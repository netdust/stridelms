<?php
/**
 * Edition Detail Template — Helder Tij
 *
 * Single template for scheduled course editions (vad_edition post type).
 * Header band + content-tabbed main column with sticky enrollment card.
 *
 * @package stridence
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

use Stride\Modules\Edition\EditionRepository;
use Stride\Modules\Edition\EditionService;
use Stride\Modules\Edition\SessionService;
use Stride\Modules\Edition\SessionSelection;
use Stride\Integrations\LearnDash\LearnDashHelper;

$edition_id = get_the_ID();

$editionService    = ntdst_get(EditionService::class);
$editionRepository = ntdst_get(EditionRepository::class);
$sessionService    = ntdst_get(SessionService::class);

$edition = $editionRepository->find($edition_id);
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
$status     = $editionService->getEffectiveStatus($edition_id);
$price      = $editionService->getPrice($edition_id, get_current_user_id() ?: null);
$can_enroll  = $editionService->canEnroll($edition_id);
$is_past     = $editionService->isPast($edition_id);
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
        $complete_url = home_url('/edities/' . get_post_field('post_name', $edition_id) . '/voltooien/');
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
$speakers     = trim((string) $editionModel->getMeta($edition_id, 'speakers', ''));

// Spots remaining: derived from capacity minus current registrations.
// `capacity = 0` means unlimited — there's nothing meaningful to count down.
$spots = null;
if ($capacity > 0) {
    $spots = max(0, $capacity - $editionService->getRegisteredCount($edition_id));
}

// Get sessions via SessionService and group by type
$all_sessions = $sessionService->getSessionsForEdition($edition_id);
$has_sessions = !empty($all_sessions);
$session_count = count($all_sessions);

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

// Session-selection model: when admin marks the edition with requires_session_selection
// + defines slots (groups of alternative sessions), visitor needs to understand
// "this is a keuzecursus" BEFORE enrolling. We render: mandatory sessions (no slot)
// in one group, and each configured slot as its own pick-N group.
$session_selection = ntdst_get(SessionSelection::class);
$slot_config = $session_selection->getSlotConfig($edition_id);
$has_slots = !empty($slot_config);

// Group scheduled sessions: by slot name, with a 'mandatory' bucket for slot-less sessions.
$scheduled_by_slot = ['__mandatory__' => []];
foreach ($slot_config as $sc) {
    $name = $sc['slot'] ?? '';
    if ($name !== '') {
        $scheduled_by_slot[$name] = [];
    }
}
foreach ($scheduled_sessions as $session) {
    $slot = $session['slot'] ?? '';
    if ($slot && isset($scheduled_by_slot[$slot])) {
        $scheduled_by_slot[$slot][] = $session;
    } else {
        $scheduled_by_slot['__mandatory__'][] = $session;
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

// Header meta dot-row — only segments with data (dates strong · venue · N sessies · price)
$meta_segments = [];
if ($start_date) {
    $meta_segments[] = ['text' => stride_format_date($start_date), 'strong' => true];
}
if ($venue) {
    $meta_segments[] = ['text' => $venue, 'strong' => false];
}
if ($session_count > 0) {
    /* translators: %d: number of sessions */
    $meta_segments[] = ['text' => sprintf(_n('%d sessie', '%d sessies', $session_count, 'stridence'), $session_count), 'strong' => false];
}
if (!$price->isZero()) {
    $meta_segments[] = ['text' => $price->format(), 'strong' => true];
}

// Content tabs (Helder Tij) — ids double as URL hash deep-links (#praktisch)
$content_tabs = [
    'omschrijving' => __('Omschrijving', 'stridence'),
    'programma'    => __('Programma', 'stridence'),
    'praktisch'    => __('Praktisch', 'stridence'),
    'lesgever'     => __('Lesgever', 'stridence'),
];

