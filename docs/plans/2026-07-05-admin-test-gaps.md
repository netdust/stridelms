# Admin Test-Coverage Gap Analysis — 5 CPTs

**Date:** 2026-07-05
**Scope:** Editions (`vad_edition`), Sessions (`vad_session`), Vouchers (`vad_voucher`), Trajectories (`vad_trajectory`), Quotes (`vad_quote`)
**Method:** Enumerated every feature/action on each CPT's admin page (metaboxes, save handlers, AJAX handlers, list columns), then cross-referenced against the full test suite (Unit / Integration / Acceptance / Playwright). This report lists what is **covered**, what is a **gap**, and the **risk** of each gap.

---

## The headline pattern

Coverage is **excellent on business-logic services** and **read-model/list endpoints**, and **thin on the admin write path**:

- ✅ **Deep**: pricing math, discount/proration math, cascade enrollment, effective-status resolution, elective validation, exporters, list/grid/typeahead read endpoints, authz on AJAX + REST.
- ⚠️ **Shallow / missing**: the `handleSave()` metabox-persist handlers (most CPTs), several AJAX **mutation** handlers (session CRUD, quote status/lock/voucher/discount actions), and a few known **latent bugs** with no regression test.

The admin bugs you're seeing most likely live in the **save handlers and admin AJAX write handlers** — precisely the least-covered layer. Acceptance Cests prove pages *render* and a couple of happy-path CRUDs work, but they don't assert *what got persisted* or exercise the edge/error branches.

---

## 1. EDITION (`vad_edition`)

**Admin surface:** `EditionAdminController` (5 metaboxes, ~14 AJAX handlers), `EditionDuplicator`, `RegistrationModalController`, `EditionActionsMetabox`, `EditionRegistrationMetabox`.

### Well covered ✅
- **Pricing** — `EditionServicePricingTest` (member/non-member, cents-not-euros, filter args, null user).
- **Effective status** — `EditionServiceEffectiveStatusTest` (15-case matrix), plus admin grid/agenda/detail emit effective status (`AdminEditionGridEffectiveStatusTest`).
- **Duplicate** — unit + integration (`EditionDuplicatorIntegrationTest`): draft copy, Kopie suffix, copies registered fields, drops unregistered meta, applies reset list, copies sessions with dates reset, source untouched. Plus acceptance `EditionDuplicateCest`.
- **Exporters** (all 5) — anonymise-exclusion integration tests + zip/bundle + stage-summary + filename collision unit tests.
- **Registration modals** — unit + integration render tests.
- **AJAX authz** — `EditionAdminControllerAuthzTest` (nonce cap gate; mark-attendance / reject / approve-post-course denial + allow).
- **Export route authz** — `AdminEditionExportRouteTest` (unknown-type reject, view-only denied, anon denied, 404, trashed/draft not-reachable).
- **Roster endpoint** — extensive (`AdminEditionRosterTest`, `...RosterEndpointTest`): PII redaction, status filtering, SQL-injection guards, batch reads.
- **Cascade delete** — `EditionCascadeDeleteTest` (edition delete/trash cascades to sessions + registrations).
- **Deadline persistence** — `EditionDeadlineFieldsPersistTest` (gate/post-gate persist, HTML strip, no-clobber-on-omit).
- **Catalog eligibility / teasers / primary-edition / public visibility** — many integration tests.
- **Playwright** — `edition-admin.spec.ts` (~70 E2E) drives list, create, session mgmt, attendance, notes, status sidebar, exports, round-trip.

### Gaps ⚠️

