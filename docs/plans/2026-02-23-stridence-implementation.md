# Stridence Theme Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Build complete Stridence theme with all pages from the UX/UI design document.

**Architecture:** Kadence child theme with minimal custom CSS, PHP templates using existing stride-core services, mobile-first responsive design with bottom navigation for dashboard.

**Tech Stack:** PHP 8.3, WordPress, Kadence theme, stride-core services (EditionService, SessionService, EnrollmentService, etc.), FluentForms

---

## Phase 1: Theme Foundation

### Task 1.1: Base CSS Variables and Reset

**Files:**
- Modify: `web/app/themes/stridence/assets/css/stridence.css` (create)
- Modify: `web/app/themes/stridence/functions.php`

**Step 1: Create the base CSS file**

```css
/* web/app/themes/stridence/assets/css/stridence.css */

/**
 * Stridence Base Styles
 * Mobile-first CSS for Stride LMS on Kadence
 */

:root {
    /* Colors */
    --str-primary: #6366f1;
    --str-primary-hover: #4f46e5;
    --str-primary-light: #eef2ff;
    --str-success: #22c55e;
    --str-success-light: #dcfce7;
    --str-warning: #f59e0b;
    --str-danger: #ef4444;
    --str-text: #1e293b;
    --str-text-muted: #64748b;
    --str-text-light: #94a3b8;
    --str-border: #e2e8f0;
    --str-background: #f8fafc;
    --str-card: #ffffff;

    /* Spacing */
    --str-space-xs: 0.25rem;
    --str-space-sm: 0.5rem;
    --str-space-md: 1rem;
    --str-space-lg: 1.5rem;
    --str-space-xl: 2rem;
    --str-space-2xl: 3rem;

    /* Border radius */
    --str-radius: 12px;
    --str-radius-sm: 8px;
    --str-radius-lg: 16px;
    --str-radius-full: 9999px;

    /* Shadows */
    --str-shadow: 0 1px 3px rgba(0,0,0,0.1), 0 1px 2px rgba(0,0,0,0.06);
    --str-shadow-md: 0 4px 6px -1px rgba(0,0,0,0.1), 0 2px 4px -1px rgba(0,0,0,0.06);
    --str-shadow-lg: 0 10px 15px -3px rgba(0,0,0,0.1), 0 4px 6px -2px rgba(0,0,0,0.05);

    /* Transitions */
    --str-transition: 0.2s ease;
}

/* Reset */
*, *::before, *::after {
    box-sizing: border-box;
}

/* Base typography */
body {
    color: var(--str-text);
    line-height: 1.6;
}

/* Mobile-first tap targets */
button, .str-btn, a.str-btn {
    min-height: 44px;
    min-width: 44px;
}

/* Utility classes */
.str-text-muted { color: var(--str-text-muted); }
.str-text-success { color: var(--str-success); }
.str-text-warning { color: var(--str-warning); }
.str-text-danger { color: var(--str-danger); }

.str-bg-light { background: var(--str-background); }
.str-bg-card { background: var(--str-card); }

.str-sr-only {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    border: 0;
}
```

**Step 2: Update functions.php to enqueue CSS**

Add to `web/app/themes/stridence/functions.php` after the existing enqueue:

```php
// Stridence base styles (always load)
wp_enqueue_style(
    'stridence-base',
    get_stylesheet_directory_uri() . '/assets/css/stridence.css',
    ['kadence-parent-style'],
    filemtime(get_stylesheet_directory() . '/assets/css/stridence.css')
);
```

**Step 3: Commit**

```bash
git add web/app/themes/stridence/assets/css/stridence.css web/app/themes/stridence/functions.php
git commit -m "feat(stridence): add base CSS variables and utilities"
```

---

### Task 1.2: Button Component CSS

**Files:**
- Modify: `web/app/themes/stridence/assets/css/stridence.css`

**Step 1: Add button styles to stridence.css**

```css
/* ==========================================================================
   Buttons
   ========================================================================== */

.str-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: var(--str-space-sm);
    padding: 0.75rem 1.25rem;
    font-size: 0.9rem;
    font-weight: 500;
    text-decoration: none;
    border-radius: var(--str-radius-sm);
    border: none;
    cursor: pointer;
    transition: all var(--str-transition);
    white-space: nowrap;
    line-height: 1.4;
}

.str-btn--primary {
    background: var(--str-primary);
    color: #fff;
}

.str-btn--primary:hover {
    background: var(--str-primary-hover);
    color: #fff;
}

.str-btn--secondary {
    background: var(--str-background);
    color: var(--str-text);
    border: 1px solid var(--str-border);
}

.str-btn--secondary:hover {
    background: var(--str-border);
}

.str-btn--ghost {
    background: transparent;
    color: var(--str-primary);
}

.str-btn--ghost:hover {
    background: var(--str-primary-light);
}

.str-btn--sm {
    padding: 0.5rem 1rem;
    font-size: 0.85rem;
}

.str-btn--lg {
    padding: 1rem 1.75rem;
    font-size: 1rem;
}

.str-btn--block {
    width: 100%;
}

/* Icon in button */
.str-btn svg,
.str-btn .str-icon {
    width: 1em;
    height: 1em;
    flex-shrink: 0;
}
```

