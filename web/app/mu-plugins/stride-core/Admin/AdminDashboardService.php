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
    private const CAPABILITY = 'manage_options';

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
        add_action('admin_menu', [$this, 'registerAdminPage']);
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
    }

    /**
     * Check if we're on the Stride dashboard page
     */
    private function isStridePage(): bool
    {
        $screen = get_current_screen();
        if (!$screen) {
            return isset($_GET['page']) && strpos($_GET['page'], self::MENU_SLUG) === 0;
        }

        return strpos($screen->id, self::MENU_SLUG) !== false;
    }

    /**
     * Enqueue assets on our pages
     */
    public function enqueueAssets(string $hook): void
    {
        if (strpos($hook, self::MENU_SLUG) === false) {
            return;
        }

        wp_enqueue_script(
            'alpinejs',
            'https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js',
            [],
            '3.14.0',
            ['strategy' => 'defer']
        );

        $user = wp_get_current_user();

        wp_localize_script('alpinejs', 'StrideConfig', [
            'apiUrl' => rest_url('stride/v1'),
            'nonce' => wp_create_nonce('wp_rest'),
            'user' => [
                'id' => $user->ID,
                'name' => $user->display_name,
                'email' => $user->user_email,
            ],
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

        echo '<style id="stride-dashboard-styles">
            :root {
                --stride-bg: #f8fafc;
                --stride-card: #ffffff;
                --stride-border: #e2e8f0;
                --stride-text: #1e293b;
                --stride-text-muted: #64748b;
                --stride-primary: #6366f1;
                --stride-primary-hover: #4f46e5;
                --stride-success: #10b981;
                --stride-warning: #f59e0b;
                --stride-danger: #ef4444;
                --stride-info: #3b82f6;
            }

            /* Hide WordPress clutter */
            body.stride-dashboard #wpadminbar,
            body.stride-dashboard #wpfooter,
            body.stride-dashboard .notice,
            body.stride-dashboard .update-nag,
            body.stride-dashboard .updated,
            body.stride-dashboard .error,
            body.stride-dashboard #screen-meta,
            body.stride-dashboard #screen-meta-links {
                display: none !important;
            }

            html.wp-toolbar:has(body.stride-dashboard) {
                padding-top: 0 !important;
                --wp-admin--admin-bar--height: 0px;
            }

            body.stride-dashboard #wpcontent {
                padding-top: 0;
                margin-left: 140px;
            }

            body.stride-dashboard.folded #wpcontent {
                margin-left: 36px;
            }

            body.stride-dashboard #wpbody-content {
                padding-bottom: 0;
                height: 100vh;
                overflow: hidden;
            }

            body.stride-dashboard .wrap {
                margin: 0;
            }

            /* ========================================
               APP STYLES
            ======================================== */
            .stride-app {
                display: flex;
                flex-direction: column;
                height: 100vh;
                background: var(--stride-bg);
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
                color: var(--stride-text);
                font-size: 14px;
                line-height: 1.5;
            }

            /* Header */
            .stride-header {
                display: flex;
                align-items: center;
                justify-content: space-between;
                padding: 0 32px;
                height: 56px;
                background: var(--stride-primary);
                flex-shrink: 0;
            }

            .stride-header h1 {
                font-size: 18px;
                font-weight: 600;
                margin: 0;
                color: #fff;
            }

            .stride-user {
                display: flex;
                align-items: center;
                gap: 16px;
            }

            .stride-user-name {
                font-size: 14px;
                color: rgba(255,255,255,0.9);
            }

            .stride-logout {
                padding: 6px 14px;
                background: rgba(255,255,255,0.15);
                border: none;
                border-radius: 6px;
                color: #fff;
                cursor: pointer;
                font-size: 13px;
                text-decoration: none;
                transition: background 0.2s;
            }

            .stride-logout:hover {
                background: rgba(255,255,255,0.25);
                color: #fff;
            }

            /* Content wrapper */
            .stride-content-wrapper {
                flex: 1;
                overflow-y: auto;
                padding: 24px 32px;
            }

            /* Page header */
            .stride-page-header {
                display: flex;
                align-items: center;
                gap: 32px;
                margin-bottom: 24px;
            }

            .stride-page-title {
                font-size: 22px;
                font-weight: 600;
                margin: 0;
                color: var(--stride-text);
            }

            /* Card */
            .stride-card {
                background: var(--stride-card);
                border: 1px solid var(--stride-border);
                border-radius: 12px;
                margin-bottom: 16px;
            }

            .stride-card-header {
                display: flex;
                align-items: center;
                justify-content: space-between;
                padding: 20px 24px;
                border-bottom: 1px solid var(--stride-border);
            }

            .stride-card-title {
                font-size: 16px;
                font-weight: 600;
                margin: 0;
                color: var(--stride-text);
            }

            .stride-card-body {
                padding: 24px;
            }

            /* Empty state */
            .stride-empty {
                text-align: center;
                padding: 48px 24px;
                color: var(--stride-text-muted);
            }

            .stride-empty-icon {
                font-size: 48px;
                margin-bottom: 16px;
                opacity: 0.5;
            }

            /* Loading */
            .stride-loading {
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 48px;
                color: var(--stride-text-muted);
            }
        </style>';
    }

    /**
     * Render the dashboard page
     */
    public function renderDashboard(): void
    {
        ?>
        <div class="wrap stride-app" x-data="strideApp()">
            <!-- Header -->
            <header class="stride-header">
                <h1>Stride</h1>
                <div class="stride-user">
                    <span class="stride-user-name" x-text="user.name"></span>
                    <a href="<?php echo esc_url(wp_logout_url(home_url())); ?>" class="stride-logout">
                        Logout
                    </a>
                </div>
            </header>

            <!-- Content -->
            <div class="stride-content-wrapper">
                <!-- Page Header -->
                <div class="stride-page-header">
                    <h2 class="stride-page-title">Dashboard</h2>
                </div>

                <!-- Welcome Card -->
                <div class="stride-card">
                    <div class="stride-card-header">
                        <h3 class="stride-card-title">Welcome to Stride</h3>
                    </div>
                    <div class="stride-card-body">
                        <template x-if="loading">
                            <div class="stride-loading">Loading...</div>
                        </template>
                        <template x-if="!loading">
                            <div>
                                <p>Hello, <strong x-text="user.name"></strong>!</p>
                                <p>The Stride admin dashboard is ready. More features coming soon.</p>
                            </div>
                        </template>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Inject Alpine.js app logic
     */
    public function injectScripts(): void
    {
        if (!$this->isStridePage()) {
            return;
        }

        ?>
        <script>
        document.addEventListener('alpine:init', () => {
            Alpine.data('strideApp', () => ({
                // State
                loading: true,
                user: StrideConfig.user,

                // Initialize
                init() {
                    this.loadData();
                },

                // Load data
                async loadData() {
                    this.loading = true;

                    // Simulate initial load
                    await new Promise(resolve => setTimeout(resolve, 300));

                    this.loading = false;
                }
            }));
        });
        </script>
        <?php
    }
}
