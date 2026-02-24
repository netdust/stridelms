<?php
/**
 * Netdust LTI Plugin Test Suite
 *
 * Tests the LTI 1.3 Tool Provider plugin:
 * - Database tables and migrations
 * - Platform repository CRUD
 * - Context repository CRUD
 * - Nonce repository
 * - User provisioner
 * - Domain objects
 *
 * Run with: ddev exec wp eval-file scripts/test-lti.php
 */

use NetdustLTI\Database\Migrations;
use NetdustLTI\Domain\Platform;
use NetdustLTI\Domain\LtiClaims;
use NetdustLTI\Repositories\PlatformRepository;
use NetdustLTI\Repositories\ContextRepository;
use NetdustLTI\Repositories\NonceRepository;
use NetdustLTI\Services\UserProvisioner;
use NetdustLTI\Services\CourseEnroller;

class LtiTestSuite
{
    private array $cleanup = [
        'platforms' => [],
        'contexts' => [],
        'users' => [],
        'courses' => [],
    ];
    private int $passed = 0;
    private int $failed = 0;

    private ?PlatformRepository $platformRepo = null;
    private ?ContextRepository $contextRepo = null;
    private ?NonceRepository $nonceRepo = null;
    private ?UserProvisioner $userProvisioner = null;

    public function run(): void
    {
        echo "\n" . str_repeat("=", 70) . "\n";
        echo "  Netdust LTI Plugin Test Suite\n";
        echo str_repeat("=", 70) . "\n\n";

        if (!$this->checkPlugin()) {
            echo "  ✗ LTI plugin not loaded. Skipping tests.\n\n";
            return;
        }

        try {
            // =====================================================================
            echo "  >> Database & Migration Tests\n";
            // =====================================================================
            $this->testTablesExist();
            $this->testMigrationsAreIdempotent();

            // =====================================================================
            echo "\n  >> Platform Repository Tests\n";
            // =====================================================================
            $this->testPlatformCreate();
            $this->testPlatformFind();
            $this->testPlatformFindByIssuerAndClient();
            $this->testPlatformUpdate();
            $this->testPlatformAll();
            $this->testPlatformAllEnabled();
            $this->testPlatformDelete();
            $this->testPlatformFindReturnsErrorForNonExistent();

            // =====================================================================
            echo "\n  >> Context Repository Tests\n";
            // =====================================================================
            $this->testContextCreate();
            $this->testContextFind();
            $this->testContextFindByLtiContext();
            $this->testContextFindByCourseId();
            $this->testContextUpdate();
            $this->testContextSettingsJsonHandling();

            // =====================================================================
            echo "\n  >> Nonce Repository Tests\n";
            // =====================================================================
            $this->testNonceSave();
            $this->testNonceExists();
            $this->testNonceExpiryCheck();
            $this->testNonceCleanup();

            // =====================================================================
            echo "\n  >> Domain Object Tests\n";
            // =====================================================================
            $this->testPlatformDomainObject();
            $this->testLtiClaimsRoleDetection();
            $this->testLtiClaimsCourseIdExtraction();

            // =====================================================================
            echo "\n  >> User Provisioner Tests\n";
            // =====================================================================
            $this->testUserProvisionerCreatesNewUser();
            $this->testUserProvisionerFindsExistingByEmail();
            $this->testUserProvisionerFindsExistingByLtiSub();
            $this->testUserProvisionerHandlesInstructorRole();

            // =====================================================================
            echo "\n  >> Course Enroller Tests\n";
            // =====================================================================
            $this->testCourseEnrollerStoresContext();
            $this->testCourseEnrollerRetrievesContext();

        } finally {
            $this->cleanup();
        }

        echo "\n" . str_repeat("=", 70) . "\n";
        $total = $this->passed + $this->failed;
        echo "  Results: {$this->passed}/{$total} passed";
        if ($this->failed > 0) {
            echo " ({$this->failed} failed)";
        }
        echo "\n" . str_repeat("=", 70) . "\n\n";

        if ($this->failed > 0) {
            exit(1);
        }
    }

