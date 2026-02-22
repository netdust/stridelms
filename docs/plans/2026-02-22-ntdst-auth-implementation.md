# ntdst-auth Plugin Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Build a standalone WordPress authentication plugin with magic link login, registration with activation, and GDPR consent tracking.

**Architecture:** Standalone plugin using ntdst-core (Router, Container, Mailer). Services follow NTDST_Service_Meta pattern. Tokens stored in transients, consent in user meta, settings in wp_options. No custom database tables.

**Tech Stack:** PHP 8.1+, WordPress 6.0+, ntdst-core framework, UIkit 3, Alpine.js

**Design Doc:** `docs/plans/2026-02-22-ntdst-auth-design.md`

---

## Phase 1: Plugin Foundation

### Task 1.1: Create Plugin Bootstrap

**Files:**
- Create: `web/app/plugins/ntdst-auth/ntdst-auth.php`
- Create: `web/app/plugins/ntdst-auth/plugin-config.php`

**Step 1: Create plugin header and bootstrap**

```php
<?php
/**
 * Plugin Name: NTDST Auth
 * Description: Magic link authentication with registration and GDPR compliance
 * Version: 1.0.0
 * Requires at least: 6.0
 * Requires PHP: 8.1
 * Author: NTDST
 * Text Domain: ntdst-auth
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

// Check ntdst-core dependency
if (!function_exists('ntdst_get')) {
    add_action('admin_notices', function () {
        echo '<div class="error"><p><strong>NTDST Auth</strong> requires ntdst-core to be active.</p></div>';
    });
    return;
}

define('NTDST_AUTH_PATH', plugin_dir_path(__FILE__));
define('NTDST_AUTH_URL', plugin_dir_url(__FILE__));
define('NTDST_AUTH_VERSION', '1.0.0');

// Autoloader
spl_autoload_register(function (string $class): void {
    $prefix = 'NTDST\\Auth\\';
    $base_dir = NTDST_AUTH_PATH . 'src/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

// Load config and register services
add_action('plugins_loaded', function () {
    $config = require NTDST_AUTH_PATH . 'plugin-config.php';

    foreach ($config['services'] as $service) {
        ntdst_get($service);
    }
}, 20);

// Add email template path
add_filter('ntdst_mail_template_paths', function (array $paths): array {
    array_unshift($paths, NTDST_AUTH_PATH . 'templates/emails');
    return $paths;
});
```

**Step 2: Create plugin config**

```php
<?php
// web/app/plugins/ntdst-auth/plugin-config.php

declare(strict_types=1);

return [
    'services' => [
        \NTDST\Auth\SettingsService::class,
        \NTDST\Auth\TokenService::class,
        \NTDST\Auth\ConsentService::class,
        \NTDST\Auth\RegistrationService::class,
        \NTDST\Auth\AuthService::class,
        \NTDST\Auth\Handlers\AuthHandler::class,
    ],
];
```

**Step 3: Create directory structure**

Run:
```bash
mkdir -p web/app/plugins/ntdst-auth/{src/Handlers,templates/{pages,emails},assets/{css,js},admin}
```

**Step 4: Commit**

```bash
git add web/app/plugins/ntdst-auth/
git commit -m "feat(ntdst-auth): add plugin bootstrap and config

Co-Authored-By: Claude Opus 4.5 <noreply@anthropic.com>"
```

---

### Task 1.2: Create SettingsService

**Files:**
- Create: `web/app/plugins/ntdst-auth/src/SettingsService.php`

**Step 1: Write SettingsService**

```php
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
```

**Step 2: Commit**

```bash
git add web/app/plugins/ntdst-auth/src/SettingsService.php
git commit -m "feat(ntdst-auth): add SettingsService for plugin configuration

Co-Authored-By: Claude Opus 4.5 <noreply@anthropic.com>"
```

---

### Task 1.3: Create TokenService

**Files:**
- Create: `web/app/plugins/ntdst-auth/src/TokenService.php`

**Step 1: Write TokenService**

```php
<?php

declare(strict_types=1);

namespace NTDST\Auth;

defined('ABSPATH') || exit;

/**
 * Handles secure token generation, storage, and verification.
 *
 * Tokens are stored hashed in transients with use counting.
 * Supports magic links (3 uses, 15 min) and activation links (1 use, 48 hours).
 */
final class TokenService implements \NTDST_Service_Meta
{
    private const TRANSIENT_PREFIX_MAGIC = 'ntdst_auth_magic_';
    private const TRANSIENT_PREFIX_ACTIVATION = 'ntdst_auth_activate_';
    private const TRANSIENT_PREFIX_RATE = 'ntdst_auth_rate_';

    private SettingsService $settings;

    public static function metadata(): array
    {
        return [
            'name' => 'Token Service',
            'description' => 'Secure token generation and verification',
            'priority' => 2,
        ];
    }

    public function __construct()
    {
        $this->settings = ntdst_get(SettingsService::class);
    }

    /**
     * Generate a cryptographically secure token.
     *
     * @return string URL-safe token (64 chars)
     */
    public function generate(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Hash token for storage.
     */
    public function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Create a magic link token for user.
     *
     * @return string|null Token or null if rate limited
     */
    public function createMagicLinkToken(string $email, int $userId): ?string
    {
        // Check rate limits
        if ($this->isRateLimited('magic_email_' . $email) || $this->isRateLimited('magic_ip_' . $this->getClientIp())) {
            return null;
        }

        $token = $this->generate();
        $hash = $this->hash($token);

        $expiry = (int) $this->settings->get('magic_link_expiry', 15);
        $maxUses = (int) $this->settings->get('magic_link_max_uses', 3);

        $data = [
            'email' => $email,
            'user_id' => $userId,
            'created' => time(),
            'uses' => 0,
            'max_uses' => $maxUses,
            'type' => 'magic_link',
        ];

        set_transient(self::TRANSIENT_PREFIX_MAGIC . $hash, $data, $expiry * MINUTE_IN_SECONDS);

        // Increment rate limit counters
        $this->incrementRateLimit('magic_email_' . $email);
        $this->incrementRateLimit('magic_ip_' . $this->getClientIp());

        return $token;
    }

    /**
     * Create an activation token for new user.
     *
     * @return string Token
     */
    public function createActivationToken(string $email, int $userId): string
    {
        $token = $this->generate();
        $hash = $this->hash($token);

        $expiry = (int) $this->settings->get('activation_link_expiry', 48);

        $data = [
            'email' => $email,
            'user_id' => $userId,
            'created' => time(),
            'uses' => 0,
            'max_uses' => 1,
            'type' => 'activation',
        ];

        set_transient(self::TRANSIENT_PREFIX_ACTIVATION . $hash, $data, $expiry * HOUR_IN_SECONDS);

        return $token;
    }

    /**
     * Verify and consume a token.
     *
     * @return array{email: string, user_id: int, type: string}|\WP_Error
     */
    public function verify(string $token, string $expectedType = 'magic_link'): array|\WP_Error
    {
        $hash = $this->hash($token);
        $prefix = $expectedType === 'activation' ? self::TRANSIENT_PREFIX_ACTIVATION : self::TRANSIENT_PREFIX_MAGIC;
        $transientKey = $prefix . $hash;

        $data = get_transient($transientKey);

        if ($data === false) {
            return new \WP_Error('token_invalid', __('This link is invalid or has expired.', 'ntdst-auth'));
        }

        if (!is_array($data) || !isset($data['email'], $data['user_id'], $data['uses'], $data['max_uses'])) {
            delete_transient($transientKey);
            return new \WP_Error('token_invalid', __('This link is invalid.', 'ntdst-auth'));
        }

        // Check if exhausted
        if ($data['uses'] >= $data['max_uses']) {
            delete_transient($transientKey);
            return new \WP_Error('token_exhausted', __('This link is no longer valid.', 'ntdst-auth'));
        }

        // Increment use count
        $data['uses']++;
        if ($data['uses'] >= $data['max_uses']) {
            // Delete after max uses
            delete_transient($transientKey);
        } else {
            // Update with new use count (keep existing TTL by getting remaining time)
            $ttl = $this->getTransientTTL($transientKey);
            if ($ttl > 0) {
                set_transient($transientKey, $data, $ttl);
            }
        }

        return [
            'email' => $data['email'],
            'user_id' => (int) $data['user_id'],
            'type' => $data['type'] ?? $expectedType,
        ];
    }

    /**
     * Invalidate all tokens for a user.
     */
    public function invalidateUserTokens(int $userId): void
    {
        // Note: WordPress doesn't provide a way to iterate transients efficiently.
        // In production, consider using object cache or custom table for better cleanup.
        // For now, tokens naturally expire.
    }

    /**
     * Check if action is rate limited.
     */
    public function isRateLimited(string $key): bool
    {
        $transientKey = self::TRANSIENT_PREFIX_RATE . md5($key);
        $data = get_transient($transientKey);

        if ($data === false) {
            return false;
        }

        $limit = $this->getRateLimitForKey($key);
        return (int) $data >= $limit;
    }

    /**
     * Increment rate limit counter.
     */
    private function incrementRateLimit(string $key): void
    {
        $transientKey = self::TRANSIENT_PREFIX_RATE . md5($key);
        $window = (int) $this->settings->get('rate_limit_window', 15);
        $current = (int) get_transient($transientKey);

        set_transient($transientKey, $current + 1, $window * MINUTE_IN_SECONDS);
    }

    /**
     * Get rate limit for key type.
     */
    private function getRateLimitForKey(string $key): int
    {
        if (str_starts_with($key, 'magic_email_')) {
            return (int) $this->settings->get('rate_limit_magic_link_per_email', 3);
        }
        if (str_starts_with($key, 'magic_ip_')) {
            return (int) $this->settings->get('rate_limit_magic_link_per_ip', 10);
        }
        if (str_starts_with($key, 'login_ip_')) {
            return (int) $this->settings->get('rate_limit_login_per_ip', 5);
        }
        if (str_starts_with($key, 'register_ip_')) {
            return (int) $this->settings->get('rate_limit_registration_per_ip', 3);
        }

        return 10; // Default
    }

    /**
     * Get remaining TTL for a transient.
     */
    private function getTransientTTL(string $key): int
    {
        $timeout = get_option('_transient_timeout_' . $key);
        if ($timeout === false) {
            return 0;
        }
        return max(0, (int) $timeout - time());
    }

    /**
     * Get client IP address.
     */
    private function getClientIp(): string
    {
        $headers = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];

        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = $_SERVER[$header];
                // Handle comma-separated list (X-Forwarded-For)
                if (str_contains($ip, ',')) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return '0.0.0.0';
    }
}
```

