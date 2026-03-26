<?php
/**
 * Homepage Template — Editorial
 *
 * @package stridence
 */

get_header();
?>

<!-- Hero Section -->
<section class="relative pt-40 lg:pt-52 pb-20 lg:pb-32 px-6 overflow-hidden">
    <!-- Decorative Blobs -->
    <div class="absolute -top-24 -left-24 w-96 h-96 bg-primary-light rounded-full blur-blob"></div>
    <div class="absolute top-1/2 -right-48 w-[500px] h-[500px] bg-secondary-container rounded-full blur-blob"></div>

    <div class="container relative z-10">
        <div class="max-w-3xl">
            <span class="inline-block text-primary font-label font-semibold tracking-widest uppercase text-xs mb-6">
                <?php esc_html_e('Professionele Ontwikkeling in de Zorg', 'stridence'); ?>
            </span>
            <h1 class="font-serif text-6xl lg:text-7xl xl:text-8xl font-light leading-tight mb-8">
                <?php echo wp_kses(
                    __('Versterk je zorgteam met <em class="italic text-primary">deskundige</em> opleidingen.', 'stridence'),
                    ['em' => ['class' => []]]
                ); ?>
            </h1>
            <p class="text-lg lg:text-xl text-text-muted leading-relaxed max-w-2xl mb-10">
                <?php esc_html_e('Wij geloven dat leren net zo zorgvuldig moet zijn als het vak dat het ondersteunt. Ontdek een platform ontworpen voor verdieping, focus en menselijke verbinding.', 'stridence'); ?>
            </p>
            <div class="flex flex-wrap gap-4">
                <a href="<?php echo esc_url(home_url('/klassikaal/')); ?>" class="btn-primary btn-lg">
                    <?php esc_html_e('Bekijk opleidingen', 'stridence'); ?>
                </a>
                <a href="<?php echo esc_url(home_url('/over-ons/')); ?>" class="btn-ghost text-text group">
                    <?php esc_html_e('Onze aanpak', 'stridence'); ?>
                    <svg class="w-4 h-4 group-hover:translate-x-1 transition-transform" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                </a>
            </div>
        </div>
    </div>
</section>

<!-- Learning Mode Selector -->
<section class="section">
    <div class="container">
        <h2 class="font-heading text-3xl font-bold text-center mb-3">
            <?php esc_html_e('Hoe wil je leren?', 'stridence'); ?>
        </h2>
        <p class="text-center text-text-muted mb-12 text-lg">
            <?php esc_html_e('Kies het format dat bij jou past', 'stridence'); ?>
        </p>

        <div class="grid md:grid-cols-3 gap-6">
            <?php
            $trajectory_count = wp_count_posts('vad_trajectory');
            $trajectory_total = isset($trajectory_count->publish) ? (int) $trajectory_count->publish : 0;

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

            <a href="<?php echo esc_url(home_url('/trajecten/')); ?>" class="card p-10 text-center group cursor-pointer">
                <div class="w-16 h-16 mx-auto mb-5 rounded-full bg-primary-subtle flex items-center justify-center group-hover:bg-primary/20 transition-colors">
                    <?php echo stridence_icon('layers', 'w-7 h-7 text-primary'); ?>
                </div>
                <h3 class="font-heading font-semibold text-xl mb-2 text-text group-hover:text-primary transition-colors">
                    <?php esc_html_e('Trajecten', 'stridence'); ?>
                </h3>
                <p class="text-text-muted text-sm mb-4">
                    <?php esc_html_e('Volg een leertraject met meerdere cursussen en begeleiding', 'stridence'); ?>
                </p>
                <span class="text-sm font-medium text-primary">
                    <?php printf(esc_html(_n('%d traject', '%d trajecten', $trajectory_total, 'stridence')), $trajectory_total); ?>
                </span>
            </a>

            <a href="<?php echo esc_url(home_url('/klassikaal/')); ?>" class="card p-10 text-center group cursor-pointer">
                <div class="w-16 h-16 mx-auto mb-5 rounded-full bg-secondary-container flex items-center justify-center group-hover:bg-primary/20 transition-colors">
                    <?php echo stridence_icon('users', 'w-7 h-7 text-primary'); ?>
                </div>
                <h3 class="font-heading font-semibold text-xl mb-2 text-text group-hover:text-primary transition-colors">
                    <?php esc_html_e('Klassikaal', 'stridence'); ?>
                </h3>
                <p class="text-text-muted text-sm mb-4">
                    <?php esc_html_e('Leer samen met anderen onder begeleiding van ervaren docenten', 'stridence'); ?>
                </p>
                <span class="text-sm font-medium text-primary">
                    <?php printf(esc_html(_n('%d editie', '%d edities', $edition_total, 'stridence')), $edition_total); ?>
                </span>
            </a>

            <a href="<?php echo esc_url(home_url('/online/')); ?>" class="card p-10 text-center group cursor-pointer">
                <div class="w-16 h-16 mx-auto mb-5 rounded-full bg-success/10 flex items-center justify-center group-hover:bg-success/20 transition-colors">
                    <?php echo stridence_icon('monitor', 'w-7 h-7 text-success'); ?>
                </div>
                <h3 class="font-heading font-semibold text-xl mb-2 text-text group-hover:text-success transition-colors">
                    <?php esc_html_e('Online', 'stridence'); ?>
                </h3>
                <p class="text-text-muted text-sm mb-4">
                    <?php esc_html_e('Leer op je eigen tempo met e-learning en webinars', 'stridence'); ?>
                </p>
                <span class="text-sm font-medium text-success">
                    <?php printf(esc_html(_n('%d cursus', '%d cursussen', $online_total, 'stridence')), $online_total); ?>
                </span>
            </a>
        </div>
    </div>