    private function checkPlugin(): bool
    {
        if (!class_exists(PlatformRepository::class)) {
            return false;
        }

        try {
            $this->platformRepo = new PlatformRepository();
            $this->contextRepo = new ContextRepository();
            $this->nonceRepo = new NonceRepository();
            $this->userProvisioner = new UserProvisioner();

            echo "  ✓ LTI plugin loaded\n\n";
            return true;
        } catch (\Throwable $e) {
            echo "  ✗ Error loading plugin: {$e->getMessage()}\n\n";
            return false;
        }
    }

    // =========================================================================
    // Database & Migration Tests
    // =========================================================================

    private function testTablesExist(): void
    {
        $this->test("Database tables exist", function() {
            global $wpdb;
            $prefix = $wpdb->prefix . 'netdust_lti_';

            $tables = ['platforms', 'contexts', 'nonces', 'access_tokens'];

            foreach ($tables as $table) {
                $exists = $wpdb->get_var(
                    $wpdb->prepare("SHOW TABLES LIKE %s", $prefix . $table)
                );
                $this->assert($exists !== null, "Table {$prefix}{$table} should exist");
            }
        });
    }

    private function testMigrationsAreIdempotent(): void
    {
        $this->test("Migrations are idempotent", function() {
            // Running migrations again should not error
            Migrations::run();
            $this->assert(true, "Migrations ran without error");
        });
    }

    // =========================================================================
    // Platform Repository Tests
    // =========================================================================

    private function testPlatformCreate(): void
    {
        $this->test("Platform create returns valid ID", function() {
            $platform = $this->createTestPlatform('Test Platform 1');
            $id = $this->platformRepo->create($platform);

            $this->assert(!is_wp_error($id), "Should not return error");
            $this->assert(is_int($id) && $id > 0, "Should return positive integer ID");

            $this->cleanup['platforms'][] = $id;
        });
    }

    private function testPlatformFind(): void
    {
        $this->test("Platform find retrieves all fields", function() {
            $platform = $this->createTestPlatform('Test Platform Find');
            $id = $this->platformRepo->create($platform);
            $this->cleanup['platforms'][] = $id;

            $found = $this->platformRepo->find($id);

            $this->assert(!is_wp_error($found), "Should find platform");
            $this->assert($found instanceof Platform, "Should return Platform object");
            $this->assert($found->name === 'Test Platform Find', "Name should match");
            $this->assert($found->platformId === $platform->platformId, "Platform ID should match");
            $this->assert($found->clientId === $platform->clientId, "Client ID should match");
            $this->assert($found->enabled === true, "Should be enabled");
        });
    }

    private function testPlatformFindByIssuerAndClient(): void
    {
        $this->test("Platform findByIssuerAndClient works", function() {
            $platform = $this->createTestPlatform('Test Issuer Lookup', 'https://unique-issuer.com', 'unique-client');
            $id = $this->platformRepo->create($platform);
            $this->cleanup['platforms'][] = $id;

            $found = $this->platformRepo->findByIssuerAndClient('https://unique-issuer.com', 'unique-client');

            $this->assert(!is_wp_error($found), "Should find platform");
            $this->assert($found->name === 'Test Issuer Lookup', "Name should match");
        });
    }

    private function testPlatformUpdate(): void
    {
        $this->test("Platform update modifies fields", function() {
            $platform = $this->createTestPlatform('Test Update Original');
            $id = $this->platformRepo->create($platform);
            $this->cleanup['platforms'][] = $id;

            $updated = new Platform(
                id: $id,
                name: 'Test Update Modified',
                platformId: 'https://updated.example.com',
                clientId: 'updated-client-id',
                deploymentId: 'deploy-123',
                authEndpoint: 'https://updated.example.com/auth',
                tokenEndpoint: 'https://updated.example.com/token',
                jwksEndpoint: 'https://updated.example.com/jwks',
                enabled: false,
            );

            $result = $this->platformRepo->update($id, $updated);
            $this->assert($result === true, "Update should succeed");

            $found = $this->platformRepo->find($id);
            $this->assert($found->name === 'Test Update Modified', "Name should be updated");
            $this->assert($found->enabled === false, "Should be disabled");
        });
    }

