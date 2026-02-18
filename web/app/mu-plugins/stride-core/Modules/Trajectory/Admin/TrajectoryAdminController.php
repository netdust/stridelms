<?php

declare(strict_types=1);

namespace Stride\Modules\Trajectory\Admin;

use Stride\Domain\TrajectoryMode;
use Stride\Domain\TrajectoryStatus;
use Stride\Infrastructure\AbstractService;
use Stride\Modules\Trajectory\TrajectoryCPT;
use Stride\Modules\Trajectory\TrajectoryRepository;
use Stride\Modules\Trajectory\TrajectoryService;
use Stride\Modules\Trajectory\TrajectoryEnrollmentRepository;
use WP_Post;

/**
 * Trajectory Admin Controller.
 *
 * Admin interface for trajectory management with separate metaboxes:
 * - Details (general settings, deadlines)
 * - Courses (course builder)
 * - Enrollments
 * - Sidebar (status & stats)
 */
final class TrajectoryAdminController extends AbstractService
{
    public const NONCE_SAVE = 'stride_save_trajectory';
    public const NONCE_FIELD = 'stride_trajectory_nonce';
    private const NONCE_AJAX = 'stride_trajectory_admin';

    public function __construct(
        private readonly TrajectoryService $trajectoryService,
        private readonly TrajectoryRepository $repository,
        private readonly TrajectoryEnrollmentRepository $enrollmentRepository,
    ) {
        parent::__construct();
    }

    public static function metadata(): array
    {
        return [
            'name' => 'Trajectory Admin Controller',
            'description' => 'Admin interface for trajectory management',
            'priority' => 100,
        ];
    }

    protected function getConfigSlug(): string
    {
        return 'trajectory-admin';
    }

    protected function init(): void
    {
        if (!is_admin()) {
            return;
        }

        add_action('add_meta_boxes', [$this, 'registerMetaboxes']);
        add_action('save_post_' . TrajectoryCPT::POST_TYPE, [$this, 'handleSave'], 10, 2);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);

