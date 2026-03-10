# Dashboard Redesign Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Transform the user dashboard from a tab-first admin panel into a warm, adaptive "Personal Learning Space" with a floating dock, context-dependent home screen, and sidepanels for quick access.

**Architecture:** The existing 5 tabs become pages accessible from a floating dock. A new Home page becomes the default landing with adaptive blocks that assemble based on the user's data. Cards open sidepanels for quick info/actions. Backend adds a `getHomeData()` method to `UserDashboardService`.

**Tech Stack:** PHP templates, Tailwind CSS tokens, Alpine.js components, existing `UserDashboardService`

**Design Doc:** `docs/plans/2026-03-10-dashboard-redesign-design.md`

---

## Phase 1: Design Tokens & CSS Foundation

### Task 1: Update Design Tokens

**Files:**
- Modify: `web/app/themes/stridence/src/css/tokens.css`

**Step 1: Update tokens for warmer dashboard feel**

Update the tokens to add larger border radii and softer shadows for the dashboard. The existing warm palette is already close — we mainly need new radius/shadow tokens and dashboard-specific values.

```css
/* Add to :root in tokens.css, after existing tokens */

/* ── Dashboard-Specific ── */
--radius-2xl: 1rem;         /* 16px - cards, panels */
--radius-3xl: 1.5rem;       /* 24px - hero card */
--shadow-card-soft: 0 1px 3px rgba(0, 0, 0, 0.04);
--shadow-card-hover: 0 4px 12px rgba(0, 0, 0, 0.06);
--shadow-dock: 0 2px 12px rgba(0, 0, 0, 0.08);
--shadow-panel: 0 8px 30px rgba(0, 0, 0, 0.12);

/* ── Dashboard Layout ── */
--dock-width: 56px;
--dock-width-expanded: 180px;
--dashboard-max: 56rem;     /* max-w-4xl equivalent */
```

**Step 2: Verify Vite build works**

Run: `cd web/app/themes/stridence && npm run build`
Expected: Build succeeds without errors.

**Step 3: Commit**

```bash
git add web/app/themes/stridence/src/css/tokens.css
git commit -m "style: add dashboard-specific design tokens for warmer card feel"
```

---

### Task 2: Add Dashboard Component Classes

**Files:**
- Modify: `web/app/themes/stridence/src/css/components.css`

**Step 1: Add dashboard card and dock component classes**

Add these after the existing CARDS section in components.css:

```css
/* ══════════════════════════════════════
   DASHBOARD CARDS (warm variant)
   ══════════════════════════════════════ */

.dash-card {
  @apply bg-surface-card rounded-2xl p-6;
  box-shadow: var(--shadow-card-soft);
  transition: box-shadow var(--duration-normal) var(--ease-out),
              transform var(--duration-normal) var(--ease-out);
}

.dash-card-interactive {
  @apply dash-card cursor-pointer;
}

.dash-card-interactive:hover {
  box-shadow: var(--shadow-card-hover);
  transform: translateY(-1px);
}

.dash-card-hero {
  @apply bg-surface-card rounded-3xl p-6 lg:p-8;
  box-shadow: var(--shadow-card-soft);
  background: linear-gradient(135deg, rgb(var(--color-primary) / 0.03), rgb(var(--color-primary) / 0.08));
}

/* ══════════════════════════════════════
   FLOATING DOCK
   ══════════════════════════════════════ */

.dock {
  @apply fixed left-4 top-1/2 -translate-y-1/2 z-40
         bg-surface-card rounded-2xl py-3
         flex flex-col items-center gap-1
         transition-all;
  width: var(--dock-width);
  box-shadow: var(--shadow-dock);
  transition-duration: var(--duration-normal);
  transition-timing-function: var(--ease-out);
}

.dock.expanded {
  width: var(--dock-width-expanded);
}

.dock-item {
  @apply flex items-center gap-3 w-full px-4 py-2.5
         rounded-xl text-text-muted
         transition-colors duration-fast
         cursor-pointer overflow-hidden whitespace-nowrap;
}

.dock-item:hover {
  @apply text-text bg-surface-alt;
}

.dock-item.active {
  @apply text-primary bg-primary/8 font-medium;
}

.dock-separator {
  @apply w-8 h-px bg-border my-2 mx-auto;
}

.dock-label {
  @apply text-sm opacity-0 transition-opacity;
  transition-duration: var(--duration-normal);
}

.dock.expanded .dock-label {
  @apply opacity-100;
}

/* ══════════════════════════════════════
   ACTION LIST (nudge items)
   ══════════════════════════════════════ */

.action-item {
  @apply flex items-center gap-3 px-4 py-3 rounded-xl
         bg-surface-card cursor-pointer
         transition-colors duration-fast;
  box-shadow: var(--shadow-card-soft);
}

.action-item:hover {
  @apply bg-surface-alt;
}

.action-border-blue {
  @apply border-l-3 border-info;
}

.action-border-amber {
  @apply border-l-3 border-warning;
}

.action-border-green {
  @apply border-l-3 border-success;
}

/* ══════════════════════════════════════
   PROGRESS RING (SVG-based)
   ══════════════════════════════════════ */

.progress-ring circle {
  transition: stroke-dashoffset 700ms var(--ease-out);
}

/* ══════════════════════════════════════
   SKELETON LOADING
   ══════════════════════════════════════ */

.skeleton {
  @apply bg-surface-alt rounded-lg animate-pulse;
}

/* ══════════════════════════════════════
   DASHBOARD SLIDE PANEL (warm variant)
   ══════════════════════════════════════ */

.dash-panel {
  @apply fixed inset-y-0 right-0 z-50
         w-full sm:max-w-md
         bg-surface-card
         flex flex-col;
  box-shadow: var(--shadow-panel);
  border-radius: 1.5rem 0 0 1.5rem;
  transition: transform var(--duration-normal) var(--ease-out);
}

@media (max-width: 639px) {
  .dash-panel {
    @apply inset-x-0 top-auto bottom-0;
    max-height: 90vh;
    border-radius: 1.5rem 1.5rem 0 0;
  }
}
```

**Step 2: Add `border-l-3` utility if not available in Tailwind config**

Check `tailwind.config.js` for borderWidth — if `3` isn't defined, add it. Otherwise, use `border-l-4`.

**Step 3: Build and verify**

Run: `cd web/app/themes/stridence && npm run build`
Expected: Build succeeds.

**Step 4: Commit**

```bash
git add web/app/themes/stridence/src/css/components.css
git commit -m "style: add dashboard card, dock, action list, and panel component classes"
```

---

## Phase 2: Backend — Home Data Service

### Task 3: Add `getHomeData()` to UserDashboardService

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Modules/User/UserDashboardService.php`
- Test: `tests/Unit/UserDashboardServiceTest.php` (create)

**Step 1: Write failing test for `getHomeData()`**

Create `tests/Unit/UserDashboardServiceTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Stride\Modules\User\UserDashboardService;
use Stride\Modules\Enrollment\RegistrationRepository;
use Stride\Modules\Edition\EditionService;
use Stride\Modules\Edition\SessionService;
use Stride\Modules\Attendance\AttendanceService;
use Stride\Modules\Edition\EditionCompletion;

