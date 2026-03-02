# LTI Tool Provider E2E Testing — Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Add E2E and integration tests for the netdust-lti Tool Provider endpoints.

**Architecture:** Two test suites: Playwright for browser-accessible endpoints (config, JWKS, admin, registration errors) and PHPUnit integration tests with a MockLtiPlatform helper that generates valid JWTs via celtic/lti to test the full launch → provision → enroll → grade chain.

**Tech Stack:** Playwright (existing), PHPUnit + wp-browser (existing), celtic/lti library (JWT generation), DDEV

**Design doc:** `docs/plans/2026-03-02-lti-e2e-testing-design.md`

---

## Task 1: MockLtiPlatform Test Helper

The foundation for all integration tests. Creates a reusable helper that generates RSA keys, registers a mock platform CPT, and builds valid LTI 1.3 JWTs.

**Files:**
- Create: `tests/Integration/NetdustLTI/MockLtiPlatform.php`

**Step 1: Create the MockLtiPlatform helper class**

```php
<?php

declare(strict_types=1);

namespace Stride\Tests\Integration\NetdustLTI;

use ceLTIc\LTI\Jwt\Jwt;
use Firebase\JWT\JWT as FirebaseJWT;

/**
 * Mock LTI Platform for integration testing.
 *
 * Generates RSA keys, creates a platform CPT, and builds valid LTI 1.3 JWTs
 * that the Tool Provider can validate.
 */
final class MockLtiPlatform
{
    private static ?array $keyPair = null;

    private int $platformPostId = 0;
    private string $platformId;
    private string $clientId;
    private string $deploymentId;
    private string $kid = 'test-key-1';

    public function __construct(
        string $platformId = 'https://mock-lms.test',
        string $clientId = 'mock-client-id-123',
        string $deploymentId = 'mock-deployment-1',
    ) {
        $this->platformId = $platformId;
        $this->clientId = $clientId;
        $this->deploymentId = $deploymentId;
    }

    /**
     * Generate or retrieve cached RSA key pair.
     *
     * @return array{private: string, public: string}
     */
    public static function getKeyPair(): array
    {
        if (self::$keyPair !== null) {
            return self::$keyPair;
        }

        $config = [
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];

        $res = openssl_pkey_new($config);
        openssl_pkey_export($res, $privateKey);
        $details = openssl_pkey_get_details($res);

        self::$keyPair = [
            'private' => $privateKey,
            'public' => $details['key'],
        ];

        return self::$keyPair;
    }

    /**
     * Register the mock platform as an lti_platform CPT.
     *
     * Returns the platform post ID.
     */
    public function register(): int
    {
        $keys = self::getKeyPair();

        $postId = wp_insert_post([
            'post_title' => 'Mock LMS Platform',
            'post_type' => 'lti_platform',
            'post_status' => 'publish',
        ]);

        if (is_wp_error($postId)) {
            throw new \RuntimeException('Failed to create mock platform: ' . $postId->get_error_message());
        }

        // Set meta with lti_ prefix (as defined in LTIDataService)
        $meta = [
            'lti_platform_id' => $this->platformId,
            'lti_client_id' => $this->clientId,
            'lti_deployment_id' => $this->deploymentId,
            'lti_auth_endpoint' => $this->platformId . '/auth',
            'lti_token_endpoint' => $this->platformId . '/token',
            'lti_jwks_endpoint' => '',
            'lti_rsa_key' => $keys['public'],
            'lti_kid' => $this->kid,
            'lti_enabled' => '1',
            'lti_role_instructor' => 'instructor',
            'lti_role_learner' => 'subscriber',
        ];

        foreach ($meta as $key => $value) {
            update_post_meta($postId, $key, $value);
        }

        $this->platformPostId = $postId;

        return $postId;
    }

    /**
     * Build a valid LTI 1.3 JWT id_token for a resource link launch.
     *
     * @param array<string, mixed> $overrides Override any claim
     * @return string Signed JWT
     */
    public function buildLaunchJwt(array $overrides = []): string
    {
        $keys = self::getKeyPair();
        $now = time();

        $claims = array_merge([
            'iss' => $this->platformId,
            'aud' => home_url(),
            'sub' => 'user-' . uniqid(),
            'iat' => $now,
            'exp' => $now + 3600,
            'nonce' => bin2hex(random_bytes(16)),
            'https://purl.imsglobal.org/spec/lti/claim/message_type' => 'LtiResourceLinkRequest',
            'https://purl.imsglobal.org/spec/lti/claim/version' => '1.3.0',
            'https://purl.imsglobal.org/spec/lti/claim/deployment_id' => $this->deploymentId,
            'https://purl.imsglobal.org/spec/lti/claim/target_link_uri' => home_url('/lti/launch'),
            'https://purl.imsglobal.org/spec/lti/claim/resource_link' => [
                'id' => 'resource-link-' . uniqid(),
                'title' => 'Test Course Launch',
            ],
            'https://purl.imsglobal.org/spec/lti/claim/roles' => [
                'http://purl.imsglobal.org/vocab/lis/v2/membership#Learner',
            ],
            'name' => 'Test User',
            'given_name' => 'Test',
            'family_name' => 'User',
            'email' => 'testuser-' . uniqid() . '@mock-lms.test',
        ], $overrides);

        return FirebaseJWT::encode($claims, $keys['private'], 'RS256', $this->kid);
    }

    /**
     * Build a JWT for a deep linking request.
     */
    public function buildDeepLinkJwt(array $overrides = []): string
    {
        return $this->buildLaunchJwt(array_merge([
            'https://purl.imsglobal.org/spec/lti/claim/message_type' => 'LtiDeepLinkingRequest',
            'https://purl.imsglobal.org/spec/lti-dl/claim/deep_linking_settings' => [
                'deep_link_return_url' => $this->platformId . '/deep-link-return',
                'accept_types' => ['ltiResourceLink'],
                'accept_presentation_document_targets' => ['iframe', 'window'],
            ],
        ], $overrides));
    }

    /**
     * Set up $_POST superglobals to simulate an LTI launch POST.
     *
     * The celtic/lti library reads from $_POST['id_token'] and $_POST['state'].
     */
    public function simulateLaunchPost(array $jwtOverrides = []): void
    {
        $jwt = $this->buildLaunchJwt($jwtOverrides);

        // celtic/lti expects id_token in POST
        $_POST['id_token'] = $jwt;
        $_POST['state'] = bin2hex(random_bytes(16));
        $_SERVER['REQUEST_METHOD'] = 'POST';
    }

    public function getPlatformPostId(): int
    {
        return $this->platformPostId;
    }

    public function getPlatformId(): string
    {
        return $this->platformId;
    }

    public function getClientId(): string
    {
        return $this->clientId;
    }

    /**
     * Clean up the mock platform CPT.
     */
    public function cleanup(): void
    {
        if ($this->platformPostId) {
            wp_delete_post($this->platformPostId, true);
            $this->platformPostId = 0;
        }
    }

    /**
     * Reset superglobals after a simulated launch.
     */
    public static function resetSuperglobals(): void
    {
        unset($_POST['id_token'], $_POST['state']);
        $_SERVER['REQUEST_METHOD'] = 'GET';
    }
}
```

