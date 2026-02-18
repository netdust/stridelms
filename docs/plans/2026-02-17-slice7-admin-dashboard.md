# Slice 7: Admin Dashboard Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Build a modern, full-screen admin dashboard using Alpine.js with WordPress sidebar collapsed, featuring edition management, student attendance, and quote tracking.

**Architecture:** Full-screen Alpine.js SPA within WordPress admin. Uses `folded` body class to collapse WP sidebar, hides admin bar/notices via CSS. Hash-based navigation for tabs. REST API endpoints for data operations.

**Tech Stack:** Alpine.js 3.x, WordPress REST API, CSS custom properties, PHP service layer

**Reference:** Acerta dashboard at `/home/ntdst/Sites/acerta/web/app/themes/ntdstheme/services/acerta/ManagerDashboardService.php`

---

## Overview

The admin dashboard provides:
- **Edition List** - All editions with status, dates, capacity
- **Edition Detail** - Tabs for students, attendance, quotes, sessions
- **Quick Actions** - Mark attendance, send emails, export data
- **Stats** - Overview of upcoming editions, pending registrations

## Design Principles

- **Professional + Fun**: Clean typography, subtle animations, friendly colors
- **Mobile-friendly**: Works on tablets for trainers marking attendance
- **Fast**: Alpine.js reactivity, no page reloads
- **Focused**: One task at a time, clear visual hierarchy

---

## Task 1: AdminDashboardService Base

**Files:**
- Create: `web/app/mu-plugins/stride-core/Admin/AdminDashboardService.php`
- Modify: `web/app/mu-plugins/stride-core/plugin-config.php`

**Step 1: Create the service file**

```php
<?php

declare(strict_types=1);

namespace Stride\Admin;

use Stride\Infrastructure\AbstractService;

/**
 * Full-screen Alpine.js admin dashboard.
 *
 * Pattern from Acerta: uses WordPress 'folded' body class + CSS to create
 * a clean, app-like experience within WP admin.
 */
final class AdminDashboardService extends AbstractService
{
    private const MENU_SLUG = 'stride-dashboard';
    private const CAPABILITY = 'edit_posts';

    public static function metadata(): array
    {
        return [
            'name' => 'Admin Dashboard',
            'description' => 'Full-screen Alpine.js admin dashboard',
            'admin_only' => true,
            'priority' => 5,
        ];
    }

    protected function getConfigSlug(): string
    {
        return 'admin_dashboard';
    }

    protected function init(): void
    {
        add_action('admin_menu', [$this, 'registerMenu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('admin_head', [$this, 'injectStyles']);
        add_action('admin_footer', [$this, 'injectScripts']);
        add_filter('admin_body_class', [$this, 'addBodyClasses']);
    }

    /**
     * Check if we're on the dashboard page.
     */
    private function isDashboardPage(): bool
    {
        $screen = get_current_screen();
        if (!$screen) {
            return isset($_GET['page']) && strpos($_GET['page'], self::MENU_SLUG) === 0;
        }

        return strpos($screen->id, self::MENU_SLUG) !== false;
    }

    /**
     * Register admin menu.
     */
    public function registerMenu(): void
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

        // Submenus with hash routing
        add_submenu_page(
            self::MENU_SLUG,
            'Dashboard',
            'Dashboard',
            self::CAPABILITY,
            self::MENU_SLUG,
            [$this, 'renderDashboard']
        );

        add_submenu_page(
            self::MENU_SLUG,
            'Editions',
            'Editions',
            self::CAPABILITY,
            self::MENU_SLUG . '#/editions',
            [$this, 'renderDashboard']
        );

        add_submenu_page(
            self::MENU_SLUG,
            'Quotes',
            'Quotes',
            self::CAPABILITY,
            self::MENU_SLUG . '#/quotes',
            [$this, 'renderDashboard']
        );
    }

    /**
     * Add body classes for our page.
     */
    public function addBodyClasses(string $classes): string
    {
        if ($this->isDashboardPage()) {
            $classes .= ' stride-dashboard folded';
        }

        return $classes;
    }

    /**
     * Enqueue Alpine.js.
     */
    public function enqueueAssets(string $hook): void
    {
        if (!$this->isDashboardPage()) {
            return;
        }

        wp_enqueue_script(
            'alpinejs',
            'https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js',
            [],
            '3.14.0',
            ['strategy' => 'defer']
        );

        wp_localize_script('alpinejs', 'StrideConfig', $this->getJsConfig());
    }

    /**
     * Get JS configuration.
     */
    private function getJsConfig(): array
    {
        return [
            'apiUrl' => rest_url('stride/v1'),
            'nonce' => wp_create_nonce('wp_rest'),
            'user' => [
                'id' => get_current_user_id(),
                'name' => wp_get_current_user()->display_name,
            ],
        ];
    }

    /**
     * Inject CSS styles.
     */
    public function injectStyles(): void
    {
        if (!$this->isDashboardPage()) {
            return;
        }

        echo '<style id="stride-dashboard-styles">';
        $this->renderCSS();
        echo '</style>';
    }

    /**
     * Inject Alpine.js app.
     */
    public function injectScripts(): void
    {
        if (!$this->isDashboardPage()) {
            return;
        }

        echo '<script>';
        $this->renderJS();
        echo '</script>';
    }

    /**
     * Render dashboard HTML.
     */
    public function renderDashboard(): void
    {
        ?>
        <div class="wrap stride-app" x-data="strideApp()">
            <!-- Header -->
            <header class="stride-header">
                <div class="stride-header-left">
                    <h1>Stride</h1>
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
                <p>Dashboard content will be added in subsequent tasks.</p>
            </div>
        </div>
        <?php
    }

    /**
     * Render CSS.
     */
    private function renderCSS(): void
    {
        ?>
        /* CSS Variables */
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

        /* Hide WordPress UI */
        body.stride-dashboard #wpadminbar,
        body.stride-dashboard #wpfooter,
        body.stride-dashboard .notice,
        body.stride-dashboard .update-nag,
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
            margin-left: 160px;
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

        /* App Layout */
        .stride-app {
            display: flex;
            flex-direction: column;
            height: 100vh;
            background: var(--stride-bg);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            color: var(--stride-text);
            font-size: 14px;
            line-height: 1.5;
        }

        /* Header */
        .stride-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 24px;
            height: 56px;
            background: var(--stride-primary);
            flex-shrink: 0;
        }

        .stride-header-left {
            display: flex;
            align-items: center;
            gap: 24px;
        }

        .stride-header h1 {
            font-size: 20px;
            font-weight: 700;
            margin: 0;
            color: #fff;
            letter-spacing: -0.02em;
        }

        .stride-header-right {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .stride-user-name {
            color: rgba(255,255,255,0.9);
            font-size: 14px;
        }

        /* Content */
        .stride-content {
            flex: 1;
            overflow-y: auto;
            padding: 24px;
        }

        /* Buttons */
        .stride-btn {
            padding: 8px 16px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            border: none;
            transition: all 0.15s ease;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            text-decoration: none;
        }

        .stride-btn-primary {
            background: var(--stride-primary);
            color: #fff;
        }

        .stride-btn-primary:hover {
            background: var(--stride-primary-hover);
            color: #fff;
        }

        .stride-btn-ghost {
            background: rgba(255,255,255,0.15);
            color: #fff;
        }

        .stride-btn-ghost:hover {
            background: rgba(255,255,255,0.25);
            color: #fff;
        }
        <?php
    }

    /**
     * Render JavaScript.
     */
    private function renderJS(): void
    {
        ?>
        document.addEventListener('alpine:init', () => {
            Alpine.data('strideApp', () => ({
                // State
                user: StrideConfig.user,
                loading: true,

                // Initialize
                init() {
                    this.loading = false;
                    console.log('Stride Dashboard initialized');
                }
            }));
        });
        <?php
    }
}
```

