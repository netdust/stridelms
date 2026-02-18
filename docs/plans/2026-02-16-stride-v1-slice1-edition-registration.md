# Stride LMS V1 - Slice 1: Edition & Registration

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Complete vertical slice from viewing editions to registering to seeing enrollment in student dashboard.

**Architecture:** EditionRepository uses DataManager CPT for editions. RegistrationRepository uses custom table for high-volume registrations. EditionService implements EditionQueryInterface for cross-module queries. EnrollmentService orchestrates registration creation and LMS access.

**Tech Stack:** PHP 8.3, ntdst-core (DataManager ORM, DI container), WordPress mu-plugin, custom SQL table

**Reference:** `@ntdst-wp-dev` for all PHP code patterns

---

## Prerequisites

- [x] Phase 0 complete (Contracts, Domain, Infrastructure in place)
- [ ] ntdst-core mu-plugin loaded and working
- [ ] LearnDash installed (for course access)

---

## Task 1: Register vad_edition CPT

**Files:**
- Create: `web/app/mu-plugins/stride-core/Modules/Edition/EditionCPT.php`
- Modify: `web/app/mu-plugins/stride-core/plugin-config.php`

**Step 1: Create CPT registration class**

```php
<?php

declare(strict_types=1);

namespace Stride\Modules\Edition;

/**
 * Edition CPT Registration.
 *
 * Scheduled course offerings with dates, capacity, pricing.
 */
final class EditionCPT
{
    public const POST_TYPE = 'vad_edition';

    public static function register(): void
    {
        ntdst_data()->register(self::POST_TYPE, [
            'label' => 'Edities',
            'labels' => [
                'name' => 'Edities',
                'singular_name' => 'Editie',
                'add_new' => 'Nieuwe editie',
                'add_new_item' => 'Nieuwe editie toevoegen',
                'edit_item' => 'Editie bewerken',
            ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => true,
            'menu_icon' => 'dashicons-calendar-alt',
            'supports' => ['title'],
            'fields' => self::getFields(),
            'field_groups' => self::getFieldGroups(),
        ]);
    }

    private static function getFields(): array
    {
        return [
            'course_id' => [
                'type' => 'int',
                'label' => 'Cursus',
                'required' => true,
            ],
            'start_date' => [
                'type' => 'text',
                'label' => 'Startdatum',
                'required' => true,
            ],
            'end_date' => [
                'type' => 'text',
                'label' => 'Einddatum',
            ],
            'capacity' => [
                'type' => 'int',
                'label' => 'Capaciteit',
                'required' => true,
            ],
            'price' => [
                'type' => 'float',
                'label' => 'Prijs (leden)',
            ],
            'price_non_member' => [
                'type' => 'float',
                'label' => 'Prijs (niet-leden)',
            ],
            'venue' => [
                'type' => 'text',
                'label' => 'Locatie',
            ],
            'status' => [
                'type' => 'text',
                'label' => 'Status',
            ],
            'speakers' => [
                'type' => 'text',
                'label' => 'Sprekers',
            ],
        ];
    }

    private static function getFieldGroups(): array
    {
        return [
            'edition_details' => [
                'title' => 'Editie Details',
                'fields' => ['course_id', 'start_date', 'end_date', 'capacity', 'venue', 'status'],
            ],
            'edition_pricing' => [
                'title' => 'Prijzen',
                'fields' => ['price', 'price_non_member'],
            ],
            'edition_info' => [
                'title' => 'Extra Info',
                'fields' => ['speakers'],
            ],
        ];
    }
}
```

**Step 2: Add to plugin-config.php**

Add `EditionCPT::class` to services array and call `register()` on init:

```php
// In plugin-config.php 'services' array:
\Stride\Modules\Edition\EditionCPT::class,
```

**Step 3: Update bootstrap to register CPT**

```php
// In stride-core.php, add after DI bindings:
add_action('init', [\Stride\Modules\Edition\EditionCPT::class, 'register'], 5);
```

**Step 4: Verify CPT registered**

```bash
ddev exec wp post-type list | grep vad_edition
```

Expected: `vad_edition` appears in list

**Step 5: Commit**

```bash
git add web/app/mu-plugins/stride-core/
git commit -m "feat(edition): register vad_edition CPT with DataManager"
```

---

## Task 2: Create EditionRepository

**Files:**
- Create: `web/app/mu-plugins/stride-core/Modules/Edition/EditionRepository.php`

**Step 1: Write repository extending AbstractRepository**

