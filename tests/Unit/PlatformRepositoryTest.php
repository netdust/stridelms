<?php
declare(strict_types=1);

namespace NetdustLTI\Tests\Unit;

use NetdustLTI\ToolProvider\PlatformRepository;
use Stride\Tests\TestCase;
use WP_Error;
use WP_Post;

/**
 * Unit tests for PlatformRepository
 *
 * Tests the Data Manager-based PlatformRepository implementation
 * for CRUD operations on LTI platform CPT records.
 */
class PlatformRepositoryTest extends TestCase
{
    private PlatformRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new PlatformRepository();
    }

    // =========================================================================
    // find() tests
    // =========================================================================

    public function test_find_returns_wp_post_when_found(): void
    {
        // Arrange: create a platform post
        $platform = $this->createPlatform([
            'ID' => 100,
            'post_title' => 'Canvas LMS',
        ]);
        $this->setPlatformMeta($platform->ID, [
            'platform_id' => 'https://canvas.instructure.com',
            'client_id' => 'client-123',
            'deployment_id' => 'deploy-1',
            'auth_endpoint' => 'https://canvas.instructure.com/api/lti/authorize',
            'token_endpoint' => 'https://canvas.instructure.com/login/oauth2/token',
            'jwks_endpoint' => 'https://canvas.instructure.com/api/lti/security/jwks',
            'enabled' => true,
        ]);

        // Act
        $result = $this->repo->find(100);

        // Assert
        $this->assertInstanceOf(WP_Post::class, $result);
        $this->assertEquals(100, $result->ID);
        $this->assertEquals('Canvas LMS', $result->post_title);
    }

    public function test_find_returns_wp_error_when_not_found(): void
    {
        // Act
        $result = $this->repo->find(99999);

        // Assert
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertEquals('not_found', $result->get_error_code());
    }

    public function test_find_returns_wp_error_when_wrong_post_type(): void
    {
        // Arrange: create a post of wrong type
        global $_test_posts;
        $_test_posts[500] = (object) [
            'ID' => 500,
            'post_type' => 'post', // Not lti_platform
            'post_title' => 'Regular Post',
            'post_status' => 'publish',
        ];

        // Act
        $result = $this->repo->find(500);

        // Assert
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertEquals('not_found', $result->get_error_code());
    }

    // =========================================================================
    // findByIssuerAndClient() tests
    // =========================================================================

    public function test_find_by_issuer_and_client_returns_wp_post_when_found(): void
    {
        // Arrange
        $platform = $this->createPlatform([
            'ID' => 101,
            'post_title' => 'Moodle LMS',
        ]);
        $this->setPlatformMeta($platform->ID, [
            'platform_id' => 'https://moodle.example.com',
            'client_id' => 'moodle-client-456',
            'deployment_id' => 'deploy-2',
            'enabled' => true,
        ]);

        // Act
        $result = $this->repo->findByIssuerAndClient(
            'https://moodle.example.com',
            'moodle-client-456'
        );

        // Assert
        $this->assertInstanceOf(WP_Post::class, $result);
        $this->assertEquals(101, $result->ID);
    }

    public function test_find_by_issuer_and_client_returns_wp_error_when_not_found(): void
    {
        // Act
        $result = $this->repo->findByIssuerAndClient(
            'https://nonexistent.example.com',
            'fake-client-id'
        );

        // Assert
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertEquals('not_found', $result->get_error_code());
    }

    public function test_find_by_issuer_and_client_requires_both_fields_to_match(): void
    {
        // Arrange
        $platform = $this->createPlatform([
            'ID' => 102,
            'post_title' => 'Test Platform',
        ]);
        $this->setPlatformMeta($platform->ID, [
            'platform_id' => 'https://test.example.com',
            'client_id' => 'test-client',
            'enabled' => true,
        ]);

        // Act - correct platform_id but wrong client_id
        $result = $this->repo->findByIssuerAndClient(
            'https://test.example.com',
            'wrong-client'
        );

        // Assert
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertEquals('not_found', $result->get_error_code());
    }

    // =========================================================================
    // all() tests
    // =========================================================================

    public function test_all_returns_array_of_platforms(): void
    {
        // Arrange
        $platform1 = $this->createPlatform(['ID' => 201, 'post_title' => 'Platform A']);
        $platform2 = $this->createPlatform(['ID' => 202, 'post_title' => 'Platform B']);
        $this->setPlatformMeta($platform1->ID, ['enabled' => true]);
        $this->setPlatformMeta($platform2->ID, ['enabled' => false]);

        // Act
        $result = $this->repo->all();

        // Assert
        $this->assertIsArray($result);
        $this->assertCount(2, $result);
    }

    public function test_all_returns_empty_array_when_no_platforms(): void
    {
        // Act
        $result = $this->repo->all();

        // Assert
        $this->assertIsArray($result);
        $this->assertCount(0, $result);
    }

    // =========================================================================
    // allEnabled() tests
    // =========================================================================

    public function test_all_enabled_returns_only_enabled_platforms(): void
    {
        // Arrange
        $platform1 = $this->createPlatform(['ID' => 301, 'post_title' => 'Enabled Platform']);
        $platform2 = $this->createPlatform(['ID' => 302, 'post_title' => 'Disabled Platform']);
        $this->setPlatformMeta($platform1->ID, ['enabled' => true]);
        $this->setPlatformMeta($platform2->ID, ['enabled' => false]);

        // Act
        $result = $this->repo->allEnabled();

        // Assert
        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        $this->assertEquals(301, $result[0]['ID']);
    }

    // =========================================================================
    // create() tests
    // =========================================================================

    public function test_create_returns_post_id_on_success(): void
    {
        // Act
        $result = $this->repo->create([
            'name' => 'New Platform',
            'platform_id' => 'https://new.example.com',
            'client_id' => 'new-client',
            'deployment_id' => 'deploy-new',
            'auth_endpoint' => 'https://new.example.com/auth',
            'token_endpoint' => 'https://new.example.com/token',
            'jwks_endpoint' => 'https://new.example.com/jwks',
            'enabled' => true,
        ]);

        // Assert
        $this->assertIsInt($result);
        $this->assertGreaterThan(0, $result);
    }

    public function test_create_stores_all_fields(): void
    {
        // Arrange
        $data = [
            'name' => 'Full Platform',
            'platform_id' => 'https://full.example.com',
            'client_id' => 'full-client',
            'deployment_id' => 'full-deploy',
            'auth_endpoint' => 'https://full.example.com/auth',
            'token_endpoint' => 'https://full.example.com/token',
            'jwks_endpoint' => 'https://full.example.com/jwks',
            'enabled' => true,
        ];

        // Act
        $postId = $this->repo->create($data);

        // Assert - verify we can retrieve it
        $found = $this->repo->find($postId);
        $this->assertInstanceOf(WP_Post::class, $found);
        $this->assertEquals('Full Platform', $found->post_title);
    }

    // =========================================================================
    // update() tests
    // =========================================================================

    public function test_update_returns_true_on_success(): void
    {
        // Arrange
        $platform = $this->createPlatform(['ID' => 401, 'post_title' => 'Original Name']);
        $this->setPlatformMeta($platform->ID, ['enabled' => true]);

        // Act
        $result = $this->repo->update(401, [
            'name' => 'Updated Name',
            'enabled' => false,
        ]);

        // Assert
        $this->assertTrue($result);
    }

    public function test_update_returns_wp_error_when_not_found(): void
    {
        // Act
        $result = $this->repo->update(99999, ['name' => 'Does Not Exist']);

        // Assert
        $this->assertInstanceOf(WP_Error::class, $result);
    }

    // =========================================================================
    // delete() tests
    // =========================================================================

    public function test_delete_returns_true_on_success(): void
    {
        // Arrange
        $platform = $this->createPlatform(['ID' => 501, 'post_title' => 'To Delete']);
        $this->setPlatformMeta($platform->ID, ['enabled' => true]);

        // Act
        $result = $this->repo->delete(501);

        // Assert
        $this->assertTrue($result);
    }

    public function test_delete_returns_wp_error_when_not_found(): void
    {
        // Act
        $result = $this->repo->delete(99999);

        // Assert
        $this->assertInstanceOf(WP_Error::class, $result);
    }

    public function test_delete_removes_platform_from_find(): void
    {
        // Arrange
        $platform = $this->createPlatform(['ID' => 502, 'post_title' => 'To Delete']);
        $this->setPlatformMeta($platform->ID, ['enabled' => true]);

        // Act
        $this->repo->delete(502);

        // Assert - should not be findable anymore
        $result = $this->repo->find(502);
        $this->assertInstanceOf(WP_Error::class, $result);
    }

    // =========================================================================
    // Helper methods
    // =========================================================================

    /**
     * Create a test LTI platform post
     */
    protected function createPlatform(array $data = []): object
    {
        global $_test_posts;

        static $nextId = 100;

        $defaults = [
            'ID' => $nextId++,
            'post_type' => 'lti_platform',
            'post_title' => 'Test Platform',
            'post_status' => 'publish',
        ];

        $platformData = array_merge($defaults, $data);
        $platform = (object) $platformData;

        $_test_posts[$platform->ID] = $platform;

        return $platform;
    }

    /**
     * Set platform meta via Data Manager mock
     */
    protected function setPlatformMeta(int $postId, array $meta): void
    {
        $this->setDataManagerMeta('lti_platform', $postId, $meta);
    }
}
