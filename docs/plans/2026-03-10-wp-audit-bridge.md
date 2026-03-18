# WPAuditBridge Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Add a `WPAuditBridge` service to ntdst-audit that logs all GDPR-relevant WordPress core events and Privacy API actions.

**Architecture:** Single service class following the existing bridge pattern (see `AuditBridge` in stride-core). Hooks into WP core actions, calls `AuditService::record()`. Always on, no configuration. Two const arrays filter noise (meta keys, options).

**Tech Stack:** PHP 8.1+, NTDST service pattern, WordPress hooks, PHPUnit

**Design doc:** `docs/plans/2026-03-10-wp-audit-bridge-design.md`

---

### Task 1: Write failing tests for authentication events

**Files:**
- Create: `tests/Unit/WPAuditBridgeTest.php`

**Step 1: Write the test class with auth event tests**

```php
<?php

declare(strict_types=1);

namespace Stride\Tests\Unit;

use NTDST\Audit\AuditService;
use NTDST\Audit\Bridges\WPAuditBridge;
use Stride\Tests\TestCase;
use WP_User;

class WPAuditBridgeTest extends TestCase
{
    private AuditService $mockAuditService;
    private WPAuditBridge $bridge;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockAuditService = new AuditService();
        $this->registerService(AuditService::class, $this->mockAuditService);

        $reflection = new \ReflectionClass(WPAuditBridge::class);
        $this->bridge = $reflection->newInstanceWithoutConstructor();
    }

    /** @test */
    public function testMetadataReturnsCorrectStructure(): void
    {
        $metadata = WPAuditBridge::metadata();

        $this->assertIsArray($metadata);
        $this->assertEquals('WP Audit Bridge', $metadata['name']);
        $this->assertArrayHasKey('priority', $metadata);
    }

    /** @test */
    public function testOnLoginRecordsAuthEvent(): void
    {
        $user = new WP_User(['ID' => 42]);
        $this->bridge->onLogin('johndoe', $user);

        $calls = $this->mockAuditService->getRecordedCalls();
        $this->assertCount(1, $calls);

        $call = $calls[0];
        $this->assertEquals('user', $call['entity_type']);
        $this->assertEquals(42, $call['entity_id']);
        $this->assertEquals('auth.login', $call['action']);
        $this->assertEquals(42, $call['actor_id']);
        $this->assertEquals('johndoe', $call['context']['user_login']);
        $this->assertArrayHasKey('ip_hash', $call['context']);
    }

    /** @test */
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

    /** @test */
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
        $this->assertEquals('baduser', $call['context']['user_login']);
        $this->assertArrayHasKey('ip_hash', $call['context']);
    }
}
```

**Step 2: Run test to verify it fails**

Run: `ddev exec vendor/bin/phpunit --filter WPAuditBridgeTest --testsuite Unit`
Expected: FAIL — class `NTDST\Audit\Bridges\WPAuditBridge` not found

---

### Task 2: Implement WPAuditBridge with authentication events

**Files:**
- Create: `web/app/plugins/ntdst-audit/src/Bridges/WPAuditBridge.php`

**Step 1: Create the bridge class with auth handlers**

