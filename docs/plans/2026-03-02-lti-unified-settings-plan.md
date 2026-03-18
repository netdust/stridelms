# LTI Unified Settings Page — Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Replace 5 scattered LTI admin pages with a single Alpine.js-powered settings page featuring inline CRUD, logs, and how-to documentation.

**Architecture:** Single admin page under Settings menu. Alpine.js app with 6 tabs (Dashboard, Platforms, Tools, Resources, Logs, How-To). CRUD via WP REST API against existing CPTs. Matches Stride admin dashboard design language.

**Tech Stack:** Alpine.js 3.x, WP REST API, PHP 8.1+, NTDST Data Manager CPTs

**Design doc:** `docs/plans/2026-03-02-lti-unified-settings-design.md`

---

## Task 1: Expose CPT meta fields to REST API

The existing CPTs (`lti_platform`, `lti_tool`, `lti_resource`) are registered via Data Manager but their meta fields aren't exposed to the WP REST API. We need `show_in_rest` + `register_post_meta()` for Alpine to read/write via `wp.apiFetch`.

**Files:**
- Modify: `web/app/plugins/netdust-lti/src/Shared/LTIDataService.php`

**Step 1: Add `show_in_rest` to all three CPT registrations**

In `registerPlatformModel()`, `registerToolModel()`, and `registerResourceModel()`, add `'show_in_rest' => true` to the registration array. Also change `'show_in_menu'` from `'options-general.php'` to `false` (we'll manage these inline now).

In `registerPlatformModel()`:
```php
ntdst_data()->register('lti_platform', [
    'label' => 'LTI Platforms',
    'public' => false,
    'show_ui' => true,
    'show_in_menu' => false,        // Changed: hidden from WP menu
    'show_in_rest' => true,         // Added: REST API access
    'rest_base' => 'lti-platforms', // Added: clean REST base
    'supports' => ['title'],
    'meta_prefix' => 'lti_',
    // ... rest unchanged
]);
```

Same pattern for `lti_tool` (rest_base: `lti-tools`) and `lti_resource` (rest_base: `lti-resources`).

**Step 2: Register meta fields for REST API**

Add a new method `registerRestMeta()` called from `init()` on the `rest_api_init` action. For each CPT, register each field with `register_post_meta()`:

```php
private function registerRestMeta(): void
{
    $platformFields = [
        'lti_platform_id' => ['type' => 'string'],
        'lti_client_id' => ['type' => 'string'],
        'lti_deployment_id' => ['type' => 'string'],
        'lti_auth_endpoint' => ['type' => 'string'],
        'lti_token_endpoint' => ['type' => 'string'],
        'lti_jwks_endpoint' => ['type' => 'string'],
        'lti_rsa_key' => ['type' => 'string'],
        'lti_kid' => ['type' => 'string'],
        'lti_enabled' => ['type' => 'boolean'],
        'lti_contexts' => ['type' => 'string'],
        'lti_role_instructor' => ['type' => 'string'],
        'lti_role_learner' => ['type' => 'string'],
    ];

    foreach ($platformFields as $key => $args) {
        register_post_meta('lti_platform', $key, [
            'show_in_rest' => true,
            'single' => true,
            'type' => $args['type'],
            'auth_callback' => fn() => current_user_can('manage_options'),
        ]);
    }

    // Same pattern for lti_tool and lti_resource fields...
}
```

Note: Meta keys are prefixed with `lti_` because of the `meta_prefix` in the Data Manager registration.

**Step 3: Hook it up in init()**

```php
private function init(): void
{
    add_action('init', [$this, 'registerModels'], 5);
    add_action('rest_api_init', [$this, 'registerRestMeta']);
    // ... existing hooks unchanged
}
```

**Step 4: Verify REST API works**

Run: `ddev exec wp eval "echo rest_url('wp/v2/lti-platforms');"`
Expected: `https://stride.ddev.site/wp-json/wp/v2/lti-platforms`

Test via browser: `https://stride.ddev.site/wp-json/wp/v2/lti-platforms` (should return JSON array)

**Step 5: Commit**

```bash
git add web/app/plugins/netdust-lti/src/Shared/LTIDataService.php
git commit -m "feat(lti): expose CPT meta fields to REST API for settings page"
```

---

## Task 2: Create SettingsPage.php controller

New admin page controller that replaces `AdminPage.php` and `LaunchTestPage.php`. Registers a single menu page, enqueues Alpine.js + CSS + JS, and renders the template.

**Files:**
- Create: `web/app/plugins/netdust-lti/src/Admin/SettingsPage.php`

**Step 1: Create the controller**

```php
<?php
declare(strict_types=1);

namespace NetdustLTI\Admin;

use NetdustLTI\Plugin;

/**
 * Unified LTI Settings Page
 *
 * Single Alpine.js-powered admin page replacing all scattered LTI admin pages.
 * Manages Platforms, Tools, Resources inline with CRUD via WP REST API.
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
    }

    public function registerMenu(): void
    {
        add_options_page(
            'Netdust LTI',
            'Netdust LTI',
            self::CAPABILITY,
            self::MENU_SLUG,
            [$this, 'renderPage']
        );
    }

    private function isLtiPage(): bool
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
            'platformEndpoints' => $this->getPlatformEndpoints(),
            'keyStatus' => $this->getKeyStatus(),
        ]);
    }

    public function addBodyClasses(string $classes): string
    {
        if ($this->isLtiPage()) {
            $classes .= ' lti-settings-page folded';
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

    private function getPlatformEndpoints(): array
    {
        return [
            'issuer' => home_url('/'),
            'auth_endpoint' => home_url('/lti/platform/auth'),
            'jwks_url' => home_url('/lti/jwks'),
            'ags_endpoint' => home_url('/lti/platform/grades'),
            'deep_link_return' => home_url('/lti/platform/deep-link-return'),
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
}
```

**Step 2: Commit**

```bash
git add web/app/plugins/netdust-lti/src/Admin/SettingsPage.php
git commit -m "feat(lti): add unified SettingsPage controller"
```

---

## Task 3: Create a REST endpoint for logs

The logs tab needs to read server-side log files. Add a simple REST endpoint.

**Files:**
- Create: `web/app/plugins/netdust-lti/src/Admin/LogsController.php`

**Step 1: Create the controller**

```php
<?php
declare(strict_types=1);

namespace NetdustLTI\Admin;

use WP_REST_Request;
use WP_REST_Response;

/**
 * REST controller for reading LTI log files.
 */
final class LogsController
{
    public function __construct()
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        register_rest_route('netdust-lti/v1', '/logs', [
            'methods' => 'GET',
            'callback' => [$this, 'getLogs'],
            'permission_callback' => fn() => current_user_can('manage_options'),
            'args' => [
                'channel' => [
                    'type' => 'string',
                    'enum' => ['lti', 'lti-grade'],
                    'default' => 'lti',
                ],
                'date' => [
                    'type' => 'string',
                    'default' => '',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'limit' => [
                    'type' => 'integer',
                    'default' => 50,
                    'minimum' => 1,
                    'maximum' => 200,
                ],
            ],
        ]);
    }

    public function getLogs(WP_REST_Request $request): WP_REST_Response
    {
        $channel = $request->get_param('channel');
        $date = $request->get_param('date') ?: date('Y-m-d');
        $limit = $request->get_param('limit');

        $logFile = WP_CONTENT_DIR . '/logs/' . $channel . '-' . $date . '.log';

        if (!file_exists($logFile)) {
            return new WP_REST_Response(['logs' => [], 'date' => $date], 200);
        }

        $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $lines = array_slice(array_reverse($lines), 0, $limit);

        $logs = [];
        foreach ($lines as $line) {
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

        return new WP_REST_Response(['logs' => $logs, 'date' => $date], 200);
    }
}
```

**Step 2: Commit**

```bash
git add web/app/plugins/netdust-lti/src/Admin/LogsController.php
git commit -m "feat(lti): add REST endpoint for log reading"
```

---

## Task 4: Create CSS file (Stride design language)

**Files:**
- Create: `web/app/plugins/netdust-lti/assets/css/lti-admin.css`

**Step 1: Create the assets directory and CSS file**

First verify directory: `ls web/app/plugins/netdust-lti/` (assets/ may not exist yet)

Create `assets/css/lti-admin.css` — the full-screen Stride-style admin CSS. This uses the same CSS variables and patterns as `stride-core/assets/css/admin-dashboard.css`.

Key sections to include:
- CSS variables (same `--stride-*` palette)
- Hide WP admin clutter (admin bar, footer, notices)
- App layout (full-height flex column)
- Header + tab navigation
- Card components
- Table styles
- Form/input styles (for CRUD modals)
- Button styles (primary, ghost, danger, small)
- Modal/slide-panel styles
- Status badges
- Endpoint URL row + copy button
- Log viewer table
- How-to documentation styles
- Responsive tweaks
- Loading/empty states

Total: ~400–500 lines of CSS.

**Step 2: Commit**

```bash
git add web/app/plugins/netdust-lti/assets/css/lti-admin.css
git commit -m "feat(lti): add Stride-style admin CSS for settings page"
```

---

## Task 5: Create the Alpine.js app

**Files:**
- Create: `web/app/plugins/netdust-lti/assets/js/lti-admin.js`

**Step 1: Create the Alpine app**

The JS app manages all state and CRUD operations. Structure:

```javascript
document.addEventListener('alpine:init', () => {
    Alpine.data('ltiApp', () => ({
        // Tab state
        tab: 'dashboard',

        // Dashboard
        keyStatus: LtiConfig.keyStatus,
        toolEndpoints: LtiConfig.toolEndpoints,
        platformEndpoints: LtiConfig.platformEndpoints,
        stats: { platforms: 0, tools: 0, resources: 0 },

        // CRUD state for each entity type
        platforms: [],
        platformsLoading: false,
        editingPlatform: null,
        platformForm: {},

        tools: [],
        toolsLoading: false,
        editingTool: null,
        toolForm: {},

        resources: [],
        resourcesLoading: false,
        editingResource: null,
        resourceForm: {},

        // Logs state
        logs: [],
        logsLoading: false,
        logChannel: 'lti',
        logDate: new Date().toISOString().split('T')[0],

        // Notifications
        notification: null,

        // Clipboard
        copied: null,

        init() {
            this.parseHash();
            window.addEventListener('hashchange', () => this.parseHash());
            this.loadStats();
        },

        parseHash() {
            const hash = window.location.hash.replace('#/', '') || 'dashboard';
            this.tab = hash;
            this.loadTabData(hash);
        },

        setTab(t) {
            this.tab = t;
            history.replaceState(null, '', '#/' + t);
            this.loadTabData(t);
        },

        // ... data loading methods for each tab
        // ... CRUD methods (create, update, delete) for each entity
        // ... clipboard copy helper
        // ... notification helper
    }));
});
```

Key methods to implement:

**Data loading:**
- `loadStats()` — Fetch counts for each CPT
- `loadPlatforms()` — `GET /wp/v2/lti-platforms?per_page=100`
- `loadTools()` — `GET /wp/v2/lti-tools?per_page=100`
- `loadResources()` — `GET /wp/v2/lti-resources?per_page=100`
- `loadLogs()` — `GET /netdust-lti/v1/logs?channel=X&date=Y`

**CRUD (same pattern for each entity):**
- `savePlatform()` — POST (create) or PUT (update) via `wp.apiFetch`
- `deletePlatform(id)` — DELETE with confirmation
- `editPlatform(item)` — Populate form, show modal
- `newPlatform()` — Empty form, show modal
- `cancelEdit()` — Close modal

**Helpers:**
- `copyToClipboard(text)` — Copy endpoint URL + show feedback
- `notify(message, type)` — Show/auto-dismiss notification
- `apiFetch(path, options)` — Wrapper around fetch with nonce header

Use `fetch()` with `X-WP-Nonce` header (from `LtiConfig.nonce`) for all REST calls. The `wp.apiFetch` may not be available since we're not enqueueing `wp-api-fetch`.

Total: ~400–500 lines of JS.

**Step 2: Commit**

```bash
git add web/app/plugins/netdust-lti/assets/js/lti-admin.js
git commit -m "feat(lti): add Alpine.js app for LTI settings page"
```

---

## Task 6: Create the main settings template

**Files:**
- Create: `web/app/plugins/netdust-lti/templates/admin/settings.php`

**Step 1: Create the Alpine HTML shell**

This template is the single-page-app container. It renders:
- Header with "Netdust LTI" title + WP Admin link
- Tab navigation bar (6 tabs)
- Content area with `x-show` for each tab
- Dashboard tab: status cards + endpoint tables
- Platforms/Tools/Resources tabs: data table + edit modal
- Logs tab: channel selector + date picker + log table
- How-To tab: includes `howto.php`

```php
<?php defined('ABSPATH') || exit; ?>
<div class="wrap lti-app" x-data="ltiApp()">
    <!-- Header -->
    <header class="lti-header">
        <div class="lti-header-left">
            <h1>Netdust LTI</h1>
            <nav class="lti-nav">
                <template x-for="t in ['dashboard','platforms','tools','resources','logs','howto']">
                    <a href="#" class="lti-nav-item"
                       :class="{ 'active': tab === t }"
                       @click.prevent="setTab(t)"
                       x-text="t === 'howto' ? 'How-To' : t.charAt(0).toUpperCase() + t.slice(1)">
                    </a>
                </template>
            </nav>
        </div>
        <div class="lti-header-right">
            <a :href="LtiConfig.adminUrl" class="lti-btn lti-btn-ghost">WP Admin</a>
        </div>
    </header>

    <!-- Notification -->
    <div x-show="notification" x-transition ...>...</div>

    <!-- Content -->
    <div class="lti-content">
        <!-- Dashboard Tab -->
        <div x-show="tab === 'dashboard'">
            <!-- Status cards -->
            <!-- Tool Provider Endpoints table with copy buttons -->
            <!-- Platform Endpoints table with copy buttons -->
        </div>

        <!-- Platforms Tab -->
        <div x-show="tab === 'platforms'">
            <!-- Add button -->
            <!-- Platforms table -->
            <!-- Edit modal -->
        </div>

        <!-- Tools Tab -->
        <div x-show="tab === 'tools'">
            <!-- Add button -->
            <!-- Tools table with Test Launch button -->
            <!-- Edit modal -->
        </div>

        <!-- Resources Tab -->
        <div x-show="tab === 'resources'">
            <!-- Add button -->
            <!-- Resources table with Launch button -->
            <!-- Edit modal -->
        </div>

        <!-- Logs Tab -->
        <div x-show="tab === 'logs'">
            <!-- Channel selector + date picker -->
            <!-- Log table -->
        </div>

        <!-- How-To Tab -->
        <div x-show="tab === 'howto'">
            <?php include __DIR__ . '/howto.php'; ?>
        </div>
    </div>
</div>
```

Each CRUD tab follows the same HTML pattern:
1. Page header with "Add New" button
2. Table with headers and `x-for` rows
3. Modal overlay with grouped form fields and Save/Cancel buttons

**Step 2: Commit**

```bash
git add web/app/plugins/netdust-lti/templates/admin/settings.php
git commit -m "feat(lti): add main settings page template"
```

---

## Task 7: Create the how-to documentation template

**Files:**
- Create: `web/app/plugins/netdust-lti/templates/admin/howto.php`

**Step 1: Create the documentation content**

Static HTML with sections:

1. **Quick Start** (3 steps: verify keys → add platform → test launch)
2. **Registering as a Tool Provider** (provide endpoint URLs to external LMS, with inline copy buttons referencing the Alpine app's `copyToClipboard`)
3. **Adding External Tools** (configure outbound tool: fill credentials + endpoints)
4. **Endpoint Reference** (full table of all URL endpoints with descriptions)
5. **Grade Passback** (enable per-course in LearnDash course editor sidebar)
6. **Troubleshooting** (JWKS errors, nonce expiry, token endpoint issues, common misconfigurations)

Use `lti-docs-*` CSS classes. Styled with markdown-like headings, code blocks, and callout boxes.

**Step 2: Commit**

```bash
git add web/app/plugins/netdust-lti/templates/admin/howto.php
git commit -m "feat(lti): add how-to documentation template"
```

---

## Task 8: Wire up Plugin.php and remove old pages

**Files:**
- Modify: `web/app/plugins/netdust-lti/src/Plugin.php`
- Delete: `web/app/plugins/netdust-lti/src/Admin/AdminPage.php`
- Delete: `web/app/plugins/netdust-lti/src/Admin/LaunchTestPage.php`
- Delete: `web/app/plugins/netdust-lti/templates/admin/settings-page.php`
- Delete: `web/app/plugins/netdust-lti/templates/admin/launch-test.php`
- Delete: `web/app/plugins/netdust-lti/templates/admin/logs.php`

**Step 1: Update Plugin.php**

In the `init()` method, replace:
```php
if (is_admin()) {
    ntdst_get(Admin\AdminPage::class);
    ntdst_get(Admin\CourseSettingsMetabox::class);
    ntdst_get(Admin\LaunchTestPage::class);
    ntdst_get(ToolProvider\DeepLinkHandler::class);
}
```

With:
```php
if (is_admin()) {
    ntdst_get(Admin\SettingsPage::class);
    ntdst_get(Admin\CourseSettingsMetabox::class);
    ntdst_get(ToolProvider\DeepLinkHandler::class);
}

// Logs REST endpoint (needed outside is_admin for REST calls)
ntdst_get(Admin\LogsController::class);
```

**Step 2: Delete old files**

```bash
rm web/app/plugins/netdust-lti/src/Admin/AdminPage.php
rm web/app/plugins/netdust-lti/src/Admin/LaunchTestPage.php
rm web/app/plugins/netdust-lti/templates/admin/settings-page.php
rm web/app/plugins/netdust-lti/templates/admin/launch-test.php
rm web/app/plugins/netdust-lti/templates/admin/logs.php
```

**Step 3: Commit**

```bash
git add -A web/app/plugins/netdust-lti/src/Admin/ web/app/plugins/netdust-lti/src/Plugin.php web/app/plugins/netdust-lti/templates/admin/
git commit -m "feat(lti): wire unified settings page, remove old admin pages"
```

---

## Task 9: Manual verification

**Step 1: Flush rewrite rules**

```bash
ddev exec wp rewrite flush
ddev exec wp cache flush
```

**Step 2: Verify the page loads**

Navigate to: `https://stride.ddev.site/wp/wp-admin/options-general.php?page=netdust-lti`

Check:
- [ ] Page loads without PHP errors
- [ ] Alpine app initializes (tab navigation works)
- [ ] Dashboard tab shows key status and endpoint URLs
- [ ] Copy buttons work for endpoint URLs

**Step 3: Verify CRUD tabs**

- [ ] Platforms tab lists existing platforms
- [ ] Can add a new platform (fill form, save)
- [ ] Can edit an existing platform
- [ ] Can delete a platform (with confirmation)
- [ ] Same for Tools and Resources tabs
- [ ] Test Launch button on Tools opens new tab

**Step 4: Verify Logs tab**

- [ ] Logs load for current date
- [ ] Channel toggle (Launches / Grade Passbacks) works
- [ ] Empty state shown when no logs

**Step 5: Verify How-To tab**

- [ ] Documentation renders with proper styling
- [ ] Copy buttons in endpoint reference work

**Step 6: Verify old pages are gone**

- [ ] No more "LTI Launch Test" in Settings menu
- [ ] No more "LTI Platforms/Tools/Resources" in Settings menu
- [ ] Only "Netdust LTI" appears under Settings

**Step 7: Commit any fixes and final commit**

```bash
git add -A web/app/plugins/netdust-lti/
git commit -m "feat(lti): unified settings page complete"
```
