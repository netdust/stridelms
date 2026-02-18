<?php
/**
 * Single Edition Template
 *
 * Displays an edition with its sessions and enrollment options.
 * Uses tabs for Over (About) and Programma (Sessions) sections.
 *
 * @package stride
 */

use Stride\Domain\EditionStatus;
use Stride\Domain\SessionType;
use Stride\Modules\Edition\EditionService;
use Stride\Modules\Edition\SessionService;
use Stride\Modules\Enrollment\EnrollmentService;

get_header();

// Get services
$editionService = ntdst_get(EditionService::class);
$sessionService = ntdst_get(SessionService::class);
$enrollmentService = ntdst_get(EnrollmentService::class);

if (have_posts()) :
    while (have_posts()) :
        the_post();

        $editionId = get_the_ID();
        $edition = $editionService ? $editionService->getEdition($editionId) : null;

        if (!$edition || is_wp_error($edition)) {
            echo '<div class="uk-container"><div class="stride-card"><p>' . esc_html__('Editie niet gevonden.', 'stride') . '</p></div></div>';
            get_footer();
            return;
        }

        // Get linked course info
        $courseId = $editionService->getCourseId($editionId);
        $course = $courseId ? get_post($courseId) : null;
        $courseTitle = $course ? $course->post_title : get_the_title();
        $courseContent = $course ? $course->post_content : '';
        $heroImage = $courseId ? get_the_post_thumbnail_url($courseId, 'large') : null;

        // Edition data
        $status = $editionService->getStatus($editionId);
        $price = $editionService->getPrice($editionId, true);
        $priceNonMember = $editionService->getPrice($editionId, false);
        $capacity = $editionService->getCapacity($editionId);
        $registeredCount = $editionService->getRegisteredCount($editionId);
        $hasSpots = $editionService->hasAvailableSpots($editionId);
        $canEnroll = $editionService->canEnroll($editionId);
        $availableSpots = max(0, $capacity - $registeredCount);

        // Session data
        $sessions = $sessionService ? $sessionService->getSessionsForEdition($editionId) : [];
        $sessionCount = $sessionService ? $sessionService->getSessionCount($editionId) : count($sessions);
        $dayCount = $sessionService ? $sessionService->getDayCount($editionId) : 0;
        $totalHours = $sessionService ? $sessionService->getTotalHours($editionId) : 0;

        // Get start and end dates from sessions
        $startDate = null;
        $endDate = null;
        if (!empty($sessions)) {
            $dates = array_filter(array_column($sessions, 'date'));
            if (!empty($dates)) {
                sort($dates);
                $startDate = strtotime(reset($dates));
                $endDate = strtotime(end($dates));
            }
        }

        // Get venue from first in-person session or edition meta
        $venue = get_post_meta($editionId, 'venue', true);
        if (!$venue) {
            foreach ($sessions as $session) {
                if (!empty($session['location'])) {
                    $venue = $session['location'];
                    break;
                }
            }
        }

        // User enrollment status
        $userId = get_current_user_id();
        $isEnrolled = ($userId && $enrollmentService) ? $enrollmentService->isEnrolled($userId, $editionId) : false;

        // Status badge configuration
        $statusConfig = match ($status) {
            EditionStatus::Open => ['class' => 'uk-label-success', 'label' => __('Open voor inschrijving', 'stride')],
            EditionStatus::Full => ['class' => 'uk-label-warning', 'label' => __('Volzet', 'stride')],
            EditionStatus::Cancelled => ['class' => 'uk-label-danger', 'label' => __('Geannuleerd', 'stride')],
            EditionStatus::Postponed => ['class' => 'uk-label-warning', 'label' => __('Uitgesteld', 'stride')],
            EditionStatus::Announcement => ['class' => 'stride-label-soft-secondary', 'label' => __('Aankondiging', 'stride')],
            EditionStatus::Completed => ['class' => 'stride-label-soft-secondary', 'label' => __('Afgelopen', 'stride')],
        };
?>

