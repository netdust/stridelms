<?php
/**
 * Homepage Template — Safe & Sound
 *
 * Asymmetric festival-flyer composition. Hot magenta + acid lime on warm cream,
 * deep ink anchors. Sticker hovers, mono labels, one italic word per hero.
 *
 * @package stride-client-safeandsound
 */

get_header();

// ── Data queries ──
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

<!-- ═══════════════════════════════════════════ HERO — Asymmetric flyer -->
<section class="relative overflow-hidden bg-surface"
         style="padding: clamp(5rem,10vw,9rem) clamp(1.5rem,5vw,5rem);">

    <!-- Decorative lime burst (riso sticker) -->
    <div aria-hidden="true" class="absolute" style="top: 12%; right: 6%; width: 240px; height: 240px;">
        <svg viewBox="0 0 240 240" class="w-full h-full" style="transform: rotate(-12deg);">
            <circle cx="120" cy="120" r="110" fill="rgb(var(--color-accent))"/>
            <text x="120" y="100" text-anchor="middle" font-family="var(--font-label)"
                  font-size="14" font-weight="600" letter-spacing="0.12em"
                  fill="rgb(var(--color-primary-dark))">EST. 2024</text>
            <text x="120" y="135" text-anchor="middle" font-family="var(--font-heading)"
                  font-size="36" font-weight="800" letter-spacing="-0.02em"
                  fill="rgb(var(--color-primary-dark))">LOOK</text>
            <text x="120" y="170" text-anchor="middle" font-family="var(--font-heading)"
                  font-size="36" font-weight="800" letter-spacing="-0.02em"
                  fill="rgb(var(--color-primary-dark))">OUT.</text>
        </svg>
    </div>

    <div class="container relative z-10">
        <div class="max-w-4xl">
            <!-- Mono eyebrow -->
            <div class="label-mono mb-8 flex items-center gap-3" style="color: rgb(var(--color-primary));">
                <span style="width: 32px; height: 2px; background: rgb(var(--color-primary)); display: inline-block;"></span>
                <?php esc_html_e('FESTIVAL · CONCERT · NIGHTLIFE PREVENTION', 'stridence'); ?>
            </div>

            <h1 class="font-heading mb-8 text-text"
                style="font-size: clamp(52px, 8.5vw, 112px); line-height: 0.95; letter-spacing: -0.035em; font-weight: 800;">
                <?php echo wp_kses(
                    __('Look out<br>for <em class="font-serif italic font-normal" style="color: rgb(var(--color-primary));">each other.</em><br>Loud out.', 'stridence'),
                    ['em' => ['class' => [], 'style' => []], 'br' => []],
                ); ?>
            </h1>

            <p class="text-text-muted mb-10 max-w-xl" style="font-size: clamp(17px, 1.6vw, 20px); line-height: 1.55;">
                <?php esc_html_e('Safe & Sound runs 90-minute workshops for teenagers heading into festival and concert season. No lectures. A plan.', 'stridence'); ?>
            </p>

            <div class="flex flex-wrap gap-4">
                <a href="<?php echo esc_url(home_url('/klassikaal/')); ?>" class="btn-primary btn-lg inline-flex items-center gap-2">
                    <?php esc_html_e('Book a workshop', 'stridence'); ?>
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 15 15"><path d="M2.5 7.5h10M9 4l3.5 3.5L9 11" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </a>
                <a href="<?php echo esc_url(home_url('/over-ons/')); ?>" class="btn-outline-dark btn-lg">
                    <?php esc_html_e('How it works', 'stridence'); ?>
                </a>
            </div>

            <!-- Wristband-style strip -->
            <div class="mt-16 inline-flex items-center gap-6 px-5 py-3 rounded-full"
                 style="background: rgb(var(--color-primary-dark)); color: rgb(var(--color-text-inverse));">
                <span class="label-mono" style="color: rgb(var(--color-accent));">NEXT SESSION</span>
                <span class="label-mono"><?php esc_html_e('SAT 31 MAY · ANTWERPEN · 14:00', 'stridence'); ?></span>
                <span class="label-mono px-3 py-1 rounded-full" style="background: rgb(var(--color-accent)); color: rgb(var(--color-primary-dark));">
                    <?php esc_html_e('FREE', 'stridence'); ?>
                </span>
            </div>
        </div>
    </div>
