<?php
/**
 * Dashboard Tab: Downloads
 *
 * Aggregates all downloadable documents (certificates, quote PDFs, invoices)
 * in a single page grouped by type.
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
use Stride\Modules\Edition\EditionRepository;
use Stride\Modules\Edition\EditionService;
use Stride\Modules\Enrollment\RegistrationRepository;
use Stride\Modules\User\UserDashboardService;

$user    = $args['user'] ?? wp_get_current_user();
$user_id = $user->ID;

// ── Certificates ────────────────────────────────────────────
$registrationRepo  = ntdst_get(RegistrationRepository::class);
$editionService    = ntdst_get(EditionService::class);
$editionRepository = ntdst_get(EditionRepository::class);

$registrations = $registrationRepo->findByUser($user_id);

$certificates = [];

foreach ($registrations as $reg) {
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

    $course_id = $editionService->getCourseId($edition_id);
    if (!$course_id) {
        continue;
    }

    $course = get_post($course_id);
    if (!$course) {
        continue;
    }

    $certificate_url = LearnDashHelper::getCertificateLink($course_id, $user_id);

    $editionModel = ntdst_data()->get('vad_edition');
    $start_date   = $editionModel->getMeta($edition_id, 'start_date', '');

    $completed_at = $reg->completed_at ?? '';
    if (empty($completed_at) && $start_date) {
        $end_date = $editionModel->getMeta($edition_id, 'end_date', '');
        $completed_at = $end_date ?: $start_date;
    }

    if (!empty($certificate_url)) {
        $certificates[] = [
            'course_id'       => $course_id,
            'course_title'    => $course->post_title,
            'completed_at'    => $completed_at,
            'certificate_url' => $certificate_url,
        ];
    }
}

// Online course certificates
$enrolled_course_ids = LearnDashHelper::getEnrolledCourses($user_id);

foreach ($enrolled_course_ids as $courseId) {
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

    if (!LearnDashHelper::isComplete($courseId, $user_id)) {
        continue;
    }

    $course = get_post($courseId);
    if (!$course) {
        continue;
    }

    $certificate_url = LearnDashHelper::getCertificateLink($courseId, $user_id);
    if (empty($certificate_url)) {
        continue;
    }

    $completion_date = LearnDashHelper::getCompletionDate($courseId, $user_id);

    $certificates[] = [
        'course_title'    => $course->post_title,
        'completed_at'    => $completion_date ? date('Y-m-d', $completion_date) : '',
        'certificate_url' => $certificate_url,
    ];
}

// Sort by completion date (newest first)
usort($certificates, fn($a, $b) => strcmp($b['completed_at'], $a['completed_at']));

// ── Quote PDFs ──────────────────────────────────────────────
$dashboardService = ntdst_get(UserDashboardService::class);
$quoteData  = $dashboardService->getQuoteData($user_id);
$all_quotes = array_merge($quoteData['active'], $quoteData['cancelled']);
?>

<div class="space-y-8">
    <!-- Certificaten -->
    <section>
        <h3 class="text-[11px] font-semibold text-text-muted uppercase tracking-wider mb-3">
            <?php esc_html_e('Certificaten', 'stridence'); ?>
        </h3>

        <?php if (!empty($certificates)) : ?>
            <div class="space-y-[10px]">
                <?php foreach ($certificates as $cert) : ?>
                    <div class="flex items-center gap-3.5 bg-surface-card rounded-[12px] shadow-card p-4 flex-wrap">
                        <!-- ✓ tile: badge-online palette -->
                        <span class="w-[38px] h-[38px] rounded-[10px] bg-badge-online-bg text-badge-online-text flex items-center justify-center shrink-0 text-[14px] font-extrabold">
                            ✓
                        </span>

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

                        <a href="<?php echo esc_url($cert['certificate_url']); ?>"
                           target="_blank"
                           rel="noopener"
                           class="btn-ghost btn-sm shrink-0">
                            <?php esc_html_e('Download', 'stridence'); ?>
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else : ?>
            <?php
            stridence_template_part('partials/empty-state', null, [
                'icon'    => 'award',
                'title'   => __('Nog geen certificaten', 'stridence'),
                'message' => __('Rond een opleiding af om je certificaten hier te zien.', 'stridence'),
            ]);
            ?>
        <?php endif; ?>
    </section>

    <!-- Offertes -->
    <section>
        <h3 class="text-[11px] font-semibold text-text-muted uppercase tracking-wider mb-3">
            <?php esc_html_e('Offertes', 'stridence'); ?>
        </h3>

        <?php if (!empty($all_quotes)) : ?>
            <div class="space-y-[10px]">
                <?php foreach ($all_quotes as $quote) : ?>
                    <div class="flex items-center gap-3.5 bg-surface-card rounded-[12px] shadow-card p-4 flex-wrap">
                        <!-- PDF tile: surface-alt + file icon -->
                        <span class="w-[38px] h-[38px] rounded-[10px] bg-surface-alt text-text-muted flex items-center justify-center shrink-0 text-[10px] font-extrabold">
                            PDF
                        </span>

                        <div class="flex-1 min-w-[200px]">
                            <div class="text-[14px] font-bold text-text leading-snug">
                                <?php
                                printf(
                                    /* translators: %s: quote number */
                                    esc_html__('Offerte #%s', 'stridence'),
                                    esc_html($quote['quote_number']),
                                );
                                ?>
                            </div>
                            <div class="text-[12px] text-text-faint mt-[2px]">
                                <?php echo esc_html($quote['title']); ?>
                                <?php if ($quote['created_at']) : ?>
                                    · <?php echo esc_html(stride_format_date($quote['created_at'])); ?>
                                <?php endif; ?>
                            </div>
                        </div>

                        <a href="<?php echo esc_url(add_query_arg([
                            'action'   => 'stride_quote_pdf',
                            'quote_id' => $quote['id'],
                            'nonce'    => wp_create_nonce('stride_quote_pdf'),
                        ], admin_url('admin-ajax.php'))); ?>"
                           target="_blank"
                           rel="noopener"
                           class="btn-ghost btn-sm shrink-0">
                            <?php esc_html_e('Download', 'stridence'); ?>
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else : ?>
            <?php
            stridence_template_part('partials/empty-state', null, [
                'icon'    => 'file-text',
                'title'   => __('Nog geen offertes', 'stridence'),
                'message' => __('Je offertes verschijnen hier zodra ze beschikbaar zijn.', 'stridence'),
            ]);
            ?>
        <?php endif; ?>
    </section>

    <!-- Facturen (future placeholder) -->
    <section>
        <h3 class="text-[11px] font-semibold text-text-muted uppercase tracking-wider mb-3">
            <?php esc_html_e('Facturen', 'stridence'); ?>
        </h3>
        <?php
        stridence_template_part('partials/empty-state', null, [
            'icon'    => 'file',
            'title'   => __('Nog geen facturen', 'stridence'),
            'message' => __('Facturen worden hier weergegeven zodra ze beschikbaar zijn.', 'stridence'),
        ]);
        ?>
    </section>
</div>
