# Slice 6: Attendance + Completion Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Implement backend services for tracking session attendance and triggering edition completion based on attendance rules.

**Architecture:** Three-tier approach: 1) AttendanceTable for high-volume attendance records, 2) AttendanceRepository for data access, 3) AttendanceService for business logic + CompletionService for completion rules. Follows the same patterns as RegistrationTable/Repository and TrajectoryEnrollmentTable/Repository.

**Tech Stack:** PHP 8.1+, WordPress custom tables via dbDelta, backed enums, NTDST Core DI container, LearnDash for course completion bridging.

---

## Context

### From Master Plan (Phase 5)

The attendance table replaces JSON postmeta storage for concurrent check-ins and audit trails:

```sql
CREATE TABLE {prefix}_vad_attendance (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  edition_id BIGINT UNSIGNED NOT NULL,
  session_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  status ENUM('present','absent','excused') DEFAULT 'present',
  marked_by BIGINT UNSIGNED NULL,
  marked_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_session_user (session_id, user_id),
  INDEX idx_user_status (user_id, status),
  UNIQUE KEY unique_session_user (session_id, user_id)
);
```

CompletionService determines if a user has completed an edition based on attendance. Supports 3 modes:
- `attend_all`: Must attend all sessions
- `percentage`: Must attend X% of sessions
- `count`: Must attend at least N sessions

### Reference Files

- Table pattern: `Modules/Enrollment/RegistrationTable.php`
- Repository pattern: `Modules/Enrollment/RegistrationRepository.php`
- Enum pattern: `Domain/RegistrationStatus.php`
- Service pattern: `Modules/Edition/SessionService.php`
- Table registration: `stride-core.php` lines 28-51
- LearnDash adapter: `Adapters/LearnDashAdapter.php` (4-point integration)

---

## Task 1: AttendanceStatus Enum

**Files:**
- Create: `web/app/mu-plugins/stride-core/Domain/AttendanceStatus.php`

**Step 1: Create the enum file**

```php
<?php

declare(strict_types=1);

namespace Stride\Domain;

/**
 * Attendance status values.
 */
enum AttendanceStatus: string
{
    case Present = 'present';
    case Absent = 'absent';
    case Excused = 'excused';

    /**
     * Check if status counts as attended.
     */
    public function countsAsAttended(): bool
    {
        return $this === self::Present;
    }

    /**
     * Check if status indicates user was scheduled but not present.
     */
    public function wasMissed(): bool
    {
        return $this === self::Absent;
    }

    /**
     * Get human-readable label (Dutch).
     */
    public function label(): string
    {
        return match ($this) {
            self::Present => 'Aanwezig',
            self::Absent => 'Afwezig',
            self::Excused => 'Verontschuldigd',
        };
    }
}
```

**Step 2: Verify file created**

Run: `ddev exec bash -c 'php -l web/app/mu-plugins/stride-core/Domain/AttendanceStatus.php'`
Expected: `No syntax errors detected`

**Step 3: Commit**

```bash
git add web/app/mu-plugins/stride-core/Domain/AttendanceStatus.php
git commit -m "feat(attendance): add AttendanceStatus enum"
```

---

## Task 2: CompletionMode Enum

**Files:**
- Create: `web/app/mu-plugins/stride-core/Domain/CompletionMode.php`

**Step 1: Create the enum file**

```php
<?php

declare(strict_types=1);

namespace Stride\Domain;

/**
 * Edition completion mode values.
 *
 * Determines how attendance is evaluated for completion.
 */
enum CompletionMode: string
{
    case AttendAll = 'attend_all';
    case Percentage = 'percentage';
    case Count = 'count';

    /**
     * Get human-readable label.
     */
    public function label(): string
    {
        return match ($this) {
            self::AttendAll => 'Alle sessies bijwonen',
            self::Percentage => 'Percentage sessies bijwonen',
            self::Count => 'Minimum aantal sessies bijwonen',
        };
    }

    /**
     * Get description.
     */
    public function description(): string
    {
        return match ($this) {
            self::AttendAll => 'Deelnemer moet alle sessies bijwonen',
            self::Percentage => 'Deelnemer moet een percentage van de sessies bijwonen',
            self::Count => 'Deelnemer moet een minimum aantal sessies bijwonen',
        };
    }
}
```

**Step 2: Verify file created**

Run: `ddev exec bash -c 'php -l web/app/mu-plugins/stride-core/Domain/CompletionMode.php'`
Expected: `No syntax errors detected`

**Step 3: Commit**

```bash
git add web/app/mu-plugins/stride-core/Domain/CompletionMode.php
git commit -m "feat(completion): add CompletionMode enum"
```

---

## Task 3: AttendanceTable

**Files:**
- Create: `web/app/mu-plugins/stride-core/Modules/Attendance/AttendanceTable.php`
- Modify: `web/app/mu-plugins/stride-core/stride-core.php` (add table creation)

**Step 1: Create the table class**