```php
<?php

declare(strict_types=1);

namespace NTDST\Audit\Bridges;

use NTDST\Audit\AuditService;
use WP_User;

final class WPAuditBridge implements \NTDST_Service_Meta
{
    public static function metadata(): array
    {
        return [
            'name' => 'WP Audit Bridge',
            'description' => 'Logs GDPR-relevant WordPress core events',
            'priority' => 99,
        ];
    }

    private const GDPR_META_KEYS = [
        'first_name', 'last_name', 'nickname', 'description',
        'billing_first_name', 'billing_last_name', 'billing_company',
        'billing_address_1', 'billing_address_2', 'billing_city',
        'billing_postcode', 'billing_country', 'billing_state',
        'billing_email', 'billing_phone', 'billing_vat',
        'shipping_first_name', 'shipping_last_name', 'shipping_company',
        'shipping_address_1', 'shipping_address_2', 'shipping_city',
        'shipping_postcode', 'shipping_country', 'shipping_state',
    ];

    private const SECURITY_OPTIONS = [
        'blogname', 'blogdescription', 'siteurl', 'home',
        'admin_email', 'users_can_register', 'default_role',
        'permalink_structure', 'blog_public',
        'wp_page_for_privacy_policy',
    ];

    public function __construct()
    {
        $this->init();
    }

    private function audit(): AuditService
    {
        return ntdst_get(AuditService::class);
    }

    private function init(): void
    {
        // Authentication
        add_action('wp_login', [$this, 'onLogin'], 10, 2);
        add_action('wp_logout', [$this, 'onLogout'], 10, 1);
        add_action('wp_login_failed', [$this, 'onLoginFailed'], 10, 1);

        // User lifecycle
        add_action('user_register', [$this, 'onUserCreated'], 10, 1);
        add_action('delete_user', [$this, 'onUserDeleted'], 10, 2);
        add_action('profile_update', [$this, 'onProfileUpdated'], 10, 2);
        add_action('set_user_role', [$this, 'onRoleChanged'], 10, 3);

        // User meta (personal data)
        add_action('updated_user_meta', [$this, 'onUserMetaUpdated'], 10, 4);
        add_action('deleted_user_meta', [$this, 'onUserMetaDeleted'], 10, 4);

        // WP Privacy API
        add_action('wp_privacy_personal_data_export_file_created', [$this, 'onPrivacyExportCreated'], 10, 4);
        add_action('wp_privacy_personal_data_erased', [$this, 'onPrivacyDataErased'], 10, 5);
        add_action('user_request_action_confirmed', [$this, 'onPrivacyRequestConfirmed'], 10, 1);

        // Admin actions
        add_action('updated_option', [$this, 'onOptionUpdated'], 10, 3);
        add_action('activated_plugin', [$this, 'onPluginActivated'], 10, 1);
        add_action('deactivated_plugin', [$this, 'onPluginDeactivated'], 10, 1);
        add_action('switch_theme', [$this, 'onThemeSwitched'], 10, 2);
    }

    private function hashedIp(): string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        return wp_hash($ip);
    }

    // ── Authentication ──

    public function onLogin(string $userLogin, WP_User $user): void
    {
        $this->audit()->record('user', $user->ID, 'auth.login', $user->ID, [
            'user_login' => $userLogin,
            'ip_hash' => $this->hashedIp(),
        ]);
    }

    public function onLogout(int $userId): void
    {
        $this->audit()->record('user', $userId, 'auth.logout', $userId);
    }

    public function onLoginFailed(string $userLogin): void
    {
        $this->audit()->record('user', 0, 'auth.login_failed', null, [
            'user_login' => $userLogin,
            'ip_hash' => $this->hashedIp(),
        ]);
    }
}
```

**Step 2: Run tests to verify they pass**

Run: `ddev exec vendor/bin/phpunit --filter WPAuditBridgeTest --testsuite Unit`
Expected: 4 tests PASS

**Step 3: Commit**

```bash
git add tests/Unit/WPAuditBridgeTest.php web/app/plugins/ntdst-audit/src/Bridges/WPAuditBridge.php
git commit -m "feat(audit): add WPAuditBridge with authentication event logging"
```

---

### Task 3: Add user lifecycle event tests

**Files:**
- Modify: `tests/Unit/WPAuditBridgeTest.php`

**Step 1: Add user lifecycle tests**

Append these tests to `WPAuditBridgeTest`:

```php
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

        // Simulate changed fields by setting new values
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
```

**Step 2: Run tests — expect FAIL**

Run: `ddev exec vendor/bin/phpunit --filter WPAuditBridgeTest --testsuite Unit`
Expected: FAIL — methods not found

---

### Task 4: Implement user lifecycle handlers

**Files:**
- Modify: `web/app/plugins/ntdst-audit/src/Bridges/WPAuditBridge.php`

**Step 1: Add user lifecycle methods to WPAuditBridge**

Add after the authentication methods:

```php
    // ── User Lifecycle ──

    public function onUserCreated(int $userId): void
    {
        $user = get_userdata($userId);
        $roles = $user ? $user->roles : [];

        $this->audit()->record('user', $userId, 'user.created', null, [
            'roles' => $roles,
        ]);
    }

    public function onUserDeleted(int $userId, ?int $reassignTo): void
    {
        $this->audit()->record('user', $userId, 'user.deleted', null, [
            'reassign_to' => $reassignTo,
        ]);
    }

    public function onProfileUpdated(int $userId, \WP_User $oldUser): void
    {
        $trackFields = ['first_name', 'last_name', 'nickname', 'user_email', 'display_name', 'description'];
        $changed = [];

        foreach ($trackFields as $field) {
            $oldValue = $oldUser->$field ?? '';
            $newValue = get_user_meta($userId, $field, true);

            // user_email and display_name are on the user object, not usermeta
            if (in_array($field, ['user_email', 'display_name'], true)) {
                $newUser = get_userdata($userId);
                $newValue = $newUser ? ($newUser->$field ?? '') : '';
            }

            if ((string) $oldValue !== (string) $newValue) {
                $changed[] = $field;
            }
        }

        if (empty($changed)) {
            return;
        }

        $this->audit()->record('user', $userId, 'user.profile_updated', null, [
            'changed_fields' => $changed,
        ]);
    }

    public function onRoleChanged(int $userId, string $newRole, array $oldRoles): void
    {
        $this->audit()->record('user', $userId, 'user.role_changed', null, [
            'new_role' => $newRole,
            'old_role' => $oldRoles[0] ?? '',
        ]);
    }
```

