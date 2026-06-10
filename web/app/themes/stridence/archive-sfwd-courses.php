<?php
/**
 * Course Archive Template
 *
 * SEO landing page showing all courses: classroom editions and online courses.
 * Links visitors to the dedicated /klassikaal/ and /online/ catalog pages.
 *
 * @package stridence
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

use Stride\Domain\OfferingStatus;
use Stride\Modules\Edition\EditionRepository;

$editionRepository = ntdst_get(EditionRepository::class);
$meta_prefix = $editionRepository->getMetaPrefix();
$archive_user_id = get_current_user_id() ?: null;

// --- Classroom editions ---
// Get online course IDs to exclude their editions
$online_course_ids = get_posts([
    'post_type'      => 'sfwd-courses',
    'posts_per_page' => 500,
    'post_status'    => 'publish',
    'fields'         => 'ids',
    'tax_query'      => [
        [
            'taxonomy' => 'stride_format',
            'field'    => 'slug',
            'terms'    => ['online', 'e-learning', 'webinar'],
        ],
    ],
]);

// NOTE (pre-existing divergence, left as-is): unlike the online query below
// and the /klassikaal page, this classroom teaser has NO date window — it
// filters on active status only (stridence_catalog_date_window_meta_query
// would add the end_date/start_date grace window). Convergence is a product
// ruling, not a refactor — tracked with the dateless-catalog follow-up.
$edition_meta_query = [
    [
        'key'     => $meta_prefix . 'status',
        'value'   => OfferingStatus::activeValues(),
        'compare' => 'IN',
    ],
];
// Exclude editions linked to online courses
if (!empty($online_course_ids)) {
    $edition_meta_query[] = [
        'relation' => 'OR',
        [
            'key'     => $meta_prefix . 'course_id',
            'value'   => $online_course_ids,
            'compare' => 'NOT IN',
        ],
        [
            'key'     => $meta_prefix . 'course_id',
            'compare' => 'NOT EXISTS',
        ],
    ];
}

$edition_query = new WP_Query([
    'post_type'      => 'vad_edition',
    'posts_per_page' => 6,
    'post_status'    => 'publish',
    'fields'         => 'ids',
    'no_found_rows'  => true,
    'meta_query'     => $edition_meta_query,
    'orderby'        => 'meta_value',
    'meta_key'       => $meta_prefix . 'start_date',
    'order'          => 'ASC',
]);

// Batch-hydrated card items (Task G1 / audit 2.2) — rendered through the
// catalog pre-pass; the card partials are pure renderers.
$classroom_items = stridence_catalog_edition_items_from_ids(array_map('intval', $edition_query->posts));

// --- Trajectories ---
$trajectory_model = ntdst_data()->get('vad_trajectory');
$trajectories = $trajectory_model->where('post_status', 'publish')
                                 ->orderBy('menu_order', 'ASC')
                                 ->orderBy('post_title', 'ASC')
                                 ->withMeta()
                                 ->limit(3)
                                 ->get();

// --- Online enrollables (active editions + pure-LD courses) ---
// Same rule as page-online.php: catalog list = the set of things a visitor
// can enroll in. See [[lesson_url_role_split]].
$online_items = [];

if (!empty($online_course_ids)) {
    // Eligibility rule shared with /klassikaal and /online — single builder
    // (dateless/self-paced exclusion documented there).
    $online_meta_query = stridence_catalog_date_window_meta_query($meta_prefix);
    $online_meta_query[] = [
        'key'     => $meta_prefix . 'course_id',
        'value'   => $online_course_ids,
        'compare' => 'IN',
    ];
    $online_edition_query = new WP_Query([
        'post_type'      => 'vad_edition',
        'posts_per_page' => 6,
        'post_status'    => 'publish',
        'fields'         => 'ids',
        'no_found_rows'  => true,
        'meta_query'     => $online_meta_query,
        'orderby'        => 'meta_value',
        'meta_key'       => $meta_prefix . 'start_date',
        'order'          => 'ASC',
    ]);
    $online_items = stridence_catalog_edition_items_from_ids(array_map('intval', $online_edition_query->posts));
}

// Top up with pure-LD courses (courses with NO edition at all — not "no
// currently-visible edition"). A course with only past editions is off-catalog
// until a new one is scheduled.
$remaining = 6 - count($online_items);
if ($remaining > 0 && !empty($online_course_ids)) {
    $with_editions = $editionRepository->courseIdsWithAnyEdition(array_map('intval', $online_course_ids));
    $pure_ld_ids = array_values(array_diff(array_map('intval', $online_course_ids), $with_editions));
    foreach (array_slice($pure_ld_ids, 0, $remaining) as $course_id) {
        if (!get_post($course_id)) {
            continue;
        }
        $online_items[] = [
            'kind'      => 'course',
            'course_id' => $course_id,
            'themes'    => [],
        ];
    }
}

get_header();
?>

<!-- Page Header -->
<div class="bg-surface-alt border-b border-border">
    <div class="container py-8 lg:py-12">
        <h1 class="text-3xl lg:text-4xl font-heading font-bold text-text mb-2">
            <?php esc_html_e('Ons opleidingsaanbod', 'stridence'); ?>
        </h1>
        <p class="text-lg text-text-muted max-w-2xl">
            <?php esc_html_e('Ontdek ons volledige aanbod aan klassikale opleidingen, leertrajecten en online cursussen in de verslavingszorg.', 'stridence'); ?>
        </p>
    </div>
</div>

<div class="container py-8 lg:py-12 space-y-12">

    <!-- Leertrajecten -->
    <?php if (!empty($trajectories)) : ?>
    <section>
        <div class="flex items-center justify-between mb-6">
            <h2 class="text-2xl font-heading font-bold text-text">
                <?php esc_html_e('Leertrajecten', 'stridence'); ?>
            </h2>
            <a href="<?php echo esc_url(get_post_type_archive_link('vad_trajectory')); ?>" class="text-sm font-medium text-primary hover:underline">
                <?php esc_html_e('Bekijk alle', 'stridence'); ?> &rarr;
            </a>
        </div>
        <p class="text-text-muted mb-6">
            <?php esc_html_e('Langlopende opleidingsprogramma\'s voor professionals die zich willen specialiseren.', 'stridence'); ?>
        </p>

        <div class="grid gap-6 md:grid-cols-2 lg:grid-cols-3">
            <?php foreach ($trajectories as $trajectory) : ?>
                <?php
                stridence_template_part('partials/card-trajectory', null, [
                    'trajectory' => $trajectory,
                ]);
                ?>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

    <!-- Klassikale opleidingen -->
    <section>
        <div class="flex items-center justify-between mb-6">
            <h2 class="text-2xl font-heading font-bold text-text">
                <?php esc_html_e('Klassikale opleidingen', 'stridence'); ?>
            </h2>
            <a href="<?php echo esc_url(home_url('/klassikaal/')); ?>" class="text-sm font-medium text-primary hover:underline">
                <?php esc_html_e('Bekijk alle', 'stridence'); ?> &rarr;
            </a>
        </div>
        <p class="text-text-muted mb-6">
            <?php esc_html_e('Leer samen met anderen onder begeleiding van ervaren docenten.', 'stridence'); ?>
        </p>

        <?php if (!empty($classroom_items)) : ?>
            <div class="grid gap-6 md:grid-cols-2 lg:grid-cols-3">
                <?php echo stridence_catalog_render_cards($classroom_items, $archive_user_id); // Escaped within the partials.?>
            </div>
        <?php else : ?>
            <?php
            stridence_template_part('partials/empty-state', null, [
                'icon'    => 'calendar',
                'title'   => __('Geen geplande opleidingen', 'stridence'),
                'message' => __('Er zijn momenteel geen klassikale opleidingen gepland.', 'stridence'),
            ]);
            ?>
        <?php endif; ?>
    </section>

    <!-- Online cursussen -->
    <section>
        <div class="flex items-center justify-between mb-6">
            <h2 class="text-2xl font-heading font-bold text-text">
                <?php esc_html_e('Online cursussen', 'stridence'); ?>
            </h2>
            <a href="<?php echo esc_url(home_url('/online/')); ?>" class="text-sm font-medium text-primary hover:underline">
                <?php esc_html_e('Bekijk alle', 'stridence'); ?> &rarr;
            </a>
        </div>
        <p class="text-text-muted mb-6">
            <?php esc_html_e('Leer op je eigen tempo met onze e-learning modules en webinars.', 'stridence'); ?>
        </p>

        <?php if (!empty($online_items)) : ?>
            <div class="grid gap-6 md:grid-cols-2 lg:grid-cols-3">
                <?php echo stridence_catalog_render_cards($online_items, $archive_user_id); // Escaped within the partials.?>
            </div>
        <?php else : ?>
            <?php
            stridence_template_part('partials/empty-state', null, [
                'icon'    => 'monitor',
                'title'   => __('Geen online cursussen', 'stridence'),
                'message' => __('Er zijn momenteel geen online cursussen beschikbaar.', 'stridence'),
            ]);
            ?>
        <?php endif; ?>
    </section>

</div>

<?php get_footer(); ?>
