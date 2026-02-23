<?php
/**
 * Dashboard Layout Close Partial
 *
 * Closes the dashboard layout and adds bottom navigation.
 * Include this at the end of dashboard pages.
 *
 * @package stridence
 *
 * @var string $current_page Current page slug for active state
 */

defined('ABSPATH') || exit;

$current_page = $current_page ?? '';

$nav_items = [
    [
        'slug' => 'overview',
        'label' => __('Home', 'stridence'),
        'icon' => 'home',
        'url' => home_url('/mijn-account/'),
    ],
    [
        'slug' => 'courses',
        'label' => __('Cursussen', 'stridence'),
        'icon' => 'book',
        'url' => home_url('/mijn-account/cursussen/'),
    ],
    [
        'slug' => 'calendar',
        'label' => __('Agenda', 'stridence'),
        'icon' => 'calendar',
        'url' => home_url('/mijn-account/agenda/'),
    ],
    [
        'slug' => 'quotes',
        'label' => __('Offertes', 'stridence'),
        'icon' => 'file-text',
        'url' => home_url('/mijn-account/offertes/'),
    ],
    [
        'slug' => 'profile',
        'label' => __('Profiel', 'stridence'),
        'icon' => 'user',
        'url' => home_url('/mijn-account/profiel/'),
    ],
];
?>
        </main>
    </div>

    <!-- Mobile Bottom Navigation -->
    <nav class="str-bottom-nav">
        <?php foreach ($nav_items as $item): ?>
            <a href="<?php echo esc_url($item['url']); ?>"
               class="str-bottom-nav__item <?php echo $current_page === $item['slug'] ? 'str-bottom-nav__item--active' : ''; ?>">
                <span class="str-bottom-nav__icon">
                    <?php stridence_icon($item['icon'], '', 24); ?>
                </span>
                <?php echo esc_html($item['label']); ?>
            </a>
        <?php endforeach; ?>
    </nav>
</div>
