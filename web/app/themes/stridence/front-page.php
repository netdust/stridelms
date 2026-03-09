<?php
/**
 * Homepage Template
 *
 * @package stridence
 */

get_header();
?>

<!-- Hero Section -->
<section class="bg-primary text-text-inverse py-16 lg:py-24">
    <div class="container">
        <div class="max-w-3xl">
            <h1 class="text-4xl lg:text-5xl font-heading font-bold mb-6">
                <?php esc_html_e('Professionele opleidingen voor de zorgsector', 'stridence'); ?>
            </h1>
            <p class="text-xl text-text-inverse/80 mb-8">
                <?php esc_html_e('Versterk je kennis en vaardigheden met onze erkende trainingen. Van ouderenzorg tot GGZ, van webinar tot meerdaagse opleiding.', 'stridence'); ?>
            </p>
            <div class="flex flex-wrap gap-4">
                <a href="<?php echo esc_url(home_url('/klassikaal/')); ?>" class="btn-primary bg-white text-primary hover:bg-white/90">
                    <?php esc_html_e('Bekijk opleidingen', 'stridence'); ?>
                </a>
                <a href="<?php echo esc_url(home_url('/trajecten/')); ?>" class="btn-ghost text-white hover:bg-white/10">
                    <?php esc_html_e('Ontdek trajecten', 'stridence'); ?>
                </a>
            </div>
        </div>
    </div>
</section>

<!-- Learning Mode Selector -->
<section class="section">
    <div class="container">
        <h2 class="font-heading text-2xl font-bold text-center mb-8">
            <?php esc_html_e('Hoe wil je leren?', 'stridence'); ?>
        </h2>
        <div class="grid md:grid-cols-3 gap-6">
            <?php
            // Count trajectories
            $trajectory_count = wp_count_posts('vad_trajectory');
            $trajectory_total = isset($trajectory_count->publish) ? (int) $trajectory_count->publish : 0;

            // Count open editions (klassikaal) — only need the count, not the posts
            $edition_query = new WP_Query([
                'post_type'      => 'vad_edition',
                'post_status'    => 'publish',
                'posts_per_page' => 1,
                'fields'         => 'ids',
                'meta_query'     => [
                    [
                        'key'     => '_ntdst_status',
                        'value'   => ['draft', 'completed', 'archived'],
                        'compare' => 'NOT IN',
                    ],
                ],
            ]);
            $edition_total = $edition_query->found_posts;

            // Count online courses — only need the count, not the posts
            $online_query = new WP_Query([
                'post_type'      => 'sfwd-courses',
                'post_status'    => 'publish',
                'posts_per_page' => 1,
                'fields'         => 'ids',
                'tax_query'      => [
                    [
                        'taxonomy' => 'stride_format',
                        'field'    => 'slug',
                        'terms'    => ['online', 'e-learning', 'webinar'],
                        'operator' => 'IN',
                    ],
                ],
            ]);
            $online_total = $online_query->found_posts;
            ?>

            <!-- Trajecten Card -->
            <a href="<?php echo esc_url(home_url('/trajecten/')); ?>" class="card-interactive p-8 text-center group">
                <div class="w-16 h-16 mx-auto mb-4 rounded-full bg-primary/10 flex items-center justify-center group-hover:bg-primary/20 transition-colors">
                    <?php echo stridence_icon('layers', 'w-8 h-8 text-primary'); ?>
                </div>
                <h3 class="font-heading font-semibold text-xl mb-2 text-text group-hover:text-primary transition-colors">
                    <?php esc_html_e('Trajecten', 'stridence'); ?>
                </h3>
                <p class="text-text-muted mb-4">
                    <?php esc_html_e('Volg een leertraject met meerdere cursussen en begeleiding', 'stridence'); ?>
                </p>
                <span class="text-sm font-medium text-primary">
                    <?php
                    printf(
                        esc_html(_n('%d traject', '%d trajecten', $trajectory_total, 'stridence')),
                        $trajectory_total
                    );
                    ?>
                </span>
            </a>

            <!-- Klassikaal Card -->
            <a href="<?php echo esc_url(home_url('/klassikaal/')); ?>" class="card-interactive p-8 text-center group">
                <div class="w-16 h-16 mx-auto mb-4 rounded-full bg-accent/10 flex items-center justify-center group-hover:bg-accent/20 transition-colors">
                    <?php echo stridence_icon('users', 'w-8 h-8 text-accent'); ?>
                </div>
                <h3 class="font-heading font-semibold text-xl mb-2 text-text group-hover:text-accent transition-colors">
                    <?php esc_html_e('Klassikaal', 'stridence'); ?>
                </h3>
                <p class="text-text-muted mb-4">
                    <?php esc_html_e('Leer samen met anderen onder begeleiding van ervaren docenten', 'stridence'); ?>
                </p>
                <span class="text-sm font-medium text-accent">
                    <?php
                    printf(
                        esc_html(_n('%d editie', '%d edities', $edition_total, 'stridence')),
                        $edition_total
                    );
                    ?>
                </span>
            </a>

            <!-- Online Card -->
            <a href="<?php echo esc_url(home_url('/online/')); ?>" class="card-interactive p-8 text-center group">
                <div class="w-16 h-16 mx-auto mb-4 rounded-full bg-success/10 flex items-center justify-center group-hover:bg-success/20 transition-colors">
                    <?php echo stridence_icon('monitor', 'w-8 h-8 text-success'); ?>
                </div>
                <h3 class="font-heading font-semibold text-xl mb-2 text-text group-hover:text-success transition-colors">
                    <?php esc_html_e('Online', 'stridence'); ?>
                </h3>
                <p class="text-text-muted mb-4">
                    <?php esc_html_e('Leer op je eigen tempo met e-learning en webinars', 'stridence'); ?>
                </p>
                <span class="text-sm font-medium text-success">
                    <?php
                    printf(
                        esc_html(_n('%d cursus', '%d cursussen', $online_total, 'stridence')),
                        $online_total
                    );
                    ?>
                </span>
            </a>
        </div>
    </div>
