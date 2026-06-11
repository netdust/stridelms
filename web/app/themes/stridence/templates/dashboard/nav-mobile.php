<?php
/**
 * Dashboard Mobile Navigation — Helder Tij sticky top bar + nav chips.
 *
 * Replaces the old fixed bottom tab bar. Row 1: wordmark + avatar-initials
 * circle linking to the profiel tab. Row 2: horizontally scrollable chip
 * row with every primary + utility tab (same nav arrays the sidebar uses).
 * Navigation stays real <a href> links (server navigation) — Alpine only
 * scrolls the active chip into view on load.
 *
 * All data passed via $args from page-mijn-account.php.
 *
 * @param array $args {
 *     @type string $current_tab  Active tab slug
 *     @type array  $primary_nav  Primary navigation items (Home, Opleidingen, Trajecten, Offertes)
 *     @type array  $utility_nav  Utility navigation items (Meldingen, Downloads, Certificaten)
 *     @type string $initials     User initials for the avatar circle
 *     @type int    $unread_count Notification badge count
 * }
 * @package stridence
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

$current_tab  = $args['current_tab'] ?? 'home';
$primary_nav  = $args['primary_nav'] ?? [];
$utility_nav  = $args['utility_nav'] ?? [];
$initials     = (string) ($args['initials'] ?? '?');
$unread_count = (int) ($args['unread_count'] ?? 0);

$base_url = get_permalink();

// One chip per tab — primary first, then utility (profiel is reached via the avatar).
$tabs = $primary_nav + $utility_nav;

$chip_base     = 'shrink-0 inline-flex items-center rounded-full px-3.5 py-1.5 text-[13px] font-bold whitespace-nowrap';
$chip_active   = $chip_base . ' bg-primary text-white';
$chip_inactive = $chip_base . ' bg-surface-alt text-text-muted';
?>

<nav class="lg:hidden sticky top-0 z-20 bg-surface-card border-b border-border-soft"
     aria-label="<?php esc_attr_e('Dashboard navigatie', 'stridence'); ?>">

    <!-- Row 1: wordmark + profile avatar -->
    <div class="flex items-center justify-between px-5 py-3">
        <span class="text-base font-extrabold tracking-tight text-text"><?php echo esc_html(get_bloginfo('name')); ?><span class="text-primary">.</span></span>
        <a href="<?php echo esc_url(add_query_arg('tab', 'profiel', $base_url)); ?>"
           class="w-8 h-8 rounded-full bg-accent-subtle text-accent-hover text-[12px] font-bold grid place-items-center"
           aria-label="<?php esc_attr_e('Profiel', 'stridence'); ?>"
           <?php echo $current_tab === 'profiel' ? 'aria-current="page"' : ''; ?>><?php echo esc_html($initials); ?></a>
    </div>

    <!-- Row 2: scrollable nav chips (plain x-data so x-init runs on the active chip) -->
    <div x-data class="flex gap-2 overflow-x-auto px-5 pb-3 scrollbar-hide">
        <?php foreach ($tabs as $slug => $item) :
            if (empty($item['visible'])) {
                continue;
            }

            $is_active = ($current_tab === $slug);
            $url = ($slug === 'home') ? $base_url : add_query_arg('tab', $slug, $base_url);
            ?>
            <a href="<?php echo esc_url($url); ?>"
               class="<?php echo esc_attr($is_active ? $chip_active : $chip_inactive); ?>"
               <?php echo $is_active ? 'x-init="$el.scrollIntoView({inline:\'center\', block:\'nearest\'})" aria-current="page"' : ''; ?>>
                <?php echo esc_html($item['label']); ?>
                <?php if ($slug === 'meldingen' && $unread_count > 0) : ?>
                    <span class="bg-accent text-white text-[10px] font-bold rounded-full px-1.5 ml-1"><?php echo esc_html((string) $unread_count); ?></span>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>
    </div>
</nav>
