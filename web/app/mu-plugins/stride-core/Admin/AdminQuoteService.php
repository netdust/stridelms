<?php

declare(strict_types=1);

namespace Stride\Admin;

use Stride\Domain\Money;
use Stride\Domain\QuoteStatus;
use Stride\Infrastructure\BatchQueryHelper;
use Stride\Modules\Edition\EditionCPT;
use Stride\Modules\Invoicing\QuoteCPT;
use Stride\Modules\Invoicing\QuoteRepository;

/**
 * Read-model assembly for the admin quote list.
 *
 * Thin service — owns WHERE-clause assembly, batch-resolve (users/editions/meta),
 * and the read-model formatting. No raw $wpdb SELECTs of its own: delegates to
 * QuoteRepository (countAdminList / findAdminListRows / findUserIdsByNameOrEmail)
 * and BatchQueryHelper (INV-3). Does NOT contain business logic.
 *
 * Strangled from AdminAPIController::getQuotes (Task D1). The Phase-1
 * search-short-circuit envelope divergence (data/total/page/per_page on a
 * zero-user match) was REMOVED at the Offertes slice (F-A8/F-O2): search now
 * also matches quote numbers, so there is no zero-match branch and every path
 * returns the one main envelope (items/total/page/perPage/totalPages). The
 * client-side quoteRows() normalizer stays as defensive tolerance only.
 *
 * Registered in plugin-config.php.
 */
final class AdminQuoteService
{
    public function __construct(
        private readonly QuoteRepository $quotes,
    ) {}

    /**
     * Build the admin quote-list read-model for the given (pre-sanitised) filters.
     *
     * @param array{page:int,per_page:int,search:string,status:string,edition_id:int,tag?:int,date_from?:string,date_to?:string} $filters
     * @return array<string,mixed>  The main envelope (items/total/page/perPage/totalPages) — always.
     */
    public function getQuoteList(array $filters): array
    {
        global $wpdb;

        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = max(1, (int) ($filters['per_page'] ?? 20));
        $search = (string) ($filters['search'] ?? '');
        $status = (string) ($filters['status'] ?? '');
        $editionId = (int) ($filters['edition_id'] ?? 0);
        $tagId = (int) ($filters['tag'] ?? 0);
        $dateFrom = (string) ($filters['date_from'] ?? '');
        $dateTo = (string) ($filters['date_to'] ?? '');
        $offset = ($page - 1) * $perPage;

        // Build query
        $where = ["p.post_type = %s", "p.post_status = 'publish'"];
        $params = [QuoteCPT::POST_TYPE];

        // Search matches CUSTOMER (name/e-mail, resolved to user ids first so
        // the main query filters by meta IN (...) instead of a double LIKE
        // join per candidate quote) OR QUOTE NUMBER (F-O2 — the search box
        // promises "Zoek op nummer, klant…" but only the customer half
        // existed). With the number half in the OR there is NO zero-match
        // short-circuit anymore — and with it went the divergent
        // data/per_page envelope (F-A8): every path now returns the one main
        // items/totalPages envelope.
        if (!empty($search)) {
            $searchPattern = '%' . $wpdb->esc_like($search) . '%';

            // Number half — fragment owned by the repo (INV-3).
            [$numberSql, $numberParams] = $this->quotes->numberSearchWhereFragment($searchPattern, 'p');
            $or = [$numberSql];
            $orParams = $numberParams;

            // Customer half. ACCEPTED COST: this double-wildcard LIKE over
            // wp_users runs on every search (no O(1) no-match exit anymore —
            // the number half can still match) — single-digit ms at LMS scale
            // (thousands of users), admin-only, 350ms-debounced. Revisit only
            // if a large user migration lands. The finder caps at 500 ids; a
            // hit on that cap means customer matches were silently dropped —
            // trip-wire log so it can't degrade quietly.
            $matchedUserIds = array_map('intval', $this->quotes->findUserIdsByNameOrEmail($searchPattern));
            if (count($matchedUserIds) === 500) {
                ntdst_log('admin')->warning('AdminQuoteService: customer search hit the 500-user cap; results may be incomplete', [
                    'search' => $search,
                ]);
            }
            if (!empty($matchedUserIds)) {
                $idPlaceholders = implode(',', array_fill(0, count($matchedUserIds), '%d'));
                $or[] = "EXISTS (
                    SELECT 1 FROM {$wpdb->postmeta} pm_user
                    WHERE pm_user.post_id = p.ID
                    AND pm_user.meta_key = 'user_id'
                    AND pm_user.meta_value IN ({$idPlaceholders})
                )";
                array_push($orParams, ...$matchedUserIds);
            }

            $where[] = '(' . implode(' OR ', $or) . ')';
            array_push($params, ...$orParams);
        }

