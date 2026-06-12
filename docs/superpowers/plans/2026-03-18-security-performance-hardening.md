# Security & Performance Hardening Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix 14 security findings and 16 performance issues identified in the Stride LMS audit.

**Architecture:** Fixes are grouped into 3 sprints by priority. Sprint 1 covers critical security blockers (IDOR, CSRF, user claiming). Sprint 2 covers high-priority security + easy performance wins (indexes, existence queries, memoization). Sprint 3 covers query optimization (N+1 patterns in Partner API and Dashboard).

**Tech Stack:** PHP 8.3, WordPress, MariaDB 10.11, Alpine.js, Vite

---

## File Map

### Files Modified

| File | Changes |
|------|---------|
| `web/app/mu-plugins/stride-core/Handlers/EnrollmentFormHandler.php` | S1: ownership check, S14: fix method call, S5: remove from public actions |
| `web/app/mu-plugins/ntdst-core/api/Endpoints.php` | S2: fix CSRF origin verification |
| `web/app/mu-plugins/stride-core/Modules/PartnerAPI/PartnerAPIController.php` | S3: remove auto-company-assignment, S9: route through EnrollmentService, P1/P2/P3: batch query rewrites |
| `web/app/mu-plugins/stride-core/Handlers/CompletionTaskHandler.php` | S4: file upload restrictions |
| `web/app/mu-plugins/stride-core/Modules/Enrollment/RegistrationRepository.php` | P5: add EXISTS queries, P7: per-request memoization on findByUser |
| `web/app/mu-plugins/stride-core/Modules/Enrollment/RegistrationTable.php` | P10: composite indexes |
| `web/app/mu-plugins/stride-core/Modules/Enrollment/EnrollmentService.php` | S6: atomic enrollment with transaction |
| `web/app/mu-plugins/stride-core/Modules/User/UserDashboardService.php` | P4/P7: batch prefetch, eliminate duplicate findByUser calls |
| `web/app/mu-plugins/stride-core/Modules/Notification/NotificationService.php` | P8: per-request memoization |
| `web/app/mu-plugins/stride-core/Modules/Trajectory/TrajectoryDashboardService.php` | P9: direct lookup, P11: pre-build courseId map |
| `web/app/mu-plugins/stride-core/Handlers/ICalHandler.php` | P6: batch prefetch edition/course data |
| `web/app/mu-plugins/stride-core/Admin/AdminDashboardService.php` | S8: pin CDN versions + SRI hashes |
| `web/app/themes/stridence/functions.php` | P14: conditional nocache headers |
| `scripts/seed.php` | S11: whitelist dev environment |
| `scripts/unseed.php` | S11: whitelist dev environment |

### Test Files

| File | Tests |
|------|-------|
| `tests/Unit/EnrollmentFormHandlerTest.php` | S1 ownership check, S14 method fix |
| `tests/Unit/EndpointsOriginTest.php` | S2 CSRF origin verification |
| `tests/Unit/PartnerAPIControllerTest.php` | S3 user claiming, S9 enrollment validation, P1/P2/P3 batch queries |
| `tests/Unit/CompletionTaskHandlerTest.php` | S4 file restrictions |
| `tests/Unit/RegistrationRepositoryTest.php` | P5 EXISTS queries |
| `tests/Unit/EnrollmentServiceTest.php` | S6 atomic enrollment |

---

## Sprint 1: Critical Security Blockers

### Task 1: Fix IDOR in Session Selection Handler (S1 + S14)

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Handlers/EnrollmentFormHandler.php:511-546`

- [ ] **Step 1: Fix the missing method call (S14)**

The handler calls `$sessionSelection->selectSessions()` which doesn't exist on `SessionSelection`. The correct method is `setSelections()`.

In `EnrollmentFormHandler.php` line 526, change:
```php
// BEFORE:
$result = $sessionSelection->selectSessions($registrationId, array_map('intval', $sessionIds));

// AFTER:
$result = $sessionSelection->setSelections($registrationId, array_map('intval', $sessionIds));
```

- [ ] **Step 2: Add ownership verification (S1)**

Add ownership check after the `$registrationId` validation (after line 519, before line 521). Follow the exact pattern from `CompletionTaskHandler.php:52-57`:

```php
// Add after line 519 (the !$registrationId check):
$userId = get_current_user_id();
if (!$userId) {
    return new WP_Error('not_logged_in', __('Je moet ingelogd zijn.', 'stride'));
}

$repo = ntdst_get(RegistrationRepository::class);
$reg = $repo->find($registrationId);
if (!$reg || (int) $reg->user_id !== $userId) {
    return new WP_Error('forbidden', __('Geen toegang.', 'stride'));
}
```

Add the required import at top of file if not already present:
```php
use Stride\Modules\Enrollment\RegistrationRepository;
```

- [ ] **Step 3: Verify the fix compiles**

Run: `ddev exec wp eval "echo class_exists('\Stride\Handlers\EnrollmentFormHandler') ? 'OK' : 'FAIL';"`
Expected: `OK`

- [ ] **Step 4: Run existing tests**

Run: `ddev exec vendor/bin/phpunit --filter EnrollmentFormHandler --testsuite Unit`

- [ ] **Step 5: Commit**

```bash
git add web/app/mu-plugins/stride-core/Handlers/EnrollmentFormHandler.php
git commit -m "fix(security): add ownership check to session selection handler (S1+S14)

- Add user ownership verification before allowing session selection changes
- Fix undefined selectSessions() call → setSelections()
- Prevents IDOR where any authenticated user could modify any registration"
```

---

### Task 2: Fix CSRF Origin Verification Bypass (S2)

**Files:**
- Modify: `web/app/mu-plugins/ntdst-core/api/Endpoints.php:173-214`

- [ ] **Step 1: Fix the origin verification logic**

Replace the `verifyOrigin()` method (lines 173-214) with:

```php
private function verifyOrigin(): bool
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $referer = $_SERVER['HTTP_REFERER'] ?? '';

    // If no origin/referer, only allow if this is not a cookie-authenticated request.
    // Browsers always send Origin on cross-origin requests with credentials.
    // Missing both headers with a logged-in cookie is suspicious.
    if (empty($origin) && empty($referer)) {
        // Same-origin XHR from older browsers may omit both; allow if no auth cookie present
        return !$this->hasCookieAuth();
    }

    $homeHost = parse_url(home_url(), PHP_URL_HOST);
    $siteHost = parse_url(site_url(), PHP_URL_HOST);

    // Exact hostname match on Origin header
    if (!empty($origin)) {
        $originHost = parse_url($origin, PHP_URL_HOST);
        if ($originHost === $homeHost || $originHost === $siteHost) {
            return true;
        }
    }

    // Referer must start with our full URL (existing logic is already correct)
    if (!empty($referer)) {
        if (str_starts_with($referer, home_url()) || str_starts_with($referer, site_url())) {
            return true;
        }
    }

    // Allow custom origins via filter
    $allowed_origins = apply_filters('ntdst/api/allowed_origins', []);
    if (!empty($origin) && in_array($origin, $allowed_origins, true)) {
        return true;
    }

    return false;
}