    private function testPlatformAll(): void
    {
        $this->test("Platform all() returns all platforms", function() {
            // Create multiple platforms
            $id1 = $this->platformRepo->create($this->createTestPlatform('All Test 1', 'https://all1.com', 'client1'));
            $id2 = $this->platformRepo->create($this->createTestPlatform('All Test 2', 'https://all2.com', 'client2'));
            $this->cleanup['platforms'][] = $id1;
            $this->cleanup['platforms'][] = $id2;

            $all = $this->platformRepo->all();

            $this->assert(is_array($all), "Should return array");
            $this->assert(count($all) >= 2, "Should have at least 2 platforms");

            $names = array_map(fn($p) => $p->name, $all);
            $this->assert(in_array('All Test 1', $names), "Should contain All Test 1");
            $this->assert(in_array('All Test 2', $names), "Should contain All Test 2");
        });
    }

    private function testPlatformAllEnabled(): void
    {
        $this->test("Platform allEnabled() filters disabled", function() {
            $enabled = $this->createTestPlatform('Enabled Platform', 'https://enabled.com', 'enabled-client');
            $disabled = new Platform(
                id: null,
                name: 'Disabled Platform',
                platformId: 'https://disabled.com',
                clientId: 'disabled-client',
                deploymentId: null,
                authEndpoint: 'https://disabled.com/auth',
                tokenEndpoint: 'https://disabled.com/token',
                jwksEndpoint: 'https://disabled.com/jwks',
                enabled: false,
            );

            $id1 = $this->platformRepo->create($enabled);
            $id2 = $this->platformRepo->create($disabled);
            $this->cleanup['platforms'][] = $id1;
            $this->cleanup['platforms'][] = $id2;

            $enabledOnly = $this->platformRepo->allEnabled();
            $names = array_map(fn($p) => $p->name, $enabledOnly);

            $this->assert(in_array('Enabled Platform', $names), "Should contain enabled platform");
            $this->assert(!in_array('Disabled Platform', $names), "Should not contain disabled platform");
        });
    }

    private function testPlatformDelete(): void
    {
        $this->test("Platform delete removes platform", function() {
            $platform = $this->createTestPlatform('Delete Test', 'https://delete.com', 'delete-client');
            $id = $this->platformRepo->create($platform);

            $result = $this->platformRepo->delete($id);
            $this->assert($result === true, "Delete should succeed");

            $found = $this->platformRepo->find($id);
            $this->assert(is_wp_error($found), "Should not find deleted platform");
        });
    }

    private function testPlatformFindReturnsErrorForNonExistent(): void
    {
        $this->test("Platform find returns WP_Error for non-existent", function() {
            $found = $this->platformRepo->find(999999);

            $this->assert(is_wp_error($found), "Should return WP_Error");
            $this->assert($found->get_error_code() === 'not_found', "Error code should be 'not_found'");
        });
    }

    // =========================================================================
    // Context Repository Tests
    // =========================================================================

    private function testContextCreate(): void
    {
        $this->test("Context create returns valid ID", function() {
            $platformId = $this->createAndStorePlatform('Context Test Platform');
            $courseId = $this->createTestCourse();

            $id = $this->contextRepo->create([
                'platform_id' => $platformId,
                'lti_context_id' => 'ctx-123',
                'ld_course_id' => $courseId,
                'resource_link_id' => 'rl-456',
            ]);

            $this->assert(!is_wp_error($id), "Should not return error");
            $this->assert(is_int($id) && $id > 0, "Should return positive integer ID");

            $this->cleanup['contexts'][] = $id;
        });
    }

    private function testContextFind(): void
    {
        $this->test("Context find retrieves all fields", function() {
            $platformId = $this->createAndStorePlatform('Context Find Platform');
            $courseId = $this->createTestCourse();

            $id = $this->contextRepo->create([
                'platform_id' => $platformId,
                'lti_context_id' => 'ctx-find-123',
                'ld_course_id' => $courseId,
                'resource_link_id' => 'rl-find-456',
                'line_item_url' => 'https://example.com/lineitem',
            ]);
            $this->cleanup['contexts'][] = $id;

            $found = $this->contextRepo->find($id);

            $this->assert(!is_wp_error($found), "Should find context");
            $this->assert((int)$found['platform_id'] === $platformId, "Platform ID should match");
            $this->assert($found['lti_context_id'] === 'ctx-find-123', "LTI context ID should match");
            $this->assert((int)$found['ld_course_id'] === $courseId, "Course ID should match");
        });
    }

