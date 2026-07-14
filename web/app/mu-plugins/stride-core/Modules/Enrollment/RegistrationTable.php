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

        // NO "IF NOT EXISTS": dbDelta parses the table name with a regex that
        // captures "IF" from that form, so it never matched the existing table
        // and never diffed columns — create() on a pre-existing older table
        // silently no-opped and then stamped the LATEST schema version below,
        // permanently stranding missing columns (v5 lead_name/lead_email are
        // read by every grid SELECT). Plain CREATE TABLE is the canonical
        // dbDelta form: it diffs an existing table and ADDs missing columns.
        $sql = "CREATE TABLE {$table} (
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

        // The version option doubles as the STEP CURSOR: each step stamps its
        // own version on completion, so a paused v5 backfill (batch cap /
        // backoff — a DESIGNED re-entry path) resumes at >= 4 and jumps
        // straight to its batch loop instead of re-paying every earlier
        // probe/ALTER on each pass. The per-step probes below stay as
        // belt-and-braces for pre-cursor installs.
        $from = (int) get_option(self::SCHEMA_VERSION_OPTION, 1);

        if ($from < 2) {
            // v2 (audit M-4 / threat-model M7): add 'partner' to enrollment_path.
            // Purely additive — existing values are untouched. Pre-v2, inserts using
            // RegistrationRepository::PATH_PARTNER were coerced to '' under
            // non-strict SQL mode; backfill those rows when they are company-scoped.
            // Rollback posture: ENUM values can't be dropped while 'partner' rows
            // exist — rollback is "leave the value in place", which is safe.
            //
            // MODIFY guarded via SHOW COLUMNS: idempotent in RESULT but not in
            // COST (potential metadata lock / table copy per pass on a live
            // table). The partner backfill UPDATE stays unconditional to cover
            // the MODIFY-succeeded/backfill-failed retry edge.
            $pathColumn = $wpdb->get_row("SHOW COLUMNS FROM {$table} LIKE 'enrollment_path'");
            $enumHasPartner = is_object($pathColumn)
                && str_contains((string) ($pathColumn->Type ?? ''), "'partner'");

            if (!$enumHasPartner) {
                $altered = $wpdb->query(
                    "ALTER TABLE {$table}
                    MODIFY enrollment_path ENUM('individual','colleague','trajectory','partner') DEFAULT 'individual'",
                );

                if ($altered === false) {
                    self::failStep('v2', 'alter_enrollment_path_enum');
                    return;
                }
            }

            // Note: 0 affected rows is int 0, not false — only false signals a DB error.
            $backfilled = $wpdb->query(
                "UPDATE {$table}
                SET enrollment_path = 'partner'
                WHERE enrollment_path = '' AND company_id IS NOT NULL",
            );

            if ($backfilled === false) {
                self::failStep('v2', 'backfill_partner_path');
                return;
            }

            update_option(self::SCHEMA_VERSION_OPTION, 2);
        }

        if ($from < 3) {
            // v3 (perf audit #8): add idx_registered_at — the default grid sort has
            // no index, so every filtered page is a top-N filesort. Purely additive.
            // Idempotent via the shared ensureRegisteredAtIndex() SHOW INDEX guard.
            if (!self::ensureRegisteredAtIndex()) {
                self::failStep('v3', 'add_registered_at_index');
                return;
            }

            update_option(self::SCHEMA_VERSION_OPTION, 3);
        }

        if ($from < 4) {
            // v4 (Phase 2 Task 2.1): add reminder_state JSON column (reminder
            // idempotency ledger). Guarded via SHOW COLUMNS — ADD COLUMN errors
            // when the column already exists (fresh create() already added it).
            $hasReminderStateColumn = $wpdb->get_var("SHOW COLUMNS FROM {$table} LIKE 'reminder_state'");

            if ($hasReminderStateColumn === null) {
                $reminderStateAltered = $wpdb->query("ALTER TABLE {$table} ADD COLUMN reminder_state JSON NULL");

                if ($reminderStateAltered === false) {
                    self::failStep('v4', 'add_reminder_state_column');
                    return;
                }
            }

            update_option(self::SCHEMA_VERSION_OPTION, 4);
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
                self::failStep('v5', 'add_lead_identity_columns');
                return;
            }
        }

        // Backfill lead identity from enrollment_data for existing anonymous
        // rows (user_id NULL/0). Decoded in PHP via the SAME extractor the
        // write path uses (RegistrationRepository::extractLeadIdentity — no
        // second JSON-path definition). Batched on `lead_name IS NULL`; rows
        // with NO extractable identity are stamped '' (checked, nothing) so a
        // batch can never re-scan them — '' never matches a %LIKE% search.
        //
        // Stamping invariant (INV-4 / same posture as every step above): the
        // version option is ONLY stamped when the backfill DRAINED. A failed
        // SELECT/UPDATE or a tripped runaway cap logs, sets the retry backoff,
        // and returns unstamped — migrate() re-enters after the backoff and
        // continues where it left off (stamped rows never re-match IS NULL).
        // Stamping anyway would strand the remaining leads unfindable forever.
        $batches = 0;
        do {
            $backfillRows = $wpdb->get_results(
                "SELECT id, enrollment_data FROM {$table}
                 WHERE (user_id IS NULL OR user_id = 0)
                   AND enrollment_data IS NOT NULL
                   AND lead_name IS NULL
                 LIMIT 500",
            );

            // Real wpdb returns [] on a FAILED SELECT (query() flushes
            // last_result to [] before erroring), so an empty array is
            // indistinguishable from a drained corpus — the error signal is
            // last_error, which query() also flushes per call.
            if (!is_array($backfillRows) || $wpdb->last_error !== '') {
                self::failStep('v5', 'backfill_lead_identity_select');
                return;
            }

            foreach ($backfillRows as $row) {
                $decoded = json_decode((string) $row->enrollment_data, true);
                $identity = is_array($decoded)
                    ? RegistrationRepository::extractLeadIdentity($decoded)
                    : ['name' => '', 'email' => ''];
                $updated = $wpdb->update($table, [
                    'lead_name' => $identity['name'],
                    'lead_email' => $identity['email'],
                ], ['id' => (int) $row->id]);

                if ($updated === false) {
                    self::failStep('v5', 'backfill_lead_identity_update', ['registration_id' => (int) $row->id]);
                    return;
                }
            }

            if (count($backfillRows) === 500 && ++$batches >= 40) {
                // Runaway guard tripped (>20k lead rows in one request): stop
                // THIS run but do NOT stamp — the next run (after the backoff)
                // continues with the still-NULL remainder.
                ntdst_log('enrollment')->warning('registrations schema v5 backfill paused at batch cap; will continue next run', [
                    'step' => 'backfill_lead_identity_cap',
                    'batches' => $batches,
                ]);
                set_transient(self::RETRY_TRANSIENT, 1, 5 * MINUTE_IN_SECONDS);

                return;
            }
        } while (count($backfillRows) === 500);

        update_option(self::SCHEMA_VERSION_OPTION, self::SCHEMA_VERSION);
    }

    /**
     * The ONE failure exit for a migrate() step (INV-4): log the DB error on
     * the enrollment channel and arm the retry backoff. The caller returns
     * WITHOUT stamping, so the step re-runs once the backoff lapses. Keeping
     * the message shape ("registrations schema vN migration failed") in one
     * place means ops greps/alerts keyed on it cannot miss a future step.
     *
     * @param string               $version 'v2'..'vN' — the failing schema step.
     * @param string               $step    Machine-readable step slug for the log context.
     * @param array<string,mixed>  $context Extra log context (merged after step/error).
     */
    private static function failStep(string $version, string $step, array $context = []): void
    {
        global $wpdb;

        ntdst_log('enrollment')->error("registrations schema {$version} migration failed", array_merge([
            'step' => $step,
            'error' => $wpdb->last_error,
        ], $context));

        set_transient(self::RETRY_TRANSIENT, 1, 5 * MINUTE_IN_SECONDS);
    }

    public static function exists(): bool
    {
        global $wpdb;

        $table = self::getTableName();

        return $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) === $table;
    }
}
