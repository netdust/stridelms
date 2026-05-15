# stride-client-kindred Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build `web/app/mu-plugins/stride-client-kindred/` — a Stride client mu-plugin that re-skins the Stridence theme into the Kindred HR brand (moss + stone palette, Geist + Instrument Serif typography), modeled exactly on `stride-client-safeandsound/`.

**Architecture:** Single mu-plugin, 11 files. PHP bootstrap registers template overrides + font URL filter + block patterns. `client.css` maps brand-board tokens into Stridence vocabulary and re-skins component classes. `front-page.php` renders an editorial homepage (hero + pillars + WP_Query of editions + featured course + quote + closer). 5 block patterns provide reusable copy/structure scaffolds.

**Tech Stack:** PHP 8.1+, WordPress (Bedrock), Stridence theme, no build step. Brand assets sourced from `/tmp/stridelms-brand/` (extracted from `~/Downloads/stridelms brand.zip`). Reference: `web/app/mu-plugins/stride-client-safeandsound/`.

**Spec:** `docs/superpowers/specs/2026-05-15-stride-client-kindred-design.md`

---

## File Structure

```
web/app/mu-plugins/stride-client-kindred/
├── stride-client-kindred.php          PHP bootstrap (Task 1)
├── IDENTITY.md                         Filled-in CLIENT-IDENTITY-TEMPLATE (Task 9)
├── assets/
│   ├── client.css                      Tokens + component re-skin (Tasks 2-3)
│   └── logo.svg                        Lifted from brand zip (Task 4)
├── templates/
│   ├── front-page.php                  Homepage (Tasks 5-6)
│   └── page-stub.php                   Long-form narrow template (Task 7)
└── patterns/
    ├── about.php                       (Task 8)
    ├── contact.php                     (Task 8)
    ├── faq.php                         (Task 8)
    ├── agenda.php                      (Task 8)
    └── terms.php                       (Task 8)
```

## Testing Strategy

This mu-plugin has no PHP business logic — it's branding (CSS) and static markup. PHPUnit
tests are not appropriate. Verification is **visual + curl-based smoke checks** at each
task boundary:

1. **PHP linter** after every PHP file write: `ddev exec php -l <file>`
2. **Smoke load** after task 1: visit `https://stride.ddev.site` returns 200, no PHP errors in logs
3. **Visual QA** in browser after tasks 3, 6, 8: confirm Kindred palette/fonts visible
4. **Pattern visibility check** after task 8: WP admin → Site Editor → Patterns → "Kindred HR" category lists 5 patterns
5. **Final acceptance walkthrough** in task 10

**Pre-flight (before Task 1):** disable `stride-client-safeandsound` so only Kindred runs.
Two clients active would compete on `template_include`. Rename safeandsound's plugin file
to `.off` and restore it at the end of task 10 (the user chooses which to keep active).

---

## Task 1: Scaffold directory + PHP bootstrap

**Files:**
- Create: `web/app/mu-plugins/stride-client-kindred/stride-client-kindred.php`
- Create: `web/app/mu-plugins/stride-client-kindred/assets/` (directory)
- Create: `web/app/mu-plugins/stride-client-kindred/templates/` (directory)
- Create: `web/app/mu-plugins/stride-client-kindred/patterns/` (directory)
- Rename: `web/app/mu-plugins/stride-client-safeandsound/stride-client-safeandsound.php` → `.off` (temporary)

- [ ] **Step 1: Disable safeandsound (so Kindred runs alone)**

Run:
```bash
mv /home/ntdst/Sites/stride/web/app/mu-plugins/stride-client-safeandsound/stride-client-safeandsound.php \
   /home/ntdst/Sites/stride/web/app/mu-plugins/stride-client-safeandsound/stride-client-safeandsound.php.off
```

Expected: no output, exit 0.

- [ ] **Step 2: Create directory skeleton**

Run:
```bash
mkdir -p /home/ntdst/Sites/stride/web/app/mu-plugins/stride-client-kindred/{assets,templates,patterns}
```

Expected: no output.

Verify:
```bash
ls /home/ntdst/Sites/stride/web/app/mu-plugins/stride-client-kindred/
```
Expected output: `assets  patterns  templates`

- [ ] **Step 3: Write the plugin bootstrap file**

Create `web/app/mu-plugins/stride-client-kindred/stride-client-kindred.php` with:

```php
<?php
/**
 * Plugin Name: Stride Client — Kindred HR
 * Description: Kindred HR training & development. Moss + cool stone palette, Geist + Instrument Serif + Geist Mono + Fraunces, editorial / structural calm.
 * Version: 1.0.0
 * Author: Netdust
 *
 * @package stride-client-kindred
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

final class StrideClientKindred
{
    private string $dir;
    private string $url;

    public function __construct()
    {
        $this->dir = __DIR__;
        $this->url = plugins_url('', __FILE__);
        $this->init();
    }

    private function init(): void
    {
        if (class_exists('\NTDST_Template_Loader')) {
            \NTDST_Template_Loader::addPath($this->dir . '/templates');
        }

        add_filter('template_include', [$this, 'overridePageTemplate'], 20);
        add_action('wp_enqueue_scripts', [$this, 'enqueueStyles'], 100);
        add_filter('stridence_font_url', [$this, 'overrideFontUrl']);
        add_action('init', [$this, 'registerPatterns']);
        add_filter('theme_page_templates', [$this, 'registerPageTemplates']);
        add_filter('template_include', [$this, 'resolvePageTemplate'], 25);
    }

    public function overridePageTemplate(string $template): string
    {
        if (is_front_page()) {
            $override = $this->dir . '/templates/front-page.php';
            if (file_exists($override)) {
                return $override;
            }
        }
        return $template;
    }

    public function enqueueStyles(): void
    {
        $cssFile = $this->dir . '/assets/client.css';
        if (!file_exists($cssFile)) {
            return;
        }

        wp_enqueue_style(
            'stride-client',
            $this->url . '/assets/client.css',
            [],
            (string) filemtime($cssFile)
        );
    }

    public function registerPatterns(): void
    {
        if (!function_exists('register_block_pattern_category') || !function_exists('register_block_pattern')) {
            return;
        }

        register_block_pattern_category('kindred', [
            'label'       => __('Kindred HR', 'stridence'),
            'description' => __('Editorial patterns for the Kindred HR brand identity.', 'stridence'),
        ]);

        $dir = $this->dir . '/patterns';
        if (!is_dir($dir)) {
            return;
        }

        foreach (glob($dir . '/*.php') as $file) {
            $headers = get_file_data($file, [
                'title'         => 'Title',
                'slug'          => 'Slug',
                'description'   => 'Description',
                'categories'    => 'Categories',
                'keywords'      => 'Keywords',
                'viewportWidth' => 'Viewport Width',
            ]);

            if (empty($headers['slug']) || empty($headers['title'])) {
                continue;
            }

            ob_start();
            include $file;
            $content = (string) ob_get_clean();

            register_block_pattern($headers['slug'], [
                'title'         => $headers['title'],
                'description'   => $headers['description'] ?: '',
                'categories'    => array_filter(array_map('trim', explode(',', $headers['categories'] ?: 'kindred'))),
                'keywords'      => array_filter(array_map('trim', explode(',', $headers['keywords'] ?: ''))),
                'viewportWidth' => (int) ($headers['viewportWidth'] ?: 1280),
                'content'       => $content,
            ]);
        }
    }

    public function registerPageTemplates(array $templates): array
    {
        $templates['kindred-page-stub.php'] = __('Kindred — Long-form page', 'stridence');
        return $templates;
    }

    public function resolvePageTemplate(string $template): string
    {
        if (is_page()) {
            $assigned = (string) get_page_template_slug();
            if ($assigned === 'kindred-page-stub.php') {
                $override = $this->dir . '/templates/page-stub.php';
                if (file_exists($override)) {
                    return $override;
                }
            }
        }
        return $template;
    }

    public function overrideFontUrl(string $url): string
    {
        return 'https://fonts.googleapis.com/css2'
            . '?family=Geist:wght@300..700'
            . '&family=Geist+Mono:wght@400..600'
            . '&family=Instrument+Serif:ital@0;1'
            . '&family=Fraunces:opsz,wght@9..144,300..700'
            . '&display=swap';
    }
}

new StrideClientKindred();
```

- [ ] **Step 4: Lint the PHP file**

Run: `ddev exec php -l /var/www/html/web/app/mu-plugins/stride-client-kindred/stride-client-kindred.php`
Expected: `No syntax errors detected in /var/www/html/web/app/mu-plugins/stride-client-kindred/stride-client-kindred.php`

- [ ] **Step 5: Smoke-test the site loads**

Run: `curl -sk -o /dev/null -w "%{http_code}\n" https://stride.ddev.site/`
Expected: `200`

If non-200: check `ddev logs` for PHP fatals. Most likely cause: typo in class declaration or missing `defined('ABSPATH')`.

- [ ] **Step 6: Verify Google Fonts URL filter is wired**

Run:
```bash
curl -sk https://stride.ddev.site/ | grep -o 'fonts.googleapis.com[^"'"'"']*' | head -1
```
Expected: a URL containing `family=Geist` and `family=Instrument+Serif`.

If empty: the theme isn't calling `apply_filters('stridence_font_url', …)`. Inspect `web/app/themes/stridence/functions.php` for the filter name — adjust the filter handle if the theme uses a different name. Note the actual name in the IDENTITY.md §8 Google Fonts URL section.

- [ ] **Step 7: Commit**

```bash
cd /home/ntdst/Sites/stride
git add web/app/mu-plugins/stride-client-kindred/stride-client-kindred.php \
        web/app/mu-plugins/stride-client-safeandsound/
git commit -m "feat(kindred): scaffold mu-plugin bootstrap + temporarily disable safeandsound"
```

Expected: 1 file added, 1 file renamed (the `.off` rename is a status change Git sees as a rename).

---

## Task 2: client.css tokens (color, typography, shape, motion)

**Files:**
- Create: `web/app/mu-plugins/stride-client-kindred/assets/client.css`

- [ ] **Step 1: Write the token block**

Create `web/app/mu-plugins/stride-client-kindred/assets/client.css` starting with the `:root` token block. Stridence uses RGB triplets (not hex) so border/text utilities can use `rgb(var(--token) / 0.5)` syntax.