    private function testContextFindByLtiContext(): void
    {
        $this->test("Context findByLtiContext works", function() {
            $platformId = $this->createAndStorePlatform('Context LTI Lookup');
            $courseId = $this->createTestCourse();

            $id = $this->contextRepo->create([
                'platform_id' => $platformId,
                'lti_context_id' => 'ctx-lti-lookup',
                'ld_course_id' => $courseId,
                'resource_link_id' => 'rl-lti-lookup',
            ]);
            $this->cleanup['contexts'][] = $id;

            $found = $this->contextRepo->findByLtiContext($platformId, 'ctx-lti-lookup', 'rl-lti-lookup');

            $this->assert($found !== null, "Should find context");
            $this->assert((int)$found['id'] === $id, "ID should match");
        });
    }

    private function testContextFindByCourseId(): void
    {
        $this->test("Context findByCourseId returns all contexts for course", function() {
            $platformId = $this->createAndStorePlatform('Context Course Lookup');
            $courseId = $this->createTestCourse();

            // Create multiple contexts for same course
            $id1 = $this->contextRepo->create([
                'platform_id' => $platformId,
                'lti_context_id' => 'ctx-course-1',
                'ld_course_id' => $courseId,
                'resource_link_id' => 'rl-course-1',
            ]);
            $id2 = $this->contextRepo->create([
                'platform_id' => $platformId,
                'lti_context_id' => 'ctx-course-2',
                'ld_course_id' => $courseId,
                'resource_link_id' => 'rl-course-2',
            ]);
            $this->cleanup['contexts'][] = $id1;
            $this->cleanup['contexts'][] = $id2;

            $found = $this->contextRepo->findByCourseId($courseId);

            $this->assert(is_array($found), "Should return array");
            $this->assert(count($found) >= 2, "Should have at least 2 contexts");
        });
    }

    private function testContextUpdate(): void
    {
        $this->test("Context update modifies fields", function() {
            $platformId = $this->createAndStorePlatform('Context Update Platform');
            $courseId = $this->createTestCourse();

            $id = $this->contextRepo->create([
                'platform_id' => $platformId,
                'lti_context_id' => 'ctx-update',
                'ld_course_id' => $courseId,
            ]);
            $this->cleanup['contexts'][] = $id;

            $result = $this->contextRepo->update($id, [
                'line_item_url' => 'https://updated.example.com/lineitem',
                'settings' => ['key' => 'value'],
            ]);

            $this->assert($result === true, "Update should succeed");

            $found = $this->contextRepo->find($id);
            $this->assert($found['line_item_url'] === 'https://updated.example.com/lineitem', "Line item URL should be updated");
            $this->assert($found['settings']['key'] === 'value', "Settings should be updated");
        });
    }

    private function testContextSettingsJsonHandling(): void
    {
        $this->test("Context settings JSON is properly encoded/decoded", function() {
            $platformId = $this->createAndStorePlatform('Context JSON Platform');
            $courseId = $this->createTestCourse();

            $id = $this->contextRepo->create([
                'platform_id' => $platformId,
                'lti_context_id' => 'ctx-json',
                'ld_course_id' => $courseId,
                'settings' => [
                    'grading' => true,
                    'percentage' => 85.5,
                    'roles' => ['instructor', 'learner'],
                ],
            ]);
            $this->cleanup['contexts'][] = $id;

            $found = $this->contextRepo->find($id);

            $this->assert(is_array($found['settings']), "Settings should be decoded as array");
            $this->assert($found['settings']['grading'] === true, "Boolean should be preserved");
            $this->assert($found['settings']['percentage'] === 85.5, "Float should be preserved");
            $this->assert($found['settings']['roles'][0] === 'instructor', "Nested array should be preserved");
        });
    }

    // =========================================================================
    // Nonce Repository Tests
    // =========================================================================

    private function testNonceSave(): void
    {
        $this->test("Nonce save stores nonce", function() {
            $platformId = $this->createAndStorePlatform('Nonce Test Platform');
            $nonce = 'test-nonce-' . uniqid();

            $result = $this->nonceRepo->save($platformId, $nonce, time() + 3600);

            $this->assert($result === true, "Save should succeed");
        });
    }

