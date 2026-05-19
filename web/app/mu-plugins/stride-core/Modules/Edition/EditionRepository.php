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
     * Get editions for a specific course (all post-status=publish, any status).
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
     * Get IDs of editions for a course that are "active" — i.e. publicly
     * visible, listed in catalogs, reachable via slug routing. Excludes
     * terminal statuses (cancelled, completed, archived) and drafts.
     *
     * Status set lives on OfferingStatus::activeCases().
     *
     * @return list<int>
     */
    public function findActiveIdsByCourse(int $courseId): array
    {
        $rows = $this->model()
            ->where('course_id', $courseId)
            ->where('post_status', 'publish')
            ->whereIn('status', OfferingStatus::activeValues())
            ->get();

        return array_map(static fn(array $row): int => (int) ($row['id'] ?? $row['ID'] ?? 0), $rows);
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
