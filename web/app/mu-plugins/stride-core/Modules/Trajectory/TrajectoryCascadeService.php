<?php

declare(strict_types=1);

namespace Stride\Modules\Trajectory;

use Stride\Contracts\LMSAdapterInterface;
use Stride\Domain\RegistrationStatus;
use Stride\Modules\Edition\EditionService;
use Stride\Modules\Enrollment\RegistrationRepository;
use Stride\Modules\Invoicing\QuoteService;
use WP_Error;

/**
 * Cascade-enrollment coordinator for trajectories.
 *
 * Given a parent trajectory-registration, this service materialises the
 * matching child edition-registrations (or pure-LD course grants) and tears
 * them down on cancellation. It is a pure coordinator: no hooks, no boot-time
 * side-effects. Resolved via DI autowiring from callers in
 * `TrajectorySelection` and `EnrollmentService`.
 *
 * Parent row shape: `trajectory_id` set, `edition_id` NULL,
 *   `parent_registration_id` NULL, `enrollment_path = trajectory`.
 * Child row shape:  `trajectory_id` NULL, `edition_id` set,
 *   `parent_registration_id = <parent.id>`, `enrollment_path = trajectory`.
 *
 * Pure-LD courses (no edition) are NOT child rows — they live in user-meta
 * `_stride_trajectory_courses` so LD access can be granted/revoked.
 *
 * Status cascade is mode-aware: `cohort` trajectories propagate parent
 * cancellation + status changes to children; `self_paced` does not.
 *
 * See `plans/2026-05-20-trajectory-cascade-enrollment.md` for the full
 * contract and decision log.
 */
final class TrajectoryCascadeService
{
    public const TRAJECTORY_COURSES_META_KEY = '_stride_trajectory_courses';

    public function __construct(
        private readonly RegistrationRepository $registrations,
        private readonly TrajectoryRepository $trajectories,
        private readonly EditionService $editions,
        private readonly LMSAdapterInterface $lms,
        private readonly QuoteService $quotes,
    ) {}

    /**
     * Cascade on initial trajectory enrollment.
     *
     * Walks the trajectory's required courses:
     *  - Course has a linked edition → create a child registration on that edition.
     *  - Course has no edition (pure LD) → grant LD access + append to user-meta.
     *
     * Electives are NOT handled here — see `cascadeOnSelection()`. Idempotent:
     * re-running for the same parent skips rows that already exist.
     */
    public function cascadeOnEnrollment(int $parentRegistrationId): void
    {
        $parent = $this->registrations->find($parentRegistrationId);
        if (!$parent || empty($parent->trajectory_id) || !empty($parent->edition_id)) {
            return;
        }

        $trajectoryId = (int) $parent->trajectory_id;
        $userId = (int) ($parent->user_id ?? 0);
        if ($userId <= 0) {
            return;
        }

        $existingChildEditionIds = $this->existingChildEditionIds($parentRegistrationId);

        foreach ($this->trajectories->getRequiredCourses($trajectoryId) as $course) {
            $config = $course->trajectory_config ?? [];
            $type = $config['type'] ?? 'online';
            $editionId = (int) ($config['edition_id'] ?? 0);

            // Pure-LD course (no edition) — Stap 5 handles grant + user-meta.
            if ($type !== 'edition' || $editionId <= 0) {
                continue;
            }

            if (isset($existingChildEditionIds[$editionId])) {
                continue;
            }

            // Skip if the user already has a registration on this edition from
            // any path (direct, partner, another trajectory). Cascade never
            // promotes an existing row to a child — the link would lose its
            // original context. Admin can manually reassign if needed.
            $existing = $this->registrations->findByUserAndEdition($userId, $editionId);
            if ($existing !== null) {
                ntdst_log('enrollment')->info('Cascade: user already on edition, skipping child creation', [
                    'parent_registration_id' => $parentRegistrationId,
                    'user_id' => $userId,
                    'edition_id' => $editionId,
                    'existing_registration_id' => (int) $existing->id,
                ]);
                continue;
            }

            $childId = $this->registrations->create([
                'user_id' => $userId,
                'edition_id' => $editionId,
                'parent_registration_id' => $parentRegistrationId,
                'company_id' => isset($parent->company_id) ? (int) $parent->company_id : null,
                'enrolled_by' => isset($parent->enrolled_by) ? (int) $parent->enrolled_by : null,
                'status' => (string) ($parent->status ?? RegistrationStatus::Confirmed->value),
                'enrollment_path' => RegistrationRepository::PATH_TRAJECTORY,
            ]);

            if (is_wp_error($childId)) {
                ntdst_log('enrollment')->warning('Cascade: failed to create child registration', [
                    'parent_registration_id' => $parentRegistrationId,
                    'trajectory_id' => $trajectoryId,
                    'edition_id' => $editionId,
                    'error' => $childId->get_error_code(),
                    'message' => $childId->get_error_message(),
                ]);
                continue;
            }

            $existingChildEditionIds[$editionId] = true;
        }
    }

