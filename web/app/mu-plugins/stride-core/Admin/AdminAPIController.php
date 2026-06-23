<?php

declare(strict_types=1);

namespace Stride\Admin;

use NTDST\Audit\AuditTable;
use Stride\Admin\Support\AdminBatchHelpers;
use Stride\Domain\AttendanceStatus;
use Stride\Domain\Money;
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
    // Shared audit-context hydration (enrichAuditContexts + fetchPostTitles)
    // — cross-domain, also used by AdminUserService::getUserDetail (S2).
    use AdminBatchHelpers;

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

        // Case-view trajectory progress (§11.4 / F8) — a SEPARATE lazy fetch
        // from /detail (the Dossier section loads it independently).
        register_rest_route(self::NAMESPACE, '/admin/users/(?P<id>\d+)/trajectories', [
            'methods' => 'GET',
            'callback' => [$this, 'getUserTrajectories'],
            'permission_callback' => [$this, 'canViewAdmin'],
            'args' => [
                'id' => ['type' => 'integer', 'required' => true],
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
        // Thin delegator — all $wpdb / read-model assembly lives in
        // AdminStatsService (strangle §12.4 / S1, INV-3).
        $service = ntdst_get(\Stride\Admin\AdminStatsService::class);

        return new WP_REST_Response($service->getStats());
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
        $q = sanitize_text_field((string) ($request->get_param('q') ?? ''));

        $scope = (string) ($request->get_param('scope') ?? 'active');
        if (!in_array($scope, ['active', 'all'], true)) {
            $scope = 'active';
        }

        $page = max(1, absint($request->get_param('page')));
        $perPage = absint($request->get_param('per_page'));
        $perPage = $perPage > 0 ? min($perPage, 100) : 50;
        $offset = ($page - 1) * $perPage;

        $dateScoped = $scope === 'active';

        // CR-2: scope=active drops editions whose EFFECTIVE status is terminal
        // (INV-7) — a PHP-side decision that pure SQL cannot mirror, since
        // getEffectiveStatus derives status from stored status + dates + session
        // count. The OLD code applied that drop AFTER the SQL LIMIT while `total`
        // was the PRE-filter SQL COUNT, so an active page could come back SHORT
        // and total/perPage/items disagreed → broken typeahead paging.
        //
        // Fix (pragmatic + correct for a typeahead over a small corpus — editions
        // number in the hundreds, not the millions): fetch the date-pre-filtered
        // candidate id+title set (still bounded by the repo WHERE), apply the
        // effective-status filter in PHP, then paginate the FILTERED list in PHP.
        // This guarantees total, perPage, the page slice, and items are mutually
        // consistent. NULL-last ordering is preserved.
        //
        // scope=all has no effective-status drop, so its SQL LIMIT/OFFSET +
        // pre-filter COUNT are already consistent — keep the cheap SQL paging
        // path for it and avoid loading the whole corpus.
        if ($scope === 'all') {
            $total = $this->editionRepository->countEditionOptions($q, $dateScoped);
            $rows = $this->editionRepository->findEditionOptions($q, $dateScoped, $perPage, $offset);

            $editionIds = array_map(static fn($r) => (int) $r->ID, $rows);
            $statuses = [];
            if (!empty($editionIds)) {
                $editionService = ntdst_get(\Stride\Modules\Edition\EditionService::class);
                $statuses = $editionService->getEffectiveStatuses($editionIds);
            }

            $items = [];
            foreach ($rows as $row) {
                $id = (int) $row->ID;
                $status = $statuses[$id] ?? null;
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

        // scope=active: fetch the full date-pre-filtered candidate set (NULL-last
        // ordering), then effective-status-filter + paginate in PHP so the count
        // and the page agree.
        $candidates = $this->editionRepository->findEditionOptions($q, $dateScoped);

        $candidateIds = array_map(static fn($r) => (int) $r->ID, $candidates);
        $statuses = [];
        if (!empty($candidateIds)) {
            $editionService = ntdst_get(\Stride\Modules\Edition\EditionService::class);
            $statuses = $editionService->getEffectiveStatuses($candidateIds);
        }

        // Apply the effective-status filter (INV-7) BEFORE paginating, so total
        // is the count of what actually survives. Terminal editions
        // (Cancelled/Completed/Archived) are dropped; dateless editions are
        // never terminal here, so they survive (sessionless §10.7).
        $filtered = [];
        foreach ($candidates as $row) {
            $id = (int) $row->ID;
            $status = $statuses[$id] ?? null;
            if ($status !== null && $status->isTerminal()) {
                continue;
            }
            $filtered[] = [
                'id' => $id,
                'title' => $row->post_title,
                'effective_status' => $status !== null ? $status->value : '',
            ];
        }

        $total = count($filtered);
        $items = array_slice($filtered, $offset, $perPage);

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
        return ntdst_get(\Stride\Admin\AdminTrajectoryService::class)->getTrajectories($request);
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
        $q = sanitize_text_field((string) ($request->get_param('q') ?? ''));

        $scope = (string) ($request->get_param('scope') ?? 'active');
        if (!in_array($scope, ['active', 'all'], true)) {
            $scope = 'active';
        }

        $page = max(1, absint($request->get_param('page')));
        $perPage = absint($request->get_param('per_page'));
        $perPage = $perPage > 0 ? min($perPage, 100) : 50;
        $offset = ($page - 1) * $perPage;

        $activeOnly = $scope === 'active';

        $trajectoryRepo = ntdst_get(\Stride\Modules\Trajectory\TrajectoryRepository::class);
        $total = $trajectoryRepo->countTrajectoryOptions($q, $activeOnly);
        $rows = $trajectoryRepo->findTrajectoryOptions($q, $activeOnly, $perPage, $offset);

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
        return ntdst_get(\Stride\Admin\AdminTrajectoryService::class)->getTrajectory($request);
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
        $rules = StrideSettingsService::getNotificationRules();

        // SQL data-gathering + rule evaluation + transient caching live in
        // AdminStatsService (strangle §12.4 / S1, INV-3). The per-user
        // dismissal filter below is session state, not SQL — it stays here.
        $items = ntdst_get(\Stride\Admin\AdminStatsService::class)
            ->getActionQueueItems($rules);

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
        // Thin delegator — all $wpdb / read-model assembly lives in
        // AdminUserService (strangle §12.4 / S2, INV-3).
        return ntdst_get(\Stride\Admin\AdminUserService::class)->getUserDetail($request);
    }

    /**
     * GET /admin/users/{id}/trajectories
     *
     * Thin delegator to AdminTrajectoryService::getUserTrajectories — case-view
     * trajectory progress (§11.4 / F8). A separate lazy fetch from /detail.
     */
    public function getUserTrajectories(WP_REST_Request $request): WP_REST_Response
    {
        return ntdst_get(\Stride\Admin\AdminTrajectoryService::class)->getUserTrajectories($request);
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

    // enrichAuditContexts + fetchPostTitles moved to the shared
    // Stride\Admin\Support\AdminBatchHelpers trait (S2) — cross-domain,
    // consumed by getActivityFeed / getNotifications (here, via the trait)
    // AND AdminUserService::getUserDetail.

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

    // fetchSessionCountByEdition + fetchUserAttendanceByEdition moved into
    // AdminUserService as private methods (S2) — their only call sites were
    // inside getUserDetail (verified single-consumer at extraction time).

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
