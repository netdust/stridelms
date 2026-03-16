<?php

declare(strict_types=1);

namespace Stride\Modules\Edition;

use Stride\Domain\SessionType;
use WP_Error;
use WP_Post;

/**
 * Session business logic.
 *
 * Plain class — owned by EditionService.
 */
final class SessionService
{
    public function __construct(
        private readonly SessionRepository $repository,
    ) {
    }

    // === CRUD ===

    /**
     * Create a new session.
     */
    public function createSession(array $data): int|WP_Error
    {
        $result = $this->repository->create($data);

        if (is_wp_error($result)) {
            return $result;
        }

        do_action('stride/session/created', [
            'session_id' => $result->ID,
            'edition_id' => $data['edition_id'] ?? 0,
        ]);

        return $result->ID;
    }

    /**
     * Get session by ID.
     *
     * @return array<string, mixed>|null
     */
    public function getSession(int $sessionId): ?array
    {
        $post = $this->repository->find($sessionId);

        if (is_wp_error($post)) {
            return null;
        }

        return $this->formatSession($post);
    }

    /**
     * Update session.
     */
    public function updateSession(int $sessionId, array $data): true|WP_Error
    {
        // Check if description changed (for audit notification)
        $descriptionChanged = false;
        if (array_key_exists('description', $data)) {
            $oldDescription = $this->repository->getMeta($sessionId, 'description') ?? '';
            $descriptionChanged = $data['description'] !== $oldDescription;
        }

        $result = $this->repository->update($sessionId, $data);

        if (is_wp_error($result)) {
            return $result;
        }

        // Fire audit hook if description changed
        if ($descriptionChanged) {
            $editionId = $this->repository->getMeta($sessionId, 'edition_id');
            do_action('stride/session/note_updated', [
                'session_id' => $sessionId,
                'edition_id' => (int) $editionId,
            ]);
        }

        return true;
    }

    // === Queries ===

    /**
     * Get all sessions for an edition.
     *
     * @return array<array<string, mixed>>
     */
    public function getSessionsForEdition(int $editionId): array
    {
        $sessions = $this->repository->findByEdition($editionId);

        return array_map([$this, 'formatSessionArray'], $sessions);
    }

    /**
     * Get sessions by slot.
     *
     * @return array<array<string, mixed>>
     */
    public function getSessionsBySlot(int $editionId, string $slot): array
    {
        $sessions = $this->repository->findBySlot($editionId, $slot);

        return array_map([$this, 'formatSessionArray'], $sessions);
    }

    /**
     * Get session count for edition.
     */
    public function getSessionCount(int $editionId): int
    {
        return $this->repository->countByEdition($editionId);
    }

    /**
     * Get unique day count for edition.
     */
    public function getDayCount(int $editionId): int
    {
        return count($this->repository->getUniqueDates($editionId));
    }

    // === Duration Calculations ===

    /**
     * Get session duration in hours.
     */
    public function getSessionDuration(int $sessionId): float
    {
        $session = $this->getSession($sessionId);

        if (!$session || empty($session['start_time']) || empty($session['end_time'])) {
            return 0.0;
        }

        $start = strtotime($session['start_time']);
        $end = strtotime($session['end_time']);

        return ($end - $start) / 3600;
    }

    /**
     * Get total hours for all sessions in edition.
     */
    public function getTotalHours(int $editionId): float
    {
        $sessions = $this->getSessionsForEdition($editionId);
        $total = 0.0;

        foreach ($sessions as $session) {
            if (!empty($session['start_time']) && !empty($session['end_time'])) {
                $start = strtotime($session['start_time']);
                $end = strtotime($session['end_time']);
                $total += ($end - $start) / 3600;
            }
        }

        return $total;
    }

