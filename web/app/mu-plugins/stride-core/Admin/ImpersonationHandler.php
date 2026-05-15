<?php
declare(strict_types=1);

namespace Stride\Admin;

final class ImpersonationHandler
{
    public const COOKIE_NAME = 'stride_impersonate_token';
    public const TRANSIENT_PREFIX = 'stride_impersonate_';
    public const TTL = 3600;

    /**
     * Validate that the caller may impersonate the target user.
     *
     * @param int  $targetUserId           The user ID to impersonate.
     * @param bool $targetIsAdmin          Whether the target holds administrator privileges.
     * @param bool $callerHasManageOptions Whether the caller has manage_options capability.
     *
     * @return true|\WP_Error
     */
    public function validateTarget(
        int $targetUserId,
        bool $targetIsAdmin,
        bool $callerHasManageOptions
    ): true|\WP_Error {
        if (!$callerHasManageOptions) {
            return new \WP_Error('forbidden', __('You do not have permission to impersonate users.', 'stride'));
        }

        if ($targetUserId <= 0) {
            return new \WP_Error('invalid_user', __('Invalid user.', 'stride'));
        }

        if ($targetIsAdmin) {
            return new \WP_Error('cannot_impersonate_admin', __('Cannot impersonate an administrator.', 'stride'));
        }

        return true;
    }

    /**
     * Generate a cryptographically secure random token (64 hex chars).
     */
    public function generateToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Store an impersonation session in a transient.
     *
     * @param string $token           The session token.
     * @param int    $originalAdminId The WP user ID of the admin starting impersonation.
     * @param int    $targetUserId    The WP user ID of the user being impersonated.
     */
    public function storeSession(string $token, int $originalAdminId, int $targetUserId = 0): void
    {
        set_transient(
            self::TRANSIENT_PREFIX . $token,
            ['admin_id' => $originalAdminId, 'target_id' => $targetUserId],
            self::TTL
        );
    }

    /**
     * Retrieve the impersonation session payload for a given token.
     *
     * @return array{admin_id: int, target_id: int}|null
     */
    public function getSession(string $token): ?array
    {
        $value = get_transient(self::TRANSIENT_PREFIX . $token);

        if (is_array($value)) {
            return [
                'admin_id'  => (int) ($value['admin_id'] ?? 0),
                'target_id' => (int) ($value['target_id'] ?? 0),
            ];
        }

        // Backwards-compatible legacy payload (int admin id only).
        if (is_int($value) || is_numeric($value)) {
            return ['admin_id' => (int) $value, 'target_id' => 0];
        }

        return null;
    }

    /**
     * Retrieve the original admin ID for a given impersonation token.
     *
     * @param string $token The session token.
     *
     * @return int The original admin user ID, or 0 if not found.
     */
    public function getOriginalAdmin(string $token): int
    {
        return $this->getSession($token)['admin_id'] ?? 0;
    }

    /**
     * End an impersonation session by deleting its transient.
     *
     * @param string $token The session token.
     */
    public function endSession(string $token): void
    {
        delete_transient(self::TRANSIENT_PREFIX . $token);
    }

    /**
     * Check whether an impersonation session is currently active (cookie present).
     */
    public function isActive(): bool
    {
        return !empty($_COOKIE[self::COOKIE_NAME]);
    }

    /**
     * Read and sanitize the impersonation token from the cookie.
     */
    public function getTokenFromCookie(): string
    {
        $raw = $_COOKIE[self::COOKIE_NAME] ?? '';

        return sanitize_text_field((string) $raw);
    }
}
