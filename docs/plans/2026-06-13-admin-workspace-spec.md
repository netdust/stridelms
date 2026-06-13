# Stride Admin Workspace — Spec & Implementation Plan

> **This IS the `spec.md` the design brief calls for.** Named per the project's dated-plan convention. The first-slice UI mockups are built by a separate agent FROM this spec; this document does not contain mockups.

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:subagent-driven-development` (recommended) or `superpowers:executing-plans` to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Turn the entity-organised Stride admin dashboard into a *workbench* — a worklist home, a multi-select registrations grid with bulk actions and group-by, and a per-person case view — by **extending the existing Alpine `strideApp`**, not rewriting it.

**Architecture:** A new batched-join REST read-model (`GET /stride/v1/admin/registrations`) feeds a new Alpine grid component with multi-select state and a bulk-action bar. Bulk actions are a state-machine over the real `RegistrationStatus` enum, executed through ~8–10 smart-action handlers registered on the existing generic `ntdst/v1/action` registry (each minting its own nonce), one generic bulk-set-field for safe columns, and field-scoped export as the long-tail fallback. **No projection table, no postmeta-per-row join, no JSON in any WHERE/GROUP BY.**

**Tech Stack:** WordPress (Bedrock) · NTDST Core (DI, repositories, `ntdst/v1/action` registry) · `stride-core` mu-plugin · Alpine.js + the existing 955-line `admin-dashboard.js` · `wp_vad_registrations` custom table (already indexed) · `QuoteRepository` · DOMPDF/exporters.

**Work classification:** **Class A** — new multi-surface feature, full Stage 0→1 gated plan. Stage 0 brainstorming was pre-resolved by the design brief + settled user decisions (the convergent design exists); this document is the Stage-1 gated spec.

---

## 0. Source-of-truth corrections (grounding done — facts that override the brief)

Grounding read the codebase. **Where code contradicts the brief, the code wins.** Four brief errors are SETTLED and reflected throughout this spec; one additional refinement surfaced during spot-verification against source.

| # | Brief claimed | Source truth (verified) | Where it lands in this spec |
|---|---|---|---|
| 1 | Payment is tracked; "unpaid & confirmed" queue; "payment" column | **No payment tracking.** `Domain/QuoteStatus.php` = `Draft`/`Sent`/`Exported`/`Cancelled` (workflow, not money). `paid_at` is **dead** (read at `AdminAPIController.php:~2736`, shown in `dashboard.php:~1325`, never written). Exact Online owns invoicing (CLAUDE.md Decision #3). | The grid column is **offerte-status** (Geen offerte / In behandeling=Draft / Verzonden=Sent / Verwerkt=Exported). The worklist queue is **offerte-opvolging** = confirmed registrations with (no quote OR quote not yet `Exported`). Labelled honestly, never "onbetaald". Precedent: `AnnualReportService::quoteAggregates()` (~line 415) already uses `status === Exported` as the "sent to Exact" proxy. |
| 2 | `enrollment_data` stage = `{data, submitted_at}` | Each stage = **`{submitted_at, submitted_by, data}`** (3 keys). Stage keys: `interest`, `waitlist`, `enrollment_personal`, `enrollment_billing`, `intake`, `evaluation`, plus `initial_selection` (un-wrapped: `{type, phases[]}`). | Case-view rendering (§4.3) reads `stage.data` and surfaces `submitted_at` + `submitted_by` per stage. |
| 3 | (Implied) a grid-wide registrations list exists | **It does not.** Registrations are reachable only per-edition (`/admin/editions/{id}/registrations`) or per-user (`/admin/users/{id}/detail`). | **New endpoint** `GET /stride/v1/admin/registrations` — batched-join read-model (§5). The single biggest new backend piece. |
| 4 | Exporters can be made field-scoped easily | 5 exporters, **zero field-scoping**, fixed column sets. Only PII guard at the export boundary: `EditionFilesZipExporter` skips `_stride_anonymised_at` users. | Field-scoped deliverable export is genuine new work — **deferred to Phase 3** (§9), not first slice. |
| **5** *(new, spot-verified)* | `findQuoteIdsByRegistrations` returns a quote-status map | It returns `array<int regId, int quoteId>` — **quote post IDs, not statuses** (`QuoteRepository.php:121`, MIN(ID) tiebreak). | The grid's offerte-status resolver is **two-step**: (a) `findQuoteIdsByRegistrations($regIds)` → reg→quoteId map; (b) one batched `QuoteRepository` read of those quote IDs' `status` field → quoteId→status map; compose to reg→offerteStatus. Specified in Task 2.2. |

**Confirmed correct, do NOT re-litigate:** quote↔registration is strictly **1:1** (`QuoteRepository`, `registration_id` single int meta, `quote_id` single FK); the `ntdst/v1/action` registry works as described (dispatch `apply_filters("ntdst/api_data/{$action}", [], $params)` at `Endpoints.php:353`, register via `add_filter`, guarded by per-action rate-limit + Origin/Referer CSRF + `wp_verify_nonce($nonce,$action)` at `:343` + `has_filter` 404 at `:349`); the action-queue transient `stride_action_queue` busts on `stride/registration/{created,confirmed,cancelled}`, `stride/attendance/marked`, `save_post_vad_quote`; `admin-dashboard.js` is one 955-line `strideApp` factory, 5 tabs, zero multi-select.

---

## 1. Information architecture — three surfaces + the cohort lens

All UI text is **Dutch (nl_BE)**, matching the existing dashboard vocabulary (`edities`, `sessies`, `offertes`, `gebruikers`, `trajecten`, "Acties nodig").

### Surface 1 — "Vandaag" (worklist home, the front door)
Promotes the buried "Acties nodig" queue to the home screen. Named queues with **live counts**, each a saved filter over **structured columns only**:

| Queue (Dutch label) | Definition (structured only) | Default bulk action armed |
|---|---|---|
| **Wacht op goedkeuring** | `status = pending` | Goedkeuren (bulk-approve) |
| **Wachtlijst — plaatsen vrij** | `status = waitlist` AND edition has open capacity | Promoveer van wachtlijst |
| **Offerte-opvolging** *(NOT "onbetaald")* | `status = confirmed` AND (no quote OR quote.status ≠ `Exported`) | Markeer offerte verzonden / verwerkt |
| **Afgerond zonder certificaat** | `status = completed` AND `completed_at` set AND no LD certificate (via `LearnDashHelper::getCertificateLink`) | Bericht sturen |
| **Oude interesse** | `status = interest` AND `registered_at` older than N days | Bericht sturen / archiveren |

Clicking a queue opens **Surface 2 pre-filtered** with the relevant bulk action ready. These five ARE the shipped saved views (the brief's "~5 pre-made worklists"). A blank view-builder is explicitly **NOT** the front door.

**Queue counts are scoped to active editions** (§10). A coordinator chases *live* work — nobody promotes a waitlist on a 2021 edition. The count queries (`countByEditions`/`statusBreakdownByEditions`/`batchGetRegistrationCounts`, all verified to take an explicit edition-ID set) are fed the active-edition subset, not the whole corpus, so the counts stay both fast and meaningful at 200+ total editions.

### Surface 2 — "Inschrijvingen" (the registrations grid / workbench)
Row = **one registration in its current state** (NOT one row per write/event — events live in the case-view history). Columns are a **composite from structured sources**:

| Column | Source |
|---|---|
| Naam | `user` (batch-resolved display name) |
| Editie | `edition_id` → edition title (batch-resolved) |
| Status | `vad_registrations.status` → `RegistrationStatus::label()` |
| Offerte | quote workflow status (two-step resolver, §0 #5) — Geen offerte / In behandeling / Verzonden / Verwerkt |
| Aanwezigheid % | `vad_attendance` aggregate (batch) |
| Organisatie | `company_id` (batch-resolved) |
| Traject | `trajectory_id` → trajectory title (when the row belongs to a trajectory; batch-resolved). Most rows are not in a trajectory → blank. |

- **The status filter is presented as the enrollment PIPELINE, not a row of bare chips** (mockup-validated 2026-06-13). The lifecycle reads left→right as a funnel labelled "Fase in inschrijvingsproces": *Interesse getoond → Op wachtlijst → Wacht op goedkeuring → Bevestigd / ingeschreven → Afgerond*, connected by arrows, each a clickable toggle carrying its live count. **`Geannuleerd` is split off after a divider as a dead-end exit**, never inline in the funnel. Microcopy is self-explanatory ("where this person is in the process"), not single words — bare words like "Interesse"/"Wachtlijst" tested as meaningless. The *Wacht op goedkeuring* step carries a tooltip noting the two real sub-states (waiting on the user to finish tasks, OR waiting on admin approval once tasks are done — see §2.4).
- **Group-by is the control "Indelen per"** (Editie / Status / Organisatie) — labelled so it reads as restructuring the view, not sorting. It collapses the list into **collapsible sections** per distinct value; each section header shows aggregates **from structured data only**: `count`, `% afgerond` (via `completed_at`), gem. aanwezigheid % (via `vad_attendance`), offerte-verdeling (a small quote-status breakdown, e.g. "2 verwerkt · 3 verzonden · 1 geen"). **Never** a questionnaire/`enrollment_data` JSON aggregate. This in-grid grouping replaces Excel pivot tables. Multi-select + bulk bar work inside grouped view.
- **Multi-select → bulk-action bar.** Top 3 actions surface as buttons; the rest in an overflow menu. The actions shown are **state-aware** — derived from the §2.1 transition map for the current selection (a mixed-state selection narrows to the safe intersection).
- **Server-side pagination + filtering** (see §5 + §10 perf notes — never load all rows client-side; Alpine cannot virtualize that).
- **Defaults to active editions only** (see §10) — archived/past editions are excluded from the live grid + queue counts but reachable via the searchable edition picker.
- **Filter by trajectory** — just like the edition filter, the grid can be scoped to a trajectory (`trajectory_id` is indexed identically — `idx_trajectory`/`idx_trajectory_status`, verified). **Semantics (decided):** filtering by trajectory T shows the **child edition-registrations** of that trajectory (the actual course participations), NOT the parent row — so the grid stays edition-grained and attendance %/offerte/status columns keep their meaning per row. This requires the parent→child join, not a naïve `WHERE trajectory_id = T` (a trajectory is **1 parent row** `trajectory_id=T, edition_id=NULL` **+ N child rows** `edition_id=E, parent_registration_id=parent, trajectory_id=NULL` on cascade — a naïve filter would catch only the parent and miss every course row). The repository already has `findEditionsByTrajectory()`; the grid query reuses that join shape (§3.1). A small "Traject: <naam>" filter pill shows the scope, fed by a trajectory typeahead (same lightweight-picker pattern as editions, §10).
- Click a row → Surface 3.

### Surface 3 — "Dossier" (case view, per person → per registration)
The full join for one registrant. A **person can hold multiple registrations** → person-headed, registrations listed, expand one for detail. Powered by the **existing** `GET /admin/users/{id}/detail` (REUSE — §6). Renders:
- Registration detail. The lifecycle status field is labelled **"Inschrijvingsstatus"** (not bare "Status"). A `pending` registration shows an inline hint explaining the two sub-states (waiting on the user vs waiting on admin approval — §2.4).
- **`enrollment_data` stages as collapsible panels** (mockup-validated 2026-06-13): for each stage (`interest`, `waitlist`, `enrollment_personal`, `enrollment_billing`, `intake`, `evaluation`, `initial_selection`):
  - **Stages with no submitted data are HIDDEN entirely** — a stage is empty if it's null/absent or its `.data` is empty. Do not render an empty panel.
  - **Stages with data render as CLOSED panels** that open on click. The closed header shows the stage's Dutch name + `submitted_at` + `submitted_by` (the 3-key shape, §0 #2). Opened, `stage.data` renders as **clean humanized label→value pairs**, never raw JSON.
  - **Dutch stage names:** `intake` → **"Intakevragenlijst"**, `evaluation` → **"Evaluatie (na afloop)"**, etc.
- **There is NO separate "Vragenlijst" block.** The intake questionnaire answers ARE the `intake` stage's data — render them there ONCE. ("Intakevragen" may still appear as a *completion-task* status in the tasks list, but the answers live only under the intake stage.) Source: the `questionnaire` completion-task and the `intake` enrollment-data stage are the same dataset (verified — `QuestionnaireHandler`, `EnrollmentCompletion`). The brief's separate questionnaire-answers section was a duplication and is removed.
- Linked quote (amount + **offerte-status**, never "betaald/onbetaald").
- Attendance per session, selected sessions (read via the server-side detail payload per **INV-6b** — never the raw `selections` column), `completion_tasks`, notes.
- **Trajectory section (when the person is in a trajectory)** — for each trajectory the user is enrolled in, show: the trajectory title + status + mode; **progress** (afgerond / bezig / nog te doen — `completed_count` / `in_progress_count` / `total_required`); the **required courses** with per-course state (afgerond via the LMS adapter `isComplete`, bezig via an active edition registration, nog te volgen); and the **elective groups** with **what the user chose vs. the group requirement** ("Keuze A: 2 van 2 gekozen — *Module X, Module Y*"). This is the user's other half of the story (the grid shows only edition rows). **This is the one genuine backend gap** — `getUserDetail` returns *no* trajectory data today (verified). But the compute already exists and is used by the live user-facing views (`TrajectoryDashboardService::getProgressData`, `TrajectorySelection::getSelectedCourseIds`/`isGroupChosen`/`countChosenInGroup`; mirrored from `tab-trajecten.php` + `tab-voortgang.php`) — so this is **wiring, not new logic** (see §11 + Task 3.5). Read selections via `TrajectorySelection` per **INV-6b**, never the raw `selections` column.
- The **state-appropriate action buttons** (§2 — same map as the grid), styled as real buttons under "Acties voor deze inschrijving"; a terminal (`cancelled`) registration shows a muted "geen acties" state.
- A **history timeline** — this is where per-write events live (events are history *inside* a record, never the main grid). It surfaces the full audited event spectrum: registration created/confirmed/cancelled, interest/waitlist, **attendance marked** (present/absent/excused), quote created/sent, completion-task/approval, certificate issued/course completed, admin messages, **and session selection**. ⚠️ **Implementation gap (small, named):** session-selection events ARE recorded (`session.selections_updated` + the `initial_selection.phases[]` trail with `captured_at`/`captured_by`) but are NOT currently surfaced in the admin timeline — `session.selections_updated` is absent from `AdminActivityMapper::KNOWN_ACTIONS`. Closing this is a one-line addition to that list (plus a label), tracked as Task 3.4a. The mockup shows these events because the data backs them.

### Surface 4 — "Trajecten" (trajectory overview tab — IN the first slice, read-only)
A dedicated tab (alongside edities / offertes / gebruikers), **mirroring the editions-tab pattern**. This is a *view that does not exist today* — but its **backing endpoints already do**: `GET /admin/trajectories` (list) and `GET /admin/trajectories/{id}` (detail with the course list + enrolled users) are already in `AdminAPIController` (verified). So Surface 4 is mostly a **frontend tab over existing endpoints**, not net-new backend.
- **List:** each trajectory with title, status (Concept/Open/Volzet/Afgesloten/Gearchiveerd), mode (cohort/self-paced), capacity, enrolled count, course count. Search + status filter, active-by-default scope (same posture as editions, §10).
- **Detail (slide-over):** the trajectory's course list (required + elective groups) and its enrolled users (with per-user trajectory status).
- **Jump to the grid:** from a trajectory, "Toon inschrijvingen" opens Surface 2 filtered to that trajectory (child edition-rows, per the filter semantics above) — the trajectory tab is the overview, the grid is where you act.
- **Phase 1 = read-only overview + jump-to-grid.** Trajectory-specific *bulk actions* on the trajectory roster are deferred (Phase 2 cohort work) — the existing per-registration bulk actions already apply once you're in the grid.

### The Editie/sessie cohort lens (described fully; FIRST SLICE excludes it — Phase 2)
- Edition → its sessions → per-session roster ("who is in which session"), derived from `registrations.selections` **within the edition's loaded roster** (a small set — no global query, no index, no projection).
- Roster surfaces logistics/extras fields (book/lunch/vegan/dietary) as columns/badges, with filter & count **within the loaded roster** (per-edition, small set). Extras vary per edition → **never a global column**.
- Attendance marking per session (`vad_attendance`); the reverse per registrant (their selected sessions).
- Bulk actions on the roster (confirm, message, generate docs). The existing Edition exporters live here.

---

## 2. The action model — a state machine over the real enum

**Actions belong to row STATE, not to views.** Registration is a state machine over the real `RegistrationStatus` enum (`Domain/RegistrationStatus.php`, verified): `confirmed`, `completed`, `cancelled`, `waitlist`, `interest`, `pending`. Define the state→valid-transitions map **once**; every view (grid bulk-bar, case-view buttons, cohort roster) inherits the buttons from it.

### 2.1 State → valid transitions → smart action (the single map)

This table is the source of truth. It is implemented once as a PHP map (Task 1.1) and mirrored to the Alpine client (Task 4.1) so the UI and server agree on what's allowed.

| From state | Valid transition(s) | Smart action (handler) | Domain effect |
|---|---|---|---|
| `pending` | → `confirmed` | `stride_bulk_approve` | `EnrollmentCompletion::completeTask(id,'approval')` then `EnrollmentService::confirmRegistration(id)` (wraps the single-item `approveRegistration`, §6) — grants LD access, fires `stride/registration/confirmed` |
| `waitlist` | → `confirmed` | `stride_bulk_promote_waitlist` | confirm + grant seat (only if edition has open capacity; per-row capacity re-check) |
| `interest` | → `pending` \| `cancelled` | `stride_bulk_approve` (to pending) / `stride_bulk_cancel` | move interest into the approval pipe, or drop |
| `confirmed` | → `cancelled`; quote-workflow side-actions | `stride_bulk_cancel`; `stride_bulk_quote_sent`; `stride_bulk_quote_exported` | cancel = **release seat** (`revokeAccess`) + notify + fire `stride/registration/cancelled`; quote actions set quote `status` (NOT "paid") |
| `confirmed`/`completed` | post-course approval | `stride_bulk_approve_post_course` | wraps single-item `approvePostCourse` → `completeTask(id,'post_approval')` (LD completion + status change) |
| `completed` | → (terminal); message/cert | `stride_bulk_message`; `stride_bulk_generate_doc` | send templated message; generate deliverable doc |
| `cancelled` | → (terminal) | — | terminal; no transitions out (re-enrol is a new registration) |
| *(any state)* | switch the person on a registration | `stride_switch_person` | re-assign `user_id` (a deliberate corrective action; single-item only, NOT a bulk lifecycle op) |

**Smart-action roster (the ~8–10 the brief asks for):**
1. `stride_bulk_approve` — pending/interest → confirmed
2. `stride_bulk_promote_waitlist` — waitlist → confirmed (capacity-gated)
3. `stride_bulk_cancel` — → cancelled (release seat + notify)
4. `stride_bulk_quote_sent` — quote → `Sent` (Verzonden) — **NOT mark-paid**
5. `stride_bulk_quote_exported` — quote → `Exported` (Verwerkt) — **NOT mark-paid**
6. `stride_bulk_approve_post_course` — post-course approval
7. `stride_bulk_message` — send templated notification
8. `stride_bulk_generate_doc` — generate deliverable (certificate/attestation)
9. `stride_switch_person` — corrective re-assign (single-item)

### 2.2 The three action layers

- **(a) Smart actions** — the ~9 above, each a registered handler on the `ntdst/v1/action` registry with **real domain logic**. Each removes one reason to fall back to Excel/export. Do NOT aim for 90% coverage before shipping; each is independently valuable.
- **(b) One generic `stride_bulk_set_field`** — sets a **safe, dumb column** across the selection: `notes`, `tags`, `company_id` **only**. **NEVER** `status`/lifecycle/`completed_at`/`cancelled_at` — those go through smart actions so domain effects (seat release, LD access, notifications) fire. The allowlist is enforced **server-side** (§ Threat model M7), not just hidden in the UI.
- **(c) Export** — the long-tail fallback (§9), field-scoped, deferred to Phase 3.

### 2.3 Bulk execution semantics (the real engineering work — specified explicitly)

**Per-action nonce minting under multi-select.** Each smart action is a distinct `ntdst/v1/action` action name, and the registry verifies `wp_verify_nonce($nonce, $action)` where the **nonce's action string must equal the action name** (`Endpoints.php:343`). Therefore:
- The grid, when a bulk action is chosen, **mints ONE nonce for that action name** via `wp_create_nonce('<action>')` and reuses it for every row in the batch — the registry verifies the *same* `(nonce, action)` pair per call, so one nonce armed for the chosen action covers the N rows. (It does NOT need N distinct nonces; it needs the nonce that matches the chosen action.) The server is handed the nonce on each row dispatch.
- Nonces are surfaced to the client by `wp_create_nonce` printed into the admin bootstrap for the registered action names (Task 1.3), so the Alpine app holds a `{actionName: nonce}` map and arms the correct one when a bulk op starts.
- **Capability is still checked server-side on EACH item** (§ Threat model M2) — the nonce proves request integrity, not authorization.

**Partial-failure model.** Bulk = a server-side loop, one row at a time, **no implicit transaction across rows** (these are domain operations with side effects — LD access grants, emails — that cannot be atomically rolled back). The handler returns a **per-row outcome report**:
```json
{
  "total": 10,
  "succeeded": [ {"id": 101}, {"id": 102}, ... ],
  "failed": [ {"id": 105, "code": "capacity_full", "message": "Editie is vol."} ],
  "summary": { "ok": 9, "error": 1 }
}
```
- **Rollback policy: none across rows; fail-soft per row.** When 8/10 succeed, the 8 stay applied and the 2 failures are reported with their `WP_Error` code/message. This is the correct semantics for side-effectful domain ops (you do NOT un-send an email or un-grant LD access to honour a sibling row's failure). The UI shows "9 geslaagd, 1 mislukt" with the failed rows expandable. **This non-atomicity is a stated design decision, not a gap** (§ Acceptance flows edge "mid-flow failure").
- Each per-row call goes through the **same single-item domain path** the case view uses, so a bulk approve and a single approve are the identical code path (no second code path to drift — `lesson_pure_passthrough_is_drift`).

**Cache invalidation.** Bulk mutations MUST bust the `stride_action_queue` transient. The existing bust hooks (`AdminDashboardService.php:~79`) already fire on `stride/registration/{created,confirmed,cancelled}`, `stride/attendance/marked`, `save_post_vad_quote`. **New events to add to the bust list** (Task 1.4):
- `stride/registration/quote_status_changed` — fired by `stride_bulk_quote_sent` / `stride_bulk_quote_exported` (since these change the offerte-opvolging queue's contents without touching `save_post_vad_quote` if the status is set via the repo).
- `stride/registration/bulk_completed` — a coarse "a bulk op finished, recount" event fired once per bulk batch, so the worklist counts refresh after a batch without N individual busts.

Because the existing smart paths (`confirmRegistration`, cancel) already fire `stride/registration/{confirmed,cancelled}`, bulk-approve and bulk-cancel inherit the bust for free — only the two new event names above need adding.

---

## 3. Read-model / data-flow — the batched-join read-model

The grid's composite read model is assembled via **batched joins**, NOT a materialized projection table and NOT a naïve postmeta-per-row join.

### 3.1 The flow for `GET /admin/registrations`
1. **One indexed query** on `wp_vad_registrations` with WHERE/ORDER/LIMIT built from structured filter params — uses the existing indexes: `idx_status`, `idx_edition_status`, `idx_company`, `idx_user_status` (verified present, `RegistrationTable.php`). Returns the page of rows (structured columns + the FK ids).
2. **Batch-resolve display fields** for that page only (≤ per_page rows, default 50):
   - user names: one `get_users(['include' => $userIds])` or batched usermeta read.
   - edition titles: one batched post read by `edition_id` set.
   - **offerte status (two-step, §0 #5):** `QuoteRepository::findQuoteIdsByRegistrations($regIds)` → reg→quoteId map; then one batched `QuoteRepository` read of those quote IDs' `status` → quoteId→status; compose to reg→offerteStatus.
   - attendance %: one batched `AttendanceRepository` aggregate by `(user_id, edition_id)` for the page (reuse `BatchQueryHelper` pattern, INV-3 justified exception).
   - company names: one batched read by `company_id` set.
3. Compose the page DTO and return it with pagination metadata.

**No `enrollment_data` / questionnaire JSON is read in the grid path** — display-only fields live in the case view, never the grid.

### 3.2 Why batched-join over a projection table (the YAGNI reasoning)
- **Scale is ~4000 rows total.** A page is 50. Step 1 is a single indexed query; steps 2–6 are ≤6 batched reads regardless of page size. Total queries per grid load ≈ 7, constant in page size — well within budget at this corpus.
- A projection table introduces a **staleness class**: every write to registration/quote/attendance must fan out to the projection, and any missed fan-out silently serves stale grid data (Stride already has a documented stale-read class — `gotcha_stale_database_reads`). At 4000 rows there is **no read-performance problem to justify importing that whole failure mode**.
- Group-by aggregates are computed **from structured columns via SQL `GROUP BY`** on the same indexed table (count, completed_at-based % afgerond, attendance %, quote-status distribution) — no projection needed for aggregation either.

**Recommendation: batched-join. No projection table.**

---

## 4. Endpoints to ADD vs REUSE

### 4.1 ADD

| Endpoint / handler | Type | Purpose | Key params / notes |
|---|---|---|---|
| `GET /stride/v1/admin/registrations` | REST route on `AdminAPIController` | The grid read-model (§3) | Filters: `status`, `edition_id`, `company_id`, `trajectory_id`, `offerte_status`, `q` (name search); `sort` (whitelisted columns only); `group_by` (whitelisted: `edition_id`/`status`/`company_id`/`trajectory_id`); `page`, `per_page` (capped). `trajectory_id` filter returns the trajectory's **child edition-rows** via the parent→child join (§11), not the parent. `permission_callback` → `canViewAdmin`. New registration query *shapes* extracted into `RegistrationRepository` (INV-3). |
| `stride_bulk_approve` | `ntdst/v1/action` handler | bulk pending/interest → confirmed | wraps single-item `approveRegistration` path; per-row report (§2.3) |
| `stride_bulk_promote_waitlist` | `ntdst/v1/action` handler | waitlist → confirmed | capacity re-check per row |
| `stride_bulk_cancel` | `ntdst/v1/action` handler | → cancelled | release seat + notify |
| `stride_bulk_quote_sent` | `ntdst/v1/action` handler | quote → `Sent` | NOT paid |
| `stride_bulk_quote_exported` | `ntdst/v1/action` handler | quote → `Exported` | NOT paid |
| `stride_bulk_approve_post_course` | `ntdst/v1/action` handler | post-course approval | wraps single-item `approvePostCourse` |
| `stride_bulk_message` | `ntdst/v1/action` handler | send templated notification | reuses NotificationService |
| `stride_bulk_generate_doc` | `ntdst/v1/action` handler | generate deliverable | reuses exporters/PDF |
| `stride_bulk_set_field` | `ntdst/v1/action` handler | generic safe-column set | **server-side allowlist** notes/tags/company only |
| `GET /admin/users/{id}/trajectories` | REST route on `AdminAPIController` | the case-view trajectory section (Surface 3 / §11) | **the one real gap** — returns per-trajectory progress + chosen electives by wiring the EXISTING `TrajectoryDashboardService::getProgressData` + `TrajectorySelection` (no new compute). `permission_callback` → `canViewAdmin`. *(Alternatively fold into `getUserDetail`; a separate endpoint keeps that payload lean — recommended.)* |
| `GET /admin/trajectories/options` | REST route on `AdminAPIController` | trajectory typeahead for the grid filter + Trajecten-tab search | lightweight `{id, title, status}` (same pattern as `/editions/options`, §10). |
| field-scoped export | extends exporters | deliverable export (Phase 3) | §9 — deferred |

All bulk handlers register via `add_filter('ntdst/api_data/<name>', $cb, 10, 2)` (INV-2 convergence point) and authorize **inside** the handler with `canManageAdmin` semantics on **each row** (INV-1, § Threat model M2).

### 4.2 REUSE (cite each + note the gap)

| Existing endpoint/service | Reused for | Gap (if any) |
|---|---|---|
| `GET /admin/stats` | worklist home top-line counts | none — extend with the 5 queue counts |
| `GET /admin/action-queue` (+`/dismiss`) | "Acties nodig" → promoted to Vandaag | none — already cached + busted |
| `POST /admin/attendance` (`markAttendance`, `:1420`) | cohort roster attendance (Phase 2) | single-item; cohort bulk wraps it |
| `POST /admin/approve-registration` (`approveRegistration`, `:2121`) | bulk-approve per-row body | single-item → **bulk-approve wraps it** in a loop |
| `POST /admin/approve-post-course` (`approvePostCourse`, `:2157`) | bulk-approve-post-course per-row body | single-item → bulk wraps it |
| `GET /admin/users/{id}/detail` | powers the case view's edition/quote/attendance sections (Surface 3) | **edition-only** — returns NO trajectory data (verified); the trajectory section needs the new `/users/{id}/trajectories` (above) |
| `GET /admin/users/{id}/reveal` (audited PII) | case-view PII reveal | none — reuse audited reveal posture |
| `GET /admin/trajectories` + `/admin/trajectories/{id}` | **the Trajecten tab (Surface 4)** — list + detail (courses + enrolled users) | none for the read-only overview; detail lacks per-user progress/electives (that's the case-view job, via `/users/{id}/trajectories`) |
| `TrajectoryDashboardService::getProgressData` + `TrajectorySelection` (`getSelectedCourseIds`/`isGroupChosen`/`countChosenInGroup`) | compute behind `/users/{id}/trajectories` | none — already used by `tab-trajecten.php`/`tab-voortgang.php`; admin endpoint just wires it |
| `RegistrationRepository::findEditionsByTrajectory` / `findByTrajectory` / `countByTrajectoryIds` | grid trajectory filter (parent→child join) + Trajecten-tab counts | none — verified, indexed; the grid filter reuses the join shape |
| `QuoteRepository::findQuoteIdsByRegistrations` (`:121`) | grid offerte-status resolver step (a) | returns quote IDs not status → add step (b) batched status read |
| The 5 Edition exporters | deliverable export (Phase 3) | no field-scoping → Phase 3 adds it |
| `stride_action_queue` bust hooks | bulk cache invalidation | add 2 new event names (§2.3) |

---

## 5. Rewrite-vs-extend recommendation — **Extend the Alpine app**

**Recommendation: extend `strideApp`, do NOT do a React rewrite.** Measured reasoning:

- **The gap is interaction-model, not capability.** Backend + REST + domain actions already exist (23 routes, the `/action` registry, the exporters). The missing pieces are: a grid component, multi-select state, a bulk-action bar, group-by. All are additive to the existing 955-line `admin-dashboard.js`.
- **Endpoint reuse:** the existing 23 endpoints + the `X-WP-Nonce` API helper + the admin menu registration are all reusable as-is. A React rewrite throws that wiring away and re-pays it.
- **Near-launch risk:** the project is in ship-mode (`feedback_ship_mode`). A framework swap on the admin surface weeks from launch is uncalibrated risk for zero capability gain.
- **Honest strain point:** Alpine cannot virtualize a ~4000-row grid client-side. **Mitigation: server-side pagination + filtering** (§3) — the grid never holds more than one page (default 50). This is a hard requirement, not a nice-to-have; "load all rows then filter in Alpine" is explicitly forbidden. With server-side paging, Alpine's reactivity over ≤50 rows + a selection Set is well within its comfort zone.

> A `## Doubt the decision` pass was run on "extend vs rewrite" from fresh context: the only scenario favouring React is "the grid will grow to tens of thousands of rows AND need rich client-side interactions". At 4000 rows with server-side paging, that scenario does not obtain. Extend stands.

