<?php
declare(strict_types=1);

namespace NtdstAssistant\Claude;

use Anthropic\Client;
use Anthropic\Messages\TextBlock;
use Anthropic\Messages\ToolUseBlock;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\RateLimitException;
use Anthropic\Core\Exceptions\APITimeoutException;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
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

        $model     = get_option('ntdst_assistant_model', 'claude-sonnet-4-6');
        $maxTokens = (int) get_option('ntdst_assistant_max_tokens', 4096);

        try {
            $client = new Client(apiKey: $apiKey);

            $message = $client->messages->create(
                maxTokens: $maxTokens,
                messages: $messages,
                model: $model,
                system: $systemPrompt,
                tools: !empty($tools) ? $tools : null,
            );
        } catch (AuthenticationException $e) {
            throw new \RuntimeException('401: ' . $e->getMessage(), 0, $e);
        } catch (RateLimitException $e) {
            throw new \RuntimeException('429: ' . $e->getMessage(), 0, $e);
        } catch (APITimeoutException $e) {
            throw new \RuntimeException('Request timed out: ' . $e->getMessage(), 0, $e);
        } catch (APIConnectionException $e) {
            throw new \RuntimeException('Connection error: ' . $e->getMessage(), 0, $e);
        } catch (APIStatusException $e) {
            $status = $e->status ?? 0;
            throw new \RuntimeException("{$status}: " . $e->getMessage(), 0, $e);
        }

        return [
            'content' => $this->convertContentBlocks($message->content),
        ];
    }

    /**
     * Convert SDK content block objects to plain arrays matching the HttpClaudeClient format.
     *
     * @param array<mixed> $blocks
     * @return array<array<string, mixed>>
     */
    private function convertContentBlocks(array $blocks): array
    {
        $result = [];

        foreach ($blocks as $block) {
            if ($block instanceof TextBlock) {
                $result[] = [
                    'type' => 'text',
                    'text' => $block->text,
                ];
            } elseif ($block instanceof ToolUseBlock) {
                $result[] = [
                    'type'  => 'tool_use',
                    'id'    => $block->id,
                    'name'  => $block->name,
                    'input' => $block->input,
                ];
            }
            // Unknown block types are silently skipped — forward-compatible.
        }

        return $result;
    }
}