```php
<?php

declare(strict_types=1);

namespace Stride\Modules\Edition;

use Stride\Domain\EditionStatus;
use Stride\Infrastructure\AbstractRepository;
use WP_Post;

/**
 * Repository for edition data access.
 */
final class EditionRepository extends AbstractRepository
{
    protected string $postType = EditionCPT::POST_TYPE;

    /**
     * Get editions for a specific course.
     *
     * @return array<array<string, mixed>>
     */
    public function findByCourse(int $courseId): array
    {
        return $this->model()
            ->where('course_id', $courseId)
            ->where('post_status', 'publish')
            ->orderBy('start_date', 'ASC')
            ->withMeta()
            ->get();
    }

    /**
     * Get upcoming editions (start date >= today).
     *
     * @return array<array<string, mixed>>
     */
    public function findUpcoming(int $limit = 10): array
    {
        $today = date('Y-m-d');

        return $this->model()
            ->where('start_date', ['>=', $today])
            ->where('post_status', 'publish')
            ->whereNot('status', EditionStatus::Cancelled->value)
            ->orderBy('start_date', 'ASC')
            ->limit($limit)
            ->withMeta()
            ->get();
    }

    /**
     * Get editions with available spots.
     *
     * @return array<array<string, mixed>>
     */
    public function findWithAvailability(): array
    {
        return $this->model()
            ->where('status', EditionStatus::Open->value)
            ->where('post_status', 'publish')
            ->orderBy('start_date', 'ASC')
            ->withMeta()
            ->get();
    }

    /**
     * Get field value from edition.
     */
    public function getField(int $editionId, string $field, mixed $default = null): mixed
    {
        return $this->model()->getMeta($editionId, $field, $default);
    }

    /**
     * Update edition status.
     */
    public function updateStatus(int $editionId, EditionStatus $status): void
    {
        $this->model()->updateMeta($editionId, 'status', $status->value);
    }
}
```

**Step 2: Verify syntax**

```bash
php -l web/app/mu-plugins/stride-core/Modules/Edition/EditionRepository.php
```

**Step 3: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Edition/EditionRepository.php
git commit -m "feat(edition): add EditionRepository with course and availability queries"
```

---

## Task 3: Create EditionService implementing EditionQueryInterface

**Files:**
- Create: `web/app/mu-plugins/stride-core/Modules/Edition/EditionService.php`

**Step 1: Write service implementing the interface**

```php
<?php

declare(strict_types=1);

namespace Stride\Modules\Edition;

use Stride\Contracts\EditionQueryInterface;
use Stride\Domain\EditionStatus;
use Stride\Domain\Money;
use Stride\Infrastructure\AbstractService;
use WP_Post;
use WP_Error;

/**
 * Edition business logic.
 *
 * Implements EditionQueryInterface for cross-module queries.
 */
final class EditionService extends AbstractService implements EditionQueryInterface
{
    public function __construct(
        private readonly EditionRepository $repository,
    ) {
        parent::__construct();
    }

    public static function metadata(): array
    {
        return [
            'name' => 'Edition Service',
            'description' => 'Manages scheduled course offerings',
            'priority' => 10,
        ];
    }

    protected function getConfigSlug(): string
    {
        return 'edition';
    }

    protected function init(): void
    {
        // Register hooks for capacity updates
        add_action('stride/registration/created', [$this, 'onRegistrationCreated']);
        add_action('stride/registration/cancelled', [$this, 'onRegistrationCancelled']);
    }

    // === EditionQueryInterface Implementation ===

    public function hasAvailableSpots(int $editionId): bool
    {
        $capacity = $this->getCapacity($editionId);
        $registered = $this->getRegisteredCount($editionId);

        return $registered < $capacity;
    }

