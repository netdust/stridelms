# Admin Workspace — Phase 1D — Alpine UI (grid + Vandaag + Dossier) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:subagent-driven-development` (recommended) or `superpowers:executing-plans` to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Port the static workspace mockups (Vandaag worklist home, the multi-select Inschrijvingen grid with bulk bar + group-by, the Dossier case view) into the existing `strideApp` Alpine app and `dashboard.php`, fed by the real 1A/1B/1C endpoints — and strangle the `getStats`/`getActionQueue`/`getUserDetail` slice of `AdminAPIController` out into services while we are in those methods.

**Architecture:** Additive Alpine components inside the existing 958-line `admin-dashboard.js` `strideApp` factory; template partials added to `dashboard.php` (optionally split into `templates/admin/dashboard/*.php`); mockup CSS (`workspace.css` + `grid.css`) merged into the admin stylesheet `AdminDashboardService` injects, reconciled with the existing `--sd-` tokens. Backend: extract `AdminStatsService` (hosts `getStats` + the `getActionQueue` SQL **and the NEW 5-queue worklist counts**) and `AdminUserService` (hosts `getUserDetail`), behavior-preserving, existing suite green before+after.

**Tech Stack:** WordPress (Bedrock) · NTDST Core (DI, repositories) · `stride-core` mu-plugin · Alpine.js · existing `this.api()` `X-WP-Nonce` fetch helper · Playwright (acceptance, `playwright.config.ts` at repo root).

**Work classification:** **Class B freshness review** of an authored spec (`2026-06-13-admin-workspace-spec.md`) + phase-map, writing the executable Phase-1D plan. Cluster tier: **STANDARD** (UI over already-gated endpoints; one additive backend extension behind the existing `/admin/stats` permission_callback). Ends at `── REVIEW GATE C1 ──`.

