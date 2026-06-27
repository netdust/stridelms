<?php
/**
 * Homepage Template — Kindred HR
 *
 * Editorial cover composition. Moss + stone, Geist + Instrument Serif italic accents.
 * Five-pillar grid, server-rendered editions, restrained closer.
 *
 * @package stride-client-kindred
 */

get_header();
?>

<!-- ═══════════════════════════════════════════ COVER -->
<section class="kindred-cover" style="
    max-width: var(--kindred-page-max);
    margin: 0 auto;
    padding: clamp(48px, 8vw, 110px) var(--kindred-gutter);
    border-bottom: 1px solid rgb(var(--color-border));
">
    <div class="kindred-cover__grid" style="
        display: grid;
        grid-template-columns: 130px 1fr auto;
        gap: 32px;
        align-items: end;
        margin-bottom: 64px;
    ">
        <span class="t-mono t-eyebrow"><?php esc_html_e('KINDRED HR', 'stridence'); ?></span>
        <h1 class="t-fraunces" style="
            font-weight: 400;
            font-size: clamp(44px, 7vw, 96px);
            line-height: 0.95;
            letter-spacing: -0.035em;
            margin: 0;
            max-width: 16ch;
            text-wrap: balance;
            color: rgb(var(--color-text));
        ">
            <?php echo wp_kses(
                __('Trainingen voor mensen die <em class="t-serif">werken met mensen.</em>', 'stridence'),
                ['em' => ['class' => []]],
            ); ?>
        </h1>
        <div class="t-mono" style="
            font-size: 11px;
            letter-spacing: 0.06em;
            color: rgb(var(--color-text-muted));
            text-align: right;
            line-height: 1.6;
        ">
            <?php esc_html_e('v2026.1', 'stridence'); ?><br>
            <?php esc_html_e('NL · BE', 'stridence'); ?>
        </div>
    </div>

    <div class="kindred-cover__intro" style="
        display: grid;
        grid-template-columns: 130px 1fr;
        gap: 32px;
        padding-top: 48px;
        border-top: 1px solid rgb(var(--color-border));
    ">
        <span class="t-mono t-eyebrow"><?php esc_html_e('01 / INTRO', 'stridence'); ?></span>
        <div>
            <p class="t-serif" style="
                font-size: clamp(24px, 2.2vw, 30px);
                line-height: 1.35;
                color: rgb(var(--color-text));
                margin: 0 0 16px;
                max-width: 38ch;
            ">
                <?php esc_html_e('Kindred helpt organisaties trainen, coachen en ontwikkelen — voor managers, teams en HR-professionals die met mensen werken.', 'stridence'); ?>
            </p>
            <p style="
                font-family: var(--font-sans);
                font-size: 17px;
                line-height: 1.55;
                color: rgb(var(--color-text-muted));
                margin: 0;
                max-width: 52ch;
            ">
                <?php esc_html_e('Geen losse workshops zonder context. Geen abstracte modellen. Praktische trainingen die in het werk landen, in tien hoofdstukken die jouw team al kent.', 'stridence'); ?>
            </p>
        </div>
    </div>
</section>

<!-- ═══════════════════════════════════════════ PILLARS -->
<section style="
    max-width: var(--kindred-page-max);
    margin: 0 auto;
    padding: clamp(48px, 8vw, 110px) var(--kindred-gutter);
">
    <div style="
        display: grid;
        grid-template-columns: 130px 1fr;
        gap: 32px;
        margin-bottom: 48px;
        align-items: baseline;
    ">
        <span class="t-mono t-eyebrow"><?php esc_html_e('02 / DOMEINEN', 'stridence'); ?></span>
        <h2 class="t-fraunces" style="
            font-weight: 500;
            font-size: clamp(28px, 3.5vw, 44px);
            letter-spacing: -0.02em;
            line-height: 1.05;
            margin: 0;
            max-width: 22ch;
        "><?php esc_html_e('Vijf domeinen waar onze trainingen verschil maken.', 'stridence'); ?></h2>
    </div>

    <div class="kindred-pillars" style="
        display: grid;
        grid-template-columns: repeat(5, 1fr);
        gap: 0;
        border-top: 1px solid rgb(var(--color-border));
    ">
        <?php
        $pillars = [
            ['01', __('Leiderschap', 'stridence'),  __('Coachend leiderschap, moeilijke gesprekken, situationeel sturen.', 'stridence')],
            ['02', __('Communicatie', 'stridence'), __('Feedback geven, conflictbemiddeling, verbindend overleggen.', 'stridence')],
            ['03', __('Welzijn', 'stridence'),      __('Veerkracht, werkdruk, stress-signalen herkennen.', 'stridence')],
            ['04', __('Coaching', 'stridence'),     __('Loopbaancoaching, intervisie, ontwikkelgesprekken.', 'stridence')],
            ['05', __('Compliance', 'stridence'),   __('GDPR, integriteit, grensoverschrijdend gedrag.', 'stridence')],
        ];
