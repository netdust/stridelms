<?php
/**
 * Homepage Template — CareCommunity Editorial
 *
 * Compassionate editorial design with tonal layering, asymmetric layouts,
 * and Noto Serif + Inter typography. Adapted from Stitch "Our Services" screen.
 *
 * @package stride-client-carecommunity
 */

get_header();

// ── Data queries ──
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

$courses = get_posts([
    'post_type'      => 'sfwd-courses',
    'posts_per_page' => 6,
    'orderby'        => 'date',
    'order'          => 'DESC',
]);
?>

<!-- ═══════════════════════════════════════════ HERO (Editorial Serif) -->
<section class="relative overflow-hidden bg-surface"
         style="padding: clamp(5rem,10vw,9rem) clamp(1.5rem,5vw,5rem);">
    <!-- Background image -->
    <div class="absolute inset-0">
        <img class="w-full h-full object-cover"
             src="<?php echo esc_url(get_theme_file_uri('assets/images/hero-bg.jpg')); ?>"
             alt="" aria-hidden="true"
             style="opacity: 0.12;">
    </div>
    <div class="container relative z-10">
        <div class="max-w-3xl">
            <h1 class="font-serif font-bold text-text leading-tight tracking-tight mb-8"
                style="font-size: clamp(48px, 7vw, 88px); letter-spacing: -0.03em;">
                <?php echo wp_kses(
                    __('Compassionate <em class="italic font-normal" style="color: rgb(var(--color-primary));">Care</em> for Every Journey.', 'stridence'),
                    ['em' => ['class' => [], 'style' => []]],
                ); ?>
            </h1>

            <p class="text-text-muted leading-relaxed max-w-2xl mb-10" style="font-size: clamp(16px, 1.8vw, 19px);">
                <?php esc_html_e('We provide more than medical support. We offer a sanctuary for healing, a community for sharing, and a legacy of dignity for every individual we serve.', 'stridence'); ?>
            </p>

            <div class="flex flex-wrap gap-3">
                <a href="<?php echo esc_url(home_url('/klassikaal/')); ?>" class="btn-primary btn-lg group">
                    <?php esc_html_e('Explore Services', 'stridence'); ?>
                    <svg class="w-4 h-4 transition-transform group-hover:translate-x-1" fill="none" viewBox="0 0 15 15"><path d="M2.5 7.5h10M9 4l3.5 3.5L9 11" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </a>
                <a href="<?php echo esc_url(home_url('/over-ons/')); ?>" class="btn-outline-dark btn-lg">
                    <?php esc_html_e('About Us', 'stridence'); ?>
                </a>
            </div>
        </div>
    </div>
</section>

<!-- ═══════════════════════════════════════════ INPATIENT CENTER (Bento Grid) -->
<section class="bg-surface" style="padding: clamp(4rem,8vw,7rem) clamp(1.5rem,5vw,5rem);" data-reveal>
    <div class="container">
        <!-- Section label -->
        <div class="flex items-center gap-4 mb-8">
            <span class="h-px w-12" style="background: rgb(var(--color-border));"></span>
            <span class="text-xs font-bold uppercase tracking-widest text-text-muted">01. Comprehensive Care</span>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-12 gap-6">
            <!-- Large image card -->
            <div class="md:col-span-8 rounded-xl overflow-hidden group relative min-h-[400px]"
                 style="background: rgb(var(--color-surface-container));">
                <?php
                $hero_image = get_the_post_thumbnail_url(get_option('page_on_front'), 'large');
if (!$hero_image) {
    $hero_image = get_theme_file_uri('assets/images/hero-inpatient.jpg');
}
?>
                    <img class="absolute inset-0 w-full h-full object-cover transition-transform group-hover:scale-105"
                         style="transition-duration: 700ms;"
                         src="<?php echo esc_url($hero_image); ?>"
                         alt="<?php esc_attr_e('Inpatient Center', 'stridence'); ?>">
                <div class="absolute inset-0" style="background: linear-gradient(to top, rgba(0,0,0,0.8) 0%, rgba(0,0,0,0.55) 50%, rgba(0,0,0,0.35) 100%);"></div>
                <div class="absolute bottom-0 left-0 p-8 text-white">
                    <h2 class="text-3xl font-serif font-bold mb-2">
                        <?php esc_html_e('Inpatient Center', 'stridence'); ?>
                    </h2>
                    <p class="text-sm max-w-md" style="opacity: 0.9;">
                        <?php esc_html_e('Our specialized facility provides 24/7 medical supervision in an environment that feels like home.', 'stridence'); ?>
                    </p>
                </div>
            </div>

            <!-- Info card (primary colored) -->
            <div class="md:col-span-4 rounded-xl p-8 flex flex-col justify-between"
                 style="background: rgb(var(--color-primary)); color: rgb(var(--color-text-inverse));">
                <svg class="w-10 h-10 mb-8" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12l8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25"/>
                </svg>
                <div>
                    <h3 class="text-xl font-serif mb-4">
                        <?php esc_html_e('Round-the-clock Monitoring', 'stridence'); ?>
                    </h3>
                    <p class="text-sm leading-relaxed" style="opacity: 0.85;">
                        <?php esc_html_e('Full medical staff coverage including pain management specialists and licensed caregivers.', 'stridence'); ?>
                    </p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ═══════════════════════════════════════════ GRIEF SUPPORT (Asymmetric Overlap) -->
