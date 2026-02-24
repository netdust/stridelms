<?php
/**
 * Archive template for Trajectories
 *
 * Uses TrajectoryService for data access.
 *
 * @package stridence
 */

defined('ABSPATH') || exit;

use Stride\Modules\Trajectory\TrajectoryService;

get_header();

// Get trajectories via service
$trajectoryService = stride_service(TrajectoryService::class);
$trajectories = $trajectoryService->getOpenTrajectories();
?>

<main class="str-main">
    <div class="str-container">
        <section class="str-section">
            <header class="str-section__header">
                <h1 class="str-section__title"><?php esc_html_e('Trajecten', 'stridence'); ?></h1>
                <p class="str-section__subtitle">
                    <?php esc_html_e('Complete leerpaden voor diepgaande expertise', 'stridence'); ?>
                </p>
            </header>

            <?php if (!empty($trajectories)): ?>
                <div class="str-grid str-grid--trajectories">
                    <?php foreach ($trajectories as $trajectory): ?>
                        <article class="str-trajectory-card">
                            <div class="str-trajectory-card__image">
                                <?php if ($thumbnail = get_the_post_thumbnail_url($trajectory['id'], 'medium_large')): ?>
                                    <img src="<?php echo esc_url($thumbnail); ?>" alt="">
                                <?php else: ?>
                                    <div class="str-trajectory-card__placeholder">
                                        <?php stridence_icon('academic-cap', '', 48); ?>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="str-trajectory-card__content">
                                <h2 class="str-trajectory-card__title">
                                    <a href="<?php echo esc_url(get_permalink($trajectory['id'])); ?>">
                                        <?php echo esc_html($trajectory['title']); ?>
                                    </a>
                                </h2>

                                <?php if (!empty($trajectory['description'])): ?>
                                    <p class="str-trajectory-card__excerpt">
                                        <?php echo esc_html(wp_trim_words($trajectory['description'], 20)); ?>
                                    </p>
                                <?php endif; ?>

                                <div class="str-trajectory-card__meta">
                                    <?php $courseCount = count($trajectory['courses'] ?? []); ?>
                                    <?php if ($courseCount > 0): ?>
                                        <span class="str-trajectory-card__courses">
                                            <?php stridence_icon('book', '', 16); ?>
                                            <?php printf(
                                                _n('%d cursus', '%d cursussen', $courseCount, 'stridence'),
                                                $courseCount
                                            ); ?>
                                        </span>
                                    <?php endif; ?>

                                    <?php if (!empty($trajectory['enrollment_deadline'])): ?>
                                        <span class="str-trajectory-card__deadline">
                                            <?php stridence_icon('calendar', '', 16); ?>
                                            <?php echo esc_html(date_i18n('j F Y', strtotime($trajectory['enrollment_deadline']))); ?>
                                        </span>
                                    <?php endif; ?>
                                </div>

                                <?php if ($trajectory['price'] > 0): ?>
                                    <div class="str-trajectory-card__price">
                                        <?php echo esc_html(number_format($trajectory['price'], 0, ',', '.')); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="str-empty-state">
                    <?php stridence_icon('academic-cap', '', 48); ?>
                    <h2><?php esc_html_e('Geen trajecten gevonden', 'stridence'); ?></h2>
                    <p><?php esc_html_e('Er zijn momenteel geen trajecten beschikbaar.', 'stridence'); ?></p>
                </div>
            <?php endif; ?>
        </section>
    </div>
</main>

<?php get_footer(); ?>
