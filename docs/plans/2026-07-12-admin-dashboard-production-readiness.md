# Admin Dashboard — Production-Readiness Review & Remediation Plan

**Date:** 2026-07-12
**Scope:** the entire admin workspace (`?page=stride-dashboard`) — Vandaag, Inschrijvingen, Dossier, Trajecten, Edities, Offertes, Gebruikers, cohort lens, shell, the REST surface behind them, and exports.
**Method:** five parallel end-to-end code traces (REST endpoint → JS factory → template), each finding verified against source with file:line evidence; the highest-severity singleton findings re-verified independently. **No code was changed.**
**Verdict:** the architecture is sound and the backend is largely correct, batched, and security-consistent — but the frontend wiring is roughly 70% finished while *looking* 100% finished. The dominant failure mode is not broken code; it is **live-looking UI backed by nothing** (buttons that no-op, filters that are never sent, counts whose click-through shows different rows, scopes applied invisibly). That is exactly the "bugs on every page" experience: the admin cannot tell the difference between "not wired yet" and "broken", so everything reads as broken.

---

## 1. The six root causes (fix these classes, not 70 individual bugs)

Almost every finding below is an instance of one of six systemic causes. The remediation plan is organized around them.

### RC-1 — Invisible, inconsistent scoping ("things don't show")
The workspace applies a default **active-edition scope** (`start_date >= today − 2 days OR NULL`) to the grid, all six Vandaag queue counts, and the Edities agenda — **silently**. The spec (§10.4) requires a visible, dismissable "Actieve edities" pill with a widen-to-all affordance; none was built, `edition_scope` is never sent by any JS, and there is no UI escape hatch.

Worse, "active" is defined by **start** date, not end date or effective status. A 6-week edition falls out of every queue and the default grid **two days after its first session** — precisely when the post-course work the queues describe (approvals, quote follow-up, certificates) actually happens. The "Afgerond zonder certificaat" queue is structurally ~0 for dated editions. This is very likely the single biggest source of "tasks that don't show".

