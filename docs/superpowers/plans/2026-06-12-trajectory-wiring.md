# Trajectory Wiring & Dead-Ends Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (or superpowers:executing-plans) to implement this plan task-by-task. Harness: netdust-agent:harnessed-development Class A — all gates fired at plan time (threat model 1a, invariants 1b, acceptance matrix 1g, wp-plan-requirements). On execution start, copy this plan to `docs/superpowers/plans/2026-06-12-trajectory-wiring.md` and append the verbatim netdust addendum to every implementer dispatch.

**Goal:** Make the trajectory feature actually work end-to-end: wire the inert elective-choices form to the (already-tested) selection backend incl. pure-LD electives, register the `trajectory_messages` field so the existing admin metabox + berichten tab function, fix the stale dashboard/cascade tests, and prove all of it with an acceptance suite.

**Architecture:** The choices flow keeps `vad_registrations.selections` (flat edition IDs) as the canonical store and adds a course-ID entry point: a new `stride_save_trajectory_choices` API action → `TrajectorySelection::setSelectionsFromCourses()` which maps course→edition via `getElectiveGroups()`, validates per-group counts at COURSE level, delegates edition-backed picks to the existing cascade path, and grants/revokes LD access for pure-LD picks via `LMSAdapterInterface`. One new read helper (`getSelectedCourseIds()`) becomes the single decision point all templates use to render "what did I pick".

**Tech stack:** WordPress mu-plugin (stride-core, NTDST framework), Alpine.js + ntdstAPI (theme), PHPUnit + wp-browser/Codeception.

**Class:** A (new feature/multi-task). Review clusters + tiers declared per cluster below.

---

## Golden path: form-data-flow (deviations must be named and justified)

- Built to `netdust-wp:ntdst-patterns` → `golden-paths/form-data-flow.md` — implementer reads it before task breakdown.
- Deviations: (1) the new action is registered in the EXISTING `EnrollmentFormHandler` next to its sibling `stride_save_session_selection` (same domain, same conventions) instead of a new handler class — symmetry beats scaffolding; (2) no new frontend form is scaffolded — the server-rendered `#elective-selection-form` in `tab-keuzes.php` gets an Alpine submit wrapper following the `interest.php` inline-component pattern.

## Threat model

> Written 2026-06-12 for the new `stride_save_trajectory_choices` API action and the pure-LD LD-access grant path. This section is the `/code-review` convergence target — reviews verify against the numbered mitigations below, not free-form.

### What we're defending

1. `wp_vad_registrations.selections` + child registration rows — another user's trajectory enrollment state (writes create/cancel child registrations and quotes via the cascade).
2. LearnDash course access grants — `setSelectionsFromCourses` calls `LMSAdapterInterface::grantAccess/revokeAccess`; an abuse path = free course access.
3. The choice-window/lock business rule (`choice_available_date`/`choice_deadline`, `selections_locked_at`) — bypassing it corrupts cohort planning.
4. `trajectory_messages` content — admin-authored; rendered to all enrolled users (stored XSS surface).

### Who we're defending against

- Anonymous visitors (IN scope — must be rejected before any read).
- Authenticated users targeting ANOTHER user's registration_id (IN scope — ownership check).
- Authenticated enrollees replaying/forging requests outside the choice window, after lock, or with course IDs not in the trajectory (IN scope).
- Admins authoring malicious message HTML (IN scope only as output-escaping; admins are trusted authors).
- Insiders with DB access (OUT of scope).

### Attacks to defend against

1. **IDOR on registration_id**: attacker posts someone else's registration_id to `stride_save_trajectory_choices`, rewriting their selections, cancelling their child registrations, and granting/revoking LD access on their account.
2. **Anonymous write**: the ntdst API `public_actions` filter could expose the action without auth (the interest/waitlist actions are public by design — this one must NOT be).
3. **Out-of-catalog course injection**: posting arbitrary course IDs (e.g. a paid course not in any elective group) to obtain a free `grantAccess` call.
4. **Window/lock bypass**: posting directly to the wire after `choice_deadline` or after `selections_locked_at` (UI hides the form; server must refuse).
5. **Count forging**: picking more than `min_choices` per group (extra cascade children / extra LD grants) or fewer (incomplete cohort) by bypassing client-side input constraints.
6. **CSRF**: cross-site post triggering a selection change for a logged-in victim.
7. **Stored XSS via trajectory_messages**: message title/body rendered unescaped in `tab-berichten.php`.

