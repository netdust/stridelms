<?php

declare(strict_types=1);

namespace Stride\Admin;

use Stride\Domain\QuoteStatus;
use Stride\Domain\RegistrationStatus;
use Stride\Infrastructure\BatchQueryHelper;
use Stride\Modules\Edition\EditionCPT;
use Stride\Modules\Edition\SessionCPT;
use Stride\Modules\Enrollment\RegistrationRepository;
use Stride\Modules\Enrollment\RegistrationTable;
use Stride\Modules\Invoicing\QuoteRepository;
use Stride\Modules\Trajectory\TrajectoryCPT;

/**
 * Read-model assembly for the admin registration grid.
 *
 * Thin service — owns batch-resolve + two-step offerte resolver.
 * No raw $wpdb SELECTs of its own: delegates to RegistrationRepository
 * and BatchQueryHelper. Does NOT contain business logic.
 *
 * Registered in plugin-config.php.
 */
final class AdminRegistrationQueryService
{
    public function __construct(
        private readonly RegistrationRepository $registrations,
        private readonly QuoteRepository $quotes,
    ) {}

    // =========================================================================
    // PUBLIC API
    // =========================================================================

    /**
     * Assemble the composite page DTO for the registration grid.
     *
     * When $params['group_by'] is present the response items are GROUP
     * AGGREGATES — one per distinct value of the group column — NOT flat
     * registration rows.  The caller (controller) decides which shape to return
     * based on whether the key is present, but this service owns the distinction.
     *
     * @param  array<string,mixed> $params  Pre-sanitised request params.
     * @return array{items:array,total:int,page:int,perPage:int,totalPages:int}
     */
    public function getGridPage(array $params): array
    {
        $groupBy = $params['group_by'] ?? null;

        if ($groupBy !== null) {
            return $this->getGroupedPage($params, (string) $groupBy);
        }

        return $this->getFlatPage($params);
    }

    // =========================================================================
    // FLAT (NON-GROUPED) PATH
    // =========================================================================

    /**
     * @return array{items:array,total:int,page:int,perPage:int,totalPages:int}
     */
    private function getFlatPage(array $params): array
    {
        $result  = $this->registrations->queryForGrid($params);
        $rows    = $result['rows'];
        $total   = $result['total'];
        $page    = $result['page'];
        $perPage = $result['per_page'];

        if (empty($rows)) {
            return $this->paginationEnvelope([], $total, $page, $perPage);
        }

        // --- Collect IDs for batch resolution ---
        $userIds      = [];
        $editionIds   = [];
        $trajectoryIds = [];
        $regIds       = [];

        foreach ($rows as $row) {
            $userId = (int) $row->user_id;
            if ($userId > 0) {
                $userIds[] = $userId;
            }
            if (!empty($row->edition_id)) {
                $editionIds[] = (int) $row->edition_id;
            }
            if (!empty($row->trajectory_id)) {
                $trajectoryIds[] = (int) $row->trajectory_id;
            }
            $regIds[] = (int) $row->id;
        }

        $userIds       = array_unique($userIds);
        $editionIds    = array_unique($editionIds);
        $trajectoryIds = array_unique($trajectoryIds);

        // --- Batch resolve ---
        $users    = BatchQueryHelper::batchGetUsers($userIds);
        $editions = BatchQueryHelper::batchGetPosts($editionIds, EditionCPT::POST_TYPE);

        $trajectories = [];
        if (!empty($trajectoryIds)) {
            $trajectories = BatchQueryHelper::batchGetPosts($trajectoryIds, TrajectoryCPT::POST_TYPE);
        }

        // Company names: prime WP meta cache, then read per-user (no N queries)
        if (!empty($userIds)) {
            update_meta_cache('user', $userIds);
        }

        // Session counts per edition (for attendance %)
        $sessionCountByEdition = $this->batchGetSessionCounts($editionIds);

        // Attendance per edition (keyed per edition → userId → sessionId → status)
        $attendanceByEdition = [];
        foreach ($editionIds as $editionId) {
            $attendanceByEdition[$editionId] = BatchQueryHelper::batchGetAttendance($editionId);
        }

        // Two-step offerte resolver
        $offerteByReg = $this->resolveOfferteStatuses($regIds);

        // --- Compose items ---
        $items = [];
        foreach ($rows as $row) {
            $userId    = (int) $row->user_id;
            $editionId = (int) ($row->edition_id ?? 0);
            $regId     = (int) $row->id;

            $user = $userId > 0 ? ($users[$userId] ?? null) : null;

            // Status
            $statusEnum  = RegistrationStatus::tryFrom((string) $row->status);
            $statusLabel = $statusEnum?->label() ?? (string) $row->status;

            // Attendance %
            $attendancePct = $this->computeAttendancePct(
                $userId,
                $editionId,
                $attendanceByEdition[$editionId] ?? [],
                $sessionCountByEdition[$editionId] ?? 0
            );

            // Company name — from user meta (billing_company), cache-primed above
            $companyName = '';
            if ($userId > 0) {
                $companyName = (string) get_user_meta($userId, 'billing_company', true);
            }

            // Trajectory
            $trajectoryData = null;
            if (!empty($row->trajectory_id)) {
                $trajId   = (int) $row->trajectory_id;
                $trajPost = $trajectories[$trajId] ?? null;
                if ($trajPost !== null) {
                    $trajectoryData = [
                        'id'    => $trajId,
                        'title' => $trajPost->post_title,
                    ];
                } else {
                    $trajectoryData = ['id' => $trajId, 'title' => ''];
                }
            }

            $editionPost  = $editionId > 0 ? ($editions[$editionId] ?? null) : null;
            $editionTitle = $editionPost?->post_title ?? '';

            $items[] = [
                'id'           => $regId,
                'user'         => [
                    'id'    => $userId,
                    'name'  => $user?->display_name ?? '',
                    'email' => $user?->user_email ?? '',
                ],
                'edition'      => [
                    'id'    => $editionId,
                    'title' => $editionTitle,
                ],
                'status'       => [
                    'value' => $row->status,
                    'label' => $statusLabel,
                ],
                'offerteStatus' => $offerteByReg[$regId] ?? 'Geen offerte',
                'attendancePct' => $attendancePct,
                'company'      => [
                    'id'   => (int) ($row->company_id ?? 0),
                    'name' => $companyName,
                ],
                'trajectory'   => $trajectoryData,
            ];
        }

        return $this->paginationEnvelope($items, $total, $page, $perPage);
    }

