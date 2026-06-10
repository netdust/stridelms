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

use Stride\Modules\Edition\EditionRepository;

// Get theme terms for tabs
$themes = get_terms([
    'taxonomy'   => 'stride_theme',
    'hide_empty' => false,
]);
if (is_wp_error($themes)) {
    $themes = [];
}

// One card per enrollable. For online courses an enrollable is either:
//   (a) an active edition of an online-format course, OR
//   (b) a pure-LD online-format course with zero active editions.
// Two active editions of the same course → two cards. Matches the rule
// described in [[lesson_url_role_split]]: /edities/* is transactional,
// so the catalog list = the set of things a visitor can enroll in.

$online_format_slugs = ['online', 'e-learning', 'webinar'];

// All online-format course IDs (used to scope the edition query AND to find
// pure-LD courses for the second pass).
$online_course_ids = get_posts([
    'post_type'      => 'sfwd-courses',
    'posts_per_page' => 500,
    'post_status'    => 'publish',
    'fields'         => 'ids',
    'tax_query'      => [
        [
            'taxonomy' => 'stride_format',
            'field'    => 'slug',
            'terms'    => $online_format_slugs,
        ],
    ],
]);

$enrollables = []; // mixed list of ['kind' => 'edition'|'course', ..., 'themes' => [slugs]]

// --- (a) Active editions of online courses ---
// Scheduled online editions are rare (most online courses are pure-LD), but when
// they exist their dates matter. Apply the same 2-day past-cutoff used by
// klassikaal so just-finished cohorts stay findable for a day, then drop off.
if (!empty($online_course_ids)) {
    $editionRepository = ntdst_get(EditionRepository::class);
    $past_cutoff = date('Y-m-d', strtotime('-2 days'));

    $edition_query = new WP_Query([
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
                'key'     => '_ntdst_course_id',
                'value'   => $online_course_ids,
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
                [
                    // Self-paced online edition (no dates at all) — always show
                    'relation' => 'AND',
                    [
                        'key'     => '_ntdst_end_date',
                        'compare' => 'NOT EXISTS',
                    ],
                    [
                        'key'     => '_ntdst_start_date',
                        'compare' => 'NOT EXISTS',
                    ],
                ],
            ],
        ],
        'orderby'        => 'meta_value',
        'meta_key'       => '_ntdst_start_date',
        'order'          => 'ASC',
    ]);

    foreach ($edition_query->posts as $edition_post) {
        $edition_obj = $editionRepository->find($edition_post->ID);
        if (!$edition_obj || is_wp_error($edition_obj)) {
            continue;
        }
        $course_id = (int) ($edition_obj->fields['course_id'] ?? 0);

        $theme_slugs = [];
        if ($course_id) {
            $course_themes = get_the_terms($course_id, 'stride_theme');
            if ($course_themes && !is_wp_error($course_themes)) {
                $theme_slugs = wp_list_pluck($course_themes, 'slug');
            }
        }

        $enrollables[] = [
            'kind'    => 'edition',
            'edition' => [
                'id'              => $edition_obj->ID,
                'title'           => $edition_obj->post_title,
                'course_id'       => $course_id,
                'start_date'      => $edition_obj->fields['start_date'] ?? null,
                'end_date'        => $edition_obj->fields['end_date'] ?? null,
                'venue'           => $edition_obj->fields['venue'] ?? null,
                'price'           => $edition_obj->fields['price'] ?? null,
                'capacity'        => $edition_obj->fields['capacity'] ?? null,
                'status'          => $edition_obj->fields['status'] ?? 'open',
                'spots_remaining' => $edition_obj->fields['spots_remaining'] ?? null,
            ],
            'themes'  => $theme_slugs,
        ];
    }
}

// --- (b) Pure-LD online courses ---
// A "pure-LD" course is one that has NEVER had an edition (or has all editions
// deleted). A course whose editions are all expired is NOT pure-LD — it goes
// off-catalog until the editor schedules a new edition. So scope by "course
// has any edition at all", not by "course has a currently-visible edition."
$any_edition_course_ids = [];
if (!empty($online_course_ids)) {
    global $wpdb;
    $in_placeholders = implode(',', array_fill(0, count($online_course_ids), '%d'));
    $any_edition_course_ids = $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT pm.meta_value + 0
         FROM {$wpdb->postmeta} pm
         INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
         WHERE pm.meta_key = '_ntdst_course_id'
           AND p.post_type = 'vad_edition'
           AND pm.meta_value IN ($in_placeholders)",
        ...$online_course_ids,
    ));
    $any_edition_course_ids = array_map('intval', $any_edition_course_ids);
}
$pure_ld_course_ids = array_diff($online_course_ids, $any_edition_course_ids);
foreach ($pure_ld_course_ids as $course_id) {
    $course = get_post($course_id);
    if (!$course) {
        continue;
    }
    $theme_slugs = [];
    $course_themes = get_the_terms($course_id, 'stride_theme');
    if ($course_themes && !is_wp_error($course_themes)) {
        $theme_slugs = wp_list_pluck($course_themes, 'slug');
    }
    $enrollables[] = [
        'kind'   => 'course',
        'course' => $course,
        'themes' => $theme_slugs,
    ];
}

// Theme counts for filter tabs
$theme_counts = [];
foreach ($enrollables as $item) {
    foreach ($item['themes'] as $slug) {
        $theme_counts[$slug] = ($theme_counts[$slug] ?? 0) + 1;
    }
}

$total = count($enrollables);

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

    <!-- Enrollable Grid (one card per active edition + one per pure-LD course) -->
    <div class="container py-8 lg:py-12">
        <?php if (!empty($enrollables)) : ?>
            <div class="grid gap-6 md:grid-cols-2 lg:grid-cols-3" x-ref="grid">
                <?php foreach ($enrollables as $item) : ?>
                    <div data-themes="<?php echo esc_attr(implode(',', $item['themes'])); ?>">
                        <?php if ($item['kind'] === 'edition') : ?>
                            <?php stridence_template_part('partials/card-edition', null, [
                                    'edition' => $item['edition'],
                                ]); ?>
                        <?php else : ?>
                            <?php stridence_template_part('partials/card-course', null, [
                                    'course' => $item['course'],
                                ]); ?>
                        <?php endif; ?>
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
'icon'    => 'monitor',
'title'   => __('Geen opleidingen gevonden', 'stridence'),
'message' => __('Er zijn momenteel geen online opleidingen beschikbaar.', 'stridence'),
            ]);
            ?>
        <?php endif; ?>
    </div>
</div>

<?php get_footer(); ?>