foreach ($pillars as [$num, $label, $copy]) :
    ?>
        <div style="
            padding: 32px 24px;
            border-right: 1px solid rgb(var(--color-border));
            min-height: 200px;
            display: flex;
            flex-direction: column;
            gap: 12px;
        ">
            <span class="t-mono" style="
                font-size: 11px;
                letter-spacing: 0.12em;
                color: rgb(var(--color-text-muted));
            "><?php echo esc_html($num); ?></span>
            <h3 style="
                font-family: var(--font-heading);
                font-weight: 500;
                font-size: 19px;
                line-height: 1.2;
                letter-spacing: -0.015em;
                margin: 0;
                color: rgb(var(--color-text));
            "><?php echo esc_html($label); ?></h3>
            <p style="
                font-family: var(--font-sans);
                font-size: 14px;
                line-height: 1.5;
                color: rgb(var(--color-text-muted));
                margin: auto 0 0;
            "><?php echo esc_html($copy); ?></p>
        </div>
        <?php endforeach; ?>
    </div>
</section>

<!-- ═══════════════════════════════════════════ UPCOMING EDITIONS -->
<?php
$editions = new WP_Query([
    'post_type'      => 'vad_edition',
    'post_status'    => 'publish',
    'posts_per_page' => 6,
    'meta_query'     => [
    [
        'key'     => '_ntdst_status',
        'value'   => ['draft', 'completed', 'archived'],
        'compare' => 'NOT IN',
    ],
    ],
]);
?>
<section style="
    background: rgb(var(--color-surface-alt));
    padding: clamp(48px, 8vw, 110px) 0;
">
    <div style="
        max-width: var(--kindred-page-max);
        margin: 0 auto;
        padding: 0 var(--kindred-gutter);
    ">
        <div style="
            display: grid;
            grid-template-columns: 130px 1fr;
            gap: 32px;
            margin-bottom: 48px;
            align-items: baseline;
        ">
            <span class="t-mono t-eyebrow"><?php esc_html_e('03 / AGENDA', 'stridence'); ?></span>
            <h2 class="t-fraunces" style="
                font-weight: 500;
                font-size: clamp(28px, 3.5vw, 44px);
                letter-spacing: -0.02em;
                line-height: 1.05;
                margin: 0;
                max-width: 22ch;
            "><?php esc_html_e('Eerstvolgende trainingen.', 'stridence'); ?></h2>
        </div>

        <?php if ($editions->have_posts()) : ?>
        <div style="
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 24px;
        ">
            <?php while ($editions->have_posts()) : $editions->the_post(); ?>
            <a href="<?php the_permalink(); ?>" class="card card-interactive" style="
                display: block;
                padding: 24px;
                color: inherit;
                text-decoration: none;
            ">
                <span class="t-mono" style="
                    font-size: 11px;
                    letter-spacing: 0.1em;
                    color: rgb(var(--color-text-muted));
                    text-transform: uppercase;
                "><?php echo esc_html(get_post_meta(get_the_ID(), '_ntdst_start_date', true) ?: __('Binnenkort', 'stridence')); ?></span>
                <h3 style="
                    font-family: var(--font-heading);
                    font-weight: 500;
                    font-size: 20px;
                    line-height: 1.2;
                    letter-spacing: -0.015em;
                    margin: 12px 0 8px;
                    color: rgb(var(--color-text));
                "><?php the_title(); ?></h3>
                <p style="
                    font-family: var(--font-sans);
                    font-size: 14px;
                    line-height: 1.5;
                    color: rgb(var(--color-text-muted));
                    margin: 0;
                "><?php echo esc_html(wp_trim_words(get_the_excerpt(), 18)); ?></p>
            </a>
            <?php endwhile;
            wp_reset_postdata(); ?>
        </div>
        <?php else : ?>
        <p class="t-serif" style="
            font-size: 22px;
            color: rgb(var(--color-text-muted));
            max-width: 38ch;
        "><?php esc_html_e('Geen geplande trainingen op dit moment. Volg ons of meld je aan voor de nieuwsbrief.', 'stridence'); ?></p>
        <?php endif; ?>
    </div>
</section>

