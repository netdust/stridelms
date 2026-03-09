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

use Stride\Modules\Edition\EditionService;

$editionService = ntdst_get(EditionService::class);

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

$edition_meta_query = [
    [
        'key'     => '_ntdst_status',
        'value'   => ['draft', 'completed', 'archived'],
        'compare' => 'NOT IN',
    ],
];
// Exclude editions linked to online courses
if (!empty($online_course_ids)) {
    $edition_meta_query[] = [
        'relation' => 'OR',
        [
            'key'     => '_ntdst_course_id',
            'value'   => $online_course_ids,
            'compare' => 'NOT IN',
        ],
        [
            'key'     => '_ntdst_course_id',
            'compare' => 'NOT EXISTS',
        ],
    ];
}

$edition_query = new WP_Query([
    'post_type'      => 'vad_edition',
    'posts_per_page' => 6,
    'post_status'    => 'publish',
    'meta_query'     => $edition_meta_query,
    'orderby'        => 'meta_value',
    'meta_key'       => '_ntdst_start_date',
    'order'          => 'ASC',
]);

$classroom_editions = [];
foreach ($edition_query->posts as $edition_post) {
    $edition_obj = $editionService->getEdition($edition_post->ID);
    if (!$edition_obj || is_wp_error($edition_obj)) {
        continue;
    }
    $classroom_editions[] = [
        'id'              => $edition_obj->ID,
        'title'           => $edition_obj->post_title,
        'course_id'       => $edition_obj->fields['course_id'] ?? null,
        'start_date'      => $edition_obj->fields['start_date'] ?? null,
        'venue'           => $edition_obj->fields['venue'] ?? null,
        'price'           => $edition_obj->fields['price'] ?? null,
        'capacity'        => $edition_obj->fields['capacity'] ?? null,
        'status'          => $edition_obj->fields['status'] ?? 'open',
        'spots_remaining' => $edition_obj->fields['spots_remaining'] ?? null,
    ];
}

// --- Trajectories ---
$trajectory_model = ntdst_data()->get('vad_trajectory');
$trajectories = $trajectory_model->where('post_status', 'publish')
                                 ->orderBy('menu_order', 'ASC')
                                 ->orderBy('post_title', 'ASC')
                                 ->withMeta()
                                 ->limit(3)
                                 ->get();

// --- Online courses ---
$online_query = new WP_Query([
    'post_type'      => 'sfwd-courses',
    'posts_per_page' => 6,
    'post_status'    => 'publish',
    'tax_query'      => [
        [
            'taxonomy' => 'stride_format',
            'field'    => 'slug',
            'terms'    => ['online', 'e-learning', 'webinar'],
        ],
    ],
    'orderby' => 'title',
    'order'   => 'ASC',
]);
$online_courses = $online_query->posts;

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
                get_template_part('partials/card-trajectory', null, [
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

        <?php if (!empty($classroom_editions)) : ?>
            <div class="grid gap-6 md:grid-cols-2 lg:grid-cols-3">
                <?php foreach ($classroom_editions as $edition) : ?>
                    <?php
                    get_template_part('partials/card-edition', null, [
                        'edition' => $edition,
                    ]);
                    ?>
                <?php endforeach; ?>
            </div>
        <?php else : ?>
            <?php
            get_template_part('partials/empty-state', null, [
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

        <?php if (!empty($online_courses)) : ?>
            <div class="grid gap-6 md:grid-cols-2 lg:grid-cols-3">
                <?php foreach ($online_courses as $course) : ?>
                    <?php
                    get_template_part('partials/card-course', null, [
                        'course' => $course,
                    ]);
                    ?>
                <?php endforeach; ?>
            </div>
        <?php else : ?>
            <?php
            get_template_part('partials/empty-state', null, [
                'icon'    => 'monitor',
                'title'   => __('Geen online cursussen', 'stridence'),
                'message' => __('Er zijn momenteel geen online cursussen beschikbaar.', 'stridence'),
            ]);
            ?>
        <?php endif; ?>
    </section>

</div>

<?php get_footer(); ?>
