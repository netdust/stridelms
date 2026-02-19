<?php

declare(strict_types=1);

namespace Stride\Modules\Edition;

use Stride\Contracts\EditionQueryInterface;
use Stride\Domain\EditionStatus;
use Stride\Domain\Money;
use Stride\Infrastructure\AbstractService;
use WP_Post;
use WP_Error;

/**
 * Edition business logic.
 *
 * Implements EditionQueryInterface for cross-module queries.
 */
final class EditionService extends AbstractService implements EditionQueryInterface
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
        // Register hooks for capacity updates
        add_action('stride/registration/created', [$this, 'onRegistrationCreated']);
        add_action('stride/registration/cancelled', [$this, 'onRegistrationCancelled']);
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
            "SELECT COUNT(*) FROM {$table} WHERE edition_id = %d AND status = 'confirmed'",
            $editionId
        ));
    }

    public function getCapacity(int $editionId): int
    {
        return (int) $this->repository->getField($editionId, 'capacity', 0);
    }

    public function getStatus(int $editionId): EditionStatus
    {
        $status = $this->repository->getField($editionId, 'status', 'open');

        return EditionStatus::tryFrom($status) ?? EditionStatus::Open;
    }

    public function getCourseId(int $editionId): ?int
    {
        $courseId = $this->repository->getField($editionId, 'course_id');

        return $courseId ? (int) $courseId : null;
    }

    public function exists(int $editionId): bool
    {
        $result = $this->repository->find($editionId);

        return !is_wp_error($result);
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
     * Get price for edition.
     */
    public function getPrice(int $editionId, bool $isMember = true): Money
    {
        $field = $isMember ? 'price' : 'price_non_member';
        $amount = (float) $this->repository->getField($editionId, $field, 0);

        return Money::eur($amount);
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
            $this->repository->updateStatus($editionId, EditionStatus::Full);
        }
    }

    /**
     * Handle registration cancelled event.
     *
     * @param array<string, mixed> $data
     */
    public function onRegistrationCancelled(array $data): void
    {
        $editionId = $data['edition_id'] ?? 0;
        $currentStatus = $this->getStatus($editionId);

        if ($editionId && $currentStatus === EditionStatus::Full) {
            if ($this->hasAvailableSpots($editionId)) {
                $this->repository->updateStatus($editionId, EditionStatus::Open);
            }
        }
    }
}
