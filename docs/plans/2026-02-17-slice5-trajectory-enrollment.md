# Slice 5: Trajectory Enrollment with Electives

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Enable multi-course programs (trajectories) with required and elective courses, choice windows, and deadlines.

**Architecture:** Trajectories are CPTs containing course configurations. Users enroll in trajectories, then pick electives during choice windows. Elective choices stored in `wp_vad_trajectory_enrollments` table. Deadlines lock selections. Similar pattern to session selection (Slice 4).

**Tech Stack:** PHP 8.3, ntdst-core DataManager, WordPress custom tables

---

## Context

**What exists:**
- `EditionCPT`, `EditionService`, `EditionRepository` for scheduled offerings
- `SessionSelectionService` for deadline-aware selection (Slice 4 pattern to follow)
- `RegistrationTable` and `RegistrationRepository` for user→edition enrollment
- `AbstractRepository` and `AbstractService` base classes
- Domain enums: `EditionStatus`, `RegistrationStatus`, etc.

**What we're building:**
- Trajectory CPT (`vad_trajectory`) - multi-course programs
- `TrajectoryRepository` - CRUD for trajectories
- `TrajectoryService` - business logic
- `TrajectoryEnrollmentTable` - user→trajectory enrollments with elective choices
- `TrajectoryEnrollmentRepository` - CRUD for enrollment records
- `TrajectorySelectionService` - deadline-aware elective selection
- `TrajectoryMode` and `TrajectoryStatus` domain enums

**Data flow:**
```
Trajectory (has course configurations)
  └── TrajectoryEnrollment (user enrolled)
        └── elective_choices[] (user picks from 'Keuze' groups)
              └── Locked after choice_deadline
```

---

## Task 1: Domain Enums

**Files:**
- Create: `web/app/mu-plugins/stride-core/Domain/TrajectoryMode.php`
- Create: `web/app/mu-plugins/stride-core/Domain/TrajectoryStatus.php`

**Step 1: Create TrajectoryMode enum**

```php
<?php

declare(strict_types=1);

namespace Stride\Domain;

/**
 * Trajectory enrollment modes.
 */
enum TrajectoryMode: string
{
    case Cohort = 'cohort';
    case SelfPaced = 'self_paced';

    /**
     * Get human-readable label.
     */
    public function label(): string
    {
        return match ($this) {
            self::Cohort => 'Cohort (vaste editie-reeks)',
            self::SelfPaced => 'Zelfgestuurd (eigen edities kiezen)',
        };
    }

    /**
     * Check if user must pick editions.
     */
    public function requiresEditionChoice(): bool
    {
        return $this === self::SelfPaced;
    }
}
```

**Step 2: Create TrajectoryStatus enum**

```php
<?php

declare(strict_types=1);

namespace Stride\Domain;

/**
 * Trajectory status values.
 */
enum TrajectoryStatus: string
{
    case Draft = 'draft';
    case Open = 'open';
    case InProgress = 'in_progress';
    case Closed = 'closed';
    case Archived = 'archived';

    /**
     * Check if enrollment is allowed.
     */
    public function allowsEnrollment(): bool
    {
        return $this === self::Open;
    }

    /**
     * Check if trajectory is active.
     */
    public function isActive(): bool
    {
        return in_array($this, [self::Open, self::InProgress], true);
    }

    /**
     * Get human-readable label.
     */
    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Concept',
            self::Open => 'Open voor inschrijving',
            self::InProgress => 'Lopend',
            self::Closed => 'Gesloten',
            self::Archived => 'Gearchiveerd',
        };
    }
}
```

**Step 3: Commit**

```bash
git add web/app/mu-plugins/stride-core/Domain/TrajectoryMode.php
git add web/app/mu-plugins/stride-core/Domain/TrajectoryStatus.php
git commit -m "feat(trajectory): add TrajectoryMode and TrajectoryStatus enums"
```

---

## Task 2: Trajectory CPT

**Files:**
- Create: `web/app/mu-plugins/stride-core/Modules/Trajectory/TrajectoryCPT.php`

**Step 1: Create TrajectoryCPT class**

```php
<?php

declare(strict_types=1);

namespace Stride\Modules\Trajectory;

/**
 * Trajectory CPT Registration.
 *
 * Multi-course programs with required and elective courses.
 */
final class TrajectoryCPT
{
    public const POST_TYPE = 'vad_trajectory';

    public static function register(): void
    {
        ntdst_data()->register(self::POST_TYPE, [
            'label' => 'Trajecten',
            'labels' => [
                'name' => 'Trajecten',
                'singular_name' => 'Traject',
                'add_new' => 'Nieuw traject',
                'add_new_item' => 'Nieuw traject toevoegen',
                'edit_item' => 'Traject bewerken',
            ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => true,
            'menu_icon' => 'dashicons-networking',
            'supports' => ['title', 'editor'],
            'fields' => self::getFields(),
            'field_groups' => self::getFieldGroups(),
        ]);
    }

    private static function getFields(): array
    {
        return [
            'mode' => [
                'type' => 'text',
                'label' => 'Modus',
                'description' => 'cohort or self_paced',
            ],
            'status' => [
                'type' => 'text',
                'label' => 'Status',
            ],
            'enrollment_deadline' => [
                'type' => 'text',
                'label' => 'Inschrijfdeadline',
                'description' => 'Last date to enroll (YYYY-MM-DD)',
            ],
            'choice_available_date' => [
                'type' => 'text',
                'label' => 'Keuzemoment start',
                'description' => 'When elective choice opens (YYYY-MM-DD)',
            ],
            'choice_deadline' => [
                'type' => 'text',
                'label' => 'Keuzemoment deadline',
                'description' => 'When elective choice locks (YYYY-MM-DD)',
            ],
            'courses' => [
                'type' => 'json',
                'label' => 'Cursussen',
                'description' => 'JSON array of course configurations',
            ],
            'capacity' => [
                'type' => 'int',
                'label' => 'Capaciteit',
            ],
            'price' => [
                'type' => 'float',
                'label' => 'Prijs (leden)',
            ],
            'price_non_member' => [
                'type' => 'float',
                'label' => 'Prijs (niet-leden)',
            ],
        ];
    }

    private static function getFieldGroups(): array
    {
        return [
            'trajectory_details' => [
                'title' => 'Traject Details',
                'fields' => ['mode', 'status', 'capacity'],
            ],
            'trajectory_deadlines' => [
                'title' => 'Deadlines',
                'fields' => ['enrollment_deadline', 'choice_available_date', 'choice_deadline'],
            ],
            'trajectory_courses' => [
                'title' => 'Cursussen',
                'fields' => ['courses'],
            ],
            'trajectory_pricing' => [
                'title' => 'Prijzen',
                'fields' => ['price', 'price_non_member'],
            ],
        ];
    }
}
```

