<?php
/**
 * Dashboard Sidebar Navigation — Helder Tij collapsible rail.
 *
 * Expanded (240px) and collapsed rail (56px) render from the SAME markup;
 * Alpine's sidebarRail() factory toggles between them and persists the
 * choice in localStorage ('stride-rail'). Navigation stays real <a href>
 * links (server navigation) — Alpine only handles the collapse.
 *
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
    . mb_substr($user->last_name ?: '', 0, 1),
) ?: '?';
$organisation = (string) get_user_meta($user->ID, 'organisation', true);

// Shared nav item classes (one markup, both variants).
$item_base   = 'relative flex items-center rounded-md transition-colors duration-fast';
$item_active = $item_base . ' bg-badge-online-bg text-badge-online-text font-bold';
$item_idle   = $item_base . ' text-text-muted font-semibold hover:bg-surface-alt';
?>

<aside class="hidden lg:flex flex-col sticky top-0 h-screen shrink-0 bg-surface-card border-r border-border-soft py-5 overflow-hidden transition-[width] duration-normal"
       x-data="sidebarRail()"
       :class="collapsed ? 'w-14 px-2' : 'w-60 px-3.5'"
       aria-label="<?php esc_attr_e('Dashboard navigatie', 'stridence'); ?>">

    <!-- Logo row: wordmark + collapse toggle -->
    <div class="flex items-center pb-4" :class="collapsed ? 'justify-center' : 'justify-between px-2.5'">
        <a href="<?php echo esc_url(home_url('/')); ?>"
           x-show="!collapsed"
           class="text-xl font-extrabold tracking-tight text-text whitespace-nowrap"><?php echo esc_html(get_bloginfo('name')); ?><span class="text-primary">.</span></a>
        <button type="button"
                x-show="!collapsed"
                @click="toggle()"
                aria-label="<?php esc_attr_e('Zijbalk inklappen', 'stridence'); ?>"
                class="w-7 h-7 rounded-sm text-sm text-text-muted hover:bg-surface-alt transition-colors duration-fast">&laquo;</button>
        <button type="button"
                x-cloak
                x-show="collapsed"
                @click="toggle()"
                aria-label="<?php esc_attr_e('Zijbalk uitklappen', 'stridence'); ?>"
                class="w-8 h-8 rounded-sm text-sm text-text-muted hover:bg-surface-alt transition-colors duration-fast">&raquo;</button>
    </div>

    <!-- Primary Navigation -->
    <nav class="flex flex-col gap-0.5">
        <?php foreach ($primary_nav as $slug => $item) :
            if (empty($item['visible'])) {
                continue;
            }

            $is_active = ($current_tab === $slug);
            $url = ($slug === 'home') ? $base_url : add_query_arg('tab', $slug, $base_url);
            ?>
            <a href="<?php echo esc_url($url); ?>"
               class="<?php echo esc_attr($is_active ? $item_active : $item_idle); ?>"
               :class="collapsed ? 'justify-center' : 'gap-3 pr-3'"
               :title="collapsed ? <?php echo esc_attr(wp_json_encode($item['label'])); ?> : null"
               <?php echo $is_active ? 'aria-current="page"' : ''; ?>>
                <span class="w-[38px] h-[38px] grid place-items-center shrink-0<?php echo $is_active ? ' text-primary' : ''; ?>">
                    <?php echo stridence_icon($item['icon'], 'w-5 h-5'); ?>
                </span>
                <span x-show="!collapsed" class="flex-1 text-sm whitespace-nowrap"><?php echo esc_html($item['label']); ?></span>
            </a>
        <?php endforeach; ?>
    </nav>

    <!-- Divider -->
    <div class="border-t border-border-soft my-3"></div>

    <!-- Utility Navigation -->
    <nav class="flex flex-col gap-0.5">
        <?php foreach ($utility_nav as $slug => $item) :
            if (empty($item['visible'])) {
                continue;
            }

            $is_active = ($current_tab === $slug);
            $url = add_query_arg('tab', $slug, $base_url);
            $has_badge = ($slug === 'meldingen' && $unread_count > 0);
            ?>
            <a href="<?php echo esc_url($url); ?>"
               class="<?php echo esc_attr($is_active ? $item_active : $item_idle); ?>"
               :class="collapsed ? 'justify-center' : 'gap-3 pr-3'"
               :title="collapsed ? <?php echo esc_attr(wp_json_encode($item['label'])); ?> : null"
               <?php echo $is_active ? 'aria-current="page"' : ''; ?>>
                <span class="relative w-[38px] h-[38px] grid place-items-center shrink-0<?php echo $is_active ? ' text-primary' : ''; ?>">
                    <?php echo stridence_icon($item['icon'], 'w-5 h-5'); ?>
                    <?php if ($has_badge) : ?>
                        <span x-cloak
                              x-show="collapsed"
                              class="absolute top-1 right-1 w-[9px] h-[9px] rounded-full bg-accent ring-2 ring-surface-card"></span>
                    <?php endif; ?>
                </span>
                <span x-show="!collapsed" class="flex-1 text-sm whitespace-nowrap"><?php echo esc_html($item['label']); ?></span>
                <?php if ($has_badge) : ?>
                    <span x-show="!collapsed"
                          class="bg-accent text-white text-[11px] font-bold rounded-full px-1.5 py-px"><?php echo esc_html((string) $unread_count); ?></span>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>
    </nav>

    <!-- Profile row (bottom) -->
    <div class="mt-auto">
        <div class="border-t border-border-soft my-3"></div>
        <?php $profiel_active = ($current_tab === 'profiel'); ?>
        <a href="<?php echo esc_url(add_query_arg('tab', 'profiel', $base_url)); ?>"
           class="<?php echo esc_attr($profiel_active ? $item_active : $item_idle); ?>"
           :class="collapsed ? 'justify-center py-1' : 'gap-3 px-0.5 py-1'"
           :title="collapsed ? <?php echo esc_attr(wp_json_encode($user->display_name)); ?> : null"
           <?php echo $profiel_active ? 'aria-current="page"' : ''; ?>>
            <span class="w-[34px] h-[34px] rounded-full bg-accent-subtle text-accent-hover text-xs font-bold grid place-items-center shrink-0"><?php echo esc_html($initials); ?></span>
            <span x-show="!collapsed" class="min-w-0">
                <span class="block text-[13px] font-bold text-text truncate"><?php echo esc_html($user->display_name); ?></span>
                <?php if ($organisation !== '') : ?>
                    <span class="block text-[11px] font-normal text-text-muted truncate"><?php echo esc_html($organisation); ?></span>
                <?php endif; ?>
            </span>
        </a>

        <!-- Uitloggen -->
        <div class="border-t border-border-soft/60 my-2"></div>
        <a href="<?php echo esc_url(wp_logout_url(home_url('/'))); ?>"
           class="<?php echo esc_attr($item_idle); ?> text-error/70 hover:text-error hover:bg-error/5"
           :class="collapsed ? 'justify-center' : 'gap-3 pr-3'"
           title="<?php esc_attr_e('Uitloggen', 'stridence'); ?>">
            <span class="w-[38px] h-[38px] grid place-items-center shrink-0">
                <?php echo stridence_icon('log-out', 'w-5 h-5 shrink-0'); ?>
            </span>
            <span x-show="!collapsed" class="text-sm whitespace-nowrap"><?php esc_html_e('Uitloggen', 'stridence'); ?></span>
        </a>
    </div>

</aside>
