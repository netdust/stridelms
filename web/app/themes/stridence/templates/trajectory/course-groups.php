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

use Stride\Modules\Edition\EditionService;

$requiredCourses  = $args['required_courses'] ?? [];
$electiveGroups   = $args['elective_groups'] ?? [];

// Get edition service for upcoming editions
$editionService = ntdst_get(EditionService::class);
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
            $course_id = $course->ID;
            $excerpt = has_excerpt($course_id)
                ? get_the_excerpt($course_id)
                : wp_trim_words(get_post_field('post_content', $course_id), 25);

            // Get upcoming editions for this course
            $editions = $editionService->getEditionsForCourse($course_id);
            $upcoming_editions = array_filter($editions, function($ed) use ($editionService) {
                $edition_id = (int) ($ed['id'] ?? $ed['ID'] ?? 0);
                return $edition_id && $editionService->canEnroll($edition_id);
            });
            $next_edition = !empty($upcoming_editions) ? reset($upcoming_editions) : null;
        ?>
            <div class="card" x-data="expandable()">
                <button type="button"
                        class="w-full p-4 flex items-center gap-4 text-left"
                        @click="toggle()">
                    <!-- Thumbnail -->
                    <div class="w-14 h-14 rounded overflow-hidden flex-shrink-0">
                        <?php if (has_post_thumbnail($course)) : ?>
                            <?php echo get_the_post_thumbnail($course, 'thumbnail', ['class' => 'w-full h-full object-cover']); ?>
                        <?php else : ?>
                            <div class="w-full h-full bg-surface-alt flex items-center justify-center">
                                <?php echo stridence_icon('book-open', 'w-6 h-6 text-text-muted'); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <!-- Title + meta -->
                    <div class="flex-1 min-w-0">
                        <h4 class="font-semibold text-text truncate">
                            <?php echo esc_html(get_the_title($course)); ?>
                        </h4>
                        <div class="flex flex-wrap gap-3 mt-1 text-sm text-text-muted">
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-primary/10 text-primary">
                                <?php esc_html_e('Verplicht', 'stridence'); ?>
                            </span>
                            <?php if ($next_edition) :
                                $editionModel = ntdst_data()->get('vad_edition');
                                $start_date = $editionModel->getMeta((int) ($next_edition['id'] ?? $next_edition['ID']), 'start_date', '');
                                if ($start_date) : ?>
                                <span class="flex items-center gap-1">
                                    <?php echo stridence_icon('calendar', 'w-3.5 h-3.5'); ?>
                                    <?php echo esc_html(stride_format_date($start_date)); ?>
                                </span>
                            <?php endif; endif; ?>
                        </div>
                    </div>
                    <!-- Chevron -->
                    <span class="shrink-0 text-text-muted transition-transform duration-200"
                          :class="{ 'rotate-180': open }">
                        <?php echo stridence_icon('chevron-down', 'w-5 h-5'); ?>
                    </span>
                </button>

                <div x-show="open" x-collapse class="border-t border-border">
                    <div class="p-4 space-y-4">
                        <!-- Description -->
                        <?php if ($excerpt) : ?>
                            <p class="text-sm text-text-muted">
                                <?php echo esc_html($excerpt); ?>
                            </p>
                        <?php endif; ?>

                        <!-- Upcoming editions -->
                        <?php if (!empty($upcoming_editions)) : ?>
                            <div class="space-y-2">
                                <p class="text-xs font-medium text-text-muted uppercase tracking-wide">
                                    <?php esc_html_e('Beschikbare edities', 'stridence'); ?>
                                </p>
                                <div class="divide-y divide-border rounded-lg border border-border">
                                    <?php
                                    $editionModel = ntdst_data()->get('vad_edition');
                                    foreach (array_slice($upcoming_editions, 0, 3) as $ed) :
                                        $ed_id = (int) ($ed['id'] ?? $ed['ID'] ?? 0);
                                        $ed_start = $editionModel->getMeta($ed_id, 'start_date', '');
                                        $ed_venue = $editionModel->getMeta($ed_id, 'venue', '');
                                    ?>
                                        <div class="p-3 flex items-center justify-between gap-3 text-sm">
                                            <div class="flex items-center gap-3 text-text-muted">
                                                <?php if ($ed_start) : ?>
                                                    <span class="flex items-center gap-1">
                                                        <?php echo stridence_icon('calendar', 'w-4 h-4'); ?>
                                                        <?php echo esc_html(stride_format_date($ed_start)); ?>
                                                    </span>
                                                <?php endif; ?>
                                                <?php if ($ed_venue) : ?>
                                                    <span class="flex items-center gap-1">
                                                        <?php echo stridence_icon('map-pin', 'w-4 h-4'); ?>
                                                        <?php echo esc_html($ed_venue); ?>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php else : ?>
                            <p class="text-sm text-text-muted italic">
                                <?php esc_html_e('Nog geen edities gepland', 'stridence'); ?>
                            </p>
                        <?php endif; ?>

                        <!-- Actions -->
                        <div class="flex flex-wrap gap-3 pt-2">
                            <a href="<?php echo esc_url(get_permalink($course)); ?>"
                               class="btn-secondary text-sm">
                                <?php esc_html_e('Bekijk cursus', 'stridence'); ?>
                                <?php echo stridence_icon('chevron-right', 'w-4 h-4 ml-1'); ?>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($electiveGroups)) : ?>
    <?php foreach ($electiveGroups as $group) :
        $groupName = $group['name'] ?? __('Keuzecursussen', 'stridence');
        $required = (int) ($group['required'] ?? 0);
        $courses = $group['courses'] ?? [];
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
                $course_id = $course->ID;
                $excerpt = has_excerpt($course_id)
                    ? get_the_excerpt($course_id)
                    : wp_trim_words(get_post_field('post_content', $course_id), 25);

                // Get upcoming editions for this course
                $editions = $editionService->getEditionsForCourse($course_id);
                $upcoming_editions = array_filter($editions, function($ed) use ($editionService) {
                    $edition_id = (int) ($ed['id'] ?? $ed['ID'] ?? 0);
                    return $edition_id && $editionService->canEnroll($edition_id);
                });
                $next_edition = !empty($upcoming_editions) ? reset($upcoming_editions) : null;
            ?>
                <div class="card" x-data="expandable()">
                    <button type="button"
                            class="w-full p-4 flex items-center gap-4 text-left"
                            @click="toggle()">
                        <!-- Thumbnail -->
                        <div class="w-14 h-14 rounded overflow-hidden flex-shrink-0">
                            <?php if (has_post_thumbnail($course)) : ?>
                                <?php echo get_the_post_thumbnail($course, 'thumbnail', ['class' => 'w-full h-full object-cover']); ?>
                            <?php else : ?>
                                <div class="w-full h-full bg-surface-alt flex items-center justify-center">
                                    <?php echo stridence_icon('book-open', 'w-6 h-6 text-text-muted'); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <!-- Title + meta -->
                        <div class="flex-1 min-w-0">
                            <h4 class="font-semibold text-text truncate">
                                <?php echo esc_html(get_the_title($course)); ?>
                            </h4>
                            <div class="flex flex-wrap gap-3 mt-1 text-sm text-text-muted">
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-accent/10 text-accent">
                                    <?php esc_html_e('Keuzevak', 'stridence'); ?>
                                </span>
                                <?php if ($next_edition) :
                                    $editionModel = ntdst_data()->get('vad_edition');
                                    $start_date = $editionModel->getMeta((int) ($next_edition['id'] ?? $next_edition['ID']), 'start_date', '');
                                    if ($start_date) : ?>
                                    <span class="flex items-center gap-1">
                                        <?php echo stridence_icon('calendar', 'w-3.5 h-3.5'); ?>
                                        <?php echo esc_html(stride_format_date($start_date)); ?>
                                    </span>
                                <?php endif; endif; ?>
                            </div>
                        </div>
                        <!-- Chevron -->
                        <span class="shrink-0 text-text-muted transition-transform duration-200"
                              :class="{ 'rotate-180': open }">
                            <?php echo stridence_icon('chevron-down', 'w-5 h-5'); ?>
                        </span>
                    </button>

                    <div x-show="open" x-collapse class="border-t border-border">
                        <div class="p-4 space-y-4">
                            <!-- Description -->
                            <?php if ($excerpt) : ?>
                                <p class="text-sm text-text-muted">
                                    <?php echo esc_html($excerpt); ?>
                                </p>
                            <?php endif; ?>

                            <!-- Upcoming editions -->
                            <?php if (!empty($upcoming_editions)) : ?>
                                <div class="space-y-2">
                                    <p class="text-xs font-medium text-text-muted uppercase tracking-wide">
                                        <?php esc_html_e('Beschikbare edities', 'stridence'); ?>
                                    </p>
                                    <div class="divide-y divide-border rounded-lg border border-border">
                                        <?php
                                        $editionModel = ntdst_data()->get('vad_edition');
                                        foreach (array_slice($upcoming_editions, 0, 3) as $ed) :
                                            $ed_id = (int) ($ed['id'] ?? $ed['ID'] ?? 0);
                                            $ed_start = $editionModel->getMeta($ed_id, 'start_date', '');
                                            $ed_venue = $editionModel->getMeta($ed_id, 'venue', '');
                                        ?>
                                            <div class="p-3 flex items-center justify-between gap-3 text-sm">
                                                <div class="flex items-center gap-3 text-text-muted">
                                                    <?php if ($ed_start) : ?>
                                                        <span class="flex items-center gap-1">
                                                            <?php echo stridence_icon('calendar', 'w-4 h-4'); ?>
                                                            <?php echo esc_html(stride_format_date($ed_start)); ?>
                                                        </span>
                                                    <?php endif; ?>
                                                    <?php if ($ed_venue) : ?>
                                                        <span class="flex items-center gap-1">
                                                            <?php echo stridence_icon('map-pin', 'w-4 h-4'); ?>
                                                            <?php echo esc_html($ed_venue); ?>
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php else : ?>
                                <p class="text-sm text-text-muted italic">
                                    <?php esc_html_e('Nog geen edities gepland', 'stridence'); ?>
                                </p>
                            <?php endif; ?>

                            <!-- Actions -->
                            <div class="flex flex-wrap gap-3 pt-2">
                                <a href="<?php echo esc_url(get_permalink($course)); ?>"
                                   class="btn-secondary text-sm">
                                    <?php esc_html_e('Bekijk cursus', 'stridence'); ?>
                                    <?php echo stridence_icon('chevron-right', 'w-4 h-4 ml-1'); ?>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endforeach; ?>
<?php endif; ?>
