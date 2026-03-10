<?php

declare(strict_types=1);

namespace NTDST\Audit\Bridges;

use NTDST\Audit\AuditService;
use WP_User;

/**
 * Bridges GDPR-relevant WordPress core events to the NTDST Audit system.
 *
 * Logs authentication, user lifecycle, personal data changes,
 * privacy API usage, and security-relevant admin actions.
 */
final class WPAuditBridge implements \NTDST_Service_Meta
{
    /**
     * User meta keys containing personal data (GDPR-relevant).
     */
    private const GDPR_META_KEYS = [
        'first_name', 'last_name', 'nickname', 'description',
        'billing_first_name', 'billing_last_name', 'billing_company',
        'billing_address_1', 'billing_address_2', 'billing_city',
        'billing_postcode', 'billing_country', 'billing_state',
        'billing_email', 'billing_phone', 'billing_vat',
        'shipping_first_name', 'shipping_last_name', 'shipping_company',
        'shipping_address_1', 'shipping_address_2', 'shipping_city',
        'shipping_postcode', 'shipping_country', 'shipping_state',
    ];

    /**
     * Security-relevant WordPress options.
     */
    private const SECURITY_OPTIONS = [
        'blogname', 'blogdescription', 'siteurl', 'home',
        'admin_email', 'users_can_register', 'default_role',
        'permalink_structure', 'blog_public',
        'wp_page_for_privacy_policy',
    ];

    public static function metadata(): array
    {
        return [
            'name' => 'WP Audit Bridge',
            'description' => 'Logs GDPR-relevant WordPress core events',
            'priority' => 99,
        ];
    }

    public function __construct()
    {
        $this->init();
    }

    private function init(): void
    {
        // Authentication
        add_action('wp_login', [$this, 'onLogin'], 10, 2);
        add_action('wp_logout', [$this, 'onLogout'], 10, 1);
        add_action('wp_login_failed', [$this, 'onLoginFailed'], 10, 1);

        // User lifecycle
        add_action('user_register', [$this, 'onUserCreated'], 10, 1);
        add_action('delete_user', [$this, 'onUserDeleted'], 10, 2);
        add_action('profile_update', [$this, 'onProfileUpdated'], 10, 2);
        add_action('set_user_role', [$this, 'onRoleChanged'], 10, 3);

        // User meta (personal data)
        add_action('updated_user_meta', [$this, 'onUserMetaUpdated'], 10, 4);
        add_action('deleted_user_meta', [$this, 'onUserMetaDeleted'], 10, 4);

        // WP Privacy API
        add_action('wp_privacy_personal_data_export_file_created', [$this, 'onPrivacyExportCreated'], 10, 4);
        add_action('wp_privacy_personal_data_erased', [$this, 'onPrivacyDataErased'], 10, 5);
        add_action('user_request_action_confirmed', [$this, 'onPrivacyRequestConfirmed'], 10, 1);

        // Admin actions
        add_action('updated_option', [$this, 'onOptionUpdated'], 10, 3);
        add_action('activated_plugin', [$this, 'onPluginActivated'], 10, 1);
        add_action('deactivated_plugin', [$this, 'onPluginDeactivated'], 10, 1);
        add_action('switch_theme', [$this, 'onThemeSwitched'], 10, 2);
    }

    private function audit(): AuditService
    {
        return ntdst_get(AuditService::class);
    }

