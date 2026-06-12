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

    public function test_returns_all_keys(): void
    {
        $service = new HealthCheckService();
        $result = $service->evaluate(0, 0, false);
        $this->assertArrayHasKey('registration', $result);
        $this->assertArrayHasKey('mail', $result);
        $this->assertArrayHasKey('audit', $result);
    }

    // AF-2 residual: the PII-reveal audit trail depends on the ntdst-audit
    // plugin being active. If it is deactivated (deploy mistake, plugin
    // conflict), reveals proceed UNLOGGED — that must be a RED flag on the
    // dashboard, not silence.

    public function test_audit_red_when_audit_service_inactive(): void
    {
        $service = new HealthCheckService();
        $result = $service->evaluate(
            lastRegistration: time(),
            lastMailSend: time(),
            hasOpenEditions: true,
            auditActive: false,
        );
        $this->assertSame('red', $result['audit'], 'Inactive audit trail must flag RED (PII reveals would go unlogged)');
    }

    public function test_audit_green_when_audit_service_active(): void
    {
        $service = new HealthCheckService();
        $result = $service->evaluate(
            lastRegistration: time(),
            lastMailSend: time(),
            hasOpenEditions: true,
            auditActive: true,
        );
        $this->assertSame('green', $result['audit']);
    }
}
