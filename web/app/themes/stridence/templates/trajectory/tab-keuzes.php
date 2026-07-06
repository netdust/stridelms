<?php
/**
 * Trajectory Tab: Keuzes (Elective Selection) — Helder Tij
 *
 * Handles elective course selection with choice window states.
 *
 * Restyle only: intro line with the deadline, selectable white cards
 * (border ring + filled radio when chosen, via :has(:checked)) and a
 * primary confirm button. The selection mechanism itself — form id,
 * input names/values, server-side window logic — is unchanged.
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

// Choice window state — the SERVICE is the single decision point
// (TrajectoryService::isChoiceWindowOpen; the server-side submit guard uses
// the same call). Shake-out BUG-4: this template re-derived the rule and
// required BOTH dates, so a trajectory without configured dates rendered
// "closed" while the server accepted submissions. No dates configured = open.
$choiceAvailable = $trajectoryData['choice_available_date'] ?? '';
$choiceDeadline = $trajectoryData['choice_deadline'] ?? '';

$windowOpen = $trajectoryService->isChoiceWindowOpen($trajectory->ID);
$windowBefore = !$windowOpen && !empty($choiceAvailable) && time() < strtotime($choiceAvailable);
$windowAfter = !$windowOpen && !$windowBefore;

// Current picks as COURSE ids — through the single decision point
// (TrajectorySelection::getSelectedCourseIds). The raw selections column
// stores flat EDITION ids; templates never re-derive from it.
$selectedCourseIds = ntdst_get(\Stride\Modules\Trajectory\TrajectorySelection::class)
    ->getSelectedCourseIds((int) ($enrollment->id ?? 0));
?>

<div class="space-y-6">
    <h2 class="text-lg font-bold text-text">
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
        <div class="bg-surface-card rounded-[14px] shadow-card p-6 text-center">
            <div class="w-14 h-14 rounded-full bg-surface-alt flex items-center justify-center mx-auto mb-4">
                <?php echo stridence_icon('clock', 'w-6 h-6 text-text-muted'); ?>
            </div>
            <h3 class="font-heading font-bold text-[16px] text-text mb-2">
                <?php esc_html_e('Keuzemoment nog niet beschikbaar', 'stridence'); ?>
            </h3>
            <p class="text-[13px] text-text-muted mb-4">
                <?php
                printf(
                    esc_html__('Je kunt je keuzes maken vanaf %s.', 'stridence'),
                    esc_html(date_i18n('j F Y', strtotime($choiceAvailable))),
                );
        ?>
            </p>

            <!-- Preview electives -->
            <div class="text-left mt-6">
                <h4 class="text-[12px] font-bold text-text-faint uppercase tracking-wide mb-3">
                    <?php esc_html_e('Beschikbare keuzes', 'stridence'); ?>
                </h4>
                <?php foreach ($electiveGroups as $group) : ?>
                    <div class="mb-4">
                        <p class="text-[13px] font-bold text-text mb-2">
                            <?php echo esc_html($group['name'] ?? __('Keuzegroep', 'stridence')); ?>
                            <span class="text-text-muted font-normal">
                                (<?php printf(esc_html__('kies %d', 'stridence'), (int) ($group['required'] ?? 1)); ?>)
                            </span>
                        </p>
                        <ul class="text-[13px] text-text-muted space-y-1.5">
                            <?php foreach ($group['courses'] ?? [] as $course) : ?>
                                <li class="flex items-center gap-2">
                                    <span class="w-1.5 h-1.5 rounded-full bg-border-soft"></span>
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
        <?php if (!empty($choiceDeadline)) : ?>
            <p class="text-[15px] text-text-muted leading-relaxed max-w-[640px]">
                <?php
                printf(
                    esc_html__('Maak je keuze voor %s.', 'stridence'),
                    esc_html(date_i18n('j F Y', strtotime($choiceDeadline))),
                );
            ?>
            </p>
        <?php endif; ?>

        <div x-data="strideTrajectoryChoices({ registrationId: <?php echo (int) ($enrollment->id ?? 0); ?> })">
        <form id="elective-selection-form" class="space-y-7" x-show="!saved" @submit.prevent="submit($el)">
            <?php foreach ($electiveGroups as $groupIndex => $group) :
                $groupName = $group['name'] ?? __('Keuzegroep', 'stridence');
                $required = (int) ($group['required'] ?? 1);
                $courses = $group['courses'] ?? [];
                $inputType = $required === 1 ? 'radio' : 'checkbox';
                $inputClass = $required === 1 ? 'input-radio' : 'input-checkbox';
                ?>
                <div>
                    <h3 class="text-[15px] font-bold text-text mb-0.5">
                        <?php echo esc_html($groupName); ?>
                    </h3>
                    <p class="text-[13px] text-text-muted mb-3.5">
                        <?php printf(esc_html__('Selecteer %d cursus(sen)', 'stridence'), $required); ?>
                    </p>

                    <div class="grid gap-3.5 [grid-template-columns:repeat(auto-fit,minmax(260px,1fr))]">
                        <?php foreach ($courses as $course) :
                            $isSelected = in_array((int) $course->ID, $selectedCourseIds, true);
                            // Metadata subline from the elective's next upcoming
                            // edition (format · sessions · venue). Empty for a
                            // pure-LD elective with no edition — line hidden then.
                            $electiveEditionId = stridence_trajectory_elective_edition_id((int) $course->ID);
                            $electiveMeta = stridence_trajectory_meta_line($electiveEditionId);
                            ?>
                            <label class="bg-surface-card rounded-[14px] border border-border-soft hover:border-border p-5 flex flex-col gap-2.5 cursor-pointer transition-all duration-fast has-[:checked]:border-primary has-[:checked]:shadow-[0_0_0_2px_rgb(var(--color-primary)/0.25)]">
                                <span class="flex items-start justify-between gap-2.5">
                                    <span class="text-[15px] font-bold leading-snug text-text">
                                        <?php echo esc_html($course->post_title); ?>
                                    </span>
                                    <input type="<?php echo esc_attr($inputType); ?>"
                                           name="elective_group_<?php echo esc_attr($groupIndex); ?>"
                                           value="<?php echo esc_attr($course->ID); ?>"
                                           <?php checked($isSelected); ?>
                                           class="<?php echo esc_attr($inputClass); ?> mt-px">
                                </span>
                                <?php if (!empty($course->post_excerpt)) : ?>
                                    <span class="text-[13px] text-text-muted leading-relaxed">
                                        <?php echo esc_html($course->post_excerpt); ?>
                                    </span>
                                <?php endif; ?>
                                <?php if ($electiveMeta !== '') : ?>
                                    <span class="text-[12px] text-text-faint">
                                        <?php echo esc_html($electiveMeta); ?>
                                    </span>
                                <?php endif; ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>

            <p x-show="error" x-text="error" x-cloak class="text-error text-sm"></p>

            <div class="flex flex-wrap items-center gap-3">
                <button type="submit" class="btn-primary" :disabled="loading">
                    <span x-show="!loading"><?php esc_html_e('Bevestig je keuze', 'stridence'); ?></span>
                    <span x-show="loading" x-cloak><?php esc_html_e('Bezig...', 'stridence'); ?></span>
                </button>
                <?php if (!empty($choiceDeadline)) : ?>
                    <span class="text-[13px] text-text-faint">
                        <?php
                        printf(
                            esc_html__('Je kunt je keuze tot %s aanpassen.', 'stridence'),
                            esc_html(date_i18n('j F Y', strtotime($choiceDeadline))),
                        );
                    ?>
                    </span>
                <?php endif; ?>
            </div>
        </form>
        </div>

        <script>
        function strideTrajectoryChoices(config) {
            return {
                loading: false,
                saved: false,
                error: '',
                async submit(form) {
                    this.loading = true;
                    this.error = '';
                    const selections = Array.from(
                        form.querySelectorAll('input[type="radio"]:checked, input[type="checkbox"]:checked'),
                    ).map((input) => parseInt(input.value, 10));
                    try {
                        await ntdstAPI.call('stride_save_trajectory_choices', {
                            registration_id: config.registrationId,
                            selections: selections,
                        });
                        // Server is the source of truth for the rendered
                        // summary — hide the form and re-render immediately.
                        this.saved = true;
                        window.location.reload();
                    } catch (e) {
                        this.error = e.message || '<?php echo esc_js(__('Er is een fout opgetreden.', 'stridence')); ?>';
                    } finally {
                        this.loading = false;
                    }
                }
            };
        }
        </script>

    <?php else : ?>
        <!-- Choice window closed -->
        <div class="bg-surface-card rounded-[14px] shadow-card p-6">
            <div class="flex items-center gap-3 mb-4">
                <?php echo stridence_icon('check', 'w-5 h-5 text-success'); ?>
                <h3 class="text-[15px] font-bold text-text">
                    <?php esc_html_e('Keuzeperiode is gesloten', 'stridence'); ?>
                </h3>
            </div>

            <?php if (!empty($selectedCourseIds)) : ?>
                <p class="text-[13px] text-text-muted mb-4">
                    <?php esc_html_e('Jouw geselecteerde cursussen:', 'stridence'); ?>
                </p>

                <?php foreach ($electiveGroups as $group) :
                    $groupChosen = array_filter(
                        $group['courses'] ?? [],
                        fn($course): bool => in_array((int) $course->ID, $selectedCourseIds, true),
                    );
                    if (empty($groupChosen)) {
                        continue;
                    }
                    ?>
                    <div class="mb-4">
                        <p class="text-[13px] font-bold text-text mb-2">
                            <?php echo esc_html($group['name'] ?? __('Keuzegroep', 'stridence')); ?>
                        </p>
                        <ul class="space-y-2">
                            <?php foreach ($groupChosen as $course) : ?>
                                <li class="flex items-center gap-2 text-[14px] text-text">
                                    <?php echo stridence_icon('check', 'w-4 h-4 text-success'); ?>
                                    <?php echo esc_html($course->post_title); ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endforeach; ?>
            <?php else : ?>
                <p class="text-[13px] text-text-muted">
                    <?php esc_html_e('Er zijn geen keuzes gemaakt tijdens de keuzeperiode.', 'stridence'); ?>
                </p>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