    public function getRegisteredCount(int $editionId): int
    {
        global $wpdb;

        $table = $wpdb->prefix . 'vad_registrations';

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE edition_id = %d AND status = 'confirmed'",
            $editionId
        ));
    }

    public function getCapacity(int $editionId): int
    {
        return (int) $this->repository->getField($editionId, 'capacity', 0);
    }

    public function getStatus(int $editionId): EditionStatus
    {
        $status = $this->repository->getField($editionId, 'status', 'open');

        return EditionStatus::tryFrom($status) ?? EditionStatus::Open;
    }

    public function getCourseId(int $editionId): ?int
    {
        $courseId = $this->repository->getField($editionId, 'course_id');

        return $courseId ? (int) $courseId : null;
    }

    public function exists(int $editionId): bool
    {
        $result = $this->repository->find($editionId);

        return !is_wp_error($result);
    }

    // === Public API ===

    /**
     * Get edition by ID.
     */
    public function getEdition(int $editionId): WP_Post|WP_Error
    {
        return $this->repository->find($editionId);
    }

    /**
     * Get editions for a course.
     *
     * @return array<array<string, mixed>>
     */
    public function getEditionsForCourse(int $courseId): array
    {
        return $this->repository->findByCourse($courseId);
    }

    /**
     * Get upcoming editions.
     *
     * @return array<array<string, mixed>>
     */
    public function getUpcomingEditions(int $limit = 10): array
    {
        return $this->repository->findUpcoming($limit);
    }

    /**
     * Get price for edition.
     */
    public function getPrice(int $editionId, bool $isMember = true): Money
    {
        $field = $isMember ? 'price' : 'price_non_member';
        $amount = (float) $this->repository->getField($editionId, $field, 0);

        return Money::eur($amount);
    }

    /**
     * Check if enrollment is allowed.
     */
    public function canEnroll(int $editionId): bool
    {
        $status = $this->getStatus($editionId);

        if (!$status->allowsEnrollment()) {
            return false;
        }

        return $this->hasAvailableSpots($editionId);
    }

    // === Event Handlers ===

    public function onRegistrationCreated(array $data): void
    {
        $editionId = $data['edition_id'] ?? 0;

        if ($editionId && !$this->hasAvailableSpots($editionId)) {
            $this->repository->updateStatus($editionId, EditionStatus::Full);
        }
    }

    public function onRegistrationCancelled(array $data): void
    {
        $editionId = $data['edition_id'] ?? 0;
        $currentStatus = $this->getStatus($editionId);

        if ($editionId && $currentStatus === EditionStatus::Full) {
            if ($this->hasAvailableSpots($editionId)) {
                $this->repository->updateStatus($editionId, EditionStatus::Open);
            }
        }
    }
}
```

**Step 2: Verify syntax**

```bash
php -l web/app/mu-plugins/stride-core/Modules/Edition/EditionService.php
```

**Step 3: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Edition/EditionService.php
git commit -m "feat(edition): add EditionService implementing EditionQueryInterface"
```

---

## Task 4: Create Registration Table Migration

**Files:**
- Create: `web/app/mu-plugins/stride-core/Modules/Enrollment/RegistrationTable.php`

**Step 1: Write table migration class**

```php
<?php

declare(strict_types=1);

namespace Stride\Modules\Enrollment;

/**
 * Registration table creation and migration.
 */
final class RegistrationTable
{
    public const TABLE_NAME = 'vad_registrations';

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
            edition_id BIGINT UNSIGNED NOT NULL,
            status ENUM('confirmed','cancelled','waitlist','interest') DEFAULT 'confirmed',
            enrollment_path ENUM('individual','colleague','trajectory','interest') DEFAULT 'individual',
            enrolled_by BIGINT UNSIGNED NULL,
            voucher_code VARCHAR(50) NULL,
            quote_id BIGINT UNSIGNED NULL,
            registered_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            cancelled_at DATETIME NULL,
            notes TEXT NULL,
            INDEX idx_user (user_id),
            INDEX idx_edition (edition_id),
            INDEX idx_status (status),
            UNIQUE KEY unique_user_edition (user_id, edition_id)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    public static function exists(): bool
    {
        global $wpdb;

        $table = self::getTableName();

        return $wpdb->get_var("SHOW TABLES LIKE '{$table}'") === $table;
    }
}
```

**Step 2: Verify syntax**

```bash
php -l web/app/mu-plugins/stride-core/Modules/Enrollment/RegistrationTable.php
```

**Step 3: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Enrollment/RegistrationTable.php
git commit -m "feat(enrollment): add RegistrationTable migration class"
```

---

## Task 5: Create RegistrationRepository

**Files:**
- Create: `web/app/mu-plugins/stride-core/Modules/Enrollment/RegistrationRepository.php`

**Step 1: Write repository for custom table**

```php
<?php

declare(strict_types=1);

namespace Stride\Modules\Enrollment;

use Stride\Domain\RegistrationStatus;
use WP_Error;

/**
 * Repository for registration data access.
 *
 * Uses custom table instead of CPT for performance.
 */
final class RegistrationRepository
{
    public const PATH_INDIVIDUAL = 'individual';
    public const PATH_COLLEAGUE = 'colleague';
    public const PATH_TRAJECTORY = 'trajectory';
    public const PATH_INTEREST = 'interest';

