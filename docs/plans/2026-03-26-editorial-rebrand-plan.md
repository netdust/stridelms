# Editorial Rebrand Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Apply a warm editorial design language to the Stride LMS theme for a Belgian healthcare client demo, keeping all existing page structures intact except the homepage.

**Architecture:** CSS-only rebrand via design token system. All visual changes flow through `tokens.css` custom properties → consumed by Tailwind config → used in component classes and templates. Homepage gets a new editorial template. No backend/PHP service changes.

**Tech Stack:** Tailwind CSS, CSS custom properties, Google Fonts (Newsreader, Plus Jakarta Sans, Manrope), Alpine.js (existing, unchanged)

**Spec:** `docs/plans/2026-03-26-editorial-rebrand-design.md`

---

## File Map

| File | Action | Responsibility |
|------|--------|---------------|
| `src/css/tokens.css` | Modify | Design token values (colors, fonts, spacing, shadows, radii) |
| `src/css/base.css` | Modify | Add glass-nav, blur-blob utilities |
| `src/css/components.css` | Modify | Editorial component adaptations (buttons, cards, badges, lists) |
| `src/css/learndash.css` | Modify | Remove focus mode header border |
| `tailwind.config.js` | Modify | New font families, surface containers, tertiary/accent colors |
| `header.php` | Modify | Glass nav, font swap, editorial logo, remove borders |
| `header-dashboard.php` | Modify | Font swap only (1 line) |
| `footer.php` | Modify | Remove borders, editorial typography |
| `front-page.php` | Rewrite | Full editorial homepage |
| `stride-client-example/assets/client.css` | Modify | Update token inventory comments |

---

## Task 1: Design Tokens

**Files:**
- Modify: `web/app/themes/stridence/src/css/tokens.css`

- [ ] **Step 1: Replace all token values**

Replace the entire `:root` block in `tokens.css` with the editorial palette. Preserve ALL existing token names and add new ones. The full replacement:

```css
:root {
  /* ── Brand Colors ── */
  --color-primary: 8 106 105;          /* #086a69 deep teal */
  --color-primary-hover: 0 93 92;      /* #005d5c */
  --color-primary-subtle: 232 248 247; /* #e8f8f7 very subtle teal */
  --color-primary-light: 142 220 218;  /* #8edcda */
  --color-primary-dark: 0 88 87;       /* #005857 */
  --color-accent: 152 72 45;          /* #98482d terracotta */
  --color-accent-light: 244 145 113;  /* #f49171 */

  /* ── Neutral Colors (warm paper) ── */
  --color-surface: 251 250 242;        /* #fbfaf2 */
  --color-surface-alt: 244 244 235;    /* #f4f4eb */
  --color-surface-card: 255 255 255;   /* #ffffff */
  --color-border: 177 179 167;         /* #b1b3a7 ghost borders */
  --color-border-strong: 121 124 113;  /* #797c71 */
  --color-text: 49 51 43;             /* #31332b never pure black */
  --color-text-muted: 94 96 86;       /* #5e6056 */
  --color-text-inverse: 255 255 255;

  /* ── Status Colors ── */
  --color-success: 22 163 74;
  --color-warning: 217 119 6;
  --color-error: 172 52 52;            /* #ac3434 warmer red */
  --color-info: 8 106 105;

  /* ── Badge Colors (token-driven) ── */
  --color-badge-open-bg: 240 253 244;
  --color-badge-open-text: 21 128 61;
  --color-badge-few-bg: 254 252 232;
  --color-badge-few-text: 161 98 7;
  --color-badge-full-bg: 254 242 242;
  --color-badge-full-text: 153 27 27;
  --color-badge-cancelled-bg: 249 250 251;
  --color-badge-cancelled-text: 107 114 128;
  --color-badge-online-bg: 232 248 247;
  --color-badge-online-text: 8 106 105;
  --color-badge-free-bg: 236 253 245;
  --color-badge-free-text: 4 120 87;

  /* ── Typography ── */
  --font-sans: 'Plus Jakarta Sans', 'Manrope', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
  --font-heading: 'Plus Jakarta Sans', system-ui, sans-serif;
  --font-serif: 'Newsreader', Georgia, 'Times New Roman', serif;

  /* ── Spacing ── */
  --space-section: 6rem;
  --space-block: 3.5rem;
  --space-element: 1.5rem;

  /* ── Layout (unchanged) ── */
  --container-max: 1280px;
  --content-max: 960px;
  --sidebar-width: 240px;
  --sidebar-collapsed: 56px;

  /* ── Border Radius ── */
  --radius-sm: 0.5rem;
  --radius-md: 0.75rem;
  --radius-lg: 1rem;
  --radius-xl: 1.5rem;

  /* ── Shadows (warm-tinted) ── */
  --shadow-xs: 0 1px 2px rgba(49, 51, 43, 0.03);
  --shadow-sm: 0 2px 6px rgba(49, 51, 43, 0.04);
  --shadow-md: 0 8px 16px -4px rgba(49, 51, 43, 0.06);
  --shadow-lg: 0 20px 40px rgba(49, 51, 43, 0.06);
  --shadow-overlay: 0 24px 48px -12px rgba(49, 51, 43, 0.12);

  /* Tailwind-mapped aliases */
  --shadow-card: var(--shadow-xs);
  --shadow-elevated: var(--shadow-md);

  /* ── Transitions (unchanged) ── */
  --ease-out: cubic-bezier(0.16, 1, 0.3, 1);
  --duration-fast: 150ms;
  --duration-normal: 250ms;

  /* ── Editorial Surface Containers ── */
  --color-tertiary: 129 82 0;
  --color-tertiary-light: 255 185 92;
  --color-secondary-container: 221 246 239;
  --color-surface-container: 238 238 228;
  --color-surface-container-high: 232 233 222;
  --color-surface-container-highest: 226 228 215;
}
```