**Step 2: Commit**

```bash
git add web/app/themes/stridence/assets/css/stridence.css
git commit -m "feat(stridence): add button component styles"
```

---

### Task 1.3: Card Component CSS

**Files:**
- Modify: `web/app/themes/stridence/assets/css/stridence.css`

**Step 1: Add card styles**

```css
/* ==========================================================================
   Cards
   ========================================================================== */

.str-card {
    background: var(--str-card);
    border: 1px solid var(--str-border);
    border-radius: var(--str-radius);
    box-shadow: var(--str-shadow);
    overflow: hidden;
    transition: transform var(--str-transition), box-shadow var(--str-transition);
}

.str-card--hover:hover {
    transform: translateY(-4px);
    box-shadow: var(--str-shadow-md);
}

.str-card__image {
    width: 100%;
    aspect-ratio: 16 / 9;
    object-fit: cover;
}

.str-card__body {
    padding: var(--str-space-lg);
}

.str-card__title {
    font-size: 1.1rem;
    font-weight: 600;
    color: var(--str-text);
    margin: 0 0 var(--str-space-sm);
    line-height: 1.3;
}

.str-card__meta {
    display: flex;
    flex-wrap: wrap;
    gap: var(--str-space-md);
    font-size: 0.85rem;
    color: var(--str-text-muted);
    margin-bottom: var(--str-space-md);
}

.str-card__meta-item {
    display: flex;
    align-items: center;
    gap: var(--str-space-xs);
}

.str-card__footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding-top: var(--str-space-md);
    border-top: 1px solid var(--str-border);
    margin-top: var(--str-space-md);
}

.str-card__price {
    font-size: 1.1rem;
    font-weight: 600;
    color: var(--str-text);
}

/* Badge on card */
.str-card__badge {
    position: absolute;
    top: var(--str-space-md);
    left: var(--str-space-md);
    padding: var(--str-space-xs) var(--str-space-sm);
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    border-radius: var(--str-radius-sm);
    background: var(--str-primary);
    color: #fff;
}

.str-card__badge--secondary {
    background: var(--str-text-muted);
}

.str-card__badge--warning {
    background: var(--str-warning);
}
```

**Step 2: Commit**

```bash
git add web/app/themes/stridence/assets/css/stridence.css
git commit -m "feat(stridence): add card component styles"
```

---

### Task 1.4: Grid and Layout CSS

**Files:**
- Modify: `web/app/themes/stridence/assets/css/stridence.css`

**Step 1: Add grid and layout styles**

```css
/* ==========================================================================
   Layout & Grid
   ========================================================================== */

.str-container {
    width: 100%;
    max-width: 1200px;
    margin: 0 auto;
    padding: 0 var(--str-space-md);
}

@media (min-width: 768px) {
    .str-container {
        padding: 0 var(--str-space-lg);
    }
}

/* Course grid - mobile first */
.str-grid {
    display: grid;
    gap: var(--str-space-lg);
}

.str-grid--courses {
    grid-template-columns: 1fr;
}

@media (min-width: 640px) {
    .str-grid--courses {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (min-width: 1024px) {
    .str-grid--courses {
        grid-template-columns: repeat(3, 1fr);
    }
}

/* Section spacing */
.str-section {
    padding: var(--str-space-xl) 0;
}

@media (min-width: 768px) {
    .str-section {
        padding: var(--str-space-2xl) 0;
    }
}

.str-section__header {
    margin-bottom: var(--str-space-xl);
}

.str-section__title {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--str-text);
    margin: 0 0 var(--str-space-sm);
}

@media (min-width: 768px) {
    .str-section__title {
        font-size: 1.75rem;
    }
}

.str-section__subtitle {
    font-size: 1rem;
    color: var(--str-text-muted);
    margin: 0;
}

/* Flex utilities */
.str-flex { display: flex; }
.str-flex-col { flex-direction: column; }
.str-items-center { align-items: center; }
.str-justify-between { justify-content: space-between; }
.str-gap-sm { gap: var(--str-space-sm); }
.str-gap-md { gap: var(--str-space-md); }
.str-gap-lg { gap: var(--str-space-lg); }
```

**Step 2: Commit**

```bash
git add web/app/themes/stridence/assets/css/stridence.css
git commit -m "feat(stridence): add grid and layout styles"
```

---

### Task 1.5: Badge and Tag CSS

**Files:**
- Modify: `web/app/themes/stridence/assets/css/stridence.css`

**Step 1: Add badge/tag styles**

