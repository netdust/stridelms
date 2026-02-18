# Slice 4: Session Selection with Deadlines

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Enable users to select specific sessions within an edition, with deadline enforcement.

**Architecture:** Sessions are CPTs linked to editions. Editions define session slots (groups users must pick from). Users register for specific sessions via `wp_vad_session_registrations` table. Deadlines lock selections.

**Tech Stack:** PHP 8.3, ntdst-core DataManager, WordPress custom tables

---

## Context

**What exists:**
- `EditionCPT` and `EditionService` for scheduled offerings
- `RegistrationTable` and `RegistrationRepository` for user→edition enrollment
- `SessionType` enum (in_person, webinar, online, assignment)
- `AbstractRepository` base class for CPT repos

**What we're building:**
- Session CPT (`vad_session`) - individual meeting days/times
- `SessionRepository` - CRUD for sessions
- `SessionService` - business logic
- `SessionRegistrationTable` - user→session selections
- `SessionSelectionService` - deadline-aware selection logic
- Edition fields for slots and deadlines

**Data flow:**
```
Edition (has session_slots config)
  └── Sessions (belong to slots)
        └── SessionRegistrations (user picks)
              └── Locked after selection_deadline
```

---

## Task 1: Session CPT

**Files:**
- Create: `web/app/mu-plugins/stride-core/Modules/Edition/SessionCPT.php`

**Step 1: Create SessionCPT class**

```php
<?php

declare(strict_types=1);

namespace Stride\Modules\Edition;

/**
 * Session CPT Registration.
 *
 * Individual meeting days/times within an edition.
 */
final class SessionCPT
{
    public const POST_TYPE = 'vad_session';

    public static function register(): void
    {
        ntdst_data()->register(self::POST_TYPE, [
            'label' => 'Sessies',
            'labels' => [
                'name' => 'Sessies',
                'singular_name' => 'Sessie',
                'add_new' => 'Nieuwe sessie',
                'add_new_item' => 'Nieuwe sessie toevoegen',
                'edit_item' => 'Sessie bewerken',
            ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => 'edit.php?post_type=vad_edition',
            'supports' => ['title'],
            'fields' => self::getFields(),
            'field_groups' => self::getFieldGroups(),
        ]);
    }

    private static function getFields(): array
    {
        return [
            'edition_id' => [
                'type' => 'int',
                'label' => 'Editie',
                'required' => true,
            ],
            'slot' => [
                'type' => 'text',
                'label' => 'Slot',
                'description' => 'Slot identifier (e.g., dag1_vm, keuze_a)',
            ],
            'date' => [
                'type' => 'text',
                'label' => 'Datum',
                'required' => true,
            ],
            'start_time' => [
                'type' => 'text',
                'label' => 'Starttijd',
            ],
            'end_time' => [
                'type' => 'text',
                'label' => 'Eindtijd',
            ],
            'location' => [
                'type' => 'text',
                'label' => 'Locatie',
            ],
            'type' => [
                'type' => 'text',
                'label' => 'Type',
                'description' => 'in_person, webinar, online, assignment',
            ],
            'capacity' => [
                'type' => 'int',
                'label' => 'Capaciteit',
                'description' => 'Leave empty for unlimited',
            ],
            'optional' => [
                'type' => 'boolean',
                'label' => 'Optioneel',
                'description' => 'User can opt out',
            ],
        ];
    }

    private static function getFieldGroups(): array
    {
        return [
            'session_details' => [
                'title' => 'Sessie Details',
                'fields' => ['edition_id', 'slot', 'date', 'start_time', 'end_time', 'location'],
            ],
            'session_config' => [
                'title' => 'Configuratie',
                'fields' => ['type', 'capacity', 'optional'],
            ],
        ];
    }
}
```

**Step 2: Register CPT in stride-coreloader.php**

Find the CPT registration section and add:

```php
\Stride\Modules\Edition\SessionCPT::register();
```

**Step 3: Verify CPT loads**

Run: `ddev exec wp post-type list --format=table | grep vad_session`

Expected: `vad_session` appears in list

