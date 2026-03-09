<?php

declare(strict_types=1);

namespace Stride\Modules\User;

use Stride\Domain\RegistrationStatus;
use Stride\Integrations\LearnDash\LearnDashHelper;
use Stride\Modules\Attendance\AttendanceService;
use Stride\Modules\Edition\EditionCompletion;
use Stride\Modules\Edition\EditionService;
use Stride\Modules\Edition\SessionService;
use Stride\Modules\Enrollment\EnrollmentCompletion;
use Stride\Modules\Enrollment\RegistrationRepository;

/**
 * User Dashboard Data Aggregation
 *
 * Prepares display-ready data for user dashboard tabs.
 * Templates receive pre-assembled arrays and only handle rendering.
 *
 * Plain class — registered in stride-core.php.
 */
final class UserDashboardService
{
    public function __construct(
        private readonly RegistrationRepository $registrationRepo,
        private readonly EditionService $editionService,
        private readonly SessionService $sessionService,
        private readonly AttendanceService $attendanceService,
        private readonly EditionCompletion $completionService,
    ) {
    }

    /**
     * Get all enrollment data for dashboard display.
     *
     * @return array{
     *   active_editions: array,
     *   active_online: array,
     *   completed_items: array,
     *   cancelled_editions: array,
     *   upcoming_sessions: array
     * }
     */
    public function getEnrollmentData(int $userId): array
    {
        [$activeEditions, $completedEditions, $cancelledEditions] = $this->buildEditionRegistrations($userId);
        [$activeOnline, $completedOnline] = $this->buildOnlineCourses($userId);

        $completedItems = array_merge($completedEditions, $completedOnline);
        usort($completedItems, fn($a, $b) => strcmp($b['completed_at'] ?? '', $a['completed_at'] ?? ''));

        return [
            'active_editions'    => $activeEditions,
            'active_online'      => $activeOnline,
            'completed_items'    => $completedItems,
            'cancelled_editions' => $cancelledEditions,
            'upcoming_sessions'  => $this->buildUpcomingSessions($activeEditions),
        ];
    }

    /**
     * Build edition registration data grouped by status.
     *
     * @return array{0: array, 1: array, 2: array} [active, completed, cancelled]
     */
    private function buildEditionRegistrations(int $userId): array
    {
        $registrations = $this->registrationRepo->findByUser($userId);
        $editionModel  = ntdst_data()->get('vad_edition');
        $active = $completed = $cancelled = [];

        foreach ($registrations as $reg) {
            if (empty($reg->edition_id)) {
                continue;
            }

            $editionId = (int) $reg->edition_id;
            $edition   = $this->editionService->getEdition($editionId);

            if (is_wp_error($edition) || $this->editionService->isOnline($editionId)) {
                continue;
            }

            $courseId = $this->editionService->getCourseId($editionId);
            $course   = $courseId ? get_post($courseId) : null;
            $sessions = $this->sessionService->getSessionsForEdition($editionId);

            foreach ($sessions as &$session) {
                $status = $this->attendanceService->getStatus((int) $session['id'], $userId);
                $session['attendance'] = $status?->value;
            }
            unset($session);

            $regData = [
                'id'               => (int) $reg->id,
                'edition_id'       => $editionId,
                'course_id'        => $courseId,
                'course_title'     => $course ? $course->post_title : $edition->post_title,
                'start_date'       => $editionModel->getMeta($editionId, 'start_date', ''),
                'venue'            => $editionModel->getMeta($editionId, 'venue', ''),
                'status'           => $reg->status,
                'sessions'         => $sessions,
                'progress'         => $this->completionService->getProgress($editionId, $userId),
                'completion_tasks' => $reg->completion_tasks ?? null,
                'task_summary'     => null,
                'complete_url'     => null,
                'type'             => 'edition',
            ];

            if ($reg->status === 'pending' && !empty($reg->completion_tasks)) {
                $enrollment = ntdst_get(EnrollmentCompletion::class);
                $regData['task_summary'] = $enrollment->getTaskSummary((int) $reg->id);
                $regData['complete_url'] = home_url('/vormingen/' . get_post_field('post_name', $editionId) . '/voltooien/');
            }

            $regStatus = RegistrationStatus::tryFrom($reg->status) ?? RegistrationStatus::Confirmed;

            match ($regStatus) {
                RegistrationStatus::Completed => $completed[] = array_merge($regData, ['completed_at' => $regData['start_date']]),
                RegistrationStatus::Cancelled => $cancelled[] = $regData,
                default                       => $active[] = $regData,
            };
        }

        return [$active, $completed, $cancelled];
    }

