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
                ['em' => ['class' => []]]
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