**Step 2: Commit**

```bash
git add web/app/plugins/ntdst-auth/src/TokenService.php
git commit -m "feat(ntdst-auth): add TokenService for secure token management

Co-Authored-By: Claude Opus 4.5 <noreply@anthropic.com>"
```

---

### Task 1.4: Create ConsentService

**Files:**
- Create: `web/app/plugins/ntdst-auth/src/ConsentService.php`

**Step 1: Write ConsentService**

```php
<?php

declare(strict_types=1);

namespace NTDST\Auth;

defined('ABSPATH') || exit;

/**
 * Manages GDPR consent recording and verification.
 *
 * Stores consent in user meta with version, timestamp, and IP.
 * Integrates with WordPress privacy tools for export/erase.
 */
final class ConsentService implements \NTDST_Service_Meta
{
    private const META_CONSENT = 'ntdst_auth_consent';
    private const META_ACTIVATED = 'ntdst_auth_activated';
    private const META_ACTIVATED_AT = 'ntdst_auth_activated_at';

    private SettingsService $settings;

    public static function metadata(): array
    {
        return [
            'name' => 'Consent Service',
            'description' => 'GDPR consent tracking and privacy tools integration',
            'priority' => 3,
        ];
    }

    public function __construct()
    {
        $this->settings = ntdst_get(SettingsService::class);
        $this->init();
    }

    private function init(): void
    {
        // Register privacy exporters and erasers
        add_filter('wp_privacy_personal_data_exporters', [$this, 'registerExporter']);
        add_filter('wp_privacy_personal_data_erasers', [$this, 'registerEraser']);
    }

    /**
     * Record user consent.
     *
     * @param array{terms: bool, privacy: bool} $consent
     */
    public function recordConsent(int $userId, array $consent): bool
    {
        $data = [
            'terms' => !empty($consent['terms']),
            'privacy' => !empty($consent['privacy']),
            'version' => $this->settings->get('consent_version', '1.0'),
            'timestamp' => time(),
            'ip' => $this->getClientIp(),
        ];

        $result = update_user_meta($userId, self::META_CONSENT, $data);

        if ($result) {
            /**
             * Fires when consent is recorded.
             *
             * @param int $userId User ID
             * @param array $data Consent data
             */
            do_action('ntdst_auth_consent_recorded', $userId, $data);
        }

        return (bool) $result;
    }

    /**
     * Get user's consent data.
     *
     * @return array{terms: bool, privacy: bool, version: string, timestamp: int, ip: string}|null
     */
    public function getConsent(int $userId): ?array
    {
        $consent = get_user_meta($userId, self::META_CONSENT, true);
        return is_array($consent) ? $consent : null;
    }

    /**
     * Check if user has valid consent for current version.
     */
    public function hasValidConsent(int $userId): bool
    {
        $consent = $this->getConsent($userId);
        if (!$consent) {
            return false;
        }

        $currentVersion = $this->settings->get('consent_version', '1.0');
        return ($consent['version'] ?? '') === $currentVersion
            && !empty($consent['terms'])
            && !empty($consent['privacy']);
    }

    /**
     * Check if user consent is outdated.
     */
    public function isConsentOutdated(int $userId): bool
    {
        $consent = $this->getConsent($userId);
        if (!$consent) {
            return true;
        }

        $currentVersion = $this->settings->get('consent_version', '1.0');
        if (($consent['version'] ?? '') !== $currentVersion) {
            /**
             * Fires when user's consent version doesn't match current version.
             *
             * @param int $userId User ID
             */
            do_action('ntdst_auth_consent_outdated', $userId);
            return true;
        }

        return false;
    }

    /**
     * Mark user as activated.
     */
    public function activateUser(int $userId): bool
    {
        update_user_meta($userId, self::META_ACTIVATED, true);
        update_user_meta($userId, self::META_ACTIVATED_AT, time());

        /**
         * Fires when user account is activated.
         *
         * @param int $userId User ID
         */
        do_action('ntdst_auth_user_activated', $userId);

        return true;
    }

    /**
     * Check if user is activated.
     */
    public function isActivated(int $userId): bool
    {
        return (bool) get_user_meta($userId, self::META_ACTIVATED, true);
    }

    /**
     * Get activation timestamp.
     */
    public function getActivatedAt(int $userId): ?int
    {
        $timestamp = get_user_meta($userId, self::META_ACTIVATED_AT, true);
        return $timestamp ? (int) $timestamp : null;
    }

    /**
     * Register privacy data exporter.
     *
     * @param array<string, array> $exporters
     * @return array<string, array>
     */
    public function registerExporter(array $exporters): array
    {
        $exporters['ntdst-auth'] = [
            'exporter_friendly_name' => __('Authentication Data', 'ntdst-auth'),
            'callback' => [$this, 'exportUserData'],
        ];
        return $exporters;
    }

    /**
     * Register privacy data eraser.
     *
     * @param array<string, array> $erasers
     * @return array<string, array>
     */
    public function registerEraser(array $erasers): array
    {
        $erasers['ntdst-auth'] = [
            'eraser_friendly_name' => __('Authentication Data', 'ntdst-auth'),
            'callback' => [$this, 'eraseUserData'],
        ];
        return $erasers;
    }

    /**
     * Export user authentication data.
     *
     * @return array{data: array, done: bool}
     */
    public function exportUserData(string $email, int $page = 1): array
    {
        $user = get_user_by('email', $email);
        if (!$user) {
            return ['data' => [], 'done' => true];
        }

        $data = [];
        $consent = $this->getConsent($user->ID);

        if ($consent) {
            $data[] = [
                'group_id' => 'ntdst-auth',
                'group_label' => __('Authentication', 'ntdst-auth'),
                'item_id' => 'consent-' . $user->ID,
                'data' => [
                    ['name' => __('Terms Accepted', 'ntdst-auth'), 'value' => $consent['terms'] ? __('Yes', 'ntdst-auth') : __('No', 'ntdst-auth')],
                    ['name' => __('Privacy Accepted', 'ntdst-auth'), 'value' => $consent['privacy'] ? __('Yes', 'ntdst-auth') : __('No', 'ntdst-auth')],
                    ['name' => __('Consent Version', 'ntdst-auth'), 'value' => $consent['version']],
                    ['name' => __('Consent Date', 'ntdst-auth'), 'value' => wp_date('Y-m-d H:i:s', $consent['timestamp'])],
                    ['name' => __('IP Address', 'ntdst-auth'), 'value' => $consent['ip']],
                ],
            ];
        }

        $activatedAt = $this->getActivatedAt($user->ID);
        if ($activatedAt) {
            $data[] = [
                'group_id' => 'ntdst-auth',
                'group_label' => __('Authentication', 'ntdst-auth'),
                'item_id' => 'activation-' . $user->ID,
                'data' => [
                    ['name' => __('Account Activated', 'ntdst-auth'), 'value' => __('Yes', 'ntdst-auth')],
                    ['name' => __('Activation Date', 'ntdst-auth'), 'value' => wp_date('Y-m-d H:i:s', $activatedAt)],
                ],
            ];
        }

        return ['data' => $data, 'done' => true];
    }

    /**
     * Erase user authentication data.
     *
     * @return array{items_removed: int, items_retained: int, messages: array, done: bool}
     */
    public function eraseUserData(string $email, int $page = 1): array
    {
        $user = get_user_by('email', $email);
        if (!$user) {
            return [
                'items_removed' => 0,
                'items_retained' => 0,
                'messages' => [],
                'done' => true,
            ];
        }

        $removed = 0;

        if (delete_user_meta($user->ID, self::META_CONSENT)) {
            $removed++;
        }
        if (delete_user_meta($user->ID, self::META_ACTIVATED)) {
            $removed++;
        }
        if (delete_user_meta($user->ID, self::META_ACTIVATED_AT)) {
            $removed++;
        }

        return [
            'items_removed' => $removed,
            'items_retained' => 0,
            'messages' => [],
            'done' => true,
        ];
    }

    /**
     * Get client IP address.
     */
    private function getClientIp(): string
    {
        $headers = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];

        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = $_SERVER[$header];
                if (str_contains($ip, ',')) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return '0.0.0.0';
    }
}
```

**Step 2: Commit**

```bash
git add web/app/plugins/ntdst-auth/src/ConsentService.php
git commit -m "feat(ntdst-auth): add ConsentService for GDPR compliance

Co-Authored-By: Claude Opus 4.5 <noreply@anthropic.com>"
```

---

## Phase 2: Registration & Authentication Services

### Task 2.1: Create RegistrationService

**Files:**
- Create: `web/app/plugins/ntdst-auth/src/RegistrationService.php`

**Step 1: Write RegistrationService**

