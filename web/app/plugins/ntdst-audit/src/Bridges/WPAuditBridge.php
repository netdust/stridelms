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
}
