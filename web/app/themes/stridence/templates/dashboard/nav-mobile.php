<?php
/**
 * Dashboard Mobile Navigation
 *
 * Fixed bottom tab bar for mobile dashboard view.
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

<nav class="fixed bottom-0 left-0 right-0 bg-surface border-t border-border lg:hidden z-40 safe-area-bottom"
     aria-label="<?php esc_attr_e('Dashboard navigatie', 'stridence'); ?>">
    <div class="flex justify-around items-center h-16">
        <?php foreach ($tabs as $slug => $tab) :
            $is_active = ($current_tab === $slug);
            $url = add_query_arg('tab', $slug, get_permalink());

            $classes = $is_active
                ? 'flex flex-col items-center justify-center gap-1 px-3 py-2 text-primary'
                : 'flex flex-col items-center justify-center gap-1 px-3 py-2 text-text-muted hover:text-text transition-colors';
        ?>
            <a href="<?php echo esc_url($url); ?>"
               class="<?php echo esc_attr($classes); ?>"
               <?php echo $is_active ? 'aria-current="page"' : ''; ?>>
                <?php echo stridence_icon($tab['icon'], 'w-6 h-6'); ?>
                <span class="text-xs font-medium hidden sm:block"><?php echo esc_html($tab['label']); ?></span>
            </a>
        <?php endforeach; ?>
    </div>
</nav>
