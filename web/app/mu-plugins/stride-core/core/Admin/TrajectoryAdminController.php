<?php

namespace ntdst\Stride\core\Admin;

defined('ABSPATH') || exit;

use ntdst\Stride\core\TrajectoryService;
use ntdst\Stride\core\TrajectoryEnrollmentRepository;
use ntdst\Stride\core\EditionService;
use ntdst\Stride\FieldRegistry;

/**
 * Trajectory Admin Controller
 *
 * Handles admin interface for trajectories with tabbed mode-based UI:
 * - Tab 1: General settings (mode, status, deadline)
 * - Tab 2: Courses (course requirements builder - deferred to Phase 2)
 * - Tab 3: Cohort Settings (only visible when mode = cohort)
 *
 * This class is instantiated by TrajectoryService in admin context.
 * Not a service - just a plain admin handler class.
 *
 * @package ntdst\Stride\core\Admin
 */
class TrajectoryAdminController
{
    private ?TrajectoryService $trajectoryService = null;
    private ?TrajectoryEnrollmentRepository $enrollmentRepo = null;
    private ?EditionService $editionService = null;

    /**
     * Constructor - uses lazy loading to avoid circular dependencies
     */
    public function __construct()
    {
        // Register hooks
        add_action('add_meta_boxes', [$this, 'registerMetaboxes']);
        add_action('save_post_' . TrajectoryService::POST_TYPE, [$this, 'saveTrajectoryMeta'], 10, 2);

        // Enqueue admin assets
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
    }

    /**
     * Get TrajectoryService (lazy loaded)
     */
    private function getTrajectoryService(): TrajectoryService
    {
        if ($this->trajectoryService === null) {
            $this->trajectoryService = $this->resolveService(TrajectoryService::class);
        }
        return $this->trajectoryService;
    }

    /**
     * Get TrajectoryEnrollmentRepository (lazy loaded)
     */
    private function getEnrollmentRepo(): TrajectoryEnrollmentRepository
    {
        if ($this->enrollmentRepo === null) {
            $this->enrollmentRepo = $this->resolveService(TrajectoryEnrollmentRepository::class);
        }
        return $this->enrollmentRepo;
    }

    /**
     * Get EditionService (lazy loaded)
     */
    private function getEditionService(): EditionService
    {
        if ($this->editionService === null) {
            $this->editionService = $this->resolveService(EditionService::class);
        }
        return $this->editionService;
    }

    /**
     * Resolve service from DI container
     */
    private function resolveService(string $class): object
    {
        if (function_exists('ntdst_get')) {
            try {
                $service = ntdst_get($class);
                if ($service instanceof $class) {
                    return $service;
                }
            } catch (\Exception $e) {
                // Fall through to create new instance
            }
        }
        return new $class();
    }

