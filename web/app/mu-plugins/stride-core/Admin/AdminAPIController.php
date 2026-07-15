<?php

declare(strict_types=1);

namespace Stride\Admin;

use Stride\Admin\Mappers\EditionAdminMapper;
use Stride\Domain\AttendanceStatus;
use Stride\Infrastructure\BatchQueryHelper;
use Stride\Modules\Attendance\AttendanceRepository;
use Stride\Modules\Attendance\AttendanceTable;
use Stride\Modules\Edition\EditionCPT;
use Stride\Modules\Edition\EditionRepository;
use Stride\Modules\Edition\SessionCPT;
use Stride\Modules\Edition\SessionRepository;
use Stride\Modules\Enrollment\EnrollmentService;
use Stride\Modules\Enrollment\RegistrationRepository;
use Stride\Modules\Enrollment\RegistrationTable;
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
        private readonly RegistrationRepository $registrationRepository,
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
                'scope' => [
                    // upcoming (default) = the 2-day-lookback cutoff;
                    // all = no default cutoff (F-E2: the lookback was silent
                    // and had no UI escape hatch). An explicit date_from
                    // always overrides the default cutoff, either scope.
                    // DELIBERATELY named 'upcoming', not 'active': the
                    // typeahead's scope=active (/admin/editions/options) and
                    // the trajecten scope are STATUS-based (not admin-closed),
                    // while this one is a pure DATE cutoff — two different
                    // boundaries; one word for both would conflate them.
                    'type' => 'string',
                    'default' => 'upcoming',
                    'enum' => ['upcoming', 'all'],
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

        // Per-edition cohort roster read-model (Phase 2a, Task 2a.3)
        register_rest_route(self::NAMESPACE, '/admin/editions/(?P<id>\d+)/roster', [
            'methods' => 'GET',
            'callback' => [$this, 'getEditionRoster'],
            'permission_callback' => [$this, 'canViewAdmin'],
            'args' => [
                'id' => [
                    'type' => 'integer',
                    'required' => true,
                ],
            ],
        ]);

        // Per-edition exporter download (Phase 2a, Task 2a.10, CM-4).
        // canManageAdmin — stricter than the roster READ (canViewAdmin) because
        // the exporters egress the full, non-field-scoped PII roster.
        register_rest_route(self::NAMESPACE, '/admin/editions/(?P<id>\d+)/export/(?P<type>[a-z]+)', [
            'methods' => 'GET',
            'callback' => [$this, 'getEditionExport'],
            'permission_callback' => [$this, 'canManageAdmin'],
            'args' => [
                'id' => [
                    'type' => 'integer',
                    'required' => true,
                ],
                'type' => [
                    'type' => 'string',
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
                'tag' => [
                    'type' => 'integer',
                    'default' => 0,
                ],
                'date_from' => [
                    'type' => 'string',
                    'default' => '',
                ],
                'date_to' => [
                    'type' => 'string',
                    'default' => '',
                ],
            ],
        ]);

        // Quotes CSV export for the Exact Online handoff (F-A9): the exact
        // Offertes predicate (search/status/tag/date), stride_manage gated
        // like every export (PII + financial egress).
        register_rest_route(self::NAMESPACE, '/admin/quotes/export', [
            'methods'             => 'GET',
            'callback'            => [$this, 'getQuotesExport'],
            'permission_callback' => [$this, 'canManageAdmin'],
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
                // minLength stays as server-side defense; the CLIENT guards
                // short queries too (F-U1 — the raw 400 rendered as an
                // English error flash on every 1-character keystroke).
                'q' => ['type' => 'string', 'required' => true, 'minLength' => 2],
                'page' => ['type' => 'integer', 'default' => 1, 'minimum' => 1],
                'per_page' => ['type' => 'integer', 'default' => 25, 'minimum' => 1, 'maximum' => 100],
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
                // Widened (clamped server-side to ≤100) by the dossier's soft
                // refresh so already-loaded pages survive a reload in one request.
                'reg_per_page' => ['type' => 'integer', 'default' => 20],
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
        // Impersonation's real authority is manage_options (full admin), NOT the
        // broader stride_manage coordinator cap. The route gate must match the
        // body's validateTarget authority so a future refactor that trusts the
        // gate cannot silently open impersonation to coordinators (threat #2).
        register_rest_route(self::NAMESPACE, '/admin/users/(?P<id>\d+)/impersonate', [
            'methods' => 'POST',
            'callback' => [$this, 'impersonateUser'],
            'permission_callback' => [$this, 'canImpersonate'],
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

        // "Exporteer huidige weergave" (F-A9): a CSV of the EXACT grid
        // predicate — same params, same scope pins, same composer as the
        // grid read. canManageAdmin like the per-edition exporters: exports
        // egress the full non-field-scoped PII set.
        register_rest_route(self::NAMESPACE, '/admin/registrations/export', [
            'methods'             => 'GET',
            'callback'            => [$this, 'getRegistrationsGridExport'],
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
     * Permission callback for the impersonate route only.
     *
     * Impersonation's real authority is manage_options (full admin), stricter
     * than the stride_manage coordinator cap that gates the other mutation
     * routes. The route gate matches the body's validateTarget authority so the
     * entry-point authorization equals the actual authority (INV-1, threat #2).
     */
    public function canImpersonate(): bool
    {
        return current_user_can('manage_options');
    }

    /**
     * GET /admin/stats
     *
     * Dashboard statistics.
     * Optimized to use batch queries and reduce N+1 patterns.
     */
    public function getStats(WP_REST_Request $request): WP_REST_Response
    {
        // F-V10: ?fresh=1 (the Vernieuwen button) busts THIS endpoint's read
        // caches before the read — the stats transient + the resolver
        // id-sets feeding its queue counts. Endpoint-scoped on purpose: the
        // pulse fires this and /admin/action-queue in PARALLEL, and a full
        // bustCaches() from both raced to delete the transient the sibling
        // request had just repopulated (double recompute per click).
        if ($request->get_param('fresh')) {
            delete_transient(AdminStatsService::STATS_TRANSIENT_KEY);
            AdminStatsService::bumpQueueRev();
        }

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
        // Clamp ceiling (4B.3): the schema declares maximum=100, but read the
        // param defensively so an internal rest_do_request that bypasses arg
        // validation still cannot hydrate everything (per_page=100000).
        $perPage = min(100, max(1, (int) ($request->get_param('per_page') ?: 20)));
        $search = sanitize_text_field($request->get_param('search') ?? '');
        $status = sanitize_text_field($request->get_param('status') ?? '');
        $dateFrom = sanitize_text_field($request->get_param('date_from') ?? '');
        $dateTo = sanitize_text_field($request->get_param('date_to') ?? '');
        $themeId = (int) $request->get_param('theme');
        $formatId = (int) $request->get_param('format');
        $tagId = (int) $request->get_param('tag');
        $scope = (string) ($request->get_param('scope') ?? 'upcoming');
        if (!in_array($scope, ['upcoming', 'all'], true)) {
            $scope = 'upcoming';
        }
        $offset = ($page - 1) * $perPage;

        $today = current_time('Y-m-d');
        $twoDaysAgo = wp_date('Y-m-d', strtotime('-2 days'));

        if ($view === 'agenda') {
            return $this->getEditionsAgendaView($request, $today, $twoDaysAgo, $scope);
        }

        // LIST VIEW: One row per edition
        // Build query with JOIN on start_date meta
        $where = ["p.post_type = %s", "p.post_status = 'publish'"];
        $params = [EditionCPT::POST_TYPE];

        // By default (scope=upcoming), only show editions that haven't passed
        // more than 2 days ago; scope=all lifts the cutoff (F-E2 — the
        // lookback was invisible and inescapable). Permit NULL start_date so
        // dateless editions (no sessions -> no start_date meta, the
        // interest-list anchors) show in the default scope. Same fix the
        // Admin Workspace spec §10.7 / Task 1.2 inherits — see
        // docs/plans/2026-06-13-admin-workspace-spec.md.
        if (empty($dateFrom) && $scope !== 'all') {
            $where[] = "(pm_start.meta_value >= %s OR pm_start.meta_value IS NULL)";
            $params[] = $twoDaysAgo;
        }

        if (!empty($search)) {
            $where[] = "p.post_title LIKE %s";
            $params[] = '%' . $wpdb->esc_like($search) . '%';
        }

        // Status filter matches the EFFECTIVE status — the badge the row
        // actually displays (F-E2). The old stored-meta EXISTS filter
        // disagreed with the rendered label for exactly the rows where they
        // diverge (e.g. stored 'open' + past dates renders "Afgelopen" but
        // matched status=open, not status=completed) — the F-T2 lesson: a
        // filter must speak the vocabulary the surface renders.
        if (!$this->spliceEffectiveStatusFilter($status, 'p.ID', $where, $params, $filterStatusMap)) {
            return $this->emptyEditionsResponse($page, $perPage, 'list');
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

        // Read SQL extracted to EditionRepository (INV-3, strangle Task 2a.4).
        // The §10.7 NULL-permitting default-scope predicate + LEFT JOIN +
        // NULL-last ordering are decided by $where/$whereClause above and
        // reproduced verbatim in the repo. Behavior-preserving move.
        // Ordering: upcoming scope reads ASC (next first); the widened
        // scope=all reads DESC (most recent first) — the widen exists to
        // reach recently-finished editions, which ASC would bury behind the
        // full history. An explicit date range keeps ASC (chronological).
        $order = ($scope === 'all' && empty($dateFrom)) ? 'DESC' : 'ASC';
        $total = $this->editionRepository->countAdminList($whereClause, $params, $tagJoin);
        $editions = $this->editionRepository->findAdminListRows($whereClause, $params, $tagJoin, $perPage, $offset, $order);

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

        // INV-7 (C1): batch-resolve EFFECTIVE status for every visible edition,
        // the same read the typeahead (getEditionOptions) uses, so the grid and
        // the typeahead agree. Passed into the mapper $context; the mapper does
        // NO queries. A status-filtered request already resolved the full
        // corpus — reuse that map instead of resolving again.
        $effectiveStatuses = $filterStatusMap
            ?? (!empty($editionIds)
                ? ntdst_get(\Stride\Modules\Edition\EditionService::class)->getEffectiveStatuses($editionIds)
                : []);

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

            // Check if edition is today. A DATELESS edition (no start_date —
            // the sessionless interest anchors, §10.7) is neither today nor
            // past: the old string comparisons flagged it isPast ('' < today
            // is true) and, with only an end_date, even isToday (F-A8/F-E1 —
            // latent while the list view had no UI caller; it has one now).
            // isPast delegates to THE calendar predicate the effective-status
            // badge runs on (EditionService::isPastDates) — the row flag and
            // the badge must never disagree.
            $isToday = $startDate !== '' && ($startDate === $today || ($endDate !== '' && $startDate <= $today && $endDate >= $today));
            $isPast = \Stride\Modules\Edition\EditionService::isPastDates($endDate ?: null, $startDate ?: null);

            // Common edition->item shaping shared with the agenda view, deduped
            // into EditionAdminMapper (id, course{id,title}, capacity,
            // registeredCount, status, editUrl). status is the EFFECTIVE status
            // (INV-7, C1) resolved from the batched $effectiveStatuses map.
            $base = EditionAdminMapper::toItem([
                'editionId' => $editionId,
                'courseId' => $courseId,
                'courseTitle' => $courseTitle,
                'capacity' => $capacity,
                'registeredCount' => $registeredCount,
                'status' => $editionStatus,
                'effectiveStatuses' => $effectiveStatuses,
            ]);

            // LIST-view-specific keys merged onto the common base. course.tags,
            // the edition title source, the start/end dates and the edition-date-
            // derived isToday/isPast are unique to this view.
            $base['title'] = $edition->post_title;
            $base['course']['tags'] = $courseTagList;
            $base['startDate'] = $startDate ?: null;
            $base['endDate'] = $endDate ?: null;
            // Server-owned Dutch date label (INV-7 — cells rendered raw ISO,
            // F-E2). Empty for a dateless edition; the template shows its own
            // 'Geen datum' state.
            $base['dateLabel'] = $this->editionDateRangeLabel($startDate, $endDate);
            $base['venue'] = $venue ?: null;
            $base['isToday'] = $isToday;
            $base['isPast'] = $isPast;

            $items[] = $base;
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
     *
     * $scope arrives validated from getEditions (the one param-assembly
     * site) — never re-parse it here, or the two views' whitelists drift.
     */
    private function getEditionsAgendaView(WP_REST_Request $request, string $today, string $twoDaysAgo, string $scope): WP_REST_Response
    {
        global $wpdb;

        $page = max(1, (int) ($request->get_param('page') ?: 1));
        $perPage = min(100, max(1, (int) ($request->get_param('per_page') ?: 20))); // clamp ceiling (4B.3)
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

        // Default (scope=upcoming): only show sessions from 2 days ago onwards;
        // scope=all lifts the cutoff (F-E2). Sessions ALWAYS carry a date
        // (INNER JOIN on _ntdst_date in the repo), so unlike the LIST view
        // there is NO §10.7 NULL-permitting carve-out here — dateless editions
        // have no session rows and never appear in the agenda (they live in
        // the LIST view, F-E1). Keep this predicate non-NULL-permitting.
        if (empty($dateFrom) && $scope !== 'all') {
            $where[] = "pm_date.meta_value >= %s";
            $params[] = $twoDaysAgo;
        }

        // Search by edition title
        if (!empty($search)) {
            $where[] = "e.post_title LIKE %s";
            $params[] = '%' . $wpdb->esc_like($search) . '%';
        }

        // Filter by the edition's EFFECTIVE status — the badge the row
        // displays (F-E2; same rationale as the LIST view above).
        if (!$this->spliceEffectiveStatusFilter($status, 'e.ID', $where, $params, $filterStatusMap)) {
            return $this->emptyEditionsResponse($page, $perPage, 'agenda');
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

        // Read SQL extracted to EditionRepository (INV-3, strangle Task 2a.5).
        // The session->edition->date INNER JOINs + the (date, edition) ordering
        // are reproduced verbatim in the repo. The controller keeps ONLY param
        // assembly + the taxonomy-join helper (shared with the LIST view,
        // getEditions). Ordering direction mirrors the LIST view: scope=all
        // without an explicit range reads DESC (most recent first — the widen
        // targets recently-finished editions, which ASC would bury).
        $order = ($scope === 'all' && empty($dateFrom)) ? 'DESC' : 'ASC';
        $total = $this->editionRepository->countAgendaRows($whereClause, $params, $tagJoin);
        $sessions = $this->editionRepository->findAgendaRows($whereClause, $params, $tagJoin, $perPage, $offset, $order);

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

        // INV-7 (C1): batch-resolve EFFECTIVE status for every visible edition
        // (same read as the typeahead) so the agenda grid and the typeahead
        // agree. Passed into the mapper $context; the mapper does NO queries.
        // A status-filtered request already resolved the full corpus — reuse.
        $effectiveStatuses = $filterStatusMap
            ?? (!empty($editionIds)
                ? ntdst_get(\Stride\Modules\Edition\EditionService::class)->getEffectiveStatuses($editionIds)
                : []);

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

            // Common edition->item shaping shared with the list view, deduped
            // into EditionAdminMapper (id, course{id,title}, capacity,
            // registeredCount, status, editUrl). status is the EFFECTIVE status
            // (INV-7, C1) resolved from the batched $effectiveStatuses map.
            $base = EditionAdminMapper::toItem([
                'editionId' => $editionId,
                'courseId' => $courseId,
                'courseTitle' => $courseTitle,
                'capacity' => $capacity,
                'registeredCount' => $registeredCount,
                'status' => $editionStatus,
                'effectiveStatuses' => $effectiveStatuses,
            ]);

            // AGENDA-view-specific keys merged onto the common base. sessionId,
            // sessionTitle, the session date + times, the venue location-fallback
            // and the session-date-derived isToday/isPast are unique to this view.
            $base['sessionId'] = $sessionId;
            $base['title'] = $session->edition_title;
            $base['sessionTitle'] = $session->session_title;
            $base['date'] = $sessionDate;
            // Server-owned Dutch date label (INV-7 — the cell rendered the raw
            // ISO date, F-E2). Falls back to '' on an unparseable date; the
            // template then shows the raw ISO value rather than nothing.
            $base['dateLabel'] = stride_format_date((string) $sessionDate);
            $base['startTime'] = $startTime ?: null;
            $base['endTime'] = $endTime ?: null;
            $base['venue'] = $location ?: $venue ?: null;
            $base['isToday'] = $isToday;
            $base['isPast'] = $isPast;

            $items[] = $base;
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
     * Splice the EFFECTIVE-status filter into a WHERE assembly (F-E2; INV-7:
     * one decision engine — EditionService::findIdsByEffectiveStatus). ONE
     * implementation for the LIST and AGENDA views, so the filter semantics
     * can never drift between the two halves of the same toolbar dropdown.
     *
     * Returns FALSE when the filter matches nothing (incl. an unknown status
     * value — matches nothing, like the old stored-meta equality did); the
     * caller then returns the empty envelope. On a match the id set is
     * spliced as one prepared IN clause (SQL paging stays intact) and
     * $statusMap receives the FULL corpus effective-status map so the
     * caller's badge shaping reuses it — never resolve twice per request.
     *
     * @param array<int, \Stride\Domain\OfferingStatus>|null $statusMap
     */
    private function spliceEffectiveStatusFilter(
        string $status,
        string $idColumn,
        array &$where,
        array &$params,
        ?array &$statusMap,
    ): bool {
        $statusMap = null;
        if ($status === '') {
            return true;
        }

        $enum = \Stride\Domain\OfferingStatus::tryFrom($status);
        $ids = [];
        if ($enum !== null) {
            $result = ntdst_get(\Stride\Modules\Edition\EditionService::class)->findIdsByEffectiveStatus($enum);
            $ids = $result['ids'];
            $statusMap = $result['statuses'];
        }

        if ($ids === []) {
            return false;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $where[] = "{$idColumn} IN ({$placeholders})";
        array_push($params, ...$ids);

        return true;
    }

    /**
     * The shared empty paged envelope for GET /admin/editions (both views).
     */
    private function emptyEditionsResponse(int $page, int $perPage, string $view): WP_REST_Response
    {
        return new WP_REST_Response([
            'items' => [],
            'total' => 0,
            'page' => $page,
            'perPage' => $perPage,
            'totalPages' => 0,
            'view' => $view,
        ]);
    }

    /**
     * Dutch date(-range) label for a LIST-view edition row (F-E2 — cells
     * rendered raw ISO). '' ONLY for a truly dateless edition (§10.7
     * anchors); a bound that exists but fails to parse falls back to its raw
     * ISO value (the agenda path's documented behavior — never hide a date
     * that exists), and a missing bound never leaves a dangling separator.
     */
    private function editionDateRangeLabel(string $startDate, string $endDate): string
    {
        $fmt = static fn(string $d): string => $d === '' ? '' : (stride_format_date($d) ?: $d);

        $start = $fmt($startDate);
        $end = ($endDate !== '' && $endDate !== $startDate) ? $fmt($endDate) : '';

        if ($start === '') {
            return $end;
        }

        return $end === '' ? $start : $start . ' – ' . $end;
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

        // Get edition. CR-4 publish guard (F-A2): every list surface filters
        // post_status='publish'; without the same guard here a trashed/draft
        // edition's detail stayed readable by id.
        $edition = get_post($editionId);
        if (!$edition || $edition->post_type !== EditionCPT::POST_TYPE || $edition->post_status !== 'publish') {
            return new WP_Error('not_found', 'Edition not found', ['status' => 404]);
        }

        // Get meta values via repository
        $startDate = $this->editionRepository->getField($editionId, 'start_date', '');
        $endDate = $this->editionRepository->getField($editionId, 'end_date', '');
        $venue = $this->editionRepository->getField($editionId, 'venue', '');
        $capacity = (int) $this->editionRepository->getField($editionId, 'capacity', 0);
        // INV-7: emit EFFECTIVE status (stored + dates + session count), the same
        // convergence point the grid + typeahead use — so the slide-over Info-tab
        // badge can't disagree with the grid row it was opened from. (Was raw
        // stored `_ntdst_status`, the C1 second-site bypass found at gate review.)
        $effectiveStatus = ntdst_get(\Stride\Modules\Edition\EditionService::class)->getEffectiveStatus($editionId);
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
            'status' => $effectiveStatus->value,
            'status_label' => $effectiveStatus->label(),
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
        $editionId = (int) $request->get_param('id');

        // Verify edition exists. CR-4 publish guard (F-A2): without it a
        // trashed/draft edition's participant rows (names, e-mails) stayed
        // readable by anyone with stride_view.
        $edition = get_post($editionId);
        if (!$edition || $edition->post_type !== EditionCPT::POST_TYPE || $edition->post_status !== 'publish') {
            return new WP_Error('not_found', 'Edition not found', ['status' => 404]);
        }

        // Check tables exist — PRESERVED guard: no registration table means no
        // registrations/sessions payload (behavior-preserving, the 2a-A roster
        // deliberately does NOT have this guard; getEditionRegistrations keeps it).
        if (!RegistrationTable::exists()) {
            return new WP_REST_Response([
                'items' => [],
                'sessions' => [],
            ]);
        }

        // Get PUBLISHED session ids for this edition (ordered by ID). Unified
        // builder-path reader (INV-3, CR-2B #2/#3) — the published scope is one
        // argument over the single session-ids reader, no raw SQL twin.
        $sessionIds = $this->sessionRepository->findIdsByEdition($editionId, 'publish');

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

        // Get registrations — the per-edition reg-rows query is owned by
        // RegistrationRepository::findByEdition (its SELECT * ... WHERE
        // edition_id ... ORDER BY registered_at ASC is identical to the prior
        // inline query; INV-3). SELECT * includes the v5 lead_name/lead_email
        // columns the anon-identity fallback below reads.
        $registrations = $this->registrationRepository->findByEdition($editionId);

        // Collect user IDs for batch fetch
        $userIds = array_map(fn($r) => (int) $r->user_id, $registrations);

        // Batch fetch users
        $users = BatchQueryHelper::batchGetUsers($userIds);

        // Get attendance records if table exists (already optimized with batch)
        $attendanceByUser = BatchQueryHelper::batchGetAttendance($editionId);

        // Format registrations with pre-fetched data.
        // Anonymous interest/waitlist rows have no user record — fall back to
        // the denormalized lead identity columns (v5) so admin can see them.
        $items = [];
        foreach ($registrations as $reg) {
            $userId = (int) $reg->user_id;
            $user = $userId ? ($users[$userId] ?? null) : null;

            $name = $user ? $user->display_name : '';
            $email = $user ? $user->user_email : '';
            $isAnon = !$user;

            if ($isAnon) {
                // The ONE lead-identity presenter (INV-3) — the same
                // '(anoniem)' rule the grid renders, so a row can never show
                // two identities across admin surfaces.
                $identity = \Stride\Modules\Enrollment\RegistrationRepository::presentLeadIdentity($reg);
                $name  = $identity['name'];
                $email = $identity['email'];
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
     * GET /admin/editions/{id}/roster
     *
     * Per-edition cohort roster read-model (Phase 2a, Task 2a.3). Thin delegator —
     * NO SQL / business logic here: it validates the edition exists, then returns
     * AdminEditionRosterService::getRosterForEdition verbatim (INV-3). The {id} is
     * absint'd via (int) cast (the route's \d+ pattern already constrains it); a
     * missing edition bubbles a 404 WP_Error rather than being swallowed (INV-4).
     * No roster filter param is bound into SQL — extras stay loaded-set only (CM-3).
     */
    public function getEditionRoster(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $editionId = (int) $request->get_param('id');

        // Verify edition exists AND is published (CM-5 absint + edition-exists guard;
        // INV-4 bubble). CR-4: a trashed/draft edition is a real WP_Post of the right
        // post_type, so a post_type-only guard would leak its full PII roster for an
        // edition the admin UI no longer lists. Scope post_status = 'publish' to match
        // every sibling DATA query in this controller (e.g. :1244). 404, not 403 — do
        // not reveal the existence of a non-published edition.
        $edition = get_post($editionId);
        if (!$edition || $edition->post_type !== EditionCPT::POST_TYPE || $edition->post_status !== 'publish') {
            return new WP_Error('not_found', 'Edition not found', ['status' => 404]);
        }

        $service = ntdst_get(\Stride\Admin\AdminEditionRosterService::class);

        return new WP_REST_Response($service->getRosterForEdition($editionId));
    }

    /**
     * GET /admin/editions/{id}/export/{type}
     *
     * Stream one of the 5 Edition exporters to the browser as a file download
     * (Task 2a.10, CM-4). On success the chosen exporter's export() sets its own
     * headers, echoes the file, and exits — so the happy path never returns to
     * the REST serializer. This method only returns (a WP_Error) on a rejected
     * request.
     *
     * Security (CM-4 / B1):
     *  - canManageAdmin gate (permission_callback) — PII egress, stricter than
     *    the roster READ's canViewAdmin.
     *  - {type} is mapped to an exporter via a fixed SERVER-SIDE allowlist; an
     *    attacker-supplied {type} (incl. a class name) hits the whitelist, NEVER
     *    a class lookup. Unknown type -> 404.
     *  - {id} absint'd (the \d+ route pattern + (int) cast) + edition-exists +
     *    post_status=publish (CR-4: a trashed/draft edition's PII export is
     *    unreachable). 404, not 403 — do not reveal existence.
     *  - The exporters themselves drop GDPR-erased participants via the shared
     *    FiltersAnonymisedParticipants skip (B1).
     */
    public function getEditionExport(WP_REST_Request $request): WP_Error
    {
        $editionId = (int) $request->get_param('id');

        $edition = get_post($editionId);
        if (!$edition || $edition->post_type !== EditionCPT::POST_TYPE || $edition->post_status !== 'publish') {
            return new WP_Error('not_found', 'Edition not found', ['status' => 404]);
        }

        // CM-4: fixed type -> exporter map, resolved SERVER-SIDE. The request
        // never names a class; an unknown {type} falls through to the 404 below.
        $type = (string) $request->get_param('type');
        $exporter = match ($type) {
            'registration' => ntdst_get(\Stride\Modules\Edition\Admin\EditionRegistrationExporter::class),
            'attendance'   => ntdst_get(\Stride\Modules\Edition\Admin\EditionAttendanceExporter::class),
            'namecard'     => ntdst_get(\Stride\Modules\Edition\Admin\EditionNamecardExporter::class),
            'files'        => ntdst_get(\Stride\Modules\Edition\Admin\EditionFilesZipExporter::class),
            'bundle'       => ntdst_get(\Stride\Modules\Edition\Admin\EditionBundleZipExporter::class),
            default        => null,
        };

        if ($exporter === null) {
            return new WP_Error('invalid_export_type', 'Unknown export type', ['status' => 404]);
        }

        // Terminal: sets headers, streams the file, and exits. Never returns.
        $exporter->export($editionId);

        // Defensive — export() exits; if a future exporter ever returns, surface
        // an honest error rather than a silent empty 200.
        return new WP_Error('export_did_not_stream', 'Export failed to stream', ['status' => 500]);
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

        // CM-2: the user must be registered in the edition the session belongs to.
        // AttendanceService resolves the editionId from the session's own edition_id, so a
        // mismatch never corrupts another edition's records — but it WOULD record attendance
        // and fire auto-completion side effects for a user who is not registered in that
        // edition. Reject before any write (covers both the mark and the clear branch).
        //
        // The session-exists check above uses get_post() (WP post-object cache); the edition
        // resolution below uses SessionService::getSession() (the data-layer find()). These are
        // DIFFERENT lookup paths, so getSession() CAN return null even after get_post() passed
        // (a data-layer cache/lookup edge). Guard the ?array before dereferencing it — a
        // lookup inconsistency is an honest invalid_session 404, never a null-offset on ?array.
        $sessionData = ntdst_get(\Stride\Modules\Edition\SessionService::class)->getSession($sessionId);
        if ($sessionData === null || !isset($sessionData['edition_id'])) {
            return new WP_Error('invalid_session', 'Session not found', ['status' => 404]);
        }
        $sessionEditionId = (int) $sessionData['edition_id'];
        if (!$this->registrationRepository->existsForEdition($userId, $sessionEditionId)) {
            return new WP_Error(
                'session_edition_mismatch',
                'Deze deelnemer is niet ingeschreven voor de editie van deze sessie.',
                ['status' => 400],
            );
        }

        // Handle clearing attendance (empty status)
        if (empty($statusValue)) {
            // Delete attendance record
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
        // Thin callback — all assembly in AdminQuoteService (strangle Task D1, INV-3).
        // Parses + sanitises params, delegates to the service, wraps in a response.
        // NO $wpdb / read-model logic here.
        $filters = [
            'page'       => max(1, (int) ($request->get_param('page') ?: 1)),
            'per_page'   => min(100, max(1, (int) ($request->get_param('per_page') ?: 20))), // clamp ceiling (4B.3 sibling audit)
            'search'     => sanitize_text_field($request->get_param('search') ?? ''),
            'status'     => sanitize_text_field($request->get_param('status') ?? ''),
            'edition_id' => (int) $request->get_param('edition_id'),
            'tag'        => (int) $request->get_param('tag'),
            'date_from'  => sanitize_text_field($request->get_param('date_from') ?? ''),
            'date_to'    => sanitize_text_field($request->get_param('date_to') ?? ''),
        ];

        $result = ntdst_get(\Stride\Admin\AdminQuoteService::class)->getQuoteList($filters);

        return new WP_REST_Response($result);
    }

    /**
     * GET /admin/quotes/export
     *
     * Streams the Exact-handoff CSV of the exact Offertes predicate (F-A9).
     * Param parsing mirrors getQuotes; row assembly in AdminQuoteService::
     * getExportRows (the same getQuoteList pipeline as the surface read);
     * the controller keeps only the streaming — headers + BOM + fputcsv +
     * exit (F-A5: never return after streaming).
     */
    public function getQuotesExport(WP_REST_Request $request): void
    {
        $filters = [
            'search'     => sanitize_text_field($request->get_param('search') ?? ''),
            'status'     => sanitize_text_field($request->get_param('status') ?? ''),
            'edition_id' => (int) $request->get_param('edition_id'),
            'tag'        => (int) $request->get_param('tag'),
            'date_from'  => sanitize_text_field($request->get_param('date_from') ?? ''),
            'date_to'    => sanitize_text_field($request->get_param('date_to') ?? ''),
        ];

        $result = ntdst_get(\Stride\Admin\AdminQuoteService::class)->getExportRows($filters);
        $export = ntdst_get(\Stride\Admin\AdminExportService::class);

        if (ob_get_level()) {
            ob_end_clean();
        }
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="offertes-' . current_time('Y-m-d-Hi') . '.csv"');
        header('X-Content-Type-Options: nosniff');
        header('Pragma: no-cache');
        header('Expires: 0');

        $output = fopen('php://output', 'w');
        // BOM for Excel UTF-8 compatibility; semicolons + decimal commas for
        // Dutch Excel / the Exact import.
        fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));
        fputcsv($output, ['Nummer', 'Klant', 'E-mail', 'Bedrijf', 'Editie', 'Datum', 'Status', 'Subtotaal', 'BTW', 'Totaal'], ';');

        foreach ($result['items'] as $item) {
            $billing = is_array($item['billing'] ?? null) ? $item['billing'] : [];
            fputcsv($output, array_map([$export, 'sanitizeCsvCell'], [
                $item['number'] ?? ('#' . ($item['id'] ?? '')),
                $item['user']['name'] ?? '',
                $item['user']['email'] ?? '',
                (string) ($billing['company'] ?? ''),
                $item['edition']['title'] ?? '',
                $item['dateLabel'] ?? '',
                $item['statusLabel'] ?? '',
                number_format((float) ($item['subtotal'] ?? 0), 2, ',', ''),
                number_format((float) ($item['tax'] ?? 0), 2, ',', ''),
                number_format((float) ($item['total'] ?? 0), 2, ',', ''),
            ]), ';');
        }

        fclose($output);
        exit;
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
            // Worklist queue key (?queue= from the Vandaag cards) — validated
            // against WorklistQueueResolver::QUEUES in the service (400 on miss).
            'queue'        => sanitize_key((string) ($request->get_param('queue') ?? '')),
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
     * GET /admin/registrations/export
     *
     * "Exporteer huidige weergave" (F-A9): streams a UTF-8 CSV of the exact
     * grid predicate. Param parsing mirrors getRegistrations (ONE vocabulary);
     * row assembly lives in AdminRegistrationQueryService::getExportRows (the
     * same scope pins + composer as the grid read); the controller keeps only
     * the HTTP streaming — headers + BOM + fputcsv + exit, per the
     * exportRegistrations precedent (F-A5: never return after streaming).
     */
    public function getRegistrationsGridExport(WP_REST_Request $request): WP_Error
    {
        $params = [
            'edition_id'    => absint($request->get_param('edition_id') ?: 0) ?: null,
            'company_id'    => absint($request->get_param('company_id') ?: 0) ?: null,
            'trajectory_id' => absint($request->get_param('trajectory_id') ?: 0) ?: null,
            'status'        => sanitize_text_field((string) ($request->get_param('status') ?? '')),
            'sort'          => sanitize_text_field((string) ($request->get_param('sort') ?? '')),
            'order'         => sanitize_text_field((string) ($request->get_param('order') ?? '')),
            'q'             => sanitize_text_field((string) ($request->get_param('q') ?? '')),
            'edition_scope' => sanitize_text_field((string) ($request->get_param('edition_scope') ?? 'active')),
            'queue'         => sanitize_key((string) ($request->get_param('queue') ?? '')),
        ];
        $params = array_filter($params, fn($v) => $v !== '' && $v !== null);

        $result = ntdst_get(\Stride\Admin\AdminRegistrationQueryService::class)->getExportRows($params);
        if (is_wp_error($result)) {
            return $result;
        }

        $export = ntdst_get(\Stride\Admin\AdminExportService::class);

        if (ob_get_level()) {
            ob_end_clean();
        }
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="inschrijvingen-weergave-' . current_time('Y-m-d-Hi') . '.csv"');
        header('X-Content-Type-Options: nosniff');
        header('Pragma: no-cache');
        header('Expires: 0');

        $output = fopen('php://output', 'w');
        // BOM for Excel UTF-8 compatibility; semicolons for Dutch Excel.
        fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));
        fputcsv($output, ['Naam', 'E-mail', 'Editie', 'Traject', 'Status', 'Offerte', 'Aanwezigheid %', 'Bedrijf'], ';');

        foreach ($result['items'] as $item) {
            fputcsv($output, array_map([$export, 'sanitizeCsvCell'], [
                $item['user']['name'] ?? '',
                $item['user']['email'] ?? '',
                $item['edition']['title'] ?? '',
                $item['trajectory']['title'] ?? '',
                $item['status']['label'] ?? '',
                $item['offerteStatus'] ?? '',
                $item['attendancePct'] === null ? '' : (string) $item['attendancePct'],
                $item['company']['name'] ?? '',
            ]), ';');
        }

        fclose($output);
        exit;
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
     *  - scope    active (default) | all. active = NOT admin-closed
     *             (TrajectoryRepository::adminActiveWhereFragment — the same
     *             boundary the Trajecten list scope uses; meta-less passes,
     *             cancelled stays visible). Trajectories have no dates, so
     *             the active scope is purely status-based.
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
        $perPage = min(100, max(1, (int) ($request->get_param('per_page') ?: 20))); // clamp ceiling (4B.3 sibling audit)

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

        // F-V9: registered_at is written in SITE time (current_time('mysql')),
        // so the threshold string must be site-time too — gmdate() compared a
        // UTC string against site-tz timestamps (±offset staleness skew, day-
        // boundary off-by-ones).
        $staleThreshold = wp_date('Y-m-d H:i:s', time() - ($staleDays * DAY_IN_SECONDS));

        // INV-3: the registrations table is repository-owned — both scan
        // queries (exact SQL incl. COLLATE pin + scan cap) live in
        // RegistrationRepository; the controller keeps bucketing/pagination.
        $registrationRepo = $this->registrationRepository;

        // F-V4: the panel reasons over the SAME admin-active edition corpus
        // as the queue cards and the grid default — previously it scanned
        // the whole table while the "Wacht op goedkeuring" card next to it
        // was scoped, so the two showed different numbers for "waiting on
        // approval" one panel apart. Edition-less rows (trajectory parents)
        // always pass.
        $activeEditionIds = ntdst_get(WorklistQueueResolver::class)->activeEditionIds();

        // Enrollment phase: every pending registration in scope — the bucket
        // rule (awaitsAdmin vs stale) runs in PHP below; SQL task-shape
        // pre-filters kept hiding row subsets the queue card counted.
        $pendingRows = $registrationRepo->findPendingForApprovalScan(self::APPROVALS_SCAN_CAP, $activeEditionIds);

        // Post-course phase: confirmed registrations with an open post_approval
        // task whose post-course user tasks (fixed keys) are absent-or-completed.
        // Deliberately UNSCOPED (null): post-course work arrives around/after
        // course end, when admins have often already closed the edition —
        // scoping hid open post_approvals on closed editions from EVERY
        // surface (no queue card carries them either).
        $confirmedRows = $registrationRepo->findConfirmedWithOpenPostApproval(self::APPROVALS_SCAN_CAP, null);

        $completionService = ntdst_get(\Stride\Modules\Enrollment\EnrollmentCompletion::class);
        /** @var list<array{0: object, 1: array, 2: string, 3: array}> $matches */
        $matches = [];
        $counts = ['approval' => 0, 'post_approval' => 0, 'stale_user' => 0];

        foreach ($pendingRows as $row) {
            $tasks = is_array($row->completion_tasks) ? $row->completion_tasks : [];

            // Bucket 1: the row waits on the ADMIN. THE shared rule
            // (EnrollmentCompletion::awaitsAdmin — the same predicate the
            // Vandaag card's ready/blocked split classifies with): user side
            // done, or nothing for the user to do (F-V5). Every hand-rolled
            // variant here (isset approval / approvalOpen / empty checks)
            // hid a row subset the card counted.
            $userTasksDone = $completionService->awaitsAdmin($tasks);
            if ($userTasksDone) {
                $matches[] = [$row, $tasks, 'approval', []];
                $counts['approval']++;
                continue;
            }

            // Bucket 3: user-side stale pending — user hasn't finished tasks and the
            // registration is older than the threshold. Capacity is held until admin
            // contacts the user or cancels.
            if ($row->registered_at && $row->registered_at <= $staleThreshold) {
                $openTask = $completionService->getFirstOpenUserTask($tasks);
                $matches[] = [$row, $tasks, 'stale_user', array_merge([
                    'open_task' => $openTask,
                    'open_task_label' => $openTask
                        ? \Stride\Modules\Enrollment\EnrollmentCompletion::taskTypeLabel($openTask)
                        : null,
                    // F-V9: strtotime() parses the site-time string on the
                    // server's tz — current_time('timestamp') carries the same
                    // shift, so the pair is consistent (time() was not).
                    'days_idle' => (int) floor(((int) current_time('timestamp') - strtotime($row->registered_at)) / DAY_IN_SECONDS),
                ], $this->buildDeadlineCountdown($completionService, $tasks, $openTask, (int) ($row->edition_id ?? 0)))];
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
     * Task 6.1 (gate deadlines & reminders): derive the countdown to the
     * active gate deadline for a stale_user row's open task. Display-only
     * (D3) — never used to lock/cancel.
     *
     * Convergence rule: the OVERDUE decision comes from
     * EnrollmentCompletion::getTaskAvailability()'s 'overdue' flag for the
     * open task (the same read Task 6.2's badge will use) — never a parallel
     * strtotime() compare. The raw deadline DATE (for the day-count
     * magnitude) is read via the same EditionRepository::getField() call
     * getTaskAvailability() itself uses internally (INV-3: repository-owned
     * read, no ad hoc $wpdb/meta access here).
     *
     * questionnaire/documents → gate_deadline (enrollment phase).
     * post_evaluation/post_documents → post_gate_deadline (post-course phase).
     * session_selection (and any task with no gate-deadline concept) →
     * neither key, overdue false — getTaskAvailability() carries no 'overdue'
     * entry for it, which this method treats as "no deadline applies".
     *
     * @return array{activeDeadline?: ?string, overdue?: bool, days_left?: int, days_overdue?: int}
     */
    private function buildDeadlineCountdown(
        \Stride\Modules\Enrollment\EnrollmentCompletion $completionService,
        array $tasks,
        ?string $openTask,
        int $editionId,
    ): array {
        if (!$openTask || !$editionId) {
            return ['activeDeadline' => null, 'overdue' => false];
        }

        $availability = $completionService->getTaskAvailability($tasks, $editionId);
        $taskAvailability = $availability[$openTask] ?? null;

        // Tasks with no gate-deadline concept (e.g. session_selection) never
        // carry an 'overdue' key in getTaskAvailability()'s result — that
        // absence IS the "no deadline applies" signal, sourced from the same
        // convergence point rather than re-derived here.
        if ($taskAvailability === null || !array_key_exists('overdue', $taskAvailability)) {
            return ['activeDeadline' => null, 'overdue' => false];
        }

        $overdue = (bool) $taskAvailability['overdue'];

        $deadlineField = match ($openTask) {
            'questionnaire', 'documents' => 'gate_deadline',
            'post_evaluation', 'post_documents' => 'post_gate_deadline',
            default => null,
        };

        $deadline = $deadlineField ? $this->editionRepository->getField($editionId, $deadlineField) : null;

        if (!$deadline) {
            return ['activeDeadline' => null, 'overdue' => false];
        }

        $result = ['activeDeadline' => $deadline, 'overdue' => $overdue];

        // F-V9: the deadline is a site-time date string parsed on the server's
        // tz — pair it with current_time('timestamp') (same shift), not time().
        $now = (int) current_time('timestamp');
        if ($overdue) {
            $result['days_overdue'] = (int) floor(($now - strtotime($deadline)) / DAY_IN_SECONDS);
        } else {
            $result['days_left'] = max(0, (int) floor((strtotime($deadline) - $now) / DAY_IN_SECONDS));
        }

        return $result;
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

        // Mark approval task as completed. 'task_not_required' is fine: a
        // pending row with NO tasks (or no approval task) still legitimately
        // waits on the admin (awaitsAdmin — the "Wacht op mij" bucket) and
        // approving it means confirming; a hard error here left those rows
        // listed with a dead Goedkeuren button.
        $result = $completionService->completeTask($registrationId, 'approval');

        if (is_wp_error($result) && $result->get_error_code() !== 'task_not_required') {
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

        // F-V10: ?fresh=1 (the Vernieuwen button) busts THIS endpoint's read
        // cache before the read, so the toasted "vernieuwd" is true instead
        // of re-serving a transient younger than its TTL. Endpoint-scoped —
        // see getStats for why not a full bustCaches().
        if ($request->get_param('fresh')) {
            delete_transient(AdminStatsService::ACTION_QUEUE_TRANSIENT_KEY);
        }

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
        // Thin delegator — health-check counts + verdict assembly live in
        // AdminActivityService (Task D4, INV-3).
        return new WP_REST_Response(
            ntdst_get(\Stride\Admin\AdminActivityService::class)->getHealthChecks(),
        );
    }

    /**
     * GET /admin/activity
     *
     * Recent activity feed from audit log.
     */
    public function getActivityFeed(WP_REST_Request $request): WP_REST_Response
    {
        // Thin delegator — the audit-log read + actor/target enrichment + mapper
        // assembly live in AdminActivityService (Task D4, INV-3).
        return new WP_REST_Response(
            ntdst_get(\Stride\Admin\AdminActivityService::class)->getActivityFeed([
                'limit' => (int) $request->get_param('limit'),
            ]),
        );
    }

    // =========================================================================
    // USER SEARCH + DETAIL
    // =========================================================================

    /**
     * GET /admin/users/search
     *
     * Search users by name, email, or login — PAGED (F-U1: the old hard cap
     * of 10 was presented as the complete result set with no way to reach
     * the rest). Returns the standard envelope
     * { items, total, page, perPage, totalPages }; each item carries an
     * `anonymised` flag (GDPR-scrubbed accounts kept for history must be
     * recognisable as such in the picker, not look like odd real people).
     */
    public function searchUsers(WP_REST_Request $request): WP_REST_Response
    {
        $query = sanitize_text_field($request->get_param('q'));
        $page = max(1, (int) ($request->get_param('page') ?: 1));
        $perPage = min(100, max(1, (int) ($request->get_param('per_page') ?: 25))); // clamp ceiling (4B.3)

        // ACCEPTED COST: the leading-wildcard LIKE over three user columns
        // cannot use an index, and count_total adds a matched-set count —
        // per debounced keystroke, admin-only, single-digit ms at LMS scale
        // (thousands of users). Same trade as the offertes customer search;
        // revisit only if a large user migration lands.
        $userQuery = new \WP_User_Query([
            'search' => "*{$query}*",
            'search_columns' => ['user_login', 'user_email', 'display_name'],
            'number' => $perPage,
            'offset' => ($page - 1) * $perPage,
            'count_total' => true,
            'orderby' => 'display_name',
            'fields' => ['ID', 'display_name', 'user_email'],
        ]);

        $results = $userQuery->get_results();
        $total = (int) $userQuery->get_total();

        // ONE exit — both batch callees no-op on an empty page, so the empty
        // case flows through the same envelope literal (no second copy to
        // drift when the payload gains a field).
        $userIds = array_map(static fn($u) => (int) $u->ID, $results);

        // Prime user-meta cache once so the per-row get_user_meta() /
        // isAnonymised() calls below are cache hits — drops 2×N queries.
        if (!empty($userIds)) {
            update_meta_cache('user', $userIds);
        }

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
                // THE one anonymised predicate (CR-6 convergence point) —
                // never an inline meta read; same key name as the dossier
                // and cohort-lens payloads.
                'is_anonymised' => \Stride\Modules\User\UserLifecycleService::isAnonymised($userId),
            ];
        }, $results);

        return new WP_REST_Response([
            'items' => $users,
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'totalPages' => (int) ceil($total / $perPage),
        ]);
    }

    /**
     * GET /admin/users/{id}/detail
     *
     * Comprehensive user profile: personal info, registrations, quotes,
     * attendance summary, and audit trail.
     */
    public function getUserDetail(WP_REST_Request $request): WP_REST_Response|WP_Error
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
    public function updateUserProfile(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $userId = (int) $request->get_param('id');

        $userData = get_userdata($userId);
        if (!$userData) {
            return new WP_Error('not_found', 'User not found', ['status' => 404]);
        }

        if (!current_user_can('edit_user', $userId)) {
            return new WP_Error('forbidden', 'Forbidden', ['status' => 403]);
        }

        if ((int) get_user_meta($userId, '_stride_anonymised_at', true) > 0) {
            return new WP_Error(
                'anonymised',
                __('Geanonimiseerde gebruikers kunnen niet bewerkt worden.', 'stride'),
                ['status' => 403],
            );
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
                return new WP_Error(
                    'invalid_email',
                    __('Ongeldig e-mailadres.', 'stride'),
                    ['status' => 400],
                );
            }
            $existing = email_exists($email);
            if ($existing && (int) $existing !== $userId) {
                return new WP_Error(
                    'email_in_use',
                    __('Dit e-mailadres is al in gebruik.', 'stride'),
                    ['status' => 400],
                );
            }
            $coreUpdate['user_email'] = $email;
            $hasCoreChange = true;
        }

        if ($hasCoreChange) {
            $result = wp_update_user($coreUpdate);
            if (is_wp_error($result)) {
                return new WP_Error(
                    'invalid',
                    $result->get_error_message(),
                    ['status' => 400],
                );
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
    public function revealSensitiveField(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $userId = (int) $request->get_param('id');
        $field = (string) $request->get_param('field');

        $allowed = ['national_id', 'date_of_birth', 'professional_license_number', 'phone'];
        if (!in_array($field, $allowed, true)) {
            return new WP_Error('invalid_field', 'Invalid field', ['status' => 400]);
        }

        // PII exfil-by-rate guard (threat #3). The reveal is manage-gated +
        // audited, but a compromised/curious coordinator could script it across
        // the whole user base. A per-current-user windowed counter throttles the
        // bulk harvest (the audit log records it, but this constrains DURING
        // rather than after). N is generous enough for legitimate dossier
        // browsing; a determined full-admin is only slowed, not blocked (by
        // design — see threat-model deferrals). The transient is per-site (one
        // DB) — fine for Stride's single-node shape.
        $rlLimit = (int) apply_filters('stride_pii_reveal_rate_limit', 20);
        $rlWindow = (int) apply_filters('stride_pii_reveal_rate_window', 60);
        $rlKey = 'stride_pii_reveal_rl_' . get_current_user_id();
        $rlCount = (int) get_transient($rlKey);
        if ($rlCount >= $rlLimit) {
            return new WP_Error(
                'rate_limited',
                __('Te veel aanvragen. Probeer het later opnieuw.', 'stride'),
                ['status' => 429],
            );
        }

        if (!get_userdata($userId)) {
            return new WP_Error('not_found', 'User not found', ['status' => 404]);
        }

        // Count this ALLOWED reveal against the window.
        set_transient($rlKey, $rlCount + 1, $rlWindow);

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

    // enrichAuditContexts + fetchPostTitles live in the shared
    // Stride\Admin\Support\AdminBatchHelpers trait (S2) — cross-domain,
    // now consumed by AdminActivityService (getActivityFeed / getNotifications,
    // Task D4) AND AdminUserService::getUserDetail. The controller no longer
    // touches audit read-models directly.

    // =========================================================================
    // HELPERS
    // =========================================================================

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
        // Thin delegator — the notification-action read + per-admin read/unread
        // state assembly live in AdminActivityService (Task D4, INV-3).
        return new WP_REST_Response(
            ntdst_get(\Stride\Admin\AdminActivityService::class)->getNotifications(),
        );
    }

    /**
     * Mark all notifications as read by storing the latest audit log ID.
     */
    public function markNotificationsRead(WP_REST_Request $request): WP_REST_Response
    {
        // Thin delegator — the MAX(id) read-cursor write (CURRENT user's meta
        // only, a security property) lives in AdminActivityService (Task D4).
        ntdst_get(\Stride\Admin\AdminActivityService::class)->markNotificationsRead();

        return new WP_REST_Response(['success' => true, 'unread_count' => 0]);
    }

    /**
     * Export confirmed registrations for upcoming editions as a UTF-8 CSV file.
     *
     * Outputs directly to php://output and exits — no WP_REST_Response return.
     */
    public function exportRegistrations(WP_REST_Request $request): void
    {
        if (!RegistrationTable::exists()) {
            wp_die('Registration table not found.');
        }

        // Read-model assembly (the confirmed-upcoming SELECT + per-row enrichment
        // + formula-injection control) lives in AdminExportService (Task D3, INV-3).
        // The controller keeps ONLY the HTTP streaming: headers + BOM + fputcsv.
        $today = current_time('Y-m-d');
        $export = ntdst_get(\Stride\Admin\AdminExportService::class);
        $rows = $export->buildExportRows($today);

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

        // Each assembled cell is passed through the service's formula-injection
        // control before streaming (the security control's home is the service;
        // the controller invokes it per-cell at stream time).
        foreach ($rows as $row) {
            fputcsv($output, array_map([$export, 'sanitizeCsvCell'], $row), ';');
        }

        fclose($output);
        exit;
    }
}
