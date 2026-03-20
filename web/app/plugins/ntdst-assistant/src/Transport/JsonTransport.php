<?php
declare(strict_types=1);

namespace NtdstAssistant\Transport;

use NtdstAssistant\Contracts\TransportInterface;
use Parsedown;

class JsonTransport implements TransportInterface
{
    public function deliver(array $result): void
    {
        if ($result['type'] === 'response' && isset($result['content'])) {
            $parsedown = new Parsedown();
            $parsedown->setMarkupEscaped(true);
            $html = wp_kses_post($parsedown->text($result['content']));
            $result['html'] = $html;
        }

        wp_send_json($result);
    }
}