```css
/* ==========================================================================
   Badges & Tags
   ========================================================================== */

.str-badge {
    display: inline-flex;
    align-items: center;
    gap: var(--str-space-xs);
    padding: 0.25rem 0.625rem;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    border-radius: var(--str-radius-sm);
    white-space: nowrap;
}

.str-badge--primary {
    background: var(--str-primary-light);
    color: var(--str-primary);
}

.str-badge--success {
    background: var(--str-success-light);
    color: var(--str-success);
}

.str-badge--warning {
    background: #fef3c7;
    color: #b45309;
}

.str-badge--danger {
    background: #fee2e2;
    color: #b91c1c;
}

.str-badge--neutral {
    background: var(--str-background);
    color: var(--str-text-muted);
}

/* Type badges for courses */
.str-type-badge {
    padding: 0.375rem 0.75rem;
    font-size: 0.7rem;
    font-weight: 700;
    border-radius: var(--str-radius-sm);
}

.str-type-badge--elearning {
    background: #dbeafe;
    color: #1d4ed8;
}

.str-type-badge--classroom {
    background: #fce7f3;
    color: #be185d;
}

.str-type-badge--trajectory {
    background: #d1fae5;
    color: #047857;
}
```

**Step 2: Commit**

```bash
git add web/app/themes/stridence/assets/css/stridence.css
git commit -m "feat(stridence): add badge and tag styles"
```

---

### Task 1.6: Progress Bar CSS

**Files:**
- Modify: `web/app/themes/stridence/assets/css/stridence.css`

**Step 1: Add progress bar styles**

```css
/* ==========================================================================
   Progress Bar
   ========================================================================== */

.str-progress {
    width: 100%;
    height: 8px;
    background: var(--str-background);
    border-radius: var(--str-radius-full);
    overflow: hidden;
}

.str-progress__bar {
    height: 100%;
    background: linear-gradient(90deg, var(--str-primary), var(--str-primary-hover));
    border-radius: var(--str-radius-full);
    transition: width 0.3s ease;
}

.str-progress--sm {
    height: 4px;
}

.str-progress--lg {
    height: 12px;
}

/* Progress with label */
.str-progress-group {
    display: flex;
    flex-direction: column;
    gap: var(--str-space-sm);
}

.str-progress-label {
    display: flex;
    justify-content: space-between;
    font-size: 0.85rem;
    color: var(--str-text-muted);
}

.str-progress-label__value {
    font-weight: 600;
    color: var(--str-primary);
}
```

**Step 2: Commit**

```bash
git add web/app/themes/stridence/assets/css/stridence.css
git commit -m "feat(stridence): add progress bar styles"
```

---

### Task 1.7: Bottom Navigation CSS (Mobile Dashboard)

**Files:**
- Modify: `web/app/themes/stridence/assets/css/stridence.css`

**Step 1: Add bottom navigation styles**

```css
/* ==========================================================================
   Bottom Navigation (Mobile Dashboard)
   ========================================================================== */

.str-bottom-nav {
    position: fixed;
    bottom: 0;
    left: 0;
    right: 0;
    display: flex;
    justify-content: space-around;
    background: var(--str-card);
    border-top: 1px solid var(--str-border);
    padding: var(--str-space-sm) 0;
    padding-bottom: calc(var(--str-space-sm) + env(safe-area-inset-bottom, 0));
    z-index: 1000;
    box-shadow: 0 -2px 10px rgba(0,0,0,0.05);
}

/* Hide on desktop */
@media (min-width: 768px) {
    .str-bottom-nav {
        display: none;
    }
}

.str-bottom-nav__item {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 2px;
    padding: var(--str-space-xs) var(--str-space-sm);
    text-decoration: none;
    color: var(--str-text-muted);
    font-size: 0.65rem;
    font-weight: 500;
    transition: color var(--str-transition);
    min-width: 60px;
}

.str-bottom-nav__item:hover,
.str-bottom-nav__item--active {
    color: var(--str-primary);
}

.str-bottom-nav__icon {
    font-size: 1.25rem;
}

.str-bottom-nav__icon svg {
    width: 24px;
    height: 24px;
}

/* Add padding to body when bottom nav is present */
body.has-bottom-nav {
    padding-bottom: 70px;
}

@media (min-width: 768px) {
    body.has-bottom-nav {
        padding-bottom: 0;
    }
}
```

**Step 2: Commit**

```bash
git add web/app/themes/stridence/assets/css/stridence.css
git commit -m "feat(stridence): add bottom navigation styles for mobile"
```

---

### Task 1.8: Form Styles CSS

**Files:**
- Modify: `web/app/themes/stridence/assets/css/stridence.css`

**Step 1: Add form styles**

