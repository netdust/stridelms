/**
 * Admin E2E Test Helpers
 *
 * Shared utilities for WordPress admin E2E tests.
 * Uses seed data from scripts/seed.php
 */

import type { Page } from '@playwright/test';
import { execSync } from 'child_process';
import * as crypto from 'crypto';

// ---------------------------------------------------------------------------
// Constants
// ---------------------------------------------------------------------------

export const WP_ADMIN = '/wp/wp-admin';

// The seed admin we log in as. Its numeric user_id is resolved at runtime
// (seed IDs drift on every re-seed) — never hardcode the id.
export const SEED_ADMIN_EMAIL = 'seed_admin@seed.test';

export const adminUsers = {
  admin: {
    email: SEED_ADMIN_EMAIL,
    password: 'seedpass123',
  },
};

// ---------------------------------------------------------------------------
// Test-login key computation — the contract with the backend validator.
//
// web/app/mu-plugins/test-login-helper.php (line ~54) computes:
//   hash_hmac('sha256', 'login:' . $userId, STRIDE_TEST_LOGIN_SECRET)
// and accepts only a test_key equal to that (hash_equals). The secret comes
// from the environment ONLY — there is no hardcoded fallback on either side.
//
// This is the single source of the key algorithm on the test side and is
// exported as a pure function so it can be unit-tested against a known
// vector in isolation (it is the testable seam for this fixture).
// ---------------------------------------------------------------------------

/**
 * Compute the test-login key for a user, matching the backend's
 * `hash_hmac('sha256', 'login:' . $userId, $secret)`.
 *
 * Pure: same (userId, secret) always yields the same key. No I/O.
 */
export function computeTestLoginKey(userId: number, secret: string): string {
  return crypto
    .createHmac('sha256', secret)
    .update(`login:${userId}`)
    .digest('hex');
}

// ---------------------------------------------------------------------------
// Runtime resolution of the secret + seed-admin id (cached per process).
//
// The STRIDE_TEST_LOGIN_SECRET lives in the project .env, which Bedrock loads
// into PHP $_ENV inside the DDEV container — it is NOT exported to the host
// shell, and dotenv is not a test dependency. Rather than read .env from the
// host, resolve both the secret and the (drifting) seed-admin id from inside
// the container in one trusted call, the same `ddev exec wp eval-file` pattern
// the enrollment specs already use. Cached so we pay the round-trip once.
// ---------------------------------------------------------------------------

type AdminLoginContext = { userId: number; secret: string };

let cachedAdminContext: AdminLoginContext | null = null;

function resolveAdminLoginContext(): AdminLoginContext {
  if (cachedAdminContext) return cachedAdminContext;

  // Allow a host-provided override (e.g. CI exporting the secret + a pre-known
  // id) without a DDEV round-trip. Both must be present to take this path.
  const envSecret = process.env.STRIDE_TEST_LOGIN_SECRET;
  const envUserId = process.env.SEED_ADMIN_USER_ID;
  if (envSecret && envUserId && Number(envUserId) > 0) {
    cachedAdminContext = { userId: Number(envUserId), secret: envSecret };
    return cachedAdminContext;
  }

  // Resolve from inside the container: the secret as PHP sees it + the
  // runtime-resolved seed-admin user id. Written to a temp file under the
  // mounted project dir because inline `wp eval` mangles $-vars through the
  // shell (same constraint the enrollment fixtures document).
  const rel = `scripts/.admin-login-${crypto.randomBytes(4).toString('hex')}.php`;
  const php =
    `<?php\n` +
    `$s = $_ENV['STRIDE_TEST_LOGIN_SECRET'] ?? getenv('STRIDE_TEST_LOGIN_SECRET') ?: '';\n` +
    `$u = get_user_by('email', '${SEED_ADMIN_EMAIL}');\n` +
    `echo json_encode(['secret' => (string) $s, 'user_id' => $u ? (int) $u->ID : 0]);\n`;

  let out: string;
  // eslint-disable-next-line @typescript-eslint/no-var-requires
  const fs = require('fs') as typeof import('fs');
  fs.writeFileSync(rel, php);
  try {
    out = execSync(`ddev exec wp eval-file ${rel}`, {
      encoding: 'utf-8',
      cwd: process.cwd(),
    }).trim();
  } finally {
    fs.rmSync(rel, { force: true });
  }

  const line = out.split('\n').filter((l) => l.startsWith('{')).pop();
  if (!line) {
    throw new Error(
      `Could not resolve admin login context from DDEV. Output:\n${out}`,
    );
  }
  const parsed = JSON.parse(line) as { secret: string; user_id: number };

  if (!parsed.secret) {
    throw new Error(
      'STRIDE_TEST_LOGIN_SECRET is not set in the test environment. The ' +
        'test-login backdoor (web/app/mu-plugins/test-login-helper.php) reads ' +
        'this secret from the env with NO hardcoded fallback, so a bad/empty ' +
        'secret produces a key the backend will reject. Set it in the project ' +
        '.env (loaded into the DDEV container) or export STRIDE_TEST_LOGIN_SECRET.',
    );
  }
  if (!parsed.user_id || parsed.user_id <= 0) {
    throw new Error(
      `Seed admin "${SEED_ADMIN_EMAIL}" not found. Run: ddev exec wp eval-file scripts/seed.php`,
    );
  }

  cachedAdminContext = { userId: parsed.user_id, secret: parsed.secret };
  return cachedAdminContext;
}

