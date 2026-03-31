<?php
/**
 * Course Sidebar Online Template
 *
 * Sticky sidebar for online courses. Three states:
 * 1. Completed  — certificate download + review link
 * 2. Enrolled   — progress bar + continue/start button
 * 3. Not enrolled — price + CTA (LD payment buttons or own fallback)
 *
 * Below the CTA: course details (points, expiration, dates) in all states.
 *
 * @param array $args {
 *     @type int    $course_id      Course post ID
 *     @type string $enrollment_url Stride enrollment URL (edition-based)
 * }
 */

defined('ABSPATH') || exit;

use Stride\Integrations\LearnDash\LearnDashHelper;
use Stride\Domain\Money;

$course_id       = $args['course_id'] ?? get_the_ID();
$enrollment_url  = $args['enrollment_url'] ?? '';
$stride_enrolled = $args['user_enrolled'] ?? false;
$edition_price   = $args['edition_price'] ?? null; // Money object from edition
$user_id         = get_current_user_id();

// ── Enrollment state ──
// Check both LearnDash access AND Stride registration (covers sync delays)
$has_access  = $user_id && (LearnDashHelper::hasAccess($course_id, $user_id) || $stride_enrolled);
$is_enrolled = $user_id && (LearnDashHelper::isEnrolled($course_id, $user_id) || $stride_enrolled);
$progress    = $has_access ? LearnDashHelper::getProgress($course_id, $user_id) : 0;
$is_complete = $has_access && $progress >= 100;
$is_open     = LearnDashHelper::getAccessMode($course_id) === LearnDashHelper::MODE_OPEN;

// ── Price info (for not-enrolled state) ──
// Prefer Stride edition price over LearnDash price (LD returns null for closed-type courses)
$has_edition_price = $edition_price instanceof Money && !$edition_price->isZero();

$price_info = function_exists('learndash_get_course_price')
    ? learndash_get_course_price($course_id)
    : [];
$price_type   = $has_edition_price ? 'paynow' : ($price_info['type'] ?? 'open');
$course_price = $price_info['price'] ?? '';

// Format price display
if ($has_edition_price) {
    $price_formatted = $edition_price->format();
} else {
    $price_formatted = !empty($course_price)
        ? '€ ' . number_format((float) $course_price, 2, ',', '.')
        : '';
}

// Subscription billing text
$billing_text = '';
$trial_text   = '';
if ($price_type === 'subscribe' && !empty($course_price)) {
    $freq_map = [
        'D' => ['dag', 'dagen'],
        'W' => ['week', 'weken'],
        'M' => ['maand', 'maanden'],
        'Y' => ['jaar', 'jaar'],
    ];
    $interval  = (int) ($price_info['interval'] ?? 1);
    $freq_raw  = $price_info['frequency_raw'] ?? 'M';
    $freq_pair = $freq_map[$freq_raw] ?? ['maand', 'maanden'];
    $freq_label = $interval === 1 ? $freq_pair[0] : $freq_pair[1];
    $billing_text = $interval === 1
        ? sprintf('per %s', $freq_label)
        : sprintf('per %d %s', $interval, $freq_label);

    $trial_price = $price_info['trial_price'] ?? '';
    if ($trial_price !== '' && $trial_price !== '0') {
        $trial_text = sprintf('Proefperiode: € %s', number_format((float) $trial_price, 2, ',', '.'));
    }
}

// CTA label (fallback when LD payment buttons are empty)
$cta_label = match ($price_type) {
    'paynow'    => __('Cursus kopen', 'stridence'),
    'subscribe' => __('Abonneren', 'stridence'),
    'free'      => __('Gratis inschrijven', 'stridence'),
    default     => __('Inschrijven', 'stridence'),
};

// LD payment buttons (non-empty when Stripe/PayPal is configured)
$ld_buttons = function_exists('learndash_payment_buttons')
    ? trim(learndash_payment_buttons($course_id))
    : '';

