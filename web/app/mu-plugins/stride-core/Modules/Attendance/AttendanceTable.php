<?php

declare(strict_types=1);

namespace Stride\Modules\Attendance;

/**
 * Attendance table for session attendance tracking.
 */
final class AttendanceTable
{
    public const TABLE_NAME = 'vad_attendance';

    /**
     * Versioned schema upgrades for installs whose table predates a change.
     * Bump when ALTERing the table; add the matching step in migrate().
     *
     * v2 (perf audit 4B.4): DROP idx_session_user (session_id, user_id) — it is
     *     column-identical to unique_session_user (session_id, user_id), so the
     *     UNIQUE key already serves every (session_id[, user_id]) lookup. The
     *     duplicate is pure write-amplification at scale.
     */
    public const SCHEMA_VERSION = 2;

    private const SCHEMA_VERSION_OPTION = 'stride_attendance_schema_version';

    public static function getTableName(): string
    {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_NAME;
    }

    /**
     * Build the table at the LATEST schema and stamp the version. Fresh installs
     * never carry idx_session_user (dropped in v2) — the UNIQUE key covers those
     * lookups — and never replay migrate() (pattern: RegistrationTable/AuditTable).
     */
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
            INDEX idx_user_status (user_id, status),
            INDEX idx_edition (edition_id),
            UNIQUE KEY unique_session_user (session_id, user_id)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        update_option(self::SCHEMA_VERSION_OPTION, self::SCHEMA_VERSION);
    }

    /**
     * Idempotent, version-gated schema upgrade for pre-existing installs.
     *
     * v2: DROP the redundant idx_session_user. Guarded by a SHOW INDEX existence
     *     check so a re-run (or a fresh v2 create() that never had it) is a clean
     *     no-op rather than a "can't DROP; check that it exists" error.
     */
    public static function migrate(): void
    {
        if ((int) get_option(self::SCHEMA_VERSION_OPTION, 1) >= self::SCHEMA_VERSION) {
            return;
        }

        if (!self::exists()) {
            // No table yet — create() builds the latest schema and stamps the
            // version itself; nothing to upgrade.
            return;
        }

        global $wpdb;
        $table = self::getTableName();

        if (self::indexExists('idx_session_user')) {
            $dropped = $wpdb->query("ALTER TABLE {$table} DROP INDEX idx_session_user");

            if ($dropped === false) {
                ntdst_log('attendance')->error('vad_attendance schema v2 migration failed', [
                    'step' => 'drop_redundant_idx_session_user',
                    'error' => $wpdb->last_error,
                ]);

                // Don't stamp the version; the step is idempotent so a later
                // request retries the guarded DROP.
                return;
            }
        }

        update_option(self::SCHEMA_VERSION_OPTION, self::SCHEMA_VERSION);
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

    public static function exists(): bool
    {
        global $wpdb;

        $table = self::getTableName();

        return $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) === $table;
    }
}