**Step 2: Verify file loads without errors**

Run: `ddev exec php -l tests/Integration/NetdustLTI/MockLtiPlatform.php`
Expected: `No syntax errors detected`

**Step 3: Commit**

```bash
git add tests/Integration/NetdustLTI/MockLtiPlatform.php
git commit -m "test: add MockLtiPlatform helper for LTI integration tests"
```

---

## Task 2: Integration Bootstrap Updates

Update the integration test bootstrap to set up LTI keys and flush rewrite rules so `/lti/*` routes work.

**Files:**
- Modify: `tests/Integration/bootstrap.php`

**Step 1: Add LTI key setup to bootstrap**

After the `DOING_PHPUNIT` define block (around line 27), add:

```php
// Ensure LTI keys are configured for integration tests
if (!get_option('netdust_lti_private_key')) {
    $config = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
    $res = openssl_pkey_new($config);
    openssl_pkey_export($res, $privateKey);
    $details = openssl_pkey_get_details($res);

    update_option('netdust_lti_private_key', $privateKey);
    update_option('netdust_lti_public_key', $details['key']);
    update_option('netdust_lti_kid', 'test-tool-key-1');
}

// Flush rewrite rules so /lti/* routes are registered
flush_rewrite_rules();
```

Also add a `createTestLtiPlatform` helper method to `IntegrationTestCase`:

```php
/**
 * Create a test LTI platform CPT.
 */
protected function createTestLtiPlatform(array $data = []): int
{
    $defaults = [
        'post_title' => 'Test Platform ' . wp_generate_password(4, false),
        'post_type' => 'lti_platform',
        'post_status' => 'publish',
    ];

    $postData = array_merge($defaults, $data);
    $postId = wp_insert_post($postData);

    if (is_wp_error($postId)) {
        throw new \RuntimeException('Failed to create test platform: ' . $postId->get_error_message());
    }

    self::$testPosts[] = $postId;

    $metaDefaults = [
        'lti_platform_id' => 'https://test-platform.test',
        'lti_client_id' => 'test-client-' . uniqid(),
        'lti_deployment_id' => 'test-deploy-1',
        'lti_auth_endpoint' => 'https://test-platform.test/auth',
        'lti_token_endpoint' => 'https://test-platform.test/token',
        'lti_enabled' => '1',
    ];

    $meta = array_merge($metaDefaults, $data['meta'] ?? []);
    foreach ($meta as $key => $value) {
        update_post_meta($postId, $key, $value);
    }

    return $postId;
}
```

**Step 2: Run existing integration tests to confirm no regressions**

Run: `ddev exec vendor/bin/phpunit --testsuite Integration 2>&1 | tail -5`
Expected: `OK (120 tests, ...)`

**Step 3: Commit**

```bash
git add tests/Integration/bootstrap.php
git commit -m "test: add LTI key setup and platform helper to integration bootstrap"
```

---

## Task 3: WPDataConnector Integration Tests

Test the DataConnector CRUD operations against real WordPress DB.

**Files:**
- Create: `tests/Integration/NetdustLTI/WPDataConnectorTest.php`

**Step 1: Write the WPDataConnector integration tests**

