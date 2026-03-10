# Dashboard UX/UI Redesign — Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Transform the Stride user dashboard from horizontal-tab layout to a Notion-style sidebar workspace with new Notifications and Downloads pages, updated design tokens, and flat list patterns.

**Architecture:** Keep existing `?tab=` routing on `page-mijn-account.php`. Replace horizontal tabs with sidebar nav (desktop) and updated bottom nav (mobile). Add two new tabs: `meldingen` and `downloads`. Add a lightweight `NotificationService` to stride-core for computing notifications from existing data. All frontend stays Tailwind + Alpine.js.

**Tech Stack:** PHP templates, Tailwind CSS, Alpine.js, NTDST framework services

**Design Doc:** `docs/plans/2026-03-10-dashboard-ux-redesign-design.md`

---

## Phase 1: Design Token Foundation

### Task 1: Update color tokens

**Files:**
- Modify: `web/app/themes/stridence/src/css/tokens.css`
- Modify: `web/app/themes/stridence/tailwind.config.js`

**Step 1: Update tokens.css**

Replace the color block in `:root` with the new Warm Notion palette:

```css
/* ── Brand Colors ── */
--color-primary: 79 70 229;        /* #4F46E5 indigo */
--color-primary-hover: 67 56 202;  /* #4338CA indigo darker */
--color-primary-subtle: 238 242 255; /* #EEF2FF indigo tint */
--color-primary-light: 99 102 241; /* #6366F1 indigo-500 */
--color-primary-dark: 55 48 163;   /* #3730A3 indigo-800 */
--color-accent: 13 148 136;        /* #0D9488 teal */
--color-accent-light: 20 184 166;  /* #14B8A6 teal lighter */

/* ── Neutral Colors ── */
--color-surface: 250 248 246;      /* #FAF8F6 warm off-white */
--color-surface-alt: 245 242 238;  /* #F5F2EE warm elevated */
--color-surface-card: 255 255 255; /* #FFFFFF white */
--color-border: 232 228 223;       /* #E8E4DF warm border */
--color-border-strong: 212 207 200; /* #D4CFC8 active border */
--color-text: 28 25 23;            /* #1C1917 stone-900 */
--color-text-muted: 120 113 108;   /* #78716C stone-500 */
--color-text-inverse: 255 255 255;

/* ── Status Colors ── */
--color-success: 22 163 74;
--color-warning: 217 119 6;       /* #D97706 amber-600 */
--color-error: 220 38 38;
--color-info: 79 70 229;          /* same as primary */
```

Also update layout tokens:

```css
/* ── Layout ── */
--sidebar-width: 240px;
--sidebar-collapsed: 56px;
--content-max: 960px;
```

Remove `--color-primary-light` and `--color-primary-dark` old navy values. Replace with indigo equivalents above.

Also remove `--font-heading: 'Plus Jakarta Sans'...` — headings now use Inter:

```css
--font-heading: var(--font-sans);
```

**Step 2: Update tailwind.config.js**

Add new color tokens to the Tailwind config `colors` block:

```javascript
primary: {
  DEFAULT: 'rgb(var(--color-primary) / <alpha-value>)',
  hover: 'rgb(var(--color-primary-hover) / <alpha-value>)',
  subtle: 'rgb(var(--color-primary-subtle) / <alpha-value>)',
  light: 'rgb(var(--color-primary-light) / <alpha-value>)',
  dark: 'rgb(var(--color-primary-dark) / <alpha-value>)',
},
```

Add `border-strong` to the border color:
```javascript
border: {
  DEFAULT: 'rgb(var(--color-border) / <alpha-value>)',
  strong: 'rgb(var(--color-border-strong) / <alpha-value>)',
},
```

Add sidebar spacing:
```javascript
spacing: {
  // ... existing
  sidebar: 'var(--sidebar-width)',
  'sidebar-collapsed': 'var(--sidebar-collapsed)',
},
```

**Step 3: Build and verify**

Run: `cd web/app/themes/stridence && npm run build`
Expected: Build succeeds, no Tailwind errors.

**Step 4: Commit**

```bash
git add web/app/themes/stridence/src/css/tokens.css web/app/themes/stridence/tailwind.config.js
git commit -m "style: update design tokens to Warm Notion palette (indigo primary, warm grays)"
```

---

### Task 2: Update component CSS for new tokens

**Files:**
- Modify: `web/app/themes/stridence/src/css/components.css`

**Step 1: Update button classes**

The `.btn-primary` currently uses `bg-primary` which will now resolve to indigo. Verify these still look right. Update `.btn-primary` hover to use the new hover token:

```css
.btn-primary {
  @apply ... hover:bg-primary-hover ...;
}
```

**Step 2: Add sidebar nav component classes**

Add to components.css after the existing nav section:

```css
/* ══════════════════════════════════════
   SIDEBAR NAVIGATION
   ══════════════════════════════════════ */

.sidebar {
  @apply fixed inset-y-0 left-0 z-40
         w-sidebar bg-surface-card
         border-r border-border
         flex flex-col;
  transition: width var(--duration-normal) var(--ease-out);
}

.sidebar-collapsed {
  width: var(--sidebar-collapsed);
}

.sidebar-nav-item {
  @apply flex items-center gap-3 px-3 py-2.5 rounded-lg
         text-sm font-medium text-text-muted
         cursor-pointer
         hover:text-text hover:bg-surface-alt
         transition-colors duration-fast;
}

.sidebar-nav-item-active {
  @apply text-primary bg-primary-subtle;
}

.sidebar-nav-badge {
  @apply ml-auto text-xs font-semibold
         bg-primary text-text-inverse
         rounded-full min-w-[20px] h-5
         flex items-center justify-center px-1.5;
}

.sidebar-divider {
  @apply border-t border-border my-2 mx-3;
}

.sidebar-user {
  @apply flex items-center gap-3 px-3 py-3;
}
```

