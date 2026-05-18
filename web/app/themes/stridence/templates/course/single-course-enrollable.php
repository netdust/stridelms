<?php
/**
 * Course-as-Enrollable Template
 *
 * Rendered when /vormingen/<course-slug>/ resolves to a pure-LD course
 * (no edition exists for the course). Same shape as single-vad_edition.php
 * — header, content, sidebar with CTA, mobile sticky — but sourced from
 * the LD course since there's no edition.
 *
 * Routed by Stride\Modules\Edition\EditionRouter.
 *
 * @package stridence
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

$course_id = get_the_ID();
$is_online = true; // /vormingen/<course>/ is only used for pure-LD online courses

$breadcrumbs = [
    ['label' => __('Opleidingen', 'stridence'), 'url' => get_post_type_archive_link('sfwd-courses')],
    ['label' => get_the_title(), 'url' => get_permalink($course_id)],
    ['label' => __('Inschrijven', 'stridence')],
];

get_header();
?>

<article <?php post_class('pb-12 lg:pb-16'); ?>>
    <?php
    stridence_template_part('templates/course/header', null, [
        'course_id'   => $course_id,
        'breadcrumbs' => $breadcrumbs,
        'is_online'   => true,
        'editions'    => [],
    ]);
    ?>

    <?php
    stridence_template_part('templates/course/tabs', null, [
        'is_online' => true,
    ]);
    ?>

    <div class="container py-8 lg:py-12">
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
    </div>

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
</article>

<?php get_footer(); ?>
