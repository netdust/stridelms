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
            $page = isset($_GET['page']) ? sanitize_text_field(wp_unslash($_GET['page'])) : '';

            return $page === self::MENU_SLUG;
        }

        return str_contains($screen->id, self::MENU_SLUG);
    }

    /**
     * Enqueue assets on our pages
     */
    public function enqueueAssets(string $hook): void
    {
        if (!str_contains($hook, self::MENU_SLUG)) {
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

            /* Header Layout */
            .stride-header-left {
                display: flex;
                align-items: center;
                gap: 32px;
            }

            .stride-header-right {
                display: flex;
                align-items: center;
                gap: 16px;
            }

            /* Navigation */
            .stride-nav {
                display: flex;
                gap: 4px;
            }

            .stride-nav-item {
                padding: 8px 16px;
                color: rgba(255,255,255,0.7);
                text-decoration: none;
                font-size: 14px;
                font-weight: 500;
                border-radius: 6px;
                transition: all 0.15s ease;
            }

            .stride-nav-item:hover {
                color: #fff;
                background: rgba(255,255,255,0.1);
            }

            .stride-nav-item.active {
                color: #fff;
                background: rgba(255,255,255,0.2);
            }

            /* Buttons */
            .stride-btn {
                display: inline-flex;
                align-items: center;
                gap: 8px;
                padding: 8px 16px;
                font-size: 14px;
                font-weight: 500;
                border-radius: 6px;
                text-decoration: none;
                cursor: pointer;
                transition: all 0.15s ease;
                border: none;
            }

            .stride-btn-ghost {
                background: rgba(255,255,255,0.15);
                color: #fff;
            }

            .stride-btn-ghost:hover {
                background: rgba(255,255,255,0.25);
                color: #fff;
            }

            /* Content */
            .stride-content {
                flex: 1;
                overflow-y: auto;
                padding: 24px 32px;
            }

            /* Stats Grid */
            .stride-stats {
                display: grid;
                grid-template-columns: repeat(4, 1fr);
                gap: 16px;
                margin-bottom: 24px;
            }

            .stride-stat-card {
                background: var(--stride-card);
                border: 1px solid var(--stride-border);
                border-radius: 12px;
                padding: 20px;
                display: flex;
                align-items: center;
                gap: 16px;
            }

            .stride-stat-icon {
                width: 48px;
                height: 48px;
                border-radius: 12px;
                display: flex;
                align-items: center;
                justify-content: center;
            }

            .stride-stat-icon .dashicons {
                font-size: 24px;
                width: 24px;
                height: 24px;
            }

            .stride-stat-icon.upcoming {
                background: rgba(99, 102, 241, 0.1);
                color: var(--stride-primary);
            }

            .stride-stat-icon.registrations {
                background: rgba(16, 185, 129, 0.1);
                color: var(--stride-success);
            }

            .stride-stat-icon.pending {
                background: rgba(245, 158, 11, 0.1);
                color: var(--stride-warning);
            }

            .stride-stat-icon.today {
                background: rgba(59, 130, 246, 0.1);
                color: var(--stride-info);
            }

            .stride-stat-value {
                font-size: 28px;
                font-weight: 700;
                color: var(--stride-text);
                line-height: 1;
            }

            .stride-stat-label {
                font-size: 13px;
                color: var(--stride-text-muted);
                margin-top: 4px;
            }

            /* Quick Actions */
            .stride-quick-actions {
                display: flex;
                gap: 12px;
            }

            .stride-quick-action {
                display: flex;
                align-items: center;
                gap: 8px;
                padding: 12px 20px;
                background: var(--stride-bg);
                border: 1px solid var(--stride-border);
                border-radius: 8px;
                color: var(--stride-text);
                text-decoration: none;
                font-weight: 500;
                transition: all 0.15s ease;
            }

            .stride-quick-action:hover {
                border-color: var(--stride-primary);
                color: var(--stride-primary);
            }

            .stride-quick-action .dashicons {
                font-size: 20px;
                width: 20px;
                height: 20px;
            }

            .stride-muted {
                color: var(--stride-text-muted);
            }

            /* Responsive */
            @media (max-width: 1200px) {
                .stride-stats {
                    grid-template-columns: repeat(2, 1fr);
                }
            }

            @media (max-width: 768px) {
                .stride-stats {
                    grid-template-columns: 1fr;
                }
                .stride-nav {
                    display: none;
                }
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
                <div class="stride-header-left">
                    <h1>Stride</h1>
                    <nav class="stride-nav">
                        <a href="#/" class="stride-nav-item" :class="{ 'active': view === 'dashboard' }" @click.prevent="view = 'dashboard'">
                            Dashboard
                        </a>
                        <a href="#/editions" class="stride-nav-item" :class="{ 'active': view === 'editions' }" @click.prevent="view = 'editions'">
                            Editions
                        </a>
                        <a href="#/quotes" class="stride-nav-item" :class="{ 'active': view === 'quotes' }" @click.prevent="view = 'quotes'">
                            Quotes
                        </a>
                    </nav>
                </div>
                <div class="stride-header-right">
                    <span class="stride-user-name" x-text="user.name"></span>
                    <a href="<?php echo esc_url(admin_url()); ?>" class="stride-btn stride-btn-ghost">
                        WP Admin
                    </a>
                </div>
            </header>

            <!-- Content -->
            <div class="stride-content">
                <!-- Dashboard View -->
                <template x-if="view === 'dashboard'">
                    <div>
                        <div class="stride-page-header">
                            <h2 class="stride-page-title">Dashboard</h2>
                        </div>

                        <!-- Stats Grid -->
                        <div class="stride-stats">
                            <div class="stride-stat-card">
                                <div class="stride-stat-icon upcoming">
                                    <span class="dashicons dashicons-calendar-alt"></span>
                                </div>
                                <div class="stride-stat-info">
                                    <div class="stride-stat-value" x-text="stats.upcomingEditions">-</div>
                                    <div class="stride-stat-label">Upcoming Editions</div>
                                </div>
                            </div>
                            <div class="stride-stat-card">
                                <div class="stride-stat-icon registrations">
                                    <span class="dashicons dashicons-groups"></span>
                                </div>
                                <div class="stride-stat-info">
                                    <div class="stride-stat-value" x-text="stats.totalRegistrations">-</div>
                                    <div class="stride-stat-label">Total Registrations</div>
                                </div>
                            </div>
                            <div class="stride-stat-card">
                                <div class="stride-stat-icon pending">
                                    <span class="dashicons dashicons-media-document"></span>
                                </div>
                                <div class="stride-stat-info">
                                    <div class="stride-stat-value" x-text="stats.pendingQuotes">-</div>
                                    <div class="stride-stat-label">Pending Quotes</div>
                                </div>
                            </div>
                            <div class="stride-stat-card">
                                <div class="stride-stat-icon today">
                                    <span class="dashicons dashicons-clock"></span>
                                </div>
                                <div class="stride-stat-info">
                                    <div class="stride-stat-value" x-text="stats.todaySessions">-</div>
                                    <div class="stride-stat-label">Sessions Today</div>
                                </div>
                            </div>
                        </div>

                        <!-- Quick Actions -->
                        <div class="stride-card">
                            <div class="stride-card-header">
                                <h3 class="stride-card-title">Quick Actions</h3>
                            </div>
                            <div class="stride-card-body stride-quick-actions">
                                <a href="#/editions" @click.prevent="view = 'editions'" class="stride-quick-action">
                                    <span class="dashicons dashicons-calendar-alt"></span>
                                    <span>View Editions</span>
                                </a>
                                <a href="<?php echo esc_url(admin_url('post-new.php?post_type=vad_edition')); ?>" class="stride-quick-action">
                                    <span class="dashicons dashicons-plus-alt"></span>
                                    <span>New Edition</span>
                                </a>
                                <a href="#/quotes" @click.prevent="view = 'quotes'" class="stride-quick-action">
                                    <span class="dashicons dashicons-media-document"></span>
                                    <span>View Quotes</span>
                                </a>
                            </div>
                        </div>
                    </div>
                </template>

                <!-- Editions View -->
                <template x-if="view === 'editions'">
                    <div>
                        <div class="stride-page-header">
                            <h2 class="stride-page-title">Editions</h2>
                        </div>
                        <div class="stride-card">
                            <div class="stride-card-body">
                                <p class="stride-muted">Edition list will be added in Task 4.</p>
                            </div>
                        </div>
                    </div>
                </template>

                <!-- Quotes View -->
                <template x-if="view === 'quotes'">
                    <div>
                        <div class="stride-page-header">
                            <h2 class="stride-page-title">Quotes</h2>
                        </div>
                        <div class="stride-card">
                            <div class="stride-card-body">
                                <p class="stride-muted">Quote list will be added in Task 5.</p>
                            </div>
                        </div>
                    </div>
                </template>
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
                user: StrideConfig.user,
                view: 'dashboard',
                loading: true,

                // Stats
                stats: {
                    upcomingEditions: 0,
                    totalRegistrations: 0,
                    pendingQuotes: 0,
                    todaySessions: 0
                },

                // Initialize
                init() {
                    this.parseHash();
                    window.addEventListener('hashchange', () => this.parseHash());
                    this.loadStats();
                },

                parseHash() {
                    const hash = window.location.hash.replace('#/', '') || 'dashboard';
                    this.view = hash;
                },

                async loadStats() {
                    this.loading = true;
                    try {
                        const response = await fetch(`${StrideConfig.apiUrl}/admin/stats`, {
                            headers: {
                                'X-WP-Nonce': StrideConfig.nonce
                            }
                        });
                        if (response.ok) {
                            this.stats = await response.json();
                        }
                    } catch (e) {
                        console.error('Failed to load stats:', e);
                    }
                    this.loading = false;
                }
            }));
        });
        </script>
        <?php
    }
}
