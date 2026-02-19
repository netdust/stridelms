<?php

declare(strict_types=1);

namespace Stride\Modules\Invoicing;

use Stride\Contracts\EditionQueryInterface;
use Stride\Domain\Money;
use Stride\Domain\QuoteStatus;
use Stride\Infrastructure\AbstractService;
use WP_Error;
use WP_Post;

/**
 * Quote business logic.
 */
final class QuoteService extends AbstractService
{
    public function __construct(
        private readonly QuoteRepository $repository,
        private readonly EditionQueryInterface $editions,
    ) {
        parent::__construct();
    }

    public static function metadata(): array
    {
        return [
            'name' => 'Quote Service',
            'description' => 'Manages quotes and invoices',
            'priority' => 20,
        ];
    }

    protected function getConfigSlug(): string
    {
        return 'invoicing';
    }

    protected function init(): void
    {
        // Instantiate quote-related handlers
        ntdst_get(\Stride\Handlers\EnrollmentQuoteHandler::class);
        ntdst_get(\Stride\Handlers\QuoteUpdateHandler::class);
    }

    /**
     * Create a quote for a registration.
     *
     * @param array<array{title: string, quantity: int, unit_price: Money}> $items
     * @param array<string, mixed> $billing
     */
    public function createQuote(
        int $userId,
        int $registrationId,
        int $editionId,
        array $items,
        array $billing = [],
        ?string $voucherCode = null,
        ?Money $discount = null,
    ): int|WP_Error {
        // Calculate totals
        $totals = QuoteCalculator::calculateTotals($items, $discount);

        // Generate quote number
        $quoteNumber = $this->repository->generateQuoteNumber();

        // Get edition title for quote title
        $edition = $this->editions->exists($editionId)
            ? get_post($editionId)
            : null;
        $title = $edition ? $edition->post_title : "Offerte {$quoteNumber}";

        // Format items for storage
        $storedItems = QuoteCalculator::formatItemsForStorage($items);

        // Create quote
        $result = $this->repository->create([
            'title' => $title,
            'user_id' => $userId,
            'registration_id' => $registrationId,
            'edition_id' => $editionId,
            'quote_number' => $quoteNumber,
            'status' => QuoteStatus::Draft->value,
            'items' => $storedItems,
            'subtotal' => $totals['subtotal']->inCents(),
            'discount' => $totals['discount']->inCents(),
            'tax' => $totals['tax']->inCents(),
            'total' => $totals['total']->inCents(),
            'billing' => $billing,
            'voucher_code' => $voucherCode,
            'valid_until' => date('Y-m-d', strtotime('+30 days')),
        ]);

        if (is_wp_error($result)) {
            return $result;
        }

        $quoteId = $result->ID;

        // Fire event
        $this->dispatch('quote/created', [
            'quote_id' => $quoteId,
            'user_id' => $userId,
            'registration_id' => $registrationId,
            'edition_id' => $editionId,
            'total' => $totals['total']->inCents(),
        ]);

        return $quoteId;
    }

    /**
     * Get quote by ID.
     *
     * @param bool $skipCache Set true after mutations to get fresh data
     */
    public function getQuote(int $quoteId, bool $skipCache = false): array|WP_Error
    {
        $result = $this->repository->find($quoteId, $skipCache);

        if (is_wp_error($result)) {
            return $result;
        }

        return $this->hydrateQuote($result);
    }

    /**
     * Get quotes for a user.
     *
     * @return array<array<string, mixed>>
     */
    public function getUserQuotes(int $userId): array
    {
        $quotes = $this->repository->findByUser($userId);

        return array_map([$this, 'hydrateQuote'], $quotes);
    }

    /**
     * Get quote by registration.
     */
    public function getQuoteByRegistration(int $registrationId): ?array
    {
        $quote = $this->repository->findByRegistration($registrationId);

        return $quote ? $this->hydrateQuote($quote) : null;
    }

    /**
     * Mark quote as sent.
     */
    public function markAsSent(int $quoteId): bool|WP_Error
    {
        $quote = $this->repository->find($quoteId);

        if (is_wp_error($quote)) {
            return $quote;
        }

        $status = QuoteStatus::tryFrom($quote->status ?? '');

        if ($status !== QuoteStatus::Draft) {
            return new WP_Error('invalid_status', 'Only draft quotes can be sent');
        }

        $result = $this->repository->updateStatus($quoteId, QuoteStatus::Sent);

        if ($result) {
            $this->dispatch('quote/sent', ['quote_id' => $quoteId]);
        }

        return $result;
    }

