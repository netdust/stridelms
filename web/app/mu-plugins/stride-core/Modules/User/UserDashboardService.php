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
            'upcoming_sessions'    => $enrollmentData['upcoming_sessions'],
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
        $all = $enrollmentData['action_items'] ?? [];

        // Tasks (enrollment/completion) always first, lessons fill remaining slots
        $tasks   = array_filter($all, fn($a) => ($a['type'] ?? '') !== 'online_lesson');
        $lessons = array_filter($all, fn($a) => ($a['type'] ?? '') === 'online_lesson');

        $result = array_values($tasks);
        $remaining = max(0, 6 - count($result));

        return array_merge($result, array_slice(array_values($lessons), 0, $remaining));
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
        $items = [];

        // 1. Enrollment & post-course completion tasks
        $completion = ntdst_get(EnrollmentCompletion::class);
        $pending = $completion->getPendingForUser($userId);

        $taskLabels = [
            'session_selection' => __('Sessiekeuze', 'stride'),
            'questionnaire'     => __('Vragenlijst', 'stride'),
            'documents'         => __('Documenten', 'stride'),
            'approval'          => __('Goedkeuring', 'stride'),
            'post_evaluation'   => __('Evaluatie', 'stride'),
            'post_documents'    => __('Documenten', 'stride'),
            'post_approval'     => __('Goedkeuring', 'stride'),
        ];

        foreach ($pending as $reg) {
            $editionId = (int) ($reg->edition_id ?? 0);
            if (!$editionId) {
                continue;
            }

            $tasks = is_string($reg->completion_tasks)
                ? json_decode($reg->completion_tasks, true) ?: []
                : (array) $reg->completion_tasks;

            $phase = 'enrollment';
            $total = 0;
            $done = 0;
            $allTasks = [];

            foreach ($tasks as $type => $task) {
                $total++;
                $isComplete = ($task['status'] ?? 'pending') === 'completed';
                if ($isComplete) {
                    $done++;
                } elseif (($task['phase'] ?? 'enrollment') === 'post_course') {
                    $phase = 'post_course';
                }
                $allTasks[] = [
                    'label' => $taskLabels[$type] ?? $type,
                    'done'  => $isComplete,
                ];
            }

            // Check task availability — skip if only pending task is session_selection and it's not open
            $pendingTypes = array_keys(array_filter($tasks, fn($t) => ($t['status'] ?? 'pending') !== 'completed'));
            $isSessionSelection = $pendingTypes === ['session_selection'];

            if ($isSessionSelection) {
                $availability = $completion->getTaskAvailability($tasks, $editionId);
                if (($availability['session_selection']['state'] ?? '') !== 'available') {
                    continue; // Selection not open yet or deadline passed
                }
            }

            $courseId = $this->editionService->getCourseId($editionId);
            $course = $courseId ? get_post($courseId) : null;
            $slug = get_post_field('post_name', $editionId);

            $items[] = [
                'course_title' => $course ? $course->post_title : get_the_title($editionId),
                'label' => $isSessionSelection
                    ? __('Sessiekeuze', 'stride')
                    : ($phase === 'post_course'
                        ? __('Afronding', 'stride')
                        : __('Inschrijving', 'stride')),
                'url'        => home_url('/vormingen/' . $slug . '/voltooien/'),
                'type'       => $isSessionSelection ? 'session_selection' : $phase,
                'total_tasks' => $total,
                'done_tasks'  => $done,
                'all_tasks'   => $allTasks,
            ];
        }

        // 2. Uncompleted online session lessons (blended learning)
        $items = array_merge($items, $this->buildOnlineLessonActions($userId));

        return $items;
    }

    /**
     * Build action items for uncompleted lessons linked to online sessions.
     *
     * For each active edition enrollment, finds sessions of type 'online',
     * checks their linked LearnDash lessons, and surfaces available but
     * uncompleted lessons as action items.
     *
     * @return array<array{course_title: string, label: string, url: string, type: string}>
     */
    private function buildOnlineLessonActions(int $userId): array
    {
        $registrations = $this->registrationRepo->findByUser($userId);
        $items = [];

        foreach ($registrations as $reg) {
            $status = RegistrationStatus::tryFrom($reg->status ?? '');
            if (!$status || !in_array($status, [RegistrationStatus::Pending, RegistrationStatus::Confirmed], true)) {
                continue;
            }

            $editionId = (int) ($reg->edition_id ?? 0);
            if (!$editionId) {
                continue;
            }

            $courseId = $this->editionService->getCourseId($editionId);
            if (!$courseId) {
                continue;
            }

            $sessions = $this->sessionService->getSessionsForEdition($editionId);
            $course = get_post($courseId);
            $courseTitle = $course ? $course->post_title : get_the_title($editionId);

            foreach ($sessions as $session) {
                if (($session['type'] ?? '') !== 'online') {
                    continue;
                }

                $lessonIds = $session['lesson_ids'] ?? [];
                if (empty($lessonIds)) {
                    continue;
                }

                foreach ($lessonIds as $lessonId) {
                    $lessonId = (int) $lessonId;
                    if (!$lessonId) {
                        continue;
                    }

                    // Check availability (drip content / scheduling)
                    if (function_exists('ld_lesson_access_from')) {
                        $accessFrom = ld_lesson_access_from($lessonId, $userId, $courseId);
                        if ($accessFrom && $accessFrom > time()) {
                            continue; // Not yet available
                        }
                    }

                    // Check completion
                    if (function_exists('learndash_is_lesson_complete')
                        && learndash_is_lesson_complete($userId, $lessonId, $courseId)) {
                        continue; // Already done
                    }

                    $lessonPost = get_post($lessonId);
                    $lessonTitle = $lessonPost ? $lessonPost->post_title : '';

                    $items[] = [
                        'course_title' => $courseTitle,
                        'label'        => $lessonTitle,
                        'url'          => get_permalink($lessonId) ?: '',
                        'type'         => 'online_lesson',
                        'total_tasks'  => 0,
                        'done_tasks'   => 0,
                        'all_tasks'    => [],
                    ];
                }
            }
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
            $selectedIds = array_map('intval', $reg->selections ?? []);

            foreach ($sessions as &$session) {
                $status = $this->attendanceService->getStatus((int) $session['id'], $userId);
                $session['attendance'] = $status?->value;
                $session['selected'] = in_array((int) $session['id'], $selectedIds, true);
            }
            unset($session);

            // Attach quote summary if one exists for this registration
            $quoteService = ntdst_get(\Stride\Modules\Invoicing\QuoteService::class);
            $quote = $quoteService->getQuoteByRegistration((int) $reg->id);
            $quoteSummary = null;
            if ($quote) {
                $totalMoney = $quote['total_money'] ?? null;
                $quoteSummary = [
                    'id'           => (int) ($quote['ID'] ?? $quote['id'] ?? 0),
                    'status'       => $quote['status_enum'] ?? \Stride\Domain\QuoteStatus::Draft,
                    'status_label' => ($quote['status_enum'] ?? \Stride\Domain\QuoteStatus::Draft)->label(),
                    'total'        => $totalMoney instanceof \Stride\Domain\Money ? $totalMoney->format() : '',
                    'quote_number' => $quote['quote_number'] ?? '',
                ];
            }

            // Find next upcoming session (in_person only, today or later)
            $today = date('Y-m-d');
            $nextSession = null;
            foreach ($sessions as $s) {
                if (($s['type'] ?? '') === 'in_person' && !empty($s['date']) && $s['date'] >= $today) {
                    if (!$nextSession || $s['date'] < $nextSession['date']) {
                        $nextSession = $s;
                    }
                }
            }

            $regData = [
                'id'               => (int) $reg->id,
                'edition_id'       => $editionId,
                'course_id'        => $courseId,
                'course_title'     => $course ? $course->post_title : $edition->post_title,
                'start_date'       => $editionModel->getMeta($editionId, 'start_date', ''),
                'venue'            => $editionModel->getMeta($editionId, 'venue', ''),
                'status'           => $reg->status,
                'sessions'         => $sessions,
                'next_session'     => $nextSession,
                'progress'         => $this->completionService->getProgress($editionId, $userId),
                'completion_tasks' => $reg->completion_tasks ?? null,
                'task_summary'     => null,
                'complete_url'     => null,
                'quote'            => $quoteSummary,
                'type'             => 'edition',
                'cta'              => null,
            ];

            // Populate task summary for pending (enrollment) and confirmed (post-course) registrations
            if (!empty($reg->completion_tasks) && in_array($reg->status, ['pending', 'confirmed'], true)) {
                $enrollment = ntdst_get(EnrollmentCompletion::class);
                $regData['task_summary'] = $enrollment->getTaskSummary((int) $reg->id);
                $regData['complete_url'] = home_url('/vormingen/' . get_post_field('post_name', $editionId) . '/voltooien/');
                $regData['cta'] = $this->calculateEditionCTA($regData['task_summary'], $regData['complete_url'], $editionId);
            }

            $regStatus = RegistrationStatus::tryFrom($reg->status) ?? RegistrationStatus::Confirmed;

            match ($regStatus) {
                RegistrationStatus::Completed => $completed[] = array_merge($regData, ['completed_at' => $regData['start_date']]),
                RegistrationStatus::Cancelled => $cancelled[] = $regData,
                default                       => $active[] = $regData,
            };
        }

        // Sort by next upcoming session date, fallback to start_date
        usort($active, function ($a, $b) {
            $dateA = $a['next_session']['date'] ?? $a['start_date'] ?? '';
            $dateB = $b['next_session']['date'] ?? $b['start_date'] ?? '';
            return strcmp($dateA, $dateB);
        });

        return [$active, $completed, $cancelled];
    }

    /**
     * Calculate the CTA (call-to-action) for an edition enrollment.
     *
     * Rules:
     * - If pending tasks > 0: label based on task type (session_selection / post_course / default)
     * - If session_selection done but re-editable (selection_open, deadline not passed, course not started): "Sessiekeuze wijzigen"
     * - Otherwise: null (no CTA)
     *
     * @return array{url: string, label: string}|null
     */
    private function calculateEditionCTA(?array $taskSummary, ?string $completeUrl, int $editionId): ?array
    {
        if (!$taskSummary || !$completeUrl) {
            return null;
        }

        $total     = (int) ($taskSummary['total'] ?? 0);
        $completed = (int) ($taskSummary['completed'] ?? 0);
        $pending   = $total - $completed;
        $tasks     = $taskSummary['tasks'] ?? [];

        // Pending tasks: determine CTA label by task type
        if ($pending > 0) {
            $hasSessionSelection = isset($tasks['session_selection'])
                && ($tasks['session_selection']['status'] ?? '') !== 'completed';
            $hasPostCourse = false;
            foreach (['post_evaluation', 'post_documents', 'post_approval'] as $pt) {
                if (isset($tasks[$pt]) && ($tasks[$pt]['status'] ?? '') !== 'completed') {
                    $hasPostCourse = true;
                    break;
                }
            }

            $label = match (true) {
                $hasSessionSelection => __('Sessiekeuze maken', 'stridence'),
                $hasPostCourse       => __('Vorming afronden', 'stridence'),
                default              => __('Inschrijving voltooien', 'stridence'),
            };

            return ['url' => $completeUrl, 'label' => $label];
        }

        // Session selection done — check if re-editable
        if (!empty($tasks['session_selection'])) {
            $editionModel  = ntdst_data()->get('vad_edition');
            $selOpen       = (bool) $editionModel->getMeta($editionId, 'selection_open');
            $deadline      = $editionModel->getMeta($editionId, 'selection_deadline');
            $startDate     = $editionModel->getMeta($editionId, 'start_date');
            $pastDeadline  = $deadline && strtotime($deadline) < current_time('timestamp');
            $courseStarted = $startDate && strtotime($startDate) < current_time('timestamp');

            if ($selOpen && !$pastDeadline && !$courseStarted) {
                return ['url' => $completeUrl, 'label' => __('Sessiekeuze wijzigen', 'stridence')];
            }
        }

        return null;
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
                // Skip online/assignment sessions — those show as action items, not agenda
                $sessionType = $session['type'] ?? 'in_person';
                if (in_array($sessionType, ['online', 'assignment'], true)) {
                    continue;
                }

                if (!empty($session['date']) && $session['date'] >= $today) {
                    $sessions[] = array_merge($session, [
                        'course_title' => $reg['course_title'],
                        'edition_id'   => $reg['edition_id'],
                    ]);
                }
            }
        }

        usort($sessions, fn($a, $b) => strcmp($a['date'] ?? '', $b['date'] ?? ''));

        return array_slice($sessions, 0, 5);
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