<main class="stride-main stride-main--edition">
    <!-- Hero Section -->
    <section class="stride-hero stride-hero--edition">
        <?php if ($heroImage): ?>
            <div class="stride-hero__background" style="background-image: url('<?php echo esc_url($heroImage); ?>');">
                <div class="stride-hero__overlay"></div>
            </div>
        <?php else: ?>
            <div class="stride-hero__background stride-hero__background--gradient"></div>
        <?php endif; ?>

        <div class="uk-container">
            <div class="stride-hero__content">
                <nav class="uk-margin-bottom">
                    <ul class="uk-breadcrumb uk-breadcrumb-light">
                        <li><a href="<?php echo esc_url(home_url('/')); ?>"><?php esc_html_e('Home', 'stride'); ?></a></li>
                        <li><a href="<?php echo esc_url(home_url('/cursussen/')); ?>"><?php esc_html_e('Cursussen', 'stride'); ?></a></li>
                        <?php if ($course): ?>
                            <li><a href="<?php echo esc_url(get_permalink($courseId)); ?>"><?php echo esc_html($courseTitle); ?></a></li>
                        <?php endif; ?>
                        <li><span><?php the_title(); ?></span></li>
                    </ul>
                </nav>

                <span class="uk-label <?php echo esc_attr($statusConfig['class']); ?> uk-margin-small-bottom">
                    <?php echo esc_html($statusConfig['label']); ?>
                </span>

                <h1 class="stride-hero__title"><?php echo esc_html($courseTitle); ?></h1>

                <?php if ($startDate): ?>
                    <div class="stride-hero__meta">
                        <span class="stride-hero__meta-item">
                            <span uk-icon="icon: calendar"></span>
                            <?php echo esc_html(date_i18n('l j F Y', $startDate)); ?>
                            <?php if ($endDate && $endDate !== $startDate): ?>
                                - <?php echo esc_html(date_i18n('j F Y', $endDate)); ?>
                            <?php endif; ?>
                        </span>
                        <?php if ($venue): ?>
                            <span class="stride-hero__meta-item">
                                <span uk-icon="icon: location"></span>
                                <?php echo esc_html($venue); ?>
                            </span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <div class="uk-container uk-container-large uk-margin-large-top">
        <div uk-grid class="uk-grid-large">
            <!-- Main Content -->
            <div class="uk-width-2-3@m">
                <!-- Tab Navigation -->
                <ul class="uk-tab stride-tabs" uk-tab>
                    <li class="uk-active"><a href="#"><?php esc_html_e('Over', 'stride'); ?></a></li>
                    <li><a href="#"><?php esc_html_e('Programma', 'stride'); ?></a></li>
                </ul>

                <ul class="uk-switcher uk-margin">
                    <!-- Tab: Over (About) -->
                    <li>
                        <?php if (!empty($courseContent)): ?>
                            <div class="stride-card uk-margin-bottom">
                                <div class="stride-card-header">
                                    <h2 class="stride-card-title">
                                        <span uk-icon="icon: info"></span>
                                        <?php esc_html_e('Over deze cursus', 'stride'); ?>
                                    </h2>
                                </div>
                                <div class="stride-article-content uk-padding">
                                    <?php echo wp_kses_post(wpautop($courseContent)); ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- Quick Facts -->
                        <div class="stride-card">
                            <div class="stride-card-header">
                                <h2 class="stride-card-title">
                                    <span uk-icon="icon: list"></span>
                                    <?php esc_html_e('Praktische info', 'stride'); ?>
                                </h2>
                            </div>
                            <ul class="stride-info-list uk-padding">
                                <?php if ($dayCount > 0): ?>
                                    <li class="stride-info-list__item">
                                        <span uk-icon="icon: calendar; ratio: 0.9"></span>
                                        <div>
                                            <strong><?php esc_html_e('Duur', 'stride'); ?></strong>
                                            <p class="uk-margin-remove">
                                                <?php printf(
                                                    esc_html(_n('%d dag', '%d dagen', $dayCount, 'stride')),
                                                    $dayCount
                                                ); ?>
                                                <?php if ($totalHours > 0): ?>
                                                    (<?php printf(esc_html__('%.1f uur', 'stride'), $totalHours); ?>)
                                                <?php endif; ?>
                                            </p>
                                        </div>
                                    </li>
                                <?php endif; ?>

                                <?php if ($sessionCount > 0): ?>
                                    <li class="stride-info-list__item">
                                        <span uk-icon="icon: clock; ratio: 0.9"></span>
                                        <div>
                                            <strong><?php esc_html_e('Sessies', 'stride'); ?></strong>
                                            <p class="uk-margin-remove">
                                                <?php printf(
                                                    esc_html(_n('%d sessie', '%d sessies', $sessionCount, 'stride')),
                                                    $sessionCount
                                                ); ?>
                                            </p>
                                        </div>
                                    </li>
                                <?php endif; ?>

                                <?php if ($venue): ?>
                                    <li class="stride-info-list__item">
                                        <span uk-icon="icon: location; ratio: 0.9"></span>
                                        <div>
                                            <strong><?php esc_html_e('Locatie', 'stride'); ?></strong>
                                            <p class="uk-margin-remove"><?php echo esc_html($venue); ?></p>
                                        </div>
                                    </li>
                                <?php endif; ?>

                                <?php if ($capacity > 0): ?>
                                    <li class="stride-info-list__item">
                                        <span uk-icon="icon: users; ratio: 0.9"></span>
                                        <div>
                                            <strong><?php esc_html_e('Capaciteit', 'stride'); ?></strong>
                                            <p class="uk-margin-remove">
                                                <?php printf(esc_html__('%d deelnemers', 'stride'), $capacity); ?>
                                                <?php if ($hasSpots && $availableSpots <= 5): ?>
                                                    <span class="uk-text-warning">
                                                        (<?php printf(esc_html__('nog %d plaatsen', 'stride'), $availableSpots); ?>)
                                                    </span>
                                                <?php endif; ?>
                                            </p>
                                        </div>
                                    </li>
                                <?php endif; ?>
                            </ul>
                        </div>
                    </li>

                    <!-- Tab: Programma (Sessions) -->
                    <li>
                        <?php if (!empty($sessions)): ?>
                            <div class="stride-card">
                                <div class="stride-card-header">
                                    <h2 class="stride-card-title">
                                        <span uk-icon="icon: clock"></span>
                                        <?php esc_html_e('Programma', 'stride'); ?>
                                    </h2>
                                </div>
                                <div class="stride-sessions-list uk-padding">
                                    <?php foreach ($sessions as $session):
                                        $sessionDate = !empty($session['date']) ? strtotime($session['date']) : null;
                                        $sessionType = SessionType::tryFrom($session['type'] ?? 'in_person') ?? SessionType::InPerson;
                                        $sessionLocation = $session['location'] ?: $venue;
                                    ?>
                                        <div class="stride-session-item">
                                            <div class="stride-session-date <?php echo $sessionType === SessionType::Online ? 'stride-session-date--online' : ''; ?>">
                                                <?php if ($sessionDate): ?>
                                                    <span class="stride-session-date__day"><?php echo esc_html(date_i18n('j', $sessionDate)); ?></span>
                                                    <span class="stride-session-date__month"><?php echo esc_html(date_i18n('M', $sessionDate)); ?></span>
                                                <?php else: ?>
                                                    <span uk-icon="icon: laptop; ratio: 1.2"></span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="stride-session-info">
                                                <h4 class="stride-session-info__title">
                                                    <?php echo esc_html($sessionType->label()); ?>
                                                    <?php if ($session['optional']): ?>
                                                        <span class="uk-label stride-label-soft-secondary uk-margin-small-left"><?php esc_html_e('Optioneel', 'stride'); ?></span>
                                                    <?php endif; ?>
                                                </h4>
                                                <div class="stride-session-info__meta">
                                                    <?php if (!empty($session['start_time'])): ?>
                                                        <span class="stride-session-info__meta-item">
                                                            <span uk-icon="icon: clock; ratio: 0.75"></span>
                                                            <?php echo esc_html($session['start_time']); ?>
                                                            <?php if (!empty($session['end_time'])): ?>
                                                                - <?php echo esc_html($session['end_time']); ?>
                                                            <?php endif; ?>
                                                        </span>
                                                    <?php endif; ?>

                                                    <?php if ($sessionType === SessionType::InPerson && $sessionLocation): ?>
                                                        <span class="stride-session-info__meta-item">
                                                            <span uk-icon="icon: location; ratio: 0.75"></span>
                                                            <?php echo esc_html($sessionLocation); ?>
                                                        </span>
                                                    <?php elseif ($sessionType === SessionType::Webinar): ?>
                                                        <span class="stride-session-info__meta-item">
                                                            <span uk-icon="icon: video-camera; ratio: 0.75"></span>
                                                            <?php esc_html_e('Online webinar', 'stride'); ?>
                                                        </span>
                                                    <?php elseif ($sessionType === SessionType::Online): ?>
                                                        <span class="stride-session-info__meta-item">
                                                            <span uk-icon="icon: laptop; ratio: 0.75"></span>
                                                            <?php esc_html_e('Zelfstudie', 'stride'); ?>
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="stride-card">
                                <div class="stride-empty-state">
                                    <div class="stride-empty-state__icon">
                                        <span uk-icon="icon: clock; ratio: 2"></span>
                                    </div>
                                    <h3 class="stride-empty-state__title"><?php esc_html_e('Programma wordt bekend gemaakt', 'stride'); ?></h3>
                                    <p class="stride-empty-state__description"><?php esc_html_e('Het programma voor deze editie wordt binnenkort bekend gemaakt.', 'stride'); ?></p>
                                </div>
                            </div>
                        <?php endif; ?>
                    </li>
                </ul>
            </div>

            <!-- Sidebar -->
            <div class="uk-width-1-3@m">
                <div class="stride-course-sidebar" uk-sticky="offset: 100; bottom: true; media: @m;">
                    <div class="stride-course-info-card">
                        <div class="stride-course-info-header">
                            <?php if ($price->isZero()): ?>
                                <p class="stride-course-price"><?php esc_html_e('Gratis', 'stride'); ?></p>
                            <?php else: ?>
                                <p class="stride-course-price"><?php echo esc_html($price->format()); ?></p>
                                <p class="stride-course-price-label"><?php esc_html_e('excl. BTW', 'stride'); ?></p>
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

                                <?php if ($sessionCount > 0): ?>
                                    <li class="stride-course-info-item">
                                        <span class="stride-course-info-icon" uk-icon="icon: clock; ratio: 0.9"></span>
                                        <span>
                                            <?php printf(
                                                esc_html(_n('%d sessie', '%d sessies', $sessionCount, 'stride')),
                                                $sessionCount
                                            ); ?>
                                        </span>
                                    </li>
                                <?php endif; ?>

                                <?php if ($capacity > 0): ?>
                                    <li class="stride-course-info-item">
                                        <span class="stride-course-info-icon" uk-icon="icon: users; ratio: 0.9"></span>
                                        <span>
                                            <?php if (!$hasSpots): ?>
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

                            <!-- Enrollment CTA -->
                            <?php if ($status === EditionStatus::Cancelled): ?>
                                <div class="uk-alert uk-alert-danger uk-margin-bottom">
                                    <?php esc_html_e('Deze editie is geannuleerd.', 'stride'); ?>
                                </div>
                            <?php elseif ($isEnrolled): ?>
                                <div class="uk-alert uk-alert-success uk-margin-bottom">
                                    <span uk-icon="icon: check"></span>
                                    <?php esc_html_e('Je bent ingeschreven voor deze editie.', 'stride'); ?>
                                </div>
                                <a href="<?php echo esc_url(home_url('/mijn-account/cursussen/')); ?>" class="stride-course-action-btn uk-button uk-button-default">
                                    <?php esc_html_e('Bekijk mijn cursussen', 'stride'); ?>
                                </a>
                            <?php elseif (!is_user_logged_in()): ?>
                                <a href="<?php echo esc_url(wp_login_url(get_permalink())); ?>" class="stride-course-action-btn uk-button uk-button-primary">
                                    <?php esc_html_e('Log in om in te schrijven', 'stride'); ?>
                                </a>
                                <p class="uk-text-small uk-text-center uk-text-muted uk-margin-small-top">
                                    <?php printf(
                                        esc_html__('Nog geen account? %sRegistreer hier%s', 'stride'),
                                        '<a href="' . esc_url(wp_registration_url()) . '">',
                                        '</a>'
                                    ); ?>
                                </p>
                            <?php elseif (!$hasSpots): ?>
                                <button class="stride-course-action-btn uk-button uk-button-default" disabled>
                                    <?php esc_html_e('Volzet', 'stride'); ?>
                                </button>
                                <p class="uk-text-small uk-text-center uk-text-muted uk-margin-small-top">
                                    <?php esc_html_e('Neem contact op voor de wachtlijst', 'stride'); ?>
                                </p>
                            <?php elseif ($canEnroll): ?>
                                <a href="<?php echo esc_url(add_query_arg('edition', $editionId, home_url('/inschrijven/'))); ?>" class="stride-course-action-btn uk-button uk-button-primary">
                                    <?php esc_html_e('Inschrijven', 'stride'); ?>
                                </a>
                            <?php else: ?>
                                <button class="stride-course-action-btn uk-button uk-button-default" disabled>
                                    <?php esc_html_e('Inschrijving gesloten', 'stride'); ?>
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Mobile Sticky CTA -->
    <div class="stride-sticky-cta uk-hidden@m">
        <div class="stride-sticky-cta__inner">
            <div class="stride-sticky-cta__price">
                <?php if ($price->isZero()): ?>
                    <span class="stride-sticky-cta__price-value"><?php esc_html_e('Gratis', 'stride'); ?></span>
                <?php else: ?>
                    <span class="stride-sticky-cta__price-value"><?php echo esc_html($price->format()); ?></span>
                    <span class="stride-sticky-cta__price-label"><?php esc_html_e('excl. BTW', 'stride'); ?></span>
                <?php endif; ?>
            </div>
            <div class="stride-sticky-cta__action">
                <?php if ($status === EditionStatus::Cancelled): ?>
                    <span class="uk-text-danger"><?php esc_html_e('Geannuleerd', 'stride'); ?></span>
                <?php elseif ($isEnrolled): ?>
                    <span class="stride-text-success"><span uk-icon="icon: check"></span> <?php esc_html_e('Ingeschreven', 'stride'); ?></span>
                <?php elseif (!is_user_logged_in()): ?>
                    <a href="<?php echo esc_url(wp_login_url(get_permalink())); ?>" class="uk-button uk-button-primary uk-button-small">
                        <?php esc_html_e('Log in', 'stride'); ?>
                    </a>
                <?php elseif (!$hasSpots): ?>
                    <span class="uk-text-warning"><?php esc_html_e('Volzet', 'stride'); ?></span>
                <?php elseif ($canEnroll): ?>
                    <a href="<?php echo esc_url(add_query_arg('edition', $editionId, home_url('/inschrijven/'))); ?>" class="uk-button uk-button-primary uk-button-small">
                        <?php esc_html_e('Inschrijven', 'stride'); ?>
                    </a>
                <?php else: ?>
                    <span class="uk-text-muted"><?php esc_html_e('Gesloten', 'stride'); ?></span>
                <?php endif; ?>
            </div>
        </div>
    </div>
