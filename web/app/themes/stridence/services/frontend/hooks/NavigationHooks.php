<?php

declare(strict_types=1);

namespace stridence\services\frontend\hooks;

use NTDST_Theme;
use WP_Post;

/**
 * Highlights primary menu items on detail pages WordPress can't auto-resolve:
 * - sfwd-courses (online)       → "Online" page menu item
 * - sfwd-courses (classroom)    → "Klassikaal" page menu item
 * - sfwd-lessons / sfwd-topic   → same as parent course
 * - vad_trajectory              → "Trajecten" custom menu item
 * - vad_edition                 → follows linked course format
 *
 * Also registers the personal trajectory dashboard route.
 */
final class NavigationHooks
{
    public function bind(NTDST_Theme $theme): void
    {
        $theme
            ->filter('wp_nav_menu_objects', [$this, 'highlightActiveMenu'])
            ->on('init', [$this, 'registerTrajectoryRoute'], 20);
    }

    public function highlightActiveMenu(array $items): array
    {
        if (is_admin()) {
            return $items;
        }

        $target_slug = $this->getActiveMenuSlug();
        if (!$target_slug) {
            return $items;
        }

        foreach ($items as $item) {
            $match = ($target_slug === 'trajecten')
                ? $this->menuItemMatchesUrl($item, '/trajecten/')
                : $this->menuItemIsPage($item, $target_slug);

            if ($match) {
                $item->classes[] = 'current-menu-item';
            }
        }

        return $items;
    }

    public function registerTrajectoryRoute(): void
    {
        ntdst_router()->get('mijn-account/trajecten/:slug', function (array $params) {
            if (!is_user_logged_in()) {
                wp_safe_redirect(wp_login_url(home_url('/mijn-account/trajecten/' . $params['slug'] . '/')));
                exit;
            }

            get_header();
            stridence_template_part('templates/trajectory/dashboard', null, [
                'trajectory_slug' => sanitize_title($params['slug']),
                'user'            => wp_get_current_user(),
            ]);
            get_footer();
        });
    }

    private function getActiveMenuSlug(): string
    {
        if (is_singular('vad_trajectory')) {
            return 'trajecten';
        }

        $course_id = 0;

        if (is_singular('sfwd-courses')) {
            $course_id = (int) get_the_ID();
        } elseif (is_singular(['sfwd-lessons', 'sfwd-topic']) && function_exists('learndash_get_course_id')) {
            $course_id = (int) learndash_get_course_id(get_the_ID());
        } elseif (is_singular('vad_edition')) {
            $course_id = (int) get_post_meta(get_the_ID(), '_ntdst_course_id', true);
        }

        if (!$course_id) {
            return '';
        }

        return stridence_is_online_course($course_id) ? 'online' : 'klassikaal';
    }

    private function menuItemIsPage(object $item, string $slug): bool
    {
        if ($item->type === 'post_type' && $item->object === 'page') {
            $page = get_post($item->object_id);
            return $page instanceof WP_Post && $page->post_name === $slug;
        }
        return false;
    }

    private function menuItemMatchesUrl(object $item, string $path): bool
    {
        return str_contains($item->url ?? '', rtrim($path, '/'));
    }
}
