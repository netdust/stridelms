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
            user_id BIGINT UNSIGNED NOT NULL,
            edition_id BIGINT UNSIGNED NULL,
            trajectory_id BIGINT UNSIGNED NULL,
            status ENUM('confirmed','cancelled','waitlist','completed','withdrawn') DEFAULT 'confirmed',
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
            INDEX idx_user (user_id),
            INDEX idx_edition (edition_id),
            INDEX idx_trajectory (trajectory_id),
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

    /**
     * Migration: Add new columns to existing table.
     */
    public static function migrate(): void
    {
        global $wpdb;

        $table = self::getTableName();

        // Add trajectory_id if missing
        $hasTrajectoryId = $wpdb->get_var("SHOW COLUMNS FROM {$table} LIKE 'trajectory_id'");
        if (!$hasTrajectoryId) {
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN trajectory_id BIGINT UNSIGNED NULL AFTER edition_id");
            $wpdb->query("ALTER TABLE {$table} ADD INDEX idx_trajectory (trajectory_id)");
            $wpdb->query("ALTER TABLE {$table} ADD INDEX idx_trajectory_status (trajectory_id, status)");
        }

        // Add selections if missing
        $hasSelections = $wpdb->get_var("SHOW COLUMNS FROM {$table} LIKE 'selections'");
        if (!$hasSelections) {
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN selections JSON NULL AFTER enrollment_path");
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN selections_locked_at DATETIME NULL AFTER selections");
        }

        // Add completed_at if missing
        $hasCompletedAt = $wpdb->get_var("SHOW COLUMNS FROM {$table} LIKE 'completed_at'");
        if (!$hasCompletedAt) {
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN completed_at DATETIME NULL AFTER registered_at");
        }

        // Make edition_id nullable if needed
        $wpdb->query("ALTER TABLE {$table} MODIFY COLUMN edition_id BIGINT UNSIGNED NULL");

        // Drop voucher_code if exists (moved to quote)
        $hasVoucherCode = $wpdb->get_var("SHOW COLUMNS FROM {$table} LIKE 'voucher_code'");
        if ($hasVoucherCode) {
            $wpdb->query("ALTER TABLE {$table} DROP COLUMN voucher_code");
        }

        // Add company_id if missing (Partner API)
        $hasCompanyId = $wpdb->get_var("SHOW COLUMNS FROM {$table} LIKE 'company_id'");
        if (!$hasCompanyId) {
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN company_id BIGINT UNSIGNED NULL AFTER quote_id");
            $wpdb->query("ALTER TABLE {$table} ADD INDEX idx_company (company_id)");
        }

        // Expand status ENUM to include 'interest' and 'pending'
        $wpdb->query(
            "ALTER TABLE {$table} MODIFY COLUMN status
            ENUM('confirmed','cancelled','waitlist','completed','withdrawn','interest','pending')
            DEFAULT 'confirmed'"
        );

        // Add completion_tasks JSON column
        $hasCompletionTasks = $wpdb->get_var("SHOW COLUMNS FROM {$table} LIKE 'completion_tasks'");
        if (!$hasCompletionTasks) {
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN completion_tasks JSON NULL AFTER notes");
        }

        // Add enrollment_data JSON column (stores extra fields from field groups)
        $hasEnrollmentData = $wpdb->get_var("SHOW COLUMNS FROM {$table} LIKE 'enrollment_data'");
        if (!$hasEnrollmentData) {
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN enrollment_data JSON NULL AFTER completion_tasks");
        }

        // Add composite index (user_id, status) for findByUser queries
        $hasUserStatusIdx = $wpdb->get_results("SHOW INDEX FROM {$table} WHERE Key_name = 'idx_user_status'");
        if (empty($hasUserStatusIdx)) {
            $wpdb->query("ALTER TABLE {$table} ADD INDEX idx_user_status (user_id, status)");
        }

        // Add composite index (user_id, edition_id) for findByUserAndEdition queries
        $hasUserEditionIdx = $wpdb->get_results("SHOW INDEX FROM {$table} WHERE Key_name = 'idx_user_edition'");
        if (empty($hasUserEditionIdx)) {
            $wpdb->query("ALTER TABLE {$table} ADD INDEX idx_user_edition (user_id, edition_id)");
        }

        // Make user_id nullable for anonymous interest registrations
        $wpdb->query("ALTER TABLE {$table} MODIFY COLUMN user_id BIGINT UNSIGNED NULL");

        // Migrate existing flat enrollment_data to stage-keyed format
        $rows = $wpdb->get_results(
            "SELECT id, enrollment_data FROM {$table} WHERE enrollment_data IS NOT NULL AND enrollment_data != '' AND enrollment_data != 'null'"
        );
        foreach ($rows as $row) {
            $decoded = json_decode($row->enrollment_data, true);
            if (!is_array($decoded) || empty($decoded)) {
                continue;
            }
            // Skip if already stage-keyed (first key is a known stage)
            $firstKey = array_key_first($decoded);
            if (in_array($firstKey, ['interest', 'enrollment_personal', 'enrollment_billing', 'intake', 'evaluation'], true)) {
                continue;
            }
            // Wrap as enrollment_personal
            $wpdb->update(
                $table,
                ['enrollment_data' => wp_json_encode(['enrollment_personal' => $decoded])],
                ['id' => (int) $row->id]
            );
        }
    }
}
