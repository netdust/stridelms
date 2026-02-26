<?php
declare(strict_types=1);

namespace NetdustLTI\Repositories;

/**
 * Repository for LTI nonces.
 *
 * Uses WordPress transients for auto-expiring storage.
 * Nonces are short-lived (typically 5-10 minutes) so transients
 * are ideal - they auto-expire and don't require manual cleanup.
 */
final class NonceRepository
{
    /**
     * Generate transient key for a nonce.
     */
    private function getTransientKey(int $platformId, string $nonce): string
    {
        return 'lti_nonce_' . $platformId . '_' . md5($nonce);
    }

    /**
     * Check if a nonce exists (has been used).
     */
    public function exists(int $platformId, string $nonce): bool
    {
        $key = $this->getTransientKey($platformId, $nonce);
        return get_transient($key) !== false;
    }

    /**
     * Save a nonce (mark as used).
     */
    public function save(int $platformId, string $nonce, int $expiresAt): bool
    {
        $key = $this->getTransientKey($platformId, $nonce);
        $ttl = max(0, $expiresAt - time());

        return set_transient($key, '1', $ttl);
    }

    /**
     * Clean up expired nonces.
     *
     * With transients, WordPress handles cleanup automatically.
     * This method is kept for interface compatibility.
     *
     * @return int Always returns 0 with transients
     */
    public function cleanup(): int
    {
        // Transients auto-expire, no manual cleanup needed
        return 0;
    }
}
