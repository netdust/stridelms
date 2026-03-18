<?php
/**
 * Enrollment Card Partial
 *
 * Visual card with course thumbnail for the home screen.
 * Supports both edition (classroom) and online enrollment types.
 *
 * @param array $args {
 *     @type array $enrollment  Enrollment data from UserDashboardService::getHomeData()
 *     @type bool  $panel_enabled Whether clicking opens the side panel
 * }
 * @package stridence
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

$enrollment = $args['enrollment'] ?? [];
if (empty($enrollment)) {
    return;
}

$panelEnabled = !empty($args['panel_enabled']);

$type     = $enrollment['type'] ?? 'edition';
$title    = $enrollment['course_title'] ?? '';
$courseId = (int) ($enrollment['course_id'] ?? 0);
$progress = 0;

// Edition-specific fields
$startDate = $enrollment['start_date'] ?? '';
$venue     = $enrollment['venue'] ?? '';
$editionId = $enrollment['edition_id'] ?? 0;

// Online-specific fields
$courseUrl        = $enrollment['course_url'] ?? '';
$formatLabel     = $enrollment['format_label'] ?? __('Online', 'stridence');
$totalLessons    = (int) ($enrollment['total_lessons'] ?? 0);
$completedLessons = (int) ($enrollment['completed_lessons'] ?? 0);

// Badge
$badgeLabel = $type === 'edition' ? __('Klassikaal', 'stridence') : $formatLabel;
$badgeClass = $type === 'edition' ? 'bg-primary/10 text-primary' : 'bg-accent/10 text-accent';

// Progress percentage
if ($type === 'online') {
    $progress = (int) ($enrollment['progress'] ?? 0);
} elseif ($type === 'edition' && is_array($enrollment['progress'] ?? null)) {
    $required = (int) ($enrollment['progress']['required'] ?? 0);
    $attended = (int) ($enrollment['progress']['attended'] ?? 0);
    $progress = $required > 0 ? (int) round(($attended / $required) * 100) : 0;
}

// Course thumbnail
$thumbnail = '';
if ($courseId && has_post_thumbnail($courseId)) {
    $thumbnail = get_the_post_thumbnail_url($courseId, 'medium');
}
?>

<div class="dash-card-interactive !p-0 overflow-hidden flex flex-col"
     <?php if ($panelEnabled) : ?>
         @click="openPanel(<?php echo esc_attr(wp_json_encode($enrollment)); ?>)"
         role="button"
         tabindex="0"
         @keydown.enter="openPanel(<?php echo esc_attr(wp_json_encode($enrollment)); ?>)"
     <?php endif; ?>>

    <!-- Thumbnail -->
    <div class="aspect-[16/9] relative overflow-hidden rounded-t-xl">
        <?php if ($thumbnail) : ?>
            <img src="<?php echo esc_url($thumbnail); ?>"
                 alt=""
                 class="w-full h-full object-cover"
                 loading="lazy">
        <?php else : ?>
            <div class="w-full h-full flex items-center justify-center bg-gradient-to-br <?php echo $type === 'edition' ? 'from-primary/5 via-primary/10 to-primary/20' : 'from-accent/5 via-accent/10 to-accent/20'; ?>">
                <?php echo stridence_icon($type === 'edition' ? 'users' : 'monitor', 'w-9 h-9 ' . ($type === 'edition' ? 'text-primary/20' : 'text-accent/20')); ?>
            </div>
        <?php endif; ?>

        <!-- Badge overlay -->
        <span class="absolute top-2.5 left-2.5 inline-flex items-center px-2 py-0.5 rounded-md text-[11px] font-semibold <?php echo esc_attr($badgeClass); ?> bg-surface-card/90 backdrop-blur-sm shadow-sm">
            <?php echo esc_html($badgeLabel); ?>
        </span>

        <!-- Progress ring overlay -->
        <?php if ($progress > 0) : ?>
            <div class="absolute top-2.5 right-2.5 bg-surface-card/90 backdrop-blur-sm rounded-full p-0.5 shadow-sm">
                <?php
                stridence_template_part('templates/dashboard/partials/progress-ring', null, [
                    'progress' => $progress,
                    'size'     => 36,
                ]);
                ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Content -->
    <div class="p-4 flex-1 flex flex-col">
        <h3 class="font-semibold text-text line-clamp-2 mb-2">
            <?php echo esc_html($title); ?>
        </h3>

        <?php if ($type === 'edition') : ?>
            <div class="flex flex-wrap gap-x-3 gap-y-1 text-sm text-text-muted mt-auto">
                <?php if ($startDate) : ?>
                    <span class="flex items-center gap-1">
                        <?php echo stridence_icon('calendar', 'w-3.5 h-3.5'); ?>
                        <?php echo esc_html(stride_format_date($startDate)); ?>
                    </span>
                <?php endif; ?>
                <?php if ($venue) : ?>
                    <span class="flex items-center gap-1">
                        <?php echo stridence_icon('map-pin', 'w-3.5 h-3.5'); ?>
                        <?php echo esc_html($venue); ?>
                    </span>
                <?php endif; ?>
            </div>
        <?php else : ?>
            <?php if ($totalLessons > 0) : ?>
                <div class="mt-auto">
                    <div class="flex items-center justify-between text-xs text-text-muted mb-1.5">
                        <span><?php echo esc_html(sprintf(__('%d van %d lessen', 'stridence'), $completedLessons, $totalLessons)); ?></span>
                        <span><?php echo esc_html($progress . '%'); ?></span>
                    </div>
                    <div class="w-full h-1.5 bg-surface-alt rounded-full overflow-hidden">
                        <div class="h-full bg-primary rounded-full transition-all" style="width: <?php echo esc_attr($progress); ?>%"></div>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
