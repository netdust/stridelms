<?php
/**
 * Single Trajectory Template
 *
 * Displays a trajectory with its modules and enrollment options.
 *
 * @package stride
 */

get_header();

// Get services
$trajectoryService = stride_service(\ntdst\Stride\core\TrajectoryService::class);
$editionService = stride_service(\ntdst\Stride\core\EditionService::class);

$trajectoryId = get_the_ID();
$trajectory = $trajectoryService ? $trajectoryService->getTrajectory($trajectoryId) : null;

if (!$trajectory) {
    echo '<div class="uk-container"><div class="stride-card"><p>' . esc_html__('Traject niet gevonden.', 'stride') . '</p></div></div>';
    get_footer();
    return;
}

$mode = $trajectory['mode'] ?? 'self_paced';
$isCohort = $mode === 'cohort';
$courses = $trajectory['courses'] ?? [];
$enrollmentDeadline = $trajectory['enrollment_deadline'] ?? null;
$choiceDeadline = $trajectory['choice_deadline'] ?? null;

// Status badge
$statusClass = $isCohort ? 'stride-badge-info' : 'stride-badge-in-person';
$statusLabel = $isCohort ? __('Cohort', 'stride') : __('Zelfstandig tempo', 'stride');

// Group courses by mandatory/elective
$mandatoryModules = array_filter($courses, fn($r) => ($r['group'] ?? 'mandatory') !== 'elective');
$electiveModules = array_filter($courses, fn($r) => ($r['group'] ?? '') === 'elective');
?>