class UserDashboardServiceTest extends TestCase
{
    private UserDashboardService $service;

    protected function setUp(): void
    {
        $this->service = new UserDashboardService(
            $this->createMock(RegistrationRepository::class),
            $this->createMock(EditionService::class),
            $this->createMock(SessionService::class),
            $this->createMock(AttendanceService::class),
            $this->createMock(EditionCompletion::class),
        );
    }

    public function test_getHomeData_returns_expected_structure(): void
    {
        $data = $this->service->getHomeData(1);

        $this->assertArrayHasKey('user', $data);
        $this->assertArrayHasKey('hero', $data);
        $this->assertArrayHasKey('actions', $data);
        $this->assertArrayHasKey('active_enrollments', $data);
        $this->assertArrayHasKey('active_trajectories', $data);
        $this->assertArrayHasKey('recent_certificates', $data);
        $this->assertArrayHasKey('nav_items', $data);
    }

    public function test_getHomeData_nav_items_has_correct_keys(): void
    {
        $data = $this->service->getHomeData(1);

        $nav = $data['nav_items'];
        $this->assertArrayHasKey('opleidingen', $nav);
        $this->assertArrayHasKey('trajecten', $nav);
        $this->assertArrayHasKey('agenda', $nav);
        $this->assertArrayHasKey('offertes', $nav);
        $this->assertArrayHasKey('certificaten', $nav);
    }

    public function test_resolveHero_returns_null_when_no_data(): void
    {
        $hero = $this->invokePrivate('resolveHero', [[], [], [], []]);

        $this->assertNull($hero);
    }

    public function test_resolveHero_prioritizes_upcoming_session(): void
    {
        $sessions = [
            ['date' => date('Y-m-d', strtotime('+1 day')), 'course_title' => 'Test', 'time_start' => '09:00', 'venue' => 'Antwerpen'],
        ];

        $hero = $this->invokePrivate('resolveHero', [$sessions, [['type' => 'enrollment']], [], []]);

        $this->assertEquals('upcoming_session', $hero['type']);
    }

    public function test_resolveHero_shows_action_items_when_no_session(): void
    {
        $actions = [['type' => 'post_course', 'label' => 'Rond af', 'url' => '/test']];

        $hero = $this->invokePrivate('resolveHero', [[], $actions, [], []]);

        $this->assertEquals('action_required', $hero['type']);
    }

    private function invokePrivate(string $method, array $args): mixed
    {
        $ref = new \ReflectionMethod($this->service, $method);
        $ref->setAccessible(true);
        return $ref->invokeArgs($this->service, $args);
    }
}
```

**Step 2: Run test to verify it fails**

Run: `ddev exec vendor/bin/phpunit --filter UserDashboardServiceTest --testsuite Unit`
Expected: FAIL — `getHomeData` method doesn't exist.

**Step 3: Implement `getHomeData()` and `resolveHero()`**

Add to `UserDashboardService.php` after the existing `getQuoteData()` method:

```php
/**
 * Get all data needed for the Home overview screen.
 *
 * @return array{
 *   user: array,
 *   hero: ?array,
 *   actions: array,
 *   active_enrollments: array,
 *   active_trajectories: array,
 *   recent_certificates: array,
 *   nav_items: array
 * }
 */
public function getHomeData(int $userId): array
{
    $enrollmentData = $this->getEnrollmentData($userId);
    $quoteData      = $this->getQuoteData($userId);
    $trajectories   = $this->buildActiveTrajectories($userId);
    $certificates   = array_slice($enrollmentData['completed_items'], 0, 3);

    $wpUser = get_userdata($userId);
    $firstName = $wpUser ? ($wpUser->first_name ?: $wpUser->display_name) : '';

    // Merge active editions + online into one list for the home screen
    $activeEnrollments = array_merge(
        $enrollmentData['active_editions'],
        $enrollmentData['active_online']
    );

    // Determine hero action
    $hero = $this->resolveHero(
        $enrollmentData['upcoming_sessions'],
        $enrollmentData['action_items'],
        $activeEnrollments,
        $certificates,
    );

    // Build adaptive nav flags
    $hasEditions     = !empty($enrollmentData['active_editions']) || !empty($enrollmentData['active_online']) || !empty($enrollmentData['completed_items']);
    $hasTrajectories = !empty($trajectories);
    $hasSessions     = !empty($enrollmentData['upcoming_sessions']);
    $hasQuotes       = !empty($quoteData['active']) || !empty($quoteData['cancelled']);
    $hasCertificates = !empty($enrollmentData['completed_items']);

    return [
        'user' => [
            'name'     => $firstName,
            'initials' => $this->getInitials($wpUser),
            'email'    => $wpUser->user_email ?? '',
        ],
        'hero'                 => $hero,
        'actions'              => $this->buildActionList($enrollmentData, $quoteData),
        'active_enrollments'   => $activeEnrollments,
        'active_trajectories'  => $trajectories,
        'recent_certificates'  => $certificates,
        'nav_items' => [
            'opleidingen'  => $hasEditions,
            'trajecten'    => $hasTrajectories,
            'agenda'       => $hasSessions,
            'offertes'     => $hasQuotes,
            'certificaten' => $hasCertificates,
        ],
    ];
}

/**
 * Resolve the single most important hero action for the Home screen.
 *
 * Priority: upcoming session → action items → in-progress course → certificate → nothing.
 */
private function resolveHero(array $upcomingSessions, array $actionItems, array $activeEnrollments, array $certificates): ?array
{
    // 1. Session today or tomorrow
    $tomorrow = date('Y-m-d', strtotime('+1 day'));
    foreach ($upcomingSessions as $session) {
        if (($session['date'] ?? '') <= $tomorrow) {
            return [
                'type' => 'upcoming_session',
                'data' => $session,
            ];
        }
    }

    // 2. Pending action items
    if (!empty($actionItems)) {
        return [
            'type' => 'action_required',
            'data' => $actionItems[0],
        ];
    }

    // 3. In-progress course (prefer online for "Ga verder" CTA)
    foreach ($activeEnrollments as $enrollment) {
        if (($enrollment['type'] ?? '') === 'online' && ($enrollment['progress'] ?? 0) > 0) {
            return [
                'type' => 'continue_course',
                'data' => $enrollment,
            ];
        }
    }

    // 4. First active enrollment
    if (!empty($activeEnrollments)) {
        return [
            'type' => 'active_enrollment',
            'data' => $activeEnrollments[0],
        ];
    }

    // 5. Recent certificate
    if (!empty($certificates)) {
        return [
            'type' => 'certificate_ready',
            'data' => $certificates[0],
        ];
    }

    return null;
}

/**
 * Build a unified action list for the Home screen nudges.
 */
