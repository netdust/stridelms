<?php

declare(strict_types=1);

namespace Stride\Tests\Integration\NetdustLTI;

use IntegrationTestCase;
use NetdustLTI\Shared\Domain\LtiClaims;
use NetdustLTI\ToolProvider\Services\UserProvisioner;
use WP_User;

/**
 * Integration tests for the full LTI launch flow.
 *
 * Tests UserProvisioner with real WordPress: user creation, scoped sub storage,
 * role assignment from platform meta, email matching, and claims filtering.
 *
 * Run: ddev exec vendor/bin/phpunit --testsuite Integration --filter LtiLaunchFlow
 */
class LtiLaunchFlowTest extends IntegrationTestCase
{
    private MockLtiPlatform $mockPlatform;
    private UserProvisioner $provisioner;

    /** @var list<int> User IDs created during tests, cleaned up in tearDown */
    private array $createdUserIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockPlatform = new MockLtiPlatform();
        $this->mockPlatform->register();

        // Track platform post for cleanup
        self::$testPosts[] = $this->mockPlatform->getPlatformPostId();

        $this->provisioner = new UserProvisioner();
    }

    protected function tearDown(): void
    {
        MockLtiPlatform::resetSuperglobals();

        // Remove any filter hooks added during tests
        remove_all_filters('netdust_lti_claims');
        remove_all_filters('netdust_lti_provision_user_data');

        // Clean up any users created during launch
        require_once ABSPATH . 'wp-admin/includes/user.php';
        foreach ($this->createdUserIds as $userId) {
            wp_delete_user($userId);
        }
        $this->createdUserIds = [];

        parent::tearDown();
    }

    // =========================================================================
    // Helper: build LtiClaims with all required parameters
    // =========================================================================

    private function makeClaims(array $overrides = []): LtiClaims
    {
        $defaults = [
            'sub' => 'test-sub-' . uniqid(),
            'email' => 'ltiuser-' . uniqid() . '@mock-lms.test',
            'name' => 'Test User',
            'givenName' => 'Test',
            'familyName' => 'User',
            'contextId' => 'ctx-1',
            'contextTitle' => 'Test Context',
            'resourceLinkId' => 'rl-1',
            'resourceLinkTitle' => 'Test Resource',
            'roles' => ['http://purl.imsglobal.org/vocab/lis/v2/membership#Learner'],
            'custom' => [],
            'lineItemUrl' => null,
            'lineItemsUrl' => null,
            'scoresUrl' => null,
        ];

        $data = array_merge($defaults, $overrides);

        return new LtiClaims(
            sub: $data['sub'],
            email: $data['email'],
            name: $data['name'],
            givenName: $data['givenName'],
            familyName: $data['familyName'],
            contextId: $data['contextId'],
            contextTitle: $data['contextTitle'],
            resourceLinkId: $data['resourceLinkId'],
            resourceLinkTitle: $data['resourceLinkTitle'],
            roles: $data['roles'],
            custom: $data['custom'],
            lineItemUrl: $data['lineItemUrl'],
            lineItemsUrl: $data['lineItemsUrl'],
            scoresUrl: $data['scoresUrl'],
        );
    }

    // =========================================================================
    // User Creation Tests
    // =========================================================================

    /** @test */
    public function launchCreatesNewUserWithCorrectUsername(): void
    {
        $email = 'launch-test-' . uniqid() . '@mock-lms.test';

        $claims = $this->makeClaims([
            'sub' => 'user-jane-smith',
            'name' => 'Jane Smith',
            'givenName' => 'Jane',
            'familyName' => 'Smith',
            'email' => $email,
        ]);

        $user = $this->provisioner->provision($claims, $this->mockPlatform->getPlatformPostId());

        $this->assertNotWPError($user);
        $this->assertInstanceOf(WP_User::class, $user);
        $this->createdUserIds[] = $user->ID;

        // Username should be deterministic: given.family (lowercase)
        $this->assertEquals('jane.smith', $user->user_login);
        $this->assertEquals($email, $user->user_email);
        $this->assertEquals('Jane Smith', $user->display_name);
        $this->assertEquals('Jane', $user->first_name);
        $this->assertEquals('Smith', $user->last_name);
    }

    /** @test */
    public function launchCreatesUserWithEmailPrefixWhenNoNames(): void
    {
        $email = 'nonames-' . uniqid() . '@mock-lms.test';

        $claims = $this->makeClaims([
            'sub' => 'user-no-names',
            'name' => 'No Names',
            'givenName' => null,
            'familyName' => null,
            'email' => $email,
        ]);

        $user = $this->provisioner->provision($claims, $this->mockPlatform->getPlatformPostId());

        $this->assertNotWPError($user);
        $this->assertInstanceOf(WP_User::class, $user);
        $this->createdUserIds[] = $user->ID;

        // Should fall back to email prefix
        $expectedPrefix = explode('@', $email)[0];
        $this->assertStringStartsWith($expectedPrefix, $user->user_login);
    }

    // =========================================================================
    // Scoped Sub Storage Tests
    // =========================================================================

    /** @test */
    public function launchStoresScopedSub(): void
    {
        $sub = 'unique-sub-' . uniqid();

        $claims = $this->makeClaims([
            'sub' => $sub,
            'givenName' => 'Sub',
            'familyName' => 'Test',
        ]);

        $user = $this->provisioner->provision($claims, $this->mockPlatform->getPlatformPostId());
        $this->assertNotWPError($user);
        $this->createdUserIds[] = $user->ID;

        // Sub should be stored as {platformPostId}:{sub}
        $storedSub = get_user_meta($user->ID, '_netdust_lti_sub', true);
        $expectedSub = $this->mockPlatform->getPlatformPostId() . ':' . $sub;
        $this->assertEquals($expectedSub, $storedSub);
    }

    /** @test */
    public function launchStoresLastLoginTimestamp(): void
    {
        $claims = $this->makeClaims([
            'sub' => 'login-ts-' . uniqid(),
            'givenName' => 'Login',
            'familyName' => 'Stamp',
        ]);

        $user = $this->provisioner->provision($claims, $this->mockPlatform->getPlatformPostId());
        $this->assertNotWPError($user);
        $this->createdUserIds[] = $user->ID;

        $lastLogin = get_user_meta($user->ID, '_netdust_lti_last_login', true);
        $this->assertNotEmpty($lastLogin, 'Last login timestamp should be stored');
    }

    /** @test */
    public function launchSetsProvisionedFlag(): void
    {
        $claims = $this->makeClaims([
            'sub' => 'provision-flag-' . uniqid(),
            'givenName' => 'Provision',
            'familyName' => 'Flag',
        ]);

        $user = $this->provisioner->provision($claims, $this->mockPlatform->getPlatformPostId());
        $this->assertNotWPError($user);
        $this->createdUserIds[] = $user->ID;

        // New users should have the provisioned flag set
        $this->assertTrue($this->provisioner->isLtiUser($user->ID));
    }

    // =========================================================================
    // Role Assignment Tests
    // =========================================================================

    /** @test */
    public function launchAssignsLearnerRoleFromPlatformMeta(): void
    {
        $claims = $this->makeClaims([
            'sub' => 'role-learner-' . uniqid(),
            'givenName' => 'Role',
            'familyName' => 'Learner',
            'roles' => ['http://purl.imsglobal.org/vocab/lis/v2/membership#Learner'],
        ]);

        $user = $this->provisioner->provision($claims, $this->mockPlatform->getPlatformPostId());
        $this->assertNotWPError($user);
        $this->createdUserIds[] = $user->ID;

        // MockLtiPlatform registers role_learner = 'subscriber'
        $this->assertTrue(
            in_array('subscriber', $user->roles, true),
            'Learner should get subscriber role. Actual roles: ' . implode(', ', $user->roles)
        );
    }

    /** @test */
    public function launchAssignsInstructorRoleFromPlatformMeta(): void
    {
        // Ensure 'instructor' role exists in test environment
        // (in production it's registered by the LTI plugin activation)
        if (!get_role('instructor')) {
            add_role('instructor', 'Instructor', ['read' => true]);
        }

        $claims = $this->makeClaims([
            'sub' => 'role-instructor-' . uniqid(),
            'givenName' => 'Role',
            'familyName' => 'Instructor',
            'roles' => ['http://purl.imsglobal.org/vocab/lis/v2/membership#Instructor'],
        ]);

        $user = $this->provisioner->provision($claims, $this->mockPlatform->getPlatformPostId());
        $this->assertNotWPError($user);
        $this->createdUserIds[] = $user->ID;

        // MockLtiPlatform registers role_instructor = 'instructor'
        $this->assertTrue(
            in_array('instructor', $user->roles, true),
            'Instructor should get instructor role. Actual roles: ' . implode(', ', $user->roles)
        );
    }

    // =========================================================================
    // Email Matching Tests
    // =========================================================================

    /** @test */
    public function launchMatchesExistingUserByEmail(): void
    {
        $email = 'existing-' . uniqid() . '@mock-lms.test';

        // Create a regular WP user first
        $existingId = wp_create_user('existing_user_' . uniqid(), 'testpass123', $email);
        $this->assertIsInt($existingId, 'Failed to create test user');
        $this->createdUserIds[] = $existingId;

        // Now provision via LTI with the same email
        $claims = $this->makeClaims([
            'sub' => 'different-sub-' . uniqid(),
            'name' => 'Existing User',
            'givenName' => 'Existing',
            'familyName' => 'User',
            'email' => $email,
        ]);

        $user = $this->provisioner->provision($claims, $this->mockPlatform->getPlatformPostId());
        $this->assertNotWPError($user);

        // Should match existing user, not create a new one
        $this->assertEquals($existingId, $user->ID, 'Should match existing user by email');

        // Should store scoped sub on the existing user
        $storedSub = get_user_meta($user->ID, '_netdust_lti_sub', true);
        $this->assertStringContains(':', $storedSub, 'Scoped sub should be stored on matched user');
    }

    /** @test */
    public function launchDoesNotCreateDuplicateUserForSameEmail(): void
    {
        $email = 'nodupe-' . uniqid() . '@mock-lms.test';

        // First provision
        $claims1 = $this->makeClaims([
            'sub' => 'first-sub-' . uniqid(),
            'givenName' => 'First',
            'familyName' => 'Nodupe',
            'email' => $email,
        ]);

        $user1 = $this->provisioner->provision($claims1, $this->mockPlatform->getPlatformPostId());
        $this->assertNotWPError($user1);
        $this->createdUserIds[] = $user1->ID;

        // Second provision with different sub but same email
        $claims2 = $this->makeClaims([
            'sub' => 'second-sub-' . uniqid(),
            'givenName' => 'Second',
            'familyName' => 'Nodupe',
            'email' => $email,
        ]);

        $user2 = $this->provisioner->provision($claims2, $this->mockPlatform->getPlatformPostId());
        $this->assertNotWPError($user2);

        // Same user should be returned (matched by scoped sub from first provision,
        // since the sub stored after first provision won't match second sub,
        // it falls through to email match)
        $this->assertEquals($user1->ID, $user2->ID, 'Second provision should match first user by email');
    }

    // =========================================================================
    // Scoped Sub Matching Tests
    // =========================================================================

    /** @test */
    public function launchMatchesExistingUserByScopedSub(): void
    {
        $sub = 'reusable-sub-' . uniqid();
        $email1 = 'sub-match-1-' . uniqid() . '@mock-lms.test';

        // First launch creates user
        $claims1 = $this->makeClaims([
            'sub' => $sub,
            'givenName' => 'First',
            'familyName' => 'Launch',
            'email' => $email1,
        ]);

        $user1 = $this->provisioner->provision($claims1, $this->mockPlatform->getPlatformPostId());
        $this->assertNotWPError($user1);
        $this->createdUserIds[] = $user1->ID;

        // Second launch with same sub but different email
        $email2 = 'sub-match-2-' . uniqid() . '@mock-lms.test';
        $claims2 = $this->makeClaims([
            'sub' => $sub,
            'givenName' => 'First',
            'familyName' => 'Launch',
            'email' => $email2,
        ]);

        $user2 = $this->provisioner->provision($claims2, $this->mockPlatform->getPlatformPostId());
        $this->assertNotWPError($user2);

        // Should match by scoped sub, not create new user
        $this->assertEquals($user1->ID, $user2->ID, 'Should match existing user by scoped sub');
    }

    // =========================================================================
    // Claims Filter Tests
    // =========================================================================

    /** @test */
    public function claimsFilterModifiesClaimsBeforeProvisioning(): void
    {
        $filterEmail = 'filter-test-' . uniqid() . '@mock-lms.test';

        // Register a filter that modifies claims
        add_filter('netdust_lti_claims', function (LtiClaims $claims) {
            return new LtiClaims(
                sub: $claims->sub,
                email: $claims->email,
                name: 'Filtered Name',
                givenName: 'Filtered',
                familyName: 'Name',
                contextId: $claims->contextId,
                contextTitle: $claims->contextTitle,
                resourceLinkId: $claims->resourceLinkId,
                resourceLinkTitle: $claims->resourceLinkTitle,
                roles: $claims->roles,
                custom: $claims->custom,
                lineItemUrl: $claims->lineItemUrl,
                lineItemsUrl: $claims->lineItemsUrl,
                scoresUrl: $claims->scoresUrl,
            );
        });

        $originalClaims = $this->makeClaims([
            'sub' => 'filter-sub-' . uniqid(),
            'name' => 'Original Name',
            'givenName' => 'Original',
            'familyName' => 'Name',
            'email' => $filterEmail,
        ]);

        // Apply the filter (as the Tool would do before provisioning)
        $filteredClaims = apply_filters('netdust_lti_claims', $originalClaims);

        $this->assertEquals('Filtered Name', $filteredClaims->name);
        $this->assertEquals('Filtered', $filteredClaims->givenName);
        $this->assertEquals('Name', $filteredClaims->familyName);

        // Verify the filtered claims can be used for provisioning
        $user = $this->provisioner->provision($filteredClaims, $this->mockPlatform->getPlatformPostId());
        $this->assertNotWPError($user);
        $this->createdUserIds[] = $user->ID;

        // Username should reflect filtered claims
        $this->assertEquals('filtered.name', $user->user_login);
    }

    /** @test */
    public function userDataFilterModifiesProvisionedUser(): void
    {
        // Register the netdust_lti_provision_user_data filter
        add_filter('netdust_lti_provision_user_data', function (array $userData, LtiClaims $claims) {
            $userData['display_name'] = 'Custom Display Name';
            return $userData;
        }, 10, 2);

        $claims = $this->makeClaims([
            'sub' => 'userdata-filter-' . uniqid(),
            'givenName' => 'Userdata',
            'familyName' => 'Filter',
        ]);

        $user = $this->provisioner->provision($claims, $this->mockPlatform->getPlatformPostId());
        $this->assertNotWPError($user);
        $this->createdUserIds[] = $user->ID;

        $this->assertEquals('Custom Display Name', $user->display_name);
    }

    // =========================================================================
    // Assertion Helpers
    // =========================================================================

    private function assertNotWPError(mixed $value, string $message = ''): void
    {
        if (is_wp_error($value)) {
            $this->fail($message ?: 'Expected non-WP_Error, got: ' . $value->get_error_message());
        }
    }

    private function assertStringContains(string $needle, string $haystack, string $message = ''): void
    {
        $this->assertTrue(
            str_contains($haystack, $needle),
            $message ?: "Expected string to contain '{$needle}', got: '{$haystack}'"
        );
    }
}
