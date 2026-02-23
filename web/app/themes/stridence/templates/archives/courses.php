<?php
/**
 * Course Archive Template
 *
 * @package stridence
 */

defined('ABSPATH') || exit;

get_header();

// Get course type filter from URL
$course_type = 'all';
$request_uri = $_SERVER['REQUEST_URI'] ?? '';
if (strpos($request_uri, '/e-learning') !== false) {
    $course_type = 'elearning';
} elseif (strpos($request_uri, '/klassikaal') !== false) {
    $course_type = 'classroom';
}

// Get courses
$courses = [];

// Get LearnDash courses
$args = [
    'post_type' => 'sfwd-courses',
    'posts_per_page' => 12,
    'post_status' => 'publish',
    'paged' => get_query_var('paged') ?: 1,
];

$query = new WP_Query($args);

// Get edition service for classroom courses
$editionService = null;
if (class_exists(\Stride\Modules\Edition\EditionService::class)) {
    try {
        $editionService = ntdst_get(\Stride\Modules\Edition\EditionService::class);
    } catch (\Exception $e) {
        // Service not available
    }
}

if ($query->have_posts()) {
    while ($query->have_posts()) {
        $query->the_post();
        $courseId = get_the_ID();

        // Determine if classroom or e-learning
        $isClassroom = false;
        $editions = [];
        $nextEdition = null;

        if ($editionService) {
            $editions = $editionService->getEditionsForCourse($courseId);
            $isClassroom = !empty($editions);

            // Get next upcoming edition
            if ($isClassroom) {
                foreach ($editions as $edition) {
                    $startDate = $edition['start_date'] ?? '';
                    if ($startDate && $startDate >= wp_date('Y-m-d')) {
                        $nextEdition = $edition;
                        break;
                    }
                }
            }
        }

        // Apply type filter
        if ($course_type === 'elearning' && $isClassroom) continue;
        if ($course_type === 'classroom' && !$isClassroom) continue;

        // Get course duration from LearnDash
        $duration = get_post_meta($courseId, '_ld_course_duration', true) ?: '';

        // Get price (from edition if classroom, or course meta)
        $price = 0;
        if ($nextEdition) {
            $price = $nextEdition['price'] ?? 0;
        } else {
            $price = get_post_meta($courseId, '_ld_course_price', true) ?: 0;
        }

        $courses[] = [
            'id' => $courseId,
            'title' => get_the_title(),
            'url' => $nextEdition ? get_permalink($nextEdition['id']) : get_permalink(),
            'type' => $isClassroom ? 'classroom' : 'elearning',
            'thumbnail' => get_the_post_thumbnail_url($courseId, 'medium_large'),
            'duration' => $duration,
            'price' => (float) $price,
            'location' => $nextEdition['location'] ?? '',
            'date_range' => $nextEdition ? date_i18n('j M', strtotime($nextEdition['start_date'])) : '',
            'spots_left' => $nextEdition['spots_left'] ?? null,
        ];
    }
    wp_reset_postdata();
}

// Page titles
$titles = [
    'all' => __('Alle cursussen', 'stridence'),
    'elearning' => __('E-learning cursussen', 'stridence'),
    'classroom' => __('Klassikale cursussen', 'stridence'),
];
?>

<main class="str-main">
    <div class="str-container">
        <section class="str-section">
            <header class="str-section__header">
                <h1 class="str-section__title"><?php echo esc_html($titles[$course_type]); ?></h1>
                <p class="str-section__subtitle">
                    <?php esc_html_e('Ontdek ons aanbod aan professionele trainingen', 'stridence'); ?>
                </p>
            </header>

            <?php
            $current_type = $course_type;
            $categories = get_terms(['taxonomy' => 'ld_course_category', 'hide_empty' => true]);
            if (is_wp_error($categories)) {
                $categories = [];
            }
            include get_stylesheet_directory() . '/templates/partials/archive-filters.php';
            ?>

            <?php if (!empty($courses)): ?>
                <div class="str-grid str-grid--courses">
                    <?php foreach ($courses as $course): ?>
                        <?php include get_stylesheet_directory() . '/templates/partials/course-card.php'; ?>
                    <?php endforeach; ?>
                </div>

                <?php if ($query->max_num_pages > 1): ?>
                    <nav class="str-pagination">
                        <?php
                        echo paginate_links([
                            'total' => $query->max_num_pages,
                            'current' => get_query_var('paged') ?: 1,
                            'prev_text' => stridence_get_icon('arrow-left', '', 16) . __('Vorige', 'stridence'),
                            'next_text' => __('Volgende', 'stridence') . stridence_get_icon('arrow-right', '', 16),
                        ]);
                        ?>
                    </nav>
                <?php endif; ?>
            <?php else: ?>
                <div class="str-empty-state">
                    <?php stridence_icon('book', '', 48); ?>
                    <h2><?php esc_html_e('Geen cursussen gevonden', 'stridence'); ?></h2>
                    <p><?php esc_html_e('Er zijn momenteel geen cursussen beschikbaar in deze categorie.', 'stridence'); ?></p>
                </div>
            <?php endif; ?>
        </section>
    </div>
</main>

<?php get_footer(); ?>