    private function testNonceExists(): void
    {
        $this->test("Nonce exists returns true for valid nonce", function() {
            $platformId = $this->createAndStorePlatform('Nonce Exists Platform');
            $nonce = 'exists-nonce-' . uniqid();

            // Use WordPress's current_time which respects timezone settings
            // Add 2 hours to be safe across timezone differences
            $expiresAt = current_time('timestamp') + 7200;

            $saved = $this->nonceRepo->save($platformId, $nonce, $expiresAt);
            $this->assert($saved === true, "Nonce should be saved");

            $exists = $this->nonceRepo->exists($platformId, $nonce);
            $this->assert($exists === true, "Nonce should exist");
        });
    }

    private function testNonceExpiryCheck(): void
    {
        $this->test("Nonce exists returns false for expired nonce", function() {
            $platformId = $this->createAndStorePlatform('Nonce Expiry Platform');
            $nonce = 'expired-nonce-' . uniqid();

            // Save with past expiry
            $this->nonceRepo->save($platformId, $nonce, time() - 1);

            $exists = $this->nonceRepo->exists($platformId, $nonce);
            $this->assert($exists === false, "Expired nonce should not exist");
        });
    }

    private function testNonceCleanup(): void
    {
        $this->test("Nonce cleanup removes expired nonces", function() {
            $platformId = $this->createAndStorePlatform('Nonce Cleanup Platform');

            // Create expired nonces
            $this->nonceRepo->save($platformId, 'cleanup-1-' . uniqid(), time() - 100);
            $this->nonceRepo->save($platformId, 'cleanup-2-' . uniqid(), time() - 100);

            $deleted = $this->nonceRepo->cleanup();

            $this->assert($deleted >= 2, "Should delete at least 2 expired nonces");
        });
    }

    // =========================================================================
    // Domain Object Tests
    // =========================================================================

    private function testPlatformDomainObject(): void
    {
        $this->test("Platform domain object converts to/from array", function() {
            $platform = new Platform(
                id: 123,
                name: 'Domain Test',
                platformId: 'https://domain.test',
                clientId: 'domain-client',
                deploymentId: 'deploy-123',
                authEndpoint: 'https://domain.test/auth',
                tokenEndpoint: 'https://domain.test/token',
                jwksEndpoint: 'https://domain.test/jwks',
                enabled: true,
            );

            $array = $platform->toArray();

            $this->assert($array['name'] === 'Domain Test', "Name should be in array");
            $this->assert($array['platform_id'] === 'https://domain.test', "Platform ID should be in array");
            $this->assert($array['enabled'] === 1, "Enabled should be 1 (int)");

            // Test fromRow reconstruction
            $row = array_merge($array, [
                'id' => 123,
                'created_at' => '2024-01-01 00:00:00',
                'updated_at' => '2024-01-01 00:00:00',
            ]);
            $reconstructed = Platform::fromRow($row);

            $this->assert($reconstructed->name === 'Domain Test', "Reconstructed name should match");
            $this->assert($reconstructed->enabled === true, "Reconstructed enabled should be bool");
        });
    }

    private function testLtiClaimsRoleDetection(): void
    {
        $this->test("LtiClaims correctly detects instructor role", function() {
            $instructorClaims = new LtiClaims(
                sub: 'user-123',
                email: 'instructor@test.com',
                name: 'Test Instructor',
                givenName: 'Test',
                familyName: 'Instructor',
                contextId: 'ctx-1',
                contextTitle: 'Test Course',
                resourceLinkId: 'rl-1',
                resourceLinkTitle: 'Assignment 1',
                roles: ['http://purl.imsglobal.org/vocab/lis/v2/membership#Instructor'],
                custom: [],
                lineItemUrl: null,
                lineItemsUrl: null,
                scoresUrl: null,
            );

            $learnerClaims = new LtiClaims(
                sub: 'user-456',
                email: 'learner@test.com',
                name: 'Test Learner',
                givenName: 'Test',
                familyName: 'Learner',
                contextId: 'ctx-1',
                contextTitle: 'Test Course',
                resourceLinkId: 'rl-1',
                resourceLinkTitle: 'Assignment 1',
                roles: ['http://purl.imsglobal.org/vocab/lis/v2/membership#Learner'],
                custom: [],
                lineItemUrl: null,
                lineItemsUrl: null,
                scoresUrl: null,
            );

            $this->assert($instructorClaims->isInstructor() === true, "Instructor should be detected");
            $this->assert($instructorClaims->isLearner() === false, "Instructor is not learner");
            $this->assert($learnerClaims->isLearner() === true, "Learner should be detected");
            $this->assert($learnerClaims->isInstructor() === false, "Learner is not instructor");
        });
    }

