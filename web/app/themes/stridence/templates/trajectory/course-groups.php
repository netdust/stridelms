<?php
/**
 * Trajectory Course Groups Template
 *
 * Displays required and elective courses for a trajectory.
 *
 * @param array $args {
 *     @type array $required_courses   Array of WP_Post objects for required courses
 *     @type array $elective_courses   Array of WP_Post objects for elective courses
 *     @type int   $electives_required Number of electives that must be completed
 * }
 */

defined('ABSPATH') || exit;

$requiredCourses    = $args['required_courses'] ?? [];
$electiveCourses    = $args['elective_courses'] ?? [];
$electivesRequired  = (int) ($args['electives_required'] ?? 0);
?>

<?php if (!empty($requiredCourses)) : ?>
<div class="mb-8">
    <h3 class="font-heading font-semibold text-lg mb-4 flex items-center gap-2">
        <?php echo stridence_icon('check-circle', 'w-5 h-5 text-primary'); ?>
        Verplichte cursussen
    </h3>
    <div class="space-y-3">
        <?php foreach ($requiredCourses as $course) : ?>
            <a href="<?php echo esc_url(get_permalink($course)); ?>" class="card-interactive p-4 flex items-center gap-4">
                <!-- Thumbnail -->
                <div class="w-16 h-16 rounded overflow-hidden flex-shrink-0">
                    <?php if (has_post_thumbnail($course)) : ?>
                        <?php echo get_the_post_thumbnail($course, 'thumbnail', ['class' => 'w-full h-full object-cover']); ?>
                    <?php else : ?>
                        <div class="w-full h-full bg-surface-alt flex items-center justify-center">
                            <?php echo stridence_icon('book-open', 'w-6 h-6 text-text-muted'); ?>
                        </div>
                    <?php endif; ?>
                </div>
                <!-- Title + label -->
                <div class="flex-1 min-w-0">
                    <div class="font-medium text-text truncate"><?php echo esc_html(get_the_title($course)); ?></div>
                    <div class="text-sm text-text-muted">Verplicht</div>
                </div>
                <!-- Arrow -->
                <?php echo stridence_icon('chevron-right', 'w-5 h-5 text-text-muted flex-shrink-0'); ?>
            </a>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($electiveCourses)) : ?>
<div>
    <h3 class="font-heading font-semibold text-lg mb-4 flex items-center gap-2">
        <?php echo stridence_icon('list', 'w-5 h-5 text-accent'); ?>
        Keuzecursussen
        <?php if ($electivesRequired > 0) : ?>
            <span class="text-sm font-normal text-text-muted">(kies er <?php echo esc_html($electivesRequired); ?>)</span>
        <?php endif; ?>
    </h3>
    <div class="space-y-3">
        <?php foreach ($electiveCourses as $course) : ?>
            <a href="<?php echo esc_url(get_permalink($course)); ?>" class="card-interactive p-4 flex items-center gap-4">
                <!-- Thumbnail -->
                <div class="w-16 h-16 rounded overflow-hidden flex-shrink-0">
                    <?php if (has_post_thumbnail($course)) : ?>
                        <?php echo get_the_post_thumbnail($course, 'thumbnail', ['class' => 'w-full h-full object-cover']); ?>
                    <?php else : ?>
                        <div class="w-full h-full bg-surface-alt flex items-center justify-center">
                            <?php echo stridence_icon('book-open', 'w-6 h-6 text-text-muted'); ?>
                        </div>
                    <?php endif; ?>
                </div>
                <!-- Title + label -->
                <div class="flex-1 min-w-0">
                    <div class="font-medium text-text truncate"><?php echo esc_html(get_the_title($course)); ?></div>
                    <div class="text-sm text-text-muted">Keuzevak</div>
                </div>
                <!-- Arrow -->
                <?php echo stridence_icon('chevron-right', 'w-5 h-5 text-text-muted flex-shrink-0'); ?>
            </a>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>
