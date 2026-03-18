<?php

declare(strict_types=1);

namespace Stride\Tests\Unit;

use NTDST\Audit\AuditService;
use Stride\Modules\Audit\AuditBridge;
use Stride\Tests\TestCase;
use WP_User;

/**
 * Unit tests for AuditBridge
 *
 * Tests the event handlers that bridge Stride events to ntdst-audit.
 */
class AuditBridgeTest extends TestCase
{
    private AuditService $mockAuditService;
    private AuditBridge $bridge;

    protected function setUp(): void
    {
        parent::setUp();

        // Create mock AuditService that captures record() calls
        $this->mockAuditService = new AuditService();

        // Register in test container
        $this->registerService(AuditService::class, $this->mockAuditService);

        // Create AuditBridge instance without calling constructor
        // This avoids init() registering WordPress hooks
        $reflection = new \ReflectionClass(AuditBridge::class);
        $this->bridge = $reflection->newInstanceWithoutConstructor();
    }

    /**
     * @test
     */
    public function testMetadataReturnsCorrectStructure(): void
    {
        $metadata = AuditBridge::metadata();

        $this->assertIsArray($metadata);
        $this->assertArrayHasKey('name', $metadata);
        $this->assertArrayHasKey('description', $metadata);
        $this->assertArrayHasKey('priority', $metadata);

        $this->assertEquals('Audit Bridge', $metadata['name']);
        $this->assertEquals('Connects Stride events to NTDST Audit plugin', $metadata['description']);
        $this->assertEquals(99, $metadata['priority']);
    }

    /**
     * @test
     */
    public function testOnRegistrationCreatedRecordsCorrectData(): void
    {
        $data = [
            'registration_id' => 123,
            'user_id' => 456,
            'edition_id' => 789,
            'enrollment_path' => 'individual',
        ];

        $this->bridge->onRegistrationCreated($data);

        $calls = $this->mockAuditService->getRecordedCalls();
        $this->assertCount(1, $calls);

        $call = $calls[0];
        $this->assertEquals('registration', $call['entity_type']);
        $this->assertEquals(123, $call['entity_id']);
        $this->assertEquals('registration.created', $call['action']);
        $this->assertEquals(456, $call['actor_id']); // user_id as actor when no enrolled_by
        $this->assertEquals([
            'user_id' => 456,
            'edition_id' => 789,
            'enrollment_path' => 'individual',
        ], $call['context']);
    }

    /**
     * @test
     */
    public function testOnRegistrationCreatedUsesEnrolledByAsActor(): void
    {
        $data = [
            'registration_id' => 123,
            'user_id' => 456,
            'enrolled_by' => 999, // Admin enrolling on behalf of user
            'edition_id' => 789,
            'enrollment_path' => 'admin',
        ];

        $this->bridge->onRegistrationCreated($data);

        $calls = $this->mockAuditService->getRecordedCalls();
        $this->assertCount(1, $calls);

        $call = $calls[0];
        // enrolled_by should take precedence over user_id for actor
        $this->assertEquals(999, $call['actor_id']);
        $this->assertEquals(456, $call['context']['user_id']);
    }

    /**
     * @test
     */
    public function testOnRegistrationCreatedWithDefaultEnrollmentPath(): void
    {
        $data = [
            'registration_id' => 123,
            'user_id' => 456,
            'edition_id' => 789,
            // No enrollment_path provided - should default to 'individual'
        ];

        $this->bridge->onRegistrationCreated($data);

        $calls = $this->mockAuditService->getRecordedCalls();
        $this->assertEquals('individual', $calls[0]['context']['enrollment_path']);
    }

    /**
     * @test
     */
    public function testOnRegistrationCancelledRecordsCorrectData(): void
    {
        $data = [
            'registration_id' => 123,
            'user_id' => 456,
            'edition_id' => 789,
        ];

        $this->bridge->onRegistrationCancelled($data);

        $calls = $this->mockAuditService->getRecordedCalls();
        $this->assertCount(1, $calls);

        $call = $calls[0];
        $this->assertEquals('registration', $call['entity_type']);
        $this->assertEquals(123, $call['entity_id']);
        $this->assertEquals('registration.cancelled', $call['action']);
        $this->assertNull($call['actor_id']); // No actor for cancellation
        $this->assertEquals([
            'user_id' => 456,
            'edition_id' => 789,
        ], $call['context']);
    }

    /**
     * @test
     * @dataProvider attendanceStatusProvider
     */
    public function testOnAttendanceMarkedMapsStatusToAction(string $status, string $expectedAction): void
    {
        $data = [
            'attendance_id' => 100,
            'session_id' => 200,
            'user_id' => 300,
            'edition_id' => 400,
            'status' => $status,
            'marked_by' => 500,
        ];

        $this->bridge->onAttendanceMarked($data);

        $calls = $this->mockAuditService->getRecordedCalls();
        $this->assertCount(1, $calls);

        $call = $calls[0];
        $this->assertEquals('attendance', $call['entity_type']);
        $this->assertEquals(100, $call['entity_id']);
        $this->assertEquals($expectedAction, $call['action']);
        $this->assertEquals(500, $call['actor_id']);
        $this->assertEquals([
            'session_id' => 200,
            'user_id' => 300,
            'edition_id' => 400,
            'status' => $status,
        ], $call['context']);
    }

