<?php
/**
 * Trajectory Catalog Template
 *
 * Displays trajectories open for enrollment with course counts, pricing, and status.
 * Public page - no login required.
 *
 * @package stride
 */

defined('ABSPATH') || exit;

use Stride\Modules\Trajectory\TrajectoryService;

// Service
$trajectoryService = ntdst_get(TrajectoryService::class);

// Get trajectories open for enrollment
$trajectories = $trajectoryService->getOpenTrajectories();

// Dutch month names for deadline formatting
$dutchMonths = [
    1 => 'jan', 2 => 'feb', 3 => 'mrt', 4 => 'apr', 5 => 'mei', 6 => 'jun',
    7 => 'jul', 8 => 'aug', 9 => 'sep', 10 => 'okt', 11 => 'nov', 12 => 'dec'
];

/**
 * Format date for display (e.g., "15 maart 2026").
 */
function stride_trajectory_format_date(string $dateString, array $dutchMonths): string
{
    if (empty($dateString)) {
        return '';
    }

    $timestamp = strtotime($dateString);
    if (!$timestamp) {
        return '';
    }

    return date_i18n('j F Y', $timestamp);
}

/**
 * Get mode label and icon.
 */
function stride_trajectory_mode_info(string $mode): array
{
    return match ($mode) {
        'cohort' => [
            'label' => __('Cohort', 'stride'),
            'icon' => 'users',
            'class' => 'stride-label-soft-primary',
            'description' => __('Volg samen met een groep', 'stride'),
        ],
        'self_paced' => [
            'label' => __('Eigen tempo', 'stride'),
            'icon' => 'user',
            'class' => 'stride-label-soft-secondary',
            'description' => __('Op je eigen tempo', 'stride'),
        ],
        default => [
            'label' => __('Traject', 'stride'),
            'icon' => 'git-branch',
            'class' => 'stride-label-soft-secondary',
            'description' => '',
        ],
    };
}

// Enrich trajectories with computed data
$enrichedTrajectories = [];
foreach ($trajectories as $trajectory) {
    $trajectoryId = (int) ($trajectory['id'] ?? 0);

    if (!$trajectoryId) {
        continue;
    }

    // Get course count
    $courseCount = $trajectoryService->getCourseCount($trajectoryId);

    // Get mode info
    $mode = $trajectory['mode'] ?? 'cohort';
    $modeInfo = stride_trajectory_mode_info($mode);

    // Get thumbnail
    $thumbnail = get_the_post_thumbnail_url($trajectoryId, 'large');

    // Get excerpt/description
    $description = $trajectory['description'] ?? '';
    $excerpt = wp_trim_words(wp_strip_all_tags($description), 30, '...');

    // Get pricing
    $price = (float) ($trajectory['price'] ?? 0);
    $priceNonMember = (float) ($trajectory['price_non_member'] ?? 0);

    // Check enrollment status
    $enrollmentOpen = $trajectoryService->isEnrollmentOpen($trajectoryId);
    $enrollmentDeadline = $trajectory['enrollment_deadline'] ?? '';

    $enrichedTrajectories[] = [
        'id' => $trajectoryId,
        'title' => $trajectory['title'] ?? '',
        'description' => $description,
        'excerpt' => $excerpt,
        'mode' => $mode,
        'mode_info' => $modeInfo,
        'thumbnail' => $thumbnail,
        'course_count' => $courseCount,
        'price' => $price,
        'price_non_member' => $priceNonMember,
        'enrollment_open' => $enrollmentOpen,
        'enrollment_deadline' => $enrollmentDeadline,
        'enrollment_deadline_formatted' => stride_trajectory_format_date($enrollmentDeadline, $dutchMonths),
        'permalink' => get_permalink($trajectoryId),
    ];
}
?>

