<?php

declare(strict_types=1);

namespace Stride\Tests\Integration;

use IntegrationTestCase;
use NTDST\Audit\AuditService;
use NTDST\Audit\AuditTable;
use Stride\Admin\AdminActivityService;

/**
 * Characterization pin for the getActivityFeed / getHealthChecks /
 * getNotifications / markNotificationsRead -> AdminActivityService strangle (Task D4).
 *
 * Task D4 relocates the activity-feed audit-log SELECT, the health-check
 * assembly, and the notifications read + mark-read roundtrip out of
 * AdminAPIController into AdminActivityService (read-model assembly, INV-3). The
 * audit-log table reads stay as $wpdb->prepare()d queries inside the service: the
 * audit_log table is a cross-cutting NTDST table whose own NTDST\Audit\AuditRepository
 * exposes only entity/actor/date-range finders — none of which match the
 * "latest N across all actions" / "latest N for a notification action set" /
 * "MAX(id)" / "MAX(created_at) WHERE action" shapes these endpoints need. The
 * freshest sibling (AdminUserService::getUserDetail, §12.4/S2) already set the
 * precedent of keeping a prepared audit-log read in the service rather than
 * widening the cross-cutting repo; D4 mirrors it. Routing these shapes through
 * AuditRepository is logged as a follow-up.
 *
 * The move MUST be behavior-preserving. This is the safety net — it pins:
 *   1. ACTIVITY FEED — known-action filtering, actor enrichment (display name vs
 *      "Systeem"), target-name resolution for user.* events, the
 *      session.selections_updated mapping, the DESC + LIMIT ordering, and the
 *      empty-table short-circuit.
 *   2. HEALTH CHECKS — the three-key shape {registration, mail, audit} the
 *      HealthCheckService emits.
 *   3. NOTIFICATIONS — the action-allowlist filter, the 10-row cap, actor-name
 *      hydration, the read/unread flag vs the stored last-read id, and the
 *      unread_count envelope.
 *   4. MARK-READ — stores MAX(audit id) into ONLY the calling user's user-meta
 *      (a security property: no cross-user write) and flips unread to 0.
 *
 * Run: ddev exec vendor/bin/phpunit -c phpunit-integration.xml.dist --filter AdminActivityService
 */
