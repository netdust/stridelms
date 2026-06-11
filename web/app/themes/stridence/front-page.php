<?php
/**
 * Homepage Template — Helder Tij
 *
 * Sections per mockup `docs/stride-base-design/Homepage.dc.html`:
 * hero, mode selector, "Binnenkort van start" band, "Waarom Stride"
 * + closing CTA. Counts and featured cards come from the existing
 * INV-7 catalog pre-pass (helpers/catalog.php) — no new query shapes.
 *
 * @package stridence
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

// Mode-selector counts via the existing catalog helpers only (Task 9.1):
// the same eligible-items lists the catalog pages render, so the numbers
// match what the user finds after clicking through.
$klassikaal_items = stridence_catalog_items('klassikaal');
$online_items     = stridence_catalog_items('online');
$klassikaal_total = count($klassikaal_items);
$online_total     = count($online_items);

$trajectory_counts = wp_count_posts('vad_trajectory');
$trajectory_total  = isset($trajectory_counts->publish) ? (int) $trajectory_counts->publish : 0;

// "Binnenkort van start": the 3 soonest klassikaal items (the list is
// already start_date ASC), rendered through the same prefetch + card
// path as page-klassikaal.php. E1: zero items hides the band entirely.
$featured_items = array_slice($klassikaal_items, 0, 3);
$featured_html  = !empty($featured_items)
    ? stridence_catalog_render_cards($featured_items, get_current_user_id() ?: null)
    : '';

get_header();
?>

<!-- Hero -->
<section class="relative overflow-hidden px-[clamp(20px,4vw,48px)] pt-[clamp(64px,10vw,120px)] pb-[clamp(56px,8vw,96px)]">
    <!-- Decorative blobs -->
    <div class="absolute -top-[120px] -right-20 w-[420px] h-[420px] rounded-full bg-badge-online-bg blur-[2px] z-0" aria-hidden="true"></div>
    <div class="absolute -bottom-[180px] -left-[120px] w-[380px] h-[380px] rounded-full bg-accent-subtle z-0" aria-hidden="true"></div>

    <div class="relative z-[1] max-w-[1080px] mx-auto flex flex-col items-start gap-[22px]">
        <p class="text-[13px] font-bold uppercase tracking-[0.14em] text-accent">
            <?php esc_html_e('Professionele Ontwikkeling in de Zorg', 'stridence'); ?>
        </p>
        <h1 class="font-serif font-light text-[clamp(44px,7vw,84px)] leading-[1.05] tracking-tight max-w-[860px] [text-wrap:balance]">
            <?php echo wp_kses(
                __('Versterk je zorgteam met <em>deskundige</em> opleidingen.', 'stridence'),
                ['em' => []],
            ); ?>
        </h1>
        <p class="text-[clamp(16px,2vw,19px)] text-text-muted leading-[1.65] max-w-[560px]">
            <?php esc_html_e('Wij geloven dat leren net zo zorgvuldig moet zijn als het vak dat het ondersteunt. Ontdek een platform ontworpen voor verdieping, focus en menselijke verbinding.', 'stridence'); ?>
        </p>
        <div class="mt-2 flex flex-wrap gap-3">
            <a href="<?php echo esc_url(home_url('/klassikaal/')); ?>" class="btn-primary btn-lg">
                <?php esc_html_e('Bekijk het aanbod', 'stridence'); ?>
            </a>
            <a href="<?php echo esc_url(home_url('/contact/')); ?>" class="btn-ghost btn-lg">
                <?php esc_html_e('Opleiding op maat', 'stridence'); ?>
            </a>
        </div>
    </div>
</section>

<!-- Mode selector -->
<section class="px-[clamp(20px,4vw,48px)] pb-[clamp(56px,8vw,88px)]">
    <div class="max-w-[1080px] mx-auto flex flex-col gap-6">
        <h2 class="font-serif font-normal text-[clamp(26px,3.5vw,36px)] text-text">
            <?php esc_html_e('Hoe wil je leren?', 'stridence'); ?>
        </h2>

        <div class="grid grid-cols-[repeat(auto-fit,minmax(280px,1fr))] gap-[18px]">

            <a href="<?php echo esc_url(home_url('/trajecten/')); ?>"
                class="flex flex-col gap-3 bg-surface-card rounded-[16px] shadow-card p-7 transition-[box-shadow,transform] duration-normal ease-out hover:shadow-elevated hover:-translate-y-[3px]">
                <!-- Trajectory dots strip (korenbloem) -->
                <div class="flex items-center w-16" aria-hidden="true">
                    <span class="w-2.5 h-2.5 rounded-full bg-accent"></span>
                    <span class="h-0.5 flex-1 bg-accent-light"></span>
                    <span class="w-2.5 h-2.5 rounded-full bg-accent-light"></span>
                    <span class="h-0.5 flex-1 bg-accent-light"></span>
                    <span class="w-2.5 h-2.5 rounded-full bg-accent-subtle shadow-[inset_0_0_0_1.5px_rgb(var(--color-accent-light))]"></span>
                </div>
                <h3 class="text-[19px] font-bold text-text">
                    <?php esc_html_e('Trajecten', 'stridence'); ?>
                </h3>
                <p class="text-sm text-text-muted leading-[1.6]">
                    <?php esc_html_e('Volg een leertraject met meerdere cursussen en begeleiding', 'stridence'); ?>
                </p>
                <div class="mt-auto flex items-center justify-between pt-1.5">
                    <span class="text-[13px] font-bold text-text-faint">
                        <?php printf(esc_html(_n('%d traject', '%d trajecten', $trajectory_total, 'stridence')), $trajectory_total); ?>
                    </span>
                    <span class="text-sm font-bold text-primary"><?php esc_html_e('Ontdek', 'stridence'); ?> &rarr;</span>
                </div>
            </a>

            <a href="<?php echo esc_url(home_url('/klassikaal/')); ?>"
                class="flex flex-col gap-3 bg-surface-card rounded-[16px] shadow-card p-7 transition-[box-shadow,transform] duration-normal ease-out hover:shadow-elevated hover:-translate-y-[3px]">
                <!-- Klassikaal squares (teal) -->
                <div class="flex items-end gap-1 h-2.5" aria-hidden="true">
                    <span class="w-2.5 h-2.5 rounded-[3px] bg-primary"></span>
                    <span class="w-2.5 h-2.5 rounded-[3px] bg-primary-light"></span>
                    <span class="w-2.5 h-2.5 rounded-[3px] bg-primary-subtle"></span>
                </div>
                <h3 class="text-[19px] font-bold text-text">
                    <?php esc_html_e('Klassikaal', 'stridence'); ?>
                </h3>
                <p class="text-sm text-text-muted leading-[1.6]">
                    <?php esc_html_e('Leer samen met anderen onder begeleiding van ervaren docenten', 'stridence'); ?>
                </p>
                <div class="mt-auto flex items-center justify-between pt-1.5">
                    <span class="text-[13px] font-bold text-text-faint">
                        <?php printf(esc_html(_n('%d opleiding', '%d opleidingen', $klassikaal_total, 'stridence')), $klassikaal_total); ?>
                    </span>
                    <span class="text-sm font-bold text-primary"><?php esc_html_e('Ontdek', 'stridence'); ?> &rarr;</span>
                </div>
            </a>

            <a href="<?php echo esc_url(home_url('/online/')); ?>"
                class="flex flex-col gap-3 bg-surface-card rounded-[16px] shadow-card p-7 transition-[box-shadow,transform] duration-normal ease-out hover:shadow-elevated hover:-translate-y-[3px]">
                <!-- Online bars (teal) -->
                <div class="flex items-center gap-1 h-2.5" aria-hidden="true">
                    <span class="w-[26px] h-1.5 rounded-full bg-primary"></span>
                    <span class="w-3.5 h-1.5 rounded-full bg-primary-light"></span>
                    <span class="w-2 h-1.5 rounded-full bg-primary-subtle"></span>
                </div>
                <h3 class="text-[19px] font-bold text-text">
                    <?php esc_html_e('Online', 'stridence'); ?>
                </h3>
                <p class="text-sm text-text-muted leading-[1.6]">
                    <?php esc_html_e('Leer op je eigen tempo met e-learning en webinars', 'stridence'); ?>
                </p>
                <div class="mt-auto flex items-center justify-between pt-1.5">
                    <span class="text-[13px] font-bold text-text-faint">
                        <?php printf(esc_html(_n('%d cursus', '%d cursussen', $online_total, 'stridence')), $online_total); ?>
                    </span>
                    <span class="text-sm font-bold text-primary"><?php esc_html_e('Ontdek', 'stridence'); ?> &rarr;</span>
                </div>
            </a>

        </div>
    </div>
</section>

<!-- Binnenkort van start (hidden entirely when no upcoming items — E1) -->
<?php if (!empty($featured_items)) : ?>
<section class="bg-surface-alt px-[clamp(20px,4vw,48px)] py-[clamp(48px,7vw,80px)]">
    <div class="max-w-[1080px] mx-auto flex flex-col gap-6">
        <div class="flex flex-wrap items-baseline justify-between gap-4">
            <h2 class="font-serif font-normal text-[clamp(26px,3.5vw,36px)] text-text">
                <?php esc_html_e('Binnenkort van start', 'stridence'); ?>
            </h2>
            <a href="<?php echo esc_url(home_url('/klassikaal/')); ?>" class="text-sm font-bold text-primary hover:text-primary-hover">
                <?php esc_html_e('Volledig aanbod', 'stridence'); ?> &rarr;
            </a>
        </div>
        <div class="grid grid-cols-[repeat(auto-fit,minmax(280px,1fr))] gap-[18px]">
            <?php echo $featured_html; // Card HTML — escaped within the partials.?>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- Waarom Stride -->
<section class="px-[clamp(20px,4vw,48px)] py-[clamp(56px,8vw,96px)]">
    <div class="max-w-[1080px] mx-auto flex flex-wrap items-center gap-[clamp(28px,5vw,64px)]">
        <div class="flex-[1_1_420px] min-w-[280px] flex flex-col gap-[18px]">
            <p class="text-[13px] font-bold uppercase tracking-[0.14em] text-accent">
                <?php esc_html_e('Waarom Stride', 'stridence'); ?>
            </p>
            <h2 class="font-serif font-normal text-[clamp(26px,3.5vw,38px)] leading-[1.2] text-text [text-wrap:balance]">
                <?php esc_html_e('Kwaliteitsvolle nascholing voor de volgende generatie zorgverleners.', 'stridence'); ?>
            </h2>
            <div class="flex flex-col gap-4 text-base text-text-muted leading-[1.7] max-w-[480px]">
                <p><?php esc_html_e('Wij geloven dat professionele groei in de zorgsector niet beperkt mag blijven tot verplichte bijscholing. Onze opleidingen combineren wetenschappelijke onderbouwing met praktijkervaring.', 'stridence'); ?></p>
                <p><?php esc_html_e('Als onafhankelijk opleidingscentrum garanderen wij dat elke zorgprofessional toegang heeft tot de tools, begeleiding en erkenning die nodig zijn om het verschil te maken.', 'stridence'); ?></p>
            </div>
            <?php
            // PLACEHOLDER (field-inventory: stats_trio) — no data source for
            // these figures exists yet; values are i18n'd sample copy.
            $stats_trio = [
                ['value' => __('15', 'stridence'), 'label' => __('jaar ervaring', 'stridence')],
                ['value' => __('3.200+', 'stridence'), 'label' => __('deelnemers per jaar', 'stridence')],
                ['value' => __('9,1', 'stridence'), 'label' => __('gemiddelde score', 'stridence')],
            ];
            ?>
            <div class="mt-2 flex flex-wrap gap-[clamp(20px,4vw,44px)]">
                <?php foreach ($stats_trio as $stat) : ?>
                    <div>
                        <div class="text-[30px] font-extrabold tabular-nums text-text"><?php echo esc_html($stat['value']); ?></div>
                        <div class="text-[13px] text-text-muted"><?php echo esc_html($stat['label']); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <!-- Photo slot — PLACEHOLDER (field-inventory: waarom_foto) -->
        <div class="flex-[1_1_360px] min-w-[280px] aspect-[4/3] rounded-[20px] grid place-items-center"
            style="background: repeating-linear-gradient(45deg, rgb(var(--color-surface-alt)) 0px, rgb(var(--color-surface-alt)) 14px, rgb(var(--color-surface-container-high)) 14px, rgb(var(--color-surface-container-high)) 28px);">
            <span class="font-mono text-xs text-text-faint bg-white/80 rounded-sm px-3 py-1.5">
                <?php esc_html_e('foto: lesgever met groep, warm licht', 'stridence'); ?>
            </span>
        </div>
    </div>
</section>

<!-- Closing CTA -->
<section class="px-[clamp(20px,4vw,48px)] pb-[clamp(56px,8vw,88px)]">
    <div class="relative overflow-hidden max-w-[1080px] mx-auto bg-primary rounded-[24px] px-[clamp(24px,5vw,64px)] py-[clamp(40px,6vw,72px)] flex flex-col items-start gap-[18px]">
        <div class="absolute -top-[100px] -right-[60px] w-[280px] h-[280px] rounded-full bg-white/[0.07]" aria-hidden="true"></div>
        <?php // PLACEHOLDER copy (field-inventory: cta_team_copy) — in-company offer copy has no data source yet.?>
        <h2 class="relative font-serif font-light text-[clamp(28px,4.5vw,48px)] leading-[1.15] text-white max-w-[640px] [text-wrap:balance]">
            <?php esc_html_e('Een opleiding voor je hele team?', 'stridence'); ?>
        </h2>
        <p class="relative text-base text-white/85 leading-[1.65] max-w-[480px]">
            <?php esc_html_e('We komen naar jouw organisatie, stemmen de inhoud af op jullie praktijk en regelen alles — van offerte tot attesten.', 'stridence'); ?>
        </p>
        <a href="<?php echo esc_url(home_url('/contact/')); ?>" class="relative mt-1.5 bg-white text-primary rounded-[12px] px-7 py-[15px] text-base font-bold leading-tight transition-transform duration-fast ease-out hover:-translate-y-0.5">
            <?php esc_html_e('Vraag een offerte', 'stridence'); ?>
        </a>
    </div>
</section>

<?php
get_footer();