---

## 6. Two exports distinguished

- **Kill the worklist export.** The "export registrations to Excel to build a worklist" flow is **replaced** by the in-app worklists (Surface 1) + bulk actions (§2). Remove its entry point from the new workbench UI (the underlying `GET /admin/export/registrations` CSV route may remain for backward compat but is no longer the worklist path).
- **Keep + extend the deliverable export** (Phase 3, §9). The deliverable export = a readable artifact for an **external party** (caterer/venue/partner). Make the exporters **field-scoped**: the export picks which fields go out — caterer = name + dietary; venue = name + book; **nobody external gets the invoice stage**. Reuse the **anonymise/reveal posture at the export boundary** (`EditionFilesZipExporter` already skips `_stride_anonymised_at` users — extend that guard to all field-scoped exports). GDPR/PII hygiene is the point.

---

## 10. Scale & the active/archived edition cutoff

The workbench must stay performant at **200+ editions** and **thousands of registrations**. Grounding (2026-06-13) confirms the data already supports the cutoff — this is a *default + a query scope*, not new infrastructure.

### 10.1 The registrations grid scales already
The grid is **server-side paged** (default 50/page) over the indexed `wp_vad_registrations` table. Per-page cost is one indexed query + ≤6 batched reads (§3), **constant in total corpus size**. Thousands of registrations is well within budget — the grid does not change with scale. The only hard rule is the one already stated (§5): never load the full set client-side.

