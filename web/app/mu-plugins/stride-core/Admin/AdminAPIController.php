<?php

declare(strict_types=1);

namespace Stride\Admin;

use NTDST\Audit\AuditTable;
use Stride\Domain\AttendanceStatus;
use Stride\Domain\Money;
use Stride\Domain\OfferingStatus;
use Stride\Domain\QuoteStatus;
use Stride\Infrastructure\BatchQueryHelper;
use Stride\Modules\Attendance\AttendanceRepository;
use Stride\Modules\Attendance\AttendanceTable;
use Stride\Modules\Edition\EditionCPT;
use Stride\Modules\Edition\EditionRepository;
use Stride\Modules\Edition\SessionCPT;
use Stride\Modules\Edition\SessionRepository;
use Stride\Modules\Enrollment\EnrollmentService;
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

    /**
     * Hard upper bound on rows fetched per pending-approvals query (H-8).
     * Bounds JSON-decode work per poll; bucket counts clip silently beyond it.
     */
    private const APPROVALS_SCAN_CAP = 500;

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
                'theme' => [
                    'type' => 'integer',
                    'default' => 0,
                ],
                'format' => [
                    'type' => 'integer',
                    'default' => 0,
                ],
                'tag' => [
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

        // Task 1.4a — lightweight searchable edition typeahead (grid filter +
        // group-by source + queue scoping). NOT the heavy getEditions payload.
        register_rest_route(self::NAMESPACE, '/admin/editions/options', [
            'methods' => 'GET',
            'callback' => [$this, 'getEditionOptions'],
            'permission_callback' => [$this, 'canViewAdmin'],
            'args' => [
                'q' => [
                    'type' => 'string',
                    'default' => '',
                ],
                'scope' => [
                    'type' => 'string',
                    'default' => 'active',
                    'enum' => ['active', 'all'],
                ],
                'page' => [
                    'type' => 'integer',
                    'default' => 1,
                    'minimum' => 1,
                ],
                'per_page' => [
                    // No 'maximum' in the schema: an over-cap value must CLAMP
                    // in the callback (typeahead UX), not 400-reject. The hard
                    // cap of 100 is enforced in getEditionOptions().
                    'type' => 'integer',
                    'default' => 50,
                    'minimum' => 1,
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

        // Trajectory typeahead options (lightweight {id,title,status})
        register_rest_route(self::NAMESPACE, '/admin/trajectories/options', [
            'methods' => 'GET',
            'callback' => [$this, 'getTrajectoryOptions'],
            'permission_callback' => [$this, 'canViewAdmin'],
            'args' => [
                'q' => [
                    'type' => 'string',
                    'default' => '',
                ],
                'scope' => [
                    'type' => 'string',
                    'default' => 'active',
                    'enum' => ['active', 'all'],
                ],
                'page' => [
                    'type' => 'integer',
                    'default' => 1,
                    'minimum' => 1,
                ],
                'per_page' => [
                    // No 'maximum': over-cap CLAMPS in the callback (typeahead UX),
                    // never 400-rejects. Hard cap of 100 enforced in
                    // getTrajectoryOptions().
                    'type' => 'integer',
                    'default' => 50,
                    'minimum' => 1,
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
            'args' => [
                'stale_days' => [
                    'type' => 'integer',
                    'default' => 7,
                    'minimum' => 1,
                ],
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
            ],
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

        // Update user profile (personal + billing)
        register_rest_route(self::NAMESPACE, '/admin/users/(?P<id>\d+)/profile', [
            'methods' => 'POST',
            'callback' => [$this, 'updateUserProfile'],
            'permission_callback' => [$this, 'canManageAdmin'],
            'args' => [
                'id' => ['type' => 'integer', 'required' => true],
            ],
        ]);

        // Reveal a single sensitive field (national_id, date_of_birth,
        // professional_license_number, phone) — every success is audited.
        register_rest_route(self::NAMESPACE, '/admin/users/(?P<id>\d+)/reveal', [
            'methods' => 'GET',
            'callback' => [$this, 'revealSensitiveField'],
            'permission_callback' => [$this, 'canManageAdmin'],
            'args' => [
                'id' => ['type' => 'integer', 'required' => true],
                'field' => ['type' => 'string', 'required' => true],
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

        // Task 1.3 — admin registration grid (strangle: thin route, delegates to service)
        register_rest_route(self::NAMESPACE, '/admin/registrations', [
            'methods'             => 'GET',
            'callback'            => [$this, 'getRegistrations'],
            'permission_callback' => [$this, 'canViewAdmin'],
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
            $today,
        ));

        // Total active registrations
        $totalRegistrations = 0;
        if ($registrationTableExists) {
            $totalRegistrations = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$registrationTable} WHERE status = 'confirmed'",
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
            QuoteStatus::Draft->value,
        ));

        // Pending registrations (for actionCount)
        $pendingRegistrations = 0;
        if ($registrationTableExists) {
            $pendingRegistrations = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$registrationTable} WHERE status = 'pending'",
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
            $today,
        ));

        // Open trajectories (status = 'open')
        $openTrajectories = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = %s
             WHERE p.post_type = %s AND p.post_status = 'publish'
             AND pm.meta_value = %s",
            '_ntdst_status',
            TrajectoryCPT::POST_TYPE,
            'open',
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
            $today,
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
            $today,
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
                $weekAgo,
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
                $thisWeekStart,
            ));
            $registrationsLastWeek = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$registrationTable} WHERE registered_at >= %s AND registered_at < %s",
                $lastWeekStart,
                $thisWeekStart,
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
            $twoWeeksFromNow,
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
        $page = max(1, (int) ($request->get_param('page') ?: 1));
        $perPage = max(1, (int) ($request->get_param('per_page') ?: 20));
        $search = sanitize_text_field($request->get_param('search') ?? '');
        $status = sanitize_text_field($request->get_param('status') ?? '');
        $dateFrom = sanitize_text_field($request->get_param('date_from') ?? '');
        $dateTo = sanitize_text_field($request->get_param('date_to') ?? '');
        $themeId = (int) $request->get_param('theme');
        $formatId = (int) $request->get_param('format');
        $tagId = (int) $request->get_param('tag');
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

        // By default, only show editions that haven't passed more than 2 days ago.
        // Permit NULL start_date so dateless editions (no sessions -> no
        // start_date meta, the interest-list anchors) show in the default scope.
        // Same fix the Admin Workspace spec §10.7 / Task 1.2 inherits — see
        // docs/plans/2026-06-13-admin-workspace-spec.md.
        if (empty($dateFrom)) {
            $where[] = "(pm_start.meta_value >= %s OR pm_start.meta_value IS NULL)";
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

        // Taxonomy filters (theme/format/tag — applied to the linked course's terms)
        $tagJoin = $this->buildCourseTaxonomyJoin(
            ['theme' => $themeId, 'format' => $formatId, 'tag' => $tagId],
            $where,
            $params,
        );

        $whereClause = implode(' AND ', $where);

        // Get total count
        $countParams = $params;
        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm_start ON p.ID = pm_start.post_id AND pm_start.meta_key = '_ntdst_start_date'
             {$tagJoin}
             WHERE {$whereClause}",
            ...$countParams,
        ));

        // Get editions - ordered by start date ASC (nearest first)
        $params[] = $perPage;
        $params[] = $offset;

        $editions = $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT p.ID, p.post_title, pm_start.meta_value as start_date
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm_start ON p.ID = pm_start.post_id AND pm_start.meta_key = '_ntdst_start_date'
             {$tagJoin}
             WHERE {$whereClause}
             ORDER BY pm_start.meta_value IS NULL, pm_start.meta_value ASC
             LIMIT %d OFFSET %d",
            ...$params,
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
     * GET /admin/editions/options
     *
     * Lightweight, searchable edition typeahead for the admin grid filter,
     * group-by source, and queue scoping. Returns only {id, title,
     * effective_status} per edition — NOT the heavy getEditions payload.
     *
     * Params:
     *  - q        server-side title LIKE (bound via $wpdb->prepare).
     *  - scope    active (default) | all. active excludes terminal/past
     *             editions via getEffectiveStatus (INV-7) but KEEPS dateless
     *             editions (sessionless §10.7 carve-out).
     *  - page / per_page  paged, per_page capped at 100.
     *
     * §10.6: scope=all warrants NO extra capability — gate is canViewAdmin only.
     * M4: every param is validated and bound through $wpdb->prepare.
     */
    public function getEditionOptions(WP_REST_Request $request): WP_REST_Response
    {
        global $wpdb;

        $q = sanitize_text_field((string) ($request->get_param('q') ?? ''));

        $scope = (string) ($request->get_param('scope') ?? 'active');
        if (!in_array($scope, ['active', 'all'], true)) {
            $scope = 'active';
        }

        $page = max(1, absint($request->get_param('page')));
        $perPage = absint($request->get_param('per_page'));
        $perPage = $perPage > 0 ? min($perPage, 100) : 50;
        $offset = ($page - 1) * $perPage;

        $twoDaysAgo = wp_date('Y-m-d', strtotime('-2 days'));

        // Base predicate: published editions.
        $where = ['p.post_type = %s', "p.post_status = 'publish'"];
        $params = [EditionCPT::POST_TYPE];

        // scope=active → pre-filter to NOT-yet-past, but PERMIT NULL start_date
        // so dateless (sessionless §10.7) editions stay in scope. Mirrors the
        // getEditions list-view default predicate (commit e2ace22b).
        if ($scope === 'active') {
            $where[] = '(pm_start.meta_value >= %s OR pm_start.meta_value IS NULL)';
            $params[] = $twoDaysAgo;
        }

        // q → server-side title search, bound LIKE (never interpolated).
        if ($q !== '') {
            $where[] = 'p.post_title LIKE %s';
            $params[] = '%' . $wpdb->esc_like($q) . '%';
        }

        $whereClause = implode(' AND ', $where);

        // Total (DISTINCT — LEFT JOIN can multiply rows).
        $countParams = $params;
        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm_start ON p.ID = pm_start.post_id AND pm_start.meta_key = '_ntdst_start_date'
             WHERE {$whereClause}",
            ...$countParams,
        ));

        // Page of candidate ids, NULL-last ordering (dateless sink to the end).
        $pageParams = $params;
        $pageParams[] = $perPage;
        $pageParams[] = $offset;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT p.ID, p.post_title, pm_start.meta_value AS start_date
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm_start ON p.ID = pm_start.post_id AND pm_start.meta_key = '_ntdst_start_date'
             WHERE {$whereClause}
             ORDER BY pm_start.meta_value IS NULL, pm_start.meta_value ASC
             LIMIT %d OFFSET %d",
            ...$pageParams,
        ));

        $editionIds = array_map(static fn($r) => (int) $r->ID, $rows);

        // INV-7: display status via getEffectiveStatus — batch to avoid N+1.
        $statuses = [];
        if (!empty($editionIds)) {
            $editionService = ntdst_get(\Stride\Modules\Edition\EditionService::class);
            $statuses = $editionService->getEffectiveStatuses($editionIds);
        }

        $items = [];
        foreach ($rows as $row) {
            $id = (int) $row->ID;
            $status = $statuses[$id] ?? null;

            // scope=active also drops editions whose EFFECTIVE status is
            // terminal (Cancelled/Completed/Archived) even if the SQL date
            // pre-filter let them through (e.g. terminal stored status, or a
            // past end_date with no/future start_date). Dateless editions are
            // never terminal here, so they survive (sessionless §10.7).
            if ($scope === 'active' && $status !== null && $status->isTerminal()) {
                continue;
            }

            $items[] = [
                'id' => $id,
                'title' => $row->post_title,
                'effective_status' => $status !== null ? $status->value : '',
            ];
        }

        return new WP_REST_Response([
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
        ]);
    }

    /**
     * Agenda view: Each session date is a row.
     */
    private function getEditionsAgendaView(WP_REST_Request $request, string $today, string $twoDaysAgo): WP_REST_Response
    {
        global $wpdb;

        $page = max(1, (int) ($request->get_param('page') ?: 1));
        $perPage = max(1, (int) ($request->get_param('per_page') ?: 20));
        $search = sanitize_text_field($request->get_param('search') ?? '');
        $status = sanitize_text_field($request->get_param('status') ?? '');
        $dateFrom = sanitize_text_field($request->get_param('date_from') ?? '');
        $dateTo = sanitize_text_field($request->get_param('date_to') ?? '');
        $themeId = (int) $request->get_param('theme');
        $formatId = (int) $request->get_param('format');
        $tagId = (int) $request->get_param('tag');
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

        // Taxonomy filters (theme/format/tag — applied to the linked course's terms)
        $tagJoin = $this->buildCourseTaxonomyJoin(
            ['theme' => $themeId, 'format' => $formatId, 'tag' => $tagId],
            $where,
            $params,
            'e.ID',
        );

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
            ...$countParams,
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
            ...$params,
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
     * Get the three taxonomies used by the editions filter:
     *   - theme   (stride_theme, curated content area)
     *   - format  (stride_format, delivery format)
     *   - tag     (ld_course_tag, free-form admin tags)
     *
     * Each is an array of {id, name, count}, with `hide_empty => false` so admins
     * can browse the full vocabulary even when nothing is tagged yet.
     */
    public function getCourseTags(WP_REST_Request $request): WP_REST_Response
    {
        return new WP_REST_Response([
            'theme'  => $this->fetchTaxonomyTerms('stride_theme'),
            'format' => $this->fetchTaxonomyTerms('stride_format'),
            'tag'    => $this->fetchTaxonomyTerms('ld_course_tag'),
        ]);
    }

    /**
     * Fetch terms for a taxonomy as a simple list of {id, name, count}.
     */
    private function fetchTaxonomyTerms(string $taxonomy): array
    {
        $terms = get_terms([
            'taxonomy' => $taxonomy,
            'hide_empty' => false,
            'orderby' => 'name',
            'order' => 'ASC',
        ]);

        if (is_wp_error($terms)) {
            return [];
        }

        return array_map(static fn($t) => [
            'id' => (int) $t->term_id,
            'name' => $t->name,
            'count' => (int) $t->count,
        ], $terms);
    }

    /**
     * Build the JOIN clause filtering editions by linked-course taxonomy terms.
     *
     * Adds WHERE conditions + params for each non-zero term id. Returns the JOIN SQL
     * fragment (empty string if no filters active). Mutates $where and $params in place.
     *
     * @param array{theme: int, format: int, tag: int} $termIds
     * @param array<int, string>                       $where
     * @param array<int, mixed>                        $params
     * @param string                                   $editionIdColumn  Column for the edition post ID (e.g. 'p.ID' or 'e.ID')
     */
    private function buildCourseTaxonomyJoin(array $termIds, array &$where, array &$params, string $editionIdColumn = 'p.ID'): string
    {
        global $wpdb;

        $taxonomies = [
            'theme'  => 'stride_theme',
            'format' => 'stride_format',
            'tag'    => 'ld_course_tag',
        ];

        $activeFilters = array_filter($termIds, static fn($id) => $id > 0);
        if (empty($activeFilters)) {
            return '';
        }

        // Always join postmeta → course id once
        $joins = ["INNER JOIN {$wpdb->postmeta} pm_course ON {$editionIdColumn} = pm_course.post_id AND pm_course.meta_key = '_ntdst_course_id'"];

        // Add one term_relationships + term_taxonomy join alias per active filter,
        // so they can be AND-combined. Taxonomy names are hardcoded (internal
        // constants, never user input) to avoid placeholder ordering issues
        // between JOIN and WHERE — only the integer term_id is parameterized.
        //
        // CAST the postmeta varchar to UNSIGNED to match term_relationships.object_id
        // (bigint). Without the explicit cast MySQL coerces the bigint to varchar,
        // killing the object_id index on a large term_relationships table.
        foreach ($activeFilters as $kind => $termId) {
            $aliasTr = "tr_{$kind}";
            $aliasTt = "tt_{$kind}";
            $taxonomy = esc_sql($taxonomies[$kind]);
            $joins[] = "INNER JOIN {$wpdb->term_relationships} {$aliasTr} ON CAST(pm_course.meta_value AS UNSIGNED) = {$aliasTr}.object_id";
            $joins[] = "INNER JOIN {$wpdb->term_taxonomy} {$aliasTt} ON {$aliasTr}.term_taxonomy_id = {$aliasTt}.term_taxonomy_id AND {$aliasTt}.taxonomy = '{$taxonomy}'";
            $where[] = "{$aliasTt}.term_id = %d";
            $params[] = (int) $termId;
        }

        return implode(' ', $joins);
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
        $speakers = $this->editionRepository->getSpeakersLabel($editionId);

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
                $editionId,
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
            $editionId,
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
            $editionId,
        ));

        // Collect user IDs for batch fetch
        $userIds = array_map(fn($r) => (int) $r->user_id, $registrations);

        // Batch fetch users
        $users = BatchQueryHelper::batchGetUsers($userIds);

        // Get attendance records if table exists (already optimized with batch)
        $attendanceByUser = BatchQueryHelper::batchGetAttendance($editionId);

        // Format registrations with pre-fetched data.
        // Anonymous interest/waitlist rows have no user record — fall back to
        // the name/email captured in enrollment_data so admin can see them.
        $items = [];
        foreach ($registrations as $reg) {
            $userId = (int) $reg->user_id;
            $user = $userId ? ($users[$userId] ?? null) : null;

            $name = $user ? $user->display_name : '';
            $email = $user ? $user->user_email : '';
            $isAnon = !$user;

            if ($isAnon) {
                $stageData = [];
                $raw = $reg->enrollment_data ?? '';
                if (is_string($raw) && $raw !== '') {
                    $decoded = json_decode($raw, true);
                    if (is_array($decoded)) {
                        // status maps to the stage key (interest/waitlist).
                        // Wrapped shape: $decoded[$status]['data'][field].
                        $stageEnvelope = $decoded[$reg->status] ?? [];
                        $stageData = is_array($stageEnvelope['data'] ?? null) ? $stageEnvelope['data'] : [];
                    }
                }
                $name = $stageData['name'] ?? '(anoniem)';
                $email = $stageData['email'] ?? '';
            }

            // Build attendance map for this user (anon rows have empty attendance)
            $attendance = [];
            if ($userId) {
                foreach ($sessionIds as $sessionId) {
                    $attendance[$sessionId] = $attendanceByUser[$userId][$sessionId] ?? null;
                }
            }

            $items[] = [
                'id' => (int) $reg->id,
                'user' => [
                    'id' => $userId,
                    'name' => $name,
                    'email' => $email,
                ],
                'anonymous' => $isAnon,
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

        $page = max(1, (int) ($request->get_param('page') ?: 1));
        $perPage = max(1, (int) ($request->get_param('per_page') ?: 20));
        $search = sanitize_text_field($request->get_param('search') ?? '');
        $status = sanitize_text_field($request->get_param('status') ?? '');
        $editionId = (int) $request->get_param('edition_id');
        $offset = ($page - 1) * $perPage;

        // Build query
        $where = ["p.post_type = %s", "p.post_status = 'publish'"];
        $params = [QuoteCPT::POST_TYPE];

        // Search by user name or email. Resolve to user IDs first so the main
        // quote query only filters by meta_value IN (...) instead of running a
        // double LIKE join against wp_users for every candidate quote.
        if (!empty($search)) {
            $searchPattern = '%' . $wpdb->esc_like($search) . '%';
            $matchedUserIds = $wpdb->get_col($wpdb->prepare(
                "SELECT ID FROM {$wpdb->users}
                 WHERE display_name LIKE %s OR user_email LIKE %s
                 LIMIT 500",
                $searchPattern,
                $searchPattern,
            ));

            if (empty($matchedUserIds)) {
                // No matching users — short-circuit with an empty result set.
                return new WP_REST_Response([
                    'data'     => [],
                    'total'    => 0,
                    'page'     => $page,
                    'per_page' => $perPage,
                ]);
            }

            $matchedUserIds = array_map('intval', $matchedUserIds);
            $idPlaceholders = implode(',', array_fill(0, count($matchedUserIds), '%d'));
            $where[] = "EXISTS (
                SELECT 1 FROM {$wpdb->postmeta} pm_user
                WHERE pm_user.post_id = p.ID
                AND pm_user.meta_key = 'user_id'
                AND pm_user.meta_value IN ({$idPlaceholders})
            )";
            foreach ($matchedUserIds as $uid) {
                $params[] = $uid;
            }
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
            ...$params,
        ));

        // Get quotes
        $params[] = $perPage;
        $params[] = $offset;

        $quotes = $wpdb->get_results($wpdb->prepare(
            "SELECT p.ID, p.post_title, p.post_date FROM {$wpdb->posts} p
             WHERE {$whereClause}
             ORDER BY p.post_date DESC
             LIMIT %d OFFSET %d",
            ...$params,
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
            $quoteTotal = Money::cents((int) ($meta['total'] ?? 0))->amount();
            $quoteSubtotal = Money::cents((int) ($meta['subtotal'] ?? 0))->amount();
            $quoteTax = Money::cents((int) ($meta['tax'] ?? 0))->amount();
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
                'lineItems' => $this->lineItemsToEuros(
                    is_array($quoteItems) ? $quoteItems : (json_decode($quoteItems, true) ?: []),
                ),
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
     * GET /admin/registrations
     *
     * Registration grid — thin callback, all logic in AdminRegistrationQueryService.
     * Validates + sanitises params, delegates to the service, returns response.
     * NO $wpdb / business logic here (strangle discipline INV-3).
     */
    public function getRegistrations(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $params = [
            'page'         => absint($request->get_param('page') ?: 1),
            'per_page'     => absint($request->get_param('per_page') ?: 50),
            'edition_id'   => absint($request->get_param('edition_id') ?: 0) ?: null,
            'company_id'   => absint($request->get_param('company_id') ?: 0) ?: null,
            'trajectory_id' => absint($request->get_param('trajectory_id') ?: 0) ?: null,
            'status'       => sanitize_text_field((string) ($request->get_param('status') ?? '')),
            'sort'         => sanitize_text_field((string) ($request->get_param('sort') ?? '')),
            'order'        => sanitize_text_field((string) ($request->get_param('order') ?? '')),
            'q'            => sanitize_text_field((string) ($request->get_param('q') ?? '')),
            'edition_scope' => sanitize_text_field((string) ($request->get_param('edition_scope') ?? 'active')),
            'group_by'     => sanitize_text_field((string) ($request->get_param('group_by') ?? '')),
        ];

        // Remove empty strings so queryForGrid's isset/!empty checks work correctly.
        $params = array_filter($params, fn($v) => $v !== '' && $v !== null);

        $service = ntdst_get(\Stride\Admin\AdminRegistrationQueryService::class);
        $result  = $service->getGridPage($params);

        if (is_wp_error($result)) {
            return $result;
        }

        return new WP_REST_Response($result);
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

        $page = max(1, (int) ($request->get_param('page') ?: 1));
        $perPage = max(1, (int) ($request->get_param('per_page') ?: 20));
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
            ...$params,
        ));

        // Get trajectories
        $params[] = $perPage;
        $params[] = $offset;

        $trajectories = $wpdb->get_results($wpdb->prepare(
            "SELECT p.ID, p.post_title, p.post_date, p.post_content FROM {$wpdb->posts} p
             WHERE {$whereClause}
             ORDER BY p.post_date DESC
             LIMIT %d OFFSET %d",
            ...$params,
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

        // Collect all edition IDs from trajectory courses meta
        $allEditionIds = [];

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

        // Batch fetch trajectory enrollments via canonical RegistrationRepository
        // (stride_vad_registrations is the unified source; the legacy
        // stride_vad_trajectory_enrollments table is no longer written to.)
        $registrationRepo = ntdst_get(\Stride\Modules\Enrollment\RegistrationRepository::class);
        $enrollmentCounts = $registrationRepo->countByTrajectoryIds($trajectoryIds);
        $allEnrollments = $registrationRepo->findByTrajectoryIds($trajectoryIds, 50);

        $allEnrollmentUserIds = [];
        foreach ($allEnrollments as $rows) {
            foreach ($rows as $row) {
                $allEnrollmentUserIds[] = (int) $row->user_id;
            }
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
                        'enrolledAt' => $enrollment->registered_at,
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
     * GET /admin/trajectories/options
     *
     * Lightweight, searchable trajectory typeahead for the admin grid
     * trajectory filter. Returns only {id, title, status} per trajectory —
     * NOT the heavy getTrajectories payload.
     *
     * Params:
     *  - q        server-side title LIKE (bound via $wpdb->prepare + esc_like).
     *  - scope    active (default) | all. active restricts to non-terminal
     *             statuses (announcement/open/in_progress — mirrors
     *             TrajectoryRepository::findActive). Trajectories have no dates,
     *             so the active scope is purely status-based.
     *  - page / per_page  paged, per_page capped at 100 (clamp, not 400).
     *
     * §10.6: scope=all warrants NO extra capability — gate is canViewAdmin only.
     * M4: every param is validated and bound through $wpdb->prepare.
     */
    public function getTrajectoryOptions(WP_REST_Request $request): WP_REST_Response
    {
        global $wpdb;

        $q = sanitize_text_field((string) ($request->get_param('q') ?? ''));

        $scope = (string) ($request->get_param('scope') ?? 'active');
        if (!in_array($scope, ['active', 'all'], true)) {
            $scope = 'active';
        }

        $page = max(1, absint($request->get_param('page')));
        $perPage = absint($request->get_param('per_page'));
        $perPage = $perPage > 0 ? min($perPage, 100) : 50;
        $offset = ($page - 1) * $perPage;

        // Base predicate: published trajectories.
        $where = ['p.post_type = %s', "p.post_status = 'publish'"];
        $params = [TrajectoryCPT::POST_TYPE];

        // q → server-side title search, bound LIKE (never interpolated, M4).
        if ($q !== '') {
            $where[] = 'p.post_title LIKE %s';
            $params[] = '%' . $wpdb->esc_like($q) . '%';
        }

        // scope=active → restrict to non-terminal statuses via an EXISTS subquery
        // (mirrors TrajectoryRepository::findActive's status set). scope=all adds
        // no status restriction. No date carve-out: trajectories have no dates.
        if ($scope === 'active') {
            $activeStatuses = [
                OfferingStatus::Announcement->value,
                OfferingStatus::Open->value,
                OfferingStatus::InProgress->value,
            ];
            $statusPlaceholders = implode(',', array_fill(0, count($activeStatuses), '%s'));
            $where[] = "EXISTS (SELECT 1 FROM {$wpdb->postmeta} pm_status
                WHERE pm_status.post_id = p.ID
                AND pm_status.meta_key = '_ntdst_status'
                AND pm_status.meta_value IN ({$statusPlaceholders}))";
            foreach ($activeStatuses as $st) {
                $params[] = $st;
            }
        }

        $whereClause = implode(' AND ', $where);

        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} p WHERE {$whereClause}",
            ...$params,
        ));

        $pageParams = $params;
        $pageParams[] = $perPage;
        $pageParams[] = $offset;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT p.ID, p.post_title FROM {$wpdb->posts} p
             WHERE {$whereClause}
             ORDER BY p.post_title ASC
             LIMIT %d OFFSET %d",
            ...$pageParams,
        ));

        $trajectoryIds = array_map(static fn($r) => (int) $r->ID, $rows);

        // Batch-fetch status to compose {id,title,status} — avoid N+1.
        $statusMeta = [];
        if (!empty($trajectoryIds)) {
            $statusMeta = BatchQueryHelper::batchGetPostMeta($trajectoryIds, ['_ntdst_status']);
        }

        $items = [];
        foreach ($rows as $row) {
            $id = (int) $row->ID;
            $status = $statusMeta[$id]['_ntdst_status'] ?? '';

            $items[] = [
                'id' => $id,
                'title' => $row->post_title,
                'status' => is_string($status) ? $status : '',
            ];
        }

        return new WP_REST_Response([
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
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
     * Returns "needs admin attention" registrations across three buckets:
     * - approval        : pending, user tasks complete, waiting for admin
     * - post_approval   : confirmed, post-course user tasks complete, waiting for sign-off
     * - stale_user      : pending, user tasks incomplete, idle for ≥ stale_days (default 7)
     *                     — admin sees who's stuck and decides per case (no auto-cancel)
     *
     * Query params:
     * - stale_days (int, default 7): how many days of inactivity before a user-side
     *   pending registration is considered "stale".
     * - page / per_page (int, defaults 1/20): paginate `items` like the other
     *   list endpoints (total/page/perPage/totalPages in the response).
     *   `counts` stays GLOBAL — the dashboard tab pills read it.
     *
     * Bounded (audit H-8): both queries are column-trimmed, pre-filtered with
     * JSON predicates on the FIXED task keys (approval / post_approval /
     * post_evaluation / post_documents) and LIMIT-capped. The arbitrary-key
     * scans (areUserTasksComplete / getFirstOpenUserTask) stay in PHP —
     * EnrollmentCompletion owns those semantics, so the SQL filter is shaped
     * to only ever OVER-fetch (PHP re-checks remain authoritative). The task
     * status comparisons carry an explicit COLLATE utf8mb4_bin so SQL matches
     * PHP's strict ===/!== exactly (CR-E1): MariaDB's JSON column type already
     * pins completion_tasks to utf8mb4_bin (live-probed), but the explicit
     * collation keeps the over-fetch property even if the column type drifts
     * to the table's case-insensitive collation.
     * Bucket counts clip beyond APPROVALS_SCAN_CAP rows per query; the
     * response carries `clipped` so the consumer can surface the truncation
     * instead of presenting capped counts as the whole queue (CR-E2).
     */
    public function getPendingApprovals(WP_REST_Request $request): WP_REST_Response
    {
        $staleDays = max(1, (int) ($request->get_param('stale_days') ?? 7));
        $page = max(1, (int) ($request->get_param('page') ?: 1));
        $perPage = max(1, (int) ($request->get_param('per_page') ?: 20));

        if (!RegistrationTable::exists()) {
            return new WP_REST_Response([
                'items' => [],
                'counts' => ['approval' => 0, 'post_approval' => 0, 'stale_user' => 0],
                'clipped' => false,
                'stale_threshold_days' => $staleDays,
                'total' => 0,
                'page' => $page,
                'perPage' => $perPage,
                'totalPages' => 0,
            ]);
        }

        $staleThreshold = gmdate('Y-m-d H:i:s', time() - ($staleDays * DAY_IN_SECONDS));

        // INV-3: the registrations table is repository-owned — both scan
        // queries (exact SQL incl. COLLATE pin + scan cap) live in
        // RegistrationRepository; the controller keeps bucketing/pagination.
        $registrationRepo = ntdst_get(\Stride\Modules\Enrollment\RegistrationRepository::class);

        // Enrollment phase: pending registrations that can still yield an item —
        // an open admin-approval task (bucket 1) or stale-aged (bucket 3 candidate).
        $pendingRows = $registrationRepo->findPendingWithOpenApproval($staleThreshold, self::APPROVALS_SCAN_CAP);

        // Post-course phase: confirmed registrations with an open post_approval
        // task whose post-course user tasks (fixed keys) are absent-or-completed.
        $confirmedRows = $registrationRepo->findConfirmedWithOpenPostApproval(self::APPROVALS_SCAN_CAP);

        $completionService = ntdst_get(\Stride\Modules\Enrollment\EnrollmentCompletion::class);
        /** @var list<array{0: object, 1: array, 2: string, 3: array}> $matches */
        $matches = [];
        $counts = ['approval' => 0, 'post_approval' => 0, 'stale_user' => 0];

        foreach ($pendingRows as $row) {
            $tasks = is_array($row->completion_tasks) ? $row->completion_tasks : [];
            $userTasksDone = $completionService->areUserTasksComplete($tasks);

            // Bucket 1: user is done, waiting on admin approval
            if (
                $userTasksDone
                && isset($tasks['approval'])
                && ($tasks['approval']['status'] ?? 'pending') !== 'completed'
            ) {
                $matches[] = [$row, $tasks, 'approval', []];
                $counts['approval']++;
                continue;
            }

            // Bucket 3: user-side stale pending — user hasn't finished tasks and the
            // registration is older than the threshold. Capacity is held until admin
            // contacts the user or cancels.
            if (!$userTasksDone && $row->registered_at && $row->registered_at <= $staleThreshold) {
                $openTask = $completionService->getFirstOpenUserTask($tasks);
                $matches[] = [$row, $tasks, 'stale_user', [
                    'open_task' => $openTask,
                    'open_task_label' => $openTask
                        ? \Stride\Modules\Enrollment\EnrollmentCompletion::taskTypeLabel($openTask)
                        : null,
                    'days_idle' => (int) floor((time() - strtotime($row->registered_at)) / DAY_IN_SECONDS),
                ]];
                $counts['stale_user']++;
            }
        }

        // Bucket 2: post-course approval. SQL already filtered on the fixed
        // keys; the PHP re-check stays authoritative (the filter may over-fetch).
        foreach ($confirmedRows as $row) {
            $tasks = is_array($row->completion_tasks) ? $row->completion_tasks : [];

            if (!isset($tasks['post_approval']) || $tasks['post_approval']['status'] === 'completed') {
                continue;
            }
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

            $matches[] = [$row, $tasks, 'post_approval', []];
            $counts['post_approval']++;
        }

        // Hydrate (get_userdata/get_post) only the requested page.
        $total = count($matches);
        $items = array_map(
            fn(array $match): array => $this->buildApprovalItem(...$match),
            array_slice($matches, ($page - 1) * $perPage, $perPage),
        );

        // CR-E2: a scan query that fills the cap may have left qualifying
        // rows unscanned — counts/items are then lower bounds, not the queue.
        $clipped = count($pendingRows) >= self::APPROVALS_SCAN_CAP
            || count($confirmedRows) >= self::APPROVALS_SCAN_CAP;

        return new WP_REST_Response([
            'items' => $items,
            'counts' => $counts,
            'clipped' => $clipped,
            'stale_threshold_days' => $staleDays,
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'totalPages' => (int) ceil($total / $perPage),
        ]);
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
                    $today,
                ), ARRAY_A);
                $data['editions'] = $editions ?: [];
            }

            // Pending approvals
            if (!empty($rules['pending_approval']['enabled']) && $registrationTableExists) {
                $pending = $wpdb->get_results(
                    "SELECT id FROM {$registrationTable} WHERE status = 'pending'",
                    ARRAY_A,
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
                    $cutoff,
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
                    $approachDate,
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
                    $startDate,
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
                     AND r.completion_tasks IS NOT NULL
                     AND r.completion_tasks LIKE %s
                     AND r.registered_at < %s",
                    '%"completed":false%',
                    $taskCutoff,
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
                "SELECT MAX(registered_at) FROM {$registrationTable}",
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
                'quote.sent',
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
            $today,
        ));

        $service = new HealthCheckService();
        $checks = $service->evaluate(
            $lastRegistration,
            $lastMailSend,
            $hasOpenEditions,
            // AF-2 residual: PII-reveal audit trail inactive = red flag.
            class_exists(\NTDST\Audit\AuditService::class),
        );

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
            $limit,
        ));

        if (empty($entries)) {
            return new WP_REST_Response([]);
        }

        // Collect actor IDs AND target user IDs (entity_type=user) for one batch fetch
        $userIdsToResolve = [];
        foreach ($entries as $entry) {
            if (!empty($entry->actor_id)) {
                $userIdsToResolve[] = (int) $entry->actor_id;
            }
            if (($entry->entity_type ?? '') === 'user' && !empty($entry->entity_id)) {
                $userIdsToResolve[] = (int) $entry->entity_id;
            }
        }

        $usersMap = !empty($userIdsToResolve)
            ? BatchQueryHelper::batchGetUsers(array_unique($userIdsToResolve))
            : [];

        $entries = $this->enrichAuditContexts($entries);

        $feed = [];
        foreach ($entries as $entry) {
            // Skip raw/system events that don't have a user-friendly label
            if (!AdminActivityMapper::isKnownAction($entry)) {
                continue;
            }

            $actorId = (int) ($entry->actor_id ?? 0);
            $actor = $usersMap[$actorId] ?? null;
            $actorName = $actor ? $actor->display_name : __('Systeem', 'stride');

            // Resolve target name from entity_id for user.* events
            $targetName = '';
            if (($entry->entity_type ?? '') === 'user' && !empty($entry->entity_id)) {
                $targetUser = $usersMap[(int) $entry->entity_id] ?? null;
                if ($targetUser) {
                    $targetName = $targetUser->display_name;
                }
            }

            $feed[] = AdminActivityMapper::fromAuditEntry($entry, $actorName, $targetName);
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

        $results = $userQuery->get_results();
        if (empty($results)) {
            return new WP_REST_Response([]);
        }

        // Prime user-meta cache once so the per-row get_user_meta() call below
        // is a cache hit — drops 10 queries on a full result set.
        $userIds = array_map(static fn($u) => (int) $u->ID, $results);
        update_meta_cache('user', $userIds);

        // Aggregate registration counts in a single GROUP BY query instead of
        // one COUNT(*) per row.
        $counts = $this->batchCountUserRegistrations($userIds);

        $users = array_map(static function ($user) use ($counts) {
            $userId = (int) $user->ID;
            return [
                'id' => $userId,
                'name' => $user->display_name,
                'email' => $user->user_email,
                'organisation' => get_user_meta($userId, 'organisation', true) ?: '',
                'registration_count' => $counts[$userId] ?? 0,
            ];
        }, $results);

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

        // Sensitive fields (phone, audit trail, full quote listing) are only
        // returned to stride_manage. stride_view (read-only Supervisor role)
        // gets the safe subset — without this, a Supervisor can dump the
        // entire user base via /admin/users/{id}/detail.
        $canSeeSensitive = current_user_can('stride_manage');

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

        $anonymisedAt = (int) get_user_meta($userId, '_stride_anonymised_at', true);
        $isAnonymised = $anonymisedAt > 0;

        $anonymiseUrl = null;
        if (!$isAnonymised && current_user_can('edit_user', $userId) && $userId !== get_current_user_id()) {
            $anonymiseUrl = wp_nonce_url(
                admin_url('admin-post.php?action=stride_anonymise_user&user=' . $userId),
                'stride_anonymise_user_' . $userId,
            );
        }

        $sensitivePlaceholder = '••••••';

        $rawNationalId = get_user_meta($userId, 'national_id', true) ?: '';
        $rawDateOfBirth = get_user_meta($userId, 'date_of_birth', true) ?: '';
        $rawLicense = get_user_meta($userId, 'professional_license_number', true) ?: '';

        $user = [
            'id' => $userId,
            'first_name' => $userData->first_name ?? '',
            'last_name' => $userData->last_name ?? '',
            'display_name' => $userData->display_name,
            'email' => $userData->user_email,
            'phone' => $canSeeSensitive ? (get_user_meta($userId, 'phone', true) ?: '') : '',
            'organisation' => get_user_meta($userId, 'organisation', true) ?: '',
            'department' => get_user_meta($userId, 'department', true) ?: '',

            // Sensitive identity fields — read-only masked for non-managers.
            // Boolean flag tells the UI whether to show a "reveal" affordance.
            'national_id' => $canSeeSensitive && $rawNationalId !== '' ? $sensitivePlaceholder : '',
            'national_id_present' => $rawNationalId !== '',
            'date_of_birth' => $canSeeSensitive && $rawDateOfBirth !== '' ? $sensitivePlaceholder : '',
            'date_of_birth_present' => $rawDateOfBirth !== '',
            'professional_license_number' => $canSeeSensitive && $rawLicense !== '' ? $sensitivePlaceholder : '',
            'professional_license_number_present' => $rawLicense !== '',

            // Billing
            'billing_company' => get_user_meta($userId, 'billing_company', true) ?: '',
            'billing_vat' => get_user_meta($userId, 'billing_vat', true) ?: '',
            'billing_address_1' => get_user_meta($userId, 'billing_address_1', true) ?: '',
            'billing_postcode' => get_user_meta($userId, 'billing_postcode', true) ?: '',
            'billing_city' => get_user_meta($userId, 'billing_city', true) ?: '',
            'invoice_email' => get_user_meta($userId, 'invoice_email', true) ?: '',
            'gln_number' => get_user_meta($userId, 'gln_number', true) ?: '',

            'profile_type' => $profileType,
            'is_anonymised' => $isAnonymised,
            'anonymised_label' => $isAnonymised
                ? sprintf(__('Geanonimiseerd op %s', 'stride'), date_i18n('d M Y', $anonymisedAt))
                : '',
            'anonymise_url' => $anonymiseUrl,
        ];

        // --- Registrations (paginated, with edition title) ---
        $registrations = [];
        $registrationsTotal = 0;
        $registrationTable = RegistrationTable::getTableName();

        if (RegistrationTable::exists()) {
            $registrationsTotal = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$registrationTable} WHERE user_id = %d",
                $userId,
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
                $regOffset,
            ));

            // Pre-fetch attendance stats + total session counts for all loaded editions,
            // so each row carries actionable info without N+1 queries.
            $editionIds = array_map(static fn($r) => (int) $r->edition_id, $regRows);
            $editionIds = array_values(array_unique(array_filter($editionIds)));

            $attendanceByEdition = $this->fetchUserAttendanceByEdition($userId, $editionIds);
            $sessionCountByEdition = $this->fetchSessionCountByEdition($editionIds);

            foreach ($regRows as $row) {
                $editionId = (int) $row->edition_id;
                $att = $attendanceByEdition[$editionId] ?? null;
                $totalSessions = $sessionCountByEdition[$editionId] ?? 0;
                $attendanceSummary = null;

                if ($totalSessions > 0) {
                    $present = $att['present'] ?? 0;
                    $absent = $att['absent'] ?? 0;
                    $excused = $att['excused'] ?? 0;
                    $hours = $att['hours'] ?? 0;
                    $attendanceSummary = [
                        'present' => $present,
                        'absent' => $absent,
                        'excused' => $excused,
                        'total_sessions' => $totalSessions,
                        'hours' => $hours,
                    ];
                }

                $registrations[] = [
                    'id' => (int) $row->id,
                    'edition_id' => $editionId,
                    'edition_title' => $row->edition_title ?: __('Onbekend', 'stride'),
                    'status' => $row->status,
                    'enrollment_path' => $row->enrollment_path,
                    'registered_at' => $row->registered_at,
                    'completed_at' => $row->completed_at,
                    'cancelled_at' => $row->cancelled_at,
                    'has_sessions' => $totalSessions > 0,
                    'attendance' => $attendanceSummary,
                ];
            }
        }

        // --- Quotes (linked to user by user_id meta or billing email) ---
        //
        // WP_Query meta_query OR forces a double LEFT JOIN with no covering
        // index, then the per-row get_post_meta() loop adds 5-7 lookups per
        // quote. Mirror getQuotes() instead: one SELECT with explicit joins,
        // one BatchQueryHelper::batchGetPostMeta() for everything we need.
        $quotePosts = $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT p.ID, p.post_title, p.post_date
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm_user
               ON pm_user.post_id = p.ID AND pm_user.meta_key = 'user_id'
             LEFT JOIN {$wpdb->postmeta} pm_email
               ON pm_email.post_id = p.ID AND pm_email.meta_key = 'billing_email'
             WHERE p.post_type = %s
               AND p.post_status = 'publish'
               AND (pm_user.meta_value = %s OR pm_email.meta_value = %s)
             ORDER BY p.post_date DESC
             LIMIT 20",
            QuoteCPT::POST_TYPE,
            (string) $userId,
            $userData->user_email,
        ));

        $quoteIds = array_map(static fn($q) => (int) $q->ID, $quotePosts);
        $quoteMeta = BatchQueryHelper::batchGetPostMeta($quoteIds, [
            'quote_number', 'status', 'total', 'edition_id',
            'sent_at', 'paid_at', 'valid_until',
        ]);

        $quoteEditionIds = array_values(array_unique(array_filter(array_map(
            static fn($id) => (int) ($quoteMeta[$id]['edition_id'] ?? 0),
            $quoteIds,
        ))));
        $quoteEditions = BatchQueryHelper::batchGetPosts($quoteEditionIds, EditionCPT::POST_TYPE);

        $quotes = [];
        foreach ($quotePosts as $quotePost) {
            $quoteId = (int) $quotePost->ID;
            $meta = $quoteMeta[$quoteId] ?? [];

            $quoteEditionId = (int) ($meta['edition_id'] ?? 0);
            $quoteStatus = (string) ($meta['status'] ?? '');
            $statusEnum = QuoteStatus::tryFrom($quoteStatus);

            $quotes[] = [
                'id' => $quoteId,
                'title' => $quotePost->post_title,
                'number' => (string) ($meta['quote_number'] ?? ''),
                'edition_id' => $quoteEditionId,
                'edition_title' => isset($quoteEditions[$quoteEditionId]) ? $quoteEditions[$quoteEditionId]->post_title : '',
                'status' => $quoteStatus,
                'status_label' => $statusEnum?->label() ?? $quoteStatus,
                'total' => Money::cents((int) ($meta['total'] ?? 0))->amount(),
                'created_at' => $quotePost->post_date,
                'sent_at' => ($meta['sent_at'] ?? '') ?: null,
                'paid_at' => ($meta['paid_at'] ?? '') ?: null,
                'valid_until' => ($meta['valid_until'] ?? '') ?: null,
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
                $userId,
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

            // Enrich with total session count + hours per edition
            $summaryEditionIds = array_keys($grouped);
            if (!empty($summaryEditionIds)) {
                $sessionCounts = $this->fetchSessionCountByEdition($summaryEditionIds);
                $hoursByEdition = $this->fetchUserAttendanceByEdition($userId, $summaryEditionIds);
                foreach ($grouped as $editionId => &$row) {
                    $row['total_sessions'] = $sessionCounts[$editionId] ?? 0;
                    $row['hours'] = $hoursByEdition[$editionId]['hours'] ?? 0;
                }
                unset($row);
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
                $userId,
            ));

            $auditEntries = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$auditTable}
                 WHERE actor_id = %d OR (entity_type = 'user' AND entity_id = %d)
                 ORDER BY created_at DESC
                 LIMIT 50",
                $userId,
                $userId,
            ));

            // Collect actor IDs AND target user IDs for batch fetch
            $userIdsToResolve = [];
            foreach ($auditEntries as $entry) {
                if (!empty($entry->actor_id)) {
                    $userIdsToResolve[] = (int) $entry->actor_id;
                }
                if (($entry->entity_type ?? '') === 'user' && !empty($entry->entity_id)) {
                    $userIdsToResolve[] = (int) $entry->entity_id;
                }
            }
            $usersMap = !empty($userIdsToResolve)
                ? BatchQueryHelper::batchGetUsers(array_unique($userIdsToResolve))
                : [];

            $auditEntries = $this->enrichAuditContexts($auditEntries);

            foreach ($auditEntries as $entry) {
                $actorId = (int) ($entry->actor_id ?? 0);
                $actorUser = $usersMap[$actorId] ?? null;
                $actorName = $actorUser ? $actorUser->display_name : __('Systeem', 'stride');

                $targetName = '';
                if (($entry->entity_type ?? '') === 'user' && !empty($entry->entity_id)) {
                    $targetUser = $usersMap[(int) $entry->entity_id] ?? null;
                    if ($targetUser) {
                        $targetName = $targetUser->display_name;
                    }
                }

                $auditTrail[] = AdminActivityMapper::fromAuditEntry($entry, $actorName, $targetName);
            }
        }

        return new WP_REST_Response([
            'user' => $user,
            'registrations' => $registrations,
            'registrations_total' => $registrationsTotal,
            'quotes' => $canSeeSensitive ? $quotes : [],
            'attendance' => $attendance,
            'audit_trail' => $canSeeSensitive ? $auditTrail : [],
            'audit_trail_total' => $canSeeSensitive ? $auditTrailTotal : 0,
        ]);
    }

    /**
     * POST /admin/users/{id}/profile
     *
     * Updates personal + billing user data. Delegates persistence to
     * {@see EnrollmentService::updateUserProfile()} so admin edits and
     * enrollment-form edits share one canonical mutator.
     */
    public function updateUserProfile(WP_REST_Request $request): WP_REST_Response
    {
        $userId = (int) $request->get_param('id');

        $userData = get_userdata($userId);
        if (!$userData) {
            return new WP_REST_Response(['error' => 'User not found'], 404);
        }

        if (!current_user_can('edit_user', $userId)) {
            return new WP_REST_Response(['error' => 'Forbidden'], 403);
        }

        if ((int) get_user_meta($userId, '_stride_anonymised_at', true) > 0) {
            return new WP_REST_Response([
                'error' => __('Geanonimiseerde gebruikers kunnen niet bewerkt worden.', 'stride'),
            ], 403);
        }

        $body = $request->get_json_params() ?: $request->get_body_params();

        // Update WP user core fields (name/email).
        $coreUpdate = ['ID' => $userId];
        $hasCoreChange = false;

        if (array_key_exists('first_name', $body)) {
            $coreUpdate['first_name'] = sanitize_text_field((string) $body['first_name']);
            $hasCoreChange = true;
        }
        if (array_key_exists('last_name', $body)) {
            $coreUpdate['last_name'] = sanitize_text_field((string) $body['last_name']);
            $hasCoreChange = true;
        }
        if ($hasCoreChange) {
            $coreUpdate['display_name'] = trim(
                ($coreUpdate['first_name'] ?? $userData->first_name ?? '')
                . ' '
                . ($coreUpdate['last_name'] ?? $userData->last_name ?? ''),
            );
        }

        if (array_key_exists('email', $body)) {
            $email = sanitize_email((string) $body['email']);
            if ($email === '' || !is_email($email)) {
                return new WP_REST_Response([
                    'error' => __('Ongeldig e-mailadres.', 'stride'),
                ], 400);
            }
            $existing = email_exists($email);
            if ($existing && (int) $existing !== $userId) {
                return new WP_REST_Response([
                    'error' => __('Dit e-mailadres is al in gebruik.', 'stride'),
                ], 400);
            }
            $coreUpdate['user_email'] = $email;
            $hasCoreChange = true;
        }

        if ($hasCoreChange) {
            $result = wp_update_user($coreUpdate);
            if (is_wp_error($result)) {
                return new WP_REST_Response([
                    'error' => $result->get_error_message(),
                ], 400);
            }
        }

        // Map request keys to the EnrollmentService::getUserMetaMapping() input keys.
        $mapping = EnrollmentService::getUserMetaMapping();
        $profileData = [];
        foreach (array_keys($mapping) as $inputKey) {
            if (array_key_exists($inputKey, $body)) {
                $profileData[$inputKey] = $body[$inputKey];
            }
        }

        if (!empty($profileData)) {
            /** @var EnrollmentService $enrollment */
            $enrollment = ntdst_get(EnrollmentService::class);
            $enrollment->updateUserProfile($userId, $profileData);
        }

        // Audit (event name matches AdminActivityMapper's existing slot).
        $audit = ntdst_get(\NTDST\Audit\AuditService::class);
        if ($audit) {
            $audit->record(
                'user',
                $userId,
                'user.profile_updated',
                get_current_user_id() ?: null,
                [
                    'target_user_id' => $userId,
                    'fields' => array_keys($profileData) + ($hasCoreChange ? ['core'] : []),
                ],
            );
        }

        return new WP_REST_Response([
            'success' => true,
            'message' => __('Gebruiker bijgewerkt.', 'stride'),
        ]);
    }

    /**
     * GET /admin/users/{id}/reveal?field=...
     *
     * Returns the raw value of one sensitive identity field. Admin-only,
     * one field per call — keeps reveal explicit. Every successful reveal
     * writes an audit row (threat-model M5): the access attempt is the
     * event, even when the stored value is empty.
     */
    public function revealSensitiveField(WP_REST_Request $request): WP_REST_Response
    {
        $userId = (int) $request->get_param('id');
        $field = (string) $request->get_param('field');

        $allowed = ['national_id', 'date_of_birth', 'professional_license_number', 'phone'];
        if (!in_array($field, $allowed, true)) {
            return new WP_REST_Response(['error' => 'Invalid field'], 400);
        }

        if (!get_userdata($userId)) {
            return new WP_REST_Response(['error' => 'User not found'], 404);
        }

        $value = get_user_meta($userId, $field, true) ?: '';

        // Audit the PII access. A failed audit write is logged but does NOT
        // block the reveal (availability over strictness — AF-2 ruling).
        // class_exists guard: the container THROWS on unresolvable ids, so
        // resolving without it would 500 when ntdst-audit is deactivated
        // (review finding CR-B2) — check availability before resolving.
        $audit = class_exists(\NTDST\Audit\AuditService::class)
            ? ntdst_get(\NTDST\Audit\AuditService::class)
            : null;
        $recorded = $audit
            ? $audit->record('user', $userId, 'admin.pii_reveal', null, ['field' => $field])
            : new WP_Error('audit_unavailable', 'AuditService not available');

        if (is_wp_error($recorded)) {
            ntdst_log('audit')->warning('PII reveal audit write failed', [
                'user_id' => $userId,
                'field' => $field,
                'error' => $recorded->get_error_message(),
            ]);
        }

        return new WP_REST_Response([
            'field' => $field,
            'value' => $value,
        ]);
    }

    /**
     * Batch-load edition / course titles referenced by a set of audit
     * entries and inject them into each entry's encoded JSON context so the
     * mapper can render full activity strings without per-row queries.
     *
     * Mutates entries in place and returns them for convenience.
     *
     * @param array<object> $entries
     * @return array<object>
     */
    private function enrichAuditContexts(array $entries): array
    {
        if (empty($entries)) {
            return $entries;
        }

        $postIds = [];
        $decoded = [];

        foreach ($entries as $i => $entry) {
            $ctx = json_decode($entry->context ?? '{}', true) ?: [];
            $decoded[$i] = $ctx;
            if (!empty($ctx['edition_id'])) {
                $postIds[] = (int) $ctx['edition_id'];
            }
            if (!empty($ctx['course_id']) && empty($ctx['course_title'])) {
                $postIds[] = (int) $ctx['course_id'];
            }
            if (!empty($ctx['quote_id'])) {
                $postIds[] = (int) $ctx['quote_id'];
            }
        }

        $titles = $this->fetchPostTitles(array_values(array_unique(array_filter($postIds))));

        foreach ($entries as $i => $entry) {
            $ctx = $decoded[$i];
            $editionId = (int) ($ctx['edition_id'] ?? 0);
            $courseId = (int) ($ctx['course_id'] ?? 0);

            if ($editionId > 0 && empty($ctx['edition_title']) && isset($titles[$editionId])) {
                $ctx['edition_title'] = $titles[$editionId];
            }
            if ($courseId > 0 && empty($ctx['course_title']) && isset($titles[$courseId])) {
                $ctx['course_title'] = $titles[$courseId];
            }

            $entry->context = wp_json_encode($ctx);
        }

        return $entries;
    }

    /**
     * Batch fetch post_title for a set of IDs.
     *
     * @param array<int> $postIds
     * @return array<int, string>
     */
    private function fetchPostTitles(array $postIds): array
    {
        if (empty($postIds)) {
            return [];
        }
        global $wpdb;
        $placeholders = implode(',', array_fill(0, count($postIds), '%d'));
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT ID, post_title FROM {$wpdb->posts} WHERE ID IN ({$placeholders})",
            ...$postIds,
        ));
        $titles = [];
        foreach ($rows as $row) {
            $titles[(int) $row->ID] = (string) $row->post_title;
        }
        return $titles;
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
            $userId,
        ));
    }

    /**
     * Batch-count registrations for a list of users in a single query.
     *
     * @param int[] $userIds
     * @return array<int,int> user_id => count (missing user_ids return 0 via caller fallback)
     */
    private function batchCountUserRegistrations(array $userIds): array
    {
        if (empty($userIds) || !RegistrationTable::exists()) {
            return [];
        }

        global $wpdb;
        $table = RegistrationTable::getTableName();
        $userIds = array_values(array_unique(array_map('intval', $userIds)));
        $placeholders = implode(',', array_fill(0, count($userIds), '%d'));

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT user_id, COUNT(*) AS cnt FROM {$table}
             WHERE user_id IN ({$placeholders})
             GROUP BY user_id",
            ...$userIds,
        ));

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row->user_id] = (int) $row->cnt;
        }
        return $counts;
    }

    /**
     * Count sessions per edition for a set of edition IDs.
     *
     * Returns [edition_id => session_count]. Editions with no sessions are absent
     * from the map; callers should treat missing keys as 0 (no sessions ⇒ e-learning).
     *
     * @param array<int> $editionIds
     * @return array<int, int>
     */
    private function fetchSessionCountByEdition(array $editionIds): array
    {
        if (empty($editionIds)) {
            return [];
        }

        global $wpdb;
        $placeholders = implode(',', array_fill(0, count($editionIds), '%d'));
        $params = array_merge([SessionCPT::POST_TYPE], $editionIds);

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT pm.meta_value AS edition_id, COUNT(*) AS cnt
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_ntdst_edition_id'
             WHERE p.post_type = %s
               AND p.post_status = 'publish'
               AND pm.meta_value IN ({$placeholders})
             GROUP BY pm.meta_value",
            ...$params,
        ));

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row->edition_id] = (int) $row->cnt;
        }
        return $map;
    }

    /**
     * Aggregate attendance for a user across a set of editions.
     *
     * Returns [edition_id => [present, absent, excused, hours]]. Hours assumes
     * 4 hours per "present" session (current convention in the user-detail
     * attendance summary). Editions with no recorded attendance are absent.
     *
     * @param array<int> $editionIds
     * @return array<int, array{present:int, absent:int, excused:int, hours:int}>
     */
    private function fetchUserAttendanceByEdition(int $userId, array $editionIds): array
    {
        if (empty($editionIds) || !AttendanceTable::exists()) {
            return [];
        }

        global $wpdb;
        $attendanceTable = AttendanceTable::getTableName();
        $placeholders = implode(',', array_fill(0, count($editionIds), '%d'));
        $params = array_merge([$userId], $editionIds);

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT edition_id, status, COUNT(*) AS cnt
             FROM {$attendanceTable}
             WHERE user_id = %d
               AND edition_id IN ({$placeholders})
             GROUP BY edition_id, status",
            ...$params,
        ));

        $map = [];
        foreach ($rows as $row) {
            $editionId = (int) $row->edition_id;
            if (!isset($map[$editionId])) {
                $map[$editionId] = ['present' => 0, 'absent' => 0, 'excused' => 0, 'hours' => 0];
            }
            if (isset($map[$editionId][$row->status])) {
                $map[$editionId][$row->status] = (int) $row->cnt;
            }
        }

        // Hours = present count × 4 (matches existing convention)
        foreach ($map as &$entry) {
            $entry['hours'] = $entry['present'] * 4;
        }
        unset($entry);

        return $map;
    }

    /**
     * Convert quote line-item money fields from cents (storage) to euros (API).
     * Storage fields: unit_price, total. Other fields pass through unchanged.
     */
    private function lineItemsToEuros(array $items): array
    {
        return array_map(static function ($item) {
            if (!is_array($item)) {
                return $item;
            }
            if (isset($item['unit_price'])) {
                $item['unit_price'] = Money::cents((int) $item['unit_price'])->amount();
            }
            if (isset($item['total'])) {
                $item['total'] = Money::cents((int) $item['total'])->amount();
            }
            return $item;
        }, $items);
    }

    /**
     * Build a pending approval item for the REST response.
     */
    /**
     * @param array<string, mixed> $extra Bucket-specific extra fields (e.g. open_task, days_idle)
     */
    private function buildApprovalItem(object $row, array $tasks, string $type, array $extra = []): array
    {
        $userId = (int) $row->user_id;
        $user = get_userdata($userId);
        $editionId = (int) ($row->edition_id ?? 0);
        $edition = $editionId ? get_post($editionId) : null;

        return array_merge([
            'id' => (int) $row->id,
            'type' => $type,
            'user_id' => $userId,
            'user_name' => $user ? $user->display_name : __('Onbekend', 'stride'),
            'user_email' => $user ? $user->user_email : '',
            'edition_id' => $editionId,
            'edition_title' => $edition ? $edition->post_title : __('Onbekend', 'stride'),
            'registered_at' => $row->registered_at,
            'tasks' => $tasks,
        ], $extra);
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
        $handler->storeSession($token, $adminId, $targetId);

        // Audit trail via AuditService::record() — the single write path that
        // owns the entity_type/entity_id schema, JSON-encodes context, stamps
        // created_at, and logs. (Previously a raw $wpdb->insert here bypassed it.)
        $audit = ntdst_get(\NTDST\Audit\AuditService::class);
        if ($audit) {
            $audit->record(
                'user',
                $targetId,
                'impersonation.started',
                $adminId,
                [
                    'target_name'  => $targetUser->display_name,
                    'target_email' => $targetUser->user_email,
                ],
            );
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
        $session = $handler->getSession($token);
        $adminId = $session['admin_id'] ?? 0;
        $targetId = $session['target_id'] ?? 0;
        $callerId = get_current_user_id();

        // Caller-is-target check: the only user allowed to walk back into the
        // original admin's session is the user who is currently impersonated.
        // Without this, anyone who steals the auth cookie of an impersonated
        // user (XSS, session theft) could escalate to admin by hitting /end.
        if ($adminId <= 0 || $callerId <= 0 || ($targetId > 0 && $callerId !== $targetId)) {
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

        // Symmetric audit row — same single write path as impersonation.started.
        $audit = ntdst_get(\NTDST\Audit\AuditService::class);
        if ($audit) {
            $targetUser = $targetId > 0 ? get_userdata($targetId) : null;
            $audit->record(
                'user',
                $targetId,
                'impersonation.ended',
                $adminId,
                [
                    'target_name'  => $targetUser?->display_name,
                    'target_email' => $targetUser?->user_email,
                ],
            );
        }

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
        $table = $wpdb->prefix . 'audit_log';

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

        $entries = $this->enrichAuditContexts($entries ?: []);

        $notifications = array_map(function ($entry) use ($lastReadId) {
            $actorName = '';
            if (!empty($entry->actor_id)) {
                $user = get_userdata((int) $entry->actor_id);
                $actorName = $user ? $user->display_name : 'Onbekend';
            }
            $mapped = AdminActivityMapper::fromAuditEntry($entry, $actorName);
            $mapped['read'] = $mapped['id'] <= $lastReadId;

            return $mapped;
        }, $entries);

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
        $table = $wpdb->prefix . 'audit_log';
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

        // Get confirmed registrations for upcoming editions. Columns are
        // enumerated (panel perf SF-1): r.* dragged the completion_tasks +
        // enrollment_data JSON blobs into memory for every row while the CSV
        // reads five scalars — O(rows x blob) at production scale.
        $today = current_time('Y-m-d');
        $registrations = $wpdb->get_results($wpdb->prepare(
            "SELECT r.id, r.user_id, r.status,
                    p.post_title as edition_title,
                    pm_date.meta_value as edition_date
             FROM {$table} r
             LEFT JOIN {$wpdb->posts} p ON r.edition_id = p.ID
             LEFT JOIN {$wpdb->postmeta} pm_date ON r.edition_id = pm_date.post_id AND pm_date.meta_key = '_ntdst_start_date'
             WHERE r.status = 'confirmed'
             AND (pm_date.meta_value >= %s OR pm_date.meta_value IS NULL)
             ORDER BY pm_date.meta_value ASC, r.registered_at ASC",
            $today,
        ));

        // Set download headers. nosniff mirrors the universal file-response
        // posture (NTDST_Response::fileHeaders / M4): a browser must never
        // content-sniff an attacker-influenced CSV into HTML.
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="inschrijvingen-' . date('Y-m-d') . '.csv"');
        header('X-Content-Type-Options: nosniff');
        header('Pragma: no-cache');
        header('Expires: 0');

        $output = fopen('php://output', 'w');
        // BOM for Excel UTF-8 compatibility
        fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

        // Header row (semicolons for Dutch Excel)
        fputcsv($output, ['Naam', 'E-mail', 'Organisatie', 'Editie', 'Datum', 'Status', 'Offerte #'], ';');

        // Batch-prime all per-row lookups so the export's query count stays
        // constant regardless of row count (audit 2.6): one user fetch + one
        // user-meta prime + one quote map + one quote-meta fetch replace the
        // previous ~4 queries per row. A missing user (deleted account) maps
        // to null and renders as blank cells, same as get_userdata() === false.
        $userIds = array_values(array_unique(array_filter(array_map(
            static fn($reg) => (int) ($reg->user_id ?? 0),
            $registrations,
        ))));
        $users = BatchQueryHelper::batchGetUsers($userIds);
        if ($userIds !== []) {
            update_meta_cache('user', $userIds);
        }

        $registrationIds = array_values(array_filter(array_map(
            static fn($reg) => (int) ($reg->id ?? 0),
            $registrations,
        )));
        $quoteIdsByRegistration = ntdst_get(\Stride\Modules\Invoicing\QuoteRepository::class)
            ->findQuoteIdsByRegistrations($registrationIds);
        $quoteMeta = BatchQueryHelper::batchGetPostMeta(
            array_values($quoteIdsByRegistration),
            ['quote_number'],
        );

        foreach ($registrations as $reg) {
            $user = $users[(int) ($reg->user_id ?? 0)] ?? null;
            $name = $user ? $user->display_name : 'Onbekend';
            $email = $user ? $user->user_email : '';
            $org = $user ? (get_user_meta($user->ID, 'organisation', true) ?: '') : '';

            // Linked quote number from the batched map (fallback mirrors the
            // old per-row behaviour: 'Q-' . post ID when the meta is empty).
            $quoteNumber = '';
            $quoteId = $quoteIdsByRegistration[(int) ($reg->id ?? 0)] ?? 0;
            if ($quoteId) {
                $quoteNumber = (string) ($quoteMeta[$quoteId]['quote_number'] ?? '') ?: 'Q-' . $quoteId;
            }

            fputcsv($output, array_map([self::class, 'sanitizeCsvCell'], [
                $name,
                $email,
                $org,
                $reg->edition_title ?? '',
                $reg->edition_date ?? '',
                $reg->status ?? '',
                $quoteNumber,
            ]), ';');
        }

        fclose($output);
        exit;
    }

    /**
     * Neutralise CSV / spreadsheet formula injection.
     *
     * Excel, LibreOffice and Google Sheets execute any cell whose first
     * character is `=`, `+`, `-`, `@`, TAB or CR. An attacker who can place
     * arbitrary text into a user-facing field (display_name, organisation,
     * edition title) could exfiltrate data via `=WEBSERVICE(...)` when an
     * admin opens the export. Prefix any such cell with a single quote so
     * the spreadsheet treats it as a literal string.
     */
    private static function sanitizeCsvCell(mixed $value): string
    {
        $str = (string) $value;
        if ($str === '') {
            return '';
        }
        $first = $str[0];
        if ($first === '=' || $first === '+' || $first === '-' || $first === '@' || $first === "\t" || $first === "\r") {
            return "'" . $str;
        }
        return $str;
    }
}
