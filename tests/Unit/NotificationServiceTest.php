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
}
