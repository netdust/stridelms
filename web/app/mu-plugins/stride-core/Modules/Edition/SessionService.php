<?php

declare(strict_types=1);

namespace Stride\Modules\Edition;

use Stride\Domain\SessionType;
use Stride\Infrastructure\AbstractService;
use WP_Error;
use WP_Post;

/**
 * Session business logic.
 */
final class SessionService extends AbstractService
{
    public function __construct(
        private readonly SessionRepository $repository,
    ) {
        parent::__construct();
    }

    public static function metadata(): array
    {
        return [
            'name' => 'Session Service',
            'description' => 'Manages meeting days within editions',
            'priority' => 15,
        ];
    }

    protected function getConfigSlug(): string
    {
        return 'session';
    }

    protected function init(): void
    {
        // Future: hooks for session events
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
        $result = $this->repository->update($sessionId, $data);

        if (is_wp_error($result)) {
            return $result;
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
        ];
    }

    /**
     * Format array result to session array.
     */
    private function formatSessionArray(array $data): array
    {
        $typeValue = $data['type'] ?? 'in_person';
        $type = SessionType::tryFrom($typeValue) ?? SessionType::InPerson;

        return [
            'id' => (int) $data['ID'],
            'edition_id' => (int) ($data['edition_id'] ?? 0),
            'slot' => $data['slot'] ?? '',
            'date' => $data['date'] ?? '',
            'start_time' => $data['start_time'] ?? '',
            'end_time' => $data['end_time'] ?? '',
            'location' => $data['location'] ?? '',
            'type' => $type->value,
            'type_enum' => $type,
            'capacity' => (int) ($data['capacity'] ?? 0),
            'optional' => (bool) ($data['optional'] ?? false),
        ];
    }
}