**Gate-firing decision (explicit):**
- **1a threat-modeling — DOES NOT FIRE.** Ran the trigger list literally: no new AJAX/REST handler with a new auth model, no user-controlled URL, no new credential, no untrusted parser. The single backend touch (extending `/admin/stats` with 5 counts) lives behind the existing `getStats` route's `canViewAdmin` permission_callback and reads structured columns only — no new attack surface. The spec's `## Threat model` (M1–M10) already governs the consumed endpoints; this cluster inherits it, adds nothing.
- **1b architecture-invariants — APPLIES (cite, don't author).** INV-3 (the strangle must keep `vad_registrations` access in repos — `check-invariants.sh` treats INV-3 as advisory, so the rule here is *do not add new raw SQL outside the repo during extraction*), INV-6b (Dossier selections via `TrajectorySelection`/the detail payload, not the raw `selections` column), INV-7 (any edition-status display reads `getEffectiveStatus`). Doc: `/home/ntdst/Sites/stride/ARCHITECTURE-INVARIANTS.md`.
- **1g feature-acceptance — FIRES.** This IS the F1–F6 browser-driven acceptance cluster. Matrix embedded below; the cluster-C1 integration gate is the Playwright/Chrome pass driving the REAL admin page (not jsdom).
- **API/boundary design — DOES NOT FIRE.** No new contract; the `/admin/stats` extension is additive-only to an existing response shape.

---

## Freshness-review preamble — premises ground-truthed (2026-06-22) + DRIFT found

Every premise the brief/spec/phase-map asserts was checked against current source. Evidence is `file:line`.

### Confirmed (no drift)
- **1A artifacts exist.** `RegistrationTransitions.php` (`Modules/Enrollment/`, the §2.1 single-source map, Task 1.1 ✓), `RegistrationRepository::queryForGrid()` (`:1571`) + `queryForGridGrouped()` (`:1660`), `AdminRegistrationQueryService` (`Admin/AdminRegistrationQueryService.php`, `final class`, ctor-injected `RegistrationRepository`+`QuoteRepository`, **no raw `$wpdb` of its own**, registered in `plugin-config.php:56`). **This is the extraction-shape precedent the strangle must match.**
- **1A/1B/1C endpoints all registered** in `AdminAPIController`: `/admin/registrations` (`:483`), `/admin/stats` (`:58`), `/admin/editions/options` (`:119`), `/admin/trajectories/options` (`:265`), `/admin/trajectories` (`:237`), `/admin/trajectories/{id}` (`:296`), `/admin/action-queue` (`:360`), `/admin/users/{id}/detail` (`:405`).
- **1C bulk handlers exist** — `Handlers/BulkRegistrationHandler.php` registers all 9 smart actions + `stride_bulk_set_field` on `ntdst/api_data/*` (`:59-72`).
- **`users/{id}/detail` is consumed** by the existing app at `admin-dashboard.js:597` — the Dossier reuse premise holds.
- **Playwright config present** at repo root (`playwright.config.ts`) — the acceptance gate's faithful UI layer is real, not aspirational.
- **The mockup port target is sound:** `grid.js` (mockup) provides the concrete component to port — `corpus`/`filtered`/`pageRows` (mock client-pages; prod server-pages), `selected{}` object-as-Set, `bulkActions` from `WS.actionsForStates` (the §2.1 mirror), the partial-failure report modal, `QUEUE_FILTER` (queue→filter+armAction), `childRegsByTrajectory` (parent→child join). `data.js` defines `SMART_ACTIONS`/`QUEUES`/`QUEUE_FILTER`/`REG_STATUS`/`OFFERTE_STATUS`.

### DRIFT found (act on these)

1. **Controller grew, ALL phase-map line numbers are stale.** Phase map §1 says 3,627 lines / 128 `$wpdb`; current = **4,013 lines / 173 `$wpdb` / 28 routes** (`wc -l`, `grep -c`). Method ranges shifted hard:
   | Method | Phase-map line | **Actual line** |
   |---|---|---|
   | `getStats` | 442–757 | **`:512`** |
   | `getActionQueue` | 2187–2347 | **`:2573`** |
   | `getUserDetail` | 2556–2892 | **`:2942`** |
   | `getEditionRegistrations` | 1297–1419 | **`:1540`** |
   Shared-helper hazard lines also shifted: `fetchPostTitles` `:3491` (map said 3105), `batchCountUserRegistrations` `:3536`, `fetchSessionCountByEdition` `:3570`, `fetchUserAttendanceByEdition` `:3608`, `enrichAuditContexts` `:3442`, `lineItemsToEuros` `:3652`, `buildApprovalItem` `:3674`, `buildCourseTaxonomyJoin` `:1413`, `fetchTaxonomyTerms` `:1382`. **Re-locate by `grep -n 'function <name>'` at execution time; do not trust the map's numbers.**

2. **Task 2.4 (cache-bust events) is ALREADY DONE.** The spec/§2.3 frames `stride/registration/bulk_completed` + `quote_status_changed` as new events to add. They are **already wired** in `AdminDashboardService.php:92-93` (the bust list), landed in 1C. **Phase 1D adds nothing here** — note it so the implementer does not re-add or "fix" it.

3. **`getActionQueue` is only HALF-extracted.** `ActionQueueService.php` exists (extracted 2026-05-14) but it only owns the *rule-evaluation* half (`evaluate(array $rules, array $data)`). The **SQL data-gathering** (19 `$wpdb` calls, `:2573-~2730`) is still in the controller. So the 1D strangle of `getActionQueue` moves its SQL into `AdminStatsService` and has `AdminStatsService` call the existing `ActionQueueService::evaluate()` for the rule layer — it does NOT re-implement rule evaluation.

4. **The `/admin/stats` ↔ 5-queue-counts gap is REAL and larger than "extend".** `getStats` (`:512-~900`) returns top-line dashboard summary (`upcomingEditions`, `totalRegistrations`, `pendingQuotes`, `pendingRegistrations`, `todaySessions`, `openTrajectories`, today's-sessions detail, upcoming-editions detail, recent-registrations, week-over-week, capacity alerts). **Of the 5 worklist queues (§1), only "Wacht op goedkeuring" overlaps** (`pendingRegistrations`). The other four — `waitlist + open capacity`, `confirmed + quote ≠ Exported` (offerte-opvolging), `completed + no LD cert`, `interest + age > N days` — have **no existing count**. So Task 3.3's "live counts from extended `/admin/stats`" requires **genuine new backend compute** (4 new count queries), which lands in `AdminStatsService` (drift #3's home), scoped to active editions per §10. This is the one real backend deliverable hiding inside a "Tier-B UI" phase.

5. **The §10.4 `loadQuoteEditions` repoint bonus is ALREADY DONE.** Spec lists repointing the heavy quotes-filter at `/admin/editions/options` as a 1B follow-on; `admin-dashboard.js:504` already calls `/admin/editions/options?scope=all`. No work in 1D.

6. **Two bulk actions are HONEST DEFERRED STUBS, not full handlers.** `stride_bulk_message` (`BulkRegistrationHandler.php:396`) and `stride_bulk_generate_doc` (`:420`) are registered stubs (Phase 2/3 real impl). **Consequence for the UI (3.2/3.3):** the bulk bar + the "nocert"/"oldinterest" queues arm these actions and must render their **deferred response gracefully** (a "nog niet beschikbaar" / no-op toast), not assume a success report. The mockup's `SMART_ACTIONS` lists them as armable — the prod port must handle the stub response shape.

7. **Branch naming.** Current branch is `feat/admin-workspace-1b-pickers-traj-filter` — 1C landed on it but it is still 1B-named. **The implementer must branch fresh for 1D** off the merge base (do NOT continue on the 1B branch). (Per harness, the planner does not branch.)

8. **3.4a is a TWO-part add, not "one line."** The spec calls it a one-line addition to `KNOWN_ACTIONS`. Ground-truth: the audit action string is **`session.selections_updated`** (written `AuditBridge.php:449`, fired `SessionSelection.php:76`). It is absent from `AdminActivityMapper::KNOWN_ACTIONS` (`:16-36`) **AND** has no arm in the `resolve()` `match($action)` (`:71+`). So 3.4a must (a) add it to `KNOWN_ACTIONS` *and* (b) add a `match` arm returning `['type', 'Dutch text']`, else the event is "known" but renders empty. The RED test must assert the rendered text, not just `isKnownAction`.

**Net:** the cluster is correctly scoped, but its single "extend `/admin/stats`" line is the real engineering (4 new queue counts inside the new `AdminStatsService`), the strangle line numbers are all stale, `getActionQueue` is half-done, two bulk targets are stubs the UI must tolerate, and 3.4a is two edits. No premise is fatally wrong; the spec's design holds.

---

## Strangle plan (god-class, §12.4 — behavior-preserving, existing tests are the net)

**Services to extract (matching the `AdminRegistrationQueryService` precedent: `final class` in `namespace Stride\Admin`, ctor-injected dependencies, no raw `$wpdb` *invented*, registered in `plugin-config.php`).**

### S1 — `AdminStatsService` (hosts `getStats` + `getActionQueue` SQL + the NEW 5 queue counts)
- **Extracts:** `getStats` body (`:512`, 32 `$wpdb`) and the `getActionQueue` SQL data-gathering (`:2573`, 19 `$wpdb`). The controller methods become thin: build request params → call service → `WP_REST_Response`.
- **Reuse, don't duplicate:** `getActionQueue`'s rule-evaluation already lives in `ActionQueueService::evaluate()` — `AdminStatsService` gathers the data then calls `ActionQueueService::evaluate($rules, $data)`. Do not re-implement rule logic.
- **NEW compute (drift #4):** add `getWorklistQueueCounts(array $activeEditionIds): array` returning the 5 counts:
  - `pending` = `RegistrationRepository::countByEditions($ids, ['pending'])` (or status-breakdown).
  - `waitlist_open` = waitlist rows whose edition has open capacity (per-edition capacity check, read `getEffectiveStatus`/capacity via existing batch helpers — INV-7).
  - `offerte_opvolging` = `confirmed` rows whose linked quote is absent OR `status ≠ Exported` (the §0 #5 two-step resolver — **reuse `AdminRegistrationQueryService`'s offerte resolver**, do not fork a 2nd "paid-proxy" definition — Sibling-site audit item 1).
  - `nocert` = `completed` + `completed_at` set + no LD cert (`LearnDashHelper::getCertificateLink`).
  - `oldinterest` = `interest` + `registered_at` older than N days.
  - **All scoped to the active-edition subset** (§10: `countByEditions`/`statusBreakdownByEditions` take an explicit ID set — feed the active subset, never a corpus scan).
- **Shared-helper routing:** `getStats`/`getActionQueue` use `BatchQueryHelper::batchGet*` (already a shared Infrastructure class — leave it). They do NOT touch the §1 hazard helpers (`fetchPostTitles`/`buildApprovalItem`/etc. are used by `getUserDetail`/quotes/approvals, not stats) — verified by reading the method bodies. **No hazard helper needs relocating for S1.**

### S2 — `AdminUserService` (hosts `getUserDetail` for the case view)
- **Extracts:** `getUserDetail` body (`:2942`, 12 `$wpdb`).
- **Shared-helper hazard check (§1) — THIS is where the hazards bite.** `getUserDetail` is the consumer of `fetchPostTitles` (`:3491`), `batchCountUserRegistrations` (`:3536`), `fetchSessionCountByEdition` (`:3570`), `fetchUserAttendanceByEdition` (`:3608`), `lineItemsToEuros` (`:3652`), `enrichAuditContexts` (`:3442`) — confirm each usage at execution time. **These are cross-domain helpers (quotes/approvals/activity also use several).** Route them to a **shared location, NOT into `AdminUserService`** — options: a `Stride\Admin\Support\AdminBatchHelpers` trait or a small shared service. Pick the one already implied by the codebase; if none, a stateless trait the controller + both new services `use`. **Do not let `AdminUserService` privately own a helper that `getQuotes`/`getActionQueue` also call** — that is the exact mistake §1 warns against.
- The case-view trajectory section is **out of scope (1E/C2)** — `AdminUserService::getUserDetail` returns edition/quote/attendance/selections only, as today.

### Sequencing (relative to UI tasks — call-out)
- **S1 BEFORE Task 3.3.** Extending `/admin/stats` with the 5 queue counts is far cleaner inside the extracted `AdminStatsService` than bolting onto a 32-`$wpdb` controller method. So: extract `AdminStatsService` (behavior-preserving, suite green), THEN add `getWorklistQueueCounts` to it, THEN wire Task 3.3's Vandaag UI to the extended endpoint. **S1 is a prerequisite of 3.3, not a parallel task.**
- **S2 BEFORE Task 3.4.** Extract `AdminUserService` (behavior-preserving) before the Dossier UI consumes `users/{id}/detail`, so the case view is built over the thin, extracted method.
- Both extractions are **green-before-and-after**: run the existing integration suite (`AdminStats`/`getUserDetail` coverage) before the extraction to capture the baseline, extract, run again — identical. The extraction itself is NOT a Tier-A test-bearing task (no behavior change); the *new* `getWorklistQueueCounts` IS (drift #4 → Task 3.3 carries a backend test contract for the counts).

---

## Acceptance flows

> Per `netdust-agent:feature-acceptance` (situation A — authored at plan-time). One row per intended-use flow; the Edges column enumerates the six classes (empty/zero, denied actor, wrong-order/re-entry, concurrent/double, boundary, mid-flow failure). This is the **subset of the spec's F1–F8 that Phase 1D ships** — F7/F8 (trajectory tab + trajectory case-view section) are cluster C2 (Phase 1E), excluded here. The cluster-C1 integration gate **drives each of F1–F6 through the REAL admin page via Playwright/Chrome (not jsdom)** AND the API layer, emitting a pass/fail/not-reachable manifest. No UI flow is `pass` without a browser driving it.

| # | Intended-use flow | Happy path | Edges (all six — mandatory) |
|---|---|---|---|
| **F1** | Open a worklist queue → grid pre-filtered | Click "Wacht op goedkeuring" on Vandaag → grid loads filtered `status=pending`, bulk-approve armed (mockup `QUEUE_FILTER`) | **empty:** queue has 0 rows → "Geen inschrijvingen wachten op goedkeuring" empty state (mockup `emptyTitle()`), no bulk bar. **denied:** `stride_supervisor` (view) opens Vandaag → sees counts (read) but the armed bulk bar is absent/disabled (server still enforces M2). **re-entry:** queue clicked twice → idempotent, no duplicate filter stacking. **concurrent:** another admin approves a row while open → count refreshes on next `/admin/stats` load (M10 bust already wired). **boundary:** queue with `per_page`+1 rows → server pagination control appears. **mid-flow:** `/admin/stats` rejects → queue shows "kon niet laden", grid still openable. |
| **F2** | Multi-select N rows → bulk approve with 1 failure → partial-success report | Select 10 pending → "Goedkeuren" → 9 confirmed, 1 fails → report "9 geslaagd, 1 mislukt", failed row expandable (mockup result modal) | **empty:** 0 selected → bulk button disabled. **denied:** view-only user's payload reaches `stride_bulk_approve` → 403 before loop (M2, tested in 1C). **wrong-order:** a selected row already `confirmed` → benign no-op in report, not double-grant (M9). **concurrent:** two admins bulk-approve overlapping selections → each row's single-item path idempotent. **boundary:** select-all across pages → the select-all-as-filter model (mockup `selectAllFiltered`; the capped server expansion is Task 4.1/Phase 1F — in 1D, select-all on the loaded filtered set + a confirm). **mid-flow:** handler fails on row 5 → rows 1–4 applied, 5 failed, 6–10 processed (non-atomic). **stub-tolerance (drift #6):** if a `stride_bulk_message`/`stride_bulk_generate_doc` is armed, the UI renders the deferred-stub response as a "nog niet beschikbaar" toast, not a crash. |
| **F3** | Group-by ("Indelen per") → collapsed aggregates | Grid → "Indelen per Editie" → rows collapse into per-edition groups showing count / % afgerond / aanwezigheid % / offerte-verdeling (mockup `groups`) | **empty:** an edition group with 0 rows after a co-filter → group omitted. **denied:** view-only can group (read) but bulk-on-group disabled. **wrong-order:** group-by then change filter → server recomputes aggregates (`queryForGridGrouped`), not stale client tally. **concurrent:** a row mutates while grouped → next load reflects it. **boundary:** group-by on a column with 1 distinct value → single group. **mid-flow:** grouped query errors → "kon niet groeperen", flat list remains. |
| **F4** | Filter by status + company (+ pipeline funnel) | Grid → pipeline chip `Bevestigd` + company=X → server-side indexed page returns matches; funnel shows per-stage live counts (mockup `statusCount`) | **empty:** no rows → empty state. **denied:** unauth → `/admin/registrations` 401/403 (M1). **wrong-order:** invalid `status`/`sort` in URL → server rejects to whitelist (M4), not 500. **concurrent:** n/a (read). **boundary:** `per_page` over cap → clamped server-side (M4). **mid-flow:** malformed `sort` → default sort used. |
| **F5** | Open a row → Dossier case view, all stages | Click a row → Dossier (via `users/{id}/detail`) → person-headed registrations + all `enrollment_data` stages (3-key shape, empty hidden / with-data closed-then-open) + offerte-status + attendance + selections (INV-6b via the payload) + history timeline + state-appropriate buttons from the §2.1 map | **empty:** registration with no stages submitted → missing stages hidden, "Nog geen gegevens ingediend", no crash. **denied:** view-only opens (read OK) but action buttons hidden. **wrong-order:** open a `cancelled` registration → terminal, muted "geen acties", no transition buttons (§2.1). **concurrent:** mutates elsewhere while open → reflected on reopen. **boundary:** person with many registrations → person-headed list, expand-one. **mid-flow:** `users/{id}/detail` errors → "kon dossier niet laden", grid intact. **dedup-check:** NO separate "Vragenlijst" block — intake answers render once under the `intake` stage. **trajectory section absent here** (it is F8/C2). |
| **F6** *(M7 guard, UI-layer)* | `bulk_set_field` on a safe column | Select rows → set `tags` → applied | **empty:** 0 selected → disabled. **denied:** view-only → 403 (M2). **wrong-order:** UI never offers `status`; if a crafted payload smuggles `field=status` → **400 server-side** (M7 — the denial is tested in 1C Task 2.3; the UI must not expose status in the picker). **concurrent:** two admins set `tags` on overlapping rows → last-write-wins on the dumb column. **boundary:** set field on select-all → batched. **mid-flow:** one row's write fails → per-row report. |

**3.4a timeline check (folds into F5's verification):** the Dossier history timeline must render a `session.selections_updated` event with a Dutch label (proves drift #8 closed) alongside an attendance event.

---

## Per-task breakdown — Phase 1D (cluster C1)

> Tier tags per `netdust-agent:testing-workflow`. The UI tasks (3.1–3.4, 3.6-excluded) are **Tier B** — presentational Alpine wiring over already-tested endpoints; their behavior is proven by the F1–F6 acceptance pass at the integration gate, NOT bespoke unit tests. The two test-bearing tasks are the **`getWorklistQueueCounts` backend compute** (folded into 3.3, Tier-A contract) and **3.4a** (Tier A, RED-first). The strangle extractions S1/S2 are behavior-preserving refactors gated by the *existing* suite (green before+after), not new tests.

### ── strangle, before the UI it feeds ──

#### Task S1: Extract `AdminStatsService` (getStats + getActionQueue SQL)
**Files:** Create `web/app/mu-plugins/stride-core/Admin/AdminStatsService.php`; Modify `AdminAPIController.php` (`getStats` `:512`, `getActionQueue` `:2573` → thin delegators); Modify `plugin-config.php` (register service); shared helpers (if any cross-domain) → common location per §1.
- **Tier:** behavior-preserving refactor. `no bespoke unit test: covered by the EXISTING /admin/stats + /admin/action-queue integration tests — green before AND after the extraction is the proof.`
- [ ] Run the existing stats/action-queue integration tests, capture baseline GREEN.
- [ ] Create `AdminStatsService` (final class, `namespace Stride\Admin`, ctor-inject what `getStats`/`getActionQueue` need; call `ActionQueueService::evaluate()` for the rule half — do NOT re-implement it, drift #3). Move the SQL bodies verbatim.
- [ ] Make the controller methods thin delegators; register the service in `plugin-config.php` after `AdminRegistrationQueryService::class`.
- [ ] Route any §1 hazard helper used by these methods to a shared location (verify: `getStats`/`getActionQueue` mostly use `BatchQueryHelper` — leave that; relocate only a genuinely shared private helper).
- [ ] Re-run the same tests, confirm identical GREEN; commit.

#### Task S2: Extract `AdminUserService` (getUserDetail)
**Files:** Create `web/app/mu-plugins/stride-core/Admin/AdminUserService.php`; Modify `AdminAPIController.php` (`getUserDetail` `:2942` → thin); Modify `plugin-config.php`; relocate the cross-domain hazard helpers it shares (`fetchPostTitles`/`batchCountUserRegistrations`/`fetchSessionCountByEdition`/`fetchUserAttendanceByEdition`/`lineItemsToEuros`/`enrichAuditContexts`) to a shared `Stride\Admin\Support\AdminBatchHelpers` trait/service used by the controller + both new services.
- **Tier:** behavior-preserving refactor. `no bespoke unit test: covered by the EXISTING getUserDetail integration test — green before AND after.`
- [ ] Baseline GREEN on the existing user-detail integration test.
- [ ] **Hazard-routing first (§1):** before moving `getUserDetail`, identify which of its helpers are also called by `getQuotes`/`getActionQueue`/approvals (grep each helper name's call sites). Move shared ones to the shared trait/service; only genuinely user-detail-private logic goes into `AdminUserService`.
- [ ] Create `AdminUserService`, move `getUserDetail` body, controller delegates; register in `plugin-config.php`.
- [ ] Re-run, identical GREEN; commit.

### ── REVIEW GATE C1 tasks (UI) ──

#### Task 3.1: Grid component in `strideApp` (server-side paged)
**Files:** Modify `web/app/mu-plugins/stride-core/assets/js/admin-dashboard.js` (add `registrationsGrid` Alpine state + `this.api('/admin/registrations?…')` fetch, port from mockup `grid.js`); Modify `templates/admin/dashboard.php` (add the Inschrijvingen tab/partial, optionally `templates/admin/dashboard/inschrijvingen.php`).
- **Tier B.** `no unit test: Tier B, presentational Alpine wiring over the tested /admin/registrations endpoint — covered by the F2/F3/F4 acceptance pass.`
- [ ] Port the grid state from mockup `grid.js` BUT **rows = one server page, never the full set** (§5 hard rule): `page`/`perPage`/`sortKey`/`sortDir`/`groupBy`/`filters` become **server params** on `/admin/registrations` (drop the mockup's client `filtered`/`pageRows` slicing). Pipeline funnel chips (`statusCount`) read the server's per-status counts. Bind filter/sort/group controls → refetch. Reconcile structured-only filters (M4/M5 — no dietary/JSON filter, Sibling-site audit item 3). Commit.

#### Task 3.2: Multi-select + bulk-action bar + nonce arming
**Files:** Modify `admin-dashboard.js` (selection model, bulk bar, per-action nonce map from the admin bootstrap, partial-failure report modal).
- **Tier B.** `no unit test: Tier B, UI state (selection + bulk bar) — server enforcement already tested in 1C (2.1–2.3); behavior proven by F2/F6 acceptance.`
- [ ] Port `selected{}` (object-as-Set), `bulkActions` from `RegistrationTransitions`'s client mirror (`actionsForStates` — the §2.1 single source, Sibling-site audit item 2; do NOT hard-code a second button set), top-3 + overflow. Arm `wp_create_nonce('<action>')` per chosen action (the `{actionName: nonce}` map printed into the bootstrap, §2.3). Render the per-row `{succeeded[],failed[]}` report. **Handle the stub response (drift #6):** `stride_bulk_message`/`stride_bulk_generate_doc` return a deferred response → render "nog niet beschikbaar", not a crash. Commit.

#### Task 3.3: Vandaag worklist home (5 queues) + group-by rendering + the `/admin/stats` extension
**Files:** Modify `AdminStatsService.php` (add `getWorklistQueueCounts()` — the REAL backend work, drift #4) + `AdminAPIController.php` `getStats` to include the 5 counts in its response (additive); Modify `admin-dashboard.js` + `dashboard.php` (Vandaag tab port from `vandaag.html` + the queue cards from mockup `QUEUES`/`QUEUE_FILTER`); Test `tests/Integration/AdminWorklistQueueCountsTest.php`.
- **Tier:** **MIXED.** The `getWorklistQueueCounts` backend compute is **Tier A** (real logic). The Vandaag UI is **Tier B** (`no unit test: presentational — queue defs are server-side; F1/F3 acceptance covers the UI`).
- **Tier-A test contract (backend counts):** asserts `getWorklistQueueCounts($activeEditionIds)` returns the 5 counts; asserts **offerte-opvolging counts `confirmed` rows whose quote is absent OR `status ≠ Exported`** via the SAME two-step resolver as the grid offerte column (NOT a forked "paid" definition — Sibling-site audit item 1); asserts **counts are scoped to the active-edition subset** (a row on a terminal/past edition is excluded) and that a **dateless-edition interest row IS counted in "Oude interesse"** (the §10.7 carve-out — active subset must include dateless editions); asserts `waitlist_open` excludes a waitlist row whose edition has no open capacity (INV-7 effective status).
- [ ] Write the failing backend test; run (FAIL); implement `getWorklistQueueCounts` in `AdminStatsService` reusing the offerte resolver + active-edition scoping; add the 5 counts to the `/admin/stats` response (additive — existing keys unchanged); run (PASS); commit.
- [ ] Port the Vandaag tab: 5 queue cards (from `QUEUES`) with live counts from the extended `/admin/stats`; click → `?queue=<key>` opens the grid pre-filtered + bulk action armed (mockup `QUEUE_FILTER` → grid `init()`); render group-by collapsed aggregates. Commit.

#### Task 3.4: Dossier case view (person → registration, all stages)
**Files:** Modify `admin-dashboard.js` (case-view state, fetch `users/{id}/detail` via the S2-extracted endpoint) + `dashboard.php` (Dossier slide-over, port from `dossier.html`).
- **Tier B.** `no unit test: Tier B, presentational join-renderer over the tested /admin/users/{id}/detail endpoint (INV-6b enforced server-side) — F5 acceptance drives all-stages rendering.`
- [ ] Render person-headed registrations; lifecycle field labelled **"Inschrijvingsstatus"** with the pending two-substate hint (§2.4); `enrollment_data` stages as **collapsible panels — empty stages hidden, with-data closed-then-open-on-click, clean label→value (never raw JSON)**, header shows Dutch stage name + `submitted_at` + `submitted_by` (3-key shape); `intake`→"Intakevragenlijst" / `evaluation`→"Evaluatie (na afloop)"; **NO separate Vragenlijst block** (intake answers render once); offerte-status (never "betaald/onbetaald"); selections via the detail payload (INV-6b — server-side, already enforced); state-appropriate buttons from the `RegistrationTransitions` client mirror (terminal → muted "geen acties"); history timeline. **Trajectory section EXCLUDED — it is Task 3.5/cluster C2 (Phase 1E).** Commit.

#### Task 3.4a: Surface `session.selections_updated` in the admin timeline (drift #8 — TWO edits)
**Files:** Modify `web/app/mu-plugins/stride-core/Admin/AdminActivityMapper.php` (add to `KNOWN_ACTIONS` `:16` AND a `match` arm in `resolve()` `:71`); Test `tests/Unit/Admin/AdminActivityMapperTest.php`.
- **Tier A. RED-first.**
- **Test contract:** asserts an audit entry with action **`session.selections_updated`** maps (via `fromAuditEntry`) to a rendered timeline item with a **non-empty Dutch label** (e.g. "Sessies gekozen …") and the correct actor/timestamp — AND that `isKnownAction` returns `true` for it. The test must currently FAIL on BOTH counts (action absent from `KNOWN_ACTIONS` → dropped; no `match` arm → empty text). Asserting only `isKnownAction` is insufficient (drift #8 — the `resolve()` arm is the second half).
- [ ] Write the failing test (assert the event currently renders to nothing); run (FAIL); add `session.selections_updated` to `KNOWN_ACTIONS` AND a `resolve()` `match` arm returning `['enrollment'|'attendance', "Sessies gekozen voor {$edition}"]` (mirror the existing arms' Dutch voice); run (PASS); commit.

**Integration gate (cluster C1):**
- Existing suite green (S1/S2 behavior-preserving): `ddev exec vendor/bin/phpunit -c phpunit-integration.xml.dist --filter 'Stats|UserDetail|ActionQueue'` identical before/after.
- New backend: `--filter AdminWorklistQueueCounts` green; `--filter AdminActivityMapper` green (3.4a).
- **Feature-acceptance browser pass (situation B):** drive **F1–F6** through the REAL admin page via Playwright/Chrome (`playwright.config.ts`) — NOT jsdom — and emit the pass/fail/not-reachable manifest. No UI flow is "pass" without the browser driving it. Confirm: the Dossier hides ≥1 empty stage, opens a stage on click, shows NO duplicate Vragenlijst block, and the timeline renders a `session.selections_updated` + an attendance event (3.4a closed); the bulk bar tolerates the two stub actions (drift #6); select-all carries the filter not 4k rows (§5).
- Manual: as `stride_supervisor` (view-only), Vandaag shows counts but the bulk bar is absent/disabled; as `stride_coordinator`, a 3-row bulk-approve (1 invalid) → "2 geslaagd, 1 mislukt".

**── REVIEW GATE C1 ── (tier: STANDARD — Alpine grid + worklist + case view + the additive `/admin/stats` queue counts; multi-file UI behavior over already-gated endpoints, no new 1a surface; the strangle is behavior-preserving). HALT — run `/code-review` + `/security-review` on the C1 diff before starting Phase 1E.** STANDARD fan-out: `/code-review` (2 finders + simplicity) + `/security-review` + the feature-acceptance browser pass above; NO `security-sentinel` panel unless a finding lands on a 1a surface (then the cluster promotes to FULL — one-way escalation). Reviewer focus: the strangle preserved behavior (no new `vad_registrations` SQL outside the repo, INV-3 advisory); the 5-queue offerte count uses the single paid-proxy definition (Sibling-site audit 1); the bulk bar derives from `RegistrationTransitions` not a hard-coded set (Sibling-site audit 2); structured-only grid params (Sibling-site audit 3).

---

## Sibling-site audit (constraints this cluster must route through)

These are convergence targets from the spec — surfaced as plan constraints so the reviewer keys findings to a named item:
1. **Paid-proxy definition** — "confirmed AND quote ≠ Exported" lives in the grid offerte column (1A), the grid group-by offerte-verdeling (1A), `AnnualReportService::quoteAggregates()`, AND now the Vandaag "Offerte-opvolging" queue count (Task 3.3). The 3.3 count MUST reuse the existing two-step resolver, not fork a second definition.
2. **Transition map single source** — the bulk bar (3.2) and the Dossier action buttons (3.4) MUST derive from `RegistrationTransitions` (Task 1.1, exists) via its client mirror (`actionsForStates`). A hard-coded button set anywhere is a finding.
3. **Structured-only grid params** — any grid filter/sort/group param (3.1) is checked against the M4/M5 whitelist. A "filter by dietary need" / any `enrollment_data` JSON param belongs in the Phase-2 cohort roster, never the global grid.

---

## Out of scope for Phase 1D (do NOT pull in)
- **Tasks 3.5 / 3.6** (`/admin/users/{id}/trajectories` + Dossier trajectory section + Trajecten tab) — cluster C2 / Phase 1E. The Dossier in 3.4 renders WITHOUT the trajectory section.
- **Tasks 4.1 / 4.2** (select-all capped server expansion + worklist-export removal) — cluster D / Phase 1F. (3.2 ships the client-side select-all-as-filter + confirm; the capped server expansion is 4.1.)
- Cohort lens (Phase 2), field-scoped export (Phase 3).