</main>

<style>
/* Edition Hero */
.stride-hero--edition {
    position: relative;
    padding: var(--stride-space-2xl) 0;
    min-height: 280px;
    display: flex;
    align-items: flex-end;
}

.stride-hero__background {
    position: absolute;
    inset: 0;
    background-size: cover;
    background-position: center;
}

.stride-hero__background--gradient {
    background: linear-gradient(135deg, var(--stride-secondary) 0%, var(--stride-primary) 100%);
}

.stride-hero__overlay {
    position: absolute;
    inset: 0;
    background: linear-gradient(to top, rgba(45, 62, 80, 0.9) 0%, rgba(45, 62, 80, 0.4) 100%);
}

.stride-hero__content {
    position: relative;
    z-index: 1;
    color: #FFFFFF;
}

.stride-hero__title {
    font-size: var(--stride-font-size-3xl);
    font-weight: 700;
    margin: var(--stride-space-sm) 0;
    color: #FFFFFF;
}

.stride-hero__meta {
    display: flex;
    flex-wrap: wrap;
    gap: var(--stride-space-lg);
    margin-top: var(--stride-space-md);
    opacity: 0.9;
}

.stride-hero__meta-item {
    display: inline-flex;
    align-items: center;
    gap: var(--stride-space-xs);
}

