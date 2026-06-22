<?php

declare(strict_types=1);

namespace Stride\Tests\Unit\Handlers;

use Stride\Handlers\BulkRegistrationHandler;
use Stride\Modules\Enrollment\EnrollmentCompletion;
use Stride\Modules\Enrollment\EnrollmentService;
use Stride\Modules\Enrollment\RegistrationRepository;
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
}