Keep the `@media (prefers-reduced-motion)` block unchanged.

- [ ] **Step 2: Verify build compiles**

Run: `cd web/app/themes/stridence && npm run build`
Expected: Build succeeds, no errors.

- [ ] **Step 3: Commit**

```bash
git add web/app/themes/stridence/src/css/tokens.css
git commit -m "style: remap design tokens to editorial palette"
```

---

## Task 2: Tailwind Config

**Files:**
- Modify: `web/app/themes/stridence/tailwind.config.js`

- [ ] **Step 1: Add new color and font entries**

Add `accent`, `tertiary`, `secondary-container` colors. Extend `surface` with container variants. Add `serif` and `label` font families. The complete updated config:

```js
/** @type {import('tailwindcss').Config} */
export default {
  content: [
    './*.php',
    './templates/**/*.php',
    './partials/**/*.php',
    './src/**/*.js',
  ],

  theme: {
    extend: {
      colors: {
        primary: {
          DEFAULT: 'rgb(var(--color-primary) / <alpha-value>)',
          hover: 'rgb(var(--color-primary-hover) / <alpha-value>)',
          subtle: 'rgb(var(--color-primary-subtle) / <alpha-value>)',
          light: 'rgb(var(--color-primary-light) / <alpha-value>)',
          dark: 'rgb(var(--color-primary-dark) / <alpha-value>)',
        },
        accent: {
          DEFAULT: 'rgb(var(--color-accent) / <alpha-value>)',
          light: 'rgb(var(--color-accent-light) / <alpha-value>)',
        },
        tertiary: {
          DEFAULT: 'rgb(var(--color-tertiary) / <alpha-value>)',
          light: 'rgb(var(--color-tertiary-light) / <alpha-value>)',
        },
        'secondary-container': 'rgb(var(--color-secondary-container) / <alpha-value>)',
        surface: {
          DEFAULT: 'rgb(var(--color-surface) / <alpha-value>)',
          alt: 'rgb(var(--color-surface-alt) / <alpha-value>)',
          card: 'rgb(var(--color-surface-card) / <alpha-value>)',
          container: 'rgb(var(--color-surface-container) / <alpha-value>)',
          'container-high': 'rgb(var(--color-surface-container-high) / <alpha-value>)',
          'container-highest': 'rgb(var(--color-surface-container-highest) / <alpha-value>)',
        },
        border: {
          DEFAULT: 'rgb(var(--color-border) / <alpha-value>)',
          strong: 'rgb(var(--color-border-strong) / <alpha-value>)',
        },
        text: {
          DEFAULT: 'rgb(var(--color-text) / <alpha-value>)',
          muted: 'rgb(var(--color-text-muted) / <alpha-value>)',
          inverse: 'rgb(var(--color-text-inverse) / <alpha-value>)',
        },
        success: 'rgb(var(--color-success) / <alpha-value>)',
        warning: 'rgb(var(--color-warning) / <alpha-value>)',
        error: 'rgb(var(--color-error) / <alpha-value>)',
        info: 'rgb(var(--color-info) / <alpha-value>)',
      },

      fontFamily: {
        sans: ['var(--font-sans)'],
        heading: ['var(--font-heading)'],
        serif: ['var(--font-serif)'],
        label: ['Manrope', 'var(--font-sans)'],
      },

      maxWidth: {
        content: 'var(--content-max)',
        container: 'var(--container-max)',
      },

      boxShadow: {
        card: 'var(--shadow-card)',
        elevated: 'var(--shadow-elevated)',
        overlay: 'var(--shadow-overlay)',
      },

      borderRadius: {
        sm: 'var(--radius-sm)',
        md: 'var(--radius-md)',
        lg: 'var(--radius-lg)',
        xl: 'var(--radius-xl)',
      },

      transitionTimingFunction: {
        out: 'var(--ease-out)',
      },

      transitionDuration: {
        fast: 'var(--duration-fast)',
        normal: 'var(--duration-normal)',
      },

      spacing: {
        section: 'var(--space-section)',
        block: 'var(--space-block)',
        element: 'var(--space-element)',
        sidebar: 'var(--sidebar-width)',
        'sidebar-collapsed': 'var(--sidebar-collapsed)',
      },
    },
  },

  plugins: [],
};
```

- [ ] **Step 2: Verify build compiles**

Run: `cd web/app/themes/stridence && npm run build`
Expected: Build succeeds.

