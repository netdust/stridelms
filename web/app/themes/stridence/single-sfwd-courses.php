<?php
/**
 * Course Detail Template
 *
 * Single template for LearnDash courses (sfwd-courses post type).
 *
 * /opleidingen/<course-slug>/ is the LD container view: course description,
 * lessons, and the list of editions you can enroll in. **No CTAs here** —
 * enrollment lives on /vormingen/<slug>/. For pure-LD online courses (no
 * edition) the editions list renders a single "Direct inschrijven" row
 * pointing at /vormingen/<course-slug>/.
 *
 * @package stridence
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

use Stride\Modules\Edition\EditionRepository;

$course_id = get_the_ID();
$is_online = stridence_is_online_course($course_id);

// Fetch editions for the inline list. Status filtering happens in
// templates/course/editions-list.php.
$editions = ntdst_get(EditionRepository::class)->findByCourse($course_id);

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
        <div class="max-w-3xl space-y-12">
            <?php
            stridence_template_part('templates/course/content', null, [
                'course_id' => $course_id,
                'is_online' => $is_online,
                'editions'  => $editions,
            ]);
            ?>
        </div>
    </div>
</article>

<?php get_footer(); ?>