    private function table(): string
    {
        return RegistrationTable::getTableName();
    }

    /**
     * Create a new registration.
     *
     * @param array<string, mixed> $data
     * @return int|WP_Error Registration ID or error
     */
    public function create(array $data): int|WP_Error
    {
        global $wpdb;

        $required = ['user_id', 'edition_id'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                return new WP_Error('missing_field', "Required field: {$field}");
            }
        }

        // Check for duplicate
        if ($this->exists((int) $data['user_id'], (int) $data['edition_id'])) {
            return new WP_Error('duplicate', 'User already registered for this edition');
        }

        $insert = [
            'user_id' => absint($data['user_id']),
            'edition_id' => absint($data['edition_id']),
            'status' => $data['status'] ?? RegistrationStatus::Confirmed->value,
            'enrollment_path' => $data['enrollment_path'] ?? self::PATH_INDIVIDUAL,
            'enrolled_by' => isset($data['enrolled_by']) ? absint($data['enrolled_by']) : null,
            'voucher_code' => isset($data['voucher_code']) ? sanitize_text_field($data['voucher_code']) : null,
            'quote_id' => isset($data['quote_id']) ? absint($data['quote_id']) : null,
            'notes' => isset($data['notes']) ? sanitize_textarea_field($data['notes']) : null,
        ];

        $result = $wpdb->insert($this->table(), $insert);

        if ($result === false) {
            return new WP_Error('db_error', 'Failed to create registration');
        }

        return (int) $wpdb->insert_id;
    }

    /**
     * Find registration by ID.
     *
     * @return object|WP_Error
     */
    public function find(int $id): object|WP_Error
    {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table()} WHERE id = %d",
            $id
        ));

        if (!$row) {
            return new WP_Error('not_found', 'Registration not found');
        }

        return $row;
    }

    /**
     * Find registration by user and edition.
     */
    public function findByUserAndEdition(int $userId, int $editionId): ?object
    {
        global $wpdb;

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table()} WHERE user_id = %d AND edition_id = %d",
            $userId,
            $editionId
        ));
    }

    /**
     * Check if registration exists.
     */
    public function exists(int $userId, int $editionId): bool
    {
        return $this->findByUserAndEdition($userId, $editionId) !== null;
    }

    /**
     * Get all registrations for a user.
     *
     * @return array<object>
     */
    public function findByUser(int $userId, ?string $status = null): array
    {
        global $wpdb;

        $sql = "SELECT * FROM {$this->table()} WHERE user_id = %d";
        $params = [$userId];

        if ($status !== null) {
            $sql .= " AND status = %s";
            $params[] = $status;
        }

        $sql .= " ORDER BY registered_at DESC";

        return $wpdb->get_results($wpdb->prepare($sql, ...$params));
    }

    /**
     * Get all registrations for an edition.
     *
     * @return array<object>
     */
    public function findByEdition(int $editionId, ?string $status = null): array
    {
        global $wpdb;

        $sql = "SELECT * FROM {$this->table()} WHERE edition_id = %d";
        $params = [$editionId];

        if ($status !== null) {
            $sql .= " AND status = %s";
            $params[] = $status;
        }

        $sql .= " ORDER BY registered_at ASC";

        return $wpdb->get_results($wpdb->prepare($sql, ...$params));
    }

    /**
     * Count confirmed registrations for edition.
     */
    public function countConfirmed(int $editionId): int
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table()} WHERE edition_id = %d AND status = 'confirmed'",
            $editionId
        ));
    }

    /**
     * Update registration status.
     */
    public function updateStatus(int $id, RegistrationStatus $status): bool
    {
        global $wpdb;

        $data = ['status' => $status->value];

        if ($status === RegistrationStatus::Cancelled) {
            $data['cancelled_at'] = current_time('mysql');
        }

        return $wpdb->update($this->table(), $data, ['id' => $id]) !== false;
    }

    /**
     * Cancel a registration.
     */
    public function cancel(int $id): bool
    {
        return $this->updateStatus($id, RegistrationStatus::Cancelled);
    }
}
```

**Step 2: Verify syntax**

```bash
php -l web/app/mu-plugins/stride-core/Modules/Enrollment/RegistrationRepository.php
```

**Step 3: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Enrollment/RegistrationRepository.php
git commit -m "feat(enrollment): add RegistrationRepository for custom table"
```

---

## Task 6: Create EnrollmentService

**Files:**
- Create: `web/app/mu-plugins/stride-core/Modules/Enrollment/EnrollmentService.php`