```php
<?php

declare(strict_types=1);

namespace NTDST\Auth;

defined('ABSPATH') || exit;

/**
 * Handles user registration with activation flow.
 *
 * Creates users in pending state, sends activation emails,
 * and activates accounts when users click the link.
 */
final class RegistrationService implements \NTDST_Service_Meta
{
    private SettingsService $settings;
    private TokenService $tokens;
    private ConsentService $consent;

    public static function metadata(): array
    {
        return [
            'name' => 'Registration Service',
            'description' => 'User registration and activation',
            'priority' => 4,
        ];
    }

    public function __construct()
    {
        $this->settings = ntdst_get(SettingsService::class);
        $this->tokens = ntdst_get(TokenService::class);
        $this->consent = ntdst_get(ConsentService::class);
    }

    /**
     * Register a new user.
     *
     * Always returns success message to prevent email enumeration.
     * Internally handles existing users vs new registrations.
     *
     * @param array{email: string, first_name?: string, last_name?: string, consent_terms?: bool, consent_privacy?: bool} $data
     * @return array{success: bool, message: string}|\WP_Error
     */
    public function register(array $data): array|\WP_Error
    {
        // Check if registration is enabled
        if (!$this->settings->get('enable_registration', true)) {
            return new \WP_Error('registration_disabled', __('Registration is currently disabled.', 'ntdst-auth'));
        }

        // Check rate limit
        if ($this->tokens->isRateLimited('register_ip_' . $this->getClientIp())) {
            return new \WP_Error('rate_limited', __('Too many registration attempts. Please try again later.', 'ntdst-auth'));
        }

        // Validate email
        $email = sanitize_email($data['email'] ?? '');
        if (!is_email($email)) {
            return new \WP_Error('invalid_email', __('Please enter a valid email address.', 'ntdst-auth'));
        }

        // Validate consent
        if (empty($data['consent_terms']) || empty($data['consent_privacy'])) {
            return new \WP_Error('consent_required', __('You must accept the terms and privacy policy.', 'ntdst-auth'));
        }

        // Check if user exists
        $existingUser = get_user_by('email', $email);

        if ($existingUser) {
            // Send "already registered" email (no enumeration)
            $this->sendAlreadyRegisteredEmail($email);
        } else {
            // Create new user
            $result = $this->createUser($data);
            if (is_wp_error($result)) {
                // Log error but return generic message
                ntdst_log('auth')->error('Registration failed', [
                    'email' => $email,
                    'error' => $result->get_error_message(),
                ]);
            }
        }

        // Always return same message to prevent enumeration
        return [
            'success' => true,
            'message' => __('Check your inbox for instructions to complete your registration.', 'ntdst-auth'),
        ];
    }

    /**
     * Create a new user account.
     *
     * @param array{email: string, first_name?: string, last_name?: string, consent_terms?: bool, consent_privacy?: bool} $data
     * @return int|\WP_Error User ID or error
     */
    private function createUser(array $data): int|\WP_Error
    {
        $email = sanitize_email($data['email']);
        $firstName = sanitize_text_field($data['first_name'] ?? '');
        $lastName = sanitize_text_field($data['last_name'] ?? '');

        // Generate username from email
        $username = $this->generateUsername($email);

        // Generate random password (user won't use it with magic links)
        $password = wp_generate_password(24, true, true);

        // Create user
        $userId = wp_insert_user([
            'user_login' => $username,
            'user_email' => $email,
            'user_pass' => $password,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'display_name' => trim($firstName . ' ' . $lastName) ?: $username,
            'role' => 'subscriber',
        ]);

        if (is_wp_error($userId)) {
            return $userId;
        }

        // Record consent
        $this->consent->recordConsent($userId, [
            'terms' => !empty($data['consent_terms']),
            'privacy' => !empty($data['consent_privacy']),
        ]);

        // Send activation email
        $this->sendActivationEmail($email, $userId);

        /**
         * Fires after user registration is complete.
         *
         * @param int $userId User ID
         * @param array $data Registration data
         */
        do_action('ntdst_auth_registration_complete', $userId, $data);

        return $userId;
    }

    /**
     * Activate a user account.
     *
     * @return array{success: bool, user_id: int}|\WP_Error
     */
    public function activate(string $token): array|\WP_Error
    {
        $result = $this->tokens->verify($token, 'activation');

        if (is_wp_error($result)) {
            return $result;
        }

        $userId = $result['user_id'];

        // Check if already activated
        if ($this->consent->isActivated($userId)) {
            return new \WP_Error('already_activated', __('Your account is already activated. Please log in.', 'ntdst-auth'));
        }

        // Activate user
        $this->consent->activateUser($userId);

        // Send welcome email
        $user = get_user_by('ID', $userId);
        if ($user) {
            $this->sendWelcomeEmail($user->user_email);
        }

        return [
            'success' => true,
            'user_id' => $userId,
        ];
    }

    /**
     * Generate unique username from email.
     */
    private function generateUsername(string $email): string
    {
        $base = sanitize_user(explode('@', $email)[0], true);
        $username = $base;
        $suffix = 1;

        while (username_exists($username)) {
            $username = $base . $suffix;
            $suffix++;
        }

        return $username;
    }

    /**
     * Send activation email.
     */
    private function sendActivationEmail(string $email, int $userId): void
    {
        $token = $this->tokens->createActivationToken($email, $userId);
        $activateUrl = home_url('/auth/activate/' . $token);
        $expiry = (int) $this->settings->get('activation_link_expiry', 48);

        ntdst_mail()
            ->to($email)
            ->subject(__('Activate your account', 'ntdst-auth'))
            ->template('activation', [
                'activate_url' => $activateUrl,
                'expiry_hours' => $expiry,
                'site_name' => get_bloginfo('name'),
            ])
            ->send();
    }

    /**
     * Send "already registered" email.
     */
    private function sendAlreadyRegisteredEmail(string $email): void
    {
        $loginUrl = home_url($this->settings->get('login_url', '/login'));

        ntdst_mail()
            ->to($email)
            ->subject(__('Account already exists', 'ntdst-auth'))
            ->template('already-registered', [
                'login_url' => $loginUrl,
                'site_name' => get_bloginfo('name'),
            ])
            ->send();
    }

    /**
     * Send welcome email.
     */
    private function sendWelcomeEmail(string $email): void
    {
        $loginUrl = home_url($this->settings->get('login_url', '/login'));

        ntdst_mail()
            ->to($email)
            ->subject(__('Welcome!', 'ntdst-auth'))
            ->template('welcome', [
                'login_url' => $loginUrl,
                'site_name' => get_bloginfo('name'),
            ])
            ->send();
    }

    /**
     * Get client IP address.
     */
    private function getClientIp(): string
    {
        $headers = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];

        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = $_SERVER[$header];
                if (str_contains($ip, ',')) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return '0.0.0.0';
    }
}
```

**Step 2: Commit**

```bash
git add web/app/plugins/ntdst-auth/src/RegistrationService.php
git commit -m "feat(ntdst-auth): add RegistrationService with activation flow

Co-Authored-By: Claude Opus 4.5 <noreply@anthropic.com>"
```

---

### Task 2.2: Create AuthService

**Files:**
- Create: `web/app/plugins/ntdst-auth/src/AuthService.php`

**Step 1: Write AuthService**