final class AdminActivityServiceTest extends IntegrationTestCase
{
    private AdminActivityService $service;
    private AuditService $audit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(self::$testUserId);
        $this->service = ntdst_get(AdminActivityService::class);
        $this->audit = ntdst_get(AuditService::class);
        $this->cleanAudit();
        delete_user_meta(self::$testUserId, 'stride_last_read_notification_id');
    }

    protected function tearDown(): void
    {
        $this->cleanAudit();
        parent::tearDown();
    }

    private function cleanAudit(): void
    {
        global $wpdb;
        $table = AuditTable::getTableName();
        $wpdb->query("DELETE FROM {$table}");
    }

    // =========================================================================
    // 1. ACTIVITY FEED
    // =========================================================================

    /** @test */
    public function activityFeedReturnsEmptyArrayWhenNoEntries(): void
    {
        $this->assertSame([], $this->service->getActivityFeed(['limit' => 10]));
    }

    /** @test */
    public function activityFeedEnrichesActorAndFiltersUnknownActions(): void
    {
        $actorId = self::$testUserId;
        wp_update_user(['ID' => $actorId, 'display_name' => 'Activity Actor']);
        $editionId = $this->createTestEdition(['post_title' => 'Feed Edition']);

        // KNOWN action with a real actor -> enriched display name.
        $this->audit->record('edition', $editionId, 'registration.created', $actorId, [
            'edition_id' => $editionId,
        ]);
        // UNKNOWN/system action -> must be filtered out of the feed.
        $this->audit->record('system', 0, 'assistant.stride/get-editions', $actorId, []);

        // NB: wp_create_user/wp_update_user in the fixtures themselves fire KNOWN
        // audit actions (user.created / user.role_changed / user.profile_updated),
        // so we assert on the seeded line's CONTENT, not the total feed size.
        $feed = $this->service->getActivityFeed(['limit' => 50]);

        $registration = array_values(array_filter(
            $feed,
            static fn($line) => str_contains($line['text'], 'Feed Edition'),
        ));
        $this->assertCount(1, $registration, 'the known registration action surfaces, enriched');
        $this->assertSame('enrollment', $registration[0]['type']);
        $this->assertSame('Activity Actor', $registration[0]['actor_name']);

        // The unknown/system action must NOT surface (known-action filter).
        $texts = array_map(static fn($l) => $l['text'], $feed);
        foreach ($texts as $text) {
            $this->assertStringNotContainsString('get-editions', $text, 'unknown action filtered out');
        }
    }

    /** @test */
    public function activityFeedResolvesTargetNameForUserEvents(): void
    {
        $actorId = self::$testUserId;
        wp_update_user(['ID' => $actorId, 'display_name' => 'Admin Actor']);

        $targetId = (int) wp_create_user('act_target_' . uniqid(), 'pass123', 'tgt_' . uniqid() . '@test.local');
        wp_update_user(['ID' => $targetId, 'display_name' => 'Target Person']);

        $this->audit->record('user', $targetId, 'user.profile_updated', $actorId, [
            'target_user_id' => $targetId,
        ]);

        $feed = $this->service->getActivityFeed(['limit' => 50]);

        // AdminActivityMapper resolves entity_id -> "{target} bijgewerkt door {actor}".
        // Find the seeded line by the resolved target display name.
        $matched = array_values(array_filter(
            $feed,
            static fn($line) => str_contains($line['text'], 'Target Person'),
        ));
        $this->assertNotEmpty($matched, 'target name resolved from entity_id for user.* events');
        $this->assertStringContainsString('Target Person', $matched[0]['text']);

        require_once ABSPATH . 'wp-admin/includes/user.php';
        wp_delete_user($targetId);
    }

    /** @test */
    public function activityFeedMapsSessionSelectionsUpdated(): void
    {
        $editionId = $this->createTestEdition(['post_title' => 'Keuze Editie']);
        $this->audit->record('edition', $editionId, 'session.selections_updated', self::$testUserId, [
            'edition_id' => $editionId,
        ]);

        $feed = $this->service->getActivityFeed(['limit' => 10]);

        $this->assertCount(1, $feed);
        $this->assertSame('enrollment', $feed[0]['type']);
        $this->assertSame('Sessies gekozen voor Keuze Editie', $feed[0]['text']);
    }

    /** @test */
    public function activityFeedHonoursLimitAndDescOrdering(): void
    {
        $editionId = $this->createTestEdition(['post_title' => 'Limit Editie']);
        for ($i = 0; $i < 4; $i++) {
            $this->audit->record('edition', $editionId, 'registration.created', self::$testUserId, [
                'edition_id' => $editionId,
            ]);
        }

        $feed = $this->service->getActivityFeed(['limit' => 2]);
        $this->assertCount(2, $feed, 'limit caps the feed');

        // DESC by created_at then id: the two newest rows surface first.
        $this->assertGreaterThan(0, $feed[0]['id']);
        $this->assertGreaterThanOrEqual($feed[1]['id'], $feed[0]['id'], 'newest first');
    }

    // =========================================================================
    // 2. HEALTH CHECKS
    // =========================================================================

    /** @test */
    public function healthChecksReturnTheThreeKeyShape(): void
    {
        $checks = $this->service->getHealthChecks();

        $this->assertArrayHasKey('registration', $checks);
        $this->assertArrayHasKey('mail', $checks);
        $this->assertArrayHasKey('audit', $checks);
        // audit is green when the AuditService class is loaded (it is, in DDEV).
        $this->assertContains($checks['audit'], ['green', 'red']);
        $this->assertContains($checks['registration'], ['green', 'amber']);
        $this->assertContains($checks['mail'], ['green', 'amber']);
    }

    // =========================================================================
    // 3. NOTIFICATIONS
    // =========================================================================

    /** @test */
    public function notificationsFilterToAllowlistAndHydrateActor(): void
    {
        $actorId = self::$testUserId;
        wp_update_user(['ID' => $actorId, 'display_name' => 'Notif Actor']);
        $editionId = $this->createTestEdition(['post_title' => 'Notif Editie']);

        // INCLUDED — registration.created is a notification-worthy action.
        $this->audit->record('edition', $editionId, 'registration.created', $actorId, [
            'edition_id' => $editionId,
        ]);
        // EXCLUDED — auth.login is in the activity feed but NOT a notification.
        $this->audit->record('user', $actorId, 'auth.login', $actorId, []);

        $result = $this->service->getNotifications();

        $this->assertArrayHasKey('notifications', $result);
        $this->assertArrayHasKey('unread_count', $result);
        $this->assertCount(1, $result['notifications'], 'only allowlisted action surfaces');
        $this->assertSame('Notif Actor', $result['notifications'][0]['actor_name']);
        $this->assertFalse($result['notifications'][0]['read'], 'unread before mark-read');
        $this->assertSame(1, $result['unread_count']);
    }

    /** @test */
    public function notificationsCapAtTenRows(): void
    {
        $editionId = $this->createTestEdition(['post_title' => 'Cap Editie']);
        for ($i = 0; $i < 13; $i++) {
            $this->audit->record('edition', $editionId, 'quote.created', self::$testUserId, [
                'edition_id' => $editionId,
            ]);
        }

        $result = $this->service->getNotifications();
        $this->assertCount(10, $result['notifications'], '10-row cap');
    }

    /** @test */
    public function markReadFlipsUnreadToZeroForCallerOnly(): void
    {
        $editionId = $this->createTestEdition(['post_title' => 'Read Editie']);
        $this->audit->record('edition', $editionId, 'registration.created', self::$testUserId, [
            'edition_id' => $editionId,
        ]);

        // Before: one unread.
        $before = $this->service->getNotifications();
        $this->assertSame(1, $before['unread_count']);

        // Another admin user whose own read-state must NOT be touched.
        $otherAdmin = (int) wp_create_user('other_admin_' . uniqid(), 'pass123', 'oa_' . uniqid() . '@test.local');
        update_user_meta($otherAdmin, 'stride_last_read_notification_id', 0);

        $ok = $this->service->markNotificationsRead();
        $this->assertTrue($ok);

        // Caller is now caught up.
        $after = $this->service->getNotifications();
        $this->assertSame(0, $after['unread_count'], 'caller is caught up after mark-read');

        // SECURITY: the other admin's last-read id was NOT advanced.
        $this->assertSame(
            '0',
            (string) get_user_meta($otherAdmin, 'stride_last_read_notification_id', true),
            'mark-read must only touch the calling user meta (no cross-user write)',
        );

        require_once ABSPATH . 'wp-admin/includes/user.php';
        wp_delete_user($otherAdmin);
    }
}