- [ ] **Step 3: Commit**

```bash
git add web/app/themes/stridence/tailwind.config.js
git commit -m "style: add editorial font families and surface container colors to Tailwind"
```

---

## Task 3: Base CSS — Editorial Utilities

**Files:**
- Modify: `web/app/themes/stridence/src/css/base.css`

- [ ] **Step 1: Add editorial utilities to base layer**

Add after the existing `nav ul` rule (before the closing `}` of `@layer base`):

```css
  /* ── Editorial Utilities ── */
  .glass-nav {
    backdrop-filter: blur(20px);
    -webkit-backdrop-filter: blur(20px);
    background: rgb(var(--color-surface) / 0.8);
  }

  .blur-blob {
    filter: blur(80px);
    opacity: 0.12;
    pointer-events: none;
  }

  /* Focus ring offset for glass backgrounds */
  .glass-nav :focus-visible {
    --tw-ring-offset-color: rgb(var(--color-surface));
  }
```

- [ ] **Step 2: Verify build**

Run: `cd web/app/themes/stridence && npm run build`
Expected: Build succeeds.

- [ ] **Step 3: Commit**

```bash
git add web/app/themes/stridence/src/css/base.css
git commit -m "style: add glass-nav and blur-blob editorial utilities"
```

---

## Task 4: Component Adaptations

**Files:**
- Modify: `web/app/themes/stridence/src/css/components.css`

- [ ] **Step 1: Update buttons — rounded-full**

Change `.btn-primary` from `rounded-lg` to `rounded-full`:

```css
  .btn-primary {
    @apply inline-flex items-center justify-center gap-2
           bg-primary text-text-inverse font-medium
           px-5 py-2.5 rounded-full cursor-pointer
           hover:bg-primary-hover
           focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/50 focus-visible:ring-offset-2
           disabled:opacity-50 disabled:cursor-not-allowed
           transition-colors duration-fast;
  }
```

Change `.btn-secondary` — remove border:

```css
  .btn-secondary {
    @apply inline-flex items-center justify-center gap-2
           bg-surface-card text-text font-medium
           px-5 py-2.5 rounded-full border-0 cursor-pointer
           hover:bg-surface-alt
           focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/50 focus-visible:ring-offset-2
           disabled:opacity-50 disabled:cursor-not-allowed
           transition-colors duration-fast;
  }
```

- [ ] **Step 2: Update cards — no border**

```css
  .card {
    @apply bg-surface-card rounded-xl;
    box-shadow: var(--shadow-xs);
  }

  .card-interactive {
    @apply card cursor-pointer;
    transition: box-shadow var(--duration-normal) var(--ease-out),
                transform var(--duration-normal) var(--ease-out);
  }

  .card-interactive:hover {
    box-shadow: var(--shadow-md);
    transform: translateY(-1px);
  }

  .card-bordered {
    @apply card border border-border shadow-none;
  }
```

- [ ] **Step 3: Update dashboard cards — no side borders**

```css
  .dash-card {
    @apply bg-surface-card rounded-xl;
    box-shadow: var(--shadow-xs);
    transition: box-shadow var(--duration-normal) var(--ease-out),
                transform var(--duration-normal) var(--ease-out);
  }

  .dash-card-interactive {
    @apply dash-card cursor-pointer;
  }

  .dash-card-interactive:hover {
    box-shadow: var(--shadow-sm);
    transform: translateY(-1px);
  }

  .dash-card-hero {
    @apply bg-surface-card rounded-xl p-6 lg:p-8;
    background: linear-gradient(135deg, rgb(var(--color-primary) / 0.04), rgb(var(--color-primary) / 0.10));
    box-shadow: var(--shadow-sm);
  }
```

Note: `.dash-card-hero` has NO `border-left`. Never use one-sided borders on rounded cards.

- [ ] **Step 4: Update badges — token-driven, no rings**

Replace ALL badge classes:

```css
  .badge {
    @apply inline-flex items-center text-xs font-medium px-2.5 py-1 rounded-full;
  }

  .badge-open {
    background: rgb(var(--color-badge-open-bg));
    color: rgb(var(--color-badge-open-text));
  }

  .badge-few {
    background: rgb(var(--color-badge-few-bg));
    color: rgb(var(--color-badge-few-text));
  }

  .badge-full {
    background: rgb(var(--color-badge-full-bg));
    color: rgb(var(--color-badge-full-text));
  }

  .badge-cancelled {
    background: rgb(var(--color-badge-cancelled-bg));
    color: rgb(var(--color-badge-cancelled-text));
  }

  .badge-online {
    background: rgb(var(--color-badge-online-bg));
    color: rgb(var(--color-badge-online-text));
  }

  .badge-free {
    background: rgb(var(--color-badge-free-bg));
    color: rgb(var(--color-badge-free-text));
  }

  .badge-primary {
    @apply badge bg-primary/10 text-primary;
  }
```

- [ ] **Step 5: Update list items — no bottom borders**

```css
  .list-item {
    @apply flex items-center gap-4 px-4 py-3.5
           cursor-pointer rounded-lg
           hover:bg-surface-alt
           transition-colors duration-fast;
  }

  .list-item-static {
    @apply list-item cursor-default hover:bg-transparent;
  }
```