<section class="relative bg-surface" style="padding: clamp(4rem,8vw,7rem) clamp(1.5rem,5vw,5rem);" data-reveal>
    <div class="container">
        <!-- Section label -->
        <div class="flex items-center gap-4 mb-12">
            <span class="h-px w-12" style="background: rgb(var(--color-border));"></span>
            <span class="text-xs font-bold uppercase tracking-widest text-text-muted">02. Emotional Wellness</span>
        </div>

        <div class="flex flex-col md:flex-row items-center gap-12">
            <!-- Text card (overlapping) -->
            <div class="w-full md:w-1/2 relative z-10">
                <div class="bg-surface-card p-10 md:p-16 rounded-xl" style="box-shadow: var(--shadow-sm);">
                    <h2 class="font-serif font-bold mb-6" style="font-size: clamp(28px, 3.5vw, 40px); color: rgb(var(--color-primary));">
                        <?php esc_html_e('Grief & Bereavement Support', 'stridence'); ?>
                    </h2>
                    <p class="text-text-muted leading-relaxed mb-8">
                        <?php esc_html_e('Healing is not a destination, but a process. Our certified counselors provide individual and group sessions for those navigating the complexities of loss.', 'stridence'); ?>
                    </p>
                    <ul class="space-y-4 mb-10">
                        <li class="flex items-start gap-3">
                            <svg class="w-5 h-5 shrink-0 mt-0.5" style="color: rgb(var(--color-primary-hover));" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z" clip-rule="evenodd"/></svg>
                            <span class="text-sm font-medium"><?php esc_html_e('One-on-one professional counseling', 'stridence'); ?></span>
                        </li>
                        <li class="flex items-start gap-3">
                            <svg class="w-5 h-5 shrink-0 mt-0.5" style="color: rgb(var(--color-primary-hover));" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z" clip-rule="evenodd"/></svg>
                            <span class="text-sm font-medium"><?php esc_html_e('Monthly community support circles', 'stridence'); ?></span>
                        </li>
                        <li class="flex items-start gap-3">
                            <svg class="w-5 h-5 shrink-0 mt-0.5" style="color: rgb(var(--color-primary-hover));" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z" clip-rule="evenodd"/></svg>
                            <span class="text-sm font-medium"><?php esc_html_e('Specialized programs for children and teens', 'stridence'); ?></span>
                        </li>
                    </ul>
                    <a href="#" class="text-sm font-bold" style="color: rgb(var(--color-primary)); border-bottom: 1px solid rgb(var(--color-primary) / 0.2); padding-bottom: 2px;">
                        <?php esc_html_e('Download Resources', 'stridence'); ?>
                    </a>
                </div>
            </div>

            <!-- Overlapping image -->
            <div class="w-full md:w-1/2 md:-ml-24">
                <div class="rounded-xl overflow-hidden" style="box-shadow: var(--shadow-lg); height: 500px;">
                    <img class="w-full h-full object-cover"
                         src="<?php echo esc_url(get_theme_file_uri('assets/images/grief-support.jpg')); ?>"
                         alt="<?php esc_attr_e('Grief support', 'stridence'); ?>"
                         onerror="this.parentElement.style.background='rgb(var(--color-surface-container))'">
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ═══════════════════════════════════════════ FEATURED COURSES (Volunteer-style Card Grid) -->
<?php if (!empty($courses)) : ?>
<section class="rounded-none md:rounded-[40px] md:mx-6"
         style="background: rgb(var(--color-surface-alt)); padding: clamp(4rem,8vw,7rem) clamp(1.5rem,5vw,5rem);">
    <div class="container">
        <div class="max-w-3xl mb-16" data-reveal>
            <span class="text-xs font-bold uppercase tracking-widest mb-4 block" style="color: rgb(var(--color-primary));">
                03. <?php esc_html_e('Our Programs', 'stridence'); ?>
            </span>
            <h2 class="font-serif font-bold mb-6" style="font-size: clamp(32px, 4.5vw, 48px);">
                <?php esc_html_e('Upcoming Training', 'stridence'); ?>
            </h2>
            <p class="text-text-muted text-lg">
                <?php esc_html_e('Our programs are developed by experienced professionals with decades of hands-on expertise.', 'stridence'); ?>
            </p>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-8" data-stagger>
            <?php foreach (array_slice($courses, 0, 3) as $course) :
                $terms = wp_get_post_terms($course->ID, 'ld_course_category', ['fields' => 'names']);
                $format_terms = wp_get_post_terms($course->ID, 'stride_format', ['fields' => 'slugs']);
                $format_slug = !empty($format_terms) ? $format_terms[0] : 'klassikaal';
                $format_label = match ($format_slug) {
                    'online', 'e-learning' => 'E-learning',
                    'webinar' => 'Webinar',
                    default => 'In-person',
                };
                ?>
                <a href="<?php echo esc_url(get_permalink($course)); ?>"
                   class="bg-surface-card p-8 rounded-xl block transition-transform"
                   style="transition-duration: 500ms;"
                   onmouseenter="this.style.transform='translateY(-8px)'"
                   onmouseleave="this.style.transform=''">
                    <!-- Icon circle -->
                    <div class="w-12 h-12 rounded-full flex items-center justify-center mb-6"
                         style="background: rgb(var(--color-secondary-container) / 0.3); color: rgb(var(--color-primary));">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4.26 10.147a60.436 60.436 0 00-.491 6.347A48.627 48.627 0 0112 20.904a48.627 48.627 0 018.232-4.41 60.46 60.46 0 00-.491-6.347m-15.482 0a50.57 50.57 0 00-2.658-.813A59.905 59.905 0 0112 3.493a59.902 59.902 0 0110.399 5.84c-.896.248-1.783.52-2.658.814m-15.482 0A50.697 50.697 0 0112 13.489a50.702 50.702 0 017.74-3.342"/>
                        </svg>
                    </div>

                    <h4 class="text-xl font-serif font-bold mb-3"><?php echo esc_html($course->post_title); ?></h4>

                    <p class="text-sm text-text-muted leading-relaxed mb-4">
                        <?php echo esc_html(wp_trim_words($course->post_excerpt ?: $course->post_content, 20)); ?>
                    </p>

                    <span class="inline-block text-xs font-bold uppercase tracking-wider px-3 py-1 rounded-full"
                          style="background: rgb(var(--color-primary-subtle)); color: rgb(var(--color-primary-dark));">
                        <?php echo esc_html($format_label); ?>
                    </span>
                </a>
            <?php endforeach; ?>
        </div>

        <div class="mt-12 text-center">
            <a href="<?php echo esc_url(home_url('/klassikaal/')); ?>"
               class="btn-primary btn-lg inline-flex items-center gap-2">
                <?php esc_html_e('View All Programs', 'stridence'); ?>
                <svg class="w-4 h-4" fill="none" viewBox="0 0 15 15"><path d="M2.5 7.5h10M9 4l3.5 3.5L9 11" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </a>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- ═══════════════════════════════════════════ MISSION (Stats + Quote) -->