.uk-breadcrumb-light > * > * {
    color: rgba(255, 255, 255, 0.7);
}

.uk-breadcrumb-light > :last-child > * {
    color: #FFFFFF;
}

/* Tabs */
.stride-tabs {
    border-bottom: 2px solid var(--stride-border-light);
}

/* Info List */
.stride-info-list {
    list-style: none;
    margin: 0;
    padding: 0;
}

.stride-info-list__item {
    display: flex;
    gap: var(--stride-space-md);
    padding: var(--stride-space-md) 0;
    border-bottom: 1px solid var(--stride-border-light);
}

.stride-info-list__item:last-child {
    border-bottom: none;
}

.stride-info-list__item > span:first-child {
    color: var(--stride-primary);
    flex-shrink: 0;
}

/* Sessions List */
.stride-sessions-list {
    display: flex;
    flex-direction: column;
}

.stride-session-date--online {
    background: var(--stride-success-light);
}

.stride-session-date--online .stride-session-date__day,
.stride-session-date--online .stride-session-date__month {
    color: var(--stride-success);
}

/* Course Sidebar Card */
.stride-course-info-card {
    background: var(--stride-surface);
    border-radius: var(--stride-radius-lg);
    box-shadow: var(--stride-shadow-md);
    overflow: hidden;
}

