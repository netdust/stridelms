<?php

declare(strict_types=1);

namespace Stride\Modules\Edition;

use Stride\Contracts\EditionQueryInterface;
use Stride\Domain\OfferingStatus;
use Stride\Domain\Money;
use Stride\Infrastructure\AbstractService;
use WP_Post;
use WP_Error;

/**
 * Edition business logic.
 *
 * Implements EditionQueryInterface for cross-module queries.
 */
class EditionService extends AbstractService implements EditionQueryInterface
{
    public function __construct(
        private readonly EditionRepository $repository,
    ) {
        parent::__construct();
    }

    public static function metadata(): array
    {
        return [
            'name' => 'Edition Service',
            'description' => 'Manages scheduled course offerings',
            'priority' => 10,
        ];
    }

    protected function getConfigSlug(): string
    {
        return 'edition';
    }

    protected function init(): void
    {
        EditionCPT::register();
        SessionCPT::register();

        // Register sub-components as singletons
        $sessionService = new SessionService(ntdst_get(SessionRepository::class));
        ntdst_set(SessionService::class, fn() => $sessionService);

        $completion = new EditionCompletion();
        ntdst_set(EditionCompletion::class, fn() => $completion);
        add_action('stride/attendance/marked', [$completion, 'onAttendanceMarked']);

        // Admin UI + settings (registers own hooks in constructor)
        new \Stride\Admin\StrideSettingsService();
        new Admin\EditionAdminController(
            $this,
            $this->repository,
            $sessionService,
            ntdst_get(SessionRepository::class),
            ntdst_get(\Stride\Modules\Attendance\AttendanceRepository::class),
        );

        // Register hooks for capacity updates
        add_action('stride/registration/created', [$this, 'onRegistrationCreated']);
        add_action('stride/registration/cancelled', [$this, 'onRegistrationCancelled']);

        // Cascade delete: clean up sessions and registrations when an edition is deleted
        add_action('before_delete_post', [$this, 'onEditionDeleted']);
        add_action('wp_trash_post', [$this, 'onEditionTrashed']);
    }

    // === EditionQueryInterface Implementation ===

    public function hasAvailableSpots(int $editionId): bool
    {
        $capacity = $this->getCapacity($editionId);

        // Capacity 0 means unlimited (e.g., e-learning courses)
        if ($capacity === 0) {
            return true;
        }

        $registered = $this->getRegisteredCount($editionId);

        return $registered < $capacity;
    }

