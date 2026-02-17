<?php

declare(strict_types=1);

namespace Stride\Modules\Edition;

/**
 * Session registration table for user session selections.
 */
final class SessionRegistrationTable
{
    public const TABLE_NAME = 'vad_session_registrations';

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
            registration_id BIGINT UNSIGNED NOT NULL,
            session_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            status ENUM('registered','cancelled') DEFAULT 'registered',
            registered_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            cancelled_at DATETIME NULL,
            INDEX idx_registration (registration_id),
            INDEX idx_session (session_id),
            INDEX idx_user (user_id),
            INDEX idx_status (status),
            UNIQUE KEY unique_user_session (user_id, session_id)
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
}
