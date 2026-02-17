<?php

declare(strict_types=1);

namespace Stride\Admin;

use Stride\Domain\AttendanceStatus;
use Stride\Domain\QuoteStatus;
use Stride\Infrastructure\AbstractService;
use Stride\Modules\Attendance\AttendanceRepository;
use Stride\Modules\Attendance\AttendanceTable;
use Stride\Modules\Edition\EditionCPT;
use Stride\Modules\Edition\SessionCPT;
use Stride\Modules\Enrollment\RegistrationTable;
use Stride\Modules\Invoicing\QuoteCPT;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * REST API endpoints for admin dashboard.
 */
final class AdminAPIController extends AbstractService
{
    private const NAMESPACE = 'stride/v1';

    private AttendanceRepository $attendance;

    public function __construct()
    {
        $this->attendance = new AttendanceRepository();
        parent::__construct();
    }

    public static function metadata(): array
    {
        return [
            'name' => 'Admin API Controller',
            'description' => 'REST API for admin dashboard',
            'admin_only' => true,
            'priority' => 6,
        ];
    }

    protected function getConfigSlug(): string
    {
        return 'admin_api';
    }

    protected function init(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        // Dashboard stats
        register_rest_route(self::NAMESPACE, '/admin/stats', [
            'methods' => 'GET',
            'callback' => [$this, 'getStats'],
            'permission_callback' => [$this, 'canAccessAdmin'],
        ]);

        // Editions list
        register_rest_route(self::NAMESPACE, '/admin/editions', [
            'methods' => 'GET',
            'callback' => [$this, 'getEditions'],
            'permission_callback' => [$this, 'canAccessAdmin'],
            'args' => [
                'page' => [
                    'type' => 'integer',
                    'default' => 1,
                    'minimum' => 1,
                ],
                'per_page' => [
                    'type' => 'integer',
                    'default' => 20,
                    'minimum' => 1,
                    'maximum' => 100,
                ],
                'search' => [
                    'type' => 'string',
                    'default' => '',
                ],
                'status' => [
                    'type' => 'string',
                    'default' => '',
                ],
            ],
        ]);

        // Edition detail
        register_rest_route(self::NAMESPACE, '/admin/editions/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [$this, 'getEdition'],
            'permission_callback' => [$this, 'canAccessAdmin'],
            'args' => [
                'id' => [
                    'type' => 'integer',
                    'required' => true,
                ],
            ],
        ]);

        // Edition registrations
        register_rest_route(self::NAMESPACE, '/admin/editions/(?P<id>\d+)/registrations', [
            'methods' => 'GET',
            'callback' => [$this, 'getEditionRegistrations'],
            'permission_callback' => [$this, 'canAccessAdmin'],
            'args' => [
                'id' => [
                    'type' => 'integer',
                    'required' => true,
                ],
            ],
        ]);

        // Mark attendance
        register_rest_route(self::NAMESPACE, '/admin/attendance', [
            'methods' => 'POST',
            'callback' => [$this, 'markAttendance'],
            'permission_callback' => [$this, 'canAccessAdmin'],
            'args' => [
                'session_id' => [
                    'type' => 'integer',
                    'required' => true,
                ],
                'user_id' => [
                    'type' => 'integer',
                    'required' => true,
                ],
                'status' => [
                    'type' => 'string',
                    'required' => false,
                    'enum' => ['present', 'absent', 'excused', ''],
                ],
            ],
        ]);