**Step 2: Register the service**

Add to `plugin-config.php` services array:

```php
\Stride\Admin\AdminDashboardService::class,
```

**Step 3: Test**

Run: `ddev launch /wp/wp-admin/admin.php?page=stride-dashboard`
Expected: Full-screen dashboard with purple header showing "Stride" and user name

**Step 4: Commit**

```bash
git add web/app/mu-plugins/stride-core/Admin/AdminDashboardService.php
git add web/app/mu-plugins/stride-core/plugin-config.php
git commit -m "feat(admin): add AdminDashboardService base"
```

---

## Task 2: Dashboard Navigation & Tabs

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Admin/AdminDashboardService.php`

**Step 1: Add tab navigation to renderDashboard()**

Replace the `renderDashboard()` method:

```php
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
                            <a href="<?php echo admin_url('post-new.php?post_type=vad_edition'); ?>" class="stride-quick-action">
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
                            <p class="stride-muted">Edition list will be added in Task 3.</p>
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
```

**Step 2: Add navigation CSS to renderCSS()**

Add after the existing CSS:

```php
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

/* Page Header */
.stride-page-header {
    margin-bottom: 24px;
}

.stride-page-title {
    font-size: 24px;
    font-weight: 600;
    margin: 0;
    color: var(--stride-text);
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

/* Cards */
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
    padding: 16px 20px;
    border-bottom: 1px solid var(--stride-border);
}

.stride-card-title {
    font-size: 16px;
    font-weight: 600;
    margin: 0;
}