// ── Course details (all states) ──
$course_points         = LearnDashHelper::getCoursePoints($course_id);
$has_expiration        = LearnDashHelper::hasExpiration($course_id);
$expire_days_setting   = $has_expiration && function_exists('learndash_get_setting')
    ? (int) learndash_get_setting($course_id, 'expire_access_days') : 0;
$points_required       = LearnDashHelper::getPointsRequired($course_id);
$has_points_requirement = LearnDashHelper::hasPointsRequirement($course_id);
$start_date            = LearnDashHelper::getStartDate($course_id);
$end_date              = LearnDashHelper::getEndDate($course_id);

// Enrolled user expiration
$days_remaining = ($has_access && $has_expiration)
    ? LearnDashHelper::getAccessDaysRemaining($course_id, $user_id) : null;
$expiration_ts = ($days_remaining !== null)
    ? LearnDashHelper::getAccessExpiration($course_id, $user_id) : null;

?>
<aside class="card p-6 sticky top-24">
    <?php if ($is_complete) : ?>
        <!-- ── Completed ── -->
        <div class="flex items-center gap-2 mb-4">
            <?php echo stridence_icon('check-circle', 'w-5 h-5 text-status-success'); ?>
            <h3 class="font-heading font-semibold text-lg text-status-success">Afgerond</h3>
        </div>

        <div class="space-y-4">
            <p class="text-sm text-text-muted">
                Je hebt deze cursus succesvol afgerond.
            </p>

            <?php if ($expiration_ts) : ?>
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

    <?php elseif ($has_access && $is_enrolled) : ?>
        <!-- ── Enrolled, in progress ── -->
        <div class="flex items-center gap-2 mb-4">
            <?php echo stridence_icon('check-circle', 'w-5 h-5 text-primary'); ?>
            <h3 class="font-heading font-semibold text-lg">Ingeschreven</h3>
        </div>

        <div class="space-y-4">
            <div>
                <div class="flex justify-between text-sm text-text-muted mb-1">
                    <span>Voortgang</span>
                    <span><?php echo esc_html($progress); ?>%</span>
                </div>
                <div class="w-full bg-surface-container rounded-full h-2">
                    <div class="bg-primary rounded-full h-2 transition-all" style="width: <?php echo esc_attr($progress); ?>%"></div>
                </div>
            </div>

            <?php if ($days_remaining !== null) :
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
        <!-- ── Not enrolled ── -->
        <div class="space-y-4">
            <?php if ($price_type === 'open' || $price_type === 'free') : ?>
                <div class="text-2xl font-bold text-text">
                    <?php esc_html_e('Gratis', 'stridence'); ?>
                </div>
            <?php elseif ($price_formatted) : ?>
                <div>
                    <div class="text-2xl font-bold text-text">
                        <?php echo esc_html($price_formatted); ?>
                    </div>
                    <?php if ($billing_text) : ?>
                        <p class="text-sm text-text-muted mt-1">
                            <?php echo esc_html($billing_text); ?>
                            <?php if ($trial_text) : ?>
                                <br><span class="text-xs"><?php echo esc_html($trial_text); ?></span>
                            <?php endif; ?>
                        </p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <ul class="text-sm text-text-muted space-y-2">
                <li class="flex items-center gap-2">
                    <?php echo stridence_icon('check', 'w-4 h-4 text-status-success'); ?>
                    <?php esc_html_e('Direct toegang', 'stridence'); ?>
                </li>
                <li class="flex items-center gap-2">
                    <?php echo stridence_icon('check', 'w-4 h-4 text-status-success'); ?>
                    <?php esc_html_e('Leer in je eigen tempo', 'stridence'); ?>
                </li>
                <li class="flex items-center gap-2">
                    <?php echo stridence_icon('check', 'w-4 h-4 text-status-success'); ?>
                    <?php esc_html_e('Certificaat na afronding', 'stridence'); ?>
                </li>
            </ul>

            <?php if ($is_open && $has_access) : ?>
                <a href="<?php echo esc_url(LearnDashHelper::getFirstLessonUrl($course_id)); ?>" class="btn btn-primary w-full text-center">
                    <?php esc_html_e('Direct starten', 'stridence'); ?>
                </a>
            <?php elseif ($enrollment_url) : ?>
                <a href="<?php echo esc_url($enrollment_url); ?>" class="btn btn-primary w-full text-center">
                    <?php esc_html_e('Inschrijven', 'stridence'); ?>
                </a>
            <?php elseif ($ld_buttons) : ?>
                <div class="ld-course-buttons">
                    <?php echo $ld_buttons; ?>
                </div>
            <?php else : ?>
                <a href="<?php echo esc_url(is_user_logged_in() ? get_permalink($course_id) : wp_login_url(get_permalink($course_id))); ?>" class="btn btn-primary w-full text-center">
                    <?php echo esc_html($cta_label); ?>
                </a>
            <?php endif; ?>

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

    <?php
    // ── Course details (all states) ──
    $has_info = ($course_points > 0) || ($expire_days_setting > 0)
        || ($has_points_requirement && $points_required > 0)
        || $start_date || $end_date;

    if ($has_info) :
    ?>
        <div class="mt-6 pt-5 border-t border-border">
            <h4 class="text-xs font-semibold text-text-muted uppercase tracking-wider mb-3">
                <?php esc_html_e('Cursusdetails', 'stridence'); ?>
            </h4>
            <dl class="space-y-2 text-sm">
                <?php if ($course_points > 0) : ?>
                    <div class="flex justify-between">
                        <dt class="text-text-muted"><?php esc_html_e('Punten na afronding', 'stridence'); ?></dt>
                        <dd class="font-medium text-text">
                            <?php echo esc_html(sprintf(
                                _n('%d punt', '%d punten', $course_points, 'stridence'),
                                $course_points
                            )); ?>
                        </dd>
                    </div>
                <?php endif; ?>

                <?php if ($has_points_requirement && $points_required > 0) : ?>
                    <div class="flex justify-between">
                        <dt class="text-text-muted"><?php esc_html_e('Vereiste punten', 'stridence'); ?></dt>
                        <dd class="font-medium text-text">
                            <?php echo esc_html(sprintf(
                                _n('%d punt', '%d punten', $points_required, 'stridence'),
                                $points_required
                            )); ?>
                        </dd>
                    </div>
                <?php endif; ?>

                <?php if ($expire_days_setting > 0) : ?>
                    <div class="flex justify-between">
                        <dt class="text-text-muted"><?php esc_html_e('Toegangsduur', 'stridence'); ?></dt>
                        <dd class="font-medium text-text">
                            <?php echo esc_html(sprintf(
                                _n('%d dag', '%d dagen', $expire_days_setting, 'stridence'),
                                $expire_days_setting
                            )); ?>
                        </dd>
                    </div>
                <?php endif; ?>

                <?php if ($start_date) : ?>
                    <div class="flex justify-between">
                        <dt class="text-text-muted"><?php esc_html_e('Beschikbaar vanaf', 'stridence'); ?></dt>
                        <dd class="font-medium text-text">
                            <?php echo esc_html(stride_format_date(date('Y-m-d', $start_date))); ?>
                        </dd>
                    </div>
                <?php endif; ?>

                <?php if ($end_date) : ?>
                    <div class="flex justify-between">
                        <dt class="text-text-muted"><?php esc_html_e('Beschikbaar tot', 'stridence'); ?></dt>
                        <dd class="font-medium text-text">
                            <?php echo esc_html(stride_format_date(date('Y-m-d', $end_date))); ?>
                        </dd>
                    </div>
                <?php endif; ?>
            </dl>
        </div>
    <?php endif; ?>
</aside>
