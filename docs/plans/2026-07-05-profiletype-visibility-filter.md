# Plan: Profile-Type Enrollment Gate + Catalog Flag

**Date:** 2026-07-05
**Branch:** `feat/profiletype-enroll-gate`
**Class:** A (multi-task feature, phased)
**Spec-kit graft:** not installed → gates 1a–1g applied as a manual checklist (stated at the seam).

> **Revision note:** an earlier draft of this plan implemented full role-based content *visibility* (per-item catalog filter + menu filter + direct-URL 404 + WP Page rules + allowlist/denylist modes). Per the user (2026-07-05), that was collapsed to a much simpler, less bug-prone shape: **two orthogonal concepts** — (1) a blunt `exclude_from_catalog` boolean (not role-based) to keep internal courses off the normal catalog, and (2) a **profile-type gate at the enrollment seam** (block / minimal-form / auto-voucher). No cross-surface visibility consistency to maintain. The deleted-surface history is recorded in §9.

---

## 1. Intent

Three independent, single-job concepts:

**A. `exclude_from_catalog` flag (not profile-type related).**
A simple boolean on `vad_edition` (and `vad_trajectory`). When true, the enrollable does NOT appear in the normal catalog (`/klassikaal`, `/online`, teasers, course cards). It still exists, still has a page, can be linked directly (e.g. from a hidden/internal page). This is "listed or not," nothing to do with roles.

**B. Profile-type gate at enrollment.**
Driven by the logged-in user's **Stride profile type** (`ProfileTypeService::getUserType($userId)`, primary slug of `_stride_profile_type`; each user has exactly one). At the enroll seam, the enrollable's per-profiletype rules decide three things:
- **block** — this profile type may not enroll (button shows a locked "Niet beschikbaar voor jouw profieltype" state; server ALSO rejects the enroll action).
- **minimal form** — this profile type sees the stripped enrollment form (reuses the existing `'minimal'` form_type: skips type + billing steps).
- **auto-voucher** — this profile type gets a named voucher auto-applied to their quote.

**C. Dashboard "Voor jou" curated links (additive, NOT access control).**
Each WP `page` gets a metabox: "Toon op dashboard voor profieltypes" (a set of profile-type slugs). On login, the user's dashboard shows link cards to the pages whose metabox includes the user's profile type. This is pure *curation* — the page is NOT hidden, NOT 404'd, NOT removed from any menu; it's simply *surfaced* to the right people in their own space. Someone who finds the URL directly can open it — it was never secret. (This replaces the earlier "hide pages from the menu" idea, per the user: a login-time positive "here's what's for you" beats subtractive menu-hiding.)

**Why the three don't interact:** (A) catalog listing = a flag; (B) who may enroll = the enroll gate; (C) what pages are promoted on the dashboard = the per-page metabox. Each is one job in one place. No per-surface visibility filtering, no menu filter, no 404 gate, no allowlist/denylist modeling. B works identically whether or not a course is catalog-listed; C never gates access, only promotes links.

