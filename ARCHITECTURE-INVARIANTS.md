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

**Convergence point:** `ntdst-core/api/Endpoints.php:330` — `if (!wp_verify_nonce($nonce, $action))` — gates **every** `ntdst/api_data/{action}` dispatch before the filter fires (`:340`).

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

All four use `$wpdb->prepare` and are concentrated for auditability. A **new** direct `$wpdb` against these tables outside their owning repository (and not one of the four above) is the bypass to flag.

**Audit move:**
```bash
# $wpdb outside repositories, *Table.php schema classes, and the justified files:
grep -rln '\$wpdb->' --include="*.php" web/app/mu-plugins/stride-core \
  | grep -vE "Repository\.php|Table\.php" \
  | grep -vE "EditionService|BatchQueryHelper|EditionFilesZipExporter"
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

**The rule (the dependency arrow).** `stride-core` (mu-plugin) MUST NEVER call theme helpers — `stridence_template_part`, `stridence_template_html`, any `stridence_*`, **or the non-prefixed theme helpers** `stride_format_date`, `stride_format_money`, `stride_enrollment_url` (defined in `themes/stridence/helpers/formatting.php`). Plugin→theme inverts the dependency. Plugin-owned partials live in `stride-core/templates/` and render through the loader / `ntdst_response()->html()`. (This is the `gotcha_mu_plugin_no_theme_calls` memory, codified. The original "verified clean" claim covered only the `stridence_` prefix — a grep blind spot, audit finding H-6: 4 `stride_format_date` calls exist in `NotificationMapper.php:139` and `StrideMailBridge.php:223,759,760`. Task C2 moves `stride_format_date` into stride-core; until then the check is advisory, after C2 it flips blocking.)

**Output escaping (sub-invariant).** Dynamic output is escaped at the sink: `esc_html` (text), `esc_attr` (attributes), `esc_url` (URLs). Alpine `x-text` bindings are intentionally unescaped data (Alpine HTML-escapes on insertion — safe; `x-html` would not be). The one deliberate raw echo (`_tool-header.php` `$attrs`, "caller is trusted") is marked inline — new raw `echo $var` of dynamic data without an `esc_*` is a bypass.

**Audit move:**
```bash
# The forbidden plugin→theme call — pattern covers ALL theme-defined procedural
# helpers (stridence_* prefix + the non-prefixed formatting helpers). Re-sweep
# themes/stridence/helpers/*.php + functions.php for new helpers when extending:
grep -rn "stride_format_\|stride_enrollment_url\|stridence_" --include="*.php" web/app/mu-plugins/stride-core
# must be empty — 4 known stride_format_date hits remain advisory until Task C2 lands, then blocking
# Unescaped dynamic echo in templates:
grep -rn "echo \$" --include="*.php" web/app/mu-plugins/stride-core/templates | grep -v "esc_\|(int)\|(float)\|x-text\|wp_kses\|ntdst_response"
```

---

## INV-6 — LearnDash is touched only through `LMSAdapterInterface` (writes) / `LearnDashHelper` (reads)

**Convergence point:** `Contracts/LMSAdapterInterface` (impl `Integrations/LearnDash/LearnDashService`) for **business operations** — `grantAccess`, `revokeAccess`, `isComplete`, `markComplete`, `isOpenCourse`. Read-only presentation goes through the static `LearnDashHelper` (`getProgress`, `getCertificateLink`, `getCompletionDate`, `getCourseAction`, `getLessons`, …).

**The rule.** No stride-core code calls LearnDash functions (`ld_update_course_access`, `learndash_*`, `sfwd_*`) directly. Mutations go through the injected `LMSAdapterInterface` (`ntdst_get(LMSAdapterInterface::class)`); reads go through `LearnDashHelper`. The only legitimate consumers of the write interface are `EnrollmentService` and `TrajectoryCascadeService`. A new `learndash_*` call anywhere else is a bypass — extend the adapter or the helper instead.

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

## INV-7 — Display status is derived through `getEffectiveStatus()`, not read raw

**Convergence point:** `Modules/Edition/EditionService::getEffectiveStatus(int): OfferingStatus`. Status value objects live in `Domain/` — `OfferingStatus`, `RegistrationStatus`, `AttendanceStatus`, `Money` (`EditionStatus` is a deprecated alias to `OfferingStatus`).

**The rule.** Stored status ≠ display status. Any surface (badge, enrollment gate, form resolution, server-side guard) that decides "can enroll / is this offering open / is it completed" reads through `getEffectiveStatus()`, which layers calendar + session reality over the stored intent (terminal status wins; past end-date → `Completed`; classroom with no published sessions → `Announcement`). Reading the raw stored status meta for a display/gate decision is a bypass — it will drift from what `getEffectiveStatus` shows. (The `lesson_effective_status_pattern` memory, codified.)

**Known weakness / notes:**
- **Status transitions are NOT centralized** — there is no state machine. Writes happen inline (`EditionService::setStatus()`, `EnrollmentService::confirmRegistration()`, `EnrollmentCompletion::completeTask()`). The invariant constrains the **read** side (always via `getEffectiveStatus`), not the write side. A future hardening would funnel transitions through one method; until then, transition logic is reviewed case-by-case.

**Audit move:**
```bash
# Raw status reads used for a gate/display (should go through getEffectiveStatus):
grep -rn "getStatus(\|->status\b\|'status'" --include="*.php" web/app/mu-plugins/stride-core \
  | grep -iE "enroll|badge|can_|display|gate|->open\b"
```

---

## Quick reference — convergence points

| # | Property | Convergence point | Bypass signal |
|---|---|---|---|
| 1 | Authorization | `AdminAPIController::canView/canManageAdmin`, `PartnerAPIController::checkPermission`, per-ability registrars | route with no / inline `permission_callback`; new custom cap; partner query not via `findByCompany` |
| 2 | Frontend AJAX nonce | `ntdst-core/api/Endpoints.php:330` (framework) | raw `wp_ajax_*` handler with no nonce |
| 3 | Data access | `AbstractRepository`→`ntdst_data()`; `RegistrationRepository`/`AttendanceRepository` own their tables | `$wpdb`/`ntdst_data()` outside a repo; `post_title` keys; hardcoded `_ntdst_*` |
| 4 | Error handling | `WP_Error` everywhere + `ntdst_log('chan')` | `return null/false` on failure; swallowed `is_wp_error`; raw `error_log` |
| 5 | Rendering | `NTDST_Template_Loader` / `ntdst_response()->html()`; plugin never calls theme | `stridence_*` in stride-core; unescaped `echo $var` |
| 6 | LearnDash boundary | `LMSAdapterInterface` (writes) / `LearnDashHelper` (reads) | `learndash_*`/`sfwd_*` outside the adapter+helper |
| 7 | Status | `EditionService::getEffectiveStatus()` | raw stored-status read for a gate/display |
