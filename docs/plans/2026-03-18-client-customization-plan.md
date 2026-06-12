# Client Customization System — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a template override mechanism to the stridence theme and scaffold a reference client mu-plugin for per-client look & feel customization.

**Architecture:** A `stridence_template_part()` wrapper replaces all `get_template_part()` calls in the theme, adding a filter hook that client plugins use to override templates. A reference client mu-plugin demonstrates CSS token overrides and template replacement.

**Tech Stack:** PHP 8.3, WordPress, Tailwind CSS custom properties

---

## File Structure

### New files
| File | Responsibility |
|------|---------------|
| `stridence/helpers/templates.php` | `stridence_template_part()` function with filter |
| `stride-client-example/stride-client-example.php` | Client mu-plugin bootstrap |
| `stride-client-example/assets/client.css` | Example CSS token overrides |
| `stride-client-example/templates/partials/card-course.php` | Example template override |

### Modified files
| File | Change |
|------|--------|
| `stridence/functions.php` | Require `helpers/templates.php` |
| 32 theme PHP files | Replace `get_template_part()` → `stridence_template_part()` |

---

## Task 1: Create template override helper

**Files:**
- Create: `web/app/themes/stridence/helpers/templates.php`
- Modify: `web/app/themes/stridence/functions.php`

- [ ] **Step 1: Create helpers/templates.php**

```php
<?php
/**
 * Template loading with override support.
 *
 * Wraps get_template_part() with a filter that allows plugins
 * to override any template by providing an alternative file path.
 *
 * @package stridence
 */

declare(strict_types=1);

/**
 * Load a template part with plugin override support.
 *
 * Works identically to get_template_part() but fires a filter
 * that client plugins can hook into to provide an override path.
 *
 * Filter: 'stridence_template_path'
 *   @param string      $override  Override file path (empty = use default)
 *   @param string      $slug      Template slug
 *   @param string|null $name      Template name variant
 *   @param array       $args      Arguments passed to the template
 *
 * @param string      $slug Template slug (e.g., 'partials/card-course')
 * @param string|null $name Optional template name variant
 * @param array       $args Arguments passed to the template
 */
function stridence_template_part(string $slug, ?string $name = null, array $args = []): void
{
    $override = apply_filters('stridence_template_path', '', $slug, $name, $args);

    if ($override && file_exists($override)) {
        load_template($override, false, $args);
        return;
    }

    get_template_part($slug, $name, $args);
}
```

- [ ] **Step 2: Require the helper in functions.php**

In `web/app/themes/stridence/functions.php`, add after the existing helper requires (after `require_once STRIDENCE_DIR . '/helpers/formatting.php';`):

```php
require_once STRIDENCE_DIR . '/helpers/templates.php';
```

- [ ] **Step 3: Verify helper loads**

```bash
ddev exec wp eval "echo function_exists('stridence_template_part') ? 'OK' : 'FAIL';"
```

Expected: `OK`

- [ ] **Step 4: Commit**

```bash
git add web/app/themes/stridence/helpers/templates.php web/app/themes/stridence/functions.php
git commit -m "feat(theme): add stridence_template_part() with plugin override filter"
```

---

## Task 2: Replace get_template_part calls across theme

**Files:** 32 PHP files in `web/app/themes/stridence/`

This is a bulk find-and-replace. Replace every `get_template_part(` call with `stridence_template_part(` across the theme. The function signature is identical — this is a safe 1:1 replacement.

**IMPORTANT:** Only replace calls within the stridence theme directory. Do NOT touch any files outside `web/app/themes/stridence/`.

- [ ] **Step 1: Count current calls to verify scope**

```bash
ddev exec bash -c "grep -r 'get_template_part(' /var/www/html/web/app/themes/stridence/ --include='*.php' | wc -l"
```

Expected: ~82 matches

- [ ] **Step 2: Perform the replacement**

```bash
ddev exec bash -c "find /var/www/html/web/app/themes/stridence/ -name '*.php' -exec sed -i 's/get_template_part(/stridence_template_part(/g' {} +"
```

- [ ] **Step 3: Verify the replacement**

```bash
ddev exec bash -c "grep -r 'get_template_part(' /var/www/html/web/app/themes/stridence/ --include='*.php' | wc -l"
```

