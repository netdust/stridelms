# Admin Workspace — Phase 1C: Bulk-Mutation Handlers (cluster B) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:subagent-driven-development` (recommended) or `superpowers:executing-plans` to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the bulk-mutation layer of the Stride Admin Workspace — the ~9 smart `ntdst/v1/action` handlers + one generic `stride_bulk_set_field` + the cache-bust events — each wrapping the **existing single-item domain path** (no second code path), with a per-row partial-failure report, capability-before-loop and per-row existence/transition authorization.

**Architecture:** A single new thin handler (`Handlers/BulkRegistrationHandler.php`) registers each bulk action on the framework's `ntdst/api_data/<name>` filter. The framework already verifies nonce + Origin/Referer CSRF + per-action rate-limit before dispatch (INV-2); each handler adds the `stride_manage` capability gate as its FIRST line (M2), then loops the row ids, calling the verified single-item domain methods (`EnrollmentService::confirmRegistration`/`cancel`, `EnrollmentCompletion::completeTask`, `QuoteRepository::updateStatus`, …) per row and collecting a `{total, succeeded[], failed[], summary}` report (M9). Cache busts via the existing `stride_action_queue` hook list plus two new event names (M10).

**Tech Stack:** WordPress (Bedrock) · NTDST Core (`ntdst/api_data` registry, DI, repositories) · `stride-core` mu-plugin · `wp_vad_registrations` custom table · `QuoteRepository` · PHPUnit (Unit + Integration).

**Work classification:** **Class B** — executing an existing, settled spec (`docs/plans/2026-06-13-admin-workspace-spec.md`, cluster B = Tasks 2.1–2.4) as a critical freshness review. The design is fixed; this plan ground-truths every premise against current source, bakes verified signatures into each task, and flags drift as plan-correction notes. **Do NOT redesign.**

## Global Constraints

- **Branch:** work on `feat/admin-workspace-1b-pickers-traj-filter` (cluster A2 merged-in-place here; 6 commits on top of `e72770f4`, current HEAD `f8a38908`). The bulk handlers are NEW files — no conflict with A2's files **except `Admin/AdminDashboardService.php`** (Task 2.4 edits the bust list near line 80).
- **All UI/error text in Dutch (nl_BE)**; code in English.
- **PHP 8.3**, `declare(strict_types=1)`, `final class`.
- **INV-2 — nonce verified ONCE by the framework.** Handlers MUST NOT call `wp_verify_nonce` (the registry already did it at `Endpoints.php:343`). A re-verify is a review finding.
- **No raw `wp_ajax_*`** — bulk actions register through `ntdst/api_data/*` only (INV-2).
- **No swallowed `WP_Error`** — each per-row failure is captured into `failed[]`; a domain `WP_Error` is never `is_wp_error($x) ? return : …`'d away (INV-4).
- **All data access through the repository** (INV-3) — no raw `$wpdb` in the handler; use `RegistrationRepository`, `QuoteRepository`, `EditionService`.
- **No pure pass-through** — the handler adds the capability gate + per-row loop + report + transition validation; it is not a 1-line proxy (`lesson_pure_passthrough_is_drift`).
- **Test commands:** `ddev exec vendor/bin/phpunit --testsuite Unit` (fast, stubs) · `ddev exec vendor/bin/phpunit -c phpunit-integration.xml.dist` (real WP). Lint: `ddev exec composer lint` (Pint + PHPStan — run Pint fix TWICE for convergence, verify CI via `gh run watch`, not local — `gotcha_ci_green_local_red`).

---

## Ground-truth report — verified vs. drifted premises

Read against current source (HEAD `f8a38908`) before writing tasks. **Where code contradicts the spec, the code wins** and the task below bakes in the verified signature.

### VERIFIED (use as-is)

| # | Premise | Verified fact (file:line) |
|---|---|---|
| V1 | `ntdst/api_data` registry dispatch | `apply_filters("ntdst/api_data/{$action}", [], $params)` at `ntdst-core/api/Endpoints.php:353`. `$params` = full request body (`action`, `nonce`, plus handler params e.g. `ids`, `field`, `value`) from `get_request_params()`. Handler signature: `fn(mixed $data, array $params): array|WP_Error`. |
| V2 | Nonce verified once by framework | `wp_verify_nonce($nonce, $action)` at `Endpoints.php:343` inside `handle_action`. INV-2 holds — handlers must NOT re-verify. |
| V3 | CSRF + rate-limit + auth guard fire before dispatch | **Located in the route's `permission_callback` `check_action_permission` (`Endpoints.php:142-168`)**, NOT inline in `handle_action`. It runs `checkRateLimit` (`:148`), `verifyOrigin` (Origin/Referer same-origin CSRF, `:153` → `:214-254`), and an auth gate (anon may only dispatch public actions, `:163`). Order: rate-limit → CSRF → auth → (then `handle_action`) nonce → `has_filter` 404 → dispatch. Bulk actions are NOT public → require login. (M6 satisfied by inheritance.) |
| V4 | Capability methods | `AdminAPIController::canViewAdmin()` = `current_user_can('stride_view')` (`:493`); `canManageAdmin()` = `current_user_can('stride_manage')` (`:501`). The bulk handler is in `Handlers/`, not the controller — it calls `current_user_can('stride_manage')` directly (M2). |
| V5 | Single-item confirm path | `EnrollmentService::confirmRegistration(int $id): true\|WP_Error` (`Modules/Enrollment/EnrollmentService.php:565`). Grants LD access, fires `stride/registration/confirmed`. |
| V6 | Single-item approve sequence | `AdminAPIController::approveRegistration` (`:2507`) performs: `EnrollmentCompletion::completeTask($id,'approval')` THEN `EnrollmentService::confirmRegistration($id)`. Both `…: true\|WP_Error`. |
| V7 | Single-item cancel | `EnrollmentService::cancel(int $id): bool\|WP_Error` (`:625`). Releases seat (`registrations->cancel`), `revokeAccess`, fires `stride/registration/cancelled`. Guards `Completed`/`Cancelled` → `WP_Error`. |
| V8 | Post-course approve | `AdminAPIController::approvePostCourse` (`:2543`) = `EnrollmentCompletion::completeTask($id,'post_approval')` only. `…: true\|WP_Error`. |
| V9 | `completeTask` signature | `EnrollmentCompletion::completeTask(int $id, string $taskType, array $data = []): true\|WP_Error` (`:358`). Validates task type against `TASK_TYPES`; returns `WP_Error('task_not_required')` if the task isn't on the row. |
| V10 | Capacity for waitlist promote | `EditionService::getCapacity(int $id): int` (`:130`); `RegistrationRepository::countConfirmedForEdition(int $id): int` (`:621`) and the race-safe `countConfirmedForUpdate` (FOR UPDATE, transaction) (`:635`). INV-7 "edition not terminal" via `EditionService::getEffectiveStatus(int $id): OfferingStatus` (`:311`). |
| V11 | Quote status setter | `QuoteRepository::updateStatus(int $quoteId, QuoteStatus $status): bool` (`:228`). `QuoteStatus` cases = `Draft\|Sent\|Exported\|Cancelled` (`Domain/QuoteStatus.php:12-15`) — **NO `Paid` case**. Reg→quote lookup: `QuoteRepository::findQuoteIdsByRegistrations(array $regIds): array<int regId, int quoteId>` (`:121`). |
| V12 | Cache-bust list | `Admin/AdminDashboardService.php:80-85`: `$invalidateQueue = fn() => delete_transient('stride_action_queue');` then `add_action('stride/registration/created'|'…/confirmed'|'…/cancelled'|'stride/attendance/marked'|'save_post_vad_quote', $invalidateQueue);`. Task 2.4 adds two more `add_action` lines bound to `$invalidateQueue`. |
| V13 | Thin-handler mirror | `Handlers/QuoteUpdateHandler.php` — `final class` in `Stride\Handlers`, no constructor DI, `__construct(){ $this->init(); }`, `init()` registers `add_filter('ntdst/api_data/<name>', [$this,'method'], 10, 2)`, methods `fn(mixed $data, array $params): array\|WP_Error`, `ntdst_get(Service::class)` inside, returns `WP_Error` on failure. **Copy this shape.** |
| V14 | Handler registration site | `stride-core.php:163-175` — the `$handlers = [ … ]` list, each `ntdst_set` + `ntdst_get`. Task 2.1 adds `\Stride\Handlers\BulkRegistrationHandler::class` here. |
| V15 | Transition map exists | `Modules/Enrollment/RegistrationTransitions.php` already shipped (Task 1.1, cluster A) with `validFor(RegistrationStatus): array`, `isAllowed(from,to): bool`, `isTerminal(state): bool`. Use it for per-row valid-transition validation (M3). |
| V16 | Unit-test approach | `tests/Unit/ProfileHandlerTest.php` — `new Handler()`, set `global $_test_current_user_id`, drive private methods via Reflection. **`current_user_can` is controllable**: stub at `tests/Stubs/wordpress-stubs.php:421-431` reads `global $current_user_caps` (set `['stride_manage'=>false]` to force the M2 denial). |

