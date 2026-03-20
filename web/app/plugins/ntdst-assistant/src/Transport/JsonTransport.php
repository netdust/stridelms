<?php
declare(strict_types=1);

namespace NtdstAssistant\Transport;

use NtdstAssistant\Contracts\TransportInterface;
use Parsedown;

class JsonTransport implements TransportInterface
{
    public function deliver(array $result): void
    {
        // Normalize: ToolExecutor uses 'text', frontend expects 'content'
        if (isset($result['text']) && !isset($result['content'])) {
            $result['content'] = $result['text'];
            unset($result['text']);
        }

        if ($result['type'] === 'response' && isset($result['content'])) {
            $parsedown = new Parsedown();
            $parsedown->setMarkupEscaped(true);
            $result['html'] = wp_kses_post($parsedown->text($result['content']));
        }

        wp_send_json($result);
    }
}
