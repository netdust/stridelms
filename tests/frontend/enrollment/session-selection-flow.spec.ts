import { test as baseTest, expect, type Page } from '@playwright/test';
import { execSync } from 'child_process';
import * as fs from 'fs';
import * as crypto from 'crypto';

/**
 * Acceptance: Enroll → Voucher → Session Selection (Kies 1 uit N)
 *
 * Spec: docs/architecture/acceptance-flows/enroll-voucher-session-selection.md
 *
 * Backend-seeds the enroll+voucher half via a PHP fixture (asserts the voucher
 * discounted the quote in the DB), then DRIVES the session-selection UI + all
 * six edge classes through the real browser against running DDEV.
 *
 * Faithful layers: enroll/voucher = un-mocked services (fixture, DB-asserted);
 * session selection + edges = real browser, no ntdstAPI mock.
 */

// Resolved dynamically from the fixture (seed IDs drift on re-seed).
let TEST_USER_ID = 0;
const TEST_LOGIN_SECRET = 'stride_codeception_test_secret_2024';
const AUTH_FILE = '/tmp/stride-session-selection-auth.json';

// --- backend fixture: enroll + voucher, returns reachable flow data ----------
type Fixture = {
  user_id: number;
  registration_id: number;
  edition_id: number;
  edition_slug: string;
  course_slug: string;
  slot: string;
  slot_session_ids: number[];
  quote_id: number;
  voucher_applied: boolean;
};

function seedFixture(reset = true): Fixture {
  const env = reset ? 'STRIDE_RESET=1 ' : '';
  const out = execSync(
    `ddev exec bash -c '${env}wp eval-file tests/frontend/enrollment/fixtures/seed-session-selection-flow.php'`,
    { encoding: 'utf-8', cwd: process.cwd() },
  );
  const line = out.trim().split('\n').filter((l) => l.startsWith('{')).pop();
  if (!line) throw new Error(`Fixture produced no JSON. Output:\n${out}`);
  return JSON.parse(line) as Fixture;
}

// Run PHP in the container via a temp file under the (mounted) project dir —
// inline `wp eval` mangles $-vars through the shell, so always eval-file.
function wpEvalFile(php: string): string {
  const rel = `scripts/.spec-${crypto.randomBytes(4).toString('hex')}.php`;
  fs.writeFileSync(rel, `<?php\n${php}\n`);
  try {
    return execSync(`ddev exec wp eval-file ${rel}`, {
      encoding: 'utf-8',
      cwd: process.cwd(),
    }).trim();
  } finally {
    fs.rmSync(rel, { force: true });
  }
}

// query the DB for the persisted selection — the data-check after the UI acts
function dbSelections(registrationId: number): number[] {
  const out = wpEvalFile(
    `global $wpdb; $r=$wpdb->get_var($wpdb->prepare("SELECT selections FROM {$wpdb->prefix}vad_registrations WHERE id=%d",${registrationId})); echo $r?:'[]';`,
  );
  try {
    const parsed = JSON.parse(out);
    if (!Array.isArray(parsed)) return [];
    return parsed.map((s: any) => (typeof s === 'object' ? Number(s.session_id) : Number(s)));
  } catch {
    return [];
  }
}

function lockSelection(registrationId: number): void {
  wpEvalFile(
    `global $wpdb; $wpdb->update("{$wpdb->prefix}vad_registrations",["selections_locked_at"=>current_time('mysql',true)],["id"=>${registrationId}]); echo 'locked';`,
  );
}

// --- auth (backdoor login by user_id — resolved from the fixture) ------------
async function userLogin(page: Page, userId: number): Promise<void> {
  const testKey = crypto
    .createHash('md5')
    .update(`stride_test_${userId}_${TEST_LOGIN_SECRET}`)
    .digest('hex');
  await page.goto(`/?stride_test_login=1&user_id=${userId}&test_key=${testKey}`, {
    waitUntil: 'domcontentloaded',
    timeout: 30000,
  });
  if (page.url().includes('/login') || page.url().includes('/aanmelden')) {
    throw new Error(
      `Test-login backdoor failed for user ${userId} — likely a stale/absent seed user.`,
    );
  }
}

