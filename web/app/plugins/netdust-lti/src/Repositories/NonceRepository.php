<?php
declare(strict_types=1);

namespace NetdustLTI\Repositories;

final class NonceRepository
{
    private \wpdb $wpdb;
    private string $table;

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table = $wpdb->prefix . 'netdust_lti_nonces';
    }

    public function exists(int $platformId, string $nonce): bool
    {
        $result = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT 1 FROM {$this->table} WHERE platform_id = %d AND nonce = %s AND expires_at > NOW()",
                $platformId,
                $nonce
            )
        );

        return $result !== null;
    }

    public function save(int $platformId, string $nonce, int $expiresAt): bool
    {
        $result = $this->wpdb->insert(
            $this->table,
            [
                'platform_id' => $platformId,
                'nonce' => $nonce,
                'expires_at' => date('Y-m-d H:i:s', $expiresAt),
            ]
        );

        return $result !== false;
    }

    public function cleanup(): int
    {
        return (int) $this->wpdb->query(
            "DELETE FROM {$this->table} WHERE expires_at < NOW()"
        );
    }
}