**Step 2: Register CPT in stride-core.php**

Add after line 24 (with other CPT registrations):

```php
add_action('init', [\Stride\Modules\Trajectory\TrajectoryCPT::class, 'register'], 5);
```

**Step 3: Verify CPT loads**

Run: `ddev exec wp post-type list --format=table | grep vad_trajectory`

Expected: `vad_trajectory` appears in list

**Step 4: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Trajectory/TrajectoryCPT.php
git add web/app/mu-plugins/stride-core/stride-core.php
git commit -m "feat(trajectory): add Trajectory CPT registration"
```

---

## Task 3: Trajectory Repository

**Files:**
- Create: `web/app/mu-plugins/stride-core/Modules/Trajectory/TrajectoryRepository.php`

**Step 1: Create TrajectoryRepository class**

```php
<?php

declare(strict_types=1);

namespace Stride\Modules\Trajectory;

use Stride\Domain\TrajectoryMode;
use Stride\Domain\TrajectoryStatus;
use Stride\Infrastructure\AbstractRepository;
use WP_Error;
use WP_Post;

/**
 * Trajectory repository for CRUD operations.
 */
final class TrajectoryRepository extends AbstractRepository
{
    protected string $postType = TrajectoryCPT::POST_TYPE;

    /**
     * Get a single field value.
     */
    public function getField(int $id, string $field, mixed $default = null): mixed
    {
        $value = $this->model()->getMeta($id, $field);

        return $value !== null ? $value : $default;
    }

    /**
     * Find active trajectories.
     *
     * @return array<array<string, mixed>>
     */
    public function findActive(): array
    {
        return $this->model()
            ->whereIn('status', [TrajectoryStatus::Open->value, TrajectoryStatus::InProgress->value])
            ->orderBy('post_title', 'ASC')
            ->withMeta()
            ->get();
    }

    /**
     * Find trajectories open for enrollment.
     *
     * @return array<array<string, mixed>>
     */
    public function findOpen(): array
    {
        return $this->model()
            ->where('status', TrajectoryStatus::Open->value)
            ->orderBy('post_title', 'ASC')
            ->withMeta()
            ->get();
    }

    /**
     * Validate trajectory data before create/update.
     */
    public function validate(array $data): true|WP_Error
    {
        // Validate mode if provided
        if (!empty($data['mode'])) {
            $mode = TrajectoryMode::tryFrom($data['mode']);
            if ($mode === null) {
                return new WP_Error('invalid_mode', 'Invalid trajectory mode');
            }
        }

        // Validate status if provided
        if (!empty($data['status'])) {
            $status = TrajectoryStatus::tryFrom($data['status']);
            if ($status === null) {
                return new WP_Error('invalid_status', 'Invalid trajectory status');
            }
        }

        // Validate deadline order if both provided
        if (!empty($data['choice_available_date']) && !empty($data['choice_deadline'])) {
            if (strtotime($data['choice_deadline']) <= strtotime($data['choice_available_date'])) {
                return new WP_Error('invalid_deadlines', 'Choice deadline must be after choice available date');
            }
        }

        return true;
    }

    /**
     * Create trajectory with validation.
     */
    public function create(array $data): WP_Post|WP_Error
    {
        $validation = $this->validate($data);
        if (is_wp_error($validation)) {
            return $validation;
        }

        // Set defaults
        if (empty($data['mode'])) {
            $data['mode'] = TrajectoryMode::Cohort->value;
        }
        if (empty($data['status'])) {
            $data['status'] = TrajectoryStatus::Draft->value;
        }

        return parent::create($data);
    }

    /**
     * Get course configuration for trajectory.
     *
     * @return array<array<string, mixed>>
     */
    public function getCourses(int $trajectoryId): array
    {
        $courses = $this->getField($trajectoryId, 'courses', []);

        if (empty($courses)) {
            return [];
        }

        return is_array($courses) ? $courses : json_decode($courses, true) ?: [];
    }

    /**
     * Get required courses for trajectory.
     *
     * @return array<array<string, mixed>>
     */
    public function getRequiredCourses(int $trajectoryId): array
    {
        $courses = $this->getCourses($trajectoryId);

        return array_filter($courses, fn($c) => ($c['required'] ?? false) === true);
    }

    /**
     * Get elective groups for trajectory.
     *
     * @return array<string, array<array<string, mixed>>>
     */
    public function getElectiveGroups(int $trajectoryId): array
    {
        $courses = $this->getCourses($trajectoryId);
        $electives = array_filter($courses, fn($c) => ($c['required'] ?? false) === false);

        $groups = [];
        foreach ($electives as $course) {
            $group = $course['group'] ?? 'Keuze';
            if (!isset($groups[$group])) {
                $groups[$group] = [];
            }
            $groups[$group][] = $course;
        }

        return $groups;
    }
}
```

**Step 2: Register repository in stride-core.php**

Add after line 50 (with other repository registrations):

```php
ntdst_set(\Stride\Modules\Trajectory\TrajectoryRepository::class);
```

**Step 3: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Trajectory/TrajectoryRepository.php
git add web/app/mu-plugins/stride-core/stride-core.php
git commit -m "feat(trajectory): add TrajectoryRepository with validation"
```

---

## Task 4: Trajectory Service

**Files:**
- Create: `web/app/mu-plugins/stride-core/Modules/Trajectory/TrajectoryService.php`

**Step 1: Create TrajectoryService class**