- [ ] **Step 6: Update sidebar divider — ghost border**

```css
  .sidebar-divider {
    @apply my-3 mx-3;
    border-top: 1px solid rgb(var(--color-border) / 0.15);
  }
```

- [ ] **Step 7: Verify build**

Run: `cd web/app/themes/stridence && npm run build`
Expected: Build succeeds.

- [ ] **Step 8: Commit**

```bash
git add web/app/themes/stridence/src/css/components.css
git commit -m "style: editorial component adaptations — rounded buttons, borderless cards, token badges"
```

---

## Task 5: LearnDash CSS

**Files:**
- Modify: `web/app/themes/stridence/src/css/learndash.css`

- [ ] **Step 1: Add focus mode border removal**

Add at the end of the file, inside the `@layer components` block:

```css
  /* ══════════════════════════════════════
     FOCUS MODE — Editorial No-Line Rule
     ══════════════════════════════════════ */

  .ld-focus .ld-focus-header {
    border-bottom: none;
  }
```

- [ ] **Step 2: Verify build**

Run: `cd web/app/themes/stridence && npm run build`
Expected: Build succeeds.

- [ ] **Step 3: Commit**

```bash
git add web/app/themes/stridence/src/css/learndash.css
git commit -m "style: remove LearnDash focus mode header border"
```

---

## Task 6: Header — Glass Nav + Fonts

**Files:**
- Modify: `web/app/themes/stridence/header.php`

- [ ] **Step 1: Swap Google Fonts link**

Replace line 17:
```html
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@600;700&display=swap" rel="stylesheet">
```

With:
```html
    <link href="https://fonts.googleapis.com/css2?family=Newsreader:ital,opsz,wght@0,6..72,300..800;1,6..72,300..800&family=Plus+Jakarta+Sans:wght@300..800&family=Manrope:wght@400;500;600;700&display=swap" rel="stylesheet">
```

- [ ] **Step 2: Apply glass nav to header element**

Replace line 31:
```php
    <header class="sticky top-0 z-40 bg-surface-card border-b border-border" x-data="mobileMenu()">
```

With:
```php
    <header class="sticky top-0 z-40 glass-nav" x-data="mobileMenu()">
```

- [ ] **Step 3: Update logo fallback to serif italic**

Replace lines 39-41:
```php
                    <?php else : ?>
                        <span class="text-xl font-heading font-bold text-primary">
                            <?php bloginfo('name'); ?>
                        </span>
```

With:
```php
                    <?php else : ?>
                        <span class="text-xl font-serif italic font-semibold text-accent">
                            <?php bloginfo('name'); ?>
                        </span>
```

- [ ] **Step 4: Remove dropdown border**

Replace line 91 — the dropdown container class:
```php
                                 class="absolute right-0 mt-2 w-48 bg-surface-card rounded-lg shadow-overlay border border-border py-1 z-50">
```

With:
```php
                                 class="absolute right-0 mt-2 w-48 bg-surface-card rounded-xl shadow-overlay py-1 z-50">
```

- [ ] **Step 5: Remove hr in mobile menu**

Replace line 152:
```php
                <hr class="my-4 border-border">
```

With:
```php
                <div class="py-3"></div>
```

- [ ] **Step 6: Verify site loads**

Run: `ddev launch` and check header on homepage.
Expected: Glass nav with blurred background, serif italic logo, no borders.

- [ ] **Step 7: Commit**

```bash
git add web/app/themes/stridence/header.php
git commit -m "style: editorial glass nav, serif logo, remove borders"
```

---

## Task 7: Header Dashboard — Font Swap

**Files:**
- Modify: `web/app/themes/stridence/header-dashboard.php`

- [ ] **Step 1: Swap Google Fonts link**

Replace line 20:
```html
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
```

With:
```html
    <link href="https://fonts.googleapis.com/css2?family=Newsreader:ital,opsz,wght@0,6..72,300..800;1,6..72,300..800&family=Plus+Jakarta+Sans:wght@300..800&family=Manrope:wght@400;500;600;700&display=swap" rel="stylesheet">
```

- [ ] **Step 2: Commit**

```bash
git add web/app/themes/stridence/header-dashboard.php
git commit -m "style: swap dashboard header fonts to editorial stack"
```

---

## Task 8: Footer — Editorial Typography

**Files:**
- Modify: `web/app/themes/stridence/footer.php`

- [ ] **Step 1: Remove top border from footer**

Replace line 13:
```php
    <footer class="bg-surface-alt border-t border-border mt-auto <?php echo is_page_template('page-mijn-account.php') ? 'hidden lg:block' : ''; ?>">
```

With:
```php
    <footer class="bg-surface-alt mt-auto <?php echo is_page_template('page-mijn-account.php') ? 'hidden lg:block' : ''; ?>">
```

- [ ] **Step 2: Update logo fallback to serif italic**

Replace lines 22-24:
```php
                    <?php else : ?>
                        <span class="text-xl font-heading font-bold text-primary">
                            <?php bloginfo('name'); ?>
                        </span>
```

