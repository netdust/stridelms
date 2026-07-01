<?php

declare(strict_types=1);

namespace Stride\Tests\Integration;

use IntegrationTestCase;
use Stride\Modules\Enrollment\RegistrationRepository;
use WP_Error;

/**
 * Integration tests for RegistrationRepository::getReminderState() /
 * setReminderState() (Phase 2 Task 2.2).
 *
 * Contract:
 *  - set then get round-trips the EXACT array (incl. nested nulls).
 *  - get on a fresh registration (NULL column) returns [] — never null/false.
 *  - get on a non-existent regId returns [] — never null/false.
 *  - setReminderState returns WP_Error (INV-4) on a genuine DB failure, not
 *    false. A non-existent regId is NOT itself an error (0 rows affected is
 *    a valid "nothing to update" outcome, matching the other update-style
 *    methods in this repo, e.g. attachUserToWaitlistRow) — so the DB-failure
 *    path is asserted by forcing $wpdb->update to fail via a dropped column
 *    (a clean, real failure injection rather than a faked one).
 *
 * Run: ddev exec "vendor/bin/phpunit -c phpunit-integration.xml.dist --filter RegistrationReminderStateRepo"
 */
final class RegistrationReminderStateRepoTest extends IntegrationTestCase
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

        $this->ensureReminderStateColumn();

        parent::tearDown();
    }

    /** @test */
    public function setThenGetRoundTripsTheExactArrayIncludingNestedNulls(): void
    {
        $edition = $this->createTestEdition();

        $regId = $this->repo->create([
            'user_id' => self::$testUserId,
            'edition_id' => $edition,
            'status' => 'confirmed',
            'enrollment_path' => RegistrationRepository::PATH_INDIVIDUAL,
        ]);
        $this->assertIsInt($regId);
        $this->createdRegistrationIds[] = $regId;

        $state = [
            'enroll' => ['reminder' => '2026-07-10', 'deadline' => null],
            'post' => ['reminder' => null, 'deadline' => '2026-08-01'],
        ];

        $result = $this->repo->setReminderState($regId, $state);
        $this->assertTrue($result, 'setReminderState must return true on success');

        $roundTripped = $this->repo->getReminderState($regId);
        $this->assertSame($state, $roundTripped, 'getReminderState must round-trip the exact array incl. nested nulls');
    }

    /** @test */
    public function getOnAFreshRegistrationWithNullColumnReturnsEmptyArray(): void
    {
        $edition = $this->createTestEdition();

        $regId = $this->repo->create([
            'user_id' => self::$testUserId,
            'edition_id' => $edition,
            'status' => 'confirmed',
            'enrollment_path' => RegistrationRepository::PATH_INDIVIDUAL,
        ]);
        $this->assertIsInt($regId);
        $this->createdRegistrationIds[] = $regId;

        $result = $this->repo->getReminderState($regId);

        $this->assertIsArray($result);
        $this->assertSame([], $result, 'A fresh registration with NULL reminder_state must return [], never null/false');
    }

    /** @test */
    public function getOnANonExistentRegIdReturnsEmptyArray(): void
    {
        $result = $this->repo->getReminderState(PHP_INT_MAX - 1);

        $this->assertIsArray($result);
        $this->assertSame([], $result, 'A non-existent registration id must return [], never null/false');
    }

    /** @test */
    public function setReminderStateReturnsWpErrorOnGenuineDbFailure(): void
    {
        global $wpdb;

        $edition = $this->createTestEdition();

        $regId = $this->repo->create([
            'user_id' => self::$testUserId,
            'edition_id' => $edition,
            'status' => 'confirmed',
            'enrollment_path' => RegistrationRepository::PATH_INDIVIDUAL,
        ]);
        $this->assertIsInt($regId);
        $this->createdRegistrationIds[] = $regId;

        // Force a genuine DB failure: drop the column the write targets, so
        // $wpdb->update() fails at the SQL level (real failure injection,
        // not a faked WP_Error return).
        $this->dropReminderStateColumn();

        $result = $this->repo->setReminderState($regId, ['enroll' => ['reminder' => '2026-07-10']]);

        $this->assertInstanceOf(
            WP_Error::class,
            $result,
            'setReminderState must return WP_Error on a genuine DB failure (INV-4), not false',
        );
        $this->assertSame('db_error', $result->get_error_code());
    }

    // === Helpers ===

    private function reminderStateColumnExists(): bool
    {
        global $wpdb;

        $table = $wpdb->prefix . 'vad_registrations';
        $column = $wpdb->get_row("SHOW COLUMNS FROM {$table} LIKE 'reminder_state'");

        return $column !== null;
    }

    private function dropReminderStateColumn(): void
    {
        global $wpdb;

        if (!$this->reminderStateColumnExists()) {
            return;
        }

        $table = $wpdb->prefix . 'vad_registrations';
        $wpdb->query("ALTER TABLE {$table} DROP COLUMN reminder_state");
    }

    private function ensureReminderStateColumn(): void
    {
        global $wpdb;

        if ($this->reminderStateColumnExists()) {
            return;
        }

        $table = $wpdb->prefix . 'vad_registrations';
        $wpdb->query("ALTER TABLE {$table} ADD COLUMN reminder_state JSON NULL");
    }
}
