<?php
declare(strict_types=1);

namespace NetdustLTI\Admin;

/**
 * LTI Settings Admin Page.
 *
 * Provides a simplified settings page that links to CPT edit screens
 * (Data Manager auto-generates platform/tool admin UI) and log viewing.
 */
final class AdminPage
{
    public function __construct()
    {
        add_action('admin_menu', [$this, 'registerMenu']);
    }

    public function registerMenu(): void
    {
        add_options_page(
            'Netdust LTI',
            'Netdust LTI',
            'manage_options',
            'netdust-lti',
            [$this, 'renderPage']
        );
    }

    public function renderPage(): void
    {
        $action = isset($_GET['action']) ? sanitize_key($_GET['action']) : 'settings';

        if ($action === 'logs') {
            $this->renderLogs();
        } else {
            include dirname(__DIR__, 2) . '/templates/admin/settings-page.php';
        }
    }

    private function renderLogs(): void
    {
        $tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'launches';
        $channel = $tab === 'grades' ? 'lti-grade' : 'lti';

        $logs = $this->readLogFile($channel, 50);

        include dirname(__DIR__, 2) . '/templates/admin/logs.php';
    }

    private function readLogFile(string $channel, int $limit = 50): array
    {
        $logFile = WP_CONTENT_DIR . '/logs/' . $channel . '-' . date('Y-m-d') . '.log';

        if (!file_exists($logFile)) {
            return [];
        }

        $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $lines = array_slice(array_reverse($lines), 0, $limit);

        $logs = [];
        foreach ($lines as $line) {
            // Parse log line format: [YYYY-MM-DD HH:MM:SS] channel.LEVEL: Message {"context":"data"}
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

        return $logs;
    }

    public function getToolEndpoints(): array
    {
        return [
            'oidc_login' => home_url('/lti/login'),
            'launch' => home_url('/lti/launch'),
            'jwks' => home_url('/lti/jwks'),
            'deep_link' => home_url('/lti/deep-link'),
        ];
    }
}