private function buildActionList(array $enrollmentData, array $quoteData): array
{
    $actions = [];

    // Upcoming sessions (blue)
    foreach ($enrollmentData['upcoming_sessions'] as $session) {
        $actions[] = [
            'type'  => 'session',
            'color' => 'blue',
            'label' => sprintf(
                '%s — %s, %s',
                $session['course_title'] ?? '',
                stride_format_date($session['date'] ?? ''),
                $session['time_start'] ?? ''
            ),
            'url' => '#',
        ];
    }

    // Action items (amber)
    foreach ($enrollmentData['action_items'] as $item) {
        $actions[] = [
            'type'  => 'action',
            'color' => 'amber',
            'label' => $item['label'] . ': ' . $item['course_title'],
            'url'   => $item['url'],
        ];
    }

    // Unsigned quotes (amber)
    foreach ($quoteData['active'] as $quote) {
        $status = $quote['status'];
        if ($status === \Stride\Domain\QuoteStatus::Draft || $status === \Stride\Domain\QuoteStatus::Sent) {
            $actions[] = [
                'type'  => 'quote',
                'color' => 'amber',
                'label' => sprintf(__('Offerte %s wacht op akkoord', 'stride'), $quote['quote_number']),
                'url'   => add_query_arg('tab', 'offertes', get_permalink()),
            ];
        }
    }

    // Recent certificates (green)
    foreach (array_slice($enrollmentData['completed_items'], 0, 2) as $item) {
        $certUrl = $item['certificate_url'] ?? '';
        if ($certUrl) {
            $actions[] = [
                'type'  => 'certificate',
                'color' => 'green',
                'label' => sprintf(__('Certificaat %s beschikbaar', 'stride'), $item['course_title']),
                'url'   => $certUrl,
            ];
        }
    }

    return $actions;
}

/**
 * Build active trajectory data for the Home screen.
 */
private function buildActiveTrajectories(int $userId): array
{
    $regRepo = $this->registrationRepo;
    $trajectories = $regRepo->findTrajectoryEnrollmentsByUser($userId);

    if (empty($trajectories)) {
        return [];
    }

    $result = [];
    foreach ($trajectories as $traj) {
        $trajectoryId = (int) ($traj->trajectory_id ?? $traj->edition_id ?? 0);
        if (!$trajectoryId) continue;

        $post = get_post($trajectoryId);
        if (!$post || $post->post_status !== 'publish') continue;

        $result[] = [
            'id'    => $trajectoryId,
            'title' => $post->post_title,
            'slug'  => $post->post_name,
            'url'   => home_url('/mijn-account/trajecten/' . $post->post_name . '/'),
        ];
    }

    return $result;
}

/**
 * Get user initials for avatar fallback.
 */
private function getInitials(?\WP_User $user): string
{
    if (!$user) return '?';

    $first = mb_substr($user->first_name ?: $user->display_name, 0, 1);
    $last  = mb_substr($user->last_name ?: '', 0, 1);

    return mb_strtoupper($first . $last);
}
```

**Step 4: Run tests to verify they pass**

Run: `ddev exec vendor/bin/phpunit --filter UserDashboardServiceTest --testsuite Unit`
Expected: All tests PASS.

**Step 5: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/User/UserDashboardService.php tests/Unit/UserDashboardServiceTest.php
git commit -m "feat: add getHomeData() for adaptive dashboard home screen"
```

---

## Phase 3: Navigation — Floating Dock & Page Shell

### Task 4: Create the Floating Dock Template

**Files:**
- Create: `web/app/themes/stridence/templates/dashboard/nav-dock.php`

**Step 1: Create the dock template**

```php
<?php
/**
 * Dashboard Floating Dock Navigation
 *
 * Floating icon sidebar for desktop. Expands on hover to show labels.
 * Items are adaptive — only shows nav items relevant to the user.
 *
 * @param array $args {
 *     @type string $current_tab   Active page slug
 *     @type array  $nav_items     Flags for which items to show
 * }
 * @package stridence
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

$current_tab = $args['current_tab'] ?? 'home';
$nav_items   = $args['nav_items'] ?? [];

$base_url = get_permalink();

// Always-visible items + conditional items
$tabs = [
    'home' => [
        'label'   => __('Home', 'stridence'),
        'icon'    => 'home',
        'show'    => true,
        'url'     => $base_url,
    ],
    'inschrijvingen' => [
        'label'   => __('Opleidingen', 'stridence'),
        'icon'    => 'book-open',
        'show'    => $nav_items['opleidingen'] ?? true,
        'url'     => add_query_arg('tab', 'inschrijvingen', $base_url),
    ],
    'trajecten' => [
        'label'   => __('Trajecten', 'stridence'),
        'icon'    => 'layers',
        'show'    => $nav_items['trajecten'] ?? false,
        'url'     => add_query_arg('tab', 'trajecten', $base_url),
    ],
    'offertes' => [
        'label'   => __('Offertes', 'stridence'),
        'icon'    => 'file-text',
        'show'    => $nav_items['offertes'] ?? false,
        'url'     => add_query_arg('tab', 'offertes', $base_url),
    ],
    'certificaten' => [
        'label'   => __('Certificaten', 'stridence'),
        'icon'    => 'award',
        'show'    => $nav_items['certificaten'] ?? false,
        'url'     => add_query_arg('tab', 'certificaten', $base_url),
    ],
];

// Profiel is always last, separated
$profiel = [
    'label' => __('Profiel', 'stridence'),
    'icon'  => 'user',
    'show'  => true,
    'url'   => add_query_arg('tab', 'profiel', $base_url),
];
?>

<nav x-data="{ expanded: false }"
     @mouseenter="expanded = true"
     @mouseleave="expanded = false"
     :class="expanded ? 'dock expanded' : 'dock'"
     class="dock hidden lg:flex"
     aria-label="<?php esc_attr_e('Dashboard navigatie', 'stridence'); ?>">

    <?php foreach ($tabs as $slug => $tab) :
        if (!$tab['show']) continue;
        $is_active = ($current_tab === $slug);
    ?>
        <a href="<?php echo esc_url($tab['url']); ?>"
           class="dock-item <?php echo $is_active ? 'active' : ''; ?>"
           <?php echo $is_active ? 'aria-current="page"' : ''; ?>>
            <?php echo stridence_icon($tab['icon'], 'w-5 h-5 shrink-0'); ?>
            <span class="dock-label"><?php echo esc_html($tab['label']); ?></span>
        </a>
    <?php endforeach; ?>

    <div class="dock-separator"></div>

    <a href="<?php echo esc_url($profiel['url']); ?>"
       class="dock-item <?php echo ($current_tab === 'profiel') ? 'active' : ''; ?>"
       <?php echo ($current_tab === 'profiel') ? 'aria-current="page"' : ''; ?>>
        <?php echo stridence_icon($profiel['icon'], 'w-5 h-5 shrink-0'); ?>
        <span class="dock-label"><?php echo esc_html($profiel['label']); ?></span>
    </a>
</nav>
```

**Step 2: Commit**

```bash
git add web/app/themes/stridence/templates/dashboard/nav-dock.php
git commit -m "feat: add floating dock navigation template"
```

---

### Task 5: Update Mobile Navigation to Be Adaptive

**Files:**
- Modify: `web/app/themes/stridence/templates/dashboard/nav-mobile.php`

**Step 1: Update nav-mobile.php to accept and use nav_items**

The mobile nav should now include "Home" as the first item and filter items based on `nav_items` flags, just like the dock.

