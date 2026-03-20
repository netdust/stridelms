<?php
declare(strict_types=1);

namespace NtdstAssistant\Claude;

use NtdstAssistant\Contracts\ClaudeClientInterface;

class SDKClaudeClient implements ClaudeClientInterface
{
    public function send(array $messages, array $tools, string $systemPrompt): array
    {
        $apiKey = defined('NTDST_ASSISTANT_API_KEY')
            ? NTDST_ASSISTANT_API_KEY
            : get_option('ntdst_assistant_api_key', '');

        if (empty($apiKey)) {
            throw new \RuntimeException('API key not configured.');
        }

        // SDK implementation — requires anthropic-ai/client-php
        // TODO: Implement when SDK is available in dev
        throw new \RuntimeException('SDK client not yet implemented. Use HttpClaudeClient.');
    }
}
