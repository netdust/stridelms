<?php
declare(strict_types=1);

namespace Stride\Tests\Unit\NtdstAssistant;

use NtdstAssistant\AbilityBridge;
use NtdstAssistant\ConversationStore;
use NtdstAssistant\Contracts\ClaudeClientInterface;
use NtdstAssistant\SystemPrompt;
use NtdstAssistant\ToolExecutor;
use Stride\Tests\TestCase;

class ToolExecutorTest extends TestCase
{
    private ConversationStore $store;
    private AbilityBridge $bridge;
    private SystemPrompt $prompt;
    private int $adminUserId = 1;

    protected function setUp(): void
    {
        parent::setUp();

        global $_test_current_user_id;
        $_test_current_user_id = $this->adminUserId;

        $this->store  = new ConversationStore();
        $this->bridge = new AbilityBridge($this->store);
        $this->prompt = new SystemPrompt();
    }

    // ---------------------------------------------------------------
    // 1. Text-only response returns directly
    // ---------------------------------------------------------------

    public function testTextOnlyResponseReturnsDirectly(): void
    {
        $client = $this->createMock(ClaudeClientInterface::class);
        $client->expects($this->once())
            ->method('send')
            ->willReturn([
                'content' => [
                    ['type' => 'text', 'text' => 'Hello, I can help with that.'],
                ],
            ]);

        $executor = new ToolExecutor($client, $this->bridge, $this->store, $this->prompt);
        $result = $executor->run('What can you do?', $this->adminUserId);

        $this->assertSame('response', $result['type']);
        $this->assertSame('Hello, I can help with that.', $result['text']);
    }

    // ---------------------------------------------------------------
    // 2. Read tool executes and loops
    // ---------------------------------------------------------------

    public function testReadToolExecutesAndLoops(): void
    {
        // Register a readonly ability
        wp_register_ability('test/list-items', [
            'label'       => 'List Items',
            'description' => 'Returns items',
            'category'    => 'test',
            'input_schema' => ['type' => 'object', 'properties' => new \stdClass()],
            'meta' => [
                'show_in_rest' => true,
                'readonly'     => true,
            ],
            'execute_callback' => fn($input) => ['items' => ['a', 'b']],
        ]);

        $client = $this->createMock(ClaudeClientInterface::class);
        $client->expects($this->exactly(2))
            ->method('send')
            ->willReturnOnConsecutiveCalls(
                // First call: Claude returns a tool_use
                [
                    'content' => [
                        ['type' => 'text', 'text' => 'Let me look that up.'],
                        [
                            'type'  => 'tool_use',
                            'id'    => 'toolu_01',
                            'name'  => 'test__list-items',
                            'input' => [],
                        ],
                    ],
                ],
                // Second call: Claude returns final text after seeing tool result
                [
                    'content' => [
                        ['type' => 'text', 'text' => 'Here are the items: a, b.'],
                    ],
                ],
            );

        $executor = new ToolExecutor($client, $this->bridge, $this->store, $this->prompt);
        $result = $executor->run('Show me items', $this->adminUserId);

        $this->assertSame('response', $result['type']);
        $this->assertSame('Here are the items: a, b.', $result['text']);
    }

    // ---------------------------------------------------------------
    // 3. Write tool returns pending confirmation
    // ---------------------------------------------------------------

    public function testWriteToolReturnsPendingConfirmation(): void
    {
        wp_register_ability('test/delete-item', [
            'label'       => 'Delete Item',
            'description' => 'Deletes an item',
            'category'    => 'test',
            'input_schema' => [
                'type' => 'object',
                'properties' => ['id' => ['type' => 'integer']],
            ],
            'meta' => [
                'show_in_rest' => true,
                'readonly'     => false,
            ],
            'execute_callback' => fn($input) => ['deleted' => true],
        ]);

        $client = $this->createMock(ClaudeClientInterface::class);
        $client->expects($this->once())
            ->method('send')
            ->willReturn([
                'content' => [
                    ['type' => 'text', 'text' => 'I will delete that for you.'],
                    [
                        'type'  => 'tool_use',
                        'id'    => 'toolu_02',
                        'name'  => 'test__delete-item',
                        'input' => ['id' => 7],
                    ],
                ],
            ]);

        $executor = new ToolExecutor($client, $this->bridge, $this->store, $this->prompt);
        $result = $executor->run('Delete item 7', $this->adminUserId);

        $this->assertSame('confirmation', $result['type']);
        $this->assertArrayHasKey('confirm_token', $result);
        $this->assertNotEmpty($result['confirm_token']);
        $this->assertArrayHasKey('summary', $result);
        $this->assertSame('toolu_02', $result['tool_use_id']);
    }

