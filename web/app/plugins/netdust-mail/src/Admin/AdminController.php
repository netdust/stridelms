<?php

declare(strict_types=1);

namespace Netdust\Mail\Admin;

use Netdust\Mail\MailTemplateCPT;
use Netdust\Mail\SmartCodeRegistry;
use Netdust\Mail\TriggerRegistry;
use Netdust\Mail\AttachmentHandler;

defined('ABSPATH') || exit;

/**
 * Single Alpine.js-powered admin page for email template management.
 *
 * Follows the netdust-lti SettingsPage pattern:
 * - Registered under Settings menu
 * - Alpine.js tabs with hash-based routing
 * - CRUD via WP REST API
 * - Inline asset injection
 */
final class AdminController
{
    private const MENU_SLUG = 'netdust-mail';
    private const CAPABILITY = 'manage_options';

    public function __construct(
        private readonly SmartCodeRegistry $smartCodeRegistry,
        private readonly TriggerRegistry $triggerRegistry,
        private readonly AttachmentHandler $attachmentHandler,
    ) {
        $this->init();
    }

    private function init(): void
    {
        add_action('admin_menu', [$this, 'registerMenu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('admin_head', [$this, 'injectStyles']);
        add_action('admin_footer', [$this, 'injectScripts']);
        add_action('rest_api_init', [$this, 'registerRestRoutes']);
    }

    public function registerMenu(): void
    {
        add_options_page(
            'Netdust Mail',
            'Netdust Mail',
            self::CAPABILITY,
            self::MENU_SLUG,
            [$this, 'renderPage']
        );
    }

    private function isMailPage(): bool
    {
        $screen = get_current_screen();
        if (!$screen) {
            return (sanitize_text_field($_GET['page'] ?? '') === self::MENU_SLUG);
        }
        return $screen->id === 'settings_page_' . self::MENU_SLUG;
    }

    public function enqueueAssets(string $hook): void
    {
        if ($hook !== 'settings_page_' . self::MENU_SLUG) {
            return;
        }

        ntdst_enqueue_admin_toolkit();

        // WordPress editor (TinyMCE) for email body editing
        wp_enqueue_editor();

        wp_enqueue_script(
            'alpinejs',
            'https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js',
            [],
            '3.14.0',
            ['strategy' => 'defer']
        );

        wp_localize_script('alpinejs', 'MailConfig', [
            'restUrl' => rest_url('wp/v2'),
            'nonce' => wp_create_nonce('wp_rest'),
            'smartcodes' => $this->smartCodeRegistry->getAll(),
            'smartcodesFlat' => $this->smartCodeRegistry->getAllFlat(),
            'triggers' => $this->triggerRegistry->getAll(),
            'triggerOptions' => $this->triggerRegistry->getOptions(),
            'pdfGenerators' => $this->attachmentHandler->getAvailableGenerators(),
            'settings' => [
                'fromName' => get_option('ndmail_from_name', ''),
                'fromEmail' => get_option('ndmail_from_email', ''),
            ],
            'categoryOptions' => [
                'auth' => 'Authentication',
                'notification' => 'Notification',
                'transactional' => 'Transactional',
                'marketing' => 'Marketing',
            ],
        ]);
    }

    public function injectStyles(): void
    {
        if (!$this->isMailPage()) {
            return;
        }
        $cssPath = NDMAIL_PATH . 'assets/css/admin.css';
        if (file_exists($cssPath)) {
            echo '<style id="ndmail-admin-styles">';
            include $cssPath;
            echo '</style>';
        }
    }

    public function injectScripts(): void
    {
        if (!$this->isMailPage()) {
            return;
        }
        $jsPath = NDMAIL_PATH . 'assets/js/admin.js';
        if (file_exists($jsPath)) {
            echo '<script>';
            include $jsPath;
            echo '</script>';
        }
    }

    public function renderPage(): void
    {
        include NDMAIL_PATH . 'templates/admin/settings.php';
    }

    public function registerRestRoutes(): void
    {
        register_rest_route('netdust-mail/v1', '/settings', [
            'methods' => 'POST',
            'callback' => [$this, 'saveSettings'],
            'permission_callback' => fn() => current_user_can(self::CAPABILITY),
        ]);
    }

    public function saveSettings(\WP_REST_Request $request): \WP_REST_Response
    {
        $fromName = sanitize_text_field($request->get_param('fromName') ?? '');
        $fromEmail = sanitize_email($request->get_param('fromEmail') ?? '');

        update_option('ndmail_from_name', $fromName);
        update_option('ndmail_from_email', $fromEmail);

        return new \WP_REST_Response([
            'fromName' => $fromName,
            'fromEmail' => $fromEmail,
        ]);
    }
}
