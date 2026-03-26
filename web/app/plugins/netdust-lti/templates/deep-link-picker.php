<?php
/**
 * Deep Link Course Picker Template
 *
 * Standalone HTML document for LTI Deep Linking course selection.
 * Renders inside a Moodle (or other LMS) iframe — must be self-contained.
 *
 * @var WP_Post[] $courses Array of course posts
 */

defined('ABSPATH') || exit;
?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr(get_locale()); ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php esc_html_e('Select Course', 'netdust-lti'); ?></title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        html, body {
            height: 100%;
            overflow: hidden;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen, Ubuntu, sans-serif;
            font-size: 14px;
            line-height: 1.5;
            color: #1d2327;
            background: #f0f0f1;
            display: flex;
            flex-direction: column;
        }
        .picker {
            display: flex;
            flex-direction: column;
            height: 100%;
            background: #fff;
            overflow: hidden;
        }
        .picker-header {
            padding: 20px 24px;
            border-bottom: 1px solid #e0e0e0;
            flex-shrink: 0;
        }
        .picker-header h1 {
            font-size: 20px;
            font-weight: 600;
            margin-bottom: 4px;
        }
        .picker-header p {
            color: #646970;
            font-size: 13px;
        }
        .course-list {
            list-style: none;
            flex: 1;
            overflow-y: auto;
            min-height: 0;
        }
        .course-item {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 14px 24px;
            border-bottom: 1px solid #f0f0f1;
            cursor: pointer;
            transition: background .15s;
        }
        .course-item:hover { background: #f6f7f7; }
        .course-item:has(input:checked) {
            background: #e7f5fe;
            border-color: #72aee6;
        }
        .course-item input[type="radio"] {
            margin-top: 3px;
            flex-shrink: 0;
            accent-color: #2271b1;
        }
        .course-info { flex: 1; min-width: 0; }
        .course-title {
            font-weight: 600;
            font-size: 14px;
            color: #1d2327;
        }
        .course-desc {
            font-size: 12px;
            color: #646970;
            margin-top: 2px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .picker-footer {
            padding: 16px 24px;
            border-top: 1px solid #e0e0e0;
            text-align: right;
            flex-shrink: 0;
        }
        .btn-submit {
            display: inline-block;
            background: #2271b1;
            color: #fff;
            border: none;
            padding: 8px 20px;
            font-size: 14px;
            font-weight: 500;
            border-radius: 4px;
            cursor: pointer;
            transition: background .15s;
        }
        .btn-submit:hover { background: #135e96; }
        .notice {
            padding: 16px 24px;
            color: #996800;
            background: #fcf9e8;
        }
    </style>
</head>
<body>
    <div class="picker">
        <div class="picker-header">
            <h1><?php esc_html_e('Select Course', 'netdust-lti'); ?></h1>
            <p><?php esc_html_e('Choose a LearnDash course to add to the external LMS:', 'netdust-lti'); ?></p>
        </div>

        <?php if (empty($courses)) : ?>
            <div class="notice">
                <?php esc_html_e('No published courses found. Please create and publish a LearnDash course first.', 'netdust-lti'); ?>
            </div>
        <?php else : ?>
            <form method="post" action="<?php echo esc_url(home_url('/lti/deep-link-submit')); ?>" id="deep-link-form" style="display:flex;flex-direction:column;flex:1;min-height:0;">
                <input type="hidden" name="dl_token" value="<?php echo esc_attr(sanitize_text_field($_GET['dl_token'] ?? '')); ?>">
                <ul class="course-list">
                    <?php foreach ($courses as $course) :
                        $excerpt = $course->post_excerpt;
                        if (empty($excerpt)) {
                            $excerpt = $course->post_content;
                        }
                        // Strip all HTML tags, then trim to 20 words
                        $excerpt = wp_trim_words(wp_strip_all_tags(strip_tags(html_entity_decode($excerpt))), 20, '...');
                    ?>
                        <li class="course-item" onclick="this.querySelector('input').checked=true">
                            <input
                                type="radio"
                                name="course_id"
                                id="course-<?php echo esc_attr($course->ID); ?>"
                                value="<?php echo esc_attr($course->ID); ?>"
                                required
                            >
                            <div class="course-info">
                                <label class="course-title" for="course-<?php echo esc_attr($course->ID); ?>">
                                    <?php echo esc_html($course->post_title); ?>
                                </label>
                                <?php if ($excerpt) : ?>
                                    <div class="course-desc"><?php echo esc_html($excerpt); ?></div>
                                <?php endif; ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>

                <div class="picker-footer">
                    <button type="submit" class="btn-submit">
                        <?php esc_html_e('Add Course to LMS', 'netdust-lti'); ?>
                    </button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>
