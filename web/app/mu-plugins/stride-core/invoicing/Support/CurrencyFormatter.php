<?php

namespace ntdst\Stride\invoicing\Support;

defined('ABSPATH') || exit;

/**
 * Currency Formatter
 *
 * Shared currency formatting for invoicing module.
 * Provides consistent currency display across quotes, PDFs, and admin.
 *
 * @package stride\services\invoicing\Support
 */
class CurrencyFormatter
{
    /**
     * Format amount as currency string
     *
     * @param float $amount Amount to format
     * @param string $currency Currency code (default EUR)
     * @param bool $escape Whether to escape HTML (default true)
     * @return string Formatted currency string
     */
    public static function format(float $amount, string $currency = 'EUR', bool $escape = true): string
    {
        $symbol = self::getSymbol($currency);
        $formatted = $symbol . ' ' . number_format($amount, 2, ',', '.');

        return $escape ? esc_html($formatted) : $formatted;
    }

    /**
     * Format amount as negative (for discounts)
     *
     * @param float $amount Amount to format (positive value)
     * @param string $currency Currency code
     * @param bool $escape Whether to escape HTML
     * @return string Formatted negative currency string
     */
    public static function formatNegative(float $amount, string $currency = 'EUR', bool $escape = true): string
    {
        $symbol = self::getSymbol($currency);
        $formatted = '- ' . $symbol . ' ' . number_format(abs($amount), 2, ',', '.');

        return $escape ? esc_html($formatted) : $formatted;
    }

    /**
     * Format amount for JavaScript (raw number)
     *
     * @param float $amount Amount
     * @return string Formatted for JS (2 decimal places, dot separator)
     */
    public static function formatForJs(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }

    /**
     * Parse currency string to float
     *
     * Handles both European (1.234,56) and US (1,234.56) formats.
     *
     * @param string $value Currency string to parse
     * @return float Parsed amount
     */
    public static function parse(string $value): float
    {
        // Remove currency symbols and whitespace
        $value = preg_replace('/[^\d,.\-]/', '', trim($value));

        if (empty($value)) {
            return 0.0;
        }

        // Detect format by last separator position
        $lastComma = strrpos($value, ',');
        $lastDot = strrpos($value, '.');

        if ($lastComma !== false && $lastDot !== false) {
            // Both separators present - last one is decimal
            if ($lastComma > $lastDot) {
                // European: 1.234,56
                $value = str_replace('.', '', $value);
                $value = str_replace(',', '.', $value);
            } else {
                // US: 1,234.56
                $value = str_replace(',', '', $value);
            }
        } elseif ($lastComma !== false) {
            // Only comma - check if it's decimal
            $afterComma = strlen($value) - $lastComma - 1;
            if ($afterComma === 2 || $afterComma === 1) {
                // Likely decimal: 123,45 or 123,5
                $value = str_replace(',', '.', $value);
            } else {
                // Likely thousand separator: 1,234
                $value = str_replace(',', '', $value);
            }
        }
        // If only dot, treat as decimal separator

        return (float) $value;
    }

    /**
     * Get currency symbol
     *
     * @param string $currency Currency code
     * @return string Currency symbol
     */
    public static function getSymbol(string $currency): string
    {
        return match (strtoupper($currency)) {
            'EUR' => '€',
            'USD' => '$',
            'GBP' => '£',
            'CHF' => 'CHF',
            default => $currency,
        };
    }

    /**
     * Get JavaScript formatter function
     *
     * Returns JS code for client-side formatting.
     *
     * @param string $currency Currency code
     * @return string JavaScript function code
     */
    public static function getJsFormatter(string $currency = 'EUR'): string
    {
        $symbol = self::getSymbol($currency);

        return <<<JS
        function formatCurrency(amount) {
            return '{$symbol} ' + amount.toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, '.');
        }
        JS;
    }
}
