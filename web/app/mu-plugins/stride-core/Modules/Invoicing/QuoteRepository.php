<?php

declare(strict_types=1);

namespace Stride\Modules\Invoicing;

use Stride\Domain\QuoteStatus;
use Stride\Infrastructure\AbstractRepository;

/**
 * Repository for quote data access.
 */
final class QuoteRepository extends AbstractRepository
{
    protected string $postType = QuoteCPT::POST_TYPE;

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

        return $this->model()->updateMetaBatch($quoteId, $data);
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
        return $this->model()->updateMetaBatch($quoteId, $data);
    }
}