    /**
     * Data provider for attendance status mapping tests
     */
    public static function attendanceStatusProvider(): array
    {
        return [
            'present status' => ['present', 'attendance.marked_present'],
            'absent status' => ['absent', 'attendance.marked_absent'],
            'excused status' => ['excused', 'attendance.marked_excused'],
            'unknown status falls back to default' => ['unknown', 'attendance.marked'],
        ];
    }

    /**
     * @test
     */
    public function testOnAttendanceMarkedWithNoStatusDefaults(): void
    {
        $data = [
            'attendance_id' => 100,
            'session_id' => 200,
            'user_id' => 300,
            'edition_id' => 400,
            // No status - should default to 'present'
            'marked_by' => 500,
        ];

        $this->bridge->onAttendanceMarked($data);

        $calls = $this->mockAuditService->getRecordedCalls();
        $this->assertEquals('attendance.marked_present', $calls[0]['action']);
    }

    /**
     * @test
     */
    public function testOnAttendanceMarkedWithNoMarkedBy(): void
    {
        $data = [
            'attendance_id' => 100,
            'session_id' => 200,
            'user_id' => 300,
            'edition_id' => 400,
            'status' => 'present',
            // No marked_by
        ];

        $this->bridge->onAttendanceMarked($data);

        $calls = $this->mockAuditService->getRecordedCalls();
        $this->assertNull($calls[0]['actor_id']);
    }

    /**
     * @test
     */
    public function testOnCourseCompletedRecordsCompletion(): void
    {
        $course = (object) [
            'ID' => 1001,
            'post_title' => 'Test Course Title',
        ];

        // LearnDash passes a single array with user inside
        $data = [
            'user' => new WP_User(['ID' => 456]),
            'course' => $course,
        ];

        $this->bridge->onCourseCompleted($data);

        $calls = $this->mockAuditService->getRecordedCalls();
        $this->assertCount(1, $calls);

        $call = $calls[0];
        $this->assertEquals('completion', $call['entity_type']);
        $this->assertEquals(1001, $call['entity_id']);
        $this->assertEquals('completion.course_completed', $call['action']);
        $this->assertEquals(456, $call['actor_id']);
        $this->assertEquals([
            'course_id' => 1001,
            'course_title' => 'Test Course Title',
        ], $call['context']);
    }

    /**
     * @test
     */
    public function testOnCourseCompletedWithCourseIdFallback(): void
    {
        // When course object is not fully available, fall back to course_id
        $data = [
            'user' => new WP_User(['ID' => 456]),
            'course_id' => 2002,
        ];

        $this->bridge->onCourseCompleted($data);

        $calls = $this->mockAuditService->getRecordedCalls();
        $this->assertCount(1, $calls);

        $call = $calls[0];
        $this->assertEquals(2002, $call['entity_id']);
        $this->assertEquals(2002, $call['context']['course_id']);
        $this->assertEquals('', $call['context']['course_title']); // No title available
    }

    /**
     * @test
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testOnCourseCompletedWithCertificate(): void
    {
        // Reset test state for isolated process
        $this->mockAuditService = new AuditService();
        $this->registerService(AuditService::class, $this->mockAuditService);

        $reflection = new \ReflectionClass(AuditBridge::class);
        $this->bridge = $reflection->newInstanceWithoutConstructor();

        // Define the LearnDash function for this test (only works in separate process)
        if (!function_exists('learndash_get_course_certificate_link')) {
            eval('
                function learndash_get_course_certificate_link(int $courseId, int $userId): string
                {
                    return "https://example.com/certificate/" . $courseId . "/" . $userId;
                }
            ');
        }

        $course = (object) [
            'ID' => 1001,
            'post_title' => 'Test Course With Certificate',
        ];

        $data = [
            'user' => new WP_User(['ID' => 456]),
            'course' => $course,
        ];

        $this->bridge->onCourseCompleted($data);

        $calls = $this->mockAuditService->getRecordedCalls();
        // Should record both completion and certificate issuance
        $this->assertCount(2, $calls);

        // First call: course completion
        $this->assertEquals('completion.course_completed', $calls[0]['action']);

        // Second call: certificate issued
        $call = $calls[1];
        $this->assertEquals('completion', $call['entity_type']);
        $this->assertEquals(1001, $call['entity_id']);
        $this->assertEquals('completion.certificate_issued', $call['action']);
        $this->assertEquals(456, $call['actor_id']);
        $this->assertArrayHasKey('certificate_link', $call['context']);
        $this->assertEquals('https://example.com/certificate/1001/456', $call['context']['certificate_link']);
    }

    /** @test */
    public function testOnSessionNoteUpdatedRecordsCorrectData(): void
    {
        $data = [
            'session_id' => 100,
            'edition_id' => 200,
        ];

        $this->bridge->onSessionNoteUpdated($data);

        $calls = $this->mockAuditService->getRecordedCalls();
        $this->assertCount(1, $calls);

        $call = $calls[0];
        $this->assertEquals('session', $call['entity_type']);
        $this->assertEquals(100, $call['entity_id']);
        $this->assertEquals('session.note_updated', $call['action']);
        $this->assertEquals([
            'session_id' => 100,
            'edition_id' => 200,
        ], $call['context']);
    }
}
