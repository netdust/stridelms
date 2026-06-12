<?php

declare(strict_types=1);

namespace Stride\Admin;

/**
 * Admin Guide Page.
 *
 * Handleiding page under Stride menu explaining the system
 * concepts, workflows, and how CPTs relate to each other.
 *
 * Plain class — instantiated by AdminDashboardService.
 */
final class AdminGuidePage
{
    private const PAGE_SLUG = 'stride-handleiding';
    private const CAPABILITY = 'stride_view';

    public function __construct()
    {
        add_action('admin_menu', [$this, 'registerPage'], 90);
        add_action('admin_head', [$this, 'loadChrome']);
    }

    public function loadChrome(): void
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || !str_contains((string) $screen->id, self::PAGE_SLUG)) {
            return;
        }
        if (function_exists('stride_load_tool_chrome')) {
            stride_load_tool_chrome();
        }
    }

    public function registerPage(): void
    {
        add_submenu_page(
            'stride-dashboard',
            'Handleiding',
            'Handleiding',
            self::CAPABILITY,
            self::PAGE_SLUG,
            [$this, 'render'],
        );
    }

    public function render(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            return;
        }

        $templatePath = dirname(__DIR__) . '/templates/admin/handleiding.php';
        if (file_exists($templatePath)) {
            include $templatePath;
        }
    }
}
