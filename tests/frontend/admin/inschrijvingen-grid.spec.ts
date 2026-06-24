/**
 * Acceptance: Inschrijvingen grid cold-landing (Cluster C, AF-2).
 *
 * The anti-regression gate for the abandoned attempt: a FRESH browser load
 * (backdoor-login redirecting STRAIGHT to the surface, assert before any
 * interaction) must render the grid POPULATED against the real seeded backend —
 * real rows + funnel chips with real statusCounts — not an empty skeleton.
 *
 * This is the un-mocked seam: the assertions go through the REAL chain
 * (shell api() → GET /admin/registrations → AdminRegistrationQueryService →
 * the seeded DB), never a stubbed response. grid-mappers.spec.ts covers the
 * pure helpers; this proves the wire is live and the flat→nested rebind reads
 * the REAL nested keys (r.user.name, r.status.value/label, r.offerteStatus).
 *
 * Login: a one-shot test-login backdoor that redirects directly to the target
 * admin page. (The shared wpAdminLogin() two-step — login → /wp/wp-admin/ →
 * navigate — bounces logged-out through the custom /aanmelden page in this env;
 * the one-shot redirect avoids that intermediate hop and is reliable.)
 *
 * Seeded baseline (probed this session): 34 registrations — 3 pending, 1
 * waitlist, 2 interest, 26 confirmed, 1 completed, 1 cancelled.
 */

import { test, expect, type Page } from '@playwright/test';
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
  const rel = `scripts/.grid-login-${crypto.randomBytes(4).toString('hex')}.php`;
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

/** One-shot backdoor login that lands directly on the given admin path. */
async function loginAndLand(page: Page, viewPath: string): Promise<void> {
  const { id, secret } = adminContext();
  const key = crypto.createHmac('sha256', secret).update(`login:${id}`).digest('hex');
  const target = `/wp/wp-admin/admin.php?page=stride-dashboard&${viewPath}`;
  await page.goto(
    `/?stride_test_login=1&user_id=${id}&test_key=${key}&redirect=${encodeURIComponent(target)}`,
    { waitUntil: 'domcontentloaded', timeout: 30000 },
  );
}

const GRID = "section[x-data='grid()']";

test.describe('Inschrijvingen grid — cold landing', () => {
  test('AF-2: fresh load renders real rows + funnel chips with real statusCounts', async ({ page }) => {
    await loginAndLand(page, 'view=inschrijvingen');

    const grid = page.locator(GRID);
    await expect(grid).toBeVisible({ timeout: 15000 });

    // Real rows rendered (not an empty skeleton): wait for the populated flat
    // table to appear (proves the GET /admin/registrations load resolved).
    const rows = grid.locator('table.ws-table:not(.ws-table--grouped) tbody tr');
    await expect(rows.first()).toBeVisible({ timeout: 15000 });
    expect(await rows.count()).toBeGreaterThan(0);

    // Funnel chips show the live statusCounts read from the endpoint AS RECEIVED.
    // confirmed is the dominant seeded status — its chip must be a non-zero count.
    const confirmedCount = grid.locator('.ws-stage-chip--confirmed .ws-stage-chip__count');
    await expect(confirmedCount).toBeVisible({ timeout: 15000 });
    await expect.poll(async () => Number(await confirmedCount.textContent()), { timeout: 15000 }).toBeGreaterThan(10);
    await expect(grid.locator('.ws-badge').first()).toBeVisible();
    // The badge text is a Dutch label received from the backend (INV-7), not a
    // client-derived value.
    const firstBadge = await grid.locator('.ws-badge').first().textContent();
    expect(['Interesse', 'Wachtlijst', 'In afwachting', 'Bevestigd', 'Afgerond', 'Geannuleerd']).toContain((firstBadge || '').trim());
  });

  test('AF-2 deep-link: ?queue=pending pre-filters to status=pending on cold load', async ({ page }) => {
    await loginAndLand(page, 'view=inschrijvingen&queue=pending');

    const grid = page.locator(GRID);
    await expect(grid).toBeVisible({ timeout: 15000 });

    // The pending stage chip is the active filter (deep-link landed on cold load).
    await expect(grid.locator('.ws-stage-chip--pending')).toHaveClass(/is-active/, { timeout: 15000 });
    // The active filter chip reflects the pre-applied status filter.
    await expect(grid.locator('.ws-chip').filter({ hasText: 'In afwachting' })).toBeVisible({ timeout: 15000 });

    // Every visible row is pending — the SERVER filtered (not the client). Wait
    // for the filtered page to settle first.
    const badges = grid.locator('table.ws-table:not(.ws-table--grouped) tbody tr .ws-badge');
    await expect(badges.first()).toBeVisible({ timeout: 15000 });
    const n = await badges.count();
    expect(n).toBeGreaterThan(0);
    for (let i = 0; i < n; i++) {
      await expect(badges.nth(i)).toHaveText('In afwachting');
    }
  });

  test('AF-2 empty edge: a search matching nobody shows the empty state, not a blank grid', async ({ page }) => {
    await loginAndLand(page, 'view=inschrijvingen');
    const grid = page.locator(GRID);
    await expect(grid).toBeVisible({ timeout: 15000 });
    // Wait for the initial populated load before driving the empty edge.
    await expect(grid.locator('table.ws-table:not(.ws-table--grouped) tbody tr').first()).toBeVisible({ timeout: 15000 });

    const search = grid.locator("input[type='text']").first();
    await search.fill('zzz-no-such-person-zzz');
    // The debounced search re-fetches; a zero-result page renders the empty
    // state with the search-context title (emptyTitle() branch on filters.q),
    // and the flat table rows are gone (no phantom rows behind it).
    await expect(grid.locator('table.ws-table:not(.ws-table--grouped) tbody tr')).toHaveCount(0, { timeout: 15000 });
    const emptyHeading = grid.locator('.ws-empty h3').filter({ hasText: 'zzz-no-such-person-zzz' });
    await expect(emptyHeading).toBeVisible({ timeout: 15000 });
  });
});
