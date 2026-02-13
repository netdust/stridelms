<?php
/**
 * Single Course Template
 *
 * Displays a LearnDash course with its upcoming editions.
 *
 * @package stride
 */

get_header();

// Get services
$courseService = stride_service(\ntdst\Stride\core\CourseService::class);
$editionService = stride_service(\ntdst\Stride\core\EditionService::class);

$courseId = get_the_ID();
$isOnline = $courseService ? $courseService->isOnline($courseId) : false;
$isInPerson = $courseService ? $courseService->isInPerson($courseId) : true;

// Get upcoming editions for this course
$editions = [];
if ($editionService) {
    $editions = $editionService->getUpcomingEditionsForCourse($courseId);
}

// Type badge
$typeBadgeClass = $isOnline ? 'stride-badge-online' : 'stride-badge-in-person';
$typeLabel = $isOnline ? __('Online', 'stride') : __('In-person', 'stride');
?>

<div class="uk-container uk-container-large">
    <?php if (have_posts()) : while (have_posts()) : the_post(); ?>
        <article id="post-<?php the_ID(); ?>" <?php post_class('stride-article stride-course-single'); ?>>
            <!-- Course Header -->
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
                        <span class="stride-badge <?php echo esc_attr($typeBadgeClass); ?>">
                            <?php echo esc_html($typeLabel); ?>
                        </span>
                    </div>
                </div>
            </header>

            <div class="stride-course-content uk-margin-large-top">
                <div uk-grid class="uk-grid-large">
                    <!-- Main Content -->
                    <div class="uk-width-2-3@m">
                        <!-- Course Description -->
                        <div class="stride-card uk-margin-bottom">
                            <div class="stride-card-header">
                                <h2 class="stride-card-title">
                                    <span uk-icon="icon: info"></span>
                                    <?php esc_html_e('Over deze cursus', 'stride'); ?>
                                </h2>
                            </div>
                            <div class="stride-article-content">
                                <?php the_content(); ?>
                            </div>
                        </div>

                        <?php if (!$isOnline && !empty($editions)): ?>
                            <!-- Upcoming Editions -->
                            <div class="stride-card">
                                <div class="stride-card-header">
                                    <h2 class="stride-card-title">
                                        <span uk-icon="icon: calendar"></span>
                                        <?php esc_html_e('Komende edities', 'stride'); ?>
                                    </h2>
                                </div>

                                <div class="stride-editions-list">
                                    <?php foreach ($editions as $edition):
                                        $editionId = $edition['id'];
                                        $startDateStr = $editionService->getStartDate($editionId);
                                        $endDateStr = $editionService->getEndDate($editionId);
                                        $startDate = $startDateStr ? strtotime($startDateStr) : null;
                                        $endDate = $endDateStr ? strtotime($endDateStr) : null;
                                        $venue = $editionService->getVenue($editionId);
                                        $price = $editionService->getPrice($editionId);
                                        $isFull = $editionService->isFull($editionId);
                                        $availableSpots = $editionService->getAvailableSpots($editionId);
                                        $speakers = $editionService->getSpeakers($editionId);
                                    ?>
                                        <div class="stride-edition-item <?php echo $isFull ? 'is-full' : ''; ?>">
                                            <div class="stride-edition-date">
                                                <?php if ($startDate): ?>
                                                    <div class="stride-upcoming-day"><?php echo esc_html(date_i18n('j', $startDate)); ?></div>
                                                    <div class="stride-upcoming-month"><?php echo esc_html(date_i18n('M', $startDate)); ?></div>
                                                <?php endif; ?>
                                            </div>

                                            <div class="stride-edition-info uk-flex-1">
                                                <div class="uk-flex uk-flex-middle uk-flex-wrap" style="gap: 8px;">
                                                    <?php if ($startDate): ?>
                                                        <strong><?php echo esc_html(date_i18n('l j F Y', $startDate)); ?></strong>
                                                    <?php endif; ?>
                                                    <?php if ($endDate && $endDate !== $startDate): ?>
                                                        <span class="uk-text-muted">- <?php echo esc_html(date_i18n('j F Y', $endDate)); ?></span>
                                                    <?php endif; ?>
                                                    <?php if ($isFull): ?>
                                                        <span class="stride-badge stride-badge-pending"><?php esc_html_e('Volzet', 'stride'); ?></span>
                                                    <?php elseif ($availableSpots !== null && $availableSpots <= 5): ?>
                                                        <span class="stride-badge stride-badge-in-progress">
                                                            <?php printf(esc_html__('Nog %d plaatsen', 'stride'), $availableSpots); ?>
                                                        </span>
                                                    <?php endif; ?>
                                                </div>

                                                <?php if ($venue): ?>
                                                    <div class="uk-text-muted uk-text-small uk-margin-small-top">
                                                        <span uk-icon="icon: location; ratio: 0.8"></span>
                                                        <?php echo esc_html($venue); ?>
                                                    </div>
                                                <?php endif; ?>

                                                <?php if (!empty($speakers)): ?>
                                                    <div class="uk-text-muted uk-text-small">
                                                        <span uk-icon="icon: user; ratio: 0.8"></span>
                                                        <?php echo esc_html(implode(', ', array_column($speakers, 'name'))); ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>

                                            <div class="stride-edition-action">
                                                <?php if ($price !== null): ?>
                                                    <div class="uk-text-bold uk-margin-small-bottom">
                                                        <?php echo esc_html('€ ' . number_format($price, 2, ',', '.')); ?>
                                                    </div>
                                                <?php endif; ?>

                                                <?php if ($isFull): ?>
                                                    <button class="uk-button uk-button-default uk-button-small" disabled>
                                                        <?php esc_html_e('Volzet', 'stride'); ?>
                                                    </button>
                                                <?php else: ?>
                                                    <a href="<?php echo esc_url(get_permalink($editionId)); ?>"
                                                       class="uk-button uk-button-primary uk-button-small">
                                                        <?php esc_html_e('Inschrijven', 'stride'); ?>
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php elseif ($isOnline): ?>
                            <!-- Online Course CTA -->
                            <div class="stride-card">
                                <div class="stride-card-header">
                                    <h2 class="stride-card-title">
                                        <span uk-icon="icon: play-circle"></span>
                                        <?php esc_html_e('Direct beschikbaar', 'stride'); ?>
                                    </h2>
                                </div>
                                <p><?php esc_html_e('Deze online cursus is direct beschikbaar na inschrijving. Je kunt op elk moment beginnen en in je eigen tempo de modules doorlopen.', 'stride'); ?></p>
                                <a href="#enroll" class="uk-button uk-button-primary">
                                    <?php esc_html_e('Schrijf je in', 'stride'); ?>
                                </a>
                            </div>
                        <?php else: ?>
                            <!-- No editions available -->
                            <div class="stride-card">
                                <div class="stride-empty-state">
                                    <span class="stride-empty-state-icon" uk-icon="icon: calendar; ratio: 2"></span>
                                    <h3 class="stride-empty-state-title"><?php esc_html_e('Geen geplande edities', 'stride'); ?></h3>
                                    <p class="stride-empty-state-text"><?php esc_html_e('Er zijn momenteel geen edities gepland voor deze cursus.', 'stride'); ?></p>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Sidebar -->
                    <div class="uk-width-1-3@m">
                        <?php echo do_shortcode('[stride_course_sidebar]'); ?>
                    </div>
                </div>
            </div>
        </article>
    <?php endwhile; endif; ?>
</div>

<?php get_footer(); ?>
