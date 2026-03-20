<?php
declare(strict_types=1);

namespace NtdstAssistant\Contracts;

interface ClaudeClientInterface
{
    /**
     * Send messages to Claude with tools.
     *
     * @param array  $messages     Conversation messages [{role, content, ...}]
     * @param array  $tools        Tool definitions in Claude format
     * @param string $systemPrompt System prompt text
     * @return array Claude response: {content: [{type: text|tool_use, ...}]}
     * @throws \RuntimeException On API error (401, 429, timeout, network)
     */
    public function send(array $messages, array $tools, string $systemPrompt): array;
}
