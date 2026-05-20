<?php

declare(strict_types=1);

namespace Stride\Modules\Trajectory;

use Stride\Domain\TrajectoryMode;
use Stride\Domain\OfferingStatus;
use Stride\Infrastructure\AbstractService;
use Stride\Modules\Enrollment\RegistrationRepository;
use WP_Error;
use WP_Post;

/**
 * Trajectory business logic.
 */
final class TrajectoryService extends AbstractService
{
    public function __construct(
        private readonly TrajectoryRepository $repository,
        private readonly RegistrationRepository $registrations,
    ) {
        parent::__construct();
    }

    public static function metadata(): array
    {
        return [
            'name' => 'Trajectory Service',
            'description' => 'Manages multi-course programs',
            'priority' => 20,
        ];
    }

    protected function getConfigSlug(): string
    {
        return 'trajectory';
    }

    protected function init(): void
    {
        TrajectoryCPT::register();

        // Register sub-components
        ntdst_set(TrajectoryDashboardService::class, fn() => new TrajectoryDashboardService(
            $this->repository,
            $this,
            $this->registrations,
            ntdst_get(\Stride\Modules\Edition\EditionService::class),
            ntdst_get(\Stride\Contracts\LMSAdapterInterface::class),
        ));

        new Admin\TrajectoryAdminController(
            $this,
            $this->repository,
            $this->registrations,
            ntdst_get(\Stride\Modules\Edition\EditionRepository::class),
        );

        // Cascade lifecycle listeners. EnrollmentService fires
        // stride/registration/{cancelled,confirmed} for every registration; we
        // only act on trajectory parents (trajectory_id set, no parent).
        add_action('stride/registration/cancelled', [$this, 'onRegistrationCancelled']);
        add_action('stride/registration/confirmed', [$this, 'onRegistrationConfirmed']);

        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::add_command('stride trajectory backfill-cascade', [$this, 'cliBackfillCascade']);
        }
    }

    /**
     * Cascade-cancel children when a trajectory parent is cancelled.
     *
     * Listens to the generic enrollment cancellation event so EnrollmentService
     * doesn't need to know about trajectories. Edition-only registrations
     * (parent_registration_id set, or no trajectory_id) are ignored.
     *
     * @param array{registration_id: int, user_id: int, edition_id: int} $data
     */
    public function onRegistrationCancelled(array $data): void
    {
        $registrationId = (int) ($data['registration_id'] ?? 0);
        if ($registrationId <= 0 || !$this->isTrajectoryParent($registrationId)) {
            return;
        }

        ntdst_get(TrajectoryCascadeService::class)->cascadeOnCancellation($registrationId);
    }

    /**
     * Cascade-propagate parent status to children when a trajectory parent is
     * confirmed (admin approves a pending trajectory enrollment).
     *
     * @param array{registration_id: int, user_id: int, edition_id: int} $data
     */
    public function onRegistrationConfirmed(array $data): void
    {
        $registrationId = (int) ($data['registration_id'] ?? 0);
        if ($registrationId <= 0 || !$this->isTrajectoryParent($registrationId)) {
            return;
        }

        ntdst_get(TrajectoryCascadeService::class)->cascadeOnStatusChange(
            $registrationId,
            \Stride\Domain\RegistrationStatus::Confirmed->value
        );
    }

    /**
     * A registration is a trajectory parent when it has trajectory_id set,
     * no edition_id, and no parent_registration_id (parents aren't themselves
     * children of a higher parent).
     */
    private function isTrajectoryParent(int $registrationId): bool
    {
        $registration = $this->registrations->find($registrationId);
        if (!$registration) {
            return false;
        }
        return !empty($registration->trajectory_id)
            && empty($registration->edition_id)
            && empty($registration->parent_registration_id);
    }

    /**
     * Backfill cascade children for existing trajectory parent enrollments.
     *
     * Walks confirmed + pending trajectory-parent rows and materialises the
     * child registrations that the cascade would have created if it had
     * been live at enrollment time. Dry-run by default; pass `--commit` to
     * actually run.
     *
     * ## OPTIONS
     *
     * [--commit]
     * : Apply changes. Without this flag the command only reports what would happen.
     *
     * [--trajectory=<id>]
     * : Limit the run to a single trajectory's parent rows.
     *
     * ## EXAMPLES
     *
     *   wp stride trajectory backfill-cascade
     *   wp stride trajectory backfill-cascade --commit
     *   wp stride trajectory backfill-cascade --trajectory=42 --commit
     *
     * @param array<int, string> $args
     * @param array<string, string> $assocArgs
     */
    public function cliBackfillCascade(array $args, array $assocArgs): void
    {
        global $wpdb;
        $commit = isset($assocArgs['commit']);
        $trajectoryFilter = isset($assocArgs['trajectory']) ? (int) $assocArgs['trajectory'] : 0;

        $table = $wpdb->prefix . 'vad_registrations';
        $eligibleStatuses = [
            \Stride\Domain\RegistrationStatus::Confirmed->value,
            \Stride\Domain\RegistrationStatus::Pending->value,
        ];
        $statusPlaceholders = implode(',', array_fill(0, count($eligibleStatuses), '%s'));

        $sql = "SELECT id, trajectory_id, user_id, status
                FROM {$table}
                WHERE trajectory_id IS NOT NULL
                  AND trajectory_id > 0
                  AND edition_id IS NULL
                  AND parent_registration_id IS NULL
                  AND status IN ({$statusPlaceholders})";
        $params = $eligibleStatuses;
        if ($trajectoryFilter > 0) {
            $sql .= ' AND trajectory_id = %d';
            $params[] = $trajectoryFilter;
        }
        $sql .= ' ORDER BY id ASC';

        $parents = $wpdb->get_results($wpdb->prepare($sql, ...$params));

        if (empty($parents)) {
            \WP_CLI::success('No eligible trajectory parents found.');
            return;
        }

        \WP_CLI::log(sprintf(
            '%s on %d trajectory parent registration(s)%s.',
            $commit ? 'Backfilling' : 'Dry-run',
            count($parents),
            $trajectoryFilter > 0 ? " (trajectory={$trajectoryFilter})" : ''
        ));

        if (!$commit) {
            \WP_CLI::log('Re-run with --commit to apply changes.');
            // Still emit per-parent diagnostics in dry-run mode.
        }

        $cascade = ntdst_get(TrajectoryCascadeService::class);
        $totalChildrenCreated = 0;
        $totalErrors = 0;

        foreach ($parents as $parent) {
            $parentId = (int) $parent->id;
            $existingChildren = $this->registrations->findByParent($parentId);
            $existingCount = count($existingChildren);

            if (!$commit) {
                \WP_CLI::log(sprintf(
                    '  parent=%d traject=%d user=%d status=%s existing_children=%d',
                    $parentId,
                    (int) $parent->trajectory_id,
                    (int) $parent->user_id,
                    (string) $parent->status,
                    $existingCount
                ));
                continue;
            }

            $report = $cascade->backfillParent($parentId);
            $added = $report['children_after'] - $report['children_before'];
            $totalChildrenCreated += max(0, $added);

            if ($report['error'] !== null) {
                $totalErrors++;
                \WP_CLI::warning(sprintf(
                    'parent=%d: %s (children: %d → %d)',
                    $parentId,
                    $report['error'],
                    $report['children_before'],
                    $report['children_after']
                ));
            } else {
                \WP_CLI::log(sprintf(
                    '  parent=%d: children %d → %d (+%d)',
                    $parentId,
                    $report['children_before'],
                    $report['children_after'],
                    $added
                ));
            }
        }

        if ($commit) {
            \WP_CLI::success(sprintf(
                'Backfill complete. Parents processed: %d. Children created: %d. Errors: %d.',
                count($parents),
                $totalChildrenCreated,
                $totalErrors
            ));
        }
    }

    // === CRUD ===

    /**
     * Create a new trajectory.
     */
    public function createTrajectory(array $data): int|WP_Error
    {
        $result = $this->repository->create($data);

        if (is_wp_error($result)) {
            return $result;
        }

        do_action('stride/trajectory/created', [
            'trajectory_id' => $result->ID,
        ]);

        return $result->ID;
    }

    /**
     * Get trajectory by ID.
     *
     * @return array<string, mixed>|null
     */
    public function getTrajectory(int $trajectoryId): ?array
    {
        $post = $this->repository->find($trajectoryId);

        if (is_wp_error($post)) {
            return null;
        }

        return $this->formatTrajectory($post);
    }

    /**
     * Update trajectory.
     */
    public function updateTrajectory(int $trajectoryId, array $data): true|WP_Error
    {
        $result = $this->repository->update($trajectoryId, $data);

        if (is_wp_error($result)) {
            return $result;
        }

        do_action('stride/trajectory/updated', [
            'trajectory_id' => $trajectoryId,
        ]);

        return true;
    }

    // === Queries ===

    /**
     * Get all active trajectories.
     *
     * @return array<array<string, mixed>>
     */
    public function getActiveTrajectories(): array
    {
        $trajectories = $this->repository->findActive();

        return array_map([$this, 'formatTrajectoryArray'], $trajectories);
    }

    /**
     * Get trajectories open for enrollment.
     *
     * @return array<array<string, mixed>>
     */
    public function getOpenTrajectories(): array
    {
        $trajectories = $this->repository->findOpen();

        return array_map([$this, 'formatTrajectoryArray'], $trajectories);
    }

    /**
     * Get total course count.
     */
    public function getCourseCount(int $trajectoryId): int
    {
        return count($this->repository->getCourses($trajectoryId));
    }

    /**
     * Get required course count.
     */
    public function getRequiredCourseCount(int $trajectoryId): int
    {
        return count($this->repository->getRequiredCourses($trajectoryId));
    }

    // === User Enrollment ===

    /**
     * Check if trajectory requires admin approval for enrollment.
     */
    public function requiresApproval(int $trajectoryId): bool
    {
        return (bool) $this->repository->getField($trajectoryId, 'requires_approval', false);
    }

    /**
     * Get the enrollment form key for this trajectory.
     *
     * Mirrors EditionService::getEnrollmentForm so EnrollmentRouter can pass
     * `form_type` to the shared enrollment template — the template hides the
     * billing step when form_type === 'minimal'.
     */
    public function getEnrollmentForm(int $trajectoryId): string
    {
        return (string) $this->repository->getField($trajectoryId, 'enrollment_form', 'default');
    }

    // === Deadline Checks ===

    /**
     * Check if enrollment is open.
     */
    public function isEnrollmentOpen(int $trajectoryId): bool
    {
        $trajectory = $this->getTrajectory($trajectoryId);

        if (!$trajectory) {
            return false;
        }

        // Check status
        if (!$trajectory['status_enum']->allowsEnrollment()) {
            return false;
        }

        // Check enrollment deadline if set
        $deadline = $trajectory['enrollment_deadline'];
        if (!empty($deadline) && strtotime($deadline) < time()) {
            return false;
        }

        return true;
    }

    /**
     * Check if choice window is open.
     */
    public function isChoiceWindowOpen(int $trajectoryId): bool
    {
        $trajectory = $this->getTrajectory($trajectoryId);

        if (!$trajectory) {
            return false;
        }

        $now = time();

        // Check choice available date
        $availableDate = $trajectory['choice_available_date'];
        if (!empty($availableDate) && strtotime($availableDate) > $now) {
            return false;
        }

        // Check choice deadline
        $deadline = $trajectory['choice_deadline'];
        if (!empty($deadline) && strtotime($deadline) < $now) {
            return false;
        }

        return true;
    }

    /**
     * Check if choices are locked.
     */
    public function areChoicesLocked(int $trajectoryId): bool
    {
        $deadline = $this->repository->getField($trajectoryId, 'choice_deadline');

        if (empty($deadline)) {
            return false;
        }

        return strtotime($deadline) < time();
    }

    // === Helpers ===

    /**
     * Format WP_Post to trajectory array.
     */
    private function formatTrajectory(WP_Post $post): array
    {
        $modeValue = $this->repository->getField($post->ID, 'mode', 'cohort');
        $mode = TrajectoryMode::tryFrom($modeValue) ?? TrajectoryMode::Cohort;

        $statusValue = $this->repository->getField($post->ID, 'status', 'draft');
        $status = OfferingStatus::tryFrom($statusValue) ?? OfferingStatus::Draft;

        return [
            'id' => $post->ID,
            'title' => $post->post_title,
            'description' => $post->post_content,
            'mode' => $mode->value,
            'mode_enum' => $mode,
            'status' => $status->value,
            'status_enum' => $status,
            'enrollment_deadline' => $this->repository->getField($post->ID, 'enrollment_deadline', ''),
            'choice_available_date' => $this->repository->getField($post->ID, 'choice_available_date', ''),
            'choice_deadline' => $this->repository->getField($post->ID, 'choice_deadline', ''),
            'capacity' => (int) $this->repository->getField($post->ID, 'capacity', 0),
            'price' => (float) $this->repository->getField($post->ID, 'price', 0),
            'price_non_member' => (float) $this->repository->getField($post->ID, 'price_non_member', 0),
            'requires_approval' => (bool) $this->repository->getField($post->ID, 'requires_approval', false),
            'courses' => $this->repository->getCourses($post->ID),
        ];
    }

    /**
     * Format array result to trajectory array.
     */
    private function formatTrajectoryArray(array $data): array
    {
        $modeValue = $data['meta']['mode'] ?? $data['mode'] ?? 'cohort';
        $mode = TrajectoryMode::tryFrom($modeValue) ?? TrajectoryMode::Cohort;

        $statusValue = $data['meta']['status'] ?? $data['status'] ?? 'draft';
        $status = OfferingStatus::tryFrom($statusValue) ?? OfferingStatus::Draft;

        $courses = $data['meta']['courses'] ?? $data['courses'] ?? [];
        if (is_string($courses)) {
            $courses = json_decode($courses, true) ?: [];
        }

        return [
            'id' => (int) ($data['id'] ?? $data['ID'] ?? 0),
            'title' => $data['title'] ?? $data['post_title'] ?? '',
            'description' => $data['content'] ?? $data['post_content'] ?? '',
            'mode' => $mode->value,
            'mode_enum' => $mode,
            'status' => $status->value,
            'status_enum' => $status,
            'enrollment_deadline' => $data['meta']['enrollment_deadline'] ?? $data['enrollment_deadline'] ?? '',
            'choice_available_date' => $data['meta']['choice_available_date'] ?? $data['choice_available_date'] ?? '',
            'choice_deadline' => $data['meta']['choice_deadline'] ?? $data['choice_deadline'] ?? '',
            'capacity' => (int) ($data['meta']['capacity'] ?? $data['capacity'] ?? 0),
            'price' => (float) ($data['meta']['price'] ?? $data['price'] ?? 0),
            'price_non_member' => (float) ($data['meta']['price_non_member'] ?? $data['price_non_member'] ?? 0),
            'courses' => $courses,
        ];
    }
}