    /**
     * Enqueue admin assets
     */
    public function enqueueAssets(string $hook): void
    {
        global $post_type;

        if ($post_type !== TrajectoryService::POST_TYPE) {
            return;
        }

        if (!in_array($hook, ['post.php', 'post-new.php'])) {
            return;
        }

        // Enqueue styles
        wp_enqueue_style(
            'stride-trajectory-admin',
            get_template_directory_uri() . '/assets/css/admin/trajectory-admin.css',
            [],
            filemtime(get_template_directory() . '/assets/css/admin/trajectory-admin.css')
        );

        // Enqueue scripts
        wp_enqueue_script(
            'stride-trajectory-admin',
            get_template_directory_uri() . '/assets/js/admin/trajectory-admin.js',
            ['jquery'],
            filemtime(get_template_directory() . '/assets/js/admin/trajectory-admin.js'),
            true
        );

        // Localize script
        wp_localize_script('stride-trajectory-admin', 'strideTrajectoryAdmin', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('stride_trajectory_admin'),
            'trajectoryId' => get_the_ID(),
            'i18n' => [
                'selfPaced' => __('Zelfgestuurd', 'stride'),
                'cohort' => __('Cohort', 'stride'),
                'selectEdition' => __('Selecteer editie...', 'stride'),
                'noEditions' => __('Geen edities beschikbaar', 'stride'),
                'error' => __('Er is een fout opgetreden.', 'stride'),
            ],
        ]);
    }

    /**
     * Register custom metaboxes for trajectory admin
     */
    public function registerMetaboxes(): void
    {
        // Remove DataManager auto-metabox
        remove_meta_box('ntdst_' . TrajectoryService::POST_TYPE . '_fields', TrajectoryService::POST_TYPE, 'normal');

        add_meta_box(
            'stride_trajectory_details',
            __('Traject Instellingen', 'stride'),
            [$this, 'renderDetailsMetabox'],
            TrajectoryService::POST_TYPE,
            'normal',
            'high'
        );

        add_meta_box(
            'stride_trajectory_actions',
            __('Status & Statistieken', 'stride'),
            [$this, 'renderActionsMetabox'],
            TrajectoryService::POST_TYPE,
            'side',
            'high'
        );
    }

    /**
     * Render the main trajectory details metabox
     */
    public function renderDetailsMetabox(\WP_Post $post): void
    {
        $trajectory = $this->getTrajectoryService()->getTrajectory($post->ID);
        $isNew = !$trajectory;

        // Default values for new trajectories
        if ($isNew) {
            $trajectory = [
                'mode' => FieldRegistry::TRAJECTORY_MODE_SELF_PACED,
                'status' => FieldRegistry::TRAJECTORY_STATUS_OPEN,
                'description' => '',
                'deadline_months' => null,
                'courses' => [],
                'enrollment_deadline' => null,
                'choice_available_date' => null,
                'choice_deadline' => null,
                'linked_editions' => [],
            ];
        }

        $currentMode = $trajectory['mode'] ?? FieldRegistry::TRAJECTORY_MODE_SELF_PACED;
        $isCohort = $currentMode === FieldRegistry::TRAJECTORY_MODE_COHORT;

        wp_nonce_field('stride_save_trajectory', 'stride_trajectory_nonce');
        ?>
        <div class="stride-trajectory-admin">
            <div class="stride-trajectory-tabs">
                <nav class="stride-tabs-nav">
                    <button type="button" class="stride-tab active" data-tab="algemeen"><?php esc_html_e('Algemeen', 'stride'); ?></button>
                    <button type="button" class="stride-tab" data-tab="cursussen"><?php esc_html_e('Cursussen', 'stride'); ?></button>
                    <button type="button" class="stride-tab stride-cohort-only-tab <?php echo $isCohort ? '' : 'hidden'; ?>" data-tab="cohort"><?php esc_html_e('Cohort Instellingen', 'stride'); ?></button>
                </nav>

                <!-- Tab: Algemeen -->
                <div class="stride-tab-content active" data-tab="algemeen">
                    <div class="stride-field-row">
                        <div class="stride-field">
                            <label for="trajectory_mode"><?php esc_html_e('Modus', 'stride'); ?></label>
                            <select id="trajectory_mode" name="ntdst_fields[mode]" class="stride-mode-select">
                                <option value="<?php echo esc_attr(FieldRegistry::TRAJECTORY_MODE_SELF_PACED); ?>" <?php selected($currentMode, FieldRegistry::TRAJECTORY_MODE_SELF_PACED); ?>>
                                    <?php esc_html_e('Zelfgestuurd', 'stride'); ?>
                                </option>
                                <option value="<?php echo esc_attr(FieldRegistry::TRAJECTORY_MODE_COHORT); ?>" <?php selected($currentMode, FieldRegistry::TRAJECTORY_MODE_COHORT); ?>>
                                    <?php esc_html_e('Cohort', 'stride'); ?>
                                </option>
                            </select>
                            <p class="description">
                                <span class="mode-description mode-self-paced <?php echo $isCohort ? 'hidden' : ''; ?>">
                                    <?php esc_html_e('Zelfgestuurd: Deelnemer kiest zelf edities en volgt eigen tempo.', 'stride'); ?>
                                </span>
                                <span class="mode-description mode-cohort <?php echo $isCohort ? '' : 'hidden'; ?>">
                                    <?php esc_html_e('Cohort: Vaste groep volgt vooraf gekoppelde edities samen.', 'stride'); ?>
                                </span>
                            </p>
                        </div>
                    </div>

                    <div class="stride-field-row">
                        <div class="stride-field">
                            <label for="trajectory_description"><?php esc_html_e('Beschrijving', 'stride'); ?></label>
                            <textarea id="trajectory_description" name="ntdst_fields[description]" rows="4"
                                      placeholder="<?php esc_attr_e('Omschrijving van het traject...', 'stride'); ?>"><?php echo esc_textarea($trajectory['description'] ?? ''); ?></textarea>
                        </div>
                    </div>

                    <div class="stride-field-row stride-self-paced-only <?php echo $isCohort ? 'hidden' : ''; ?>">
                        <div class="stride-field">
                            <label for="trajectory_deadline_months"><?php esc_html_e('Deadline (maanden)', 'stride'); ?></label>
                            <input type="number" id="trajectory_deadline_months" name="ntdst_fields[deadline_months]"
                                   value="<?php echo esc_attr($trajectory['deadline_months'] ?? ''); ?>"
                                   min="0" step="1" placeholder="<?php esc_attr_e('bijv. 18', 'stride'); ?>">
                            <p class="description"><?php esc_html_e('Aantal maanden vanaf inschrijving om traject te voltooien.', 'stride'); ?></p>
                        </div>
                    </div>
                </div>

                <!-- Tab: Cursussen -->
                <div class="stride-tab-content" data-tab="cursussen">
                    <div class="stride-courses-section">
                        <p class="description">
                            <?php esc_html_e('Cursussen en keuzevakken kunnen worden ingesteld via het bestaande veld.', 'stride'); ?>
                        </p>

                        <?php
                        // Show current courses if any
                        $courses = $trajectory['courses'] ?? [];
                        if (!empty($courses)):
                            // Prime post cache for all courses at once (avoids N+1 on get_the_title)
                            $allCourseIds = array_unique(array_filter(array_map('absint', array_column($courses, 'course_id'))));
                            if (!empty($allCourseIds)) {
                                _prime_post_caches($allCourseIds, false, false);
                            }

                            $grouped = [];
                            foreach ($courses as $req) {
                                $group = $req['group'] ?? __('Overige', 'stride');
                                if (!isset($grouped[$group])) {
                                    $grouped[$group] = [];
                                }
                                $grouped[$group][] = $req;
                            }
                        ?>
                            <div class="stride-courses-preview">
                                <h4><?php esc_html_e('Huidige cursussen', 'stride'); ?></h4>
                                <?php foreach ($grouped as $groupName => $reqs): ?>
                                    <div class="stride-course-group">
                                        <h5><?php echo esc_html($groupName); ?></h5>
                                        <ul>
                                            <?php foreach ($reqs as $req): ?>
                                                <?php $courseTitle = get_the_title($req['course_id']); ?>
                                                <li>
                                                    <?php echo esc_html($courseTitle ?: '#' . $req['course_id']); ?>
                                                    <?php if (!empty($req['pick_count'])): ?>
                                                        <span class="pick-count">(<?php echo esc_html(sprintf(__('Kies %d', 'stride'), $req['pick_count'])); ?>)</span>
                                                    <?php endif; ?>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <p class="no-courses"><?php esc_html_e('Nog geen cursussen toegevoegd aan dit traject.', 'stride'); ?></p>
                        <?php endif; ?>

                        <p class="description" style="margin-top: 16px;">
                            <em><?php esc_html_e('Geavanceerde cursusbuilder wordt in een volgende versie toegevoegd.', 'stride'); ?></em>
                        </p>
                    </div>
                </div>

                <!-- Tab: Cohort (only visible when mode = cohort) -->
                <div class="stride-tab-content stride-cohort-only <?php echo $isCohort ? '' : 'hidden'; ?>" data-tab="cohort">
                    <h4><?php esc_html_e('Inschrijvingsperiode', 'stride'); ?></h4>
                    <div class="stride-field-row">
                        <div class="stride-field">
                            <label for="trajectory_enrollment_deadline"><?php esc_html_e('Inschrijvingsdeadline', 'stride'); ?></label>
                            <input type="date" id="trajectory_enrollment_deadline" name="ntdst_fields[enrollment_deadline]"
                                   value="<?php echo esc_attr($trajectory['enrollment_deadline'] ?? ''); ?>">
                            <p class="description"><?php esc_html_e('Inschrijvingen sluiten na deze datum.', 'stride'); ?></p>
                        </div>
                    </div>

                    <h4><?php esc_html_e('Keuzeperiode', 'stride'); ?></h4>
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
                    <p class="description"><?php esc_html_e('Deelnemers kunnen keuzevakken selecteren tussen deze datums.', 'stride'); ?></p>

                    <h4 style="margin-top: 24px;"><?php esc_html_e('Gekoppelde Edities', 'stride'); ?></h4>
                    <p class="description"><?php esc_html_e('Koppel elke cursus aan een specifieke editie voor dit cohort.', 'stride'); ?></p>

                    <?php
                    // Show linked editions interface
                    $linkedEditions = $trajectory['linked_editions'] ?? [];
                    $linkedMap = [];
                    foreach ($linkedEditions as $link) {
                        $linkedMap[(int)$link['course_id']] = (int)$link['edition_id'];
                    }

                    if (!empty($courses)):
                        // Batch fetch all editions for all courses at once (avoids N+1 queries)
                        $courseIds = array_unique(array_map('absint', array_column($courses, 'course_id')));
                        $courseIds = array_filter($courseIds);
                        $editionsByCourse = !empty($courseIds)
                            ? $this->getEditionService()->getEditionsForCourses($courseIds)
                            : [];
                    ?>
                        <div class="stride-linked-editions">
                            <table class="widefat striped">
                                <thead>
                                    <tr>
                                        <th><?php esc_html_e('Cursus', 'stride'); ?></th>
                                        <th><?php esc_html_e('Editie', 'stride'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    foreach ($courseIds as $courseId):
                                        $courseTitle = get_the_title($courseId);
                                        $linkedEditionId = $linkedMap[(int)$courseId] ?? 0;

                                        // Get editions from batch-loaded data
                                        $editions = $editionsByCourse[$courseId] ?? [];
                                    ?>
                                        <tr>
                                            <td>
                                                <?php echo esc_html($courseTitle ?: '#' . $courseId); ?>
                                                <input type="hidden" name="ntdst_fields[linked_editions][<?php echo esc_attr($courseId); ?>][course_id]"
                                                       value="<?php echo esc_attr($courseId); ?>">
                                            </td>
                                            <td>
                                                <select name="ntdst_fields[linked_editions][<?php echo esc_attr($courseId); ?>][edition_id]"
                                                        class="stride-edition-select">
                                                    <option value=""><?php esc_html_e('Selecteer editie...', 'stride'); ?></option>
                                                    <?php foreach ($editions as $edition): ?>
                                                        <option value="<?php echo esc_attr($edition['id']); ?>" <?php selected($linkedEditionId, $edition['id']); ?>>
                                                            <?php
                                                            $editionLabel = $edition['start_date'] ?? '';
                                                            if (!empty($edition['venue'])) {
                                                                $editionLabel .= ' - ' . $edition['venue'];
                                                            }
                                                            echo esc_html($editionLabel ?: '#' . $edition['id']);
                                                            ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <p class="no-courses"><?php esc_html_e('Voeg eerst cursussen toe om edities te koppelen.', 'stride'); ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render sidebar actions metabox
     */
    public function renderActionsMetabox(\WP_Post $post): void
    {
        $trajectory = $this->getTrajectoryService()->getTrajectory($post->ID);
        $isNew = !$trajectory;

        if ($isNew) {
            echo '<p class="description">' . esc_html__('Sla eerst op om statistieken te zien.', 'stride') . '</p>';
            return;
        }

        $status = $trajectory['status'] ?? FieldRegistry::TRAJECTORY_STATUS_OPEN;
        $mode = $trajectory['mode'] ?? FieldRegistry::TRAJECTORY_MODE_SELF_PACED;

        // Batch fetch all status counts in a single query (avoids N+1)
        $statusCounts = $this->getEnrollmentRepo()->getStatusCounts($post->ID);
        $enrolledCount = $statusCounts[TrajectoryEnrollmentRepository::STATUS_ACTIVE] ?? 0;
        $completedCount = $statusCounts[TrajectoryEnrollmentRepository::STATUS_COMPLETED] ?? 0;
        $courseCount = count($trajectory['courses'] ?? []);

        $statusLabels = [
            FieldRegistry::TRAJECTORY_STATUS_OPEN => __('Open', 'stride'),
            FieldRegistry::TRAJECTORY_STATUS_CLOSED => __('Gesloten', 'stride'),
            FieldRegistry::TRAJECTORY_STATUS_ARCHIVED => __('Gearchiveerd', 'stride'),
        ];

        $modeLabels = [
            FieldRegistry::TRAJECTORY_MODE_SELF_PACED => __('Zelfgestuurd', 'stride'),
            FieldRegistry::TRAJECTORY_MODE_COHORT => __('Cohort', 'stride'),
        ];
        ?>
        <div class="stride-trajectory-sidebar">
            <!-- Status Section -->
            <div class="stride-sidebar-section">
                <label for="trajectory_status"><?php esc_html_e('Status', 'stride'); ?></label>
                <select id="trajectory_status" name="ntdst_fields[status]" class="stride-status-select">
                    <?php foreach ($statusLabels as $value => $label): ?>
                        <option value="<?php echo esc_attr($value); ?>" <?php selected($status, $value); ?>>
                            <?php echo esc_html($label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Quick Stats -->
            <div class="stride-sidebar-section">
                <ul class="stride-sidebar-meta">
                    <li>
                        <span class="meta-label"><?php esc_html_e('Modus', 'stride'); ?></span>
                        <span class="meta-value"><?php echo esc_html($modeLabels[$mode] ?? $mode); ?></span>
                    </li>
                    <li>
                        <span class="meta-label"><?php esc_html_e('Cursussen', 'stride'); ?></span>
                        <span class="meta-value"><?php echo esc_html($courseCount); ?></span>
                    </li>
                    <li>
                        <span class="meta-label"><?php esc_html_e('Actieve deelnemers', 'stride'); ?></span>
                        <span class="meta-value"><?php echo esc_html($enrolledCount); ?></span>
                    </li>
                    <li>
                        <span class="meta-label"><?php esc_html_e('Voltooid', 'stride'); ?></span>
                        <span class="meta-value"><?php echo esc_html($completedCount); ?></span>
                    </li>
                </ul>
            </div>

            <?php if ($mode === FieldRegistry::TRAJECTORY_MODE_COHORT): ?>
                <!-- Cohort Dates Quick View -->
                <div class="stride-sidebar-section">
                    <h4><?php esc_html_e('Cohort Datums', 'stride'); ?></h4>
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
            <?php elseif (!empty($trajectory['deadline_months'])): ?>
                <!-- Self-paced Deadline -->
                <div class="stride-sidebar-section">
                    <ul class="stride-sidebar-meta">
                        <li>
                            <span class="meta-label"><?php esc_html_e('Deadline', 'stride'); ?></span>
                            <span class="meta-value"><?php echo esc_html(sprintf(__('%d maanden', 'stride'), $trajectory['deadline_months'])); ?></span>
                        </li>
                    </ul>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Save trajectory meta on post save
     */
    public function saveTrajectoryMeta(int $postId, \WP_Post $post): void
    {
        if (!isset($_POST['stride_trajectory_nonce']) ||
            !wp_verify_nonce($_POST['stride_trajectory_nonce'], 'stride_save_trajectory')) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (!current_user_can('edit_post', $postId)) {
            return;
        }

        $model = $this->getModel();
        if (!$model) {
            return;
        }

        $fields = $_POST['ntdst_fields'] ?? [];
        if (empty($fields)) {
            return;
        }

        $updateData = [];

        // Mode
        if (isset($fields['mode'])) {
            $mode = sanitize_text_field($fields['mode']);
            if (in_array($mode, [FieldRegistry::TRAJECTORY_MODE_SELF_PACED, FieldRegistry::TRAJECTORY_MODE_COHORT], true)) {
                $updateData[FieldRegistry::TRAJECTORY_MODE] = $mode;
            }
        }

        // Status
        if (isset($fields['status'])) {
            $status = sanitize_text_field($fields['status']);
            if (in_array($status, [FieldRegistry::TRAJECTORY_STATUS_OPEN, FieldRegistry::TRAJECTORY_STATUS_CLOSED, FieldRegistry::TRAJECTORY_STATUS_ARCHIVED], true)) {
                $updateData[FieldRegistry::TRAJECTORY_STATUS] = $status;
            }
        }

        // Description
        if (isset($fields['description'])) {
            $updateData[FieldRegistry::TRAJECTORY_DESCRIPTION] = sanitize_textarea_field($fields['description']);
        }

        // Deadline months (self-paced only)
        if (isset($fields['deadline_months'])) {
            $updateData[FieldRegistry::TRAJECTORY_DEADLINE_MONTHS] = absint($fields['deadline_months']) ?: null;
        }

        // Cohort-specific fields
        if (isset($fields['enrollment_deadline'])) {
            $updateData[FieldRegistry::TRAJECTORY_ENROLLMENT_DEADLINE] = sanitize_text_field($fields['enrollment_deadline']);
        }

        if (isset($fields['choice_available_date'])) {
            $updateData[FieldRegistry::TRAJECTORY_CHOICE_AVAILABLE] = sanitize_text_field($fields['choice_available_date']);
        }

        if (isset($fields['choice_deadline'])) {
            $updateData[FieldRegistry::TRAJECTORY_CHOICE_DEADLINE] = sanitize_text_field($fields['choice_deadline']);
        }

        // Linked editions
        if (isset($fields['linked_editions']) && is_array($fields['linked_editions'])) {
            $linkedEditions = [];
            foreach ($fields['linked_editions'] as $link) {
                if (!empty($link['course_id']) && !empty($link['edition_id'])) {
                    $linkedEditions[] = [
                        'course_id' => absint($link['course_id']),
                        'edition_id' => absint($link['edition_id']),
                    ];
                }
            }
            $updateData[FieldRegistry::TRAJECTORY_LINKED_EDITIONS] = $linkedEditions;
        }

        if (!empty($updateData)) {
            $model->update($postId, $updateData);

            // Invalidate trajectory cache
            TrajectoryService::invalidateCache($postId);
        }
    }

    /**
     * Get the Data Model for trajectories
     */
    private function getModel(): ?\NTDST_Data_Model
    {
        if (!function_exists('ntdst_data')) {
            return null;
        }
        return ntdst_data()->get(TrajectoryService::POST_TYPE);
    }
}
