<?php
/**
 * Trajectory Archive Template
 *
 * Displays a grid of trajectory cards with key info and enrollment CTAs.
 *
 * @package stride
 */

use Stride\Domain\TrajectoryMode;
use Stride\Domain\TrajectoryStatus;
use Stride\Modules\Trajectory\TrajectoryService;

get_header();

$trajectoryService = ntdst_get(TrajectoryService::class);
?>

<main class="stride-main stride-main--archive">
    <!-- Hero Section -->
    <section class="stride-hero stride-hero--archive">
        <div class="stride-hero__background stride-hero__background--gradient-secondary"></div>
        <div class="uk-container">
            <div class="stride-hero__content uk-text-center">
                <h1 class="stride-hero__title"><?php esc_html_e('Trajecten', 'stride'); ?></h1>
                <p class="stride-hero__subtitle">
                    <?php esc_html_e('Combineer meerdere cursussen en bouw gestructureerd je expertise op.', 'stride'); ?>
                </p>
            </div>
        </div>
    </section>

    <div class="uk-container uk-container-large uk-margin-large-top uk-margin-large-bottom">
        <?php if (have_posts()) : ?>
            <div class="uk-grid uk-grid-match uk-child-width-1-1 uk-child-width-1-2@s uk-child-width-1-3@l" uk-grid>
                <?php while (have_posts()) : the_post();
                    $trajectoryId = get_the_ID();
                    $trajectory = $trajectoryService ? $trajectoryService->getTrajectory($trajectoryId) : null;

                    // Trajectory data
                    $mode = $trajectory['mode_enum'] ?? TrajectoryMode::Cohort;
                    $status = $trajectory['status_enum'] ?? TrajectoryStatus::Draft;
                    $price = $trajectory['price'] ?? 0;
                    $totalCourseCount = $trajectoryService ? $trajectoryService->getCourseCount($trajectoryId) : 0;
                    $isEnrollmentOpen = $trajectoryService ? $trajectoryService->isEnrollmentOpen($trajectoryId) : false;

                    // Status configuration
                    $statusConfig = match ($status) {
                        TrajectoryStatus::Open => ['class' => 'uk-label-success', 'label' => __('Open', 'stride')],
                        TrajectoryStatus::InProgress => ['class' => 'stride-label-soft-secondary', 'label' => __('Lopend', 'stride')],
                        TrajectoryStatus::Closed => ['class' => 'uk-label-warning', 'label' => __('Gesloten', 'stride')],
                        TrajectoryStatus::Draft => ['class' => 'stride-label-soft-secondary', 'label' => __('Concept', 'stride')],
                        TrajectoryStatus::Archived => ['class' => 'stride-label-soft-secondary', 'label' => __('Gearchiveerd', 'stride')],
                    };

                    // Mode configuration
                    $isCohort = $mode === TrajectoryMode::Cohort;
                    $modeLabel = $isCohort ? __('Cohort', 'stride') : __('Zelfstandig', 'stride');
                    $modeIcon = $isCohort ? 'users' : 'user';

                    // Hero image
                    $heroImage = get_the_post_thumbnail_url($trajectoryId, 'medium_large');
                ?>
                    <div>
                        <article id="post-<?php the_ID(); ?>" <?php post_class('stride-trajectory-card'); ?>>
                            <a href="<?php the_permalink(); ?>" class="stride-trajectory-card__link">
                                <!-- Card Image -->
                                <div class="stride-trajectory-card__image">
                                    <?php if ($heroImage) : ?>
                                        <img src="<?php echo esc_url($heroImage); ?>" alt="<?php the_title_attribute(); ?>" loading="lazy">
                                    <?php else : ?>
                                        <div class="stride-trajectory-card__placeholder">
                                            <span uk-icon="icon: git-branch; ratio: 2"></span>
                                        </div>
                                    <?php endif; ?>

                                    <!-- Status Badge -->
                                    <span class="stride-trajectory-card__badge uk-label <?php echo esc_attr($statusConfig['class']); ?>">
                                        <?php echo esc_html($statusConfig['label']); ?>
                                    </span>
                                </div>

                                <!-- Card Body -->
                                <div class="stride-trajectory-card__body">
                                    <h3 class="stride-trajectory-card__title"><?php the_title(); ?></h3>

                                    <?php if (has_excerpt()) : ?>
                                        <p class="stride-trajectory-card__excerpt">
                                            <?php echo esc_html(wp_trim_words(get_the_excerpt(), 15)); ?>
                                        </p>
                                    <?php endif; ?>

                                    <!-- Meta Info -->
                                    <div class="stride-trajectory-card__meta">
                                        <span class="stride-trajectory-card__meta-item">
                                            <span uk-icon="icon: git-branch; ratio: 0.8"></span>
                                            <?php printf(esc_html__('%d cursussen', 'stride'), $totalCourseCount); ?>
                                        </span>
                                        <span class="stride-trajectory-card__meta-item">
                                            <span uk-icon="icon: <?php echo esc_attr($modeIcon); ?>; ratio: 0.8"></span>
                                            <?php echo esc_html($modeLabel); ?>
                                        </span>
                                    </div>
                                </div>

                                <!-- Card Footer -->
                                <div class="stride-trajectory-card__footer">
                                    <?php if ($price > 0) : ?>
                                        <span class="stride-trajectory-card__price">
                                            &euro; <?php echo number_format($price, 0, ',', '.'); ?>
                                        </span>
                                    <?php else : ?>
                                        <span class="stride-trajectory-card__price stride-trajectory-card__price--free">
                                            <?php esc_html_e('Prijs op aanvraag', 'stride'); ?>
                                        </span>
                                    <?php endif; ?>

                                    <span class="stride-trajectory-card__cta uk-button uk-button-primary uk-button-small">
                                        <?php if ($isEnrollmentOpen) : ?>
                                            <?php esc_html_e('Bekijk traject', 'stride'); ?>
                                        <?php else : ?>
                                            <?php esc_html_e('Meer info', 'stride'); ?>
                                        <?php endif; ?>
                                    </span>
                                </div>
                            </a>
                        </article>
                    </div>
                <?php endwhile; ?>
            </div>

            <!-- Pagination -->
            <div class="uk-margin-large-top">
                <?php the_posts_pagination([
                    'mid_size' => 2,
                    'prev_text' => '<span uk-icon="icon: chevron-left"></span> ' . __('Vorige', 'stride'),
                    'next_text' => __('Volgende', 'stride') . ' <span uk-icon="icon: chevron-right"></span>',
                    'class' => 'uk-pagination uk-flex-center',
                ]); ?>
            </div>

        <?php else : ?>
            <div class="stride-card uk-padding-large uk-text-center">
                <div class="stride-empty-state">
                    <span class="stride-empty-state-icon" uk-icon="icon: git-branch; ratio: 3"></span>
                    <h3 class="stride-empty-state-title"><?php esc_html_e('Geen trajecten beschikbaar', 'stride'); ?></h3>
                    <p class="stride-empty-state-text">
                        <?php esc_html_e('Er zijn momenteel geen trajecten gepubliceerd. Bekijk onze individuele cursussen.', 'stride'); ?>
                    </p>
                    <a href="<?php echo esc_url(home_url('/vormingen/')); ?>" class="uk-button uk-button-primary">
                        <?php esc_html_e('Bekijk cursussen', 'stride'); ?>
                    </a>
                </div>
            </div>
        <?php endif; ?>
    </div>
</main>

<?php get_footer(); ?>