```php
<?php

declare(strict_types=1);

namespace Stride\Modules\Attendance;

/**
 * Attendance table for session attendance tracking.
 */
final class AttendanceTable
{
    public const TABLE_NAME = 'vad_attendance';

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
            edition_id BIGINT UNSIGNED NOT NULL,
            session_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            status ENUM('present','absent','excused') DEFAULT 'present',
            marked_by BIGINT UNSIGNED NULL,
            marked_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_session_user (session_id, user_id),
            INDEX idx_user_status (user_id, status),
            INDEX idx_edition (edition_id),
            UNIQUE KEY unique_session_user (session_id, user_id)
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

**Step 2: Verify syntax**

Run: `ddev exec bash -c 'php -l web/app/mu-plugins/stride-core/Modules/Attendance/AttendanceTable.php'`
Expected: `No syntax errors detected`

**Step 3: Add table creation to stride-core.php**

In `stride-core.php`, after line 51 (after trajectory_enrollments table creation), add:

```php
// Create attendance table if missing
add_action('init', function (): void {
    if (!get_option('stride_attendance_table_created')) {
        \Stride\Modules\Attendance\AttendanceTable::create();
        update_option('stride_attendance_table_created', '1');
    }
}, 1);
```

**Step 4: Test table creation**

Run: `ddev exec bash -c 'wp eval "\\Stride\\Modules\\Attendance\\AttendanceTable::create(); echo \\Stride\\Modules\\Attendance\\AttendanceTable::exists() ? \"OK\" : \"FAIL\";"'`
Expected: `OK`

**Step 5: Verify table structure**

Run: `ddev exec bash -c 'wp db query "DESCRIBE wp_vad_attendance"'`
Expected: Table with columns: id, edition_id, session_id, user_id, status, marked_by, marked_at

**Step 6: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Attendance/AttendanceTable.php web/app/mu-plugins/stride-core/stride-core.php
git commit -m "feat(attendance): add attendance table"
```

---

## Task 4: AttendanceRepository

**Files:**
- Create: `web/app/mu-plugins/stride-core/Modules/Attendance/AttendanceRepository.php`
- Modify: `web/app/mu-plugins/stride-core/stride-core.php` (register in DI)

**Step 1: Create the repository**

```php
<?php

declare(strict_types=1);

namespace Stride\Modules\Attendance;

use Stride\Domain\AttendanceStatus;
use WP_Error;

/**
 * Repository for attendance data access.
 */
final class AttendanceRepository
{
    private function table(): string
    {
        return AttendanceTable::getTableName();
    }

    /**
     * Record attendance for a user at a session.
     *
     * @return int|WP_Error Attendance record ID or error
     */
    public function record(int $sessionId, int $userId, AttendanceStatus $status, ?int $editionId = null, ?int $markedBy = null): int|WP_Error
    {
        global $wpdb;

        // If edition_id not provided, look it up from session
        if ($editionId === null) {
            $editionId = (int) get_post_meta($sessionId, '_vad_edition_id', true);
            if ($editionId === 0) {
                return new WP_Error('missing_edition', 'Could not determine edition for session');
            }
        }

        // Check for existing record
        $existing = $this->findBySessionAndUser($sessionId, $userId);

        if ($existing) {
            // Update existing record
            $result = $wpdb->update(
                $this->table(),
                [
                    'status' => $status->value,
                    'marked_by' => $markedBy,
                    'marked_at' => current_time('mysql'),
                ],
                ['id' => $existing->id]
            );

            if ($result === false) {
                return new WP_Error('db_error', 'Failed to update attendance');
            }

            return (int) $existing->id;
        }

        // Insert new record
        $result = $wpdb->insert($this->table(), [
            'edition_id' => $editionId,
            'session_id' => $sessionId,
            'user_id' => $userId,
            'status' => $status->value,
            'marked_by' => $markedBy,
        ]);

        if ($result === false) {
            return new WP_Error('db_error', 'Failed to record attendance');
        }

        return (int) $wpdb->insert_id;
    }

    /**
     * Find attendance record by ID.
     */
    public function find(int $id): ?object
    {
        global $wpdb;

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table()} WHERE id = %d",
            $id
        ));
    }

    /**
     * Find attendance record by session and user.
     */
    public function findBySessionAndUser(int $sessionId, int $userId): ?object
    {
        global $wpdb;

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table()} WHERE session_id = %d AND user_id = %d",
            $sessionId,
            $userId
        ));
    }

    /**
     * Get all attendees for a session.
     *
     * @return array<object>
     */
    public function getBySession(int $sessionId, ?AttendanceStatus $status = null): array
    {
        global $wpdb;

        $sql = "SELECT * FROM {$this->table()} WHERE session_id = %d";
        $params = [$sessionId];

        if ($status !== null) {
            $sql .= " AND status = %s";
            $params[] = $status->value;
        }

        $sql .= " ORDER BY marked_at ASC";

        return $wpdb->get_results($wpdb->prepare($sql, ...$params));
    }

    /**
     * Get attendance records for a user in an edition.
     *
     * @return array<object>
     */
    public function getByUserAndEdition(int $userId, int $editionId): array
    {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table()} WHERE user_id = %d AND edition_id = %d ORDER BY session_id ASC",
            $userId,
            $editionId
        ));
    }

    /**
     * Get all attendance records for a user.
     *
     * @return array<object>
     */
    public function getByUser(int $userId, ?AttendanceStatus $status = null): array
    {
        global $wpdb;

        $sql = "SELECT * FROM {$this->table()} WHERE user_id = %d";
        $params = [$userId];

        if ($status !== null) {
            $sql .= " AND status = %s";
            $params[] = $status->value;
        }

        $sql .= " ORDER BY marked_at DESC";

        return $wpdb->get_results($wpdb->prepare($sql, ...$params));
    }

    /**
     * Count attended sessions for user in edition.
     */
    public function countAttended(int $userId, int $editionId): int
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table()} WHERE user_id = %d AND edition_id = %d AND status = 'present'",
            $userId,
            $editionId
        ));
    }

    /**
     * Count total attendance records for user in edition.
     */
    public function countRecords(int $userId, int $editionId): int
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table()} WHERE user_id = %d AND edition_id = %d",
            $userId,
            $editionId
        ));
    }

    /**
     * Check if user has any attendance record for session.
     */
    public function hasRecord(int $sessionId, int $userId): bool
    {
        return $this->findBySessionAndUser($sessionId, $userId) !== null;
    }

    /**
     * Check if user is marked present for session.
     */
    public function isPresent(int $sessionId, int $userId): bool
    {
        $record = $this->findBySessionAndUser($sessionId, $userId);

        if (!$record) {
            return false;
        }

        $status = AttendanceStatus::tryFrom($record->status);
        return $status?->countsAsAttended() ?? false;
    }

    /**
     * Get user IDs marked present for a session.
     *
     * @return array<int>
     */
    public function getPresentUserIds(int $sessionId): array
    {
        global $wpdb;

        $results = $wpdb->get_col($wpdb->prepare(
            "SELECT user_id FROM {$this->table()} WHERE session_id = %d AND status = 'present'",
            $sessionId
        ));

        return array_map('intval', $results);
    }

    /**
     * Delete attendance record.
     */
    public function delete(int $id): bool
    {
        global $wpdb;

        return $wpdb->delete($this->table(), ['id' => $id]) !== false;
    }

    /**
     * Delete all attendance records for a session.
     */
    public function deleteBySession(int $sessionId): bool
    {
        global $wpdb;

        return $wpdb->delete($this->table(), ['session_id' => $sessionId]) !== false;
    }
}
```