```php
<?php

declare(strict_types=1);

namespace NTDST\Auth;

defined('ABSPATH') || exit;

/**
 * Handles authentication via magic link and optional password.
 *
 * Registers URL routes, processes login/logout, and manages sessions.
 */
final class AuthService implements \NTDST_Service_Meta
{
    private SettingsService $settings;
    private TokenService $tokens;
    private ConsentService $consent;

    public static function metadata(): array
    {
        return [
            'name' => 'Auth Service',
            'description' => 'Magic link and password authentication',
            'priority' => 5,
        ];
    }

    public function __construct()
    {
        $this->settings = ntdst_get(SettingsService::class);
        $this->tokens = ntdst_get(TokenService::class);
        $this->consent = ntdst_get(ConsentService::class);
        $this->init();
    }

    private function init(): void
    {
        add_action('init', [$this, 'registerRoutes']);

        // Redirect wp-login.php if enabled
        if ($this->settings->get('redirect_wp_login', true)) {
            add_action('login_init', [$this, 'redirectWpLogin']);
        }
    }

    /**
     * Register URL routes.
     */
    public function registerRoutes(): void
    {
        $loginUrl = ltrim($this->settings->get('login_url', '/login'), '/');
        $registerUrl = ltrim($this->settings->get('register_url', '/register'), '/');

        // Login page
        ntdst_router()->get($loginUrl, function () {
            if (is_user_logged_in()) {
                wp_redirect($this->getRedirectAfterLogin());
                exit;
            }
            return $this->renderPage('login');
        });

        // Register page
        ntdst_router()->get($registerUrl, function () {
            if (is_user_logged_in()) {
                wp_redirect($this->getRedirectAfterLogin());
                exit;
            }
            if (!$this->settings->get('enable_registration', true)) {
                wp_redirect(home_url($this->settings->get('login_url', '/login')));
                exit;
            }
            return $this->renderPage('register');
        });

        // Magic link verification
        ntdst_router()->get('auth/verify/:token', function (array $params) {
            return $this->handleMagicLinkVerify($params['token']);
        });

        // Activation link
        ntdst_router()->get('auth/activate/:token', function (array $params) {
            return $this->handleActivation($params['token']);
        });

        // Logout
        ntdst_router()->get('auth/logout', function () {
            return $this->handleLogout();
        });
    }

    /**
     * Request magic link for email.
     *
     * @return array{success: bool, message: string}
     */
    public function requestMagicLink(string $email): array
    {
        $email = sanitize_email($email);

        // Always return same message to prevent enumeration
        $successMessage = __('If an account exists with this email, you will receive a login link shortly.', 'ntdst-auth');

        if (!is_email($email)) {
            // Still return success message (no enumeration)
            return ['success' => true, 'message' => $successMessage];
        }

        // Check rate limit
        if ($this->tokens->isRateLimited('magic_email_' . $email) || $this->tokens->isRateLimited('magic_ip_' . $this->getClientIp())) {
            return [
                'success' => false,
                'message' => __('Please wait before requesting another login link.', 'ntdst-auth'),
            ];
        }

        $user = get_user_by('email', $email);

        // Only send if user exists and is activated
        if ($user && $this->consent->isActivated($user->ID)) {
            $token = $this->tokens->createMagicLinkToken($email, $user->ID);

            if ($token) {
                $this->sendMagicLinkEmail($email, $token);
            }
        }

        return ['success' => true, 'message' => $successMessage];
    }

    /**
     * Login with password.
     *
     * @return array{success: bool, message?: string, redirect?: string}|\WP_Error
     */
    public function loginWithPassword(string $email, string $password): array|\WP_Error
    {
        if (!$this->settings->get('enable_password', false)) {
            return new \WP_Error('password_disabled', __('Password login is not enabled.', 'ntdst-auth'));
        }

        // Check rate limit
        if ($this->tokens->isRateLimited('login_ip_' . $this->getClientIp())) {
            return new \WP_Error('rate_limited', __('Too many login attempts. Please try again later.', 'ntdst-auth'));
        }

        $email = sanitize_email($email);
        $user = get_user_by('email', $email);

        // Generic error for security
        $genericError = new \WP_Error('invalid_credentials', __('Invalid email or password.', 'ntdst-auth'));

        if (!$user) {
            return $genericError;
        }

        // Check if activated
        if (!$this->consent->isActivated($user->ID)) {
            return new \WP_Error('not_activated', __('Please activate your account first. Check your email for the activation link.', 'ntdst-auth'));
        }

        // Verify password
        if (!wp_check_password($password, $user->user_pass, $user->ID)) {
            return $genericError;
        }

        // Log user in
        $this->setAuthCookie($user->ID);

        /**
         * Fires on successful login.
         *
         * @param int $userId User ID
         */
        do_action('ntdst_auth_login_success', $user->ID);

        return [
            'success' => true,
            'redirect' => $this->getRedirectAfterLogin(),
        ];
    }

    /**
     * Handle magic link verification.
     */
    private function handleMagicLinkVerify(string $token): void
    {
        $result = $this->tokens->verify($token, 'magic_link');

        if (is_wp_error($result)) {
            $this->renderPage('error', [
                'title' => __('Link Invalid', 'ntdst-auth'),
                'message' => $result->get_error_message(),
                'show_request_new' => true,
            ]);
            exit;
        }

        // Check if user is activated
        if (!$this->consent->isActivated($result['user_id'])) {
            $this->renderPage('error', [
                'title' => __('Account Not Activated', 'ntdst-auth'),
                'message' => __('Please activate your account first.', 'ntdst-auth'),
            ]);
            exit;
        }

        // Log user in
        $this->setAuthCookie($result['user_id']);

        /**
         * Fires on successful login.
         *
         * @param int $userId User ID
         */
        do_action('ntdst_auth_login_success', $result['user_id']);

        // Redirect
        wp_redirect($this->getRedirectAfterLogin());
        exit;
    }

    /**
     * Handle activation link.
     */
    private function handleActivation(string $token): void
    {
        $registration = ntdst_get(RegistrationService::class);
        $result = $registration->activate($token);

        if (is_wp_error($result)) {
            $this->renderPage('error', [
                'title' => __('Activation Failed', 'ntdst-auth'),
                'message' => $result->get_error_message(),
            ]);
            exit;
        }

        // Log user in
        $this->setAuthCookie($result['user_id']);

        // Show success page
        $this->renderPage('activate', [
            'title' => __('Account Activated', 'ntdst-auth'),
            'message' => __('Your account has been activated successfully!', 'ntdst-auth'),
            'redirect' => $this->getRedirectAfterLogin(),
        ]);
        exit;
    }

    /**
     * Handle logout.
     */
    private function handleLogout(): void
    {
        if (is_user_logged_in()) {
            wp_logout();
        }

        $redirectUrl = home_url($this->settings->get('redirect_after_logout', '/login'));
        wp_safe_redirect($redirectUrl);
        exit;
    }

    /**
     * Redirect wp-login.php to custom login.
     */
    public function redirectWpLogin(): void
    {
        // Allow password reset flow
        $action = $_GET['action'] ?? '';
        if (in_array($action, ['lostpassword', 'rp', 'resetpass'], true)) {
            return;
        }

        // Allow logout
        if ($action === 'logout') {
            return;
        }

        $loginUrl = home_url($this->settings->get('login_url', '/login'));
        wp_safe_redirect($loginUrl);
        exit;
    }

    /**
     * Set authentication cookie for user.
     */
    private function setAuthCookie(int $userId): void
    {
        wp_clear_auth_cookie();
        wp_set_current_user($userId);
        wp_set_auth_cookie($userId, true);
    }

    /**
     * Get redirect URL after login.
     */
    private function getRedirectAfterLogin(): string
    {
        // Check for redirect_to parameter
        $redirectTo = $_GET['redirect_to'] ?? '';
        if ($redirectTo) {
            $validated = wp_validate_redirect($redirectTo, home_url('/'));
            if ($validated !== home_url('/') || $redirectTo === home_url('/')) {
                return $validated;
            }
        }

        return home_url($this->settings->get('redirect_after_login', '/'));
    }

    /**
     * Send magic link email.
     */
    private function sendMagicLinkEmail(string $email, string $token): void
    {
        $verifyUrl = home_url('/auth/verify/' . $token);
        $expiry = (int) $this->settings->get('magic_link_expiry', 15);

        ntdst_mail()
            ->to($email)
            ->subject(__('Your login link', 'ntdst-auth'))
            ->template('magic-link', [
                'login_url' => $verifyUrl,
                'expiry_minutes' => $expiry,
                'site_name' => get_bloginfo('name'),
            ])
            ->send();
    }

    /**
     * Render a page template.
     *
     * @param array<string, mixed> $data
     */
    private function renderPage(string $template, array $data = []): void
    {
        // Check for theme override
        $paths = [
            get_stylesheet_directory() . '/ntdst-auth/pages/' . $template . '.php',
            get_template_directory() . '/ntdst-auth/pages/' . $template . '.php',
            NTDST_AUTH_PATH . 'templates/pages/' . $template . '.php',
        ];

        foreach ($paths as $path) {
            if (file_exists($path)) {
                // Extract data to variables
                extract($data);
                $settings = $this->settings->getSettings();

                include $path;
                exit;
            }
        }

        // Fallback
        wp_die(__('Template not found.', 'ntdst-auth'));
    }

    /**
     * Get client IP address.
     */
    private function getClientIp(): string
    {
        $headers = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];

        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = $_SERVER[$header];
                if (str_contains($ip, ',')) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return '0.0.0.0';
    }
}
```

**Step 2: Commit**

```bash
git add web/app/plugins/ntdst-auth/src/AuthService.php
git commit -m "feat(ntdst-auth): add AuthService with magic link and routing

Co-Authored-By: Claude Opus 4.5 <noreply@anthropic.com>"
```

---

### Task 2.3: Create AuthHandler

**Files:**
- Create: `web/app/plugins/ntdst-auth/src/Handlers/AuthHandler.php`

**Step 1: Write AuthHandler**

```php
<?php

declare(strict_types=1);

namespace NTDST\Auth\Handlers;

use NTDST\Auth\AuthService;
use NTDST\Auth\RegistrationService;
use NTDST\Auth\SettingsService;

defined('ABSPATH') || exit;

/**
 * AJAX handler for authentication actions.
 *
 * Thin handler - validates input, delegates to services, returns JSON.
 */
final class AuthHandler implements \NTDST_Service_Meta
{
    public static function metadata(): array
    {
        return [
            'name' => 'Auth Handler',
            'description' => 'AJAX endpoints for authentication',
            'priority' => 6,
        ];
    }

    public function __construct()
    {
        $this->init();
    }

    private function init(): void
    {
        // Public actions (no login required)
        add_action('wp_ajax_nopriv_ntdst_auth_request_magic_link', [$this, 'ajaxRequestMagicLink']);
        add_action('wp_ajax_nopriv_ntdst_auth_login_password', [$this, 'ajaxLoginPassword']);
        add_action('wp_ajax_nopriv_ntdst_auth_register', [$this, 'ajaxRegister']);

        // Logged-in actions
        add_action('wp_ajax_ntdst_auth_request_magic_link', [$this, 'ajaxRequestMagicLink']);
    }

    /**
     * AJAX: Request magic link.
     */
    public function ajaxRequestMagicLink(): void
    {
        if (!$this->verifyNonce('ntdst_auth_login')) {
            wp_send_json_error(['message' => __('Invalid security token.', 'ntdst-auth')]);
        }

        $email = sanitize_email($_POST['email'] ?? '');

        if (empty($email)) {
            wp_send_json_error(['message' => __('Please enter your email address.', 'ntdst-auth')]);
        }

        $authService = ntdst_get(AuthService::class);
        $result = $authService->requestMagicLink($email);

        if ($result['success']) {
            wp_send_json_success(['message' => $result['message']]);
        } else {
            wp_send_json_error(['message' => $result['message']]);
        }
    }

    /**
     * AJAX: Login with password.
     */
    public function ajaxLoginPassword(): void
    {
        if (!$this->verifyNonce('ntdst_auth_login')) {
            wp_send_json_error(['message' => __('Invalid security token.', 'ntdst-auth')]);
        }

        $email = sanitize_email($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($email) || empty($password)) {
            wp_send_json_error(['message' => __('Please enter both email and password.', 'ntdst-auth')]);
        }

        $authService = ntdst_get(AuthService::class);
        $result = $authService->loginWithPassword($email, $password);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success([
            'message' => __('Login successful!', 'ntdst-auth'),
            'redirect' => $result['redirect'],
        ]);
    }

    /**
     * AJAX: Register new user.
     */
    public function ajaxRegister(): void
    {
        if (!$this->verifyNonce('ntdst_auth_register')) {
            wp_send_json_error(['message' => __('Invalid security token.', 'ntdst-auth')]);
        }

        $data = [
            'email' => sanitize_email($_POST['email'] ?? ''),
            'first_name' => sanitize_text_field($_POST['first_name'] ?? ''),
            'last_name' => sanitize_text_field($_POST['last_name'] ?? ''),
            'consent_terms' => !empty($_POST['consent_terms']),
            'consent_privacy' => !empty($_POST['consent_privacy']),
        ];

        if (empty($data['email'])) {
            wp_send_json_error(['message' => __('Please enter your email address.', 'ntdst-auth')]);
        }

        $registration = ntdst_get(RegistrationService::class);
        $result = $registration->register($data);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success(['message' => $result['message']]);
    }

    /**
     * Verify nonce from request.
     */
    private function verifyNonce(string $action): bool
    {
        $nonce = $_POST['nonce'] ?? $_POST['_wpnonce'] ?? '';
        return wp_verify_nonce($nonce, $action) !== false;
    }
}
```

