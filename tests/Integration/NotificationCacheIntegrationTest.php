<?php

declare(strict_types=1);

namespace Stride\Tests\Integration;

use IntegrationTestCase;
use NTDST\Audit\AuditService;
use Stride\Modules\Notification\NotificationService;

/**
 * Integration tests for the cached unread-notification count (audit H-4 / task F1).
 *
 * Contract:
 *  - getUnreadCount() primes a per-user transient (the dashboard badge no
 *    longer hits the audit table on every page view).
 *  - A new subject-targeted audit event invalidates the cache through the
 *    REAL chain (AuditService::record → ntdst/audit/recorded →
 *    NotificationService listener) — a stale cached count never survives a
 *    new event (the cache-correctness denial path).
 *  - mail.sent never increments unread, even for the adversarial/historical
 *    row shape that DOES carry context.user_id.
 *  - markAllRead() invalidates the cached count.
 *
 * Run: ddev exec "vendor/bin/phpunit -c phpunit-integration.xml.dist --filter NotificationCacheIntegration"
 */
final class NotificationCacheIntegrationTest extends IntegrationTestCase
{
    private AuditService $audit;

    /** @var array<int> */
    private array $auditRowIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->audit = ntdst_get(AuditService::class);

        // Actor must be "system" (NULL) so the seeded events count as
        // subject-targeted, not self-actions.
        wp_set_current_user(0);