```css
/**
 * Kindred HR — Design Token Overrides
 *
 * Editorial "cool stone + moss" design system.
 * Moss green on warm stone paper. Ink anchors. Instrument Serif for editorial
 * fragments only. Geist for everything else.
 *
 * Source: brand-board.css from stridelms brand.zip (2026-05).
 *
 * Design principles:
 * - Restraint over decoration — every element earns its presence
 * - Editorial typography is the visual system — type does the work
 * - Tonal layering, no hard borders — surface shifts instead of 1px lines
 */

:root {
  /* ── Brand Colors — Moss + Stone ── */
  --color-primary: 31 91 61;              /* #1F5B3D moss */
  --color-primary-hover: 15 58 37;        /* #0F3A25 deep moss */
  --color-primary-subtle: 188 212 196;    /* #BCD4C4 soft moss tint */
  --color-primary-light: 188 212 196;     /* #BCD4C4 (same as subtle, single tint) */
  --color-primary-dark: 15 58 37;         /* #0F3A25 deep moss */
  --color-accent: 31 91 61;               /* moss as single accent — no second accent */
  --color-accent-light: 188 212 196;

  /* ── Neutrals — Cool Stone Paper ── */
  --color-surface: 241 242 239;           /* #F1F2EF stone base */
  --color-surface-alt: 232 234 229;       /* #E8EAE5 deeper stone */
  --color-surface-card: 249 250 247;      /* #F9FAF7 lifted card */
  --color-border: 225 227 220;            /* #E1E3DC soft line */
  --color-border-strong: 212 215 208;     /* #D4D7D0 stronger line */
  --color-text: 17 19 18;                 /* #111312 ink */
  --color-text-muted: 108 112 107;        /* #6C706B muted stone */
  --color-text-inverse: 249 250 247;

  /* ── 5-tier surface ladder ── */
  --color-surface-container: 232 234 229;        /* #E8EAE5 emphasised panels */
  --color-surface-container-high: 225 227 220;   /* #E1E3DC modals / dropdowns */
  --color-surface-container-highest: 212 215 208;/* #D4D7D0 overlays */
  --color-secondary-container: 188 212 196;     /* moss tint */
  --color-tertiary: 17 19 18;                   /* ink as tertiary anchor */
  --color-tertiary-light: 54 58 54;             /* ink-soft #363A36 */

  /* ── Status Colors ── */
  --color-success: 31 91 61;              /* moss for ok */
  --color-warning: 138 106 28;            /* #8A6A1C umber */
  --color-error: 138 42 28;               /* #8A2A1C oxblood */
  --color-info: 31 91 61;

  /* ── Badge Colors — restrained, all on stone surface ── */
  --color-badge-open-bg: 188 212 196;          /* moss tint */
  --color-badge-open-text: 15 58 37;
  --color-badge-few-bg: 232 215 178;           /* warm sand */
  --color-badge-few-text: 138 106 28;
  --color-badge-full-bg: 212 215 208;          /* stone — neutral "closed" */
  --color-badge-full-text: 54 58 54;
  --color-badge-cancelled-bg: 225 227 220;
  --color-badge-cancelled-text: 108 112 107;
  --color-badge-online-bg: 212 220 225;        /* cool grey-blue */
  --color-badge-online-text: 54 58 54;
  --color-badge-free-bg: 188 212 196;
  --color-badge-free-text: 15 58 37;

  /* ── Typography ── */
  --font-sans: 'Geist', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
  --font-heading: 'Geist', system-ui, sans-serif;
  --font-serif: 'Instrument Serif', Georgia, 'Times New Roman', serif;
  --font-label: 'Geist Mono', ui-monospace, 'SF Mono', Menlo, monospace;
  --font-display-alt: 'Fraunces', 'Instrument Serif', Georgia, serif;

  /* ── Spacing ── */
  --space-section: 6rem;
  --space-block: 3.5rem;
  --space-element: 1.5rem;

  /* ── Border Radius — restrained, no pills ── */
  --radius-sm: 4px;
  --radius-md: 8px;
  --radius-lg: 14px;
  --radius-xl: 22px;

  /* ── Shadows — minimal, ink-tinted ── */
  --shadow-xs: 0 1px 2px rgba(17, 19, 18, 0.04);
  --shadow-sm: 0 2px 6px rgba(17, 19, 18, 0.05);
  --shadow-md: 0 8px 20px -6px rgba(17, 19, 18, 0.08);
  --shadow-lg: 0 20px 40px -12px rgba(17, 19, 18, 0.10);
  --shadow-overlay: 0 24px 50px -14px rgba(17, 19, 18, 0.14);
  --shadow-card: var(--shadow-xs);
  --shadow-elevated: var(--shadow-sm);

  /* ── Motion — slow, premium, never bouncy ── */
  --ease-out: cubic-bezier(0.22, 0.61, 0.36, 1);
  --duration-fast: 180ms;
  --duration-normal: 320ms;

  /* ── Brand utilities ── */
  --kindred-page-max: 1320px;
  --kindred-gutter: clamp(20px, 4vw, 56px);
}
```

- [ ] **Step 2: Commit the token foundation**

```bash
cd /home/ntdst/Sites/stride
git add web/app/mu-plugins/stride-client-kindred/assets/client.css
git commit -m "feat(kindred): client.css token foundation"
```

---

## Task 3: client.css component re-skin

**Files:**
- Modify: `web/app/mu-plugins/stride-client-kindred/assets/client.css` (append)

- [ ] **Step 1: Append the component override block**

Append to `client.css`:

```css

/* ═══════════════════════════════════════════ Component overrides ═══════════════════════════════════════════ */

/* ── Body baseline ── */
body {
  font-family: var(--font-sans);
  font-feature-settings: "ss01", "cv11";
  background: rgb(var(--color-surface));
  color: rgb(var(--color-text));
  -webkit-font-smoothing: antialiased;
  text-rendering: optimizeLegibility;
  letter-spacing: -0.005em;
}

/* ── Selection ── */
::selection {
  background: rgb(var(--color-primary));
  color: rgb(var(--color-text-inverse));
}

/* ── Links ── */
a {
  color: rgb(var(--color-primary));
  text-decoration: underline;
  text-decoration-thickness: 1px;
  text-underline-offset: 3px;
  transition: color var(--duration-fast) var(--ease-out);
}
a:hover { color: rgb(var(--color-primary-hover)); }

/* ── Buttons ── */
.btn-primary,
.btn-secondary,
.btn-accent,
.btn-outline-dark,
.btn-outline-light,
.btn-ghost,
.btn-danger {
  border-radius: var(--radius-md);
  font-family: var(--font-sans);
  font-weight: 500;
  letter-spacing: -0.005em;
  transition: background var(--duration-fast) var(--ease-out),
              color var(--duration-fast) var(--ease-out),
              border-color var(--duration-fast) var(--ease-out);
}

.btn-primary {
  background: rgb(var(--color-primary));
  color: rgb(var(--color-text-inverse));
  border: 1px solid rgb(var(--color-primary));
}
.btn-primary:hover {
  background: rgb(var(--color-primary-hover));
  border-color: rgb(var(--color-primary-hover));
}

.btn-secondary {
  background: rgb(var(--color-surface-card));
  color: rgb(var(--color-text));
  border: 1px solid rgb(var(--color-border-strong));
}
.btn-secondary:hover {
  background: rgb(var(--color-surface-alt));
}

.btn-outline-dark {
  background: transparent;
  color: rgb(var(--color-text));
  border: 1px solid rgb(var(--color-text));
}
.btn-outline-dark:hover {
  background: rgb(var(--color-text));
  color: rgb(var(--color-text-inverse));
}

.btn-ghost {
  background: transparent;
  color: rgb(var(--color-text));
  border: 1px solid transparent;
}
.btn-ghost:hover {
  background: rgb(var(--color-surface-alt));
}

.btn-sm { font-size: 13px; padding: 0.5rem 0.875rem; }
.btn-lg { font-size: 16px; padding: 0.9rem 1.5rem; }

/* ── Cards ── */
.card,
.card-bordered,
.card-interactive,
.dash-card,
.dash-card-hero,
.dash-card-interactive,
.dash-panel {
  background: rgb(var(--color-surface-card));
  border: 1px solid rgb(var(--color-border));
  border-radius: var(--radius-lg);
  box-shadow: var(--shadow-card);
}
.card-interactive:hover,
.dash-card-interactive:hover {
  background: rgb(var(--color-surface-card));
  border-color: rgb(var(--color-border-strong));
  box-shadow: var(--shadow-elevated);
}

/* ── Glass nav (header) ── */
.glass-nav {
  background: rgb(var(--color-surface) / 0.85);
  backdrop-filter: saturate(180%) blur(12px);
  -webkit-backdrop-filter: saturate(180%) blur(12px);
  border-bottom: 1px solid rgb(var(--color-border));
}

/* ── Dark section ── */
.dark-section {
  background: rgb(var(--color-text));
  color: rgb(var(--color-text-inverse));
}
.dark-section a { color: rgb(var(--color-primary-subtle)); }

/* ── Hero watermark ── */
.hero-watermark {
  color: rgb(var(--color-primary) / 0.06);
  font-family: var(--font-heading);
}

/* ── Tags / pills ── */
.tag-pill {
  background: rgb(var(--color-primary-subtle));
  color: rgb(var(--color-primary-dark));
  border-radius: 999px;
  padding: 0.25rem 0.75rem;
  font-family: var(--font-label);
  font-size: 11px;
  letter-spacing: 0.06em;
  text-transform: uppercase;
}

/* ── Status badges ── */
.badge-open    { background: rgb(var(--color-badge-open-bg));    color: rgb(var(--color-badge-open-text)); }
.badge-few     { background: rgb(var(--color-badge-few-bg));     color: rgb(var(--color-badge-few-text)); }
.badge-full    { background: rgb(var(--color-badge-full-bg));    color: rgb(var(--color-badge-full-text)); }
.badge-cancelled { background: rgb(var(--color-badge-cancelled-bg)); color: rgb(var(--color-badge-cancelled-text)); }
.badge-online  { background: rgb(var(--color-badge-online-bg));  color: rgb(var(--color-badge-online-text)); }
.badge-free    { background: rgb(var(--color-badge-free-bg));    color: rgb(var(--color-badge-free-text)); }

/* ── Prose ── */
.prose-stride {
  color: rgb(var(--color-text));
  font-family: var(--font-sans);
  line-height: 1.65;
}
.prose-stride h1,
.prose-stride h2,
.prose-stride h3,
.prose-stride h4 {
  font-family: var(--font-heading);
  font-weight: 500;
  letter-spacing: -0.02em;
  line-height: 1.15;
}
.prose-stride em { font-family: var(--font-serif); font-style: italic; }
.prose-stride code { font-family: var(--font-label); font-size: 0.9em; }

/* ── Forms ── */
input[type="text"],
input[type="email"],
input[type="tel"],
input[type="url"],
input[type="password"],
input[type="number"],
input[type="search"],
input[type="date"],
select,
textarea {
  background: rgb(var(--color-surface-card));
  border: 1px solid rgb(var(--color-border-strong));
  border-radius: var(--radius-md);
  color: rgb(var(--color-text));
  font-family: var(--font-sans);
  font-size: 15px;
  padding: 0.65rem 0.85rem;
  transition: border-color var(--duration-fast) var(--ease-out),
              box-shadow var(--duration-fast) var(--ease-out);
}
input:focus,
select:focus,
textarea:focus {
  outline: none;
  border-color: rgb(var(--color-primary));
  box-shadow: 0 0 0 3px rgb(var(--color-primary-subtle) / 0.5);
}

/* ── Footer ── */
footer {
  background: rgb(var(--color-surface));
  border-top: 1px solid rgb(var(--color-border));
  color: rgb(var(--color-text-muted));
  font-family: var(--font-label);
  font-size: 12px;
  letter-spacing: 0.04em;
}
footer a { color: rgb(var(--color-text)); }

/* ── Brand utilities (front-page) ── */
.t-fraunces { font-family: var(--font-display-alt); }
.t-serif    { font-family: var(--font-serif); font-style: italic; letter-spacing: -0.01em; }
.t-mono     { font-family: var(--font-label); letter-spacing: 0; }
.t-eyebrow  {
  font-family: var(--font-label);
  font-size: 11px;
  letter-spacing: 0.12em;
  text-transform: uppercase;
  color: rgb(var(--color-text-muted));
  font-weight: 500;
}

/* ── Reduced motion ── */
@media (prefers-reduced-motion: reduce) {
  *, *::before, *::after {
    transition-duration: 0.01ms !important;
    animation-duration: 0.01ms !important;
    animation-iteration-count: 1 !important;
  }
}
```

