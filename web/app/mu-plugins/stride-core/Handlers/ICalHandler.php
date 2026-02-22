<?php

declare(strict_types=1);

namespace Stride\Handlers;

use Stride\Modules\Edition\EditionRepository;
use Stride\Modules\Edition\EditionService;
use Stride\Modules\Edition\SessionService;
use Stride\Modules\Enrollment\RegistrationRepository;

/**
 * Handles iCal download requests.
 *
 * Thin handler - generates iCal files for user's sessions.
 */
final class ICalHandler
{
    public function __construct()
    {
        $this->init();
    }

    private function init(): void
    {
        add_action('wp_ajax_stride_download_ical', [$this, 'ajaxDownloadIcal']);
    }

    /**
     * AJAX: Download iCal file.
     */
    public function ajaxDownloadIcal(): void
    {
        if (!wp_verify_nonce($_GET['nonce'] ?? '', 'stride_ical')) {
            wp_die(__('Ongeldige link.', 'stride'));
        }

        $userId = get_current_user_id();
        if (!$userId) {
            wp_die(__('Je moet ingelogd zijn.', 'stride'));
        }

        $sessionId = absint($_GET['session_id'] ?? 0);
        $editionId = absint($_GET['edition_id'] ?? 0);

        $ical = $this->generateIcal($userId, $sessionId, $editionId);

        header('Content-Type: text/calendar; charset=utf-8');
        header('Content-Disposition: attachment; filename="stride-calendar.ics"');
        echo $ical;
        exit;
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
            // All sessions for edition
            $sessions = $sessionService->getSessionsForEdition($editionId);
            foreach ($sessions as $session) {
                $events[] = $this->sessionToEvent($session, $editionService);
            }
        } else {
            // All user's upcoming sessions (already filtered by user's registrations)
            $registrations = $registrationRepo->findByUser($userId, 'confirmed');

            foreach ($registrations as $reg) {
                $sessions = $sessionService->getSessionsForEdition((int) $reg->edition_id);
                foreach ($sessions as $session) {
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
        $edition = $editionService->getEdition($session['edition_id']);
        $courseId = is_wp_error($edition) ? 0 : $editionService->getCourseId($session['edition_id']);
        $course = $courseId ? get_post($courseId) : null;

        // Get venue from edition via repository
        $venue = '';
        if (!is_wp_error($edition)) {
            $editionRepository = ntdst_get(EditionRepository::class);
            $venue = $editionRepository->getField($edition->ID, 'venue', '');
        }

        return [
            'uid' => 'session-' . $session['id'] . '@stride',
            'summary' => $course ? $course->post_title : 'Stride Training',
            'description' => $session['description'] ?? '',
            'location' => $session['location'] ?: $venue,
            'start' => $session['start_time'] ?? '',
            'end' => $session['end_time'] ?? '',
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
        return gmdate('Ymd\THis\Z', $timestamp);
    }
}