### DRIFTED — plan-correction notes (the spec is wrong; the task below corrects it)

| # | Spec premise | Source truth | Correction baked into |
|---|---|---|---|
| **D1** | "`stride_bulk_approve` wraps the single-item `approveRegistration`" (§2.1, §4.2) | `approveRegistration` is a **REST controller method taking `WP_REST_Request`** (`:2507`) — NOT a reusable per-row domain method. The reusable sequence is the two service calls it makes: `completeTask($id,'approval')` then `confirmRegistration($id)`. | **Task 2.1** wraps the **domain sequence** (V6), not the controller method. Same for post-course (V8): wrap `completeTask($id,'post_approval')`, not `approvePostCourse(WP_REST_Request)`. |
| **D2** | M9 / Task 2.1: "`confirmRegistration` already guards confirmed→confirmed (benign no-op)" | `confirmRegistration` returns **`WP_Error('invalid_status','Registration is not pending approval')`** for ANY non-pending status (`EnrollmentService.php:577`). An already-confirmed row yields a `WP_Error`, not a silent success. | **Task 2.1** test asserts an already-`confirmed` row lands in **`failed[]`** with code `invalid_status` (integrity-benign: no double-grant) — NOT a `succeeded[]` no-op. The handler MAY pre-check status via `RegistrationTransitions`/`find()` and report it as a benign skip, but the load-bearing assertion is "no double LD grant." |
| **D3** | "`stride_bulk_message` reuses NotificationService" (§4.1) | `NotificationService` is **in-app (bell) notifications** (`getNotifications`/`markRead`/`onAuditRecorded`) — it has NO "send templated email" method. Email transport is `ndmail_send($template,$data,$options)` (netdust-mail global), wrapped by `StrideMailBridge` (`Modules/Mail/StrideMailBridge.php:99,152,529`). | **Task 2.2**: `stride_bulk_message` is the LEAST-grounded handler. Decide its transport at task start — either (a) in-app `NotificationService` add-notification path, or (b) `ndmail_send` with an admin-message template. If neither has a clean single-item method, **scope `stride_bulk_message` to a minimal in-app notification** and record the email-broadcast variant as deferred (it overlaps `project_mail_broadcast_feature`, which lives in netdust-mail, not Stride). Do not invent a new mail pipeline in this cluster. |
| **D4** | "`stride_bulk_generate_doc` reuses exporters/PDF" (§4.1) | Generators exist (`QuotePDFGenerator`, `Edition*Exporter`, `AnnualReportPdfGenerator`) but they are **edition/quote/report-scoped**, none is a per-registration "deliverable" generator with a clean bulk entry. | **Task 2.2**: `stride_bulk_generate_doc` is also weakly grounded. Field-scoped deliverable export is **explicitly Phase 3** (spec §6/§9). For 1C, scope this to the narrowest existing per-row artifact that has a clean call (e.g. queue a certificate/attestation via the existing generator IF one resolves per registration); otherwise **mark `stride_bulk_generate_doc` as a deferred stub returning a "nog niet beschikbaar" per-row failure** and note it lands fully in Phase 3. The cluster ships the 7 well-grounded handlers; these two are flagged, not forced. |
| **D5** | Cache-bust events fired via `$this->dispatch(...)` | `dispatch()` lives on `AbstractService` (`Infrastructure/AbstractService.php:56`, `do_action("stride/{$event}")`). The bulk handler is a **thin Handler, not a Service** — it has no `dispatch()`. | **Task 2.4**: the handler fires events with **`do_action('stride/registration/bulk_completed', $ctx)`** and **`do_action('stride/registration/quote_status_changed', $ctx)`** directly (full `stride/` prefix), matching the listener names added to the bust list. |
| **D6** | Test path `tests/Unit/Handlers/BulkRegistrationHandlerTest.php` | Existing handler tests live at `tests/Unit/*Test.php` (e.g. `ProfileHandlerTest.php`), NOT under `tests/Unit/Handlers/`. | **Acceptable** — create the `tests/Unit/Handlers/` subdir as the spec specifies (it's a cleaner location). Just be aware it differs from the established flat location; ensure `phpunit.xml` Unit suite globs `tests/Unit/**`. Verify the glob before writing (one `grep`). |

---

## Threat-model slice (cluster B convergence target — M2/M3/M6/M7/M9/M10)

> Embedded from the spec's `## Threat model`. `/code-review` + `security-sentinel` verify the cluster-B diff against these numbered mitigations, not free-form. Each names the task that satisfies it.

| ID | Mitigation | Satisfied by |
|---|---|---|
| **M2** | Capability gate `if (!current_user_can('stride_manage')) return new WP_Error('forbidden', …, ['status'=>403]);` as the **FIRST line of every `stride_bulk_*` handler, BEFORE the row loop**. Nonce proves integrity (M6); this proves authorization. | **Task 2.1** (the gate, with the load-bearing 403-before-loop RED denial test), inherited by 2.2/2.3. |
| **M3** | Per-row existence + valid-transition check inside the loop: each `id` `absint`'d, resolved via `RegistrationRepository::find($id)`; a row that doesn't resolve, or whose current→target transition is not in `RegistrationTransitions::isAllowed`, goes to `failed[]` with `not_found`/`invalid_status` — never silently skipped, never mutated. (Capability model is global `stride_manage`; the hook for company-scoping is left but not implemented — spec out-of-scope.) | **Task 2.1** (RED test: non-existent id → `failed[]`, not mutated), inherited by 2.2/2.3. |
| **M6** | Same-origin + nonce on every bulk mutation — inherited from the registry (`verifyOrigin` + `wp_verify_nonce`, V3/V2). The grid mints `wp_create_nonce('<action>')` per chosen action (spec §2.3, client-side Task 3.2 in Phase 1D — out of THIS cluster). Handler does NOT re-verify (INV-2). | Framework (V2/V3); **Task 2.1** asserts no re-verify present. |
| **M7** | Server-side field allowlist in `stride_bulk_set_field`: hard-reject any field not in `{notes, tags, company_id}` with a 400 — `status`/`completed_at`/`cancelled_at`/any lifecycle field refused regardless of payload. UI hiding is cosmetic; THIS is the control. | **Task 2.3** (load-bearing M7 RED denial: `field=status` → 400). |
| **M9** | Idempotency + explicit per-row report `{total, succeeded[], failed[], summary:{ok,error}}`; no auto-retry of the whole batch; a naturally-idempotent action (re-approve a confirmed row) does NOT double-apply (per D2: it lands in `failed[]` with `invalid_status` — integrity-benign, no double grant). | **Task 2.1** (report shape + the no-double-grant assertion), 2.2 (capacity skip → `failed[]`). |
| **M10** | Cache bust on bulk completion: per-row events (`stride/registration/{confirmed,cancelled}`) fire inherited from the domain methods (V5/V7); PLUS `stride/registration/bulk_completed` once per batch and `stride/registration/quote_status_changed` for quote actions — all added to the `stride_action_queue` bust list. | **Task 2.4** (bust-list additions + the `do_action` fires, per D5). |

**Out of scope (do NOT flag as findings):** company-scoping of the admin grid (intentionally cross-company); full per-row bulk-mutation audit log (Phase 3); insider with valid `stride_coordinator` creds (audit-trail only).

## Architecture invariants touched

| Invariant | Obligation in this cluster |
|---|---|
| **INV-1 — authz at entry by capability** | `current_user_can('stride_manage')` first line of every handler (M2). No new caps invented. |
| **INV-2 — frontend nonce verified once by framework** | Handlers register on `ntdst/api_data/*` and do NOT re-verify the nonce; not reachable by `wp_ajax_*`. |
| **INV-3 — data through the repository** | All reads/writes via `RegistrationRepository`/`QuoteRepository`/`EditionService` — no raw `$wpdb` in the handler. |
| **INV-4 — `WP_Error` logged/bubbled, never swallowed** | Per-row failures captured into `failed[]` with code+message; domain `WP_Error`s never discarded. |
| **INV-7 — display/gate status via `getEffectiveStatus()`** | The waitlist "seats open" gate reads `EditionService::getEffectiveStatus()` (edition not terminal) + capacity vs. confirmed count, not raw stored status. |

## Gate & tier decision

- **Tier: FULL** (matches §2.1 trigger surface — this cluster is the confused-deputy / per-row-authz / lifecycle-mutation security boundary: M2/M3/M6/M7). At spec-close the full reviewer panel + `security-sentinel` run on the branch diff.
- **Cluster size: 4 tasks (2.1–2.4)** — within the ~4 review-cluster cap → a **single `── REVIEW GATE B ──`** holds the whole cluster. No sub-split needed.
- **Strangle:** **~0 SQL moves** (phase-map Phase 1C). The bulk targets are already thin (`approveRegistration`/`approvePostCourse`/`markAttendance` = 0 `$wpdb`, they delegate to services). The handler wraps clean domain logic. Optionally extract an `AdminApprovalService` if the single-item logic wants a home — but no `$wpdb` leaves the controller in this phase.

---

## File structure

- **Create** `web/app/mu-plugins/stride-core/Handlers/BulkRegistrationHandler.php` — the one new file; all bulk handlers + the shared loop/report helpers live here (files that change together live together).
- **Create** `tests/Unit/Handlers/BulkRegistrationHandlerTest.php` — unit tests (capability gate, per-row report, allowlist) driven via the handler's public `ntdst/api_data` methods with stubbed `current_user_can` + stubbed services where needed.
- **Create** `tests/Integration/BulkCacheBustTest.php` — Task 2.4 (real `delete_transient` + `do_action` wiring).
- **Modify** `web/app/mu-plugins/stride-core/stride-core.php:163-175` — register the handler.
- **Modify** `web/app/mu-plugins/stride-core/Admin/AdminDashboardService.php:80-85` — add two bust events.

---

## ── REVIEW GATE B ── (tier: FULL — cluster builds the bulk-mutation handlers under multi-select; the M2/M3/M6/M7 confused-deputy + per-row-authz security boundary)

### Task 2.1: Bulk-approve + bulk-cancel handlers (wrap single-item domain paths)

**Files:**
- Create: `web/app/mu-plugins/stride-core/Handlers/BulkRegistrationHandler.php`
- Modify: `web/app/mu-plugins/stride-core/stride-core.php:163-175` (register the handler in the `$handlers` list)
- Test: `tests/Unit/Handlers/BulkRegistrationHandlerTest.php`

**Interfaces:**
- Consumes (verified signatures): `EnrollmentCompletion::completeTask(int $id, string $taskType): true|WP_Error` (V9) · `EnrollmentService::confirmRegistration(int $id): true|WP_Error` (V5) · `EnrollmentService::cancel(int $id): bool|WP_Error` (V7) · `RegistrationRepository::find(int $id): ?object|WP_Error` · `RegistrationTransitions::isAllowed(RegistrationStatus $from, RegistrationStatus $to): bool` (V15). Registry contract V1: `fn(mixed $data, array $params): array|WP_Error`, `$params['ids']` carries the row ids.
- Produces (later tasks add methods to THIS file): a shared `private function runBulk(array $params, callable $perRow): array` returning the report shape below, and the report contract `{total:int, succeeded: array<{id:int}>, failed: array<{id:int, code:string, message:string}>, summary:{ok:int, error:int}}`.

- [ ] **Step 1: Confirm the Unit suite globs `tests/Unit/Handlers/`**

Run: `grep -n "tests/Unit" phpunit.xml.dist phpunit.xml 2>/dev/null`
Expected: a `<directory>tests/Unit</directory>` glob (recursive). If it lists individual files, add `tests/Unit/Handlers`. (D6.)

- [ ] **Step 2: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace Stride\Tests\Unit\Handlers;

use Stride\Handlers\BulkRegistrationHandler;
use Stride\Tests\TestCase;

class BulkRegistrationHandlerTest extends TestCase
{
    private BulkRegistrationHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        global $_test_current_user_id, $current_user_caps;
        $_test_current_user_id = 1;
        $current_user_caps = ['stride_manage' => true]; // entitled by default
        $this->handler = new BulkRegistrationHandler();
    }

    /** M2: capability denied BEFORE the loop → 403, nothing mutated. */
    public function test_bulk_approve_denies_view_only_actor_before_loop(): void
    {
        global $current_user_caps;
        $current_user_caps = ['stride_manage' => false]; // view-only

        $result = $this->handler->handleBulkApprove([], ['ids' => [101, 102]]);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('forbidden', $result->get_error_code());
        $this->assertSame(403, $result->get_error_data()['status'] ?? null);
    }

    /** M9 report shape: total/succeeded/failed/summary present. */
    public function test_bulk_approve_returns_per_row_report_shape(): void
    {
        $report = $this->handler->handleBulkApprove([], ['ids' => []]);
        $this->assertSame(['total', 'succeeded', 'failed', 'summary'], array_keys($report));
        $this->assertSame(0, $report['total']);
        $this->assertSame(['ok' => 0, 'error' => 0], $report['summary']);
    }

    /** M3: a non-existent row id lands in failed[], not mutated. */
    public function test_bulk_approve_nonexistent_row_lands_in_failed(): void
    {
        $report = $this->handler->handleBulkApprove([], ['ids' => [99999999]]);
        $this->assertSame(1, $report['total']);
        $this->assertCount(0, $report['succeeded']);
        $this->assertCount(1, $report['failed']);
        $this->assertSame(99999999, $report['failed'][0]['id']);
        $this->assertContains($report['failed'][0]['code'], ['not_found', 'invalid_status']);
    }

    /** D2/M9: an already-confirmed row does NOT double-grant; lands in failed[] benignly. */
    public function test_bulk_approve_already_confirmed_is_no_double_grant(): void
    {
        // Arrange a confirmed registration via the test repository fixture,
        // then bulk-approve it. confirmRegistration returns WP_Error('invalid_status').
        $confirmedId = $this->seedConfirmedRegistration();
        $report = $this->handler->handleBulkApprove([], ['ids' => [$confirmedId]]);
        $this->assertCount(1, $report['failed']);
        $this->assertSame('invalid_status', $report['failed'][0]['code']);
        // The load-bearing assertion: no second grantAccess fired (assert via the LMS spy).
        $this->assertSame(0, $this->lmsSpy->grantAccessCallCount($confirmedId));
    }
}
```
*(Implementer: wire `seedConfirmedRegistration()` + `lmsSpy` to the existing unit fixtures/stubs the way `ProfileHandlerTest`/the enrollment unit tests do. If the LMS spy seam isn't available at unit level, demote `test_bulk_approve_already_confirmed_is_no_double_grant` to the integration suite — but keep the "lands in failed[] with invalid_status" half at unit level. The 403-before-loop and report-shape tests stay unit.)*

- [ ] **Step 3: Run the tests, verify they fail**

Run: `ddev exec vendor/bin/phpunit --testsuite Unit --filter BulkRegistrationHandler`
Expected: FAIL — `Class "Stride\Handlers\BulkRegistrationHandler" not found`.

- [ ] **Step 4: Implement the handler**

```php
<?php
declare(strict_types=1);