<section class="bg-surface" style="padding: clamp(4rem,8vw,7rem) clamp(1.5rem,5vw,5rem);">
    <div class="container">
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-12 lg:gap-20 items-center" data-reveal>

            <!-- Mission Text -->
            <div>
                <span class="text-xs font-bold uppercase tracking-widest text-text-muted mb-2 block">
                    <?php esc_html_e('Our Mission', 'stridence'); ?>
                </span>
                <h2 class="font-serif font-bold tracking-tight leading-tight mb-6"
                    style="font-size: clamp(26px, 3.2vw, 42px); letter-spacing: -0.02em;">
                    <?php esc_html_e('Every individual deserves dignity — and every family deserves support.', 'stridence'); ?>
                </h2>
                <p class="text-text-muted leading-relaxed mb-4" style="font-size: 15.5px;">
                    <?php esc_html_e('CareCommunity has been providing compassionate care since 2008 — through hospice services, grief counseling, and community programs that support families in their most difficult moments.', 'stridence'); ?>
                </p>
                <p class="text-text-muted leading-relaxed" style="font-size: 15.5px;">
                    <?php esc_html_e('From inpatient facilities and bereavement groups to volunteer programs and spiritual counseling: we walk alongside everyone who needs us. Evidence-based, always with heart.', 'stridence'); ?>
                </p>
            </div>

            <!-- Side cards -->
            <div class="flex flex-col gap-4">
                <!-- Stat box -->
                <div class="rounded-xl p-8 flex items-center gap-4"
                     style="background: rgb(var(--color-primary-subtle));">
                    <svg class="w-10 h-8 shrink-0" style="color: rgb(var(--color-primary));" viewBox="0 0 48 32" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="0,16 8,16 12,4 18,28 24,12 30,20 34,16 48,16"/>
                    </svg>
                    <div>
                        <strong class="block text-sm font-bold text-text mb-0.5"><?php esc_html_e('Founded 2008', 'stridence'); ?></strong>
                        <span class="text-sm text-text-muted">
                            <?php esc_html_e('Serving over 2,000 families annually. Located in the heart of our community.', 'stridence'); ?>
                        </span>
                    </div>
                </div>

                <!-- Quote card -->
                <div class="rounded-xl p-8" style="background: rgb(var(--color-primary-dark));">
                    <p class="font-serif font-bold italic text-text-inverse leading-snug mb-7"
                       style="font-size: clamp(18px, 2.2vw, 23px); letter-spacing: -0.02em;">
                        &ldquo;<?php esc_html_e('In the end, it\'s not the days in your life that count. It\'s the life in your days.', 'stridence'); ?>&rdquo;
                    </p>
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-full flex items-center justify-center font-serif font-bold text-base shrink-0"
                             style="background: rgb(var(--color-primary-light)); color: rgb(var(--color-primary-dark));">S</div>
                        <div>
                            <div class="text-sm font-bold text-text-inverse"><?php esc_html_e('Sarah Mitchell', 'stridence'); ?></div>
                            <div class="text-xs mt-0.5" style="color: rgb(255 255 255 / 0.4);"><?php esc_html_e('Hospice Director & Founder', 'stridence'); ?></div>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>