```css
/* ==========================================================================
   Forms
   ========================================================================== */

.str-form-group {
    margin-bottom: var(--str-space-lg);
}

.str-label {
    display: block;
    font-size: 0.875rem;
    font-weight: 500;
    color: var(--str-text);
    margin-bottom: var(--str-space-sm);
}

.str-label--required::after {
    content: ' *';
    color: var(--str-danger);
}

.str-input,
.str-select,
.str-textarea {
    width: 100%;
    padding: 0.75rem 1rem;
    font-size: 1rem;
    color: var(--str-text);
    background: var(--str-card);
    border: 1px solid var(--str-border);
    border-radius: var(--str-radius-sm);
    transition: border-color var(--str-transition), box-shadow var(--str-transition);
}

.str-input:focus,
.str-select:focus,
.str-textarea:focus {
    outline: none;
    border-color: var(--str-primary);
    box-shadow: 0 0 0 3px var(--str-primary-light);
}

.str-input::placeholder {
    color: var(--str-text-light);
}

.str-textarea {
    min-height: 120px;
    resize: vertical;
}

/* Checkbox & Radio */
.str-checkbox,
.str-radio {
    display: flex;
    align-items: flex-start;
    gap: var(--str-space-sm);
    cursor: pointer;
}

.str-checkbox input,
.str-radio input {
    width: 20px;
    height: 20px;
    margin-top: 2px;
    accent-color: var(--str-primary);
}

.str-checkbox__label,
.str-radio__label {
    font-size: 0.9rem;
    color: var(--str-text);
    line-height: 1.5;
}

/* Form sections */
.str-form-section {
    background: var(--str-card);
    border: 1px solid var(--str-border);
    border-radius: var(--str-radius);
    padding: var(--str-space-lg);
    margin-bottom: var(--str-space-lg);
}

.str-form-section__title {
    font-size: 1rem;
    font-weight: 600;
    color: var(--str-text);
    margin: 0 0 var(--str-space-lg);
    padding-bottom: var(--str-space-md);
    border-bottom: 1px solid var(--str-border);
}

/* Error state */
.str-input--error,
.str-select--error,
.str-textarea--error {
    border-color: var(--str-danger);
}

.str-error-message {
    font-size: 0.8rem;
    color: var(--str-danger);
    margin-top: var(--str-space-xs);
}
```

**Step 2: Commit**

```bash
git add web/app/themes/stridence/assets/css/stridence.css
git commit -m "feat(stridence): add form component styles"
```

---

### Task 1.9: Icon Helper (SVG Icons)

**Files:**
- Create: `web/app/themes/stridence/helpers/icons.php`
- Modify: `web/app/themes/stridence/functions.php`

**Step 1: Create icons helper**

```php
<?php
/**
 * Stridence Icon Helper
 *
 * Simple SVG icon system using Heroicons.
 * Usage: <?php stridence_icon('calendar'); ?>
 *
 * @package stridence
 */

defined('ABSPATH') || exit;

/**
 * Output an SVG icon.
 *
 * @param string $name Icon name
 * @param string $class Additional CSS classes
 * @param int $size Icon size in pixels
 */
function stridence_icon(string $name, string $class = '', int $size = 20): void
{
    echo stridence_get_icon($name, $class, $size);
}

/**
 * Get an SVG icon as string.
 *
 * @param string $name Icon name
 * @param string $class Additional CSS classes
 * @param int $size Icon size in pixels
 * @return string SVG markup
 */
function stridence_get_icon(string $name, string $class = '', int $size = 20): string
{
    $icons = stridence_get_icons();

    if (!isset($icons[$name])) {
        return '';
    }

    $classes = 'str-icon str-icon--' . esc_attr($name);
    if ($class) {
        $classes .= ' ' . esc_attr($class);
    }

    return sprintf(
        '<svg class="%s" width="%d" height="%d" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">%s</svg>',
        $classes,
        $size,
        $size,
        $icons[$name]
    );
}

/**
 * Get icon definitions.
 *
 * @return array<string, string> Icon name => SVG path content
 */
function stridence_get_icons(): array
{
    return [
        'home' => '<path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>',
        'book' => '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/>',
        'calendar' => '<rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>',
        'clock' => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
        'location' => '<path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/>',
        'user' => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
        'users' => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'check' => '<polyline points="20 6 9 17 4 12"/>',
        'check-circle' => '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>',
        'arrow-right' => '<line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/>',
        'arrow-left' => '<line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/>',
        'chevron-right' => '<polyline points="9 18 15 12 9 6"/>',
        'chevron-down' => '<polyline points="6 9 12 15 18 9"/>',
        'play' => '<polygon points="5 3 19 12 5 21 5 3"/>',
        'download' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>',
        'file-text' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/>',
        'warning' => '<path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
        'laptop' => '<rect x="2" y="3" width="20" height="14" rx="2" ry="2"/><line x1="2" y1="20" x2="22" y2="20"/>',
        'award' => '<circle cx="12" cy="8" r="7"/><polyline points="8.21 13.89 7 23 12 20 17 23 15.79 13.88"/>',
        'gift' => '<polyline points="20 12 20 22 4 22 4 12"/><rect x="2" y="7" width="20" height="5"/><line x1="12" y1="22" x2="12" y2="7"/><path d="M12 7H7.5a2.5 2.5 0 0 1 0-5C11 2 12 7 12 7z"/><path d="M12 7h4.5a2.5 2.5 0 0 0 0-5C13 2 12 7 12 7z"/>',
        'filter' => '<polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/>',
        'grid' => '<rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/>',
        'list' => '<line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/>',
    ];
}
```

**Step 2: Include helper in functions.php**

Add to `web/app/themes/stridence/functions.php`:

```php
// Load helpers
require_once get_stylesheet_directory() . '/helpers/icons.php';
```

**Step 3: Commit**

```bash
git add web/app/themes/stridence/helpers/icons.php web/app/themes/stridence/functions.php
git commit -m "feat(stridence): add SVG icon helper system"
```

---

### Task 1.10: Create Template Directory Structure

**Files:**
- Create directories and placeholder files

**Step 1: Create directory structure**

