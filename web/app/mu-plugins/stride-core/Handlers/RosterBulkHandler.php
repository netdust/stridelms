<?php

declare(strict_types=1);

namespace Stride\Handlers;

use Stride\Domain\RegistrationStatus;
use Stride\Handlers\Support\BulkRunner;
use Stride\Modules\Enrollment\EnrollmentCompletion;
use Stride\Modules\Enrollment\EnrollmentService;
use Stride\Modules\Enrollment\RegistrationTransitions;
use WP_Error;

/**
 * Cohort-lens roster bulk handlers (Admin Workspace, Phase 2a, Task 2a.7).
 *
 * The roster is a per-EDITION surface, so these actions add ONE load-bearing
 * control on top of the Phase-1C bulk pattern (which the global Inschrijvingen
 * grid uses): an edition scope every row must belong to.
 *
 *   CM-1 (load-bearing, C-ATK-1) — every stride_roster_bulk_* action takes the
 *     OPENED edition scope (`edition_id`) as a REQUIRED param. Each row id is
 *     verified to belong to that edition BEFORE mutation
 *     (RegistrationRepository::find($id)->edition_id === $editionId — find() is
 *     SELECT * and returns edition_id). A row from a DIFFERENT edition (the
 *     cross-edition confused-deputy: a stride_manage actor opens edition A's
 *     roster then POSTs ids belonging to edition B) lands in failed[] with
 *     out_of_scope and is NEVER mutated. The scope is computed ONCE from the
 *     opened edition param, never re-derived per row from the row's own data.
 *
 * Layered ON TOP of the inherited Phase-1C controls (via the shared BulkRunner):
 *   M2 — denyIfNotManager() FIRST, before the loop (a view-only actor → 403).
 *   M3 — per-row existence (a missing row → failed[] not_found).
 *   §674 — the action's validity for a row derives from the ONE
 *          RegistrationTransitions map, never a hard-coded status list.
 *   M9 — the {total, succeeded, failed, summary} per-row report (runBulk).
 *
 * Registration: on the ntdst/api_data registry, which has ALREADY verified the
 * per-action rate-limit + Origin/Referer CSRF + wp_verify_nonce($nonce, $action)
 * + anon-gate before the filter fires (INV-2) — this handler does NOT re-add
 * nonce/CSRF; it owns the AUTHZ (M2) + the per-row scope (CM-1).
 *
 * DECISION (handler vs extend BulkRegistrationHandler): a SEPARATE handler. The
 * edition_id scope is a REQUIRED, load-bearing param that has NO place on the
 * global-grid actions (the grid is intentionally cross-edition). Folding an
 * optional scope into BulkRegistrationHandler would risk either the global
 * actions silently inheriting an edition scope or the roster actions running
 * scope-less — the exact CM-1 failure mode. A separate handler makes "the roster
 * bulk is edition-scoped" structural and un-forgettable. The two share the
 * per-row-report ENGINE (the BulkRunner trait, single source) and the single-item
 * domain paths (confirmRegistration / RegistrationTransitions), not the front
 * door. Message / generate-doc inherit the Phase-1C honest deferred-stub caveat
 * (no clean per-registration mail/doc target — netdust-mail owns broadcast,
 * field-scoped doc export is Phase 3): each per-row result is an explicit
 * not_available WP_Error, never a silent success, and the M2 + CM-1 gates still
 * apply.
 */
final class RosterBulkHandler
{
    use BulkRunner;

    public function __construct()
    {
        $this->init();
    }

    private function init(): void
    {
        add_filter('ntdst/api_data/stride_roster_bulk_approve', [$this, 'handleRosterBulkApprove'], 10, 2);
        add_filter('ntdst/api_data/stride_roster_bulk_message', [$this, 'handleRosterBulkMessage'], 10, 2);
        add_filter('ntdst/api_data/stride_roster_bulk_generate_doc', [$this, 'handleRosterBulkGenerateDoc'], 10, 2);

        // Trajectory-roster variant (Task 2a.8) — scoped to a trajectory's
        // MULTI-USER child set, not an edition.
        add_filter('ntdst/api_data/stride_traj_roster_bulk_approve', [$this, 'handleTrajRosterBulkApprove'], 10, 2);
        add_filter('ntdst/api_data/stride_traj_roster_bulk_message', [$this, 'handleTrajRosterBulkMessage'], 10, 2);
        add_filter('ntdst/api_data/stride_traj_roster_bulk_generate_doc', [$this, 'handleTrajRosterBulkGenerateDoc'], 10, 2);
    }