- [ ] **Step 2: Visual smoke test in browser**

Open `https://stride.ddev.site/` in a browser. Hard-refresh (Cmd/Ctrl+Shift+R).
Expected: moss-green primary buttons/links visible, stone-coloured page background, Geist font in body.

If colors are not visible: open DevTools, inspect `<body>`, confirm `client.css` is loaded
(network tab shows 200 for `/app/mu-plugins/stride-client-kindred/assets/client.css`). If
not loaded: `enqueueStyles()` not firing — check that the file path matches and `dirname`
resolves correctly.

- [ ] **Step 3: Commit**

```bash
cd /home/ntdst/Sites/stride
git add web/app/mu-plugins/stride-client-kindred/assets/client.css
git commit -m "feat(kindred): client.css component re-skin"
```

---

## Task 4: Logo asset

**Files:**
- Create: `web/app/mu-plugins/stride-client-kindred/assets/logo.svg`

- [ ] **Step 1: Copy logo from brand zip**

Run:
```bash
cp /tmp/stridelms-brand/uploads/logo.svg \
   /home/ntdst/Sites/stride/web/app/mu-plugins/stride-client-kindred/assets/logo.svg
```

Expected: no output, exit 0.

- [ ] **Step 2: Verify it's a valid SVG**

Run:
```bash
head -2 /home/ntdst/Sites/stride/web/app/mu-plugins/stride-client-kindred/assets/logo.svg
```
Expected: starts with `<?xml version="1.0"` and `<svg`.

If `/tmp/stridelms-brand/` no longer exists (filesystem cleaned), re-extract:
```bash
cd /tmp && rm -rf stridelms-brand && mkdir stridelms-brand && cd stridelms-brand && \
  unzip -q "/mnt/c/Users/stefa/Downloads/stridelms brand.zip"
```

- [ ] **Step 3: Tint the logo (set fills to currentColor)**

Open the logo file. The brand-board logo uses CSS classes `cls-2` (background rect) and
`cls-1` (path fills). For inline-SVG usage in templates we want the logo to inherit text
color from its parent. Edit the file to:
- Add a `style` element inside `<defs>` setting `.cls-2 { fill: currentColor; }` and `.cls-1 { fill: rgb(var(--color-surface)); }`

Replace the empty `<defs></defs>` block with:

```xml
<defs>
  <style>
    .cls-2 { fill: currentColor; }
    .cls-1 { fill: #f9faf7; }
  </style>
</defs>
```

Note: SVG `<style>` doesn't see CSS variables from the host page, so we hard-code the
stone-card color for the foreground paths. The background rect stays `currentColor` so the
logo recolors when placed on dark/light surfaces.

- [ ] **Step 4: Commit**

```bash
cd /home/ntdst/Sites/stride
git add web/app/mu-plugins/stride-client-kindred/assets/logo.svg
git commit -m "feat(kindred): logo svg from brand zip + currentColor tinting"
```

---

## Task 5: front-page.php — hero + pillars (sections 1-3)

**Files:**
- Create: `web/app/mu-plugins/stride-client-kindred/templates/front-page.php`

- [ ] **Step 1: Create the file with header + hero**

Create `web/app/mu-plugins/stride-client-kindred/templates/front-page.php`:

```php
<?php
/**
 * Homepage Template — Kindred HR
 *
 * Editorial cover composition. Moss + stone, Geist + Instrument Serif italic accents.
 * Five-pillar grid, server-rendered editions, restrained closer.
 *
 * @package stride-client-kindred
 */

get_header();
?>

<!-- ═══════════════════════════════════════════ COVER -->
<section class="kindred-cover" style="
    max-width: var(--kindred-page-max);
    margin: 0 auto;
    padding: clamp(48px, 8vw, 110px) var(--kindred-gutter);
    border-bottom: 1px solid rgb(var(--color-border));
">
    <div class="kindred-cover__grid" style="
        display: grid;
        grid-template-columns: 130px 1fr auto;
        gap: 32px;
        align-items: end;
        margin-bottom: 64px;
    ">
        <span class="t-mono t-eyebrow"><?php esc_html_e('KINDRED HR', 'stridence'); ?></span>
        <h1 class="t-fraunces" style="
            font-weight: 400;
            font-size: clamp(44px, 7vw, 96px);
            line-height: 0.95;
            letter-spacing: -0.035em;
            margin: 0;
            max-width: 16ch;
            text-wrap: balance;
            color: rgb(var(--color-text));
        ">
            <?php echo wp_kses(
                __('Trainingen voor mensen die <em class="t-serif">werken met mensen.</em>', 'stridence'),
                ['em' => ['class' => []]]
            ); ?>
        </h1>
        <div class="t-mono" style="
            font-size: 11px;
            letter-spacing: 0.06em;
            color: rgb(var(--color-text-muted));
            text-align: right;
            line-height: 1.6;
        ">
            <?php esc_html_e('v2026.1', 'stridence'); ?><br>
            <?php esc_html_e('NL · BE', 'stridence'); ?>
        </div>
    </div>

    <div class="kindred-cover__intro" style="
        display: grid;
        grid-template-columns: 130px 1fr;
        gap: 32px;
        padding-top: 48px;
        border-top: 1px solid rgb(var(--color-border));
    ">
        <span class="t-mono t-eyebrow"><?php esc_html_e('01 / INTRO', 'stridence'); ?></span>
        <div>
            <p class="t-serif" style="
                font-size: clamp(24px, 2.2vw, 30px);
                line-height: 1.35;
                color: rgb(var(--color-text));
                margin: 0 0 16px;
                max-width: 38ch;
            ">
                <?php esc_html_e('Kindred helpt organisaties trainen, coachen en ontwikkelen — voor managers, teams en HR-professionals die met mensen werken.', 'stridence'); ?>
            </p>
            <p style="
                font-family: var(--font-sans);
                font-size: 17px;
                line-height: 1.55;
                color: rgb(var(--color-text-muted));
                margin: 0;
                max-width: 52ch;
            ">
                <?php esc_html_e('Geen losse workshops zonder context. Geen abstracte modellen. Praktische trainingen die in het werk landen, in tien hoofdstukken die jouw team al kent.', 'stridence'); ?>
            </p>
        </div>
    </div>
</section>
```

- [ ] **Step 2: Append pillars section**

Append to `front-page.php`:

```php
<!-- ═══════════════════════════════════════════ PILLARS -->
<section style="
    max-width: var(--kindred-page-max);
    margin: 0 auto;
    padding: clamp(48px, 8vw, 110px) var(--kindred-gutter);
">
    <div style="
        display: grid;
        grid-template-columns: 130px 1fr;
        gap: 32px;
        margin-bottom: 48px;
        align-items: baseline;
    ">
        <span class="t-mono t-eyebrow"><?php esc_html_e('02 / DOMEINEN', 'stridence'); ?></span>
        <h2 class="t-fraunces" style="
            font-weight: 500;
            font-size: clamp(28px, 3.5vw, 44px);
            letter-spacing: -0.02em;
            line-height: 1.05;
            margin: 0;
            max-width: 22ch;
        "><?php esc_html_e('Vijf domeinen waar onze trainingen verschil maken.', 'stridence'); ?></h2>
    </div>

    <div class="kindred-pillars" style="
        display: grid;
        grid-template-columns: repeat(5, 1fr);
        gap: 0;
        border-top: 1px solid rgb(var(--color-border));
    ">
        <?php
        $pillars = [
            ['01', __('Leiderschap', 'stridence'),  __('Coachend leiderschap, moeilijke gesprekken, situationeel sturen.', 'stridence')],
            ['02', __('Communicatie', 'stridence'), __('Feedback geven, conflictbemiddeling, verbindend overleggen.', 'stridence')],
            ['03', __('Welzijn', 'stridence'),      __('Veerkracht, werkdruk, stress-signalen herkennen.', 'stridence')],
            ['04', __('Coaching', 'stridence'),     __('Loopbaancoaching, intervisie, ontwikkelgesprekken.', 'stridence')],
            ['05', __('Compliance', 'stridence'),   __('GDPR, integriteit, grensoverschrijdend gedrag.', 'stridence')],
        ];
        foreach ($pillars as [$num, $label, $copy]) :
        ?>
        <div style="
            padding: 32px 24px;
            border-right: 1px solid rgb(var(--color-border));
            min-height: 200px;
            display: flex;
            flex-direction: column;
            gap: 12px;
        ">
            <span class="t-mono" style="
                font-size: 11px;
                letter-spacing: 0.12em;
                color: rgb(var(--color-text-muted));
            "><?php echo esc_html($num); ?></span>
            <h3 style="
                font-family: var(--font-heading);
                font-weight: 500;
                font-size: 19px;
                line-height: 1.2;
                letter-spacing: -0.015em;
                margin: 0;
                color: rgb(var(--color-text));
            "><?php echo esc_html($label); ?></h3>
            <p style="
                font-family: var(--font-sans);
                font-size: 14px;
                line-height: 1.5;
                color: rgb(var(--color-text-muted));
                margin: auto 0 0;
            "><?php echo esc_html($copy); ?></p>
        </div>
        <?php endforeach; ?>
    </div>
</section>
```

- [ ] **Step 3: Lint**

Run: `ddev exec php -l /var/www/html/web/app/mu-plugins/stride-client-kindred/templates/front-page.php`
Expected: `No syntax errors detected`.

- [ ] **Step 4: Commit**

```bash
cd /home/ntdst/Sites/stride
git add web/app/mu-plugins/stride-client-kindred/templates/front-page.php
git commit -m "feat(kindred): front-page hero + pillars sections"
```