</section>

<!-- ═══════════════════════════════════════════ WHAT WE DO — 3-up bento -->
<section class="bg-surface" style="padding: clamp(4rem,8vw,7rem) clamp(1.5rem,5vw,5rem);" data-reveal>
    <div class="container">
        <!-- Section header -->
        <div class="flex items-end justify-between mb-12 flex-wrap gap-6">
            <div class="max-w-2xl">
                <div class="label-mono mb-4" style="color: rgb(var(--color-text-muted));">01 / WHAT WE DO</div>
                <h2 class="font-heading text-text" style="font-size: clamp(34px, 5vw, 56px); line-height: 1; letter-spacing: -0.03em; font-weight: 700;">
                    <?php echo wp_kses(
                        __('Three workshops.<br>One <em class="font-serif italic font-normal" style="color: rgb(var(--color-primary));">very loud</em> season ahead.', 'stridence'),
                        ['em' => ['class' => [], 'style' => []], 'br' => []],
                    ); ?>
                </h2>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-12 gap-6">
            <!-- Card 1: Mate Watch -->
            <article class="md:col-span-5 p-10 rounded-[20px] flex flex-col gap-6 card-interactive"
                     style="background: rgb(var(--color-primary)); color: rgb(var(--color-text-inverse)); border: 2px solid rgb(var(--color-primary-dark));">
                <div class="label-mono" style="color: rgb(var(--color-accent));">01 · 90 MIN</div>
                <h3 class="font-heading" style="font-size: clamp(28px, 3.2vw, 40px); line-height: 1.05; letter-spacing: -0.02em; font-weight: 700;">
                    <?php esc_html_e('Mate Watch', 'stridence'); ?>
                </h3>
                <p style="font-size: 16px; line-height: 1.55; opacity: 0.92;">
                    <?php esc_html_e('How to spot when your friend is in trouble. What to say. What to do. What never to do. Run through three real scenarios — not slides.', 'stridence'); ?>
                </p>
                <div class="mt-auto flex items-center gap-2 label-mono">
                    <?php esc_html_e('AGES 14–22', 'stridence'); ?>
                    <span style="opacity: 0.6;">·</span>
                    <?php esc_html_e('GROUPS OF 8–30', 'stridence'); ?>
                </div>
            </article>

            <!-- Card 2: Substance Smarts -->
            <article class="md:col-span-7 p-10 rounded-[20px] flex flex-col gap-6 card-interactive"
                     style="background: rgb(var(--color-surface-card)); border: 2px solid rgb(var(--color-primary-dark));">
                <div class="flex items-start justify-between gap-6">
                    <div class="flex flex-col gap-6 flex-1">
                        <div class="label-mono" style="color: rgb(var(--color-text-muted));">02 · 90 MIN</div>
                        <h3 class="font-heading text-text" style="font-size: clamp(28px, 3.2vw, 40px); line-height: 1.05; letter-spacing: -0.02em; font-weight: 700;">
                            <?php esc_html_e('Substance Smarts', 'stridence'); ?>
                        </h3>
                        <p class="text-text-muted" style="font-size: 16px; line-height: 1.55;">
                            <?php esc_html_e('We don\'t tell you not to drink. We tell you what naloxone is, where the harm-reduction tent lives, and how to talk to security without it going sideways.', 'stridence'); ?>
                        </p>
                    </div>
                    <!-- Lime sticker -->
                    <div class="hidden md:flex shrink-0 w-20 h-20 rounded-full items-center justify-center"
                         style="background: rgb(var(--color-accent)); border: 2px solid rgb(var(--color-primary-dark)); transform: rotate(8deg);">
                        <span class="label-mono" style="font-size: 11px; color: rgb(var(--color-primary-dark)); text-align: center; line-height: 1.1;">REAL<br>TALK</span>
                    </div>
                </div>
                <div class="mt-auto flex items-center gap-2 label-mono" style="color: rgb(var(--color-text-muted));">
                    <?php esc_html_e('AGES 16+', 'stridence'); ?>
                    <span>·</span>
                    <?php esc_html_e('GROUPS OF 8–30', 'stridence'); ?>
                </div>
            </article>

            <!-- Card 3: Peer Educator -->
            <article class="md:col-span-7 p-10 rounded-[20px] flex flex-col gap-6 card-interactive"
                     style="background: rgb(var(--color-primary-dark)); color: rgb(var(--color-text-inverse));">
                <div class="label-mono" style="color: rgb(var(--color-accent));">03 · 2 DAYS</div>
                <h3 class="font-heading" style="font-size: clamp(28px, 3.2vw, 40px); line-height: 1.05; letter-spacing: -0.02em; font-weight: 700; color: rgb(var(--color-text-inverse));">
                    <?php esc_html_e('Peer Educator Track', 'stridence'); ?>
                </h3>
                <p style="font-size: 16px; line-height: 1.55; opacity: 0.78;">
                    <?php esc_html_e('Half the people in our workshops end up running the next one. Two-day intensive: facilitation, scenario design, looking after yourself while looking out for others.', 'stridence'); ?>
                </p>
                <div class="mt-auto flex items-center gap-2 label-mono" style="opacity: 0.6;">
                    <?php esc_html_e('AGES 17+', 'stridence'); ?>
                    <span>·</span>
                    <?php esc_html_e('APPLICATION ONLY', 'stridence'); ?>
                </div>
            </article>

            <!-- Card 4: Stat -->
            <article class="md:col-span-5 p-10 rounded-[20px] flex flex-col justify-between"
                     style="background: rgb(var(--color-accent)); color: rgb(var(--color-primary-dark)); border: 2px solid rgb(var(--color-primary-dark));">
                <div class="label-mono" style="opacity: 0.7;"><?php esc_html_e('SINCE 2024', 'stridence'); ?></div>
                <div>
                    <div class="font-heading" style="font-size: clamp(64px, 9vw, 112px); line-height: 0.85; letter-spacing: -0.05em; font-weight: 800;">
                        4,200
                    </div>
                    <div class="font-heading mt-2" style="font-size: 22px; line-height: 1.1; font-weight: 700;">
                        <?php esc_html_e('teenagers walked into a festival with a plan.', 'stridence'); ?>
                    </div>
                </div>
            </article>
        </div>
    </div>
