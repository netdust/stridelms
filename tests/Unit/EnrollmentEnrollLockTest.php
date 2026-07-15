<?php

declare(strict_types=1);

namespace Stride\Tests\Unit;

use Mockery;
use Mockery\MockInterface;
use Stride\Contracts\EditionQueryInterface;
use Stride\Contracts\LMSAdapterInterface;
use Stride\Domain\OfferingStatus;
use Stride\Modules\Enrollment\EnrollmentCompletion;
use Stride\Modules\Enrollment\EnrollmentService;
use Stride\Modules\Enrollment\RegistrationRepository;
use Stride\Modules\User\ProfileTypePolicy;
use Stride\Tests\TestCase;
use WP_Error;

/**
 * DATA-2 / Task 1B.2 — enroll-path (user,edition) advisory-lock seam.
 *
 * The capacity `countConfirmedForUpdate` FOR UPDATE lock covers the CAPACITY
 * predicate, not the DUPLICATE predicate (`findByUserAndEdition` at
 * EnrollmentService:278). Two concurrent enrolls for the same (user,edition)
 * can both pass that read before either inserts → two confirmed rows + double
 * grant. Task 1B.2 wraps the duplicate-check-through-insert span in the same
 * tuple advisory lock the repository owns (acquireEnrollLock), acquired BEFORE
 * the capacity FOR UPDATE (lock ordering, deadlock avoidance).
 *
 * Seam contract (mirrors TrajectorySelectionFromCoursesTest's lock seam):
 * - enroll() acquires acquireEnrollLock(user,edition) before the :278
 *   duplicate check, and before the capacity FOR UPDATE (ordering).
 * - the lock is released on every exit (success, duplicate, full, timeout).
 * - a lock timeout returns WP_Error('lock_timeout') and NEVER reaches create().
 *
 * Run: ddev exec vendor/bin/phpunit --testsuite Unit --filter EnrollmentEnrollLock
 */
final class EnrollmentEnrollLockTest extends TestCase
{
    private const USER_ID = 42;
    private const EDITION_ID = 7;
    private const COURSE_ID = 900;

    private RegistrationRepository|MockInterface $registrations;
    private EditionQueryInterface|MockInterface $editions;
    private LMSAdapterInterface|MockInterface $lms;
    private EnrollmentService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registrations = Mockery::mock(RegistrationRepository::class);
        $this->editions = Mockery::mock(EditionQueryInterface::class);
        $this->lms = Mockery::mock(LMSAdapterInterface::class);

        // Edition gate passes: exists, not past, open, has capacity headroom.
        $this->editions->shouldReceive('exists')->andReturn(true)->byDefault();
        $this->editions->shouldReceive('isPast')->andReturn(false)->byDefault();
        $this->editions->shouldReceive('getEffectiveStatus')->andReturn(OfferingStatus::Open)->byDefault();
        $this->editions->shouldReceive('getCapacity')->andReturn(0)->byDefault(); // 0 = unlimited
        $this->editions->shouldReceive('getCourseId')->andReturn(self::COURSE_ID)->byDefault();
        $this->editions->shouldReceive('requiresApproval')->andReturn(false)->byDefault();

        $this->lms->shouldReceive('grantAccess')->andReturn(true)->byDefault();

        // enroll()'s lead-adopt pre-check (interest AND waitlist leads).
        $this->registrations->shouldReceive('findAnonymousForEmailAndEdition')->andReturn(null)->byDefault();

        $this->service = new EnrollmentService(
            $this->registrations,
            $this->editions,
            $this->lms,
        );

        // Register AFTER construction — EnrollmentService::init() (run from the
        // constructor) binds a Closure factory for EnrollmentCompletion into the
        // container, which would otherwise clobber this mock. enroll() resolves
        // this via ntdst_get() before the duplicate check.
        $completion = Mockery::mock(EnrollmentCompletion::class);
        $completion->shouldReceive('hasRequirements')->andReturn(false)->byDefault();
        $completion->shouldReceive('initializeForRegistration')->byDefault();
        $this->registerService(EnrollmentCompletion::class, $completion);

