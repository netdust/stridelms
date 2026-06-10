<?php
/**
 * Trajectory Course Groups Template
 *
 * Displays required and elective courses for a trajectory with expandable panels.
 *
 * @param array $args {
 *     @type array $required_courses  Array of WP_Post objects for required courses
 *     @type array $elective_groups   Array of groups: [{name, required, courses: [WP_Post]}]
 * }
 */

defined('ABSPATH') || exit;

$requiredCourses = $args['required_courses'] ?? [];
$electiveGroups  = $args['elective_groups'] ?? [];
?>

<?php if (!empty($requiredCourses)) : ?>
<div class="mb-8">
    <h3 class="font-heading font-semibold text-lg mb-4 flex items-center gap-2">
        <?php echo stridence_icon('check-circle', 'w-5 h-5 text-primary'); ?>
        <?php esc_html_e('Verplichte cursussen', 'stridence'); ?>
        <span class="text-sm font-normal text-text-muted">
            (<?php echo count($requiredCourses); ?>)
        </span>
    </h3>
    <div class="space-y-3">
        <?php foreach ($requiredCourses as $course) :
            $args = stridence_build_course_card_args_from_trajectory_course(
                $course,
                ['label' => __('Verplicht', 'stridence'), 'tone' => 'primary'],
            );
            get_template_part('templates/components/course-card', null, $args);
        endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($electiveGroups)) : ?>
    <?php foreach ($electiveGroups as $group) :
        $groupName = $group['name'] ?? __('Keuzecursussen', 'stridence');
        $required  = (int) ($group['required'] ?? 0);
        $courses   = $group['courses'] ?? [];
        if (empty($courses)) {
            continue;
        }
        ?>
    <div class="mb-8 last:mb-0">
        <h3 class="font-heading font-semibold text-lg mb-4 flex items-center gap-2">
            <?php echo stridence_icon('list', 'w-5 h-5 text-accent'); ?>
            <?php echo esc_html($groupName); ?>
            <?php if ($required > 0) : ?>
                <span class="text-sm font-normal text-text-muted">
                    (<?php printf(esc_html__('kies er %d van %d', 'stridence'), $required, count($courses)); ?>)
                </span>
            <?php endif; ?>
        </h3>
        <div class="space-y-3">
            <?php foreach ($courses as $course) :
                $args = stridence_build_course_card_args_from_trajectory_course(
                    $course,
                    ['label' => __('Keuzevak', 'stridence'), 'tone' => 'accent'],
                );
                get_template_part('templates/components/course-card', null, $args);
            endforeach; ?>
        </div>
    </div>
    <?php endforeach; ?>
<?php endif; ?>