</section>

<!-- Featured Editions (placeholder) -->
<section class="section-alt">
    <div class="container">
        <div class="flex items-center justify-between mb-8">
            <h2 class="font-heading text-2xl font-bold">
                <?php esc_html_e('Binnenkort gepland', 'stridence'); ?>
            </h2>
            <a href="<?php echo esc_url(home_url('/klassikaal/')); ?>" class="btn-ghost">
                <?php esc_html_e('Alle edities', 'stridence'); ?> &rarr;
            </a>
        </div>

        <div class="grid gap-6 md:grid-cols-2 lg:grid-cols-3">
            <?php
            // Get upcoming editions
            // This will be replaced with actual EditionRepository call
            $courses = get_posts([
                'post_type' => 'sfwd-courses',
                'posts_per_page' => 6,
                'orderby' => 'date',
                'order' => 'DESC',
            ]);

            if (!empty($courses)) :
                foreach ($courses as $course) :
            ?>
                <article class="card overflow-hidden flex flex-col">
                    <?php if (has_post_thumbnail($course)) : ?>
                        <a href="<?php echo esc_url(get_permalink($course)); ?>" class="block aspect-video overflow-hidden">
                            <?php echo get_the_post_thumbnail($course, 'stride_course_card', ['class' => 'w-full h-full object-cover']); ?>
                        </a>
                    <?php endif; ?>

                    <div class="p-5 flex-1 flex flex-col">
                        <h3 class="font-heading font-semibold text-lg mb-2 line-clamp-2">
                            <a href="<?php echo esc_url(get_permalink($course)); ?>" class="text-text hover:text-primary">
                                <?php echo esc_html($course->post_title); ?>
                            </a>
                        </h3>

                        <p class="text-sm text-text-muted line-clamp-2 mb-4 flex-1">
                            <?php echo esc_html(wp_trim_words($course->post_excerpt ?: $course->post_content, 20)); ?>
                        </p>

                        <a href="<?php echo esc_url(get_permalink($course)); ?>" class="btn-primary w-full text-center">
                            <?php esc_html_e('Meer info', 'stridence'); ?>
                        </a>
                    </div>
                </article>
            <?php
                endforeach;
            else :
            ?>
                <div class="col-span-full text-center py-12">
                    <p class="text-text-muted">
                        <?php esc_html_e('Er zijn momenteel geen geplande opleidingen.', 'stridence'); ?>
                    </p>
                    <p class="text-sm text-text-muted mt-2">
                        <?php esc_html_e('Schrijf je in voor de nieuwsbrief om op de hoogte te blijven.', 'stridence'); ?>
                    </p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</section>

<!-- Value Proposition -->
<section class="section">
    <div class="container">
        <h2 class="font-heading text-2xl font-bold text-center mb-12">
            <?php esc_html_e('Waarom kiezen voor Stride?', 'stridence'); ?>
        </h2>

        <div class="grid gap-8 md:grid-cols-3">
            <div class="text-center">
                <div class="w-16 h-16 mx-auto mb-4 rounded-full bg-accent/10 flex items-center justify-center">
                    <?php echo stridence_icon('award', 'w-8 h-8 text-accent'); ?>
                </div>
                <h3 class="font-heading font-semibold text-lg mb-2">
                    <?php esc_html_e('Geaccrediteerd', 'stridence'); ?>
                </h3>
                <p class="text-text-muted">
                    <?php esc_html_e('Onze opleidingen zijn erkend door RIZIV, VDAB en andere instanties.', 'stridence'); ?>
                </p>
            </div>

            <div class="text-center">
                <div class="w-16 h-16 mx-auto mb-4 rounded-full bg-primary/10 flex items-center justify-center">
                    <?php echo stridence_icon('users', 'w-8 h-8 text-primary'); ?>
                </div>
                <h3 class="font-heading font-semibold text-lg mb-2">
                    <?php esc_html_e('Praktijkgericht', 'stridence'); ?>
                </h3>
                <p class="text-text-muted">
                    <?php esc_html_e('Direct toepasbaar in je dagelijkse werk door ervaren docenten.', 'stridence'); ?>
                </p>
            </div>

            <div class="text-center">
                <div class="w-16 h-16 mx-auto mb-4 rounded-full bg-success/10 flex items-center justify-center">
                    <?php echo stridence_icon('check-circle', 'w-8 h-8 text-success'); ?>
                </div>
                <h3 class="font-heading font-semibold text-lg mb-2">
                    <?php esc_html_e('Flexibel leren', 'stridence'); ?>
                </h3>
                <p class="text-text-muted">
                    <?php esc_html_e('Kies uit meerdaagse opleidingen, studiedagen of e-learning.', 'stridence'); ?>
                </p>
            </div>
        </div>
    </div>
</section>

<?php
get_footer();