</section>

<!-- Featured Courses -->
<?php
$courses = get_posts([
    'post_type'      => 'sfwd-courses',
    'posts_per_page' => 6,
    'orderby'        => 'date',
    'order'          => 'DESC',
]);

if (!empty($courses)) :
?>
<section class="section bg-surface-alt rounded-t-[48px]">
    <div class="container">
        <div class="flex items-end justify-between mb-12">
            <div>
                <h2 class="font-serif text-4xl mb-2"><?php esc_html_e('Binnenkort gepland', 'stridence'); ?></h2>
                <p class="text-text-muted text-lg"><?php esc_html_e('Onze cursussen worden samengesteld door ervaren professionals uit de zorgsector.', 'stridence'); ?></p>
            </div>
            <a href="<?php echo esc_url(home_url('/klassikaal/')); ?>" class="btn-ghost hidden md:inline-flex">
                <?php esc_html_e('Alle edities', 'stridence'); ?> &rarr;
            </a>
        </div>

        <div class="grid gap-6 md:grid-cols-2 lg:grid-cols-3">
            <?php foreach ($courses as $course) : ?>
                <article class="card overflow-hidden flex flex-col">
                    <?php if (has_post_thumbnail($course)) : ?>
                        <a href="<?php echo esc_url(get_permalink($course)); ?>" class="block aspect-video overflow-hidden">
                            <?php echo get_the_post_thumbnail($course, 'stride_course_card', ['class' => 'w-full h-full object-cover']); ?>
                        </a>
                    <?php endif; ?>
                    <div class="p-6 flex-1 flex flex-col">
                        <h3 class="font-heading font-semibold text-lg mb-2 line-clamp-2">
                            <a href="<?php echo esc_url(get_permalink($course)); ?>" class="text-text hover:text-primary">
                                <?php echo esc_html($course->post_title); ?>
                            </a>
                        </h3>
                        <p class="text-sm text-text-muted line-clamp-2 mb-5 flex-1">
                            <?php echo esc_html(wp_trim_words($course->post_excerpt ?: $course->post_content, 20)); ?>
                        </p>
                        <a href="<?php echo esc_url(get_permalink($course)); ?>" class="btn-primary w-full text-center">
                            <?php esc_html_e('Meer info', 'stridence'); ?>
                        </a>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- Mission Section -->
