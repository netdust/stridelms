<?php

declare(strict_types=1);

namespace Stride\Modules\Invoicing;

use Stride\Domain\VoucherStatus;
use Stride\Infrastructure\AbstractRepository;

/**
 * Repository for voucher data access.
 */
final class VoucherRepository extends AbstractRepository
{
    protected string $postType = VoucherCPT::POST_TYPE;

    /**
     * Find voucher by code.
     */
    public function findByCode(string $code): ?array
    {
        $code = strtoupper(trim($code));

        $results = $this->model()
            ->where('code', $code)
            ->where('post_status', 'publish')
            ->limit(1)
            ->withMeta()
            ->get();

        return $results[0] ?? null;
    }

    /**
     * Find active vouchers.
     *
     * @return array<array<string, mixed>>
     */
    public function findActive(int $limit = 100): array
    {
        return $this->model()
            ->where('status', VoucherStatus::Active->value)
            ->where('post_status', 'publish')
            ->orderBy('post_date', 'DESC')
            ->limit($limit)
            ->withMeta()
            ->get();
    }

    /**
     * Find vouchers by edition.
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
     * Update voucher meta fields.
     */
    public function updateMeta(int $voucherId, array $data): bool
    {
        return $this->model()->updateMeta($voucherId, $data) !== false;
    }

    /**
     * Increment used count and add redemption.
     */
    public function recordRedemption(int $voucherId, array $redemption, int $newUsedCount, VoucherStatus $newStatus): bool
    {
        $currentRedemptions = $this->model()->getMeta($voucherId, 'redemptions', []);
        if (!is_array($currentRedemptions)) {
            $currentRedemptions = [];
        }

        $currentRedemptions[] = $redemption;

        return $this->model()->updateMeta($voucherId, [
            'used_count' => $newUsedCount,
            'redemptions' => $currentRedemptions,
            'status' => $newStatus->value,
        ]) !== false;
    }
}
