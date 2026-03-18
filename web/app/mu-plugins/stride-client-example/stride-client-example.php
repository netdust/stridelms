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
 * TEMPLATE OVERRIDES:
 * To override a theme template, create the same path under templates/.
 * Example: to override partials/card-course.php, create:
 *   templates/partials/card-course.php
 *
 * CSS OVERRIDES:
 * Edit assets/client.css to change CSS custom properties.
 * Colors use RGB triplets: --color-primary: 220 38 38;
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

        // Admin CSS overrides (optional — only loads if assets/admin.css exists)
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
     * @param string      $override Current override path (empty = use default)
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