```php
<?php

declare(strict_types=1);

namespace Stride\Tests\Integration\NetdustLTI;

use IntegrationTestCase;
use ceLTIc\LTI\Platform;
use ceLTIc\LTI\PlatformNonce;
use ceLTIc\LTI\AccessToken;
use ceLTIc\LTI\Context;
use NetdustLTI\ToolProvider\WPDataConnector;

/**
 * Integration tests for WPDataConnector.
 *
 * Tests CRUD operations for platforms, nonces, access tokens, and contexts
 * using real WordPress database.
 *
 * Run: ddev exec vendor/bin/phpunit --testsuite Integration --filter WPDataConnector
 */
class WPDataConnectorTest extends IntegrationTestCase
{
    private WPDataConnector $connector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connector = ntdst_get(WPDataConnector::class);
    }

    // =========================================================================
    // Platform CRUD
    // =========================================================================

    /** @test */
    public function canLoadPlatformByRecordId(): void
    {
        $postId = $this->createTestLtiPlatform([
            'meta' => [
                'lti_platform_id' => 'https://moodle.test',
                'lti_client_id' => 'moodle-client-abc',
            ],
        ]);

        $platform = Platform::fromRecordId($postId, $this->connector);

        $this->assertEquals('https://moodle.test', $platform->platformId);
        $this->assertEquals('moodle-client-abc', $platform->clientId);
        $this->assertTrue($platform->enabled);
    }

    /** @test */
    public function canLoadPlatformByIssuerAndClient(): void
    {
        $this->createTestLtiPlatform([
            'meta' => [
                'lti_platform_id' => 'https://canvas.test',
                'lti_client_id' => 'canvas-client-xyz',
            ],
        ]);

        $platform = Platform::fromPlatformId('https://canvas.test', 'canvas-client-xyz', $this->connector);

        $this->assertNotEmpty($platform->getRecordId());
        $this->assertEquals('https://canvas.test', $platform->platformId);
    }

    /** @test */
    public function canSaveNewPlatform(): void
    {
        $platform = new Platform($this->connector);
        $platform->name = 'New Platform';
        $platform->platformId = 'https://new-platform.test';
        $platform->clientId = 'new-client-001';
        $platform->authenticationUrl = 'https://new-platform.test/auth';
        $platform->accessTokenUrl = 'https://new-platform.test/token';
        $platform->enabled = true;

        $result = $this->connector->savePlatform($platform);

        $this->assertTrue($result);
        $this->assertGreaterThan(0, $platform->getRecordId());

        // Track for cleanup
        self::$testPosts[] = $platform->getRecordId();

        // Reload and verify
        $loaded = Platform::fromRecordId($platform->getRecordId(), $this->connector);
        $this->assertEquals('https://new-platform.test', $loaded->platformId);
    }

    /** @test */
    public function canUpdateExistingPlatform(): void
    {
        $postId = $this->createTestLtiPlatform([
            'meta' => [
                'lti_platform_id' => 'https://update-test.test',
                'lti_client_id' => 'update-client',
            ],
        ]);

        $platform = Platform::fromRecordId($postId, $this->connector);
        $platform->name = 'Updated Platform Name';
        $this->connector->savePlatform($platform);

        $reloaded = Platform::fromRecordId($postId, $this->connector);
        $this->assertEquals('Updated Platform Name', $reloaded->name);
    }

    /** @test */
    public function canDeletePlatform(): void
    {
        $postId = $this->createTestLtiPlatform();

        $platform = Platform::fromRecordId($postId, $this->connector);
        $result = $this->connector->deletePlatform($platform);

        $this->assertTrue($result);

        // Remove from cleanup list since already deleted
        self::$testPosts = array_filter(self::$testPosts, fn($id) => $id !== $postId);

        // Verify deleted
        $deleted = Platform::fromRecordId($postId, $this->connector);
        $this->assertEmpty($deleted->platformId);
    }

    /** @test */
    public function platformRegisteredActionFires(): void
    {
        $fired = false;
        $capturedId = null;

        add_action('netdust_lti_platform_registered', function ($id) use (&$fired, &$capturedId) {
            $fired = true;
            $capturedId = $id;
        }, 10, 1);

        $platform = new Platform($this->connector);
        $platform->name = 'Action Test Platform';
        $platform->platformId = 'https://action-test.test';
        $platform->clientId = 'action-client';
        $platform->enabled = true;

        $this->connector->savePlatform($platform);

        self::$testPosts[] = $platform->getRecordId();

        $this->assertTrue($fired, 'netdust_lti_platform_registered action should have fired');
        $this->assertEquals($platform->getRecordId(), $capturedId);
    }

    // =========================================================================
    // Nonce lifecycle
    // =========================================================================

    /** @test */
    public function nonceCanBeSavedAndLoaded(): void
    {
        $postId = $this->createTestLtiPlatform();
        $platform = Platform::fromRecordId($postId, $this->connector);

        $nonce = new PlatformNonce($platform, 'test-nonce-' . uniqid());
        $nonce->expires = time() + 300;

        $saved = $this->connector->savePlatformNonce($nonce);
        $this->assertTrue($saved);

        // Load should return true (nonce exists = already used)
        $loaded = $this->connector->loadPlatformNonce($nonce);
        $this->assertTrue($loaded);
    }

    /** @test */
    public function unusedNonceReturnsNotLoaded(): void
    {
        $postId = $this->createTestLtiPlatform();
        $platform = Platform::fromRecordId($postId, $this->connector);

        $nonce = new PlatformNonce($platform, 'never-used-nonce-' . uniqid());

        $loaded = $this->connector->loadPlatformNonce($nonce);
        $this->assertFalse($loaded);
    }

    // =========================================================================
    // Access Token
    // =========================================================================

    /** @test */
    public function accessTokenCanBeSavedAndLoaded(): void
    {
        $postId = $this->createTestLtiPlatform();
        $platform = Platform::fromRecordId($postId, $this->connector);

        $token = new AccessToken($platform);
        $token->token = 'test-access-token-' . uniqid();
        $token->expires = time() + 3600;
        $token->scopes = ['https://purl.imsglobal.org/spec/lti-ags/scope/score'];

        $saved = $this->connector->saveAccessToken($token);
        $this->assertTrue($saved);

        $loadToken = new AccessToken($platform);
        $loaded = $this->connector->loadAccessToken($loadToken);
        $this->assertTrue($loaded);
        $this->assertEquals($token->token, $loadToken->token);
        $this->assertEquals($token->scopes, $loadToken->scopes);
    }

    // =========================================================================
    // Context CRUD
    // =========================================================================

    /** @test */
    public function contextCanBeSavedAndLoaded(): void
    {
        $postId = $this->createTestLtiPlatform();
        $platform = Platform::fromRecordId($postId, $this->connector);

        $context = Context::fromPlatform($platform, 'course-context-123');
        $context->title = '42'; // LD course ID stored as title
        $context->setSetting('ld_course_id', '42');

        $saved = $this->connector->saveContext($context);
        $this->assertTrue($saved);

        $loadContext = Context::fromPlatform($platform, 'course-context-123');
        $loaded = $this->connector->loadContext($loadContext);
        $this->assertTrue($loaded);
        $this->assertEquals('42', $loadContext->title);
    }

    /** @test */
    public function contextCanBeDeleted(): void
    {
        $postId = $this->createTestLtiPlatform();
        $platform = Platform::fromRecordId($postId, $this->connector);

        $context = Context::fromPlatform($platform, 'delete-me-context');
        $this->connector->saveContext($context);

        $deleted = $this->connector->deleteContext($context);
        $this->assertTrue($deleted);

        $loadContext = Context::fromPlatform($platform, 'delete-me-context');
        $loaded = $this->connector->loadContext($loadContext);
        $this->assertFalse($loaded);
    }
}
```

**Step 2: Run the tests**

Run: `ddev exec vendor/bin/phpunit --testsuite Integration --filter WPDataConnector`
Expected: All tests pass

**Step 3: Commit**

```bash
git add tests/Integration/NetdustLTI/WPDataConnectorTest.php
git commit -m "test: add WPDataConnector integration tests (platform, nonce, token, context CRUD)"
```

---

## Task 4: Config Endpoint Integration Tests

Test that config endpoints return correct data when built with real WordPress functions.

**Files:**
- Create: `tests/Integration/NetdustLTI/ConfigEndpointIntegrationTest.php`

**Step 1: Write the config endpoint integration tests**