Replace the entire `$tabs` array and rendering loop to match the dock's adaptive pattern. Keep the same visual structure (fixed bottom bar) but add Home and filter items.

Key changes:
- Add `'home'` as first tab, always visible
- Add `$nav_items` from `$args` to control visibility
- If total visible items > 5, show last slot as "Meer" overflow (defer to Phase 6)

```php
$current_tab = $args['current_tab'] ?? 'home';
$nav_items   = $args['nav_items'] ?? [];

$base_url = get_permalink();

$tabs = [
    'home' => ['label' => __('Home', 'stridence'), 'icon' => 'home', 'show' => true, 'url' => $base_url],
    'inschrijvingen' => ['label' => __('Cursussen', 'stridence'), 'icon' => 'book-open', 'show' => $nav_items['opleidingen'] ?? true, 'url' => add_query_arg('tab', 'inschrijvingen', $base_url)],
    'trajecten' => ['label' => __('Trajecten', 'stridence'), 'icon' => 'layers', 'show' => $nav_items['trajecten'] ?? false, 'url' => add_query_arg('tab', 'trajecten', $base_url)],
    'offertes' => ['label' => __('Offertes', 'stridence'), 'icon' => 'file-text', 'show' => $nav_items['offertes'] ?? false, 'url' => add_query_arg('tab', 'offertes', $base_url)],
    'certificaten' => ['label' => __('Certificaten', 'stridence'), 'icon' => 'award', 'show' => $nav_items['certificaten'] ?? false, 'url' => add_query_arg('tab', 'certificaten', $base_url)],
    'profiel' => ['label' => __('Profiel', 'stridence'), 'icon' => 'user', 'show' => true, 'url' => add_query_arg('tab', 'profiel', $base_url)],
];

// Filter to visible items only
$visible_tabs = array_filter($tabs, fn($t) => $t['show']);
```

The rendering loop stays the same as current — iterate `$visible_tabs` and render anchors.

**Step 2: Commit**

```bash
git add web/app/themes/stridence/templates/dashboard/nav-mobile.php
git commit -m "feat: make mobile nav adaptive with home tab and conditional items"
```

---

### Task 6: Rewrite Page Shell (page-mijn-account.php)

**Files:**
- Modify: `web/app/themes/stridence/page-mijn-account.php`

**Step 1: Rewrite the page template**

The new layout: no sidebar grid. Full-width centered content with dock floating outside. Home is the default tab.

```php
<?php
/**
 * Template Name: Mijn Account
 *
 * Dashboard shell: floating dock + centered content.
 * Home is the default landing page.
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

if (!is_user_logged_in()) {
    wp_safe_redirect(wp_login_url(get_permalink()));
    exit;
}

$user = wp_get_current_user();

// Home is the new default — existing tabs still available
$valid_tabs = ['home', 'inschrijvingen', 'trajecten', 'offertes', 'certificaten', 'profiel'];
$current_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'home';

if (!in_array($current_tab, $valid_tabs, true)) {
    $current_tab = 'home';
}

// For the Home tab, fetch home data (includes nav_items)
// For other tabs, still fetch nav_items for dock
$dashboardService = ntdst_get(\Stride\Modules\User\UserDashboardService::class);
$homeData = $dashboardService->getHomeData($user->ID);
$nav_items = $homeData['nav_items'];

get_header();
?>

<div class="min-h-screen bg-surface pb-20 lg:pb-0">

    <!-- Floating Dock (Desktop) -->
    <?php
    get_template_part('templates/dashboard/nav-dock', null, [
        'current_tab' => $current_tab,
        'nav_items'   => $nav_items,
    ]);
    ?>

    <!-- Main Content Area -->
    <main class="max-w-4xl mx-auto px-6 lg:px-8 py-8 lg:py-12">
        <?php
        if ($current_tab === 'home') {
            get_template_part('templates/dashboard/tab-home', null, [
                'user'      => $user,
                'home_data' => $homeData,
            ]);
        } else {
            get_template_part("templates/dashboard/tab-{$current_tab}", null, [
                'user' => $user,
            ]);
        }
        ?>
    </main>

    <!-- Mobile Navigation -->
    <?php
    get_template_part('templates/dashboard/nav-mobile', null, [
        'current_tab' => $current_tab,
        'nav_items'   => $nav_items,
    ]);
    ?>
</div>

<?php get_footer(); ?>
```

Key changes from current:
- Default tab: `home` instead of `inschrijvingen`
- No sidebar grid — `max-w-4xl mx-auto` centered content
- Background: `bg-surface` (warm cream) instead of `bg-surface-alt`
- `getHomeData()` called once, passed to Home template
- `nav_items` passed to both dock and mobile nav
- No page header with greeting (greeting moves into Home tab)

**Step 2: Verify the page loads**

Start DDEV if needed: `ddev start`
Navigate to: `https://stride.ddev.site/mijn-account/`
Expected: Page loads without fatal error. Home tab template missing is OK (next task).

**Step 3: Commit**

```bash
git add web/app/themes/stridence/page-mijn-account.php
git commit -m "feat: rewrite dashboard shell with floating dock and centered content"
```

---

## Phase 4: Home Screen Template

### Task 7: Create the Home Tab Template

**Files:**
- Create: `web/app/themes/stridence/templates/dashboard/tab-home.php`

**Step 1: Create the adaptive home screen template**

This is the largest single template. It renders adaptive blocks based on the `$homeData` array. Only blocks with data are shown.

