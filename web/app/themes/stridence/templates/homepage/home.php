<?php
/**
 * Homepage Template
 *
 * @package stridence
 */

defined('ABSPATH') || exit;

get_header();

// Get featured courses (limit 6)
$featuredCourses = [];
$args = [
    'post_type' => 'sfwd-courses',
    'posts_per_page' => 6,
    'post_status' => 'publish',
    'meta_query' => [
        [
            'key' => '_featured',
            'value' => '1',
            'compare' => '=',
        ],
    ],
];
$query = new WP_Query($args);

// If no featured courses, just get recent ones
if (!$query->have_posts()) {
    $args = [
        'post_type' => 'sfwd-courses',
        'posts_per_page' => 6,
        'post_status' => 'publish',
    ];
    $query = new WP_Query($args);
}

if ($query->have_posts()) {
    while ($query->have_posts()) {
        $query->the_post();
        $courseId = get_the_ID();
        $price = get_post_meta($courseId, '_ld_course_price', true) ?: 0;

        $featuredCourses[] = [
            'id' => $courseId,
            'title' => get_the_title(),
            'url' => get_permalink(),
            'type' => 'elearning',
            'thumbnail' => get_the_post_thumbnail_url($courseId, 'medium_large'),
            'duration' => get_post_meta($courseId, '_ld_course_duration', true) ?: '',
            'price' => (float) $price,
            'location' => '',
            'date_range' => '',
            'spots_left' => null,
        ];
    }
    wp_reset_postdata();
}
?>