**Step 3: Add toast component class**

```css
/* ══════════════════════════════════════
   TOAST NOTIFICATIONS
   ══════════════════════════════════════ */

.toast {
  @apply fixed bottom-6 right-6 z-[60]
         bg-surface-card border border-border
         rounded-lg px-4 py-3
         flex items-center gap-3
         text-sm text-text;
  box-shadow: var(--shadow-elevated);
}

.toast-success {
  border-left: 3px solid rgb(var(--color-success));
}

.toast-error {
  border-left: 3px solid rgb(var(--color-error));
}
```

**Step 4: Add notification item class**

```css
/* ══════════════════════════════════════
   NOTIFICATION LIST
   ══════════════════════════════════════ */

.notification-item {
  @apply flex gap-3 px-4 py-3 rounded-lg
         cursor-pointer
         hover:bg-surface-alt
         transition-colors duration-fast;
}

.notification-unread {
  @apply relative;
}

.notification-unread::before {
  content: '';
  @apply absolute left-1 top-1/2 -translate-y-1/2
         w-2 h-2 rounded-full bg-primary;
}
```

**Step 5: Add flat list item class**

```css
/* ══════════════════════════════════════
   FLAT LIST ITEMS
   ══════════════════════════════════════ */

.list-item {
  @apply flex items-center gap-4 px-4 py-3.5
         border-b border-border/60 last:border-b-0
         cursor-pointer
         hover:bg-surface-alt
         transition-colors duration-fast;
}

.list-item-static {
  @apply list-item cursor-default hover:bg-transparent;
}
```

**Step 6: Build and verify**

Run: `cd web/app/themes/stridence && npm run build`

**Step 7: Commit**

```bash
git add web/app/themes/stridence/src/css/components.css
git commit -m "style: add sidebar, toast, notification, and flat list component classes"
```

---

## Phase 2: Layout Shell

### Task 3: Convert page-mijn-account to sidebar layout

**Files:**
- Modify: `web/app/themes/stridence/page-mijn-account.php`

This is the core structural change. The page switches from horizontal tabs to sidebar + content area.

**Step 1: Update valid tabs array**

Add new tabs to the `$valid_tabs` array:

```php
$valid_tabs = ['home', 'inschrijvingen', 'trajecten', 'offertes', 'certificaten', 'profiel', 'meldingen', 'downloads'];
```

**Step 2: Update tabs config**

Replace the existing `$tabs` array with the new sidebar structure. Split into primary nav, utility nav, and user nav:

```php
$primary_nav = [
    'home'            => ['label' => __('Home', 'stridence'), 'icon' => 'home', 'visible' => true],
    'inschrijvingen'  => ['label' => __('Mijn opleidingen', 'stridence'), 'icon' => 'book-open', 'visible' => !empty($nav_items['opleidingen'])],
    'trajecten'       => ['label' => __('Trajecten', 'stridence'), 'icon' => 'layers', 'visible' => !empty($nav_items['trajecten'])],
    'offertes'        => ['label' => __('Offertes', 'stridence'), 'icon' => 'file-text', 'visible' => !empty($nav_items['offertes'])],
];

$utility_nav = [
    'meldingen'       => ['label' => __('Meldingen', 'stridence'), 'icon' => 'bell', 'visible' => true],
    'downloads'       => ['label' => __('Downloads', 'stridence'), 'icon' => 'download', 'visible' => true],
    'certificaten'    => ['label' => __('Certificaten', 'stridence'), 'icon' => 'award', 'visible' => !empty($nav_items['certificaten'])],
];
```

Also fetch the notification unread count for the sidebar badge:

```php
$notificationService = ntdst_get(\Stride\Modules\Notification\NotificationService::class);
$unread_count = $notificationService->getUnreadCount($user->ID);
```

Note: The NotificationService doesn't exist yet — it will be created in Task 7. For now, hardcode `$unread_count = 0` with a TODO comment until that task is done.

**Step 3: Replace HTML layout**

Replace the entire HTML structure (from `<div class="min-h-screen">` to the end) with the sidebar layout:

```php
<div class="min-h-screen bg-surface">
    <!-- Sidebar (desktop only) -->
    <div class="hidden lg:block">
        <?php get_template_part('templates/dashboard/nav-sidebar', null, [
            'current_tab'   => $current_tab,
            'primary_nav'   => $primary_nav,
            'utility_nav'   => $utility_nav,
            'user'          => $user,
            'unread_count'  => $unread_count,
        ]); ?>
    </div>

    <!-- Main Content Area -->
    <main class="lg:ml-sidebar min-h-screen">
        <!-- Top Bar -->
        <div class="sticky top-0 z-30 bg-surface/80 backdrop-blur-sm border-b border-border/60">
            <div class="max-w-content mx-auto px-4 md:px-6 lg:px-8 h-14 flex items-center justify-between">
                <h1 class="text-lg font-semibold text-text tracking-tight">
                    <?php echo esc_html($page_titles[$current_tab] ?? 'Dashboard'); ?>
                </h1>
            </div>
        </div>

        <!-- Page Content -->
        <div class="max-w-content mx-auto px-4 md:px-6 lg:px-8 py-6 lg:py-8">
            <?php
            if ($current_tab === 'home') {
                get_template_part('templates/dashboard/tab-home', null, [
                    'user'      => $user,
                    'home_data' => $home_data,
                ]);
            } else {
                get_template_part("templates/dashboard/tab-{$current_tab}", null, [
                    'user' => $user,
                ]);
            }
            ?>
        </div>
    </main>

    <!-- Mobile Bottom Navigation -->
    <div class="lg:hidden">
        <?php get_template_part('templates/dashboard/nav-mobile', null, [
            'current_tab'  => $current_tab,
            'nav_items'    => $nav_items,
            'unread_count' => $unread_count,
        ]); ?>
    </div>
</div>
```

