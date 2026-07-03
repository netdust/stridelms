<?php

declare(strict_types=1);

namespace NTDST\Audit;

final class AuditTable
{
    public const TABLE_NAME = 'audit_log';

    /**
     * Versioned schema upgrades for installs whose table predates a change.
     * Bump when ALTERing the table; add the matching step in migrate().
     *
     * v2: STORED generated column subject_user_id (from context.user_id)
     *     + indexes idx_subject_user / idx_action, for indexable
     *     subject-targeted queries (notification feeds).
     * v3: composite index idx_created_id (created_at, id) for the activity-feed
     *     keyset order `ORDER BY created_at DESC, id DESC LIMIT N`. The single
     *     idx_created (created_at) cannot satisfy the id tiebreak, so the top-N
     *     read was a filesort over the whole table (perf audit 4B.1).
     */
    public const SCHEMA_VERSION = 3;

    private const SCHEMA_VERSION_OPTION = 'ntdst_audit_schema_version';

    /**
     * Set after a failed migration run: while it lives, migrate() bails
     * before any DDL so a persistently failing ALTER does not retry (and
     * log-spam) on every request. The version option stays unstamped, so
     * retry semantics are unchanged once it lapses.
     */
    private const RETRY_TRANSIENT = 'ntdst_audit_migration_backoff';

    public static function getTableName(): string
    {
        global $wpdb;
        $tableName = apply_filters('ntdst/audit/table_name', self::TABLE_NAME);
        return $wpdb->prefix . $tableName;
    }

    /**
     * Build the table at the LATEST schema and stamp the version — fresh
     * installs get the v2 column + indexes in the CREATE itself and never
     * replay historical migrate() steps (pattern: RegistrationTable).
     *
     * dbDelta executes the statement verbatim for a not-yet-existing table
     * (probed on a scratch DB: the generated column and all indexes survive
     * intact), so no post-CREATE ALTER is needed.
     */
    public static function create(): void
    {
        global $wpdb;

        $table = self::getTableName();
        $charset = $wpdb->get_charset_collate();
        $subjectExpression = self::subjectUserIdExpression();

        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            entity_type VARCHAR(50) NOT NULL,
            entity_id BIGINT UNSIGNED NOT NULL,
            action VARCHAR(50) NOT NULL,
            actor_id BIGINT UNSIGNED NULL,
            actor_type VARCHAR(20) NOT NULL DEFAULT 'user',
            context JSON,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            subject_user_id BIGINT UNSIGNED
                GENERATED ALWAYS AS ({$subjectExpression}) STORED,
            INDEX idx_entity (entity_type, entity_id),
            INDEX idx_actor (actor_id),
            INDEX idx_created (created_at),
            INDEX idx_created_id (created_at, id),
            INDEX idx_subject_user (subject_user_id, created_at),
            INDEX idx_action (action)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        // Fresh tables are created at the latest schema — stamp the version
        // so migrate() doesn't re-run historical steps.
        update_option(self::SCHEMA_VERSION_OPTION, self::SCHEMA_VERSION);
    }

