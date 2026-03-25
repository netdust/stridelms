<?php

declare(strict_types=1);

namespace Stride\Admin;

use NTDST\Audit\AuditTable;
use Stride\Domain\AttendanceStatus;
use Stride\Domain\QuoteStatus;
use Stride\Infrastructure\BatchQueryHelper;
use Stride\Modules\Attendance\AttendanceRepository;
use Stride\Modules\Attendance\AttendanceTable;
use Stride\Modules\Edition\EditionCPT;
use Stride\Modules\Edition\EditionRepository;
use Stride\Modules\Edition\SessionCPT;
use Stride\Modules\Edition\SessionRepository;
use Stride\Modules\Enrollment\RegistrationTable;
use Stride\Modules\Invoicing\QuoteCPT;
use Stride\Modules\Trajectory\TrajectoryCPT;
use Stride\Modules\User\ProfileTypeService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * REST API endpoints for admin dashboard.
 *
 * Plain class — owned by AdminDashboardService.
 */
final class AdminAPIController
{
    private const NAMESPACE = 'stride/v1';

    public function __construct(
        private readonly AttendanceRepository $attendance,
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
        // Dashboard stats
        register_rest_route(self::NAMESPACE, '/admin/stats', [
            'methods' => 'GET',
            'callback' => [$this, 'getStats'],
            'permission_callback' => [$this, 'canViewAdmin'],
        ]);

        // Editions list
        register_rest_route(self::NAMESPACE, '/admin/editions', [
            'methods' => 'GET',
            'callback' => [$this, 'getEditions'],
            'permission_callback' => [$this, 'canViewAdmin'],
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
                'date_from' => [
                    'type' => 'string',
                    'default' => '',
                ],
                'date_to' => [
                    'type' => 'string',
                    'default' => '',
                ],
                'course_tag' => [
                    'type' => 'integer',
                    'default' => 0,
                ],
                'view' => [
                    'type' => 'string',
                    'default' => 'agenda',
                    'enum' => ['agenda', 'list'],
                ],
            ],
        ]);

