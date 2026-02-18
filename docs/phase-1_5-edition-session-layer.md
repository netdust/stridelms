# Phase 1.5: Edition/Session Layer

**Status:** In progress
**Complexity:** MORE (multi-file changes, new services, architectural shift)
**Dependencies:** Phase 0 & 1 complete (NTDST Core, CourseService, QuoteService, VoucherService)

---

## Progress

| Step | Status | Notes |
|------|--------|-------|
| 1. FieldRegistry constants | ⬜ | |
| 2. EditionService | ⬜ | |
| 3. SessionService | ⬜ | |
| 4. RegistrationRepository | ⬜ | |
| 5. CourseService slim-down | ⬜ | Deprecate, don't delete |
| 6. EnrollmentService refactor | ⬜ | |
| 7. FormSubmissionHandler refactor | ⬜ | |
| 8. EnrollmentQuoteHandler refactor | ⬜ | |
| 9. Service registration | ⬜ | |
| 10. Database migration | ⬜ | |

### --- SAFE CHECKPOINT: Steps 1-4 ---
New services work independently, old code still functions.
Steps 5-8 are rewiring — do as a batch.

---

## Parking Lot
<!-- Out-of-scope items discovered during implementation -->

---

## Mental Model

```
Course (LD)  = the WHAT    — curriculum, lessons, quizzes, certificate. Exists once.
Edition      = the OFFER   — when, where, how much, who teaches, how many spots.
Session      = the PROGRAM — one participation unit. Something the user does and gets tracked on.
```

**Course** owns no scheduling metadata — just content text and LD lessons for online delivery.

**Edition** owns all business data for a specific offering of a course — price, capacity, venue, speakers, status. An edition links to exactly one LD course.

**Session** is one line in the program. Title + description give it meaning. The type determines how completion is tracked:

| Type | What it is | Scheduled | Completion | Location field | LD link |
|------|-----------|-----------|------------|----------------|---------|
| `in_person` | Physical session | date + time | admin checkbox | venue | — |
| `webinar` | Live online session | date + time | admin checkbox | meeting link | — |
| `online` | Self-paced LD lesson | deadline | LD auto-tracks | — | lesson_id |
| `assignment` | LD quiz or exercise | deadline | LD auto-tracks | — | lesson_id |

**Granularity rule:** if you need separate attendance/completion tracking, it's a separate session. If not, put it in the description.

**Example — hybrid edition:**
```
Edition: "Vorming Drugs Basis, Voorjaar 2026"

Sessie 1: Introductie & Kader              [in_person]   15 mrt, 9:00-17:00, VAD Brussel
Sessie 2: Casestudie Alcohol               [online]      voor 1 apr → LD lesson 45
Sessie 3: Casestudie Cannabis              [online]      voor 1 apr → LD lesson 46
Sessie 4: Reflectieopdracht               [assignment]   voor 10 apr → LD quiz 50
Sessie 5: Terugkomdag                      [in_person]   12 apr, 9:00-13:00, VAD Brussel
```

**Example — simple 2-day course (80% of editions):**
```
Edition: "Motiverende Gespreksvoering, Oktober 2026"

Sessie 1: Dag 1 — Basisprincipes           [in_person]   14 okt, 9:00-17:00, VAD Brussel
  Description: "09:00 Ontvangst, 09:30 Introductie, 10:30 Pauze, ..."

Sessie 2: Dag 2 — Verdieping & Oefeningen  [in_person]   15 okt, 9:00-16:00, VAD Brussel
  Description: "09:00 Recap, 09:30 Rollenspel, ..."
```

**Example — webinar:**
```
Edition: "Webinar Nieuwe Wetgeving, Juni 2026"

Sessie 1: Live webinar                     [webinar]     5 jun, 14:00-16:00
  Location: "Zoom (link volgt via e-mail)"
```

---

## Problem/Feature

