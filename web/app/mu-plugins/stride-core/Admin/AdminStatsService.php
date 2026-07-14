<?php

declare(strict_types=1);

namespace Stride\Admin;

use Stride\Domain\QuoteStatus;
use Stride\Infrastructure\BatchQueryHelper;
use Stride\Modules\Edition\EditionCPT;
use Stride\Modules\Edition\EditionRepository;
use Stride\Modules\Edition\SessionCPT;
use Stride\Modules\Enrollment\RegistrationTable;
use Stride\Modules\Invoicing\QuoteCPT;
use Stride\Modules\Trajectory\TrajectoryCPT;

/**
 * Read-model assembly for the admin dashboard stats + action-queue surfaces.
 *
 * Strangled out of AdminAPIController (§12.4) behavior-preserving: the SQL
 * data-gathering for GET /admin/stats and GET /admin/action-queue lives here;
 * the controller methods are thin delegators. Rule evaluation for the action
 * queue is NOT re-implemented — it delegates to ActionQueueService::evaluate().
 *
 * Registered in plugin-config.php.
 */
final class AdminStatsService
{
    /**
     * Transient key + TTL for the cached dashboard stats payload (S6).
     *
     * Mirrors the sibling getActionQueueItems / stride_action_queue cache in
     * this file: a short TTL bounds staleness on a quiet system, and a broad set
     * of registration/quote/attendance/CPT write events busts this key (wired in
     * AdminDashboardService::init). Because the stats payload counts MORE inputs
     * than the action queue (interest/waitlist sign-ups, edition/session/
     * trajectory CPT saves, any reg status transition), the bust set is wider
     * than the action queue's — it also hooks interest_registered, waitlisted,
     * the generic registration/updated event, and save_post_vad_{edition,session,
     * trajectory}. With those wired, a covered write reflects on the next read;
     * any input not in that set is bounded to ≤120s staleness on a self-healing
     * TTL — acceptable, as none of the ~15 stats queries are real-time critical.
     */
    public const STATS_TRANSIENT_KEY = 'stride_admin_stats';
    private const STATS_TTL = 2 * MINUTE_IN_SECONDS;

    /** Transient key for the cached action-queue items (getActionQueueItems). */
    public const ACTION_QUEUE_TRANSIENT_KEY = 'stride_action_queue';

    public function __construct(
        private readonly ActionQueueService $actionQueue,
        private readonly EditionRepository $editionRepository,
    ) {}

    /**
     * Bust every cached dashboard read this service owns (stats + action
     * queue) — THE single bust both hook sites call
     * (AdminDashboardService::init's write-event closure and
     * LearnDashService's course-completion hook), so a cache added here is
     * busted everywhere at once instead of drifting between two hand-copied
     * delete_transient lists.
     */
    public static function bustCaches(): void
    {
        delete_transient(self::ACTION_QUEUE_TRANSIENT_KEY);
        delete_transient(self::STATS_TRANSIENT_KEY);
    }

    // =========================================================================
    // DASHBOARD STATS  (GET /admin/stats)
    // =========================================================================

    /**
     * Assemble the top-line dashboard summary response.
     *
     * Moved verbatim from AdminAPIController::getStats (behavior-preserving).
     *
     * @return array<string,mixed>
     */
    public function getStats(): array
    {
        global $wpdb;

        // Serve from the transient within the TTL (S6). Busted on every
        // registration/quote/attendance write (AdminDashboardService::init), so
        // a cache hit can only return data no staler than the last such write or
        // the TTL — whichever is sooner.
        $cached = get_transient(self::STATS_TRANSIENT_KEY);
        if (is_array($cached)) {
            return $cached;
        }

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
                    pm_capacity.meta_value as capacity
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm_date ON p.ID = pm_date.post_id AND pm_date.meta_key = '_ntdst_start_date'
             LEFT JOIN {$wpdb->postmeta} pm_capacity ON p.ID = pm_capacity.post_id AND pm_capacity.meta_key = '_ntdst_capacity'
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

        $stats = [
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
            // ADDITIVE (Phase-1D Task 3.3 / drift #4): the Vandaag worklist queue
            // counts, scoped to the active-edition subset. Existing keys above are
            // UNCHANGED — other UI consumes them. Adding a 6th queue key
            // (interest_to_invite) flows through here without a whitelist.
            'worklistQueues' => $this->getWorklistQueueCounts($this->activeEditionIds()),
        ];

        set_transient(self::STATS_TRANSIENT_KEY, $stats, self::STATS_TTL);

        return $stats;
    }

    // =========================================================================
    // WORKLIST QUEUE COUNTS  (Vandaag home — Phase-1D Task 3.3, drift #4)
    // =========================================================================

