<?php
declare(strict_types=1);

namespace stridence\services\frontend\hooks;

use NTDST_Theme;

/**
 * Browser-level hooks: PWA metadata, prefetch hardening, cache headers,
 * emoji cleanup and global body classes.
 *
 * The prefetch/speculation handling exists because Brave (and Chrome under
 * some settings) issues prefetch requests *without* cookies. WordPress then
 * renders an unauthenticated response which the browser caches and serves on
 * the real navigation — making enrollment state, dashboard content and login
 * state appear stale until refresh.
 */
final class BrowserHooks
{
    public function bind(NTDST_Theme $theme): void
    {
        // Emoji cleanup is a removal, not an addition — do it immediately.
        remove_action('wp_head', 'print_emoji_detection_script', 7);
        remove_action('wp_print_styles', 'print_emoji_styles');

        $theme
            ->on('wp_head', [$this, 'pwaMeta'], 1)
            ->on('send_headers', [$this, 'noCacheLoggedIn'])
            ->on('init', [$this, 'blockPrefetchRequests'], 1)
            ->filter('wp_speculation_rules_configuration', '__return_null')
            ->filter('body_class', [$this, 'addPageStateClasses'])
            ->filter('show_admin_bar', [$this, 'hideAdminBarForLearners']);
    }

    public function hideAdminBarForLearners(bool $show): bool
    {
        if (!$show || !is_user_logged_in()) {
            return $show;
        }
        return current_user_can('stride_view');
    }

    public function pwaMeta(): void
    {
        ?>
    <meta name="theme-color" content="#1d4e89">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="Stride">
        <?php
    }

    public function noCacheLoggedIn(): void
    {
        if (!is_admin() && is_user_logged_in()) {
            nocache_headers();
            header('Vary: Cookie', false);
        }
    }

    public function blockPrefetchRequests(): void
    {
        $purpose = $_SERVER['HTTP_PURPOSE'] ?? $_SERVER['HTTP_SEC_PURPOSE'] ?? '';
        if (stripos($purpose, 'prefetch') !== false) {
            status_header(503);
            exit;
        }
    }

    public function addPageStateClasses(array $classes): array
    {
        if (is_front_page()) {
            $classes[] = 'stridence-homepage';
        }
        if (is_user_logged_in()) {
            $classes[] = 'stridence-logged-in';
        }
        return $classes;
    }
}
