<?php

declare(strict_types=1);

namespace Stride\Tests\Unit;

use Stride\Modules\Notification\NotificationMapper;
use Stride\Tests\TestCase;

class NotificationMapperTest extends TestCase
{
    /** @test */
    public function testMapsRegistrationCreatedToNotification(): void
    {
        $this->createEdition(['ID' => 789, 'post_title' => 'Test Editie']);

        $entry = (object) [
            'id' => 1,
            'entity_type' => 'registration',
            'entity_id' => 123,
            'action' => 'registration.created',
            'actor_id' => 999,
            'context' => json_encode(['user_id' => 456, 'edition_id' => 789]),
            'created_at' => '2026-03-10 14:30:00',
        ];

        $notification = NotificationMapper::fromAuditEntry($entry);

        $this->assertEquals('enrollment', $notification['type']);
        $this->assertStringContainsString('inschrijving', strtolower($notification['title']));
        $this->assertNotEmpty($notification['url']);
        $this->assertIsInt($notification['timestamp']);
    }

    /** @test */
    public function testMapsAttendanceMarkedPresentToNotification(): void
    {
        global $_test_post_meta;

        $this->createSession(['ID' => 200, 'post_title' => 'Sessie 1']);
        $_test_post_meta[200]['_ntdst_date'] = ['2026-03-15'];

        $this->createEdition(['ID' => 789, 'post_title' => 'Test Editie']);

        $entry = (object) [
            'id' => 2,
            'entity_type' => 'attendance',
            'entity_id' => 100,
            'action' => 'attendance.marked_present',
            'actor_id' => 999,
            'context' => json_encode(['user_id' => 456, 'session_id' => 200, 'edition_id' => 789]),
            'created_at' => '2026-03-10 14:30:00',
        ];

        $notification = NotificationMapper::fromAuditEntry($entry);

        $this->assertEquals('attendance', $notification['type']);
        $this->assertStringContainsString('aanwezigheid', strtolower($notification['title']));
    }

    /** @test */
    public function testMapsCourseCompletedToNotification(): void
    {
        $entry = (object) [
            'id' => 3,
            'entity_type' => 'completion',
            'entity_id' => 1001,
            'action' => 'completion.course_completed',
            'actor_id' => null,
            'context' => json_encode(['course_id' => 1001, 'course_title' => 'Test Cursus']),
            'created_at' => '2026-03-10 14:30:00',
        ];

        $notification = NotificationMapper::fromAuditEntry($entry);

        $this->assertEquals('completion', $notification['type']);
        $this->assertStringContainsString('Test Cursus', $notification['title']);
    }

    /** @test */
    public function testMapsCertificateIssuedToNotification(): void
    {
        $entry = (object) [
            'id' => 4,
            'entity_type' => 'completion',
            'entity_id' => 1001,
            'action' => 'completion.certificate_issued',
            'actor_id' => null,
            'context' => json_encode([
                'course_id' => 1001,
                'course_title' => 'Test Cursus',
                'certificate_link' => 'https://example.com/cert/123',
            ]),
            'created_at' => '2026-03-10 14:30:00',
        ];

        $notification = NotificationMapper::fromAuditEntry($entry);

        $this->assertEquals('certificate', $notification['type']);
        $this->assertStringContainsString('certificaat', strtolower($notification['title']));
    }

    /** @test */
    public function testMapsSessionNoteUpdatedToNotification(): void
    {
        global $_test_post_meta;

        $this->createSession(['ID' => 100, 'post_title' => 'Sessie 1']);
        $_test_post_meta[100]['_ntdst_date'] = ['2026-03-15'];

        $this->createEdition(['ID' => 789, 'post_title' => 'Test Editie']);

        $entry = (object) [
            'id' => 5,
            'entity_type' => 'session',
            'entity_id' => 100,
            'action' => 'session.note_updated',
            'actor_id' => 999,
            'context' => json_encode(['session_id' => 100, 'edition_id' => 789]),
            'created_at' => '2026-03-10 14:30:00',
        ];

        $notification = NotificationMapper::fromAuditEntry($entry);

        $this->assertEquals('session', $notification['type']);
        $this->assertStringContainsString('bijgewerkt', strtolower($notification['title']));
    }

    /** @test */
    public function testNotificationHasRequiredKeys(): void
    {
        $this->createEdition(['ID' => 789, 'post_title' => 'Test Editie']);

        $entry = (object) [
            'id' => 1,
            'entity_type' => 'registration',
            'entity_id' => 123,
            'action' => 'registration.created',
            'actor_id' => 999,
            'context' => json_encode(['user_id' => 456, 'edition_id' => 789]),
            'created_at' => '2026-03-10 14:30:00',
        ];

        $notification = NotificationMapper::fromAuditEntry($entry);

        $required = ['id', 'type', 'title', 'body', 'url', 'timestamp'];
        foreach ($required as $key) {
            $this->assertArrayHasKey($key, $notification, "Missing key: {$key}");
        }
    }

    /** @test */
    public function testMapsRegistrationCancelledWithCorrectMessage(): void
    {
        $this->createEdition(['ID' => 789, 'post_title' => 'Test Editie']);

        $entry = (object) [
            'id' => 6,
            'entity_type' => 'registration',
            'entity_id' => 123,
            'action' => 'registration.cancelled',
            'actor_id' => null,
            'context' => json_encode(['user_id' => 456, 'edition_id' => 789]),
            'created_at' => '2026-03-10 14:30:00',
        ];

        $notification = NotificationMapper::fromAuditEntry($entry);

        $this->assertEquals('enrollment', $notification['type']);
        $this->assertStringContainsString('geannuleerd', strtolower($notification['title']));
    }
}