namespace Stride\Handlers;

use Stride\Domain\RegistrationStatus;
use Stride\Modules\Enrollment\EnrollmentCompletion;
use Stride\Modules\Enrollment\EnrollmentService;
use Stride\Modules\Enrollment\RegistrationRepository;
use Stride\Modules\Enrollment\RegistrationTransitions;
use WP_Error;

/**
 * Bulk registration mutation handlers (Admin Workspace, Phase 1C).
 *
 * Each action registers on the ntdst/api_data registry; the framework has
 * already verified nonce + Origin/Referer CSRF + rate-limit (INV-2). Each
 * handler:
 *   M2 — checks current_user_can('stride_manage') FIRST, before the loop.
 *   M3 — per-row existence + valid-transition check; failures into failed[].
 *   M9 — returns the {total, succeeded, failed, summary} report; no auto-retry.
 * Every per-row call goes through the SAME single-item domain path the case
 * view uses (lesson_pure_passthrough_is_drift) — no second code path.
 */
final class BulkRegistrationHandler
{
    public function __construct()
    {
        $this->init();
    }

    private function init(): void
    {
        add_filter('ntdst/api_data/stride_bulk_approve', [$this, 'handleBulkApprove'], 10, 2);
        add_filter('ntdst/api_data/stride_bulk_cancel', [$this, 'handleBulkCancel'], 10, 2);
        // Task 2.2 adds: quote_sent, quote_exported, promote_waitlist, message,
        // approve_post_course, generate_doc. Task 2.3 adds: set_field.
    }