    /**
     * Build online course data for enrolled/started courses.
     *
     * @return array{0: array, 1: array} [active, completed]
     */
    private function buildOnlineCourses(int $userId): array
    {
        $enrolledIds = LearnDashHelper::getEnrolledCourses($userId);
        if (empty($enrolledIds)) {
            return [[], []];
        }

        // Start from user's enrolled courses, filter to online formats
        $onlineCourseIds = get_posts([
            'post_type'      => 'sfwd-courses',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'fields'         => 'ids',
            'post__in'       => $enrolledIds,
            'tax_query'      => [
                ['taxonomy' => 'stride_format', 'field' => 'slug', 'terms' => ['online', 'e-learning', 'webinar']],
            ],
        ]);

        $active = $completed = [];

        foreach ($onlineCourseIds as $courseId) {
            $courseId = (int) $courseId;

            $course = get_post($courseId);
            if (!$course || $course->post_status !== 'publish') {
                continue;
            }

            $lessonsData = LearnDashHelper::getLessonsWithAvailability($courseId, $userId);
            $nextDrip    = null;
            foreach ($lessonsData as $l) {
                if (!$l['is_available'] && $l['available_from']) {
                    $nextDrip = $l;
                    break;
                }
            }

            $data = [
                'course_id'         => $courseId,
                'course_title'      => $course->post_title,
                'course_url'        => LearnDashHelper::getResumeUrl($courseId, $userId),
                'progress'          => LearnDashHelper::getProgress($courseId, $userId),
                'format_label'      => $this->getFormatLabel($courseId),
                'type'              => 'online',
                'total_lessons'     => count($lessonsData),
                'completed_lessons' => count(array_filter($lessonsData, fn($l) => $l['completed'])),
                'last_activity'     => LearnDashHelper::getLastActivityDate($courseId, $userId),
                'days_remaining'    => LearnDashHelper::getAccessDaysRemaining($courseId, $userId),
                'next_drip'         => $nextDrip,
            ];

            if (LearnDashHelper::isComplete($courseId, $userId)) {
                $completionDate = LearnDashHelper::getCompletionDate($courseId, $userId);
                $data['completed_at']     = $completionDate ? date('Y-m-d', $completionDate) : '';
                $data['certificate_url']  = LearnDashHelper::getCertificateLink($courseId, $userId);
                $completed[] = $data;
            } else {
                $active[] = $data;
            }
        }

        return [$active, $completed];
    }

    /**
     * Get stride_format display label for a course.
     */
    private function getFormatLabel(int $courseId): string
    {
        $formats = get_the_terms($courseId, 'stride_format');
        if (!$formats || is_wp_error($formats)) {
            return __('Online', 'stride');
        }

        foreach ($formats as $fmt) {
            if ($fmt->slug === 'e-learning') {
                return 'E-learning';
            }
            if ($fmt->slug === 'webinar') {
                return 'Webinar';
            }
        }

        return __('Online', 'stride');
    }

    /**
     * Build upcoming sessions from active edition registrations.
     *
     * @return array First 3 upcoming sessions sorted by date
     */
    private function buildUpcomingSessions(array $activeEditions): array
    {
        $today    = date('Y-m-d');
        $sessions = [];

        foreach ($activeEditions as $reg) {
            foreach ($reg['sessions'] as $session) {
                if (!empty($session['date']) && $session['date'] >= $today) {
                    $sessions[] = array_merge($session, [
                        'course_title' => $reg['course_title'],
                        'edition_id'   => $reg['edition_id'],
                    ]);
                }
            }
        }

        usort($sessions, fn($a, $b) => strcmp($a['date'] ?? '', $b['date'] ?? ''));

        return array_slice($sessions, 0, 3);
    }

    /**
     * Get quote data grouped by status for dashboard display.
     *
     * @return array{active: array, cancelled: array}
     */
    public function getQuoteData(int $userId): array
    {
        $quoteService = ntdst_get(\Stride\Modules\Invoicing\QuoteService::class);
        $quotes = $quoteService->getUserQuotes($userId);

        $active = $cancelled = [];

        foreach ($quotes as $quote) {
            $status = $quote['status_enum'] ?? \Stride\Domain\QuoteStatus::Draft;

            $data = [
                'id'           => (int) ($quote['ID'] ?? $quote['id'] ?? 0),
                'quote_number' => $quote['quote_number'] ?? '',
                'title'        => $quote['post_title'] ?? $quote['title'] ?? '',
                'status'       => $status,
                'status_label' => $status->label(),
                'total'        => $quote['total_money'],
                'subtotal'     => $quote['subtotal_money'],
                'discount'     => $quote['discount_money'],
                'tax'          => $quote['tax_money'],
                'items'        => $quote['items'] ?? [],
                'valid_until'  => $quote['valid_until'] ?? '',
                'created_at'   => $quote['post_date'] ?? '',
                'voucher_code' => $quote['voucher_code'] ?? '',
                'billing'      => [
                    'company'     => $quote['billing']['company'] ?? '',
                    'email'       => $quote['billing_email'] ?? $quote['billing']['email'] ?? '',
                    'address'     => $quote['billing_address'] ?? $quote['billing']['address'] ?? '',
                    'postal_code' => $quote['billing_postal_code'] ?? $quote['billing']['postal_code'] ?? '',
                    'city'        => $quote['billing_city'] ?? $quote['billing']['city'] ?? '',
                    'vat_number'  => $quote['billing_vat_number'] ?? $quote['billing']['vat_number'] ?? '',
                ],
            ];

            if ($status === \Stride\Domain\QuoteStatus::Cancelled) {
                $cancelled[] = $data;
            } else {
                $active[] = $data;
            }
        }

        usort($active, fn($a, $b) => strcmp($a['created_at'], $b['created_at']));

        return ['active' => $active, 'cancelled' => $cancelled];
    }
}
