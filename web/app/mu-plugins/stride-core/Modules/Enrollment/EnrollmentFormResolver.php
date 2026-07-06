<?php

declare(strict_types=1);

namespace Stride\Modules\Enrollment;

use Stride\Domain\OfferingStatus;
use Stride\Modules\Edition\EditionService;
use Stride\Modules\Trajectory\TrajectoryService;
use Stride\Modules\User\ProfileTypePolicy;
use WP_Post;

/**
 * Resolves the props the enrollment template needs from an edition or
 * trajectory ID. Single source of truth for both the route handler
 * (`/edities|trajecten/<slug>/inschrijving/`) and the `[stride_enrollment]`
 * shortcode — without this, they drift apart and read different fields
 * (e.g. one reads form_type, the other doesn't).
 *
 * Returns a normalized shape with a `state` key the caller dispatches on:
 *
 *   'render'           → render the form template with the returned args
 *   'already_enrolled' → caller decides UX (router shows empty state,
 *                        shortcode shows inline notice)
 *   'closed'           → enrollment not currently possible
 *   'direct'           → admin asked for the no-form, enroll-immediately
 *                        path; caller drives the side-effect
 *
 * Plain class, autowired via DI. Resolved lazily by callers.
 */
final class EnrollmentFormResolver
{
    public function __construct(
        private readonly EnrollmentService $enrollmentService,
        private readonly RegistrationRepository $registrations,
    ) {}

    /**
     * Build template args for an enrollment form rendering an edition or
     * trajectory.
     *
     * @return array{
     *     state: string,
     *     item: WP_Post|null,
     *     item_id: int,
     *     item_type: string,
     *     item_data: array<string, mixed>,
     *     enrollment_mode: string,
     *     enrollment_open: bool,
     *     is_online: bool,
     *     form_type: string,
     *     already_enrolled: bool,
     * }
     */
    public function resolveTemplateArgs(WP_Post $item, string $itemType): array
    {
        $itemId = (int) $item->ID;
        $base = [
            'state' => 'render',
            'item' => $item,
            'item_id' => $itemId,
            'item_type' => $itemType,
            'item_data' => [
                'id' => $itemId,
                'title' => $item->post_title,
            ],
            'enrollment_mode' => 'enrollment',
            'enrollment_open' => false,
            'is_online' => false,
            'form_type' => 'default',
            'already_enrolled' => false,
        ];

        if ($itemType === 'edition') {
            return $this->resolveEdition($item, $base);
        }

        if ($itemType === 'trajectory') {
            return $this->resolveTrajectory($item, $base);
        }

        // Unknown type — caller should treat as closed.
        return ['state' => 'closed'] + $base;
    }

    /**
     * @param array<string, mixed> $base
     * @return array<string, mixed>
     */
    private function resolveEdition(WP_Post $edition, array $base): array
    {
        $editionId = (int) $edition->ID;
        $userId = get_current_user_id();

        if ($userId > 0 && $this->enrollmentService->hasActiveRegistration($userId, editionId: $editionId)) {
            $base['already_enrolled'] = true;
            $base['state'] = 'already_enrolled';
            return $base;
        }

        $editionService = ntdst_get(EditionService::class);
        $status = $editionService->getEffectiveStatus($editionId);
        $mode = $this->computeEnrollmentMode(
            $status,
            $editionService->requiresApproval($editionId),
            $status->allowsEnrollment() && $editionService->hasAvailableSpots($editionId),
        );

        $base['enrollment_mode'] = $mode;
        $base['enrollment_open'] = $mode !== 'closed';
        $base['is_online'] = $editionService->isOnline($editionId);
        $base['form_type'] = $editionService->getEnrollmentForm($editionId);

        if ($mode === 'closed') {
            $base['state'] = 'closed';
            return $base;
        }

        // M2: the form_type is SERVER-decided. If the user's stored profile type
        // routes to a minimal form for this edition, force it — this overrides the
        // edition's stored form_type and is never client-selectable. Read the
        // stored value's 'direct' intent BEFORE the override so the direct-state
        // dispatch below stays coherent (a direct enrollable still drives the
        // no-form side-effect; a minimal profile only changes which form renders).
        $wantsDirect = $base['form_type'] === 'direct';

        if (ntdst_get(ProfileTypePolicy::class)->usesMinimalForm($userId, $editionId, 'vad_edition')) {
            $base['form_type'] = 'minimal';
        }

        if ($wantsDirect) {
            $base['state'] = 'direct';
        }

        return $base;
    }

    /**
     * @param array<string, mixed> $base
     * @return array<string, mixed>
     */
    private function resolveTrajectory(WP_Post $trajectory, array $base): array
    {
        $trajectoryService = ntdst_get(TrajectoryService::class);
        $trajectoryId = (int) $trajectory->ID;
        $userId = get_current_user_id();

        $mode = $this->computeEnrollmentMode(
            $trajectoryService->getTrajectory($trajectoryId)['status_enum'] ?? OfferingStatus::Draft,
            $trajectoryService->requiresApproval($trajectoryId),
            $trajectoryService->isEnrollmentOpen($trajectoryId),
        );

        $base['enrollment_mode'] = $mode;
        $base['enrollment_open'] = $mode !== 'closed';
        $base['is_online'] = false;
        $base['form_type'] = $trajectoryService->getEnrollmentForm($trajectoryId);

        if ($mode === 'closed') {
            $base['state'] = 'closed';
        }

        // M2 parity with resolveEdition(): the user's stored profile type can force
        // a minimal form for this trajectory, overriding the stored form_type.
        // Server-decided, never client-selectable.
        if (ntdst_get(ProfileTypePolicy::class)->usesMinimalForm($userId, $trajectoryId, 'vad_trajectory')) {
            $base['form_type'] = 'minimal';
        }

        return $base;
    }

    private function computeEnrollmentMode(OfferingStatus $status, bool $requiresApproval, bool $enrollmentOpen): string
    {
        if ($status->allowsInterest()) {
            return 'interest';
        }

        if ($status->allowsWaitlist()) {
            return 'waitlist';
        }

        if ($enrollmentOpen) {
            return $requiresApproval ? 'pending_approval' : 'enrollment';
        }

        return 'closed';
    }
}
