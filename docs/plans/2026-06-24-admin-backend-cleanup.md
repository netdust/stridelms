# Admin Backend Cleanup (Phase 1) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Drain `AdminAPIController` (2,877 lines) to a thin controller, move every read-model into repositories/services mirroring `AdminRegistrationQueryService`, fix the frontend-facing contract bugs, delete verified-dead code, fix hot paths, and harden the three security surfaces — so the Phase 2 frontend rebuild builds against a pristine, consistent backend contract.

**Architecture:** Strangle/refactor, **behavior-preserving EXCEPT C1** (a deliberate INV-7 bug fix). The existing unit + integration suites are the safety net — green BEFORE and AFTER every task. SQL moves verbatim out of the controller into repository methods (INV-3 convergence) following the existing `EditionRepository::countAdminList`/`findAdminListRows` template; read-model assembly moves into `Admin/*Service` classes (the sanctioned read-model layer per INV-3). No new feature behavior.

**Tech Stack:** NTDST Core (DI, Repository, REST controllers), PHP 8.3, WordPress/Bedrock, LearnDash. PHPUnit (Unit + Integration). PHPStan + Pint (`composer lint`).

## Global Constraints

- **Branch:** `admin-backend-cleanup` (off the Phase 2a cohort-lens baseline + the F1 trajectory-detail fix already cherry-picked).
- **Backend only.** No frontend/JS/template changes. The admin frontend is rebuilt from wireframes in a SEPARATE Phase 2. If a contract field's NAME or SHAPE changes (C1 effective-status value, C2 envelope, `paid_at` removal), that is the contract the new frontend will build to — note it, do not also touch the existing consumer.
- **Behavior-preserving except C1.** Every SQL move is VERBATIM (same JOINs, same WHERE, same ORDER BY, same param order). The ONLY intended behavior change is C1 (edition grid emits effective status). Any other diff in suite output is a regression.
- **Suite-green gate, both ends.** `ddev exec vendor/bin/phpunit --testsuite Unit` and `ddev exec vendor/bin/phpunit -c phpunit-integration.xml.dist` pass before a task starts AND after it closes. `composer lint` (Pint + PHPStan) clean at each task close. CI is the source of truth (`gh run watch`), not local-only (gotcha_ci_green_local_red).
- **INV-3 is the spine.** New/moved registration-table + edition + quote + trajectory query SHAPES go to the OWNING repository. Read-model ASSEMBLY (bucketing/formatting/mapping) goes to an `Admin/*Service`. The gold-standard template is `Admin/AdminRegistrationQueryService` (zero raw SELECTs; delegates to repo + `BatchQueryHelper`; owns only read-model assembly). A new raw `$wpdb` in a service that isn't moved-from-the-controller and isn't repo-worthy is a bypass.
- **Error convention is `WP_Error`** (INV-4). New/converted REST failure paths return `WP_Error` with `['status' => 4xx]`, never `new WP_REST_Response(['error' => ...], 4xx)`.
- **Dutch UI strings preserved verbatim** when moving code (the error envelopes in C2 carry translated messages — keep the `__(...)` text, only change the envelope type).

---

## Architecture invariants touched (gate 1b)

This plan converges hard on two named invariants in `/home/ntdst/Sites/stride/ARCHITECTURE-INVARIANTS.md`. Cite them in every relevant task; they are the `/code-review` + `invariant-auditor` convergence target.

- **INV-3 — Domain data is reached through the per-domain Repository.** The ENTIRE strangle (D1–D4, B3, S5) is INV-3 work: it moves the controller's remaining direct-`$wpdb` reads (`getQuotes`, `getActivityFeed`, `getHealthChecks`, `getNotifications`, the export reg-side SELECT, the agenda/list assembly) into the owning repositories + the sanctioned `Admin/*Service` read-model layer. INV-3's "known bypasses" list **already names this controller as the actively-draining accepted zone** — this plan is the next drain. Each extraction must follow the rule: net-new/relocated query SHAPES → repository (`QuoteRepository`, `TrajectoryRepository`, `RegistrationRepository`); ASSEMBLY → `Admin/*Service`. The Data API vocabulary sub-invariant (`title`/`content`, bare field names, `_ntdst_` applied by the layer) holds.
- **INV-7 — Display status is derived through `getEffectiveStatus()`, not read raw.** C1 IS an INV-7 bypass in the controller's edition read-model: `getEditions` (`:715`) + `getEditionsAgendaView` (`:1005`) emit raw `_ntdst_status` for a DISPLAY surface, while the typeahead (`getEditionOptions`) already emits `getEffectiveStatuses()`. The fix routes both grid loops through the (already-batched) effective-status read — the exact convergence INV-7 names. This is the one deliberate behavior change.
- **INV-1 / INV-4 touched by the security + C2 clusters:** S2 (impersonation) sharpens INV-1's authorization-at-entry-point; S1 (partner child scoping) is INV-1's "company scoping pushed DOWN into the repository, not done inline"; C2 aligns the envelope with INV-4's `WP_Error`-everywhere rule.

---

## Golden path: none (refactor/strangle — no matching archetype)

This is a behavior-preserving strangle of an existing controller, not a new content-type / form / settings-page / YOOtheme feature. The `netdust-wp:ntdst-patterns` golden-path slices author NEW vertical slices; here the "spine" already exists and the work is to make existing code route through the named convergence points (INV-3 repo, INV-7 effective-status). The convergence target is therefore the **invariants doc**, not a golden-path slice. The security cluster (S1/S2/N1) is the one place new authorization logic is added — its requirements are in the WP-security block below.

---

## WP security requirements (per data-flow)

The four pillars are defined in `netdust-wp:wp-security` — this names which apply per touched flow. Most flows here are **read-model moves** where the entry-point authz (`permission_callback`) is UNCHANGED and the SQL is already `$wpdb->prepare`d — the requirement on those is "preserve, don't weaken." The security cluster ADDS authorization logic; those rows are the load-bearing ones.

- [ ] **REST routes touched by the strangle (D1–D4, B3, C1, C2):** authorize = UNCHANGED (`canViewAdmin` / `canManageAdmin` already declared per route, INV-1) — verify the route registration is untouched. sanitize = UNCHANGED (params already `absint`/`sanitize_text_field`d in the controller; moved code carries them). escape = N/A (REST JSON, WP serializes). prepare = every relocated query uses `$wpdb->prepare` with every dynamic value a placeholder (the `EditionRepository::countAdminList` template enforces this; mirror it VERBATIM).
- [ ] **S1 — `GET /stride/v1/partner/enrollments` + `/{id}` (trajectory children):** authorize = `checkPermission()` proves partner role + `_stride_company_id` (UNCHANGED). **NEW pillar: company scoping pushed DOWN** — `RegistrationRepository::findByParents($parentIds, $companyId)` adds `AND company_id = %d` (INV-1 rule: partner data filter comes from the repository, never a controller `array_filter`). prepare = the new `company_id` predicate is a `%d` placeholder.
- [ ] **S2 — `POST /stride/v1/admin/users/{id}/impersonate`:** **authorize = the fix.** Route currently declares `permission_callback => canManageAdmin` (checks `stride_manage`) but the real authority gate inside the body is `manage_options`. Change the route `permission_callback` to require `manage_options` directly (a closure or named method returning `current_user_can('manage_options')`), so the entry point matches the actual authority (INV-1: authorization decided at the entry point). The body's internal `validateTarget(callerHasManageOptions: current_user_can('manage_options'))` stays as defense-in-depth. sanitize = `id` is `(int)` (UNCHANGED).
- [ ] **N1 — `GET /stride/v1/admin/users/{id}/reveal-field` (`revealSensitiveField`):** authorize = `canManageAdmin` (UNCHANGED) + already audited. **NEW: rate-limit** — a per-user transient (`stride_pii_reveal_rl_{currentUserId}`, short window, capped count) checked at the top of the handler; over-limit returns `WP_Error('rate_limited', ..., ['status' => 429])`. sanitize = `field` already validated against the `$allowed` allow-list (UNCHANGED). escape = N/A (REST JSON). The audit write stays.