    /**
     * M2 capability gate — call as the FIRST line of every handler.
     * @return WP_Error|null  WP_Error(403) when denied, null when allowed.
     */
    private function denyIfNotManager(): ?WP_Error
    {
        if (!current_user_can('stride_manage')) {
            return new WP_Error('forbidden', __('Geen toegang.', 'stride'), ['status' => 403]);
        }
        return null;
    }

    /**
     * Shared bulk loop + per-row report (M3 + M9).
     *
     * @param array<string,mixed> $params  registry params; $params['ids'] = row ids.
     * @param callable(int $id, object $registration): (true|WP_Error) $perRow
     * @return array{total:int, succeeded:array<int,array{id:int}>, failed:array<int,array{id:int,code:string,message:string}>, summary:array{ok:int,error:int}}
     */
    private function runBulk(array $params, callable $perRow): array
    {
        $ids = array_values(array_unique(array_map('absint', (array) ($params['ids'] ?? []))));
        $repo = ntdst_get(RegistrationRepository::class);

        $succeeded = [];
        $failed = [];

        foreach ($ids as $id) {
            $registration = $repo->find($id);
            if ($registration === null || is_wp_error($registration)) {
                $failed[] = ['id' => $id, 'code' => 'not_found', 'message' => __('Inschrijving niet gevonden.', 'stride')];
                continue;
            }

            $result = $perRow($id, $registration);
            if (is_wp_error($result)) {
                $failed[] = [
                    'id' => $id,
                    'code' => $result->get_error_code(),
                    'message' => $result->get_error_message(),
                ];
                continue;
            }
            $succeeded[] = ['id' => $id];
        }

        return [
            'total' => count($ids),
            'succeeded' => $succeeded,
            'failed' => $failed,
            'summary' => ['ok' => count($succeeded), 'error' => count($failed)],
        ];
    }

