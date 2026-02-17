<?php

declare(strict_types=1);

namespace Stride\Modules\Invoicing;

use Stride\Domain\DiscountType;
use Stride\Domain\Money;
use Stride\Domain\VoucherStatus;
use Stride\Infrastructure\AbstractService;
use WP_Error;
use WP_Post;

/**
 * Voucher business logic.
 */
final class VoucherService extends AbstractService
{
    public function __construct(
        private readonly VoucherRepository $repository,
    ) {
        parent::__construct();
    }

    public static function metadata(): array
    {
        return [
            'name' => 'Voucher Service',
            'description' => 'Manages discount vouchers',
            'priority' => 25,
        ];
    }

    protected function getConfigSlug(): string
    {
        return 'vouchers';
    }

    protected function init(): void
    {
        // No hooks needed - voucher operations triggered by service calls
    }

    /**
     * Create a voucher.
     *
     * @param array{
     *   code?: string,
     *   discount_type?: string,
     *   discount_value?: int,
     *   usage_limit?: int,
     *   edition_id?: int,
     *   valid_from?: string,
     *   valid_until?: string,
     * } $data
     */
    public function createVoucher(array $data = []): int|WP_Error
    {
        // Generate code if not provided
        $code = !empty($data['code'])
            ? strtoupper(trim($data['code']))
            : VoucherCodeGenerator::generate('VAD', fn($c) => $this->repository->findByCode($c) !== null);

        // Check for duplicate
        if ($this->repository->findByCode($code)) {
            return new WP_Error('code_exists', 'Deze vouchercode bestaat al');
        }

        $result = $this->repository->create([
            'title' => $code,
            'code' => $code,
            'discount_type' => $data['discount_type'] ?? DiscountType::Full->value,
            'discount_value' => (int) ($data['discount_value'] ?? 0),
            'usage_limit' => (int) ($data['usage_limit'] ?? 1),
            'used_count' => 0,
            'edition_id' => (int) ($data['edition_id'] ?? 0),
            'valid_from' => $data['valid_from'] ?? '',
            'valid_until' => $data['valid_until'] ?? '',
            'status' => VoucherStatus::Active->value,
            'created_by' => get_current_user_id(),
            'redemptions' => [],
        ]);

        if (is_wp_error($result)) {
            return $result;
        }

        $voucherId = $result->ID;

        $this->dispatch('voucher/created', [
            'voucher_id' => $voucherId,
            'code' => $code,
        ]);

        return $voucherId;
    }

    /**
     * Get voucher by ID.
     */
    public function getVoucher(int $voucherId): ?array
    {
        $result = $this->repository->find($voucherId);

        if (is_wp_error($result) || !$result) {
            return null;
        }

        return $this->hydrateVoucher($result);
    }

    /**
     * Get voucher by code.
     */
    public function getVoucherByCode(string $code): ?array
    {
        $result = $this->repository->findByCode($code);

        return $result ? $this->hydrateVoucher($result) : null;
    }

    /**
     * Validate a voucher code.
     *
     * @return array|WP_Error Voucher data or error
     */
    public function validateVoucher(string $code, ?int $editionId = null): array|WP_Error
    {
        $voucher = $this->getVoucherByCode($code);

        if (!$voucher) {
            return new WP_Error('not_found', 'Voucher niet gevonden');
        }

        if ($voucher['status_enum'] !== VoucherStatus::Active) {
            return new WP_Error('invalid_status', 'Voucher is niet meer geldig');
        }

        // Check usage limit
        if ($voucher['usage_limit'] > 0 && $voucher['used_count'] >= $voucher['usage_limit']) {
            return new WP_Error('exhausted', 'Voucher is uitgeput');
        }

        // Check date validity
        $now = current_time('Y-m-d');

        if (!empty($voucher['valid_from']) && $now < $voucher['valid_from']) {
            return new WP_Error('not_yet_valid', 'Voucher is nog niet geldig');
        }

        if (!empty($voucher['valid_until']) && $now > $voucher['valid_until']) {
            return new WP_Error('expired', 'Voucher is verlopen');
        }

        // Check edition restriction
        if ($editionId !== null && $voucher['edition_id'] > 0 && $voucher['edition_id'] !== $editionId) {
            return new WP_Error('wrong_edition', 'Voucher is niet geldig voor deze editie');
        }

        return $voucher;
    }