        // AJAX endpoints
        add_action('wp_ajax_stride_search_courses', [$this, 'ajaxSearchCourses']);
        add_action('wp_ajax_stride_get_course_editions', [$this, 'ajaxGetCourseEditions']);
        add_action('wp_ajax_stride_get_trajectory_enrollments', [$this, 'ajaxGetEnrollments']);
    }

    public function registerMetaboxes(): void
    {
        // Remove default editor
        remove_post_type_support(TrajectoryCPT::POST_TYPE, 'editor');

        // Main details metabox
        add_meta_box(
            'stride_trajectory_details',
            __('Traject Details', 'stride'),
            [$this, 'renderDetailsMetabox'],
            TrajectoryCPT::POST_TYPE,
            'normal',
            'high'
        );

        // Courses metabox
        add_meta_box(
            'stride_trajectory_courses',
            __('Cursussen', 'stride'),
            [$this, 'renderCoursesMetabox'],
            TrajectoryCPT::POST_TYPE,
            'normal',
            'default'
        );

        // Enrollments metabox
        add_meta_box(
            'stride_trajectory_enrollments',
            __('Inschrijvingen', 'stride'),
            [$this, 'renderEnrollmentsMetabox'],
            TrajectoryCPT::POST_TYPE,
            'normal',
            'default'
        );

        // Sidebar
        add_meta_box(
            'stride_trajectory_actions',
            __('Status & Statistieken', 'stride'),
            [$this, 'renderSidebarMetabox'],
            TrajectoryCPT::POST_TYPE,
            'side',
            'high'
        );
    }

    public function enqueueAssets(string $hook): void
    {
        global $post_type, $post;

        if ($post_type !== TrajectoryCPT::POST_TYPE) {
            return;
        }

        // Select2 from CDN
        wp_enqueue_style(
            'select2',
            'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css',
            [],
            '4.1.0'
        );
        wp_enqueue_script(
            'select2',
            'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js',
            ['jquery'],
            '4.1.0',
            true
        );

        // Trajectory admin styles
        $cssFile = get_stylesheet_directory() . '/assets/css/admin/trajectory-admin.css';
        if (file_exists($cssFile)) {
            wp_enqueue_style(
                'stride-trajectory-admin',
                get_stylesheet_directory_uri() . '/assets/css/admin/trajectory-admin.css',
                ['select2'],
                filemtime($cssFile)
            );
        }

        // Trajectory admin scripts
        $jsFile = get_stylesheet_directory() . '/assets/js/admin/trajectory-admin.js';
        if (file_exists($jsFile)) {
            wp_enqueue_script(
                'stride-trajectory-admin',
                get_stylesheet_directory_uri() . '/assets/js/admin/trajectory-admin.js',
                ['jquery', 'select2'],
                filemtime($jsFile),
                true
            );

            wp_localize_script('stride-trajectory-admin', 'strideTrajectoryAdmin', [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce(self::NONCE_AJAX),
                'trajectoryId' => $post ? $post->ID : 0,
                'i18n' => [
                    'searchCourse' => __('Zoek cursus...', 'stride'),
                    'selectEdition' => __('Selecteer editie...', 'stride'),
                    'noResults' => __('Geen resultaten gevonden', 'stride'),
                    'addGroup' => __('Nieuwe groep', 'stride'),
                    'groupName' => __('Groepnaam', 'stride'),
                    'pickCount' => __('Kies', 'stride'),
                    'remove' => __('Verwijderen', 'stride'),
                    'confirmDeleteGroup' => __('Weet je zeker dat je deze groep wilt verwijderen?', 'stride'),
                    'error' => __('Er ging iets mis.', 'stride'),
                ],
            ]);
        }
    }

    public function renderDetailsMetabox(WP_Post $post): void
    {
        $trajectory = $this->trajectoryService->getTrajectory($post->ID);
        $isNew = !$trajectory;

        if ($isNew) {
            $trajectory = [
                'mode' => TrajectoryMode::Cohort->value,
                'description' => '',
                'capacity' => 0,
                'price' => 0,
                'price_non_member' => 0,
                'deadline_months' => null,
                'enrollment_deadline' => '',
                'choice_available_date' => '',
                'choice_deadline' => '',
                'courses' => [],
                'linked_editions' => [],
            ];
        }

        $currentMode = $trajectory['mode'] ?? TrajectoryMode::Cohort->value;
        $isCohort = $currentMode === TrajectoryMode::Cohort->value;
        $priceDisplay = ($trajectory['price'] ?? 0) / 100;
        $priceNonMemberDisplay = ($trajectory['price_non_member'] ?? 0) / 100;

        wp_nonce_field(self::NONCE_SAVE, self::NONCE_FIELD);
        ?>
        <style>
            .stride-trajectory-details { max-width: 700px; }
            .stride-section { margin-bottom: 24px; padding-bottom: 24px; border-bottom: 1px solid #f0f0f1; }
            .stride-section:last-child { border-bottom: none; margin-bottom: 0; padding-bottom: 0; }
            .stride-section h4 { margin: 0 0 12px 0; font-size: 13px; color: #1d2327; font-weight: 600; }
            .stride-field-row { display: flex; gap: 16px; margin-bottom: 12px; }
            .stride-field-row.two-col > .stride-field { flex: 1; }
            .stride-field { flex: 1; }
            .stride-field.half { flex: 0.5; }
            .stride-field label { display: block; font-size: 12px; font-weight: 600; margin-bottom: 4px; color: #1d2327; }
            .stride-field input, .stride-field select, .stride-field textarea { width: 100%; padding: 6px 10px; }
            .stride-field textarea { min-height: 80px; }
            .stride-field .description { font-size: 11px; color: #646970; margin-top: 4px; }
            .stride-cohort-only { display: <?php echo $isCohort ? 'block' : 'none'; ?>; }
            .stride-self-paced-only { display: <?php echo $isCohort ? 'none' : 'block'; ?>; }
            .mode-description { font-style: italic; }
            .stride-info-box { background: #f0f6fc; border-left: 4px solid #72aee6; padding: 12px 16px; margin: 12px 0; }
            .stride-info-box p { margin: 0; }
        </style>

        <div class="stride-trajectory-details">
            <!-- General Settings -->
            <div class="stride-section">
                <div class="stride-field-row">
                    <div class="stride-field half">
                        <label for="trajectory_mode"><?php esc_html_e('Modus', 'stride'); ?></label>
                        <select id="trajectory_mode" name="ntdst_fields[mode]">
                            <?php foreach (TrajectoryMode::cases() as $mode): ?>
                            <option value="<?php echo esc_attr($mode->value); ?>" <?php selected($currentMode, $mode->value); ?>>
                                <?php echo esc_html($mode->label()); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description mode-description">
                            <span class="mode-cohort" style="<?php echo $isCohort ? '' : 'display:none;'; ?>">
                                <?php esc_html_e('Vaste groep volgt vooraf gekoppelde edities samen.', 'stride'); ?>
                            </span>
                            <span class="mode-self-paced" style="<?php echo $isCohort ? 'display:none;' : ''; ?>">
                                <?php esc_html_e('Deelnemer kiest zelf edities en volgt eigen tempo.', 'stride'); ?>
                            </span>
                        </p>
                    </div>
                </div>

                <div class="stride-field-row">
                    <div class="stride-field">
                        <label for="trajectory_description"><?php esc_html_e('Beschrijving', 'stride'); ?></label>
                        <textarea id="trajectory_description" name="ntdst_fields[description]" placeholder="<?php esc_attr_e('Omschrijving van het traject...', 'stride'); ?>"><?php echo esc_textarea($trajectory['description'] ?? ''); ?></textarea>
                    </div>
                </div>

                <div class="stride-field-row two-col">
                    <div class="stride-field">
                        <label for="trajectory_capacity"><?php esc_html_e('Capaciteit', 'stride'); ?></label>
                        <input type="number" id="trajectory_capacity" name="ntdst_fields[capacity]"
                               value="<?php echo esc_attr($trajectory['capacity'] ?? 0); ?>" min="0">
                        <p class="description"><?php esc_html_e('0 = onbeperkt', 'stride'); ?></p>
                    </div>
                    <div class="stride-field"></div>
                </div>

                <div class="stride-field-row two-col">
                    <div class="stride-field">
                        <label for="trajectory_price"><?php esc_html_e('Prijs leden (€)', 'stride'); ?></label>
                        <input type="number" id="trajectory_price" name="ntdst_fields[price]"
                               value="<?php echo esc_attr($priceDisplay); ?>" min="0" step="0.01">
                    </div>
                    <div class="stride-field">
                        <label for="trajectory_price_non_member"><?php esc_html_e('Prijs niet-leden (€)', 'stride'); ?></label>
                        <input type="number" id="trajectory_price_non_member" name="ntdst_fields[price_non_member]"
                               value="<?php echo esc_attr($priceNonMemberDisplay); ?>" min="0" step="0.01">
                    </div>
                </div>
            </div>

            <!-- Cohort Deadlines -->
            <div class="stride-section stride-cohort-only">
                <h4><?php esc_html_e('Deadlines', 'stride'); ?></h4>
                <div class="stride-field-row">
                    <div class="stride-field half">
                        <label for="trajectory_enrollment_deadline"><?php esc_html_e('Inschrijvingsdeadline', 'stride'); ?></label>
                        <input type="date" id="trajectory_enrollment_deadline" name="ntdst_fields[enrollment_deadline]"
                               value="<?php echo esc_attr($trajectory['enrollment_deadline'] ?? ''); ?>">
                    </div>
                </div>
                <div class="stride-field-row two-col">
                    <div class="stride-field">
                        <label for="trajectory_choice_available"><?php esc_html_e('Keuzes beschikbaar vanaf', 'stride'); ?></label>
                        <input type="date" id="trajectory_choice_available" name="ntdst_fields[choice_available_date]"
                               value="<?php echo esc_attr($trajectory['choice_available_date'] ?? ''); ?>">
                    </div>
                    <div class="stride-field">
                        <label for="trajectory_choice_deadline"><?php esc_html_e('Keuzedeadline', 'stride'); ?></label>
                        <input type="date" id="trajectory_choice_deadline" name="ntdst_fields[choice_deadline]"
                               value="<?php echo esc_attr($trajectory['choice_deadline'] ?? ''); ?>">
                    </div>
                </div>
            </div>

            <!-- Self-Paced Deadline -->
            <div class="stride-section stride-self-paced-only">
                <h4><?php esc_html_e('Doorlooptijd', 'stride'); ?></h4>
                <div class="stride-field-row">
                    <div class="stride-field half">
                        <label for="trajectory_deadline_months"><?php esc_html_e('Deadline (maanden)', 'stride'); ?></label>
                        <input type="number" id="trajectory_deadline_months" name="ntdst_fields[deadline_months]"
                               value="<?php echo esc_attr($trajectory['deadline_months'] ?? ''); ?>" min="0" step="1"
                               placeholder="<?php esc_attr_e('bijv. 18', 'stride'); ?>">
                        <p class="description"><?php esc_html_e('Aantal maanden vanaf inschrijving om traject te voltooien.', 'stride'); ?></p>
                    </div>
                </div>
            </div>

            <!-- Linked Editions (Cohort only) -->
            <?php $this->renderLinkedEditions($trajectory, $isCohort); ?>
        </div>
        <?php
    }

    private function renderLinkedEditions(array $trajectory, bool $isCohort): void
    {
        $courses = $trajectory['courses'] ?? [];
        $linkedEditions = $trajectory['linked_editions'] ?? [];
        $courseIds = array_unique(array_filter(array_map(fn($c) => (int) ($c['course_id'] ?? 0), $courses)));

        $linkedMap = [];
        foreach ($linkedEditions as $link) {
            $linkedMap[(int) $link['course_id']] = (int) $link['edition_id'];
        }

        if (empty($courseIds)) {
            return;
        }
        ?>
        <div class="stride-section stride-cohort-only">
            <h4><?php esc_html_e('Gekoppelde Edities', 'stride'); ?></h4>
            <p class="description"><?php esc_html_e('Koppel elke cursus aan een specifieke editie voor dit cohort.', 'stride'); ?></p>

            <table class="widefat striped" style="margin-top: 12px;">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Cursus', 'stride'); ?></th>
                        <th><?php esc_html_e('Editie', 'stride'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($courseIds as $courseId): ?>
                        <?php
                        $courseTitle = get_the_title($courseId);
                        $linkedEditionId = $linkedMap[$courseId] ?? 0;
                        ?>
                        <tr>
                            <td>
                                <?php echo esc_html($courseTitle ?: '#' . $courseId); ?>
                                <input type="hidden" name="ntdst_fields[linked_editions][<?php echo esc_attr($courseId); ?>][course_id]"
                                       value="<?php echo esc_attr($courseId); ?>">
                            </td>
                            <td>
                                <select name="ntdst_fields[linked_editions][<?php echo esc_attr($courseId); ?>][edition_id]"
                                        class="stride-edition-select"
                                        data-course-id="<?php echo esc_attr($courseId); ?>"
                                        style="width: 100%;">
                                    <option value=""><?php esc_html_e('Selecteer editie...', 'stride'); ?></option>
                                    <?php if ($linkedEditionId): ?>
                                        <option value="<?php echo esc_attr($linkedEditionId); ?>" selected>
                                            <?php echo esc_html(get_the_title($linkedEditionId) ?: '#' . $linkedEditionId); ?>
                                        </option>
                                    <?php endif; ?>
                                </select>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public function renderCoursesMetabox(WP_Post $post): void
    {
        $trajectory = $this->trajectoryService->getTrajectory($post->ID);
        $courses = $trajectory['courses'] ?? [];

        $requiredCourses = array_filter($courses, fn($c) => ($c['required'] ?? false) === true);
        $electiveCourses = array_filter($courses, fn($c) => ($c['required'] ?? false) === false);

        $electiveGroups = [];
        foreach ($electiveCourses as $course) {
            $group = $course['group'] ?? 'Keuze';
            if (!isset($electiveGroups[$group])) {
                $electiveGroups[$group] = [
                    'name' => $group,
                    'pick_count' => $course['pick_count'] ?? 1,
                    'courses' => [],
                ];
            }
            $electiveGroups[$group]['courses'][] = $course;
        }
        ?>
        <style>
            .stride-courses-section { margin-bottom: 24px; }
            .stride-courses-section h4 { margin: 0 0 12px; display: flex; justify-content: space-between; align-items: center; font-size: 13px; font-weight: 600; }
            .stride-course-list { list-style: none; margin: 0; padding: 0; }
            .stride-course-item { display: flex; align-items: center; padding: 8px 12px; background: #f6f7f7; border: 1px solid #dcdcde; margin-bottom: 4px; border-radius: 3px; }
            .stride-course-item .course-title { flex: 1; }
            .stride-course-item .remove-course { color: #b32d2e; cursor: pointer; }
            .stride-course-item .remove-course:hover { color: #a00; }
            .stride-add-course { margin-top: 8px; display: flex; gap: 8px; }
            .stride-add-course .select2-container { flex: 1; }
            .stride-elective-group { border: 1px solid #c3c4c7; padding: 16px; margin-bottom: 16px; border-radius: 4px; background: #fafafa; }
            .stride-elective-group .group-header { display: flex; gap: 12px; margin-bottom: 12px; align-items: flex-end; }
            .stride-elective-group .group-header .stride-field { margin-bottom: 0; }
            .stride-elective-group .group-footer { display: flex; justify-content: flex-end; margin-top: 12px; }
            .stride-no-courses { color: #646970; font-style: italic; padding: 12px; background: #f6f7f7; border-radius: 3px; }
            .stride-field label { display: block; font-size: 12px; font-weight: 600; margin-bottom: 4px; color: #1d2327; }
            .stride-field input { padding: 6px 10px; }
        </style>

        <!-- Required Courses -->
        <div class="stride-courses-section">
            <h4><?php esc_html_e('Verplichte Cursussen', 'stride'); ?></h4>

            <ul class="stride-course-list" id="stride-required-courses">
                <?php if (empty($requiredCourses)): ?>
                    <li class="stride-no-courses"><?php esc_html_e('Nog geen verplichte cursussen toegevoegd.', 'stride'); ?></li>
                <?php else: ?>
                    <?php foreach ($requiredCourses as $course): ?>
                        <?php $courseTitle = get_the_title($course['course_id']); ?>
                        <li class="stride-course-item" data-course-id="<?php echo esc_attr($course['course_id']); ?>">
                            <span class="course-title"><?php echo esc_html($courseTitle ?: '#' . $course['course_id']); ?></span>
                            <span class="remove-course dashicons dashicons-no-alt" title="<?php esc_attr_e('Verwijderen', 'stride'); ?>"></span>
                            <input type="hidden" name="ntdst_fields[courses_required][]" value="<?php echo esc_attr($course['course_id']); ?>">
                        </li>
                    <?php endforeach; ?>
                <?php endif; ?>
            </ul>

            <div class="stride-add-course">
                <select id="stride-add-required-course" class="stride-course-select" style="width: 100%;">
                    <option value=""><?php esc_html_e('Zoek cursus...', 'stride'); ?></option>
                </select>
                <button type="button" class="button" id="stride-add-required-btn"><?php esc_html_e('Toevoegen', 'stride'); ?></button>
            </div>
        </div>

        <!-- Elective Groups -->
        <div class="stride-courses-section">
            <h4>
                <?php esc_html_e('Keuzevakken', 'stride'); ?>
                <button type="button" class="button" id="stride-add-group-btn"><?php esc_html_e('+ Nieuwe groep', 'stride'); ?></button>
            </h4>

            <div id="stride-elective-groups">
                <?php if (empty($electiveGroups)): ?>
                    <p class="stride-no-courses"><?php esc_html_e('Nog geen keuzegroepen. Klik op "+ Nieuwe groep" om te beginnen.', 'stride'); ?></p>
                <?php else: ?>
                    <?php $groupIndex = 0; foreach ($electiveGroups as $groupName => $group): ?>
                        <?php $this->renderElectiveGroup($groupIndex, $group); $groupIndex++; ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <script type="text/template" id="stride-elective-group-template">
            <?php $this->renderElectiveGroup('__INDEX__', ['name' => '', 'pick_count' => 1, 'courses' => []]); ?>
        </script>
        <?php
    }

    private function renderElectiveGroup(int|string $index, array $group): void
    {
        $namePrefix = "ntdst_fields[elective_groups][{$index}]";
        ?>
        <div class="stride-elective-group" data-group-index="<?php echo esc_attr($index); ?>">
            <div class="group-header">
                <div class="stride-field" style="flex: 2;">
                    <label><?php esc_html_e('Groepnaam', 'stride'); ?></label>
                    <input type="text" name="<?php echo esc_attr($namePrefix); ?>[name]"
                           value="<?php echo esc_attr($group['name']); ?>"
                           placeholder="<?php esc_attr_e('bijv. Specialisatie', 'stride'); ?>">
                </div>
                <div class="stride-field" style="flex: 0 0 100px;">
                    <label><?php esc_html_e('Kies', 'stride'); ?></label>
                    <input type="number" name="<?php echo esc_attr($namePrefix); ?>[pick_count]"
                           value="<?php echo esc_attr($group['pick_count']); ?>" min="1" max="10">
                </div>
            </div>

            <ul class="stride-course-list stride-elective-course-list">
                <?php if (empty($group['courses'])): ?>
                    <li class="stride-no-courses"><?php esc_html_e('Nog geen cursussen in deze groep.', 'stride'); ?></li>
                <?php else: ?>
                    <?php foreach ($group['courses'] as $course): ?>
                        <?php $courseTitle = get_the_title($course['course_id']); ?>
                        <li class="stride-course-item" data-course-id="<?php echo esc_attr($course['course_id']); ?>">
                            <span class="course-title"><?php echo esc_html($courseTitle ?: '#' . $course['course_id']); ?></span>
                            <span class="remove-course dashicons dashicons-no-alt" title="<?php esc_attr_e('Verwijderen', 'stride'); ?>"></span>
                            <input type="hidden" name="<?php echo esc_attr($namePrefix); ?>[courses][]" value="<?php echo esc_attr($course['course_id']); ?>">
                        </li>
                    <?php endforeach; ?>
                <?php endif; ?>
            </ul>

            <div class="stride-add-course">
                <select class="stride-course-select stride-elective-course-select" style="width: 100%;">
                    <option value=""><?php esc_html_e('Zoek cursus...', 'stride'); ?></option>
                </select>
                <button type="button" class="button stride-add-elective-btn"><?php esc_html_e('Toevoegen', 'stride'); ?></button>
            </div>

            <div class="group-footer">
                <button type="button" class="button-link stride-delete-group" style="color: #b32d2e;">
                    <span class="dashicons dashicons-trash"></span> <?php esc_html_e('Groep verwijderen', 'stride'); ?>
                </button>
            </div>
        </div>
        <?php
    }

    public function renderEnrollmentsMetabox(WP_Post $post): void
    {
        if ($post->post_status === 'auto-draft') {
            echo '<p class="description">' . esc_html__('Sla het traject eerst op om inschrijvingen te zien.', 'stride') . '</p>';
            return;
        }

        $enrollments = $this->enrollmentRepository->findByTrajectory($post->ID);
        $trajectory = $this->trajectoryService->getTrajectory($post->ID);
        $totalCourses = count($trajectory['courses'] ?? []);
        ?>
        <style>
            .stride-enrollments-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
            .stride-enrollments-filters { display: flex; gap: 12px; }
            .stride-enrollments-filters input, .stride-enrollments-filters select { padding: 4px 8px; }
            .stride-enrollment-count { font-weight: 600; color: #1d2327; }
            .stride-progress-bar { width: 100px; height: 12px; background: #dcdcde; border-radius: 6px; overflow: hidden; display: inline-block; vertical-align: middle; }
            .stride-progress-bar-fill { height: 100%; background: #00a32a; transition: width 0.3s; }
            .stride-progress-text { margin-left: 8px; font-size: 11px; color: #646970; }
            .stride-status-badge { padding: 2px 8px; border-radius: 3px; font-size: 11px; font-weight: 500; }
            .stride-status-enrolled { background: #d4edda; color: #155724; }
            .stride-status-completed { background: #cce5ff; color: #004085; }
            .stride-status-paused { background: #fff3cd; color: #856404; }
            .stride-status-cancelled { background: #f8d7da; color: #721c24; }
            .stride-enrollments-empty { text-align: center; padding: 40px; color: #646970; }
        </style>

        <div class="stride-enrollments-header">
            <span class="stride-enrollment-count">
                <?php printf(esc_html__('%d deelnemers ingeschreven', 'stride'), count($enrollments)); ?>
            </span>
            <div class="stride-enrollments-filters">
                <input type="text" id="stride-enrollment-search" placeholder="<?php esc_attr_e('Zoeken...', 'stride'); ?>">
                <select id="stride-enrollment-status-filter">
                    <option value=""><?php esc_html_e('Alle statussen', 'stride'); ?></option>
                    <option value="enrolled"><?php esc_html_e('Actief', 'stride'); ?></option>
                    <option value="completed"><?php esc_html_e('Voltooid', 'stride'); ?></option>
                    <option value="paused"><?php esc_html_e('Pauze', 'stride'); ?></option>
                    <option value="cancelled"><?php esc_html_e('Geannuleerd', 'stride'); ?></option>
                </select>
            </div>
        </div>

        <?php if (empty($enrollments)): ?>
            <div class="stride-enrollments-empty">
                <span class="dashicons dashicons-groups" style="font-size: 48px; width: 48px; height: 48px; color: #dcdcde;"></span>
                <p><?php esc_html_e('Nog geen inschrijvingen voor dit traject.', 'stride'); ?></p>
            </div>
        <?php else: ?>
            <table class="widefat striped" id="stride-enrollments-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Deelnemer', 'stride'); ?></th>
                        <th><?php esc_html_e('Status', 'stride'); ?></th>
                        <th><?php esc_html_e('Voortgang', 'stride'); ?></th>
                        <th><?php esc_html_e('Ingeschreven', 'stride'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($enrollments as $enrollment): ?>
                        <?php
                        $user = get_userdata($enrollment['user_id']);
                        $userName = $user ? ($user->display_name ?: $user->user_email) : __('Onbekend', 'stride');
                        $status = $enrollment['status'] ?? 'enrolled';
                        $enrolledAt = $enrollment['enrolled_at'] ?? '';
                        $completedCourses = 0;
                        $progressPercent = $totalCourses > 0 ? round(($completedCourses / $totalCourses) * 100) : 0;

                        $statusLabels = [
                            'enrolled' => __('Actief', 'stride'),
                            'completed' => __('Voltooid', 'stride'),
                            'paused' => __('Pauze', 'stride'),
                            'cancelled' => __('Geannuleerd', 'stride'),
                        ];
                        ?>
                        <tr data-status="<?php echo esc_attr($status); ?>" data-name="<?php echo esc_attr(strtolower($userName)); ?>">
                            <td>
                                <?php if ($user): ?>
                                    <a href="<?php echo esc_url(get_edit_user_link($user->ID)); ?>"><?php echo esc_html($userName); ?></a>
                                <?php else: ?>
                                    <?php echo esc_html($userName); ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="stride-status-badge stride-status-<?php echo esc_attr($status); ?>">
                                    <?php echo esc_html($statusLabels[$status] ?? $status); ?>
                                </span>
                            </td>
                            <td>
                                <div class="stride-progress-bar">
                                    <div class="stride-progress-bar-fill" style="width: <?php echo esc_attr($progressPercent); ?>%;"></div>
                                </div>
                                <span class="stride-progress-text"><?php echo esc_html("{$completedCourses}/{$totalCourses}"); ?></span>
                            </td>
                            <td>
                                <?php echo esc_html($enrolledAt ? date_i18n('d M Y', strtotime($enrolledAt)) : '-'); ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <?php
    }

    public function renderSidebarMetabox(WP_Post $post): void
    {
        $trajectory = $this->trajectoryService->getTrajectory($post->ID);

        if (!$trajectory) {
            echo '<p class="description">' . esc_html__('Sla eerst op om statistieken te zien.', 'stride') . '</p>';
            return;
        }

        $status = TrajectoryStatus::tryFrom($trajectory['status'] ?? '') ?? TrajectoryStatus::Draft;
        $mode = TrajectoryMode::tryFrom($trajectory['mode'] ?? '') ?? TrajectoryMode::Cohort;
        $courseCount = count($trajectory['courses'] ?? []);

        $enrollments = $this->enrollmentRepository->findByTrajectory($post->ID);
        $activeCount = count(array_filter($enrollments, fn($e) => $e['status'] === 'enrolled'));
        $completedCount = count(array_filter($enrollments, fn($e) => $e['status'] === 'completed'));
        ?>
        <style>
            .stride-trajectory-sidebar .stride-sidebar-section { margin-bottom: 16px; padding-bottom: 16px; border-bottom: 1px solid #f0f0f1; }
            .stride-trajectory-sidebar .stride-sidebar-section:last-child { border-bottom: none; margin-bottom: 0; padding-bottom: 0; }
            .stride-trajectory-sidebar label { display: block; font-size: 11px; font-weight: 600; text-transform: uppercase; color: #646970; margin-bottom: 6px; }
            .stride-trajectory-sidebar select { width: 100%; }
            .stride-sidebar-meta { list-style: none; margin: 0; padding: 0; }
            .stride-sidebar-meta li { display: flex; justify-content: space-between; padding: 6px 0; font-size: 12px; }
            .stride-sidebar-meta .meta-label { color: #646970; }
            .stride-sidebar-meta .meta-value { font-weight: 500; color: #1d2327; }
        </style>

        <div class="stride-trajectory-sidebar">
            <div class="stride-sidebar-section">
                <label for="trajectory_status"><?php esc_html_e('Status', 'stride'); ?></label>
                <select id="trajectory_status" name="ntdst_fields[status]">
                    <?php foreach (TrajectoryStatus::cases() as $statusOption): ?>
                    <option value="<?php echo esc_attr($statusOption->value); ?>" <?php selected($status->value, $statusOption->value); ?>>
                        <?php echo esc_html($statusOption->label()); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="stride-sidebar-section">
                <ul class="stride-sidebar-meta">
                    <li>
                        <span class="meta-label"><?php esc_html_e('Modus', 'stride'); ?></span>
                        <span class="meta-value"><?php echo esc_html($mode->label()); ?></span>
                    </li>
                    <li>
                        <span class="meta-label"><?php esc_html_e('Cursussen', 'stride'); ?></span>
                        <span class="meta-value"><?php echo esc_html($courseCount); ?></span>
                    </li>
                    <li>
                        <span class="meta-label"><?php esc_html_e('Actief', 'stride'); ?></span>
                        <span class="meta-value"><?php echo esc_html($activeCount); ?></span>
                    </li>
                    <li>
                        <span class="meta-label"><?php esc_html_e('Voltooid', 'stride'); ?></span>
                        <span class="meta-value"><?php echo esc_html($completedCount); ?></span>
                    </li>
                </ul>
            </div>

            <?php if ($mode === TrajectoryMode::Cohort): ?>
                <?php if (!empty($trajectory['enrollment_deadline']) || !empty($trajectory['choice_deadline'])): ?>
                <div class="stride-sidebar-section">
                    <ul class="stride-sidebar-meta">
                        <?php if (!empty($trajectory['enrollment_deadline'])): ?>
                        <li>
                            <span class="meta-label"><?php esc_html_e('Inschrijving tot', 'stride'); ?></span>
                            <span class="meta-value"><?php echo esc_html(date_i18n('d M Y', strtotime($trajectory['enrollment_deadline']))); ?></span>
                        </li>
                        <?php endif; ?>
                        <?php if (!empty($trajectory['choice_deadline'])): ?>
                        <li>
                            <span class="meta-label"><?php esc_html_e('Keuze tot', 'stride'); ?></span>
                            <span class="meta-value"><?php echo esc_html(date_i18n('d M Y', strtotime($trajectory['choice_deadline']))); ?></span>
                        </li>
                        <?php endif; ?>
                    </ul>
                </div>
                <?php endif; ?>
            <?php elseif (!empty($trajectory['deadline_months'])): ?>
                <div class="stride-sidebar-section">
                    <ul class="stride-sidebar-meta">
                        <li>
                            <span class="meta-label"><?php esc_html_e('Deadline', 'stride'); ?></span>
                            <span class="meta-value"><?php printf(esc_html__('%d maanden', 'stride'), $trajectory['deadline_months']); ?></span>
                        </li>
                    </ul>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    public function handleSave(int $postId, WP_Post $post): void
    {
        if (!isset($_POST[self::NONCE_FIELD]) ||
            !wp_verify_nonce($_POST[self::NONCE_FIELD], self::NONCE_SAVE)) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (!current_user_can('edit_post', $postId)) {
            return;
        }

        $fields = $_POST['ntdst_fields'] ?? [];
        if (empty($fields)) {
            return;
        }

        $updateData = [];

        if (isset($fields['mode'])) {
            $mode = sanitize_text_field($fields['mode']);
            if (TrajectoryMode::tryFrom($mode)) {
                $updateData['mode'] = $mode;
            }
        }

        if (isset($fields['status'])) {
            $status = sanitize_text_field($fields['status']);
            if (TrajectoryStatus::tryFrom($status)) {
                $updateData['status'] = $status;
            }
        }

        if (isset($fields['description'])) {
            $updateData['description'] = sanitize_textarea_field($fields['description']);
        }

        if (isset($fields['capacity'])) {
            $updateData['capacity'] = absint($fields['capacity']);
        }

        if (isset($fields['price'])) {
            $updateData['price'] = (int) round((float) $fields['price'] * 100);
        }
        if (isset($fields['price_non_member'])) {
            $updateData['price_non_member'] = (int) round((float) $fields['price_non_member'] * 100);
        }

        if (isset($fields['deadline_months'])) {
            $updateData['deadline_months'] = absint($fields['deadline_months']) ?: null;
        }

        if (isset($fields['enrollment_deadline'])) {
            $updateData['enrollment_deadline'] = sanitize_text_field($fields['enrollment_deadline']);
        }
        if (isset($fields['choice_available_date'])) {
            $updateData['choice_available_date'] = sanitize_text_field($fields['choice_available_date']);
        }
        if (isset($fields['choice_deadline'])) {
            $updateData['choice_deadline'] = sanitize_text_field($fields['choice_deadline']);
        }

        $courses = [];

        if (!empty($fields['courses_required']) && is_array($fields['courses_required'])) {
            foreach ($fields['courses_required'] as $courseId) {
                $courseId = absint($courseId);
                if ($courseId > 0) {
                    $courses[] = ['course_id' => $courseId, 'required' => true];
                }
            }
        }

        if (!empty($fields['elective_groups']) && is_array($fields['elective_groups'])) {
            foreach ($fields['elective_groups'] as $group) {
                $groupName = sanitize_text_field($group['name'] ?? '');
                $pickCount = absint($group['pick_count'] ?? 1);

                if (!empty($groupName) && !empty($group['courses']) && is_array($group['courses'])) {
                    foreach ($group['courses'] as $courseId) {
                        $courseId = absint($courseId);
                        if ($courseId > 0) {
                            $courses[] = [
                                'course_id' => $courseId,
                                'required' => false,
                                'group' => $groupName,
                                'pick_count' => $pickCount,
                            ];
                        }
                    }
                }
            }
        }

        $updateData['courses'] = $courses;

        if (!empty($fields['linked_editions']) && is_array($fields['linked_editions'])) {
            $linkedEditions = [];
            foreach ($fields['linked_editions'] as $link) {
                $courseId = absint($link['course_id'] ?? 0);
                $editionId = absint($link['edition_id'] ?? 0);
                if ($courseId > 0 && $editionId > 0) {
                    $linkedEditions[] = ['course_id' => $courseId, 'edition_id' => $editionId];
                }
            }
            $updateData['linked_editions'] = $linkedEditions;
        }

        if (!empty($updateData)) {
            $this->repository->update($postId, $updateData);
        }
    }

    // === AJAX Endpoints ===

    public function ajaxSearchCourses(): void
    {
        if (!$this->verifyAjaxNonce()) {
            return;
        }

        $search = sanitize_text_field($_POST['search'] ?? '');

        $args = [
            'post_type' => 'sfwd-courses',
            'post_status' => 'publish',
            'posts_per_page' => 20,
            'orderby' => 'title',
            'order' => 'ASC',
        ];

        if (!empty($search)) {
            $args['s'] = $search;
        }

        $courses = get_posts($args);
        $results = [];

        foreach ($courses as $course) {
            $results[] = ['id' => $course->ID, 'text' => $course->post_title];
        }

        wp_send_json_success(['results' => $results]);
    }

    public function ajaxGetCourseEditions(): void
    {
        if (!$this->verifyAjaxNonce()) {
            return;
        }

        $courseId = absint($_POST['course_id'] ?? 0);
        if (!$courseId) {
            wp_send_json_error(['message' => 'Invalid course ID']);
            return;
        }

        $editionService = ntdst_get(\Stride\Modules\Edition\EditionService::class);
        $editions = $editionService->getEditionsForCourse($courseId);

        $results = [];
        foreach ($editions as $edition) {
            $label = $edition['start_date'] ?? '';
            if (!empty($edition['venue'])) {
                $label .= ' - ' . $edition['venue'];
            }
            if (empty($label)) {
                $label = '#' . $edition['id'];
            }

            $results[] = ['id' => $edition['id'], 'text' => $label];
        }

        wp_send_json_success(['results' => $results]);
    }

    public function ajaxGetEnrollments(): void
    {
        if (!$this->verifyAjaxNonce()) {
            return;
        }

        $trajectoryId = absint($_POST['trajectory_id'] ?? 0);
        $page = absint($_POST['page'] ?? 1);
        $perPage = 20;
        $status = sanitize_text_field($_POST['status'] ?? '');
        $search = sanitize_text_field($_POST['search'] ?? '');

        if (!$trajectoryId) {
            wp_send_json_error(['message' => 'Invalid trajectory ID']);
            return;
        }

        $enrollments = $this->enrollmentRepository->findByTrajectory($trajectoryId);

        if (!empty($status)) {
            $enrollments = array_filter($enrollments, fn($e) => $e['status'] === $status);
        }

        if (!empty($search)) {
            $enrollments = array_filter($enrollments, function($e) use ($search) {
                $user = get_userdata($e['user_id']);
                if (!$user) return false;
                $name = strtolower($user->display_name . ' ' . $user->user_email);
                return str_contains($name, strtolower($search));
            });
        }

        $total = count($enrollments);
        $enrollments = array_slice($enrollments, ($page - 1) * $perPage, $perPage);

        $results = [];
        foreach ($enrollments as $enrollment) {
            $user = get_userdata($enrollment['user_id']);
            $results[] = [
                'id' => $enrollment['id'],
                'user_id' => $enrollment['user_id'],
                'user_name' => $user ? ($user->display_name ?: $user->user_email) : 'Unknown',
                'status' => $enrollment['status'],
                'enrolled_at' => $enrollment['enrolled_at'],
            ];
        }

        wp_send_json_success([
            'enrollments' => $results,
            'total' => $total,
            'page' => $page,
            'pages' => ceil($total / $perPage),
        ]);
    }

    private function verifyAjaxNonce(): bool
    {
        if (!check_ajax_referer(self::NONCE_AJAX, 'nonce', false)) {
            wp_send_json_error(['message' => 'Invalid security token'], 403);
            return false;
        }

        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => 'Unauthorized'], 403);
            return false;
        }

        return true;
    }
}
