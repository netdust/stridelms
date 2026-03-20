<?php
declare(strict_types=1);

namespace Stride\Tests\Unit\NtdstAssistant;

use NtdstAssistant\AbilityBridge;
use NtdstAssistant\ConversationStore;
use Stride\Tests\TestCase;

class AbilityBridgeTest extends TestCase
{
    private AbilityBridge $bridge;
    private ConversationStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        global $_test_current_user_id;
        $_test_current_user_id = 1;

        $this->store = new ConversationStore();
        $this->bridge = new AbilityBridge($this->store);
    }

    // ---------------------------------------------------------------
    // Tool Definitions
    // ---------------------------------------------------------------

    public function testGetToolDefinitionsReturnsClaudeFormat(): void
    {
        wp_register_ability('test/list-items', [
            'label'       => 'List Items',
            'description' => 'Returns a list of items',
            'category'    => 'test',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'page' => ['type' => 'integer', 'description' => 'Page number'],
                ],
            ],
            'meta' => [
                'show_in_rest' => true,
                'readonly'     => true,
            ],
            'execute_callback' => fn($input) => ['items' => []],
        ]);

        $tools = $this->bridge->getToolDefinitions();

        $this->assertCount(1, $tools);

        $tool = $tools[0];
        $this->assertSame('test__list-items', $tool['name']);
        $this->assertSame('Returns a list of items', $tool['description']);
        $this->assertArrayHasKey('input_schema', $tool);
        $this->assertSame('object', $tool['input_schema']['type']);
        $this->assertArrayHasKey('page', $tool['input_schema']['properties']);
    }

    public function testNonRestAbilitiesAreExcluded(): void
    {
        wp_register_ability('test/internal-only', [
            'label'       => 'Internal',
            'description' => 'Not for REST',
            'category'    => 'test',
            'meta' => [
                'show_in_rest' => false,
            ],
            'execute_callback' => fn($input) => true,
        ]);

        wp_register_ability('test/visible', [
            'label'       => 'Visible',
            'description' => 'For REST',
            'category'    => 'test',
            'meta' => [
                'show_in_rest' => true,
                'readonly'     => true,
            ],
            'execute_callback' => fn($input) => true,
        ]);

        $tools = $this->bridge->getToolDefinitions();

        $this->assertCount(1, $tools);
        $this->assertSame('test__visible', $tools[0]['name']);
    }

    public function testToolsFilterIsApplied(): void
    {
        add_filter('ntdst_assistant/tools', function (array $tools): array {
            $tools[] = [
                'name'         => 'custom__injected-tool',
                'description'  => 'Injected by filter',
                'input_schema' => ['type' => 'object', 'properties' => new \stdClass()],
            ];
            return $tools;
        });

        $tools = $this->bridge->getToolDefinitions();

        $names = array_column($tools, 'name');
        $this->assertContains('custom__injected-tool', $names);
    }

    // ---------------------------------------------------------------
    // Name Mapping
    // ---------------------------------------------------------------

    public function testNameMappingConvertsSlashToDoubleUnderscore(): void
    {
        // WP name → Claude name
        $this->assertSame('stride__get-editions', AbilityBridge::toClaudeName('stride/get-editions'));

        // Claude name → WP name
        $this->assertSame('stride/get-editions', AbilityBridge::toWpName('stride__get-editions'));
    }

    // ---------------------------------------------------------------
    // Execute — readonly vs write
    // ---------------------------------------------------------------

    public function testReadonlyAbilityExecutesImmediately(): void
    {
        wp_register_ability('test/read-data', [
            'label'       => 'Read Data',
            'description' => 'Reads data',
            'category'    => 'test',
            'input_schema' => ['type' => 'object', 'properties' => new \stdClass()],
            'meta' => [
                'show_in_rest' => true,
                'readonly'     => true,
            ],
            'execute_callback' => fn($input) => ['count' => 42],
        ]);

        $result = $this->bridge->execute('test/read-data', []);

        $this->assertSame('executed', $result['status']);
        $this->assertSame(['count' => 42], $result['result']);
    }

    public function testWriteAbilityReturnsPendingConfirmation(): void
    {
        wp_register_ability('test/delete-item', [
            'label'       => 'Delete Item',
            'description' => 'Deletes an item',
            'category'    => 'test',
            'input_schema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]],
            'meta' => [
                'show_in_rest' => true,
                'readonly'     => false,
            ],
            'execute_callback' => fn($input) => ['deleted' => true],
        ]);

        $result = $this->bridge->execute('test/delete-item', ['id' => 7]);

        $this->assertSame('pending_confirmation', $result['status']);
        $this->assertArrayHasKey('confirm_token', $result);
        $this->assertNotEmpty($result['confirm_token']);
        $this->assertArrayHasKey('summary', $result);
    }

    // ---------------------------------------------------------------
    // Execute Confirmed — token validation
    // ---------------------------------------------------------------

    public function testExecuteConfirmedWithValidToken(): void
    {
        $executed = false;

        wp_register_ability('test/write-data', [
            'label'       => 'Write Data',
            'description' => 'Writes data',
            'category'    => 'test',
            'input_schema' => ['type' => 'object', 'properties' => new \stdClass()],
            'meta' => [
                'show_in_rest' => true,
                'readonly'     => false,
            ],
            'execute_callback' => function ($input) use (&$executed) {
                $executed = true;
                return ['written' => true];
            },
        ]);

        // First execute to get pending confirmation
        $pending = $this->bridge->execute('test/write-data', ['key' => 'val']);
        $this->assertSame('pending_confirmation', $pending['status']);

        // Now confirm with valid token
        $result = $this->bridge->executeConfirmed(1, $pending['confirm_token']);

        $this->assertTrue($executed, 'Execute callback should have been called');
        $this->assertSame('executed', $result['status']);
        $this->assertSame(['written' => true], $result['result']);
    }

    public function testExecuteConfirmedWithInvalidTokenReturnsError(): void
    {
        wp_register_ability('test/write-action', [
            'label'       => 'Write Action',
            'description' => 'Does a write',
            'category'    => 'test',
            'input_schema' => ['type' => 'object', 'properties' => new \stdClass()],
            'meta' => [
                'show_in_rest' => true,
                'readonly'     => false,
            ],
            'execute_callback' => fn($input) => ['done' => true],
        ]);

        // Create a pending confirmation
        $this->bridge->execute('test/write-action', ['x' => 1]);

        // Try to confirm with an invalid token
        $result = $this->bridge->executeConfirmed(1, 'totally-invalid-token');

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('invalid_token', $result->get_error_code());
    }

    // ---------------------------------------------------------------
    // Hooks — before/after execute
    // ---------------------------------------------------------------

    public function testBeforeAfterExecuteHooksFire(): void
    {
        wp_register_ability('test/hook-test', [
            'label'       => 'Hook Test',
            'description' => 'Tests hooks',
            'category'    => 'test',
            'input_schema' => ['type' => 'object', 'properties' => new \stdClass()],
            'meta' => [
                'show_in_rest' => true,
                'readonly'     => true,
            ],
            'execute_callback' => fn($input) => ['ok' => true],
        ]);

        $this->bridge->execute('test/hook-test', ['foo' => 'bar']);

        $this->assertActionFired('ntdst_assistant/before_execute', 1);
        $this->assertActionFired('ntdst_assistant/after_execute', 1);

        // Verify args passed to before hook
        $beforeArgs = $this->getActionArgs('ntdst_assistant/before_execute');
        $this->assertSame('test/hook-test', $beforeArgs[0]);
        $this->assertSame(['foo' => 'bar'], $beforeArgs[1]);

        // Verify args passed to after hook
        $afterArgs = $this->getActionArgs('ntdst_assistant/after_execute');
        $this->assertSame('test/hook-test', $afterArgs[0]);
        $this->assertSame(['ok' => true], $afterArgs[2]);
    }
}