| # | Feature | Status | Risk |
|---|---------|--------|------|
| E-1 | **`handleSave()` price dual-write** (`price` == `price_non_member`, euros→cents at save) | Playwright touches save, but **no assertion-level test that both meta keys get the identical cents value** | Med — a regression here silently mis-prices editions; getPrice read-path is tested but the write-path pairing isn't. |
| E-2 | **`requires_session_selection` is *derived*** at save from any slot's `required` flag (`handleSave` ~:511) | **No test** | Med — subtle derive logic; if it breaks, session-selection gating silently turns on/off. Perfect regression-test candidate. |
| E-3 | **`ajaxBulkLockQuotes`** (bulk lock all quotes for an edition) | Service-level `bulkSetLockedByEdition` is tested in `QuoteUpdateHandlerIntegrationTest`, but the **AJAX handler wrapper** (nonce, cap, response shape) is not | Low-Med — service is covered; only the thin handler is untested. |
| E-4 | **Notes add/delete persist** (JSON `notes` field, type todo/email/userinfo) | Playwright add/delete/persist in UI; **no unit/integration assertion** on the sanitize-and-store round-trip incl. `_deleted` drop | Low — mirrors the quote notes gap (Q-4); UI test exists but not a data-layer test. |
| E-5 | **Speakers repeater save** (add/remove rows, presence marker) | Read-path normalization is well tested (`EditionRepositorySpeakersTest`); **write-path (save handler builds speakers JSON)** is not asserted | Low — read is defensive/tested; write is the untested half. |
| E-6 | **`stride_get_registration_modal` weaker auth** — uses `manage_options` and skips per-edition `edit_post` (unlike sibling handlers) | **No test pins this** | Med (security) — flagged by the mapper as an authz inconsistency. Worth a test that documents/enforces the intended cap. |
| E-7 | **`ajaxGetCourseLessons`** (lesson/quiz picker data for session form) | **No test** | Low — read-only helper. |

---

## 2. SESSION (`vad_session`)

**Admin surface:** No dedicated controller — all session authoring is **inline in the Edition page** (Sessions metabox) via `stride_add_session` / `stride_update_session` / `stride_delete_session`. Slot config (`session_slots`) authored here too.

### Well covered ✅
- **Duration math** — `SessionServiceDurationTest` (single/multi/empty/no-meta).
- **Selection-slot validation** — `SessionSelectionSlotKeysTest` (under/complete/legacy-key/default-1) and `EditionSessionsMetaboxRenderTest` (gate-deadline render + escaping + conditional visibility).
- **Price modifiers** — `session-price-modifiers.spec.ts` (Playwright): field, label, column display (+/−/none), persistence, hint toggle.
- **Cascade/lifecycle** — session deletion on edition delete, draft-session exclusion from lists (via Edition tests).
- **Session mgmt UI** — `edition-admin.spec.ts` add/edit/delete/cancel + type-switching (in_person/webinar/online/assignment field visibility).

### Gaps ⚠️

| # | Feature | Status | Risk |
|---|---------|--------|------|
| S-1 | **`SessionService::createSession` / `updateSession`** (the actual create/update contract) | Playwright drives the UI; **no unit/integration test of the service create/update** (field mapping, type-specific fields, cents conversion of price_modifier) | **High** — this is the core session write path. `sanitizeSessionData` maps posted keys per session type (`EditionAdminController:1181`); no test asserts the mapping is correct per type. A likely home for admin bugs. |
| S-2 | **`ajaxDeleteSession`** does a **hard** `wp_delete_post($id, true)` | **No test** | Med — hard-delete with no test; a wrong id or missing cap check would be silent. Authz is covered generically but delete-specific behavior isn't. |
| S-3 | **Slot config save** (add/edit/remove slot; naam/code/max_selections/required; `selection_open` + `selection_deadline`) | Slot *validation* is tested; **slot save/persist round-trip is not** | Med — slots drive the "kies N uit M" gating; if save mangles slot keys, selection breaks downstream. |
| S-4 | **Attendance marking** (`markPresent/markAbsent/markExcused`, unmarked=delete, bulk mark-all-present) | Playwright drives the grid; authz tested (`...AuthzTest`); **no service-level assertion** on the state machine (present→absent→excused→unmarked cycle, "unmarked deletes record") | Med — attendance feeds completion/hours; the cycle logic deserves a unit test. |

