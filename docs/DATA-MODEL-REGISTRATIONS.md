# `wp_vad_registrations` — Data Model Reference

> **Read this before touching the table.** This is the single high-volume table
> behind enrollments, the admin grid, the worklist queues, the Partner API and
> the dossier. Most of the 2026-07 admin-dashboard bugs traced back to code
> that guessed at this table's semantics instead of knowing them. Every rule
> below is enforced somewhere (an invariant doc entry, a contract test, or a
> guard in code) — the pointers tell you where.

**Owners (INV-3):**

| Concern | Owner | Rule |
|---|---|---|
| DDL + versioned migrations | `Modules/Enrollment/RegistrationTable.php` | The ONLY place that issues `CREATE TABLE` / `ALTER TABLE`. |
| Every query | `Modules/Enrollment/RegistrationRepository.php` | The ONLY `$wpdb` caller for this table. Need a query it doesn't expose? **Add a repository method** — never reach around it (ARCHITECTURE-INVARIANTS.md INV-3). |
| Status vocabulary | `Domain/RegistrationStatus.php` | Closed enum; semantic questions (`countsTowardCapacity()`, `hasAccess()`, `blocksDuplicate()`, `label()`) are enum methods — don't re-derive them from the raw string. |
| Status transitions | `Modules/Enrollment/RegistrationTransitions.php` | THE transition map (client bulk bar drift-validates against it via `StrideConfig.transitions`). |
| Completion tasks JSON | `Modules/Enrollment/EnrollmentCompletion.php` | Builds/mutates `completion_tasks`; nothing else invents task shapes. |

---

## 1. Three row kinds live in one table

The (`edition_id`, `trajectory_id`, `parent_registration_id`) signature decides
what a row IS. Get this wrong and rows leak into (or vanish from) the admin
grid, capacity counts, and trajectory dashboards.

| Row kind | `edition_id` | `trajectory_id` | `parent_registration_id` | Meaning |
|---|---|---|---|---|
| **Edition row** | set | NULL | NULL | A person's relationship to ONE scheduled offering. The unit the admin grid, capacity, attendance and quotes reason over. |
| **Trajectory PARENT** | **NULL** | set | NULL | The umbrella enrollment in a trajectory. **Never an edition-grained row**: no capacity, no attendance, no grid row. |
| **Cascade CHILD** | set | set (legacy) or via parent | set → the parent row | An edition row created by trajectory cascade. Counts as a normal edition row everywhere; its parentage links it to the trajectory. |

Hard rules that follow:

- **The admin grid is edition-grained.** `buildGridFilters` pins
  `r.edition_id IS NOT NULL` as the base predicate — trajectory parents are
  NEVER grid rows in any scope. Surfacing them is the trajectory layer's job.
- **Trajectory scoping is a parent→child JOIN**, not `WHERE trajectory_id = T`
  (which misses cascade children whose `trajectory_id` is NULL). The verified
  join shape lives in `buildGridFilters` / `findChildRegistrationIdsByTrajectory`
  — mirror those, don't improvise.
- **Interest rows always carry a real `edition_id`** (dateless interest-anchor
  editions included). A NULL `edition_id` means trajectory parent, nothing else.

## 2. Column reference

