# Phase 1.5: Edition/Session Layer

**Status:** Ready for implementation
**Complexity:** MORE (multi-file changes, new services, architectural shift)
**Dependencies:** Phase 0 & 1 complete (NTDST Core, CourseService, QuoteService, VoucherService)

---

## Problem/Feature

The current architecture stores course scheduling data (dates, capacity, venue, pricing, status) directly on LearnDash course posts. This makes it impossible to offer the same course multiple times with different dates/venues (editions).

**The Edition Model:**
- **Course** = Content template (LearnDash) - lessons, quizzes, certificate settings
- **Edition** = Scheduled offering - dates, capacity, venue, pricing, speakers, status
- **Session** = Individual meeting day within an edition - for attendance tracking

This separation allows:
- Same course offered multiple times per year
- Different pricing/capacity per edition
- Session-based attendance tracking
- Proper completion logic (attend X sessions → complete)

---

## Acceptance Criteria

- [ ] `vad_edition` CPT registered via DataManager with all required fields
- [ ] `vad_session` CPT registered via DataManager for attendance
- [ ] `wp_vad_registrations` table created for high-volume user-edition records
- [ ] CourseService trimmed to ~10 LearnDash-only methods
- [ ] EditionService provides all date/capacity/venue/pricing/status methods
- [ ] SessionService provides session queries and attendance methods
- [ ] FieldRegistry updated with EDITION_* and SESSION_* constants
- [ ] EnrollmentService refactored to accept editionId instead of courseId
- [ ] FormSubmissionHandler extracts edition_id from form submissions
- [ ] Existing hooks maintain backward compatibility during transition

---

## Implementation Plan

### Step 1: Create FieldRegistry Constants

**File:** `services/FieldRegistry.php`

Add constants for edition and session fields:

```php
// Edition fields (new)
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

// Session fields (new)
public const SESSION_EDITION_ID = 'edition_id';
public const SESSION_DATE = 'date';
public const SESSION_START_TIME = 'start_time';
public const SESSION_END_TIME = 'end_time';
public const SESSION_LOCATION = 'location';
public const SESSION_ATTENDEES = 'attendees';

// Edition status values
public const EDITION_STATUS_OPEN = 'open';
public const EDITION_STATUS_FULL = 'full';
public const EDITION_STATUS_CANCELLED = 'cancelled';
public const EDITION_STATUS_POSTPONED = 'postponed';
public const EDITION_STATUS_ANNOUNCEMENT = 'announcement';
public const EDITION_STATUS_COMPLETED = 'completed';
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
            'priority' => 5, // Before CourseService (10) for model registration
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
                FieldRegistry::EDITION_COURSE_ID => ['type' => 'integer', 'required' => true],
                FieldRegistry::EDITION_START_DATE => ['type' => 'text', 'required' => true], // YYYY-MM-DD
                FieldRegistry::EDITION_END_DATE => ['type' => 'text'],
                FieldRegistry::EDITION_CAPACITY => ['type' => 'integer', 'min' => 0],
                FieldRegistry::EDITION_PRICE => ['type' => 'float', 'min' => 0],
                FieldRegistry::EDITION_PRICE_NON_MEMBER => ['type' => 'float', 'min' => 0],
                FieldRegistry::EDITION_VENUE => ['type' => 'text'],
                FieldRegistry::EDITION_SPEAKERS => ['type' => 'text'],
                FieldRegistry::EDITION_STATUS => ['type' => 'text', 'default' => 'open'],
                FieldRegistry::EDITION_INVOICE_ITEM => ['type' => 'text'],
                FieldRegistry::EDITION_INVOICE_ENABLED => ['type' => 'boolean', 'default' => true],
                FieldRegistry::EDITION_CERTIFICATE_ENABLED => ['type' => 'boolean', 'default' => false],
                FieldRegistry::EDITION_CUSTOM_FORM => ['type' => 'text'],
            ],
            'auto_metabox' => true,
        ]);
    }

    // Core queries
    public function getEdition(int $editionId): ?array;
    public function getEditionsForCourse(int $courseId): array;
    public function getUpcomingEditions(int $limit = 20): array;
    public function getLinkedCourseId(int $editionId): ?int;

    // Date methods (moved from CourseService)
    public function getStartDate(int $editionId): ?string;
    public function getEndDate(int $editionId): ?string;
    public function hasStarted(int $editionId): bool;
    public function hasEnded(int $editionId): bool;

    // Status methods (moved from CourseService)
    public function getStatus(int $editionId): string;
    public function isCancelled(int $editionId): bool;
    public function isPostponed(int $editionId): bool;
    public function isFull(int $editionId): bool;
    public function isAnnouncement(int $editionId): bool;
    public function isUpcoming(int $editionId): bool;
    public function isEnrollmentOpen(int $editionId): bool;

    // Capacity methods (uses registrations table)
    public function getCapacity(int $editionId): ?int;
    public function getRegisteredCount(int $editionId): int;
    public function hasAvailableSpots(int $editionId): bool;
    public function getAvailableSpots(int $editionId): int;

    // Pricing methods (moved from CourseService)
    public function getPrice(int $editionId): ?float;
    public function getPriceNonMember(int $editionId): ?float;
    public function getInvoiceItem(int $editionId): ?string;
    public function isInvoiceEnabled(int $editionId): bool;
    public function isCertificateEnabled(int $editionId): bool;

    // Speakers (moved from CourseService)
    public function getSpeakers(int $editionId): array;

    // Venue
    public function getVenue(int $editionId): ?string;

    // Custom form
    public function getCustomForm(int $editionId): ?string;

    // Enrollment validation
    public function canUserEnroll(int $userId, int $editionId): true|WP_Error;
}
```

