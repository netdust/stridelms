# Frontend Headspace Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Build a mobile-first, PWA-ready frontend shell with Headspace-inspired calm aesthetic.

**Architecture:** Custom shell (header, bottom nav, page layouts) using UIkit 3 + new design system CSS. LearnDash Focus Mode styled via CSS overrides. Service worker for PWA capabilities.

**Tech Stack:** UIkit 3, Inter font, CSS custom properties, vanilla JS for PWA, LearnDash Focus Mode

---

## Phase 1: Design System Foundation

### Task 1: Create New Design System CSS

**Files:**
- Create: `web/app/themes/stride/assets/css/design-system.css`

**Step 1: Create the design system CSS file**

```css
/**
 * Stride Design System - Headspace-Inspired
 *
 * Mobile-first, calm + motivating aesthetic
 */

/* ========================================
   CSS CUSTOM PROPERTIES
   ======================================== */
:root {
    /* Primary - Warm Orange */
    --stride-primary: #FF8C42;
    --stride-primary-hover: #E67A35;
    --stride-primary-light: #FFF5EB;
    --stride-primary-rgb: 255, 140, 66;

    /* Secondary - Calm Navy */
    --stride-secondary: #2D3E50;
    --stride-secondary-hover: #1E2D3D;
    --stride-secondary-light: #E8ECF1;

    /* Success - Teal */
    --stride-success: #4ECDC4;
    --stride-success-hover: #3DBDB4;
    --stride-success-light: #E0F7F5;

    /* Warning */
    --stride-warning: #F4A261;
    --stride-warning-light: #FEF3E8;

    /* Danger */
    --stride-danger: #E76F51;
    --stride-danger-light: #FDEEEA;

    /* Accent - Mint */
    --stride-accent: #A8E6CF;

    /* Backgrounds */
    --stride-bg: #FAFBFC;
    --stride-surface: #FFFFFF;

    /* Text */
    --stride-text: #2D3E50;
    --stride-text-muted: #6B7C8F;
    --stride-text-light: #9BA8B5;

    /* Borders */
    --stride-border: #E5E9ED;
    --stride-border-light: #F0F2F5;

    /* Spacing Scale */
    --space-xs: 4px;
    --space-sm: 8px;
    --space-md: 16px;
    --space-lg: 24px;
    --space-xl: 32px;
    --space-2xl: 48px;
    --space-3xl: 64px;

    /* Border Radius */
    --radius-sm: 6px;
    --radius-md: 8px;
    --radius-lg: 12px;
    --radius-xl: 16px;
    --radius-full: 9999px;

    /* Shadows */
    --shadow-sm: 0 1px 3px rgba(0, 0, 0, 0.06);
    --shadow-md: 0 4px 12px rgba(0, 0, 0, 0.08);
    --shadow-lg: 0 8px 24px rgba(0, 0, 0, 0.10);

    /* Transitions */
    --transition-fast: 150ms ease-out;
    --transition-normal: 250ms ease-out;
    --transition-slow: 350ms ease-out;

    /* Shell heights */
    --header-height: 56px;
    --bottom-nav-height: 64px;
    --safe-area-bottom: env(safe-area-inset-bottom, 0px);
}

/* ========================================
   TYPOGRAPHY - Inter Font
   ======================================== */
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');

body {
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
    font-size: 16px;
    line-height: 1.5;
    color: var(--stride-text);
    background-color: var(--stride-bg);
    -webkit-font-smoothing: antialiased;
    -moz-osx-font-smoothing: grayscale;
}

h1, h2, h3, h4, h5, h6 {
    font-weight: 600;
    line-height: 1.3;
    color: var(--stride-secondary);
    margin-top: 0;
}

h1 { font-size: 1.75rem; }
h2 { font-size: 1.375rem; }
h3 { font-size: 1.125rem; }
h4 { font-size: 1rem; }

@media (min-width: 768px) {
    h1 { font-size: 2.25rem; }
    h2 { font-size: 1.75rem; }
    h3 { font-size: 1.375rem; }
}

.text-muted { color: var(--stride-text-muted); }
.text-light { color: var(--stride-text-light); }
.text-primary { color: var(--stride-primary); }
.text-success { color: var(--stride-success); }
.text-small { font-size: 0.875rem; }
.text-xs { font-size: 0.75rem; }
.font-medium { font-weight: 500; }
.font-semibold { font-weight: 600; }
.font-bold { font-weight: 700; }
```

**Step 2: Verify file created**

Run: `ls -la web/app/themes/stride/assets/css/design-system.css`
Expected: File exists

**Step 3: Commit**

```bash
git add web/app/themes/stride/assets/css/design-system.css
git commit -m "feat: add design system CSS foundation"
```

---

### Task 2: Add Component Styles to Design System

**Files:**
- Modify: `web/app/themes/stride/assets/css/design-system.css`

**Step 1: Append component styles**

Add to `design-system.css`:

