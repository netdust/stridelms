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
        private readonly \Stride\Modules\Membership\MembershipService $membership,
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
        $sessionRepo = ntdst_get(SessionRepository::class);
        $sessionService = new SessionService($sessionRepo);
        ntdst_set(SessionService::class, fn() => $sessionService);

        $completion = new EditionCompletion($this, $sessionService);
        ntdst_set(EditionCompletion::class, fn() => $completion);
        add_action('stride/attendance/marked', [$completion, 'onAttendanceMarked']);
        add_action('learndash_course_completed', [$completion, 'onLearnDashCourseCompleted']);

        // Admin UI + settings (registers own hooks in constructor)
        new \Stride\Admin\StrideSettingsService();
        new Admin\EditionAdminController(
            $this,
            $this->repository,
            $sessionService,
            $sessionRepo,
            ntdst_get(\Stride\Modules\Attendance\AttendanceRepository::class),
        );

        new Admin\RegistrationModalController(
            $this,
            $sessionService,
            ntdst_get(\Stride\Modules\Edition\SessionSelection::class),
            ntdst_get(\Stride\Modules\Enrollment\RegistrationRepository::class),
        );

        // Register hooks for capacity updates
        add_action('stride/registration/created', [$this, 'onRegistrationCreated']);
        add_action('stride/registration/cancelled', [$this, 'onRegistrationCancelled']);

        // Cascade delete: clean up sessions and registrations when an edition is deleted
        add_action('before_delete_post', [$this, 'onEditionDeleted']);
        add_action('wp_trash_post', [$this, 'onEditionTrashed']);

        // Route /vormingen/<slug>/ → pure-LD course fallback when slug isn't an edition
        (new EditionRouter())->register();
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
        $cacheKey = 'stride_edition_reg_count_' . $editionId;
        $cached = get_transient($cacheKey);
        if ($cached !== false) {
            return (int) $cached;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'vad_registrations';

        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE edition_id = %d AND status IN ('confirmed', 'completed', 'pending')",
            $editionId
        ));

        set_transient($cacheKey, $count, 60);

        return $count;
    }

    private function invalidateRegisteredCountCache(int $editionId): void
    {
        if ($editionId > 0) {
            delete_transient('stride_edition_reg_count_' . $editionId);
        }
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
     * Delegates to MembershipService. Kept as a thin pass-through so
     * existing callers (`$editionService->isMember($id)`) keep working.
     */
    public function isMember(int $userId): bool
    {
        return $this->membership->isMember($userId);
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
     *
     * Combines admin-managed status, capacity, AND a calendar-aware past-check.
     * The past-check guards against an admin who forgot to flip an Open edition
     * to Completed/Archived after its end_date passed — we won't let users
     * enroll in something that already happened.
     */
    public function canEnroll(int $editionId): bool
    {
        $status = $this->getStatus($editionId);

        if (!$status->allowsEnrollment()) {
            return false;
        }

        if ($this->isPast($editionId)) {
            return false;
        }

        return $this->hasAvailableSpots($editionId);
    }

    /**
     * True when the edition's end_date (or start_date as fallback) is in the past.
     *
     * Pure calendar check — independent of OfferingStatus. Use this anywhere
     * UI logic depends on "can a user still take action on this edition?".
     */
    public function isPast(int $editionId): bool
    {
        $endDate   = (string) $this->repository->getField($editionId, 'end_date', '');
        $startDate = (string) $this->repository->getField($editionId, 'start_date', '');
        $reference = $endDate ?: $startDate;
        if (!$reference) {
            return false;
        }
        return strtotime($reference) < strtotime(date('Y-m-d'));
    }

    /**
     * Alias for canEnroll for handler compatibility.
     */
    public function isEnrollmentOpen(int $editionId): bool
    {
        return $this->canEnroll($editionId);
    }

    /**
     * Display status for the public frontend.
     *
     * Stored `_ntdst_status` reflects admin intent — but doesn't auto-transition
     * when end_date passes. For display (badges, cards, single page header),
     * we lay an `isPast` check over the stored status: a past edition reads
     * as Completed regardless of what's stored.
     *
     * Use this anywhere a status is shown to a visitor. Use `getStatus()`
     * when admin intent matters (queries by stored status, transitions, etc.).
     */
    public function getEffectiveStatus(int $editionId): OfferingStatus
    {
        $stored = $this->getStatus($editionId);
        if ($stored->isTerminal()) {
            // Stored Cancelled/Completed/Archived always wins
            return $stored;
        }
        if ($this->isPast($editionId)) {
            return OfferingStatus::Completed;
        }
        return $stored;
    }

    // === Event Handlers ===

    /**
     * Handle registration created event.
     *
     * @param array<string, mixed> $data
     */
    public function onRegistrationCreated(array $data): void
    {
        $editionId = (int) ($data['edition_id'] ?? 0);
        $this->invalidateRegisteredCountCache($editionId);

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
        $this->invalidateRegisteredCountCache($editionId);

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

        if (empty($sessions)) {
            return;
        }

        // Bulk delete attendance records once, then drop the session posts.
        // Done per-post via wp_delete_post so meta + relationships clean up
        // through WordPress, but the attendance hit is now a single DELETE.
        $attendanceRepo = ntdst_get(\Stride\Modules\Attendance\AttendanceRepository::class);
        $attendanceRepo->deleteBySessions($sessions);

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
