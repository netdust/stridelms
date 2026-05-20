<?php

declare(strict_types=1);

namespace Stride\Modules\PartnerAPI;

use Stride\Modules\Enrollment\RegistrationRepository;
use Stride\Modules\Attendance\AttendanceRepository;
use Stride\Modules\Edition\EditionRepository;
use Stride\Modules\Edition\SessionRepository;
use Stride\Modules\User\CompanyAffiliation;
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
        private readonly EditionRepository $editionRepository,
        private readonly SessionRepository $sessionRepository,
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
        return CompanyAffiliation::getCompanyId(get_current_user_id());
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
            'meta_key' => CompanyAffiliation::META_KEY,
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
            $userCompanyId = CompanyAffiliation::getCompanyId((int) $filters['user_id']);
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

        // Trajectory parents (trajectory_id set, no edition_id) need their
        // cascade children nested under `child_registrations` so partners
        // see one card per enrollment instead of N+1.
        $parentRegistrationIds = array_values(array_filter(
            array_map(fn($r) => (int) $r->id, $rows),
            fn($id) => $id > 0
        ));
        $trajectoryParentIds = array_values(array_filter(
            array_map(fn($r) => !empty($r->trajectory_id) && empty($r->edition_id) ? (int) $r->id : 0, $rows),
            fn($id) => $id > 0
        ));
        $childrenByParent = !empty($trajectoryParentIds)
            ? $this->registrationRepository->findByParents($trajectoryParentIds)
            : [];

        // Collect IDs for batch fetching — include both top-level rows and any
        // nested children so we can format both in one batch lookup.
        $userIds = array_unique(array_map(fn($r) => (int) $r->user_id, $rows));
        $editionIds = array_filter(array_unique(array_map(fn($r) => (int) ($r->edition_id ?? 0), $rows)));
        foreach ($childrenByParent as $children) {
            foreach ($children as $child) {
                if (!empty($child->edition_id)) {
                    $editionIds[] = (int) $child->edition_id;
                }
            }
        }
        $editionIds = array_values(array_unique(array_filter($editionIds)));

        // Batch-fetch users
        $users = [];
        if (!empty($userIds)) {
            $userQuery = new \WP_User_Query(['include' => $userIds, 'fields' => ['ID', 'user_email']]);
            foreach ($userQuery->get_results() as $u) {
                $users[(int) $u->ID] = $u;
            }
        }

        // Batch-fetch editions + their course IDs via the repository
        $editions = [];
        $editionCourseMap = [];
        $courseIds = [];
        if (!empty($editionIds)) {
            $editions = $this->editionRepository->findManyById($editionIds);
            $editionCourseMap = $this->editionRepository->findCourseIdsForEditions($editionIds);
            $courseIds = array_values($editionCourseMap);
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
        $data = array_filter(array_map(function ($row) use ($users, $editions, $editionCourseMap, $courses, $childrenByParent) {
            if (!isset($users[(int) $row->user_id])) {
                return null;
            }

            $entry = $this->formatEnrollmentRow($row, $users, $editions, $editionCourseMap, $courses);

            // Trajectory parent → nest children.
            if (!empty($row->trajectory_id) && empty($row->edition_id)) {
                $children = $childrenByParent[(int) $row->id] ?? [];
                $entry['child_registrations'] = array_map(
                    fn($child) => $this->formatEnrollmentRow($child, $users, $editions, $editionCourseMap, $courses),
                    $children
                );
            }

            return $entry;
        }, $rows));

        return new WP_REST_Response([
            'data' => array_values($data),
            'total' => $result['total'],
            'page' => $filters['page'],
            'per_page' => $filters['per_page'],
        ]);
    }

    /**
     * Shape a registration row for API output. Used by both list and detail
     * endpoints, and for nested child rows inside trajectory parents.
     *
     * @param array<int, \WP_User> $users
     * @param array<int, \WP_Post> $editions
     * @param array<int, int> $editionCourseMap
     * @param array<int, \WP_Post> $courses
     * @return array<string, mixed>
     */
    private function formatEnrollmentRow(
        object $row,
        array $users,
        array $editions,
        array $editionCourseMap,
        array $courses
    ): array {
        $editionId = !empty($row->edition_id) ? (int) $row->edition_id : 0;
        $courseId = $editionCourseMap[$editionId] ?? 0;

        return [
            'id' => (int) $row->id,
            'user_id' => (int) $row->user_id,
            'user_email' => ($users[(int) $row->user_id] ?? null)?->user_email,
            'edition_id' => $editionId ?: null,
            'edition_title' => ($editions[$editionId] ?? null)?->post_title,
            'course_title' => ($courses[$courseId] ?? null)?->post_title,
            'trajectory_id' => !empty($row->trajectory_id) ? (int) $row->trajectory_id : null,
            'parent_registration_id' => !empty($row->parent_registration_id) ? (int) $row->parent_registration_id : null,
            'status' => $row->status,
            'registered_at' => $row->registered_at ? gmdate('c', strtotime($row->registered_at)) : null,
            'completed_at' => $row->completed_at ? gmdate('c', strtotime($row->completed_at)) : null,
        ];
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
        $edition = $editionId ? $this->editionRepository->find($editionId) : null;
        $editionIsValid = $edition instanceof \WP_Post;
        $courseId = $editionIsValid
            ? (int) ($this->editionRepository->getField($editionId, 'course_id') ?: 0)
            : 0;
        $course = $courseId ? get_post($courseId) : null;

        $response = [
            'id' => (int) $registration->id,
            'user_id' => (int) $registration->user_id,
            'user_email' => $user ? $user->user_email : null,
            'user_name' => $user ? trim($user->first_name . ' ' . $user->last_name) : null,
            'edition_id' => $registration->edition_id ? (int) $registration->edition_id : null,
            'edition_title' => $editionIsValid ? $edition->post_title : null,
            'course_title' => $course ? $course->post_title : null,
            'trajectory_id' => $registration->trajectory_id ? (int) $registration->trajectory_id : null,
            'parent_registration_id' => !empty($registration->parent_registration_id) ? (int) $registration->parent_registration_id : null,
            'status' => $registration->status,
            'enrollment_path' => $registration->enrollment_path,
            'registered_at' => $registration->registered_at ? gmdate('c', strtotime($registration->registered_at)) : null,
            'completed_at' => $registration->completed_at ? gmdate('c', strtotime($registration->completed_at)) : null,
            'notes' => $registration->notes,
        ];

        // Trajectory parent → nest children with edition/course info.
        if (!empty($registration->trajectory_id) && empty($registration->edition_id)) {
            $children = $this->registrationRepository->findByParents([(int) $registration->id])[$registration->id] ?? [];

            $childEditionIds = array_values(array_unique(array_filter(array_map(
                fn($c) => (int) ($c->edition_id ?? 0),
                $children
            ))));
            $childEditions = !empty($childEditionIds) ? $this->editionRepository->findManyById($childEditionIds) : [];
            $childCourseMap = !empty($childEditionIds) ? $this->editionRepository->findCourseIdsForEditions($childEditionIds) : [];
            $childCourseIds = array_values(array_unique(array_filter($childCourseMap)));
            $childCourses = [];
            if (!empty($childCourseIds)) {
                $coursePosts = get_posts([
                    'post_type' => 'sfwd-courses',
                    'post__in' => $childCourseIds,
                    'posts_per_page' => count($childCourseIds),
                    'post_status' => 'any',
                ]);
                foreach ($coursePosts as $cp) {
                    $childCourses[$cp->ID] = $cp;
                }
            }
            $users = $user ? [(int) $user->ID => $user] : [];

            $response['child_registrations'] = array_map(
                fn($child) => $this->formatEnrollmentRow($child, $users, $childEditions, $childCourseMap, $childCourses),
                $children
            );
        }

        return new WP_REST_Response($response);
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
            'meta_key' => CompanyAffiliation::META_KEY,
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

        // Query LearnDash activity table with SQL-level pagination.
        global $wpdb;
        $userIdList = implode(',', array_map('intval', $userIds));
        $offset = ($page - 1) * $perPage;

        $total = (int) $wpdb->get_var(
            "SELECT COUNT(*)
             FROM {$wpdb->prefix}learndash_user_activity
             WHERE user_id IN ({$userIdList})
               AND activity_type = 'course'
               AND activity_completed > 0"
        );

        if ($total === 0) {
            return new WP_REST_Response([
                'data' => [],
                'total' => 0,
                'page' => $page,
                'per_page' => $perPage,
            ]);
        }

        $pageResults = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT user_id, post_id AS course_id, activity_completed AS completed_at
                 FROM {$wpdb->prefix}learndash_user_activity
                 WHERE user_id IN ({$userIdList})
                   AND activity_type = 'course'
                   AND activity_completed > 0
                 ORDER BY activity_completed DESC
                 LIMIT %d OFFSET %d",
                $perPage,
                $offset
            )
        );

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
            'meta_key' => CompanyAffiliation::META_KEY,
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

        // Batch-fetch sessions via the repository
        $sessionIds = array_unique(array_column($records, 'session_id'));
        $sessionsMap = [];
        foreach ($sessionIds as $sid) {
            $session = $this->sessionRepository->find((int) $sid);
            if (!is_wp_error($session)) {
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
            $edition = $this->editionRepository->find($editionId);
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
            CompanyAffiliation::setCompanyId($userId, $companyId);
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
        $userCompanyId = CompanyAffiliation::getCompanyId((int) $user->ID);
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