```css
/* ========================================
   BUTTONS
   ======================================== */
.btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: var(--space-sm);
    padding: 12px 20px;
    font-size: 1rem;
    font-weight: 500;
    line-height: 1;
    border-radius: var(--radius-md);
    border: none;
    cursor: pointer;
    transition: all var(--transition-fast);
    text-decoration: none;
}

.btn-primary {
    background-color: var(--stride-primary);
    color: white;
}

.btn-primary:hover {
    background-color: var(--stride-primary-hover);
    color: white;
    text-decoration: none;
}

.btn-secondary {
    background-color: var(--stride-secondary);
    color: white;
}

.btn-secondary:hover {
    background-color: var(--stride-secondary-hover);
    color: white;
}

.btn-outline {
    background-color: transparent;
    border: 1px solid var(--stride-border);
    color: var(--stride-text);
}

.btn-outline:hover {
    border-color: var(--stride-primary);
    color: var(--stride-primary);
    background-color: var(--stride-primary-light);
}

.btn-ghost {
    background-color: transparent;
    color: var(--stride-text-muted);
}

.btn-ghost:hover {
    background-color: var(--stride-bg);
    color: var(--stride-text);
}

.btn-sm {
    padding: 8px 14px;
    font-size: 0.875rem;
}

.btn-lg {
    padding: 16px 28px;
    font-size: 1.125rem;
}

.btn-block {
    width: 100%;
}

/* ========================================
   CARDS
   ======================================== */
.card {
    background: var(--stride-surface);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-sm);
    overflow: hidden;
}

.card-body {
    padding: var(--space-lg);
}

.card-header {
    padding: var(--space-md) var(--space-lg);
    border-bottom: 1px solid var(--stride-border-light);
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.card-title {
    margin: 0;
    font-size: 1rem;
    font-weight: 600;
}

.card-hover {
    transition: transform var(--transition-fast), box-shadow var(--transition-fast);
}

.card-hover:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-md);
}

/* ========================================
   BADGES
   ======================================== */
.badge {
    display: inline-flex;
    align-items: center;
    padding: 4px 10px;
    font-size: 0.75rem;
    font-weight: 500;
    border-radius: var(--radius-full);
    text-transform: uppercase;
    letter-spacing: 0.02em;
}

.badge-primary {
    background-color: var(--stride-primary-light);
    color: var(--stride-primary);
}

.badge-success {
    background-color: var(--stride-success-light);
    color: var(--stride-success);
}

.badge-warning {
    background-color: var(--stride-warning-light);
    color: var(--stride-warning);
}

.badge-muted {
    background-color: var(--stride-bg);
    color: var(--stride-text-muted);
}

.badge-online {
    background-color: #E8F4FD;
    color: #3B82F6;
}

.badge-klassikaal {
    background-color: var(--stride-primary-light);
    color: var(--stride-primary);
}

/* ========================================
   PROGRESS BAR
   ======================================== */
.progress {
    height: 6px;
    background-color: var(--stride-border);
    border-radius: var(--radius-full);
    overflow: hidden;
}

.progress-bar {
    height: 100%;
    background-color: var(--stride-primary);
    border-radius: var(--radius-full);
    transition: width var(--transition-slow);
}

.progress-bar.success {
    background-color: var(--stride-success);
}

.progress-lg {
    height: 8px;
}

/* ========================================
   FORM ELEMENTS
   ======================================== */
.form-group {
    margin-bottom: var(--space-lg);
}

.form-label {
    display: block;
    margin-bottom: var(--space-sm);
    font-weight: 500;
    font-size: 0.875rem;
    color: var(--stride-text);
}

.form-input {
    width: 100%;
    padding: 12px 14px;
    font-size: 1rem;
    border: 1px solid var(--stride-border);
    border-radius: var(--radius-md);
    background-color: var(--stride-surface);
    color: var(--stride-text);
    transition: border-color var(--transition-fast), box-shadow var(--transition-fast);
}

.form-input:focus {
    outline: none;
    border-color: var(--stride-primary);
    box-shadow: 0 0 0 3px rgba(var(--stride-primary-rgb), 0.15);
}

.form-input::placeholder {
    color: var(--stride-text-light);
}

.form-hint {
    margin-top: var(--space-xs);
    font-size: 0.75rem;
    color: var(--stride-text-muted);
}

/* ========================================
   EMPTY STATE
   ======================================== */
.empty-state {
    text-align: center;
    padding: var(--space-2xl);
}

.empty-state-icon {
    font-size: 3rem;
    color: var(--stride-text-light);
    margin-bottom: var(--space-md);
}

.empty-state-title {
    font-size: 1.125rem;
    font-weight: 600;
    color: var(--stride-text);
    margin-bottom: var(--space-sm);
}

.empty-state-text {
    color: var(--stride-text-muted);
    margin-bottom: var(--space-lg);
}
```

**Step 2: Verify styles appended**

Run: `tail -20 web/app/themes/stride/assets/css/design-system.css`
Expected: See empty-state styles

**Step 3: Commit**

```bash
git add web/app/themes/stride/assets/css/design-system.css
git commit -m "feat: add button, card, badge, progress, form component styles"
```

---

## Phase 2: Mobile Shell

### Task 3: Create Bottom Navigation Component

**Files:**
- Create: `web/app/themes/stride/templates/shell/bottom-nav.php`
- Modify: `web/app/themes/stride/assets/css/design-system.css`

**Step 1: Create bottom navigation template**

```php
<?php
/**
 * Bottom Navigation Component
 *
 * Mobile-first bottom navigation bar with 5 items.
 * Only shown on mobile (< 768px).
 *
 * @package stride
 */

defined('ABSPATH') || exit;

$current_path = $_SERVER['REQUEST_URI'] ?? '';
$nav_items = [
    [
        'icon' => 'home',
        'label' => __('Home', 'stride'),
        'url' => home_url('/mijn-account/'),
        'match' => ['/mijn-account/$', '/mijn-account$'],
    ],
    [
        'icon' => 'album',
        'label' => __('Cursussen', 'stride'),
        'url' => home_url('/cursussen/'),
        'match' => ['/cursussen'],
    ],
    [
        'icon' => 'git-branch',
        'label' => __('Traject', 'stride'),
        'url' => home_url('/mijn-account/trajecten/'),
        'match' => ['/trajecten'],
    ],
    [
        'icon' => 'calendar',
        'label' => __('Agenda', 'stride'),
        'url' => home_url('/mijn-account/agenda/'),
        'match' => ['/agenda'],
    ],
    [
        'icon' => 'user',
        'label' => __('Profiel', 'stride'),
        'url' => home_url('/mijn-account/profiel/'),
        'match' => ['/profiel'],
    ],
];

/**
 * Check if current path matches nav item
 */
function stride_nav_is_active(string $current_path, array $patterns): bool {
    foreach ($patterns as $pattern) {
        if (preg_match('#' . $pattern . '#', $current_path)) {
            return true;
        }
    }
    return false;
}
?>

<nav class="bottom-nav" id="bottom-nav">
    <?php foreach ($nav_items as $item):
        $is_active = stride_nav_is_active($current_path, $item['match']);
    ?>
        <a href="<?php echo esc_url($item['url']); ?>"
           class="bottom-nav-item <?php echo $is_active ? 'is-active' : ''; ?>">
            <span class="bottom-nav-icon" uk-icon="icon: <?php echo esc_attr($item['icon']); ?>; ratio: 1.2"></span>
            <span class="bottom-nav-label"><?php echo esc_html($item['label']); ?></span>
        </a>
    <?php endforeach; ?>
</nav>
```

**Step 2: Add bottom nav CSS to design-system.css**

Append to file:

