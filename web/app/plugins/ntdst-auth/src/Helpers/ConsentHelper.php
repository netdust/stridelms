<?php

declare(strict_types=1);

namespace NTDST\Auth\Helpers;

defined('ABSPATH') || exit;

/**
 * GDPR consent recording and verification utilities.
 *
 * Stores consent in user meta with version, timestamp, and IP.
 */
final class ConsentHelper
{
    private const META_CONSENT = 'ntdst_auth_consent';
    private const META_ACTIVATED = 'ntdst_auth_activated';
    private const META_ACTIVATED_AT = 'ntdst_auth_activated_at';

    /**
     * @param array<string, mixed> $config Plugin configuration
     */
    public function __construct(
        private readonly array $config,
    ) {}

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
            'version' => $this->config['consent_version'] ?? '1.0',
            'timestamp' => time(),
            'ip' => $this->getClientIp(),
        ];

        $result = update_user_meta($userId, self::META_CONSENT, $data);

        if ($result) {
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

        $currentVersion = $this->config['consent_version'] ?? '1.0';
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

        $currentVersion = $this->config['consent_version'] ?? '1.0';
        if (($consent['version'] ?? '') !== $currentVersion) {
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
     * Export user authentication data (for WP privacy tools).
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
     * Erase user authentication data (for WP privacy tools).
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
