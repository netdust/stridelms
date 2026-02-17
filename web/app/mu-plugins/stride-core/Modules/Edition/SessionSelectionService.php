<?php

declare(strict_types=1);

namespace Stride\Modules\Edition;

use Stride\Infrastructure\AbstractService;
use WP_Error;

/**
 * Session selection with deadline enforcement.
 *
 * Handles user picking sessions from available slots.
 */
final class SessionSelectionService extends AbstractService
{
    public function __construct(
        private readonly SessionService $sessions,
        private readonly EditionRepository $editions,
    ) {
        parent::__construct();
    }

    public static function metadata(): array
    {
        return [
            'name' => 'Session Selection Service',
            'description' => 'Handles session selection with deadlines',
            'priority' => 16,
        ];
    }

    protected function getConfigSlug(): string
    {
        return 'session_selection';
    }

    protected function init(): void
    {
        // Future: lock expired selections
    }

    // === Selection Queries ===

    /**
     * Get user's session selections for a registration.
     *
     * @return array<array<string, mixed>>
     */
    public function getUserSelections(int $registrationId): array
    {
        global $wpdb;
        $table = SessionRegistrationTable::getTableName();

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE registration_id = %d AND status = 'registered'",
            $registrationId
        ), ARRAY_A);

        return $results ?: [];
    }

    /**
     * Get registration count for a session.
     */
    public function getSessionRegistrationCount(int $sessionId): int
    {
        global $wpdb;
        $table = SessionRegistrationTable::getTableName();

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE session_id = %d AND status = 'registered'",
            $sessionId
        ));
    }

    /**
     * Check if session has available capacity.
     */
    public function hasCapacity(int $sessionId): bool
    {
        $session = $this->sessions->getSession($sessionId);

        if (!$session) {
            return false;
        }

        // No capacity limit
        if ($session['capacity'] === 0) {
            return true;
        }

        $registered = $this->getSessionRegistrationCount($sessionId);

        return $registered < $session['capacity'];
    }

    // === Selection Actions ===

    /**
     * Register user for a session.
     */
    public function registerForSession(
        int $registrationId,
        int $sessionId,
        int $userId
    ): true|WP_Error {
        // Validate session exists
        $session = $this->sessions->getSession($sessionId);
        if (!$session) {
            return new WP_Error('session_not_found', 'Session not found');
        }

        // Check deadline
        $editionId = $session['edition_id'];
        if ($this->isSelectionLocked($editionId)) {
            return new WP_Error('deadline_passed', 'Selection deadline has passed');
        }

        // Check capacity
        if (!$this->hasCapacity($sessionId)) {
            return new WP_Error('no_capacity', 'Session is full');
        }

        // Check not already registered
        if ($this->isRegisteredForSession($userId, $sessionId)) {
            return new WP_Error('already_registered', 'Already registered for this session');
        }

        // Insert registration
        global $wpdb;
        $table = SessionRegistrationTable::getTableName();

        $result = $wpdb->insert($table, [
            'registration_id' => $registrationId,
            'session_id' => $sessionId,
            'user_id' => $userId,
            'status' => 'registered',
            'registered_at' => current_time('mysql'),
        ], ['%d', '%d', '%d', '%s', '%s']);

        if ($result === false) {
            return new WP_Error('db_error', 'Failed to register for session');
        }

        do_action('stride/session/user_registered', [
            'registration_id' => $registrationId,
            'session_id' => $sessionId,
            'user_id' => $userId,
        ]);

        return true;
    }

    /**
     * Cancel user's session registration.
     */
    public function cancelSessionRegistration(int $userId, int $sessionId): true|WP_Error
    {
        $session = $this->sessions->getSession($sessionId);
        if (!$session) {
            return new WP_Error('session_not_found', 'Session not found');
        }

        // Check deadline
        if ($this->isSelectionLocked($session['edition_id'])) {
            return new WP_Error('deadline_passed', 'Selection deadline has passed');
        }

        global $wpdb;
        $table = SessionRegistrationTable::getTableName();

        $result = $wpdb->update(
            $table,
            [
                'status' => 'cancelled',
                'cancelled_at' => current_time('mysql'),
            ],
            [
                'user_id' => $userId,
                'session_id' => $sessionId,
            ],
            ['%s', '%s'],
            ['%d', '%d']
        );

        if ($result === false) {
            return new WP_Error('db_error', 'Failed to cancel session registration');
        }

        return true;
    }

    /**
     * Check if user is registered for session.
     */
    public function isRegisteredForSession(int $userId, int $sessionId): bool
    {
        global $wpdb;
        $table = SessionRegistrationTable::getTableName();

        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table}
             WHERE user_id = %d AND session_id = %d AND status = 'registered'",
            $userId,
            $sessionId
        ));

        return (int) $count > 0;
    }

    // === Deadline Checks ===

    /**
     * Check if selection is locked for an edition.
     */
    public function isSelectionLocked(int $editionId): bool
    {
        $deadline = $this->editions->getField($editionId, 'selection_deadline');

        if (empty($deadline)) {
            return false;
        }

        return strtotime($deadline) < time();
    }

    /**
     * Get days until selection deadline.
     */
    public function getDaysUntilDeadline(int $editionId): ?int
    {
        $deadline = $this->editions->getField($editionId, 'selection_deadline');

        if (empty($deadline)) {
            return null;
        }

        $diff = strtotime($deadline) - time();

        return (int) floor($diff / DAY_IN_SECONDS);
    }

    /**
     * Check if selection window is open.
     */
    public function isSelectionOpen(int $editionId): bool
    {
        // Check deadline not passed
        if ($this->isSelectionLocked($editionId)) {
            return false;
        }

        // Check if edition has slots configured
        $slots = $this->editions->getField($editionId, 'session_slots');

        return !empty($slots);
    }

    // === Slot Validation ===

    /**
     * Get slot configuration for edition.
     *
     * @return array<array<string, mixed>>
     */
    public function getSlotConfig(int $editionId): array
    {
        $slots = $this->editions->getField($editionId, 'session_slots');

        if (empty($slots)) {
            return [];
        }

        return is_array($slots) ? $slots : (json_decode($slots, true) ?: []);
    }

    /**
     * Validate user's selections meet slot requirements.
     */
    public function validateSelections(int $registrationId, int $editionId): true|WP_Error
    {
        $slots = $this->getSlotConfig($editionId);
        $selections = $this->getUserSelections($registrationId);
        $selectedSessionIds = array_column($selections, 'session_id');

        foreach ($slots as $slot) {
            $slotName = $slot['slot'] ?? '';
            $required = $slot['required'] ?? false;
            $pickCount = $slot['pick_count'] ?? 1;

            if (!$required) {
                continue;
            }

            // Get sessions in this slot
            $slotSessions = $this->sessions->getSessionsBySlot($editionId, $slotName);
            $slotSessionIds = array_column($slotSessions, 'id');

            // Count how many selected
            $selectedInSlot = count(array_intersect($selectedSessionIds, $slotSessionIds));

            if ($selectedInSlot < $pickCount) {
                return new WP_Error(
                    'incomplete_selection',
                    sprintf('Slot "%s" requires %d selection(s), got %d', $slotName, $pickCount, $selectedInSlot)
                );
            }
        }

        return true;
    }
}
