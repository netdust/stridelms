# Shared Richer Trajectory Card Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:executing-plans (sequential, shared context). Harness: netdust-agent:harnessed-development Class A. No threat model (public read-only presentation + an existing capability-gated dashboard; all output escaped). wp-plan-requirements layering gate applies (one drift fix: the archive's raw ntdst_data query → repository).

**Goal:** One richer trajectory card, used in BOTH the public `/trajecten` catalog and the dashboard "Mijn trajecten" view: badges + title, a meta line ("N opleidingen · waarvan M keuzemodule"), price (or "Gratis"), a date line (catalog → "Inschrijven tot …", enrolled → "Gestart …"), and a compact "X% voltooid" badge shown ONLY when the viewer is enrolled. Remove the step-progress visuals (the catalog journey-dots strip AND the dashboard per-course checklist) — too many courses to be useful.

**Architecture:** `partials/card-trajectory.php` becomes the single card, driven entirely by a normalized `$args` contract (no service calls inside the partial — its existing rule). A new theme helper `stridence_build_trajectory_card_args()` produces that contract from a trajectory id (+ optional per-user progress), so the catalog page and the dashboard both feed the card identically. The dashboard tab swaps its bespoke ring+checklist card for the shared card. Progress is an OPTIONAL arg: absent on the catalog (no enrolled badge), present on the dashboard (drives the "X% voltooid" badge).

**Tech stack:** Stridence theme (PHP partials/templates) + stride-core read methods (TrajectoryService/Repository) + Codeception/PHPUnit.

**Class:** A. Single review cluster, tier STANDARD (theme presentation + one drift fix; no 1a surface).

## Golden path: content-type feature — frontend slice (deviations named)

- Built to the frontend portion of `netdust-wp:ntdst-patterns` golden paths. Deviation: no new CPT/Service — adds one read helper on the service (`getElectiveGroupCount`) + a theme args-builder; reuses `card-trajectory.php`, `progress-ring`/`badge-status` partials, and the dashboard's existing progress computation.

## WP security requirements (per data-flow)

- [ ] Card render (catalog + dashboard, public/own-data read): every field `esc_html`/`esc_attr`/`esc_url`. Price via `stride_format_money`. Progress clamped 0–100 (the partial already does, reuse). escape: covered; authorize: dashboard is the existing capability-gated `/mijn-account`, catalog is public read-only; validate/sanitize: read-side only.

## ntdst-core layering requirements

- [ ] Replace the archive's raw `ntdst_data()->get('vad_trajectory')` (archive-vad_trajectory.php:20) with `TrajectoryRepository`/`TrajectoryService` reads (drift category 1: data access outside a repository). The card args-builder reads through `TrajectoryService::getTrajectory()` + `getCourseCount()`/`getRequiredCourseCount()` (existing) + a new `getElectiveGroupCount()`.
- [ ] No service calls inside the partial — the args-builder (theme helper) does the lookups, the partial stays pure (its documented contract).
- [ ] No new service/handler/repository; extend `TrajectoryService` with one read method.

> Convergence target for `/code-review` + `ntdst-drift-reviewer`: the named slice + this layering list.

## Acceptance flows

| # | Flow | Faithful layer | Edges |
|---|---|---|---|
| F1 | Public `/trajecten` shows the richer card: badges, title, "N opleidingen · waarvan M keuzemodule", price (Gratis for free), "Inschrijven tot …" when a deadline exists, NO progress badge, NO dots strip | Browser | empty: 0 trajectories → empty-state (unchanged); boundary: trajectory with 0 elective groups → "N opleidingen" only (no "waarvan"); 0 deadline → no date line; free → "Gratis" |
| F2 | Dashboard "Mijn trajecten" shows the SAME card per enrollment WITH a "X% voltooid" badge + "Open traject" CTA; NO per-course checklist | Browser (enrolled user) | empty: not enrolled → existing empty-state; boundary: 0% and 100% both render a sane badge; mixed required+elective progress correct |
| F3 | The card is one partial: same markup/classes from both surfaces (only the progress badge + CTA target differ by the args) | Browser diff | denied: a logged-out visitor on catalog never sees a progress badge (no progress arg) |

## Design spec (from the screenshot + answers)

