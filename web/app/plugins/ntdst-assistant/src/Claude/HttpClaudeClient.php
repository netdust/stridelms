<?php
declare(strict_types=1);

namespace NtdstAssistant\Claude;

use NtdstAssistant\Contracts\ClaudeClientInterface;

class HttpClaudeClient implements ClaudeClientInterface
{
    private const API_URL = 'https://api.anthropic.com/v1/messages';
    private const TIMEOUT = 120;

    public function send(array $messages, array $tools, string $systemPrompt): array
    {
        $apiKey = $this->getApiKey();

        if (empty($apiKey)) {
            throw new \RuntimeException('API key not configured.');
        }

        $model = get_option('ntdst_assistant_model', 'claude-sonnet-4-6');
        $maxTokens = (int) get_option('ntdst_assistant_max_tokens', 4096);

        $body = [
            'model' => $model,
            'max_tokens' => $maxTokens,
            'system' => $systemPrompt,
            'messages' => $messages,
        ];

        if (!empty($tools)) {
            $body['tools'] = $tools;
        }

        $response = wp_remote_post(self::API_URL, [
            'timeout' => self::TIMEOUT,
            'headers' => [
                'Content-Type' => 'application/json',
                'x-api-key' => $apiKey,
                'anthropic-version' => '2023-06-01',
            ],
            'body' => json_encode($body),
        ]);

        if (is_wp_error($response)) {
            throw new \RuntimeException($response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        $responseBody = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 200) {
            $errorMsg = $responseBody['error']['message'] ?? "HTTP {$code}";
            throw new \RuntimeException("{$code}: {$errorMsg}");
        }

        return $responseBody;
    }

    private function getApiKey(): string
    {
        if (defined('NTDST_ASSISTANT_API_KEY')) {
            return NTDST_ASSISTANT_API_KEY;
        }

        return get_option('ntdst_assistant_api_key', '');
    }
}
