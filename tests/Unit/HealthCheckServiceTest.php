<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Stride\Admin\HealthCheckService;

class HealthCheckServiceTest extends TestCase
{
    public function test_registration_green_when_recent(): void
    {
        $service = new HealthCheckService();
        $result = $service->evaluate(
            lastRegistration: time() - 3600,
            lastMailSend: time() - 7200,
            hasOpenEditions: true
        );
        $this->assertSame('green', $result['registration']);
    }

    public function test_registration_green_when_no_open_editions(): void
    {
        $service = new HealthCheckService();
        $result = $service->evaluate(
            lastRegistration: 0,
            lastMailSend: time(),
            hasOpenEditions: false
        );
        $this->assertSame('green', $result['registration']);
    }

    public function test_registration_amber_when_stale_with_open_editions(): void
    {
        $service = new HealthCheckService();
        $result = $service->evaluate(
            lastRegistration: time() - 90000, // 25 hours
            lastMailSend: time(),
            hasOpenEditions: true
        );
        $this->assertSame('amber', $result['registration']);
    }

    public function test_mail_green_when_recent(): void
    {
        $service = new HealthCheckService();
        $result = $service->evaluate(
            lastRegistration: time(),
            lastMailSend: time() - 3600,
            hasOpenEditions: true
        );
        $this->assertSame('green', $result['mail']);
    }

    public function test_mail_amber_when_stale(): void
    {
        $service = new HealthCheckService();
        $result = $service->evaluate(
            lastRegistration: time(),
            lastMailSend: time() - 90000,
            hasOpenEditions: true
        );
        $this->assertSame('amber', $result['mail']);
    }

    public function test_mail_amber_when_never_sent(): void
    {
        $service = new HealthCheckService();
        $result = $service->evaluate(
            lastRegistration: time(),
            lastMailSend: 0,
            hasOpenEditions: true
        );
        $this->assertSame('amber', $result['mail']);
    }

    public function test_returns_both_keys(): void
    {
        $service = new HealthCheckService();
        $result = $service->evaluate(0, 0, false);
        $this->assertArrayHasKey('registration', $result);
        $this->assertArrayHasKey('mail', $result);
    }
}
