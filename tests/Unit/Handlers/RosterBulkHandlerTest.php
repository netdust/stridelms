<?php

declare(strict_types=1);

namespace Stride\Tests\Unit\Handlers;

use Stride\Handlers\RosterBulkHandler;
use Stride\Modules\Enrollment\EnrollmentCompletion;
use Stride\Modules\Enrollment\EnrollmentService;
use Stride\Modules\Enrollment\RegistrationRepository;
use Stride\Tests\TestCase;

/**
 * Unit tests for RosterBulkHandler (Admin Workspace, Phase 2a, Task 2a.7).
 *
 * The roster bulk surface is the cohort-lens manifestation of the Phase-1C bulk
 * actions, with ONE new load-bearing control on top of the inherited M2/M3/§674:
 *
 *   CM-1 (load-bearing) — every stride_roster_bulk_* action takes the OPENED
 *     edition scope (edition_id) as a REQUIRED param. Each row id is verified to
 *     belong to that edition BEFORE mutation (find($id)->edition_id === $editionId).
 *     A row from a DIFFERENT edition (C-ATK-1, the cross-edition confused-deputy)
 *     lands in failed[] with out_of_scope and is NEVER mutated. The scope is
 *     computed once from the opened edition, not re-derived per row from input.
 *
 *   M2 (inherited) — stride_manage checked FIRST, before the loop; a view-only
 *     actor is denied entirely (403), no rows processed.
 *
 *   §674 (inherited) — the action's validity for a row derives from the ONE
 *     RegistrationTransitions map, not a hard-coded status list.
 *
 *   M9 (inherited) — the {total, succeeded, failed, summary} per-row report shape,
 *     reusing the Phase-1 runBulk engine.
 */