**Step 4: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Edition/SessionCPT.php
git add web/app/mu-plugins/stride-coreloader.php
git commit -m "feat(session): add Session CPT registration"
```

---

## Task 2: Session Repository

**Files:**
- Create: `web/app/mu-plugins/stride-core/Modules/Edition/SessionRepository.php`

**Step 1: Create SessionRepository class**

```php
<?php

declare(strict_types=1);

namespace Stride\Modules\Edition;

use Stride\Domain\SessionType;
use Stride\Infrastructure\AbstractRepository;
use WP_Error;
use WP_Post;

/**
 * Session repository for CRUD operations.
 */
final class SessionRepository extends AbstractRepository
{
    protected string $postType = SessionCPT::POST_TYPE;

    /**
     * Get a single field value.
     */
    public function getField(int $id, string $field, mixed $default = null): mixed
    {
        $value = $this->model()->getMeta($id, $field);

        return $value !== '' ? $value : $default;
    }

    /**
     * Find sessions for an edition.
     *
     * @return array<array<string, mixed>>
     */
    public function findByEdition(int $editionId): array
    {
        return $this->model()
            ->where('edition_id', $editionId)
            ->orderBy('date', 'ASC')
            ->orderBy('start_time', 'ASC')
            ->withMeta()
            ->get();
    }

    /**
     * Find sessions by slot within an edition.
     *
     * @return array<array<string, mixed>>
     */
    public function findBySlot(int $editionId, string $slot): array
    {
        return $this->model()
            ->where('edition_id', $editionId)
            ->where('slot', $slot)
            ->orderBy('date', 'ASC')
            ->withMeta()
            ->get();
    }

    /**
     * Count sessions for an edition.
     */
    public function countByEdition(int $editionId): int
    {
        return $this->model()
            ->where('edition_id', $editionId)
            ->count();
    }

    /**
     * Get unique dates for an edition.
     */
    public function getUniqueDates(int $editionId): array
    {
        $sessions = $this->findByEdition($editionId);
        $dates = array_unique(array_column($sessions, 'date'));
        sort($dates);

        return array_values($dates);
    }

    /**
     * Validate session data before create/update.
     */
    public function validate(array $data): true|WP_Error
    {
        if (empty($data['edition_id'])) {
            return new WP_Error('missing_edition', 'Edition ID is required');
        }

        if (empty($data['date'])) {
            return new WP_Error('missing_date', 'Date is required');
        }

        // Validate time range if both provided
        if (!empty($data['start_time']) && !empty($data['end_time'])) {
            if (strtotime($data['end_time']) <= strtotime($data['start_time'])) {
                return new WP_Error('invalid_time_range', 'End time must be after start time');
            }
        }

        // Validate type if provided
        if (!empty($data['type'])) {
            $validType = SessionType::tryFrom($data['type']);
            if ($validType === null) {
                return new WP_Error('invalid_type', 'Invalid session type');
            }
        }

        return true;
    }

    /**
     * Create session with validation.
     */
    public function create(array $data): WP_Post|WP_Error
    {
        $validation = $this->validate($data);
        if (is_wp_error($validation)) {
            return $validation;
        }

        // Set title from date + time
        $title = $data['date'];
        if (!empty($data['start_time'])) {
            $title .= ' ' . $data['start_time'];
        }
        $data['post_title'] = $title;

        return parent::create($data);
    }
}
```

**Step 2: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Edition/SessionRepository.php
git commit -m "feat(session): add SessionRepository with validation"
```

---

## Task 3: Session Service

**Files:**
- Create: `web/app/mu-plugins/stride-core/Modules/Edition/SessionService.php`

**Step 1: Create SessionService class**

