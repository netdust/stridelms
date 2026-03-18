<?php
/**
 * Course Sidebar Edition Template
 *
 * Sticky sidebar card showing scheduled editions for a course.
 * Shows enrollment-aware CTA per edition:
 * - Enrolled: "Ingeschreven" label + dashboard/calendar links
 * - Not enrolled: price + enroll button
 *
 * @param array $args {
 *     @type array $editions  Array of edition objects/arrays
 *     @type int   $course_id Course post ID
 * }
 */

defined('ABSPATH') || exit;

use Stride\Modules\Enrollment\EnrollmentService;

$editions  = $args['editions'] ?? [];
$course_id = $args['course_id'] ?? 0;
$user_id   = get_current_user_id();

// Check enrollment status for logged-in users
$enrollmentService = $user_id ? ntdst_get(EnrollmentService::class) : null;

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

                // Check if current user is enrolled in this edition
                $is_enrolled = $edition_id && $enrollmentService && $enrollmentService->isEnrolled($user_id, (int) $edition_id);

                // Determine if enrollment is available
                $can_enroll = !$is_enrolled && in_array($status, ['open', 'few_spots'], true);
                ?>
                <div class="p-4 border border-border rounded-lg <?php echo $is_enrolled ? 'border-primary/30 bg-primary/5' : ''; ?>">
                    <!-- Date and status -->
                    <div class="flex items-start justify-between mb-2">
                        <div class="font-medium text-text">
                            <?php if ($start_date) : ?>
                                <?php echo esc_html(stride_format_date($start_date)); ?>
                            <?php else : ?>
                                <span class="text-text-muted">Datum nog niet bekend</span>
                            <?php endif; ?>
                        </div>
                        <?php if ($is_enrolled) : ?>
                            <span class="inline-flex items-center gap-1 text-xs font-medium text-green-700 bg-green-100 px-2 py-0.5 rounded-full">
                                <?php echo stridence_icon('check-circle', 'w-3.5 h-3.5'); ?>
                                Ingeschreven
                            </span>
                        <?php else : ?>
                            <?php
                            stridence_template_part('partials/badge-status', null, [
                                'status' => $status,
                                'spots'  => $spots_remaining,
                            ]);
                            ?>
                        <?php endif; ?>
                    </div>

                    <?php if ($venue) : ?>
                        <!-- Venue -->
                        <div class="text-sm text-text-muted mb-2 flex items-center gap-1">
                            <?php echo stridence_icon('map-pin', 'w-4 h-4 shrink-0'); ?>
                            <span><?php echo esc_html($venue); ?></span>
                        </div>
                    <?php endif; ?>

                    <!-- CTA -->
                    <div class="mt-3">
                        <?php if ($is_enrolled) : ?>
                            <!-- Enrolled: show dashboard + calendar links -->
                            <div class="flex items-center gap-2">
                                <a href="<?php echo esc_url(home_url('/mijn-account/')); ?>" class="btn-ghost text-sm px-3 py-1.5 flex items-center gap-1.5">
                                    <?php echo stridence_icon('layout-dashboard', 'w-4 h-4'); ?>
                                    Mijn dashboard
                                </a>
                                <button type="button"
                                    class="btn-ghost text-sm px-3 py-1.5 flex items-center gap-1.5"
                                    title="Toevoegen aan agenda"
                                    onclick="ntdstAPI.call('stride_download_ical', { edition_id: <?php echo (int) $edition_id; ?> })">
                                    <?php echo stridence_icon('calendar', 'w-4 h-4'); ?>
                                </button>
                            </div>
                        <?php else : ?>
                            <!-- Not enrolled: price + enroll -->
                            <div class="flex items-center justify-between">
                                <?php if ($price !== null) : ?>
                                    <span class="font-semibold text-text">
                                        <?php echo esc_html(stride_format_money((int) ($price * 100))); ?>
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