**Step 1: Write enrollment orchestration service**

```php
<?php

declare(strict_types=1);

namespace Stride\Modules\Enrollment;

use Stride\Contracts\EditionQueryInterface;
use Stride\Contracts\LMSAdapterInterface;
use Stride\Domain\RegistrationStatus;
use Stride\Infrastructure\AbstractService;
use WP_Error;

/**
 * Enrollment orchestration service.
 *
 * Handles registration creation and LMS access management.
 */
final class EnrollmentService extends AbstractService
{
    public function __construct(
        private readonly RegistrationRepository $registrations,
        private readonly EditionQueryInterface $editions,
        private readonly LMSAdapterInterface $lms,
    ) {
        parent::__construct();
    }

    public static function metadata(): array
    {
        return [
            'name' => 'Enrollment Service',
            'description' => 'Handles user enrollment in editions',
            'priority' => 15,
        ];
    }

    protected function getConfigSlug(): string
    {
        return 'enrollment';
    }

    protected function init(): void
    {
        // No hooks needed yet
    }

    /**
     * Enroll user in an edition.
     *
     * @param array<string, mixed> $options Additional options (voucher_code, enrolled_by, notes)
     * @return int|WP_Error Registration ID or error
     */
    public function enroll(int $userId, int $editionId, array $options = []): int|WP_Error
    {
        // Validate edition exists
        if (!$this->editions->exists($editionId)) {
            return new WP_Error('invalid_edition', 'Edition does not exist');
        }

        // Check enrollment allowed
        $status = $this->editions->getStatus($editionId);
        if (!$status->allowsEnrollment()) {
            return new WP_Error('enrollment_closed', 'Enrollment is not open for this edition');
        }

        // Check capacity
        if (!$this->editions->hasAvailableSpots($editionId)) {
            return new WP_Error('edition_full', 'This edition is full');
        }

        // Check not already enrolled
        if ($this->isEnrolled($userId, $editionId)) {
            return new WP_Error('already_enrolled', 'User is already enrolled in this edition');
        }

        // Create registration
        $registrationId = $this->registrations->create([
            'user_id' => $userId,
            'edition_id' => $editionId,
            'status' => RegistrationStatus::Confirmed->value,
            'enrollment_path' => $options['enrollment_path'] ?? RegistrationRepository::PATH_INDIVIDUAL,
            'enrolled_by' => $options['enrolled_by'] ?? null,
            'voucher_code' => $options['voucher_code'] ?? null,
            'notes' => $options['notes'] ?? null,
        ]);

        if (is_wp_error($registrationId)) {
            return $registrationId;
        }

        // Grant LMS access
        $courseId = $this->editions->getCourseId($editionId);
        if ($courseId) {
            $this->lms->grantAccess($userId, $courseId);
        }

        // Fire event
        $this->dispatch('registration/created', [
            'registration_id' => $registrationId,
            'user_id' => $userId,
            'edition_id' => $editionId,
        ]);

        return $registrationId;
    }

    /**
     * Cancel enrollment.
     */
    public function cancel(int $registrationId): bool|WP_Error
    {
        $registration = $this->registrations->find($registrationId);

        if (is_wp_error($registration)) {
            return $registration;
        }

        // Update status
        $result = $this->registrations->cancel($registrationId);

        if (!$result) {
            return new WP_Error('cancel_failed', 'Failed to cancel registration');
        }

        // Revoke LMS access
        $courseId = $this->editions->getCourseId((int) $registration->edition_id);
        if ($courseId) {
            $this->lms->revokeAccess((int) $registration->user_id, $courseId);
        }

        // Fire event
        $this->dispatch('registration/cancelled', [
            'registration_id' => $registrationId,
            'user_id' => (int) $registration->user_id,
            'edition_id' => (int) $registration->edition_id,
        ]);

        return true;
    }

    /**
     * Check if user is enrolled in edition.
     */
    public function isEnrolled(int $userId, int $editionId): bool
    {
        $registration = $this->registrations->findByUserAndEdition($userId, $editionId);

        if (!$registration) {
            return false;
        }

        return $registration->status === RegistrationStatus::Confirmed->value;
    }

    /**
     * Get user's enrollments.
     *
     * @return array<object>
     */
    public function getUserEnrollments(int $userId): array
    {
        return $this->registrations->findByUser($userId, RegistrationStatus::Confirmed->value);
    }

    /**
     * Get registration by ID.
     */
    public function getRegistration(int $registrationId): object|WP_Error
    {
        return $this->registrations->find($registrationId);
    }
}
```

