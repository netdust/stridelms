<?php
/**
 * Course Detail Template
 *
 * Single template for LearnDash courses (sfwd-courses post type).
 *
 * /opleidingen/<course-slug>/ — owned by LD, decorated by Stride. Branches on
 * active-edition presence:
 *
 *  - Online, active edition(s) → edition-overview surface: editions list only,
 *    no lesson list, no enroll sidebar. The visitor must pick an edition —
 *    enrollment happens on /edities/<edition-slug>/, not by the page silently
 *    choosing a cohort for them.
 *  - Online, 0 editions → e-learning surface: "Inhoud van de opleiding" lesson
 *    list + self-enroll sidebar. "?enroll=1" handler grants LD access (free) +
 *    bounces to first lesson.
 *  - Klassikaal, active edition(s) → editions list wins.
 *  - Klassikaal, 0 editions → info only, "Geen actieve edities" notice.
 *
 * See tasks/url-structure-rework.md for the full decision rules.
 *
 * @package stridence
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

use Stride\Integrations\LearnDash\LearnDashHelper;
use Stride\Modules\Edition\EditionRepository;

$course_id = get_the_ID();
$is_online = stridence_is_online_course($course_id);

$editionRepository = ntdst_get(EditionRepository::class);
$editions = $editionRepository->findByCourse($course_id);
$active_edition_ids = $editionRepository->findActiveIdsByCourse($course_id);
$has_active_edition = !empty($active_edition_ids);

// Course lessons fetched ONCE and passed down (mirrors $editions) — content,
// sidebar-online and mobile-cta each used to call LearnDashHelper::getLessons
// themselves (up to 3x per request). Only online pages render lesson data.
$lessons = $is_online
    ? LearnDashHelper::getLessons($course_id, get_current_user_id() ?: null)
    : [];

// The enroll sidebar is the e-learning surface — pure-LD online courses that
// the visitor self-enrolls into directly. An online course WITH active
// edition(s) is an edition-overview page instead: the editions list is the
// only enrollment path (you can't enroll without first choosing an edition),
// so it gets neither the sidebar nor the lesson list.
$show_online_sidebar = $is_online && !$has_active_edition;

// The sidebar only renders for pure-LD courses (no edition), so it needs no
// edition-derived args — enrollment + price come straight from LearnDash.
$online_sidebar_args = [
    'course_id' => $course_id,
    'lessons'   => $lessons,
];

$breadcrumbs = [
    ['label' => __('Opleidingen', 'stridence'), 'url' => get_post_type_archive_link('sfwd-courses')],
    ['label' => get_the_title()],
];

get_header();
?>

<article <?php post_class($show_online_sidebar ? 'pb-24 lg:pb-0' : 'pb-12 lg:pb-16'); ?>>
    <?php
    stridence_template_part('templates/course/header', null, [
        'course_id'   => $course_id,
        'breadcrumbs' => $breadcrumbs,
        'is_online'   => $is_online,
        'editions'    => $editions,
    ]);
?>

    <?php if (!$is_online) : ?>
        <?php
        // Helder Tij: the online detail page is a single focused scroll
        // (intro + lesson list + sidebar) — no tab nav. Klassikaal keeps it.
        // On an edition overview the tab set is trimmed to the sections that
        // actually render (Overzicht + Edities) so tabs can't point at removed
        // Programma/Sprekers/Praktisch anchors.
        stridence_template_part('templates/course/tabs', null, [
            'is_online'           => $is_online,
            'is_edition_overview' => $has_active_edition,
        ]);
        ?>
    <?php endif; ?>

    <div class="container py-8 lg:py-12">
        <?php if ($show_online_sidebar) : ?>
            <!-- Pure-LD online course (no active edition): two-column with the
                 self-enroll CTA sidebar. (Online courses WITH editions take the
                 edition-overview branch below — no sidebar.) -->
            <div class="grid lg:grid-cols-3 gap-8 lg:gap-12">
                <div class="lg:col-span-2 space-y-12">
                    <?php
                // No editions list here: $show_online_sidebar implies
                // !$has_active_edition, so there are none to show.
                stridence_template_part('templates/course/content', null, [
                    'course_id'     => $course_id,
                    'is_online'     => true,
                    'editions'      => $editions,
                    'lessons'       => $lessons,
                    'show_editions' => false,
                ]);
            ?>
                </div>
                <div class="lg:col-span-1">
                    <?php stridence_template_part('templates/course/sidebar-online', null, $online_sidebar_args); ?>
                </div>
            </div>
        <?php else : ?>
            <!-- Edition surface wins (klassikaal or online-with-edition), OR
                 klassikaal with no active editions (info-only). -->
            <div class="max-w-3xl space-y-12">
                <?php
                stridence_template_part('templates/course/content', null, [
                    'course_id'           => $course_id,
                    'is_online'           => $is_online,
                    'editions'            => $editions,
                    'lessons'             => $lessons,
                    // A course that HAS active editions is an edition overview
                    // (online OR klassikaal): show only the LD course intro
                    // (Overzicht) + the editions list. Programma, Sprekers and
                    // Praktische informatie are the edition-specific / enrolled
                    // experience and live on the edition page, not here.
                    'is_edition_overview' => $has_active_edition,
                ]);
            ?>

                <?php if (!$is_online && !$has_active_edition) : ?>
                    <!-- Klassikaal, 0 active editions: notice only -->
                    <div class="card p-6 bg-surface-alt border border-border">
                        <h3 class="font-heading font-semibold text-lg mb-2">
                            <?php esc_html_e('Geen actieve edities', 'stridence'); ?>
                        </h3>
                        <p class="text-sm text-text-muted">
                            <?php esc_html_e('Op dit moment zijn er geen geplande edities van deze opleiding. Hou deze pagina in de gaten of bekijk ons volledige aanbod.', 'stridence'); ?>
                        </p>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($show_online_sidebar) : ?>
        <?php
        stridence_template_part('templates/course/mobile-cta', null, array_merge($online_sidebar_args, [
            'is_online' => true,
        ]));
        ?>
    <?php endif; ?>
</article>

<?php get_footer(); ?>
