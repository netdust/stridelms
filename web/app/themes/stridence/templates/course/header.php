<?php
/**
 * Course Header Template Part
 *
 * @param array $args {
 *     @type int   $course_id   Course post ID
 *     @type array $breadcrumbs Breadcrumb items
 *     @type bool  $is_online   Whether course is online
 * }
 */

defined('ABSPATH') || exit;

$course_id   = $args['course_id'] ?? get_the_ID();
$breadcrumbs = $args['breadcrumbs'] ?? [];
$is_online   = $args['is_online'] ?? false;

?>
<div class="bg-surface-alt border-b border-border">
    <div class="container py-8 lg:py-12">
        <?php
        stridence_template_part('partials/breadcrumb', null, [
            'items' => $breadcrumbs,
        ]);
        ?>

        <!-- Format badge -->
        <div class="flex items-center gap-2 mb-4">
            <?php if ($is_online) : ?>
                <span class="inline-flex items-center gap-1 px-2 py-1 rounded-full text-xs font-medium bg-accent text-text-inverse">
                    <?php echo stridence_icon('wifi', 'w-3 h-3'); ?>
                    <?php esc_html_e('Online cursus', 'stridence'); ?>
                </span>
            <?php else : ?>
                <span class="inline-flex items-center gap-1 px-2 py-1 rounded-full text-xs font-medium bg-primary text-text-inverse">
                    <?php echo stridence_icon('map-pin', 'w-3 h-3'); ?>
                    <?php esc_html_e('Klassikaal', 'stridence'); ?>
                </span>
            <?php endif; ?>
        </div>

        <h1 class="font-heading text-3xl lg:text-4xl font-bold text-text mb-4">
            <?php echo get_the_title($course_id); ?>
        </h1>

        <?php if (has_excerpt($course_id)) : ?>
            <p class="text-lg text-text-muted max-w-3xl">
                <?php echo esc_html(get_the_excerpt($course_id)); ?>
            </p>
        <?php endif; ?>
    </div>
</div>