        // Edition detail
        register_rest_route(self::NAMESPACE, '/admin/editions/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [$this, 'getEdition'],
            'permission_callback' => [$this, 'canViewAdmin'],
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
            'permission_callback' => [$this, 'canViewAdmin'],
            'args' => [
                'id' => [
                    'type' => 'integer',
                    'required' => true,
                ],
            ],
        ]);

        // Course tags for filter
        register_rest_route(self::NAMESPACE, '/admin/course-tags', [
            'methods' => 'GET',
            'callback' => [$this, 'getCourseTags'],
            'permission_callback' => [$this, 'canViewAdmin'],
        ]);

        // Mark attendance
        register_rest_route(self::NAMESPACE, '/admin/attendance', [
            'methods' => 'POST',
            'callback' => [$this, 'markAttendance'],
            'permission_callback' => [$this, 'canManageAdmin'],
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
            'permission_callback' => [$this, 'canViewAdmin'],
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
                'edition_id' => [
                    'type' => 'integer',
                    'default' => 0,
                ],
            ],
        ]);

        // Trajectories list
        register_rest_route(self::NAMESPACE, '/admin/trajectories', [
            'methods' => 'GET',
            'callback' => [$this, 'getTrajectories'],
            'permission_callback' => [$this, 'canViewAdmin'],
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

        // Trajectory detail
        register_rest_route(self::NAMESPACE, '/admin/trajectories/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [$this, 'getTrajectory'],
            'permission_callback' => [$this, 'canViewAdmin'],
            'args' => [
                'id' => [
                    'type' => 'integer',
                    'required' => true,
                ],
            ],
        ]);

        // Pending approvals
        register_rest_route(self::NAMESPACE, '/admin/pending-approvals', [
            'methods' => 'GET',
            'callback' => [$this, 'getPendingApprovals'],
            'permission_callback' => [$this, 'canViewAdmin'],
        ]);

        // Approve registration (enrollment phase)
        register_rest_route(self::NAMESPACE, '/admin/approve-registration', [
            'methods' => 'POST',
            'callback' => [$this, 'approveRegistration'],
            'permission_callback' => [$this, 'canManageAdmin'],
            'args' => [
                'registration_id' => [
                    'type' => 'integer',
                    'required' => true,
                ],
            ],
        ]);

        // Approve post-course (aftekenen)
        register_rest_route(self::NAMESPACE, '/admin/approve-post-course', [
            'methods' => 'POST',
            'callback' => [$this, 'approvePostCourse'],
            'permission_callback' => [$this, 'canManageAdmin'],
            'args' => [
                'registration_id' => [
                    'type' => 'integer',
                    'required' => true,
                ],
            ],
        ]);

        // Action queue
        register_rest_route(self::NAMESPACE, '/admin/action-queue', [
            'methods' => 'GET',
            'callback' => [$this, 'getActionQueue'],
            'permission_callback' => [$this, 'canViewAdmin'],
        ]);

        // Dismiss action queue item
        register_rest_route(self::NAMESPACE, '/admin/action-queue/dismiss', [
            'methods' => 'POST',
            'callback' => [$this, 'dismissActionItem'],
            'permission_callback' => [$this, 'canViewAdmin'],
            'args' => [
                'rule' => ['type' => 'string', 'required' => true],
                'subject_id' => ['type' => 'integer', 'default' => 0],
            ],
        ]);

        // Health checks
        register_rest_route(self::NAMESPACE, '/admin/health-checks', [
            'methods' => 'GET',
            'callback' => [$this, 'getHealthChecks'],
            'permission_callback' => [$this, 'canViewAdmin'],
        ]);

        // Activity feed
        register_rest_route(self::NAMESPACE, '/admin/activity', [
            'methods' => 'GET',
            'callback' => [$this, 'getActivityFeed'],
            'permission_callback' => [$this, 'canViewAdmin'],
            'args' => [
                'limit' => ['type' => 'integer', 'default' => 10, 'maximum' => 50],
            ],
        ]);

        // User search
        register_rest_route(self::NAMESPACE, '/admin/users/search', [
            'methods' => 'GET',
            'callback' => [$this, 'searchUsers'],
            'permission_callback' => [$this, 'canViewAdmin'],
            'args' => [
                'q' => ['type' => 'string', 'required' => true, 'minLength' => 2],
            ],
        ]);

        // User detail
        register_rest_route(self::NAMESPACE, '/admin/users/(?P<id>\d+)/detail', [
            'methods' => 'GET',
            'callback' => [$this, 'getUserDetail'],
            'permission_callback' => [$this, 'canViewAdmin'],
            'args' => [
                'id' => ['type' => 'integer', 'required' => true],
                'reg_page' => ['type' => 'integer', 'default' => 1],
            ],
        ]);

        // Impersonate user
        register_rest_route(self::NAMESPACE, '/admin/users/(?P<id>\d+)/impersonate', [
            'methods' => 'POST',
            'callback' => [$this, 'impersonateUser'],
            'permission_callback' => [$this, 'canManageAdmin'],
            'args' => [
                'id' => ['type' => 'integer', 'required' => true],
            ],
        ]);

        // End impersonation — permission validated internally via cookie+transient
        register_rest_route(self::NAMESPACE, '/admin/impersonate/end', [
            'methods' => ['GET', 'POST'],
            'callback' => [$this, 'endImpersonation'],
            'permission_callback' => function () {
                $handler = new ImpersonationHandler();
                if (!$handler->isActive()) {
                    return false;
                }
                $token = $handler->getTokenFromCookie();
                return $handler->getOriginalAdmin($token) > 0;
            },
        ]);

        // Notifications
        register_rest_route(self::NAMESPACE, '/admin/notifications', [
            'methods' => 'GET',
            'callback' => [$this, 'getNotifications'],
            'permission_callback' => [$this, 'canViewAdmin'],
        ]);

        // Mark notifications read
        register_rest_route(self::NAMESPACE, '/admin/notifications/read', [
            'methods' => 'POST',
            'callback' => [$this, 'markNotificationsRead'],
            'permission_callback' => [$this, 'canViewAdmin'],
        ]);

        // Export registrations as CSV
        register_rest_route(self::NAMESPACE, '/admin/export/registrations', [
            'methods' => 'GET',
            'callback' => [$this, 'exportRegistrations'],
            'permission_callback' => [$this, 'canManageAdmin'],
        ]);
    }

    /**
     * Permission callback for read-only admin endpoints.
     */
    public function canViewAdmin(): bool
    {
        return current_user_can('stride_view');
    }

    /**
     * Permission callback for mutation admin endpoints.
     */
    public function canManageAdmin(): bool
    {
        return current_user_can('stride_manage');
    }

    /**
     * GET /admin/stats
     *
     * Dashboard statistics.
     * Optimized to use batch queries and reduce N+1 patterns.
     */
    public function getStats(WP_REST_Request $request): WP_REST_Response
    {
        global $wpdb;

        $today = current_time('Y-m-d');
        $registrationTable = RegistrationTable::getTableName();
        $registrationTableExists = RegistrationTable::exists();

        // === COUNT QUERIES (single query each, no N+1) ===

        // Upcoming editions count
        $upcomingEditions = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = %s
             WHERE p.post_type = %s AND p.post_status = 'publish'
             AND pm.meta_value >= %s",
            '_ntdst_start_date',
            EditionCPT::POST_TYPE,
            $today
        ));

        // Total active registrations
        $totalRegistrations = 0;
        if ($registrationTableExists) {
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
            'status',
            QuoteCPT::POST_TYPE,
            QuoteStatus::Draft->value
        ));

        // Pending registrations (for actionCount)
        $pendingRegistrations = 0;
        if ($registrationTableExists) {
            $pendingRegistrations = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$registrationTable} WHERE status = 'pending'"
            );
        }

        // Sessions today count
        $todaySessions = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = %s
             WHERE p.post_type = %s AND p.post_status = 'publish'
             AND pm.meta_value = %s",
            '_ntdst_date',
            SessionCPT::POST_TYPE,
            $today
        ));

        // Open trajectories (status = 'open')
        $openTrajectories = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = %s
             WHERE p.post_type = %s AND p.post_status = 'publish'
             AND pm.meta_value = %s",
            '_ntdst_status',
            TrajectoryCPT::POST_TYPE,
            'open'
        ));

        // === TODAY'S SESSIONS WITH DETAILS (batch fetch) ===

        $todaySessionDetails = [];
        $sessions = $wpdb->get_results($wpdb->prepare(
            "SELECT p.ID, p.post_title, pm_time.meta_value as start_time, pm_end.meta_value as end_time,
                    pm_edition.meta_value as edition_id
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm_date ON p.ID = pm_date.post_id AND pm_date.meta_key = '_ntdst_date'
             LEFT JOIN {$wpdb->postmeta} pm_time ON p.ID = pm_time.post_id AND pm_time.meta_key = '_ntdst_start_time'
             LEFT JOIN {$wpdb->postmeta} pm_end ON p.ID = pm_end.post_id AND pm_end.meta_key = '_ntdst_end_time'
             LEFT JOIN {$wpdb->postmeta} pm_edition ON p.ID = pm_edition.post_id AND pm_edition.meta_key = '_ntdst_edition_id'
             WHERE p.post_type = %s AND p.post_status = 'publish'
             AND pm_date.meta_value = %s
             ORDER BY pm_time.meta_value ASC",
            SessionCPT::POST_TYPE,
            $today
        ));

        if (!empty($sessions)) {
            // Collect edition IDs for batch fetch
            $sessionEditionIds = [];
            foreach ($sessions as $session) {
                $editionId = (int) $session->edition_id;
                if ($editionId > 0) {
                    $sessionEditionIds[] = $editionId;
                }
            }

            // Batch fetch editions and registration counts
            $editionsMap = BatchQueryHelper::batchGetPosts($sessionEditionIds, EditionCPT::POST_TYPE);
            $regCountsMap = $registrationTableExists
                ? BatchQueryHelper::batchGetRegistrationCounts($sessionEditionIds)
                : [];

            foreach ($sessions as $session) {
                $editionId = (int) $session->edition_id;
                $edition = $editionsMap[$editionId] ?? null;
                $registeredCount = $regCountsMap[$editionId] ?? 0;

                $todaySessionDetails[] = [
                    'id' => (int) $session->ID,
                    'title' => $session->post_title,
                    'editionTitle' => $edition ? $edition->post_title : '',
                    'startTime' => $session->start_time ?: '',
                    'endTime' => $session->end_time ?: '',
                    'registeredCount' => $registeredCount,
                ];
            }
        }

        // === UPCOMING EDITIONS (next 5, batch fetch registration counts) ===

        $upcomingEditionDetails = [];
        $upcomingList = $wpdb->get_results($wpdb->prepare(
            "SELECT p.ID, p.post_title, pm_date.meta_value as start_date,
                    pm_capacity.meta_value as capacity, pm_status.meta_value as status
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm_date ON p.ID = pm_date.post_id AND pm_date.meta_key = '_ntdst_start_date'
             LEFT JOIN {$wpdb->postmeta} pm_capacity ON p.ID = pm_capacity.post_id AND pm_capacity.meta_key = '_ntdst_capacity'
             LEFT JOIN {$wpdb->postmeta} pm_status ON p.ID = pm_status.post_id AND pm_status.meta_key = '_ntdst_status'
             WHERE p.post_type = %s AND p.post_status = 'publish'
             AND pm_date.meta_value >= %s
             ORDER BY pm_date.meta_value ASC
             LIMIT 5",
            EditionCPT::POST_TYPE,
            $today
        ));

        if (!empty($upcomingList)) {
            // Collect edition IDs for batch fetch
            $upcomingEditionIds = array_map(fn($ed) => (int) $ed->ID, $upcomingList);

            // Batch fetch registration counts
            $upcomingRegCounts = $registrationTableExists
                ? BatchQueryHelper::batchGetRegistrationCounts($upcomingEditionIds)
                : [];

            foreach ($upcomingList as $ed) {
                $editionId = (int) $ed->ID;
                $capacity = (int) $ed->capacity;
                $registeredCount = $upcomingRegCounts[$editionId] ?? 0;

                $upcomingEditionDetails[] = [
                    'id' => $editionId,
                    'title' => $ed->post_title,
                    'startDate' => $ed->start_date,
                    'status' => $ed->status ?: 'open',
                    'capacity' => $capacity,
                    'registeredCount' => $registeredCount,
                    'spotsLeft' => $capacity > 0 ? max(0, $capacity - $registeredCount) : null,
                ];
            }
        }

        // === RECENT REGISTRATIONS (last 7 days, batch fetch users and editions) ===

        $recentRegistrations = [];
        if ($registrationTableExists) {
            $weekAgo = wp_date('Y-m-d H:i:s', strtotime('-7 days'));
            $recentRegs = $wpdb->get_results($wpdb->prepare(
                "SELECT r.id, r.user_id, r.edition_id, r.status, r.registered_at
                 FROM {$registrationTable} r
                 WHERE r.registered_at >= %s
                 ORDER BY r.registered_at DESC
                 LIMIT 10",
                $weekAgo
            ));

            if (!empty($recentRegs)) {
                // Collect IDs for batch fetch
                $userIds = [];
                $editionIds = [];
                foreach ($recentRegs as $reg) {
                    $userIds[] = (int) $reg->user_id;
                    $editionIds[] = (int) $reg->edition_id;
                }

                // Batch fetch users and editions
                $usersMap = BatchQueryHelper::batchGetUsers($userIds);
                $editionsMap = BatchQueryHelper::batchGetPosts($editionIds, EditionCPT::POST_TYPE);

                foreach ($recentRegs as $reg) {
                    $userId = (int) $reg->user_id;
                    $editionId = (int) $reg->edition_id;
                    $user = $usersMap[$userId] ?? null;
                    $edition = $editionsMap[$editionId] ?? null;

                    $recentRegistrations[] = [
                        'id' => (int) $reg->id,
                        'userName' => $user ? $user->display_name : 'Unknown',
                        'userEmail' => $user ? $user->user_email : '',
                        'editionTitle' => $edition ? $edition->post_title : 'Unknown',
                        'status' => $reg->status,
                        'createdAt' => $reg->registered_at,
                    ];
                }
            }
        }

        // === REGISTRATIONS THIS WEEK VS LAST WEEK (single queries) ===

        $thisWeekStart = wp_date('Y-m-d', strtotime('monday this week'));
        $lastWeekStart = wp_date('Y-m-d', strtotime('monday last week'));
        $registrationsThisWeek = 0;
        $registrationsLastWeek = 0;

        if ($registrationTableExists) {
            $registrationsThisWeek = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$registrationTable} WHERE registered_at >= %s",
                $thisWeekStart
            ));
            $registrationsLastWeek = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$registrationTable} WHERE registered_at >= %s AND registered_at < %s",
                $lastWeekStart,
                $thisWeekStart
            ));
        }

        // === ALERTS (batch fetch registration counts) ===

        $alerts = [];
        $twoWeeksFromNow = wp_date('Y-m-d', strtotime('+14 days'));
        $alertEditions = $wpdb->get_results($wpdb->prepare(
            "SELECT p.ID, p.post_title, pm_date.meta_value as start_date,
                    pm_capacity.meta_value as capacity
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm_date ON p.ID = pm_date.post_id AND pm_date.meta_key = '_ntdst_start_date'
             LEFT JOIN {$wpdb->postmeta} pm_capacity ON p.ID = pm_capacity.post_id AND pm_capacity.meta_key = '_ntdst_capacity'
             WHERE p.post_type = %s AND p.post_status = 'publish'
             AND pm_date.meta_value >= %s AND pm_date.meta_value <= %s
             ORDER BY pm_date.meta_value ASC",
            EditionCPT::POST_TYPE,
            $today,
            $twoWeeksFromNow
        ));

        if (!empty($alertEditions)) {
            // Filter to only editions with capacity > 0, collect IDs
            $alertEditionIds = [];
            $alertEditionsFiltered = [];
            foreach ($alertEditions as $ed) {
                $capacity = (int) $ed->capacity;
                if ($capacity > 0) {
                    $alertEditionIds[] = (int) $ed->ID;
                    $alertEditionsFiltered[] = $ed;
                }
            }

            // Batch fetch registration counts
            $alertRegCounts = $registrationTableExists
                ? BatchQueryHelper::batchGetRegistrationCounts($alertEditionIds)
                : [];

            foreach ($alertEditionsFiltered as $ed) {
                $editionId = (int) $ed->ID;
                $capacity = (int) $ed->capacity;
                $registeredCount = $alertRegCounts[$editionId] ?? 0;
                $fillRate = ($registeredCount / $capacity) * 100;

                if ($fillRate >= 80) {
                    $alerts[] = [
                        'type' => 'almost_full',
                        'editionId' => $editionId,
                        'editionTitle' => $ed->post_title,
                        'startDate' => $ed->start_date,
                        'message' => sprintf('%d/%d plaatsen bezet', $registeredCount, $capacity),
                        'fillRate' => (int) round($fillRate),
                    ];
                } elseif ($fillRate < 30) {
                    $alerts[] = [
                        'type' => 'low_registration',
                        'editionId' => $editionId,
                        'editionTitle' => $ed->post_title,
                        'startDate' => $ed->start_date,
                        'message' => sprintf('Slechts %d/%d inschrijvingen', $registeredCount, $capacity),
                        'fillRate' => (int) round($fillRate),
                    ];
                }
            }
        }

        return new WP_REST_Response([
            'upcomingEditions' => $upcomingEditions,
            'totalRegistrations' => $totalRegistrations,
            'pendingQuotes' => $pendingQuotes,
            'todaySessions' => $todaySessions,
            'openTrajectories' => $openTrajectories,
            'actionCount' => $pendingRegistrations + $pendingQuotes,
            // Dashboard detail data
            'todaySessionDetails' => $todaySessionDetails,
            'upcomingEditionDetails' => $upcomingEditionDetails,
            'recentRegistrations' => $recentRegistrations,
            'registrationsThisWeek' => $registrationsThisWeek,
            'registrationsLastWeek' => $registrationsLastWeek,
            'alerts' => $alerts,
        ]);
    }

    /**
     * GET /admin/editions
     *
     * List editions with pagination, search, and status filtering.
     * Supports two views:
     * - 'agenda' (default): Shows each session date as a row (calendar view)
     * - 'list': Shows each edition as a row (collapsed view)
     */
    public function getEditions(WP_REST_Request $request): WP_REST_Response
    {
        global $wpdb;

        $view = $request->get_param('view') ?? 'agenda';
        $page = $request->get_param('page');
        $perPage = $request->get_param('per_page');
        $search = sanitize_text_field($request->get_param('search') ?? '');
        $status = sanitize_text_field($request->get_param('status') ?? '');
        $dateFrom = sanitize_text_field($request->get_param('date_from') ?? '');
        $dateTo = sanitize_text_field($request->get_param('date_to') ?? '');
        $courseTag = (int) $request->get_param('course_tag');
        $offset = ($page - 1) * $perPage;

        $today = current_time('Y-m-d');
        $twoDaysAgo = wp_date('Y-m-d', strtotime('-2 days'));

        if ($view === 'agenda') {
            return $this->getEditionsAgendaView($request, $today, $twoDaysAgo);
        }

        // LIST VIEW: One row per edition
        // Build query with JOIN on start_date meta
        $where = ["p.post_type = %s", "p.post_status = 'publish'"];
        $params = [EditionCPT::POST_TYPE];

        // By default, only show editions that haven't passed more than 2 days ago
        if (empty($dateFrom)) {
            $where[] = "pm_start.meta_value >= %s";
            $params[] = $twoDaysAgo;
        }

        if (!empty($search)) {
            $where[] = "p.post_title LIKE %s";
            $params[] = '%' . $wpdb->esc_like($search) . '%';
        }

        if (!empty($status)) {
            $where[] = "EXISTS (SELECT 1 FROM {$wpdb->postmeta} pm_status WHERE pm_status.post_id = p.ID AND pm_status.meta_key = '_ntdst_status' AND pm_status.meta_value = %s)";
            $params[] = $status;
        }

        // Date range filter
        if (!empty($dateFrom)) {
            $where[] = "pm_start.meta_value >= %s";
            $params[] = $dateFrom;
        }
        if (!empty($dateTo)) {
            $where[] = "pm_start.meta_value <= %s";
            $params[] = $dateTo;
        }

        // Course tag filter (via linked course)
        $tagJoin = '';
        if ($courseTag > 0) {
            $tagJoin = "INNER JOIN {$wpdb->postmeta} pm_course ON p.ID = pm_course.post_id AND pm_course.meta_key = '_ntdst_course_id'
                        INNER JOIN {$wpdb->term_relationships} tr ON pm_course.meta_value = tr.object_id
                        INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id AND tt.taxonomy = 'ld_course_tag'";
            $where[] = "tt.term_id = %d";
            $params[] = $courseTag;
        }

        $whereClause = implode(' AND ', $where);

        // Get total count
        $countParams = $params;
        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm_start ON p.ID = pm_start.post_id AND pm_start.meta_key = '_ntdst_start_date'
             {$tagJoin}
             WHERE {$whereClause}",
            ...$countParams
        ));

        // Get editions - ordered by start date ASC (nearest first)
        $params[] = $perPage;
        $params[] = $offset;

        $editions = $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT p.ID, p.post_title, pm_start.meta_value as start_date
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm_start ON p.ID = pm_start.post_id AND pm_start.meta_key = '_ntdst_start_date'
             {$tagJoin}
             WHERE {$whereClause}
             ORDER BY pm_start.meta_value ASC
             LIMIT %d OFFSET %d",
            ...$params
        ));

        // Format editions with meta
        $items = [];

        // Batch fetch all data upfront
        $editionIds = array_map(fn($e) => (int) $e->ID, $editions);

        // Batch fetch meta for all editions
        $editionMeta = BatchQueryHelper::batchGetPostMeta($editionIds, [
            '_ntdst_start_date', '_ntdst_end_date', '_ntdst_venue', '_ntdst_capacity', '_ntdst_status', '_ntdst_course_id',
        ]);

        // Batch fetch registration counts
        $regCounts = RegistrationTable::exists()
            ? BatchQueryHelper::batchGetRegistrationCounts($editionIds)
            : [];

        // Batch fetch course data
        $courseIds = array_filter(array_map(fn($id) => (int) ($editionMeta[$id]['_ntdst_course_id'] ?? 0), $editionIds));
        $courses = BatchQueryHelper::batchGetPosts($courseIds, 'sfwd-courses');
        $courseTags = BatchQueryHelper::batchGetCourseTags($courseIds);

        foreach ($editions as $edition) {
            $editionId = (int) $edition->ID;
            $meta = $editionMeta[$editionId] ?? [];

            // Get meta values from batch
            $startDate = $meta['_ntdst_start_date'] ?? '';
            $endDate = $meta['_ntdst_end_date'] ?? '';
            $venue = $meta['_ntdst_venue'] ?? '';
            $capacity = (int) ($meta['_ntdst_capacity'] ?? 0);
            $editionStatus = $meta['_ntdst_status'] ?? '';
            $courseId = (int) ($meta['_ntdst_course_id'] ?? 0);

            // Get course data from batch
            $courseTitle = '';
            $courseTagList = [];
            if ($courseId > 0) {
                $course = $courses[$courseId] ?? null;
                if ($course) {
                    $courseTitle = $course->post_title;
                }
                $courseTagList = $courseTags[$courseId] ?? [];
            }

            // Get registration count from batch
            $registeredCount = $regCounts[$editionId] ?? 0;

            // Check if edition is today
            $isToday = $startDate === $today || ($startDate <= $today && $endDate >= $today);
            $isPast = !empty($endDate) ? $endDate < $today : $startDate < $today;

            $items[] = [
                'id' => $editionId,
                'title' => $edition->post_title,
                'course' => [
                    'id' => $courseId,
                    'title' => $courseTitle,
                    'tags' => $courseTagList,
                ],
                'startDate' => $startDate ?: null,
                'endDate' => $endDate ?: null,
                'venue' => $venue ?: null,
                'capacity' => $capacity,
                'registeredCount' => $registeredCount,
                'status' => $editionStatus ?: 'open',
                'isToday' => $isToday,
                'isPast' => $isPast,
                'editUrl' => admin_url("post.php?post={$editionId}&action=edit"),
            ];
        }

        return new WP_REST_Response([
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'totalPages' => (int) ceil($total / $perPage),
            'view' => 'list',
        ]);
    }

    /**
     * Agenda view: Each session date is a row.
     */
    private function getEditionsAgendaView(WP_REST_Request $request, string $today, string $twoDaysAgo): WP_REST_Response
    {
        global $wpdb;

        $page = $request->get_param('page');
        $perPage = $request->get_param('per_page');
        $search = sanitize_text_field($request->get_param('search') ?? '');
        $status = sanitize_text_field($request->get_param('status') ?? '');
        $dateFrom = sanitize_text_field($request->get_param('date_from') ?? '');
        $dateTo = sanitize_text_field($request->get_param('date_to') ?? '');
        $courseTag = (int) $request->get_param('course_tag');
        $offset = ($page - 1) * $perPage;

        // Build query for sessions with edition info
        $where = [
            "s.post_type = %s",
            "s.post_status = 'publish'",
            "e.post_type = %s",
            "e.post_status = 'publish'",
        ];
        $params = [SessionCPT::POST_TYPE, EditionCPT::POST_TYPE];

        // Default: only show sessions from 2 days ago onwards
        if (empty($dateFrom)) {
            $where[] = "pm_date.meta_value >= %s";
            $params[] = $twoDaysAgo;
        }

        // Search by edition title
        if (!empty($search)) {
            $where[] = "e.post_title LIKE %s";
            $params[] = '%' . $wpdb->esc_like($search) . '%';
        }

        // Filter by edition status
        if (!empty($status)) {
            $where[] = "EXISTS (SELECT 1 FROM {$wpdb->postmeta} pm_status WHERE pm_status.post_id = e.ID AND pm_status.meta_key = '_ntdst_status' AND pm_status.meta_value = %s)";
            $params[] = $status;
        }

        // Date range filter on session date
        if (!empty($dateFrom)) {
            $where[] = "pm_date.meta_value >= %s";
            $params[] = $dateFrom;
        }
        if (!empty($dateTo)) {
            $where[] = "pm_date.meta_value <= %s";
            $params[] = $dateTo;
        }

        // Course tag filter
        $tagJoin = '';
        if ($courseTag > 0) {
            $tagJoin = "INNER JOIN {$wpdb->postmeta} pm_course ON e.ID = pm_course.post_id AND pm_course.meta_key = '_ntdst_course_id'
                        INNER JOIN {$wpdb->term_relationships} tr ON pm_course.meta_value = tr.object_id
                        INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id AND tt.taxonomy = 'ld_course_tag'";
            $where[] = "tt.term_id = %d";
            $params[] = $courseTag;
        }

        $whereClause = implode(' AND ', $where);

        // Count total sessions
        $countParams = $params;
        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT s.ID)
             FROM {$wpdb->posts} s
             INNER JOIN {$wpdb->postmeta} pm_edition ON s.ID = pm_edition.post_id AND pm_edition.meta_key = '_ntdst_edition_id'
             INNER JOIN {$wpdb->posts} e ON pm_edition.meta_value = e.ID
             INNER JOIN {$wpdb->postmeta} pm_date ON s.ID = pm_date.post_id AND pm_date.meta_key = '_ntdst_date'
             {$tagJoin}
             WHERE {$whereClause}",
            ...$countParams
        ));

        // Get sessions ordered by date
        $params[] = $perPage;
        $params[] = $offset;

        $sessions = $wpdb->get_results($wpdb->prepare(
            "SELECT s.ID as session_id, s.post_title as session_title,
                    e.ID as edition_id, e.post_title as edition_title,
                    pm_date.meta_value as session_date
             FROM {$wpdb->posts} s
             INNER JOIN {$wpdb->postmeta} pm_edition ON s.ID = pm_edition.post_id AND pm_edition.meta_key = '_ntdst_edition_id'
             INNER JOIN {$wpdb->posts} e ON pm_edition.meta_value = e.ID
             INNER JOIN {$wpdb->postmeta} pm_date ON s.ID = pm_date.post_id AND pm_date.meta_key = '_ntdst_date'
             {$tagJoin}
             WHERE {$whereClause}
             ORDER BY pm_date.meta_value ASC, pm_edition.meta_value ASC
             LIMIT %d OFFSET %d",
            ...$params
        ));

        // Format items
        $items = [];

        // Batch fetch all data upfront
        $sessionIds = array_map(fn($s) => (int) $s->session_id, $sessions);
        $editionIds = array_unique(array_map(fn($s) => (int) $s->edition_id, $sessions));

        // Batch fetch session meta
        $sessionMeta = BatchQueryHelper::batchGetPostMeta($sessionIds, [
            '_ntdst_start_time', '_ntdst_end_time', '_ntdst_location',
        ]);

        // Batch fetch edition meta
        $editionMeta = BatchQueryHelper::batchGetPostMeta($editionIds, [
            '_ntdst_venue', '_ntdst_capacity', '_ntdst_status', '_ntdst_course_id',
        ]);

        // Batch fetch registration counts
        $regCounts = RegistrationTable::exists()
            ? BatchQueryHelper::batchGetRegistrationCounts($editionIds)
            : [];

        // Batch fetch course data
        $courseIds = array_filter(array_map(fn($id) => (int) ($editionMeta[$id]['_ntdst_course_id'] ?? 0), $editionIds));
        $courses = BatchQueryHelper::batchGetPosts($courseIds, 'sfwd-courses');

        foreach ($sessions as $session) {
            $sessionId = (int) $session->session_id;
            $editionId = (int) $session->edition_id;
            $sessionDate = $session->session_date;

            // Get session meta from batch
            $sMeta = $sessionMeta[$sessionId] ?? [];
            $startTime = $sMeta['_ntdst_start_time'] ?? '';
            $endTime = $sMeta['_ntdst_end_time'] ?? '';
            $location = $sMeta['_ntdst_location'] ?? '';

            // Get edition meta from batch
            $eMeta = $editionMeta[$editionId] ?? [];
            $venue = $eMeta['_ntdst_venue'] ?? '';
            $capacity = (int) ($eMeta['_ntdst_capacity'] ?? 0);
            $editionStatus = $eMeta['_ntdst_status'] ?? '';
            $courseId = (int) ($eMeta['_ntdst_course_id'] ?? 0);

            // Get course title from batch
            $courseTitle = '';
            if ($courseId > 0) {
                $course = $courses[$courseId] ?? null;
                if ($course) {
                    $courseTitle = $course->post_title;
                }
            }

            // Get registration count from batch
            $registeredCount = $regCounts[$editionId] ?? 0;

            // Check if session is today/past
            $isToday = $sessionDate === $today;
            $isPast = $sessionDate < $today;

            $items[] = [
                'id' => $editionId,
                'sessionId' => $sessionId,
                'title' => $session->edition_title,
                'sessionTitle' => $session->session_title,
                'course' => [
                    'id' => $courseId,
                    'title' => $courseTitle,
                ],
                'date' => $sessionDate,
                'startTime' => $startTime ?: null,
                'endTime' => $endTime ?: null,
                'venue' => $location ?: $venue ?: null,
                'capacity' => $capacity,
                'registeredCount' => $registeredCount,
                'status' => $editionStatus ?: 'open',
                'isToday' => $isToday,
                'isPast' => $isPast,
                'editUrl' => admin_url("post.php?post={$editionId}&action=edit"),
            ];
        }

        return new WP_REST_Response([
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'totalPages' => (int) ceil($total / $perPage),
            'view' => 'agenda',
        ]);
    }

    /**
     * GET /admin/course-tags
     *
     * Get all course tags for filter dropdown.
     */
    public function getCourseTags(WP_REST_Request $request): WP_REST_Response
    {
        $tags = get_terms([
            'taxonomy' => 'ld_course_tag',
            'hide_empty' => false,
            'orderby' => 'name',
            'order' => 'ASC',
        ]);

        $items = [];
        if (!is_wp_error($tags)) {
            foreach ($tags as $tag) {
                $items[] = [
                    'id' => $tag->term_id,
                    'name' => $tag->name,
                    'count' => $tag->count,
                ];
            }
        }

        return new WP_REST_Response($items);
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

        // Get meta values via repository
        $startDate = $this->editionRepository->getField($editionId, 'start_date', '');
        $endDate = $this->editionRepository->getField($editionId, 'end_date', '');
        $venue = $this->editionRepository->getField($editionId, 'venue', '');
        $capacity = (int) $this->editionRepository->getField($editionId, 'capacity', 0);
        $editionStatus = $this->editionRepository->getField($editionId, 'status', '');
        $courseId = (int) $this->editionRepository->getField($editionId, 'course_id', 0);
        $price = (int) $this->editionRepository->getField($editionId, 'price', 0);
        $priceNonMember = (int) $this->editionRepository->getField($editionId, 'price_non_member', 0);
        $speakers = $this->editionRepository->getField($editionId, 'speakers', '');

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

        // Get sessions via repository
        $sessions = $this->sessionRepository->findByEdition($editionId);

        $sessionItems = [];
        foreach ($sessions as $session) {
            $sessionItems[] = [
                'id' => (int) $session['id'],
                'title' => $session['post_title'] ?? '',
                'date' => $session['meta']['date'] ?? null,
                'startTime' => $session['meta']['start_time'] ?? null,
                'endTime' => $session['meta']['end_time'] ?? null,
                'type' => $session['meta']['type'] ?? 'default',
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
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_ntdst_edition_id'
             WHERE p.post_type = %s AND p.post_status = 'publish' AND pm.meta_value = %d
             ORDER BY p.ID ASC",
            SessionCPT::POST_TYPE,
            $editionId
        ));

        $sessionIds = array_map(fn($s) => (int) $s->ID, $sessions);

        // Batch fetch session meta
        $sessionMeta = BatchQueryHelper::batchGetPostMeta($sessionIds, ['_ntdst_date', '_ntdst_start_time']);

        $sessionItems = [];
        foreach ($sessionIds as $sessionId) {
            $meta = $sessionMeta[$sessionId] ?? [];
            $sessionItems[] = [
                'id' => $sessionId,
                'date' => $meta['_ntdst_date'] ?: null,
                'startTime' => $meta['_ntdst_start_time'] ?: null,
            ];
        }

        // Get registrations
        $registrationTable = RegistrationTable::getTableName();
        $registrations = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$registrationTable} WHERE edition_id = %d ORDER BY registered_at ASC",
            $editionId
        ));

        // Collect user IDs for batch fetch
        $userIds = array_map(fn($r) => (int) $r->user_id, $registrations);

        // Batch fetch users
        $users = BatchQueryHelper::batchGetUsers($userIds);

        // Get attendance records if table exists (already optimized with batch)
        $attendanceByUser = BatchQueryHelper::batchGetAttendance($editionId);

        // Format registrations with pre-fetched data
        $items = [];
        foreach ($registrations as $reg) {
            $userId = (int) $reg->user_id;
            $user = $users[$userId] ?? null;

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

        // Record attendance via service (fires events for audit + auto-complete)
        $attendanceService = ntdst_get(\Stride\Modules\Attendance\AttendanceService::class);
        $result = match ($status) {
            AttendanceStatus::Present => $attendanceService->markPresent($sessionId, $userId),
            AttendanceStatus::Absent => $attendanceService->markAbsent($sessionId, $userId),
            AttendanceStatus::Excused => $attendanceService->markExcused($sessionId, $userId),
        };

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
        $editionId = (int) $request->get_param('edition_id');
        $offset = ($page - 1) * $perPage;

        // Build query
        $where = ["p.post_type = %s", "p.post_status = 'publish'"];
        $params = [QuoteCPT::POST_TYPE];

        // Search by user name or email
        if (!empty($search)) {
            $searchPattern = '%' . $wpdb->esc_like($search) . '%';
            $where[] = "EXISTS (
                SELECT 1 FROM {$wpdb->postmeta} pm_user
                INNER JOIN {$wpdb->users} u ON u.ID = pm_user.meta_value
                WHERE pm_user.post_id = p.ID
                AND pm_user.meta_key = 'user_id'
                AND (u.display_name LIKE %s OR u.user_email LIKE %s)
            )";
            $params[] = $searchPattern;
            $params[] = $searchPattern;
        }

        // Filter by status
        if (!empty($status)) {
            $where[] = "EXISTS (SELECT 1 FROM {$wpdb->postmeta} pm_status WHERE pm_status.post_id = p.ID AND pm_status.meta_key = 'status' AND pm_status.meta_value = %s)";
            $params[] = $status;
        }

        // Filter by edition (item_id when item_type is edition)
        if ($editionId > 0) {
            $where[] = "EXISTS (SELECT 1 FROM {$wpdb->postmeta} pm_edition WHERE pm_edition.post_id = p.ID AND pm_edition.meta_key = 'edition_id' AND pm_edition.meta_value = %d)";
            $params[] = $editionId;
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

        // Collect quote IDs for batch queries
        $quoteIds = array_map(fn($q) => (int) $q->ID, $quotes);

        // Batch fetch all quote meta
        $quoteMeta = BatchQueryHelper::batchGetPostMeta($quoteIds, [
            'quote_number', 'status', 'total', 'subtotal',
            'tax', 'user_id', 'edition_id', 'sent_at',
            'valid_until', 'items', 'billing',
        ]);

        // Collect unique user IDs and edition IDs for batch fetch
        $userIds = [];
        $editionIds = [];
        foreach ($quoteIds as $quoteId) {
            $userId = (int) ($quoteMeta[$quoteId]['user_id'] ?? 0);
            $editionId = (int) ($quoteMeta[$quoteId]['edition_id'] ?? 0);
            if ($userId > 0) {
                $userIds[] = $userId;
            }
            if ($editionId > 0) {
                $editionIds[] = $editionId;
            }
        }

        // Batch fetch users and editions
        $users = BatchQueryHelper::batchGetUsers(array_unique($userIds));
        $editions = BatchQueryHelper::batchGetPosts(array_unique($editionIds), EditionCPT::POST_TYPE);

        // Format quotes with pre-fetched data
        $items = [];
        foreach ($quotes as $quote) {
            $quoteId = (int) $quote->ID;
            $meta = $quoteMeta[$quoteId] ?? [];

            $quoteNumber = $meta['quote_number'] ?? '';
            $quoteStatus = $meta['status'] ?? 'draft';
            $quoteTotal = (float) ($meta['total'] ?? 0);
            $quoteSubtotal = (float) ($meta['subtotal'] ?? 0);
            $quoteTax = (float) ($meta['tax'] ?? 0);
            $userId = (int) ($meta['user_id'] ?? 0);
            $editionId = (int) ($meta['edition_id'] ?? 0);
            $sentAt = $meta['sent_at'] ?? '';
            $validUntil = $meta['valid_until'] ?? '';
            $quoteItems = $meta['items'] ?? [];
            $billing = $meta['billing'] ?? [];

            // Get user info from batch
            $userName = '';
            $userEmail = '';
            $user = $users[$userId] ?? null;
            if ($user) {
                $userName = $user->display_name;
                $userEmail = $user->user_email;
            }

            // Get edition info from batch
            $editionTitle = '';
            $edition = $editions[$editionId] ?? null;
            if ($edition) {
                $editionTitle = $edition->post_title;
            }

            // Get status label
            $statusEnum = QuoteStatus::tryFrom($quoteStatus);
            $statusLabel = $statusEnum?->label() ?? $quoteStatus;

            $items[] = [
                'id' => $quoteId,
                'number' => $quoteNumber ?: null,
                'status' => $quoteStatus ?: 'draft',
                'statusLabel' => $statusLabel,
                'subtotal' => $quoteSubtotal,
                'tax' => $quoteTax,
                'total' => $quoteTotal,
                'totalFormatted' => number_format($quoteTotal, 2, ',', '.'),
                'date' => $quote->post_date,
                'sentAt' => $sentAt ?: null,
                'validUntil' => $validUntil ?: null,
                'user' => [
                    'id' => $userId,
                    'name' => $userName,
                    'email' => $userEmail,
                ],
                'edition' => [
                    'id' => $editionId,
                    'title' => $editionTitle,
                ],
                'lineItems' => is_array($quoteItems) ? $quoteItems : (json_decode($quoteItems, true) ?: []),
                'billing' => is_array($billing) ? $billing : (json_decode($billing, true) ?: []),
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

    /**
     * GET /admin/trajectories
     *
     * List trajectories with pagination, search, and status filtering.
     * Optimized with batch queries to avoid N+1 patterns.
     */
    public function getTrajectories(WP_REST_Request $request): WP_REST_Response
    {
        global $wpdb;

        $page = $request->get_param('page');
        $perPage = $request->get_param('per_page');
        $search = sanitize_text_field($request->get_param('search') ?? '');
        $status = sanitize_text_field($request->get_param('status') ?? '');
        $offset = ($page - 1) * $perPage;

        // Build query
        $where = ["p.post_type = %s", "p.post_status = 'publish'"];
        $params = [TrajectoryCPT::POST_TYPE];

        if (!empty($search)) {
            $where[] = "p.post_title LIKE %s";
            $params[] = '%' . $wpdb->esc_like($search) . '%';
        }

        if (!empty($status)) {
            $where[] = "EXISTS (SELECT 1 FROM {$wpdb->postmeta} pm_status WHERE pm_status.post_id = p.ID AND pm_status.meta_key = '_ntdst_status' AND pm_status.meta_value = %s)";
            $params[] = $status;
        }

        $whereClause = implode(' AND ', $where);

        // Get total count
        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} p WHERE {$whereClause}",
            ...$params
        ));

        // Get trajectories
        $params[] = $perPage;
        $params[] = $offset;

        $trajectories = $wpdb->get_results($wpdb->prepare(
            "SELECT p.ID, p.post_title, p.post_date, p.post_content FROM {$wpdb->posts} p
             WHERE {$whereClause}
             ORDER BY p.post_date DESC
             LIMIT %d OFFSET %d",
            ...$params
        ));

        if (empty($trajectories)) {
            return new WP_REST_Response([
                'items' => [],
                'total' => $total,
                'page' => $page,
                'perPage' => $perPage,
                'totalPages' => (int) ceil($total / $perPage),
            ]);
        }

        // === BATCH FETCH ALL DATA UPFRONT ===

        $trajectoryIds = array_map(fn($t) => (int) $t->ID, $trajectories);

        // Batch fetch trajectory meta
        $trajectoryMeta = BatchQueryHelper::batchGetPostMeta($trajectoryIds, [
            '_ntdst_status', '_ntdst_mode', '_ntdst_capacity', '_ntdst_enrollment_deadline', '_ntdst_choice_deadline',
            '_ntdst_courses', '_ntdst_price', '_ntdst_price_non_member', '_ntdst_choice_available_date',
        ]);

        // Check if enrollment table exists (once)
        $enrollmentTable = $wpdb->prefix . 'vad_trajectory_enrollments';
        $enrollmentTableExists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $enrollmentTable)) === $enrollmentTable;

        // Collect all edition IDs from courses meta and all enrollments
        $allEditionIds = [];
        $allEnrollments = []; // trajectoryId => enrollments
        $enrollmentCounts = []; // trajectoryId => count

        foreach ($trajectories as $trajectory) {
            $trajectoryId = (int) $trajectory->ID;
            $meta = $trajectoryMeta[$trajectoryId] ?? [];

            // Parse courses to collect edition IDs
            $courses = $meta['_ntdst_courses'] ?? null;
            $courseList = [];
            if (is_array($courses)) {
                $courseList = $courses;
            } elseif (is_string($courses) && !empty($courses)) {
                $decoded = json_decode($courses, true);
                if (is_array($decoded)) {
                    $courseList = $decoded;
                }
            }

            foreach ($courseList as $course) {
                $editionId = (int) ($course['edition_id'] ?? 0);
                if ($editionId > 0) {
                    $allEditionIds[] = $editionId;
                }
            }
        }

        // Batch fetch enrollments for all trajectories
        $allEnrollmentUserIds = [];
        if ($enrollmentTableExists && !empty($trajectoryIds)) {
            $placeholders = implode(',', array_fill(0, count($trajectoryIds), '%d'));

            // Get enrollment counts
            $countResults = $wpdb->get_results($wpdb->prepare(
                "SELECT trajectory_id, COUNT(*) as count FROM {$enrollmentTable}
                 WHERE trajectory_id IN ({$placeholders})
                 GROUP BY trajectory_id",
                ...$trajectoryIds
            ));
            foreach ($countResults as $row) {
                $enrollmentCounts[(int) $row->trajectory_id] = (int) $row->count;
            }

            // Get enrollment details (limited to 50 per trajectory)
            // Use a single query with ROW_NUMBER or just fetch all and limit in PHP
            $enrollmentResults = $wpdb->get_results($wpdb->prepare(
                "SELECT trajectory_id, user_id, status, enrolled_at FROM {$enrollmentTable}
                 WHERE trajectory_id IN ({$placeholders})
                 ORDER BY trajectory_id, enrolled_at DESC",
                ...$trajectoryIds
            ));

            // Group by trajectory and limit to 50 per trajectory
            $enrollmentsByTrajectory = [];
            foreach ($enrollmentResults as $row) {
                $trajectoryId = (int) $row->trajectory_id;
                if (!isset($enrollmentsByTrajectory[$trajectoryId])) {
                    $enrollmentsByTrajectory[$trajectoryId] = [];
                }
                if (count($enrollmentsByTrajectory[$trajectoryId]) < 50) {
                    $enrollmentsByTrajectory[$trajectoryId][] = $row;
                    $allEnrollmentUserIds[] = (int) $row->user_id;
                }
            }
            $allEnrollments = $enrollmentsByTrajectory;
        }

        // Batch fetch editions for courses
        $editionsMap = BatchQueryHelper::batchGetPosts($allEditionIds, EditionCPT::POST_TYPE);

        // Batch fetch users for enrollments
        $usersMap = BatchQueryHelper::batchGetUsers($allEnrollmentUserIds);

        // === FORMAT TRAJECTORIES ===

        $items = [];
        foreach ($trajectories as $trajectory) {
            $trajectoryId = (int) $trajectory->ID;
            $meta = $trajectoryMeta[$trajectoryId] ?? [];

            // Get meta values from batch
            $trajectoryStatus = $meta['_ntdst_status'] ?? '';
            $mode = $meta['_ntdst_mode'] ?? '';
            $capacity = (int) ($meta['_ntdst_capacity'] ?? 0);
            $enrollmentDeadline = $meta['_ntdst_enrollment_deadline'] ?? '';
            $choiceDeadline = $meta['_ntdst_choice_deadline'] ?? '';
            $price = (int) ($meta['_ntdst_price'] ?? 0);
            $priceNonMember = (float) ($meta['_ntdst_price_non_member'] ?? 0);
            $choiceAvailableDate = $meta['_ntdst_choice_available_date'] ?? '';

            // Parse courses
            $courses = $meta['_ntdst_courses'] ?? null;
            $courseList = [];
            if (is_array($courses)) {
                $courseList = $courses;
            } elseif (is_string($courses) && !empty($courses)) {
                $decoded = json_decode($courses, true);
                if (is_array($decoded)) {
                    $courseList = $decoded;
                }
            }
            $courseCount = count($courseList);

            // Enrich courses with edition titles (using batch-fetched data)
            $coursesWithDetails = [];
            foreach ($courseList as $course) {
                $editionId = (int) ($course['edition_id'] ?? 0);
                $courseData = [
                    'editionId' => $editionId,
                    'type' => $course['type'] ?? 'required',
                    'title' => '',
                ];
                if ($editionId > 0) {
                    $edition = $editionsMap[$editionId] ?? null;
                    if ($edition) {
                        $courseData['title'] = $edition->post_title;
                    }
                }
                $coursesWithDetails[] = $courseData;
            }

            // Get enrolled users (using batch-fetched data)
            $enrolledCount = $enrollmentCounts[$trajectoryId] ?? 0;
            $enrolledUsers = [];
            $trajectoryEnrollments = $allEnrollments[$trajectoryId] ?? [];

            foreach ($trajectoryEnrollments as $enrollment) {
                $userId = (int) $enrollment->user_id;
                $user = $usersMap[$userId] ?? null;
                if ($user) {
                    $enrolledUsers[] = [
                        'id' => $userId,
                        'name' => $user->display_name,
                        'email' => $user->user_email,
                        'status' => $enrollment->status,
                        'enrolledAt' => $enrollment->enrolled_at,
                    ];
                }
            }

            // Get description from already fetched post_content
            $description = $trajectory->post_content;

            // Get status label
            $statusLabel = match ($trajectoryStatus) {
                'open' => 'Open',
                'closed' => 'Gesloten',
                'full' => 'Volzet',
                'archived' => 'Gearchiveerd',
                'draft' => 'Concept',
                default => ucfirst($trajectoryStatus ?: 'draft'),
            };

            // Get mode label
            $modeLabel = match ($mode) {
                'cohort' => 'Cohort',
                'open' => 'Open inschrijving',
                default => ucfirst($mode ?: 'cohort'),
            };

            $items[] = [
                'id' => $trajectoryId,
                'title' => $trajectory->post_title,
                'description' => wp_trim_words(wp_strip_all_tags($description), 30, '...'),
                'status' => $trajectoryStatus ?: 'draft',
                'statusLabel' => $statusLabel,
                'mode' => $mode ?: 'cohort',
                'modeLabel' => $modeLabel,
                'capacity' => $capacity,
                'enrolledCount' => $enrolledCount,
                'courseCount' => $courseCount,
                'courses' => $coursesWithDetails,
                'enrolledUsers' => $enrolledUsers,
                'price' => $price,
                'priceFormatted' => number_format($price, 2, ',', '.'),
                'priceNonMember' => $priceNonMember,
                'priceNonMemberFormatted' => number_format($priceNonMember, 2, ',', '.'),
                'enrollmentDeadline' => $enrollmentDeadline ?: null,
                'choiceAvailableDate' => $choiceAvailableDate ?: null,
                'choiceDeadline' => $choiceDeadline ?: null,
                'editUrl' => admin_url("post.php?post={$trajectoryId}&action=edit"),
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
     * GET /admin/trajectories/{id}
     *
     * Single trajectory detail. Reuses the same logic as the list endpoint
     * but returns a single item by fetching the list for that ID.
     */
    public function getTrajectory(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        global $wpdb;

        $trajectoryId = (int) $request->get_param('id');

        $post = get_post($trajectoryId);
        if (!$post || $post->post_type !== TrajectoryCPT::POST_TYPE) {
            return new WP_Error('not_found', 'Trajectory not found', ['status' => 404]);
        }

        // Reuse the list endpoint with a filter that returns just this one
        $listRequest = new WP_REST_Request('GET', '/stride/v1/admin/trajectories');
        $listRequest->set_param('page', 1);
        $listRequest->set_param('per_page', 100);
        $listRequest->set_param('search', '');
        $listRequest->set_param('status', '');

        $listResponse = $this->getTrajectories($listRequest);
        $items = $listResponse->get_data()['items'] ?? [];

        foreach ($items as $item) {
            if ($item['id'] === $trajectoryId) {
                // Remap enrolledUsers to registrations for slide-over template
                $regStatusLabels = [
                    'active' => 'Actief', 'completed' => 'Afgerond',
                    'cancelled' => 'Geannuleerd', 'pending' => 'In afwachting',
                ];
                $registrations = array_map(function (array $u) use ($regStatusLabels) {
                    return [
                        'id' => $u['id'],
                        'name' => $u['name'],
                        'email' => $u['email'],
                        'status' => $u['status'],
                        'status_label' => $regStatusLabels[$u['status']] ?? ucfirst($u['status'] ?? ''),
                    ];
                }, $item['enrolledUsers'] ?? []);

                return new WP_REST_Response(array_merge($item, [
                    'registrations' => $registrations,
                ]));
            }
        }

        return new WP_Error('not_found', 'Trajectory not found', ['status' => 404]);
    }

    /**
     * GET /admin/pending-approvals
     *
     * Returns registrations where all user tasks are complete, awaiting admin approval.
     */
    public function getPendingApprovals(WP_REST_Request $request): WP_REST_Response
    {
        global $wpdb;

        $table = RegistrationTable::getTableName();

        if (!RegistrationTable::exists()) {
            return new WP_REST_Response(['items' => []]);
        }

        // Enrollment phase: pending registrations with approval task
        $pendingRows = $wpdb->get_results(
            "SELECT * FROM {$table}
             WHERE status = 'pending'
               AND completion_tasks IS NOT NULL
             ORDER BY registered_at DESC"
        );

        // Post-course phase: confirmed registrations with post_approval task
        $confirmedRows = $wpdb->get_results(
            "SELECT * FROM {$table}
             WHERE status = 'confirmed'
               AND completion_tasks IS NOT NULL
               AND completion_tasks LIKE '%post_approval%'
             ORDER BY registered_at DESC"
        );

        $completionService = ntdst_get(\Stride\Modules\Enrollment\EnrollmentCompletion::class);
        $items = [];

        // Enrollment approvals
        foreach ($pendingRows as $row) {
            $tasks = json_decode($row->completion_tasks ?? '{}', true) ?: [];

            if (!isset($tasks['approval']) || $tasks['approval']['status'] === 'completed') {
                continue;
            }
            if (!$completionService->areUserTasksComplete($tasks)) {
                continue;
            }

            $items[] = $this->buildApprovalItem($row, $tasks, 'approval');
        }

        // Post-course approvals
        foreach ($confirmedRows as $row) {
            $tasks = json_decode($row->completion_tasks ?? '{}', true) ?: [];

            if (!isset($tasks['post_approval']) || $tasks['post_approval']['status'] === 'completed') {
                continue;
            }
            // Check post-course user tasks done
            $postUserDone = true;
            foreach (['post_evaluation', 'post_documents'] as $pt) {
                if (isset($tasks[$pt]) && ($tasks[$pt]['status'] ?? 'pending') !== 'completed') {
                    $postUserDone = false;
                    break;
                }
            }
            if (!$postUserDone) {
                continue;
            }

            $items[] = $this->buildApprovalItem($row, $tasks, 'post_approval');
        }

        return new WP_REST_Response(['items' => $items]);
    }

    /**
     * POST /admin/approve-registration
     *
     * Marks approval task as complete and confirms the registration.
     */
    public function approveRegistration(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $registrationId = $request->get_param('registration_id');

        if (!$registrationId) {
            return new WP_Error('missing_param', __('registration_id is verplicht.', 'stride'), ['status' => 400]);
        }

        $completionService = ntdst_get(\Stride\Modules\Enrollment\EnrollmentCompletion::class);

        // Mark approval task as completed
        $result = $completionService->completeTask($registrationId, 'approval');

        if (is_wp_error($result)) {
            return $result;
        }

        // Now confirm the registration (auto-confirm won't fire for approval tasks)
        $enrollmentService = ntdst_get(\Stride\Modules\Enrollment\EnrollmentService::class);
        $confirmResult = $enrollmentService->confirmRegistration($registrationId);

        if (is_wp_error($confirmResult)) {
            return $confirmResult;
        }

        return new WP_REST_Response([
            'approved' => true,
            'registration_id' => $registrationId,
        ]);
    }

    /**
     * POST /admin/approve-post-course
     *
     * Marks post_approval task as complete (triggers LD completion + status change).
     */
    public function approvePostCourse(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $registrationId = $request->get_param('registration_id');

        if (!$registrationId) {
            return new WP_Error('missing_param', __('registration_id is verplicht.', 'stride'), ['status' => 400]);
        }

        $completionService = ntdst_get(\Stride\Modules\Enrollment\EnrollmentCompletion::class);
        $result = $completionService->completeTask($registrationId, 'post_approval');

        if (is_wp_error($result)) {
            return $result;
        }

        return new WP_REST_Response([
            'approved' => true,
            'registration_id' => $registrationId,
        ]);
    }

    // =========================================================================
    // ACTION QUEUE, HEALTH CHECKS, ACTIVITY FEED
    // =========================================================================

    /**
     * GET /admin/action-queue
     *
     * Evaluate notification rules against live data, cached for 5 minutes.
     */
    public function getActionQueue(WP_REST_Request $request): WP_REST_Response
    {
        global $wpdb;

        $rules = StrideSettingsService::getNotificationRules();

        // Check transient cache
        $cached = get_transient('stride_action_queue');
        if ($cached !== false) {
            $items = $cached;
        } else {
            $today = current_time('Y-m-d');
            $registrationTable = RegistrationTable::getTableName();
            $registrationTableExists = RegistrationTable::exists();

            $data = [];

            // Editions with capacity (for capacity_threshold rule)
            if (!empty($rules['capacity_threshold']['enabled'])) {
                $editions = $wpdb->get_results($wpdb->prepare(
                    "SELECT p.ID as id, p.post_title as title,
                            pm_cap.meta_value as capacity,
                            COALESCE(rc.cnt, 0) as registered
                     FROM {$wpdb->posts} p
                     INNER JOIN {$wpdb->postmeta} pm_date ON p.ID = pm_date.post_id AND pm_date.meta_key = '_ntdst_start_date'
                     LEFT JOIN {$wpdb->postmeta} pm_cap ON p.ID = pm_cap.post_id AND pm_cap.meta_key = '_ntdst_capacity'
                     LEFT JOIN (
                         SELECT edition_id, COUNT(*) as cnt FROM {$registrationTable}
                         WHERE status = 'confirmed' GROUP BY edition_id
                     ) rc ON rc.edition_id = p.ID
                     WHERE p.post_type = %s AND p.post_status = 'publish'
                     AND pm_date.meta_value >= %s
                     AND pm_cap.meta_value > 0",
                    EditionCPT::POST_TYPE,
                    $today
                ), ARRAY_A);
                $data['editions'] = $editions ?: [];
            }

            // Pending approvals
            if (!empty($rules['pending_approval']['enabled']) && $registrationTableExists) {
                $pending = $wpdb->get_results(
                    "SELECT id FROM {$registrationTable} WHERE status = 'pending'",
                    ARRAY_A
                );
                $data['pending_approvals'] = $pending ?: [];
            }

            // Stale quotes
            if (!empty($rules['stale_quote']['enabled'])) {
                $staleDays = (int) ($rules['stale_quote']['value'] ?? 7);
                $cutoff = wp_date('Y-m-d H:i:s', strtotime("-{$staleDays} days"));
                $staleQuotes = $wpdb->get_results($wpdb->prepare(
                    "SELECT p.ID as id, pm_num.meta_value as number
                     FROM {$wpdb->posts} p
                     INNER JOIN {$wpdb->postmeta} pm_st ON p.ID = pm_st.post_id AND pm_st.meta_key = 'status'
                     LEFT JOIN {$wpdb->postmeta} pm_num ON p.ID = pm_num.post_id AND pm_num.meta_key = 'quote_number'
                     WHERE p.post_type = %s AND p.post_status = 'publish'
                     AND pm_st.meta_value = %s
                     AND p.post_date < %s",
                    QuoteCPT::POST_TYPE,
                    QuoteStatus::Draft->value,
                    $cutoff
                ), ARRAY_A);
                $data['stale_quotes'] = $staleQuotes ?: [];
            }

            // Sessions approaching
            if (!empty($rules['session_approaching']['enabled'])) {
                $approachDays = (int) ($rules['session_approaching']['value'] ?? 1);
                $approachDate = wp_date('Y-m-d', strtotime("+{$approachDays} days"));
                $approachingSessions = $wpdb->get_results($wpdb->prepare(
                    "SELECT s.ID as id, s.post_title,
                            pm_date.meta_value as date,
                            pm_eid.meta_value as edition_id,
                            e.post_title as edition_title
                     FROM {$wpdb->posts} s
                     INNER JOIN {$wpdb->postmeta} pm_date ON s.ID = pm_date.post_id AND pm_date.meta_key = '_ntdst_date'
                     LEFT JOIN {$wpdb->postmeta} pm_eid ON s.ID = pm_eid.post_id AND pm_eid.meta_key = '_ntdst_edition_id'
                     LEFT JOIN {$wpdb->posts} e ON e.ID = pm_eid.meta_value
                     WHERE s.post_type = %s AND s.post_status = 'publish'
                     AND pm_date.meta_value >= %s AND pm_date.meta_value <= %s",
                    SessionCPT::POST_TYPE,
                    $today,
                    $approachDate
                ), ARRAY_A);
                $data['approaching_sessions'] = $approachingSessions ?: [];
            }

            // Editions starting soon
            if (!empty($rules['edition_starting']['enabled'])) {
                $startDays = (int) ($rules['edition_starting']['value'] ?? 3);
                $startDate = wp_date('Y-m-d', strtotime("+{$startDays} days"));
                $startingSoon = $wpdb->get_results($wpdb->prepare(
                    "SELECT p.ID as id, p.post_title as title,
                            pm_date.meta_value as start_date
                     FROM {$wpdb->posts} p
                     INNER JOIN {$wpdb->postmeta} pm_date ON p.ID = pm_date.post_id AND pm_date.meta_key = '_ntdst_start_date'
                     WHERE p.post_type = %s AND p.post_status = 'publish'
                     AND pm_date.meta_value >= %s AND pm_date.meta_value <= %s",
                    EditionCPT::POST_TYPE,
                    $today,
                    $startDate
                ), ARRAY_A);
                $data['starting_soon'] = $startingSoon ?: [];
            }

            // Incomplete tasks (editions where last session passed, registrations with incomplete tasks)
            if (!empty($rules['incomplete_tasks']['enabled']) && $registrationTableExists) {
                $taskDays = (int) ($rules['incomplete_tasks']['value'] ?? 7);
                $taskCutoff = wp_date('Y-m-d', strtotime("-{$taskDays} days"));
                $incompleteTasks = $wpdb->get_results($wpdb->prepare(
                    "SELECT r.id
                     FROM {$registrationTable} r
                     WHERE r.status = 'confirmed'
                     AND r.tasks IS NOT NULL
                     AND r.tasks LIKE %s
                     AND r.registered_at < %s",
                    '%"completed":false%',
                    $taskCutoff
                ), ARRAY_A);
                $data['incomplete_tasks'] = $incompleteTasks ?: [];
            }

            $service = new ActionQueueService();
            $items = $service->evaluate($rules, $data);

            set_transient('stride_action_queue', $items, 5 * MINUTE_IN_SECONDS);
        }

        // Filter out dismissed items
        $userId = get_current_user_id();
        $dismissed = get_user_meta($userId, 'stride_dismissed_actions', true);
        $dismissed = is_array($dismissed) ? $dismissed : [];

        // Prune dismissals older than 30 days
        $thirtyDaysAgo = strtotime('-30 days');
        $dismissed = array_filter($dismissed, static function (array $entry) use ($thirtyDaysAgo): bool {
            return strtotime($entry['date'] ?? '1970-01-01') > $thirtyDaysAgo;
        });
        update_user_meta($userId, 'stride_dismissed_actions', $dismissed);

        // Build a lookup set for fast filtering
        $dismissedKeys = [];
        foreach ($dismissed as $entry) {
            $dismissedKeys[$entry['rule'] . ':' . ($entry['subject_id'] ?? 0)] = true;
        }

        $filtered = array_values(array_filter($items, static function (array $item) use ($dismissedKeys): bool {
            $key = $item['rule'] . ':' . ($item['subject_id'] ?? 0);
            return !isset($dismissedKeys[$key]);
        }));

        return new WP_REST_Response($filtered);
    }

    /**
     * POST /admin/action-queue/dismiss
     *
     * Dismiss an action queue item for the current user.
     */
    public function dismissActionItem(WP_REST_Request $request): WP_REST_Response
    {
        $rule = sanitize_text_field($request->get_param('rule'));
        $subjectId = (int) $request->get_param('subject_id');
        $userId = get_current_user_id();

        $dismissed = get_user_meta($userId, 'stride_dismissed_actions', true);
        $dismissed = is_array($dismissed) ? $dismissed : [];

        $dismissed[] = [
            'rule' => $rule,
            'subject_id' => $subjectId,
            'date' => current_time('Y-m-d'),
        ];

        update_user_meta($userId, 'stride_dismissed_actions', $dismissed);

        return new WP_REST_Response(['dismissed' => true]);
    }

    /**
     * GET /admin/health-checks
     *
     * System health indicators for the dashboard.
     */
    public function getHealthChecks(WP_REST_Request $request): WP_REST_Response
    {
        global $wpdb;

        $registrationTable = RegistrationTable::getTableName();
        $registrationTableExists = RegistrationTable::exists();
        $today = current_time('Y-m-d');

        // Last registration timestamp
        $lastRegistration = 0;
        if ($registrationTableExists) {
            $lastRegDate = $wpdb->get_var(
                "SELECT MAX(registered_at) FROM {$registrationTable}"
            );
            if ($lastRegDate) {
                $lastRegistration = (int) strtotime($lastRegDate);
            }
        }

        // Last mail send timestamp — check audit log for quote.sent action
        $lastMailSend = 0;
        if (AuditTable::exists()) {
            $auditTable = AuditTable::getTableName();
            $lastMailDate = $wpdb->get_var($wpdb->prepare(
                "SELECT MAX(created_at) FROM {$auditTable} WHERE action = %s",
                'quote.sent'
            ));
            if ($lastMailDate) {
                $lastMailSend = (int) strtotime($lastMailDate);
            }
        }

        // Any open editions with future start date?
        $hasOpenEditions = (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT 1 FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm_status ON p.ID = pm_status.post_id AND pm_status.meta_key = '_ntdst_status'
             INNER JOIN {$wpdb->postmeta} pm_date ON p.ID = pm_date.post_id AND pm_date.meta_key = '_ntdst_start_date'
             WHERE p.post_type = %s AND p.post_status = 'publish'
             AND pm_status.meta_value = 'open'
             AND pm_date.meta_value >= %s
             LIMIT 1",
            EditionCPT::POST_TYPE,
            $today
        ));

        $service = new HealthCheckService();
        $checks = $service->evaluate($lastRegistration, $lastMailSend, $hasOpenEditions);

        return new WP_REST_Response($checks);
    }

    /**
     * GET /admin/activity
     *
     * Recent activity feed from audit log.
     */
    public function getActivityFeed(WP_REST_Request $request): WP_REST_Response
    {
        global $wpdb;

        $limit = min((int) $request->get_param('limit'), 50);
        if ($limit <= 0) {
            $limit = 10;
        }

        if (!AuditTable::exists()) {
            return new WP_REST_Response([]);
        }

        $auditTable = AuditTable::getTableName();
        $entries = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$auditTable} ORDER BY created_at DESC LIMIT %d",
            $limit
        ));

        if (empty($entries)) {
            return new WP_REST_Response([]);
        }

        // Collect actor IDs for batch user fetch
        $actorIds = [];
        foreach ($entries as $entry) {
            if (!empty($entry->actor_id)) {
                $actorIds[] = (int) $entry->actor_id;
            }
        }

        $usersMap = !empty($actorIds) ? BatchQueryHelper::batchGetUsers($actorIds) : [];

        $feed = [];
        foreach ($entries as $entry) {
            // Skip raw/system events that don't have a user-friendly label
            if (!AdminActivityMapper::isKnownAction($entry)) {
                continue;
            }

            $actorId = (int) ($entry->actor_id ?? 0);
            $user = $usersMap[$actorId] ?? null;
            $actorName = $user ? $user->display_name : __('Systeem', 'stride');

            $feed[] = AdminActivityMapper::fromAuditEntry($entry, $actorName);
        }

        return new WP_REST_Response($feed);
    }

    // =========================================================================
    // USER SEARCH + DETAIL
    // =========================================================================

    /**
     * GET /admin/users/search
     *
     * Search users by name, email, or login. Returns max 10 results.
     */
    public function searchUsers(WP_REST_Request $request): WP_REST_Response
    {
        $query = sanitize_text_field($request->get_param('q'));

        $userQuery = new \WP_User_Query([
            'search' => "*{$query}*",
            'search_columns' => ['user_login', 'user_email', 'display_name'],
            'number' => 10,
            'orderby' => 'display_name',
            'fields' => ['ID', 'display_name', 'user_email'],
        ]);

        $users = array_map(function ($user) {
            $userId = (int) $user->ID;
            return [
                'id' => $userId,
                'name' => $user->display_name,
                'email' => $user->user_email,
                'organisation' => get_user_meta($userId, 'organisation', true) ?: '',
                'registration_count' => $this->countUserRegistrations($userId),
            ];
        }, $userQuery->get_results());

        return new WP_REST_Response($users);
    }

    /**
     * GET /admin/users/{id}/detail
     *
     * Comprehensive user profile: personal info, registrations, quotes,
     * attendance summary, and audit trail.
     */
    public function getUserDetail(WP_REST_Request $request): WP_REST_Response
    {
        global $wpdb;

        $userId = (int) $request->get_param('id');
        $regPage = max(1, (int) $request->get_param('reg_page'));
        $regPerPage = 20;

        // --- User data ---
        $userData = get_userdata($userId);
        if (!$userData) {
            return new WP_REST_Response(['error' => 'User not found'], 404);
        }

        // Profile type
        $profileType = null;
        $profileService = ntdst_get(ProfileTypeService::class);
        if ($profileService) {
            $type = $profileService->getUserType($userId);
            if ($type) {
                $profileType = [
                    'name' => $type['label'] ?? $type['slug'],
                    'color' => $type['color'] ?? '',
                ];
            }
        }

        $user = [
            'id' => $userId,
            'display_name' => $userData->display_name,
            'email' => $userData->user_email,
            'phone' => get_user_meta($userId, 'phone', true) ?: '',
            'organisation' => get_user_meta($userId, 'organisation', true) ?: '',
            'department' => get_user_meta($userId, 'department', true) ?: '',
            'profile_type' => $profileType,
        ];

        // --- Registrations (paginated, with edition title) ---
        $registrations = [];
        $registrationsTotal = 0;
        $registrationTable = RegistrationTable::getTableName();

        if (RegistrationTable::exists()) {
            $registrationsTotal = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$registrationTable} WHERE user_id = %d",
                $userId
            ));

            $regOffset = ($regPage - 1) * $regPerPage;
            $regRows = $wpdb->get_results($wpdb->prepare(
                "SELECT r.id, r.edition_id, r.status, r.enrollment_path, r.registered_at,
                        r.completed_at, r.cancelled_at, p.post_title AS edition_title
                 FROM {$registrationTable} r
                 LEFT JOIN {$wpdb->posts} p ON r.edition_id = p.ID
                 WHERE r.user_id = %d
                 ORDER BY r.registered_at DESC
                 LIMIT %d OFFSET %d",
                $userId,
                $regPerPage,
                $regOffset
            ));

            foreach ($regRows as $row) {
                $registrations[] = [
                    'id' => (int) $row->id,
                    'edition_id' => (int) $row->edition_id,
                    'edition_title' => $row->edition_title ?: __('Onbekend', 'stride'),
                    'status' => $row->status,
                    'enrollment_path' => $row->enrollment_path,
                    'registered_at' => $row->registered_at,
                    'completed_at' => $row->completed_at,
                    'cancelled_at' => $row->cancelled_at,
                ];
            }
        }

        // --- Quotes (linked to user by billing email or user meta) ---
        $quotes = [];
        $quoteQuery = new \WP_Query([
            'post_type' => QuoteCPT::POST_TYPE,
            'post_status' => 'publish',
            'posts_per_page' => 20,
            'meta_query' => [
                'relation' => 'OR',
                [
                    'key' => 'billing_email',
                    'value' => $userData->user_email,
                ],
                [
                    'key' => 'user_id',
                    'value' => $userId,
                    'type' => 'NUMERIC',
                ],
            ],
            'orderby' => 'date',
            'order' => 'DESC',
        ]);

        foreach ($quoteQuery->posts as $quotePost) {
            $quoteId = $quotePost->ID;
            $quoteEditionId = (int) get_post_meta($quoteId, 'edition_id', true);
            $quoteEdition = $quoteEditionId ? get_post($quoteEditionId) : null;
            $quoteStatus = get_post_meta($quoteId, 'status', true) ?: '';
            $statusEnum = QuoteStatus::tryFrom($quoteStatus);

            $quotes[] = [
                'id' => $quoteId,
                'title' => $quotePost->post_title,
                'number' => get_post_meta($quoteId, 'quote_number', true) ?: '',
                'edition_id' => $quoteEditionId,
                'edition_title' => $quoteEdition ? $quoteEdition->post_title : '',
                'status' => $quoteStatus,
                'status_label' => $statusEnum?->label() ?? $quoteStatus,
                'total' => (float) get_post_meta($quoteId, 'total', true),
                'created_at' => $quotePost->post_date,
            ];
        }

        // --- Attendance summary (grouped by edition) ---
        $attendance = [];
        if (AttendanceTable::exists()) {
            $attendanceTable = AttendanceTable::getTableName();
            $attRows = $wpdb->get_results($wpdb->prepare(
                "SELECT a.edition_id, a.status, COUNT(*) as cnt,
                        p.post_title AS edition_title
                 FROM {$attendanceTable} a
                 LEFT JOIN {$wpdb->posts} p ON a.edition_id = p.ID
                 WHERE a.user_id = %d
                 GROUP BY a.edition_id, a.status
                 ORDER BY a.edition_id DESC",
                $userId
            ));

            // Group by edition
            $grouped = [];
            foreach ($attRows as $row) {
                $editionId = (int) $row->edition_id;
                if (!isset($grouped[$editionId])) {
                    $grouped[$editionId] = [
                        'edition_id' => $editionId,
                        'edition_title' => $row->edition_title ?: __('Onbekend', 'stride'),
                        'present' => 0,
                        'absent' => 0,
                        'excused' => 0,
                    ];
                }
                $status = $row->status;
                if (isset($grouped[$editionId][$status])) {
                    $grouped[$editionId][$status] = (int) $row->cnt;
                }
            }
            $attendance = array_values($grouped);
        }

        // --- Audit trail (last 50 entries where user is actor or subject) ---
        $auditTrail = [];
        $auditTrailTotal = 0;

        if (AuditTable::exists()) {
            $auditTable = AuditTable::getTableName();

            $auditTrailTotal = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$auditTable}
                 WHERE actor_id = %d OR (entity_type = 'user' AND entity_id = %d)",
                $userId,
                $userId
            ));

            $auditEntries = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$auditTable}
                 WHERE actor_id = %d OR (entity_type = 'user' AND entity_id = %d)
                 ORDER BY created_at DESC
                 LIMIT 50",
                $userId,
                $userId
            ));

            // Collect actor IDs for batch user fetch
            $actorIds = [];
            foreach ($auditEntries as $entry) {
                if (!empty($entry->actor_id)) {
                    $actorIds[] = (int) $entry->actor_id;
                }
            }
            $usersMap = !empty($actorIds) ? BatchQueryHelper::batchGetUsers($actorIds) : [];

            foreach ($auditEntries as $entry) {
                $actorId = (int) ($entry->actor_id ?? 0);
                $actorUser = $usersMap[$actorId] ?? null;
                $actorName = $actorUser ? $actorUser->display_name : __('Systeem', 'stride');

                $auditTrail[] = AdminActivityMapper::fromAuditEntry($entry, $actorName);
            }
        }

        return new WP_REST_Response([
            'user' => $user,
            'registrations' => $registrations,
            'registrations_total' => $registrationsTotal,
            'quotes' => $quotes,
            'attendance' => $attendance,
            'audit_trail' => $auditTrail,
            'audit_trail_total' => $auditTrailTotal,
        ]);
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * Count registrations for a given user.
     */
    private function countUserRegistrations(int $userId): int
    {
        global $wpdb;
        $table = RegistrationTable::getTableName();
        if (!RegistrationTable::exists()) {
            return 0;
        }
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE user_id = %d",
            $userId
        ));
    }

    /**
     * Build a pending approval item for the REST response.
     */
    private function buildApprovalItem(object $row, array $tasks, string $type): array
    {
        $userId = (int) $row->user_id;
        $user = get_userdata($userId);
        $editionId = (int) ($row->edition_id ?? 0);
        $edition = $editionId ? get_post($editionId) : null;

        return [
            'id' => (int) $row->id,
            'type' => $type,
            'user_id' => $userId,
            'user_name' => $user ? $user->display_name : __('Onbekend', 'stride'),
            'user_email' => $user ? $user->user_email : '',
            'edition_id' => $editionId,
            'edition_title' => $edition ? $edition->post_title : __('Onbekend', 'stride'),
            'registered_at' => $row->registered_at,
            'tasks' => $tasks,
        ];
    }

    /* ---------------------------------------------------------------
     *  Impersonation
     * ------------------------------------------------------------- */

    /**
     * Start impersonating a user. Switches the current session to the target user
     * and stores a token so the admin can return.
     */
    public function impersonateUser(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $targetId = (int) $request->get_param('id');
        $targetUser = get_userdata($targetId);

        if (!$targetUser) {
            return new WP_Error('not_found', __('Gebruiker niet gevonden.', 'stride'), ['status' => 404]);
        }

        $handler = new ImpersonationHandler();
        $validation = $handler->validateTarget(
            targetUserId: $targetId,
            targetIsAdmin: user_can($targetId, 'manage_options'),
            callerHasManageOptions: current_user_can('manage_options'),
        );

        if (is_wp_error($validation)) {
            return $validation;
        }

        $adminId = get_current_user_id();
        $token = $handler->generateToken();
        $handler->storeSession($token, $adminId);

        // Audit trail
        global $wpdb;
        $auditTable = $wpdb->prefix . 'ntdst_audit_log';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $auditTable))) {
            $wpdb->insert($auditTable, [
                'action'     => 'impersonation.started',
                'actor_id'   => $adminId,
                'subject_id' => $targetId,
                'context'    => wp_json_encode([
                    'target_name'  => $targetUser->display_name,
                    'target_email' => $targetUser->user_email,
                ]),
                'created_at' => current_time('mysql', true),
            ]);
        }

        // Switch session to target user
        wp_clear_auth_cookie();
        wp_set_auth_cookie($targetId, false);

        // Set impersonation cookie
        setcookie(ImpersonationHandler::COOKIE_NAME, $token, [
            'expires'  => time() + ImpersonationHandler::TTL,
            'path'     => COOKIEPATH,
            'domain'   => COOKIE_DOMAIN,
            'secure'   => is_ssl(),
            'httponly'  => true,
            'samesite' => 'Strict',
        ]);

        return new WP_REST_Response([
            'success'  => true,
            'redirect' => home_url('/'),
        ]);
    }

    /**
     * End impersonation and switch back to the original admin.
     * Redirects to the admin dashboard users tab.
     */
    public function endImpersonation(WP_REST_Request $request): void
    {
        $handler = new ImpersonationHandler();
        $token = $handler->getTokenFromCookie();
        $adminId = $handler->getOriginalAdmin($token);

        if ($adminId <= 0) {
            wp_safe_redirect(admin_url());
            exit;
        }

        // Clean up session
        $handler->endSession($token);

        // Clear impersonation cookie
        setcookie(ImpersonationHandler::COOKIE_NAME, '', [
            'expires' => time() - 3600,
            'path'    => COOKIEPATH,
            'domain'  => COOKIE_DOMAIN,
        ]);

        // Switch back to admin
        wp_clear_auth_cookie();
        wp_set_auth_cookie($adminId, false);

        // Redirect to dashboard users tab
        wp_safe_redirect(admin_url('admin.php?page=stride-dashboard#/gebruikers'));
        exit;
    }

    /* ---------------------------------------------------------------
     *  Notifications
     * ------------------------------------------------------------- */

    /**
     * Get recent notifications from the audit log.
     * Tracks read/unread state per admin user.
     */
    public function getNotifications(WP_REST_Request $request): WP_REST_Response
    {
        $userId = get_current_user_id();
        $lastReadId = (int) get_user_meta($userId, 'stride_last_read_notification_id', true);

        global $wpdb;
        $table = $wpdb->prefix . 'ntdst_audit_log';

        // Only notification-worthy events
        $actions = [
            'registration.created',
            'registration.cancelled',
            'quote.created',
            'completion.course_completed',
        ];
        $placeholders = implode(',', array_fill(0, count($actions), '%s'));

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $entries = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE action IN ({$placeholders}) ORDER BY created_at DESC LIMIT 10",
            ...$actions,
        ));

        $notifications = array_map(function ($entry) use ($lastReadId) {
            $actorName = '';
            if (!empty($entry->actor_id)) {
                $user = get_userdata((int) $entry->actor_id);
                $actorName = $user ? $user->display_name : 'Onbekend';
            }
            $mapped = AdminActivityMapper::fromAuditEntry($entry, $actorName);
            $mapped['read'] = $mapped['id'] <= $lastReadId;

            return $mapped;
        }, $entries ?: []);

        $unread = count(array_filter($notifications, fn($n) => !$n['read']));

        return new WP_REST_Response([
            'notifications' => $notifications,
            'unread_count'  => $unread,
        ]);
    }

    /**
     * Mark all notifications as read by storing the latest audit log ID.
     */
    public function markNotificationsRead(WP_REST_Request $request): WP_REST_Response
    {
        $userId = get_current_user_id();

        global $wpdb;
        $table = $wpdb->prefix . 'ntdst_audit_log';
        $latestId = (int) $wpdb->get_var("SELECT MAX(id) FROM {$table}");

        update_user_meta($userId, 'stride_last_read_notification_id', $latestId);

        return new WP_REST_Response(['success' => true, 'unread_count' => 0]);
    }

    /**
     * Export confirmed registrations for upcoming editions as a UTF-8 CSV file.
     *
     * Outputs directly to php://output and exits — no WP_REST_Response return.
     */
    public function exportRegistrations(WP_REST_Request $request): void
    {
        global $wpdb;
        $table = RegistrationTable::getTableName();

        if (!RegistrationTable::exists()) {
            wp_die('Registration table not found.');
        }

        // Get confirmed registrations for upcoming editions
        $today = current_time('Y-m-d');
        $registrations = $wpdb->get_results($wpdb->prepare(
            "SELECT r.*, p.post_title as edition_title,
                    pm_date.meta_value as edition_date
             FROM {$table} r
             LEFT JOIN {$wpdb->posts} p ON r.edition_id = p.ID
             LEFT JOIN {$wpdb->postmeta} pm_date ON r.edition_id = pm_date.post_id AND pm_date.meta_key = '_ntdst_start_date'
             WHERE r.status = 'confirmed'
             AND (pm_date.meta_value >= %s OR pm_date.meta_value IS NULL)
             ORDER BY pm_date.meta_value ASC, r.created_at ASC",
            $today
        ));

        // Set download headers
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="inschrijvingen-' . date('Y-m-d') . '.csv"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $output = fopen('php://output', 'w');
        // BOM for Excel UTF-8 compatibility
        fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

        // Header row (semicolons for Dutch Excel)
        fputcsv($output, ['Naam', 'E-mail', 'Organisatie', 'Editie', 'Datum', 'Status', 'Offerte #'], ';');

        foreach ($registrations as $reg) {
            $user = get_userdata((int) ($reg->user_id ?? 0));
            $name = $user ? $user->display_name : 'Onbekend';
            $email = $user ? $user->user_email : '';
            $org = $user ? (get_user_meta($user->ID, 'organisation', true) ?: '') : '';

            // Find linked quote number
            $quoteNumber = '';
            if (!empty($reg->id)) {
                $quotePost = $wpdb->get_var($wpdb->prepare(
                    "SELECT p.ID FROM {$wpdb->posts} p
                     INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
                     WHERE p.post_type = %s AND p.post_status = 'publish'
                     AND pm.meta_key = 'registration_id' AND pm.meta_value = %s
                     LIMIT 1",
                    QuoteCPT::POST_TYPE,
                    $reg->id
                ));
                if ($quotePost) {
                    $quoteNumber = get_post_meta((int) $quotePost, 'quote_number', true) ?: 'Q-' . $quotePost;
                }
            }

            fputcsv($output, [
                $name,
                $email,
                $org,
                $reg->edition_title ?? '',
                $reg->edition_date ?? '',
                $reg->status ?? '',
                $quoteNumber,
            ], ';');
        }

        fclose($output);
        exit;
    }
}
