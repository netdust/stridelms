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
use Stride\Modules\Edition\EditionService;
use Stride\Modules\Enrollment\RegistrationRepository;
use Stride\Modules\User\UserDashboardService;

$user    = $args['user'] ?? wp_get_current_user();
$user_id = $user->ID;

// ── Certificates ────────────────────────────────────────────
$registrationRepo  = ntdst_get(RegistrationRepository::class);
$editionService    = ntdst_get(EditionService::class);

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
    $edition    = $editionService->getEdition($edition_id);

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
        if (($cert['course_id'] ?? 0) === $courseId) {
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
    <div>
        <h3 class="text-xs font-semibold text-text-muted uppercase tracking-wider mb-3">
            <?php esc_html_e('Certificaten', 'stridence'); ?>
        </h3>
        <?php if (!empty($certificates)) : ?>
            <div class="bg-surface-card rounded-lg border border-border/60 divide-y divide-border/60">
                <?php foreach ($certificates as $cert) : ?>
                    <div class="list-item-static">
                        <?php echo stridence_icon('award', 'w-5 h-5 text-accent shrink-0'); ?>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-medium text-text truncate">
                                <?php echo esc_html($cert['course_title']); ?>
                            </p>
                            <?php if ($cert['completed_at']) : ?>
                                <p class="text-xs text-text-muted">
                                    <?php
                                    printf(
                                        esc_html__('Behaald op %s', 'stridence'),
                                        esc_html(stride_format_date($cert['completed_at']))
                                    );
                                    ?>
                                </p>
                            <?php endif; ?>
                        </div>
                        <a href="<?php echo esc_url($cert['certificate_url']); ?>"
                           target="_blank"
                           rel="noopener"
                           class="btn-ghost btn-sm">
                            <?php echo stridence_icon('download', 'w-4 h-4'); ?>
                            PDF
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else : ?>
            <div class="text-sm text-text-muted px-4 py-6 text-center bg-surface-card rounded-lg border border-border/60">
                <?php esc_html_e('Nog geen certificaten beschikbaar', 'stridence'); ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Offertes -->
    <div>
        <h3 class="text-xs font-semibold text-text-muted uppercase tracking-wider mb-3">
            <?php esc_html_e('Offertes', 'stridence'); ?>
        </h3>
        <?php if (!empty($all_quotes)) : ?>
            <div class="bg-surface-card rounded-lg border border-border/60 divide-y divide-border/60">
                <?php foreach ($all_quotes as $quote) : ?>
                    <div class="list-item-static">
                        <?php echo stridence_icon('file-text', 'w-5 h-5 text-text-muted shrink-0'); ?>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-medium text-text truncate">
                                <?php
                                printf(
                                    esc_html__('Offerte #%s', 'stridence'),
                                    esc_html($quote['quote_number'])
                                );
                                ?>
                            </p>
                            <?php if ($quote['created_at']) : ?>
                                <p class="text-xs text-text-muted">
                                    <?php echo esc_html(stride_format_date($quote['created_at'])); ?>
                                </p>
                            <?php endif; ?>
                        </div>
                        <a href="<?php echo esc_url(add_query_arg([
                            'action'   => 'stride_quote_pdf',
                            'quote_id' => $quote['id'],
                            'nonce'    => wp_create_nonce('stride_quote_pdf'),
                        ], admin_url('admin-ajax.php'))); ?>"
                           target="_blank"
                           rel="noopener"
                           class="btn-ghost btn-sm">
                            <?php echo stridence_icon('download', 'w-4 h-4'); ?>
                            PDF
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else : ?>
            <div class="text-sm text-text-muted px-4 py-6 text-center bg-surface-card rounded-lg border border-border/60">
                <?php esc_html_e('Nog geen offertes beschikbaar', 'stridence'); ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Facturen (future placeholder) -->
    <div>
        <h3 class="text-xs font-semibold text-text-muted uppercase tracking-wider mb-3">
            <?php esc_html_e('Facturen', 'stridence'); ?>
        </h3>
        <div class="text-sm text-text-muted px-4 py-6 text-center bg-surface-card rounded-lg border border-border/60">
            <?php esc_html_e('Nog geen facturen beschikbaar', 'stridence'); ?>
        </div>
    </div>
</div>
