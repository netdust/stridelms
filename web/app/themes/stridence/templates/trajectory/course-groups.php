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
<div class="mb-6">
    <div class="flex items-center gap-2 mb-3">
        <?php echo stridence_icon('check-circle', 'w-4.5 h-4.5 text-primary'); ?>
        <span class="text-[13px] font-semibold text-text-muted uppercase tracking-wider">
            <?php esc_html_e('Verplichte cursussen', 'stridence'); ?>
        </span>
        <?php
        stridence_template_part('partials/badge-status', null, [
            'status' => 'confirmed',
            'size'   => 'sm',
        ]);
        ?>
        <span class="text-[12px] font-normal text-text-faint ml-1">
            (<?php echo count($requiredCourses); ?>)
        </span>
    </div>
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
    <div class="mb-6 last:mb-0">
        <div class="flex items-center gap-2 mb-3">
            <?php echo stridence_icon('list', 'w-4.5 h-4.5 text-accent'); ?>
            <span class="text-[13px] font-semibold text-text-muted uppercase tracking-wider">
                <?php echo esc_html($groupName); ?>
            </span>
            <?php if ($required > 0) : ?>
                <span class="text-[11px] font-bold px-[9px] py-[3px] rounded-full inline-flex items-center bg-accent-subtle text-accent-hover">
                    <?php printf(esc_html__('kies %d van %d', 'stridence'), $required, count($courses)); ?>
                </span>
            <?php endif; ?>
        </div>
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
