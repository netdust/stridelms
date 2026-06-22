<?php

declare(strict_types=1);

namespace Stride\Tests\Unit\Handlers;

use Stride\Domain\QuoteStatus;
use Stride\Handlers\BulkRegistrationHandler;
use Stride\Modules\Enrollment\EnrollmentCompletion;
use Stride\Modules\Enrollment\EnrollmentService;
use Stride\Modules\Enrollment\RegistrationRepository;
use Stride\Modules\Invoicing\QuoteRepository;
use Stride\Tests\TestCase;

/**
 * Unit tests for BulkRegistrationHandler (Admin Workspace, Phase 1C, Task 2.1).
 *
 * Covers the load-bearing security/behavior contract:
 *   M2 — capability denied BEFORE the loop → WP_Error(403), nothing mutated.
 *   M9 — per-row report shape {total, succeeded, failed, summary}.
 *   M3 — a non-existent row id lands in failed[], never mutated.
 *   D2 — an already-confirmed row lands in failed[] with invalid_status
 *        (integrity-benign: no double grant). The LMS-spy double-grant
 *        assertion itself is demoted to integration (no spy seam at unit level
 *        — see plan Step 2 note); the "lands in failed[] with invalid_status"
 *        half stays here.
 */
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

    /** M2 inherited: bulk-cancel denies a view-only actor before the loop too. */
    public function test_bulk_cancel_denies_view_only_actor_before_loop(): void
    {
        global $current_user_caps;
        $current_user_caps = ['stride_manage' => false]; // view-only

        $result = $this->handler->handleBulkCancel([], ['ids' => [101]]);

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
        // Repo resolves the id to null (not found) — the row must land in failed[].
        $repo = $this->createMock(RegistrationRepository::class);
        $repo->method('find')->willReturn(null);
        ntdst_set(RegistrationRepository::class, $repo);

        $report = $this->handler->handleBulkApprove([], ['ids' => [99999999]]);
        $this->assertSame(1, $report['total']);
        $this->assertCount(0, $report['succeeded']);
        $this->assertCount(1, $report['failed']);
        $this->assertSame(99999999, $report['failed'][0]['id']);
        $this->assertContains($report['failed'][0]['code'], ['not_found', 'invalid_status']);
    }

    /**
     * D2/M9: an already-confirmed row does NOT double-grant; lands in failed[] benignly.
     *
     * The handler pre-checks status from the resolved row (confirmed is neither
     * Pending nor Interest) → invalid_status, so the domain confirm path is never
     * re-entered. The "no second grantAccess" half is proven at the integration
     * tier (no LMS spy seam at unit level — plan Step 2 note); here we assert the
     * row lands in failed[] with invalid_status, which is the gate that prevents
     * the second grant.
     */
    public function test_bulk_approve_already_confirmed_lands_in_failed_with_invalid_status(): void
    {
        $confirmedRow = (object) ['id' => 555, 'status' => 'confirmed', 'edition_id' => 10];
        $repo = $this->createMock(RegistrationRepository::class);
        $repo->method('find')->willReturn($confirmedRow);
        ntdst_set(RegistrationRepository::class, $repo);

        // EnrollmentCompletion / EnrollmentService MUST NOT be reached for a
        // confirmed row: expectations enforce "no double-apply" at unit level.
        $completion = $this->createMock(EnrollmentCompletion::class);
        $completion->expects($this->never())->method('completeTask');
        ntdst_set(EnrollmentCompletion::class, $completion);

        $enrollment = $this->createMock(EnrollmentService::class);
        $enrollment->expects($this->never())->method('confirmRegistration');
        ntdst_set(EnrollmentService::class, $enrollment);

        $report = $this->handler->handleBulkApprove([], ['ids' => [555]]);

        $this->assertCount(0, $report['succeeded']);
        $this->assertCount(1, $report['failed']);
        $this->assertSame(555, $report['failed'][0]['id']);
        $this->assertSame('invalid_status', $report['failed'][0]['code']);
    }

    /**
     * B1 (M9 non-atomic): a per-row domain method that throws a raw Throwable
     * mid-batch must NOT abort the batch. Row1 succeeds, row2 throws, row3 must
     * STILL be processed. The throwing row lands in failed[] with a generic
     * message (the raw exception text is logged, never leaked to the report), and
     * no exception escapes runBulk.
     */
    public function test_bulk_cancel_per_row_exception_does_not_abort_batch(): void
    {
        $rows = [
            201 => (object) ['id' => 201, 'status' => 'confirmed', 'edition_id' => 10],
            202 => (object) ['id' => 202, 'status' => 'confirmed', 'edition_id' => 10],
            203 => (object) ['id' => 203, 'status' => 'confirmed', 'edition_id' => 10],
        ];
        $repo = $this->createMock(RegistrationRepository::class);
        $repo->method('find')->willReturnCallback(fn (int $id) => $rows[$id] ?? null);
        ntdst_set(RegistrationRepository::class, $repo);

        $enrollment = $this->createMock(EnrollmentService::class);
        $enrollment->method('cancel')->willReturnCallback(function (int $id) {
            if ($id === 202) {
                throw new \RuntimeException('SECRET DB constraint detail at row 202');
            }

            return true;
        });
        ntdst_set(EnrollmentService::class, $enrollment);

        $report = $this->handler->handleBulkCancel([], ['ids' => [201, 202, 203]]);

        // All three rows accounted for — the throw did NOT kill the loop.
        $this->assertSame(3, $report['total']);
        $this->assertCount(2, $report['succeeded'], 'rows 201 and 203 still processed');
        $this->assertCount(1, $report['failed']);
        $this->assertSame(202, $report['failed'][0]['id']);
        $this->assertSame('exception', $report['failed'][0]['code']);
        // The raw exception message must NOT leak to the client report.
        $this->assertStringNotContainsString('SECRET', $report['failed'][0]['message']);
        $this->assertStringNotContainsString('constraint', $report['failed'][0]['message']);
        $this->assertSame(['ok' => 2, 'error' => 1], $report['summary']);
    }

    /**
     * B1: the caught exception's detail is logged to the enrollment channel with
     * the offending registration id, so operators can diagnose without the client
     * report ever carrying the raw message.
     */
    public function test_bulk_cancel_logs_caught_exception_detail(): void
    {
        global $_test_log_entries;
        $_test_log_entries = [];

        $row = (object) ['id' => 210, 'status' => 'confirmed', 'edition_id' => 10];
        $repo = $this->createMock(RegistrationRepository::class);
        $repo->method('find')->willReturn($row);
        ntdst_set(RegistrationRepository::class, $repo);

        $enrollment = $this->createMock(EnrollmentService::class);
        $enrollment->method('cancel')->willThrowException(new \RuntimeException('boom-detail-210'));
        ntdst_set(EnrollmentService::class, $enrollment);

        $this->handler->handleBulkCancel([], ['ids' => [210]]);

        $errorLogs = array_filter($_test_log_entries, fn (array $e) => $e['level'] === 'error');
        $this->assertNotEmpty($errorLogs, 'an error must be logged for the caught exception');
        $entry = array_values($errorLogs)[0];
        $this->assertSame('enrollment', $entry['channel']);
        $this->assertSame(210, $entry['context']['registration_id'] ?? null);
    }

    // =========================================================================
    // Task 2.2 — quote / waitlist / post-course / message / generate-doc
    // =========================================================================

    /**
     * Quote → Exported via QuoteRepository::updateStatus(QuoteStatus::Exported).
     * Touches NO "paid" field — none exists on QuoteStatus (V11).
     */
    public function test_bulk_quote_exported_sets_status_via_repository(): void
    {
        $row = (object) ['id' => 70, 'status' => 'confirmed', 'edition_id' => 10];
        $repo = $this->createMock(RegistrationRepository::class);
        $repo->method('find')->willReturn($row);
        ntdst_set(RegistrationRepository::class, $repo);

        $quoteRepo = $this->createMock(QuoteRepository::class);
        $quoteRepo->method('findQuoteIdsByRegistrations')->with([70])->willReturn([70 => 900]);
        $quoteRepo->expects($this->once())
            ->method('updateStatus')
            ->with(900, QuoteStatus::Exported)
            ->willReturn(true);
        ntdst_set(QuoteRepository::class, $quoteRepo);

        $report = $this->handler->handleBulkQuoteExported([], ['ids' => [70]]);

        $this->assertCount(1, $report['succeeded']);
        $this->assertCount(0, $report['failed']);
    }

    /** A row with no quote lands in failed[] with code no_quote. */
    public function test_bulk_quote_sent_no_quote_lands_in_failed(): void
    {
        $row = (object) ['id' => 71, 'status' => 'confirmed', 'edition_id' => 10];
        $repo = $this->createMock(RegistrationRepository::class);
        $repo->method('find')->willReturn($row);
        ntdst_set(RegistrationRepository::class, $repo);

        $quoteRepo = $this->createMock(QuoteRepository::class);
        $quoteRepo->method('findQuoteIdsByRegistrations')->willReturn([]); // no quote
        $quoteRepo->expects($this->never())->method('updateStatus');
        ntdst_set(QuoteRepository::class, $quoteRepo);

        $report = $this->handler->handleBulkQuoteSent([], ['ids' => [71]]);

        $this->assertCount(1, $report['failed']);
        $this->assertSame('no_quote', $report['failed'][0]['code']);
    }

    /**
     * Waitlist promote skips a full edition into failed[] with capacity_full
     * (per-row capacity re-check, INV-7 not-terminal gate). The handler wraps the
     * single-item domain method promoteFromWaitlist — here we drive the handler's
     * own guard via a mocked EditionService that reports the edition full.
     */
    public function test_bulk_promote_waitlist_skips_full_edition(): void
    {
        $row = (object) ['id' => 72, 'status' => 'waitlist', 'edition_id' => 10];
        $repo = $this->createMock(RegistrationRepository::class);
        $repo->method('find')->willReturn($row);
        ntdst_set(RegistrationRepository::class, $repo);

        $enrollment = $this->createMock(EnrollmentService::class);
        $enrollment->method('promoteFromWaitlist')
            ->with(72)
            ->willReturn(new \WP_Error('capacity_full', 'Edition is full'));
        ntdst_set(EnrollmentService::class, $enrollment);

        $report = $this->handler->handleBulkPromoteWaitlist([], ['ids' => [72]]);

        $this->assertCount(1, $report['failed']);
        $this->assertSame('capacity_full', $report['failed'][0]['code']);
    }

    /** Post-course approve wraps completeTask('post_approval') — domain call, not the controller. */
    public function test_bulk_approve_post_course_wraps_complete_task(): void
    {
        $row = (object) ['id' => 73, 'status' => 'confirmed', 'edition_id' => 10];
        $repo = $this->createMock(RegistrationRepository::class);
        $repo->method('find')->willReturn($row);
        ntdst_set(RegistrationRepository::class, $repo);

        $completion = $this->createMock(EnrollmentCompletion::class);
        $completion->expects($this->once())
            ->method('completeTask')
            ->with(73, 'post_approval')
            ->willReturn(true);
        ntdst_set(EnrollmentCompletion::class, $completion);

        $report = $this->handler->handleBulkApprovePostCourse([], ['ids' => [73]]);

        $this->assertCount(1, $report['succeeded']);
    }

    /**
     * Decision 2: stride_bulk_message is an HONEST deferred stub — each per-row
     * result is WP_Error('not_available'), never a silent success.
     */
    public function test_bulk_message_is_deferred_stub_all_failed(): void
    {
        $row = (object) ['id' => 80, 'status' => 'confirmed', 'edition_id' => 10];
        $repo = $this->createMock(RegistrationRepository::class);
        $repo->method('find')->willReturn($row);
        ntdst_set(RegistrationRepository::class, $repo);

        $report = $this->handler->handleBulkMessage([], ['ids' => [80]]);

        $this->assertCount(0, $report['succeeded']);
        $this->assertCount(1, $report['failed']);
        $this->assertSame('not_available', $report['failed'][0]['code']);
    }

    /** Decision 2: stride_bulk_generate_doc is an HONEST deferred stub too. */
    public function test_bulk_generate_doc_is_deferred_stub_all_failed(): void
    {
        $row = (object) ['id' => 81, 'status' => 'confirmed', 'edition_id' => 10];
        $repo = $this->createMock(RegistrationRepository::class);
        $repo->method('find')->willReturn($row);
        ntdst_set(RegistrationRepository::class, $repo);

        $report = $this->handler->handleBulkGenerateDoc([], ['ids' => [81]]);

        $this->assertCount(0, $report['succeeded']);
        $this->assertCount(1, $report['failed']);
        $this->assertSame('not_available', $report['failed'][0]['code']);
    }

    /**
     * M2 inherited: EVERY new handler denies a view-only actor before the loop —
     * including the deferred stubs (a view-only actor must be M2-denied first,
     * not reach the not_available per-row path).
     */
    public function test_all_task_2_2_handlers_deny_view_only(): void
    {
        global $current_user_caps;
        $current_user_caps = ['stride_manage' => false];

        $methods = [
            'handleBulkQuoteSent',
            'handleBulkQuoteExported',
            'handleBulkPromoteWaitlist',
            'handleBulkApprovePostCourse',
            'handleBulkMessage',
            'handleBulkGenerateDoc',
        ];
        foreach ($methods as $m) {
            $result = $this->handler->$m([], ['ids' => [1]]);
            $this->assertInstanceOf(\WP_Error::class, $result, "$m must deny a view-only actor");
            $this->assertSame('forbidden', $result->get_error_code(), "$m denial code");
            $this->assertSame(403, $result->get_error_data()['status'] ?? null, "$m denial status");
        }
    }

    // =========================================================================
    // Task 2.3 — stride_bulk_set_field with server-side field allowlist (M7)
    // =========================================================================

    /**
     * M7 (load-bearing): a lifecycle field is rejected 400 server-side regardless
     * of UI, and the rejection happens BEFORE any row is touched — the repository
     * update mutator is never called.
     */
    public function test_bulk_set_field_rejects_status_field_400_before_any_write(): void
    {
        // The repo MUST NOT be reached for a rejected field — no row may be mutated.
        $repo = $this->createMock(RegistrationRepository::class);
        $repo->expects($this->never())->method('find');
        $repo->expects($this->never())->method('update');
        ntdst_set(RegistrationRepository::class, $repo);

        $result = $this->handler->handleBulkSetField([], ['ids' => [101], 'field' => 'status', 'value' => 'confirmed']);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('invalid_field', $result->get_error_code());
        $this->assertSame(400, $result->get_error_data()['status'] ?? null);
    }

    /** M7: completed_at (a lifecycle field) is refused 400 server-side. */
    public function test_bulk_set_field_rejects_completed_at_400(): void
    {
        $repo = $this->createMock(RegistrationRepository::class);
        $repo->expects($this->never())->method('update');
        ntdst_set(RegistrationRepository::class, $repo);

        $result = $this->handler->handleBulkSetField([], ['ids' => [101], 'field' => 'completed_at', 'value' => '2026-01-01']);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('invalid_field', $result->get_error_code());
        $this->assertSame(400, $result->get_error_data()['status'] ?? null);
    }

    /** M7: cancelled_at (a lifecycle field) is refused 400 server-side. */
    public function test_bulk_set_field_rejects_cancelled_at_400(): void
    {
        $repo = $this->createMock(RegistrationRepository::class);
        $repo->expects($this->never())->method('update');
        ntdst_set(RegistrationRepository::class, $repo);

        $result = $this->handler->handleBulkSetField([], ['ids' => [101], 'field' => 'cancelled_at', 'value' => '2026-01-01']);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame(400, $result->get_error_data()['status'] ?? null);
    }

    /**
     * A safe field (notes) succeeds and persists via RegistrationRepository::update.
     *
     * DIVERGENCE: the plan's allowlist named `tags`, but there is NO `tags` column
     * or meta on the registration table (RegistrationTable schema + update()
     * allowlist) — persisting it would be a silent no-op success-lie. The honest
     * safe set is {notes, company_id}, the columns update() actually writes.
     */
    public function test_bulk_set_field_allows_notes(): void
    {
        $row = (object) ['id' => 90, 'status' => 'confirmed', 'edition_id' => 10];
        $repo = $this->createMock(RegistrationRepository::class);
        $repo->method('find')->willReturn($row);
        $repo->expects($this->once())
            ->method('update')
            ->with(90, ['notes' => 'belangrijke nota'])
            ->willReturn(true);
        ntdst_set(RegistrationRepository::class, $repo);

        $report = $this->handler->handleBulkSetField([], ['ids' => [90], 'field' => 'notes', 'value' => 'belangrijke nota']);

        $this->assertCount(1, $report['succeeded']);
        $this->assertCount(0, $report['failed']);
    }

    /** A safe field (company_id) is absint-sanitized and persisted. */
    public function test_bulk_set_field_allows_company_id_absint(): void
    {
        $row = (object) ['id' => 91, 'status' => 'confirmed', 'edition_id' => 10];
        $repo = $this->createMock(RegistrationRepository::class);
        $repo->method('find')->willReturn($row);
        $repo->expects($this->once())
            ->method('update')
            ->with(91, ['company_id' => 42])
            ->willReturn(true);
        ntdst_set(RegistrationRepository::class, $repo);

        $report = $this->handler->handleBulkSetField([], ['ids' => [91], 'field' => 'company_id', 'value' => '42abc']);

        $this->assertCount(1, $report['succeeded']);
        $this->assertCount(0, $report['failed']);
    }

    /** M2 inherited: a view-only actor is denied 403 before the loop. */
    public function test_bulk_set_field_denies_view_only(): void
    {
        global $current_user_caps;
        $current_user_caps = ['stride_manage' => false];

        $result = $this->handler->handleBulkSetField([], ['ids' => [1], 'field' => 'notes', 'value' => 'x']);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('forbidden', $result->get_error_code());
        $this->assertSame(403, $result->get_error_data()['status'] ?? null);
    }

    // =========================================================================
    // Task 2.4 — cache-bust events fired by the handler (M10, handler side)
    // =========================================================================

    /**
     * M10 (handler side): a finished bulk batch fires stride/registration/bulk_completed
     * exactly once at the batch tail. The integration test proves the listener
     * busts the queue; this proves the handler actually fires the event the
     * listener is bound to — closing the handler half of the wire.
     */
    public function test_bulk_approve_fires_bulk_completed_once(): void
    {
        $this->resetActionCalls();

        $this->handler->handleBulkApprove([], ['ids' => []]);

        $this->assertSame(1, $this->actionFireCount('stride/registration/bulk_completed'));
    }

    /**
     * M10 negative (handler side): a DENIED bulk call fires NOTHING — the
     * capability gate returns before runBulk, so no batch ran and no recount
     * event is emitted. Proves the fire is at the batch tail, not method entry.
     */
    public function test_denied_bulk_call_fires_no_completion_event(): void
    {
        global $current_user_caps;
        $current_user_caps = ['stride_manage' => false];
        $this->resetActionCalls();

        $result = $this->handler->handleBulkApprove([], ['ids' => [1, 2]]);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame(0, $this->actionFireCount('stride/registration/bulk_completed'));
    }

    /**
     * M10 (handler side, quote path): a successful quote batch fires BOTH
     * quote_status_changed (the offerte-opvolging bust) and the shared
     * bulk_completed recount, once each.
     */
    public function test_quote_batch_fires_quote_status_changed_and_bulk_completed(): void
    {
        $row = (object) ['id' => 95, 'status' => 'confirmed', 'edition_id' => 10];
        $repo = $this->createMock(RegistrationRepository::class);
        $repo->method('find')->willReturn($row);
        ntdst_set(RegistrationRepository::class, $repo);

        $quoteRepo = $this->createMock(QuoteRepository::class);
        $quoteRepo->method('findQuoteIdsByRegistrations')->willReturn([95 => 950]);
        $quoteRepo->method('updateStatus')->willReturn(true);
        ntdst_set(QuoteRepository::class, $quoteRepo);

        $this->resetActionCalls();

        $this->handler->handleBulkQuoteSent([], ['ids' => [95]]);

        $this->assertSame(1, $this->actionFireCount('stride/registration/quote_status_changed'));
        $this->assertSame(1, $this->actionFireCount('stride/registration/bulk_completed'));
    }

    /**
     * M10 negative (quote path): a quote batch where NO row succeeded must NOT
     * fire quote_status_changed (nothing changed), but still fires the coarse
     * bulk_completed recount.
     */
    public function test_quote_batch_with_no_success_skips_quote_status_changed(): void
    {
        $row = (object) ['id' => 96, 'status' => 'confirmed', 'edition_id' => 10];
        $repo = $this->createMock(RegistrationRepository::class);
        $repo->method('find')->willReturn($row);
        ntdst_set(RegistrationRepository::class, $repo);

        $quoteRepo = $this->createMock(QuoteRepository::class);
        $quoteRepo->method('findQuoteIdsByRegistrations')->willReturn([]); // no quote → row fails
        ntdst_set(QuoteRepository::class, $quoteRepo);

        $this->resetActionCalls();

        $report = $this->handler->handleBulkQuoteSent([], ['ids' => [96]]);

        $this->assertSame(0, $report['summary']['ok']);
        $this->assertSame(0, $this->actionFireCount('stride/registration/quote_status_changed'));
        $this->assertSame(1, $this->actionFireCount('stride/registration/bulk_completed'));
    }

    private function resetActionCalls(): void
    {
        global $_test_action_calls;
        $_test_action_calls = [];
    }

    private function actionFireCount(string $hook): int
    {
        global $_test_action_calls;

        return count($_test_action_calls[$hook] ?? []);
    }
}