With:
```php
                    <?php else : ?>
                        <span class="text-xl font-serif italic font-semibold text-accent">
                            <?php bloginfo('name'); ?>
                        </span>
```

- [ ] **Step 3: Update section titles to Manrope label style**

Replace all `<h4>` section titles (3 instances) from:
```php
                    <h4 class="font-heading font-semibold text-sm uppercase tracking-wide text-text-muted mb-4">
```

To:
```php
                    <h4 class="font-label font-bold text-[10px] uppercase tracking-widest text-text-muted/60 mb-5">
```

Use `replace_all` for this pattern since it appears 3 times.

- [ ] **Step 4: Update link hover to terracotta**

Replace all footer link hover classes from `hover:text-primary` to `hover:text-accent`:
```php
<!-- Replace in all <a> tags within footer link lists -->
hover:text-primary → hover:text-accent
```

- [ ] **Step 5: Remove bottom bar border**

Replace line 68:
```php
            <div class="mt-12 pt-8 border-t border-border flex flex-col sm:flex-row justify-between items-center gap-4">
```

With:
```php
            <div class="mt-16 pt-8 flex flex-col sm:flex-row justify-between items-center gap-4">
```

- [ ] **Step 6: Commit**

```bash
git add web/app/themes/stridence/footer.php
git commit -m "style: editorial footer — serif logo, label headings, no borders"
```

---

## Task 9: Homepage — Editorial Rewrite

**Files:**
- Rewrite: `web/app/themes/stridence/front-page.php`

- [ ] **Step 1: Write the complete editorial homepage**

Replace the entire file content with the new editorial structure. The template keeps the same PHP data queries (WP_Query for editions, online courses, trajectories) but wraps them in editorial HTML.