Card (vertical, `bg-surface-card rounded-[14px] shadow-card`):
- **Badge row**: `[Traject]` + status badge; PLUS a `[X% voltooid]` badge **only when `progress` arg is present** (enrolled). Reuse `badge-status` (add a `progress`/`voltooid` variant, or render an inline pill — decide in Task 2).
- **Title**: 17px bold, line-clamp-2.
- **Meta line 1**: "N opleidingen" + (electives > 0 ? " · waarvan M keuzemodule(s)" : ""). `_n()` plural both.
- **Meta line 2 (date)**: enrolled → "Gestart {registered_at month YYYY}"; catalog → deadline ? "Inschrijven tot {date}" : (omit).
- **Price**: bold, `stride_format_money` or "Gratis" (matches the detail page convention).
- **Footer**: catalog → "Bekijk traject →" (whole card links to the public permalink); dashboard → "Open traject" primary-sm button (links to `/mijn-account/trajecten/<slug>/`).
- **REMOVED**: the journey-dots strip (card-trajectory.php:88-99) and the dashboard per-course checklist (tab-trajecten.php parts loop).

## The card args contract (built by the helper, consumed by the partial)

```php
[
  'id'            => int,        // permalink / dashboard-url base
  'title'         => string,
  'status'        => string,     // OfferingStatus value → badge
  'course_count'  => int,        // getCourseCount()
  'elective_count'=> int,        // getElectiveGroupCount()  (NEW service method)
  'price'         => float,      // euros (detail-page convention)
  'deadline'      => string,     // '' or Y-m-d
  // enrolled-only (omit on catalog → no progress badge, "Bekijk traject"):
  'progress'      => int|null,   // 0-100, null = not enrolled
  'started_at'    => string,     // '' or Y-m-d (registered_at) for "Gestart …"
  'dashboard_url' => string,     // when enrolled; else permalink used
]
```

## File structure

- Modify: `web/app/mu-plugins/stride-core/Modules/Trajectory/TrajectoryService.php` — add `getElectiveGroupCount(int): int` (count of `getElectiveGroups()`).
- Create: `web/app/themes/stridence/helpers/trajectory-card.php` — `stridence_build_trajectory_card_args(int $trajectoryId, array $opts = [])`. Loads via require in functions.php (check how other helpers load).
- Modify: `web/app/themes/stridence/partials/card-trajectory.php` — rewrite to the shared contract: remove dots strip, add price + elective-count + progress badge + dual footer (catalog link vs dashboard CTA via a `mode` arg).
- Modify: `web/app/themes/stridence/archive-vad_trajectory.php` — query via repository; build args with the helper (no progress); pass to the card.
- Modify: `web/app/themes/stridence/templates/dashboard/tab-trajecten.php` — replace the bespoke ring+checklist card with the shared card, passing progress + started_at + dashboard_url; keep the section/empty-state shell; DELETE the parts-checklist block.
- Modify: `web/app/themes/stridence/partials/badge-status.php` — add a "voltooid"/progress pill variant if not rendering inline.
- Create: `tests/Integration/TrajectoryCardArgsTest.php` — the args-builder produces the right shape (course/elective counts, price, progress passthrough, started_at).
- Modify: `tests/acceptance/TrajectoryE2ECest.php` — F1 (catalog card: counts + price + no dots), F2 (dashboard card: % badge + no checklist). Reuse the existing self-sufficient fixture (it already creates a trajectory with mandatory + elective courses + an enrollment).

---

# Task breakdown

### Task 1: `getElectiveGroupCount` + the card args-builder

- [ ] Step 1: Failing integration test `TrajectoryCardArgsTest`: build args for a trajectory with N required + an elective group → `course_count`/`elective_count`/`price`/`deadline` correct; with a `progress`+`started_at` opt → passed through; without → `progress` null. RED.
- [ ] Step 2: Add `TrajectoryService::getElectiveGroupCount()` (`return count($this->getElectiveGroups($id))`). Create the theme helper `stridence_build_trajectory_card_args()` reading getTrajectory/getCourseCount/getElectiveGroupCount; merge `$opts` (progress, started_at, dashboard_url). Wire the require in functions.php.
- [ ] Step 3: GREEN; unit suite; commit.

Unit/integration test: args shape. Tier A (presentation-logic with branching — counts + enrolled vs not).

### Task 2: Rewrite the shared card partial