**Step 2: Verify syntax**

```bash
php -l web/app/mu-plugins/stride-core/Modules/Enrollment/EnrollmentService.php
```

**Step 3: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Enrollment/EnrollmentService.php
git commit -m "feat(enrollment): add EnrollmentService orchestrating registration and LMS access"
```

---

## Task 7: Update Plugin Config and Bootstrap

**Files:**
- Modify: `web/app/mu-plugins/stride-core/plugin-config.php`
- Modify: `web/app/mu-plugins/stride-core/stride-core.php`

**Step 1: Update plugin-config.php**

```php
<?php

declare(strict_types=1);

/**
 * Stride Core Plugin Configuration
 *
 * Service registration and DI bindings.
 */

use Stride\Adapters\LearnDashAdapter;
use Stride\Contracts\EditionQueryInterface;
use Stride\Contracts\LMSAdapterInterface;
use Stride\Modules\Edition\EditionService;

return [
    /**
     * DI Container Bindings
     *
     * Interface => Implementation mappings
     */
    'bindings' => [
        LMSAdapterInterface::class => LearnDashAdapter::class,
        EditionQueryInterface::class => EditionService::class,
    ],

    /**
     * Services to auto-register
     *
     * Services are loaded in order, by priority from metadata().
     */
    'services' => [
        \Stride\Modules\Edition\EditionService::class,
        \Stride\Modules\Enrollment\EnrollmentService::class,
    ],

    /**
     * Module configuration
     */
    'modules' => [
        'edition' => [],
        'enrollment' => [],
        'invoicing' => [],
        'trajectory' => [],
    ],
];
```

**Step 2: Update stride-core.php**

```php
<?php

declare(strict_types=1);

/**
 * Plugin Name: Stride Core
 * Description: Business logic for Stride LMS
 * Version: 1.0.0
 * Author: NTDST
 */

defined('ABSPATH') || exit;

// Load autoloader
require_once __DIR__ . '/autoload.php';

// Load config
$config = require __DIR__ . '/plugin-config.php';

// Register CPTs early
add_action('init', [\Stride\Modules\Edition\EditionCPT::class, 'register'], 5);

// Create custom tables on activation
add_action('init', function (): void {
    if (!get_option('stride_tables_created')) {
        \Stride\Modules\Enrollment\RegistrationTable::create();
        update_option('stride_tables_created', '1');
    }
}, 1);

// Register DI bindings
add_action('ntdst/core_ready', function () use ($config): void {
    // Register repositories first
    ntdst_set(\Stride\Modules\Edition\EditionRepository::class);
    ntdst_set(\Stride\Modules\Enrollment\RegistrationRepository::class);

    // Register interface bindings
    foreach ($config['bindings'] as $interface => $implementation) {
        ntdst_set($interface, $implementation);
    }
});

// Register services
add_action('ntdst/features_ready', function () use ($config): void {
    foreach ($config['services'] as $serviceClass) {
        if (class_exists($serviceClass)) {
            ntdst_get($serviceClass);
        }
    }
});
```

**Step 3: Verify syntax**

```bash
php -l web/app/mu-plugins/stride-core/plugin-config.php
php -l web/app/mu-plugins/stride-core/stride-core.php
```

**Step 4: Commit**

```bash
git add web/app/mu-plugins/stride-core/
git commit -m "feat: wire up Edition and Enrollment modules in plugin config"
```

---

## Task 8: Verify Services Load

**Step 1: Check table exists**

```bash
ddev exec wp db query "SHOW TABLES LIKE 'wp_vad_registrations'"
```

Expected: `wp_vad_registrations` table shown

**Step 2: Check CPT registered**

```bash
ddev exec wp post-type list --format=table | grep vad
```

Expected: `vad_edition` listed

**Step 3: Test autoloading**

```bash
ddev exec wp eval "
require_once ABSPATH . '../app/mu-plugins/stride-core/autoload.php';
echo class_exists('Stride\Modules\Edition\EditionService') ? 'EditionService: OK' : 'EditionService: FAIL';
echo PHP_EOL;
echo class_exists('Stride\Modules\Enrollment\EnrollmentService') ? 'EnrollmentService: OK' : 'EnrollmentService: FAIL';
echo PHP_EOL;
echo class_exists('Stride\Modules\Enrollment\RegistrationRepository') ? 'RegistrationRepository: OK' : 'RegistrationRepository: FAIL';
"
```

Expected: All OK

**Step 4: Commit verification**

```bash
git log --oneline -5
```

---

## Task 9: Create Test Data Script

**Files:**
- Create: `scripts/seed-editions.php`

**Step 1: Write seeder script**

```php
<?php
/**
 * Seed test editions for development.
 *
 * Run: ddev exec wp eval-file scripts/seed-editions.php
 */