The current architecture stores course scheduling data (dates, capacity, venue, pricing, status) directly on LearnDash course posts. This makes it impossible to:
- Offer the same course multiple times with different dates/venues
- Track attendance per session
- Support hybrid courses (in-person + online + assignments)
- Auto-complete based on LD lesson progress

---

## Acceptance Criteria

- [ ] `vad_edition` CPT registered via DataManager with all required fields
- [ ] `vad_session` CPT registered via DataManager with 4 types (in_person, webinar, online, assignment)
- [ ] `wp_vad_registrations` table created for high-volume user-edition records
- [ ] CourseService trimmed to ~10 LearnDash-only methods (deprecated wrappers for old methods)
- [ ] EditionService provides all date/capacity/venue/pricing/status methods
- [ ] SessionService provides session queries, type-aware completion, and attendance
- [ ] `isSessionComplete()` returns correct result per type (attendance check vs LD lesson check)
- [ ] FieldRegistry updated with EDITION_* and SESSION_* constants
- [ ] EnrollmentService refactored to accept editionId instead of courseId
- [ ] FormSubmissionHandler extracts edition_id from form submissions
- [ ] Existing hooks maintain backward compatibility during transition

---

## Implementation Plan

### Step 1: Create FieldRegistry Constants

**File:** `services/FieldRegistry.php`

```php
// Edition fields
public const EDITION_COURSE_ID = 'course_id';
public const EDITION_START_DATE = 'start_date';
public const EDITION_END_DATE = 'end_date';
public const EDITION_CAPACITY = 'capacity';
public const EDITION_PRICE = 'price';
public const EDITION_PRICE_NON_MEMBER = 'price_non_member';
public const EDITION_VENUE = 'venue';
public const EDITION_SPEAKERS = 'speakers';
public const EDITION_STATUS = 'status';
public const EDITION_INVOICE_ITEM = 'invoice_item';
public const EDITION_INVOICE_ENABLED = 'invoice_enabled';
public const EDITION_CERTIFICATE_ENABLED = 'certificate_enabled';
public const EDITION_CUSTOM_FORM = 'custom_form';

// Edition status values
public const EDITION_STATUS_OPEN = 'open';
public const EDITION_STATUS_FULL = 'full';
public const EDITION_STATUS_CANCELLED = 'cancelled';
public const EDITION_STATUS_POSTPONED = 'postponed';
public const EDITION_STATUS_ANNOUNCEMENT = 'announcement';
public const EDITION_STATUS_COMPLETED = 'completed';

// Session fields
public const SESSION_EDITION_ID = 'edition_id';
public const SESSION_TYPE = 'type';
public const SESSION_SORT_ORDER = 'sort_order';
public const SESSION_DESCRIPTION = 'description';

// Session: in_person + webinar fields
public const SESSION_DATE = 'date';
public const SESSION_START_TIME = 'start_time';
public const SESSION_END_TIME = 'end_time';
public const SESSION_LOCATION = 'location';

// Session: online + assignment fields
public const SESSION_DEADLINE = 'deadline';
public const SESSION_LESSON_ID = 'lesson_id';

// Session: attendance (in_person + webinar only)
public const SESSION_ATTENDEES = 'attendees';

// Session type values
public const SESSION_TYPE_IN_PERSON = 'in_person';
public const SESSION_TYPE_WEBINAR = 'webinar';
public const SESSION_TYPE_ONLINE = 'online';
public const SESSION_TYPE_ASSIGNMENT = 'assignment';
```

---

### Step 2: Create EditionService

**File:** `services/core/EditionService.php` (new, ~400 lines)

Register `vad_edition` CPT via DataManager and implement all edition-specific methods.