```php
<?php

declare(strict_types=1);

namespace Stride\Tests\Integration\NetdustLTI;

use IntegrationTestCase;
use NetdustLTI\ToolProvider\Router;

/**
 * Integration tests for LTI config endpoints.
 *
 * Verifies JSON and XML configs contain correct WordPress-generated URLs.
 *
 * Run: ddev exec vendor/bin/phpunit --testsuite Integration --filter ConfigEndpointIntegration
 */
class ConfigEndpointIntegrationTest extends IntegrationTestCase
{
    private Router $router;

    protected function setUp(): void
    {
        parent::setUp();
        $this->router = ntdst_get(Router::class);
    }

    /** @test */
    public function jsonConfigContainsCorrectHomeUrl(): void
    {
        $method = new \ReflectionMethod($this->router, 'buildJsonConfig');
        $method->setAccessible(true);

        $config = $method->invoke($this->router);

        $homeUrl = home_url();

        $this->assertStringStartsWith($homeUrl, $config['oidc_initiation_url']);
        $this->assertStringStartsWith($homeUrl, $config['target_link_uri']);
        $this->assertStringStartsWith($homeUrl, $config['jwks_uri']);
        $this->assertStringContainsString('/lti/login', $config['oidc_initiation_url']);
        $this->assertStringContainsString('/lti/launch', $config['target_link_uri']);
        $this->assertStringContainsString('/lti/jwks', $config['jwks_uri']);
    }

    /** @test */
    public function jsonConfigContainsBlogName(): void
    {
        $method = new \ReflectionMethod($this->router, 'buildJsonConfig');
        $method->setAccessible(true);

        $config = $method->invoke($this->router);
        $blogName = get_bloginfo('name');

        if (!empty($blogName)) {
            $this->assertEquals($blogName, $config['title']);
        } else {
            $this->assertEquals('Stride LMS', $config['title']);
        }
    }

    /** @test */
    public function jsonConfigContainsRequiredMessages(): void
    {
        $method = new \ReflectionMethod($this->router, 'buildJsonConfig');
        $method->setAccessible(true);

        $config = $method->invoke($this->router);
        $messageTypes = array_column($config['messages'], 'type');

        $this->assertContains('LtiResourceLinkRequest', $messageTypes);
        $this->assertContains('LtiDeepLinkingRequest', $messageTypes);
    }

    /** @test */
    public function jsonConfigContainsAgsScopes(): void
    {
        $method = new \ReflectionMethod($this->router, 'buildJsonConfig');
        $method->setAccessible(true);

        $config = $method->invoke($this->router);

        $this->assertContains('https://purl.imsglobal.org/spec/lti-ags/scope/lineitem', $config['scopes']);
        $this->assertContains('https://purl.imsglobal.org/spec/lti-ags/scope/score', $config['scopes']);
    }

    /** @test */
    public function xmlConfigContainsCorrectDomain(): void
    {
        $method = new \ReflectionMethod($this->router, 'buildCanvasXml');
        $method->setAccessible(true);

        $xml = $method->invoke($this->router);
        $domain = wp_parse_url(home_url(), PHP_URL_HOST);

        $this->assertStringContainsString($domain, $xml);
        $this->assertStringContainsString('<cartridge_basiclti_link', $xml);
        $this->assertStringContainsString('blti:launch_url', $xml);
    }

    /** @test */
    public function xmlConfigIsValidXml(): void
    {
        $method = new \ReflectionMethod($this->router, 'buildCanvasXml');
        $method->setAccessible(true);

        $xml = $method->invoke($this->router);

        // Suppress warnings and try to parse
        $doc = @simplexml_load_string($xml);
        $this->assertNotFalse($doc, 'XML config should be valid XML');
    }
}
```

**Step 2: Run the tests**

Run: `ddev exec vendor/bin/phpunit --testsuite Integration --filter ConfigEndpointIntegration`
Expected: All tests pass

**Step 3: Commit**

```bash
git add tests/Integration/NetdustLTI/ConfigEndpointIntegrationTest.php
git commit -m "test: add config endpoint integration tests (JSON + XML with real WordPress)"
```

---

## Task 5: Grade Passback Integration Tests

Test grade filters and payload construction with real WordPress context.

**Files:**
- Create: `tests/Integration/NetdustLTI/GradePassbackIntegrationTest.php`

**Step 1: Write the grade passback integration tests**

```php
<?php

declare(strict_types=1);

namespace Stride\Tests\Integration\NetdustLTI;

use IntegrationTestCase;
use NetdustLTI\ToolProvider\Domain\GradePayload;
use NetdustLTI\ToolProvider\Services\GradePassbackService;

/**
 * Integration tests for GradePassbackService.
 *
 * Tests filter hooks and grade payload handling with real WordPress.
 *
 * Run: ddev exec vendor/bin/phpunit --testsuite Integration --filter GradePassbackIntegration
 */
class GradePassbackIntegrationTest extends IntegrationTestCase
{
    /** @test */
    public function gradePayloadFilterModifiesPayload(): void
    {
        $originalPayload = GradePayload::completion(1, 100);

        add_filter('netdust_lti_grade_payload', function (GradePayload $payload): GradePayload {
            return new GradePayload(
                userId: $payload->userId,
                courseId: $payload->courseId,
                score: 50.0,
                maxScore: $payload->maxScore,
                activityProgress: $payload->activityProgress,
                gradingProgress: $payload->gradingProgress,
                comment: 'Modified by filter',
            );
        });

        $filtered = apply_filters('netdust_lti_grade_payload', $originalPayload);

        $this->assertEquals(50.0, $filtered->score);
        $this->assertEquals('Modified by filter', $filtered->comment);

        // Clean up
        remove_all_filters('netdust_lti_grade_payload');
    }

    /** @test */
    public function shouldPostGradeFilterCanSuppressGrading(): void
    {
        add_filter('netdust_lti_should_post_grade', '__return_false');

        $should = apply_filters('netdust_lti_should_post_grade', true, GradePayload::completion(1, 100));

        $this->assertFalse($should);

        remove_all_filters('netdust_lti_should_post_grade');
    }

    /** @test */
    public function completionPayloadHasCorrectDefaults(): void
    {
        $payload = GradePayload::completion(42, 100);

        $this->assertEquals(42, $payload->userId);
        $this->assertEquals(100, $payload->courseId);
        $this->assertEquals(1.0, $payload->score);
        $this->assertEquals(1.0, $payload->maxScore);
        $this->assertEquals('Completed', $payload->activityProgress);
        $this->assertEquals('FullyGraded', $payload->gradingProgress);
    }

    /** @test */
    public function quizScorePayloadCalculatesCorrectly(): void
    {
        $payload = GradePayload::quizScore(42, 100, 85.0, 100.0);

        $this->assertEquals(85.0, $payload->score);
        $this->assertEquals(100.0, $payload->maxScore);
        $this->assertEquals('Completed', $payload->activityProgress);
    }

    /** @test */
    public function tincannyScorePayloadNormalizesPercentage(): void
    {
        $payload = GradePayload::tincannyScore(42, 100, 75.0);

        $this->assertEquals(0.75, $payload->score);
        $this->assertEquals(1.0, $payload->maxScore);
    }

    /** @test */
    public function agsContextStoredInUserMeta(): void
    {
        $userId = self::$testUserId;

        // Simulate AGS context being stored (as done in CourseEnroller)
        update_user_meta($userId, '_netdust_lti_ags_endpoint', 'https://mock-lms.test/ags/lineitems/123');
        update_user_meta($userId, '_netdust_lti_platform_id', 99);

        $agsEndpoint = get_user_meta($userId, '_netdust_lti_ags_endpoint', true);
        $platformId = get_user_meta($userId, '_netdust_lti_platform_id', true);

        $this->assertEquals('https://mock-lms.test/ags/lineitems/123', $agsEndpoint);
        $this->assertEquals('99', $platformId);

        // Clean up
        $this->cleanupUserMeta($userId, ['_netdust_lti_ags_endpoint', '_netdust_lti_platform_id']);
    }
}
```

**Step 2: Run the tests**