```php
<?php

declare(strict_types=1);

namespace Stride\Modules\Trajectory;

use Stride\Domain\TrajectoryMode;
use Stride\Domain\TrajectoryStatus;
use Stride\Infrastructure\AbstractService;
use WP_Error;
use WP_Post;

/**
 * Trajectory business logic.
 */
final class TrajectoryService extends AbstractService
{
    public function __construct(
        private readonly TrajectoryRepository $repository,
    ) {
        parent::__construct();
    }

    public static function metadata(): array
    {
        return [
            'name' => 'Trajectory Service',
            'description' => 'Manages multi-course programs',
            'priority' => 20,
        ];
    }

    protected function getConfigSlug(): string
    {
        return 'trajectory';
    }

    protected function init(): void
    {
        // Future: hooks for trajectory events
    }

    // === CRUD ===

    /**
     * Create a new trajectory.
     */
    public function createTrajectory(array $data): int|WP_Error
    {
        $result = $this->repository->create($data);

        if (is_wp_error($result)) {
            return $result;
        }

        do_action('stride/trajectory/created', [
            'trajectory_id' => $result->ID,
        ]);

        return $result->ID;
    }

    /**
     * Get trajectory by ID.
     *
     * @return array<string, mixed>|null
     */
    public function getTrajectory(int $trajectoryId): ?array
    {
        $post = $this->repository->find($trajectoryId);

        if (is_wp_error($post)) {
            return null;
        }

        return $this->formatTrajectory($post);
    }

    /**
     * Update trajectory.
     */
    public function updateTrajectory(int $trajectoryId, array $data): true|WP_Error
    {
        $result = $this->repository->update($trajectoryId, $data);

        if (is_wp_error($result)) {
            return $result;
        }

        return true;
    }

    // === Queries ===

    /**
     * Get all active trajectories.
     *
     * @return array<array<string, mixed>>
     */
    public function getActiveTrajectories(): array
    {
        $trajectories = $this->repository->findActive();

        return array_map([$this, 'formatTrajectoryArray'], $trajectories);
    }

    /**
     * Get trajectories open for enrollment.
     *
     * @return array<array<string, mixed>>
     */
    public function getOpenTrajectories(): array
    {
        $trajectories = $this->repository->findOpen();

        return array_map([$this, 'formatTrajectoryArray'], $trajectories);
    }

    /**
     * Get course configuration for trajectory.
     *
     * @return array<array<string, mixed>>
     */
    public function getCourses(int $trajectoryId): array
    {
        return $this->repository->getCourses($trajectoryId);
    }

    /**
     * Get required courses.
     *
     * @return array<array<string, mixed>>
     */
    public function getRequiredCourses(int $trajectoryId): array
    {
        return $this->repository->getRequiredCourses($trajectoryId);
    }

    /**
     * Get elective groups.
     *
     * @return array<string, array<array<string, mixed>>>
     */
    public function getElectiveGroups(int $trajectoryId): array
    {
        return $this->repository->getElectiveGroups($trajectoryId);
    }

    /**
     * Get total course count.
     */
    public function getCourseCount(int $trajectoryId): int
    {
        return count($this->repository->getCourses($trajectoryId));
    }

    /**
     * Get required course count.
     */
    public function getRequiredCourseCount(int $trajectoryId): int
    {
        return count($this->repository->getRequiredCourses($trajectoryId));
    }

    // === Deadline Checks ===

    /**
     * Check if enrollment is open.
     */
    public function isEnrollmentOpen(int $trajectoryId): bool
    {
        $trajectory = $this->getTrajectory($trajectoryId);

        if (!$trajectory) {
            return false;
        }

        // Check status
        if (!$trajectory['status_enum']->allowsEnrollment()) {
            return false;
        }

        // Check enrollment deadline if set
        $deadline = $trajectory['enrollment_deadline'];
        if (!empty($deadline) && strtotime($deadline) < time()) {
            return false;
        }

        return true;
    }

    /**
     * Check if choice window is open.
     */
    public function isChoiceWindowOpen(int $trajectoryId): bool
    {
        $trajectory = $this->getTrajectory($trajectoryId);

        if (!$trajectory) {
            return false;
        }

        $now = time();

        // Check choice available date
        $availableDate = $trajectory['choice_available_date'];
        if (!empty($availableDate) && strtotime($availableDate) > $now) {
            return false;
        }

        // Check choice deadline
        $deadline = $trajectory['choice_deadline'];
        if (!empty($deadline) && strtotime($deadline) < $now) {
            return false;
        }

        return true;
    }

    /**
     * Check if choices are locked.
     */
    public function areChoicesLocked(int $trajectoryId): bool
    {
        $deadline = $this->repository->getField($trajectoryId, 'choice_deadline');

        if (empty($deadline)) {
            return false;
        }

        return strtotime($deadline) < time();
    }

    // === Helpers ===

    /**
     * Format WP_Post to trajectory array.
     */
    private function formatTrajectory(WP_Post $post): array
    {
        $modeValue = $this->repository->getField($post->ID, 'mode', 'cohort');
        $mode = TrajectoryMode::tryFrom($modeValue) ?? TrajectoryMode::Cohort;

        $statusValue = $this->repository->getField($post->ID, 'status', 'draft');
        $status = TrajectoryStatus::tryFrom($statusValue) ?? TrajectoryStatus::Draft;

        return [
            'id' => $post->ID,
            'title' => $post->post_title,
            'description' => $post->post_content,
            'mode' => $mode->value,
            'mode_enum' => $mode,
            'status' => $status->value,
            'status_enum' => $status,
            'enrollment_deadline' => $this->repository->getField($post->ID, 'enrollment_deadline', ''),
            'choice_available_date' => $this->repository->getField($post->ID, 'choice_available_date', ''),
            'choice_deadline' => $this->repository->getField($post->ID, 'choice_deadline', ''),
            'capacity' => (int) $this->repository->getField($post->ID, 'capacity', 0),
            'price' => (float) $this->repository->getField($post->ID, 'price', 0),
            'price_non_member' => (float) $this->repository->getField($post->ID, 'price_non_member', 0),
            'courses' => $this->repository->getCourses($post->ID),
        ];
    }

    /**
     * Format array result to trajectory array.
     */
    private function formatTrajectoryArray(array $data): array
    {
        $modeValue = $data['meta']['mode'] ?? $data['mode'] ?? 'cohort';
        $mode = TrajectoryMode::tryFrom($modeValue) ?? TrajectoryMode::Cohort;

        $statusValue = $data['meta']['status'] ?? $data['status'] ?? 'draft';
        $status = TrajectoryStatus::tryFrom($statusValue) ?? TrajectoryStatus::Draft;

        $courses = $data['meta']['courses'] ?? $data['courses'] ?? [];
        if (is_string($courses)) {
            $courses = json_decode($courses, true) ?: [];
        }

        return [
            'id' => (int) ($data['id'] ?? $data['ID'] ?? 0),
            'title' => $data['post_title'] ?? '',
            'description' => $data['post_content'] ?? '',
            'mode' => $mode->value,
            'mode_enum' => $mode,
            'status' => $status->value,
            'status_enum' => $status,
            'enrollment_deadline' => $data['meta']['enrollment_deadline'] ?? $data['enrollment_deadline'] ?? '',
            'choice_available_date' => $data['meta']['choice_available_date'] ?? $data['choice_available_date'] ?? '',
            'choice_deadline' => $data['meta']['choice_deadline'] ?? $data['choice_deadline'] ?? '',
            'capacity' => (int) ($data['meta']['capacity'] ?? $data['capacity'] ?? 0),
            'price' => (float) ($data['meta']['price'] ?? $data['price'] ?? 0),
            'price_non_member' => (float) ($data['meta']['price_non_member'] ?? $data['price_non_member'] ?? 0),
            'courses' => $courses,
        ];
    }
}
```