<!-- ═══════════════════════════════════════════ FEATURED TRAJECTORY -->
<?php
$featured_courses = get_posts([
    'post_type'      => 'sfwd-courses',
    'posts_per_page' => 1,
    'orderby'        => 'rand',
]);
$featured = $featured_courses[0] ?? null;
?>
<?php if ($featured) : ?>
<section style="
    max-width: var(--kindred-page-max);
    margin: 0 auto;
    padding: clamp(48px, 8vw, 110px) var(--kindred-gutter);
">
    <div style="
        display: grid;
        grid-template-columns: 60% 40%;
        gap: 64px;
        align-items: center;
    " class="kindred-feature">
        <div style="
            aspect-ratio: 4/3;
            background: rgb(var(--color-primary-subtle));
            border-radius: var(--radius-xl);
            overflow: hidden;
        ">
            <?php if (has_post_thumbnail($featured->ID)) : ?>
                <?php echo get_the_post_thumbnail(
                    $featured->ID,
                    'large',
                    ['style' => 'width:100%;height:100%;object-fit:cover;display:block;'],
                ); ?>
            <?php endif; ?>
        </div>
        <div>
            <span class="t-mono t-eyebrow"><?php esc_html_e('04 / UITGELICHT TRAJECT', 'stridence'); ?></span>
            <h2 class="t-fraunces" style="
                font-weight: 500;
                font-size: clamp(28px, 3vw, 40px);
                letter-spacing: -0.02em;
                line-height: 1.1;
                margin: 16px 0;
                color: rgb(var(--color-text));
            "><?php echo esc_html(get_the_title($featured->ID)); ?></h2>
            <p style="
                font-family: var(--font-sans);
                font-size: 16px;
                line-height: 1.6;
                color: rgb(var(--color-text-muted));
                margin: 0 0 24px;
            "><?php echo esc_html(wp_trim_words(get_the_excerpt($featured->ID), 32)); ?></p>
            <a href="<?php echo esc_url(get_permalink($featured->ID)); ?>" class="btn-primary btn-lg">
                <?php esc_html_e('Bekijk het traject', 'stridence'); ?>
            </a>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- ═══════════════════════════════════════════ QUOTE -->
<section style="
    background: rgb(var(--color-surface-alt));
    padding: clamp(80px, 12vw, 160px) var(--kindred-gutter);
    text-align: center;
">
    <div style="max-width: 56ch; margin: 0 auto;">
        <blockquote class="t-serif" style="
            font-size: clamp(24px, 3vw, 40px);
            line-height: 1.3;
            color: rgb(var(--color-text));
            margin: 0 0 24px;
            font-style: italic;
        ">
            <?php esc_html_e('"Geen frontale lessen. Geen abstracte modellen. Voor het eerst een traject dat midden in de praktijk landt."', 'stridence'); ?>
        </blockquote>
        <p class="t-mono" style="
            font-size: 12px;
            letter-spacing: 0.06em;
            color: rgb(var(--color-text-muted));
            margin: 0;
        "><?php esc_html_e('— L. JANSSENS · HR-DIRECTEUR · ZORGORGANISATIE', 'stridence'); ?></p>
    </div>
</section>

<!-- ═══════════════════════════════════════════ CLOSER -->
<section style="
    max-width: var(--kindred-page-max);
    margin: 0 auto;
    padding: clamp(80px, 12vw, 160px) var(--kindred-gutter);
    text-align: center;
">
    <h2 class="t-fraunces" style="
        font-weight: 400;
        font-size: clamp(36px, 5vw, 72px);
        letter-spacing: -0.03em;
        line-height: 1.05;
        margin: 0 0 24px;
        max-width: 18ch;
        margin-left: auto;
        margin-right: auto;
        color: rgb(var(--color-text));
    "><?php esc_html_e('Klaar om te starten?', 'stridence'); ?></h2>
    <p style="
        font-family: var(--font-sans);
        font-size: 17px;
        line-height: 1.55;
        color: rgb(var(--color-text-muted));
        max-width: 44ch;
        margin: 0 auto 32px;
    "><?php esc_html_e('Bekijk de eerstvolgende trainingen en plan jouw eerste sessie in.', 'stridence'); ?></p>
    <a href="<?php echo esc_url(home_url('/vormingen/')); ?>" class="btn-primary btn-lg">
        <?php esc_html_e('Bekijk de vormingen', 'stridence'); ?>
    </a>
</section>

<style>
@media (max-width: 960px) {
    .kindred-pillars { grid-template-columns: 1fr 1fr !important; }
    .kindred-feature { grid-template-columns: 1fr !important; }
}
@media (max-width: 600px) {
    .kindred-pillars { grid-template-columns: 1fr !important; }
}
</style>

<?php
get_footer();