.stride-course-info-header {
    background: linear-gradient(135deg, var(--stride-primary) 0%, var(--stride-primary-hover) 100%);
    color: #FFFFFF;
    padding: var(--stride-space-lg);
    text-align: center;
}

.stride-course-price {
    font-size: var(--stride-font-size-2xl);
    font-weight: 700;
    margin: 0;
}

.stride-course-price-label {
    font-size: var(--stride-font-size-sm);
    opacity: 0.8;
    margin: 0;
}

.stride-course-info-body {
    padding: var(--stride-space-lg);
}

.stride-course-info-list {
    list-style: none;
    margin: 0 0 var(--stride-space-lg);
    padding: 0;
}

.stride-course-info-item {
    display: flex;
    align-items: center;
    gap: var(--stride-space-sm);
    padding: var(--stride-space-sm) 0;
    color: var(--stride-text);
}

.stride-course-info-icon {
    color: var(--stride-text-muted);
}

.stride-course-action-btn {
    width: 100%;
    text-align: center;
}

/* Sticky CTA (Mobile) */
.stride-sticky-cta {
    position: fixed;
    bottom: var(--stride-bottom-nav-height);
    left: 0;
    right: 0;
    background: var(--stride-surface);
    border-top: 1px solid var(--stride-border-light);
    box-shadow: 0 -4px 12px rgba(0, 0, 0, 0.08);
    z-index: var(--stride-z-sticky);
    padding: var(--stride-space-md) var(--stride-space-lg);
}

