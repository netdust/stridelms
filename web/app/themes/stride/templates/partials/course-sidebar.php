<?php
/**
 * Course Sidebar Partial
 *
 * Displays course info and action button for single course pages.
 *
 * @var int $course_id
 * @var int $user_id
 * @var array $course_info
 * @var array $action_button
 * @var DashboardService $dashboard_service
 *
 * @package stride
 */

defined('ABSPATH') || exit;

// Button style classes
$buttonStyleClass = match ($action_button['style']) {
    'primary' => 'uk-button-primary',
    'success' => 'uk-button-success',
    'warning' => 'uk-button-warning',
    'danger' => 'uk-button-danger',
    'muted' => 'uk-button-default uk-disabled',
    default => 'uk-button-default',
};
?>

<div class="stride-course-sidebar">
    <div class="stride-course-info-card">
        <!-- Price Header -->
        <div class="stride-course-info-header">
            <p class="stride-course-price">
                <?php echo esc_html($course_info['price_formatted']); ?>
            </p>
            <?php if ($course_info['price'] !== null): ?>
                <p class="stride-course-price-label">
                    <?php esc_html_e('excl. BTW', 'stride'); ?>
                </p>
            <?php endif; ?>
        </div>

        <!-- Course Info Body -->
        <div class="stride-course-info-body">
            <ul class="stride-course-info-list">
                <!-- Type -->
                <li class="stride-course-info-item">
                    <span class="stride-course-info-icon" uk-icon="icon: tv"></span>
                    <span>
                        <?php if ($course_info['is_online']): ?>
                            <?php esc_html_e('Online cursus', 'stride'); ?>
                        <?php else: ?>
                            <?php esc_html_e('In-person vorming', 'stride'); ?>
                        <?php endif; ?>
                    </span>
                </li>

                <!-- Date(s) -->
                <?php if (!empty($course_info['dates']) && !$course_info['is_online']): ?>
                    <li class="stride-course-info-item">
                        <span class="stride-course-info-icon" uk-icon="icon: calendar"></span>
                        <div>
                            <?php foreach ($course_info['dates'] as $date): ?>
                                <div><?php echo esc_html($date); ?></div>
                            <?php endforeach; ?>
                        </div>
                    </li>
                <?php endif; ?>

                <!-- Day count -->
                <?php if ($course_info['day_count'] > 0 && !$course_info['is_online']): ?>
                    <li class="stride-course-info-item">
                        <span class="stride-course-info-icon" uk-icon="icon: clock"></span>
                        <span>
                            <?php printf(
                                esc_html(_n('%d cursusdag', '%d cursusdagen', $course_info['day_count'], 'stride')),
                                $course_info['day_count']
                            ); ?>
                        </span>
                    </li>
                <?php endif; ?>

                <!-- Location -->
                <?php if (!empty($course_info['location'])): ?>
                    <li class="stride-course-info-item">
                        <span class="stride-course-info-icon" uk-icon="icon: location"></span>
                        <span><?php echo esc_html($course_info['location']); ?></span>
                    </li>
                <?php endif; ?>

                <!-- Speakers -->
                <?php if (!empty($course_info['speakers'])): ?>
                    <li class="stride-course-info-item">
                        <span class="stride-course-info-icon" uk-icon="icon: user"></span>
                        <div>
                            <?php foreach ($course_info['speakers'] as $speaker): ?>
                                <div>
                                    <?php echo esc_html($speaker['name']); ?>
                                    <?php if (!empty($speaker['role'])): ?>
                                        <span class="uk-text-muted uk-text-small">(<?php echo esc_html($speaker['role']); ?>)</span>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </li>
                <?php endif; ?>

                <!-- Available spots -->
                <?php if ($course_info['spots_text'] && !$course_info['is_online']): ?>
                    <li class="stride-course-info-item">
                        <span class="stride-course-info-icon" uk-icon="icon: users"></span>
                        <span class="<?php echo $course_info['available_spots'] <= 3 ? 'uk-text-warning' : ''; ?>">
                            <?php echo esc_html($course_info['spots_text']); ?>
                        </span>
                    </li>
                <?php endif; ?>
            </ul>

            <!-- Action Button -->
            <?php if ($action_button['url']): ?>
                <a href="<?php echo esc_url($action_button['url']); ?>"
                   class="uk-button <?php echo esc_attr($buttonStyleClass); ?> stride-course-action-btn uk-width-1-1"
                   <?php echo $action_button['disabled'] ? 'aria-disabled="true"' : ''; ?>>
                    <?php echo esc_html($action_button['label']); ?>
                </a>
            <?php else: ?>
                <button class="uk-button <?php echo esc_attr($buttonStyleClass); ?> stride-course-action-btn uk-width-1-1"
                        disabled>
                    <?php echo esc_html($action_button['label']); ?>
                </button>
            <?php endif; ?>

            <!-- Additional actions for logged-in users -->
            <?php if ($user_id && !$action_button['disabled']): ?>
                <?php if (!$course_info['is_online'] && !empty($course_info['dates'])): ?>
                    <div class="uk-margin-small-top uk-text-center">
                        <a href="#" data-ical-download="<?php echo esc_attr($course_id); ?>" class="uk-link-muted uk-text-small">
                            <span uk-icon="icon: calendar; ratio: 0.8"></span>
                            <?php esc_html_e('Toevoegen aan agenda', 'stride'); ?>
                        </a>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Questions/Help Card -->
    <div class="stride-card uk-margin-top">
        <h4 class="uk-h5 uk-margin-remove-top">
            <span uk-icon="icon: question"></span>
            <?php esc_html_e('Vragen?', 'stride'); ?>
        </h4>
        <p class="uk-text-small uk-text-muted uk-margin-small-bottom">
            <?php esc_html_e('Neem contact met ons op voor meer informatie over deze cursus.', 'stride'); ?>
        </p>
        <a href="<?php echo esc_url(home_url('/contact/')); ?>" class="uk-button uk-button-default uk-button-small uk-width-1-1">
            <?php esc_html_e('Contact', 'stride'); ?>
        </a>
    </div>
</div>
