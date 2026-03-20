<?php
declare(strict_types=1);

use NtdstAssistant\Contracts\ClaudeClientInterface;
use NtdstAssistant\Contracts\TransportInterface;
use NtdstAssistant\Claude\HttpClaudeClient;
use NtdstAssistant\Transport\JsonTransport;

return [
    'services' => [
        \NtdstAssistant\AssistantService::class,
        \NtdstAssistant\ConversationStore::class,
        \NtdstAssistant\SystemPrompt::class,
        \NtdstAssistant\AbilityBridge::class,
        \NtdstAssistant\ToolExecutor::class,
        \NtdstAssistant\ChatController::class,
    ],
    'bindings' => [
        ClaudeClientInterface::class => HttpClaudeClient::class,
        TransportInterface::class => JsonTransport::class,
    ],
];