---

## Task 6: front-page.php — editions, trajectory, quote, closer (sections 4-7)

**Files:**
- Modify: `web/app/mu-plugins/stride-client-kindred/templates/front-page.php` (append)

- [ ] **Step 1: Append upcoming editions section**

Append to `front-page.php` (before `get_footer()`):

```php
<!-- ═══════════════════════════════════════════ UPCOMING EDITIONS -->
<?php
$editions = new WP_Query([
    'post_type'      => 'vad_edition',
    'post_status'    => 'publish',
    'posts_per_page' => 6,
    'meta_query'     => [
        [
            'key'     => '_ntdst_status',
            'value'   => ['draft', 'completed', 'archived'],
            'compare' => 'NOT IN',
        ],
    ],
]);
?>
<section style="
    background: rgb(var(--color-surface-alt));
    padding: clamp(48px, 8vw, 110px) 0;
">
    <div style="
        max-width: var(--kindred-page-max);
        margin: 0 auto;
        padding: 0 var(--kindred-gutter);
    ">
        <div style="
            display: grid;
            grid-template-columns: 130px 1fr;
            gap: 32px;
            margin-bottom: 48px;
            align-items: baseline;
        ">
            <span class="t-mono t-eyebrow"><?php esc_html_e('03 / AGENDA', 'stridence'); ?></span>
            <h2 class="t-fraunces" style="
                font-weight: 500;
                font-size: clamp(28px, 3.5vw, 44px);
                letter-spacing: -0.02em;
                line-height: 1.05;
                margin: 0;
                max-width: 22ch;
            "><?php esc_html_e('Eerstvolgende trainingen.', 'stridence'); ?></h2>
        </div>

        <?php if ($editions->have_posts()) : ?>
        <div style="
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 24px;
        ">
            <?php while ($editions->have_posts()) : $editions->the_post(); ?>
            <a href="<?php the_permalink(); ?>" class="card card-interactive" style="
                display: block;
                padding: 24px;
                color: inherit;
                text-decoration: none;
            ">
                <span class="t-mono" style="
                    font-size: 11px;
                    letter-spacing: 0.1em;
                    color: rgb(var(--color-text-muted));
                    text-transform: uppercase;
                "><?php echo esc_html(get_post_meta(get_the_ID(), '_ntdst_start_date', true) ?: __('Binnenkort', 'stridence')); ?></span>
                <h3 style="
                    font-family: var(--font-heading);
                    font-weight: 500;
                    font-size: 20px;
                    line-height: 1.2;
                    letter-spacing: -0.015em;
                    margin: 12px 0 8px;
                    color: rgb(var(--color-text));
                "><?php the_title(); ?></h3>
                <p style="
                    font-family: var(--font-sans);
                    font-size: 14px;
                    line-height: 1.5;
                    color: rgb(var(--color-text-muted));
                    margin: 0;
                "><?php echo esc_html(wp_trim_words(get_the_excerpt(), 18)); ?></p>
            </a>
            <?php endwhile; wp_reset_postdata(); ?>
        </div>
        <?php else : ?>
        <p class="t-serif" style="
            font-size: 22px;
            color: rgb(var(--color-text-muted));
            max-width: 38ch;
        "><?php esc_html_e('Geen geplande trainingen op dit moment. Volg ons of meld je aan voor de nieuwsbrief.', 'stridence'); ?></p>
        <?php endif; ?>
    </div>
</section>
```

- [ ] **Step 2: Append featured trajectory section**

Append:

```php
<!-- ═══════════════════════════════════════════ FEATURED TRAJECTORY -->
<?php
$featured_courses = get_posts([
    'post_type'      => 'sfwd-courses',
    'posts_per_page' => 1,
    'orderby'        => 'rand',
]);
$featured = $featured_courses[0] ?? null;
?>
<?php if ($featured) : ?>
<section style="
    max-width: var(--kindred-page-max);
    margin: 0 auto;
    padding: clamp(48px, 8vw, 110px) var(--kindred-gutter);
">
    <div style="
        display: grid;
        grid-template-columns: 60% 40%;
        gap: 64px;
        align-items: center;
    " class="kindred-feature">
        <div style="
            aspect-ratio: 4/3;
            background: rgb(var(--color-primary-subtle));
            border-radius: var(--radius-xl);
            overflow: hidden;
        ">
            <?php if (has_post_thumbnail($featured->ID)) : ?>
                <?php echo get_the_post_thumbnail(
                    $featured->ID,
                    'large',
                    ['style' => 'width:100%;height:100%;object-fit:cover;display:block;']
                ); ?>
            <?php endif; ?>
        </div>
        <div>
            <span class="t-mono t-eyebrow"><?php esc_html_e('04 / UITGELICHT TRAJECT', 'stridence'); ?></span>
            <h2 class="t-fraunces" style="
                font-weight: 500;
                font-size: clamp(28px, 3vw, 40px);
                letter-spacing: -0.02em;
                line-height: 1.1;
                margin: 16px 0;
                color: rgb(var(--color-text));
            "><?php echo esc_html(get_the_title($featured->ID)); ?></h2>
            <p style="
                font-family: var(--font-sans);
                font-size: 16px;
                line-height: 1.6;
                color: rgb(var(--color-text-muted));
                margin: 0 0 24px;
            "><?php echo esc_html(wp_trim_words(get_the_excerpt($featured->ID), 32)); ?></p>
            <a href="<?php echo esc_url(get_permalink($featured->ID)); ?>" class="btn-primary btn-lg">
                <?php esc_html_e('Bekijk het traject', 'stridence'); ?>
            </a>
        </div>
    </div>
</section>
<?php endif; ?>
```

- [ ] **Step 3: Append quote section**

Append:

```php
<!-- ═══════════════════════════════════════════ QUOTE -->
<section style="
    background: rgb(var(--color-surface-alt));
    padding: clamp(80px, 12vw, 160px) var(--kindred-gutter);
    text-align: center;
">
    <div style="max-width: 56ch; margin: 0 auto;">
        <blockquote class="t-serif" style="
            font-size: clamp(24px, 3vw, 40px);
            line-height: 1.3;
            color: rgb(var(--color-text));
            margin: 0 0 24px;
            font-style: italic;
        ">
            <?php esc_html_e('"Geen frontale lessen. Geen abstracte modellen. Voor het eerst een traject dat midden in de praktijk landt."', 'stridence'); ?>
        </blockquote>
        <p class="t-mono" style="
            font-size: 12px;
            letter-spacing: 0.06em;
            color: rgb(var(--color-text-muted));
            margin: 0;
        "><?php esc_html_e('— L. JANSSENS · HR-DIRECTEUR · ZORGORGANISATIE', 'stridence'); ?></p>
    </div>
</section>
```

- [ ] **Step 4: Append closer section + footer call**

Append:

```php
<!-- ═══════════════════════════════════════════ CLOSER -->
<section style="
    max-width: var(--kindred-page-max);
    margin: 0 auto;
    padding: clamp(80px, 12vw, 160px) var(--kindred-gutter);
    text-align: center;
">
    <h2 class="t-fraunces" style="
        font-weight: 400;
        font-size: clamp(36px, 5vw, 72px);
        letter-spacing: -0.03em;
        line-height: 1.05;
        margin: 0 0 24px;
        max-width: 18ch;
        margin-left: auto;
        margin-right: auto;
        color: rgb(var(--color-text));
    "><?php esc_html_e('Klaar om te starten?', 'stridence'); ?></h2>
    <p style="
        font-family: var(--font-sans);
        font-size: 17px;
        line-height: 1.55;
        color: rgb(var(--color-text-muted));
        max-width: 44ch;
        margin: 0 auto 32px;
    "><?php esc_html_e('Bekijk de eerstvolgende trainingen en plan jouw eerste sessie in.', 'stridence'); ?></p>
    <a href="<?php echo esc_url(home_url('/vormingen/')); ?>" class="btn-primary btn-lg">
        <?php esc_html_e('Bekijk de vormingen', 'stridence'); ?>
    </a>
</section>

<style>
@media (max-width: 960px) {
    .kindred-pillars { grid-template-columns: 1fr 1fr !important; }
    .kindred-feature { grid-template-columns: 1fr !important; }
}
@media (max-width: 600px) {
    .kindred-pillars { grid-template-columns: 1fr !important; }
}
</style>

<?php
get_footer();
```

- [ ] **Step 5: Lint**

Run: `ddev exec php -l /var/www/html/web/app/mu-plugins/stride-client-kindred/templates/front-page.php`
Expected: `No syntax errors detected`.

- [ ] **Step 6: Visual smoke test**

Open `https://stride.ddev.site/` in a browser. Hard refresh.
Expected: 7 sections render (cover → pillars → editions → featured → quote → closer → footer). Cover headline is large editorial italic-blended. Pillars are 5 columns on desktop, stacked on mobile.

If only header+footer render: `template_include` filter isn't matching. Check `is_front_page()` returns true (WP admin → Settings → Reading: "Your homepage displays" → "Your latest posts" or a static page set). If the site has no front page configured, set Settings → Reading → "A static page" → pick or create one named "Home".

- [ ] **Step 7: Commit**

```bash
cd /home/ntdst/Sites/stride
git add web/app/mu-plugins/stride-client-kindred/templates/front-page.php
git commit -m "feat(kindred): front-page editions, featured, quote, closer sections"
```

---

## Task 7: page-stub.php long-form template

**Files:**
- Create: `web/app/mu-plugins/stride-client-kindred/templates/page-stub.php`

- [ ] **Step 1: Create the page-stub template**

Create `web/app/mu-plugins/stride-client-kindred/templates/page-stub.php`:

```php
<?php
/**
 * Template Name: Kindred — Long-form page
 *
 * Narrow editorial layout for stub pages (about, terms, privacy).
 *
 * @package stride-client-kindred
 */

get_header();
?>

<article class="prose-stride" style="
    max-width: 720px;
    margin: 0 auto;
    padding: clamp(56px, 9vw, 120px) var(--kindred-gutter);
">
    <header style="margin-bottom: 48px;">
        <span class="t-mono t-eyebrow"><?php esc_html_e('KINDRED HR', 'stridence'); ?></span>
        <h1 class="t-fraunces" style="
            font-weight: 400;
            font-size: clamp(40px, 5vw, 64px);
            line-height: 1.05;
            letter-spacing: -0.03em;
            margin: 16px 0 0;
            max-width: 18ch;
            color: rgb(var(--color-text));
        "><?php the_title(); ?></h1>
    </header>

    <?php
    if (have_posts()) :
        while (have_posts()) : the_post();
            the_content();
        endwhile;
    endif;
    ?>
</article>

<?php get_footer();
```

- [ ] **Step 2: Lint**

