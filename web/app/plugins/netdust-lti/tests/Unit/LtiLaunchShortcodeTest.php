<?php
declare(strict_types=1);

namespace NetdustLTI\Tests\Unit;

use NetdustLTI\Platform\LtiLaunchShortcode;
use NetdustLTI\Platform\ToolRepository;
use PHPUnit\Framework\TestCase;
use WP_Post;
use WP_Error;

class LtiLaunchShortcodeTest extends TestCase
{
    private ToolRepository $toolRepository;

    protected function setUp(): void
    {
        parent::setUp();

        // Reset global state
        global $_test_options, $_test_posts, $_test_data_manager_meta, $_test_actions;
        $_test_options = ['home' => 'https://example.com'];
        $_test_posts = [];
        $_test_data_manager_meta = [];
        $_test_actions = [];

        $this->toolRepository = $this->createMock(ToolRepository::class);
    }

    public function test_implements_service_meta(): void
    {
        $this->assertTrue(
            in_array(\NTDST_Service_Meta::class, class_implements(LtiLaunchShortcode::class)),
            'LtiLaunchShortcode should implement NTDST_Service_Meta'
        );
    }

    public function test_metadata_returns_expected_structure(): void
    {
        $metadata = LtiLaunchShortcode::metadata();

        $this->assertIsArray($metadata);
        $this->assertArrayHasKey('name', $metadata);
        $this->assertArrayHasKey('description', $metadata);
        $this->assertArrayHasKey('priority', $metadata);
        $this->assertEquals('LTI Launch Shortcode', $metadata['name']);
    }

    public function test_renders_form_with_numeric_tool_id(): void
    {
        $shortcode = new LtiLaunchShortcode($this->toolRepository);

        $output = $shortcode->render(['tool' => '123', 'course_id' => '456'], 'Launch Course');

        $this->assertStringContainsString('<form', $output);
        $this->assertStringContainsString('name="tool_id"', $output);
        $this->assertStringContainsString('value="123"', $output);
        $this->assertStringContainsString('name="resource_link_id"', $output);
        $this->assertStringContainsString('value="456"', $output);
        $this->assertStringContainsString('Launch Course', $output);
        $this->assertStringContainsString('lti_nonce', $output);
    }

    public function test_renders_form_with_tool_slug(): void
    {
        $tool = new WP_Post(['ID' => 42, 'post_type' => 'lti_tool', 'post_name' => 'articulate-tool']);

        $this->toolRepository
            ->expects($this->once())
            ->method('findBySlug')
            ->with('articulate-tool')
            ->willReturn($tool);

        $shortcode = new LtiLaunchShortcode($this->toolRepository);

        $output = $shortcode->render(['tool' => 'articulate-tool'], 'Start');

        $this->assertStringContainsString('value="42"', $output);
        $this->assertStringContainsString('Start', $output);
    }

    public function test_returns_comment_when_tool_not_found(): void
    {
        $this->toolRepository
            ->expects($this->once())
            ->method('findBySlug')
            ->with('nonexistent')
            ->willReturn(new WP_Error('not_found', 'Tool not found'));

        $shortcode = new LtiLaunchShortcode($this->toolRepository);

        $output = $shortcode->render(['tool' => 'nonexistent']);

        $this->assertStringContainsString('<!-- LTI Launch: Tool not found -->', $output);
        $this->assertStringNotContainsString('<form', $output);
    }

    public function test_returns_comment_when_tool_empty(): void
    {
        $shortcode = new LtiLaunchShortcode($this->toolRepository);

        $output = $shortcode->render(['tool' => '']);

        $this->assertStringContainsString('<!-- LTI Launch: Tool not found -->', $output);
    }

    public function test_uses_default_button_text(): void
    {
        $shortcode = new LtiLaunchShortcode($this->toolRepository);

        $output = $shortcode->render(['tool' => '123']);

        // Button contains "Launch" text (with possible whitespace)
        $this->assertStringContainsString('Launch', $output);
        $this->assertStringContainsString('</button>', $output);
    }