    /**
     * Versioned, idempotent schema upgrades for pre-existing installs.
     *
     * Option-gated and result-checked. Idempotency comes from per-clause
     * information_schema existence checks — NOT from "ADD ... IF NOT EXISTS",
     * which is MariaDB-only DDL: MySQL 8 parse-errors on it, the version
     * never stamps, every request retries the broken ALTER, and the v1.1.0
     * queries reference a column that never materialises. Called from
     * AuditService::init() on every request — the option read is the only
     * cost once stamped.
     *
     * v2:
     *  - STORED generated column subject_user_id derived from context.user_id.
     *    DDL only — no UPDATE touches row data. MariaDB rebuilds the table on
     *    disk for a STORED column (measured ~1s at 64k rows on MariaDB
     *    10.11); deploy off-peak. Rollback = DROP the column + indexes.
     *  - The expression guards every context shape — absent key, NULL
     *    context, JSON null, booleans, non-numeric garbage and numbers wider
     *    than BIGINT UNSIGNED all yield NULL. See subjectUserIdExpression()
     *    for the per-guard rationale (an unguarded CAST aborts the ALTER
     *    under strict mode).
     *  - idx_subject_user (subject_user_id, created_at) serves the
     *    subject-targeted notification queries; idx_action serves
     *    action-filtered scans (e.g. session.note_updated).
     *
     * v3:
     *  - idx_created_id (created_at, id) serves the activity-feed keyset order
     *    `ORDER BY created_at DESC, id DESC LIMIT N`, which idx_created alone
     *    could not (the id tiebreak forced a top-N filesort). Purely additive.
     *
     * Failure backoff: a failed run sets a 5-minute transient; while it
     * lives, migrate() bails before any DDL so a persistently failing ALTER
     * does not retry + log-spam on every request.
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
            // No table yet — create() (called by AuditService::init() earlier
            // in this same request) builds the latest schema and stamps the
            // version itself; there is nothing to upgrade here.
            return;
        }

        global $wpdb;

        $table = self::getTableName();

        // v2 — each clause guarded by a portable existence check, so re-runs
        // are idempotent on MariaDB and MySQL alike.
        $clauses = [];

        if (!self::columnExists('subject_user_id')) {
            $clauses[] = 'ADD COLUMN subject_user_id BIGINT UNSIGNED
                GENERATED ALWAYS AS (' . self::subjectUserIdExpression() . ') STORED';
        }

        if (!self::indexExists('idx_subject_user')) {
            $clauses[] = 'ADD INDEX idx_subject_user (subject_user_id, created_at)';
        }

        if (!self::indexExists('idx_action')) {
            $clauses[] = 'ADD INDEX idx_action (action)';
        }

        // v3 — composite keyset index for the activity-feed ORDER BY. Purely
        // additive; the per-clause indexExists guard keeps it idempotent across
        // a straight v1→v3 jump and a v2→v3 upgrade alike.
        if (!self::indexExists('idx_created_id')) {
            $clauses[] = 'ADD INDEX idx_created_id (created_at, id)';
        }

        if ($clauses !== []) {
            $altered = $wpdb->query("ALTER TABLE {$table}\n            " . implode(",\n            ", $clauses));

            if ($altered === false) {
                ntdst_log('audit')->error('audit_log schema v2 migration failed', [
                    'step' => 'add_subject_user_id_generated_column',
                    'error' => $wpdb->last_error,
                ]);

                // Don't stamp the version; retry once the backoff lapses.
                set_transient(self::RETRY_TRANSIENT, 1, 5 * MINUTE_IN_SECONDS);

                return;
            }
        }

        update_option(self::SCHEMA_VERSION_OPTION, self::SCHEMA_VERSION);
    }

    private static function columnExists(string $column): bool
    {
        global $wpdb;

        return (bool) $wpdb->get_var($wpdb->prepare(
            'SELECT 1 FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
            self::getTableName(),
            $column,
        ));
    }

    private static function indexExists(string $index): bool
    {
        global $wpdb;

        return (bool) $wpdb->get_var($wpdb->prepare(
            'SELECT 1 FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s',
            self::getTableName(),
            $index,
        ));
    }

    /**
     * Generated-column expression deriving subject_user_id from
     * context.user_id. Single source for create() and migrate() — the two
     * must stay byte-identical or fresh installs and migrated installs drift.
     *
     * Guards (each probed live on a scratch table, strict and non-strict):
     *  - JSON_TYPE pre-check: only INTEGER and STRING JSON values qualify —
     *    JSON true/false (JSON_VALUE stringifies to '1'/'0', which would
     *    misattribute to user 1) and JSON null are excluded by type.
     *  - Bounded digit count {1,19}: a bare '^[0-9]+$' admits numeric strings
     *    longer than BIGINT UNSIGNED — under STRICT_TRANS_TABLES the CAST
     *    then aborts the whole ALTER (error 1292) and, post-migration, makes
     *    any INSERT carrying such a value fail, losing the audit row; under
     *    non-strict mode it saturates to 18446744073709551615. 19 digits is
     *    the longest length that can never overflow (max is 20 digits).
     *  - Absent key / NULL context: JSON_TYPE returns NULL → CASE yields NULL.
     */
    private static function subjectUserIdExpression(): string
    {
        return "CASE WHEN JSON_TYPE(JSON_EXTRACT(context, '$.user_id')) IN ('INTEGER','STRING')
                      AND JSON_VALUE(context, '$.user_id') RLIKE '^[0-9]{1,19}$'
                     THEN CAST(JSON_VALUE(context, '$.user_id') AS UNSIGNED)
                END";
    }

    public static function exists(): bool
    {
        global $wpdb;
        $table = self::getTableName();
        return $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) === $table;
    }
}