.stride-card-body {
    padding: 20px;
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
```

**Step 3: Update JavaScript in renderJS()**

```php
private function renderJS(): void
{
    ?>
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
    <?php
}
```

**Step 4: Test**

Run: `ddev launch /wp/wp-admin/admin.php?page=stride-dashboard`
Expected: Dashboard with navigation tabs, stats cards (showing "-"), quick actions

**Step 5: Commit**

```bash
git add web/app/mu-plugins/stride-core/Admin/AdminDashboardService.php
git commit -m "feat(admin): add dashboard navigation and stats layout"
```

---

## Task 3: Admin REST API Endpoints

**Files:**
- Create: `web/app/mu-plugins/stride-core/Admin/AdminAPIController.php`
- Modify: `web/app/mu-plugins/stride-core/plugin-config.php`

**Step 1: Create the API controller**

```php
<?php

declare(strict_types=1);

namespace Stride\Admin;

use Stride\Infrastructure\AbstractService;
use Stride\Modules\Edition\EditionRepository;
use Stride\Modules\Edition\SessionRepository;
use Stride\Modules\Enrollment\RegistrationRepository;
use Stride\Modules\Invoicing\QuoteRepository;
use Stride\Modules\Attendance\AttendanceRepository;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * REST API endpoints for admin dashboard.
 */
final class AdminAPIController extends AbstractService
{
    private const NAMESPACE = 'stride/v1';

    public function __construct(
        private readonly EditionRepository $editions,
        private readonly SessionRepository $sessions,
        private readonly RegistrationRepository $registrations,
        private readonly QuoteRepository $quotes,
        private readonly AttendanceRepository $attendance,
    ) {
        parent::__construct();
    }

    public static function metadata(): array
    {
        return [
            'name' => 'Admin API Controller',
            'description' => 'REST API for admin dashboard',
            'admin_only' => true,
            'priority' => 6,
        ];
    }

    protected function getConfigSlug(): string
    {
        return 'admin_api';
    }

    protected function init(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    /**
     * Register REST routes.
     */
    public function registerRoutes(): void
    {
        // Dashboard stats
        register_rest_route(self::NAMESPACE, '/admin/stats', [
            'methods' => 'GET',
            'callback' => [$this, 'getStats'],
            'permission_callback' => [$this, 'canAccessAdmin'],
        ]);

        // Editions list
        register_rest_route(self::NAMESPACE, '/admin/editions', [
            'methods' => 'GET',
            'callback' => [$this, 'getEditions'],
            'permission_callback' => [$this, 'canAccessAdmin'],
        ]);

        // Edition detail
        register_rest_route(self::NAMESPACE, '/admin/editions/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [$this, 'getEdition'],
            'permission_callback' => [$this, 'canAccessAdmin'],
        ]);

        // Edition registrations
        register_rest_route(self::NAMESPACE, '/admin/editions/(?P<id>\d+)/registrations', [
            'methods' => 'GET',
            'callback' => [$this, 'getEditionRegistrations'],
            'permission_callback' => [$this, 'canAccessAdmin'],
        ]);

        // Mark attendance
        register_rest_route(self::NAMESPACE, '/admin/attendance', [
            'methods' => 'POST',
            'callback' => [$this, 'markAttendance'],
            'permission_callback' => [$this, 'canAccessAdmin'],
        ]);

        // Quotes list
        register_rest_route(self::NAMESPACE, '/admin/quotes', [
            'methods' => 'GET',
            'callback' => [$this, 'getQuotes'],
            'permission_callback' => [$this, 'canAccessAdmin'],
        ]);
    }

    /**
     * Check if user can access admin.
     */
    public function canAccessAdmin(): bool
    {
        return current_user_can('edit_posts');
    }

    /**
     * GET /admin/stats
     */
    public function getStats(WP_REST_Request $request): WP_REST_Response
    {
        global $wpdb;

        // Upcoming editions count
        $upcomingEditions = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_vad_start_date'
             WHERE p.post_type = 'vad_edition' AND p.post_status = 'publish'
             AND pm.meta_value >= %s",
            current_time('Y-m-d')
        ));

        // Total active registrations
        $totalRegistrations = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}vad_registrations WHERE status = 'confirmed'"
        );

        // Pending quotes (draft status)
        $pendingQuotes = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_vad_status'
             WHERE p.post_type = 'vad_quote' AND p.post_status = 'publish'
             AND pm.meta_value = %s",
            'draft'
        ));

        // Sessions today
        $todaySessions = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_vad_date'
             WHERE p.post_type = 'vad_session' AND p.post_status = 'publish'
             AND pm.meta_value = %s",
            current_time('Y-m-d')
        ));

        return new WP_REST_Response([
            'upcomingEditions' => (int) $upcomingEditions,
            'totalRegistrations' => (int) $totalRegistrations,
            'pendingQuotes' => (int) $pendingQuotes,
            'todaySessions' => (int) $todaySessions,
        ]);
    }

    /**
     * GET /admin/editions
     */
    public function getEditions(WP_REST_Request $request): WP_REST_Response
    {
        $status = $request->get_param('status');
        $search = $request->get_param('search');
        $page = max(1, (int) $request->get_param('page') ?: 1);
        $perPage = 20;

        global $wpdb;

        $where = ["p.post_type = 'vad_edition'", "p.post_status = 'publish'"];
        $params = [];

        if ($search) {
            $where[] = "p.post_title LIKE %s";
            $params[] = '%' . $wpdb->esc_like($search) . '%';
        }

        $whereClause = implode(' AND ', $where);
        $offset = ($page - 1) * $perPage;

        // Get total
        $totalQuery = "SELECT COUNT(*) FROM {$wpdb->posts} p WHERE {$whereClause}";
        $total = (int) $wpdb->get_var($params ? $wpdb->prepare($totalQuery, ...$params) : $totalQuery);

        // Get editions
        $query = "SELECT p.ID, p.post_title
                  FROM {$wpdb->posts} p
                  LEFT JOIN {$wpdb->postmeta} pm_date ON p.ID = pm_date.post_id AND pm_date.meta_key = '_vad_start_date'
                  WHERE {$whereClause}
                  ORDER BY pm_date.meta_value DESC
                  LIMIT %d OFFSET %d";

        $queryParams = [...$params, $perPage, $offset];
        $rows = $wpdb->get_results($wpdb->prepare($query, ...$queryParams));

        $editions = array_map(function ($row) {
            return $this->formatEdition((int) $row->ID, $row->post_title);
        }, $rows);

        return new WP_REST_Response([
            'items' => $editions,
            'total' => $total,
            'page' => $page,
            'pages' => ceil($total / $perPage),
        ]);
    }

    /**
     * Format edition for API response.
     */
    private function formatEdition(int $id, string $title): array
    {
        $startDate = get_post_meta($id, '_vad_start_date', true);
        $endDate = get_post_meta($id, '_vad_end_date', true);
        $status = get_post_meta($id, '_vad_status', true) ?: 'open';
        $capacity = (int) get_post_meta($id, '_vad_capacity', true);
        $venue = get_post_meta($id, '_vad_venue', true);

        global $wpdb;
        $registered = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}vad_registrations WHERE edition_id = %d AND status = 'confirmed'",
            $id
        ));

        return [
            'id' => $id,
            'title' => $title,
            'startDate' => $startDate,
            'endDate' => $endDate,
            'status' => $status,
            'capacity' => $capacity,
            'registered' => $registered,
            'venue' => $venue,
            'editUrl' => get_edit_post_link($id, 'raw'),
        ];
    }

    /**
     * GET /admin/editions/{id}
     */
    public function getEdition(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $id = (int) $request->get_param('id');
        $post = get_post($id);

        if (!$post || $post->post_type !== 'vad_edition') {
            return new WP_Error('not_found', 'Edition not found', ['status' => 404]);
        }

        $edition = $this->formatEdition($id, $post->post_title);

        // Add sessions
        global $wpdb;
        $sessionRows = $wpdb->get_results($wpdb->prepare(
            "SELECT p.ID, p.post_title FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_vad_edition_id'
             WHERE p.post_type = 'vad_session' AND p.post_status = 'publish' AND pm.meta_value = %d
             ORDER BY (SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = p.ID AND meta_key = '_vad_date') ASC",
            $id
        ));

        $edition['sessions'] = array_map(function ($row) {
            $date = get_post_meta($row->ID, '_vad_date', true);
            $startTime = get_post_meta($row->ID, '_vad_start_time', true);
            $endTime = get_post_meta($row->ID, '_vad_end_time', true);

            return [
                'id' => (int) $row->ID,
                'title' => $row->post_title,
                'date' => $date,
                'startTime' => $startTime,
                'endTime' => $endTime,
            ];
        }, $sessionRows);

        return new WP_REST_Response($edition);
    }

    /**
     * GET /admin/editions/{id}/registrations
     */
    public function getEditionRegistrations(WP_REST_Request $request): WP_REST_Response
    {
        $editionId = (int) $request->get_param('id');

        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT r.*, u.display_name, u.user_email
             FROM {$wpdb->prefix}vad_registrations r
             LEFT JOIN {$wpdb->users} u ON r.user_id = u.ID
             WHERE r.edition_id = %d
             ORDER BY r.registered_at DESC",
            $editionId
        ));

        // Get sessions for this edition
        $sessionIds = $wpdb->get_col($wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_vad_edition_id'
             WHERE p.post_type = 'vad_session' AND pm.meta_value = %d",
            $editionId
        ));

        $registrations = array_map(function ($row) use ($sessionIds, $wpdb) {
            // Get attendance for each session
            $attendance = [];
            foreach ($sessionIds as $sessionId) {
                $status = $wpdb->get_var($wpdb->prepare(
                    "SELECT status FROM {$wpdb->prefix}vad_attendance WHERE session_id = %d AND user_id = %d",
                    $sessionId,
                    $row->user_id
                ));
                $attendance[$sessionId] = $status ?: null;
            }

            return [
                'id' => (int) $row->id,
                'userId' => (int) $row->user_id,
                'name' => $row->display_name,
                'email' => $row->user_email,
                'status' => $row->status,
                'enrollmentPath' => $row->enrollment_path,
                'registeredAt' => $row->registered_at,
                'attendance' => $attendance,
            ];
        }, $rows);

        return new WP_REST_Response([
            'items' => $registrations,
            'sessions' => array_map('intval', $sessionIds),
        ]);
    }

    /**
     * POST /admin/attendance
     */
    public function markAttendance(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $sessionId = (int) $request->get_param('sessionId');
        $userId = (int) $request->get_param('userId');
        $status = $request->get_param('status'); // 'present', 'absent', 'excused', or null to remove

        if (!$sessionId || !$userId) {
            return new WP_Error('invalid_params', 'sessionId and userId required', ['status' => 400]);
        }

        // Get edition ID from session
        $editionId = (int) get_post_meta($sessionId, '_vad_edition_id', true);

        if ($status === null || $status === '') {
            // Remove attendance record
            global $wpdb;
            $wpdb->delete(
                $wpdb->prefix . 'vad_attendance',
                ['session_id' => $sessionId, 'user_id' => $userId]
            );
        } else {
            // Record attendance
            $result = $this->attendance->record(
                $sessionId,
                $userId,
                \Stride\Domain\AttendanceStatus::from($status),
                $editionId,
                get_current_user_id()
            );

            if (is_wp_error($result)) {
                return new WP_Error('record_failed', $result->get_error_message(), ['status' => 500]);
            }
        }

        return new WP_REST_Response(['success' => true]);
    }

    /**
     * GET /admin/quotes
     */
    public function getQuotes(WP_REST_Request $request): WP_REST_Response
    {
        $status = $request->get_param('status');
        $search = $request->get_param('search');
        $page = max(1, (int) $request->get_param('page') ?: 1);
        $perPage = 20;

        global $wpdb;

        $where = ["p.post_type = 'vad_quote'", "p.post_status = 'publish'"];
        $params = [];

        if ($status) {
            $where[] = "EXISTS (SELECT 1 FROM {$wpdb->postmeta} WHERE post_id = p.ID AND meta_key = '_vad_status' AND meta_value = %s)";
            $params[] = $status;
        }

        if ($search) {
            $where[] = "p.post_title LIKE %s";
            $params[] = '%' . $wpdb->esc_like($search) . '%';
        }

        $whereClause = implode(' AND ', $where);
        $offset = ($page - 1) * $perPage;

        // Get total
        $totalQuery = "SELECT COUNT(*) FROM {$wpdb->posts} p WHERE {$whereClause}";
        $total = (int) $wpdb->get_var($params ? $wpdb->prepare($totalQuery, ...$params) : $totalQuery);

        // Get quotes
        $query = "SELECT p.ID, p.post_title, p.post_date
                  FROM {$wpdb->posts} p
                  WHERE {$whereClause}
                  ORDER BY p.post_date DESC
                  LIMIT %d OFFSET %d";

        $queryParams = [...$params, $perPage, $offset];
        $rows = $wpdb->get_results($wpdb->prepare($query, ...$queryParams));

        $quotes = array_map(function ($row) {
            $status = get_post_meta($row->ID, '_vad_status', true) ?: 'draft';
            $total = get_post_meta($row->ID, '_vad_total', true);
            $userId = (int) get_post_meta($row->ID, '_vad_user_id', true);
            $user = get_user_by('id', $userId);

            return [
                'id' => (int) $row->ID,
                'number' => $row->post_title,
                'status' => $status,
                'total' => $total ? (float) $total : 0,
                'date' => $row->post_date,
                'userName' => $user ? $user->display_name : 'Unknown',
                'userEmail' => $user ? $user->user_email : '',
                'editUrl' => get_edit_post_link($row->ID, 'raw'),
            ];
        }, $rows);

        return new WP_REST_Response([
            'items' => $quotes,
            'total' => $total,
            'page' => $page,
            'pages' => ceil($total / $perPage),
        ]);
    }
}
```

**Step 2: Register the service**

Add to `plugin-config.php`:

```php
\Stride\Admin\AdminAPIController::class,
```

**Step 3: Test**

Run: `ddev exec wp eval "echo rest_url('stride/v1/admin/stats');"`
Expected: URL like `https://stride.ddev.site/wp-json/stride/v1/admin/stats`

