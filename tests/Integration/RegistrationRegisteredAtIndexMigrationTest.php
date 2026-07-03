<?php

declare(strict_types=1);

namespace Stride\Tests\Integration;

use IntegrationTestCase;
use Stride\Modules\Enrollment\RegistrationTable;

/**
 * Integration tests for the registered_at index migration (perf audit #8 / schema v3).
 *
 * Contract (Tier B — additive index migration; verified by SHOW INDEX + idempotency,
 * not a bespoke unit test):
 *  - After migrate() upgrades a pre-v3 install, idx_registered_at exists on registered_at.
 *  - Fresh create() and a migrated table produce the SAME index (fresh-vs-migrated parity).
 *  - Running migrate() twice is a no-op (guarded second run + forced re-run both clean).
 *  - The schema-version option is stamped to SCHEMA_VERSION after the migration.
 *
 * Run: ddev exec "vendor/bin/phpunit -c phpunit-integration.xml.dist --filter RegistrationRegisteredAtIndexMigration"
 */
final class RegistrationRegisteredAtIndexMigrationTest extends IntegrationTestCase
{
    protected function tearDown(): void
    {
        // Restore the schema-version option in case a forced re-run test failed
        // between delete_option() and migrate() completing.
        update_option('stride_registrations_schema_version', RegistrationTable::SCHEMA_VERSION);
        delete_transient('stride_registrations_migration_backoff');

        parent::tearDown();
    }

    /** @test */
    public function schemaVersionIsThree(): void
    {
        $this->assertSame(
            3,
            RegistrationTable::SCHEMA_VERSION,
            'registered_at index ships at SCHEMA_VERSION 3',
        );
    }

    /** @test */
    public function migrateAddsRegisteredAtIndex(): void
    {
        // Simulate a pre-v3 install: table exists (create() already ran in bootstrap),
        // but drop the index and reset the version so migrate() runs the v3 step.
        $this->dropRegisteredAtIndexIfPresent();
        $this->assertFalse($this->hasRegisteredAtIndex(), 'Precondition: index dropped');

        $this->forceMigration();

        $this->assertTrue(
            $this->hasRegisteredAtIndex(),
            'migrate() must add idx_registered_at on a pre-v3 install',
        );
        $this->assertSame(
            RegistrationTable::SCHEMA_VERSION,
            (int) get_option('stride_registrations_schema_version'),
            'Schema version option must be stamped after migration',
        );
    }

    /** @test */
    public function freshCreateAndMigratedTableHaveTheSameIndex(): void
    {
        // create() (fresh install) — the index must be present directly.
        RegistrationTable::create();
        $this->assertTrue(
            $this->hasRegisteredAtIndex(),
            'Fresh create() must build idx_registered_at directly',
        );
        $freshColumns = $this->registeredAtIndexColumns();

        // Migrated path (drop + migrate) must land on the SAME index shape.
        $this->dropRegisteredAtIndexIfPresent();
        $this->forceMigration();
        $migratedColumns = $this->registeredAtIndexColumns();

        $this->assertSame(
            ['registered_at'],
            $freshColumns,
            'Fresh index covers exactly registered_at',
        );
        $this->assertSame(
            $freshColumns,
            $migratedColumns,
            'Fresh-install and migrated index must be identical (parity is the schema-version risk surface)',
        );
    }

    /** @test */
    public function migrationIsIdempotent(): void
    {
        global $wpdb;

        // Ensure the index is present and version stamped.
        RegistrationTable::create();
        $this->assertTrue($this->hasRegisteredAtIndex());

        // Guarded second run (version already stamped): must be a clean no-op.
        RegistrationTable::migrate();
        $this->assertSame('', $wpdb->last_error, 'Guarded second run must not error');

        // Forced re-run with the index ALREADY present: the SHOW INDEX guard must
        // skip the ADD INDEX, so no "Duplicate key name" error.
        $this->forceMigration();
        $this->assertSame('', $wpdb->last_error, 'Forced re-run with index present must not error');
        $this->assertTrue($this->hasRegisteredAtIndex(), 'Index still present after re-run');
        $this->assertSame(
            ['registered_at'],
            $this->registeredAtIndexColumns(),
            'Index unchanged after re-run',
        );
    }

    // === Helpers ===

    private function forceMigration(): void
    {
        delete_option('stride_registrations_schema_version');
        delete_transient('stride_registrations_migration_backoff');
        RegistrationTable::migrate();
    }

    private function hasRegisteredAtIndex(): bool
    {
        global $wpdb;

        $table = $wpdb->prefix . RegistrationTable::TABLE_NAME;

        return $wpdb->get_var(
            "SHOW INDEX FROM {$table} WHERE Key_name = 'idx_registered_at'",
        ) !== null;
    }

    /** @return array<int, string> column names of idx_registered_at, in Seq_in_index order */
    private function registeredAtIndexColumns(): array
    {
        global $wpdb;

        $table = $wpdb->prefix . RegistrationTable::TABLE_NAME;

        // SHOW INDEX does not accept an ORDER BY clause in MariaDB (syntax error →
        // empty result, which would mask the parity assertion). It already returns
        // rows in (Key_name, Seq_in_index) order, so filtering to one Key_name
        // yields the columns in Seq_in_index order without an explicit sort.
        $rows = $wpdb->get_results(
            "SHOW INDEX FROM {$table} WHERE Key_name = 'idx_registered_at'",
        );

        return array_map(static fn($row) => (string) $row->Column_name, $rows ?: []);
    }

    private function dropRegisteredAtIndexIfPresent(): void
    {
        global $wpdb;

        if (!$this->hasRegisteredAtIndex()) {
            return;
        }

        $table = $wpdb->prefix . RegistrationTable::TABLE_NAME;
        $wpdb->query("ALTER TABLE {$table} DROP INDEX idx_registered_at");
    }
}
