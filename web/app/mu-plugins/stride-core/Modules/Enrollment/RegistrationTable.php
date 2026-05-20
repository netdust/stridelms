<?php

declare(strict_types=1);

namespace Stride\Modules\Enrollment;

/**
 * Unified registration table for edition and trajectory enrollments.
 *
 * Handles:
 * - Edition enrollment (edition_id set, trajectory_id null)
 * - Trajectory enrollment (trajectory_id set, edition_id null)
 * - Edition via trajectory (both set)
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
            user_id BIGINT UNSIGNED NULL,
            edition_id BIGINT UNSIGNED NULL,
            trajectory_id BIGINT UNSIGNED NULL,
            parent_registration_id BIGINT UNSIGNED NULL,
            status ENUM('confirmed','cancelled','waitlist','completed','interest','pending') DEFAULT 'confirmed',
            enrollment_path ENUM('individual','colleague','trajectory') DEFAULT 'individual',
            selections JSON NULL COMMENT 'Session IDs or elective edition IDs',
            selections_locked_at DATETIME NULL,
            quote_id BIGINT UNSIGNED NULL,
            company_id BIGINT UNSIGNED NULL,
            enrolled_by BIGINT UNSIGNED NULL,
            registered_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            completed_at DATETIME NULL,
            cancelled_at DATETIME NULL,
            notes TEXT NULL,
            completion_tasks JSON NULL,
            enrollment_data JSON NULL,
            INDEX idx_user (user_id),
            INDEX idx_edition (edition_id),
            INDEX idx_trajectory (trajectory_id),
            INDEX idx_parent (parent_registration_id),
            INDEX idx_status (status),
            INDEX idx_edition_status (edition_id, status),
            INDEX idx_trajectory_status (trajectory_id, status),
            INDEX idx_company (company_id),
            INDEX idx_user_status (user_id, status),
            INDEX idx_user_edition (user_id, edition_id)
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
