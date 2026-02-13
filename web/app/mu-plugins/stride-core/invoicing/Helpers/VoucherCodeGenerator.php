<?php

namespace ntdst\Stride\invoicing\Helpers;

defined('ABSPATH') || exit;

/**
 * Voucher Code Generator
 *
 * Pure stateless helper for generating unique voucher codes.
 * All methods are static - no instance state needed.
 *
 * This is a stateless helper class with no WordPress hooks.
 * All methods are pure functions operating on input data.
 *
 * @package stride\services\invoicing\Helpers
 */
class VoucherCodeGenerator
{
    private const DEFAULT_PREFIX = 'VAD';

    /**
     * Generate a unique voucher code
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
     * Generate multiple unique codes efficiently
     *
     * Pre-fetches existing codes to avoid repeated database lookups.
     *
     * @param int $count Number of codes to generate
     * @param string $prefix Code prefix (default: VAD)
     * @param array $existingCodes Array of existing codes to avoid (keys or values)
     * @return array Generated codes
     */
    public static function generateBatch(
        int $count,
        string $prefix = self::DEFAULT_PREFIX,
        array $existingCodes = []
    ): array {
        // Normalize existing codes to use as keys for O(1) lookup
        $existingMap = [];
        foreach ($existingCodes as $key => $value) {
            // Support both ['CODE1', 'CODE2'] and ['CODE1' => true, 'CODE2' => true]
            $code = is_string($value) ? $value : $key;
            $existingMap[strtoupper($code)] = true;
        }

        $codes = [];
        $maxAttempts = $count * 10;
        $attempts = 0;

        while (count($codes) < $count && $attempts < $maxAttempts) {
            $code = self::buildCode($prefix);

            // Check both existing codes and newly generated codes
            if (!isset($existingMap[$code]) && !isset($codes[$code])) {
                $codes[$code] = true;
            }

            $attempts++;
        }

        return array_keys($codes);
    }

    /**
     * Build a single code string
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