Run: `ddev exec php -l /var/www/html/web/app/mu-plugins/stride-client-kindred/templates/page-stub.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Verify template appears in WP admin**

In a browser, visit `https://stride.ddev.site/wp/wp-admin/edit.php?post_type=page`. Open any
page → Page Attributes → Template dropdown.
Expected: "Kindred — Long-form page" appears as an option.

- [ ] **Step 4: Commit**

```bash
cd /home/ntdst/Sites/stride
git add web/app/mu-plugins/stride-client-kindred/templates/page-stub.php
git commit -m "feat(kindred): page-stub long-form page template"
```

---

## Task 8: Block patterns (5 files)

**Files:**
- Create: `web/app/mu-plugins/stride-client-kindred/patterns/about.php`
- Create: `web/app/mu-plugins/stride-client-kindred/patterns/contact.php`
- Create: `web/app/mu-plugins/stride-client-kindred/patterns/faq.php`
- Create: `web/app/mu-plugins/stride-client-kindred/patterns/agenda.php`
- Create: `web/app/mu-plugins/stride-client-kindred/patterns/terms.php`

Each pattern is a flat PHP file with header docblock + raw block markup that's parsed by
`registerPatterns()`. Patterns use Gutenberg block comments (`<!-- wp:... /-->`) so they
drop into the block editor cleanly.

- [ ] **Step 1: Create `patterns/about.php`**

```php
<?php
/**
 * Title: Kindred — About
 * Slug: kindred/about
 * Description: Editorial about-page hero + two-column body.
 * Categories: kindred
 * Keywords: about, intro, kindred
 * Viewport Width: 1280
 */
?>
<!-- wp:group {"tagName":"section","style":{"spacing":{"padding":{"top":"clamp(5rem,9vw,8rem)","bottom":"clamp(3rem,5vw,4.5rem)","left":"clamp(1.5rem,5vw,5rem)","right":"clamp(1.5rem,5vw,5rem)"}}}} -->
<section class="wp-block-group" style="padding-top:clamp(5rem,9vw,8rem);padding-right:clamp(1.5rem,5vw,5rem);padding-bottom:clamp(3rem,5vw,4.5rem);padding-left:clamp(1.5rem,5vw,5rem)">
    <!-- wp:paragraph {"className":"t-eyebrow"} -->
    <p class="t-eyebrow">01 / OVER KINDRED</p>
    <!-- /wp:paragraph -->

    <!-- wp:heading {"level":1,"className":"t-fraunces","style":{"typography":{"fontSize":"clamp(40px,6vw,80px)","lineHeight":"1.05","letterSpacing":"-0.03em","fontWeight":"400"}}} -->
    <h1 class="wp-block-heading t-fraunces" style="font-size:clamp(40px,6vw,80px);font-style:normal;font-weight:400;letter-spacing:-0.03em;line-height:1.05">Een trainingsbureau dat <em>het werk respecteert</em>.</h1>
    <!-- /wp:heading -->
</section>
<!-- /wp:group -->

<!-- wp:columns {"style":{"spacing":{"padding":{"top":"0","bottom":"clamp(4rem,8vw,7rem)","left":"clamp(1.5rem,5vw,5rem)","right":"clamp(1.5rem,5vw,5rem)"}}}} -->
<div class="wp-block-columns" style="padding-top:0;padding-right:clamp(1.5rem,5vw,5rem);padding-bottom:clamp(4rem,8vw,7rem);padding-left:clamp(1.5rem,5vw,5rem)">
    <!-- wp:column {"width":"33%"} -->
    <div class="wp-block-column" style="flex-basis:33%">
        <!-- wp:paragraph {"className":"t-eyebrow"} -->
        <p class="t-eyebrow">WAAROM WE BESTAAN</p>
        <!-- /wp:paragraph -->
    </div>
    <!-- /wp:column -->
    <!-- wp:column -->
    <div class="wp-block-column">
        <!-- wp:paragraph -->
        <p>Kindred is opgericht in 2024 door een team uit HR-consulting en pedagogiek. We zagen te veel trainingen die in een powerpoint bleven hangen. Wat we bouwen: praktische, doorlopende leertrajecten die in het werk landen — geen losse workshops zonder follow-up.</p>
        <!-- /wp:paragraph -->
    </div>
    <!-- /wp:column -->
</div>
<!-- /wp:columns -->
```

- [ ] **Step 2: Create `patterns/contact.php`**

```php
<?php
/**
 * Title: Kindred — Contact
 * Slug: kindred/contact
 * Description: Contact page hero with split-column body for form + side card.
 * Categories: kindred
 * Keywords: contact, form
 * Viewport Width: 1280
 */
?>
<!-- wp:group {"tagName":"section","style":{"spacing":{"padding":{"top":"clamp(5rem,9vw,8rem)","bottom":"clamp(3rem,5vw,4.5rem)","left":"clamp(1.5rem,5vw,5rem)","right":"clamp(1.5rem,5vw,5rem)"}}}} -->
<section class="wp-block-group" style="padding-top:clamp(5rem,9vw,8rem);padding-right:clamp(1.5rem,5vw,5rem);padding-bottom:clamp(3rem,5vw,4.5rem);padding-left:clamp(1.5rem,5vw,5rem)">
    <!-- wp:paragraph {"className":"t-eyebrow"} -->
    <p class="t-eyebrow">CONTACT · BOEK · VRAAG</p>
    <!-- /wp:paragraph -->

    <!-- wp:heading {"level":1,"className":"t-fraunces","style":{"typography":{"fontSize":"clamp(40px,6vw,80px)","lineHeight":"1.05","letterSpacing":"-0.03em","fontWeight":"400"}}} -->
    <h1 class="wp-block-heading t-fraunces" style="font-size:clamp(40px,6vw,80px);font-style:normal;font-weight:400;letter-spacing:-0.03em;line-height:1.05">Stuur ons een <em>bericht.</em></h1>
    <!-- /wp:heading -->

    <!-- wp:paragraph {"style":{"typography":{"fontSize":"clamp(17px,1.6vw,20px)","lineHeight":"1.55"}}} -->
    <p style="font-size:clamp(17px,1.6vw,20px);line-height:1.55">Vragen over een traject op maat, in-company sessies of intervisie? Laat het ons weten — we plannen graag een eerste gesprek.</p>
    <!-- /wp:paragraph -->
</section>
<!-- /wp:group -->

<!-- wp:columns {"style":{"spacing":{"padding":{"top":"0","bottom":"clamp(4rem,8vw,7rem)","left":"clamp(1.5rem,5vw,5rem)","right":"clamp(1.5rem,5vw,5rem)"}}}} -->
<div class="wp-block-columns" style="padding-top:0;padding-right:clamp(1.5rem,5vw,5rem);padding-bottom:clamp(4rem,8vw,7rem);padding-left:clamp(1.5rem,5vw,5rem)">
    <!-- wp:column {"width":"60%"} -->
    <div class="wp-block-column" style="flex-basis:60%">
        <!-- wp:paragraph {"className":"t-eyebrow"} -->
        <p class="t-eyebrow">01 / FORMULIER</p>
        <!-- /wp:paragraph -->

        <!-- wp:paragraph -->
        <p><em>(Plaats hier het FluentForms shortcode of een formulier-blok.)</em></p>
        <!-- /wp:paragraph -->
    </div>
    <!-- /wp:column -->
    <!-- wp:column {"width":"40%"} -->
    <div class="wp-block-column" style="flex-basis:40%">
        <!-- wp:group {"className":"card","style":{"spacing":{"padding":{"top":"32px","right":"32px","bottom":"32px","left":"32px"}}}} -->
        <div class="wp-block-group card" style="padding-top:32px;padding-right:32px;padding-bottom:32px;padding-left:32px">
            <!-- wp:paragraph {"className":"t-eyebrow"} -->
            <p class="t-eyebrow">BEREIKBAAR</p>
            <!-- /wp:paragraph -->

            <!-- wp:paragraph -->
            <p><strong>contact@kindred.example</strong><br>+32 (0)2 000 00 00<br>Ma–Do · 9:00–17:00</p>
            <!-- /wp:paragraph -->
        </div>
        <!-- /wp:group -->
    </div>
    <!-- /wp:column -->
</div>
<!-- /wp:columns -->
```

- [ ] **Step 3: Create `patterns/faq.php`**

```php
<?php
/**
 * Title: Kindred — FAQ
 * Slug: kindred/faq
 * Description: Editorial Q&A list with mono numbering.
 * Categories: kindred
 * Keywords: faq, questions
 * Viewport Width: 1280
 */
?>
<!-- wp:group {"tagName":"section","style":{"spacing":{"padding":{"top":"clamp(5rem,9vw,8rem)","bottom":"clamp(5rem,9vw,8rem)","left":"clamp(1.5rem,5vw,5rem)","right":"clamp(1.5rem,5vw,5rem)"}}}} -->
<section class="wp-block-group" style="padding-top:clamp(5rem,9vw,8rem);padding-right:clamp(1.5rem,5vw,5rem);padding-bottom:clamp(5rem,9vw,8rem);padding-left:clamp(1.5rem,5vw,5rem)">
    <!-- wp:paragraph {"className":"t-eyebrow"} -->
    <p class="t-eyebrow">VEELGESTELDE VRAGEN</p>
    <!-- /wp:paragraph -->

    <!-- wp:heading {"level":2,"className":"t-fraunces","style":{"typography":{"fontSize":"clamp(32px,4.5vw,56px)","lineHeight":"1.05","letterSpacing":"-0.025em","fontWeight":"500"}}} -->
    <h2 class="wp-block-heading t-fraunces" style="font-size:clamp(32px,4.5vw,56px);font-style:normal;font-weight:500;letter-spacing:-0.025em;line-height:1.05">Wat klanten meestal vragen.</h2>
    <!-- /wp:heading -->

    <!-- wp:group {"style":{"spacing":{"margin":{"top":"48px"}}}} -->
    <div class="wp-block-group" style="margin-top:48px">
        <!-- wp:heading {"level":3,"style":{"typography":{"fontSize":"22px","fontWeight":"500"}}} -->
        <h3 class="wp-block-heading" style="font-size:22px;font-style:normal;font-weight:500">01 / Doen jullie in-company trajecten?</h3>
        <!-- /wp:heading -->
        <!-- wp:paragraph -->
        <p>Ja. We werken met groepen van 6 tot 20 deelnemers op locatie of online, gespreid over een traject van 4 tot 8 weken.</p>
        <!-- /wp:paragraph -->
    </div>
    <!-- /wp:group -->

    <!-- wp:group -->
    <div class="wp-block-group">
        <!-- wp:heading {"level":3,"style":{"typography":{"fontSize":"22px","fontWeight":"500"}}} -->
        <h3 class="wp-block-heading" style="font-size:22px;font-style:normal;font-weight:500">02 / Wat kost een traject?</h3>
        <!-- /wp:heading -->
        <!-- wp:paragraph -->
        <p>Open inschrijvingen vanaf €450 per deelnemer. In-company trajecten op maat — vraag een offerte via het contactformulier.</p>
        <!-- /wp:paragraph -->
    </div>
    <!-- /wp:group -->

    <!-- wp:group -->
    <div class="wp-block-group">
        <!-- wp:heading {"level":3,"style":{"typography":{"fontSize":"22px","fontWeight":"500"}}} -->
        <h3 class="wp-block-heading" style="font-size:22px;font-style:normal;font-weight:500">03 / Krijg ik een certificaat?</h3>
        <!-- /wp:heading -->
        <!-- wp:paragraph -->
        <p>Bij voltooiing van een traject ontvang je een Kindred-certificaat dat aansluit bij erkende HR- en pedagogische frameworks.</p>
        <!-- /wp:paragraph -->
    </div>
    <!-- /wp:group -->
</section>
<!-- /wp:group -->
```

