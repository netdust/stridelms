<?php

namespace ntdst\Stride\core;

defined('ABSPATH') || exit;

use ntdst\Stride\core\Admin\TrajectoryAdminController;
use ntdst\Stride\FieldRegistry;
use WP_Error;

/**
 * Trajectory Service
 *
 * Manages learning paths (trajectories) - structured multi-course programs.
 *
 * A trajectory defines:
 * - Required courses (basis modules)
 * - Elective courses (keuze modules) with pick count
 * - Deadline for completion (in months)
 *
 * Course requirements format:
 * [
 *   ['course_id' => 123, 'group' => 'Basismodules', 'required' => true],
 *   ['course_id' => 456, 'group' => 'Keuzemodules', 'required' => true, 'pick_count' => 2],
 * ]
 *
 * Available hooks:
 * - stride/trajectory/created (action) - After trajectory creation
 * - stride/trajectory/updated (action) - After trajectory update
 *
 * @package stride\services\core
 */
class TrajectoryService implements \NTDST_Service_Meta
{
    public const POST_TYPE = 'vad_trajectory';

    /**
     * Cache TTL in seconds (5 minutes)
     */
    private const CACHE_TTL = 300;

    /**
     * Request-level cache for trajectories
     * @var array<int, array|null>
     */
    private static array $trajectoryCache = [];

    /**
     * Service metadata for NTDST Bootstrap
     */
    public static function metadata(): array
    {
        return [
            'name' => 'Trajectory Service',
            'description' => 'Multi-course learning paths with requirements',
            'admin_only' => false,
            'enabled' => true,
            'priority' => 4, // Before EditionService (5)
        ];
    }

    /**
     * Constructor
     */
    public function __construct()
    {
        // Register CPT via DataManager
        add_action('init', [$this, 'registerModel'], 5);

        // Initialize admin controller for custom metaboxes
        if (is_admin()) {
            new TrajectoryAdminController();
        }
    }

    // ========================================
    // CPT REGISTRATION
    // ========================================