**Step 2: Register service in plugin-config.php**

Add to the services array:

```php
\Stride\Modules\Trajectory\TrajectoryService::class,
```

**Step 3: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Trajectory/TrajectoryService.php
git add web/app/mu-plugins/stride-core/plugin-config.php
git commit -m "feat(trajectory): add TrajectoryService with deadlines"
```

---

## Task 5: Trajectory Enrollment Table

**Files:**
- Create: `web/app/mu-plugins/stride-core/Modules/Trajectory/TrajectoryEnrollmentTable.php`

**Step 1: Create table class**

```php
<?php

declare(strict_types=1);

namespace Stride\Modules\Trajectory;

/**
 * Trajectory enrollment table for user enrollments and elective choices.
 */
final class TrajectoryEnrollmentTable
{
    public const TABLE_NAME = 'vad_trajectory_enrollments';

    public static function getTableName(): string
    {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_NAME;
    }

    public static function create(): void
    {
        global $wpdb;

        $table = self::getTableName();
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            trajectory_id BIGINT UNSIGNED NOT NULL,
            status ENUM('enrolled','completed','cancelled','withdrawn') DEFAULT 'enrolled',
            elective_choices JSON NULL COMMENT 'Array of chosen course_ids by group',
            choices_locked_at DATETIME NULL,
            enrolled_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            completed_at DATETIME NULL,
            cancelled_at DATETIME NULL,
            notes TEXT NULL,
            INDEX idx_user (user_id),
            INDEX idx_trajectory (trajectory_id),
            INDEX idx_status (status),
            UNIQUE KEY unique_user_trajectory (user_id, trajectory_id)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    public static function exists(): bool
    {
        global $wpdb;

        $table = self::getTableName();

        return $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) === $table;
    }
}
```

**Step 2: Add table creation to stride-core.php**

Add `TrajectoryEnrollmentTable::create();` in the table creation block (around line 30):

```php
\Stride\Modules\Trajectory\TrajectoryEnrollmentTable::create();
```

Also add migration hook for existing installations (after line 41):

```php
// Create trajectory_enrollments table if missing
add_action('init', function (): void {
    if (!get_option('stride_trajectory_enrollments_table_created')) {
        \Stride\Modules\Trajectory\TrajectoryEnrollmentTable::create();
        update_option('stride_trajectory_enrollments_table_created', '1');
    }
}, 1);
```

**Step 3: Trigger table creation**

Run: `ddev exec wp eval "\\Stride\\Modules\\Trajectory\\TrajectoryEnrollmentTable::create();"`

**Step 4: Verify table exists**

Run: `ddev exec wp db query "SHOW TABLES LIKE '%trajectory_enrollments%'"`

Expected: Table name appears

**Step 5: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Trajectory/TrajectoryEnrollmentTable.php
git add web/app/mu-plugins/stride-core/stride-core.php
git commit -m "feat(trajectory): add trajectory_enrollments table"
```

---

## Task 6: Trajectory Enrollment Repository

**Files:**
- Create: `web/app/mu-plugins/stride-core/Modules/Trajectory/TrajectoryEnrollmentRepository.php`

**Step 1: Create repository class**

