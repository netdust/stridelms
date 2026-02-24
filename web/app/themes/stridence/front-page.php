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
                <a href="<?php echo esc_url(home_url('/cursussen/')); ?>" class="btn-primary bg-white text-primary hover:bg-white/90">
                    <?php esc_html_e('Bekijk opleidingen', 'stridence'); ?>
                </a>
                <a href="<?php echo esc_url(home_url('/trajecten/')); ?>" class="btn-ghost text-white hover:bg-white/10">
                    <?php esc_html_e('Ontdek trajecten', 'stridence'); ?>
                </a>
            </div>
        </div>
    </div>
</section>

<!-- Quick Category Links -->
<section class="section">
    <div class="container">
        <h2 class="font-heading text-2xl font-bold text-center mb-8">
            <?php esc_html_e('Opleidingen per domein', 'stridence'); ?>
        </h2>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
            <?php
            $domains = get_terms([
                'taxonomy' => 'stride_domain',
                'hide_empty' => true,
            ]);

            if (!is_wp_error($domains) && !empty($domains)) :
                foreach ($domains as $domain) :
            ?>
                <a href="<?php echo esc_url(get_term_link($domain)); ?>"
                   class="card-interactive p-6 text-center">
                    <span class="text-lg font-semibold text-text">
                        <?php echo esc_html($domain->name); ?>
                    </span>
                    <span class="block text-sm text-text-muted mt-1">
                        <?php
                        printf(
                            esc_html(_n('%d opleiding', '%d opleidingen', $domain->count, 'stridence')),
                            $domain->count
                        );
                        ?>
                    </span>
                </a>
            <?php
                endforeach;
            else :
            ?>
                <!-- Fallback categories -->
                <a href="<?php echo esc_url(home_url('/cursussen/?domein=ouderenzorg')); ?>" class="card-interactive p-6 text-center">
                    <span class="text-lg font-semibold text-text"><?php esc_html_e('Ouderenzorg', 'stridence'); ?></span>
                </a>
                <a href="<?php echo esc_url(home_url('/cursussen/?domein=ggz')); ?>" class="card-interactive p-6 text-center">
                    <span class="text-lg font-semibold text-text"><?php esc_html_e('Geestelijke gezondheidszorg', 'stridence'); ?></span>
                </a>
                <a href="<?php echo esc_url(home_url('/cursussen/?domein=eerste-lijn')); ?>" class="card-interactive p-6 text-center">
                    <span class="text-lg font-semibold text-text"><?php esc_html_e('Eerste lijn', 'stridence'); ?></span>
                </a>
                <a href="<?php echo esc_url(home_url('/cursussen/?domein=ziekenhuiszorg')); ?>" class="card-interactive p-6 text-center">
                    <span class="text-lg font-semibold text-text"><?php esc_html_e('Ziekenhuiszorg', 'stridence'); ?></span>
                </a>
            <?php endif; ?>
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
            <a href="<?php echo esc_url(home_url('/cursussen/')); ?>" class="btn-ghost">
                <?php esc_html_e('Alle opleidingen', 'stridence'); ?> &rarr;
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
