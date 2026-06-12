<?php
declare(strict_types=1);

use NtdstAssistant\Contracts\ClaudeClientInterface;
use NtdstAssistant\Contracts\TransportInterface;
use NtdstAssistant\Claude\HttpClaudeClient;
use NtdstAssistant\Transport\JsonTransport;

return [
    // Only classes that hook into WordPress at boot time.
    // Other classes (ToolExecutor, AbilityBridge, SystemPrompt,
    // ConversationStore, ExportService) are resolved lazily
    // via autowiring when injected as constructor dependencies.
    'services' => [
        \NtdstAssistant\AssistantService::class,
        \NtdstAssistant\ChatController::class,
    ],
    'bindings' => [
        ClaudeClientInterface::class => HttpClaudeClient::class,
        TransportInterface::class => JsonTransport::class,
    ],
];