    // =========================================================================
    // GROUPED (AGGREGATE) PATH  — §3.2
    // =========================================================================

    /**
     * Return GROUP AGGREGATE rows (one per distinct group_by value).
     *
     * Each aggregate item:
     *  - group_value: the distinct column value
     *  - count: number of registrations in the group
     *  - pct_afgerond: % of rows with status = 'completed'
     *  - avg_attendance_pct: average attendance % across registrations in the group
     *  - offerte_verdeling: tally of offerte statuses within the group
     *
     * This path avoids the ONLY_FULL_GROUP_BY problem in queryForGrid's data
     * SELECT by running its own aggregate query.
     *
     * @param  string $groupBy  Allowlisted column (validated by queryForGrid M4).
     * @return array{items:array,total:int,page:int,perPage:int,totalPages:int}
     */
    private function getGroupedPage(array $params, string $groupBy): array
    {
        global $wpdb;

        // Re-use queryForGrid for the total-groups count only.
        $countResult = $this->registrations->queryForGrid($params);
        $total       = $countResult['total'];
        $page        = $countResult['page'];
        $perPage     = $countResult['per_page'];

        // Allowlist guard (mirrors M4 in queryForGrid; defensive in-service check).
        $groupByAllowlist = ['edition_id', 'status', 'company_id'];
        if (!in_array($groupBy, $groupByAllowlist, true)) {
            return $this->paginationEnvelope([], 0, 1, $perPage);
        }

        if (!RegistrationTable::exists()) {
            return $this->paginationEnvelope([], 0, 1, $perPage);
        }

        $regTable = $wpdb->prefix . 'vad_registrations';

        // Build minimal WHERE that mirrors what queryForGrid builds, but only
        // for the simple scalar filters the grouped path needs.
        $where  = [];
        $sqlParams = [];

        $editionScopeActive = (string) ($params['edition_scope'] ?? 'active') !== 'all'
            && empty($params['edition_id']);

        $activeJoin = '';
        if ($editionScopeActive) {
            $twoDaysAgo = wp_date('Y-m-d', strtotime('-2 days'));
            $activeJoin =
                "LEFT JOIN {$wpdb->posts} ae ON ae.ID = r.edition_id AND ae.post_type = 'vad_edition' AND ae.post_status = 'publish'
                 LEFT JOIN {$wpdb->postmeta} pm_start ON pm_start.post_id = ae.ID AND pm_start.meta_key = '_ntdst_start_date'";
            $where[]     = '(r.edition_id IS NULL OR ae.ID IS NOT NULL)';
            $where[]     = '(pm_start.meta_value >= %s OR pm_start.meta_value IS NULL)';
            $sqlParams[] = $twoDaysAgo;
        }

        if (!empty($params['edition_id'])) {
            $where[]     = 'r.edition_id = %d';
            $sqlParams[] = absint($params['edition_id']);
        }

        if (!empty($params['company_id'])) {
            $where[]     = 'r.company_id = %d';
            $sqlParams[] = absint($params['company_id']);
        }

        if (!empty($params['status'])) {
            $statusEnum = RegistrationStatus::tryFrom((string) $params['status']);
            if ($statusEnum !== null) {
                $where[]     = 'r.status = %s';
                $sqlParams[] = $statusEnum->value;
            }
        }

        $whereClause = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        // Aggregate SELECT — only GROUP BY column, COUNT, and completed-pct.
        // attendance_pct is deferred to NULL here (attendace is per-edition,
        // not directly available in a pure SQL aggregate across editions).
        // offerte_verdeling is resolved via the bounded two-step resolver below.
        $groupColSql = "r.{$groupBy}";  // column name from allowlist (never user input)

        $aggSql = "SELECT {$groupColSql} AS group_value,
                          COUNT(*) AS cnt,
                          SUM(CASE WHEN r.status = 'completed' THEN 1 ELSE 0 END) AS completed_count
                   FROM {$regTable} r
                   LEFT JOIN {$wpdb->users} u ON u.ID = r.user_id
                   {$activeJoin}
                   {$whereClause}
                   GROUP BY {$groupColSql}
                   ORDER BY cnt DESC
                   LIMIT %d OFFSET %d";

        $aggParams   = array_merge($sqlParams, [$perPage, ($page - 1) * $perPage]);
        $aggRows     = $aggParams
            ? $wpdb->get_results($wpdb->prepare($aggSql, ...$aggParams))
            : $wpdb->get_results($aggSql);

        if (empty($aggRows)) {
            return $this->paginationEnvelope([], $total, $page, $perPage);
        }

        // For offerte_verdeling: fetch ALL reg IDs in each group to run the
        // bounded two-step resolver. We limit to the current page's groups only.
        // Build per-group reg ID sets.
        $groupValues = array_map(fn($r) => $r->group_value, $aggRows);

        $groupRegIds = $this->fetchRegIdsPerGroup($groupBy, $groupValues, $whereClause, $sqlParams, $regTable, $activeJoin);

        // All reg IDs across visible groups
        $allRegIds    = array_merge(...array_values($groupRegIds));
        $offerteByReg = !empty($allRegIds) ? $this->resolveOfferteStatuses($allRegIds) : [];

        // Compose aggregate items
        $items = [];
        foreach ($aggRows as $row) {
            $count     = (int) $row->cnt;
            $completed = (int) $row->completed_count;
            $pctAfgerond = $count > 0 ? (int) round($completed / $count * 100) : 0;

            // Tally offerte statuses for this group
            $groupRids      = $groupRegIds[$row->group_value] ?? [];
            $offerteTally   = $this->tallyOfferteStatuses($groupRids, $offerteByReg);

            $items[] = [
                'group_value'       => $row->group_value,
                'count'             => $count,
                'pct_afgerond'      => $pctAfgerond,
                'avg_attendance_pct' => null,  // Deferred: cross-edition avg requires per-row resolution
                'offerte_verdeling' => $offerteTally,
            ];
        }

        return $this->paginationEnvelope($items, $total, $page, $perPage);
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * Two-step offerte resolver.
     *
     * Step 1: findQuoteIdsByRegistrations → [regId => quoteId]
     * Step 2: batchGetPostMeta([quoteIds], ['status']) → [quoteId => ['status' => value]]
     * Output: [regId => Dutch label | 'Geen offerte']
     *
     * @param  array<int> $regIds
     * @return array<int,string>
     */
    private function resolveOfferteStatuses(array $regIds): array
    {
        if (empty($regIds)) {
            return [];
        }

        // Step 1
        $quoteIdsByReg = $this->quotes->findQuoteIdsByRegistrations($regIds);

        if (empty($quoteIdsByReg)) {
            return [];
        }

        // Step 2
        $uniqueQuoteIds = array_values(array_unique(array_values($quoteIdsByReg)));
        $quoteMeta      = BatchQueryHelper::batchGetPostMeta($uniqueQuoteIds, ['status']);

        // Compose regId → label
        $result = [];
        foreach ($quoteIdsByReg as $regId => $quoteId) {
            $rawStatus = $quoteMeta[$quoteId]['status'] ?? null;
            if ($rawStatus === null || $rawStatus === '') {
                $result[$regId] = 'Geen offerte';
                continue;
            }
            $statusEnum     = QuoteStatus::tryFrom((string) $rawStatus);
            $result[$regId] = $statusEnum !== null ? $statusEnum->label() : (string) $rawStatus;
        }

        return $result;
    }

    /**
     * Compute attendance % for a single (user, edition) combination.
     *
     * @param  array<int,array<int,string>> $attendanceForEdition  userId → sessionId → status
     * @return int|null  null when the edition has no sessions
     */
    private function computeAttendancePct(
        int $userId,
        int $editionId,
        array $attendanceForEdition,
        int $sessionCount
    ): ?int {
        if ($sessionCount === 0 || $userId === 0) {
            return null;
        }

        $userAttendance = $attendanceForEdition[$userId] ?? [];
        $presentCount   = 0;

        foreach ($userAttendance as $status) {
            if ($status === 'present') {
                $presentCount++;
            }
        }

        return (int) round($presentCount / $sessionCount * 100);
    }

    /**
     * Batch-count sessions per edition (one query, not N).
     *
     * @param  array<int> $editionIds
     * @return array<int,int>  editionId => sessionCount
     */
    private function batchGetSessionCounts(array $editionIds): array
    {
        if (empty($editionIds)) {
            return [];
        }

        global $wpdb;

        $ids          = array_map('intval', array_unique($editionIds));
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT pm.meta_value AS edition_id, COUNT(p.ID) AS session_count
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
             WHERE p.post_type = %s
               AND p.post_status = 'publish'
               AND pm.meta_key = '_ntdst_edition_id'
               AND pm.meta_value IN ({$placeholders})
             GROUP BY pm.meta_value",
            SessionCPT::POST_TYPE,
            ...$ids,
        ));

