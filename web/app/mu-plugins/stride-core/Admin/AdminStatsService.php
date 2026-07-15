<?php

declare(strict_types=1);

namespace Stride\Admin;

use Stride\Domain\QuoteStatus;
use Stride\Domain\RegistrationStatus;
use Stride\Modules\Edition\EditionCPT;
use Stride\Modules\Edition\EditionRepository;
use Stride\Modules\Edition\SessionCPT;
use Stride\Modules\Enrollment\RegistrationTable;
use Stride\Modules\Invoicing\QuoteCPT;

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

    /**
     * Revision counter salting WorklistQueueResolver's short-TTL id-set
     * cache. The cache keys are dynamic (edition-set + queue-subset hash),
     * so they cannot be deleted by name — bumping the rev invalidates every
     * cached id-set at once.
     */
    public const QUEUE_REV_OPTION = 'stride_queue_rev';

    public function __construct(
        private readonly ActionQueueService $actionQueue,
        private readonly EditionRepository $editionRepository,
    ) {
        $this->init();
    }

    /**
     * The write-event bust hooks live HERE — with the cache owner — and NOT
     * in AdminDashboardService: that service is admin_only, and the ntdst
     * Bootstrap skips admin_only services when !is_admin(). Workspace
     * mutations run through REST (is_admin() false), so hooks registered
     * there never fired for exactly the writes the workspace makes —
     * approve/promote/bulk left every dashboard count stale until the TTL.
     */
    private function init(): void
    {
        $bust = static function (): void {
            self::bustCaches();
        };
        add_action('stride/registration/created', $bust);
        add_action('stride/registration/confirmed', $bust);
        add_action('stride/registration/cancelled', $bust);
        add_action('stride/attendance/marked', $bust);
        add_action('save_post_vad_quote', $bust);
        // Bulk batch completion + quote-status changes set via the repo
        // (which never touch save_post_vad_quote) must recount the queue.
        add_action('stride/registration/bulk_completed', $bust);
        add_action('stride/registration/quote_status_changed', $bust);
        // Interest/waitlist public sign-ups feed worklistQueues.oldinterest /
        // .waitlist_open (not covered by created/confirmed/cancelled).
        add_action('stride/registration/interest_registered', $bust);
        add_action('stride/registration/waitlisted', $bust);
        // Any other status transition (e.g. a single reg → completed feeding
        // .nocert) fires the repo's generic updated event — the catch-all.
        add_action('stride/registration/updated', $bust);
        // Edition / session / trajectory CPT writes feed upcomingEditions,
        // todaySessions and the queue scope (findAdminActiveIds).
        add_action('save_post_vad_edition', $bust);
        add_action('save_post_vad_session', $bust);
        add_action('save_post_vad_trajectory', $bust);
        // learndash_course_completed (feeds the nocert count) fires on
        // frontend requests — that bust lives in LearnDashService, which
        // loads on every request. It calls the same bustCaches().
    }

    /**
     * Bust every cached dashboard read this service owns (stats + action
     * queue + the resolver's id-sets) — THE single bust both hook sites
     * call (init() above and LearnDashService's course-completion hook), so
     * a cache added here is busted everywhere at once instead of drifting
     * between two hand-copied delete_transient lists.
     */
    public static function bustCaches(): void
    {
        delete_transient(self::ACTION_QUEUE_TRANSIENT_KEY);
        delete_transient(self::STATS_TRANSIENT_KEY);
        self::bumpQueueRev();
    }

    /**
     * Invalidate the resolver's id-set cache (dynamic keys — validated
     * against this rev inside the cached value).
     */
    public static function bumpQueueRev(): void
    {
        update_option(self::QUEUE_REV_OPTION, (int) get_option(self::QUEUE_REV_OPTION, 0) + 1, true);
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

        // F-V12: the payload carries ONLY consumed keys. The pre-workspace
        // dashboard's detail blocks (todaySessionDetails, upcomingEditionDetails,
        // recentRegistrations, openTrajectories, alerts, actionCount) were
        // computed on every cache miss — batch fetches included — and consumed
        // by NOTHING after the workspace replaced that UI. Grep before
        // re-adding a key: vandaag.js mapStats/mapQueues is the consumer.
        $stats = [
            'upcomingEditions' => $upcomingEditions,
            'totalRegistrations' => $totalRegistrations,
            'pendingQuotes' => $pendingQuotes,
            'todaySessions' => $todaySessions,
            'registrationsThisWeek' => $registrationsThisWeek,
            'registrationsLastWeek' => $registrationsLastWeek,
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
        $resolver = ntdst_get(WorklistQueueResolver::class);
        $ids = $resolver->idsByQueue($activeEditionIds);

        // Decision 7a: the pending card renders a ready/blocked split —
        // same fetch+definition as the pending set itself (ready ∪ blocked
        // ≡ pending, so the split can never disagree with the card total).
        $split = $resolver->pendingSplit($activeEditionIds);

        // Payload keys are the legacy stats vocabulary (vandaag.js countKey map).
        return [
            'pending'            => count($ids['pending']),
            'pending_ready'      => count($split['ready']),
            'waitlist_open'      => count($ids['waitlist']),
            'offerte_opvolging'  => count($ids['offerte']),
            'nocert'             => count($ids['nocert']),
            'oldinterest'        => count($ids['oldinterest']),
            'interest_to_invite' => count($ids['interest_to_invite']),
            // F-V8: DISTINCT registrations across all queues — a row may
            // legitimately sit in two queues (oldinterest ∩ interest_to_invite),
            // so the headline "N openstaande acties" must not sum the cards.
            'total'              => count(array_unique(array_merge(...array_values($ids)))),
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

        // Editions with capacity (for capacity_threshold rule). Occupancy
        // counts the enum's seat-holding statuses (F-V6) — the same
        // definition as EditionService::getRegisteredCount/hasAvailableSpots,
        // so a "near capacity" melding and the waitlist-open queue can never
        // reason over two different numbers.
        if (!empty($rules['capacity_threshold']['enabled'])) {
            $capacityStatuses = RegistrationStatus::capacityValues();
            $statusPlaceholders = implode(',', array_fill(0, count($capacityStatuses), '%s'));
            $editions = $wpdb->get_results($wpdb->prepare(
                "SELECT p.ID as id, p.post_title as title,
                        pm_cap.meta_value as capacity,
                        COALESCE(rc.cnt, 0) as registered
                 FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} pm_date ON p.ID = pm_date.post_id AND pm_date.meta_key = '_ntdst_start_date'
                 LEFT JOIN {$wpdb->postmeta} pm_cap ON p.ID = pm_cap.post_id AND pm_cap.meta_key = '_ntdst_capacity'
                 LEFT JOIN (
                     SELECT edition_id, COUNT(*) as cnt FROM {$registrationTable}
                     WHERE status IN ({$statusPlaceholders}) GROUP BY edition_id
                 ) rc ON rc.edition_id = p.ID
                 WHERE p.post_type = %s AND p.post_status = 'publish'
                 AND pm_date.meta_value >= %s
                 AND pm_cap.meta_value > 0",
                ...array_merge($capacityStatuses, [EditionCPT::POST_TYPE, $today]),
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

        // Registrations sitting on an open USER task past the cutoff — the
        // same population as the "Wacht op gebruiker" tab this melding
        // deep-links to. The old predicate LIKE '"completed":false' matched
        // a boolean key no writer ever produces (F-V1: could never fire);
        // a bare LIKE '"status":"pending"' over-matched admin-side approval
        // tasks and told the admin users were dawdling when the admin was
        // the blocker. SQL over-fetches (any pending sub-object), the shared
        // rule (EnrollmentCompletion::awaitsAdmin — the actor decider) is
        // authoritative in PHP.
        if (!empty($rules['incomplete_tasks']['enabled']) && $registrationTableExists) {
            $taskDays = (int) ($rules['incomplete_tasks']['value'] ?? 7);
            $taskCutoff = wp_date('Y-m-d', strtotime("-{$taskDays} days"));
            $candidates = $wpdb->get_results($wpdb->prepare(
                "SELECT r.id, r.completion_tasks
                 FROM {$registrationTable} r
                 WHERE r.status = %s
                 AND r.completion_tasks IS NOT NULL
                 AND r.completion_tasks LIKE %s
                 AND r.registered_at < %s
                 LIMIT 500",
                RegistrationStatus::Pending->value,
                '%"status":"pending"%',
                $taskCutoff,
            ), ARRAY_A);

            $completion = ntdst_get(\Stride\Modules\Enrollment\EnrollmentCompletion::class);
            $data['incomplete_tasks'] = array_values(array_filter(
                $candidates ?: [],
                static function (array $row) use ($completion): bool {
                    $tasks = json_decode((string) ($row['completion_tasks'] ?? ''), true);

                    // Waiting on the USER = not awaiting the admin.
                    return is_array($tasks) && !$completion->awaitsAdmin($tasks);
                },
            ));
        }

        $items = $this->actionQueue->evaluate($rules, $data);

        set_transient(self::ACTION_QUEUE_TRANSIENT_KEY, $items, 5 * MINUTE_IN_SECONDS);

        return $items;
    }
}