```php
<?php

declare(strict_types=1);

namespace Stride\Modules\Edition;

use Stride\Domain\SessionType;
use Stride\Infrastructure\AbstractService;
use WP_Error;
use WP_Post;

/**
 * Session business logic.
 */
final class SessionService extends AbstractService
{
    public function __construct(
        private readonly SessionRepository $repository,
    ) {
        parent::__construct();
    }

    public static function metadata(): array
    {
        return [
            'name' => 'Session Service',
            'description' => 'Manages meeting days within editions',
            'priority' => 15,
        ];
    }

    protected function getConfigSlug(): string
    {
        return 'session';
    }

    protected function init(): void
    {
        // Future: hooks for session events
    }

    // === CRUD ===

    /**
     * Create a new session.
     */
    public function createSession(array $data): int|WP_Error
    {
        $result = $this->repository->create($data);

        if (is_wp_error($result)) {
            return $result;
        }

        do_action('stride/session/created', [
            'session_id' => $result->ID,
            'edition_id' => $data['edition_id'] ?? 0,
        ]);

        return $result->ID;
    }

    /**
     * Get session by ID.
     *
     * @return array<string, mixed>|null
     */
    public function getSession(int $sessionId): ?array
    {
        $post = $this->repository->find($sessionId);

        if (is_wp_error($post)) {
            return null;
        }

        return $this->formatSession($post);
    }

    /**
     * Update session.
     */
    public function updateSession(int $sessionId, array $data): true|WP_Error
    {
        $result = $this->repository->update($sessionId, $data);

        if (is_wp_error($result)) {
            return $result;
        }

        return true;
    }

    // === Queries ===

    /**
     * Get all sessions for an edition.
     *
     * @return array<array<string, mixed>>
     */
    public function getSessionsForEdition(int $editionId): array
    {
        $sessions = $this->repository->findByEdition($editionId);

        return array_map([$this, 'formatSessionArray'], $sessions);
    }

    /**
     * Get sessions by slot.
     *
     * @return array<array<string, mixed>>
     */
    public function getSessionsBySlot(int $editionId, string $slot): array
    {
        $sessions = $this->repository->findBySlot($editionId, $slot);

        return array_map([$this, 'formatSessionArray'], $sessions);
    }

    /**
     * Get session count for edition.
     */
    public function getSessionCount(int $editionId): int
    {
        return $this->repository->countByEdition($editionId);
    }

    /**
     * Get unique day count for edition.
     */
    public function getDayCount(int $editionId): int
    {
        return count($this->repository->getUniqueDates($editionId));
    }

    // === Duration Calculations ===

    /**
     * Get session duration in hours.
     */
    public function getSessionDuration(int $sessionId): float
    {
        $session = $this->getSession($sessionId);

        if (!$session || empty($session['start_time']) || empty($session['end_time'])) {
            return 0.0;
        }

        $start = strtotime($session['start_time']);
        $end = strtotime($session['end_time']);

        return ($end - $start) / 3600;
    }

    /**
     * Get total hours for all sessions in edition.
     */
    public function getTotalHours(int $editionId): float
    {
        $sessions = $this->getSessionsForEdition($editionId);
        $total = 0.0;

        foreach ($sessions as $session) {
            if (!empty($session['start_time']) && !empty($session['end_time'])) {
                $start = strtotime($session['start_time']);
                $end = strtotime($session['end_time']);
                $total += ($end - $start) / 3600;
            }
        }

        return $total;
    }

    // === Helpers ===

    /**
     * Format WP_Post to session array.
     */
    private function formatSession(WP_Post $post): array
    {
        $typeValue = $this->repository->getField($post->ID, 'type', 'in_person');
        $type = SessionType::tryFrom($typeValue) ?? SessionType::InPerson;

        return [
            'id' => $post->ID,
            'edition_id' => (int) $this->repository->getField($post->ID, 'edition_id'),
            'slot' => $this->repository->getField($post->ID, 'slot', ''),
            'date' => $this->repository->getField($post->ID, 'date', ''),
            'start_time' => $this->repository->getField($post->ID, 'start_time', ''),
            'end_time' => $this->repository->getField($post->ID, 'end_time', ''),
            'location' => $this->repository->getField($post->ID, 'location', ''),
            'type' => $type->value,
            'type_enum' => $type,
            'capacity' => (int) $this->repository->getField($post->ID, 'capacity', 0),
            'optional' => (bool) $this->repository->getField($post->ID, 'optional', false),
        ];
    }

    /**
     * Format array result to session array.
     */
    private function formatSessionArray(array $data): array
    {
        $typeValue = $data['type'] ?? 'in_person';
        $type = SessionType::tryFrom($typeValue) ?? SessionType::InPerson;

        return [
            'id' => (int) $data['ID'],
            'edition_id' => (int) ($data['edition_id'] ?? 0),
            'slot' => $data['slot'] ?? '',
            'date' => $data['date'] ?? '',
            'start_time' => $data['start_time'] ?? '',
            'end_time' => $data['end_time'] ?? '',
            'location' => $data['location'] ?? '',
            'type' => $type->value,
            'type_enum' => $type,
            'capacity' => (int) ($data['capacity'] ?? 0),
            'optional' => (bool) ($data['optional'] ?? false),
        ];
    }
}
```

