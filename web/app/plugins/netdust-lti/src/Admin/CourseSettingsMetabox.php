<?php
declare(strict_types=1);

namespace NetdustLTI\Admin;

use NetdustLTI\ToolProvider\Services\CourseGradeSettingsService;

final class CourseSettingsMetabox
{
    public function __construct(
        private readonly CourseGradeSettingsService $gradeSettings,
    ) {
        add_action('add_meta_boxes', [$this, 'register']);
        add_action('save_post_sfwd-courses', [$this, 'save']);
    }

    public function register(): void
    {
        add_meta_box(
            'netdust_lti_grade_settings',
            'LTI Grade Passback',
            [$this, 'render'],
            'sfwd-courses',
            'side',
            'default'
        );
    }

    public function render(\WP_Post $post): void
    {
        $settings = $this->gradeSettings->getSettings($post->ID);

        wp_nonce_field('netdust_lti_course_settings', 'netdust_lti_course_nonce');
        ?>
        <p>Push grades to external LMS when:</p>
        <label>
            <input type="checkbox" name="lti_grade[course_complete]" value="1"
                <?php checked(!empty($settings['course_complete'])); ?>>
            Course completed
        </label><br>
        <label>
            <input type="checkbox" name="lti_grade[quiz_score]" value="1"
                <?php checked(!empty($settings['quiz_score'])); ?>>
            Quiz completed
        </label><br>
        <label>
            <input type="checkbox" name="lti_grade[tincanny_complete]" value="1"
                <?php checked(!empty($settings['tincanny_complete'])); ?>>
            TinCanny module completed
        </label>
        <?php
    }

    public function save(int $postId): void
    {
        if (!isset($_POST['netdust_lti_course_nonce'])) {
            return;
        }

        if (!wp_verify_nonce($_POST['netdust_lti_course_nonce'], 'netdust_lti_course_settings')) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (!current_user_can('edit_post', $postId)) {
            return;
        }

        $settings = [];

        if (isset($_POST['lti_grade']) && is_array($_POST['lti_grade'])) {
            foreach ($_POST['lti_grade'] as $key => $value) {
                $settings[sanitize_key($key)] = 1;
            }
        }

        $this->gradeSettings->saveSettings($postId, $settings);
    }
}
