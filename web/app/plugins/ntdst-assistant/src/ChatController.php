<?php
declare(strict_types=1);

namespace NtdstAssistant;

use NtdstAssistant\Contracts\TransportInterface;
use WP_REST_Request;

final class ChatController implements \NTDST_Service_Meta
{
    private const NAMESPACE = 'ntdst-assistant/v1';

    public static function metadata(): array
    {
        return [
            'name'        => 'Assistant Chat Controller',
            'description' => 'REST endpoints for AI chat',
            'admin_only'  => true,
            'priority'    => 16,
        ];
    }

    public function __construct(
        private readonly ToolExecutor $executor,
        private readonly ConversationStore $store,
        private readonly TransportInterface $transport,
    ) {
        $this->init();
    }

    private function init(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        register_rest_route(self::NAMESPACE, '/chat', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handleChat'],
            'permission_callback' => [$this, 'checkPermission'],
            'args'                => [
                'content' => [
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_textarea_field',
                ],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/confirm', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handleConfirm'],
            'permission_callback' => [$this, 'checkPermission'],
            'args'                => [
                'confirm_token' => [
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/cancel', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handleCancel'],
            'permission_callback' => [$this, 'checkPermission'],
            'args'                => [
                'confirm_token' => [
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);
    }

    public function checkPermission(): bool
    {
        $capability = get_option('ntdst_assistant_capability', 'edit_others_posts');

        $allowed = ['manage_options', 'edit_others_posts', 'stride_manage', 'stride_view'];
        if (!in_array($capability, $allowed, true)) {
            $capability = 'edit_others_posts';
        }

        return current_user_can($capability);
    }

    public function handleChat(WP_REST_Request $request): void
    {
        set_time_limit(180);

        $apiKey = defined('NTDST_ASSISTANT_API_KEY')
            ? NTDST_ASSISTANT_API_KEY
            : get_option('ntdst_assistant_api_key', '');

        if (empty($apiKey)) {
            $this->transport->deliver([
                'type' => 'error',
                'text' => 'API-sleutel is niet geconfigureerd.',
            ]);
            return;
        }

        $userId = get_current_user_id();

        // Rate limiting: 30 requests per minute per user
        $rateLimitKey = 'ntdst_assistant_rate_' . $userId;
        $requestCount = (int) get_transient($rateLimitKey);

        if ($requestCount >= 30) {
            $this->transport->deliver([
                'type' => 'error',
                'text' => 'Te veel verzoeken. Wacht even en probeer het opnieuw.',
            ]);
            return;
        }

        set_transient($rateLimitKey, $requestCount + 1, MINUTE_IN_SECONDS);

        $content = $request->get_param('content');

        try {
            $result = $this->executor->run($content, $userId);
        } catch (\Throwable $e) {
            error_log('[ntdst-assistant] Error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            $result = [
                'type' => 'error',
                'text' => 'Er ging iets mis. Probeer het opnieuw.',
            ];
        }

        $this->transport->deliver($result);
    }

    public function handleConfirm(WP_REST_Request $request): void
    {
        set_time_limit(180);

        $userId = get_current_user_id();
        $token  = $request->get_param('confirm_token');

        $pending = $this->store->getPending($userId);

        if ($pending === null) {
            $this->transport->deliver([
                'type' => 'error',
                'text' => 'Geen actie in afwachting van bevestiging. De sessie is mogelijk verlopen.',
            ]);
            return;
        }

        $toolUseId = $pending['tool_use_id'] ?? '';

        try {
            $result = $this->executor->runConfirmed($token, $userId, $toolUseId);
        } catch (\Throwable $e) {
            error_log('[ntdst-assistant] Error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            $result = [
                'type' => 'error',
                'text' => 'Er ging iets mis. Probeer het opnieuw.',
            ];
        }

        $this->transport->deliver($result);
    }

    public function handleCancel(WP_REST_Request $request): void
    {
        $userId = get_current_user_id();
        $token  = $request->get_param('confirm_token');

        $pending = $this->store->getPending($userId);

        if ($pending === null) {
            $this->transport->deliver([
                'type' => 'error',
                'text' => 'Geen actie in afwachting van bevestiging.',
            ]);
            return;
        }

        $storedToken = $pending['token'] ?? '';

        if (!hash_equals($storedToken, $token)) {
            $this->transport->deliver([
                'type' => 'error',
                'text' => 'Ongeldig bevestigingstoken.',
            ]);
            return;
        }

        $toolUseId = $pending['tool_use_id'] ?? '';

        $this->store->clearPending($userId);

        $result = $this->executor->runCancelled($toolUseId, $userId);

        $this->transport->deliver($result);
    }
}
