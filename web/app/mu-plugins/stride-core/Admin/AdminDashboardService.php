<?php

declare(strict_types=1);

namespace Stride\Admin;

use Stride\Infrastructure\AbstractService;

/**
 * Admin Dashboard Service
 *
 * Creates a full-screen Alpine.js app for admin dashboard in WordPress admin.
 * Hides most WordPress UI elements, keeps sidebar collapsed.
 */
class AdminDashboardService extends AbstractService
{
    /** Menu slug */
    private const MENU_SLUG = 'stride-dashboard';

    /** Capability required to access */
    private const CAPABILITY = 'stride_view';

    /**
     * {@inheritDoc}
     */
    public static function metadata(): array
    {
        return [
            'name' => 'Stride Admin Dashboard',
            'description' => 'Full-screen Alpine.js dashboard for administration',
            'admin_only' => true,
            'enabled' => true,
            'priority' => 5,
        ];
    }

    /**
     * {@inheritDoc}
     */
    protected function getConfigSlug(): string
    {
        return 'admin_dashboard';
    }

    /**
     * {@inheritDoc}
     */
    protected function init(): void
    {
        // Admin REST API (registers own hooks in constructor)
        new AdminAPIController(
            ntdst_get(\Stride\Modules\Attendance\AttendanceRepository::class),
            ntdst_get(\Stride\Modules\Edition\EditionRepository::class),
            ntdst_get(\Stride\Modules\Edition\SessionRepository::class),
        );

        // Admin guide page (registers own menu hook)
        new AdminGuidePage();

        add_action('admin_menu', [$this, 'registerAdminPage']);
        add_action('admin_menu', [$this, 'reorderSubmenus'], 999);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('admin_head', [$this, 'injectStyles']);
        add_action('admin_footer', [$this, 'injectScripts']);
        add_filter('admin_body_class', [$this, 'addBodyClasses']);
    }

    /**
     * Register the admin menu page
     */
    public function registerAdminPage(): void
    {
        add_menu_page(
            'Stride',
            'Stride',
            self::CAPABILITY,
            self::MENU_SLUG,
            [$this, 'renderDashboard'],
            'dashicons-welcome-learn-more',
            2
        );

        // Add explicit Dashboard submenu as first item
        add_submenu_page(
            self::MENU_SLUG,
            'Dashboard',
            'Dashboard',
            self::CAPABILITY,
            self::MENU_SLUG,
            [$this, 'renderDashboard']
        );
    }

    /**
     * Reorder submenus to put Dashboard first
     */
    public function reorderSubmenus(): void
    {
        global $submenu;

        if (!isset($submenu[self::MENU_SLUG])) {
            return;
        }

        $items = $submenu[self::MENU_SLUG];
        $dashboard = null;
        $others = [];

        foreach ($items as $key => $item) {
            // Dashboard has the same slug as the parent menu
            if ($item[2] === self::MENU_SLUG) {
                $dashboard = $item;
            } else {
                $others[] = $item;
            }
        }

        // Rebuild with Dashboard first
        if ($dashboard) {
            $submenu[self::MENU_SLUG] = array_merge([$dashboard], $others);
        }
    }

    /**
     * Check if we're on the Stride dashboard page
     */
    private function isStridePage(): bool
    {
        $screen = get_current_screen();
        if (!$screen) {
            $page = isset($_GET['page']) ? sanitize_text_field(wp_unslash($_GET['page'])) : '';

            return $page === self::MENU_SLUG;
        }

        // Only match the actual dashboard page, not submenu pages
        return $screen->id === 'toplevel_page_' . self::MENU_SLUG;
    }

    /**
     * Enqueue assets on our pages
     */
    public function enqueueAssets(string $hook): void
    {
        if ($hook !== 'toplevel_page_' . self::MENU_SLUG) {
            return;
        }

        // Flatpickr for date range picker
        wp_enqueue_style(
            'flatpickr',
            'https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css',
            [],
            '4.6.13'
        );
        wp_enqueue_script(
            'flatpickr',
            'https://cdn.jsdelivr.net/npm/flatpickr',
            [],
            '4.6.13',
            true
        );
        wp_enqueue_script(
            'flatpickr-nl',
            'https://cdn.jsdelivr.net/npm/flatpickr/dist/l10n/nl.js',
            ['flatpickr'],
            '4.6.13',
            true
        );

        wp_enqueue_script(
            'alpinejs',
            'https://cdn.jsdelivr.net/npm/alpinejs@3.14.9/dist/cdn.min.js',
            ['flatpickr'],
            '3.14.9',
            ['strategy' => 'defer']
        );

        // Add crossorigin for CDN scripts (required for SRI)
        add_filter('script_loader_tag', function (string $tag, string $handle): string {
            if (in_array($handle, ['alpinejs', 'flatpickr', 'flatpickr-nl'], true)) {
                return str_replace(' src=', ' crossorigin="anonymous" src=', $tag);
            }
            return $tag;
        }, 10, 2);

        $user = wp_get_current_user();

        wp_localize_script('alpinejs', 'StrideConfig', [
            'apiUrl' => rest_url('stride/v1'),
            'nonce' => wp_create_nonce('wp_rest'),
            'user' => [
                'id' => $user->ID,
                'name' => $user->display_name,
                'email' => $user->user_email,
                'firstName' => $user->first_name ?: $user->display_name,
            ],
            'canManage' => current_user_can('stride_manage'),
        ]);
    }

    /**
     * Add body classes for our pages
     */
    public function addBodyClasses(string $classes): string
    {
        if ($this->isStridePage()) {
            $classes .= ' stride-dashboard folded';
        }

        return $classes;
    }

    /**
     * Inject CSS to hide WordPress UI
     */
    public function injectStyles(): void
    {
        if (!$this->isStridePage()) {
            return;
        }

        $cssPath = dirname(__DIR__) . '/assets/css/admin-dashboard.css';
        if (file_exists($cssPath)) {
            echo '<style id="stride-dashboard-styles">';
            include $cssPath;
            echo '</style>';
        }
    }

    /**
     * Render the dashboard page
     */
    public function renderDashboard(): void
    {
        $templatePath = dirname(__DIR__) . '/templates/admin/dashboard.php';

        $admin_url = admin_url();
        $user = wp_get_current_user();
        $user_name = $user->display_name;

        if (file_exists($templatePath)) {
            include $templatePath;
        }
    }

    /**
     * Inject Alpine.js app logic
     */
    public function injectScripts(): void
    {
        if (!$this->isStridePage()) {
            return;
        }

        $jsPath = dirname(__DIR__) . '/assets/js/admin-dashboard.js';
        if (file_exists($jsPath)) {
            echo '<script>';
            include $jsPath;
            echo '</script>';
        }
    }
}
