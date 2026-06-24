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
            ntdst_get(\Stride\Modules\Enrollment\RegistrationRepository::class),
        );

        // Admin guide page (registers own menu hook)
        new AdminGuidePage();

        // Impersonation admin bar hook
        $impersonation = new ImpersonationHandler();
        if ($impersonation->isActive()) {
            add_filter('show_admin_bar', '__return_true', 999);
            add_action('admin_bar_menu', function (\WP_Admin_Bar $bar) use ($impersonation) {
                $token = $impersonation->getTokenFromCookie();
                $adminId = $impersonation->getOriginalAdmin($token);
                if ($adminId > 0) {
                    $adminUser = get_userdata($adminId);
                    $bar->add_node([
                        'id' => 'stride-end-impersonation',
                        'title' => sprintf('← Terug naar %s', $adminUser ? $adminUser->display_name : 'admin'),
                        'href' => rest_url('stride/v1/admin/impersonate/end') . '?token=' . urlencode($token) . '&_wpnonce=' . wp_create_nonce('wp_rest'),
                        'meta' => ['class' => 'stride-impersonation-notice'],
                    ]);
                }
            }, 999);
        }

        // Cache invalidation for the action-queue AND dashboard-stats transients
        // (S6). Both are derived from the registration/quote/attendance corpus,
        // so the same write events bust both — keeping the dashboard stats no
        // staler than the last such write (within their TTL). Void closure: do
        // not surface delete_transient()'s bool to the action dispatcher (an
        // action callback must return nothing).
        $invalidateQueue = static function (): void {
            delete_transient('stride_action_queue');
            delete_transient(\Stride\Admin\AdminStatsService::STATS_TRANSIENT_KEY);
        };
        add_action('stride/registration/created', $invalidateQueue);
        add_action('stride/registration/confirmed', $invalidateQueue);
        add_action('stride/registration/cancelled', $invalidateQueue);
        add_action('stride/attendance/marked', $invalidateQueue);
        add_action('save_post_vad_quote', $invalidateQueue);
        // M10 (Task 2.4) — bulk batch completion + quote-status changes set via
        // the repo (which never touch save_post_vad_quote) must recount the queue.
        add_action('stride/registration/bulk_completed', $invalidateQueue);
        add_action('stride/registration/quote_status_changed', $invalidateQueue);
        // Cluster-F gate (perf-oracle F-1): the stats key counts more inputs than
        // the action-queue did, so it needs a wider bust set or it shows stale
        // headline counts for up to STATS_TTL.
        //  - interest/waitlist public sign-ups feed worklistQueues.oldinterest /
        //    .waitlist_open (not covered by created/confirmed/cancelled).
        add_action('stride/registration/interest_registered', $invalidateQueue);
        add_action('stride/registration/waitlisted', $invalidateQueue);
        //  - any other status transition (e.g. a single reg → completed feeding
        //    .nocert) fires the repo's generic updated event — the catch-all.
        add_action('stride/registration/updated', $invalidateQueue);
        //  - edition / session / trajectory CPT writes feed upcomingEditions,
        //    todaySessions, openTrajectories, upcomingEditionDetails, alerts.
        add_action('save_post_vad_edition', $invalidateQueue);
        add_action('save_post_vad_session', $invalidateQueue);
        add_action('save_post_vad_trajectory', $invalidateQueue);

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
            2,
        );

        // Add explicit Dashboard submenu as first item
        add_submenu_page(
            self::MENU_SLUG,
            'Dashboard',
            'Dashboard',
            self::CAPABILITY,
            self::MENU_SLUG,
            [$this, 'renderDashboard'],
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
            '4.6.13',
        );
        wp_enqueue_script(
            'flatpickr',
            'https://cdn.jsdelivr.net/npm/flatpickr',
            [],
            '4.6.13',
            true,
        );
        wp_enqueue_script(
            'flatpickr-nl',
            'https://cdn.jsdelivr.net/npm/flatpickr/dist/l10n/nl.js',
            ['flatpickr'],
            '4.6.13',
            true,
        );

        wp_enqueue_script(
            'alpinejs',
            'https://cdn.jsdelivr.net/npm/alpinejs@3.14.9/dist/cdn.min.js',
            ['flatpickr'],
            '3.14.9',
            ['strategy' => 'defer'],
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
            'adminUrl' => admin_url(),
            'nonce' => wp_create_nonce('wp_rest'),
            'exportNonce' => wp_create_nonce('stride_edition_admin'),
            'user' => [
                'id' => $user->ID,
                'name' => $user->display_name,
                'email' => $user->user_email,
                'firstName' => $user->first_name ?: $user->display_name,
            ],
            'canManage' => current_user_can('stride_manage'),
            // §12.3 cutover: the no-hash landing view. Defaults to the Vandaag
            // worklist home (Task 3.3). One-line override point — set via the
            // 'stride_admin_default_view' filter (e.g. flip back to 'dashboard'
            // during a transition, or once Dossier/3.4 lands). The old entity
            // tabs stay reachable regardless.
            'defaultView' => (string) apply_filters('stride_admin_default_view', 'vandaag'),
            // Per-action nonces for the bulk grid (Task 3.2, §2.3). Each bulk POST
            // to ntdst/v1/action carries its action-specific nonce
            // (verified server-side via wp_verify_nonce($nonce, $action)).
            'bulkNonces' => self::bulkActionNonces(),
            // Authoritative lifecycle transition map (CR-5). The JS bulk bar
            // validates its lifecycle actions (approve/cancel/promote) against
            // this server-printed map and warns on drift, instead of trusting a
            // hand-copied JS constant. Quote/completion/message actions are
            // orthogonal to lifecycle status and are NOT in this map.
            'transitions' => \Stride\Modules\Enrollment\RegistrationTransitions::toArray(),
        ]);
    }

    /**
     * Per-action nonces for the bulk grid (Task 3.2, §2.3).
     *
     * The action registry (ntdst/v1/action) verifies wp_verify_nonce($nonce,
     * $action) per request; the grid must arm the right nonce for each bulk
     * action it POSTs. The action set MUST match BulkRegistrationHandler's
     * registered ntdst/api_data/* filters — a drift here means an armed action
     * with no nonce (401). The two deferred stubs (message, generate_doc) are
     * included so the UI can arm them and render their deferred response.
     *
     * @return array<string,string> action name => nonce
     */
    private static function bulkActionNonces(): array
    {
        $actions = [
            'stride_bulk_approve',
            'stride_bulk_cancel',
            'stride_bulk_quote_sent',
            'stride_bulk_quote_exported',
            'stride_bulk_promote_waitlist',
            'stride_bulk_approve_post_course',
            'stride_bulk_message',
            'stride_bulk_generate_doc',
            'stride_bulk_set_field',
            // Cohort-lens roster bulk (Phase 2a, Task 2a.9). Each maps to a
            // Handlers/RosterBulkHandler ntdst/api_data/* filter; the cohort UI
            // arms the matching nonce per action via cohortActionName().
            'stride_roster_bulk_approve',
            'stride_roster_bulk_message',
            'stride_roster_bulk_generate_doc',
            'stride_traj_roster_bulk_approve',
            'stride_traj_roster_bulk_message',
            'stride_traj_roster_bulk_generate_doc',
        ];

        $nonces = [];
        foreach ($actions as $action) {
            $nonces[$action] = wp_create_nonce($action);
        }

        return $nonces;
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
     * Inject the admin workspace design system + WP-chrome adjust.
     *
     * The design system is the wireframe CSS adopted verbatim:
     *   workspace.css (chrome/tokens) + grid.css (data grid).
     * It was authored for a standalone file:// document that owns <body>, so
     * wp-admin-adjust.css re-points the WP chrome-hiding rules at the new
     * `.ws-shell` host (the only visible nav becomes the dark `.ws-rail`).
     *
     * Self-hosted fonts (Space Grotesk / Inter Tight / JetBrains Mono, latin
     * subset, woff2) are emitted via @font-face with ABSOLUTE plugin URLs —
     * the stylesheets are inlined in <head>, so relative url() would resolve
     * against the wp-admin document URL, not the CSS file. font-display: swap +
     * a system-stack fallback (baked into the --ws-font* tokens) keep the UI
     * usable if a face fails. No Google Fonts <link> in wp-admin (privacy).
     */
    public function injectStyles(): void
    {
        if (!$this->isStridePage()) {
            return;
        }

        $basePath = dirname(__DIR__);
        $fontBase = plugins_url('assets/fonts', $basePath . '/stride-core.php');

        echo '<style id="stride-workspace-fonts">';
        echo $this->fontFaceCss($fontBase);
        echo '</style>';

        foreach (['admin/workspace.css', 'admin/grid.css', 'admin/wp-admin-adjust.css'] as $rel) {
            $cssPath = $basePath . '/assets/css/' . $rel;
            if (file_exists($cssPath)) {
                echo '<style id="stride-workspace-' . esc_attr(basename($rel, '.css')) . '">';
                include $cssPath;
                echo '</style>';
            }
        }
    }

    /**
     * Build the @font-face block for the self-hosted workspace fonts.
     *
     * Inter Tight and Space Grotesk are variable fonts (one woff2 spans the
     * 400-700 range used by the design); JetBrains Mono likewise covers 400-500.
     * A single @font-face per family with a `font-weight` range is therefore
     * correct and the smallest payload.
     *
     * @param string $fontBase Absolute URL of the fonts directory (no trailing slash).
     */
    private function fontFaceCss(string $fontBase): string
    {
        $faces = [
            ['Space Grotesk', 'space-grotesk-latin.woff2', '400 700'],
            ['Inter Tight',   'inter-tight-latin.woff2',   '400 700'],
            ['JetBrains Mono', 'jetbrains-mono-latin.woff2', '400 500'],
        ];

        $css = '';
        foreach ($faces as [$family, $file, $weight]) {
            $url = esc_url($fontBase . '/' . $file);
            $css .= "@font-face{font-family:'{$family}';font-style:normal;"
                . "font-weight:{$weight};font-display:swap;"
                . "src:url('{$url}') format('woff2');}";
        }

        return $css;
    }

    /**
     * Render the dashboard page (the ws-shell host).
     *
     * @var string $admin_url, \WP_User $user, string $user_name — consumed by the template.
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
     * The admin workspace JS, loaded in order.
     *
     * The shell (per-surface architecture's spine — active view, nav, the shared
     * api() helper, the constant icon map) loads first. Each later cluster (B–G)
     * appends its own small per-surface factory file here; surfaces are separate
     * components, NOT methods on the shell.
     *
     * @return list<string> Plugin-relative paths under assets/js/.
     */
    private function workspaceScripts(): array
    {
        return [
            'admin/shell.js',
            'admin/vandaag.js',
            'admin/grid.js',
            'admin/dossier.js',
        ];
    }

    /**
     * Inject the admin workspace JS.
     *
     * Inlined in <script> (mirrors injectStyles()'s inline-include idiom) so the
     * files share the StrideConfig localize block already printed on `alpinejs`
     * and need no separate handle registration. Each file is an IIFE that
     * registers its Alpine factory on `window` before Alpine boots (deferred).
     */
    public function injectScripts(): void
    {
        if (!$this->isStridePage()) {
            return;
        }

        $basePath = dirname(__DIR__) . '/assets/js/';
        foreach ($this->workspaceScripts() as $rel) {
            $jsPath = $basePath . $rel;
            if (file_exists($jsPath)) {
                echo '<script>';
                include $jsPath;
                echo '</script>';
            }
        }
    }
}