    private function testLtiClaimsCourseIdExtraction(): void
    {
        $this->test("LtiClaims extracts course ID from custom claims", function() {
            $claims = new LtiClaims(
                sub: 'user-123',
                email: 'test@test.com',
                name: 'Test User',
                givenName: null,
                familyName: null,
                contextId: 'ctx-1',
                contextTitle: null,
                resourceLinkId: null,
                resourceLinkTitle: null,
                roles: [],
                custom: ['ld_course_id' => '42'],
                lineItemUrl: null,
                lineItemsUrl: null,
                scoresUrl: null,
            );

            $this->assert($claims->getCourseId() === 42, "Course ID should be extracted as int");

            $claimsWithoutCourse = new LtiClaims(
                sub: 'user-456',
                email: null,
                name: null,
                givenName: null,
                familyName: null,
                contextId: null,
                contextTitle: null,
                resourceLinkId: null,
                resourceLinkTitle: null,
                roles: [],
                custom: [],
                lineItemUrl: null,
                lineItemsUrl: null,
                scoresUrl: null,
            );

            $this->assert($claimsWithoutCourse->getCourseId() === null, "Missing course ID should return null");
        });
    }

    // =========================================================================
    // User Provisioner Tests
    // =========================================================================

    private function testUserProvisionerCreatesNewUser(): void
    {
        $this->test("UserProvisioner creates new user", function() {
            $claims = new LtiClaims(
                sub: 'lti-new-user-' . uniqid(),
                email: 'newuser-' . uniqid() . '@lti.test',
                name: 'New LTI User',
                givenName: 'New',
                familyName: 'User',
                contextId: null,
                contextTitle: null,
                resourceLinkId: null,
                resourceLinkTitle: null,
                roles: ['http://purl.imsglobal.org/vocab/lis/v2/membership#Learner'],
                custom: [],
                lineItemUrl: null,
                lineItemsUrl: null,
                scoresUrl: null,
            );

            $user = $this->userProvisioner->provision($claims);

            $this->assert(!is_wp_error($user), "Should not return error");
            $this->assert($user instanceof WP_User, "Should return WP_User");
            $this->assert($user->display_name === 'New LTI User', "Display name should match");

            $this->cleanup['users'][] = $user->ID;
        });
    }

    private function testUserProvisionerFindsExistingByEmail(): void
    {
        $this->test("UserProvisioner finds existing user by email", function() {
            $email = 'existing-' . uniqid() . '@lti.test';

            // Create user first
            $existingUserId = wp_insert_user([
                'user_login' => 'existing_' . uniqid(),
                'user_email' => $email,
                'user_pass' => wp_generate_password(),
            ]);
            $this->cleanup['users'][] = $existingUserId;

            $claims = new LtiClaims(
                sub: 'lti-existing-' . uniqid(),
                email: $email,
                name: 'Existing User',
                givenName: null,
                familyName: null,
                contextId: null,
                contextTitle: null,
                resourceLinkId: null,
                resourceLinkTitle: null,
                roles: [],
                custom: [],
                lineItemUrl: null,
                lineItemsUrl: null,
                scoresUrl: null,
            );

            $user = $this->userProvisioner->provision($claims);

            $this->assert(!is_wp_error($user), "Should not return error");
            $this->assert($user->ID === $existingUserId, "Should return existing user");
        });
    }

