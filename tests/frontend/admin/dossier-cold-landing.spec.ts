/**
 * Acceptance: Dossier surface cold-landing (Cluster D, AF-3).
 *
 * The anti-regression gate for the abandoned attempt: a FRESH browser load
 * (backdoor-login redirecting STRAIGHT to ?view=dossier&user=<id>, assert
 * before any interaction) must render the dossier POPULATED against the real
 * seeded backend — person header, registration cards, the derived completion
 * checklist — not an empty skeleton.
 *
 * This is the un-mocked seam for the wiring task: the assertions go through the
 * REAL chain (shell api() → GET /admin/users/{id}/detail + /trajectories →
 * AdminUserService → the seeded DB), never a stubbed response.
 * dossier-mappers.spec.ts covers the three pure mappers; this proves the wire
 * is live and the partial binds the REAL endpoint keys (person.display_name,
 * r.edition_title, r.status, r.stages, completionFor(r)).
 *
 * Login mechanism + adminContext() are lifted verbatim from
 * inschrijvingen-grid.spec.ts (same env, same backdoor).
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
  const rel = `scripts/.dossier-login-${crypto.randomBytes(4).toString('hex')}.php`;
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

/** A seeded student WITH multiple registrations — resolved at runtime so the
 *  spec is not pinned to a hard-coded id that shifts between seeds. */
let dossierUser: number | null = null;
function resolveDossierUser(): number {
  if (dossierUser) return dossierUser;
  const rel = `scripts/.dossier-user-${crypto.randomBytes(4).toString('hex')}.php`;
  fs.writeFileSync(
    rel,
    `<?php global $wpdb; $t=$wpdb->prefix.'vad_registrations'; ` +
      `$id=(int)$wpdb->get_var("SELECT user_id FROM $t GROUP BY user_id ORDER BY COUNT(*) DESC LIMIT 1"); echo $id;`,
  );
  let out: string;
  try {
    out = execSync(`ddev exec wp eval-file ${rel}`, { encoding: 'utf-8', cwd: process.cwd() }).trim();
  } finally {
    fs.rmSync(rel, { force: true });
  }
  const id = Number((out.match(/\d+/) || [])[0]);
  if (!id) throw new Error(`No seeded user with registrations found — run scripts/seed.php. Output:\n${out}`);
  dossierUser = id;
  return id;
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

const DOSSIER = "section[x-data='dossier()']";

test.describe('Dossier surface — cold landing', () => {
  test('AF-3: fresh load renders the person header + real registration cards', async ({ page }) => {
    const user = resolveDossierUser();
    await loginAndLand(page, `view=dossier&user=${user}`);

    const dossier = page.locator(DOSSIER);
    await expect(dossier).toBeVisible({ timeout: 15000 });

    // Person header populated from the REAL /detail user.display_name (not blank,
    // not the mockup's person.name) — proves the GET /detail load resolved AND
    // the partial binds the real key.
    const name = dossier.locator('.ws-person-head__name');
    await expect(name).toBeVisible({ timeout: 15000 });
    expect((await name.textContent())?.trim().length).toBeGreaterThan(0);

    // Real registration cards rendered (not an empty skeleton).
    const regs = dossier.locator('.ws-reg');
    await expect(regs.first()).toBeVisible({ timeout: 15000 });
    expect(await regs.count()).toBeGreaterThan(0);

    // The first card's status badge is a Dutch label rendered AS RECEIVED
    // (INV-7). NOTE: .ws-reg__badges scopes to the header's badge cluster —
    // the reg title now carries a separate (often hidden) 'Traject' tag whose
    // text is not a status, so a bare .ws-badge .first() would pin that.
    const badge = dossier.locator('.ws-reg .ws-reg__badges .ws-badge').first();
    await expect(badge).toBeVisible({ timeout: 15000 });
    const badgeText = (await badge.textContent() || '').trim();
    expect(['Interesse', 'Wachtlijst', 'In afwachting', 'Bevestigd', 'Afgerond', 'Geannuleerd']).toContain(badgeText);
  });

  test('AF-3: the completion-task panel renders the SERVER task list (or its empty state)', async ({ page }) => {
    const user = resolveDossierUser();
    await loginAndLand(page, `view=dossier&user=${user}`);

    const dossier = page.locator(DOSSIER);
    await expect(dossier).toBeVisible({ timeout: 15000 });
    // First card is open by default (idx === 0). The Voltooiingstaken panel
    // renders the registration's REAL completion_tasks (r.tasks, Dutch labels
    // from EnrollmentCompletion::taskTypeLabel) — the client-derived 4-item
    // checklist is gone. A reg without tasks shows the explicit empty state;
    // pre-fulfillment/terminal statuses show the fulfillment hint instead.
    const openCard = dossier.locator('.ws-reg.is-open').first();
    await expect(openCard).toBeVisible({ timeout: 15000 });
    const panelHeader = openCard.getByText('Voltooiingstaken', { exact: false }).first();
    const taskPill = openCard.locator('.ws-pill--done, .ws-pill--todo').first();
    const emptyState = openCard.getByText('Geen taken voor deze inschrijving', { exact: false }).first();
    const fulfillmentHint = openCard.locator('.ws-muted').filter({ hasText: /wachtlijst|Interesse|Geannuleerd|voortgang/ }).first();
    // Either the panel renders (with a pill or the empty line), or the whole
    // fulfillment region is replaced by the status hint — never a blank hole.
    await expect(panelHeader.or(fulfillmentHint)).toBeVisible({ timeout: 15000 });
    if (await panelHeader.isVisible().catch(() => false)) {
      await expect(taskPill.or(emptyState)).toBeVisible({ timeout: 15000 });
    }
  });

  test('AF-3 empty edge: a user with no registrations shows the empty state, not a crash', async ({ page }) => {
    // The seed admin itself is a valid user; if it has registrations this still
    // renders cards — so we drive the explicit no-user guard instead, which is
    // the deterministic empty branch (init() with no ?user= → "Geen gebruiker
    // geselecteerd").
    await loginAndLand(page, 'view=dossier');
    const dossier = page.locator(DOSSIER);
    await expect(dossier).toBeVisible({ timeout: 15000 });
    // The detail-error banner shows the no-user message; the surface does not
    // crash or render a half-built skeleton.
    await expect(dossier.getByText('Geen gebruiker geselecteerd', { exact: false })).toBeVisible({ timeout: 15000 });
  });
});
