/**
 * Acceptance: lazy-load on first activation (I-1, un-mocked seam).
 *
 * The behaviour change: each per-surface factory loads its data the FIRST time
 * its view becomes active, not on mount. Before the fix, landing on Vandaag
 * fired ~6 unused REST calls (the grid, editions×2, quotes, trajectories) for
 * surfaces the user wasn't viewing — including the expensive grid query.
 *
 * This drives the REAL chain (backdoor-login → the live dashboard → real
 * /admin/* REST calls against the seeded backend) and observes the Network
 * panel, so it proves the wire is live AND lazy:
 *   1. landing on Vandaag fires ONLY Vandaag's calls (/admin/stats,
 *      /admin/pending-approvals, /admin/action-queue) — NOT the grid /
 *      editions / quotes / trajectories calls;
 *   2. navigating to Inschrijvingen THEN fires the grid load (lazy works);
 *   3. Vandaag still cold-loads populated (the default view is unaffected).
 *
 * Login mechanism lifted verbatim from dossier-cold-landing.spec.ts.
 */

import { test, expect, type Page, type Request } from '@playwright/test';
import * as crypto from 'crypto';
import { execSync } from 'child_process';
import * as fs from 'fs';

const SEED_ADMIN_EMAIL = 'seed_admin@seed.test';

let cached: { id: number; secret: string } | null = null;
function adminContext(): { id: number; secret: string } {
  if (cached) return cached;
  const envSecret = process.env.STRIDE_TEST_LOGIN_SECRET;
  const envId = process.env.SEED_ADMIN_USER_ID;
  if (envSecret && envId && Number(envId) > 0) {
    cached = { id: Number(envId), secret: envSecret };
    return cached;
  }
  const rel = `scripts/.lazyload-login-${crypto.randomBytes(4).toString('hex')}.php`;
  fs.writeFileSync(
    rel,
    `<?php $s=$_ENV['STRIDE_TEST_LOGIN_SECRET']??getenv('STRIDE_TEST_LOGIN_SECRET')?:''; ` +
      `$u=get_user_by('email','${SEED_ADMIN_EMAIL}'); echo json_encode(['s'=>(string)$s,'id'=>$u?(int)$u->ID:0]);`,
  );
  let out: string;
  try {
    out = execSync(`ddev exec wp eval-file ${rel}`, { encoding: 'utf-8', cwd: process.cwd() }).trim();
  } finally {
    fs.rmSync(rel, { force: true });
  }
  const line = out.split('\n').filter((l) => l.startsWith('{')).pop();
  if (!line) throw new Error(`Could not resolve admin login context. Output:\n${out}`);
  const parsed = JSON.parse(line) as { s: string; id: number };
  if (!parsed.s) throw new Error('STRIDE_TEST_LOGIN_SECRET not set in the DDEV env.');
  if (!parsed.id) throw new Error(`Seed admin "${SEED_ADMIN_EMAIL}" not found — run scripts/seed.php`);
  cached = { id: parsed.id, secret: parsed.s };
  return cached;
}

async function loginAndLand(page: Page, viewPath: string): Promise<void> {
  const { id, secret } = adminContext();
  const key = crypto.createHmac('sha256', secret).update(`login:${id}`).digest('hex');
  const target = `/wp/wp-admin/admin.php?page=stride-dashboard&${viewPath}`;
  await page.goto(
    `/?stride_test_login=1&user_id=${id}&test_key=${key}&redirect=${encodeURIComponent(target)}`,
    { waitUntil: 'domcontentloaded', timeout: 30000 },
  );
}

/** Record every /admin/* REST call's pathname (query stripped). */
function trackAdminCalls(page: Page): string[] {
  const calls: string[] = [];
  page.on('request', (req: Request) => {
    const u = req.url();
    const m = u.match(/\/wp-json\/[^/]+\/v1(\/admin\/[^?]*)/);
    if (m) calls.push(m[1]);
  });
  return calls;
}

const has = (calls: string[], needle: string) => calls.some((c) => c.startsWith(needle));

test.describe('Lazy-load on first activation (I-1)', () => {
  test('landing on Vandaag fires ONLY Vandaag calls — not grid/editions/quotes/trajectories', async ({ page }) => {
    const calls = trackAdminCalls(page);
    await loginAndLand(page, 'view=vandaag');

    // Vandaag must cold-load populated: wait for its stat strip to render.
    const vandaag = page.locator("section[x-data='vandaag()']");
    await expect(vandaag).toBeVisible({ timeout: 15000 });
    // give any (erroneous) sibling loads a chance to fire before asserting absence
    await page.waitForTimeout(1500);

    // Vandaag's own three calls DID fire (the wire is live).
    expect(has(calls, '/admin/stats'), 'vandaag loaded stats').toBeTruthy();
    expect(has(calls, '/admin/pending-approvals'), 'vandaag loaded approvals').toBeTruthy();
    expect(has(calls, '/admin/action-queue'), 'vandaag loaded action-queue').toBeTruthy();

    // DENIAL: the OTHER surfaces did NOT eager-load (the fixed waste).
    expect(has(calls, '/admin/registrations'), 'grid did NOT load on a Vandaag landing').toBeFalsy();
    expect(has(calls, '/admin/editions'), 'editions/sessies did NOT load on a Vandaag landing').toBeFalsy();
    expect(has(calls, '/admin/quotes'), 'quotes did NOT load on a Vandaag landing').toBeFalsy();
    expect(has(calls, '/admin/trajectories'), 'trajectories did NOT load on a Vandaag landing').toBeFalsy();
  });

  test('navigating Vandaag → Inschrijvingen THEN fires the grid load (lazy works)', async ({ page }) => {
    const calls = trackAdminCalls(page);
    await loginAndLand(page, 'view=vandaag');
    await expect(page.locator("section[x-data='vandaag()']")).toBeVisible({ timeout: 15000 });
    await page.waitForTimeout(800);
    expect(has(calls, '/admin/registrations'), 'grid not loaded yet').toBeFalsy();

    // Click the Inschrijvingen rail item (an <a class="ws-nav__item"> that calls
    // switchView('inschrijvingen') → flips the shell view → the grid's guard fires).
    await page.locator('.ws-nav__item', { hasText: 'Inschrijvingen' }).first().click();

    // Now the grid load fires on first activation.
    await expect.poll(() => has(calls, '/admin/registrations'), { timeout: 15000 })
      .toBeTruthy();
    // And the grid renders its real surface.
    await expect(page.locator("section[x-data='grid()']")).toBeVisible({ timeout: 15000 });
  });

  test('each list surface lazily fires ITS endpoint on first activation, uniformly', async ({ page }) => {
    const calls = trackAdminCalls(page);
    await loginAndLand(page, 'view=vandaag');
    await expect(page.locator("section[x-data='vandaag()']")).toBeVisible({ timeout: 15000 });
    await page.waitForTimeout(600);

    // Edities → /admin/editions?view=list
    await page.locator('.ws-nav__item', { hasText: 'Edities' }).first().click();
    await expect.poll(() => has(calls, '/admin/editions'), { timeout: 15000 }).toBeTruthy();

    // Offertes → /admin/quotes
    await page.locator('.ws-nav__item', { hasText: 'Offertes' }).first().click();
    await expect.poll(() => has(calls, '/admin/quotes'), { timeout: 15000 }).toBeTruthy();

    // Trajecten → /admin/trajectories
    await page.locator('.ws-nav__item', { hasText: 'Trajecten' }).first().click();
    await expect.poll(() => has(calls, '/admin/trajectories'), { timeout: 15000 }).toBeTruthy();
  });
});