    /**
     * Compute the Vandaag worklist queue counts, scoped to the supplied
     * active-edition subset (§10 — the caller feeds the active-edition ID set;
     * this NEVER scans the full registration corpus).
     *
     * The queues (§1 of the spec):
     *  - pending           — registrations in 'pending' status.
     *  - waitlist_open     — 'waitlist' rows whose edition has open capacity
     *                        (per-edition capacity check, read through
     *                        getEffectiveStatus — INV-7: never raw status).
     *  - offerte_opvolging — 'confirmed' rows whose linked quote is absent OR
     *                        status != Exported. Uses the SINGLE paid-proxy
     *                        resolver (AdminRegistrationQueryService) — the same
     *                        one the grid offerte column uses (Sibling-site
     *                        audit item 1: one definition only).
     *  - nocert            — 'completed' rows with completed_at set but no
     *                        LearnDash certificate.
     *  - oldinterest       — 'interest' rows registered more than
     *                        WorklistQueueResolver::OLD_INTEREST_DAYS ago.
     *                        Counts DATELESS-edition rows
     *                        too, because the active subset includes dateless
     *                        editions (§10.7 carve-out — the active-set predicate
     *                        is NULL-permitting, not a start_date >= X filter).
     *  - interest_to_invite — 'interest' rows whose edition now has a PLANNED
     *                        date (non-empty _ntdst_start_date). The interest
     *                        anchor was dateless; once a session/date is added,
     *                        these people can be invited. DISTINCT from
     *                        oldinterest (age-based) — a row may count toward
     *                        both. The actual bulk-mail SEND is DEFERRED to the
     *                        netdust-mail broadcast; this queue only surfaces
     *                        the list. Freshness depends on the stats transient
     *                        being busted on save_post_vad_session /
     *                        save_post_vad_edition (AdminDashboardService) —
     *                        adding a date already refreshes this count.
     *
     * @param  array<int> $activeEditionIds
     * @return array{pending:int,waitlist_open:int,offerte_opvolging:int,nocert:int,oldinterest:int,interest_to_invite:int}
     */
    public function getWorklistQueueCounts(array $activeEditionIds): array
    {
        // THE queue definitions live in WorklistQueueResolver — the counts are
        // count() of the SAME id-sets the grid's ?queue= filter applies, so the
        // card number and its click-through can never drift (RC-2). Lazy
        // container read: the resolver also serves AdminRegistrationQueryService,
        // which this service already owns — a constructor dep would cycle.
        $ids = ntdst_get(WorklistQueueResolver::class)->idsByQueue($activeEditionIds);

        // Payload keys are the legacy stats vocabulary (vandaag.js countKey map).
        return [
            'pending'            => count($ids['pending']),
            'waitlist_open'      => count($ids['waitlist']),
            'offerte_opvolging'  => count($ids['offerte']),
            'nocert'             => count($ids['nocert']),
            'oldinterest'        => count($ids['oldinterest']),
            'interest_to_invite' => count($ids['interest_to_invite']),
        ];
    }

    /**
     * Derive the active-edition ID set for the worklist queue counts.
     *
     * Active = published editions the admin has NOT closed (status-based —
     * OfferingStatus::adminClosedValues; decision 2026-07-14, F-V3). Dateless
     * editions (the sessionless interest anchors, §10.7) are active by
     * construction — the rule never looks at dates. NOTE this deliberately
     * DIVERGES from the Edities agenda's date-scoped list predicate
     * (AdminAPIController::getEditions) — reconciliation is scheduled for the
     * Edities slice; do not treat that predicate as this rule's mirror.
     *
     * @return array<int>
     */
    private function activeEditionIds(): array
    {
        // Canonical admin-active set (CR-6) — owned by the edition domain
        // (EditionRepository::findAdminActiveIds). No second copy of the
        // predicate here. Call the repo directly: the former EditionService
        // pass-through was drift.
        return $this->editionRepository->findAdminActiveIds();
    }

    // =========================================================================
    // ACTION QUEUE  (GET /admin/action-queue) — SQL data-gathering + evaluate
    // =========================================================================

    /**
     * Gather live data per the enabled rules, evaluate it into prioritized
     * action items, and cache the result for 5 minutes.
     *
     * Moved verbatim from AdminAPIController::getActionQueue (behavior-preserving):
     * this owns the SQL data-gathering + transient caching, and delegates the
     * rule evaluation to ActionQueueService::evaluate() (drift #3 — NOT
     * re-implemented here). The controller keeps the per-user dismissal filter.
     *
     * @param array<string, array{enabled: bool, value?: int}> $rules
     * @return array<int, array{rule: string, priority: string, text: string, subject_id: int|null, url: string}>
     */
    public function getActionQueueItems(array $rules): array
    {
        global $wpdb;

        // Check transient cache
        $cached = get_transient(self::ACTION_QUEUE_TRANSIENT_KEY);
        if ($cached !== false) {
            return $cached;
        }

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

        $items = $this->actionQueue->evaluate($rules, $data);

        set_transient(self::ACTION_QUEUE_TRANSIENT_KEY, $items, 5 * MINUTE_IN_SECONDS);

        return $items;
    }
}