Add page titles map before the HTML:

```php
$page_titles = [
    'home'           => $greeting . ', ' . $firstName,
    'inschrijvingen' => __('Mijn opleidingen', 'stridence'),
    'trajecten'      => __('Trajecten', 'stridence'),
    'offertes'       => __('Offertes', 'stridence'),
    'certificaten'   => __('Certificaten', 'stridence'),
    'profiel'        => __('Profiel', 'stridence'),
    'meldingen'      => __('Meldingen', 'stridence'),
    'downloads'      => __('Downloads', 'stridence'),
];
```

**Step 4: Verify page loads**

Run: `ddev launch /mijn-account/`
Expected: Page loads without PHP errors. Content area renders. Sidebar not yet visible (nav-sidebar.php needs update next).

**Step 5: Commit**

```bash
git add web/app/themes/stridence/page-mijn-account.php
git commit -m "refactor: convert dashboard layout from horizontal tabs to sidebar shell"
```

---

### Task 4: Rebuild sidebar navigation

**Files:**
- Modify: `web/app/themes/stridence/templates/dashboard/nav-sidebar.php`

**Step 1: Rewrite nav-sidebar.php**

Replace entire file content with the full sidebar component:

```php
<?php
/**
 * Dashboard Sidebar Navigation
 *
 * Fixed left sidebar for desktop dashboard.
 * All data passed via $args — no service calls inside partials.
 *
 * @param array $args {
 *     @type string $current_tab   Active tab slug
 *     @type array  $primary_nav   Primary navigation items
 *     @type array  $utility_nav   Utility navigation items
 *     @type WP_User $user         Current user
 *     @type int    $unread_count  Unread notification count
 * }
 */

declare(strict_types=1);
defined('ABSPATH') || exit;

$current_tab  = $args['current_tab'] ?? 'home';
$primary_nav  = $args['primary_nav'] ?? [];
$utility_nav  = $args['utility_nav'] ?? [];
$user         = $args['user'] ?? wp_get_current_user();
$unread_count = $args['unread_count'] ?? 0;
$base_url     = get_permalink();

$firstName = explode(' ', trim($user->display_name))[0];
$initials  = strtoupper(
    mb_substr($user->first_name ?: $firstName, 0, 1)
    . mb_substr($user->last_name ?: '', 0, 1)
) ?: '?';
?>

<aside class="sidebar" aria-label="<?php esc_attr_e('Dashboard navigatie', 'stridence'); ?>">
    <!-- Brand -->
    <div class="px-4 py-5">
        <a href="<?php echo esc_url(home_url('/')); ?>" class="text-lg font-semibold text-text tracking-tight">
            Stride
        </a>
    </div>

    <!-- Primary Navigation -->
    <nav class="flex-1 px-3 space-y-0.5">
        <?php foreach ($primary_nav as $slug => $item) :
            if (!$item['visible']) continue;
            $url = $slug === 'home' ? $base_url : add_query_arg('tab', $slug, $base_url);
            $is_active = ($current_tab === $slug);
        ?>
            <a href="<?php echo esc_url($url); ?>"
               class="sidebar-nav-item <?php echo $is_active ? 'sidebar-nav-item-active' : ''; ?>"
               <?php echo $is_active ? 'aria-current="page"' : ''; ?>>
                <?php echo stridence_icon($item['icon'], 'w-5 h-5 shrink-0'); ?>
                <span><?php echo esc_html($item['label']); ?></span>
            </a>
        <?php endforeach; ?>

        <div class="sidebar-divider"></div>

        <!-- Utility Navigation -->
        <?php foreach ($utility_nav as $slug => $item) :
            if (!$item['visible']) continue;
            $url = add_query_arg('tab', $slug, $base_url);
            $is_active = ($current_tab === $slug);
        ?>
            <a href="<?php echo esc_url($url); ?>"
               class="sidebar-nav-item <?php echo $is_active ? 'sidebar-nav-item-active' : ''; ?>"
               <?php echo $is_active ? 'aria-current="page"' : ''; ?>>
                <?php echo stridence_icon($item['icon'], 'w-5 h-5 shrink-0'); ?>
                <span><?php echo esc_html($item['label']); ?></span>
                <?php if ($slug === 'meldingen' && $unread_count > 0) : ?>
                    <span class="sidebar-nav-badge" aria-label="<?php printf(esc_attr__('%d ongelezen', 'stridence'), $unread_count); ?>">
                        <?php echo esc_html($unread_count); ?>
                    </span>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>
    </nav>

    <!-- User Footer -->
    <div class="border-t border-border px-3 py-3 space-y-0.5">
        <div class="sidebar-user">
            <div class="w-8 h-8 rounded-full bg-primary-subtle flex items-center justify-center shrink-0">
                <span class="text-primary font-semibold text-xs"><?php echo esc_html($initials); ?></span>
            </div>
            <span class="text-sm font-medium text-text truncate"><?php echo esc_html($firstName); ?></span>
        </div>
        <a href="<?php echo esc_url(add_query_arg('tab', 'profiel', $base_url)); ?>"
           class="sidebar-nav-item <?php echo $current_tab === 'profiel' ? 'sidebar-nav-item-active' : ''; ?>">
            <?php echo stridence_icon('user', 'w-5 h-5 shrink-0'); ?>
            <span><?php echo esc_html__('Profiel', 'stridence'); ?></span>
        </a>
        <a href="<?php echo esc_url(wp_logout_url(home_url('/'))); ?>"
           class="sidebar-nav-item text-error/70 hover:text-error hover:bg-error/5">
            <?php echo stridence_icon('log-out', 'w-5 h-5 shrink-0'); ?>
            <span><?php echo esc_html__('Uitloggen', 'stridence'); ?></span>
        </a>
    </div>
</aside>
```