Every flow accounts for all four pillars above; where a pillar is `UNCHANGED` the requirement is "the move/edit does not weaken it," verified in the diff.

## ntdst-core layering requirements

Only the rows that apply to this refactor (drift categories from `ntdst-drift-reviewer`):

- [ ] **Data access goes through a Repository** — every relocated SELECT lands in `QuoteRepository` / `TrajectoryRepository` / `RegistrationRepository`, OR in a sanctioned `Admin/*Service` read-model (INV-3's listed exception) when it is assembly moved verbatim from the controller. No new direct `ntdst_data()`/`$wpdb` outside those.
- [ ] **No pure pass-through Service methods** — the new `Admin*Service` methods ADD assembly/bucketing/formatting (that is their reason to exist). A service method that is literally `return $this->repo->X(...)` is drift (lesson_pure_passthrough_is_drift) — don't create one; call the repo directly from the controller if there's no assembly.
- [ ] **No swallowed `WP_Error`** — C2 conversions and any relocated error path return/bubble `WP_Error` (INV-4); no `is_wp_error` branch that neither logs nor returns.
- [ ] **No hardcoded meta prefix** — relocated SQL keeps its existing literal `_ntdst_status` etc. (this is raw SQL in a repository, the sanctioned home; do NOT introduce NEW hardcoded prefixes in service-layer PHP — use `getMetaPrefix()` there).
- [ ] **Correct module layering** — new services live in `Admin/` (read-model layer); new repo methods in the owning `Modules/*/`*Repository.php`. Service lifecycle per `NTDST_Service_Meta` + registered in `plugin-config.php` if DI-resolved.

**Per-task acceptance line (every module-touching task):** drift pre-check clean — `/drift-reviewer <touched path>` returns no findings (the nine categories), and the per-flow security line above is satisfied in the diff.

> **Convergence contract.** These blocks (golden-path N/A + the per-flow pillars + the layering categories) plus the `## Threat model` below and the INV-3/INV-7 cites are the convergence target for `/code-review` and `ntdst-drift-reviewer` at shake-out. Reviewers verify the diff against these NAMED items, not free-form — a gap is a one-line finding keyed to a named item, not a re-discovery.

---

## Threat model

> Context: this threat model covers the **security-hardening cluster (S1/S2/N1)** of the Phase 1 admin backend cleanup, written 2026-06-24 BEFORE task breakdown. These are defense-in-depth hardenings (the security audit found no current live exploit), but each touches a 1a trigger surface — multi-tenancy boundary (S1), auth/capability (S2), PII exfil rate (N1). Without this section, `/code-review` + `/security-review` on the security cluster re-discover the surface independently. This is the convergence target.

### What we're defending

1. **Cross-company registration isolation** — `wp_vad_registrations` rows, scoped per partner by the `company_id` column. A partner must see ONLY their own company's enrollments, including trajectory-CHILD registrations reached by parent FK.
2. **The impersonation capability boundary** — the ability to assume another user's session (`POST /admin/users/{id}/impersonate`), which must be gated by `manage_options` (full admin), not the broader `stride_manage` coordinator cap.
3. **Sensitive PII at rest** — `national_id`, `date_of_birth`, `professional_license_number`, `phone` in usermeta, exposed one-field-at-a-time through `revealSensitiveField` (already audited per-access).
4. **The audit trail integrity** — PII reveals are recorded; an attacker should not be able to exfil PII faster than the audit/rate machinery can constrain.

### Who we're defending against

- **A configured partner user** (has `partner` role + `_stride_company_id`) trying to read ANOTHER company's enrollments via the trajectory-children path — **IN scope** (S1).
- **A `stride_coordinator` (stride_manage, NOT manage_options)** — a non-admin staff role — attempting to impersonate a user, which the route currently appears to allow at the gate even though the body enforces `manage_options` — **IN scope** (S2: the entry-point gate must match the real authority).
- **A compromised/curious admin-capable account** scripting `revealSensitiveField` to bulk-harvest PII across many users — **IN scope, partially** (N1: rate-limit raises the cost; a determined admin with `manage_options` is only slowed, not blocked — see deferrals).
- **External unauthenticated attackers** — OUT of scope on these routes (all sit behind `permission_callback`; INV-1 holds).
- **Insiders with stolen `manage_options` credentials** — OUT of scope (the rate-limit slows but cannot stop a full-admin; credential theft is an orthogonal control).

### Attacks to defend against

1. **Cross-tenant trajectory-child leak (S1):** a partner queries `GET /partner/enrollments` (or `/{id}`); the parent registrations are correctly company-scoped, but the CHILD registrations are fetched by `findByParents($parentIds)` **by FK alone, with no `company_id` re-check** (`PartnerAPIController:218`,`:386` → `RegistrationRepository::findByParents:1061`). If a parent's children somehow carry a different `company_id` (data drift, a cascade bug, a shared-trajectory edge), the partner sees another company's child rows. This is the INV-1 / traverse-clause class: scoping correct at the parent fetch, absent at the child fetch.
2. **Impersonation capability drift (S2):** the route `/admin/users/{id}/impersonate` declares `permission_callback => canManageAdmin` (`current_user_can('stride_manage')`), but impersonation's real authority is `manage_options` (the body checks it via `validateTarget`). A `stride_coordinator` passes the GATE; only the body's deeper check stops them. The entry-point authorization does not match the actual authority — a future refactor that trusts the gate (e.g. removes the body check as "redundant") silently opens impersonation to coordinators.
3. **PII exfil by rate (N1):** an admin-capable caller scripts `revealSensitiveField` across user ids, one field per call, harvesting `national_id`/`date_of_birth`/etc. for the whole user base. Each call is audited, but there is no rate ceiling — exfil speed is bounded only by HTTP throughput, and the audit log fills after the fact rather than constraining during.

### Mitigations required

1. **S1 — company-scoped child fetch.** Add `RegistrationRepository::findByParents(array $parentIds, ?int $companyId = null): array` (extend the existing `:1061` signature with an optional `$companyId`). When `$companyId` is non-null, append `AND company_id = %d` to the WHERE (a `$wpdb->prepare` `%d` placeholder). Update BOTH partner call sites (`PartnerAPIController:218` list path, `:386` single path) to pass the partner's resolved `company_id`. The non-null branch is the partner path; the null default preserves any internal caller (verify none rely on cross-company children — ground-truth at task time). Checkable: grep the two call sites pass `$companyId`; the repo method has the `%d` predicate.
2. **S2 — entry-point cap match.** Change the impersonate route's `permission_callback` from `[$this, 'canManageAdmin']` to a callback requiring `current_user_can('manage_options')` directly (a named `canImpersonate()` method or inline closure). Keep the body's `validateTarget(callerHasManageOptions: ...)` as layered defense. Checkable: the route at `:489` no longer reads `canManageAdmin`; it reads the `manage_options` gate. A test asserts a `stride_manage`-only user is denied at the route.
3. **N1 — per-user reveal rate-limit.** At the top of `revealSensitiveField` (after param validation, before the meta read), check a transient keyed to the CURRENT user (`stride_pii_reveal_rl_{get_current_user_id()}`): a windowed counter (e.g. ≤ N reveals per 60s). Over limit → `return new WP_Error('rate_limited', __('Te veel aanvragen. Probeer later opnieuw.', 'stride'), ['status' => 429]);` (note: this also becomes a `WP_Error`, consistent with the C2 envelope fix — see C2). Increment the counter on each allowed reveal. Checkable: the transient check exists; a test drives N+1 calls and asserts the (N+1)th returns 429.

### Out of scope (explicit deferrals)

- **A full-admin (`manage_options`) bulk-harvesting PII** is only SLOWED by N1, not blocked — an account with full admin is trusted by design. Hard per-field consent / break-glass workflow is deferred (operational, post-launch).
- **Trajectory-child `company_id` data REPAIR** — S1 prevents the leak at read time; if drifted child rows exist, a separate data-audit migration is out of scope here (S1 makes the read safe regardless).
- **Rate-limit shared across a cluster** — the transient is per-site (single DB); a multi-server deployment would need a shared store. N/A for Stride's single-node DDEV/prod shape.
- **Impersonation session-theft beyond the existing cookie+transient binding** — already mitigated in the body (`:2641` note); not re-litigated here.

### How to use this section

- **Controller pre-flight:** before dispatching the security cluster (Phase 5), verify the three mitigations are spelled into the tasks (they are: Task 5.1=S1, 5.2=S2, 5.3=N1).
- **`/code-review` + `/security-review`:** invoke with "Verify the diff against the Phase 1 threat model. Check each numbered mitigation (S1 company-scoped child fetch, S2 entry-point cap match, N1 reveal rate-limit) — report in place / missing / out-of-scope-per-deferrals." `/security-review` is MANDATORY for this cluster (a plan-time threat model exists), independent of tier.
- **`/evaluate` retros:** any unimplemented mitigation is a plan-correction defect.
- **Downstream:** Phase 2 frontend inherits the N1 429 + S1 scoping as contract; cross-reference, don't re-litigate.

---

## 1c premise ground-truth (DONE at plan-time — recorded for the implementer)

The plan's core premise is "repo X already has method-shape Y to copy." **All verified against source 2026-06-24:**

- ✅ `EditionRepository::countAdminList(string $whereClause, array $params, string $tagJoin): int` (`:191`) + `findAdminListRows(..., int $limit, int $offset): array` (`:218`) **EXIST** with the exact signature D1 mirrors. The doc-comment spells the "caller builds WHERE + params + JOIN, repo owns only the `$wpdb->prepare` execution" contract — copy it.
- ✅ `TrajectoryRepository::countTrajectoryOptions(string $q, bool $activeOnly): int` (`:107`) + `findTrajectoryOptions(string $q, bool $activeOnly, int $limit, int $offset): array` (`:126`) **EXIST** — D2's `countAdminList`/`findAdminListRows` mirror these.
- ✅ `Admin/AdminRegistrationQueryService.php` (gold standard) **EXISTS**.
- ✅ `QuoteRepository.php` **EXISTS** at `Modules/Invoicing/QuoteRepository.php` and does NOT yet have `countAdminList`/`findAdminListRows` — D1 ADDS them (mirroring Edition's). Correct premise.
- ✅ `RegistrationRepository::findByParents(array $parentIds): array` (`:1061`) **EXISTS** — S1 extends its signature.
- ✅ Dead-code caller counts: `countConfirmed` (`:2208`) = **0 callers** (definition only). `countUserRegistrations` (`:2464`) = **0 callers** (definition only). `hasActiveRegistrations` (`:1158`) + `hasTrajectoryEnrollments` (`:1177`) = called ONLY by `tests/Integration/DashboardCascadeReadPathsTest.php` (`:208`,`:212`,`:231`,`:232`). Confirmed.
- ✅ `paid_at` in `AdminUserService.php`: fetched `:261`, emitted `:310` (`'paid_at' => ... ?: null`). Stride never writes it (gotcha_no_payment_tracking). Confirmed dead read.
- ✅ S2 drift: route `:489` declares `permission_callback => [$this, 'canManageAdmin']`; body `:2576` enforces `manage_options`. Confirmed gate/authority mismatch.
- ✅ S4 N+1: `AdminEditionRosterService` per-row `displayName()` → `get_userdata($userId)` (`:202`) + inline `get_user_meta($userId, 'organisation', ...)` (`:119`) inside the row loop. The `cache_users` + `update_meta_cache` precedent exists at `searchUsers:2248` / `exportRegistrations:2810`. Confirmed.
- ✅ S6 transient precedent: `AdminStatsService::getActionQueueItems` (`:581`) uses `get_transient('stride_action_queue')` / `set_transient(... 5*MINUTE_IN_SECONDS)` (`:586`/`:706`); `getStats()` (`:59`) is uncached. Confirmed pattern to copy.

**One adjustment surfaced by ground-truth:** the S4 N+1 lives in `displayName()` (a helper called per-row), not only an inline loop body — the fix (`cache_users($userIds); update_meta_cache('user', $userIds);` before the loop) primes the cache that BOTH `get_userdata` (inside `displayName`) and the inline `get_user_meta` read, so it fixes both with no signature change. Noted in Task 4.1.

---

## Execution order & review clusters

Behavior-preserving first (drains the most drift, lowest risk), the one behavior change (C1) on its own gated cluster, then dead-code, perf, and the security cluster last. **Each `── REVIEW GATE ──` is a hard STOP: commit the cluster, run `/integration` on its diff + the full suite, dispatch the stated review tier, do NOT start the next cluster until clear.** Clusters are ≤4 tasks (1f).

| Cluster | Tasks | Review tier (1h) | Why |
|---|---|---|---|
| **A — Quote + Trajectory strangle** | D1, D2 | **STANDARD** | Data-layer move, but VERBATIM + suite-backed, behavior-preserving; no 1a surface. *(See note ‡)* |
| **B — Export + Activity strangle** | D3, D4 | **STANDARD** | Same shape, behavior-preserving. |
| **C — Edition read-model dedup** | B3 | **STANDARD** | Shared mapper extraction, behavior-preserving. |
| **D — Contract fixes** | C2, C1 | **FULL** | C1 is the one BEHAVIOR CHANGE (INV-7); FULL because the contract the new frontend builds to changes + it touches the status convergence point. |
| **E — Dead code** | DC1, DC2 | **STANDARD** | Pure deletion, suite proves no behavioral loss. |
| **F — Perf** | S4, S5, S6 | **STANDARD** | Hot-path caching, behavior-preserving (cache-correctness, not 1a). *(See note ‡)* |
| **G — Security hardening** | S1, S2, N1 | **FULL** | All three touch 1a surfaces (tenancy boundary / auth-capability / PII rate). `/security-review` mandatory (threat model fired). |

‡ **Tier note:** Clusters A and F touch the data layer / migration-adjacent code, which the 1h table flags as a FULL trigger. They are held at **STANDARD** because every change is a **VERBATIM SQL relocation or a cache wrapper with the suite as the behavior-preserving proof** — no new query semantics, no new authorization, no schema change. **This is a controller override of the provisional tier, justified here.** One-way escalation still applies: if any finder surfaces a finding on a 1a surface (e.g. a relocated query turns out to drop a scope predicate), the cluster promotes to FULL immediately.

---

## File structure

| File | Responsibility | Clusters |
|---|---|---|
| `Modules/Invoicing/QuoteRepository.php` | +`countAdminList`/`findAdminListRows` (mirror Edition) | A (D1) |
| `Admin/AdminQuoteService.php` *(new)* | Quote list read-model assembly (mirror `AdminRegistrationQueryService`) | A (D1) |
| `Modules/Trajectory/TrajectoryRepository.php` | +`countAdminList`/`findAdminListRows`/`findById` (mirror its options methods) | A (D2), F (S5) |
| `Admin/AdminTrajectoryService.php` | Remove inline `$wpdb`; delegate to repo; keep bucketing/formatting | A (D2), F (S5) |
| `Modules/Enrollment/RegistrationRepository.php` | +`findForExport($today)`; +`findByParents($parentIds, $companyId)`; DELETE 4 dead methods | B (D3), E (DC1), G (S1) |
| `Admin/AdminExportService.php` *(new)* | Export read-model + `sanitizeCsvCell`; controller keeps CSV streaming | B (D3) |
| `Admin/AdminActivityService.php` *(new)* | Activity feed + health checks + notifications read-models (pair existing `AdminActivityMapper`) | B (D4) |
| `Admin/Mappers/EditionAdminMapper.php` *(new)* | Shared `toItem()` for list + agenda edition→course→regcount→item shaping | C (B3), D (C1) |
| `Admin/AdminAPIController.php` | Thin: delegate `getQuotes`/`getTrajectories`/`exportRegistrations`/`getActivityFeed`/etc.; C2 envelope fix; C1 effective-status; S2 route cap; N1 rate-limit; DELETE `countUserRegistrations` | all |
| `Admin/AdminUserService.php` | C2 envelope fix; DELETE `paid_at` read + key | D (C2), E (DC2) |
| `Admin/AdminEditionRosterService.php` | S4 cache priming before the roster loop | F (S4) |
| `Admin/AdminStatsService.php` | S6 transient wrap around `getStats()` + invalidation | F (S6) |
| `Modules/PartnerAPI/PartnerAPIController.php` | S1 pass `$companyId` to `findByParents` at both call sites | G (S1) |
| `tests/Integration/DashboardCascadeReadPathsTest.php` | DC1 re-point or remove the 4 dead-method assertions | E (DC1) |

---

## CLUSTER A — Quote + Trajectory strangle

### Task D1: Extract `getQuotes` → `QuoteRepository` + `AdminQuoteService`

**Files:**
- Modify: `Modules/Invoicing/QuoteRepository.php` (add `countAdminList` + `findAdminListRows`)
- Create: `Admin/AdminQuoteService.php`
- Modify: `Admin/AdminAPIController.php:1495-1689` (`getQuotes` → thin delegate)
- Modify: `plugin-config.php` (register `AdminQuoteService` if DI-resolved)
- Test: `tests/Integration/AdminQuoteServiceTest.php` (new) + existing `getQuotes` integration coverage

**Interfaces:**
- Consumes: the existing `EditionRepository::countAdminList(string $whereClause, array $params, string $tagJoin): int` / `findAdminListRows(..., int $limit, int $offset): array` as the SHAPE template.
- Produces: `QuoteRepository::countAdminList(string $whereClause, array $params): int`, `QuoteRepository::findAdminListRows(string $whereClause, array $params, int $limit, int $offset): array`; `AdminQuoteService::getQuoteList(array $filters): array` (the read-model the controller returns).

**Tier A** — moves ~6 inline queries + the read-model assembly across a layer boundary; the relocation must preserve the exact result shape. **Test contract:** an integration test asserts `AdminQuoteService::getQuoteList()` returns the SAME rows/shape/order as the pre-extraction `getQuotes` for a seeded fixture (characterization), AND `QuoteRepository::findAdminListRows` returns prepared rows for a known WHERE — the denial/edge path being an empty-filter and a filtered-by-status fixture both matching the old output.

- [ ] **Step 1:** Read `getQuotes` (`:1495-1689`), inventory the ~6 queries + the assembly. Read `EditionRepository::countAdminList`/`findAdminListRows` (`:191-235`) as the template.
- [ ] **Step 2:** Write the characterization integration test FIRST (RED): seed quotes, capture current `getQuotes` JSON, assert the new `AdminQuoteService::getQuoteList()` will match. Run → FAIL (service doesn't exist).
- [ ] **Step 3:** Add `QuoteRepository::countAdminList` + `findAdminListRows` — VERBATIM move of the quote-side SELECTs from the controller, caller builds the WHERE + params, repo owns only `$wpdb->prepare` execution (copy the Edition doc-contract).
- [ ] **Step 4:** Create `AdminQuoteService` mirroring `AdminRegistrationQueryService`: WHERE assembly + `BatchQueryHelper` for any per-row enrichment + the read-model formatting. Zero raw SELECTs in the service.
- [ ] **Step 5:** Reduce `AdminAPIController::getQuotes` to `return $this->respond(ntdst_get(AdminQuoteService::class)->getQuoteList($filters));` (preserve the existing param parsing + `permission_callback`).
- [ ] **Step 6:** Run the characterization test → GREEN. Run full Unit + Integration suites → green, no delta beyond the new test. `composer lint` clean.
- [ ] **Step 7:** `/drift-reviewer Admin/AdminQuoteService.php Modules/Invoicing/QuoteRepository.php` → no findings. Commit.

**Acceptance:** drift pre-check clean; `getQuotes` output byte-identical for the seeded fixture; INV-3 satisfied (SQL in repo, assembly in service).

### Task D2: Drain `AdminTrajectoryService::getTrajectories` inline `$wpdb` → `TrajectoryRepository`

**Files:**
- Modify: `Modules/Trajectory/TrajectoryRepository.php` (add `countAdminList` + `findAdminListRows`, mirroring `countTrajectoryOptions`/`findTrajectoryOptions`)
- Modify: `Admin/AdminTrajectoryService.php` (remove inline `$wpdb->get_var:98` / `get_results:107`; delegate; keep bucketing/formatting)
- Test: `tests/Integration/AdminTrajectoryServiceTest.php` (characterization)

**Interfaces:**
- Consumes: `TrajectoryRepository::countTrajectoryOptions`/`findTrajectoryOptions` as the shape template; the existing `buildOptionsWhere` style.
- Produces: `TrajectoryRepository::countAdminList(string $whereClause, array $params): int`, `findAdminListRows(string $whereClause, array $params, int $limit, int $offset): array`.

**Tier A** — relocates the self-flagged "DRIFT #2" SQL; result shape must be preserved. **Test contract:** characterization test asserts `getTrajectories()` output unchanged for a seeded fixture across the search + status-filter branches (the `$where` clauses at `:75-91` must reproduce verbatim).

- [ ] **Step 1:** Read `getTrajectories` (`:58-...`), note the inline `$wpdb->get_var:98` (count) + `get_results:107` (rows) and the `$where`/`$params` assembly (`:75-91`).
- [ ] **Step 2:** Write characterization test (RED): seed trajectories with statuses, capture current output, assert post-refactor equality. Run → FAIL.
- [ ] **Step 3:** Add `TrajectoryRepository::countAdminList`/`findAdminListRows` — VERBATIM move of the two queries (the `SELECT p.ID, p.post_title, p.post_date, p.post_content FROM ...` and the COUNT), caller passes the assembled WHERE + params.
- [ ] **Step 4:** In `AdminTrajectoryService`, replace the inline `$wpdb` calls with the repo delegation; KEEP the bucketing/formatting (that is the service's reason to exist — not pass-through).
- [ ] **Step 5:** Run characterization test → GREEN. Full suites green. `composer lint` clean.
- [ ] **Step 6:** `/drift-reviewer Admin/AdminTrajectoryService.php Modules/Trajectory/TrajectoryRepository.php` → no findings (the INV-3 "DRIFT #2" advisory should clear). Commit.

**Acceptance:** drift pre-check clean; no `$wpdb` remains in `AdminTrajectoryService`; output unchanged.

── REVIEW GATE ── (tier: STANDARD — VERBATIM data-layer relocation, behavior-preserving, suite-backed, no 1a surface; controller override of the data-layer FULL trigger, justified. Run `/integration` on the A-cluster diff + 2 finders + simplicity. One-way escalate to FULL if any finder finds a dropped scope predicate.)

---

## CLUSTER B — Export + Activity strangle

### Task D3: Extract `exportRegistrations` reg-side SELECT → `RegistrationRepository::findForExport` + `AdminExportService`

**Files:**
- Modify: `Modules/Enrollment/RegistrationRepository.php` (add `findForExport(string $today): array`)
- Create: `Admin/AdminExportService.php` (read-model + `sanitizeCsvCell`)
- Modify: `Admin/AdminAPIController.php:2758` (`exportRegistrations` — keep CSV STREAMING, delegate the query + cell sanitization)
- Test: `tests/Integration/AdminExportServiceTest.php`

**Interfaces:**
- Produces: `RegistrationRepository::findForExport(string $today): array`; `AdminExportService::buildExportRows(string $today): array`, `AdminExportService::sanitizeCsvCell(string $value): string`.

**Tier A** — the reg-side SELECT has a hardcoded `_ntdst_start_date` (`:2772`) that must move verbatim; CSV injection sanitization is security-relevant logic. **Test contract:** test asserts `findForExport` returns the same rows as the inline query for a fixture, AND `sanitizeCsvCell` neutralizes a leading `=`/`+`/`-`/`@` (CSV-injection denial path).

- [ ] **Step 1:** Read `exportRegistrations` (`:2758-...`); note it ALREADY delegates the quote side (half-applied) — only the reg-side SELECT (`:2772`, hardcoded `_ntdst_start_date`) + the CSV cell handling are inline.
- [ ] **Step 2:** Write RED tests: (a) `findForExport` row-shape characterization; (b) `sanitizeCsvCell('=cmd')` returns a neutralized cell. Run → FAIL.
- [ ] **Step 3:** Add `RegistrationRepository::findForExport(string $today)` — verbatim move of the reg-side SELECT, `$today` as a `$wpdb->prepare` `%s` param.
- [ ] **Step 4:** Create `AdminExportService` with `buildExportRows` (delegates to repo + the already-delegated quote side) + `sanitizeCsvCell`. The controller keeps `header()` + `fputcsv` STREAMING (response-shaping stays in the controller).
- [ ] **Step 5:** Wire the controller to call the service for rows + sanitize each cell. Run tests → GREEN. Full suites green. `composer lint` clean.
- [ ] **Step 6:** `/drift-reviewer` on the touched files → clean. Commit.

**Acceptance:** export output byte-identical; CSV-injection neutralized; INV-3 satisfied.

### Task D4: Extract `getActivityFeed` + `getHealthChecks` + `getNotifications`/`markNotificationsRead` → `AdminActivityService`

**Files:**
- Create: `Admin/AdminActivityService.php` (pair existing `AdminActivityMapper`)
- Modify: `Admin/AdminAPIController.php:2153` (`getActivityFeed`, inline `SELECT * FROM audit_log`), `:2091` (`getHealthChecks`), `:2692` (`getNotifications`/`markNotificationsRead`) → thin delegates
- Test: `tests/Integration/AdminActivityServiceTest.php`

**Interfaces:**
- Produces: `AdminActivityService::getActivityFeed(array $args): array`, `getHealthChecks(): array`, `getNotifications(): array`, `markNotificationsRead(array $ids): bool`.

**Tier A** — relocates an inline `SELECT * FROM audit_log`; the `audit_log` table read should land in a repository or the service's prepared query (it has no per-domain repo today — keep the prepared `$wpdb` in the service as a sanctioned read-model move, mirroring INV-3's accepted-zone rationale, and note it). **Test contract:** characterization of the activity-feed rows + the notifications read/mark roundtrip.

- [ ] **Step 1:** Read the four methods; inventory the `audit_log` SELECT (`:2153`) + the health-check assembly + the notifications read/write.
- [ ] **Step 2:** Write RED characterization tests for feed + notifications roundtrip. Run → FAIL.
- [ ] **Step 3:** Create `AdminActivityService`; move the assembly verbatim. Keep `$wpdb->prepare` on the `audit_log` read inside the service (sanctioned read-model move; document why — no `audit_log` repo exists, lowest-risk move is concentrate-then-extract-later).
- [ ] **Step 4:** Reduce the four controller methods to delegates. Run tests → GREEN. Full suites green. `composer lint` clean.
- [ ] **Step 5:** `/drift-reviewer` → clean. Commit.

**Acceptance:** activity/health/notifications output unchanged; the SQL concentrated in one read-model service.

── REVIEW GATE ── (tier: STANDARD — VERBATIM relocation, behavior-preserving, suite-backed. CSV-sanitization denial test must be GREEN. `/integration` on the B-cluster diff + 2 finders + simplicity.)

---

## CLUSTER C — Edition read-model dedup

### Task B3: Extract shared `EditionAdminMapper::toItem()` for list + agenda views

**Files:**
- Create: `Admin/Mappers/EditionAdminMapper.php`
- Modify: `Admin/AdminAPIController.php:651-720` (list view) + `:930-1010` (agenda view) — replace the ~150 duplicated lines with `EditionAdminMapper::toItem()`
- Test: `tests/Unit/Admin/EditionAdminMapperTest.php`

**Interfaces:**
- Produces: `EditionAdminMapper::toItem(object $edition, array $context): array` (edition→course→regcount→item shaping). `$context` carries the batched course-titles + reg-counts so the mapper does NO queries (it's a pure shaper — N+1-safe).

**Tier A** — a pure data-shaping function shared by two surfaces; the union of fields both views emit must be exact (a missing key silently breaks one grid). **Test contract:** unit test asserts `toItem()` emits the full field set BOTH views currently emit, for a representative edition (this is also the seam where C1's effective-status will slot in — see C1). **Sibling-site audit:** the `status` key shape is the cross-cutting surface — both list (`:715`) and agenda (`:1005`) emit it; the mapper centralizes it so C1 has ONE place to fix.

- [ ] **Step 1:** Diff the two blocks (`:651-720` vs `:930-1010`); enumerate the shared item fields + the (currently identical-but-buggy) `status` emission.
- [ ] **Step 2:** Write RED unit test: `toItem()` returns the exact field set both views emit. Run → FAIL.
- [ ] **Step 3:** Create `EditionAdminMapper::toItem()` — pure shaper, takes a `$context` of pre-batched course titles + reg counts (NO queries inside). Move the shared shaping verbatim (status STILL stored-raw here — C1 fixes it next, in this one place).
- [ ] **Step 4:** Replace both controller blocks with `EditionAdminMapper::toItem($edition, $context)`. Run test → GREEN. Full suites green (behavior unchanged — status still raw). `composer lint` clean.
- [ ] **Step 5:** `/drift-reviewer Admin/Mappers/EditionAdminMapper.php` → clean. Commit.

**Acceptance:** both views emit identical output to before; ~150 lines deduped to one mapper; the `status` emission is now single-sourced (enabling C1).

── REVIEW GATE ── (tier: STANDARD — pure-shaper extraction, behavior-preserving. `/integration` on the C-cluster diff + 2 finders + simplicity.)

---

## CLUSTER D — Contract fixes (FULL)

### Task C2: Convert the 5 hand-rolled error envelopes to `WP_Error`

**Files:**
- Modify: `Admin/AdminAPIController.php` — `updateUserProfile:2305,2309,2314,2343,2349` (and the sibling `revealSensitiveField:2417,2421` if in the same convert-set) + `:2305`/`:2309`/`:2314`/`:2344`/`:2350` envelopes
- Modify: `Admin/AdminUserService.php:62` (the `new WP_REST_Response(['error'=>'User not found'], 404)`)
- Test: `tests/Integration/` covering the converted error paths (status code + `WP_Error` code)

**Interfaces:** no new interface — converts `return new WP_REST_Response(['error' => $msg], $code)` → `return new WP_Error('<slug>', $msg, ['status' => $code])`. Preserve the Dutch `__(...)` message text verbatim.

**Tier A** — the error ENVELOPE is the frontend contract; the new frontend reads `WP_Error` JSON shape (`code`/`message`/`data.status`), not `{error: ...}`. **Test contract:** an integration test asserts each converted path returns a `WP_Error` with the right `['status']` (e.g. `updateUserProfile` on a missing user → 404 `WP_Error`, not `WP_REST_Response`). Denial path: invalid email → 400 `WP_Error` with the Dutch message preserved.

- [ ] **Step 1:** Grep-confirm the 5 sites (`AdminAPIController` `updateUserProfile` block + `AdminUserService:62`). Ground-truth: `:2305`,`:2309`,`:2314`,`:2344`,`:2350` in the controller; `:62` in the service. (Note `:2417`/`:2421` in `revealSensitiveField` are the SAME envelope bug — fold them in for consistency; N1 also touches this method.)
- [ ] **Step 2:** Write RED tests asserting `WP_Error` + status for each path. Run → FAIL (currently `WP_REST_Response`).
- [ ] **Step 3:** Convert each: `new WP_Error('not_found'|'forbidden'|'invalid_email'|'email_in_use'|'anonymised'|'invalid_field', <same __() msg>, ['status' => <same code>])`.
- [ ] **Step 4:** Run tests → GREEN. Full suites green. `composer lint` clean.
- [ ] **Step 5:** `/drift-reviewer` → clean (INV-4 satisfied — no swallowed/mis-shaped errors). Commit.

**Acceptance:** all 5 (+2 reveal) paths return `WP_Error`; Dutch messages intact; status codes unchanged.

### Task C1: Fix the INV-7 status bug — edition grid emits EFFECTIVE status (THE behavior change)

**Files:**
- Modify: `Admin/Mappers/EditionAdminMapper.php` (the single `status` emission from B3) — resolve effective status
- Modify: `Admin/AdminAPIController.php` — ensure both grid loops (`:651-720` list, `:930-1010` agenda, now via the mapper) pass the batched `getEffectiveStatuses($editionIds)` into the mapper `$context`
- Test: `tests/Integration/` asserting a cancelled/completed edition shows EFFECTIVE status in the grid

**Interfaces:**
- Consumes: `EditionService::getEffectiveStatuses(array $editionIds): array` (already batched — the typeahead `getEditionOptions:790`/`:832` uses it). Feed its output into the mapper `$context`.
- Produces: `EditionAdminMapper::toItem()` now emits `$context['effectiveStatuses'][$id]` for the `status` key, replacing the raw `_ntdst_status ?: 'open'`.

**Tier A — THE behavior change.** This is the one deliberate deviation from behavior-preservation, and an INV-7 fix. **Test contract:** RED test seeds a CANCELLED edition (stored `_ntdst_status` = cancelled or a past-end-date → effective Completed) and asserts the grid read-model emits the EFFECTIVE status (cancelled/completed), NOT `'open'`. This test FAILS on current code (which emits raw) and PASSES after the fix — the proof the bug is fixed. **Browser verification deferred to Phase 2** (no admin frontend in this phase). **Sibling-site audit:** because B3 centralized the `status` emission, this fix lands in ONE place and the test covers both list + agenda paths through the mapper — confirm both loops feed `effectiveStatuses` into `$context`.

- [ ] **Step 1:** Confirm `getEffectiveStatuses(array): array` signature + that it's already called batched in `getEditionOptions` (`:790`/`:832`). Confirm the mapper `status` key (from B3) is the single emission point.
- [ ] **Step 2:** Write the RED test: seed a cancelled edition + a past-end-date edition; assert the list AND agenda read-model emit effective status. Run → FAIL (emits `'open'`/raw).
- [ ] **Step 3:** In both controller grid loops, batch-resolve `getEffectiveStatuses($editionIds)` (mirror the typeahead) and pass into the mapper `$context`. In `EditionAdminMapper::toItem`, emit `$context['effectiveStatuses'][$id]` for `status` (fallback to the old raw read ONLY if the id is missing, matching the card-partial degradation INV-7 allows — but log if hit).
- [ ] **Step 4:** Run the test → GREEN. Full suites green (expect the new test + ANY existing test that asserted the OLD raw-status behavior to update — if an existing test breaks, it was asserting the bug; fix it to assert effective, and note it as an intended characterization update).
- [ ] **Step 5:** `composer lint` clean. `/drift-reviewer` → clean. Commit.

**Acceptance:** a cancelled/completed edition shows effective status in BOTH grid read-models; the typeahead and the grid now agree (INV-7 satisfied); the ONE behavior change is covered by a test and isolated to this task.

── REVIEW GATE ── (tier: **FULL** — C1 is the behavior change + touches the INV-7 status convergence point + changes the contract the Phase 2 frontend builds to. All finders + `security-sentinel` (it touches a read-model the partner/admin surface shares) + simplicity + `invariant-auditor` on INV-7. `/code-review --effort=high`. Confirm no OTHER behavior moved.)

---

## CLUSTER E — Dead code

### Task DC1: Delete the 4 dead `RegistrationRepository` methods + their test assertions

**Files:**
- Modify: `Modules/Enrollment/RegistrationRepository.php` — DELETE `countConfirmed:2208`, `hasActiveRegistrations:1158`, `hasTrajectoryEnrollments:1177`
- Modify: `Admin/AdminAPIController.php` — DELETE `countUserRegistrations:2464` (superseded by `batchCountUserRegistrations:2483`)
- Modify: `tests/Integration/DashboardCascadeReadPathsTest.php` — remove/repoint `:208`,`:212`,`:231`,`:232` (+ the `:28` docblock)

**Tier B** — pure deletion, no logic. `no unit test: Tier B, deletion of verified-zero-caller code; the existing suite passing after removal IS the proof nothing depended on it.` **Decision for the test:** `hasActiveRegistrations`/`hasTrajectoryEnrollments` are asserted only in `DashboardCascadeReadPathsTest`. The invariant they assert (a cascade child creates active + trajectory enrollments) is STILL worth holding — **re-point those assertions to `findByUser`/`findByTrajectory`** (non-empty result) rather than deleting the coverage. Delete the methods + repoint the assertions.

- [ ] **Step 1:** Re-confirm zero prod callers (ground-truthed: `countConfirmed`=0, `countUserRegistrations`=0, the other two only in the one test).
- [ ] **Step 2:** Re-point `DashboardCascadeReadPathsTest:208/231` to `assertNotEmpty($this->repo->findByUser(...))` and `:212/232` to `findByTrajectory(...)` (preserve the cascade invariant via the live methods).
- [ ] **Step 3:** Delete the 4 methods.
- [ ] **Step 4:** Run full Unit + Integration suites → green (the repointed test still proves the cascade invariant). `composer lint` clean.
- [ ] **Step 5:** Commit.

**Acceptance:** 4 methods gone; the cascade invariant still tested via live methods; suites green.

### Task DC2: Remove the dead `paid_at` read + response key from the dossier

**Files:**
- Modify: `Admin/AdminUserService.php` — remove `'paid_at'` from the meta fetch (`:261`) + the `'paid_at' => ... ?: null` response key (`:310`)
- Test: existing dossier integration test (assert `paid_at` no longer in the response)

**Tier B** — removes a never-populated field. `no unit test: Tier B, removal of a dead read; a one-line assertion that the key is absent is the only check.` But ADD that one assertion (it's the contract the new frontend relies on — no phantom "paid date").

- [ ] **Step 1:** Confirm `paid_at` is never WRITTEN anywhere (gotcha_no_payment_tracking; Exact Online owns invoicing). Grep `paid_at` writes → expect none.
- [ ] **Step 2:** Add an assertion to the dossier test: `paid_at` NOT in the response array. Run → FAIL (still present).
- [ ] **Step 3:** Remove `'paid_at'` from the `:261` fetch list and the `:310` emission.
- [ ] **Step 4:** Run → GREEN. Full suites green. `composer lint` clean. Commit.

**Acceptance:** `paid_at` gone from the dossier contract; the new frontend won't build a never-populating field.

── REVIEW GATE ── (tier: STANDARD — deletions, suite is the proof. `/integration` on the E-cluster diff + 2 finders. Confirm the repointed cascade test still asserts the real invariant.)

---

## CLUSTER F — Perf

### Task S4: Fix the roster N+1 (prime user caches before the loop)

**Files:**
- Modify: `Admin/AdminEditionRosterService.php` — before the row loop in `getRosterForEdition`, call `cache_users($userIds); update_meta_cache('user', $userIds);`
- Test: a test asserting the roster output is unchanged (behavior-preserving); the perf gain is structural, not asserted in unit.

**Tier A** — the cache priming must cover BOTH reads: `displayName()`→`get_userdata` (`:202`) AND the inline `get_user_meta($userId, 'organisation')` (`:119`). A wrong key set silently leaves the N+1. **Test contract:** characterization that the roster rows are identical before/after (correctness preserved); note the O(2N)→O(2) gain in the commit.

- [ ] **Step 1:** Confirm `$userIds` is available before the loop (collect it from the registrations if not). Confirm the two read sites (`:119` meta, `:202` userdata via `displayName`).
- [ ] **Step 2:** Write RED-ish characterization (roster output equality for a multi-user fixture). Run → currently GREEN (output won't change) — this is the behavior-preserving guard, not a failing test; that's correct for a perf task (Tier A, but the assertion is "output unchanged").
- [ ] **Step 3:** Add `cache_users($userIds); update_meta_cache('user', $userIds);` immediately before the loop (mirror `searchUsers:2248` / `exportRegistrations:2810`).
- [ ] **Step 4:** Run → GREEN (unchanged output). Full suites green. `composer lint` clean. Commit (note O(2N)→O(2)).

**Acceptance:** roster output identical; per-row `get_userdata`/`get_user_meta` now hit primed cache.

### Task S5: Single-trajectory fetch — `TrajectoryRepository::findById` + targeted assembly

**Files:**
- Modify: `Modules/Trajectory/TrajectoryRepository.php` — add `findById(int $id): ?object` (single-row, mirrors the options query shape)
- Modify: `Admin/AdminTrajectoryService.php:466` (`getTrajectory`) — assemble ONE trajectory from `findById` instead of re-running the full list assembly
- Test: characterization that `getTrajectory($id)` returns the same single-item shape as before

**Interfaces:**
- Consumes: the D2 `findAdminListRows` assembly shape (reuse the per-item formatting helper D2 extracts).
- Produces: `TrajectoryRepository::findById(int $id): ?object`.

**Tier A** — folds into D2's repo work; the single-item assembly must match what the list produced for that item. **Test contract:** `getTrajectory($id)` output equals the matching item from `getTrajectories()` for a seeded trajectory (so the single + list paths agree — pattern_trajectory_edition_parity).

- [ ] **Step 1:** Read `getTrajectory:466` — confirm it re-runs the FULL list assembly (F1 fixed the 404, not the heavy re-query).
- [ ] **Step 2:** Write RED test: `getTrajectory($id)` equals the list item for that id, AND does NOT depend on list-wide state. Run → FAIL or pass-but-heavy; assert the shape.
- [ ] **Step 3:** Add `TrajectoryRepository::findById`; refactor `getTrajectory` to fetch one + run the shared per-item formatter (the same one D2 uses) — single-trajectory assembly.
- [ ] **Step 4:** Run → GREEN. Full suites green. `composer lint` clean. Commit.

**Acceptance:** `getTrajectory` no longer re-runs the list query; single + list items agree.

### Task S6: Cache `getStats()` in a transient with write-invalidation

**Files:**
- Modify: `Admin/AdminStatsService.php:59` (`getStats`) — wrap in `get_transient('stride_admin_stats')` / `set_transient(..., 60-120s)`; invalidate on registration/quote write
- Modify: the registration + quote write paths (or hook `save_post_vad_quote` + the registration repo write) to `delete_transient('stride_admin_stats')` — mirror the `stride_action_queue` invalidation mechanism (`getActionQueueItems:586`/`:706`)
- Test: a test asserting cache hit returns the cached value + a write busts it

**Tier A** — caching with invalidation has a correctness contract (stale stats after a write is a bug). **Test contract:** (1) two `getStats()` calls hit the transient (the second doesn't re-query — assert via a spy or a mutated-underlying-data-but-same-result check within the window); (2) after a registration/quote write + `delete_transient`, `getStats()` recomputes. The denial/stale path is the load-bearing assertion.

- [ ] **Step 1:** Read `getStats:59` (~15 queries) + the `stride_action_queue` transient pattern (`:586`/`:706`) as the template. Identify the write events that must invalidate (registration create/update, quote create/update).
- [ ] **Step 2:** Write RED tests: cache-hit returns cached; post-write recomputes. Run → FAIL (uncached).
- [ ] **Step 3:** Wrap `getStats` in the transient (60-120s TTL). Add `delete_transient('stride_admin_stats')` to the registration + quote write paths (hook or repo-side).
- [ ] **Step 4:** Run → GREEN. Full suites green. `composer lint` clean. Commit.

**Acceptance:** `getStats` served from transient within the window; busted on registration/quote write; no stale-stat window beyond TTL on non-write.

── REVIEW GATE ── (tier: STANDARD — cache-correctness, behavior-preserving. The S6 stale-path test + S5 list/single parity test must be GREEN. `/integration` on the F-cluster diff + 2 finders + simplicity + `performance-oracle` (this cluster IS hot-path work — the one place performance-oracle is warranted under STANDARD). One-way escalate to FULL only if a finder finds a correctness defect on a shared surface.)

---

## CLUSTER G — Security hardening (FULL)

> This cluster is the threat-model's three mitigations. `/security-review` is MANDATORY (a plan-time `## Threat model` exists). All three are Tier A with a denial-path test contract.

### Task S1: Company-scope the trajectory-child fetch

**Files:**
- Modify: `Modules/Enrollment/RegistrationRepository.php:1061` — `findByParents(array $parentIds, ?int $companyId = null): array` (append `AND company_id = %d` when non-null)
- Modify: `Modules/PartnerAPI/PartnerAPIController.php:218` (list path) + `:386` (single path) — pass the partner's resolved `company_id`
- Test: `tests/Integration/PartnerAPIIntegrationTest.php` — cross-company child denial

**Interfaces:**
- Produces: `RegistrationRepository::findByParents(array $parentIds, ?int $companyId = null): array`.

**Tier A — tenancy boundary (1a surface).** **Test contract:** seed two companies; company A's partner queries enrollments whose trajectory PARENT is A's but with a CHILD row mislabeled company B — assert the child is NOT returned (the denial path is the whole point). Also assert the null-default branch still returns all children (internal-caller compatibility — but first ground-truth no internal caller depends on cross-company children).

- [ ] **Step 1:** Confirm `findByParents:1061` current signature + both partner call sites (`:218`,`:386`). Confirm how the partner's `company_id` is resolved in the controller (`_stride_company_id`).
- [ ] **Step 2:** Write the RED denial test (cross-company child not returned). Run → FAIL (currently returned).
- [ ] **Step 3:** Extend `findByParents` with `?int $companyId = null` + the `AND company_id = %d` `$wpdb->prepare` predicate on the non-null branch.
- [ ] **Step 4:** Pass `$companyId` at both partner call sites.
- [ ] **Step 5:** Run → GREEN (denial holds). Full suites green. `composer lint` clean.
- [ ] **Step 6:** `/drift-reviewer Modules/PartnerAPI/PartnerAPIController.php Modules/Enrollment/RegistrationRepository.php` → clean (INV-1 "scoping pushed down to the repo" satisfied). Commit.

**Acceptance:** cross-company children denied; INV-1 company-scoping-in-the-repo satisfied; null default preserves internal callers.

### Task S2: Match the impersonate route gate to the real authority (`manage_options`)

**Files:**
- Modify: `Admin/AdminAPIController.php:489` — change the impersonate route `permission_callback` from `[$this, 'canManageAdmin']` to a `manage_options` gate (add `canImpersonate(): bool` returning `current_user_can('manage_options')`, or inline closure)
- Test: `tests/Integration/` — a `stride_manage`-only (coordinator) user is denied at the route

**Tier A — auth/capability (1a surface).** **Test contract:** a user with `stride_manage` but NOT `manage_options` gets a 403 from the route `permission_callback` (denial at the gate, not just the body). A `manage_options` user passes the gate. This proves the entry-point authority now matches the body.

- [ ] **Step 1:** Confirm the route at `:489` reads `canManageAdmin` (which checks `stride_manage`) while the body `:2576` enforces `manage_options`. Confirm `canManageAdmin` is shared by other routes (do NOT change it globally — only the impersonate route's callback).
- [ ] **Step 2:** Write the RED test: a `stride_coordinator` (stride_manage, no manage_options) is denied at the impersonate route. Run → FAIL (currently passes the gate, only the body stops them).
- [ ] **Step 3:** Add `canImpersonate(): bool { return current_user_can('manage_options'); }` and set it as the impersonate route's `permission_callback` (keep the body's `validateTarget` as defense-in-depth).
- [ ] **Step 4:** Run → GREEN (coordinator denied at gate; admin passes). Full suites green. `composer lint` clean.
- [ ] **Step 5:** `/drift-reviewer` → clean (INV-1 entry-point authorization). Commit.

**Acceptance:** impersonate gate requires `manage_options`; coordinator denied at the route; `canManageAdmin` unchanged for other routes.

### Task N1: Rate-limit `revealSensitiveField`

**Files:**
- Modify: `Admin/AdminAPIController.php:2410` (`revealSensitiveField`) — per-current-user transient rate-limit at the top (after the `$allowed` field check); over-limit → `WP_Error('rate_limited', ..., ['status' => 429])`
- Test: `tests/Integration/` — N+1 reveals returns 429

**Tier A — PII surface (1a).** **Test contract:** drive N allowed reveals then the (N+1)th within the window → assert 429 `WP_Error` (the denial path). A reveal after the window resets succeeds.

- [ ] **Step 1:** Read `revealSensitiveField:2410` — confirm the audit write + the `$allowed` allow-list are already present (they are). Decide N + window (e.g. 30 reveals / 60s — tune to not break legitimate dossier browsing).
- [ ] **Step 2:** Write the RED test: N+1 reveals → 429. Run → FAIL (no limit today).
- [ ] **Step 3:** Add the transient counter keyed `stride_pii_reveal_rl_{get_current_user_id()}`: read count, if `>= N` return `WP_Error('rate_limited', __('Te veel aanvragen...', 'stride'), ['status' => 429])`; else increment + set with the window TTL. Place it AFTER param validation, BEFORE the meta read. (This also brings the method's other envelopes to `WP_Error` — coordinate with C2 if C2 already converted `:2417`/`:2421`.)
- [ ] **Step 4:** Run → GREEN (429 on over-limit; reset after window). Full suites green. `composer lint` clean.
- [ ] **Step 5:** `/drift-reviewer` → clean. Commit.

**Acceptance:** reveals rate-limited per user; over-limit returns 429 `WP_Error`; audit write preserved.

── REVIEW GATE ── (tier: **FULL** — all three touch 1a surfaces. All finders + `security-sentinel` (mandatory) + simplicity + `invariant-auditor` on INV-1. `/code-review --effort=high` AND `/security-review` (mandatory — threat model fired) against the three named mitigations. One-way escalation already at ceiling.)

---

## Stage 3 — phase close (after all clusters)

1. **Integration gate:** full Unit + Integration suites green (`--testsuite Unit` + `-c phpunit-integration.xml.dist`); `composer lint` clean; verify on CI (`gh run watch`), not local-only (gotcha_ci_green_local_red).
2. **Test-effectiveness audit** over the branch diff — walk the seven green-but-blind modes, focus on: the C1 effective-status assertion actually goes RED on raw-status regression; the S1 cross-company denial goes RED if the predicate is dropped; the S2 coordinator-denial and N1 429 go RED if the guards are removed. Record the covered/blind/fixed manifest.
3. **Feature-acceptance:** N/A for the BROWSER layer this phase (no admin frontend) — but the API-layer flows (partner enrollments scoping, impersonate denial, reveal 429, quote/trajectory/export read-models) are driven through the un-mocked REST wire as the acceptance manifest. No UI flow is `pass` here; UI acceptance is Phase 2.
4. **Shake-out / `/shakeout`:** branch tier = **FULL** (the branch touches 1a surfaces in cluster G + the INV-7 contract change). Panel = `reviewer` + `code-simplicity-reviewer` + `security-sentinel` + `performance-oracle` + `invariant-auditor` + `ntdst-drift-reviewer` (WP). Convergence target = this plan's Threat model + INV-3/INV-7 cites + the WP requirement blocks.
5. **Finish:** `superpowers:finishing-a-development-branch`.
6. **Compound (spec-close):** patch `docs/architecture/CODE-MAP.md` with the new `Admin/*Service` + mapper layer; `/skill-audit` scoped to the touched skills. The INV-3 "actively-draining" note updates with the new line count (target: controller well below 2,877).

---

## Self-review

- **Spec coverage:** C1✓(D/C1), C2✓(D/C2), D1✓, D2✓, D3✓, D4✓, B3✓(C), dead-code 4 methods + paid_at✓(E/DC1,DC2), S4✓, S5✓, S6✓, S1✓, S2✓, N1✓. The "Also consider" `getPendingApprovals`→AdminApprovalQueueService and `getEditions`/`getEditionsAgendaView`→AdminEditionListService are NOT separate tasks — B3 already extracts the shared edition shaping, and the further service extraction is lower-priority drift left for a follow-up (noted, not in this phase's clusters to keep clusters ≤4 and the diff reviewable). Flag if the user wants them in-scope.
- **Type consistency:** repo methods named `countAdminList`/`findAdminListRows` consistently (Edition template → Quote D1, Trajectory D2); `findByParents($parentIds, ?int $companyId)` consistent S1; `getEffectiveStatuses(array): array` (plural, batched) used in C1.
- **Placeholder scan:** no TBD/"handle edge cases"; every task has concrete files, line anchors (ground-truthed), and a test contract.

---

## Deferred follow-up (approved 2026-06-24 — NOT in this phase's clusters)

The god-class drain is ~85% in clusters A–G. Two extractions are deliberately deferred to a small follow-up (their SQL is already in repos; this is read-model-formatting extraction only, lowest risk, no contract change):
- `getPendingApprovals` (`:1837`, 122 lines) → `AdminApprovalQueueService` (SQL already in repo via `findPendingWithOpenApproval`/`findConfirmedWithOpenPostApproval`; the 3-bucket logic + pagination is the extractable part).
- `getEditions` / `getEditionsAgendaView` SQL-assembly → `AdminEditionListService` (SQL half-moved to `EditionRepository`; the param/where-clause assembly + taxonomy-join helper remain in the controller).

These do NOT block the Phase 2 frontend (the endpoints work + are secure + behavior-stable). Schedule after Phase 2 ships, or fold into a later backend-tidy pass.
