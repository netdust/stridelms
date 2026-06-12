<?php
declare(strict_types=1);

namespace Stride\Tests\Unit;

use NTDST\Audit\AuditService;
use Stride\Modules\Enrollment\RegistrationRepository;
use Stride\Modules\Notification\NotificationMapper;
use Stride\Modules\Notification\NotificationService;
use Stride\Tests\TestCase;

class NotificationServiceTest extends TestCase
{
    /** @test */
    public function testGetNotificationsReturnsArray(): void
    {
        $mockAudit = new AuditService();
        $mockRegRepo = $this->createMock(RegistrationRepository::class);
        $mockRegRepo->method('findByUser')->willReturn([]);

        $this->registerService(AuditService::class, $mockAudit);
        $this->registerService(RegistrationRepository::class, $mockRegRepo);

        $service = new NotificationService($mockAudit, $mockRegRepo, $this->createMock(NotificationMapper::class));
        $notifications = $service->getNotifications(456);

        $this->assertIsArray($notifications);
    }

    /** @test */
    public function testGetUnreadCountReturnsInteger(): void
    {
        $mockAudit = new AuditService();
        $mockRegRepo = $this->createMock(RegistrationRepository::class);
        $mockRegRepo->method('findByUser')->willReturn([]);

        $service = new NotificationService($mockAudit, $mockRegRepo, $this->createMock(NotificationMapper::class));
        $count = $service->getUnreadCount(456);

        $this->assertIsInt($count);
        $this->assertGreaterThanOrEqual(0, $count);
    }

    /** @test */
    public function testConstructorDoesNotRequireUserDashboardService(): void
    {
        $mockAudit = new AuditService();
        $mockRegRepo = $this->createMock(RegistrationRepository::class);

        $service = new NotificationService($mockAudit, $mockRegRepo, $this->createMock(NotificationMapper::class));
        $this->assertInstanceOf(NotificationService::class, $service);
    }

    // === Unread-count caching (audit H-4 / task F1) ===

    /** @test */
    public function testGetUnreadCountPrimesPerUserTransient(): void
    {
        global $_test_transients;

        $service = $this->makeService();
        $service->getUnreadCount(456);

        $this->assertArrayHasKey(
            'stride_unread_count_456',
            $_test_transients,
            'getUnreadCount() must cache the count in a per-user transient',
        );
    }

    /** @test */
    public function testGetUnreadCountReturnsCachedValueWithoutRecompute(): void
    {
        global $_test_transients;

        $_test_transients['stride_unread_count_456'] = 7;

        $service = $this->makeService();

        $this->assertSame(
            7,
            $service->getUnreadCount(456),
            'A warm transient must short-circuit the audit-table query',
        );
    }

    /** @test */
    public function testSubjectTargetedAuditEventInvalidatesCachedCount(): void
    {
        global $_test_transients;

        $_test_transients['stride_unread_count_456'] = 7;

        $service = $this->makeService();
        $service->onAuditRecorded('registration.created', 'registration', 1, ['user_id' => 456]);

        $this->assertArrayNotHasKey(
            'stride_unread_count_456',
            $_test_transients,
            'Denial path: a stale cached count must not survive a subject-targeted event',
        );
    }

    /** @test */
    public function testAuditEventWithoutSubjectUserLeavesCacheAlone(): void
    {
        global $_test_transients;

        $_test_transients['stride_unread_count_456'] = 7;

        $service = $this->makeService();
        $service->onAuditRecorded('session.created', 'session', 1, ['edition_id' => 9]);

        $this->assertSame(
            7,
            $_test_transients['stride_unread_count_456'] ?? null,
            'Events without a subject user must not touch other users\' cache',
        );
    }

    /** @test */
    public function testMarkAllReadInvalidatesCachedCount(): void
    {
        global $_test_transients;

        $_test_transients['stride_unread_count_456'] = 7;

        $service = $this->makeService();
        $service->markAllRead(456);

        $this->assertArrayNotHasKey(
            'stride_unread_count_456',
            $_test_transients,
            'markAllRead() changes the count and must invalidate the cache',
        );
    }

    private function makeService(): NotificationService
    {
        $mockRegRepo = $this->createMock(RegistrationRepository::class);
        $mockRegRepo->method('findByUser')->willReturn([]);

        return new NotificationService(
            new AuditService(),
            $mockRegRepo,
            $this->createMock(NotificationMapper::class),
        );
    }
}
