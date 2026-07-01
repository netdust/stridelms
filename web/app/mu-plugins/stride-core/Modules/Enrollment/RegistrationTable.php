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

    /**
     * Versioned schema upgrades for installs whose table predates a change.
     * Bump when ALTERing the table; add the matching step in migrate().
     *
     * v2: enrollment_path ENUM gains 'partner' (audit M-4).
     * v3: reminder_state JSON column added (Phase 2 Task 2.1 — reminder
     *     idempotency ledger, consumed by later reminder-send tasks).
     */
    public const SCHEMA_VERSION = 3;

    private const SCHEMA_VERSION_OPTION = 'stride_registrations_schema_version';

    /**
     * Set after a failed run (panel SF-2 / drift Important-1 — same mechanism
     * as CompletionProofStorage::RETRY_TRANSIENT and AuditTable CR-F3): while
     * it lives, migrate() bails before issuing DDL so a persistently failing
     * ALTER does not re-run + log-spam on every request. The version option
     * stays unstamped, so the retry semantics are unchanged once it lapses.
     */
    private const RETRY_TRANSIENT = 'stride_registrations_migration_backoff';

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
            enrollment_path ENUM('individual','colleague','trajectory','partner') DEFAULT 'individual',
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
            reminder_state JSON NULL,
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

        // Fresh tables are created at the latest schema — stamp the version so
        // migrate() doesn't re-run historical steps.
        update_option(self::SCHEMA_VERSION_OPTION, self::SCHEMA_VERSION);
    }

    /**
     * Versioned, idempotent schema upgrades for pre-existing installs.
     *
     * Option-gated like stride_roles_version in stride-core.php; each step is
     * additionally safe to re-run (additive DDL + constant-valued backfill).
     */
    public static function migrate(): void
    {
        if ((int) get_option(self::SCHEMA_VERSION_OPTION, 1) >= self::SCHEMA_VERSION) {
            return;
        }

        if (get_transient(self::RETRY_TRANSIENT) !== false) {
            // A recent run failed — back off until the transient lapses.
            return;
        }

        if (!self::exists()) {
            // No table yet — create() will build the latest schema and stamp the version.
            return;
        }

        global $wpdb;

        $table = self::getTableName();

        // v2 (audit M-4 / threat-model M7): add 'partner' to enrollment_path.
        // Purely additive — existing values are untouched. Pre-v2, inserts using
        // RegistrationRepository::PATH_PARTNER were coerced to '' under
        // non-strict SQL mode; backfill those rows when they are company-scoped.
        // Rollback posture: ENUM values can't be dropped while 'partner' rows
        // exist — rollback is "leave the value in place", which is safe.
        $altered = $wpdb->query(
            "ALTER TABLE {$table}
            MODIFY enrollment_path ENUM('individual','colleague','trajectory','partner') DEFAULT 'individual'",
        );

        if ($altered === false) {
            ntdst_log('enrollment')->error('registrations schema v2 migration failed', [
                'step' => 'alter_enrollment_path_enum',
                'error' => $wpdb->last_error,
            ]);

            // Don't stamp the version: retried once the backoff lapses (steps are idempotent).
            set_transient(self::RETRY_TRANSIENT, 1, 5 * MINUTE_IN_SECONDS);

            return;
        }

        // Note: 0 affected rows is int 0, not false — only false signals a DB error.
        $backfilled = $wpdb->query(
            "UPDATE {$table}
            SET enrollment_path = 'partner'
            WHERE enrollment_path = '' AND company_id IS NOT NULL",
        );

        if ($backfilled === false) {
            ntdst_log('enrollment')->error('registrations schema v2 migration failed', [
                'step' => 'backfill_partner_path',
                'error' => $wpdb->last_error,
            ]);

            set_transient(self::RETRY_TRANSIENT, 1, 5 * MINUTE_IN_SECONDS);

            return;
        }

        // v3 (Phase 2 Task 2.1): add reminder_state JSON column, used by later
        // reminder-send tasks as a per-registration idempotency ledger.
        // Guarded via SHOW COLUMNS (same style as exists()'s SHOW TABLES
        // probe) — unlike the v2 MODIFY above, ADD COLUMN errors if the
        // column already exists, and the >= guard at the top of this method
        // makes a v2 DB re-enter migrate() once SCHEMA_VERSION is bumped, so
        // this step must tolerate running again on an already-v3 table.
        $hasReminderStateColumn = $wpdb->get_var("SHOW COLUMNS FROM {$table} LIKE 'reminder_state'");

        if ($hasReminderStateColumn === null) {
            $reminderStateAltered = $wpdb->query("ALTER TABLE {$table} ADD COLUMN reminder_state JSON NULL");

            if ($reminderStateAltered === false) {
                ntdst_log('enrollment')->error('registrations schema v3 migration failed', [
                    'step' => 'add_reminder_state_column',
                    'error' => $wpdb->last_error,
                ]);

                // Don't stamp the version: retried once the backoff lapses (step is idempotent).
                set_transient(self::RETRY_TRANSIENT, 1, 5 * MINUTE_IN_SECONDS);

                return;
            }
        }

        update_option(self::SCHEMA_VERSION_OPTION, self::SCHEMA_VERSION);
    }

    public static function exists(): bool
    {
        global $wpdb;

        $table = self::getTableName();

        return $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) === $table;
    }
}