    /**
     * Calculate discount amount for a voucher.
     */
    public function calculateDiscount(array $voucher, Money $subtotal): Money
    {
        $discountType = DiscountType::tryFrom($voucher['discount_type'] ?? '') ?? DiscountType::Full;

        return match ($discountType) {
            DiscountType::Full => $subtotal,
            DiscountType::Fixed => Money::cents(min($voucher['discount_value'], $subtotal->inCents())),
            DiscountType::Percentage => Money::cents(
                (int) round($subtotal->inCents() * ($voucher['discount_value'] / 100))
            ),
        };
    }

    /**
     * Redeem a voucher for a quote.
     *
     * Records the redemption and increments usage count.
     * Uses transaction locking to prevent race conditions.
     */
    public function redeemVoucher(string $code, int $userId, int $quoteId): array|WP_Error
    {
        global $wpdb;

        $voucher = $this->validateVoucher($code);

        if (is_wp_error($voucher)) {
            return $voucher;
        }

        $voucherId = $voucher['id'];

        try {
            $wpdb->query('START TRANSACTION');

            // Lock the voucher row
            $wpdb->get_row($wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE ID = %d FOR UPDATE",
                $voucherId
            ));

            // Re-fetch after lock to get current state
            $voucher = $this->getVoucher($voucherId);

            if (!$voucher || $voucher['status_enum'] !== VoucherStatus::Active) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('invalid_status', 'Voucher is niet meer geldig');
            }

            // Check if already used by this user
            $redemptions = $voucher['redemptions'] ?? [];
            foreach ($redemptions as $r) {
                if (($r['user_id'] ?? 0) === $userId) {
                    $wpdb->query('ROLLBACK');
                    return new WP_Error('already_redeemed', 'Je hebt deze voucher al gebruikt');
                }
            }

            // Record redemption
            $newUsedCount = $voucher['used_count'] + 1;
            $newStatus = $voucher['status_enum'];

            if ($voucher['usage_limit'] > 0 && $newUsedCount >= $voucher['usage_limit']) {
                $newStatus = VoucherStatus::Exhausted;
            }

            $redemption = [
                'user_id' => $userId,
                'quote_id' => $quoteId,
                'redeemed_at' => current_time('mysql'),
            ];

            $success = $this->repository->recordRedemption(
                $voucherId,
                $redemption,
                $newUsedCount,
                $newStatus
            );

            if (!$success) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('redemption_failed', 'Kon voucher niet verzilveren');
            }

            $wpdb->query('COMMIT');

            $this->dispatch('voucher/redeemed', [
                'voucher_id' => $voucherId,
                'user_id' => $userId,
                'quote_id' => $quoteId,
            ]);

            return [
                'voucher_id' => $voucherId,
                'code' => $voucher['code'],
            ];

        } catch (\Exception $e) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('transaction_failed', 'Transactie mislukt');
        }
    }

    /**
     * Hydrate voucher data.
     *
     * @param array<string, mixed>|WP_Post $voucher
     * @return array<string, mixed>
     */
    private function hydrateVoucher(array|WP_Post $voucher): array
    {
        $data = is_array($voucher) ? $voucher : (array) $voucher;

        // Flatten meta fields to top level if present
        if (isset($data['meta']) && is_array($data['meta'])) {
            $data = array_merge($data, $data['meta']);
        }

        // Parse enums
        $data['status_enum'] = VoucherStatus::tryFrom($data['status'] ?? '') ?? VoucherStatus::Active;
        $data['discount_type_enum'] = DiscountType::tryFrom($data['discount_type'] ?? '') ?? DiscountType::Full;

        // Ensure numeric fields
        $data['usage_limit'] = (int) ($data['usage_limit'] ?? 1);
        $data['used_count'] = (int) ($data['used_count'] ?? 0);
        $data['discount_value'] = (int) ($data['discount_value'] ?? 0);
        $data['edition_id'] = (int) ($data['edition_id'] ?? 0);

        // Ensure redemptions is array
        if (!isset($data['redemptions']) || !is_array($data['redemptions'])) {
            $data['redemptions'] = [];
        }

        return $data;
    }
}
