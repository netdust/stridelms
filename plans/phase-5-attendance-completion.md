# Phase 5: Attendance & Completion

## Overview

Build the completion engine that determines when a user has finished an edition based on attendance, then triggers LearnDash course completion and certificate availability.

**Dependencies:** SessionService ✅, EditionService ✅, CourseService ✅, RegistrationRepository ✅

---

## Acceptance Criteria

- [ ] Attendance stored in dedicated table (not postmeta)
- [ ] CompletionEngine determines edition completion based on attendance rules
- [ ] Completion triggers LearnDash `markComplete()`
- [ ] Certificate merge tags include Edition-specific data
- [ ] Existing tests pass after migration

---

## Implementation

### 5.1 Attendance Table Migration

**Why:** Current JSON postmeta doesn't support concurrent check-ins, audit trails, or efficient queries.

**File:** `core/AttendanceRepository.php` (NEW)

```php
class AttendanceRepository
{
    public const TABLE = 'vad_attendance';

    // Status constants
    public const STATUS_PRESENT = 'present';
    public const STATUS_ABSENT = 'absent';
    public const STATUS_EXCUSED = 'excused';

    public function createTable(): void;
    public function mark(int $sessionId, int $userId, string $status, ?int $markedBy = null): bool;
    public function getStatus(int $sessionId, int $userId): ?string;
    public function getAttendeesForSession(int $sessionId): array;
    public function getAttendanceForUser(int $userId, int $editionId): array;
    public function batchMark(int $sessionId, array $userStatuses, ?int $markedBy = null): int;
}
```

**Table Schema:**
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
  INDEX idx_user_edition (user_id, edition_id),
  UNIQUE KEY unique_session_user (session_id, user_id)
);
```

**Migration:** `scripts/migrate-attendance.php`
- Copy existing `_vad_attendees` postmeta to new table
- Validate row counts match
- Keep postmeta as backup until verified

### 5.2 Update SessionService

**File:** `core/SessionService.php`

Update attendance methods to use `AttendanceRepository`:

```php
public function markPresent(int $sessionId, int $userId): true|WP_Error
{
    // Delegate to AttendanceRepository
    $repo = ntdst_get(AttendanceRepository::class);
    return $repo->mark($sessionId, $userId, AttendanceRepository::STATUS_PRESENT, get_current_user_id());
}

public function getAttendees(int $sessionId): array
{
    $repo = ntdst_get(AttendanceRepository::class);
    return $repo->getAttendeesForSession($sessionId);
}
```

### 5.3 CompletionEngine

**File:** `core/CompletionEngine.php` (NEW)

```php
class CompletionEngine implements \NTDST_Service_Meta
{
    private SessionService $sessionService;
    private EditionService $editionService;
    private CourseService $courseService;

    // Completion modes (stored in edition meta)
    public const MODE_ATTEND_ALL = 'attend_all';      // 100% attendance
    public const MODE_ATTEND_PERCENTAGE = 'attend_percentage';  // e.g., 80%
    public const MODE_ATTEND_COUNT = 'attend_count';  // e.g., 5 of 8 sessions

    /**
     * Check if user completed edition based on attendance rules
     */
    public function isEditionComplete(int $editionId, int $userId): bool
    {
        $sessions = $this->sessionService->getSessionsForEdition($editionId);
        $attended = $this->countAttendedSessions($editionId, $userId, $sessions);

        $mode = $this->editionService->getMeta($editionId, 'completion_mode') ?: self::MODE_ATTEND_ALL;
        $threshold = $this->editionService->getMeta($editionId, 'completion_threshold') ?: 100;

        return match ($mode) {
            self::MODE_ATTEND_ALL => $attended === count($sessions),
            self::MODE_ATTEND_PERCENTAGE => ($attended / count($sessions) * 100) >= $threshold,
            self::MODE_ATTEND_COUNT => $attended >= $threshold,
            default => $attended === count($sessions),
        };
    }

    /**
     * Process completion - called after attendance is marked
     * Triggers LearnDash course completion if edition is complete
     */
    public function processCompletion(int $editionId, int $userId): bool
    {
        if (!$this->isEditionComplete($editionId, $userId)) {
            return false;
        }

        $courseId = $this->editionService->getLinkedCourseId($editionId);
        if (!$courseId) {
            return false;
        }

        // Mark LearnDash course as complete
        $this->courseService->markComplete($userId, $courseId);

        do_action('stride/edition/completed', $editionId, $userId);

        return true;
    }