```php
<?php
namespace stride\services\core;

class EditionService implements \NTDST_Service_Meta
{
    public const POST_TYPE = 'vad_edition';

    public static function metadata(): array
    {
        return [
            'name' => 'Edition Service',
            'description' => 'Scheduled course offerings with dates, capacity, pricing',
            'admin_only' => false,
            'enabled' => true,
            'priority' => 5,
        ];
    }

    public function __construct()
    {
        add_action('init', [$this, 'registerModel'], 5);
    }

    public function registerModel(): void
    {
        ntdst_data()->register(self::POST_TYPE, [
            'label' => __('Edities', 'stride'),
            'labels' => [
                'singular_name' => __('Editie', 'stride'),
                'add_new_item' => __('Nieuwe editie', 'stride'),
                'edit_item' => __('Editie bewerken', 'stride'),
            ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => 'stride-admin',
            'supports' => ['title'],
            'fields' => [
                FieldRegistry::EDITION_COURSE_ID           => ['type' => 'integer', 'required' => true],
                FieldRegistry::EDITION_START_DATE           => ['type' => 'text', 'required' => true],
                FieldRegistry::EDITION_END_DATE             => ['type' => 'text'],
                FieldRegistry::EDITION_CAPACITY             => ['type' => 'integer', 'min' => 0],
                FieldRegistry::EDITION_PRICE                => ['type' => 'float', 'min' => 0],
                FieldRegistry::EDITION_PRICE_NON_MEMBER     => ['type' => 'float', 'min' => 0],
                FieldRegistry::EDITION_VENUE                => ['type' => 'text'],
                FieldRegistry::EDITION_SPEAKERS             => ['type' => 'text'],
                FieldRegistry::EDITION_STATUS               => ['type' => 'text', 'default' => 'open'],
                FieldRegistry::EDITION_INVOICE_ITEM         => ['type' => 'text'],
                FieldRegistry::EDITION_INVOICE_ENABLED      => ['type' => 'boolean', 'default' => true],
                FieldRegistry::EDITION_CERTIFICATE_ENABLED  => ['type' => 'boolean', 'default' => false],
                FieldRegistry::EDITION_CUSTOM_FORM          => ['type' => 'text'],
            ],
            'auto_metabox' => true,
        ]);
    }

    // --- Core queries ---

    public function getEdition(int $editionId): ?array;
    public function getEditionsForCourse(int $courseId): array;
    public function getUpcomingEditions(int $limit = 20): array;
    public function getUpcomingEditionsForCourse(int $courseId): array;
    public function getLinkedCourseId(int $editionId): ?int;

    // --- Dates (moved from CourseService) ---

    public function getStartDate(int $editionId): ?string;
    public function getEndDate(int $editionId): ?string;
    public function hasStarted(int $editionId): bool;
    public function hasEnded(int $editionId): bool;

    // --- Status (moved from CourseService) ---

    public function getStatus(int $editionId): string;
    public function isCancelled(int $editionId): bool;
    public function isPostponed(int $editionId): bool;
    public function isFull(int $editionId): bool;
    public function isAnnouncement(int $editionId): bool;
    public function isUpcoming(int $editionId): bool;
    public function isEnrollmentOpen(int $editionId): bool;

    // --- Capacity (uses registrations table) ---

    public function getCapacity(int $editionId): ?int;
    public function getRegisteredCount(int $editionId): int;
    public function hasAvailableSpots(int $editionId): bool;
    public function getAvailableSpots(int $editionId): int;

    // --- Pricing (moved from CourseService) ---

    public function getPrice(int $editionId): ?float;
    public function getPriceNonMember(int $editionId): ?float;
    public function getInvoiceItem(int $editionId): ?string;
    public function isInvoiceEnabled(int $editionId): bool;
    public function isCertificateEnabled(int $editionId): bool;

    // --- Speakers, venue, form ---

    public function getSpeakers(int $editionId): array;
    public function getVenue(int $editionId): ?string;
    public function getCustomForm(int $editionId): ?string;

    // --- Enrollment validation ---

    public function canUserEnroll(int $userId, int $editionId): true|WP_Error;
}
```

