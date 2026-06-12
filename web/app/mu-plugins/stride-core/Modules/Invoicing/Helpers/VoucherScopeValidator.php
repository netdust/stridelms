<?php

declare(strict_types=1);

namespace Stride\Modules\Invoicing\Helpers;

use WP_Error;

/**
 * Validates voucher scope against a target edition.
 *
 * Plain helper — no hooks, no NTDST_Service_Meta. Resolved via DI autowiring.
 */
final class VoucherScopeValidator
{
    public function validate(array $voucher, ?int $editionId): true|WP_Error
    {
        if ($editionId === null) {
            return true;
        }

        $mode = $this->resolveMode($voucher);

        return match ($mode) {
            'only' => $this->validateOnly($voucher, $editionId),
            'except' => $this->validateExcept($voucher, $editionId),
            default => true,
        };
    }

    private function resolveMode(array $voucher): string
    {
        $mode = $voucher['scope_mode'] ?? '';

        if ($mode === '' && (int) ($voucher['edition_id'] ?? 0) > 0) {
            return 'only';
        }

        return $mode !== '' ? $mode : 'all';
    }

    private function validateOnly(array $voucher, int $editionId): true|WP_Error
    {
        $allowed = (int) ($voucher['edition_id'] ?? 0);

        if ($allowed > 0 && $allowed !== $editionId) {
            return new WP_Error('wrong_edition', 'Voucher is niet geldig voor deze editie');
        }

        return true;
    }

    private function validateExcept(array $voucher, int $editionId): true|WP_Error
    {
        $excluded = $voucher['excluded_edition_ids'] ?? [];

        if (!is_array($excluded)) {
            return true;
        }

        $excludedIds = array_map('intval', $excluded);

        if (in_array($editionId, $excludedIds, true)) {
            return new WP_Error('wrong_edition', 'Voucher is niet geldig voor deze editie');
        }

        return true;
    }
}