Expected: `0` — no remaining `get_template_part` calls

```bash
ddev exec bash -c "grep -r 'stridence_template_part(' /var/www/html/web/app/themes/stridence/ --include='*.php' | wc -l"
```

Expected: ~82 matches

- [ ] **Step 4: Smoke test — load the homepage**

```bash
ddev exec wp eval "echo 'Site loads OK';"
```

Visit `https://stride.ddev.site` — confirm no fatal errors, pages render normally.

- [ ] **Step 5: Smoke test — load dashboard**

Visit `https://stride.ddev.site/mijn-account/` — confirm dashboard renders, tabs work.

- [ ] **Step 6: Commit**

```bash
cd web/app/themes/stridence && git add -A && cd /home/ntdst/Sites/stride
git commit -m "refactor(theme): replace get_template_part with stridence_template_part

Enables client plugins to override any template via the
stridence_template_path filter. All 82 calls updated."
```

---

## Task 3: Scaffold client mu-plugin

**Files:**
- Create: `web/app/mu-plugins/stride-client-example/stride-client-example.php`
- Create: `web/app/mu-plugins/stride-client-example/assets/client.css`
- Create: `web/app/mu-plugins/stride-client-example/templates/partials/card-course.php`

- [ ] **Step 1: Create plugin bootstrap**

```php
<?php
/**
 * Plugin Name: Stride Client — Example
 * Description: Reference client customization plugin. Copy this as a starting point for new clients.
 * Version: 1.0.0
 * Author: Netdust
 *
 * HOW TO USE:
 * 1. Copy this folder as stride-client-{clientname}/
 * 2. Rename this file to stride-client-{clientname}.php
 * 3. Update the Plugin Name above
 * 4. Edit assets/client.css for branding (colors, fonts)
 * 5. Add template overrides in templates/ (mirror theme structure)
 *
 * @package stride-client-example
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

final class StrideClientExample
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
        // Template overrides
        add_filter('stridence_template_path', [$this, 'overrideTemplatePath'], 10, 4);

        // CSS branding overrides (load after theme styles)
        add_action('wp_enqueue_scripts', [$this, 'enqueueStyles'], 100);

        // Admin CSS overrides (optional)
        add_action('admin_enqueue_scripts', [$this, 'enqueueAdminStyles'], 100);
    }

    /**
     * Override template paths.
     *
     * If this plugin has a matching template file in its templates/ dir,
     * it takes priority over the theme's version.
     *
     * Template structure mirrors the theme:
     *   theme:  partials/card-course.php
     *   client: templates/partials/card-course.php
     *
     * @param string      $override Current override path (empty = default)
     * @param string      $slug     Template slug
     * @param string|null $name     Template name variant
     * @param array       $args     Template arguments
     * @return string Override path or empty string
     */
    public function overrideTemplatePath(string $override, string $slug, ?string $name, array $args): string
    {
        // Don't override if another plugin already claimed this template
        if (!empty($override)) {
            return $override;
        }

        $file = $this->dir . '/templates/' . $slug;
        if ($name) {
            $file .= '-' . $name;
        }
        $file .= '.php';

        return file_exists($file) ? $file : '';
    }

    /**
     * Enqueue client CSS after theme styles.
     */
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

    /**
     * Enqueue admin CSS overrides (optional).
     *
     * Only loads if assets/admin.css exists.
     */
    public function enqueueAdminStyles(): void
    {
        $cssFile = $this->dir . '/assets/admin.css';
        if (!file_exists($cssFile)) {
            return;
        }

        wp_enqueue_style(
            'stride-client-admin',
            $this->url . '/assets/admin.css',
            [],
            (string) filemtime($cssFile)
        );
    }
}

new StrideClientExample();
```

- [ ] **Step 2: Create example client.css**