**Step 2: Verify sidebar renders**

Open: `https://stride.ddev.site/mijn-account/` on desktop
Expected: Sidebar visible on left, content area shifted right. Nav items highlight correctly. Clicking items navigates between tabs.

**Step 3: Commit**

```bash
git add web/app/themes/stridence/templates/dashboard/nav-sidebar.php
git commit -m "feat: rebuild sidebar navigation with primary, utility, and user sections"
```

---

### Task 5: Update mobile bottom navigation

**Files:**
- Modify: `web/app/themes/stridence/templates/dashboard/nav-mobile.php`

**Step 1: Update tab list**

Update the `$tabs` array to include Meldingen (with badge) and reduce to 5 items max:

```php
$unread_count = $args['unread_count'] ?? 0;

$tabs = [
    'home' => [
        'label'   => __('Home', 'stridence'),
        'icon'    => 'home',
        'url'     => $base_url,
        'visible' => true,
        'badge'   => 0,
    ],
    'inschrijvingen' => [
        'label'   => __('Opleidingen', 'stridence'),
        'icon'    => 'book-open',
        'url'     => add_query_arg('tab', 'inschrijvingen', $base_url),
        'visible' => true,
        'badge'   => 0,
    ],
    'meldingen' => [
        'label'   => __('Meldingen', 'stridence'),
        'icon'    => 'bell',
        'url'     => add_query_arg('tab', 'meldingen', $base_url),
        'visible' => true,
        'badge'   => $unread_count,
    ],
    'downloads' => [
        'label'   => __('Downloads', 'stridence'),
        'icon'    => 'download',
        'url'     => add_query_arg('tab', 'downloads', $base_url),
        'visible' => true,
        'badge'   => 0,
    ],
    'profiel' => [
        'label'   => __('Profiel', 'stridence'),
        'icon'    => 'user',
        'url'     => add_query_arg('tab', 'profiel', $base_url),
        'visible' => true,
        'badge'   => 0,
    ],
];
```

**Step 2: Add badge rendering**

Inside the tab link rendering loop, after the icon, add badge support:

```php
<?php if (!empty($tab['badge'])) : ?>
    <span class="absolute -top-1 -right-1 bg-primary text-text-inverse text-[9px] font-bold rounded-full w-4 h-4 flex items-center justify-center">
        <?php echo esc_html($tab['badge']); ?>
    </span>
<?php endif; ?>
```

Make the link container `relative` for badge positioning.

**Step 3: Verify on mobile viewport**

Resize browser to <1024px.
Expected: Bottom nav shows 5 items. Notification badge shows count. Sidebar hidden.

**Step 4: Commit**

```bash
git add web/app/themes/stridence/templates/dashboard/nav-mobile.php
git commit -m "feat: update mobile nav with notifications badge and downloads"
```

---

## Phase 3: Dashboard Home Redesign

### Task 6: Redesign home tab template

**Files:**
- Modify: `web/app/themes/stridence/templates/dashboard/tab-home.php`
- Modify: `web/app/themes/stridence/templates/dashboard/partials/action-items.php`
- Create: `web/app/themes/stridence/templates/dashboard/partials/stat-cards.php`

**Step 1: Create stat-cards partial**

Create `templates/dashboard/partials/stat-cards.php`:

```php
<?php
declare(strict_types=1);
defined('ABSPATH') || exit;

$stats = $args['stats'] ?? [];
if (empty($stats)) return;
?>

<div class="flex gap-3 flex-wrap">
    <?php foreach ($stats as $stat) : ?>
        <div class="flex-1 min-w-[120px] px-4 py-3 rounded-lg border border-border/60 bg-surface-card">
            <div class="text-2xl font-semibold text-text"><?php echo esc_html($stat['value']); ?></div>
            <div class="text-xs text-text-muted mt-0.5"><?php echo esc_html($stat['label']); ?></div>
        </div>
    <?php endforeach; ?>
</div>
```

**Step 2: Rewrite tab-home.php**

Replace the content of `tab-home.php` with the new flat layout:

- **Greeting + status line** (lightweight, replaces hero)
- **Stat cards** (inline row)
- **Acties** (action list with colored borders — reuse existing action-items partial)
- **Verder leren** (2-column grid of in-progress course cards with progress bar + CTA)
- **Recent behaald** (compact list with download links)
- **Empty state** (if no data)

Key structure:

```php
<?php
declare(strict_types=1);
defined('ABSPATH') || exit;

$user      = $args['user'];
$home_data = $args['home_data'];
$actions   = $home_data['actions'] ?? [];
$courses   = $home_data['active_courses'] ?? [];
$completed = $home_data['recent_completed'] ?? [];

// Greeting
$firstName = explode(' ', trim($user->display_name))[0];
$hour = (int) date('G');
$greeting = match (true) {
    $hour < 12  => __('Goedemorgen', 'stridence'),
    $hour < 18  => __('Goedemiddag', 'stridence'),
    default     => __('Goedenavond', 'stridence'),
};

// Stats
$stats = [];
if (!empty($courses)) {
    $stats[] = ['value' => count($courses), 'label' => __('Lopende opleidingen', 'stridence')];
}
$action_count = count(array_filter($actions, fn($a) => ($a['type'] ?? '') === 'action'));
if ($action_count > 0) {
    $stats[] = ['value' => $action_count, 'label' => __('Actie vereist', 'stridence')];
}
if (!empty($completed)) {
    $stats[] = ['value' => count($home_data['certificates'] ?? []), 'label' => __('Certificaten', 'stridence')];
}
?>

<div class="space-y-8">
    <!-- Greeting -->
    <div>
        <h2 class="text-xl font-semibold text-text"><?php echo esc_html($greeting . ', ' . $firstName); ?></h2>
        <?php if (!empty($actions)) : ?>
            <p class="text-sm text-text-muted mt-1">
                <?php printf(
                    esc_html__('Je hebt %d acties die aandacht nodig hebben', 'stridence'),
                    count($actions)
                ); ?>
            </p>
        <?php endif; ?>
    </div>

    <!-- Stats -->
    <?php if (!empty($stats)) :
        get_template_part('templates/dashboard/partials/stat-cards', null, ['stats' => $stats]);
    endif; ?>

    <!-- Actions -->
    <?php if (!empty($actions)) : ?>
        <div>
            <h3 class="text-base font-semibold text-text mb-3"><?php esc_html_e('Acties', 'stridence'); ?></h3>
            <?php get_template_part('templates/dashboard/partials/action-items', null, ['actions' => $actions]); ?>
        </div>
    <?php endif; ?>

    <!-- Continue Learning -->
    <!-- ... 2-column grid of course cards ... -->

    <!-- Recently Completed -->
    <!-- ... compact list ... -->

    <!-- Empty State -->
    <!-- ... if no data at all ... -->
</div>
```