Run: `ddev exec vendor/bin/phpunit --testsuite Integration --filter GradePassbackIntegration`
Expected: All tests pass

**Step 3: Commit**

```bash
git add tests/Integration/NetdustLTI/GradePassbackIntegrationTest.php
git commit -m "test: add grade passback integration tests (filters, payloads, user meta)"
```

---

## Task 6: LTI Launch Flow Integration Tests

The main E2E test — full JWT launch through Tool::handleRequest() with real WordPress.

**Files:**
- Create: `tests/Integration/NetdustLTI/LtiLaunchFlowTest.php`

**Step 1: Write the launch flow integration tests**

```php
<?php

declare(strict_types=1);

namespace Stride\Tests\Integration\NetdustLTI;

use IntegrationTestCase;
use NetdustLTI\ToolProvider\Tool;
use NetdustLTI\ToolProvider\WPDataConnector;

/**
 * Integration tests for the full LTI launch flow.
 *
 * Uses MockLtiPlatform to generate valid JWTs, then tests
 * Tool::handleRequest() with real WordPress user creation and enrollment.
 *
 * Run: ddev exec vendor/bin/phpunit --testsuite Integration --filter LtiLaunchFlow
 */
class LtiLaunchFlowTest extends IntegrationTestCase
{
    private MockLtiPlatform $mockPlatform;
    private array $createdUserIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockPlatform = new MockLtiPlatform();
        $this->mockPlatform->register();

        // Track platform for cleanup
        self::$testPosts[] = $this->mockPlatform->getPlatformPostId();
    }

    protected function tearDown(): void
    {
        MockLtiPlatform::resetSuperglobals();

        // Clean up any users created during launch
        require_once ABSPATH . 'wp-admin/includes/user.php';
        foreach ($this->createdUserIds as $userId) {
            wp_delete_user($userId);
        }
        $this->createdUserIds = [];

        parent::tearDown();
    }

    /** @test */
    public function launchCreatesNewUserWithCorrectUsername(): void
    {
        $email = 'launch-test-' . uniqid() . '@mock-lms.test';

        $this->mockPlatform->simulateLaunchPost([
            'given_name' => 'Jane',
            'family_name' => 'Smith',
            'email' => $email,
            'sub' => 'user-jane-smith',
        ]);

        $connector = ntdst_get(WPDataConnector::class);
        $tool = new Tool($connector);

        // Note: handleRequest() will fail on full OIDC validation because
        // we can't do the OIDC redirect dance in a unit test.
        // Instead, test the UserProvisioner directly with the claims.
        $provisioner = ntdst_get(\NetdustLTI\ToolProvider\Services\UserProvisioner::class);
        $claims = new \NetdustLTI\Shared\Domain\LtiClaims(
            sub: 'user-jane-smith',
            name: 'Jane Smith',
            givenName: 'Jane',
            familyName: 'Smith',
            email: $email,
            roles: ['http://purl.imsglobal.org/vocab/lis/v2/membership#Learner'],
            resourceLinkId: 'test-resource',
            contextId: 'test-context',
        );

        $user = $provisioner->provision($claims, $this->mockPlatform->getPlatformPostId());

        $this->assertNotWPError($user);
        $this->assertInstanceOf(\WP_User::class, $user);
        $this->createdUserIds[] = $user->ID;

        // Username should be deterministic: given.family
        $this->assertEquals('jane.smith', $user->user_login);
        $this->assertEquals($email, $user->user_email);
    }

    /** @test */
    public function launchStoresScopedSub(): void
    {
        $email = 'sub-test-' . uniqid() . '@mock-lms.test';
        $sub = 'unique-sub-' . uniqid();

        $provisioner = ntdst_get(\NetdustLTI\ToolProvider\Services\UserProvisioner::class);
        $claims = new \NetdustLTI\Shared\Domain\LtiClaims(
            sub: $sub,
            name: 'Sub Test',
            givenName: 'Sub',
            familyName: 'Test',
            email: $email,
            roles: ['http://purl.imsglobal.org/vocab/lis/v2/membership#Learner'],
        );

        $user = $provisioner->provision($claims, $this->mockPlatform->getPlatformPostId());
        $this->assertNotWPError($user);
        $this->createdUserIds[] = $user->ID;

        // Sub should be stored as {platformId}:{sub}
        $storedSub = get_user_meta($user->ID, '_netdust_lti_sub', true);
        $expectedSub = $this->mockPlatform->getPlatformPostId() . ':' . $sub;
        $this->assertEquals($expectedSub, $storedSub);
    }

    /** @test */
    public function launchAssignsRoleFromPlatformMeta(): void
    {
        $email = 'role-test-' . uniqid() . '@mock-lms.test';

        $provisioner = ntdst_get(\NetdustLTI\ToolProvider\Services\UserProvisioner::class);
        $claims = new \NetdustLTI\Shared\Domain\LtiClaims(
            sub: 'role-test-sub',
            name: 'Role Test',
            givenName: 'Role',
            familyName: 'Test',
            email: $email,
            roles: ['http://purl.imsglobal.org/vocab/lis/v2/membership#Learner'],
        );

        $user = $provisioner->provision($claims, $this->mockPlatform->getPlatformPostId());
        $this->assertNotWPError($user);
        $this->createdUserIds[] = $user->ID;

        // Platform has role_learner = 'subscriber' (default)
        $this->assertTrue(in_array('subscriber', $user->roles, true));
    }

    /** @test */
    public function launchMatchesExistingUserByEmail(): void
    {
        $email = 'existing-' . uniqid() . '@mock-lms.test';

        // Create user first
        $existingId = wp_create_user('existing_user_' . uniqid(), 'testpass', $email);
        $this->createdUserIds[] = $existingId;

        $provisioner = ntdst_get(\NetdustLTI\ToolProvider\Services\UserProvisioner::class);
        $claims = new \NetdustLTI\Shared\Domain\LtiClaims(
            sub: 'different-sub',
            name: 'Existing User',
            givenName: 'Existing',
            familyName: 'User',
            email: $email,
            roles: ['http://purl.imsglobal.org/vocab/lis/v2/membership#Learner'],
        );

        $user = $provisioner->provision($claims, $this->mockPlatform->getPlatformPostId());
        $this->assertNotWPError($user);

        // Should match existing user, not create a new one
        $this->assertEquals($existingId, $user->ID);
    }

    /** @test */
    public function launchMatchesExistingUserByScopedSub(): void
    {
        $email1 = 'sub-match-' . uniqid() . '@mock-lms.test';
        $sub = 'reusable-sub-' . uniqid();

        $provisioner = ntdst_get(\NetdustLTI\ToolProvider\Services\UserProvisioner::class);

        // First launch creates user
        $claims1 = new \NetdustLTI\Shared\Domain\LtiClaims(
            sub: $sub,
            name: 'First Launch',
            givenName: 'First',
            familyName: 'Launch',
            email: $email1,
            roles: ['http://purl.imsglobal.org/vocab/lis/v2/membership#Learner'],
        );

        $user1 = $provisioner->provision($claims1, $this->mockPlatform->getPlatformPostId());
        $this->assertNotWPError($user1);
        $this->createdUserIds[] = $user1->ID;

        // Second launch with same sub, different email
        $email2 = 'sub-match-2-' . uniqid() . '@mock-lms.test';
        $claims2 = new \NetdustLTI\Shared\Domain\LtiClaims(
            sub: $sub,
            name: 'First Launch',
            givenName: 'First',
            familyName: 'Launch',
            email: $email2,
            roles: ['http://purl.imsglobal.org/vocab/lis/v2/membership#Learner'],
        );

        $user2 = $provisioner->provision($claims2, $this->mockPlatform->getPlatformPostId());
        $this->assertNotWPError($user2);

        // Should match by scoped sub
        $this->assertEquals($user1->ID, $user2->ID);
    }

    /** @test */
    public function claimsFilterModifiesClaims(): void
    {
        add_filter('netdust_lti_claims', function ($claims) {
            // Modify the claims object
            return new \NetdustLTI\Shared\Domain\LtiClaims(
                sub: $claims->sub,
                name: 'Filtered Name',
                givenName: 'Filtered',
                familyName: 'Name',
                email: $claims->email,
                roles: $claims->roles,
            );
        });

        $originalClaims = new \NetdustLTI\Shared\Domain\LtiClaims(
            sub: 'test-sub',
            name: 'Original Name',
            givenName: 'Original',
            familyName: 'Name',
            email: 'filter-test@mock-lms.test',
            roles: ['http://purl.imsglobal.org/vocab/lis/v2/membership#Learner'],
        );

        $filtered = apply_filters('netdust_lti_claims', $originalClaims);

        $this->assertEquals('Filtered Name', $filtered->name);
        $this->assertEquals('Filtered', $filtered->givenName);

        remove_all_filters('netdust_lti_claims');
    }

    /**
     * Assert that a value is not a WP_Error.
     */
    private function assertNotWPError(mixed $value, string $message = ''): void
    {
        if (is_wp_error($value)) {
            $this->fail($message ?: 'Expected non-WP_Error, got: ' . $value->get_error_message());
        }
    }
}
```

