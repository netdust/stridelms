<?php
/**
 * Course Card Component
 *
 * @package stridence
 *
 * @var array $course {
 *     @type int    $id          Course/Edition ID
 *     @type string $title       Course title
 *     @type string $url         Permalink
 *     @type string $type        'elearning' or 'classroom'
 *     @type string $thumbnail   Thumbnail URL
 *     @type string $duration    Duration text (e.g., "4 uur", "2 dagen")
 *     @type float  $price       Price
 *     @type string $location    Location (for classroom)
 *     @type string $date_range  Date range (for classroom)
 *     @type int    $spots_left  Remaining spots (for classroom)
 * }
 */

defined('ABSPATH') || exit;

$type_class = $course['type'] === 'elearning' ? 'str-type-badge--elearning' : 'str-type-badge--classroom';
$type_label = $course['type'] === 'elearning' ? __('E-learning', 'stridence') : __('Klassikaal', 'stridence');
?>

<article class="str-card str-card--hover str-course-card">
    <div class="str-course-card__image-wrapper">
        <?php if (!empty($course['thumbnail'])): ?>
            <img
                src="<?php echo esc_url($course['thumbnail']); ?>"
                alt="<?php echo esc_attr($course['title']); ?>"
                class="str-card__image"
                loading="lazy"
            >
        <?php else: ?>
            <div class="str-card__image str-course-card__placeholder">
                <?php stridence_icon('book', '', 48); ?>
            </div>
        <?php endif; ?>

        <span class="str-type-badge <?php echo esc_attr($type_class); ?>">
            <?php echo esc_html($type_label); ?>
        </span>

        <?php if ($course['type'] === 'classroom' && !empty($course['date_range'])): ?>
            <span class="str-course-card__date-badge">
                <?php echo esc_html($course['date_range']); ?>
            </span>
        <?php endif; ?>
    </div>

    <div class="str-card__body">
        <h3 class="str-card__title">
            <a href="<?php echo esc_url($course['url']); ?>">
                <?php echo esc_html($course['title']); ?>
            </a>
        </h3>

        <div class="str-card__meta">
            <?php if (!empty($course['duration'])): ?>
                <span class="str-card__meta-item">
                    <?php stridence_icon('clock', '', 16); ?>
                    <?php echo esc_html($course['duration']); ?>
                </span>
            <?php endif; ?>

            <?php if ($course['type'] === 'classroom' && !empty($course['location'])): ?>
                <span class="str-card__meta-item">
                    <?php stridence_icon('location', '', 16); ?>
                    <?php echo esc_html($course['location']); ?>
                </span>
            <?php endif; ?>
        </div>

        <?php if (!empty($course['spots_left']) && $course['spots_left'] <= 5): ?>
            <div class="str-course-card__warning">
                <?php stridence_icon('warning', '', 16); ?>
                <?php printf(
                    esc_html(_n('Nog %d plaats', 'Nog %d plaatsen', $course['spots_left'], 'stridence')),
                    $course['spots_left']
                ); ?>
            </div>
        <?php endif; ?>

        <div class="str-card__footer">
            <span class="str-card__price">
                <?php echo esc_html('€' . number_format($course['price'], 2, ',', '.')); ?>
            </span>
            <a href="<?php echo esc_url($course['url']); ?>" class="str-btn str-btn--primary str-btn--sm">
                <?php esc_html_e('Bekijk', 'stridence'); ?>
                <?php stridence_icon('chevron-right', '', 16); ?>
            </a>
        </div>
    </div>
</article>