**Step 2: Register service in plugin-config.php**

Add to the `services` array:

```php
\Stride\Modules\Edition\SessionService::class,
```

**Step 3: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Edition/SessionService.php
git add web/app/mu-plugins/stride-core/plugin-config.php
git commit -m "feat(session): add SessionService with CRUD and duration"
```

---

## Task 4: Session Registration Table

**Files:**
- Create: `web/app/mu-plugins/stride-core/Modules/Edition/SessionRegistrationTable.php`

**Step 1: Create table class**

```php
<?php

declare(strict_types=1);

namespace Stride\Modules\Edition;

/**
 * Session registration table for user session selections.
 */
final class SessionRegistrationTable
{
    public const TABLE_NAME = 'vad_session_registrations';

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
            registration_id BIGINT UNSIGNED NOT NULL,
            session_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            status ENUM('registered','cancelled') DEFAULT 'registered',
            registered_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            cancelled_at DATETIME NULL,
            INDEX idx_registration (registration_id),
            INDEX idx_session (session_id),
            INDEX idx_user (user_id),
            INDEX idx_status (status),
            UNIQUE KEY unique_user_session (user_id, session_id)
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

**Step 2: Create table on plugin activation**

Add to stride-coreloader.php in the activation section:

```php
\Stride\Modules\Edition\SessionRegistrationTable::create();
```

**Step 3: Manually trigger table creation**

Run: `ddev exec wp eval "\\Stride\\Modules\\Edition\\SessionRegistrationTable::create();"`

**Step 4: Verify table exists**

Run: `ddev exec wp db query "SHOW TABLES LIKE '%session_registrations%'"`

Expected: Table name appears

**Step 5: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Edition/SessionRegistrationTable.php
git add web/app/mu-plugins/stride-coreloader.php
git commit -m "feat(session): add session_registrations table"
```

---

## Task 5: Session Selection Service

**Files:**
- Create: `web/app/mu-plugins/stride-core/Modules/Edition/SessionSelectionService.php`

**Step 1: Create service class**

```php
<?php

declare(strict_types=1);

namespace Stride\Modules\Edition;

use Stride\Infrastructure\AbstractService;
use WP_Error;

/**
 * Session selection with deadline enforcement.
 *
 * Handles user picking sessions from available slots.
 */
final class SessionSelectionService extends AbstractService
{
    public function __construct(
        private readonly SessionService $sessions,
        private readonly EditionRepository $editions,
    ) {
        parent::__construct();
    }

    public static function metadata(): array
    {
        return [
            'name' => 'Session Selection Service',
            'description' => 'Handles session selection with deadlines',
            'priority' => 16,
        ];
    }

    protected function getConfigSlug(): string
    {
        return 'session_selection';
    }

    protected function init(): void
    {
        // Future: lock expired selections
    }

    // === Selection Queries ===

    /**
     * Get user's session selections for a registration.
     *
     * @return array<array<string, mixed>>
     */
    public function getUserSelections(int $registrationId): array
    {
        global $wpdb;
        $table = SessionRegistrationTable::getTableName();

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE registration_id = %d AND status = 'registered'",
            $registrationId
        ), ARRAY_A);

