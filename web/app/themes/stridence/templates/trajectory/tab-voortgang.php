<?php
/**
 * Trajectory Tab: Voortgang (Progress) — Helder Tij journey timeline
 *
 * Renders the trajectory parts as a vertical journey: one row per required
 * course (done / active / upcoming) plus one keuze row per elective group,
 * connected by a 2px rail — primary through completed rows, soft after the
 * current one, no trailing segment on the last row.
 *
 * Overall progress (ring + counts) lives in the enrolled shell's header
 * band (dashboard.php, same getProgressData source), so this tab renders
 * the timeline only.
 *
 * States map from existing data only: completed_courses / in_progress_courses
 * from getProgressData(), edition links via the registration rows the same
 * payload carries (course→edition lookup as in dashboard/tab-trajecten.php),
 * elective confirmation from enrollment selections — the same rule the shell
 * uses for the Keuzes tab badge. Keuze buttons navigate server-side via
 * ?tab=keuzes, matching the shell's tab links.
 *
 * @param array $args {
 *     @type WP_Post $trajectory
 *     @type object $enrollment
 *     @type WP_User $user
 *     @type TrajectoryDashboardService $dashboard_service
 * }
 * @package stridence
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

use Stride\Integrations\LearnDash\LearnDashHelper;
use Stride\Modules\Edition\EditionService;
use Stride\Modules\Trajectory\TrajectoryDashboardService;

$trajectory = $args['trajectory'];
$enrollment = $args['enrollment'];
$user = $args['user'];
$dashboardService = $args['dashboard_service'];

// Get progress data — prefer the pre-fetched value passed by dashboard.php
// (single DB call); fall back to a direct fetch for isolated-render testability.
$progress = $args['progress'] ?? $dashboardService->getProgressData($user->ID, $trajectory->ID);

$completedIds = array_map('intval', $progress['completed_courses']);
$inProgressIds = array_map('intval', $progress['in_progress_courses']);

// Course → edition map from the registration rows already in the payload
// (same lookup dashboard/tab-trajecten.php uses) — powers "Bekijk editie".
$editionService = ntdst_get(EditionService::class);
$editionByCourse = [];
foreach ($progress['edition_registrations'] as $edReg) {
    $editionId = (int) $edReg->edition_id;
    $courseId = $editionService->getCourseId($editionId);
    if ($courseId !== null && !isset($editionByCourse[$courseId])) {
        $editionByCourse[$courseId] = $editionId;
    }
}

// Selections — findByUserAndTrajectory() already decodes to an array;
// tolerate a raw JSON string for rows that skipped that path (as the shell does).
$rawSelections = $enrollment->selections ?? null;
if (is_array($rawSelections)) {
    $selections = $rawSelections;
} elseif (is_string($rawSelections) && $rawSelections !== '') {
    $selections = (array) json_decode($rawSelections, true);
} else {
    $selections = [];
}

// Build timeline rows: required courses in order, then one keuze row per group.
$timeline = [];

foreach ($progress['required_courses'] as $course) {
    if (in_array($course->ID, $completedIds, true)) {
        $state = 'done';
    } elseif (in_array($course->ID, $inProgressIds, true)) {
        $state = 'active';
    } else {
        $state = 'upcoming';
    }

    $timeline[] = [
        'type' => 'course',
        'state' => $state,
        'course' => $course,
    ];
}

foreach ($progress['elective_groups'] as $groupIndex => $group) {
    $courses = $group['courses'] ?? [];

    if (empty($courses)) {
        continue;
    }

    // Same confirmation rule as the shell's Keuzes tab badge:
    // a group is open while required > 0 and fewer choices are made.
    $required = (int) ($group['required'] ?? 0);
    $chosenIds = array_map('intval', (array) ($selections[$groupIndex] ?? []));
    $confirmed = count($chosenIds) >= $required;

    $chosenTitles = [];
    foreach ($courses as $course) {
        if (in_array($course->ID, $chosenIds, true)) {
            $chosenTitles[] = $course->post_title;
        }
    }

    $timeline[] = [
        'type' => 'elective',
        'state' => 'choice',
        'name' => (string) ($group['name'] ?? ''),
        'required' => max(1, $required), // clamp: required=0 is invalid schema data; render defensively as 1
        'total' => count($courses),
        'confirmed' => $confirmed,
        'chosen_titles' => $chosenTitles,
    ];
}

$rowCount = count($timeline);
$keuzesUrl = add_query_arg('tab', 'keuzes');

// Sheet pill recipe (card size 'sm') — badge-status has no 'afgerond'
// variant, so the pill renders inline (same approach as card-edition).
$pillSm = 'text-[11px] font-bold px-[9px] py-[3px] rounded-full inline-flex items-center gap-1';
?>

<?php if ($rowCount === 0) : ?>
    <?php
    stridence_template_part('partials/empty-state', null, [
        'icon' => 'layers',
        'title' => __('Geen onderdelen', 'stridence'),
        'message' => __('Dit traject heeft nog geen onderdelen.', 'stridence'),
    ]);
    ?>
<?php else : ?>
    <div class="flex flex-col">
        <?php foreach ($timeline as $index => $row) :
            $isLast = ($index === $rowCount - 1);

            // Rail segment below this row: primary through completed rows,
            // soft below the current/upcoming ones.
            // elective rows keep the soft rail even when confirmed — pending visual decision, see /shakeout F8
            $lineClass = $row['state'] === 'done' ? 'bg-primary' : 'bg-border-soft';
            ?>
            <div class="flex gap-[18px]">
                <!-- Connector rail -->
                <div class="flex flex-col items-center w-7 shrink-0">
                    <?php if ($row['state'] === 'done') : ?>
                        <span class="w-7 h-7 rounded-full bg-badge-open-bg text-badge-open-text grid place-items-center shrink-0">
                            <?php echo stridence_icon('check', 'w-4 h-4'); ?>
                        </span>
                    <?php elseif ($row['state'] === 'active') : ?>
                        <span class="w-7 h-7 rounded-full bg-primary grid place-items-center shrink-0">
                            <span class="w-[9px] h-[9px] rounded-full bg-white"></span>
                        </span>
                    <?php else : ?>
                        <span class="w-7 h-7 rounded-full border-2 border-border bg-surface-card shrink-0"></span>
                    <?php endif; ?>

                    <?php if (!$isLast) : ?>
                        <span class="w-0.5 flex-1 min-h-[24px] <?php echo esc_attr($lineClass); ?>"></span>
                    <?php endif; ?>
                </div>

                <?php if ($row['type'] === 'course') :
                    $course = $row['course'];
                    ?>
                    <?php if ($row['state'] === 'done') :
                        $completedOn = LearnDashHelper::getCompletionDate($course->ID, $user->ID);
                        ?>
                        <div class="flex-1 bg-surface-card rounded-[14px] shadow-card p-5<?php echo $isLast ? '' : ' mb-3.5'; ?> flex flex-wrap items-center gap-3.5">
                            <div class="flex-1 min-w-[200px]">
                                <h3 class="text-[15px] font-bold text-text">
                                    <?php echo esc_html($course->post_title); ?>
                                </h3>
                                <?php if ($completedOn) : ?>
                                    <p class="text-[13px] text-text-muted mt-0.5">
                                        <?php
                                        printf(
                                            /* translators: %s: completion date */
                                            esc_html__('Afgerond op %s', 'stridence'),
                                            esc_html(date_i18n('j F Y', $completedOn)),
                                        );
                        ?>
                                    </p>
                                <?php endif; ?>
                            </div>
                            <span class="<?php echo esc_attr($pillSm); ?> bg-badge-open-bg text-badge-open-text">
                                <?php esc_html_e('Afgerond', 'stridence'); ?>
                            </span>
                        </div>
                    <?php elseif ($row['state'] === 'active') :
                        $editionId = $editionByCourse[$course->ID] ?? 0;
                        $ctaUrl = $editionId ? get_permalink($editionId) : get_permalink($course);
                        ?>
                        <div class="flex-1 bg-badge-online-bg rounded-[14px] p-5<?php echo $isLast ? '' : ' mb-3.5'; ?> flex flex-wrap items-center gap-3.5">
                            <div class="flex-1 min-w-[200px]">
                                <h3 class="text-[15px] font-bold text-text">
                                    <?php echo esc_html($course->post_title); ?>
                                </h3>
                            </div>
                            <a href="<?php echo esc_url($ctaUrl); ?>" class="btn-primary btn-sm">
                                <?php esc_html_e('Bekijk editie', 'stridence'); ?> &rarr;
                            </a>
                        </div>
                    <?php else : ?>
                        <div class="flex-1 border border-border-soft bg-surface-card rounded-[14px] p-5<?php echo $isLast ? '' : ' mb-3.5'; ?> flex flex-wrap items-center gap-3.5">
                            <div class="flex-1 min-w-[200px]">
                                <h3 class="text-[15px] font-bold text-text-muted">
                                    <?php echo esc_html($course->post_title); ?>
                                </h3>
                                <p class="text-[13px] text-text-muted mt-0.5">
                                    <?php esc_html_e('Nog te starten', 'stridence'); ?>
                                </p>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php else :
                    if ($row['name'] !== '') {
                        $keuzeTitle = sprintf(
                            /* translators: 1: elective group name, 2: number to choose, 3: number of options */
                            __('%1$s — kies %2$d uit %3$d', 'stridence'),
                            $row['name'],
                            $row['required'],
                            $row['total'],
                        );
                    } else {
                        $keuzeTitle = sprintf(
                            /* translators: 1: number to choose, 2: number of options */
                            __('Keuzemodule — kies %1$d uit %2$d', 'stridence'),
                            $row['required'],
                            $row['total'],
                        );
                    }

                    if (!empty($row['chosen_titles'])) {
                        $keuzeStatus = implode(' · ', $row['chosen_titles']);
                    } elseif ($row['confirmed']) {
                        $keuzeStatus = __('Keuze bevestigd', 'stridence');
                    } else {
                        $keuzeStatus = __('Nog geen keuze gemaakt', 'stridence');
                    }
                    ?>
                    <div class="flex-1 border border-dashed border-border bg-surface-card rounded-[14px] p-5<?php echo $isLast ? '' : ' mb-3.5'; ?> flex flex-wrap items-center gap-3.5">
                        <div class="flex-1 min-w-[200px]">
                            <h3 class="text-[15px] font-bold text-text">
                                <?php echo esc_html($keuzeTitle); ?>
                            </h3>
                            <p class="text-[13px] text-text-muted mt-0.5">
                                <?php echo esc_html($keuzeStatus); ?>
                            </p>
                        </div>
                        <?php if ($row['confirmed']) : ?>
                            <a href="<?php echo esc_url($keuzesUrl); ?>" class="btn-ghost btn-sm">
                                <?php esc_html_e('Wijzig keuze', 'stridence'); ?>
                            </a>
                        <?php else : ?>
                            <a href="<?php echo esc_url($keuzesUrl); ?>" class="btn-primary btn-sm">
                                <?php esc_html_e('Maak je keuze', 'stridence'); ?>
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
