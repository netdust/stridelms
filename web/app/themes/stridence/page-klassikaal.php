<?php
/**
 * Template Name: Klassikaal
 *
 * Catalog page for classroom/in-person courses.
 * Client-side theme filtering and pagination via Alpine.js.
 *
 * @package stridence
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

use Stride\Modules\Edition\EditionRepository;

// Get theme terms for tabs
$themes = get_terms([
    'taxonomy'   => 'stride_theme',
    'hide_empty' => false,
]);
if (is_wp_error($themes)) {
    $themes = [];
}

// Query all open editions
$editionRepository = ntdst_get(EditionRepository::class);

// Hide editions whose end_date is more than 2 days in the past. The 2-day
// grace keeps just-finished cohorts findable for visitors who got the link
// emailed/shared the day after the last session. Their effective status
// already reads as "Afgelopen" via EditionService::getEffectiveStatus().
$past_cutoff = date('Y-m-d', strtotime('-2 days'));

$edition_args = [
    'post_type'      => 'vad_edition',
    'posts_per_page' => 200,
    'post_status'    => 'publish',
    'meta_query'     => [
        [
            'key'     => '_ntdst_status',
            'value'   => ['announcement', 'open', 'full', 'in_progress'],
            'compare' => 'IN',
        ],
        [
            'relation' => 'OR',
            [
                'key'     => '_ntdst_end_date',
                'value'   => $past_cutoff,
                'compare' => '>=',
                'type'    => 'DATE',
            ],
            [
                // Fallback when end_date is missing: use start_date
                'relation' => 'AND',
                [
                    'key'     => '_ntdst_end_date',
                    'compare' => 'NOT EXISTS',
                ],
                [
                    'key'     => '_ntdst_start_date',
                    'value'   => $past_cutoff,
                    'compare' => '>=',
                    'type'    => 'DATE',
                ],
            ],
        ],
    ],
    'orderby'        => 'meta_value',
    'meta_key'       => '_ntdst_start_date',
    'order'          => 'ASC',
];

$edition_query = new WP_Query($edition_args);
$all_editions = [];

foreach ($edition_query->posts as $edition_post) {
    $edition_obj = $editionRepository->find($edition_post->ID);
    if ($edition_obj && !is_wp_error($edition_obj)) {
        $edition_data = [
            'id'              => $edition_obj->ID,
            'title'           => $edition_obj->post_title,
            'course_id'       => $edition_obj->fields['course_id'] ?? null,
            'start_date'      => $edition_obj->fields['start_date'] ?? null,
            'end_date'        => $edition_obj->fields['end_date'] ?? null,
            'venue'           => $edition_obj->fields['venue'] ?? null,
            'price'           => $edition_obj->fields['price'] ?? null,
            'capacity'        => $edition_obj->fields['capacity'] ?? null,
            'status'          => $edition_obj->fields['status'] ?? 'open',
            'spots_remaining' => $edition_obj->fields['spots_remaining'] ?? null,
            'themes'          => [],
        ];

        if ($edition_data['course_id']) {
            // Skip editions for online/webinar/e-learning courses
            $formats = get_the_terms($edition_data['course_id'], 'stride_format');
            if ($formats && !is_wp_error($formats)) {
                $format_slugs = wp_list_pluck($formats, 'slug');
                $online_formats = ['online', 'webinar', 'e-learning'];
                $classroom_formats = ['klassikaal', 'classroom'];
                if (!empty(array_intersect($format_slugs, $online_formats)) && empty(array_intersect($format_slugs, $classroom_formats))) {
                    continue;
                }
            }

            $course_themes = get_the_terms($edition_data['course_id'], 'stride_theme');
            if ($course_themes && !is_wp_error($course_themes)) {
                $edition_data['themes'] = wp_list_pluck($course_themes, 'slug');
            }
        }

        $all_editions[] = $edition_data;
    }
}

// Count editions per theme for tab badges
$theme_counts = [];
foreach ($all_editions as $ed) {
    foreach ($ed['themes'] as $theme_slug) {
        $theme_counts[$theme_slug] = ($theme_counts[$theme_slug] ?? 0) + 1;
    }
}

$total = count($all_editions);

get_header();
?>

<!-- Page Header -->
<div class="bg-surface-alt border-b border-border">
    <div class="container py-8 lg:py-12">
        <h1 class="text-3xl lg:text-4xl font-heading font-bold text-text mb-2">
            <?php esc_html_e('Klassikale opleidingen', 'stridence'); ?>
        </h1>
        <p class="text-lg text-text-muted">
            <?php esc_html_e('Leer samen met anderen onder begeleiding van ervaren docenten', 'stridence'); ?>
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
                    if ($count === 0) {
                        continue;
                    }
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

    <!-- Edition Grid -->
    <div class="container py-8 lg:py-12">
        <?php if (!empty($all_editions)) : ?>
            <div class="grid gap-6 md:grid-cols-2 lg:grid-cols-3" x-ref="grid">
                <?php foreach ($all_editions as $edition) : ?>
                    <div data-themes="<?php echo esc_attr(implode(',', $edition['themes'])); ?>">
                        <?php
                            stridence_template_part('partials/card-edition', null, [
                                'edition' => $edition,
                            ]);
                    ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Empty state for filtered results -->
            <div x-show="filteredCount === 0" x-cloak class="text-center py-12">
                <?php
                stridence_template_part('partials/empty-state', null, [
                    'icon'    => 'calendar',
                    'title'   => __('Geen opleidingen gevonden', 'stridence'),
                    'message' => __('Er zijn geen klassikale opleidingen in dit thema.', 'stridence'),
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
                            :class="p === page ? 'bg-primary text-text-inverse' : 'hover:bg-surface-alt'"
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
            'icon'    => 'calendar',
            'title'   => __('Geen opleidingen gevonden', 'stridence'),
            'message' => __('Er zijn momenteel geen klassikale opleidingen gepland.', 'stridence'),
            ]);
            ?>
        <?php endif; ?>
    </div>
</div>

<?php get_footer(); ?>
