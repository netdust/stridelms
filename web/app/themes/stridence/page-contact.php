<?php
/**
 * Template Name: Contact
 *
 * Contact page with header band, info cluster and message form column.
 * The right column uses the_content() as the form seam — client patterns
 * (FluentForms shortcode or block content) keep working unchanged.
 *
 * Placeholder copy is inventoried in:
 * docs/plans/2026-06-11-helder-tij-field-inventory.md
 *
 * @package stridence
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

get_header();
?>

<!-- ═══════════════════════════════════
     HEADER BAND
     bg-surface-alt, serif h1 per mockup
     ═══════════════════════════════════ -->
<div class="bg-surface-alt">
    <div class="container py-[clamp(28px,5vw,48px)]">
        <h1 class="font-serif font-normal text-[clamp(32px,4.5vw,44px)] leading-[1.1] text-text mb-[10px]">
            <?php echo esc_html(get_the_title()); ?>
        </h1>
        <?php
        /*
         * PLACEHOLDER — contact_intro
         * Source: site option or page excerpt.
         * Field inventory: docs/plans/2026-06-11-helder-tij-field-inventory.md
         */
?>
        <p class="text-[16px] text-text-muted max-w-[560px]">
            <?php esc_html_e('Een vraag over een opleiding, een offerte voor je team, of gewoon eens aftoetsen wat kan? We antwoorden binnen één werkdag — met een mens, niet met een ticketnummer.', 'stridence'); ?>
        </p>
    </div>
</div>

<!-- ═══════════════════════════════════
     TWO-COLUMN CONTENT
     ═══════════════════════════════════ -->
<div class="container py-[clamp(24px,4vw,44px)] pb-20">
    <div class="flex flex-wrap gap-10 items-start">

        <!-- ─────────────────────────────
             LEFT: details column
             ───────────────────────────── -->
        <div class="flex-1 min-w-[300px] flex flex-col gap-[22px]">

            <!-- Persons cluster -->
            <?php
    /*
     * PLACEHOLDER — contact_persons
     * Source: site option or ACF repeater on the contact page.
     * Field inventory: docs/plans/2026-06-11-helder-tij-field-inventory.md
     * Initials, bg/text colours per mockup rows 50-53.
     */
?>
            <div class="flex gap-[14px] items-start">
                <div class="flex">
                    <span class="w-11 h-11 rounded-full bg-[#E2F0EE] text-[#0B5F5C] grid place-items-center text-[14px] font-extrabold ring-2 ring-surface">LD</span>
                    <span class="w-11 h-11 rounded-full bg-[#EBEEF9] text-[#36459C] grid place-items-center text-[14px] font-extrabold -ml-2 ring-2 ring-surface">EM</span>
                    <span class="w-11 h-11 rounded-full bg-[#DFF3E9] text-[#166F54] grid place-items-center text-[14px] font-extrabold -ml-2 ring-2 ring-surface">JV</span>
                </div>
                <p class="text-[14px] text-text-muted leading-[1.6]">
                    <?php esc_html_e('Lies, Eva en Jonas beantwoorden je bericht — zij kennen het aanbod door en door.', 'stridence'); ?>
                </p>
            </div>

            <!-- Info card -->
            <div class="bg-surface-card rounded-[16px] shadow-card divide-y divide-border-soft">
                <!-- Bezoek ons -->
                <div class="py-4 first:pt-0">
                    <?php
        /*
         * PLACEHOLDER — contact_address
         * Source: stride site settings or ACF option.
         * Field inventory: docs/plans/2026-06-11-helder-tij-field-inventory.md
         */