```bash
mkdir -p web/app/themes/stridence/templates/{archives,detail,dashboard,forms,partials,homepage}
```

**Step 2: Create placeholder index.php files to prevent directory listing**

```php
<?php
// Silence is golden.
```

Place this in each template subdirectory.

**Step 3: Commit**

```bash
git add web/app/themes/stridence/templates/
git commit -m "feat(stridence): create template directory structure"
```

---

## Phase 2: Archive Pages

### Task 2.1: Course Card Component Template

**Files:**
- Create: `web/app/themes/stridence/templates/partials/course-card.php`

**Step 1: Create the course card partial**

```php
<?php
/**
 * Course Card Component
 *
 * @package stridence
 *
 * @var array $course {
 *     @type int    $id          Course/Edition ID
 *     @type string $title       Course title
 *     @type string $url         Permalink
 *     @type string $type        'elearning' or 'classroom'
 *     @type string $thumbnail   Thumbnail URL
 *     @type string $duration    Duration text (e.g., "4 uur", "2 dagen")
 *     @type float  $price       Price
 *     @type string $location    Location (for classroom)
 *     @type string $date_range  Date range (for classroom)
 *     @type int    $spots_left  Remaining spots (for classroom)
 * }
 */

defined('ABSPATH') || exit;

$type_class = $course['type'] === 'elearning' ? 'str-type-badge--elearning' : 'str-type-badge--classroom';
$type_label = $course['type'] === 'elearning' ? __('E-learning', 'stridence') : __('Klassikaal', 'stridence');
?>

<article class="str-card str-card--hover str-course-card">
    <div class="str-course-card__image-wrapper">
        <?php if (!empty($course['thumbnail'])): ?>
            <img
                src="<?php echo esc_url($course['thumbnail']); ?>"
                alt="<?php echo esc_attr($course['title']); ?>"
                class="str-card__image"
                loading="lazy"
            >
        <?php else: ?>
            <div class="str-card__image str-course-card__placeholder">
                <?php stridence_icon('book', '', 48); ?>
            </div>
        <?php endif; ?>

        <span class="str-type-badge <?php echo esc_attr($type_class); ?>">
            <?php echo esc_html($type_label); ?>
        </span>

        <?php if ($course['type'] === 'classroom' && !empty($course['date_range'])): ?>
            <span class="str-course-card__date-badge">
                <?php echo esc_html($course['date_range']); ?>
            </span>
        <?php endif; ?>
    </div>

    <div class="str-card__body">
        <h3 class="str-card__title">
            <a href="<?php echo esc_url($course['url']); ?>">
                <?php echo esc_html($course['title']); ?>
            </a>
        </h3>

        <div class="str-card__meta">
            <?php if (!empty($course['duration'])): ?>
                <span class="str-card__meta-item">
                    <?php stridence_icon('clock', '', 16); ?>
                    <?php echo esc_html($course['duration']); ?>
                </span>
            <?php endif; ?>

            <?php if ($course['type'] === 'classroom' && !empty($course['location'])): ?>
                <span class="str-card__meta-item">
                    <?php stridence_icon('location', '', 16); ?>
                    <?php echo esc_html($course['location']); ?>
                </span>
            <?php endif; ?>
        </div>

        <?php if (!empty($course['spots_left']) && $course['spots_left'] <= 5): ?>
            <div class="str-course-card__warning">
                <?php stridence_icon('warning', '', 16); ?>
                <?php printf(
                    esc_html(_n('Nog %d plaats', 'Nog %d plaatsen', $course['spots_left'], 'stridence')),
                    $course['spots_left']
                ); ?>
            </div>
        <?php endif; ?>

        <div class="str-card__footer">
            <span class="str-card__price">
                <?php echo esc_html('€' . number_format($course['price'], 2, ',', '.')); ?>
            </span>
            <a href="<?php echo esc_url($course['url']); ?>" class="str-btn str-btn--primary str-btn--sm">
                <?php esc_html_e('Bekijk', 'stridence'); ?>
                <?php stridence_icon('chevron-right', '', 16); ?>
            </a>
        </div>
    </div>
</article>
```

**Step 2: Add course card specific CSS to stridence.css**

```css
/* ==========================================================================
   Course Card (extends str-card)
   ========================================================================== */

.str-course-card__image-wrapper {
    position: relative;
}

.str-course-card__placeholder {
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--str-background);
    color: var(--str-text-light);
}

.str-course-card__date-badge {
    position: absolute;
    bottom: var(--str-space-md);
    left: var(--str-space-md);
    padding: var(--str-space-xs) var(--str-space-sm);
    font-size: 0.8rem;
    font-weight: 500;
    background: var(--str-card);
    border-radius: var(--str-radius-sm);
    box-shadow: var(--str-shadow);
}

.str-course-card__warning {
    display: flex;
    align-items: center;
    gap: var(--str-space-xs);
    padding: var(--str-space-sm);
    margin-top: var(--str-space-sm);
    font-size: 0.85rem;
    font-weight: 500;
    color: #b45309;
    background: #fef3c7;
    border-radius: var(--str-radius-sm);
}
```

**Step 3: Commit**