    /**
     * Cancel quote.
     */
    public function cancel(int $quoteId): bool|WP_Error
    {
        $quote = $this->repository->find($quoteId);

        if (is_wp_error($quote)) {
            return $quote;
        }

        $status = QuoteStatus::tryFrom($quote->status ?? '');

        if ($status === QuoteStatus::Exported) {
            return new WP_Error('cannot_cancel', 'Exported quotes cannot be cancelled');
        }

        $result = $this->repository->updateStatus($quoteId, QuoteStatus::Cancelled);

        if ($result) {
            $this->dispatch('quote/cancelled', ['quote_id' => $quoteId]);
        }

        return $result;
    }

    /**
     * Apply a voucher code to a draft quote.
     */
    public function applyVoucher(int $quoteId, string $voucherCode): bool|WP_Error
    {
        $quote = $this->repository->find($quoteId);

        if (is_wp_error($quote)) {
            return $quote;
        }

        // Get meta from dynamic property on WP_Post
        $meta = $quote->meta ?? [];
        $status = QuoteStatus::tryFrom($meta['status'] ?? '');

        if ($status !== QuoteStatus::Draft) {
            return new WP_Error('invalid_status', 'Alleen concept-offertes kunnen worden aangepast');
        }

        // Validate and get voucher through VoucherService
        $voucherService = ntdst_get(VoucherService::class);
        $editionId = (int) ($meta['edition_id'] ?? 0);
        $voucher = $voucherService->validateVoucher($voucherCode, $editionId);

        if (is_wp_error($voucher)) {
            return $voucher;
        }

        // Calculate discount
        $subtotalCents = (int) ($meta['subtotal'] ?? 0);
        $subtotal = Money::cents($subtotalCents);
        $discount = $voucherService->calculateDiscount($voucher, $subtotal);

        // Recalculate totals
        $newSubtotal = $subtotal;
        $newDiscount = $discount;
        $newTax = Money::cents((int) round(($newSubtotal->inCents() - $newDiscount->inCents()) * 0.21));
        $newTotal = Money::cents($newSubtotal->inCents() - $newDiscount->inCents() + $newTax->inCents());

        // Update quote
        $result = $this->repository->updateMeta($quoteId, [
            'voucher_code' => $voucherCode,
            'discount' => $newDiscount->inCents(),
            'tax' => $newTax->inCents(),
            'total' => $newTotal->inCents(),
        ]);

        if (!$result) {
            return new WP_Error('update_failed', 'Kon offerte niet bijwerken');
        }

        // Redeem the voucher
        $userId = (int) ($meta['user_id'] ?? 0);
        $redemption = $voucherService->redeemVoucher($voucherCode, $userId, $quoteId);

        if (is_wp_error($redemption)) {
            return $redemption;
        }

        $this->dispatch('quote/voucher_applied', [
            'quote_id' => $quoteId,
            'voucher_code' => $voucherCode,
            'discount' => $newDiscount->inCents(),
        ]);

        return true;
    }

    /**
     * Hydrate quote data with Money objects.
     *
     * @param array<string, mixed>|WP_Post $quote
     * @return array<string, mixed>
     */
    private function hydrateQuote(array|WP_Post $quote): array
    {
        // Handle WP_Post with dynamically added meta/fields properties
        if ($quote instanceof WP_Post) {
            $data = (array) $quote;

            // Access dynamic properties directly from the object
            // Prefer 'fields' (formatted/unprefixed) over 'meta' (raw/prefixed)
            if (isset($quote->fields) && is_array($quote->fields)) {
                $data = array_merge($data, $quote->fields);
            } elseif (isset($quote->meta) && is_array($quote->meta)) {
                $data = array_merge($data, $quote->meta);
            }
        } else {
            $data = $quote;

            // Flatten fields to top level if present (NTDST_Data_Model returns formatted fields)
            // Prefer 'fields' (formatted/unprefixed) over 'meta' for legacy batch query results
            if (isset($data['fields']) && is_array($data['fields'])) {
                $data = array_merge($data, $data['fields']);
            } elseif (isset($data['meta']) && is_array($data['meta'])) {
                $data = array_merge($data, $data['meta']);
            }
        }

        // Convert cents to Money objects
        $data['subtotal_money'] = Money::cents((int) ($data['subtotal'] ?? 0));
        $data['discount_money'] = Money::cents((int) ($data['discount'] ?? 0));
        $data['tax_money'] = Money::cents((int) ($data['tax'] ?? 0));
        $data['total_money'] = Money::cents((int) ($data['total'] ?? 0));

        // Parse status
        $data['status_enum'] = QuoteStatus::tryFrom($data['status'] ?? '') ?? QuoteStatus::Draft;

        return $data;
    }
}