| Column | Type | Written by | Semantics & gotchas |
|---|---|---|---|
| `id` | PK | — | Referenced by quotes (`registration_id` postmeta on `vad_quote`), audit context, attendance. |
| `user_id` | BIGINT NULL | enrollment paths | **NULL/0 = anonymous lead** (interest/waitlist form without an account). May also point to a since-DELETED WP user — readers must key "anonymous" on *record absence*, not on `user_id <= 0` (the grid and roster both do; see `presentLeadIdentity`). |
| `lead_name`, `lead_email` | VARCHAR(191) NULL, **v5** | repo write paths ONLY | Denormalized, searchable copy of the anonymous submitter's identity. See §4 — five invariants. |
| `edition_id` | BIGINT NULL | enrollment paths | FK → `vad_edition` post. NULL ⇔ trajectory parent (§1). |
| `trajectory_id` | BIGINT NULL | trajectory enrollment | FK → `vad_trajectory` post. |
| `parent_registration_id` | BIGINT NULL | cascade | FK → this table (the trajectory parent row). |
| `status` | ENUM | domain services | See §3. Stored value == `RegistrationStatus` case value. The DB ENUM and the PHP enum must be changed TOGETHER. |
| `enrollment_path` | ENUM | create paths | `individual` \| `colleague` \| `trajectory` \| `partner` (`PATH_*` consts on the repo). `partner` added in schema v2. How the row came to exist — never a lifecycle signal. |
| `selections` | JSON NULL | selection flows | Session IDs or elective edition IDs. Server-owned (INV-6b): clients never write it directly. Read via `SessionSelection` helpers. Writes MUST hold the selection lock (§5). |
| `selections_locked_at` | DATETIME NULL | selection lock-in | Once set, selections are frozen for the participant. |
| `quote_id` | BIGINT NULL | invoicing | FK → `vad_quote`. The *offerte status* itself lives on the quote post's meta — resolved ONLY via `AdminRegistrationQueryService::offerteStatusesForRegistrations()` (the single paid-proxy definition). Never re-derive "has an offerte" from this column alone. |
| `company_id` | BIGINT NULL | partner path | Partner-affiliation scoping id (`_stride_company_id` usermeta world). **NOT a name-resolvable entity and NOT `billing_company`** — a user's invoice company (usermeta) and this FK are independent; never conflate or fall back between them (CLAUDE.md rule). Partner API scoping = `findByCompany()` only. |
| `enrolled_by` | BIGINT NULL | colleague/admin enroll | Actor ≠ participant (e.g. a colleague enrolling someone). |
| `registered_at` | DATETIME | insert | Default grid sort (indexed, v3). Also feeds the `oldinterest` queue cutoff. |
| `completed_at` | DATETIME NULL | completion | Set when completed. The `nocert` queue requires it non-empty. |
| `cancelled_at` | DATETIME NULL | cancellation | Cleared again when a cancelled row is **reactivated** (§5). |
| `notes` | TEXT NULL | admin | Free text. |
| `completion_tasks` | JSON NULL | `EnrollmentCompletion` | Per-registration task ledger (approval, intake, session_selection, …). Only `EnrollmentCompletion` builds/mutates task entries. |
| `enrollment_data` | JSON NULL | form flows via repo | The stage envelope archive — see §4. |
| `reminder_state` | JSON NULL, **v4** | reminder sender | Per-registration reminder idempotency ledger. |

**Indexes:** `idx_user`, `idx_edition`, `idx_trajectory`, `idx_parent`,
`idx_status`, `idx_edition_status`, `idx_trajectory_status`, `idx_company`,
`idx_user_status`, `idx_user_edition`, `idx_registered_at` (v3 — the default
grid sort; keep it or every filtered page is a filesort).

## 3. Status lifecycle

```
Interest  → Pending, Cancelled
Waitlist  → Confirmed, Cancelled
Pending   → Confirmed, Cancelled
Confirmed → Cancelled            (Confirmed → Completed happens via completion flow)
Completed → (terminal)
Cancelled → (terminal — but see reactivation, §5)
```

- `RegistrationTransitions::isAllowed()` is the ONE gate; the JS bulk bars
  validate their action catalogs against it at init (CR-5 drift warning).
- Capacity counts = `countsTowardCapacity()` (Confirmed, Completed, Pending —
  pending RESERVES a spot). Course access = `hasAccess()` (Confirmed,
  Completed). Duplicate blocking = `blocksDuplicate()` (adds Interest).
  **Ask the enum, don't hand-roll status lists** — three different hand-rolled
  lists is how the pre-slice capacity bugs happened.
- Every admin surface renders `status` AS RECEIVED via `label()` (INV-7):
  no client-side re-derivation of "is this really completed".
- The worklist queues are NOT statuses: each queue is a server-resolved id-set
  (`Admin/WorklistQueueResolver`) whose predicate merely *starts from* one
  status (`statusForQueue()` — contract-tested against grid.js `QUEUE_META`).

## 4. JSON columns — the M5 rule and the lead-identity invariants

