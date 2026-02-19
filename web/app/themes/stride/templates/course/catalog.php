<?php
/**
 * Course Catalog Template
 *
 * Displays upcoming editions as course cards with pricing, dates, and enrollment status.
 * Public page - no login required.
 *
 * @package stride
 */

defined('ABSPATH') || exit;

use Stride\Modules\Edition\EditionService;
use Stride\Modules\Edition\SessionService;
use Stride\Domain\SessionType;

// Services
$editionService = ntdst_get(EditionService::class);
$sessionService = ntdst_get(SessionService::class);

// Get upcoming editions (limit 50 for initial load)
$editions = $editionService->getUpcomingEditions(50);

// Current filter (from query string)
$currentFilter = sanitize_text_field($_GET['type'] ?? 'all');

// Dutch month names for date formatting
$dutchMonths = [
    1 => 'jan', 2 => 'feb', 3 => 'mrt', 4 => 'apr', 5 => 'mei', 6 => 'jun',
    7 => 'jul', 8 => 'aug', 9 => 'sep', 10 => 'okt', 11 => 'nov', 12 => 'dec'
];

/**
 * Determine edition type based on its sessions.
 * If it has any in_person or webinar sessions, it's "classroom".
 * Otherwise it's "online".
 */
function stride_get_edition_type(SessionService $sessionService, int $editionId): string
{
    $sessions = $sessionService->getSessionsForEdition($editionId);

    foreach ($sessions as $session) {
        $type = $session['type'] ?? 'online';
        if (in_array($type, [SessionType::InPerson->value, SessionType::Webinar->value], true)) {
            return 'classroom';
        }
    }

    return 'online';
}

/**
 * Format date for display (e.g., "15 mrt 2026").
 */
function stride_format_date(string $dateString, array $dutchMonths): string
{
    if (empty($dateString)) {
        return '';
    }

    $timestamp = strtotime($dateString);
    if (!$timestamp) {
        return '';
    }

    $day = date('j', $timestamp);
    $monthNum = (int) date('n', $timestamp);
    $year = date('Y', $timestamp);
    $month = $dutchMonths[$monthNum] ?? '';

    return "{$day} {$month} {$year}";
}

// Enrich editions with computed data
$enrichedEditions = [];
foreach ($editions as $edition) {
    $editionId = (int) ($edition['id'] ?? $edition['ID'] ?? 0);
    $meta = $edition['meta'] ?? [];

    if (!$editionId) {
        continue;
    }

    // Get course info via service (handles meta prefix correctly)
    $courseId = $editionService->getCourseId($editionId);
    $course = $courseId ? get_post($courseId) : null;
    $courseTitle = $course ? $course->post_title : '';
    // Fallback to edition title if course doesn't exist
    if (!$courseTitle) {
        $courseTitle = $meta['_ntdst_post_title'] ?? $edition['title'] ?? __('Onbekende cursus', 'stride');
    }

    // Get thumbnail
    $thumbnail = $courseId ? get_the_post_thumbnail_url($courseId, 'stride_course_card') : null;

    // Determine type
    $type = stride_get_edition_type($sessionService, $editionId);

    // Get dates (meta keys are prefixed)
    $startDate = $meta['_ntdst_start_date'] ?? '';

    // Get pricing (member price)
    $price = $editionService->getPrice($editionId, true);

    // Get day count
    $dayCount = $sessionService->getDayCount($editionId);

    // Get capacity info
    $hasSpots = $editionService->hasAvailableSpots($editionId);
    $canEnroll = $editionService->canEnroll($editionId);
    $capacity = $editionService->getCapacity($editionId);
    $registered = $editionService->getRegisteredCount($editionId);
    $spotsLeft = max(0, $capacity - $registered);

    // Venue/location (meta keys are prefixed)
    $venue = $meta['_ntdst_venue'] ?? '';

    $enrichedEditions[] = [
        'id' => $editionId,
        'url' => get_permalink($editionId),
        'course_id' => $courseId,
        'title' => $courseTitle,
        'thumbnail' => $thumbnail,
        'type' => $type,
        'start_date' => $startDate,
        'start_date_formatted' => stride_format_date($startDate, $dutchMonths),
        'price' => $price,
        'day_count' => $dayCount,
        'has_spots' => $hasSpots,
        'can_enroll' => $canEnroll,
        'spots_left' => $spotsLeft,
        'venue' => $venue,
    ];
}