The exact implementation will depend on the current structure of `$home_data`. Read `UserDashboardService::getHomeData()` before implementing to understand the data shape.

**Step 3: Simplify action-items partial**

Update `partials/action-items.php` to use the flat `action-item` class pattern. Remove any card wrappers — render as a simple bordered list inside a card container.

**Step 4: Verify home tab renders**

Open: `https://stride.ddev.site/mijn-account/`
Expected: Greeting, stat cards, action list, course cards, completed list all render with new styling.

**Step 5: Commit**

```bash
git add web/app/themes/stridence/templates/dashboard/
git commit -m "feat: redesign dashboard home with stat cards, flat actions, and course grid"
```

---

## Phase 4: Notifications

### Task 7: Create NotificationService

**Files:**
- Create: `web/app/mu-plugins/stride-core/Modules/Notification/NotificationService.php`
- Modify: `web/app/mu-plugins/stride-core/plugin-config.php` (register service)

**Step 1: Create the service**

The `NotificationService` computes notifications from existing data on each page load. It does NOT store notifications — it derives them from sessions, certificates, quotes, and actions.

Read state (which notifications the user has "seen") is stored in user meta `_stride_notifications_read` as a JSON-encoded array of notification IDs with timestamps.

```php
<?php

declare(strict_types=1);

namespace Stride\Modules\Notification;

use Stride\Modules\Edition\SessionService;
use Stride\Modules\User\UserDashboardService;
use Stride\Integrations\LearnDash\LearnDashHelper;

class NotificationService implements \NTDST_Service_Meta
{
    public static function metadata(): array
    {
        return [
            'name'        => 'Notification Service',
            'description' => 'Computes user notifications from existing data',
            'priority'    => 20,
        ];
    }

    public function __construct(
        private readonly UserDashboardService $dashboardService,
    ) {}

    /**
     * Get all notifications for user, sorted by recency.
     *
     * @return array{id: string, type: string, title: string, body: string, url: string, timestamp: int, read: bool}[]
     */
    public function getNotifications(int $userId): array
    {
        $notifications = [];
        $read_ids = $this->getReadIds($userId);

        // Derive notifications from dashboard action data
        $home_data = $this->dashboardService->getHomeData($userId);
        $actions = $home_data['actions'] ?? [];

        foreach ($actions as $action) {
            $id = 'action_' . md5($action['label'] ?? '');
            $notifications[] = [
                'id'        => $id,
                'type'      => $action['type'] ?? 'info',
                'title'     => $action['label'] ?? '',
                'body'      => $action['subtitle'] ?? '',
                'url'       => $action['url'] ?? '',
                'timestamp' => $action['timestamp'] ?? time(),
                'read'      => isset($read_ids[$id]),
            ];
        }

        // Sort by timestamp desc
        usort($notifications, fn($a, $b) => $b['timestamp'] <=> $a['timestamp']);

        return $notifications;
    }

    public function getUnreadCount(int $userId): int
    {
        $notifications = $this->getNotifications($userId);
        return count(array_filter($notifications, fn($n) => !$n['read']));
    }

    public function markAllRead(int $userId): void
    {
        $notifications = $this->getNotifications($userId);
        $read_ids = [];
        foreach ($notifications as $n) {
            $read_ids[$n['id']] = time();
        }
        update_user_meta($userId, '_stride_notifications_read', wp_json_encode($read_ids));
    }

    public function markRead(int $userId, string $notificationId): void
    {
        $read_ids = $this->getReadIds($userId);
        $read_ids[$notificationId] = time();
        update_user_meta($userId, '_stride_notifications_read', wp_json_encode($read_ids));
    }

    private function getReadIds(int $userId): array
    {
        $raw = get_user_meta($userId, '_stride_notifications_read', true);
        if (empty($raw)) return [];
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }
}
```

**Step 2: Register in plugin-config.php**

Add to the services array:
```php
\Stride\Modules\Notification\NotificationService::class,
```

**Step 3: Add AJAX handler for mark-read**

Add a filter in the service's constructor:

```php
public function __construct(
    private readonly UserDashboardService $dashboardService,
) {
    add_filter('ntdst/api_data/stride_mark_notifications_read', [$this, 'handleMarkAllRead'], 10, 2);
}

public function handleMarkAllRead(mixed $data, array $params): array|\WP_Error
{
    $userId = get_current_user_id();
    if (!$userId) {
        return new \WP_Error('not_logged_in', __('Je bent niet ingelogd.', 'stride'));
    }

    $this->markAllRead($userId);
    return ['success' => true];
}
```