### 10.2 Where edition count actually bites — two dropdown/count spots, not the grid
At 200+ editions, two surfaces degrade if naïve:
1. **The edition filter/picker** (grid filter + the "Indelen per → Editie" group-by source). A flat list of 200+ editions is both a fat query and an unusable picker.
2. **The Vandaag queue counts** (esp. "Wachtlijst — plaatsen vrij"), if they scan capacity across *all* editions including long-dead ones — slow, and meaningless (nobody chases a closed 2021 edition).

### 10.3 The cutoff is built from EXISTING status/date data (verified)
- **`Domain/OfferingStatus.php`** already defines terminal states `completed` ("Afgelopen") and `archived` ("Gearchiveerd"), alongside `cancelled`. `isTerminal()` covers all three.
- **`EditionService::getEffectiveStatus()` / `isPast()`** already derive "past" from `end_date` (fallback `start_date`) — an edition whose end date has passed reads as `Completed` in effective status even if its stored status lags (INV-7).
- **`getEditions` already soft-defaults** to hiding editions whose start is >2 days ago (`AdminAPIController.php:~786`). So a "recent/active by default" posture is the existing behaviour, not a new idea — this spec only makes it explicit and consistent for the grid + queues.
- **All count methods take an explicit edition-ID set** — `RegistrationRepository::countByEditions($ids,$statuses)`, `statusBreakdownByEditions($ids)`, `BatchQueryHelper::batchGetRegistrationCounts($ids)` (verified). They are `WHERE edition_id IN (…)`, never a full scan. So scoping to active editions is a free win: pre-filter the edition IDs to active, pass that subset in.

