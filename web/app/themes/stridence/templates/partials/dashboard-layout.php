<?php
/**
 * Dashboard Layout Partial
 *
 * Provides the sidebar (desktop) and bottom nav (mobile) structure.
 * Include this at the start of dashboard pages.
 *
 * @package stridence
 *
 * @var string $current_page Current page slug for active state
 */

defined('ABSPATH') || exit;

if (!is_user_logged_in()) {
    wp_redirect(wp_login_url(get_permalink()));
    exit;
}

$user = wp_get_current_user();
$avatar = get_avatar_url($user->ID, ['size' => 96]);
$initials = strtoupper(substr($user->display_name, 0, 1));

$current_page = $current_page ?? '';

$nav_items = [
    [
        'slug' => 'overview',
        'label' => __('Overzicht', 'stridence'),
        'icon' => 'home',
        'url' => home_url('/mijn-account/'),
    ],
    [
        'slug' => 'courses',
        'label' => __('Mijn cursussen', 'stridence'),
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
        'slug' => 'trajectories',
        'label' => __('Trajecten', 'stridence'),
        'icon' => 'gift',
        'url' => home_url('/mijn-account/trajecten/'),
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

<div class="str-dashboard">
    <div class="str-dashboard__container">
        <!-- Desktop Sidebar -->
        <aside class="str-dashboard__sidebar">
            <div class="str-dashboard__user">
                <div class="str-dashboard__avatar">
                    <?php if ($avatar): ?>
                        <img src="<?php echo esc_url($avatar); ?>" alt="<?php echo esc_attr($user->display_name); ?>">
                    <?php else: ?>
                        <?php echo esc_html($initials); ?>
                    <?php endif; ?>
                </div>
                <div class="str-dashboard__user-info">
                    <div class="str-dashboard__user-name"><?php echo esc_html($user->display_name); ?></div>
                    <div class="str-dashboard__user-email"><?php echo esc_html($user->user_email); ?></div>
                </div>
            </div>

            <nav class="str-dashboard__nav">
                <?php foreach ($nav_items as $item): ?>
                    <a href="<?php echo esc_url($item['url']); ?>"
                       class="str-dashboard__nav-item <?php echo $current_page === $item['slug'] ? 'str-dashboard__nav-item--active' : ''; ?>">
                        <span class="str-dashboard__nav-icon">
                            <?php stridence_icon($item['icon'], '', 20); ?>
                        </span>
                        <?php echo esc_html($item['label']); ?>
                    </a>
                <?php endforeach; ?>
            </nav>
        </aside>

        <!-- Main Content Area -->
        <main class="str-dashboard__main">
