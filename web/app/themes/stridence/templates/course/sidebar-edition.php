<?php
/**
 * Course Sidebar Edition Template
 *
 * Sticky sidebar card showing scheduled editions for a course.
 *
 * @param array $args {
 *     @type array $editions  Array of edition objects/arrays
 *     @type int   $course_id Course post ID
 * }
 */

defined('ABSPATH') || exit;

$editions  = $args['editions'] ?? [];
$course_id = $args['course_id'] ?? 0;

// TODO: Wire up EditionService when not passed
// if (empty($editions) && $course_id) {
//     $editionService = ntdst_get(\Stride\Modules\Edition\EditionService::class);
//     $editions = $editionService->getEditionsForCourse($course_id);
// }

?>
<aside class="card p-6 sticky top-24">
    <h3 class="font-heading font-semibold text-lg mb-4">Geplande sessies</h3>

    <?php if (!empty($editions)) : ?>
        <div class="space-y-4">
            <?php foreach ($editions as $edition) : ?>
                <?php
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

                // Determine if enrollment is available
                $can_enroll = in_array($status, ['open', 'few_spots'], true);
                ?>
                <div class="p-4 border border-border rounded-lg">
                    <!-- Date and status -->
                    <div class="flex items-start justify-between mb-2">
                        <div class="font-medium text-text">
                            <?php if ($start_date) : ?>
                                <?php echo esc_html(stride_format_date($start_date)); ?>
                            <?php else : ?>
                                <span class="text-text-muted">Datum nog niet bekend</span>
                            <?php endif; ?>
                        </div>
                        <?php
                        get_template_part('partials/badge-status', null, [
                            'status' => $status,
                            'spots'  => $spots_remaining,
                        ]);
                        ?>
                    </div>

                    <?php if ($venue) : ?>
                        <!-- Venue -->
                        <div class="text-sm text-text-muted mb-2 flex items-center gap-1">
                            <?php echo stridence_icon('map-pin', 'w-4 h-4 shrink-0'); ?>
                            <span><?php echo esc_html($venue); ?></span>
                        </div>
                    <?php endif; ?>

                    <!-- Price and CTA -->
                    <div class="flex items-center justify-between mt-3">
                        <?php if ($price !== null) : ?>
                            <span class="font-semibold text-text">
                                <?php echo esc_html(stride_format_money((int) $price)); ?>
                            </span>
                        <?php else : ?>
                            <span class="text-sm text-text-muted">Prijs op aanvraag</span>
                        <?php endif; ?>

                        <?php if ($can_enroll && $edition_id) : ?>
                            <a href="<?php echo esc_url(stride_enrollment_url((int) $edition_id)); ?>" class="btn-primary text-sm px-4 py-2">
                                Inschrijven
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else : ?>
        <p class="text-text-muted text-sm">
            Momenteel geen geplande sessies. Neem contact op voor meer informatie.
        </p>
        <a href="<?php echo esc_url(home_url('/contact/')); ?>" class="btn-ghost w-full mt-4 text-center block">
            Contact opnemen
        </a>
    <?php endif; ?>
</aside>