**Implementation notes:**
- Use `$this->getModel()->find($editionId)` for single lookup
- Use `$this->getModel()->where(...)->get()` for filtered queries
- `getRegisteredCount()` queries `wp_vad_registrations` table, not CPT meta
- `canUserEnroll()` checks: not already registered, not cancelled, not ended, capacity available, not announcement

---

### Step 3: Create SessionService

**File:** `services/core/SessionService.php` (new, ~300 lines)

Register `vad_session` CPT with type-aware fields and implement completion logic per type.

```php
<?php
namespace stride\services\core;

class SessionService implements \NTDST_Service_Meta
{
    public const POST_TYPE = 'vad_session';

    public static function metadata(): array
    {
        return [
            'name' => 'Session Service',
            'description' => 'Program sessions with type-aware completion tracking',
            'admin_only' => false,
            'enabled' => true,
            'priority' => 5,
        ];
    }

    public function __construct()
    {
        add_action('init', [$this, 'registerModel'], 5);
    }

    public function registerModel(): void
    {
        ntdst_data()->register(self::POST_TYPE, [
            'label' => __('Sessies', 'stride'),
            'labels' => [
                'singular_name' => __('Sessie', 'stride'),
            ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => 'stride-admin',
            'supports' => ['title'],
            'fields' => [
                // Core
                FieldRegistry::SESSION_EDITION_ID   => ['type' => 'integer', 'required' => true],
                FieldRegistry::SESSION_TYPE          => ['type' => 'text', 'default' => 'in_person'],
                FieldRegistry::SESSION_SORT_ORDER    => ['type' => 'integer', 'default' => 0],
                FieldRegistry::SESSION_DESCRIPTION   => ['type' => 'textarea'],

                // In-person + webinar: scheduled time
                FieldRegistry::SESSION_DATE          => ['type' => 'text'],
                FieldRegistry::SESSION_START_TIME    => ['type' => 'text'],
                FieldRegistry::SESSION_END_TIME      => ['type' => 'text'],
                FieldRegistry::SESSION_LOCATION      => ['type' => 'text'],

                // Online + assignment: self-paced
                FieldRegistry::SESSION_DEADLINE      => ['type' => 'text'],
                FieldRegistry::SESSION_LESSON_ID     => ['type' => 'integer'],

                // Attendance (in_person + webinar)
                FieldRegistry::SESSION_ATTENDEES     => ['type' => 'json', 'default' => []],
            ],
            'auto_metabox' => true,
        ]);
    }

    // --- Session queries ---

    public function getSession(int $sessionId): ?array;
    public function getSessionsForEdition(int $editionId): array;  // ordered by sort_order
    public function getSessionCount(int $editionId): int;
    public function getNextSession(int $sessionId): ?array;        // next by sort_order in same edition

    // --- Type helpers ---

    public function getType(int $sessionId): string;
    public function isScheduled(int $sessionId): bool;     // in_person or webinar
    public function isSelfPaced(int $sessionId): bool;     // online or assignment
    public function hasLessonLink(int $sessionId): bool;   // lesson_id > 0
    public function getLinkedLessonId(int $sessionId): ?int;

    // --- Type-aware completion ---

    /**
     * Check if a user has completed this session.
     *
     * in_person / webinar → admin marked present (attendees array)
     * online / assignment → linked LD lesson/quiz is complete
     */
    public function isSessionComplete(int $sessionId, int $userId): bool
    {
        $type = $this->getType($sessionId);

        return match ($type) {
            'in_person', 'webinar' => $this->isPresent($sessionId, $userId),
            'online', 'assignment' => $this->isLessonComplete($sessionId, $userId),
            default => false,
        };
    }

    // --- Attendance (in_person + webinar) ---

    public function markPresent(int $sessionId, int $userId): void;
    public function markAbsent(int $sessionId, int $userId): void;
    public function isPresent(int $sessionId, int $userId): bool;
    public function getAttendees(int $sessionId): array;

    // --- LD lesson completion (online + assignment) ---

    /**
     * Check if the linked LD lesson/quiz is complete for this user.
     */
    private function isLessonComplete(int $sessionId, int $userId): bool
    {
        $lessonId = $this->getLinkedLessonId($sessionId);
        if (!$lessonId) {
            return false;
        }

        // Get the course ID via edition → course link
        $session = $this->getSession($sessionId);
        $editionId = $session['edition_id'] ?? 0;
        $courseId = ntdst_get(EditionService::class)->getLinkedCourseId($editionId);

        if (!$courseId) {
            return false;
        }

        return learndash_is_lesson_complete($userId, $lessonId, $courseId);
    }

    // --- Hours calculation (in_person + webinar only) ---

    public function getSessionDuration(int $sessionId): float;  // hours from start_time/end_time
    public function getHoursAttended(int $userId, int $editionId): float;
    public function getAttendanceRate(int $userId, int $editionId): float;  // 0.0-1.0

    // --- Edition-level completion ---

    /**
     * Check if user has completed ALL sessions in an edition.
     */
    public function isEditionComplete(int $editionId, int $userId): bool
    {
        $sessions = $this->getSessionsForEdition($editionId);

        foreach ($sessions as $session) {
            if (!$this->isSessionComplete($session['id'], $userId)) {
                return false;
            }
        }

        return count($sessions) > 0;
    }

    /**
     * Get completion progress for a user in an edition.
     */
    public function getEditionProgress(int $editionId, int $userId): array
    {
        $sessions = $this->getSessionsForEdition($editionId);
        $completed = 0;

        foreach ($sessions as $session) {
            if ($this->isSessionComplete($session['id'], $userId)) {
                $completed++;
            }
        }

        $total = count($sessions);

        return [
            'total' => $total,
            'completed' => $completed,
            'percentage' => $total > 0 ? round($completed / $total * 100) : 0,
        ];
    }
}
```

