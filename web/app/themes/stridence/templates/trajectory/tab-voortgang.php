<?php
/**
 * Trajectory Tab: Voortgang (Progress)
 *
 * Two distinct views depending on trajectory mode, because they mean
 * different things (Stride\Domain\TrajectoryMode):
 *
 * - Cohort ("vaste editie-reeks"): one shared schedule everyone follows.
 *   Renders as a session-level AGENDA — every session across every required
 *   course, flattened and sorted chronologically (date/time/location, same
 *   idiom as klassikaal's programme), grouped under its course as a
 *   sub-heading. Each session expands to its full programme (description)
 *   via partials/session-row.php, exactly like the klassikaal edition page.
 *   Any open task (an unconfirmed elective choice) is an ACTION banner
 *   surfaced above the agenda — it blocks/matters more than "session #4 on
 *   a date three weeks out", so it isn't buried in the middle of the list.
 *
 * - SelfPaced ("zelfgestuurd, eigen edities kiezen"): no shared schedule —
 *   "active" means "you have a confirmed registration for this course", not
 *   "this is chronologically next". Renders as a flat per-course status list
 *   (done / ingeschreven / nog niet ingeschreven), no date rail, no sorting
 *   that would imply an order that doesn't exist (review, 2026-07-07).
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

use Stride\Domain\AttendanceStatus;
use Stride\Domain\TrajectoryMode;
use Stride\Integrations\LearnDash\LearnDashHelper;
use Stride\Modules\Attendance\AttendanceRepository;
use Stride\Modules\Edition\EditionService;
use Stride\Modules\Edition\SessionService;
use Stride\Modules\Trajectory\TrajectoryDashboardService;
use Stride\Modules\Trajectory\TrajectorySelection;

$trajectory = $args['trajectory'];
$enrollment = $args['enrollment'];
$user = $args['user'];
$dashboardService = $args['dashboard_service'];

// Get progress data — prefer the pre-fetched value passed by dashboard.php
// (single DB call); fall back to a direct fetch for isolated-render testability.
$progress = $args['progress'] ?? $dashboardService->getProgressData($user->ID, $trajectory->ID);

$isSelfPaced = ($progress['mode'] ?? TrajectoryMode::Cohort) === TrajectoryMode::SelfPaced;

$completedIds = array_map('intval', $progress['completed_courses']);
$inProgressIds = array_map('intval', $progress['in_progress_courses']);

// Course → edition map from the registration rows already in the payload
// (same lookup dashboard/tab-trajecten.php uses).
$editionService = ntdst_get(EditionService::class);
$editionByCourse = [];
foreach ($progress['edition_registrations'] as $edReg) {
    $editionId = (int) $edReg->edition_id;
    $courseId = $editionService->getCourseId($editionId);
    if ($courseId !== null && !isset($editionByCourse[$courseId])) {
        $editionByCourse[$courseId] = $editionId;
    }
}

// Picks as COURSE ids through the single decision point — the raw
// selections column stores flat EDITION ids, never grouped course ids.
$trajectorySelection = ntdst_get(TrajectorySelection::class);
$selectedCourseIds = $trajectorySelection->getSelectedCourseIds((int) ($enrollment->id ?? 0));
$keuzesUrl = add_query_arg('tab', 'keuzes');

// Open elective tasks — a group is open while required > 0 and fewer choices
// are made. Same rule the shell's Keuzes tab badge uses. Collected once,
// rendered differently per mode (Cohort: action banner above the agenda;
// SelfPaced: inline row, same as before — it's still just one of several
// per-course statuses there, not a blocking task on a live schedule).
$openElectiveGroups = [];
$electiveRows = [];
foreach ($progress['elective_groups'] as $group) {
    $courses = $group['courses'] ?? [];
    if (empty($courses)) {
        continue;
    }

    $required = (int) ($group['required'] ?? 0);
    $groupCourseIds = array_map(static fn($c): int => (int) $c->ID, $courses);
    $chosenIds = array_values(array_intersect($groupCourseIds, $selectedCourseIds));
    $confirmed = $trajectorySelection->isGroupChosen($group, $selectedCourseIds);

    $chosenTitles = [];
    foreach ($courses as $course) {
        if (in_array($course->ID, $chosenIds, true)) {
            $chosenTitles[] = $course->post_title;
        }
    }

    $row = [
        'name' => (string) ($group['name'] ?? ''),
        'required' => max(1, $required), // clamp: required=0 is invalid schema data
        'total' => count($courses),
        'confirmed' => $confirmed,
        'chosen_titles' => $chosenTitles,
    ];
    $electiveRows[] = $row;

    if (!$confirmed) {
        $openElectiveGroups[] = $row;
    }
}

$pillSm = 'text-[11px] font-bold px-[9px] py-[3px] rounded-full inline-flex items-center gap-1';

/**
 * Render one elective row (shared markup between the Cohort action banner
 * and the SelfPaced inline list — only the surrounding container differs).
 */