```php
<?php
/**
 * Dashboard Home Tab — Adaptive Personal Overview
 *
 * Assembles blocks based on user's actual data.
 * E-learners see minimal UI. Trajectory users see rich progress.
 *
 * @param array $args {
 *     @type WP_User $user       Current user
 *     @type array   $home_data  From UserDashboardService::getHomeData()
 * }
 * @package stridence
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

$user = $args['user'] ?? wp_get_current_user();
$data = $args['home_data'] ?? [];

$userData       = $data['user'] ?? [];
$hero           = $data['hero'] ?? null;
$actions        = $data['actions'] ?? [];
$enrollments    = $data['active_enrollments'] ?? [];
$trajectories   = $data['active_trajectories'] ?? [];
$certificates   = $data['recent_certificates'] ?? [];

// Time-of-day greeting
$hour = (int) date('G');
$greeting = match (true) {
    $hour < 12  => __('Goedemorgen', 'stridence'),
    $hour < 18  => __('Goedemiddag', 'stridence'),
    default     => __('Goedenavond', 'stridence'),
};
?>

<div class="space-y-8">

    <!-- Block 1: Greeting -->
    <header class="flex items-center gap-4">
        <div class="w-12 h-12 rounded-full bg-primary/10 flex items-center justify-center shrink-0">
            <span class="text-primary font-semibold text-sm">
                <?php echo esc_html($userData['initials'] ?? '?'); ?>
            </span>
        </div>
        <div>
            <h1 class="text-2xl font-medium text-text">
                <?php echo esc_html($greeting . ', ' . ($userData['name'] ?? '')); ?>
            </h1>
            <p class="text-sm text-text-muted">
                <?php echo esc_html(wp_date('l j F')); ?>
            </p>
        </div>
    </header>

    <!-- Block 2: Hero Action -->
    <?php if ($hero) : ?>
        <?php get_template_part('templates/dashboard/partials/hero-action', null, ['hero' => $hero]); ?>
    <?php endif; ?>

    <!-- Block 3: Acties (nudge list) -->
    <?php if (!empty($actions)) : ?>
        <section>
            <h2 class="text-sm font-medium text-text-muted uppercase tracking-wide mb-3">
                <?php esc_html_e('Acties', 'stridence'); ?>
            </h2>
            <div class="space-y-2">
                <?php foreach ($actions as $action) :
                    $borderClass = match ($action['color'] ?? 'blue') {
                        'amber' => 'action-border-amber',
                        'green' => 'action-border-green',
                        default => 'action-border-blue',
                    };
                ?>
                    <a href="<?php echo esc_url($action['url']); ?>"
                       class="action-item <?php echo esc_attr($borderClass); ?>">
                        <span class="text-sm text-text"><?php echo esc_html($action['label']); ?></span>
                        <?php echo stridence_icon('chevron-right', 'w-4 h-4 text-text-muted ml-auto shrink-0'); ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <!-- Block 4: Mijn Opleidingen -->
    <?php if (!empty($enrollments)) : ?>
        <section>
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-lg font-medium text-text">
                    <?php esc_html_e('Mijn opleidingen', 'stridence'); ?>
                </h2>
                <a href="<?php echo esc_url(add_query_arg('tab', 'inschrijvingen', get_permalink())); ?>"
                   class="text-sm text-primary hover:underline">
                    <?php esc_html_e('Alles bekijken', 'stridence'); ?>
                </a>
            </div>
            <div class="grid sm:grid-cols-2 gap-5">
                <?php foreach (array_slice($enrollments, 0, 4) as $enrollment) : ?>
                    <?php get_template_part('templates/dashboard/partials/enrollment-card', null, [
                        'enrollment' => $enrollment,
                    ]); ?>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <!-- Block 5: Mijn Trajecten -->
    <?php if (!empty($trajectories)) : ?>
        <section>
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-lg font-medium text-text">
                    <?php esc_html_e('Mijn trajecten', 'stridence'); ?>
                </h2>
                <a href="<?php echo esc_url(add_query_arg('tab', 'trajecten', get_permalink())); ?>"
                   class="text-sm text-primary hover:underline">
                    <?php esc_html_e('Alles bekijken', 'stridence'); ?>
                </a>
            </div>
            <div class="space-y-4">
                <?php foreach ($trajectories as $traj) : ?>
                    <a href="<?php echo esc_url($traj['url']); ?>"
                       class="dash-card-interactive block">
                        <div class="flex items-center justify-between">
                            <div>
                                <h3 class="font-medium text-text"><?php echo esc_html($traj['title']); ?></h3>
                            </div>
                            <?php echo stridence_icon('chevron-right', 'w-5 h-5 text-text-muted shrink-0'); ?>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <!-- Block 6: Recent Certificates -->
    <?php if (!empty($certificates)) : ?>
        <section>
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-lg font-medium text-text">
                    <?php esc_html_e('Recent behaald', 'stridence'); ?>
                </h2>
                <a href="<?php echo esc_url(add_query_arg('tab', 'certificaten', get_permalink())); ?>"
                   class="text-sm text-primary hover:underline">
                    <?php esc_html_e('Alle certificaten', 'stridence'); ?>
                </a>
            </div>
            <div class="space-y-2">
                <?php foreach ($certificates as $cert) : ?>
                    <div class="dash-card flex items-center justify-between">
                        <div class="min-w-0">
                            <p class="font-medium text-text truncate"><?php echo esc_html($cert['course_title'] ?? ''); ?></p>
                            <p class="text-sm text-text-muted"><?php echo esc_html(stride_format_date($cert['completed_at'] ?? '')); ?></p>
                        </div>
                        <?php if (!empty($cert['certificate_url'])) : ?>
                            <a href="<?php echo esc_url($cert['certificate_url']); ?>"
                               class="btn-ghost btn-sm shrink-0" target="_blank">
                                <?php echo stridence_icon('download', 'w-4 h-4'); ?>
                                <span><?php esc_html_e('Download', 'stridence'); ?></span>
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <!-- Empty state: nothing at all -->
    <?php if (empty($enrollments) && empty($trajectories) && empty($certificates) && empty($actions)) : ?>
        <?php get_template_part('partials/empty-state', null, [
            'icon'    => 'book-open',
            'title'   => __('Welkom bij Stride', 'stridence'),
            'message' => __('Je hebt nog geen actieve opleidingen. Ontdek ons aanbod en schrijf je in.', 'stridence'),
            'cta_label' => __('Bekijk aanbod', 'stridence'),
            'cta_url'   => home_url('/opleidingen/'),
        ]); ?>
    <?php endif; ?>

</div>
```

**Step 2: Commit**

```bash
git add web/app/themes/stridence/templates/dashboard/tab-home.php
git commit -m "feat: create adaptive home screen template with greeting, hero, actions, and enrollments"
```

---

### Task 8: Create Hero Action Partial

**Files:**
- Create: `web/app/themes/stridence/templates/dashboard/partials/hero-action.php`

**Step 1: Create the hero card partial**

