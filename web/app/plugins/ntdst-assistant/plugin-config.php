<?php
declare(strict_types=1);

use NtdstAssistant\Contracts\ClaudeClientInterface;
use NtdstAssistant\Contracts\TransportInterface;
use NtdstAssistant\Claude\SDKClaudeClient;
use NtdstAssistant\Claude\HttpClaudeClient;
use NtdstAssistant\Transport\JsonTransport;

return [
    'services' => [
        // Services will be added as they are implemented
    ],
    'bindings' => [
        ClaudeClientInterface::class => fn() => (
            defined('WP_ENV') && WP_ENV !== 'production' && class_exists(SDKClaudeClient::class)
                ? ntdst_make(SDKClaudeClient::class)
                : ntdst_make(HttpClaudeClient::class)
        ),
        TransportInterface::class => JsonTransport::class,
    ],
];