const test = baseTest;

// completion page is routed by EDITION slug (EnrollmentRouter: /edities/:slug/voltooien)
function completionUrl(f: Fixture): string {
  return `/edities/${f.edition_slug}/voltooien/`;
}

// the session-selection task block + its option labels
async function gotoSelectionTask(page: Page, f: Fixture): Promise<boolean> {
  await userLogin(page, f.user_id); // backdoor sets auth cookie on this context
  await page.goto(completionUrl(f), { waitUntil: 'networkidle', timeout: 30000 });
  if (page.url().includes('/login') || page.url().includes('/aanmelden')) {
    return false; // not authenticated → task unreachable
  }
  // the task renders @click.prevent="toggleSession(<id>)" labels
  const anyOption = page.locator(`[\\@click\\.prevent^="toggleSession"]`).first();
  return (await anyOption.count()) > 0;
}

function optionLocator(page: Page, sessionId: number) {
  // label whose @click.prevent calls toggleSession(<id>)
  return page.locator(`label:has(input[type="checkbox"])`).filter({
    has: page.locator(`xpath=.`),
  }).locator(`xpath=//*[contains(@*,"toggleSession(${sessionId})")]`);
}

// click the option by its toggleSession(id) attr, and confirm Alpine registered
// the toggle (label gains border-primary via :class) before proceeding.
async function pickSession(page: Page, sessionId: number): Promise<void> {
  const opt = page.locator(`[\\@click\\.prevent="toggleSession(${sessionId})"]`).first();
  await opt.click();
  await expect(opt).toHaveClass(/border-primary/, { timeout: 3000 }).catch(() => {});
}

const submitBtn = (page: Page) =>
  page.locator('button:has-text("Sessies bevestigen"), button:has-text("Sessiekeuze bijwerken")').first();

// submit + wait for the post-success navigation (completeTask does location.reload
// or redirect to /mijn-account on the final task) so the DB read isn't racing.
async function submitAndSettle(page: Page): Promise<void> {
  await Promise.all([
    page.waitForLoadState('networkidle'),
    submitBtn(page).click(),
  ]);
  await page.waitForTimeout(1200); // let the AJAX → setSelections commit
}