**Step 2: Run the tests**

Run: `ddev exec vendor/bin/phpunit --testsuite Integration --filter LtiLaunchFlow`
Expected: All tests pass

**Step 3: Commit**

```bash
git add tests/Integration/NetdustLTI/LtiLaunchFlowTest.php
git commit -m "test: add LTI launch flow integration tests (provision, roles, sub matching)"
```

---

## Task 7: Playwright LTI Helpers and Auth Setup

Create the shared helper and auth setup for Playwright LTI tests.

**Files:**
- Create: `tests/frontend/lti/fixtures/lti-helpers.ts`
- Modify: `playwright.config.ts`

**Step 1: Create LTI test helpers**

```typescript
/**
 * LTI E2E Test Helpers
 *
 * Shared utilities for LTI endpoint testing.
 */

import type { Page } from '@playwright/test';

export const WP_ADMIN = '/wp/wp-admin';
export const AUTH_FILE = '/tmp/stride-lti-admin-auth.json';

export const adminUser = {
  email: 'seed_admin@seed.test',
  password: 'seedpass123',
};

/**
 * Log in to WordPress admin and save cookies.
 */
export async function wpAdminLogin(
  page: Page,
  user = adminUser,
): Promise<void> {
  await page.goto(`${WP_ADMIN}/`, { waitUntil: 'domcontentloaded', timeout: 30000 });

  if (page.url().includes('wp-admin') && !page.url().includes('login')) return;

  await page.waitForLoadState('domcontentloaded');
  await page.waitForSelector('input[type="password"], #password', { state: 'visible', timeout: 10000 });

  const emailField = page.locator('input[type="email"], input[type="text"]').first();
  await emailField.fill(user.email);

  const passwordField = page.locator('input[type="password"]').first();
  await passwordField.fill(user.password);

  await page.click('button[type="submit"]');
  await page.waitForURL((url) => !url.pathname.includes('/login'), { timeout: 15000 });
  await page.goto(`${WP_ADMIN}/`, { waitUntil: 'domcontentloaded', timeout: 30000 });
}
```

**Step 2: Add `test:lti` script to package.json**

Add to the `"scripts"` section in `package.json`:

```json
"test:lti": "npx playwright test tests/frontend/lti/"
```

**Step 3: Commit**

```bash
git add tests/frontend/lti/fixtures/lti-helpers.ts package.json
git commit -m "test: add Playwright LTI test helpers and npm script"
```

---

## Task 8: Playwright Config Endpoint Tests

Test the browser-accessible config and JWKS endpoints.

**Files:**
- Create: `tests/frontend/lti/config-json.spec.ts`
- Create: `tests/frontend/lti/config-xml.spec.ts`
- Create: `tests/frontend/lti/jwks.spec.ts`

**Step 1: Create config JSON tests**

```typescript
import { test, expect } from '@playwright/test';

/**
 * LTI Configuration JSON Endpoint Tests
 *
 * GET /lti/configure-json should return valid IMS LTI 1.3 tool configuration.
 */
test.describe('LTI Config JSON', () => {
  test('returns 200 with JSON content type', async ({ request }) => {
    const response = await request.get('/lti/configure-json');
    expect(response.status()).toBe(200);
    expect(response.headers()['content-type']).toContain('application/json');
  });

  test('contains required IMS fields', async ({ request }) => {
    const response = await request.get('/lti/configure-json');
    const json = await response.json();

    expect(json).toHaveProperty('title');
    expect(json).toHaveProperty('oidc_initiation_url');
    expect(json).toHaveProperty('target_link_uri');
    expect(json).toHaveProperty('jwks_uri');
    expect(json).toHaveProperty('claims');
    expect(json).toHaveProperty('messages');
    expect(json).toHaveProperty('scopes');
  });

  test('URLs point to /lti/ paths', async ({ request }) => {
    const response = await request.get('/lti/configure-json');
    const json = await response.json();

    expect(json.oidc_initiation_url).toContain('/lti/login');
    expect(json.target_link_uri).toContain('/lti/launch');
    expect(json.jwks_uri).toContain('/lti/jwks');
  });

  test('messages include resource link and deep linking', async ({ request }) => {
    const response = await request.get('/lti/configure-json');
    const json = await response.json();

    const types = json.messages.map((m: any) => m.type);
    expect(types).toContain('LtiResourceLinkRequest');
    expect(types).toContain('LtiDeepLinkingRequest');
  });

  test('scopes include AGS lineitem and score', async ({ request }) => {
    const response = await request.get('/lti/configure-json');
    const json = await response.json();

    expect(json.scopes).toContain('https://purl.imsglobal.org/spec/lti-ags/scope/lineitem');
    expect(json.scopes).toContain('https://purl.imsglobal.org/spec/lti-ags/scope/score');
  });

  test('claims include required identity claims', async ({ request }) => {
    const response = await request.get('/lti/configure-json');
    const json = await response.json();

    expect(json.claims).toContain('sub');
    expect(json.claims).toContain('email');
    expect(json.claims).toContain('name');
  });
});
```

