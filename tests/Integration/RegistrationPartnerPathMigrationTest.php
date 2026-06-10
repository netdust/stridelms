<?php

declare(strict_types=1);

namespace Stride\Tests\Integration;

use IntegrationTestCase;
use Stride\Modules\Enrollment\RegistrationRepository;
use Stride\Modules\Enrollment\RegistrationTable;

/**
 * Integration tests for the enrollment_path 'partner' ENUM migration (audit M-4 / task B3).
 *
 * Contract (threat-model M7 is the spec):
 *  - 'partner' is a valid enrollment_path: inserts with PATH_PARTNER read back 'partner'
 *    (pre-migration they were coerced to '' under non-strict SQL mode).
 *  - Backfill: rows with enrollment_path = '' AND company_id IS NOT NULL become 'partner'.
 *  - Denial path: empty-path rows WITHOUT a company_id are NOT touched; existing valid
 *    values ('individual'/'colleague'/'trajectory') are untouched.
 *  - Running the migration twice is a no-op (idempotent).
 *
 * Run: ddev exec "vendor/bin/phpunit -c phpunit-integration.xml.dist --filter RegistrationPartnerPathMigration"
 */
final class RegistrationPartnerPathMigrationTest extends IntegrationTestCase
{
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

        // Restore the schema-version option in case a forced re-run test failed
        // between delete_option() and migrate() completing.
        if (defined(RegistrationTable::class . '::SCHEMA_VERSION')) {
            update_option('stride_registrations_schema_version', RegistrationTable::SCHEMA_VERSION);
        }

        parent::tearDown();
    }

    /** @test */
    public function partnerEnrollmentPathRoundTrips(): void
    {
        $edition = $this->createTestEdition();

        $regId = $this->repo->create([
            'user_id' => self::$testUserId,
            'edition_id' => $edition,
            'status' => 'confirmed',
            'enrollment_path' => RegistrationRepository::PATH_PARTNER,
            'company_id' => 42,
        ]);

        $this->assertIsInt($regId, 'create() should return a registration ID');
        $this->createdRegistrationIds[] = $regId;

        $row = $this->repo->find($regId);
        $this->assertNotNull($row);
        $this->assertSame(
            RegistrationRepository::PATH_PARTNER,
            $row->enrollment_path,
            "PATH_PARTNER must round-trip as 'partner' — '' means the ENUM coerced it (M-4)",
        );
    }

    /** @test */
    public function backfillPromotesOnlyEmptyPathRowsWithCompany(): void
    {
        $edition = $this->createTestEdition();

        // Simulate pre-migration damage: rows whose enrollment_path was coerced to ''.
        $brokenPartnerId = $this->seedRawRegistration($edition, '', 7);
        $brokenOrphanId = $this->seedRawRegistration($edition, '', null);
        $trajectoryId = $this->seedRawRegistration($edition, RegistrationRepository::PATH_TRAJECTORY, 7);

        $this->forceMigration();

        $this->assertSame(
            RegistrationRepository::PATH_PARTNER,
            $this->pathOf($brokenPartnerId),
            'Empty-path row WITH company_id must be backfilled to partner',
        );
        $this->assertSame(
            '',
            $this->pathOf($brokenOrphanId),
            'Empty-path row WITHOUT company_id must NOT be backfilled (denial path)',
        );
        $this->assertSame(
            RegistrationRepository::PATH_TRAJECTORY,
            $this->pathOf($trajectoryId),
            'Existing valid enrollment_path values must be untouched',
        );
    }

    /** @test */
    public function migrationIsIdempotent(): void
    {
        global $wpdb;

        $edition = $this->createTestEdition();
        $backfilledId = $this->seedRawRegistration($edition, '', 9);
        $individualId = $this->seedRawRegistration($edition, RegistrationRepository::PATH_INDIVIDUAL, null);

        $this->forceMigration();
        $columnAfterFirst = $this->enrollmentPathColumnType();
        $rowsAfterFirst = [$this->pathOf($backfilledId), $this->pathOf($individualId)];

        // Second run with the version option already set: must be a guarded no-op.
        RegistrationTable::migrate();
        $this->assertSame('', $wpdb->last_error, 'Guarded second run must not error');

        // Forced second run (option cleared): DDL + backfill must still be a no-op.
        $this->forceMigration();

        $this->assertSame('', $wpdb->last_error, 'Forced second run must not error');
        $this->assertSame($columnAfterFirst, $this->enrollmentPathColumnType(), 'Column definition must be unchanged after re-run');
        $this->assertSame(
            $rowsAfterFirst,
            [$this->pathOf($backfilledId), $this->pathOf($individualId)],
            'Row values must be unchanged after re-run',
        );
        $this->assertStringContainsString("'partner'", $columnAfterFirst);
        $this->assertSame(
            RegistrationTable::SCHEMA_VERSION,
            (int) get_option('stride_registrations_schema_version'),
            'Schema version option must be stamped after migration',
        );
    }

    // === Helpers ===

    /**
     * Insert a registration row directly, bypassing the repository, so we can
     * seed pre-migration states (including the coerced '' enrollment_path).
     */
    private function seedRawRegistration(int $editionId, string $enrollmentPath, ?int $companyId): int
    {
        global $wpdb;

        $result = $wpdb->insert($wpdb->prefix . 'vad_registrations', [
            'user_id' => self::$testUserId,
            'edition_id' => $editionId,
            'status' => 'confirmed',
            'enrollment_path' => $enrollmentPath,
            'company_id' => $companyId,
        ]);

        $this->assertNotFalse($result, 'Raw seed insert failed: ' . $wpdb->last_error);

        $id = (int) $wpdb->insert_id;
        $this->createdRegistrationIds[] = $id;

        return $id;
    }

    private function forceMigration(): void
    {
        delete_option('stride_registrations_schema_version');
        RegistrationTable::migrate();
    }

    private function pathOf(int $registrationId): string
    {
        global $wpdb;

        return (string) $wpdb->get_var($wpdb->prepare(
            "SELECT enrollment_path FROM {$wpdb->prefix}vad_registrations WHERE id = %d",
            $registrationId,
        ));
    }

    private function enrollmentPathColumnType(): string
    {
        global $wpdb;

        $column = $wpdb->get_row(
            "SHOW COLUMNS FROM {$wpdb->prefix}vad_registrations LIKE 'enrollment_path'",
        );

        $this->assertNotNull($column);

        return (string) $column->Type;
    }
}
