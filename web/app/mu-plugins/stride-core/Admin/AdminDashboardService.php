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
    private const CAPABILITY = 'edit_others_posts';

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

            /* ========================================
               EDITIONS VIEW STYLES
            ======================================== */

            /* Filters */
            .stride-filters {
                display: flex;
                gap: 16px;
                padding: 16px 20px;
                border-bottom: 1px solid var(--stride-border);
            }

            .stride-filter-group {
                display: flex;
                flex-direction: column;
                gap: 4px;
            }

            .stride-filter-label {
                font-size: 12px;
                font-weight: 500;
                color: var(--stride-text-muted);
                text-transform: uppercase;
                letter-spacing: 0.5px;
            }

            .stride-input,
            .stride-select {
                padding: 8px 12px;
                border: 1px solid var(--stride-border);
                border-radius: 6px;
                font-size: 14px;
                color: var(--stride-text);
                background: var(--stride-card);
                min-width: 200px;
            }

            .stride-input:focus,
            .stride-select:focus {
                outline: none;
                border-color: var(--stride-primary);
                box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
            }

            /* Table */
            .stride-table-wrapper {
                overflow-x: auto;
            }

            .stride-table {
                width: 100%;
                border-collapse: collapse;
            }

            .stride-table th,
            .stride-table td {
                padding: 12px 16px;
                text-align: left;
                border-bottom: 1px solid var(--stride-border);
            }

            .stride-table th {
                font-size: 12px;
                font-weight: 600;
                color: var(--stride-text-muted);
                text-transform: uppercase;
                letter-spacing: 0.5px;
                background: var(--stride-bg);
            }

            .stride-table tbody tr:hover {
                background: var(--stride-bg);
            }

            .stride-clickable {
                cursor: pointer;
            }

            .stride-edition-title {
                font-weight: 500;
                color: var(--stride-text);
            }

            /* Capacity indicator */
            .stride-capacity {
                font-weight: 500;
            }

            .stride-capacity.full {
                color: var(--stride-danger);
            }

            /* Badges */
            .stride-badge {
                display: inline-block;
                padding: 4px 10px;
                font-size: 12px;
                font-weight: 500;
                border-radius: 20px;
                text-transform: capitalize;
            }

            .stride-badge-sm {
                padding: 2px 8px;
                font-size: 11px;
            }

            .stride-badge-open {
                background: rgba(16, 185, 129, 0.1);
                color: var(--stride-success);
            }

            .stride-badge-full {
                background: rgba(245, 158, 11, 0.1);
                color: var(--stride-warning);
            }

            .stride-badge-cancelled {
                background: rgba(239, 68, 68, 0.1);
                color: var(--stride-danger);
            }

            .stride-badge-completed {
                background: rgba(99, 102, 241, 0.1);
                color: var(--stride-primary);
            }

            .stride-badge-confirmed {
                background: rgba(16, 185, 129, 0.1);
                color: var(--stride-success);
            }

            .stride-badge-pending {
                background: rgba(245, 158, 11, 0.1);
                color: var(--stride-warning);
            }

            /* Button variants */
            .stride-btn-primary {
                background: var(--stride-primary);
                color: #fff;
            }

            .stride-btn-primary:hover {
                background: var(--stride-primary-hover);
                color: #fff;
            }

            .stride-btn-sm {
                padding: 4px 12px;
                font-size: 13px;
            }

            .stride-btn-outline {
                background: transparent;
                border: 1px solid var(--stride-border);
                color: var(--stride-text);
            }

            .stride-btn-outline:hover {
                border-color: var(--stride-primary);
                color: var(--stride-primary);
            }

            /* Pagination */
            .stride-pagination {
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 16px;
                padding: 16px;
                border-top: 1px solid var(--stride-border);
            }

            .stride-page-btn {
                padding: 6px 12px;
                background: var(--stride-card);
                border: 1px solid var(--stride-border);
                border-radius: 6px;
                cursor: pointer;
                font-size: 14px;
                color: var(--stride-text);
            }

            .stride-page-btn:hover:not(:disabled) {
                border-color: var(--stride-primary);
                color: var(--stride-primary);
            }

            .stride-page-btn:disabled {
                opacity: 0.5;
                cursor: not-allowed;
            }

            .stride-page-info {
                font-size: 14px;
                color: var(--stride-text-muted);
            }

            /* Slide-over */
            .stride-slideover-backdrop {
                position: fixed;
                inset: 0;
                background: rgba(0, 0, 0, 0.3);
                z-index: 100000;
                display: flex;
                justify-content: flex-end;
            }

            .stride-slideover {
                width: 600px;
                max-width: 100%;
                background: var(--stride-card);
                height: 100%;
                display: flex;
                flex-direction: column;
                box-shadow: -4px 0 24px rgba(0, 0, 0, 0.1);
            }

            .stride-slideover-header {
                display: flex;
                align-items: center;
                justify-content: space-between;
                padding: 20px 24px;
                border-bottom: 1px solid var(--stride-border);
            }

            .stride-slideover-header h3 {
                margin: 0;
                font-size: 18px;
                font-weight: 600;
                color: var(--stride-text);
            }

            .stride-slideover-close {
                width: 32px;
                height: 32px;
                border: none;
                background: none;
                font-size: 24px;
                color: var(--stride-text-muted);
                cursor: pointer;
                display: flex;
                align-items: center;
                justify-content: center;
                border-radius: 6px;
            }

            .stride-slideover-close:hover {
                background: var(--stride-bg);
                color: var(--stride-text);
            }

            .stride-slideover-tabs {
                display: flex;
                border-bottom: 1px solid var(--stride-border);
                padding: 0 24px;
            }

            .stride-slideover-tab {
                padding: 12px 16px;
                background: none;
                border: none;
                border-bottom: 2px solid transparent;
                font-size: 14px;
                font-weight: 500;
                color: var(--stride-text-muted);
                cursor: pointer;
                margin-bottom: -1px;
            }

            .stride-slideover-tab:hover {
                color: var(--stride-text);
            }

            .stride-slideover-tab.active {
                color: var(--stride-primary);
                border-bottom-color: var(--stride-primary);
            }

            .stride-slideover-body {
                flex: 1;
                overflow-y: auto;
                padding: 24px;
            }

            /* Student list */
            .stride-student-list {
                display: flex;
                flex-direction: column;
                gap: 12px;
            }

            .stride-student-item {
                display: flex;
                align-items: center;
                gap: 12px;
                padding: 12px;
                background: var(--stride-bg);
                border-radius: 8px;
            }

            .stride-student-avatar {
                width: 40px;
                height: 40px;
                border-radius: 50%;
                background: var(--stride-primary);
                color: #fff;
                display: flex;
                align-items: center;
                justify-content: center;
                font-weight: 600;
                font-size: 16px;
            }

            .stride-student-info {
                flex: 1;
            }

            .stride-student-name {
                font-weight: 500;
                color: var(--stride-text);
            }

            .stride-student-email {
                font-size: 13px;
                color: var(--stride-text-muted);
            }

            /* Empty state small */
            .stride-empty-sm {
                text-align: center;
                padding: 32px 16px;
                color: var(--stride-text-muted);
            }

            /* Attendance grid */
            .stride-attendance-grid {
                overflow-x: auto;
            }

            .stride-table-compact th,
            .stride-table-compact td {
                padding: 8px 12px;
            }

            .stride-attendance-header {
                text-align: center !important;
                min-width: 70px;
            }

            .stride-attendance-cell {
                text-align: center !important;
                padding: 4px !important;
            }

            .stride-attendance-btn {
                width: 32px;
                height: 32px;
                border: 1px solid var(--stride-border);
                background: var(--stride-card);
                border-radius: 6px;
                cursor: pointer;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                color: var(--stride-text-muted);
                transition: all 0.15s ease;
            }

            .stride-attendance-btn:hover {
                border-color: var(--stride-primary);
            }

            .stride-attendance-btn .dashicons {
                font-size: 18px;
                width: 18px;
                height: 18px;
            }

            .stride-attendance-btn.present {
                background: rgba(16, 185, 129, 0.1);
                border-color: var(--stride-success);
                color: var(--stride-success);
            }

            .stride-attendance-btn.absent {
                background: rgba(239, 68, 68, 0.1);
                border-color: var(--stride-danger);
                color: var(--stride-danger);
            }

            .stride-attendance-btn.excused {
                background: rgba(245, 158, 11, 0.1);
                border-color: var(--stride-warning);
                color: var(--stride-warning);
            }

            /* Info list */
            .stride-info-list {
                display: flex;
                flex-direction: column;
                gap: 16px;
            }

            .stride-info-row {
                display: flex;
                justify-content: space-between;
                padding: 12px 0;
                border-bottom: 1px solid var(--stride-border);
            }

            .stride-info-row:last-child {
                border-bottom: none;
            }

            .stride-info-label {
                font-weight: 500;
                color: var(--stride-text-muted);
            }

            /* Quote specific */
            .stride-quote-number {
                font-family: monospace;
                font-weight: 500;
            }

            .stride-customer-name {
                font-weight: 500;
            }

            .stride-customer-email {
                font-size: 13px;
                color: var(--stride-text-muted);
            }

            .stride-amount {
                font-variant-numeric: tabular-nums;
                font-weight: 500;
            }

            /* Quote status badges */
            .stride-badge-draft {
                background: rgba(100, 116, 139, 0.1);
                color: var(--stride-text-muted);
            }

            .stride-badge-sent {
                background: rgba(59, 130, 246, 0.1);
                color: var(--stride-info);
            }

            .stride-badge-exported {
                background: rgba(16, 185, 129, 0.1);
                color: var(--stride-success);
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
                            <a href="<?php echo esc_url(admin_url('post-new.php?post_type=vad_edition')); ?>" class="stride-btn stride-btn-primary">
                                <span class="dashicons dashicons-plus-alt2"></span>
                                New Edition
                            </a>
                        </div>

                        <!-- Filters -->
                        <div class="stride-card">
                            <div class="stride-filters">
                                <div class="stride-filter-group">
                                    <label class="stride-filter-label">Search</label>
                                    <input type="text" class="stride-input" placeholder="Search editions..." x-model="editionFilters.search" @input.debounce.300ms="loadEditions()">
                                </div>
                                <div class="stride-filter-group">
                                    <label class="stride-filter-label">Status</label>
                                    <select class="stride-select" x-model="editionFilters.status" @change="loadEditions()">
                                        <option value="">All Statuses</option>
                                        <option value="open">Open</option>
                                        <option value="full">Full</option>
                                        <option value="cancelled">Cancelled</option>
                                        <option value="completed">Completed</option>
                                    </select>
                                </div>
                            </div>

                            <!-- Table -->
                            <div class="stride-table-wrapper">
                                <template x-if="editionsLoading">
                                    <div class="stride-loading">Loading editions...</div>
                                </template>
                                <template x-if="!editionsLoading && editions.length === 0">
                                    <div class="stride-empty">
                                        <span class="dashicons dashicons-calendar-alt stride-empty-icon"></span>
                                        <p>No editions found</p>
                                    </div>
                                </template>
                                <template x-if="!editionsLoading && editions.length > 0">
                                    <table class="stride-table">
                                        <thead>
                                            <tr>
                                                <th>Edition</th>
                                                <th>Date</th>
                                                <th>Venue</th>
                                                <th>Capacity</th>
                                                <th>Status</th>
                                                <th></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <template x-for="edition in editions" :key="edition.id">
                                                <tr @click="openEdition(edition.id)" class="stride-clickable">
                                                    <td>
                                                        <div class="stride-edition-title" x-text="edition.title"></div>
                                                    </td>
                                                    <td>
                                                        <span x-text="formatDate(edition.startDate)"></span>
                                                        <template x-if="edition.endDate && edition.endDate !== edition.startDate">
                                                            <span x-text="' - ' + formatDate(edition.endDate)"></span>
                                                        </template>
                                                    </td>
                                                    <td x-text="edition.venue || '-'"></td>
                                                    <td>
                                                        <span class="stride-capacity" :class="{ 'full': edition.registeredCount >= edition.capacity }">
                                                            <span x-text="edition.registeredCount"></span>/<span x-text="edition.capacity"></span>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <span class="stride-badge" :class="'stride-badge-' + edition.status" x-text="edition.status"></span>
                                                    </td>
                                                    <td>
                                                        <a :href="edition.editUrl" class="stride-btn stride-btn-sm stride-btn-outline" @click.stop>
                                                            Edit
                                                        </a>
                                                    </td>
                                                </tr>
                                            </template>
                                        </tbody>
                                    </table>
                                </template>

                                <!-- Pagination -->
                                <template x-if="editionPages > 1">
                                    <div class="stride-pagination">
                                        <button class="stride-page-btn" @click="editionPage--; loadEditions()" :disabled="editionPage === 1">&laquo;</button>
                                        <span class="stride-page-info">Page <span x-text="editionPage"></span> of <span x-text="editionPages"></span></span>
                                        <button class="stride-page-btn" @click="editionPage++; loadEditions()" :disabled="editionPage >= editionPages">&raquo;</button>
                                    </div>
                                </template>
                            </div>
                        </div>

                        <!-- Edition Detail Slide-over -->
                        <template x-if="selectedEdition">
                            <div class="stride-slideover-backdrop" @click.self="selectedEdition = null">
                                <div class="stride-slideover">
                                    <div class="stride-slideover-header">
                                        <h3 x-text="selectedEdition.title"></h3>
                                        <button class="stride-slideover-close" @click="selectedEdition = null">&times;</button>
                                    </div>
                                    <div class="stride-slideover-tabs">
                                        <button class="stride-slideover-tab" :class="{ 'active': editionTab === 'students' }" @click="editionTab = 'students'">
                                            Students
                                        </button>
                                        <button class="stride-slideover-tab" :class="{ 'active': editionTab === 'attendance' }" @click="editionTab = 'attendance'">
                                            Attendance
                                        </button>
                                        <button class="stride-slideover-tab" :class="{ 'active': editionTab === 'info' }" @click="editionTab = 'info'">
                                            Info
                                        </button>
                                    </div>
                                    <div class="stride-slideover-body">
                                        <!-- Students Tab -->
                                        <template x-if="editionTab === 'students'">
                                            <div>
                                                <template x-if="registrationsLoading">
                                                    <div class="stride-loading">Loading students...</div>
                                                </template>
                                                <template x-if="!registrationsLoading && registrations.length === 0">
                                                    <div class="stride-empty-sm">No students registered</div>
                                                </template>
                                                <template x-if="!registrationsLoading && registrations.length > 0">
                                                    <div class="stride-student-list">
                                                        <template x-for="reg in registrations" :key="reg.id">
                                                            <div class="stride-student-item">
                                                                <div class="stride-student-avatar" x-text="reg.name ? reg.name.charAt(0).toUpperCase() : '?'"></div>
                                                                <div class="stride-student-info">
                                                                    <div class="stride-student-name" x-text="reg.name || 'Unknown'"></div>
                                                                    <div class="stride-student-email" x-text="reg.email || ''"></div>
                                                                </div>
                                                                <span class="stride-badge stride-badge-sm" :class="'stride-badge-' + reg.status" x-text="reg.status"></span>
                                                            </div>
                                                        </template>
                                                    </div>
                                                </template>
                                            </div>
                                        </template>

                                        <!-- Attendance Tab -->
                                        <template x-if="editionTab === 'attendance'">
                                            <div>
                                                <template x-if="selectedEdition.sessions && selectedEdition.sessions.length > 0">
                                                    <div class="stride-attendance-grid">
                                                        <table class="stride-table stride-table-compact">
                                                            <thead>
                                                                <tr>
                                                                    <th>Student</th>
                                                                    <template x-for="session in selectedEdition.sessions" :key="session.id">
                                                                        <th class="stride-attendance-header">
                                                                            <div x-text="formatShortDate(session.date)"></div>
                                                                        </th>
                                                                    </template>
                                                                </tr>
                                                            </thead>
                                                            <tbody>
                                                                <template x-for="reg in registrations" :key="reg.id">
                                                                    <tr>
                                                                        <td x-text="reg.name || 'Unknown'"></td>
                                                                        <template x-for="session in selectedEdition.sessions" :key="session.id">
                                                                            <td class="stride-attendance-cell">
                                                                                <button
                                                                                    class="stride-attendance-btn"
                                                                                    :class="{
                                                                                        'present': reg.attendance && reg.attendance[session.id] === 'present',
                                                                                        'absent': reg.attendance && reg.attendance[session.id] === 'absent',
                                                                                        'excused': reg.attendance && reg.attendance[session.id] === 'excused'
                                                                                    }"
                                                                                    @click="toggleAttendance(session.id, reg.userId, reg.attendance ? reg.attendance[session.id] : null)"
                                                                                >
                                                                                    <template x-if="reg.attendance && reg.attendance[session.id] === 'present'">
                                                                                        <span class="dashicons dashicons-yes"></span>
                                                                                    </template>
                                                                                    <template x-if="reg.attendance && reg.attendance[session.id] === 'absent'">
                                                                                        <span class="dashicons dashicons-no"></span>
                                                                                    </template>
                                                                                    <template x-if="reg.attendance && reg.attendance[session.id] === 'excused'">
                                                                                        <span class="dashicons dashicons-clock"></span>
                                                                                    </template>
                                                                                    <template x-if="!reg.attendance || !reg.attendance[session.id]">
                                                                                        <span class="dashicons dashicons-minus"></span>
                                                                                    </template>
                                                                                </button>
                                                                            </td>
                                                                        </template>
                                                                    </tr>
                                                                </template>
                                                            </tbody>
                                                        </table>
                                                    </div>
                                                </template>
                                                <template x-if="!selectedEdition.sessions || selectedEdition.sessions.length === 0">
                                                    <div class="stride-empty-sm">No sessions defined</div>
                                                </template>
                                            </div>
                                        </template>

                                        <!-- Info Tab -->
                                        <template x-if="editionTab === 'info'">
                                            <div class="stride-info-list">
                                                <div class="stride-info-row">
                                                    <span class="stride-info-label">Start Date</span>
                                                    <span x-text="formatDate(selectedEdition.startDate)"></span>
                                                </div>
                                                <div class="stride-info-row">
                                                    <span class="stride-info-label">End Date</span>
                                                    <span x-text="formatDate(selectedEdition.endDate) || '-'"></span>
                                                </div>
                                                <div class="stride-info-row">
                                                    <span class="stride-info-label">Venue</span>
                                                    <span x-text="selectedEdition.venue || '-'"></span>
                                                </div>
                                                <div class="stride-info-row">
                                                    <span class="stride-info-label">Capacity</span>
                                                    <span x-text="selectedEdition.registeredCount + '/' + selectedEdition.capacity"></span>
                                                </div>
                                                <div class="stride-info-row">
                                                    <span class="stride-info-label">Status</span>
                                                    <span class="stride-badge" :class="'stride-badge-' + selectedEdition.status" x-text="selectedEdition.status"></span>
                                                </div>
                                            </div>
                                        </template>
                                    </div>
                                </div>
                            </div>
                        </template>
                    </div>
                </template>

                <!-- Quotes View -->
                <template x-if="view === 'quotes'">
                    <div>
                        <div class="stride-page-header">
                            <h2 class="stride-page-title">Quotes</h2>
                        </div>

                        <!-- Filters -->
                        <div class="stride-card">
                            <div class="stride-filters">
                                <div class="stride-filter-group">
                                    <label class="stride-filter-label">Search</label>
                                    <input type="text" class="stride-input" placeholder="Quote number or name..." x-model="quoteFilters.search" @input.debounce.300ms="loadQuotes()">
                                </div>
                                <div class="stride-filter-group">
                                    <label class="stride-filter-label">Status</label>
                                    <select class="stride-select" x-model="quoteFilters.status" @change="loadQuotes()">
                                        <option value="">All Statuses</option>
                                        <option value="draft">Draft</option>
                                        <option value="sent">Sent</option>
                                        <option value="exported">Exported</option>
                                    </select>
                                </div>
                            </div>

                            <!-- Table -->
                            <div class="stride-table-wrapper">
                                <template x-if="quotesLoading">
                                    <div class="stride-loading">Loading quotes...</div>
                                </template>
                                <template x-if="!quotesLoading && quotes.length === 0">
                                    <div class="stride-empty">
                                        <span class="dashicons dashicons-media-document stride-empty-icon"></span>
                                        <p>No quotes found</p>
                                    </div>
                                </template>
                                <template x-if="!quotesLoading && quotes.length > 0">
                                    <table class="stride-table">
                                        <thead>
                                            <tr>
                                                <th>Quote #</th>
                                                <th>Customer</th>
                                                <th>Date</th>
                                                <th>Total</th>
                                                <th>Status</th>
                                                <th></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <template x-for="quote in quotes" :key="quote.id">
                                                <tr>
                                                    <td>
                                                        <span class="stride-quote-number" x-text="quote.number"></span>
                                                    </td>
                                                    <td>
                                                        <div class="stride-customer-name" x-text="quote.userName || 'Unknown'"></div>
                                                        <div class="stride-customer-email" x-text="quote.userEmail || ''"></div>
                                                    </td>
                                                    <td x-text="formatDate(quote.date)"></td>
                                                    <td>
                                                        <span class="stride-amount" x-text="formatCurrency(quote.total)"></span>
                                                    </td>
                                                    <td>
                                                        <span class="stride-badge" :class="'stride-badge-' + quote.status" x-text="quote.status"></span>
                                                    </td>
                                                    <td>
                                                        <a :href="quote.editUrl" class="stride-btn stride-btn-sm stride-btn-outline">
                                                            View
                                                        </a>
                                                    </td>
                                                </tr>
                                            </template>
                                        </tbody>
                                    </table>
                                </template>

                                <!-- Pagination -->
                                <template x-if="quotePages > 1">
                                    <div class="stride-pagination">
                                        <button class="stride-page-btn" @click="quotePage--; loadQuotes()" :disabled="quotePage === 1">&laquo;</button>
                                        <span class="stride-page-info">Page <span x-text="quotePage"></span> of <span x-text="quotePages"></span></span>
                                        <button class="stride-page-btn" @click="quotePage++; loadQuotes()" :disabled="quotePage >= quotePages">&raquo;</button>
                                    </div>
                                </template>
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

                // Editions state
                editions: [],
                editionsLoading: false,
                editionFilters: { search: '', status: '' },
                editionPage: 1,
                editionPages: 1,
                selectedEdition: null,
                editionTab: 'students',
                registrations: [],
                registrationsLoading: false,

                // Quotes state
                quotes: [],
                quotesLoading: false,
                quoteFilters: { search: '', status: '' },
                quotePage: 1,
                quotePages: 1,

                // Initialize
                init() {
                    this.parseHash();
                    window.addEventListener('hashchange', () => this.parseHash());
                    this.loadStats();
                },

                parseHash() {
                    const hash = window.location.hash.replace('#/', '') || 'dashboard';
                    this.view = hash;

                    // Load editions when switching to editions view
                    if (hash === 'editions' && this.editions.length === 0) {
                        this.loadEditions();
                    } else if (hash === 'quotes' && this.quotes.length === 0) {
                        this.loadQuotes();
                    }
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
                },

                async loadEditions() {
                    this.editionsLoading = true;
                    try {
                        const params = new URLSearchParams({
                            page: this.editionPage,
                            per_page: 20
                        });
                        if (this.editionFilters.search) {
                            params.append('search', this.editionFilters.search);
                        }
                        if (this.editionFilters.status) {
                            params.append('status', this.editionFilters.status);
                        }

                        const response = await fetch(`${StrideConfig.apiUrl}/admin/editions?${params}`, {
                            headers: {
                                'X-WP-Nonce': StrideConfig.nonce
                            }
                        });
                        if (response.ok) {
                            const data = await response.json();
                            this.editions = data.items || [];
                            this.editionPages = data.pages || 1;
                        }
                    } catch (e) {
                        console.error('Failed to load editions:', e);
                    }
                    this.editionsLoading = false;
                },

                async openEdition(id) {
                    try {
                        const response = await fetch(`${StrideConfig.apiUrl}/admin/editions/${id}`, {
                            headers: {
                                'X-WP-Nonce': StrideConfig.nonce
                            }
                        });
                        if (response.ok) {
                            this.selectedEdition = await response.json();
                            this.editionTab = 'students';
                            this.loadRegistrations(id);
                        }
                    } catch (e) {
                        console.error('Failed to load edition:', e);
                    }
                },

                async loadRegistrations(editionId) {
                    this.registrationsLoading = true;
                    this.registrations = [];
                    try {
                        const response = await fetch(`${StrideConfig.apiUrl}/admin/editions/${editionId}/registrations`, {
                            headers: {
                                'X-WP-Nonce': StrideConfig.nonce
                            }
                        });
                        if (response.ok) {
                            this.registrations = await response.json();
                        }
                    } catch (e) {
                        console.error('Failed to load registrations:', e);
                    }
                    this.registrationsLoading = false;
                },

                async toggleAttendance(sessionId, userId, currentStatus) {
                    // Cycle: null -> present -> absent -> excused -> null
                    const statusCycle = [null, 'present', 'absent', 'excused'];
                    const currentIndex = statusCycle.indexOf(currentStatus);
                    const nextStatus = statusCycle[(currentIndex + 1) % statusCycle.length];

                    try {
                        const response = await fetch(`${StrideConfig.apiUrl}/admin/attendance`, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-WP-Nonce': StrideConfig.nonce
                            },
                            body: JSON.stringify({
                                session_id: sessionId,
                                user_id: userId,
                                status: nextStatus
                            })
                        });

                        if (response.ok) {
                            // Update local state
                            const reg = this.registrations.find(r => r.userId === userId);
                            if (reg) {
                                if (!reg.attendance) {
                                    reg.attendance = {};
                                }
                                if (nextStatus) {
                                    reg.attendance[sessionId] = nextStatus;
                                } else {
                                    delete reg.attendance[sessionId];
                                }
                            }
                        }
                    } catch (e) {
                        console.error('Failed to update attendance:', e);
                    }
                },

                formatDate(dateStr) {
                    if (!dateStr) return '';
                    const date = new Date(dateStr);
                    const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
                    return `${date.getDate()} ${months[date.getMonth()]} ${date.getFullYear()}`;
                },

                formatShortDate(dateStr) {
                    if (!dateStr) return '';
                    const date = new Date(dateStr);
                    const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
                    return `${date.getDate()} ${months[date.getMonth()]}`;
                },

                async loadQuotes() {
                    this.quotesLoading = true;
                    try {
                        const params = new URLSearchParams({
                            page: this.quotePage,
                            search: this.quoteFilters.search,
                            status: this.quoteFilters.status
                        });
                        const response = await fetch(`${StrideConfig.apiUrl}/admin/quotes?${params}`, {
                            headers: { 'X-WP-Nonce': StrideConfig.nonce }
                        });
                        if (response.ok) {
                            const data = await response.json();
                            this.quotes = data.items;
                            this.quotePages = data.pages;
                        }
                    } catch (e) {
                        console.error('Failed to load quotes:', e);
                    }
                    this.quotesLoading = false;
                },

                formatCurrency(amount) {
                    return new Intl.NumberFormat('nl-BE', { style: 'currency', currency: 'EUR' }).format(amount || 0);
                }
            }));
        });
        </script>
        <?php
    }
}