    /**
     * Get total duration for multiple sessions in hours.
     *
     * @param array<int> $sessionIds
     */
    public function getTotalDurationForSessions(array $sessionIds): float
    {
        if (empty($sessionIds)) {
            return 0.0;
        }

        global $wpdb;

        $placeholders = implode(',', array_fill(0, count($sessionIds), '%d'));

        // Fetch start_time and end_time for all sessions in single query
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT pm_start.post_id, pm_start.meta_value as start_time, pm_end.meta_value as end_time
             FROM {$wpdb->postmeta} pm_start
             LEFT JOIN {$wpdb->postmeta} pm_end ON pm_start.post_id = pm_end.post_id AND pm_end.meta_key = 'end_time'
             WHERE pm_start.post_id IN ({$placeholders})
             AND pm_start.meta_key = 'start_time'",
            ...$sessionIds
        ));

        $totalHours = 0.0;
        foreach ($results as $row) {
            if (!empty($row->start_time) && !empty($row->end_time)) {
                $start = strtotime($row->start_time);
                $end = strtotime($row->end_time);
                if ($end > $start) {
                    $totalHours += ($end - $start) / 3600;
                }
            }
        }

        return $totalHours;
    }

    // === Helpers ===

    /**
     * Format WP_Post to session array.
     */
    private function formatSession(WP_Post $post): array
    {
        $typeValue = $this->repository->getField($post->ID, 'type', 'in_person');
        $type = SessionType::tryFrom($typeValue) ?? SessionType::InPerson;

        return [
            'id' => $post->ID,
            'edition_id' => (int) $this->repository->getField($post->ID, 'edition_id'),
            'slot' => $this->repository->getField($post->ID, 'slot', ''),
            'date' => $this->repository->getField($post->ID, 'date', ''),
            'start_time' => $this->repository->getField($post->ID, 'start_time', ''),
            'end_time' => $this->repository->getField($post->ID, 'end_time', ''),
            'location' => $this->repository->getField($post->ID, 'location', ''),
            'type' => $type->value,
            'type_enum' => $type,
            'capacity' => (int) $this->repository->getField($post->ID, 'capacity', 0),
            'optional' => (bool) $this->repository->getField($post->ID, 'optional', false),
            'price_modifier' => (int) $this->repository->getField($post->ID, 'price_modifier', 0),
        ];
    }

    /**
     * Format array result to session array.
     *
     * NTDST_Data_Manager::getPostsFast returns data with:
     * - 'id' (lowercase) at top level
     * - meta fields nested under 'meta' key
     */
    private function formatSessionArray(array $data): array
    {
        // Meta fields are nested under 'meta' key from getPostsFast
        // Keys have _ntdst_ prefix
        $meta = $data['meta'] ?? [];

        $typeValue = $meta['_ntdst_type'] ?? $meta['type'] ?? 'in_person';
        $type = SessionType::tryFrom($typeValue) ?? SessionType::InPerson;

        // NTDST_Data_Manager::getPostsFast returns 'id' (lowercase)
        $id = (int) ($data['id'] ?? $data['ID'] ?? 0);

        // lesson_ids may be serialized array or raw array
        $lessonIds = $meta['_ntdst_lesson_ids'] ?? $meta['lesson_ids'] ?? [];
        if (is_string($lessonIds)) {
            $lessonIds = maybe_unserialize($lessonIds);
        }
        if (!is_array($lessonIds)) {
            $lessonIds = $lessonIds ? [$lessonIds] : [];
        }

        return [
            'id' => $id,
            'edition_id' => (int) ($meta['_ntdst_edition_id'] ?? $meta['edition_id'] ?? 0),
            'slot' => $meta['_ntdst_slot'] ?? $meta['slot'] ?? '',
            'date' => $meta['_ntdst_date'] ?? $meta['date'] ?? '',
            'start_time' => $meta['_ntdst_start_time'] ?? $meta['start_time'] ?? '',
            'end_time' => $meta['_ntdst_end_time'] ?? $meta['end_time'] ?? '',
            'location' => $meta['_ntdst_location'] ?? $meta['location'] ?? '',
            'type' => $type->value,
            'type_enum' => $type,
            'capacity' => (int) ($meta['_ntdst_capacity'] ?? $meta['capacity'] ?? 0),
            'optional' => (bool) ($meta['_ntdst_optional'] ?? $meta['optional'] ?? false),
            'title' => $meta['_ntdst_post_title'] ?? $data['title'] ?? '',
            'description' => $meta['_ntdst_description'] ?? $meta['description'] ?? '',
            'webinar_link' => $meta['_ntdst_webinar_link'] ?? $meta['webinar_link'] ?? '',
            'lesson_ids' => array_map('intval', $lessonIds),
            'price_modifier' => (int) ($meta['_ntdst_price_modifier'] ?? $meta['price_modifier'] ?? 0),
        ];
    }
}