// Filter editions by type if requested
$filteredEditions = $enrichedEditions;
if ($currentFilter !== 'all') {
    $filteredEditions = array_filter($enrichedEditions, function ($edition) use ($currentFilter) {
        return $edition['type'] === $currentFilter;
    });
}
?>

<div class="stride-catalog">
    <!-- Page Header -->
    <header class="stride-catalog__header">
        <h1 class="stride-catalog__title"><?php esc_html_e('Cursussen', 'stride'); ?></h1>
        <p class="stride-catalog__subtitle">
            <?php esc_html_e('Ontdek ons aanbod en schrijf je in voor een cursus.', 'stride'); ?>
        </p>
    </header>

    <!-- Filter Bar -->
    <div class="stride-catalog__filters uk-margin-medium-bottom">
        <ul class="uk-subnav uk-subnav-pill" uk-margin>
            <li class="<?php echo $currentFilter === 'all' ? 'uk-active' : ''; ?>">
                <a href="<?php echo esc_url(remove_query_arg('type')); ?>">
                    <?php esc_html_e('Alle', 'stride'); ?>
                    <span class="stride-filter-count"><?php echo count($enrichedEditions); ?></span>
                </a>
            </li>
            <li class="<?php echo $currentFilter === 'classroom' ? 'uk-active' : ''; ?>">
                <a href="<?php echo esc_url(add_query_arg('type', 'classroom')); ?>">
                    <span uk-icon="icon: users; ratio: 0.9"></span>
                    <?php esc_html_e('Klassikaal', 'stride'); ?>
                    <span class="stride-filter-count">
                        <?php echo count(array_filter($enrichedEditions, fn($e) => $e['type'] === 'classroom')); ?>
                    </span>
                </a>
            </li>
            <li class="<?php echo $currentFilter === 'online' ? 'uk-active' : ''; ?>">
                <a href="<?php echo esc_url(add_query_arg('type', 'online')); ?>">
                    <span uk-icon="icon: laptop; ratio: 0.9"></span>
                    <?php esc_html_e('Online', 'stride'); ?>
                    <span class="stride-filter-count">
                        <?php echo count(array_filter($enrichedEditions, fn($e) => $e['type'] === 'online')); ?>
                    </span>
                </a>
            </li>
        </ul>
    </div>

    <?php if (!empty($filteredEditions)) : ?>
        <!-- Edition Grid -->
        <div class="uk-grid uk-grid-match uk-child-width-1-1 uk-child-width-1-2@s uk-child-width-1-3@l" uk-grid>
            <?php foreach ($filteredEditions as $edition) : ?>
                <div>
                    <div class="stride-course-card <?php echo !$edition['can_enroll'] ? 'stride-course-card--sold-out' : ''; ?>">
                        <!-- Card Image -->
                        <div class="stride-course-card__image">
                            <?php if ($edition['thumbnail']) : ?>
                                <img src="<?php echo esc_url($edition['thumbnail']); ?>"
                                     alt="<?php echo esc_attr($edition['title']); ?>"
                                     loading="lazy">
                            <?php else : ?>
                                <div class="stride-course-placeholder stride-course-placeholder--<?php echo $edition['type'] === 'online' ? 'blue' : 'orange'; ?>">
                                    <span uk-icon="icon: <?php echo $edition['type'] === 'online' ? 'laptop' : 'users'; ?>; ratio: 2"
                                          class="stride-course-placeholder__icon"></span>
                                </div>
                            <?php endif; ?>

                            <!-- Type Badge -->
                            <span class="stride-course-card__badge uk-label <?php echo $edition['type'] === 'online' ? 'stride-label-soft-secondary' : 'stride-label-soft-primary'; ?>">
                                <?php if ($edition['type'] === 'online') : ?>
                                    <span uk-icon="icon: laptop; ratio: 0.7"></span>
                                    <?php esc_html_e('Online', 'stride'); ?>
                                <?php else : ?>
                                    <span uk-icon="icon: users; ratio: 0.7"></span>
                                    <?php esc_html_e('Klassikaal', 'stride'); ?>
                                <?php endif; ?>
                            </span>

                            <!-- Sold Out Overlay -->
                            <?php if (!$edition['has_spots']) : ?>
                                <div class="stride-course-card__overlay">
                                    <span class="uk-label uk-label-danger">
                                        <?php esc_html_e('Volzet', 'stride'); ?>
                                    </span>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Card Body -->
                        <div class="stride-course-card__body">
                            <h3 class="stride-course-card__title">
                                <a href="<?php echo esc_url($edition['url']); ?>">
                                    <?php echo esc_html($edition['title']); ?>
                                </a>
                            </h3>

                            <div class="stride-course-card__meta">
                                <?php if ($edition['start_date_formatted']) : ?>
                                    <span class="stride-course-card__meta-item">
                                        <span uk-icon="icon: calendar; ratio: 0.8"></span>
                                        <?php echo esc_html($edition['start_date_formatted']); ?>
                                    </span>
                                <?php endif; ?>

                                <?php if ($edition['day_count'] > 0) : ?>
                                    <span class="stride-course-card__meta-item">
                                        <span uk-icon="icon: clock; ratio: 0.8"></span>
                                        <?php
                                        printf(
                                            esc_html(_n('%d dag', '%d dagen', $edition['day_count'], 'stride')),
                                            $edition['day_count']
                                        );
                                        ?>
                                    </span>
                                <?php endif; ?>

                                <?php if ($edition['venue'] && $edition['type'] === 'classroom') : ?>
                                    <span class="stride-course-card__meta-item">
                                        <span uk-icon="icon: location; ratio: 0.8"></span>
                                        <?php echo esc_html($edition['venue']); ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Card Footer -->
                        <div class="stride-course-card__footer">
                            <div class="stride-course-card__price-wrap">
                                <?php if ($edition['price']->isZero()) : ?>
                                    <span class="stride-course-card__price stride-course-card__price--free">
                                        <?php esc_html_e('Gratis', 'stride'); ?>
                                    </span>
                                <?php else : ?>
                                    <span class="stride-course-card__price">
                                        <?php echo esc_html($edition['price']->format()); ?>
                                    </span>
                                    <span class="stride-course-card__price-label">
                                        <?php esc_html_e('ledenprijs', 'stride'); ?>
                                    </span>
                                <?php endif; ?>
                            </div>

                            <!-- Availability Indicator -->
                            <div class="stride-course-card__availability">
                                <?php if (!$edition['has_spots']) : ?>
                                    <span class="stride-availability stride-availability--full">
                                        <span uk-icon="icon: ban; ratio: 0.8"></span>
                                        <?php esc_html_e('Volzet', 'stride'); ?>
                                    </span>
                                <?php elseif ($edition['spots_left'] <= 5) : ?>
                                    <span class="stride-availability stride-availability--limited">
                                        <span uk-icon="icon: warning; ratio: 0.8"></span>
                                        <?php
                                        printf(
                                            esc_html(_n('Nog %d plaats', 'Nog %d plaatsen', $edition['spots_left'], 'stride')),
                                            $edition['spots_left']
                                        );
                                        ?>
                                    </span>
                                <?php else : ?>
                                    <span class="stride-availability stride-availability--available">
                                        <span uk-icon="icon: check; ratio: 0.8"></span>
                                        <?php esc_html_e('Beschikbaar', 'stride'); ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Card Action -->
                        <div class="stride-course-card__action">
                            <a href="<?php echo esc_url($edition['url']); ?>" class="uk-button uk-button-primary uk-width-1-1">
                                <?php esc_html_e('Bekijk cursus', 'stride'); ?>
                            </a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

    <?php else : ?>
        <!-- Empty State -->
        <div class="stride-empty-state">
            <div class="stride-empty-state__icon">
                <span uk-icon="icon: search; ratio: 2"></span>
            </div>
            <h2 class="stride-empty-state__title">
                <?php esc_html_e('Geen cursussen gevonden', 'stride'); ?>
            </h2>
            <p class="stride-empty-state__description">
                <?php if ($currentFilter !== 'all') : ?>
                    <?php esc_html_e('Er zijn momenteel geen cursussen in deze categorie. Bekijk alle cursussen of kom later terug.', 'stride'); ?>
                <?php else : ?>
                    <?php esc_html_e('Er zijn momenteel geen cursussen gepland. Kom later terug voor ons aanbod.', 'stride'); ?>
                <?php endif; ?>
            </p>
            <?php if ($currentFilter !== 'all') : ?>
                <div class="stride-empty-state__action">
                    <a href="<?php echo esc_url(remove_query_arg('type')); ?>" class="uk-button uk-button-primary">
                        <?php esc_html_e('Bekijk alle cursussen', 'stride'); ?>
                    </a>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
