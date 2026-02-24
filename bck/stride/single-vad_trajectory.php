<?php
/**
 * Single Trajectory Template
 *
 * Displays a trajectory with its required courses, elective groups,
 * enrollment deadline, and call-to-action.
 *
 * @package stride
 */

use Stride\Domain\TrajectoryMode;
use Stride\Domain\TrajectoryStatus;
use Stride\Modules\Trajectory\TrajectoryService;
use Stride\Modules\Edition\EditionService;

get_header();

// Get services
$trajectoryService = ntdst_get(TrajectoryService::class);
$editionService = ntdst_get(EditionService::class);

$trajectoryId = get_the_ID();
$trajectory = $trajectoryService ? $trajectoryService->getTrajectory($trajectoryId) : null;

if (!$trajectory) {
    echo '<div class="uk-container"><div class="stride-card uk-padding">';
    echo '<p>' . esc_html__('Traject niet gevonden.', 'stride') . '</p>';
    echo '</div></div>';
    get_footer();
    return;
}

// Trajectory data
$mode = $trajectory['mode_enum'] ?? TrajectoryMode::Cohort;
$status = $trajectory['status_enum'] ?? TrajectoryStatus::Draft;
$isCohort = $mode === TrajectoryMode::Cohort;
$enrollmentDeadline = $trajectory['enrollment_deadline'] ?? null;
$choiceDeadline = $trajectory['choice_deadline'] ?? null;
$price = $trajectory['price'] ?? 0;
$priceNonMember = $trajectory['price_non_member'] ?? 0;
$capacity = $trajectory['capacity'] ?? 0;

// Enrollment status
$isEnrollmentOpen = $trajectoryService->isEnrollmentOpen($trajectoryId);
$isChoiceWindowOpen = $trajectoryService->isChoiceWindowOpen($trajectoryId);

// Get courses using dedicated service methods
$requiredCourses = $trajectoryService->getRequiredCourses($trajectoryId);
$electiveGroups = $trajectoryService->getElectiveGroups($trajectoryId);
$totalCourseCount = $trajectoryService->getCourseCount($trajectoryId);
$requiredCourseCount = count($requiredCourses);

// Status badge configuration
$statusConfig = match ($status) {
    TrajectoryStatus::Open => ['class' => 'uk-label-success', 'label' => __('Open voor inschrijving', 'stride')],
    TrajectoryStatus::InProgress => ['class' => 'stride-label-soft-secondary', 'label' => __('Lopend', 'stride')],
    TrajectoryStatus::Closed => ['class' => 'uk-label-warning', 'label' => __('Gesloten', 'stride')],
    TrajectoryStatus::Draft => ['class' => 'stride-label-soft-secondary', 'label' => __('Concept', 'stride')],
    TrajectoryStatus::Archived => ['class' => 'stride-label-soft-secondary', 'label' => __('Gearchiveerd', 'stride')],
};

// Mode badge configuration
$modeConfig = $isCohort
    ? ['class' => 'stride-badge-info', 'label' => __('Cohort', 'stride'), 'icon' => 'users']
    : ['class' => 'stride-badge-in-person', 'label' => __('Zelfstandig tempo', 'stride'), 'icon' => 'user'];

// Hero image
$heroImage = get_the_post_thumbnail_url($trajectoryId, 'large');
?>

