<?php

declare(strict_types=1);

namespace Stride\Modules\Edition;

use Stride\Domain\SessionType;
use Stride\Infrastructure\AbstractRepository;
use WP_Error;
use WP_Post;

/**
 * Session repository for CRUD operations.
 */
final class SessionRepository extends AbstractRepository
{
    protected string $postType = SessionCPT::POST_TYPE;

    /**
     * Get a single field value.
     */
    public function getField(int $id, string $field, mixed $default = null): mixed
    {
        $value = $this->model()->getMeta($id, $field);

        return $value !== null ? $value : $default;
    }

    /**
     * Find sessions for an edition.
     *
     * @return array<array<string, mixed>>
     */
    public function findByEdition(int $editionId): array
    {
        return $this->model()
            ->where('edition_id', $editionId)
            ->orderBy('date', 'ASC')
            ->orderBy('start_time', 'ASC')
            ->withMeta()
            ->get();
    }

    /**
     * Find sessions by slot within an edition.
     *
     * @return array<array<string, mixed>>
     */
    public function findBySlot(int $editionId, string $slot): array
    {
        return $this->model()
            ->where('edition_id', $editionId)
            ->where('slot', $slot)
            ->orderBy('date', 'ASC')
            ->withMeta()
            ->get();
    }

    /**
     * Count sessions for an edition.
     */
    public function countByEdition(int $editionId): int
    {
        return $this->model()
            ->where('edition_id', $editionId)
            ->count();
    }

    /**
     * Get unique dates for an edition.
     *
     * @return array<string>
     */
    public function getUniqueDates(int $editionId): array
    {
        $sessions = $this->findByEdition($editionId);
        $dates = array_unique(array_column($sessions, 'date'));
        sort($dates);

        return array_values($dates);
    }

    /**
     * Validate session data before create/update.
     */
    public function validate(array $data): true|WP_Error
    {
        if (empty($data['edition_id'])) {
            return new WP_Error('missing_edition', 'Edition ID is required');
        }

        if (empty($data['date'])) {
            return new WP_Error('missing_date', 'Date is required');
        }

        // Compare time-only strings - strtotime() converts to timestamps for today
        // e.g., '09:00' < '17:00' when both are converted to same-day timestamps
        if (!empty($data['start_time']) && !empty($data['end_time'])) {
            if (strtotime($data['end_time']) <= strtotime($data['start_time'])) {
                return new WP_Error('invalid_time_range', 'End time must be after start time');
            }
        }

        // Validate type if provided
        if (!empty($data['type'])) {
            $validType = SessionType::tryFrom($data['type']);
            if ($validType === null) {
                return new WP_Error('invalid_type', 'Invalid session type');
            }
        }

        return true;
    }

    /**
     * Create session with validation.
     */
    public function create(array $data): WP_Post|WP_Error
    {
        $validation = $this->validate($data);
        if (is_wp_error($validation)) {
            return $validation;
        }

        // Set title from date + time
        $title = $data['date'];
        if (!empty($data['start_time'])) {
            $title .= ' ' . $data['start_time'];
        }
        $data['post_title'] = $title;

        return parent::create($data);
    }
}
