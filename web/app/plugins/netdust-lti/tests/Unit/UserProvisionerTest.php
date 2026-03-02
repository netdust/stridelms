<?php
declare(strict_types=1);

namespace NetdustLTI\Tests\Unit;

use NetdustLTI\ToolProvider\Services\UserProvisioner;
use NetdustLTI\Shared\Domain\LtiClaims;
use PHPUnit\Framework\TestCase;
use WP_User;

class UserProvisionerTest extends TestCase
{
    private UserProvisioner $provisioner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->provisioner = new UserProvisioner();

        // Reset global test state
        global $_test_users, $_test_user_meta, $_test_transients, $_test_filters, $_test_data_manager_meta;
        $_test_users = [];
        $_test_user_meta = [];
        $_test_transients = [];
        $_test_filters = [];
        $_test_data_manager_meta = [];
    }

    protected function tearDown(): void
    {
        global $_test_users, $_test_user_meta, $_test_transients, $_test_filters, $_test_data_manager_meta;
        $_test_users = [];
        $_test_user_meta = [];
        $_test_transients = [];
        $_test_filters = [];
        $_test_data_manager_meta = [];

        parent::tearDown();
    }

    private function makeClaims(array $overrides = []): LtiClaims
    {
        $defaults = [
            'sub' => 'test-sub-123',
            'email' => 'jane.doe@example.com',
            'name' => 'Jane Doe',
            'givenName' => 'Jane',
            'familyName' => 'Doe',
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

    public function testNewUserCreatedWithPlatformScopedSub(): void
    {
        $claims = $this->makeClaims();
        $platformId = 42;

        $result = $this->provisioner->provision($claims, $platformId);

        $this->assertInstanceOf(WP_User::class, $result);
        $this->assertGreaterThan(0, $result->ID);

        // Verify scoped sub was stored
        global $_test_user_meta;
        $storedSub = $_test_user_meta[$result->ID]['_netdust_lti_sub'][0] ?? null;
        $this->assertSame('42:test-sub-123', $storedSub);
    }

    public function testExistingUserFoundByScopedSub(): void
    {
        global $_test_users, $_test_user_meta;

        // Pre-create a user with a scoped sub
        $user = new WP_User();
        $user->ID = 500;
        $user->user_login = 'existing.user';
        $user->user_email = 'existing@example.com';
        $user->roles = ['subscriber'];
        $_test_users[500] = $user;
        $_test_user_meta[500] = [
            '_netdust_lti_sub' => ['42:test-sub-123'],
        ];

        $claims = $this->makeClaims();
        $result = $this->provisioner->provision($claims, 42);

        $this->assertInstanceOf(WP_User::class, $result);
        $this->assertSame(500, $result->ID);
    }

    public function testExistingUserFoundByBareSub(): void
    {
        global $_test_users, $_test_user_meta;

        // Pre-create a user with a bare (unscoped) sub (legacy)
        $user = new WP_User();
        $user->ID = 501;
        $user->user_login = 'legacy.user';
        $user->user_email = 'legacy@example.com';
        $user->roles = ['subscriber'];
        $_test_users[501] = $user;
        $_test_user_meta[501] = [
            '_netdust_lti_sub' => ['test-sub-123'],
        ];

        $claims = $this->makeClaims();
        $result = $this->provisioner->provision($claims, 42);

        $this->assertInstanceOf(WP_User::class, $result);
        $this->assertSame(501, $result->ID);

        // Verify sub was upgraded to scoped format
        $storedSub = $_test_user_meta[501]['_netdust_lti_sub'][0] ?? null;
        $this->assertSame('42:test-sub-123', $storedSub);
    }

    public function testExistingUserFoundByEmail(): void
    {
        global $_test_users;

        // Pre-create a user with matching email but no LTI sub
        $user = new WP_User();
        $user->ID = 502;
        $user->user_login = 'email.user';
        $user->user_email = 'jane.doe@example.com';
        $user->roles = ['subscriber'];
        $_test_users[502] = $user;

        $claims = $this->makeClaims();
        $result = $this->provisioner->provision($claims, 42);

        $this->assertInstanceOf(WP_User::class, $result);
        $this->assertSame(502, $result->ID);

        // Verify scoped sub was stored
        global $_test_user_meta;
        $storedSub = $_test_user_meta[502]['_netdust_lti_sub'][0] ?? null;
        $this->assertSame('42:test-sub-123', $storedSub);
    }

    public function testDeterministicUsernameFromGivenFamily(): void
    {
        $claims = $this->makeClaims([
            'givenName' => 'Jan',
            'familyName' => 'Janssen',
        ]);

        $result = $this->provisioner->provision($claims, 1);

        $this->assertInstanceOf(WP_User::class, $result);
        $this->assertSame('jan.janssen', $result->user_login);
    }

    public function testUsernameFromEmailPrefix(): void
    {
        $claims = $this->makeClaims([
            'givenName' => null,
            'familyName' => null,
            'email' => 'testuser@school.edu',
        ]);

        $result = $this->provisioner->provision($claims, 1);

        $this->assertInstanceOf(WP_User::class, $result);
        $this->assertSame('testuser', $result->user_login);
    }

    public function testUsernameFromSubHash(): void
    {
        $claims = $this->makeClaims([
            'givenName' => null,
            'familyName' => null,
            'email' => null,
            'sub' => 'abc-def-ghi',
        ]);

        $result = $this->provisioner->provision($claims, 1);

        $this->assertInstanceOf(WP_User::class, $result);
        $expectedBase = 'lti_' . substr(md5('abc-def-ghi'), 0, 8);
        $this->assertSame($expectedBase, $result->user_login);
    }

    public function testFilterAppliedToUserData(): void
    {
        global $_test_filters;

        // Register a filter that modifies user data
        $_test_filters['netdust_lti_provision_user_data'] = [
            [
                'callback' => function (array $userData, LtiClaims $claims) {
                    $userData['display_name'] = 'Filtered Name';
                    return $userData;
                },
                'priority' => 10,
                'accepted_args' => 2,
            ],
        ];

        $claims = $this->makeClaims();
        $result = $this->provisioner->provision($claims, 1);

        $this->assertInstanceOf(WP_User::class, $result);
        $this->assertSame('Filtered Name', $result->display_name);
    }

    public function testInstructorRoleAssigned(): void
    {
        $claims = $this->makeClaims([
            'roles' => ['http://purl.imsglobal.org/vocab/lis/v2/membership#Instructor'],
        ]);

        $result = $this->provisioner->provision($claims, 1);

        $this->assertInstanceOf(WP_User::class, $result);
        $this->assertContains('instructor', $result->roles);
    }

    public function testLearnerRoleAssigned(): void
    {
        $claims = $this->makeClaims([
            'roles' => ['http://purl.imsglobal.org/vocab/lis/v2/membership#Learner'],
        ]);

        $result = $this->provisioner->provision($claims, 1);

        $this->assertInstanceOf(WP_User::class, $result);
        $this->assertContains('subscriber', $result->roles);
    }

    public function testPerPlatformRoleMapping(): void
    {
        global $_test_data_manager_meta;

        // Configure platform 99 with custom role mappings
        $_test_data_manager_meta['lti_platform'] = [
            99 => [
                'role_instructor' => 'teacher',
                'role_learner' => 'student',
            ],
        ];

        $claims = $this->makeClaims([
            'roles' => ['http://purl.imsglobal.org/vocab/lis/v2/membership#Instructor'],
        ]);

        $result = $this->provisioner->provision($claims, 99);

        $this->assertInstanceOf(WP_User::class, $result);
        $this->assertContains('teacher', $result->roles);
    }

    public function testPerPlatformLearnerRoleMapping(): void
    {
        global $_test_data_manager_meta;

        $_test_data_manager_meta['lti_platform'] = [
            99 => [
                'role_instructor' => 'teacher',
                'role_learner' => 'student',
            ],
        ];

        $claims = $this->makeClaims([
            'roles' => ['http://purl.imsglobal.org/vocab/lis/v2/membership#Learner'],
        ]);

        $result = $this->provisioner->provision($claims, 99);

        $this->assertInstanceOf(WP_User::class, $result);
        $this->assertContains('student', $result->roles);
    }

    public function testIsLtiUserReturnsTrueForProvisioned(): void
    {
        $claims = $this->makeClaims();
        $result = $this->provisioner->provision($claims, 1);

        $this->assertTrue($this->provisioner->isLtiUser($result->ID));
    }

    public function testIsLtiUserReturnsFalseForRegular(): void
    {
        $this->assertFalse($this->provisioner->isLtiUser(999999));
    }

    public function testLastLoginTimestampUpdated(): void
    {
        $claims = $this->makeClaims();
        $result = $this->provisioner->provision($claims, 1);

        global $_test_user_meta;
        $lastLogin = $_test_user_meta[$result->ID]['_netdust_lti_last_login'][0] ?? null;
        $this->assertNotNull($lastLogin);
    }
}
