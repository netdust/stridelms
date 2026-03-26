<?php
/**
 * LTI Iframe Template
 *
 * Renders LearnDash content inside an LMS iframe using focus mode chrome
 * (header, sidebar, masthead, footer) but with our own content area.
 *
 * We avoid using focus/index.php directly because its the_content() call
 * triggers LearnDash/BuddyBoss template wrapping, causing content duplication.
 * Instead we use the focus template parts for chrome and render content ourselves.
 *
 * BuddyBoss hooks during wp_head() replace $wp_query with an empty one,
 * so we restore it afterwards to keep LearnDash functions working.
 */

defined('ABSPATH') || exit;

global $post;

// Navigation token for cookie-free iframe browsing
$lti_nav_token = $GLOBALS['lti_nav_token'] ?? '';

if (!$post) {
    echo '<p>No content found.</p>';
    return;
}

$post_type = $post->post_type;
$course_id = learndash_get_course_id($post->ID);
$user_id   = get_current_user_id();

// Enable LearnDash focus mode flag
global $learndash_in_focus_mode;
$learndash_in_focus_mode = true;

// Add body classes that BuddyBoss theme normally adds via its body_class filter.
// These may not fire correctly in the LTI template flow, so we add them explicitly.
add_filter('body_class', function (array $classes): array {
    $classes[] = 'bb-custom-ld-focus-mode-enabled';
    $classes[] = 'learndash-theme';
    $classes[] = 'lti-iframe-view';
    return $classes;
});

// Save the WP_Query before wp_head() destroys it (BuddyBoss replaces it).
// Restore at the very end of wp_head so LearnDash functions still work.
$lti_saved_query = clone $GLOBALS['wp_query'];
$lti_saved_post  = $post;
add_action('wp_head', function () use ($lti_saved_query, $lti_saved_post) {
    $GLOBALS['wp_query'] = $lti_saved_query;
    $GLOBALS['post']     = $lti_saved_post;
    setup_postdata($lti_saved_post);
}, PHP_INT_MAX);

// Inject CSS overrides into focus mode head
add_action('learndash-focus-head', function () {
    ?>
    <style>
        /* Hide navigation elements that don't belong in LTI iframe */
        .ld-focus-header .ld-user-menu,
        .ld-focus-header .ld-user-menu-items,
        #adminbar, #wpadminbar,
        .bb-mobile-header, .site-header, .bb-header, #masthead,
        .ld-focus-sidebar .ld-course-navigation-heading a,
        .ld-navigation__back-to-course,
        .ld-course-step-back { display: none !important; }
        .ld-brand-logo a { pointer-events: none; cursor: default; }
        html { margin-top: 0 !important; }
        /* Override admin-bar 32px offset — admin bar is hidden in LTI iframe */
        .lti-iframe-view .learndash-wrapper .ld-focus .ld-focus-header,
        .lti-iframe-view .learndash-wrapper .ld-focus .ld-focus-sidebar {
            top: 0 !important;
        }

        /* === Fix 1: Header / progress bar ===
         * With user-menu hidden and content-actions moved to bottom,
         * the header only contains: logo + progress.
         * Ensure proper height, padding and that the step counter stays inside.
         */
        /* Hide anything BuddyBoss injects before the focus wrapper */
        .lti-iframe-view body > *:not(.learndash-wrapper):not(script):not(style):not(link) {
            display: none !important;
        }
        .learndash-wrapper .ld-focus .ld-focus-header {
            position: sticky;
            top: 0;
            z-index: 1000;
            height: auto;
            min-height: 51px;
            padding: 0;
        }
        .learndash-wrapper .ld-focus .ld-focus-header .ld-progress {
            flex: 1 1 auto;
            padding: 8px 1em;
            margin: 0;
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 6px;
            overflow: visible;
            border-right: none;
        }
        .learndash-wrapper .ld-focus .ld-focus-header .ld-progress .ld-progress-stats {
            display: flex;
            align-items: center;
            gap: 8px;
            white-space: nowrap;
            flex-shrink: 0;
        }
        .learndash-wrapper .ld-focus .ld-focus-header .ld-progress .ld-progress-bar-wrapper,
        .learndash-wrapper .ld-focus .ld-focus-header .ld-progress .ld-progress-bar {
            flex: 1 1 100px;
            min-width: 60px;
        }
        .learndash-wrapper .ld-focus .ld-focus-header .ld-progress .ld-progress-percentage,
        .learndash-wrapper .ld-focus .ld-focus-header .ld-progress .ld-progress-steps {
            overflow: visible;
            text-overflow: ellipsis;
        }

        /* === Fix 2: Icons — ensure ld-icons font renders everywhere ===
         * BuddyBoss/theme may override font-family. Force ld-icons on all
         * icon elements: sidebar status icons, nav arrows, and content icons.
         */
        .lti-iframe-view .ld-icon,
        .lti-iframe-view .ld-icon:before,
        .lti-iframe-view .ld-status-icon .ld-icon,
        .lti-iframe-view .ld-status-icon .ld-icon:before,
        .lti-iframe-view .ld-focus-sidebar .ld-icon,
        .lti-iframe-view .ld-content-actions .ld-icon {
            font-family: 'ld-icons' !important;
            speak: none;
            font-style: normal;
            font-weight: normal;
            font-variant: normal;
            text-transform: none;
            line-height: 1;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
            display: inline-block;
        }

        /* === Fix 3: Bottom nav bar — compact, aligned ===
         * The content-actions bar at the bottom should be a compact strip
         * with properly sized prev/next buttons and arrow icons.
         */
        .ld-focus .ld-content-actions {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            width: 100%;
            z-index: 100;
            background: #fff;
            border-top: 1px solid #e7e9ec;
            padding: 0;
            margin: 0;
            display: flex;
            align-items: stretch;
            justify-content: space-between;
            box-sizing: border-box;
            height: 50px;
        }
        .ld-focus .ld-content-actions .ld-content-action {
            display: flex;
            align-items: center;
            justify-content: center;
            flex: 1;
            height: 50px;
            padding: 0 15px;
            border-right: 1px solid #e7e9ec;
        }
        .ld-focus .ld-content-actions .ld-content-action:last-child,
        .ld-focus .ld-content-actions .ld-content-action.ld-empty {
            border-right: none;
        }
        .ld-focus .ld-content-actions .ld-content-action a {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 14px;
            text-decoration: none;
            white-space: nowrap;
        }
        .ld-focus .ld-content-actions .ld-content-action .ld-icon {
            font-size: 14px;
        }
        /* Ensure main content doesn't get hidden behind the fixed bottom bar */
        .ld-focus .ld-focus-main .ld-focus-content {
            padding-bottom: 60px;
        }
    </style>
    <?php
});

