<?php

declare(strict_types=1);

namespace Tests\Unit\NetdustLTI;

use Stride\Tests\TestCase;
use NetdustLTI\Shared\LTIDataService;

class LTIDataServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Reset registered post types and meta
        global $_test_registered_post_types, $_test_registered_post_meta;
        $_test_registered_post_types = [];
        $_test_registered_post_meta = [];
    }

    protected function tearDown(): void
    {
        global $_test_registered_post_types, $_test_registered_post_meta;
        $_test_registered_post_types = [];
        $_test_registered_post_meta = [];

        parent::tearDown();
    }

    public function test_platform_cpt_is_registered(): void
    {
        // Arrange - trigger registration via init hook
        $service = new LTIDataService();
        do_action('init');

        // Assert
        $this->assertTrue(
            post_type_exists('lti_platform'),
            'lti_platform post type should be registered'
        );
    }

    public function test_tool_cpt_is_registered(): void
    {
        // Arrange
        $service = new LTIDataService();
        do_action('init');

        // Assert
        $this->assertTrue(
            post_type_exists('lti_tool'),
            'lti_tool post type should be registered'
        );
    }

    public function test_platform_cpt_has_correct_configuration(): void
    {
        // Arrange
        $service = new LTIDataService();
        do_action('init');

        // Assert
        global $_test_registered_post_types;
        $config = $_test_registered_post_types['lti_platform'] ?? [];

        $this->assertEquals('LTI Platforms', $config['label']);
        $this->assertFalse($config['public']);
        $this->assertTrue($config['show_ui']);
        $this->assertFalse($config['show_in_menu']);
        $this->assertTrue($config['show_in_rest']);
        $this->assertEquals('lti-platforms', $config['rest_base']);
        $this->assertEquals(['title'], $config['supports']);
    }

    public function test_platform_cpt_has_required_fields(): void
    {
        // Arrange
        $service = new LTIDataService();
        do_action('init');

        // Assert
        global $_test_registered_post_types;
        $config = $_test_registered_post_types['lti_platform'] ?? [];
        $fields = $config['fields'] ?? [];

        // Required credential fields
        $this->assertArrayHasKey('platform_id', $fields);
        $this->assertArrayHasKey('client_id', $fields);
        $this->assertArrayHasKey('deployment_id', $fields);

        // Required endpoint fields
        $this->assertArrayHasKey('auth_endpoint', $fields);
        $this->assertArrayHasKey('token_endpoint', $fields);
        $this->assertArrayHasKey('jwks_endpoint', $fields);

        // Settings
        $this->assertArrayHasKey('enabled', $fields);
    }

    public function test_tool_cpt_has_required_fields(): void
    {
        // Arrange
        $service = new LTIDataService();
        do_action('init');

        // Assert
        global $_test_registered_post_types;
        $config = $_test_registered_post_types['lti_tool'] ?? [];
        $fields = $config['fields'] ?? [];

        // Required tool fields
        $this->assertArrayHasKey('launch_url', $fields);
        $this->assertArrayHasKey('oidc_url', $fields);
        $this->assertArrayHasKey('jwks_url', $fields);
        $this->assertArrayHasKey('client_id', $fields);
        $this->assertArrayHasKey('deployment_id', $fields);
    }

    public function test_platform_fields_have_correct_types(): void
    {
        // Arrange
        $service = new LTIDataService();
        do_action('init');

        // Assert
        global $_test_registered_post_types;
        $config = $_test_registered_post_types['lti_platform'] ?? [];
        $fields = $config['fields'] ?? [];

        // URL fields should be type 'url'
        $this->assertEquals('url', $fields['platform_id']['type']);
        $this->assertEquals('url', $fields['auth_endpoint']['type']);
        $this->assertEquals('url', $fields['token_endpoint']['type']);
        $this->assertEquals('url', $fields['jwks_endpoint']['type']);

        // Boolean field
        $this->assertEquals('boolean', $fields['enabled']['type']);

        // Text fields
        $this->assertEquals('text', $fields['client_id']['type']);
    }

    public function test_service_implements_ntdst_service_meta(): void
    {
        $this->assertTrue(
            is_subclass_of(LTIDataService::class, \NTDST_Service_Meta::class)
        );
    }

    public function test_service_metadata_is_correct(): void
    {
        $metadata = LTIDataService::metadata();

        $this->assertArrayHasKey('name', $metadata);
        $this->assertArrayHasKey('description', $metadata);
        $this->assertArrayHasKey('priority', $metadata);
        $this->assertEquals('LTI Data Service', $metadata['name']);
    }

    public function test_tool_cpt_has_rest_configuration(): void
    {
        $service = new LTIDataService();
        do_action('init');

        global $_test_registered_post_types;
        $config = $_test_registered_post_types['lti_tool'] ?? [];

        $this->assertFalse($config['show_in_menu']);
        $this->assertTrue($config['show_in_rest']);
        $this->assertEquals('lti-tools', $config['rest_base']);
    }

    public function test_resource_cpt_has_rest_configuration(): void
    {
        $service = new LTIDataService();
        do_action('init');

        global $_test_registered_post_types;
        $config = $_test_registered_post_types['lti_resource'] ?? [];

        $this->assertFalse($config['show_in_menu']);
        $this->assertTrue($config['show_in_rest']);
        $this->assertEquals('lti-resources', $config['rest_base']);
    }

    public function test_platform_rest_meta_is_registered(): void
    {
        $service = new LTIDataService();
        do_action('rest_api_init');

        global $_test_registered_post_meta;
        $meta = $_test_registered_post_meta['lti_platform'] ?? [];

        // String fields
        $expectedStringKeys = [
            'lti_platform_id', 'lti_client_id', 'lti_deployment_id',
            'lti_auth_endpoint', 'lti_token_endpoint', 'lti_jwks_endpoint',
            'lti_rsa_key', 'lti_kid', 'lti_contexts',
            'lti_role_instructor', 'lti_role_learner',
        ];

        foreach ($expectedStringKeys as $key) {
            $this->assertArrayHasKey($key, $meta, "Platform meta '{$key}' should be registered");
            $this->assertTrue($meta[$key]['show_in_rest']);
            $this->assertTrue($meta[$key]['single']);
            $this->assertEquals('string', $meta[$key]['type']);
        }

        // Boolean field
        $this->assertArrayHasKey('lti_enabled', $meta);
        $this->assertEquals('boolean', $meta['lti_enabled']['type']);
        $this->assertTrue($meta['lti_enabled']['show_in_rest']);
    }

    public function test_tool_rest_meta_is_registered(): void
    {
        $service = new LTIDataService();
        do_action('rest_api_init');

        global $_test_registered_post_meta;
        $meta = $_test_registered_post_meta['lti_tool'] ?? [];

        $expectedKeys = [
            'lti_launch_url', 'lti_oidc_url', 'lti_jwks_url',
            'lti_client_id', 'lti_deployment_id', 'lti_public_key',
        ];

        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $meta, "Tool meta '{$key}' should be registered");
            $this->assertTrue($meta[$key]['show_in_rest']);
            $this->assertTrue($meta[$key]['single']);
            $this->assertEquals('string', $meta[$key]['type']);
        }
    }

    public function test_resource_rest_meta_is_registered(): void
    {
        $service = new LTIDataService();
        do_action('rest_api_init');

        global $_test_registered_post_meta;
        $meta = $_test_registered_post_meta['lti_resource'] ?? [];

        // Integer field
        $this->assertArrayHasKey('lti_tool_id', $meta);
        $this->assertEquals('integer', $meta['lti_tool_id']['type']);
        $this->assertTrue($meta['lti_tool_id']['show_in_rest']);

        // String fields
        $expectedStringKeys = [
            'lti_launch_url', 'lti_course_id',
            'lti_custom_params', 'lti_description',
        ];

        foreach ($expectedStringKeys as $key) {
            $this->assertArrayHasKey($key, $meta, "Resource meta '{$key}' should be registered");
            $this->assertEquals('string', $meta[$key]['type']);
            $this->assertTrue($meta[$key]['show_in_rest']);
        }
    }

    public function test_rest_meta_has_auth_callback(): void
    {
        $service = new LTIDataService();
        do_action('rest_api_init');

        global $_test_registered_post_meta;

        // Check that auth_callback is set on platform meta
        $meta = $_test_registered_post_meta['lti_platform']['lti_platform_id'] ?? [];
        $this->assertArrayHasKey('auth_callback', $meta);
        $this->assertIsCallable($meta['auth_callback']);

        // Check that auth_callback is set on tool meta
        $meta = $_test_registered_post_meta['lti_tool']['lti_launch_url'] ?? [];
        $this->assertArrayHasKey('auth_callback', $meta);
        $this->assertIsCallable($meta['auth_callback']);

        // Check that auth_callback is set on resource meta
        $meta = $_test_registered_post_meta['lti_resource']['lti_tool_id'] ?? [];
        $this->assertArrayHasKey('auth_callback', $meta);
        $this->assertIsCallable($meta['auth_callback']);
    }
}