</section>

<!-- ═══════════════════════════════════════════ QUOTE BLOCK — peer voice -->
<section style="background: rgb(var(--color-surface-alt)); padding: clamp(4rem,8vw,7rem) clamp(1.5rem,5vw,5rem);" data-reveal>
    <div class="container max-w-4xl">
        <div class="label-mono mb-8" style="color: rgb(var(--color-primary));">VOICE FROM THE BACK ROW</div>
        <blockquote class="font-heading text-text" style="font-size: clamp(32px, 5vw, 56px); line-height: 1.05; letter-spacing: -0.025em; font-weight: 700;">
            <?php echo wp_kses(
                __('&ldquo;I thought it was going to be another <em class="font-serif italic font-normal" style="color: rgb(var(--color-primary));">don\'t-do-drugs</em> assembly. It wasn\'t. I left knowing what I\'d actually do if my friend went under.&rdquo;', 'stridence'),
                ['em' => ['class' => [], 'style' => []]],
            ); ?>
        </blockquote>
        <div class="flex items-center gap-4 mt-10">
            <div class="w-12 h-12 rounded-full flex items-center justify-center font-heading font-bold"
                 style="background: rgb(var(--color-primary)); color: rgb(var(--color-text-inverse));">L</div>
            <div>
                <div class="text-text font-semibold">Lina, 17</div>
                <div class="label-mono" style="color: rgb(var(--color-text-muted));"><?php esc_html_e('MATE WATCH · GHENT · APR 2025', 'stridence'); ?></div>
            </div>
        </div>
    </div>
