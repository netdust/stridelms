<?php
/**
 * Template Name: Klassikaal
 *
 * Catalog page for classroom/in-person courses.
 *
 * Server-renders the first STRIDENCE_CATALOG_PER_PAGE cards through the
 * batch pre-pass (helpers/catalog.php — Task G1 / audit 2.2); theme
 * filtering and "Toon meer" pagination fetch further server-rendered
 * slices via the `stride_catalog_page` endpoint (ntdstAPI).
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

// Eligible items (light list: ids + theme slugs) + counts for the tabs.
$catalog_items = stridence_catalog_items('klassikaal');
$theme_counts = stridence_catalog_theme_counts($catalog_items);
$total = count($catalog_items);
$per_page = STRIDENCE_CATALOG_PER_PAGE;

// Server-render the first slice only — the rest is paged via the endpoint.
$initial_slice = array_slice($catalog_items, 0, $per_page);
$initial_html = stridence_catalog_render_cards($initial_slice, get_current_user_id() ?: null);

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

<div x-data="{
        catalog: 'klassikaal',
        filter: '',
        page: 1,
        total: <?php echo (int) $total; ?>,
        counts: <?php echo esc_attr(wp_json_encode($theme_counts, JSON_FORCE_OBJECT)); ?>,
        shown: <?php echo count($initial_slice); ?>,
        loading: false,
        error: false,
        get filteredTotal() { return this.filter ? (this.counts[this.filter] || 0) : this.total },
        get hasMore() { return this.shown < this.filteredTotal },
        async setFilter(slug) {
            if (this.loading || this.filter === slug) return;
            this.filter = slug;
            this.page = 1;
            await this.fetchPage(true);
        },
        async loadMore() {
            if (this.loading) return;
            this.page++;
            await this.fetchPage(false);
        },
        async fetchPage(replace) {
            this.loading = true;
            this.error = false;
            try {
                const res = await ntdstAPI.call('stride_catalog_page', { catalog: this.catalog, page: this.page, theme: this.filter });
                if (replace) {
                    this.$refs.grid.innerHTML = res.html;
                    this.shown = res.count;
                } else {
                    this.$refs.grid.insertAdjacentHTML('beforeend', res.html);
                    this.shown += res.count;
                }
            } catch (e) {
                this.error = true;
                if (!replace) this.page--;
            } finally {
                this.loading = false;
            }
        }
    }">

    <!-- Theme Filter Tabs -->
    <?php if (!empty($themes)) : ?>
    <div class="border-b border-border bg-surface">
        <div class="container">
            <nav class="flex overflow-x-auto -mb-px scrollbar-hide" aria-label="<?php esc_attr_e("Thema's", 'stridence'); ?>">
                <button @click="setFilter('')" type="button"
                    :class="filter === ''
                        ? 'whitespace-nowrap px-4 py-3 text-sm font-medium border-b-2 border-primary text-primary'
                        : 'whitespace-nowrap px-4 py-3 text-sm font-medium border-b-2 border-transparent text-text-muted hover:text-text hover:border-border'"
                    :aria-current="filter === '' ? 'page' : false">
                    <?php esc_html_e('Alle', 'stridence'); ?>
                    <span class="ml-1 text-xs text-text-muted">(<?php echo esc_html((string) $total); ?>)</span>
                </button>

                <?php foreach ($themes as $theme) :
                    $count = $theme_counts[$theme->slug] ?? 0;
                    if ($count === 0) {
                        continue;
                    }
                    ?>
                    <button @click="setFilter('<?php echo esc_attr($theme->slug); ?>')" type="button"
                        :class="filter === '<?php echo esc_attr($theme->slug); ?>'
                            ? 'whitespace-nowrap px-4 py-3 text-sm font-medium border-b-2 border-primary text-primary'
                            : 'whitespace-nowrap px-4 py-3 text-sm font-medium border-b-2 border-transparent text-text-muted hover:text-text hover:border-border'"
                        :aria-current="filter === '<?php echo esc_attr($theme->slug); ?>' ? 'page' : false">
                        <?php echo esc_html($theme->name); ?>
                        <span class="ml-1 text-xs text-text-muted">(<?php echo esc_html((string) $count); ?>)</span>
                    </button>
                <?php endforeach; ?>
            </nav>
        </div>
    </div>
    <?php endif; ?>

    <!-- Edition Grid -->
    <div class="container py-8 lg:py-12">
        <?php if ($total > 0) : ?>
            <div class="grid gap-6 md:grid-cols-2 lg:grid-cols-3" x-ref="grid">
                <?php echo $initial_html; // Card HTML — escaped within the partials.?>
            </div>

            <!-- Empty state for filtered results -->
            <div x-show="filteredTotal === 0 && !loading" x-cloak class="text-center py-12">
                <?php
                stridence_template_part('partials/empty-state', null, [
                    'icon'    => 'calendar',
                    'title'   => __('Geen opleidingen gevonden', 'stridence'),
                    'message' => __('Er zijn geen klassikale opleidingen in dit thema.', 'stridence'),
                ]);
            ?>
            </div>

            <!-- Load error -->
            <p x-show="error" x-cloak class="mt-8 text-center text-sm text-text-muted">
                <?php esc_html_e('Er ging iets mis bij het laden. Probeer het opnieuw.', 'stridence'); ?>
            </p>

            <!-- Toon meer -->
            <div x-show="hasMore" x-cloak class="mt-12 text-center">
                <button @click="loadMore()" :disabled="loading" type="button" class="btn-primary">
                    <span x-show="!loading"><?php esc_html_e('Toon meer', 'stridence'); ?></span>
                    <span x-show="loading"><?php esc_html_e('Laden…', 'stridence'); ?></span>
                </button>
            </div>

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