```php
<?php
/**
 * Dashboard Hero Action Card
 *
 * Context-dependent hero showing the single most important action.
 *
 * @param array $args {
 *     @type array $hero { type: string, data: array }
 * }
 * @package stridence
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

$hero = $args['hero'] ?? null;
if (!$hero) return;

$type = $hero['type'] ?? '';
$data = $hero['data'] ?? [];
?>

<div class="dash-card-hero">
    <?php match ($type) {
        'upcoming_session' => (function () use ($data) {
            $isToday = ($data['date'] ?? '') === date('Y-m-d');
            $label = $isToday
                ? __('Vandaag', 'stridence')
                : __('Binnenkort', 'stridence');
            ?>
            <p class="text-sm font-medium text-primary mb-2"><?php echo esc_html($label); ?></p>
            <h3 class="text-xl font-medium text-text mb-1">
                <?php echo esc_html($data['course_title'] ?? ''); ?>
            </h3>
            <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-text-muted mt-3">
                <span class="flex items-center gap-1.5">
                    <?php echo stridence_icon('calendar', 'w-4 h-4'); ?>
                    <?php echo esc_html(stride_format_date($data['date'] ?? '')); ?>
                </span>
                <?php if (!empty($data['time_start'])) : ?>
                    <span class="flex items-center gap-1.5">
                        <?php echo stridence_icon('clock', 'w-4 h-4'); ?>
                        <?php echo esc_html($data['time_start']); ?>
                    </span>
                <?php endif; ?>
                <?php if (!empty($data['venue'])) : ?>
                    <span class="flex items-center gap-1.5">
                        <?php echo stridence_icon('map-pin', 'w-4 h-4'); ?>
                        <?php echo esc_html($data['venue']); ?>
                    </span>
                <?php endif; ?>
            </div>
            <?php
        })(),

        'action_required' => (function () use ($data) {
            ?>
            <p class="text-sm font-medium text-warning mb-2"><?php esc_html_e('Actie vereist', 'stridence'); ?></p>
            <h3 class="text-xl font-medium text-text mb-1">
                <?php echo esc_html($data['course_title'] ?? ''); ?>
            </h3>
            <p class="text-sm text-text-muted mb-4"><?php echo esc_html($data['label'] ?? ''); ?></p>
            <a href="<?php echo esc_url($data['url'] ?? '#'); ?>" class="btn-primary btn-sm">
                <?php esc_html_e('Taken bekijken', 'stridence'); ?>
            </a>
            <?php
        })(),

        'continue_course' => (function () use ($data) {
            $progress = (int) ($data['progress'] ?? 0);
            ?>
            <div class="flex items-start justify-between gap-4">
                <div>
                    <p class="text-sm font-medium text-primary mb-2"><?php esc_html_e('Ga verder', 'stridence'); ?></p>
                    <h3 class="text-xl font-medium text-text mb-1">
                        <?php echo esc_html($data['course_title'] ?? ''); ?>
                    </h3>
                    <p class="text-sm text-text-muted mb-4">
                        <?php echo esc_html(($data['format_label'] ?? 'Online') . ' — ' . $progress . '% voltooid'); ?>
                    </p>
                    <a href="<?php echo esc_url($data['course_url'] ?? '#'); ?>" class="btn-primary btn-sm">
                        <?php esc_html_e('Ga verder', 'stridence'); ?>
                    </a>
                </div>
                <?php get_template_part('templates/dashboard/partials/progress-ring', null, [
                    'progress' => $progress,
                    'size' => 64,
                ]); ?>
            </div>
            <?php
        })(),

        'active_enrollment' => (function () use ($data) {
            ?>
            <p class="text-sm font-medium text-primary mb-2"><?php esc_html_e('Actieve opleiding', 'stridence'); ?></p>
            <h3 class="text-xl font-medium text-text mb-1">
                <?php echo esc_html($data['course_title'] ?? ''); ?>
            </h3>
            <?php if (!empty($data['start_date'])) : ?>
                <p class="text-sm text-text-muted">
                    <?php echo esc_html(__('Start:', 'stridence') . ' ' . stride_format_date($data['start_date'])); ?>
                </p>
            <?php endif; ?>
            <?php
        })(),

        'certificate_ready' => (function () use ($data) {
            ?>
            <p class="text-sm font-medium text-success mb-2"><?php esc_html_e('Gefeliciteerd!', 'stridence'); ?></p>
            <h3 class="text-xl font-medium text-text mb-1">
                <?php echo esc_html($data['course_title'] ?? ''); ?>
            </h3>
            <p class="text-sm text-text-muted mb-4"><?php esc_html_e('Je certificaat is beschikbaar.', 'stridence'); ?></p>
            <?php if (!empty($data['certificate_url'])) : ?>
                <a href="<?php echo esc_url($data['certificate_url']); ?>" class="btn-primary btn-sm" target="_blank">
                    <?php echo stridence_icon('download', 'w-4 h-4'); ?>
                    <?php esc_html_e('Download certificaat', 'stridence'); ?>
                </a>
            <?php endif; ?>
            <?php
        })(),

        default => null,
    }; ?>
</div>
```

**Step 2: Commit**

```bash
git add web/app/themes/stridence/templates/dashboard/partials/hero-action.php
git commit -m "feat: add hero action partial with context-dependent CTA"
```

---

### Task 9: Create Progress Ring Partial

**Files:**
- Create: `web/app/themes/stridence/templates/dashboard/partials/progress-ring.php`

**Step 1: Create SVG progress ring partial**

```php
<?php
/**
 * SVG Progress Ring
 *
 * Circular progress indicator that animates on mount.
 *
 * @param array $args {
 *     @type int $progress  Percentage (0-100)
 *     @type int $size      Diameter in pixels (default 48)
 * }
 * @package stridence
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

$progress = max(0, min(100, (int) ($args['progress'] ?? 0)));
$size     = (int) ($args['size'] ?? 48);
$radius   = 15.9155;
$circumference = 2 * M_PI * $radius;
$dasharray = $circumference;
$dashoffset = $circumference - ($progress / 100) * $circumference;
?>

<svg class="progress-ring shrink-0" width="<?php echo $size; ?>" height="<?php echo $size; ?>" viewBox="0 0 36 36"
     x-data="{ offset: <?php echo round($circumference); ?> }"
     x-init="setTimeout(() => offset = <?php echo round($dashoffset, 2); ?>, 100)">
    <circle cx="18" cy="18" r="<?php echo $radius; ?>"
            fill="none" stroke="rgb(var(--color-surface-alt))" stroke-width="3" />
    <circle cx="18" cy="18" r="<?php echo $radius; ?>"
            fill="none" stroke="rgb(var(--color-primary))" stroke-width="3"
            stroke-linecap="round"
            stroke-dasharray="<?php echo round($dasharray, 2); ?>"
            :stroke-dashoffset="offset"
            transform="rotate(-90 18 18)" />
    <text x="18" y="20.5" text-anchor="middle"
          class="text-[10px] font-semibold" fill="rgb(var(--color-text))">
        <?php echo $progress; ?>%
    </text>
</svg>
```

**Step 2: Commit**

```bash
git add web/app/themes/stridence/templates/dashboard/partials/progress-ring.php
git commit -m "feat: add animated SVG progress ring partial"
```

---

### Task 10: Create Enrollment Card Partial for Home Screen

**Files:**
- Create: `web/app/themes/stridence/templates/dashboard/partials/enrollment-card.php`

**Step 1: Create the enrollment card for the home overview**

This is a compact card used on the Home screen — simpler than the full edition card in `tab-inschrijvingen.php`.