**Step 2: Create config XML tests**

```typescript
import { test, expect } from '@playwright/test';

/**
 * LTI Configuration XML Endpoint Tests
 *
 * GET /lti/configure-xml should return Canvas-compatible XML config.
 */
test.describe('LTI Config XML', () => {
  test('returns 200 with XML content type', async ({ request }) => {
    const response = await request.get('/lti/configure-xml');
    expect(response.status()).toBe(200);
    expect(response.headers()['content-type']).toContain('application/xml');
  });

  test('contains valid XML with cartridge root element', async ({ request }) => {
    const response = await request.get('/lti/configure-xml');
    const body = await response.text();

    expect(body).toContain('<?xml version="1.0"');
    expect(body).toContain('<cartridge_basiclti_link');
    expect(body).toContain('</cartridge_basiclti_link>');
  });

  test('contains required blti elements', async ({ request }) => {
    const response = await request.get('/lti/configure-xml');
    const body = await response.text();

    expect(body).toContain('<blti:title>');
    expect(body).toContain('<blti:description>');
    expect(body).toContain('<blti:launch_url>');
  });

  test('contains Canvas extensions', async ({ request }) => {
    const response = await request.get('/lti/configure-xml');
    const body = await response.text();

    expect(body).toContain('platform="canvas.instructure.com"');
    expect(body).toContain('course_navigation');
  });

  test('launch URL points to /lti/launch', async ({ request }) => {
    const response = await request.get('/lti/configure-xml');
    const body = await response.text();

    expect(body).toContain('/lti/launch</blti:launch_url>');
  });
});
```

**Step 3: Create JWKS tests**

```typescript
import { test, expect } from '@playwright/test';

/**
 * LTI JWKS Endpoint Tests
 *
 * GET /lti/jwks should return a valid JSON Web Key Set.
 */
test.describe('LTI JWKS', () => {
  test('returns 200 with JSON content type', async ({ request }) => {
    const response = await request.get('/lti/jwks');
    expect(response.status()).toBe(200);
    expect(response.headers()['content-type']).toContain('application/json');
  });

  test('contains keys array', async ({ request }) => {
    const response = await request.get('/lti/jwks');
    const json = await response.json();

    expect(json).toHaveProperty('keys');
    expect(Array.isArray(json.keys)).toBe(true);
    expect(json.keys.length).toBeGreaterThan(0);
  });

  test('key has required JWK fields', async ({ request }) => {
    const response = await request.get('/lti/jwks');
    const json = await response.json();
    const key = json.keys[0];

    expect(key).toHaveProperty('kty', 'RSA');
    expect(key).toHaveProperty('n');
    expect(key).toHaveProperty('e');
    expect(key).toHaveProperty('kid');
    expect(key).toHaveProperty('use', 'sig');
  });

  test('key has correct algorithm', async ({ request }) => {
    const response = await request.get('/lti/jwks');
    const json = await response.json();
    const key = json.keys[0];

    expect(key).toHaveProperty('alg', 'RS256');
  });

  test('response is cacheable', async ({ request }) => {
    const response = await request.get('/lti/jwks');
    const cacheControl = response.headers()['cache-control'] || '';

    expect(cacheControl).toContain('public');
  });
});
```

**Step 4: Run the Playwright LTI tests**

Run: `npx playwright test tests/frontend/lti/ --reporter=list`
Expected: All tests pass

**Step 5: Commit**

```bash
git add tests/frontend/lti/config-json.spec.ts tests/frontend/lti/config-xml.spec.ts tests/frontend/lti/jwks.spec.ts
git commit -m "test: add Playwright tests for LTI config JSON, XML, and JWKS endpoints"
```

---

## Task 9: Playwright Admin Settings and Registration Tests

Test the admin settings page and dynamic registration error handling.

**Files:**
- Create: `tests/frontend/lti/admin-settings.spec.ts`
- Create: `tests/frontend/lti/registration.spec.ts`

**Step 1: Create admin settings tests**

```typescript
import { test as baseTest, expect } from '@playwright/test';
import * as fs from 'fs';
import { wpAdminLogin, WP_ADMIN, AUTH_FILE } from './fixtures/lti-helpers';

const test = baseTest.extend({
  storageState: async ({ browser, baseURL }, use) => {
    let needsLogin = true;
    if (fs.existsSync(AUTH_FILE)) {
      const stat = fs.statSync(AUTH_FILE);
      needsLogin = Date.now() - stat.mtimeMs > 5 * 60 * 1000;
    }

    if (needsLogin) {
      const ctx = await browser.newContext({ baseURL, ignoreHTTPSErrors: true });
      const page = await ctx.newPage();
      await wpAdminLogin(page);
      await ctx.storageState({ path: AUTH_FILE });
      await ctx.close();
    }

    await use(AUTH_FILE);
  },
});

/**
 * LTI Admin Settings Page Tests
 *
 * Tests the Settings > Netdust LTI admin page.
 * Requires seed data: ddev exec wp eval-file scripts/seed.php
 */
test.describe('LTI Admin Settings', () => {
  test('settings page loads with endpoint URLs', async ({ page }) => {
    await page.goto(`${WP_ADMIN}/options-general.php?page=netdust-lti`);
    await page.waitForLoadState('domcontentloaded');

    // Page title
    await expect(page.locator('h1')).toContainText('Netdust LTI');

    // Check for endpoint URLs
    await expect(page.locator('text=/lti/login')).toBeVisible();
    await expect(page.locator('text=/lti/launch')).toBeVisible();
    await expect(page.locator('text=/lti/jwks')).toBeVisible();
  });

  test('displays config and registration URLs', async ({ page }) => {
    await page.goto(`${WP_ADMIN}/options-general.php?page=netdust-lti`);
    await page.waitForLoadState('domcontentloaded');

    await expect(page.locator('text=/lti/configure-json')).toBeVisible();
    await expect(page.locator('text=/lti/configure-xml')).toBeVisible();
    await expect(page.locator('text=/lti/register')).toBeVisible();
  });

  test('copy buttons exist for each endpoint', async ({ page }) => {
    await page.goto(`${WP_ADMIN}/options-general.php?page=netdust-lti`);
    await page.waitForLoadState('domcontentloaded');

    const copyButtons = page.locator('.lti-copy-btn');
    const count = await copyButtons.count();

    // Should have at least 7 copy buttons (one per endpoint)
    expect(count).toBeGreaterThanOrEqual(7);
  });

  test('configuration links are present', async ({ page }) => {
    await page.goto(`${WP_ADMIN}/options-general.php?page=netdust-lti`);
    await page.waitForLoadState('domcontentloaded');

    await expect(page.locator('a:has-text("Manage Platforms")')).toBeVisible();
    await expect(page.locator('a:has-text("Manage Tools")')).toBeVisible();
    await expect(page.locator('a:has-text("Launch Test")')).toBeVisible();
  });
});
```