**Step 4: Remove TODO from page-mijn-account.php**

Replace the hardcoded `$unread_count = 0` with the real service call.

**Step 5: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Notification/NotificationService.php web/app/mu-plugins/stride-core/plugin-config.php web/app/themes/stridence/page-mijn-account.php
git commit -m "feat: add NotificationService for computed user notifications"
```

---

### Task 8: Create notifications tab template

**Files:**
- Create: `web/app/themes/stridence/templates/dashboard/tab-meldingen.php`

**Step 1: Create the template**

```php
<?php
declare(strict_types=1);
defined('ABSPATH') || exit;

$user = $args['user'];
$service = ntdst_get(\Stride\Modules\Notification\NotificationService::class);
$notifications = $service->getNotifications($user->ID);

// Group by today / earlier
$today = wp_date('Y-m-d');
$groups = ['today' => [], 'earlier' => []];
foreach ($notifications as $n) {
    $date = wp_date('Y-m-d', $n['timestamp']);
    $key = ($date === $today) ? 'today' : 'earlier';
    $groups[$key][] = $n;
}
$has_unread = !empty(array_filter($notifications, fn($n) => !$n['read']));
?>

<div class="space-y-6" x-data="{ marking: false }">
    <!-- Header with mark all read -->
    <?php if ($has_unread) : ?>
        <div class="flex justify-end">
            <button @click="marking = true; ntdstAPI.call('stride_mark_notifications_read').then(() => location.reload())"
                    class="text-sm text-primary hover:text-primary-hover cursor-pointer"
                    :class="marking && 'opacity-50 pointer-events-none'">
                <?php esc_html_e('Alles gelezen', 'stridence'); ?>
            </button>
        </div>
    <?php endif; ?>

    <?php if (empty($notifications)) : ?>
        <div class="text-center py-12">
            <?php echo stridence_icon('bell', 'w-10 h-10 text-text-muted/40 mx-auto mb-3'); ?>
            <p class="text-text-muted"><?php esc_html_e('Geen meldingen', 'stridence'); ?></p>
        </div>
    <?php else : ?>
        <!-- Today -->
        <?php if (!empty($groups['today'])) : ?>
            <div>
                <h3 class="text-xs font-semibold text-text-muted uppercase tracking-wider mb-2 px-4">
                    <?php esc_html_e('Vandaag', 'stridence'); ?>
                </h3>
                <div class="bg-surface-card rounded-lg border border-border/60 divide-y divide-border/60">
                    <?php foreach ($groups['today'] as $n) :
                        get_template_part('templates/dashboard/partials/notification-item', null, ['notification' => $n]);
                    endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Earlier -->
        <?php if (!empty($groups['earlier'])) : ?>
            <div>
                <h3 class="text-xs font-semibold text-text-muted uppercase tracking-wider mb-2 px-4">
                    <?php esc_html_e('Eerder', 'stridence'); ?>
                </h3>
                <div class="bg-surface-card rounded-lg border border-border/60 divide-y divide-border/60">
                    <?php foreach ($groups['earlier'] as $n) :
                        get_template_part('templates/dashboard/partials/notification-item', null, ['notification' => $n]);
                    endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
```

**Step 2: Create notification-item partial**

Create `templates/dashboard/partials/notification-item.php`:

```php
<?php
declare(strict_types=1);
defined('ABSPATH') || exit;

$n = $args['notification'];
$time_ago = human_time_diff($n['timestamp'], time());
?>

<a href="<?php echo esc_url($n['url'] ?: '#'); ?>"
   class="notification-item <?php echo !$n['read'] ? 'notification-unread' : ''; ?>">
    <div class="flex-1 min-w-0">
        <p class="text-sm font-medium text-text truncate"><?php echo esc_html($n['title']); ?></p>
        <?php if (!empty($n['body'])) : ?>
            <p class="text-xs text-text-muted mt-0.5 truncate"><?php echo esc_html($n['body']); ?></p>
        <?php endif; ?>
        <p class="text-xs text-text-muted/70 mt-1"><?php echo esc_html($time_ago . ' geleden'); ?></p>
    </div>
</a>
```

**Step 3: Verify tab renders**

Open: `https://stride.ddev.site/mijn-account/?tab=meldingen`
Expected: Notifications grouped by today/earlier. Unread items have indigo dot.

**Step 4: Commit**

```bash
git add web/app/themes/stridence/templates/dashboard/tab-meldingen.php web/app/themes/stridence/templates/dashboard/partials/notification-item.php
git commit -m "feat: add notifications tab with grouped items and mark-all-read"
```

---

## Phase 5: Downloads Page

### Task 9: Create downloads tab template

**Files:**
- Create: `web/app/themes/stridence/templates/dashboard/tab-downloads.php`

**Step 1: Create the template**

This page aggregates certificates, quote PDFs, and (future) invoices from existing service data.