        // Filter by status. The read-model defaults a MISSING status meta row
        // to 'draft' (the badge shows "In behandeling"), so the draft filter
        // must also match quotes with no status row at all — otherwise the
        // filter and the badge disagree about the same row.
        if (!empty($status)) {
            $statusExists = "EXISTS (SELECT 1 FROM {$wpdb->postmeta} pm_status WHERE pm_status.post_id = p.ID AND pm_status.meta_key = 'status' AND pm_status.meta_value = %s)";
            if ($status === \Stride\Domain\QuoteStatus::Draft->value) {
                $where[] = "({$statusExists} OR NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} pm_status_any WHERE pm_status_any.post_id = p.ID AND pm_status_any.meta_key = 'status'))";
            } else {
                $where[] = $statusExists;
            }
            $params[] = $status;
        }

        // Filter by edition (item_id when item_type is edition)
        if ($editionId > 0) {
            $where[] = "EXISTS (SELECT 1 FROM {$wpdb->postmeta} pm_edition WHERE pm_edition.post_id = p.ID AND pm_edition.meta_key = 'edition_id' AND pm_edition.meta_value = %d)";
            $params[] = $editionId;
        }

        // Filter by course tag. Walk: quote.edition_id (bare meta) → that edition's
        // _ntdst_course_id (prefixed) → the course's term in the ld_course_tag
        // taxonomy. Taxonomy + both meta keys are HARDCODED literals; the only
        // user value is the term_id, cast (int) above and bound as %d below
        // (mirrors the EXISTS pattern of the search/status/edition_id filters —
        // no repo-signature change, the subquery rides $where/$params).
        if ($tagId > 0) {
            $where[] = "EXISTS (
                SELECT 1 FROM {$wpdb->postmeta} pm_q_ed
                JOIN {$wpdb->postmeta} pm_ed_course
                    ON pm_ed_course.post_id = CAST(pm_q_ed.meta_value AS UNSIGNED)
                   AND pm_ed_course.meta_key = '_ntdst_course_id'
                JOIN {$wpdb->term_relationships} tr
                    ON tr.object_id = CAST(pm_ed_course.meta_value AS UNSIGNED)
                JOIN {$wpdb->term_taxonomy} tt
                    ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'ld_course_tag'
                WHERE pm_q_ed.post_id = p.ID AND pm_q_ed.meta_key = 'edition_id' AND tt.term_id = %d
            )";
            $params[] = $tagId;
        }

        // Filter by quote date (p.post_date). Only inject a bound %s bound when the
        // value parses as a real Y-m-d — an unparseable value is IGNORED, never
        // concatenated into SQL.
        if ($dateFrom !== '' && \DateTime::createFromFormat('Y-m-d', $dateFrom) !== false) {
            $where[] = "p.post_date >= %s";
            $params[] = $dateFrom . ' 00:00:00';
        }
        if ($dateTo !== '' && \DateTime::createFromFormat('Y-m-d', $dateTo) !== false) {
            $where[] = "p.post_date <= %s";
            $params[] = $dateTo . ' 23:59:59';
        }

        $whereClause = implode(' AND ', $where);

        // Get total count
        $total = $this->quotes->countAdminList($whereClause, $params);

        // Get quotes
        $quotes = $this->quotes->findAdminListRows($whereClause, $params, $perPage, $offset);

        // Collect quote IDs for batch queries
        $quoteIds = array_map(fn($q) => (int) $q->ID, $quotes);

        // Batch fetch all quote meta
        $quoteMeta = BatchQueryHelper::batchGetPostMeta($quoteIds, [
            'quote_number', 'status', 'total', 'subtotal',
            'tax', 'user_id', 'edition_id', 'sent_at',
            'valid_until', 'items', 'billing', 'locked',
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

            // Get user info from batch. A DELETED WP account keeps its stale
            // user_id in quote meta — emit id 0 for it (the roster rule,
            // lead-identity invariant), so the client's "has a dossier"
            // checks (Dossier button, openPerson) can key on id alone
            // instead of navigating to a nonexistent case view.
            $userName = '';
            $userEmail = '';
            $user = $users[$userId] ?? null;
            if ($user) {
                $userName = $user->display_name;
                $userEmail = $user->user_email;
            } else {
                $userId = 0;
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
                // Server-owned Dutch date label (INV-7) — the list renders a
                // Datum column now (F-O2: the date filter filtered a column
                // nobody could see). Raw-value fallback on an unparseable date.
                'dateLabel' => stride_format_date((string) $quote->post_date) ?: (string) $quote->post_date,
                // Locked = finalized on the edit screen (sent/exported); the
                // list shows the lock so an admin knows a row is no longer
                // editable BEFORE clicking through (F-O1).
                'locked' => (bool) ($meta['locked'] ?? false),
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

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'totalPages' => (int) ceil($total / $perPage),
        ];
    }

    /**
     * Convert quote line-item money fields from cents (storage) to euros (API).
     * Storage fields: unit_price, total. Other fields pass through unchanged.
     *
     * Moved VERBATIM from AdminAPIController::lineItemsToEuros (its only caller
     * was getQuotes — now this service).
     *
     * @param array<int,mixed> $items
     * @return array<int,mixed>
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
}