$renderElectiveRow = static function (array $row) use ($keuzesUrl): void {
    $keuzeTitle = $row['name'] !== ''
        ? sprintf(
            /* translators: 1: elective group name, 2: number to choose, 3: number of options */
            __('%1$s — kies %2$d uit %3$d', 'stridence'),
            $row['name'],
            $row['required'],
            $row['total'],
        )
        : sprintf(
            /* translators: 1: number to choose, 2: number of options */
            __('Keuzemodule — kies %1$d uit %2$d', 'stridence'),
            $row['required'],
            $row['total'],
        );

    if (!empty($row['chosen_titles'])) {
        $keuzeStatus = implode(' · ', $row['chosen_titles']);
    } elseif ($row['confirmed']) {
        $keuzeStatus = __('Keuze bevestigd', 'stridence');
    } else {
        $keuzeStatus = __('Nog geen keuze gemaakt', 'stridence');
    }
    ?>
    <div class="flex-1 border border-dashed border-accent/40 bg-accent/5 rounded-[14px] p-5 flex flex-wrap items-center gap-3.5">
        <span class="w-11 h-11 rounded-[11px] bg-accent/10 text-accent flex items-center justify-center shrink-0">
            <?php echo stridence_icon('list', 'w-5 h-5'); ?>
        </span>
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
    <?php
};