Run: `ddev launch /wp/wp-admin/admin.php?page=stride-dashboard`
Expected: Stats cards should now show real numbers

**Step 4: Commit**

```bash
git add web/app/mu-plugins/stride-core/Admin/AdminAPIController.php
git add web/app/mu-plugins/stride-core/plugin-config.php
git commit -m "feat(admin): add REST API endpoints for dashboard"
```

---

## Task 4: Editions List View

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Admin/AdminDashboardService.php`

**Step 1: Replace editions template in renderDashboard()**

Replace the editions template section:

```php
<!-- Editions View -->
<template x-if="view === 'editions'">
    <div>
        <div class="stride-page-header">
            <h2 class="stride-page-title">Editions</h2>
            <a href="<?php echo admin_url('post-new.php?post_type=vad_edition'); ?>" class="stride-btn stride-btn-primary">
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
                                        <span class="stride-capacity" :class="{ 'full': edition.registered >= edition.capacity }">
                                            <span x-text="edition.registered"></span>/<span x-text="edition.capacity"></span>
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
                                                <div class="stride-student-avatar" x-text="reg.name.charAt(0)"></div>
                                                <div class="stride-student-info">
                                                    <div class="stride-student-name" x-text="reg.name"></div>
                                                    <div class="stride-student-email" x-text="reg.email"></div>
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
                                                        <td x-text="reg.name"></td>
                                                        <template x-for="session in selectedEdition.sessions" :key="session.id">
                                                            <td class="stride-attendance-cell">
                                                                <button
                                                                    class="stride-attendance-btn"
                                                                    :class="{
                                                                        'present': reg.attendance[session.id] === 'present',
                                                                        'absent': reg.attendance[session.id] === 'absent',
                                                                        'excused': reg.attendance[session.id] === 'excused'
                                                                    }"
                                                                    @click="toggleAttendance(session.id, reg.userId, reg.attendance[session.id])"
                                                                >
                                                                    <template x-if="reg.attendance[session.id] === 'present'">
                                                                        <span class="dashicons dashicons-yes"></span>
                                                                    </template>
                                                                    <template x-if="reg.attendance[session.id] === 'absent'">
                                                                        <span class="dashicons dashicons-no"></span>
                                                                    </template>
                                                                    <template x-if="reg.attendance[session.id] === 'excused'">
                                                                        <span class="dashicons dashicons-clock"></span>
                                                                    </template>
                                                                    <template x-if="!reg.attendance[session.id]">
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
                                    <span x-text="selectedEdition.registered + '/' + selectedEdition.capacity"></span>
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
```

**Step 2: Add CSS for editions view**

Add to renderCSS():

```php
/* Filters */
.stride-filters {
    display: flex;
    gap: 16px;
    padding: 16px 20px;
    border-bottom: 1px solid var(--stride-border);
    flex-wrap: wrap;
}