        delete_transient($this->transientKey());
        delete_user_meta(self::$testUserId, '_stride_notifications_read');
    }

    protected function tearDown(): void
    {
        global $wpdb;

        if ($this->auditRowIds !== []) {
            $ids = implode(',', array_map('intval', $this->auditRowIds));
            $wpdb->query("DELETE FROM {$wpdb->prefix}audit_log WHERE id IN ({$ids})");
            $this->auditRowIds = [];
        }

        delete_transient($this->transientKey());
        delete_user_meta(self::$testUserId, '_stride_notifications_read');

        parent::tearDown();
    }

    /** @test */
    public function unreadCountPrimesTransientAndNewEventInvalidatesIt(): void
    {
        $before = $this->freshService()->getUnreadCount(self::$testUserId);

        $this->assertNotFalse(
            get_transient($this->transientKey()),
            'getUnreadCount() must prime the per-user transient',
        );

        // New subject-targeted event through the REAL chain (un-mocked seam).
        $this->recordAudit('registration', 'registration.created', [
            'user_id' => self::$testUserId,
            'edition_id' => 0,
        ]);

        $this->assertFalse(
            get_transient($this->transientKey()),
            'Denial path: a stale cached count must never survive a new subject-targeted event',
        );

        $after = $this->freshService()->getUnreadCount(self::$testUserId);

        $this->assertSame($before + 1, $after, 'New subject-targeted event must bump the unread count');
    }

    /** @test */
    public function completionEventInvalidatesActorBadgeCache(): void
    {
        $before = $this->freshService()->getUnreadCount(self::$testUserId);

        $this->assertNotFalse(
            get_transient($this->transientKey()),
            'getUnreadCount() must prime the per-user transient',
        );

        // CR-F2: completion.* events carry NO context.user_id — the subject
        // user surfaces via the badge query's ACTOR branch (actor_id = X AND
        // action LIKE 'completion.%'). The listener must mirror that branch
        // and invalidate the ACTOR's cache, or a fresh certificate leaves the
        // badge stale until the TTL lapses.
        $id = $this->audit->record('completion', 123, 'completion.course_completed', self::$testUserId, [
            'course_id' => 123,
            'course_title' => 'PHPUnit cursus',
        ]);

        $this->assertIsInt($id, 'Audit record must succeed');
        $this->auditRowIds[] = $id;

        $this->assertFalse(
            get_transient($this->transientKey()),
            'CR-F2 denial path: a completion event must invalidate the actor\'s cached badge count',
        );

        $after = $this->freshService()->getUnreadCount(self::$testUserId);

        $this->assertSame($before + 1, $after, 'A fresh completion must bump the unread badge');
    }

    /** @test */
    public function mailSentDoesNotIncrementUnread(): void
    {
        $before = $this->freshService()->getUnreadCount(self::$testUserId);

        // Adversarial/historical shape: a mail.sent row that DOES carry
        // context.user_id (current AuditBridge omits it, old rows may not).
        $this->recordAudit('mail', 'mail.sent', [
            'user_id' => self::$testUserId,
            'template' => 'phpunit-template',
            'to' => 'phpunit@test.local',
        ]);

        $after = $this->freshService()->getUnreadCount(self::$testUserId);

        $this->assertSame($before, $after, 'mail.sent must never increment the unread count');
    }

    /** @test */
    public function markAllReadInvalidatesCachedCount(): void
    {
        $this->recordAudit('registration', 'registration.created', [
            'user_id' => self::$testUserId,
            'edition_id' => 0,
        ]);

        $count = $this->freshService()->getUnreadCount(self::$testUserId);
        $this->assertGreaterThan(0, $count);
        $this->assertNotFalse(get_transient($this->transientKey()));

        $this->freshService()->markAllRead(self::$testUserId);

        $this->assertFalse(
            get_transient($this->transientKey()),
            'markAllRead() must invalidate the cached count',
        );
        $this->assertSame(0, $this->freshService()->getUnreadCount(self::$testUserId));
    }

    /**
     * @test
     * The Meldingen tab snapshots getNotifications() (with this-load read
     * flags) BEFORE calling markAllRead(), so the current render keeps its
     * unread accents while the badge clears for next load. Pin that ordering:
     * the snapshot is unaffected by the subsequent mark, and the count goes 0.
     */
    public function snapshotBeforeMarkKeepsRenderFlagsAndClearsBadge(): void
    {
        $this->recordAudit('registration', 'registration.created', [
            'user_id' => self::$testUserId,
            'edition_id' => 0,
        ]);

        $svc = $this->freshService();

        // 1. Snapshot — as the tab template does before marking.
        $snapshot = $svc->getNotifications(self::$testUserId);
        $this->assertNotEmpty($snapshot, 'fixture must produce at least one notification');
        $unreadInSnapshot = array_filter($snapshot, fn(array $n): bool => !$n['read']);
        $this->assertNotEmpty($unreadInSnapshot, 'arrival render must still show unread items');

        // 2. Mark — the auto-mark-read on tab view.
        $svc->markAllRead(self::$testUserId);

        // 3. This-load-vs-next-load divergence: the pre-mark snapshot still
        //    shows its items UNREAD (so the arrival render keeps its accents),
        //    while a FRESH read after the mark shows them READ. This is the
        //    behaviour the auto-mark-read UX depends on — and it only holds
        //    because getNotifications() returns a by-value snapshot, so the
        //    mark cannot retroactively flip the array already handed to render.
        foreach ($unreadInSnapshot as $n) {
            $this->assertFalse($n['read'], 'arrival snapshot must keep its unread flags after the mark');
        }
        $fresh = $this->freshService()->getNotifications(self::$testUserId);
        $this->assertNotEmpty($fresh);
        foreach ($fresh as $n) {
            $this->assertTrue($n['read'], 'a fresh read after markAllRead must show items as read');
        }

        // 4. Badge clears for next load.
        $this->assertSame(0, $this->freshService()->getUnreadCount(self::$testUserId));
    }

    /**
     * @test
     * Empty edge: no notifications → markAllRead is a safe no-op, count stays 0.
     */
    public function markAllReadOnEmptyFeedIsANoOp(): void
    {
        $svc = $this->freshService();
        $this->assertSame(0, $svc->getUnreadCount(self::$testUserId));

        $svc->markAllRead(self::$testUserId); // must not error on an empty feed

        $this->assertSame(0, $this->freshService()->getUnreadCount(self::$testUserId));
    }

    // === Helpers ===

    /**
     * A fresh instance per count: the per-request notification cache on the
     * container singleton would otherwise mask whether the DB was re-queried
     * (each construct re-registers the idempotent invalidation listener,
     * which is harmless).
     */
    private function freshService(): NotificationService
    {
        return new NotificationService(
            $this->audit,
            ntdst_get(\Stride\Modules\Enrollment\RegistrationRepository::class),
            ntdst_get(\Stride\Modules\Notification\NotificationMapper::class),
        );
    }

    private function transientKey(): string
    {
        return 'stride_unread_count_' . self::$testUserId;
    }

    private function recordAudit(string $entityType, string $action, array $context): void
    {
        $id = $this->audit->record($entityType, self::$testUserId, $action, null, $context);

        $this->assertIsInt($id, 'Audit record must succeed');
        $this->auditRowIds[] = $id;
    }
}