**Key implementation notes:**
- Sessions ordered by `sort_order` — admin drags them into program sequence
- `isSessionComplete()` is the single entry point for all completion checks
- `isEditionComplete()` lives here (not a separate CompletionEngine) since it's just "all sessions done"
- Attendees stored as JSON array: `[42, 56, 78]`
- `markPresent()` fires `do_action('stride/session/marked_present', $sessionId, $userId)` for future hooks (e.g. unlocking LD drip content)

---

### Step 4: Create RegistrationRepository

**File:** `services/core/RegistrationRepository.php` (new, ~250 lines)

Custom table for high-volume registration data.

```php
<?php
namespace stride\services\core;

class RegistrationRepository
{
    public const TABLE_NAME = 'vad_registrations';

    // Table setup
    public static function createTable(): void;
    public static function getTableName(): string;

    // CRUD
    public function create(array $data): int|WP_Error;
    public function get(int $registrationId): ?array;
    public function update(int $registrationId, array $data): true|WP_Error;
    public function delete(int $registrationId): true|WP_Error;

    // Queries
    public function findByUserAndEdition(int $userId, int $editionId): ?array;
    public function getByEdition(int $editionId, ?string $status = null): array;
    public function getByUser(int $userId, ?string $status = null): array;
    public function countByEdition(int $editionId, ?string $status = null): int;

    // Status changes
    public function confirm(int $registrationId): true|WP_Error;
    public function cancel(int $registrationId): true|WP_Error;
    public function waitlist(int $registrationId): true|WP_Error;
}
```

**Table schema:**
```sql
CREATE TABLE wp_vad_registrations (
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
    PRIMARY KEY (id),
    KEY idx_user (user_id),
    KEY idx_edition (edition_id),
    KEY idx_status (status),
    UNIQUE KEY unique_user_edition (user_id, edition_id)
);
```

**Implementation notes:**
- Use `$wpdb->prepare()` for all queries
- UNIQUE constraint prevents duplicate registrations
- Index on edition_id for fast capacity counts

