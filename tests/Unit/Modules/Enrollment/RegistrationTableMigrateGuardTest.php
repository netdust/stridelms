<?php

declare(strict_types=1);

namespace Stride\Tests\Unit\Modules\Enrollment;

use Stride\Modules\Enrollment\RegistrationTable;
use Stride\Tests\TestCase;

/**
 * Unit tests for the migrate() stamping condition (review finding CR-B1, INV-4).
 *
 * Contract: the schema-version option may ONLY be stamped when both the
 * ALTER and the backfill UPDATE did not error. On `$wpdb->query() === false`
 * the failure must be logged on the 'enrollment' channel (the module's
 * repository-failure channel) and migrate() must return WITHOUT stamping,
 * so the next init request retries. An UPDATE matching 0 rows returns 0
 * (int), not false — that is success, not an error.
 */
final class RegistrationTableMigrateGuardTest extends TestCase
{
    private object $originalWpdb;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalWpdb = $GLOBALS['wpdb'];
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb'] = $this->originalWpdb;

        parent::tearDown();
    }

    public function testAlterFailureDoesNotStampVersionAndLogsError(): void
    {
        $wpdb = $this->fakeWpdb([false], 'Lock wait timeout exceeded');

        RegistrationTable::migrate();

        $this->assertFalse(
            get_option('stride_registrations_schema_version'),
            'Schema version must NOT be stamped when the ALTER fails (would permanently no-op the migration)',
        );
        $this->assertCount(
            1,
            $wpdb->queries,
            'Backfill UPDATE must not run after the ALTER failed',
        );
        $this->assertMigrationFailureLogged('Lock wait timeout exceeded');
    }

    public function testBackfillFailureDoesNotStampVersionAndLogsError(): void
    {
        $this->fakeWpdb([true, false], 'Server has gone away');

        RegistrationTable::migrate();

        $this->assertFalse(
            get_option('stride_registrations_schema_version'),
            'Schema version must NOT be stamped when the backfill UPDATE fails',
        );
        $this->assertMigrationFailureLogged('Server has gone away');
    }

    public function testZeroRowBackfillIsSuccessNotError(): void
    {
        // $wpdb->query() returns 0 (int) when the UPDATE matches no rows —
        // that is success, not failure, and must stamp the version.
        $this->fakeWpdb([true, 0]);

        RegistrationTable::migrate();

        $this->assertSame(
            RegistrationTable::SCHEMA_VERSION,
            (int) get_option('stride_registrations_schema_version'),
            'A 0-row backfill (int 0, not false) must still stamp the version',
        );
    }

    public function testSuccessfulMigrationStampsVersion(): void
    {
        $this->fakeWpdb([true, 3]);

        RegistrationTable::migrate();

        $this->assertSame(
            RegistrationTable::SCHEMA_VERSION,
            (int) get_option('stride_registrations_schema_version'),
        );
    }

    public function testFailedRunIsRetriedOnNextInit(): void
    {
        // First init request: ALTER fails → no stamp.
        $this->fakeWpdb([false], 'Lock wait timeout exceeded');
        RegistrationTable::migrate();
        $this->assertFalse(get_option('stride_registrations_schema_version'));

        // Next init request: DB healthy → migrate() must run again and stamp.
        $wpdb = $this->fakeWpdb([true, 1]);
        RegistrationTable::migrate();

        $this->assertSame(
            RegistrationTable::SCHEMA_VERSION,
            (int) get_option('stride_registrations_schema_version'),
            'After a failed run, the next init must retry and stamp on success',
        );
        $this->assertCount(2, $wpdb->queries, 'Retry must re-run both the ALTER and the backfill');
    }

    // === Helpers ===

    /**
     * Swap the global $wpdb for a fake whose query() results are queued.
     *
     * @param array<bool|int> $queryResults Successive query() returns; false simulates a DB error.
     */
    private function fakeWpdb(array $queryResults, string $errorOnFailure = ''): object
    {
        $wpdb = new class {
            public string $prefix = 'wp_';

            public string $last_error = '';

            /** @var array<string> */
            public array $queries = [];

            /** @var array<bool|int> */
            public array $queryResults = [];

            public string $errorOnFailure = '';

            public function prepare(string $query, ...$args): string
            {
                foreach ($args as $arg) {
                    $query = preg_replace(
                        '/%[sd]/',
                        is_string($arg) ? "'" . addslashes($arg) . "'" : (string) $arg,
                        $query,
                        1,
                    );
                }

                return $query;
            }

            public function get_var(?string $query = null): ?string
            {
                // RegistrationTable::exists() probes SHOW TABLES LIKE — report the table present.
                return 'wp_' . RegistrationTable::TABLE_NAME;
            }

            public function query(string $query): bool|int
            {
                $this->queries[] = $query;
                $result = array_shift($this->queryResults) ?? true;
                $this->last_error = ($result === false) ? $this->errorOnFailure : '';

                return $result;
            }
        };

        $wpdb->queryResults = $queryResults;
        $wpdb->errorOnFailure = $errorOnFailure;

        $GLOBALS['wpdb'] = $wpdb;

        return $wpdb;
    }

    private function assertMigrationFailureLogged(string $expectedDbError): void
    {
        global $_test_log_entries;

        $failures = array_values(array_filter(
            $_test_log_entries ?? [],
            static fn(array $entry): bool => $entry['level'] === 'error'
                && $entry['channel'] === 'enrollment'
                && str_contains($entry['message'], 'schema v2 migration failed'),
        ));

        $this->assertNotEmpty(
            $failures,
            "Migration failure must be logged as error on the 'enrollment' channel (INV-4)",
        );
        $this->assertSame(
            $expectedDbError,
            $failures[0]['context']['error'] ?? null,
            'Log context must carry $wpdb->last_error',
        );
    }
}