// Lesgever card: speakers meta when present, i18n'd placeholder otherwise.
$lesgever_name = $speakers !== '' ? $speakers : __('Lesgever nog te bevestigen', 'stridence');
$lesgever_initials = '';
foreach (preg_split('/[\s,]+/u', $lesgever_name, -1, PREG_SPLIT_NO_EMPTY) as $name_part) {
    $lesgever_initials .= mb_substr($name_part, 0, 1);
    if (mb_strlen($lesgever_initials) >= 2) {
        break;
    }
}
$lesgever_initials = mb_strtoupper($lesgever_initials);

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

    <!-- Header band -->
    <div class="bg-surface-alt">
        <div class="container py-[clamp(24px,4vw,40px)]">
            <?php
            stridence_template_part('partials/breadcrumb', null, [
                'items' => $breadcrumbs,
            ]);
            ?>

            <!-- Badge row: format + effective status -->
            <div class="flex flex-wrap gap-2">
                <span class="text-[12px] font-bold px-[11px] py-1 rounded-full inline-flex items-center gap-1 <?php echo $is_online ? 'bg-badge-online-bg text-badge-online-text' : 'bg-badge-open-bg text-badge-open-text'; ?>">
                    <?php $is_online ? esc_html_e('Online', 'stridence') : esc_html_e('Klassikaal', 'stridence'); ?>
                </span>
                <?php if ($is_past) : ?>
                    <span class="text-[12px] font-bold px-[11px] py-1 rounded-full inline-flex items-center gap-1 bg-badge-cancelled-bg text-badge-cancelled-text">
                        <?php esc_html_e('Afgelopen', 'stridence'); ?>
                    </span>
                <?php else : ?>
                    <?php
                    stridence_template_part('partials/badge-status', null, [
                        'status' => $status->value,
                        'spots'  => $spots,
                    ]);
                    ?>
                <?php endif; ?>
            </div>

            <h1 class="font-serif font-normal text-[clamp(30px,4.5vw,44px)] leading-[1.12] text-text max-w-[760px] mt-3.5 mb-3">
                <?php echo $course ? esc_html(get_the_title($course)) : esc_html(get_the_title()); ?>
            </h1>

            <?php if (!empty($meta_segments)) : ?>
                <div class="flex flex-wrap items-center gap-[10px] text-[15px] text-text-muted">
                    <?php foreach ($meta_segments as $i => $segment) : ?>
                        <?php if ($i > 0) : ?>
                            <span class="text-border-strong" aria-hidden="true">&middot;</span>
                        <?php endif; ?>
                        <span<?php echo $segment['strong'] ? ' class="font-semibold text-text"' : ''; ?>><?php echo esc_html($segment['text']); ?></span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ($course) : ?>
                <div class="text-[13px] text-text-faint mt-2">
                    <?php esc_html_e('Onderdeel van de opleiding', 'stridence'); ?>
                    <a href="<?php echo esc_url(get_permalink($course)); ?>" class="text-accent font-semibold hover:text-accent-hover transition-colors">
                        <?php echo esc_html(get_the_title($course)); ?> &mdash; <?php esc_html_e('bekijk alle edities', 'stridence'); ?> &rarr;
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Two Column Layout -->
    <div class="container py-8 lg:py-12">
        <div class="grid lg:grid-cols-3 gap-8 lg:gap-12">
            <!-- Main Content (2/3): content tabs -->
            <div class="lg:col-span-2"
                 x-data="contentTabs(<?php echo esc_attr(wp_json_encode(array_keys($content_tabs))); ?>)">

                <!-- Underline tabs -->
                <nav class="border-b border-border-soft flex gap-6 overflow-x-auto scrollbar-hide" role="tablist"
                     aria-label="<?php esc_attr_e('Editie informatie', 'stridence'); ?>">
                    <?php foreach ($content_tabs as $tab_id => $tab_label) : ?>
                        <button type="button" role="tab"
                                id="tab-<?php echo esc_attr($tab_id); ?>"
                                aria-controls="<?php echo esc_attr($tab_id); ?>"
                                class="text-[15px] font-bold pb-3 px-0.5 whitespace-nowrap transition-colors"
                                :class="isActive('<?php echo esc_attr($tab_id); ?>')
                                    ? 'text-primary shadow-[inset_0_-2px_0_0] shadow-primary'
                                    : 'text-text-faint hover:text-text'"
                                :aria-selected="isActive('<?php echo esc_attr($tab_id); ?>')"
                                @click="setTab('<?php echo esc_attr($tab_id); ?>')">
                            <?php echo esc_html($tab_label); ?>
                        </button>
                    <?php endforeach; ?>
                </nav>

                <?php /* Server-render-first: all four panels are in the DOM; x-show toggles.
                         Deliberately NO x-cloak on panels — base.css hides [x-cloak], which
                         would blank the page for non-JS visitors. Without JS, the panels
                         render stacked. */ ?>

                <!-- Panel: Omschrijving -->
                <section id="omschrijving" role="tabpanel" aria-labelledby="tab-omschrijving"
                         x-show="isActive('omschrijving')" class="pt-7 flex flex-col gap-7">
                    <?php if ($course) : ?>
                        <div class="prose-stride max-w-none">
                            <?php echo apply_filters('the_content', $course->post_content); ?>
                        </div>
                    <?php else : ?>
                        <p class="text-text-muted">
                            <?php esc_html_e('Beschrijving wordt binnenkort toegevoegd.', 'stridence'); ?>
                        </p>
                    <?php endif; ?>

                    <?php
                    // PLACEHOLDER (see docs/plans/2026-06-11-helder-tij-field-inventory.md):
                    // "Wat je leert" sample items — no learning-outcomes field exists yet.
                    $learning_items = [
                        __('Spanning en escalatie vroegtijdig herkennen', 'stridence'),
                        __('De-escalerend communiceren in moeilijke gesprekken', 'stridence'),
                        __('Grenzen stellen met behoud van de zorgrelatie', 'stridence'),
                    ];
                    ?>
                    <div class="flex flex-col gap-3.5">
                        <h2 class="text-[18px] font-bold text-text"><?php esc_html_e('Wat je leert', 'stridence'); ?></h2>
                        <ul class="grid gap-2.5">
                            <?php foreach ($learning_items as $learning_item) : ?>
                                <li class="flex items-center gap-3">
                                    <span class="w-[22px] h-[22px] rounded-full bg-badge-open-bg text-badge-open-text text-[13px] font-extrabold grid place-items-center shrink-0" aria-hidden="true">&check;</span>
                                    <span class="text-[15px] text-text-muted"><?php echo esc_html($learning_item); ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>

                    <?php // PLACEHOLDER: "Voor wie?" well — no audience field exists yet. ?>
                    <div class="bg-surface-alt rounded-[14px] p-5 text-[14px] text-text-muted leading-relaxed">
                        <strong class="text-text"><?php esc_html_e('Voor wie?', 'stridence'); ?></strong>
                        <?php esc_html_e('Begeleiders, verpleegkundigen en onthaalmedewerkers in zorg en welzijn. Geen voorkennis nodig.', 'stridence'); ?>
                    </div>
                </section>

                <!-- Panel: Programma -->
                <section id="programma" role="tabpanel" aria-labelledby="tab-programma"
                         x-show="isActive('programma')" class="pt-7">
                    <?php if (!$has_sessions) : ?>
                        <?php
                        stridence_template_part('partials/empty-state', null, [
                            'icon'    => 'calendar',
                            'title'   => __('Nog geen sessies gepland', 'stridence'),
                            'message' => __('Het programma van deze editie wordt binnenkort bekendgemaakt.', 'stridence'),
                        ]);
                        ?>
                    <?php else : ?>
                        <?php $hasSelections = !empty($selected_session_ids); ?>
                        <?php if (!empty($scheduled_sessions)) :
                            $mandatory = $scheduled_by_slot['__mandatory__'] ?? [];
                            ?>

                            <?php if ($has_slots) : ?>
                                <!-- Mandatory sessions block (only shown when slots also exist) -->
                                <?php if (!empty($mandatory)) : ?>
                                    <div class="mb-6">
                                        <h3 class="font-heading text-base font-semibold text-text mb-3">
                                            <?php esc_html_e('Verplichte sessies', 'stridence'); ?>
                                            <span class="text-sm font-normal text-text-muted">
                                                — <?php esc_html_e('iedereen woont deze bij', 'stridence'); ?>
                                            </span>
                                        </h3>
                                        <div class="flex flex-col gap-2">
                                            <?php foreach ($mandatory as $session) :
                                                $isSelected = in_array((int) $session['id'], $selected_session_ids, true);
                                                ?>
                                                <?php stridence_template_part('partials/session-row', null, [
                                                    'session'    => (object) $session,
                                                    'attendance' => null,
                                                    'selected'   => $isSelected,
                                                    'not_chosen' => false,
                                                ]); ?>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <!-- One block per slot -->
                                <?php foreach ($slot_config as $sc) :
                                    $slotName       = $sc['slot'] ?? '';
                                    $slotLabel      = $sc['label'] ?? $slotName;
                                    $maxSelections  = (int) ($sc['max_selections'] ?? 1);
                                    $required       = !empty($sc['required']);
                                    $slotSessions   = $scheduled_by_slot[$slotName] ?? [];
                                    if ($slotName === '' || empty($slotSessions)) {
                                        continue;
                                    }
                                    $available = count($slotSessions);
                                    ?>
                                    <div class="mb-6">
                                        <div class="flex items-baseline justify-between mb-3">
                                            <h3 class="font-heading text-base font-semibold text-text">
                                                <?php echo esc_html($slotLabel); ?>
                                            </h3>
                                            <span class="text-sm text-primary font-medium">
                                                <?php
                                                if ($maxSelections === 1) {
                                                    /* translators: %d = total available alternatives */
                                                    printf(esc_html__('Kies 1 uit %d', 'stridence'), $available);
                                                } else {
                                                    /* translators: 1: how many to pick, 2: total alternatives */
                                                    printf(esc_html__('Kies %1$d uit %2$d', 'stridence'), $maxSelections, $available);
                                                }
                                                if (!$required) {
                                                    echo ' <span class="text-text-muted font-normal">' . esc_html__('(optioneel)', 'stridence') . '</span>';
                                                }
                                                ?>
                                            </span>
                                        </div>
                                        <div class="flex flex-col gap-2">
                                            <?php foreach ($slotSessions as $session) :
                                                $isSelected = in_array((int) $session['id'], $selected_session_ids, true);
                                                $notChosen = $hasSelections && !$isSelected;
                                                ?>
                                                <?php stridence_template_part('partials/session-row', null, [
                                                    'session'    => (object) $session,
                                                    'attendance' => null,
                                                    'selected'   => $isSelected,
                                                    'not_chosen' => $notChosen,
                                                ]); ?>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>

                            <?php else : ?>
                                <!-- No slots configured: flat list (all sessions mandatory) -->
                                <div class="flex flex-col gap-2">
                                    <?php foreach ($scheduled_sessions as $session) :
                                        $isSelected = in_array((int) $session['id'], $selected_session_ids, true);
                                        ?>
                                        <?php stridence_template_part('partials/session-row', null, [
                                            'session'    => (object) $session,
                                            'attendance' => null,
                                            'selected'   => $isSelected,
                                            'not_chosen' => false,
                                        ]); ?>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

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
                            <div class="flex flex-col gap-2">
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

                        <?php if ($session_count > 1) : ?>
                            <p class="text-[13px] text-text-faint mt-3 px-0.5">
                                <?php esc_html_e('Alle sessies horen bij dezelfde inschrijving — je hoeft maar één keer in te schrijven.', 'stridence'); ?>
                            </p>
                        <?php endif; ?>
                    <?php endif; ?>
                </section>

                <!-- Panel: Praktisch -->
                <section id="praktisch" role="tabpanel" aria-labelledby="tab-praktisch"
                         x-show="isActive('praktisch')" class="pt-7">
                    <div class="grid gap-[14px] sm:grid-cols-2 lg:grid-cols-3">
                        <div class="bg-white rounded-[14px] shadow-card p-5">
                            <h3 class="text-[11px] font-bold text-primary uppercase tracking-[0.08em]"><?php esc_html_e('Locatie', 'stridence'); ?></h3>
                            <p class="text-[14px] text-text-muted leading-relaxed mt-2">
                                <?php echo $venue ? esc_html($venue) : esc_html__('Wordt nog bekendgemaakt', 'stridence'); ?>
                            </p>
                        </div>
                        <?php // PLACEHOLDER: "Inbegrepen" card — no inclusions field exists yet. ?>
                        <div class="bg-white rounded-[14px] shadow-card p-5">
                            <h3 class="text-[11px] font-bold text-primary uppercase tracking-[0.08em]"><?php esc_html_e('Inbegrepen', 'stridence'); ?></h3>
                            <p class="text-[14px] text-text-muted leading-relaxed mt-2">
                                <?php esc_html_e('Lunch, koffie en cursusmateriaal. Je ontvangt achteraf een attest van deelname.', 'stridence'); ?>
                            </p>
                        </div>
                        <?php // PLACEHOLDER: "Annuleren" card — no cancellation-policy field exists yet. ?>
                        <div class="bg-white rounded-[14px] shadow-card p-5">
                            <h3 class="text-[11px] font-bold text-primary uppercase tracking-[0.08em]"><?php esc_html_e('Annuleren', 'stridence'); ?></h3>
                            <p class="text-[14px] text-text-muted leading-relaxed mt-2">
                                <?php esc_html_e('Kosteloos tot 14 dagen vóór de eerste sessie. Daarna kan een collega je plaats overnemen.', 'stridence'); ?>
                            </p>
                        </div>
                    </div>
                </section>

                <!-- Panel: Lesgever -->
                <section id="lesgever" role="tabpanel" aria-labelledby="tab-lesgever"
                         x-show="isActive('lesgever')" class="pt-7">
                    <div class="bg-white rounded-[14px] shadow-card p-6 flex gap-5 items-start flex-wrap">
                        <span class="w-14 h-14 rounded-full bg-accent-subtle text-accent-hover font-bold text-[18px] grid place-items-center shrink-0" aria-hidden="true"><?php echo esc_html($lesgever_initials); ?></span>
                        <div class="flex-1 min-w-[240px]">
                            <div class="text-[17px] font-bold text-text"><?php echo esc_html($lesgever_name); ?></div>
                            <?php // PLACEHOLDER: role line — no speaker-role field exists yet. ?>
                            <div class="text-[13px] text-accent font-semibold mt-0.5"><?php esc_html_e('Lesgever', 'stridence'); ?></div>
                            <?php // PLACEHOLDER: bio — no speaker-bio field exists yet. ?>
                            <p class="text-[14px] text-text-muted leading-relaxed mt-3">
                                <?php esc_html_e('Meer informatie over de lesgever volgt binnenkort.', 'stridence'); ?>
                            </p>
                        </div>
                    </div>
                </section>
            </div>

            <!-- Sidebar (1/3) - Enrollment Card -->
            <div class="lg:col-span-1">
                <div class="card p-6 sticky top-24">
                    <h3 class="font-heading font-semibold text-lg mb-4">
                        <?php
                        if ($is_past) {
                            esc_html_e('Deze editie is afgelopen', 'stridence');
                        } else {
                            $sidebar_header = match (true) {
                                $status === \Stride\Domain\OfferingStatus::Cancelled  => __('Editie geannuleerd', 'stridence'),
                                $status === \Stride\Domain\OfferingStatus::Postponed  => __('Editie uitgesteld', 'stridence'),
                                $status === \Stride\Domain\OfferingStatus::InProgress => __('Editie is bezig', 'stridence'),
                                $status === \Stride\Domain\OfferingStatus::Completed  => __('Deze editie is afgelopen', 'stridence'),
                                $status === \Stride\Domain\OfferingStatus::Archived   => __('Editie gearchiveerd', 'stridence'),
                                default => __('Inschrijven', 'stridence'),
                            };
                            echo esc_html($sidebar_header);
                        }
