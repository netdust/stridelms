<?php
declare(strict_types=1);

namespace NetdustLTI\Tests\Unit;

use NetdustLTI\Platform\ToolRepository;
use Stride\Tests\TestCase;
use WP_Error;
use WP_Post;

/**
 * Unit tests for ToolRepository
 *
 * Tests the Data Manager-based ToolRepository implementation
 * for CRUD operations on LTI tool CPT records.
 */
class ToolRepositoryTest extends TestCase
{
    private ToolRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new ToolRepository();
    }

    // =========================================================================
    // find() tests
    // =========================================================================

    public function test_find_returns_wp_post_when_found(): void
    {
        // Arrange: create a tool post
        $tool = $this->createTool([
            'ID' => 100,
            'post_title' => 'H5P Tool',
        ]);
        $this->setToolMeta($tool->ID, [
            'launch_url' => 'https://h5p.example.com/launch',
            'oidc_url' => 'https://h5p.example.com/oidc',
            'jwks_url' => 'https://h5p.example.com/jwks',
            'client_id' => 'client-123',
            'deployment_id' => 'deploy-1',
        ]);

        // Act
        $result = $this->repo->find(100);

        // Assert
        $this->assertInstanceOf(WP_Post::class, $result);
        $this->assertEquals(100, $result->ID);
        $this->assertEquals('H5P Tool', $result->post_title);
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
            'post_type' => 'post', // Not lti_tool
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
    // findBySlug() tests
    // =========================================================================

    public function test_find_by_slug_returns_wp_post_when_found(): void
    {
        // Arrange
        $tool = $this->createTool([
            'ID' => 101,
            'post_title' => 'Articulate Tool',
            'post_name' => 'articulate-tool',
        ]);
        $this->setToolMeta($tool->ID, [
            'launch_url' => 'https://articulate.example.com/launch',
            'oidc_url' => 'https://articulate.example.com/oidc',
            'jwks_url' => 'https://articulate.example.com/jwks',
            'client_id' => 'articulate-client',
            'deployment_id' => 'deploy-2',
        ]);

        // Act
        $result = $this->repo->findBySlug('articulate-tool');

        // Assert
        $this->assertInstanceOf(WP_Post::class, $result);
        $this->assertEquals(101, $result->ID);
    }

    public function test_find_by_slug_returns_wp_error_when_not_found(): void
    {
        // Act
        $result = $this->repo->findBySlug('nonexistent-tool');

        // Assert
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertEquals('not_found', $result->get_error_code());
    }

    // =========================================================================
    // all() tests
    // =========================================================================

    public function test_all_returns_array_of_tools(): void
    {
        // Arrange
        $tool1 = $this->createTool(['ID' => 201, 'post_title' => 'Tool A']);
        $tool2 = $this->createTool(['ID' => 202, 'post_title' => 'Tool B']);
        $this->setToolMeta($tool1->ID, ['launch_url' => 'https://a.example.com/launch']);
        $this->setToolMeta($tool2->ID, ['launch_url' => 'https://b.example.com/launch']);

        // Act
        $result = $this->repo->all();

        // Assert
        $this->assertIsArray($result);
        $this->assertCount(2, $result);
    }

    public function test_all_returns_empty_array_when_no_tools(): void
    {
        // Act
        $result = $this->repo->all();

        // Assert
        $this->assertIsArray($result);
        $this->assertCount(0, $result);
    }

    public function test_all_includes_meta_fields(): void
    {
        // Arrange
        $tool = $this->createTool(['ID' => 203, 'post_title' => 'Tool With Meta']);
        $this->setToolMeta($tool->ID, [
            'launch_url' => 'https://meta.example.com/launch',
            'oidc_url' => 'https://meta.example.com/oidc',
            'jwks_url' => 'https://meta.example.com/jwks',
            'client_id' => 'meta-client',
        ]);

        // Act
        $result = $this->repo->all();

        // Assert
        $this->assertCount(1, $result);
        $this->assertArrayHasKey('fields', $result[0]);
        $this->assertEquals('https://meta.example.com/launch', $result[0]['fields']['launch_url']);
    }

    // =========================================================================
    // create() tests
    // =========================================================================

    public function test_create_returns_post_id_on_success(): void
    {
        // Act
        $result = $this->repo->create([
            'name' => 'New Tool',
            'launch_url' => 'https://new.example.com/launch',
            'oidc_url' => 'https://new.example.com/oidc',
            'jwks_url' => 'https://new.example.com/jwks',
            'client_id' => 'new-client',
            'deployment_id' => 'deploy-new',
        ]);

        // Assert
        $this->assertIsInt($result);
        $this->assertGreaterThan(0, $result);
    }

    public function test_create_stores_all_fields(): void
    {
        // Arrange
        $data = [
            'name' => 'Full Tool',
            'launch_url' => 'https://full.example.com/launch',
            'oidc_url' => 'https://full.example.com/oidc',
            'jwks_url' => 'https://full.example.com/jwks',
            'client_id' => 'full-client',
            'deployment_id' => 'full-deploy',
        ];

        // Act
        $postId = $this->repo->create($data);

        // Assert - verify we can retrieve it
        $found = $this->repo->find($postId);
        $this->assertInstanceOf(WP_Post::class, $found);
        $this->assertEquals('Full Tool', $found->post_title);
    }

    public function test_create_uses_default_title_when_name_missing(): void
    {
        // Act
        $postId = $this->repo->create([
            'launch_url' => 'https://untitled.example.com/launch',
        ]);

        // Assert
        $found = $this->repo->find($postId);
        $this->assertInstanceOf(WP_Post::class, $found);
        $this->assertEquals('Untitled Tool', $found->post_title);
    }

    // =========================================================================
    // update() tests
    // =========================================================================

    public function test_update_returns_true_on_success(): void
    {
        // Arrange
        $tool = $this->createTool(['ID' => 401, 'post_title' => 'Original Name']);
        $this->setToolMeta($tool->ID, ['launch_url' => 'https://original.example.com/launch']);

        // Act
        $result = $this->repo->update(401, [
            'name' => 'Updated Name',
            'launch_url' => 'https://updated.example.com/launch',
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

    public function test_update_modifies_meta_fields(): void
    {
        // Arrange
        $tool = $this->createTool(['ID' => 402, 'post_title' => 'Tool']);
        $this->setToolMeta($tool->ID, [
            'launch_url' => 'https://old.example.com/launch',
            'oidc_url' => 'https://old.example.com/oidc',
        ]);

        // Act
        $this->repo->update(402, [
            'launch_url' => 'https://new.example.com/launch',
        ]);

        // Assert
        $found = $this->repo->find(402);
        $this->assertEquals('https://new.example.com/launch', $found->meta['launch_url'] ?? $found->fields['launch_url'] ?? null);
    }

    // =========================================================================
    // delete() tests
    // =========================================================================

    public function test_delete_returns_true_on_success(): void
    {
        // Arrange
        $tool = $this->createTool(['ID' => 501, 'post_title' => 'To Delete']);
        $this->setToolMeta($tool->ID, ['launch_url' => 'https://delete.example.com/launch']);

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

    public function test_delete_removes_tool_from_find(): void
    {
        // Arrange
        $tool = $this->createTool(['ID' => 502, 'post_title' => 'To Delete']);
        $this->setToolMeta($tool->ID, ['launch_url' => 'https://delete.example.com/launch']);

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
     * Create a test LTI tool post
     */
    protected function createTool(array $data = []): object
    {
        global $_test_posts;

        static $nextId = 100;

        $defaults = [
            'ID' => $nextId++,
            'post_type' => 'lti_tool',
            'post_title' => 'Test Tool',
            'post_status' => 'publish',
            'post_name' => '',
        ];

        $toolData = array_merge($defaults, $data);

        // Generate slug if not provided
        if (empty($toolData['post_name']) && !empty($toolData['post_title'])) {
            $toolData['post_name'] = sanitize_title($toolData['post_title']);
        }

        $tool = (object) $toolData;

        $_test_posts[$tool->ID] = $tool;

        return $tool;
    }

    /**
     * Set tool meta via Data Manager mock
     */
    protected function setToolMeta(int $postId, array $meta): void
    {
        $this->setDataManagerMeta('lti_tool', $postId, $meta);
    }
}