**Step 2: Commit**

```bash
git add web/app/plugins/ntdst-auth/src/Handlers/AuthHandler.php
git commit -m "feat(ntdst-auth): add AuthHandler for AJAX endpoints

Co-Authored-By: Claude Opus 4.5 <noreply@anthropic.com>"
```

---

## Phase 3: Templates & Assets

### Task 3.1: Create Email Templates

**Files:**
- Create: `web/app/plugins/ntdst-auth/templates/emails/magic-link.php`
- Create: `web/app/plugins/ntdst-auth/templates/emails/activation.php`
- Create: `web/app/plugins/ntdst-auth/templates/emails/already-registered.php`
- Create: `web/app/plugins/ntdst-auth/templates/emails/welcome.php`

**Step 1: Create magic-link.php**

```php
<?php
/**
 * Magic Link Email Template
 *
 * Variables: $login_url, $expiry_minutes, $site_name
 */
defined('ABSPATH') || exit;
?>
<h1><?php esc_html_e('Sign in to', 'ntdst-auth'); ?> <?php echo esc_html($site_name); ?></h1>

<p><?php esc_html_e('Click the button below to sign in to your account. This link will expire in', 'ntdst-auth'); ?> <?php echo esc_html($expiry_minutes); ?> <?php esc_html_e('minutes.', 'ntdst-auth'); ?></p>

<p style="text-align: center; margin: 30px 0;">
    <a href="<?php echo esc_url($login_url); ?>" style="display: inline-block; padding: 14px 28px; background-color: #1e87f0; color: #ffffff; text-decoration: none; border-radius: 4px; font-weight: bold;">
        <?php esc_html_e('Sign In', 'ntdst-auth'); ?>
    </a>
</p>

<p style="color: #666666; font-size: 14px;">
    <?php esc_html_e("If you didn't request this link, you can safely ignore this email.", 'ntdst-auth'); ?>
</p>

<p style="color: #999999; font-size: 12px; margin-top: 30px;">
    <?php esc_html_e("If the button doesn't work, copy and paste this link into your browser:", 'ntdst-auth'); ?><br>
    <a href="<?php echo esc_url($login_url); ?>" style="color: #1e87f0; word-break: break-all;"><?php echo esc_url($login_url); ?></a>
</p>
```

**Step 2: Create activation.php**

```php
<?php
/**
 * Activation Email Template
 *
 * Variables: $activate_url, $expiry_hours, $site_name
 */
defined('ABSPATH') || exit;
?>
<h1><?php esc_html_e('Activate your account', 'ntdst-auth'); ?></h1>

<p><?php esc_html_e('Thank you for registering at', 'ntdst-auth'); ?> <?php echo esc_html($site_name); ?>. <?php esc_html_e('Click the button below to activate your account.', 'ntdst-auth'); ?></p>

<p><?php esc_html_e('This link will expire in', 'ntdst-auth'); ?> <?php echo esc_html($expiry_hours); ?> <?php esc_html_e('hours.', 'ntdst-auth'); ?></p>

<p style="text-align: center; margin: 30px 0;">
    <a href="<?php echo esc_url($activate_url); ?>" style="display: inline-block; padding: 14px 28px; background-color: #32d296; color: #ffffff; text-decoration: none; border-radius: 4px; font-weight: bold;">
        <?php esc_html_e('Activate Account', 'ntdst-auth'); ?>
    </a>
</p>

<p style="color: #666666; font-size: 14px;">
    <?php esc_html_e("If you didn't create an account, you can safely ignore this email.", 'ntdst-auth'); ?>
</p>

<p style="color: #999999; font-size: 12px; margin-top: 30px;">
    <?php esc_html_e("If the button doesn't work, copy and paste this link into your browser:", 'ntdst-auth'); ?><br>
    <a href="<?php echo esc_url($activate_url); ?>" style="color: #32d296; word-break: break-all;"><?php echo esc_url($activate_url); ?></a>
</p>
```

**Step 3: Create already-registered.php**

```php
<?php
/**
 * Already Registered Email Template
 *
 * Variables: $login_url, $site_name
 */
defined('ABSPATH') || exit;
?>
<h1><?php esc_html_e('Account already exists', 'ntdst-auth'); ?></h1>

<p><?php esc_html_e('Someone tried to create an account at', 'ntdst-auth'); ?> <?php echo esc_html($site_name); ?> <?php esc_html_e('using this email address, but you already have an account.', 'ntdst-auth'); ?></p>

<p><?php esc_html_e('You can sign in using the link below:', 'ntdst-auth'); ?></p>

<p style="text-align: center; margin: 30px 0;">
    <a href="<?php echo esc_url($login_url); ?>" style="display: inline-block; padding: 14px 28px; background-color: #1e87f0; color: #ffffff; text-decoration: none; border-radius: 4px; font-weight: bold;">
        <?php esc_html_e('Sign In', 'ntdst-auth'); ?>
    </a>
</p>

<p style="color: #666666; font-size: 14px;">
    <?php esc_html_e("If you didn't try to register, someone else may have entered your email by mistake. You can safely ignore this email.", 'ntdst-auth'); ?>
</p>
```

**Step 4: Create welcome.php**

```php
<?php
/**
 * Welcome Email Template
 *
 * Variables: $login_url, $site_name
 */
defined('ABSPATH') || exit;
?>
<h1><?php esc_html_e('Welcome!', 'ntdst-auth'); ?></h1>

<p><?php esc_html_e('Your account at', 'ntdst-auth'); ?> <?php echo esc_html($site_name); ?> <?php esc_html_e('has been activated successfully.', 'ntdst-auth'); ?></p>

<p><?php esc_html_e("You're all set! You can now sign in to access your account.", 'ntdst-auth'); ?></p>

<p style="text-align: center; margin: 30px 0;">
    <a href="<?php echo esc_url($login_url); ?>" style="display: inline-block; padding: 14px 28px; background-color: #1e87f0; color: #ffffff; text-decoration: none; border-radius: 4px; font-weight: bold;">
        <?php esc_html_e('Sign In', 'ntdst-auth'); ?>
    </a>
</p>

<p style="color: #666666; font-size: 14px;">
    <?php esc_html_e('Thank you for joining us!', 'ntdst-auth'); ?>
</p>
```

**Step 5: Commit**

```bash
git add web/app/plugins/ntdst-auth/templates/emails/
git commit -m "feat(ntdst-auth): add email templates

Co-Authored-By: Claude Opus 4.5 <noreply@anthropic.com>"
```

---

### Task 3.2: Create Page Templates

**Files:**
- Create: `web/app/plugins/ntdst-auth/templates/pages/login.php`
- Create: `web/app/plugins/ntdst-auth/templates/pages/register.php`
- Create: `web/app/plugins/ntdst-auth/templates/pages/activate.php`
- Create: `web/app/plugins/ntdst-auth/templates/pages/error.php`

**Step 1: Create login.php**

