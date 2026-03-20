<?php
declare(strict_types=1);

namespace Stride\Tests\Unit\NtdstAssistant;

use NtdstAssistant\Claude\HttpClaudeClient;
use Stride\Tests\TestCase;

class HttpClaudeClientTest extends TestCase
{
    public function testSendThrowsWhenApiKeyMissing(): void
    {
        $client = new HttpClaudeClient();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('API key');

        $client->send([], [], 'prompt');
    }

    public function testSendThrowsWhenApiKeyIsEmpty(): void
    {
        global $_test_options;
        $_test_options['ntdst_assistant_api_key'] = '';

        $client = new HttpClaudeClient();

        $this->expectException(\RuntimeException::class);

        $client->send([], [], 'prompt');
    }
}
