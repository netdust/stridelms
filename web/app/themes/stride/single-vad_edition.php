<?php
/**
 * Single Edition Template
 *
 * Displays an edition with its sessions and enrollment options.
 *
 * @package stride
 */

get_header();

// Get services
$editionService = stride_service(\ntdst\Stride\core\EditionService::class);
$sessionService = stride_service(\ntdst\Stride\core\SessionService::class);
$courseService = stride_service(\ntdst\Stride\core\CourseService::class);

$editionId = get_the_ID();
$edition = $editionService ? $editionService->getEdition($editionId) : null;

if (!$edition) {
    echo '<div class="uk-container"><div class="stride-card"><p>' . esc_html__('Editie niet gevonden.', 'stride') . '</p></div></div>';
    get_footer();
    return;
}

$courseId = $edition['course_id'] ?? null;
$courseTitle = $courseId ? get_the_title($courseId) : '';
$startDateStr = $editionService->getStartDate($editionId);
$endDateStr = $editionService->getEndDate($editionId);
$startDate = $startDateStr ? strtotime($startDateStr) : null;
$endDate = $endDateStr ? strtotime($endDateStr) : null;
$venue = $editionService->getVenue($editionId);
$price = $editionService->getPrice($editionId);
$isFull = $editionService->isFull($editionId);
$isCancelled = $editionService->isCancelled($editionId);
$availableSpots = $editionService->getAvailableSpots($editionId);
$capacity = $edition['capacity'] ?? null;
$speakers = $editionService->getSpeakers($editionId);

// Get sessions
$sessions = $sessionService ? $sessionService->getSessionsForEdition($editionId) : [];

// Status badge
$statusClass = 'stride-badge-enrolled';
$statusLabel = __('Open voor inschrijving', 'stride');
if ($isCancelled) {
    $statusClass = 'stride-badge-cancelled';
    $statusLabel = __('Geannuleerd', 'stride');
} elseif ($isFull) {
    $statusClass = 'stride-badge-pending';
    $statusLabel = __('Volzet', 'stride');
}
?>