**Step 2: Run tests**

Run: `ddev exec vendor/bin/phpunit --filter WPAuditBridgeTest --testsuite Unit`
Expected: All tests PASS

**Step 3: Commit**

```bash
git add tests/Unit/WPAuditBridgeTest.php web/app/plugins/ntdst-audit/src/Bridges/WPAuditBridge.php
git commit -m "feat(audit): add user lifecycle event logging to WPAuditBridge"
```

---

### Task 5: Add user meta event tests

**Files:**
- Modify: `tests/Unit/WPAuditBridgeTest.php`

**Step 1: Add meta change tests**

```php
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
```

**Step 2: Run tests — expect FAIL**

Run: `ddev exec vendor/bin/phpunit --filter WPAuditBridgeTest --testsuite Unit`
Expected: FAIL — methods not found

---

### Task 6: Implement user meta handlers

**Files:**
- Modify: `web/app/plugins/ntdst-audit/src/Bridges/WPAuditBridge.php`

**Step 1: Add user meta methods**

```php
    // ── User Meta ──

    public function onUserMetaUpdated(int $metaId, int $userId, string $metaKey, mixed $metaValue): void
    {
        if (!in_array($metaKey, self::GDPR_META_KEYS, true)) {
            return;
        }

        $this->audit()->record('user', $userId, 'usermeta.updated', null, [
            'meta_key' => $metaKey,
        ]);
    }

    public function onUserMetaDeleted(array $metaIds, int $userId, string $metaKey, mixed $metaValue): void
    {
        if (!in_array($metaKey, self::GDPR_META_KEYS, true)) {
            return;
        }

        $this->audit()->record('user', $userId, 'usermeta.deleted', null, [
            'meta_key' => $metaKey,
        ]);
    }
```

**Step 2: Run tests**

Run: `ddev exec vendor/bin/phpunit --filter WPAuditBridgeTest --testsuite Unit`
Expected: All tests PASS

**Step 3: Commit**

```bash
git add tests/Unit/WPAuditBridgeTest.php web/app/plugins/ntdst-audit/src/Bridges/WPAuditBridge.php
git commit -m "feat(audit): add user meta change logging with GDPR key allowlist"
```

---

### Task 7: Add WP Privacy API event tests

**Files:**
- Modify: `tests/Unit/WPAuditBridgeTest.php`

**Step 1: Add privacy API tests**

```php
    /** @test */
    public function testOnPrivacyExportCreatedRecordsEvent(): void
    {
        $this->bridge->onPrivacyExportCreated('/path/to/file.zip', 'https://example.com/file.zip', 'test@example.com', 42);

        $calls = $this->mockAuditService->getRecordedCalls();
        $this->assertCount(1, $calls);

        $call = $calls[0];
        $this->assertEquals('privacy_request', $call['entity_type']);
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
```

**Step 2: Run tests — expect FAIL**

Run: `ddev exec vendor/bin/phpunit --filter WPAuditBridgeTest --testsuite Unit`

---

### Task 8: Implement WP Privacy API handlers

**Files:**
- Modify: `web/app/plugins/ntdst-audit/src/Bridges/WPAuditBridge.php`

**Step 1: Add privacy methods**

```php
    // ── WP Privacy API ──

    public function onPrivacyExportCreated(string $archivePath, string $archiveUrl, string $requestEmail, int $requestId): void
    {
        $this->audit()->record('privacy_request', $requestId, 'privacy.export_created', null, [
            'request_email' => $requestEmail,
        ]);
    }

    public function onPrivacyDataErased(int $requestId, string $requestEmail, int $itemsRemoved, int $itemsRetained, bool $done): void
    {
        $this->audit()->record('privacy_request', $requestId, 'privacy.data_erased', null, [
            'request_email' => $requestEmail,
            'items_removed' => $itemsRemoved,
            'items_retained' => $itemsRetained,
        ]);
    }

    public function onPrivacyRequestConfirmed(int $requestId): void
    {
        $request = get_post($requestId);
        $actionName = $request->post_name ?? '';
        $email = $request->post_title ?? '';

        $this->audit()->record('privacy_request', $requestId, 'privacy.request_confirmed', null, [
            'action_name' => $actionName,
            'request_email' => $email,
        ]);
    }
```

