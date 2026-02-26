<?php
declare(strict_types=1);

namespace NetdustLTI\Tests\Unit;

use NetdustLTI\Platform\OIDCInitiator;
use NetdustLTI\Repositories\ToolRepository;
use PHPUnit\Framework\TestCase;

class OIDCInitiatorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Reset global state
        global $_test_posts, $_test_data_manager_meta, $_test_options;
        global $_test_current_user_id, $_test_redirect_url, $_test_wp_die_called;
        global $_POST, $_GET, $_SESSION;

        $_test_posts = [];
        $_test_data_manager_meta = [];
        $_test_options = ['home' => 'https://stride.test'];
        $_test_current_user_id = 42;
        $_test_redirect_url = null;
        $_test_wp_die_called = null;
        $_POST = [];
        $_GET = [];
        $_SESSION = [];
    }

    public function test_generates_valid_state(): void
    {
        $initiator = new OIDCInitiator(
            $this->createMock(ToolRepository::class)
        );

        $state = $initiator->generateState();

        $this->assertNotEmpty($state);
        $this->assertEquals(64, strlen($state)); // 32 bytes hex encoded
    }

    public function test_generates_valid_nonce(): void
    {
        $initiator = new OIDCInitiator(
            $this->createMock(ToolRepository::class)
        );

        $nonce = $initiator->generateNonce();

        $this->assertNotEmpty($nonce);
        $this->assertEquals(32, strlen($nonce)); // 16 bytes hex encoded
    }

    public function test_state_is_cryptographically_random(): void
    {
        $initiator = new OIDCInitiator(
            $this->createMock(ToolRepository::class)
        );

        $state1 = $initiator->generateState();
        $state2 = $initiator->generateState();

        $this->assertNotEquals($state1, $state2);
    }

    public function test_nonce_is_cryptographically_random(): void
    {
        $initiator = new OIDCInitiator(
            $this->createMock(ToolRepository::class)
        );

        $nonce1 = $initiator->generateNonce();
        $nonce2 = $initiator->generateNonce();

        $this->assertNotEquals($nonce1, $nonce2);
    }

    public function test_initiate_launch_dies_without_tool_id(): void
    {
        global $_POST, $_GET;
        $_POST = [];
        $_GET = [];

        $initiator = new OIDCInitiator(
            $this->createMock(ToolRepository::class)
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Missing tool_id parameter');

        $initiator->initiateLaunch();
    }

    public function test_initiate_launch_dies_for_invalid_tool(): void
    {
        global $_POST;
        $_POST = ['tool_id' => 999];

        $toolRepo = $this->createMock(ToolRepository::class);
        $toolRepo->method('find')
            ->with(999)
            ->willReturn(new \WP_Error('not_found', 'Tool not found'));

        $initiator = new OIDCInitiator($toolRepo);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Tool not found');

        $initiator->initiateLaunch();
    }

    public function test_initiate_launch_stores_session_data(): void
    {
        global $_POST, $_SESSION, $_test_posts, $_test_data_manager_meta, $_test_redirect_url;

        // Set up a tool
        $toolPost = new \WP_Post([
            'ID' => 1,
            'post_type' => 'lti_tool',
            'post_title' => 'Test Tool',
            'post_status' => 'publish',
        ]);
        $toolPost->fields = [
            'launch_url' => 'https://tool.example.com/launch',
            'oidc_url' => 'https://tool.example.com/oidc/login',
            'client_id' => 'test-client-id',
            'deployment_id' => 'dep-1',
        ];

        $_test_posts[1] = $toolPost;
        $_test_data_manager_meta['lti_tool'][1] = $toolPost->fields;

        $_POST = [
            'tool_id' => 1,
            'resource_link_id' => 'resource-123',
            'target_link_uri' => 'https://tool.example.com/custom',
        ];

        $toolRepo = $this->createMock(ToolRepository::class);
        $toolRepo->method('find')
            ->with(1)
            ->willReturn($toolPost);

        $initiator = new OIDCInitiator($toolRepo);

        // Mock exit by catching output
        try {
            $initiator->initiateLaunch();
        } catch (\Exception $e) {
            // Expected - we mock wp_redirect to throw
        }

        // Verify session data was stored
        $this->assertArrayHasKey('lti_platform_state', $_SESSION);
        $this->assertArrayHasKey('lti_platform_nonce', $_SESSION);
        $this->assertEquals(1, $_SESSION['lti_platform_tool_id']);
        $this->assertEquals('resource-123', $_SESSION['lti_platform_resource_link_id']);
        $this->assertEquals('https://tool.example.com/custom', $_SESSION['lti_platform_target_link_uri']);

        // Verify state and nonce are valid
        $this->assertEquals(64, strlen($_SESSION['lti_platform_state']));
        $this->assertEquals(32, strlen($_SESSION['lti_platform_nonce']));
    }

    public function test_initiate_launch_redirects_to_tool_oidc_url(): void
    {
        global $_POST, $_SESSION, $_test_redirect_url;

        $toolPost = new \WP_Post([
            'ID' => 1,
            'post_type' => 'lti_tool',
            'post_title' => 'Test Tool',
            'post_status' => 'publish',
        ]);
        $toolPost->fields = [
            'launch_url' => 'https://tool.example.com/launch',
            'oidc_url' => 'https://tool.example.com/oidc/login',
            'client_id' => 'test-client-id',
            'deployment_id' => 'dep-1',
        ];

        $_POST = ['tool_id' => 1];

        $toolRepo = $this->createMock(ToolRepository::class);
        $toolRepo->method('find')
            ->with(1)
            ->willReturn($toolPost);

        $initiator = new OIDCInitiator($toolRepo);

        try {
            $initiator->initiateLaunch();
        } catch (\Exception $e) {
            // Expected
        }

        // Verify redirect URL
        $this->assertNotNull($_test_redirect_url);
        $this->assertStringContainsString('https://tool.example.com/oidc/login', $_test_redirect_url);
        $this->assertStringContainsString('iss=', $_test_redirect_url);
        $this->assertStringContainsString('client_id=test-client-id', $_test_redirect_url);
        $this->assertStringContainsString('lti_deployment_id=dep-1', $_test_redirect_url);
        $this->assertStringContainsString('login_hint=42', $_test_redirect_url);
    }

    public function test_initiate_launch_uses_tool_launch_url_when_no_target_provided(): void
    {
        global $_POST, $_test_redirect_url;

        $toolPost = new \WP_Post([
            'ID' => 1,
            'post_type' => 'lti_tool',
            'post_title' => 'Test Tool',
            'post_status' => 'publish',
        ]);
        $toolPost->fields = [
            'launch_url' => 'https://tool.example.com/default-launch',
            'oidc_url' => 'https://tool.example.com/oidc/login',
            'client_id' => 'test-client-id',
            'deployment_id' => '',
        ];

        $_POST = ['tool_id' => 1];

        $toolRepo = $this->createMock(ToolRepository::class);
        $toolRepo->method('find')
            ->with(1)
            ->willReturn($toolPost);

        $initiator = new OIDCInitiator($toolRepo);

        try {
            $initiator->initiateLaunch();
        } catch (\Exception $e) {
            // Expected
        }

        // Verify target_link_uri uses launch_url as default
        $this->assertNotNull($_test_redirect_url);
        $this->assertStringContainsString(
            'target_link_uri=' . urlencode('https://tool.example.com/default-launch'),
            $_test_redirect_url
        );
    }

    public function test_initiate_launch_defaults_deployment_id_to_one(): void
    {
        global $_POST, $_test_redirect_url;

        $toolPost = new \WP_Post([
            'ID' => 1,
            'post_type' => 'lti_tool',
            'post_title' => 'Test Tool',
            'post_status' => 'publish',
        ]);
        $toolPost->fields = [
            'launch_url' => 'https://tool.example.com/launch',
            'oidc_url' => 'https://tool.example.com/oidc/login',
            'client_id' => 'test-client-id',
            'deployment_id' => '', // Empty deployment ID
        ];

        $_POST = ['tool_id' => 1];

        $toolRepo = $this->createMock(ToolRepository::class);
        $toolRepo->method('find')
            ->with(1)
            ->willReturn($toolPost);

        $initiator = new OIDCInitiator($toolRepo);

        try {
            $initiator->initiateLaunch();
        } catch (\Exception $e) {
            // Expected
        }

        // Verify deployment_id defaults to "1"
        $this->assertNotNull($_test_redirect_url);
        $this->assertStringContainsString('lti_deployment_id=1', $_test_redirect_url);
    }

    public function test_initiate_launch_accepts_tool_id_from_get(): void
    {
        global $_POST, $_GET, $_test_redirect_url;

        $toolPost = new \WP_Post([
            'ID' => 5,
            'post_type' => 'lti_tool',
            'post_title' => 'Test Tool',
            'post_status' => 'publish',
        ]);
        $toolPost->fields = [
            'launch_url' => 'https://tool.example.com/launch',
            'oidc_url' => 'https://tool.example.com/oidc/login',
            'client_id' => 'test-client-id',
            'deployment_id' => 'dep-1',
        ];

        $_POST = [];
        $_GET = ['tool_id' => 5];

        $toolRepo = $this->createMock(ToolRepository::class);
        $toolRepo->method('find')
            ->with(5)
            ->willReturn($toolPost);

        $initiator = new OIDCInitiator($toolRepo);

        try {
            $initiator->initiateLaunch();
        } catch (\Exception $e) {
            // Expected
        }

        // Verify redirect occurred (meaning tool_id was read from GET)
        $this->assertNotNull($_test_redirect_url);
    }
}
