<?php

declare(strict_types=1);

namespace Stride\Modules\Invoicing;

/**
 * Pure helper for generating voucher codes.
 *
 * Stateless - all methods are static.
 */
final class VoucherCodeGenerator
{
    private const DEFAULT_PREFIX = 'VAD';

    /**
     * Generate a unique voucher code.
     *
     * @param string $prefix Code prefix (default: VAD)
     * @param callable|null $existsCheck Callback to check if code exists: fn(string $code): bool
     * @param int $maxAttempts Maximum generation attempts
     * @return string Generated code in format PREFIX-XXXX-XXXX
     */
    public static function generate(
        string $prefix = self::DEFAULT_PREFIX,
        ?callable $existsCheck = null,
        int $maxAttempts = 10
    ): string {
        $attempt = 0;

        do {
            $code = self::buildCode($prefix);
            $exists = $existsCheck ? $existsCheck($code) : false;
            $attempt++;
        } while ($exists && $attempt < $maxAttempts);

        return $code;
    }

    /**
     * Build a single code string.
     *
     * @param string $prefix Code prefix
     * @return string Code in format PREFIX-XXXX-XXXX
     */
    private static function buildCode(string $prefix): string
    {
        return sprintf(
            '%s-%s-%s',
            strtoupper($prefix),
            strtoupper(wp_generate_password(4, false)),
            strtoupper(wp_generate_password(4, false))
        );
    }
}
