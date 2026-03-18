<?php
/**
 * Template Name: Online
 *
 * Catalog page for online/e-learning courses.
 * Client-side theme filtering and pagination via Alpine.js.
 *
 * @package stridence
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

// Get theme terms for tabs
$themes = get_terms([
    'taxonomy'   => 'stride_theme',
    'hide_empty' => false,
]);
if (is_wp_error($themes)) {
    $themes = [];
}

// Query all online courses
$course_args = [
    'post_type'      => 'sfwd-courses',
    'posts_per_page' => 200,
    'post_status'    => 'publish',
    'tax_query'      => [
        'relation' => 'AND',
        [
            'taxonomy' => 'stride_format',
            'field'    => 'slug',
            'terms'    => ['online', 'e-learning', 'webinar'],
            'operator' => 'IN',
        ],
        [
            'taxonomy' => 'stride_format',
            'field'    => 'slug',
            'terms'    => ['klassikaal', 'classroom'],
            'operator' => 'NOT IN',
        ],
    ],
    'orderby'        => 'title',
    'order'          => 'ASC',
];

$course_query = new WP_Query($course_args);
$all_courses = $course_query->posts;

// Build theme slugs per course and count per theme
$course_themes_map = [];
$theme_counts = [];
foreach ($all_courses as $course) {
    $course_themes = get_the_terms($course->ID, 'stride_theme');
    $slugs = [];
    if ($course_themes && !is_wp_error($course_themes)) {
        foreach ($course_themes as $theme) {
            $slugs[] = $theme->slug;
            $theme_counts[$theme->slug] = ($theme_counts[$theme->slug] ?? 0) + 1;
        }
    }
    $course_themes_map[$course->ID] = $slugs;
}

$total = count($all_courses);

get_header();
?>

<!-- Page Header -->
<div class="bg-surface-alt border-b border-border">
    <div class="container py-8 lg:py-12">
        <h1 class="text-3xl lg:text-4xl font-heading font-bold text-text mb-2">
            <?php esc_html_e('Online leren', 'stridence'); ?>
        </h1>
        <p class="text-lg text-text-muted">
            <?php esc_html_e('Leer op je eigen tempo met onze e-learning modules en webinars', 'stridence'); ?>
        </p>
    </div>
</div>

<div x-data="{ filter: '', page: 1, totalPages: 1, filteredCount: <?php echo $total; ?> }"
     x-effect="
         const cards = [...$refs.grid.children];
         const perPage = 12;
         const filtered = cards.filter(c => !filter || (c.dataset.themes || '').split(',').includes(filter));
         filteredCount = filtered.length;
         totalPages = Math.ceil(filtered.length / perPage) || 1;
         if (page > totalPages) page = 1;
         const start = (page - 1) * perPage;
         cards.forEach(c => c.style.display = 'none');
         filtered.slice(start, start + perPage).forEach(c => c.style.display = '');
     ">

    <!-- Theme Filter Tabs -->
    <?php if (!empty($themes)) : ?>
    <div class="border-b border-border bg-surface">
        <div class="container">
            <nav class="flex overflow-x-auto -mb-px scrollbar-hide" aria-label="<?php esc_attr_e("Thema's", 'stridence'); ?>">
                <button @click="filter = ''; page = 1" type="button"
                    :class="filter === ''
                        ? 'whitespace-nowrap px-4 py-3 text-sm font-medium border-b-2 border-primary text-primary'
                        : 'whitespace-nowrap px-4 py-3 text-sm font-medium border-b-2 border-transparent text-text-muted hover:text-text hover:border-border'"
                    :aria-current="filter === '' ? 'page' : false">
                    <?php esc_html_e('Alle', 'stridence'); ?>
                    <span class="ml-1 text-xs text-text-muted">(<?php echo esc_html($total); ?>)</span>
                </button>

                <?php foreach ($themes as $theme) :
                    $count = $theme_counts[$theme->slug] ?? 0;
                    if ($count === 0) continue;
                ?>
                    <button @click="filter = '<?php echo esc_attr($theme->slug); ?>'; page = 1" type="button"
                        :class="filter === '<?php echo esc_attr($theme->slug); ?>'
                            ? 'whitespace-nowrap px-4 py-3 text-sm font-medium border-b-2 border-primary text-primary'
                            : 'whitespace-nowrap px-4 py-3 text-sm font-medium border-b-2 border-transparent text-text-muted hover:text-text hover:border-border'"
                        :aria-current="filter === '<?php echo esc_attr($theme->slug); ?>' ? 'page' : false">
                        <?php echo esc_html($theme->name); ?>
                        <span class="ml-1 text-xs text-text-muted">(<?php echo esc_html($count); ?>)</span>
                    </button>
                <?php endforeach; ?>
            </nav>
        </div>
    </div>
    <?php endif; ?>

    <!-- Course Grid -->
    <div class="container py-8 lg:py-12">
        <?php if (!empty($all_courses)) : ?>
            <div class="grid gap-6 md:grid-cols-2 lg:grid-cols-3" x-ref="grid">
                <?php foreach ($all_courses as $course) :
                    $slugs = $course_themes_map[$course->ID] ?? [];
                ?>
                    <div data-themes="<?php echo esc_attr(implode(',', $slugs)); ?>">
                        <?php
                        stridence_template_part('partials/card-course', null, [
                            'course' => $course,
                        ]);
                        ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Empty state for filtered results -->
            <div x-show="filteredCount === 0" x-cloak class="text-center py-12">
                <?php
                stridence_template_part('partials/empty-state', null, [
                    'icon'    => 'monitor',
                    'title'   => __('Geen opleidingen gevonden', 'stridence'),
                    'message' => __('Er zijn geen online opleidingen in dit thema.', 'stridence'),
                ]);
                ?>
            </div>

            <!-- Pagination -->
            <nav x-show="totalPages > 1" x-cloak class="mt-12 flex justify-center" aria-label="<?php esc_attr_e('Paginatie', 'stridence'); ?>">
                <div class="flex items-center gap-1">
                    <button @click="page = Math.max(1, page - 1)" :disabled="page <= 1"
                        class="inline-flex items-center justify-center min-w-[40px] h-10 px-3 rounded-lg text-sm font-medium transition-colors hover:bg-surface-alt disabled:opacity-30 disabled:pointer-events-none">
                        <span class="sr-only"><?php esc_html_e('Vorige', 'stridence'); ?></span>
                        <?php echo stridence_icon('chevron-left', 'w-5 h-5'); ?>
                    </button>

                    <template x-for="p in totalPages" :key="p">
                        <button @click="page = p"
                            :class="p === page ? 'bg-primary text-white' : 'hover:bg-surface-alt'"
                            class="inline-flex items-center justify-center min-w-[40px] h-10 px-3 rounded-lg text-sm font-medium transition-colors"
                            x-text="p">
                        </button>
                    </template>

                    <button @click="page = Math.min(totalPages, page + 1)" :disabled="page >= totalPages"
                        class="inline-flex items-center justify-center min-w-[40px] h-10 px-3 rounded-lg text-sm font-medium transition-colors hover:bg-surface-alt disabled:opacity-30 disabled:pointer-events-none">
                        <span class="sr-only"><?php esc_html_e('Volgende', 'stridence'); ?></span>
                        <?php echo stridence_icon('chevron-right', 'w-5 h-5'); ?>
                    </button>
                </div>
            </nav>

        <?php else : ?>
            <?php
            stridence_template_part('partials/empty-state', null, [
                'icon'    => 'monitor',
                'title'   => __('Geen opleidingen gevonden', 'stridence'),
                'message' => __('Er zijn momenteel geen online opleidingen beschikbaar.', 'stridence'),
            ]);
            ?>
        <?php endif; ?>
    </div>
</div>

<?php get_footer(); ?>
