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

    // ── User Meta Tests ─────────────────────────────────────────────

    /** @test */
    public function testOnUserMetaUpdatedLogsGdprRelevantKeys(): void
    {
        $this->bridge->onUserMetaUpdated(1, 42, 'first_name', 'Jane');

        $calls = $this->mockAuditService->getRecordedCalls();
        $this->assertCount(1, $calls);

        $call = $calls[0];
        $this->assertEquals('user', $call['entity_type']);
        $this->assertEquals(42, $call['entity_id']);
        $this->assertEquals('usermeta.updated', $call['action']);
        $this->assertEquals('first_name', $call['context']['meta_key']);
    }

    /** @test */
    public function testOnUserMetaUpdatedIgnoresNonGdprKeys(): void
    {
        $this->bridge->onUserMetaUpdated(1, 42, 'session_tokens', 'value');

        $calls = $this->mockAuditService->getRecordedCalls();
        $this->assertCount(0, $calls);
    }

    /** @test */
    public function testOnUserMetaUpdatedIgnoresInternalKeys(): void
    {
        $this->bridge->onUserMetaUpdated(1, 42, '_edit_lock', '1234');

        $calls = $this->mockAuditService->getRecordedCalls();
        $this->assertCount(0, $calls);
    }

    /** @test */
    public function testOnUserMetaDeletedLogsGdprRelevantKeys(): void
    {
        $this->bridge->onUserMetaDeleted([1], 42, 'billing_email', '');

        $calls = $this->mockAuditService->getRecordedCalls();
        $this->assertCount(1, $calls);

        $call = $calls[0];
        $this->assertEquals('usermeta.deleted', $call['action']);
        $this->assertEquals('billing_email', $call['context']['meta_key']);
    }

    /** @test */
    public function testOnUserMetaDeletedIgnoresNonGdprKeys(): void
    {
        $this->bridge->onUserMetaDeleted([1], 42, 'wp_capabilities', '');

        $calls = $this->mockAuditService->getRecordedCalls();
        $this->assertCount(0, $calls);
    }

    /**
     * @test
     * @dataProvider gdprMetaKeyProvider
     */
    public function testAllGdprMetaKeysAreLogged(string $metaKey): void
    {
        $this->bridge->onUserMetaUpdated(1, 42, $metaKey, 'value');

        $calls = $this->mockAuditService->getRecordedCalls();
        $this->assertCount(1, $calls, "GDPR meta key '{$metaKey}' should be logged");
    }

    public static function gdprMetaKeyProvider(): array
    {
        return [
            'first_name' => ['first_name'],
            'last_name' => ['last_name'],
            'billing_company' => ['billing_company'],
            'billing_email' => ['billing_email'],
            'billing_phone' => ['billing_phone'],
            'billing_address_1' => ['billing_address_1'],
            'shipping_first_name' => ['shipping_first_name'],
        ];
    }

    // ── User Lifecycle Tests ────────────────────────────────────────

    /** @test */
    public function testOnUserCreatedRecordsEvent(): void
    {
        $this->bridge->onUserCreated(55);

        $calls = $this->mockAuditService->getRecordedCalls();
        $this->assertCount(1, $calls);

        $call = $calls[0];
        $this->assertEquals('user', $call['entity_type']);
        $this->assertEquals(55, $call['entity_id']);
        $this->assertEquals('user.created', $call['action']);
    }

    /** @test */
    public function testOnUserDeletedRecordsEvent(): void
    {
        $this->bridge->onUserDeleted(55, 1);

        $calls = $this->mockAuditService->getRecordedCalls();
        $this->assertCount(1, $calls);

        $call = $calls[0];
        $this->assertEquals('user', $call['entity_type']);
        $this->assertEquals(55, $call['entity_id']);
        $this->assertEquals('user.deleted', $call['action']);
        $this->assertEquals(1, $call['context']['reassign_to']);
    }

    /** @test */
    public function testOnUserDeletedWithNoReassign(): void
    {
        $this->bridge->onUserDeleted(55, null);

        $calls = $this->mockAuditService->getRecordedCalls();
        $this->assertNull($calls[0]['context']['reassign_to']);
    }

    /** @test */
    public function testOnProfileUpdatedRecordsChangedFields(): void
    {
        $oldUser = new WP_User([
            'ID' => 42,
            'first_name' => 'John',
            'last_name' => 'Doe',
            'user_email' => 'john@example.com',
            'description' => 'Old bio',
        ]);

        update_user_meta(42, 'first_name', 'Jane');
        update_user_meta(42, 'description', 'New bio');

        $this->bridge->onProfileUpdated(42, $oldUser);

        $calls = $this->mockAuditService->getRecordedCalls();
        $this->assertCount(1, $calls);

        $call = $calls[0];
        $this->assertEquals('user', $call['entity_type']);
        $this->assertEquals(42, $call['entity_id']);
        $this->assertEquals('user.profile_updated', $call['action']);
        $this->assertIsArray($call['context']['changed_fields']);
    }

    /** @test */
    public function testOnRoleChangedRecordsOldAndNewRole(): void
    {
        $this->bridge->onRoleChanged(42, 'editor', ['subscriber']);

        $calls = $this->mockAuditService->getRecordedCalls();
        $this->assertCount(1, $calls);

        $call = $calls[0];
        $this->assertEquals('user', $call['entity_type']);
        $this->assertEquals(42, $call['entity_id']);
        $this->assertEquals('user.role_changed', $call['action']);
        $this->assertEquals('editor', $call['context']['new_role']);
        $this->assertEquals('subscriber', $call['context']['old_role']);
    }
}
