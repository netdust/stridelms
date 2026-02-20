<?php
declare(strict_types=1);

namespace NetdustLTI\Repositories;

use NetdustLTI\Domain\Platform;
use WP_Error;

final class PlatformRepository
{
    private \wpdb $wpdb;
    private string $table;

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table = $wpdb->prefix . 'netdust_lti_platforms';
    }

    public function find(int $id): Platform|WP_Error
    {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare("SELECT * FROM {$this->table} WHERE id = %d", $id),
            ARRAY_A
        );

        if (!$row) {
            return new WP_Error('not_found', 'Platform not found');
        }

        return Platform::fromRow($row);
    }

    public function findByIssuerAndClient(string $platformId, string $clientId): Platform|WP_Error
    {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE platform_id = %s AND client_id = %s",
                $platformId,
                $clientId
            ),
            ARRAY_A
        );

        if (!$row) {
            return new WP_Error('not_found', 'Platform not found');
        }

        return Platform::fromRow($row);
    }

    public function all(): array
    {
        $rows = $this->wpdb->get_results(
            "SELECT * FROM {$this->table} ORDER BY name",
            ARRAY_A
        );

        return array_map(fn($row) => Platform::fromRow($row), $rows);
    }

    public function allEnabled(): array
    {
        $rows = $this->wpdb->get_results(
            "SELECT * FROM {$this->table} WHERE enabled = 1 ORDER BY name",
            ARRAY_A
        );

        return array_map(fn($row) => Platform::fromRow($row), $rows);
    }

    public function create(Platform $platform): int|WP_Error
    {
        $data = $platform->toArray();
        $data['created_at'] = current_time('mysql');
        $data['updated_at'] = current_time('mysql');

        $result = $this->wpdb->insert($this->table, $data);

        if ($result === false) {
            return new WP_Error('insert_failed', $this->wpdb->last_error);
        }

        return (int) $this->wpdb->insert_id;
    }

    public function update(int $id, Platform $platform): bool|WP_Error
    {
        $data = $platform->toArray();
        $data['updated_at'] = current_time('mysql');

        $result = $this->wpdb->update($this->table, $data, ['id' => $id]);

        if ($result === false) {
            return new WP_Error('update_failed', $this->wpdb->last_error);
        }

        return true;
    }

    public function delete(int $id): bool|WP_Error
    {
        $result = $this->wpdb->delete($this->table, ['id' => $id]);

        if ($result === false) {
            return new WP_Error('delete_failed', $this->wpdb->last_error);
        }

        return true;
    }
}
