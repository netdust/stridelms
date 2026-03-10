<?php

declare(strict_types=1);

namespace Stride\Modules\User;

use Stride\Domain\QuoteStatus;
use Stride\Domain\RegistrationStatus;
use Stride\Integrations\LearnDash\LearnDashHelper;
use Stride\Modules\Attendance\AttendanceService;
use Stride\Modules\Edition\EditionCompletion;
use Stride\Modules\Edition\EditionService;
use Stride\Modules\Edition\SessionService;
use Stride\Modules\Enrollment\EnrollmentCompletion;
use Stride\Modules\Enrollment\RegistrationRepository;
use WP_User;

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
     * Get aggregated data for the dashboard home screen.
     *
     * Reuses getEnrollmentData() and getQuoteData() internally so existing
     * tab views keep working while the home screen adds a unified overview.
     *
     * @return array{
     *   user: array{name: string, initials: string, email: string},
     *   hero: ?array,
     *   actions: array,
     *   active_enrollments: array,
     *   active_trajectories: array,
     *   recent_certificates: array,
     *   nav_items: array{opleidingen: bool, trajecten: bool, agenda: bool, offertes: bool, certificaten: bool},
     * }
     */
    public function getHomeData(int $userId): array
    {
        $user = get_userdata($userId);

        $enrollmentData = $this->getEnrollmentData($userId);
        $quoteData      = $this->getQuoteData($userId);

        $activeEnrollments = array_merge(
            $enrollmentData['active_editions'],
            $enrollmentData['active_online']
        );

        $actions      = $this->buildActionList($enrollmentData, $quoteData);
        $trajectories = $this->buildActiveTrajectories($userId);
        $certificates = array_slice($enrollmentData['completed_items'], 0, 3);

        return [
            'user' => [
                'name'     => $user ? trim($user->first_name . ' ' . $user->last_name) ?: $user->display_name : '',
                'initials' => $this->getInitials($user ?: null),
                'email'    => $user ? $user->user_email : '',
            ],
            'hero'                 => $this->resolveHero(
                $enrollmentData['upcoming_sessions'],
                $enrollmentData['action_items'],
                $activeEnrollments,
                $certificates,
            ),
            'actions'              => $actions,
            'active_enrollments'   => $activeEnrollments,
            'active_trajectories'  => $trajectories,
            'recent_certificates'  => $certificates,
            'nav_items'            => [
                'opleidingen'  => !empty($activeEnrollments) || !empty($enrollmentData['completed_items']),
                'trajecten'    => !empty($trajectories),
                'agenda'       => !empty($enrollmentData['upcoming_sessions']),
                'offertes'     => !empty($quoteData['active']) || !empty($quoteData['cancelled']),
                'certificaten' => !empty($enrollmentData['completed_items']),
            ],
        ];
    }

    /**
     * Resolve the single most important hero action for the dashboard.
     *
     * Priority order:
     * 1. Session today or tomorrow
     * 2. Pending action items (enrollment/post-course tasks)
     * 3. In-progress online course (progress > 0)
     * 4. First active enrollment
     * 5. Recent certificate
     * 6. Nothing
     */
    private function resolveHero(array $upcomingSessions, array $actionItems, array $activeEnrollments, array $certificates): ?array
    {
        $tomorrow = date('Y-m-d', strtotime('+1 day'));

        // 1. Session today or tomorrow
        foreach ($upcomingSessions as $session) {
            if (!empty($session['date']) && $session['date'] <= $tomorrow) {
                return ['type' => 'upcoming_session', 'data' => $session];
            }
        }

        // 2. Pending action items
        if (!empty($actionItems)) {
            return ['type' => 'action_required', 'data' => $actionItems[0]];
        }

        // 3. In-progress online course (progress > 0)
        foreach ($activeEnrollments as $enrollment) {
            if (($enrollment['type'] ?? '') === 'online' && ($enrollment['progress'] ?? 0) > 0) {
                return ['type' => 'continue_course', 'data' => $enrollment];
            }
        }

        // 4. First active enrollment
        if (!empty($activeEnrollments)) {
            return ['type' => 'active_enrollment', 'data' => $activeEnrollments[0]];
        }

        // 5. Recent certificate
        if (!empty($certificates)) {
            return ['type' => 'certificate_ready', 'data' => $certificates[0]];
        }

        return null;
    }

    /**
     * Build unified action list with colored nudges for dashboard display.
     *
     * @return array<array{type: string, color: string, label: string, url: string}>
     */
    private function buildActionList(array $enrollmentData, array $quoteData): array
    {
        $actions = [];

        // Upcoming sessions (blue)
        foreach ($enrollmentData['upcoming_sessions'] as $session) {
            $actions[] = [
                'type'  => 'upcoming_session',
                'color' => 'blue',
                'label' => ($session['course_title'] ?? __('Sessie', 'stride'))
                    . ' — ' . ($session['date'] ?? ''),
                'url'   => home_url('/mijn-account/?tab=agenda'),
            ];
        }

        // Enrollment/post-course action items (amber)
        foreach ($enrollmentData['action_items'] as $item) {
            $actions[] = [
                'type'  => $item['type'] ?? 'action_item',
                'color' => 'amber',
                'label' => $item['label'] . ': ' . $item['course_title'],
                'url'   => $item['url'],
            ];
        }

        // Unsigned quotes (amber)
        foreach ($quoteData['active'] as $quote) {
            $status = $quote['status'] ?? null;
            if ($status === QuoteStatus::Draft || $status === QuoteStatus::Sent) {
                $actions[] = [
                    'type'  => 'unsigned_quote',
                    'color' => 'amber',
                    'label' => __('Offerte', 'stride') . ' ' . ($quote['quote_number'] ?? '') . ' — ' . ($status?->label() ?? ''),
                    'url'   => home_url('/mijn-account/?tab=offertes'),
                ];
            }
        }

        // Recent certificates (green)
        foreach (array_slice($enrollmentData['completed_items'], 0, 3) as $cert) {
            $certUrl = $cert['certificate_url'] ?? '';
            if (!empty($certUrl)) {
                $actions[] = [
                    'type'  => 'certificate',
                    'color' => 'green',
                    'label' => __('Certificaat beschikbaar', 'stride') . ': ' . ($cert['course_title'] ?? ''),
                    'url'   => $certUrl,
                ];
            }
        }

        // Cap at 6 items to prevent overwhelming the home screen
        return array_slice($actions, 0, 6);
    }

    /**
     * Fetch active trajectory enrollments for the dashboard.
     *
     * @return array<array{id: int, title: string, slug: string, url: string}>
     */
    private function buildActiveTrajectories(int $userId): array
    {
        $enrollments = $this->registrationRepo->findTrajectoryEnrollmentsByUser($userId);
        $trajectories = [];

        foreach ($enrollments as $reg) {
            $trajectoryId = (int) ($reg->trajectory_id ?? 0);
            if (!$trajectoryId) {
                continue;
            }

            $post = get_post($trajectoryId);
            if (!$post || $post->post_status !== 'publish') {
                continue;
            }

            $trajectories[] = [
                'id'    => $trajectoryId,
                'title' => $post->post_title,
                'slug'  => $post->post_name,
                'url'   => home_url('/trajecten/' . $post->post_name . '/'),
            ];
        }

        return $trajectories;
    }

    /**
     * Get uppercase initials from a WP_User.
     *
     * Returns first letter of first_name + first letter of last_name.
     * Falls back to first letter of display_name. Returns '?' for null user.
     */
    private function getInitials(?WP_User $user): string
    {
        if (!$user) {
            return '?';
        }

        $first = mb_substr(trim($user->first_name ?? ''), 0, 1);
        $last  = mb_substr(trim($user->last_name ?? ''), 0, 1);

        if ($first !== '' || $last !== '') {
            return mb_strtoupper($first . $last);
        }

        $display = mb_substr(trim($user->display_name ?? ''), 0, 1);

        return $display !== '' ? mb_strtoupper($display) : '?';
    }

    /**
     * Get all enrollment data for dashboard display.
     *
     * @return array{
     *   active_editions: array,
     *   active_online: array,
     *   completed_items: array,
     *   cancelled_editions: array,
     *   upcoming_sessions: array,
     *   action_items: array
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
            'action_items'       => $this->buildActionItems($userId),
        ];
    }

    /**
     * Get pending action items for a user (both enrollment and post-course tasks).
     *
     * @return array{course_title: string, label: string, url: string, type: string}[]
     */
    private function buildActionItems(int $userId): array
    {
        $completion = ntdst_get(EnrollmentCompletion::class);
        $pending = $completion->getPendingForUser($userId);
        $items = [];

        foreach ($pending as $reg) {
            $editionId = (int) ($reg->edition_id ?? 0);
            if (!$editionId) {
                continue;
            }

            $tasks = is_string($reg->completion_tasks)
                ? json_decode($reg->completion_tasks, true) ?: []
                : (array) $reg->completion_tasks;

            $phase = 'enrollment';
            foreach ($tasks as $task) {
                if (($task['phase'] ?? 'enrollment') === 'post_course' && ($task['status'] ?? 'pending') !== 'completed') {
                    $phase = 'post_course';
                    break;
                }
            }

            $courseId = $this->editionService->getCourseId($editionId);
            $course = $courseId ? get_post($courseId) : null;
            $slug = get_post_field('post_name', $editionId);

            $items[] = [
                'course_title' => $course ? $course->post_title : get_the_title($editionId),
                'label' => $phase === 'post_course'
                    ? __('Rond opleiding af', 'stride')
                    : __('Voltooi inschrijving', 'stride'),
                'url' => home_url('/vormingen/' . $slug . '/voltooien/'),
                'type' => $phase,
            ];
        }

        return $items;
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

            // Populate task summary for pending (enrollment) and confirmed (post-course) registrations
            if (!empty($reg->completion_tasks) && in_array($reg->status, ['pending', 'confirmed'], true)) {
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

        usort($active, fn($a, $b) => strcmp($a['start_date'] ?? '', $b['start_date'] ?? ''));

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