---

### --- SAFE CHECKPOINT: Steps 1-4 complete ---
### New services work independently, old code still functions.
### Steps 5-8 are rewiring — do as a batch.

---

### Step 5: Slim Down CourseService (deprecate, don't delete)

**File:** `services/core/CourseService.php` (reduce from 1034 lines to ~300)

**Keep these methods (LearnDash content only):**
```php
// LD availability
public function isAvailable(): bool;

// Course type detection (content-level, uses LD categories)
public function isInPerson(int $courseId): bool;
public function isOnline(int $courseId): bool;
public function isTraject(int $courseId): bool;

// Content queries
public function getCourse(int $courseId): ?WP_Post;
public function getCourseTitle(int $courseId): ?string;
public function validateCourse(int $courseId): true|WP_Error;
public function getCourseModules(int $courseId): array;
public function isModuleCourse(int $courseId): bool;

// Group/trajectory queries
public function getGroup(int $groupId): ?WP_Post;
public function getGroupTitle(int $groupId): ?string;
public function validateGroup(int $groupId): true|WP_Error;

// LearnDash integration (4 points)
public function grantAccess(int $userId, int $courseId): bool;
public function revokeAccess(int $userId, int $courseId): bool;
public function isComplete(int $userId, int $courseId): bool;
public function getCertificateLink(int $userId, int $courseId): ?string;

// User queries (still needed)
public function isUserEnrolled(int $userId, int $courseId): bool;
public function getEnrolledUsers(int $courseId): array;

// Authorization
public function currentUserCanManage(): bool;
```

**Deprecate these methods (moved to EditionService):**
Add `@deprecated` wrapper that delegates to EditionService. Log deprecation warning. Don't delete.

```php
/**
 * @deprecated Use EditionService::getStartDate() instead
 */
public function getStartDate(int $courseId): ?string
{
    _deprecated_function(__METHOD__, '4.0', 'EditionService::getStartDate()');
    $editions = ntdst_get(EditionService::class)->getUpcomingEditionsForCourse($courseId);
    return $editions[0]['start_date'] ?? null;
}
```

Methods to deprecate:
- `getCourseDates()`, `getStartDate()`, `getEndDate()`, `getNextDate()`
- `hasStarted()`, `hasEnded()`, `getDayCount()`
- `isCancelled()`, `isPostponed()`, `isFull()`, `isAnnouncement()`
- `isUpcoming()`, `isEnrollmentOpen()`
- `getCapacity()`, `getEnrolledCount()`, `hasAvailableSpots()`, `getAvailableSpots()`
- `getCourseSpeakers()`, `getCoursePrice()`, `getInvoiceItem()`, `getCourseAddress()`
- `isInvoiceEnabled()`, `isCertificateEnabled()`, `getCustomForm()`
- `enrollUser()`, `unenrollUser()`, `canUserEnroll()`

---

### Step 6: Refactor EnrollmentService

**File:** `services/enrollment/EnrollmentService.php`

Change signature from `enrollUser($userId, $courseId, ...)` to `enrollUser($userId, $editionId, ...)`.