```css
/* ========================================
   BOTTOM NAVIGATION (Mobile)
   ======================================== */
.bottom-nav {
    display: none;
    position: fixed;
    bottom: 0;
    left: 0;
    right: 0;
    height: var(--bottom-nav-height);
    padding-bottom: var(--safe-area-bottom);
    background: var(--stride-surface);
    border-top: 1px solid var(--stride-border-light);
    box-shadow: 0 -2px 10px rgba(0, 0, 0, 0.05);
    z-index: 1000;
}

@media (max-width: 767px) {
    .bottom-nav {
        display: flex;
        align-items: center;
        justify-content: space-around;
    }

    /* Add padding to body so content doesn't hide behind nav */
    body.has-bottom-nav {
        padding-bottom: calc(var(--bottom-nav-height) + var(--safe-area-bottom));
    }
}

.bottom-nav-item {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    flex: 1;
    padding: var(--space-sm) var(--space-xs);
    color: var(--stride-text-muted);
    text-decoration: none;
    transition: color var(--transition-fast);
    -webkit-tap-highlight-color: transparent;
}

.bottom-nav-item:hover,
.bottom-nav-item:focus {
    color: var(--stride-primary);
    text-decoration: none;
}

.bottom-nav-item.is-active {
    color: var(--stride-primary);
}

.bottom-nav-icon {
    margin-bottom: 2px;
}

.bottom-nav-label {
    font-size: 0.625rem;
    font-weight: 500;
    letter-spacing: 0.01em;
}

/* Active indicator pill */
.bottom-nav-item.is-active::before {
    content: '';
    position: absolute;
    top: 0;
    left: 50%;
    transform: translateX(-50%);
    width: 32px;
    height: 3px;
    background-color: var(--stride-primary);
    border-radius: 0 0 3px 3px;
}

.bottom-nav-item {
    position: relative;
}
```

**Step 3: Verify files**

Run: `ls -la web/app/themes/stride/templates/shell/`
Expected: See bottom-nav.php

**Step 4: Commit**

```bash
git add web/app/themes/stride/templates/shell/bottom-nav.php
git add web/app/themes/stride/assets/css/design-system.css
git commit -m "feat: add mobile bottom navigation component"
```

---

### Task 4: Create Mobile Header Component

**Files:**
- Create: `web/app/themes/stride/templates/shell/header-mobile.php`
- Modify: `web/app/themes/stride/assets/css/design-system.css`

**Step 1: Create mobile header template**

```php
<?php
/**
 * Mobile Header Component
 *
 * Minimal header with logo, notifications, and avatar.
 * Hides on scroll down, shows on scroll up.
 *
 * @package stride
 */

defined('ABSPATH') || exit;

$user = wp_get_current_user();
$avatar_url = get_avatar_url($user->ID, ['size' => 32]);
$notification_count = 0; // TODO: Implement notification count
?>

<header class="mobile-header" id="mobile-header">
    <div class="mobile-header-inner">
        <!-- Menu toggle (for secondary nav) -->
        <button class="mobile-header-menu" uk-toggle="target: #mobile-menu" aria-label="<?php esc_attr_e('Menu', 'stride'); ?>">
            <span uk-icon="icon: menu; ratio: 1.2"></span>
        </button>

        <!-- Logo -->
        <a href="<?php echo esc_url(home_url('/mijn-account/')); ?>" class="mobile-header-logo">
            Stride
        </a>

        <!-- Right actions -->
        <div class="mobile-header-actions">
            <!-- Notifications -->
            <button class="mobile-header-btn" aria-label="<?php esc_attr_e('Notificaties', 'stride'); ?>">
                <span uk-icon="icon: bell; ratio: 1.1"></span>
                <?php if ($notification_count > 0): ?>
                    <span class="notification-dot"></span>
                <?php endif; ?>
            </button>

            <!-- Avatar -->
            <a href="<?php echo esc_url(home_url('/mijn-account/profiel/')); ?>" class="mobile-header-avatar">
                <img src="<?php echo esc_url($avatar_url); ?>" alt="" width="32" height="32">
            </a>
        </div>
    </div>
</header>

<!-- Mobile slide-out menu for secondary items -->
<div id="mobile-menu" uk-offcanvas="overlay: true; flip: true">
    <div class="uk-offcanvas-bar uk-padding">
        <button class="uk-offcanvas-close" type="button" uk-close></button>

        <nav class="mobile-menu-nav">
            <ul class="uk-nav uk-nav-default">
                <li class="uk-nav-header"><?php esc_html_e('Account', 'stride'); ?></li>
                <li><a href="<?php echo esc_url(home_url('/mijn-account/offertes/')); ?>"><?php esc_html_e('Mijn offertes', 'stride'); ?></a></li>
                <li><a href="<?php echo esc_url(home_url('/mijn-account/certificaten/')); ?>"><?php esc_html_e('Certificaten', 'stride'); ?></a></li>
                <li><a href="<?php echo esc_url(home_url('/mijn-account/instellingen/')); ?>"><?php esc_html_e('Instellingen', 'stride'); ?></a></li>
                <li class="uk-nav-divider"></li>
                <li><a href="<?php echo esc_url(wp_logout_url(home_url())); ?>"><?php esc_html_e('Uitloggen', 'stride'); ?></a></li>
            </ul>
        </nav>
    </div>
</div>
```

**Step 2: Add mobile header CSS**

Append to `design-system.css`:

```css
/* ========================================
   MOBILE HEADER
   ======================================== */
.mobile-header {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    height: var(--header-height);
    background: var(--stride-surface);
    border-bottom: 1px solid var(--stride-border-light);
    z-index: 1000;
    transition: transform var(--transition-normal);
}

@media (max-width: 767px) {
    .mobile-header {
        display: block;
    }

    /* Add padding to body so content doesn't hide behind header */
    body.has-mobile-header {
        padding-top: var(--header-height);
    }
}

.mobile-header.is-hidden {
    transform: translateY(-100%);
}

.mobile-header-inner {
    display: flex;
    align-items: center;
    justify-content: space-between;
    height: 100%;
    padding: 0 var(--space-md);
}

.mobile-header-menu {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 40px;
    height: 40px;
    background: none;
    border: none;
    color: var(--stride-text);
    cursor: pointer;
    -webkit-tap-highlight-color: transparent;
}

.mobile-header-logo {
    font-size: 1.25rem;
    font-weight: 700;
    color: var(--stride-secondary);
    text-decoration: none;
    letter-spacing: -0.02em;
}

.mobile-header-logo:hover {
    color: var(--stride-primary);
    text-decoration: none;
}

.mobile-header-actions {
    display: flex;
    align-items: center;
    gap: var(--space-xs);
}

.mobile-header-btn {
    position: relative;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 40px;
    height: 40px;
    background: none;
    border: none;
    color: var(--stride-text-muted);
    cursor: pointer;
    -webkit-tap-highlight-color: transparent;
}

.mobile-header-btn:hover {
    color: var(--stride-text);
}

.notification-dot {
    position: absolute;
    top: 8px;
    right: 8px;
    width: 8px;
    height: 8px;
    background-color: var(--stride-danger);
    border-radius: 50%;
    border: 2px solid var(--stride-surface);
}

.mobile-header-avatar {
    display: flex;
    align-items: center;
    justify-content: center;
}

.mobile-header-avatar img {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    object-fit: cover;
}

/* Mobile menu offcanvas override */
#mobile-menu .uk-offcanvas-bar {
    background: var(--stride-surface);
    color: var(--stride-text);
}

.mobile-menu-nav .uk-nav-default > li > a {
    color: var(--stride-text);
    padding: var(--space-sm) 0;
}

.mobile-menu-nav .uk-nav-default > li > a:hover {
    color: var(--stride-primary);
}

.mobile-menu-nav .uk-nav-header {
    color: var(--stride-text-muted);
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
}
```

