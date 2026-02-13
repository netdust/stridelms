<?php

namespace ntdst\Stride\invoicing\Support;

defined('ABSPATH') || exit;

/**
 * Quote Configuration
 *
 * Centralized configuration access for invoicing module.
 * Eliminates duplicate config loading across multiple classes.
 *
 * @package stride\services\invoicing\Support
 */
class QuoteConfig
{
    private static ?array $config = null;

    /**
     * Get configuration value
     *
     * @param string $key Config key (dot notation supported, e.g., 'tax_rate')
     * @param mixed $default Default value if key not found
     * @return mixed
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        self::loadConfig();

        return self::$config['modules']['invoicing'][$key] ?? $default;
    }

    /**
     * Get all invoicing configuration
     *
     * @return array
     */
    public static function all(): array
    {
        self::loadConfig();

        return self::$config['modules']['invoicing'] ?? [];
    }

    /**
     * Get tax rate
     *
     * @return float Tax rate percentage (default 21.0)
     */
    public static function getTaxRate(): float
    {
        return (float) self::get('tax_rate', 21.0);
    }

    /**
     * Get quote validity period in days
     *
     * @return int Days (default 30)
     */
    public static function getValidDays(): int
    {
        return (int) self::get('valid_days', 30);
    }

    /**
     * Get quote number prefix
     *
     * @return string Prefix (default 'OFF')
     */
    public static function getQuotePrefix(): string
    {
        return self::get('quote_prefix', 'OFF');
    }

    /**
     * Get company details for PDF/emails
     *
     * @return array Company data
     */
    public static function getCompanyDetails(): array
    {
        return self::get('company', [
            'name' => get_bloginfo('name'),
            'address' => '',
            'postal_code' => '',
            'city' => '',
            'vat_number' => '',
            'iban' => '',
            'email' => get_bloginfo('admin_email'),
            'phone' => '',
        ]);
    }

    /**
     * Get PDF template settings
     *
     * @return array PDF settings
     */
    public static function getPdfSettings(): array
    {
        return self::get('pdf', [
            'logo' => '',
            'footer_text' => '',
            'terms' => '',
        ]);
    }

    /**
     * Get email template settings
     *
     * @return array Email settings
     */
    public static function getEmailSettings(): array
    {
        return self::get('email', [
            'from_name' => get_bloginfo('name'),
            'from_email' => get_bloginfo('admin_email'),
            'subject' => __('Uw offerte', 'stride'),
        ]);
    }

    /**
     * Load configuration from theme-config.php
     */
    private static function loadConfig(): void
    {
        if (self::$config !== null) {
            return;
        }

        $configPath = get_stylesheet_directory() . '/theme-config.php';

        if (file_exists($configPath)) {
            self::$config = include $configPath;
        } else {
            self::$config = [];
        }
    }

    /**
     * Reset config cache (useful for testing)
     */
    public static function reset(): void
    {
        self::$config = null;
    }
}