    /**
     * Register vad_trajectory model via NTDST DataManager
     */
    public function registerModel(): void
    {
        if (!function_exists('ntdst_data')) {
            $this->registerPostTypeFallback();
            return;
        }

        ntdst_data()->register(self::POST_TYPE, [
            'label' => __('Trajecten', 'stride'),
            'labels' => [
                'name' => __('Trajecten', 'stride'),
                'singular_name' => __('Traject', 'stride'),
                'menu_name' => __('Trajecten', 'stride'),
                'add_new' => __('Nieuw traject', 'stride'),
                'add_new_item' => __('Nieuw traject toevoegen', 'stride'),
                'edit_item' => __('Traject bewerken', 'stride'),
                'view_item' => __('Traject bekijken', 'stride'),
                'all_items' => __('Alle trajecten', 'stride'),
                'search_items' => __('Trajecten zoeken', 'stride'),
                'not_found' => __('Geen trajecten gevonden', 'stride'),
            ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => 'stride-admin',
            'show_in_rest' => false,
            'supports' => ['title', 'editor'],
            'capability_type' => 'post',
            'map_meta_cap' => true,
            'has_archive' => false,
            'menu_icon' => 'dashicons-networking',

            // Field schema for ORM
            'fields' => [
                FieldRegistry::TRAJECTORY_DESCRIPTION => ['type' => 'textarea'],
                FieldRegistry::TRAJECTORY_STATUS => ['type' => 'text', 'default' => FieldRegistry::TRAJECTORY_STATUS_OPEN],
                FieldRegistry::TRAJECTORY_DEADLINE_MONTHS => ['type' => 'integer', 'min' => 0],
                FieldRegistry::TRAJECTORY_COURSES => ['type' => 'json', 'default' => []],
                // Mode fields
                FieldRegistry::TRAJECTORY_MODE => ['type' => 'text', 'default' => FieldRegistry::TRAJECTORY_MODE_SELF_PACED],
                // Cohort-specific fields
                FieldRegistry::TRAJECTORY_ENROLLMENT_DEADLINE => ['type' => 'text'],
                FieldRegistry::TRAJECTORY_CHOICE_AVAILABLE => ['type' => 'text'],
                FieldRegistry::TRAJECTORY_CHOICE_DEADLINE => ['type' => 'text'],
                FieldRegistry::TRAJECTORY_LINKED_EDITIONS => ['type' => 'json', 'default' => []],
            ],
            'auto_metabox' => false, // Custom metaboxes via TrajectoryAdminController
        ]);
    }

    /**
     * Fallback CPT registration if DataManager not available
     */
    private function registerPostTypeFallback(): void
    {
        register_post_type(self::POST_TYPE, [
            'label' => __('Trajecten', 'stride'),
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => 'stride-admin',
            'supports' => ['title', 'editor'],
        ]);
    }

    /**
     * Get the DataManager model
     */
    private function getModel(): ?\NTDST_Data_Model
    {
        if (!function_exists('ntdst_data')) {
            return null;
        }
        return ntdst_data()->get(self::POST_TYPE);
    }

    // ========================================
    // CORE QUERIES
    // ========================================

    /**
     * Get trajectory by ID
     *
     * Uses request-level and object caching for performance.
     *
     * @param int $trajectoryId Trajectory post ID
     * @return array|null Trajectory data or null if not found
     */
    public function getTrajectory(int $trajectoryId): ?array
    {
        // Check request-level cache first
        if (array_key_exists($trajectoryId, self::$trajectoryCache)) {
            return self::$trajectoryCache[$trajectoryId];
        }

        // Check object cache
        $cacheKey = 'stride_trajectory_' . $trajectoryId;
        $cached = wp_cache_get($cacheKey, 'stride');
        if ($cached !== false) {
            self::$trajectoryCache[$trajectoryId] = $cached;
            return $cached;
        }

        $model = $this->getModel();
        if (!$model) {
            return null;
        }

        $post = $model->find($trajectoryId);
        if (is_wp_error($post)) {
            self::$trajectoryCache[$trajectoryId] = null;
            return null;
        }

        $result = $this->formatTrajectory($post);

        // Cache the result
        self::$trajectoryCache[$trajectoryId] = $result;
        wp_cache_set($cacheKey, $result, 'stride', self::CACHE_TTL);

        return $result;
    }

    /**
     * Batch get trajectories by IDs
     *
     * Performance optimization - single query for multiple trajectories.
     *
     * @param array $trajectoryIds Array of trajectory post IDs
     * @return array Map of trajectory_id => trajectory data
     */
    public function getTrajectories(array $trajectoryIds): array
    {
        if (empty($trajectoryIds)) {
            return [];
        }

        // Check which we already have cached
        $result = [];
        $uncached = [];

        foreach ($trajectoryIds as $id) {
            $id = (int) $id;
            if (array_key_exists($id, self::$trajectoryCache)) {
                if (self::$trajectoryCache[$id] !== null) {
                    $result[$id] = self::$trajectoryCache[$id];
                }
            } else {
                $uncached[] = $id;
            }
        }

        // Fetch uncached
        if (!empty($uncached)) {
            $posts = get_posts([
                'post_type' => self::POST_TYPE,
                'post__in' => $uncached,
                'posts_per_page' => count($uncached),
                'post_status' => 'any',
                'no_found_rows' => true,
            ]);

            foreach ($posts as $post) {
                // Get fields from model or direct meta
                $meta = get_post_meta($post->ID);
                $trajectory = $this->formatTrajectoryFromMeta($post, $meta);
                $result[$post->ID] = $trajectory;
                self::$trajectoryCache[$post->ID] = $trajectory;

                // Also set in object cache
                $cacheKey = 'stride_trajectory_' . $post->ID;
                wp_cache_set($cacheKey, $trajectory, 'stride', self::CACHE_TTL);
            }

            // Mark not found as null in cache
            foreach ($uncached as $id) {
                if (!isset($result[$id])) {
                    self::$trajectoryCache[$id] = null;
                }
            }
        }

        return $result;
    }

    /**
     * Format trajectory from post and raw meta
     */
    private function formatTrajectoryFromMeta(\WP_Post $post, array $meta): array
    {
        $courses = $meta[FieldRegistry::TRAJECTORY_COURSES][0] ?? '[]';
        if (is_string($courses)) {
            $courses = json_decode($courses, true, 32) ?: [];
        }

        $linkedEditions = $meta[FieldRegistry::TRAJECTORY_LINKED_EDITIONS][0] ?? '[]';
        if (is_string($linkedEditions)) {
            $linkedEditions = json_decode($linkedEditions, true, 32) ?: [];
        }

        return [
            'id' => $post->ID,
            'title' => $post->post_title,
            'description' => $meta[FieldRegistry::TRAJECTORY_DESCRIPTION][0] ?? $post->post_content ?? '',
            'status' => $meta[FieldRegistry::TRAJECTORY_STATUS][0] ?? FieldRegistry::TRAJECTORY_STATUS_OPEN,
            'deadline_months' => (int) ($meta[FieldRegistry::TRAJECTORY_DEADLINE_MONTHS][0] ?? 0) ?: null,
            'courses' => (array) $courses,
            // Mode fields
            'mode' => $meta[FieldRegistry::TRAJECTORY_MODE][0] ?? FieldRegistry::TRAJECTORY_MODE_SELF_PACED,
            // Cohort-specific fields
            'enrollment_deadline' => $meta[FieldRegistry::TRAJECTORY_ENROLLMENT_DEADLINE][0] ?? null,
            'choice_available_date' => $meta[FieldRegistry::TRAJECTORY_CHOICE_AVAILABLE][0] ?? null,
            'choice_deadline' => $meta[FieldRegistry::TRAJECTORY_CHOICE_DEADLINE][0] ?? null,
            'linked_editions' => (array) $linkedEditions,
        ];
    }

    /**
     * Invalidate trajectory cache
     *
     * @param int|null $trajectoryId Trajectory ID or null for all
     */
    public static function invalidateCache(?int $trajectoryId = null): void
    {
        if ($trajectoryId === null) {
            self::$trajectoryCache = [];
        } else {
            unset(self::$trajectoryCache[$trajectoryId]);
            wp_cache_delete('stride_trajectory_' . $trajectoryId, 'stride');
        }
    }

    /**
     * Get all active trajectories
     *
     * @return array Array of trajectory data
     */
    public function getActiveTrajectories(): array
    {
        $model = $this->getModel();
        if (!$model) {
            return [];
        }

        $posts = $model
            ->where(FieldRegistry::TRAJECTORY_STATUS, FieldRegistry::TRAJECTORY_STATUS_OPEN)
            ->orderBy('post_title', 'ASC')
            ->get();

        return array_map([$this, 'formatTrajectoryFromArray'], $posts);
    }

    /**
     * Get all trajectories
     *
     * @param bool $includeArchived Include archived trajectories
     * @return array Array of trajectory data
     */
    public function getAllTrajectories(bool $includeArchived = false): array
    {
        $model = $this->getModel();
        if (!$model) {
            return [];
        }

        $query = $model->orderBy('post_title', 'ASC');

        if (!$includeArchived) {
            $query = $query->whereNot(FieldRegistry::TRAJECTORY_STATUS, FieldRegistry::TRAJECTORY_STATUS_ARCHIVED);
        }

        $posts = $query->get();
        return array_map([$this, 'formatTrajectoryFromArray'], $posts);
    }

    /**
     * Get trajectories that include a specific course
     *
     * @param int $courseId LearnDash course ID
     * @return array Array of trajectory data
     */
    public function getTrajectoriesForCourse(int $courseId): array
    {
        $allTrajectories = $this->getAllTrajectories();

        return array_filter($allTrajectories, function ($trajectory) use ($courseId) {
            $courses = $trajectory['courses'] ?? [];
            foreach ($courses as $requirement) {
                if ((int) ($requirement['course_id'] ?? 0) === $courseId) {
                    return true;
                }
            }
            return false;
        });
    }

    // ========================================
    // STATUS METHODS
    // ========================================

    /**
     * Get trajectory status
     *
     * @param int $trajectoryId Trajectory post ID
     * @return string Status value
     */
    public function getStatus(int $trajectoryId): string
    {
        $model = $this->getModel();
        if (!$model) {
            return FieldRegistry::TRAJECTORY_STATUS_OPEN;
        }

        $status = $model->getMeta($trajectoryId, FieldRegistry::TRAJECTORY_STATUS);
        return $status ?: FieldRegistry::TRAJECTORY_STATUS_OPEN;
    }

    /**
     * Check if trajectory is open for enrollment
     */
    public function isOpen(int $trajectoryId): bool
    {
        return $this->getStatus($trajectoryId) === FieldRegistry::TRAJECTORY_STATUS_OPEN;
    }

    /**
     * Check if trajectory is closed
     */
    public function isClosed(int $trajectoryId): bool
    {
        return $this->getStatus($trajectoryId) === FieldRegistry::TRAJECTORY_STATUS_CLOSED;
    }

    /**
     * Check if trajectory is archived
     */
    public function isArchived(int $trajectoryId): bool
    {
        return $this->getStatus($trajectoryId) === FieldRegistry::TRAJECTORY_STATUS_ARCHIVED;
    }

    // ========================================
    // COURSE REQUIREMENTS
    // ========================================

    /**
     * Get course requirements for a trajectory
     *
     * @param int $trajectoryId Trajectory post ID
     * @return array Array of course requirements
     */
    public function getCourseRequirements(int $trajectoryId): array
    {
        $model = $this->getModel();
        if (!$model) {
            return [];
        }

        $courses = $model->getMeta($trajectoryId, FieldRegistry::TRAJECTORY_COURSES);

        if (is_string($courses)) {
            $courses = json_decode($courses, true, 32) ?: [];
        }

        return (array) $courses;
    }

    /**
     * Get required courses (basis modules)
     *
     * @param int $trajectoryId Trajectory post ID
     * @return array Array of required course requirements
     */
    public function getRequiredCourses(int $trajectoryId): array
    {
        $requirements = $this->getCourseRequirements($trajectoryId);

        return array_filter($requirements, function ($req) {
            // Required if no pick_count (all must be completed)
            return !isset($req['pick_count']) || $req['pick_count'] <= 0;
        });
    }

    /**
     * Get elective courses (keuze modules) with pick counts
     *
     * @param int $trajectoryId Trajectory post ID
     * @return array Array of elective course groups
     */
    public function getElectiveCourses(int $trajectoryId): array
    {
        $requirements = $this->getCourseRequirements($trajectoryId);

        return array_filter($requirements, function ($req) {
            // Elective if has pick_count > 0
            return isset($req['pick_count']) && $req['pick_count'] > 0;
        });
    }

    /**
     * Get courses grouped by their group name
     *
     * @param int $trajectoryId Trajectory post ID
     * @return array Grouped courses ['group_name' => [...requirements]]
     */
    public function getCoursesByGroup(int $trajectoryId): array
    {
        $requirements = $this->getCourseRequirements($trajectoryId);
        $grouped = [];

        foreach ($requirements as $req) {
            $group = $req['group'] ?? __('Overige', 'stride');
            if (!isset($grouped[$group])) {
                $grouped[$group] = [];
            }
            $grouped[$group][] = $req;
        }

        return $grouped;
    }

    /**
     * Get all course IDs in a trajectory
     *
     * @param int $trajectoryId Trajectory post ID
     * @return array Array of course IDs
     */
    public function getAllCourseIds(int $trajectoryId): array
    {
        $requirements = $this->getCourseRequirements($trajectoryId);
        return array_map(fn($req) => (int) ($req['course_id'] ?? 0), $requirements);
    }

    /**
     * Get deadline in months
     *
     * @param int $trajectoryId Trajectory post ID
     * @return int|null Months to complete or null (no deadline)
     */
    public function getDeadlineMonths(int $trajectoryId): ?int
    {
        $model = $this->getModel();
        if (!$model) {
            return null;
        }

        $months = $model->getMeta($trajectoryId, FieldRegistry::TRAJECTORY_DEADLINE_MONTHS);
        return $months ? (int) $months : null;
    }

    // ========================================
    // CRUD OPERATIONS
    // ========================================

    /**
     * Validate trajectory data
     *
     * @param array $data Trajectory data
     * @return true|WP_Error
     */
    private function validateTrajectoryData(array $data): true|WP_Error
    {
        // Validate deadline is positive
        $deadline = $data[FieldRegistry::TRAJECTORY_DEADLINE_MONTHS] ?? $data['deadline_months'] ?? null;
        if ($deadline !== null && (int) $deadline < 0) {
            return new WP_Error(
                'invalid_deadline',
                __('Deadline kan niet negatief zijn.', 'stride')
            );
        }

        // Validate course requirements structure
        $courses = $data[FieldRegistry::TRAJECTORY_COURSES] ?? $data['courses'] ?? [];
        if (is_string($courses)) {
            $courses = json_decode($courses, true, 32);
        }

        if (!empty($courses) && !is_array($courses)) {
            return new WP_Error(
                'invalid_courses',
                __('Ongeldige cursusstructuur.', 'stride')
            );
        }

        foreach ($courses as $req) {
            if (empty($req['course_id'])) {
                return new WP_Error(
                    'missing_course_id',
                    __('Elke cursusregel moet een course_id hebben.', 'stride')
                );
            }
        }

        return true;
    }

    /**
     * Create a new trajectory
     *
     * @param array $data Trajectory data
     * @return int|WP_Error Trajectory ID or error
     */
    public function createTrajectory(array $data): int|WP_Error
    {
        $model = $this->getModel();
        if (!$model) {
            return new WP_Error('no_model', 'DataManager not available');
        }

        // Validate
        $validation = $this->validateTrajectoryData($data);
        if (is_wp_error($validation)) {
            return $validation;
        }

        // Ensure courses is JSON encoded
        if (isset($data['courses']) && is_array($data['courses'])) {
            $data[FieldRegistry::TRAJECTORY_COURSES] = $data['courses'];
        }

        $result = $model->create($data);

        if (is_wp_error($result)) {
            return $result;
        }

        do_action('stride/trajectory/created', $result->ID, $data);

        return $result->ID;
    }

    /**
     * Update a trajectory
     *
     * @param int $trajectoryId Trajectory ID
     * @param array $data Data to update
     * @return true|WP_Error
     */
    public function updateTrajectory(int $trajectoryId, array $data): true|WP_Error
    {
        $model = $this->getModel();
        if (!$model) {
            return new WP_Error('no_model', 'DataManager not available');
        }

        // Validate
        $validation = $this->validateTrajectoryData($data);
        if (is_wp_error($validation)) {
            return $validation;
        }

        // Ensure courses is JSON encoded
        if (isset($data['courses']) && is_array($data['courses'])) {
            $data[FieldRegistry::TRAJECTORY_COURSES] = $data['courses'];
        }

        $result = $model->update($trajectoryId, $data);
        if (is_wp_error($result)) {
            return $result;
        }

        // Invalidate cache after update
        self::invalidateCache($trajectoryId);

        do_action('stride/trajectory/updated', $trajectoryId, $data);

        return true;
    }

    /**
     * Add a course requirement to a trajectory
     *
     * @param int $trajectoryId Trajectory ID
     * @param int $courseId Course ID to add
     * @param string $group Group name
     * @param int|null $pickCount Pick count for electives (null for required)
     * @return true|WP_Error
     */
    public function addCourseRequirement(int $trajectoryId, int $courseId, string $group = '', ?int $pickCount = null): true|WP_Error
    {
        $requirements = $this->getCourseRequirements($trajectoryId);

        // Check if already exists
        foreach ($requirements as $req) {
            if ((int) ($req['course_id'] ?? 0) === $courseId) {
                return new WP_Error('already_exists', __('Cursus zit al in dit traject.', 'stride'));
            }
        }

        $newRequirement = [
            'course_id' => $courseId,
            'group' => $group,
        ];

        if ($pickCount !== null && $pickCount > 0) {
            $newRequirement['pick_count'] = $pickCount;
        }

        $requirements[] = $newRequirement;

        return $this->updateTrajectory($trajectoryId, [
            FieldRegistry::TRAJECTORY_COURSES => $requirements,
        ]);
    }

    /**
     * Remove a course requirement from a trajectory
     *
     * @param int $trajectoryId Trajectory ID
     * @param int $courseId Course ID to remove
     * @return true|WP_Error
     */
    public function removeCourseRequirement(int $trajectoryId, int $courseId): true|WP_Error
    {
        $requirements = $this->getCourseRequirements($trajectoryId);

        $filtered = array_filter($requirements, function ($req) use ($courseId) {
            return (int) ($req['course_id'] ?? 0) !== $courseId;
        });

        if (count($filtered) === count($requirements)) {
            return new WP_Error('not_found', __('Cursus niet gevonden in traject.', 'stride'));
        }

        return $this->updateTrajectory($trajectoryId, [
            FieldRegistry::TRAJECTORY_COURSES => array_values($filtered),
        ]);
    }

    // ========================================
    // FORMATTING
    // ========================================

    /**
     * Format trajectory post to array
     */
    private function formatTrajectory(\WP_Post $post): array
    {
        $courses = $post->fields[FieldRegistry::TRAJECTORY_COURSES] ?? [];
        if (is_string($courses)) {
            $courses = json_decode($courses, true, 32) ?: [];
        }

        $linkedEditions = $post->fields[FieldRegistry::TRAJECTORY_LINKED_EDITIONS] ?? [];
        if (is_string($linkedEditions)) {
            $linkedEditions = json_decode($linkedEditions, true, 32) ?: [];
        }

        return [
            'id' => $post->ID,
            'title' => $post->post_title,
            'description' => $post->fields[FieldRegistry::TRAJECTORY_DESCRIPTION] ?? $post->post_content ?? '',
            'status' => $post->fields[FieldRegistry::TRAJECTORY_STATUS] ?? FieldRegistry::TRAJECTORY_STATUS_OPEN,
            'deadline_months' => (int) ($post->fields[FieldRegistry::TRAJECTORY_DEADLINE_MONTHS] ?? 0) ?: null,
            'courses' => (array) $courses,
            // Mode fields
            'mode' => $post->fields[FieldRegistry::TRAJECTORY_MODE] ?? FieldRegistry::TRAJECTORY_MODE_SELF_PACED,
            // Cohort-specific fields
            'enrollment_deadline' => $post->fields[FieldRegistry::TRAJECTORY_ENROLLMENT_DEADLINE] ?? null,
            'choice_available_date' => $post->fields[FieldRegistry::TRAJECTORY_CHOICE_AVAILABLE] ?? null,
            'choice_deadline' => $post->fields[FieldRegistry::TRAJECTORY_CHOICE_DEADLINE] ?? null,
            'linked_editions' => (array) $linkedEditions,
        ];
    }

    /**
     * Format trajectory from array (from DataManager query)
     */
    private function formatTrajectoryFromArray(array $data): array
    {
        $meta = $data['meta'] ?? [];
        $courses = $meta[FieldRegistry::TRAJECTORY_COURSES] ?? [];

        if (is_string($courses)) {
            $courses = json_decode($courses, true, 32) ?: [];
        }

        $linkedEditions = $meta[FieldRegistry::TRAJECTORY_LINKED_EDITIONS] ?? [];
        if (is_string($linkedEditions)) {
            $linkedEditions = json_decode($linkedEditions, true, 32) ?: [];
        }

        return [
            'id' => $data['id'] ?? 0,
            'title' => $data['title'] ?? '',
            'description' => $meta[FieldRegistry::TRAJECTORY_DESCRIPTION] ?? $data['content'] ?? '',
            'status' => $meta[FieldRegistry::TRAJECTORY_STATUS] ?? FieldRegistry::TRAJECTORY_STATUS_OPEN,
            'deadline_months' => (int) ($meta[FieldRegistry::TRAJECTORY_DEADLINE_MONTHS] ?? 0) ?: null,
            'courses' => (array) $courses,
            // Mode fields
            'mode' => $meta[FieldRegistry::TRAJECTORY_MODE] ?? FieldRegistry::TRAJECTORY_MODE_SELF_PACED,
            // Cohort-specific fields
            'enrollment_deadline' => $meta[FieldRegistry::TRAJECTORY_ENROLLMENT_DEADLINE] ?? null,
            'choice_available_date' => $meta[FieldRegistry::TRAJECTORY_CHOICE_AVAILABLE] ?? null,
            'choice_deadline' => $meta[FieldRegistry::TRAJECTORY_CHOICE_DEADLINE] ?? null,
            'linked_editions' => (array) $linkedEditions,
        ];
    }

    // ========================================
    // MODE HELPERS
    // ========================================

    /**
     * Check if trajectory is self-paced mode
     *
     * @param int $trajectoryId Trajectory post ID
     * @return bool True if self-paced mode
     */
    public function isSelfPaced(int $trajectoryId): bool
    {
        $trajectory = $this->getTrajectory($trajectoryId);
        if (!$trajectory) {
            return true; // Default to self-paced
        }
        // Treat empty/unset mode as self-paced (default for existing trajectories)
        $mode = $trajectory['mode'] ?? '';
        return empty($mode) || $mode === FieldRegistry::TRAJECTORY_MODE_SELF_PACED;
    }

    /**
     * Check if trajectory is cohort mode
     *
     * @param int $trajectoryId Trajectory post ID
     * @return bool True if cohort mode
     */
    public function isCohort(int $trajectoryId): bool
    {
        $trajectory = $this->getTrajectory($trajectoryId);
        if (!$trajectory) {
            return false;
        }
        // Only cohort if explicitly set to cohort (not empty/default)
        return ($trajectory['mode'] ?? '') === FieldRegistry::TRAJECTORY_MODE_COHORT;
    }

    /**
     * Get linked edition for a specific course in a cohort trajectory
     *
     * @param int $trajectoryId Trajectory post ID
     * @param int $courseId LearnDash course ID
     * @return int|null Edition ID or null if not linked
     */
    public function getLinkedEdition(int $trajectoryId, int $courseId): ?int
    {
        $trajectory = $this->getTrajectory($trajectoryId);
        if (!$trajectory) {
            return null;
        }

        // Check mode directly from trajectory data (avoid redundant lookup)
        $mode = $trajectory['mode'] ?? '';
        if ($mode !== FieldRegistry::TRAJECTORY_MODE_COHORT) {
            return null;
        }

        $linkedEditions = $trajectory['linked_editions'] ?? [];
        foreach ($linkedEditions as $link) {
            if ((int) ($link['course_id'] ?? 0) === $courseId) {
                return (int) ($link['edition_id'] ?? 0) ?: null;
            }
        }

        return null;
    }

    /**
     * Get all linked editions for a cohort trajectory
     *
     * @param int $trajectoryId Trajectory post ID
     * @return array Array of ['course_id' => edition_id] mappings
     */
    public function getLinkedEditions(int $trajectoryId): array
    {
        $trajectory = $this->getTrajectory($trajectoryId);
        if (!$trajectory) {
            return [];
        }

        // Check mode directly from trajectory data (avoid redundant lookup)
        $mode = $trajectory['mode'] ?? '';
        if ($mode !== FieldRegistry::TRAJECTORY_MODE_COHORT) {
            return [];
        }

        return $trajectory['linked_editions'] ?? [];
    }

    /**
     * Check if enrollment is currently open for a trajectory
     *
     * For self-paced: open if status is 'open'
     * For cohort: open if status is 'open' AND enrollment_deadline not passed
     *
     * @param int $trajectoryId Trajectory post ID
     * @return bool True if enrollment is open
     */
    public function isEnrollmentOpen(int $trajectoryId): bool
    {
        $trajectory = $this->getTrajectory($trajectoryId);
        if (!$trajectory) {
            return false;
        }

        // Status must be open
        if (($trajectory['status'] ?? '') !== FieldRegistry::TRAJECTORY_STATUS_OPEN) {
            return false;
        }

        // Check mode directly from trajectory data (avoid redundant lookup)
        $mode = $trajectory['mode'] ?? '';
        $isSelfPaced = empty($mode) || $mode === FieldRegistry::TRAJECTORY_MODE_SELF_PACED;

        // For self-paced, status is sufficient
        if ($isSelfPaced) {
            return true;
        }

        // For cohort, check enrollment deadline
        $deadline = $trajectory['enrollment_deadline'] ?? null;
        if (empty($deadline)) {
            return true; // No deadline set, assume open
        }

        // Validate date format before parsing
        if (!$this->isValidDate($deadline)) {
            return true; // Invalid date, assume open
        }

        return strtotime($deadline) >= strtotime(current_time('Y-m-d'));
    }

    /**
     * Check if currently in choice period for a cohort trajectory
     *
     * Choice period is between choice_available_date and choice_deadline.
     * Only relevant for cohort mode.
     *
     * @param int $trajectoryId Trajectory post ID
     * @return bool True if in choice period
     */
    public function isChoicePeriod(int $trajectoryId): bool
    {
        $trajectory = $this->getTrajectory($trajectoryId);
        if (!$trajectory) {
            return false;
        }

        // Check mode directly from trajectory data (avoid redundant lookup)
        $mode = $trajectory['mode'] ?? '';
        if ($mode !== FieldRegistry::TRAJECTORY_MODE_COHORT) {
            return false;
        }

        $choiceAvailable = $trajectory['choice_available_date'] ?? null;
        $choiceDeadline = $trajectory['choice_deadline'] ?? null;

        if (empty($choiceAvailable)) {
            return false; // No choice period configured
        }

        // Validate date formats before parsing
        if (!$this->isValidDate($choiceAvailable)) {
            return false;
        }

        $now = current_time('Y-m-d');
        $afterStart = strtotime($choiceAvailable) <= strtotime($now);

        // Check deadline only if set and valid
        $beforeEnd = true;
        if (!empty($choiceDeadline) && $this->isValidDate($choiceDeadline)) {
            $beforeEnd = strtotime($choiceDeadline) >= strtotime($now);
        }

        return $afterStart && $beforeEnd;
    }

    /**
     * Validate date string format (YYYY-MM-DD)
     *
     * @param string $date Date string to validate
     * @return bool True if valid date format
     */
    private function isValidDate(string $date): bool
    {
        $parsed = \DateTime::createFromFormat('Y-m-d', $date);
        return $parsed !== false && $parsed->format('Y-m-d') === $date;
    }

    /**
     * Set linked editions for a cohort trajectory
     *
     * Note: Authorization must be handled by calling code (admin controller, API endpoint).
     * This service method does not check capabilities.
     *
     * @param int $trajectoryId Trajectory post ID
     * @param array $linkedEditions Array of ['course_id' => int, 'edition_id' => int]
     * @return true|WP_Error
     */
    public function setLinkedEditions(int $trajectoryId, array $linkedEditions): true|WP_Error
    {
        // Check mode directly from trajectory (avoid redundant lookup)
        $trajectory = $this->getTrajectory($trajectoryId);
        if (!$trajectory) {
            return new WP_Error('not_found', __('Trajectory not found.', 'stride'));
        }

        $mode = $trajectory['mode'] ?? '';
        if ($mode !== FieldRegistry::TRAJECTORY_MODE_COHORT) {
            return new WP_Error('not_cohort', __('Linked editions are only available for cohort trajectories.', 'stride'));
        }

        return $this->updateTrajectory($trajectoryId, [
            FieldRegistry::TRAJECTORY_LINKED_EDITIONS => $linkedEditions,
        ]);
    }
}