    /**
     * CM-1 — resolve and validate the REQUIRED opened-edition scope.
     *
     * Returns the absint'd edition id, or a 400 WP_Error when the scope is
     * absent/zero. A scope-less roster bulk is the confused-deputy with no edition
     * binding — it must NEVER fall through to a global mutation, so the scope is
     * mandatory and checked before the loop.
     *
     * @param array<string,mixed> $params
     * @return int|WP_Error
     */
    private function requireEditionScope(array $params): int|WP_Error
    {
        $editionId = absint($params['edition_id'] ?? 0);
        if ($editionId <= 0) {
            return new WP_Error('missing_scope', __('Geen editie-scope opgegeven.', 'stride'), ['status' => 400]);
        }

        return $editionId;
    }

    /**
     * Build the per-row edition scope: M2 gate then the required edition_id.
     * Returns the resolved edition id on success, or a WP_Error to short-circuit
     * (denial / missing scope). Shared front door for every stride_roster_bulk_*
     * action so the M2 gate + the CM-1 scope can't be forgotten — the mirror of
     * resolveTrajectoryScopeLookup (which returns the child-id lookup; this returns
     * the single edition id, since the edition scope is one value, not a set).
     *
     * @param array<string,mixed> $params
     * @return int|WP_Error
     */
    private function resolveEditionScope(array $params): int|WP_Error
    {
        if ($deny = $this->denyIfNotManager()) {
            return $deny; // M2 first
        }

        return $this->requireEditionScope($params); // CM-1: scope required
    }

    /**
     * CM-1 per-row scope predicate — the row must belong to the opened edition.
     *
     * Returns a WP_Error('out_of_scope') for a foreign-edition row (so the caller
     * routes it into failed[] without mutation), or null when the row is in scope.
     * Both sides are cast to int and compared strictly (!==): find() returns
     * edition_id as the raw DB value (a string from $wpdb), so casting it normalises
     * the type before the strict compare. A foreign-edition row — or a NULL/0-edition
     * trajectory-parent row — mismatches and is rejected. Fail-closed: never loose ==
     * juggling, which would let "10" == 10 hide a type confusion in the scope guard.
     *
     * @return WP_Error|null
     */
    private function denyIfOutOfScope(object $registration, int $editionId): ?WP_Error
    {
        if ((int) ($registration->edition_id ?? 0) !== $editionId) {
            return new WP_Error('out_of_scope', __('Deze inschrijving hoort niet bij deze editie.', 'stride'));
        }

        return null;
    }

    // =========================================================================
    // CM-1 trajectory variant (Task 2a.8, B2 fix) — the scope is a trajectory's
    // MULTI-USER child set, NOT an edition and NOT the per-user
    // findEditionsByTrajectory() (which cannot serve a multi-user bulk scope).
    // =========================================================================

    /**
     * CM-1 (trajectory) — resolve and validate the REQUIRED opened-trajectory scope.
     *
     * Returns the absint'd trajectory id, or a 400 WP_Error when absent/zero. A
     * scope-less trajectory-roster bulk is the confused-deputy with no trajectory
     * binding — it must NEVER fall through to a global mutation, so the scope is
     * mandatory and checked before the loop (mirror of requireEditionScope).
     *
     * @param array<string,mixed> $params
     * @return int|WP_Error
     */
    private function requireTrajectoryScope(array $params): int|WP_Error
    {
        $trajectoryId = absint($params['trajectory_id'] ?? 0);
        if ($trajectoryId <= 0) {
            return new WP_Error('missing_scope', __('Geen traject-scope opgegeven.', 'stride'), ['status' => 400]);
        }

        return $trajectoryId;
    }

    /**
     * CM-1 (trajectory) per-row scope predicate — the row must be in the opened
     * trajectory's child set.
     *
     * The scope SET is computed ONCE from
     * RegistrationRepository::findChildRegistrationIdsByTrajectory() (the MULTI-USER
     * parent->child join, §676) and passed in; a row id absent from it (a row from
     * ANOTHER trajectory smuggled into the payload, C-ATK-1) gets a WP_Error so the
     * caller routes it into failed[] without mutation. Returns null when in scope.
     *
     * @param array<int,bool> $scopeLookup id => true for ids in the trajectory child set.
     * @return WP_Error|null
     */
    private function denyIfNotInTrajectorySet(int $id, array $scopeLookup): ?WP_Error
    {
        if (!isset($scopeLookup[$id])) {
            return new WP_Error('out_of_scope', __('Deze inschrijving hoort niet bij dit traject.', 'stride'));
        }

        return null;
    }