**Step 3: Commit**

```bash
git add web/app/themes/stride/templates/shell/header-mobile.php
git add web/app/themes/stride/assets/css/design-system.css
git commit -m "feat: add mobile header with hide-on-scroll support"
```

---

### Task 5: Create Header Scroll Behavior JS

**Files:**
- Create: `web/app/themes/stride/assets/js/shell.js`

**Step 1: Create shell JavaScript**

```javascript
/**
 * Stride Shell JavaScript
 *
 * Handles mobile header scroll behavior and other shell interactions.
 */

(function() {
    'use strict';

    /**
     * Mobile Header Hide on Scroll
     *
     * Hides header on scroll down, shows on scroll up.
     */
    function initHeaderScroll() {
        const header = document.getElementById('mobile-header');
        if (!header) return;

        let lastScrollY = window.scrollY;
        let ticking = false;
        const threshold = 10; // Minimum scroll distance to trigger

        function updateHeader() {
            const currentScrollY = window.scrollY;
            const diff = currentScrollY - lastScrollY;

            // At top of page - always show
            if (currentScrollY < 50) {
                header.classList.remove('is-hidden');
            }
            // Scrolling down - hide
            else if (diff > threshold) {
                header.classList.add('is-hidden');
            }
            // Scrolling up - show
            else if (diff < -threshold) {
                header.classList.remove('is-hidden');
            }

            lastScrollY = currentScrollY;
            ticking = false;
        }

        function onScroll() {
            if (!ticking) {
                requestAnimationFrame(updateHeader);
                ticking = true;
            }
        }

        window.addEventListener('scroll', onScroll, { passive: true });
    }

    /**
     * Add body classes for shell padding
     */
    function initBodyClasses() {
        const body = document.body;
        const header = document.getElementById('mobile-header');
        const bottomNav = document.getElementById('bottom-nav');

        if (header && window.innerWidth < 768) {
            body.classList.add('has-mobile-header');
        }

        if (bottomNav && window.innerWidth < 768) {
            body.classList.add('has-bottom-nav');
        }

        // Update on resize
        window.addEventListener('resize', function() {
            if (window.innerWidth < 768) {
                if (header) body.classList.add('has-mobile-header');
                if (bottomNav) body.classList.add('has-bottom-nav');
            } else {
                body.classList.remove('has-mobile-header', 'has-bottom-nav');
            }
        });
    }

    /**
     * Initialize on DOM ready
     */
    function init() {
        initHeaderScroll();
        initBodyClasses();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
```

**Step 2: Verify file created**

Run: `ls -la web/app/themes/stride/assets/js/shell.js`
Expected: File exists

**Step 3: Commit**

```bash
git add web/app/themes/stride/assets/js/shell.js
git commit -m "feat: add shell JS for header scroll behavior"
```

---

### Task 6: Create Desktop Header Component

**Files:**
- Create: `web/app/themes/stride/templates/shell/header-desktop.php`
- Modify: `web/app/themes/stride/assets/css/design-system.css`

**Step 1: Create desktop header template**

```php
<?php
/**
 * Desktop Header Component
 *
 * Horizontal navigation for desktop (≥ 768px).
 *
 * @package stride
 */

defined('ABSPATH') || exit;

$user = wp_get_current_user();
$avatar_url = get_avatar_url($user->ID, ['size' => 40]);
$display_name = $user->first_name ?: $user->display_name;

$current_path = $_SERVER['REQUEST_URI'] ?? '';

$nav_items = [
    [
        'label' => __('Cursussen', 'stride'),
        'url' => home_url('/cursussen/'),
        'match' => '/cursussen',
    ],
    [
        'label' => __('Trajecten', 'stride'),
        'url' => home_url('/trajecten/'),
        'match' => '/trajecten',
    ],
    [
        'label' => __('Agenda', 'stride'),
        'url' => home_url('/mijn-account/agenda/'),
        'match' => '/agenda',
    ],
];
?>

<header class="desktop-header" id="desktop-header">
    <div class="desktop-header-inner uk-container">
        <!-- Logo -->
        <a href="<?php echo esc_url(home_url('/mijn-account/')); ?>" class="desktop-header-logo">
            Stride
        </a>

        <!-- Main navigation -->
        <nav class="desktop-nav">
            <?php foreach ($nav_items as $item):
                $is_active = strpos($current_path, $item['match']) !== false;
            ?>
                <a href="<?php echo esc_url($item['url']); ?>"
                   class="desktop-nav-item <?php echo $is_active ? 'is-active' : ''; ?>">
                    <?php echo esc_html($item['label']); ?>
                </a>
            <?php endforeach; ?>
        </nav>

        <!-- Right actions -->
        <div class="desktop-header-actions">
            <!-- Notifications -->
            <button class="desktop-header-btn" aria-label="<?php esc_attr_e('Notificaties', 'stride'); ?>">
                <span uk-icon="icon: bell"></span>
            </button>

            <!-- User dropdown -->
            <div class="desktop-user-dropdown">
                <button class="desktop-user-toggle" type="button">
                    <img src="<?php echo esc_url($avatar_url); ?>" alt="" class="desktop-user-avatar">
                    <span class="desktop-user-name"><?php echo esc_html($display_name); ?></span>
                    <span uk-icon="icon: chevron-down; ratio: 0.8"></span>
                </button>
                <div uk-dropdown="mode: click; pos: bottom-right; offset: 8">
                    <ul class="uk-nav uk-dropdown-nav">
                        <li><a href="<?php echo esc_url(home_url('/mijn-account/')); ?>"><?php esc_html_e('Dashboard', 'stride'); ?></a></li>
                        <li><a href="<?php echo esc_url(home_url('/mijn-account/cursussen/')); ?>"><?php esc_html_e('Mijn cursussen', 'stride'); ?></a></li>
                        <li><a href="<?php echo esc_url(home_url('/mijn-account/offertes/')); ?>"><?php esc_html_e('Mijn offertes', 'stride'); ?></a></li>
                        <li><a href="<?php echo esc_url(home_url('/mijn-account/profiel/')); ?>"><?php esc_html_e('Profiel', 'stride'); ?></a></li>
                        <li class="uk-nav-divider"></li>
                        <li><a href="<?php echo esc_url(wp_logout_url(home_url())); ?>"><?php esc_html_e('Uitloggen', 'stride'); ?></a></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</header>
```