.stride-filter-group {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.stride-filter-label {
    font-size: 12px;
    font-weight: 500;
    color: var(--stride-text-muted);
}

.stride-input,
.stride-select {
    padding: 8px 12px;
    border: 1px solid var(--stride-border);
    border-radius: 8px;
    font-size: 14px;
    color: var(--stride-text);
    background: #fff;
    min-width: 180px;
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
    padding: 12px 20px;
    text-align: left;
    border-bottom: 1px solid var(--stride-border);
}

.stride-table th {
    font-weight: 500;
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: 0.03em;
    color: var(--stride-text-muted);
    background: var(--stride-bg);
}

.stride-table tbody tr:hover {
    background: rgba(99, 102, 241, 0.04);
}

.stride-clickable {
    cursor: pointer;
}

.stride-edition-title {
    font-weight: 500;
    color: var(--stride-text);
}

.stride-capacity {
    font-variant-numeric: tabular-nums;
}

.stride-capacity.full {
    color: var(--stride-danger);
    font-weight: 600;
}

/* Badges */
.stride-badge {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 9999px;
    font-size: 12px;
    font-weight: 500;
    text-transform: capitalize;
}

.stride-badge-open { background: rgba(16, 185, 129, 0.1); color: var(--stride-success); }
.stride-badge-full { background: rgba(245, 158, 11, 0.1); color: var(--stride-warning); }
.stride-badge-cancelled { background: rgba(239, 68, 68, 0.1); color: var(--stride-danger); }
.stride-badge-completed { background: rgba(100, 116, 139, 0.1); color: var(--stride-text-muted); }
.stride-badge-confirmed { background: rgba(16, 185, 129, 0.1); color: var(--stride-success); }
.stride-badge-draft { background: rgba(100, 116, 139, 0.1); color: var(--stride-text-muted); }
.stride-badge-sent { background: rgba(59, 130, 246, 0.1); color: var(--stride-info); }
.stride-badge-exported { background: rgba(16, 185, 129, 0.1); color: var(--stride-success); }

.stride-badge-sm {
    padding: 2px 8px;
    font-size: 11px;
}

/* Button variations */
.stride-btn-sm {
    padding: 6px 12px;
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
    gap: 12px;
    padding: 16px;
    border-top: 1px solid var(--stride-border);
}

.stride-page-btn {
    padding: 8px 14px;
    border: 1px solid var(--stride-border);
    background: #fff;
    border-radius: 6px;
    cursor: pointer;
    font-size: 14px;
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

/* Loading & Empty */
.stride-loading {
    padding: 48px;
    text-align: center;
    color: var(--stride-text-muted);
}

.stride-empty {
    padding: 48px;
    text-align: center;
    color: var(--stride-text-muted);
}

.stride-empty-icon {
    font-size: 48px;
    opacity: 0.3;
    margin-bottom: 12px;
}

.stride-empty-sm {
    padding: 24px;
    text-align: center;
    color: var(--stride-text-muted);
    font-size: 14px;
}

/* Slide-over */
.stride-slideover-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.3);
    z-index: 1000;
    display: flex;
    justify-content: flex-end;
}

