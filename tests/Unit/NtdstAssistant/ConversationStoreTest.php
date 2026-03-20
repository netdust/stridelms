<?php
declare(strict_types=1);

namespace Stride\Tests\Unit\NtdstAssistant;

use NtdstAssistant\ConversationStore;
use Stride\Tests\TestCase;

class ConversationStoreTest extends TestCase
{
    private ConversationStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = new ConversationStore();
    }

    public function testGetReturnsEmptyArrayForNewUser(): void
    {
        $this->assertSame([], $this->store->get(1));
    }

    public function testAppendAddsMessageToConversation(): void
    {
        $this->store->append(1, ['role' => 'user', 'content' => 'Hello']);
        $messages = $this->store->get(1);

        $this->assertCount(1, $messages);
        $this->assertSame('user', $messages[0]['role']);
        $this->assertSame('Hello', $messages[0]['content']);
    }

    public function testAppendAccumulatesMessages(): void
    {
        $this->store->append(1, ['role' => 'user', 'content' => 'Hello']);
        $this->store->append(1, ['role' => 'assistant', 'content' => 'Hi']);

        $this->assertCount(2, $this->store->get(1));
    }

    public function testClearRemovesAllMessages(): void
    {
        $this->store->append(1, ['role' => 'user', 'content' => 'Hello']);
        $this->store->clear(1);

        $this->assertSame([], $this->store->get(1));
    }

    public function testConversationsAreIsolatedPerUser(): void
    {
        $this->store->append(1, ['role' => 'user', 'content' => 'User 1']);
        $this->store->append(2, ['role' => 'user', 'content' => 'User 2']);

        $this->assertCount(1, $this->store->get(1));
        $this->assertSame('User 1', $this->store->get(1)[0]['content']);
    }

    public function testSetPendingStoresPendingAction(): void
    {
        $pending = [
            'ability' => 'stride/enroll-user',
            'input' => ['user_id' => 42, 'edition_id' => 108],
            'token' => 'abc123',
        ];

        $this->store->setPending(1, $pending);
        $result = $this->store->getPending(1);

        $this->assertSame('stride/enroll-user', $result['ability']);
        $this->assertSame('abc123', $result['token']);
    }

    public function testGetPendingReturnsNullWhenNoPending(): void
    {
        $this->assertNull($this->store->getPending(1));
    }

    public function testClearPendingRemovesPendingAction(): void
    {
        $this->store->setPending(1, ['ability' => 'test', 'input' => [], 'token' => 'x']);
        $this->store->clearPending(1);

        $this->assertNull($this->store->getPending(1));
    }

    public function testNewChatMessageClearsPendingState(): void
    {
        $this->store->setPending(1, ['ability' => 'test', 'input' => [], 'token' => 'x']);
        $this->store->append(1, ['role' => 'user', 'content' => 'New message']);

        $this->assertNull($this->store->getPending(1));
    }

    public function testReplaceOverwritesEntireConversation(): void
    {
        $this->store->append(1, ['role' => 'user', 'content' => 'Old message']);
        $this->store->append(1, ['role' => 'assistant', 'content' => 'Old reply']);

        $newMessages = [
            ['role' => 'user', 'content' => 'New message'],
            ['role' => 'assistant', 'content' => 'New reply'],
            ['role' => 'user', 'content' => 'Follow-up'],
        ];

        $this->store->replace(1, $newMessages);
        $messages = $this->store->get(1);

        $this->assertCount(3, $messages);
        $this->assertSame('New message', $messages[0]['content']);
        $this->assertSame('Follow-up', $messages[2]['content']);
    }

    public function testReplaceTruncatesWhenOverMaxMessages(): void
    {
        // Build a messages array exceeding the max (30)
        $messages = [];
        for ($i = 0; $i < 35; $i++) {
            $messages[] = ['role' => 'user', 'content' => "Message {$i}"];
        }

        $this->store->replace(1, $messages);
        $stored = $this->store->get(1);

        $this->assertCount(30, $stored);
        // Should keep the last 30 messages (5..34)
        $this->assertSame('Message 5', $stored[0]['content']);
        $this->assertSame('Message 34', $stored[29]['content']);
    }
}
