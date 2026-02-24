<?php
/**
 * Dashboard Sidebar Navigation
 *
 * Vertical navigation for desktop dashboard view.
 * All data passed via $args - no service calls inside partials.
 *
 * @param array $args {
 *     @type string $current_tab Active tab slug
 * }
 * @package stridence
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

$current_tab = $args['current_tab'] ?? 'inschrijvingen';

$tabs = [
    'inschrijvingen' => [
        'label' => __('Inschrijvingen', 'stridence'),
        'icon'  => 'calendar',
    ],
    'offertes' => [
        'label' => __('Offertes', 'stridence'),
        'icon'  => 'file-text',
    ],
    'certificaten' => [
        'label' => __('Certificaten', 'stridence'),
        'icon'  => 'award',
    ],
    'profiel' => [
        'label' => __('Profiel', 'stridence'),
        'icon'  => 'user',
    ],
];
?>

<nav class="space-y-1" aria-label="<?php esc_attr_e('Dashboard navigatie', 'stridence'); ?>">
    <?php foreach ($tabs as $slug => $tab) :
        $is_active = ($current_tab === $slug);
        $url = add_query_arg('tab', $slug, get_permalink());

        $classes = $is_active
            ? 'flex items-center gap-3 px-4 py-3 rounded-lg bg-primary/10 text-primary font-medium'
            : 'flex items-center gap-3 px-4 py-3 rounded-lg text-text-muted hover:text-text hover:bg-surface-alt transition-colors';
    ?>
        <a href="<?php echo esc_url($url); ?>"
           class="<?php echo esc_attr($classes); ?>"
           <?php echo $is_active ? 'aria-current="page"' : ''; ?>>
            <?php echo stridence_icon($tab['icon'], 'w-5 h-5 shrink-0'); ?>
            <span><?php echo esc_html($tab['label']); ?></span>
        </a>
    <?php endforeach; ?>
</nav>