```php
<?php
declare(strict_types=1);
defined('ABSPATH') || exit;

use Stride\Integrations\LearnDash\LearnDashHelper;

$user = $args['user'];

// Certificates
$certificates = LearnDashHelper::getCompletedCoursesWithCertificates($user->ID);

// Quote PDFs — get from UserDashboardService or QuoteService
$dashboardService = ntdst_get(\Stride\Modules\User\UserDashboardService::class);
$quotes_data = $dashboardService->getQuotesData($user->ID);
$quotes = $quotes_data['active'] ?? [];
$cancelled_quotes = $quotes_data['cancelled'] ?? [];
$all_quotes = array_merge($quotes, $cancelled_quotes);
?>

<div class="space-y-8">
    <!-- Certificates -->
    <div>
        <h3 class="text-xs font-semibold text-text-muted uppercase tracking-wider mb-3">
            <?php esc_html_e('Certificaten', 'stridence'); ?>
        </h3>
        <?php if (!empty($certificates)) : ?>
            <div class="bg-surface-card rounded-lg border border-border/60 divide-y divide-border/60">
                <?php foreach ($certificates as $cert) : ?>
                    <div class="list-item-static">
                        <?php echo stridence_icon('award', 'w-5 h-5 text-accent shrink-0'); ?>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-medium text-text truncate"><?php echo esc_html($cert['title']); ?></p>
                            <p class="text-xs text-text-muted"><?php echo esc_html(sprintf(__('Behaald op %s', 'stridence'), $cert['date'])); ?></p>
                        </div>
                        <?php if (!empty($cert['url'])) : ?>
                            <a href="<?php echo esc_url($cert['url']); ?>" target="_blank" class="btn-ghost btn-sm">
                                <?php echo stridence_icon('download', 'w-4 h-4'); ?>
                                PDF
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else : ?>
            <div class="text-sm text-text-muted px-4 py-6 text-center bg-surface-card rounded-lg border border-border/60">
                <?php esc_html_e('Nog geen certificaten beschikbaar', 'stridence'); ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Quotes -->
    <div>
        <h3 class="text-xs font-semibold text-text-muted uppercase tracking-wider mb-3">
            <?php esc_html_e('Offertes', 'stridence'); ?>
        </h3>
        <?php if (!empty($all_quotes)) : ?>
            <div class="bg-surface-card rounded-lg border border-border/60 divide-y divide-border/60">
                <?php foreach ($all_quotes as $quote) : ?>
                    <div class="list-item-static">
                        <?php echo stridence_icon('file-text', 'w-5 h-5 text-text-muted shrink-0'); ?>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-medium text-text truncate">
                                <?php echo esc_html(sprintf(__('Offerte %s', 'stridence'), $quote['number'] ?? '#')); ?>
                            </p>
                            <p class="text-xs text-text-muted"><?php echo esc_html($quote['date'] ?? ''); ?></p>
                        </div>
                        <?php if (!empty($quote['pdf_url'])) : ?>
                            <a href="<?php echo esc_url($quote['pdf_url']); ?>" target="_blank" class="btn-ghost btn-sm">
                                <?php echo stridence_icon('download', 'w-4 h-4'); ?>
                                PDF
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else : ?>
            <div class="text-sm text-text-muted px-4 py-6 text-center bg-surface-card rounded-lg border border-border/60">
                <?php esc_html_e('Nog geen offertes beschikbaar', 'stridence'); ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Invoices (future) -->
    <div>
        <h3 class="text-xs font-semibold text-text-muted uppercase tracking-wider mb-3">
            <?php esc_html_e('Facturen', 'stridence'); ?>
        </h3>
        <div class="text-sm text-text-muted px-4 py-6 text-center bg-surface-card rounded-lg border border-border/60">
            <?php esc_html_e('Nog geen facturen beschikbaar', 'stridence'); ?>
        </div>
    </div>
</div>
```

Note: `LearnDashHelper::getCompletedCoursesWithCertificates()` may not exist yet. Check what helper methods are available and adapt. The certificate data is already used in `tab-certificaten.php` — reuse that same data fetching pattern.

**Step 2: Verify tab renders**

Open: `https://stride.ddev.site/mijn-account/?tab=downloads`
Expected: Three grouped sections. Certificates and quotes show download buttons. Invoices show empty state.

**Step 3: Commit**

```bash
git add web/app/themes/stridence/templates/dashboard/tab-downloads.php
git commit -m "feat: add downloads tab aggregating certificates, quotes, and invoices"
```

---

## Phase 6: Restyle Existing Pages

### Task 10: Restyle courses tab (flat list)

**Files:**
- Modify: `web/app/themes/stridence/templates/dashboard/tab-inschrijvingen.php`

**Step 1: Read current template**

Read `tab-inschrijvingen.php` to understand the current data flow and variables used. The data is fetched from service calls inside the template.

**Step 2: Convert to flat list pattern**

Replace expandable accordion cards with flat `list-item` rows. Each row shows:
- Course title + type badge inline
- E-learning: progress bar + percentage + "Doorgaan →"
- Klassikaal: session count + next session + "Details →"

Wrap active items in a card container with `divide-y`. Keep completed/cancelled as collapsible sections.

**Step 3: Remove accordion Alpine logic**

Replace `x-data="expandable()"` with simple click-to-navigate links.

**Step 4: Verify and commit**

```bash
git add web/app/themes/stridence/templates/dashboard/tab-inschrijvingen.php
git commit -m "refactor: convert courses tab from expandable cards to flat list"
```

---

### Task 11: Restyle trajectories tab

**Files:**
- Modify: `web/app/themes/stridence/templates/dashboard/tab-trajecten.php`

Same flat list approach. Each row: title + mode badge + "4/6 afgerond" + progress bar + "Bekijk →". Remove expandable nesting.

**Commit:**
```bash
git commit -m "refactor: convert trajectories tab to flat list pattern"
```

---

### Task 12: Restyle quotes tab

**Files:**
- Modify: `web/app/themes/stridence/templates/dashboard/tab-offertes.php`

Keep the slide panel — it works well. Update card/list styling to use new tokens. Update panel styling classes to match new color tokens (indigo focus rings, warm borders).

**Commit:**
```bash
git commit -m "style: update quotes tab to new design tokens"
```

---

### Task 13: Restyle certificates tab

**Files:**
- Modify: `web/app/themes/stridence/templates/dashboard/tab-certificaten.php`

Keep 2-column grid. Update card styling to use new tokens. Update gradient header colors from navy to indigo.

**Commit:**
```bash
git commit -m "style: update certificates tab to new design tokens"
```

---

### Task 14: Restyle profile tab

**Files:**
- Modify: `web/app/themes/stridence/templates/dashboard/tab-profiel.php`

