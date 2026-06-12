<?php

declare(strict_types=1);

namespace Stride\Modules\Trajectory\Admin;

use Stride\Domain\TrajectoryMode;
use Stride\Domain\OfferingStatus;
use Stride\Modules\Edition\EditionRepository;
use Stride\Modules\Enrollment\RegistrationRepository;
use Stride\Modules\Trajectory\TrajectoryCPT;
use Stride\Modules\Trajectory\TrajectoryRepository;
use Stride\Modules\Trajectory\TrajectoryService;
use WP_Post;

/**
 * Trajectory Admin Controller.
 *
 * Admin interface for trajectory management with separate metaboxes:
 * - Details (general settings, deadlines)
 * - Courses (course builder)
 * - Enrollments
 * - Sidebar (status & stats)
 *
 * Plain class — owned by TrajectoryService.
 */
final class TrajectoryAdminController
{
    public const NONCE_SAVE = 'stride_save_trajectory';
    public const NONCE_FIELD = 'stride_trajectory_nonce';
    private const NONCE_AJAX = 'stride_trajectory_admin';

    public function __construct(
        private readonly TrajectoryService $trajectoryService,
        private readonly TrajectoryRepository $repository,
        private readonly RegistrationRepository $registrations,
        private readonly EditionRepository $editionRepository,
    ) {
        $this->init();
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
        add_action('wp_ajax_stride_search_courses_editions', [$this, 'ajaxSearchCoursesAndEditions']);
        add_action('wp_ajax_stride_get_trajectory_enrollments', [$this, 'ajaxGetEnrollments']);

        // Admin list columns
        add_filter('manage_' . TrajectoryCPT::POST_TYPE . '_posts_columns', [$this, 'defineListColumns']);
        add_action('manage_' . TrajectoryCPT::POST_TYPE . '_posts_custom_column', [$this, 'renderListColumn'], 10, 2);
        add_filter('manage_edit-' . TrajectoryCPT::POST_TYPE . '_sortable_columns', [$this, 'defineSortableColumns']);
        add_action('pre_get_posts', [$this, 'handleColumnSorting']);
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
            'high',
        );

        // Courses metabox
        add_meta_box(
            'stride_trajectory_courses',
            __('Cursussen', 'stride'),
            [$this, 'renderCoursesMetabox'],
            TrajectoryCPT::POST_TYPE,
            'normal',
            'default',
        );

        // Enrollments metabox
        add_meta_box(
            'stride_trajectory_enrollments',
            __('Inschrijvingen', 'stride'),
            [$this, 'renderEnrollmentsMetabox'],
            TrajectoryCPT::POST_TYPE,
            'normal',
            'default',
        );

        // Messages metabox
        add_meta_box(
            'stride_trajectory_messages',
            __('Berichten', 'stride'),
            [$this, 'renderMessagesMetabox'],
            TrajectoryCPT::POST_TYPE,
            'normal',
            'default',
        );

        // Sidebar
        add_meta_box(
            'stride_trajectory_actions',
            __('Status & Statistieken', 'stride'),
            [$this, 'renderSidebarMetabox'],
            TrajectoryCPT::POST_TYPE,
            'side',
            'high',
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
            '4.1.0',
        );
        wp_enqueue_script(
            'select2',
            'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js',
            ['jquery'],
            '4.1.0',
            true,
        );

        // Trajectory admin styles (from stride-core mu-plugin)
        $basePath = dirname(__DIR__, 3);
        $cssFile = $basePath . '/assets/css/admin/trajectory-admin.css';
        if (file_exists($cssFile)) {
            wp_enqueue_style(
                'stride-trajectory-admin',
                plugins_url('assets/css/admin/trajectory-admin.css', $basePath . '/stride-core.php'),
                ['select2'],
                filemtime($cssFile),
            );
        }