defined('ABSPATH') || exit;

require_once ABSPATH . '../app/mu-plugins/stride-core/autoload.php';

// Ensure table exists
\Stride\Modules\Enrollment\RegistrationTable::create();

// Get or create a test course
$courses = get_posts([
    'post_type' => 'sfwd-courses',
    'posts_per_page' => 1,
    'post_status' => 'publish',
]);

if (empty($courses)) {
    $courseId = wp_insert_post([
        'post_title' => 'Test Cursus - Basisvorming',
        'post_type' => 'sfwd-courses',
        'post_status' => 'publish',
    ]);
    echo "Created test course: {$courseId}\n";
} else {
    $courseId = $courses[0]->ID;
    echo "Using existing course: {$courseId}\n";
}

// Create test editions
$editions = [
    [
        'title' => 'Basisvorming - Maart 2026',
        'course_id' => $courseId,
        'start_date' => '2026-03-15',
        'end_date' => '2026-03-16',
        'capacity' => 20,
        'price' => 250.00,
        'price_non_member' => 350.00,
        'venue' => 'VAD Brussel',
        'status' => 'open',
    ],
    [
        'title' => 'Basisvorming - April 2026',
        'course_id' => $courseId,
        'start_date' => '2026-04-10',
        'end_date' => '2026-04-11',
        'capacity' => 15,
        'price' => 250.00,
        'price_non_member' => 350.00,
        'venue' => 'VAD Gent',
        'status' => 'open',
    ],
    [
        'title' => 'Basisvorming - Mei 2026 (Volzet)',
        'course_id' => $courseId,
        'start_date' => '2026-05-20',
        'end_date' => '2026-05-21',
        'capacity' => 5,
        'price' => 250.00,
        'price_non_member' => 350.00,
        'venue' => 'VAD Antwerpen',
        'status' => 'full',
    ],
];

$model = ntdst_data()->get('vad_edition');

foreach ($editions as $edition) {
    $existing = $model->where('post_status', 'publish')
        ->where('start_date', $edition['start_date'])
        ->where('course_id', $edition['course_id'])
        ->first();

    if ($existing) {
        echo "Edition already exists: {$existing->title}\n";
        continue;
    }

    $result = $model->create($edition);

    if (is_wp_error($result)) {
        echo "Error creating edition: " . $result->get_error_message() . "\n";
    } else {
        echo "Created edition: {$result->post_title} (ID: {$result->ID})\n";
    }
}

echo "\nDone! Created test editions.\n";
```

**Step 2: Run seeder**

```bash
ddev exec wp eval-file scripts/seed-editions.php
```

**Step 3: Commit**

```bash
git add scripts/seed-editions.php
git commit -m "chore: add seed-editions.php for test data"
```

---

## Task 10: Create Basic Dashboard Shortcode

**Files:**
- Create: `web/app/mu-plugins/stride-core/Modules/User/DashboardShortcode.php`

**Step 1: Write shortcode handler**

```php
<?php

declare(strict_types=1);

namespace Stride\Modules\User;

use Stride\Modules\Edition\EditionService;
use Stride\Modules\Enrollment\EnrollmentService;

/**
 * Dashboard shortcode for displaying user enrollments.
 */
final class DashboardShortcode
{
    public function __construct(
        private readonly EnrollmentService $enrollment,
        private readonly EditionService $editions,
    ) {
        add_shortcode('stride_my_courses', [$this, 'renderMyCourses']);
    }