**Step 2: Verify syntax**

Run: `ddev exec bash -c 'php -l web/app/mu-plugins/stride-core/Modules/Attendance/AttendanceRepository.php'`
Expected: `No syntax errors detected`

**Step 3: Register in DI container**

In `stride-core.php`, in the `ntdst/core_ready` action (around line 61), add after the TrajectoryRepository registration:

```php
ntdst_set(\Stride\Modules\Attendance\AttendanceRepository::class);
```

**Step 4: Test instantiation**

Run: `ddev exec bash -c 'wp eval "ntdst_get(\\Stride\\Modules\\Attendance\\AttendanceRepository::class); echo \"OK\";"'`
Expected: `OK`

**Step 5: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Attendance/AttendanceRepository.php web/app/mu-plugins/stride-core/stride-core.php
git commit -m "feat(attendance): add AttendanceRepository"
```

---

## Task 5: AttendanceService

**Files:**
- Create: `web/app/mu-plugins/stride-core/Modules/Attendance/AttendanceService.php`
- Modify: `web/app/mu-plugins/stride-core/plugin-config.php` (register service)

**Step 1: Create the service**

```php
<?php

declare(strict_types=1);

namespace Stride\Modules\Attendance;

use Stride\Domain\AttendanceStatus;
use Stride\Infrastructure\AbstractService;
use Stride\Modules\Edition\SessionService;
use WP_Error;

/**
 * Attendance business logic.
 */
final class AttendanceService extends AbstractService
{
    public function __construct(
        private readonly AttendanceRepository $repository,
        private readonly SessionService $sessionService,
    ) {
        parent::__construct();
    }

    public static function metadata(): array
    {
        return [
            'name' => 'Attendance Service',
            'description' => 'Manages session attendance tracking',
            'priority' => 25,
        ];
    }

    protected function getConfigSlug(): string
    {
        return 'attendance';
    }

    protected function init(): void
    {
        // Future: hooks for attendance events
    }

    // === Mark Attendance ===

    /**
     * Mark user as present for a session.
     */
    public function markPresent(int $sessionId, int $userId, ?int $markedBy = null): int|WP_Error
    {
        $session = $this->sessionService->getSession($sessionId);

        if (!$session) {
            return new WP_Error('invalid_session', 'Session not found');
        }

        $result = $this->repository->record(
            $sessionId,
            $userId,
            AttendanceStatus::Present,
            $session['edition_id'],
            $markedBy ?? get_current_user_id()
        );

        if (!is_wp_error($result)) {
            do_action('stride/attendance/marked', [
                'attendance_id' => $result,
                'session_id' => $sessionId,
                'user_id' => $userId,
                'status' => AttendanceStatus::Present->value,
                'edition_id' => $session['edition_id'],
            ]);
        }

        return $result;
    }

