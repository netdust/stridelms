<?php

declare(strict_types=1);

namespace Stride\Tests\Integration;

use IntegrationTestCase;
use Stride\Modules\Enrollment\EnrollmentService;
use Stride\Modules\Enrollment\RegistrationRepository;

/**
 * Integration tests for EnrollmentService
 *
 * Tests enrollment operations against real WordPress database.
 * Run: ddev exec vendor/bin/phpunit -c phpunit-integration.xml.dist --filter EnrollmentService
 */
class EnrollmentServiceIntegrationTest extends IntegrationTestCase
{
    private EnrollmentService $enrollmentService;
    private array $testRegistrationIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->enrollmentService = ntdst_get(EnrollmentService::class);
        $this->actingAs(self::$testUserId);
    }

    protected function tearDown(): void
    {
        // Clean up registrations created during tests
        foreach ($this->testRegistrationIds as $regId) {
            $this->deleteTestRegistration($regId);
        }
        $this->testRegistrationIds = [];

        parent::tearDown();
    }

    // =========================================================================
    // ENROLLMENT
    // =========================================================================

    /**
     * @test
     */
    public function canEnrollUserInEdition(): void
    {
        $editionId = $this->createTestEdition();

        $result = $this->enrollmentService->enroll(self::$testUserId, $editionId);

        $this->assertIsInt($result, 'Enrollment should return registration ID');
        $this->assertGreaterThan(0, $result);

        $this->testRegistrationIds[] = $result;

        // Verify enrollment
        $this->assertTrue($this->enrollmentService->isEnrolled(self::$testUserId, $editionId));
    }

    /**
     * @test
     */
    public function enrollmentRejectsInvalidEdition(): void
    {
        $result = $this->enrollmentService->enroll(self::$testUserId, 999999);

        $this->assertTrue(is_wp_error($result));
        $this->assertEquals('invalid_edition', $result->get_error_code());
    }

    /**
     * @test
     */
    public function enrollmentRejectsDuplicateEnrollment(): void
    {
        $editionId = $this->createTestEdition();

        // First enrollment
        $regId = $this->enrollmentService->enroll(self::$testUserId, $editionId);
        $this->testRegistrationIds[] = $regId;

        // Second enrollment should fail
        $result = $this->enrollmentService->enroll(self::$testUserId, $editionId);

        $this->assertTrue(is_wp_error($result));
        $this->assertEquals('already_enrolled', $result->get_error_code());
    }

    /**
     * @test
     */
    public function enrollmentRejectsClosedEdition(): void
    {
        // Use 'cancelled' status as there is no 'closed' in EditionStatus enum
        // Valid non-enrollment statuses: cancelled, full, postponed, announcement, completed
        $editionId = $this->createTestEdition([
            'meta' => ['_ntdst_status' => 'cancelled'],
        ]);

        $result = $this->enrollmentService->enroll(self::$testUserId, $editionId);

        $this->assertTrue(is_wp_error($result));
        $this->assertEquals('enrollment_closed', $result->get_error_code());
    }

    // =========================================================================
    // CANCELLATION
    // =========================================================================

    /**
     * @test
     */
    public function canCancelEnrollment(): void
    {
        $editionId = $this->createTestEdition();

        // Enroll first
        $regId = $this->enrollmentService->enroll(self::$testUserId, $editionId);
        $this->testRegistrationIds[] = $regId;

        $this->assertTrue($this->enrollmentService->isEnrolled(self::$testUserId, $editionId));

        // Cancel
        $result = $this->enrollmentService->cancel($regId);

        $this->assertTrue($result);
        $this->assertFalse($this->enrollmentService->isEnrolled(self::$testUserId, $editionId));
    }

    /**
     * @test
     */
    public function cancelRejectsInvalidRegistration(): void
    {
        $result = $this->enrollmentService->cancel(999999);

        $this->assertTrue(is_wp_error($result));
    }

    // =========================================================================
    // ENROLLMENT STATUS
    // =========================================================================

    /**
     * @test
     */
    public function isEnrolledReturnsFalseForNonEnrolledUser(): void
    {
        $editionId = $this->createTestEdition();

        $this->assertFalse($this->enrollmentService->isEnrolled(self::$testUserId, $editionId));
    }

    /**
     * @test
     */
    public function canGetUserEnrollments(): void
    {
        $edition1 = $this->createTestEdition(['post_title' => 'Test Edition 1']);
        $edition2 = $this->createTestEdition(['post_title' => 'Test Edition 2']);

        $regId1 = $this->enrollmentService->enroll(self::$testUserId, $edition1);
        $regId2 = $this->enrollmentService->enroll(self::$testUserId, $edition2);

        $this->testRegistrationIds[] = $regId1;
        $this->testRegistrationIds[] = $regId2;

        $enrollments = $this->enrollmentService->getUserEnrollments(self::$testUserId);

        $this->assertIsArray($enrollments);
        $this->assertGreaterThanOrEqual(2, count($enrollments));
    }

    // =========================================================================
    // REGISTRATION RETRIEVAL
    // =========================================================================

    /**
     * @test
     */
    public function canGetRegistrationById(): void
    {
        $editionId = $this->createTestEdition();

        $regId = $this->enrollmentService->enroll(self::$testUserId, $editionId);
        $this->testRegistrationIds[] = $regId;

        $registration = $this->enrollmentService->getRegistration($regId);

        $this->assertIsObject($registration);
        $this->assertEquals(self::$testUserId, (int) $registration->user_id);
        $this->assertEquals($editionId, (int) $registration->edition_id);
    }

    // =========================================================================
    // ENROLLMENT OPTIONS
    // =========================================================================

    /**
     * @test
     */
    public function enrollmentAcceptsVoucherCodeOption(): void
    {
        $editionId = $this->createTestEdition();

        // Test that enrollment doesn't fail with voucher_code option
        $regId = $this->enrollmentService->enroll(self::$testUserId, $editionId, [
            'voucher_code' => 'TESTVOUCHER',
        ]);
        $this->testRegistrationIds[] = $regId;

        $this->assertIsInt($regId);
        $this->assertGreaterThan(0, $regId);

        // The voucher_code may or may not be stored - just verify enrollment succeeded
        $registration = $this->enrollmentService->getRegistration($regId);
        $this->assertIsObject($registration);
    }

    /**
     * @test
     */
    public function enrollmentRecordsEnrollmentPath(): void
    {
        $editionId = $this->createTestEdition();

        $regId = $this->enrollmentService->enroll(self::$testUserId, $editionId, [
            'enrollment_path' => RegistrationRepository::PATH_COLLEAGUE,
            'enrolled_by' => 999,
        ]);
        $this->testRegistrationIds[] = $regId;

        $registration = $this->enrollmentService->getRegistration($regId);

        $this->assertEquals(RegistrationRepository::PATH_COLLEAGUE, $registration->enrollment_path);
        $this->assertEquals(999, (int) $registration->enrolled_by);
    }
}
