<?php
/**
 * Course Card Partial
 *
 * Displays a single course card for grids and listings.
 *
 * @var array $course Course data array with:
 *   - id, title, excerpt, permalink, thumbnail
 *   - is_online, is_in_person, is_trajectory
 *   - status (enrolled, in_progress, completed)
 *   - progress (0-100 for online courses)
 *   - start_date, next_date, dates
 *   - location, speakers
 *   - certificate_link
 *
 * @package stride
 */

defined('ABSPATH') || exit;

// Default placeholder image
$thumbnail = $course['thumbnail'] ?: get_stylesheet_directory_uri() . '/assets/images/course-placeholder.jpg';

// Status badge classes
$statusBadgeClass = match ($course['status']) {
    'completed' => 'stride-badge-completed',
    'in_progress' => 'stride-badge-in-progress',
    default => 'stride-badge-enrolled',
};

$statusLabel = match ($course['status']) {
    'completed' => __('Afgerond', 'stride'),
    'in_progress' => __('Bezig', 'stride'),
    default => __('Ingeschreven', 'stride'),
};

// Type badge
$typeBadgeClass = $course['is_online'] ? 'stride-badge-online' : 'stride-badge-in-person';
$typeLabel = $course['is_online'] ? __('Online', 'stride') : __('In-person', 'stride');
?>

<div class="stride-course-card"
     data-type="<?php echo $course['is_online'] ? 'online' : 'in-person'; ?>"
     data-status="<?php echo esc_attr($course['status']); ?>"
     <?php if (!empty($course['edition_id'])): ?>data-edition-id="<?php echo esc_attr($course['edition_id']); ?>"<?php endif; ?>>

    <!-- Course Image -->
    <div class="stride-course-card-image" style="background-image: url('<?php echo esc_url($thumbnail); ?>');">
        <span class="stride-badge <?php echo esc_attr($typeBadgeClass); ?>">
            <?php echo esc_html($typeLabel); ?>
        </span>
    </div>

    <!-- Course Body -->
    <div class="stride-course-card-body">
        <h3 class="stride-course-card-title">
            <a href="<?php echo esc_url($course['permalink']); ?>">
                <?php echo esc_html($course['title']); ?>
            </a>
        </h3>

        <div class="stride-course-card-meta">
            <?php if (!$course['is_online'] && !empty($course['dates'])): ?>
                <!-- In-person course dates -->
                <span uk-icon="icon: calendar; ratio: 0.8"></span>
                <?php
                $nextDate = $course['next_date'];
                if ($nextDate && $nextDate > time()) {
                    echo esc_html(date_i18n('j F Y', $nextDate));
                } elseif (!empty($course['dates'])) {
                    echo esc_html(date_i18n('j F Y', $course['dates'][0]));
                }
                ?>
            <?php elseif ($course['is_online']): ?>
                <!-- Online course - show progress -->
                <span uk-icon="icon: play-circle; ratio: 0.8"></span>
                <?php echo esc_html($course['progress']); ?>% <?php esc_html_e('voltooid', 'stride'); ?>
            <?php endif; ?>
        </div>

        <?php if (!empty($course['location'])): ?>
            <div class="stride-course-card-meta">
                <span uk-icon="icon: location; ratio: 0.8"></span>
                <?php echo esc_html($course['location']); ?>
            </div>
        <?php endif; ?>

        <!-- Progress bar for online courses -->
        <?php if ($course['is_online'] && $course['status'] !== 'completed'): ?>
            <div class="stride-progress-wrapper">
                <div class="stride-progress-bar">
                    <div class="stride-progress-fill" style="width: <?php echo esc_attr($course['progress']); ?>%;"></div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Card Footer -->
        <div class="stride-course-card-footer">
            <span class="stride-badge <?php echo esc_attr($statusBadgeClass); ?>">
                <?php if ($course['status'] === 'completed'): ?>
                    <span uk-icon="icon: check; ratio: 0.7"></span>
                <?php endif; ?>
                <?php echo esc_html($statusLabel); ?>
            </span>

            <?php if ($course['status'] === 'completed' && !empty($course['certificate_link'])): ?>
                <a href="<?php echo esc_url($course['certificate_link']); ?>"
                   class="uk-button uk-button-primary uk-button-small"
                   target="_blank">
                    <span uk-icon="icon: download; ratio: 0.8"></span>
                    <?php esc_html_e('Certificaat', 'stride'); ?>
                </a>
            <?php elseif ($course['is_online'] && $course['status'] !== 'completed'): ?>
                <a href="<?php echo esc_url($course['permalink']); ?>"
                   class="uk-button uk-button-primary uk-button-small">
                    <?php esc_html_e('Ga verder', 'stride'); ?>
                    <span uk-icon="icon: arrow-right; ratio: 0.8"></span>
                </a>
            <?php else: ?>
                <a href="<?php echo esc_url($course['permalink']); ?>"
                   class="uk-button uk-button-default uk-button-small">
                    <?php esc_html_e('Details', 'stride'); ?>
                </a>
            <?php endif; ?>
        </div>
    </div>
</div>