    /**
     * Mark user as absent for a session.
     */
    public function markAbsent(int $sessionId, int $userId, ?int $markedBy = null): int|WP_Error
    {
        $session = $this->sessionService->getSession($sessionId);

        if (!$session) {
            return new WP_Error('invalid_session', 'Session not found');
        }

        $result = $this->repository->record(
            $sessionId,
            $userId,
            AttendanceStatus::Absent,
            $session['edition_id'],
            $markedBy ?? get_current_user_id()
        );

        if (!is_wp_error($result)) {
            do_action('stride/attendance/marked', [
                'attendance_id' => $result,
                'session_id' => $sessionId,
                'user_id' => $userId,
                'status' => AttendanceStatus::Absent->value,
                'edition_id' => $session['edition_id'],
            ]);
        }

        return $result;
    }

    /**
     * Mark user as excused for a session.
     */
    public function markExcused(int $sessionId, int $userId, ?int $markedBy = null): int|WP_Error
    {
        $session = $this->sessionService->getSession($sessionId);

        if (!$session) {
            return new WP_Error('invalid_session', 'Session not found');
        }

        $result = $this->repository->record(
            $sessionId,
            $userId,
            AttendanceStatus::Excused,
            $session['edition_id'],
            $markedBy ?? get_current_user_id()
        );

        if (!is_wp_error($result)) {
            do_action('stride/attendance/marked', [
                'attendance_id' => $result,
                'session_id' => $sessionId,
                'user_id' => $userId,
                'status' => AttendanceStatus::Excused->value,
                'edition_id' => $session['edition_id'],
            ]);
        }

        return $result;
    }

    // === Queries ===

    /**
     * Check if user is present for a session.
     */
    public function isPresent(int $sessionId, int $userId): bool
    {
        return $this->repository->isPresent($sessionId, $userId);
    }

    /**
     * Get attendance status for user at session.
     */
    public function getStatus(int $sessionId, int $userId): ?AttendanceStatus
    {
        $record = $this->repository->findBySessionAndUser($sessionId, $userId);

        if (!$record) {
            return null;
        }

        return AttendanceStatus::tryFrom($record->status);
    }

    /**
     * Get all attendees for a session.
     *
     * @return array<int> User IDs
     */
    public function getAttendees(int $sessionId): array
    {
        return $this->repository->getPresentUserIds($sessionId);
    }

    /**
     * Get attendance records for a session.
     *
     * @return array<array<string, mixed>>
     */
    public function getSessionAttendance(int $sessionId): array
    {
        $records = $this->repository->getBySession($sessionId);

        return array_map(function ($record) {
            return [
                'id' => (int) $record->id,
                'user_id' => (int) $record->user_id,
                'status' => $record->status,
                'status_enum' => AttendanceStatus::tryFrom($record->status),
                'marked_by' => $record->marked_by ? (int) $record->marked_by : null,
                'marked_at' => $record->marked_at,
            ];
        }, $records);
    }

    /**
     * Get user's attendance for an edition.
     *
     * @return array<array<string, mixed>>
     */
    public function getUserEditionAttendance(int $userId, int $editionId): array
    {
        $records = $this->repository->getByUserAndEdition($userId, $editionId);

        return array_map(function ($record) {
            return [
                'id' => (int) $record->id,
                'session_id' => (int) $record->session_id,
                'status' => $record->status,
                'status_enum' => AttendanceStatus::tryFrom($record->status),
                'marked_by' => $record->marked_by ? (int) $record->marked_by : null,
                'marked_at' => $record->marked_at,
            ];
        }, $records);
    }

    // === Statistics ===

    /**
     * Count sessions attended by user in edition.
     */
    public function countAttended(int $userId, int $editionId): int
    {
        return $this->repository->countAttended($userId, $editionId);
    }

    /**
     * Get hours attended by user in edition.
     */
    public function getHoursAttended(int $userId, int $editionId): float
    {
        $attendance = $this->repository->getByUserAndEdition($userId, $editionId);
        $hours = 0.0;

        foreach ($attendance as $record) {
            $status = AttendanceStatus::tryFrom($record->status);
            if ($status?->countsAsAttended()) {
                $hours += $this->sessionService->getSessionDuration((int) $record->session_id);
            }
        }

        return $hours;
    }

    /**
     * Get attendance rate for user in edition.
     *
     * @return float Percentage (0-100)
     */
    public function getAttendanceRate(int $userId, int $editionId): float
    {
        $totalSessions = $this->sessionService->getSessionCount($editionId);

        if ($totalSessions === 0) {
            return 0.0;
        }

        $attended = $this->countAttended($userId, $editionId);

        return ($attended / $totalSessions) * 100;
    }

    // === Bulk Operations ===

