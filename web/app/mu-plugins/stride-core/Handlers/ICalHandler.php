<?php

declare(strict_types=1);

namespace Stride\Handlers;

use Stride\Modules\Edition\EditionRepository;
use Stride\Modules\Edition\EditionService;
use Stride\Modules\Edition\SessionService;
use Stride\Modules\Enrollment\RegistrationRepository;
use WP_Error;

/**
 * Handles iCal download requests.
 *
 * Thin handler - generates iCal files for user's sessions.
 */
final class ICalHandler
{
    /** @var array<int, array{courseTitle: string, venue: string}> Per-edition cache */
    private array $editionCache = [];

    public function __construct()
    {
        $this->init();
    }

    private function init(): void
    {
        add_filter('ntdst/api_data/stride_download_ical', [$this, 'handleDownloadIcal'], 10, 2);
    }

    /**
     * Handle iCal download.
     *
     * Uses Response->download() which outputs the file and exits,
     * bypassing the normal JSON response.
     *
     * @param mixed $data Existing data (unused)
     * @param array<string, mixed> $params Request parameters
     * @return never|WP_Error
     */
    public function handleDownloadIcal(mixed $data, array $params): WP_Error
    {
        $userId = get_current_user_id();
        if (!$userId) {
            return new WP_Error('not_logged_in', __('Je moet ingelogd zijn.', 'stride'));
        }

        $sessionId = absint($params['session_id'] ?? 0);
        $editionId = absint($params['edition_id'] ?? 0);

        $ical = $this->generateIcal($userId, $sessionId, $editionId);

        // This exits - never returns
        ntdst_response()->download($ical, 'stride-calendar.ics');
    }

    /**
     * Generate iCal content.
     */
    private function generateIcal(int $userId, int $sessionId, int $editionId): string
    {
        $events = [];

        $sessionService = ntdst_get(SessionService::class);
        $editionService = ntdst_get(EditionService::class);
        $registrationRepo = ntdst_get(RegistrationRepository::class);

        if ($sessionId) {
            // Single session
            $session = $sessionService->getSession($sessionId);
            if ($session) {
                // Security: Verify user is enrolled in this session's edition
                $registration = $registrationRepo->findByUserAndEdition($userId, (int) $session['edition_id']);
                if (!$registration) {
                    return $this->buildIcal([]); // Return empty calendar if not enrolled
                }
                $events[] = $this->sessionToEvent($session, $editionService);
            }
        } elseif ($editionId) {
            // Security: Verify user is enrolled in this edition
            $registration = $registrationRepo->findByUserAndEdition($userId, $editionId);
            if (!$registration) {
                return $this->buildIcal([]); // Return empty calendar if not enrolled
            }
            $selectedIds = array_map('intval', $registration->selections ?? []);
            $sessions = $sessionService->getSessionsForEdition($editionId);
            foreach ($sessions as $session) {
                if (!$this->isScheduledSession($session)) {
                    continue;
                }
                // If user has selections, only include selected + non-slot sessions
                if (!empty($selectedIds) && !empty($session['slot']) && !in_array((int) $session['id'], $selectedIds, true)) {
                    continue;
                }
                $events[] = $this->sessionToEvent($session, $editionService);
            }
        } else {
            // All user's upcoming scheduled sessions (pending + confirmed)
            $registrations = $registrationRepo->findByUser($userId);
            $activeStatuses = ['pending', 'confirmed'];

            foreach ($registrations as $reg) {
                if (!in_array($reg->status ?? '', $activeStatuses, true)) {
                    continue;
                }
                $edId = (int) ($reg->edition_id ?? 0);
                if (!$edId) {
                    continue;
                }
                $selectedIds = array_map('intval', $reg->selections ?? []);
                $sessions = $sessionService->getSessionsForEdition($edId);
                foreach ($sessions as $session) {
                    if (!$this->isScheduledSession($session)) {
                        continue;
                    }
                    if (!empty($selectedIds) && !empty($session['slot']) && !in_array((int) $session['id'], $selectedIds, true)) {
                        continue;
                    }
                    $events[] = $this->sessionToEvent($session, $editionService);
                }
            }
        }

        return $this->buildIcal($events);
    }