        return $results ?: [];
    }

    /**
     * Get registration count for a session.
     */
    public function getSessionRegistrationCount(int $sessionId): int
    {
        global $wpdb;
        $table = SessionRegistrationTable::getTableName();

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE session_id = %d AND status = 'registered'",
            $sessionId
        ));
    }

    /**
     * Check if session has available capacity.
     */
    public function hasCapacity(int $sessionId): bool
    {
        $session = $this->sessions->getSession($sessionId);

        if (!$session) {
            return false;
        }

        // No capacity limit
        if ($session['capacity'] === 0) {
            return true;
        }

        $registered = $this->getSessionRegistrationCount($sessionId);

        return $registered < $session['capacity'];
    }

    // === Selection Actions ===

    /**
     * Register user for a session.
     */
    public function registerForSession(
        int $registrationId,
        int $sessionId,
        int $userId
    ): true|WP_Error {
        // Validate session exists
        $session = $this->sessions->getSession($sessionId);
        if (!$session) {
            return new WP_Error('session_not_found', 'Session not found');
        }

        // Check deadline
        $editionId = $session['edition_id'];
        if ($this->isSelectionLocked($editionId)) {
            return new WP_Error('deadline_passed', 'Selection deadline has passed');
        }

        // Check capacity
        if (!$this->hasCapacity($sessionId)) {
            return new WP_Error('no_capacity', 'Session is full');
        }

        // Check not already registered
        if ($this->isRegisteredForSession($userId, $sessionId)) {
            return new WP_Error('already_registered', 'Already registered for this session');
        }

        // Insert registration
        global $wpdb;
        $table = SessionRegistrationTable::getTableName();

        $result = $wpdb->insert($table, [
            'registration_id' => $registrationId,
            'session_id' => $sessionId,
            'user_id' => $userId,
            'status' => 'registered',
            'registered_at' => current_time('mysql'),
        ], ['%d', '%d', '%d', '%s', '%s']);

        if ($result === false) {
            return new WP_Error('db_error', 'Failed to register for session');
        }

        do_action('stride/session/user_registered', [
            'registration_id' => $registrationId,
            'session_id' => $sessionId,
            'user_id' => $userId,
        ]);

        return true;
    }

    /**
     * Cancel user's session registration.
     */
    public function cancelSessionRegistration(int $userId, int $sessionId): true|WP_Error
    {
        $session = $this->sessions->getSession($sessionId);
        if (!$session) {
            return new WP_Error('session_not_found', 'Session not found');
        }

        // Check deadline
        if ($this->isSelectionLocked($session['edition_id'])) {
            return new WP_Error('deadline_passed', 'Selection deadline has passed');
        }

        global $wpdb;
        $table = SessionRegistrationTable::getTableName();

        $result = $wpdb->update(
            $table,
            [
                'status' => 'cancelled',
                'cancelled_at' => current_time('mysql'),
            ],
            [
                'user_id' => $userId,
                'session_id' => $sessionId,
            ],
            ['%s', '%s'],
            ['%d', '%d']
        );

        if ($result === false) {
            return new WP_Error('db_error', 'Failed to cancel session registration');
        }

        return true;
    }

    /**
     * Check if user is registered for session.
     */
    public function isRegisteredForSession(int $userId, int $sessionId): bool
    {
        global $wpdb;
        $table = SessionRegistrationTable::getTableName();

        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table}
             WHERE user_id = %d AND session_id = %d AND status = 'registered'",
            $userId,
            $sessionId
        ));

        return (int) $count > 0;
    }

    // === Deadline Checks ===

    /**
     * Check if selection is locked for an edition.
     */
    public function isSelectionLocked(int $editionId): bool
    {
        $deadline = $this->editions->getField($editionId, 'selection_deadline');

        if (empty($deadline)) {
            return false;
        }

        return strtotime($deadline) < time();
    }

    /**
     * Get days until selection deadline.
     */
    public function getDaysUntilDeadline(int $editionId): ?int
    {
        $deadline = $this->editions->getField($editionId, 'selection_deadline');

        if (empty($deadline)) {
            return null;
        }

        $diff = strtotime($deadline) - time();

        return (int) floor($diff / DAY_IN_SECONDS);
    }

    /**
     * Check if selection window is open.
     */
    public function isSelectionOpen(int $editionId): bool
    {
        // Check deadline not passed
        if ($this->isSelectionLocked($editionId)) {
            return false;
        }

        // Check if edition has slots configured
        $slots = $this->editions->getField($editionId, 'session_slots');

        return !empty($slots);
    }

    // === Slot Validation ===

    /**
     * Get slot configuration for edition.
     *
     * @return array<array<string, mixed>>
     */
    public function getSlotConfig(int $editionId): array
    {
        $slots = $this->editions->getField($editionId, 'session_slots');

        if (empty($slots)) {
            return [];
        }

        return is_array($slots) ? $slots : json_decode($slots, true) ?: [];
    }

    /**
     * Validate user's selections meet slot requirements.
     */
    public function validateSelections(int $registrationId, int $editionId): true|WP_Error
    {
        $slots = $this->getSlotConfig($editionId);
        $selections = $this->getUserSelections($registrationId);
        $selectedSessionIds = array_column($selections, 'session_id');

        foreach ($slots as $slot) {
            $slotName = $slot['slot'] ?? '';
            $required = $slot['required'] ?? false;
            $pickCount = $slot['pick_count'] ?? 1;

            if (!$required) {
                continue;
            }

            // Get sessions in this slot
            $slotSessions = $this->sessions->getSessionsBySlot($editionId, $slotName);
            $slotSessionIds = array_column($slotSessions, 'id');

            // Count how many selected
            $selectedInSlot = count(array_intersect($selectedSessionIds, $slotSessionIds));

            if ($selectedInSlot < $pickCount) {
                return new WP_Error(
                    'incomplete_selection',
                    sprintf('Slot "%s" requires %d selection(s), got %d', $slotName, $pickCount, $selectedInSlot)
                );
            }
        }

        return true;
    }
}
```

**Step 2: Register service**

Add to plugin-config.php services array:

```php
\Stride\Modules\Edition\SessionSelectionService::class,
```

**Step 3: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Edition/SessionSelectionService.php
git add web/app/mu-plugins/stride-core/plugin-config.php
git commit -m "feat(session): add SessionSelectionService with deadlines"
```