</section>

<!-- ═══════════════════════════════════════════ TESTIMONIAL -->
<section class="text-center" style="background: rgb(var(--color-primary-light)); padding: clamp(4rem,8vw,7rem) clamp(1.5rem,5vw,5rem);" data-reveal>
    <div class="max-w-3xl mx-auto">
        <span class="font-serif font-bold text-7xl leading-none block mb-4" style="color: rgb(var(--color-primary-dark) / 0.15);">&ldquo;</span>
        <p class="font-serif font-bold italic text-text leading-snug mb-8"
           style="font-size: clamp(22px, 3.2vw, 36px); letter-spacing: -0.02em;">
            <?php esc_html_e('The care team didn\'t just support my mother — they supported our entire family through the most challenging time of our lives. We are forever grateful.', 'stridence'); ?>
        </p>
        <div class="font-bold text-text"><?php esc_html_e('James & Elena Rodriguez', 'stridence'); ?></div>
        <div class="text-sm mt-1" style="color: rgb(var(--color-primary-dark) / 0.5);">
            <?php esc_html_e('Family members, 2025', 'stridence'); ?>
        </div>
    </div>
</section>

<!-- ═══════════════════════════════════════════ CTA -->
<section class="text-center" style="background: rgb(var(--color-primary-dark)); padding: clamp(4rem,8vw,7rem) clamp(1.5rem,5vw,5rem);" data-reveal>
    <div class="relative z-10">
        <span class="text-xs font-bold uppercase tracking-widest mb-4 block" style="color: rgb(var(--color-primary-light));">
            <?php esc_html_e('Get Involved', 'stridence'); ?>
        </span>
        <h2 class="font-serif font-bold text-text-inverse tracking-tight mb-4"
            style="font-size: clamp(38px, 5.5vw, 64px); letter-spacing: -0.03em; line-height: 1.05;">
            <?php echo wp_kses(
                __('Ready to make<br>a difference?', 'stridence'),
                ['br' => []],
            ); ?>
        </h2>
        <p class="text-base max-w-md mx-auto mb-10 leading-relaxed" style="color: rgb(255 255 255 / 0.45);">
            <?php esc_html_e('Whether you want to volunteer, donate, or learn more about our services — we\'d love to hear from you.', 'stridence'); ?>
        </p>
        <div class="flex flex-wrap gap-3 justify-center">
            <a href="<?php echo esc_url(home_url('/klassikaal/')); ?>" class="btn-primary btn-lg group"
               style="background: rgb(var(--color-primary-light)); color: rgb(var(--color-primary-dark));">
                <?php esc_html_e('Explore Programs', 'stridence'); ?>
                <svg class="w-4 h-4 transition-transform group-hover:translate-x-1" fill="none" viewBox="0 0 15 15"><path d="M2.5 7.5h10M9 4l3.5 3.5L9 11" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </a>
            <a href="<?php echo esc_url(home_url('/contact/')); ?>" class="btn-outline-light btn-lg">
                <?php esc_html_e('Contact Us', 'stridence'); ?>
            </a>
        </div>
        <p class="font-serif italic text-sm mt-12" style="color: rgb(255 255 255 / 0.2);">
            <?php esc_html_e('CareCommunity — care that dignifies.', 'stridence'); ?>
        </p>
    </div>
</section>

<?php
get_footer();
