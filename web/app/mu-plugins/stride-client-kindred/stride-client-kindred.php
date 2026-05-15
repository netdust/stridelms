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

        // Depend on LearnDash's late stylesheets so our :root token overrides
        // win against the LD30 modern theme (loaded after our default priority).
        // Each handle is only listed as a dep if it's actually registered —
        // wp_style_is() guards against breaking pages where LD isn't loaded.
        $deps = [];
        foreach (['learndash-front', 'learndash-ld30-modern', 'learndash-css'] as $handle) {
            if (wp_style_is($handle, 'registered') || wp_style_is($handle, 'enqueued')) {
                $deps[] = $handle;
            }
        }

        wp_enqueue_style(
            'stride-client',
            $this->url . '/assets/client.css',
            $deps,
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
