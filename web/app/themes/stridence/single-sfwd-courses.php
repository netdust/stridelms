<?php
/**
 * Course Detail Template
 *
 * Single template for LearnDash courses (sfwd-courses post type).
 * Two-column layout with sticky tab navigation and edition sidebar.
 *
 * @package stridence
 */

defined('ABSPATH') || exit;

$course_id = get_the_ID();

// TODO: Wire up EditionService when available
// $editionService = ntdst_get(\Stride\Modules\Edition\EditionService::class);
// $editions = $editionService->getEditionsForCourse($course_id);
$editions = [];

// Get first edition for mobile CTA (if any)
$first_edition = !empty($editions) ? $editions[0] : null;
$enrollment_url = $first_edition ? stride_enrollment_url((int) ($first_edition['id'] ?? $first_edition['ID'] ?? 0)) : '';

// Breadcrumb items
$breadcrumbs = [
    ['label' => __('Opleidingen', 'stridence'), 'url' => get_post_type_archive_link('sfwd-courses')],
    ['label' => get_the_title()],
];

get_header();
?>

<article <?php post_class('pb-12 lg:pb-16'); ?>>
    <!-- Header Section -->
    <div class="bg-surface-alt border-b border-border">
        <div class="container py-8 lg:py-12">
            <?php
            get_template_part('partials/breadcrumb', null, [
                'items' => $breadcrumbs,
            ]);
            ?>

            <h1 class="font-heading text-3xl lg:text-4xl font-bold text-text mb-4">
                <?php the_title(); ?>
            </h1>

            <?php if (has_excerpt()) : ?>
                <p class="text-lg text-text-muted max-w-3xl">
                    <?php echo esc_html(get_the_excerpt()); ?>
                </p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Sticky Tab Bar -->
    <div class="sticky top-16 lg:top-20 bg-surface border-b border-border z-30" x-data="courseDetailTabs()">
        <div class="container">
            <nav class="flex gap-6 overflow-x-auto -mb-px scrollbar-hide" aria-label="<?php esc_attr_e('Cursus secties', 'stridence'); ?>">
                <a href="#overzicht"
                   class="tab-link"
                   :class="{ 'tab-active': activeTab === 'overzicht' }"
                   @click.prevent="scrollTo('overzicht')">
                    <?php esc_html_e('Overzicht', 'stridence'); ?>
                </a>
                <a href="#programma"
                   class="tab-link"
                   :class="{ 'tab-active': activeTab === 'programma' }"
                   @click.prevent="scrollTo('programma')">
                    <?php esc_html_e('Programma', 'stridence'); ?>
                </a>
                <a href="#sprekers"
                   class="tab-link"
                   :class="{ 'tab-active': activeTab === 'sprekers' }"
                   @click.prevent="scrollTo('sprekers')">
                    <?php esc_html_e('Sprekers', 'stridence'); ?>
                </a>
                <a href="#praktisch"
                   class="tab-link"
                   :class="{ 'tab-active': activeTab === 'praktisch' }"
                   @click.prevent="scrollTo('praktisch')">
                    <?php esc_html_e('Praktisch', 'stridence'); ?>
                </a>
            </nav>
        </div>
    </div>

    <!-- Two Column Layout -->
    <div class="container py-8 lg:py-12">
        <div class="grid lg:grid-cols-3 gap-8 lg:gap-12">
            <!-- Main Content (2/3) -->
            <div class="lg:col-span-2 space-y-12">
                <!-- Overzicht Section -->
                <section id="overzicht" class="scroll-mt-32">
                    <div class="prose-stride max-w-none">
                        <?php the_content(); ?>
                    </div>
                </section>

                <!-- Programma Section -->
                <section id="programma" class="scroll-mt-32">
                    <h2 class="font-heading text-2xl font-bold text-text mb-6">
                        <?php esc_html_e('Programma', 'stridence'); ?>
                    </h2>
                    <div class="learndash-course-content">
                        <?php echo do_shortcode('[course_content course_id="' . esc_attr($course_id) . '"]'); ?>
                    </div>
                </section>

                <!-- Sprekers Section -->
                <section id="sprekers" class="scroll-mt-32">
                    <h2 class="font-heading text-2xl font-bold text-text mb-6">
                        <?php esc_html_e('Sprekers', 'stridence'); ?>
                    </h2>
                    <p class="text-text-muted">
                        <?php esc_html_e('Informatie over sprekers wordt binnenkort toegevoegd.', 'stridence'); ?>
                    </p>
                </section>

                <!-- Praktisch Section -->
                <section id="praktisch" class="scroll-mt-32">
                    <h2 class="font-heading text-2xl font-bold text-text mb-6">
                        <?php esc_html_e('Praktische informatie', 'stridence'); ?>
                    </h2>
                    <div class="grid sm:grid-cols-2 gap-4">
                        <div class="card-bordered p-5">
                            <h3 class="font-semibold text-text mb-2 flex items-center gap-2">
                                <?php echo stridence_icon('users', 'w-5 h-5 text-primary'); ?>
                                <?php esc_html_e('Doelgroep', 'stridence'); ?>
                            </h3>
                            <p class="text-text-muted text-sm">
                                <?php esc_html_e('Zorgprofessionals', 'stridence'); ?>
                            </p>
                        </div>
                        <div class="card-bordered p-5">
                            <h3 class="font-semibold text-text mb-2 flex items-center gap-2">
                                <?php echo stridence_icon('award', 'w-5 h-5 text-primary'); ?>
                                <?php esc_html_e('Accreditatie', 'stridence'); ?>
                            </h3>
                            <p class="text-text-muted text-sm">
                                <?php esc_html_e('In aanvraag', 'stridence'); ?>
                            </p>
                        </div>
                    </div>
                </section>
            </div>

            <!-- Sidebar (1/3) -->
            <div class="lg:col-span-1">
                <?php
                get_template_part('templates/course/sidebar-edition', null, [
                    'editions'  => $editions,
                    'course_id' => $course_id,
                ]);
                ?>
            </div>
        </div>
    </div>

    <!-- Mobile Sticky CTA (hidden on lg+) -->
    <?php if (!empty($editions) && $enrollment_url) : ?>
        <div class="fixed bottom-0 left-0 right-0 bg-surface border-t border-border p-4 lg:hidden z-40 safe-area-bottom">
            <a href="<?php echo esc_url($enrollment_url); ?>" class="btn-primary w-full text-center">
                <?php esc_html_e('Inschrijven', 'stridence'); ?>
            </a>
        </div>
    <?php endif; ?>
</article>

<?php get_footer(); ?>