---

## Task 6: Edition CPT Updates

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Modules/Edition/EditionCPT.php`

**Step 1: Add selection fields to EditionCPT**

Add to `getFields()`:

```php
'selection_deadline' => [
    'type' => 'text',
    'label' => 'Selectie deadline',
    'description' => 'Deadline for session selection (YYYY-MM-DD)',
],
'session_slots' => [
    'type' => 'json',
    'label' => 'Sessie slots',
    'description' => 'JSON array of slot configurations',
],
```

Add to `getFieldGroups()`:

```php
'edition_sessions' => [
    'title' => 'Sessie Selectie',
    'fields' => ['selection_deadline', 'session_slots'],
],
```

**Step 2: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Edition/EditionCPT.php
git commit -m "feat(edition): add session selection fields"
```

---

## Task 7: Test Script

**Files:**
- Create: `scripts/test-session-selection.php`

**Step 1: Create test script**

```php
<?php
/**
 * Stride V1 - Session Selection Tests
 *
 * Tests session CRUD, selection, and deadline enforcement.
 *
 * Run with: ddev exec wp eval-file scripts/test-session-selection.php
 */

if (!defined('ABSPATH')) {
    echo "Run via WP-CLI: ddev exec wp eval-file scripts/test-session-selection.php\n";
    exit(1);
}

use Stride\Modules\Edition\EditionService;
use Stride\Modules\Edition\EditionRepository;
use Stride\Modules\Edition\SessionService;
use Stride\Modules\Edition\SessionSelectionService;
use Stride\Modules\Enrollment\RegistrationRepository;

echo "=== Stride V1 - Session Selection Tests ===" . PHP_EOL . PHP_EOL;

$editionRepo = ntdst_get(EditionRepository::class);
$sessionService = ntdst_get(SessionService::class);
$selectionService = ntdst_get(SessionSelectionService::class);
$registrationRepo = ntdst_get(RegistrationRepository::class);

$created = ['editions' => [], 'sessions' => [], 'users' => [], 'registrations' => []];
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
    // === A. SESSION CRUD ===
    echo "A. Session CRUD..." . PHP_EOL;

    // Create test edition
    $editionId = $editionRepo->create([
        'post_title' => 'Session Test Edition',
        'course_id' => 1,
        'start_date' => date('Y-m-d', strtotime('+30 days')),
        'capacity' => 20,
        'status' => 'open',
    ])->ID;
    $created['editions'][] = $editionId;

    // A1. Create session
    $sessionId = $sessionService->createSession([
        'edition_id' => $editionId,
        'slot' => 'dag1_vm',
        'date' => date('Y-m-d', strtotime('+30 days')),
        'start_time' => '09:00',
        'end_time' => '12:00',
        'type' => 'in_person',
    ]);
    assert_test(!is_wp_error($sessionId), 'A1. Create session');
    $created['sessions'][] = $sessionId;

    // A2. Get session
    $session = $sessionService->getSession($sessionId);
    assert_test($session !== null && $session['slot'] === 'dag1_vm', 'A2. Get session');

    // A3. Session duration
    $duration = $sessionService->getSessionDuration($sessionId);
    assert_test(abs($duration - 3.0) < 0.01, 'A3. Duration is 3 hours');

    // A4. Create second session
    $sessionId2 = $sessionService->createSession([
        'edition_id' => $editionId,
        'slot' => 'dag1_nm',
        'date' => date('Y-m-d', strtotime('+30 days')),
        'start_time' => '13:00',
        'end_time' => '17:00',
        'type' => 'in_person',
    ]);
    $created['sessions'][] = $sessionId2;

    // A5. Get sessions for edition
    $sessions = $sessionService->getSessionsForEdition($editionId);
    assert_test(count($sessions) === 2, 'A5. Edition has 2 sessions');

    // A6. Total hours
    $totalHours = $sessionService->getTotalHours($editionId);
    assert_test(abs($totalHours - 7.0) < 0.01, 'A6. Total hours is 7 (3+4)');

    echo PHP_EOL;

    // === B. SESSION SELECTION ===
    echo "B. Session Selection..." . PHP_EOL;

    // Create test user
    $userId = wp_create_user('session_test_' . time(), 'pass123', 'session@test.local');
    $created['users'][] = $userId;

    // Create registration
    $regId = $registrationRepo->create([
        'user_id' => $userId,
        'edition_id' => $editionId,
        'status' => 'confirmed',
    ]);
    $created['registrations'][] = $regId;

    // B1. Register for session
    $result = $selectionService->registerForSession($regId, $sessionId, $userId);
    assert_test($result === true, 'B1. Register for session succeeds');

    // B2. Check is registered
    $isRegistered = $selectionService->isRegisteredForSession($userId, $sessionId);
    assert_test($isRegistered, 'B2. User is registered for session');

    // B3. Get user selections
    $selections = $selectionService->getUserSelections($regId);
    assert_test(count($selections) === 1, 'B3. User has 1 selection');

    // B4. Double registration fails
    $result2 = $selectionService->registerForSession($regId, $sessionId, $userId);
    assert_test(is_wp_error($result2) && $result2->get_error_code() === 'already_registered', 'B4. Double registration rejected');

    // B5. Session registration count
    $count = $selectionService->getSessionRegistrationCount($sessionId);
    assert_test($count === 1, 'B5. Session has 1 registration');

    // B6. Cancel session registration
    $cancelResult = $selectionService->cancelSessionRegistration($userId, $sessionId);
    assert_test($cancelResult === true, 'B6. Cancel session succeeds');

    // B7. User no longer registered
    $isRegistered = $selectionService->isRegisteredForSession($userId, $sessionId);
    assert_test(!$isRegistered, 'B7. User no longer registered after cancel');

    echo PHP_EOL;

    // === C. DEADLINE ENFORCEMENT ===
    echo "C. Deadline Enforcement..." . PHP_EOL;

    // C1. No deadline = not locked
    $isLocked = $selectionService->isSelectionLocked($editionId);
    assert_test(!$isLocked, 'C1. No deadline = not locked');

    // C2. Set future deadline
    $editionRepo->update($editionId, [
        'selection_deadline' => date('Y-m-d', strtotime('+14 days')),
    ]);
    $days = $selectionService->getDaysUntilDeadline($editionId);
    assert_test($days >= 13 && $days <= 14, 'C2. Days until deadline correct');

    // C3. Selection still open
    $isLocked = $selectionService->isSelectionLocked($editionId);
    assert_test(!$isLocked, 'C3. Future deadline = not locked');

    // C4. Set past deadline
    $editionRepo->update($editionId, [
        'selection_deadline' => date('Y-m-d', strtotime('-1 day')),
    ]);
    $isLocked = $selectionService->isSelectionLocked($editionId);
    assert_test($isLocked, 'C4. Past deadline = locked');

    // C5. Registration blocked after deadline
    $result = $selectionService->registerForSession($regId, $sessionId2, $userId);
    assert_test(is_wp_error($result) && $result->get_error_code() === 'deadline_passed', 'C5. Registration blocked after deadline');

    echo PHP_EOL;

    // === D. CAPACITY ===
    echo "D. Capacity..." . PHP_EOL;

    // Reset deadline and create capacity-limited session
    $editionRepo->update($editionId, ['selection_deadline' => '']);

    $limitedSessionId = $sessionService->createSession([
        'edition_id' => $editionId,
        'slot' => 'limited',
        'date' => date('Y-m-d', strtotime('+31 days')),
        'start_time' => '09:00',
        'end_time' => '12:00',
        'type' => 'in_person',
        'capacity' => 1,
    ]);
    $created['sessions'][] = $limitedSessionId;

    // D1. Has capacity initially
    $hasCapacity = $selectionService->hasCapacity($limitedSessionId);
    assert_test($hasCapacity, 'D1. Session has capacity initially');

    // D2. Register fills capacity
    $selectionService->registerForSession($regId, $limitedSessionId, $userId);
    $hasCapacity = $selectionService->hasCapacity($limitedSessionId);
    assert_test(!$hasCapacity, 'D2. Session full after registration');

    // D3. Second user blocked
    $userId2 = wp_create_user('session_test2_' . time(), 'pass123', 'session2@test.local');
    $created['users'][] = $userId2;
    $regId2 = $registrationRepo->create([
        'user_id' => $userId2,
        'edition_id' => $editionId,
        'status' => 'confirmed',
    ]);
    $created['registrations'][] = $regId2;

    $result = $selectionService->registerForSession($regId2, $limitedSessionId, $userId2);
    assert_test(is_wp_error($result) && $result->get_error_code() === 'no_capacity', 'D3. Full session rejects registration');

    echo PHP_EOL;

} catch (Exception $e) {
    echo "[FATAL] " . $e->getMessage() . PHP_EOL;
    echo $e->getTraceAsString() . PHP_EOL;
}

// Cleanup
echo "Cleaning up..." . PHP_EOL;

foreach ($created['sessions'] as $id) {
    wp_delete_post($id, true);
}
foreach ($created['editions'] as $id) {
    wp_delete_post($id, true);
}
foreach ($created['registrations'] as $id) {
    global $wpdb;
    $wpdb->delete($wpdb->prefix . 'vad_registrations', ['id' => $id]);
    $wpdb->delete($wpdb->prefix . 'vad_session_registrations', ['registration_id' => $id]);
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

Run: `ddev exec wp eval-file scripts/test-session-selection.php`

Expected: All tests pass

**Step 3: Commit**

```bash
git add scripts/test-session-selection.php
git commit -m "test(session): add session selection test script"
```

---

## Task 8: Update EditionRepository

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Modules/Edition/EditionRepository.php`

