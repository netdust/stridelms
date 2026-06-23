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
 * Moved VERBATIM from AdminAPIController::getQuotes (Task D1, behavior-preserving
 * strangle) — same WHERE construction, same param order, same query semantics,
 * same response shape, including the deliberate search-short-circuit envelope
 * divergence (data/total/page/per_page vs the main items/.../totalPages).
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
     * @param array{page:int,per_page:int,search:string,status:string,edition_id:int} $filters
     * @return array<string,mixed>  Main envelope (items/total/page/perPage/totalPages),
     *                              OR the search-short-circuit envelope
     *                              (data/total/page/per_page) when the user search
     *                              resolves to zero users.
     */
    public function getQuoteList(array $filters): array
    {
        global $wpdb;

        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = max(1, (int) ($filters['per_page'] ?? 20));
        $search = (string) ($filters['search'] ?? '');
        $status = (string) ($filters['status'] ?? '');
        $editionId = (int) ($filters['edition_id'] ?? 0);
        $offset = ($page - 1) * $perPage;

        // Build query
        $where = ["p.post_type = %s", "p.post_status = 'publish'"];
        $params = [QuoteCPT::POST_TYPE];

        // Search by user name or email. Resolve to user IDs first so the main
        // quote query only filters by meta_value IN (...) instead of running a
        // double LIKE join against wp_users for every candidate quote.
        if (!empty($search)) {
            $searchPattern = '%' . $wpdb->esc_like($search) . '%';
            $matchedUserIds = $this->quotes->findUserIdsByNameOrEmail($searchPattern);

            if (empty($matchedUserIds)) {
                // No matching users — short-circuit with an empty result set.
                return [
                    'data'     => [],
                    'total'    => 0,
                    'page'     => $page,
                    'per_page' => $perPage,
                ];
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
        $total = $this->quotes->countAdminList($whereClause, $params);

        // Get quotes
        $quotes = $this->quotes->findAdminListRows($whereClause, $params, $perPage, $offset);

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