**Step 2: Add desktop header CSS**

Append to `design-system.css`:

```css
/* ========================================
   DESKTOP HEADER
   ======================================== */
.desktop-header {
    display: none;
    position: sticky;
    top: 0;
    background: var(--stride-surface);
    border-bottom: 1px solid var(--stride-border-light);
    z-index: 1000;
}

@media (min-width: 768px) {
    .desktop-header {
        display: block;
    }
}

.desktop-header-inner {
    display: flex;
    align-items: center;
    justify-content: space-between;
    height: 64px;
}

.desktop-header-logo {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--stride-secondary);
    text-decoration: none;
    letter-spacing: -0.02em;
}

.desktop-header-logo:hover {
    color: var(--stride-primary);
    text-decoration: none;
}

/* Desktop navigation */
.desktop-nav {
    display: flex;
    align-items: center;
    gap: var(--space-xl);
}

.desktop-nav-item {
    font-size: 0.9375rem;
    font-weight: 500;
    color: var(--stride-text-muted);
    text-decoration: none;
    padding: var(--space-sm) 0;
    position: relative;
    transition: color var(--transition-fast);
}

.desktop-nav-item:hover,
.desktop-nav-item.is-active {
    color: var(--stride-text);
    text-decoration: none;
}

.desktop-nav-item.is-active::after {
    content: '';
    position: absolute;
    bottom: -1px;
    left: 0;
    right: 0;
    height: 2px;
    background-color: var(--stride-primary);
    border-radius: 2px 2px 0 0;
}

/* Desktop actions */
.desktop-header-actions {
    display: flex;
    align-items: center;
    gap: var(--space-md);
}

.desktop-header-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 40px;
    height: 40px;
    background: none;
    border: none;
    color: var(--stride-text-muted);
    cursor: pointer;
    border-radius: var(--radius-md);
    transition: background-color var(--transition-fast), color var(--transition-fast);
}

.desktop-header-btn:hover {
    background-color: var(--stride-bg);
    color: var(--stride-text);
}

/* User dropdown */
.desktop-user-toggle {
    display: flex;
    align-items: center;
    gap: var(--space-sm);
    padding: var(--space-xs) var(--space-sm);
    background: none;
    border: 1px solid var(--stride-border);
    border-radius: var(--radius-full);
    cursor: pointer;
    transition: border-color var(--transition-fast);
}

.desktop-user-toggle:hover {
    border-color: var(--stride-primary);
}

.desktop-user-avatar {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    object-fit: cover;
}

.desktop-user-name {
    font-size: 0.875rem;
    font-weight: 500;
    color: var(--stride-text);
}

/* Dropdown override */
.desktop-user-dropdown .uk-dropdown {
    min-width: 180px;
    background: var(--stride-surface);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-lg);
    padding: var(--space-sm);
}

.desktop-user-dropdown .uk-dropdown-nav > li > a {
    color: var(--stride-text);
    padding: var(--space-sm) var(--space-md);
    border-radius: var(--radius-md);
}

.desktop-user-dropdown .uk-dropdown-nav > li > a:hover {
    background-color: var(--stride-primary-light);
    color: var(--stride-primary);
}
```

**Step 3: Commit**

```bash
git add web/app/themes/stride/templates/shell/header-desktop.php
git add web/app/themes/stride/assets/css/design-system.css
git commit -m "feat: add desktop header with navigation and user dropdown"
```

---

### Task 7: Enqueue New Assets

**Files:**
- Modify: `web/app/themes/stride/functions.php`

**Step 1: Find current enqueue function**

Run: `grep -n "wp_enqueue" web/app/themes/stride/functions.php | head -20`
Expected: See existing enqueue setup

**Step 2: Add new assets to enqueue**

Find the existing `wp_enqueue_scripts` action and add:

```php
// In the existing enqueue function, add:
wp_enqueue_style(
    'stride-design-system',
    get_template_directory_uri() . '/assets/css/design-system.css',
    ['uikit'], // Load after UIkit
    filemtime(get_template_directory() . '/assets/css/design-system.css')
);

wp_enqueue_script(
    'stride-shell',
    get_template_directory_uri() . '/assets/js/shell.js',
    [], // No dependencies
    filemtime(get_template_directory() . '/assets/js/shell.js'),
    true // In footer
);
```

**Step 3: Commit**

```bash
git add web/app/themes/stride/functions.php
git commit -m "feat: enqueue design system CSS and shell JS"
```

---

## Phase 3: PWA Setup

### Task 8: Create Web App Manifest

**Files:**
- Create: `web/app/themes/stride/manifest.json`
- Modify: `web/app/themes/stride/functions.php` (add manifest link)

**Step 1: Create manifest file**

```json
{
    "name": "Stride LMS",
    "short_name": "Stride",
    "description": "Jouw leerplatform voor professionele ontwikkeling",
    "start_url": "/mijn-account/",
    "scope": "/",
    "display": "standalone",
    "orientation": "portrait-primary",
    "theme_color": "#2D3E50",
    "background_color": "#FAFBFC",
    "icons": [
        {
            "src": "/app/themes/stride/assets/images/icon-192.png",
            "sizes": "192x192",
            "type": "image/png",
            "purpose": "any"
        },
        {
            "src": "/app/themes/stride/assets/images/icon-512.png",
            "sizes": "512x512",
            "type": "image/png",
            "purpose": "any"
        },
        {
            "src": "/app/themes/stride/assets/images/icon-maskable.png",
            "sizes": "512x512",
            "type": "image/png",
            "purpose": "maskable"
        }
    ],
    "categories": ["education", "productivity"],
    "lang": "nl"
}
```

**Step 2: Add manifest link to head**

Add to functions.php:

```php
/**
 * Add PWA manifest and meta tags
 */
add_action('wp_head', function() {
    ?>
    <link rel="manifest" href="<?php echo get_template_directory_uri(); ?>/manifest.json">
    <meta name="theme-color" content="#2D3E50">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Stride">
    <link rel="apple-touch-icon" href="<?php echo get_template_directory_uri(); ?>/assets/images/icon-180.png">
    <?php
}, 5);
```