```bash
git add web/app/themes/stridence/templates/partials/course-card.php web/app/themes/stridence/assets/css/stridence.css
git commit -m "feat(stridence): add course card component"
```

---

### Task 2.2: Archive Filter Component

**Files:**
- Create: `web/app/themes/stridence/templates/partials/archive-filters.php`

**Step 1: Create filter partial**

```php
<?php
/**
 * Archive Filters Component
 *
 * @package stridence
 *
 * @var string $current_type Current filter type ('all', 'elearning', 'classroom')
 * @var array  $categories   Available categories
 */

defined('ABSPATH') || exit;

$current_type = $current_type ?? 'all';
$base_url = get_post_type_archive_link('sfwd-courses');
?>

<div class="str-filters">
    <button type="button" class="str-filters__toggle str-btn str-btn--secondary" aria-expanded="false">
        <?php stridence_icon('filter', '', 18); ?>
        <?php esc_html_e('Filters', 'stridence'); ?>
    </button>

    <div class="str-filters__panel" hidden>
        <div class="str-filters__group">
            <span class="str-filters__label"><?php esc_html_e('Type', 'stridence'); ?></span>
            <div class="str-filters__options">
                <a href="<?php echo esc_url(home_url('/cursussen/')); ?>"
                   class="str-filters__option <?php echo $current_type === 'all' ? 'str-filters__option--active' : ''; ?>">
                    <?php esc_html_e('Alle', 'stridence'); ?>
                </a>
                <a href="<?php echo esc_url(home_url('/cursussen/e-learning/')); ?>"
                   class="str-filters__option <?php echo $current_type === 'elearning' ? 'str-filters__option--active' : ''; ?>">
                    <?php esc_html_e('E-learning', 'stridence'); ?>
                </a>
                <a href="<?php echo esc_url(home_url('/cursussen/klassikaal/')); ?>"
                   class="str-filters__option <?php echo $current_type === 'classroom' ? 'str-filters__option--active' : ''; ?>">
                    <?php esc_html_e('Klassikaal', 'stridence'); ?>
                </a>
            </div>
        </div>

        <?php if (!empty($categories)): ?>
        <div class="str-filters__group">
            <span class="str-filters__label"><?php esc_html_e('Categorie', 'stridence'); ?></span>
            <div class="str-filters__options">
                <?php foreach ($categories as $cat): ?>
                    <a href="<?php echo esc_url(add_query_arg('category', $cat->slug)); ?>"
                       class="str-filters__option">
                        <?php echo esc_html($cat->name); ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
```

**Step 2: Add filter CSS**

```css
/* ==========================================================================
   Archive Filters
   ========================================================================== */

.str-filters {
    margin-bottom: var(--str-space-xl);
}

.str-filters__toggle {
    display: flex;
    align-items: center;
    gap: var(--str-space-sm);
}

@media (min-width: 768px) {
    .str-filters__toggle {
        display: none;
    }

    .str-filters__panel {
        display: block !important;
    }
}

.str-filters__panel {
    margin-top: var(--str-space-md);
    padding: var(--str-space-lg);
    background: var(--str-card);
    border: 1px solid var(--str-border);
    border-radius: var(--str-radius);
}

@media (min-width: 768px) {
    .str-filters__panel {
        display: flex;
        gap: var(--str-space-xl);
        padding: var(--str-space-md) 0;
        background: transparent;
        border: none;
    }
}

.str-filters__group {
    margin-bottom: var(--str-space-md);
}

@media (min-width: 768px) {
    .str-filters__group {
        margin-bottom: 0;
    }
}

.str-filters__label {
    display: block;
    font-size: 0.8rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: var(--str-text-muted);
    margin-bottom: var(--str-space-sm);
}

.str-filters__options {
    display: flex;
    flex-wrap: wrap;
    gap: var(--str-space-sm);
}

.str-filters__option {
    padding: var(--str-space-sm) var(--str-space-md);
    font-size: 0.875rem;
    color: var(--str-text);
    background: var(--str-background);
    border-radius: var(--str-radius-full);
    text-decoration: none;
    transition: all var(--str-transition);
}

.str-filters__option:hover {
    background: var(--str-border);
}

.str-filters__option--active {
    background: var(--str-primary);
    color: #fff;
}

.str-filters__option--active:hover {
    background: var(--str-primary-hover);
}
```

**Step 3: Add filter toggle JS to functions.php**

```php
// Add inline filter toggle script
add_action('wp_footer', function() {
    if (!is_post_type_archive('sfwd-courses')) {
        return;
    }
    ?>
    <script>
    (function() {
        var toggle = document.querySelector('.str-filters__toggle');
        var panel = document.querySelector('.str-filters__panel');
        if (!toggle || !panel) return;

        toggle.addEventListener('click', function() {
            var expanded = toggle.getAttribute('aria-expanded') === 'true';
            toggle.setAttribute('aria-expanded', !expanded);
            panel.hidden = expanded;
        });
    })();
    </script>
    <?php
}, 100);
```

**Step 4: Commit**

```bash
git add web/app/themes/stridence/templates/partials/archive-filters.php web/app/themes/stridence/assets/css/stridence.css web/app/themes/stridence/functions.php
git commit -m "feat(stridence): add archive filters component"
```