```php
<?php

declare(strict_types=1);

namespace Stride\Modules\Trajectory;

use WP_Error;

/**
 * Repository for trajectory enrollment records.
 */
final class TrajectoryEnrollmentRepository
{
    /**
     * Find enrollment by ID.
     *
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        global $wpdb;
        $table = TrajectoryEnrollmentTable::getTableName();

        $result = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d",
            $id
        ), ARRAY_A);

        return $result ?: null;
    }

    /**
     * Find enrollment by user and trajectory.
     *
     * @return array<string, mixed>|null
     */
    public function findByUserAndTrajectory(int $userId, int $trajectoryId): ?array
    {
        global $wpdb;
        $table = TrajectoryEnrollmentTable::getTableName();

        $result = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE user_id = %d AND trajectory_id = %d",
            $userId,
            $trajectoryId
        ), ARRAY_A);

        return $result ?: null;
    }

    /**
     * Find all enrollments for a user.
     *
     * @return array<array<string, mixed>>
     */
    public function findByUser(int $userId): array
    {
        global $wpdb;
        $table = TrajectoryEnrollmentTable::getTableName();

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE user_id = %d ORDER BY enrolled_at DESC",
            $userId
        ), ARRAY_A);

        return $results ?: [];
    }

    /**
     * Find active enrollments for a user.
     *
     * @return array<array<string, mixed>>
     */
    public function findActiveByUser(int $userId): array
    {
        global $wpdb;
        $table = TrajectoryEnrollmentTable::getTableName();

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE user_id = %d AND status IN ('enrolled', 'completed') ORDER BY enrolled_at DESC",
            $userId
        ), ARRAY_A);

        return $results ?: [];
    }

    /**
     * Find all enrollments for a trajectory.
     *
     * @return array<array<string, mixed>>
     */
    public function findByTrajectory(int $trajectoryId): array
    {
        global $wpdb;
        $table = TrajectoryEnrollmentTable::getTableName();

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE trajectory_id = %d ORDER BY enrolled_at DESC",
            $trajectoryId
        ), ARRAY_A);

        return $results ?: [];
    }

    /**
     * Count enrollments for a trajectory.
     */
    public function countByTrajectory(int $trajectoryId): int
    {
        global $wpdb;
        $table = TrajectoryEnrollmentTable::getTableName();

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE trajectory_id = %d AND status IN ('enrolled', 'completed')",
            $trajectoryId
        ));
    }

    /**
     * Create enrollment.
     */
    public function create(array $data): int|WP_Error
    {
        global $wpdb;
        $table = TrajectoryEnrollmentTable::getTableName();

        // Check for existing enrollment
        $existing = $this->findByUserAndTrajectory($data['user_id'], $data['trajectory_id']);
        if ($existing) {
            return new WP_Error('already_enrolled', 'User is already enrolled in this trajectory');
        }

        $insertData = [
            'user_id' => $data['user_id'],
            'trajectory_id' => $data['trajectory_id'],
            'status' => $data['status'] ?? 'enrolled',
            'elective_choices' => isset($data['elective_choices']) ? json_encode($data['elective_choices']) : null,
            'enrolled_at' => $data['enrolled_at'] ?? current_time('mysql'),
            'notes' => $data['notes'] ?? null,
        ];

        $formats = ['%d', '%d', '%s', '%s', '%s', '%s'];

        $result = $wpdb->insert($table, $insertData, $formats);

        if ($result === false) {
            return new WP_Error('db_error', 'Failed to create enrollment');
        }

        return (int) $wpdb->insert_id;
    }

    /**
     * Update enrollment.
     */
    public function update(int $id, array $data): true|WP_Error
    {
        global $wpdb;
        $table = TrajectoryEnrollmentTable::getTableName();

        $updateData = [];
        $formats = [];

        if (isset($data['status'])) {
            $updateData['status'] = $data['status'];
            $formats[] = '%s';
        }

        if (array_key_exists('elective_choices', $data)) {
            $updateData['elective_choices'] = $data['elective_choices'] !== null
                ? json_encode($data['elective_choices'])
                : null;
            $formats[] = '%s';
        }

        if (isset($data['choices_locked_at'])) {
            $updateData['choices_locked_at'] = $data['choices_locked_at'];
            $formats[] = '%s';
        }

        if (isset($data['completed_at'])) {
            $updateData['completed_at'] = $data['completed_at'];
            $formats[] = '%s';
        }

        if (isset($data['cancelled_at'])) {
            $updateData['cancelled_at'] = $data['cancelled_at'];
            $formats[] = '%s';
        }

        if (isset($data['notes'])) {
            $updateData['notes'] = $data['notes'];
            $formats[] = '%s';
        }

        if (empty($updateData)) {
            return true;
        }

        $result = $wpdb->update($table, $updateData, ['id' => $id], $formats, ['%d']);

        if ($result === false) {
            return new WP_Error('db_error', 'Failed to update enrollment');
        }

        return true;
    }

    /**
     * Get elective choices for enrollment.
     *
     * @return array<string, array<int>>
     */
    public function getElectiveChoices(int $enrollmentId): array
    {
        $enrollment = $this->find($enrollmentId);

        if (!$enrollment || empty($enrollment['elective_choices'])) {
            return [];
        }

        $choices = json_decode($enrollment['elective_choices'], true);

        return is_array($choices) ? $choices : [];
    }

    /**
     * Check if user is enrolled in trajectory.
     */
    public function isEnrolled(int $userId, int $trajectoryId): bool
    {
        $enrollment = $this->findByUserAndTrajectory($userId, $trajectoryId);

        return $enrollment !== null && in_array($enrollment['status'], ['enrolled', 'completed'], true);
    }
}
```

**Step 2: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Trajectory/TrajectoryEnrollmentRepository.php
git commit -m "feat(trajectory): add TrajectoryEnrollmentRepository"
```

---

## Task 7: Trajectory Selection Service

**Files:**
- Create: `web/app/mu-plugins/stride-core/Modules/Trajectory/TrajectorySelectionService.php`

**Step 1: Create service class**

```php
<?php

declare(strict_types=1);

namespace Stride\Modules\Trajectory;

use Stride\Infrastructure\AbstractService;
use WP_Error;

/**
 * Trajectory elective selection with deadline enforcement.
 *
 * Handles user picking elective courses within a trajectory.
 */
final class TrajectorySelectionService extends AbstractService
{
    public function __construct(
        private readonly TrajectoryService $trajectories,
        private readonly TrajectoryEnrollmentRepository $enrollments,
        private readonly TrajectoryRepository $trajectoryRepo,
    ) {
        parent::__construct();
    }

    public static function metadata(): array
    {
        return [
            'name' => 'Trajectory Selection Service',
            'description' => 'Handles elective selection with deadlines',
            'priority' => 21,
        ];
    }

    protected function getConfigSlug(): string
    {
        return 'trajectory_selection';
    }

    protected function init(): void
    {
        // Future: lock expired choices
    }

    // === Enrollment Actions ===

    /**
     * Enroll user in trajectory.
     */
    public function enroll(int $userId, int $trajectoryId): int|WP_Error
    {
        // Check trajectory allows enrollment
        if (!$this->trajectories->isEnrollmentOpen($trajectoryId)) {
            return new WP_Error('enrollment_closed', 'Enrollment is not open for this trajectory');
        }

        // Check capacity
        if (!$this->hasCapacity($trajectoryId)) {
            return new WP_Error('no_capacity', 'Trajectory is full');
        }

        // Check not already enrolled
        if ($this->enrollments->isEnrolled($userId, $trajectoryId)) {
            return new WP_Error('already_enrolled', 'Already enrolled in this trajectory');
        }

        // Create enrollment
        $enrollmentId = $this->enrollments->create([
            'user_id' => $userId,
            'trajectory_id' => $trajectoryId,
            'status' => 'enrolled',
        ]);

        if (is_wp_error($enrollmentId)) {
            return $enrollmentId;
        }

        do_action('stride/trajectory/enrolled', [
            'enrollment_id' => $enrollmentId,
            'user_id' => $userId,
            'trajectory_id' => $trajectoryId,
        ]);

        return $enrollmentId;
    }

