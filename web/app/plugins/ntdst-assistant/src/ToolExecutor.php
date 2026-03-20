<?php
declare(strict_types=1);

namespace NtdstAssistant;

use NtdstAssistant\Contracts\ClaudeClientInterface;

class ToolExecutor implements \NTDST_Service_Meta
{
    private const MAX_ITERATIONS = 5;
    private const TOTAL_TIMEOUT  = 120;

    public static function metadata(): array
    {
        return [
            'name'        => 'Assistant Tool Executor',
            'description' => 'Orchestrates the Claude conversation loop with tool execution and confirmation flow',
            'priority'    => 20,
        ];
    }

    public function __construct(
        private readonly ClaudeClientInterface $client,
        private readonly AbilityBridge $bridge,
        private readonly ConversationStore $store,
        private readonly SystemPrompt $prompt,
    ) {}

    // ---------------------------------------------------------------
    // Entry point: new user message
    // ---------------------------------------------------------------

    /**
     * Run a conversation turn starting from a user message.
     *
     * @return array{type: 'response'|'confirmation'|'error', text?: string, confirm_token?: string, summary?: string, tool_use_id?: string}
     */
    public function run(string $userMessage, int $adminUserId): array
    {
        $this->store->append($adminUserId, [
            'role'    => 'user',
            'content' => $userMessage,
        ]);

        return $this->loop($adminUserId);
    }

    // ---------------------------------------------------------------
    // Entry point: resume after confirmation
    // ---------------------------------------------------------------

    /**
     * Resume conversation after user confirmed a write action.
     *
     * @return array{type: 'response'|'error', text?: string}
     */
    public function runConfirmed(string $token, int $adminUserId, string $toolUseId): array
    {
        // Get pending state (contains assistant_content with the write tool_use)
        $pending = $this->store->getPending($adminUserId);

        $execResult = $this->bridge->executeConfirmed($adminUserId, $token);

        if ($execResult instanceof \WP_Error) {
            return [
                'type' => 'error',
                'text' => $execResult->get_error_message(),
            ];
        }

        // Reconstruct: add the assistant message with ONLY the write tool_use block
        // (read tool_uses were already stored in the filtered message during run())
        $writeToolUse = null;
        foreach (($pending['assistant_content'] ?? []) as $block) {
            if (($block['type'] ?? '') === 'tool_use' && ($block['id'] ?? '') === $toolUseId) {
                $writeToolUse = $block;
                break;
            }
        }

        if ($writeToolUse) {
            $this->store->append($adminUserId, [
                'role'    => 'assistant',
                'content' => [$writeToolUse],
            ]);
        }

        // Append the real tool_result
        $this->store->append($adminUserId, [
            'role'    => 'user',
            'content' => [
                [
                    'type'        => 'tool_result',
                    'tool_use_id' => $toolUseId,
                    'content'     => json_encode($execResult['result'] ?? $execResult),
                ],
            ],
        ]);

        return $this->loop($adminUserId);
    }

    // ---------------------------------------------------------------
    // Entry point: resume after cancellation
    // ---------------------------------------------------------------

    /**
     * Resume conversation after user cancelled a write action.
     *
     * @return array{type: 'response'|'error', text?: string}
     */
    public function runCancelled(string $toolUseId, int $adminUserId): array
    {
        $this->store->clearPending($adminUserId);

        $this->store->append($adminUserId, [
            'role'    => 'user',
            'content' => [
                [
                    'type'        => 'tool_result',
                    'tool_use_id' => $toolUseId,
                    'content'     => json_encode(['status' => 'cancelled', 'message' => 'Gebruiker heeft de actie geannuleerd.']),
                    'is_error'    => true,
                ],
            ],
        ]);

        return $this->loop($adminUserId);
    }

    // ---------------------------------------------------------------
    // Core conversation loop
    // ---------------------------------------------------------------

