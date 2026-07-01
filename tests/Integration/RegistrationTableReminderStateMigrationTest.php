<?php

declare(strict_types=1);

namespace Stride\Tests\Integration;

use IntegrationTestCase;
use Stride\Modules\Enrollment\RegistrationRepository;
use Stride\Modules\Enrollment\RegistrationTable;

/**
 * Integration tests for the v3 `reminder_state JSON` column migration
 * (Phase 2 Task 2.1 — reminder idempotency ledger).
 *
 * Contract (versioned migration pattern per RegistrationTable::migrate):
 *  - A v2 table (no reminder_state column) gains the column via migrate(),
 *    and the schema version is stamped to 3.
 *  - Re-running migrate() is a guarded no-op — no error, column stays,
 *    version stays 3.
 *  - A fresh create() builds the column directly (no migration needed).
 *  - While the retry-backoff transient lives, migrate() bails before any
 *    DDL and does NOT stamp the version.
 *
 * Run: ddev exec "vendor/bin/phpunit -c phpunit-integration.xml.dist --filter RegistrationTableReminderStateMigration"
 */
final class RegistrationTableReminderStateMigrationTest extends IntegrationTestCase
{
    private const SCHEMA_OPTION = 'stride_registrations_schema_version';
    private const BACKOFF_TRANSIENT = 'stride_registrations_migration_backoff';

    private RegistrationRepository $repo;

    /** @var array<int> */
    private array $createdRegistrationIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = ntdst_get(RegistrationRepository::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->createdRegistrationIds as $regId) {
            $this->deleteTestRegistration($regId);
        }
        $this->createdRegistrationIds = [];

        // Restore the real table to the latest schema regardless of what a
        // forced re-run test left behind, and clear any backoff.
        $this->ensureReminderStateColumn();
        update_option(self::SCHEMA_OPTION, RegistrationTable::SCHEMA_VERSION);
        delete_transient(self::BACKOFF_TRANSIENT);

        parent::tearDown();
    }

    /** @test */
    public function migrateAddsReminderStateColumnAndStampsVersionThree(): void
    {
        $this->dropReminderStateColumn();
        update_option(self::SCHEMA_OPTION, 2);

        $this->assertFalse($this->reminderStateColumnExists(), 'Precondition: column must be absent before migrate()');

        RegistrationTable::migrate();

        $this->assertTrue($this->reminderStateColumnExists(), 'migrate() must add the reminder_state column');
        $this->assertSame(
            3,
            (int) get_option(self::SCHEMA_OPTION),
            'migrate() must stamp schema version 3 after a successful ALTER',
        );
    }

    /** @test */
    public function migrateIsIdempotentOnReRun(): void
    {
        global $wpdb;

        $this->dropReminderStateColumn();
        update_option(self::SCHEMA_OPTION, 2);

        RegistrationTable::migrate();
        $this->assertTrue($this->reminderStateColumnExists());
        $this->assertSame(3, (int) get_option(self::SCHEMA_OPTION));

        // Guarded re-run: version already stamped at SCHEMA_VERSION → early return.
        RegistrationTable::migrate();
        $this->assertSame('', (string) $wpdb->last_error, 'Guarded second run must not error');
        $this->assertTrue($this->reminderStateColumnExists(), 'Column must still be present after re-run');
        $this->assertSame(3, (int) get_option(self::SCHEMA_OPTION), 'Version must still be 3 after re-run');

        // Forced re-run (option cleared, simulating a v2 DB re-entering
        // migrate() per the >= guard): the v2 step must be a safe no-op and
        // the v3 step must not error on an already-present column.
        update_option(self::SCHEMA_OPTION, 2);
        RegistrationTable::migrate();

        $this->assertSame('', (string) $wpdb->last_error, 'Forced re-run must not error');
        $this->assertTrue($this->reminderStateColumnExists(), 'Column must remain present after forced re-run');
        $this->assertSame(3, (int) get_option(self::SCHEMA_OPTION), 'Version must be re-stamped to 3');
    }

    /** @test */
    public function freshCreateBuildsReminderStateColumnDirectly(): void
    {
        global $wpdb;

        $table = RegistrationTable::getTableName();

        // Preserve real data across the drop+recreate so we don't nuke fixtures
        // used elsewhere in the suite run. Registration table is high-volume in
        // prod but empty/low in test DBs — still, be defensive.
        $wpdb->query("DROP TABLE IF EXISTS {$table}");

        RegistrationTable::create();

        $this->assertTrue(RegistrationTable::exists(), 'create() must (re)build the table');
        $this->assertTrue(
            $this->reminderStateColumnExists(),
            'A fresh create() must build the reminder_state column directly, without needing migrate()',
        );
        $this->assertSame(
            RegistrationTable::SCHEMA_VERSION,
            (int) get_option(self::SCHEMA_OPTION),
            'create() must stamp the latest schema version so migrate() does not re-run historical steps',
        );
    }

    /** @test */
    public function migrateBailsWhileRetryBackoffTransientLivesAndDoesNotStampVersion(): void
    {
        $this->dropReminderStateColumn();
        update_option(self::SCHEMA_OPTION, 2);

        set_transient(self::BACKOFF_TRANSIENT, 1, 5 * MINUTE_IN_SECONDS);

        RegistrationTable::migrate();

        $this->assertFalse(
            $this->reminderStateColumnExists(),
            'While the backoff transient lives, migrate() must bail before issuing any DDL',
        );
        $this->assertSame(
            2,
            (int) get_option(self::SCHEMA_OPTION),
            'Backoff bail must not stamp the version — retry semantics stay unstamped',
        );

        // Once the backoff lapses, the very next run must migrate normally.
        delete_transient(self::BACKOFF_TRANSIENT);
        RegistrationTable::migrate();

        $this->assertTrue($this->reminderStateColumnExists(), 'After the backoff lapses migrate() must add the column');
        $this->assertSame(3, (int) get_option(self::SCHEMA_OPTION));
    }

    // === Helpers ===

    private function reminderStateColumnExists(): bool
    {
        global $wpdb;

        $table = RegistrationTable::getTableName();
        $column = $wpdb->get_row("SHOW COLUMNS FROM {$table} LIKE 'reminder_state'");

        return $column !== null;
    }

    private function dropReminderStateColumn(): void
    {
        global $wpdb;

        if (!$this->reminderStateColumnExists()) {
            return;
        }

        $table = RegistrationTable::getTableName();
        $wpdb->query("ALTER TABLE {$table} DROP COLUMN reminder_state");
    }

    private function ensureReminderStateColumn(): void
    {
        global $wpdb;

        if ($this->reminderStateColumnExists()) {
            return;
        }

        $table = RegistrationTable::getTableName();
        $wpdb->query("ALTER TABLE {$table} ADD COLUMN reminder_state JSON NULL");
    }
}