</section>

<!-- ═══════════════════════════════════════════ UPCOMING SESSIONS -->
<?php if (!empty($courses)) : ?>
<section class="bg-surface" style="padding: clamp(4rem,8vw,7rem) clamp(1.5rem,5vw,5rem);" data-reveal>
    <div class="container">
        <div class="flex items-end justify-between mb-12 flex-wrap gap-6">
            <div class="max-w-2xl">
                <div class="label-mono mb-4" style="color: rgb(var(--color-text-muted));">02 / UPCOMING SESSIONS</div>
                <h2 class="font-heading text-text" style="font-size: clamp(34px, 5vw, 56px); line-height: 1; letter-spacing: -0.03em; font-weight: 700;">
                    <?php esc_html_e('Pick a night. Get on the list.', 'stridence'); ?>
                </h2>
            </div>
            <a href="<?php echo esc_url(home_url('/klassikaal/')); ?>" class="btn-ghost inline-flex items-center gap-2">
                <?php esc_html_e('Full programme', 'stridence'); ?> →
            </a>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-6" data-stagger>
            <?php foreach (array_slice($courses, 0, 3) as $i => $course) :
                $format_terms = wp_get_post_terms($course->ID, 'stride_format', ['fields' => 'slugs']);
                $format_slug = !empty($format_terms) ? $format_terms[0] : 'klassikaal';
                $format_label = match ($format_slug) {
                    'online', 'e-learning' => 'E-LEARNING',
                    'webinar' => 'WEBINAR',
                    default => 'IN-PERSON',
                };
                // Alternate sticker colour for visual rhythm
                $sticker = $i % 2 === 0 ? 'accent' : 'primary';
                ?>
                <a href="<?php echo esc_url(get_permalink($course)); ?>"
                   class="card-interactive p-7 rounded-[20px] flex flex-col gap-5 block"
                   style="background: rgb(var(--color-surface-card)); border: 2px solid rgb(var(--color-primary-dark));">
                    <div class="flex items-start justify-between">
                        <div class="label-mono px-3 py-1 rounded-full"
                             style="background: rgb(var(--color-<?php echo esc_attr($sticker); ?>)); color: rgb(var(--color-<?php echo $sticker === 'accent' ? 'primary-dark' : 'text-inverse'; ?>));">
                            <?php echo esc_html($format_label); ?>
                        </div>
                        <div class="label-mono" style="color: rgb(var(--color-text-muted));">90 MIN</div>
                    </div>

                    <h4 class="font-heading text-text" style="font-size: 24px; line-height: 1.1; letter-spacing: -0.02em; font-weight: 700;">
                        <?php echo esc_html($course->post_title); ?>
                    </h4>

                    <p class="text-text-muted" style="font-size: 14.5px; line-height: 1.55;">
                        <?php echo esc_html(wp_trim_words($course->post_excerpt ?: $course->post_content, 22)); ?>
                    </p>

                    <div class="mt-auto pt-4 flex items-center justify-between" style="border-top: 1px dashed rgb(var(--color-border));">
                        <span class="label-mono" style="color: rgb(var(--color-text-muted));"><?php esc_html_e('GET ON THE LIST', 'stridence'); ?></span>
                        <span style="color: rgb(var(--color-primary)); font-size: 20px;">→</span>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- ═══════════════════════════════════════════ HOW IT WORKS — process strip -->
