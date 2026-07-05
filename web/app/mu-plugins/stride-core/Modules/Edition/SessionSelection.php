<?php

declare(strict_types=1);

namespace Stride\Modules\Edition;

use Stride\Modules\Enrollment\RegistrationRepository;
use WP_Error;

/**
 * Session selection with deadline enforcement.
 *
 * Handles user picking sessions from available slots.
 * Selections stored as JSON on registration record.
 */
final class SessionSelection
{
    public function __construct(
        private readonly SessionService $sessions,
        private readonly EditionRepository $editions,
        private readonly RegistrationRepository $registrations,
    ) {}

    // === Selection Queries ===

    /**
     * Check if user selected a specific session.
     */
    public function hasSelectedSession(int $registrationId, int $sessionId): bool
    {
        $selections = $this->registrations->getSelections($registrationId);
        return in_array($sessionId, $selections, true);
    }

    // === Selection Actions ===

    /**
     * Set session selections for a registration.
     *
     * @param array<int> $sessionIds
     */
    public function setSelections(int $registrationId, array $sessionIds): true|WP_Error
    {
        $registration = $this->registrations->find($registrationId);
        if (!$registration) {
            return new WP_Error('registration_not_found', 'Registration not found');
        }

        $editionId = (int) $registration->edition_id;

        // Check deadline
        if ($this->isSelectionLocked($editionId)) {
            return new WP_Error('deadline_passed', 'Selection deadline has passed');
        }

        // Check selections are locked
        if ($this->registrations->areSelectionsLocked($registrationId)) {
            return new WP_Error('selections_locked', 'Selections are locked');
        }

        // Validate all sessions belong to this edition
        foreach ($sessionIds as $sessionId) {
            $session = $this->sessions->getSession($sessionId);
            if (!$session || $session['edition_id'] !== $editionId) {
                return new WP_Error('invalid_session', "Session {$sessionId} not found in this edition");
            }
        }

        // Seat gate: refuse a full session, but only when NEWLY added. A user who
        // already holds a seat on this session (re-submitting, or changing other
        // picks) occupies a seat they already have and must not be blocked by it.
        // Capacity 0 = unlimited (hasAvailableSeats returns true) — never blocks.
        $currentSelections = array_map('intval', $this->registrations->getSelections($registrationId));
        foreach ($sessionIds as $sessionId) {
            $sessionId = (int) $sessionId;
            $alreadyHeld = in_array($sessionId, $currentSelections, true);
            if (!$alreadyHeld && !$this->sessions->hasAvailableSeats($sessionId)) {
                return new WP_Error('session_full', "Sessie {$sessionId} is vol");
            }
        }

        // Save selections
        $result = $this->registrations->setSelections($registrationId, $sessionIds);

        if (!$result) {
            return new WP_Error('db_error', 'Failed to save selections');
        }

        // Invalidate the per-session selected-count cache for every written
        // session (both the newly-added and the retained ones) so the next
        // user's seat gate reads a fresh count.
        foreach ($sessionIds as $sessionId) {
            $this->sessions->invalidateSelectedCountCache((int) $sessionId);
        }

        do_action('stride/session/selections_updated', [
            'registration_id' => $registrationId,
            'edition_id' => $editionId,
            'session_ids' => $sessionIds,
        ]);

        return true;
    }

    /**
     * Add a session to selections.
     */
    public function addSession(int $registrationId, int $sessionId): true|WP_Error
    {
        $selections = $this->registrations->getSelections($registrationId);

        if (in_array($sessionId, $selections, true)) {
            return new WP_Error('already_selected', 'Session already selected');
        }

        $selections[] = $sessionId;

        return $this->setSelections($registrationId, $selections);
    }

    /**
     * Remove a session from selections.
     */
    public function removeSession(int $registrationId, int $sessionId): true|WP_Error
    {
        $selections = $this->registrations->getSelections($registrationId);

        $key = array_search($sessionId, $selections, true);
        if ($key === false) {
            return new WP_Error('not_selected', 'Session not selected');
        }

        unset($selections[$key]);

        return $this->setSelections($registrationId, array_values($selections));
    }

    /**
     * Lock selections (prevent further changes).
     */
    public function lockSelections(int $registrationId): true|WP_Error
    {
        if (!$this->registrations->lockSelections($registrationId)) {
            return new WP_Error('db_error', 'Failed to lock selections');
        }

        return true;
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
        if ($this->isSelectionLocked($editionId)) {
            return false;
        }

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
        $slots = $this->editions->getField($editionId, 'session_slots', []);

        return is_array($slots) ? $slots : [];
    }

    /**
     * Validate user's selections meet slot requirements.
     */
    public function validateSelections(int $registrationId, int $editionId): true|WP_Error
    {
        $slots = $this->getSlotConfig($editionId);
        $selectedSessionIds = $this->registrations->getSelections($registrationId);

        foreach ($slots as $slot) {
            $slotName = $slot['slot'] ?? '';
            $required = $slot['required'] ?? false;
            // Admin saves the chooser count as `max_selections` (canonical key,
            // set by EditionSessionsMetabox). Legacy slot configs from seed
            // data + JSON-stored rows used `pick_count` — keep as fallback.
            $pickCount = $slot['max_selections'] ?? $slot['pick_count'] ?? 1;

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
                    sprintf('Slot "%s" requires %d selection(s), got %d', $slotName, $pickCount, $selectedInSlot),
                );
            }
        }

        return true;
    }
}