class RosterBulkHandlerTest extends TestCase
{
    private RosterBulkHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        global $_test_current_user_id, $current_user_caps;
        $_test_current_user_id = 1;
        $current_user_caps = ['stride_manage' => true]; // entitled by default
        $this->handler = new RosterBulkHandler();
    }

    // =========================================================================
    // CM-1 — edition-scoped per-row authorization (the load-bearing mitigation)
    // =========================================================================

    /**
     * CM-1 (RED-first, load-bearing): a row that belongs to a DIFFERENT edition
     * than the opened scope lands in failed[] with out_of_scope and is NEVER
     * mutated — the foreign-edition confirm path is never entered.
     */
    public function test_roster_bulk_approve_foreign_edition_row_lands_in_failed_out_of_scope(): void
    {
        // Opened roster scope = edition 10. The smuggled row belongs to edition 99.
        $foreignRow = (object) ['id' => 700, 'status' => 'pending', 'edition_id' => 99];
        $repo = $this->createMock(RegistrationRepository::class);
        $repo->method('find')->willReturn($foreignRow);
        ntdst_set(RegistrationRepository::class, $repo);

        // The domain confirm path MUST NOT be entered for an out-of-scope row.
        $completion = $this->createMock(EnrollmentCompletion::class);
        $completion->expects($this->never())->method('completeTask');
        ntdst_set(EnrollmentCompletion::class, $completion);

        $enrollment = $this->createMock(EnrollmentService::class);
        $enrollment->expects($this->never())->method('confirmRegistration');
        ntdst_set(EnrollmentService::class, $enrollment);

        $report = $this->handler->handleRosterBulkApprove([], ['edition_id' => 10, 'ids' => [700]]);

        $this->assertIsArray($report);
        $this->assertCount(0, $report['succeeded']);
        $this->assertCount(1, $report['failed']);
        $this->assertSame(700, $report['failed'][0]['id']);
        $this->assertSame('out_of_scope', $report['failed'][0]['code']);
    }

    /**
     * CM-1 mixed payload: an in-scope, valid-transition row succeeds; a
     * foreign-edition row in the SAME payload lands in failed[] out_of_scope.
     * Proves the scope check is per-row, not all-or-nothing, and that the scope
     * is bound to the opened edition (10), not the foreign row's own edition.
     */
    public function test_roster_bulk_approve_mixed_payload_partial_report(): void
    {
        $rows = [
            // In scope (edition 10), Pending -> Confirmed is a valid transition.
            801 => (object) ['id' => 801, 'status' => 'pending', 'edition_id' => 10],
            // Out of scope (edition 99) — must be rejected before any mutation.
            802 => (object) ['id' => 802, 'status' => 'pending', 'edition_id' => 99],
        ];
        $repo = $this->createMock(RegistrationRepository::class);
        $repo->method('find')->willReturnCallback(fn(int $id) => $rows[$id] ?? null);
        ntdst_set(RegistrationRepository::class, $repo);

        $completion = $this->createMock(EnrollmentCompletion::class);
        // Only the in-scope row reaches the domain path — exactly once, for 801.
        $completion->expects($this->once())->method('completeTask')->with(801, 'approval')->willReturn(true);
        ntdst_set(EnrollmentCompletion::class, $completion);

        $enrollment = $this->createMock(EnrollmentService::class);
        $enrollment->expects($this->once())->method('confirmRegistration')->with(801)->willReturn(true);
        ntdst_set(EnrollmentService::class, $enrollment);

        $report = $this->handler->handleRosterBulkApprove([], ['edition_id' => 10, 'ids' => [801, 802]]);

        $this->assertSame(2, $report['total']);
        $this->assertCount(1, $report['succeeded']);
        $this->assertSame(801, $report['succeeded'][0]['id']);
        $this->assertCount(1, $report['failed']);
        $this->assertSame(802, $report['failed'][0]['id']);
        $this->assertSame('out_of_scope', $report['failed'][0]['code']);
    }

    /**
     * CM-1 guard: a missing/zero edition_id scope is refused with a 400 BEFORE
     * any row is touched. The scope param is REQUIRED — a scope-less roster bulk
     * is the exact failure mode (a confused-deputy with no edition binding) the
     * mitigation forbids; it must never fall through to a global mutation.
     */
    public function test_roster_bulk_approve_requires_edition_scope(): void
    {
        $repo = $this->createMock(RegistrationRepository::class);
        $repo->expects($this->never())->method('find');
        ntdst_set(RegistrationRepository::class, $repo);

        $result = $this->handler->handleRosterBulkApprove([], ['ids' => [801]]);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('missing_scope', $result->get_error_code());
        $this->assertSame(400, $result->get_error_data()['status'] ?? null);
    }

    // =========================================================================
    // M2 — capability denied BEFORE the loop (inherited confused-deputy gate)
    // =========================================================================

    /** M2: a view-only actor is denied 403 before the loop — no rows processed. */
    public function test_roster_bulk_approve_denies_view_only_actor_before_loop(): void
    {
        global $current_user_caps;
        $current_user_caps = ['stride_manage' => false]; // view-only

        $repo = $this->createMock(RegistrationRepository::class);
        $repo->expects($this->never())->method('find');
        ntdst_set(RegistrationRepository::class, $repo);

        $result = $this->handler->handleRosterBulkApprove([], ['edition_id' => 10, 'ids' => [801, 802]]);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('forbidden', $result->get_error_code());
        $this->assertSame(403, $result->get_error_data()['status'] ?? null);
    }

    /** M2 inherited: EVERY roster action denies a view-only actor before the loop. */
    public function test_all_roster_actions_deny_view_only(): void
    {
        global $current_user_caps;
        $current_user_caps = ['stride_manage' => false];

        $methods = [
            'handleRosterBulkApprove',
            'handleRosterBulkMessage',
            'handleRosterBulkGenerateDoc',
        ];
        foreach ($methods as $m) {
            $result = $this->handler->$m([], ['edition_id' => 10, 'ids' => [1]]);
            $this->assertInstanceOf(\WP_Error::class, $result, "$m must deny a view-only actor");
            $this->assertSame('forbidden', $result->get_error_code(), "$m denial code");
            $this->assertSame(403, $result->get_error_data()['status'] ?? null, "$m denial status");
        }
    }

    // =========================================================================
    // §674 — transition validity derives from RegistrationTransitions, not a list
    // =========================================================================

    /**
     * §674: an in-scope row whose state does NOT permit the action (Completed is
     * terminal in the map) lands in failed[] with invalid_status — the validity
     * derives from RegistrationTransitions, not a hard-coded literal, and the
     * domain confirm path is never entered (no double-apply).
     */
    public function test_roster_bulk_approve_in_scope_terminal_row_rejected_via_transition_map(): void
    {
        // In scope (edition 10) but Completed is terminal — Completed -> Confirmed
        // is NOT in the transition map.
        $completedRow = (object) ['id' => 810, 'status' => 'completed', 'edition_id' => 10];
        $repo = $this->createMock(RegistrationRepository::class);
        $repo->method('find')->willReturn($completedRow);
        ntdst_set(RegistrationRepository::class, $repo);

        $completion = $this->createMock(EnrollmentCompletion::class);
        $completion->expects($this->never())->method('completeTask');
        ntdst_set(EnrollmentCompletion::class, $completion);

        $enrollment = $this->createMock(EnrollmentService::class);
        $enrollment->expects($this->never())->method('confirmRegistration');
        ntdst_set(EnrollmentService::class, $enrollment);

        $report = $this->handler->handleRosterBulkApprove([], ['edition_id' => 10, 'ids' => [810]]);

        $this->assertCount(0, $report['succeeded']);
        $this->assertCount(1, $report['failed']);
        $this->assertSame('invalid_status', $report['failed'][0]['code']);
    }

    // =========================================================================
    // M9 — happy path + report shape (reuses the Phase-1 runBulk engine)
    // =========================================================================

    /** M9: an in-scope Pending row proceeds through the SAME domain sequence as Phase-1C approve. */
    public function test_roster_bulk_approve_in_scope_pending_proceeds(): void
    {
        $pendingRow = (object) ['id' => 820, 'status' => 'pending', 'edition_id' => 10];
        $repo = $this->createMock(RegistrationRepository::class);
        $repo->method('find')->willReturn($pendingRow);
        ntdst_set(RegistrationRepository::class, $repo);

        $completion = $this->createMock(EnrollmentCompletion::class);
        $completion->expects($this->once())->method('completeTask')->with(820, 'approval')->willReturn(true);
        ntdst_set(EnrollmentCompletion::class, $completion);

        $enrollment = $this->createMock(EnrollmentService::class);
        $enrollment->expects($this->once())->method('confirmRegistration')->with(820)->willReturn(true);
        ntdst_set(EnrollmentService::class, $enrollment);

        $report = $this->handler->handleRosterBulkApprove([], ['edition_id' => 10, 'ids' => [820]]);

        $this->assertCount(1, $report['succeeded']);
        $this->assertCount(0, $report['failed']);
    }

    /** M9 report shape: total/succeeded/failed/summary present even for an empty selection. */
    public function test_roster_bulk_approve_returns_per_row_report_shape(): void
    {
        $report = $this->handler->handleRosterBulkApprove([], ['edition_id' => 10, 'ids' => []]);
        $this->assertSame(['total', 'succeeded', 'failed', 'summary'], array_keys($report));
        $this->assertSame(0, $report['total']);
        $this->assertSame(['ok' => 0, 'error' => 0], $report['summary']);
    }

    /** M3: a non-existent in-scope row id lands in failed[] with not_found (scope check skips a null row safely). */
    public function test_roster_bulk_approve_nonexistent_row_lands_in_failed_not_found(): void
    {
        $repo = $this->createMock(RegistrationRepository::class);
        $repo->method('find')->willReturn(null);
        ntdst_set(RegistrationRepository::class, $repo);

        $report = $this->handler->handleRosterBulkApprove([], ['edition_id' => 10, 'ids' => [99999999]]);

        $this->assertSame(1, $report['total']);
        $this->assertCount(1, $report['failed']);
        $this->assertSame('not_found', $report['failed'][0]['code']);
    }

    // =========================================================================
    // Message / generate-doc — inherit the Phase-1C honest deferred-stub caveat
    // =========================================================================

    /** D3 caveat: roster message is an HONEST deferred stub — never a silent success — and is edition-scoped. */
    public function test_roster_bulk_message_is_deferred_stub_all_failed(): void
    {
        $row = (object) ['id' => 830, 'status' => 'confirmed', 'edition_id' => 10];
        $repo = $this->createMock(RegistrationRepository::class);
        $repo->method('find')->willReturn($row);
        ntdst_set(RegistrationRepository::class, $repo);

        $report = $this->handler->handleRosterBulkMessage([], ['edition_id' => 10, 'ids' => [830]]);

        $this->assertCount(0, $report['succeeded']);
        $this->assertCount(1, $report['failed']);
        $this->assertSame('not_available', $report['failed'][0]['code']);
    }

    /** D4 caveat: roster generate-doc is an HONEST deferred stub too. */
    public function test_roster_bulk_generate_doc_is_deferred_stub_all_failed(): void
    {
        $row = (object) ['id' => 831, 'status' => 'confirmed', 'edition_id' => 10];
        $repo = $this->createMock(RegistrationRepository::class);
        $repo->method('find')->willReturn($row);
        ntdst_set(RegistrationRepository::class, $repo);

        $report = $this->handler->handleRosterBulkGenerateDoc([], ['edition_id' => 10, 'ids' => [831]]);

        $this->assertCount(0, $report['succeeded']);
        $this->assertCount(1, $report['failed']);
        $this->assertSame('not_available', $report['failed'][0]['code']);
    }

    /**
     * CM-1 over message/doc too: the edition scope guards EVERY roster action, not
     * just approve. A foreign-edition row never reaches even the deferred-stub
     * per-row path — it is rejected as out_of_scope first.
     */
    public function test_roster_bulk_message_foreign_edition_row_out_of_scope(): void
    {
        $foreignRow = (object) ['id' => 840, 'status' => 'confirmed', 'edition_id' => 99];
        $repo = $this->createMock(RegistrationRepository::class);
        $repo->method('find')->willReturn($foreignRow);
        ntdst_set(RegistrationRepository::class, $repo);

        $report = $this->handler->handleRosterBulkMessage([], ['edition_id' => 10, 'ids' => [840]]);

        $this->assertCount(1, $report['failed']);
        $this->assertSame('out_of_scope', $report['failed'][0]['code']);
    }

    // =========================================================================
    // CM-1 trajectory variant (Task 2a.8, B2 fix) — the scope is the MULTI-USER
    // trajectory child set via findChildRegistrationIdsByTrajectory(), NOT the
    // per-user findEditionsByTrajectory. A row from another trajectory → out_of_scope.
    // =========================================================================

    /**
     * CM-1 trajectory variant (RED-first, load-bearing): the trajectory-roster
     * bulk takes a REQUIRED trajectory_id; the scope set is computed ONCE from
     * findChildRegistrationIdsByTrajectory(). A row id NOT in that set (a row from
     * ANOTHER trajectory smuggled into the payload) lands in failed[] out_of_scope
     * and is NEVER mutated — the domain confirm path is never entered.
     */
    public function test_traj_roster_bulk_approve_foreign_trajectory_row_out_of_scope(): void
    {
        // Opened trajectory scope = T1; its child set is {700}. The smuggled row
        // 999 belongs to ANOTHER trajectory (not in T1's child set).
        $inScopeRow = (object) ['id' => 700, 'status' => 'pending', 'edition_id' => 50];
        $foreignRow = (object) ['id' => 999, 'status' => 'pending', 'edition_id' => 60];
        $repo = $this->createMock(RegistrationRepository::class);
        $repo->method('findChildRegistrationIdsByTrajectory')->with(1)->willReturn([700]);
        $repo->method('find')->willReturnCallback(fn(int $id) => $id === 700 ? $inScopeRow : $foreignRow);
        ntdst_set(RegistrationRepository::class, $repo);

        $completion = $this->createMock(EnrollmentCompletion::class);
        $completion->expects($this->never())->method('completeTask');
        ntdst_set(EnrollmentCompletion::class, $completion);

        $enrollment = $this->createMock(EnrollmentService::class);
        $enrollment->expects($this->never())->method('confirmRegistration');
        ntdst_set(EnrollmentService::class, $enrollment);

        $report = $this->handler->handleTrajRosterBulkApprove([], ['trajectory_id' => 1, 'ids' => [999]]);

        $this->assertIsArray($report);
        $this->assertCount(0, $report['succeeded']);
        $this->assertCount(1, $report['failed']);
        $this->assertSame(999, $report['failed'][0]['id']);
        $this->assertSame('out_of_scope', $report['failed'][0]['code']);
    }

    /**
     * CM-1 trajectory mixed payload: an in-scope row (in T1's child set, valid
     * transition) succeeds; a foreign-trajectory row in the SAME payload is
     * out_of_scope. Proves the scope is the trajectory child set, computed once,
     * per-row enforced.
     */
    public function test_traj_roster_bulk_approve_mixed_payload_partial_report(): void
    {
        $rows = [
            700 => (object) ['id' => 700, 'status' => 'pending', 'edition_id' => 50],
            999 => (object) ['id' => 999, 'status' => 'pending', 'edition_id' => 60],
        ];
        $repo = $this->createMock(RegistrationRepository::class);
        $repo->method('findChildRegistrationIdsByTrajectory')->with(1)->willReturn([700]);
        $repo->method('find')->willReturnCallback(fn(int $id) => $rows[$id] ?? null);
        ntdst_set(RegistrationRepository::class, $repo);

        $completion = $this->createMock(EnrollmentCompletion::class);
        $completion->expects($this->once())->method('completeTask')->with(700, 'approval')->willReturn(true);
        ntdst_set(EnrollmentCompletion::class, $completion);

        $enrollment = $this->createMock(EnrollmentService::class);
        $enrollment->expects($this->once())->method('confirmRegistration')->with(700)->willReturn(true);
        ntdst_set(EnrollmentService::class, $enrollment);

        $report = $this->handler->handleTrajRosterBulkApprove([], ['trajectory_id' => 1, 'ids' => [700, 999]]);

        $this->assertSame(2, $report['total']);
        $this->assertCount(1, $report['succeeded']);
        $this->assertSame(700, $report['succeeded'][0]['id']);
        $this->assertCount(1, $report['failed']);
        $this->assertSame(999, $report['failed'][0]['id']);
        $this->assertSame('out_of_scope', $report['failed'][0]['code']);
    }

    /**
     * CM-1 trajectory guard: a missing/zero trajectory_id scope is refused 400
     * BEFORE any row or the scope-set query is touched.
     */
    public function test_traj_roster_bulk_approve_requires_trajectory_scope(): void
    {
        $repo = $this->createMock(RegistrationRepository::class);
        $repo->expects($this->never())->method('findChildRegistrationIdsByTrajectory');
        $repo->expects($this->never())->method('find');
        ntdst_set(RegistrationRepository::class, $repo);

        $result = $this->handler->handleTrajRosterBulkApprove([], ['ids' => [700]]);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('missing_scope', $result->get_error_code());
        $this->assertSame(400, $result->get_error_data()['status'] ?? null);
    }

    /** M2 inherited: the trajectory-roster action denies a view-only actor before the loop. */
    public function test_traj_roster_bulk_approve_denies_view_only_before_loop(): void
    {
        global $current_user_caps;
        $current_user_caps = ['stride_manage' => false];

        $repo = $this->createMock(RegistrationRepository::class);
        $repo->expects($this->never())->method('findChildRegistrationIdsByTrajectory');
        $repo->expects($this->never())->method('find');
        ntdst_set(RegistrationRepository::class, $repo);

        $result = $this->handler->handleTrajRosterBulkApprove([], ['trajectory_id' => 1, 'ids' => [700]]);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('forbidden', $result->get_error_code());
        $this->assertSame(403, $result->get_error_data()['status'] ?? null);
    }

    /** M9 happy path: an in-scope pending row proceeds through the SAME domain sequence. */
    public function test_traj_roster_bulk_approve_in_scope_pending_proceeds(): void
    {
        $pendingRow = (object) ['id' => 700, 'status' => 'pending', 'edition_id' => 50];
        $repo = $this->createMock(RegistrationRepository::class);
        $repo->method('findChildRegistrationIdsByTrajectory')->with(1)->willReturn([700]);
        $repo->method('find')->willReturn($pendingRow);
        ntdst_set(RegistrationRepository::class, $repo);

        $completion = $this->createMock(EnrollmentCompletion::class);
        $completion->expects($this->once())->method('completeTask')->with(700, 'approval')->willReturn(true);
        ntdst_set(EnrollmentCompletion::class, $completion);

        $enrollment = $this->createMock(EnrollmentService::class);
        $enrollment->expects($this->once())->method('confirmRegistration')->with(700)->willReturn(true);
        ntdst_set(EnrollmentService::class, $enrollment);

        $report = $this->handler->handleTrajRosterBulkApprove([], ['trajectory_id' => 1, 'ids' => [700]]);

        $this->assertCount(1, $report['succeeded']);
        $this->assertCount(0, $report['failed']);
    }
}
