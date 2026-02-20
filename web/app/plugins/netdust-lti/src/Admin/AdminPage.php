<?php
declare(strict_types=1);

namespace NetdustLTI\Admin;

use NetdustLTI\Repositories\PlatformRepository;
use NetdustLTI\Domain\Platform;

final class AdminPage
{
    private PlatformRepository $platformRepository;

    public function __construct()
    {
        $this->platformRepository = ntdst_get(PlatformRepository::class);

        add_action('admin_menu', [$this, 'registerMenu']);
        add_action('admin_init', [$this, 'handleFormSubmission']);
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
        $action = $_GET['action'] ?? 'list';

        switch ($action) {
            case 'add':
            case 'edit':
                $this->renderPlatformForm();
                break;

            case 'logs':
                $this->renderLogs();
                break;

            default:
                $this->renderPlatformList();
        }
    }

    private function renderPlatformList(): void
    {
        $listTable = new PlatformListTable($this->platformRepository);
        $listTable->prepare_items();

        include dirname(__DIR__, 2) . '/templates/admin/settings-page.php';
    }

    private function renderPlatformForm(): void
    {
        $platform = null;
        $platformId = isset($_GET['platform_id']) ? (int) $_GET['platform_id'] : null;

        if ($platformId) {
            $platform = $this->platformRepository->find($platformId);
            if (is_wp_error($platform)) {
                $platform = null;
            }
        }

        include dirname(__DIR__, 2) . '/templates/admin/platform-form.php';
    }

    public function handleFormSubmission(): void
    {
        if (!isset($_POST['netdust_lti_platform_nonce'])) {
            return;
        }

        if (!wp_verify_nonce($_POST['netdust_lti_platform_nonce'], 'netdust_lti_save_platform')) {
            wp_die('Security check failed');
        }

        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        // Handle delete action
        if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['platform_id'])) {
            $this->handleDelete();
            return;
        }

        $platform = new Platform(
            id: isset($_POST['platform_id']) ? (int) $_POST['platform_id'] : null,
            name: sanitize_text_field($_POST['name']),
            platformId: esc_url_raw($_POST['platform_url']),
            clientId: sanitize_text_field($_POST['client_id']),
            deploymentId: sanitize_text_field($_POST['deployment_id']) ?: null,
            authEndpoint: esc_url_raw($_POST['auth_endpoint']),
            tokenEndpoint: esc_url_raw($_POST['token_endpoint']),
            jwksEndpoint: esc_url_raw($_POST['jwks_endpoint']),
            enabled: isset($_POST['enabled']),
        );

        if ($platform->id) {
            $result = $this->platformRepository->update($platform->id, $platform);
        } else {
            $result = $this->platformRepository->create($platform);
        }

        if (is_wp_error($result)) {
            add_settings_error('netdust_lti', 'save_failed', $result->get_error_message());
        } else {
            wp_redirect(admin_url('options-general.php?page=netdust-lti&saved=1'));
            exit;
        }
    }

    private function handleDelete(): void
    {
        $platformId = (int) $_GET['platform_id'];

        if (!wp_verify_nonce($_GET['_wpnonce'] ?? '', 'delete_platform_' . $platformId)) {
            wp_die('Security check failed');
        }

        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $result = $this->platformRepository->delete($platformId);

        if (is_wp_error($result)) {
            add_settings_error('netdust_lti', 'delete_failed', $result->get_error_message());
        } else {
            wp_redirect(admin_url('options-general.php?page=netdust-lti&deleted=1'));
            exit;
        }
    }

    private function renderLogs(): void
    {
        $tab = $_GET['tab'] ?? 'launches';
        $channel = $tab === 'grades' ? 'lti-grade' : 'lti';

        // Read recent log entries
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
