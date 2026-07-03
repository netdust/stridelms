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
     * v3: idx_registered_at index (perf audit #8 — registered_at is the default
     *     grid sort / export secondary sort / grouped child-row window order;
     *     without it every grid page is a filesort over the filtered corpus).
     *
     * ⚠ MERGE COORDINATION: the unmerged branch feat/gate-deadlines-reminders
     * ALSO claims SCHEMA_VERSION=3 (for a reminder_state column). Whichever
     * branch merges SECOND must rebase its bump to v4 (or fold both into one
     * v3 step) so the two migrations don't collide on the same version number —
     * a v3 stamp from one branch would skip the other's v3 migration.
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
            INDEX idx_user (user_id),
            INDEX idx_edition (edition_id),
            INDEX idx_trajectory (trajectory_id),
            INDEX idx_parent (parent_registration_id),
            INDEX idx_status (status),
            INDEX idx_edition_status (edition_id, status),
            INDEX idx_trajectory_status (trajectory_id, status),
            INDEX idx_company (company_id),
            INDEX idx_user_status (user_id, status),
            INDEX idx_user_edition (user_id, edition_id),
            INDEX idx_registered_at (registered_at)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        // dbDelta cannot reliably ADD a newly-declared index to an already-existing
        // table (perf audit #8 / review-gate 3B.1): on any install whose table
        // predates v3 and then re-runs create() (re-activation, tests), the
        // idx_registered_at line in the DDL above is silently NOT applied. Converge
        // the fresh-create path onto the SAME guarded, idempotent index-ensuring
        // routine migrate() uses, so both paths land on identical index shape
        // regardless of dbDelta's index quirks — this is the fresh-vs-migrated
        // parity the schema-version contract depends on.
        self::ensureRegisteredAtIndex();

        // Fresh tables are created at the latest schema — stamp the version so
        // migrate() doesn't re-run historical steps.
        update_option(self::SCHEMA_VERSION_OPTION, self::SCHEMA_VERSION);
    }

    /**
     * Idempotently ensure idx_registered_at (registered_at) exists on the table.
     *
     * Shared convergence point for BOTH the fresh-create path (create()) and the
     * v2→v3 upgrade path (migrate()) — dbDelta is unreliable for adding an index
     * to a pre-existing table, and a bare ADD INDEX throws "Duplicate key name"
     * when the index is already present, so the SHOW INDEX guard makes this safe
     * to call unconditionally on every path.
     *
     * @return bool true when the index is present after the call (already there or
     *              just added), false when the ADD INDEX failed (DB error logged by
     *              the caller's context — migrate() sets the retry backoff).
     */
    private static function ensureRegisteredAtIndex(): bool
    {
        global $wpdb;

        $table = self::getTableName();

        $indexExists = $wpdb->get_var(
            "SHOW INDEX FROM {$table} WHERE Key_name = 'idx_registered_at'",
        );

        if ($indexExists !== null) {
            return true;
        }

        $indexed = $wpdb->query(
            "ALTER TABLE {$table} ADD INDEX idx_registered_at (registered_at)",
        );

        return $indexed !== false;
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

        // v3 (perf audit #8): add idx_registered_at — the default grid sort has
        // no index, so every filtered page is a top-N filesort. Purely additive.
        // Idempotent via the shared ensureRegisteredAtIndex() SHOW INDEX guard:
        // fresh v3 tables get the index from create() directly and then may still
        // enter migrate() (option unset on an old install path), and a lapsed-backoff
        // retry re-enters here — the guard skips the ADD when present, avoiding the
        // "Duplicate key name" error a bare ADD INDEX would throw.
        if (!self::ensureRegisteredAtIndex()) {
            ntdst_log('enrollment')->error('registrations schema v3 migration failed', [
                'step' => 'add_registered_at_index',
                'error' => $wpdb->last_error,
            ]);

            // Don't stamp the version: retried once the backoff lapses (step is idempotent).
            set_transient(self::RETRY_TRANSIENT, 1, 5 * MINUTE_IN_SECONDS);

            return;
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
