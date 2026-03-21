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

        register_rest_route(self::NAMESPACE, '/clear', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handleClear'],
            'permission_callback' => [$this, 'checkPermission'],
        ]);

        register_rest_route(self::NAMESPACE, '/download', [
            'methods'             => 'GET',
            'callback'            => [$this, 'handleDownload'],
            'permission_callback' => '__return_true', // Auth via HMAC-signed URL (user-bound, time-limited)
            'args'                => [
                'file'    => ['required' => true, 'sanitize_callback' => 'sanitize_file_name'],
                'token'   => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                'expires' => ['required' => true, 'sanitize_callback' => 'absint'],
                'uid'     => ['required' => true, 'sanitize_callback' => 'absint'],
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

        // Concurrency guard: prevent overlapping requests from the same user
        $lockKey = 'ntdst_assistant_lock_' . $userId;
        if (get_transient($lockKey)) {
            $this->transport->deliver([
                'type' => 'error',
                'text' => 'Er loopt al een verzoek. Wacht tot het vorige verzoek is afgerond.',
            ]);
            return;
        }
        set_transient($lockKey, true, 180);

        $content = $request->get_param('content');

        try {
            $result = $this->executor->run($content, $userId);
        } catch (\Throwable $e) {
            error_log('[ntdst-assistant] Error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            $result = [
                'type' => 'error',
                'text' => 'Er ging iets mis. Probeer het opnieuw.',
            ];
        } finally {
            delete_transient($lockKey);
        }

        $result['created_at'] = gmdate('c');
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

        $lockKey = 'ntdst_assistant_lock_' . $userId;
        if (get_transient($lockKey)) {
            $this->transport->deliver([
                'type' => 'error',
                'text' => 'Er loopt al een verzoek. Wacht tot het vorige verzoek is afgerond.',
            ]);
            return;
        }
        set_transient($lockKey, true, 180);

        $toolUseId = $pending['tool_use_id'] ?? '';

        try {
            $result = $this->executor->runConfirmed($token, $userId, $toolUseId);
        } catch (\Throwable $e) {
            error_log('[ntdst-assistant] Error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            $result = [
                'type' => 'error',
                'text' => 'Er ging iets mis. Probeer het opnieuw.',
            ];
        } finally {
            delete_transient($lockKey);
        }

        $result['created_at'] = gmdate('c');
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

        $result['created_at'] = gmdate('c');
        $this->transport->deliver($result);
    }

    public function handleClear(WP_REST_Request $request): void
    {
        $userId = get_current_user_id();
        $this->store->clear($userId);
        $this->transport->deliver(['type' => 'cleared', 'cleared' => true]);
    }

    public function handleDownload(WP_REST_Request $request): void
    {
        $file    = $request->get_param('file');
        $token   = $request->get_param('token');
        $expires = (int) $request->get_param('expires');
        $userId  = (int) $request->get_param('uid');

        $export = ntdst_get(ExportService::class);

        if (!$export->verifySignedUrl($file, $token, $expires, $userId)) {
            wp_send_json_error(['message' => 'Download link is ongeldig of verlopen.'], 403);
            return;
        }

        $filepath = $export->resolveFilePath($file);
        if ($filepath === false || !file_exists($filepath)) {
            wp_send_json_error(['message' => 'Bestand niet gevonden.'], 404);
            return;
        }

        $filename = basename($filepath);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($filepath));
        header('Cache-Control: no-cache, no-store, must-revalidate');

        readfile($filepath);
        unlink($filepath);
        exit;
    }
}
