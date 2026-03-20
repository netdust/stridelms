<?php

declare(strict_types=1);

namespace Stride\Modules\Edition;

use Stride\Domain\OfferingStatus;
use Stride\Infrastructure\AbstractRepository;

/**
 * Repository for edition data access.
 */
final class EditionRepository extends AbstractRepository
{
    protected string $postType = EditionCPT::POST_TYPE;

    /**
     * Get editions for a specific course.
     *
     * @return array<array<string, mixed>>
     */
    public function findByCourse(int $courseId): array
    {
        return $this->model()
            ->where('course_id', $courseId)
            ->where('post_status', 'publish')
            ->orderBy('start_date', 'ASC')
            ->withMeta()
            ->get();
    }

    /**
     * Get upcoming editions (start date >= today).
     *
     * @return array<array<string, mixed>>
     */
    public function findUpcoming(int $limit = 10): array
    {
        $today = date('Y-m-d');

        return $this->model()
            ->where('start_date', ['>=', $today])
            ->where('post_status', 'publish')
            ->whereNot('status', OfferingStatus::Cancelled->value)
            ->orderBy('start_date', 'ASC')
            ->limit($limit)
            ->withMeta()
            ->get();
    }

    /**
     * Get editions with available spots.
     *
     * @return array<array<string, mixed>>
     */
    public function findWithAvailability(): array
    {
        return $this->model()
            ->where('status', OfferingStatus::Open->value)
            ->where('post_status', 'publish')
            ->orderBy('start_date', 'ASC')
            ->withMeta()
            ->get();
    }

    /**
     * Query editions with flexible filters.
     *
     * @param array{
     *     course_id?: int,
     *     status?: string,
     *     start_date_from?: string,
     *     start_date_to?: string,
     *     limit?: int,
     * } $filters
     * @return array<array<string, mixed>>
     */
    public function findByFilters(array $filters = [], int $limit = 100): array
    {
        $query = $this->model()
            ->where('post_status', 'publish')
            ->orderBy('start_date', 'ASC')
            ->withMeta();

        if (!empty($filters['course_id'])) {
            $query->where('course_id', (int) $filters['course_id']);
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['start_date_from'])) {
            $query->where('start_date', ['>=', $filters['start_date_from']]);
        }

        if (!empty($filters['start_date_to'])) {
            $query->where('start_date', ['<=', $filters['start_date_to']]);
        }

        return $query->limit($limit)->get();
    }

    /**
     * Get field value from edition.
     */
    public function getField(int $editionId, string $field, mixed $default = null): mixed
    {
        return $this->model()->getMeta($editionId, $field, $default);
    }

    /**
     * Update edition status.
     */
    public function updateStatus(int $editionId, OfferingStatus $status): void
    {
        $this->model()->updateMetaBatch($editionId, ['status' => $status->value]);
    }

    /**
     * Update edition meta fields.
     */
    public function updateMeta(int $editionId, array $data): bool
    {
        return $this->model()->updateMetaBatch($editionId, $data);
    }
}
