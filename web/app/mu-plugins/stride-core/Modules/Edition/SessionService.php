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
    ) {}

    // === CRUD ===

    /**
     * Create a new session.
     */
    public function createSession(array $data): WP_Post|WP_Error
    {
        $result = $this->repository->create($data);

        if (is_wp_error($result)) {
            return $result;
        }

        do_action('stride/session/created', [
            'session_id' => $result->ID,
            'edition_id' => $data['edition_id'] ?? 0,
        ]);

        return $result;
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
    public function updateSession(int $sessionId, array $data): WP_Post|WP_Error
    {
        // Check if description changed (for audit notification)
        $descriptionChanged = false;
        if (array_key_exists('description', $data)) {
            $oldDescription = $this->repository->getField($sessionId, 'description') ?? '';
            $descriptionChanged = $data['description'] !== $oldDescription;
        }

        $result = $this->repository->update($sessionId, $data);

        if (is_wp_error($result)) {
            return $result;
        }

        // Fire audit hook if description changed
        if ($descriptionChanged) {
            $editionId = $this->repository->getField($sessionId, 'edition_id');
            do_action('stride/session/note_updated', [
                'session_id' => $sessionId,
                'edition_id' => (int) $editionId,
            ]);
        }

        return $result;
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
     *
     * Pass-through — kept temporarily for theme + assistant callers that
     * inject SessionService but not SessionRepository. Remove once those
     * callers (editions-list.php, ReadAbilityRegistrar) are refactored to
     * use the repository directly. See memory/project_framework_alignment.
     */
    public function getSessionCount(int $editionId): int
    {
        return $this->repository->countByEdition($editionId);
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
     * Pass-through — kept temporarily so integration tests
     * (SessionServiceDurationTest) keep covering the batch-query path.
     * Once the open-drift sweep refactors the remaining callers to use
     * the repository, remove this wrapper and migrate the test to
     * SessionRepository::sumDurationHours.
     *
     * @param array<int> $sessionIds
     */
    public function getTotalDurationForSessions(array $sessionIds): float
    {
        return $this->repository->sumDurationHours($sessionIds);
    }

    // === Helpers ===

    /**
     * Format WP_Post to session array. Must produce the same shape as
     * formatSessionArray() so getSession() and getSessionsForEdition()
     * are interchangeable for consumers.
     */
    private function formatSession(WP_Post $post): array
    {
        $typeValue = $this->repository->getField($post->ID, 'type', 'in_person');
        $type = SessionType::tryFrom($typeValue) ?? SessionType::InPerson;

        $lessonIds = $this->repository->getField($post->ID, 'lesson_ids', []);
        if (is_string($lessonIds)) {
            $lessonIds = maybe_unserialize($lessonIds);
        }
        if (!is_array($lessonIds)) {
            $lessonIds = $lessonIds ? [$lessonIds] : [];
        }

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
            'title' => $post->post_title,
            'description' => $this->repository->getField($post->ID, 'description', ''),
            'webinar_link' => $this->repository->getField($post->ID, 'webinar_link', ''),
            'lesson_ids' => array_map('intval', $lessonIds),
            'price_modifier' => (int) $this->repository->getField($post->ID, 'price_modifier', 0),
        ];
    }

    /**
     * Format a getPostsFast() row into the session array shape.
     *
     * Shape MUST match formatSession() so consumers can switch between
     * getSession() and getSessionsForEdition() freely.
     *
     * getPostsFast returns:
     * - 'id' (lowercase) at top level
     * - 'title' at top level (mapped from post_title)
     * - meta fields nested under 'meta' key, prefixed by the model's meta_prefix
     *
     * The prefix awareness here is the trade-off for batch loading meta in a
     * single query — getMeta-per-field would multiply queries. We pull the
     * prefix from the repository so a future prefix change doesn't break this.
     */
    private function formatSessionArray(array $data): array
    {
        $prefix = $this->repository->getMetaPrefix();
        $meta   = $data['meta'] ?? [];

        $typeValue = $meta[$prefix . 'type'] ?? 'in_person';
        $type      = SessionType::tryFrom($typeValue) ?? SessionType::InPerson;

        $id = (int) ($data['id'] ?? $data['ID'] ?? 0);

        $lessonIds = $meta[$prefix . 'lesson_ids'] ?? [];
        if (is_string($lessonIds)) {
            $lessonIds = maybe_unserialize($lessonIds);
        }
        if (!is_array($lessonIds)) {
            $lessonIds = $lessonIds ? [$lessonIds] : [];
        }

        return [
            'id' => $id,
            'edition_id' => (int) ($meta[$prefix . 'edition_id'] ?? 0),
            'slot' => $meta[$prefix . 'slot'] ?? '',
            'date' => $meta[$prefix . 'date'] ?? '',
            'start_time' => $meta[$prefix . 'start_time'] ?? '',
            'end_time' => $meta[$prefix . 'end_time'] ?? '',
            'location' => $meta[$prefix . 'location'] ?? '',
            'type' => $type->value,
            'type_enum' => $type,
            'capacity' => (int) ($meta[$prefix . 'capacity'] ?? 0),
            'optional' => (bool) ($meta[$prefix . 'optional'] ?? false),
            'title' => $data['title'] ?? '',
            'description' => $meta[$prefix . 'description'] ?? '',
            'webinar_link' => $meta[$prefix . 'webinar_link'] ?? '',
            'lesson_ids' => array_map('intval', $lessonIds),
            'price_modifier' => (int) ($meta[$prefix . 'price_modifier'] ?? 0),
        ];
    }
}