---

### Task 2.3: Course Archive Template

**Files:**
- Create: `web/app/themes/stridence/templates/archives/courses.php`
- Create: `web/app/themes/stridence/archive-sfwd-courses.php`

**Step 1: Create the archive template**

```php
<?php
/**
 * Course Archive Template
 *
 * @package stridence
 */

defined('ABSPATH') || exit;

get_header();

// Get course type filter from URL
$course_type = 'all';
$request_uri = $_SERVER['REQUEST_URI'] ?? '';
if (strpos($request_uri, '/e-learning') !== false) {
    $course_type = 'elearning';
} elseif (strpos($request_uri, '/klassikaal') !== false) {
    $course_type = 'classroom';
}

// Get courses
$courses = [];

// Get LearnDash courses
$args = [
    'post_type' => 'sfwd-courses',
    'posts_per_page' => 12,
    'post_status' => 'publish',
    'paged' => get_query_var('paged') ?: 1,
];

$query = new WP_Query($args);

// Get edition service for classroom courses
$editionService = null;
if (class_exists(\Stride\Modules\Edition\EditionService::class)) {
    try {
        $editionService = ntdst_get(\Stride\Modules\Edition\EditionService::class);
    } catch (\Exception $e) {
        // Service not available
    }
}

if ($query->have_posts()) {
    while ($query->have_posts()) {
        $query->the_post();
        $courseId = get_the_ID();

        // Determine if classroom or e-learning
        $isClassroom = false;
        $editions = [];
        $nextEdition = null;

        if ($editionService) {
            $editions = $editionService->getEditionsForCourse($courseId);
            $isClassroom = !empty($editions);

            // Get next upcoming edition
            if ($isClassroom) {
                foreach ($editions as $edition) {
                    $startDate = $edition['start_date'] ?? '';
                    if ($startDate && $startDate >= wp_date('Y-m-d')) {
                        $nextEdition = $edition;
                        break;
                    }
                }
            }
        }

        // Apply type filter
        if ($course_type === 'elearning' && $isClassroom) continue;
        if ($course_type === 'classroom' && !$isClassroom) continue;

        // Get course duration from LearnDash
        $duration = get_post_meta($courseId, '_ld_course_duration', true) ?: '';

        // Get price (from edition if classroom, or course meta)
        $price = 0;
        if ($nextEdition) {
            $price = $nextEdition['price'] ?? 0;
        } else {
            $price = get_post_meta($courseId, '_ld_course_price', true) ?: 0;
        }

        $courses[] = [
            'id' => $courseId,
            'title' => get_the_title(),
            'url' => $nextEdition ? get_permalink($nextEdition['id']) : get_permalink(),
            'type' => $isClassroom ? 'classroom' : 'elearning',
            'thumbnail' => get_the_post_thumbnail_url($courseId, 'medium_large'),
            'duration' => $duration,
            'price' => (float) $price,
            'location' => $nextEdition['location'] ?? '',
            'date_range' => $nextEdition ? date_i18n('j M', strtotime($nextEdition['start_date'])) : '',
            'spots_left' => $nextEdition['spots_left'] ?? null,
        ];
    }
    wp_reset_postdata();
}

// Page titles
$titles = [
    'all' => __('Alle cursussen', 'stridence'),
    'elearning' => __('E-learning cursussen', 'stridence'),
    'classroom' => __('Klassikale cursussen', 'stridence'),
];
?>

<main class="str-main">
    <div class="str-container">
        <section class="str-section">
            <header class="str-section__header">
                <h1 class="str-section__title"><?php echo esc_html($titles[$course_type]); ?></h1>
                <p class="str-section__subtitle">
                    <?php esc_html_e('Ontdek ons aanbod aan professionele trainingen', 'stridence'); ?>
                </p>
            </header>

            <?php
            $current_type = $course_type;
            $categories = get_terms(['taxonomy' => 'ld_course_category', 'hide_empty' => true]);
            include get_stylesheet_directory() . '/templates/partials/archive-filters.php';
            ?>

            <?php if (!empty($courses)): ?>
                <div class="str-grid str-grid--courses">
                    <?php foreach ($courses as $course): ?>
                        <?php include get_stylesheet_directory() . '/templates/partials/course-card.php'; ?>
                    <?php endforeach; ?>
                </div>

                <?php if ($query->max_num_pages > 1): ?>
                    <nav class="str-pagination">
                        <?php
                        echo paginate_links([
                            'total' => $query->max_num_pages,
                            'current' => get_query_var('paged') ?: 1,
                            'prev_text' => stridence_get_icon('arrow-left', '', 16) . __('Vorige', 'stridence'),
                            'next_text' => __('Volgende', 'stridence') . stridence_get_icon('arrow-right', '', 16),
                        ]);
                        ?>
                    </nav>
                <?php endif; ?>
            <?php else: ?>
                <div class="str-empty-state">
                    <?php stridence_icon('book', '', 48); ?>
                    <h2><?php esc_html_e('Geen cursussen gevonden', 'stridence'); ?></h2>
                    <p><?php esc_html_e('Er zijn momenteel geen cursussen beschikbaar in deze categorie.', 'stridence'); ?></p>
                </div>
            <?php endif; ?>
        </section>
    </div>
</main>

<?php get_footer(); ?>
```