/**
 * Check if the request contains WordPress authentication cookies.
 */
private function hasCookieAuth(): bool
{
    foreach ($_COOKIE as $name => $value) {
        if (str_starts_with($name, 'wordpress_logged_in_')) {
            return true;
        }
    }
    return false;
}
```

Key changes:
1. Missing origin+referer now **rejected** when auth cookies are present (fixes bypass)
2. `str_contains()` replaced with exact `parse_url(..., PHP_URL_HOST)` comparison (fixes partial match)

- [ ] **Step 2: Verify the fix compiles**

Run: `ddev exec wp eval "echo 'Origin fix loaded OK';"`

- [ ] **Step 3: Commit**

```bash
git add web/app/mu-plugins/ntdst-core/api/Endpoints.php
git commit -m "fix(security): tighten CSRF origin verification (S2)

- Reject missing Origin+Referer when auth cookies present
- Use exact hostname match instead of str_contains partial match
- Prevents cross-origin CSRF from subdomain or suffix domains"
```

---

### Task 3: Remove Partner API Auto-Company-Assignment (S3)

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Modules/PartnerAPI/PartnerAPIController.php:462-471`

- [ ] **Step 1: Replace auto-assignment with strict rejection**

Replace lines 462-471 in `createEnrollment()`:

```php
// BEFORE:
$userCompanyId = (int) get_user_meta($user->ID, '_stride_company_id', true);
if ($userCompanyId && $userCompanyId !== $companyId) {
    return new WP_Error('forbidden', __('User belongs to another company.', 'stride'), ['status' => 403]);
}
if (!$userCompanyId) {
    update_user_meta($user->ID, '_stride_company_id', $companyId);
}

// AFTER:
$userCompanyId = (int) get_user_meta($user->ID, '_stride_company_id', true);
if (!$userCompanyId) {
    return new WP_Error(
        'user_not_affiliated',
        __('User is not affiliated with any company. Contact an administrator.', 'stride'),
        ['status' => 422]
    );
}
if ($userCompanyId !== $companyId) {
    return new WP_Error('forbidden', __('User belongs to another company.', 'stride'), ['status' => 403]);
}
```

- [ ] **Step 2: Update existing unit tests**

Run: `ddev exec vendor/bin/phpunit --filter PartnerAPIController --testsuite Unit`

If tests break due to the removed auto-assignment, update them to expect the new `user_not_affiliated` error for unaffiliated users.

- [ ] **Step 3: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/PartnerAPI/PartnerAPIController.php
git commit -m "fix(security): remove auto-company-assignment in Partner API (S3)

- Partners can no longer claim unaffiliated users
- Unaffiliated users now return 422 with clear error message
- Prevents privilege escalation via enrollment endpoint"
```

---

### Task 4: Move Voucher Validation Behind Authentication (S5)

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Handlers/EnrollmentFormHandler.php:38-52`

- [ ] **Step 1: Remove voucher validation from public actions**

Remove the `registerPublicActions` method and its hook registration.

In `init()` (line 39), remove:
```php
add_filter('ntdst/api/public_actions', [$this, 'registerPublicActions']);
```

Remove the `registerPublicActions` method entirely (lines 42-52).

- [ ] **Step 2: Make voucher response generic on failure**

In `handleValidateVoucher()`, the error response at line 460 already returns a generic message. No change needed there. But update the success response (lines 479-485) to not reveal the discount type:

```php
// BEFORE (line 483):
'discount_type' => $validation['discount_type'],

// AFTER:
// discount_type intentionally omitted from public response
```

- [ ] **Step 3: Verify the fix**

Run: `ddev exec wp eval "echo 'Voucher fix loaded OK';"`

- [ ] **Step 4: Commit**

```bash
git add web/app/mu-plugins/stride-core/Handlers/EnrollmentFormHandler.php
git commit -m "fix(security): require authentication for voucher validation (S5)

- Remove stride_validate_voucher from public API actions
- Remove discount_type from response to reduce information exposure
- Prevents unauthenticated voucher code enumeration"
```

---

### Task 5: Add File Upload Restrictions (S4)

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Handlers/CompletionTaskHandler.php:84-134`

- [ ] **Step 1: Add validation constants and pre-upload checks**

Add constants at the top of the class:

```php
private const ALLOWED_MIME_TYPES = [
    'application/pdf',
    'image/jpeg',
    'image/png',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
];
private const MAX_FILE_SIZE = 10 * 1024 * 1024; // 10MB
private const MAX_FILE_COUNT = 5;
```

After the `$files` check (line 106), add validation:

```php
$fileCount = is_array($files['name']) ? count($files['name']) : 1;

if ($fileCount > self::MAX_FILE_COUNT) {
    return new WP_Error('too_many_files', sprintf(
        __('Maximaal %d bestanden per upload.', 'stride'),
        self::MAX_FILE_COUNT
    ));
}

// Validate each file before uploading
for ($i = 0; $i < $fileCount; $i++) {
    $size = is_array($files['size']) ? $files['size'][$i] : $files['size'];
    $name = is_array($files['name']) ? $files['name'][$i] : $files['name'];

    if ($size > self::MAX_FILE_SIZE) {
        return new WP_Error('file_too_large', sprintf(
            __('%s is te groot. Maximum is 10 MB.', 'stride'),
            $name
        ));
    }

    $finfo = new \finfo(FILEINFO_MIME_TYPE);
    $tmpName = is_array($files['tmp_name']) ? $files['tmp_name'][$i] : $files['tmp_name'];
    $mimeType = $finfo->file($tmpName);

    if (!in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
        return new WP_Error('invalid_file_type', sprintf(
            __('%s: ongeldig bestandstype. Toegestaan: PDF, JPG, PNG, DOC, DOCX.', 'stride'),
            $name
        ));
    }
}
```

- [ ] **Step 2: Verify compilation**

Run: `ddev exec wp eval "echo class_exists('\Stride\Handlers\CompletionTaskHandler') ? 'OK' : 'FAIL';"`

- [ ] **Step 3: Commit**

```bash
git add web/app/mu-plugins/stride-core/Handlers/CompletionTaskHandler.php
git commit -m "fix(security): add file upload type, size, and count restrictions (S4)