```php
/**
 * Enroll a user in an edition
 */
public function enrollUser(int $userId, int $editionId, array $data = []): true|WP_Error
{
    // 1. Validate via EditionService
    $canEnroll = $this->editionService->canUserEnroll($userId, $editionId);
    if (is_wp_error($canEnroll)) {
        return $canEnroll;
    }

    // 2. Get linked course ID for LearnDash
    $courseId = $this->editionService->getLinkedCourseId($editionId);
    if (!$courseId) {
        return new WP_Error('no_course', __('Editie is niet gekoppeld aan een cursus.', 'stride'));
    }

    // 3. Allow pre-enrollment modification or abort
    $data = apply_filters('stride/enrollment/before_enroll', $data, $userId, $editionId);
    if (is_wp_error($data)) {
        return $data;
    }

    // 4. Sync profile and organization
    $this->syncProfile($userId, $data);
    $this->syncOrganization($userId, $data);

    // 5. Create registration record
    $registrationId = $this->registrationRepo->create([
        'user_id'         => $userId,
        'edition_id'      => $editionId,
        'status'          => 'confirmed',
        'enrollment_path' => $data['enrollment_path'] ?? 'individual',
        'enrolled_by'     => $data['enrolled_by_user_id'] ?? null,
        'voucher_code'    => $data['voucher_code'] ?? null,
    ]);

    if (is_wp_error($registrationId)) {
        return $registrationId;
    }

    // 6. Grant LearnDash course access
    $this->courseService->grantAccess($userId, $courseId);

    // 7. CRM audit note
    $this->createEnrollmentNote($userId, $editionId, $data);

    // 8. Fire hook with editionId
    do_action('stride/enrollment/completed', $userId, $editionId, $data);

    // 9. Backward compat: also fire with courseId
    do_action('stride/enrollment/completed_course', $userId, $courseId, $data);

    return true;
}
```

**New dependencies:**
```php
private EditionService $editionService;
private RegistrationRepository $registrationRepo;
```

---

### Step 7: Refactor FormSubmissionHandler

**File:** `services/enrollment/FormSubmissionHandler.php`

Extract `edition_id` instead of `course_id` from form submissions.

```php
/**
 * Extract edition ID from form data
 */
private function extractEditionId(array $formData): ?int
{
    $editionId = $formData['edition_id']
        ?? $formData['editie_id']
        ?? $formData['hidden_edition_id']
        ?? $formData['vad_edition_id']
        ?? null;

    return $editionId ? absint($editionId) : null;
}

/**
 * Legacy fallback: extract course_id and find next upcoming edition
 */
private function resolveEditionFromCourse(int $courseId): ?int
{
    $editions = ntdst_get(EditionService::class)
        ->getUpcomingEditionsForCourse($courseId);

    return $editions[0]['id'] ?? null;
}
```

Update `handleIndividual()` and `handleColleague()` to call `extractEditionId()` first, fall back to `resolveEditionFromCourse()` if not found.

---

### Step 8: Update EnrollmentQuoteHandler

**File:** `services/handlers/EnrollmentQuoteHandler.php`

Resolve price from edition, not course.

```php
/**
 * Handle enrollment completed hook
 */
public function onEnrollmentCompleted(int $userId, int $editionId, array $data): void
{
    $price = $this->editionService->getPrice($editionId);

    if ($price <= 0 && !$this->editionService->isInvoiceEnabled($editionId)) {
        return;
    }

    $this->quoteService->createQuoteForItem($userId, 'edition', $editionId, [
        'price'        => $price,
        'invoice_item' => $this->editionService->getInvoiceItem($editionId),
        'voucher_code' => $data['voucher_code'] ?? null,
    ]);
}
```

Also update filter handlers:
- `resolveItem()`: handle `'edition'` item type
- `resolvePrice()`: handle `'edition'` item type
- Keep `'course'` handlers for legacy quotes

---

### Step 9: Service Registration

**File:** `theme-config.php`

```php
'services' => [
    'core' => [
        // NEW: before CourseService for proper init order
        'stride\\services\\core\\EditionService',
        'stride\\services\\core\\SessionService',
        'stride\\services\\core\\RegistrationRepository',

        // Existing
        'stride\\services\\core\\HistoricalDataService',
        'stride\\services\\core\\CourseService',
        // ...
    ],
],
```

---

### Step 10: Database Migration

**File:** `services/core/RegistrationRepository.php` (static method)