    /**
     * stride_bulk_approve — pending/interest → confirmed.
     * Wraps the DOMAIN SEQUENCE (D1): completeTask('approval') then
     * confirmRegistration() — NOT the controller's approveRegistration().
     *
     * @param array<string,mixed> $params
     * @return array{...}|WP_Error
     */
    public function handleBulkApprove(mixed $data, array $params): array|WP_Error
    {
        if ($deny = $this->denyIfNotManager()) {
            return $deny;
        }

        return $this->runBulk($params, function (int $id, object $reg): true|WP_Error {
            $completion = ntdst_get(EnrollmentCompletion::class);
            $enrollment = ntdst_get(EnrollmentService::class);

            // M3: only pending/interest may be approved into the pipe.
            $from = RegistrationStatus::tryFrom((string) $reg->status);
            if ($from !== RegistrationStatus::Pending && $from !== RegistrationStatus::Interest) {
                return new WP_Error('invalid_status', __('Deze inschrijving kan niet goedgekeurd worden.', 'stride'));
            }

            $task = $completion->completeTask($id, 'approval');
            if (is_wp_error($task)) {
                // task_not_required is benign for an already-approved row — still report it.
                return $task;
            }

            // D2: confirmRegistration returns WP_Error('invalid_status') for non-pending,
            // so an already-confirmed row never double-grants.
            return $enrollment->confirmRegistration($id);
        });
    }

    /**
     * stride_bulk_cancel — → cancelled (release seat + revokeAccess + notify).
     * Wraps EnrollmentService::cancel() (V7).
     */
    public function handleBulkCancel(mixed $data, array $params): array|WP_Error
    {
        if ($deny = $this->denyIfNotManager()) {
            return $deny;
        }

        return $this->runBulk($params, function (int $id, object $reg): true|WP_Error {
            $enrollment = ntdst_get(EnrollmentService::class);
            $result = $enrollment->cancel($id); // bool|WP_Error
            if (is_wp_error($result)) {
                return $result;
            }
            return true;
        });
    }
}
```

Then register it in `stride-core.php` (V14) — add to the `$handlers` array (line ~163):
```php
        \Stride\Handlers\BulkRegistrationHandler::class,
```

- [ ] **Step 5: Run the tests, verify they pass**

Run: `ddev exec vendor/bin/phpunit --testsuite Unit --filter BulkRegistrationHandler`
Expected: PASS (4 tests; the double-grant test passes or is demoted to integration per the note).

- [ ] **Step 6: Commit**

```bash
git add web/app/mu-plugins/stride-core/Handlers/BulkRegistrationHandler.php web/app/mu-plugins/stride-core/stride-core.php tests/Unit/Handlers/BulkRegistrationHandlerTest.php
git commit -m "feat(admin-bulk): bulk-approve + bulk-cancel handlers with per-row report (task 2.1)

Wraps the single-item domain sequence (completeTask+confirmRegistration / cancel),
M2 capability-before-loop, M3 per-row existence+transition, M9 partial-failure report."
```

**Tier A. Test contract:** bulk-approve loops the single-item domain sequence (completeTask('approval')+confirmRegistration, per D1) and returns a per-row `{succeeded[], failed[]}` report; **a view-only actor (no `stride_manage`) gets `WP_Error(403)` BEFORE the loop (M2, load-bearing denial)**; a non-existent row id lands in `failed[]` not mutated (M3); an already-`confirmed` row does NOT double-grant — lands in `failed[]` with `invalid_status` (D2/M9).

---

### Task 2.2: Quote-workflow + waitlist + message + post-course + generate-doc handlers

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Handlers/BulkRegistrationHandler.php` (add 6 handlers + register them in `init()`)
- Test: extends `tests/Unit/Handlers/BulkRegistrationHandlerTest.php`

**Interfaces:**
- Consumes (verified): `QuoteRepository::findQuoteIdsByRegistrations(array $regIds): array<int,int>` (V11) · `QuoteRepository::updateStatus(int $quoteId, QuoteStatus $status): bool` (V11) · `QuoteStatus::Sent|Exported` (V11, no `Paid`) · `EditionService::getCapacity(int $id): int` + `getEffectiveStatus(int $id): OfferingStatus` (V10) · `RegistrationRepository::countConfirmedForEdition(int $id): int` (V10) · `EnrollmentCompletion::completeTask(int $id,'post_approval'): true|WP_Error` (V8/V9) · `EnrollmentService::confirmRegistration` (V5, for waitlist→confirmed).
- Produces: the 6 additional `handle*` methods on the handler, all returning the shared report shape.

- [ ] **Step 1: Write the failing test**

```php
/** Quote → Exported via QuoteRepository; never a "paid" field (none exists). */
public function test_bulk_quote_exported_sets_status_no_paid_field(): void
{
    $regId = $this->seedConfirmedRegistrationWithQuote(); // quote in Draft
    $report = $this->handler->handleBulkQuoteExported([], ['ids' => [$regId]]);
    $this->assertCount(1, $report['succeeded']);
    $quoteId = $this->quoteRepo->findQuoteIdsByRegistrations([$regId])[$regId];
    $this->assertSame('exported', $this->quoteRepo->getStatus($quoteId)); // QuoteStatus::Exported
    // No "paid"/"paid_at" assertion possible — the field does not exist (intentional).
}

/** Waitlist promote skips a full edition into failed[] (per-row capacity re-check, INV-7). */
public function test_bulk_promote_waitlist_skips_full_edition(): void
{
    $fullEditionRegId = $this->seedWaitlistOnFullEdition();
    $report = $this->handler->handleBulkPromoteWaitlist([], ['ids' => [$fullEditionRegId]]);
    $this->assertCount(1, $report['failed']);
    $this->assertSame('capacity_full', $report['failed'][0]['code']);
}

/** M2 inherited: every new action denies a view-only actor before the loop. */
public function test_quote_and_waitlist_handlers_deny_view_only(): void
{
    global $current_user_caps;
    $current_user_caps = ['stride_manage' => false];
    foreach (['handleBulkQuoteSent','handleBulkQuoteExported','handleBulkPromoteWaitlist','handleBulkApprovePostCourse'] as $m) {
        $this->assertInstanceOf(\WP_Error::class, $this->handler->$m([], ['ids' => [1]]));
    }
}
```

- [ ] **Step 2: Run, verify fail**

Run: `ddev exec vendor/bin/phpunit --testsuite Unit --filter BulkRegistrationHandler`
Expected: FAIL — methods not defined.

- [ ] **Step 3: Implement the 6 handlers**