    /**
     * Check if trajectory has capacity.
     */
    public function hasCapacity(int $trajectoryId): bool
    {
        $trajectory = $this->trajectories->getTrajectory($trajectoryId);

        if (!$trajectory) {
            return false;
        }

        // No capacity limit
        if ($trajectory['capacity'] === 0) {
            return true;
        }

        $enrolled = $this->enrollments->countByTrajectory($trajectoryId);

        return $enrolled < $trajectory['capacity'];
    }

    // === Elective Selection ===

    /**
     * Set elective choices for enrollment.
     *
     * @param array<string, array<int>> $choices Group => [course_ids]
     */
    public function setElectiveChoices(int $enrollmentId, array $choices): true|WP_Error
    {
        $enrollment = $this->enrollments->find($enrollmentId);

        if (!$enrollment) {
            return new WP_Error('enrollment_not_found', 'Enrollment not found');
        }

        $trajectoryId = (int) $enrollment['trajectory_id'];

        // Check choice window is open
        if (!$this->trajectories->isChoiceWindowOpen($trajectoryId)) {
            return new WP_Error('choice_window_closed', 'Choice window is not open');
        }

        // Validate choices meet requirements
        $validation = $this->validateChoices($trajectoryId, $choices);
        if (is_wp_error($validation)) {
            return $validation;
        }

        // Use transaction for atomic update
        global $wpdb;
        $wpdb->query('START TRANSACTION');

        try {
            $result = $this->enrollments->update($enrollmentId, [
                'elective_choices' => $choices,
            ]);

            if (is_wp_error($result)) {
                $wpdb->query('ROLLBACK');
                return $result;
            }

            $wpdb->query('COMMIT');
        } catch (\Exception $e) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('db_error', 'Failed to save choices');
        }

        do_action('stride/trajectory/choices_updated', [
            'enrollment_id' => $enrollmentId,
            'trajectory_id' => $trajectoryId,
            'choices' => $choices,
        ]);

        return true;
    }

    /**
     * Get user's elective choices.
     *
     * @return array<string, array<int>>
     */
    public function getElectiveChoices(int $enrollmentId): array
    {
        return $this->enrollments->getElectiveChoices($enrollmentId);
    }

    /**
     * Lock elective choices for enrollment.
     */
    public function lockChoices(int $enrollmentId): true|WP_Error
    {
        $enrollment = $this->enrollments->find($enrollmentId);

        if (!$enrollment) {
            return new WP_Error('enrollment_not_found', 'Enrollment not found');
        }

        // Already locked
        if (!empty($enrollment['choices_locked_at'])) {
            return true;
        }

        return $this->enrollments->update($enrollmentId, [
            'choices_locked_at' => current_time('mysql'),
        ]);
    }

    /**
     * Check if choices are locked for enrollment.
     */
    public function areChoicesLocked(int $enrollmentId): bool
    {
        $enrollment = $this->enrollments->find($enrollmentId);

        if (!$enrollment) {
            return false;
        }

        // Manually locked
        if (!empty($enrollment['choices_locked_at'])) {
            return true;
        }

        // Deadline passed
        return $this->trajectories->areChoicesLocked((int) $enrollment['trajectory_id']);
    }

    // === Validation ===

    /**
     * Validate elective choices meet trajectory requirements.
     *
     * @param array<string, array<int>> $choices
     */
    public function validateChoices(int $trajectoryId, array $choices): true|WP_Error
    {
        $electiveGroups = $this->trajectoryRepo->getElectiveGroups($trajectoryId);

        foreach ($electiveGroups as $groupName => $courses) {
            $courseIds = array_column($courses, 'course_id');
            $pickCount = $courses[0]['pick_count'] ?? 1;

            $chosenForGroup = $choices[$groupName] ?? [];

            // Validate chosen courses belong to this group
            $validChoices = array_intersect($chosenForGroup, $courseIds);

            if (count($validChoices) < $pickCount) {
                return new WP_Error(
                    'incomplete_choices',
                    sprintf('Group "%s" requires %d selection(s), got %d', $groupName, $pickCount, count($validChoices))
                );
            }

            if (count($validChoices) > $pickCount) {
                return new WP_Error(
                    'too_many_choices',
                    sprintf('Group "%s" allows %d selection(s), got %d', $groupName, $pickCount, count($validChoices))
                );
            }
        }

        return true;
    }

    // === Queries ===

    /**
     * Get enrollment with details.
     *
     * @return array<string, mixed>|null
     */
    public function getEnrollment(int $enrollmentId): ?array
    {
        $enrollment = $this->enrollments->find($enrollmentId);

        if (!$enrollment) {
            return null;
        }

        $trajectory = $this->trajectories->getTrajectory((int) $enrollment['trajectory_id']);

        return [
            'id' => (int) $enrollment['id'],
            'user_id' => (int) $enrollment['user_id'],
            'trajectory_id' => (int) $enrollment['trajectory_id'],
            'trajectory' => $trajectory,
            'status' => $enrollment['status'],
            'elective_choices' => $this->enrollments->getElectiveChoices((int) $enrollment['id']),
            'choices_locked' => $this->areChoicesLocked((int) $enrollment['id']),
            'enrolled_at' => $enrollment['enrolled_at'],
            'completed_at' => $enrollment['completed_at'],
        ];
    }

    /**
     * Get user's trajectory enrollments.
     *
     * @return array<array<string, mixed>>
     */
    public function getUserEnrollments(int $userId): array
    {
        $enrollments = $this->enrollments->findActiveByUser($userId);

        return array_map(function ($e) {
            return $this->getEnrollment((int) $e['id']);
        }, $enrollments);
    }

    /**
     * Get days until choice deadline.
     */
    public function getDaysUntilChoiceDeadline(int $trajectoryId): ?int
    {
        $deadline = $this->trajectoryRepo->getField($trajectoryId, 'choice_deadline');

        if (empty($deadline)) {
            return null;
        }

        $diff = strtotime($deadline) - time();

        return (int) floor($diff / DAY_IN_SECONDS);
    }
}
```

**Step 2: Register service in plugin-config.php**

Add to the services array:

```php
\Stride\Modules\Trajectory\TrajectorySelectionService::class,
```

**Step 3: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Trajectory/TrajectorySelectionService.php
git add web/app/mu-plugins/stride-core/plugin-config.php
git commit -m "feat(trajectory): add TrajectorySelectionService with deadlines"
```

