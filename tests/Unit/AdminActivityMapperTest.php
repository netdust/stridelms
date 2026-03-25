<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Stride\Admin\AdminActivityMapper;

class AdminActivityMapperTest extends TestCase
{
    public function test_maps_registration_created_to_admin_perspective(): void
    {
        $entry = (object) [
            'id' => 1,
            'action' => 'registration.created',
            'actor_id' => 42,
            'context' => json_encode(['edition_id' => 55, 'edition_title' => 'Excel Basis']),
            'created_at' => '2026-03-25 10:30:00',
        ];
        $result = AdminActivityMapper::fromAuditEntry($entry, 'Jan Peeters');
        $this->assertSame('enrollment', $result['type']);
        $this->assertStringContainsString('Jan Peeters', $result['text']);
        $this->assertStringContainsString('Excel Basis', $result['text']);
    }

    public function test_maps_registration_cancelled(): void
    {
        $entry = (object) [
            'id' => 2,
            'action' => 'registration.cancelled',
            'actor_id' => 42,
            'context' => json_encode(['edition_title' => 'Excel Basis']),
            'created_at' => '2026-03-25 11:00:00',
        ];
        $result = AdminActivityMapper::fromAuditEntry($entry, 'Jan Peeters');
        $this->assertSame('enrollment', $result['type']);
        $this->assertStringContainsString('geannuleerd', $result['text']);
    }

    public function test_maps_attendance_marked_present(): void
    {
        $entry = (object) [
            'id' => 3,
            'action' => 'attendance.marked_present',
            'actor_id' => 1,
            'context' => json_encode(['edition_title' => 'EHBO', 'user_name' => 'Marie Claes']),
            'created_at' => '2026-03-25 11:00:00',
        ];
        $result = AdminActivityMapper::fromAuditEntry($entry, 'Admin');
        $this->assertSame('attendance', $result['type']);
        $this->assertStringContainsString('Marie Claes', $result['text']);
    }

    public function test_maps_course_completed(): void
    {
        $entry = (object) [
            'id' => 4,
            'action' => 'completion.course_completed',
            'actor_id' => 42,
            'context' => json_encode(['edition_title' => 'Excel Basis']),
            'created_at' => '2026-03-25 12:00:00',
        ];
        $result = AdminActivityMapper::fromAuditEntry($entry, 'Jan Peeters');
        $this->assertSame('completion', $result['type']);
        $this->assertStringContainsString('afgerond', $result['text']);
    }

    public function test_maps_quote_sent(): void
    {
        $entry = (object) [
            'id' => 5,
            'action' => 'quote.sent',
            'actor_id' => 1,
            'context' => json_encode([]),
            'created_at' => '2026-03-25 13:00:00',
        ];
        $result = AdminActivityMapper::fromAuditEntry($entry, 'Jan Peeters');
        $this->assertSame('quote', $result['type']);
        $this->assertStringContainsString('verzonden', $result['text']);
    }

    public function test_returns_fallback_for_unknown_action(): void
    {
        $entry = (object) [
            'id' => 6,
            'action' => 'unknown.action',
            'actor_id' => 1,
            'context' => '{}',
            'created_at' => '2026-03-25 12:00:00',
        ];
        $result = AdminActivityMapper::fromAuditEntry($entry, 'Admin');
        $this->assertSame('action', $result['type']);
    }

    public function test_returns_correct_structure(): void
    {
        $entry = (object) [
            'id' => 7,
            'action' => 'registration.created',
            'actor_id' => 42,
            'context' => json_encode(['edition_title' => 'Test']),
            'created_at' => '2026-03-25 10:00:00',
        ];
        $result = AdminActivityMapper::fromAuditEntry($entry, 'Test User');
        $this->assertArrayHasKey('id', $result);
        $this->assertArrayHasKey('type', $result);
        $this->assertArrayHasKey('text', $result);
        $this->assertArrayHasKey('actor_name', $result);
        $this->assertArrayHasKey('timestamp', $result);
        $this->assertIsInt($result['id']);
        $this->assertIsInt($result['timestamp']);
    }
}
