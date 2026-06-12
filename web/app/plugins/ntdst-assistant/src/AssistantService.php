<?php
declare(strict_types=1);

namespace NtdstAssistant;

class AssistantService implements \NTDST_Service_Meta
{
    private const MENU_SLUG = 'stride-assistant';

    public static function metadata(): array
    {
        return [
            'name' => 'Assistant Service',
            'description' => 'Admin page and asset loading for AI assistant',
            'admin_only' => true,
            'priority' => 14,
        ];
    }

    public function __construct()
    {
        $this->init();
    }

    private function init(): void
    {
        add_action('admin_menu', [$this, 'registerAdminPage']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('admin_head', [$this, 'loadChrome']);
        add_filter('stride_tools_menu_items', [$this, 'registerToolsCard']);

        if (!$this->hasApiKey()) {
            add_action('admin_notices', [$this, 'showApiKeyNotice']);
        }
    }

    private function getCapability(): string
    {
        $capability = get_option('ntdst_assistant_capability', 'edit_others_posts');
        $allowed = ['manage_options', 'edit_others_posts', 'stride_manage', 'stride_view'];
        return in_array($capability, $allowed, true) ? $capability : 'edit_others_posts';
    }

    /**
     * Surface this tool on the Stride Tools index + dashboard card.
     */
    public function registerToolsCard(array $items): array
    {
        $items[] = [
            'slug'        => self::MENU_SLUG,
            'label'       => 'Assistant',
            'description' => 'AI-assistent voor admin taken en analyses.',
            'icon'        => 'dashicons-format-chat',
            'capability'  => $this->getCapability(),
        ];
        return $items;
    }

    public function loadChrome(): void
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || !str_contains((string) $screen->id, self::MENU_SLUG)) {
            return;
        }
        if (function_exists('stride_load_tool_chrome')) {
            stride_load_tool_chrome();
        }
    }

    public function registerAdminPage(): void
    {
        $parent = class_exists('\Stride\Admin\StrideToolsService') ? 'stride-tools' : 'stride-dashboard';

        add_submenu_page(
            $parent,
            'Stride Assistant',
            'Assistant',
            $this->getCapability(),
            self::MENU_SLUG,
            [$this, 'renderPage'],
        );
    }

    public function enqueueAssets(string $hook): void
    {
        if (!str_contains($hook, self::MENU_SLUG)) {
            return;
        }

        $pluginUrl = plugin_dir_url(dirname(__FILE__));
        $pluginPath = dirname(__DIR__);

        wp_enqueue_style(
            'ntdst-assistant',
            $pluginUrl . 'assets/css/assistant.css',
            [],
            filemtime($pluginPath . '/assets/css/assistant.css'),
        );

        // assistant.js must load BEFORE Alpine so alpine:init fires after registration
        wp_enqueue_script(
            'ntdst-assistant',
            $pluginUrl . 'assets/js/assistant.js',
            [],
            filemtime($pluginPath . '/assets/js/assistant.js'),
            true,
        );

        wp_enqueue_script(
            'alpinejs',
            $pluginUrl . 'assets/js/alpine.min.js',
            ['ntdst-assistant'],
            '3.14.9',
            true,
        );

        wp_localize_script('ntdst-assistant', 'ntdstAssistantConfig', [
            'restUrl' => rest_url('ntdst-assistant/v1/'),
            'nonce' => wp_create_nonce('wp_rest'),
        ]);
    }

    public function renderPage(): void
    {
        $templatePath = dirname(__DIR__) . '/templates/admin/chat.php';
        if (file_exists($templatePath)) {
            include $templatePath;
        }
    }

    public function showApiKeyNotice(): void
    {
        $screen = get_current_screen();
        if (!str_contains($screen?->id ?? '', self::MENU_SLUG)) {
            return;
        }

        echo '<div class="notice notice-warning"><p>';
        echo '<strong>NTDST Assistant:</strong> API-sleutel niet geconfigureerd. ';
        echo 'Stel in via <code>.env</code>: <code>NTDST_ASSISTANT_API_KEY=sk-ant-...</code>';
        echo '</p></div>';
    }

    private function hasApiKey(): bool
    {
        if (defined('NTDST_ASSISTANT_API_KEY')) {
            return true;
        }
        return !empty(get_option('ntdst_assistant_api_key', ''));
    }
}
