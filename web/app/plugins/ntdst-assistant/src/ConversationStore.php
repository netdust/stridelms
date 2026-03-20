<?php
declare(strict_types=1);

namespace NtdstAssistant;

class ConversationStore implements \NTDST_Service_Meta
{
    private const TTL = HOUR_IN_SECONDS;
    private const CONV_PREFIX = 'ntdst_assistant_conv_';
    private const PENDING_PREFIX = 'ntdst_assistant_pending_';

    public static function metadata(): array
    {
        return [
            'name' => 'Assistant Conversation Store',
            'description' => 'Server-side message log per admin user',
            'priority' => 15,
        ];
    }

    public function get(int $userId): array
    {
        $messages = get_transient(self::CONV_PREFIX . $userId);
        return is_array($messages) ? $messages : [];
    }

    public function append(int $userId, array $message): void
    {
        if (($message['role'] ?? '') === 'user') {
            $this->clearPending($userId);
        }

        $messages = $this->get($userId);
        $messages[] = $message;
        set_transient(self::CONV_PREFIX . $userId, $messages, self::TTL);
    }

    public function clear(int $userId): void
    {
        delete_transient(self::CONV_PREFIX . $userId);
        $this->clearPending($userId);
    }

    public function setPending(int $userId, array $pending): void
    {
        set_transient(self::PENDING_PREFIX . $userId, $pending, self::TTL);
    }

    public function getPending(int $userId): ?array
    {
        $pending = get_transient(self::PENDING_PREFIX . $userId);
        return is_array($pending) ? $pending : null;
    }

    public function clearPending(int $userId): void
    {
        delete_transient(self::PENDING_PREFIX . $userId);
    }
}
