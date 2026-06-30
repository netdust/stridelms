<?php
/**
 * Enrollment Form Template — Orchestrator
 *
 * Slim orchestrator: fetches data, groups fields by step, includes partials.
 *
 * @var int    $item_id   Edition or Trajectory ID
 * @var string $item_type 'edition' or 'trajectory'
 * @var array  $item_data Pre-fetched item data (title, price, etc.)
 */

use Stride\Modules\Edition\EditionRepository;
use Stride\Modules\Edition\EditionService;
use Stride\Modules\Questionnaire\QuestionnaireRepository;
use Stride\Modules\Trajectory\TrajectoryService;

if (!is_user_logged_in()) {
    wp_redirect(wp_login_url(get_permalink()));
    exit;
}

$item_id         = $args['item_id'] ?? 0;
$item_type       = $args['item_type'] ?? 'edition';
$item_data       = $args['item_data'] ?? [];
$enrollment_mode = $args['enrollment_mode'] ?? 'enrollment';
$is_online       = $args['is_online'] ?? false;
$form_type       = $args['form_type'] ?? 'default';

// Fallback detection from course category
if (!$is_online && $item_type === 'edition' && $item_id) {
    $is_online = ntdst_get(EditionService::class)->isOnline($item_id);
}

$current_user = wp_get_current_user();

// Pre-fill user data — pre-assembled by stride-core. Personal `organisation`
// stays distinct from billing `company` (no cross-fallback); `phone` falls
// back to `billing_phone` inside the service.
$user_meta = ntdst_get(\Stride\Modules\User\UserDashboardService::class)->getEnrollmentPrefill($current_user->ID);

// Fetch edition/course details
$edition_data = [];
$course_data  = [];

if ($item_type === 'edition' && $item_id) {
    $editionService    = ntdst_get(EditionService::class);
    $editionRepository = ntdst_get(EditionRepository::class);
    $edition           = $editionRepository->find($item_id);

    if (!is_wp_error($edition)) {
        $course_id = $editionService->getCourseId($item_id);
        $price     = $editionService->getPrice($item_id, $current_user->ID);

        $edition_data = [
            'title'      => ($course_id ? get_the_title($course_id) : '') ?: get_the_title($item_id),
            'start_date' => $editionRepository->getField($item_id, 'start_date', ''),
            'venue'      => $editionRepository->getField($item_id, 'venue', ''),
            'price'      => $price ? $price->format() : '',
            'sessions'   => [],
        ];

        $sessionService = ntdst_get(\Stride\Modules\Edition\SessionService::class);
        $sessions       = $sessionService->getSessionsForEdition($item_id);
        if (!empty($sessions)) {
            foreach ($sessions as $session) {
                $edition_data['sessions'][] = [
                    'date'       => $session['date'] ?? '',
                    'start_time' => $session['start_time'] ?? '',
                    'end_time'   => $session['end_time'] ?? '',
                ];
            }
        }

        if ($course_id) {
            $course_data = [
                'id'      => $course_id,
                'title'   => get_the_title($course_id),
                'excerpt' => get_the_excerpt($course_id) ?: wp_trim_words(get_post_field('post_content', $course_id), 30),
                'url'     => get_permalink($item_id),
            ];
        }
    }
} elseif ($item_type === 'trajectory' && $item_id) {
    // Trajectory branch — parallel to the edition branch above. Without this
    // $edition_data stayed empty for trajectories, so the price never reached
    // the sidebar / confirmation step (both gated on $edition_data['price']).
    $trajectory = ntdst_get(TrajectoryService::class)->getTrajectory($item_id);

    if ($trajectory) {
        // getTrajectory() returns price as a float in EUROS; stride_format_money
        // takes cents — match the public template (single-vad_trajectory.php:159).
        $price = (float) ($trajectory['price'] ?? 0);

        $edition_data = [
            'title'      => $trajectory['title'] ?? get_the_title($item_id),
            'start_date' => '',
            'venue'      => '',
            'price'      => $price > 0 ? stride_format_money((int) round($price * 100)) : '',
            'sessions'   => [],
        ];
    }
}