.stride-slideover {
    width: 600px;
    max-width: 100%;
    background: #fff;
    height: 100%;
    display: flex;
    flex-direction: column;
    box-shadow: -4px 0 24px rgba(0,0,0,0.15);
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
}

.stride-slideover-close {
    width: 32px;
    height: 32px;
    border: none;
    background: transparent;
    font-size: 24px;
    cursor: pointer;
    color: var(--stride-text-muted);
    border-radius: 6px;
}

.stride-slideover-close:hover {
    background: var(--stride-bg);
}

.stride-slideover-tabs {
    display: flex;
    gap: 4px;
    padding: 12px 24px;
    border-bottom: 1px solid var(--stride-border);
    background: var(--stride-bg);
}

.stride-slideover-tab {
    padding: 8px 16px;
    border: none;
    background: transparent;
    font-size: 14px;
    font-weight: 500;
    color: var(--stride-text-muted);
    cursor: pointer;
    border-radius: 6px;
}

.stride-slideover-tab:hover {
    color: var(--stride-text);
    background: #fff;
}

.stride-slideover-tab.active {
    color: var(--stride-primary);
    background: #fff;
}

.stride-slideover-body {
    flex: 1;
    overflow-y: auto;
    padding: 24px;
}

/* Student List */
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
}

.stride-student-email {
    font-size: 13px;
    color: var(--stride-text-muted);
}

/* Attendance Grid */
.stride-attendance-grid {
    overflow-x: auto;
}

.stride-table-compact th,
.stride-table-compact td {
    padding: 8px 12px;
}

.stride-attendance-header {
    text-align: center;
    white-space: nowrap;
    font-size: 11px;
}

.stride-attendance-cell {
    text-align: center;
}

.stride-attendance-btn {
    width: 32px;
    height: 32px;
    border: 1px solid var(--stride-border);
    background: #fff;
    border-radius: 6px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
}

.stride-attendance-btn .dashicons {
    font-size: 16px;
    width: 16px;
    height: 16px;
}

.stride-attendance-btn.present {
    background: var(--stride-success);
    border-color: var(--stride-success);
    color: #fff;
}

.stride-attendance-btn.absent {
    background: var(--stride-danger);
    border-color: var(--stride-danger);
    color: #fff;
}

.stride-attendance-btn.excused {
    background: var(--stride-warning);
    border-color: var(--stride-warning);
    color: #fff;
}

