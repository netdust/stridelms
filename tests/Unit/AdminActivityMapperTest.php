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

    public function test_collapses_usermeta_events_to_single_line(): void
    {
        $entry = (object) [
            'id' => 10,
            'action' => 'usermeta.updated',
            'actor_id' => 1,
            'context' => json_encode([]),
            'created_at' => '2026-03-25 10:00:00',
        ];
        $result = AdminActivityMapper::fromAuditEntry($entry, 'Pieter Janssen');
        $this->assertSame('user', $result['type']);
        $this->assertStringContainsString('Profielgegevens', $result['text']);
        $this->assertStringContainsString('Pieter Janssen', $result['text']);
    }

    public function test_maps_user_created_with_target(): void
    {
        $entry = (object) [
            'id' => 11,
            'action' => 'user.created',
            'actor_id' => 1,
            'context' => json_encode(['target_name' => 'Marie Claes']),
            'created_at' => '2026-03-25 10:00:00',
        ];
        $result = AdminActivityMapper::fromAuditEntry($entry, 'Admin');
        $this->assertSame('user', $result['type']);
        $this->assertStringContainsString('Marie Claes', $result['text']);
        $this->assertStringContainsString('aangemaakt', $result['text']);
        $this->assertStringContainsString('Admin', $result['text']);
    }

    public function test_maps_user_created_self_registered(): void
    {
        $entry = (object) [
            'id' => 12,
            'action' => 'user.created',
            'actor_id' => 5,
            'context' => json_encode([]),
            'created_at' => '2026-03-25 10:00:00',
        ];
        $result = AdminActivityMapper::fromAuditEntry($entry, 'Nieuwe Gebruiker');
        $this->assertSame('user', $result['type']);
        $this->assertStringContainsString('Nieuwe Gebruiker', $result['text']);
        $this->assertStringContainsString('aangemaakt', $result['text']);
    }

    public function test_maps_user_role_changed_with_from_to(): void
    {
        $entry = (object) [
            'id' => 13,
            'action' => 'user.role_changed',
            'actor_id' => 1,
            'context' => json_encode([
                'target_name' => 'Marie Claes',
                'from_role' => 'student',
                'to_role' => 'instructor',
            ]),
            'created_at' => '2026-03-25 10:00:00',
        ];
        $result = AdminActivityMapper::fromAuditEntry($entry, 'Admin');
        $this->assertSame('user', $result['type']);
        $this->assertStringContainsString('Marie Claes', $result['text']);
        $this->assertStringContainsString('student', $result['text']);
        $this->assertStringContainsString('instructor', $result['text']);
    }

    public function test_maps_auth_login(): void
    {
        $entry = (object) [
            'id' => 14,
            'action' => 'auth.login',
            'actor_id' => 5,
            'context' => '{}',
            'created_at' => '2026-03-25 10:00:00',
        ];
        $result = AdminActivityMapper::fromAuditEntry($entry, 'Pieter Janssen');
        $this->assertSame('auth', $result['type']);
        $this->assertStringContainsString('Pieter Janssen', $result['text']);
        $this->assertStringContainsString('ingelogd', $result['text']);
    }

    public function test_maps_auth_logout(): void
    {
        $entry = (object) [
            'id' => 15,
            'action' => 'auth.logout',
            'actor_id' => 5,
            'context' => '{}',
            'created_at' => '2026-03-25 10:00:00',
        ];
        $result = AdminActivityMapper::fromAuditEntry($entry, 'Pieter Janssen');
        $this->assertSame('auth', $result['type']);
        $this->assertStringContainsString('uitgelogd', $result['text']);
    }

    public function test_maps_edition_created(): void
    {
        $entry = (object) [
            'id' => 16,
            'action' => 'edition.created',
            'actor_id' => 1,
            'context' => json_encode(['edition_title' => 'Excel Basis']),
            'created_at' => '2026-03-25 10:00:00',
        ];
        $result = AdminActivityMapper::fromAuditEntry($entry, 'Admin');
        $this->assertSame('edition', $result['type']);
        $this->assertStringContainsString('Excel Basis', $result['text']);
    }

    public function test_maps_impersonation_started(): void
    {
        $entry = (object) [
            'id' => 17,
            'action' => 'impersonation.started',
            'actor_id' => 1,
            'context' => json_encode(['target_name' => 'Pieter Janssen']),
            'created_at' => '2026-03-25 10:00:00',
        ];
        $result = AdminActivityMapper::fromAuditEntry($entry, 'Admin');
        $this->assertSame('user', $result['type']);
        $this->assertStringContainsString('Admin', $result['text']);
        $this->assertStringContainsString('Pieter Janssen', $result['text']);
    }

    public function test_user_event_with_unresolved_target_shows_deleted_account_text(): void
    {
        // Audit references a user that no longer exists: actor_id=0 → 'Systeem', no target_name in context
        $entry = (object) [
            'id' => 18,
            'action' => 'user.created',
            'actor_id' => 0,
            'context' => '{}',
            'created_at' => '2026-03-25 10:00:00',
        ];
        $result = AdminActivityMapper::fromAuditEntry($entry, 'Systeem');
        $this->assertSame('user', $result['type']);
        $this->assertStringContainsString('niet meer beschikbaar', $result['text']);
    }

    public function test_user_event_with_resolved_target_name(): void
    {
        // Controller resolved entity_id → display_name and passed it in
        $entry = (object) [
            'id' => 19,
            'action' => 'user.created',
            'actor_id' => 1,
            'context' => '{}',
            'created_at' => '2026-03-25 10:00:00',
        ];
        $result = AdminActivityMapper::fromAuditEntry($entry, 'Admin', 'Marie Claes');
        $this->assertSame('user', $result['type']);
        $this->assertStringContainsString('Marie Claes', $result['text']);
        $this->assertStringContainsString('aangemaakt', $result['text']);
    }

    public function test_maps_session_selections_updated(): void
    {
        // Audit entry as written by AuditBridge::onSessionSelectionsUpdated,
        // with edition_title enriched by the controller (same as other edition-scoped events).
        $entry = (object) [
            'id' => 20,
            'action' => 'session.selections_updated',
            'actor_id' => 42,
            'context' => json_encode([
                'registration_id' => 7,
                'edition_id' => 55,
                'edition_title' => 'EHBO Voortgezet',
                'session_ids' => [101, 102],
            ]),
            'created_at' => '2026-03-25 14:30:00',
        ];
        $result = AdminActivityMapper::fromAuditEntry($entry, 'Jan Peeters');

        // Must render a non-empty Dutch label naming the edition — not the empty/fallback arm.
        $this->assertNotSame('', $result['text']);
        $this->assertStringContainsString('Sessies gekozen', $result['text']);
        $this->assertStringContainsString('EHBO Voortgezet', $result['text']);
        $this->assertSame('enrollment', $result['type']);
        // Actor + timestamp carry through from the entry.
        $this->assertSame('Jan Peeters', $result['actor_name']);
        $this->assertSame(strtotime('2026-03-25 14:30:00'), $result['timestamp']);
        // And the action must be recognised, or the controller drops it before it ever reaches resolve().
        $this->assertTrue(AdminActivityMapper::isKnownAction($entry));
    }

    public function test_known_action_includes_new_events(): void
    {
        $newActions = ['auth.login', 'auth.logout', 'user.deleted', 'user.role_changed', 'user.profile_updated'];
        foreach ($newActions as $action) {
            $entry = (object) ['action' => $action];
            $this->assertTrue(
                AdminActivityMapper::isKnownAction($entry),
                "isKnownAction should return true for {$action}",
            );
        }
    }
}
