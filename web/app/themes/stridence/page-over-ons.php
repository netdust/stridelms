<?php
/**
 * Template Name: Over ons
 *
 * Editorial "About us" page following the Helder Tij mockup.
 *
 * Sections:
 *  1. Editorial hero  — eyebrow + serif light h1 + italic lede
 *  2. Long-read body  — the_content() as prose seam
 *  3. Pull-quote      — left-accent serif italic
 *  4. 21:9 photo slot — placeholder + caption
 *  5. Values grid     — "Waar we voor staan" + 3 cards (PLACEHOLDER)
 *  6. Team grid       — "Het team" + 4 cards (PLACEHOLDER)
 *  7. Closing CTA     — bg-surface-alt card → /contact/
 *
 * All placeholder strings are i18n'd via the 'stridence' text-domain.
 * See docs/plans/2026-06-11-helder-tij-field-inventory.md for the
 * field-inventory of every PLACEHOLDER below.
 *
 * @package stridence
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

get_header();
?>

<main id="main-content">
<?php while (have_posts()) : the_post(); ?>

    <!-- ══════════════════════════════════════════════════
         1. EDITORIAL HERO
         ══════════════════════════════════════════════════ -->
    <section class="px-[clamp(20px,4vw,48px)] pt-[clamp(56px,9vw,104px)] pb-[clamp(40px,6vw,64px)]">
        <div class="max-w-[760px] mx-auto flex flex-col gap-[22px]">

            <!-- Eyebrow: 13px/700 uppercase text-accent -->
            <div class="text-[13px] font-bold tracking-[0.14em] uppercase text-accent">
                <?php
                /*
                 * PLACEHOLDER: eyebrow label.
                 * Suggested source: the page title (the_title()) if natural,
                 * or a dedicated page meta field `over_ons_eyebrow`.
                 */
                echo esc_html(get_the_title());
                ?>
            </div>

            <!-- Serif light h1 clamp(38px,6vw,68px) -->
            <h1 class="font-serif font-light text-[clamp(38px,6vw,68px)] leading-[1.08] text-text m-0 text-balance">
                <?php
                /*
                 * PLACEHOLDER: editorial headline.
                 * Mockup: "We begonnen in een leefgroep, niet in een leslokaal."
                 * Suggested source: page meta field `over_ons_headline`
                 *   (or authored directly in the page's post content title — then
                 *    replace this block with the_title()).
                 */
                esc_html_e('We begonnen in een leefgroep, niet in een leslokaal.', 'stridence');
                ?>
            </h1>

            <!-- Serif italic lede clamp(18px,2.2vw,22px) text-text-muted -->
            <p class="font-serif text-[clamp(18px,2.2vw,22px)] text-text-muted leading-[1.6] m-0">
                <?php
                /*
                 * PLACEHOLDER: hero lede / sub-headline.
                 * Mockup: "Stride ontstond in 2011, toen twee jeugdzorgbegeleiders
                 *           merkten dat de opleidingen die ze kregen niets te maken
                 *           hadden met de dagen die ze meemaakten."
                 * Suggested source: page meta field `over_ons_lede` (textarea).
                 */
                esc_html_e(
                    'Stride ontstond in 2011, toen twee jeugdzorgbegeleiders merkten dat de opleidingen die ze kregen niets te maken hadden met de dagen die ze meemaakten.',
                    'stridence'
                );
                ?>
            </p>

        </div>
    </section>

    <!-- ══════════════════════════════════════════════════
         2. LONG-READ BODY  (prose seam — client edits this)
         ══════════════════════════════════════════════════ -->
    <section class="px-[clamp(20px,4vw,48px)] pb-[clamp(48px,7vw,72px)]">
        <div class="max-w-[760px] mx-auto text-[17px] leading-[1.75] text-text-muted">
            <?php the_content(); ?>
        </div>
    </section>

    <!-- ══════════════════════════════════════════════════
         3. PULL-QUOTE
         ══════════════════════════════════════════════════ -->
    <section class="px-[clamp(20px,4vw,48px)] pb-[clamp(32px,5vw,48px)]">
        <div class="max-w-[760px] mx-auto">
            <blockquote class="border-l-[3px] border-primary pl-6 font-serif italic text-[clamp(22px,3vw,28px)] leading-[1.4] text-text m-0">
                <?php
                /*
                 * PLACEHOLDER: pull-quote.
                 * Mockup: "Een goede opleiding voelt niet als een dag weg van het werk.
                 *           Ze voelt als een dag die je werk teruggeeft."
                 * Suggested source: page meta field `over_ons_pullquote` (textarea).
                 */
                esc_html_e(
                    '"Een goede opleiding voelt niet als een dag weg van het werk. Ze voelt als een dag die je werk teruggeeft."',
                    'stridence'
                );
                ?>
            </blockquote>
        </div>
    </section>

    <!-- ══════════════════════════════════════════════════
         4. PHOTO SLOT  (21:9)
         ══════════════════════════════════════════════════ -->
    <section class="px-[clamp(20px,4vw,48px)] pb-[clamp(32px,5vw,48px)]">
        <div class="max-w-[760px] mx-auto">
            <?php
            /*
             * PLACEHOLDER: team/office photo.
             * Mockup: striped placeholder, caption "foto: het team, kantoor Schaarbeek".
             * Suggested source: page meta field `over_ons_photo` (image/attachment)
             *   + `over_ons_photo_caption` (text).
             * When the meta is populated, replace the placeholder div with:
             *   <img src="..." alt="..." class="aspect-[21/9] w-full object-cover rounded-[20px]">
             */
            ?>
            <figure class="m-0">
                <div class="aspect-[21/9] rounded-[20px] overflow-hidden"
                     style="background: repeating-linear-gradient(45deg, rgb(var(--color-surface-alt)) 0px, rgb(var(--color-surface-alt)) 14px, rgb(var(--color-border-soft)) 14px, rgb(var(--color-border-soft)) 28px); display: grid; place-items: center;">
                    <span class="font-mono text-[12px] text-text-faint bg-surface-card/80 rounded-[8px] px-3 py-1.5">
                        <?php esc_html_e('foto: het team, kantoor Schaarbeek', 'stridence'); ?>
                    </span>
                </div>
                <figcaption class="mt-2 text-[13px] text-text-faint text-center">
                    <?php esc_html_e('foto: het team, kantoor Schaarbeek', 'stridence'); ?>
                </figcaption>
            </figure>
        </div>
    </section>

    <!-- ══════════════════════════════════════════════════
         5. VALUES  — "Waar we voor staan"
         ══════════════════════════════════════════════════ -->
    <section class="px-[clamp(20px,4vw,48px)] pb-[clamp(32px,5vw,48px)]">
        <div class="max-w-[760px] mx-auto">

            <h2 class="font-serif font-normal text-[clamp(26px,3.5vw,34px)] leading-[1.2] text-text mt-3 mb-4">
                <?php esc_html_e('Waar we voor staan', 'stridence'); ?>
            </h2>

            <?php
            /*
             * PLACEHOLDER: values cards (3 items).
             * Each card: title (15px/700) + copy (14px text-text-muted).
             * Mockup values: "Praktijk eerst" / "Veilig oefenen" / "Geen blabla"
             * Suggested source: page meta repeater `over_ons_values[]`
             *   → each item: `title` + `description`.
             * When the meta is populated, loop over it and output the card markup.
             */
            $values = [
                [
                    'title' => __('Praktijk eerst', 'stridence'),
                    'copy'  => __('Elke techniek is getest op een echte werkvloer voor ze in een cursus belandt.', 'stridence'),
                ],
                [
                    'title' => __('Veilig oefenen', 'stridence'),
                    'copy'  => __('Moeilijke gesprekken oefen je bij ons — niet voor het eerst bij een cliënt.', 'stridence'),
                ],
                [
                    'title' => __('Geen blabla', 'stridence'),
                    'copy'  => __('Kleine groepen, eerlijke feedback en attesten die iets betekenen.', 'stridence'),
                ],
            ];
            ?>

            <div class="grid grid-cols-[repeat(auto-fit,minmax(220px,1fr))] gap-[14px]">
                <?php foreach ($values as $value) : ?>
                    <div class="bg-surface-card rounded-[14px] shadow-card p-[22px]">
                        <div class="text-[15px] font-bold text-text mb-1.5">
                            <?php echo esc_html($value['title']); ?>
                        </div>
                        <div class="text-[14px] text-text-muted leading-[1.6]">
                            <?php echo esc_html($value['copy']); ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

        </div>
    </section>

    <!-- ══════════════════════════════════════════════════
         6. TEAM  — "Het team"
         ══════════════════════════════════════════════════ -->
    <section class="px-[clamp(20px,4vw,48px)] pb-[clamp(32px,5vw,48px)]">
        <div class="max-w-[760px] mx-auto">

            <h2 class="font-serif font-normal text-[clamp(26px,3.5vw,34px)] leading-[1.2] text-text mt-3 mb-4">
                <?php esc_html_e('Het team', 'stridence'); ?>
            </h2>

            <?php
            /*
             * PLACEHOLDER: team members (4 cards shown; third party = overflow card).
             * Card: 56px initials circle bg-accent-subtle text-accent-hover + name 14px/700 + role 12px text-text-faint.
             * Mockup members: Lies De Smet / Jonas Verhulst / Eva Maerten / "+9 lesgevers"
             * Suggested source: custom post type `stride_trainer` or page meta
             *   repeater `over_ons_team[]` → each item: `initials`, `name`, `role`.
             */
            $team_members = [
                [
                    'initials' => 'LD',
                    'name'     => __('Lies De Smet', 'stridence'),
                    'role'     => __('oprichter · jeugdzorg', 'stridence'),
                    'bg'       => 'bg-primary-subtle',
                    'color'    => 'text-primary-dark',
                ],
                [
                    'initials' => 'JV',
                    'name'     => __('Jonas Verhulst', 'stridence'),
                    'role'     => __('oprichter · gehandicaptenzorg', 'stridence'),
                    'bg'       => 'bg-accent-subtle',
                    'color'    => 'text-accent-hover',
                ],
                [
                    'initials' => 'EM',
                    'name'     => __('Eva Maerten', 'stridence'),
                    'role'     => __('trainer · agressiebeheersing', 'stridence'),
                    'bg'       => 'bg-badge-free-bg',
                    'color'    => 'text-badge-free-text',
                ],
                [
                    'initials' => '+9',
                    'name'     => __('en negen lesgevers', 'stridence'),
                    'role'     => __('allemaal uit de sector', 'stridence'),
                    'bg'       => 'bg-badge-few-bg',
                    'color'    => 'text-badge-few-text',
                ],
            ];
            ?>

            <div class="grid grid-cols-[repeat(auto-fit,minmax(160px,1fr))] gap-4">
                <?php foreach ($team_members as $member) : ?>
                    <div class="bg-surface-card rounded-[14px] shadow-card p-5 flex flex-col items-center text-center gap-2">
                        <span class="w-14 h-14 rounded-full <?php echo esc_attr($member['bg']); ?> <?php echo esc_attr($member['color']); ?> grid place-items-center text-[17px] font-extrabold flex-shrink-0">
                            <?php echo esc_html($member['initials']); ?>
                        </span>
                        <div class="text-[14px] font-bold text-text">
                            <?php echo esc_html($member['name']); ?>
                        </div>
                        <div class="text-[12px] text-text-faint">
                            <?php echo esc_html($member['role']); ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

        </div>
    </section>

    <!-- ══════════════════════════════════════════════════
         7. CLOSING CTA
         ══════════════════════════════════════════════════ -->
    <section class="px-[clamp(20px,4vw,48px)] pb-[clamp(56px,8vw,88px)]">
        <div class="max-w-[760px] mx-auto bg-surface-alt rounded-[20px] p-[clamp(32px,5vw,48px)] text-center">
            <h2 class="font-serif font-normal text-[clamp(22px,3vw,28px)] leading-[1.25] text-text m-0 mb-6">
                <?php
                /*
                 * PLACEHOLDER: CTA heading.
                 * Mockup: "Benieuwd of we bij jouw organisatie passen?"
                 * Suggested source: page meta field `over_ons_cta_heading`.
                 */
                esc_html_e('Benieuwd of we bij jouw organisatie passen?', 'stridence');
                ?>
            </h2>
            <a href="<?php echo esc_url(home_url('/contact/')); ?>" class="btn-primary">
                <?php esc_html_e('Plan een kennismaking', 'stridence'); ?>
            </a>
        </div>
    </section>

<?php endwhile; ?>
</main>

<?php get_footer(); ?>