Siblings of the same cause:
- Edities is **agenda-view only** (`edities.js:155` hardcodes `view=agenda`; the agenda INNER JOINs on session date) → **dateless editions are invisible everywhere in the workspace**, and past editions disappear with no hint.
- Trajecten has **three different "active" definitions** (list scope excludes a `closed` status that doesn't exist in `OfferingStatus`; the typeahead uses `TrajectoryRepository::ACTIVE_STATUSES`; cancelled/completed/postponed trajectories count as "active" in the list).
- Two conflicting **capacity** definitions (capacity melding counts confirmed only; `hasAvailableSpots` counts pending+confirmed+completed).

### RC-2 — Count-vs-view drift (queue cards lie on click-through)
5 of 6 Vandaag queues compute their count with predicates (capacity free, offerte ≠ Verwerkt, no certificate, age > 90d, edition dated) that the click-through **drops** — `grid.js` maps every queue to a bare status filter (`QUEUE_STATUS`, grid.js:92-103). "Afgerond zonder certificaat: 3" opens a grid of *all* completed registrations. The grid even renders queue-specific empty states asserting semantics the filter does not implement. Related: "Wacht op mij" (approvals panel, unscoped, task-aware) and "Wacht op goedkeuring" (queue card, edition-scoped, task-blind) show two different numbers for "waiting on approval" one panel apart.

### RC-3 — Half-wired frontend (live-looking dead UI)
- **Dossier action buttons: 6 of 7 are silent no-ops** (`dossier.js:464-467` maps only `stride_promote_waitlist`; Goedkeuren/Annuleren/quote actions/message/document render as enabled buttons and do nothing). The flagship flow — Vandaag → Wacht op mij → dossier → Goedkeuren — does nothing.
- **Dossier loads exactly once per session** (`dossier.js:339-341` uses the one-shot `lazyLoad` latch with no `ws-view-changed` re-activation handler, unlike grid.js:353-358). Opening user A then user B shows **user A's data under user B's URL**. Every row→dossier path on every surface is wrong from the second use on.
- **Cohort-lens bulk bar cannot ever succeed**: the roster contains only confirmed/completed rows (`COHORT_STATUSES`, product decision CR-1), so the one lifecycle action (`approve`, offered for pending/interest/waitlist) can never appear, and the two actions that *can* appear (message, generate_doc) are server-side deferred stubs that fail every row. Same for the grid: "Bericht sturen" and "Document genereren" are offered as live buttons and fail 100% of rows.
- **Topbar global search is a `disabled` input with a dead ⌘K hint** — the most prominent affordance on every page.
- **"Toon inschrijvingen" on a trajectory passes no parameter** (`trajecten.php:201-204`), `switchView`'s param whitelist couldn't carry it anyway (shell.js:189-207), and the grid has no trajectory filter UI/chip — even though the server-side parent→child join is fully implemented and correct.
- **A whole shelf of shipped backend has zero UI callers**: `/users/{id}/reveal` (masked-PII reveal), POST `/users/{id}/profile`, `/users/{id}/impersonate`, `/admin/editions/{id}/export/{type}` (all 5 exporters), `/admin/export/registrations`, `POST /admin/action-queue/dismiss` (melding dismissal), the six `stride_traj_roster_bulk_*` handlers + nonces.

### RC-4 — Enum/label/contract drift (raw slugs, dead predicates, dead maps)
- The "Openstaande taken" melding **can never fire**: `AdminStatsService.php:751-760` LIKEs for `"completed":false`, a JSON key no writer produces (tasks are `{status: 'pending'|'completed'}`). Confirmed dead.
- Grid column sort: UI sends `sort=name|edition|status`; the repository allowlist has neither `name` nor `edition` → clicking Naam/Editie silently re-sorts by `registered_at` (looks random). Only Status works.
- Timeline: `AdminActivityMapper::KNOWN_ACTIONS` misses ~20 recorded event types (registration.confirmed/waitlisted/interest, enrollment.task_completed, quote.email_sent, trajectory.*, …) → the dossier renders raw English slugs; the mapper also maps `quote.created`/`quote.sent` which are **never recorded** (AuditBridge doesn't listen to them) → health-check "last mail send" is permanently stale. Per-registration timeline attribution parses the edition id out of `target_url` → quote/completion events never match and drop out.
- Dutch UI leaking English: trajectory roster shows "Confirmed"/"Waitlist" (label map keyed on a nonexistent `active` status), bulk failure modals surface English `WP_Error` strings (`task_not_required`, "Registration is not pending approval"), export sheet prints raw `exported`, enrollment_path/initial_selection slugs, ISO dates unformatted.
- `person.profile_type` is an object rendered via `x-text` → literal **"[object Object]"** chip.
- Dossier "Voltooiingstaken" is an **invented client-side checklist** (incl. a fictional "Aanwezigheid ≥ 80%" rule); the real `completion_tasks` (7 types, per-task status, Dutch labels — all server-side) are never surfaced, and the payload doesn't include them.
- Intake answers are stored in **two places** depending on flow (`completion_tasks.questionnaire.data.answers` for the completion-task flow vs `enrollment_data.intake` for the shortcode flow); the dossier reads only the latter → intake answers invisible for the primary flow. The spec's "same dataset — verified" premise is false in code.
- Stale meta key `session_date` (real key: `_ntdst_date`) in `EditionRegistrationExporter::summarizeInitialSelection` and `RegistrationModalController` → session dates always blank there.

### RC-5 — State/URL/navigation model gaps
- `history.replaceState` everywhere, nothing pushes → browser Back exits the workspace entirely; the popstate handler is dead code.
- `switchView` whitelists only `queue|user|reg` params → no surface can deep-link a trajectory/edition filter; `?open=` lingers across views.
- Active filters without chips: `trajectory_id` and `edition_scope` filter invisibly ("Filters wissen" appears with nothing visible to clear); company chip shows a raw `#id`.
- No request sequencing/abort in `api()` → out-of-order responses render stale results on fast typing (grid + all list surfaces).
- Selection persists across pages but `selectedStates` reads only the current page's rows → the bulk bar can offer an action derived from visible rows and apply it to off-screen rows in other states; quote actions are status-blind server-side, so this can actually mutate wrong rows.
- **Grouped view: bulk selection permanently degrades to "Gemengde statussen"** (`selectedRows` filters `this.rows`, which is `[]` in grouped mode) and the pager is hidden while groups ARE server-paginated (groups 26+ unreachable; count copy wrong twice).
- Surfaces cache their first load forever (lazy-latch, no re-fetch on return) → cross-surface staleness after any mutation; cohort-lens mutations refresh only the lens.

### RC-6 — Security/hardening gaps (small list, real items)
- **XLSX formula injection**: `EditionRegistrationExporter` writes all cells via `Cell::fromValue()` — OpenSpout promotes `=`-prefixed strings to formulas; participant-controlled fields (name, organisation, enrollment answers) flow in unsanitized. The CSV path has `sanitizeCsvCell()`; the XLSX path (the PII-rich one) has nothing.
- **CR-4 publish-guard gap**: `/admin/editions/{id}` and `/admin/editions/{id}/registrations` check only `post_type` — a trashed/draft edition's full participant list is readable; the sibling roster/export routes were fixed, these two were missed.
- **Unpinned CDN scripts without SRI** in wp-admin (flatpickr floats latest; Alpine pinned but no `integrity` despite the comment claiming SRI).
- Impersonation: 1h return-token vs ~2-day granted session strands the admin logged-in as the target; raw token in the admin-bar URL; legacy-int token fallback skips the caller==target check.
- Export temp ZIPs rely on `.htaccess` only (inert on nginx) with post-stream cleanup that a client abort can skip.
- `files`/`bundle` exporters stream then *return* (no `exit`) → REST layer appends error JSON after ZIP bytes + header warnings.

---

## 2. What already works (do not rebuild)

To keep the plan honest: the review confirmed a lot of solid machinery. The bulk pipeline (per-action nonces, per-batch capability check, per-row outcome report, partial-failure modal with failed-rows-stay-selected retry, select-all-across-pages carrying the *filter* with server-side expansion and a `too_many` cap, JS↔PHP transitions drift validation at init), the grid read-model (single `buildGridFilters` WHERE shared by flat/grouped/ids paths, enum-validated params, prepared statements, JSON never in WHERE), the group-by accordion (the roadmap's "lost accordion rows" regression is **already fixed in this tree** — the 2026-07-01 plan shipped: `GROUP_ROW_CAP`, shared `composeFromRows`, `_reg-row.php`, expand/collapse, "Toon alle N"), `getUserDetail` batching (no N+1), the capability model (no `__return_true`, reads=stride_view, writes=stride_manage, PII assembly gated not just masked), promote-waitlist's per-row capacity re-check in a FOR-UPDATE transaction, anonymisation handling in rosters/exports, and the `pending_reason` sub-state hint. The workspace shell/visual system itself is good — the complaints trace to wiring, not layout.

**Roadmap corrections:** regression "group-by accordion lost" → fixed, strike it. Regression "anonymous-lead name search" → still open (see F-G3).

---

## 3. Findings register

Severity: **H** = admin cannot do their job / data wrong or leaking; **M** = feature degraded or misleading; **L** = polish/latent. Confidence is confirmed-by-trace unless marked *(suspected)*.

### Vandaag (worklist home)

| # | Sev | Finding | Where |
|---|---|---|---|
| F-V1 | H | "Openstaande taken" melding can never fire — LIKE `"completed":false` matches a key no writer produces; its deep-link (`#action-required-*`) is also dead (shell routes only `?view=`) | AdminStatsService.php:751-760; ActionQueueService.php:202 |
| F-V2 | H | 5 of 6 queue cards: count predicates dropped on click-through (bare status filter); queue-specific empty states assert unimplemented semantics | grid.js:92-103 vs AdminStatsService.php:442-611 |
| F-V3 | H | Active-edition scope is start_date-based → editions leave all queues + default grid 2 days after first session; post-course queues structurally empty for dated editions | EditionRepository.php:64-82; RegistrationRepository.php:2578-2587 |
| F-V4 | M | Two different "waiting for approval" numbers side by side (scoped task-aware pill vs unscoped task-blind queue card) | vandaag.js:158-198; AdminStatsService.php:459-460 |
| F-V5 | M | Pending rows with `completion_tasks = NULL` invisible to the approvals panel forever *(suspected — depends on write paths)* | RegistrationRepository.php:1609-1627 |
| F-V6 | M | Conflicting capacity definitions (melding: confirmed-only vs `getRegisteredCount`: pending+confirmed+completed) | AdminStatsService.php:676-684; EditionService.php:113-115 |
| F-V7 | M | Melding dismissal endpoint shipped, zero JS consumers — aggregate alerts reappear forever | AdminAPIController.php:408,2027 |
| F-V8 | M | `interest_to_invite` queue can never be cleared (no outreach marker; invite action deferred); a row can count in two interest queues → header total double-counts | AdminStatsService.php:588-599; vandaag.js:233-235 |
| F-V9 | L | Timezone mixing (`current_time` writes vs `gmdate`/`time()` math) → ±2h, day-boundary off-by-ones in staleness/age/deadline badges | AdminAPIController.php:1750,1795; AdminStatsService.php:534,589 |
| F-V10 | L | "Vernieuwen" re-serves 2/5-min transients but toasts "Tellingen vernieuwd"; settings changes & LD cert issuance never bust the caches | vandaag.js:311-318; AdminStatsService.php:85-88 |
| F-V11 | L | Approvals pills read the ≤100-item page, ignore server `clipped` flag (CR-E2 required surfacing it) | vandaag.js:256; vandaag.php:96-100 |
| F-V12 | L | Dead stats payload (todaySessionDetails, upcomingEditionDetails, alerts, recentRegistrations, openTrajectories computed each miss, never consumed); "Sessies vandaag" card is a dead end | AdminStatsService.php:156-373 |
| F-V13 | L | Queue click at count 0 is a silent no-op; "bulk action armed" from the spec never implemented; melding links open raw WP edit screens (incl. `post.php?post=0` on missing meta); "Goeiemorgen." hardcoded all day; error banner hides loaded meldingen | vandaag.js:294; ActionQueueService.php:100-178; vandaag.php:42,109-121 |

### Inschrijvingen (grid)

| # | Sev | Finding | Where |
|---|---|---|---|
| F-G1 | H | Column sort no-ops for Naam/Editie (server allowlist mismatch, silent fallback to registered_at; `name` not sortable server-side at all) | inschrijvingen.php:164-175; RegistrationRepository.php:1999-2004 |
| F-G2 | H | `edition_scope` never sent, no "Actieve edities" pill, no widen-to-all — historical/completed registrations unreachable and unexplained (spec §10.4) | grid.js:404-425,513-524; AdminAPIController.php:1595 |
| F-G3 | H | Anonymous-lead search still broken (LIKE on wp_users only; lead name/email live in enrollment_data); placeholder + header comment falsely promise organisation search | RegistrationRepository.php:2640-2647; inschrijvingen.php:97,101 |
| F-G4 | H | Bulk actions impossible in grouped view — `selectedRows` reads `rows` which is `[]` when grouped → permanent "Gemengde statussen" | grid.js:434-439,596,623-628 |
| F-G5 | H | Grouped view: groups ARE server-paginated but pager hidden + "paginering uit" claimed → groups beyond page 1 unreachable; count copy wrong twice ("Toont 1–25 van 3", "3 inschrijvingen in 3 groepen") | RegistrationRepository.php:2108-2137; inschrijvingen.php:116-119,311,325-327 |
| F-G6 | M | Stub actions (Bericht sturen / Document genereren) offered as live buttons → guaranteed "0 van N geslaagd" failure modal | grid.js:255-257; BulkRegistrationHandler.php:291-322 |
| F-G7 | M | Cross-page selection: action availability derived from current page only; quote actions are status-blind server-side → can mutate off-screen rows in other states | grid.js:488-491,596,623-628; BulkRegistrationHandler.php:172-204 |
| F-G8 | M | No request sequencing/abort → stale responses overwrite fresh filter results | grid.js:404-454 |
| F-G9 | M | Trajectory filter fully implemented server-side but: no UI control, no chip, not in `activeChips` → deep-linked filter acts invisibly | inschrijvingen.php; grid.js:513-524 |
| F-G10 | M | Edition filter = flat select of first 100 editions ordered start_date ASC (oldest first — current editions unpickable at scale); spec requires typeahead; unlisted editions render "Editie #id" in group headers | grid.js:479,557-560; AdminAPIController.php:800; EditionRepository.php:440 |
| F-G11 | M | Bulk approve surfaces English internals (`task_not_required`, "Registration is not pending approval") in the failure modal | BulkRegistrationHandler.php:124-129; EnrollmentCompletion.php:444-446 |
| F-G12 | M | `interest → pending` transition (spec §2.1) has no implementing action — interest rows can only be cancelled/messaged | grid.js SMART_ACTIONS; BulkRegistrationHandler.php:117 |
| F-G13 | M | `offerte_status` filter (spec §4.1) not implemented; `group_by=trajectory_id` missing from allowlist | AdminAPIController.php:1585-1597; RegistrationRepository.php:27 |
| F-G14 | L | Header checkbox `:indeterminate` binds an attribute, not the DOM property *(suspected, Alpine-version-dependent)*; row click dead no-op for anonymous leads (user.id=0); stale `queue` context skews empty states; group avg-attendance permanently "—" (documented deferral); attendance % numerator/denominator publish-scope mismatch (clamped); full-table "Laden…" flash on every filter click; company column renders raw `#id`; `distColor()` dead code | inschrijvingen.php:162; grid.js:530-534,719-743; AdminRegistrationQueryService.php:449,549-554; _reg-row.php:46-48 |

### Dossier (case view)

| # | Sev | Finding | Where |
|---|---|---|---|
| F-D1 | H | Never reloads after first activation (one-shot lazyLoad latch, no re-activation handler) → shows previous person's data under the new URL; also leaks a listener per `ws-refresh` | dossier.js:339-341; shell.js:90-110; dossier.php:37 |
| F-D2 | H | 6 of 7 action buttons silent no-ops (only promote-waitlist wired); no capability gating (view-only role sees buttons); hardcoded local transitions table without drift validation; no interest→pending action | dossier.js:464-467; dossier.php:364-390 |
| F-D3 | H | Intake answers invisible for the completion-task flow (stored in `completion_tasks.questionnaire.data.answers`, dossier reads only `enrollment_data.intake`) | AdminUserService.php:210; EnrollmentCompletion.php:491 |
| F-D4 | H | Real `completion_tasks` never surfaced; replaced by invented 4-item client checklist incl. fictional "Aanwezigheid ≥ 80%" rule | dossier.js:233-247 |
| F-D5 | H | `person.profile_type` renders "[object Object]" | AdminUserService.php:72-76; dossier.php:90 |
| F-D6 | H | Trajectory-parent / edition-less rows titled "Onbekend" (trajectory_id never selected/joined) — multiple indistinguishable rows + "Tijdlijn: Onbekend" options | AdminUserService.php:145-156,234 |
| F-D7 | H | Timeline: per-registration attribution parses edition id from `target_url` → quote/completion events dropped or misattached; no KNOWN_ACTIONS filter → ~20 unmapped recorded events render as raw slugs; URL-less events show on every registration | dossier.js:206-216; AdminActivityMapper.php:199-200 |
| F-D8 | H | Registrations silently capped at 20 (server pagination exists; JS never pages; count chip shows page size) | AdminUserService.php:52,144-156; dossier.php:217 |
| F-D9 | M | `quote.created`/`quote.sent` never recorded (AuditBridge doesn't listen) yet mapped + queried → notifications query never matches; health-check "last mail send" permanently stale; the events that ARE recorded (`quote.email_sent` etc.) are unmapped | AuditBridge.php:52-55; QuoteService.php:398,478; AdminActivityService.php:155 |
| F-D10 | M | Per-key `usermeta.updated` audit rows flood the LIMIT-50 timeline (~10 identical lines per enrollment) | WPAuditBridge.php:70,198; AdminUserService.php:448-458 |
| F-D11 | M | `initial_selection` stage renders garbage (phases flattened to empty; raw `session` slug; empty header) — the correct renderer exists in RegistrationModalController | AdminUserService.php:534-539 |
| F-D12 | M | `submitted_by` renders as numeric user id, `submitted_at`/`registered_at`/`completed_at` as raw ISO/MySQL strings | dossier.php:236,255-256,279-282 |
| F-D13 | M | Attendance hours fabricated (present × 4) — `SessionService::getHoursAttended` exists and is used elsewhere; no per-session attendance rows (spec requires "per session") | AdminUserService.php:733-737 |
| F-D14 | M | View-only timeline gate unreachable (`[]` is an array → `canSeeTimeline` always true → shows "Nog geen gebeurtenissen" instead of the afgeschermd state) | AdminUserService.php:499; dossier.js:382 |
| F-D15 | L | Fetched-but-never-rendered payload: quote amounts (spec requires them), billing block (all but city), masked-PII fields + `_present` flags + reveal affordance, anonymisation state/action, top-level attendance summary | dossier.php vs AdminUserService payload |
| F-D16 | L | Raw slugs/English labels (enrollment_path, auto-humanized snake_case, trajectory course sub-label shows raw edition id via nonexistent `c.cohort`); attendance timeline actor "Onbekend" (user_name never recorded); breadcrumb always claims grid origin; notes read-only with no add affordance; dev-speak leaking into UI copy | dossier.php:171,254; AdminActivityMapper.php:239; dossier.js:429-435,513 |

### Shell / navigation

| # | Sev | Finding | Where |
|---|---|---|---|
| F-S1 | H | Topbar global search: `disabled` input + dead ⌘K on every page | _ws-topbar.php:46-47 |
| F-S2 | M | `replaceState` only, nothing pushes → browser Back exits the workspace; popstate handler dead | shell.js:162,208,221 |
| F-S3 | M | `switchView` param whitelist (`queue|user|reg`) blocks trajectory/edition deep-links; never clears `open` | shell.js:189-207 |
| F-S4 | L | No abort/sequencing in shared `api()` (stale-response races on all search boxes) | shell.js:231-246 |
| F-S5 | L | Nonce printed once per page load — a tab open >12-24h fails all calls with 403 until reload, no re-auth handling | shell.js:231-246 |

### Edities / Offertes / Gebruikers / Trajecten

| # | Sev | Finding | Where |
|---|---|---|---|
| F-E1 | H | Dateless editions invisible (UI is agenda-view-only; agenda INNER JOINs session dates; the NULL-permitting list view has no UI caller) | edities.js:155; AdminAPIController.php:920-928 |
| F-E2 | M | Past editions hidden by default with zero UI hint (2-day lookback); no status filter despite server support; raw ISO dates in cells | edities.php:34-73,121; AdminAPIController.php:925-928 |
| F-E3 | L | No export action anywhere in the workspace despite live endpoints + armed exportNonce; tag options fetched eagerly for never-opened surfaces; agenda repeats multi-session editions with identical counts per row | edities.php; edities.js:99; offertes.js:97 |
| F-O1 | M | Offertes: zero quote actions (no mark sent/exported, no PDF, no lock indicator, no dossier link) — row click goes to classic WP edit screen | offertes.js:212; offertes.php:90-97 |
| F-O2 | M | Date filter filters invisible `post_date` (no date column rendered); search is user-only while placeholder promises quote-number search; tag filter silently drops edition-less quotes | AdminQuoteService.php:65-135; offertes.php:37 |
| F-U1 | M | Gebruikers: hard 10-result cap presented as complete ("10 resultaten"), no pagination; 1-char search flashes an English 400 error; anonymised users un-flagged | AdminAPIController.php:441,2093,2112-2121; gebruikers.js:57-79 |
| F-T1 | H | "Toon inschrijvingen" passes no trajectory param (whole chain broken: button → switchView whitelist → missing grid UI/chip) while the server join is done and correct | trajecten.php:201-204; shell.js:189-207 |
| F-T2 | H | Trajectory status vocabulary stale in three ways (scope excludes nonexistent `closed`; label map misses real statuses → `In_progress`/`Cancelled` via ucfirst; typeahead uses a third active-definition and drops meta-less trajectories) | AdminTrajectoryService.php:90-95,292-299 |
| F-T3 | M | Roster labels English for the most common statuses ("Confirmed", "Waitlist" — map keyed on nonexistent `active`); badge hues wrong | AdminTrajectoryService.php:564-575; trajecten.js:71-85 |
| F-T4 | M | List capped at 50 with no pager; detail roster silently capped at 50; `enrolledCount` counts cancelled parents; price mangled by `(int)` cast on a euro float (cents truncated; note editions use cents — unit clash); label drift "Gesloten" vs "Afgesloten"; roster silently drops deleted-WP-account rows; no Escape-close | trajecten.js:176; AdminTrajectoryService.php:234,295,545; RegistrationRepository.php:977-979 |

### Cohort lens

| # | Sev | Finding | Where |
|---|---|---|---|
| F-C1 | H | Entire bulk bar is a dead end: roster = confirmed/completed only, so `approve` can never appear; the reachable actions (message/generate_doc) are stubs failing every row. Plan CF4's acceptance flow and the CR-1 roster decision were never reconciled | AdminEditionRosterService.php:44-55; cohort.js:75; RosterBulkHandler.php:368-409 |
| F-C2 | M | Attendance marking: no optimistic update — each mark triggers a full roster refetch/flash (20 marks = 20 reloads, scroll lost); row shape carries only edition-level aggregates → cannot see who is marked for the selected session; mark buttons never reflect current state | cohort.js:271-293; AdminEditionRosterService.php:179-199 |
| F-C3 | M | After lens mutations nothing refreshes underneath (grid/vandaag/edities keep pre-mutation data; server transients bust, client never refetches); lens ignores `ws-refresh` | cohort.js:288,386; _cohort-lens.php:28-29 |
| F-C4 | L | Extras shipped as filter chips only — no per-row extras/logistics columns (fetched `extras_keys` unused for columns); lens only openable from Edities (grid/vandaag can't dispatch `ws-cohort-open`); traj-roster bulk backend fully built with zero frontend; detail-fetch `.catch(() => ({}))` silently disables attendance marking on transient errors | _cohort-lens.php:113-124; cohort.js:167; edities.js:245 |

### API / exports / security

| # | Sev | Finding | Where |
|---|---|---|---|
| F-A1 | H | XLSX formula injection — all cells via `Cell::fromValue()`, participant-controlled strings unsanitized (CSV path is guarded, XLSX is not) | EditionRegistrationExporter.php:294-430+ |
| F-A2 | M | CR-4 publish-guard missing on `/editions/{id}` + `/editions/{id}/registrations` → trashed/draft edition participant PII readable by stride_view | AdminAPIController.php:1183,1269 |
| F-A3 | M | Unpinned flatpickr (floats latest) + no SRI on any CDN script in an admin origin that can approve/export PII | AdminDashboardService.php:205-240 |
| F-A4 | M | Impersonation: 1h token vs ~2-day session strands admin as target; raw token in admin-bar URL; legacy-int token fallback skips caller==target check | ImpersonationHandler; AdminDashboardService.php:73; AdminAPIController.php:2498 |
| F-A5 | M | `files`/`bundle` exporters stream then return (no exit) → REST appends error JSON after ZIP bytes *(corruption suspected, header warnings confirmed)*; temp ZIPs `.htaccess`-only (inert on nginx) with abort-skippable cleanup | EditionFilesZipExporter.php:36-54; EditionBundleZipExporter.php:26-34; AdminAPIController.php:1450-1455 |
| F-A6 | M | approve-registration non-atomic: completed approval task + failed confirm → row vanishes from "Wacht op mij" while still pending, no queue surfaces it *(suspected)* | AdminAPIController.php:1928-1943 |
| F-A7 | M | Export quote-status label map has statuses that don't exist (accepted/rejected/paid) and misses `exported` → raw slug in the Facturatie sheet; stale `session_date` meta key → session dates always blank in "Originele keuze" + registration modal | EditionRegistrationExporter.php:828-839,1026; RegistrationModalController.php:213 |
| F-A8 | L | Notifications endpoints query the audit table with no existence guard and bypass `AuditTable::getTableName()`; audit `fields` context drops the `core` marker (`+` array union on numeric keys); dateless editions flagged `isPast` in the (unused) list view; `/admin/quotes` search-no-match returns a divergent envelope | AdminActivityService.php:197,245; AdminAPIController.php:732,2257 |
| F-A9 | M | **Export gap (the "export what's needed" requirement):** zero export affordances in the whole workspace; no filtered-grid export exists at all (global CSV is hardcoded to confirmed+upcoming); no quotes/Exact export, no users export, no cross-edition attendance export | all workspace templates; AdminAPIController.php export routes |

**Totals: 18 High / 30 Medium / ~22 Low.**

---

## 4. Remediation plan

Phased so every phase ends with the dashboard strictly more trustworthy — trust first, completeness second, polish third. Each fix cites the finding IDs it closes. Work through `harnessed-development` per house rules; every phase gets its own branch + review gate.

### Phase 0 — Product decisions needed from Stefan (blocks Phase 1 design, ~1 conversation)

1. **What does "active edition" mean for the admin?** Recommendation: effective-status/end-date based ("not archived/afgesloten"), not start_date−2d — post-course work must stay in scope (F-V3). Decide the default + the pill semantics ("Actieve edities" ⨯ → all).
2. **Queue click-through fidelity.** Recommendation: a server-side `queue=<key>` param on `/admin/registrations` that applies the *same* predicate as the count (single shared definition per queue — one source of truth, count and grid can never drift again) (F-V2). Alternative (more filter UI: offerte-status filter, cert filter, age filter) is more work and still drift-prone.
3. **Interest queues lifecycle.** How does "Oude interesse"/"Editie nu gepland" ever shrink — dismissal marker, "uitgenodigd" flag, or wait for the mail-broadcast feature? (F-V8, F-V7)
4. **Cohort lens bulk bar.** Reconcile CR-1 (roster = confirmed/completed) with CF4 (approve flow): either widen the roster with a status filter, or remove the bulk bar until message/doc actions ship (F-C1). Recommendation: remove/disable now, revisit with the mail-broadcast build.
5. **Stub actions posture.** Hide "Bericht sturen"/"Document genereren" everywhere until implemented, or render them disabled with "volgt binnenkort"? (F-G6) Recommendation: disabled + tooltip, so the roadmap stays visible without reading as broken.
6. **Approval semantics.** Should the "Wacht op goedkeuring" queue distinguish task-blocked vs admin-ready pendings (the two sub-states) or merge with the "Wacht op mij" definition? (F-V4)

### Phase 1 — Restore trust (the "no mistakes" bar; biggest bugs first) — est. 4–6 days

**1a. Kill the wrong-data bugs (highest priority, small diffs):**
- Dossier re-activation reload: add the `ws-view-changed` handler mirroring grid.js:353, keyed on `?user/reg` change; fix the `ws-refresh → init()` listener leak (F-D1).
- Wire the 6 dead dossier actions to the existing bulk handlers (single-id batches — the nonces are already armed), add capability gating + drift validation like grid.js, add interest→pending (F-D2, F-G12).
- Grid sort: either add `u.display_name`/edition-title to the server sort allowlist (the JOINs exist) or remove the sort affordance from Naam/Editie headers — no silent fallback (F-G1).
- Grouped view: fix `selectedRows` to read group rows; show the pager (or a "meer groepen" affordance); fix the two count strings (F-G4, F-G5).
- `incomplete_tasks` predicate → `JSON_VALUE(...status) <> 'completed'` pattern already used by the repo, and route its link through `?view=` (F-V1).
- profile_type "[object Object]", "Onbekend" trajectory rows (select/join trajectory_id), registrations pagination in dossier (F-D5, F-D6, F-D8).

**1b. Scoping made visible + correct (per Phase-0 decision 1):**
- One shared active-scope definition (effective-status based) used by queue counts, grid default, and Edities; "Actieve edities" pill + widen-to-all in grid and Edities; `edition_scope` in URL state (F-V3, F-G2, F-E2).
- Edities: request the list view (or a NULL-permitting agenda) so dateless + past editions are findable; add the status filter; format dates (F-E1, F-E2).
- Trajecten: one status vocabulary from `OfferingStatus`/`RegistrationStatus::label()` — fixes scope, labels, typeahead divergence (F-T2, F-T3).

**1c. Queue → grid fidelity (per Phase-0 decision 2):**
- Server-side `queue` param applying the count's predicate; queue definitions extracted to one class consumed by both AdminStatsService and the grid read-model (F-V2). Unify the capacity definition while there (F-V6).

**1d. Security quick wins (small, do in the same phase):**
- XLSX cell sanitization (mirror `sanitizeCsvCell`) (F-A1).
- Publish-guard on the two unguarded edition routes (F-A2).
- Pin flatpickr + add SRI hashes, or self-host both libs like the fonts (F-A3).
- `exit` after ZIP streaming (F-A5).

**Exit criterion:** an admin can (1) see every queue count and click through to *exactly* those rows, (2) open any person twice in a row and see the right person, (3) act on a registration from the dossier, (4) find any edition/registration regardless of date — all with visible scope indicators.

### Phase 2 — Completeness & correctness of what's displayed — est. 4–6 days

- **Dossier payload/rendering:** real `completion_tasks` list with Dutch labels + per-task status (server already has everything; delete the invented checklist) (F-D4); intake answers from both storage locations — and decide/execute the storage unification (the modal's renderer is the reference) (F-D3); quote amounts + billing block + reveal affordance + anonymisation state (F-D15); real hours via `getHoursAttended` + per-session attendance rows (F-D13); `initial_selection` via the modal's builder (F-D11); name/date resolution for `submitted_by`/dates (F-D12); view-only gating fix (F-D14).
- **Timeline:** stamp `registration_id`/`edition_id` into audit context at record time (stop parsing target_url); complete KNOWN_ACTIONS for all recorded events (and remove dead map entries); record `quote.created/sent` or re-key consumers to `quote.email_sent`; collapse per-meta-key floods (group by actor+minute); record `user_name` for attendance actors (F-D7, F-D9, F-D10).
- **Search:** denormalized `lead_name`/`lead_email` columns on `wp_vad_registrations` (M5-compliant) populated at write time + backfill, included in grid search; organisation search (billing_company usermeta join or drop it from the placeholder) (F-G3). Gebruikers: pagination or raise cap + truncation notice, min-length handled client-side in Dutch, anonymised flag (F-U1).
- **Filters:** trajectory filter UI + chip in the grid; wire "Toon inschrijvingen" (whitelist `trajectory_id` in switchView) (F-T1, F-G9); edition typeahead replacing the 100-cap select (F-G10); `offerte_status` filter (F-G13); chips for every active filter incl. scope, company by *name* (F-G14 partial).
- **Offertes:** date column + define what the date filter targets; quote actions (mark sent/exported reusing the bulk handlers single-id, PDF link, lock indicator, dossier link); honest search placeholder or quote-number search (F-O1, F-O2).
- **Bulk correctness:** selection tracks status at select-time (store status alongside id) so cross-page selections derive availability from the whole selection; make quote bulk actions status-checked server-side (F-G7); Dutch translations for domain errors surfaced in the modal (F-G11).
- **Cohort lens:** per-session attendance state on rows + optimistic marking with rollback (F-C2); dispatch a `ws-data-changed` event consumed by grid/vandaag/edities for cross-surface refresh (F-C3); extras as columns (F-C4).
- **Vandaag:** melding dismissal UI on the existing endpoint (F-V7); `clipped` surfaced (F-V11); interest-queue lifecycle per Phase-0 decision 3 (F-V8); timezone normalization pass — one helper, site-TZ everywhere (F-V9); approve-registration made atomic or self-healing (F-A6); trim the dead stats payload or wire the stat-card click-throughs (F-V12/13).
- Label/format sweep: every enum through its `label()`, every date through `stride_format_date`, no raw slugs — grep-able invariant (F-T3, F-A7, F-D16, F-E2).

### Phase 3 — Workflow & UX polish — est. 3–4 days

- **Exports (the owner's explicit ask):** "Exporteer huidige weergave" on the grid (server-side CSV/XLSX honoring the exact `buildGridFilters` params + queue; reuse the sanitization); export buttons on Edities rows + cohort lens for the 5 existing exporters; quotes export for the Exact handoff. Fix export label/meta-key bugs first (F-A7, F-A9, F-E3).
- **Navigation:** pushState on view switches + working popstate; origin-aware back (breadcrumb remembers Gebruikers/Vandaag); Escape-close parity (F-S2, F-D16, F-T4).
- **Global search:** implement ⌘K across persons/editions/organisations (the endpoints exist) — or remove the input; never ship it disabled (F-S1).
- **Freshness:** request-sequencing token in the shared `api()` (fixes all surfaces at once) (F-S4, F-G8); surfaces re-fetch on re-activation when stale (>N min or after `ws-data-changed`); nonce-expiry handling (403 → reload prompt) (F-S5).
- **Empty/loading states:** skeleton rows instead of full-table "Laden…"; queue cards at 0 navigate to the (empty) grid; scope-aware empty states ("verborgen door 'Actieve edities'— toon alles") (F-V13, F-G14).
- Remaining Lows: indeterminate checkbox, dead code (`distColor`), greeting by time of day, agenda edition-grouping, eager tag fetches, impersonation token TTL + URL exposure, temp-ZIP hardening, audit-table guards (F-A4, F-A5, F-A8, F-E3).

### Phase 4 — Regression harness (parallel to 1–3, not after)

The test-gap analysis (2026-07-05) already showed the thin layer is exactly where these bugs live. Additions:
1. **Contract tests, the anti-drift class:** a unit test asserting every JS-sent param name appears in the endpoint's read set (and vice versa), every `sort` key the template offers is in the repository allowlist, every SMART_ACTION id has a nonce + registered handler, every queue key exists in both AdminStatsService and the grid's queue param. These are cheap greps-as-tests that would have caught F-G1, F-V2, F-T1 at commit time.
2. **Count-vs-view parity integration tests:** for each queue, seed rows, assert `count === grid(queue=X).total` (F-V2's permanent guard).
3. **Playwright per surface:** the five acceptance flows from the rebuild plan (AF-1..AF-8) actually driven — dossier person-switch (F-D1), dossier approve (F-D2), grouped select+bulk (F-G4), trajectory jump-to-grid (F-T1), export download.
4. **Label invariant:** an integration sweep asserting no raw enum value (regex `[a-z]+_[a-z]+` against known enums) reaches any admin JSON label field.

### Estimated total: 12–17 dev-days + review gates. Phase 1 alone (4–6 days) removes every "I have seen bugs on every page" class the review confirmed.

---

## 5. Suggested immediate next step

Start with Phase 0 (six decisions above — 30 minutes of Stefan's time), then execute Phase 1 as a single gated plan (`superpowers:writing-plans`, threat model not required — no new surface except the `queue` param, which reuses existing validated predicates; the XLSX/publish-guard fixes shrink attack surface). Roadmap item 0a should be updated: strike the accordion regression (fixed), keep anon-lead search (F-G3), and replace "needs a debugging pass" with a link to this document.

---

## 6. Slice log (execution against this register)

Base for all slices: `main`, branch `claude/admin-dashboard-review-imki0g`. Per-slice flow: fixes in small commits → unit + frontend suites green → multi-agent code review (high) with confirmed findings fixed → push.

### 2026-07-13 — Dossier slice (view 1)
Resolved: F-D1..F-D16 (all Dossier findings), plus the shared approve-core extraction (BulkRunner::approveRow — grid/dossier/roster parity) and the quote.email_sent PII exclusion from the stride_view feed. Two review rounds; both suites green.

### 2026-07-14 — Inschrijvingen slice (view 2)
Resolved: F-V2 (WorklistQueueResolver id-sets — count IS the click-through), F-V3/F-G2 (status-based admin-active scope + dismissable "Actieve edities" pill + `edition_scope` URL state), F-G1 (real Naam/Editie sorts incl. lead_name), F-G3 (lead_name/lead_email columns, schema v5 + guarded backfill), F-G4/F-G5 (grouped-view bulk bar + pager + honest copy), F-G6 (stubs disabled + "volgt binnenkort"), F-G7 (select-time status stamps + status-gated quote bulk + armed-selection status from queue/status context), F-G8 (load race token), F-G9/F-T1 (trajectory filter UI + chip + "Toon inschrijvingen" deep-link), F-G10 (edition typeahead + server group labels), F-G14 partial (indeterminate header checkbox, anon-lead row toast, queue-aware empty states, honest search placeholder, `distColor` removed).

Review-round fixes on top of the slice (2026-07-14, 8-angle panel): select-all blast-radius scope hole closed (applyScopePins shared by grid read AND BulkRunner expansion; payload carries queue + edition_scope; arming gated to status-homogeneous contexts), stale queue pin cleared on deep-link re-activation, name sort covers lead_name, v5 backfill stamps the version only when drained (error/cap → backoff + retry), per-queue resolver gating (no LD cert lookups for unrelated queues), resolveAnonymousIdentity reads the v5 columns (one identity definition), `learndash_course_completed` added to the stats bust set, INV-3 start_date read moved into EditionRepository (filterIdsWithStartDate), findActiveDateScopedIds renamed findAdminActiveIds (dead $graceDays dropped), AdminStatsService dead deps/imports removed, id-set pin helper unified, contract tests hardened (QUEUE_META extraction, labels, QUEUE_ROW_STATUS).

Still open (deferred with rationale): F-G12 (interest→pending needs a domain op + product ruling), F-G13 (offerte_status filter — the queue covers the workflow; needs resolver treatment), F-G11 partially (remaining domain-error translations), organisation search (F-G3 tail), F-G14 leftovers (skeleton rows, company by name, avg-attendance). UNIQUE-constraint piggyback retargeted to schema v6 (needs a dedupe pass first — tasks/todo.md #2).

Next views per the locked decisions: ~~Vandaag~~ (done 2026-07-14, see below), then Trajecten, Edities (scope reconciliation with findAdminActiveIds), Offertes, Gebruikers, Cohort (5a remove bulk bar).

### 2026-07-14 — Vandaag slice (view 3)

**P0 found by the slice's own scout:** the round-3 `$wants`→`$sets` rename missed the six guards inside WorklistQueueResolver's classification switch — every queue count rendered 0 and every ?queue= click-through opened an empty grid. Fixed with a regression test that actually drives rows through the switch (proven RED on the broken code); the prior suite never reached it.

Resolved: F-V1 (melding predicate matches the real task shape + PHP re-check via the shared awaitsAdmin rule — counts ONLY user-blocked rows, matching its own deep-link target), F-V4/F-V5 + 7a (pending card split "N klaar voor goedkeuring · M wachten op deelnemer", ONE readiness rule `EnrollmentCompletion::awaitsAdmin` shared by resolver split + panel bucket; panel pending-scan scoped to admin-active editions, fetch-all-pending scan — SQL task-shape pre-filters kept hiding subsets the card counted; approveRegistration tolerates task_not_required so NULL-task rows are actionable), F-V6 (`RegistrationStatus::capacityValues()` drives the melding subquery, getRegisteredCount, batchGetRegistrationCounts AND the batched free-spots probe `EditionService::hasAvailableSpotsBatch`), F-V7 + 6a (per-row melding dismissal on the shipped endpoint, optimistic with per-row re-insert on failure), F-V8 headline (distinct `worklistQueues.total`), F-V9 (one time basis: wp_date/current_time pairing for staleness, idle days, deadline countdowns, oldinterest cutoff), F-V10 (?fresh=1 endpoint-scoped cache bust — Vernieuwen is now true), F-V11 (clipped surfaced as an inline notice, not suppressed by a sibling call's error), F-V12 (stats payload trimmed to consumed keys — ~200 lines of dead query work incl. batch fetches removed), F-V13 (meldingen navigate INTO the workspace via targets + the new ?edition_id= deep-link; post.php?post=0 killed; server-rendered translatable time-of-day greeting; partial-load errors render as inline notices, never hide loaded buckets).

Review-round fixes (6-angle panel): **cache-bust hooks moved to AdminStatsService::init()** — they lived in admin_only AdminDashboardService, which never boots on REST requests, i.e. the busts never fired for exactly the writes the workspace makes (pre-existing for the count transients, would have extended to the new id-set cache); id-set transient keys made FIXED with the rev inside the value (rev-salted keys orphaned entries on every write); pendingSplit resolves the full queue set (one fetch/snapshot — a subset resolve could ship a split disagreeing with its own total); post_approval scan deliberately UNSCOPED (scoping hid post-course approvals on closed editions from every surface); empty active-edition set now means "edition-less rows only", never a whole-table fallback; findByEditionsAndStatuses fetches completion_tasks for pending rows only (CASE WHEN); editionScopeSql prepared (placeholder-throughout property restored); editionTarget()/bumpQueueRev() helpers; capacity===0-is-unlimited semantics restored exactly; dismiss rollback re-inserts only the failed row; Dutch inflection (wacht/wachten); ActionQueueIncompleteTasksTest fixture updated to the real task shape.

**Testing-environment correction (honesty):** the "frontend specs green" claims in earlier slice entries covered the Node-mode mapper/contract specs only. The six browser+DDEV spec files (dossier/trajecten cold-landing, edition-admin, inschrijvingen-grid, lazyload-landing, session-price-modifiers — 79 tests) shell out to `ddev exec` and CANNOT run in the review environment; a version-mismatched Playwright browser additionally masked them as generic failures. They must be run locally in DDEV before deploy, alongside the PHP integration suite.

Deferred with rationale: StrideConfig.queues server-owned vocabulary (regex contract tests hold; revisit when mail-broadcast rebuilds the cards); findAdminActiveIds cross-request caching (pre-existing per-request scan, grows with corpus — revisit at Edities-slice scope reconciliation); ?edition_id= composing with a later queue-card click (mirrors the blessed trajectory_id composition — chip visible; watch for admin confusion); F-V8 full interest-queue lifecycle (needs the outreach/"uitgenodigd" product decision, phase-0 #3).

### 2026-07-14 — Round-3 panel (code-review high + simplicity review, full slice diff)
Eight-angle panel over `570efa0..HEAD`. Correctness fixes: bookmarked `?p=` no longer stomped by the first-activation `load(1)`; trajectory_id reclassified as a first-class FILTER (survives view round-trips like status/edition/q — the round-2 "mirror both ways" clear had wiped user-picked Traject filters; shell no longer deletes it, absorb-only deep-link); armed select-all cleared on search change; retry selection re-stamped in closeResult; quote pre-resolve now drops select_all (no double expansion / map drift); grouped aggregates gained deterministic tiebreakers (tied counts could shuffle pages AND desync the offerte tally's group set); dbDelta IF-NOT-EXISTS removed (it never diffed columns — create() on an old table stamped v5 with the lead columns missing); lead columns stamped UNCONDITIONALLY on rewrite (a scrubbed identity now clears the denormalized copy — GDPR); grid/roster anonymity unified for deleted WP users; client-supplied queue_ids/active_edition_ids stripped from bulk filters; migrate() gained a per-step version cursor (paused v5 backfill resumes at the batch loop, not through every prior ALTER).

Simplicity fixes: one QUEUE_META table ({label,status} — QUEUE_ROW_STATUS deleted); AdminStatsService::bustCaches() (both hook sites; magic string killed); RegistrationRepository::presentLeadIdentity (one '(anoniem)' rule for grid + roster); migrate() failStep() helper; resolver $wants removed + collection maps guarded + getEffectiveStatuses narrowed to waitlist-hosting editions; queue funnel derived from the flat total (statusForQueue) instead of re-shipping the id-set; buildGridFilters trip-wire log for unscoped builds (the blast-radius class can no longer be silent); stale docblocks + the INV-3 exemption rename fixed; duplicate TS queue-status value test dropped (PHP contract test is authoritative).

Deferred with rationale: short-TTL id-set cache for nocert/offerte queue resolution + batched waitlist capacity counts (Vandaag slice — the resolver is touched again there; needs a bust hook tie-in); StrideConfig.queues server-owned vocabulary replacing the regex contract tests (Vandaag slice, when the cards are rebuilt); WS.takeLatest shared stale-response helper (cross-factory, next JS-heavy slice); a dedicated pause signal distinct from RETRY_TRANSIENT (log level already distinguishes; revisit if ops confuses pause with failure).

### 2026-07-14 — Trajecten slice (view 4)

Resolved: F-T2 (ONE status vocabulary — trajectory statusLabels from `OfferingStatus::label()`, the filter dropdown enumerates the enum's cases, and 'active' = NOT admin-closed via the shared `OfferingStatus::adminClosedValues()` boundary; the old hand-rolled maps knew a fictional 'closed' status that matched nothing), F-T3 (roster status_label from `RegistrationStatus::label()` + STATUS_BADGE hue parity — the old table was keyed on a fictional 'active' and slate-greyed confirmed/waitlist/interest), F-T4 (server pager instead of a silent 50-row cap; honest clipped-roster note; cancelled parents excluded from the deelnemers counts; prices treated as canonical CENTS — the euro-float formatting overstated every price 100×; deleted-account roster rows kept via `presentLeadIdentity` and keyed on `regId`, never collapsed on user id 0; Escape closes the slide-over).

Review-round fixes (2 finders → 16 candidates → verify): pager brought onto the SHARED contract (`goPage(p)` absolute + `pageList()` ellipsis + the ws-pager markup — the slice's `goPage(delta)` variant was a cross-surface trap: any dev copying a pager button between surfaces would silently jump to page 1 or 2); page pointer clamped + refetched when the result set shrinks under it; Escape gained the `view === 'trajecten'` guard (it closed the hidden detail from OTHER views); `openDetail` race token (`_detailReq`) — Escape during load actually cancels, a stale response can't resurrect a closed slide-over; picking completed/archived under scope=active auto-widens to 'all' (that combination was structurally empty); THE admin-active trajectory predicate extracted to `TrajectoryRepository::adminActiveWhereFragment()` and spliced by BOTH the list scope and the grid typeahead (the two copies were one edit away from drifting); list payload roster trim — the list rendered no roster but shipped up to ~2,500 rows of names/e-mails per page (fetch + user batch deleted; `enrolledUsers` dropped from the list items and from the detail response where `registrations` duplicates it); mode label from `TrajectoryMode` (the old match knew a fictional 'open' mode and rendered self_paced as "Self_paced"); both prices cast `(int)` cents; `countByTrajectory` default now excludes cancelled like the batch method (CPT list-table column vs workspace count parity); clipped-roster note is ONE translatable sentence, not concatenated fragments; integration fixture 'closed' → 'completed'. New cross-language contract test `TrajectenStatusVocabularyTest`: every OfferingStatus AND RegistrationStatus value must have a STATUS_BADGE hue matching edities.js/grid.js STATUS_META, and STATUS_BADGE may contain no key outside the two enums — the fictional-vocabulary class fails at commit time now.

**Environment note:** a container rollback mid-round reverted the working tree to the identity-slice commit and lost the uncommitted review batch; everything pushed (through `ab8e446`) was intact on the remote and the batch was re-applied from the written fix list. Push-early discipline paid for itself.

Suites: PHP unit 1399 green; Node-mode frontend specs 118 green (the six browser+DDEV spec files still require a local DDEV run before deploy, as recorded in the Vandaag entry).

### 2026-07-14 — Edities slice (view 5)

Resolved: F-E1 (Agenda|Lijst view toggle — the surface was agenda-only, whose session-date INNER JOIN made dateless editions, the §10.7 sessionless interest anchors, invisible in the entire workspace; the NULL-permitting list view finally has its UI caller, rows keyed 'e'+id disjoint from agenda 's'+sessionId; the list view's `'' < $today` string comparisons that flagged dateless editions as past — and end-date-only ones as *today* — fixed, latent while the view had no caller), F-E2 (the silent 2-day lookback is now a visible "Aankomend"/"Alles" scope pill, server `scope=upcoming|all`, deliberately NOT named 'active' — the typeahead/trajecten scopes are status-boundaries while this is a pure date cutoff, recorded at the route registration; Status dropdown added matching the EFFECTIVE status the badge renders via one id-set — the stored-meta filter disagreed with the rendered label exactly where they diverge; admin-closed statuses auto-widen; server-owned Dutch date labels replace raw ISO cells), F-E3 (tag vocabulary fetch moved inside the lazy-load gate; the agenda's per-session repetition is by design now that Lijst offers the collapsed read; exports remain Phase 3), F-A2 (CR-4 publish guards on GET /admin/editions/{id} + /{id}/registrations — trashed/draft edition detail and participant PII were readable by id; roster/export were already guarded, the set is now uniform). Scope reconciliation recorded in findAdminActiveIds' NOTE: the divergence from the surface's date scope stays by design, now visible and escapable; cross-request caching of findAdminActiveIds decided AGAINST (one indexed scan over hundreds of rows vs invalidation state on every status/publish transition).

Review-round fixes (6-angle panel → 33 candidates → dedup/verify): the status auto-widen moved to a dedicated onStatusChange handler — bound to the shared onFilterChange it re-overrode a scope the user had explicitly narrowed on every Tag change; clearAllFilters restores the DEFAULT surface (scope back to 'upcoming' — the auto-widen otherwise leaked 'Alles' past the reset) and the onDateChange cleared-branch got the both-empty guard its comment already claimed (every 'Filters wissen' double-fetched); scope=all reads DESC (repo find methods gained a whitelisted order param) — the widen exists to reach recently-finished editions, which ASC buried behind the full history; the scope pill hides while a date filter is active (the server ignores scope then — a toggleable no-op pill reads as broken); `EditionService::isPastDates` made PUBLIC + moved to site-TZ current_time (the one time basis, F-V9 — the server-TZ date() made the badge disagree with every current_time read around midnight; the current_time stub's format-string handling was also wrong and fixed), and the list view's isPast now delegates to it — one calendar predicate for row flag and badge; editionDateRangeLabel formats each bound independently with raw-ISO fallback (end-date-only editions rendered 'Geen datum' beside an 'Afgelopen' badge; a half-unparseable pair left a dangling '– '); the effective-status filter extracted to `EditionService::findIdsByEffectiveStatus` returning the corpus map so the page's badge read REUSES it (the corpus was resolved twice per filtered request), spliced by ONE controller helper for both views (the two ~16-line copies differed by two tokens) with the shared empty envelope; scope parsed once in getEditions and passed down (the agenda re-parse was a second whitelist waiting to drift); `ADMIN_CLOSED` const in edities.js + trajecten.js pinned to OfferingStatus::adminClosedValues() by both vocabulary tests (the auto-widen conditionals hardcoded the strings untested); Alpine `:title` expression strings esc_js'd in edities + trajecten (esc_attr's entity-encoded apostrophe HTML-decodes back and breaks the expression — a translator-triggered failure); dynamic `icon(cond ? … : …)` x-html ternaries split into two constant-literal x-show spans (INV-5) in both templates; empty state made three-way (the scope='all' no-filters branch told the user to clear filters that didn't exist while rendering no button); extractJsBlock hoisted to the shared TestCase (three drifting copies). Accepted as documented behavior change: `?status=` now speaks ONLY the OfferingStatus vocabulary — a legacy out-of-enum stored string matches nothing (the old raw-meta equality matched it), consistent with every badge and dropdown on the surface. Deferred: the full-corpus PHP resolution for the effective-status filter stands on the CR-2 typeahead precedent (hundreds of editions, batched reads); if the corpus outgrows it, the resolution strategy now lives in ONE service method to change.

Suites: PHP unit 1403 green; Node-mode frontend specs 127 green (the six browser+DDEV spec files still require a local DDEV run before deploy).

### 2026-07-15 — Offertes slice (view 6)

Resolved: F-O2 (search finally matches quote NUMBERS as the placeholder promised — customer-OR-number in one predicate; Datum column rendered with a server Dutch dateLabel, so the date filter no longer filters an invisible post_date; Status dropdown from QuoteStatus::cases(), the server param already flowed end-to-end; the Tag filter renamed "Editietag" with an explanatory title — it filters via the linked edition's course tag, so edition-less quotes fall outside every tag BY DESIGN, now stated instead of silent), F-A8 (the zero-user-search short-circuit and its divergent data/per_page envelope removed at the root — with the number half in the OR there is no zero-match branch; every path returns the one items/totalPages envelope; the client quoteRows() normalizer stays as defensive tolerance), F-O1 proportionate (locked joins the payload and renders as a lock icon beside the badge — read-only visible BEFORE the click; per-row Dossier button into the customer's case view; a visible Bewerken/Bekijken action makes the row-click navigation honest). DEFERRED BY DESIGN: status transitions, send, and PDF from the list — the WP edit screen's QuoteActionsMetabox is the quote workbench (send, transitions, voucher, PDF regenerate, locking, validation); duplicating write flows in the list is over-engineering while the workbench exists. The F-E3 lesson applied on arrival: tag fetch lazy-gated, onDateChange both-empty guard, single-fetch reset.

Review-round fixes (3 finder angles → 17 candidates → dedup/verify): Bewerken got `.stop` (the click bubbled to the row's own openRow — a latent double-fire) and goes locked-aware ("Bekijken" + read-only tooltip — a button promising send/status next to a lock icon was the exact surprise F-O1 removes); lock icon sized inline-flex (a bare inline span ignores width/height and the SVG blew up to the cell); the server emits user id 0 for a DELETED account (the roster rule) so the Dossier button/openPerson can key on id instead of navigating to a nonexistent case view; the draft status filter also matches quotes with NO status meta row (the read-model defaults those to draft — the filter and the badge disagreed about the same row); `_listReq` race token in load() (search+status+tag+date make rapid triggers routine; the grid/trajecten pattern); emptyTitle names filter-caused emptiness for status/tag/date, not just search; the quote_number fragment moved to QuoteRepository::numberSearchWhereFragment (INV-3 — net-new query shapes live with the owning repo, the adminActiveWhereFragment precedent); the always-run customer LIKE documented as an ACCEPTED COST (single-digit ms at LMS scale, admin-only, debounced) with a trip-wire log when the 500-user cap truncates matches; QUOTE_BADGE's draft=amber documented as a DELIBERATE cross-surface exception (a quote draft is "In behandeling" — work awaiting the admin — not an inert concept); three stale Phase-1-freeze comments corrected (offertes.js normalizer block, template header, spec header); the no-fictional-keys scan consolidated into TestCase::assertJsMapKeysWithinEnum (three drifting copies, digit-tolerant key regex). Deferred: the near-identical flatpickr/date-filter block across edities.js/offertes.js stays per-surface (the pager precedent) — flagged for the shared-WS-helper pass alongside WS.takeLatest.

Suites: PHP unit 1405 green; Node-mode frontend specs 130 green (the six browser+DDEV spec files still require a local DDEV run before deploy).

### 2026-07-15 — Gebruikers slice (view 7)

Resolved F-U1 in full: the hard cap of 10 presented as the complete set ("10 resultaten", no way to reach the rest) → `/admin/users/search` is PAGED (page/per_page, count_total) with the standard envelope, the surface shows the true ranged total and the shared ws-pager; the 1-character search that flashed the server's raw English 400 on every keystroke → client-side minimum-length guard (the prompt names the 2-char minimum; the server minLength stays as defense); anonymised users un-flagged → `is_anonymised` from THE convergence predicate (`UserLifecycleService::isAnonymised`, CR-6) renders a "Geanonimiseerd" badge, so GDPR-scrubbed accounts never read as odd real people. A `_searchReq` token drops stale responses, including an in-flight longer search when the admin deletes back below the minimum.

Review-round fixes (2 finder angles → 12 candidates): the anonymised read routed through `UserLifecycleService::isAnonymised()` instead of a sixth inline meta read, and the payload key renamed `is_anonymised` to match the dossier/cohort-lens payloads (two names for one concept invites a silently-never-rendering badge); the legacy bare-array tolerance DELETED from the normalizer — the bare shape was the capped response, and passing it through would recreate the F-U1 lie under the new honest count (a blank list with the empty state beats a lying complete one; client+server deploy atomically); the toolbar count and pager hide while a request is in flight (a new term displayed the previous term's total and a stale pager invited out-of-range page clicks); the minimum-length guard counts CODE POINTS (a single emoji is 2 UTF-16 units and slipped past `.length` into the 400); **shared ws-pager contract fix across all five surfaces** — `pageList()` emits the '…' sentinel twice mid-range and the `:key="p"` duplicate corrupted Alpine's keyed reconciliation exactly on deep result sets; keys are now the loop index (gebruikers, inschrijvingen, edities, offertes, trajecten); the one-exit envelope (the empty-result early return duplicated the envelope literal — both batch callees no-op on empty); the wildcard+count_total per-keystroke cost documented as ACCEPTED (offertes precedent). Noted, deferred: the pager/race-token block is now a fifth per-surface copy — the WS shared-helper pass (WS.takeLatest + pager) is due as its own deliberate refactor, not a mid-slice side quest; searchUsers stays in the controller (accepted zone) — flagged that extraction to AdminUserService would be the strangle-consistent home if it grows again.

Suites: PHP unit 1405 green; Node-mode frontend specs 135 green (the six browser+DDEV spec files still require a local DDEV run before deploy).

### 2026-07-15 — Cohort lens slice (view 8 — CLOSES THE REGISTER'S VIEW SET)

Resolved: F-C1 per decision 5a — the roster bulk bar REMOVED (the cohort roster is confirmed/completed only per CR-1, so the one lifecycle action could never appear; message/generate_doc were stubs failing every row); selection state, result modal and the bulk machinery deleted; lifecycle work lives on the Inschrijvingen grid. F-C2 — rows carry `attendance_by_session` (per-session latest-wins map; the aggregates are now DERIVED from that same deduped map, so duplicate historical records no longer double-count) and marking is OPTIMISTIC via the pure `cohortApplyMark` (row-local patch + rollback; twenty marks used to mean twenty full roster reloads with the scroll lost); the active mark button is lit, clicking it again clears, the clear button only shows when a mark exists (its 'slash' icon existed nowhere in the icon set and rendered an empty SVG — added), and the attendance cell shows the SELECTED session's state. F-C3 — a mark flags the lens dirty and close() dispatches ws-refresh for edities/inschrijvingen/vandaag. F-C4 — the detail fetch's silent `.catch(() => ({}))` removed (a failed detail produced a sessionless lens where marking was impossible with no signal — now error + retry); per-row extras column when extras_keys exist; lens-open buttons on grid/vandaag stay deferred (Phase 3 navigation).

Review-round fixes (2 finder angles → 12 candidates): markAttendance reworked onto the ROW + registration_id (rows key on registration_id, and a user CAN hold two cohort rows — the user-id lookup routed every click to the first row, and a second attempt then read the wrong row's state and CLEARED the mark the admin thought they just made); a per-row in-flight guard (buttons disable — two rapid clicks raced snapshots and could erase a successful mark on rollback); the rollback is edition-scoped (a late failure after close/reopen could splice edition A's row object into edition B's roster); `_mutated` set BEFORE the await (close() reads it synchronously — closing while the last mark was in flight skipped the refresh, the exact F-C3 hole); **the six stride_roster_bulk_* / stride_traj_roster_bulk_* nonces DROPPED from StrideConfig.bulkNonces** — they were still minted on every admin page for a UI that no longer exists, handing every admin-page script pre-armed CSRF tokens for a live pending→confirmed mutation surface no flow exercises; without armed nonces the (kept) RosterBulkHandler registry is unreachable by design, and its retention comment now says CURRENTLY UNCONSUMED instead of falsely attributing it to the grid; ws-refresh reloads keep the CURRENT page on edities/offertes/grid (the dispatch used to snap the admin back to page 1 — their place in the list is work state) and edities.js finally got the `_listReq` race token (the last unguarded list load); `id DESC` tiebreaker on the attendance ORDER BY (two records in the same second made latest-wins nondeterministic); new CohortStatusVocabularyTest pins STATUS_LABEL to RegistrationStatus (a missing key renders the CANCELLED hue) and MARK_LABEL to AttendanceStatus (a missing key renders '—' and silently drops from both aggregate computations); the empty init() stub removed. Deferred with rationale: the attendance dedup lives in the roster service while PartnerAPI consumes raw getByUsers records — unifying them changes a partner-facing external API and needs its own decision (flagged to Stefan); grid/vandaag lens-open dispatch (Phase 3).

Suites: PHP unit 1407 green; Node-mode frontend specs 136 green.

---

## 7. Register close-out (2026-07-15)

All eight views of the locked order are done: Dossier, Inschrijvingen, (identity detour), Vandaag, Trajecten, Edities, Offertes, Gebruikers, Cohort lens — each with its own multi-agent review round, all confirmed findings fixed, all work pushed. Remaining open items live in three buckets:
1. **Phase 3 (workflow/UX)**: exports honoring grid filters (F-A9/F-E3), pushState navigation + origin-aware back, global search (⌘K), lens-open from grid/vandaag, skeleton loading states, the WS shared-helper pass (pager + takeLatest — five per-surface copies, trigger condition met at the Gebruikers slice).
2. **Product decisions still with Stefan**: phase-0 items not yet ruled (interest-queue outreach lifecycle, F-G12 interest→pending domain op); the PartnerAPI attendance dedup unification (external API semantics).
3. **Pre-deploy checklist (every deploy until the DDEV specs run in CI)**: run the PHP integration suite + the six browser+DDEV Playwright spec files locally; run scripts/adopt-leads.php against a copy of production data once; eyeball the interest/waitlist public flows.