    /**
     * Mark multiple users present for a session.
     *
     * @param array<int> $userIds
     * @return array<int, int|WP_Error> Map of userId => result
     */
    public function markMultiplePresent(int $sessionId, array $userIds, ?int $markedBy = null): array
    {
        $results = [];

        foreach ($userIds as $userId) {
            $results[$userId] = $this->markPresent($sessionId, $userId, $markedBy);
        }

        return $results;
    }
}
```

**Step 2: Verify syntax**

Run: `ddev exec bash -c 'php -l web/app/mu-plugins/stride-core/Modules/Attendance/AttendanceService.php'`
Expected: `No syntax errors detected`

**Step 3: Register service in plugin-config.php**

In `plugin-config.php`, add to the services array:

```php
\Stride\Modules\Attendance\AttendanceService::class,
```

**Step 4: Test instantiation**

Run: `ddev exec bash -c 'wp eval "ntdst_get(\\Stride\\Modules\\Attendance\\AttendanceService::class); echo \"OK\";"'`
Expected: `OK`

**Step 5: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Attendance/AttendanceService.php web/app/mu-plugins/stride-core/plugin-config.php
git commit -m "feat(attendance): add AttendanceService"
```

---

## Task 6: CompletionService

**Files:**
- Create: `web/app/mu-plugins/stride-core/Modules/Completion/CompletionService.php`
- Modify: `web/app/mu-plugins/stride-core/plugin-config.php` (register service)

**Step 1: Create the service**

```php
<?php

declare(strict_types=1);

namespace Stride\Modules\Completion;

use Stride\Contracts\LMSAdapterInterface;
use Stride\Domain\CompletionMode;
use Stride\Infrastructure\AbstractService;
use Stride\Modules\Attendance\AttendanceService;
use Stride\Modules\Edition\EditionService;
use Stride\Modules\Edition\SessionService;
use WP_Error;

/**
 * Edition completion business logic.
 *
 * Determines if a user has completed an edition based on attendance.
 */
final class CompletionService extends AbstractService
{
    public function __construct(
        private readonly AttendanceService $attendanceService,
        private readonly EditionService $editionService,
        private readonly SessionService $sessionService,
        private readonly LMSAdapterInterface $lmsAdapter,
    ) {
        parent::__construct();
    }

    public static function metadata(): array
    {
        return [
            'name' => 'Completion Service',
            'description' => 'Manages edition completion based on attendance',
            'priority' => 30,
        ];
    }

    protected function getConfigSlug(): string
    {
        return 'completion';
    }

    protected function init(): void
    {
        // Listen for attendance events to check completion
        add_action('stride/attendance/marked', [$this, 'onAttendanceMarked']);
    }

    // === Completion Checks ===

    /**
     * Check if user has completed an edition.
     */
    public function isComplete(int $editionId, int $userId): bool
    {
        $edition = $this->editionService->getEdition($editionId);

        if (!$edition) {
            return false;
        }

        $mode = $this->getCompletionMode($editionId);
        $threshold = $this->getCompletionThreshold($editionId);
        $totalSessions = $this->sessionService->getSessionCount($editionId);
        $attended = $this->attendanceService->countAttended($userId, $editionId);

        return match ($mode) {
            CompletionMode::AttendAll => $attended >= $totalSessions,
            CompletionMode::Percentage => $totalSessions > 0 && ($attended / $totalSessions * 100) >= $threshold,
            CompletionMode::Count => $attended >= $threshold,
        };
    }

    /**
     * Get completion progress for user in edition.
     *
     * @return array<string, mixed>
     */
    public function getProgress(int $editionId, int $userId): array
    {
        $totalSessions = $this->sessionService->getSessionCount($editionId);
        $attended = $this->attendanceService->countAttended($userId, $editionId);
        $mode = $this->getCompletionMode($editionId);
        $threshold = $this->getCompletionThreshold($editionId);
        $isComplete = $this->isComplete($editionId, $userId);

        $required = match ($mode) {
            CompletionMode::AttendAll => $totalSessions,
            CompletionMode::Percentage => (int) ceil($totalSessions * $threshold / 100),
            CompletionMode::Count => $threshold,
        };

        return [
            'total_sessions' => $totalSessions,
            'attended' => $attended,
            'required' => $required,
            'remaining' => max(0, $required - $attended),
            'percentage' => $totalSessions > 0 ? round($attended / $totalSessions * 100, 1) : 0,
            'is_complete' => $isComplete,
            'mode' => $mode->value,
            'threshold' => $threshold,
        ];
    }

    /**
     * Get completion mode for edition.
     */
    public function getCompletionMode(int $editionId): CompletionMode
    {
        $modeValue = get_post_meta($editionId, '_vad_completion_mode', true);

        return CompletionMode::tryFrom($modeValue) ?? CompletionMode::AttendAll;
    }

    /**
     * Get completion threshold for edition.
     *
     * For percentage mode: 0-100
     * For count mode: minimum sessions
     * For attend_all: ignored
     */
    public function getCompletionThreshold(int $editionId): int
    {
        $threshold = get_post_meta($editionId, '_vad_completion_threshold', true);

        return $threshold ? (int) $threshold : 100;
    }

    // === Process Completion ===

    /**
     * Process completion for user in edition.
     *
     * Checks attendance and triggers LearnDash course completion if requirements met.
     */
    public function processCompletion(int $editionId, int $userId): true|WP_Error
    {
        if (!$this->isComplete($editionId, $userId)) {
            return new WP_Error('not_complete', 'User has not met completion requirements');
        }

        $edition = $this->editionService->getEdition($editionId);

        if (!$edition) {
            return new WP_Error('invalid_edition', 'Edition not found');
        }

        $courseId = $edition['course_id'];

        if (!$courseId) {
            return new WP_Error('no_course', 'Edition has no linked course');
        }

        // Check if already complete in LearnDash
        if ($this->lmsAdapter->isComplete($userId, $courseId)) {
            return true;
        }

        // Mark complete in LearnDash
        // Note: LearnDash uses learndash_process_mark_complete() for this
        if (function_exists('learndash_process_mark_complete')) {
            learndash_process_mark_complete($userId, $courseId);
        }

        do_action('stride/completion/completed', [
            'edition_id' => $editionId,
            'user_id' => $userId,
            'course_id' => $courseId,
        ]);

        return true;
    }

    /**
     * Handle attendance marked event.
     *
     * @param array<string, mixed> $data
     */
    public function onAttendanceMarked(array $data): void
    {
        $editionId = $data['edition_id'] ?? 0;
        $userId = $data['user_id'] ?? 0;

        if ($editionId && $userId && $this->isComplete($editionId, $userId)) {
            $this->processCompletion($editionId, $userId);
        }
    }

    // === Configuration ===

    /**
     * Set completion mode for edition.
     */
    public function setCompletionMode(int $editionId, CompletionMode $mode): void
    {
        update_post_meta($editionId, '_vad_completion_mode', $mode->value);
    }

    /**
     * Set completion threshold for edition.
     */
    public function setCompletionThreshold(int $editionId, int $threshold): void
    {
        update_post_meta($editionId, '_vad_completion_threshold', $threshold);
    }
}
```