// ---------------------------------------------------------------------------
// Login
// ---------------------------------------------------------------------------

/**
 * Log in to WordPress admin via the test-login backdoor.
 *
 * Skips the real /login/ UI which is AJAX-driven, rate-limited
 * (5 attempts / 15 min per IP), and tripped during parallel Playwright runs.
 * Backdoor is gated by CODECEPTION_TEST env or DDEV_PROJECT=stride —
 * see web/app/mu-plugins/test-login-helper.php.
 */
export async function wpAdminLogin(
  page: Page,
  _user = adminUsers.admin,
): Promise<void> {
  // Try wp-admin first — if we already have a session cookie, we're done.
  await page.goto(`${WP_ADMIN}/`, { waitUntil: 'domcontentloaded', timeout: 30000 });
  if (page.url().includes('wp-admin') && !page.url().includes('login')) return;

  // Use the test-login backdoor — same algorithm as the backend validator
  // (web/app/mu-plugins/test-login-helper.php): HMAC-SHA256 over 'login:<id>'
  // keyed by STRIDE_TEST_LOGIN_SECRET. Both the secret and the seed-admin id
  // are resolved from the running container (no hardcoding, no stale id).
  const { userId, secret } = resolveAdminLoginContext();
  const testKey = computeTestLoginKey(userId, secret);

  await page.goto(
    `/?stride_test_login=1&user_id=${userId}&test_key=${testKey}` +
      `&redirect=${encodeURIComponent(`${WP_ADMIN}/`)}`,
    { waitUntil: 'domcontentloaded', timeout: 30000 },
  );

  if (page.url().includes('/login') || page.url().includes('wp-login')) {
    throw new Error(
      `Test-login backdoor unavailable for admin user ${userId}. ` +
        `Verify web/app/mu-plugins/test-login-helper.php is active in this env.`,
    );
  }
}

// ---------------------------------------------------------------------------
// Navigation
// ---------------------------------------------------------------------------

/**
 * Navigate to the Edition list table (edit.php?post_type=vad_edition).
 */
export async function gotoEditionList(page: Page): Promise<void> {
  await page.goto(`${WP_ADMIN}/edit.php?post_type=vad_edition`);
  await page.waitForLoadState('domcontentloaded');
}

/**
 * Navigate to the "Add New Edition" screen.
 */
export async function gotoNewEdition(page: Page): Promise<void> {
  await page.goto(`${WP_ADMIN}/post-new.php?post_type=vad_edition`);
  await page.waitForLoadState('domcontentloaded');
}

/**
 * Navigate to an existing edition edit screen by post ID.
 */
export async function gotoEditEdition(page: Page, postId: number): Promise<void> {
  await page.goto(`${WP_ADMIN}/post.php?post=${postId}&action=edit`);
  await page.waitForLoadState('domcontentloaded');
}

// ---------------------------------------------------------------------------
// Edition helpers
// ---------------------------------------------------------------------------

/**
 * Find the first edition on the list table and return its post ID + row locator.
 */
export async function getFirstEditionRow(page: Page) {
  const row = page.locator('#the-list tr').first();
  const editLink = row.locator('.row-title');
  const href = await editLink.getAttribute('href');
  const match = href?.match(/post=(\d+)/);
  const postId = match ? Number(match[1]) : 0;
  return { row, postId, editLink };
}

/**
 * Publish / Update the current edition being edited.
 */
export async function publishEdition(page: Page): Promise<void> {
  await page.click('#publish');
  // Wait for the "Post published" / "Post updated" notice
  await page.waitForSelector('#message, .notice-success, .updated', { timeout: 15000 });
}

/**
 * Wait for an AJAX response from admin-ajax.php.
 */
export async function waitForAjax(page: Page, timeout = 10000) {
  return page.waitForResponse(
    (res) => res.url().includes('admin-ajax.php') && res.status() === 200,
    { timeout },
  );
}

/**
 * Accept a native confirm() dialog (next one that fires).
 */
export function acceptNextDialog(page: Page): void {
  page.once('dialog', (dialog) => dialog.accept());
}

/**
 * Dismiss a native confirm() dialog.
 */
export function dismissNextDialog(page: Page): void {
  page.once('dialog', (dialog) => dialog.dismiss());
}