**Key implementation notes:**
- Use `$this->getModel()->find($editionId)` for single edition lookup
- Use `$this->getModel()->where(...)->get()` for filtered queries
- `getRegisteredCount()` queries `wp_vad_registrations` table, not CPT meta
- `canUserEnroll()` checks: not already registered, not cancelled, not ended, capacity available, not announcement

---

### Step 3: Create SessionService

**File:** `services/core/SessionService.php` (new, ~200 lines)

Register `vad_session` CPT and implement session/attendance methods.

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
            'description' => 'Individual meeting days and attendance tracking',
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
                FieldRegistry::SESSION_EDITION_ID => ['type' => 'integer', 'required' => true],
                FieldRegistry::SESSION_DATE => ['type' => 'text', 'required' => true],
                FieldRegistry::SESSION_START_TIME => ['type' => 'text'],
                FieldRegistry::SESSION_END_TIME => ['type' => 'text'],
                FieldRegistry::SESSION_LOCATION => ['type' => 'text'],
                FieldRegistry::SESSION_ATTENDEES => ['type' => 'json', 'default' => []],
            ],
            'auto_metabox' => true,
        ]);
    }

    // Session queries
    public function getSessionsForEdition(int $editionId): array;
    public function getSessionCount(int $editionId): int;
    public function getDayCount(int $editionId): int;

    // Attendance (meta-based: attendees JSON array on session)
    public function markPresent(int $sessionId, int $userId): void;
    public function markAbsent(int $sessionId, int $userId): void;
    public function isPresent(int $sessionId, int $userId): bool;
    public function getAttendees(int $sessionId): array;

    // Hours calculation
    public function getSessionDuration(int $sessionId): float; // hours
    public function getHoursAttended(int $userId, int $editionId): float;
    public function getAttendanceRate(int $userId, int $editionId): float; // 0.0-1.0
}
```

**Key implementation notes:**
- Attendees stored as JSON array in `_vad_attendees` meta: `[42, 56, 78]`
- `markPresent()` adds userId to array, `markAbsent()` removes it
- `getSessionDuration()` calculates hours from start_time/end_time

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
    INDEX idx_user (user_id),
    INDEX idx_edition (edition_id),
    INDEX idx_status (status),
    UNIQUE KEY unique_user_edition (user_id, edition_id)
);
```

**Key implementation notes:**
- Use `$wpdb->prepare()` for all queries
- UNIQUE constraint prevents duplicate registrations
- Index on edition_id for fast capacity counts

---

### Step 5: Refactor CourseService (Thin Version)

**File:** `services/core/CourseService.php` (reduce from 1034 lines to ~300)

Remove all edition-specific methods, keep only LearnDash content operations.

**Keep these methods:**
```php
// LearnDash availability
public function isAvailable(): bool;

// Course type detection (content-level)
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

// LearnDash enrollment (4 integration points)
public function grantAccess(int $userId, int $courseId): bool;
public function revokeAccess(int $userId, int $courseId): bool;
public function isComplete(int $userId, int $courseId): bool;
public function getCertificateLink(int $userId, int $courseId): ?string;

// User queries (still needed for user info)
public function isUserEnrolled(int $userId, int $courseId): bool;
public function hasDirectEnrollment(int $userId, int $courseId): bool;
public function getEnrolledUsers(int $courseId): array;
public function getUserDisplayInfo(int $userId): ?string;

// Authorization
public function currentUserCanManage(): bool;
private function canModifyUser(int $userId): bool;
```

**Remove/deprecate these methods (moved to EditionService):**
- `getCourseDates()`, `getStartDate()`, `getEndDate()`, `getNextDate()`
- `hasStarted()`, `hasEnded()`, `getDayCount()`
- `isCancelled()`, `isPostponed()`, `isFull()`, `isAnnouncement()`
- `isUpcoming()`, `isEnrollmentOpen()`
- `getCapacity()`, `getEnrolledCount()`, `hasAvailableSpots()`, `getAvailableSpots()`
- `getCourseSpeakers()`, `getCoursePrice()`, `getInvoiceItem()`, `getCourseAddress()`
- `isInvoiceEnabled()`, `isCertificateEnabled()`, `getCustomForm()`
- `enrollUser()`, `unenrollUser()` - move to EnrollmentService
- `canUserEnroll()` - move to EditionService