<div class="uk-container uk-container-large">
    <article class="stride-article stride-edition-single">
        <!-- Edition Header -->
        <header class="stride-course-header">
            <div class="uk-container">
                <nav class="uk-margin-bottom">
                    <ul class="uk-breadcrumb">
                        <li><a href="<?php echo esc_url(home_url('/')); ?>"><?php esc_html_e('Home', 'stride'); ?></a></li>
                        <li><a href="<?php echo esc_url(home_url('/cursussen/')); ?>"><?php esc_html_e('Cursussen', 'stride'); ?></a></li>
                        <?php if ($courseId): ?>
                            <li><a href="<?php echo esc_url(get_permalink($courseId)); ?>"><?php echo esc_html($courseTitle); ?></a></li>
                        <?php endif; ?>
                        <li><span><?php the_title(); ?></span></li>
                    </ul>
                </nav>

                <div class="uk-flex uk-flex-middle uk-flex-wrap" style="gap: 12px;">
                    <h1 class="stride-page-title uk-margin-remove"><?php the_title(); ?></h1>
                    <span class="stride-badge <?php echo esc_attr($statusClass); ?>">
                        <?php echo esc_html($statusLabel); ?>
                    </span>
                </div>

                <?php if ($startDate): ?>
                    <p class="uk-text-lead uk-margin-small-top uk-margin-remove-bottom">
                        <span uk-icon="icon: calendar"></span>
                        <?php echo esc_html(date_i18n('l j F Y', $startDate)); ?>
                        <?php if ($endDate && $endDate !== $startDate): ?>
                            - <?php echo esc_html(date_i18n('j F Y', $endDate)); ?>
                        <?php endif; ?>
                    </p>
                <?php endif; ?>
            </div>
        </header>

        <div class="stride-edition-content uk-margin-large-top">
            <div uk-grid class="uk-grid-large">
                <!-- Main Content -->
                <div class="uk-width-2-3@m">
                    <!-- Edition Details -->
                    <div class="stride-card uk-margin-bottom">
                        <div class="stride-card-header">
                            <h2 class="stride-card-title">
                                <span uk-icon="icon: info"></span>
                                <?php esc_html_e('Details', 'stride'); ?>
                            </h2>
                        </div>

                        <ul class="stride-info-list">
                            <?php if ($venue): ?>
                                <li>
                                    <span uk-icon="icon: location; ratio: 0.9"></span>
                                    <div>
                                        <strong><?php esc_html_e('Locatie', 'stride'); ?></strong>
                                        <p class="uk-margin-remove"><?php echo esc_html($venue); ?></p>
                                    </div>
                                </li>
                            <?php endif; ?>

                            <?php if (!empty($speakers)): ?>
                                <li>
                                    <span uk-icon="icon: users; ratio: 0.9"></span>
                                    <div>
                                        <strong><?php esc_html_e('Docent(en)', 'stride'); ?></strong>
                                        <p class="uk-margin-remove">
                                            <?php foreach ($speakers as $speaker): ?>
                                                <?php echo esc_html($speaker['name']); ?><br>
                                            <?php endforeach; ?>
                                        </p>
                                    </div>
                                </li>
                            <?php endif; ?>

                            <?php if ($capacity): ?>
                                <li>
                                    <span uk-icon="icon: user; ratio: 0.9"></span>
                                    <div>
                                        <strong><?php esc_html_e('Capaciteit', 'stride'); ?></strong>
                                        <p class="uk-margin-remove">
                                            <?php printf(esc_html__('%d deelnemers', 'stride'), $capacity); ?>
                                            <?php if ($availableSpots !== null): ?>
                                                <span class="uk-text-muted">
                                                    (<?php printf(esc_html__('%d beschikbaar', 'stride'), $availableSpots); ?>)
                                                </span>
                                            <?php endif; ?>
                                        </p>
                                    </div>
                                </li>
                            <?php endif; ?>
                        </ul>
                    </div>

                    <?php if (!empty($sessions)): ?>
                        <!-- Sessions / Class Schedule -->
                        <div class="stride-card">
                            <div class="stride-card-header">
                                <h2 class="stride-card-title">
                                    <span uk-icon="icon: clock"></span>
                                    <?php esc_html_e('Lesrooster', 'stride'); ?>
                                </h2>
                                <p class="uk-text-muted uk-margin-remove">
                                    <?php printf(esc_html__('%d lesmomenten', 'stride'), count($sessions)); ?>
                                </p>
                            </div>

                            <div class="stride-sessions-list">
                                <?php foreach ($sessions as $index => $session):
                                    $sessionDate = $session['date'] ?? null;
                                    $startTime = $session['start_time'] ?? null;
                                    $endTime = $session['end_time'] ?? null;
                                    $sessionVenue = $session['venue'] ?? $venue;
                                ?>
                                    <div class="stride-session-item">
                                        <div class="stride-session-number">
                                            <?php echo esc_html($index + 1); ?>
                                        </div>
                                        <div class="stride-session-info uk-flex-1">
                                            <?php if ($sessionDate): ?>
                                                <strong><?php echo esc_html(date_i18n('l j F Y', strtotime($sessionDate))); ?></strong>
                                            <?php endif; ?>
                                            <?php if ($startTime): ?>
                                                <span class="uk-text-muted">
                                                    <?php echo esc_html($startTime); ?>
                                                    <?php if ($endTime): ?>
                                                        - <?php echo esc_html($endTime); ?>
                                                    <?php endif; ?>
                                                </span>
                                            <?php endif; ?>
                                            <?php if ($sessionVenue && $sessionVenue !== $venue): ?>
                                                <div class="uk-text-small uk-text-muted uk-margin-small-top">
                                                    <span uk-icon="icon: location; ratio: 0.7"></span>
                                                    <?php echo esc_html($sessionVenue); ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($courseId): ?>
                        <!-- Course Content -->
                        <div class="stride-card uk-margin-top">
                            <div class="stride-card-header">
                                <h2 class="stride-card-title">
                                    <span uk-icon="icon: file-text"></span>
                                    <?php esc_html_e('Cursusinhoud', 'stride'); ?>
                                </h2>
                            </div>
                            <div class="stride-article-content">
                                <?php
                                $course = get_post($courseId);
                                if ($course) {
                                    echo wp_kses_post(apply_filters('the_content', $course->post_content));
                                }
                                ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Sidebar -->
                <div class="uk-width-1-3@m">
                    <div class="stride-course-sidebar">
                        <div class="stride-course-info-card">
                            <div class="stride-course-info-header">
                                <?php if ($price !== null): ?>
                                    <p class="stride-course-price">
                                        <?php echo esc_html('€ ' . number_format($price, 2, ',', '.')); ?>
                                    </p>
                                    <p class="stride-course-price-label"><?php esc_html_e('excl. BTW', 'stride'); ?></p>
                                <?php else: ?>
                                    <p class="stride-course-price"><?php esc_html_e('Gratis', 'stride'); ?></p>
                                <?php endif; ?>
                            </div>

                            <div class="stride-course-info-body">
                                <ul class="stride-course-info-list">
                                    <?php if ($startDate): ?>
                                        <li class="stride-course-info-item">
                                            <span class="stride-course-info-icon" uk-icon="icon: calendar; ratio: 0.9"></span>
                                            <span><?php echo esc_html(date_i18n('j F Y', $startDate)); ?></span>
                                        </li>
                                    <?php endif; ?>

                                    <?php if ($venue): ?>
                                        <li class="stride-course-info-item">
                                            <span class="stride-course-info-icon" uk-icon="icon: location; ratio: 0.9"></span>
                                            <span><?php echo esc_html($venue); ?></span>
                                        </li>
                                    <?php endif; ?>

                                    <?php if (count($sessions) > 0): ?>
                                        <li class="stride-course-info-item">
                                            <span class="stride-course-info-icon" uk-icon="icon: clock; ratio: 0.9"></span>
                                            <span><?php printf(esc_html(_n('%d sessie', '%d sessies', count($sessions), 'stride')), count($sessions)); ?></span>
                                        </li>
                                    <?php endif; ?>

                                    <?php if ($availableSpots !== null): ?>
                                        <li class="stride-course-info-item">
                                            <span class="stride-course-info-icon" uk-icon="icon: users; ratio: 0.9"></span>
                                            <span>
                                                <?php if ($isFull): ?>
                                                    <span class="uk-text-danger"><?php esc_html_e('Volzet', 'stride'); ?></span>
                                                <?php elseif ($availableSpots <= 5): ?>
                                                    <span class="uk-text-warning"><?php printf(esc_html__('Nog %d plaatsen', 'stride'), $availableSpots); ?></span>
                                                <?php else: ?>
                                                    <?php printf(esc_html__('%d plaatsen beschikbaar', 'stride'), $availableSpots); ?>
                                                <?php endif; ?>
                                            </span>
                                        </li>
                                    <?php endif; ?>
                                </ul>

                                <?php if ($isCancelled): ?>
                                    <div class="uk-alert uk-alert-danger uk-margin-bottom">
                                        <?php esc_html_e('Deze editie is geannuleerd.', 'stride'); ?>
                                    </div>
                                <?php elseif ($isFull): ?>
                                    <button class="stride-course-action-btn uk-button uk-button-default" disabled>
                                        <?php esc_html_e('Volzet', 'stride'); ?>
                                    </button>
                                    <p class="uk-text-small uk-text-center uk-text-muted uk-margin-small-top">
                                        <?php esc_html_e('Neem contact op voor de wachtlijst', 'stride'); ?>
                                    </p>
                                <?php else: ?>
                                    <a href="#enroll-form" class="stride-course-action-btn uk-button uk-button-primary">
                                        <?php esc_html_e('Inschrijven', 'stride'); ?>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>

                        <?php if ($courseId): ?>
                            <div class="uk-margin-top">
                                <a href="<?php echo esc_url(get_permalink($courseId)); ?>" class="uk-link-muted uk-display-block uk-text-center">
                                    <span uk-icon="icon: arrow-left; ratio: 0.8"></span>
                                    <?php esc_html_e('Terug naar cursus', 'stride'); ?>
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </article>
</div>

<?php get_footer(); ?>
