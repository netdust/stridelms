<?php
declare(strict_types=1);

namespace NtdstAssistant\Contracts;

interface TransportInterface
{
    /**
     * Deliver ToolExecutor result to the browser.
     *
     * @param array $result {type: response|confirmation|error, ...}
     */
    public function deliver(array $result): void;
}