**Step 2: Verify syntax**

Run: `ddev exec bash -c 'php -l web/app/mu-plugins/stride-core/Modules/Completion/CompletionService.php'`
Expected: `No syntax errors detected`

**Step 3: Register service in plugin-config.php**

In `plugin-config.php`, add to the services array after AttendanceService:

```php
\Stride\Modules\Completion\CompletionService::class,
```

**Step 4: Test instantiation**

Run: `ddev exec bash -c 'wp eval "ntdst_get(\\Stride\\Modules\\Completion\\CompletionService::class); echo \"OK\";"'`
Expected: `OK`

**Step 5: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Completion/CompletionService.php web/app/mu-plugins/stride-core/plugin-config.php
git commit -m "feat(completion): add CompletionService"
```

---

## Task 7: Test Script

**Files:**
- Create: `scripts/test-attendance-completion.php`

**Step 1: Create comprehensive test script**

```php
<?php
/**
 * Stride V1 - Attendance + Completion Tests
 *
 * Tests attendance recording, queries, and completion logic.
 *
 * Run with: ddev exec wp eval-file scripts/test-attendance-completion.php
 */

if (!defined('ABSPATH')) {
    echo "Run via WP-CLI: ddev exec wp eval-file scripts/test-attendance-completion.php\n";
    exit(1);
}

use Stride\Domain\AttendanceStatus;
use Stride\Domain\CompletionMode;
use Stride\Modules\Attendance\AttendanceService;
use Stride\Modules\Attendance\AttendanceRepository;
use Stride\Modules\Completion\CompletionService;
use Stride\Modules\Edition\EditionService;
use Stride\Modules\Edition\SessionService;

echo "=== Stride V1 - Attendance + Completion Tests ===" . PHP_EOL . PHP_EOL;

$attendanceService = ntdst_get(AttendanceService::class);
$attendanceRepo = ntdst_get(AttendanceRepository::class);
$completionService = ntdst_get(CompletionService::class);
$editionService = ntdst_get(EditionService::class);
$sessionService = ntdst_get(SessionService::class);

$created = ['editions' => [], 'sessions' => [], 'users' => []];
$GLOBALS['passed'] = 0;
$GLOBALS['failed'] = 0;

function assert_test(bool $condition, string $message): void {
    if ($condition) {
        echo "  [PASS] {$message}" . PHP_EOL;
        $GLOBALS['passed']++;
    } else {
        echo "  [FAIL] {$message}" . PHP_EOL;
        $GLOBALS['failed']++;
    }
}

wp_set_current_user(1);