    /**
     * Render user's enrolled courses/editions.
     */
    public function renderMyCourses(array $atts = []): string
    {
        if (!is_user_logged_in()) {
            return '<p>Je moet ingelogd zijn om je cursussen te zien.</p>';
        }

        $userId = get_current_user_id();
        $enrollments = $this->enrollment->getUserEnrollments($userId);

        if (empty($enrollments)) {
            return '<div class="uk-alert uk-alert-primary">Je hebt nog geen inschrijvingen.</div>';
        }

        $output = '<div class="uk-grid uk-grid-small uk-child-width-1-1" uk-grid>';

        foreach ($enrollments as $registration) {
            $edition = $this->editions->getEdition((int) $registration->edition_id);

            if (is_wp_error($edition)) {
                continue;
            }

            $startDate = get_post_meta($edition->ID, 'start_date', true);
            $venue = get_post_meta($edition->ID, 'venue', true);
            $status = get_post_meta($edition->ID, 'status', true);

            $output .= sprintf(
                '<div>
                    <div class="uk-card uk-card-default uk-card-body uk-card-small">
                        <h3 class="uk-card-title uk-margin-remove-bottom">%s</h3>
                        <p class="uk-text-meta uk-margin-remove-top">
                            <span uk-icon="calendar"></span> %s
                            %s
                        </p>
                        <span class="uk-label %s">%s</span>
                    </div>
                </div>',
                esc_html($edition->post_title),
                esc_html($startDate ? date_i18n('j F Y', strtotime($startDate)) : 'Datum onbekend'),
                $venue ? '<span uk-icon="location"></span> ' . esc_html($venue) : '',
                $status === 'completed' ? 'uk-label-success' : 'uk-label-primary',
                esc_html(ucfirst($status ?: 'ingeschreven'))
            );
        }

        $output .= '</div>';

        return $output;
    }
}
```

**Step 2: Register in bootstrap**

Add to `stride-core.php` in the `ntdst/features_ready` action:

```php
// Register shortcodes
ntdst_set(\Stride\Modules\User\DashboardShortcode::class);
ntdst_get(\Stride\Modules\User\DashboardShortcode::class);
```

**Step 3: Verify syntax**

```bash
php -l web/app/mu-plugins/stride-core/Modules/User/DashboardShortcode.php
```

**Step 4: Commit**

```bash
git add web/app/mu-plugins/stride-core/
git commit -m "feat(user): add DashboardShortcode for displaying enrollments"
```

---

## Task 11: Test Complete Flow

**Step 1: Create test user and enroll**

```bash
ddev exec wp eval "
require_once ABSPATH . '../app/mu-plugins/stride-core/autoload.php';

// Get test user
\$user = get_user_by('email', 'seed_student1@seed.test');
if (!\$user) {
    echo 'Test user not found. Run scripts/seed.php first.';
    exit(1);
}

// Get first edition
\$editions = ntdst_data()->get('vad_edition')->where('status', 'open')->limit(1)->withMeta()->get();
if (empty(\$editions)) {
    echo 'No open editions found. Run scripts/seed-editions.php first.';
    exit(1);
}

\$editionId = \$editions[0]['id'];
echo \"Enrolling user {\$user->ID} in edition {\$editionId}...\n\";

// Get enrollment service
\$enrollment = ntdst_get(\Stride\Modules\Enrollment\EnrollmentService::class);
\$result = \$enrollment->enroll(\$user->ID, \$editionId);

if (is_wp_error(\$result)) {
    echo 'Error: ' . \$result->get_error_message() . \"\n\";
} else {
    echo \"Success! Registration ID: {\$result}\n\";
}

// Verify enrollment
\$isEnrolled = \$enrollment->isEnrolled(\$user->ID, \$editionId);
echo \"Is enrolled: \" . (\$isEnrolled ? 'YES' : 'NO') . \"\n\";
"
```

**Step 2: Check database**

```bash
ddev exec wp db query "SELECT * FROM wp_vad_registrations LIMIT 5"
```

**Step 3: Test shortcode renders**

Create a test page or check shortcode output:

```bash
ddev exec wp eval "
require_once ABSPATH . '../app/mu-plugins/stride-core/autoload.php';

// Simulate logged in user
wp_set_current_user(2); // Use appropriate user ID

\$shortcode = ntdst_get(\Stride\Modules\User\DashboardShortcode::class);
echo \$shortcode->renderMyCourses();
"
```

**Step 4: Final commit**

```bash
git add -A
git commit -m "test: verify complete enrollment flow works"
```

---

## Slice 1 Complete - Exit Criteria

- [x] vad_edition CPT registered with DataManager
- [x] EditionRepository with course/availability queries
- [x] EditionService implementing EditionQueryInterface
- [x] wp_vad_registrations custom table created
- [x] RegistrationRepository for high-volume queries
- [x] EnrollmentService orchestrating registration + LMS access
- [x] DashboardShortcode showing user enrollments
- [x] Test data seeder working
- [x] Complete flow: create edition → enroll user → see in dashboard

---

## Next: Slice 2

Create separate plan: `docs/plans/2026-02-16-stride-v1-slice2-quotes.md`

Slice 2 covers:
- QuoteRepository + QuoteService
- Quote creation on enrollment
- PDF generation
- Basic quote listing in dashboard
