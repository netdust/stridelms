<?php
/**
 * Trajectory Tab: Keuzes (Elective Selection)
 *
 * Handles elective course selection with choice window states.
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

use Stride\Modules\Trajectory\TrajectoryRepository;
use Stride\Modules\Trajectory\TrajectoryService;

$trajectory = $args['trajectory'];
$enrollment = $args['enrollment'];
$user = $args['user'];
$dashboardService = $args['dashboard_service'];

$trajectoryService = ntdst_get(TrajectoryService::class);
$trajectoryData = $trajectoryService->getTrajectory($trajectory->ID);

// Get elective groups
$electiveGroups = ntdst_get(TrajectoryRepository::class)->getElectiveGroups($trajectory->ID);

// Check choice window status
$choiceAvailable = $trajectoryData['choice_available_date'] ?? '';
$choiceDeadline = $trajectoryData['choice_deadline'] ?? '';

$now = time();
$windowOpen = false;
$windowBefore = false;
$windowAfter = false;

if (!empty($choiceAvailable) && !empty($choiceDeadline)) {
    $availableTime = strtotime($choiceAvailable);
    $deadlineTime = strtotime($choiceDeadline);

    if ($now < $availableTime) {
        $windowBefore = true;
    } elseif ($now >= $availableTime && $now <= $deadlineTime) {
        $windowOpen = true;
    } else {
        $windowAfter = true;
    }
}

// Get current selections
$selections = $enrollment->selections ? json_decode($enrollment->selections, true) : [];
?>

<div class="space-y-6">
    <h2 class="text-lg font-semibold text-text">
        <?php esc_html_e('Keuzecursussen', 'stridence'); ?>
    </h2>

    <?php if (empty($electiveGroups)) : ?>
        <?php
        stridence_template_part('partials/empty-state', null, [
            'icon' => 'check-square',
            'title' => __('Geen keuzevakken', 'stridence'),
            'message' => __('Dit traject heeft geen keuzecursussen.', 'stridence'),
        ]);
        ?>
    <?php elseif ($windowBefore) : ?>
        <!-- Choice window not yet open -->
        <div class="card p-6 text-center">
            <div class="w-16 h-16 rounded-full bg-accent/10 flex items-center justify-center mx-auto mb-4">
                <?php echo stridence_icon('clock', 'w-8 h-8 text-accent'); ?>
            </div>
            <h3 class="font-semibold text-text mb-2">
                <?php esc_html_e('Keuzemoment nog niet beschikbaar', 'stridence'); ?>
            </h3>
            <p class="text-text-muted mb-4">
                <?php
                printf(
                    esc_html__('Je kunt je keuzes maken vanaf %s.', 'stridence'),
                    esc_html(date_i18n('j F Y', strtotime($choiceAvailable)))
                );
                ?>
            </p>

            <!-- Preview electives -->
            <div class="text-left mt-6">
                <h4 class="text-sm font-medium text-text-muted uppercase tracking-wide mb-3">
                    <?php esc_html_e('Beschikbare keuzes', 'stridence'); ?>
                </h4>
                <?php foreach ($electiveGroups as $group) : ?>
                    <div class="mb-4">
                        <p class="text-sm font-medium text-text mb-2">
                            <?php echo esc_html($group['name'] ?? __('Keuzegroep', 'stridence')); ?>
                            <span class="text-text-muted font-normal">
                                (<?php printf(esc_html__('kies %d', 'stridence'), (int) ($group['required'] ?? 1)); ?>)
                            </span>
                        </p>
                        <ul class="text-sm text-text-muted space-y-1">
                            <?php foreach ($group['courses'] ?? [] as $course) : ?>
                                <li class="flex items-center gap-2">
                                    <span class="w-1.5 h-1.5 rounded-full bg-border"></span>
                                    <?php echo esc_html($course->post_title); ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

    <?php elseif ($windowOpen) : ?>
        <!-- Choice window is open -->
        <div class="card p-4 bg-success/5 border-success/20 mb-6">
            <div class="flex items-center gap-3">
                <?php echo stridence_icon('check-square', 'w-5 h-5 text-success'); ?>
                <div>
                    <p class="font-medium text-success">
                        <?php esc_html_e('Keuzemoment is open', 'stridence'); ?>
                    </p>
                    <p class="text-sm text-text-muted">
                        <?php
                        printf(
                            esc_html__('Maak je keuze voor %s.', 'stridence'),
                            esc_html(date_i18n('j F Y', strtotime($choiceDeadline)))
                        );
                        ?>
                    </p>
                </div>
            </div>
        </div>

        <form id="elective-selection-form" class="space-y-6">
            <?php foreach ($electiveGroups as $groupIndex => $group) :
                $groupName = $group['name'] ?? __('Keuzegroep', 'stridence');
                $required = (int) ($group['required'] ?? 1);
                $courses = $group['courses'] ?? [];
            ?>
                <div class="card p-4">
                    <h3 class="font-medium text-text mb-1">
                        <?php echo esc_html($groupName); ?>
                    </h3>
                    <p class="text-sm text-text-muted mb-4">
                        <?php printf(esc_html__('Selecteer %d cursus(sen)', 'stridence'), $required); ?>
                    </p>

                    <div class="space-y-2">
                        <?php foreach ($courses as $course) :
                            $isSelected = in_array($course->ID, $selections[$groupIndex] ?? [], true);
                            $inputType = $required === 1 ? 'radio' : 'checkbox';
                        ?>
                            <label class="flex items-center gap-3 p-3 rounded-lg border border-border hover:border-primary cursor-pointer transition-colors <?php echo $isSelected ? 'border-primary bg-primary/5' : ''; ?>">
                                <input type="<?php echo esc_attr($inputType); ?>"
                                       name="selections[<?php echo esc_attr($groupIndex); ?>]<?php echo $required > 1 ? '[]' : ''; ?>"
                                       value="<?php echo esc_attr($course->ID); ?>"
                                       <?php checked($isSelected); ?>
                                       class="text-primary">
                                <span class="text-text"><?php echo esc_html($course->post_title); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>

            <div class="flex justify-end">
                <button type="submit" class="btn-primary">
                    <?php esc_html_e('Keuzes opslaan', 'stridence'); ?>
                </button>
            </div>
        </form>

    <?php else : ?>
        <!-- Choice window closed -->
        <div class="card p-6">
            <div class="flex items-center gap-3 mb-4">
                <?php echo stridence_icon('check', 'w-5 h-5 text-success'); ?>
                <h3 class="font-medium text-text">
                    <?php esc_html_e('Keuzeperiode is gesloten', 'stridence'); ?>
                </h3>
            </div>

            <?php if (!empty($selections)) : ?>
                <p class="text-sm text-text-muted mb-4">
                    <?php esc_html_e('Jouw geselecteerde cursussen:', 'stridence'); ?>
                </p>

                <?php foreach ($electiveGroups as $groupIndex => $group) :
                    $groupSelections = $selections[$groupIndex] ?? [];
                    if (empty($groupSelections)) continue;
                ?>
                    <div class="mb-4">
                        <p class="text-sm font-medium text-text mb-2">
                            <?php echo esc_html($group['name'] ?? __('Keuzegroep', 'stridence')); ?>
                        </p>
                        <ul class="space-y-2">
                            <?php foreach ($group['courses'] ?? [] as $course) :
                                if (!in_array($course->ID, $groupSelections, true)) continue;
                            ?>
                                <li class="flex items-center gap-2 text-text">
                                    <?php echo stridence_icon('check', 'w-4 h-4 text-success'); ?>
                                    <?php echo esc_html($course->post_title); ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endforeach; ?>
            <?php else : ?>
                <p class="text-text-muted">
                    <?php esc_html_e('Er zijn geen keuzes gemaakt tijdens de keuzeperiode.', 'stridence'); ?>
                </p>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
