<?php
/**
 * Archive template for Trajectories
 *
 * @package stridence
 */

defined('ABSPATH') || exit;

get_header();

// Get trajectories
$trajectories = [];
$args = [
    'post_type' => 'vad_trajectory',
    'posts_per_page' => 12,
    'post_status' => 'publish',
    'paged' => get_query_var('paged') ?: 1,
];

$query = new WP_Query($args);

if ($query->have_posts()) {
    while ($query->have_posts()) {
        $query->the_post();
        $id = get_the_ID();

        $trajectories[] = [
            'id' => $id,
            'title' => get_the_title(),
            'url' => get_permalink(),
            'thumbnail' => get_the_post_thumbnail_url($id, 'medium_large'),
            'excerpt' => get_the_excerpt(),
            'course_count' => count(get_post_meta($id, '_course_ids', true) ?: []),
            'duration' => get_post_meta($id, '_duration', true) ?: '',
            'price' => (float) (get_post_meta($id, '_price', true) ?: 0),
        ];
    }
    wp_reset_postdata();
}
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
                                <?php if ($trajectory['thumbnail']): ?>
                                    <img src="<?php echo esc_url($trajectory['thumbnail']); ?>" alt="">
                                <?php else: ?>
                                    <div class="str-trajectory-card__placeholder">
                                        <?php stridence_icon('academic-cap', '', 48); ?>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="str-trajectory-card__content">
                                <h2 class="str-trajectory-card__title">
                                    <a href="<?php echo esc_url($trajectory['url']); ?>">
                                        <?php echo esc_html($trajectory['title']); ?>
                                    </a>
                                </h2>

                                <?php if ($trajectory['excerpt']): ?>
                                    <p class="str-trajectory-card__excerpt">
                                        <?php echo esc_html($trajectory['excerpt']); ?>
                                    </p>
                                <?php endif; ?>

                                <div class="str-trajectory-card__meta">
                                    <?php if ($trajectory['course_count']): ?>
                                        <span class="str-trajectory-card__courses">
                                            <?php stridence_icon('book', '', 16); ?>
                                            <?php printf(
                                                _n('%d cursus', '%d cursussen', $trajectory['course_count'], 'stridence'),
                                                $trajectory['course_count']
                                            ); ?>
                                        </span>
                                    <?php endif; ?>

                                    <?php if ($trajectory['duration']): ?>
                                        <span class="str-trajectory-card__duration">
                                            <?php stridence_icon('clock', '', 16); ?>
                                            <?php echo esc_html($trajectory['duration']); ?>
                                        </span>
                                    <?php endif; ?>
                                </div>

                                <?php if ($trajectory['price'] > 0): ?>
                                    <div class="str-trajectory-card__price">
                                        €<?php echo esc_html(number_format($trajectory['price'], 0, ',', '.')); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>

                <?php if ($query->max_num_pages > 1): ?>
                    <nav class="str-pagination">
                        <?php
                        echo paginate_links([
                            'total' => $query->max_num_pages,
                            'current' => get_query_var('paged') ?: 1,
                        ]);
                        ?>
                    </nav>
                <?php endif; ?>
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