- Limit uploads to PDF, JPG, PNG, DOC, DOCX
- Max file size: 10MB
- Max files per upload: 5
- Validates MIME type from file content, not extension"
```

---

## Sprint 2: High-Priority Security + Easy Performance Wins

### Task 6: Route Partner API Enrollment Through EnrollmentService (S9)

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Modules/PartnerAPI/PartnerAPIController.php:473-495`

- [ ] **Step 1: Replace direct repository call with EnrollmentService**

Replace the registration creation block (lines 473-495) with:

```php
// Route through validated enrollment paths for capacity, duplicate, and status checks
if ($editionId) {
    $enrollmentService = ntdst_get(\Stride\Modules\Enrollment\EnrollmentService::class);
    $result = $enrollmentService->enroll($user->ID, $editionId, [
        'company_id' => $companyId,
        'enrollment_path' => 'individual',
        'notes' => sprintf('Enrolled via Partner API by user #%d', get_current_user_id()),
    ]);
} elseif ($trajectoryId) {
    // Trajectory enrollment uses TrajectorySelection::enroll() which handles
    // capacity checks, duplicate detection, and enrollment status validation
    $selectionService = ntdst_get(\Stride\Modules\Trajectory\TrajectorySelection::class);
    $result = $selectionService->enroll($user->ID, $trajectoryId);
} else {
    return new WP_Error('invalid_input', __('Either edition_id or trajectory_id is required.', 'stride'), ['status' => 400]);
}

if (is_wp_error($result)) {
    $statusMap = [
        'already_enrolled' => 409,
        'edition_full' => 409,
        'enrollment_closed' => 422,
        'invalid_edition' => 404,
    ];
    $status = $statusMap[$result->get_error_code()] ?? 400;
    return new WP_Error($result->get_error_code(), $result->get_error_message(), ['status' => $status]);
}

ntdst_log('partner-api')->info('Enrollment created via Partner API', [
    'registration_id' => $result,
    'user_id' => $user->ID,
    'edition_id' => $editionId,
    'trajectory_id' => $trajectoryId,
    'company_id' => $companyId,
]);

return new WP_REST_Response([
    'id' => $result,
    'user_id' => $user->ID,
    'edition_id' => $editionId ?: null,
    'trajectory_id' => $trajectoryId ?: null,
    'status' => 'confirmed',
    'registered_at' => gmdate('c'),
], 201);
```

- [ ] **Step 2: Run Partner API tests**

Run: `ddev exec vendor/bin/phpunit --filter PartnerAPIController --testsuite Unit`

- [ ] **Step 3: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/PartnerAPI/PartnerAPIController.php
git commit -m "fix(security): route Partner API enrollment through EnrollmentService (S9)

- Enforces capacity checks, duplicate detection, enrollment status validation
- Partners can no longer enroll in closed/full/cancelled editions
- Maps EnrollmentService errors to proper HTTP status codes"
```

---

### Task 7: Add Composite Database Indexes (P10)

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Modules/Enrollment/RegistrationTable.php:32-55` and `migrate()`

- [ ] **Step 1: Add indexes to CREATE TABLE**

Add these indexes to the CREATE TABLE statement (after line 54):

```php
INDEX idx_user_status (user_id, status),
INDEX idx_user_edition (user_id, edition_id),
```

- [ ] **Step 2: Add migration for existing tables**

Add to the `migrate()` method:

```php
// Add composite index (user_id, status) for findByUser queries
$hasUserStatusIdx = $wpdb->get_results("SHOW INDEX FROM {$table} WHERE Key_name = 'idx_user_status'");
if (empty($hasUserStatusIdx)) {
    $wpdb->query("ALTER TABLE {$table} ADD INDEX idx_user_status (user_id, status)");
}

// Add composite index (user_id, edition_id) for findByUserAndEdition queries
$hasUserEditionIdx = $wpdb->get_results("SHOW INDEX FROM {$table} WHERE Key_name = 'idx_user_edition'");
if (empty($hasUserEditionIdx)) {
    $wpdb->query("ALTER TABLE {$table} ADD INDEX idx_user_edition (user_id, edition_id)");
}
```

- [ ] **Step 3: Run migration to apply**

Run: `ddev exec wp eval "Stride\Modules\Enrollment\RegistrationTable::migrate();"`

- [ ] **Step 4: Verify indexes exist**

Run: `ddev exec wp db query "SHOW INDEX FROM wp_vad_registrations WHERE Key_name IN ('idx_user_status', 'idx_user_edition');"`

- [ ] **Step 5: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Enrollment/RegistrationTable.php
git commit -m "perf: add composite indexes on registrations table (P10)

- Add (user_id, status) for findByUser filtered queries
- Add (user_id, edition_id) for findByUserAndEdition lookups
- Includes migration for existing installations"
```

---

### Task 8: Add Lightweight EXISTS Queries (P5)

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Modules/Enrollment/RegistrationRepository.php:195-263`

- [ ] **Step 1: Replace existsForEdition with direct SQL**

Replace `existsForEdition()` (lines 195-198):

```php
public function existsForEdition(int $userId, int $editionId): bool
{
    global $wpdb;
    return (bool) $wpdb->get_var($wpdb->prepare(
        "SELECT 1 FROM {$this->table()} WHERE user_id = %d AND edition_id = %d LIMIT 1",
        $userId,
        $editionId
    ));
}
```

- [ ] **Step 2: Replace existsForTrajectory with direct SQL**

Replace `existsForTrajectory()` (lines 260-263):

```php
public function existsForTrajectory(int $userId, int $trajectoryId): bool
{
    global $wpdb;
    return (bool) $wpdb->get_var($wpdb->prepare(
        "SELECT 1 FROM {$this->table()} WHERE user_id = %d AND trajectory_id = %d AND edition_id IS NULL LIMIT 1",
        $userId,
        $trajectoryId
    ));
}
```

- [ ] **Step 3: Run tests**

Run: `ddev exec vendor/bin/phpunit --filter RegistrationRepository --testsuite Unit`

- [ ] **Step 4: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Enrollment/RegistrationRepository.php
git commit -m "perf: use lightweight EXISTS queries for registration checks (P5)

