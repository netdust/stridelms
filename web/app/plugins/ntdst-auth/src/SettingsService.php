<?php

declare(strict_types=1);

namespace NTDST\Auth;

defined('ABSPATH') || exit;

/**
 * Manages plugin settings stored in wp_options.
 */
final class SettingsService implements \NTDST_Service_Meta
{
    private const OPTION_KEY = 'ntdst_auth_settings';

    public static function metadata(): array
    {
        return [
            'name' => 'Auth Settings',
            'description' => 'Authentication plugin settings management',
            'priority' => 1,
        ];
    }

    public function __construct()
    {
        $this->init();
    }

    private function init(): void
    {
        add_action('admin_menu', [$this, 'addSettingsPage']);
        add_action('admin_init', [$this, 'registerSettings']);
    }

    /**
     * Get all settings with defaults.
     *
     * @return array<string, mixed>
     */
    public function getSettings(): array
    {
        $defaults = $this->getDefaults();
        $saved = get_option(self::OPTION_KEY, []);

        return array_merge($defaults, is_array($saved) ? $saved : []);
    }

    /**
     * Get a single setting value.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $settings = $this->getSettings();
        return $settings[$key] ?? $default;
    }

    /**
     * Update a setting value.
     */
    public function set(string $key, mixed $value): bool
    {
        $settings = $this->getSettings();
        $settings[$key] = $value;
        return update_option(self::OPTION_KEY, $settings);
    }

    /**
     * Get default settings.
     *
     * @return array<string, mixed>
     */
    public function getDefaults(): array
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
     * Add settings page to admin menu.
     */
    public function addSettingsPage(): void
    {
        add_options_page(
            __('Authentication', 'ntdst-auth'),
            __('Authentication', 'ntdst-auth'),
            'manage_options',
            'ntdst-auth',
            [$this, 'renderSettingsPage']
        );
    }

    /**
     * Register settings with WordPress.
     */
    public function registerSettings(): void
    {
        register_setting('ntdst_auth', self::OPTION_KEY, [
            'type' => 'array',
            'sanitize_callback' => [$this, 'sanitizeSettings'],
        ]);
    }

    /**
     * Sanitize settings on save.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function sanitizeSettings(array $input): array
    {
        $sanitized = [];
        $defaults = $this->getDefaults();

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

        return $sanitized;
    }

    /**
     * Render settings page.
     */
    public function renderSettingsPage(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $template = NTDST_AUTH_PATH . 'admin/settings.php';
        if (file_exists($template)) {
            $settings = $this->getSettings();
            include $template;
        }
    }
}