**Step 1: Add getField method if missing**

Check if `getField()` exists. If not, add:

```php
/**
 * Get a single field value.
 */
public function getField(int $id, string $field, mixed $default = null): mixed
{
    $value = $this->model()->getMeta($id, $field);

    return $value !== '' ? $value : $default;
}
```

**Step 2: Commit if changed**

```bash
git add web/app/mu-plugins/stride-core/Modules/Edition/EditionRepository.php
git commit -m "feat(edition): add getField method to repository"
```

---

## Summary

| Task | Description | Files |
|------|-------------|-------|
| 1 | Session CPT | `SessionCPT.php`, coreloader |
| 2 | Session Repository | `SessionRepository.php` |
| 3 | Session Service | `SessionService.php`, plugin-config |
| 4 | Session Registration Table | `SessionRegistrationTable.php`, coreloader |
| 5 | Session Selection Service | `SessionSelectionService.php`, plugin-config |
| 6 | Edition CPT Updates | `EditionCPT.php` |
| 7 | Test Script | `test-session-selection.php` |
| 8 | Edition Repository Update | `EditionRepository.php` |

**Total: ~8 commits, ~600 lines**

---

## References

- Architecture: `docs/plans/2026-02-16-stride-v1-architecture-design.md`
- Existing patterns: `Modules/Invoicing/VoucherService.php`, `Modules/Enrollment/RegistrationTable.php`
- SessionType enum: `Domain/SessionType.php`
