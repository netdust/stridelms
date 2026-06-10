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