```php
<?php
/**
 * Homepage Template — Editorial
 *
 * @package stridence
 */

get_header();
?>

<!-- Hero Section -->
<section class="relative pt-40 lg:pt-52 pb-20 lg:pb-32 px-6 overflow-hidden">
    <!-- Decorative Blobs -->
    <div class="absolute -top-24 -left-24 w-96 h-96 bg-primary-light rounded-full blur-blob"></div>
    <div class="absolute top-1/2 -right-48 w-[500px] h-[500px] bg-secondary-container rounded-full blur-blob"></div>

    <div class="container relative z-10">
        <div class="max-w-3xl">
            <span class="inline-block text-primary font-label font-semibold tracking-widest uppercase text-xs mb-6">
                <?php esc_html_e('Professionele Ontwikkeling in de Zorg', 'stridence'); ?>
            </span>
            <h1 class="font-serif text-6xl lg:text-7xl xl:text-8xl font-light leading-tight mb-8">
                <?php echo wp_kses(
                    __('Versterk je zorgteam met <em class="italic text-primary">deskundige</em> opleidingen.', 'stridence'),
                    ['em' => ['class' => []]]
                ); ?>
            </h1>
            <p class="text-lg lg:text-xl text-text-muted leading-relaxed max-w-2xl mb-10">
                <?php esc_html_e('Wij geloven dat leren net zo zorgvuldig moet zijn als het vak dat het ondersteunt. Ontdek een platform ontworpen voor verdieping, focus en menselijke verbinding.', 'stridence'); ?>
            </p>
            <div class="flex flex-wrap gap-4">
                <a href="<?php echo esc_url(home_url('/klassikaal/')); ?>" class="btn-primary btn-lg">
                    <?php esc_html_e('Bekijk opleidingen', 'stridence'); ?>
                </a>
                <a href="<?php echo esc_url(home_url('/over-ons/')); ?>" class="btn-ghost text-text group">
                    <?php esc_html_e('Onze aanpak', 'stridence'); ?>
                    <svg class="w-4 h-4 group-hover:translate-x-1 transition-transform" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                </a>
            </div>
        </div>
    </div>
</section>

<!-- Learning Mode Selector -->
<section class="section">
    <div class="container">
        <h2 class="font-heading text-3xl font-bold text-center mb-3">
            <?php esc_html_e('Hoe wil je leren?', 'stridence'); ?>
        </h2>
        <p class="text-center text-text-muted mb-12 text-lg">
            <?php esc_html_e('Kies het format dat bij jou past', 'stridence'); ?>
        </p>

        <div class="grid md:grid-cols-3 gap-6">
            <?php
            $trajectory_count = wp_count_posts('vad_trajectory');
            $trajectory_total = isset($trajectory_count->publish) ? (int) $trajectory_count->publish : 0;

            $edition_query = new WP_Query([
                'post_type'      => 'vad_edition',
                'post_status'    => 'publish',
                'posts_per_page' => 1,
                'fields'         => 'ids',
                'meta_query'     => [
                    [
                        'key'     => '_ntdst_status',
                        'value'   => ['draft', 'completed', 'archived'],
                        'compare' => 'NOT IN',
                    ],
                ],
            ]);
            $edition_total = $edition_query->found_posts;

            $online_query = new WP_Query([
                'post_type'      => 'sfwd-courses',
                'post_status'    => 'publish',
                'posts_per_page' => 1,
                'fields'         => 'ids',
                'tax_query'      => [
                    [
                        'taxonomy' => 'stride_format',
                        'field'    => 'slug',
                        'terms'    => ['online', 'e-learning', 'webinar'],
                        'operator' => 'IN',
                    ],
                ],
            ]);
            $online_total = $online_query->found_posts;
            ?>

            <a href="<?php echo esc_url(home_url('/trajecten/')); ?>" class="card p-10 text-center group cursor-pointer">
                <div class="w-16 h-16 mx-auto mb-5 rounded-full bg-primary-subtle flex items-center justify-center group-hover:bg-primary/20 transition-colors">
                    <?php echo stridence_icon('layers', 'w-7 h-7 text-primary'); ?>
                </div>
                <h3 class="font-heading font-semibold text-xl mb-2 text-text group-hover:text-primary transition-colors">
                    <?php esc_html_e('Trajecten', 'stridence'); ?>
                </h3>
                <p class="text-text-muted text-sm mb-4">
                    <?php esc_html_e('Volg een leertraject met meerdere cursussen en begeleiding', 'stridence'); ?>
                </p>
                <span class="text-sm font-medium text-primary">
                    <?php printf(esc_html(_n('%d traject', '%d trajecten', $trajectory_total, 'stridence')), $trajectory_total); ?>
                </span>
            </a>

            <a href="<?php echo esc_url(home_url('/klassikaal/')); ?>" class="card p-10 text-center group cursor-pointer">
                <div class="w-16 h-16 mx-auto mb-5 rounded-full bg-secondary-container flex items-center justify-center group-hover:bg-primary/20 transition-colors">
                    <?php echo stridence_icon('users', 'w-7 h-7 text-primary'); ?>
                </div>
                <h3 class="font-heading font-semibold text-xl mb-2 text-text group-hover:text-primary transition-colors">
                    <?php esc_html_e('Klassikaal', 'stridence'); ?>
                </h3>
                <p class="text-text-muted text-sm mb-4">
                    <?php esc_html_e('Leer samen met anderen onder begeleiding van ervaren docenten', 'stridence'); ?>
                </p>
                <span class="text-sm font-medium text-primary">
                    <?php printf(esc_html(_n('%d editie', '%d edities', $edition_total, 'stridence')), $edition_total); ?>
                </span>
            </a>

            <a href="<?php echo esc_url(home_url('/online/')); ?>" class="card p-10 text-center group cursor-pointer">
                <div class="w-16 h-16 mx-auto mb-5 rounded-full bg-success/10 flex items-center justify-center group-hover:bg-success/20 transition-colors">
                    <?php echo stridence_icon('monitor', 'w-7 h-7 text-success'); ?>
                </div>
                <h3 class="font-heading font-semibold text-xl mb-2 text-text group-hover:text-success transition-colors">
                    <?php esc_html_e('Online', 'stridence'); ?>
                </h3>
                <p class="text-text-muted text-sm mb-4">
                    <?php esc_html_e('Leer op je eigen tempo met e-learning en webinars', 'stridence'); ?>
                </p>
                <span class="text-sm font-medium text-success">
                    <?php printf(esc_html(_n('%d cursus', '%d cursussen', $online_total, 'stridence')), $online_total); ?>
                </span>
            </a>
        </div>
    </div>
</section>

<!-- Featured Courses -->
<?php
$courses = get_posts([
    'post_type'      => 'sfwd-courses',
    'posts_per_page' => 6,
    'orderby'        => 'date',
    'order'          => 'DESC',
]);

if (!empty($courses)) :
?>
<section class="section bg-surface-alt rounded-t-[48px]">
    <div class="container">
        <div class="flex items-end justify-between mb-12">
            <div>
                <h2 class="font-serif text-4xl mb-2"><?php esc_html_e('Binnenkort gepland', 'stridence'); ?></h2>
                <p class="text-text-muted text-lg"><?php esc_html_e('Onze cursussen worden samengesteld door ervaren professionals uit de zorgsector.', 'stridence'); ?></p>
            </div>
            <a href="<?php echo esc_url(home_url('/klassikaal/')); ?>" class="btn-ghost hidden md:inline-flex">
                <?php esc_html_e('Alle edities', 'stridence'); ?> &rarr;
            </a>
        </div>

        <div class="grid gap-6 md:grid-cols-2 lg:grid-cols-3">
            <?php foreach ($courses as $course) : ?>
                <article class="card overflow-hidden flex flex-col">
                    <?php if (has_post_thumbnail($course)) : ?>
                        <a href="<?php echo esc_url(get_permalink($course)); ?>" class="block aspect-video overflow-hidden">
                            <?php echo get_the_post_thumbnail($course, 'stride_course_card', ['class' => 'w-full h-full object-cover']); ?>
                        </a>
                    <?php endif; ?>
                    <div class="p-6 flex-1 flex flex-col">
                        <h3 class="font-heading font-semibold text-lg mb-2 line-clamp-2">
                            <a href="<?php echo esc_url(get_permalink($course)); ?>" class="text-text hover:text-primary">
                                <?php echo esc_html($course->post_title); ?>
                            </a>
                        </h3>
                        <p class="text-sm text-text-muted line-clamp-2 mb-5 flex-1">
                            <?php echo esc_html(wp_trim_words($course->post_excerpt ?: $course->post_content, 20)); ?>
                        </p>
                        <a href="<?php echo esc_url(get_permalink($course)); ?>" class="btn-primary w-full text-center">
                            <?php esc_html_e('Meer info', 'stridence'); ?>
                        </a>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- Mission Section -->
<section class="py-24 lg:py-32 bg-surface-alt relative overflow-hidden">
    <div class="blur-blob absolute -bottom-24 left-1/2 -translate-x-1/2 w-3/4 h-3/4 bg-primary-light/20 rounded-full"></div>
    <div class="container relative z-10">
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-16 lg:gap-24 items-center">
            <div>
                <div class="w-20 h-1 bg-primary mb-8"></div>
                <h2 class="font-serif text-4xl lg:text-5xl italic leading-tight mb-8">
                    <?php esc_html_e('Kwaliteitsvolle nascholing voor de volgende generatie zorgverleners.', 'stridence'); ?>
                </h2>
                <div class="space-y-6 text-lg text-text-muted leading-relaxed">
                    <p><?php esc_html_e('Wij geloven dat professionele groei in de zorgsector niet beperkt mag blijven tot verplichte bijscholing. Onze opleidingen combineren wetenschappelijke onderbouwing met praktijkervaring.', 'stridence'); ?></p>
                    <p><?php esc_html_e('Als onafhankelijk opleidingscentrum garanderen wij dat elke zorgprofessional toegang heeft tot de tools, begeleiding en erkenning die nodig zijn om het verschil te maken.', 'stridence'); ?></p>
                </div>
            </div>
            <div class="relative">
                <div class="aspect-[4/5] bg-surface-container-highest rounded-xl overflow-hidden transform rotate-2 flex items-center justify-center">
                    <?php echo stridence_icon('heart', 'w-20 h-20 text-text-muted/30'); ?>
                </div>
                <div class="absolute -bottom-8 -left-8 bg-surface-card p-7 rounded-xl shadow-sm max-w-xs transform -rotate-3">
                    <p class="font-serif italic text-xl text-primary leading-snug">
                        <?php esc_html_e('"Zorg voor anderen begint met investeren in jezelf."', 'stridence'); ?>
                    </p>
                    <p class="mt-3 text-[11px] font-label font-bold uppercase tracking-widest text-text-muted">
                        — Dr. Els Van den Broeck
                    </p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Testimonial -->
<section class="py-24 lg:py-32 px-6 text-center max-w-4xl mx-auto">
    <svg class="w-14 h-14 mx-auto mb-8 text-primary-light" fill="currentColor" viewBox="0 0 24 24"><path d="M14.017 21v-7.391c0-5.704 3.731-9.57 8.983-10.609l.995 2.151c-2.432.917-3.995 3.638-3.995 5.849h4v10H14.017zM0 21v-7.391c0-5.704 3.731-9.57 8.983-10.609l.995 2.151C7.546 6.068 5.983 8.789 5.983 11H10v10H0z"/></svg>
    <blockquote class="font-serif text-3xl lg:text-4xl font-light leading-snug mb-12">
        <?php echo wp_kses(
            __('"De opleiding palliatieve zorg heeft mijn hele aanpak veranderd. Ik voelde me voor het eerst echt <em class="italic text-primary">voorbereid</em> op de moeilijkste gesprekken met families."', 'stridence'),
            ['em' => ['class' => []]]
        ); ?>
    </blockquote>
    <div class="flex flex-col items-center">
        <div class="w-14 h-14 rounded-full bg-surface-container-highest mb-3 flex items-center justify-center">
            <?php echo stridence_icon('user', 'w-6 h-6 text-text-muted'); ?>
        </div>
        <p class="font-bold"><?php esc_html_e('Sarah Janssens', 'stridence'); ?></p>
        <p class="text-text-muted text-sm"><?php esc_html_e('Verpleegkundige & Alumna 2024', 'stridence'); ?></p>
    </div>
</section>

<!-- CTA Section -->
<section class="py-24 lg:py-32 px-6">
    <div class="max-w-2xl mx-auto text-center">
        <h2 class="font-serif text-5xl lg:text-6xl font-light mb-8"><?php esc_html_e('Klaar om te starten?', 'stridence'); ?></h2>
        <p class="text-lg text-text-muted mb-10 leading-relaxed">
            <?php esc_html_e('Ontdek ons aanbod en schrijf je vandaag nog in. Versterk je vaardigheden met opleidingen die ertoe doen.', 'stridence'); ?>
        </p>
        <div class="flex flex-col sm:flex-row justify-center gap-4">
            <a href="<?php echo esc_url(home_url('/klassikaal/')); ?>" class="btn-primary btn-lg shadow-lg">
                <?php esc_html_e('Bekijk alle opleidingen', 'stridence'); ?>
            </a>
            <a href="<?php echo esc_url(home_url('/contact/')); ?>" class="btn-secondary btn-lg bg-surface-container-high">
                <?php esc_html_e('Neem contact op', 'stridence'); ?>
            </a>
        </div>
        <p class="mt-10 font-serif italic text-text-muted/60 text-lg">
            <?php esc_html_e('Een oase van groei voor zorgprofessionals.', 'stridence'); ?>
        </p>
    </div>
</section>

<?php
get_footer();
```