---

## Task 8: Test Script

**Files:**
- Create: `scripts/test-trajectory-enrollment.php`

**Step 1: Create test script**

```php
<?php
/**
 * Stride V1 - Trajectory Enrollment Tests
 *
 * Tests trajectory CRUD, enrollment, and elective selection.
 *
 * Run with: ddev exec wp eval-file scripts/test-trajectory-enrollment.php
 */

if (!defined('ABSPATH')) {
    echo "Run via WP-CLI: ddev exec wp eval-file scripts/test-trajectory-enrollment.php\n";
    exit(1);
}

use Stride\Modules\Trajectory\TrajectoryService;
use Stride\Modules\Trajectory\TrajectoryRepository;
use Stride\Modules\Trajectory\TrajectorySelectionService;
use Stride\Modules\Trajectory\TrajectoryEnrollmentRepository;
use Stride\Domain\TrajectoryMode;
use Stride\Domain\TrajectoryStatus;

echo "=== Stride V1 - Trajectory Enrollment Tests ===" . PHP_EOL . PHP_EOL;

$trajectoryService = ntdst_get(TrajectoryService::class);
$trajectoryRepo = ntdst_get(TrajectoryRepository::class);
$selectionService = ntdst_get(TrajectorySelectionService::class);
$enrollmentRepo = ntdst_get(TrajectoryEnrollmentRepository::class);

$created = ['trajectories' => [], 'users' => [], 'enrollments' => []];
$passed = 0;
$failed = 0;

function assert_test(bool $condition, string $message): void {
    global $passed, $failed;
    if ($condition) {
        echo "  [PASS] {$message}" . PHP_EOL;
        $passed++;
    } else {
        echo "  [FAIL] {$message}" . PHP_EOL;
        $failed++;
    }
}

wp_set_current_user(1);

try {
    // === A. TRAJECTORY CRUD ===
    echo "A. Trajectory CRUD..." . PHP_EOL;

    // A1. Create trajectory
    $trajectoryId = $trajectoryService->createTrajectory([
        'post_title' => 'Test Trajectory',
        'mode' => TrajectoryMode::Cohort->value,
        'status' => TrajectoryStatus::Open->value,
        'capacity' => 20,
        'enrollment_deadline' => date('Y-m-d', strtotime('+30 days')),
        'choice_available_date' => date('Y-m-d', strtotime('+1 day')),
        'choice_deadline' => date('Y-m-d', strtotime('+14 days')),
        'courses' => [
            ['course_id' => 101, 'group' => 'Basis', 'required' => true],
            ['course_id' => 102, 'group' => 'Basis', 'required' => true],
            ['course_id' => 201, 'group' => 'Keuze', 'required' => false, 'pick_count' => 1],
            ['course_id' => 202, 'group' => 'Keuze', 'required' => false, 'pick_count' => 1],
            ['course_id' => 203, 'group' => 'Keuze', 'required' => false, 'pick_count' => 1],
        ],
    ]);
    assert_test(!is_wp_error($trajectoryId), 'A1. Create trajectory');
    $created['trajectories'][] = $trajectoryId;

    // A2. Get trajectory
    $trajectory = $trajectoryService->getTrajectory($trajectoryId);
    assert_test($trajectory !== null && $trajectory['title'] === 'Test Trajectory', 'A2. Get trajectory');

    // A3. Check mode
    assert_test($trajectory['mode_enum'] === TrajectoryMode::Cohort, 'A3. Mode is cohort');

    // A4. Check status
    assert_test($trajectory['status_enum'] === TrajectoryStatus::Open, 'A4. Status is open');

    // A5. Get courses
    $courses = $trajectoryService->getCourses($trajectoryId);
    assert_test(count($courses) === 5, 'A5. Has 5 courses');

    // A6. Get required courses
    $required = $trajectoryService->getRequiredCourses($trajectoryId);
    assert_test(count($required) === 2, 'A6. Has 2 required courses');

    // A7. Get elective groups
    $groups = $trajectoryRepo->getElectiveGroups($trajectoryId);
    assert_test(isset($groups['Keuze']) && count($groups['Keuze']) === 3, 'A7. Has Keuze group with 3 electives');

    echo PHP_EOL;

    // === B. ENROLLMENT ===
    echo "B. Enrollment..." . PHP_EOL;

    // Create test user
    $userId = wp_create_user('traj_test_' . time(), 'pass123', 'traj@test.local');
    $created['users'][] = $userId;

    // B1. Enrollment is open
    $isOpen = $trajectoryService->isEnrollmentOpen($trajectoryId);
    assert_test($isOpen, 'B1. Enrollment is open');

    // B2. Has capacity
    $hasCapacity = $selectionService->hasCapacity($trajectoryId);
    assert_test($hasCapacity, 'B2. Has capacity');

    // B3. Enroll user
    $enrollmentId = $selectionService->enroll($userId, $trajectoryId);
    assert_test(!is_wp_error($enrollmentId), 'B3. Enroll user succeeds');
    $created['enrollments'][] = $enrollmentId;

    // B4. Is enrolled
    $isEnrolled = $enrollmentRepo->isEnrolled($userId, $trajectoryId);
    assert_test($isEnrolled, 'B4. User is enrolled');

    // B5. Double enrollment fails
    $result = $selectionService->enroll($userId, $trajectoryId);
    assert_test(is_wp_error($result) && $result->get_error_code() === 'already_enrolled', 'B5. Double enrollment rejected');

    // B6. Get enrollment
    $enrollment = $selectionService->getEnrollment($enrollmentId);
    assert_test($enrollment !== null && $enrollment['user_id'] === $userId, 'B6. Get enrollment');

    echo PHP_EOL;

    // === C. ELECTIVE SELECTION ===
    echo "C. Elective Selection..." . PHP_EOL;

    // Make choice window open by setting dates
    $trajectoryService->updateTrajectory($trajectoryId, [
        'choice_available_date' => date('Y-m-d', strtotime('-1 day')),
        'choice_deadline' => date('Y-m-d', strtotime('+14 days')),
    ]);

    // C1. Choice window is open
    $isWindowOpen = $trajectoryService->isChoiceWindowOpen($trajectoryId);
    assert_test($isWindowOpen, 'C1. Choice window is open');

    // C2. Set valid choices
    $result = $selectionService->setElectiveChoices($enrollmentId, [
        'Keuze' => [201],
    ]);
    assert_test($result === true, 'C2. Set valid choices succeeds');

    // C3. Get choices
    $choices = $selectionService->getElectiveChoices($enrollmentId);
    assert_test(isset($choices['Keuze']) && in_array(201, $choices['Keuze']), 'C3. Get choices');

    // C4. Update choices
    $result = $selectionService->setElectiveChoices($enrollmentId, [
        'Keuze' => [202],
    ]);
    assert_test($result === true, 'C4. Update choices succeeds');

    // C5. Verify updated
    $choices = $selectionService->getElectiveChoices($enrollmentId);
    assert_test(in_array(202, $choices['Keuze']), 'C5. Choices updated');

    // C6. Too few choices fails
    $result = $selectionService->setElectiveChoices($enrollmentId, [
        'Keuze' => [],
    ]);
    assert_test(is_wp_error($result) && $result->get_error_code() === 'incomplete_choices', 'C6. Too few choices rejected');

    // C7. Too many choices fails
    $result = $selectionService->setElectiveChoices($enrollmentId, [
        'Keuze' => [201, 202],
    ]);
    assert_test(is_wp_error($result) && $result->get_error_code() === 'too_many_choices', 'C7. Too many choices rejected');

    echo PHP_EOL;

    // === D. DEADLINE ENFORCEMENT ===
    echo "D. Deadline Enforcement..." . PHP_EOL;

    // D1. Days until deadline
    $days = $selectionService->getDaysUntilChoiceDeadline($trajectoryId);
    assert_test($days >= 13 && $days <= 14, 'D1. Days until deadline correct');

    // D2. Choices not locked
    $isLocked = $selectionService->areChoicesLocked($enrollmentId);
    assert_test(!$isLocked, 'D2. Choices not locked');

    // D3. Set past deadline
    $trajectoryService->updateTrajectory($trajectoryId, [
        'choice_deadline' => date('Y-m-d', strtotime('-1 day')),
    ]);
    $isLocked = $trajectoryService->areChoicesLocked($trajectoryId);
    assert_test($isLocked, 'D3. Choices locked after deadline');

    // D4. Selection blocked after deadline
    $result = $selectionService->setElectiveChoices($enrollmentId, [
        'Keuze' => [203],
    ]);
    assert_test(is_wp_error($result) && $result->get_error_code() === 'choice_window_closed', 'D4. Selection blocked after deadline');

    echo PHP_EOL;

    // === E. CAPACITY ===
    echo "E. Capacity..." . PHP_EOL;

    // Create capacity-limited trajectory
    $limitedId = $trajectoryService->createTrajectory([
        'post_title' => 'Limited Trajectory',
        'mode' => TrajectoryMode::Cohort->value,
        'status' => TrajectoryStatus::Open->value,
        'capacity' => 1,
        'courses' => [
            ['course_id' => 101, 'group' => 'Basis', 'required' => true],
        ],
    ]);
    $created['trajectories'][] = $limitedId;

    // E1. Has capacity initially
    $hasCapacity = $selectionService->hasCapacity($limitedId);
    assert_test($hasCapacity, 'E1. Has capacity initially');

    // E2. Enroll fills capacity
    $enrollmentId2 = $selectionService->enroll($userId, $limitedId);
    $created['enrollments'][] = $enrollmentId2;
    $hasCapacity = $selectionService->hasCapacity($limitedId);
    assert_test(!$hasCapacity, 'E2. No capacity after enrollment');

    // E3. Second user blocked
    $userId2 = wp_create_user('traj_test2_' . time(), 'pass123', 'traj2@test.local');
    $created['users'][] = $userId2;

    $result = $selectionService->enroll($userId2, $limitedId);
    assert_test(is_wp_error($result) && $result->get_error_code() === 'no_capacity', 'E3. Full trajectory rejects enrollment');

    echo PHP_EOL;

} catch (Exception $e) {
    echo "[FATAL] " . $e->getMessage() . PHP_EOL;
    echo $e->getTraceAsString() . PHP_EOL;
}

// Cleanup
echo "Cleaning up..." . PHP_EOL;

foreach ($created['enrollments'] as $id) {
    global $wpdb;
    $wpdb->delete($wpdb->prefix . 'vad_trajectory_enrollments', ['id' => $id]);
}
foreach ($created['trajectories'] as $id) {
    wp_delete_post($id, true);
}
require_once ABSPATH . 'wp-admin/includes/user.php';
foreach ($created['users'] as $id) {
    wp_delete_user($id);
}

echo PHP_EOL . "=== Results ===" . PHP_EOL;
echo "Passed: {$passed}" . PHP_EOL;
echo "Failed: {$failed}" . PHP_EOL;
echo ($failed === 0 ? "ALL TESTS PASSED!" : "SOME TESTS FAILED") . PHP_EOL;
```

