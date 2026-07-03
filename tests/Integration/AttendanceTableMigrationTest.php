<?php

declare(strict_types=1);

namespace Stride\Tests\Integration;

use IntegrationTestCase;
use Stride\Modules\Attendance\AttendanceTable;

/**
 * Perf audit 4B.4 — the vad_attendance table carried idx_session_user
 * (session_id, user_id) that is column-identical to the UNIQUE key
 * unique_session_user (session_id, user_id). The duplicate is pure
 * write-amplification; the UNIQUE key already serves every such lookup.
 *
 * migrate() must DROP idx_session_user idempotently while PRESERVING
 * unique_session_user (and the other indexes), and a fresh create() must never
 * build idx_session_user in the first place.
 *
 * Run: ddev exec "vendor/bin/phpunit -c phpunit-integration.xml.dist --filter AttendanceTableMigration"
 */
final class AttendanceTableMigrationTest extends IntegrationTestCase
{
    private const SCHEMA_OPTION = 'stride_attendance_schema_version';

    private function table(): string
    {
        return AttendanceTable::getTableName();
    }

    private function indexExists(string $index): bool
    {
        global $wpdb;

        return (bool) $wpdb->get_var($wpdb->prepare(
            'SELECT 1 FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s',
            $this->table(),
            $index,
        ));
    }

    /** @test */
    public function migrate_drops_the_redundant_index_but_keeps_the_unique_key(): void
    {
        global $wpdb;

        // Simulate a legacy install: re-add idx_session_user + set version to v1.
        if (!AttendanceTable::exists()) {
            AttendanceTable::create();
        }
        if (!$this->indexExists('idx_session_user')) {
            $wpdb->query("ALTER TABLE {$this->table()} ADD INDEX idx_session_user (session_id, user_id)");
        }
        update_option(self::SCHEMA_OPTION, 1);

        $this->assertTrue($this->indexExists('idx_session_user'), 'precondition: legacy index present');

        AttendanceTable::migrate();

        $this->assertFalse($this->indexExists('idx_session_user'), '4B.4: migrate() must DROP idx_session_user');
        $this->assertTrue($this->indexExists('unique_session_user'), '4B.4: the UNIQUE key must survive');
        $this->assertTrue($this->indexExists('idx_user_status'), 'other indexes must survive');
        $this->assertTrue($this->indexExists('idx_edition'), 'other indexes must survive');
        $this->assertSame(AttendanceTable::SCHEMA_VERSION, (int) get_option(self::SCHEMA_OPTION), 'version stamped');
    }

    /** @test */
    public function migrate_is_idempotent_when_the_index_is_already_gone(): void
    {
        global $wpdb;

        if (!AttendanceTable::exists()) {
            AttendanceTable::create();
        }
        // Ensure the redundant index is absent, then force a re-run from v1.
        if ($this->indexExists('idx_session_user')) {
            $wpdb->query("ALTER TABLE {$this->table()} DROP INDEX idx_session_user");
        }
        update_option(self::SCHEMA_OPTION, 1);

        AttendanceTable::migrate();

        $this->assertSame('', $wpdb->last_error, '4B.4: guarded DROP on an absent index must not error');
        $this->assertFalse($this->indexExists('idx_session_user'));
        $this->assertTrue($this->indexExists('unique_session_user'));
        $this->assertSame(AttendanceTable::SCHEMA_VERSION, (int) get_option(self::SCHEMA_OPTION));
    }
}
