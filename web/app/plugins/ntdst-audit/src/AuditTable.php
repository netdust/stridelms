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
     *     + indexes idx_subject_user / idx_action (Stride audit H-4 / M8).
     */
    public const SCHEMA_VERSION = 2;

    private const SCHEMA_VERSION_OPTION = 'ntdst_audit_schema_version';

    public static function getTableName(): string
    {
        global $wpdb;
        $tableName = apply_filters('ntdst/audit/table_name', self::TABLE_NAME);
        return $wpdb->prefix . $tableName;
    }

    public static function create(): void
    {
        global $wpdb;

        $table = self::getTableName();
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            entity_type VARCHAR(50) NOT NULL,
            entity_id BIGINT UNSIGNED NOT NULL,
            action VARCHAR(50) NOT NULL,
            actor_id BIGINT UNSIGNED NULL,
            actor_type VARCHAR(20) NOT NULL DEFAULT 'user',
            context JSON,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_entity (entity_type, entity_id),
            INDEX idx_actor (actor_id),
            INDEX idx_created (created_at)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Versioned, idempotent schema upgrades for pre-existing installs.
     *
     * Pattern per Stride's RegistrationTable::migrate(): option-gated,
     * result-checked, each step additionally safe to re-run (IF NOT EXISTS
     * DDL). Called from AuditService::init() on every request — the option
     * read is the only cost once stamped.
     *
     * v2 (Stride audit H-4 / threat-model M8):
     *  - STORED generated column subject_user_id derived from context.user_id.
     *    DDL only — no UPDATE touches row data. MariaDB rebuilds the table on
     *    disk for a STORED column (measured ~1s at 64k rows on MariaDB
     *    10.11); deploy off-peak. Rollback = DROP the column + indexes.
     *  - The expression guards every context shape: absent key, NULL context,
     *    JSON null and non-numeric values all yield NULL. A bare
     *    CAST(JSON_UNQUOTE(JSON_EXTRACT(...))) aborts the whole ALTER under
     *    strict mode when any row holds JSON null ('null' string → CAST error).
     *  - idx_subject_user (subject_user_id, created_at) serves the
     *    subject-targeted notification queries; idx_action serves
     *    action-filtered scans (e.g. session.note_updated).
     */
    public static function migrate(): void
    {
        if ((int) get_option(self::SCHEMA_VERSION_OPTION, 1) >= self::SCHEMA_VERSION) {
            return;
        }

        if (!self::exists()) {
            // No table yet — create() builds the base schema first; the next
            // request applies the version steps.
            return;
        }

        global $wpdb;

        $table = self::getTableName();

        $altered = $wpdb->query(
            "ALTER TABLE {$table}
            ADD COLUMN IF NOT EXISTS subject_user_id BIGINT UNSIGNED
                GENERATED ALWAYS AS (
                    CASE WHEN JSON_VALUE(context, '$.user_id') RLIKE '^[0-9]+$'
                         THEN CAST(JSON_VALUE(context, '$.user_id') AS UNSIGNED)
                    END
                ) STORED,
            ADD INDEX IF NOT EXISTS idx_subject_user (subject_user_id, created_at),
            ADD INDEX IF NOT EXISTS idx_action (action)"
        );

        if ($altered === false) {
            ntdst_log('audit')->error('audit_log schema v2 migration failed', [
                'step' => 'add_subject_user_id_generated_column',
                'error' => $wpdb->last_error,
            ]);

            // Don't stamp the version: the next request retries (steps are idempotent).
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
