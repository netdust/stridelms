<?php
/**
 * Course Detail Template
 *
 * Single template for LearnDash courses (sfwd-courses post type).
 * - Online courses: Two-column layout with LD-native sidebar
 * - In-person courses: Two-column layout with edition sidebar
 *
 * @package stridence
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

use Stride\Modules\Edition\EditionService;
use Stride\Modules\Enrollment\EnrollmentService;

$course_id = get_the_ID();
$user_id   = get_current_user_id();

// Determine if course is online or in-person via stride_format taxonomy
$is_online = stridence_is_online_course($course_id);

// Get editions via EditionService (both online and in-person)
$editions = [];
$enrollment_url = '';
$user_enrolled = false;

$editionService = ntdst_get(EditionService::class);
$editions = $editionService->getEditionsForCourse($course_id);
$enrollmentService = $user_id ? ntdst_get(EnrollmentService::class) : null;

foreach ($editions as $edition) {
    $edition_id = (int) ($edition['id'] ?? $edition['ID'] ?? 0);
    if (!$edition_id) {
        continue;
    }

    if ($enrollmentService && $enrollmentService->isEnrolled($user_id, $edition_id)) {
        $user_enrolled = true;
    }

    // For online courses, only set enrollment URL when edition has a form configured
    if (!$enrollment_url && $editionService->canEnroll($edition_id)) {
        if (!$is_online || $editionService->hasEnrollmentForm($edition_id)) {
            $enrollment_url = stride_enrollment_url($edition_id);
        }
    }
}

// Breadcrumb items
$breadcrumbs = [
    ['label' => __('Opleidingen', 'stridence'), 'url' => get_post_type_archive_link('sfwd-courses')],
    ['label' => get_the_title()],
];

get_header();
?>

<article <?php post_class('pb-12 lg:pb-16'); ?>>
    <!-- Header Section -->
    <?php
    stridence_template_part('templates/course/header', null, [
        'course_id'   => $course_id,
        'breadcrumbs' => $breadcrumbs,
        'is_online'   => $is_online,
    ]);
    ?>

    <!-- Sticky Tab Bar -->
    <?php
    stridence_template_part('templates/course/tabs', null, [
        'is_online' => $is_online,
    ]);
    ?>

    <!-- Two Column Layout -->
    <div class="container py-8 lg:py-12">
        <div class="grid lg:grid-cols-3 gap-8 lg:gap-12">
            <!-- Main Content (2/3) -->
            <div class="lg:col-span-2 space-y-12">
                <?php
                stridence_template_part('templates/course/content', null, [
                    'course_id' => $course_id,
                    'is_online' => $is_online,
                ]);
                ?>
            </div>

            <!-- Sidebar (1/3) -->
            <div class="lg:col-span-1">
                <?php if ($is_online) : ?>
                    <?php
                    stridence_template_part('templates/course/sidebar-online', null, [
                        'course_id'      => $course_id,
                        'enrollment_url' => $enrollment_url,
                        'user_enrolled'  => $user_enrolled,
                    ]);
                    ?>
                <?php else : ?>
                    <?php
                    stridence_template_part('templates/course/sidebar-edition', null, [
                        'editions'  => $editions,
                        'course_id' => $course_id,
                    ]);
                    ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Mobile Sticky CTA -->
    <?php
    stridence_template_part('templates/course/mobile-cta', null, [
        'course_id'      => $course_id,
        'is_online'      => $is_online,
        'enrollment_url' => $enrollment_url,
        'user_enrolled'  => $user_enrolled,
    ]);
    ?>
</article>

<?php get_footer(); ?>