Convert accordion sections to flat groups (visible by default, no expand/collapse). Keep inline edit pattern. Update input styles to use `--primary` focus rings. Remove logout (it's now in sidebar).

**Commit:**
```bash
git commit -m "refactor: convert profile tab from accordions to flat sections"
```

---

## Phase 7: Toast Component

### Task 15: Add toast Alpine component

**Files:**
- Create: `web/app/themes/stridence/templates/dashboard/partials/toast.php`

**Step 1: Create toast partial**

A global toast that can be triggered from any Alpine component:

```php
<div x-data="strideToast()" x-on:stride-toast.window="show($event.detail)" x-cloak>
    <template x-if="visible">
        <div class="toast" :class="type === 'error' ? 'toast-error' : 'toast-success'"
             x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="opacity-0 translate-y-2"
             x-transition:enter-end="opacity-100 translate-y-0"
             x-transition:leave="transition ease-in duration-150"
             x-transition:leave-start="opacity-100"
             x-transition:leave-end="opacity-0">
            <span x-text="message"></span>
            <button @click="visible = false" class="text-text-muted hover:text-text cursor-pointer">
                <?php echo stridence_icon('x', 'w-4 h-4'); ?>
            </button>
        </div>
    </template>
</div>

<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('strideToast', () => ({
        visible: false,
        message: '',
        type: 'success',
        timeout: null,
        show(detail) {
            this.message = detail.message || '';
            this.type = detail.type || 'success';
            this.visible = true;
            clearTimeout(this.timeout);
            this.timeout = setTimeout(() => { this.visible = false; }, 4000);
        }
    }));
});
</script>
```

**Step 2: Include toast in page-mijn-account.php**

Add before closing `</div>`:
```php
<?php get_template_part('templates/dashboard/partials/toast'); ?>
```

**Usage from any Alpine component:**
```javascript
$dispatch('stride-toast', { message: 'Profiel bijgewerkt', type: 'success' })
```

**Step 3: Commit**

```bash
git add web/app/themes/stridence/templates/dashboard/partials/toast.php web/app/themes/stridence/page-mijn-account.php
git commit -m "feat: add toast notification component for user feedback"
```

---

## Phase 8: Build & Polish

### Task 16: Build production assets

**Step 1: Run Vite build**

```bash
cd web/app/themes/stridence && npm run build
```

**Step 2: Verify build output**

Check `dist/` contains new CSS with all component classes. No build errors.

**Step 3: Commit build artifacts**

```bash
git add web/app/themes/stridence/dist/
git commit -m "build: production assets for dashboard redesign v2"
```

---

### Task 17: Responsive & accessibility audit

**Step 1: Test at all breakpoints**

- 375px (mobile): bottom nav visible, sidebar hidden, single column
- 768px (tablet): same as mobile but wider content
- 1024px+: sidebar visible, bottom nav hidden, content offset by sidebar width

**Step 2: Keyboard navigation**

- Tab through sidebar items — focus rings visible
- Tab through content — logical order
- Enter activates links

**Step 3: Check color contrast**

All text passes 4.5:1 against backgrounds:
- `#1C1917` on `#FAF8F6` → passes (16.1:1)
- `#78716C` on `#FFFFFF` → passes (5.0:1)
- `#4F46E5` on `#EEF2FF` → check (should pass at 5.6:1)

**Step 4: Screen reader**

- Sidebar has `aria-label="Dashboard navigatie"`
- Active items have `aria-current="page"`
- Notification badge has `aria-label` with count
- Toast uses Alpine transitions (not aria-live yet — add if needed)

**Step 5: Fix any issues found**

**Step 6: Commit**

```bash
git commit -m "fix: accessibility and responsive polish for dashboard redesign"
```

---

## Summary of Files

### New Files
| File | Purpose |
|------|---------|
| `stride-core/Modules/Notification/NotificationService.php` | Compute notifications from existing data |
| `stridence/templates/dashboard/tab-meldingen.php` | Notifications page |
| `stridence/templates/dashboard/tab-downloads.php` | Downloads aggregation page |
| `stridence/templates/dashboard/partials/stat-cards.php` | Dashboard stat cards |
| `stridence/templates/dashboard/partials/notification-item.php` | Single notification row |
| `stridence/templates/dashboard/partials/toast.php` | Toast feedback component |

### Modified Files
| File | Changes |
|------|---------|
| `stridence/src/css/tokens.css` | New color palette (indigo), layout tokens |
| `stridence/tailwind.config.js` | New color mappings, sidebar spacing |
| `stridence/src/css/components.css` | Sidebar, toast, notification, list-item classes |
| `stridence/page-mijn-account.php` | Sidebar layout shell, new tabs |
| `stridence/templates/dashboard/nav-sidebar.php` | Full sidebar rebuild |
| `stridence/templates/dashboard/nav-mobile.php` | Updated with notifications + downloads |
| `stridence/templates/dashboard/tab-home.php` | Redesigned home (stats, flat actions, course grid) |
| `stridence/templates/dashboard/partials/action-items.php` | Simplified action list |
| `stridence/templates/dashboard/tab-inschrijvingen.php` | Flat list pattern |
| `stridence/templates/dashboard/tab-trajecten.php` | Flat list pattern |
| `stridence/templates/dashboard/tab-offertes.php` | Token restyle |
| `stridence/templates/dashboard/tab-certificaten.php` | Token restyle |
| `stridence/templates/dashboard/tab-profiel.php` | Flat sections, remove accordion |
| `stride-core/plugin-config.php` | Register NotificationService |

### Unchanged
| File | Why |
|------|-----|
| All backend services | No business logic changes |
| LearnDash integration | No API changes |
| Enrollment handlers | No form changes |
| Admin dashboard | Out of scope (admin stays separate) |