    private function loop(int $adminUserId): array
    {
        $tools        = $this->bridge->getToolDefinitions();
        $systemPrompt = $this->prompt->build();
        $startTime    = time();

        // Read conversation once — work in memory, flush on exit
        $messages = $this->store->get($adminUserId);

        for ($i = 0; $i < self::MAX_ITERATIONS; $i++) {

            // Timeout guard
            if ((time() - $startTime) >= self::TOTAL_TIMEOUT) {
                $this->store->replace($adminUserId, $messages);
                return [
                    'type' => 'error',
                    'text' => 'De assistent heeft te lang geduurd. Probeer het opnieuw.',
                ];
            }

            try {
                $response = $this->client->send(
                    $messages,
                    $tools,
                    $systemPrompt,
                );
            } catch (\RuntimeException $e) {
                $this->store->replace($adminUserId, $messages);
                return $this->mapApiError($e);
            }

            $contentBlocks = $response['content'] ?? [];
            $textBlocks    = [];
            $toolUseBlocks = [];

            foreach ($contentBlocks as $block) {
                if (($block['type'] ?? '') === 'text') {
                    $textBlocks[] = $block;
                } elseif (($block['type'] ?? '') === 'tool_use') {
                    $toolUseBlocks[] = $block;
                }
            }

            // No tool_use: store assistant message and return text
            if (empty($toolUseBlocks)) {
                $text = $this->extractText($textBlocks);

                $messages[] = ['role' => 'assistant', 'content' => $contentBlocks];
                $this->store->replace($adminUserId, $messages);

                return [
                    'type' => 'response',
                    'text' => $text,
                ];
            }

            // Process tool_use blocks before storing assistant message
            $toolResults        = [];
            $confirmationResult = null;
            $confirmationIndex  = null;

            foreach ($toolUseBlocks as $idx => $toolUse) {
                $wpName = AbilityBridge::toWpName($toolUse['name']);
                $input  = $toolUse['input'] ?? [];

                $execResult = $this->bridge->execute($wpName, $input);

                if ($execResult instanceof \WP_Error) {
                    $toolResults[] = [
                        'type'        => 'tool_result',
                        'tool_use_id' => $toolUse['id'],
                        'content'     => json_encode(['error' => $execResult->get_error_message()]),
                        'is_error'    => true,
                    ];
                    continue;
                }

                if ($execResult['status'] === 'pending_confirmation') {
                    $confirmationResult = $execResult;
                    $confirmationIndex  = $idx;
                    break;
                }

                $toolResults[] = [
                    'type'        => 'tool_result',
                    'tool_use_id' => $toolUse['id'],
                    'content'     => json_encode($execResult['result']),
                ];
            }

            // If confirmation triggered: only store the content blocks that have results
            // Strip the write tool_use and anything after it from the assistant message
            if ($confirmationResult !== null) {
                $writeToolId = $toolUseBlocks[$confirmationIndex]['id'];

                // Filter assistant content: keep text blocks + only tool_use blocks that have results
                $processedToolIds = array_column($toolResults, 'tool_use_id');
                $filteredContent = array_filter($contentBlocks, function ($block) use ($processedToolIds, $writeToolId) {
                    if (($block['type'] ?? '') !== 'tool_use') {
                        return true; // keep text blocks
                    }
                    return in_array($block['id'] ?? '', $processedToolIds, true);
                });

                // Append filtered assistant message (only contains resolved tool_uses)
                if (!empty($filteredContent)) {
                    $messages[] = ['role' => 'assistant', 'content' => array_values($filteredContent)];
                }

                // Append results for resolved read tools only
                if (!empty($toolResults)) {
                    $messages[] = ['role' => 'user', 'content' => $toolResults];
                }

                // Flush to store
                $this->store->replace($adminUserId, $messages);

                $toolUseId = $toolUseBlocks[$confirmationIndex]['id'];

                // Store tool_use_id + the original assistant content in pending
                // so runConfirmed() can reconstruct the full message
                $pending = $this->store->getPending($adminUserId);
                if ($pending) {
                    $pending['tool_use_id'] = $toolUseId;
                    $pending['assistant_content'] = $contentBlocks;
                    $this->store->setPending($adminUserId, $pending);
                }

                return [
                    'type'          => 'confirmation',
                    'confirm_token' => $confirmationResult['confirm_token'],
                    'summary'       => $confirmationResult['summary'],
                    'tool_use_id'   => $toolUseId,
                    'text'          => $this->extractText($textBlocks),
                ];
            }

            // No confirmation: append full assistant message + all tool results, continue loop
            $messages[] = ['role' => 'assistant', 'content' => $contentBlocks];

            if (!empty($toolResults)) {
                $messages[] = ['role' => 'user', 'content' => $toolResults];
            }
        }

        // Max iterations reached — flush and return error
        $this->store->replace($adminUserId, $messages);
        return [
            'type' => 'error',
            'text' => 'De assistent heeft het maximaal aantal iteraties bereikt. Probeer een eenvoudigere vraag.',
        ];
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    /**
     * Extract combined text from text content blocks.
     */
    private function extractText(array $textBlocks): string
    {
        $parts = [];
        foreach ($textBlocks as $block) {
            if (!empty($block['text'])) {
                $parts[] = $block['text'];
            }
        }
        return implode("\n\n", $parts);
    }

    /**
     * Map RuntimeException from the Claude client to Dutch user-facing error messages.
     *
     * @return array{type: 'error', text: string}
     */
    private function mapApiError(\RuntimeException $e): array
    {
        $msg = $e->getMessage();

        error_log('[ntdst-assistant] Claude API error: ' . $msg);

        $text = match (true) {
            str_contains($msg, '401') => 'Claude API-sleutel is ongeldig.',
            str_contains($msg, '429') => 'Te veel verzoeken. Probeer het over een minuut opnieuw.',
            str_contains($msg, '400') => 'Ongeldig verzoek naar Claude: ' . $msg,
            default                   => 'Claude reageert niet. Probeer het opnieuw. (' . $msg . ')',
        };

        return [
            'type' => 'error',
            'text' => $text,
        ];
    }
}