### Mitigations required

1. **Ownership at entry (INV-1)**: handler resolves the registration via `RegistrationRepository::find(absint($params['registration_id']))` and refuses unless `(int)$registration->user_id === get_current_user_id()` AND user is logged in. WP_Error `not_allowed`, no detail leak about foreign registrations (same error for "not found" and "not yours").
2. **Not a public action**: do NOT add the action to `ntdst/api/public_actions`. Test asserts an anonymous wire call is refused.
3. **Catalog-bound validation**: `setSelectionsFromCourses` accepts ONLY course IDs present in `getElectiveGroups($trajectoryId)`; unknown IDs → WP_Error `invalid_choice` (no partial application). `grantAccess` is called exclusively for validated pure-LD electives of THIS trajectory.
4. **Server-side window/lock checks**: reuse the existing `isChoiceWindowOpen()` + `areSelectionsLocked()` guards inside the service (already present in `setSelections`; the new course-level entry point must pass through the same guards — single decision point, not a copy).
5. **Per-group exact-count validation at course level**: mirror of `validateSelections` semantics (`required` = min_choices, exact match) computed over course IDs so pure-LD picks count toward the group; under- and over-picking both refuse.
6. **Nonce by framework (INV-2)**: the ntdst API layer verifies the per-action nonce; the handler adds none of its own. (Covered by using `ntdst/api_data/*` registration — verify, don't reimplement.)
7. **Escape on render**: `tab-berichten.php` outputs via `esc_html` (title/body) / `wp_kses_post` if rich text is required (check current template; keep its existing escaping, add where missing). Sanitize on save already happens in `TrajectoryAdminController::handleSave` — verify it sanitizes each message's keys.

### Out of scope (explicit deferrals)

- Rate-limiting choice submissions (idempotent writes; nuisance only) — deferred.
- Phased choices (per-group opens_at/deadline) — separate planned feature (`plans/2026-05-20-trajectory-phased-choices.md`); this plan keeps the single trajectory-level window.
- Admin-side selection editing UI — out of scope; admins use existing metaboxes.
- Capacity race between validation and cascade child-creation — handled by existing cascade `edition_full` partial-failure semantics; not re-engineered here.

### How to use this section

- Controller pre-flight: verify each task's code includes its numbered mitigations before dispatch.
- `/code-review`: "Verify the diff against the threat model in the plan; report each numbered mitigation as in place / missing / deferred."
- `/evaluate` retros: missing mitigations are plan-correction defects.
- Downstream phases (phased-choices): extend, don't re-litigate.

## Architecture invariants touched (cite, route through, never fork)

- **INV-1** authorization at entry point (handler ownership check).
- **INV-2** nonce verified by the framework API layer.
- **INV-3** all registration/trajectory data through `RegistrationRepository` / `TrajectoryRepository`.
- **INV-4** errors are `WP_Error`, logged once via `ntdst_log('enrollment')`.
- **INV-5** templates render via the framework loader (no plugin→theme calls).
- **INV-6** LD writes ONLY via `LMSAdapterInterface` (`grantAccess`/`revokeAccess`) — never direct LD functions.

## WP security requirements (per data-flow)

- [ ] API `stride_save_trajectory_choices`: framework nonce (INV-2) + logged-in + ownership check (mitigation 1) + sanitize (`absint` registration_id, `array_map('absint', selections)`) + catalog-bound validation (mitigation 3) + escape: n/a — JSON response of translated strings only.
- [ ] Admin save of `trajectory_messages` (existing flow, now actually persisting): nonce + capability already in `TrajectoryAdminController::handleSave`; sanitize each message field on save (`sanitize_text_field` title/date/type, `wp_kses_post` body); escape on output in `tab-berichten.php`.

## ntdst-core layering requirements

- [ ] Data access through repositories only (`RegistrationRepository`, `TrajectoryRepository`).
- [ ] No raw `wp_ajax_*` — register via `ntdst/api_data/*` filter in `EnrollmentFormHandler`.
- [ ] No swallowed `WP_Error` — propagate from service to handler to API response.
- [ ] Data API vocabulary: `trajectory_messages` REGISTERED in `TrajectoryCPT::getFields()` (this is the bug being fixed).
- [ ] No pass-through service methods: `setSelectionsFromCourses` adds mapping+validation+grants; `getSelectedCourseIds` adds derivation — neither is a pure repo passthrough.
- [ ] Service lifecycle: no new services; extend existing `TrajectorySelection` (plain DI class) + `EnrollmentFormHandler` (thin handler).

> These blocks are the convergence target for `/code-review` and the `ntdst-drift-reviewer` at shake-out. Reviewers verify the diff against the named golden-path slice + pillars + categories above — a gap is a one-line finding keyed to a named item, not a re-discovery.

## Acceptance flows

| # | Flow (intended use) | Faithful layer | Edges (all six classes per row) |
|---|---|---|---|
| F1 | Enroll in trajectory from `/trajecten/<slug>/inschrijving/` → dashboard shows trajectory + mandatory child editions | Browser | empty: trajectory with no courses still enrolls; denied: logged-out → login redirect; re-entry: second enroll → "al ingeschreven"; concurrent: double-submit creates one parent (existing dedup); boundary: enrollment_deadline passed → refused; mid-flow: (covered by existing enrollment suite — cite, don't re-test) |
| F2 | Open trajectory dashboard → Keuzes tab in OPEN window → pick per group → "Bevestig je keuze" → success state; reload shows picks checked; child registrations exist for edition-backed picks; LD access granted for pure-LD picks | Browser + DB + LD usermeta | empty: trajectory without electives → "Geen keuzevakken"; denied actor: wire call with another user's registration_id → refused, no row change (mitigation 1); wrong-order: submit before `choice_available_date` → server refuses (window UI also hidden); concurrent/double: submit twice with same picks → idempotent (one child per edition, single grant); boundary: exactly min_choices passes, min±1 refused (mitigation 5); mid-flow failure: one edition at capacity → `edition_full` error surfaced, no phantom success |
| F3 | Change picks within window → previous edition child cancelled, new created; deselected pure-LD course access REVOKED | Wire + DB | empty: submit identical picks (no-op); denied: after `choice_deadline` → refused; re-entry: change twice; concurrent: n/a — same dedup as F2; boundary: swap exactly one pick; mid-flow: cascade partial failure leaves consistent state (existing semantics, assert error surfaced) |
| F4 | Window states render correctly: before (preview + date), open (form), after (read-only summary of picks) | Browser | empty: no selections + closed window → "geen keuzes gemaakt"; denied: n/a (read-only); wrong-order: n/a; concurrent: n/a; boundary: deadline = today; mid-flow: n/a — excluded: pure display row |
| F5 | Admin authors a message in the trajectory metabox → enrolled user sees it on Berichten tab (newest first, deleted hidden) | Admin browser/DB + user browser | empty: no messages → empty state; denied: non-enrolled user can't reach trajectory dashboard (existing gate — assert); wrong-order: n/a; concurrent: n/a; boundary: `_deleted` message hidden; mid-flow: n/a — content feature |
| F6 | Materialen tab renders LD materials for accessible courses | Browser | empty: no materials → empty state; others excluded: read-only passthrough of LD content |

## File structure

- Modify: `web/app/mu-plugins/stride-core/Modules/Trajectory/TrajectoryCPT.php` (register `trajectory_messages`)
- Modify: `web/app/mu-plugins/stride-core/Modules/Trajectory/TrajectorySelection.php` (`setSelectionsFromCourses`, `getSelectedCourseIds`, course-level validation, pure-LD grant/revoke, phase-entry `course_ids`)
- Modify: `web/app/mu-plugins/stride-core/Handlers/EnrollmentFormHandler.php` (register + implement `stride_save_trajectory_choices`)
- Modify: `web/app/themes/stridence/templates/trajectory/tab-keuzes.php` (Alpine submit + checked-state via helper)
- Modify: `web/app/themes/stridence/templates/dashboard/tab-trajecten.php` (selection display via helper)
- Modify: `tests/acceptance/TrajectoryCascadeCest.php`, `tests/acceptance/DashboardCest.php` (stale-test fixes)
- Create: `tests/Unit/TrajectorySelectionFromCoursesTest.php`, `tests/Integration/TrajectoryMessagesFieldTest.php`, `tests/Integration/TrajectoryChoicesHandlerTest.php`, `tests/acceptance/TrajectoryE2ECest.php`

---

# Task breakdown

## ── CLUSTER 1 — backend wiring ── (provisional review tier: FULL — new API action on an auth/ownership surface, LD-access grants)

### Task 1: Register `trajectory_messages` field

**Files:** Modify `Modules/Trajectory/TrajectoryCPT.php` (getFields array); Test `tests/Integration/TrajectoryMessagesFieldTest.php`

- [ ] Step 1: Write failing integration test: `TrajectoryRepository->update($id, ['trajectory_messages' => [msg]])` then `getMessages($id)` returns the message; a `_deleted` message is filtered; messages sorted newest-first; `getField` returns `[]` default when unset. Run → expect FAIL (field unregistered → default returned).
- [ ] Step 2: Add to `TrajectoryCPT::getFields()` (alongside `linked_editions`, same json idiom):
```php
'trajectory_messages' => [
    'type' => 'json',
    'default' => [],
    'description' => 'Admin-authored messages/announcements shown on the trajectory dashboard',
],
```
- [ ] Step 3: Run test → PASS. Verify the EXISTING admin metabox round-trip manually-in-test: simulate `handleSave` fields array containing `ntdst_fields[trajectory_messages]` JSON → persisted + readable. Check `TrajectoryAdminController::handleSave` (line ~1139) sanitizes message keys; if not, add `sanitize_text_field`/`wp_kses_post` per threat-model mitigation 7. Check `tab-berichten.php` escapes output; add `esc_html`/`wp_kses_post` where missing.
- [ ] Step 4: Full unit suite + commit (`fix(trajectory): register trajectory_messages field — admin metabox and berichten tab were silently dead`).

Unit test: messages round-trip + `_deleted` filter + sort + default. Tier A (data-layer registration with read/write semantics).
Acceptance: drift pre-check clean — `/drift-reviewer Modules/Trajectory` no findings; security line satisfied.

### Task 2: `TrajectorySelection::setSelectionsFromCourses()` + `getSelectedCourseIds()`

**Files:** Modify `Modules/Trajectory/TrajectorySelection.php`; Test `tests/Unit/TrajectorySelectionFromCoursesTest.php`

Contract (Step 2.5 ground-truth `getElectiveGroups()` + `cascadeOnSelection()` signatures before dispatch):

```php
/**
 * Set elective choices from COURSE ids (the form's native shape).
 * Maps edition-backed electives to edition ids (existing cascade path) and
 * pure-LD electives (no edition_id in trajectory_config) to direct LD access.
 */
public function setSelectionsFromCourses(int $registrationId, array $courseIds): true|WP_Error
// 1. find registration, derive trajectoryId (reuse setSelections guards: window open, not locked)
// 2. Build catalog from getElectiveGroups(): courseId => ['edition_id' => int|0, 'group' => name]
// 3. Reject any submitted courseId not in catalog (WP_Error 'invalid_choice')   [mitigation 3]
// 4. Per-group exact-count validation over COURSE ids (required = min_choices)  [mitigation 5]
// 5. Partition: $editionIds (edition-backed picks), $pureLdCourseIds
// 6. Delegate edition-backed to the EXISTING write path (repository setSelections +
//    cascadeOnSelection + appendInitialSelectionPhase + choices_updated action) —
//    refactor setSelections so validation is injectable/skippable for the pre-validated
//    course-level path; do NOT duplicate the guard/cascade sequence.
// 7. Pure-LD diff vs previous picks (latest initial_selection phase entry's course_ids):
//    newly picked → LMSAdapterInterface::grantAccess($userId, $courseId)
//    deselected   → revokeAccess($userId, $courseId)                           [INV-6]
// 8. Phase entry now records both: ['edition_ids' => [...], 'course_ids' => $courseIds]

/** Single decision point for "which courses did this registration pick". */
public function getSelectedCourseIds(int $registrationId): array
// edition-backed: map selections (edition ids) back through getElectiveGroups' trajectory_config
// pure-LD: latest initial_selection phase entry's course_ids ∩ pure-LD catalog
```

- [ ] Step 1: Failing unit tests (stubs per `tests/Stubs` conventions): happy path mixed group; unknown course refused; under/over-pick refused; window-closed refused; locked refused; pure-LD grant on pick; revoke on deselect; idempotent resubmit (no duplicate grant); `getSelectedCourseIds` round-trip both kinds.
- [ ] Step 2: Implement; run RED→GREEN.
- [ ] Step 3: Full unit suite; commit.

Unit test: as above. Tier A (auth-adjacent business logic + LD grants; denial paths mandatory).
Acceptance: drift pre-check clean; mitigations 3/4/5 visibly implemented.

### Task 3: API action `stride_save_trajectory_choices`

**Files:** Modify `Handlers/EnrollmentFormHandler.php`; Test `tests/Integration/TrajectoryChoicesHandlerTest.php`

- [ ] Step 1: Ground-truth (Step 2.5): read `handleSaveSessionSelection` (line ~504) and mirror its registration, param shape, and response idiom exactly.
- [ ] Step 2: Failing integration tests: anonymous call refused (action NOT in `public_actions`) [mitigation 2]; foreign registration_id refused with same error as not-found [mitigation 1]; valid call persists selections + returns success message; WP_Error from service propagates [INV-4].
- [ ] Step 3: Implement:
```php
add_filter('ntdst/api_data/stride_save_trajectory_choices', [$this, 'handleSaveTrajectoryChoices'], 10, 2);

public function handleSaveTrajectoryChoices(mixed $data, array $params): array|WP_Error
{
    $userId = get_current_user_id();
    if (!$userId) {
        return new WP_Error('not_allowed', __('Je moet ingelogd zijn.', 'stride'));
    }
    $registrationId = absint($params['registration_id'] ?? 0);
    $courseIds = array_values(array_filter(array_map('absint', (array) ($params['selections'] ?? []))));

    $registration = ntdst_get(RegistrationRepository::class)->find($registrationId);
    if (!$registration || (int) $registration->user_id !== $userId) {
        return new WP_Error('not_allowed', __('Inschrijving niet gevonden.', 'stride')); // same msg for both
    }

    $result = ntdst_get(TrajectorySelection::class)->setSelectionsFromCourses($registrationId, $courseIds);
    if (is_wp_error($result)) {
        ntdst_log('enrollment')->warning('Trajectory choices refused', [...]);
        return $result;
    }
    return ['success' => true, 'message' => __('Je keuze is opgeslagen.', 'stride')];
}
```
- [ ] Step 4: RED→GREEN, full suite, commit.

Unit test: integration denials + happy path (above). Tier A.
Acceptance: drift pre-check clean; threat-model mitigations 1/2/6 verified in diff.

### ── REVIEW GATE — Cluster 1 (tier: FULL) ──
Commit cluster → `/integration` on diff → `/code-review --effort=high` with the threat model as convergence target → `/security-review` (threat model fired at plan time) → `security-sentinel` + finder fan-out. HALT until clear.

## ── CLUSTER 2 — frontend wiring + stale tests ── (provisional tier: STANDARD — templates/JS, no 1a surface)

### Task 4: Wire `#elective-selection-form` (tab-keuzes.php)

**Files:** Modify `templates/trajectory/tab-keuzes.php` (+ `templates/trajectory/dashboard.php` only if registration id isn't already passed — it is, as `$enrollment`)

- [ ] Step 1: Add inline Alpine component following `interest.php`'s pattern: `x-data="trajectoryChoices(config)"` on the form wrapper; config = `{registrationId: (int) $enrollment->id}`. Submit handler collects checked inputs (flat course-ID array), calls `ntdstAPI.call('stride_save_trajectory_choices', {registration_id, selections})`, shows success state (`Je keuze is opgeslagen`) or `e.message` error line; re-renders checked state from response or reload.
- [ ] Step 2: Fix checked-state derivation: replace `in_array($course->ID, $selections[$groupIndex] ?? [])` (wrong shape — selections are FLAT EDITION ids) with `in_array($course->ID, $selectedCourseIds, true)` where `$selectedCourseIds = ntdst_get(TrajectorySelection::class)->getSelectedCourseIds((int) $enrollment->id)`. Same fix in the closed-window summary block.
- [ ] Step 3: Manual smoke via browser (logged-in test user) before commit.

No unit test: Tier B — template/Alpine glue over a Tier-A-tested service; covered by Task 8's browser flows (deferral: real-browser seam → TrajectoryE2ECest).
**Sibling-site audit:** grep theme for every reader of `->selections` / `$selections[` (`tab-trajecten.php` line ~274 confirmed; check `trajectory/tab-voortgang.php`, `course-groups.php`, dashboard partials) — each must read through `getSelectedCourseIds()` or flat edition ids, never the grouped-course-id shape.

### Task 5: Fix selection display in `tab-trajecten.php` (+ audited siblings)

**Files:** Modify `templates/dashboard/tab-trajecten.php` + any sibling found by Task 4's audit

- [ ] Replace grouped-shape reads with `getSelectedCourseIds()`; group-chosen boolean = group's course ids ∩ selected ids count ≥ required.
- [ ] No unit test: Tier B — display mapping; asserted by Task 8 acceptance.

### Task 6: Fix stale/failing tests (product ruling: collapsed-by-default is correct)

**Files:** Modify `tests/acceptance/DashboardCest.php`, `tests/acceptance/TrajectoryCascadeCest.php`

- [ ] Step 1: DIAGNOSE `TrajectoryCascadeCest::cohortChildDoesNotAppearAsStandaloneCard` first (`superpowers:systematic-debugging` if it's a product bug): fixture builds a direct edition+registration; determine why 0 expandable cards render (suspect: fixture edition missing `_ntdst_course_id`/format meta so the dashboard service filters it, or redesign moved direct cards). If product bug → separate TDD cycle; if fixture/selector drift → fix the test.
- [ ] Step 2: Rewrite `DashboardCest`'s two auto-expand tests per Stefan's ruling (collapsed by default): assert cards render collapsed and EXPAND on click (keep the interaction coverage, drop the auto-expand assertion).
- [ ] Step 3: Run both Cests green; commit.

No unit test: Tier B — test-only changes (unless Step 1 finds a product bug → Tier A TDD cycle).

### ── REVIEW GATE — Cluster 2 (tier: STANDARD) ──
`/integration` → `/code-review --effort=medium` + `code-simplicity-reviewer` + feature-acceptance browser pass on F2/F4. HALT until clear.

## ── CLUSTER 3 — acceptance proof ── (provisional tier: STANDARD)

### Task 7: `TrajectoryE2ECest`

**Files:** Create `tests/acceptance/TrajectoryE2ECest.php`

Self-sufficient fixtures (follow `QuestionnaireBuilderCest`/`PreEnrollmentFlowCest` conventions: own trajectory post + meta, own editions, own user; snapshot/restore nothing global; cleanup in `_after`). Trajectory fixture `courses` json must mirror the seed matrix shape (ground-truth against `scripts/seed/matrix.php` + `TrajectoryRepository::getCourses()` before writing): one mandatory edition entry, one elective group with 1 edition-backed + 1 pure-LD course (`min_choices: 1`), choice window open (`choice_available_date` yesterday, `choice_deadline` +7d).

Drive the acceptance matrix:
- [ ] F1: browser enroll → registration row + mandatory child + redirect; Mailpit got `stride-trajectory-enrolled` mail.
- [ ] F2: keuzes tab shows form; pick edition-backed option → submit → success text; DB: child registration for that edition; reload → checked. Then re-run with the pure-LD option → LD usermeta `course_<id>_access_from` exists.
- [ ] F3: change pick → old child cancelled, new created; deselected pure-LD course access revoked.
- [ ] F2-denials (wire): foreign registration_id refused; window-before refused (set `choice_available_date` future); after `choice_deadline` refused; over-pick refused; unknown course id refused — each with no DB change.
- [ ] F4: window-before renders preview; window-after renders summary with picked titles.
- [ ] F5: write a message via the option-backed field (DB write mirroring admin save), assert Berichten tab shows it and `_deleted` one hidden; non-enrolled user gets the access gate.
- [ ] F6: materialen tab renders without error (empty state acceptable).
- [ ] Run Cest green; commit.

Unit test line: n/a — this IS the acceptance layer (Tier A behavioral coverage of F1–F6).

### ── REVIEW GATE — Cluster 3 (tier: STANDARD) ──

## Stage 3 — phase close (after all clusters)

1. `/integration` full run (unit + integration suites; note the 6 pre-existing unrelated integration failures — PendingApprovals/CatalogBatchHydration/PartnerPathMigration — are NOT this plan's scope; do not let them mask new failures).
2. `test-effectiveness` audit over the branch diff (denial paths, wire-mock leaks, unmounted guards).
3. `feature-acceptance` Situation B: drive the F1–F6 manifest (TrajectoryE2ECest is the executable form).
4. `/shakeout` — branch tier FULL (cluster 1 touched a 1a surface): 5-persona panel + `ntdst-drift-reviewer` on `Modules/Trajectory` + `Handlers`.
5. `superpowers:finishing-a-development-branch` (commits to main per project convention, push after green).
6. `compounding` proposals (CODE-MAP: trajectory choices flow + the new convergence point `getSelectedCourseIds`).

## Verification (how to test end-to-end by hand)

```bash
ddev exec vendor/bin/phpunit --testsuite Unit --filter Trajectory
ddev exec vendor/bin/phpunit -c phpunit-integration.xml.dist --filter "'Trajectory|Cascade|Messages'"
ddev exec vendor/bin/codecept run acceptance TrajectoryE2ECest
ddev exec vendor/bin/codecept run acceptance TrajectoryCascadeCest
ddev exec vendor/bin/codecept run acceptance DashboardCest
# Manual: log in as a fresh user → enroll in "Traject Jeugdgezondheidsspecialist" →
# dashboard → trajectory → Keuzes → pick → Bevestig → reload shows picks;
# wp-admin trajectory edit → Berichten metabox → add message → user sees it on Berichten tab.
```

## Known context for the implementer

- `selections` column = FLAT edition IDs (canonical; do not change storage shape).
- The keuzes form posts course IDs — the mapping lives server-side (Task 2), templates read through `getSelectedCourseIds()` only.
- `getElectiveGroups()` returns `[{name, required(min_choices), courses: WP_Post[] each with ->trajectory_config['edition_id']}]` (TrajectoryRepository:148).
- Pure-LD elective = config entry with no/0 `edition_id` (`validateSelections` currently skips them — Task 2 supersedes that skip with course-level counting; keep `validateSelections` behavior for its existing callers).
- Cascade semantics (child add/cancel, `edition_full` partial failure) are existing + integration-tested — delegate, never duplicate (TrajectoryCascadeService::cascadeOnSelection:119).
- 6 pre-existing integration failures are unrelated (verified by bisect 2026-06-12); don't chase them here.