if ($isSelfPaced) :
    // ── SelfPaced: flat per-course status list ─────────────────────────
    $courseRows = [];
    foreach ($progress['required_courses'] as $course) {
        if (in_array($course->ID, $completedIds, true)) {
            $state = 'done';
        } elseif (in_array($course->ID, $inProgressIds, true)) {
            $state = 'active';
        } else {
            $state = 'upcoming';
        }

        $editionId = $editionByCourse[$course->ID] ?? 0;
        if ($editionId === 0) {
            $editionId = stridence_trajectory_elective_edition_id((int) $course->ID);
        }

        $courseRows[] = [
            'state' => $state,
            'course' => $course,
            'edition_id' => $editionId,
        ];
    }

    $rowCount = count($courseRows) + count($electiveRows);
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
        <div class="flex flex-col gap-3.5">
            <?php foreach ($courseRows as $row) :
                $course = $row['course'];
                $editionId = (int) $row['edition_id'];
                $cardUrl = $editionId > 0 ? get_permalink($editionId) : '';
                $cardTag = $cardUrl !== '' ? 'a' : 'div';
                $cardHref = $cardUrl !== '' ? ' href="' . esc_url($cardUrl) . '"' : '';
                $cardHover = $cardUrl !== ''
                    ? ' transition-shadow duration-normal ease-out hover:shadow-elevated cursor-pointer'
                    : '';
                ?>
                <?php if ($row['state'] === 'done') :
                    $completedOn = LearnDashHelper::getCompletionDate($course->ID, $user->ID);
                    $metaParts = [];
                    if ($editionId > 0) {
                        $metaParts[] = $editionService->isOnline($editionId)
                            ? __('Online', 'stridence')
                            : __('Klassikaal', 'stridence');
                    }
                    if ($completedOn) {
                        $metaParts[] = sprintf(
                            /* translators: %s: completion date */
                            __('afgerond op %s', 'stridence'),
                            date_i18n('j F Y', $completedOn),
                        );
                    }
                    $metaLine = implode(' · ', $metaParts);
                    ?>
                    <<?php echo $cardTag . $cardHref; ?> class="flex-1 bg-surface-card rounded-[14px] shadow-card p-5 flex flex-wrap items-center gap-3.5<?php echo esc_attr($cardHover); ?>">
                        <span class="w-11 h-11 rounded-[11px] bg-badge-open-bg text-badge-open-text flex items-center justify-center shrink-0">
                            <?php echo stridence_icon('check', 'w-5 h-5'); ?>
                        </span>
                        <div class="flex-1 min-w-[200px]">
                            <h3 class="text-[15px] font-bold text-text"><?php echo esc_html($course->post_title); ?></h3>
                            <?php if ($metaLine !== '') : ?>
                                <p class="text-[13px] text-text-muted mt-0.5"><?php echo esc_html($metaLine); ?></p>
                            <?php endif; ?>
                        </div>
                        <span class="<?php echo esc_attr($pillSm); ?> bg-badge-open-bg text-badge-open-text">
                            <?php esc_html_e('Afgerond', 'stridence'); ?>
                        </span>
                    </<?php echo $cardTag; ?>>
                <?php elseif ($row['state'] === 'active') :
                    // "active" = confirmed registration, NOT "next by date" — no
                    // shared calendar exists in SelfPaced mode.
                    $metaLine = stridence_trajectory_meta_line($editionId);
                    ?>
                    <<?php echo $cardTag . $cardHref; ?> class="flex-1 bg-surface-card rounded-[14px] shadow-card border-2 border-primary p-5 flex flex-wrap items-center gap-3.5<?php echo esc_attr($cardHover); ?>">
                        <span class="w-11 h-11 rounded-[11px] bg-primary/10 text-primary flex items-center justify-center shrink-0">
                            <?php echo stridence_icon('book-open', 'w-5 h-5'); ?>
                        </span>
                        <div class="flex-1 min-w-[200px]">
                            <h3 class="text-[15px] font-bold text-text"><?php echo esc_html($course->post_title); ?></h3>
                            <p class="text-[13px] text-text-muted mt-0.5">
                                <?php echo esc_html(trim(implode(' · ', array_filter([__('Ingeschreven', 'stridence'), $metaLine])), ' ·')); ?>
                            </p>
                        </div>
                        <span class="btn-primary btn-sm"><?php esc_html_e('Bekijk editie', 'stridence'); ?> &rarr;</span>
                    </<?php echo $cardTag; ?>>
                <?php else : ?>
                    <div class="flex-1 border border-dashed border-border-soft bg-surface-card rounded-[14px] p-5 flex flex-wrap items-center gap-3.5">
                        <span class="w-11 h-11 rounded-[11px] bg-surface-alt text-text-muted flex items-center justify-center shrink-0">
                            <?php echo stridence_icon('book-open', 'w-5 h-5'); ?>
                        </span>
                        <div class="flex-1 min-w-[200px]">
                            <h3 class="text-[15px] font-bold text-text-muted"><?php echo esc_html($course->post_title); ?></h3>
                            <p class="text-[13px] text-text-muted mt-0.5"><?php esc_html_e('Nog niet ingeschreven', 'stridence'); ?></p>
                        </div>
                        <?php if ($cardUrl !== '') : ?>
                            <a href="<?php echo esc_url($cardUrl); ?>" class="btn-secondary btn-sm"><?php esc_html_e('Bekijk editie', 'stridence'); ?> &rarr;</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>

            <?php foreach ($electiveRows as $row) : ?>
                <?php $renderElectiveRow($row); ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