- existsForEdition() now uses SELECT 1 instead of SELECT * + JSON decode
- existsForTrajectory() same treatment
- Eliminates unnecessary data deserialization for boolean checks"
```

---

### Task 9: Memoize findByUser Per-Request (P7)

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Modules/Enrollment/RegistrationRepository.php:328-354`

- [ ] **Step 1: Add memoization cache property**

Add at the top of the class:

```php
/** @var array<string, array<object>> Per-request cache for findByUser results */
private array $findByUserCache = [];
```

- [ ] **Step 2: Wrap findByUser with cache**

Replace `findByUser()`:

```php
public function findByUser(int $userId, ?string $status = null): array
{
    $cacheKey = $userId . ':' . ($status ?? '*');

    if (isset($this->findByUserCache[$cacheKey])) {
        return $this->findByUserCache[$cacheKey];
    }

    global $wpdb;

    $sql = "SELECT * FROM {$this->table()} WHERE user_id = %d";
    $params = [$userId];

    if ($status !== null) {
        $sql .= " AND status = %s";
        $params[] = $status;
    }

    $sql .= " ORDER BY registered_at DESC";

    $results = $wpdb->get_results($wpdb->prepare($sql, ...$params));

    foreach ($results as $row) {
        if (!empty($row->selections) && is_string($row->selections)) {
            $row->selections = json_decode($row->selections, true);
        }
        if (!empty($row->completion_tasks) && is_string($row->completion_tasks)) {
            $row->completion_tasks = json_decode($row->completion_tasks, true);
        }
    }

    $this->findByUserCache[$cacheKey] = $results;

    return $results;
}
```

- [ ] **Step 3: Add cache invalidation on write operations**

Add a method to clear cache, and call it from `create()`, `update()`, and `delete()`:

```php
/**
 * Clear per-request memoization cache.
 */
public function clearCache(): void
{
    $this->findByUserCache = [];
}
```

In the `create()` method, after the insert succeeds, add `$this->clearCache();`.
In `updateStatus()` (if it exists), add `$this->clearCache();`.
In `cancel()` (if it exists), add `$this->clearCache();`.
In `setSelections()`, add `$this->clearCache();`.

- [ ] **Step 4: Run tests**

Run: `ddev exec vendor/bin/phpunit --filter RegistrationRepository --testsuite Unit`

- [ ] **Step 5: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Enrollment/RegistrationRepository.php
git commit -m "perf: memoize findByUser() per request lifecycle (P7)

- Caches findByUser results keyed by userId:status
- Eliminates 2-3 duplicate queries per dashboard page load
- Cache auto-clears on write operations"
```

---

### Task 10: Fix TrajectoryDashboardService Inefficiencies (P9 + P11)

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Modules/Trajectory/TrajectoryDashboardService.php:45-56, 132-154`

- [ ] **Step 1: Replace getEnrollmentForUser scan with direct lookup (P9)**

Replace `getEnrollmentForUser()` (lines 45-56):

```php
public function getEnrollmentForUser(int $userId, int $trajectoryId): ?object
{
    return $this->registrationRepo->findByUserAndTrajectory($userId, $trajectoryId);
}
```

- [ ] **Step 2: Pre-build courseId map in getProgressData (P11)**

In `getProgressData()`, after line 88, build a lookup map:

```php
$editionRegs = $this->registrationRepo->findEditionsByTrajectory($userId, $trajectoryId);

// Pre-build edition → courseId map to avoid N+1 getCourseId calls
$editionCourseMap = [];
foreach ($editionRegs as $edReg) {
    $edId = (int) $edReg->edition_id;
    $editionCourseMap[$edId] = $this->editionService->getCourseId($edId);
}
```

Then update `checkCourseStatus()` to accept and use the map:

```php
private function checkCourseStatus(
    int $userId,
    int $courseId,
    array $editionRegs,
    array $editionCourseMap,
    array &$completedCourses,
    array &$inProgressCourses
): void {
    if ($this->lmsAdapter->isComplete($userId, $courseId)) {
        $completedCourses[] = $courseId;
        return;
    }

    foreach ($editionRegs as $edReg) {
        $edCourseId = $editionCourseMap[(int) $edReg->edition_id] ?? 0;
        if ($edCourseId === $courseId) {
            $inProgressCourses[] = $courseId;
            return;
        }
    }
}
```

Update all call sites to pass `$editionCourseMap` as the 4th argument.

- [ ] **Step 3: Run tests**

Run: `ddev exec vendor/bin/phpunit --filter Trajectory --testsuite Unit`

- [ ] **Step 4: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Trajectory/TrajectoryDashboardService.php
git commit -m "perf: optimize trajectory dashboard queries (P9+P11)

- Use direct findByUserAndTrajectory instead of scan-all-then-filter
- Pre-build courseId map to eliminate N+1 getCourseId in checkCourseStatus"
```

---

### Task 11: Memoize NotificationService Per-Request (P8)

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Modules/Notification/NotificationService.php:90-96`

- [ ] **Step 1: Add per-request cache**

Add a cache property:

```php
/** @var array<int, array> Per-request notification cache */
private array $cache = [];
```

Wrap `getNotifications()` with cache (add at the beginning of the method):

```php
if (isset($this->cache[$userId])) {
    return $this->cache[$userId];
}
```

And before the return, cache the result:

```php
$this->cache[$userId] = $notifications;
return $notifications;
```

Add cache invalidation in `markAllRead()`:

```php
unset($this->cache[$userId]);
```

- [ ] **Step 2: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Notification/NotificationService.php
git commit -m "perf: memoize notification results per request (P8)

- getUnreadCount() and markAllRead() no longer re-build full notification list
- Eliminates duplicate notification queries on dashboard pages"
```

---

### Task 12: Atomic Enrollment with Transaction (S6)

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Modules/Enrollment/EnrollmentService.php:162-273`

- [ ] **Step 1: Wrap capacity check + create in transaction**

In `enroll()`, wrap the critical section (from capacity check to registration creation) in a transaction. Replace lines 189-242:

First, add a locking count method to `RegistrationRepository` (since `table()` is private):

```php
/**
 * Count confirmed registrations with row-level lock (FOR UPDATE).
 * Must be called within a transaction.
 */
public function countConfirmedForUpdate(int $editionId): int
{
    global $wpdb;
    return (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$this->table()} WHERE edition_id = %d AND status = 'confirmed' FOR UPDATE",
        $editionId
    ));
}
```

Then in `EnrollmentService::enroll()`, wrap the critical section:

```php
// Begin atomic enrollment
global $wpdb;
$wpdb->query('START TRANSACTION');

try {
    // Lock capacity check with FOR UPDATE (via repository, since table() is private)
    $confirmedCount = $this->registrations->countConfirmedForUpdate($editionId);

    $capacity = $this->editions->getCapacity($editionId);
    if ($capacity > 0 && $confirmedCount >= $capacity) {
        $wpdb->query('ROLLBACK');
        return new WP_Error('edition_full', 'This edition is full');
    }

    // Check not already registered (within transaction)
    if ($this->hasActiveRegistration($userId, editionId: $editionId)) {
        $wpdb->query('ROLLBACK');
        return new WP_Error('already_enrolled', 'User is already enrolled in this edition');
    }

    // Determine initial status
    $completionService = ntdst_get(EnrollmentCompletion::class);
    $hasCompletionRequirements = $completionService->hasRequirements($editionId, 'vad_edition');
    $initialStatus = ($hasCompletionRequirements || $this->editions->requiresApproval($editionId))
        ? RegistrationStatus::Pending
        : RegistrationStatus::Confirmed;

    // Build + create registration
    $registrationData = [
        'user_id' => $userId,
        'edition_id' => $editionId,
        'status' => $initialStatus->value,
        'enrollment_path' => $options['enrollment_path'] ?? RegistrationRepository::PATH_INDIVIDUAL,
        'enrolled_by' => $options['enrolled_by'] ?? null,
        'voucher_code' => $options['voucher_code'] ?? null,
        'notes' => $options['notes'] ?? null,
        'enrollment_data' => $options['enrollment_data'] ?? null,
    ];

    if (!isset($options['company_id'])) {
        $companyId = (int) get_user_meta($userId, '_stride_company_id', true);
        if ($companyId) {
            $registrationData['company_id'] = $companyId;
        }
    } elseif ($options['company_id']) {
        $registrationData['company_id'] = $options['company_id'];
    }

    $registrationId = $this->registrations->create($registrationData);

    if (is_wp_error($registrationId)) {
        $wpdb->query('ROLLBACK');
        return $registrationId;
    }

    $wpdb->query('COMMIT');
} catch (\Throwable $e) {
    $wpdb->query('ROLLBACK');
    throw $e;
}
```

Keep the post-creation logic (LMS access grant, event dispatch, logging, completion init) outside the transaction — these are side effects that don't need atomicity.

- [ ] **Step 2: Run enrollment tests**

Run: `ddev exec vendor/bin/phpunit --filter EnrollmentService --testsuite Unit`

- [ ] **Step 3: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Enrollment/EnrollmentService.php
git commit -m "fix(security): atomic enrollment with FOR UPDATE locking (S6)

- Wrap capacity check + registration create in DB transaction
- Use SELECT FOR UPDATE to prevent race conditions on capacity
- Prevents over-enrollment under concurrent requests"
```

---

### Task 13: Pin CDN Versions + Add SRI Hashes (S8)

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Admin/AdminDashboardService.php:149-177`

- [ ] **Step 1: Pin Alpine.js to exact version and add SRI**

Replace the Alpine.js enqueue (lines 171-177):

```php
wp_enqueue_script(
    'alpinejs',
    'https://cdn.jsdelivr.net/npm/alpinejs@3.14.9/dist/cdn.min.js',
    ['flatpickr'],
    '3.14.9',
    ['strategy' => 'defer']
);
```

Note: The SRI hash should be generated from the pinned version. Add after the enqueue:

```php
// Add crossorigin attribute for CDN scripts (required for SRI if added later)
add_filter('script_loader_tag', function (string $tag, string $handle) {
    if (in_array($handle, ['alpinejs', 'flatpickr', 'flatpickr-nl'], true)) {
        return str_replace(' src=', ' crossorigin="anonymous" src=', $tag);
    }
    return $tag;
}, 10, 2);
```

The key fix here is pinning `@3.x.x` → `@3.14.9` (exact version). SRI hashes can be added incrementally once we verify the exact CDN content hash.

- [ ] **Step 2: Commit**

```bash
git add web/app/mu-plugins/stride-core/Admin/AdminDashboardService.php
git commit -m "fix(security): pin Alpine.js version + add crossorigin for CDN assets (S8)

- Pin Alpine from @3.x.x wildcard to exact @3.14.9
- Add crossorigin=anonymous to CDN scripts (enables future SRI)
- Prevents auto-upgrade supply chain attacks"
```

---

### Task 14: Conditional nocache Headers (P14)

**Files:**
- Modify: `web/app/themes/stridence/functions.php:406-412`

- [ ] **Step 1: Only send nocache for authenticated users**

Replace lines 406-412:

```php
// Prevent browser from caching authenticated pages (stale logged-in/out state)
add_action('send_headers', function () {
    if (!is_admin() && is_user_logged_in()) {
        nocache_headers();
        header('Vary: Cookie', false);
    }
});
```

- [ ] **Step 2: Verify**

Test by visiting a public page as a logged-out user — headers should no longer include `Cache-Control: no-store`.

- [ ] **Step 3: Commit**

```bash
git add web/app/themes/stridence/functions.php
git commit -m "perf: only send nocache headers for authenticated users (P14)

- Public pages (catalog, course listings) can now be cached by CDN/browser
- Authenticated pages still get nocache to prevent stale state"
```

---

### Task 15: Whitelist Dev Environment in Seed Scripts (S11)

**Files:**
- Modify: `scripts/seed.php:34-38`
- Modify: `scripts/unseed.php:22-25`

- [ ] **Step 1: Invert guard logic in both files**

In `seed.php`, replace lines 34-38:

```php
// Only allow in development environments
if (!defined('WP_ENV') || !in_array(WP_ENV, ['development', 'local'], true)) {
    echo "ERROR: Seed script only allowed in development/local environments!\n";
    exit(1);
}
```

In `unseed.php`, apply the same change to lines 22-25:

```php
if (!defined('WP_ENV') || !in_array(WP_ENV, ['development', 'local'], true)) {
    echo "ERROR: Unseed script only allowed in development/local environments!\n";
    exit(1);
}
```

- [ ] **Step 2: Commit**

```bash
git add scripts/seed.php scripts/unseed.php
git commit -m "fix(security): whitelist dev environments for seed scripts (S11)

- Scripts now require WP_ENV=development or WP_ENV=local explicitly
- Blocks execution when WP_ENV is undefined (misconfiguration)"
```

---