### 10.4 The design rule
- **Mirror the existing editions tab — do NOT invent a parallel concept.** The edities tab already IS the date-filtered current-edition browser (verified): text search, a status filter incl. "Afgelopen", a Flatpickr `date_from`/`date_to` range, and the **same 2-days-ago default scope** (`getEditions`, `AdminAPIController.php:~786`). The workspace's active/archived scoping is the *same posture the editions tab already uses* — reuse its mental model and its scoping defaults, don't define a competing one.
- **"Active" = not terminal AND not past:** `OfferingStatus NOT IN (completed, cancelled, archived)` AND `end_date >= today` (via the existing effective-status logic). Read through `getEffectiveStatus()` (INV-7), never raw stored status. (This is the explicit form of the editions tab's 2-day default.)
- **The grid + queue counts default to active editions, and the scope is VISIBLE.** A dismissable filter pill ("Actieve edities") announces the default so an admin never silently wonders why older data is missing; clearing it (or the picker's "Toon ook afgesloten edities") widens to all. **This is a UX affordance, not a privacy control** — see 10.6.
- **The edition picker is a server-side searchable typeahead**, NOT a flat 200-item `<select>`. It returns a lightweight `{id, title, effective_status}` list, paged/filtered server-side, defaulting to active editions with archived ones one search away. This needs a small **new lightweight endpoint** `GET /admin/editions/options?q=&scope=active|all` (id+title only — do NOT reuse the heavy `getEditions` payload, which carries ~15 fields/edition incl. sessions/counts/taxonomies, to fill a picker). **Bonus consolidation:** the existing quotes-filter already fakes a picker via `/admin/editions?per_page=100&view=list` (the heavy payload) — the new lightweight endpoint should replace that call too (`loadQuoteEditions`), removing an existing inefficiency.
- **An archived edition is never lost** — it is always reachable: search it in the picker (`scope=all`), and once selected the grid shows its registrations normally. Archiving changes the *default scope*, not *access*.
- **Registrations themselves are never archived/hidden** — only editions gate the default view. A registration on an archived edition is still found by selecting that edition.

### 10.5 Net effect
At 200+ editions: the picker is a typeahead over a tiny id+title list (scoped + paged), the queue counts run `WHERE edition_id IN (active subset)` instead of a corpus scan, and the grid is unchanged (already paged). No projection table, no new edition lifecycle — just the active-by-default scope (mirroring the editions tab) over data that already distinguishes active from terminal/past. *(This adds Task 1.4a — the `/editions/options` typeahead endpoint, which also cleans up the quotes-filter — and the active-default scope to Task 1.2's query method + the queue-count callers.)*

### 10.6 Visibility & the PII boundary — archived-search is safe by design
Making archived-edition data searchable does **NOT** widen the PII surface, because the active/archived scope is a *convenience filter, not an authorization boundary*:
- Any admin who can open the grid already holds `stride_view`, and can **already** reach an archived edition's registrations today — via the user-detail view, or the editions tab's date/status filters (10.4). Scoping archived editions out of the default view hides *clutter*, not *access* — the data was never protected from this actor.
- Therefore surfacing it via the picker exposes nothing new: it gives an entitled user a faster path to data they already have rights to. The real PII boundary stays exactly where the threat model puts it — `permission_callback` on the read endpoint (M1), per-row capability on mutations (M2/M3), and the field-scoped **export** boundary with the anonymise-skip (M8). The archived-search affordance touches none of those.
- **Consequence for the spec:** the "Actieve edities" pill and "Toon ook afgesloten edities" are pure UX (signal which scope you're in); they are explicitly NOT listed as security controls, and `/editions/options` with `scope=all` is gated only by the same `canViewAdmin` as every other admin read (M1) — no extra capability, because none is warranted.

---

## 11. The trajectory layer (trajecten) — surfacing the second spine

The grid is built around `edition_id`, but `trajectory_id` is an equal first-class FK on the same table and was initially overlooked. This section adds it. **Almost all the machinery already exists** (grounded 2026-06-13) — the work is admin-side wiring, not new domain logic.

### 11.1 The data model (verified — get the parent/child right)
A trajectory enrolment is **not** a flat set of rows:
- **One PARENT row:** `trajectory_id = T`, `edition_id = NULL`, `parent_registration_id = NULL`, `enrollment_path = 'trajectory'`. This is "the person is in trajectory T".
- **N CHILD rows:** one per course edition (mandatory + each chosen elective), `edition_id = E`, `parent_registration_id = <parent.id>`, `trajectory_id = NULL` on cascade children. These are the actual course participations (carry attendance, quote, status).
- `selections` JSON on the **parent** records which electives were picked.
- Cancelling the parent cascades to the children (`cancelChildren`, verified).

**Consequence:** "all registrations for trajectory T" = parent + children. A naïve `WHERE trajectory_id = T` catches only the parent (and legacy pre-cascade rows) and misses the course rows. `RegistrationRepository::findEditionsByTrajectory(userId, trajectoryId)` already does the correct parent→child join (handles both cascade + legacy shapes) — reuse it.

### 11.2 Grid filter (decided: show child edition-rows)
Filtering the grid by trajectory T shows the **child edition-rows** (the course participations), keeping rows edition-grained so attendance %/offerte/status stay meaningful. `trajectory_id` is indexed (`idx_trajectory`, `idx_trajectory_status`) — the filter is as cheap as the edition filter. Implemented in `queryForGrid` by routing the trajectory filter through the parent→child join shape (not a bare column WHERE). Fed by a trajectory typeahead (`/admin/trajectories/options`, mirror of §10's edition picker).

### 11.3 Trajecten tab (Surface 4 — read-only Phase 1, endpoints already exist)
`GET /admin/trajectories` (list: status/capacity/enrolled/courses) and `GET /admin/trajectories/{id}` (detail: courses + enrolled users) are already built. Surface 4 is a frontend tab over them + a "jump to grid filtered by this trajectory" action. Trajectory-roster bulk actions are deferred (Phase 2); the per-registration bulk actions apply once you're in the grid.

### 11.4 Case-view trajectory section (the ONE real backend gap)
`getUserDetail` returns **zero** trajectory data today — this is the only genuine gap. The fix is a new `GET /admin/users/{id}/trajectories` (keeps `getUserDetail` lean) that, for each trajectory the user is in, returns the progress + chosen-vs-required shape the **live user-facing views already render** (`tab-trajecten.php`, `tab-voortgang.php`). It wires existing compute, no new logic:
- `TrajectoryDashboardService::getProgressData(userId, trajectoryId)` → `required_courses`, `elective_groups`, `completed_courses`, `in_progress_courses`, `total_required`, `edition_registrations`.
- `TrajectorySelection::getSelectedCourseIds(registrationId)` / `isGroupChosen()` / `countChosenInGroup()` → what the user **chose** per elective group vs. the group requirement.
- Course state per course: afgerond (LMS adapter `isComplete`), bezig (active edition registration), nog te volgen.
- Read selections via `TrajectorySelection` (**INV-6b**), never the raw `selections` column.

> ⚠️ Memory note: `TrajectorySelection::validateSelections()` has known field-shape bugs (`bug_trajectory_validate_selections_drift`) — but the case view uses the **read** methods above (`getSelectedCourseIds`/`isGroupChosen`/`countChosenInGroup`), which are the same ones the live frontend uses, NOT `validateSelections`. No exposure here; do not "fix" validateSelections as part of this work.

### 11.5 Net effect
Trajectory becomes the grid's second filter axis (cheap, indexed, child-row semantics), gets a read-only overview tab over existing endpoints, and completes the case view via one new wiring endpoint. *(Adds Tasks 1.4b — trajectory filter in `queryForGrid` + `/trajectories/options`; 3.5 — `/users/{id}/trajectories` + the case-view section; 3.6 — the Trajecten tab UI.)*

---

## Golden path: form / AJAX / write-flow (deviations must be named and justified)

- [ ] Built to `netdust-wp:ntdst-patterns → golden-paths/form-data-flow.md` — read before task breakdown. The bulk handlers ARE write-flows over the `ntdst/api_data` filter path, which is the slice's spine.
- [ ] Deviations from the slice (each named + justified):
      - **The read-model endpoint (`GET /admin/registrations`) is a REST route on `AdminAPIController`, not an `ntdst/api_data` filter** — justified: it is an admin-only read consumed by `X-WP-Nonce` fetch, consistent with the other 23 admin routes; the `api_data` path is for the *frontend* public-ish action surface. Authz via `canViewAdmin` (INV-1).
      - **Bulk handlers loop the single-item domain path rather than each being a fresh write** — justified: reuse of the verified single-item path (no second code path), per-row report instead of one all-or-nothing write.
      - **Bulk is non-atomic across rows** — justified: side-effectful domain ops (LD access, email) can't be transactionally rolled back; fail-soft per row is the correct model (§2.3).

## WP security requirements (per data-flow)

> Pillars and exact functions defined in `netdust-wp:wp-security`; lines below name which pillars apply per flow.

- [ ] **REST `GET /admin/registrations`**: permission_callback = `canViewAdmin` (NOT `__return_true`) + **validate/sanitize every param** (`status`/`offerte_status`/`group_by`/`sort` against **server-side whitelists**, never interpolated; `edition_id`/`company_id`/`page`/`per_page` via `absint` + `per_page` capped; `q` via `sanitize_text_field` + bound as `$wpdb->prepare` `%s`) + **no JSON column in any WHERE/GROUP BY** + escape: response is JSON via `WP_REST_Response` (WP encodes) — n/a raw echo.
- [ ] **Each `stride_bulk_*` action**: nonce verified by the framework registry (`wp_verify_nonce($nonce,$action)`, INV-2) + **`canManageAdmin` capability checked inside the handler on EACH row id** + sanitize (`$params` ids via `absint`, each id must resolve to a registration the actor may manage) + `$wpdb->prepare` on any write (via repository) + escape: JSON report, n/a raw echo.
- [ ] **`stride_bulk_set_field`**: same nonce+capability as above + **server-side field allowlist** (reject anything not in `{notes, tags, company_id}` with a 400 — the UI hiding status is NOT the control) + value sanitised per field type.
- [ ] **Field-scoped export (Phase 3)**: capability (`canManageAdmin`) + **field allowlist per recipient profile** + **`_stride_anonymised_at` skip at the boundary** (reuse) + escape on the rendered artifact (`esc_html`/sheet-cell encode).

Each flow accounts for all four pillars; where one is n/a it is stated (`escape: n/a — JSON response`).

## ntdst-core layering requirements

- [ ] Data access goes through a **Repository** — new `vad_registrations` query shapes added to `RegistrationRepository`, not raw `$wpdb` in `AdminAPIController` (INV-3; AdminAPIController is an accepted `$wpdb` zone but *new shapes extract* — the established CR-D3 pattern).
- [ ] **No pure pass-through Service methods** — bulk handlers add the loop + per-row report + capability re-check; they are not 1-line repo proxies.
- [ ] **No raw `wp_ajax_*` handlers** — bulk actions register through the `ntdst/api_data/*` filter layer (INV-2).
- [ ] **No swallowed `WP_Error`** — per-row failures are captured into the report's `failed[]`, never `is_wp_error($x) ? return; : …` with no log/report (INV-4).
- [ ] **No hardcoded meta prefix** — use the CPT `getFields()` / repository, not `_ntdst_*` literals (INV-3).
- [ ] **Correct module layering** — read-model query in `Modules/Enrollment/RegistrationRepository`; bulk handlers in `Handlers/` (thin) delegating to `Modules/*` services; no business logic in the controller.
- [ ] **Output via `getEffectiveStatus`** where a display/gate decision is made on edition status (INV-7) — the grid's edition-status-derived columns and the waitlist "seats open" check read through `EditionService::getEffectiveStatus()`, not raw stored status.

> **Convergence contract:** these blocks (golden path + four pillars + drift categories + the named invariants) are the convergence target for `/code-review` and the `ntdst-drift-reviewer` at shake-out. Reviewers verify the diff against the named items, not free-form — a gap is a one-line finding keyed to a named item, not a re-discovery.

---

## Threat model

> For the Admin Workspace feature, written 2026-06-13 at plan-time (proactive, before task breakdown). The new flat-grid REST read-model + bulk mutations under multi-select + a field-scoped export boundary are security-rich (mass authorization, confused-deputy, PII egress). This section is the convergence target — `/code-review` and `security-sentinel` verify against the numbered mitigations rather than re-discovering the surface each round.

### What we're defending
- **A1 — Cross-registration confidentiality.** Every registrant's name, organisation, edition membership, attendance %, quote-status — now exposed in ONE flat grid (previously only reachable per-edition/per-user). The grid is a new aggregation surface for PII.
- **A2 — Registration lifecycle integrity.** `status` transitions carry real side effects (seat release on cancel, LD `grantAccess`/`revokeAccess`, notification emails, quote workflow). A bad bulk transition is a mass side-effect.
- **A3 — The `enrollment_data` invoice/billing stage** (`enrollment_billing`: VAT, billing address, invoice email) — must never leave to an external party via the deliverable export.
- **A4 — Server resources / the registration table.** A flat queryable endpoint with sort/group params is a new path to expensive queries / SQL injection if params reach SQL un-whitelisted.
- **A5 — Audit integrity** of PII reveal and impersonation (existing, must not be weakened by the new surface).

### Who we're defending against
- **External unauthenticated attacker** — IN scope (must hit `permission_callback` and get nothing).
- **`stride_supervisor` (view-only) user** attempting a mutate/bulk action — IN scope (the confused-deputy / privilege-escalation case the bulk surface introduces).
- **A `stride_coordinator` (manage) user tricked/CSRF'd** into firing a bulk mutation — IN scope (nonce + same-origin).
- **A partner user** (`partner` role, company-scoped) trying to reach the admin grid — IN scope (must be denied; admin grid is NOT company-scoped and partners must never see it).
- **Insider with stolen `stride_coordinator` credentials** — OUT of scope (acknowledged; no defence beyond audit trail).
- **DNS rebinding / network-position attacks** — n/a (no user-controlled outbound URL in this feature).

### Attacks to defend against
1. **Unauthenticated grid read** — the new `GET /admin/registrations` ships with a missing/`__return_true` `permission_callback`, leaking the whole PII corpus.
2. **View-only user performs a bulk mutation** — `stride_supervisor` (view) fires `stride_bulk_cancel`/`stride_bulk_approve`; the handler checks the nonce but not the capability, so a read-only actor mutates lifecycle en masse (confused deputy / vertical privilege escalation).
3. **Per-row authorization skipped under bulk** — capability is checked once for the request but NOT per row, so a crafted payload includes registration IDs outside what the actor should touch (or IDs that don't exist / belong to a context the actor can't manage) and they're all mutated.
4. **SQL injection / expensive-query via grid params** — `sort`/`group_by`/`status`/`offerte_status` are interpolated into the query, or `per_page` is uncapped, enabling injection or a table-scan DoS.
5. **JSON column reaches WHERE/GROUP BY** — a filter/group param is wired to `enrollment_data`/`selections` JSON, both injecting risk and breaking the "structured-only" contract (and enabling slow JSON scans at 4000 rows).
6. **CSRF on bulk mutation** — a state-changing bulk action is fired cross-origin against a logged-in coordinator's session without same-origin/nonce enforcement.
7. **`bulk_set_field` lifecycle bypass** — the UI hides `status` from the safe-column picker, but the server accepts any field name in the payload, so `bulk_set_field` is used to set `status`/`completed_at` directly, skipping every domain side-effect (no seat release, no LD revoke, no notification) — a silent integrity hole.
8. **Invoice-stage PII egress via export** — a field-scoped export to a caterer/venue includes the `enrollment_billing` stage (VAT/address/invoice email), or includes an anonymised user's data, leaking PII to an external party.
9. **Partial-failure state confusion** — a bulk op fails on row 5; the client believes all-or-nothing and re-fires the whole batch, double-applying side effects (double email, double LD grant) on the rows that already succeeded.
10. **Stale-count / queue divergence** — a bulk op mutates rows but does not bust `stride_action_queue`, so the worklist counts and the grid disagree, and an admin re-acts on already-handled rows (a correctness+integrity issue, not pure availability).

### Mitigations required (numbered to match attacks)
1. **M1 — `permission_callback => [$this, 'canViewAdmin']` on `GET /admin/registrations`**, the named INV-1 read convergence method (`current_user_can('stride_view')`). Never `__return_true`, never an inline closure. Verified by the INV-1 audit grep (routes missing `permission_callback`).
2. **M2 — Capability gate inside every `stride_bulk_*` handler**: `if (!current_user_can('stride_manage')) return new WP_Error('forbidden', …, ['status'=>403]);` as the first line, BEFORE the row loop. The registry's nonce check proves integrity; this proves authorization. Read-only actions (`stride_bulk_message` is a mutation → manage; there is no view-only bulk action) all require `stride_manage`.
3. **M3 — Per-row authorization + existence check**: inside the loop, each `id` is `absint`'d and resolved via `RegistrationRepository::find($id)`; a row that doesn't resolve, or that the actor's capability doesn't cover, is pushed to `failed[]` with `forbidden`/`not_found` — it is NOT silently skipped and NOT mutated. The capability model here is global (`stride_manage` covers all registrations), so per-row is existence + valid-state-transition validation; if company-scoping is ever added to admin it slots in here.
4. **M4 — Server-side whitelists for every structured param**: `sort` ∈ a fixed column allowlist; `group_by` ∈ `{edition_id,status,company_id}`; `status` ∈ `RegistrationStatus` cases; `offerte_status` ∈ the four quote statuses. `edition_id`/`company_id`/`page`/`per_page` via `absint`; `per_page` clamped to a max (e.g. 100). `q` bound as a `$wpdb->prepare('%s', …)` LIKE param, never interpolated. No param is ever string-concatenated into SQL. Lives in the `RegistrationRepository` query method (INV-3).
5. **M5 — No JSON column in WHERE/GROUP BY, enforced by construction**: the read-model query method only accepts the structured columns above; `enrollment_data`/`selections`/`completion_tasks` are not parameters and the method has no code path that reaches them. Aggregates use SQL `GROUP BY` on structured columns only. (This is also INV-3 / the §3 data contract.)
6. **M6 — Same-origin + nonce on every bulk mutation**: inherited from the `ntdst/v1/action` registry (Origin/Referer CSRF check + `wp_verify_nonce($nonce,$action)`, `Endpoints.php`). The grid mints `wp_create_nonce('<action>')` for the chosen action (§2.3); a cross-origin POST is rejected by the registry's Origin check before dispatch.
7. **M7 — Server-side field allowlist in `stride_bulk_set_field`**: the handler hard-rejects any field not in `{notes, tags, company_id}` with a 400 — `status`, `completed_at`, `cancelled_at`, any lifecycle field is refused server-side regardless of payload. The UI restriction is cosmetic; THIS is the control. Pinned by a denial test (Task 3.x RED test asserts a `status` payload is rejected).
8. **M8 — Export field-scoping + anonymise skip at the boundary**: the field-scoped exporter (Phase 3) takes a per-recipient field allowlist; `enrollment_billing` / invoice-stage fields are NOT in any external-recipient profile; the export loop reuses the `_stride_anonymised_at` skip (extended from `EditionFilesZipExporter` to all field-scoped exports). PII egress is decided by the allowlist, not the caller.
9. **M9 — Idempotency + explicit per-row report**: the bulk response is the per-row `{succeeded[], failed[]}` report (§2.3), and the client renders "9 ok / 1 mislukt" and does NOT auto-retry the whole batch. Re-firing is a deliberate user action on the failed rows only. Where a smart action is naturally idempotent (approve an already-confirmed row), the single-item path returns a benign no-op rather than double-applying (the existing `confirmRegistration` already guards confirmed→confirmed).
10. **M10 — Cache bust on bulk completion**: every bulk handler fires the existing `stride/registration/{confirmed,cancelled}` events per row (inherited) PLUS the new `stride/registration/bulk_completed` once per batch and `stride/registration/quote_status_changed` for quote actions (§2.3, Task 1.4) — all added to the `stride_action_queue` bust list so counts and grid reconverge immediately.

### Out of scope (explicit deferrals)
- **Company-scoping of the admin grid** — the admin grid is intentionally cross-company (that's its purpose); per-row company scoping is a Partner-API concern, not this feature. M3 leaves the hook for it but does not implement it.
- **Insider with valid `stride_coordinator` credentials** — no defence beyond the existing audit trail; accepted residual risk.
- **Rate-limiting the grid read** beyond WP's defaults — the `/action` registry rate-limits the *bulk mutations* (per-action), but the read endpoint relies on capability + pagination; a coordinator hammering the read is an insider concern (above).
- **Full per-row audit log of every bulk mutation** — the existing per-action audit (reveal/impersonate) is unchanged; a dedicated bulk-mutation audit trail is a Phase 3 enhancement, not v1.
- **Field-scoped export PII review workflow** (a human approving each external export) — deferred; v1 of the export relies on the allowlist + anonymise skip.
- **Active/archived edition scope is NOT a security control** (§10.6) — it is a convenience filter. An admin with `stride_view` can already reach archived-edition registrations today (user-detail view, editions-tab date/status filters), so making them searchable in the workspace exposes nothing new. `/editions/options?scope=all` and the "Toon ook afgesloten edities" affordance are gated only by `canViewAdmin` (M1); reviewers should NOT flag the lack of an extra capability on archived search as a finding — it is a deliberate, justified non-control.

### How to use this section
- **Controller pre-flight:** verify M1–M10 are present in the plan-supplied tasks before dispatching the security-boundary cluster.
- **`/code-review` invocations:** "Verify code against the threat model. Check each numbered mitigation M1–M10; report in-place / missing / out-of-scope per the deferrals." Point `security-sentinel` at `references/security-checklist.md`.
- **`/evaluate` retros:** list any unimplemented mitigation as a plan-correction defect.
- **Downstream phases (2 & 3):** cross-reference, don't re-litigate. Phase 3 export EXTENDS M8; the cohort lens (Phase 2) inherits M1–M7 for its roster bulk actions.

---

## Architecture invariants touched

Cited per `netdust-agent:architecture-invariants` against the project's `ARCHITECTURE-INVARIANTS.md` (read, not guessed):

| Invariant | How this feature touches it | Obligation |
|---|---|---|
| **INV-1 — Authorization at the entry point, by capability** | New REST route + 9 bulk actions | `GET /admin/registrations` → `canViewAdmin`; every `stride_bulk_*` → `canManageAdmin` checked inside the handler per row (M1–M3). No new caps invented — reuse `stride_view`/`stride_manage`. |
| **INV-2 — Frontend AJAX nonce verified once by the framework** | Bulk actions register on `ntdst/api_data/*` | Handlers do NOT re-verify the nonce (already done at `Endpoints.php:343`); they MUST NOT be reachable by a raw `wp_ajax_*` path. |
| **INV-3 — Domain data through the per-domain Repository** | New `vad_registrations` read-model query shapes + quote/attendance batch reads | New query shapes extracted into `RegistrationRepository` (the established CR-D3 pattern), not raw `$wpdb` left in the controller. Quote resolver via `QuoteRepository`; attendance via `BatchQueryHelper` (justified exception). Data-API vocabulary + no hardcoded `_ntdst_*`. |
| **INV-4 — Errors are `WP_Error`, logged/bubbled** | Per-row bulk failures | Each failure is a `WP_Error` captured into `failed[]` — never swallowed (`is_wp_error ? return;`). |
| **INV-6b — Trajectory selection via `TrajectorySelection`** | Case view renders selected sessions/courses | Read via `getSelectedCourseIds()` / `isGroupChosen()`, never the raw `selections` column. |
| **INV-7 — Display status via `getEffectiveStatus()`** | Grid edition-status-derived columns + waitlist "seats open" check | Read through `EditionService::getEffectiveStatus()`, not raw stored status. |

**No new convergence point needs authoring** for Phase 1 — the existing invariants cover the surface. **Note for Phase 3:** if a dedicated *bulk-mutation audit trail* is built, a new INV (audit convergence) should be authored at that time.

---

## Acceptance flows

> Per `netdust-agent:feature-acceptance` (situation A — authored at plan-time). One row per intended-use flow; the **Edges** column is mandatory and enumerates the six edge classes (empty/zero, denied actor, wrong-order/re-entry, concurrent/double, boundary, mid-flow failure). Verified at shake-out by driving each through the real browser (Playwright/Chrome against the running admin) AND the API layer.

| # | Intended-use flow | Happy path | Edges (all six — mandatory) |
|---|---|---|---|
| **F1** | Open a worklist queue → grid pre-filtered | Click "Wacht op goedkeuring" on Vandaag → grid loads filtered `status=pending`, bulk-approve armed | **empty:** queue has 0 rows → "Geen inschrijvingen wachten op goedkeuring" empty state, no bulk bar. **denied:** `stride_supervisor` opens Vandaag → sees counts (view) but bulk bar absent/disabled. **re-entry:** queue clicked twice → idempotent, no duplicate filter stacking. **concurrent:** another admin approves a row while the queue is open → count refreshes on next load (M10). **boundary:** queue with exactly `per_page`+1 rows → pagination control appears. **mid-flow:** stats endpoint errors → queue shows "kon niet laden", grid still openable. |
| **F2** | Multi-select N rows → bulk approve with 1 failure → partial-success report | Select 10 pending rows → "Goedkeuren" → 9 confirmed, 1 fails (capacity) → report "9 geslaagd, 1 mislukt" with the failed row expandable | **empty:** 0 selected → bulk button disabled. **denied:** view-only user payload reaches `stride_bulk_approve` → 403 before loop (M2). **wrong-order:** a selected row is already `confirmed` → benign no-op in report, not double-grant (M9). **concurrent:** two admins bulk-approve overlapping selections → each row's single-item path is idempotent; no double LD grant. **boundary:** select-all across all pages → confirm the selection model (select-all = a filter, not 4000 client rows — see boundary note below). **mid-flow:** handler throws on row 5 of 10 → rows 1–4 stay applied, 5 reported failed, 6–10 still processed (non-atomic, M9). |
| **F3** | Group-by edition → collapsed aggregates | Grid → group-by "Editie" → rows collapse into per-edition groups showing count / % afgerond / aanwezigheid % / offerte-verdeling | **empty:** an edition group with 0 matching rows after a co-filter → group omitted. **denied:** view-only user can group (read) but bulk-on-group disabled. **wrong-order:** group-by then change filter → aggregates recompute server-side, not stale. **concurrent:** a row mutates while grouped → next load reflects it. **boundary:** group-by on a column with 1 distinct value → single group. **mid-flow:** aggregate query errors → "kon niet groeperen", flat list remains. |
| **F4** | Filter by status + company | Grid → status=`confirmed` + company=X → indexed query returns matching page | **empty:** no rows match → empty state. **denied:** unauth → endpoint 401/403 (M1). **wrong-order:** invalid `status` value in URL → server rejects to whitelist (M4), not 500. **concurrent:** n/a (read). **boundary:** `per_page` over the cap → clamped (M4). **mid-flow:** a malformed `sort` param → rejected to whitelist, default sort used. |
| **F5** | Open a row → case view, all stages rendered | Click a row → Dossier opens (via `users/{id}/detail`) → registration + all `enrollment_data` stages (3-key shape) + quote offerte-status + attendance + selections rendered (+ the **trajectory section** when applicable — its own flow is F8) | **empty:** a registration with no stages submitted yet → "Nog geen gegevens ingediend" per missing stage, no crash. **denied:** view-only user opens case view (read OK) but action buttons hidden. **wrong-order:** open a `cancelled` registration → terminal, no transition buttons shown (§2.1). **concurrent:** the registration mutates elsewhere while open → reflected on reopen. **boundary:** a person with many registrations → person-headed list, expand-one. **mid-flow:** `users/{id}/detail` errors → "kon dossier niet laden", grid intact. |
| **F6** *(M7 guard)* | `bulk_set_field` on a safe column | Select rows → set `tags` → applied | **empty:** 0 selected → disabled. **denied:** view-only → 403 (M2). **wrong-order:** payload smuggles `field=status` → **400 rejected server-side** (M7 — this is the load-bearing denial test). **concurrent:** two admins set `tags` on overlapping rows → last-write-wins on the dumb column, no domain effect. **boundary:** set field on select-all → batched. **mid-flow:** one row's write fails → per-row report. |
| **F7** *(trajectory)* | Trajecten tab → detail → jump to grid filtered by trajectory | Open Trajecten tab → pick a trajectory → see its courses + enrolled users → "Toon inschrijvingen" → grid filtered to that trajectory's **child edition-rows** (§11.2) | **empty:** a trajectory with 0 enrollments → "Nog geen inschrijvingen" in detail; jump-to-grid shows empty grid with the Traject pill set. **denied:** unauth → `/admin/trajectories` 401/403 (M1). **wrong-order:** jump to grid for a trajectory then clear the pill → grid widens back to all. **concurrent:** an enrollment changes while the detail is open → reflected on reopen. **boundary:** a trajectory with many child editions → grid pages them (server-side, §11.2). **mid-flow:** `/admin/trajectories/{id}` errors → "kon traject niet laden", tab list intact. **leak-check:** the filtered grid shows ONLY this trajectory's child rows, never another trajectory's (the join-correctness assertion, A2/Task 1.4b). |
| **F8** *(trajectory case-view)* | Open the Dossier of a trajectory-enrolled user → trajectory section | Click a trajectory user's row → Dossier → trajectory section shows progress (afgerond/bezig/te doen) + required courses with state + elective groups with chosen-vs-required | **empty:** user in NO trajectory → trajectory section absent (not an error); user in a trajectory with no electives chosen yet → "Nog geen keuzes gemaakt". **denied:** unauth → `/users/{id}/trajectories` 401/403 (M1). **wrong-order:** open a user whose trajectory is cancelled → shows the cancelled trajectory state, no crash. **concurrent:** a course completes while open → reflected on reopen. **boundary:** a user in multiple trajectories → each rendered as its own block. **mid-flow:** `/users/{id}/trajectories` errors → "kon traject-voortgang niet laden", rest of the Dossier intact. |

**Boundary note (F2/F3 select-all across pages):** "select all" is modelled as **a server-side filter selection**, not 4000 materialised client rows — the bulk request carries the *filter*, and the server expands it to ids inside a capped batch (with an explicit max-batch guard and a "dit raakt N inschrijvingen — bevestig" confirm). This keeps the §5 "never load 4k rows client-side" contract intact even for select-all. *(This boundary is itself a Phase-1 task — Task 4.4.)*

---

## Phase boundaries — FIRST SLICE is Phase 1 (person lens)

| Phase | Scope | In first slice? |
|---|---|---|
| **Phase 1 — Person workbench + trajectory layer (FIRST SLICE)** | Worklist home (Vandaag, 5 queues) + Inschrijvingen grid (multi-select, bulk-bar top-3 + overflow, group-by, server-side paging, **edition + trajectory filters**) + one Dossier case view (**incl. the trajectory progress section**) + the **Trajecten tab** (read-only overview + jump-to-grid) + the read-model endpoint + the ~9 bulk smart actions + `bulk_set_field` + the two lightweight picker endpoints (`/editions/options`, `/trajectories/options`) + `/users/{id}/trajectories` | **YES** |
| **Phase 2 — Cohort lens** | Editie → sessie → per-session roster, attendance marking per session, roster extras columns/badges (per-edition loaded set), roster bulk actions, **trajectory-roster bulk actions**, existing exporters surfaced here | No — deferred, described fully in §1 |
| **Phase 3 — Field-scoped deliverable export** | Extend the 5 exporters with per-recipient field allowlists, invoice-stage exclusion, anonymise-skip at the boundary; optional bulk-mutation audit trail | No — deferred, described in §6/§9 |

**Do NOT over-scope Phase 1.** The cohort roster, trajectory-*roster bulk actions*, and field-scoped export are explicitly later phases. Phase 1's trajectory work is **read/filter only** — the layer is *surfaced* (filter + tab + case-view section), not made bulk-actionable on the trajectory roster itself.

---

## Task breakdown — Phase 1 (FIRST SLICE)

> Tier tags per `netdust-agent:testing-workflow`: **Tier A** carries a one-line test contract (the RED-first assertion, incl. denial path for guards); **Tier B** (glue/wrapper/presentational/config) carries `no unit test: Tier B, <reason>`. Every phase has an Integration gate line.

### ── REVIEW GATE A ── (tier: FULL — cluster builds the new REST read-model + repository query shape; a 1a/M4/M5 surface + the INV-3 data layer)

#### Task 1.1: RegistrationStatus transition map (PHP single source)
**Files:** Create `web/app/mu-plugins/stride-core/Modules/Enrollment/RegistrationTransitions.php`; Test `tests/Unit/Modules/Enrollment/RegistrationTransitionsTest.php`
- Tier A. **Test contract:** asserts `RegistrationTransitions::validFor(RegistrationStatus::Confirmed)` includes `Cancelled` and EXCLUDES `Pending`; asserts `Cancelled` is terminal (empty transition set); asserts an invalid transition (`Cancelled → Confirmed`) is rejected by `isAllowed()`.
- [ ] Write the failing test; run it (FAIL: class not found); implement the map (mirror of §2.1); run (PASS); commit.

#### Task 1.2: RegistrationRepository read-model query (the batched-join core)
**Files:** Modify `web/app/mu-plugins/stride-core/Modules/Enrollment/RegistrationRepository.php`; Test `tests/Integration/RegistrationGridQueryTest.php`
- Tier A. **Test contract:** asserts the new `queryForGrid(array $filters): array` returns only rows matching a structured `status`+`company_id` filter, respects `per_page`/`page`, and that an **out-of-whitelist `sort`/`group_by` value is rejected** (M4) and **no param path reaches `enrollment_data`** (M5 — assert the method signature/whitelist, drive a JSON-ish param and confirm it's ignored/rejected); **asserts the active-edition scope default** (§10.4) — with `edition_scope=active` (default), rows on a terminal/past edition are excluded; with `edition_scope=all` or an explicit `edition_id`, they are included. Uses the integration DB (real indexes).
- [ ] Write failing test; run (FAIL); implement `queryForGrid` with server-side whitelists + `$wpdb->prepare` + the active-edition scope (active = `getEffectiveStatus` not terminal AND `end_date >= today`, default on; bypassed by explicit `edition_id` or `scope=all`); run (PASS); commit.

#### Task 1.3: `GET /admin/registrations` REST route (read-model endpoint)
**Files:** Modify `web/app/mu-plugins/stride-core/Admin/AdminAPIController.php` (register route + `getRegistrations()` callback); Test `tests/Integration/AdminRegistrationsEndpointTest.php`
- Tier A. **Test contract:** asserts the route returns the composed page DTO (name/edition/status/offerte-status/attendance%/company) for a `canViewAdmin` user; **asserts an unauthenticated request is denied (M1)**; asserts the offerte-status column is the two-step resolved value (§0 #5), NOT a "paid" flag.
- [ ] Write failing test; run (FAIL); register route with `permission_callback => [$this,'canViewAdmin']`, implement `getRegistrations()` calling `queryForGrid` + the batch resolvers (§3.1); run (PASS); commit.
- **Acceptance:** drift pre-check clean — `/drift-reviewer` on the touched paths returns no findings; the per-flow security line (M1/M4/M5) satisfied in the diff.

#### Task 1.4a: `GET /admin/editions/options` — lightweight searchable edition picker (scale, §10)
**Files:** Modify `web/app/mu-plugins/stride-core/Admin/AdminAPIController.php` (register route + `getEditionOptions()`); Test `tests/Integration/AdminEditionOptionsEndpointTest.php`
- Tier A. **Test contract:** asserts the route returns a lightweight `{id, title, effective_status}` list (NOT the heavy `getEditions` payload) for a `canViewAdmin` user; **asserts `scope=active` (default) excludes terminal/past editions and `scope=all` includes them** (§10.4); asserts `q` does a server-side title search bound via `$wpdb->prepare`; asserts the result is capped/paged (no 200-row dump). Denies unauthenticated (M1).
- [ ] Write failing test; run (FAIL); implement `getEditionOptions()` reading effective status (INV-7), `scope` default `active` (mirroring the editions tab's 2-day default, §10.4), `q` typeahead, capped page; run (PASS); commit.
- [ ] Repoint the existing quotes-filter `loadQuoteEditions()` (which today calls the heavy `/admin/editions?per_page=100&view=list`) at this lightweight endpoint — a free cleanup of an existing inefficiency (§10.4 bonus). *(Light follow-on; keep in the same commit or a trailing one.)*
- Rationale: powers the grid's edition filter + group-by source + the Vandaag queue scoping as a typeahead, so a 200+-edition corpus never fills a flat `<select>` and an archived edition is one search away (§10.2–10.4). Gated by `canViewAdmin` only — `scope=all` warrants no extra capability (§10.6).

#### Task 1.4b: Trajectory filter in `queryForGrid` (parent→child join) + `GET /admin/trajectories/options` (§11)
**Files:** Modify `RegistrationRepository.php` (trajectory filter via the `findEditionsByTrajectory` join shape) + `AdminAPIController.php` (`getTrajectoryOptions()` route); Test extends `RegistrationGridQueryTest` + `tests/Integration/AdminTrajectoryOptionsEndpointTest.php`
- Tier A. **Test contract:** asserts `queryForGrid(['trajectory_id'=>T])` returns the trajectory's **child edition-rows** (the course participations), NOT the parent row, and NOT rows of other trajectories — i.e. the parent→child join is correct and does not leak (§11.2); asserts `/trajectories/options` returns a lightweight `{id,title,status}` list for `canViewAdmin`, denies anon (M1). **The "does not leak other trajectories' rows" assertion is load-bearing** — a botched join is both a correctness and a confidentiality bug (threat-model A1).
- [ ] Write failing test; run (FAIL); implement the trajectory filter routing through the verified join + the options endpoint; run (PASS); commit.

> **Cluster A is now 5 tasks (1.1–1.4b) — over the ~4 cap (1f).** Split into **A1** (1.1, 1.2, 1.3 — the core read-model + endpoint) and **A2** (1.4a, 1.4b — the two lightweight picker endpoints + the trajectory join). Both remain **TIER FULL** (A1: M4/M5 + INV-3; A2: the trajectory-join confidentiality assertion + param whitelists). Run the integration gate + review at each sub-cluster.

**Integration gate (cluster A1):** `ddev exec vendor/bin/phpunit -c phpunit-integration.xml.dist --filter 'RegistrationGrid|AdminRegistrationsEndpoint'` green; manual `curl -u coordinator … /admin/registrations?status=confirmed&group_by=edition_id` returns aggregates; `curl` as anon → 401/403.

**Integration gate (cluster A2):** `--filter 'EditionOptions|TrajectoryOptions|RegistrationGridQuery'` green; manual `curl … /admin/registrations?trajectory_id=<T>` returns that trajectory's child edition-rows only; `/editions/options?scope=active` and `/trajectories/options` return lightweight lists.

### ── REVIEW GATE B ── (tier: FULL — cluster builds the bulk-mutation handlers under multi-select; the M2/M3/M6/M7 confused-deputy + per-row-authz security boundary)

#### Task 2.1: Bulk-approve + bulk-cancel handlers (wrap single-item paths)
**Files:** Create `web/app/mu-plugins/stride-core/Handlers/BulkRegistrationHandler.php`; register `ntdst/api_data/stride_bulk_approve` + `stride_bulk_cancel`; Test `tests/Unit/Handlers/BulkRegistrationHandlerTest.php`
- Tier A. **Test contract:** asserts bulk-approve loops the single-item approve path and returns a per-row `{succeeded[], failed[]}` report; **asserts a view-only actor (no `stride_manage`) gets 403 BEFORE the loop (M2, the denial path)**; asserts a non-existent row id lands in `failed[]` not mutated (M3); asserts a `confirmed`-already row is a benign no-op (M9).
- [ ] Write failing test; run (FAIL); implement handler with capability gate first, then per-row loop delegating to `EnrollmentService`/`EnrollmentCompletion`; run (PASS); commit.

#### Task 2.2: Quote-workflow + waitlist + message bulk handlers
**Files:** Modify `BulkRegistrationHandler.php` (add `stride_bulk_quote_sent`, `stride_bulk_quote_exported`, `stride_bulk_promote_waitlist`, `stride_bulk_message`, `stride_bulk_approve_post_course`, `stride_bulk_generate_doc`); Test extends 2.1's file
- Tier A. **Test contract:** asserts `stride_bulk_quote_exported` sets quote `status=Exported` via `QuoteRepository` and does NOT touch any "paid" field (none exists); asserts `stride_bulk_promote_waitlist` skips a row whose edition is full (per-row capacity re-check) into `failed[]`.
- [ ] Write failing test; run (FAIL); implement; run (PASS); commit.

#### Task 2.3: `stride_bulk_set_field` with server-side allowlist
**Files:** Modify `BulkRegistrationHandler.php`; Test extends 2.1's file
- Tier A. **Test contract (load-bearing M7 denial):** asserts setting `notes`/`tags`/`company_id` succeeds; **asserts a payload with `field=status` (or `completed_at`) is rejected 400 server-side regardless of UI** — this is the integrity guard, must be RED-first.
- [ ] Write failing test; run (FAIL); implement allowlist check before any write; run (PASS); commit.

#### Task 2.4: Cache-bust events for bulk
**Files:** Modify `web/app/mu-plugins/stride-core/Admin/AdminDashboardService.php` (~line 79 bust list); Modify `BulkRegistrationHandler.php` to fire the new events; Test `tests/Integration/BulkCacheBustTest.php`
- Tier A. **Test contract:** asserts firing a bulk batch busts `stride_action_queue` (the new `stride/registration/bulk_completed` + `quote_status_changed` events are in the bust list, M10).
- [ ] Write failing test; run (FAIL); add the two event names to the bust hookup + fire them; run (PASS); commit.

**Integration gate (cluster B):** full bulk suite green; manual: as coordinator POST `ntdst/v1/action` `stride_bulk_approve` with 3 ids (1 invalid) → report shows 2 ok / 1 failed; as supervisor → 403; POST `stride_bulk_set_field` `field=status` → 400.

### ── REVIEW GATE C ── (tier: STANDARD — Alpine grid + worklist UI + case view; multi-file UI behavior, no 1a surface beyond consuming the gated endpoints)

#### Task 3.1: Grid component in `strideApp` (server-side paged)
**Files:** Modify `web/app/mu-plugins/stride-core/assets/js/admin-dashboard.js` (add `registrationsGrid` state + fetch); Modify the admin template to add the Inschrijvingen tab
- Tier B. `no unit test: Tier B, presentational Alpine wiring over a tested endpoint — covered by the F2/F4 acceptance pass`.
- [ ] Add grid state (rows = one page, never all), the `X-WP-Nonce` fetch to `/admin/registrations`, filter/sort/group controls bound to server params; commit.

#### Task 3.2: Multi-select + bulk-action bar + nonce arming
**Files:** Modify `admin-dashboard.js` (selection `Set`, bulk-bar, per-action nonce map from bootstrap)
- Tier B. `no unit test: Tier B, UI state (selection Set + bulk bar) — behavior proven by F2/F6 acceptance`. *(The nonce-arming logic per §2.3 is wired here; its server-side enforcement is already tested in 2.1–2.3.)*
- [ ] Implement selection model, top-3 bulk buttons + overflow, arm `wp_create_nonce('<action>')` per chosen action, render the per-row partial-failure report; commit.

#### Task 3.3: Vandaag worklist home (5 queues) + group-by rendering
**Files:** Modify `admin-dashboard.js` + admin template (Vandaag tab with the 5 queues from §1, live counts from extended `/admin/stats`)
- Tier B. `no unit test: Tier B, presentational — queue definitions are server-side; F1/F3 acceptance covers behavior`.
- [ ] Implement the 5 queue cards → click opens grid pre-filtered with the armed bulk action; render group-by collapsed aggregates; commit.

#### Task 3.4: Dossier case view (person → registration, all stages)
**Files:** Modify `admin-dashboard.js` + admin template (case-view slide-over consuming `users/{id}/detail`)
- Tier B. `no unit test: Tier B, presentational join-renderer over a tested endpoint — F5 acceptance drives all-stages rendering`.
- [ ] Render person-headed registrations; status field labelled **"Inschrijvingsstatus"** with the pending two-substate hint (§2.4); enrollment_data stages as **collapsible panels — empty stages hidden, with-data stages closed-then-open-on-click, clean label→value rendering** (§ Surface 3); `intake`→"Intakevragenlijst" / `evaluation`→"Evaluatie (na afloop)"; **NO separate Vragenlijst block** (answers live under intake once); offerte-status (not paid); selections via the detail payload (INV-6b on the server side); state-appropriate buttons styled as real buttons from the §2.1 map (terminal → muted "geen acties"); history timeline; commit.

#### Task 3.4a: Surface session-selection events in the admin timeline (named gap, § Surface 3)
**Files:** Modify `web/app/mu-plugins/stride-core/Admin/AdminActivityMapper.php` (add `session.selections_updated` to `KNOWN_ACTIONS` + a Dutch label/mapping); Test `tests/Unit/Admin/AdminActivityMapperTest.php`
- Tier A. **Test contract:** asserts an audit entry with action `session.selections_updated` maps to a rendered timeline item with a Dutch label (e.g. "Sessies gekozen: …") and the correct actor/timestamp — currently it is dropped because the action is absent from `KNOWN_ACTIONS`.
- [ ] Write failing test (asserts the event currently maps to nothing); run (FAIL); add the action + label mapping; run (PASS); commit.
- Rationale: session selection IS recorded (`session.selections_updated` + the `initial_selection.phases[]` trail) but not shown; this one-line gap is what makes the timeline genuinely "show everything" as the dossier promises.

**Integration gate (cluster C1 — grid/worklist/case-view UI):** Playwright/Chrome acceptance pass F1–F6 against the running admin (per `feature-acceptance` situation B) — emit the pass/fail/not-reachable manifest. No UI flow is "pass" without the browser driving it. Confirm the dossier hides ≥1 empty stage, opens a stage on click, shows no duplicate Vragenlijst block, and the timeline renders a session-selection + an attendance event.

### ── REVIEW GATE C2 ── (tier: STANDARD — trajectory case-view endpoint + Trajecten tab; a new read endpoint + UI over existing endpoints. NOTE: the trajectory grid filter join lives in cluster A2, already FULL-reviewed.)

> Cluster C split (1f): C1 = the edition-centric UI (3.1–3.4a, STANDARD); C2 = the trajectory surfaces (3.5, 3.6, STANDARD). Keeps each review unit ≤4 tasks and isolates the trajectory layer.

#### Task 3.5: `GET /admin/users/{id}/trajectories` + the case-view trajectory section (§11.4)
**Files:** Modify `AdminAPIController.php` (new route + `getUserTrajectories()` wiring `TrajectoryDashboardService::getProgressData` + `TrajectorySelection`); Modify `admin-dashboard.js` + template (render the trajectory section in the Dossier); Test `tests/Integration/AdminUserTrajectoriesEndpointTest.php`
- Tier A (the endpoint; the UI render is Tier-B presentational). **Test contract:** for a user enrolled in a trajectory, asserts the endpoint returns per-trajectory `progress` (completed/in_progress/total_required), `required_courses`, `elective_groups` with **chosen-vs-required** (via `getSelectedCourseIds`/`isGroupChosen`), reading through `TrajectorySelection` (INV-6b) — **NOT** `validateSelections` (the buggy method, §11.4); **denies unauthenticated (M1)**; asserts a user in NO trajectory returns an empty list (not an error). Mirrors the shape `tab-voortgang.php` already renders.
- [ ] Write failing test; run (FAIL); implement `getUserTrajectories()` wiring the EXISTING compute (no new logic), `permission_callback => canViewAdmin`; render the Dossier trajectory section (progress + courses + chosen electives); run (PASS); commit.

#### Task 3.6: Trajecten tab (Surface 4 — read-only overview over existing endpoints)
**Files:** Modify `admin-dashboard.js` (trajectories list/detail state + fetch) + admin template (the Trajecten tab)
- Tier B. `no unit test: Tier B, presentational tab over the already-tested /admin/trajectories(+/{id}) endpoints — F7 acceptance covers the flow`.
- [ ] Add the Trajecten tab: list (status/capacity/enrolled/courseCount, search + active-default scope), detail slide-over (courses + enrolled users), and "Toon inschrijvingen" → opens the grid filtered by `trajectory_id` (child-row semantics, §11.2); commit.

**Integration gate (cluster C2):** acceptance pass F7 (trajectory tab → detail → jump-to-grid) + the case-view trajectory section drives for a trajectory-enrolled user; manual `curl … /admin/users/<id>/trajectories` returns progress + chosen electives for an enrolled user, `[]` for a non-enrolled one, 401/403 anon.

### ── REVIEW GATE D ── (tier: STANDARD — select-all boundary model + worklist-export removal; behavior change, no new 1a surface)

#### Task 4.1: Select-all-across-pages as a server-side filter selection
**Files:** Modify `admin-dashboard.js` (select-all = carry the filter, not 4k rows) + `BulkRegistrationHandler.php` (expand filter→ids inside a capped batch with a max guard) + Test `tests/Integration/BulkSelectAllTest.php`
- Tier A. **Test contract:** asserts a bulk op with `select_all + filter` expands server-side to the filtered set within the batch cap, and that exceeding the cap returns a clear "te veel rijen" error rather than silently truncating (the F2/F3 boundary).
- [ ] Write failing test; run (FAIL); implement filter-carry + cap guard + the "dit raakt N inschrijvingen" confirm on the client; run (PASS); commit.

#### Task 4.2: Remove the worklist-export entry point
**Files:** Modify the admin template (remove the "exporteer inschrijvingen voor worklist" button); leave the CSV route for back-compat
- Tier B. `no unit test: Tier B, UI removal — the replacement worklists are covered by F1`.
- [ ] Remove the entry point; add a one-line note that worklists replace it; commit.

**Integration gate (cluster D):** select-all boundary test green; manual confirm the export-for-worklist button is gone and the 5 queues cover its use cases.

---

## Sibling-site audit

Cross-cutting concerns to sweep when the relevant code is touched:

- **`## Sibling-site audit` — the offerte-status proxy.** The predicate "confirmed AND quote not Exported = needs follow-up" now lives in: the Vandaag "Offerte-opvolging" queue, the grid offerte column, the grid group-by aggregate, AND `AnnualReportService::quoteAggregates()` (the precedent). When the proxy definition changes, all four must change together. Do NOT let a second definition of "paid-proxy" drift in.
- **`## Sibling-site audit` — the RegistrationStatus transition map.** `RegistrationTransitions` (Task 1.1) is the ONE source of valid transitions. The grid bulk-bar, the case-view buttons, and (Phase 2) the cohort roster all derive their buttons from it. A view that hard-codes its own button set is a drift — sweep all action-rendering surfaces against the map.
- **`## Sibling-site audit` — the structured-only contract.** Any new grid filter/sort/group param must be checked against the M4/M5 whitelist. A reviewer adding a "filter by dietary need" must be stopped: that's `enrollment_data` JSON → it belongs in the per-edition cohort roster (Phase 2, loaded-set), never the global grid.
- **`## Sibling-site audit` — the trajectory parent/child join.** "Registrations for trajectory T = parent + children" lives in: the grid trajectory filter (Task 1.4b), the Trajecten-tab jump-to-grid (Task 3.6), and (if reused) any count. They MUST all route through `findEditionsByTrajectory` / the verified join — a bare `WHERE trajectory_id = T` anywhere is a bug (misses child rows) AND a leak risk if it crosses trajectories. Sweep every trajectory-scoped query against the join shape (§11.1).
- **`## Sibling-site audit` — trajectory selection READ vs validate.** The case view + any trajectory progress read MUST use `TrajectorySelection::getSelectedCourseIds`/`isGroupChosen`/`countChosenInGroup` (the methods the live frontend uses), NEVER `validateSelections` (known-buggy, `bug_trajectory_validate_selections_drift`). If a reviewer sees `validateSelections` in admin-workspace code, that's a finding.

---

## Self-review (run against the brief)

- **Spec coverage:** IA — 3 surfaces + Trajecten tab + cohort lens (§1) ✓; action model + state→transitions table (§2) ✓; read-model/data-flow (§3) ✓; endpoints add-vs-reuse (§4) ✓; rewrite-vs-extend (§5) ✓; two exports (§6) ✓; scale/archived editions (§10) ✓; **trajectory layer — grid filter + Trajecten tab + case-view section (§11)** ✓; bulk execution semantics — partial-failure + per-action nonce + cache invalidation (§2.3) ✓; the four brief corrections + the 5th refinement (§0) ✓.
- **Gates fired + embedded inline:** Threat model (M1–M10) ✓; Architecture invariants (INV-1/2/3/4/6b/7) ✓; Acceptance flows (F1–**F8**, six edges each — F7/F8 = trajectory) ✓; WP plan-requirements (golden path + four pillars + drift categories) ✓.
- **First-slice boundary:** Phase 1 = person lens + trajectory **read/filter** layer; cohort roster + trajectory-*roster bulk actions* (Phase 2) + field-scoped export (Phase 3) explicitly deferred ✓.
- **Review clusters sized + tiered (each ≤4 tasks, 1f):** Gate **A1** (read-model + endpoint, FULL) · **A2** (picker endpoints + trajectory join, FULL) · Gate B (bulk mutations, FULL) · Gate **C1** (edition UI, STANDARD) · **C2** (trajectory surfaces, STANDARD) · Gate D (boundary + cleanup, STANDARD) ✓.
- **Per-task tiers:** every task tagged Tier A (with test contract) or Tier B (with reason) ✓; per-cluster Integration gate lines ✓.
- **Trajectory layer (added 2026-06-13 after the overlooked-layer review):** machinery verified to exist; the only true backend gap is `/users/{id}/trajectories` (wiring existing compute); the parent/child join + read-vs-validate concerns are pinned as sibling-site audits ✓.

---

## Execution handoff

Plan complete and saved to `docs/plans/2026-06-13-admin-workspace-spec.md`. This IS the brief's `spec.md`.

**Mockups built + reviewed** under `docs/mockups/admin-workspace/` (Vandaag / Inschrijvingen / Dossier, person lens). This spec is **synced to the reviewed mockups (2026-06-13)** — the enrollment-pipeline status filter, "Indelen per" group-by, "Inschrijvingsstatus" + pending hint, hide-empty/collapsed stages, the Vragenlijst de-duplication, real action buttons, the comprehensive timeline, and the §10 scale/active-edition cutoff are all reflected here.

**Refinement-derived tasks added:** **1.4a** (`/editions/options` typeahead), **3.4a** (surface session-selection in the timeline), and the **trajectory layer** (§11): **1.4b** (trajectory grid filter + `/trajectories/options`), **3.5** (`/users/{id}/trajectories` + case-view trajectory section), **3.6** (Trajecten tab).

> **Mockup gap (deliberate):** the reviewed mockups do NOT yet show the trajectory layer (filter pill, Trajecten tab, case-view trajectory section) — that layer was added to the spec after the mockup round. Before/with Phase-1 implementation, the mockups should be extended with: a "Traject" filter pill on Inschrijvingen, a Trajecten tab (list + detail + jump-to-grid), and the trajectory progress section in the Dossier. Small mockup follow-on, tracked here so it isn't lost.

For implementation, two execution options:
1. **Subagent-Driven (recommended)** — fresh subagent per task, review between tasks at each `── REVIEW GATE ──`.
2. **Inline Execution** — batch execution with checkpoints at each gate.