**Config shape.** (⚠️ Storage keys corrected by freshness review — both CPTs use `meta_prefix => '_ntdst_'`, so `getFields()` fields persist `_ntdst_`-prefixed; see §5 Block-2.)
- On `vad_edition` / `vad_trajectory` (registered in each CPT's `getFields()`):
  - field `exclude_from_catalog` — bool → stored as **`_ntdst_exclude_from_catalog`** (concept A).
  - field `profiletype_rules` — json, `{ "<slug>": { "block": bool, "minimal": bool, "voucher": "<code>|null" }, ... }` → stored as **`_ntdst_profiletype_rules`** (concept B). Absent slug / empty map ⇒ that type enrolls normally, full form, no voucher (fail-open at enrollment is correct — default is "anyone may enroll," today's behavior).
- On `page` (NOT ntdst_data-registered — manual `register_post_meta` + save):
  - `_stride_dashboard_profiletypes` — array of profile-type slugs (concept C). Hand-chosen key, no `_ntdst_` prefix; use the SAME literal on save (T10) and read (T11). Page appears on the dashboard for a user whose type is in the array. Empty ⇒ not promoted. Purely additive.

**Deferred:** `sfwd-courses` (pure-LD) restriction; global per-profiletype defaults in Settings; any page *access control* (concept C is promotion only — pages are never hidden or gated).

---

## 2. Golden path: content-type-feature + form-data-flow (deviations named)

- [ ] Built to `golden-paths/content-type-feature.md` (metabox + repository read/write on existing CPTs) and `golden-paths/form-data-flow.md` (the enrollment→quote write flow this hooks into). Read both before task breakdown.
- [ ] **Deviations (named + justified):**
  - **No new CPT** — adds two metas + a metabox to existing `vad_edition`/`vad_trajectory`, plus a policy read at the enroll seam and one catalog-query predicate for the flag. Justified: enrollables exist; we add a gate + a listing flag.
  - **No frontend router/redirect work** — the earlier draft's `template_redirect` gate is GONE. There is no direct-URL access control in this shape (visibility isn't the requirement; enrollment gating is). Justified by the simplification decision.
  - **Pages queried with direct `get_posts`, not a repository** (concept C) — `AbstractRepository` is CPT-only and there is no page repository; direct `get_posts` with a serialized-array `LIKE` meta_query is the established in-codebase idiom (`ProfileTypeService::countUsersWithType`, `UserDashboardService` get_posts calls). Justified: adding a page repository for one query would be more drift, not less.
  - **Dashboard section, not a new tab** — `UserDashboardService::getForYouPages()` + a `partials/voor-jou.php` section in `tab-home.php`, the lower-friction pattern matching existing partials. No new tab wiring.

---

## 3. Architecture: `ProfileTypePolicy` (enroll-time only)

**Convergence point:** the answer to *"for this user's profile type + this enrollable, does enrollment block / use minimal form / auto-apply a voucher?"* is decided in ONE place — `Stride\Modules\User\ProfileTypePolicy` — read at the enroll seam and the form resolver. No surface re-derives it from raw meta.

`ProfileTypePolicy` (plain DI class, `ntdst_get`-resolved — NOT a service, no boot hooks):
```php
final class ProfileTypePolicy {
    public function __construct(
        private readonly ProfileTypeService  $profileTypes,
        private readonly EditionRepository   $editions,      // reads the rules meta on vad_edition
        private readonly TrajectoryRepository $trajectories, // reads the rules meta on vad_trajectory
    ) {}

    // $postType disambiguates edition vs trajectory (an enrollable id is NOT globally typed):
    public function blocksEnrollment(?int $userId, int $enrollableId, string $postType): bool;  // fail-open: no rule ⇒ false
    public function usesMinimalForm(?int $userId, int $enrollableId, string $postType): bool;    // no rule ⇒ false (full form)
    public function autoVoucherCode(?int $userId, int $enrollableId, string $postType): ?string; // no rule ⇒ null
    public function currentUserType(?int $userId): ?string;                                       // wraps getUserType, resolves current user
}
```
No `canView`, no mode, no parent-trajectory lookup, no bulk `filterViewable` — none of the visibility machinery is needed.

> **⚠️ Freshness corrections (2026-07-06) to this section:**
> - **`EnrollableRuleRepository` does NOT exist and should NOT be created.** Rules live as a `getFields()` meta (`profiletype_rules`, json) on each CPT, read through the EXISTING `EditionRepository` / `TrajectoryRepository` (both `extends AbstractRepository`, both expose `getField()`/`updateMeta()`). Adding a rules-only repository is drift — use the two existing repos. T1 registers the field + adds a typed `getProfiletypeRules($id)` / `setProfiletypeRules(...)` accessor on each (a thin typed wrapper over `getField('profiletype_rules', [])`), NOT a new repository class.
> - **An enrollable id is not self-typing.** `blocksEnrollment` etc. take a `$postType` (`vad_edition` | `vad_trajectory`) so the policy reads the right repo. Every call site already knows the type (the edition/trajectory chokepoints, the resolver, the metabox), so threading it is free. This replaces the plan's original `(?int $userId, int $enrollableId)` two-arg signature.

**Note this is NOT an architecture-invariant-worthy convergence point** in the "authorization gate every request routes through" sense — it's one policy consulted at two seams. INV doc gets a one-line note (T3) but this is not a cross-cutting request gate.

---

## 4. Threat model (gate 1a)

> Feature: profile-type gate at the enrollment seam (block/minimal-form/auto-voucher) + a non-role catalog-listing flag. Written 2026-07-05, proactively. Much smaller surface than the earlier visibility draft — there is NO content-hiding claim, so no "existence leak" class. The gate is an *enrollment authorization* control + a *money* control (auto-voucher). This section is the `/code-review` convergence target.

### What we're defending
1. **Enrollment authorization** — that a profile type marked `block` genuinely cannot create a registration for that enrollable, on ANY write path.
2. **Form integrity** — that minimal-vs-full form is server-decided, not client-selectable.
3. **Auto-voucher grant** — that a voucher is auto-applied only to the profile type the rules name, resolved from the user's STORED type, and still subject to full voucher business rules (usage caps, dates, scope, redemption).

### Who we're defending against
- **Logged-in user of a blocked profile type** — IN scope. Tries to enroll anyway via a non-form path.
- **User tampering with the enroll POST** (edition_id, form_type, voucher_code, or a claimed profile type) — IN scope. Cannot self-unblock, self-select minimal form, or claim another type's voucher.
- **Partner integrator** (Partner API) enrolling their company's users — IN scope: a blocked profile type is blocked regardless of who initiates the enrollment.
- **Admin setting rules** — trusted; the metabox save is a normal WP-security surface (nonce/cap/sanitize).
- **Insider with stolen admin creds** — OUT of scope (standard deferral).

### Attacks → Mitigations (paired)

1. **Block bypass via a non-form write path.** Blocked user is stopped at the web form but registrations are created by MANY paths. `EnrollmentService::enroll()` (method `:167`, `create()` `:335`) is the true edition chokepoint (`processEnrollment()` `:1025` is only a wrapper); `TrajectorySelection::enroll()` (`:31`, `create()` `:54`) for trajectories. Direct-URL enroll (`EnrollmentRouter::handleDirectEnrollment` method `:215`, `enroll()` call `:228`) and Partner API (`PartnerAPIController::createEnrollment` method `:614`, `enroll()` `:678` / `TrajectorySelection::enroll()` `:685`) both route THROUGH `enroll()`. **BUT waitlist does NOT** — `registerWaitlist()` (`:502`, `create()` `:557`) calls `create()` directly, never `enroll()`.
   → **M1 (CORRECTED — freshness review 2026-07-06):** the earlier draft's claim that gating `enroll()` "covers waitlist" is **FALSE** — `registerWaitlist()` calls `create()` directly, never `enroll()`. Since a blocked user is blocked from enrolling, they are equally blocked from the thing that *becomes* an enrollment — so the user's own waitlist-JOIN is gated too. Gate placement is **three USER-initiated insertion points**, all at the intent layer:
   - **(a)** `EnrollmentService::enroll()` (`:167`, before `create()` `:335`) — covers web form, direct-URL (`:228`), Partner API edition (`:678`), colleague-enroll.
   - **(b)** `TrajectorySelection::enroll()` (`:54`) — covers trajectory + Partner API trajectory (`:685`).
   - **(c) `EnrollmentService::registerWaitlist()` (`:502`, before `create()` `:557`)** — NEW, mandatory. The user's own waitlist-join; blocked type cannot self-waitlist.

   **The block is a USER-level gate, not an admin gate.** It stops the *user* from self-enrolling or self-waitlisting. It deliberately does NOT re-check at `promoteFromWaitlist()` (`:619`): promotion is an ADMIN action (`BulkRegistrationHandler::handleBulkPromoteWaitlist`), and an admin promoting someone is a trusted, deliberate override — the system must not fight it. So there is no gate (d).

   **Explicitly exempt / not-gated (verified correct by freshness review):**
   - **Interest** — both `EnrollmentService::registerInterest()` (`:462`) and the anonymous `QuestionnaireHandler::handleSubmitInterest()` (`:82`) — pre-enrollment lead signal, no access implication.
   - **Anonymous questionnaire waitlist** `QuestionnaireHandler::handleSubmitWaitlist()` (`:172`) — `user_id=null`, genuinely typeless (no profile type to check) → fail-open. Acceptable: no user, no rule to enforce.
   - **Admin promotion** `promoteFromWaitlist()` (`:619`) — admin override, trusted, not gated (see above).
   - **Cascade children** `TrajectoryCascadeService::createChildRegistration()` (`:520`, `create()` `:569`) — carries the parent's `user_id`; the parent trajectory already passed the gate at (b). Re-blocking a child would WRONGLY break a legitimate trajectory enrollment.

   **Why NOT gate `RegistrationRepository::create()` (the single convergence point) — rejected, with reason:** `create()` is below the intent layer where "enrollment vs interest vs waitlist vs cascade-child" is known. Gating it over-gates: it breaks the cascade (child `create()` would be re-blocked), violates the interest exemption, and it has no clean `$enrollableId` to key on (it receives `edition_id` OR `trajectory_id` as two nullable columns, and for a cascade child the policy-relevant enrollable is the *parent trajectory*, not the child edition it was handed). Policy gates belong at the intent layer; T4 places four intent-layer checks instead of one persistence-layer check. Grep-confirm the complete `create()` caller set (T4): the seven callers above are ALL of them.

2. **Minimal-form coercion.** User tampers with submitted `form_type` to skip billing/type steps.
   → **M2:** `form_type` is server-decided in `EnrollmentFormResolver` via `ProfileTypePolicy::usesMinimalForm()`; the client-sent `form_type` is not trusted for the minimal decision. Recomputed on submit.

3. **Auto-voucher theft / coercion.** User of type A claims type B's richer voucher, or submits an auto-only code as a manual `voucher_code`.
   → **M3:** Auto-voucher code resolved server-side from `ProfileTypePolicy::autoVoucherCode($userId, …)` using the STORED profile type (usermeta), never a client-sent type. Applied via `QuoteService::applyVoucher($quoteId, $code)` which runs full `VoucherService::validateVoucher` (scope/date/usage) + redeem (`used_count` moves — closes the createQuote-doesn't-redeem gap the exploration flagged).

4. **Locked-state UI is cosmetic only.** The greyed enroll button is presentation; a crafted POST ignores it.
   → **M4:** The locked state is defense-in-depth ONLY. The authoritative block is M1 (server-side at the chokepoint). Tests assert the SERVER blocks, independent of the button state.

5. **Rules / flag / dashboard-links metabox save (admin surface).** Malformed rule data, flag, or page-slug list persisted.
   → **M5:** Every metabox save = nonce (`check_admin_referer`) + cap (post-type edit cap) + sanitize. Enrollable box: rule keys validated against known profile-type slugs (`ProfileTypeService::getTypes()`, **unknown slugs dropped AND surfaced via an admin notice**), booleans cast, voucher `sanitize_text_field` + existence-checked; `_stride_exclude_from_catalog` bool cast. Page box: `_stride_dashboard_profiletypes` sanitized to a slug-allowlisted array. Write through the repository.

6. **Dashboard curated-link render (concept C).** The dashboard shows page links for the user's type.
   → **M6:** This is a READ of the current user's OWN type + a `meta_query` for pages promoting it — no privilege boundary crossed (it only surfaces links the user could already visit; pages aren't gated). Escape: `esc_url` the link, `esc_html` the title. The query filters by the *logged-in user's stored type* (`ProfileTypePolicy::currentUserType`), never a request param — so a user can't enumerate another type's promoted pages by tampering. Low-risk; listed for completeness, not a boundary.

### Out of scope (explicit deferrals)
- **Content hiding / existence secrecy** — this feature does NOT hide content. A blocked course is visible and browsable; only enrollment is gated. Dashboard curation (C) *promotes* pages, never hides them — a non-promoted page is still directly reachable. If a course must be truly off the catalog, use the `exclude_from_catalog` flag (not a security boundary). Documented so reviewers don't raise "the page/course is still visible" as a finding.
- **Deleted-profile-type edge** — if a user's stored slug is deleted, `getUserType` returns null → they fail-open to "not blocked." Acceptable: the default is "may enroll," and a deleted type has no rules to enforce. (No silent *un-restriction of hidden content* concern here, because nothing is hidden.)
- `sfwd-courses` gating; global Settings defaults.

### How to use this section
- Controller pre-flight: verify M1–M5 present in the relevant task diffs before closing each cluster.
- `/code-review`: "Verify against M1–M5. Report each in-place / missing / out-of-scope."
- Downstream: cross-reference; extend only if the surface grows.

---

## 5. WP security requirements (per data-flow) — Block 1

- [ ] **Rules+flag metabox save** (`save_post` on edition/trajectory): nonce + `current_user_can` CPT edit-cap + sanitize (slug-allowlist, bool casts, voucher validate) + no output. Repository write, not raw `update_post_meta`.
- [ ] **Enroll block (M1)**: authorize via policy on the (already `absint`'d) `edition_id`/`trajectory_id`; return `WP_Error`. No new sanitize surface.
- [ ] **Form-type server decision (M2)**: no client input trusted; escape n/a (server bool).
- [ ] **Auto-voucher resolve+apply (M3)**: server-resolved code through `QuoteService::applyVoucher` (already validates). No new echo.
- [ ] **Catalog flag predicate**: reads a bool meta; no user-supplied filter param. sanitize/escape n/a (internal predicate).
- [ ] **Locked-state render (M4)**: `esc_html` the message; the button state is presentation only.

### ntdst-core layering requirements — Block 2
- [ ] Rule + flag meta read/write through a Repository — no raw `get_post_meta`/`update_post_meta` outside `*Repository.php`.
- [ ] `ProfileTypePolicy` is a plain DI class (no `NTDST_Service_Meta`) — it adds nothing at boot.
- [ ] No pure pass-through: the policy adds rule-resolution + fail-open logic; not a rename of `ProfileTypeService`.
- [ ] Data API vocabulary: `profiletype_rules` (json) + `exclude_from_catalog` (bool) registered in the CPT `getFields()`. **⚠️ STORAGE-KEY NOTE (freshness review 2026-07-06):** both `EditionCPT` (`:22`) and `TrajectoryCPT` (`:21`) register with `'meta_prefix' => '_ntdst_'`. A field declared as `exclude_from_catalog` in `getFields()` therefore physically persists as **`_ntdst_exclude_from_catalog`**, NOT `_stride_…`. This matters at every RAW read that cannot go through `getField()`: the theme `get_posts` (`catalog.php:435`), the trajectory builder (`TrajectoryRepository::findActive`), and the page dashboard `meta_query`. Those MUST query `_ntdst_exclude_from_catalog` / `_ntdst_dashboard_profiletypes` or read≠write silently. (The `page` CPT is NOT registered via ntdst_data — its `_stride_dashboard_profiletypes` key must be chosen explicitly and used consistently on both the `register_post_meta`/save side and the `get_posts` read side; pick ONE and pin it in T10/T11.) Wherever possible read via the repository `getField()` (which applies the prefix); only the three raw paths above need the literal key.
- [ ] No hardcoded meta prefix in NEW code — use `getField()`/field registration. Where a raw `meta_query` is unavoidable (the three paths above), the literal `_ntdst_`-prefixed key is correct and required; comment it with why.
- [ ] Correct module layering: `ProfileTypePolicy` under `Modules/User/`; enroll-gate edits in `Modules/Enrollment`; metabox in the existing Edition/Trajectory admin controllers.

> **Convergence contract:** Blocks 0–2 + Threat model M1–M5 are the convergence target for `/code-review` + `ntdst-drift-reviewer` at shake-out. A gap is a one-line finding keyed to a named item.

---

## 6. Sibling-site audit blocks (gate 1e)

**§6.1 — Catalog-flag enumeration paths.** The `exclude_from_catalog` predicate must be applied everywhere the catalog is assembled (or explicitly ruled out):
- [ ] **Primary list** — add the flag clause in `EditionRepository::findCatalogEligibleIds()` (**`:176`**, meta_query built via `catalogDateWindowMetaQuery()` `:110`, appended `:190`, `WP_Query` `:197`). Freshness review confirmed this is the SINGLE convergence point for klassikaal (null filter) + online (course filter) + the AJAX `stride_catalog_page` slice (`CatalogEndpoint::handleCatalogPage` → `stridence_catalog_items` → `EditionService::getCatalogItems()` `:503` → `findCatalogEligibleIds()`). One `_ntdst_exclude_from_catalog != true` / `NOT EXISTS` clause here covers all three. (NOT `hydrateEditionItems()` `:674` — that only hydrates IDs already produced; it is not a query and cannot filter.)
- [ ] **Teasers** — `EditionService::getArchiveTeaserItems()` (`:600`) hydrates; the actual query is `EditionRepository::findArchiveClassroomTeaserIds()` (`:229`, a deliberately different active-only/no-date query). Add the flag clause THERE. Separate path.
- [ ] **Course cards (theme)** — `stridence_prefetch_course_cards()` (function decl `catalog.php:418`, own `get_posts` at **`:435`**, meta_query `:440-451`). Add the flag as a meta_query element using the literal `_ntdst_exclude_from_catalog` key (raw path, no repo). Riskiest to forget.
- [ ] **Trajectory cards** — RESOLVED by freshness review: assembled by `TrajectoryRepository::findActive()` (**`TrajectoryRepository.php:52`**), rendered from `archive-vad_trajectory.php:21`. ⚠️ This uses the fluent **query-builder** (`model()->whereIn('status',…)->orderBy(…)->withMeta()->get()`), NOT a WP_Query `meta_query`. The flag clause here is a builder predicate (a `where`/`whereMeta`-style call on `_ntdst_exclude_from_catalog`), written differently from the three meta_query paths above. Single trajectory-catalog point; no separate AJAX/pagination path (`limit(-1)`, server-rendered).

**§6.2 — Enroll-seam chokepoints (M1).** All SEVEN `RegistrationRepository::create()` callers classified (freshness review 2026-07-06 — this is the complete grep-confirmed set):
- [ ] `EnrollmentService::enroll()` (method `:167`, `create()` `:335`) — **GATE (a)**. Covers web form, direct-URL (`:228`), Partner API edition (`:678`), colleague-enroll.
- [ ] `TrajectorySelection::enroll()` (`:31`, `create()` `:54`) — **GATE (b)**. Covers trajectory + Partner API trajectory (`:685`).
- [ ] `EnrollmentService::registerWaitlist()` (`:502`, `create()` `:557`) — **GATE (c) — NEW.** User's own waitlist-join; does NOT route through `enroll()`; the earlier "enroll covers waitlist" claim was false (M1 corrected). Gate before `create()`.
- [ ] `EnrollmentService::promoteFromWaitlist()` (`:619`) — **NOT gated** (admin override, trusted — see M1).
- [ ] `EnrollmentService::registerInterest()` (`:462`) — **exempt** (lead signal). Tested.
- [ ] `QuestionnaireHandler::handleSubmitInterest()` (`:82`) — **exempt**, anonymous lead. Tested (no block).
- [ ] `QuestionnaireHandler::handleSubmitWaitlist()` (`:172`) — anonymous (`user_id=null`), no profile type → fail-open. Tested.
- [ ] `TrajectoryCascadeService::createChildRegistration()` (`:520`, `create()` `:569`) — **no re-block** (parent trajectory already gated; carries parent user_id). Tested.
- [ ] Confirmed: NO caller outside this set. `create()`-convergence gating rejected (over-gates cascade/interest; no clean enrollableId) — see M1.

**§6.3 — Enroll→quote paths (form + voucher).** Edition path event-driven; trajectory path inline. BOTH need M2 + M3:
- [ ] Minimal-form: `EnrollmentFormResolver::resolveEdition()` (:111) AND `resolveTrajectory()` (:143).
- [ ] Auto-voucher: edition `EnrollmentQuoteHandler::onRegistrationCreated()` (`Handlers/EnrollmentQuoteHandler.php:31`, fired by `stride/registration/created` dispatched at `EnrollmentService.php:370`) AND trajectory `EnrollmentFormHandler::createTrajectoryQuote()` (method `:361`, voucher block `:388–403`). ⚠️ Freshness review: BOTH existing paths currently `validateVoucher` + `calculateDiscount` only — **neither calls `redeemVoucher`** (`used_count` never moves). Redemption lives ONLY in `QuoteService::applyVoucher()` (`:601`, redeems at `:668` via `VoucherService::redeemVoucher` `:195` under `SELECT … FOR UPDATE`). T8/T9 MUST route the auto-voucher through `applyVoucher` (as the plan already specifies) — this is the "createQuote-doesn't-redeem gap" (M3), now confirmed still open in current source.
  - **Known limitation (web-form-only).** The trajectory auto-voucher fires ONLY on the web-form path: `createTrajectoryQuote` is inline, not event-driven like the edition `EnrollmentQuoteHandler` (which listens on `stride/registration/created`). A **Partner-API** trajectory enroll creates no quote and thus no auto-voucher. Backlog: unify trajectory quoting onto the edition event path (separate feature) — do NOT event-drive it here.

**§6.4 — Dashboard "Voor jou" (concept C).** Single render path — no sibling surfaces:
- [ ] Assembler: `UserDashboardService::getForYouPages(int $userId)` (`UserDashboardService.php` — insert near `buildActiveTrajectories()`; wire into `getHomeData()` `:102` return). Resolve type slug via `ProfileTypeService::getUserType()` (`:73`), `get_posts` page `meta_query` on `_stride_dashboard_profiletypes` (serialized-array `LIKE`). ⚠️ Freshness note: `countUsersWithType()` (`:137`) is a RAW `$wpdb` query, not a `get_posts` — reuse only its LIKE *pattern* (`'%"' . esc_like($slug) . '"%'`, `compare => 'LIKE'`), there is no copyable `get_posts` helper. The `page` CPT is NOT ntdst_data-registered, so `_stride_dashboard_profiletypes` is a hand-chosen key — use the SAME literal on the `register_post_meta`/save side (T10) and this read side (no `_ntdst_` prefix applies here; page meta is manual).
- [ ] Render: new partial `templates/dashboard/partials/voor-jou.php`, included in `tab-home.php` (after stat-cards `:101`), gated on `!empty($forYouLinks)`. `esc_url`/`esc_html`.
- [ ] Page has no repository — direct `get_posts` is the in-codebase norm (documented deviation).

---

## 7. Phases, tasks & review clusters

**Loop budget:** ~11 tasks + 4 review clusters + slack ≈ 16 iterations. (T4 widened to 4 gate points and T5 has a builder-vs-meta_query split, but both remain single tasks; budget unchanged.)

Test tiers per `testing-workflow`. `Test-author` mode per D1: Tier-A security-boundary → `split`; Tier-A logic → `solo`; Tier-B → `solo — Tier B`. No `[HUMAN]` yield points (additive meta-only).

### Phase 1 — Data + Policy core

- **T1. Metas + repository.** Register `_stride_profiletype_rules` (json) + `_stride_exclude_from_catalog` (bool) on `vad_edition`/`vad_trajectory` `getFields()`; repository get/set.
  Unit test: rule map + flag round-trip; empty/legacy → `[]`/`false`. `Tier A` · `Test-author: solo — pure data round-trip`
- **T2. `ProfileTypePolicy`.** `blocksEnrollment` / `usesMinimalForm` / `autoVoucherCode` / `currentUserType`, fail-open (no rule ⇒ not blocked / full form / no voucher).
  Unit test: block:true→blocks; absent→not blocked; minimal:true→minimal; voucher resolves for type, null for others; logged-out→no-type→not blocked. **Denial path (blocked type) mandatory.** `Tier A` · `Test-author: split` (enroll authorization core)
- **T3. One-line note in `ARCHITECTURE-INVARIANTS.md`** (policy is the single enroll-gate decision point). `Tier B` · `Test-author: solo — Tier B (doc)`

`── REVIEW GATE ──` **Cluster 1** (T1–T3) · tier **FULL** (data + authorization core). `/integration` + `/code-review`.
Integration gate: policy reads real meta through the repository; fail-open verified on a no-rules fixture.

### Phase 2 — Catalog flag + Enroll gate

- **T4. Enroll-chokepoint block (M1) — THREE user-initiated gate points.** Gate at `EnrollmentService::enroll()` (a) + `TrajectorySelection::enroll()` (b) + **`registerWaitlist()` (c, NEW)** → `WP_Error`/blocked if the user's stored type is blocked. Block is USER-level: admin `promoteFromWaitlist()` is a trusted override, NOT gated. `create()`-convergence gating rejected (see M1). §6.2 lists all seven `create()` callers; classify interest ×2 (exempt), anonymous waitlist (fail-open), admin promotion (not gated), cascade (no re-block).
  Unit/integration: blocked type via **web form, direct-URL, Partner API, AND waitlist-join** → blocked; allowed type → proceeds; blocked-type **interest** → succeeds (exempt); cascade child rows still created; **admin promotion of a blocked-type waitlist row → still succeeds (admin override).** **Denial path across all three user gate points.** `Tier A` · `Test-author: split` (enroll authorization boundary — multi-path incl. waitlist-join)
- **T5. Catalog flag predicate (§6.1).** `exclude_from_catalog` editions/trajectories absent from primary list + teasers + course cards + trajectory cards.
  Integration: flagged enrollable absent from catalog, still reachable by direct URL. `Tier A` · `Test-author: split` (listing correctness; sibling paths)
- **T6. Locked-state UI (M4).** On the course/enroll page, blocked profile type sees a greyed "Niet beschikbaar voor jouw profieltype" state instead of the enroll button. Presentation only; server block is M1.
  Unit/view: blocked type → locked state; allowed type → enroll button. `Tier A` · `Test-author: solo — presentational; real gate is T4`

`── REVIEW GATE ──` **Cluster 2** (T4–T6) · tier **FULL** (enroll authorization + 1a surface). `/integration` + `/code-review` + `/security-review`.
Integration gate: blocked type cannot self-enroll or self-waitlist via ANY user path (web/direct-URL/Partner API/waitlist-join); admin promotion is an intentional override and still works; flagged courses off-catalog; locked state shows but server is authoritative.

### Phase 3 — Minimal form + Auto-voucher

- **T7. Server-decided minimal form (M2).** `EnrollmentFormResolver::resolveEdition()` + `resolveTrajectory()`; ignore client `form_type` for the minimal decision.
  Unit: policy minimal:true → 'minimal' regardless of client input; else 'default'. `Tier A` · `Test-author: split` (client-input-not-trusted)
- **T8. Auto-voucher (M3), edition path.** `autoVoucherCode` from stored type; apply via `QuoteService::applyVoucher` post-create; redemption correct.
  Unit/integration: correct code for type, null for others; applyVoucher validates + redeems (`used_count` moves); expired/over-cap code → rejected, enroll still succeeds w/o discount. **Denial path (wrong type → no voucher) mandatory.** `Tier A` · `Test-author: split` (money boundary)
- **T9. Auto-voucher trajectory parity + rules/flag metabox (M5).** Trajectory inline path gets the same auto-voucher; metabox UI on Edition + Trajectory admin — per-profiletype rows {block, minimal, voucher} + the `exclude_from_catalog` checkbox; nonce+cap+sanitize+unknown-slug-surfaced.
  Integration: trajectory auto-voucher applies+redeems; metabox save round-trips; unknown slug dropped+noticed; bad nonce rejected; non-cap user rejected. **Denial path mandatory.** `Tier A` · `Test-author: split` (money + admin-write + cap boundary)

`── REVIEW GATE ──` **Cluster 3** (T7–T9) · tier **FULL** (money + admin-write + cap + input-trust). `/integration` + `/code-review` + `/security-review`.
Integration gate: minimal-form server-authoritative; auto-voucher applies+redeems for the right type on BOTH paths; metabox cap+nonce-guarded.

### Phase 4 — Dashboard "Voor jou" curated links (concept C)

- **T10. Page metabox `_stride_dashboard_profiletypes` (M5).** `add_meta_box` on `page` ("Toon op dashboard voor profieltypes" — checkboxes sourced from `ProfileTypeService::getTypes()`). Save on `save_post_page`: nonce + `current_user_can('edit_page', $id)` + slug-allowlist sanitize to array. `register_post_meta` so it round-trips in the block editor.
  Integration: save round-trips; unknown slug dropped; bad nonce rejected; non-`edit_page` user rejected. **Denial path mandatory.** `Tier A` · `Test-author: split` (admin write + cap boundary)
- **T11. Dashboard "Voor jou" section (M6/§6.4).** `UserDashboardService::getForYouPages($userId)` (get_posts meta_query by current user's type slug) → wired into `getHomeData()`; `partials/voor-jou.php` rendered in `tab-home.php`, gated on non-empty. `esc_url`/`esc_html`.
  Unit/integration: user of type X sees link cards to pages promoting X; user of type Y does NOT see X-only pages; no type / no promoted pages → section absent (no empty shell). `Tier A` · `Test-author: split` (per-user data-scoping read — assert wrong-type user does NOT see another type's links)

`── REVIEW GATE ──` **Cluster 4** (T10–T11) · tier **FULL** (admin write + cap + per-user read). `/integration` + `/code-review` + `/security-review`.
Integration gate: a page promoted to type X appears on X-users' dashboards and NOT others'; the page itself is never gated (direct URL still 200 for anyone).

### Phase 5 — Shake-out
- Feature-acceptance drive of the §8 matrix; `ntdst-drift-reviewer` on all touched paths.

---

## 8. Acceptance flows (gate 1g)

| # | Flow | Happy path | Edges |
|---|------|-----------|-------|
| A | Blocked profile type views a course | sees locked "niet beschikbaar" state, no enroll | **denied (server):** blocked type POSTs enroll or self-waitlist anyway (web/direct-URL/Partner API/**waitlist-join**) → WP_Error; **admin override:** admin CAN still promote/enroll a blocked-type user (block is user-level, not admin-level); **empty:** no rules → enrolls normally; **exempt:** blocked type CAN register interest |
| B | Allowed profile type enrolls | full enroll works | **boundary:** type with a rule but block:false → enrolls |
| C | Minimal-form profile type enrolls | sees minimal form (no billing/type step) | **tamper:** client sends form_type=minimal but policy says full → server forces full; **empty:** no rule → full form |
| D | Auto-voucher profile type enrolls | voucher auto-applied, discount + redemption recorded | **denied:** wrong type → no voucher; **boundary:** resolved voucher expired/over-cap → rejected by VoucherService, enroll still succeeds; **concurrent:** near usage cap → applyVoucher row-lock holds |
| E | `exclude_from_catalog` course | absent from catalog list/teasers/cards; reachable by direct URL | **boundary:** flag off → appears normally |
| F | Admin sets rules + flag metabox | rows + flag save, drive A–E | **denied:** non-cap user rejected; bad nonce rejected; **empty:** clearing rows → enrollable enrolls-for-all again; unknown slug → dropped + admin notice |
| G | User logs in, sees "Voor jou" dashboard links | dashboard home shows link cards to pages promoting the user's profile type | **denied/scoping:** type-Y user does NOT see a page promoted only to type X; **empty:** user with no type OR no promoted pages → section absent (not an empty shell); **not-hidden:** a non-promoted page is still directly reachable (C promotes, never gates) |
| H | Admin sets page dashboard metabox | page's "toon voor profieltypes" saves, drives G | **denied:** non-`edit_page` user rejected; bad nonce rejected; unknown slug dropped |

Driven at shake-out (Codeception/WPBrowser backend + browser for metabox + locked-state UI). This matrix is what Phase 4 drives.

---

## 9. History & follow-up

**Simplification (2026-07-05, user):** the original plan built full role-based content *visibility* — per-item catalog filter (+4 sibling paths), nav menu filter, `template_redirect` direct-URL 404, WP-Page rule metabox, `allowlist/denylist` modes, deleted-slug handling, trajectory-cascade visibility rules. All DELETED. Replaced by three orthogonal single-job concepts: (A) a blunt `exclude_from_catalog` flag, (B) an enroll-time profile-type gate, (C) additive dashboard "Voor jou" curated links per profile type. Rationale: the real requirement is "wrong type can't *enroll*" (B) + "surface the right pages to the right people" (C, additive) — not "wrong type can't *see*"; each concept is one decision in one place instead of one hidden thing chased across five surfaces. Far less bug-prone. The adversarial review that shaped the enroll-seam hardening (chokepoint at `enroll()` not `processEnrollment()`; waitlist gated; Partner API covered) carries forward as M1.

**WP-Page evolution:** first "hide pages from the menu (404 + nav filter)" → then dropped entirely → finally (user) reframed as concept C: pages are never hidden or gated; instead the dashboard *promotes* per-profiletype page links at login. Subtractive access-control became additive curation — no security boundary on pages at all.

**Out-of-tree (NOT fixed here):** pre-existing bug `AnnualReportService.php:378` joins usermeta on `meta_key='profile_type'` but the real key is `_stride_profile_type` (array) → annual-report profile-type distribution is empty. Separate fix.

**Deferred features:** `sfwd-courses` gating; global per-profiletype Settings defaults; WP-Page role visibility (served instead by the catalog flag + unlisted page).