**Backward compatibility:**
Add deprecated wrapper methods that delegate to EditionService during transition:

```php
/**
 * @deprecated Use EditionService::getStartDate() instead
 */
public function getStartDate(int $courseId): ?string
{
    _deprecated_function(__METHOD__, '4.0', 'EditionService::getStartDate()');

    // Find first upcoming edition for this course
    $editions = ntdst_get(EditionService::class)->getEditionsForCourse($courseId);
    return $editions[0]['start_date'] ?? null;
}
```

---

### Step 6: Refactor EnrollmentService

**File:** `services/enrollment/EnrollmentService.php`

Change signature from `enrollUser($userId, $courseId, ...)` to `enrollUser($userId, $editionId, ...)`.

```php
/**
 * Enroll a user in an edition
 *
 * @param int $userId WordPress user ID
 * @param int $editionId Edition ID (vad_edition CPT)
 * @param array $data Enrollment data
 * @return true|WP_Error
 */
public function enrollUser(int $userId, int $editionId, array $data = []): true|WP_Error
{
    // 1. Validate via EditionService (not CourseService)
    $canEnroll = $this->editionService->canUserEnroll($userId, $editionId);
    if (is_wp_error($canEnroll)) {
        return $canEnroll;
    }

    // 2. Get linked course ID for LearnDash enrollment
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

    // 5. Create registration record in wp_vad_registrations
    $registrationId = $this->registrationRepo->create([
        'user_id' => $userId,
        'edition_id' => $editionId,
        'status' => 'confirmed',
        'enrollment_path' => $data['enrollment_path'] ?? 'individual',
        'enrolled_by' => $data['enrolled_by_user_id'] ?? null,
        'voucher_code' => $data['voucher_code'] ?? null,
    ]);

    if (is_wp_error($registrationId)) {
        return $registrationId;
    }

    // 6. Grant LearnDash course access
    $this->courseService->grantAccess($userId, $courseId);

    // 7. Create CRM audit note
    $this->createEnrollmentNote($userId, $editionId, $data);

    // 8. Fire completion hook (now with editionId)
    do_action('stride/enrollment/completed', $userId, $editionId, $data);

    // 9. Backward compat: also fire with courseId for existing handlers
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
    // Check multiple possible field names
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
    // Get price from edition
    $price = $this->editionService->getPrice($editionId);

    // Skip if free edition
    if ($price <= 0 && !$this->editionService->isInvoiceEnabled($editionId)) {
        return;
    }

    // Create quote with item_type = 'edition'
    $this->quoteService->createQuoteForItem($userId, 'edition', $editionId, [
        'price' => $price,
        'invoice_item' => $this->editionService->getInvoiceItem($editionId),
        'voucher_code' => $data['voucher_code'] ?? null,
    ]);
}
```

---

### Step 9: Service Registration

**File:** `theme-config.php`

Add new services to registration array:

```php
'services' => [
    'core' => [
        // NEW: Add before CourseService for proper init order
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

**File:** `services/core/RegistrationRepository.php` (activation hook)

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
| `services/FieldRegistry.php` | Edit | +30 |
| `services/core/EditionService.php` | Create | ~400 |
| `services/core/SessionService.php` | Create | ~200 |
| `services/core/RegistrationRepository.php` | Create | ~250 |
| `services/core/CourseService.php` | Refactor | -700 (trim to ~300) |
| `services/enrollment/EnrollmentService.php` | Refactor | ~+50 |
| `services/enrollment/FormSubmissionHandler.php` | Refactor | ~+30 |
| `services/handlers/EnrollmentQuoteHandler.php` | Refactor | ~+20 |
| `theme-config.php` | Edit | +5 |
| `functions.php` | Edit | +5 |

**Net change:** ~+300 lines (removal of duplicated code from CourseService offsets new service code)

---

## Testing Checklist

- [ ] EditionService CPT appears in admin under Stride menu
- [ ] SessionService CPT appears in admin under Stride menu
- [ ] Can create edition with all fields via admin
- [ ] Can create session linked to edition
- [ ] `wp_vad_registrations` table created on activation
- [ ] `EditionService::canUserEnroll()` validates correctly
- [ ] `EnrollmentService::enrollUser()` creates registration record
- [ ] `EnrollmentService::enrollUser()` grants LearnDash course access
- [ ] `SessionService::markPresent()` updates attendees array
- [ ] `EditionService::getRegisteredCount()` returns correct count
- [ ] Quote created with item_type='edition' on enrollment
- [ ] Deprecated CourseService methods log warnings

---

## References

- Project Plan: `docs/V4-PROJECT-PLAN master.md` (Phase 1.5 section)
- DataManager API: `web/app/mu-plugins/ntdst-core/api/Data.php`
- QuoteService pattern: `services/invoicing/QuoteService.php`
- VoucherService pattern: `services/invoicing/VoucherService.php`
- Current CourseService: `services/core/CourseService.php`
- Current EnrollmentService: `services/enrollment/EnrollmentService.php`