## Sprint 3: Query Optimization

### Task 16: Optimize Partner API getEnrollments N+1 (P3)

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Modules/PartnerAPI/PartnerAPIController.php:175-229`

- [ ] **Step 1: Batch-fetch related data after query**

Replace the `array_map` response builder (lines 201-221):

```php
$rows = $result['data'];

// Collect IDs for batch fetching
$userIds = array_unique(array_column($rows, 'user_id'));
$editionIds = array_filter(array_unique(array_map(fn($r) => (int) ($r->edition_id ?? 0), $rows)));

// Batch-fetch users
$users = [];
if (!empty($userIds)) {
    $userQuery = new \WP_User_Query(['include' => $userIds, 'fields' => ['ID', 'user_email']]);
    foreach ($userQuery->get_results() as $u) {
        $users[(int) $u->ID] = $u;
    }
}

// Batch-fetch editions + their course IDs
$editions = [];
$courseIds = [];
if (!empty($editionIds)) {
    $editionPosts = get_posts([
        'post_type' => 'vad_edition',
        'post__in' => $editionIds,
        'posts_per_page' => count($editionIds),
        'post_status' => 'any',
    ]);
    foreach ($editionPosts as $ep) {
        $editions[$ep->ID] = $ep;
    }

    // Batch-fetch course IDs from meta
    global $wpdb;
    $editionIdList = implode(',', array_map('intval', $editionIds));
    $metaRows = $wpdb->get_results(
        "SELECT post_id, meta_value FROM {$wpdb->postmeta}
         WHERE post_id IN ({$editionIdList}) AND meta_key = '_ntdst_course_id'"
    );
    $editionCourseMap = [];
    foreach ($metaRows as $mr) {
        $cid = (int) $mr->meta_value;
        $editionCourseMap[(int) $mr->post_id] = $cid;
        if ($cid) {
            $courseIds[] = $cid;
        }
    }
}

// Batch-fetch courses
$courses = [];
$courseIds = array_unique(array_filter($courseIds));
if (!empty($courseIds)) {
    $coursePosts = get_posts([
        'post_type' => 'sfwd-courses',
        'post__in' => $courseIds,
        'posts_per_page' => count($courseIds),
        'post_status' => 'any',
    ]);
    foreach ($coursePosts as $cp) {
        $courses[$cp->ID] = $cp;
    }
}

// Map results
$data = array_map(function ($row) use ($users, $editions, $editionCourseMap, $courses) {
    $editionId = $row->edition_id ? (int) $row->edition_id : 0;
    $courseId = $editionCourseMap[$editionId] ?? 0;

    return [
        'id' => (int) $row->id,
        'user_id' => (int) $row->user_id,
        'user_email' => ($users[(int) $row->user_id] ?? null)?->user_email,
        'edition_id' => $editionId ?: null,
        'edition_title' => ($editions[$editionId] ?? null)?->post_title,
        'course_title' => ($courses[$courseId] ?? null)?->post_title,
        'trajectory_id' => $row->trajectory_id ? (int) $row->trajectory_id : null,
        'status' => $row->status,
        'registered_at' => $row->registered_at ? gmdate('c', strtotime($row->registered_at)) : null,
        'completed_at' => $row->completed_at ? gmdate('c', strtotime($row->completed_at)) : null,
    ];
}, $rows);
```

This reduces from ~80 queries (20 results × 4 queries each) to ~5 queries total.

- [ ] **Step 2: Run Partner API tests**

Run: `ddev exec vendor/bin/phpunit --filter PartnerAPIController --testsuite Unit`

- [ ] **Step 3: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/PartnerAPI/PartnerAPIController.php
git commit -m "perf: batch-fetch related data in Partner API getEnrollments (P3)

- Replace per-row user/edition/course queries with batch fetches
- Reduces ~80 queries per page to ~5 queries total"
```

---

### Task 17: Optimize Partner API getCertificates (P1)

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Modules/PartnerAPI/PartnerAPIController.php:285-352`

- [ ] **Step 1: Query LearnDash activity table directly**

Replace the nested loop (lines 317-337) with a direct database query:

```php
$certificates = [];

// Query LearnDash activity table directly for completed courses
global $wpdb;
$userIdList = implode(',', array_map('intval', $userIds));
$completions = $wpdb->get_results(
    "SELECT user_id, post_id AS course_id, activity_completed AS completed_at
     FROM {$wpdb->prefix}learndash_user_activity
     WHERE user_id IN ({$userIdList})
       AND activity_type = 'course'
       AND activity_completed > 0
     ORDER BY activity_completed DESC"
);

if (empty($completions)) {
    return new WP_REST_Response([
        'data' => [],
        'total' => 0,
        'page' => $page,
        'per_page' => $perPage,
    ]);
}

// DB-level pagination
$total = count($completions);
$offset = ($page - 1) * $perPage;
$pageResults = array_slice($completions, $offset, $perPage);

// Batch-fetch users and courses for this page only
$pageUserIds = array_unique(array_column($pageResults, 'user_id'));
$pageCourseIds = array_unique(array_column($pageResults, 'course_id'));

$users = [];
if (!empty($pageUserIds)) {
    $userQuery = new \WP_User_Query(['include' => $pageUserIds, 'fields' => ['ID', 'user_email']]);
    foreach ($userQuery->get_results() as $u) {
        $users[(int) $u->ID] = $u;
    }
}

$coursePosts = [];
if (!empty($pageCourseIds)) {
    $posts = get_posts([
        'post_type' => 'sfwd-courses',
        'post__in' => array_map('intval', $pageCourseIds),
        'posts_per_page' => count($pageCourseIds),
        'post_status' => 'any',
    ]);
    foreach ($posts as $p) {
        $coursePosts[$p->ID] = $p;
    }
}

$data = array_map(function ($row) use ($users, $coursePosts) {
    $userId = (int) $row->user_id;
    $courseId = (int) $row->course_id;

    return [
        'user_id' => $userId,
        'user_email' => ($users[$userId] ?? null)?->user_email,
        'course_id' => $courseId,
        'course_title' => ($coursePosts[$courseId] ?? null)?->post_title,
        'completed_at' => gmdate('c', (int) $row->completed_at),
        'certificate_url' => function_exists('learndash_get_course_certificate_link')
            ? (learndash_get_course_certificate_link($courseId, $userId) ?: null)
            : null,
    ];
}, $pageResults);