```php
// In init(), register the new actions:
add_filter('ntdst/api_data/stride_bulk_quote_sent', [$this, 'handleBulkQuoteSent'], 10, 2);
add_filter('ntdst/api_data/stride_bulk_quote_exported', [$this, 'handleBulkQuoteExported'], 10, 2);
add_filter('ntdst/api_data/stride_bulk_promote_waitlist', [$this, 'handleBulkPromoteWaitlist'], 10, 2);
add_filter('ntdst/api_data/stride_bulk_approve_post_course', [$this, 'handleBulkApprovePostCourse'], 10, 2);
add_filter('ntdst/api_data/stride_bulk_message', [$this, 'handleBulkMessage'], 10, 2);
add_filter('ntdst/api_data/stride_bulk_generate_doc', [$this, 'handleBulkGenerateDoc'], 10, 2);

private function setQuoteStatusForRows(array $params, \Stride\Domain\QuoteStatus $status): array|WP_Error
{
    if ($deny = $this->denyIfNotManager()) {
        return $deny;
    }
    $quoteRepo = ntdst_get(\Stride\Modules\Invoicing\QuoteRepository::class);

    return $this->runBulk($params, function (int $id, object $reg) use ($quoteRepo, $status): true|WP_Error {
        $map = $quoteRepo->findQuoteIdsByRegistrations([$id]); // V11: regId => quoteId
        $quoteId = $map[$id] ?? 0;
        if (!$quoteId) {
            return new WP_Error('no_quote', __('Geen offerte voor deze inschrijving.', 'stride'));
        }
        if (!$quoteRepo->updateStatus($quoteId, $status)) {
            return new WP_Error('quote_update_failed', __('Offertestatus kon niet worden bijgewerkt.', 'stride'));
        }
        return true;
    });
}

public function handleBulkQuoteSent(mixed $data, array $params): array|WP_Error
{
    return $this->setQuoteStatusForRows($params, \Stride\Domain\QuoteStatus::Sent);
}

public function handleBulkQuoteExported(mixed $data, array $params): array|WP_Error
{
    return $this->setQuoteStatusForRows($params, \Stride\Domain\QuoteStatus::Exported);
}

public function handleBulkPromoteWaitlist(mixed $data, array $params): array|WP_Error
{
    if ($deny = $this->denyIfNotManager()) {
        return $deny;
    }
    $editions = ntdst_get(\Stride\Modules\Edition\EditionService::class);
    $repo = ntdst_get(RegistrationRepository::class);
    $enrollment = ntdst_get(EnrollmentService::class);

    return $this->runBulk($params, function (int $id, object $reg) use ($editions, $repo, $enrollment): true|WP_Error {
        $from = RegistrationStatus::tryFrom((string) $reg->status);
        if ($from !== RegistrationStatus::Waitlist) {
            return new WP_Error('invalid_status', __('Inschrijving staat niet op de wachtlijst.', 'stride'));
        }
        $editionId = (int) $reg->edition_id;

        // INV-7: edition must not be terminal.
        if ($editions->getEffectiveStatus($editionId)->isTerminal()) {
            return new WP_Error('edition_closed', __('Editie is afgesloten.', 'stride'));
        }
        // Per-row capacity re-check (V10).
        if ($repo->countConfirmedForEdition($editionId) >= $editions->getCapacity($editionId)) {
            return new WP_Error('capacity_full', __('Editie is vol.', 'stride'));
        }
        // confirmRegistration requires Pending; waitlist promote sets pending→confirmed
        // through the repository status update + the same grant path. Reuse the verified
        // single-item path: move to pending then confirm, OR use the dedicated promote
        // method if EnrollmentService exposes one. (Implementer: confirm the exact
        // waitlist→confirmed single-item method at task start; if none, the correct
        // sequence is updateStatus(Pending) then confirmRegistration — but verify it
        // fires grantAccess + the confirmed event exactly once.)
        return $enrollment->confirmRegistration($id);
    });
}

public function handleBulkApprovePostCourse(mixed $data, array $params): array|WP_Error
{
    if ($deny = $this->denyIfNotManager()) {
        return $deny;
    }
    return $this->runBulk($params, function (int $id, object $reg): true|WP_Error {
        $completion = ntdst_get(EnrollmentCompletion::class);
        return $completion->completeTask($id, 'post_approval'); // D1: wrap domain call, not approvePostCourse(WP_REST_Request)
    });
}

public function handleBulkMessage(mixed $data, array $params): array|WP_Error
{
    if ($deny = $this->denyIfNotManager()) {
        return $deny;
    }
    // D3: NotificationService has no templated-email send. Scope to the in-app
    // notification path OR ndmail_send with an admin-message template — decide at
    // task start. If neither resolves cleanly, ship the minimal in-app notification
    // and record the email-broadcast variant as deferred (project_mail_broadcast_feature,
    // lives in netdust-mail). Do NOT invent a new mail pipeline here.
    return $this->runBulk($params, function (int $id, object $reg) use ($params): true|WP_Error {
        // implement the chosen transport per row; return WP_Error on failure.
        return true;
    });
}

public function handleBulkGenerateDoc(mixed $data, array $params): array|WP_Error
{
    if ($deny = $this->denyIfNotManager()) {
        return $deny;
    }
    // D4: no clean per-registration deliverable generator exists; field-scoped
    // export is Phase 3 (spec §6/§9). For 1C either wire the narrowest existing
    // per-row artifact that has a clean call, or ship a deferred stub returning a
    // per-row "nog niet beschikbaar" failure. Decide at task start; do not build
    // the Phase-3 export here.
    return $this->runBulk($params, function (int $id, object $reg): true|WP_Error {
        return new WP_Error('not_available', __('Documentgeneratie volgt in een latere fase.', 'stride'));
    });
}
```

- [ ] **Step 4: Run, verify pass**

Run: `ddev exec vendor/bin/phpunit --testsuite Unit --filter BulkRegistrationHandler`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add web/app/mu-plugins/stride-core/Handlers/BulkRegistrationHandler.php tests/Unit/Handlers/BulkRegistrationHandlerTest.php
git commit -m "feat(admin-bulk): quote/waitlist/post-course/message/doc bulk handlers (task 2.2)

Quote status via QuoteRepository::updateStatus (Sent/Exported, no paid field);
waitlist promote with per-row capacity re-check + INV-7 effective-status gate;
post-course wraps completeTask('post_approval'); message/doc flagged per D3/D4."
```

**Tier A. Test contract:** `stride_bulk_quote_exported` sets quote `status=Exported` via `QuoteRepository::updateStatus` and touches NO "paid" field (none exists, V11); `stride_bulk_promote_waitlist` skips a full edition (`countConfirmedForEdition >= getCapacity`, gated by `getEffectiveStatus`, INV-7) into `failed[]` with `capacity_full`; every new handler denies a view-only actor before the loop (M2 inherited).
**Plan-correction (D3/D4):** `stride_bulk_message` and `stride_bulk_generate_doc` have no clean single-item reuse target — implement minimal/deferred per the notes; do not force a new pipeline.

---

### Task 2.3: `stride_bulk_set_field` with server-side allowlist (M7)

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Handlers/BulkRegistrationHandler.php`
- Test: extends `tests/Unit/Handlers/BulkRegistrationHandlerTest.php`

