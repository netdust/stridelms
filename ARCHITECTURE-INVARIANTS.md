# Architecture Invariants — Stride LMS

> **What this document is.** For each cross-cutting property of the system there is **one place where it is decided** — a *convergence point*. New code is correct on that axis when it routes through the convergence point, and is suspect (a *bypass*) when it does the work itself instead.
>
> This doc names those convergence points so `/code-review` and `/shakeout` (and the `invariant-auditor` agent) can flag bypasses **mechanically** — "does this path route through the named point, or around it?" — instead of re-discovering the wiring free-form every review.
>
> Sibling to `docs/` threat-models (which name *attacks + mitigations*); this names *convergence points + bypasses*. Authored 2026-06-08 by audit of `web/app/mu-plugins/stride-core` + `web/app/themes/stridence`.

**How to read each invariant:** the **Convergence point** is the single place the property is decided. **The rule** is what new code must do. **Known bypasses** are the existing exceptions — each is either justified (and listed so it's not re-flagged) or tracked debt. **Audit move** is the grep a reviewer runs to find new bypasses.

---

## INV-1 — Authorization is decided at the entry point, by capability

**Convergence points (by surface):**

| Surface | Convergence point | Decides |
|---|---|---|
| Admin REST (`/stride/v1/admin/*`) | `Admin/AdminAPIController::canViewAdmin()` (read) / `canManageAdmin()` (mutate) | `current_user_can('stride_view' \| 'stride_manage')` |
| Assistant REST | `Modules/Assistant/ReadAbilityRegistrar` (`stride_view`) / `WriteAbilityRegistrar` (`stride_manage`) | per-ability `permission_callback` |
| Partner REST (`/stride/v1/partner/*`) | `Modules/PartnerAPI/PartnerAPIController::checkPermission()` | logged-in **+** `partner` role **+** `_stride_company_id` present |
| Frontend AJAX (`ntdst/api_data/*`) | nonce by framework (see INV-2); per-user authz **inside** the handler | ownership / `current_user_can` as business logic requires |
| Admin-page AJAX (legacy controllers) | each controller's own `check_ajax_referer` + `current_user_can('edit_post', …)` | per-handler |

**The rule.** Every REST route MUST declare a `permission_callback` that routes to one of the named methods above — never `'__return_true'`, never an inline closure that re-implements the check. The two custom capabilities `stride_view` / `stride_manage` are registered **once** in `stride-core.php` (roles `stride_coordinator` = manage+view, `stride_supervisor` = view; administrator granted both). New protected surfaces consume those caps; they do not invent parallel caps.

**Company scoping (Partner API) is pushed DOWN, not done inline.** `checkPermission()` proves the caller is a configured partner; the actual data filter is `RegistrationRepository::findByCompany()` (`WHERE company_id = %d`). A partner endpoint that queries registrations any other way is a bypass — scoping must come from the repository method, not a controller-side `array_filter`.

**Known bypasses / notes:**
- Authorization is **convention-based, not enforced by a single chokepoint** — there is no global middleware that intercepts all routes. The invariant is "every route declares one of the named callbacks," verified per-route. A route with no `permission_callback` is the failure mode to hunt.
- Admin-page AJAX controllers (`EditionAdminController`, `QuoteAdminController`, `VoucherAdminController`, `TrajectoryAdminController`, `RegistrationModalController`) each do their own nonce + `edit_post` check. This is acceptable for `wp-admin`-only surfaces but is a *second* authz pattern — when touching these, match the existing per-controller check; don't assume the REST callbacks cover them.

**Audit move:**
```bash
# Routes missing a permission_callback (must be empty):
grep -rn "register_rest_route" --include="*.php" web/app/mu-plugins/stride-core \
  -A6 | grep -B5 "callback" | grep -L "permission_callback"
# New custom caps outside the single registration point (should only be stride-core.php):
grep -rn "add_cap\|'stride_manage'\|'stride_view'" --include="*.php" web/app/mu-plugins/stride-core
```

---

## INV-2 — Frontend AJAX nonce is verified once, by the framework

**Convergence point:** `ntdst-core/api/Endpoints.php:349` — `if (!wp_verify_nonce($nonce, $action))` — gates **every** `ntdst/api_data/{action}` dispatch before the filter fires (`:359`).

**The rule.** Frontend write/read actions register as `add_filter('ntdst/api_data/<action>', $cb, 10, 2)` (or via `Theme.php:536`'s wrapper). The handler receives already-nonce-verified input; it MUST NOT re-verify, and MUST NOT be reachable by any path that skips Endpoints.php. New frontend AJAX = a new `ntdst/api_data/*` filter, never a raw `add_action('wp_ajax_*')` that hand-rolls its own nonce.

This is *why* handlers like `ProfileHandler::handleUpdateProfile()` contain no `wp_verify_nonce` call and are still correct — the nonce was already checked upstream. A reviewer seeing "no nonce in the handler" should confirm it's an `api_data` handler (correct) vs. a raw `wp_ajax_` callback (bypass — must verify its own nonce).

**Known bypasses / notes:**
- Legacy admin-page AJAX (the controllers in INV-1) use `add_action('wp_ajax_*')` + `check_ajax_referer` directly — they predate / sit outside the `api_data` path and verify their own nonce. That is the *correct* pattern for that path; the bypass to flag is a raw `wp_ajax_*` handler with **no** nonce check at all.

**Audit move:**
```bash
# Raw wp_ajax handlers (each must have its own check_ajax_referer/wp_verify_nonce):
grep -rn "add_action('wp_ajax" --include="*.php" web/app/mu-plugins/stride-core
# Frontend handlers should appear as filters, not raw actions:
grep -rn "ntdst/api_data/" --include="*.php" web/app/mu-plugins/stride-core
```

---

## INV-3 — Domain data is reached through the per-domain Repository

**Convergence point:** `Infrastructure/AbstractRepository.php` → `ntdst_data()->get($this->postType)` (`:29`). Every CPT repository (`EditionRepository` `vad_edition`, `SessionRepository` `vad_session`, `TrajectoryRepository` `vad_trajectory`, `QuoteRepository` `vad_quote`, `VoucherRepository` `vad_voucher`) extends it and inherits `find/create/update/delete/all/count/getField/findFields`.

**Custom-table convergence:** the two high-volume tables are owned 1:1 by a repository that is the *only* `$wpdb` caller for that table:
- `wp_vad_registrations` → `Modules/Enrollment/RegistrationRepository.php` (all access, `$wpdb->prepare` throughout)
- `wp_vad_attendance` → `Modules/Attendance/AttendanceRepository.php` (same)
- `wp_options`-backed questionnaire schema → `Modules/Questionnaire/QuestionnaireRepository.php`

**The rule.** No service, handler, admin controller, or template touches CPT data via `ntdst_data()` directly, nor a custom table via `$wpdb`, when a repository method exists. Need a query the repo doesn't expose? Add the method to the repository — don't reach around it. (This is the `pattern_repositories_only` memory, codified.)

**Data API vocabulary (sub-invariant).** Through `ntdst_data()` the keys are `title` / `content` / `excerpt` — **never** `post_title` / `post_content` / `post_excerpt`. Wrong keys are silently dropped (now logged via `ntdst_log('data')->warning()`). Meta prefix is `_ntdst_`, applied by the layer — code passes bare field names (`course_id`, not `_ntdst_course_id`).

**Field-name source of truth.** There is **no** central `FieldRegistry.php` (the CLAUDE.md reference is aspirational/stale on this point). Field names + types live in each CPT's `getFields()` (`EditionCPT::getFields()` etc.), registered via `ntdst_data()->register()`. Treat the CPT class as the schema; don't hardcode `_ntdst_*` meta keys elsewhere.

**Known bypasses (justified — do not re-flag):**
- `EditionService::getRegisteredCount()` — direct `$wpdb` count on `vad_registrations`, 60s cache, performance.
- `EditionService::deleteEditionRegistrations()` — bulk `$wpdb->delete` by edition (no bulk-delete-by-edition in the repo).
- `Infrastructure/BatchQueryHelper.php` — N+1-prevention batch reads across `vad_registrations` / `vad_attendance` / `postmeta` / `term_relationships`.
- `Modules/Edition/EditionFilesZipExporter.php` — export query.
- `RegistrationRepository::offerteVerdelingByGroup()` — correlated `vad_quote` derived-table join (`MIN(published post ID)` per `registration_id`, then the quote's `status` meta) for the grouped-grid offerte tally (FIX-10, 2026-07-03). Cannot reuse `QuoteRepository::findQuoteIdsByRegistrations()` because that materialises a PHP id-map — the exact unbounded id-pull FIX-10 removes; a correlated aggregate must be inline SQL. Mirrors that method's `publish`/`MIN(post ID)` semantic and uses `QuoteCPT::POST_TYPE` (no literal). If a **third** consumer needs the reg→quote resolution, promote it to a shared SQL-fragment provider on `QuoteRepository` and name a "canonical quote of a registration" convergence point; two call sites is tolerable.
- `Admin/AdminAPIController.php` — **accepted zone, actively draining** (`project_unified_api_postlaunch`): the legacy admin controller still carries direct `$wpdb` reads in its un-extracted remnants (`getEditions`, `getQuotes`, `getEditionsAgendaView`, `getEditionRegistrations`). The Admin-Workspace slice (1B–1F, 2026-06-23) **strangled it 4,013 → 2,783 lines / 173 → 76 `$wpdb`** — over half drained. New registration-table query *shapes* go to `RegistrationRepository` as touched (`idsWithCompletionTasks`, `findPendingWithOpenApproval`/`findConfirmedWithOpenPostApproval`; `idsForGridFilter`/`statusBreakdown`/`findByEditionsAndStatuses`; the picker `findEditionOptions`/`countEditionOptions`; `TrajectoryRepository::findTrajectoryOptions`/`countTrajectoryOptions`; `EditionRepository::findAdminActiveIds`). The remaining controller body is accepted until its extraction; the INV-3 advisory listing this file is expected, not a regression.
- `Infrastructure/BatchQueryHelper::batchGetUsersByEmail()` — one prepared `IN()` read on core `wp_users` (get_users has no `email__in` argument). Deliberately minimal (id + display_name for the grid's lead account-match) — NOT a general user-by-email API; a caller needing more goes through `get_user_by`/`get_users`.
- **Admin read-model services** (`Admin/AdminStatsService.php`, `Admin/AdminUserService.php`, `Admin/AdminTrajectoryService.php`, `Admin/Support/AdminBatchHelpers.php`, `Admin/AdminActivityService.php`, `Admin/AdminQuoteService.php`) — **sanctioned read-model layer** (the strangle's extraction targets, 1D/1E + the 2026-06-24 backend-cleanup that drained `AdminAPIController` 2,877 → 2,526). These hold the dashboard/stats/user-detail/trajectory/**activity-audit**/**quote-list** read SQL **moved verbatim out of `AdminAPIController`** (behavior-preserving, `$wpdb->prepare` throughout, concentrated for auditability — the same properties that justified the controller's accepted zone). `AdminActivityService` holds the `audit_log` reads (no matching `AuditRepository` finder exists — documented in its class header); `AdminQuoteService` holds only WHERE-assembly `esc_like`/`postmeta` interpolation (the quote SELECTs themselves moved to `QuoteRepository`). `AdminExportService` needs no exemption — it fully delegates and holds no `$wpdb`. New *write* shapes and net-new registration/edition query shapes still go to the owning repository (e.g. the 1F picker SQL was relocated from the controller into `EditionRepository`/`TrajectoryRepository`, NOT left in a service). A service growing a genuinely-new raw `$wpdb` read that isn't moved-from-the-controller and isn't a repo-worthy shape is still a bypass to flag.

All of the above use `$wpdb->prepare` and are concentrated for auditability. A **new** direct `$wpdb` against these tables outside their owning repository (and not one of the exceptions above) is the bypass to flag.

**Audit move:**
```bash
# $wpdb outside repositories, *Table.php schema classes, and the justified files:
grep -rln '\$wpdb->' --include="*.php" web/app/mu-plugins/stride-core \
  | grep -vE "Repository\.php|Table\.php" \
  | grep -vE "EditionService|BatchQueryHelper|EditionFilesZipExporter" \
  | grep -vE "AdminAPIController|AdminStatsService|AdminUserService|AdminTrajectoryService|AdminBatchHelpers|AdminActivityService|AdminQuoteService"
# Wrong Data API vocabulary:
grep -rn "'post_title'\|'post_content'\|'post_excerpt'" --include="*.php" web/app/mu-plugins/stride-core
# Hardcoded meta prefix outside CPT/getMetaPrefix:
grep -rn "_ntdst_" --include="*.php" web/app/mu-plugins/stride-core | grep -v "getMetaPrefix\|CPT.php"
```

---

## INV-4 — Errors are `WP_Error`, logged once via `ntdst_log`, surfaced by layer

**Convergence point:** the contract `Contracts/RepositoryInterface.php` — *"All repositories return `WP_Error` on failure, never null/false."* `WP_Error` is the single error representation end-to-end (return type `T|WP_Error` at every layer). Logging converges on `ntdst_log('<channel>')->level($msg, $context)` (channels: `enrollment`, `invoicing`, `user-lifecycle`, `data`, …). Defined in `ntdst-core/services/Logger.php`.

**The rule.**
- Failure paths return `WP_Error`, never `null` / `false` / bare `throw` (across the service/repository/handler stack).
- Callers bubble it: `if (is_wp_error($x)) return $x;`. An error MUST be **logged or bubbled** — never assigned-and-ignored, never `if (is_wp_error($x)) return;` (void) with no log.
- **Surfacing by layer:**
  - Frontend AJAX → `wp_send_json_error(['message' => …])` / `wp_send_json_success(…)` (raw, per-handler — see note).
  - REST → return `WP_Error` (WP converts it; status carried in `['status' => 4xx]`), success → `WP_REST_Response`. Partner/Admin controllers put the `WP_Error` in `permission_callback` for authz failures.

**Known bypasses / notes:**
- **AJAX error surfacing is NOT centralized** — every handler calls `wp_send_json_error/success` itself; there is no WP_Error→JSON middleware. This is accepted today; the invariant is "use those two functions + a `message` key," not "route through a helper." If a handler invents a different JSON error shape, flag it.
- The audit found **no** silent-swallow sites at authoring time. The thing to hunt is a *newly introduced* one: an `is_wp_error` branch that neither logs nor returns the error.
- `error_log()` instead of `ntdst_log()` is a bypass — logging must go through the channel API so it lands in the right subscriber.

**Audit move:**
```bash
# Swallowed errors — is_wp_error branch with no log/return on the next lines:
grep -rn "is_wp_error" --include="*.php" web/app/mu-plugins/stride-core -A2 | grep -i "return;\|// ignore\|// skip"
# Raw error_log bypassing the channel logger:
grep -rn "error_log(" --include="*.php" web/app/mu-plugins/stride-core
# Repository methods returning null/false on failure (should be WP_Error):
grep -rn "return false;\|return null;" --include="*Repository.php" web/app/mu-plugins/stride-core
```

---

## INV-5 — Rendering goes through `NTDST_Template_Loader`; the plugin never calls the theme

**Convergence point:** templates resolve through `NTDST_Template_Loader`. `stride-core` registers its own tree at `stride-core.php:27` — `NTDST_Template_Loader::addPath(__DIR__ . '/templates')` — and renders nested templates via `ntdst_response()->html('...')` (e.g. `templates/forms/fields/field-group.php`). Direct `include $templatePath;` of a path *inside* `stride-core/templates/` is the accepted simple form for top-level admin pages.

**The rule (the dependency arrow).** `stride-core` (mu-plugin) MUST NEVER call theme helpers — `stridence_template_part`, `stridence_template_html`, any `stridence_*`, **or the non-prefixed theme helpers** `stride_format_money`, `stride_enrollment_url` (defined in `themes/stridence/helpers/formatting.php`). Plugin→theme inverts the dependency. Plugin-owned partials live in `stride-core/templates/` and render through the loader / `ntdst_response()->html()`. (This is the `gotcha_mu_plugin_no_theme_calls` memory, codified. The original "verified clean" claim covered only the `stridence_` prefix — a grep blind spot, audit finding H-6: 4 `stride_format_date` calls existed in `NotificationMapper` and `StrideMailBridge`. Task C2 — 2026-06-10 — resolved H-6 by moving `stride_format_date` into `stride-core/Support/formatting.php`: it is **core-owned** now, core and theme may both call it, and the check is BLOCKING. The unit-suite contract test is `tests/Unit/Support/FormattingHelpersTest.php` — it pins both the core ownership and the Dutch output.)

**Output escaping (sub-invariant).** Dynamic output is escaped at the sink: `esc_html` (text), `esc_attr` (attributes), `esc_url` (URLs). Alpine `x-text` bindings are intentionally unescaped data (Alpine HTML-escapes on insertion — safe; `x-html` would not be). The one deliberate raw echo (`_tool-header.php` `$attrs`, "caller is trusted") is marked inline — new raw `echo $var` of dynamic data without an `esc_*` is a bypass.

**API envelope note (added by the 2026-07 output-layer reshape):** `NTDST_Response` additionally owns the REST API envelope — `apiSuccess()` / `apiError()` / `toRestResponse()` (see INV-10) — alongside its existing template-render responsibility. The two concerns (page rendering vs. REST envelope) share one class but are additive/separate surfaces; `render()`/`html()`/`json()` behavior is unchanged.

**Audit move:**
```bash
# The forbidden plugin→theme call — pattern covers ALL theme-defined procedural
# helpers (stridence_* prefix + the non-prefixed formatting helpers). Note:
# stride_format_date is core-owned since Task C2 and deliberately NOT in the
# pattern. Re-sweep themes/stridence/helpers/*.php + functions.php for new
# helpers when extending:
grep -rn "stride_format_money\|stride_enrollment_url\|stridence_" --include="*.php" web/app/mu-plugins/stride-core
# must be empty — BLOCKING since Task C2 (2026-06-10)
# Unescaped dynamic echo in templates:
grep -rn "echo \$" --include="*.php" web/app/mu-plugins/stride-core/templates | grep -v "esc_\|(int)\|(float)\|x-text\|wp_kses\|ntdst_response"
```

---

## INV-6 — LearnDash is touched only through `LMSAdapterInterface` (writes) / `LearnDashHelper` (reads)

**Convergence point:** `Contracts/LMSAdapterInterface` (impl `Integrations/LearnDash/LearnDashService`) for **business operations** — `grantAccess`, `revokeAccess`, `isComplete`, `markComplete`, `isOpenCourse`. Read-only presentation goes through the static `LearnDashHelper` (`getProgress`, `getCertificateLink`, `getCompletionDate`, `getCourseAction`, `getLessons`, …).

**The rule.** No stride-core code calls LearnDash functions (`ld_update_course_access`, `learndash_*`, `sfwd_*`) directly. Mutations go through the injected `LMSAdapterInterface` (`ntdst_get(LMSAdapterInterface::class)`); reads go through `LearnDashHelper`. The only legitimate consumers of the write interface are `EnrollmentService`, `TrajectoryCascadeService` and `Modules/Trajectory/TrajectorySelection` (pure-LD elective grant/revoke, 2026-06-12). A new `learndash_*` call anywhere else is a bypass — extend the adapter or the helper instead.

**Entity-model corollary (what LD owns vs. Stride owns).** Course (`sfwd-courses`) = LD content only. Edition (`vad_edition`) = scheduled offering (dates/price/venue/capacity). Session (`vad_session`) = meeting day. Registration (`wp_vad_registrations`) = enrollment. **LearnDash enforces course-completion rules** (lessons, quizzes); Stride defers to it via `isComplete` — attendance alone cannot complete a course that has required LD steps (the `lesson_ld_owns_completion` memory).

**Audit move:**
```bash
# Direct LD calls outside the adapter + helper (excluding event-hook subscriptions + comments):
grep -rn "learndash_\|ld_update_course_access\|sfwd_" --include="*.php" web/app/mu-plugins/stride-core \
  | grep -vE "Integrations/LearnDash/(LearnDashService|LearnDashHelper)\.php" \
  | grep -vE "add_action\(|add_filter\(" \
  | grep -vE ":[0-9]+:\s*(\*|//|#)"
```

---

## INV-6b — Selection state is read through the selection convergence points (`TrajectorySelection` for electives, `SessionSelection`/`getSelections()` for sessions), never the raw `selections` column

**Convergence points:** `wp_vad_registrations.selections` is a single flat-id column read through **two** convergence points depending on the question being asked:

1. **Trajectory electives — which elective courses did this registration pick:** `Modules/Trajectory/TrajectorySelection::getSelectedCourseIds(int): array` (+ `countChosenInGroup()` / `isGroupChosen()` for per-group thresholds).
2. **Session roster — who is in which session:** `Modules/Edition/SessionSelection::hasSelectedSession(int $registrationId, int $sessionId): bool`, over the underlying read `Modules/Enrollment/RegistrationRepository::getSelections(int $registrationId): array`. `getSelections()` is the ONE decode of the raw column; roster code asks "is this registration in this session" through `hasSelectedSession()`, never by joining or decoding `$reg->selections` itself.

**The rule.** `wp_vad_registrations.selections` stores **flat ids** — flat edition ids for the trajectory-electives shape, flat session ids for the session-roster shape — never grouped, never course ids, never an ad-hoc nested shape. Any surface rendering "which elective courses did this registration pick" reads through `getSelectedCourseIds()` (which folds in pure-LD picks from the `initial_selection` phase entries) with per-group chosen state through `isGroupChosen()`; any surface rendering "who is enrolled in which session" reads through `SessionSelection::hasSelectedSession()` / `RegistrationRepository::getSelections()`. Re-deriving from the raw column re-introduces the 2026-06-12 bug class where four templates each invented a different (wrong) shape — and, for the session-roster shape Phase 2a introduces (`AdminEditionRosterService` + the cohort-lens grid JS), a per-row decode of `$reg->selections` is the same bug class waiting to re-open.

**Audit move:**
```bash
# Raw selections reads outside the convergence-point files + repository.
# Exempt the convergence-point FILES (TrajectorySelection / SessionSelection)
# and the repository (the legitimate home of the decode) — NOT the WORD "session":
# the prior `|session` exclusion blanket-exempted any line mentioning a session,
# which would let a new roster service decode the raw column unseen. Phase 2a's
# AdminEditionRosterService MUST be caught here if it bypasses getSelections().
grep -rn "\->selections" --include="*.php" web/app/themes/stridence web/app/mu-plugins/stride-core \
  | grep -vE "TrajectorySelection\.php|RegistrationRepository\.php|SessionSelection\.php"
```

**Known pre-existing direct reads (the landscape this audit move surfaces today — NOT new bypasses; any reader BEYOND this list is a finding):** these legacy surfaces decode `$reg->selections` inline and predate the convergence points. They are the accepted baseline — the audit's signal is "no reader appears here that isn't already on this list," and in particular **no Phase 2a roster code**:
- `themes/stridence/single-vad_edition.php` — edition page, pre-selected session ids for the logged-in user's own registration.
- `themes/stridence/templates/forms/completion/task-session_selection.php` — the session-selection completion form, prefilling the user's own current picks.
- `Modules/Trajectory/TrajectoryCascadeService.php` — copies a parent registration's flat picks when cascading children.
- `Modules/Mail/StrideMailBridge.php` — mail recipient/payload assembly.
- `Modules/User/UserDashboardService.php` — the user's own dashboard session list.
- `Handlers/ICalHandler.php` (×2) — iCal feed for the user's own selected sessions.
- `Admin/AdminUserService.php` — admin single-user view.

When Phase 2a lands `AdminEditionRosterService`, it MUST route through `SessionSelection::hasSelectedSession()` / `getSelections()` so it does NOT add a tenth entry to this list — that is the whole reason this convergence point is named before the roster code ships.

---

## INV-7 — Display status is derived through `getEffectiveStatus()`, not read raw

**Convergence point:** `Modules/Edition/EditionService::getEffectiveStatus(int): OfferingStatus`. Status value objects live in `Domain/` — `OfferingStatus`, `RegistrationStatus`, `AttendanceStatus`, `Money` (`EditionStatus` is a deprecated alias to `OfferingStatus`).

**The rule.** Stored status ≠ display status. Any surface (badge, enrollment gate, form resolution, server-side guard) that decides "can enroll / is this offering open / is it completed" reads through `getEffectiveStatus()`, which layers calendar + session reality over the stored intent (terminal status wins; past end-date → `Completed`; classroom with no published sessions → `Announcement`). Reading the raw stored status meta for a display/gate decision is a bypass — it will drift from what `getEffectiveStatus` shows. (The `lesson_effective_status_pattern` memory, codified.)

**Known weakness / notes:**
- **Status transitions are NOT centralized** — there is no state machine. Writes happen inline (`EditionService::setStatus()`, `EnrollmentService::confirmRegistration()`, `EnrollmentCompletion::completeTask()`). The invariant constrains the **read** side (always via `getEffectiveStatus`), not the write side. A future hardening would funnel transitions through one method; until then, transition logic is reviewed case-by-case.
- **Deliberate degradation (known bypass): the card-partial stored-status fallback.** `partials/card-edition.php` renders the *stored* status when the prefetched `$args['status']` is absent (mid-flow fallback — degraded but never fatal, never a per-card query). This is the one accepted raw-status read on a display surface; any OTHER raw-status read for a gate/display remains a violation.
- **Deliberate bypass (decision 2026-07-14, F-V3): the admin-active scope reads STORED status.** `EditionRepository::findAdminActiveIds()` gates the admin workspace scope (worklist queues, default grid) on the raw `_ntdst_status` meta vs `OfferingStatus::adminClosedValues()` — deliberately NOT `getEffectiveStatus()`. The effective layer's past-end-date → `Completed` inference is exactly the auto-closing this rule removes: an edition must stay admin-active (its post-course approvals/quotes/certificates workable) until the ADMIN closes it. Routing this read through the effective status would silently re-drop editions from every queue days after their sessions — the F-V3 bug. Do not "fix" this back.
- **Catalog-card convergence point:** catalog cards render ONLY through `stridence_catalog_render_cards()` / the batch pre-pass (`stridence_prefetch_edition_cards()` / `stridence_prefetch_course_cards()` in `themes/stridence/helpers/catalog.php`), which feeds INV-7 statuses + enrolled state + spots into the pure-renderer partials. **Bypass signal:** invoking `partials/card-edition` / `partials/card-course` directly without prefetched data — that path silently falls back to stored status (the degradation above) and reintroduces per-card lookups.

**Audit move:**
```bash
# Raw status reads used for a gate/display (should go through getEffectiveStatus):
grep -rn "getStatus(\|->status\b\|'status'" --include="*.php" web/app/mu-plugins/stride-core \
  | grep -iE "enroll|badge|can_|display|gate|->open\b"
```

---

## INV-8 — VAT/totals math is decided once, in `QuoteCalculator`

**Convergence point:** `Modules/Invoicing/Helpers/QuoteCalculator` — `TAX_RATE` (21% BTW) plus the cents-level derivation `deriveTotalsFromCents(int $subtotalCents, int $discountCents = 0): array{subtotal, discount, tax, total}`.

**The rule.** Every quote write path (admin save, manual discount, discount removal, new-quote creation, voucher application, session-modifier recompute) derives subtotal→discount→tax→total through `deriveTotalsFromCents()` — the Money-based `calculateTotals()` (new-quote creation) is a thin wrapper that delegates to it (CR-C2), not a second chain. A hardcoded VAT literal (`0.21`, `1.21`, `21 / 100`) or a re-implemented `round($x * rate)` chain outside the helper is a bypass — six such literals drifted apart before Task C1 consolidated them (audit finding H-5). The discount is clamped to `[0, subtotal]` and the taxable base never goes below zero; the contract is pinned by `tests/Unit/Modules/Invoicing/QuoteCalculatorTest.php` and `tests/Integration/QuoteTotalsCharacterizationTest.php`.

**Audit move:**
```bash
# Hardcoded VAT literal outside the convergence point (must be empty — BLOCKING in check-invariants.sh,
# which additionally scans *.js and carries the documented display-only quote-admin.js exception):
grep -rnE '0\.21|1\.21|21[[:space:]]*/[[:space:]]*100' --include="*.php" web/app/mu-plugins/stride-core \
  | grep -v "Modules/Invoicing/Helpers/QuoteCalculator.php"
```

---

## INV-9 — Anonymous-lead → real-account resolution is decided once, in `resolveLeadAccount()`

**Convergence point:** `Modules/Enrollment/EnrollmentService::resolveLeadAccount(string $email, string $name): array{user_id:int, was_existing:bool}|WP_Error`. This is the single place a pre-account registration becomes a real WordPress account.

**The rule.** Any path that turns an anonymous pre-account registration — `user_id` NULL/0, captured by the public interest/waitlist forms — into a real account does so through this method: `sanitize_email` → `is_email` validate (else `WP_Error('lead_no_email')`) → `get_user_by('email')` find-or-create. **No credentials are ever sent to a found existing account** (the existing-user branch returns before any `wp_new_user_notification`); only a genuinely new account may be mailed a welcome/login link, and that send is gated downstream on `was_existing===false`.

**Meta-on-create reuses the existing convergence, and is NEVER written to a found existing account.** On `was_existing===false`, the captured personal/billing fields are mapped onto the new user via `EnrollmentService::updateUserProfile()` / `getUserMetaMapping()` — never a hand-rolled second mapping. On `was_existing===true` the promote path writes NOTHING to that account (not name, not roles, not `billing_*`/personal usermeta); the lead's captured values stay per-registration in `enrollment_data`. **This mirrors the enroll path's `$isExistingColleague` no-overwrite guard (`EnrollmentService.php:973–980`) — the two paths MUST NOT diverge on this rule** (writing a stranger's billing meta onto a real account is an invoice-integrity vector).

**Sibling that must not drift:** the pre-existing `upgradeFromInterest()` self-enroll path. It also resolves an anonymous interest row to an account; when its resolution shape changes, `resolveLeadAccount()` must be reconciled against it (and vice versa) so the two anonymous-lead → account paths stay one decision.

**Known bypass (tracked debt, user-confirmed deferred):** `resolveParticipant()` (colleague-enroll) still does inline find-or-create and calls `wp_new_user_notification($id, null, 'both')` for **every** resolved user including a pre-existing one — the collision-unsafe *mail* pattern that INV-9 exists to prevent spreading. `resolveLeadAccount()` was deliberately created as the *safe* sibling rather than reusing `resolveParticipant()`; the latter's mail unsafety is NOT fixed here (deferred). Its sibling no-overwrite guard for *meta* already exists at `:973–980`.

**Audit move:**
```bash
# Every account-creation / credential-mail site. Each hit must be inside
# resolveLeadAccount() (the safe convergence point) or the tracked
# resolveParticipant() bypass. PartnerAPIController.php:644 is a pre-existing
# separate-surface creation site, tracked as post-launch debt. A NEW third
# site (anywhere else) is a finding — route it through resolveLeadAccount().
grep -rn "wp_create_user\|wp_new_user_notification" --include="*.php" web/app/mu-plugins/stride-core
# New-account meta must go through the mapping convergence, not ad-hoc keys:
grep -rn "update_user_meta\|wp_update_user" --include="*.php" web/app/mu-plugins/stride-core/Modules/Enrollment
```

---

## INV-10 — Recurring cron jobs register through `ntdst_schedule_recurring()`, not raw `wp_schedule_event`

**Convergence point:** `ntdst_schedule_recurring(string $hook, string $interval, callable $cb): void` in `web/app/mu-plugins/ntdst-coreloader.php` (global helper, `function_exists`-guarded, sits alongside `ntdst_enqueue_admin_toolkit()` / `ntdst_enqueue_api_client()`). Its sibling `ntdst_clear_recurring(string $hook): void` unschedules the same hook.

**The rule.** Any code that needs a recurring WP-Cron job calls `ntdst_schedule_recurring($hook, $interval, $cb)` instead of pairing `wp_next_scheduled()` + `wp_schedule_event()` + `add_action()` by hand. The helper is self-healing (only schedules when nothing is already pending for the hook — repeated calls, e.g. on every page load, never double-schedule) and only accepts a **built-in** WP-Cron interval (`'daily'`, `'hourly'`, `'twicedaily'`, `'weekly'`) — it does not register a custom `cron_schedules` interval, so a caller needing a non-standard cadence must register that interval itself before calling in. To stop the recurrence, call `ntdst_clear_recurring($hook)`.

The callback receives no request data — WP-Cron invokes hooks outside any HTTP request context, so no `$_GET`/`$_POST`/`$_REQUEST` is ever threaded through. This is a structural property of `add_action($hook, $cb)` fired by `do_action()` from `wp-cron.php`, not something the helper itself has to guard.

**Known bypasses (accepted, pre-existing — do not re-flag):**
- `ntdst-audit` (`web/app/plugins/ntdst-audit/src/AuditService.php:51`) — weekly `wp_schedule_event(time(), 'weekly', 'ntdst_audit_cleanup')`.
- `ntdst-assistant` (`web/app/plugins/ntdst-assistant/ntdst-assistant.php:62`) — hourly `wp_schedule_event(time(), 'hourly', 'ntdst_assistant_cleanup_exports')`.

Both are regular plugins that predate this seam (Task 4.1, 2026-07-01); they are accepted debt, not new bypasses to chase.

**Out of scope (not recurring — do not flag as bypasses):** `ntdst_send_queued_mail` and `stride/mail/admin_notify_async` are single-event offloads (`wp_schedule_single_event`), not recurring jobs. INV-10 governs recurrence only.

**Audit move:**
```bash
# Should return only the seam itself (ntdst-coreloader.php) plus the two
# accepted pre-existing bypasses above. Any other hit is a new finding —
# route it through ntdst_schedule_recurring().
grep -rn "wp_schedule_event" web/app/mu-plugins/{stride-core,ntdst-core}
```

---

## INV-11 — REST route registration and the CORS decision are made in one place

**Convergence points:**

| Concern | Convergence point | Decides |
|---|---|---|
| New REST route registration | `NTDST_Rest_Registrar` (via `ntdst_router()->rest($namespace)`) — `web/app/mu-plugins/ntdst-core/api/RestRegistrar.php` | Route registration itself: required `permission` callable, `args`, optional `cors`, `max_body_bytes`, `max_json_depth` |
| CORS / `Access-Control-*` emission | `NTDST_Cors_Policy` — `web/app/mu-plugins/ntdst-core/api/CorsPolicy.php` | Origin allow-listing, credentials-header stripping, preflight headers — for any route |

**The rule.** Every *new* REST route registers through `ntdst_router()->rest($namespace)` (the `NTDST_Rest_Registrar` facade), never a raw `register_rest_route()` call. Any route that needs to answer cross-origin does so by passing a `NTDST_Cors_Policy` instance to the registrar — no hand-rolled `Access-Control-*` header, and no second `rest_pre_serve_request` CORS hook outside `CorsPolicy`. A route with no `permission` (or an inline-permissive one, e.g. `'__return_true'`) is the INV-1 failure mode recurring at framework level — the registrar makes `permission` a required option with no default specifically to prevent it.

**Construction/registration misconfiguration throws; runtime paths never throw.** Bad configuration — `'*'` as an origin, a missing `permission` — is a programmer error caught at construction/registration time via `InvalidArgumentException` / `_doing_it_wrong()` (a wrong route/policy simply never goes live). Once registered, request-handling code paths never throw: a bad or malicious *request* (disallowed origin, oversized body, invalid JSON) is handled as a normal `WP_Error` / denied-CORS response, not an exception. Future auditors should not re-litigate this split — it is deliberate, not an inconsistency.

**Dual error shape (deliberate, do not "unify").** `NTDST_Response` owns the API envelope, but two error shapes coexist on purpose for two different consumer sets:
- `apiSuccess()`/`apiError()` → `{success:false,data:{message,code}}` — the `Endpoints.php`/`ntdstAPI` JS wire shape (frontend AJAX, INV-2).
- `jsonPayload()` (via `json()`/`toRestResponse()`) → `{success:false,error:string}` — the REST-registrar wire shape.

A "unify the two shapes" refactor would silently break one of these consumer sets (existing `ntdstAPI` JS callers expect `data.message`/`data.code`; REST consumers of the registrar expect `error`). Keep them separate; if a true unification is ever wanted, it needs a versioned migration on both sides, not a drive-by rename.

**Known accepted baseline (pre-existing — do NOT re-flag):** `Modules/PartnerAPI/PartnerAPIController` and `Admin/AdminAPIController` call raw `register_rest_route()` with their own permission checks; `Modules/Assistant/ReadAbilityRegistrar` / `WriteAbilityRegistrar` register via WordPress's Abilities API (`wp_register_ability()`) with per-ability `permission_callback`s, not `register_rest_route()` at all (excluded on the `AbilityRegistrar` filename token); and `ntdst-core/api/Endpoints.php` is the same-origin `api_data` dispatcher. All predate the registrar — migrating them onto it is optional future work, not debt created by this reshape.

**Audit move (verified ZERO out-of-baseline hits across Phases 2-4 of the 2026-07 output-layer reshape):**
```bash
grep -rn "register_rest_route\|Access-Control-\|rest_pre_serve_request" --include="*.php" web/app/mu-plugins web/app/themes \
  | grep -vE "RestRegistrar|CorsPolicy|Endpoints\.php|PartnerAPIController|AdminAPIController|AbilityRegistrar"
```

---

## INV-12 — The profile-type enrollment gate is decided in one place, `ProfileTypePolicy`

**Convergence point:** `Stride\Modules\User\ProfileTypePolicy` — `web/app/mu-plugins/stride-core/Modules/User/ProfileTypePolicy.php`.

**The rule.** The answer to *"for this user's profile type + this enrollable, does enrollment block / use the minimal form / auto-apply a voucher?"* is decided ONLY in `ProfileTypePolicy` (`blocksEnrollment` / `usesMinimalForm` / `autoVoucherCode`, keyed on the user's STORED type slug via `currentUserType`). No enroll or form or quote surface re-derives the answer from raw `_ntdst_profiletype_rules` meta — they consult the policy. Rule reads go through `EditionRepository`/`TrajectoryRepository::getProfiletypeRules()` (INV-3), never raw `get_post_meta`.

**Scope note (deliberate — do not "promote" this).** Unlike INV-1 (authorization), this is NOT a request gate every request routes through. It is one policy consulted at a small, enumerated set of USER-initiated enroll seams: `EnrollmentService::enroll()`, `TrajectorySelection::enroll()`, and `EnrollmentService::registerWaitlist()`. The block is a **user-level** control — admin actions (`promoteFromWaitlist`, admin manual enroll) are trusted overrides and are intentionally NOT gated. A blocked type may still register **INTEREST** (`registerInterest` / the anonymous `QuestionnaireHandler` interest path) — interest is a pre-enrollment lead signal, not an enrollment, so it is exempt; the block replaces the enroll affordance only, never the lead-capture affordance (asserted by `blockedTypeCanRegisterInterest`). Gating interest would break lead capture. Fail-open is correct everywhere: no user / no stored type / deleted slug / absent rule ⇒ not blocked, full form, no voucher (the default is "anyone may enroll"). Adding a NEW user-initiated `RegistrationRepository::create()` caller means adding the policy check there; an admin-initiated, interest, or cascade-child path does not re-gate.

---

## Quick reference — convergence points

| # | Property | Convergence point | Bypass signal |
|---|---|---|---|
| 1 | Authorization | `AdminAPIController::canView/canManageAdmin`, `PartnerAPIController::checkPermission`, per-ability registrars | route with no / inline `permission_callback`; new custom cap; partner query not via `findByCompany` |
| 2 | Frontend AJAX nonce | `ntdst-core/api/Endpoints.php:349` (framework) | raw `wp_ajax_*` handler with no nonce |
| 3 | Data access | `AbstractRepository`→`ntdst_data()`; `RegistrationRepository`/`AttendanceRepository` own their tables | `$wpdb`/`ntdst_data()` outside a repo; `post_title` keys; hardcoded `_ntdst_*` |
| 4 | Error handling | `WP_Error` everywhere + `ntdst_log('chan')` | `return null/false` on failure; swallowed `is_wp_error`; raw `error_log` |
| 5 | Rendering | `NTDST_Template_Loader` / `ntdst_response()->html()`; plugin never calls theme | `stridence_*` in stride-core; unescaped `echo $var` |
| 6 | LearnDash boundary | `LMSAdapterInterface` (writes) / `LearnDashHelper` (reads) | `learndash_*`/`sfwd_*` outside the adapter+helper |
| 7 | Status | `EditionService::getEffectiveStatus()` | raw stored-status read for a gate/display |
| 12 | Profile-type enroll gate | `ProfileTypePolicy` (block/minimal-form/auto-voucher, user-level) | raw `_ntdst_profiletype_rules` read; new user-enroll path not consulting the policy; gating an admin/interest/cascade path |
| 8 | VAT/totals | `QuoteCalculator::TAX_RATE` + `deriveTotalsFromCents()` | hardcoded `0.21` / re-derived totals outside the helper |
| 9 | Anon-lead → account | `EnrollmentService::resolveLeadAccount()` | `wp_create_user`/`wp_new_user_notification` outside it (or the tracked `resolveParticipant`/PartnerAPI bypass); credentials or `billing_*` meta written to a found existing account |
| 10 | Recurring cron | `ntdst_schedule_recurring()` / `ntdst_clear_recurring()` (`ntdst-coreloader.php`) | raw `wp_schedule_event` outside the seam (excl. the two tracked pre-existing plugin bypasses) |
| 11 | REST registration + CORS | `NTDST_Rest_Registrar` (`ntdst_router()->rest()`) / `NTDST_Cors_Policy` | raw `register_rest_route()` or hand-rolled `Access-Control-*`/`rest_pre_serve_request` outside the two classes; route with no/inline-permissive `permission` |