return new WP_REST_Response([
    'data' => $data,
    'total' => $total,
    'page' => $page,
    'per_page' => $perPage,
]);
```

Note: `certificate_url` still requires per-row calls since LD stores certificate config in course meta + user completion. This is acceptable since we only call it for the current page (max 20 items).

- [ ] **Step 2: Run tests**

Run: `ddev exec vendor/bin/phpunit --filter PartnerAPIController --testsuite Unit`

- [ ] **Step 3: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/PartnerAPI/PartnerAPIController.php
git commit -m "perf: rewrite getCertificates with direct LD activity query (P1)

- Replace O(users×courses) nested loop with single DB query
- Batch-fetch users and courses for current page only
- Reduces 1000+ queries to ~4 queries for typical company"
```

---

### Task 18: Optimize Partner API getAttendance + Add Pagination (P2)

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Modules/PartnerAPI/PartnerAPIController.php:357-409`

- [ ] **Step 1: Rewrite with batch queries and pagination**

Replace the `getAttendance()` method:

```php
public function getAttendance(WP_REST_Request $request): WP_REST_Response|WP_Error
{
    $companyId = $this->getCompanyId();
    $editionId = $request->get_param('edition_id');
    $userId = $request->get_param('user_id');
    $page = max(1, absint($request->get_param('page') ?? 1));
    $perPage = min(100, max(1, absint($request->get_param('per_page') ?? 50)));

    // Get company user IDs
    $userQuery = new \WP_User_Query([
        'meta_key' => '_stride_company_id',
        'meta_value' => $companyId,
        'fields' => 'ID',
    ]);
    $companyUserIds = array_map('intval', $userQuery->get_results());

    if (empty($companyUserIds)) {
        return new WP_REST_Response(['data' => [], 'total' => 0, 'page' => $page, 'per_page' => $perPage]);
    }

    // If user_id provided, verify belongs to company
    if ($userId) {
        if (!in_array((int) $userId, $companyUserIds, true)) {
            return new WP_Error('rest_forbidden', __('User does not belong to your company.', 'stride'), ['status' => 403]);
        }
        $companyUserIds = [(int) $userId];
    }

    // Batch-fetch attendance records
    $records = $this->attendanceRepository->getByUsers($companyUserIds, $editionId ? (int) $editionId : null);

    // Collect session IDs and batch-fetch
    $sessionIds = array_unique(array_column($records, 'session_id'));
    $sessionsMap = [];
    if (!empty($sessionIds)) {
        foreach ($sessionIds as $sid) {
            $session = ntdst_data()->get('vad_session')->find((int) $sid);
            if ($session) {
                $sessionsMap[(int) $sid] = $session;
            }
        }
    }

    $total = count($records);
    $offset = ($page - 1) * $perPage;
    $pageRecords = array_slice($records, $offset, $perPage);

    $attendance = array_map(function ($record) use ($sessionsMap) {
        $session = $sessionsMap[(int) $record->session_id] ?? null;
        $sessionHours = $session ? ((float) ($session->fields['duration'] ?? 0)) / 60 : 0;

        return [
            'user_id' => (int) $record->user_id,
            'session_id' => (int) $record->session_id,
            'session_date' => $session ? ($session->fields['date'] ?? null) : null,
            'session_title' => $session ? ($session->post_title ?? null) : null,
            'status' => $record->status,
            'hours' => round($sessionHours, 2),
        ];
    }, $pageRecords);

    return new WP_REST_Response([
        'data' => $attendance,
        'total' => $total,
        'page' => $page,
        'per_page' => $perPage,
    ]);
}
```

- [ ] **Step 2: Add batch attendance query method**

If `AttendanceRepository` doesn't have a `getByUsers()` method, add one:

```php
/**
 * Get attendance records for multiple users.
 *
 * @param array<int> $userIds
 */
public function getByUsers(array $userIds, ?int $editionId = null): array
{
    global $wpdb;

    $placeholders = implode(',', array_fill(0, count($userIds), '%d'));
    $params = $userIds;

    $sql = "SELECT * FROM {$this->table()} WHERE user_id IN ({$placeholders})";

    if ($editionId !== null) {
        $sql .= " AND edition_id = %d";
        $params[] = $editionId;
    }

    $sql .= " ORDER BY marked_at DESC";

    return $wpdb->get_results($wpdb->prepare($sql, ...$params));
}
```

- [ ] **Step 3: Run tests**

Run: `ddev exec vendor/bin/phpunit --filter PartnerAPIController --testsuite Unit`

- [ ] **Step 4: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/PartnerAPI/PartnerAPIController.php
git add web/app/mu-plugins/stride-core/Modules/Attendance/AttendanceRepository.php
git commit -m "perf: batch attendance queries + add pagination to Partner API (P2)

- Replace per-user attendance queries with single batch query
- Batch-fetch session data from collected IDs
- Add pagination support (was previously unbounded)"
```

---

### Task 19: Optimize UserDashboardService (P4)

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Modules/User/UserDashboardService.php`

This is the highest-effort task. The dashboard home page triggers 150-200+ queries. The fix involves:
1. Fetch registrations once and pass everywhere
2. Batch-fetch edition data upfront
3. Batch-fetch session data for all editions
4. Pre-build edition→courseId map

- [ ] **Step 1: Pass registrations as parameter instead of re-querying**

In `getEnrollmentData()`, fetch registrations once and pass to sub-methods:

```php
public function getEnrollmentData(int $userId): array
{
    // Fetch registrations once for the entire method
    $allRegistrations = $this->registrationRepo->findByUser($userId);

    [$activeEditions, $completedEditions, $cancelledEditions] = $this->buildEditionRegistrations($userId, $allRegistrations);
    [$activeOnline, $completedOnline] = $this->buildOnlineCourses($userId);

    $completedItems = array_merge($completedEditions, $completedOnline);
    usort($completedItems, fn($a, $b) => strcmp($b['completed_at'] ?? '', $a['completed_at'] ?? ''));

    return [
        'active_editions'    => $activeEditions,
        'active_online'      => $activeOnline,
        'completed_items'    => $completedItems,
        'cancelled_editions' => $cancelledEditions,
        'upcoming_sessions'  => $this->buildUpcomingSessions($activeEditions),
        'action_items'       => $this->buildActionItems($userId, $allRegistrations),
    ];
}
```

- [ ] **Step 2: Update buildEditionRegistrations to accept registrations**

Change signature from `private function buildEditionRegistrations(int $userId)` to `private function buildEditionRegistrations(int $userId, array $registrations)` and remove the internal `findByUser` call at line 426.

- [ ] **Step 3: Update buildOnlineLessonActions to accept registrations**

Change `buildOnlineLessonActions()` signature to accept `$registrations` and remove the `findByUser` call at line 347.

Same for `buildActionItems()` — pass registrations through to `buildOnlineLessonActions()`.

- [ ] **Step 4: Pre-build edition data map in buildEditionRegistrations**

At the start of `buildEditionRegistrations()`, collect all edition IDs and batch-fetch:

```php
// Collect all edition IDs
$editionIds = array_filter(array_map(fn($r) => (int) ($r->edition_id ?? 0), $registrations));

