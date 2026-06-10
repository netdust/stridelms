<?php
/**
 * Dashboard Floating Dock Navigation
 *
 * Floating icon sidebar for desktop that sits in the left viewport margin.
 * Expands on hover to show labels. Items are adaptive based on nav_items flags.
 *
 * @param array $args {
 *     @type string $current_tab   Active page slug
 *     @type array  $nav_items     Flags for which items to show
 * }
 * @package stridence
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

$current_tab = $args['current_tab'] ?? 'home';
$nav_items   = $args['nav_items'] ?? [];
$base_url    = get_permalink();

$items = [
    'home' => [
        'label'   => __('Home', 'stridence'),
        'icon'    => 'home',
        'url'     => $base_url,
        'visible' => true,
    ],
    'inschrijvingen' => [
        'label'   => __('Opleidingen', 'stridence'),
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
];

$bottom_items = [
    'profiel' => [
        'label'   => __('Profiel', 'stridence'),
        'icon'    => 'user',
        'url'     => add_query_arg('tab', 'profiel', $base_url),
        'visible' => true,
    ],
];
?>

<nav class="dock hidden lg:flex"
     x-data="{ expanded: false }"
     @mouseenter="expanded = true"
     @mouseleave="expanded = false"
     :class="{ 'expanded': expanded }"
     aria-label="<?php esc_attr_e('Dashboard navigatie', 'stridence'); ?>">

    <?php foreach ($items as $slug => $item) :
        if (!$item['visible']) {
            continue;
        }
        $is_active = ($current_tab === $slug);
        ?>
        <a href="<?php echo esc_url($item['url']); ?>"
           class="dock-item <?php echo $is_active ? 'active' : ''; ?>"
           <?php echo $is_active ? 'aria-current="page"' : ''; ?>
           title="<?php echo esc_attr($item['label']); ?>">
            <?php echo stridence_icon($item['icon'], 'w-5 h-5 shrink-0'); ?>
            <span class="dock-label"><?php echo esc_html($item['label']); ?></span>
        </a>
    <?php endforeach; ?>

    <div class="dock-separator"></div>

    <?php foreach ($bottom_items as $slug => $item) :
        if (!$item['visible']) {
            continue;
        }
        $is_active = ($current_tab === $slug);
        ?>
        <a href="<?php echo esc_url($item['url']); ?>"
           class="dock-item <?php echo $is_active ? 'active' : ''; ?>"
           <?php echo $is_active ? 'aria-current="page"' : ''; ?>
           title="<?php echo esc_attr($item['label']); ?>">
            <?php echo stridence_icon($item['icon'], 'w-5 h-5 shrink-0'); ?>
            <span class="dock-label"><?php echo esc_html($item['label']); ?></span>
        </a>
    <?php endforeach; ?>

</nav>
