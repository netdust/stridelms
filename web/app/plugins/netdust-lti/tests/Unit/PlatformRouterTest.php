<?php
declare(strict_types=1);

namespace NetdustLTI\Tests\Unit;

use NetdustLTI\Platform\PlatformRouter;
use PHPUnit\Framework\TestCase;

class PlatformRouterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Reset global state
        global $wp_rewrite, $_test_query_vars, $_test_actions, $_test_filters, $_test_wp_die_called;
        $wp_rewrite = new class {
            public array $extra_rules_top = [];
        };
        $_test_query_vars = [];
        $_test_actions = [];
        $_test_filters = [];
        $_test_wp_die_called = null;
    }

    public function test_registers_rewrite_rules(): void
    {
        $router = new PlatformRouter();
        $router->registerRewriteRules();

        global $wp_rewrite;
        $rules = $wp_rewrite->extra_rules_top ?? [];

        $this->assertArrayHasKey('^lti/platform/([a-z-]+)/?$', $rules);
        $this->assertEquals('index.php?lti_platform_action=$matches[1]', $rules['^lti/platform/([a-z-]+)/?$']);
    }

    public function test_registers_query_vars(): void
    {
        $router = new PlatformRouter();

        $vars = $router->registerQueryVars(['existing_var']);

        $this->assertContains('lti_platform_action', $vars);
        $this->assertContains('existing_var', $vars);
    }

    public function test_implements_service_meta(): void
    {
        $this->assertTrue(
            in_array(\NTDST_Service_Meta::class, class_implements(PlatformRouter::class)),
            'PlatformRouter should implement NTDST_Service_Meta'
        );
    }

    public function test_metadata_returns_expected_structure(): void
    {
        $metadata = PlatformRouter::metadata();

        $this->assertIsArray($metadata);
        $this->assertArrayHasKey('name', $metadata);
        $this->assertArrayHasKey('description', $metadata);
        $this->assertArrayHasKey('priority', $metadata);
        $this->assertEquals('LTI Platform Router', $metadata['name']);
    }

    public function test_handle_request_does_nothing_without_action(): void
    {
        global $_test_query_vars;
        $_test_query_vars = [];

        $router = new PlatformRouter();

        // Should not throw or do anything
        $router->handleRequest();

        $this->assertTrue(true, 'handleRequest completed without action');
    }

    public function test_handle_request_rejects_invalid_action(): void
    {
        global $_test_query_vars;
        $_test_query_vars['lti_platform_action'] = 'invalid-action';

        $router = new PlatformRouter();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid platform action');

        $router->handleRequest();
    }

    public function test_registers_hooks_on_construction(): void
    {
        global $_test_actions, $_test_filters;

        $router = new PlatformRouter();

        // Check init action registered
        $initHooks = array_filter($_test_actions['init'] ?? [], function ($action) use ($router) {
            return $action['callback'] === [$router, 'registerRewriteRules'];
        });
        $this->assertNotEmpty($initHooks, 'registerRewriteRules should be hooked to init');

        // Check query_vars filter registered
        $queryVarsFilters = array_filter($_test_filters['query_vars'] ?? [], function ($filter) use ($router) {
            return $filter['callback'] === [$router, 'registerQueryVars'];
        });
        $this->assertNotEmpty($queryVarsFilters, 'registerQueryVars should be hooked to query_vars');

        // Check template_redirect action registered
        $templateHooks = array_filter($_test_actions['template_redirect'] ?? [], function ($action) use ($router) {
            return $action['callback'] === [$router, 'handleRequest'];
        });
        $this->assertNotEmpty($templateHooks, 'handleRequest should be hooked to template_redirect');
    }

    public function test_supported_actions_are_recognized(): void
    {
        $router = new PlatformRouter();

        // Test that valid actions don't throw invalid action error
        // They may fail for other reasons (missing services) but should be recognized
        $validActions = ['launch', 'auth', 'deep-link-return', 'grades'];

        foreach ($validActions as $action) {
            global $_test_query_vars, $_test_wp_die_called, $_test_container;
            $_test_query_vars['lti_platform_action'] = $action;
            $_test_wp_die_called = null;

            // Mock the services that would be called
            $_test_container = [];

            try {
                $router->handleRequest();
            } catch (\RuntimeException $e) {
                // Should not be "Invalid platform action"
                $this->assertStringNotContainsString(
                    'Invalid platform action',
                    $e->getMessage(),
                    "Action '$action' should be recognized as valid"
                );
            }
        }

        $this->assertTrue(true, 'All valid actions are recognized');
    }
}
