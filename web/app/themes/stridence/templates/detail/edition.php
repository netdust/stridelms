<?php
/**
 * Edition Detail Template (Classroom)
 *
 * @package stridence
 */

defined('ABSPATH') || exit;

get_header();

$editionId = get_the_ID();
$edition = get_post($editionId);

// Get edition meta
$courseId = get_post_meta($editionId, '_course_id', true);
$course = $courseId ? get_post($courseId) : null;
$startDate = get_post_meta($editionId, '_start_date', true);
$endDate = get_post_meta($editionId, '_end_date', true);
$location = get_post_meta($editionId, '_location', true);
$address = get_post_meta($editionId, '_address', true);
$price = get_post_meta($editionId, '_price', true) ?: 0;
$capacity = get_post_meta($editionId, '_capacity', true) ?: 0;
$spotsLeft = get_post_meta($editionId, '_spots_left', true);
$instructor = get_post_meta($editionId, '_instructor', true);

// Get sessions if available
$sessions = [];
if (class_exists(\Stride\Modules\Edition\SessionService::class)) {
    try {
        $sessionService = ntdst_get(\Stride\Modules\Edition\SessionService::class);
        $sessions = $sessionService->getSessionsForEdition($editionId);
    } catch (\Exception $e) {
        // Service not available
    }
}

// Format dates
$dateDisplay = '';
if ($startDate) {
    $dateDisplay = date_i18n('j F Y', strtotime($startDate));
    if ($endDate && $endDate !== $startDate) {
        $dateDisplay .= ' - ' . date_i18n('j F Y', strtotime($endDate));
    }
}

// Check enrollment status
$isOpen = true;
if ($spotsLeft !== '' && (int)$spotsLeft <= 0) {
    $isOpen = false;
}

$features = [
    __('Erkend certificaat', 'stridence'),
    __('Kleine groepen', 'stridence'),
    __('Praktijkgerichte aanpak', 'stridence'),
    __('Inclusief lesmateriaal', 'stridence'),
];
?>

<main class="str-main">
    <div class="str-container">
        <article class="str-detail">
            <div class="str-detail__grid">
                <div class="str-detail__main">
                    <!-- Hero -->
                    <div class="str-detail__hero">
                        <?php if ($course && has_post_thumbnail($course->ID)): ?>
                            <?php echo get_the_post_thumbnail($course->ID, 'large', ['class' => 'str-detail__hero-image']); ?>
                        <?php elseif (has_post_thumbnail()): ?>
                            <?php the_post_thumbnail('large', ['class' => 'str-detail__hero-image']); ?>
                        <?php else: ?>
                            <div class="str-detail__hero-placeholder">
                                <?php stridence_icon('users', '', 64); ?>
                            </div>
                        <?php endif; ?>

                        <div class="str-detail__badges">
                            <span class="str-type-badge str-type-badge--classroom">
                                <?php esc_html_e('Klassikaal', 'stridence'); ?>
                            </span>
                            <?php if (!$isOpen): ?>
                                <span class="str-badge str-badge--danger">
                                    <?php esc_html_e('Volzet', 'stridence'); ?>
                                </span>
                            <?php elseif ($spotsLeft !== '' && (int)$spotsLeft <= 5): ?>
                                <span class="str-badge str-badge--warning">
                                    <?php printf(esc_html__('Nog %d plaatsen', 'stridence'), (int)$spotsLeft); ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Header -->
                    <header class="str-detail__header">
                        <h1 class="str-detail__title"><?php the_title(); ?></h1>

                        <div class="str-detail__meta">
                            <?php if ($dateDisplay): ?>
                                <span class="str-detail__meta-item">
                                    <?php stridence_icon('calendar', '', 20); ?>
                                    <?php echo esc_html($dateDisplay); ?>
                                </span>
                            <?php endif; ?>

                            <?php if ($location): ?>
                                <span class="str-detail__meta-item">
                                    <?php stridence_icon('location', '', 20); ?>
                                    <?php echo esc_html($location); ?>
                                </span>
                            <?php endif; ?>

                            <?php if ($instructor): ?>
                                <span class="str-detail__meta-item">
                                    <?php stridence_icon('user', '', 20); ?>
                                    <?php echo esc_html($instructor); ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </header>

                    <!-- Content -->
                    <div class="str-detail__content">
                        <?php if ($course): ?>
                            <?php echo apply_filters('the_content', $course->post_content); ?>
                        <?php else: ?>
                            <?php the_content(); ?>
                        <?php endif; ?>
                    </div>

                    <!-- Sessions -->
                    <?php if (!empty($sessions)): ?>
                        <?php include get_stylesheet_directory() . '/templates/partials/session-list.php'; ?>
                    <?php endif; ?>

                    <!-- Location details -->
                    <?php if ($address): ?>
                        <section class="str-location-info">
                            <h2><?php esc_html_e('Locatie', 'stridence'); ?></h2>
                            <div class="str-location-info__card">
                                <div class="str-location-info__icon">
                                    <?php stridence_icon('location', '', 24); ?>
                                </div>
                                <div class="str-location-info__details">
                                    <?php if ($location): ?>
                                        <strong><?php echo esc_html($location); ?></strong><br>
                                    <?php endif; ?>
                                    <?php echo nl2br(esc_html($address)); ?>
                                </div>
                            </div>
                        </section>
                    <?php endif; ?>
                </div>

                <!-- Sidebar -->
                <aside class="str-detail__sidebar">
                    <div class="str-sidebar-card">
                        <div class="str-sidebar-card__price">
                            €<?php echo esc_html(number_format((float)$price, 2, ',', '.')); ?>
                            <span class="str-sidebar-card__price-note"><?php esc_html_e('excl. BTW', 'stridence'); ?></span>
                        </div>

                        <div class="str-sidebar-card__cta">
                            <?php if ($isOpen): ?>
                                <a href="<?php echo esc_url(home_url('/inschrijven/?edition=' . $editionId)); ?>" class="str-btn str-btn--primary str-btn--block str-btn--lg">
                                    <?php esc_html_e('Inschrijven', 'stridence'); ?>
                                </a>
                            <?php else: ?>
                                <button class="str-btn str-btn--secondary str-btn--block str-btn--lg" disabled>
                                    <?php esc_html_e('Volzet', 'stridence'); ?>
                                </button>
                                <a href="<?php echo esc_url(home_url('/interesse/?course=' . $courseId)); ?>" class="str-btn str-btn--ghost str-btn--block" style="margin-top: var(--str-space-sm);">
                                    <?php esc_html_e('Interesse melden', 'stridence'); ?>
                                </a>
                            <?php endif; ?>
                        </div>

                        <?php if ($dateDisplay || $location): ?>
                            <div class="str-sidebar-card__info">
                                <?php if ($dateDisplay): ?>
                                    <div class="str-sidebar-card__info-item">
                                        <?php stridence_icon('calendar', '', 18); ?>
                                        <span><?php echo esc_html($dateDisplay); ?></span>
                                    </div>
                                <?php endif; ?>
                                <?php if ($location): ?>
                                    <div class="str-sidebar-card__info-item">
                                        <?php stridence_icon('location', '', 18); ?>
                                        <span><?php echo esc_html($location); ?></span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <ul class="str-sidebar-card__features">
                            <?php foreach ($features as $feature): ?>
                                <li class="str-sidebar-card__feature">
                                    <?php stridence_icon('check', '', 18); ?>
                                    <?php echo esc_html($feature); ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </aside>
            </div>
        </article>
    </div>
</main>

<?php get_footer(); ?>
