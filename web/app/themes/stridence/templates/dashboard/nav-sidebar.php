<?php
/**
 * Dashboard Sidebar Navigation
 *
 * Full sidebar with brand, primary nav, utility nav, and user footer.
 * All data passed via $args from page-mijn-account.php.
 *
 * @param array $args {
 *     @type string  $current_tab  Active tab slug
 *     @type array   $primary_nav  Primary navigation items (Home, Opleidingen, Trajecten, Offertes)
 *     @type array   $utility_nav  Utility navigation items (Meldingen, Downloads, Certificaten)
 *     @type WP_User $user         Current user object
 *     @type int     $unread_count Notification badge count
 * }
 * @package stridence
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

$current_tab  = $args['current_tab'] ?? 'home';
$primary_nav  = $args['primary_nav'] ?? [];
$utility_nav  = $args['utility_nav'] ?? [];
$user         = $args['user'] ?? wp_get_current_user();
$unread_count = (int) ($args['unread_count'] ?? 0);

$base_url = get_permalink();
$firstName = explode(' ', trim($user->display_name))[0];
$initials = strtoupper(
    mb_substr($user->first_name ?: $firstName, 0, 1)
    . mb_substr($user->last_name ?: '', 0, 1)
) ?: '?';
?>

<aside class="sidebar" aria-label="<?php esc_attr_e('Dashboard navigatie', 'stridence'); ?>">

    <!-- Brand -->
    <div class="px-4 py-5">
        <a href="<?php echo esc_url(home_url('/')); ?>" class="text-lg font-bold tracking-tight text-text hover:text-primary transition-colors">
            Stride
        </a>
    </div>

    <!-- Primary Navigation -->
    <nav class="px-3 space-y-0.5">
        <?php foreach ($primary_nav as $slug => $item) :
            if (empty($item['visible'])) continue;

            $is_active = ($current_tab === $slug);
            $url = ($slug === 'home') ? $base_url : add_query_arg('tab', $slug, $base_url);
            $active_class = $is_active ? ' sidebar-nav-item-active' : '';
        ?>
            <a href="<?php echo esc_url($url); ?>"
               class="sidebar-nav-item<?php echo $active_class; ?>"
               <?php echo $is_active ? 'aria-current="page"' : ''; ?>>
                <?php echo stridence_icon($item['icon'], 'w-5 h-5 shrink-0'); ?>
                <span><?php echo esc_html($item['label']); ?></span>
            </a>
        <?php endforeach; ?>
    </nav>

    <!-- Divider -->
    <div class="sidebar-divider"></div>

    <!-- Utility Navigation -->
    <nav class="px-3 space-y-0.5">
        <?php foreach ($utility_nav as $slug => $item) :
            if (empty($item['visible'])) continue;

            $is_active = ($current_tab === $slug);
            $url = add_query_arg('tab', $slug, $base_url);
            $active_class = $is_active ? ' sidebar-nav-item-active' : '';
        ?>
            <a href="<?php echo esc_url($url); ?>"
               class="sidebar-nav-item<?php echo $active_class; ?>"
               <?php echo $is_active ? 'aria-current="page"' : ''; ?>>
                <?php echo stridence_icon($item['icon'], 'w-5 h-5 shrink-0'); ?>
                <span><?php echo esc_html($item['label']); ?></span>
                <?php if ($slug === 'meldingen' && $unread_count > 0) : ?>
                    <span class="sidebar-nav-badge"><?php echo esc_html((string) $unread_count); ?></span>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>
    </nav>

    <!-- Spacer -->
    <div class="flex-1"></div>

    <!-- User Footer -->
    <div class="sidebar-divider"></div>

    <div class="px-3 pb-4 space-y-1">
        <!-- User avatar + name -->
        <div class="sidebar-user">
            <span class="w-8 h-8 rounded-full bg-primary-subtle text-primary text-xs font-semibold flex items-center justify-center shrink-0">
                <?php echo esc_html($initials); ?>
            </span>
            <span class="text-sm font-medium text-text truncate"><?php echo esc_html($user->display_name); ?></span>
        </div>

        <!-- Profiel link -->
        <?php
            $profiel_active = ($current_tab === 'profiel');
            $profiel_class = $profiel_active ? ' sidebar-nav-item-active' : '';
        ?>
        <a href="<?php echo esc_url(add_query_arg('tab', 'profiel', $base_url)); ?>"
           class="sidebar-nav-item<?php echo $profiel_class; ?>"
           <?php echo $profiel_active ? 'aria-current="page"' : ''; ?>>
            <?php echo stridence_icon('user', 'w-5 h-5 shrink-0'); ?>
            <span><?php esc_html_e('Profiel', 'stridence'); ?></span>
        </a>

        <!-- Uitloggen link -->
        <a href="<?php echo esc_url(wp_logout_url(home_url('/'))); ?>"
           class="sidebar-nav-item text-error/70 hover:text-error hover:bg-error/5">
            <?php echo stridence_icon('log-out', 'w-5 h-5 shrink-0'); ?>
            <span><?php esc_html_e('Uitloggen', 'stridence'); ?></span>
        </a>
    </div>

</aside>