```php
<?php
/**
 * Dashboard Enrollment Card (Home screen)
 *
 * Compact card showing an active enrollment with key info + single CTA.
 * Used on the Home overview — not the full enrollments list.
 *
 * @param array $args {
 *     @type array $enrollment  Enrollment data from UserDashboardService
 * }
 * @package stridence
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

$item = $args['enrollment'] ?? [];
if (empty($item)) return;

$type     = $item['type'] ?? 'edition';
$title    = $item['course_title'] ?? '';
$progress = (int) ($item['progress'] ?? 0);

// Determine type label
$typeLabel = match ($type) {
    'online' => $item['format_label'] ?? __('Online', 'stridence'),
    default  => __('Klassikaal', 'stridence'),
};

// Determine CTA
$ctaLabel = match ($type) {
    'online' => __('Ga verder', 'stridence'),
    default  => __('Bekijk', 'stridence'),
};

$ctaUrl = match ($type) {
    'online' => $item['course_url'] ?? '#',
    default  => '#',  // Opens sidepanel via Alpine (Phase 5)
};
?>

<div class="dash-card-interactive" data-enrollment-type="<?php echo esc_attr($type); ?>">
    <div class="flex items-start justify-between gap-3 mb-3">
        <div class="min-w-0">
            <span class="badge badge-primary text-xs mb-2 inline-block">
                <?php echo esc_html($typeLabel); ?>
            </span>
            <h3 class="font-medium text-text truncate"><?php echo esc_html($title); ?></h3>
        </div>
        <?php if ($progress > 0) : ?>
            <?php get_template_part('templates/dashboard/partials/progress-ring', null, [
                'progress' => $progress,
                'size'     => 44,
            ]); ?>
        <?php endif; ?>
    </div>

    <?php if ($type === 'edition' && !empty($item['start_date'])) : ?>
        <p class="text-sm text-text-muted flex items-center gap-1.5">
            <?php echo stridence_icon('calendar', 'w-3.5 h-3.5'); ?>
            <?php echo esc_html(stride_format_date($item['start_date'])); ?>
            <?php if (!empty($item['venue'])) : ?>
                <span class="mx-1">&middot;</span>
                <?php echo stridence_icon('map-pin', 'w-3.5 h-3.5'); ?>
                <?php echo esc_html($item['venue']); ?>
            <?php endif; ?>
        </p>
    <?php elseif ($type === 'online') : ?>
        <p class="text-sm text-text-muted">
            <?php echo esc_html(sprintf(
                __('%d van %d lessen', 'stridence'),
                $item['completed_lessons'] ?? 0,
                $item['total_lessons'] ?? 0
            )); ?>
        </p>
    <?php endif; ?>

    <div class="mt-4">
        <a href="<?php echo esc_url($ctaUrl); ?>" class="btn-ghost btn-sm text-sm">
            <?php echo esc_html($ctaLabel); ?>
            <?php echo stridence_icon('arrow-right', 'w-4 h-4'); ?>
        </a>
    </div>
</div>
```

**Step 2: Commit**

```bash
git add web/app/themes/stridence/templates/dashboard/partials/enrollment-card.php
git commit -m "feat: add compact enrollment card partial for home screen"
```

---

## Phase 5: Sidepanels

### Task 11: Create Edition/Course Sidepanel Template

**Files:**
- Create: `web/app/themes/stridence/templates/dashboard/partials/panel-enrollment.php`

**Step 1: Create the enrollment sidepanel**

This panel opens when a user clicks an enrollment card on the Home screen. It shows contextual info and quick actions for both edition and online enrollments.

```php
<?php
/**
 * Enrollment Detail Sidepanel
 *
 * Quick-view panel for enrollment details — shown inline via Alpine.
 * The actual content is loaded via the enrollment data already in the page.
 * This template defines the panel shell and content layout.
 *
 * @package stridence
 */

declare(strict_types=1);

defined('ABSPATH') || exit;
?>

<!-- Panel shell — managed by Alpine enrollmentPanel component -->
<template x-if="activeEnrollment">
    <div>
        <!-- Header -->
        <div class="slide-panel-header">
            <h3 x-text="activeEnrollment.course_title"></h3>
            <button @click="closePanel()" class="text-text-muted hover:text-text p-1 rounded-lg hover:bg-surface-alt transition-colors">
                <?php echo stridence_icon('x', 'w-5 h-5'); ?>
            </button>
        </div>

        <!-- Body -->
        <div class="slide-panel-body">

            <!-- Type badge + progress -->
            <div class="flex items-center justify-between">
                <span class="badge badge-primary" x-text="activeEnrollment.type === 'online' ? (activeEnrollment.format_label || 'Online') : 'Klassikaal'"></span>
                <template x-if="activeEnrollment.progress > 0">
                    <span class="text-sm font-medium text-text" x-text="activeEnrollment.progress + '% voltooid'"></span>
                </template>
            </div>

            <!-- Progress bar -->
            <template x-if="activeEnrollment.progress > 0">
                <div class="h-2 bg-surface-alt rounded-full overflow-hidden">
                    <div class="h-full bg-primary rounded-full transition-all duration-700"
                         :style="'width: ' + activeEnrollment.progress + '%'"></div>
                </div>
            </template>

            <!-- Sessions (for editions) -->
            <template x-if="activeEnrollment.type === 'edition' && activeEnrollment.sessions?.length">
                <div>
                    <h4 class="text-sm font-medium text-text-muted uppercase tracking-wide mb-3">
                        <?php esc_html_e('Sessies', 'stridence'); ?>
                    </h4>
                    <div class="space-y-2">
                        <template x-for="session in activeEnrollment.sessions" :key="session.id">
                            <div class="flex items-center gap-3 py-2 text-sm">
                                <div class="w-2 h-2 rounded-full shrink-0"
                                     :class="{
                                        'bg-success': session.attendance === 'present',
                                        'bg-error': session.attendance === 'absent',
                                        'bg-surface-alt': !session.attendance || session.attendance === 'pending'
                                     }"></div>
                                <span class="text-text-muted" x-text="session.date_formatted || session.date"></span>
                                <span class="text-text" x-text="session.title || ''"></span>
                            </div>
                        </template>
                    </div>
                </div>
            </template>

            <!-- Online course info -->
            <template x-if="activeEnrollment.type === 'online'">
                <div class="space-y-3">
                    <div class="flex justify-between text-sm">
                        <span class="text-text-muted"><?php esc_html_e('Lessen', 'stridence'); ?></span>
                        <span class="text-text font-medium" x-text="(activeEnrollment.completed_lessons || 0) + ' / ' + (activeEnrollment.total_lessons || 0)"></span>
                    </div>
                    <template x-if="activeEnrollment.days_remaining !== null">
                        <div class="flex justify-between text-sm">
                            <span class="text-text-muted"><?php esc_html_e('Resterende dagen', 'stridence'); ?></span>
                            <span class="text-text font-medium" x-text="activeEnrollment.days_remaining + ' dagen'"></span>
                        </div>
                    </template>
                </div>
            </template>

            <!-- Quick links section -->
            <template x-if="activeEnrollment.complete_url || activeEnrollment.course_url">
                <div>
                    <h4 class="text-sm font-medium text-text-muted uppercase tracking-wide mb-3">
                        <?php esc_html_e('Acties', 'stridence'); ?>
                    </h4>
                    <div class="space-y-2">
                        <template x-if="activeEnrollment.course_url">
                            <a :href="activeEnrollment.course_url" class="action-item action-border-blue">
                                <?php echo stridence_icon('play', 'w-4 h-4 text-info shrink-0'); ?>
                                <span class="text-sm"><?php esc_html_e('Ga verder met cursus', 'stridence'); ?></span>
                            </a>
                        </template>
                        <template x-if="activeEnrollment.complete_url">
                            <a :href="activeEnrollment.complete_url" class="action-item action-border-amber">
                                <?php echo stridence_icon('check-circle', 'w-4 h-4 text-warning shrink-0'); ?>
                                <span class="text-sm"><?php esc_html_e('Taken bekijken', 'stridence'); ?></span>
                            </a>
                        </template>
                    </div>
                </div>
            </template>
        </div>

        <!-- Footer -->
        <div class="slide-panel-footer flex gap-3">
            <template x-if="activeEnrollment.type === 'online' && activeEnrollment.course_url">
                <a :href="activeEnrollment.course_url" class="btn-primary flex-1 text-center">
                    <?php esc_html_e('Ga verder', 'stridence'); ?>
                </a>
            </template>
            <template x-if="activeEnrollment.type === 'edition'">
                <a :href="'?tab=inschrijvingen'" class="btn-secondary flex-1 text-center">
                    <?php esc_html_e('Alle details', 'stridence'); ?>
                </a>
            </template>
        </div>
    </div>
</template>
```

