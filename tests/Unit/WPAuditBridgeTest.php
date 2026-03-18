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

    // ── WP Privacy API Tests ────────────────────────────────────────

    /** @test */
    public function testOnPrivacyExportCreatedRecordsEvent(): void
    {
        $this->bridge->onPrivacyExportCreated('/path/to/file.zip', 'https://example.com/file.zip', 'test@example.com', 42);

        $calls = $this->mockAuditService->getRecordedCalls();
        $this->assertCount(1, $calls);

        $call = $calls[0];
        $this->assertEquals('privacy_request', $call['entity_type']);
        $this->assertEquals(42, $call['entity_id']);
        $this->assertEquals('privacy.export_created', $call['action']);
        $this->assertEquals('test@example.com', $call['context']['request_email']);
    }

    /** @test */
    public function testOnPrivacyDataErasedRecordsEvent(): void
    {
        $this->bridge->onPrivacyDataErased(42, 'test@example.com', 5, 2, true);

        $calls = $this->mockAuditService->getRecordedCalls();
        $this->assertCount(1, $calls);

        $call = $calls[0];
        $this->assertEquals('privacy_request', $call['entity_type']);
        $this->assertEquals(42, $call['entity_id']);
        $this->assertEquals('privacy.data_erased', $call['action']);
        $this->assertEquals('test@example.com', $call['context']['request_email']);
        $this->assertEquals(5, $call['context']['items_removed']);
        $this->assertEquals(2, $call['context']['items_retained']);
    }

    /** @test */
    public function testOnPrivacyRequestConfirmedRecordsEvent(): void
    {
        $this->bridge->onPrivacyRequestConfirmed(99);

        $calls = $this->mockAuditService->getRecordedCalls();
        $this->assertCount(1, $calls);

        $call = $calls[0];
        $this->assertEquals('privacy_request', $call['entity_type']);
        $this->assertEquals(99, $call['entity_id']);
        $this->assertEquals('privacy.request_confirmed', $call['action']);
    }

    // ── Admin Action Tests ──────────────────────────────────────────

    /** @test */
    public function testOnOptionUpdatedLogsSecurityOptions(): void
    {
        $this->bridge->onOptionUpdated('admin_email', 'old@example.com', 'new@example.com');

        $calls = $this->mockAuditService->getRecordedCalls();
        $this->assertCount(1, $calls);

        $call = $calls[0];
        $this->assertEquals('option', $call['entity_type']);
        $this->assertEquals(0, $call['entity_id']);
        $this->assertEquals('option.updated', $call['action']);
        $this->assertEquals('admin_email', $call['context']['option_name']);
    }

    /** @test */
    public function testOnOptionUpdatedIgnoresNonSecurityOptions(): void
    {
        $this->bridge->onOptionUpdated('_transient_feed_mod_abc', '', '12345');

        $calls = $this->mockAuditService->getRecordedCalls();
        $this->assertCount(0, $calls);
    }

    /** @test */
    public function testOnOptionUpdatedIgnoresGenericOptions(): void
    {
        $this->bridge->onOptionUpdated('sidebars_widgets', [], []);

        $calls = $this->mockAuditService->getRecordedCalls();
        $this->assertCount(0, $calls);
    }

    /**
     * @test
     * @dataProvider securityOptionProvider
     */
    public function testAllSecurityOptionsAreLogged(string $option): void
    {
        $this->bridge->onOptionUpdated($option, 'old', 'new');

        $calls = $this->mockAuditService->getRecordedCalls();
        $this->assertCount(1, $calls, "Security option '{$option}' should be logged");
    }

    public static function securityOptionProvider(): array
    {
        return [
            'blogname' => ['blogname'],
            'admin_email' => ['admin_email'],
            'users_can_register' => ['users_can_register'],
            'default_role' => ['default_role'],
            'permalink_structure' => ['permalink_structure'],
            'blog_public' => ['blog_public'],
            'wp_page_for_privacy_policy' => ['wp_page_for_privacy_policy'],
        ];
    }

    /** @test */
    public function testOnPluginActivatedRecordsEvent(): void
    {
        $this->bridge->onPluginActivated('my-plugin/my-plugin.php');

        $calls = $this->mockAuditService->getRecordedCalls();
        $this->assertCount(1, $calls);

        $call = $calls[0];
        $this->assertEquals('plugin', $call['entity_type']);
        $this->assertEquals(0, $call['entity_id']);
        $this->assertEquals('plugin.activated', $call['action']);
        $this->assertEquals('my-plugin/my-plugin.php', $call['context']['plugin_file']);
    }

    /** @test */
    public function testOnPluginDeactivatedRecordsEvent(): void
    {
        $this->bridge->onPluginDeactivated('my-plugin/my-plugin.php');

        $calls = $this->mockAuditService->getRecordedCalls();
        $this->assertCount(1, $calls);

        $call = $calls[0];
        $this->assertEquals('plugin.deactivated', $call['action']);
        $this->assertEquals('my-plugin/my-plugin.php', $call['context']['plugin_file']);
    }

    /** @test */
    public function testOnThemeSwitchedRecordsEvent(): void
    {
        $oldTheme = new \WP_Theme('old-theme');
        $this->bridge->onThemeSwitched('new-theme', $oldTheme);

        $calls = $this->mockAuditService->getRecordedCalls();
        $this->assertCount(1, $calls);

        $call = $calls[0];
        $this->assertEquals('theme', $call['entity_type']);
        $this->assertEquals(0, $call['entity_id']);
        $this->assertEquals('theme.switched', $call['action']);
        $this->assertEquals('new-theme', $call['context']['new_theme']);
    }
}