<section class="py-24 lg:py-32 bg-surface-alt relative overflow-hidden">
    <div class="blur-blob absolute -bottom-24 left-1/2 -translate-x-1/2 w-3/4 h-3/4 bg-primary-light/20 rounded-full"></div>
    <div class="container relative z-10">
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-16 lg:gap-24 items-center">
            <div>
                <div class="w-20 h-1 bg-primary mb-8"></div>
                <h2 class="font-serif text-4xl lg:text-5xl italic leading-tight mb-8">
                    <?php esc_html_e('Kwaliteitsvolle nascholing voor de volgende generatie zorgverleners.', 'stridence'); ?>
                </h2>
                <div class="space-y-6 text-lg text-text-muted leading-relaxed">
                    <p><?php esc_html_e('Wij geloven dat professionele groei in de zorgsector niet beperkt mag blijven tot verplichte bijscholing. Onze opleidingen combineren wetenschappelijke onderbouwing met praktijkervaring.', 'stridence'); ?></p>
                    <p><?php esc_html_e('Als onafhankelijk opleidingscentrum garanderen wij dat elke zorgprofessional toegang heeft tot de tools, begeleiding en erkenning die nodig zijn om het verschil te maken.', 'stridence'); ?></p>
                </div>
            </div>
            <div class="relative">
                <div class="aspect-[4/5] bg-surface-container-highest rounded-xl overflow-hidden transform rotate-2 flex items-center justify-center">
                    <?php echo stridence_icon('heart', 'w-20 h-20 text-text-muted/30'); ?>
                </div>
                <div class="absolute -bottom-8 -left-8 bg-surface-card p-7 rounded-xl shadow-sm max-w-xs transform -rotate-3">
                    <p class="font-serif italic text-xl text-primary leading-snug">
                        <?php esc_html_e('"Zorg voor anderen begint met investeren in jezelf."', 'stridence'); ?>
                    </p>
                    <p class="mt-3 text-[11px] font-label font-bold uppercase tracking-widest text-text-muted">
                        — Dr. Els Van den Broeck
                    </p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Testimonial -->
<section class="py-24 lg:py-32 px-6 text-center max-w-4xl mx-auto">
    <svg class="w-14 h-14 mx-auto mb-8 text-primary-light" fill="currentColor" viewBox="0 0 24 24"><path d="M14.017 21v-7.391c0-5.704 3.731-9.57 8.983-10.609l.995 2.151c-2.432.917-3.995 3.638-3.995 5.849h4v10H14.017zM0 21v-7.391c0-5.704 3.731-9.57 8.983-10.609l.995 2.151C7.546 6.068 5.983 8.789 5.983 11H10v10H0z"/></svg>
    <blockquote class="font-serif text-3xl lg:text-4xl font-light leading-snug mb-12">
        <?php echo wp_kses(
            __('"De opleiding palliatieve zorg heeft mijn hele aanpak veranderd. Ik voelde me voor het eerst echt <em class="italic text-primary">voorbereid</em> op de moeilijkste gesprekken met families."', 'stridence'),
            ['em' => ['class' => []]]
        ); ?>
    </blockquote>
    <div class="flex flex-col items-center">
        <div class="w-14 h-14 rounded-full bg-surface-container-highest mb-3 flex items-center justify-center">
            <?php echo stridence_icon('user', 'w-6 h-6 text-text-muted'); ?>
        </div>
        <p class="font-bold"><?php esc_html_e('Sarah Janssens', 'stridence'); ?></p>
        <p class="text-text-muted text-sm"><?php esc_html_e('Verpleegkundige & Alumna 2024', 'stridence'); ?></p>
    </div>
</section>

<!-- CTA Section -->
<section class="py-24 lg:py-32 px-6">
    <div class="max-w-2xl mx-auto text-center">
        <h2 class="font-serif text-5xl lg:text-6xl font-light mb-8"><?php esc_html_e('Klaar om te starten?', 'stridence'); ?></h2>
        <p class="text-lg text-text-muted mb-10 leading-relaxed">
            <?php esc_html_e('Ontdek ons aanbod en schrijf je vandaag nog in. Versterk je vaardigheden met opleidingen die ertoe doen.', 'stridence'); ?>
        </p>
        <div class="flex flex-col sm:flex-row justify-center gap-4">
            <a href="<?php echo esc_url(home_url('/klassikaal/')); ?>" class="btn-primary btn-lg shadow-lg">
                <?php esc_html_e('Bekijk alle opleidingen', 'stridence'); ?>
            </a>
            <a href="<?php echo esc_url(home_url('/contact/')); ?>" class="btn-secondary btn-lg bg-surface-container-high">
                <?php esc_html_e('Neem contact op', 'stridence'); ?>
            </a>
        </div>
        <p class="mt-10 font-serif italic text-text-muted/60 text-lg">
            <?php esc_html_e('Een oase van groei voor zorgprofessionals.', 'stridence'); ?>
        </p>
    </div>
</section>

<?php
get_footer();