- [ ] **Step 4: Create `patterns/agenda.php`**

```php
<?php
/**
 * Title: Kindred — Agenda
 * Slug: kindred/agenda
 * Description: Manual editions list pattern. Use the Stride editions shortcode for dynamic lists; this is for hand-curated overviews.
 * Categories: kindred
 * Keywords: agenda, editions, schedule
 * Viewport Width: 1280
 */
?>
<!-- wp:group {"tagName":"section","style":{"spacing":{"padding":{"top":"clamp(5rem,9vw,8rem)","bottom":"clamp(5rem,9vw,8rem)","left":"clamp(1.5rem,5vw,5rem)","right":"clamp(1.5rem,5vw,5rem)"}}}} -->
<section class="wp-block-group" style="padding-top:clamp(5rem,9vw,8rem);padding-right:clamp(1.5rem,5vw,5rem);padding-bottom:clamp(5rem,9vw,8rem);padding-left:clamp(1.5rem,5vw,5rem)">
    <!-- wp:paragraph {"className":"t-eyebrow"} -->
    <p class="t-eyebrow">PROGRAMMA</p>
    <!-- /wp:paragraph -->

    <!-- wp:heading {"level":2,"className":"t-fraunces","style":{"typography":{"fontSize":"clamp(32px,4.5vw,56px)","lineHeight":"1.05","letterSpacing":"-0.025em","fontWeight":"500"}}} -->
    <h2 class="wp-block-heading t-fraunces" style="font-size:clamp(32px,4.5vw,56px);font-style:normal;font-weight:500;letter-spacing:-0.025em;line-height:1.05">Trainingen in het komende kwartaal.</h2>
    <!-- /wp:heading -->

    <!-- wp:columns {"style":{"spacing":{"margin":{"top":"48px"}}}} -->
    <div class="wp-block-columns" style="margin-top:48px">
        <!-- wp:column -->
        <div class="wp-block-column">
            <!-- wp:group {"className":"card","style":{"spacing":{"padding":{"top":"24px","right":"24px","bottom":"24px","left":"24px"}}}} -->
            <div class="wp-block-group card" style="padding-top:24px;padding-right:24px;padding-bottom:24px;padding-left:24px">
                <!-- wp:paragraph {"className":"t-eyebrow"} -->
                <p class="t-eyebrow">12 MAART</p>
                <!-- /wp:paragraph -->
                <!-- wp:heading {"level":3,"style":{"typography":{"fontSize":"20px","fontWeight":"500"}}} -->
                <h3 class="wp-block-heading" style="font-size:20px;font-style:normal;font-weight:500">Feedback geven die landt</h3>
                <!-- /wp:heading -->
                <!-- wp:paragraph -->
                <p>Eendaagse training voor leidinggevenden. Beperkt tot 12 deelnemers.</p>
                <!-- /wp:paragraph -->
            </div>
            <!-- /wp:group -->
        </div>
        <!-- /wp:column -->

        <!-- wp:column -->
        <div class="wp-block-column">
            <!-- wp:group {"className":"card","style":{"spacing":{"padding":{"top":"24px","right":"24px","bottom":"24px","left":"24px"}}}} -->
            <div class="wp-block-group card" style="padding-top:24px;padding-right:24px;padding-bottom:24px;padding-left:24px">
                <!-- wp:paragraph {"className":"t-eyebrow"} -->
                <p class="t-eyebrow">04 APRIL</p>
                <!-- /wp:paragraph -->
                <!-- wp:heading {"level":3,"style":{"typography":{"fontSize":"20px","fontWeight":"500"}}} -->
                <h3 class="wp-block-heading" style="font-size:20px;font-style:normal;font-weight:500">Veerkracht in teams</h3>
                <!-- /wp:heading -->
                <!-- wp:paragraph -->
                <p>Tweedaags traject. Stress-signalen, werkdruk, herstel.</p>
                <!-- /wp:paragraph -->
            </div>
            <!-- /wp:group -->
        </div>
        <!-- /wp:column -->

        <!-- wp:column -->
        <div class="wp-block-column">
            <!-- wp:group {"className":"card","style":{"spacing":{"padding":{"top":"24px","right":"24px","bottom":"24px","left":"24px"}}}} -->
            <div class="wp-block-group card" style="padding-top:24px;padding-right:24px;padding-bottom:24px;padding-left:24px">
                <!-- wp:paragraph {"className":"t-eyebrow"} -->
                <p class="t-eyebrow">22 MEI</p>
                <!-- /wp:paragraph -->
                <!-- wp:heading {"level":3,"style":{"typography":{"fontSize":"20px","fontWeight":"500"}}} -->
                <h3 class="wp-block-heading" style="font-size:20px;font-style:normal;font-weight:500">Moeilijke gesprekken</h3>
                <!-- /wp:heading -->
                <!-- wp:paragraph -->
                <p>Halve dag. Voor managers, HR-business-partners en coaches.</p>
                <!-- /wp:paragraph -->
            </div>
            <!-- /wp:group -->
        </div>
        <!-- /wp:column -->
    </div>
    <!-- /wp:columns -->
</section>
<!-- /wp:group -->
```

- [ ] **Step 5: Create `patterns/terms.php`**

```php
<?php
/**
 * Title: Kindred — Voorwaarden
 * Slug: kindred/terms
 * Description: Long-form legal page scaffold (use with Kindred — Long-form page template).
 * Categories: kindred
 * Keywords: terms, conditions, legal
 * Viewport Width: 1280
 */
?>
<!-- wp:paragraph {"className":"t-eyebrow"} -->
<p class="t-eyebrow">JURIDISCH · LAATST GEWIJZIGD 2026-05</p>
<!-- /wp:paragraph -->

<!-- wp:heading {"level":2} -->
<h2 class="wp-block-heading">01 · Algemeen</h2>
<!-- /wp:heading -->
<!-- wp:paragraph -->
<p>Deze voorwaarden zijn van toepassing op alle trainingen, trajecten en consultancy-opdrachten van Kindred HR. Door inschrijving of opdrachtverstrekking aanvaard je deze voorwaarden integraal.</p>
<!-- /wp:paragraph -->

<!-- wp:heading {"level":2} -->
<h2 class="wp-block-heading">02 · Inschrijving en annulering</h2>
<!-- /wp:heading -->
<!-- wp:paragraph -->
<p>Inschrijvingen voor open trainingen zijn bindend na bevestiging. Annulering tot 14 dagen voor aanvang: kosteloos. Tussen 14 en 7 dagen: 50% van het tarief. Minder dan 7 dagen of niet komen opdagen: volledig tarief verschuldigd.</p>
<!-- /wp:paragraph -->

<!-- wp:heading {"level":2} -->
<h2 class="wp-block-heading">03 · Privacy</h2>
<!-- /wp:heading -->
<!-- wp:paragraph -->
<p>Persoonsgegevens worden verwerkt conform onze privacyverklaring. We delen geen deelnemerslijsten met derden.</p>
<!-- /wp:paragraph -->

<!-- wp:heading {"level":2} -->
<h2 class="wp-block-heading">04 · Intellectueel eigendom</h2>
<!-- /wp:heading -->
<!-- wp:paragraph -->
<p>Training materiaal blijft eigendom van Kindred. Het mag binnen jouw organisatie gebruikt worden, maar niet extern gedeeld zonder toestemming.</p>
<!-- /wp:paragraph -->
```

- [ ] **Step 6: Lint all 5 pattern files**

Run:
```bash
for f in /home/ntdst/Sites/stride/web/app/mu-plugins/stride-client-kindred/patterns/*.php; do
  ddev exec php -l /var/www/html/web/app/mu-plugins/stride-client-kindred/patterns/$(basename "$f")
done
```
Expected: 5× `No syntax errors detected`.

- [ ] **Step 7: Verify patterns appear in Gutenberg**

In a browser, open the WP admin → any page or post → Gutenberg block editor → click the
`+` block inserter → switch to "Patterns" tab → look for "Kindred HR" category.
Expected: 5 patterns visible (About, Contact, FAQ, Agenda, Voorwaarden).

If category empty: confirm `registerPatterns()` ran — visit `/?p=1` and check
`ddev logs` for any pattern-related errors. Most likely cause: invalid block markup in
one of the files. Comment out other files and bisect.

- [ ] **Step 8: Commit**

```bash
cd /home/ntdst/Sites/stride
git add web/app/mu-plugins/stride-client-kindred/patterns/
git commit -m "feat(kindred): 5 block patterns (about, contact, faq, agenda, terms)"
```

---

## Task 9: IDENTITY.md

**Files:**
- Create: `web/app/mu-plugins/stride-client-kindred/IDENTITY.md`

- [ ] **Step 1: Write IDENTITY.md**

Create `web/app/mu-plugins/stride-client-kindred/IDENTITY.md`:

```markdown
# Kindred HR — Client Identity

Stride client identity for Kindred HR, a fictional HR training and development company
serving Dutch and Belgian organisations.

Source: `~/Downloads/stridelms brand.zip` brand-board.
Filled-in copy of `CLIENT-IDENTITY-TEMPLATE.md`.

---

## 1. Brand Positioning

Kindred helpt organisaties trainen, coachen en ontwikkelen — voor managers, teams en
HR-professionals die met mensen werken.

**Tagline:** "Trainingen voor mensen die werken met mensen."

---

## 2. Adjective Set

kalm · doordacht · helder · menselijk · zakelijk · betrouwbaar · editorial

---

## 3. Reference Brands

Scandinavische digital minimalism + Stripe's editorial calm + Linear's structural clarity.

**Specifically inspired by:**
- Stripe (editorial typography, calm IA, restraint)
- Linear (structural typography, mono accents)
- The New York Times' product design (serif-italic accents, editorial gravitas)

**Specifically NOT like:**
- Generic corporate HR (stock photo handshakes, "wij geloven dat"-openings)
- Motivational poster aesthetic (gradients, big arrows, exclamation points)
- Startup-saas (rounded everything, gradient buttons, illustrations of laptops)

---

## 4. Design Principles

### 01. Restraint over decoration
**Rule:** Every visual element must earn its place — no decorative shapes, no marketing
illustrations, no celebratory ornament.
**Consequence:** Page composition relies on typography, surface tone shifts, and
whitespace rhythm. Stock photography forbidden. Hero "decorations" are absent.

### 02. Editorial typography is the visual system
**Rule:** Type does the work that graphics would do in a louder brand.
**Consequence:** Mix Geist (sans) with Instrument Serif italic for editorial fragments
and Fraunces for display. Mono labels (Geist Mono) for eyebrows, meta, micro-copy. No
icon-heavy compositions.

### 03. Tonal layering, not hard borders
**Rule:** Section breaks use surface-colour shifts, not 1px lines, wherever possible.
**Consequence:** `--color-surface` → `--color-surface-alt` ladder defines section rhythm.
1px lines are reserved for editorial inner-grids (intro split, pillar dividers), not
section separation.

---

## 5. Avoid / Instead

| Avoid | Instead |
|---|---|
| Generic corporate blue | Moss green `#1F5B3D` on warm stone `#F1F2EF` |
| Heavy gradients | Single-tone surfaces; tonal shifts via `--color-surface*` ladder |
| Busy layouts | Editorial column-grid (130px label col + 1fr body) with generous whitespace |
| Playful startup feel | Editorial gravitas — Instrument Serif italic + restrained spacing |
| Fast bouncy motion | Slow premium motion — 180/320ms with `cubic-bezier(0.22, 0.61, 0.36, 1)`, never overshoot |
| Stock photography | Photography sparingly, only when documentary (real workshops, hands, environments) |

---

## 6. Logo & Mark

**Mark concept:** Abstract "S" curve formed by four paths inside a rounded square,
suggesting motion, connection, and a sheltering container.

**Wordmark personality:**
- Style: geometric sans (Geist)
- Tracking: tight, architectural (`-0.02em`)
- Case: lowercase or sentence case in long-form copy; uppercase in mono eyebrows only
- Weight: regular 400, never bold

**Lockup rules:**
- Icon-only used as favicon and small UI
- Icon and wordmark always horizontal in lockups
- Minimum icon height 24px

**Variants required:**
- [x] Primary mark (light backgrounds): `logo.svg` with `currentColor` rect + `#f9faf7` paths
- [x] Inverted: same SVG, parent text-color reversed
- [x] Icon-only: same SVG
- [ ] Horizontal lockup: not in this iteration
- [ ] Vertical lockup: not in this iteration

---

## 7. Color System

### Palette intent

Cool stone + moss. Calm, professional, vegetal. Stone is the breathing space; moss is
the single accent that does all the structural work.

### Primary palette (RGB triplets for use with `rgb(var(--token))`)

| Role | Token | Hex | RGB triplet |
|---|---|---|---|
| Primary brand | `--color-primary` | `#1F5B3D` | `31 91 61` |
| Primary hover | `--color-primary-hover` | `#0F3A25` | `15 58 37` |
| Primary subtle | `--color-primary-subtle` | `#BCD4C4` | `188 212 196` |
| Primary light | `--color-primary-light` | `#BCD4C4` | `188 212 196` |
| Primary dark | `--color-primary-dark` | `#0F3A25` | `15 58 37` |
| Accent | `--color-accent` | `#1F5B3D` | `31 91 61` |
| Accent light | `--color-accent-light` | `#BCD4C4` | `188 212 196` |

### Surface palette

| Role | Token | Hex | RGB triplet |
|---|---|---|---|
| Body background | `--color-surface` | `#F1F2EF` | `241 242 239` |
| Section shift | `--color-surface-alt` | `#E8EAE5` | `232 234 229` |
| Card surface | `--color-surface-card` | `#F9FAF7` | `249 250 247` |
| Border (ghost) | `--color-border` | `#E1E3DC` | `225 227 220` |
| Border (strong) | `--color-border-strong` | `#D4D7D0` | `212 215 208` |
| Text | `--color-text` | `#111312` | `17 19 18` |
| Text muted | `--color-text-muted` | `#6C706B` | `108 112 107` |

### Secondary palette

Not used. Single accent (moss) does all structural work; no decorative secondary.

### Accessibility rules

- [x] WCAG AA contrast on all text/background pairs (moss `#1F5B3D` on stone `#F1F2EF`
      hits 8.4:1; primary-hover `#0F3A25` hits 11.2:1)
- [x] No low-opacity text on tinted backgrounds
- [x] Focus states visible: 3px primary-subtle ring + border-color shift
- [x] Status communicated by colour + label text, never colour alone

---

## 8. Typography

### Primary typeface

**Family:** Geist
**Used for:** Body, UI, labels, H1–H4 (regular 400 / medium 500, never bold)
**Why this one:** Modern geometric sans with strong text-feature support (`ss01`, `cv11`).
Matches "editorial calm" while staying neutral enough for product UI.
**Fallbacks:** system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif

### Secondary typeface

**Family:** Instrument Serif
**Used for:** Editorial fragments — italicised phrases inside hero headlines, quote
blocks, hero intro paragraphs
**Used sparingly:** yes — one italic fragment per major heading max
**Fallbacks:** Georgia, "Times New Roman", serif

### Tertiary typeface (mono)

**Family:** Geist Mono
**Used for:** Eyebrows (`t-eyebrow`), meta strings, mono labels, code

### Display alt typeface

**Family:** Fraunces
**Used for:** Optional display headings via `.t-fraunces` utility — applied to front-page
H1/H2 for editorial weight without going to bold sans
**Used sparingly:** front-page only

### Token mapping

| Stridence token | Family | Role |
|---|---|---|
| `--font-sans` | Geist | Body, UI, labels |
| `--font-heading` | Geist | H1–H4 (paired with `.t-fraunces` utility for display) |
| `--font-serif` | Instrument Serif | Editorial fragments |
| `--font-label` | Geist Mono | Eyebrows, meta, micro |
| `--font-display-alt` | Fraunces | Front-page display |

### Google Fonts URL

```
https://fonts.googleapis.com/css2?family=Geist:wght@300..700&family=Geist+Mono:wght@400..600&family=Instrument+Serif:ital@0;1&family=Fraunces:opsz,wght@9..144,300..700&display=swap
```

Loaded via `overrideFontUrl()` in `stride-client-kindred.php`.

### Hierarchy

| Level | Family | Weight | Personality |
|---|---|---|---|
| H1 | Fraunces (`.t-fraunces`) | 400 | editorial |
| H2 | Fraunces (`.t-fraunces`) | 500 | structural |
| H3 | Geist | 500 | utilitarian |
| Body | Geist | 400 | readable |
| UI label | Geist Mono | 500 | crisp |

---

## 9. Shape, Surface & Elevation

### Radius

| Token | Value | Notes |
|---|---|---|
| `--radius-sm` | 4px | small inputs, tags |
| `--radius-md` | 8px | buttons, inputs |
| `--radius-lg` | 14px | cards, panels |
| `--radius-xl` | 22px | hero blocks |
| Button radius | 8px | never pill |
| Card radius | 14px | |

### Shadow language

**Personality:** minimal, ink-tinted, near-invisible defaults
**Blur range:** 2-50px
**Opacity range:** 4-14%
**Tint:** ink `#111312`, never pure black, never brand-tinted

### Surface ladder

| Token | Visual role |
|---|---|
| `--color-surface` | Page base (stone `#F1F2EF`) |
| `--color-surface-alt` | Section shift (`#E8EAE5`) |
| `--color-surface-card` | Cards lifted from page (`#F9FAF7`) |
| `--color-surface-container` | Emphasised panels (`#E8EAE5`) |
| `--color-surface-container-high` | Modals, dropdowns (`#E1E3DC`) |
| `--color-surface-container-highest` | Overlays, popovers (`#D4D7D0`) |

---

## 10. Motion

| Token | Value | When |
|---|---|---|
| `--duration-fast` | 180ms | hover, focus, color shift |
| `--duration-normal` | 320ms | reveal, dismiss, content swap |
| `--ease-out` | `cubic-bezier(0.22, 0.61, 0.36, 1)` | Default — never overshoot |

**Motion personality:** Slow enough to feel premium; never bouncy.

**Preferred motion types:**
- fade
- soft slide (8–16px translation max)
- gentle scale (0.98 ↔ 1)

**Avoid:**
- bounce / spring physics
- fast snappy transitions (<150ms)
- parallax

**Reduced motion:** honors `prefers-reduced-motion: reduce` — all transitions and
animations reduced to 0.01ms.

---

## 11. Imagery

### Photography

**Allowed:** sparingly
**Style:** documentary, real
**Subjects:** real workshops, hands writing, environment shots — never staged smiles
**Treatment:** natural colour, slight desaturation; no duotones
**Sources:** commissioned or brand library

**Avoid:**
- staged corporate smiles
- obvious stock
- "diversity poses"
- technology clichés (hands on laptop, gears, lightbulbs)

### Illustration

**Style:** none / minimal line if needed
**Color:** `currentColor` so it inherits

### Iconography

**Style:** outline, 1.5px stroke
**Reference libraries:** Phosphor or Lucide

### Data visualization

**Color usage:** monochrome moss ramp; no rainbow palettes
**Chart style:** editorial — minimal grid, mono labels

---

## 12. Layout & Composition

### Grid philosophy

Editorial column grid: 130px label column + 1fr body. Generous whitespace.

### Layout traits

- Whitespace: generous
- Alignment: strict editorial grid with asymmetric balance
- Rhythm: varied per section (cover, pillars, agenda, closer have distinct rhythms)
- Sections: divided by tonal shift (surface ↔ surface-alt), not 1px lines

### Avoid

- overcrowded sections
- too many card styles
- heavy shadows on every element
- decorative animation

---

## 13. Pattern Policies

### Hero

- Layout: editorial split (130px label + 1fr title + auto meta)
- Imagery: none — typography is the hero
- Headline style: Fraunces 400 + Instrument Serif italic fragment, max 12 words
- Lede: Instrument Serif italic 24–30px + Geist body paragraph
- CTA: single primary, verb pattern: imperative + concrete object ("Bekijk de vormingen")
- Animation: none on first paint; gentle fade-in on scroll only if used
- Never: stock photography, centered photo, video background

### Homepage section sequence

1. [x] Hero (cover)
2. [x] Why this matters (intro paragraph in hero)
3. [x] Services / features (5-pillar grid)
4. [x] Editions / agenda
5. [x] Featured trajectory (split)
6. [x] Quote / testimonial
7. [x] CTA / closer
8. [ ] Pricing — not in front-page
9. [ ] FAQ — not in front-page (own page via pattern)

### Services / feature grid

- Layout: 5-col on desktop, 2-col tablet, 1-col mobile
- Card style: borderless tonal divider — column-rule 1px line, no card shadows
- Card body: number eyebrow + label + 1-line description

