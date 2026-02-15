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

// Get sessions and slot configuration
$sessions = $sessionService ? $sessionService->getSessionsForEdition($editionId) : [];
$sessionSlots = $editionService ? $editionService->getSessionSlots($editionId) : [];

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
                        <?php
                        // Helper to render a session block
                        $renderSession = function($session, $defaultVenue) {
                            $date = $session['date'] ?? null;
                            $startTime = $session['start_time'] ?? null;
                            $endTime = $session['end_time'] ?? null;
                            $title = $session['title'] ?? '';
                            $description = $session['description'] ?? '';
                            $type = $session['type'] ?? 'in_person';
                            $location = $session['location'] ?: $defaultVenue;
                            $webinarLink = $session['webinar_link'] ?? '';
                            $lessonIds = $session['lesson_ids'] ?? [];

                            // For online/assignment types, get lesson info
                            $lessonExcerpt = '';
                            if (($type === 'online' || $type === 'assignment') && !empty($lessonIds)) {
                                $lesson = get_post($lessonIds[0]);
                                if ($lesson) {
                                    if (!$title) $title = $lesson->post_title;
                                    if (!$description) $lessonExcerpt = wp_trim_words(strip_tags($lesson->post_content), 20, '...');
                                }
                            }

                            $showDescription = $description ?: $lessonExcerpt;

                            // Type config
                            $typeLabel = match($type) {
                                'webinar' => __('Webinar', 'stride'),
                                'online' => __('Online module', 'stride'),
                                'assignment' => __('Opdracht', 'stride'),
                                default => null,
                            };
                            ?>
                            <div class="stride-program-item">
                                <div class="stride-program-date">
                                    <?php if ($date): ?>
                                        <span class="stride-program-day"><?php echo esc_html(date_i18n('D', strtotime($date))); ?></span>
                                        <span class="stride-program-daynum"><?php echo esc_html(date_i18n('j', strtotime($date))); ?></span>
                                        <span class="stride-program-month"><?php echo esc_html(date_i18n('M', strtotime($date))); ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="stride-program-content">
                                    <div class="stride-program-header">
                                        <h4 class="stride-program-title">
                                            <?php echo esc_html($title ?: __('Sessie', 'stride')); ?>
                                            <?php if ($typeLabel): ?>
                                                <span class="stride-program-type"><?php echo esc_html($typeLabel); ?></span>
                                            <?php endif; ?>
                                        </h4>
                                        <?php if ($startTime): ?>
                                            <span class="stride-program-time"><?php echo esc_html($startTime); ?><?php if ($endTime): ?> – <?php echo esc_html($endTime); ?><?php endif; ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($showDescription): ?>
                                        <p class="stride-program-desc"><?php echo esc_html($showDescription); ?></p>
                                    <?php endif; ?>
                                    <div class="stride-program-meta">
                                        <?php if ($type === 'in_person' && $location): ?>
                                            <span><span uk-icon="icon: location; ratio: 0.75"></span> <?php echo esc_html($location); ?></span>
                                        <?php endif; ?>
                                        <?php if ($type === 'webinar' && $webinarLink): ?>
                                            <a href="<?php echo esc_url($webinarLink); ?>" target="_blank" rel="noopener"><span uk-icon="icon: link; ratio: 0.75"></span> <?php esc_html_e('Webinar link', 'stride'); ?></a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <?php
                        };

                        // Split sessions by slot
                        $slotNames = !empty($sessionSlots) ? array_column($sessionSlots, 'slot') : [];
                        $unslottedSessions = array_filter($sessions, fn($s) => empty($s['slot']) || !in_array($s['slot'], $slotNames, true));
                        ?>

                        <!-- Sessions / Course Program -->
                        <div class="stride-card">
                            <div class="stride-card-header">
                                <h2 class="stride-card-title">
                                    <span uk-icon="icon: clock"></span>
                                    <?php esc_html_e('Programma', 'stride'); ?>
                                </h2>
                            </div>

                            <div class="stride-program">
                                <?php if (!empty($sessionSlots)): ?>
                                    <?php foreach ($sessionSlots as $slot):
                                        $slotSessions = array_filter($sessions, fn($s) => ($s['slot'] ?? '') === $slot['slot']);
                                        if (empty($slotSessions)) continue;
                                        $pickCount = (int)($slot['pick_count'] ?? 0);
                                        $isOptIn = $pickCount > 0 && count($slotSessions) > $pickCount;
                                    ?>
                                        <?php if ($isOptIn): ?>
                                            <div class="stride-program-slot stride-program-slot-optin">
                                                <div class="stride-program-slot-header">
                                                    <span uk-icon="icon: git-branch; ratio: 0.85"></span>
                                                    <strong><?php echo esc_html($slot['label'] ?? $slot['slot']); ?></strong>
                                                    <span class="stride-program-slot-pick"><?php printf(esc_html__('Kies %d van %d', 'stride'), $pickCount, count($slotSessions)); ?></span>
                                                </div>
                                                <div class="stride-program-slot-options">
                                                    <?php foreach ($slotSessions as $session): ?>
                                                        <?php $renderSession($session, $venue); ?>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                        <?php else: ?>
                                            <?php foreach ($slotSessions as $session): ?>
                                                <?php $renderSession($session, $venue); ?>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                <?php endif; ?>

                                <?php foreach ($unslottedSessions as $session): ?>
                                    <?php $renderSession($session, $venue); ?>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php
                    // Show course description if available (without LearnDash filters)
                    $course = $courseId ? get_post($courseId) : null;
                    if ($course && !empty($course->post_content)):
                    ?>
                        <div class="stride-card uk-margin-top">
                            <div class="stride-card-header">
                                <h2 class="stride-card-title">
                                    <span uk-icon="icon: file-text"></span>
                                    <?php esc_html_e('Over deze cursus', 'stride'); ?>
                                </h2>
                            </div>
                            <div class="stride-article-content">
                                <?php echo wp_kses_post(wpautop($course->post_content)); ?>
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

                    </div>
                </div>
            </div>
        </div>
    </article>
</div>

<?php get_footer(); ?>
