<?php
/**
 * Edition Detail Template
 *
 * Single template for scheduled course editions (vad_edition post type).
 * Two-column layout with session list and sticky enrollment card.
 *
 * @package stridence
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

use Stride\Modules\Edition\EditionService;
use Stride\Modules\Edition\SessionService;
use Stride\Integrations\LearnDash\LearnDashHelper;

$edition_id = get_the_ID();

// Get services
$editionService = ntdst_get(EditionService::class);
$sessionService = ntdst_get(SessionService::class);

// Get edition data via service
$edition = $editionService->getEdition($edition_id);
if (is_wp_error($edition)) {
    stridence_template_part('partials/empty-state', null, [
        'icon'    => 'alert-circle',
        'title'   => __('Editie niet gevonden', 'stridence'),
        'message' => __('Deze editie bestaat niet of is verwijderd.', 'stridence'),
        'action'  => __('Naar opleidingen', 'stridence'),
        'url'     => get_post_type_archive_link('sfwd-courses'),
    ]);
    return;
}

// Get edition fields via service
$course_id  = $editionService->getCourseId($edition_id);
$course     = $course_id ? get_post($course_id) : null;
$status     = $editionService->getStatus($edition_id);
$price      = $editionService->getPrice($edition_id, get_current_user_id() ?: null);
$can_enroll  = $editionService->canEnroll($edition_id);
$capacity    = $editionService->getCapacity($edition_id);
$enrollmentService = ntdst_get(\Stride\Modules\Enrollment\EnrollmentService::class);
$is_enrolled = is_user_logged_in() && $enrollmentService->isEnrolled(get_current_user_id(), $edition_id);
$is_online = $editionService->isOnline($edition_id);

// Get user's registration (for pending tasks + session selections)
$has_pending_tasks = false;
$complete_url = null;
$selected_session_ids = [];
$reg = null;
if (is_user_logged_in()) {
    $reg = ntdst_get(\Stride\Modules\Enrollment\RegistrationRepository::class)
        ->findByUserAndEdition(get_current_user_id(), $edition_id);
    if ($reg) {
        $selected_session_ids = array_map('intval', $reg->selections ?? []);
        $complete_url = home_url('/vormingen/' . get_post_field('post_name', $edition_id) . '/voltooien/');
        if (!$is_enrolled && $reg->status === 'pending' && !empty($reg->completion_tasks)) {
            $has_pending_tasks = true;
        }
    }
}

// Enrolled CTA
$enrolled_cta = null;
if ($has_pending_tasks) {
    $enrolled_cta = ['label' => __('Voltooi inschrijving', 'stridence'), 'url' => $complete_url];
} elseif ($is_enrolled && $course_id) {
    // Certificate available → always show, regardless of format
    $certificate = LearnDashHelper::getCertificateLink($course_id);
    if ($certificate) {
        $enrolled_cta = ['label' => __('Certificaat bekijken', 'stridence'), 'url' => $certificate];
    } elseif ($is_online && LearnDashHelper::hasAccess($course_id)) {
        // Online/blended with LD content → show course action
        $ld_action = LearnDashHelper::getCourseAction($course_id);
        if (in_array($ld_action['action'], ['start', 'continue', 'view'], true)) {
            $enrolled_cta = ['label' => $ld_action['label'], 'url' => $ld_action['url']];
        }
    }
}

// Get raw meta fields via Data Manager
// Note: These could be added to EditionService if needed frequently
$editionModel = ntdst_data()->get('vad_edition');
$start_date   = $editionModel->getMeta($edition_id, 'start_date', '');
$venue        = $editionModel->getMeta($edition_id, 'venue', '');
$spots        = $editionModel->getMeta($edition_id, 'spots_remaining');

// Get sessions via SessionService and group by type
$all_sessions = $sessionService->getSessionsForEdition($edition_id);
$has_sessions = !empty($all_sessions);

// Split into scheduled (in_person, webinar) and online (online, assignment)
$scheduled_sessions = [];
$online_sessions = [];
foreach ($all_sessions as $session) {
    $type = \Stride\Domain\SessionType::tryFrom($session['type'] ?? 'in_person')
        ?? \Stride\Domain\SessionType::InPerson;
    if ($type->isScheduled()) {
        $scheduled_sessions[] = $session;
    } else {
        $online_sessions[] = $session;
    }
}

// Breadcrumb items
$breadcrumbs = [
    ['label' => __('Opleidingen', 'stridence'), 'url' => get_post_type_archive_link('sfwd-courses')],
];

if ($course) {
    $breadcrumbs[] = ['label' => get_the_title($course), 'url' => get_permalink($course)];
}

$breadcrumbs[] = ['label' => $start_date ? stride_format_date($start_date) : get_the_title()];

get_header();
?>

<article <?php post_class('pb-12 lg:pb-16'); ?>>
    <?php if (!empty($_GET['enrolled'])) : ?>
        <div class="container mt-6">
            <div class="flex items-center gap-3 p-4 rounded-lg bg-status-success/10 text-status-success-dark border border-status-success/20">
                <?php echo stridence_icon('check-circle', 'w-5 h-5 shrink-0'); ?>
                <p class="text-sm font-medium"><?php esc_html_e('Je bent succesvol ingeschreven!', 'stridence'); ?></p>
            </div>
        </div>
    <?php endif; ?>

    <!-- Header Section -->
    <div class="bg-surface-alt border-b border-border">
        <div class="container py-8 lg:py-12">
            <?php
            stridence_template_part('partials/breadcrumb', null, [
                'items' => $breadcrumbs,
            ]);
            ?>

            <!-- Format badge -->
            <div class="flex items-center gap-2 mb-4">
                <span class="inline-flex items-center gap-1 px-2 py-1 rounded-full text-xs font-medium bg-primary text-text-inverse">
                    <?php echo stridence_icon('map-pin', 'w-3 h-3'); ?>
                    <?php esc_html_e('Klassikaal', 'stridence'); ?>
                </span>
            </div>

            <div class="flex flex-wrap items-start gap-4 mb-4">
                <h1 class="font-heading text-3xl lg:text-4xl font-bold text-text flex-1">
                    <?php echo $course ? esc_html(get_the_title($course)) : the_title(); ?>
                </h1>
                <?php
                stridence_template_part('partials/badge-status', null, [
                    'status' => $status->value,
                    'spots'  => $spots,
                ]);
                ?>
            </div>

            <div class="flex flex-wrap gap-6 text-text-muted">
                <?php if ($start_date) : ?>
                    <span class="flex items-center gap-2">
                        <?php echo stridence_icon('calendar', 'w-5 h-5'); ?>
                        <?php echo esc_html(stride_format_date($start_date)); ?>
                    </span>
                <?php endif; ?>

                <?php if ($venue) : ?>
                    <span class="flex items-center gap-2">
                        <?php echo stridence_icon('map-pin', 'w-5 h-5'); ?>
                        <?php echo esc_html($venue); ?>
                    </span>
                <?php endif; ?>

                <?php if (!$price->isZero()) : ?>
                    <span class="flex items-center gap-2 font-semibold text-text">
                        <?php echo stridence_icon('receipt', 'w-5 h-5 text-text-muted'); ?>
                        <?php echo esc_html($price->format()); ?>
                    </span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Sticky Tab Bar -->
    <?php
    stridence_template_part('templates/edition/tabs', null, [
        'has_sessions' => $has_sessions,
    ]);
    ?>

    <!-- Two Column Layout -->
    <div class="container py-8 lg:py-12">
        <div class="grid lg:grid-cols-3 gap-8 lg:gap-12">
            <!-- Main Content (2/3) -->
            <div class="lg:col-span-2 space-y-12">
                <!-- Overzicht Section -->
                <section id="overzicht" class="scroll-mt-32">
                    <?php if ($course) : ?>
                        <div class="prose-stride max-w-none">
                            <?php echo apply_filters('the_content', $course->post_content); ?>
                        </div>
                    <?php else : ?>
                        <p class="text-text-muted">
                            <?php esc_html_e('Beschrijving wordt binnenkort toegevoegd.', 'stridence'); ?>
                        </p>
                    <?php endif; ?>
                </section>

                <!-- Sessies Section -->
                <?php if ($has_sessions) : ?>
                <section id="sessies" class="scroll-mt-32">
                    <h2 class="font-heading text-2xl font-bold text-text mb-6">
                        <?php esc_html_e('Sessies', 'stridence'); ?>
                    </h2>

                    <?php if (!empty($scheduled_sessions)) : ?>
                        <div class="card divide-y divide-border">
                            <?php
                            $hasSelections = !empty($selected_session_ids);
                            foreach ($scheduled_sessions as $session) :
                                $isSelected = in_array((int) $session['id'], $selected_session_ids, true);
                                $notChosen = $hasSelections && !$isSelected && !empty($session['slot']);
                            ?>
                                <?php
                                stridence_template_part('partials/session-row', null, [
                                    'session'    => (object) $session,
                                    'attendance' => null,
                                    'selected'   => $isSelected,
                                    'not_chosen' => $notChosen,
                                ]);
                                ?>
                            <?php endforeach; ?>
                        </div>
                        <?php if (!empty($selected_session_ids) && $complete_url) : ?>
                            <div class="mt-2 text-right">
                                <a href="<?php echo esc_url($complete_url); ?>"
                                   class="text-sm text-primary hover:underline inline-flex items-center gap-1">
                                    <?php echo stridence_icon('edit-2', 'w-3.5 h-3.5'); ?>
                                    <?php esc_html_e('Sessiekeuze wijzigen', 'stridence'); ?>
                                </a>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php if (!empty($online_sessions)) : ?>
                        <h3 class="font-heading text-lg font-semibold text-text mt-8 mb-4">
                            <?php esc_html_e('Online modules', 'stridence'); ?>
                        </h3>
                        <div class="card divide-y divide-border">
                            <?php foreach ($online_sessions as $session) :
                                $isSelected = in_array((int) $session['id'], $selected_session_ids, true);
                                $notChosen = $hasSelections && !$isSelected && !empty($session['slot']);
                            ?>
                                <?php
                                stridence_template_part('partials/session-row', null, [
                                    'session'    => (object) $session,
                                    'attendance' => null,
                                    'selected'   => $isSelected,
                                    'not_chosen' => $notChosen,
                                ]);
                                ?>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>
                <?php endif; ?>

                <!-- Sprekers Section -->
                <?php $speakers = $editionModel->getMeta($edition_id, 'speakers', ''); ?>
                <?php if ($speakers) : ?>
                <section id="sprekers" class="scroll-mt-32">
                    <h2 class="font-heading text-2xl font-bold text-text mb-6">
                        <?php esc_html_e('Sprekers', 'stridence'); ?>
                    </h2>
                    <p class="text-text-muted">
                        <?php echo esc_html($speakers); ?>
                    </p>
                </section>
                <?php endif; ?>

                <!-- Praktisch Section -->
                <section id="praktisch" class="scroll-mt-32">
                    <h2 class="font-heading text-2xl font-bold text-text mb-6">
                        <?php esc_html_e('Praktische informatie', 'stridence'); ?>
                    </h2>
                    <div class="grid sm:grid-cols-2 gap-4">
                        <div class="card-bordered p-5">
                            <h3 class="font-semibold text-text mb-2 flex items-center gap-2">
                                <?php echo stridence_icon('users', 'w-5 h-5 text-primary'); ?>
                                <?php esc_html_e('Doelgroep', 'stridence'); ?>
                            </h3>
                            <p class="text-text-muted text-sm">
                                <?php esc_html_e('Zorgprofessionals', 'stridence'); ?>
                            </p>
                        </div>
                        <div class="card-bordered p-5">
                            <h3 class="font-semibold text-text mb-2 flex items-center gap-2">
                                <?php echo stridence_icon('award', 'w-5 h-5 text-primary'); ?>
                                <?php esc_html_e('Accreditatie', 'stridence'); ?>
                            </h3>
                            <p class="text-text-muted text-sm">
                                <?php esc_html_e('In aanvraag', 'stridence'); ?>
                            </p>
                        </div>
                        <div class="card-bordered p-5">
                            <h3 class="font-semibold text-text mb-2 flex items-center gap-2">
                                <?php echo stridence_icon('map-pin', 'w-5 h-5 text-primary'); ?>
                                <?php esc_html_e('Locatie', 'stridence'); ?>
                            </h3>
                            <p class="text-text-muted text-sm">
                                <?php echo $venue ? esc_html($venue) : esc_html__('Wordt nog bekendgemaakt', 'stridence'); ?>
                            </p>
                        </div>
                        <div class="card-bordered p-5">
                            <h3 class="font-semibold text-text mb-2 flex items-center gap-2">
                                <?php echo stridence_icon('calendar', 'w-5 h-5 text-primary'); ?>
                                <?php esc_html_e('Startdatum', 'stridence'); ?>
                            </h3>
                            <p class="text-text-muted text-sm">
                                <?php echo $start_date ? esc_html(stride_format_date($start_date)) : esc_html__('Wordt nog bekendgemaakt', 'stridence'); ?>
                            </p>
                        </div>
                    </div>
                </section>
            </div>

            <!-- Sidebar (1/3) - Enrollment Card -->
            <div class="lg:col-span-1">
                <div class="card p-6 sticky top-24">
                    <h3 class="font-heading font-semibold text-lg mb-4">
                        <?php esc_html_e('Inschrijven', 'stridence'); ?>
                    </h3>

                    <div class="space-y-3 mb-6">
                        <div class="flex justify-between">
                            <span class="text-text-muted"><?php esc_html_e('Prijs', 'stridence'); ?></span>
                            <span class="font-semibold">
                                <?php
                                if (!$price->isZero()) {
                                    echo esc_html($price->format());
                                } else {
                                    esc_html_e('Op aanvraag', 'stridence');
                                }
                                ?>
                            </span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-text-muted"><?php esc_html_e('Locatie', 'stridence'); ?></span>
                            <span><?php echo $venue ? esc_html($venue) : '-'; ?></span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-text-muted"><?php esc_html_e('Startdatum', 'stridence'); ?></span>
                            <span><?php echo $start_date ? esc_html(stride_format_date($start_date)) : '-'; ?></span>
                        </div>
                        <?php if ($spots !== null && $spots !== '' && $can_enroll) : ?>
                            <div class="flex justify-between">
                                <span class="text-text-muted"><?php esc_html_e('Beschikbaar', 'stridence'); ?></span>
                                <span>
                                    <?php
                                    printf(
                                        esc_html(_n('%d plaats', '%d plaatsen', (int) $spots, 'stridence')),
                                        (int) $spots
                                    );
                                    ?>
                                </span>
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php if ($enrolled_cta) : ?>
                        <a href="<?php echo esc_url($enrolled_cta['url']); ?>" class="btn-primary w-full text-center">
                            <?php echo esc_html($enrolled_cta['label']); ?>
                        </a>
                    <?php elseif ($is_enrolled) : ?>
                        <span class="btn-secondary w-full text-center block">
                            <?php esc_html_e('Ingeschreven', 'stridence'); ?>
                        </span>
                    <?php elseif ($can_enroll) : ?>
                        <a href="<?php echo esc_url(stride_enrollment_url($edition_id)); ?>" class="btn-primary w-full text-center">
                            <?php esc_html_e('Nu inschrijven', 'stridence'); ?>
                        </a>
                    <?php elseif ($status->allowsInterest()) : ?>
                        <a href="<?php echo esc_url(stride_enrollment_url($edition_id)); ?>" class="btn-primary w-full text-center block">
                            <?php esc_html_e('Interesse melden', 'stridence'); ?>
                        </a>
                        <p class="text-xs text-text-muted mt-3 text-center">
                            <?php esc_html_e('Deze editie is nog in voorbereiding. Meld je interesse en we houden je op de hoogte.', 'stridence'); ?>
                        </p>
                    <?php else : ?>
                        <button type="button" class="btn-secondary w-full text-center opacity-50 cursor-not-allowed" disabled>
                            <?php esc_html_e('Niet beschikbaar', 'stridence'); ?>
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Mobile Sticky CTA (hidden on lg+) -->
    <?php if ($enrolled_cta) : ?>
        <div class="fixed bottom-0 left-0 right-0 bg-surface border-t border-border p-4 lg:hidden z-40 safe-area-bottom">
            <a href="<?php echo esc_url($enrolled_cta['url']); ?>" class="btn-primary w-full text-center">
                <?php echo esc_html($enrolled_cta['label']); ?>
            </a>
        </div>
    <?php elseif ($can_enroll) : ?>
        <div class="fixed bottom-0 left-0 right-0 bg-surface border-t border-border p-4 lg:hidden z-40 safe-area-bottom">
            <a href="<?php echo esc_url(stride_enrollment_url($edition_id)); ?>" class="btn-primary w-full text-center">
                <?php esc_html_e('Nu inschrijven', 'stridence'); ?>
            </a>
        </div>
    <?php elseif ($status->allowsInterest()) : ?>
        <div class="fixed bottom-0 left-0 right-0 bg-surface border-t border-border p-4 lg:hidden z-40 safe-area-bottom">
            <a href="<?php echo esc_url(stride_enrollment_url($edition_id)); ?>" class="btn-primary w-full text-center">
                <?php esc_html_e('Interesse melden', 'stridence'); ?>
            </a>
        </div>
    <?php endif; ?>
</article>

<?php get_footer(); ?>