try {
    // === A. SETUP: Create test edition with sessions ===
    echo "A. Setup..." . PHP_EOL;

    // A1. Create edition
    $editionId = $editionService->createEdition([
        'title' => 'Test Edition for Attendance',
        'course_id' => 0, // No LD course needed for testing
        'start_date' => date('Y-m-d'),
        'end_date' => date('Y-m-d', strtotime('+2 days')),
        'capacity' => 20,
    ]);
    assert_test(!is_wp_error($editionId), 'A1. Create edition');
    $created['editions'][] = $editionId;

    // A2-A4. Create 3 sessions
    for ($i = 1; $i <= 3; $i++) {
        $sessionId = $sessionService->createSession([
            'edition_id' => $editionId,
            'date' => date('Y-m-d', strtotime("+{$i} days")),
            'start_time' => '09:00',
            'end_time' => '17:00',
        ]);
        assert_test(!is_wp_error($sessionId), "A{$i}a. Create session {$i}");
        $created['sessions'][] = $sessionId;
    }

    // A5. Create test user
    $userId = wp_create_user('att_test_' . time(), 'pass123', 'att@test.local');
    $created['users'][] = $userId;
    assert_test($userId > 0, 'A5. Create test user');

    echo PHP_EOL;

    // === B. ATTENDANCE MARKING ===
    echo "B. Attendance Marking..." . PHP_EOL;

    $session1 = $created['sessions'][0];
    $session2 = $created['sessions'][1];
    $session3 = $created['sessions'][2];

    // B1. Mark present
    $result = $attendanceService->markPresent($session1, $userId);
    assert_test(!is_wp_error($result), 'B1. Mark present');

    // B2. Check is present
    $isPresent = $attendanceService->isPresent($session1, $userId);
    assert_test($isPresent === true, 'B2. Is present');

    // B3. Get status
    $status = $attendanceService->getStatus($session1, $userId);
    assert_test($status === AttendanceStatus::Present, 'B3. Status is present');

    // B4. Mark absent
    $result = $attendanceService->markAbsent($session2, $userId);
    assert_test(!is_wp_error($result), 'B4. Mark absent');

    // B5. Check absent status
    $status = $attendanceService->getStatus($session2, $userId);
    assert_test($status === AttendanceStatus::Absent, 'B5. Status is absent');

    // B6. Mark excused
    $result = $attendanceService->markExcused($session3, $userId);
    assert_test(!is_wp_error($result), 'B6. Mark excused');

    // B7. Check excused status
    $status = $attendanceService->getStatus($session3, $userId);
    assert_test($status === AttendanceStatus::Excused, 'B7. Status is excused');

    // B8. Update status (mark session 2 as present)
    $result = $attendanceService->markPresent($session2, $userId);
    assert_test(!is_wp_error($result), 'B8. Update attendance');

    $status = $attendanceService->getStatus($session2, $userId);
    assert_test($status === AttendanceStatus::Present, 'B8a. Updated status is present');

    echo PHP_EOL;

    // === C. ATTENDANCE QUERIES ===
    echo "C. Attendance Queries..." . PHP_EOL;

    // C1. Count attended
    $attended = $attendanceService->countAttended($userId, $editionId);
    assert_test($attended === 2, 'C1. Count attended = 2');

    // C2. Get attendees for session
    $attendees = $attendanceService->getAttendees($session1);
    assert_test(in_array($userId, $attendees), 'C2. User in session attendees');

    // C3. Get user edition attendance
    $attendance = $attendanceService->getUserEditionAttendance($userId, $editionId);
    assert_test(count($attendance) === 3, 'C3. Has 3 attendance records');

    // C4. Get attendance rate (2/3 = 66.67%)
    $rate = $attendanceService->getAttendanceRate($userId, $editionId);
    assert_test($rate >= 66 && $rate <= 67, 'C4. Attendance rate ~67%');

    // C5. Get session attendance
    $sessionAttendance = $attendanceService->getSessionAttendance($session1);
    assert_test(count($sessionAttendance) === 1, 'C5. Session has 1 record');

    echo PHP_EOL;

    // === D. COMPLETION MODE: ATTEND_ALL ===
    echo "D. Completion Mode: Attend All..." . PHP_EOL;

    // D1. Set attend_all mode (default)
    $completionService->setCompletionMode($editionId, CompletionMode::AttendAll);
    $mode = $completionService->getCompletionMode($editionId);
    assert_test($mode === CompletionMode::AttendAll, 'D1. Mode is attend_all');

    // D2. Not complete (2/3 sessions)
    $isComplete = $completionService->isComplete($editionId, $userId);
    assert_test(!$isComplete, 'D2. Not complete with 2/3 sessions');

    // D3. Mark session 3 as present
    $attendanceService->markPresent($session3, $userId);
    $isComplete = $completionService->isComplete($editionId, $userId);
    assert_test($isComplete, 'D3. Complete with 3/3 sessions');

    // D4. Get progress
    $progress = $completionService->getProgress($editionId, $userId);
    assert_test($progress['is_complete'] === true, 'D4. Progress shows complete');
    assert_test($progress['attended'] === 3, 'D4a. Progress shows 3 attended');

    echo PHP_EOL;

    // === E. COMPLETION MODE: PERCENTAGE ===
    echo "E. Completion Mode: Percentage..." . PHP_EOL;

    // E1. Set percentage mode with 50% threshold
    $completionService->setCompletionMode($editionId, CompletionMode::Percentage);
    $completionService->setCompletionThreshold($editionId, 50);
    $mode = $completionService->getCompletionMode($editionId);
    assert_test($mode === CompletionMode::Percentage, 'E1. Mode is percentage');

    // E2. Mark session 3 as absent (now 2/3 = 67%)
    $attendanceService->markAbsent($session3, $userId);
    $isComplete = $completionService->isComplete($editionId, $userId);
    assert_test($isComplete, 'E2. Complete with 67% (threshold 50%)');

    // E3. Set higher threshold (70%)
    $completionService->setCompletionThreshold($editionId, 70);
    $isComplete = $completionService->isComplete($editionId, $userId);
    assert_test(!$isComplete, 'E3. Not complete with 67% (threshold 70%)');

    echo PHP_EOL;

    // === F. COMPLETION MODE: COUNT ===
    echo "F. Completion Mode: Count..." . PHP_EOL;

    // F1. Set count mode with minimum 2 sessions
    $completionService->setCompletionMode($editionId, CompletionMode::Count);
    $completionService->setCompletionThreshold($editionId, 2);
    $mode = $completionService->getCompletionMode($editionId);
    assert_test($mode === CompletionMode::Count, 'F1. Mode is count');

    // F2. Complete with 2 sessions (threshold 2)
    $isComplete = $completionService->isComplete($editionId, $userId);
    assert_test($isComplete, 'F2. Complete with 2/3 (threshold 2)');

    // F3. Set higher threshold (3)
    $completionService->setCompletionThreshold($editionId, 3);
    $isComplete = $completionService->isComplete($editionId, $userId);
    assert_test(!$isComplete, 'F3. Not complete with 2/3 (threshold 3)');

    echo PHP_EOL;

    // === G. BULK OPERATIONS ===
    echo "G. Bulk Operations..." . PHP_EOL;

    // Create second user
    $userId2 = wp_create_user('att_test2_' . time(), 'pass123', 'att2@test.local');
    $created['users'][] = $userId2;

    // G1. Mark multiple users present
    $results = $attendanceService->markMultiplePresent($session1, [$userId, $userId2]);
    assert_test(count($results) === 2, 'G1. Marked 2 users');
    assert_test(!is_wp_error($results[$userId2]), 'G1a. Second user marked');

    // G2. Get attendees
    $attendees = $attendanceService->getAttendees($session1);
    assert_test(count($attendees) === 2, 'G2. Session has 2 attendees');

    echo PHP_EOL;

} catch (Exception $e) {
    echo "[FATAL] " . $e->getMessage() . PHP_EOL;
    echo $e->getTraceAsString() . PHP_EOL;
}