    public function getRegisteredCount(int $editionId): int
    {
        global $wpdb;

        $table = $wpdb->prefix . 'vad_registrations';

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE edition_id = %d AND status IN ('confirmed', 'completed', 'pending')",
            $editionId
        ));
    }

    public function getCapacity(int $editionId): int
    {
        return (int) $this->repository->getField($editionId, 'capacity', 0);
    }

    public function getStatus(int $editionId): OfferingStatus
    {
        $status = $this->repository->getField($editionId, 'status', 'open');

        return OfferingStatus::tryFrom($status) ?? OfferingStatus::Open;
    }

    public function getCourseId(int $editionId): ?int
    {
        $courseId = $this->repository->getField($editionId, 'course_id');

        return $courseId ? (int) $courseId : null;
    }

    /**
     * Check if this edition is for an online course.
     * Derives format from the linked LearnDash course's stride_format taxonomy.
     */
    public function isOnline(int $editionId): bool
    {
        $courseId = $this->getCourseId($editionId);
        if (!$courseId) {
            return false;
        }

        $formats = get_the_terms($courseId, 'stride_format');
        if (!$formats || is_wp_error($formats)) {
            return false;
        }

        foreach ($formats as $fmt) {
            if (in_array($fmt->slug, ['online', 'webinar', 'e-learning'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the enrollment form key for this edition.
     * Returns empty string if no form is configured.
     */
    public function getEnrollmentForm(int $editionId): string
    {
        return (string) $this->repository->getField($editionId, 'enrollment_form', 'default');
    }

    /**
     * Check if this edition has an enrollment form configured.
     */
    public function hasEnrollmentForm(int $editionId): bool
    {
        return $this->getEnrollmentForm($editionId) !== '';
    }

    public function exists(int $editionId): bool
    {
        $result = $this->repository->find($editionId);

        return !is_wp_error($result);
    }

    public function requiresApproval(int $editionId): bool
    {
        return (bool) $this->repository->getField($editionId, 'requires_approval', false);
    }

    // === Public API ===

    /**
     * Get edition by ID.
     */
    public function getEdition(int $editionId): WP_Post|WP_Error
    {
        return $this->repository->find($editionId);
    }

    /**
     * Get editions for a course.
     *
     * @return array<array<string, mixed>>
     */
    public function getEditionsForCourse(int $courseId): array
    {
        return $this->repository->findByCourse($courseId);
    }

    /**
     * Get upcoming editions.
     *
     * @return array<array<string, mixed>>
     */
    public function getUpcomingEditions(int $limit = 10): array
    {
        return $this->repository->findUpcoming($limit);
    }

    /**
     * Check if a user is a member.
     *
     * Uses `is_vad_member` user meta as default.
     * Override via `stride/membership/is_member` filter.
     */
    public function isMember(int $userId): bool
    {
        $isMember = (bool) get_user_meta($userId, 'is_vad_member', true);

        return (bool) apply_filters('stride/membership/is_member', $isMember, $userId);
    }

    /**
     * Get price for edition.
     *
     * When $userId is provided, checks membership for member pricing.
     * When null (anonymous/display), returns non-member price.
     *
     * Override via `stride/membership/price` filter.
     */
    public function getPrice(int $editionId, ?int $userId = null): Money
    {
        $isMember = $userId !== null ? $this->isMember($userId) : false;
        $field = $isMember ? 'price' : 'price_non_member';
        $amount = (float) $this->repository->getField($editionId, $field, 0);
        $price = Money::eur($amount);

        return apply_filters('stride/membership/price', $price, $editionId, $userId, $isMember);
    }

    /**
     * Check if enrollment is allowed.
     */
    public function canEnroll(int $editionId): bool
    {
        $status = $this->getStatus($editionId);

        if (!$status->allowsEnrollment()) {
            return false;
        }

        return $this->hasAvailableSpots($editionId);
    }

    /**
     * Alias for canEnroll for handler compatibility.
     */
    public function isEnrollmentOpen(int $editionId): bool
    {
        return $this->canEnroll($editionId);
    }

    // === Event Handlers ===

    /**
     * Handle registration created event.
     *
     * @param array<string, mixed> $data
     */
    public function onRegistrationCreated(array $data): void
    {
        $editionId = $data['edition_id'] ?? 0;

        if ($editionId && !$this->hasAvailableSpots($editionId)) {
            $this->repository->updateStatus($editionId, OfferingStatus::Full);
        }
    }

    /**
     * Handle registration cancelled event.
     *
     * @param array<string, mixed> $data
     */
    public function onRegistrationCancelled(array $data): void
    {
        $editionId = (int) ($data['edition_id'] ?? 0);
        $currentStatus = $this->getStatus($editionId);

        if ($editionId && $currentStatus === OfferingStatus::Full) {
            if ($this->hasAvailableSpots($editionId)) {
                $this->repository->updateStatus($editionId, OfferingStatus::Open);
            }
        }
    }

    /**
     * Cascade delete: remove sessions and registrations when edition is permanently deleted.
     */
    public function onEditionDeleted(int $postId): void
    {
        if (get_post_type($postId) !== EditionCPT::POST_TYPE) {
            return;
        }

        $this->deleteChildSessions($postId);
        $this->deleteEditionRegistrations($postId);
    }

    /**
     * Cascade trash: also trash child sessions when edition is trashed.
     */
    public function onEditionTrashed(int $postId): void
    {
        if (get_post_type($postId) !== EditionCPT::POST_TYPE) {
            return;
        }

        $this->deleteChildSessions($postId);
        $this->deleteEditionRegistrations($postId);
    }

    private function deleteChildSessions(int $editionId): void
    {
        $sessions = get_posts([
            'post_type' => SessionCPT::POST_TYPE,
            'post_parent' => $editionId,
            'post_status' => 'any',
            'posts_per_page' => -1,
            'fields' => 'ids',
        ]);

        foreach ($sessions as $sessionId) {
            wp_delete_post($sessionId, true);
        }
    }

    private function deleteEditionRegistrations(int $editionId): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'vad_registrations';
        $wpdb->delete($table, ['edition_id' => $editionId], ['%d']);
    }
}
