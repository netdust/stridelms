<?php

declare(strict_types=1);

namespace Stride\Modules\PartnerAPI;

use Stride\Modules\Enrollment\RegistrationRepository;
use Stride\Modules\Attendance\AttendanceRepository;
use Stride\Modules\Edition\EditionService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * REST API controller for partner access.
 *
 * Endpoints:
 * - GET /stride/v1/partner/users
 * - GET /stride/v1/partner/enrollments
 * - GET /stride/v1/partner/enrollments/{id}
 * - GET /stride/v1/partner/certificates
 * - GET /stride/v1/partner/attendance
 * - POST /stride/v1/partner/enrollments
 */
final class PartnerAPIController
{
    private const NAMESPACE = 'stride/v1';
    private const ROUTE_PREFIX = 'partner';

    public function __construct(
        private readonly RegistrationRepository $registrationRepository,
        private readonly AttendanceRepository $attendanceRepository,
        private readonly EditionService $editionService,
    ) {
        $this->init();
    }

    private function init(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        // GET /partner/users
        register_rest_route(self::NAMESPACE, '/' . self::ROUTE_PREFIX . '/users', [
            'methods' => 'GET',
            'callback' => [$this, 'getUsers'],
            'permission_callback' => [$this, 'checkPermission'],
        ]);

        // GET /partner/enrollments
        register_rest_route(self::NAMESPACE, '/' . self::ROUTE_PREFIX . '/enrollments', [
            'methods' => 'GET',
            'callback' => [$this, 'getEnrollments'],
            'permission_callback' => [$this, 'checkPermission'],
        ]);

        // GET /partner/enrollments/{id}
        register_rest_route(self::NAMESPACE, '/' . self::ROUTE_PREFIX . '/enrollments/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [$this, 'getEnrollment'],
            'permission_callback' => [$this, 'checkPermission'],
        ]);

        // GET /partner/certificates
        register_rest_route(self::NAMESPACE, '/' . self::ROUTE_PREFIX . '/certificates', [
            'methods' => 'GET',
            'callback' => [$this, 'getCertificates'],
            'permission_callback' => [$this, 'checkPermission'],
        ]);

        // GET /partner/attendance
        register_rest_route(self::NAMESPACE, '/' . self::ROUTE_PREFIX . '/attendance', [
            'methods' => 'GET',
            'callback' => [$this, 'getAttendance'],
            'permission_callback' => [$this, 'checkPermission'],
        ]);