    /**
     * Check completion for all users in an edition
     * Called when admin marks bulk attendance
     */
    public function processEditionCompletions(int $editionId): array
    {
        $registrations = $this->getRegistrations($editionId);
        $results = [];

        foreach ($registrations as $reg) {
            $results[$reg['user_id']] = $this->processCompletion($editionId, $reg['user_id']);
        }

        return $results;
    }
}
```

### 5.4 CourseService.markComplete()

**File:** `core/CourseService.php`

Add method to trigger LearnDash completion:

```php
/**
 * Mark course as complete for user
 * Called by CompletionEngine when edition attendance requirements are met
 */
public function markComplete(int $userId, int $courseId): true|WP_Error
{
    if (!$this->isAvailable()) {
        return new WP_Error('learndash_unavailable', 'LearnDash not available');
    }

    // Use LearnDash API to mark complete
    $this->learndash->markComplete($userId, $courseId);

    do_action('stride/course/marked_complete', $userId, $courseId);

    return true;
}
```

**File:** `adapters/LearnDashAdapter.php`

```php
public function markComplete(int $userId, int $courseId): bool
{
    if (!function_exists('learndash_process_mark_complete')) {
        return false;
    }

    // LearnDash function to mark course complete
    learndash_process_mark_complete($userId, $courseId);
    return true;
}
```

### 5.5 Certificate Merge Tags

**File:** `smartcode/SmartCodeService.php`

Add edition-aware shortcodes for LearnDash certificates:

```php
// Register shortcodes
add_shortcode('stride_edition_dates', [$this, 'renderEditionDates']);
add_shortcode('stride_instructor', [$this, 'renderInstructor']);
add_shortcode('stride_venue', [$this, 'renderVenue']);
add_shortcode('stride_hours_attended', [$this, 'renderHoursAttended']);

public function renderEditionDates(array $atts): string
{
    $editionId = $this->getCurrentEditionId();
    if (!$editionId) return '';

    $edition = $this->editionService->getEdition($editionId);
    return sprintf('%s - %s', $edition['start_date'], $edition['end_date']);
}
```

**Hook into LearnDash certificate generation:**
```php
add_filter('learndash_certificate_details', function($details, $userId, $courseId) {
    // Inject current edition context for shortcode processing
    $editionId = $this->findUserEdition($userId, $courseId);
    $this->setCurrentEditionId($editionId);
    return $details;
}, 10, 3);
```

### 5.6 Edition Fields

**File:** `FieldRegistry.php`

Add completion fields:

```php
public const EDITION_COMPLETION_MODE = '_vad_completion_mode';
public const EDITION_COMPLETION_THRESHOLD = '_vad_completion_threshold';
```

**File:** `core/EditionService.php`

Register fields in model schema.

---

## Files to Modify/Create

| File | Action |
|------|--------|
| `core/AttendanceRepository.php` | NEW |
| `core/CompletionEngine.php` | NEW |
| `core/SessionService.php` | Update attendance methods |
| `core/CourseService.php` | Add markComplete() |
| `adapters/LearnDashAdapter.php` | Add markComplete() |
| `smartcode/SmartCodeService.php` | Add certificate shortcodes |
| `FieldRegistry.php` | Add completion fields |
| `scripts/migrate-attendance.php` | NEW - migration script |

---

## Verification

```bash
# 1. Run migration
ddev exec wp eval-file scripts/migrate-attendance.php

# 2. Verify table created
ddev exec wp db query "DESCRIBE wp_vad_attendance"

# 3. Verify data migrated
ddev exec wp db query "SELECT COUNT(*) FROM wp_vad_attendance"

# 4. Test completion flow
ddev exec wp eval '
use ntdst\Stride\core\CompletionEngine;
use ntdst\Stride\core\SessionService;

$engine = ntdst_get(CompletionEngine::class);
$session = ntdst_get(SessionService::class);

// Mark attendance for test user
$session->markPresent(SESSION_ID, USER_ID);

// Check completion
$complete = $engine->isEditionComplete(EDITION_ID, USER_ID);
echo $complete ? "COMPLETE" : "INCOMPLETE";
'

# 5. Run existing tests
ddev exec wp eval-file scripts/test-voucher.php
```

---

## Hooks Reference

| Hook | Type | Purpose |
|------|------|---------|
| `stride/session/attendance_marked` | action | After attendance status changes |
| `stride/edition/completed` | action | After edition marked complete |
| `stride/course/marked_complete` | action | After LearnDash course completed |
| `stride/completion/before_process` | filter | Override completion check |