**Step 2: Commit**

```bash
git add web/app/themes/stridence/templates/dashboard/partials/panel-enrollment.php
git commit -m "feat: add enrollment sidepanel template for quick-view from home"
```

---

### Task 12: Add Alpine.js Dashboard Components

**Files:**
- Modify: `web/app/themes/stridence/src/main.js`

**Step 1: Add `dashboardHome` Alpine component**

Add to the Alpine data/component section in `main.js`:

```javascript
// Dashboard Home — manages enrollment panel state
Alpine.data('dashboardHome', () => ({
    // Panel state
    panelOpen: false,
    activeEnrollment: null,

    // Enrollment data passed from PHP via x-data attribute
    enrollments: [],

    init() {
        // Close panel on Escape
        this.$watch('panelOpen', (open) => {
            document.body.style.overflow = open ? 'hidden' : '';
        });
    },

    openPanel(enrollment) {
        this.activeEnrollment = enrollment;
        this.panelOpen = true;
    },

    closePanel() {
        this.panelOpen = false;
        setTimeout(() => { this.activeEnrollment = null; }, 300);
    },
}));
```

**Step 2: Build frontend**

Run: `cd web/app/themes/stridence && npm run build`
Expected: Build succeeds.

**Step 3: Commit**

```bash
git add web/app/themes/stridence/src/main.js
git commit -m "feat: add dashboardHome Alpine component for enrollment panel"
```

---

## Phase 6: Visual Polish & Integration

### Task 13: Add Missing SVG Icons

**Files:**
- Create: `web/app/themes/stridence/icons/home.svg`
- Create: `web/app/themes/stridence/icons/arrow-right.svg`
- Create: `web/app/themes/stridence/icons/chevron-right.svg` (if not existing)
- Create: `web/app/themes/stridence/icons/play.svg`
- Create: `web/app/themes/stridence/icons/check-circle.svg`
- Create: `web/app/themes/stridence/icons/clock.svg`
- Create: `web/app/themes/stridence/icons/map-pin.svg`
- Create: `web/app/themes/stridence/icons/download.svg`
- Create: `web/app/themes/stridence/icons/x.svg`

**Step 1: Check which icons already exist**

Run: `ls web/app/themes/stridence/icons/`

For each missing icon, create a minimal 24x24 SVG from Feather Icons (the icon set already used by the theme).

**Step 2: Commit**

```bash
git add web/app/themes/stridence/icons/
git commit -m "feat: add missing feather icons for dashboard home screen"
```

---

### Task 14: Restyle Existing Tabs with Warm Cards

**Files:**
- Modify: `web/app/themes/stridence/templates/dashboard/tab-inschrijvingen.php`
- Modify: `web/app/themes/stridence/templates/dashboard/tab-certificaten.php`
- Modify: `web/app/themes/stridence/templates/dashboard/tab-trajecten.php`
- Modify: `web/app/themes/stridence/templates/dashboard/tab-offertes.php`
- Modify: `web/app/themes/stridence/templates/dashboard/tab-profiel.php`

**Step 1: Replace `card` class with `dash-card` across all tab templates**

This is a search-and-replace across the 5 tab templates:
- `class="card ` → `class="dash-card `
- `card-interactive` → `dash-card-interactive`
- `card-bordered` → `dash-card` (remove bordered variant — warm cards don't need borders)

Also update section headers to use `text-lg font-medium` instead of `font-heading font-bold` for consistency with the Home screen's calmer typography.

**Step 2: Build and verify visual appearance**

Run: `cd web/app/themes/stridence && npm run build`
Navigate to each tab and verify cards have warmer styling.

**Step 3: Commit**

```bash
git add web/app/themes/stridence/templates/dashboard/
git commit -m "style: apply warm dash-card styling to all dashboard tabs"
```

---

### Task 15: Update Slide Panel Styling for Warm Design

**Files:**
- Modify: `web/app/themes/stridence/src/css/components.css`
- Modify: `web/app/themes/stridence/templates/dashboard/tab-offertes.php`

**Step 1: Update existing slide-panel to use warm styling**

The existing `.slide-panel` in tab-offertes already works well. Add warm styling to make it consistent with the new design:

In `components.css`, update the existing `.slide-panel` class to add `rounded-l-2xl` (matching `.dash-panel`). Or, update the offertes template to use the new `.dash-panel` class.

The simpler approach: update the quote panel's HTML to use `.dash-panel` instead of `.slide-panel`.

**Step 2: Commit**

```bash
git add web/app/themes/stridence/src/css/components.css web/app/themes/stridence/templates/dashboard/tab-offertes.php
git commit -m "style: update quote sidepanel to use warm dash-panel styling"
```

---

### Task 16: Integration Test — Full Dashboard Flow

**Step 1: Start DDEV and verify**

```bash
ddev start
ddev launch /mijn-account/
```

**Step 2: Test with seed users**

Log in as each user type and verify:

1. **E-learning user** (`seed_student1@seed.test` / `seedpass123`):
   - Home shows greeting + course card + certificate (if completed)
   - Dock shows: Home, Opleidingen, Certificaten, Profiel
   - Card click opens sidepanel with course info

2. **In-person user** (student with edition registrations):
   - Home shows hero with upcoming session + action items
   - Dock includes Agenda item
   - Sessions show date/time/location

3. **Full user** (has everything):
   - All dock items visible
   - All home blocks rendered
   - Trajecten section shows trajectory cards

**Step 3: Test responsive behavior**

- Desktop (>1024px): Floating dock visible, content centered
- Tablet (768-1024px): No dock, bottom tab bar visible
- Mobile (<768px): Bottom tab bar, stacked cards, full-width

**Step 4: Take screenshots for review**

```bash
# Take screenshots at key breakpoints
```

**Step 5: Commit any fixes found during testing**

---

### Task 17: Final Build & Commit

**Step 1: Run full Vite build**

```bash
cd web/app/themes/stridence && npm run build
```

**Step 2: Run PHP tests**

```bash
ddev exec vendor/bin/phpunit --testsuite Unit
```

**Step 3: Commit built assets**

```bash
git add web/app/themes/stridence/dist/
git commit -m "build: production assets for dashboard redesign"
```

---

## Summary

| Phase | Tasks | What it delivers |
|-------|-------|-----------------|
| **1. Foundation** | 1-2 | Warm design tokens + dashboard CSS components |
| **2. Backend** | 3 | `getHomeData()` service method with hero logic |
| **3. Navigation** | 4-6 | Floating dock + adaptive mobile nav + new page shell |
| **4. Home Screen** | 7-10 | Adaptive home template with greeting, hero, actions, cards |
| **5. Sidepanels** | 11-12 | Enrollment sidepanel + Alpine component |
| **6. Polish** | 13-17 | Icons, warm restyling of existing tabs, integration testing |

**Total: 17 tasks across 6 phases.**
