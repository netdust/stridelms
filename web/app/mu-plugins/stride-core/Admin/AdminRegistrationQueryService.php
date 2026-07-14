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
        $params = $this->applyScopePins($params);
        if (is_wp_error($params)) {
            return $params;
        }

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
        // both. Existing envelope keys are unchanged. The flat total is passed
        // so a queue view's single-bar funnel can be derived instead of
        // re-shipping the whole queue id-set in a second GROUP BY query.
        $page['statusCounts'] = $this->statusCounts(
            $params,
            $groupBy === null ? (int) $page['total'] : null,
        );

        return $page;
    }

    /**
     * Resolve the queue pin and the default active-edition scope into the
     * server-owned id-set filter keys — THE one place scope enters a grid
     * filter set. Every consumer of buildGridFilters that starts from CLIENT
     * filter input must route through here first: the grid read (getGridPage)
     * AND the bulk select-all expansion (BulkRunner::resolveBulkIds). Skipping
     * this step made a select-all expand UNSCOPED over the whole registrations
     * table while the grid showed a scoped subset — mutating rows the admin
     * never saw (review 2026-07-14, blast-radius regression).
     *
     * Two injections, mirroring what the grid read renders:
     *  - queue → queue_ids: the SAME id-set the Vandaag card counted
     *    (WorklistQueueResolver — one definition, RC-2). The ids already
     *    encode the active-edition scope, so the scope pin is skipped.
     *    An unknown queue key is a hard 400 — never a silent no-filter.
     *  - default 'active' scope → active_edition_ids: resolved ONCE (memoized
     *    in the resolver). Omitted for edition_scope=all, an explicit
     *    edition_id (a picked edition is reachable regardless of status), or
     *    a queue pin. The repository carries no scope SQL of its own.
     *
     * @param  array<string,mixed> $params  Client filter/request params.
     * @return array<string,mixed>|\WP_Error $params with queue_ids/active_edition_ids injected.
     */
    public function applyScopePins(array $params): array|\WP_Error
    {
        if (!empty($params['queue'])) {
            $resolver = ntdst_get(WorklistQueueResolver::class);
            $queueIds = $resolver->idsForQueue((string) $params['queue']);
            if ($queueIds === null) {
                return new \WP_Error(
                    'invalid_queue',
                    __('Onbekende wachtrij.', 'stride'),
                    ['status' => 400],
                );
            }
            $params['queue_ids'] = $queueIds;
            $params['edition_scope'] = 'all';
        }

        $editionScope = (string) ($params['edition_scope'] ?? 'active');
        if (
            $editionScope !== 'all'
            && empty($params['edition_id'])
            && !array_key_exists('queue_ids', $params)
        ) {
            $params['active_edition_ids'] = ntdst_get(WorklistQueueResolver::class)->activeEditionIds();
        }

        // Trip-wire marker: buildGridFilters warns loudly when it receives a
        // filter set that never passed through here — the repo API cannot
        // enforce scope by construction (the resolver DI would cycle), so an
        // unscoped call from a future surface must at least be a loud log,
        // never a silent whole-table read (the 2026-07-14 blast-radius class).
        $params['scope_pins_applied'] = true;

        return $params;
    }

    /**
     * Build the zero-filled per-status count map for the funnel.
     *
     * Delegates the count to RegistrationRepository::statusBreakdown (INV-3 —
     * structured columns through the repo, the status filter dropped there), then
     * zero-fills every RegistrationStatus so each funnel chip always has a number.
     *
     * QUEUE SHORTCUT: a queue view's funnel is derivable — every queue's
     * id-set is status-homogeneous (WorklistQueueResolver::statusForQueue,
     * contract-tested) and the queue clears the status filter, so the GROUP BY
     * over the pinned ids could only ever return one row equal to the flat
     * total the page already computed. Deriving it skips re-shipping the
     * whole queue id-set as an IN() list a second time per interaction.
     *
     * @param  array<string,mixed> $params    The grid request params (post-applyScopePins).
     * @param  int|null            $flatTotal The flat path's total, when known (null in grouped mode).
     * @return array<string,int>  status value => count, all enum cases present.
     */
    private function statusCounts(array $params, ?int $flatTotal = null): array
    {
        if (!RegistrationTable::exists()) {
            return $this->zeroStatusCounts();
        }

        // Only when no status filter composes on top (the funnel drops the
        // status filter, the flat total would not — a client never sends both,
        // but the derivation must not silently diverge if one ever does).
        if ($flatTotal !== null && !empty($params['queue']) && empty($params['status'])) {
            $queueStatus = WorklistQueueResolver::statusForQueue((string) $params['queue']);
            if ($queueStatus !== null) {
                return array_merge($this->zeroStatusCounts(), [$queueStatus => $flatTotal]);
            }
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

        // Two-step offerte resolver over this page's reg ids.
        $regIds       = array_map(static fn($row) => (int) $row->id, $rows);
        $offerteByReg = $this->resolveOfferteStatuses($regIds);

        $items = $this->composeFromRows($rows, $offerteByReg);

        return $this->paginationEnvelope($items, $total, $page, $perPage);
    }

    /**
     * Collect ids from a batch of raw rows, run the batch resolves, and compose
     * the read-model items — the resolve+compose half shared by the flat path and
     * the grouped child-row path so there is no second copy of the batch-resolve
     * or the row composition (INV-3: one composer, one resolve shape).
     *
     * The offerte map is passed IN (already resolved by the caller) because the
     * grouped path resolves it once over the FULL group id set; composeRows reads
     * it per-regId, so a map keyed by a superset of these rows' ids is fine.
     *
     * @param  array             $rows          Raw registration row objects.
     * @param  array<int,string> $offerteByReg  regId => offerte label (may be a superset).
     * @return array
     */
    private function composeFromRows(array $rows, array $offerteByReg): array
    {
        if (empty($rows)) {
            return [];
        }

        // --- Collect IDs for batch resolution ---
        $userIds       = [];
        $editionIds    = [];
        $trajectoryIds = [];

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

        // Lead rows whose e-mail belongs to an EXISTING account (safer identity
        // variant: such rows stay leads until promotion — the admin must see
        // the match to act on it). One batched query over this page's lead
        // e-mails, never per-row lookups.
        $leadEmails = [];
        foreach ($rows as $row) {
            $userId = (int) $row->user_id;
            // Same lead predicate as $isAnon below: no positive user record
            // (batchGetUsers seeds misses with null, so isset() covers both
            // the missing and the deleted-user case).
            if (($userId <= 0 || !isset($users[$userId])) && !empty($row->lead_email)) {
                $leadEmails[] = (string) $row->lead_email;
            }
        }
        $accountsByEmail = !empty($leadEmails)
            ? BatchQueryHelper::batchGetUsersByEmail($leadEmails)
            : [];

        // Session counts per edition (for attendance %).
        // Delegated to SessionRepository (INV-3): dynamic meta prefix, published-only,
        // all input ids present (0 default) — no raw $wpdb / hardcoded meta key here.
        $sessionCountByEdition = !empty($editionIds)
            ? ntdst_get(\Stride\Modules\Edition\SessionRepository::class)->countByEditions($editionIds)
            : [];

        // Attendance per edition (keyed per edition → userId → sessionId → status).
        // ONE batched query over edition_id IN (...) — the SHOW TABLES existence
        // probe + SELECT are hoisted out of the former per-edition loop (perf 4B.2).
        $attendanceByEdition = !empty($editionIds)
            ? BatchQueryHelper::batchGetAttendanceForEditions($editionIds)
            : [];

        // --- Per-row assembly (no queries below this point) ---
        // The identity/status/attendance/company/trajectory/offerte shape shared by
        // BOTH the flat and grouped-child-row paths — the single row-composer. All
        // reads are against the already-resolved batch maps above.
        $items = [];
        foreach ($rows as $row) {
            $userId    = (int) $row->user_id;
            $editionId = (int) ($row->edition_id ?? 0);
            $regId     = (int) $row->id;

            $user   = $userId > 0 ? ($users[$userId] ?? null) : null;
            // Anonymous = no resolvable user RECORD, not merely user_id <= 0:
            // a registration whose WP user was since deleted must fall back to
            // the lead columns / '(anoniem)' exactly like the roster does —
            // keying on the id alone rendered those rows with a blank Naam.
            $isAnon = $user === null;

            // Identity. Logged-in rows resolve from the joined user record.
            // Anonymous rows (no account, or a deleted account) fall back to
            // the denormalized lead identity columns (v5), stamped by the
            // SAME extractor the search columns use.
            if ($isAnon) {
                $identity = $this->resolveAnonymousIdentity($row);
                $name  = $identity['name'];
                $email = $identity['email'];
            } else {
                $name  = $user->display_name ?? '';
                $email = $user->user_email ?? '';
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

            // A lead whose e-mail matches an existing account (not bound —
            // binding only happens for self-submission or at promotion).
            $accountMatch = null;
            if ($isAnon && !empty($row->lead_email)) {
                $matched = $accountsByEmail[strtolower((string) $row->lead_email)] ?? null;
                if ($matched !== null) {
                    $accountMatch = [
                        'id'   => (int) $matched->ID,
                        'name' => (string) ($matched->display_name ?? ''),
                    ];
                }
            }

            $items[] = [
                'id'           => $regId,
                'user'         => [
                    'id'    => $userId,
                    'name'  => $name,
                    'email' => $email,
                ],
                'anonymous'    => $isAnon,
                'accountMatch' => $accountMatch,
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
     * Resolve the captured name/email for an anonymous row from the
     * denormalized lead_name/lead_email columns (schema v5).
     *
     * Thin delegate: the '(anoniem)' fallback rule lives ONCE on
     * RegistrationRepository::presentLeadIdentity (INV-3 — the edition roster
     * reads the same presenter, so one row can never render two identities
     * across admin surfaces). The columns are stamped on every write path AND
     * backfilled via ONE extractor, so this read shares the exact identity
     * definition the grid SEARCH matches against.
     *
     * @param  object $row  Grid row with ->lead_name / ->lead_email.
     * @return array{name:string,email:string}
     */
    private function resolveAnonymousIdentity(object $row): array
    {
        return RegistrationRepository::presentLeadIdentity($row);
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
        $result    = $this->registrations->queryForGridGrouped($params, $groupBy);
        $aggRows   = $result['agg_rows'];
        $groupRows = $result['group_rows'];
        $total     = $result['total'];
        $page      = $result['page'];
        $perPage   = $result['per_page'];

        if (empty($aggRows)) {
            return $this->paginationEnvelope([], $total, $page, $perPage);
        }

        // Per-group offerte tally — computed IN SQL (FIX-10). Replaces the old
        // path that pulled every registration id of every visible group into PHP
        // just to count offerte labels (OOM at 50k rows). The repo returns raw
        // [group_value => (rawStatus|'') => count]; we map raw → label here (the
        // SAME translation resolveOfferteStatuses uses), so the enum→label output
        // is byte-identical to the old tally.
        $rawVerdeling  = $this->registrations->offerteVerdelingByGroup($params, $groupBy);
        $offerteTallies = [];
        foreach ($rawVerdeling as $groupKey => $statusCounts) {
            $offerteTallies[$groupKey] = $this->labelOfferteTally($statusCounts);
        }

        // Compose the capped child rows ONCE over the UNION of every group's
        // (≤ GROUP_ROW_CAP) rows, via the SHARED composer — same identity/company/
        // status/trajectory/offerte assembly as the flat grid, so grouped child
        // rows can expose no row and no field the flat grid does not (INV-3/INV-7).
        // This offerte resolve is over the BOUNDED capped union only (≤ GROUP_ROW_CAP
        // per group), never the unbounded full id set — that is the whole point of
        // FIX-10: only the tally moved to SQL; the capped-row resolve stays bounded.
        $composedByRegId = [];
        $cappedUnion     = $groupRows ? array_merge(...array_values($groupRows)) : [];
        if (!empty($cappedUnion)) {
            $cappedIds        = array_map(static fn($r) => (int) $r->id, $cappedUnion);
            $cappedOfferteMap = $this->resolveOfferteStatuses($cappedIds);
            foreach ($this->composeFromRows($cappedUnion, $cappedOfferteMap) as $item) {
                $composedByRegId[$item['id']] = $item;
            }
        }

        // Server-resolved group display labels (F-G10 tail): the client used to
        // resolve edition group headers against its capped edition-options
        // list, degrading unlisted editions to "Editie #123". One batched read
        // over THIS page's group values instead.
        $groupLabels = [];
        if ($groupBy === 'edition_id') {
            $editionIds = array_values(array_filter(array_map(
                static fn($row) => (int) ($row->group_value ?? 0),
                $aggRows,
            )));
            $editionPosts = !empty($editionIds)
                ? BatchQueryHelper::batchGetPosts($editionIds, EditionCPT::POST_TYPE)
                : [];
            foreach ($editionIds as $editionId) {
                $groupLabels[$editionId] = isset($editionPosts[$editionId])
                    ? (string) $editionPosts[$editionId]->post_title
                    : sprintf(__('Editie #%d', 'stride'), $editionId);
            }
        }

        // Compose aggregate items, attaching each group's composed child rows.
        $items = [];
        foreach ($aggRows as $row) {
            $count       = (int) $row->cnt;
            $completed   = (int) $row->completed_count;
            $pctAfgerond = $count > 0 ? (int) round($completed / $count * 100) : 0;

            // Look up the SQL tally by the same key convention the repo uses:
            // '' for a NULL group_value, (string) value otherwise.
            $groupKey     = $row->group_value === null ? '' : (string) $row->group_value;
            $offerteTally = $offerteTallies[$groupKey] ?? [];

            // Bucket the composed rows back to this group, preserving the repo's
            // registered_at DESC order (group_rows is already ordered + capped).
            $groupChildRows = [];
            foreach ($groupRows[$row->group_value] ?? [] as $rawRow) {
                $regId = (int) $rawRow->id;
                if (isset($composedByRegId[$regId])) {
                    $groupChildRows[] = $composedByRegId[$regId];
                }
            }

            $items[] = [
                'group_value'        => $row->group_value,
                // Server-resolved header label (edition groups only — status
                // labels are a closed client enum, company has no name entity).
                'group_label'        => $groupLabels[(int) ($row->group_value ?? 0)] ?? null,
                'count'              => $count,
                'pct_afgerond'       => $pctAfgerond,
                'avg_attendance_pct' => null,  // Deferred: cross-edition avg requires per-row resolution
                'offerte_verdeling'  => $offerteTally,
                'rows'               => $groupChildRows,  // ≤ GROUP_ROW_CAP composed child rows
                'row_total'          => $count,           // full group size — client shows "Toon alle N" when > count(rows)
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
     * Translate a raw per-group offerte tally (from RegistrationRepository::
     * offerteVerdelingByGroup) into the Dutch-label tally the grid renders.
     *
     * The raw map is keyed by the quote's stored status meta value ('' for the
     * no-quote / null-status bucket). This applies the SAME label translation as
     * resolveOfferteStatuses so the counts and labels are byte-identical to the
     * pre-FIX-10 PHP tally: '' → 'Geen offerte'; a valid QuoteStatus → label();
     * an unknown raw status → the raw value verbatim. Two raw statuses that map to
     * the same label are summed (defensive — cannot happen with the current enum).
     *
     * @param  array<string,int> $statusCounts  rawStatus|'' => count
     * @return array<string,int>  label => count
     */
    private function labelOfferteTally(array $statusCounts): array
    {
        $tally = [];
        foreach ($statusCounts as $rawStatus => $count) {
            if ($rawStatus === '') {
                $label = 'Geen offerte';
            } else {
                $enum  = QuoteStatus::tryFrom((string) $rawStatus);
                $label = $enum !== null ? $enum->label() : (string) $rawStatus;
            }
            $tally[$label] = ($tally[$label] ?? 0) + (int) $count;
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