**Step 3: Commit**

```bash
git add web/app/themes/stride/manifest.json
git add web/app/themes/stride/functions.php
git commit -m "feat: add PWA manifest and meta tags"
```

---

### Task 9: Create Service Worker

**Files:**
- Create: `web/sw.js` (must be at root for scope)
- Modify: `web/app/themes/stride/assets/js/shell.js` (register SW)

**Step 1: Create service worker**

```javascript
/**
 * Stride Service Worker
 *
 * Caching strategy: Network-first with cache fallback.
 * Caches shell assets for fast loading.
 */

const CACHE_NAME = 'stride-v1';
const SHELL_ASSETS = [
    '/app/themes/stride/assets/css/design-system.css',
    '/app/themes/stride/assets/js/shell.js',
    '/app/themes/stride/manifest.json',
    'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap'
];

/**
 * Install - cache shell assets
 */
self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME)
            .then((cache) => cache.addAll(SHELL_ASSETS))
            .then(() => self.skipWaiting())
    );
});

/**
 * Activate - clean old caches
 */
self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((cacheNames) => {
                return Promise.all(
                    cacheNames
                        .filter((name) => name !== CACHE_NAME)
                        .map((name) => caches.delete(name))
                );
            })
            .then(() => self.clients.claim())
    );
});

/**
 * Fetch - network first, cache fallback
 */
self.addEventListener('fetch', (event) => {
    // Skip non-GET requests
    if (event.request.method !== 'GET') return;

    // Skip API requests and admin
    const url = new URL(event.request.url);
    if (url.pathname.startsWith('/wp-admin') ||
        url.pathname.startsWith('/wp-json') ||
        url.pathname.includes('ajax')) {
        return;
    }

    event.respondWith(
        fetch(event.request)
            .then((response) => {
                // Cache successful responses
                if (response.ok) {
                    const responseClone = response.clone();
                    caches.open(CACHE_NAME)
                        .then((cache) => cache.put(event.request, responseClone));
                }
                return response;
            })
            .catch(() => {
                // Fallback to cache
                return caches.match(event.request);
            })
    );
});
```

**Step 2: Add SW registration to shell.js**

Add to end of shell.js:

```javascript
/**
 * Register Service Worker
 */
function initServiceWorker() {
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', () => {
            navigator.serviceWorker.register('/sw.js')
                .then((registration) => {
                    console.log('SW registered:', registration.scope);
                })
                .catch((error) => {
                    console.log('SW registration failed:', error);
                });
        });
    }
}

// Add to init function
initServiceWorker();
```

**Step 3: Commit**

```bash
git add web/sw.js
git add web/app/themes/stride/assets/js/shell.js
git commit -m "feat: add service worker for PWA caching"
```

---

### Task 10: Create PWA Install Prompt

**Files:**
- Modify: `web/app/themes/stride/assets/js/shell.js`
- Modify: `web/app/themes/stride/assets/css/design-system.css`

**Step 1: Add install prompt JavaScript**

Add to shell.js:

```javascript
/**
 * PWA Install Prompt
 *
 * Custom install prompt shown after 2nd visit.
 */
function initInstallPrompt() {
    let deferredPrompt = null;
    const INSTALL_KEY = 'stride_install_dismissed';
    const VISIT_KEY = 'stride_visit_count';

    // Track visits
    const visits = parseInt(localStorage.getItem(VISIT_KEY) || '0', 10) + 1;
    localStorage.setItem(VISIT_KEY, visits.toString());

    // Check if dismissed
    const dismissed = localStorage.getItem(INSTALL_KEY);
    if (dismissed) {
        const dismissedDate = new Date(dismissed);
        const daysSince = (Date.now() - dismissedDate.getTime()) / (1000 * 60 * 60 * 24);
        if (daysSince < 30) return; // Don't show for 30 days
    }

    // Capture the install prompt
    window.addEventListener('beforeinstallprompt', (e) => {
        e.preventDefault();
        deferredPrompt = e;

        // Show after 2nd visit
        if (visits >= 2) {
            showInstallBanner();
        }
    });

    function showInstallBanner() {
        // Don't show if already installed
        if (window.matchMedia('(display-mode: standalone)').matches) return;

        const banner = document.createElement('div');
        banner.className = 'install-banner';
        banner.innerHTML = `
            <div class="install-banner-content">
                <div class="install-banner-icon">📱</div>
                <div class="install-banner-text">
                    <strong>Installeer Stride</strong>
                    <span>Voeg toe aan je startscherm voor snelle toegang.</span>
                </div>
            </div>
            <div class="install-banner-actions">
                <button class="btn btn-primary btn-sm" id="install-btn">Installeren</button>
                <button class="btn btn-ghost btn-sm" id="install-dismiss">Niet nu</button>
            </div>
        `;
        document.body.appendChild(banner);

        // Animate in
        requestAnimationFrame(() => {
            banner.classList.add('is-visible');
        });

        // Install button
        document.getElementById('install-btn').addEventListener('click', async () => {
            if (!deferredPrompt) return;
            deferredPrompt.prompt();
            const { outcome } = await deferredPrompt.userChoice;
            deferredPrompt = null;
            banner.remove();
        });

        // Dismiss button
        document.getElementById('install-dismiss').addEventListener('click', () => {
            localStorage.setItem(INSTALL_KEY, new Date().toISOString());
            banner.classList.remove('is-visible');
            setTimeout(() => banner.remove(), 300);
        });
    }
}

// Add to init
initInstallPrompt();
```

**Step 2: Add install banner CSS**

Append to design-system.css:

```css
/* ========================================
   PWA INSTALL BANNER
   ======================================== */
.install-banner {
    position: fixed;
    bottom: calc(var(--bottom-nav-height) + var(--safe-area-bottom) + var(--space-md));
    left: var(--space-md);
    right: var(--space-md);
    background: var(--stride-surface);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-lg);
    padding: var(--space-md);
    z-index: 1001;
    transform: translateY(calc(100% + var(--space-xl)));
    opacity: 0;
    transition: transform var(--transition-normal), opacity var(--transition-normal);
}

.install-banner.is-visible {
    transform: translateY(0);
    opacity: 1;
}

@media (min-width: 768px) {
    .install-banner {
        bottom: var(--space-lg);
        left: auto;
        right: var(--space-lg);
        max-width: 360px;
    }
}

.install-banner-content {
    display: flex;
    align-items: flex-start;
    gap: var(--space-md);
    margin-bottom: var(--space-md);
}

.install-banner-icon {
    font-size: 2rem;
    line-height: 1;
}

.install-banner-text {
    flex: 1;
}

.install-banner-text strong {
    display: block;
    font-weight: 600;
    color: var(--stride-text);
    margin-bottom: 2px;
}

.install-banner-text span {
    font-size: 0.875rem;
    color: var(--stride-text-muted);
}

.install-banner-actions {
    display: flex;
    gap: var(--space-sm);
    justify-content: flex-end;
}
```

