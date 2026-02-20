<?php
/**
 * Dashboard Navigation Panel Partial
 *
 * Desktop: Top-right card with icon + label rows
 * Mobile: Fixed bottom navbar with icons only
 *
 * @package stride
 */

defined('ABSPATH') || exit;

// Determine current page for active state (sanitized)
$currentUrl = isset($_SERVER['REQUEST_URI']) ? esc_url_raw($_SERVER['REQUEST_URI']) : '';

// Check if URL matches current page
$isActiveNav = function($url, $currentUrl) {
    return strpos($currentUrl, $url) !== false;
};

$navItems = [
    [
        'url' => '/mijn-account/mijn-cursussen/',
        'label' => __('Cursussen', 'stride'),
        'icon' => 'copy',
    ],
    [
        'url' => '/mijn-account/mijn-trajecten/',
        'label' => __('Trajecten', 'stride'),
        'icon' => 'git-branch',
    ],
    [
        'url' => '/mijn-account/mijn-offertes/',
        'label' => __('Offertes', 'stride'),
        'icon' => 'file-text',
    ],
    [
        'url' => '/mijn-account/mijn-profiel/',
        'label' => __('Profiel', 'stride'),
        'icon' => 'user',
    ],
    [
        'url' => '/mijn-account/kalender/',
        'label' => __('Kalender', 'stride'),
        'icon' => 'calendar',
    ],
];

?>

<!-- Desktop Navigation Panel (hidden on mobile) -->
<nav class="stride-nav-panel uk-visible@m" aria-label="<?php esc_attr_e('Dashboard navigatie', 'stride'); ?>">
    <ul class="stride-nav-panel__list">
        <?php foreach ($navItems as $item) :
            $isActive = $isActiveNav($item['url'], $currentUrl);
        ?>
            <li class="stride-nav-panel__item<?php echo $isActive ? ' stride-nav-panel__item--active' : ''; ?>">
                <a href="<?php echo esc_url(home_url($item['url'])); ?>" class="stride-nav-panel__link">
                    <span uk-icon="icon: <?php echo esc_attr($item['icon']); ?>; ratio: 1"></span>
                    <span class="stride-nav-panel__label"><?php echo esc_html($item['label']); ?></span>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
</nav>

<!-- Mobile Bottom Navbar (hidden on desktop) -->
<nav class="stride-bottom-navbar uk-hidden@m" aria-label="<?php esc_attr_e('Dashboard navigatie', 'stride'); ?>">
    <?php foreach ($navItems as $item) :
        $isActive = $isActiveNav($item['url'], $currentUrl);
    ?>
        <a href="<?php echo esc_url(home_url($item['url'])); ?>"
           class="stride-bottom-navbar__item<?php echo $isActive ? ' stride-bottom-navbar__item--active' : ''; ?>"
           aria-label="<?php echo esc_attr($item['label']); ?>">
            <span uk-icon="icon: <?php echo esc_attr($item['icon']); ?>; ratio: 1.2"></span>
        </a>
    <?php endforeach; ?>
</nav>
