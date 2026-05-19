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
use Stride\Domain\OfferingStatus;

$course_id              = $args['course_id'] ?? get_the_ID();
$is_online              = $args['is_online'] ?? false;
$enrollment_url         = $args['enrollment_url'] ?? '';
$user_enrolled          = $args['user_enrolled'] ?? false;
$primary_edition_id     = (int) ($args['primary_edition_id'] ?? 0);
$primary_edition_status = $args['primary_edition_status'] ?? null;
$user_id                = get_current_user_id();

$has_edition = $primary_edition_id > 0 && $primary_edition_status instanceof OfferingStatus;

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
                    <?php echo stridence_icon('check-circle', 'w-4 h-4 text-status-success'); ?>
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

        <?php elseif ($is_online && $enrollment_url) : ?>
            <!-- Online not enrolled: Stride enrollment URL -->
            <a href="<?php echo esc_url($enrollment_url); ?>" class="btn btn-primary w-full text-center">
                <?php esc_html_e('Inschrijven', 'stridence'); ?>
            </a>

        <?php elseif ($is_online && $has_edition && $primary_edition_status->allowsInterest()) : ?>
            <a href="<?php echo esc_url(home_url('/interesse/?editie=' . $primary_edition_id)); ?>" class="btn btn-primary w-full text-center">
                <?php esc_html_e('Interesse melden', 'stridence'); ?>
            </a>

        <?php elseif ($is_online && $has_edition && $primary_edition_status->allowsWaitlist()) : ?>
            <a href="<?php echo esc_url(home_url('/wachtlijst/?editie=' . $primary_edition_id)); ?>" class="btn btn-primary w-full text-center">
                <?php esc_html_e('Op wachtlijst plaatsen', 'stridence'); ?>
            </a>

        <?php elseif ($is_online && $has_edition) : ?>
            <button type="button" class="btn btn-secondary w-full text-center opacity-50 cursor-not-allowed" disabled>
                <?php esc_html_e('Niet beschikbaar', 'stridence'); ?>
            </button>

        <?php elseif ($is_online) : ?>
            <!-- Online not enrolled, no edition: pure-LD enroll URL -->
            <?php
            $mobile_price_type = LearnDashHelper::getAccessMode($course_id);
            $mobile_cta_label = match ($mobile_price_type) {
                LearnDashHelper::MODE_PAYNOW, LearnDashHelper::MODE_SUBSCRIBE => __('Cursus kopen', 'stridence'),
                LearnDashHelper::MODE_FREE => __('Gratis inschrijven', 'stridence'),
                default => __('Inschrijven', 'stridence'),
            };
            $mobile_enroll_url = add_query_arg('enroll', '1', get_permalink($course_id));
            ?>
                <a href="<?php echo esc_url($mobile_enroll_url); ?>" class="btn btn-primary w-full text-center">
                    <?php echo esc_html($mobile_cta_label); ?>
                </a>

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