**Step 3: Commit**

```bash
git add web/app/themes/stride/assets/js/shell.js
git add web/app/themes/stride/assets/css/design-system.css
git commit -m "feat: add PWA install prompt banner"
```

---

### Task 11: Create Offline Indicator

**Files:**
- Modify: `web/app/themes/stride/assets/js/shell.js`
- Modify: `web/app/themes/stride/assets/css/design-system.css`

**Step 1: Add offline indicator JavaScript**

Add to shell.js:

```javascript
/**
 * Offline Indicator
 *
 * Shows banner when connection is lost.
 */
function initOfflineIndicator() {
    let banner = null;

    function showOffline() {
        if (banner) return;

        banner = document.createElement('div');
        banner.className = 'offline-banner';
        banner.innerHTML = `
            <span uk-icon="icon: warning; ratio: 0.9"></span>
            <span>Je bent offline. Sommige functies zijn mogelijk niet beschikbaar.</span>
            <button class="offline-close" aria-label="Sluiten">
                <span uk-icon="icon: close; ratio: 0.8"></span>
            </button>
        `;
        document.body.appendChild(banner);

        requestAnimationFrame(() => {
            banner.classList.add('is-visible');
        });

        banner.querySelector('.offline-close').addEventListener('click', () => {
            banner.classList.remove('is-visible');
        });
    }

    function hideOffline() {
        if (!banner) return;
        banner.classList.remove('is-visible');
        setTimeout(() => {
            if (banner) {
                banner.remove();
                banner = null;
            }
        }, 300);
    }

    window.addEventListener('online', hideOffline);
    window.addEventListener('offline', showOffline);

    // Check initial state
    if (!navigator.onLine) {
        showOffline();
    }
}

// Add to init
initOfflineIndicator();
```

**Step 2: Add offline banner CSS**

Append to design-system.css:

```css
/* ========================================
   OFFLINE INDICATOR
   ======================================== */
.offline-banner {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: var(--space-sm);
    padding: var(--space-sm) var(--space-md);
    background: var(--stride-warning);
    color: white;
    font-size: 0.875rem;
    font-weight: 500;
    z-index: 1002;
    transform: translateY(-100%);
    transition: transform var(--transition-normal);
}

.offline-banner.is-visible {
    transform: translateY(0);
}

/* Push header down when offline banner visible */
.offline-banner.is-visible ~ .mobile-header,
.offline-banner.is-visible ~ .desktop-header {
    top: 40px;
}

.offline-close {
    position: absolute;
    right: var(--space-md);
    display: flex;
    align-items: center;
    justify-content: center;
    width: 24px;
    height: 24px;
    background: none;
    border: none;
    color: white;
    cursor: pointer;
    opacity: 0.8;
}

.offline-close:hover {
    opacity: 1;
}
```

**Step 3: Commit**

```bash
git add web/app/themes/stride/assets/js/shell.js
git add web/app/themes/stride/assets/css/design-system.css
git commit -m "feat: add offline indicator banner"
```

---

## Phase 4: LearnDash Focus Mode Overrides

### Task 12: Create Focus Mode CSS Overrides

**Files:**
- Create: `web/app/themes/stride/assets/css/focus-mode.css`
- Modify: `web/app/themes/stride/functions.php`

**Step 1: Create Focus Mode override CSS**