**Step 2: Run tests**

Run: `ddev exec vendor/bin/phpunit --filter WPAuditBridgeTest --testsuite Unit`
Expected: All PASS

**Step 3: Commit**

```bash
git add tests/Unit/WPAuditBridgeTest.php web/app/plugins/ntdst-audit/src/Bridges/WPAuditBridge.php
git commit -m "feat(audit): add WP Privacy API event logging"
```

---

### Task 9: Add admin action event tests

**Files:**
- Modify: `tests/Unit/WPAuditBridgeTest.php`

**Step 1: Add admin action tests**

```php
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
        $this->assertEquals('theme.switched', $call['action']);
        $this->assertEquals('new-theme', $call['context']['new_theme']);
    }
```

**Step 2: Run tests — expect FAIL**

Run: `ddev exec vendor/bin/phpunit --filter WPAuditBridgeTest --testsuite Unit`

---

### Task 10: Implement admin action handlers

**Files:**
- Modify: `web/app/plugins/ntdst-audit/src/Bridges/WPAuditBridge.php`

**Step 1: Add admin action methods**

```php
    // ── Admin Actions ──

    public function onOptionUpdated(string $option, mixed $oldValue, mixed $newValue): void
    {
        if (!in_array($option, self::SECURITY_OPTIONS, true)) {
            return;
        }

        $this->audit()->record('option', 0, 'option.updated', null, [
            'option_name' => $option,
        ]);
    }

    public function onPluginActivated(string $plugin): void
    {
        $this->audit()->record('plugin', 0, 'plugin.activated', null, [
            'plugin_file' => $plugin,
        ]);
    }

    public function onPluginDeactivated(string $plugin): void
    {
        $this->audit()->record('plugin', 0, 'plugin.deactivated', null, [
            'plugin_file' => $plugin,
        ]);
    }

    public function onThemeSwitched(string $newThemeName, \WP_Theme $oldTheme): void
    {
        $this->audit()->record('theme', 0, 'theme.switched', null, [
            'new_theme' => $newThemeName,
            'old_theme' => $oldTheme->get_stylesheet(),
        ]);
    }
```

**Step 2: Check if `WP_Theme` stub exists — if not, add minimal stub**

Check: `tests/Stubs/wordpress-stubs.php` for `WP_Theme` class.

If missing, add:
```php
class WP_Theme {
    private string $stylesheet;
    public function __construct(string $stylesheet = '') {
        $this->stylesheet = $stylesheet;
    }
    public function get_stylesheet(): string {
        return $this->stylesheet;
    }
}
```

**Step 3: Run tests**

Run: `ddev exec vendor/bin/phpunit --filter WPAuditBridgeTest --testsuite Unit`
Expected: All PASS

**Step 4: Commit**

```bash
git add tests/Unit/WPAuditBridgeTest.php web/app/plugins/ntdst-audit/src/Bridges/WPAuditBridge.php tests/Stubs/wordpress-stubs.php
git commit -m "feat(audit): add admin action event logging (options, plugins, themes)"
```

---

### Task 11: Register WPAuditBridge in plugin config

**Files:**
- Modify: `web/app/plugins/ntdst-audit/plugin-config.php`

**Step 1: Add WPAuditBridge to services array**

```php
<?php

declare(strict_types=1);

return [
    'services' => [
        \NTDST\Audit\AuditService::class,
        \NTDST\Audit\Bridges\WPAuditBridge::class,
        \NTDST\Audit\Admin\AdminController::class,
        \NTDST\Audit\Admin\APIController::class,
    ],
];
```

**Step 2: Run full test suite**

Run: `ddev exec vendor/bin/phpunit --testsuite Unit`
Expected: All tests PASS

**Step 3: Commit**

```bash
git add web/app/plugins/ntdst-audit/plugin-config.php
git commit -m "feat(audit): register WPAuditBridge service in plugin config"
```

---

### Task 12: Smoke test on live site

**Step 1: Flush and verify plugin loads**

Run: `ddev exec wp cache flush`
Run: `ddev exec wp eval "echo class_exists('\NTDST\Audit\Bridges\WPAuditBridge') ? 'OK' : 'FAIL';"`
Expected: `OK`

**Step 2: Trigger a login event and check the audit log**

Run: `ddev launch /wp/wp-admin/` — log in
Navigate to Tools > Audit Log, verify `auth.login` entry appears.

**Step 3: Change a user profile field, verify `user.profile_updated` entry appears**

**Step 4: Change a security option (Settings > General > Site Title), verify `option.updated` entry appears**
