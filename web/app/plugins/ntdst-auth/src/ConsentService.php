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