<?php else :
    // ── Cohort: session-level agenda ────────────────────────────────────
    $sessionService = ntdst_get(SessionService::class);
    $attendanceRepo = ntdst_get(AttendanceRepository::class);

    // One group per required course: its resolved edition's sessions, each
    // with attendance when marked. Groups sorted by their earliest session
    // date so the whole agenda reads chronologically top to bottom.
    $courseGroups = [];
    foreach ($progress['required_courses'] as $course) {
        $editionId = $editionByCourse[$course->ID] ?? 0;
        if ($editionId === 0) {
            $editionId = stridence_trajectory_elective_edition_id((int) $course->ID);
        }

        $sessions = $editionId > 0 ? $sessionService->getSessionsForEdition($editionId) : [];

        $attendanceBySession = [];
        if ($editionId > 0) {
            foreach ($attendanceRepo->getByUserAndEdition((int) $user->ID, $editionId) as $att) {
                $status = AttendanceStatus::tryFrom((string) ($att->status ?? ''));
                // session-row.php only knows present/absent/pending — excused
                // reads closest to "pending" (not a firm present/absent mark).
                $attendanceBySession[(int) $att->session_id] = match ($status) {
                    AttendanceStatus::Present => 'present',
                    AttendanceStatus::Absent => 'absent',
                    default => 'pending',
                };
            }
        }

        $earliestDate = '';
        foreach ($sessions as $s) {
            $d = (string) ($s['date'] ?? '');
            if ($d !== '' && ($earliestDate === '' || $d < $earliestDate)) {
                $earliestDate = $d;
            }
        }

        $courseGroups[] = [
            'course' => $course,
            'edition_id' => $editionId,
            'sessions' => $sessions,
            'attendance' => $attendanceBySession,
            'sort_date' => $earliestDate,
            'is_online' => $editionId > 0 && $editionService->isOnline($editionId),
        ];
    }

    usort($courseGroups, static function (array $a, array $b): int {
        if ($a['sort_date'] === $b['sort_date']) {
            return 0;
        }
        if ($a['sort_date'] === '') {
            return 1;
        }
        if ($b['sort_date'] === '') {
            return -1;
        }

        return strcmp($a['sort_date'], $b['sort_date']);
    });

    $hasAnySessions = false;
    foreach ($courseGroups as $g) {
        if (!empty($g['sessions'])) {
            $hasAnySessions = true;
            break;
        }
    }
    ?>

    <?php if (empty($courseGroups) && empty($electiveRows)) : ?>
        <?php
        stridence_template_part('partials/empty-state', null, [
            'icon' => 'layers',
            'title' => __('Geen onderdelen', 'stridence'),
            'message' => __('Dit traject heeft nog geen onderdelen.', 'stridence'),
        ]);
        ?>
    <?php else : ?>

        <?php if (!empty($openElectiveGroups)) : ?>
            <!-- Action-needed banner: an open elective choice matters more
                 than any single agenda row, so it sits above the agenda
                 instead of being buried inline (review, 2026-07-07). -->
            <div class="mb-6 flex flex-col gap-3">
                <p class="text-xs font-semibold text-text-muted uppercase tracking-wider">
                    <?php esc_html_e('Actie nodig', 'stridence'); ?>
                </p>
                <?php foreach ($openElectiveGroups as $row) : ?>
                    <?php $renderElectiveRow($row); ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($hasAnySessions) : ?>
            <div class="flex flex-col gap-6">
                <?php foreach ($courseGroups as $group) :
                    if (empty($group['sessions'])) {
                        continue;
                    }
                    $course = $group['course'];
                    $isComplete = in_array($course->ID, $completedIds, true);
                    ?>
                    <div>
                        <div class="flex items-center gap-2 mb-2.5">
                            <h3 class="text-[15px] font-bold text-text"><?php echo esc_html($course->post_title); ?></h3>
                            <?php if ($isComplete) : ?>
                                <span class="<?php echo esc_attr($pillSm); ?> bg-badge-open-bg text-badge-open-text">
                                    <?php esc_html_e('Afgerond', 'stridence'); ?>
                                </span>
                            <?php endif; ?>
                        </div>
                        <div class="flex flex-col gap-2">
                            <?php foreach ($group['sessions'] as $session) :
                                $sessionId = (int) ($session['id'] ?? 0);
                                stridence_template_part('partials/session-row', null, [
                                    'session' => (object) $session,
                                    'attendance' => $group['attendance'][$sessionId] ?? null,
                                ]);
                            endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else : ?>
            <?php
            stridence_template_part('partials/empty-state', null, [
                'icon' => 'calendar',
                'title' => __('Nog geen sessies gepland', 'stridence'),
                'message' => __('Zodra er sessies gepland zijn, verschijnen ze hier.', 'stridence'),
            ]);
            ?>
        <?php endif; ?>
    <?php endif; ?>
<?php endif; ?>