    /**
     * Build the per-row trajectory scope: M2 gate, required trajectory_id, and the
     * computed-once child-id lookup. Returns the lookup map on success, or a
     * WP_Error to short-circuit (denial / missing scope). Shared front door for
     * every stride_traj_roster_bulk_* action so the scope can't be forgotten.
     *
     * @param array<string,mixed> $params
     * @return array<int,bool>|WP_Error
     */
    private function resolveTrajectoryScopeLookup(array $params): array|WP_Error
    {
        if ($deny = $this->denyIfNotManager()) {
            return $deny; // M2 first
        }

        $trajectoryId = $this->requireTrajectoryScope($params); // CM-1: scope required
        if (is_wp_error($trajectoryId)) {
            return $trajectoryId;
        }

        // CM-1: compute the scope set ONCE from the opened trajectory — never
        // re-derive it per row from the row's own (client-supplied) data.
        $repo = ntdst_get(\Stride\Modules\Enrollment\RegistrationRepository::class);
        $childIds = $repo->findChildRegistrationIdsByTrajectory($trajectoryId);

        return array_fill_keys($childIds, true);
    }

    /**
     * stride_traj_roster_bulk_approve — pending/interest/waitlist → confirmed,
     * scoped to the opened trajectory's MULTI-USER child set.
     *
     * Same M2 + §674 + M9 layering as the edition variant; the ONLY difference is
     * the scope predicate (trajectory child set vs a single edition_id).
     *
     * @param array<string,mixed> $params
     * @return array{total:int, succeeded:array<int,array{id:int}>, failed:array<int,array{id:int,code:string,message:string}>, summary:array{ok:int,error:int}}|WP_Error
     */
    public function handleTrajRosterBulkApprove(mixed $data, array $params): array|WP_Error
    {
        $scopeLookup = $this->resolveTrajectoryScopeLookup($params);
        if (is_wp_error($scopeLookup)) {
            return $scopeLookup;
        }

        return $this->finishBatch($this->runBulk($params, function (int $id, object $reg) use ($scopeLookup): true|WP_Error {
            // CM-1: the foreign-trajectory confused-deputy check runs FIRST.
            if ($scope = $this->denyIfNotInTrajectorySet($id, $scopeLookup)) {
                return $scope;
            }

            // §674: derive validity from the ONE transition map, not a literal.
            $from = RegistrationStatus::tryFrom((string) $reg->status);
            if ($from === null || !RegistrationTransitions::isAllowed($from, RegistrationStatus::Confirmed)) {
                return new WP_Error('invalid_status', __('Deze inschrijving kan niet goedgekeurd worden.', 'stride'));
            }

            $completion = ntdst_get(EnrollmentCompletion::class);
            $enrollment = ntdst_get(EnrollmentService::class);

            $task = $completion->completeTask($id, 'approval');
            if (is_wp_error($task)) {
                return $task;
            }

            return $enrollment->confirmRegistration($id);
        }));
    }

    /**
     * stride_traj_roster_bulk_message — HONEST DEFERRED STUB (inherits Phase-1C D3),
     * trajectory-scoped. A foreign-trajectory row is out_of_scope before the stub.
     *
     * @param array<string,mixed> $params
     * @return array{total:int, succeeded:array<int,array{id:int}>, failed:array<int,array{id:int,code:string,message:string}>, summary:array{ok:int,error:int}}|WP_Error
     */
    public function handleTrajRosterBulkMessage(mixed $data, array $params): array|WP_Error
    {
        $scopeLookup = $this->resolveTrajectoryScopeLookup($params);
        if (is_wp_error($scopeLookup)) {
            return $scopeLookup;
        }

        return $this->finishBatch($this->runBulk($params, function (int $id, object $reg) use ($scopeLookup): true|WP_Error {
            if ($scope = $this->denyIfNotInTrajectorySet($id, $scopeLookup)) {
                return $scope;
            }

            return new WP_Error('not_available', __('Berichten versturen volgt in een latere fase.', 'stride'));
        }));
    }

    /**
     * stride_traj_roster_bulk_generate_doc — HONEST DEFERRED STUB (inherits
     * Phase-1C D4), trajectory-scoped.
     *
     * @param array<string,mixed> $params
     * @return array{total:int, succeeded:array<int,array{id:int}>, failed:array<int,array{id:int,code:string,message:string}>, summary:array{ok:int,error:int}}|WP_Error
     */
    public function handleTrajRosterBulkGenerateDoc(mixed $data, array $params): array|WP_Error
    {
        $scopeLookup = $this->resolveTrajectoryScopeLookup($params);
        if (is_wp_error($scopeLookup)) {
            return $scopeLookup;
        }

        return $this->finishBatch($this->runBulk($params, function (int $id, object $reg) use ($scopeLookup): true|WP_Error {
            if ($scope = $this->denyIfNotInTrajectorySet($id, $scopeLookup)) {
                return $scope;
            }

            return new WP_Error('not_available', __('Documentgeneratie volgt in een latere fase.', 'stride'));
        }));
    }