        // POST /partner/enrollments
        register_rest_route(self::NAMESPACE, '/' . self::ROUTE_PREFIX . '/enrollments', [
            'methods' => 'POST',
            'callback' => [$this, 'createEnrollment'],
            'permission_callback' => [$this, 'checkPermission'],
        ]);
    }

    /**
     * Check if current user is authenticated partner with company_id.
     */
    public function checkPermission(): bool|WP_Error
    {
        $userId = get_current_user_id();

        if (!$userId) {
            return new WP_Error(
                'rest_not_logged_in',
                __('Authentication required.', 'stride'),
                ['status' => 401]
            );
        }

        $user = get_userdata($userId);
        if (!$user || !in_array('partner', $user->roles, true)) {
            return new WP_Error(
                'rest_forbidden',
                __('Partner role required.', 'stride'),
                ['status' => 403]
            );
        }

        $companyId = $this->getCompanyId();
        if (!$companyId) {
            return new WP_Error(
                'rest_forbidden',
                __('Partner account not configured.', 'stride'),
                ['status' => 403]
            );
        }

        return true;
    }

    /**
     * Get company_id from current user's meta.
     */
    private function getCompanyId(): int
    {
        return (int) get_user_meta(get_current_user_id(), '_stride_company_id', true);
    }

    /**
     * GET /partner/users - List users belonging to partner's company.
     */
    public function getUsers(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $companyId = $this->getCompanyId();
        $page = max(1, absint($request->get_param('page') ?? 1));
        $perPage = min(100, max(1, absint($request->get_param('per_page') ?? 20)));

        $args = [
            'meta_key' => '_stride_company_id',
            'meta_value' => $companyId,
            'number' => $perPage,
            'paged' => $page,
            'orderby' => 'registered',
            'order' => 'DESC',
        ];

        $query = new \WP_User_Query($args);
        $users = $query->get_results();
        $total = $query->get_total();

        $data = array_map(function ($user) {
            return [
                'id' => $user->ID,
                'email' => $user->user_email,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'registered_at' => gmdate('c', strtotime($user->user_registered)),
            ];
        }, $users);

        return new WP_REST_Response([
            'data' => $data,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
        ]);
    }

    /**
     * GET /partner/enrollments - List enrollments for partner's users.
     */
    public function getEnrollments(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $companyId = $this->getCompanyId();

        $filters = [
            'status' => $request->get_param('status'),
            'edition_id' => $request->get_param('edition_id'),
            'user_id' => $request->get_param('user_id'),
            'page' => max(1, absint($request->get_param('page') ?? 1)),
            'per_page' => min(100, max(1, absint($request->get_param('per_page') ?? 20))),
        ];

        // If user_id provided, verify it belongs to partner's company
        if (!empty($filters['user_id'])) {
            $userCompanyId = (int) get_user_meta($filters['user_id'], '_stride_company_id', true);
            if ($userCompanyId !== $companyId) {
                return new WP_Error(
                    'rest_forbidden',
                    __('User does not belong to your company.', 'stride'),
                    ['status' => 403]
                );
            }
        }

        $result = $this->registrationRepository->findByCompany($companyId, $filters);

        $rows = $result['data'];

        // Collect IDs for batch fetching
        $userIds = array_unique(array_map(fn($r) => (int) $r->user_id, $rows));
        $editionIds = array_filter(array_unique(array_map(fn($r) => (int) ($r->edition_id ?? 0), $rows)));

        // Batch-fetch users
        $users = [];
        if (!empty($userIds)) {
            $userQuery = new \WP_User_Query(['include' => $userIds, 'fields' => ['ID', 'user_email']]);
            foreach ($userQuery->get_results() as $u) {
                $users[(int) $u->ID] = $u;
            }
        }

        // Batch-fetch editions + their course IDs
        $editions = [];
        $editionCourseMap = [];
        $courseIds = [];
        if (!empty($editionIds)) {
            $editionPosts = get_posts([
                'post_type' => 'vad_edition',
                'post__in' => $editionIds,
                'posts_per_page' => count($editionIds),
                'post_status' => 'any',
            ]);
            foreach ($editionPosts as $ep) {
                $editions[$ep->ID] = $ep;
            }

            // Batch-fetch course IDs from meta
            global $wpdb;
            $editionIdList = implode(',', array_map('intval', $editionIds));
            $metaRows = $wpdb->get_results(
                "SELECT post_id, meta_value FROM {$wpdb->postmeta}
                 WHERE post_id IN ({$editionIdList}) AND meta_key = '_ntdst_course_id'"
            );
            foreach ($metaRows as $mr) {
                $cid = (int) $mr->meta_value;
                $editionCourseMap[(int) $mr->post_id] = $cid;
                if ($cid) {
                    $courseIds[] = $cid;
                }
            }
        }

        // Batch-fetch courses
        $courses = [];
        $courseIds = array_unique(array_filter($courseIds));
        if (!empty($courseIds)) {
            $coursePosts = get_posts([
                'post_type' => 'sfwd-courses',
                'post__in' => $courseIds,
                'posts_per_page' => count($courseIds),
                'post_status' => 'any',
            ]);
            foreach ($coursePosts as $cp) {
                $courses[$cp->ID] = $cp;
            }
        }

        // Map results, skip orphaned registrations (deleted users)
        $data = array_filter(array_map(function ($row) use ($users, $editions, $editionCourseMap, $courses) {
            if (!isset($users[(int) $row->user_id])) {
                return null;
            }

            $editionId = $row->edition_id ? (int) $row->edition_id : 0;
            $courseId = $editionCourseMap[$editionId] ?? 0;

            return [
                'id' => (int) $row->id,
                'user_id' => (int) $row->user_id,
                'user_email' => ($users[(int) $row->user_id] ?? null)?->user_email,
                'edition_id' => $editionId ?: null,
                'edition_title' => ($editions[$editionId] ?? null)?->post_title,
                'course_title' => ($courses[$courseId] ?? null)?->post_title,
                'trajectory_id' => $row->trajectory_id ? (int) $row->trajectory_id : null,
                'status' => $row->status,
                'registered_at' => $row->registered_at ? gmdate('c', strtotime($row->registered_at)) : null,
                'completed_at' => $row->completed_at ? gmdate('c', strtotime($row->completed_at)) : null,
            ];
        }, $rows));

        return new WP_REST_Response([
            'data' => array_values($data),
            'total' => $result['total'],
            'page' => $filters['page'],
            'per_page' => $filters['per_page'],
        ]);
    }

    /**
     * GET /partner/enrollments/{id} - Get single enrollment details.
     */
    public function getEnrollment(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $id = (int) $request->get_param('id');
        $companyId = $this->getCompanyId();

        $registration = $this->registrationRepository->find($id);

        if (!$registration) {
            return new WP_Error(
                'rest_not_found',
                __('Enrollment not found.', 'stride'),
                ['status' => 404]
            );
        }

        // Verify enrollment belongs to partner's company
        if ((int) $registration->company_id !== $companyId) {
            return new WP_Error(
                'rest_forbidden',
                __('Access denied.', 'stride'),
                ['status' => 403]
            );
        }

        $user = get_userdata($registration->user_id);
        $editionId = $registration->edition_id ? (int) $registration->edition_id : 0;
        $edition = $editionId ? $this->editionService->getEdition($editionId) : null;
        $editionIsValid = $edition instanceof \WP_Post;
        $courseId = $editionIsValid ? $this->editionService->getCourseId($editionId) : 0;
        $course = $courseId ? get_post($courseId) : null;

        return new WP_REST_Response([
            'id' => (int) $registration->id,
            'user_id' => (int) $registration->user_id,
            'user_email' => $user ? $user->user_email : null,
            'user_name' => $user ? trim($user->first_name . ' ' . $user->last_name) : null,
            'edition_id' => $registration->edition_id ? (int) $registration->edition_id : null,
            'edition_title' => $editionIsValid ? $edition->post_title : null,
            'course_title' => $course ? $course->post_title : null,
            'trajectory_id' => $registration->trajectory_id ? (int) $registration->trajectory_id : null,
            'status' => $registration->status,
            'enrollment_path' => $registration->enrollment_path,
            'registered_at' => $registration->registered_at ? gmdate('c', strtotime($registration->registered_at)) : null,
            'completed_at' => $registration->completed_at ? gmdate('c', strtotime($registration->completed_at)) : null,
            'notes' => $registration->notes,
        ]);
    }

    /**
     * GET /partner/certificates - List certificates for partner's users.
     */
    public function getCertificates(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $companyId = $this->getCompanyId();
        $page = max(1, absint($request->get_param('page') ?? 1));
        $perPage = min(100, max(1, absint($request->get_param('per_page') ?? 20)));

        // Get company users
        $userQuery = new \WP_User_Query([
            'meta_key' => '_stride_company_id',
            'meta_value' => $companyId,
            'fields' => 'ID',
        ]);
        $userIds = $userQuery->get_results();

        if (empty($userIds)) {
            return new WP_REST_Response([
                'data' => [],
                'total' => 0,
                'page' => $page,
                'per_page' => $perPage,
            ]);
        }

        $certificates = [];

        // Query LearnDash activity table directly for completed courses
        global $wpdb;
        $userIdList = implode(',', array_map('intval', $userIds));
        $completions = $wpdb->get_results(
            "SELECT user_id, post_id AS course_id, activity_completed AS completed_at
             FROM {$wpdb->prefix}learndash_user_activity
             WHERE user_id IN ({$userIdList})
               AND activity_type = 'course'
               AND activity_completed > 0
             ORDER BY activity_completed DESC"
        );

        if (empty($completions)) {
            return new WP_REST_Response([
                'data' => [],
                'total' => 0,
                'page' => $page,
                'per_page' => $perPage,
            ]);
        }

        // DB-level pagination
        $total = count($completions);
        $offset = ($page - 1) * $perPage;
        $pageResults = array_slice($completions, $offset, $perPage);

        // Batch-fetch users and courses for this page only
        $pageUserIds = array_unique(array_column($pageResults, 'user_id'));
        $pageCourseIds = array_unique(array_column($pageResults, 'course_id'));

        $users = [];
        if (!empty($pageUserIds)) {
            $userQuery = new \WP_User_Query(['include' => $pageUserIds, 'fields' => ['ID', 'user_email']]);
            foreach ($userQuery->get_results() as $u) {
                $users[(int) $u->ID] = $u;
            }
        }

        $coursePosts = [];
        if (!empty($pageCourseIds)) {
            $posts = get_posts([
                'post_type' => 'sfwd-courses',
                'post__in' => array_map('intval', $pageCourseIds),
                'posts_per_page' => count($pageCourseIds),
                'post_status' => 'any',
            ]);
            foreach ($posts as $p) {
                $coursePosts[$p->ID] = $p;
            }
        }

        $data = array_map(function ($row) use ($users, $coursePosts) {
            $userId = (int) $row->user_id;
            $courseId = (int) $row->course_id;

            return [
                'user_id' => $userId,
                'user_email' => ($users[$userId] ?? null)?->user_email,
                'course_id' => $courseId,
                'course_title' => ($coursePosts[$courseId] ?? null)?->post_title,
                'completed_at' => gmdate('c', (int) $row->completed_at),
                'certificate_url' => function_exists('learndash_get_course_certificate_link')
                    ? (learndash_get_course_certificate_link($courseId, $userId) ?: null)
                    : null,
            ];
        }, $pageResults);

        return new WP_REST_Response([
            'data' => $data,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
        ]);
    }

    /**
     * GET /partner/attendance - Get attendance records for partner's users.
     */
    public function getAttendance(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $companyId = $this->getCompanyId();
        $editionId = $request->get_param('edition_id');
        $userId = $request->get_param('user_id');
        $page = max(1, absint($request->get_param('page') ?? 1));
        $perPage = min(100, max(1, absint($request->get_param('per_page') ?? 50)));

        // Get company user IDs
        $userQuery = new \WP_User_Query([
            'meta_key' => '_stride_company_id',
            'meta_value' => $companyId,
            'fields' => 'ID',
        ]);
        $companyUserIds = array_map('intval', $userQuery->get_results());

        if (empty($companyUserIds)) {
            return new WP_REST_Response(['data' => [], 'total' => 0, 'page' => $page, 'per_page' => $perPage]);
        }

        // If user_id provided, verify belongs to company
        if ($userId) {
            if (!in_array((int) $userId, $companyUserIds, true)) {
                return new WP_Error('rest_forbidden', __('User does not belong to your company.', 'stride'), ['status' => 403]);
            }
            $companyUserIds = [(int) $userId];
        }

        // Batch-fetch attendance records
        $records = $this->attendanceRepository->getByUsers($companyUserIds, $editionId ? (int) $editionId : null);

        // Batch-fetch sessions
        $sessionIds = array_unique(array_column($records, 'session_id'));
        $sessionsMap = [];
        foreach ($sessionIds as $sid) {
            $session = ntdst_data()->get('vad_session')->find((int) $sid);
            if ($session) {
                $sessionsMap[(int) $sid] = $session;
            }
        }

        // Paginate
        $total = count($records);
        $offset = ($page - 1) * $perPage;
        $pageRecords = array_slice($records, $offset, $perPage);

        $attendance = array_map(function ($record) use ($sessionsMap) {
            $session = $sessionsMap[(int) $record->session_id] ?? null;
            $sessionHours = 0;
            if ($session) {
                $startTime = $session->fields['start_time'] ?? '';
                $endTime = $session->fields['end_time'] ?? '';
                if ($startTime && $endTime) {
                    $start = strtotime($startTime);
                    $end = strtotime($endTime);
                    if ($start && $end && $end > $start) {
                        $sessionHours = ($end - $start) / 3600;
                    }
                }
            }

            return [
                'user_id' => (int) $record->user_id,
                'session_id' => (int) $record->session_id,
                'session_date' => $session ? ($session->fields['date'] ?? null) : null,
                'session_title' => $session ? ($session->post_title ?? null) : null,
                'status' => $record->status,
                'hours' => round($sessionHours, 2),
            ];
        }, $pageRecords);

        return new WP_REST_Response([
            'data' => $attendance,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
        ]);
    }

    /**
     * POST /partner/enrollments - Enroll a user in an edition or trajectory.
     */
    public function createEnrollment(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $companyId = $this->getCompanyId();

        $userEmail = sanitize_email($request->get_param('user_email') ?? '');
        $editionId = absint($request->get_param('edition_id') ?? 0);
        $trajectoryId = absint($request->get_param('trajectory_id') ?? 0);
        $createUser = (bool) $request->get_param('create_user');

        // Validate required fields
        if (empty($userEmail)) {
            return new WP_Error('invalid_request', __('user_email required.', 'stride'), ['status' => 400]);
        }

        if (!$editionId && !$trajectoryId) {
            return new WP_Error('invalid_request', __('edition_id or trajectory_id required.', 'stride'), ['status' => 400]);
        }

        // Validate edition exists
        if ($editionId) {
            $edition = $this->editionService->getEdition($editionId);
            if (is_wp_error($edition)) {
                return new WP_Error('not_found', __('Edition not found.', 'stride'), ['status' => 404]);
            }
        }

        // Find or create user
        $user = get_user_by('email', $userEmail);

        if (!$user && $createUser) {
            $userId = wp_create_user($userEmail, wp_generate_password(), $userEmail);
            if (is_wp_error($userId)) {
                return new WP_Error('user_creation_failed', $userId->get_error_message(), ['status' => 400]);
            }
            update_user_meta($userId, '_stride_company_id', $companyId);
            $user = get_userdata($userId);

            ntdst_log('partner-api')->info('Created user via Partner API', [
                'user_id' => $userId,
                'email' => $userEmail,
                'company_id' => $companyId,
            ]);
        }

        if (!$user) {
            return new WP_Error('user_not_found', __('User not found.', 'stride'), ['status' => 404]);
        }

        // Verify user belongs to partner's company — never auto-assign
        $userCompanyId = (int) get_user_meta($user->ID, '_stride_company_id', true);
        if (!$userCompanyId) {
            return new WP_Error(
                'user_not_affiliated',
                __('User is not affiliated with any company. Contact an administrator.', 'stride'),
                ['status' => 422]
            );
        }
        if ($userCompanyId !== $companyId) {
            return new WP_Error('forbidden', __('User belongs to another company.', 'stride'), ['status' => 403]);
        }

        // Route through validated enrollment paths for capacity, duplicate, and status checks
        if ($editionId) {
            $enrollmentService = ntdst_get(\Stride\Modules\Enrollment\EnrollmentService::class);
            $result = $enrollmentService->enroll($user->ID, $editionId, [
                'company_id' => $companyId,
                'enrollment_path' => \Stride\Modules\Enrollment\RegistrationRepository::PATH_PARTNER,
                'notes' => sprintf('Enrolled via Partner API by user #%d', get_current_user_id()),
            ]);
        } elseif ($trajectoryId) {
            $selectionService = ntdst_get(\Stride\Modules\Trajectory\TrajectorySelection::class);
            $result = $selectionService->enroll($user->ID, $trajectoryId, [
                'company_id' => $companyId,
            ]);
        } else {
            return new WP_Error('invalid_input', __('Either edition_id or trajectory_id is required.', 'stride'), ['status' => 400]);
        }

        if (is_wp_error($result)) {
            $statusMap = [
                'already_enrolled' => 409,
                'edition_full' => 409,
                'enrollment_closed' => 422,
                'invalid_edition' => 404,
            ];
            $status = $statusMap[$result->get_error_code()] ?? 400;
            return new WP_Error($result->get_error_code(), $result->get_error_message(), ['status' => $status]);
        }

        ntdst_log('partner-api')->info('Enrollment created via Partner API', [
            'registration_id' => $result,
            'user_id' => $user->ID,
            'edition_id' => $editionId,
            'trajectory_id' => $trajectoryId,
            'company_id' => $companyId,
        ]);

        // Fetch actual registration to return real status
        $repo = ntdst_get(\Stride\Modules\Enrollment\RegistrationRepository::class);
        $registration = $repo?->find($result);

        return new WP_REST_Response([
            'id' => $result,
            'user_id' => $user->ID,
            'edition_id' => $editionId ?: null,
            'trajectory_id' => $trajectoryId ?: null,
            'status' => $registration?->status ?? 'confirmed',
            'registered_at' => $registration?->registered_at ? gmdate('c', strtotime($registration->registered_at)) : gmdate('c'),
        ], 201);
    }
}