?>
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
        (int) $spots,
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
                    <?php elseif ($is_past) : ?>
                        <button type="button" class="btn-secondary w-full text-center opacity-50 cursor-not-allowed" disabled>
                            <?php esc_html_e('Editie is afgelopen', 'stridence'); ?>
                        </button>
                    <?php elseif ($can_enroll) : ?>
                        <a href="<?php echo esc_url(stride_enrollment_url($edition_id)); ?>" class="btn-primary w-full text-center">
                            <?php esc_html_e('Nu inschrijven', 'stridence'); ?>
                        </a>
                    <?php elseif ($status->allowsInterest()) : ?>
                        <a href="<?php echo esc_url(home_url('/interesse/?editie=' . $edition_id)); ?>" class="btn-primary w-full text-center block">
                            <?php esc_html_e('Interesse melden', 'stridence'); ?>
                        </a>
                        <p class="text-xs text-text-muted mt-3 text-center">
                            <?php esc_html_e('Deze editie is nog in voorbereiding. Meld je interesse en we houden je op de hoogte.', 'stridence'); ?>
                        </p>
                    <?php elseif ($status->allowsWaitlist()) : ?>
                        <a href="<?php echo esc_url(home_url('/wachtlijst/?editie=' . $edition_id)); ?>" class="btn-primary w-full text-center block">
                            <?php esc_html_e('Op wachtlijst plaatsen', 'stridence'); ?>
                        </a>
                        <p class="text-xs text-text-muted mt-3 text-center">
                            <?php esc_html_e('Deze editie is volzet. Laat je gegevens achter en we nemen contact op als er een plaats vrijkomt.', 'stridence'); ?>
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

    <!-- Mobile Sticky CTA (hidden on lg+). Past editions show no CTA. -->
    <?php if ($enrolled_cta) : ?>
        <div class="fixed bottom-0 left-0 right-0 bg-surface border-t border-border p-4 lg:hidden z-40 safe-area-bottom">
            <a href="<?php echo esc_url($enrolled_cta['url']); ?>" class="btn-primary w-full text-center">
                <?php echo esc_html($enrolled_cta['label']); ?>
            </a>
        </div>
    <?php elseif ($is_past) : ?>
        <?php /* No sticky CTA for past editions. */ ?>
    <?php elseif ($can_enroll) : ?>
        <div class="fixed bottom-0 left-0 right-0 bg-surface border-t border-border p-4 lg:hidden z-40 safe-area-bottom">
            <a href="<?php echo esc_url(stride_enrollment_url($edition_id)); ?>" class="btn-primary w-full text-center">
                <?php esc_html_e('Nu inschrijven', 'stridence'); ?>
            </a>
        </div>
    <?php elseif ($status->allowsInterest()) : ?>
        <div class="fixed bottom-0 left-0 right-0 bg-surface border-t border-border p-4 lg:hidden z-40 safe-area-bottom">
            <a href="<?php echo esc_url(home_url('/interesse/?editie=' . $edition_id)); ?>" class="btn-primary w-full text-center">
                <?php esc_html_e('Interesse melden', 'stridence'); ?>
            </a>
        </div>
    <?php elseif ($status->allowsWaitlist()) : ?>
        <div class="fixed bottom-0 left-0 right-0 bg-surface border-t border-border p-4 lg:hidden z-40 safe-area-bottom">
            <a href="<?php echo esc_url(home_url('/wachtlijst/?editie=' . $edition_id)); ?>" class="btn-primary w-full text-center">
                <?php esc_html_e('Op wachtlijst plaatsen', 'stridence'); ?>
            </a>
        </div>
    <?php endif; ?>
</article>

<?php get_footer(); ?>
