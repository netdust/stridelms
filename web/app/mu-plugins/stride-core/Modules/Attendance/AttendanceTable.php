<?php

declare(strict_types=1);

namespace Stride\Modules\Attendance;

/**
 * Attendance table for session attendance tracking.
 */
final class AttendanceTable
{
    public const TABLE_NAME = 'vad_attendance';

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
            edition_id BIGINT UNSIGNED NOT NULL,
            session_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            status ENUM('present','absent','excused') DEFAULT 'present',
            marked_by BIGINT UNSIGNED NULL,
            marked_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_session_user (session_id, user_id),
            INDEX idx_user_status (user_id, status),
            INDEX idx_edition (edition_id),
            UNIQUE KEY unique_session_user (session_id, user_id)
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
