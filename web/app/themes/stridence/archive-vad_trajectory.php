<?php
/**
 * Trajectory Catalog Archive
 *
 * Template for displaying trajectories in a filterable grid.
 * Supports status filter (open, ongoing, completed).
 *
 * @package stridence
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

// Get filter value from URL
$current_status = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';

// Pagination
$paged    = get_query_var('paged') ? (int) get_query_var('paged') : 1;
$per_page = 12;

// Build query via Data Manager
$model = ntdst_data()->get('vad_trajectory');
$query = $model->where('post_status', 'publish')
               ->orderBy('post_title', 'ASC');

// Apply status filter if set
if (!empty($current_status)) {
    $query = $query->where('status', $current_status);
}

// Get total count for pagination
$total_count = $query->count();
$max_pages   = (int) ceil($total_count / $per_page);

// Get paginated results
$trajectories = $query->limit($per_page)
                      ->offset(($paged - 1) * $per_page)
                      ->withMeta()
                      ->get();

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
    <?php if (!empty($trajectories)) : ?>
        <div class="grid gap-6 md:grid-cols-2 lg:grid-cols-3">
            <?php foreach ($trajectories as $trajectory_data) : ?>
                <?php
                // Pass trajectory data to card partial
                get_template_part('partials/card-trajectory', null, [
                    'trajectory' => $trajectory_data,
                ]);
                ?>
            <?php endforeach; ?>
        </div>

        <!-- Pagination -->
        <?php if ($max_pages > 1) : ?>
            <nav class="mt-12 flex justify-center" aria-label="<?php esc_attr_e('Paginatie', 'stridence'); ?>">
                <?php
                $pagination_args = [
                    'total'     => $max_pages,
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

<?php get_footer();
