<?php

namespace stride\services\admin;

defined('ABSPATH') || exit;

/**
 * Admin Menu Service
 *
 * Registers the main Stride admin menu that other services attach to.
 *
 * @package stride\services\admin
 */
class AdminMenuService implements \NTDST_Service_Meta
{
    public const MENU_SLUG = 'stride-admin';

    public static function metadata(): array
    {
        return [
            'name' => 'Admin Menu Service',
            'description' => 'Registers Stride admin menu',
            'admin_only' => true,
            'enabled' => true,
            'priority' => 5, // Load early so CPTs can attach
        ];
    }

    public function __construct()
    {
        add_action('admin_menu', [$this, 'registerMenu'], 5);
    }

    /**
     * Register the main Stride admin menu
     */
    public function registerMenu(): void
    {
        add_menu_page(
            __('Stride LMS', 'stride'),
            __('Stride', 'stride'),
            'edit_posts',
            self::MENU_SLUG,
            [$this, 'renderDashboard'],
            'dashicons-welcome-learn-more',
            25 // Position below Comments
        );

        // Add Dashboard submenu (same slug replaces default)
        add_submenu_page(
            self::MENU_SLUG,
            __('Dashboard', 'stride'),
            __('Dashboard', 'stride'),
            'edit_posts',
            self::MENU_SLUG,
            [$this, 'renderDashboard']
        );
    }

    /**
     * Render admin dashboard page
     */
    public function renderDashboard(): void
    {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Stride LMS', 'stride'); ?></h1>
            <p><?php esc_html_e('Welkom bij Stride LMS beheer.', 'stride'); ?></p>

            <div class="card">
                <h2><?php esc_html_e('Snelle links', 'stride'); ?></h2>
                <ul>
                    <li><a href="<?php echo esc_url(admin_url('edit.php?post_type=vad_quote')); ?>"><?php esc_html_e('Offertes beheren', 'stride'); ?></a></li>
                    <li><a href="<?php echo esc_url(admin_url('edit.php?post_type=vad_voucher')); ?>"><?php esc_html_e('Vouchers beheren', 'stride'); ?></a></li>
                    <li><a href="<?php echo esc_url(admin_url('edit.php?post_type=sfwd-courses')); ?>"><?php esc_html_e('Cursussen beheren', 'stride'); ?></a></li>
                </ul>
            </div>
        </div>
        <?php
    }
}