```css
/**
 * LearnDash Focus Mode Overrides
 *
 * Styles the course player to match Stride's calm aesthetic.
 */

/* ========================================
   SIDEBAR
   ======================================== */
.learndash-wrapper .ld-focus .ld-focus-sidebar {
    background-color: var(--stride-secondary) !important;
}

.learndash-wrapper .ld-focus .ld-focus-sidebar-wrapper {
    background-color: var(--stride-secondary) !important;
}

.learndash-wrapper .ld-focus .ld-focus-sidebar .ld-course-navigation-heading {
    color: white !important;
    font-family: 'Inter', sans-serif !important;
    font-weight: 600 !important;
}

.learndash-wrapper .ld-focus .ld-focus-sidebar .ld-lesson-list .ld-lesson-item {
    border-color: rgba(255, 255, 255, 0.1) !important;
}

.learndash-wrapper .ld-focus .ld-focus-sidebar .ld-lesson-list .ld-lesson-item a {
    color: rgba(255, 255, 255, 0.8) !important;
    font-family: 'Inter', sans-serif !important;
}

.learndash-wrapper .ld-focus .ld-focus-sidebar .ld-lesson-list .ld-lesson-item a:hover {
    color: white !important;
}

.learndash-wrapper .ld-focus .ld-focus-sidebar .ld-lesson-list .ld-lesson-item.ld-is-current-lesson a {
    color: var(--stride-primary) !important;
}

/* Progress bar in sidebar */
.learndash-wrapper .ld-focus .ld-focus-sidebar .ld-progress {
    background-color: rgba(255, 255, 255, 0.2) !important;
}

.learndash-wrapper .ld-focus .ld-focus-sidebar .ld-progress .ld-progress-bar {
    background-color: var(--stride-success) !important;
}

/* ========================================
   CONTENT AREA
   ======================================== */
.learndash-wrapper .ld-focus .ld-focus-content {
    background-color: var(--stride-bg) !important;
    font-family: 'Inter', sans-serif !important;
}

.learndash-wrapper .ld-focus .ld-focus-content .ld-focus-content-wrapper {
    max-width: 720px !important;
    margin: 0 auto !important;
    padding: var(--space-2xl) var(--space-lg) !important;
}

.learndash-wrapper .ld-focus .ld-focus-content h1,
.learndash-wrapper .ld-focus .ld-focus-content h2,
.learndash-wrapper .ld-focus .ld-focus-content h3 {
    color: var(--stride-secondary) !important;
    font-weight: 600 !important;
}

/* Video wrapper */
.learndash-wrapper .ld-focus .ld-focus-content .ld-video {
    border-radius: var(--radius-lg) !important;
    overflow: hidden !important;
    box-shadow: var(--shadow-md) !important;
}

/* ========================================
   NAVIGATION BUTTONS
   ======================================== */
.learndash-wrapper .ld-focus .ld-focus-content .ld-content-actions {
    margin-top: var(--space-2xl) !important;
    padding-top: var(--space-lg) !important;
    border-top: 1px solid var(--stride-border) !important;
}

.learndash-wrapper .ld-focus .ld-button {
    font-family: 'Inter', sans-serif !important;
    font-weight: 500 !important;
    border-radius: var(--radius-md) !important;
    padding: 12px 24px !important;
    transition: all var(--transition-fast) !important;
}

.learndash-wrapper .ld-focus .ld-button.ld-button-primary {
    background-color: var(--stride-primary) !important;
    border-color: var(--stride-primary) !important;
}

.learndash-wrapper .ld-focus .ld-button.ld-button-primary:hover {
    background-color: var(--stride-primary-hover) !important;
    border-color: var(--stride-primary-hover) !important;
}

/* ========================================
   QUIZ STYLES
   ======================================== */
.learndash-wrapper .ld-focus .wpProQuiz_content {
    font-family: 'Inter', sans-serif !important;
}

.learndash-wrapper .ld-focus .wpProQuiz_question {
    background: var(--stride-surface) !important;
    border-radius: var(--radius-lg) !important;
    padding: var(--space-lg) !important;
    margin-bottom: var(--space-lg) !important;
    box-shadow: var(--shadow-sm) !important;
}

.learndash-wrapper .ld-focus .wpProQuiz_questionList {
    padding: 0 !important;
}

.learndash-wrapper .ld-focus .wpProQuiz_questionListItem {
    background: var(--stride-bg) !important;
    border: 2px solid var(--stride-border) !important;
    border-radius: var(--radius-md) !important;
    padding: var(--space-md) !important;
    margin-bottom: var(--space-sm) !important;
    transition: all var(--transition-fast) !important;
}

.learndash-wrapper .ld-focus .wpProQuiz_questionListItem:hover {
    border-color: var(--stride-primary) !important;
}

.learndash-wrapper .ld-focus .wpProQuiz_questionListItem.wpProQuiz_questionInput:checked + label {
    background: var(--stride-primary-light) !important;
    border-color: var(--stride-primary) !important;
}

/* Correct/incorrect feedback */
.learndash-wrapper .ld-focus .wpProQuiz_correct {
    background: var(--stride-success-light) !important;
    border-color: var(--stride-success) !important;
    color: var(--stride-success) !important;
}

.learndash-wrapper .ld-focus .wpProQuiz_incorrect {
    background: var(--stride-danger-light) !important;
    border-color: var(--stride-danger) !important;
    color: var(--stride-danger) !important;
}

/* ========================================
   HEADER BAR
   ======================================== */
.learndash-wrapper .ld-focus .ld-focus-header {
    background: var(--stride-surface) !important;
    border-bottom: 1px solid var(--stride-border-light) !important;
}

.learndash-wrapper .ld-focus .ld-focus-header .ld-brand-logo {
    font-family: 'Inter', sans-serif !important;
    font-weight: 700 !important;
    color: var(--stride-secondary) !important;
}

/* Back to dashboard link */
.learndash-wrapper .ld-focus .ld-focus-header .ld-home-link {
    color: var(--stride-text-muted) !important;
    font-family: 'Inter', sans-serif !important;
}

.learndash-wrapper .ld-focus .ld-focus-header .ld-home-link:hover {
    color: var(--stride-primary) !important;
}

/* ========================================
   MOBILE ADJUSTMENTS
   ======================================== */
@media (max-width: 767px) {
    .learndash-wrapper .ld-focus .ld-focus-content .ld-focus-content-wrapper {
        padding: var(--space-lg) var(--space-md) !important;
    }

    .learndash-wrapper .ld-focus .wpProQuiz_questionListItem {
        padding: var(--space-sm) var(--space-md) !important;
    }
}
```

**Step 2: Enqueue Focus Mode CSS conditionally**

Add to functions.php:

```php
/**
 * Enqueue Focus Mode overrides on LearnDash pages
 */
add_action('wp_enqueue_scripts', function() {
    if (is_singular(['sfwd-courses', 'sfwd-lessons', 'sfwd-topic', 'sfwd-quiz'])) {
        wp_enqueue_style(
            'stride-focus-mode',
            get_template_directory_uri() . '/assets/css/focus-mode.css',
            ['learndash-front-css'], // Load after LearnDash
            filemtime(get_template_directory() . '/assets/css/focus-mode.css')
        );
    }
}, 20); // Priority 20 to load after LD
```

**Step 3: Commit**

```bash
git add web/app/themes/stride/assets/css/focus-mode.css
git add web/app/themes/stride/functions.php
git commit -m "feat: add LearnDash Focus Mode style overrides"
```

---

## Phase 5: Create Placeholder Icons

### Task 13: Create PWA Icon Placeholders

**Files:**
- Create: `web/app/themes/stride/assets/images/icon-192.png`
- Create: `web/app/themes/stride/assets/images/icon-512.png`
- Create: `web/app/themes/stride/assets/images/icon-180.png`
- Create: `web/app/themes/stride/assets/images/icon-maskable.png`

**Step 1: Create placeholder SVG icons (can be replaced later)**

For now, we'll use a simple generated placeholder. Create these manually or use an icon generator.

Note: This task requires design assets. For MVP, use solid color squares:
- Navy background (#2D3E50)
- White "S" letter centered
- Sizes: 192x192, 512x512, 180x180

**Step 2: Commit placeholder or skip**

```bash
# If icons created:
git add web/app/themes/stride/assets/images/icon-*.png
git commit -m "feat: add PWA icon placeholders"
```

---

## Summary

**Total Tasks:** 13

**Files Created:**
- `assets/css/design-system.css` - Design system with all components
- `assets/css/focus-mode.css` - LearnDash Focus Mode overrides
- `assets/js/shell.js` - Header scroll, PWA registration, install prompt, offline indicator
- `templates/shell/bottom-nav.php` - Mobile bottom navigation
- `templates/shell/header-mobile.php` - Mobile header
- `templates/shell/header-desktop.php` - Desktop header
- `manifest.json` - PWA manifest
- `sw.js` - Service worker

**Files Modified:**
- `functions.php` - Asset enqueuing, PWA meta tags

**Next Steps After Plan:**
1. Create PWA icons (design task)
2. Integrate shell components into theme templates
3. Build individual page templates using new design system