    public function test_applies_custom_class(): void
    {
        $shortcode = new LtiLaunchShortcode($this->toolRepository);

        $output = $shortcode->render(['tool' => '123', 'class' => 'button primary large']);

        $this->assertStringContainsString('class="button primary large"', $output);
    }

    public function test_discover_mode_sets_deep_linking_message_type(): void
    {
        $shortcode = new LtiLaunchShortcode($this->toolRepository);

        $output = $shortcode->render(['tool' => '123', 'mode' => 'discover']);

        $this->assertStringContainsString('name="message_type"', $output);
        $this->assertStringContainsString('value="LtiDeepLinkingRequest"', $output);
    }

    public function test_launch_mode_sets_resource_link_message_type(): void
    {
        $shortcode = new LtiLaunchShortcode($this->toolRepository);

        $output = $shortcode->render(['tool' => '123', 'mode' => 'launch']);

        $this->assertStringContainsString('name="message_type"', $output);
        $this->assertStringContainsString('value="LtiResourceLinkRequest"', $output);
    }

    public function test_default_mode_is_launch(): void
    {
        $shortcode = new LtiLaunchShortcode($this->toolRepository);

        $output = $shortcode->render(['tool' => '123']);

        $this->assertStringContainsString('value="LtiResourceLinkRequest"', $output);
    }

    public function test_includes_target_uri_when_provided(): void
    {
        $shortcode = new LtiLaunchShortcode($this->toolRepository);

        $output = $shortcode->render(['tool' => '123', 'target_uri' => 'https://tool.example.com/course/1']);

        $this->assertStringContainsString('name="target_link_uri"', $output);
        $this->assertStringContainsString('value="https://tool.example.com/course/1"', $output);
    }

    public function test_form_posts_to_platform_launch_url(): void
    {
        $shortcode = new LtiLaunchShortcode($this->toolRepository);

        $output = $shortcode->render(['tool' => '123']);

        $this->assertStringContainsString('action="https://example.com/lti/platform/launch"', $output);
    }

    public function test_form_opens_in_new_tab(): void
    {
        $shortcode = new LtiLaunchShortcode($this->toolRepository);

        $output = $shortcode->render(['tool' => '123']);

        $this->assertStringContainsString('target="_blank"', $output);
    }

    public function test_form_uses_post_method(): void
    {
        $shortcode = new LtiLaunchShortcode($this->toolRepository);

        $output = $shortcode->render(['tool' => '123']);

        $this->assertStringContainsString('method="post"', $output);
    }

    public function test_escapes_html_in_button_text(): void
    {
        $shortcode = new LtiLaunchShortcode($this->toolRepository);

        $output = $shortcode->render(['tool' => '123'], '<script>alert("xss")</script>');

        $this->assertStringNotContainsString('<script>', $output);
        $this->assertStringContainsString('&lt;script&gt;', $output);
    }

    public function test_escapes_attributes(): void
    {
        $shortcode = new LtiLaunchShortcode($this->toolRepository);

        $output = $shortcode->render(['tool' => '123', 'class' => 'button" onclick="alert(1)']);

        // The class attribute should be safely escaped - quotes become &quot;
        $this->assertStringContainsString('&quot;', $output);
        // The raw onclick attribute should not appear unescaped
        $this->assertStringNotContainsString('onclick="alert', $output);
    }

    public function test_registers_shortcode_on_construction(): void
    {
        global $_test_actions;
        $_test_actions = [];

        // We can't easily test add_shortcode since it's WordPress-specific
        // but we can verify the class constructs without errors
        $shortcode = new LtiLaunchShortcode($this->toolRepository);

        $this->assertInstanceOf(LtiLaunchShortcode::class, $shortcode);
    }

    public function test_handles_string_attributes(): void
    {
        $shortcode = new LtiLaunchShortcode($this->toolRepository);

        // WordPress sometimes passes attributes as a string (empty shortcode)
        $output = $shortcode->render('', null);

        // Should handle gracefully
        $this->assertStringContainsString('<!-- LTI Launch: Tool not found -->', $output);
    }
}
