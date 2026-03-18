<?php
/**
 * Edition Card Partial
 *
 * Renders an edition card with course context, including thumbnail with status badge,
 * course title, date, venue, price, and enrollment CTA.
 *
 * @param array $args {
 *     @type object|array $edition Edition object/array with id/ID, start_date, venue/location, price, spots_remaining, status
 *     @type WP_Post      $course  Optional course post for title and thumbnail
 * }
 */

defined('ABSPATH') || exit;

$edition = $args['edition'] ?? null;
$course  = $args['course'] ?? null;

// Early return if no edition
if (!$edition) {
    return;
}

// Helper to access edition properties (supports both object and array)
$get = function (string $key, $default = null) use ($edition) {
    if (is_object($edition)) {
        return $edition->{$key} ?? $default;
    }
    if (is_array($edition)) {
        return $edition[$key] ?? $default;
    }
    return $default;
};

// Get edition data
$edition_id      = $get('id') ?? $get('ID');
$edition_title   = $get('title');
$start_date      = $get('start_date');
$venue           = $get('venue') ?? $get('location');
$price           = $get('price');
$spots_remaining = $get('spots_remaining');
$status          = $get('status', 'open');
$course_id       = $get('course_id');

// Fetch course if not provided but course_id available
if (!$course && $course_id) {
    $course = get_post($course_id);
}

// Get course data - link to edition detail page, not course
$edition_link = $edition_id ? get_permalink($edition_id) : '#';
$course_title = ($course instanceof WP_Post ? get_the_title($course) : null) ?: $edition_title ?: 'Cursus';

// Get thumbnail
$thumbnail = null;
if ($course instanceof WP_Post) {
    $thumbnail = get_the_post_thumbnail(
        $course,
        'stride_course_card',
        ['class' => 'w-full h-full object-cover transition-transform hover:scale-105']
    );
}

// Check if current user is enrolled in this edition
$is_enrolled = false;
$enrolled_badge = null;
if (is_user_logged_in() && $edition_id) {
    $enrollmentService = ntdst_get(\Stride\Modules\Enrollment\EnrollmentService::class);
    $is_enrolled = $enrollmentService->isEnrolled(get_current_user_id(), (int) $edition_id);

    if ($is_enrolled && $course_id) {
        $progress = \Stride\Integrations\LearnDash\LearnDashHelper::getProgress((int) $course_id);
        if ($progress >= 100) {
            $enrolled_badge = ['class' => 'bg-green-600 text-white', 'label' => __('Afgerond', 'stridence'), 'icon' => 'check'];
        } elseif ($progress > 0) {
            $enrolled_badge = ['class' => 'bg-accent text-white', 'label' => sprintf(__('%d%% voltooid', 'stridence'), $progress), 'icon' => 'clock'];
        } else {
            $enrolled_badge = ['class' => 'bg-primary text-white', 'label' => __('Ingeschreven', 'stridence'), 'icon' => 'check'];
        }
    } elseif ($is_enrolled) {
        $enrolled_badge = ['class' => 'bg-primary text-white', 'label' => __('Ingeschreven', 'stridence'), 'icon' => 'check'];
    }
}

?>
<article class="card overflow-hidden flex flex-col h-full">
    <!-- Thumbnail with badge overlay -->
    <a href="<?php echo esc_url($edition_link); ?>" class="block aspect-video overflow-hidden relative bg-surface-alt">
        <?php if ($thumbnail): ?>
            <?php echo $thumbnail; ?>
        <?php else: ?>
            <div class="w-full h-full flex items-center justify-center">
                <?php echo stridence_icon('book-open', 'w-12 h-12 text-text-muted'); ?>
            </div>
        <?php endif; ?>
        <!-- Status badge -->
        <div class="absolute top-3 right-3">
            <?php if ($enrolled_badge) : ?>
                <span class="inline-flex items-center gap-1 px-2 py-1 rounded-full text-xs font-medium <?php echo esc_attr($enrolled_badge['class']); ?>">
                    <?php echo stridence_icon($enrolled_badge['icon'], 'w-3 h-3'); ?>
                    <?php echo esc_html($enrolled_badge['label']); ?>
                </span>
            <?php else : ?>
                <?php stridence_template_part('partials/badge-status', null, [
                    'status' => $status,
                    'spots'  => $spots_remaining,
                ]); ?>
            <?php endif; ?>
        </div>
    </a>

    <div class="p-5 flex-1 flex flex-col">
        <h3 class="font-heading font-semibold text-lg mb-3 line-clamp-2">
            <a href="<?php echo esc_url($edition_link); ?>" class="text-text hover:text-primary transition-colors">
                <?php echo esc_html($course_title); ?>
            </a>
        </h3>

        <div class="space-y-2 mb-4 flex-1">
            <?php if ($start_date): ?>
                <!-- Date -->
                <div class="flex items-center gap-2 text-sm text-text-muted">
                    <?php echo stridence_icon('calendar', 'w-4 h-4 shrink-0'); ?>
                    <span><?php echo esc_html(stride_format_date($start_date)); ?></span>
                </div>
            <?php endif; ?>

            <?php if ($venue): ?>
                <!-- Venue -->
                <div class="flex items-center gap-2 text-sm text-text-muted">
                    <?php echo stridence_icon('map-pin', 'w-4 h-4 shrink-0'); ?>
                    <span><?php echo esc_html($venue); ?></span>
                </div>
            <?php endif; ?>

            <?php if ($price !== null): ?>
                <!-- Price -->
                <div class="flex items-center gap-2 text-sm font-semibold text-text">
                    <?php echo stridence_icon('receipt', 'w-4 h-4 shrink-0 text-text-muted'); ?>
                    <span><?php echo esc_html(stride_format_money((int) ($price * 100))); ?></span>
                </div>
            <?php endif; ?>
        </div>

        <!-- CTA button -->
        <a href="<?php echo esc_url($edition_link); ?>" class="btn-primary w-full text-center">
            <?php esc_html_e('Meer info', 'stridence'); ?>
        </a>
    </div>
</article>