if (in_array($post_type, ['sfwd-lessons', 'sfwd-topic', 'sfwd-quiz'], true)) {
    $template_args = [
        'course_id' => $course_id,
        'user_id'   => $user_id,
        'context'   => 'focus',
    ];

    // Focus chrome: header (includes <html><head>wp_head()</head><body>)
    learndash_get_template_part('focus/header.php', $template_args, true);

    // Focus chrome: sidebar with lesson navigation
    learndash_get_template_part('focus/sidebar.php', $template_args, true);

    ?>
    <div class="ld-focus-main" role="main">
        <?php
        // Focus chrome: masthead with progress bar and step navigation
        learndash_get_template_part('focus/masthead.php', $template_args, true);
        ?>
        <div id="ld-focus-content" class="ld-focus-content">
            <?php
            if ($post_type === 'sfwd-quiz') {
                // Render quiz directly — LearnDash's template_content filter
                // gets disabled by focus mode template parts (sidebar/masthead)
                // via content_filter_control(false), so it won't generate quiz HTML.
                // Call learndash_quiz_shortcode() directly instead.
                $quiz_pro_id = absint(get_post_meta($post->ID, 'quiz_pro_id', true));
                if (empty($quiz_pro_id)) {
                    $quiz_settings = learndash_get_setting($post->ID);
                    $quiz_pro_id = absint($quiz_settings['quiz_pro'] ?? 0);
                }
                echo apply_filters('the_content', $post->post_content);
                echo learndash_quiz_shortcode([
                    'quiz_id'     => $post->ID,
                    'course_id'   => $course_id,
                    'quiz_pro_id' => $quiz_pro_id,
                ]);
            } else {
                // For lessons/topics: remove LearnDash's template_content filter
                // to prevent it from wrapping content in the full lesson/topic template
                // (which causes content duplication, wrong navigation context, etc.)
                global $wp_filter;
                $saved_filters = [];
                $priority = defined('LEARNDASH_FILTER_PRIORITY_THE_CONTENT') ? LEARNDASH_FILTER_PRIORITY_THE_CONTENT : 30;
                if (isset($wp_filter['the_content']->callbacks[$priority])) {
                    $saved_filters = $wp_filter['the_content']->callbacks[$priority];
                    unset($wp_filter['the_content']->callbacks[$priority]);
                }

                echo apply_filters('the_content', $post->post_content);

                if (!empty($saved_filters)) {
                    $wp_filter['the_content']->callbacks[$priority] = $saved_filters;
                }
            }
            ?>
        </div>
    </div>
    <?php

    // Inject navigation JS via wp_footer (before </body>) so it runs reliably
    if ($lti_nav_token) {
        $__lti_token = $lti_nav_token;
        $__lti_post_id = $post->ID;
        add_action('wp_footer', function () use ($__lti_token, $__lti_post_id) {
            \NetdustLTI\Shared\TokenInjector::render($__lti_token, $__lti_post_id);
        }, PHP_INT_MAX);
    }

    // Focus chrome: footer (includes wp_footer(), </body>, </html>)
    learndash_get_template_part('focus/footer.php', $template_args, true);

} else {
    // Course overview pages: minimal layout
    ?>
    <!DOCTYPE html>
    <html <?php language_attributes(); ?>>
    <head>
        <meta charset="<?php bloginfo('charset'); ?>">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <?php wp_head(); ?>
        <style>
            #adminbar, #wpadminbar,
            .bb-mobile-header, .site-header, .bb-header, #masthead {
                display: none !important;
            }
            html, body { margin: 0; padding: 0; height: 100%; overflow-x: hidden; }
            html { margin-top: 0 !important; }
        </style>
    </head>
    <body <?php body_class('lti-iframe-view'); ?>>
        <?php setup_postdata($post); ?>
        <div class="<?php echo esc_attr(learndash_the_wrapper_class()); ?>">
            <div class="ld-focus">
                <div class="ld-focus-main" role="main" style="width:100%;max-width:100%;">
                    <div id="ld-focus-content" class="ld-focus-content" style="padding:2rem;">
                        <h1><?php echo esc_html($post->post_title); ?></h1>
                        <?php echo apply_filters('the_content', $post->post_content); ?>
                    </div>
                </div>
            </div>
        </div>
        <?php
        if ($lti_nav_token) {
            \NetdustLTI\Shared\TokenInjector::render($lti_nav_token, $post->ID);
        }
        wp_footer();
        ?>
    </body>
    </html>
    <?php
}
