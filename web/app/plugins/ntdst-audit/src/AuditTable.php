<?php

declare(strict_types=1);

namespace NTDST\Audit;

final class AuditTable
{
    public const TABLE_NAME = 'audit_log';

    public static function getTableName(): string
    {
        global $wpdb;
        $tableName = apply_filters('ntdst/audit/table_name', self::TABLE_NAME);
        return $wpdb->prefix . $tableName;
    }

    public static function create(): void
    {
        global $wpdb;

        $table = self::getTableName();
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            entity_type VARCHAR(50) NOT NULL,
            entity_id BIGINT UNSIGNED NOT NULL,
            action VARCHAR(50) NOT NULL,
            actor_id BIGINT UNSIGNED NULL,
            actor_type VARCHAR(20) NOT NULL DEFAULT 'user',
            context JSON,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_entity (entity_type, entity_id),
            INDEX idx_actor (actor_id),
            INDEX idx_created (created_at)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    public static function exists(): bool
    {
        global $wpdb;
        $table = self::getTableName();
        return $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) === $table;
    }
}