<main class="stride-main stride-main--trajectory">
    <!-- Hero Section -->
    <section class="stride-hero stride-hero--trajectory">
        <?php if ($heroImage): ?>
            <div class="stride-hero__background" style="background-image: url('<?php echo esc_url($heroImage); ?>');">
                <div class="stride-hero__overlay"></div>
            </div>
        <?php else: ?>
            <div class="stride-hero__background stride-hero__background--gradient-secondary"></div>
        <?php endif; ?>

        <div class="uk-container">
            <div class="stride-hero__content">
                <nav class="uk-margin-bottom">
                    <ul class="uk-breadcrumb uk-breadcrumb-light">
                        <li><a href="<?php echo esc_url(home_url('/')); ?>"><?php esc_html_e('Home', 'stride'); ?></a></li>
                        <li><a href="<?php echo esc_url(home_url('/trajecten/')); ?>"><?php esc_html_e('Trajecten', 'stride'); ?></a></li>
                        <li><span><?php the_title(); ?></span></li>
                    </ul>
                </nav>

                <div class="stride-hero__badges uk-margin-small-bottom">
                    <span class="uk-label <?php echo esc_attr($statusConfig['class']); ?>">
                        <?php echo esc_html($statusConfig['label']); ?>
                    </span>
                    <span class="uk-label <?php echo esc_attr($modeConfig['class']); ?>">
                        <span uk-icon="icon: <?php echo esc_attr($modeConfig['icon']); ?>; ratio: 0.75"></span>
                        <?php echo esc_html($modeConfig['label']); ?>
                    </span>
                </div>

                <h1 class="stride-hero__title"><?php the_title(); ?></h1>

                <div class="stride-hero__meta">
                    <span class="stride-hero__meta-item">
                        <span uk-icon="icon: git-branch"></span>
                        <?php printf(
                            esc_html(_n('%d cursus', '%d cursussen', $totalCourseCount, 'stride')),
                            $totalCourseCount
                        ); ?>
                    </span>
                    <?php if ($enrollmentDeadline && $isEnrollmentOpen): ?>
                        <span class="stride-hero__meta-item stride-hero__meta-item--deadline">
                            <span uk-icon="icon: clock"></span>
                            <?php printf(
                                esc_html__('Inschrijven voor %s', 'stride'),
                                date_i18n('j F Y', strtotime($enrollmentDeadline))
                            ); ?>
                        </span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </section>

    <div class="uk-container uk-container-large uk-margin-large-top">
        <div uk-grid class="uk-grid-large">
            <!-- Main Content -->
            <div class="uk-width-2-3@m">
                <!-- Description -->
                <?php if (get_the_content()): ?>
                    <div class="stride-card uk-margin-bottom">
                        <div class="stride-card-header">
                            <h2 class="stride-card-title">
                                <span uk-icon="icon: info"></span>
                                <?php esc_html_e('Over dit traject', 'stride'); ?>
                            </h2>
                        </div>
                        <div class="stride-article-content uk-padding">
                            <?php the_content(); ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Required Courses -->
                <?php if (!empty($requiredCourses)): ?>
                    <div class="stride-card uk-margin-bottom">
                        <div class="stride-card-header">
                            <h2 class="stride-card-title">
                                <span uk-icon="icon: check"></span>
                                <?php esc_html_e('Verplichte cursussen', 'stride'); ?>
                            </h2>
                            <span class="stride-card-badge">
                                <?php printf(esc_html__('%d cursussen', 'stride'), $requiredCourseCount); ?>
                            </span>
                        </div>

                        <p class="uk-text-muted uk-padding uk-padding-remove-bottom uk-margin-remove">
                            <?php esc_html_e('Deze cursussen zijn verplicht om het traject succesvol af te ronden.', 'stride'); ?>
                        </p>

                        <div class="stride-modules-list uk-padding">
                            <?php foreach ($requiredCourses as $index => $courseConfig):
                                $courseId = $courseConfig['course_id'] ?? null;
                                if (!$courseId) continue;
                                $courseTitle = get_the_title($courseId);
                                $coursePermalink = get_permalink($courseId);
                                $courseExcerpt = get_the_excerpt($courseId);

                                // Get next edition for this course
                                $nextEdition = null;
                                if ($editionService) {
                                    $editions = $editionService->getEditionsForCourse($courseId);
                                    if (!empty($editions)) {
                                        $nextEdition = $editions[0];
                                    }
                                }
                            ?>
                                <div class="stride-module-item">
                                    <div class="stride-module-number">
                                        <?php echo esc_html($index + 1); ?>
                                    </div>
                                    <div class="stride-module-info uk-flex-1">
                                        <a href="<?php echo esc_url($coursePermalink); ?>" class="stride-module-title">
                                            <?php echo esc_html($courseTitle); ?>
                                        </a>
                                        <?php if ($courseExcerpt): ?>
                                            <p class="stride-module-excerpt uk-text-small uk-text-muted uk-margin-remove">
                                                <?php echo esc_html(wp_trim_words($courseExcerpt, 15)); ?>
                                            </p>
                                        <?php endif; ?>
                                        <?php if ($nextEdition): ?>
                                            <div class="stride-module-meta uk-text-small uk-text-muted uk-margin-small-top">
                                                <span uk-icon="icon: calendar; ratio: 0.7"></span>
                                                <?php esc_html_e('Volgende editie beschikbaar', 'stride'); ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <a href="<?php echo esc_url($coursePermalink); ?>" class="uk-button uk-button-default uk-button-small">
                                        <?php esc_html_e('Bekijk', 'stride'); ?>
                                    </a>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Elective Groups -->
                <?php if (!empty($electiveGroups)): ?>
                    <?php foreach ($electiveGroups as $groupName => $groupCourses):
                        $electiveCount = count($groupCourses);
                        // Parse group name for required count (e.g., "Keuze (2)" means pick 2)
                        $requiredPicks = 1;
                        if (preg_match('/\((\d+)\)/', $groupName, $matches)) {
                            $requiredPicks = (int) $matches[1];
                            $groupName = trim(preg_replace('/\(\d+\)/', '', $groupName));
                        }
                    ?>
                        <div class="stride-card uk-margin-bottom">
                            <div class="stride-card-header">
                                <h2 class="stride-card-title">
                                    <span uk-icon="icon: plus-circle"></span>
                                    <?php echo esc_html($groupName ?: __('Keuzecursussen', 'stride')); ?>
                                </h2>
                                <span class="stride-card-badge stride-card-badge--elective">
                                    <?php printf(
                                        esc_html(_n('Kies %d van %d', 'Kies %d van %d', $requiredPicks, 'stride')),
                                        $requiredPicks,
                                        $electiveCount
                                    ); ?>
                                </span>
                            </div>

                            <p class="uk-text-muted uk-padding uk-padding-remove-bottom uk-margin-remove">
                                <?php if ($requiredPicks === 1): ?>
                                    <?php esc_html_e('Kies een van de onderstaande cursussen om je traject te completeren.', 'stride'); ?>
                                <?php else: ?>
                                    <?php printf(
                                        esc_html__('Kies %d van de onderstaande cursussen om je traject te completeren.', 'stride'),
                                        $requiredPicks
                                    ); ?>
                                <?php endif; ?>
                            </p>

                            <div class="stride-modules-list uk-padding">
                                <?php foreach ($groupCourses as $courseConfig):
                                    $courseId = $courseConfig['course_id'] ?? null;
                                    if (!$courseId) continue;
                                    $courseTitle = get_the_title($courseId);
                                    $coursePermalink = get_permalink($courseId);
                                    $courseExcerpt = get_the_excerpt($courseId);
                                ?>
                                    <div class="stride-module-item stride-module-item--elective">
                                        <div class="stride-module-checkbox">
                                            <span uk-icon="icon: plus; ratio: 0.8"></span>
                                        </div>
                                        <div class="stride-module-info uk-flex-1">
                                            <a href="<?php echo esc_url($coursePermalink); ?>" class="stride-module-title">
                                                <?php echo esc_html($courseTitle); ?>
                                            </a>
                                            <?php if ($courseExcerpt): ?>
                                                <p class="stride-module-excerpt uk-text-small uk-text-muted uk-margin-remove">
                                                    <?php echo esc_html(wp_trim_words($courseExcerpt, 15)); ?>
                                                </p>
                                            <?php endif; ?>
                                        </div>
                                        <a href="<?php echo esc_url($coursePermalink); ?>" class="uk-button uk-button-default uk-button-small">
                                            <?php esc_html_e('Bekijk', 'stride'); ?>
                                        </a>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Sidebar -->
            <div class="uk-width-1-3@m">
                <div class="stride-course-sidebar" uk-sticky="offset: 100; bottom: true; media: @m;">
                    <div class="stride-course-info-card">
                        <div class="stride-course-info-header" style="background: linear-gradient(135deg, var(--stride-secondary) 0%, var(--stride-secondary-hover) 100%);">
                            <?php if ($price > 0): ?>
                                <p class="stride-course-price">&euro; <?php echo number_format($price, 2, ',', '.'); ?></p>
                                <p class="stride-course-price-label"><?php esc_html_e('excl. BTW', 'stride'); ?></p>
                            <?php else: ?>
                                <p class="stride-course-price" style="font-size: 1.25rem;">
                                    <?php echo $isCohort ? esc_html__('Groepstraject', 'stride') : esc_html__('Individueel traject', 'stride'); ?>
                                </p>
                                <p class="stride-course-price-label">
                                    <?php printf(esc_html__('%d cursussen', 'stride'), $totalCourseCount); ?>
                                </p>
                            <?php endif; ?>
                        </div>

                        <div class="stride-course-info-body">
                            <ul class="stride-course-info-list">
                                <li class="stride-course-info-item">
                                    <span class="stride-course-info-icon" uk-icon="icon: check; ratio: 0.9"></span>
                                    <span>
                                        <?php printf(
                                            esc_html(_n('%d verplichte cursus', '%d verplichte cursussen', $requiredCourseCount, 'stride')),
                                            $requiredCourseCount
                                        ); ?>
                                    </span>
                                </li>

                                <?php if (!empty($electiveGroups)):
                                    $totalElectives = array_sum(array_map('count', $electiveGroups));
                                ?>
                                    <li class="stride-course-info-item">
                                        <span class="stride-course-info-icon" uk-icon="icon: plus-circle; ratio: 0.9"></span>
                                        <span>
                                            <?php printf(
                                                esc_html(_n('%d keuzecursus', '%d keuzecursussen', $totalElectives, 'stride')),
                                                $totalElectives
                                            ); ?>
                                        </span>
                                    </li>
                                <?php endif; ?>

                                <li class="stride-course-info-item">
                                    <span class="stride-course-info-icon" uk-icon="icon: <?php echo $isCohort ? 'users' : 'user'; ?>; ratio: 0.9"></span>
                                    <span>
                                        <?php echo $isCohort
                                            ? esc_html__('Volg samen met een groep', 'stride')
                                            : esc_html__('Op je eigen tempo', 'stride'); ?>
                                    </span>
                                </li>

                                <?php if ($capacity > 0): ?>
                                    <li class="stride-course-info-item">
                                        <span class="stride-course-info-icon" uk-icon="icon: users; ratio: 0.9"></span>
                                        <span>
                                            <?php printf(esc_html__('Max. %d deelnemers', 'stride'), $capacity); ?>
                                        </span>
                                    </li>
                                <?php endif; ?>
                            </ul>

                            <!-- Enrollment Deadline Alert -->
                            <?php if ($enrollmentDeadline && $isEnrollmentOpen): ?>
                                <div class="stride-deadline-alert uk-margin-bottom">
                                    <span uk-icon="icon: clock; ratio: 0.9"></span>
                                    <div>
                                        <strong><?php esc_html_e('Inschrijfdeadline', 'stride'); ?></strong>
                                        <p class="uk-margin-remove uk-text-small">
                                            <?php echo esc_html(date_i18n('l j F Y', strtotime($enrollmentDeadline))); ?>
                                        </p>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <!-- CTA Button -->
                            <?php if ($status === TrajectoryStatus::Closed || $status === TrajectoryStatus::Archived): ?>
                                <div class="uk-alert uk-alert-warning uk-margin-bottom">
                                    <?php esc_html_e('Dit traject is momenteel gesloten voor inschrijvingen.', 'stride'); ?>
                                </div>
                            <?php elseif (!$isEnrollmentOpen && $enrollmentDeadline): ?>
                                <div class="uk-alert uk-alert-warning uk-margin-bottom">
                                    <?php esc_html_e('De inschrijfperiode voor dit traject is verstreken.', 'stride'); ?>
                                </div>
                            <?php elseif (is_user_logged_in()): ?>
                                <a href="<?php echo esc_url(get_permalink($trajectoryId) . 'inschrijving/'); ?>" class="stride-course-action-btn uk-button uk-button-primary">
                                    <?php esc_html_e('Start dit traject', 'stride'); ?>
                                </a>
                            <?php else: ?>
                                <a href="<?php echo esc_url(wp_login_url(get_permalink())); ?>" class="stride-course-action-btn uk-button uk-button-primary">
                                    <?php esc_html_e('Log in om te starten', 'stride'); ?>
                                </a>
                                <p class="uk-text-small uk-text-center uk-text-muted uk-margin-small-top">
                                    <?php printf(
                                        esc_html__('Nog geen account? %sRegistreer hier%s', 'stride'),
                                        '<a href="' . esc_url(wp_registration_url()) . '">',
                                        '</a>'
                                    ); ?>
                                </p>
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
                <?php if ($price > 0): ?>
                    <span class="stride-sticky-cta__price-value">&euro; <?php echo number_format($price, 2, ',', '.'); ?></span>
                    <span class="stride-sticky-cta__price-label"><?php esc_html_e('excl. BTW', 'stride'); ?></span>
                <?php else: ?>
                    <span class="stride-sticky-cta__price-value">
                        <?php printf(esc_html__('%d cursussen', 'stride'), $totalCourseCount); ?>
                    </span>
                    <span class="stride-sticky-cta__price-label"><?php echo esc_html($modeConfig['label']); ?></span>
                <?php endif; ?>
            </div>
            <div class="stride-sticky-cta__action">
                <?php if (!$isEnrollmentOpen): ?>
                    <span class="uk-text-warning"><?php esc_html_e('Gesloten', 'stride'); ?></span>
                <?php elseif (!is_user_logged_in()): ?>
                    <a href="<?php echo esc_url(wp_login_url(get_permalink())); ?>" class="uk-button uk-button-primary uk-button-small">
                        <?php esc_html_e('Log in', 'stride'); ?>
                    </a>
                <?php else: ?>
                    <a href="<?php echo esc_url(get_permalink($trajectoryId) . 'inschrijving/'); ?>" class="uk-button uk-button-primary uk-button-small">
                        <?php esc_html_e('Start traject', 'stride'); ?>
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</main>

<?php get_footer(); ?>
