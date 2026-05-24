<?php

declare(strict_types=1);

namespace Stride\Tests\Integration;

use IntegrationTestCase;
use Stride\Handlers\EnrollmentFormHandler;
use Stride\Modules\Enrollment\EnrollmentService;
use Stride\Modules\Enrollment\RegistrationRepository;

/**
 * Integration test: EnrollmentFormHandler wraps stage data with submitter metadata.
 *
 * Verifies that after Task 7, both enrollment_personal and enrollment_billing
 * are persisted as wrapped shapes (submitted_by, submitted_at, data) rather than flat arrays.
 *
 * Run: ddev exec vendor/bin/phpunit --filter EnrollmentFormHandlerWrapTest --testsuite Integration
 */
final class EnrollmentFormHandlerWrapTest extends IntegrationTestCase
{
    private EnrollmentFormHandler $handler;
    private RegistrationRepository $registrations;
    private array $testRegistrationIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->handler = ntdst_get(EnrollmentFormHandler::class);
        $this->registrations = ntdst_get(RegistrationRepository::class);
        $this->actingAs(self::$testUserId);
    }

    protected function tearDown(): void
    {
        global $wpdb;
        foreach ($this->testRegistrationIds as $regId) {
            $wpdb->delete($wpdb->prefix . 'vad_registrations', ['id' => $regId]);
        }
        parent::tearDown();
    }

    public function testEnrollmentPersistsWrappedPersonalAndBillingStages(): void
    {
        $courseId  = $this->createTestCourse();
        $editionId = $this->createTestEdition([
            'meta' => [
                '_ntdst_course_id'    => $courseId,
                '_ntdst_price'        => 0,
                '_ntdst_status'       => 'open',
                '_ntdst_capacity_max' => 10,
            ],
        ]);

        $result = $this->handler->handleSubmitEnrollment(null, [
            'edition_id'      => (string) $editionId,
            'enrollment_type' => 'self',
            'item_type'       => 'edition',
            'first_name'      => 'Jan',
            'last_name'       => 'Janssens',
            'email'           => 'jan-wrap-test@example.com',
            'terms_accepted'  => true,
            'extra_fields'    => [
                'organisation' => 'ACME',
                'vat_number'   => 'BE0123',
            ],
        ]);

        if (is_wp_error($result)) {
            $this->fail('Enrollment failed: ' . $result->get_error_message());
        }

        $this->assertIsArray($result, 'enrollment should succeed');
        $regId = (int) ($result['registration_id'] ?? 0);
        $this->assertGreaterThan(0, $regId, 'registration_id should be set');
        $this->testRegistrationIds[] = $regId;

        $row = $this->registrations->find($regId);
        $this->assertNotNull($row, 'registration row should exist');

        $enrollmentData = $row->enrollment_data ?? null;
        $this->assertIsArray($enrollmentData, 'enrollment_data should be an array');

        // --- enrollment_personal ---
        $personal = $enrollmentData['enrollment_personal'] ?? null;
        $this->assertNotNull($personal, 'enrollment_personal stage should be present');
        $this->assertArrayHasKey('submitted_by', $personal, 'enrollment_personal should have submitted_by');
        $this->assertArrayHasKey('submitted_at', $personal, 'enrollment_personal should have submitted_at');
        $this->assertArrayHasKey('data', $personal, 'enrollment_personal should have data key');
        $this->assertSame(self::$testUserId, $personal['submitted_by'], 'submitted_by should be current user');

        // --- enrollment_billing ---
        $billing = $enrollmentData['enrollment_billing'] ?? null;
        $this->assertNotNull($billing, 'enrollment_billing stage should be present');
        $this->assertArrayHasKey('submitted_by', $billing, 'enrollment_billing should have submitted_by');
        $this->assertArrayHasKey('submitted_at', $billing, 'enrollment_billing should have submitted_at');
        $this->assertArrayHasKey('data', $billing, 'enrollment_billing should have data key');
        $this->assertSame(self::$testUserId, $billing['submitted_by'], 'submitted_by should be current user');
    }

    public function testDirectExtraFieldsAtServiceLayerWriteWrappedPersonalStage(): void
    {
        $courseId  = $this->createTestCourse();
        $editionId = $this->createTestEdition([
            'meta' => [
                '_ntdst_course_id'    => $courseId,
                '_ntdst_price'        => 0,
                '_ntdst_status'       => 'open',
                '_ntdst_capacity_max' => 10,
            ],
        ]);

        $this->actingAs(self::$testUserId);

        $service = ntdst_get(EnrollmentService::class);
        $result  = $service->processEnrollment([
            'edition_id'      => $editionId,
            'user_id'         => self::$testUserId,
            'enrollment_type' => 'self',
            'first_name'      => 'Jan',
            'last_name'       => 'Janssens',
            'email'           => 'jan-direct@example.com',
            'terms_accepted'  => true,
            'extra_fields'    => ['fav_color' => 'blue'],
        ]);

        if (is_wp_error($result)) {
            $this->fail('Enrollment failed: ' . $result->get_error_message());
        }

        $this->assertIsArray($result, 'enrollment should succeed');
        $regId = (int) ($result['registration_id'] ?? 0);
        $this->assertGreaterThan(0, $regId, 'registration_id should be set');
        $this->testRegistrationIds[] = $regId;

        $row = ntdst_get(RegistrationRepository::class)->find($regId);
        $this->assertNotNull($row, 'registration row should exist');
        $enrollmentData = $row->enrollment_data ?? [];

        $this->assertArrayHasKey('enrollment_personal', $enrollmentData, 'must have enrollment_personal stage');
        $this->assertSame('blue', $enrollmentData['enrollment_personal']['data']['fav_color'] ?? null);
        $this->assertArrayNotHasKey('fav_color', $enrollmentData, 'must not persist fav_color at root');
    }
}
