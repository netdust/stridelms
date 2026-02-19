<?php
/**
 * Page Template
 *
 * Handles all pages, with special routing for dashboard pages.
 *
 * Dashboard pages (children of mijn-account) are routed to their
 * respective templates in templates/dashboard/
 *
 * @package stride
 */

defined('ABSPATH') || exit;

// Check if this is a dashboard page (child of mijn-account)
$mijnAccountPage = get_page_by_path('mijn-account');
$isDashboardPage = false;
$dashboardTemplate = null;

if ($mijnAccountPage) {
    $currentPage = get_queried_object();

    // Check if current page is mijn-account or a child of it
    if ($currentPage && $currentPage->ID === $mijnAccountPage->ID) {
        $isDashboardPage = true;
        $dashboardTemplate = 'home';
    } elseif ($currentPage && $currentPage->post_parent === $mijnAccountPage->ID) {
        $isDashboardPage = true;
        $dashboardTemplate = $currentPage->post_name;
    }
}

// Dashboard pages require login
if ($isDashboardPage && !is_user_logged_in()) {
    wp_redirect(wp_login_url(get_permalink()));
    exit;
}

get_header();

if ($isDashboardPage && $dashboardTemplate) {
    // Map page slugs to template files
    $templateMap = [
        'home' => 'templates/dashboard/home.php',
        'mijn-cursussen' => 'templates/dashboard/courses.php',
        'mijn-offertes' => 'templates/dashboard/quotes.php',
        'mijn-profiel' => 'templates/dashboard/profile.php',
        'mijn-agenda' => 'templates/dashboard/calendar.php',
        'mijn-trajecten' => 'templates/dashboard/trajectories.php',
    ];

    $templateFile = $templateMap[$dashboardTemplate] ?? null;

    if ($templateFile) {
        $template = locate_template($templateFile);
        if ($template) {
            echo '<div class="uk-container uk-container-large uk-margin-large-top uk-margin-large-bottom">';
            include $template;
            echo '</div>';
        } else {
            echo '<div class="uk-container uk-margin-large-top">';
            echo '<div class="uk-alert uk-alert-warning">';
            printf(esc_html__('Template %s niet gevonden.', 'stride'), esc_html($templateFile));
            echo '</div></div>';
        }
    } else {
        // Unknown dashboard page - show default content
        echo '<div class="uk-container uk-container-large uk-margin-large-top uk-margin-large-bottom">';
        while (have_posts()) {
            the_post();
            the_content();
        }
        echo '</div>';
    }
} else {
    // Regular page - show content
    ?>
    <div class="uk-container uk-container-large uk-margin-large-top uk-margin-large-bottom">
        <?php while (have_posts()) : the_post(); ?>
            <article id="post-<?php the_ID(); ?>" <?php post_class('stride-article'); ?>>
                <header class="stride-page-header uk-margin-medium-bottom">
                    <?php the_title('<h1 class="uk-heading-medium">', '</h1>'); ?>
                </header>

                <div class="stride-article-content">
                    <?php the_content(); ?>
                </div>
            </article>
        <?php endwhile; ?>
    </div>
    <?php
}

get_footer();