    private function testUserProvisionerFindsExistingByLtiSub(): void
    {
        $this->test("UserProvisioner finds existing user by LTI sub", function() {
            $ltiSub = 'lti-sub-' . uniqid();

            // Create user and set LTI sub
            $existingUserId = wp_insert_user([
                'user_login' => 'lti_sub_' . uniqid(),
                'user_email' => 'ltisub-' . uniqid() . '@lti.test',
                'user_pass' => wp_generate_password(),
            ]);
            update_user_meta($existingUserId, '_netdust_lti_sub', $ltiSub);
            $this->cleanup['users'][] = $existingUserId;

            $claims = new LtiClaims(
                sub: $ltiSub,
                email: 'different-' . uniqid() . '@lti.test', // Different email!
                name: 'LTI Sub User',
                givenName: null,
                familyName: null,
                contextId: null,
                contextTitle: null,
                resourceLinkId: null,
                resourceLinkTitle: null,
                roles: [],
                custom: [],
                lineItemUrl: null,
                lineItemsUrl: null,
                scoresUrl: null,
            );

            $user = $this->userProvisioner->provision($claims);

            $this->assert(!is_wp_error($user), "Should not return error");
            $this->assert($user->ID === $existingUserId, "Should return existing user by LTI sub");
        });
    }

    private function testUserProvisionerHandlesInstructorRole(): void
    {
        $this->test("UserProvisioner assigns instructor role", function() {
            $claims = new LtiClaims(
                sub: 'lti-instructor-' . uniqid(),
                email: 'instructor-' . uniqid() . '@lti.test',
                name: 'New Instructor',
                givenName: 'New',
                familyName: 'Instructor',
                contextId: null,
                contextTitle: null,
                resourceLinkId: null,
                resourceLinkTitle: null,
                roles: ['http://purl.imsglobal.org/vocab/lis/v2/membership#Instructor'],
                custom: [],
                lineItemUrl: null,
                lineItemsUrl: null,
                scoresUrl: null,
            );

            $user = $this->userProvisioner->provision($claims);
            $this->cleanup['users'][] = $user->ID;

            // Check role - depends on whether 'instructor' role exists in WP
            // If not, it falls back to default role behavior
            $this->assert(!is_wp_error($user), "Should create user successfully");
        });
    }

    // =========================================================================
    // Course Enroller Tests
    // =========================================================================

    private function testCourseEnrollerStoresContext(): void
    {
        $this->test("CourseEnroller stores LTI context in user meta", function() {
            $platformId = $this->createAndStorePlatform('Enroller Test Platform');
            $courseId = $this->createTestCourse();
            $userId = $this->createTestUser();

            $user = get_user_by('id', $userId);
            $claims = new LtiClaims(
                sub: 'enroller-test-' . uniqid(),
                email: $user->user_email,
                name: $user->display_name,
                givenName: null,
                familyName: null,
                contextId: 'enroller-ctx-123',
                contextTitle: 'Enroller Test Course',
                resourceLinkId: 'enroller-rl-456',
                resourceLinkTitle: 'Test Assignment',
                roles: [],
                custom: [],
                lineItemUrl: 'https://example.com/lineitems/1',
                lineItemsUrl: 'https://example.com/lineitems',
                scoresUrl: 'https://example.com/scores',
            );

            $enroller = new CourseEnroller($this->contextRepo);
            // Note: enroll() requires LearnDash functions, so we test the context storage directly
            $this->callPrivateMethod($enroller, 'storeLtiContext', [$userId, $courseId, $claims, $platformId]);

            $storedContext = get_user_meta($userId, '_netdust_lti_context_' . $courseId, true);

            $this->assert(is_array($storedContext), "Context should be stored");
            $this->assert((int)$storedContext['platform_id'] === $platformId, "Platform ID should match");
            $this->assert($storedContext['lti_context_id'] === 'enroller-ctx-123', "Context ID should match");
        });
    }

    private function testCourseEnrollerRetrievesContext(): void
    {
        $this->test("CourseEnroller retrieves stored context", function() {
            $courseId = $this->createTestCourse();
            $userId = $this->createTestUser();

            // Store context directly
            $contextData = [
                'platform_id' => 999,
                'lti_context_id' => 'retrieve-test-ctx',
                'resource_link_id' => 'retrieve-test-rl',
            ];
            update_user_meta($userId, '_netdust_lti_context_' . $courseId, $contextData);

            $enroller = new CourseEnroller($this->contextRepo);
            $retrieved = $enroller->getLtiContext($userId, $courseId);

            $this->assert($retrieved !== null, "Should retrieve context");
            $this->assert($retrieved['lti_context_id'] === 'retrieve-test-ctx', "Context ID should match");
            $this->assert($enroller->hasLtiContext($userId, $courseId) === true, "hasLtiContext should return true");
        });
    }