if (empty($editionIds)) {
    return [[], [], []];
}

// Batch-fetch edition posts
$editionPosts = get_posts([
    'post_type' => 'vad_edition',
    'post__in' => $editionIds,
    'posts_per_page' => count($editionIds),
    'post_status' => 'any',
]);
$editionMap = [];
foreach ($editionPosts as $ep) {
    $editionMap[$ep->ID] = $ep;
}

// Batch-fetch courseId meta
global $wpdb;
$idList = implode(',', array_map('intval', $editionIds));
$metaRows = $wpdb->get_results(
    "SELECT post_id, meta_value FROM {$wpdb->postmeta}
     WHERE post_id IN ({$idList}) AND meta_key = '_ntdst_course_id'"
);
$courseIdMap = [];
$courseIds = [];
foreach ($metaRows as $mr) {
    $courseIdMap[(int) $mr->post_id] = (int) $mr->meta_value;
    if ((int) $mr->meta_value) {
        $courseIds[] = (int) $mr->meta_value;
    }
}

// Batch-fetch course posts
$courseMap = [];
$courseIds = array_unique(array_filter($courseIds));
if (!empty($courseIds)) {
    $coursePosts = get_posts([
        'post_type' => 'sfwd-courses',
        'post__in' => $courseIds,
        'posts_per_page' => count($courseIds),
        'post_status' => 'any',
    ]);
    foreach ($coursePosts as $cp) {
        $courseMap[$cp->ID] = $cp;
    }
}
```

Then in the loop, replace individual calls:
- `$this->editionService->getEdition($editionId)` → `$editionMap[$editionId] ?? null`
- `$this->editionService->getCourseId($editionId)` → `$courseIdMap[$editionId] ?? 0`
- `get_post($courseId)` → `$courseMap[$courseId] ?? null`

For `isOnline()`, use the pre-fetched course data and check taxonomy:
```php
// Replace $this->editionService->isOnline($editionId) with inline check:
$courseId = $courseIdMap[$editionId] ?? 0;
if ($courseId) {
    $formats = get_the_terms($courseId, 'stride_format');
    $isOnline = $formats && !is_wp_error($formats) &&
        !empty(array_filter($formats, fn($f) => in_array($f->slug, ['online', 'webinar', 'e-learning'], true)));
    if ($isOnline) {
        continue;
    }
}
```

- [ ] **Step 5: Run tests**

Run: `ddev exec vendor/bin/phpunit --filter UserDashboard --testsuite Unit`

- [ ] **Step 6: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/User/UserDashboardService.php
git commit -m "perf: batch-prefetch data in UserDashboardService (P4)

- Fetch registrations once, pass to all sub-methods
- Batch-fetch editions, courses, and courseId meta upfront
- Eliminates ~100 duplicate queries per dashboard load"
```

---

### Task 20: Optimize ICalHandler (P6)

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Handlers/ICalHandler.php:85-155`

- [ ] **Step 1: Cache edition/course lookups in sessionToEvent**

Add a local cache in the handler class:

```php
/** @var array<int, array{edition: WP_Post|null, courseTitle: string, venue: string}> */
private array $editionCache = [];
```

In `sessionToEvent()`, replace the per-call lookups:

```php
private function sessionToEvent(array $session, EditionService $editionService): array
{
    $editionId = (int) $session['edition_id'];

    if (!isset($this->editionCache[$editionId])) {
        $edition = $editionService->getEdition($editionId);
        $courseId = is_wp_error($edition) ? 0 : $editionService->getCourseId($editionId);
        $course = $courseId ? get_post($courseId) : null;
        $editionRepository = ntdst_get(EditionRepository::class);
        $venue = !is_wp_error($edition)
            ? $editionRepository->getField($edition->ID, 'venue', '')
            : '';

        $this->editionCache[$editionId] = [
            'courseTitle' => $course ? $course->post_title : 'Stride Training',
            'venue' => $venue,
        ];
    }

    $cached = $this->editionCache[$editionId];
    $date = $session['date'] ?? '';
    $startTime = $session['start_time'] ?? '';
    $endTime = $session['end_time'] ?? '';

    return [
        'uid' => 'session-' . $session['id'] . '@stride',
        'summary' => $cached['courseTitle'],
        'description' => $session['description'] ?? '',
        'location' => $session['location'] ?: $cached['venue'],
        'start' => $date && $startTime ? ($date . ' ' . $startTime) : '',
        'end' => $date && $endTime ? ($date . ' ' . $endTime) : '',
    ];
}
```

- [ ] **Step 2: Commit**

```bash
git add web/app/mu-plugins/stride-core/Handlers/ICalHandler.php
git commit -m "perf: cache edition/course data in ICalHandler (P6)

- sessionToEvent now caches per edition ID
- Eliminates repeated lookups when multiple sessions share an edition"
```

---

## Backlog (deferred, low priority)

These items are documented for future reference but not included in the sprint plan:

| ID | Finding | Notes |
|----|---------|-------|
| S7 | Unescaped LD button HTML | Wrap with `wp_kses_post()`. Risk requires LD vulnerability first. |
| S10 | Predictable billing transient keys | Add random token to key. Low exploitability. |
| S12 | Weak transient rate limiter | Replace with Redis-based counter when object cache is added. |
| S13 | Loose admin capability | Create custom `stride_manage_enrollments` capability. Needs role audit. |
| P13 | getUniqueDates fetches full sessions | Use `SELECT DISTINCT date` query. |
| P15 | SCORM detection per-page | Cache result or simplify check. |
| P16 | Menu item per-item get_post | Pre-fetch or use URL-based matching. |

---

## Verification

After all sprints, run:

```bash
# Full unit test suite
ddev exec vendor/bin/phpunit --testsuite Unit

# Full integration test suite
ddev exec vendor/bin/phpunit --testsuite Integration

# Verify no PHP errors on key pages
ddev exec wp eval "echo 'All services loaded OK';"
```
