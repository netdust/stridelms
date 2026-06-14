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
$uid = get_current_user_id() ?: null;

// Split the page-1 slice at the dateless run for the "Binnenkort — toon
// interesse" header. Same emptiness predicate as
// stridence_catalog_order_into_bands() — reused, not forked. Bands are
// contiguous in the ordered list (A.. B.. C..), so the dateless items form a
// single run. KLASSIKAAL ONLY — page-online.php never does this (online is a
// flat enrollable grid by design; online courses are always-on).
$today = date('Y-m-d');
$is_dateless = static fn(array $i): bool =>
    ($i['kind'] ?? 'edition') === 'edition'
    && empty($i['edition']['start_date'] ?? null);

$b_indexes = array_keys(array_filter($initial_slice, $is_dateless));
$has_band  = !empty($b_indexes);

get_header();
?>

<!-- Page Header -->
<div class="bg-surface-alt">
    <div class="container py-[clamp(28px,5vw,44px)]">
        <h1 class="font-serif font-normal text-[clamp(32px,4.5vw,44px)] leading-[1.1] text-text mb-2">
            <?php esc_html_e('Klassikale opleidingen', 'stridence'); ?>
        </h1>
        <p class="text-[16px] text-text-muted max-w-[560px]">
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
            const prevFilter = this.filter;
            const prevPage = this.page;
            this.filter = slug;
            this.page = 1;
            // Roll back on failure (S8): the grid still shows the old slice,
            // so filter/page must match it — same as the append path does.
            if (!await this.fetchPage(true)) {
                this.filter = prevFilter;
                this.page = prevPage;
            }
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
                return true;
            } catch (e) {
                this.error = true;
                if (!replace) this.page--;
                return false;
            } finally {
                this.loading = false;
            }
        }
    }">

    <!-- Theme Filter Chips -->
    <?php if (!empty($themes)) : ?>
    <div class="container pt-6 pb-2">
        <div class="flex flex-wrap gap-2" role="group" aria-label="<?php esc_attr_e("Thema's", 'stridence'); ?>">

            <!-- "Alles" chip with total count -->
            <button @click="setFilter('')" type="button"
                class="rounded-full px-4 py-2 min-h-[36px] border text-[13px] font-bold transition-colors"
                :class="filter === ''
                    ? 'bg-primary text-white border-primary'
                    : 'bg-surface-card border-border text-text-muted'"
                :aria-pressed="filter === ''">
                <?php esc_html_e('Alles', 'stridence'); ?>
                <span class="text-[11px] font-bold tabular-nums rounded-full px-1.5 py-0.5 ml-1.5"
                    :class="filter === ''
                        ? 'bg-white/20 text-white'
                        : 'bg-surface-alt text-text-faint'"
                ><?php echo esc_html((string) $total); ?></span>
            </button>

            <?php foreach ($themes as $theme) :
                $count = $theme_counts[$theme->slug] ?? 0;
                if ($count === 0) {
                    continue;
                }
                ?>
                <button @click="setFilter('<?php echo esc_attr($theme->slug); ?>')" type="button"
                    class="rounded-full px-4 py-2 min-h-[36px] border text-[13px] font-bold transition-colors"
                    :class="filter === '<?php echo esc_attr($theme->slug); ?>'
                        ? 'bg-primary text-white border-primary'
                        : 'bg-surface-card border-border text-text-muted'"
                    :aria-pressed="filter === '<?php echo esc_attr($theme->slug); ?>'">
                    <?php echo esc_html($theme->name); ?>
                    <span class="text-[11px] font-bold tabular-nums rounded-full px-1.5 py-0.5 ml-1.5"
                        :class="filter === '<?php echo esc_attr($theme->slug); ?>'
                            ? 'bg-white/20 text-white'
                            : 'bg-surface-alt text-text-faint'"
                    ><?php echo esc_html((string) $count); ?></span>
                </button>
            <?php endforeach; ?>

        </div>
    </div>
    <?php endif; ?>

    <!-- Edition Grid -->
    <div class="container py-6 lg:py-8">
        <?php if ($total > 0) : ?>
            <div class="grid grid-cols-[repeat(auto-fill,minmax(300px,1fr))] gap-[18px]" x-ref="grid">
                <?php
                if ($has_band) {
                    $first  = (int) min($b_indexes);
                    $last   = (int) max($b_indexes);
                    $before = array_slice($initial_slice, 0, $first);
                    $band   = array_slice($initial_slice, $first, $last - $first + 1);
                    $after  = array_slice($initial_slice, $last + 1);

                    echo stridence_catalog_render_cards($before, $uid); // escaped within partials
                    // Full-row band header — server-render only, KLASSIKAAL only.
                    // The "Toon meer" / theme-filter endpoint returns flat cards;
                    // Band B is fully consumed on page 1 (the page-1 guard in
                    // stridence_catalog_order_into_bands() guarantees it), so
                    // pages >=2 and filtered replaces never need this separator.
                    // /online has no band at all. See the dateless-editions
                    // catalog plan for why this stays page-1-only + klassikaal-only.
                    ?>
                    <div class="col-span-full mt-2 mb-1 flex items-center gap-3">
                        <h2 class="text-[15px] font-bold text-text"><?php esc_html_e('Binnenkort — toon interesse', 'stridence'); ?></h2>
                        <span class="flex-1 h-px bg-border-soft" aria-hidden="true"></span>
                    </div>
                    <?php
                    echo stridence_catalog_render_cards($band, $uid);  // escaped within partials
                    echo stridence_catalog_render_cards($after, $uid); // escaped within partials
                } else {
                    echo stridence_catalog_render_cards($initial_slice, $uid); // escaped within partials
                }
                ?>
            </div>

            <!-- Empty state for filtered results -->
            <div x-show="filteredTotal === 0 && !loading" x-cloak>
                <?php
                stridence_template_part('partials/empty-state', null, [
                    'icon'    => 'search',
                    'title'   => __('Geen opleidingen gevonden', 'stridence'),
                    'message' => __('Er zijn geen klassikale opleidingen in dit thema.', 'stridence'),
                    'band'    => true,
                ]);
                ?>
                <div class="mt-4 flex justify-center">
                    <button @click="setFilter('')" type="button" class="btn-ghost">
                        <?php esc_html_e('Toon alles', 'stridence'); ?>
                    </button>
                </div>
            </div>

            <!-- Load error -->
            <p x-show="error" x-cloak class="mt-8 text-center text-sm text-text-muted">
                <?php esc_html_e('Er ging iets mis bij het laden. Probeer het opnieuw.', 'stridence'); ?>
            </p>

            <!-- Toon meer -->
            <div x-show="hasMore" x-cloak class="mt-8 flex justify-center">
                <button @click="loadMore()" :disabled="loading" type="button"
                    :class="loading ? 'btn-load-more btn-loading' : 'btn-load-more'">
                    <span x-show="loading" class="spinner" aria-hidden="true"></span>
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
