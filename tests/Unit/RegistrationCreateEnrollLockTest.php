<?php

declare(strict_types=1);

namespace Stride\Tests\Unit;

use Mockery;
use Mockery\MockInterface;
use ReflectionMethod;
use Stride\Modules\Enrollment\RegistrationRepository;
use Stride\Tests\TestCase;
use WP_Error;

/**
 * DATA-2 / Task 1B.1 — create-path (user,edition) advisory-lock seam.
 *
 * RegistrationRepository::create() is a read-then-insert: it reads
 * findByUserAndEdition, then either reactivates the found row, blocks the
 * active duplicate, or inserts. With no lock, two concurrent create() calls
 * for the same (user_id, edition_id) both pass the read before either writes
 * → two confirmed rows + double grant. Task 1B.1 wraps the check-and-insert
 * in the tuple advisory lock (acquireEnrollLock), released on EVERY exit.
 *
 * Concurrency can't be driven with real threads in the unit suite, so this is
 * a SEAM test: a partial mock keeps create() real but spies on the lock
 * methods + findByUserAndEdition, asserting the lock brackets the critical
 * section on every path. The re-enroll-same-row constraint (the behavior the
 * dropped UNIQUE key broke) is asserted here too — it MUST stay green.
 * A true two-connection race is deferred to the integration gate.
 *
 * Run: ddev exec vendor/bin/phpunit --testsuite Unit --filter RegistrationCreateEnrollLock
 */
final class RegistrationCreateEnrollLockTest extends TestCase
{
    private const USER_ID = 42;
    private const EDITION_ID = 7;

    private RegistrationRepository|MockInterface $repo;

    protected function setUp(): void
    {
        parent::setUp();
        // Partial mock: create() runs real; only the lock methods + the
        // duplicate read are overridden so we can observe bracketing without
        // a real MySQL GET_LOCK.
        $this->repo = Mockery::mock(RegistrationRepository::class)->makePartial();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** @test */
    public function freshCreateAcquiresLockBeforeDuplicateReadAndReleasesAfterInsert(): void
    {
        $order = [];

        $this->repo->shouldReceive('acquireEnrollLock')
            ->once()->with(self::USER_ID, self::EDITION_ID)
            ->andReturnUsing(function () use (&$order) {
                $order[] = 'acquire';
                return true;
            });
        $this->repo->shouldReceive('findByUserAndEdition')
            ->with(self::USER_ID, self::EDITION_ID)
            ->andReturnUsing(function () use (&$order) {
                $order[] = 'duplicate_read';
                return null; // no existing row → insert path
            });
        $this->repo->shouldReceive('releaseEnrollLock')
            ->once()->with(self::USER_ID, self::EDITION_ID)
            ->andReturnUsing(function () use (&$order) {
                $order[] = 'release';
            });
        // clearCache is called after the insert; keep it inert.
        $this->repo->shouldReceive('clearCache')->andReturnNull();

        $result = $this->repo->create([
            'user_id' => self::USER_ID,
            'edition_id' => self::EDITION_ID,
            'status' => 'confirmed',
        ]);

        // The global $wpdb stub returns true/insert_id=0, so create() returns 0.
        $this->assertIsInt($result);
        $this->assertSame(['acquire', 'duplicate_read', 'release'], $order);
    }

    /** @test */
    public function activeDuplicateReturnsWpErrorAndReleasesLock(): void
    {
        $this->repo->shouldReceive('acquireEnrollLock')
            ->once()->with(self::USER_ID, self::EDITION_ID)->andReturn(true);
        $this->repo->shouldReceive('findByUserAndEdition')
            ->with(self::USER_ID, self::EDITION_ID)
            ->andReturn((object) ['id' => 99, 'status' => 'confirmed', 'enrollment_data' => null]);
        // Insert must NOT run — an active duplicate blocks. clearCache guards it.
        $this->repo->shouldReceive('releaseEnrollLock')
            ->once()->with(self::USER_ID, self::EDITION_ID);

        $result = $this->repo->create([
            'user_id' => self::USER_ID,
            'edition_id' => self::EDITION_ID,
            'status' => 'confirmed',
        ]);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('duplicate', $result->get_error_code());
    }

    /**
     * @test
     * The constraint that killed the plain UNIQUE key: a Cancelled row is
     * REACTIVATED (same id returned), not blocked, not duplicated. This MUST
     * stay green — the advisory lock preserves it where a unique key forbade it.
     */
    public function reEnrollAfterCancelReactivatesSameRowUnderLock(): void
    {
        $this->repo->shouldReceive('acquireEnrollLock')
            ->once()->with(self::USER_ID, self::EDITION_ID)->andReturn(true);
        $this->repo->shouldReceive('findByUserAndEdition')
            ->with(self::USER_ID, self::EDITION_ID)
            ->andReturn((object) [
                'id' => 123,
                'status' => 'cancelled',
                'enrollment_data' => null,
                'enrollment_path' => 'individual',
            ]);
        $this->repo->shouldReceive('releaseEnrollLock')
            ->once()->with(self::USER_ID, self::EDITION_ID);
        $this->repo->shouldReceive('clearCache')->andReturnNull();

        $result = $this->repo->create([
            'user_id' => self::USER_ID,
            'edition_id' => self::EDITION_ID,
            'status' => 'confirmed',
        ]);

        // Same row id reactivated — NOT a new insert, NOT a duplicate error.
        $this->assertSame(123, $result);
    }

    /**
     * @test
     * Denial path: a contended lock (GET_LOCK timeout) returns
     * WP_Error('lock_timeout') and NEVER reads or inserts — proceeding unlocked
     * would reopen the race.
     */
    public function lockTimeoutRefusesAndNeverReadsOrInserts(): void
    {
        $this->repo->shouldReceive('acquireEnrollLock')
            ->once()->with(self::USER_ID, self::EDITION_ID)->andReturn(false);
        $this->repo->shouldNotReceive('findByUserAndEdition');
        $this->repo->shouldNotReceive('releaseEnrollLock');

        $result = $this->repo->create([
            'user_id' => self::USER_ID,
            'edition_id' => self::EDITION_ID,
            'status' => 'confirmed',
        ]);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('lock_timeout', $result->get_error_code());
    }

    /**
     * @test
     * The lock name follows the existing selection-lock idiom: table-prefixed
     * so parallel test/staging DBs on one MySQL server don't contend.
     */
    public function lockNameIsTablePrefixedTuple(): void
    {
        global $wpdb;
        $method = new ReflectionMethod(RegistrationRepository::class, 'enrollLockName');
        $method->setAccessible(true);

        $repo = new RegistrationRepository();
        $name = $method->invoke($repo, 55, 88);

        $this->assertSame($wpdb->prefix . 'stride_reg_55_88', $name);
    }
}
