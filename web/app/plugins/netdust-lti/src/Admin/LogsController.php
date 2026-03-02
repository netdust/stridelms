<?php
declare(strict_types=1);

namespace NetdustLTI\Admin;

use WP_REST_Request;
use WP_REST_Response;

/**
 * REST controller for reading LTI log files.
 */
final class LogsController
{
    public function __construct()
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        register_rest_route('netdust-lti/v1', '/logs', [
            'methods' => 'GET',
            'callback' => [$this, 'getLogs'],
            'permission_callback' => fn() => current_user_can('manage_options'),
            'args' => [
                'channel' => [
                    'type' => 'string',
                    'enum' => ['lti', 'lti-grade'],
                    'default' => 'lti',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'date' => [
                    'type' => 'string',
                    'default' => '',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'limit' => [
                    'type' => 'integer',
                    'default' => 50,
                    'minimum' => 1,
                    'maximum' => 200,
                    'sanitize_callback' => 'absint',
                ],
            ],
        ]);
    }

    public function getLogs(WP_REST_Request $request): WP_REST_Response
    {
        $channel = $request->get_param('channel');
        $date = $request->get_param('date') ?: date('Y-m-d');
        $limit = $request->get_param('limit');

        // Validate date format to prevent path traversal
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $date = date('Y-m-d');
        }

        $logFile = WP_CONTENT_DIR . '/logs/' . $channel . '-' . $date . '.log';

        if (!file_exists($logFile)) {
            return new WP_REST_Response(['logs' => [], 'date' => $date], 200);
        }

        $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $lines = array_slice(array_reverse($lines), 0, $limit);

        $logs = [];
        foreach ($lines as $line) {
            if (preg_match('/^\[([^\]]+)\]\s+(\w+[-\w]*)\.(\w+):\s+(.+?)(\s+\{.*\})?$/', $line, $matches)) {
                $logs[] = [
                    'time' => $matches[1],
                    'channel' => $matches[2],
                    'level' => $matches[3],
                    'message' => $matches[4],
                    'context' => isset($matches[5]) ? json_decode(trim($matches[5]), true) : [],
                ];
            }
        }

        return new WP_REST_Response(['logs' => $logs, 'date' => $date], 200);
    }
}
