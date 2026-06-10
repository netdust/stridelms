<?php
/**
 * Dashboard Mobile Navigation
 *
 * Fixed bottom tab bar for mobile dashboard view.
 * Exactly 5 items, always visible: Home, Opleidingen, Meldingen, Downloads, Profiel.
 * All data passed via $args from page-mijn-account.php.
 *
 * @param array $args {
 *     @type string $current_tab  Active tab slug
 *     @type int    $unread_count Notification badge count
 * }
 * @package stridence
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

$current_tab  = $args['current_tab'] ?? 'home';
$unread_count = (int) ($args['unread_count'] ?? 0);
$base_url     = get_permalink();

$tabs = [
    'home' => [
        'label' => __('Home', 'stridence'),
        'icon'  => 'home',
        'url'   => $base_url,
    ],
    'inschrijvingen' => [
        'label' => __('Opleidingen', 'stridence'),
        'icon'  => 'book-open',
        'url'   => add_query_arg('tab', 'inschrijvingen', $base_url),
    ],
    'meldingen' => [
        'label' => __('Meldingen', 'stridence'),
        'icon'  => 'bell',
        'url'   => add_query_arg('tab', 'meldingen', $base_url),
    ],
    'downloads' => [
        'label' => __('Downloads', 'stridence'),
        'icon'  => 'download',
        'url'   => add_query_arg('tab', 'downloads', $base_url),
    ],
    'profiel' => [
        'label' => __('Profiel', 'stridence'),
        'icon'  => 'user',
        'url'   => add_query_arg('tab', 'profiel', $base_url),
    ],
];
?>

<nav class="fixed bottom-0 left-0 right-0 bg-surface border-t border-border lg:hidden z-50 safe-area-bottom"
     aria-label="<?php esc_attr_e('Dashboard navigatie', 'stridence'); ?>">
    <div class="flex justify-around items-center h-16">
        <?php foreach ($tabs as $slug => $tab) :
            $is_active = ($current_tab === $slug);

            $classes = $is_active
                ? 'relative flex flex-col items-center justify-center gap-0.5 px-2 py-2 text-primary min-w-0'
                : 'relative flex flex-col items-center justify-center gap-0.5 px-2 py-2 text-text-muted hover:text-text transition-colors min-w-0';
            ?>
            <a href="<?php echo esc_url($tab['url']); ?>"
               class="<?php echo esc_attr($classes); ?>"
               <?php echo $is_active ? 'aria-current="page"' : ''; ?>>
                <span class="relative">
                    <?php echo stridence_icon($tab['icon'], 'w-5 h-5'); ?>
                    <?php if ($slug === 'meldingen' && $unread_count > 0) : ?>
                        <span class="absolute -top-1.5 -right-1.5 bg-primary text-text-inverse text-[10px] font-bold leading-none rounded-full min-w-[16px] h-4 flex items-center justify-center px-1">
                            <?php echo esc_html((string) $unread_count); ?>
                        </span>
                    <?php endif; ?>
                </span>
                <span class="text-[10px] font-medium leading-tight truncate max-w-[64px]"><?php echo esc_html($tab['label']); ?></span>
            </a>
        <?php endforeach; ?>
    </div>
</nav>