/* Info List */
.stride-info-list {
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.stride-info-row {
    display: flex;
    justify-content: space-between;
    padding-bottom: 16px;
    border-bottom: 1px solid var(--stride-border);
}

.stride-info-row:last-child {
    border-bottom: none;
    padding-bottom: 0;
}

.stride-info-label {
    font-weight: 500;
    color: var(--stride-text-muted);
}
```

**Step 3: Update JavaScript in renderJS()**

```php
private function renderJS(): void
{
    ?>
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

            // Editions
            editions: [],
            editionsLoading: false,
            editionFilters: { search: '', status: '' },
            editionPage: 1,
            editionPages: 1,
            selectedEdition: null,
            editionTab: 'students',
            registrations: [],
            registrationsLoading: false,

            // Initialize
            init() {
                this.parseHash();
                window.addEventListener('hashchange', () => this.parseHash());
                this.loadStats();
            },

            parseHash() {
                const hash = window.location.hash.replace('#/', '') || 'dashboard';
                this.view = hash;
                if (hash === 'editions') {
                    this.loadEditions();
                }
            },

            async loadStats() {
                this.loading = true;
                try {
                    const response = await fetch(`${StrideConfig.apiUrl}/admin/stats`, {
                        headers: { 'X-WP-Nonce': StrideConfig.nonce }
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
                        search: this.editionFilters.search,
                        status: this.editionFilters.status
                    });
                    const response = await fetch(`${StrideConfig.apiUrl}/admin/editions?${params}`, {
                        headers: { 'X-WP-Nonce': StrideConfig.nonce }
                    });
                    if (response.ok) {
                        const data = await response.json();
                        this.editions = data.items;
                        this.editionPages = data.pages;
                    }
                } catch (e) {
                    console.error('Failed to load editions:', e);
                }
                this.editionsLoading = false;
            },

            async openEdition(id) {
                try {
                    const response = await fetch(`${StrideConfig.apiUrl}/admin/editions/${id}`, {
                        headers: { 'X-WP-Nonce': StrideConfig.nonce }
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
                try {
                    const response = await fetch(`${StrideConfig.apiUrl}/admin/editions/${editionId}/registrations`, {
                        headers: { 'X-WP-Nonce': StrideConfig.nonce }
                    });
                    if (response.ok) {
                        const data = await response.json();
                        this.registrations = data.items;
                    }
                } catch (e) {
                    console.error('Failed to load registrations:', e);
                }
                this.registrationsLoading = false;
            },

            async toggleAttendance(sessionId, userId, currentStatus) {
                const statuses = [null, 'present', 'absent', 'excused'];
                const currentIndex = statuses.indexOf(currentStatus);
                const newStatus = statuses[(currentIndex + 1) % statuses.length];

                // Optimistic update
                const reg = this.registrations.find(r => r.userId === userId);
                if (reg) {
                    reg.attendance[sessionId] = newStatus;
                }

                try {
                    await fetch(`${StrideConfig.apiUrl}/admin/attendance`, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-WP-Nonce': StrideConfig.nonce
                        },
                        body: JSON.stringify({ sessionId, userId, status: newStatus })
                    });
                } catch (e) {
                    console.error('Failed to update attendance:', e);
                    // Revert on error
                    this.loadRegistrations(this.selectedEdition.id);
                }
            },

            formatDate(dateStr) {
                if (!dateStr) return '';
                const date = new Date(dateStr);
                return date.toLocaleDateString('nl-BE', { day: 'numeric', month: 'short', year: 'numeric' });
            },

            formatShortDate(dateStr) {
                if (!dateStr) return '';
                const date = new Date(dateStr);
                return date.toLocaleDateString('nl-BE', { day: 'numeric', month: 'short' });
            }
        }));
    });
    <?php
}
```

**Step 4: Test**

Run: `ddev launch /wp/wp-admin/admin.php?page=stride-dashboard#/editions`
Expected: Editions list with filters, click an edition to see slide-over with students/attendance tabs

**Step 5: Commit**

```bash
git add web/app/mu-plugins/stride-core/Admin/AdminDashboardService.php
git commit -m "feat(admin): add editions list view with attendance"
```

---

## Task 5: Quotes List View

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Admin/AdminDashboardService.php`

**Step 1: Replace quotes template in renderDashboard()**

Replace the quotes template section:

```php
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
                                        <div class="stride-customer-name" x-text="quote.userName"></div>
                                        <div class="stride-customer-email" x-text="quote.userEmail"></div>
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
```

**Step 2: Add quotes CSS**

Add to renderCSS():

```php
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
```

**Step 3: Add quotes state and methods to JavaScript**

Add to the Alpine.data object:

```javascript
// Add to state section:
// Quotes
quotes: [],
quotesLoading: false,
quoteFilters: { search: '', status: '' },
quotePage: 1,
quotePages: 1,

// Update parseHash:
parseHash() {
    const hash = window.location.hash.replace('#/', '') || 'dashboard';
    this.view = hash;
    if (hash === 'editions') {
        this.loadEditions();
    } else if (hash === 'quotes') {
        this.loadQuotes();
    }
},

// Add new methods:
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
    return new Intl.NumberFormat('nl-BE', { style: 'currency', currency: 'EUR' }).format(amount);
}
```

**Step 4: Test**

Run: `ddev launch /wp/wp-admin/admin.php?page=stride-dashboard#/quotes`
Expected: Quotes list with status filter, showing quote number, customer, date, total, status

**Step 5: Commit**

```bash
git add web/app/mu-plugins/stride-core/Admin/AdminDashboardService.php
git commit -m "feat(admin): add quotes list view"
```

---

## Task 6: Test Script

**Files:**
- Create: `scripts/test-admin-dashboard.php`

**Step 1: Create test script**