    private function hashedIp(): string
    {
        return wp_hash($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    // ── Authentication events ──────────────────────────────────────

    public function onLogin(string $userLogin, WP_User $user): void
    {
        $this->audit()->record(
            'user',
            $user->ID,
            'auth.login',
            $user->ID,
            [
                'user_login' => $userLogin,
                'ip_hash' => $this->hashedIp(),
            ]
        );
    }

    public function onLogout(int $userId): void
    {
        $this->audit()->record(
            'user',
            $userId,
            'auth.logout',
            $userId,
        );
    }

    public function onLoginFailed(string $userLogin): void
    {
        $this->audit()->record(
            'user',
            0,
            'auth.login_failed',
            null,
            [
                'user_login' => $userLogin,
                'ip_hash' => $this->hashedIp(),
            ]
        );
    }

    // ── User Lifecycle ─────────────────────────────────────────────

    public function onUserCreated(int $userId): void
    {
        $user = get_userdata($userId);
        $roles = $user ? $user->roles : [];

        $this->audit()->record('user', $userId, 'user.created', null, [
            'roles' => $roles,
        ]);
    }

    public function onUserDeleted(int $userId, ?int $reassignTo): void
    {
        $this->audit()->record('user', $userId, 'user.deleted', null, [
            'reassign_to' => $reassignTo,
        ]);
    }

    public function onProfileUpdated(int $userId, WP_User $oldUser): void
    {
        $trackFields = ['first_name', 'last_name', 'nickname', 'user_email', 'display_name', 'description'];
        $changed = [];

        foreach ($trackFields as $field) {
            $oldValue = $oldUser->$field ?? '';
            $newValue = get_user_meta($userId, $field, true);

            if (in_array($field, ['user_email', 'display_name'], true)) {
                $newUser = get_userdata($userId);
                $newValue = $newUser ? ($newUser->$field ?? '') : '';
            }

            if ((string) $oldValue !== (string) $newValue) {
                $changed[] = $field;
            }
        }

        if (empty($changed)) {
            return;
        }

        $this->audit()->record('user', $userId, 'user.profile_updated', null, [
            'changed_fields' => $changed,
        ]);
    }

    public function onRoleChanged(int $userId, string $newRole, array $oldRoles): void
    {
        $this->audit()->record('user', $userId, 'user.role_changed', null, [
            'new_role' => $newRole,
            'old_role' => $oldRoles[0] ?? '',
        ]);
    }

    // ── User Meta ───────────────────────────────────────────────────

    public function onUserMetaUpdated(int $metaId, int $userId, string $metaKey, mixed $metaValue): void
    {
        if (!in_array($metaKey, self::GDPR_META_KEYS, true)) {
            return;
        }

        $this->audit()->record('user', $userId, 'usermeta.updated', null, [
            'meta_key' => $metaKey,
        ]);
    }

    public function onUserMetaDeleted(array $metaIds, int $userId, string $metaKey, mixed $metaValue): void
    {
        if (!in_array($metaKey, self::GDPR_META_KEYS, true)) {
            return;
        }

        $this->audit()->record('user', $userId, 'usermeta.deleted', null, [
            'meta_key' => $metaKey,
        ]);
    }

    // ── WP Privacy API ──────────────────────────────────────────────

    public function onPrivacyExportCreated(string $archivePath, string $archiveUrl, string $requestEmail, int $requestId): void
    {
        $this->audit()->record('privacy_request', $requestId, 'privacy.export_created', null, [
            'request_email' => $requestEmail,
        ]);
    }

    public function onPrivacyDataErased(int $requestId, string $requestEmail, int $itemsRemoved, int $itemsRetained, bool $done): void
    {
        $this->audit()->record('privacy_request', $requestId, 'privacy.data_erased', null, [
            'request_email' => $requestEmail,
            'items_removed' => $itemsRemoved,
            'items_retained' => $itemsRetained,
        ]);
    }

    public function onPrivacyRequestConfirmed(int $requestId): void
    {
        $request = get_post($requestId);
        $actionName = $request->post_name ?? '';
        $email = $request->post_title ?? '';

        $this->audit()->record('privacy_request', $requestId, 'privacy.request_confirmed', null, [
            'action_name' => $actionName,
            'request_email' => $email,
        ]);
    }

    // ── Admin Actions ──────────────────────────────────────────────

    public function onOptionUpdated(string $option, mixed $oldValue, mixed $newValue): void
    {
        if (!in_array($option, self::SECURITY_OPTIONS, true)) {
            return;
        }

        $this->audit()->record('option', 0, 'option.updated', null, [
            'option_name' => $option,
        ]);
    }

    public function onPluginActivated(string $plugin): void
    {
        $this->audit()->record('plugin', 0, 'plugin.activated', null, [
            'plugin_file' => $plugin,
        ]);
    }

    public function onPluginDeactivated(string $plugin): void
    {
        $this->audit()->record('plugin', 0, 'plugin.deactivated', null, [
            'plugin_file' => $plugin,
        ]);
    }

    public function onThemeSwitched(string $newThemeName, \WP_Theme $oldTheme): void
    {
        $this->audit()->record('theme', 0, 'theme.switched', null, [
            'new_theme' => $newThemeName,
            'old_theme' => $oldTheme->get_stylesheet(),
        ]);
    }
}
