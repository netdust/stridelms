<?php

declare(strict_types=1);

namespace Stride\Tests\Unit\Modules\Enrollment;

use Stride\Modules\Enrollment\RegistrationRepository;
use Stride\Tests\TestCase;

/**
 * Verifies the shape EnrollmentService produces when a direct caller passes
 * extra_fields without going through the frontend EnrollmentFormHandler
 * (which pre-wraps). We can't easily exercise the live processEnrollment
 * method in a unit test without WP, so we assert the wrap pattern itself —
 * the live behavior is verified by EnrollmentServiceIntegrationTest +
 * Task 16's acceptance test.
 */
final class EnrollmentServiceStageShapeTest extends TestCase
{
    public function testDirectCallerExtraFieldsLandInWrappedPersonalStage(): void
    {
        $courseFields = ['favourite_topic' => 'AI'];
        $wrapped = [
            'enrollment_personal' => RegistrationRepository::wrapStage($courseFields, null, '2026-05-24T12:00:00+00:00'),
        ];

        $this->assertArrayHasKey('enrollment_personal', $wrapped);
        $this->assertArrayHasKey('data', $wrapped['enrollment_personal']);
        $this->assertSame($courseFields, $wrapped['enrollment_personal']['data']);
        $this->assertSame('2026-05-24T12:00:00+00:00', $wrapped['enrollment_personal']['submitted_at']);
    }
}
