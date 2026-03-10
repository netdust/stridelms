<?php
/**
 * Dashboard Mobile Navigation
 *
 * Fixed bottom tab bar for mobile dashboard view.
 * Items are adaptive based on nav_items flags from getHomeData().
 * All data passed via $args - no service calls inside partials.
 *
 * @param array $args {
 *     @type string $current_tab Active tab slug
 *     @type array  $nav_items   Flags for which items to show
 * }
 * @package stridence
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

$current_tab = $args['current_tab'] ?? 'home';
$nav_items   = $args['nav_items'] ?? [];
$base_url    = get_permalink();

$tabs = [
    'home' => [
        'label'   => __('Home', 'stridence'),
        'icon'    => 'home',
        'url'     => $base_url,
        'visible' => true,
    ],
    'inschrijvingen' => [
        'label'   => __('Cursussen', 'stridence'),
        'icon'    => 'book-open',
        'url'     => add_query_arg('tab', 'inschrijvingen', $base_url),
        'visible' => !empty($nav_items['opleidingen']),
    ],
    'trajecten' => [
        'label'   => __('Trajecten', 'stridence'),
        'icon'    => 'layers',
        'url'     => add_query_arg('tab', 'trajecten', $base_url),
        'visible' => !empty($nav_items['trajecten']),
    ],
    'offertes' => [
        'label'   => __('Offertes', 'stridence'),
        'icon'    => 'file-text',
        'url'     => add_query_arg('tab', 'offertes', $base_url),
        'visible' => !empty($nav_items['offertes']),
    ],
    'certificaten' => [
        'label'   => __('Certificaten', 'stridence'),
        'icon'    => 'award',
        'url'     => add_query_arg('tab', 'certificaten', $base_url),
        'visible' => !empty($nav_items['certificaten']),
    ],
    'profiel' => [
        'label'   => __('Profiel', 'stridence'),
        'icon'    => 'user',
        'url'     => add_query_arg('tab', 'profiel', $base_url),
        'visible' => true,
    ],
];

// Filter to visible items only
$visible_tabs = array_filter($tabs, fn($tab) => $tab['visible']);
?>

<nav class="fixed bottom-0 left-0 right-0 bg-surface border-t border-border lg:hidden z-50 safe-area-bottom"
     aria-label="<?php esc_attr_e('Dashboard navigatie', 'stridence'); ?>">
    <div class="flex justify-around items-center h-16">
        <?php foreach ($visible_tabs as $slug => $tab) :
            $is_active = ($current_tab === $slug);

            $classes = $is_active
                ? 'flex flex-col items-center justify-center gap-0.5 px-2 py-2 text-primary min-w-0'
                : 'flex flex-col items-center justify-center gap-0.5 px-2 py-2 text-text-muted hover:text-text transition-colors min-w-0';
        ?>
            <a href="<?php echo esc_url($tab['url']); ?>"
               class="<?php echo esc_attr($classes); ?>"
               <?php echo $is_active ? 'aria-current="page"' : ''; ?>>
                <?php echo stridence_icon($tab['icon'], 'w-5 h-5'); ?>
                <span class="text-[10px] font-medium leading-tight truncate max-w-[64px]"><?php echo esc_html($tab['label']); ?></span>
            </a>
        <?php endforeach; ?>
    </div>
</nav>
