<?php
/**
 * Dashboard Tab: Certificaten (Certificates)
 *
 * Shows user's earned certificates from completed courses.
 * Uses CompletionService and LMSAdapter for data access.
 *
 * @param array $args {
 *     @type WP_User $user Current user object
 * }
 * @package stridence
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

use Stride\Contracts\LMSAdapterInterface;
use Stride\Domain\RegistrationStatus;
use Stride\Modules\Completion\CompletionService;
use Stride\Modules\Edition\EditionService;
use Stride\Modules\Enrollment\RegistrationRepository;

$user    = $args['user'] ?? wp_get_current_user();
$user_id = $user->ID;

// Get services
$registrationRepo  = ntdst_get(RegistrationRepository::class);
$editionService    = ntdst_get(EditionService::class);
$completionService = ntdst_get(CompletionService::class);
$lmsAdapter        = ntdst_get(LMSAdapterInterface::class);

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
    $edition    = $editionService->getEdition($edition_id);

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
    $certificate_url = $lmsAdapter->getCertificateLink($user_id, $course_id);

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

// Sort by completion date (newest first)
usort($certificates, fn($a, $b) => strcmp($b['completed_at'], $a['completed_at']));
?>

<div class="space-y-6">
    <section>
        <h2 class="font-heading text-xl font-bold text-text mb-4">
            <?php esc_html_e('Mijn certificaten', 'stridence'); ?>
        </h2>

        <?php if (!empty($certificates)) : ?>
            <div class="grid gap-4 sm:grid-cols-2">
                <?php foreach ($certificates as $cert) : ?>
                    <div class="card overflow-hidden">
                        <!-- Certificate Header -->
                        <div class="p-4 bg-gradient-to-r from-primary/10 to-primary/5">
                            <div class="flex items-center gap-3">
                                <div class="w-12 h-12 rounded-full bg-primary/20 flex items-center justify-center shrink-0">
                                    <?php echo stridence_icon('award', 'w-6 h-6 text-primary'); ?>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <h3 class="font-semibold text-text truncate">
                                        <?php echo esc_html($cert['course_title']); ?>
                                    </h3>
                                    <p class="text-sm text-text-muted truncate">
                                        <?php echo esc_html($cert['edition_title']); ?>
                                    </p>
                                </div>
                            </div>
                        </div>

                        <!-- Certificate Details -->
                        <div class="p-4 space-y-3">
                            <?php if ($cert['completed_at']) : ?>
                                <div class="flex items-center gap-2 text-sm text-text-muted">
                                    <?php echo stridence_icon('check-circle', 'w-4 h-4 text-green-500'); ?>
                                    <span>
                                        <?php
                                        printf(
                                            /* translators: %s: completion date */
                                            esc_html__('Behaald op %s', 'stridence'),
                                            esc_html(stride_format_date($cert['completed_at']))
                                        );
                                        ?>
                                    </span>
                                </div>
                            <?php endif; ?>

                            <?php if ($cert['has_certificate']) : ?>
                                <a href="<?php echo esc_url($cert['certificate_url']); ?>"
                                   class="btn-primary w-full text-sm"
                                   target="_blank"
                                   rel="noopener">
                                    <?php echo stridence_icon('download', 'w-4 h-4 mr-2'); ?>
                                    <?php esc_html_e('Download certificaat', 'stridence'); ?>
                                </a>
                            <?php else : ?>
                                <div class="flex items-center gap-2 text-sm text-amber-600 bg-amber-50 rounded-lg px-3 py-2">
                                    <?php echo stridence_icon('clock', 'w-4 h-4'); ?>
                                    <span><?php esc_html_e('Certificaat wordt gegenereerd...', 'stridence'); ?></span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else : ?>
            <?php
            get_template_part('partials/empty-state', null, [
                'icon'    => 'award',
                'title'   => __('Nog geen certificaten', 'stridence'),
                'message' => __('Je hebt nog geen certificaten behaald. Rond een opleiding succesvol af om je eerste certificaat te verdienen.', 'stridence'),
                'action'  => __('Bekijk mijn inschrijvingen', 'stridence'),
                'url'     => add_query_arg('tab', 'inschrijvingen', get_permalink()),
            ]);
            ?>
        <?php endif; ?>
    </section>

    <!-- Certificate Info -->
    <?php if (!empty($certificates)) : ?>
        <section class="card p-4">
            <div class="flex items-start gap-3">
                <div class="shrink-0 mt-0.5">
                    <?php echo stridence_icon('info', 'w-5 h-5 text-blue-500'); ?>
                </div>
                <div class="text-sm text-text-muted space-y-1">
                    <p class="font-medium text-text">
                        <?php esc_html_e('Over je certificaten', 'stridence'); ?>
                    </p>
                    <p>
                        <?php esc_html_e('Certificaten worden automatisch gegenereerd zodra je een opleiding hebt afgerond. Je kunt ze hier downloaden en gebruiken als bewijs van je behaalde competenties.', 'stridence'); ?>
                    </p>
                </div>
            </div>
        </section>
    <?php endif; ?>
</div>