```php
<?php
/**
 * Login Page Template
 *
 * Variables: $settings (array of all settings)
 */
defined('ABSPATH') || exit;

$enableMagicLink = $settings['enable_magic_link'] ?? true;
$enablePassword = $settings['enable_password'] ?? false;
$registerUrl = home_url($settings['register_url'] ?? '/register');
$enableRegistration = $settings['enable_registration'] ?? true;
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php esc_html_e('Sign In', 'ntdst-auth'); ?> | <?php bloginfo('name'); ?></title>
    <?php wp_head(); ?>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/uikit@3.21.5/dist/css/uikit.min.css">
    <link rel="stylesheet" href="<?php echo esc_url(NTDST_AUTH_URL . 'assets/css/auth.css'); ?>">
</head>
<body class="ntdst-auth-page">
    <div class="uk-flex uk-flex-center uk-flex-middle uk-height-viewport uk-padding" x-data="authLogin()">
        <div class="uk-card uk-card-default uk-card-body uk-width-medium">
            <!-- Logo slot -->
            <div class="uk-text-center uk-margin-medium-bottom">
                <h2 class="uk-card-title"><?php bloginfo('name'); ?></h2>
            </div>

            <!-- Success message -->
            <template x-if="success">
                <div class="uk-alert uk-alert-success" x-text="message"></div>
            </template>

            <!-- Error message -->
            <template x-if="error">
                <div class="uk-alert uk-alert-danger" x-text="message"></div>
            </template>

            <!-- Magic Link Form -->
            <?php if ($enableMagicLink && !$enablePassword): ?>
            <form @submit.prevent="requestMagicLink" x-show="!success">
                <div class="uk-margin">
                    <label class="uk-form-label" for="email"><?php esc_html_e('Email', 'ntdst-auth'); ?></label>
                    <input class="uk-input" type="email" id="email" x-model="email" required autofocus>
                </div>

                <div class="uk-margin">
                    <button class="uk-button uk-button-primary uk-width-1-1" type="submit" :disabled="loading">
                        <span x-show="!loading"><?php esc_html_e('Send Login Link', 'ntdst-auth'); ?></span>
                        <span x-show="loading" uk-spinner="ratio: 0.6"></span>
                    </button>
                </div>
            </form>
            <?php endif; ?>

            <!-- Password Login Form (with optional magic link toggle) -->
            <?php if ($enablePassword): ?>
            <form @submit.prevent="loginPassword" x-show="!success && mode === 'password'">
                <div class="uk-margin">
                    <label class="uk-form-label" for="email"><?php esc_html_e('Email', 'ntdst-auth'); ?></label>
                    <input class="uk-input" type="email" id="email" x-model="email" required autofocus>
                </div>

                <div class="uk-margin">
                    <label class="uk-form-label" for="password"><?php esc_html_e('Password', 'ntdst-auth'); ?></label>
                    <input class="uk-input" type="password" id="password" x-model="password" required>
                </div>

                <div class="uk-margin">
                    <button class="uk-button uk-button-primary uk-width-1-1" type="submit" :disabled="loading">
                        <span x-show="!loading"><?php esc_html_e('Sign In', 'ntdst-auth'); ?></span>
                        <span x-show="loading" uk-spinner="ratio: 0.6"></span>
                    </button>
                </div>

                <?php if ($enableMagicLink): ?>
                <div class="uk-text-center uk-margin-small-top">
                    <a href="#" @click.prevent="mode = 'magic'" class="uk-link-muted uk-text-small">
                        <?php esc_html_e('Sign in with email link instead', 'ntdst-auth'); ?>
                    </a>
                </div>
                <?php endif; ?>
            </form>

            <?php if ($enableMagicLink): ?>
            <form @submit.prevent="requestMagicLink" x-show="!success && mode === 'magic'">
                <div class="uk-margin">
                    <label class="uk-form-label" for="email-magic"><?php esc_html_e('Email', 'ntdst-auth'); ?></label>
                    <input class="uk-input" type="email" id="email-magic" x-model="email" required>
                </div>

                <div class="uk-margin">
                    <button class="uk-button uk-button-primary uk-width-1-1" type="submit" :disabled="loading">
                        <span x-show="!loading"><?php esc_html_e('Send Login Link', 'ntdst-auth'); ?></span>
                        <span x-show="loading" uk-spinner="ratio: 0.6"></span>
                    </button>
                </div>

                <div class="uk-text-center uk-margin-small-top">
                    <a href="#" @click.prevent="mode = 'password'" class="uk-link-muted uk-text-small">
                        <?php esc_html_e('Sign in with password instead', 'ntdst-auth'); ?>
                    </a>
                </div>
            </form>
            <?php endif; ?>
            <?php endif; ?>

            <!-- Register link -->
            <?php if ($enableRegistration): ?>
            <div class="uk-text-center uk-margin-top">
                <span class="uk-text-muted"><?php esc_html_e("Don't have an account?", 'ntdst-auth'); ?></span>
                <a href="<?php echo esc_url($registerUrl); ?>"><?php esc_html_e('Register', 'ntdst-auth'); ?></a>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/uikit@3.21.5/dist/js/uikit.min.js"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.3/dist/cdn.min.js"></script>
    <script src="<?php echo esc_url(NTDST_AUTH_URL . 'assets/js/auth.js'); ?>"></script>
    <script>
        window.ntdstAuth = {
            ajaxUrl: '<?php echo esc_url(admin_url('admin-ajax.php')); ?>',
            nonce: '<?php echo esc_js(wp_create_nonce('ntdst_auth_login')); ?>',
            enablePassword: <?php echo $enablePassword ? 'true' : 'false'; ?>
        };
    </script>
    <?php wp_footer(); ?>
</body>
</html>
```

**Step 2: Create register.php**

```php
<?php
/**
 * Register Page Template
 *
 * Variables: $settings (array of all settings)
 */
defined('ABSPATH') || exit;

$loginUrl = home_url($settings['login_url'] ?? '/login');
$termsUrl = home_url($settings['terms_url'] ?? '/terms');
$privacyUrl = home_url($settings['privacy_url'] ?? '/privacy');
$fields = $settings['registration_fields'] ?? ['email', 'first_name', 'last_name'];
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php esc_html_e('Register', 'ntdst-auth'); ?> | <?php bloginfo('name'); ?></title>
    <?php wp_head(); ?>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/uikit@3.21.5/dist/css/uikit.min.css">
    <link rel="stylesheet" href="<?php echo esc_url(NTDST_AUTH_URL . 'assets/css/auth.css'); ?>">
</head>
<body class="ntdst-auth-page">
    <div class="uk-flex uk-flex-center uk-flex-middle uk-height-viewport uk-padding" x-data="authRegister()">
        <div class="uk-card uk-card-default uk-card-body uk-width-medium">
            <!-- Logo slot -->
            <div class="uk-text-center uk-margin-medium-bottom">
                <h2 class="uk-card-title"><?php esc_html_e('Create Account', 'ntdst-auth'); ?></h2>
            </div>

            <!-- Success message -->
            <template x-if="success">
                <div class="uk-alert uk-alert-success" x-text="message"></div>
            </template>

            <!-- Error message -->
            <template x-if="error">
                <div class="uk-alert uk-alert-danger" x-text="message"></div>
            </template>

            <!-- Registration Form -->
            <form @submit.prevent="register" x-show="!success">
                <?php if (in_array('first_name', $fields)): ?>
                <div class="uk-margin">
                    <label class="uk-form-label" for="first_name"><?php esc_html_e('First Name', 'ntdst-auth'); ?></label>
                    <input class="uk-input" type="text" id="first_name" x-model="firstName" required>
                </div>
                <?php endif; ?>

                <?php if (in_array('last_name', $fields)): ?>
                <div class="uk-margin">
                    <label class="uk-form-label" for="last_name"><?php esc_html_e('Last Name', 'ntdst-auth'); ?></label>
                    <input class="uk-input" type="text" id="last_name" x-model="lastName" required>
                </div>
                <?php endif; ?>

                <div class="uk-margin">
                    <label class="uk-form-label" for="email"><?php esc_html_e('Email', 'ntdst-auth'); ?></label>
                    <input class="uk-input" type="email" id="email" x-model="email" required>
                </div>

                <div class="uk-margin">
                    <label>
                        <input class="uk-checkbox" type="checkbox" x-model="consentTerms" required>
                        <?php printf(
                            esc_html__('I accept the %1$sTerms of Service%2$s', 'ntdst-auth'),
                            '<a href="' . esc_url($termsUrl) . '" target="_blank">',
                            '</a>'
                        ); ?>
                    </label>
                </div>

                <div class="uk-margin">
                    <label>
                        <input class="uk-checkbox" type="checkbox" x-model="consentPrivacy" required>
                        <?php printf(
                            esc_html__('I accept the %1$sPrivacy Policy%2$s', 'ntdst-auth'),
                            '<a href="' . esc_url($privacyUrl) . '" target="_blank">',
                            '</a>'
                        ); ?>
                    </label>
                </div>

                <div class="uk-margin">
                    <button class="uk-button uk-button-primary uk-width-1-1" type="submit" :disabled="loading">
                        <span x-show="!loading"><?php esc_html_e('Create Account', 'ntdst-auth'); ?></span>
                        <span x-show="loading" uk-spinner="ratio: 0.6"></span>
                    </button>
                </div>
            </form>

            <!-- Login link -->
            <div class="uk-text-center uk-margin-top">
                <span class="uk-text-muted"><?php esc_html_e('Already have an account?', 'ntdst-auth'); ?></span>
                <a href="<?php echo esc_url($loginUrl); ?>"><?php esc_html_e('Sign In', 'ntdst-auth'); ?></a>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/uikit@3.21.5/dist/js/uikit.min.js"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.3/dist/cdn.min.js"></script>
    <script src="<?php echo esc_url(NTDST_AUTH_URL . 'assets/js/auth.js'); ?>"></script>
    <script>
        window.ntdstAuth = {
            ajaxUrl: '<?php echo esc_url(admin_url('admin-ajax.php')); ?>',
            nonce: '<?php echo esc_js(wp_create_nonce('ntdst_auth_register')); ?>'
        };
    </script>
    <?php wp_footer(); ?>
</body>
</html>
```

**Step 3: Create activate.php**

```php
<?php
/**
 * Activation Success Page Template
 *
 * Variables: $title, $message, $redirect
 */
defined('ABSPATH') || exit;
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo esc_html($title); ?> | <?php bloginfo('name'); ?></title>
    <?php wp_head(); ?>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/uikit@3.21.5/dist/css/uikit.min.css">
    <link rel="stylesheet" href="<?php echo esc_url(NTDST_AUTH_URL . 'assets/css/auth.css'); ?>">
    <?php if (!empty($redirect)): ?>
    <meta http-equiv="refresh" content="3;url=<?php echo esc_url($redirect); ?>">
    <?php endif; ?>
</head>
<body class="ntdst-auth-page">
    <div class="uk-flex uk-flex-center uk-flex-middle uk-height-viewport uk-padding">
        <div class="uk-card uk-card-default uk-card-body uk-width-medium uk-text-center">
            <span uk-icon="icon: check; ratio: 3" class="uk-text-success"></span>
            <h2 class="uk-card-title uk-margin-top"><?php echo esc_html($title); ?></h2>
            <p class="uk-text-muted"><?php echo esc_html($message); ?></p>
            <?php if (!empty($redirect)): ?>
            <p class="uk-text-small uk-text-muted">
                <?php esc_html_e('Redirecting...', 'ntdst-auth'); ?>
            </p>
            <a href="<?php echo esc_url($redirect); ?>" class="uk-button uk-button-primary">
                <?php esc_html_e('Continue', 'ntdst-auth'); ?>
            </a>
            <?php endif; ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/uikit@3.21.5/dist/js/uikit.min.js"></script>
    <?php wp_footer(); ?>
</body>
</html>
```

**Step 4: Create error.php**