**M5 (ARCHITECTURE-INVARIANTS.md): JSON columns never appear in
WHERE / ORDER BY / GROUP BY.** No `LIKE` over `enrollment_data`, no
`JSON_EXTRACT` in a hot path (the two narrow `JSON_EXTRACT` equality lookups
in the repo's find-by-email helpers are the sanctioned exceptions — exact
match, never scans). Anything that must be filterable gets **denormalized
into a real column** (that is exactly why `lead_name`/`lead_email` exist).

`enrollment_data` shape — enforced by `normalizeEnrollmentData()`:

```
{
  "<stage>":            { "submitted_at": ISO-8601 UTC,
                          "submitted_by": int|null,      // null = anonymous
                          "data": { ...form fields... } },
  "initial_selection":  { "phases": [ ... ] }            // append-only phase log
}
// allowed root keys: interest, waitlist, enrollment_personal,
// enrollment_billing, intake, evaluation, initial_selection
```

Unknown root keys are DROPPED (and logged) on write — don't invent new stages
without extending `ALLOWED_ROOT_KEYS`. Always build envelopes via
`wrapStage()`; never hand-assemble the shape.

**Who a "lead" is — participant vs actor.** The public interest/waitlist
forms create anonymous rows (`user_id = NULL`) UNCONDITIONALLY — even for a
logged-in visitor — deduplicated by e-mail per edition
(`findAnonymousForEmailAndEdition`; a repeat submission appends its stage to
the same row). The lead columns therefore always hold the **participant's**
identity (who the interest is FOR — possibly someone the visitor typed in),
never the actor's. The actor is tracked separately: `submitted_by` inside the
stage envelope (pre-enrollment) or the `enrolled_by` column (full enrollment).
Contrast: full enrollment "voor een collega" (`PATH_COLLEAGUE`) creates a REAL
WP account for the participant — no lead columns there. When a lead later
enrolls or is promoted from the waitlist, the upgrade path find-or-creates
the account collision-safely (INV-9) and ADOPTS the anonymous row (merging
`enrollment_data`) — never a duplicate. Known product gap: a logged-in user
submitting interest for THEMSELVES also becomes an anonymous lead (the form
never binds to the session account); `EnrollmentService::registerInterest()`
— the account-bound path — currently has no callers.

**The five lead-identity invariants** (`lead_name` / `lead_email`, schema v5):

1. **One extractor.** `RegistrationRepository::extractLeadIdentity()` is THE
   definition of where a lead's identity lives in the JSON
   (`interest` → `waitlist` stage fallthrough). The write paths, the v5
   backfill, and any future reader all call it — a second JSON-path
   re-implementation WILL drift (it already did once).
2. **One presenter.** `RegistrationRepository::presentLeadIdentity()` is THE
   `'(anoniem)'` fallback rule. The grid and the edition roster both render
   through it; a hand-rolled fallback makes the same row show two identities
   on two screens (it already did once).
3. **Stamped unconditionally, cleared unconditionally.** Whenever an anonymous
   row's `enrollment_data` is (re)written, the columns are set to whatever the
   extractor returns — INCLUDING `''`. An identity scrubbed from the JSON
   (GDPR/privacy cleanup) must clear the denormalized copy; a "only stamp when
   non-empty" guard makes the PII outlive its source (it already did once).
4. **Repo-derived, never caller-settable.** The columns are not in `update()`'s
   `$allowed` list; they are derived from `enrollment_data` inside the repo.
   No handler/service writes them directly.
5. **The backfill is resumable, never silently partial.** The v5 migration
   batches on `lead_name IS NULL`, stamps `''` for checked-but-empty rows so a
   batch never re-scans them, and only stamps the schema version when the
   corpus DRAINED — errors and the runaway cap pause with a backoff and resume
   (see §6).

## 5. Concurrency & uniqueness — read this before "just adding a UNIQUE key"

- **There is deliberately NO unique key on (user_id, edition_id).** One was
  added and **dropped in June 2026** (`gotcha_bad_unique_user_edition_constraint`):
  it broke re-enrollment (a Cancelled row is REACTIVATED in place — same row,
  `cancelled_at` cleared, status reset) and trajectory cascade children (a
  parent and child can share the user+edition shape). If the constraint ever
  returns it must be status-class-aware AND ship with a duplicate-cleanup
  pass — tracked as `tasks/todo.md` follow-up #2, targeted at schema v6.
  **Do not piggyback it casually on the next version bump.**