```css
/**
 * Stride Client — Example Branding
 *
 * Override CSS custom properties to change the look & feel.
 * All tokens are defined in the theme's tokens.css.
 *
 * Colors use RGB triplets (no commas) for Tailwind alpha support:
 *   --color-primary: 220 38 38;  (not #DC2626 or rgb(220, 38, 38))
 *
 * COMMON OVERRIDES:
 * - Brand colors: --color-primary, --color-primary-hover, --color-primary-subtle
 * - Accent: --color-accent, --color-accent-light
 * - Surfaces: --color-surface, --color-surface-alt, --color-surface-card
 * - Typography: --font-sans, --font-heading
 * - Layout: --container-max, --sidebar-width
 * - Borders: --radius-sm through --radius-xl
 */

:root {
    /* ─── Example: Red brand instead of indigo ─── */
    /* Uncomment and adjust to your client's brand colors */

    /*
    --color-primary: 220 38 38;
    --color-primary-hover: 185 28 28;
    --color-primary-subtle: 254 242 242;
    --color-primary-light: 239 68 68;
    --color-primary-dark: 153 27 27;

    --color-accent: 37 99 235;
    --color-accent-light: 59 130 246;
    */

    /* ─── Example: Custom fonts ─── */
    /*
    --font-sans: 'Poppins', system-ui, sans-serif;
    --font-heading: 'Playfair Display', Georgia, serif;
    */

    /* ─── Example: Tighter border radius ─── */
    /*
    --radius-sm: 0.25rem;
    --radius-md: 0.375rem;
    --radius-lg: 0.5rem;
    --radius-xl: 0.75rem;
    */
}

/* ─── Client logo ─── */
/*
.site-logo img {
    content: url('../images/client-logo.svg');
    max-height: 40px;
}
*/
```

- [ ] **Step 3: Create example template override**

Copy the current `partials/card-course.php` from the theme as a starting point, then add a comment explaining this is a client override:

First read the current card-course.php from the theme (`web/app/themes/stridence/partials/card-course.php`), then create a modified version at `web/app/mu-plugins/stride-client-example/templates/partials/card-course.php`.

Add this header comment to the top of the copied file:

```php
<?php
/**
 * Client Override: Course Card
 *
 * This file overrides the theme's partials/card-course.php.
 * Modify the HTML/layout below to match this client's design.
 *
 * Available variables (from $args):
 *   $args['course'] — WP_Post object for the course
 *
 * To revert to the default, simply delete this file.
 *
 * @package stride-client-example
 */
```

Keep the rest of the template identical to the theme's version — it serves as documentation of the override mechanism.

- [ ] **Step 4: Verify plugin loads and override works**

```bash
ddev exec wp plugin list --status=must-use
```

Expected: `stride-client-example` shows in the list.

Test template override works:
```bash
ddev exec wp eval "
    // Simulate the filter
    \$override = apply_filters('stridence_template_path', '', 'partials/card-course', null, []);
    echo \$override ? 'Override active: ' . basename(\$override) : 'No override';
"
```

Expected: `Override active: card-course.php`

- [ ] **Step 5: Verify client CSS loads**

Visit `https://stride.ddev.site` → View page source → search for `stride-client` in stylesheet links.

Expected: `client.css` is enqueued after the theme stylesheet.

- [ ] **Step 6: Commit**

```bash
git add web/app/mu-plugins/stride-client-example/
git commit -m "feat(client): scaffold reference client mu-plugin

Demonstrates template overrides, CSS token overrides, and plugin structure.
Copy as stride-client-{name}/ for new client customizations."
```

---

## Task 4: Verify end-to-end

- [ ] **Step 1: Test default behavior (no overrides active)**

Temporarily rename the client plugin:

```bash
mv web/app/mu-plugins/stride-client-example web/app/mu-plugins/_stride-client-example
```

Visit key pages and confirm everything renders normally:
- Homepage
- Course archive (`/opleidingen/`)
- Dashboard (`/mijn-account/`)
- Single course page

- [ ] **Step 2: Restore plugin and test override**

```bash
mv web/app/mu-plugins/_stride-client-example web/app/mu-plugins/stride-client-example
```

Visit a page showing course cards — confirm the override template is used (check for the client override header comment in page source if needed).

- [ ] **Step 3: Test CSS override**

Uncomment a color override in `client.css` (e.g., change primary to red), reload the site, and verify the color changes throughout.

Re-comment the override after testing.

- [ ] **Step 4: Run unit tests to confirm no regressions**

```bash
ddev exec vendor/bin/phpunit --testsuite Unit 2>&1 | tail -5
```

Expected: Same pass/fail count as before (no new failures).
