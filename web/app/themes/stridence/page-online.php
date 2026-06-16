<?php
/**
 * Template Name: Online
 *
 * Catalog page for online/e-learning courses.
 *
 * One card per enrollable: an active edition of an online-format course, OR
 * a pure-LD online course with zero editions (lesson_url_role_split — the
 * catalog list = the set of things a visitor can enroll in).
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
$catalog_items = stridence_catalog_items('online');
$theme_counts = stridence_catalog_theme_counts($catalog_items);
$total = count($catalog_items);
$per_page = STRIDENCE_CATALOG_PER_PAGE;

// Server-render the first slice only — the rest is paged via the endpoint.
$initial_slice = array_slice($catalog_items, 0, $per_page);
$initial_html = stridence_catalog_render_cards($initial_slice, get_current_user_id() ?: null);

get_header();
?>

<!-- Page Header -->
<div class="bg-surface-alt">
    <div class="container py-[clamp(28px,5vw,44px)]">
        <h1 class="font-serif font-normal text-[clamp(32px,4.5vw,44px)] leading-[1.1] text-text mb-[10px]">
            <?php esc_html_e('Online leren', 'stridence'); ?>
        </h1>
        <p class="text-[16px] text-text-muted max-w-[560px]">
            <?php esc_html_e('Leer op je eigen tempo met onze e-learningmodules en webinars — start wanneer het jou past.', 'stridence'); ?>
        </p>
    </div>
</div>

<div x-data="{
        catalog: 'online',
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

    <!-- Filter Chips -->
    <?php if (!empty($themes)) : ?>
    <div class="container pt-6 pb-2">
        <div class="flex gap-2 flex-wrap" role="group" aria-label="<?php esc_attr_e("Filter op thema", 'stridence'); ?>">
            <!-- "Alles" chip -->
            <button @click="setFilter('')" type="button"
                class="inline-flex items-center gap-[7px] rounded-full px-4 py-2 min-h-[36px] border text-[13px] font-bold transition-colors duration-150"
                :class="filter === ''
                    ? 'bg-primary text-white border-primary'
                    : 'bg-surface-card border-border text-text-muted hover:border-primary/40'"
                :aria-pressed="filter === ''">
                <?php esc_html_e('Alles', 'stridence'); ?>
                <span class="text-[11px] font-bold tabular-nums rounded-full px-[7px] py-px"
                    :class="filter === ''
                        ? 'bg-white/20 text-white'
                        : 'bg-surface-alt text-text-faint'">
                    <?php echo esc_html((string) $total); ?>
                </span>
            </button>

            <?php foreach ($themes as $theme) :
                $count = $theme_counts[$theme->slug] ?? 0;
                if ($count === 0) {
                    continue;
                }
                ?>
                <button @click="setFilter('<?php echo esc_attr($theme->slug); ?>')" type="button"
                    class="inline-flex items-center gap-[7px] rounded-full px-4 py-2 min-h-[36px] border text-[13px] font-bold transition-colors duration-150"
                    :class="filter === '<?php echo esc_attr($theme->slug); ?>'
                        ? 'bg-primary text-white border-primary'
                        : 'bg-surface-card border-border text-text-muted hover:border-primary/40'"
                    :aria-pressed="filter === '<?php echo esc_attr($theme->slug); ?>'">
                    <?php echo esc_html($theme->name); ?>
                    <span class="text-[11px] font-bold tabular-nums rounded-full px-[7px] py-px"
                        :class="filter === '<?php echo esc_attr($theme->slug); ?>'
                            ? 'bg-white/20 text-white'
                            : 'bg-surface-alt text-text-faint'">
                        <?php echo esc_html((string) $count); ?>
                    </span>
                </button>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Enrollable Grid (one card per active edition + one per pure-LD course) -->
    <div class="container py-6 lg:py-8">
        <?php if ($total > 0) : ?>
            <div class="grid grid-cols-[repeat(auto-fill,minmax(300px,1fr))] gap-[18px]" x-ref="grid">
                <?php
                // /online renders a flat enrollable grid by design — online
                // courses are always-on, so there is NO "Binnenkort — toon
                // interesse" band here. Dateless online editions are normal
                // enroll cards (status stays Open). The only behavior change on
                // /online is that more cards appear via the inclusion fix; the
                // render path is unchanged. See the dateless-editions catalog plan.
                echo $initial_html; // Card HTML — escaped within the partials.
            ?>
            </div>

            <!-- Empty state for filtered results -->
            <div x-show="filteredTotal === 0 && !loading" x-cloak>
                <?php
            stridence_template_part('partials/empty-state', null, [
                'icon'    => 'monitor',
                'title'   => __('Geen online opleidingen binnen dit thema', 'stridence'),
                'message' => __('We werken aan nieuwe modules rond dit thema. Bekijk intussen het volledige aanbod.', 'stridence'),
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
                'icon'    => 'monitor',
                'title'   => __('Geen opleidingen gevonden', 'stridence'),
                'message' => __('Er zijn momenteel geen online opleidingen beschikbaar.', 'stridence'),
            ]);
            ?>
        <?php endif; ?>
    </div>
</div>

<?php get_footer(); ?>