<section class="dark-section" style="padding: clamp(4rem,8vw,7rem) clamp(1.5rem,5vw,5rem);" data-reveal>
    <div class="container">
        <div class="max-w-3xl mb-16">
            <div class="label-mono mb-4" style="color: rgb(var(--color-accent));">03 / HOW A WORKSHOP RUNS</div>
            <h2 class="font-heading" style="font-size: clamp(34px, 5vw, 56px); line-height: 1; letter-spacing: -0.03em; font-weight: 700; color: rgb(var(--color-text-inverse));">
                <?php echo wp_kses(
                    __('90 minutes.<br>Three <em class="font-serif italic font-normal" style="color: rgb(var(--color-accent));">scenarios.</em><br>Zero slides.', 'stridence'),
                    ['em' => ['class' => [], 'style' => []], 'br' => []],
                ); ?>
            </h2>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-x-8 gap-y-12">
            <?php
            $steps = [
                ['0–20', 'Set the room', 'We swap names, set what stays in the room, and run through what tonight is — and what it is not.'],
                ['20–70', 'Three scenarios', 'Real situations. A friend who\'s too far gone. A first kiss that wasn\'t a kiss. A stranger at the back of the queue. You decide what you\'d do.'],
                ['70–90', 'Walk-away kit', 'You leave with three numbers, a card for your wallet, and one promise. That\'s the workshop.'],
            ];
foreach ($steps as $idx => $step) : ?>
                <div>
                    <div class="font-heading mb-4" style="font-size: 88px; line-height: 0.85; letter-spacing: -0.05em; font-weight: 800; color: rgb(var(--color-accent));">
                        <?php echo (string) ($idx + 1); ?>
                    </div>
                    <div class="label-mono mb-3" style="color: rgb(var(--color-accent)); opacity: 0.7;">
                        <?php echo esc_html($step[0]); ?> MIN
                    </div>
                    <h3 class="font-heading mb-3" style="font-size: 24px; line-height: 1.1; letter-spacing: -0.02em; font-weight: 700; color: rgb(var(--color-text-inverse));">
                        <?php echo esc_html($step[1]); ?>
                    </h3>
                    <p style="font-size: 15px; line-height: 1.55; color: rgb(255 255 255 / 0.65);">
                        <?php echo esc_html($step[2]); ?>
                    </p>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- ═══════════════════════════════════════════ CTA -->
<section class="text-center" style="background: rgb(var(--color-accent)); padding: clamp(4rem,8vw,7rem) clamp(1.5rem,5vw,5rem);" data-reveal>
    <div class="max-w-3xl mx-auto">
        <div class="label-mono mb-6" style="color: rgb(var(--color-primary-dark));"><?php esc_html_e('GET IN TOUCH', 'stridence'); ?></div>
        <h2 class="font-heading mb-8" style="font-size: clamp(48px, 8vw, 96px); line-height: 0.95; letter-spacing: -0.035em; font-weight: 800; color: rgb(var(--color-primary-dark));">
            <?php echo wp_kses(
                __('Your venue,<br>your school,<br><em class="font-serif italic font-normal">your festival.</em>', 'stridence'),
                ['em' => ['class' => [], 'style' => []], 'br' => []],
            ); ?>
        </h2>
        <p class="mb-10 max-w-xl mx-auto" style="font-size: 18px; line-height: 1.5; color: rgb(var(--color-primary-dark)); opacity: 0.78;">
            <?php esc_html_e('We come to you. Schools, youth clubs, festival pre-events. 90 minutes, 8 to 30 teenagers, no cost where we can manage it.', 'stridence'); ?>
        </p>
        <div class="flex flex-wrap gap-4 justify-center">
            <a href="<?php echo esc_url(home_url('/contact/')); ?>" class="btn-primary btn-lg inline-flex items-center gap-2">
                <?php esc_html_e('Bring it to us', 'stridence'); ?>
                <svg class="w-4 h-4" fill="none" viewBox="0 0 15 15"><path d="M2.5 7.5h10M9 4l3.5 3.5L9 11" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </a>
            <a href="<?php echo esc_url(home_url('/klassikaal/')); ?>" class="btn-outline-dark btn-lg">
                <?php esc_html_e('See open sessions', 'stridence'); ?>
            </a>
        </div>
    </div>
</section>

<?php
get_footer();
