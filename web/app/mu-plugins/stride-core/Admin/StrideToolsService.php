<?php

declare(strict_types=1);

namespace Stride\Admin;

use Stride\Infrastructure\AbstractService;

/**
 * Stride Tools menu — second top-level menu containing cross-cutting infra
 * tools (Mail, Audit, Auth, LTI, ...). External plugins target the
 * 'stride-tools' parent slug. They additionally register themselves in the
 * index page via the `stride_tools_menu_items` filter.
 *
 * Plugin registration shape:
 *
 *   add_filter('stride_tools_menu_items', function (array $items): array {
 *       $items[] = [
 *           'slug'        => 'netdust-mail',                  // page slug
 *           'label'       => 'Mail',                          // card title
 *           'description' => 'E-mail templates en bezorging.',
 *           'icon'        => 'dashicons-email-alt',
 *           'capability'  => 'manage_options',
 *       ];
 *       return $items;
 *   });
 */
final class StrideToolsService extends AbstractService
{
    public const MENU_SLUG = 'stride-tools';
    private const CAPABILITY = 'manage_options';

    public static function metadata(): array
    {
        return [
            'name'        => 'Stride Tools Menu',
            'description' => 'Top-level admin menu for cross-cutting tool plugins',
            'admin_only'  => true,
            'enabled'     => true,
            'priority'    => 5,
        ];
    }

    protected function getConfigSlug(): string
    {
        return 'stride_tools';
    }

    protected function init(): void
    {
        // Priority 1: parent menu MUST be registered before plugin submenus.
        // WP's add_submenu_page fails silently and registers a hidden
        // admin_page_* hook (with broken cap enforcement) if the parent
        // slug doesn't exist yet at registration time.
        add_action('admin_menu', [$this, 'registerMenu'], 1);
        add_action('admin_head', [$this, 'loadChrome']);
    }

    public function registerMenu(): void
    {
        add_menu_page(
            'Stride Tools',
            'Stride Tools',
            self::CAPABILITY,
            self::MENU_SLUG,
            [$this, 'renderIndex'],
            'dashicons-admin-tools',
            3,
        );

        // Explicit 'Overzicht' submenu so the index page is reachable once
        // other plugins add their own submenus underneath.
        add_submenu_page(
            self::MENU_SLUG,
            'Overzicht',
            'Overzicht',
            self::CAPABILITY,
            self::MENU_SLUG,
            [$this, 'renderIndex'],
        );
    }

    /**
     * Inline the shared --sd-* tokens + tool-chrome CSS on any Stride Tools page.
     */
    public function loadChrome(): void
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || !is_string($screen->id) || !str_contains($screen->id, self::MENU_SLUG)) {
            return;
        }
        if (function_exists('stride_load_tool_chrome')) {
            stride_load_tool_chrome();
        }
    }

    public function renderIndex(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            return;
        }

        $items = stride_tools_menu_items();
        $templatePath = dirname(__DIR__) . '/templates/admin/tools-index.php';
        if (file_exists($templatePath)) {
            include $templatePath;
        }
    }
}