```php
<?php
/**
 * Error Page Template
 *
 * Variables: $title, $message, $show_request_new (optional)
 */
defined('ABSPATH') || exit;

$settings = ntdst_get(\NTDST\Auth\SettingsService::class)->getSettings();
$loginUrl = home_url($settings['login_url'] ?? '/login');
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo esc_html($title); ?> | <?php bloginfo('name'); ?></title>
    <?php wp_head(); ?>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/uikit@3.21.5/dist/css/uikit.min.css">
    <link rel="stylesheet" href="<?php echo esc_url(NTDST_AUTH_URL . 'assets/css/auth.css'); ?>">
</head>
<body class="ntdst-auth-page">
    <div class="uk-flex uk-flex-center uk-flex-middle uk-height-viewport uk-padding">
        <div class="uk-card uk-card-default uk-card-body uk-width-medium uk-text-center">
            <span uk-icon="icon: warning; ratio: 3" class="uk-text-warning"></span>
            <h2 class="uk-card-title uk-margin-top"><?php echo esc_html($title); ?></h2>
            <p class="uk-text-muted"><?php echo esc_html($message); ?></p>
            <?php if (!empty($show_request_new)): ?>
            <a href="<?php echo esc_url($loginUrl); ?>" class="uk-button uk-button-primary">
                <?php esc_html_e('Request New Link', 'ntdst-auth'); ?>
            </a>
            <?php else: ?>
            <a href="<?php echo esc_url($loginUrl); ?>" class="uk-button uk-button-default">
                <?php esc_html_e('Back to Login', 'ntdst-auth'); ?>
            </a>
            <?php endif; ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/uikit@3.21.5/dist/js/uikit.min.js"></script>
    <?php wp_footer(); ?>
</body>
</html>
```

**Step 5: Commit**

```bash
git add web/app/plugins/ntdst-auth/templates/pages/
git commit -m "feat(ntdst-auth): add page templates with UIkit + Alpine.js

Co-Authored-By: Claude Opus 4.5 <noreply@anthropic.com>"
```

---

### Task 3.3: Create Assets

**Files:**
- Create: `web/app/plugins/ntdst-auth/assets/css/auth.css`
- Create: `web/app/plugins/ntdst-auth/assets/js/auth.js`

**Step 1: Create auth.css**

```css
/**
 * NTDST Auth - Styles
 *
 * Extends UIkit for authentication pages.
 */

.ntdst-auth-page {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    min-height: 100vh;
}

.ntdst-auth-page .uk-card {
    box-shadow: 0 15px 35px rgba(0, 0, 0, 0.2);
}

.ntdst-auth-page .uk-card-title {
    font-weight: 600;
    color: #333;
}

.ntdst-auth-page .uk-form-label {
    font-weight: 500;
    color: #666;
    margin-bottom: 5px;
    display: block;
}

.ntdst-auth-page .uk-input,
.ntdst-auth-page .uk-select {
    border-radius: 4px;
    border: 1px solid #e5e5e5;
    transition: border-color 0.2s, box-shadow 0.2s;
}

.ntdst-auth-page .uk-input:focus,
.ntdst-auth-page .uk-select:focus {
    border-color: #1e87f0;
    box-shadow: 0 0 0 3px rgba(30, 135, 240, 0.1);
}

.ntdst-auth-page .uk-button-primary {
    border-radius: 4px;
    font-weight: 600;
    text-transform: none;
    padding: 0 30px;
    height: 44px;
}

.ntdst-auth-page .uk-alert {
    border-radius: 4px;
    padding: 15px 20px;
}

.ntdst-auth-page .uk-checkbox {
    margin-right: 8px;
}

.ntdst-auth-page .uk-link-muted {
    transition: color 0.2s;
}

.ntdst-auth-page .uk-link-muted:hover {
    color: #1e87f0;
}

/* Loading state */
.ntdst-auth-page button[disabled] {
    opacity: 0.7;
    cursor: not-allowed;
}

/* Success icon animation */
@keyframes checkmark {
    0% { transform: scale(0); }
    50% { transform: scale(1.2); }
    100% { transform: scale(1); }
}

.ntdst-auth-page .uk-text-success[uk-icon] {
    animation: checkmark 0.5s ease-out;
}
```

**Step 2: Create auth.js**

```javascript
/**
 * NTDST Auth - Alpine.js Components
 */

/**
 * Login page component
 */
function authLogin() {
    return {
        email: '',
        password: '',
        loading: false,
        success: false,
        error: false,
        message: '',
        mode: window.ntdstAuth?.enablePassword ? 'password' : 'magic',

        async requestMagicLink() {
            this.loading = true;
            this.error = false;
            this.success = false;

            try {
                const response = await this.post('ntdst_auth_request_magic_link', {
                    email: this.email
                });

                if (response.success) {
                    this.success = true;
                    this.message = response.data.message;
                } else {
                    this.error = true;
                    this.message = response.data.message || 'An error occurred.';
                }
            } catch (e) {
                this.error = true;
                this.message = 'Network error. Please try again.';
            }

            this.loading = false;
        },

        async loginPassword() {
            this.loading = true;
            this.error = false;
            this.success = false;

            try {
                const response = await this.post('ntdst_auth_login_password', {
                    email: this.email,
                    password: this.password
                });

                if (response.success) {
                    this.success = true;
                    this.message = response.data.message;
                    // Redirect after short delay
                    setTimeout(() => {
                        window.location.href = response.data.redirect || '/';
                    }, 500);
                } else {
                    this.error = true;
                    this.message = response.data.message || 'Invalid credentials.';
                }
            } catch (e) {
                this.error = true;
                this.message = 'Network error. Please try again.';
            }

            this.loading = false;
        },

        async post(action, data) {
            const formData = new FormData();
            formData.append('action', action);
            formData.append('nonce', window.ntdstAuth?.nonce || '');

            for (const [key, value] of Object.entries(data)) {
                formData.append(key, value);
            }

            const response = await fetch(window.ntdstAuth?.ajaxUrl || '/wp-admin/admin-ajax.php', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            });

            return response.json();
        }
    };
}

/**
 * Registration page component
 */
function authRegister() {
    return {
        firstName: '',
        lastName: '',
        email: '',
        consentTerms: false,
        consentPrivacy: false,
        loading: false,
        success: false,
        error: false,
        message: '',

        async register() {
            this.loading = true;
            this.error = false;
            this.success = false;

            // Client-side validation
            if (!this.consentTerms || !this.consentPrivacy) {
                this.error = true;
                this.message = 'Please accept the terms and privacy policy.';
                this.loading = false;
                return;
            }

            try {
                const response = await this.post('ntdst_auth_register', {
                    first_name: this.firstName,
                    last_name: this.lastName,
                    email: this.email,
                    consent_terms: this.consentTerms ? '1' : '',
                    consent_privacy: this.consentPrivacy ? '1' : ''
                });

                if (response.success) {
                    this.success = true;
                    this.message = response.data.message;
                } else {
                    this.error = true;
                    this.message = response.data.message || 'Registration failed.';
                }
            } catch (e) {
                this.error = true;
                this.message = 'Network error. Please try again.';
            }

            this.loading = false;
        },

        async post(action, data) {
            const formData = new FormData();
            formData.append('action', action);
            formData.append('nonce', window.ntdstAuth?.nonce || '');

            for (const [key, value] of Object.entries(data)) {
                formData.append(key, value);
            }

            const response = await fetch(window.ntdstAuth?.ajaxUrl || '/wp-admin/admin-ajax.php', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            });

            return response.json();
        }
    };
}
```

**Step 3: Commit**

```bash
git add web/app/plugins/ntdst-auth/assets/
git commit -m "feat(ntdst-auth): add CSS and JavaScript assets

Co-Authored-By: Claude Opus 4.5 <noreply@anthropic.com>"
```

---

### Task 3.4: Create Admin Settings Page

**Files:**
- Create: `web/app/plugins/ntdst-auth/admin/settings.php`

**Step 1: Create settings.php**

