<?php

namespace stride\services\frontend;

defined('ABSPATH') || exit;

use ntdst\Stride\core\CourseService;

/**
 * iCal Service
 *
 * Generates iCal/ICS files for calendar downloads.
 *
 * @package stride\services\frontend
 */
class ICalService implements \NTDST_Service_Meta
{
    private ?CourseService $courseService;

    /**
     * Service metadata for NTDST Bootstrap
     */
    public static function metadata(): array
    {
        return [
            'name' => 'iCal Service',
            'description' => 'Calendar event generation and downloads',
            'admin_only' => false,
            'enabled' => true,
            'priority' => 20,
        ];
    }

    /**
     * Constructor
     */
    public function __construct(?CourseService $courseService = null)
    {
        $this->courseService = $courseService ?? $this->resolveService(CourseService::class);
    }

    /**
     * Resolve service from DI container
     */
    private function resolveService(string $class): ?object
    {
        if (function_exists('ntdst_get')) {
            try {
                return ntdst_get($class);
            } catch (\Exception $e) {
                return null;
            }
        }
        return null;
    }

    /**
     * Generate iCal content for a single course
     *
     * @param int $courseId
     * @return string|null
     */
    public function generateCourseEvent(int $courseId): ?string
    {
        if (!$this->courseService) {
            return null;
        }

        $course = $this->courseService->getCourse($courseId);
        $dates = $this->courseService->getCourseDates($courseId);
        $location = $this->courseService->getCourseAddress($courseId);

        if (!$course || empty($dates)) {
            return null;
        }

        $events = [];
        $host = wp_parse_url(home_url(), PHP_URL_HOST);

        foreach ($dates as $index => $timestamp) {
            $uid = sprintf('stride-course-%d-date-%d@%s', $courseId, $index, $host);
            $events[] = $this->generateEvent([
                'uid' => $uid,
                'summary' => $course->post_title,
                'description' => wp_strip_all_tags(get_the_excerpt($course)),
                'location' => $location,
                'start' => $timestamp,
                'end' => $timestamp + 28800, // Default 8 hours
                'url' => get_permalink($courseId),
            ]);
        }

        return $this->generateCalendar($events);
    }

    /**
     * Download iCal for a course
     *
     * @param int $courseId
     */
    public function downloadCourseEvent(int $courseId): void
    {
        $ical = $this->generateCourseEvent($courseId);

        if ($ical === null) {
            wp_die(__('Geen datum gevonden voor deze cursus.', 'stride'));
        }

        $filename = sanitize_file_name('stride-cursus-' . $courseId . '.ics');

        header('Content-Type: text/calendar; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-cache, must-revalidate');
        header('Expires: 0');

        echo $ical;
        exit;
    }

    /**
     * Generate iCal for all user's upcoming dates
     *
     * @param int $userId
     * @return string|null
     */
    public function generateUserAgenda(int $userId): ?string
    {
        $dashboardService = stride_service(DashboardService::class);

        if (!$dashboardService) {
            return null;
        }

        $upcomingDates = $dashboardService->getUpcomingDates($userId, 50);

        if (empty($upcomingDates)) {
            return null;
        }

        $events = [];
        $host = wp_parse_url(home_url(), PHP_URL_HOST);

        foreach ($upcomingDates as $date) {
            $uid = sprintf('stride-user-%d-course-%d-date-%d@%s',
                $userId,
                $date['course_id'],
                $date['timestamp'],
                $host
            );

            $events[] = $this->generateEvent([
                'uid' => $uid,
                'summary' => $date['course_title'],
                'location' => $date['location'],
                'start' => $date['timestamp'],
                'end' => $date['timestamp'] + 28800,
                'url' => get_permalink($date['course_id']),
            ]);
        }

        return $this->generateCalendar($events);
    }

    /**
     * Download user's full agenda
     *
     * @param int $userId
     */
    public function downloadUserAgenda(int $userId): void
    {
        $ical = $this->generateUserAgenda($userId);

        if ($ical === null) {
            wp_die(__('Geen komende afspraken gevonden.', 'stride'));
        }

        $filename = 'stride-mijn-agenda.ics';

        header('Content-Type: text/calendar; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-cache, must-revalidate');
        header('Expires: 0');

        echo $ical;
        exit;
    }

    /**
     * Generate single VEVENT block
     *
     * @param array $event Event data
     * @return string
     */
    private function generateEvent(array $event): string
    {
        $lines = [
            'BEGIN:VEVENT',
            'UID:' . $event['uid'],
            'DTSTAMP:' . gmdate('Ymd\THis\Z'),
            'DTSTART:' . gmdate('Ymd\THis\Z', $event['start']),
            'DTEND:' . gmdate('Ymd\THis\Z', $event['end']),
            'SUMMARY:' . $this->escapeText($event['summary']),
        ];

        if (!empty($event['description'])) {
            $lines[] = 'DESCRIPTION:' . $this->escapeText($event['description']);
        }

        if (!empty($event['location'])) {
            $lines[] = 'LOCATION:' . $this->escapeText($event['location']);
        }

        if (!empty($event['url'])) {
            $lines[] = 'URL:' . $event['url'];
        }

        $lines[] = 'END:VEVENT';

        return implode("\r\n", $lines);
    }

    /**
     * Generate full VCALENDAR wrapper
     *
     * @param array $events Array of VEVENT strings
     * @return string
     */
    private function generateCalendar(array $events): string
    {
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Stride LMS//NONSGML v1.0//NL',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:' . $this->escapeText(get_bloginfo('name')),
        ];

        foreach ($events as $event) {
            $lines[] = $event;
        }

        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", $lines);
    }

    /**
     * Escape text for iCal format
     *
     * @param string $text
     * @return string
     */
    private function escapeText(string $text): string
    {
        // Replace line breaks with \n
        $text = str_replace(["\r\n", "\r", "\n"], '\\n', $text);

        // Escape special characters
        $text = str_replace(['\\', ';', ','], ['\\\\', '\\;', '\\,'], $text);

        // Fold long lines (max 75 chars)
        if (strlen($text) > 75) {
            $text = wordwrap($text, 73, "\r\n ", true);
        }

        return $text;
    }
}