---

## 3. VOUCHER (`vad_voucher`)

**Admin surface:** `VoucherAdminController` (3 metaboxes, `handleSave`, list columns). **No AJAX / no REST of its own** — consumed by the quote flow.

### Well covered ✅
- **Discount math** — `VoucherServiceIntegrationTest` (~28 tests): full/percent/fixed, cap-at-subtotal, 0% / 100% / >100% clamp, never-negative.
- **Proration** — `VoucherProraterTest` + integration (even split, zero/negative sessions → full subtotal).
- **Validation** — status/exhausted/expired/not-yet-valid, edition scope (only/except/all/legacy), wrong/correct edition.
- **Redeem/release** — `VoucherReleaseTest`, `VoucherUnfundedDiscountTest` (transactional consistency with quote totals, re-apply releases prior).
- **Field access** — `VoucherRepositoryTest` (all reads via DataManager, every status round-trips, no raw meta leak).
- **Admin CRUD render** — `AdminVoucherCest` (list, columns, new page, metaboxes, create, edit).

### Gaps ⚠️

| # | Feature | Status | Risk |
|---|---------|--------|------|
| V-1 | **`handleSave()` discount-value cents conversion** — Fixed type ×100 (euros→cents), percent stored as-is (`:494-504`) | Math is tested at service level; **the save-handler's type-conditional cents conversion is not asserted** | **High** — this is a classic off-by-100 trap (cf. the trajectory price cents/euros bug in memory). If Fixed doesn't ×100 on save, every fixed voucher is 100× wrong. No regression test guards it. |
| V-2 | **`handleSave()` code → post_title mirror** + auto-generate on new | `VoucherCodeGenerator` likely tested in isolation; **the save-time mirror to post title and default pre-fill is not asserted** | Low-Med — a broken mirror makes vouchers unsearchable by code in the list. |
| V-3 | **`scope_mode` save round-trip** (all / only+edition_id / except+excluded_edition_ids JSON) | Validation *reads* scope correctly (tested); **save persisting the right shape per mode is not** | Med — scope drives which editions a voucher applies to; a save bug here mis-scopes discounts. |
| V-4 | **`apply_mode` save** (full vs single_session) | Proration math tested; **save persisting apply_mode is not** | Low — small surface. |

> Note: Voucher has **no admin AJAX**, so its entire admin write surface is the one `handleSave`. That single method is the whole gap — and it's untested end-to-end.

---

## 4. TRAJECTORY (`vad_trajectory`)

**Admin surface:** Classic post-edit screen (`TrajectoryAdminController`: 5 metaboxes, `handleSave`, 3 AJAX search/enrollment handlers, list columns) + read-only React grid (GET REST).

### Well covered ✅
- **Cascade** — *exceptionally* thorough: enrollment, selection, cancellation, status-change, backfill, mandatory-children, wiring, parent-child repo. (~10 integration files.)
- **Elective selection** — `TrajectorySelectionFromCoursesTest` (18 tests: edition/pureLD picks, revoke on switch, over/under-pick refusal, lock contention, window-closed, denial paths) + `TrajectorySelectionValidationTest`.
- **Choice window** — boundary logic fully tested.
- **Meta access** — all via DataManager, enum round-trips, no legacy prefix.
- **Descriptive fields / messages** — round-trip + deleted-filter + empty-default.
- **Read endpoints** — list/detail/options/user-trajectories (authz, scope, search, pagination, 404, beyond-100).
- **E2E** — `TrajectoryE2ECest` (13 tests) covers enroll, elective choice, pureLD switch, denials, window states, tabs, cards.

### Gaps ⚠️

