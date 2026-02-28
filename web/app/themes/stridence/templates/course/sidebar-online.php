<?php
/**
 * Course Sidebar Online Template
 *
 * Sticky sidebar for online courses. Shows enrollment-aware CTA:
 * - Not enrolled: price + enroll button (via LearnDash)
 * - Enrolled, in progress: progress bar + continue button
 * - Enrolled, completed: certificate download
 *
 * @param array $args {
 *     @type int $course_id Course post ID
 * }
 */

defined('ABSPATH') || exit;

use Stride\Integrations\LearnDash\LearnDashHelper;

$course_id = $args['course_id'] ?? get_the_ID();
$user_id   = get_current_user_id();

// Determine enrollment state via LearnDashHelper
$has_access = $user_id && LearnDashHelper::hasAccess($course_id, $user_id);
$progress   = $has_access ? LearnDashHelper::getProgress($course_id, $user_id) : 0;
$is_complete = $has_access && $progress >= 100;

// Get course price info for non-enrolled display
$course_price_type = [];
if (function_exists('learndash_get_course_price')) {
    $course_price_type = learndash_get_course_price($course_id);
}
$course_price = $course_price_type['price'] ?? '';
$price_type = $course_price_type['type'] ?? 'open';

?>
<aside class="card p-6 sticky top-24">
    <?php if ($is_complete) : ?>
        <!-- Completed state -->
        <div class="flex items-center gap-2 mb-4">
            <?php echo stridence_icon('check-circle', 'w-5 h-5 text-green-600'); ?>
            <h3 class="font-heading font-semibold text-lg text-green-700">Afgerond</h3>
        </div>

        <div class="space-y-4">
            <p class="text-sm text-text-muted">
                Je hebt deze cursus succesvol afgerond.
            </p>

            <?php
            $days_remaining = LearnDashHelper::getAccessDaysRemaining($course_id, $user_id);
            if ($days_remaining !== null) :
                $expiration_ts = LearnDashHelper::getAccessExpiration($course_id, $user_id);
            ?>
                <p class="text-xs text-text-muted">
                    <?php echo esc_html(sprintf(
                        __('Toegang tot %s', 'stridence'),
                        stride_format_date(date('Y-m-d', $expiration_ts))
                    )); ?>
                </p>
            <?php endif; ?>

            <?php $cert_link = LearnDashHelper::getCertificateLink($course_id, $user_id); ?>
            <?php if ($cert_link) : ?>
                <a href="<?php echo esc_url($cert_link); ?>" target="_blank" class="btn btn-primary w-full text-center flex items-center justify-center gap-2">
                    <?php echo stridence_icon('download', 'w-4 h-4'); ?>
                    Certificaat downloaden
                </a>
            <?php endif; ?>

            <a href="<?php echo esc_url(LearnDashHelper::getResumeUrl($course_id, $user_id)); ?>" class="btn btn-ghost w-full text-center">
                Cursus bekijken
            </a>
        </div>

    <?php elseif ($has_access) : ?>
        <!-- Enrolled, in progress -->
        <div class="flex items-center gap-2 mb-4">
            <?php echo stridence_icon('check-circle', 'w-5 h-5 text-primary'); ?>
            <h3 class="font-heading font-semibold text-lg">Ingeschreven</h3>
        </div>

        <div class="space-y-4">
            <!-- Progress bar -->
            <div>
                <div class="flex justify-between text-sm text-text-muted mb-1">
                    <span>Voortgang</span>
                    <span><?php echo esc_html($progress); ?>%</span>
                </div>
                <div class="w-full bg-gray-200 rounded-full h-2">
                    <div class="bg-primary rounded-full h-2 transition-all" style="width: <?php echo esc_attr($progress); ?>%"></div>
                </div>
            </div>

            <?php
            $days_remaining = LearnDashHelper::getAccessDaysRemaining($course_id, $user_id);
            if ($days_remaining !== null) :
                $expiration_ts = LearnDashHelper::getAccessExpiration($course_id, $user_id);
                $is_urgent = $days_remaining <= 14;
            ?>
                <div class="flex items-start gap-2 p-3 rounded-lg text-sm <?php echo $is_urgent ? 'bg-warning/10 text-warning-dark' : 'bg-surface-alt text-text-muted'; ?>">
                    <?php echo stridence_icon($is_urgent ? 'alert-circle' : 'clock', 'w-4 h-4 mt-0.5 shrink-0'); ?>
                    <div>
                        <span class="font-medium">
                            <?php echo esc_html(sprintf(
                                _n('Nog %d dag toegang', 'Nog %d dagen toegang', $days_remaining, 'stridence'),
                                $days_remaining
                            )); ?>
                        </span>
                        <?php if ($expiration_ts) : ?>
                            <span class="block text-xs mt-0.5">
                                <?php echo esc_html(sprintf(
                                    __('Vervalt op %s', 'stridence'),
                                    stride_format_date(date('Y-m-d', $expiration_ts))
                                )); ?>
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($progress > 0) : ?>
                <a href="<?php echo esc_url(LearnDashHelper::getResumeUrl($course_id, $user_id)); ?>" class="btn btn-primary w-full text-center">
                    Doorgaan
                </a>
            <?php else : ?>
                <a href="<?php echo esc_url(LearnDashHelper::getFirstLessonUrl($course_id)); ?>" class="btn btn-primary w-full text-center">
                    Start cursus
                </a>
            <?php endif; ?>
        </div>

    <?php else : ?>
        <!-- Not enrolled -->
        <h3 class="font-heading font-semibold text-lg mb-4">
            <?php esc_html_e('Direct starten', 'stridence'); ?>
        </h3>

        <div class="space-y-4">
            <!-- Price display -->
            <?php if ($price_type === 'open' || $price_type === 'free') : ?>
                <div class="text-2xl font-bold text-text">
                    <?php esc_html_e('Gratis', 'stridence'); ?>
                </div>
            <?php elseif (!empty($course_price)) : ?>
                <div class="text-2xl font-bold text-text">
                    <?php echo esc_html($course_price); ?>
                </div>
            <?php endif; ?>

            <?php
            if (LearnDashHelper::hasExpiration($course_id)) :
                $expire_days = function_exists('learndash_get_setting') ? (int) learndash_get_setting($course_id, 'expire_access_days') : 0;
                if ($expire_days > 0) :
            ?>
                <p class="text-sm text-text-muted">
                    <?php echo esc_html(sprintf(
                        __('%d dagen toegang na inschrijving', 'stridence'),
                        $expire_days
                    )); ?>
                </p>
            <?php
                endif;
            endif;
            ?>

            <!-- Benefits list -->
            <ul class="text-sm text-text-muted space-y-2">
                <li class="flex items-center gap-2">
                    <?php echo stridence_icon('check', 'w-4 h-4 text-green-600'); ?>
                    <?php esc_html_e('Direct toegang', 'stridence'); ?>
                </li>
                <li class="flex items-center gap-2">
                    <?php echo stridence_icon('check', 'w-4 h-4 text-green-600'); ?>
                    <?php esc_html_e('Leer in je eigen tempo', 'stridence'); ?>
                </li>
                <li class="flex items-center gap-2">
                    <?php echo stridence_icon('check', 'w-4 h-4 text-green-600'); ?>
                    <?php esc_html_e('Certificaat na afronding', 'stridence'); ?>
                </li>
                <?php
                $course_points = LearnDashHelper::getCoursePoints($course_id);
                if ($course_points > 0) :
                ?>
                <li class="flex items-center gap-2">
                    <?php echo stridence_icon('check', 'w-4 h-4 text-green-600'); ?>
                    <?php echo esc_html(sprintf(
                        _n('%d punt na afronding', '%d punten na afronding', $course_points, 'stridence'),
                        $course_points
                    )); ?>
                </li>
                <?php endif; ?>
            </ul>

            <!-- LearnDash native buttons - handles enrollment/payment -->
            <div class="ld-course-buttons">
                <?php echo do_shortcode('[learndash_payment_buttons course_id="' . esc_attr($course_id) . '"]'); ?>
            </div>

            <?php if (!is_user_logged_in()) : ?>
                <p class="text-xs text-text-muted text-center">
                    <?php esc_html_e('Nog geen account?', 'stridence'); ?>
                    <a href="<?php echo esc_url(wp_registration_url()); ?>" class="text-primary hover:underline">
                        <?php esc_html_e('Registreer hier', 'stridence'); ?>
                    </a>
                </p>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</aside>
