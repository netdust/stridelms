<?php

declare(strict_types=1);

namespace NTDST\Auth\Helpers;

defined('ABSPATH') || exit;

/**
 * Plugin configuration helper.
 *
 * Reads settings from wp_options with sensible defaults.
 */
final class Config
{
    private const OPTION_KEY = 'ntdst_auth_settings';

    private static ?array $settings = null;

    /**
     * Get all settings with defaults.
     *
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        if (self::$settings === null) {
            $defaults = self::defaults();
            $saved = get_option(self::OPTION_KEY, []);
            self::$settings = array_merge($defaults, is_array($saved) ? $saved : []);
        }

        return self::$settings;
    }

    /**
     * Get a single setting value.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $settings = self::all();
        return $settings[$key] ?? $default;
    }

    /**
     * Update a setting value.
     */
    public static function set(string $key, mixed $value): bool
    {
        $settings = self::all();
        $settings[$key] = $value;
        self::$settings = $settings;
        return update_option(self::OPTION_KEY, $settings);
    }

    /**
     * Get default settings.
     *
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            // URLs
            'login_url' => '/login',
            'register_url' => '/register',
            'activate_url' => '/activate',
            'redirect_after_login' => '/',
            'redirect_after_logout' => '/login',

            // Authentication methods
            'enable_magic_link' => true,
            'enable_password' => false,
            'magic_link_expiry' => 15,
            'magic_link_max_uses' => 3,
            'activation_link_expiry' => 48,

            // Registration
            'enable_registration' => true,
            'registration_fields' => ['email', 'first_name', 'last_name'],

            // GDPR
            'terms_url' => '/terms',
            'privacy_url' => '/privacy',
            'consent_version' => '1.0',

            // Security
            'rate_limit_magic_link_per_email' => 3,
            'rate_limit_magic_link_per_ip' => 10,
            'rate_limit_login_per_ip' => 5,
            'rate_limit_registration_per_ip' => 3,
            'rate_limit_window' => 15,
            'redirect_wp_login' => true,
        ];
    }

    /**
     * Get option key for settings registration.
     */
    public static function optionKey(): string
    {
        return self::OPTION_KEY;
    }

    /**
     * Sanitize settings on save.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function sanitize(array $input): array
    {
        $sanitized = [];
        $defaults = self::defaults();

        // URLs - sanitize as paths
        foreach (['login_url', 'register_url', 'activate_url', 'redirect_after_login', 'redirect_after_logout', 'terms_url', 'privacy_url'] as $key) {
            $sanitized[$key] = '/' . ltrim(sanitize_text_field($input[$key] ?? $defaults[$key]), '/');
        }

        // Booleans
        foreach (['enable_magic_link', 'enable_password', 'enable_registration', 'redirect_wp_login'] as $key) {
            $sanitized[$key] = !empty($input[$key]);
        }

        // Integers
        foreach (['magic_link_expiry', 'magic_link_max_uses', 'activation_link_expiry', 'rate_limit_magic_link_per_email', 'rate_limit_magic_link_per_ip', 'rate_limit_login_per_ip', 'rate_limit_registration_per_ip', 'rate_limit_window'] as $key) {
            $sanitized[$key] = absint($input[$key] ?? $defaults[$key]);
        }

        // Arrays
        if (isset($input['registration_fields']) && is_array($input['registration_fields'])) {
            $allowed = ['email', 'first_name', 'last_name', 'phone', 'company'];
            $sanitized['registration_fields'] = array_values(array_intersect($input['registration_fields'], $allowed));
        } else {
            $sanitized['registration_fields'] = $defaults['registration_fields'];
        }

        // Consent version
        $sanitized['consent_version'] = sanitize_text_field($input['consent_version'] ?? $defaults['consent_version']);

        // Clear cache
        self::$settings = null;

        return $sanitized;
    }

    /**
     * Clear settings cache (useful after updates).
     */
    public static function clearCache(): void
    {
        self::$settings = null;
    }
}