**Interfaces:**
- Consumes: `RegistrationRepository::update(int $id, array $fields): bool` (verify the exact mutator name at task start — `AbstractRepository` provides `update`; confirm the registration repo exposes a column setter for `notes`/`tags`/`company_id`).
- Produces: `handleBulkSetField(mixed $data, array $params): array|WP_Error`; the allowlist constant `private const SAFE_FIELDS = ['notes', 'tags', 'company_id'];`.

- [ ] **Step 1: Write the failing test (load-bearing M7 denial — RED first)**

```php
/** M7 (load-bearing): a lifecycle field is rejected 400 server-side regardless of UI. */
public function test_bulk_set_field_rejects_status_field_400(): void
{
    $result = $this->handler->handleBulkSetField([], ['ids' => [101], 'field' => 'status', 'value' => 'confirmed']);
    $this->assertInstanceOf(\WP_Error::class, $result);
    $this->assertSame('invalid_field', $result->get_error_code());
    $this->assertSame(400, $result->get_error_data()['status'] ?? null);
}

public function test_bulk_set_field_rejects_completed_at_400(): void
{
    $result = $this->handler->handleBulkSetField([], ['ids' => [101], 'field' => 'completed_at', 'value' => '2026-01-01']);
    $this->assertInstanceOf(\WP_Error::class, $result);
    $this->assertSame(400, $result->get_error_data()['status'] ?? null);
}

/** Safe column succeeds. */
public function test_bulk_set_field_allows_tags(): void
{
    $regId = $this->seedConfirmedRegistration();
    $report = $this->handler->handleBulkSetField([], ['ids' => [$regId], 'field' => 'tags', 'value' => 'vip']);
    $this->assertCount(1, $report['succeeded']);
}

/** M2 inherited. */
public function test_bulk_set_field_denies_view_only(): void
{
    global $current_user_caps;
    $current_user_caps = ['stride_manage' => false];
    $this->assertInstanceOf(\WP_Error::class, $this->handler->handleBulkSetField([], ['ids' => [1], 'field' => 'tags', 'value' => 'x']));
}
```

- [ ] **Step 2: Run, verify fail**

Run: `ddev exec vendor/bin/phpunit --testsuite Unit --filter BulkRegistrationHandler`
Expected: FAIL — `handleBulkSetField` not defined.

- [ ] **Step 3: Implement — allowlist check BEFORE any write**

```php
private const SAFE_FIELDS = ['notes', 'tags', 'company_id'];

// In init():
add_filter('ntdst/api_data/stride_bulk_set_field', [$this, 'handleBulkSetField'], 10, 2);

public function handleBulkSetField(mixed $data, array $params): array|WP_Error
{
    if ($deny = $this->denyIfNotManager()) {
        return $deny; // M2 first
    }

    $field = sanitize_key((string) ($params['field'] ?? ''));

    // M7: server-side allowlist — reject lifecycle/any non-safe field with 400,
    // BEFORE touching a single row. The UI restriction is cosmetic; this is the control.
    if (!in_array($field, self::SAFE_FIELDS, true)) {
        return new WP_Error('invalid_field', __('Dit veld kan niet in bulk worden gewijzigd.', 'stride'), ['status' => 400]);
    }

    $value = $field === 'company_id'
        ? absint($params['value'] ?? 0)
        : sanitize_text_field((string) ($params['value'] ?? ''));

    $repo = ntdst_get(RegistrationRepository::class);

    return $this->runBulk($params, function (int $id, object $reg) use ($repo, $field, $value): true|WP_Error {
        if (!$repo->update($id, [$field => $value])) { // confirm the mutator at task start
            return new WP_Error('update_failed', __('Veld kon niet worden bijgewerkt.', 'stride'));
        }
        return true;
    });
}
```

- [ ] **Step 4: Run, verify pass**

Run: `ddev exec vendor/bin/phpunit --testsuite Unit --filter BulkRegistrationHandler`
Expected: PASS — the `status`/`completed_at` payloads return 400.

- [ ] **Step 5: Commit**

```bash
git add web/app/mu-plugins/stride-core/Handlers/BulkRegistrationHandler.php tests/Unit/Handlers/BulkRegistrationHandlerTest.php
git commit -m "feat(admin-bulk): stride_bulk_set_field with server-side allowlist (task 2.3)

M7: rejects any field outside {notes,tags,company_id} with 400 before any write;
lifecycle fields (status/completed_at) refused regardless of payload."
```

**Tier A. Test contract (load-bearing M7 denial, RED-first):** setting `notes`/`company_id` succeeds; **a payload with `field=status` (or `completed_at`) is rejected 400 server-side regardless of UI** — the integrity guard; plus M2 view-only denial inherited.

> **RESOLVED (controller decision, 2026-06-22) — `tags` dropped from the safe set.** Ground-truth at implementation found `tags` has **no backing column, no meta, and no setter anywhere** on `wp_vad_registrations` (schema lines 51-80) — it appears ONLY in the spec's §2.2/§4.1 allowlist, in no mockup, with no store. Including it would make `bulk_set_field` report `succeeded[]` while persisting nothing (a success-lie). Shipped `SAFE_FIELDS = {notes, company_id}` (and added the real `company_id` column to `RegistrationRepository::update()`'s allowlist, where it had been silently no-op'd). **Spec §2.2 + §4.1 should be corrected to `{notes, company_id}`; the Phase 1D bulk-bar UI must NOT expose a `tags` set-field option.** Re-introduce `tags` only when a real registration-tagging feature (column/meta + UI) is designed — out of scope for Phase 1.

---

### Task 2.4: Cache-bust events for bulk (M10)

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Admin/AdminDashboardService.php:80-85` (add two `add_action` lines)
- Modify: `web/app/mu-plugins/stride-core/Handlers/BulkRegistrationHandler.php` (fire the new events)
- Test: `tests/Integration/BulkCacheBustTest.php`

**Interfaces:**
- Consumes: the existing `$invalidateQueue = fn() => delete_transient('stride_action_queue');` (V12).
- Produces: two new event names on the bust list — `stride/registration/bulk_completed`, `stride/registration/quote_status_changed` — fired by the handler via `do_action` (D5).

- [ ] **Step 1: Write the failing integration test**

```php
<?php
declare(strict_types=1);

namespace Stride\Tests\Integration;

use WP_UnitTestCase;

class BulkCacheBustTest extends WP_UnitTestCase
{
    public function test_bulk_completed_event_busts_action_queue(): void
    {
        set_transient('stride_action_queue', ['stale' => true], 300);
        do_action('stride/registration/bulk_completed', ['count' => 3]);
        $this->assertFalse(get_transient('stride_action_queue'), 'bulk_completed must bust the queue');
    }

