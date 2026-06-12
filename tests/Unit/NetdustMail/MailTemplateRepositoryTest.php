<?php

declare(strict_types=1);

namespace Tests\Unit\NetdustMail;

use Netdust\Mail\MailTemplateCPT;
use Netdust\Mail\MailTemplateRepository;
use PHPUnit\Framework\TestCase;

class MailTemplateRepositoryTest extends TestCase
{
    private MailTemplateRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        // Reset global test state
        global $_test_posts, $_test_data_manager_meta;
        $_test_posts = [];
        $_test_data_manager_meta = [];

        $this->repository = new MailTemplateRepository();
    }

    protected function tearDown(): void
    {
        global $_test_posts, $_test_data_manager_meta;
        $_test_posts = [];
        $_test_data_manager_meta = [];

        parent::tearDown();
    }

    public function test_get_post_type_returns_correct_value(): void
    {
        $this->assertEquals(MailTemplateCPT::POST_TYPE, $this->repository->getPostType());
        $this->assertEquals('ndmail_template', $this->repository->getPostType());
    }

    public function test_find_by_slug_returns_null_when_not_found(): void
    {
        $result = $this->repository->findBySlug('non-existent-template');
        $this->assertNull($result);
    }

    public function test_find_by_slug_returns_template_when_found(): void
    {
        $this->createTestTemplate([
            'ID' => 100,
            'post_name' => 'welcome-email',
            'post_status' => 'publish',
        ], [
            'subject' => 'Welcome!',
            'body' => '<p>Hello!</p>',
        ]);

        $result = $this->repository->findBySlug('welcome-email');

        $this->assertNotNull($result);
        $this->assertInstanceOf(\WP_Post::class, $result);
        $this->assertEquals(100, $result->ID);
        $this->assertEquals('welcome-email', $result->post_name);
    }

    public function test_find_by_slug_attaches_fields(): void
    {
        $this->createTestTemplate([
            'ID' => 101,
            'post_name' => 'template-with-fields',
            'post_status' => 'publish',
            'post_content' => '<p>Test Body</p>',
        ], [
            'subject' => 'Test Subject',
            'category' => 'notification',
        ]);

        $result = $this->repository->findBySlug('template-with-fields');

        $this->assertNotNull($result);
        $this->assertIsArray($result->fields);
        $this->assertEquals('Test Subject', $result->fields['subject']);
        $this->assertEquals('<p>Test Body</p>', $result->post_content); // Body is now in post_content
        $this->assertEquals('notification', $result->fields['category']);
    }

    public function test_find_by_slug_returns_null_for_draft_template(): void
    {
        $this->createTestTemplate([
            'ID' => 102,
            'post_name' => 'draft-template',
            'post_status' => 'draft',
        ]);

        $result = $this->repository->findBySlug('draft-template');
        $this->assertNull($result);
    }

    public function test_find_by_id_returns_null_on_wp_error(): void
    {
        // When post doesn't exist, find() returns WP_Error -> repository returns null
        $result = $this->repository->findById(99999);
        $this->assertNull($result);
    }

    public function test_find_by_id_returns_template_when_found(): void
    {
        $this->createTestTemplate([
            'ID' => 103,
            'post_name' => 'test-template',
            'post_status' => 'publish',
        ], [
            'subject' => 'ID Test',
        ]);

        $result = $this->repository->findById(103);

        $this->assertNotNull($result);
        $this->assertInstanceOf(\WP_Post::class, $result);
        $this->assertEquals(103, $result->ID);
    }

    public function test_find_by_id_returns_template_with_fields(): void
    {
        $this->createTestTemplate([
            'ID' => 104,
            'post_status' => 'publish',
        ], [
            'subject' => 'Field Test Subject',
            'trigger' => 'user_registered',
        ]);

        $result = $this->repository->findById(104);

        $this->assertNotNull($result);
        $this->assertIsArray($result->fields);
        $this->assertEquals('Field Test Subject', $result->fields['subject']);
        $this->assertEquals('user_registered', $result->fields['trigger']);
    }

    public function test_find_with_triggers_returns_empty_array_when_none(): void
    {
        $this->createTestTemplate([
            'ID' => 105,
            'post_status' => 'publish',
        ], [
            'trigger' => '', // No trigger
        ]);

        $result = $this->repository->findWithTriggers();
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function test_find_with_triggers_returns_templates_with_triggers(): void
    {
        // Template with trigger
        $this->createTestTemplate([
            'ID' => 106,
            'post_status' => 'publish',
        ], [
            'trigger' => 'user_registered',
        ]);

        // Template without trigger
        $this->createTestTemplate([
            'ID' => 107,
            'post_status' => 'publish',
        ], [
            'trigger' => '',
        ]);

        // Another template with trigger
        $this->createTestTemplate([
            'ID' => 108,
            'post_status' => 'publish',
        ], [
            'trigger' => 'order_completed',
        ]);

        $result = $this->repository->findWithTriggers();

        $this->assertIsArray($result);
        $this->assertCount(2, $result);

        $ids = array_map(fn($p) => $p->ID, $result);
        $this->assertContains(106, $ids);
        $this->assertContains(108, $ids);
        $this->assertNotContains(107, $ids);
    }

    public function test_find_by_category_returns_empty_array_when_none(): void
    {
        $result = $this->repository->findByCategory('auth');
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function test_find_by_category_returns_matching_templates(): void
    {
        $this->createTestTemplate([
            'ID' => 109,
            'post_status' => 'publish',
        ], [
            'category' => 'auth',
        ]);

        $this->createTestTemplate([
            'ID' => 110,
            'post_status' => 'publish',
        ], [
            'category' => 'notification',
        ]);

        $this->createTestTemplate([
            'ID' => 111,
            'post_status' => 'publish',
        ], [
            'category' => 'auth',
        ]);

        $result = $this->repository->findByCategory('auth');

        $this->assertIsArray($result);
        $this->assertCount(2, $result);

        $ids = array_map(fn($p) => $p->ID, $result);
        $this->assertContains(109, $ids);
        $this->assertContains(111, $ids);
    }

    public function test_find_all_returns_empty_array_when_none(): void
    {
        $result = $this->repository->findAll();
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function test_find_all_returns_all_published_templates(): void
    {
        $this->createTestTemplate([
            'ID' => 112,
            'post_status' => 'publish',
        ]);

        $this->createTestTemplate([
            'ID' => 113,
            'post_status' => 'draft',
        ]);

        $this->createTestTemplate([
            'ID' => 114,
            'post_status' => 'publish',
        ]);

        $result = $this->repository->findAll();

        $this->assertIsArray($result);
        $this->assertCount(2, $result);

        $ids = array_map(fn($p) => $p->ID, $result);
        $this->assertContains(112, $ids);
        $this->assertContains(114, $ids);
        $this->assertNotContains(113, $ids);
    }

    public function test_create_returns_wp_post(): void
    {
        $result = $this->repository->create([
            'title' => 'New Template',
            'subject' => 'New Subject',
            'body' => '<p>New Body</p>',
        ]);

        $this->assertInstanceOf(\WP_Post::class, $result);
        $this->assertEquals('New Template', $result->post_title);
    }

    public function test_create_defaults_to_draft_status(): void
    {
        $result = $this->repository->create([
            'title' => 'Draft Template',
        ]);

        $this->assertInstanceOf(\WP_Post::class, $result);
        $this->assertEquals('draft', $result->post_status);
    }

    public function test_create_respects_provided_status(): void
    {
        $result = $this->repository->create([
            'title' => 'Published Template',
            'post_status' => 'publish',
        ]);

        $this->assertInstanceOf(\WP_Post::class, $result);
        $this->assertEquals('publish', $result->post_status);
    }

    public function test_create_stores_meta_fields(): void
    {
        $result = $this->repository->create([
            'title' => 'Template With Fields',
            'post_status' => 'publish',
            'post_content' => '<p>Created Body</p>',
            'subject' => 'Created Subject',
            'category' => 'transactional',
        ]);

        $this->assertInstanceOf(\WP_Post::class, $result);
        $this->assertIsArray($result->fields);
        $this->assertEquals('Created Subject', $result->fields['subject']);
        $this->assertEquals('<p>Created Body</p>', $result->post_content); // Body is in post_content
        $this->assertEquals('transactional', $result->fields['category']);
    }

    public function test_update_returns_wp_post(): void
    {
        $this->createTestTemplate([
            'ID' => 115,
            'post_title' => 'Original Title',
            'post_status' => 'publish',
        ]);

        $result = $this->repository->update(115, [
            'post_title' => 'Updated Title',
        ]);

        $this->assertInstanceOf(\WP_Post::class, $result);
        $this->assertEquals('Updated Title', $result->post_title);
    }

    public function test_update_returns_wp_error_for_nonexistent_post(): void
    {
        $result = $this->repository->update(99999, [
            'post_title' => 'Updated Title',
        ]);

        $this->assertInstanceOf(\WP_Error::class, $result);
    }

    public function test_update_updates_meta_fields(): void
    {
        $this->createTestTemplate([
            'ID' => 116,
            'post_status' => 'publish',
        ], [
            'subject' => 'Original Subject',
        ]);

        $result = $this->repository->update(116, [
            'subject' => 'Updated Subject',
            'trigger' => 'new_trigger',
        ]);

        $this->assertInstanceOf(\WP_Post::class, $result);
        $this->assertEquals('Updated Subject', $result->fields['subject']);
        $this->assertEquals('new_trigger', $result->fields['trigger']);
    }

    public function test_delete_returns_true_on_success(): void
    {
        $this->createTestTemplate([
            'ID' => 117,
            'post_status' => 'publish',
        ]);

        $result = $this->repository->delete(117);
        $this->assertTrue($result);
    }

    public function test_delete_returns_wp_error_for_nonexistent_post(): void
    {
        $result = $this->repository->delete(99999);
        $this->assertInstanceOf(\WP_Error::class, $result);
    }

    public function test_delete_trashes_by_default(): void
    {
        global $_test_posts;

        $this->createTestTemplate([
            'ID' => 118,
            'post_status' => 'publish',
        ]);

        $this->repository->delete(118);

        $this->assertArrayHasKey(118, $_test_posts);
        $this->assertEquals('trash', $_test_posts[118]->post_status);
    }

    public function test_delete_force_removes_permanently(): void
    {
        global $_test_posts;

        $this->createTestTemplate([
            'ID' => 119,
            'post_status' => 'publish',
        ]);

        $this->repository->delete(119, true);

        $this->assertArrayNotHasKey(119, $_test_posts);
    }

    public function test_method_signatures_are_correct(): void
    {
        $reflection = new \ReflectionClass(MailTemplateRepository::class);

        // getPostType
        $method = $reflection->getMethod('getPostType');
        $this->assertEquals('string', $method->getReturnType()->getName());
        $this->assertCount(0, $method->getParameters());

        // findBySlug
        $method = $reflection->getMethod('findBySlug');
        $this->assertTrue($method->getReturnType()->allowsNull());
        $this->assertCount(1, $method->getParameters());
        $this->assertEquals('string', $method->getParameters()[0]->getType()->getName());

        // findById
        $method = $reflection->getMethod('findById');
        $this->assertTrue($method->getReturnType()->allowsNull());
        $this->assertCount(1, $method->getParameters());
        $this->assertEquals('int', $method->getParameters()[0]->getType()->getName());

        // findWithTriggers
        $method = $reflection->getMethod('findWithTriggers');
        $this->assertEquals('array', $method->getReturnType()->getName());
        $this->assertCount(0, $method->getParameters());

        // findByCategory
        $method = $reflection->getMethod('findByCategory');
        $this->assertEquals('array', $method->getReturnType()->getName());
        $this->assertCount(1, $method->getParameters());
        $this->assertEquals('string', $method->getParameters()[0]->getType()->getName());

        // findAll
        $method = $reflection->getMethod('findAll');
        $this->assertEquals('array', $method->getReturnType()->getName());
        $this->assertCount(0, $method->getParameters());

        // create
        $method = $reflection->getMethod('create');
        $this->assertCount(1, $method->getParameters());
        $this->assertEquals('array', $method->getParameters()[0]->getType()->getName());

        // update
        $method = $reflection->getMethod('update');
        $this->assertCount(2, $method->getParameters());
        $this->assertEquals('int', $method->getParameters()[0]->getType()->getName());
        $this->assertEquals('array', $method->getParameters()[1]->getType()->getName());

        // delete
        $method = $reflection->getMethod('delete');
        $this->assertCount(2, $method->getParameters());
        $this->assertEquals('int', $method->getParameters()[0]->getType()->getName());
        $this->assertEquals('bool', $method->getParameters()[1]->getType()->getName());
        $this->assertFalse($method->getParameters()[1]->getDefaultValue());
    }

    /**
     * Helper to create a test template in global state
     */
    private function createTestTemplate(array $postData, array $meta = []): void
    {
        global $_test_posts, $_test_data_manager_meta;

        $defaults = [
            'post_type' => MailTemplateCPT::POST_TYPE,
            'post_title' => 'Test Template',
            'post_status' => 'publish',
            'post_name' => 'test-template-' . ($postData['ID'] ?? rand(1000, 9999)),
        ];

        $data = array_merge($defaults, $postData);
        $_test_posts[$data['ID']] = (object) $data;

        if (!empty($meta)) {
            if (!isset($_test_data_manager_meta[MailTemplateCPT::POST_TYPE])) {
                $_test_data_manager_meta[MailTemplateCPT::POST_TYPE] = [];
            }
            $_test_data_manager_meta[MailTemplateCPT::POST_TYPE][$data['ID']] = $meta;
        }
    }
}
