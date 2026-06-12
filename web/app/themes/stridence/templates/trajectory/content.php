<?php
/**
 * Trajectory Content Template Part
 *
 * Main content sections for trajectory detail page.
 *
 * @param array $args {
 *     @type int   $trajectory_id     Trajectory post ID
 *     @type array $required_courses  Array of required course WP_Post objects
 *     @type array $elective_groups   Array of elective groups with courses
 *     @type array $trajectory        Trajectory data array
 * }
 */

defined('ABSPATH') || exit;

$trajectory_id    = $args['trajectory_id'] ?? get_the_ID();
$required_courses = $args['required_courses'] ?? [];
$elective_groups  = $args['elective_groups'] ?? [];
$trajectory       = $args['trajectory'] ?? [];

$has_courses = !empty($required_courses) || !empty($elective_groups);

?>
<?php
// Descriptive fields (shared with editions) — surfaced by getTrajectory().
$target_audience     = trim((string) ($trajectory['target_audience'] ?? ''));
$required_experience = trim((string) ($trajectory['required_experience'] ?? ''));
$included            = trim((string) ($trajectory['included'] ?? ''));
$cancellation_policy = trim((string) ($trajectory['cancellation_policy'] ?? ''));
$duration            = trim((string) ($trajectory['duration'] ?? ''));
?>
<!-- Overzicht Section -->
<section id="overzicht" class="scroll-mt-32 flex flex-col gap-6">
    <!-- Bare prose, no card — matches the edition/course description block
         (single-vad_edition.php #omschrijving). A panel around the body text
         reads as broken when the content is a single short paragraph. -->
    <div class="prose-stride max-w-none">
        <?php echo apply_filters('the_content', get_post_field('post_content', $trajectory_id)); ?>
    </div>

    <?php if ($target_audience !== '') : ?>
        <!-- "Voor wie?" well — mirrors single-vad_edition.php:353. -->
        <div class="bg-surface-alt rounded-[14px] p-5 text-[14px] text-text-muted leading-relaxed">
            <strong class="text-text"><?php esc_html_e('Voor wie?', 'stridence'); ?></strong>
            <?php echo esc_html($target_audience); ?>
        </div>
    <?php endif; ?>
</section>

<!-- Cursussen Section -->
<?php if ($has_courses) : ?>
<section id="cursussen" class="scroll-mt-32">
    <h2 class="font-heading text-xl font-semibold text-text mb-4">
        <?php esc_html_e('Cursussen in dit traject', 'stridence'); ?>
    </h2>

    <?php
    stridence_template_part('templates/trajectory/course-groups', null, [
        'required_courses'  => $required_courses,
        'elective_groups'   => $elective_groups,
    ]);
    ?>
</section>
<?php endif; ?>

<!-- Praktisch Section — always-on cards (course count, doorlooptijd) plus
     render-when-present wells for the descriptive fields. No hardcoded
     placeholder values: an unset field shows no card. -->
<?php
$total_courses = count($required_courses);
foreach ($elective_groups as $group) {
    $total_courses += count($group['courses'] ?? []);
}
// Doorlooptijd: the authored duration wins; otherwise derive from mode.
$mode = $trajectory['mode'] ?? 'cohort';
$duration_label = $duration !== ''
    ? $duration
    : ($mode === 'self_paced'
        ? __('In eigen tempo', 'stridence')
        : __('Vast programma met cohort', 'stridence'));
?>
<section id="praktisch" class="scroll-mt-32">
    <h2 class="font-heading text-xl font-semibold text-text mb-4">
        <?php esc_html_e('Praktische informatie', 'stridence'); ?>
    </h2>
    <div class="grid sm:grid-cols-2 gap-4">
        <div class="bg-surface-card rounded-[14px] shadow-card p-5">
            <h3 class="font-semibold text-text mb-2 flex items-center gap-2">
                <?php echo stridence_icon('book-open', 'w-5 h-5 text-primary'); ?>
                <?php esc_html_e('Cursussen', 'stridence'); ?>
            </h3>
            <p class="text-text-muted text-sm">
                <?php
                printf(
                    esc_html(_n('%d cursus', '%d cursussen', $total_courses, 'stridence')),
                    $total_courses,
                );
?>
            </p>
        </div>
        <div class="bg-surface-card rounded-[14px] shadow-card p-5">
            <h3 class="font-semibold text-text mb-2 flex items-center gap-2">
                <?php echo stridence_icon('clock', 'w-5 h-5 text-primary'); ?>
                <?php esc_html_e('Doorlooptijd', 'stridence'); ?>
            </h3>
            <p class="text-text-muted text-sm"><?php echo esc_html($duration_label); ?></p>
        </div>
        <?php if ($required_experience !== '') : ?>
            <div class="bg-surface-card rounded-[14px] shadow-card p-5">
                <h3 class="font-semibold text-text mb-2 flex items-center gap-2">
                    <?php echo stridence_icon('check-circle', 'w-5 h-5 text-primary'); ?>
                    <?php esc_html_e('Voorkennis', 'stridence'); ?>
                </h3>
                <p class="text-text-muted text-sm"><?php echo esc_html($required_experience); ?></p>
            </div>
        <?php endif; ?>
        <?php if ($included !== '') : ?>
            <div class="bg-surface-card rounded-[14px] shadow-card p-5">
                <h3 class="font-semibold text-text mb-2 flex items-center gap-2">
                    <?php echo stridence_icon('award', 'w-5 h-5 text-primary'); ?>
                    <?php esc_html_e('Inbegrepen', 'stridence'); ?>
                </h3>
                <p class="text-text-muted text-sm"><?php echo esc_html($included); ?></p>
            </div>
        <?php endif; ?>
        <?php if ($cancellation_policy !== '') : ?>
            <div class="bg-surface-card rounded-[14px] shadow-card p-5">
                <h3 class="font-semibold text-text mb-2 flex items-center gap-2">
                    <?php echo stridence_icon('alert-circle', 'w-5 h-5 text-primary'); ?>
                    <?php esc_html_e('Annuleren', 'stridence'); ?>
                </h3>
                <p class="text-text-muted text-sm"><?php echo esc_html($cancellation_policy); ?></p>
            </div>
        <?php endif; ?>
    </div>
</section>

<!-- FAQ Section -->
<section id="faq" class="scroll-mt-32">
    <h2 class="font-heading text-xl font-semibold text-text mb-4">
        <?php esc_html_e('Veelgestelde vragen', 'stridence'); ?>
    </h2>

    <div class="space-y-3">
        <!-- FAQ Item 1 -->
        <div class="bg-surface-card rounded-[14px] shadow-card border border-border-soft" x-data="expandable()">
            <button type="button"
                    class="w-full p-5 text-left flex items-center justify-between gap-4"
                    @click="toggle()">
                <span class="font-semibold text-text">
                    <?php esc_html_e('Wat is een leertraject?', 'stridence'); ?>
                </span>
                <span class="flex-shrink-0 transition-transform duration-200" :class="{ 'rotate-180': open }">
                    <?php echo stridence_icon('chevron-down', 'w-5 h-5 text-text-muted'); ?>
                </span>
            </button>
            <div x-show="open" x-collapse>
                <div class="px-5 pb-5 text-text-muted">
                    <p><?php esc_html_e('Een leertraject is een samengesteld programma van meerdere cursussen die samen een volledige opleiding vormen. Je schrijft je in voor het hele traject en volgt alle cursussen in een logische volgorde. Na afronding ontvang je een certificaat.', 'stridence'); ?></p>
                </div>
            </div>
        </div>

        <!-- FAQ Item 2 -->
        <div class="bg-surface-card rounded-[14px] shadow-card border border-border-soft" x-data="expandable()">
            <button type="button"
                    class="w-full p-5 text-left flex items-center justify-between gap-4"
                    @click="toggle()">
                <span class="font-semibold text-text">
                    <?php esc_html_e('Hoe werken verplichte en keuzecursussen?', 'stridence'); ?>
                </span>
                <span class="flex-shrink-0 transition-transform duration-200" :class="{ 'rotate-180': open }">
                    <?php echo stridence_icon('chevron-down', 'w-5 h-5 text-text-muted'); ?>
                </span>
            </button>
            <div x-show="open" x-collapse>
                <div class="px-5 pb-5 text-text-muted">
                    <p><?php esc_html_e('Verplichte cursussen zijn onderdelen die iedereen moet volgen. Keuzecursussen bieden je de mogelijkheid om je te specialiseren. Je kiest een bepaald aantal uit het aanbod, afhankelijk van het traject.', 'stridence'); ?></p>
                </div>
            </div>
        </div>

        <!-- FAQ Item 3 -->
        <div class="bg-surface-card rounded-[14px] shadow-card border border-border-soft" x-data="expandable()">
            <button type="button"
                    class="w-full p-5 text-left flex items-center justify-between gap-4"
                    @click="toggle()">
                <span class="font-semibold text-text">
                    <?php esc_html_e('Wanneer moet ik mijn keuzecursussen kiezen?', 'stridence'); ?>
                </span>
                <span class="flex-shrink-0 transition-transform duration-200" :class="{ 'rotate-180': open }">
                    <?php echo stridence_icon('chevron-down', 'w-5 h-5 text-text-muted'); ?>
                </span>
            </button>
            <div x-show="open" x-collapse>
                <div class="px-5 pb-5 text-text-muted">
                    <?php
    $choice_date = $trajectory['choice_available_date'] ?? '';
$choice_deadline = $trajectory['choice_deadline'] ?? '';
if ($choice_date && $choice_deadline) {
    printf(
        '<p>' . esc_html__('Je kunt je keuzecursussen kiezen tussen %s en %s. Je ontvangt tijdig een herinnering.', 'stridence') . '</p>',
        esc_html(stride_format_date($choice_date)),
        esc_html(stride_format_date($choice_deadline)),
    );
} else {
    echo '<p>' . esc_html__('De keuzeperiode wordt bekendgemaakt na je inschrijving. Je ontvangt tijdig een herinnering.', 'stridence') . '</p>';
}
?>
                </div>
            </div>
        </div>

        <!-- FAQ Item 4 -->
        <div class="bg-surface-card rounded-[14px] shadow-card border border-border-soft" x-data="expandable()">
            <button type="button"
                    class="w-full p-5 text-left flex items-center justify-between gap-4"
                    @click="toggle()">
                <span class="font-semibold text-text">
                    <?php esc_html_e('Kan ik cursussen los volgen?', 'stridence'); ?>
                </span>
                <span class="flex-shrink-0 transition-transform duration-200" :class="{ 'rotate-180': open }">
                    <?php echo stridence_icon('chevron-down', 'w-5 h-5 text-text-muted'); ?>
                </span>
            </button>
            <div x-show="open" x-collapse>
                <div class="px-5 pb-5 text-text-muted">
                    <p><?php esc_html_e('Sommige cursussen zijn ook los te volgen. Bekijk het cursusoverzicht voor meer informatie. Let op: alleen het volledige traject leidt tot certificering.', 'stridence'); ?></p>
                </div>
            </div>
        </div>

        <!-- FAQ Item 5 -->
        <div class="bg-surface-card rounded-[14px] shadow-card border border-border-soft" x-data="expandable()">
            <button type="button"
                    class="w-full p-5 text-left flex items-center justify-between gap-4"
                    @click="toggle()">
                <span class="font-semibold text-text">
                    <?php esc_html_e('Wat gebeurt er na inschrijving?', 'stridence'); ?>
                </span>
                <span class="flex-shrink-0 transition-transform duration-200" :class="{ 'rotate-180': open }">
                    <?php echo stridence_icon('chevron-down', 'w-5 h-5 text-text-muted'); ?>
                </span>
            </button>
            <div x-show="open" x-collapse>
                <div class="px-5 pb-5 text-text-muted">
                    <p><?php esc_html_e('Na inschrijving ontvang je een bevestigingsmail met alle praktische informatie. Je krijgt toegang tot je persoonlijk dashboard waar je je voortgang kunt volgen, sessies kunt bekijken en documenten kunt downloaden.', 'stridence'); ?></p>
                </div>
            </div>
        </div>
    </div>
</section>
