<?php
declare(strict_types=1);

namespace NetdustLTI\Admin;

use NetdustLTI\Plugin;

/**
 * Unified LTI Settings Page
 *
 * Single Alpine.js-powered admin page for LTI tool provider configuration.
 * Manages Platforms (external LMS connections) inline with CRUD via WP REST API.
 */
final class SettingsPage
{
    private const MENU_SLUG = 'netdust-lti';
    private const CAPABILITY = 'manage_options';

    public function __construct()
    {
        add_action('admin_menu', [$this, 'registerMenu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('admin_head', [$this, 'injectStyles']);
        add_action('admin_footer', [$this, 'injectScripts']);
        add_filter('admin_body_class', [$this, 'addBodyClasses']);
        add_filter('stride_tools_menu_items', [$this, 'registerToolsCard']);
    }

    public function registerMenu(): void
    {
        if ($this->hasStrideTools()) {
            add_submenu_page(
                'stride-tools',
                'Netdust LTI',
                'LTI',
                self::CAPABILITY,
                self::MENU_SLUG,
                [$this, 'renderPage']
            );
            return;
        }

        add_options_page(
            'Netdust LTI',
            'Netdust LTI',
            self::CAPABILITY,
            self::MENU_SLUG,
            [$this, 'renderPage']
        );
    }

    /**
     * Surface this tool on the Stride Tools index + dashboard card.
     */
    public function registerToolsCard(array $items): array
    {
        $items[] = [
            'slug'        => self::MENU_SLUG,
            'label'       => 'LTI',
            'description' => 'Configureer LTI tools, platforms en launch tests.',
            'icon'        => 'dashicons-admin-plugins',
            'capability'  => self::CAPABILITY,
        ];
        return $items;
    }

    private function hasStrideTools(): bool
    {
        return class_exists('\Stride\Admin\StrideToolsService');
    }

    private function isLtiPage(): bool
    {
        $screen = get_current_screen();
        if (!$screen) {
            return (sanitize_text_field($_GET['page'] ?? '') === self::MENU_SLUG);
        }
        $expected = ($this->hasStrideTools() ? 'stride-tools_page_' : 'settings_page_') . self::MENU_SLUG;
        return $screen->id === $expected;
    }

    public function enqueueAssets(string $hook): void
    {
        $expected = ($this->hasStrideTools() ? 'stride-tools_page_' : 'settings_page_') . self::MENU_SLUG;
        if ($hook !== $expected) {
            return;
        }

        ntdst_enqueue_admin_toolkit();

        wp_enqueue_script(
            'alpinejs',
            'https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js',
            [],
            '3.14.0',
            ['strategy' => 'defer']
        );

        wp_localize_script('alpinejs', 'LtiConfig', [
            'restUrl' => rest_url('wp/v2'),
            'nonce' => wp_create_nonce('wp_rest'),
            'homeUrl' => home_url(),
            'adminUrl' => admin_url(),
            'toolEndpoints' => $this->getToolEndpoints(),
            'keyStatus' => $this->getKeyStatus(),
        ]);
    }

    public function addBodyClasses(string $classes): string
    {
        if ($this->isLtiPage()) {
            $classes .= ' lti-settings-page';
        }
        return $classes;
    }

    public function injectStyles(): void
    {
        if (!$this->isLtiPage()) {
            return;
        }
        $cssPath = Plugin::pluginPath() . '/assets/css/lti-admin.css';
        if (file_exists($cssPath)) {
            echo '<style id="lti-admin-styles">';
            include $cssPath;
            echo '</style>';
        }
    }

    public function injectScripts(): void
    {
        if (!$this->isLtiPage()) {
            return;
        }
        $jsPath = Plugin::pluginPath() . '/assets/js/lti-admin.js';
        if (file_exists($jsPath)) {
            echo '<script>';
            include $jsPath;
            echo '</script>';
        }
    }

    public function renderPage(): void
    {
        include Plugin::pluginPath() . '/templates/admin/settings.php';
    }

    private function getToolEndpoints(): array
    {
        return [
            'oidc_login' => home_url('/lti/login'),
            'launch' => home_url('/lti/launch'),
            'jwks' => home_url('/lti/jwks'),
            'deep_link' => home_url('/lti/deep-link'),
            'json_config' => home_url('/lti/configure-json'),
            'xml_config' => home_url('/lti/configure-xml'),
            'dynamic_registration' => home_url('/lti/register'),
        ];
    }

    private function getKeyStatus(): array
    {
        $kid = get_option('netdust_lti_kid', '');
        $hasPrivateKey = (bool) get_option('netdust_lti_private_key', '');
        $hasPublicKey = (bool) get_option('netdust_lti_public_key', '');
        return [
            'kid' => $kid,
            'hasKeys' => $hasPrivateKey && $hasPublicKey,
        ];
    }

    /**
     * Get legacy (LTI 1.1/1.2) configuration for a platform.
     */
    public function getLegacyConfig(int $platformId): array
    {
        return [
            'launch_url'      => home_url('/lti/launch'),
            'consumer_key'    => get_post_meta($platformId, 'lti_consumer_key', true) ?: '',
            'consumer_secret' => get_post_meta($platformId, 'lti_consumer_secret', true) ?: '',
        ];
    }
}