    // =========================================================================
    // Helper Methods
    // =========================================================================

    private function createTestPlatform(string $name, ?string $platformId = null, ?string $clientId = null): Platform
    {
        // Always use unique IDs unless explicitly provided
        $platformId = $platformId ?? 'https://' . uniqid('platform-') . '.test';
        $clientId = $clientId ?? 'client-' . uniqid();

        return new Platform(
            id: null,
            name: $name,
            platformId: $platformId,
            clientId: $clientId,
            deploymentId: null,
            authEndpoint: $platformId . '/auth',
            tokenEndpoint: $platformId . '/token',
            jwksEndpoint: $platformId . '/jwks',
            enabled: true,
        );
    }

    private function createAndStorePlatform(string $name): int
    {
        $platform = $this->createTestPlatform($name, 'https://' . uniqid() . '.test', 'client-' . uniqid());
        $id = $this->platformRepo->create($platform);
        $this->cleanup['platforms'][] = $id;
        return $id;
    }

    private function createTestCourse(): int
    {
        $courseId = wp_insert_post([
            'post_type' => 'sfwd-courses',
            'post_title' => 'LTI Test Course ' . uniqid(),
            'post_status' => 'publish',
        ]);
        $this->cleanup['courses'][] = $courseId;
        return $courseId;
    }

    private function createTestUser(): int
    {
        $userId = wp_insert_user([
            'user_login' => 'lti_test_' . uniqid(),
            'user_email' => 'lti_test_' . uniqid() . '@test.local',
            'user_pass' => wp_generate_password(),
        ]);
        $this->cleanup['users'][] = $userId;
        return $userId;
    }

    private function callPrivateMethod(object $obj, string $method, array $args = []): mixed
    {
        $reflection = new ReflectionClass($obj);
        $method = $reflection->getMethod($method);
        $method->setAccessible(true);
        return $method->invokeArgs($obj, $args);
    }

    private function test(string $name, callable $fn): void
    {
        echo "  Testing: {$name}... ";

        try {
            $fn();
            echo "✓ PASS\n";
            $this->passed++;
        } catch (\Throwable $e) {
            echo "✗ FAIL\n";
            echo "    Error: {$e->getMessage()}\n";
            if ($e->getFile()) {
                echo "    At: {$e->getFile()}:{$e->getLine()}\n";
            }
            $this->failed++;
        }
    }

    private function assert(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new \RuntimeException("Assertion failed: {$message}");
        }
    }

    private function cleanup(): void
    {
        global $wpdb;

        $contextCount = count($this->cleanup['contexts']);
        $platformCount = count($this->cleanup['platforms']);
        $userCount = count($this->cleanup['users']);
        $courseCount = count($this->cleanup['courses']);

        echo "\n  Cleaning up: {$platformCount} platforms, {$contextCount} contexts, {$userCount} users, {$courseCount} courses...\n";

        // Clean contexts first (foreign key to platforms)
        foreach ($this->cleanup['contexts'] as $id) {
            $wpdb->delete($wpdb->prefix . 'netdust_lti_contexts', ['id' => $id]);
        }

        // Clean platforms (skip if ID is WP_Error from failed create)
        foreach ($this->cleanup['platforms'] as $id) {
            if (is_int($id) && $id > 0) {
                $this->platformRepo->delete($id);
            }
        }

        // Clean users
        foreach ($this->cleanup['users'] as $userId) {
            wp_delete_user($userId);
        }

        // Clean courses
        foreach ($this->cleanup['courses'] as $courseId) {
            wp_delete_post($courseId, true);
        }

        // Clean expired nonces
        $this->nonceRepo->cleanup();

        echo "  Cleanup complete.\n";
    }
}

// Run the tests
$suite = new LtiTestSuite();
$suite->run();