        // enroll() resolves the profile-type gate (INV-12) via ntdst_get()
        // before the duplicate check; register a fail-open mock so this
        // lock-seam test isn't gated (the gate's own behaviour is covered by
        // ProfileTypePolicyTest + ProfiletypeEnrollGateTest).
        $policy = Mockery::mock(ProfileTypePolicy::class);
        $policy->shouldReceive('blocksEnrollment')->andReturn(false)->byDefault();
        $this->registerService(ProfileTypePolicy::class, $policy);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * @test
     * The tuple lock is acquired BEFORE the capacity FOR UPDATE and the
     * duplicate check, and released after the write. Lock ordering matters:
     * tuple-lock → capacity FOR UPDATE, never the reverse (deadlock avoidance).
     */
    public function enrollAcquiresEnrollLockBeforeDuplicateCheckAndReleasesAfter(): void
    {
        $order = [];

        $this->registrations->shouldReceive('acquireEnrollLock')
            ->once()->with(self::USER_ID, self::EDITION_ID)
            ->andReturnUsing(function () use (&$order) {
                $order[] = 'acquire';
                return true;
            });

        $this->registrations->shouldReceive('countConfirmedForUpdate')
            ->andReturnUsing(function () use (&$order) {
                $order[] = 'capacity_for_update';
                return 0;
            });

        $this->registrations->shouldReceive('findByUserAndEdition')
            ->with(self::USER_ID, self::EDITION_ID)
            ->andReturnUsing(function () use (&$order) {
                $order[] = 'duplicate_check';
                return null;
            });

        $this->registrations->shouldReceive('create')
            ->andReturnUsing(function () use (&$order) {
                $order[] = 'create';
                return 555;
            });

        $this->registrations->shouldReceive('releaseEnrollLock')
            ->once()->with(self::USER_ID, self::EDITION_ID)
            ->andReturnUsing(function () use (&$order) {
                $order[] = 'release';
            });

        $result = $this->service->enroll(self::USER_ID, self::EDITION_ID);

        $this->assertSame(555, $result);
        // Lock acquired first, before BOTH the capacity FOR UPDATE and the
        // duplicate read; released last.
        $this->assertSame('acquire', $order[0], 'enroll lock must be acquired first');
        $this->assertSame('release', $order[count($order) - 1], 'enroll lock must be released last');
        $acquireIdx = array_search('acquire', $order, true);
        $capacityIdx = array_search('capacity_for_update', $order, true);
        $dupIdx = array_search('duplicate_check', $order, true);
        $this->assertLessThan($capacityIdx, $acquireIdx, 'tuple lock must precede capacity FOR UPDATE');
        $this->assertLessThan($dupIdx, $acquireIdx, 'tuple lock must precede the duplicate check');
    }

    /**
     * @test
     * Denial path: a contended tuple lock (GET_LOCK timeout) returns
     * WP_Error('lock_timeout') and NEVER reaches create() or grantAccess —
     * proceeding unlocked would reopen the very race this fix closes.
     */
    public function enrollLockTimeoutRefusesAndNeverInserts(): void
    {
        $this->registrations->shouldReceive('acquireEnrollLock')
            ->once()->with(self::USER_ID, self::EDITION_ID)
            ->andReturn(false);

        $this->registrations->shouldNotReceive('create');
        $this->registrations->shouldNotReceive('countConfirmedForUpdate');
        $this->lms->shouldNotReceive('grantAccess');
        // Nothing to release — the lock was never held.
        $this->registrations->shouldNotReceive('releaseEnrollLock');

        $result = $this->service->enroll(self::USER_ID, self::EDITION_ID);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('lock_timeout', $result->get_error_code());
    }

    /**
     * @test
     * The lock is released even when the duplicate check refuses the enroll
     * (already-enrolled). A held lock that is never released would wedge every
     * later enroll for that tuple until the connection drops.
     */
    public function enrollReleasesLockWhenDuplicateRefuses(): void
    {
        $this->registrations->shouldReceive('acquireEnrollLock')
            ->once()->with(self::USER_ID, self::EDITION_ID)->andReturn(true);
        $this->registrations->shouldReceive('countConfirmedForUpdate')->andReturn(0);

        $existing = (object) [
            'id' => 12,
            'status' => 'confirmed',
            'user_id' => self::USER_ID,
        ];
        $this->registrations->shouldReceive('findByUserAndEdition')
            ->with(self::USER_ID, self::EDITION_ID)->andReturn($existing);

        $this->registrations->shouldNotReceive('create');
        $this->registrations->shouldReceive('releaseEnrollLock')
            ->once()->with(self::USER_ID, self::EDITION_ID);

        $result = $this->service->enroll(self::USER_ID, self::EDITION_ID);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('already_enrolled', $result->get_error_code());
    }
}