    /**
     * Cascade on elective-selection change.
     *
     * For each chosen edition: create a child registration if not already
     * present. Children for editions no longer in the selection are
     * cancelled. Returns `WP_Error('edition_full', ...)` if any selected
     * edition has no remaining capacity — the selections JSON has already
     * been saved at the call site, but no child row is created for that
     * edition; the caller surfaces the error to the user.
     *
     * @param array<int> $editionIds Currently selected elective edition IDs.
     */
    public function cascadeOnSelection(int $parentRegistrationId, array $editionIds): WP_Error|true
    {
        // Stap 6
        return true;
    }

    /**
     * Cascade parent cancellation to children.
     *
     * `cohort` trajectories: bulk-cancel all children + revoke LD access for
     * pure-LD grants tied to this parent, then drop those meta entries.
     * `self_paced` trajectories: no-op — children stay enrolled.
     */
    public function cascadeOnCancellation(int $parentRegistrationId): void
    {
        // Stap 7
    }

    /**
     * Cascade parent status change to children.
     *
     * `cohort` trajectories: propagate `$newStatus` to all children.
     * `self_paced` trajectories: no-op — children manage their own status.
     */
    public function cascadeOnStatusChange(int $parentRegistrationId, string $newStatus): void
    {
        // Stap 8
    }

    /**
     * Index existing children of a parent by edition_id for fast idempotency
     * checks. Cancelled children are included — re-enrolling a user into the
     * same trajectory after their cohort cancelled them would otherwise
     * double-row. The reactivation path on `RegistrationRepository::create()`
     * handles refresh of cancelled rows when needed.
     *
     * @return array<int, true> Map of edition_id => true
     */
    private function existingChildEditionIds(int $parentRegistrationId): array
    {
        $children = $this->registrations->findByParent($parentRegistrationId);
        $index = [];
        foreach ($children as $child) {
            $editionId = (int) ($child->edition_id ?? 0);
            if ($editionId > 0) {
                $index[$editionId] = true;
            }
        }
        return $index;
    }

    /**
     * Auto-generate a child quote when the parent trajectory is free but the
     * child edition is paid.
     *
     * Default behaviour for cascade-created children is `quote_id = NULL` —
     * the parent trajectory's quote is the source of truth for billing.
     * Exception: when trajectory price is €0 AND the child edition price is
     * > €0, we create a per-child quote inheriting the parent's billing
     * info, since there's no parent quote to bill against.
     *
     * Never blocks enrollment — the child row exists regardless of quote
     * outcome.
     */
    private function maybeCreateChildQuote(int $childRegistrationId, int $parentRegistrationId): void
    {
        // Stap 6 (called from cascadeOnSelection) and Stap 4 (mandatory paid editions)
    }
}