- **Duplicate prevention is an advisory lock**, not a constraint:
  `acquireEnrollLock(userId, editionId)` serializes the
  check-then-insert in `create()` (DATA-2). New enrollment entry points must
  route through `create()` — never a bare `$wpdb->insert`.
- **Selections/enrollment_data read-modify-write requires
  `acquireSelectionLock(registrationId)`** — interleaved submissions diffing
  against the same pre-state was a real shipped bug (shake-out 2026-06-12).
- **Reactivation:** `create()` for a (user, edition) that has a Cancelled row
  UPDATES that row (merging `enrollment_data`, re-stamping lead identity)
  instead of inserting. Code that assumes "one INSERT per enrollment" or that
  row ids are append-only will miscount.

## 6. Schema changes — the migration contract

Versioning lives in `RegistrationTable` (`SCHEMA_VERSION`, option
`stride_registrations_schema_version`). The option is also the **step
cursor**: each step stamps its own version on completion, so a paused later
step resumes without re-paying earlier ALTERs. History: v2 partner enum ·
v3 `idx_registered_at` · v4 `reminder_state` · v5 `lead_name`/`lead_email`
+ backfill.

Checklist for **v6+** (all of these are load-bearing; each exists because its
absence shipped a bug):

1. Add the column/index to BOTH the `create()` DDL and a new `migrate()` step.
   The DDL is plain `CREATE TABLE` (dbDelta diffs it) — **never** add
   `IF NOT EXISTS` back: dbDelta cannot parse that form, silently skips column
   diffing, and `create()` would stamp the new version with the columns missing.
2. Wrap the step in `if ($from < N)` and stamp `N` on completion.
3. Guard DDL idempotently (`SHOW COLUMNS` / `SHOW INDEX`) — steps re-run on
   pre-cursor installs and after backoffs.
4. On any failure: `self::failStep('vN', '<step-slug>'); return;` — log +
   retry backoff, version unstamped. **Never stamp after a partial step.**
5. A data backfill must be batched, resumable (marker value for
   checked-but-empty rows), and must check `$wpdb->last_error` — real wpdb
   returns `[]` on a FAILED select, not null.
6. Update `RegistrationTableMigrateGuardTest` (the fake wpdb pins the
   stamping contract) and, if a column feeds reads, the grid SELECT column
   lists in `queryForGrid` / `queryForGridGrouped`.

## 7. Reading the table for admin surfaces — the scope contract

- Grid-family reads (`queryForGrid`, `queryForGridGrouped`, `statusBreakdown`,
  `offerteVerdelingByGroup`, `idsForGridFilter`) apply **no scope of their
  own**. Client filter input MUST pass through
  `AdminRegistrationQueryService::applyScopePins()` first (queue → server
  id-set; default → admin-active edition set). `buildGridFilters` logs a loud
  warning when built without the pins — if you see that log, you found the
  next blast-radius bug before it shipped. This applies to READS **and** to
  bulk select-all expansion (`BulkRunner::resolveBulkIds`).
- Id-set pins go through `pinToIdSet()`: an EMPTY resolved set means **zero
  rows** (`1 = 0`), never "no filter".
- `queue_ids` / `active_edition_ids` are SERVER vocabulary — strip them from
  any client-supplied filter before pinning (BulkRunner does).
- Exports/new surfaces: same rules. If you're writing
  `$repo->queryForGrid($clientInput)` directly, you are re-creating the
  2026-07-14 blast-radius bug.

## 8. Where things are deliberately NOT

- **Offerte status** → on the `vad_quote` post, resolved via the single
  two-step resolver (see `quote_id` row above).
- **Attendance** → `wp_vad_attendance` (own table, own repository).
- **Capacity** → derived (edition capacity meta vs `countsTowardCapacity()`
  rows); cached per edition for 60s in `EditionService::getRegisteredCount()`.
- **Certificates** → LearnDash (`LearnDashHelper::getCertificateLink`), never
  stored here.
