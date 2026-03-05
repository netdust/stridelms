<?php
/**
 * Course Mobile CTA Template Part
 *
 * Sticky bottom CTA for mobile devices. Enrollment-aware:
 * - Enrolled online: continue/certificate
 * - Enrolled klassikaal: dashboard link
 * - Not enrolled: enroll button
 *
 * @param array $args {
 *     @type int    $course_id      Course post ID
 *     @type bool   $is_online      Whether course is online
 *     @type string $enrollment_url Enrollment URL (for in-person courses)
 *     @type bool   $user_enrolled  Whether user is enrolled in an edition (klassikaal)
 * }
 */

defined('ABSPATH') || exit;

use Stride\Integrations\LearnDash\LearnDashHelper;

$course_id      = $args['course_id'] ?? get_the_ID();
$is_online      = $args['is_online'] ?? false;
$enrollment_url = $args['enrollment_url'] ?? '';
$user_enrolled  = $args['user_enrolled'] ?? false;
$user_id        = get_current_user_id();

// Online course enrollment state
$has_access  = $is_online && $user_id && LearnDashHelper::hasAccess($course_id, $user_id);
$is_enrolled = $is_online && $user_id && LearnDashHelper::isEnrolled($course_id, $user_id);
$progress    = $has_access ? LearnDashHelper::getProgress($course_id, $user_id) : 0;
$is_complete = $has_access && $progress >= 100;
$is_open     = $is_online && LearnDashHelper::getAccessMode($course_id) === LearnDashHelper::MODE_OPEN;

?>
<div class="lg:hidden fixed bottom-0 inset-x-0 bg-surface border-t border-border p-4 z-40">
    <div class="container">
        <?php if ($is_online && $is_complete) : ?>
            <!-- Online completed: certificate -->
            <?php $cert_link = LearnDashHelper::getCertificateLink($course_id, $user_id); ?>
            <?php if ($cert_link) : ?>
                <a href="<?php echo esc_url($cert_link); ?>" target="_blank" class="btn btn-primary w-full text-center flex items-center justify-center gap-2">
                    <?php echo stridence_icon('download', 'w-4 h-4'); ?>
                    Certificaat downloaden
                </a>
            <?php else : ?>
                <span class="btn btn-ghost w-full text-center flex items-center justify-center gap-2 pointer-events-none">
                    <?php echo stridence_icon('check-circle', 'w-4 h-4 text-green-600'); ?>
                    Afgerond
                </span>
            <?php endif; ?>

        <?php elseif ($is_online && $has_access && $is_enrolled) : ?>
            <!-- Online enrolled, in progress: continue -->
            <a href="<?php echo esc_url(LearnDashHelper::getResumeUrl($course_id, $user_id)); ?>" class="btn btn-primary w-full text-center">
                <?php echo $progress > 0 ? 'Doorgaan' : 'Start cursus'; ?>
            </a>

        <?php elseif ($is_online && $is_open && $has_access) : ?>
            <!-- Open course: direct start -->
            <a href="<?php echo esc_url(LearnDashHelper::getFirstLessonUrl($course_id)); ?>" class="btn btn-primary w-full text-center">
                <?php esc_html_e('Start cursus', 'stridence'); ?>
            </a>

        <?php elseif ($is_online) : ?>
            <!-- Online not enrolled: LD payment or our own CTA -->
            <?php
            $ld_mobile_buttons = function_exists('learndash_payment_buttons') ? learndash_payment_buttons($course_id) : '';
            $mobile_price_type = LearnDashHelper::getAccessMode($course_id);
            if (!empty(trim($ld_mobile_buttons))) :
            ?>
                <div class="ld-course-buttons">
                    <?php echo $ld_mobile_buttons; ?>
                </div>
            <?php else :
                $mobile_cta_label = match ($mobile_price_type) {
                    LearnDashHelper::MODE_PAYNOW, LearnDashHelper::MODE_SUBSCRIBE => __('Cursus kopen', 'stridence'),
                    LearnDashHelper::MODE_FREE => __('Gratis inschrijven', 'stridence'),
                    default => __('Inschrijven', 'stridence'),
                };
            ?>
                <a href="<?php echo esc_url(is_user_logged_in() ? get_permalink($course_id) : wp_login_url(get_permalink($course_id))); ?>" class="btn btn-primary w-full text-center">
                    <?php echo esc_html($mobile_cta_label); ?>
                </a>
            <?php endif; ?>

        <?php elseif ($user_enrolled) : ?>
            <!-- Klassikaal enrolled: dashboard link -->
            <a href="<?php echo esc_url(home_url('/mijn-account/')); ?>" class="btn btn-primary w-full text-center flex items-center justify-center gap-2">
                <?php echo stridence_icon('layout-dashboard', 'w-4 h-4'); ?>
                Mijn dashboard
            </a>

        <?php elseif (!empty($enrollment_url)) : ?>
            <!-- Klassikaal not enrolled: enroll -->
            <a href="<?php echo esc_url($enrollment_url); ?>" class="btn btn-primary w-full text-center">
                <?php esc_html_e('Inschrijven', 'stridence'); ?>
            </a>
        <?php else : ?>
            <!-- No enrollable editions available -->
            <button type="button" class="btn btn-disabled w-full text-center" disabled>
                <?php esc_html_e('Geen beschikbare data', 'stridence'); ?>
            </button>
        <?php endif; ?>
    </div>
</div>