        // Trajectory admin scripts (from stride-core mu-plugin)
        $jsFile = $basePath . '/assets/js/admin/trajectory-admin.js';
        if (file_exists($jsFile)) {
            wp_enqueue_script(
                'stride-trajectory-admin',
                plugins_url('assets/js/admin/trajectory-admin.js', $basePath . '/stride-core.php'),
                ['jquery', 'select2'],
                filemtime($jsFile),
                true,
            );

            $currentUser = wp_get_current_user();
            wp_localize_script('stride-trajectory-admin', 'strideTrajectoryAdmin', [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce(self::NONCE_AJAX),
                'trajectoryId' => $post ? $post->ID : 0,
                'currentUser' => $currentUser->display_name ?: $currentUser->user_login,
                'i18n' => [
                    'searchCourse' => __('Zoek cursus...', 'stride'),
                    'searchCourseOrEdition' => __('Zoek cursus of editie...', 'stride'),
                    'selectEdition' => __('Selecteer editie...', 'stride'),
                    'noResults' => __('Geen resultaten gevonden', 'stride'),
                    'addGroup' => __('Nieuwe groep', 'stride'),
                    'groupName' => __('Groepnaam', 'stride'),
                    'pickCount' => __('Kies', 'stride'),
                    'remove' => __('Verwijderen', 'stride'),
                    'confirmDeleteGroup' => __('Weet je zeker dat je deze groep wilt verwijderen?', 'stride'),
                    'error' => __('Er ging iets mis.', 'stride'),
                    'editionBadge' => __('Editie', 'stride'),
                    'onlineBadge' => __('Online', 'stride'),
                    // Messages i18n
                    'enterMessage' => __('Vul een bericht in.', 'stride'),
                    'noMessages' => __('Nog geen berichten toegevoegd.', 'stride'),
                    'remove' => __('Verwijderen', 'stride'),
                    'announcement' => __('Mededeling', 'stride'),
                    'faq' => __('Vraag', 'stride'),
                    'update' => __('Update', 'stride'),
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
            .stride-tabs-nav { display: flex; gap: 0; border-bottom: 1px solid #c3c4c7; margin-bottom: 16px; }
            .stride-tab { padding: 10px 18px; background: #f0f0f1; border: 1px solid #c3c4c7; border-bottom: none; margin-bottom: -1px; margin-right: -1px; cursor: pointer; font-size: 13px; font-weight: 500; color: #646970; transition: all 0.15s ease; }
            .stride-tab:first-child { border-radius: 3px 0 0 0; }
            .stride-tab:last-child { border-radius: 0 3px 0 0; margin-right: 0; }
            .stride-tab:hover { background: #f6f7f7; color: #1d2327; }
            .stride-tab.active { background: #fff; border-bottom-color: #fff; color: #1d2327; }
            .stride-tab.hidden { display: none; }
            .stride-tab-content { display: none; }
            .stride-tab-content.active { display: block; }
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
        </style>

        <div class="stride-trajectory-details">
            <!-- Tab Navigation -->
            <div class="stride-tabs-nav">
                <div class="stride-tab active" data-tab="general"><?php esc_html_e('Algemeen', 'stride'); ?></div>
                <div class="stride-tab" data-tab="description"><?php esc_html_e('Beschrijving', 'stride'); ?></div>
                <div class="stride-tab" data-tab="deadlines"><?php esc_html_e('Deadlines', 'stride'); ?></div>
            </div>

            <!-- Tab: General -->
            <div class="stride-tab-content active" data-tab="general">
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

            <!-- Tab: Description -->
            <div class="stride-tab-content" data-tab="description">
                <?php
                wp_editor(
                    $trajectory['description'] ?? '',
                    'trajectory_description',
                    [
                        'textarea_name' => 'ntdst_fields[description]',
                        'textarea_rows' => 12,
                        'media_buttons' => true,
                        'teeny' => false,
                        'quicktags' => true,
                        'tinymce' => [
                            'toolbar1' => 'formatselect,bold,italic,underline,bullist,numlist,link,unlink,wp_more,fullscreen',
                            'toolbar2' => '',
                        ],
                    ],
                );
        ?>
            </div>

            <!-- Tab: Deadlines -->
            <div class="stride-tab-content" data-tab="deadlines">
                <!-- Cohort Deadlines -->
                <div class="stride-cohort-only">
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
                <div class="stride-self-paced-only">
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
            </div>
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
        <!-- Required Courses -->
        <div class="stride-courses-section">
            <h4><?php esc_html_e('Verplichte Cursussen', 'stride'); ?></h4>

            <ul class="stride-course-list" id="stride-required-courses">
                <?php if (empty($requiredCourses)): ?>
                    <li class="stride-no-courses"><?php esc_html_e('Nog geen verplichte cursussen toegevoegd.', 'stride'); ?></li>
                <?php else: ?>
                    <?php foreach ($requiredCourses as $course): ?>
                        <?php echo $this->renderCourseItem($course, 'ntdst_fields[courses_required][]'); ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </ul>

            <div class="stride-add-course">
                <select id="stride-add-required-course" class="stride-course-select stride-hybrid-select" style="width: 100%;">
                    <option value=""><?php esc_html_e('Zoek cursus of editie...', 'stride'); ?></option>
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
                    <?php $groupIndex = 0;
                    foreach ($electiveGroups as $groupName => $group): ?>
                        <?php $this->renderElectiveGroup($groupIndex, $group);
                        $groupIndex++; ?>
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
        $isNew = empty($group['name']) && empty($group['courses']);
        $courseCount = is_array($group['courses'] ?? null) ? count($group['courses']) : 0;
        ?>
        <div class="stride-elective-group<?php echo $isNew ? ' is-editing' : ''; ?>"
             data-group-index="<?php echo esc_attr($index); ?>">

            <!-- Summary header (always visible) -->
            <div class="stride-group-summary">
                <div class="stride-group-summary-main">
                    <span class="stride-group-summary-label"><?php echo esc_html($group['name'] ?: __('(Nieuwe groep)', 'stride')); ?></span>
                    <span class="stride-group-summary-meta">
                        <?php printf(
                            /* translators: %d: pick count */
                            esc_html__('Kies %d', 'stride'),
                            (int) ($group['pick_count'] ?? 1),
                        ); ?>
                        ·
                        <?php printf(
                            /* translators: %d: number of courses */
                            esc_html(_n('%d cursus', '%d cursussen', $courseCount, 'stride')),
                            $courseCount,
                        ); ?>
                    </span>
                </div>
                <div class="stride-group-summary-actions">
                    <button type="button" class="button-link stride-edit-group" title="<?php esc_attr_e('Bewerken', 'stride'); ?>">
                        <span class="dashicons dashicons-edit"></span>
                    </button>
                    <button type="button" class="button-link stride-delete-group" title="<?php esc_attr_e('Groep verwijderen', 'stride'); ?>">
                        <span class="dashicons dashicons-trash"></span>
                    </button>
                </div>
            </div>

            <!-- Course list (always visible) -->
            <ul class="stride-course-list stride-elective-course-list">
                <?php if (empty($group['courses'])): ?>
                    <li class="stride-no-courses"><?php esc_html_e('Nog geen cursussen in deze groep.', 'stride'); ?></li>
                <?php else: ?>
                    <?php foreach ($group['courses'] as $course): ?>
                        <?php echo $this->renderCourseItem($course, $namePrefix . '[courses][]'); ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </ul>

            <!-- Edit panel (hidden unless .is-editing) -->
            <div class="stride-group-edit">
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

                <div class="stride-add-course">
                    <select class="stride-course-select stride-hybrid-select stride-elective-course-select" style="width: 100%;">
                        <option value=""><?php esc_html_e('Zoek cursus of editie...', 'stride'); ?></option>
                    </select>
                    <button type="button" class="button stride-add-elective-btn"><?php esc_html_e('Toevoegen', 'stride'); ?></button>
                </div>

                <div class="group-footer">
                    <button type="button" class="button button-small stride-group-done"><?php esc_html_e('Klaar', 'stride'); ?></button>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render a single course/edition item with type indicator.
     *
     * @param array<string, mixed> $course Course data array
     * @param string $inputName Hidden input field name
     * @return string HTML for the course item
     */
    private function renderCourseItem(array $course, string $inputName): string
    {
        $type = $course['type'] ?? 'online';
        $courseId = (int) ($course['course_id'] ?? 0);
        $editionId = (int) ($course['edition_id'] ?? 0);

        // Build display label
        $courseTitle = $courseId ? get_the_title($courseId) : __('Onbekende cursus', 'stride');
        $label = $courseTitle ?: '#' . $courseId;

        // For editions, add date and venue info
        if ($type === 'edition' && $editionId) {
            $editionPost = $this->editionRepository->find($editionId);
            if (!is_wp_error($editionPost)) {
                $startDate = $this->editionRepository->getField($editionId, 'start_date', '');
                $venue = $this->editionRepository->getField($editionId, 'venue', '');
                if ($startDate) {
                    $label .= ' - ' . date_i18n('d M Y', strtotime($startDate));
                }
                if ($venue) {
                    $label .= ' - ' . $venue;
                }
            }
        }

        // Build JSON data for hidden input
        $jsonData = json_encode([
            'type' => $type,
            'course_id' => $courseId,
            'edition_id' => $type === 'edition' ? $editionId : null,
        ], JSON_UNESCAPED_UNICODE);

        // Type-specific styling
        $typeClass = $type === 'edition' ? 'stride-item-edition' : 'stride-item-online';
        $icon = $type === 'edition' ? 'calendar-alt' : 'laptop';
        $badgeText = $type === 'edition' ? __('Editie', 'stride') : __('Online', 'stride');
        $badgeClass = $type === 'edition' ? 'stride-badge-edition' : 'stride-badge-online';

        ob_start();
        ?>
        <li class="stride-course-item <?php echo esc_attr($typeClass); ?>" data-course-id="<?php echo esc_attr($courseId); ?>" data-edition-id="<?php echo esc_attr($editionId); ?>" data-type="<?php echo esc_attr($type); ?>">
            <span class="item-icon dashicons dashicons-<?php echo esc_attr($icon); ?>"></span>
            <span class="course-title"><?php echo esc_html($label); ?></span>
            <span class="item-badge <?php echo esc_attr($badgeClass); ?>"><?php echo esc_html($badgeText); ?></span>
            <span class="remove-course dashicons dashicons-no-alt" title="<?php esc_attr_e('Verwijderen', 'stride'); ?>"></span>
            <input type="hidden" name="<?php echo esc_attr($inputName); ?>" value="<?php echo esc_attr($jsonData); ?>">
        </li>
        <?php
        return ob_get_clean();
    }

    public function renderEnrollmentsMetabox(WP_Post $post): void
    {
        if ($post->post_status === 'auto-draft') {
            echo '<p class="description">' . esc_html__('Sla het traject eerst op om inschrijvingen te zien.', 'stride') . '</p>';
            return;
        }

        $enrollments = $this->registrations->findByTrajectory($post->ID);
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
                        $user = get_userdata($enrollment->user_id);
                        $userName = $user ? ($user->display_name ?: $user->user_email) : __('Onbekend', 'stride');
                        $status = $enrollment->status ?? 'confirmed';
                        $enrolledAt = $enrollment->registered_at ?? '';
                        $completedCourses = 0;
                        $progressPercent = $totalCourses > 0 ? round(($completedCourses / $totalCourses) * 100) : 0;

                        $statusLabels = [
                            'confirmed' => __('Actief', 'stride'),
                            'completed' => __('Voltooid', 'stride'),
                            'waitlist' => __('Wachtlijst', 'stride'),
                            'cancelled' => __('Geannuleerd', 'stride'),
                        ];
                        ?>
                        <tr data-status="<?php echo esc_attr($status); ?>" data-name="<?php echo esc_attr(strtolower($userName)); ?>">
                            <td>
                                <?php
                                $userEditUrl = $user ? get_edit_user_link($user->ID) : null;
                        if ($userEditUrl): ?>
                                    <a href="<?php echo esc_url($userEditUrl); ?>"><?php echo esc_html($userName); ?></a>
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

        $status = OfferingStatus::tryFrom($trajectory['status'] ?? '') ?? OfferingStatus::Draft;
        $mode = TrajectoryMode::tryFrom($trajectory['mode'] ?? '') ?? TrajectoryMode::Cohort;
        $courseCount = count($trajectory['courses'] ?? []);

        $enrollments = $this->registrations->findByTrajectory($post->ID);
        $activeCount = count(array_filter($enrollments, fn($e) => $e->status === 'confirmed'));
        $completedCount = count(array_filter($enrollments, fn($e) => $e->status === 'completed'));
        ?>
        <style>
            .stride-trajectory-sidebar .stride-sidebar-section { margin-bottom: 16px; padding-bottom: 16px; border-bottom: 1px solid #f0f0f1; }
            .stride-trajectory-sidebar .stride-sidebar-section:last-child { border-bottom: none; margin-bottom: 0; padding-bottom: 0; }
            .stride-trajectory-sidebar > div.stride-sidebar-section label.status-label { display: block; font-size: 11px; font-weight: 600; text-transform: uppercase; color: #646970; margin-bottom: 6px; }
            .stride-trajectory-sidebar select { width: 100%; }
            .stride-sidebar-meta { list-style: none; margin: 0; padding: 0; }
            .stride-sidebar-meta li { display: flex; justify-content: space-between; padding: 6px 0; font-size: 12px; }
            .stride-sidebar-meta .meta-label { color: #646970; }
            .stride-sidebar-meta .meta-value { font-weight: 500; color: #1d2327; }
        </style>

        <div class="stride-trajectory-sidebar">
            <div class="stride-sidebar-section">
                <label class="status-label" for="trajectory_status"><?php esc_html_e('Status', 'stride'); ?></label>
                <select id="trajectory_status" name="ntdst_fields[status]">
                    <?php foreach (OfferingStatus::cases() as $statusOption): ?>
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

            <?php \Stride\Modules\Edition\Admin\OfferingSidebarPartial::render($post, TrajectoryCPT::POST_TYPE); ?>
        </div>
        <?php
    }

    public function renderMessagesMetabox(WP_Post $post): void
    {
        // For new trajectories, show placeholder
        if ($post->post_status === 'auto-draft') {
            echo '<p class="description">' . esc_html__('Sla het traject eerst op om berichten toe te voegen.', 'stride') . '</p>';
            return;
        }

        $messages = $this->repository->getField($post->ID, 'trajectory_messages', []);
        if (is_string($messages)) {
            $messages = json_decode($messages, true) ?: [];
        }

        // Message types configuration
        $messageTypes = [
            'announcement' => [
                'label' => __('Mededeling', 'stride'),
                'icon' => 'megaphone',
                'color' => 'announcement',
            ],
            'faq' => [
                'label' => __('Vraag', 'stride'),
                'icon' => 'editor-help',
                'color' => 'faq',
            ],
            'update' => [
                'label' => __('Update', 'stride'),
                'icon' => 'update',
                'color' => 'update',
            ],
        ];
        ?>
        <style>
            .stride-messages-timeline { margin-bottom: 16px; }
            .stride-message-item { display: flex; gap: 12px; padding: 12px 0; border-bottom: 1px solid #f0f0f1; position: relative; }
            .stride-message-item:last-child { border-bottom: none; }
            .stride-message-icon { width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
            .stride-message-icon.announcement { background: #e6f4ea; color: #1e7e34; }
            .stride-message-icon.faq { background: #fff3cd; color: #856404; }
            .stride-message-icon.update { background: #e5f0f8; color: #2271b1; }
            .stride-message-icon .dashicons { font-size: 16px; width: 16px; height: 16px; }
            .stride-message-body { flex: 1; min-width: 0; }
            .stride-message-meta { display: flex; gap: 8px; align-items: center; font-size: 12px; color: #646970; margin-bottom: 4px; }
            .stride-message-meta .author { font-weight: 500; color: #1d2327; }
            .stride-message-meta .type-badge { padding: 1px 6px; border-radius: 3px; font-size: 11px; font-weight: 500; }
            .stride-message-meta .type-badge.announcement { background: #e6f4ea; color: #1e7e34; }
            .stride-message-meta .type-badge.faq { background: #fff3cd; color: #856404; }
            .stride-message-meta .type-badge.update { background: #e5f0f8; color: #2271b1; }
            .stride-message-content { font-size: 13px; color: #1d2327; line-height: 1.5; white-space: pre-wrap; }
            .stride-message-delete { color: #b32d2e; cursor: pointer; opacity: 0; transition: opacity 0.15s; position: absolute; right: 0; top: 12px; }
            .stride-message-item:hover .stride-message-delete { opacity: 1; }
            .stride-empty-messages { padding: 24px; text-align: center; color: #646970; background: #f9f9f9; border-radius: 4px; }
            .stride-add-message-form textarea { width: 100%; min-height: 80px; margin-bottom: 8px; resize: vertical; }
            .stride-add-message-form .form-row { display: flex; justify-content: space-between; align-items: center; gap: 12px; }
            .stride-add-message-form .type-selector { display: flex; gap: 12px; }
            .stride-add-message-form .type-selector label { display: flex; align-items: center; gap: 4px; cursor: pointer; font-size: 12px; }
            .stride-add-message-form .type-selector input[type="radio"] { margin: 0; }
            .stride-add-message-form .type-icon { display: inline-flex; align-items: center; justify-content: center; width: 20px; height: 20px; border-radius: 50%; }
            .stride-add-message-form .type-icon.announcement { background: #e6f4ea; color: #1e7e34; }
            .stride-add-message-form .type-icon.faq { background: #fff3cd; color: #856404; }
            .stride-add-message-form .type-icon.update { background: #e5f0f8; color: #2271b1; }
            .stride-add-message-form .type-icon .dashicons { font-size: 12px; width: 12px; height: 12px; }
        </style>

        <!-- Messages Timeline -->
        <div id="stride-messages-list" class="stride-messages-timeline">
            <?php if (empty($messages)): ?>
                <div class="stride-empty-messages">
                    <?php esc_html_e('Nog geen berichten toegevoegd.', 'stride'); ?>
                </div>
            <?php else: ?>
                <?php foreach ($messages as $index => $message): ?>
                    <?php if (!empty($message['_deleted'])) {
                        continue;
                    } ?>
                    <?php
                    $type = $message['type'] ?? 'announcement';
                    $typeConfig = $messageTypes[$type] ?? $messageTypes['announcement'];
                    ?>
                    <div class="stride-message-item" data-index="<?php echo esc_attr($index); ?>">
                        <div class="stride-message-icon <?php echo esc_attr($type); ?>">
                            <span class="dashicons dashicons-<?php echo esc_attr($typeConfig['icon']); ?>"></span>
                        </div>
                        <div class="stride-message-body">
                            <div class="stride-message-meta">
                                <span class="author"><?php echo esc_html($message['author'] ?? __('Onbekend', 'stride')); ?></span>
                                <span class="type-badge <?php echo esc_attr($type); ?>"><?php echo esc_html($typeConfig['label']); ?></span>
                                <span class="date"><?php echo esc_html($message['date'] ?? ''); ?></span>
                            </div>
                            <div class="stride-message-content"><?php echo esc_html($message['content'] ?? ''); ?></div>
                        </div>
                        <span class="stride-message-delete dashicons dashicons-no-alt" title="<?php esc_attr_e('Verwijderen', 'stride'); ?>"></span>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Add Message Form -->
        <div class="stride-add-message-form">
            <textarea id="stride-message-content" placeholder="<?php esc_attr_e('Schrijf een bericht...', 'stride'); ?>"></textarea>
            <div class="form-row">
                <div class="type-selector">
                    <?php foreach ($messageTypes as $typeKey => $typeConfig): ?>
                        <label>
                            <input type="radio" name="stride_message_type" value="<?php echo esc_attr($typeKey); ?>" <?php checked($typeKey, 'announcement'); ?>>
                            <span class="type-icon <?php echo esc_attr($typeKey); ?>"><span class="dashicons dashicons-<?php echo esc_attr($typeConfig['icon']); ?>"></span></span>
                            <?php echo esc_html($typeConfig['label']); ?>
                        </label>
                    <?php endforeach; ?>
                </div>
                <button type="button" class="button" id="stride-add-message">
                    <?php esc_html_e('Bericht toevoegen', 'stride'); ?>
                </button>
            </div>
        </div>

        <input type="hidden" id="stride_messages_data" name="ntdst_fields[trajectory_messages]" value="<?php echo esc_attr(json_encode($messages)); ?>">

        <script>
        (function($) {
            'use strict';

            var $messagesList = $('#stride-messages-list');
            var $messagesData = $('#stride_messages_data');
            var messages = [];

            // Parse existing messages
            try {
                messages = JSON.parse($messagesData.val() || '[]');
            } catch(e) {
                messages = [];
            }

            // Message types configuration (mirrored from PHP)
            var messageTypes = {
                announcement: { label: strideTrajectoryAdmin.i18n.announcement || 'Mededeling', icon: 'megaphone' },
                faq: { label: strideTrajectoryAdmin.i18n.faq || 'Vraag', icon: 'editor-help' },
                update: { label: strideTrajectoryAdmin.i18n.update || 'Update', icon: 'update' }
            };

            function renderMessages() {
                if (messages.length === 0 || messages.every(function(m) { return m._deleted; })) {
                    $messagesList.html('<div class="stride-empty-messages">' + (strideTrajectoryAdmin.i18n.noMessages || 'Nog geen berichten toegevoegd.') + '</div>');
                    return;
                }

                var html = '';
                messages.forEach(function(message, index) {
                    if (message._deleted) return;

                    var type = message.type || 'announcement';
                    var typeConfig = messageTypes[type] || messageTypes.announcement;

                    html += '<div class="stride-message-item" data-index="' + index + '">';
                    html += '<div class="stride-message-icon ' + type + '">';
                    html += '<span class="dashicons dashicons-' + typeConfig.icon + '"></span>';
                    html += '</div>';
                    html += '<div class="stride-message-body">';
                    html += '<div class="stride-message-meta">';
                    html += '<span class="author">' + escapeHtml(message.author || '') + '</span>';
                    html += '<span class="type-badge ' + type + '">' + escapeHtml(typeConfig.label) + '</span>';
                    html += '<span class="date">' + escapeHtml(message.date || '') + '</span>';
                    html += '</div>';
                    html += '<div class="stride-message-content">' + escapeHtml(message.content || '') + '</div>';
                    html += '</div>';
                    html += '<span class="stride-message-delete dashicons dashicons-no-alt" title="' + (strideTrajectoryAdmin.i18n.remove || 'Verwijderen') + '"></span>';
                    html += '</div>';
                });

                $messagesList.html(html);
            }

            function escapeHtml(text) {
                var div = document.createElement('div');
                div.textContent = text;
                return div.innerHTML;
            }

            function saveMessages() {
                $messagesData.val(JSON.stringify(messages));
            }

            // Add message
            $('#stride-add-message').on('click', function() {
                var $content = $('#stride-message-content');
                var content = $content.val().trim();

                if (!content) {
                    alert(strideTrajectoryAdmin.i18n.enterMessage || 'Vul een bericht in.');
                    $content.focus();
                    return;
                }

                var type = $('input[name="stride_message_type"]:checked').val() || 'announcement';

                // Get current user from localized data
                var currentUser = strideTrajectoryAdmin.currentUser || 'Admin';
                if (!currentUser && typeof strideEditionAdmin !== 'undefined') {
                    currentUser = strideEditionAdmin.currentUser || 'Admin';
                }

                var now = new Date();
                var dateStr = now.toLocaleDateString('nl-BE', { day: '2-digit', month: 'short', year: 'numeric' });

                var newMessage = {
                    type: type,
                    content: content,
                    author: currentUser,
                    date: dateStr
                };

                // Prepend new message to beginning
                messages.unshift(newMessage);
                saveMessages();
                renderMessages();

                // Clear form
                $content.val('');
            });

            // Delete message
            $messagesList.on('click', '.stride-message-delete', function() {
                var $item = $(this).closest('.stride-message-item');
                var index = parseInt($item.data('index'), 10);

                if (typeof messages[index] !== 'undefined') {
                    messages[index]._deleted = true;
                    saveMessages();
                    renderMessages();
                }
            });

        })(jQuery);
        </script>
        <?php
    }

    public function handleSave(int $postId, WP_Post $post): void
    {
        if (!isset($_POST[self::NONCE_FIELD])
            || !wp_verify_nonce($_POST[self::NONCE_FIELD], self::NONCE_SAVE)) {
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
            if (OfferingStatus::tryFrom($status)) {
                $updateData['status'] = $status;
            }
        }

        if (isset($fields['description'])) {
            $updateData['description'] = sanitize_textarea_field($fields['description']);
        }

        if (isset($fields['capacity'])) {
            $updateData['capacity'] = absint($fields['capacity']);
        }

        if (isset($fields['requires_approval'])) {
            $updateData['requires_approval'] = (bool) $fields['requires_approval'];
        }

        // Lifecycle requirements (mirrors EditionAdminController save handler)
        if (isset($fields['enrollment_form'])) {
            $updateData['enrollment_form'] = sanitize_text_field($fields['enrollment_form']);
        }
        $offeringBooleans = [
            'requires_questionnaire',
            'requires_documents',
            'post_requires_evaluation',
            'post_requires_documents',
            'post_requires_approval',
        ];
        foreach ($offeringBooleans as $boolField) {
            if (isset($fields[$boolField])) {
                $updateData[$boolField] = (bool) $fields[$boolField];
            }
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

        // Process required courses (supports both JSON and legacy integer format)
        if (!empty($fields['courses_required']) && is_array($fields['courses_required'])) {
            foreach ($fields['courses_required'] as $itemValue) {
                $courseEntry = $this->parseCourseItemValue($itemValue);
                if (!$courseEntry) {
                    continue;
                }

                $courseEntry['required'] = true;
                $courses[] = $courseEntry;
            }
        }

        // Process elective groups (supports both JSON and legacy integer format)
        if (!empty($fields['elective_groups']) && is_array($fields['elective_groups'])) {
            foreach ($fields['elective_groups'] as $group) {
                $groupName = sanitize_text_field($group['name'] ?? '');
                $pickCount = absint($group['pick_count'] ?? 1);

                if (empty($groupName) || empty($group['courses']) || !is_array($group['courses'])) {
                    continue;
                }

                foreach ($group['courses'] as $itemValue) {
                    $courseEntry = $this->parseCourseItemValue($itemValue);
                    if (!$courseEntry) {
                        continue;
                    }

                    $courseEntry['required'] = false;
                    $courseEntry['group'] = $groupName;
                    $courseEntry['pick_count'] = $pickCount;
                    $courses[] = $courseEntry;
                }
            }
        }

        $updateData['courses'] = $courses;

        // Save trajectory messages
        if (isset($fields['trajectory_messages'])) {
            $jsonString = wp_unslash($fields['trajectory_messages']);
            $messages = json_decode($jsonString, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                ntdst_log('trajectory')->warning('JSON decode error for messages', [
                    'post_id' => $postId,
                    'error'   => json_last_error_msg(),
                ]);
            } elseif (is_array($messages)) {
                // Filter out deleted messages and sanitize
                $sanitizedMessages = [];
                foreach ($messages as $message) {
                    if (empty($message['_deleted'])) {
                        $sanitizedMessages[] = [
                            'type' => sanitize_key($message['type'] ?? 'announcement'),
                            'content' => sanitize_textarea_field($message['content'] ?? ''),
                            'author' => sanitize_text_field($message['author'] ?? ''),
                            'date' => sanitize_text_field($message['date'] ?? ''),
                        ];
                    }
                }
                $updateData['trajectory_messages'] = $sanitizedMessages;
            }
        }

        if (!empty($updateData)) {
            $this->repository->update($postId, $updateData);
        }
    }

    /**
     * Parse a course item value from form submission.
     *
     * Supports both:
     * - New JSON format: {"type":"edition","course_id":123,"edition_id":456}
     * - Legacy integer format: 123 (treated as online course)
     *
     * @param string|int $value The form value
     * @return array|null Parsed course entry or null if invalid
     */
    private function parseCourseItemValue(string|int $value): ?array
    {
        // Handle legacy integer format (plain course ID)
        if (is_numeric($value)) {
            $courseId = absint($value);
            if ($courseId <= 0) {
                return null;
            }
            return [
                'type' => 'online',
                'course_id' => $courseId,
            ];
        }

        // WordPress adds slashes to POST data - remove them before JSON decode
        $value = wp_unslash($value);

        // Handle JSON format
        $item = json_decode($value, true);
        if (!is_array($item)) {
            // If JSON decode fails, try to extract a numeric ID
            $courseId = absint($value);
            if ($courseId > 0) {
                return [
                    'type' => 'online',
                    'course_id' => $courseId,
                ];
            }
            return null;
        }

        $type = sanitize_text_field($item['type'] ?? 'online');
        $courseId = absint($item['course_id'] ?? 0);

        if ($courseId <= 0) {
            return null;
        }

        $courseEntry = [
            'type' => $type,
            'course_id' => $courseId,
        ];

        if ($type === 'edition') {
            $courseEntry['edition_id'] = absint($item['edition_id'] ?? 0);
        }

        return $courseEntry;
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

    /**
     * AJAX: Search for both editions and online courses.
     *
     * Returns grouped results in Select2 format:
     * - Group "Edities": Upcoming editions with course name, date, venue
     * - Group "Online Cursussen": LearnDash courses (content without scheduled editions)
     *
     * IDs are formatted as "edition:123" or "online:456" for client-side parsing.
     */
    public function ajaxSearchCoursesAndEditions(): void
    {
        if (!$this->verifyAjaxNonce()) {
            return;
        }

        $search = sanitize_text_field($_POST['search'] ?? '');
        $results = [];

        // Group 1: Editions
        $editions = $this->editionRepository->findUpcoming(50);

        $editionResults = [];
        foreach ($editions as $edition) {
            $editionId = (int) $edition['id'];
            $meta = $edition['meta'] ?? [];
            $courseId = (int) ($meta['course_id'] ?? 0);
            $courseTitle = $courseId ? get_the_title($courseId) : '';

            // Build display text: "Course Name - 15 jan 2025 - Amsterdam"
            $label = $courseTitle ?: __('Onbekende cursus', 'stride');

            $startDate = $meta['start_date'] ?? '';
            if (!empty($startDate)) {
                $label .= ' - ' . date_i18n('d M Y', strtotime($startDate));
            }

            $venue = $meta['venue'] ?? '';
            if (!empty($venue)) {
                $label .= ' - ' . $venue;
            }

            // Filter by search term
            if (!empty($search) && stripos($label, $search) === false) {
                continue;
            }

            $editionResults[] = [
                'id' => 'edition:' . $editionId . ':' . $courseId,
                'text' => $label,
            ];
        }

        if (!empty($editionResults)) {
            $results[] = [
                'text' => __('Edities', 'stride'),
                'children' => $editionResults,
            ];
        }

        // Group 2: Online courses (LearnDash courses)
        $args = [
            'post_type' => 'sfwd-courses',
            'post_status' => 'publish',
            'posts_per_page' => 30,
            'orderby' => 'title',
            'order' => 'ASC',
        ];

        if (!empty($search)) {
            $args['s'] = $search;
        }

        $courses = get_posts($args);
        $onlineResults = [];

        foreach ($courses as $course) {
            $onlineResults[] = [
                'id' => 'online:' . $course->ID,
                'text' => $course->post_title,
            ];
        }

        if (!empty($onlineResults)) {
            $results[] = [
                'text' => __('Online Cursussen', 'stride'),
                'children' => $onlineResults,
            ];
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

        $enrollments = $this->registrations->findByTrajectory($trajectoryId);

        if (!empty($status)) {
            $enrollments = array_filter($enrollments, fn($e) => $e->status === $status);
        }

        if (!empty($search)) {
            $enrollments = array_filter($enrollments, function ($e) use ($search) {
                $user = get_userdata($e->user_id);
                if (!$user) {
                    return false;
                }
                $name = strtolower($user->display_name . ' ' . $user->user_email);
                return str_contains($name, strtolower($search));
            });
        }

        $total = count($enrollments);
        $enrollments = array_slice($enrollments, ($page - 1) * $perPage, $perPage);

        $results = [];
        foreach ($enrollments as $enrollment) {
            $user = get_userdata($enrollment->user_id);
            $results[] = [
                'id' => $enrollment->id,
                'user_id' => $enrollment->user_id,
                'user_name' => $user ? ($user->display_name ?: $user->user_email) : 'Unknown',
                'status' => $enrollment->status,
                'enrolled_at' => $enrollment->registered_at,
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

    // =========================================================================
    // Admin List Columns
    // =========================================================================

    /**
     * Define admin list columns.
     *
     * @param array<string, string> $columns
     * @return array<string, string>
     */
    public function defineListColumns(array $columns): array
    {
        $newColumns = [];
        $newColumns['cb'] = $columns['cb'] ?? '<input type="checkbox" />';
        $newColumns['title'] = __('Traject', 'stride');
        $newColumns['mode'] = __('Modus', 'stride');
        $newColumns['courses_count'] = __('Cursussen', 'stride');
        $newColumns['enrollments'] = __('Inschrijvingen', 'stride');
        $newColumns['status'] = __('Status', 'stride');
        $newColumns['deadline'] = __('Deadline', 'stride');

        return $newColumns;
    }

    /**
     * Render admin list column content.
     */
    public function renderListColumn(string $column, int $postId): void
    {
        switch ($column) {
            case 'mode':
                $mode = $this->repository->getField($postId, 'mode', 'self_paced');
                $modeEnum = TrajectoryMode::tryFrom($mode) ?? TrajectoryMode::SelfPaced;
                $icon = $modeEnum === TrajectoryMode::Cohort ? 'groups' : 'admin-users';
                $color = $modeEnum === TrajectoryMode::Cohort ? '#2271b1' : '#00a32a';
                echo '<span style="color:' . $color . ';">';
                echo '<span class="dashicons dashicons-' . $icon . '" style="font-size:16px;vertical-align:text-bottom;"></span> ';
                echo esc_html($modeEnum->label());
                echo '</span>';
                break;

            case 'courses_count':
                $courses = $this->repository->getField($postId, 'courses', []);
                if (is_string($courses)) {
                    $courses = json_decode($courses, true) ?: [];
                }
                $courses = is_array($courses) ? $courses : [];
                $required = count(array_filter($courses, fn($c) => ($c['required'] ?? false)));
                $elective = count($courses) - $required;

                echo '<span title="' . esc_attr__('Verplicht', 'stride') . '">';
                echo '<strong>' . $required . '</strong> ' . __('verplicht', 'stride');
                echo '</span>';
                if ($elective > 0) {
                    echo '<br><span style="color:#666;" title="' . esc_attr__('Keuzevakken', 'stride') . '">';
                    echo $elective . ' ' . __('keuze', 'stride');
                    echo '</span>';
                }
                break;

            case 'enrollments':
                $capacity = (int) $this->repository->getField($postId, 'capacity', 0);
                $enrolledCount = $this->registrations->countByTrajectory($postId);

                if ($capacity > 0) {
                    $percentage = min(100, round(($enrolledCount / $capacity) * 100));
                    $color = $percentage >= 100 ? '#d63638' : ($percentage >= 80 ? '#dba617' : '#00a32a');
                    echo '<span style="color:' . $color . ';font-weight:500;">' . $enrolledCount . '/' . $capacity . '</span>';
                } else {
                    echo '<span>' . $enrolledCount . '</span>';
                }
                break;

            case 'status':
                $status = $this->repository->getField($postId, 'status', 'draft');
                $statusEnum = OfferingStatus::tryFrom($status) ?? OfferingStatus::Draft;
                $config = $this->getOfferingStatusConfig($statusEnum);
                echo '<span style="display:inline-block;padding:2px 8px;border-radius:3px;background:' . $config['bg'] . ';color:' . $config['color'] . ';font-size:12px;">';
                echo esc_html($statusEnum->label());
                echo '</span>';
                break;

            case 'deadline':
                $mode = $this->repository->getField($postId, 'mode', 'self_paced');
                if ($mode === 'cohort') {
                    $enrollmentDeadline = $this->repository->getField($postId, 'enrollment_deadline', '');
                    if ($enrollmentDeadline) {
                        $isExpired = strtotime($enrollmentDeadline) < time();
                        $style = $isExpired ? 'color:#d63638;' : '';
                        echo '<span style="' . $style . '">' . esc_html(date_i18n('j M Y', strtotime($enrollmentDeadline))) . '</span>';
                    } else {
                        echo '<span style="color:#999;">—</span>';
                    }
                } else {
                    $months = (int) $this->repository->getField($postId, 'deadline_months', 0);
                    if ($months > 0) {
                        echo $months . ' ' . __('maanden', 'stride');
                    } else {
                        echo '<span style="color:#999;">' . __('Geen', 'stride') . '</span>';
                    }
                }
                break;
        }
    }

    /**
     * Get trajectory status display configuration.
     *
     * @return array{color: string, bg: string}
     */
    private function getOfferingStatusConfig(OfferingStatus $status): array
    {
        $badge = $status->badgeConfig();

        return ['color' => $badge['color'], 'bg' => $badge['bg']];
    }

    /**
     * Define sortable columns.
     *
     * @param array<string, string> $columns
     * @return array<string, string>
     */
    public function defineSortableColumns(array $columns): array
    {
        $columns['mode'] = 'mode';
        $columns['status'] = 'status';
        return $columns;
    }

    /**
     * Handle sorting by custom meta columns.
     */
    public function handleColumnSorting(\WP_Query $query): void
    {
        if (!is_admin() || !$query->is_main_query()) {
            return;
        }

        if ($query->get('post_type') !== TrajectoryCPT::POST_TYPE) {
            return;
        }

        $orderby = $query->get('orderby');

        if ($orderby === 'mode') {
            $query->set('meta_key', 'mode');
            $query->set('orderby', 'meta_value');
        } elseif ($orderby === 'status') {
            $query->set('meta_key', 'status');
            $query->set('orderby', 'meta_value');
        }
    }
}