    /**
     * Convert session to iCal event array.
     *
     * @param array<string, mixed> $session
     * @return array<string, mixed>
     */
    private function sessionToEvent(array $session, EditionService $editionService): array
    {
        $editionId = (int) $session['edition_id'];

        if (!isset($this->editionCache[$editionId])) {
            $edition = $editionService->getEdition($editionId);
            $courseId = is_wp_error($edition) ? 0 : $editionService->getCourseId($editionId);
            $course = $courseId ? get_post($courseId) : null;
            $editionRepository = ntdst_get(EditionRepository::class);
            $venue = !is_wp_error($edition)
                ? $editionRepository->getField($edition->ID, 'venue', '')
                : '';

            $this->editionCache[$editionId] = [
                'courseTitle' => $course ? $course->post_title : 'Stride Training',
                'venue' => $venue,
            ];
        }

        $cached = $this->editionCache[$editionId];
        $date = $session['date'] ?? '';
        $startTime = $session['start_time'] ?? '';
        $endTime = $session['end_time'] ?? '';

        return [
            'uid' => 'session-' . $session['id'] . '@stride',
            'summary' => $cached['courseTitle'],
            'description' => $session['description'] ?? '',
            'location' => $session['location'] ?: $cached['venue'],
            'start' => $date && $startTime ? ($date . ' ' . $startTime) : '',
            'end' => $date && $endTime ? ($date . ' ' . $endTime) : '',
        ];
    }

    /**
     * Build iCal file content.
     *
     * @param array<array<string, mixed>> $events
     */
    private function buildIcal(array $events): string
    {
        $ical = "BEGIN:VCALENDAR\r\n";
        $ical .= "VERSION:2.0\r\n";
        $ical .= "PRODID:-//Stride//Calendar//NL\r\n";
        $ical .= "CALSCALE:GREGORIAN\r\n";
        $ical .= "METHOD:PUBLISH\r\n";

        foreach ($events as $event) {
            if (empty($event['start'])) {
                continue;
            }

            $ical .= "BEGIN:VEVENT\r\n";
            $ical .= "UID:" . $event['uid'] . "\r\n";
            $ical .= "DTSTAMP:" . gmdate('Ymd\THis\Z') . "\r\n";
            $ical .= "SUMMARY:" . $this->escapeIcal($event['summary']) . "\r\n";
            $ical .= "DTSTART:" . $this->formatIcalDate($event['start']) . "\r\n";
            if (!empty($event['end'])) {
                $ical .= "DTEND:" . $this->formatIcalDate($event['end']) . "\r\n";
            }
            if (!empty($event['location'])) {
                $ical .= "LOCATION:" . $this->escapeIcal($event['location']) . "\r\n";
            }
            if (!empty($event['description'])) {
                $ical .= "DESCRIPTION:" . $this->escapeIcal($event['description']) . "\r\n";
            }
            $ical .= "END:VEVENT\r\n";
        }

        $ical .= "END:VCALENDAR\r\n";

        return $ical;
    }

    /**
     * Check if session has a scheduled date+time (not self-paced online/assignment).
     */
    private function isScheduledSession(array $session): bool
    {
        $type = $session['type'] ?? 'in_person';
        if (in_array($type, ['online', 'assignment'], true)) {
            return false;
        }

        return !empty($session['date']) && !empty($session['start_time']);
    }

    /**
     * Escape string for iCal.
     */
    private function escapeIcal(string $text): string
    {
        return str_replace(["\n", "\r", ",", ";", "\\"], ["\\n", "", "\\,", "\\;", "\\\\"], $text);
    }

    /**
     * Format date for iCal.
     */
    private function formatIcalDate(string $datetime): string
    {
        $timestamp = strtotime($datetime);
        return $timestamp ? gmdate('Ymd\THis\Z', $timestamp) : '';
    }
}