```php
<?php
/**
 * Stride V1 - Admin Dashboard Tests
 *
 * Tests admin API endpoints and service registration.
 *
 * Run with: ddev exec wp eval-file scripts/test-admin-dashboard.php
 */

if (!defined('ABSPATH')) {
    echo "Run via WP-CLI: ddev exec wp eval-file scripts/test-admin-dashboard.php\n";
    exit(1);
}

use Stride\Admin\AdminDashboardService;
use Stride\Admin\AdminAPIController;

echo "=== Stride V1 - Admin Dashboard Tests ===" . PHP_EOL . PHP_EOL;

$GLOBALS['passed'] = 0;
$GLOBALS['failed'] = 0;

function assert_test(bool $condition, string $message): void {
    if ($condition) {
        echo "  [PASS] {$message}" . PHP_EOL;
        $GLOBALS['passed']++;
    } else {
        echo "  [FAIL] {$message}" . PHP_EOL;
        $GLOBALS['failed']++;
    }
}

wp_set_current_user(1);

try {
    // === A. SERVICE REGISTRATION ===
    echo "A. Service Registration..." . PHP_EOL;

    // A1. AdminDashboardService exists
    $dashboardService = ntdst_get(AdminDashboardService::class);
    assert_test($dashboardService !== null, 'A1. AdminDashboardService registered');

    // A2. AdminAPIController exists
    $apiController = ntdst_get(AdminAPIController::class);
    assert_test($apiController !== null, 'A2. AdminAPIController registered');

    echo PHP_EOL;

    // === B. API ENDPOINTS ===
    echo "B. API Endpoints..." . PHP_EOL;

    // B1. Stats endpoint
    $request = new WP_REST_Request('GET', '/stride/v1/admin/stats');
    $response = rest_do_request($request);
    assert_test($response->get_status() === 200, 'B1. Stats endpoint returns 200');

    $stats = $response->get_data();
    assert_test(isset($stats['upcomingEditions']), 'B1a. Stats has upcomingEditions');
    assert_test(isset($stats['totalRegistrations']), 'B1b. Stats has totalRegistrations');
    assert_test(isset($stats['pendingQuotes']), 'B1c. Stats has pendingQuotes');
    assert_test(isset($stats['todaySessions']), 'B1d. Stats has todaySessions');

    // B2. Editions endpoint
    $request = new WP_REST_Request('GET', '/stride/v1/admin/editions');
    $response = rest_do_request($request);
    assert_test($response->get_status() === 200, 'B2. Editions endpoint returns 200');

    $editions = $response->get_data();
    assert_test(isset($editions['items']), 'B2a. Editions has items array');
    assert_test(isset($editions['total']), 'B2b. Editions has total count');
    assert_test(isset($editions['pages']), 'B2c. Editions has pages count');

    // B3. Quotes endpoint
    $request = new WP_REST_Request('GET', '/stride/v1/admin/quotes');
    $response = rest_do_request($request);
    assert_test($response->get_status() === 200, 'B3. Quotes endpoint returns 200');

    $quotes = $response->get_data();
    assert_test(isset($quotes['items']), 'B3a. Quotes has items array');

    echo PHP_EOL;

    // === C. PERMISSION CHECK ===
    echo "C. Permission Check..." . PHP_EOL;

    // C1. Anonymous user cannot access
    wp_set_current_user(0);
    $request = new WP_REST_Request('GET', '/stride/v1/admin/stats');
    $response = rest_do_request($request);
    assert_test($response->get_status() === 401, 'C1. Anonymous user blocked');

    // Restore admin user
    wp_set_current_user(1);

    echo PHP_EOL;

    // === D. ATTENDANCE API ===
    echo "D. Attendance API..." . PHP_EOL;

    // Create test data if we have seed data
    global $wpdb;
    $testSession = $wpdb->get_var("SELECT ID FROM {$wpdb->posts} WHERE post_type = 'vad_session' LIMIT 1");
    $testUser = $wpdb->get_var("SELECT ID FROM {$wpdb->users} WHERE ID > 1 LIMIT 1");

    if ($testSession && $testUser) {
        // D1. Mark present
        $request = new WP_REST_Request('POST', '/stride/v1/admin/attendance');
        $request->set_body_params([
            'sessionId' => (int) $testSession,
            'userId' => (int) $testUser,
            'status' => 'present'
        ]);
        $response = rest_do_request($request);
        assert_test($response->get_status() === 200, 'D1. Mark attendance returns 200');

        $data = $response->get_data();
        assert_test($data['success'] === true, 'D1a. Mark attendance succeeds');

        // D2. Check attendance recorded
        $record = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}vad_attendance WHERE session_id = %d AND user_id = %d",
            $testSession,
            $testUser
        ));
        assert_test($record !== null, 'D2. Attendance record created');
        assert_test($record->status === 'present', 'D2a. Status is present');

        // Cleanup
        $wpdb->delete($wpdb->prefix . 'vad_attendance', [
            'session_id' => $testSession,
            'user_id' => $testUser
        ]);
    } else {
        echo "  [SKIP] D. No seed data - run seed.php first" . PHP_EOL;
    }

    echo PHP_EOL;

} catch (Exception $e) {
    echo "[FATAL] " . $e->getMessage() . PHP_EOL;
    echo $e->getTraceAsString() . PHP_EOL;
}

$passed = $GLOBALS['passed'];
$failed = $GLOBALS['failed'];

echo "=== Results ===" . PHP_EOL;
echo "Passed: {$passed}" . PHP_EOL;
echo "Failed: {$failed}" . PHP_EOL;
echo ($failed === 0 ? "ALL TESTS PASSED!" : "SOME TESTS FAILED") . PHP_EOL;
```

**Step 2: Run test**

Run: `ddev exec wp eval-file scripts/test-admin-dashboard.php`
Expected: All tests pass

**Step 3: Commit**

```bash
git add scripts/test-admin-dashboard.php
git commit -m "test(admin): add admin dashboard test script"
```

---

## Summary

After completing all tasks, the admin dashboard will have:

1. **Full-screen app** with collapsed WordPress sidebar
2. **Dashboard home** with stats and quick actions
3. **Editions list** with search/filter, pagination
4. **Edition detail** slide-over with students and attendance tabs
5. **Attendance tracking** with click-to-toggle buttons
6. **Quotes list** with status filter
7. **REST API** for all data operations
8. **Test coverage** for API endpoints

The design follows the Acerta pattern: Alpine.js for reactivity, CSS custom properties for theming, and a clean, professional UI with subtle animations.
