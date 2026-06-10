<?php

declare(strict_types=1);

namespace Stride\Modules\Invoicing;

use Stride\Domain\QuoteStatus;
use Stride\Infrastructure\AbstractRepository;
use WP_Error;
use WP_Post;

/**
 * Repository for quote data access.
 */
final class QuoteRepository extends AbstractRepository
{
    protected string $postType = QuoteCPT::POST_TYPE;

    /**
     * Quote writes are spread across QuoteService, QuoteAdminController,
     * QuoteUpdateHandler and QuotePDFGenerator, but they ALL converge on this
     * repository's write methods. Fire one signal here so per-request memos
     * (UserDashboardService) invalidate on every quote write path.
     */
    private function flagDataChanged(): void
    {
        do_action('stride/quote/data_changed');
    }

    public function create(array $data): WP_Post|WP_Error
    {
        $result = parent::create($data);

        if (!is_wp_error($result)) {
            $this->flagDataChanged();
        }

        return $result;
    }

    public function update(int $id, array $data): WP_Post|WP_Error
    {
        $result = parent::update($id, $data);

        if (!is_wp_error($result)) {
            $this->flagDataChanged();
        }

        return $result;
    }

    public function delete(int $id, bool $force = false): bool|WP_Error
    {
        $result = parent::delete($id, $force);

        if ($result === true) {
            $this->flagDataChanged();
        }

        return $result;
    }

    /**
     * Find quotes for a user.
     *
     * @return array<array<string, mixed>>
     */
    public function findByUser(int $userId, ?string $status = null): array
    {
        $query = $this->model()
            ->where('user_id', $userId)
            ->where('post_status', 'publish')
            ->orderBy('post_date', 'DESC');

        if ($status !== null) {
            $query = $query->where('status', $status);
        }

        return $query->withMeta()->get();
    }

    /**
     * Find all quotes linked to an edition.
     *
     * @return array<array<string, mixed>>
     */
    public function findByEdition(int $editionId): array
    {
        return $this->model()
            ->where('edition_id', $editionId)
            ->where('post_status', 'publish')
            ->orderBy('post_date', 'DESC')
            ->withMeta()
            ->get();
    }

    /**
     * Find quote by registration ID.
     */
    public function findByRegistration(int $registrationId): ?array
    {
        $results = $this->model()
            ->where('registration_id', $registrationId)
            ->where('post_status', 'publish')
            ->limit(1)
            ->withMeta()
            ->get();

        return $results[0] ?? null;
    }

    /**
     * Map registration IDs to their linked published quote post ID, in one
     * grouped query (replaces the per-row lookup in the registrations CSV
     * export — audit 2.6). When multiple quotes link to one registration the
     * lowest post ID wins (deterministic refinement of the old LIMIT 1).
     *
     * @param array<int> $registrationIds
     * @return array<int, int> Map of registrationId => quote post ID
     */
    public function findQuoteIdsByRegistrations(array $registrationIds): array
    {
        if (empty($registrationIds)) {
            return [];
        }

        global $wpdb;

        $registrationIds = array_values(array_unique(array_map('intval', $registrationIds)));
        // String placeholders: registration_id meta is stored as a string,
        // matching the previous per-row `pm.meta_value = %s` comparison.
        $placeholders = implode(',', array_fill(0, count($registrationIds), '%s'));

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT pm.meta_value AS registration_id, MIN(p.ID) AS quote_id
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
             WHERE p.post_type = %s AND p.post_status = 'publish'
             AND pm.meta_key = 'registration_id' AND pm.meta_value IN ({$placeholders})
             GROUP BY pm.meta_value",
            QuoteCPT::POST_TYPE,
            ...array_map('strval', $registrationIds),
        ));

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row->registration_id] = (int) $row->quote_id;
        }

        return $map;
    }

    /**
     * Find quote by quote number.
     */
    public function findByNumber(string $quoteNumber): ?array
    {
        $results = $this->model()
            ->where('quote_number', $quoteNumber)
            ->where('post_status', 'publish')
            ->limit(1)
            ->withMeta()
            ->get();

        return $results[0] ?? null;
    }

    /**
     * Get quotes pending export.
     *
     * @return array<array<string, mixed>>
     */
    public function findPendingExport(): array
    {
        return $this->model()
            ->where('status', QuoteStatus::Sent->value)
            ->where('post_status', 'publish')
            ->orderBy('post_date', 'ASC')
            ->withMeta()
            ->get();
    }

    /**
     * Generate next quote number.
     */
    public function generateQuoteNumber(): string
    {
        $year = date('Y');
        $prefix = "OFF-{$year}-";
        $cacheKey = 'stride_last_quote_number_' . $year;

        // Try to get from cache first
        $lastNumber = get_transient($cacheKey);

        if ($lastNumber === false) {
            // Find highest number for this year
            global $wpdb;
            $table = $wpdb->prefix . 'postmeta';
            $postsTable = $wpdb->prefix . 'posts';

            $lastNumber = $wpdb->get_var($wpdb->prepare(
                "SELECT MAX(CAST(SUBSTRING(meta_value, %d) AS UNSIGNED))
                 FROM {$table} pm
                 JOIN {$postsTable} p ON pm.post_id = p.ID
                 WHERE pm.meta_key = 'quote_number'
                 AND pm.meta_value LIKE %s
                 AND p.post_type = %s",
                strlen($prefix) + 1,
                $prefix . '%',
                QuoteCPT::POST_TYPE,
            ));

            $lastNumber = (int) $lastNumber;
        }

        $nextNumber = $lastNumber + 1;

        // Update cache with new number (1 hour TTL)
        set_transient($cacheKey, $nextNumber, HOUR_IN_SECONDS);

        return $prefix . str_pad((string) $nextNumber, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Update quote status.
     */
    public function updateStatus(int $quoteId, QuoteStatus $status): bool
    {
        $data = ['status' => $status->value];

        if ($status === QuoteStatus::Sent) {
            $data['sent_at'] = current_time('mysql');
        }

        $result = $this->model()->updateMetaBatch($quoteId, $data);

        if ($result) {
            $this->flagDataChanged();
        }

        return $result;
    }

    /**
     * Get field value from quote.
     */
    public function getField(int $quoteId, string $field, mixed $default = null): mixed
    {
        return $this->model()->getMeta($quoteId, $field, $default);
    }

    /**
     * Update quote meta fields.
     */
    public function updateMeta(int $quoteId, array $data): bool
    {
        $result = $this->model()->updateMetaBatch($quoteId, $data);

        if ($result) {
            $this->flagDataChanged();
        }

        return $result;
    }
}