.stride-sticky-cta__inner {
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.stride-sticky-cta__price-value {
    font-size: var(--stride-font-size-lg);
    font-weight: 700;
    color: var(--stride-text);
}

.stride-sticky-cta__price-label {
    display: block;
    font-size: var(--stride-font-size-xs);
    color: var(--stride-text-muted);
}

/* Cards */
.stride-card {
    background: var(--stride-surface);
    border-radius: var(--stride-radius-lg);
    box-shadow: var(--stride-shadow-sm);
    border: 1px solid var(--stride-border-light);
    overflow: hidden;
}

.stride-card-header {
    padding: var(--stride-space-md) var(--stride-space-lg);
    border-bottom: 1px solid var(--stride-border-light);
}

.stride-card-title {
    font-size: var(--stride-font-size-lg);
    font-weight: 600;
    color: var(--stride-text);
    margin: 0;
    display: flex;
    align-items: center;
    gap: var(--stride-space-sm);
}

.stride-card-title > span[uk-icon] {
    color: var(--stride-primary);
}

/* Article Content */
.stride-article-content {
    font-size: var(--stride-font-size-base);
    line-height: var(--stride-line-height-relaxed);
    color: var(--stride-text);
}

.stride-article-content p {
    margin-bottom: var(--stride-space-md);
}

.stride-article-content h2,
.stride-article-content h3,
.stride-article-content h4 {
    margin-top: var(--stride-space-lg);
    margin-bottom: var(--stride-space-sm);
}

/* Responsive */
@media (max-width: 767px) {
    .stride-hero--edition {
        min-height: 220px;
        padding: var(--stride-space-xl) 0;
    }

    .stride-hero__title {
        font-size: var(--stride-font-size-2xl);
    }

    .stride-hero__meta {
        flex-direction: column;
        gap: var(--stride-space-sm);
    }

    body.stride-user-logged-in .stride-sticky-cta {
        bottom: var(--stride-bottom-nav-height);
    }
}
</style>

<?php
    endwhile;
endif;

get_footer();
?>