// Cleanup
echo "Cleaning up..." . PHP_EOL;

// Delete attendance records
global $wpdb;
foreach ($created['sessions'] as $sessionId) {
    $wpdb->delete($wpdb->prefix . 'vad_attendance', ['session_id' => $sessionId]);
}

// Delete sessions and editions
foreach ($created['sessions'] as $id) {
    wp_delete_post($id, true);
}
foreach ($created['editions'] as $id) {
    wp_delete_post($id, true);
}

// Delete users
require_once ABSPATH . 'wp-admin/includes/user.php';
foreach ($created['users'] as $id) {
    wp_delete_user($id);
}

$passed = $GLOBALS['passed'];
$failed = $GLOBALS['failed'];

echo PHP_EOL . "=== Results ===" . PHP_EOL;
echo "Passed: {$passed}" . PHP_EOL;
echo "Failed: {$failed}" . PHP_EOL;
echo ($failed === 0 ? "ALL TESTS PASSED!" : "SOME TESTS FAILED") . PHP_EOL;
```

**Step 2: Run tests**

Run: `ddev exec wp eval-file scripts/test-attendance-completion.php`
Expected: All tests pass (PASS for each assertion)

**Step 3: Commit**

```bash
git add scripts/test-attendance-completion.php
git commit -m "test(attendance): add attendance and completion test script"
```

---

## Summary

This plan implements:

1. **Task 1**: AttendanceStatus enum (present/absent/excused)
2. **Task 2**: CompletionMode enum (attend_all/percentage/count)
3. **Task 3**: AttendanceTable with proper schema and indexes
4. **Task 4**: AttendanceRepository for data access
5. **Task 5**: AttendanceService for business logic and action hooks
6. **Task 6**: CompletionService for completion rules and LearnDash integration
7. **Task 7**: Comprehensive test script

Total: 7 tasks, each with clear steps and verification.

### Files Created
- `Domain/AttendanceStatus.php`
- `Domain/CompletionMode.php`
- `Modules/Attendance/AttendanceTable.php`
- `Modules/Attendance/AttendanceRepository.php`
- `Modules/Attendance/AttendanceService.php`
- `Modules/Completion/CompletionService.php`
- `scripts/test-attendance-completion.php`

### Files Modified
- `stride-core.php` (table creation + DI registration)
- `plugin-config.php` (service registration)
