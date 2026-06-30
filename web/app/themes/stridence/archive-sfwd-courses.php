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
$archive_user_id = get_current_user_id() ?: null;

// --- Classroom editions ---
// SEO teaser strip (6 items). PRODUCT RULING (Stefan, 2026-06-30): this strip
// is DELIBERATELY active-status-only with NO date window and EXCLUDES dateless
// editions — distinct from the /klassikaal + /online catalog (which is
// date-windowed and includes dateless). The query/shaping lives in stride-core
// (EditionService::getArchiveTeaserItems → EditionRepository teaser queries) so
// no raw WP_Query lives in the theme (Task 3.3 / INV-3); the teaser's distinct
// behaviour is PRESERVED there, not converged.
$classroom_items = $editionService->getArchiveTeaserItems('classroom');

// --- Trajectories ---
$trajectory_model = ntdst_data()->get('vad_trajectory');
$trajectories = $trajectory_model->where('post_status', 'publish')
                                 ->orderBy('menu_order', 'ASC')
                                 ->orderBy('post_title', 'ASC')
                                 ->withMeta()
                                 ->limit(3)
                                 ->get();

// --- Online enrollables (active editions + pure-LD courses) ---
// SEO teaser strip (6 items). Unlike the classroom strip, the online strip IS
// date-windowed (canonical eligibility, past-grace dropped) and tops up with
// pure-LD online courses — but, like the classroom strip, EXCLUDES dateless
// editions (teaser only). Same stride-core method owns the query + shaping +
// top-up (Task 3.3); see [[lesson_url_role_split]].
$online_items = $editionService->getArchiveTeaserItems('online');

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
