<?php
/**
 * Course Detail Template
 *
 * Single template for LearnDash courses (sfwd-courses post type).
 *
 * /opleidingen/<course-slug>/ — owned by LD, decorated by Stride. Branches on
 * active-edition presence:
 *
 *  - Has active edition(s) → editions list wins. Edition CTA(s) flow through
 *    /edities/<edition-slug>/ → /edities/<edition-slug>/inschrijving/.
 *  - 0 editions, online format → Stride self-enroll CTA on the sidebar.
 *    "?enroll=1" handler grants LD access (free) + bounces to first lesson.
 *  - 0 editions, klassikaal → info only, "Geen actieve edities" notice.
 *
 * See tasks/url-structure-rework.md for the full decision rules.
 *
 * @package stridence
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

use Stride\Modules\Edition\EditionRepository;
use Stride\Integrations\LearnDash\LearnDashHelper;

$course_id = get_the_ID();
$is_online = stridence_is_online_course($course_id);

$editionRepository = ntdst_get(EditionRepository::class);
$editions = $editionRepository->findByCourse($course_id);
$active_edition_ids = $editionRepository->findActiveIdsByCourse($course_id);
$has_active_edition = !empty($active_edition_ids);

// Show the self-enroll sidebar only for pure-LD online courses (no active edition).
$show_self_enroll_sidebar = $is_online && !$has_active_edition;

$breadcrumbs = [
    ['label' => __('Opleidingen', 'stridence'), 'url' => get_post_type_archive_link('sfwd-courses')],
    ['label' => get_the_title()],
];

get_header();
?>

<article <?php post_class('pb-12 lg:pb-16'); ?>>
    <?php
    stridence_template_part('templates/course/header', null, [
        'course_id'   => $course_id,
        'breadcrumbs' => $breadcrumbs,
        'is_online'   => $is_online,
        'editions'    => $editions,
    ]);
    ?>

    <?php
    stridence_template_part('templates/course/tabs', null, [
        'is_online' => $is_online,
    ]);
    ?>

    <div class="container py-8 lg:py-12">
        <?php if ($show_self_enroll_sidebar) : ?>
            <!-- Pure-LD online course: two-column with self-enroll sidebar -->
            <div class="grid lg:grid-cols-3 gap-8 lg:gap-12">
                <div class="lg:col-span-2 space-y-12">
                    <?php
                    stridence_template_part('templates/course/content', null, [
                        'course_id'     => $course_id,
                        'is_online'     => true,
                        'show_editions' => false,
                    ]);
                    ?>
                </div>
                <div class="lg:col-span-1">
                    <?php
                    stridence_template_part('templates/course/sidebar-online', null, [
                        'course_id'              => $course_id,
                        'enrollment_url'         => '',
                        'user_enrolled'          => false,
                        'edition_price'          => null,
                        'primary_edition_id'     => 0,
                        'primary_edition_status' => null,
                    ]);
                    ?>
                </div>
            </div>
        <?php else : ?>
            <!-- Edition surface wins (klassikaal or online-with-edition), OR
                 klassikaal with no active editions (info-only). -->
            <div class="max-w-3xl space-y-12">
                <?php
                stridence_template_part('templates/course/content', null, [
                    'course_id' => $course_id,
                    'is_online' => $is_online,
                    'editions'  => $editions,
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

    <?php if ($show_self_enroll_sidebar) : ?>
        <?php
        stridence_template_part('templates/course/mobile-cta', null, [
            'course_id'              => $course_id,
            'is_online'              => true,
            'enrollment_url'         => '',
            'user_enrolled'          => false,
            'primary_edition_id'     => 0,
            'primary_edition_status' => null,
        ]);
        ?>
    <?php endif; ?>
</article>

<?php get_footer(); ?>
