<?php
/**
 * Session List Component
 *
 * Displays individual meeting days/sessions for a classroom edition.
 *
 * @package stridence
 *
 * @var array $sessions Array of session data
 */

defined('ABSPATH') || exit;

if (empty($sessions)) {
    return;
}
?>

<section class="str-sessions">
    <h2><?php esc_html_e('Sessies', 'stridence'); ?></h2>
    <p class="str-sessions__intro">
        <?php printf(
            esc_html(_n(
                'Deze editie bestaat uit %d sessie.',
                'Deze editie bestaat uit %d sessies.',
                count($sessions),
                'stridence'
            )),
            count($sessions)
        ); ?>
    </p>

    <div class="str-sessions__list">
        <?php foreach ($sessions as $index => $session):
            $date = $session['date'] ?? '';
            $startTime = $session['start_time'] ?? '';
            $endTime = $session['end_time'] ?? '';
            $title = $session['title'] ?? sprintf(__('Sessie %d', 'stridence'), $index + 1);
            $location = $session['location'] ?? '';

            $dateFormatted = $date ? date_i18n('l j F Y', strtotime($date)) : '';
            $timeRange = '';
            if ($startTime && $endTime) {
                $timeRange = $startTime . ' - ' . $endTime;
            } elseif ($startTime) {
                $timeRange = $startTime;
            }
        ?>
            <div class="str-session">
                <div class="str-session__date">
                    <?php if ($date): ?>
                        <span class="str-session__day"><?php echo esc_html(date_i18n('j', strtotime($date))); ?></span>
                        <span class="str-session__month"><?php echo esc_html(date_i18n('M', strtotime($date))); ?></span>
                    <?php else: ?>
                        <span class="str-session__day"><?php echo esc_html($index + 1); ?></span>
                    <?php endif; ?>
                </div>

                <div class="str-session__content">
                    <h3 class="str-session__title"><?php echo esc_html($title); ?></h3>

                    <div class="str-session__meta">
                        <?php if ($dateFormatted): ?>
                            <span class="str-session__meta-item">
                                <?php stridence_icon('calendar', '', 16); ?>
                                <?php echo esc_html($dateFormatted); ?>
                            </span>
                        <?php endif; ?>

                        <?php if ($timeRange): ?>
                            <span class="str-session__meta-item">
                                <?php stridence_icon('clock', '', 16); ?>
                                <?php echo esc_html($timeRange); ?>
                            </span>
                        <?php endif; ?>

                        <?php if ($location): ?>
                            <span class="str-session__meta-item">
                                <?php stridence_icon('location', '', 16); ?>
                                <?php echo esc_html($location); ?>
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</section>