**Step 2: Run tests**

Run: `ddev exec wp eval-file scripts/test-trajectory-enrollment.php`

Expected: All tests pass

**Step 3: Commit**

```bash
git add scripts/test-trajectory-enrollment.php
git commit -m "test(trajectory): add trajectory enrollment test script"
```

---

## Summary

| Task | Description | Files |
|------|-------------|-------|
| 1 | Domain Enums | `TrajectoryMode.php`, `TrajectoryStatus.php` |
| 2 | Trajectory CPT | `TrajectoryCPT.php`, stride-core.php |
| 3 | Trajectory Repository | `TrajectoryRepository.php`, stride-core.php |
| 4 | Trajectory Service | `TrajectoryService.php`, plugin-config.php |
| 5 | Enrollment Table | `TrajectoryEnrollmentTable.php`, stride-core.php |
| 6 | Enrollment Repository | `TrajectoryEnrollmentRepository.php` |
| 7 | Selection Service | `TrajectorySelectionService.php`, plugin-config.php |
| 8 | Test Script | `test-trajectory-enrollment.php` |

**Total: ~8 commits, ~900 lines**

---

## References

- Architecture: `docs/plans/2026-02-16-stride-v1-architecture-design.md`
- Existing patterns: `Modules/Edition/SessionSelectionService.php`, `Modules/Enrollment/RegistrationTable.php`
- Domain enums: `Domain/EditionStatus.php`, `Domain/SessionType.php`
