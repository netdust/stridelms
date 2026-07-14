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
     * v4: reminder_state JSON column added (Phase 2 Task 2.1 — reminder
     *     idempotency ledger, consumed by later reminder-send tasks). Rebased
     *     from v3 to v4 when feat/admin-url-filter-state merged first and took
     *     v3 for the index; the two migrations now sequence cleanly (v2 install
     *     upgrades v3-index then v4-column) instead of colliding on one version.
     * v5: lead_name / lead_email columns (F-G3 — anonymous-lead search).
     *     Anonymous interest/waitlist submitters live only in enrollment_data
     *     JSON, which the grid search deliberately never LIKEs (M5), so leads
     *     were unfindable by the name/email on their own submission. The
     *     denormalized columns are stamped at write time
     *     (RegistrationRepository::extractLeadIdentity) and backfilled from
     *     the JSON here for existing lead rows.
     */
    public const SCHEMA_VERSION = 5;

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
            lead_name VARCHAR(191) NULL COMMENT 'Anonymous-lead searchability (v5) — name from the interest/waitlist submission',
            lead_email VARCHAR(191) NULL COMMENT 'Anonymous-lead searchability (v5)',
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
        // fresh tables get the index from create() directly and then may still
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

        // v4 (Phase 2 Task 2.1): add reminder_state JSON column, used by later
        // reminder-send tasks as a per-registration idempotency ledger. Rebased
        // from v3 to v4 (admin-url-filter-state took v3 for the index). Guarded
        // via SHOW COLUMNS (same style as exists()'s SHOW TABLES probe) — unlike
        // the v2 MODIFY above, ADD COLUMN errors if the column already exists, and
        // the >= guard at the top of this method makes a v3 DB re-enter migrate()
        // once SCHEMA_VERSION is bumped, so this step must tolerate running again
        // on an already-v4 table (fresh create() already added the column).
        $hasReminderStateColumn = $wpdb->get_var("SHOW COLUMNS FROM {$table} LIKE 'reminder_state'");

        if ($hasReminderStateColumn === null) {
            $reminderStateAltered = $wpdb->query("ALTER TABLE {$table} ADD COLUMN reminder_state JSON NULL");

            if ($reminderStateAltered === false) {
                ntdst_log('enrollment')->error('registrations schema v4 migration failed', [
                    'step' => 'add_reminder_state_column',
                    'error' => $wpdb->last_error,
                ]);

                // Don't stamp the version: retried once the backoff lapses (step is idempotent).
                set_transient(self::RETRY_TRANSIENT, 1, 5 * MINUTE_IN_SECONDS);

                return;
            }
        }

        // v5 (F-G3): lead_name/lead_email columns + JSON backfill for existing
        // anonymous-lead rows. Guarded via SHOW COLUMNS (same posture as v4).
        $hasLeadNameColumn = $wpdb->get_var("SHOW COLUMNS FROM {$table} LIKE 'lead_name'");

        if ($hasLeadNameColumn === null) {
            $leadAltered = $wpdb->query(
                "ALTER TABLE {$table}
                 ADD COLUMN lead_name VARCHAR(191) NULL,
                 ADD COLUMN lead_email VARCHAR(191) NULL",
            );

            if ($leadAltered === false) {
                ntdst_log('enrollment')->error('registrations schema v5 migration failed', [
                    'step' => 'add_lead_identity_columns',
                    'error' => $wpdb->last_error,
                ]);

                set_transient(self::RETRY_TRANSIENT, 1, 5 * MINUTE_IN_SECONDS);

                return;
            }
        }

        // Backfill lead identity from enrollment_data for existing anonymous
        // rows (user_id NULL/0). Decoded in PHP via the SAME extractor the
        // write path uses (RegistrationRepository::extractLeadIdentity — no
        // second JSON-path definition). Batched on `lead_name IS NULL`; rows
        // with NO extractable identity are stamped '' (checked, nothing) so a
        // batch can never re-scan them — '' never matches a %LIKE% search.
        // The batch cap is a runaway guard only; leads are a small subset.
        $batches = 0;
        do {
            $backfillRows = $wpdb->get_results(
                "SELECT id, enrollment_data FROM {$table}
                 WHERE (user_id IS NULL OR user_id = 0)
                   AND enrollment_data IS NOT NULL
                   AND lead_name IS NULL
                 LIMIT 500",
            ) ?: [];
            foreach ($backfillRows as $row) {
                $decoded = json_decode((string) $row->enrollment_data, true);
                $identity = is_array($decoded)
                    ? RegistrationRepository::extractLeadIdentity($decoded)
                    : ['name' => '', 'email' => ''];
                $wpdb->update($table, [
                    'lead_name' => $identity['name'],
                    'lead_email' => $identity['email'],
                ], ['id' => (int) $row->id]);
            }
        } while (count($backfillRows) === 500 && ++$batches < 40);

        update_option(self::SCHEMA_VERSION_OPTION, self::SCHEMA_VERSION);
    }

    public static function exists(): bool
    {
        global $wpdb;

        $table = self::getTableName();

        return $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) === $table;
    }
}