<main class="str-main">
    <!-- Hero Section -->
    <section class="str-hero">
        <div class="str-container">
            <div class="str-hero__content">
                <h1 class="str-hero__title">
                    <?php esc_html_e('Ontwikkel jezelf met professionele trainingen', 'stridence'); ?>
                </h1>
                <p class="str-hero__subtitle">
                    <?php esc_html_e('Ontdek ons aanbod aan e-learning en klassikale cursussen voor jouw persoonlijke en professionele groei.', 'stridence'); ?>
                </p>
                <div class="str-hero__actions">
                    <a href="<?php echo esc_url(home_url('/cursussen/')); ?>" class="str-btn str-btn--primary str-btn--lg">
                        <?php esc_html_e('Bekijk cursussen', 'stridence'); ?>
                        <?php stridence_icon('arrow-right', '', 20); ?>
                    </a>
                    <a href="<?php echo esc_url(home_url('/trajecten/')); ?>" class="str-btn str-btn--secondary str-btn--lg">
                        <?php esc_html_e('Ontdek trajecten', 'stridence'); ?>
                    </a>
                </div>
            </div>
        </div>
    </section>

    <!-- Course Types Section -->
    <section class="str-section str-bg-light">
        <div class="str-container">
            <header class="str-section__header str-section__header--center">
                <h2 class="str-section__title"><?php esc_html_e('Kies je leerervaring', 'stridence'); ?></h2>
                <p class="str-section__subtitle">
                    <?php esc_html_e('Flexibel online leren of interactief in de klas', 'stridence'); ?>
                </p>
            </header>

            <div class="str-course-types">
                <div class="str-course-type">
                    <div class="str-course-type__icon str-course-type__icon--elearning">
                        <?php stridence_icon('laptop', '', 32); ?>
                    </div>
                    <h3 class="str-course-type__title"><?php esc_html_e('E-learning', 'stridence'); ?></h3>
                    <p class="str-course-type__desc">
                        <?php esc_html_e('Leer waar en wanneer je wilt met onze online cursussen.', 'stridence'); ?>
                    </p>
                    <ul class="str-course-type__features">
                        <li><?php stridence_icon('check', '', 16); ?> <?php esc_html_e('Flexibel tempo', 'stridence'); ?></li>
                        <li><?php stridence_icon('check', '', 16); ?> <?php esc_html_e('Direct toegang', 'stridence'); ?></li>
                        <li><?php stridence_icon('check', '', 16); ?> <?php esc_html_e('Certificaat', 'stridence'); ?></li>
                    </ul>
                    <a href="<?php echo esc_url(home_url('/cursussen/e-learning/')); ?>" class="str-btn str-btn--ghost">
                        <?php esc_html_e('Bekijk e-learning', 'stridence'); ?>
                        <?php stridence_icon('chevron-right', '', 18); ?>
                    </a>
                </div>

                <div class="str-course-type">
                    <div class="str-course-type__icon str-course-type__icon--classroom">
                        <?php stridence_icon('users', '', 32); ?>
                    </div>
                    <h3 class="str-course-type__title"><?php esc_html_e('Klassikaal', 'stridence'); ?></h3>
                    <p class="str-course-type__desc">
                        <?php esc_html_e('Interactieve training met een ervaren docent.', 'stridence'); ?>
                    </p>
                    <ul class="str-course-type__features">
                        <li><?php stridence_icon('check', '', 16); ?> <?php esc_html_e('Expert begeleiding', 'stridence'); ?></li>
                        <li><?php stridence_icon('check', '', 16); ?> <?php esc_html_e('Netwerken', 'stridence'); ?></li>
                        <li><?php stridence_icon('check', '', 16); ?> <?php esc_html_e('Praktijkgericht', 'stridence'); ?></li>
                    </ul>
                    <a href="<?php echo esc_url(home_url('/cursussen/klassikaal/')); ?>" class="str-btn str-btn--ghost">
                        <?php esc_html_e('Bekijk klassikaal', 'stridence'); ?>
                        <?php stridence_icon('chevron-right', '', 18); ?>
                    </a>
                </div>

                <div class="str-course-type">
                    <div class="str-course-type__icon str-course-type__icon--trajectory">
                        <?php stridence_icon('gift', '', 32); ?>
                    </div>
                    <h3 class="str-course-type__title"><?php esc_html_e('Trajecten', 'stridence'); ?></h3>
                    <p class="str-course-type__desc">
                        <?php esc_html_e('Complete leerpaden voor diepgaande expertise.', 'stridence'); ?>
                    </p>
                    <ul class="str-course-type__features">
                        <li><?php stridence_icon('check', '', 16); ?> <?php esc_html_e('Gestructureerd pad', 'stridence'); ?></li>
                        <li><?php stridence_icon('check', '', 16); ?> <?php esc_html_e('Voordelige bundel', 'stridence'); ?></li>
                        <li><?php stridence_icon('check', '', 16); ?> <?php esc_html_e('Extra certificaat', 'stridence'); ?></li>
                    </ul>
                    <a href="<?php echo esc_url(home_url('/trajecten/')); ?>" class="str-btn str-btn--ghost">
                        <?php esc_html_e('Bekijk trajecten', 'stridence'); ?>
                        <?php stridence_icon('chevron-right', '', 18); ?>
                    </a>
                </div>
            </div>
        </div>
    </section>

    <!-- Featured Courses Section -->
    <?php if (!empty($featuredCourses)): ?>
    <section class="str-section">
        <div class="str-container">
            <header class="str-section__header">
                <div>
                    <h2 class="str-section__title"><?php esc_html_e('Populaire cursussen', 'stridence'); ?></h2>
                    <p class="str-section__subtitle"><?php esc_html_e('Ontdek onze meest gevolgde trainingen', 'stridence'); ?></p>
                </div>
                <a href="<?php echo esc_url(home_url('/cursussen/')); ?>" class="str-btn str-btn--secondary">
                    <?php esc_html_e('Alle cursussen', 'stridence'); ?>
                </a>
            </header>

            <div class="str-grid str-grid--courses">
                <?php foreach ($featuredCourses as $course): ?>
                    <?php include get_stylesheet_directory() . '/templates/partials/course-card.php'; ?>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <!-- Why Choose Us Section -->
    <section class="str-section str-bg-light">
        <div class="str-container">
            <header class="str-section__header str-section__header--center">
                <h2 class="str-section__title"><?php esc_html_e('Waarom kiezen voor ons?', 'stridence'); ?></h2>
            </header>

            <div class="str-features">
                <div class="str-feature">
                    <div class="str-feature__icon">
                        <?php stridence_icon('award', '', 28); ?>
                    </div>
                    <h3 class="str-feature__title"><?php esc_html_e('Erkende certificaten', 'stridence'); ?></h3>
                    <p class="str-feature__desc">
                        <?php esc_html_e('Ontvang erkende certificaten die je carrière een boost geven.', 'stridence'); ?>
                    </p>
                </div>

                <div class="str-feature">
                    <div class="str-feature__icon">
                        <?php stridence_icon('users', '', 28); ?>
                    </div>
                    <h3 class="str-feature__title"><?php esc_html_e('Expert docenten', 'stridence'); ?></h3>
                    <p class="str-feature__desc">
                        <?php esc_html_e('Leer van professionals met jarenlange praktijkervaring.', 'stridence'); ?>
                    </p>
                </div>

                <div class="str-feature">
                    <div class="str-feature__icon">
                        <?php stridence_icon('clock', '', 28); ?>
                    </div>
                    <h3 class="str-feature__title"><?php esc_html_e('Flexibel leren', 'stridence'); ?></h3>
                    <p class="str-feature__desc">
                        <?php esc_html_e('Kies zelf je tempo en leermoment, online of in de klas.', 'stridence'); ?>
                    </p>
                </div>

                <div class="str-feature">
                    <div class="str-feature__icon">
                        <?php stridence_icon('check-circle', '', 28); ?>
                    </div>
                    <h3 class="str-feature__title"><?php esc_html_e('Praktijkgericht', 'stridence'); ?></h3>
                    <p class="str-feature__desc">
                        <?php esc_html_e('Direct toepasbare kennis en vaardigheden voor je werk.', 'stridence'); ?>
                    </p>
                </div>
            </div>
        </div>
    </section>

    <!-- CTA Section -->
    <section class="str-cta">
        <div class="str-container">
            <div class="str-cta__content">
                <h2 class="str-cta__title"><?php esc_html_e('Klaar om te groeien?', 'stridence'); ?></h2>
                <p class="str-cta__text">
                    <?php esc_html_e('Start vandaag nog met je ontwikkeling en ontdek ons volledige aanbod.', 'stridence'); ?>
                </p>
                <div class="str-cta__actions">
                    <a href="<?php echo esc_url(home_url('/cursussen/')); ?>" class="str-btn str-btn--primary str-btn--lg">
                        <?php esc_html_e('Bekijk alle cursussen', 'stridence'); ?>
                    </a>
                    <a href="<?php echo esc_url(home_url('/contact/')); ?>" class="str-btn str-btn--secondary str-btn--lg">
                        <?php esc_html_e('Neem contact op', 'stridence'); ?>
                    </a>
                </div>
            </div>
        </div>
    </section>
</main>

<?php get_footer(); ?>