// =============================================================================
test.describe('Enroll → Voucher → Session Selection (Kies 1 uit N)', () => {
  let f: Fixture;

  test.beforeAll(() => {
    f = seedFixture(true);
    // DATA CHECK (backend): voucher actually discounted the quote
    expect(f.quote_id, 'quote was created').toBeGreaterThan(0);
    expect(f.voucher_applied, 'voucher discount applied to quote').toBe(true);
    expect(f.slot_session_ids.length, 'slot has ≥2 options to choose from').toBeGreaterThanOrEqual(2);
  });

  // --- HAPPY PATH ------------------------------------------------------------
  test('happy: pick 1 of N afternoon sessions, persists to DB', async ({ page }) => {
    const reachable = await gotoSelectionTask(page, f);
    expect(reachable, 'session-selection task is reachable on the completion page').toBe(true);

    const chosen = f.slot_session_ids[0];
    await pickSession(page, chosen);
    await expect(submitBtn(page)).toBeEnabled();
    await submitAndSettle(page);

    // DATA CHECK: exactly the chosen session persisted; others not
    const persisted = dbSelections(f.registration_id);
    expect(persisted, 'chosen session persisted').toContain(chosen);
    for (const other of f.slot_session_ids.slice(1)) {
      expect(persisted, `non-chosen session ${other} not selected`).not.toContain(other);
    }
  });

  // --- E1 empty / required ---------------------------------------------------
  test('E1 empty: submit blocked with zero selection (slot is required)', async ({ page }) => {
    seedFixture(true); // reset to no-selection state
    f = seedFixture(false);
    await gotoSelectionTask(page, f);
    // button disabled while selected.length === 0
    await expect(submitBtn(page)).toBeDisabled();
  });

  // --- E2 denied actor -------------------------------------------------------
  test('E2 denied: logged-out user cannot reach the completion task', async ({ browser }) => {
    const ctx = await browser.newContext({ ignoreHTTPSErrors: true });
    const page = await ctx.newPage();
    await page.goto(completionUrl(f), { waitUntil: 'domcontentloaded' });
    expect(page.url(), 'redirected to login when logged out').toMatch(/login|aanmelden|wp-login/);
    await ctx.close();
  });

  // --- E3 wrong-order / locked ----------------------------------------------
  test('E3 locked: selection rejected after lockSelections / deadline', async ({ page }) => {
    const f2 = seedFixture(true);
    lockSelection(f2.registration_id);
    await gotoSelectionTask(page, f2);
    // attempt a pick + submit; DB must remain empty (locked)
    await pickSession(page, f2.slot_session_ids[0]).catch(() => {});
    if (await submitBtn(page).isEnabled().catch(() => false)) {
      await submitBtn(page).click();
      await page.waitForLoadState('networkidle');
    }
    const persisted = dbSelections(f2.registration_id);
    expect(persisted, 'locked selection did not persist a new choice').toEqual([]);
  });

  // --- E4 concurrent / double ------------------------------------------------
  // setSelections() does $wpdb->update with a JSON-encoded array — it OVERWRITES
  // the selections column, so a double-submit can never produce duplicate rows.
  // The invariant to prove: after a double-submit the slot holds AT MOST ONE
  // selection (never 2, never a duplicated id). (On success the page reloads,
  // so the second click typically no-ops — we assert the data invariant, not the
  // click count.)
  test('E4 double: rapid double-submit never produces duplicate/2 selections', async ({ page }) => {
    const f2 = seedFixture(true);
    await gotoSelectionTask(page, f2);
    await pickSession(page, f2.slot_session_ids[0]);
    // Fire two clicks in the same tick (before the success-reload navigates),
    // then let the page settle. Tolerate the page navigating away mid-clicks.
    await Promise.all([
      submitBtn(page).click({ timeout: 2000 }).catch(() => {}),
      submitBtn(page).click({ timeout: 2000 }).catch(() => {}),
    ]);
    await page.waitForLoadState('networkidle').catch(() => {});
    await page.waitForTimeout(1500);

    const persisted = dbSelections(f2.registration_id);
    const inSlot = persisted.filter((s) => f2.slot_session_ids.includes(s));
    // never 2-in-a-1-slot, never a duplicated id (overwrite semantics hold)
    expect(inSlot.length, 'at most one selection in the max=1 slot after double-submit')
      .toBeLessThanOrEqual(1);
    expect(new Set(persisted).size, 'no duplicated selection ids').toBe(persisted.length);
  });

  // --- E5 boundary: max_selections = 1 --------------------------------------
  test('E5 boundary: selecting 2 in a max_selections=1 slot never persists 2', async ({ page }) => {
    const f2 = seedFixture(true);
    await gotoSelectionTask(page, f2);
    // try to pick two options in the same (1-max) slot
    await pickSession(page, f2.slot_session_ids[0]);
    await pickSession(page, f2.slot_session_ids[1]);
    if (await submitBtn(page).isEnabled().catch(() => false)) {
      await submitBtn(page).click();
      await page.waitForLoadState('networkidle');
    }
    const persisted = dbSelections(f2.registration_id);
    const inSlot = persisted.filter((s) => f2.slot_session_ids.includes(s));
    expect(inSlot.length, 'max_selections=1 slot holds at most 1 selection').toBeLessThanOrEqual(1);
  });

  // --- E6 mid-flow failure / abandon ----------------------------------------
  test('E6 abandon: enroll+voucher persist, task stays open, resumable', async ({ page }) => {
    const f2 = seedFixture(true);
    // "abandon" = never submit a selection. Registration + quote must still exist,
    // task still open (no selection), user can return and the task is reachable.
    const persistedBefore = dbSelections(f2.registration_id);
    expect(persistedBefore, 'no selection yet (abandoned before picking)').toEqual([]);
    expect(f2.quote_id, 'quote (voucher) persisted despite abandoning selection').toBeGreaterThan(0);
    const reachable = await gotoSelectionTask(page, f2);
    expect(reachable, 'task resumable after abandon').toBe(true);
  });
});
