<?php
declare(strict_types=1);

namespace NtdstAssistant\Transport;

use NtdstAssistant\Contracts\TransportInterface;

class SseTransport implements TransportInterface
{
    public function deliver(array $result): void
    {
        throw new \RuntimeException('SSE transport not yet implemented.');
    }
}
