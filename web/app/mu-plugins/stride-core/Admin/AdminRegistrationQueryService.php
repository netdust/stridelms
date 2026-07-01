<?php

declare(strict_types=1);

namespace Stride\Admin;

use Stride\Domain\QuoteStatus;
use Stride\Domain\RegistrationStatus;
use Stride\Infrastructure\BatchQueryHelper;
use Stride\Modules\Edition\EditionCPT;
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
     * @return array{items:array,total:int,page:int,perPage:int,totalPages:int}|\WP_Error
     */
    public function getGridPage(array $params): array|\WP_Error
    {
        $groupBy = $params['group_by'] ?? null;

        $page = $groupBy !== null
            ? $this->getGroupedPage($params, (string) $groupBy)
            : $this->getFlatPage($params);

        if (is_wp_error($page)) {
            return $page;
        }

        // ADDITIVE (Task 3.3 Part B): per-status funnel counts. Reflects the
        // current filter set MINUS the status filter itself, so the pipeline
        // funnel shows the live distribution under the OTHER active filters
        // (F4 acceptance). Independent of the flat/grouped shape — same key on
        // both. Existing envelope keys are unchanged.
        $page['statusCounts'] = $this->statusCounts($params);

        return $page;
    }

    /**
     * Build the zero-filled per-status count map for the funnel.
     *
     * Delegates the count to RegistrationRepository::statusBreakdown (INV-3 —
     * structured columns through the repo, the status filter dropped there), then
     * zero-fills every RegistrationStatus so each funnel chip always has a number.
     *
     * @param  array<string,mixed> $params  The grid request params.
     * @return array<string,int>  status value => count, all enum cases present.
     */
    private function statusCounts(array $params): array
    {
        if (!RegistrationTable::exists()) {
            return $this->zeroStatusCounts();
        }

        $breakdown = $this->registrations->statusBreakdown($params);

        return array_merge($this->zeroStatusCounts(), array_intersect_key(
            $breakdown,
            $this->zeroStatusCounts(),
        ));
    }

    /**
     * @return array<string,int>  every RegistrationStatus value => 0.
     */
    private function zeroStatusCounts(): array
    {
        $zero = [];
        foreach (RegistrationStatus::cases() as $case) {
            $zero[$case->value] = 0;
        }
        return $zero;
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

        // Session counts per edition (for attendance %).
        // Delegated to SessionRepository (INV-3): dynamic meta prefix, published-only,
        // all input ids present (0 default) — no raw $wpdb / hardcoded meta key here.
        $sessionCountByEdition = !empty($editionIds)
            ? ntdst_get(\Stride\Modules\Edition\SessionRepository::class)->countByEditions($editionIds)
            : [];

        // Attendance per edition (keyed per edition → userId → sessionId → status)
        $attendanceByEdition = [];
        foreach ($editionIds as $editionId) {
            $attendanceByEdition[$editionId] = BatchQueryHelper::batchGetAttendance($editionId);
        }

        // Two-step offerte resolver
        $offerteByReg = $this->resolveOfferteStatuses($regIds);

        $items = $this->composeRows(
            $rows,
            $users,
            $editions,
            $trajectories,
            $sessionCountByEdition,
            $attendanceByEdition,
            $offerteByReg,
        );

        return $this->paginationEnvelope($items, $total, $page, $perPage);
    }

    /**
     * Compose the per-row read-model items from a resolved batch of rows.
     *
     * The per-row assembly (identity/status/attendance/company/trajectory/offerte)
     * shared by the flat and grouped-child-row paths. Takes the already-resolved
     * batch maps as params; issues no queries of its own.
     *
     * @param array                    $rows                  Raw registration row objects.
     * @param array                    $users                 userId => WP_User.
     * @param array                    $editions              editionId => WP_Post.
     * @param array                    $trajectories          trajectoryId => WP_Post.
     * @param array<int,int>           $sessionCountByEdition editionId => session count.
     * @param array                    $attendanceByEdition   editionId => attendance map.
     * @param array<int,string>        $offerteByReg          regId => offerte status.
     * @return array
     */
    private function composeRows(
        array $rows,
        array $users,
        array $editions,
        array $trajectories,
        array $sessionCountByEdition,
        array $attendanceByEdition,
        array $offerteByReg,
    ): array {
        $items = [];
        foreach ($rows as $row) {
            $userId    = (int) $row->user_id;
            $editionId = (int) ($row->edition_id ?? 0);
            $regId     = (int) $row->id;

            $user   = $userId > 0 ? ($users[$userId] ?? null) : null;
            $isAnon = $userId <= 0;

            // Identity. Logged-in rows resolve from the joined user record.
            // Anonymous interest/waitlist rows (user_id 0/NULL) have no user
            // record — fall back to the name/email captured in enrollment_data,
            // mirroring the per-edition roster path (INV-3: identical decode).
            if ($isAnon) {
                $identity = $this->resolveAnonymousIdentity($row);
                $name  = $identity['name'];
                $email = $identity['email'];
            } else {
                $name  = $user?->display_name ?? '';
                $email = $user?->user_email ?? '';
            }

            // Status
            $statusEnum  = RegistrationStatus::tryFrom((string) $row->status);
            $statusLabel = $statusEnum?->label() ?? (string) $row->status;

            // Attendance %
            $attendancePct = $this->computeAttendancePct(
                $userId,
                $editionId,
                $attendanceByEdition[$editionId] ?? [],
                $sessionCountByEdition[$editionId] ?? 0,
            );

            // Company — TWO INDEPENDENT identifiers, deliberately NOT one resolved entity
            // (CLAUDE.md: organisation ≠ billing_company, never conflate):
            //   - company.id   = the registration row's company_id FK (partner-affiliation
            //                    scoping id, _stride_company_id; NOT a name-resolvable post/term).
            //   - company.name = the USER's billing_company usermeta (invoice company).
            // company_id has no name source to resolve from, so the name field reflects the
            // user's billing company; the two are surfaced side-by-side, not merged.
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
                    'name'  => $name,
                    'email' => $email,
                ],
                'anonymous'    => $isAnon,
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

        return $items;
    }

    /**
     * Resolve the captured name/email for an anonymous (user_id 0/NULL) row
     * from its enrollment_data JSON.
     *
     * Anonymous interest/waitlist submissions store the submitter's identity
     * in the enrollment_data envelope: enrollment_data[<stage>]['data']['name'|'email'],
     * where <stage> equals the row's status ('interest' or 'waitlist').
     *
     * Decode semantics are IDENTICAL to the per-edition roster path
     * (AdminAPIController::formatEditionRoster) — INV-3: the two reads of this
     * one concern must not drift. Same envelope path, same '(anoniem)' / ''
     * defaults.
     *
     * @param  object $row  Grid row with ->status and ->enrollment_data (raw JSON string|null).
     * @return array{name:string,email:string}
     */
    private function resolveAnonymousIdentity(object $row): array
    {
        $stageData = [];
        $raw = $row->enrollment_data ?? '';
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                // status maps to the stage key (interest/waitlist).
                // Wrapped shape: $decoded[$status]['data'][field].
                $stageEnvelope = $decoded[$row->status] ?? [];
                $stageData = is_array($stageEnvelope['data'] ?? null) ? $stageEnvelope['data'] : [];
            }
        }

        return [
            'name'  => $stageData['name'] ?? '(anoniem)',
            'email' => $stageData['email'] ?? '',
        ];
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
     *  - avg_attendance_pct: null (deferred — cross-edition avg requires per-row resolution)
     *  - offerte_verdeling: tally of offerte statuses within the group
     *
     * Delegates ALL filter/WHERE/JOIN construction to
     * RegistrationRepository::queryForGridGrouped so that q, active-scope,
     * edition_id, company_id and status are applied IDENTICALLY to the flat
     * path — no second divergent copy of the scoping logic.
     *
     * @param  string $groupBy  Allowlisted column (validated below via GROUP_BY_ALLOWLIST).
     * @return array{items:array,total:int,page:int,perPage:int,totalPages:int}|\WP_Error
     */
    private function getGroupedPage(array $params, string $groupBy): array|\WP_Error
    {
        // Allowlist guard — single definition lives on the repository (M4).
        // An out-of-allowlist group_by changes the response SHAPE (aggregates vs
        // rows), so it must be a hard 400 — NOT a silent empty 200 that is
        // indistinguishable from no-data. (The M4 sort fallback stays a fallback:
        // a bad sort only reorders, it does not reshape the response.)
        if (!in_array($groupBy, RegistrationRepository::GROUP_BY_ALLOWLIST, true)) {
            return new \WP_Error(
                'invalid_group_by',
                sprintf(
                    /* translators: %s: the rejected group_by value */
                    __('Ongeldige group_by-waarde: %s', 'stride'),
                    $groupBy,
                ),
                ['status' => 400],
            );
        }

        if (!RegistrationTable::exists()) {
            return $this->paginationEnvelope([], 0, 1, 50);
        }

        // Delegate to the repository: identical WHERE/JOIN construction to
        // queryForGrid — q, active-scope, edition_id, company_id, status all applied.
        $result      = $this->registrations->queryForGridGrouped($params, $groupBy);
        $aggRows     = $result['agg_rows'];
        $groupRegIds = $result['group_reg_ids'];
        $total       = $result['total'];
        $page        = $result['page'];
        $perPage     = $result['per_page'];

        if (empty($aggRows)) {
            return $this->paginationEnvelope([], $total, $page, $perPage);
        }

        // All reg IDs across visible groups — for the bounded two-step offerte resolver.
        $allRegIds    = array_merge(...array_values($groupRegIds));
        $offerteByReg = !empty($allRegIds) ? $this->resolveOfferteStatuses($allRegIds) : [];

        // Compose aggregate items.
        $items = [];
        foreach ($aggRows as $row) {
            $count       = (int) $row->cnt;
            $completed   = (int) $row->completed_count;
            $pctAfgerond = $count > 0 ? (int) round($completed / $count * 100) : 0;

            $groupRids    = $groupRegIds[$row->group_value] ?? [];
            $offerteTally = $this->tallyOfferteStatuses($groupRids, $offerteByReg);

            $items[] = [
                'group_value'        => $row->group_value,
                'count'              => $count,
                'pct_afgerond'       => $pctAfgerond,
                'avg_attendance_pct' => null,  // Deferred: cross-edition avg requires per-row resolution
                'offerte_verdeling'  => $offerteTally,
            ];
        }

        return $this->paginationEnvelope($items, $total, $page, $perPage);
    }

    /**
     * Public accessor for the two-step offerte (paid-proxy) resolver.
     *
     * The SINGLE definition of "what offerte status does this registration have"
     * (Sibling-site audit item 1). The grid offerte column reads it via the
     * private resolveOfferteStatuses(); the Vandaag "Offerte-opvolging" queue
     * count (AdminStatsService::getWorklistQueueCounts) reads it here. There is
     * no second "confirmed AND quote != Exported" definition anywhere — callers
     * compare the returned label against QuoteStatus::Exported->label().
     *
     * @param  array<int> $regIds
     * @return array<int,string>  regId => Dutch offerte label ('Geen offerte' when
     *                            no quote; absent from the map is also "no quote").
     */
    public function offerteStatusesForRegistrations(array $regIds): array
    {
        return $this->resolveOfferteStatuses($regIds);
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
        int $sessionCount,
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

        // Clamp to [0,100]: the numerator (present marks) is NOT publish-filtered
        // while the denominator (sessionCount) is published-only, so a session
        // trashed after attendance was marked can push present > sessionCount → >100%.
        // Clamping prevents the impossible value (aligning publish-scope would be a
        // larger change).
        return min(100, (int) round($presentCount / $sessionCount * 100));
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