<div class="stride-trajectory-catalog">
    <!-- Page Header -->
    <header class="stride-catalog__header">
        <h1 class="stride-catalog__title"><?php esc_html_e('Trajecten', 'stride'); ?></h1>
        <p class="stride-catalog__subtitle">
            <?php esc_html_e('Ontdek onze leertrajecten - complete programmas om je professioneel te ontwikkelen.', 'stride'); ?>
        </p>
    </header>

    <?php if (!empty($enrichedTrajectories)) : ?>
        <!-- Trajectory List -->
        <div class="stride-trajectory-list">
            <?php foreach ($enrichedTrajectories as $trajectory) : ?>
                <article class="stride-trajectory-card <?php echo !$trajectory['enrollment_open'] ? 'stride-trajectory-card--closed' : ''; ?>">
                    <!-- Card Image / Gradient Background -->
                    <div class="stride-trajectory-card__visual">
                        <?php if ($trajectory['thumbnail']) : ?>
                            <img src="<?php echo esc_url($trajectory['thumbnail']); ?>"
                                 alt="<?php echo esc_attr($trajectory['title']); ?>"
                                 class="stride-trajectory-card__image"
                                 loading="lazy">
                        <?php else : ?>
                            <div class="stride-trajectory-card__gradient stride-trajectory-card__gradient--<?php echo esc_attr($trajectory['mode']); ?>">
                                <span uk-icon="icon: git-branch; ratio: 3" class="stride-trajectory-card__icon"></span>
                            </div>
                        <?php endif; ?>

                        <!-- Mode Badge -->
                        <span class="stride-trajectory-card__badge uk-label <?php echo esc_attr($trajectory['mode_info']['class']); ?>">
                            <span uk-icon="icon: <?php echo esc_attr($trajectory['mode_info']['icon']); ?>; ratio: 0.7"></span>
                            <?php echo esc_html($trajectory['mode_info']['label']); ?>
                        </span>
                    </div>

                    <!-- Card Content -->
                    <div class="stride-trajectory-card__content">
                        <div class="stride-trajectory-card__main">
                            <h2 class="stride-trajectory-card__title">
                                <a href="<?php echo esc_url($trajectory['permalink']); ?>">
                                    <?php echo esc_html($trajectory['title']); ?>
                                </a>
                            </h2>

                            <?php if ($trajectory['excerpt']) : ?>
                                <p class="stride-trajectory-card__excerpt">
                                    <?php echo esc_html($trajectory['excerpt']); ?>
                                </p>
                            <?php endif; ?>

                            <!-- Stats -->
                            <div class="stride-trajectory-card__stats">
                                <span class="stride-trajectory-card__stat">
                                    <span uk-icon="icon: list; ratio: 0.9"></span>
                                    <?php
                                    printf(
                                        esc_html(_n('%d module', '%d modules', $trajectory['course_count'], 'stride')),
                                        $trajectory['course_count']
                                    );
                                    ?>
                                </span>

                                <?php if ($trajectory['mode_info']['description']) : ?>
                                    <span class="stride-trajectory-card__stat">
                                        <span uk-icon="icon: <?php echo esc_attr($trajectory['mode_info']['icon']); ?>; ratio: 0.9"></span>
                                        <?php echo esc_html($trajectory['mode_info']['description']); ?>
                                    </span>
                                <?php endif; ?>

                                <?php if ($trajectory['enrollment_deadline_formatted']) : ?>
                                    <span class="stride-trajectory-card__stat stride-trajectory-card__stat--deadline">
                                        <span uk-icon="icon: clock; ratio: 0.9"></span>
                                        <?php
                                        printf(
                                            esc_html__('Inschrijven voor %s', 'stride'),
                                            $trajectory['enrollment_deadline_formatted']
                                        );
                                        ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Card Footer with Price and CTA -->
                        <div class="stride-trajectory-card__footer">
                            <div class="stride-trajectory-card__price-wrap">
                                <?php if ($trajectory['price'] > 0) : ?>
                                    <span class="stride-trajectory-card__price">
                                        <?php echo esc_html(number_format($trajectory['price'], 0, ',', '.')); ?>
                                        <span class="stride-trajectory-card__currency">&euro;</span>
                                    </span>
                                    <span class="stride-trajectory-card__price-label">
                                        <?php esc_html_e('ledenprijs', 'stride'); ?>
                                    </span>
                                <?php else : ?>
                                    <span class="stride-trajectory-card__price stride-trajectory-card__price--free">
                                        <?php esc_html_e('Gratis', 'stride'); ?>
                                    </span>
                                <?php endif; ?>
                            </div>

                            <div class="stride-trajectory-card__actions">
                                <?php if ($trajectory['enrollment_open']) : ?>
                                    <a href="<?php echo esc_url($trajectory['permalink']); ?>" class="uk-button uk-button-primary">
                                        <?php esc_html_e('Bekijk traject', 'stride'); ?>
                                        <span uk-icon="icon: arrow-right"></span>
                                    </a>
                                <?php else : ?>
                                    <span class="stride-trajectory-card__status stride-trajectory-card__status--closed">
                                        <span uk-icon="icon: lock; ratio: 0.9"></span>
                                        <?php esc_html_e('Inschrijving gesloten', 'stride'); ?>
                                    </span>
                                    <a href="<?php echo esc_url($trajectory['permalink']); ?>" class="uk-button uk-button-default">
                                        <?php esc_html_e('Meer info', 'stride'); ?>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>

    <?php else : ?>
        <!-- Empty State -->
        <div class="stride-empty-state">
            <div class="stride-empty-state__icon">
                <span uk-icon="icon: git-branch; ratio: 2"></span>
            </div>
            <h2 class="stride-empty-state__title">
                <?php esc_html_e('Geen trajecten beschikbaar', 'stride'); ?>
            </h2>
            <p class="stride-empty-state__description">
                <?php esc_html_e('Er zijn momenteel geen trajecten open voor inschrijving. Bekijk ons cursusaanbod of kom later terug.', 'stride'); ?>
            </p>
            <div class="stride-empty-state__action">
                <a href="<?php echo esc_url(home_url('/cursussen/')); ?>" class="uk-button uk-button-primary">
                    <?php esc_html_e('Bekijk cursussen', 'stride'); ?>
                </a>
            </div>
        </div>
    <?php endif; ?>
</div>
