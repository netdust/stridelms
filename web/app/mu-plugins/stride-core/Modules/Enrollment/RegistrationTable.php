<?php

declare(strict_types=1);

namespace Stride\Modules\Enrollment;

/**
 * Registration table creation and migration.
 */
final class RegistrationTable
{
    public const TABLE_NAME = 'vad_registrations';

    public static function getTableName(): string
    {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_NAME;
    }

    public static function create(): void
    {
        global $wpdb;

        $table = self::getTableName();
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            edition_id BIGINT UNSIGNED NOT NULL,
            status ENUM('confirmed','cancelled','waitlist','interest') DEFAULT 'confirmed',
            enrollment_path ENUM('individual','colleague','trajectory','interest') DEFAULT 'individual',
            enrolled_by BIGINT UNSIGNED NULL,
            voucher_code VARCHAR(50) NULL,
            quote_id BIGINT UNSIGNED NULL,
            registered_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            cancelled_at DATETIME NULL,
            notes TEXT NULL,
            INDEX idx_user (user_id),
            INDEX idx_edition (edition_id),
            INDEX idx_status (status),
            INDEX idx_edition_status (edition_id, status),
            UNIQUE KEY unique_user_edition (user_id, edition_id)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    public static function exists(): bool
    {
        global $wpdb;

        $table = self::getTableName();

        return $wpdb->get_var("SHOW TABLES LIKE '{$table}'") === $table;
    }

    /**
     * Add composite index for existing tables (migration).
     */
    public static function addCompositeIndexIfMissing(): void
    {
        global $wpdb;

        $table = self::getTableName();

        // Check if index exists
        $indexExists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.statistics
             WHERE table_schema = DATABASE()
             AND table_name = %s
             AND index_name = 'idx_edition_status'",
            $table
        ));

        if (!$indexExists) {
            $wpdb->query("ALTER TABLE {$table} ADD INDEX idx_edition_status (edition_id, status)");
        }
    }
}
