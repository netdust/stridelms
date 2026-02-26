<?php
declare(strict_types=1);

namespace NetdustLTI\Tests\Unit;

use NetdustLTI\Platform\JWTBuilder;
use NetdustLTI\Repositories\ToolRepository;
use PHPUnit\Framework\TestCase;

class JWTBuilderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Reset global state
        global $_test_posts, $_test_data_manager_meta, $_test_options;
        global $_test_current_user_id, $_test_wp_die_called, $_test_user_meta;
        global $_POST, $_GET, $_SESSION, $_test_users, $current_user_caps;

        $_test_posts = [];
        $_test_data_manager_meta = [];
        $_test_options = [
            'home' => 'https://stride.test',
            'netdust_lti_private_key' => $this->getTestPrivateKey(),
            'netdust_lti_kid' => 'test-key-id',
        ];
        $_test_current_user_id = 42;
        $_test_wp_die_called = null;
        $_test_user_meta = [];
        $_test_users = [];
        $current_user_caps = [];
        $_POST = [];
        $_GET = [];
        $_SESSION = [];
    }

    public function test_builds_lti_claims(): void
    {
        $builder = new JWTBuilder(
            $this->createMock(ToolRepository::class)
        );

        $user = (object) [
            'ID' => 1,
            'user_email' => 'test@example.com',
            'display_name' => 'Test User',
        ];

        $claims = $builder->buildLTIClaims($user, 'resource-123', 'https://example.com/launch');

        $this->assertEquals('1.3.0', $claims['https://purl.imsglobal.org/spec/lti/claim/version']);
        $this->assertEquals('LtiResourceLinkRequest', $claims['https://purl.imsglobal.org/spec/lti/claim/message_type']);
    }

    public function test_builds_lti_claims_with_correct_structure(): void
    {
        $builder = new JWTBuilder(
            $this->createMock(ToolRepository::class)
        );

        $user = (object) [
            'ID' => 42,
            'user_email' => 'student@example.com',
            'display_name' => 'Jane Doe',
        ];

        $claims = $builder->buildLTIClaims($user, 'resource-456', 'https://tool.example.com/launch');

        // Verify basic claims
        $this->assertEquals('https://stride.test/', $claims['iss']);
        $this->assertEquals('42', $claims['sub']);
        $this->assertArrayHasKey('iat', $claims);
        $this->assertArrayHasKey('exp', $claims);

        // Verify LTI claims
        $this->assertEquals('1.3.0', $claims['https://purl.imsglobal.org/spec/lti/claim/version']);
        $this->assertEquals('LtiResourceLinkRequest', $claims['https://purl.imsglobal.org/spec/lti/claim/message_type']);

        // Verify resource link
        $resourceLink = $claims['https://purl.imsglobal.org/spec/lti/claim/resource_link'];
        $this->assertEquals('resource-456', $resourceLink['id']);
        $this->assertEquals('Course Launch', $resourceLink['title']);

        // Verify target link URI
        $this->assertEquals('https://tool.example.com/launch', $claims['https://purl.imsglobal.org/spec/lti/claim/target_link_uri']);

        // Verify user identity
        $this->assertEquals('Jane Doe', $claims['name']);
        $this->assertEquals('student@example.com', $claims['email']);
    }

    public function test_builds_lti_claims_with_roles(): void
    {
        $builder = new JWTBuilder(
            $this->createMock(ToolRepository::class)
        );

        $user = (object) [
            'ID' => 1,
            'user_email' => 'test@example.com',
            'display_name' => 'Test User',
        ];

        $claims = $builder->buildLTIClaims($user, 'resource-123', 'https://example.com/launch');

        // Should have roles array
        $this->assertArrayHasKey('https://purl.imsglobal.org/spec/lti/claim/roles', $claims);
        $this->assertIsArray($claims['https://purl.imsglobal.org/spec/lti/claim/roles']);
    }

    public function test_builds_lti_claims_with_context(): void
    {
        $builder = new JWTBuilder(
            $this->createMock(ToolRepository::class)
        );

        $user = (object) [
            'ID' => 1,
            'user_email' => 'test@example.com',
            'display_name' => 'Test User',
        ];

        $claims = $builder->buildLTIClaims($user, 'resource-123', 'https://example.com/launch');

        // Should have context
        $this->assertArrayHasKey('https://purl.imsglobal.org/spec/lti/claim/context', $claims);
        $context = $claims['https://purl.imsglobal.org/spec/lti/claim/context'];
        $this->assertArrayHasKey('id', $context);
        $this->assertArrayHasKey('label', $context);
        $this->assertArrayHasKey('title', $context);
    }

    public function test_builds_lti_claims_with_ags_endpoint(): void
    {
        $builder = new JWTBuilder(
            $this->createMock(ToolRepository::class)
        );

        $user = (object) [
            'ID' => 1,
            'user_email' => 'test@example.com',
            'display_name' => 'Test User',
        ];

        $claims = $builder->buildLTIClaims($user, 'resource-123', 'https://example.com/launch');

        // Should have AGS endpoint
        $this->assertArrayHasKey('https://purl.imsglobal.org/spec/lti-ags/claim/endpoint', $claims);
        $ags = $claims['https://purl.imsglobal.org/spec/lti-ags/claim/endpoint'];
        $this->assertArrayHasKey('scope', $ags);
        $this->assertArrayHasKey('lineitem', $ags);
    }

    public function test_get_user_roles_returns_admin_for_manage_options(): void
    {
        global $current_user_caps;
        $current_user_caps = ['manage_options' => true];

        $builder = new JWTBuilder(
            $this->createMock(ToolRepository::class)
        );

        $user = (object) [
            'ID' => 1,
            'user_email' => 'admin@example.com',
            'display_name' => 'Admin User',
        ];

        $claims = $builder->buildLTIClaims($user, 'resource-123', 'https://example.com/launch');
        $roles = $claims['https://purl.imsglobal.org/spec/lti/claim/roles'];

        $this->assertContains('http://purl.imsglobal.org/vocab/lis/v2/membership#Administrator', $roles);
        $this->assertContains('http://purl.imsglobal.org/vocab/lis/v2/membership#Instructor', $roles);
    }

    public function test_get_user_roles_returns_instructor_for_edit_posts(): void
    {
        global $current_user_caps;
        $current_user_caps = ['manage_options' => false, 'edit_posts' => true];

        $builder = new JWTBuilder(
            $this->createMock(ToolRepository::class)
        );

        $user = (object) [
            'ID' => 2,
            'user_email' => 'instructor@example.com',
            'display_name' => 'Instructor User',
        ];

        $claims = $builder->buildLTIClaims($user, 'resource-123', 'https://example.com/launch');
        $roles = $claims['https://purl.imsglobal.org/spec/lti/claim/roles'];

        $this->assertContains('http://purl.imsglobal.org/vocab/lis/v2/membership#Instructor', $roles);
        $this->assertNotContains('http://purl.imsglobal.org/vocab/lis/v2/membership#Administrator', $roles);
    }

    public function test_get_user_roles_returns_learner_for_subscriber(): void
    {
        global $current_user_caps;
        $current_user_caps = ['manage_options' => false, 'edit_posts' => false];

        $builder = new JWTBuilder(
            $this->createMock(ToolRepository::class)
        );

        $user = (object) [
            'ID' => 3,
            'user_email' => 'student@example.com',
            'display_name' => 'Student User',
        ];

        $claims = $builder->buildLTIClaims($user, 'resource-123', 'https://example.com/launch');
        $roles = $claims['https://purl.imsglobal.org/spec/lti/claim/roles'];

        $this->assertContains('http://purl.imsglobal.org/vocab/lis/v2/membership#Learner', $roles);
        $this->assertNotContains('http://purl.imsglobal.org/vocab/lis/v2/membership#Administrator', $roles);
        $this->assertNotContains('http://purl.imsglobal.org/vocab/lis/v2/membership#Instructor', $roles);
    }

    public function test_handle_auth_callback_fails_with_invalid_state(): void
    {
        global $_GET, $_SESSION;
        $_GET = ['state' => 'invalid-state'];
        $_SESSION = ['lti_platform_state' => 'valid-state'];

        $builder = new JWTBuilder(
            $this->createMock(ToolRepository::class)
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid state parameter');

        $builder->handleAuthCallback();
    }

    public function test_handle_auth_callback_fails_with_missing_state(): void
    {
        global $_GET, $_SESSION;
        $_GET = [];
        $_SESSION = ['lti_platform_state' => 'valid-state'];

        $builder = new JWTBuilder(
            $this->createMock(ToolRepository::class)
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid state parameter');

        $builder->handleAuthCallback();
    }

    public function test_handle_auth_callback_fails_for_missing_tool(): void
    {
        global $_GET, $_SESSION;
        $state = 'valid-state-12345';
        $_GET = ['state' => $state];
        $_SESSION = [
            'lti_platform_state' => $state,
            'lti_platform_nonce' => 'test-nonce',
            'lti_platform_tool_id' => 999,
            'lti_platform_resource_link_id' => 'resource-123',
            'lti_platform_target_link_uri' => 'https://tool.example.com/launch',
        ];

        $toolRepo = $this->createMock(ToolRepository::class);
        $toolRepo->method('find')
            ->with(999)
            ->willReturn(new \WP_Error('not_found', 'Tool not found'));

        $builder = new JWTBuilder($toolRepo);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Tool not found');

        $builder->handleAuthCallback();
    }

    public function test_claims_expiry_is_in_future(): void
    {
        $builder = new JWTBuilder(
            $this->createMock(ToolRepository::class)
        );

        $user = (object) [
            'ID' => 1,
            'user_email' => 'test@example.com',
            'display_name' => 'Test User',
        ];

        $claims = $builder->buildLTIClaims($user, 'resource-123', 'https://example.com/launch');

        $this->assertGreaterThan(time(), $claims['exp']);
        $this->assertLessThanOrEqual(time() + 3600, $claims['exp']);
    }

    public function test_claims_issued_at_is_current_time(): void
    {
        $builder = new JWTBuilder(
            $this->createMock(ToolRepository::class)
        );

        $user = (object) [
            'ID' => 1,
            'user_email' => 'test@example.com',
            'display_name' => 'Test User',
        ];

        $beforeTime = time();
        $claims = $builder->buildLTIClaims($user, 'resource-123', 'https://example.com/launch');
        $afterTime = time();

        $this->assertGreaterThanOrEqual($beforeTime, $claims['iat']);
        $this->assertLessThanOrEqual($afterTime, $claims['iat']);
    }

    /**
     * Generate a test RSA private key for signing JWTs in tests.
     */
    private function getTestPrivateKey(): string
    {
        // A minimal test RSA key for testing purposes only
        return <<<'EOD'
-----BEGIN RSA PRIVATE KEY-----
MIIEowIBAAKCAQEA0m59l2u9iDnMbrXHfqkOrn2dVQ3vfBJqcDuFUK03d+1PZGbV
XMNW2xNe5y8BLiA9JyXJ6dj5VfDPqDQXYmzAC0cTMJvJFJ/1YhHAMCwXpFmxZLJh
ew2K1YXmhZLaupFpuF2mf55Y76z9i3NqAqLwXFCqxQQX9rMvcbTkVBfOYHLLkqm5
pHFWdLSMfZxrYT/qFyYFnrqWzP4qvBBYoKLUTH8A3lG7g0YmJVZI47wN3FPmhfnH
KGgKj9qAVQHpO3WyM7XiJjH8mMBgaXpYEoFO0ZVqmNqLsNO7lNiH2KH2gQXqKQm8
VzPQLHqUEftZsVbXxUCOwUkCZKcNBJMDPY8DgwIDAQABAoIBAAhLu+gKP0YgHHSg
TW+8FTzjZGxQFpfnJcyN6T7KjJKT9wIZCqAqD6rC1hSgCMRq/VjA4pJvRPg7Vq5I
0aVAqGq4fJPhqR4J8xfuJZJvMoOmWZZMjJ1x4d5VtEpPTm8X5s5LrkFvY8XMJVYQ
wGYMMqQFg/6tLEsaVf8S7aHSJJMV0ZQQpRAQDrz1LHQK1O0kF9c4I7l/bWN3F5GH
HpU7VTmZ8y8dW5qY+j3rCC3PJZL3I8A0ZjQ+KhL2d3MZ8jh7xdVLMsOO6CnZQNXA
ZjGJKPfQ+y5JvNvC+p4E8E3jAqKSmLSjQ9qQm0mOdJAuXp7b4TJYrZmI+pMX7HJJ
TqWTEOECgYEA7S+HzlThWQIxHrjCzAWCq+0q3EQxghZPZZBFTQwq3VdB4V0Cw7G+
8jQGz5VJqKs3TsLHVQWEpF9gj0F7fVj+b8k8xJNJ7zqPWMGF/9fDDAWpljQOyKJd
MkRqKHqc7eZKN2Zj8k7q/nJHZnXxp9PjO3n8r3y+8XvHhk5V0VHsGssCgYEA40r7
5vEjO0sH+F3EpLO7Vka0VT2P0Z0wXdCxJcgq3oLA3rq8B0J/vWvQZvSH8wNGRQmj
+/bIB6l1VqTpD1rpyQpLwQX0MFCR/0hH0SuhJLNj8wSINcO/rT0x3A9yYAiQXnq6
LVEbZNFchZ8bMzn/pJC0cE9QN6iZJE6xp8nFOOkCgYEAz/5/8Tf1IXKA8VKVbUhL
nF7cF8kLI/lhYzVJ4JEpz9HvL+UoRmKY0xJqJOmRg9V0NJb8X3Q8i4dKQNgHZA9N
Eq/A8KHkpHJfCwPqLt3JA3sL6LvICxX9vK0lCqF7n9PDAKOGxpMa2qA7TAJo7RPE
dC7K0hJCZDf9J0A/4WQiEbcCgYB9dEH4z7TU7fQPQLD5Y9cEH8Bs1XEeIQz8DzSK
AK5lLSJ3+i3QpDBgF8TJz7Q7KqpGsHq+VhSMHJKPgYCvyXCJmT3L3F9TxZ7lFGMK
TKJZ3PQq7q0pUPIMRhXQoHPOJFH5nN5FXCNYTYvb7P5C7gCZPOfpMQQJJGv4gLZP
AKJroQKBgCJYE8xfkPEq4nVP0JQ3LxQN5nRqKCm5l5aXJqVqRJqmRJKmyRGkPJKG
tAkCHpJORRBJd0XKA8pLxA9rGtVxZPQthYwpQf0ixr3GKe9ILfz0w/nkXdIr8g7k
dJwEGQMgWFN6Y6JT7VCIJ0Mfy3cF8QxJjk9hQPFb/TqAHxmHXIUa
-----END RSA PRIVATE KEY-----
EOD;
    }
}