?>
                    <div class="px-6">
                        <p class="text-[11px] font-bold tracking-[0.1em] uppercase text-text-faint mb-[6px]">
                            <?php esc_html_e('Bezoek ons', 'stridence'); ?>
                        </p>
                        <p class="text-[14px] text-[#43454C] leading-[1.6]">
                            Vanderlindenstraat 15, 1030 Brussel<br>
                            <?php esc_html_e('op 5 min wandelen van station Schaarbeek', 'stridence'); ?>
                        </p>
                    </div>
                </div>

                <!-- Bel of mail -->
                <div class="py-4">
                    <?php
/*
 * PLACEHOLDER — contact_phone, contact_hours, contact_email
 * Source: stride site settings or ACF option.
 * Field inventory: docs/plans/2026-06-11-helder-tij-field-inventory.md
 */
?>
                    <div class="px-6">
                        <p class="text-[11px] font-bold tracking-[0.1em] uppercase text-text-faint mb-[6px]">
                            <?php esc_html_e('Bel of mail', 'stridence'); ?>
                        </p>
                        <p class="text-[14px] text-[#43454C] leading-[1.6]">
                            <?php // TODO at wiring time: esc_url('tel:' . get_option('stride_contact_phone'))?>
                            <a href="tel:+3221234567" class="text-primary font-bold no-underline">+32 2 123 45 67</a>
                            — <?php esc_html_e('werkdagen 9:00 – 17:00', 'stridence'); ?><br>
                            <?php // TODO at wiring time: esc_url('mailto:' . get_option('stride_contact_email'))?>
                            <a href="mailto:info@stride.be" class="text-primary font-bold no-underline">info@stride.be</a>
                        </p>
                    </div>
                </div>

                <!-- Facturatie -->
                <div class="py-4 last:pb-0">
                    <?php
/*
 * PLACEHOLDER — contact_vat, contact_kmo
 * Source: stride site settings or ACF option.
 * Field inventory: docs/plans/2026-06-11-helder-tij-field-inventory.md
 */
?>
                    <div class="px-6">
                        <p class="text-[11px] font-bold tracking-[0.1em] uppercase text-text-faint mb-[6px]">
                            <?php esc_html_e('Facturatie', 'stridence'); ?>
                        </p>
                        <p class="text-[14px] text-[#43454C] leading-[1.6]">
                            BTW BE 0123.456.789<br>
                            <?php esc_html_e('Erkend dienstverlener KMO-portefeuille', 'stridence'); ?>
                        </p>
                    </div>
                </div>
            </div>

            <!-- Map slot -->
            <?php
            /*
             * PLACEHOLDER — map_embed
             * Source: site option or ACF field (Google Maps embed URL or iframe).
             * Field inventory: docs/plans/2026-06-11-helder-tij-field-inventory.md
             */
?>
            <div class="aspect-video rounded-[16px] bg-[repeating-linear-gradient(45deg,#EEF1F5_0px,#EEF1F5_14px,#E4E8EE_14px,#E4E8EE_28px)] grid place-items-center">
                <span class="font-mono text-[12px] text-text-faint bg-white/80 rounded-lg px-3 py-1">
                    <?php esc_html_e('kaart: locatie Schaarbeek', 'stridence'); ?>
                </span>
            </div>

        </div><!-- /left -->

        <!-- ─────────────────────────────
             RIGHT: form card
             the_content() is the form seam — FluentForms shortcode or
             block content renders here; its handler is unchanged.
             ───────────────────────────── -->
        <div class="contact-form-card flex-1 min-w-[320px] bg-surface-card rounded-[16px] shadow-elevated p-7 flex flex-col gap-[18px]">
            <h2 class="text-[17px] font-bold text-text m-0">
                <?php esc_html_e('Stuur ons een bericht', 'stridence'); ?>
            </h2>

            <?php
// Form seam — preserves existing page content (FluentForms shortcode,
// block content, or client-pattern override) byte-identical.
if (have_posts()) :
    while (have_posts()) :
        the_post();
        the_content();
    endwhile;
endif;
?>
        </div><!-- /right -->

    </div><!-- /flex -->
</div><!-- /content -->

<?php get_footer(); ?>