- [ ] **Step 2: Verify homepage loads**

Run: `ddev launch`
Expected: Editorial homepage with hero, learning mode cards, course grid, mission section, testimonial, and CTA. All in Dutch. No console errors.

- [ ] **Step 3: Commit**

```bash
git add web/app/themes/stridence/front-page.php
git commit -m "feat: editorial homepage with hero, mission, testimonial sections"
```

---

## Task 10: Client Example Token Docs

**Files:**
- Modify: `web/app/mu-plugins/stride-client-example/assets/client.css`

- [ ] **Step 1: Update the AVAILABLE TOKENS comment block**

Add the new editorial tokens to the comment block at the top of the file. After the existing `Borders:` section, add:

```css
 * Editorial:
 *   --color-tertiary, --color-tertiary-light
 *   --color-secondary-container
 *   --color-surface-container, --color-surface-container-high, --color-surface-container-highest
 *   --font-serif
 *
 * Badges (per-variant bg/text):
 *   --color-badge-open-bg, --color-badge-open-text
 *   --color-badge-few-bg, --color-badge-few-text
 *   --color-badge-full-bg, --color-badge-full-text
 *   --color-badge-cancelled-bg, --color-badge-cancelled-text
 *   --color-badge-online-bg, --color-badge-online-text
 *   --color-badge-free-bg, --color-badge-free-text
```