### Section rhythm

- Section spacing: clamp(48px, 8vw, 110px) padding
- Section dividers: tonal shift (`--color-surface` ↔ `--color-surface-alt`)
- Section heading style: number eyebrow + Fraunces title

### Forms

- Field style: bordered
- Field radius: `--radius-md` (8px)
- Focus state: border-color shift to moss + 3px primary-subtle ring
- Error language: direct, recovery-oriented ("Vul je e-mailadres in om verder te gaan.")

---

## 14. Voice

### Tone traits

Doordacht · zakelijk · warm · helder · respectvol · niet pushend · editorial

### Messaging principles

- **Speak clearly:** Korte zinnen. Geen jargon. Concrete werkwoorden.
- **Frame:** Educate, niet verkoop. Help kiezen, geen druk uitoefenen.
- **Confidence level:** Quiet authority — we weten wat we doen, we juichen het niet uit.

### We sound like

- "Geen frontale lessen. Praktische trainingen die in het werk landen."
- "We werken in groepen van 6 tot 20, met follow-up tussen sessies."
- "Inschrijving sluit een week voor aanvang."
- "Annulering tot 14 dagen vooraf: kosteloos."

### We don't sound like

- "Wij geloven dat elke mens uniek is en wij maken het verschil!" (marketing-juich)
- "Boost je impact 🚀" (emoji-startup)
- "Click here to learn more" (CTA-templates)
- "Onze missie is om de wereld een betere plek te maken." (lege grandiositeit)

### Microcopy patterns

**CTA verb pattern:** Imperatief + concrete object. "Bekijk de vormingen", "Plan een gesprek", "Download de programmabrochure". Never "Klik hier" or "Lees meer".

**Empty state pattern:** "Geen geplande trainingen op dit moment. Volg ons of meld je aan voor de nieuwsbrief."

**Error message pattern:** "Vul je e-mailadres in om verder te gaan." Plain description + recovery hint, geen excuses.

**Sample headlines:**
- "Trainingen voor mensen die werken met mensen."
- "Vijf domeinen waar onze trainingen verschil maken."
- "Eerstvolgende trainingen."
- "Klaar om te starten?"

---

## 15. Brand Applications

- [x] Website (Stride LMS frontend) — primary surface
- [x] Web app / product UI (dashboard, course pages, enrollment) — same skin
- [ ] LinkedIn / social — out of scope for this plugin
- [ ] Email templates — handled separately by theme
- [ ] Slide decks — out of scope
- [ ] PDF documents (quotes, invoices) — uses Stride core PDF templates, not client-skinned
- [ ] Workshop / printed materials — out of scope

---

## 16. Technical Contract — Token Coverage

### Required

- [x] `--color-primary` + `-hover` + `-subtle` + `-light` + `-dark`
- [x] `--color-accent` + `-light`
- [x] `--color-surface` + `-alt` + `-card`
- [x] `--color-text` + `-muted`
- [x] `--color-border` + `-strong`
- [x] `--font-sans`, `--font-heading`, `--font-serif`, `--font-label`

### Optional

- [x] Status colors (`--color-success`, `-warning`, `-error`, `-info`)
- [x] Badge color pairs (open / few / full / cancelled / online / free)
- [x] 5-tier surface ladder
- [ ] Tertiary palette (single accent, no tertiary)
- [x] Radii
- [x] Shadows
- [x] Motion
- [x] Macro spacing (`--space-section`, `-block`, `-element`)

---

## 17. Technical Contract — Component & Template Coverage

### Components

- [x] `.btn-primary` / `.btn-secondary` / `.btn-accent`
- [x] `.btn-outline-dark` / `.btn-outline-light`
- [x] `.btn-ghost` / `.btn-danger`
- [x] `.btn-sm` / `.btn-lg`
- [x] `.card` / `.card-bordered` / `.card-interactive`
- [x] `.dash-card` / `.dash-card-hero` / `.dash-card-interactive` / `.dash-panel`
- [x] `.glass-nav`
- [x] `.dark-section`
- [x] `.hero-watermark`
- [x] `.tag-pill`
- [x] `.prose-stride`
- [x] Form inputs
- [x] Status badges
- [x] `::selection`
- [x] `a` (links)
- [x] `footer`
- [x] `@media (prefers-reduced-motion: reduce)`

### Templates

- [x] `templates/front-page.php` — required for identity swap
- [ ] `templates/header.php` — not needed, glass-nav re-skin via CSS suffices
- [ ] `templates/footer.php` — not needed, footer re-skin via CSS suffices
- [x] `templates/page-stub.php` — narrow long-form layout for stub pages

---

## 18. Out of Scope

By default this identity does not touch:

- Admin dashboard (`stride-core/Admin/`)
- PDF templates (invoices, quotes)
- LearnDash course content rendering
- Enrollment / checkout flow logic
- Email templates

---

## 19. Deliverable Phases — status

### Phase 1: Foundation
- [x] Logo system
- [x] Color tokens
- [x] Typography tokens
- [x] Shape & motion tokens
- [x] IDENTITY.md complete

### Phase 2: Surfaces
- [x] `client.css` — tokens + component re-skin
- [x] `templates/front-page.php`
- [x] `templates/page-stub.php`
- [x] `stride-client-kindred.php`

### Phase 3: Extensions
- [ ] Email templates — out of scope
- [ ] Slide templates — out of scope
- [ ] Illustration system — none required

---

## 20. Reference

- **Reference implementation:** `stride-client-safeandsound/`
- **Token contract:** `docs/STRIDENCE-TOKENS.md` (if present)
- **Brand source:** `~/Downloads/stridelms brand.zip` (extracted to `/tmp/stridelms-brand/`)
- **Design spec:** `docs/superpowers/specs/2026-05-15-stride-client-kindred-design.md`
```

- [ ] **Step 2: Verify no placeholders remain**

Run:
```bash
grep -nE '_\[.*\]_|TBD|TODO|<.+>' /home/ntdst/Sites/stride/web/app/mu-plugins/stride-client-kindred/IDENTITY.md | grep -v "^.*://" | grep -v "RGB triplet"
```
Expected: no output (any `<.+>` matches will be in URL `<>` brackets, the grep filters URLs; any remaining matches are placeholders to fill).

- [ ] **Step 3: Commit**

```bash
cd /home/ntdst/Sites/stride
git add web/app/mu-plugins/stride-client-kindred/IDENTITY.md
git commit -m "feat(kindred): filled IDENTITY.md"
```

---

## Task 10: Final acceptance walkthrough

**No files modified. This task verifies the spec acceptance criteria (§10 of spec).**

- [ ] **Step 1: Confirm directory has 11 files**

Run:
```bash
find /home/ntdst/Sites/stride/web/app/mu-plugins/stride-client-kindred -type f | wc -l
```
Expected: `11`

If 10 or 12: list the directory and reconcile with §2 of the spec.

- [ ] **Step 2: Visual QA — homepage**

Browser: `https://stride.ddev.site/`.
Confirm:
- Cover hero shows "Trainingen voor mensen die werken met mensen." with "werken met mensen" in italic Instrument Serif
- 5 pillars render in a row on desktop
- Upcoming editions section shows either edition cards or the empty-state "Geen geplande trainingen op dit moment." copy
- Quote in italic Instrument Serif, large
- Closer with "Klaar om te starten?" and "Bekijk de vormingen" button
- Background: stone `#F1F2EF`; primary buttons: moss `#1F5B3D`

- [ ] **Step 3: Visual QA — interior pages**

Browser: `https://stride.ddev.site/mijn-account/` (or any dashboard URL).
Confirm:
- Body background: stone (not white, not warm cream)
- Geist font on body text
- Primary buttons: moss green
- Cards: stone-card surface with subtle border, 14px radius

If a page renders in pre-Kindred styling (white background, default font): a stylesheet is
loading later than `client.css` and winning specificity. Check the `<head>` order — Kindred
CSS should be near the end of stylesheet enqueues (we set priority 100 in `init()`).

- [ ] **Step 4: Verify reduced-motion**

In DevTools (Chrome / Firefox) → Rendering tab → Emulate CSS media feature
`prefers-reduced-motion: reduce`. Reload. Hover over a primary button.
Expected: instant color change (no 180ms transition).

- [ ] **Step 5: Verify Google Fonts loaded**

DevTools → Network tab → filter for "fonts.googleapis". Reload.
Expected: a request to `fonts.googleapis.com/css2?family=Geist...&family=Instrument+Serif...&family=Fraunces...` returns 200.

- [ ] **Step 6: Verify patterns in Gutenberg**

WP admin → any page → block editor → `+` → Patterns tab → search "Kindred".
Expected: 5 patterns under "Kindred HR" category.

- [ ] **Step 7: Verify page template selectable**

WP admin → Pages → any page → Page Attributes panel.
Expected: "Kindred — Long-form page" available in Template dropdown.

- [ ] **Step 8: Restore safeandsound (if user wants both available)**

The user chooses which client to keep active. If they want safeandsound back:

```bash
mv /home/ntdst/Sites/stride/web/app/mu-plugins/stride-client-safeandsound/stride-client-safeandsound.php.off \
   /home/ntdst/Sites/stride/web/app/mu-plugins/stride-client-safeandsound/stride-client-safeandsound.php
```

NOTE: only one client should be active at a time — multiple `template_include` overrides
will collide. The user should decide which one is "live" and `.off` the others.

If both remain renamed `.off`, leave Kindred as the only active client.

- [ ] **Step 9: Final summary commit (optional)**

If any documentation needs to mention the new client (e.g., `CLAUDE.md`'s "Reference implementations" section, the spec's `§20`):

```bash
cd /home/ntdst/Sites/stride
git add docs/ CLAUDE.md
git commit -m "docs: reference stride-client-kindred"
```

If nothing else to document, skip.

---

## Self-review checklist

After all tasks:

1. **Spec coverage:**
   - §2 file structure → Task 1 + 2 + 3 + 4 + 5 + 6 + 7 + 8 + 9 ✓
   - §3 PHP bootstrap → Task 1 ✓
   - §4 token mapping → Task 2 ✓
   - §5 front-page sequence → Tasks 5 + 6 ✓
   - §6 IDENTITY.md content → Task 9 ✓
   - §7 patterns → Task 8 ✓
   - §8 activation (disable safeandsound) → Task 1 step 1 + Task 10 step 8 ✓
   - §10 acceptance criteria → Task 10 ✓

2. **No placeholders:** none in plan body. IDENTITY.md instructs Task 9 step 2 to grep for any.

3. **Type consistency:** class name `StrideClientKindred` consistent across Task 1; CSS token names (`--color-primary` etc.) consistent between Task 2 and IDENTITY.md §7.

4. **Verification at every task:** lint after PHP writes, visual smoke after CSS / templates, pattern visibility after Task 8, full walkthrough in Task 10.
