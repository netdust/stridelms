<?php
/**
 * Course Content Template Part
 *
 * Main content sections for course detail page.
 *
 * @param array $args {
 *     @type int   $course_id Course post ID
 *     @type bool  $is_online Whether course is online
 *     @type array $editions  Scheduled editions for in-person courses (optional)
 *     @type array $lessons   Course lessons (LearnDashHelper::getLessons), fetched
 *                            once in single-sfwd-courses.php
 * }
 */

defined('ABSPATH') || exit;

use Stride\Integrations\LearnDash\LearnDashHelper;

$course_id    = $args['course_id'] ?? get_the_ID();
$is_online    = $args['is_online'] ?? false;
$editions     = $args['editions'] ?? [];
$lessons      = $args['lessons'] ?? [];
// When rendered on /edities/<course-slug>/ (the enrollment surface itself),
// we don't repeat the editions list — the visitor is already past discovery.
$show_editions = $args['show_editions'] ?? true;
// An edition-overview surface (a course that HAS active editions, online or
// klassikaal) is a chooser: it shows only the LD course intro (Overzicht) and
// the editions list. Programma (lessons), Sprekers and Praktische informatie
// are the enrolled / edition-specific experience and belong on the edition
// page, not here. Defaults to false so the e-learning and no-edition surfaces
// render their full content unchanged.
$is_edition_overview = $args['is_edition_overview'] ?? false;

?>

<?php
$user_id = get_current_user_id();

// Prerequisites notice
if ($is_online && LearnDashHelper::hasPrerequisites($course_id)) :
    $prerequisites = LearnDashHelper::getPrerequisites($course_id, $user_id ?: null);
    $all_met = !$user_id ? false : LearnDashHelper::arePrerequisitesMet($course_id, $user_id);

    if (!empty($prerequisites) && !$all_met) :
        ?>
<div class="mb-8 p-4 rounded-lg border border-status-warning bg-status-warning-subtle">
    <div class="flex items-start gap-3">
        <?php echo stridence_icon('alert-circle', 'w-5 h-5 text-status-warning mt-0.5 shrink-0'); ?>
        <div>
            <h3 class="font-semibold text-status-warning mb-1">
                <?php esc_html_e('Vereiste voorkennis', 'stridence'); ?>
            </h3>
            <p class="text-sm text-status-warning mb-3">
                <?php esc_html_e('Rond eerst de volgende cursus(sen) af om toegang te krijgen:', 'stridence'); ?>
            </p>
            <ul class="space-y-2">
                <?php foreach ($prerequisites as $prereq) : ?>
                    <li class="flex items-center gap-2 text-sm">
                        <?php if ($prereq['completed']) : ?>
                            <?php echo stridence_icon('check-circle', 'w-4 h-4 text-status-success'); ?>
                            <span class="text-status-success line-through"><?php echo esc_html($prereq['title']); ?></span>
                        <?php else : ?>
                            <?php echo stridence_icon('circle', 'w-4 h-4 text-status-warning'); ?>
                            <a href="<?php echo esc_url($prereq['url']); ?>" class="text-status-warning hover:underline font-medium">
                                <?php echo esc_html($prereq['title']); ?>
                            </a>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
</div>
<?php
endif;
endif;

// Points requirement notice
if ($is_online && LearnDashHelper::hasPointsRequirement($course_id)) :
    $points_required = LearnDashHelper::getPointsRequired($course_id);
    if ($points_required > 0) :
        ?>
<div class="mb-8 p-4 rounded-lg border border-blue-200 bg-blue-50">
    <div class="flex items-start gap-3">
        <?php echo stridence_icon('info', 'w-5 h-5 text-blue-600 mt-0.5 shrink-0'); ?>
        <div>
            <h3 class="font-semibold text-blue-800 mb-1">
                <?php esc_html_e('Puntenvereiste', 'stridence'); ?>
            </h3>
            <p class="text-sm text-blue-700">
                <?php echo esc_html(sprintf(
                    __('Je hebt %d punten nodig om deze cursus te volgen.', 'stridence'),
                    $points_required,
                )); ?>
            </p>
        </div>
    </div>
</div>
<?php
    endif;
endif;
?>

<!-- Overzicht Section -->
<section id="overzicht" class="scroll-mt-32">
    <div class="prose-stride max-w-none">
        <?php echo apply_filters('the_content', get_post_field('post_content', $course_id)); ?>
    </div>
</section>

<!-- Edities Section — every course (including pure-LD) shows editions on the
     /opleidingen/ container view. The list is the visitor's entry point into
     /edities/<slug>/ where the CTA lives. -->
<?php if ($show_editions) : ?>
    <?php
    stridence_template_part('templates/course/editions-list', null, [
        'editions'  => $editions,
        'course_id' => $course_id,
        'is_online' => $is_online,
    ]);
    ?>