- [ ] **Step 2: Commit**

```bash
git add web/app/mu-plugins/stride-client-example/assets/client.css
git commit -m "docs: update client example with editorial token inventory"
```

---

## Task 11: Build + Visual Verification

- [ ] **Step 1: Full production build**

```bash
cd web/app/themes/stridence && npm run build
```

Expected: Build succeeds with no errors.

- [ ] **Step 2: Flush caches**

```bash
ddev exec wp cache flush
```

- [ ] **Step 3: Visual smoke test**

Open the following pages and verify the editorial design applies:

| Page | URL | Check |
|------|-----|-------|
| Homepage | `https://stride.ddev.site/` | Editorial hero, serif headlines, warm palette, blur blobs, course cards |
| Course catalog | `https://stride.ddev.site/klassikaal/` | Warm surface, borderless cards, teal badges, rounded buttons |
| Course detail | Click any course | Glass header visible, warm palette, serif headings in tabs |
| Dashboard | `https://stride.ddev.site/mijn-account/` | Fonts swapped, subtle teal sidebar active, no side-border on hero card |
| Enrollment form | Click "Inschrijven" on any edition | Warm inputs, rounded primary button |

- [ ] **Step 4: Mobile check**

Resize browser to 375px width and check:
- Homepage hero text is readable
- Mobile menu opens (solid background, not glass)
- Dashboard bottom nav uses correct colors
- Course cards stack properly

- [ ] **Step 5: Commit build artifacts if needed**

```bash
cd /home/ntdst/Sites/stride
git add web/app/themes/stridence/dist/
git commit -m "chore: rebuild theme assets with editorial design"
```

---

## Verification Stages (MANDATORY)

> Run AFTER all implementation tasks. NOT done until all stages pass.

### Stage V1: Build Verification

```bash
cd web/app/themes/stridence && npm run build
```

Expected: Zero errors. All CSS compiles, all Tailwind classes resolve.

### Stage V2: Visual Regression — Key Pages

```markdown
## Visual Smoke Test

- [ ] Visit: https://stride.ddev.site/
      Expected: Editorial hero, serif headlines, warm paper surface, glass nav, blur blobs
- [ ] Visit: https://stride.ddev.site/klassikaal/
      Expected: Warm palette, borderless course cards, teal primary buttons (rounded-full)
- [ ] Visit: https://stride.ddev.site/online/
      Expected: Same editorial styling, online badge in teal (not indigo)
- [ ] Click: Any course → detail page
      Expected: Glass header, warm surface, serif section headings
- [ ] Visit: https://stride.ddev.site/mijn-account/ (logged in as seed_admin)
      Expected: Plus Jakarta Sans body font, subtle teal sidebar active state, no border-left on hero card
- [ ] Visit: https://stride.ddev.site/mijn-account/?tab=profiel
      Expected: Form inputs render with warm palette
- [ ] Action: Resize to 375px width on homepage
      Expected: Responsive hero, stacked cards, readable text
- [ ] Action: Open mobile menu
      Expected: Solid background (not glass), no hr dividers, spacing between sections
```

### Stage V3: Token Override Test

```bash
# Temporarily uncomment primary color override in client.css to verify swappability
ddev exec wp eval "echo 'Token system check';"
```

Verify that changing `--color-primary` in `stride-client-example/assets/client.css` changes the primary color across all pages.

### Stage V4: No Console Errors

Open browser DevTools console on each page. Expected: Zero JavaScript errors, zero CSS warnings about missing custom properties.
