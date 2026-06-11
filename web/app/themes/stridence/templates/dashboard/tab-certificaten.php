<?php
/**
 * Dashboard Tab: Certificaten (Certificates)
 *
 * Shows user's earned certificates from completed courses.
 * Uses EditionCompletion and LMSAdapter for data access.
 *
 * @param array $args {
 *     @type WP_User $user Current user object
 * }
 * @package stridence
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

use Stride\Domain\RegistrationStatus;
use Stride\Integrations\LearnDash\LearnDashHelper;
use Stride\Modules\Edition\EditionCompletion;
use Stride\Modules\Edition\EditionRepository;
use Stride\Modules\Edition\EditionService;
use Stride\Modules\Enrollment\RegistrationRepository;

$user    = $args['user'] ?? wp_get_current_user();
$user_id = $user->ID;

$registrationRepo  = ntdst_get(RegistrationRepository::class);
$editionService    = ntdst_get(EditionService::class);
$editionRepository = ntdst_get(EditionRepository::class);
$completionService = ntdst_get(EditionCompletion::class);

// Get completed registrations
$registrations = $registrationRepo->findByUser($user_id);

// Build certificates list
$certificates = [];

foreach ($registrations as $reg) {
    // Skip non-edition registrations and non-completed
    if (empty($reg->edition_id)) {
        continue;
    }

    $status = RegistrationStatus::tryFrom($reg->status ?? '');
    if ($status !== RegistrationStatus::Completed) {
        continue;
    }

    $edition_id = (int) $reg->edition_id;
    $edition    = $editionRepository->find($edition_id);

    if (is_wp_error($edition)) {
        continue;
    }

    // Get course for this edition
    $course_id = $editionService->getCourseId($edition_id);
    if (!$course_id) {
        continue;
    }

    $course = get_post($course_id);
    if (!$course) {
        continue;
    }

    // Get certificate link from LearnDash
    $certificate_url = LearnDashHelper::getCertificateLink($course_id, $user_id);

    // Get edition meta for additional info
    $editionModel = ntdst_data()->get('vad_edition');
    $start_date   = $editionModel->getMeta($edition_id, 'start_date', '');

    // Calculate completion date (use registration completed_at or estimate from start_date)
    $completed_at = $reg->completed_at ?? '';
    if (empty($completed_at) && $start_date) {
        // Estimate: completion is typically end of edition
        $end_date = $editionModel->getMeta($edition_id, 'end_date', '');
        $completed_at = $end_date ?: $start_date;
    }

    $certificates[] = [
        'edition_id'      => $edition_id,
        'course_id'       => $course_id,
        'course_title'    => $course->post_title,
        'edition_title'   => $edition->post_title,
        'completed_at'    => $completed_at,
        'certificate_url' => $certificate_url,
        'has_certificate' => !empty($certificate_url),
    ];
}

// ── Online course certificates ──────────────────────────────
$enrolled_course_ids = LearnDashHelper::getEnrolledCourses($user_id);

foreach ($enrolled_course_ids as $courseId) {
    // Skip if already covered by an edition certificate above
    $already_covered = false;
    foreach ($certificates as $cert) {
        if ((int) ($cert['course_id'] ?? 0) === $courseId) {
            $already_covered = true;
            break;
        }
    }
    if ($already_covered) {
        continue;
    }

    // Only include online/e-learning/webinar courses (matches archive)
    $formats = get_the_terms($courseId, 'stride_format');
    $is_online = false;
    if ($formats && !is_wp_error($formats)) {
        foreach ($formats as $fmt) {
            if (in_array($fmt->slug, ['online', 'e-learning', 'webinar'], true)) {
                $is_online = true;
                break;
            }
        }
    }
    if (!$is_online) {
        continue;
    }

    // Check completion
    if (!LearnDashHelper::isComplete($courseId, $user_id)) {
        continue;
    }

    $course = get_post($courseId);
    if (!$course) {
        continue;
    }

    $certificate_url = LearnDashHelper::getCertificateLink($courseId, $user_id);
    $completion_date = LearnDashHelper::getCompletionDate($courseId, $user_id);

    $certificates[] = [
        'edition_id'      => 0,
        'course_id'       => $courseId,
        'course_title'    => $course->post_title,
        'edition_title'   => __('Online cursus', 'stridence'),
        'completed_at'    => $completion_date ? date('Y-m-d', $completion_date) : '',
        'certificate_url' => $certificate_url,
        'has_certificate' => !empty($certificate_url),
    ];
}

// Sort by completion date (newest first)
usort($certificates, fn($a, $b) => strcmp($b['completed_at'], $a['completed_at']));
?>

<div class="space-y-3">
    <?php if (!empty($certificates)) : ?>
        <?php foreach ($certificates as $cert) : ?>
            <div class="flex items-center gap-3.5 bg-surface-card rounded-[12px] shadow-card p-4 flex-wrap">
                <!-- ✓ tile: 38×38, badge-online palette, rounded-[10px] -->
                <span class="w-[38px] h-[38px] rounded-[10px] bg-badge-online-bg text-badge-online-text flex items-center justify-center shrink-0 text-[14px] font-extrabold">
                    ✓
                </span>

                <!-- Title + date -->
                <div class="flex-1 min-w-[200px]">
                    <div class="text-[14px] font-bold text-text leading-snug">
                        <?php echo esc_html($cert['course_title']); ?>
                    </div>
                    <?php if ($cert['completed_at']) : ?>
                        <div class="text-[12px] text-text-faint mt-[2px]">
                            <?php
                            printf(
                                /* translators: %s: completion date */
                                esc_html__('behaald op %s', 'stridence'),
                                esc_html(stride_format_date($cert['completed_at'])),
                            );
                            ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Download PDF button (existing link mechanism) -->
                <?php if ($cert['has_certificate']) : ?>
                    <a href="<?php echo esc_url($cert['certificate_url']); ?>"
                       class="btn-ghost btn-sm shrink-0"
                       target="_blank"
                       rel="noopener">
                        <?php esc_html_e('Download PDF', 'stridence'); ?>
                    </a>
                <?php else : ?>
                    <span class="text-[12px] text-text-muted shrink-0">
                        <?php esc_html_e('Certificaat wordt gegenereerd...', 'stridence'); ?>
                    </span>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>

    <?php else : ?>
        <?php
        stridence_template_part('partials/empty-state', null, [
            'icon'    => 'award',
            'title'   => __('Nog geen certificaten', 'stridence'),
            'message' => __('Je hebt nog geen certificaten behaald. Rond een opleiding succesvol af om je eerste certificaat te verdienen.', 'stridence'),
            'action'  => __('Bekijk mijn inschrijvingen', 'stridence'),
            'url'     => add_query_arg('tab', 'inschrijvingen', get_permalink()),
        ]);
        ?>
    <?php endif; ?>
</div>
