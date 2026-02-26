<?php

declare(strict_types=1);

namespace Tests\Unit\NetdustLTI;

use Stride\Tests\TestCase;
use NetdustLTI\Data\LTIDataService;

class LTIDataServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Reset registered post types
        global $_test_registered_post_types;
        $_test_registered_post_types = [];
    }

    protected function tearDown(): void
    {
        global $_test_registered_post_types;
        $_test_registered_post_types = [];

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
        $this->assertEquals('options-general.php', $config['show_in_menu']);
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
}