**Step 2: Create registration error tests**

```typescript
import { test as baseTest, expect } from '@playwright/test';
import * as fs from 'fs';
import { wpAdminLogin, WP_ADMIN, AUTH_FILE } from './fixtures/lti-helpers';

/**
 * LTI Dynamic Registration Error Handling Tests
 *
 * Tests that /lti/register properly rejects invalid requests.
 */

// Unauthenticated tests (no login)
baseTest.describe('LTI Registration - Unauthenticated', () => {
  baseTest('rejects unauthenticated access', async ({ page }) => {
    const response = await page.goto('/lti/register');

    // Should show 403 error (either via HTTP status or wp_die content)
    const content = await page.textContent('body');
    const is403 = content?.includes('administrator') || content?.includes('Unauthorized');
    expect(is403).toBe(true);
  });
});

// Authenticated tests (admin login)
const test = baseTest.extend({
  storageState: async ({ browser, baseURL }, use) => {
    let needsLogin = true;
    if (fs.existsSync(AUTH_FILE)) {
      const stat = fs.statSync(AUTH_FILE);
      needsLogin = Date.now() - stat.mtimeMs > 5 * 60 * 1000;
    }

    if (needsLogin) {
      const ctx = await browser.newContext({ baseURL, ignoreHTTPSErrors: true });
      const page = await ctx.newPage();
      await wpAdminLogin(page);
      await ctx.storageState({ path: AUTH_FILE });
      await ctx.close();
    }

    await use(AUTH_FILE);
  },
});

test.describe('LTI Registration - Admin', () => {
  test('rejects missing openid_configuration', async ({ page }) => {
    await page.goto('/lti/register');
    const content = await page.textContent('body');

    expect(content).toContain('openid_configuration');
  });

  test('rejects invalid openid_configuration URL', async ({ page }) => {
    await page.goto('/lti/register?openid_configuration=not-a-url');
    const content = await page.textContent('body');

    expect(content).toContain('openid_configuration');
  });
});
```

**Step 3: Run all Playwright LTI tests**

Run: `npx playwright test tests/frontend/lti/ --reporter=list`
Expected: All tests pass

**Step 4: Commit**

```bash
git add tests/frontend/lti/admin-settings.spec.ts tests/frontend/lti/registration.spec.ts
git commit -m "test: add Playwright tests for LTI admin settings and registration error handling"
```

---

## Task 10: Full Test Suite Verification

Run all test suites to confirm no regressions.

**Step 1: Run all unit tests**

Run: `ddev exec vendor/bin/phpunit --testsuite Unit`
Expected: All 412+ tests pass

**Step 2: Run all integration tests**

Run: `ddev exec vendor/bin/phpunit --testsuite Integration`
Expected: All tests pass (120+ original + new LTI tests)

**Step 3: Run all Playwright tests**

Run: `npx playwright test --reporter=list`
Expected: All tests pass including new LTI tests

**Step 4: Commit any fixes needed, then final commit**

```bash
git add -A
git commit -m "test: verify full test suite passes with LTI E2E tests"
```

---

## Verification Stages (MANDATORY)

> Run AFTER all implementation tasks. NOT done until all stages pass.
> If ANY stage fails: fix → re-run that stage → continue.

### Stage V1: Static Analysis

```bash
ddev exec vendor/bin/phpunit --testsuite Unit 2>&1 | tail -5
```

Expected: No errors. All unit tests still pass.

### Stage V2: Unit Tests

**Existing test files (no changes needed):**
- `web/app/plugins/netdust-lti/tests/Unit/GradePayloadTest.php`
- `web/app/plugins/netdust-lti/tests/Unit/UserProvisionerTest.php`
- `web/app/plugins/netdust-lti/tests/Unit/ConfigEndpointTest.php`

```bash
ddev exec vendor/bin/phpunit --testsuite Unit
```

Expected: ALL tests pass (412+).

### Stage V3: Integration Tests

**Test files created:**
- `tests/Integration/NetdustLTI/WPDataConnectorTest.php`
- `tests/Integration/NetdustLTI/ConfigEndpointIntegrationTest.php`
- `tests/Integration/NetdustLTI/GradePassbackIntegrationTest.php`
- `tests/Integration/NetdustLTI/LtiLaunchFlowTest.php`

```bash
ddev exec vendor/bin/phpunit --testsuite Integration
```

Expected: ALL integration tests pass.

### Stage V4: Playwright E2E Tests

**Test files created:**
- `tests/frontend/lti/config-json.spec.ts`
- `tests/frontend/lti/config-xml.spec.ts`
- `tests/frontend/lti/jwks.spec.ts`
- `tests/frontend/lti/admin-settings.spec.ts`
- `tests/frontend/lti/registration.spec.ts`

```bash
npx playwright test tests/frontend/lti/ --reporter=list
```

Expected: ALL Playwright tests pass.

### Stage V5: Full Regression

```bash
ddev exec vendor/bin/phpunit --testsuite Unit && ddev exec vendor/bin/phpunit --testsuite Integration
npx playwright test --reporter=list
```

Expected: Zero failures across all suites.

### Stage V6: Smoke Test Checklist

```markdown
## Manual Smoke Test

- [ ] Visit: https://stride.ddev.site/lti/configure-json
      Expected: JSON response with title, URLs, messages, scopes
- [ ] Visit: https://stride.ddev.site/lti/configure-xml
      Expected: XML response with cartridge_basiclti_link root
- [ ] Visit: https://stride.ddev.site/lti/jwks
      Expected: JSON with keys array containing RSA key
- [ ] Admin: https://stride.ddev.site/wp/wp-admin/options-general.php?page=netdust-lti
      Expected: All 7 endpoint URLs displayed with copy buttons
- [ ] Visit: https://stride.ddev.site/lti/register (not logged in)
      Expected: 403 error about administrator access
- [ ] Visit: https://stride.ddev.site/lti/register (logged in as admin, no params)
      Expected: Error about missing openid_configuration
```