        // Quotes list
        register_rest_route(self::NAMESPACE, '/admin/quotes', [
            'methods' => 'GET',
            'callback' => [$this, 'getQuotes'],
            'permission_callback' => [$this, 'canAccessAdmin'],
            'args' => [
                'page' => [
                    'type' => 'integer',
                    'default' => 1,
                    'minimum' => 1,
                ],
                'per_page' => [
                    'type' => 'integer',
                    'default' => 20,
                    'minimum' => 1,
                    'maximum' => 100,
                ],
                'search' => [
                    'type' => 'string',
                    'default' => '',
                ],
                'status' => [
                    'type' => 'string',
                    'default' => '',
                ],
            ],
        ]);
    }

    /**
     * Permission callback for admin endpoints.
     */
    public function canAccessAdmin(): bool
    {
        return current_user_can('edit_others_posts');
    }

    /**
     * GET /admin/stats
     *
     * Dashboard statistics.
     */
    public function getStats(WP_REST_Request $request): WP_REST_Response
    {
        global $wpdb;

        $today = current_time('Y-m-d');

        // Upcoming editions count
        $upcomingEditions = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = %s
             WHERE p.post_type = %s AND p.post_status = 'publish'
             AND pm.meta_value >= %s",
            '_vad_start_date',
            EditionCPT::POST_TYPE,
            $today
        ));

        // Total active registrations
        $registrationTable = RegistrationTable::getTableName();
        $totalRegistrations = 0;
        if (RegistrationTable::exists()) {
            $totalRegistrations = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$registrationTable} WHERE status = 'confirmed'"
            );
        }

        // Pending quotes (draft status)
        $pendingQuotes = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = %s
             WHERE p.post_type = %s AND p.post_status = 'publish'
             AND pm.meta_value = %s",
            '_vad_status',
            QuoteCPT::POST_TYPE,
            QuoteStatus::Draft->value
        ));

        // Sessions today
        $todaySessions = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = %s
             WHERE p.post_type = %s AND p.post_status = 'publish'
             AND pm.meta_value = %s",
            '_vad_date',
            SessionCPT::POST_TYPE,
            $today
        ));

        return new WP_REST_Response([
            'upcomingEditions' => $upcomingEditions,
            'totalRegistrations' => $totalRegistrations,
            'pendingQuotes' => $pendingQuotes,
            'todaySessions' => $todaySessions,
        ]);
    }

    /**
     * GET /admin/editions
     *
     * List editions with pagination, search, and status filtering.
     */
    public function getEditions(WP_REST_Request $request): WP_REST_Response
    {
        global $wpdb;

        $page = $request->get_param('page');
        $perPage = $request->get_param('per_page');
        $search = sanitize_text_field($request->get_param('search') ?? '');
        $status = sanitize_text_field($request->get_param('status') ?? '');
        $offset = ($page - 1) * $perPage;

        // Build query
        $where = ["p.post_type = %s", "p.post_status = 'publish'"];
        $params = [EditionCPT::POST_TYPE];

        if (!empty($search)) {
            $where[] = "p.post_title LIKE %s";
            $params[] = '%' . $wpdb->esc_like($search) . '%';
        }

        if (!empty($status)) {
            $where[] = "EXISTS (SELECT 1 FROM {$wpdb->postmeta} pm_status WHERE pm_status.post_id = p.ID AND pm_status.meta_key = '_vad_status' AND pm_status.meta_value = %s)";
            $params[] = $status;
        }

        $whereClause = implode(' AND ', $where);

        // Get total count
        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} p WHERE {$whereClause}",
            ...$params
        ));

        // Get editions
        $params[] = $perPage;
        $params[] = $offset;

        $editions = $wpdb->get_results($wpdb->prepare(
            "SELECT p.ID, p.post_title FROM {$wpdb->posts} p
             WHERE {$whereClause}
             ORDER BY p.post_date DESC
             LIMIT %d OFFSET %d",
            ...$params
        ));

        // Format editions with meta
        $items = [];
        $registrationTable = RegistrationTable::getTableName();

        foreach ($editions as $edition) {
            $editionId = (int) $edition->ID;

            // Get meta values
            $startDate = get_post_meta($editionId, '_vad_start_date', true);
            $endDate = get_post_meta($editionId, '_vad_end_date', true);
            $venue = get_post_meta($editionId, '_vad_venue', true);
            $capacity = (int) get_post_meta($editionId, '_vad_capacity', true);
            $editionStatus = get_post_meta($editionId, '_vad_status', true);
            $courseId = (int) get_post_meta($editionId, '_vad_course_id', true);

            // Get course title
            $courseTitle = '';
            if ($courseId > 0) {
                $course = get_post($courseId);
                if ($course) {
                    $courseTitle = $course->post_title;
                }
            }

            // Count registrations
            $registeredCount = 0;
            if (RegistrationTable::exists()) {
                $registeredCount = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$registrationTable} WHERE edition_id = %d AND status = 'confirmed'",
                    $editionId
                ));
            }

            $items[] = [
                'id' => $editionId,
                'title' => $edition->post_title,
                'course' => [
                    'id' => $courseId,
                    'title' => $courseTitle,
                ],
                'startDate' => $startDate ?: null,
                'endDate' => $endDate ?: null,
                'venue' => $venue ?: null,
                'capacity' => $capacity,
                'registeredCount' => $registeredCount,
                'status' => $editionStatus ?: 'open',
                'editUrl' => admin_url("post.php?post={$editionId}&action=edit"),
            ];
        }

        return new WP_REST_Response([
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'totalPages' => (int) ceil($total / $perPage),
        ]);
    }

    /**
     * GET /admin/editions/{id}
     *
     * Single edition detail with sessions.
     */
    public function getEdition(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        global $wpdb;

        $editionId = (int) $request->get_param('id');

        // Get edition
        $edition = get_post($editionId);
        if (!$edition || $edition->post_type !== EditionCPT::POST_TYPE) {
            return new WP_Error('not_found', 'Edition not found', ['status' => 404]);
        }

        // Get meta values
        $startDate = get_post_meta($editionId, '_vad_start_date', true);
        $endDate = get_post_meta($editionId, '_vad_end_date', true);
        $venue = get_post_meta($editionId, '_vad_venue', true);
        $capacity = (int) get_post_meta($editionId, '_vad_capacity', true);
        $editionStatus = get_post_meta($editionId, '_vad_status', true);
        $courseId = (int) get_post_meta($editionId, '_vad_course_id', true);
        $price = (int) get_post_meta($editionId, '_vad_price', true);
        $priceNonMember = (int) get_post_meta($editionId, '_vad_price_non_member', true);
        $speakers = get_post_meta($editionId, '_vad_speakers', true);

        // Get course title
        $courseTitle = '';
        if ($courseId > 0) {
            $course = get_post($courseId);
            if ($course) {
                $courseTitle = $course->post_title;
            }
        }

        // Count registrations
        $registeredCount = 0;
        $registrationTable = RegistrationTable::getTableName();
        if (RegistrationTable::exists()) {
            $registeredCount = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$registrationTable} WHERE edition_id = %d AND status = 'confirmed'",
                $editionId
            ));
        }

        // Get sessions
        $sessions = $wpdb->get_results($wpdb->prepare(
            "SELECT p.ID, p.post_title FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_vad_edition_id'
             WHERE p.post_type = %s AND p.post_status = 'publish' AND pm.meta_value = %d
             ORDER BY p.ID ASC",
            SessionCPT::POST_TYPE,
            $editionId
        ));

        $sessionItems = [];
        foreach ($sessions as $session) {
            $sessionId = (int) $session->ID;
            $sessionDate = get_post_meta($sessionId, '_vad_date', true);
            $startTime = get_post_meta($sessionId, '_vad_start_time', true);
            $endTime = get_post_meta($sessionId, '_vad_end_time', true);
            $sessionType = get_post_meta($sessionId, '_vad_type', true);

            $sessionItems[] = [
                'id' => $sessionId,
                'title' => $session->post_title,
                'date' => $sessionDate ?: null,
                'startTime' => $startTime ?: null,
                'endTime' => $endTime ?: null,
                'type' => $sessionType ?: 'default',
            ];
        }

        return new WP_REST_Response([
            'id' => $editionId,
            'title' => $edition->post_title,
            'course' => [
                'id' => $courseId,
                'title' => $courseTitle,
            ],
            'startDate' => $startDate ?: null,
            'endDate' => $endDate ?: null,
            'venue' => $venue ?: null,
            'capacity' => $capacity,
            'registeredCount' => $registeredCount,
            'status' => $editionStatus ?: 'open',
            'price' => $price,
            'priceNonMember' => $priceNonMember,
            'speakers' => $speakers ?: null,
            'sessions' => $sessionItems,
            'editUrl' => admin_url("post.php?post={$editionId}&action=edit"),
        ]);
    }

    /**
     * GET /admin/editions/{id}/registrations
     *
     * Edition registrations with user info and attendance status.
     */
    public function getEditionRegistrations(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        global $wpdb;

        $editionId = (int) $request->get_param('id');

        // Verify edition exists
        $edition = get_post($editionId);
        if (!$edition || $edition->post_type !== EditionCPT::POST_TYPE) {
            return new WP_Error('not_found', 'Edition not found', ['status' => 404]);
        }

        // Check tables exist
        if (!RegistrationTable::exists()) {
            return new WP_REST_Response([
                'items' => [],
                'sessions' => [],
            ]);
        }

        // Get sessions for this edition
        $sessions = $wpdb->get_results($wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_vad_edition_id'
             WHERE p.post_type = %s AND p.post_status = 'publish' AND pm.meta_value = %d
             ORDER BY p.ID ASC",
            SessionCPT::POST_TYPE,
            $editionId
        ));

        $sessionIds = array_map(fn($s) => (int) $s->ID, $sessions);

        $sessionItems = [];
        foreach ($sessionIds as $sessionId) {
            $sessionDate = get_post_meta($sessionId, '_vad_date', true);
            $startTime = get_post_meta($sessionId, '_vad_start_time', true);

            $sessionItems[] = [
                'id' => $sessionId,
                'date' => $sessionDate ?: null,
                'startTime' => $startTime ?: null,
            ];
        }

        // Get registrations
        $registrationTable = RegistrationTable::getTableName();
        $registrations = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$registrationTable} WHERE edition_id = %d ORDER BY registered_at ASC",
            $editionId
        ));

        // Get attendance records if table exists
        $attendanceByUser = [];
        if (AttendanceTable::exists()) {
            $attendanceTable = AttendanceTable::getTableName();
            $attendanceRecords = $wpdb->get_results($wpdb->prepare(
                "SELECT user_id, session_id, status FROM {$attendanceTable} WHERE edition_id = %d",
                $editionId
            ));

            foreach ($attendanceRecords as $record) {
                $userId = (int) $record->user_id;
                $sessionId = (int) $record->session_id;

                if (!isset($attendanceByUser[$userId])) {
                    $attendanceByUser[$userId] = [];
                }
                $attendanceByUser[$userId][$sessionId] = $record->status;
            }
        }

        // Format registrations
        $items = [];
        foreach ($registrations as $reg) {
            $userId = (int) $reg->user_id;
            $user = get_userdata($userId);

            if (!$user) {
                continue;
            }

            // Build attendance map for this user
            $attendance = [];
            foreach ($sessionIds as $sessionId) {
                $attendance[$sessionId] = $attendanceByUser[$userId][$sessionId] ?? null;
            }

            $items[] = [
                'id' => (int) $reg->id,
                'user' => [
                    'id' => $userId,
                    'name' => $user->display_name,
                    'email' => $user->user_email,
                ],
                'status' => $reg->status,
                'enrollmentPath' => $reg->enrollment_path,
                'registeredAt' => $reg->registered_at,
                'attendance' => $attendance,
            ];
        }

        return new WP_REST_Response([
            'items' => $items,
            'sessions' => $sessionItems,
        ]);
    }

    /**
     * POST /admin/attendance
     *
     * Mark attendance for a user at a session.
     */
    public function markAttendance(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $sessionId = (int) $request->get_param('session_id');
        $userId = (int) $request->get_param('user_id');
        $statusValue = $request->get_param('status');

        // Verify session exists
        $session = get_post($sessionId);
        if (!$session || $session->post_type !== SessionCPT::POST_TYPE) {
            return new WP_Error('invalid_session', 'Session not found', ['status' => 404]);
        }

        // Verify user exists
        $user = get_userdata($userId);
        if (!$user) {
            return new WP_Error('invalid_user', 'User not found', ['status' => 404]);
        }

        // Handle clearing attendance (empty status)
        if (empty($statusValue)) {
            // Delete attendance record
            global $wpdb;
            $attendanceTable = AttendanceTable::getTableName();

            if (AttendanceTable::exists()) {
                $existing = $this->attendance->findBySessionAndUser($sessionId, $userId);
                if ($existing) {
                    $this->attendance->delete((int) $existing->id);
                }
            }

            return new WP_REST_Response([
                'success' => true,
                'sessionId' => $sessionId,
                'userId' => $userId,
                'status' => null,
            ]);
        }

        // Validate status
        $status = AttendanceStatus::tryFrom($statusValue);
        if ($status === null) {
            return new WP_Error('invalid_status', 'Invalid attendance status', ['status' => 400]);
        }

        // Record attendance
        $currentUserId = get_current_user_id();
        $result = $this->attendance->record($sessionId, $userId, $status, null, $currentUserId);

        if (is_wp_error($result)) {
            return $result;
        }

        return new WP_REST_Response([
            'success' => true,
            'attendanceId' => $result,
            'sessionId' => $sessionId,
            'userId' => $userId,
            'status' => $status->value,
        ]);
    }

    /**
     * GET /admin/quotes
     *
     * List quotes with pagination, search, and status filtering.
     */
    public function getQuotes(WP_REST_Request $request): WP_REST_Response
    {
        global $wpdb;

        $page = $request->get_param('page');
        $perPage = $request->get_param('per_page');
        $search = sanitize_text_field($request->get_param('search') ?? '');
        $status = sanitize_text_field($request->get_param('status') ?? '');
        $offset = ($page - 1) * $perPage;

        // Build query
        $where = ["p.post_type = %s", "p.post_status = 'publish'"];
        $params = [QuoteCPT::POST_TYPE];

        if (!empty($search)) {
            // Search in title or quote_number meta
            $where[] = "(p.post_title LIKE %s OR EXISTS (SELECT 1 FROM {$wpdb->postmeta} pm_search WHERE pm_search.post_id = p.ID AND pm_search.meta_key = '_vad_quote_number' AND pm_search.meta_value LIKE %s))";
            $searchPattern = '%' . $wpdb->esc_like($search) . '%';
            $params[] = $searchPattern;
            $params[] = $searchPattern;
        }

        if (!empty($status)) {
            $where[] = "EXISTS (SELECT 1 FROM {$wpdb->postmeta} pm_status WHERE pm_status.post_id = p.ID AND pm_status.meta_key = '_vad_status' AND pm_status.meta_value = %s)";
            $params[] = $status;
        }

        $whereClause = implode(' AND ', $where);

        // Get total count
        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} p WHERE {$whereClause}",
            ...$params
        ));

        // Get quotes
        $params[] = $perPage;
        $params[] = $offset;

        $quotes = $wpdb->get_results($wpdb->prepare(
            "SELECT p.ID, p.post_title, p.post_date FROM {$wpdb->posts} p
             WHERE {$whereClause}
             ORDER BY p.post_date DESC
             LIMIT %d OFFSET %d",
            ...$params
        ));

        // Format quotes with meta
        $items = [];
        foreach ($quotes as $quote) {
            $quoteId = (int) $quote->ID;

            // Get meta values
            $quoteNumber = get_post_meta($quoteId, '_vad_quote_number', true);
            $quoteStatus = get_post_meta($quoteId, '_vad_status', true);
            $quoteTotal = (int) get_post_meta($quoteId, '_vad_total', true);
            $userId = (int) get_post_meta($quoteId, '_vad_user_id', true);
            $editionId = (int) get_post_meta($quoteId, '_vad_edition_id', true);
            $sentAt = get_post_meta($quoteId, '_vad_sent_at', true);

            // Get user info
            $userName = '';
            $userEmail = '';
            if ($userId > 0) {
                $user = get_userdata($userId);
                if ($user) {
                    $userName = $user->display_name;
                    $userEmail = $user->user_email;
                }
            }

            // Get edition info
            $editionTitle = '';
            if ($editionId > 0) {
                $edition = get_post($editionId);
                if ($edition) {
                    $editionTitle = $edition->post_title;
                }
            }

            // Get status label
            $statusEnum = QuoteStatus::tryFrom($quoteStatus);
            $statusLabel = $statusEnum?->label() ?? $quoteStatus;

            $items[] = [
                'id' => $quoteId,
                'number' => $quoteNumber ?: null,
                'status' => $quoteStatus ?: 'draft',
                'statusLabel' => $statusLabel,
                'total' => $quoteTotal,
                'totalFormatted' => number_format($quoteTotal / 100, 2, ',', '.'),
                'date' => $quote->post_date,
                'sentAt' => $sentAt ?: null,
                'user' => [
                    'id' => $userId,
                    'name' => $userName,
                    'email' => $userEmail,
                ],
                'edition' => [
                    'id' => $editionId,
                    'title' => $editionTitle,
                ],
                'editUrl' => admin_url("post.php?post={$quoteId}&action=edit"),
            ];
        }

        return new WP_REST_Response([
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'totalPages' => (int) ceil($total / $perPage),
        ]);
    }
}