<div class="uk-container uk-container-large">
    <article class="stride-article stride-trajectory-single">
        <!-- Trajectory Header -->
        <header class="stride-course-header">
            <div class="uk-container">
                <nav class="uk-margin-bottom">
                    <ul class="uk-breadcrumb">
                        <li><a href="<?php echo esc_url(home_url('/')); ?>"><?php esc_html_e('Home', 'stride'); ?></a></li>
                        <li><a href="<?php echo esc_url(home_url('/trajecten/')); ?>"><?php esc_html_e('Trajecten', 'stride'); ?></a></li>
                        <li><span><?php the_title(); ?></span></li>
                    </ul>
                </nav>

                <div class="uk-flex uk-flex-middle uk-flex-wrap" style="gap: 12px;">
                    <h1 class="stride-page-title uk-margin-remove"><?php the_title(); ?></h1>
                    <span class="stride-badge <?php echo esc_attr($statusClass); ?>">
                        <?php echo esc_html($statusLabel); ?>
                    </span>
                </div>

                <p class="uk-text-lead uk-margin-small-top uk-margin-remove-bottom">
                    <span uk-icon="icon: git-branch"></span>
                    <?php printf(
                        esc_html(_n('%d module', '%d modules', count($courses), 'stride')),
                        count($courses)
                    ); ?>
                </p>
            </div>
        </header>

        <div class="stride-trajectory-content uk-margin-large-top">
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
                            <div class="stride-article-content">
                                <?php the_content(); ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Mandatory Modules -->
                    <?php if (!empty($mandatoryModules)): ?>
                        <div class="stride-card uk-margin-bottom">
                            <div class="stride-card-header">
                                <h2 class="stride-card-title">
                                    <span uk-icon="icon: list"></span>
                                    <?php esc_html_e('Verplichte modules', 'stride'); ?>
                                </h2>
                            </div>

                            <div class="stride-modules-list">
                                <?php foreach ($mandatoryModules as $index => $requirement):
                                    $courseId = $requirement['course_id'] ?? null;
                                    if (!$courseId) continue;
                                    $courseTitle = get_the_title($courseId);
                                    $coursePermalink = get_permalink($courseId);

                                    // Get next edition for this course
                                    $nextDate = null;
                                    if ($editionService) {
                                        $editions = $editionService->getUpcomingEditionsForCourse($courseId);
                                        if (!empty($editions)) {
                                            $nextDateStr = $editionService->getStartDate($editions[0]['id']);
                                            $nextDate = $nextDateStr ? strtotime($nextDateStr) : null;
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
                                            <?php if ($nextDate): ?>
                                                <div class="uk-text-small uk-text-muted">
                                                    <span uk-icon="icon: calendar; ratio: 0.7"></span>
                                                    <?php printf(esc_html__('Volgende editie: %s', 'stride'), date_i18n('j F Y', $nextDate)); ?>
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

                    <!-- Elective Modules -->
                    <?php if (!empty($electiveModules)): ?>
                        <div class="stride-card">
                            <div class="stride-card-header">
                                <h2 class="stride-card-title">
                                    <span uk-icon="icon: plus-circle"></span>
                                    <?php esc_html_e('Keuzemodules', 'stride'); ?>
                                </h2>
                            </div>

                            <p class="uk-text-muted uk-margin-bottom">
                                <?php esc_html_e('Kies een of meer modules om je traject te completeren.', 'stride'); ?>
                            </p>

                            <div class="stride-modules-list">
                                <?php foreach ($electiveModules as $requirement):
                                    $courseId = $requirement['course_id'] ?? null;
                                    if (!$courseId) continue;
                                    $courseTitle = get_the_title($courseId);
                                    $coursePermalink = get_permalink($courseId);
                                ?>
                                    <div class="stride-module-item elective">
                                        <div class="stride-module-checkbox">
                                            <span uk-icon="icon: plus; ratio: 0.8"></span>
                                        </div>
                                        <div class="stride-module-info uk-flex-1">
                                            <a href="<?php echo esc_url($coursePermalink); ?>" class="stride-module-title">
                                                <?php echo esc_html($courseTitle); ?>
                                            </a>
                                        </div>
                                        <a href="<?php echo esc_url($coursePermalink); ?>" class="uk-button uk-button-default uk-button-small">
                                            <?php esc_html_e('Bekijk', 'stride'); ?>
                                        </a>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Sidebar -->
                <div class="uk-width-1-3@m">
                    <div class="stride-course-sidebar">
                        <div class="stride-course-info-card">
                            <div class="stride-course-info-header" style="background: var(--stride-secondary);">
                                <p class="stride-course-price" style="font-size: 1.25rem;">
                                    <?php echo $isCohort ? esc_html__('Groepstraject', 'stride') : esc_html__('Individueel traject', 'stride'); ?>
                                </p>
                                <p class="stride-course-price-label">
                                    <?php printf(esc_html__('%d modules', 'stride'), count($courses)); ?>
                                </p>
                            </div>

                            <div class="stride-course-info-body">
                                <ul class="stride-course-info-list">
                                    <li class="stride-course-info-item">
                                        <span class="stride-course-info-icon" uk-icon="icon: git-branch; ratio: 0.9"></span>
                                        <span>
                                            <?php printf(esc_html__('%d verplichte modules', 'stride'), count($mandatoryModules)); ?>
                                        </span>
                                    </li>

                                    <?php if (!empty($electiveModules)): ?>
                                        <li class="stride-course-info-item">
                                            <span class="stride-course-info-icon" uk-icon="icon: plus-circle; ratio: 0.9"></span>
                                            <span>
                                                <?php printf(esc_html__('%d keuzemodules', 'stride'), count($electiveModules)); ?>
                                            </span>
                                        </li>
                                    <?php endif; ?>

                                    <?php if ($isCohort && $enrollmentDeadline): ?>
                                        <li class="stride-course-info-item">
                                            <span class="stride-course-info-icon" uk-icon="icon: clock; ratio: 0.9"></span>
                                            <span>
                                                <?php printf(esc_html__('Inschrijven voor %s', 'stride'), date_i18n('j F Y', strtotime($enrollmentDeadline))); ?>
                                            </span>
                                        </li>
                                    <?php endif; ?>

                                    <li class="stride-course-info-item">
                                        <span class="stride-course-info-icon" uk-icon="icon: <?php echo $isCohort ? 'users' : 'user'; ?>; ratio: 0.9"></span>
                                        <span>
                                            <?php echo $isCohort ? esc_html__('Volg samen met een groep', 'stride') : esc_html__('Op je eigen tempo', 'stride'); ?>
                                        </span>
                                    </li>
                                </ul>

                                <?php if (is_user_logged_in()): ?>
                                    <a href="<?php echo esc_url(home_url('/mijn-account/trajecten/')); ?>" class="stride-course-action-btn uk-button uk-button-primary">
                                        <?php esc_html_e('Start dit traject', 'stride'); ?>
                                    </a>
                                <?php else: ?>
                                    <a href="<?php echo esc_url(wp_login_url(get_permalink())); ?>" class="stride-course-action-btn uk-button uk-button-primary">
                                        <?php esc_html_e('Log in om te starten', 'stride'); ?>
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