<?php endif; ?>

<!-- Programma Section -->
<?php if (!$is_edition_overview) : ?>
<section id="programma" class="scroll-mt-32">
    <?php
// One design for the lesson list everywhere: the styled "Inhoud van de
// opleiding" card renders for ANY course (online or in-person) that has
// LearnDash lessons, for ANY visitor. getLessonsWithAvailability() degrades
// cleanly for anon / not-enrolled visitors ($user_id=0 → every row neutral,
// no completion/drip data), so the same markup is a read-only preview when
// logged out and gains progress decoration (done / current / "Hier ben je
// gebleven") once the visitor is logged in with access. The raw LearnDash
// [course_content] is the fallback only when the course has no lessons.
$lessons_with_dates = LearnDashHelper::getLessonsWithAvailability($course_id, $user_id ?: null);
    $has_styled_list    = !empty($lessons_with_dates);

    // Progress label ("X van Y modules afgerond") only when the visitor is logged
    // in with access AND there is progress data to show.
    $lesson_total         = count($lessons_with_dates);
    $lessons_done         = count(array_filter($lessons_with_dates, static fn(array $l): bool => !empty($l['completed'])));
    $show_lesson_progress = $user_id
        && $lesson_total > 0
        && LearnDashHelper::hasAccess($course_id, $user_id);
    ?>
    <?php if ($has_styled_list) : ?>
    <div class="flex justify-between items-baseline gap-3.5 flex-wrap mb-3">
        <h2 class="text-[18px] font-bold text-text">
            <?php esc_html_e('Inhoud van de opleiding', 'stridence'); ?>
        </h2>
        <?php if ($show_lesson_progress) : ?>
            <div class="text-[13px] font-bold text-text-muted">
                <?php
                /* translators: 1: completed modules, 2: total modules */
                echo esc_html(sprintf(__('%1$d van %2$d modules afgerond', 'stridence'), $lessons_done, $lesson_total));
            ?>
            </div>
        <?php endif; ?>
    </div>
    <?php else : ?>
    <h2 class="font-heading text-2xl font-bold text-text mb-6">
        <?php esc_html_e('Programma', 'stridence'); ?>
    </h2>
    <?php endif; ?>

    <?php
if ($has_styled_list) :
    $locked_lessons = array_filter($lessons_with_dates, fn($l) => !$l['is_available']);
    ?>
        <?php if (!empty($locked_lessons)) : ?>
        <div class="mb-4 p-3 rounded-lg bg-blue-50 border border-blue-200 text-sm text-blue-800 flex items-start gap-2">
            <?php echo stridence_icon('info', 'w-4 h-4 mt-0.5 shrink-0 text-blue-600'); ?>
            <span>
                <?php esc_html_e('Sommige lessen worden op een later moment beschikbaar. Bekijk de planning hieronder.', 'stridence'); ?>
            </span>
        </div>
        <?php endif; ?>

        <!-- Helder Tij lesson-list recipe (white card, divider rows, status
             circles). Our own markup — the LearnDash list is never re-rendered
             when this shows. -->
        <div class="mb-6 bg-surface-card rounded-[16px] shadow-card flex flex-col overflow-hidden">
            <?php
            $current_marked = false;
    foreach (array_values($lessons_with_dates) as $i => $lesson) :
        $is_done    = !empty($lesson['completed']);
        $is_locked  = empty($lesson['is_available']);
        $is_current = !$current_marked && !$is_done && !$is_locked;
        if ($is_current) {
            $current_marked = true;
        }
        ?>
                <?php if ($i > 0) : ?>
                    <div class="h-px bg-surface-alt mx-5"></div>
                <?php endif; ?>
                <div class="px-5 py-4 flex items-center gap-3.5<?php echo $is_current ? ' bg-badge-online-bg/50' : ''; ?>">
                    <?php if ($is_done) : ?>
                        <span class="w-6 h-6 rounded-full bg-badge-open-bg text-badge-open-text text-[13px] font-extrabold grid place-items-center shrink-0" aria-hidden="true">&check;</span>
                    <?php elseif ($is_current) : ?>
                        <span class="w-6 h-6 rounded-full bg-primary grid place-items-center shrink-0" aria-hidden="true"><span class="w-2 h-2 rounded-full bg-white"></span></span>
                    <?php else : ?>
                        <span class="w-6 h-6 rounded-full border-2 border-border shrink-0" aria-hidden="true"></span>
                    <?php endif; ?>

                    <div class="flex-1 min-w-0">
                        <?php
                    // "N · " prefix mirrors the design's numbered rows.
                    $num_prefix = ($i + 1) . ' · ';
        ?>
                        <?php if (!$is_locked) : ?>
                            <a href="<?php echo esc_url($lesson['url']); ?>" class="text-[15px] <?php echo $is_current ? 'font-bold text-text' : 'font-semibold ' . ($is_done ? 'text-text-muted' : 'text-text'); ?> hover:text-primary truncate block">
                                <span class="text-text-muted font-normal"><?php echo esc_html($num_prefix); ?></span><?php echo esc_html($lesson['title']); ?>
                            </a>
                            <?php if ($is_current) : ?>
                                <div class="text-[12px] font-bold text-badge-online-text mt-0.5">
                                    <?php esc_html_e('Hier ben je gebleven', 'stridence'); ?>
                                </div>
                            <?php endif; ?>
                        <?php else : ?>
                            <span class="text-[15px] font-semibold text-text-muted truncate block">
                                <span class="font-normal"><?php echo esc_html($num_prefix); ?></span><?php echo esc_html($lesson['title']); ?>
                            </span>
                        <?php endif; ?>
                    </div>

                    <?php if ($lesson['available_from']) : ?>
                        <span class="text-xs text-text-muted whitespace-nowrap">
                            <?php echo esc_html(sprintf(
                                __('Beschikbaar %s', 'stridence'),
                                stride_format_date(date('Y-m-d', $lesson['available_from'])),
                            )); ?>
                        </span>
                    <?php elseif ($is_done) : ?>
                        <span class="text-xs text-badge-open-text whitespace-nowrap">
                            <?php esc_html_e('Afgerond', 'stridence'); ?>
                        </span>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else : ?>
    <!-- Fallback only when the course has no LearnDash lessons to style. -->
    <div class="learndash-course-content">
        <?php
        echo do_shortcode('[course_content course_id="' . esc_attr($course_id) . '"]');
        ?>
    </div>
    <?php endif; ?>