| # | Feature | Status | Risk |
|---|---------|--------|------|
| **T-1** | **`pick_count` (save) vs `min_choices` (read) key mismatch** — `handleSave` writes per-course `pick_count` (:1193); `getElectiveGroups` reads `min_choices` (`TrajectoryRepository:348`) | **No test round-trips a group's "kies N" through save→read** | **High (likely a real bug).** Flagged by the mapper. Selection validation tests use `min_choices` fixtures directly, so they'd never catch a save that writes `pick_count`. This is exactly the kind of admin bug you're hunting. **Verify first.** |
| T-2 | **`handleSave()` courses rebuild** — `courses_required[]` + `elective_groups[]` → JSON via `parseCourseItemValue` (JSON + legacy int format) | Cascade tests consume `_ntdst_courses`, but **no test asserts the save handler *produces* the correct JSON** from posted form arrays | **High** — the course builder is the heart of the trajectory admin. If parse/rebuild drops a group or mis-flags required vs elective, every downstream cascade is wrong. Untested. |
| T-3 | **`renderEnrollmentsMetabox` progress bar hardcodes `completedCourses = 0`** (:686) | **No test** (and arguably a bug — always 0%) | Med — cosmetic but misleading admin data. Confirm intended vs bug. |
| T-4 | **`handleSave()` price cents dual-write** (member + non-member euros→cents) | **No test** | Med — same cents trap as V-1/E-1; memory already records a trajectory price cents/euros 100× bug (`bug_trajectory_price_unit_mismatch`). Regression test overdue. |
| T-5 | **`ajaxSearchCoursesAndEditions` hybrid id format** (`edition:<eid>:<cid>` vs `online:<cid>`) | **No test** | Low-Med — if the id encoding drifts, the course builder silently adds wrong items. |
| T-6 | **Lifecycle-boolean save** (requires_questionnaire/documents, post_* toggles, enrollment_form) | **No test** on trajectory save | Med — these gate the whole enrollment flow; editions have partial deadline coverage, trajectory has none. |

---

## 5. QUOTE (`vad_quote`)

**Admin surface:** `QuoteAdminController` (+ `QuoteOverviewMetabox`, `QuoteActionsMetabox`), one AJAX (`stride_get_user_data`), `QuotePDFGenerator`. Rich sidebar of **status/send/voucher/discount/lock/PDF** actions all routed through `handleSave`.

### Well covered ✅
- **Totals math** — `QuoteCalculatorTest` (tax-on-discounted-subtotal, clamps, half-cent rounding) + `QuoteTotalsCharacterizationTest` (admin & service paths agree, remove-discount, apply-voucher).
- **Session modifier items** — `QuoteServiceModifierTest` (10 tests).
- **PDF enrichment** — `QuotePDFGeneratorTest` (money format, company/user data, JSON decode, storage paths).
- **Frontend/API handlers** — `QuoteUpdateHandlerIntegrationTest` (~28 tests): auth, non-owner, missing-id, non-draft, locked-rejection, bulk-lock idempotent/scoped.
- **List service** — `AdminQuoteServiceTest` (status/edition/search filters, envelope shape).
- **Locked-quote GDPR edge** — `DashboardQuoteGdprEdgeCest`.
- **Admin render** — `AdminQuoteCest` (list, table, view existing, edit page loads).

### Gaps ⚠️ — **this is the biggest admin-write gap of the five**