- [ ] Step 1: Rewrite `card-trajectory.php` to the contract: badge row (+ "X% voltooid" pill when `progress !== null`), title, meta lines, price, date line, dual footer by `mode` ('catalog'|'dashboard'). Remove the dots strip. Decide the progress pill: inline span with badge tokens (simplest) vs a badge-status variant — prefer inline span to avoid touching the shared badge map.
- [ ] Step 2: Browser smoke from a scratch render (both modes) before wiring callers.

No unit test: Tier B — pure presentation; covered by F1/F2.

### Task 3: Wire the catalog (archive) page

- [ ] Step 1: Replace the raw `ntdst_data()->get()` with `ntdst_get(TrajectoryRepository::class)` active-trajectory read (match existing repo query methods; published + ordered). Build args via the helper (no progress). Pass `mode => 'catalog'`.
- [ ] Step 2: Browser verify `/trajecten` matches the design (counts, price, deadline, no dots); commit Tasks 2+3 together (card + first caller).

No unit test: Tier B — template wiring.

### Task 4: Wire the dashboard tab

- [ ] Step 1: In `tab-trajecten.php`, replace the bespoke card block (ring + header + parts checklist) with the shared card: pass `mode => 'dashboard'`, `progress => $progressPct`, `started_at => $traj['registered_at']`, `dashboard_url`. DELETE the `$allParts` checklist loop entirely. Keep the active/completed section split + empty-state.
- [ ] Step 2: Browser verify with an enrolled user (% badge shows, no checklist); commit.

No unit test: Tier B — template; covered by F2.

### ── REVIEW GATE — single cluster (tier: STANDARD) ──
`/code-review --effort=medium` + `code-simplicity-reviewer` + `ntdst-drift-reviewer Modules/Trajectory` (verify the archive drift fix) + feature-acceptance browser pass F1/F2/F3.

### Task 5: Acceptance coverage

- [ ] Extend `TrajectoryE2ECest`: F1 catalog (visit `/trajecten/`, assert the fixture trajectory's card shows "N opleidingen", "waarvan … keuzemodule", price, no `.journey-dots`/dots strip), F2 dashboard (enrolled user → "% voltooid" badge present, per-course checklist absent). Reuse the existing fixture + enrollment.
- [ ] Run green; commit.

## Stage 3 — close

1. `/integration` (unit + integration; the 6 pre-existing unrelated failures stay out of scope).
2. `test-effectiveness` over the diff (the enrolled-vs-catalog branch + empty-elective boundary).
3. `feature-acceptance` drive F1/F2/F3.
4. `/shakeout` — tier STANDARD: `reviewer` + `invariant-auditor` + `ntdst-drift-reviewer`.
5. `superpowers:finishing-a-development-branch`.

## Verification (manual)

```bash
ddev exec vendor/bin/phpunit -c phpunit-integration.xml.dist --filter TrajectoryCardArgs
ddev exec vendor/bin/codecept run acceptance TrajectoryE2ECest
# Browser: /trajecten/ → richer cards (counts, price, deadline, no dots);
#          login as a trajectory-enrolled user → /mijn-account/?tab=trajecten →
#          same card + "X% voltooid" badge + "Open traject", no per-course checklist.
```

## Known context for the implementer

- The partial MUST stay pure (no service calls) — the new theme helper does all lookups. This is the partial's documented contract (card-trajectory.php:9-12).
- `getTrajectory()` returns euros for price; the card formats with `stride_format_money((int)($price*100))` like the detail page. (The admin cents/euros bug is tracked separately in memory/bug_trajectory_price_unit_mismatch — do NOT entangle it here; the card just mirrors the detail-page read.)
- Dashboard progress is already computed in tab-trajecten.php (`$progressPct` from completed_count/total_required) — reuse it as the `progress` arg, don't recompute.
- "Gestart {month YYYY}" uses the enrollment's `registered_at` (already on `$traj`), formatted with `date_i18n('F Y', strtotime(...))`.
- Removed visuals: card-trajectory.php dots strip (lines ~88-99) and tab-trajecten.php parts-checklist (the `$allParts` foreach). The progress-ring partial is no longer used on the dashboard trajectory card (it stays for other dashboards — don't delete it).
- Duration line is intentionally NOT on the card (user skipped it); it remains on the detail page.
- E2E fixture already builds a trajectory with mandatory + elective + a real enrollment — reuse it for F1/F2, no new fixture.
