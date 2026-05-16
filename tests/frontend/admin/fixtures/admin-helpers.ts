/**
 * Admin E2E Test Helpers
 *
 * Shared utilities for WordPress admin E2E tests.
 * Uses seed data from scripts/seed.php
 */

import type { Page } from '@playwright/test';
import * as crypto from 'crypto';

// ---------------------------------------------------------------------------
// Constants
// ---------------------------------------------------------------------------

export const WP_ADMIN = '/wp/wp-admin';

// seed_admin user id (from scripts/seed.php). The test-login backdoor in
// web/app/mu-plugins/test-login-helper.php signs by user_id; see
// tests/_support/Helper/Acceptance.php for the matching acceptance helper.
const SEED_ADMIN_USER_ID = 3191;
const TEST_LOGIN_SECRET = 'stride_codeception_test_secret_2024';

export const adminUsers = {
  admin: {
    email: 'seed_admin@seed.test',
    password: 'seedpass123',
  },
};

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

  // Use the test-login backdoor: same pattern as acceptance suite (see
  // tests/_support/Helper/Acceptance.php::loginAsUserId).
  const testKey = crypto
    .createHash('md5')
    .update(`stride_test_${SEED_ADMIN_USER_ID}_${TEST_LOGIN_SECRET}`)
    .digest('hex');

  await page.goto(
    `/?stride_test_login=1&user_id=${SEED_ADMIN_USER_ID}&test_key=${testKey}` +
      `&redirect=${encodeURIComponent(`${WP_ADMIN}/`)}`,
    { waitUntil: 'domcontentloaded', timeout: 30000 },
  );

  if (page.url().includes('/login') || page.url().includes('wp-login')) {
    throw new Error(
      `Test-login backdoor unavailable for admin user ${SEED_ADMIN_USER_ID}. ` +
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
