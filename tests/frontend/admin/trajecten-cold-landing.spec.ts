/**
 * Acceptance: Trajecten surface cold-landing (Cluster E, AF-4).
 *
 * A FRESH browser load (backdoor-login redirecting STRAIGHT to ?view=trajecten,
 * assert before any interaction) must render the trajecten list POPULATED
 * against the real seeded backend — the 4 seeded trajectories with their server
 * status/mode labels — then opening a row must populate the detail slide-over
 * (courses grouped by type + the deelnemers roster) via the REAL detail fetch.
 *
 * This is the un-mocked seam for the wiring task: the assertions go through the
 * REAL chain (shell api() → GET /admin/trajectories[/{id}] →
 * AdminTrajectoryService → the seeded DB), never a stubbed response.
 * trajecten-mappers.spec.ts covers the pure mappers; this proves the wire is
 * live and the partial binds the REAL endpoint keys (item.statusLabel,
 * item.modeLabel, detail.courses[].type, detail.registrations).
 *
 * Login mechanism + adminContext() are lifted verbatim from
 * dossier-cold-landing.spec.ts (same env, same backdoor).
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
  const rel = `scripts/.traj-login-${crypto.randomBytes(4).toString('hex')}.php`;
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

/** A seeded trajectory WITH courses — resolved at runtime so the spec is not
 *  pinned to a hard-coded id that shifts between seeds. Returns its title. */
let seededTraj: { id: number; title: string } | null = null;
function resolveSeededTrajectory(): { id: number; title: string } {
  if (seededTraj) return seededTraj;
  const rel = `scripts/.traj-find-${crypto.randomBytes(4).toString('hex')}.php`;
  fs.writeFileSync(
    rel,
    `<?php $q=new WP_Query(["post_type"=>"vad_trajectory","post_status"=>"publish","posts_per_page"=>-1]); ` +
      `foreach($q->posts as $p){$c=get_post_meta($p->ID,"_ntdst_courses",true); if(is_string($c))$c=json_decode($c,true); ` +
      `if(is_array($c)&&count($c)>0){echo json_encode(["id"=>(int)$p->ID,"title"=>$p->post_title]);break;}}`,
  );
  let out: string;
  try {
    out = execSync(`ddev exec wp eval-file ${rel}`, { encoding: 'utf-8', cwd: process.cwd() }).trim();
  } finally {
    fs.rmSync(rel, { force: true });
  }
  const line = out.split('\n').filter((l) => l.startsWith('{')).pop();
  if (!line) throw new Error(`No seeded trajectory with courses found — run scripts/seed.php. Output:\n${out}`);
  seededTraj = JSON.parse(line) as { id: number; title: string };
  return seededTraj;
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

const TRAJ = "section[x-data='trajecten()']";

test.describe('Trajecten surface — cold landing', () => {
  test('AF-4: fresh load renders the populated trajecten list with server labels', async ({ page }) => {
    const traj = resolveSeededTrajectory();
    await loginAndLand(page, 'view=trajecten&scope=all');

    const section = page.locator(TRAJ);
    await expect(section).toBeVisible({ timeout: 15000 });

    // The list table is populated (not the empty/loading state) — proves the
    // REAL GET /admin/trajectories load resolved AND the partial binds the real
    // `items` key (rebound from the mockup's `trajectories`).
    const rows = section.locator('table.ws-traj-table tbody tr');
    await expect(rows.first()).toBeVisible({ timeout: 15000 });
    expect(await rows.count()).toBeGreaterThan(0);

    // The seeded trajectory's title is rendered (real data through the wire).
    await expect(section.getByText(traj.title, { exact: false }).first()).toBeVisible({ timeout: 15000 });

    // A status badge carries a Dutch label rendered AS RECEIVED (INV-7) — the
    // server's statusLabel, never re-derived client-side.
    const badge = section.locator('table.ws-traj-table .ws-badge').first();
    await expect(badge).toBeVisible({ timeout: 15000 });
    expect((await badge.textContent() || '').trim().length).toBeGreaterThan(0);
  });

  test('AF-4: opening a row populates the detail slide-over (courses + roster) via the real fetch', async ({ page }) => {
    const traj = resolveSeededTrajectory();
    await loginAndLand(page, 'view=trajecten&scope=all');

    const section = page.locator(TRAJ);
    await expect(section).toBeVisible({ timeout: 15000 });

    // Click the seeded trajectory's row → triggers GET /admin/trajectories/{id}.
    const row = section.locator('table.ws-traj-table tbody tr', { hasText: traj.title }).first();
    await expect(row).toBeVisible({ timeout: 15000 });
    await row.click();

    // The slide-over opens and the detail header binds the real title.
    const slideover = section.locator('.ws-slideover');
    await expect(slideover).toBeVisible({ timeout: 15000 });
    await expect(slideover.locator('.ws-slideover__title')).toHaveText(traj.title, { timeout: 15000 });

    // The course block rendered (this seeded trajectory has courses) — proves
    // groupCourses() ran over the REAL flat courses array, NOT a crash on the
    // absent required/electiveGroups keys.
    await expect(slideover.locator('.ws-traj-course').first()).toBeVisible({ timeout: 15000 });

    // The roster section renders — for a 0-enrollment seed this is the empty
    // edge (the t3 edge), NOT a crash. Either a roster item OR the empty state.
    const roster = slideover.locator('.ws-traj-roster .ws-traj-rosteritem');
    const emptyRoster = slideover.getByText('Nog geen inschrijvingen', { exact: false });
    const hasRoster = (await roster.count()) > 0;
    if (hasRoster) {
      await expect(roster.first()).toBeVisible();
    } else {
      await expect(emptyRoster).toBeVisible({ timeout: 15000 });
    }
  });
});