| # | Feature | Status | Risk |
|---|---------|--------|------|
| **Q-1** | **`handleSave()` status transitions** — draft/sent/exported/cancelled; sets `sent_at` on first→sent, `exported_at`+**auto-lock** on→exported, `cancelled_at` on→cancelled (`:311-329`) | **No direct test.** `markAsSent` service is tested, but the **admin save-handler transition logic + side-effects (auto-lock on export, timestamps) is not** | **High** — status + auto-lock-on-export is core admin workflow with real side-effects. The frontend handler is well-tested; the **admin metabox save path is not**. |
| **Q-2** | **Send quote from admin** (`stride_send_quote=1` → fires `stride/quote/send_email`, attaches PDF, `last_sent_to`) | **No test** on the admin send path | **High** — sending a quote email is a user-visible, hard-to-undo action. Untested end-to-end from the save handler. |
| **Q-3** | **Apply voucher / manual discount / remove** *from the admin sidebar* (`stride_apply_voucher`, `stride_apply_discount`, `stride_remove_voucher`) | Service methods tested (`applyVoucher`, `applyManualDiscount`, `removeDiscount`); **the admin save-handler dispatch to them is not** | Med-High — the wiring between the sidebar POST and the service is the untested seam (classic wire gap). |
| **Q-4** | **Lock / unlock from admin** (`stride_lock_action`) | `setLocked` service tested via frontend handler; **admin lock/unlock save path not directly asserted** | Med — overlaps with covered service, but the admin trigger isn't pinned. |
| **Q-5** | **New-quote creation from admin** (`handleNewQuoteCreation`: pick user+edition → build line item, seed billing, quote number, valid_until +30d, title) | **No test** | **High** — this is how quotes are born in the admin. Untested creation logic (price pick member-else-nonmember, billing seed, number gen). |
| **Q-6** | **Regenerate PDF** (`stride_regenerate_pdf=1` → `stride/quote/regenerate_pdf`) | Generator enrichment tested; **admin regenerate trigger not** | Low — generator is covered. |
| **Q-7** | **Cancel-with-registration** checkbox (`stride_cancel_registration` → also cancels registration + revokes access) | **No test** on this admin branch | Med — a destructive cascade toggle with no test. |
| Q-8 | **Undeclared fields written by admin** — `order_number`, `exported_at`, `cancelled_at`, `last_sent_to` are read/written but **not in `getFields()`** | **No test** | Low-Med — bare-key meta bypass; works today but fragile (cf. formatted-read gotchas in memory). |
| Q-9 | **`ajaxGetUserData`** (billing auto-populate on user select) | **No test** | Low — read-only helper, but drives the whole billing prefill UX. |

---

## Prioritized recommendation (where to start)

If the goal is to catch the admin bugs you're seeing, write regression tests in this order — highest-signal first:

1. **T-1** — verify the `pick_count`/`min_choices` round-trip. **This one may already be broken.** Do a save→read test on a trajectory elective group's "kies N". *(Investigate before writing — it might be a bug to fix, not just cover.)*
2. **V-1** — voucher `handleSave` Fixed-discount euros→cents. Off-by-100 trap with prior art in this codebase.
3. **T-4 / E-1** — price cents dual-write on trajectory + edition save (memory already logs a 100× trajectory price bug).
4. **Q-1 / Q-2 / Q-5** — quote admin status-transition (+auto-lock), admin send, admin new-quote creation. The quote **admin** write path is the single largest untested surface.
5. **S-1 / S-3** — `SessionService::createSession/updateSession` field mapping per type, and slot-config save round-trip.
6. **T-2** — trajectory courses-rebuild JSON from posted form arrays.
7. **T-3 / Q-7** — confirm the hardcoded `completedCourses=0` and the cancel-with-registration cascade (decide bug vs intended, then pin).

### The through-line
Every high-priority gap is a **`handleSave()` metabox-persist handler** or an **admin AJAX mutation**. The service layer beneath them is thoroughly tested; the **admin write layer that calls those services is the blind spot** — and that's where admin bugs hide. Acceptance Cests confirm rendering, not persistence correctness.

---

## Method note / caveats
- Feature maps built by reading controllers, metaboxes, JS, and CPT `getFields()` directly (file:line refs available in session).
- Test inventory built by grepping CPT slugs + service names across `tests/Unit`, `tests/Integration`, `tests/acceptance`, `tests/frontend`.
- `tests/manual/shake-*.php` are procedural walkthroughs, not assertions — they exercise flows but don't guard regressions in CI.
- "No test" means no *assertion-level* coverage found; some gaps have Playwright UI coverage (noted) which proves the button works but not what persisted.
- **Two items (T-1, T-3) look like latent bugs, not just gaps** — investigate before writing tests.
