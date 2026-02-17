<?php

declare(strict_types=1);

namespace Stride\Modules\Trajectory;

/**
 * Trajectory enrollment table for user enrollments and elective choices.
 */
final class TrajectoryEnrollmentTable
{
    public const TABLE_NAME = 'vad_trajectory_enrollments';

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
            trajectory_id BIGINT UNSIGNED NOT NULL,
            status ENUM('enrolled','completed','cancelled','withdrawn') DEFAULT 'enrolled',
            elective_choices JSON NULL COMMENT 'Array of chosen course_ids by group',
            choices_locked_at DATETIME NULL,
            enrolled_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            completed_at DATETIME NULL,
            cancelled_at DATETIME NULL,
            notes TEXT NULL,
            INDEX idx_user (user_id),
            INDEX idx_trajectory (trajectory_id),
            INDEX idx_status (status),
            UNIQUE KEY unique_user_trajectory (user_id, trajectory_id)
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