    // ---------------------------------------------------------------
    // 4. Max iterations returns error
    // ---------------------------------------------------------------

    public function testMaxIterationsReturnsError(): void
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
            'execute_callback' => fn($input) => ['data' => 'test'],
        ]);

        $client = $this->createMock(ClaudeClientInterface::class);
        // Always return a tool_use to force max iterations
        $client->method('send')
            ->willReturn([
                'content' => [
                    [
                        'type'  => 'tool_use',
                        'id'    => 'toolu_loop',
                        'name'  => 'test__read-data',
                        'input' => [],
                    ],
                ],
            ]);

        $executor = new ToolExecutor($client, $this->bridge, $this->store, $this->prompt);
        $result = $executor->run('Do something', $this->adminUserId);

        $this->assertSame('error', $result['type']);
        $this->assertStringContainsString('iteraties', $result['text']);
    }

    // ---------------------------------------------------------------
    // 5. Claude API error returns error
    // ---------------------------------------------------------------

    public function testClaudeApiErrorReturnsError(): void
    {
        $client = $this->createMock(ClaudeClientInterface::class);
        $client->method('send')
            ->willThrowException(new \RuntimeException('Unauthorized', 401));

        $executor = new ToolExecutor($client, $this->bridge, $this->store, $this->prompt);
        $result = $executor->run('Hello', $this->adminUserId);

        $this->assertSame('error', $result['type']);
        $this->assertNotEmpty($result['text']);
    }

    public function testClaudeRateLimitErrorReturnsError(): void
    {
        $client = $this->createMock(ClaudeClientInterface::class);
        $client->method('send')
            ->willThrowException(new \RuntimeException('Rate limited', 429));

        $executor = new ToolExecutor($client, $this->bridge, $this->store, $this->prompt);
        $result = $executor->run('Hello', $this->adminUserId);

        $this->assertSame('error', $result['type']);
        $this->assertStringContainsString('verzoeken', $result['text']);
    }

    public function testClaudeGenericErrorReturnsError(): void
    {
        $client = $this->createMock(ClaudeClientInterface::class);
        $client->method('send')
            ->willThrowException(new \RuntimeException('Server error', 500));

        $executor = new ToolExecutor($client, $this->bridge, $this->store, $this->prompt);
        $result = $executor->run('Hello', $this->adminUserId);

        $this->assertSame('error', $result['type']);
        $this->assertStringContainsString('reageert niet', $result['text']);
    }

    // ---------------------------------------------------------------
    // 6. Messages appended to conversation store
    // ---------------------------------------------------------------

    public function testMessagesAppendedToConversationStore(): void
    {
        $client = $this->createMock(ClaudeClientInterface::class);
        $client->method('send')
            ->willReturn([
                'content' => [
                    ['type' => 'text', 'text' => 'Sure thing.'],
                ],
            ]);

        $executor = new ToolExecutor($client, $this->bridge, $this->store, $this->prompt);
        $executor->run('Help me', $this->adminUserId);

        $messages = $this->store->get($this->adminUserId);

        // Should have user message + assistant response
        $this->assertCount(2, $messages);
        $this->assertSame('user', $messages[0]['role']);
        $this->assertSame('Help me', $messages[0]['content']);
        $this->assertSame('assistant', $messages[1]['role']);
    }

    // ---------------------------------------------------------------
    // 7. runConfirmed resumes conversation after confirmation
    // ---------------------------------------------------------------

    public function testRunConfirmedExecutesAndReturnsResponse(): void
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

        // Seed the conversation store FIRST (appending user message clears pending)
        $this->store->append($this->adminUserId, ['role' => 'user', 'content' => 'Write my data']);
        $this->store->append($this->adminUserId, [
            'role'    => 'assistant',
            'content' => [
                ['type' => 'text', 'text' => 'I will write that.'],
                ['type' => 'tool_use', 'id' => 'toolu_03', 'name' => 'test__write-data', 'input' => ['key' => 'val']],
            ],
        ]);

        // Step 1: Get pending confirmation via bridge (after seeding, so user append doesn't clear it)
        $pendingResult = $this->bridge->execute('test/write-data', ['key' => 'val']);
        $this->assertSame('pending_confirmation', $pendingResult['status']);

        $token = $pendingResult['confirm_token'];

        // Step 2: Mock Claude to respond after seeing tool_result
        $client = $this->createMock(ClaudeClientInterface::class);
        $client->expects($this->once())
            ->method('send')
            ->willReturn([
                'content' => [
                    ['type' => 'text', 'text' => 'Done! Data has been written.'],
                ],
            ]);

        $executor = new ToolExecutor($client, $this->bridge, $this->store, $this->prompt);
        $result = $executor->runConfirmed($token, $this->adminUserId, 'toolu_03');

        $this->assertTrue($executed, 'Write ability should have been executed');
        $this->assertSame('response', $result['type']);
        $this->assertSame('Done! Data has been written.', $result['text']);
    }

    // ---------------------------------------------------------------
    // 8. runCancelled resumes conversation with cancellation
    // ---------------------------------------------------------------

    public function testRunCancelledReturnsResponse(): void
    {
        $client = $this->createMock(ClaudeClientInterface::class);
        $client->expects($this->once())
            ->method('send')
            ->willReturn([
                'content' => [
                    ['type' => 'text', 'text' => 'OK, action cancelled.'],
                ],
            ]);

        // Seed the conversation
        $this->store->append($this->adminUserId, ['role' => 'user', 'content' => 'Delete item 7']);
        $this->store->append($this->adminUserId, [
            'role'    => 'assistant',
            'content' => [
                ['type' => 'tool_use', 'id' => 'toolu_04', 'name' => 'test__delete-item', 'input' => ['id' => 7]],
            ],
        ]);

        $executor = new ToolExecutor($client, $this->bridge, $this->store, $this->prompt);
        $result = $executor->runCancelled('toolu_04', $this->adminUserId);

        $this->assertSame('response', $result['type']);
        $this->assertSame('OK, action cancelled.', $result['text']);
    }

    // ---------------------------------------------------------------
    // 9. Unprocessed tool_use blocks get error results
    // ---------------------------------------------------------------

    public function testUnprocessedToolUseBlocksGetErrorResults(): void
    {
        // Register a write ability (first) and a read ability (second)
        wp_register_ability('test/write-action', [
            'label'       => 'Write Action',
            'description' => 'Writes something',
            'category'    => 'test',
            'input_schema' => ['type' => 'object', 'properties' => new \stdClass()],
            'meta' => [
                'show_in_rest' => true,
                'readonly'     => false,
            ],
            'execute_callback' => fn($input) => ['done' => true],
        ]);

        wp_register_ability('test/read-after', [
            'label'       => 'Read After',
            'description' => 'Reads after write',
            'category'    => 'test',
            'input_schema' => ['type' => 'object', 'properties' => new \stdClass()],
            'meta' => [
                'show_in_rest' => true,
                'readonly'     => true,
            ],
            'execute_callback' => fn($input) => ['data' => 'test'],
        ]);

        $client = $this->createMock(ClaudeClientInterface::class);
        $client->expects($this->once())
            ->method('send')
            ->willReturn([
                'content' => [
                    [
                        'type'  => 'tool_use',
                        'id'    => 'toolu_write',
                        'name'  => 'test__write-action',
                        'input' => [],
                    ],
                    [
                        'type'  => 'tool_use',
                        'id'    => 'toolu_read_after',
                        'name'  => 'test__read-after',
                        'input' => [],
                    ],
                ],
            ]);

        $executor = new ToolExecutor($client, $this->bridge, $this->store, $this->prompt);
        $result = $executor->run('Do both things', $this->adminUserId);

        // Write ability should trigger confirmation, second tool should be unprocessed
        $this->assertSame('confirmation', $result['type']);

        // Verify that tool_result messages were stored for BOTH tool_use blocks
        $messages = $this->store->get($this->adminUserId);
        $toolResultMessages = array_filter($messages, fn($m) => $m['role'] === 'user');

        // Find the tool_result entries in the last user message
        $lastUserMsg = null;
        foreach (array_reverse($messages) as $msg) {
            if ($msg['role'] === 'user' && is_array($msg['content'] ?? null)) {
                $lastUserMsg = $msg;
                break;
            }
        }

        $this->assertNotNull($lastUserMsg, 'Should have a user message with tool_result content blocks');

        $toolResultIds = array_map(
            fn($block) => $block['tool_use_id'],
            array_filter($lastUserMsg['content'], fn($b) => $b['type'] === 'tool_result')
        );

        $this->assertContains('toolu_write', $toolResultIds);
        $this->assertContains('toolu_read_after', $toolResultIds);
    }

    // ---------------------------------------------------------------
    // 10. Metadata interface
    // ---------------------------------------------------------------

    public function testImplementsServiceMetaInterface(): void
    {
        $client = $this->createMock(ClaudeClientInterface::class);
        $executor = new ToolExecutor($client, $this->bridge, $this->store, $this->prompt);

        $this->assertInstanceOf(\NTDST_Service_Meta::class, $executor);

        $meta = ToolExecutor::metadata();
        $this->assertArrayHasKey('name', $meta);
        $this->assertArrayHasKey('description', $meta);
        $this->assertArrayHasKey('priority', $meta);
    }
}
