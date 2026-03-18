<?php
/**
 * Deep Link Course Picker Template
 *
 * Displays a list of LearnDash courses for selection during LTI Deep Linking.
 *
 * @var WP_Post[] $courses Array of course posts
 */

defined('ABSPATH') || exit;
?>
<div class="wrap">
    <h1><?php esc_html_e('Select Course', 'netdust-lti'); ?></h1>
    <p><?php esc_html_e('Choose a LearnDash course to add to the external LMS:', 'netdust-lti'); ?></p>

    <?php if (empty($courses)) : ?>
        <div class="notice notice-warning">
            <p><?php esc_html_e('No published courses found. Please create and publish a LearnDash course first.', 'netdust-lti'); ?></p>
        </div>
    <?php else : ?>
        <form method="post" action="<?php echo esc_url(home_url('/lti/deep-link-submit')); ?>">

            <table class="widefat striped">
                <thead>
                    <tr>
                        <th scope="col" class="check-column"></th>
                        <th scope="col"><?php esc_html_e('Course', 'netdust-lti'); ?></th>
                        <th scope="col"><?php esc_html_e('Description', 'netdust-lti'); ?></th>
                        <th scope="col"><?php esc_html_e('Status', 'netdust-lti'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($courses as $course) : ?>
                        <tr>
                            <td>
                                <input
                                    type="radio"
                                    name="course_id"
                                    id="course-<?php echo esc_attr($course->ID); ?>"
                                    value="<?php echo esc_attr($course->ID); ?>"
                                    required
                                >
                            </td>
                            <td>
                                <label for="course-<?php echo esc_attr($course->ID); ?>">
                                    <strong><?php echo esc_html($course->post_title); ?></strong>
                                </label>
                            </td>
                            <td>
                                <?php
                                $excerpt = $course->post_excerpt;
                                if (empty($excerpt)) {
                                    $excerpt = wp_trim_words(wp_strip_all_tags($course->post_content), 20, '...');
                                }
                                echo esc_html($excerpt ?: '—');
                                ?>
                            </td>
                            <td>
                                <span class="status-<?php echo esc_attr($course->post_status); ?>">
                                    <?php echo esc_html(ucfirst($course->post_status)); ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <p class="submit">
                <input
                    type="submit"
                    class="button button-primary"
                    value="<?php esc_attr_e('Add Course to LMS', 'netdust-lti'); ?>"
                >
            </p>
        </form>
    <?php endif; ?>
</div>

<style>
.widefat .check-column {
    width: 2.2em;
    padding: 10px;
}
.widefat td label {
    display: block;
    cursor: pointer;
}
.status-publish {
    color: #00a32a;
}
.status-draft {
    color: #996800;
}
</style>
