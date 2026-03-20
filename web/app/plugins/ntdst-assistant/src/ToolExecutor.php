<?php
declare(strict_types=1);

namespace NtdstAssistant;

use NtdstAssistant\Contracts\ClaudeClientInterface;

class ToolExecutor implements \NTDST_Service_Meta
{
    private const MAX_ITERATIONS = 10;
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
        $execResult = $this->bridge->executeConfirmed($adminUserId, $token);

        if ($execResult instanceof \WP_Error) {
            return [
                'type' => 'error',
                'text' => $execResult->get_error_message(),
            ];
        }

        // Append tool_result for the confirmed action
        $this->store->append($adminUserId, [
            'role'    => 'user',
            'content' => [
                [
                    'type'        => 'tool_result',
                    'tool_use_id' => $toolUseId,
                    'content'     => json_encode($execResult['result']),
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
        $tools       = $this->bridge->getToolDefinitions();
        $systemPrompt = $this->prompt->build();
        $startTime   = time();

        for ($i = 0; $i < self::MAX_ITERATIONS; $i++) {
            // Timeout guard
            if ((time() - $startTime) >= self::TOTAL_TIMEOUT) {
                return [
                    'type' => 'error',
                    'text' => 'De assistent heeft te lang geduurd. Probeer het opnieuw.',
                ];
            }

            try {
                $response = $this->client->send(
                    $this->store->get($adminUserId),
                    $tools,
                    $systemPrompt,
                );
            } catch (\RuntimeException $e) {
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

                $this->store->append($adminUserId, [
                    'role'    => 'assistant',
                    'content' => $contentBlocks,
                ]);

                return [
                    'type' => 'response',
                    'text' => $text,
                ];
            }

            // Has tool_use blocks: store full assistant content
            $this->store->append($adminUserId, [
                'role'    => 'assistant',
                'content' => $contentBlocks,
            ]);

            // Process tool_use blocks
            $toolResults        = [];
            $confirmationResult = null;
            $confirmationIndex  = null;

            foreach ($toolUseBlocks as $idx => $toolUse) {
                $wpName = AbilityBridge::toWpName($toolUse['name']);
                $input  = $toolUse['input'] ?? [];

                $execResult = $this->bridge->execute($wpName, $input);

                if ($execResult instanceof \WP_Error) {
                    // Permission denied or unknown ability
                    $toolResults[] = [
                        'type'        => 'tool_result',
                        'tool_use_id' => $toolUse['id'],
                        'content'     => json_encode(['error' => $execResult->get_error_message()]),
                        'is_error'    => true,
                    ];
                    continue;
                }

                if ($execResult['status'] === 'pending_confirmation') {
                    // Write ability: stop processing, return confirmation
                    $confirmationResult = $execResult;
                    $confirmationIndex  = $idx;

                    // Add awaiting result for this tool
                    $toolResults[] = [
                        'type'        => 'tool_result',
                        'tool_use_id' => $toolUse['id'],
                        'content'     => json_encode(['status' => 'awaiting_confirmation']),
                        'is_error'    => false,
                    ];

                    // Add error results for all remaining unprocessed tool_use blocks
                    for ($j = $idx + 1; $j < count($toolUseBlocks); $j++) {
                        $toolResults[] = [
                            'type'        => 'tool_result',
                            'tool_use_id' => $toolUseBlocks[$j]['id'],
                            'content'     => json_encode(['error' => 'Action paused pending confirmation']),
                            'is_error'    => true,
                        ];
                    }

                    break;
                }

                // Readonly ability: executed successfully
                $toolResults[] = [
                    'type'        => 'tool_result',
                    'tool_use_id' => $toolUse['id'],
                    'content'     => json_encode($execResult['result']),
                ];
            }

            // Store tool results
            if (!empty($toolResults)) {
                $this->store->append($adminUserId, [
                    'role'    => 'user',
                    'content' => $toolResults,
                ]);
            }

            // If a confirmation was triggered, store tool_use_id in pending and return
            if ($confirmationResult !== null) {
                $toolUseId = $toolUseBlocks[$confirmationIndex]['id'];

                // Add tool_use_id to the pending state so /confirm can use it
                $pending = $this->store->getPending($adminUserId);
                if ($pending) {
                    $pending['tool_use_id'] = $toolUseId;
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

            // Otherwise, loop again with tool results
        }

        // Max iterations reached
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