    public function test_quote_status_changed_event_busts_action_queue(): void
    {
        set_transient('stride_action_queue', ['stale' => true], 300);
        do_action('stride/registration/quote_status_changed', ['registration_id' => 1]);
        $this->assertFalse(get_transient('stride_action_queue'), 'quote_status_changed must bust the queue');
    }
}
```
*(The AdminDashboardService bust hooks register on its constructor/init; the integration bootstrap loads the plugin so the listeners are wired. If they are gated to admin context, ensure the test sets an admin user — mirror how other integration tests boot the dashboard service.)*

- [ ] **Step 2: Run, verify fail**

Run: `ddev exec vendor/bin/phpunit -c phpunit-integration.xml.dist --filter BulkCacheBust`
Expected: FAIL — the transient is NOT busted (no listener for the new events yet).

- [ ] **Step 3: Add the two events to the bust list**

In `AdminDashboardService.php` after line 85 (V12):
```php
        add_action('stride/registration/bulk_completed', $invalidateQueue);
        add_action('stride/registration/quote_status_changed', $invalidateQueue);
```

In `BulkRegistrationHandler.php`, fire the events (D5 — `do_action` directly, full `stride/` prefix, the handler is not an AbstractService):
- After EACH `setQuoteStatusForRows` batch that had ≥1 success, fire once:
```php
        do_action('stride/registration/quote_status_changed', ['count' => count($report['succeeded'] ?? [])]);
```
- At the end of EVERY bulk handler (a coarse "a batch finished, recount"), fire once:
```php
        do_action('stride/registration/bulk_completed', ['summary' => $report['summary'] ?? []]);
```
*(Wire these into `runBulk`'s return path or each handler's tail. `runBulk` returns the report array; fire `bulk_completed` from each public handler after `runBulk` returns, and `quote_status_changed` only from the quote handlers. Per-row `stride/registration/{confirmed,cancelled}` already fire inside the domain methods — inherited, M10.)*

- [ ] **Step 4: Run, verify pass**

Run: `ddev exec vendor/bin/phpunit -c phpunit-integration.xml.dist --filter BulkCacheBust`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add web/app/mu-plugins/stride-core/Admin/AdminDashboardService.php web/app/mu-plugins/stride-core/Handlers/BulkRegistrationHandler.php tests/Integration/BulkCacheBustTest.php
git commit -m "feat(admin-bulk): cache-bust events for bulk completion (task 2.4)

M10: bulk_completed + quote_status_changed added to the stride_action_queue bust
list; handler fires them via do_action (thin handler, not AbstractService::dispatch)."
```

**Tier A. Test contract:** firing a bulk batch busts `stride_action_queue` — the new `stride/registration/bulk_completed` + `stride/registration/quote_status_changed` events are in the bust list (M10). Per-row `{confirmed,cancelled}` busts are inherited from the domain methods.

---

**Integration gate (cluster B):** full bulk suite green —
`ddev exec vendor/bin/phpunit --testsuite Unit --filter BulkRegistrationHandler` and
`ddev exec vendor/bin/phpunit -c phpunit-integration.xml.dist --filter 'BulkCacheBust'`.
Manual (as coordinator, same-origin, with a freshly minted `wp_create_nonce('stride_bulk_approve')`):
POST `…/wp-json/ntdst/v1/action` `{action: stride_bulk_approve, nonce, ids:[a,b,INVALID]}` → report shows 2 ok / 1 failed; as a `stride_view`-only user → `403 forbidden`; POST `stride_bulk_set_field` `{field:status}` → `400 invalid_field`.
Lint: `ddev exec composer lint` (Pint twice, then PHPStan). **HALT — `/code-review` + `/security-review` (FULL) on the cluster-B diff before starting Phase 1D.** Reviewer brief: "Verify against threat-model M2/M3/M6/M7/M9/M10; INV-1/2/3/4/7; the no-second-code-path rule (D1)."

---

## Sibling-site audit (sweep when this code is touched)

- **The RegistrationStatus transition map.** `RegistrationTransitions` (V15) is the ONE source of valid transitions. The bulk handlers' per-row `tryFrom`+transition checks (Task 2.1/2.2) MUST derive from it — a hard-coded status list in the handler is drift. (When Phase 1D builds the bulk-bar, its client-side state-aware actions mirror this map; when Phase 2 builds the cohort roster, same.)
- **The offerte-status proxy.** `stride_bulk_quote_sent`/`exported` (Task 2.2) set `QuoteStatus` via `updateStatus` — the SAME field the grid offerte column, the Vandaag "Offerte-opvolging" queue, and `AnnualReportService::quoteAggregates()` read. Do not introduce a second "paid-proxy" definition; there is no `Paid`.
- **No second code path.** Every bulk per-row call routes through the verified single-item domain method (`confirmRegistration`/`cancel`/`completeTask`/`updateStatus`) — `lesson_pure_passthrough_is_drift`. A reviewer seeing the handler re-implement seat-release or LD-grant logic instead of calling `cancel()`/`confirmRegistration()` should flag it.
- **INV-2 no re-verify.** A `wp_verify_nonce` call inside any `BulkRegistrationHandler` method is a finding — the framework already verified it (V2/V3).

---

## Self-review (against the spec)

- **Spec coverage (cluster B = Tasks 2.1–2.4):** 2.1 bulk-approve+cancel ✓ · 2.2 quote/waitlist/message/post-course/doc ✓ · 2.3 set_field allowlist ✓ · 2.4 cache-bust ✓. The ~9 smart actions + 1 generic + 2 events all map to a task.
- **Threat model:** M2 (Task 2.1 gate + denial test) · M3 (per-row existence/transition) · M6 (framework, V3) · M7 (Task 2.3 load-bearing 400 denial) · M9 (report shape + no-double-grant, corrected by D2) · M10 (Task 2.4) — all cited to a task.
- **Invariants:** INV-1 (M2), INV-2 (no re-verify), INV-3 (repository-only), INV-4 (WP_Error into failed[]), INV-7 (waitlist effective-status gate) — all cited.
- **Gate/tier:** FULL, 4 tasks, single `── REVIEW GATE B ──`, ~0 SQL strangle (targets already thin). ✓
- **Drift flagged:** D1 (wrap domain sequence not controller method) · D2 (confirmRegistration is not a silent no-op) · D3 (no NotificationService email send) · D4 (no per-reg doc generator → Phase 3) · D5 (handler fires do_action, no AbstractService::dispatch) · D6 (test path is a new subdir). Each baked into the relevant task.
- **Per-task tiers:** all four Tier A with RED-first test contracts; the load-bearing denials (M2 403-before-loop in 2.1, M7 field=status 400 in 2.3) are explicit RED tests.

## Execution handoff

Plan complete and saved to `docs/plans/2026-06-22-admin-workspace-1c-bulk-handlers.md`.

**1. Subagent-Driven (recommended)** — fresh subagent per task (2.1 → 2.2 → 2.3 → 2.4), review between tasks, single FULL review gate at cluster close.

**2. Inline Execution** — execute the four tasks in this session with a checkpoint at each commit, then the cluster-B integration gate + FULL review.

Which approach?
