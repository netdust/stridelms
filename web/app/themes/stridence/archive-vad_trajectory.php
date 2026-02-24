<?php
/**
 * Trajectory Catalog Archive
 *
 * Template for displaying trajectories in a filterable grid.
 * Supports status filter (open, ongoing, completed).
 *
 * @package stridence
 */

defined('ABSPATH') || exit;

// Get filter value from URL
$current_status = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';

// Build query args
$paged = get_query_var('paged') ? get_query_var('paged') : 1;

$query_args = [
    'post_type'      => 'vad_trajectory',
    'posts_per_page' => 12,
    'paged'          => $paged,
    'orderby'        => 'title',
    'order'          => 'ASC',
];

// Apply status filter via meta query
if (!empty($current_status)) {
    $query_args['meta_query'] = [
        [
            'key'   => '_trajectory_status',
            'value' => $current_status,
        ],
    ];
}

$trajectories_query = new WP_Query($query_args);

// Check if filter is active
$has_filter = !empty($current_status);

// Build base URL for filter links
$base_url = get_post_type_archive_link('vad_trajectory');

// Status options
$status_options = [
    ''          => __('Alle statussen', 'stridence'),
    'open'      => __('Open voor inschrijving', 'stridence'),
    'ongoing'   => __('Lopend', 'stridence'),
    'completed' => __('Afgerond', 'stridence'),
];

get_header();
?>

<!-- Page Header -->
<div class="bg-surface-alt border-b border-border">
    <div class="container py-8 lg:py-12">
        <h1 class="text-3xl lg:text-4xl font-heading font-bold text-text mb-2">
            <?php esc_html_e('Trajecten', 'stridence'); ?>
        </h1>
        <p class="text-lg text-text-muted">
            <?php esc_html_e('Samengestelde leertrajecten voor diepgaande specialisatie', 'stridence'); ?>
        </p>
    </div>
</div>

<!-- Filters -->
<div class="container py-6">
    <form method="get" action="<?php echo esc_url($base_url); ?>" class="flex flex-wrap gap-4 items-center">
        <!-- Status Filter -->
        <select name="status" class="input-select" onchange="this.form.submit()">
            <?php foreach ($status_options as $value => $label) : ?>
                <option value="<?php echo esc_attr($value); ?>" <?php selected($current_status, $value); ?>>
                    <?php echo esc_html($label); ?>
                </option>
            <?php endforeach; ?>
        </select>

        <?php if ($has_filter) : ?>
            <a href="<?php echo esc_url($base_url); ?>" class="text-sm text-text-muted hover:text-text inline-flex items-center gap-1">
                <?php echo stridence_icon('x', 'w-4 h-4'); ?>
                <?php esc_html_e('Filter wissen', 'stridence'); ?>
            </a>
        <?php endif; ?>
    </form>
</div>

<!-- Trajectory Grid -->
<div class="container pb-12">
    <?php if ($trajectories_query->have_posts()) : ?>
        <div class="grid gap-6 md:grid-cols-2 lg:grid-cols-3">
            <?php while ($trajectories_query->have_posts()) : $trajectories_query->the_post(); ?>
                <?php
                get_template_part('partials/card-trajectory', null, [
                    'trajectory' => get_post(),
                ]);
                ?>
            <?php endwhile; ?>
        </div>

        <!-- Pagination -->
        <?php if ($trajectories_query->max_num_pages > 1) : ?>
            <nav class="mt-12 flex justify-center" aria-label="<?php esc_attr_e('Paginatie', 'stridence'); ?>">
                <?php
                $pagination_args = [
                    'total'     => $trajectories_query->max_num_pages,
                    'current'   => $paged,
                    'mid_size'  => 2,
                    'prev_text' => '<span class="sr-only">' . __('Vorige', 'stridence') . '</span>' . stridence_icon('chevron-left', 'w-5 h-5'),
                    'next_text' => '<span class="sr-only">' . __('Volgende', 'stridence') . '</span>' . stridence_icon('chevron-right', 'w-5 h-5'),
                    'type'      => 'array',
                ];

                // Preserve status param in pagination
                if (!empty($current_status)) {
                    $pagination_args['add_args']['status'] = $current_status;
                }

                $pagination_links = paginate_links($pagination_args);

                if ($pagination_links) :
                ?>
                    <ul class="flex items-center gap-1">
                        <?php foreach ($pagination_links as $link) : ?>
                            <li>
                                <?php
                                // Add styling classes to pagination links
                                $link = str_replace(
                                    'page-numbers',
                                    'page-numbers inline-flex items-center justify-center min-w-[40px] h-10 px-3 rounded-lg text-sm font-medium transition-colors',
                                    $link
                                );
                                $link = str_replace(
                                    'current',
                                    'current bg-primary text-white',
                                    $link
                                );
                                // Non-current links
                                if (strpos($link, 'current') === false && strpos($link, 'dots') === false) {
                                    $link = str_replace(
                                        'page-numbers',
                                        'page-numbers hover:bg-surface-alt',
                                        $link
                                    );
                                }
                                echo $link;
                                ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </nav>
        <?php endif; ?>

    <?php else : ?>
        <!-- Empty State -->
        <?php
        get_template_part('partials/empty-state', null, [
            'icon'    => 'book-open',
            'title'   => __('Geen trajecten gevonden', 'stridence'),
            'message' => __('Er zijn geen trajecten die voldoen aan je zoekcriteria. Probeer een andere filter of bekijk alle trajecten.', 'stridence'),
            'action'  => __('Bekijk alle trajecten', 'stridence'),
            'url'     => $base_url,
        ]);
        ?>
    <?php endif; ?>
</div>

<?php
wp_reset_postdata();
get_footer();