```php
<?php
/**
 * Admin Settings Page Template
 *
 * Variables: $settings (array of all settings)
 */
defined('ABSPATH') || exit;

if (!current_user_can('manage_options')) {
    return;
}

$activeTab = $_GET['tab'] ?? 'urls';
?>
<div class="wrap">
    <h1><?php esc_html_e('Authentication Settings', 'ntdst-auth'); ?></h1>

    <nav class="nav-tab-wrapper">
        <a href="?page=ntdst-auth&tab=urls" class="nav-tab <?php echo $activeTab === 'urls' ? 'nav-tab-active' : ''; ?>">
            <?php esc_html_e('URLs', 'ntdst-auth'); ?>
        </a>
        <a href="?page=ntdst-auth&tab=methods" class="nav-tab <?php echo $activeTab === 'methods' ? 'nav-tab-active' : ''; ?>">
            <?php esc_html_e('Methods', 'ntdst-auth'); ?>
        </a>
        <a href="?page=ntdst-auth&tab=registration" class="nav-tab <?php echo $activeTab === 'registration' ? 'nav-tab-active' : ''; ?>">
            <?php esc_html_e('Registration', 'ntdst-auth'); ?>
        </a>
        <a href="?page=ntdst-auth&tab=security" class="nav-tab <?php echo $activeTab === 'security' ? 'nav-tab-active' : ''; ?>">
            <?php esc_html_e('Security', 'ntdst-auth'); ?>
        </a>
    </nav>

    <form method="post" action="options.php">
        <?php settings_fields('ntdst_auth'); ?>

        <?php if ($activeTab === 'urls'): ?>
        <table class="form-table">
            <tr>
                <th scope="row"><?php esc_html_e('Login URL', 'ntdst-auth'); ?></th>
                <td>
                    <input type="text" name="ntdst_auth_settings[login_url]" value="<?php echo esc_attr($settings['login_url']); ?>" class="regular-text">
                    <p class="description"><?php esc_html_e('URL path for the login page (e.g., /login)', 'ntdst-auth'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Register URL', 'ntdst-auth'); ?></th>
                <td>
                    <input type="text" name="ntdst_auth_settings[register_url]" value="<?php echo esc_attr($settings['register_url']); ?>" class="regular-text">
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Redirect After Login', 'ntdst-auth'); ?></th>
                <td>
                    <input type="text" name="ntdst_auth_settings[redirect_after_login]" value="<?php echo esc_attr($settings['redirect_after_login']); ?>" class="regular-text">
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Redirect After Logout', 'ntdst-auth'); ?></th>
                <td>
                    <input type="text" name="ntdst_auth_settings[redirect_after_logout]" value="<?php echo esc_attr($settings['redirect_after_logout']); ?>" class="regular-text">
                </td>
            </tr>
        </table>
        <?php endif; ?>

        <?php if ($activeTab === 'methods'): ?>
        <table class="form-table">
            <tr>
                <th scope="row"><?php esc_html_e('Enable Magic Link', 'ntdst-auth'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="ntdst_auth_settings[enable_magic_link]" value="1" <?php checked($settings['enable_magic_link']); ?>>
                        <?php esc_html_e('Allow users to log in via magic link', 'ntdst-auth'); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Enable Password', 'ntdst-auth'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="ntdst_auth_settings[enable_password]" value="1" <?php checked($settings['enable_password']); ?>>
                        <?php esc_html_e('Allow users to log in with password', 'ntdst-auth'); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Magic Link Expiry', 'ntdst-auth'); ?></th>
                <td>
                    <input type="number" name="ntdst_auth_settings[magic_link_expiry]" value="<?php echo esc_attr($settings['magic_link_expiry']); ?>" class="small-text" min="1" max="60">
                    <?php esc_html_e('minutes', 'ntdst-auth'); ?>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Magic Link Max Uses', 'ntdst-auth'); ?></th>
                <td>
                    <input type="number" name="ntdst_auth_settings[magic_link_max_uses]" value="<?php echo esc_attr($settings['magic_link_max_uses']); ?>" class="small-text" min="1" max="10">
                    <p class="description"><?php esc_html_e('Number of times a magic link can be used (handles email scanner pre-fetching)', 'ntdst-auth'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Activation Link Expiry', 'ntdst-auth'); ?></th>
                <td>
                    <input type="number" name="ntdst_auth_settings[activation_link_expiry]" value="<?php echo esc_attr($settings['activation_link_expiry']); ?>" class="small-text" min="1" max="168">
                    <?php esc_html_e('hours', 'ntdst-auth'); ?>
                </td>
            </tr>
        </table>
        <?php endif; ?>

        <?php if ($activeTab === 'registration'): ?>
        <table class="form-table">
            <tr>
                <th scope="row"><?php esc_html_e('Enable Registration', 'ntdst-auth'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="ntdst_auth_settings[enable_registration]" value="1" <?php checked($settings['enable_registration']); ?>>
                        <?php esc_html_e('Allow new user registration', 'ntdst-auth'); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Registration Fields', 'ntdst-auth'); ?></th>
                <td>
                    <?php
                    $availableFields = ['email' => 'Email', 'first_name' => 'First Name', 'last_name' => 'Last Name', 'phone' => 'Phone', 'company' => 'Company'];
                    $selectedFields = $settings['registration_fields'] ?? [];
                    foreach ($availableFields as $field => $label):
                    ?>
                    <label style="display: block; margin-bottom: 5px;">
                        <input type="checkbox" name="ntdst_auth_settings[registration_fields][]" value="<?php echo esc_attr($field); ?>" <?php checked(in_array($field, $selectedFields)); ?> <?php echo $field === 'email' ? 'disabled checked' : ''; ?>>
                        <?php echo esc_html($label); ?>
                        <?php if ($field === 'email'): ?><em>(<?php esc_html_e('required', 'ntdst-auth'); ?>)</em><?php endif; ?>
                    </label>
                    <?php endforeach; ?>
                    <input type="hidden" name="ntdst_auth_settings[registration_fields][]" value="email">
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Terms URL', 'ntdst-auth'); ?></th>
                <td>
                    <input type="text" name="ntdst_auth_settings[terms_url]" value="<?php echo esc_attr($settings['terms_url']); ?>" class="regular-text">
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Privacy Policy URL', 'ntdst-auth'); ?></th>
                <td>
                    <input type="text" name="ntdst_auth_settings[privacy_url]" value="<?php echo esc_attr($settings['privacy_url']); ?>" class="regular-text">
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Consent Version', 'ntdst-auth'); ?></th>
                <td>
                    <input type="text" name="ntdst_auth_settings[consent_version]" value="<?php echo esc_attr($settings['consent_version']); ?>" class="small-text">
                    <p class="description"><?php esc_html_e('Increment this when terms/privacy change to prompt re-consent', 'ntdst-auth'); ?></p>
                </td>
            </tr>
        </table>
        <?php endif; ?>

        <?php if ($activeTab === 'security'): ?>
        <table class="form-table">
            <tr>
                <th scope="row"><?php esc_html_e('Redirect wp-login.php', 'ntdst-auth'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="ntdst_auth_settings[redirect_wp_login]" value="1" <?php checked($settings['redirect_wp_login']); ?>>
                        <?php esc_html_e('Redirect default WordPress login to custom login page', 'ntdst-auth'); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Rate Limit Window', 'ntdst-auth'); ?></th>
                <td>
                    <input type="number" name="ntdst_auth_settings[rate_limit_window]" value="<?php echo esc_attr($settings['rate_limit_window']); ?>" class="small-text" min="1" max="60">
                    <?php esc_html_e('minutes', 'ntdst-auth'); ?>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Magic Links per Email', 'ntdst-auth'); ?></th>
                <td>
                    <input type="number" name="ntdst_auth_settings[rate_limit_magic_link_per_email]" value="<?php echo esc_attr($settings['rate_limit_magic_link_per_email']); ?>" class="small-text" min="1" max="20">
                    <span class="description"><?php esc_html_e('per rate limit window', 'ntdst-auth'); ?></span>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Magic Links per IP', 'ntdst-auth'); ?></th>
                <td>
                    <input type="number" name="ntdst_auth_settings[rate_limit_magic_link_per_ip]" value="<?php echo esc_attr($settings['rate_limit_magic_link_per_ip']); ?>" class="small-text" min="1" max="50">
                    <span class="description"><?php esc_html_e('per rate limit window', 'ntdst-auth'); ?></span>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Login Attempts per IP', 'ntdst-auth'); ?></th>
                <td>
                    <input type="number" name="ntdst_auth_settings[rate_limit_login_per_ip]" value="<?php echo esc_attr($settings['rate_limit_login_per_ip']); ?>" class="small-text" min="1" max="20">
                    <span class="description"><?php esc_html_e('per rate limit window', 'ntdst-auth'); ?></span>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Registration Attempts per IP', 'ntdst-auth'); ?></th>
                <td>
                    <input type="number" name="ntdst_auth_settings[rate_limit_registration_per_ip]" value="<?php echo esc_attr($settings['rate_limit_registration_per_ip']); ?>" class="small-text" min="1" max="10">
                    <span class="description"><?php esc_html_e('per hour', 'ntdst-auth'); ?></span>
                </td>
            </tr>
        </table>
        <?php endif; ?>

        <?php submit_button(); ?>
    </form>
</div>
```

**Step 2: Commit**

```bash
git add web/app/plugins/ntdst-auth/admin/
git commit -m "feat(ntdst-auth): add admin settings page with tabs

Co-Authored-By: Claude Opus 4.5 <noreply@anthropic.com>"
```

---

## Phase 4: Verification

### Task 4.1: Activate and Smoke Test

**Step 1: Activate plugin**

Run:
```bash
ddev exec wp plugin activate ntdst-auth
```
Expected: "Plugin 'ntdst-auth' activated."

**Step 2: Flush rewrite rules**

Run:
```bash
ddev exec wp rewrite flush
```

**Step 3: Verify routes**

Run:
```bash
curl -s -o /dev/null -w "%{http_code}" https://stride.ddev.site/login
```
Expected: 200

**Step 4: Verify admin settings page**

- Navigate to: https://stride.ddev.site/wp/wp-admin/options-general.php?page=ntdst-auth
- Expected: Settings page with 4 tabs (URLs, Methods, Registration, Security)

**Step 5: Commit verification**

```bash
git add -A && git commit -m "chore(ntdst-auth): plugin activation verified

Co-Authored-By: Claude Opus 4.5 <noreply@anthropic.com>"
```

---

## Verification Stages (MANDATORY — do not skip)

> These stages run AFTER all implementation tasks are complete.
> If ANY stage fails: fix → re-run that stage → continue.
> The plan is NOT done until all stages pass.

### Stage V1: Static Analysis

Run:
```bash
ddev exec vendor/bin/phpcs --standard=PSR12 web/app/plugins/ntdst-auth/src/ --ignore=*/templates/*
```
Expected: No errors. Fix all issues before proceeding.

### Stage V2: Manual Flow Testing

Since this project doesn't have PHPUnit set up for the plugin, verify flows manually:

1. **Registration Flow**
   - Go to /register
   - Fill form with test email, check consent boxes
   - Submit → should see "Check your inbox"
   - Check Mailhog for activation email
   - Click activation link → should activate and redirect

2. **Magic Link Flow**
   - Go to /login
   - Enter registered email
   - Submit → should see "Check your inbox"
   - Check Mailhog for magic link email
   - Click link → should log in and redirect

3. **Rate Limiting**
   - Request 4 magic links in a row
   - 4th should show rate limit message

4. **Admin Settings**
   - Go to Settings → Authentication
   - Change settings, save
   - Refresh → settings should persist

### Stage V3: Security Verification

1. **Email Enumeration**
   - Request magic link for non-existent email
   - Should show same message as existing email

2. **Token Security**
   - Use magic link 3 times
   - 4th use should show error

3. **CSRF**
   - Attempt AJAX request without nonce
   - Should fail with "Invalid security token"

### Stage V4: GDPR Verification

1. **Privacy Export**
   - Go to Tools → Export Personal Data
   - Add a registered user's email
   - Export should include consent data

2. **Privacy Erase**
   - Go to Tools → Erase Personal Data
   - Erase user's data
   - User meta should be cleared

### Stage V5: Final Commit

```bash
git add -A && git status
git commit -m "feat(ntdst-auth): complete authentication plugin

Implements:
- Magic link login with 3 uses, 15 min expiry
- Registration with activation flow
- GDPR consent tracking with privacy tools
- Admin settings page with 4 tabs
- Rate limiting for security
- UIkit 3 + Alpine.js frontend

Co-Authored-By: Claude Opus 4.5 <noreply@anthropic.com>"
```
