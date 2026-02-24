<?php
/**
 * Course Catalog Archive
 *
 * Template for displaying LearnDash courses in a filterable grid.
 * Supports domain, format, and location filters.
 *
 * @package stridence
 */

defined('ABSPATH') || exit;

// Get filter values from URL
$current_domain  = isset($_GET['domein']) ? sanitize_text_field($_GET['domein']) : '';
$current_format  = isset($_GET['formaat']) ? sanitize_text_field($_GET['formaat']) : '';
$current_locatie = isset($_GET['locatie']) ? sanitize_text_field($_GET['locatie']) : '';

// Get domain terms
$domains = get_terms([
    'taxonomy'   => 'stride_domain',
    'hide_empty' => true,
]);

if (is_wp_error($domains)) {
    $domains = [];
}

// Build query args
$paged = get_query_var('paged') ? get_query_var('paged') : 1;

$query_args = [
    'post_type'      => 'sfwd-courses',
    'posts_per_page' => 12,
    'paged'          => $paged,
    'orderby'        => 'title',
    'order'          => 'ASC',
];

// Apply domain filter via taxonomy query
if (!empty($current_domain)) {
    $query_args['tax_query'] = [
        [
            'taxonomy' => 'stride_domain',
            'field'    => 'slug',
            'terms'    => $current_domain,
        ],
    ];
}

// Note: Format and location filters would typically query edition meta
// For now, these are placeholders that could be extended with meta queries
// when EditionService integration is complete

$courses_query = new WP_Query($query_args);

// Check if any filter is active
$has_filters = !empty($current_domain) || !empty($current_format) || !empty($current_locatie);

// Build base URL for filter links (preserves domain when clearing other filters)
$base_url = get_post_type_archive_link('sfwd-courses');

// Format options
$format_options = [
    ''                   => __('Alle formaten', 'stridence'),
    'meerdaagse'         => __('Meerdaagse opleiding', 'stridence'),
    'studiedag'          => __('Studiedag', 'stridence'),
    'webinar'            => __('Webinar', 'stridence'),
];

// Location options
$location_options = [
    ''         => __('Alle locaties', 'stridence'),
    'online'   => __('Online', 'stridence'),
    'gent'     => __('Gent', 'stridence'),
    'brussel'  => __('Brussel', 'stridence'),
    'antwerpen'=> __('Antwerpen', 'stridence'),
];

get_header();
?>

<!-- Page Header -->
<div class="bg-surface-alt border-b border-border">
    <div class="container py-8 lg:py-12">
        <h1 class="text-3xl lg:text-4xl font-heading font-bold text-text mb-2">
            <?php esc_html_e('Opleidingen', 'stridence'); ?>
        </h1>
        <p class="text-lg text-text-muted">
            <?php esc_html_e('Ontdek ons volledig aanbod aan professionele trainingen', 'stridence'); ?>
        </p>
    </div>
</div>

<!-- Domain Tabs -->
<?php if (!empty($domains)) : ?>
<div class="border-b border-border bg-surface">
    <div class="container">
        <nav class="flex overflow-x-auto -mb-px scrollbar-hide" aria-label="<?php esc_attr_e('Domeinen', 'stridence'); ?>">
            <?php
            // Build URL for "Alle" tab
            $alle_url = $base_url;
            if (!empty($current_format)) {
                $alle_url = add_query_arg('formaat', $current_format, $alle_url);
            }
            if (!empty($current_locatie)) {
                $alle_url = add_query_arg('locatie', $current_locatie, $alle_url);
            }

            $alle_active = empty($current_domain);
            $alle_classes = $alle_active
                ? 'whitespace-nowrap px-4 py-3 text-sm font-medium border-b-2 border-primary text-primary'
                : 'whitespace-nowrap px-4 py-3 text-sm font-medium border-b-2 border-transparent text-text-muted hover:text-text hover:border-border';
            ?>
            <a href="<?php echo esc_url($alle_url); ?>"
               class="<?php echo esc_attr($alle_classes); ?>"
               <?php echo $alle_active ? 'aria-current="page"' : ''; ?>>
                <?php esc_html_e('Alle', 'stridence'); ?>
            </a>

            <?php foreach ($domains as $domain) :
                // Build domain tab URL preserving other filters
                $domain_url = add_query_arg('domein', $domain->slug, $base_url);
                if (!empty($current_format)) {
                    $domain_url = add_query_arg('formaat', $current_format, $domain_url);
                }
                if (!empty($current_locatie)) {
                    $domain_url = add_query_arg('locatie', $current_locatie, $domain_url);
                }

                $is_active = ($current_domain === $domain->slug);
                $tab_classes = $is_active
                    ? 'whitespace-nowrap px-4 py-3 text-sm font-medium border-b-2 border-primary text-primary'
                    : 'whitespace-nowrap px-4 py-3 text-sm font-medium border-b-2 border-transparent text-text-muted hover:text-text hover:border-border';
            ?>
                <a href="<?php echo esc_url($domain_url); ?>"
                   class="<?php echo esc_attr($tab_classes); ?>"
                   <?php echo $is_active ? 'aria-current="page"' : ''; ?>>
                    <?php echo esc_html($domain->name); ?>
                </a>
            <?php endforeach; ?>
        </nav>
    </div>
