# Audit Remediation — Nine Findings

**Date:** 2026-07-03
**Branch (current tree):** `feat/admin-url-filter-state` (`RegistrationTable` SCHEMA_VERSION=2, NO Reminder module)
**Work class:** C (bug-fix bundle from an audit — one TDD cycle per finding at execution; this document is the Stage-0→1 plan only)
**Sources of findings:** `REVIEW.md` (2026-07-02 production-readiness), perf audit (2026-07-03)

---

## Classification and gate-firing decisions (the judgment layer)

**Class C** — nine established audit findings, evidence already gathered, fixes to be planned. Each finding is its own TDD cycle at Stage 2. No brainstorm (intent is concrete), but the plan-time security + invariant + WP gates fire because the surfaces trigger them.

### Gates that FIRED (with the one-line trigger)

| Gate | Fired? | Trigger |
|---|---|---|
| **1a Threat-modeling** | ✅ FIRED | AA-1 (authorization boundary), BULK-1 (multi-tenancy / partner scoping), DATA-2 (money/grant race on the public enrollment surface). `## Threat model` embedded below, BEFORE task breakdown. |
| **1b Architecture-invariants** | ✅ FIRED (doc exists) | Findings bypass **INV-1** (authorization — AA-1), **INV-3** (data access — DATA-2, FIX-10, registered_at index), **INV-8** (VAT/totals — DATA-1 touches the quote write path), **INV-9** (company-scoping down-push — BULK-1). Each task cites the invariant it converges back to. Doc already authored; no `/architecture-invariants audit` needed. |
| **WP plan-requirements (stack override)** | ✅ FIRED | Findings touch AJAX caps (AA-1), custom-table writes (DATA-2, DATA-1), partner multi-tenancy scoping (BULK-1), schema migration (registered_at index). Four-pillar + ntdst-core layering blocks embedded per-flow below. |
| **1g Feature-acceptance** | ✅ FIRED | Three user-reachable behavior changes: enrollment double-submit (DATA-2), voucher re-apply/failed-redeem (DATA-1), admin reject-registration under the raised cap (AA-1). `## Acceptance flows` matrix embedded below. |

### Gates that did NOT fire (and why)

| Gate | Why not |
|---|---|
| **Stage 0 brainstorm** | Intent is concrete — nine findings with established evidence and named fixes. No open design questions except the DATA-2 lock-vs-index choice, which is decided in-task (constraint below). |
| **`sourcing-from-docs` (external lib verify)** | No fix rests on external-library behavior. All fixes are against Stride's own source (already ground-truthed, Stage 1c below). MariaDB `GET_LOCK`/generated-column semantics are standard SQL, verified at execution, not a doc-premise. |
| **`designing-apis`** | No new API/boundary is designed. AA-1 hardens an existing surface to match its REST twin; no new routes. |

### Stage 1c — premises ground-truthed against source (the cheapest catch)

All nine premises were READ against current source before this plan shipped. Confirmed-true:

- **DATA-2:** `RegistrationRepository::create()` (`:242–312`) is a read-then-insert with **no lock** on `(user,edition)`. `EnrollmentService::enroll()` (`:208–293`) wraps `START TRANSACTION` but `countConfirmedForUpdate` locks the **capacity** predicate, while the duplicate check at `:278` is a plain `findByUserAndEdition` read — a different predicate. Premise HOLDS.
- **DATA-1:** `QuoteService::applyVoucher()` writes discount to the quote at `:658`, **then** redeems at `:676`; a failed redeem at `:678` returns the error with the discount already persisted. Premise HOLDS. Callers: `QuoteAdminController:522`, `QuoteUpdateHandler:110`.
- **AA-1:** `EditionAdminController::verifyAjaxNonce()` (`:1104–1117`) gates on `edit_posts`, not `stride_manage`. The correctly-gated sibling handlers (`ajaxAddSession:574`, `ajaxUpdateSession:613`, `ajaxDeleteSession:651`, `ajaxBulkLockQuotes:795`) add per-object `current_user_can('edit_post',$editionId)` — **the pattern to copy**. The AA-1 handlers (`ajaxMarkAttendance:687`, `ajaxBulkAttendance:740`, `ajaxConfirmRegistration:809`, `ajaxRejectRegistration:830`, `ajaxApprovePostCourse:851`, `ajaxExportRegistrations:874`) rely only on `verifyAjaxNonce`/`edit_posts` with no per-edition scope. REST twin `AdminAPIController` POST `/admin/attendance` (`:212`) gates `canManageAdmin` = `stride_manage`. Premise HOLDS. **Correct controller path is `Modules/Edition/Admin/EditionAdminController.php`** (the finding's short path was ambiguous).
- **BULK-1:** `BulkRegistrationHandler::handleBulkSetField()` sanitizes `company_id → absint` (`:359–360`) then writes via `$repo->update($id, ['company_id' => $value])` (`:366`) with no existence check. The `stride_manage` cap gate is present (M2). Premise HOLDS. **Key nuance:** `_stride_company_id` is a **scoping id, not a name-resolvable post/term** (`AdminRegistrationQueryService:243`) — there is no Company CPT; "a real company" = a company_id at least one user actually carries. Home of the key: `Modules/User/CompanyAffiliation.php` (`META_KEY = _stride_company_id`, `getCompanyId()`).
- **#5 500-cap:** four sites in `EditionAdminController.php` — `:236`, `:261`, `:978`, `:1071` — each `'posts_per_page' => 500`. Premise HOLDS.
- **#6 FIX-10:** `RegistrationRepository:1937–1989` — the per-group IDs subquery is unbounded; the `TODO(perf, deferred)` at `:1939` documents the exact OOM shape. `AdminRegistrationQueryService:395–396` consumes it. Premise HOLDS.
- **#8 registered_at index:** the default grid sort; `RegistrationTable` is at SCHEMA_VERSION=2 with a version-gated `migrate()` + `RETRY_TRANSIENT` backoff (`:96–130`). Premise HOLDS.
- **#7 gate-reminder cron:** confirmed NOT on this tree — no Reminder module, no `reminder_state`/`findWithActiveDeadline` present. Lives on `feat/gate-deadlines-reminders`. See **Out-of-tree items** below.
- **#9 Redis:** `web/app/object-cache.php` is **absent**; `wpackagist-plugin/redis-cache ^2.8` IS in composer. Premise HOLDS. Ops-blocked (below).

---

## Threat model

> **Context.** This threat model covers three surfaces the nine audit findings touch: the admin AJAX authorization boundary (AA-1), the partner multi-tenancy scoping boundary (BULK-1), and the money/course-grant race on the public enrollment surface (DATA-2). Written 2026-07-03, at plan time, before any task is dispatched. It exists so `/code-review` and `/security-review` verify the fixes against named mitigations instead of re-hunting — and so the three security-boundary clusters (Phase 1 DATA-2, Phase 2 AA-1, Phase 4 BULK-1) converge in one round. The other six findings (500-cap, FIX-10, index, wait-items, cron, Redis) are correctness/scalability, not attack surface — they are out of this section's scope and carry no attacker.

### What we're defending

1. **Course/LMS grant integrity** — `wp_vad_registrations` rows + the `LMSAdapterInterface::grantAccess()` side effect they drive. A duplicate confirmed enrollment for one `(user_id, edition_id)` can double-grant LD access, double-count capacity, and (via the quote path) create a second billable quote for the same seat.
2. **Capacity as a scarce resource** — the `capacity`/`countConfirmedForUpdate` invariant on an edition. A race that inserts two confirmations past a capacity-of-N boundary oversells the seat.
3. **The quote discount ledger** — `vad_quote.discount/tax/total` meta and the paired `vad_voucher` redemption count. An unfunded discount (discount persisted, voucher never redeemed) is money written off with no matching redemption — an invoice-integrity defect flowing to Exact Online.
4. **Attendance / registration-status mutation authority** — the admin AJAX handlers that mark attendance, confirm/reject a registration, and export the roster (PII egress). These decide who completed a course and who appears on a downstream certificate/report.
5. **The partner API data boundary** — `_stride_company_id` scoping. `RegistrationRepository::findByCompany()` (`WHERE company_id = %d`) is the whole tenant wall; the `company_id` column value is the key that boundary trusts.

### Who we're defending against

| Actor | Scope |
|---|---|
| Anonymous / low-priv public enroller double-submitting the enrollment form (fast double-click, retried request, two tabs) | **IN** — DATA-2 |
| Authenticated WP user with `edit_posts` but WITHOUT `stride_manage` (e.g. an author/editor of unrelated content) | **IN** — AA-1: today they can hit attendance/confirm/reject/export handlers |
| A `stride_manage` coordinator scoped to some editions but not others (per-edition object scope) | **IN** — AA-1: no per-edition `edit_post` check today |
| A legitimate `stride_manage` admin who fat-fingers or is fed a bad `company_id` in a bulk set-field | **IN** — BULK-1: misassigns a registration into a partner's API scope |
| A partner API caller reading `/stride/v1/partner/*` who benefits from a mis-scoped row landing in their company | **IN** (as the beneficiary of BULK-1) — they see a registration that isn't theirs |
| Insider with a stolen `administrator` credential | **OUT** — acknowledged, not defended; admin is trusted end-to-end |

### Attacks to defend against

1. **DATA-2 / duplicate-grant race (create path):** two concurrent `RegistrationRepository::create()` calls for the same `(user_id, edition_id)` both pass the `findByUserAndEdition` read at `:251` before either inserts → two confirmed rows → double `grantAccess` + double capacity count. No lock spans the check-and-insert.
2. **DATA-2 / duplicate-grant race (enroll path):** `EnrollmentService::enroll()` holds a `FOR UPDATE` lock on the **capacity** rows (`countConfirmedForUpdate`) but the duplicate check at `:278` reads a **different** predicate (`findByUserAndEdition`) that the capacity lock does not cover — two enrollments for the same user can still interleave.
3. **DATA-2 / capacity oversell:** the same race lets `confirmedCount` be read stale by both transactions at the capacity boundary, confirming N+1 into an N-seat edition.
4. **DATA-1 / unfunded discount:** `applyVoucher` persists the discount then redeems; if `redeemVoucher` fails (voucher exhausted, concurrent redemption, DB error) the discount stays on the quote with no redemption — money written off without a funding record.
5. **AA-1 / capability under-gate:** a user with only `edit_posts` reaches `ajaxMarkAttendance` / `ajaxConfirmRegistration` / `ajaxRejectRegistration` / `ajaxApprovePostCourse` / `ajaxExportRegistrations` because `verifyAjaxNonce` gates `edit_posts`, not `stride_manage` — they mutate completion state and exfiltrate roster PII.
6. **AA-1 / missing per-edition object scope:** a `stride_manage` coordinator can act on ANY edition's attendance/registrations, not just editions they can `edit_post`, because these handlers omit the per-edition `current_user_can('edit_post',$editionId)` the sibling session handlers already enforce.
7. **BULK-1 / scope poisoning:** a bad `company_id` (absint-only, never checked to exist) written onto a registration silently moves that row into a partner's `findByCompany` result set — a cross-tenant leak of a registration that belongs to no company or the wrong one.

### Mitigations required (numbered to match attacks)

1. **DATA-2 mitigation (create path) — advisory lock around check-and-insert.** In `RegistrationRepository::create()`, wrap the duplicate-check-then-insert in a MariaDB named advisory lock keyed on the tuple: `GET_LOCK(CONCAT('stride_reg_', user_id, '_', edition_id), <timeout>)` acquired before `findByUserAndEdition`, released after the insert/reactivate commits (and in every early-return/error path). The lock name is scoped to `(user_id, edition_id)` so unrelated enrollments don't serialize. **Do NOT add a plain UNIQUE key on `(user_id, edition_id)`** — see the boxed constraint below.
2. **DATA-2 mitigation (enroll path) — cover the duplicate predicate under the held transaction.** In `EnrollmentService::enroll()`, acquire the same `(user,edition)` advisory lock (or route the duplicate check + insert through the now-locking `create()`) so the duplicate check at `:278` is serialized against a concurrent enroll for the same user — the capacity `FOR UPDATE` is retained for oversell, the advisory lock adds the tuple coverage it doesn't provide.
3. **DATA-2 mitigation (oversell) — retained + verified.** Keep `countConfirmedForUpdate` + `FOR UPDATE`; the Tier-A test drives two concurrent confirmations at a capacity-1 boundary and asserts exactly one confirms. (Same lock ordering as #1/#2 to avoid deadlock: always tuple-lock → then capacity `FOR UPDATE`, never the reverse.)
4. **DATA-1 mitigation — redeem-then-write, or transaction-wrap with compensating rollback.** Reorder `applyVoucher` so `redeemVoucher` succeeds BEFORE the quote discount write, OR wrap both writes in a `START TRANSACTION` and `ROLLBACK` the discount if redeem returns `WP_Error`. The discount must never be observable on the quote unless a matching redemption exists. Totals still derive through `QuoteCalculator::deriveTotalsFromCents()` (INV-8) — the reorder does not touch the math.
5. **AA-1 mitigation — raise the cap.** Change `verifyAjaxNonce()` to require `current_user_can('stride_manage')` (matching the REST twin `canManageAdmin`) instead of `edit_posts`. Fix `ajaxExportRegistrations` (`:880`), which checks `edit_posts` inline, to the same cap.
6. **AA-1 mitigation — add per-edition object scope.** Add `current_user_can('edit_post', $editionId)` to each of the five AA-1 handlers, resolving `$editionId` the way each already does (attendance handlers resolve via session → edition, as `ajaxMarkAttendance:713` already does). This copies the exact pattern the sibling session handlers use at `:574/:613/:651/:795`. Denial returns the same 403 JSON shape.
7. **BULK-1 mitigation — validate `company_id` against the real-company set before write.** In `BulkRegistrationHandler::handleBulkSetField()`, when `$field === 'company_id'` and `$value > 0`, verify the value is an existing company scope before calling `$repo->update()`. Since there is no Company CPT, "existing" = the value appears as a `_stride_company_id` on at least one user (add a `CompanyAffiliation::companyExists(int): bool` / `getKnownCompanyIds(): int[]` reader as the convergence home, so the check lives beside `META_KEY`, not inline in the handler). `$value === 0` (clear the field) stays allowed. On an unknown non-zero company_id, the row is skipped with a per-row error in the batch report (existing `runBulk` error contract).

### Out of scope (explicit deferrals)

- **DATA-2 across-node/cluster locking:** `GET_LOCK` is per-MariaDB-connection-server, correct for Stride's single-DB deployment. A future multi-primary topology would need a different primitive — deferred, not v1.
- **DATA-2 conditional/generated-column unique index as an alternative:** documented as the acceptable alternative in the constraint box, but the advisory lock is the chosen primary (simpler, no schema migration, no re-enroll/cascade regression risk). If a reviewer prefers the index, it must EXCLUDE cancelled + child rows — a plain unique key is forbidden.
- **DATA-1 voucher-redemption idempotency under retry:** the reorder closes the unfunded-discount window; making `redeemVoucher` itself idempotent under client retry is a separate hardening, deferred.
- **AA-1 nonce lifetime / CSRF beyond `check_ajax_referer`:** unchanged; `check_ajax_referer` remains the same-origin control. Not touched by this fix.
- **BULK-1 partner-role validation of the acting admin:** the `stride_manage` gate already proves the actor; we validate the *value*, not re-validate the actor. A partner writing their own company scope is out of this handler's path (partner API is read-only for enrollments except the scoped POST).
- **Insider with stolen admin credential:** OUT (admin trusted end-to-end).

### How to use this section

- **Controller pre-flight:** before dispatching the DATA-2, AA-1, and BULK-1 tasks, confirm the task prompt carries mitigations 1–7 as concrete requirements.
- **`/code-review` + `/security-review`:** run against this threat model — check each numbered mitigation is present, missing, or deferred per the list. `/security-review` is MANDATORY on the DATA-2 (Phase 1), AA-1 (Phase 2), and BULK-1 (Phase 4) clusters because this threat model fired at plan time.
- **Downstream:** the gate-reminder cron fix (out-of-tree, `feat/gate-deadlines-reminders`) inherits nothing from this model — it is a correctness predicate fix with no attacker; do not re-litigate.

> ### ⛔ DATA-2 hard constraint (read before implementing)
> A plain UNIQUE key on `(user_id, edition_id)` was **already tried and DROPPED in June 2026** (memory `gotcha_bad_unique_user_edition_constraint`) because it broke **re-enrollment** (Cancelled → re-enroll reactivates the same row) and **trajectory cascade children** (multiple child rows share a user+edition shape). Do NOT reintroduce it, do NOT migrate it into `RegistrationTable::create()`, and do NOT make tests tolerate it. The fix is a **`GET_LOCK` advisory lock** around check-and-insert (chosen primary), OR — if a reviewer insists on an index — a **conditional/generated-column unique index that excludes `status='cancelled'` and child rows** (`parent_registration_id IS NOT NULL`). Never a plain unique key.

---

## WP security requirements (per data-flow)

> Per the four pillars (validate / sanitize / escape / authorize) — canonical functions in `netdust-wp:wp-security`. Only the flows these findings touch are listed.

- [ ] **AJAX `stride_*` attendance/confirm/reject/approve/export (AA-1):** `check_ajax_referer` (retained) + **authorize raised to `current_user_can('stride_manage')` + per-edition `current_user_can('edit_post',$editionId)`** + sanitize (existing `absint`/`sanitize_text_field` on session_id/user_id/status — retained) + `$wpdb->prepare` via repository (retained) + escape: n/a for the JSON handlers, `esc_*` retained on the export output path.
- [ ] **AJAX `ntdst/api_data/stride_bulk_set_field` (BULK-1):** nonce via framework (INV-2, upstream — retained) + `current_user_can('stride_manage')` (M2, retained) + **validate: `company_id` must be an existing company scope before write** + sanitize (`absint` retained) + `$wpdb->prepare` via `RegistrationRepository::update` (retained) + escape: n/a (returns batch report, no echo).
- [ ] **Quote voucher-apply write path (DATA-1):** authorize (existing `stride_manage` in `QuoteAdminController`/`QuoteUpdateHandler` — retained) + validate (voucher existence — retained) + **integrity: redeem-then-write / transaction-rollback so no unfunded discount persists** + `$wpdb->prepare` via `QuoteRepository::updateMeta` (retained) + totals via `QuoteCalculator` (INV-8, retained).
- [ ] **Enrollment create/enroll write path (DATA-2):** authorize (existing enrollment gate — retained) + validate (existing edition-open/status checks — retained) + **concurrency: advisory-lock the `(user,edition)` check-and-insert** + `$wpdb->prepare` (retained) + escape: n/a (no output).
- [ ] **Schema migration (registered_at index, #8):** no user input; requirement is **version-gated `migrate()` at SCHEMA_VERSION bump with `RETRY_TRANSIENT` backoff** (never a standalone `ALTER`) + additive/idempotent DDL.
- [ ] **500-cap query fixes (#5) + per_page clamp (#3 wait-item):** validate/clamp the numeric bound (`min(per_page, 100)`); no new user-input surface; no output change.

---

## ntdst-core layering requirements

> Same nine drift categories the `ntdst-drift-reviewer` checks — canonical definitions in `netdust-wp:ntdst-architecture`. Only the rows these findings touch:

- [ ] **Data access through the owning Repository (INV-3):** DATA-2 lock lives in `RegistrationRepository` (the `wp_vad_registrations` owner); FIX-10 SQL tally lives in `RegistrationRepository`, consumed by `AdminRegistrationQueryService`; registered_at index in `RegistrationTable` (schema owner). No new `$wpdb` in a service that isn't a moved-from-controller/repo-worthy shape.
- [ ] **No swallowed `WP_Error` (INV-4):** DATA-1 rollback path returns/logs the redeem `WP_Error`; BULK-1 unknown-company path returns a per-row error via `runBulk`.
- [ ] **Authorization at the entry point by capability (INV-1):** AA-1 raises `verifyAjaxNonce` to `stride_manage` + per-edition `edit_post` — the admin-page-AJAX convergence pattern, matching the REST twin.
- [ ] **Company scoping pushed down (INV-1/INV-9 corollary):** BULK-1 validation reader lives in `CompanyAffiliation` (the `_stride_company_id` home), not inline in the handler.
- [ ] **VAT/totals via `QuoteCalculator` (INV-8):** DATA-1 reorder must not re-derive totals — `deriveTotalsFromCents()` stays the single chain.
- [ ] **No hardcoded meta prefix / correct Data API vocabulary:** all writes stay on the existing repository methods.

> **Convergence contract.** These blocks + the `## Threat model` + the cited invariants are the convergence target for `/code-review`, `/security-review`, and `ntdst-drift-reviewer` at shake-out. A gap is a one-line finding keyed to a named item, not a re-discovery.

---

## Acceptance flows (1g)

> The three user-reachable behavior changes. Each row's Edges column enumerates the six edge classes (empty/zero · denied actor · wrong-order/re-entry · concurrent/double · boundary · mid-flow failure) or names why one is excluded. Driven at shake-out through the faithful layer (Codeception/WPBrowser + real HTTP for backend; browser for the admin reject UI).

| Flow | Intended use | Edges (six classes) |
|---|---|---|
| **Enrollment double-submit (DATA-2)** | User enrolls once in an edition → one confirmed row, one LD grant, capacity +1 | **empty:** enroll with no prior row → single insert. **denied:** unauthenticated enroll on a closed edition → `enrollment_closed`, no row. **re-entry:** Cancelled row → re-enroll reactivates the SAME row (not a new one, not blocked — the constraint that killed the unique key). **concurrent/double:** two simultaneous enrolls for the same `(user,edition)` → exactly one confirmed row, one grant (advisory lock). **boundary:** two enrolls at capacity-1 → one confirms, one gets `edition_full`. **mid-flow failure:** insert fails after lock acquired → lock released, `WP_Error` returned, no partial row. |
| **Voucher re-apply / failed redeem (DATA-1)** | Admin applies a valid voucher → discount on quote AND matching redemption recorded | **empty:** apply with empty/blank code → validation error, no write. **denied:** non-`stride_manage` actor cannot reach applyVoucher (upstream gate). **re-entry:** re-apply a different code → previous released, new redeemed, discount recomputed once. **concurrent/double:** two applies of a near-exhausted voucher → at most the available redemptions succeed; a failed redeem leaves NO discount (the fix). **boundary:** >100% percentage voucher → discount clamped to subtotal, tax/total ≥ 0 (INV-8 clamp). **mid-flow failure:** `redeemVoucher` returns `WP_Error` → discount NOT persisted (redeem-then-write / rollback), error surfaced. |
| **Admin reject-registration under raised cap (AA-1)** | `stride_manage` coordinator rejects a pending registration on an edition they can edit → status transitions, roster updates | **empty:** reject a non-existent/already-rejected registration → 404/no-op, no double transition. **denied (the fix):** `edit_posts`-only user (no `stride_manage`) → 403, no mutation; AND a `stride_manage` user on an edition they can't `edit_post` → 403 (per-edition scope). **re-entry:** reject then reject again → idempotent/no-op. **concurrent/double:** two rejects of the same row → one transition, one no-op. **boundary:** reject the last pending registration on an edition → roster empties cleanly. **mid-flow failure:** transition write fails → `WP_Error` surfaced, status unchanged. |

---

# Phasing (priority-ordered)

**Ordering rationale.** Phase 1 = the two things that hurt at CURRENT volume: #5 (500-cap is a *correctness* bug at 470 courses today — misclassifies course #501, truncates the dropdown) and DATA-2 (#1 is a P1 money/grant race on the public launch surface). Phase 2 = the two small money+authz fixes (DATA-1, AA-1). Phase 3 = scale-before-volume (FIX-10 OOM, registered_at index). Phase 4 = BULK-1 + the four wait-items. Out-of-tree: #7 gate-reminder (pre-merge fix on its own branch), #9 Redis (ops-blocked). This matches the requested order; no stronger ordering found — DATA-2 and #5 are the only two with a *today* cost, so they lead.

---

## Phase 1 — Hurts now (correctness at current volume + P1 launch-surface race)

**Integration gate (phase):** enrollment integration suite green — concurrent-enroll test at capacity-1 confirms exactly one row + one grant; re-enroll-after-cancel still reactivates the same row; course-classification integration test passes at a corpus > 500 courses (seed or synthesize) with correct online/self-paced classification and a complete filter dropdown.

### Cluster 1A — 500-course cap (correctness bug) · `── REVIEW GATE ── (tier: STANDARD — query-shape/classification fix, no 1a surface, no data-layer write)`

**Task 1A.1 — Remove the 500 cap at all four classification/filter sites.** `EditionAdminController.php:236, :261, :978, :1071`. Replace `'posts_per_page' => 500` with `'nopaging' => true` + `'fields' => 'ids'` (or a term-relationship query), no numeric cap. INV-3 (query shape stays in the controller's accepted zone; if a new reusable shape emerges, push to `EditionRepository`). **[Tier B]** — no bespoke unit test; a classification integration test at corpus > 500 (the phase gate) plus a query-budget assertion suffices; this is a cap-removal, not new logic. `no unit test: Tier B, cap removal verified by phase classification integration test`.

> **Sibling-site audit (500-cap):** the finding names 4 sites — `:236` and `:261` (online/self-paced classification) and `:978`, `:1071` (filter dropdown). Fix all four in one task; grep `posts_per_page.*500` across `EditionAdminController` after the change must be empty. Also sweep the whole controller for any other numeric `posts_per_page` cap on a course/edition corpus query — the four are the known set; confirm no fifth.

### Cluster 1B — DATA-2 enrollment duplicate/grant race (P1) · `── REVIEW GATE ── (tier: FULL — data-layer + concurrency on the money/grant path; 1a surface; /security-review mandatory)`

**Task 1B.1 — Advisory-lock the create-path check-and-insert.** `RegistrationRepository::create()` (`:242–312`). Acquire `GET_LOCK('stride_reg_{user}_{edition}', <timeout>)` before `findByUserAndEdition`, release after commit AND on every early-return/error/reactivate path. Mitigation 1. **Honor the DATA-2 hard constraint — advisory lock, NOT a unique key.** INV-3 (lock lives in the table's owning repository). **[Tier A]** — *test contract:* a RED-first test drives two concurrent `create()` calls for the same `(user_id, edition_id)` and asserts exactly one row is inserted (the second sees the existing row and blocks/reactivates, does not duplicate); includes the denial path (concurrent duplicate is rejected, not silently double-granted) and the re-enroll-after-cancel path still reactivates the same row.

**Task 1B.2 — Cover the enroll-path duplicate predicate under the lock.** `EnrollmentService::enroll()` (`:208–293`). Acquire the same `(user,edition)` advisory lock (tuple-lock → then capacity `FOR UPDATE`, never the reverse) so the duplicate check at `:278` is serialized; retain `countConfirmedForUpdate` for oversell. Mitigations 2 + 3. INV-3. **[Tier A]** — *test contract:* a RED-first test drives two concurrent `enroll()` calls for the same user at a capacity-1 edition and asserts one confirms + one grant + exactly one seat consumed, and a separate case asserts a concurrent duplicate for the same user does not produce a second confirmed row; denial path = second enroll returns `already_enrolled`/`edition_full`, no grant.

> **Sibling-site audit (DATA-2):** both write entry points that create a confirmed `(user,edition)` row must serialize — `RegistrationRepository::create()` and `EnrollmentService::enroll()`'s inline insert branch (`:295+`). Confirm no third path inserts a confirmed registration bypassing the lock (grep `INSERT`/`->create(`/`upgradeFromInterest` in the enrollment module). `upgradeFromInterest` mutates an existing anonymous row, not a new confirmed insert — verify it stays out of the duplicate class or is covered by the lock.

**Phase 1 review:** Cluster 1A → STANDARD (2 finders + simplicity + the classification integration pass). Cluster 1B → **FULL** (all finders + `security-sentinel`; `/code-review --effort=high`; **`/security-review` mandatory** — threat model fired). Escalation one-way: any finding on the enrollment write path keeps/promotes 1B at FULL.

---

## Phase 2 — Money + authz (both small)

**Integration gate (phase):** invoicing integration suite green — voucher failed-redeem leaves no discount on the quote; admin-authz integration test — an `edit_posts`-only actor is denied on all five AA-1 handlers, and a `stride_manage` actor is denied on an edition they cannot `edit_post`.

### Cluster 2A — DATA-1 voucher/quote unfunded discount · `── REVIEW GATE ── (tier: FULL — money write path + INV-8 quote totals; 1a surface; /security-review mandatory)`

**Task 2A.1 — Redeem-then-write (or transaction-wrap) in `applyVoucher`.** `QuoteService::applyVoucher()` (`:601–699`, reorder around `:655–685`). Redeem BEFORE persisting the discount, OR wrap both in a transaction and roll back the discount on redeem `WP_Error`. Totals stay via `QuoteCalculator::deriveTotalsFromCents()` (INV-8 — do not re-derive). Mitigation 4. INV-4 (redeem error is returned/logged, not swallowed). **[Tier A]** — *test contract:* a RED-first test asserts that when `redeemVoucher` returns `WP_Error`, the quote discount/tax/total are UNCHANGED (no unfunded discount persisted) and the error is surfaced; a green case asserts a successful apply persists discount AND records the redemption; boundary case: >100% voucher clamps to subtotal.

> **Sibling-site audit (DATA-1):** `applyVoucher` is called from `QuoteAdminController:522` and `QuoteUpdateHandler:110` — both inherit the fix (it's inside the service). Confirm no OTHER discount-write path persists a discount without a paired redemption (grep `updateMeta.*discount`/`releaseVoucher`/`redeemVoucher` in `Modules/Invoicing`). The `releaseVoucher` of a previous code at `:642` (re-apply path) must still fire before the new redeem — verify the reorder doesn't strand a released-but-not-replaced code.

### Cluster 2B — AA-1 admin AJAX authz · `── REVIEW GATE ── (tier: FULL — authorization boundary bypass, INV-1; 1a surface; /security-review mandatory)`

**Task 2B.1 — Raise the cap to `stride_manage`.** `EditionAdminController::verifyAjaxNonce()` (`:1104–1117`) → `current_user_can('stride_manage')`; fix the inline `edit_posts` in `ajaxExportRegistrations` (`:880`) to match. Mitigation 5. INV-1 (matches the REST twin `canManageAdmin`). **[Tier A]** — *test contract:* a RED-first test asserts an `edit_posts`-only actor is denied (403) on `verifyAjaxNonce`, and a `stride_manage` actor is allowed; denial path is the assertion.

**Task 2B.2 — Add per-edition object scope to the five AA-1 handlers.** `ajaxMarkAttendance` (`:687`), `ajaxBulkAttendance` (`:740`), `ajaxConfirmRegistration` (`:809`), `ajaxRejectRegistration` (`:830`), `ajaxApprovePostCourse` (`:851`). Add `current_user_can('edit_post', $editionId)` resolving `$editionId` as each handler already does (attendance via session→edition). Mitigation 6. INV-1. **[Tier A]** — *test contract:* a RED-first test asserts a `stride_manage` actor is denied (403) on an edition they cannot `edit_post`, and allowed on one they can; one case per handler class (attendance / confirm-reject / approve).

> **Sibling-site audit (AA-1):** the handlers that ALREADY gate correctly — `ajaxAddSession:574`, `ajaxUpdateSession:613`, `ajaxDeleteSession:651`, `ajaxBulkLockQuotes:795` — are **the pattern to copy** (per-edition `edit_post` after `verifyAjaxNonce`). After the fix, EVERY `ajax*` mutating handler in `EditionAdminController` must have BOTH the raised cap (via `verifyAjaxNonce`) AND a per-edition `edit_post` check; grep `function ajax` and confirm each mutator has the object check (read-only helpers like `ajaxGetCourseLessons:662` are exempt — note them). Confirm the REST twin (`AdminAPIController` `canManageAdmin`) is unchanged (it's the reference, already correct).

**Phase 2 review:** both clusters **FULL** — 2A is a money write path (INV-8), 2B is an authorization boundary (INV-1). All finders + `security-sentinel`; `/code-review --effort=high`; **`/security-review` mandatory** on both.

---

## Phase 3 — Scale before volume (near-fatal at 50k + the default-sort index)

**Integration gate (phase):** grid integration suite green — group-by "offerte verdeling" tally returns the SAME counts as the pre-fix PHP tally (characterization parity) with a bounded query (assert no unbounded IDs subquery / no PHP object explosion via a query-count budget); grid default-sort query uses the `registered_at` index (assert via `EXPLAIN` or query-budget on a filtered corpus).

### Cluster 3A — FIX-10 grouped-grid OOM · `── REVIEW GATE ── (tier: FULL — data-layer rewrite of a tally SQL on the high-volume registration table, INV-3)`

**Task 3A.1 — Move the offerte-verdeling tally into SQL.** `RegistrationRepository:1937–1989` (replace the unbounded per-group IDs subquery + PHP tally) + `AdminRegistrationQueryService:395–396` (consume the SQL result). JOIN the quotes' `registration_id` postmeta + `GROUP BY group_value, status` so the tally is computed in the DB, not by pulling every reg-id into PHP. Remove the `TODO(perf, deferred)` at `:1939`. INV-3 (SQL shape lives in `RegistrationRepository`; service consumes). Preserve the NULL-group `IS NULL` handling already noted at `:1945–1948`. **[Tier A]** — *test contract:* a RED-first characterization test pins the tally output shape/counts for a fixture with multiple groups + statuses + a NULL group, and asserts the new SQL path returns identical counts to the old PHP path (parity), including the NULL-group-via-`IS NULL` case; a scale-shaped case asserts no per-group ID materialization.

> **Sibling-site audit (FIX-10):** the tally is one of a family of grid aggregates. Confirm no OTHER group-by aggregate in `RegistrationRepository`/`AdminRegistrationQueryService` pulls a full ID list into PHP for a count (grep `get_results` feeding a `count()`/`array_column` tally in the grid path). The trajectory-parent corpus exclusion (FIX 1, noted at `:1943`) already shrinks the corpus — verify the new SQL keeps that exclusion.

### Cluster 3B — registered_at index (schema v3) · `── REVIEW GATE ── (tier: FULL — irreversible-class schema migration on the high-volume table; solo cluster; /security-review)`

**Task 3B.1 — Add the `registered_at` index via a SCHEMA_VERSION=3 bump.** `RegistrationTable.php` — bump `SCHEMA_VERSION` 2 → 3, add the v3 step in `migrate()` (additive `ALTER TABLE … ADD INDEX idx_registered_at (registered_at)`) guarded by the existing `RETRY_TRANSIENT` backoff, add the index to `create()`'s `CREATE TABLE` for fresh installs, and stamp the version. Never a standalone `ALTER`. Optionally add `completed_at`/`cancelled_at` indexes if the export/cron order needs them (default sort is `registered_at` — that's the required one). INV-3 (schema owner). **[Tier B]** — *no bespoke unit test; migration correctness is a Tier-B concern verified by:* the phase integration gate (default-sort query uses the index via `EXPLAIN`/budget) + a migration-idempotency integration assertion (running `migrate()` twice is a no-op, and the fresh-`create()` schema equals the migrated schema). `no unit test: Tier B, additive index migration verified by EXPLAIN + idempotency integration checks`.

> This cluster is a **solo irreversible-class migration** — it reviews alone (1f: schema change is a security-boundary/irreversible step). Even though the index add is reversible in principle, the SCHEMA_VERSION mechanism + fresh-install-vs-migrate parity is the risk surface; it gets its own gate.

**Phase 3 review:** both clusters **FULL** (data-layer + migration). All finders + `security-sentinel` + `performance-oracle` (this phase IS the perf work); `/code-review --effort=high`; `/security-review` on 3B (schema/migration). Note: no plan-time threat model covers 3A/3B (no attacker), so `/security-review` here is the schema-safety pass, not a tenancy audit.

---

## Phase 4 — Partner scoping + wait-items

**Integration gate (phase):** partner-scoping integration test — a bulk set-field with an unknown `company_id` skips the row with a per-row error and writes nothing; a known company_id writes; `company_id = 0` clears. Wait-item perf assertions green (composite-index EXPLAIN, batched attendance query-count, per_page clamp boundary, dropped-index absence).

### Cluster 4A — BULK-1 company_id validation · `── REVIEW GATE ── (tier: FULL — multi-tenancy scoping boundary, INV-1/INV-9 corollary; 1a surface; /security-review mandatory)`

**Task 4A.1 — Add a company-existence reader to `CompanyAffiliation`.** `Modules/User/CompanyAffiliation.php` — add `companyExists(int $companyId): bool` (or `getKnownCompanyIds(): int[]`) reading the distinct set of `_stride_company_id` usermeta values. This is the convergence home for "is this a real company scope" — beside `META_KEY`. INV-3 (usermeta read concentrated in the affiliation home, not inline). **[Tier A]** — *test contract:* a RED-first test asserts `companyExists()` returns true for a company_id carried by a user and false for one carried by nobody; boundary: `0`/negative → false.

**Task 4A.2 — Validate `company_id` before write in the bulk handler.** `BulkRegistrationHandler::handleBulkSetField()` (`:340–367`). When `$field === 'company_id'` and `$value > 0`, call `CompanyAffiliation::companyExists($value)`; on false, skip the row with a per-row error via `runBulk` (no write); `$value === 0` (clear) stays allowed. Mitigation 7. INV-1 (scope integrity; the write no longer poisons `findByCompany`). **[Tier A]** — *test contract:* a RED-first test asserts a bulk set-field with an unknown non-zero company_id writes NOTHING and reports a per-row error; a known company_id writes; `0` clears the field; denial path = the unknown-company write is refused, not silently applied.

> **Sibling-site audit (BULK-1):** `company_id` is written via `RegistrationRepository::update()` from the bulk handler (`SAFE_FIELDS = ['notes','company_id']`). Confirm no OTHER admin/handler path writes `company_id` without the existence check (grep `'company_id'` writes across `Handlers/`, `Admin/`, `Modules/Enrollment`). The partner-scoped enrollment POST (`PartnerAPIController`) derives company from the caller, not from input — verify it stays out of this class. If a second unchecked writer exists, route it through `companyExists()` too.

### Cluster 4B — Four wait-items · `── REVIEW GATE ── (tier: STANDARD — index/query-shape/clamp tuning outside 1a surfaces and outside a plan-time threat model)`

**Task 4B.1 — Activity-feed composite index `(created_at, id)`.** `AdminActivityService.php:74` — add the composite index for the activity keyset order (via the owning table's schema mechanism if it's a Stride table, or documented if it's `audit_log`). INV-3. **[Tier B]** — `no unit test: Tier B, index-only; verified by EXPLAIN in the phase gate`.

**Task 4B.2 — Batch `batchGetAttendance` across editions.** `AdminRegistrationQueryService:197–200` — replace the per-edition attendance fetch with one batched query (BatchQueryHelper pattern, INV-3 justified-bypass family). **[Tier A]** — *test contract:* a RED-first test asserts the batched call returns the same attendance map as N per-edition calls (parity) with a bounded query count (1, not N).

**Task 4B.3 — Clamp `per_page` on `getEditions`.** `AdminAPIController.php:604` — `$perPage = min(100, max(1, (int)($request->get_param('per_page') ?: 20)))`. **[Tier B]** — `no unit test: Tier B, boundary clamp verified by a query-budget/boundary assertion in the phase gate`. (Same clamp pattern as the sibling-site audit note below.)

**Task 4B.4 — Drop redundant `idx_session_user` on `vad_attendance`.** Via the attendance table's schema-version mechanism (an index DROP is additive-safe; guard with the same backoff pattern). INV-3. **[Tier B]** — `no unit test: Tier B, index drop verified by schema assertion + attendance suite still green`.

> **Sibling-site audit (per_page clamp):** `getEditions:604` is one of several REST list endpoints that read `per_page`. After clamping it, grep `per_page` across `AdminAPIController` + `PartnerAPIController` and confirm every unbounded `per_page` read is clamped to ≤100 (the partner endpoints already clamp — verify; if any admin list endpoint reads `per_page` without a ceiling, clamp it in the same task or note it as a follow-up).

**Phase 4 review:** Cluster 4A → **FULL** (multi-tenancy boundary; all finders + `security-sentinel`; `/code-review --effort=high`; **`/security-review` mandatory** — threat model fired for BULK-1). Cluster 4B → STANDARD (2 finders + simplicity + perf assertions; `performance-oracle` only if a hot path in `CODE-MAP.md` is touched; no `security-sentinel`). Escalation one-way: a finding on 4B that touches a scoping/authz surface promotes it to FULL.

---

# Out-of-tree items (sequenced separately — NOT tasks on the current tree)

## #7 — Gate-reminder cron predicate (PRE-MERGE FIX on `feat/gate-deadlines-reminders`)

**This is NOT a task on `feat/admin-url-filter-state`.** The gate-reminder cron work AND schema v3's `reminder_state` column live on the UNMERGED branch `feat/gate-deadlines-reminders`. The fix must be applied **on that branch, BEFORE it merges** — it is a pre-merge correctness fix, not a fix on the current tree.

**Finding:** `findWithActiveDeadline` has no date floor and uses OFFSET pagination — at volume it re-scans and paginates unstably.
**Fix (on `feat/gate-deadlines-reminders`):** add a `deadline >= today` (or `reminder_state`-incomplete) predicate + replace OFFSET pagination with keyset pagination (`WHERE r.id > %d` ORDER BY id). **[Tier A]** when implemented on that branch — *test contract:* a RED-first test asserts rows with a past deadline (or completed reminder_state) are excluded, and that keyset pagination returns each row exactly once across pages with no OFFSET drift.

**Sequencing note:** whoever merges `feat/gate-deadlines-reminders` MUST land this fix first. When that branch's schema v3 (`reminder_state`) merges relative to THIS plan's schema v3 (`registered_at` index, Task 3B.1), **the two SCHEMA_VERSION bumps collide** — coordinate: whichever merges second must rebase to become v4 (or fold both into one bump). Flag at merge time; do not let two branches both claim SCHEMA_VERSION=3.

## #9 — Redis object-cache drop-in (OPS-BLOCKED, not an implementer-closable code task)

**This is an OPS/infra action, not a code task the implementer can close alone.** `web/app/object-cache.php` is absent; `wpackagist-plugin/redis-cache ^2.8` is already in composer. Enabling the drop-in is the highest-leverage infra mitigation (every transient mitigation assumes it), BUT it is **blocked on two open items**:

- **H-1: no deploy method / Makefile exists** — there is no established way to activate the drop-in in staging/prod.
- **Open question: is Redis provisioned on the Ploi plan?** — unconfirmed.

**Action:** flag to Stefan as an ops decision. When unblocked, activation is `wp redis enable` (drops in `object-cache.php`) after confirming the Redis service + connection env. Do NOT dispatch this to an implementer as a code task — it cannot be verified by the test suite and depends on infra not yet in place. Track in the launch checklist under infra, not this plan's phases.

---

# Review-tier summary (1f / 1h)

| Cluster | Tasks | Tier | Why |
|---|---|---|---|
| 1A — 500-cap | 1 | STANDARD | query-shape/classification, no 1a surface |
| 1B — DATA-2 race | 2 | **FULL** | data-layer + concurrency on money/grant path (1a) |
| 2A — DATA-1 voucher | 1 | **FULL** | money write path + INV-8 |
| 2B — AA-1 authz | 2 | **FULL** | authorization boundary bypass (INV-1, 1a) |
| 3A — FIX-10 tally | 1 | **FULL** | data-layer rewrite on high-volume table (INV-3) |
| 3B — registered_at index | 1 | **FULL** | irreversible-class schema migration (solo cluster) |
| 4A — BULK-1 scoping | 2 | **FULL** | multi-tenancy scoping boundary (1a) |
| 4B — wait-items | 4 | STANDARD | index/query/clamp tuning, no 1a surface |

`/security-review` fires on 1B, 2A, 2B, 4A (plan-time threat model covers them) and on 3B (schema-safety). Every cluster is ≤ 4 tasks (1f). Escalation is one-way: any finding on a 1a surface promotes its cluster to FULL.