</section>
<?php endif; // !$is_edition_overview?>

<?php if (!$is_online && !$is_edition_overview) : ?>
<!-- Sprekers Section (in-person only) -->
<section id="sprekers" class="scroll-mt-32">
    <h2 class="font-heading text-2xl font-bold text-text mb-6">
        <?php esc_html_e('Sprekers', 'stridence'); ?>
    </h2>
    <?php
        // TODO: Wire up speakers from course/edition meta
    ?>
    <p class="text-text-muted">
        <?php esc_html_e('Informatie over sprekers wordt binnenkort toegevoegd.', 'stridence'); ?>
    </p>
</section>
<?php endif; ?>

<!-- Praktisch Section -->
<?php if (!$is_edition_overview) : ?>
<section id="praktisch" class="scroll-mt-32">
    <h2 class="font-heading text-2xl font-bold text-text mb-6">
        <?php esc_html_e('Praktische informatie', 'stridence'); ?>
    </h2>
    <?php /* Doelgroep + Accreditatie cards removed (Stefan, 2026-06-12):
             courses carry no such fields — we use only what LearnDash
             offers at course level. Audience info lives on editions
             (target_audience). The remaining cards are generic copy. */ ?>
    <?php if ($is_online) : ?>
    <div class="grid sm:grid-cols-2 gap-4">
        <div class="card-bordered p-5">
            <h3 class="font-semibold text-text mb-2 flex items-center gap-2">
                <?php echo stridence_icon('clock', 'w-5 h-5 text-primary'); ?>
                <?php esc_html_e('Doorlooptijd', 'stridence'); ?>
            </h3>
            <p class="text-text-muted text-sm">
                <?php esc_html_e('In eigen tempo', 'stridence'); ?>
            </p>
        </div>
        <div class="card-bordered p-5">
            <h3 class="font-semibold text-text mb-2 flex items-center gap-2">
                <?php echo stridence_icon('device-laptop', 'w-5 h-5 text-primary'); ?>
                <?php esc_html_e('Toegang', 'stridence'); ?>
            </h3>
            <p class="text-text-muted text-sm">
                <?php esc_html_e('Online, 24/7 beschikbaar', 'stridence'); ?>
            </p>
        </div>
    </div>
    <?php endif; ?>

    <?php
    $materials = LearnDashHelper::getCourseMaterials($course_id);
    if (!empty($materials)) :
        ?>
        <div class="card-bordered p-5 mt-6">
            <h3 class="font-semibold text-text mb-3 flex items-center gap-2">
                <?php echo stridence_icon('file-text', 'w-5 h-5 text-primary'); ?>
                <?php esc_html_e('Cursusmateriaal', 'stridence'); ?>
            </h3>
            <div class="prose-stride text-sm max-w-none">
                <?php echo wp_kses_post($materials); ?>
            </div>
        </div>
    <?php endif; ?>
</section>
<?php endif; // !$is_edition_overview?>