    /**
     * stride_roster_bulk_approve — pending/interest/waitlist → confirmed, scoped
     * to the opened edition.
     *
     * Reuses the SAME domain sequence the Phase-1C grid approve uses
     * (completeTask('approval') then confirmRegistration), gated per row by:
     *   1. M2 (denyIfNotManager, before the loop),
     *   2. CM-1 (denyIfOutOfScope, FIRST inside the per-row closure — a foreign
     *      row is rejected before the transition check or any mutation),
     *   3. §674 (RegistrationTransitions::isAllowed, the ONE map).
     *
     * @param array<string,mixed> $params
     * @return array{total:int, succeeded:array<int,array{id:int}>, failed:array<int,array{id:int,code:string,message:string}>, summary:array{ok:int,error:int}}|WP_Error
     */
    public function handleRosterBulkApprove(mixed $data, array $params): array|WP_Error
    {
        $editionId = $this->resolveEditionScope($params);
        if (is_wp_error($editionId)) {
            return $editionId;
        }

        return $this->finishBatch($this->runBulk($params, function (int $id, object $reg) use ($editionId): true|WP_Error {
            // CM-1: the foreign-edition confused-deputy check runs FIRST, before
            // the transition check and before any domain mutation. A row from
            // another edition never reaches confirmRegistration.
            if ($scope = $this->denyIfOutOfScope($reg, $editionId)) {
                return $scope;
            }

            // §674: derive validity from the ONE transition map, not a literal.
            $from = RegistrationStatus::tryFrom((string) $reg->status);
            if ($from === null || !RegistrationTransitions::isAllowed($from, RegistrationStatus::Confirmed)) {
                return new WP_Error('invalid_status', __('Deze inschrijving kan niet goedgekeurd worden.', 'stride'));
            }

            $completion = ntdst_get(EnrollmentCompletion::class);
            $enrollment = ntdst_get(EnrollmentService::class);

            $task = $completion->completeTask($id, 'approval');
            if (is_wp_error($task)) {
                return $task;
            }

            // confirmRegistration re-guards (invalid_status for non-pending), so an
            // already-confirmed row never double-grants.
            return $enrollment->confirmRegistration($id);
        }));
    }

    /**
     * stride_roster_bulk_message — HONEST DEFERRED STUB (inherits Phase-1C D3).
     *
     * Templated-email broadcast lives in netdust-mail, not Stride; there is no
     * clean per-registration templated-send. Each per-row result is an explicit
     * not_available WP_Error — never a silent success — so the UI can disable the
     * action. The M2 gate + the CM-1 per-row edition scope still apply: a foreign
     * row is reported out_of_scope, an in-scope row not_available.
     *
     * @param array<string,mixed> $params
     * @return array{total:int, succeeded:array<int,array{id:int}>, failed:array<int,array{id:int,code:string,message:string}>, summary:array{ok:int,error:int}}|WP_Error
     */
    public function handleRosterBulkMessage(mixed $data, array $params): array|WP_Error
    {
        $editionId = $this->resolveEditionScope($params);
        if (is_wp_error($editionId)) {
            return $editionId;
        }

        return $this->finishBatch($this->runBulk($params, function (int $id, object $reg) use ($editionId): true|WP_Error {
            if ($scope = $this->denyIfOutOfScope($reg, $editionId)) {
                return $scope;
            }

            return new WP_Error('not_available', __('Berichten versturen volgt in een latere fase.', 'stride'));
        }));
    }

    /**
     * stride_roster_bulk_generate_doc — HONEST DEFERRED STUB (inherits Phase-1C D4).
     *
     * Field-scoped per-registration deliverable export is explicitly Phase 3; no
     * clean per-row generator exists today. As with message, each per-row result
     * is an explicit not_available WP_Error — never a silent success — and the
     * M2 + CM-1 gates still apply.
     *
     * @param array<string,mixed> $params
     * @return array{total:int, succeeded:array<int,array{id:int}>, failed:array<int,array{id:int,code:string,message:string}>, summary:array{ok:int,error:int}}|WP_Error
     */
    public function handleRosterBulkGenerateDoc(mixed $data, array $params): array|WP_Error
    {
        $editionId = $this->resolveEditionScope($params);
        if (is_wp_error($editionId)) {
            return $editionId;
        }

        return $this->finishBatch($this->runBulk($params, function (int $id, object $reg) use ($editionId): true|WP_Error {
            if ($scope = $this->denyIfOutOfScope($reg, $editionId)) {
                return $scope;
            }

            return new WP_Error('not_available', __('Documentgeneratie volgt in een latere fase.', 'stride'));
        }));
    }
}
