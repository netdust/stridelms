<?php

declare(strict_types=1);

namespace Stride\Tests\Unit;

use NTDST\Audit\AuditService;
use NTDST\Audit\Bridges\WPAuditBridge;
use Stride\Tests\TestCase;
use WP_User;

/**
 * Unit tests for WPAuditBridge
 *
 * Tests the WordPress core event handlers that bridge WP events to ntdst-audit.
 */
class WPAuditBridgeTest extends TestCase
{
    private AuditService $mockAuditService;
    private WPAuditBridge $bridge;

    protected function setUp(): void
    {
        parent::setUp();

        // Create mock AuditService that captures record() calls
        $this->mockAuditService = new AuditService();

        // Register in test container
        $this->registerService(AuditService::class, $this->mockAuditService);

        // Create WPAuditBridge instance without calling constructor
        // This avoids init() registering WordPress hooks
        $reflection = new \ReflectionClass(WPAuditBridge::class);
        $this->bridge = $reflection->newInstanceWithoutConstructor();
    }

    /**
     * @test
     */
    public function testMetadataReturnsCorrectStructure(): void
    {
        $metadata = WPAuditBridge::metadata();

        $this->assertIsArray($metadata);
        $this->assertArrayHasKey('name', $metadata);
        $this->assertArrayHasKey('description', $metadata);
        $this->assertArrayHasKey('priority', $metadata);

        $this->assertEquals('WP Audit Bridge', $metadata['name']);
        $this->assertEquals(99, $metadata['priority']);
    }

    /**
     * @test
     */
    public function testOnLoginRecordsAuthEvent(): void
    {
        $user = new WP_User(['ID' => 42, 'user_login' => 'johndoe']);

        $this->bridge->onLogin('johndoe', $user);

        $calls = $this->mockAuditService->getRecordedCalls();
        $this->assertCount(1, $calls);

        $call = $calls[0];
        $this->assertEquals('user', $call['entity_type']);
        $this->assertEquals(42, $call['entity_id']);
        $this->assertEquals('auth.login', $call['action']);
        $this->assertEquals(42, $call['actor_id']);
        $this->assertArrayHasKey('user_login', $call['context']);
        $this->assertEquals('johndoe', $call['context']['user_login']);
        $this->assertArrayHasKey('ip_hash', $call['context']);
    }

    /**
     * @test
     */
    public function testOnLogoutRecordsAuthEvent(): void
    {
        $this->bridge->onLogout(42);

        $calls = $this->mockAuditService->getRecordedCalls();
        $this->assertCount(1, $calls);

        $call = $calls[0];
        $this->assertEquals('user', $call['entity_type']);
        $this->assertEquals(42, $call['entity_id']);
        $this->assertEquals('auth.logout', $call['action']);
        $this->assertEquals(42, $call['actor_id']);
    }

    /**
     * @test
     */
    public function testOnLoginFailedRecordsAttempt(): void
    {
        $this->bridge->onLoginFailed('baduser');

        $calls = $this->mockAuditService->getRecordedCalls();
        $this->assertCount(1, $calls);

        $call = $calls[0];
        $this->assertEquals('user', $call['entity_type']);
        $this->assertEquals(0, $call['entity_id']);
        $this->assertEquals('auth.login_failed', $call['action']);
        $this->assertNull($call['actor_id']);
        $this->assertArrayHasKey('user_login', $call['context']);
        $this->assertEquals('baduser', $call['context']['user_login']);
        $this->assertArrayHasKey('ip_hash', $call['context']);
    }
}