**Step 2: Create the archive template file in theme root**

```php
<?php
/**
 * Archive template for LearnDash courses
 *
 * @package stridence
 */

include get_stylesheet_directory() . '/templates/archives/courses.php';
```

Save as `web/app/themes/stridence/archive-sfwd-courses.php`.

**Step 3: Add pagination and empty state CSS**

```css
/* ==========================================================================
   Pagination
   ========================================================================== */

.str-pagination {
    display: flex;
    justify-content: center;
    gap: var(--str-space-sm);
    margin-top: var(--str-space-2xl);
}

.str-pagination a,
.str-pagination span {
    display: inline-flex;
    align-items: center;
    gap: var(--str-space-xs);
    padding: var(--str-space-sm) var(--str-space-md);
    font-size: 0.9rem;
    color: var(--str-text);
    background: var(--str-card);
    border: 1px solid var(--str-border);
    border-radius: var(--str-radius-sm);
    text-decoration: none;
    transition: all var(--str-transition);
}

.str-pagination a:hover {
    background: var(--str-background);
    border-color: var(--str-primary);
}

.str-pagination .current {
    background: var(--str-primary);
    border-color: var(--str-primary);
    color: #fff;
}

/* ==========================================================================
   Empty State
   ========================================================================== */

.str-empty-state {
    text-align: center;
    padding: var(--str-space-2xl);
    color: var(--str-text-muted);
}

.str-empty-state h2 {
    margin: var(--str-space-md) 0 var(--str-space-sm);
    font-size: 1.25rem;
    color: var(--str-text);
}

.str-empty-state p {
    margin: 0;
}
```

**Step 4: Commit**

```bash
git add web/app/themes/stridence/templates/archives/courses.php web/app/themes/stridence/archive-sfwd-courses.php web/app/themes/stridence/assets/css/stridence.css
git commit -m "feat(stridence): add course archive template"
```

---

## Phase 3-6: Continue with remaining templates...

Due to the extensive scope, I'll outline the remaining tasks at a higher level. Each task follows the same pattern: create template, add CSS, commit.

### Phase 3: Detail Pages
- Task 3.1: Course detail template (e-learning)
- Task 3.2: Edition detail template (klassikaal)
- Task 3.3: Trajectory detail template
- Task 3.4: Session list component

### Phase 4: User Dashboard
- Task 4.1: Dashboard layout with sidebar/bottom nav
- Task 4.2: Dashboard overview page
- Task 4.3: My courses page
- Task 4.4: My calendar page
- Task 4.5: My trajectories page
- Task 4.6: My quotes page
- Task 4.7: My profile page

### Phase 5: Forms
- Task 5.1: Enrollment form template
- Task 5.2: Interest form template
- Task 5.3: Form success/confirmation pages

### Phase 6: Homepage
- Task 6.1: Hero section
- Task 6.2: Course types section
- Task 6.3: Featured courses section
- Task 6.4: Trajectories section
- Task 6.5: Upcoming sessions section
- Task 6.6: Why choose us section
- Task 6.7: Testimonials section
- Task 6.8: CTA and footer

---

## Verification Stages (MANDATORY)

> Run AFTER all implementation tasks. NOT done until all stages pass.

### Stage V1: Static Analysis

```bash
# Check PHP syntax
find web/app/themes/stridence -name "*.php" -exec php -l {} \;

# Check CSS syntax (if stylelint available)
npx stylelint "web/app/themes/stridence/**/*.css" --fix
```

Expected: No syntax errors.

### Stage V2: Theme Activation Test

```bash
ddev exec wp theme activate stridence
ddev exec wp cache flush
```

Expected: Theme activates without errors.

### Stage V3: Visual Verification

**Test files to verify:**
- `tests/acceptance/StrideArchiveCest.php`
- `tests/acceptance/StrideDashboardCest.php`

**Manual checks:**
```markdown
## Manual Smoke Test

- [ ] Visit: /cursussen/
      Expected: Course grid displays, filters work, no PHP errors
- [ ] Visit: /cursussen/e-learning/
      Expected: Only e-learning courses shown
- [ ] Visit: /cursussen/klassikaal/
      Expected: Only classroom editions shown
- [ ] Visit: /mijn-account/ (logged in)
      Expected: Dashboard with bottom nav on mobile
- [ ] Mobile: Check bottom nav works
      Expected: 5 icons, active state, navigation works
- [ ] Console: Open DevTools
      Expected: No JavaScript errors
```

### Stage V4: Mobile Responsiveness

```markdown
## Mobile Tests (Chrome DevTools)

- [ ] iPhone SE (375px): All content readable, no horizontal scroll
- [ ] iPhone 12 (390px): Cards stack single column
- [ ] iPad (768px): Cards 2-column, sidebar appears
- [ ] Desktop (1024px+): Cards 3-column, full layout
```

### Stage V5: Integration Test

```bash
ddev exec vendor/bin/codecept run acceptance StridenceCest --steps
```

Expected: All acceptance tests pass.