```php
public static function createTable(): void
{
    global $wpdb;

    $table_name = $wpdb->prefix . self::TABLE_NAME;
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        user_id bigint(20) unsigned NOT NULL,
        edition_id bigint(20) unsigned NOT NULL,
        status enum('confirmed','cancelled','waitlist','interest') DEFAULT 'confirmed',
        enrollment_path enum('individual','colleague','trajectory','interest') DEFAULT 'individual',
        enrolled_by bigint(20) unsigned NULL,
        voucher_code varchar(50) NULL,
        quote_id bigint(20) unsigned NULL,
        registered_at datetime DEFAULT CURRENT_TIMESTAMP,
        cancelled_at datetime NULL,
        notes text NULL,
        PRIMARY KEY (id),
        KEY idx_user (user_id),
        KEY idx_edition (edition_id),
        KEY idx_status (status),
        UNIQUE KEY unique_user_edition (user_id, edition_id)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}
```

Register on theme activation:

```php
// In functions.php or theme setup
add_action('after_switch_theme', function() {
    \stride\services\core\RegistrationRepository::createTable();
});
```

---

## File Changes Summary

| File | Action | Lines |
|------|--------|-------|
| `services/FieldRegistry.php` | Edit | +40 |
| `services/core/EditionService.php` | Create | ~400 |
| `services/core/SessionService.php` | Create | ~300 |
| `services/core/RegistrationRepository.php` | Create | ~250 |
| `services/core/CourseService.php` | Refactor | keep ~300, deprecate rest |
| `services/enrollment/EnrollmentService.php` | Refactor | ~+50 |
| `services/enrollment/FormSubmissionHandler.php` | Refactor | ~+30 |
| `services/handlers/EnrollmentQuoteHandler.php` | Refactor | ~+20 |
| `theme-config.php` | Edit | +5 |
| `functions.php` | Edit | +5 |

**Net change:** ~+400 lines (deprecated wrappers in CourseService add some, but logic moves rather than duplicates)

---

## Testing Checklist

### Step 1-4 (independent — no existing code changes)
- [ ] EditionService CPT appears in admin under Stride menu
- [ ] SessionService CPT appears in admin under Stride menu
- [ ] Can create edition with all fields via admin
- [ ] Can create in_person session with date, time, location, description
- [ ] Can create webinar session with date, time, meeting link
- [ ] Can create online session with deadline + LD lesson link
- [ ] Can create assignment session with deadline + LD quiz link
- [ ] Sessions display in sort_order within edition
- [ ] `wp_vad_registrations` table created on activation
- [ ] `RegistrationRepository::create()` inserts record
- [ ] `RegistrationRepository::findByUserAndEdition()` returns correct result
- [ ] UNIQUE constraint prevents duplicate user+edition

### Step 5-8 (rewiring — do as batch)
- [ ] `EditionService::canUserEnroll()` validates correctly
- [ ] `EnrollmentService::enrollUser()` creates registration record
- [ ] `EnrollmentService::enrollUser()` grants LearnDash course access
- [ ] `SessionService::markPresent()` updates attendees array
- [ ] `SessionService::isSessionComplete()` returns true for attended in_person session
- [ ] `SessionService::isSessionComplete()` returns true for completed LD lesson (online session)
- [ ] `SessionService::isEditionComplete()` returns true when all sessions complete
- [ ] `EditionService::getRegisteredCount()` returns correct count from registrations table
- [ ] Quote created with item_type='edition' on enrollment
- [ ] Deprecated CourseService methods still work but log warnings
- [ ] `stride/enrollment/completed` hook fires with editionId
- [ ] `stride/enrollment/completed_course` BC hook fires with courseId

---

## References

- Project Plan: `docs/V4-PROJECT-PLAN.md` (Phase 1.5 section)
- DataManager API: `web/app/mu-plugins/ntdst-core/api/Data.php`
- QuoteService pattern: `services/invoicing/QuoteService.php` (CPT via DataManager reference)
- VoucherService pattern: `services/invoicing/VoucherService.php` (CPT via DataManager reference)
- Current CourseService: `services/core/CourseService.php`
- Current EnrollmentService: `services/enrollment/EnrollmentService.php`