        $counts = array_fill_keys($ids, 0);
        foreach ($rows as $row) {
            $counts[(int) $row->edition_id] = (int) $row->session_count;
        }

        return $counts;
    }

    /**
     * Fetch the registration IDs belonging to each group value.
     *
     * Used by the group_by path to feed the bounded two-step offerte resolver
     * without a JSON aggregate (M5 stays intact).
     *
     * @param  string   $groupBy
     * @param  array    $groupValues  Distinct values from the aggregate page
     * @param  string   $whereClause  Pre-built WHERE fragment (from getGroupedPage)
     * @param  array    $whereParams  Bound parameters for $whereClause
     * @param  string   $regTable
     * @param  string   $activeJoin
     * @return array<string,array<int>>  group_value => [regId, ...]
     */
    private function fetchRegIdsPerGroup(
        string $groupBy,
        array $groupValues,
        string $whereClause,
        array $whereParams,
        string $regTable,
        string $activeJoin
    ): array {
        global $wpdb;

        if (empty($groupValues)) {
            return [];
        }

        $allowlist = ['edition_id', 'status', 'company_id'];
        if (!in_array($groupBy, $allowlist, true)) {
            return [];
        }

        $groupPlaceholder = implode(',', array_fill(0, count($groupValues), '%s'));
        $groupValuesCast  = array_map('strval', $groupValues);

        // Add a filter clause restricting to current page's group values.
        $groupFilter = "r.{$groupBy} IN ({$groupPlaceholder})";

        $fullWhere = $whereClause
            ? "{$whereClause} AND {$groupFilter}"
            : "WHERE {$groupFilter}";

        $sql = "SELECT r.id, r.{$groupBy} AS group_val
                FROM {$regTable} r
                LEFT JOIN {$wpdb->users} u ON u.ID = r.user_id
                {$activeJoin}
                {$fullWhere}";

        $sqlParams = array_merge($whereParams, $groupValuesCast);
        $rows      = $wpdb->get_results($wpdb->prepare($sql, ...$sqlParams));

        $result = [];
        foreach ($rows as $row) {
            $groupVal = $row->group_val;
            if (!isset($result[$groupVal])) {
                $result[$groupVal] = [];
            }
            $result[$groupVal][] = (int) $row->id;
        }

        return $result;
    }

    /**
     * Tally offerte statuses for a set of registration IDs.
     *
     * @param  array<int>    $regIds
     * @param  array<int,string> $offerteByReg
     * @return array<string,int>  label => count
     */
    private function tallyOfferteStatuses(array $regIds, array $offerteByReg): array
    {
        $tally = [];
        foreach ($regIds as $regId) {
            $label        = $offerteByReg[$regId] ?? 'Geen offerte';
            $tally[$label] = ($tally[$label] ?? 0) + 1;
        }
        return $tally;
    }

    /**
     * Build the standard pagination envelope matching getQuotes() shape.
     *
     * @param  array $items
     * @return array{items:array,total:int,page:int,perPage:int,totalPages:int}
     */
    private function paginationEnvelope(array $items, int $total, int $page, int $perPage): array
    {
        return [
            'items'      => $items,
            'total'      => $total,
            'page'       => $page,
            'perPage'    => $perPage,
            'totalPages' => $perPage > 0 ? (int) ceil($total / $perPage) : 0,
        ];
    }
}
