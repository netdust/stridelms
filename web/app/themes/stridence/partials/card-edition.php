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
$start_date      = $get('start_date');
$venue           = $get('venue') ?? $get('location');
$price           = $get('price');
$spots_remaining = $get('spots_remaining');
$status          = $get('status', 'open');

// Get course data
$course_link  = $course instanceof WP_Post ? get_permalink($course) : '#';
$course_title = $course instanceof WP_Post ? get_the_title($course) : 'Cursus';

// Get thumbnail
$thumbnail = null;
if ($course instanceof WP_Post) {
    $thumbnail = get_the_post_thumbnail(
        $course,
        'stride_course_card',
        ['class' => 'w-full h-full object-cover transition-transform hover:scale-105']
    );
}

// Determine if enrollment is available
$can_enroll = in_array($status, ['open', 'few_spots'], true);

?>
<article class="card overflow-hidden flex flex-col h-full">
    <!-- Thumbnail with badge overlay -->
    <a href="<?php echo esc_url($course_link); ?>" class="block aspect-video overflow-hidden relative bg-surface-alt">
        <?php if ($thumbnail): ?>
            <?php echo $thumbnail; ?>
        <?php else: ?>
            <div class="w-full h-full flex items-center justify-center">
                <?php echo stridence_icon('book-open', 'w-12 h-12 text-text-muted'); ?>
            </div>
        <?php endif; ?>
        <div class="absolute top-3 right-3">
            <?php
            get_template_part('partials/badge-status', null, [
                'status' => $status,
                'spots'  => $spots_remaining,
            ]);
            ?>
        </div>
    </a>

    <div class="p-5 flex-1 flex flex-col">
        <h3 class="font-heading font-semibold text-lg mb-3 line-clamp-2">
            <a href="<?php echo esc_url($course_link); ?>" class="text-text hover:text-primary transition-colors">
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
                    <span><?php echo esc_html(stride_format_money((int) $price)); ?></span>
                </div>
            <?php endif; ?>
        </div>

        <!-- CTA button -->
        <?php if ($can_enroll && $edition_id): ?>
            <a href="<?php echo esc_url(stride_enrollment_url((int) $edition_id)); ?>" class="btn-primary w-full text-center">
                Inschrijven
            </a>
        <?php else: ?>
            <button type="button" class="btn-secondary w-full text-center opacity-50 cursor-not-allowed" disabled>
                Niet beschikbaar
            </button>
        <?php endif; ?>
    </div>
</article>