</div>
<?php endif; ?>

<!-- Filters -->
<div class="container py-6">
    <form method="get" action="<?php echo esc_url($base_url); ?>" class="flex flex-wrap gap-4 items-center">
        <?php if (!empty($current_domain)) : ?>
            <input type="hidden" name="domein" value="<?php echo esc_attr($current_domain); ?>">
        <?php endif; ?>

        <!-- Format Filter -->
        <select name="formaat" class="input-select" onchange="this.form.submit()">
            <?php foreach ($format_options as $value => $label) : ?>
                <option value="<?php echo esc_attr($value); ?>" <?php selected($current_format, $value); ?>>
                    <?php echo esc_html($label); ?>
                </option>
            <?php endforeach; ?>
        </select>

        <!-- Location Filter -->
        <select name="locatie" class="input-select" onchange="this.form.submit()">
            <?php foreach ($location_options as $value => $label) : ?>
                <option value="<?php echo esc_attr($value); ?>" <?php selected($current_locatie, $value); ?>>
                    <?php echo esc_html($label); ?>
                </option>
            <?php endforeach; ?>
        </select>

        <?php if ($has_filters) :
            // Build clear URL that preserves domain filter
            $clear_url = $base_url;
            if (!empty($current_domain)) {
                $clear_url = add_query_arg('domein', $current_domain, $clear_url);
            }
        ?>
            <a href="<?php echo esc_url($clear_url); ?>" class="text-sm text-text-muted hover:text-text inline-flex items-center gap-1">
                <?php echo stridence_icon('x', 'w-4 h-4'); ?>
                <?php esc_html_e('Filters wissen', 'stridence'); ?>
            </a>
        <?php endif; ?>
    </form>
</div>

<!-- Course Grid -->
<div class="container pb-12">
    <?php if ($courses_query->have_posts()) : ?>
        <div class="grid gap-6 md:grid-cols-2 lg:grid-cols-3">
            <?php while ($courses_query->have_posts()) : $courses_query->the_post(); ?>
                <?php
                get_template_part('partials/card-course', null, [
                    'course' => get_post(),
                ]);
                ?>
            <?php endwhile; ?>
        </div>

        <!-- Pagination -->
        <?php if ($courses_query->max_num_pages > 1) : ?>
            <nav class="mt-12 flex justify-center" aria-label="<?php esc_attr_e('Paginatie', 'stridence'); ?>">
                <?php
                $pagination_args = [
                    'total'     => $courses_query->max_num_pages,
                    'current'   => $paged,
                    'mid_size'  => 2,
                    'prev_text' => '<span class="sr-only">' . __('Vorige', 'stridence') . '</span>' . stridence_icon('chevron-left', 'w-5 h-5'),
                    'next_text' => '<span class="sr-only">' . __('Volgende', 'stridence') . '</span>' . stridence_icon('chevron-right', 'w-5 h-5'),
                    'type'      => 'array',
                ];

                // Preserve filter params in pagination
                if (!empty($current_domain)) {
                    $pagination_args['add_args']['domein'] = $current_domain;
                }
                if (!empty($current_format)) {
                    $pagination_args['add_args']['formaat'] = $current_format;
                }
                if (!empty($current_locatie)) {
                    $pagination_args['add_args']['locatie'] = $current_locatie;
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
            'icon'    => 'search',
            'title'   => __('Geen opleidingen gevonden', 'stridence'),
            'message' => __('Er zijn geen opleidingen die voldoen aan je zoekcriteria. Probeer een andere filter of bekijk alle opleidingen.', 'stridence'),
            'action'  => __('Bekijk alle opleidingen', 'stridence'),
            'url'     => $base_url,
        ]);
        ?>
    <?php endif; ?>
</div>

<?php
wp_reset_postdata();
get_footer();