// Get field groups, filtered by step (works for editions and trajectories)
$field_groups     = [];
$personal_groups  = [];
$billing_groups   = [];

if ($item_id) {
    try {
        $post_type         = $item_type === 'trajectory' ? 'vad_trajectory' : 'vad_edition';
        $questionnaireRepo = ntdst_get(QuestionnaireRepository::class);
        $personal_groups   = $questionnaireRepo->getGroupsForStage($item_id, 'enrollment_personal', $post_type);
        $billing_groups    = $questionnaireRepo->getGroupsForStage($item_id, 'enrollment_billing', $post_type);
        $field_groups      = array_merge($personal_groups, $billing_groups);
    } catch (\Exception $e) {
        // Service not available
    }
}

// Enrich item_data with edition details for the confirmation step
if (!empty($edition_data['title'])) {
    $item_data['title'] = $edition_data['title'];
}
if (!empty($edition_data['start_date'])) {
    $item_data['date'] = stride_format_date($edition_data['start_date']);
}
if (!empty($edition_data['venue'])) {
    $item_data['venue'] = $edition_data['venue'];
}
if (!empty($edition_data['price'])) {
    $item_data['priceFormatted'] = $edition_data['price'];
}

// Alpine config
$alpine_config = json_encode([
    'itemId'         => $item_id,
    'itemType'       => $item_type,
    'itemData'       => $item_data,
    'userEmail'      => $current_user->user_email,
    'prefill'        => $user_meta,
    'fieldGroups'    => $field_groups,
    'enrollmentMode' => $enrollment_mode,
    'isOnline'       => $is_online,
    'formType'       => $form_type,
]);
?>

<?php
$enrollment_js = get_stylesheet_directory() . '/templates/forms/enrollment.js';
$enrollment_js_ver = file_exists($enrollment_js) ? filemtime($enrollment_js) : '1';
?>
<script src="<?= esc_url(get_stylesheet_directory_uri() . '/templates/forms/enrollment.js?v=' . $enrollment_js_ver) ?>"></script>

<div class="container py-8 lg:py-12"
     x-data="enrollmentForm(<?= esc_attr($alpine_config) ?>)">

    <?php stridence_template_part('templates/forms/enrollment/progress'); ?>

    <!-- Two Column Layout -->
    <div class="grid lg:grid-cols-3 gap-8 lg:gap-12">

        <!-- Main Form Column (2/3) -->
        <div class="lg:col-span-2">
            <?php $is_minimal = $form_type === 'minimal'; ?>
            <form @submit.prevent="submitForm" novalidate class="bg-surface-card rounded-[16px] shadow-card p-6 md:p-8">
                <?php if (!$is_online && !$is_minimal) : ?>
                    <?php stridence_template_part('templates/forms/enrollment/step-type'); ?>
                <?php endif; ?>
                <?php stridence_template_part('templates/forms/enrollment/step-personal', null, [
                    'personal_groups' => $personal_groups,
                    'is_online'       => $is_online,
                    'form_type'       => $form_type,
                ]); ?>
                <?php if (!$is_online && !$is_minimal) : ?>
                    <?php stridence_template_part('templates/forms/enrollment/step-billing', null, [
                        'billing_groups' => $billing_groups,
                    ]); ?>
                <?php endif; ?>
                <?php stridence_template_part('templates/forms/enrollment/step-confirm'); ?>
            </form>
        </div>

        <!-- Sidebar (1/3) -->
        <div class="lg:col-span-1">
            <?php stridence_template_part('templates/forms/enrollment/sidebar', null, [
                'edition_data' => $edition_data,
                'course_data'  => $course_data,
            ]); ?>
        </div>
    </div>

    <?php stridence_template_part('templates/forms/enrollment/faq'); ?>
</div><!-- /.container -->

<?php stridence_template_part('templates/forms/enrollment/faq-contact'); ?>
