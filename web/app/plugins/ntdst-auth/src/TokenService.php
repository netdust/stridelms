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
