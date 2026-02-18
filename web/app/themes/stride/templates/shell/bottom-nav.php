<?php
/**
 * Mobile Bottom Navigation Template
 *
 * Displays a fixed bottom navigation bar for logged-in mobile users.
 * Only visible on mobile devices (hidden on medium screens and up via CSS).
 *
 * @package stride
 */

defined('ABSPATH') || exit;

// Only show for logged-in users
if (!is_user_logged_in()) {
    return;
}

// Define navigation items
$nav_items = [
    [
        'url'   => home_url('/mijn-account/'),
        'icon'  => 'home',
        'label' => __('Home', 'stride'),
        'match' => '/mijn-account/$',
    ],
    [
        'url'   => home_url('/cursussen/'),
        'icon'  => 'album',
        'label' => __('Cursussen', 'stride'),
        'match' => '/cursussen/',
    ],
    [
        'url'   => home_url('/mijn-account/trajecten/'),
        'icon'  => 'git-branch',
        'label' => __('Traject', 'stride'),
        'match' => '/trajecten/',
    ],
    [
        'url'   => home_url('/mijn-account/agenda/'),
        'icon'  => 'calendar',
        'label' => __('Agenda', 'stride'),
        'match' => '/agenda/',
    ],
    [
        'url'   => home_url('/mijn-account/profiel/'),
        'icon'  => 'user',
        'label' => __('Profiel', 'stride'),
        'match' => '/profiel/',
    ],
];

// Get current URL path for active state detection
$current_path = wp_parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
?>

<nav class="stride-bottom-nav" aria-label="<?php esc_attr_e('Mobiele navigatie', 'stride'); ?>">
    <?php foreach ($nav_items as $item) : ?>
        <?php
        // Check if current item is active
        $is_active = false;
        if (!empty($item['match'])) {
            $is_active = (bool) preg_match('#' . $item['match'] . '#', $current_path);
        }
        $active_class = $is_active ? ' stride-bottom-nav__item--active' : '';
        ?>
        <a href="<?php echo esc_url($item['url']); ?>" class="stride-bottom-nav__item<?php echo esc_attr($active_class); ?>">
            <span class="stride-bottom-nav__icon" uk-icon="icon: <?php echo esc_attr($item['icon']); ?>; ratio: 1.2"></span>
            <span class="stride-bottom-nav__label"><?php echo esc_html($item['label']); ?></span>
        </a>
    <?php endforeach; ?>
</nav>
