# Stride-Core Audit Remediation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Fix critical issues identified in stride-core audit: broken tests, register handlers, fix unbounded query.

**Architecture:** Address test failures first (blocking CI), then register missing handlers, then fix anti-patterns in order of severity.

**Tech Stack:** PHP 8.3, WordPress, Codeception, PHPUnit

---

## Phase 1: Fix Broken Tests (Critical)

### Task 1: Remove Obsolete AuditServiceTest

The `AuditService` was refactored to `AuditBridge` which delegates to the external `ntdst-audit` plugin. The tests reference a non-existent `AuditRepository` class.

**Files:**
- Delete: `tests/Unit/AuditServiceTest.php`

**Step 1: Verify the test file exists and is obsolete**

Check that `Stride\Modules\Audit\AuditRepository` doesn't exist:
```bash
ddev exec grep -r "class AuditRepository" web/app/mu-plugins/stride-core/
```
Expected: No matches (class was removed)

**Step 2: Delete the obsolete test file**

```bash
rm tests/Unit/AuditServiceTest.php
```

**Step 3: Run PHPUnit to verify remaining tests pass**

```bash
ddev exec vendor/bin/phpunit --testdox
```
Expected: Only FieldRegistryTest and Integration tests should run, all should pass.

**Step 4: Commit**

```bash
git add -A tests/Unit/
git commit -m "chore(tests): remove obsolete AuditServiceTest

AuditService was refactored to AuditBridge which delegates to
external ntdst-audit plugin. Tests referenced non-existent
AuditRepository class."
```

---

### Task 2: Write AuditBridge Unit Tests

Replace the obsolete tests with tests for the new `AuditBridge` class.

**Files:**
- Create: `tests/Unit/AuditBridgeTest.php`

**Step 1: Write the test file**

```php
<?php

namespace Stride\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Stride\Modules\Audit\AuditBridge;

/**
 * Unit Test: AuditBridge
 *
 * Tests the audit bridge's event handling and delegation to ntdst-audit.
 */
class AuditBridgeTest extends TestCase
{
    private array $recordedCalls = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->recordedCalls = [];
    }

    public function testMetadataReturnsCorrectStructure(): void
    {
        $metadata = AuditBridge::metadata();

        $this->assertIsArray($metadata);
        $this->assertArrayHasKey('name', $metadata);
        $this->assertArrayHasKey('description', $metadata);
        $this->assertArrayHasKey('priority', $metadata);
        $this->assertEquals('Audit Bridge', $metadata['name']);
        $this->assertEquals(99, $metadata['priority']);
    }

    public function testOnRegistrationCreatedExtractsCorrectData(): void
    {
        // Mock ntdst_get to capture the record() call
        $this->mockAuditService();

        $bridge = $this->createBridgeWithoutInit();
        $bridge->onRegistrationCreated([
            'registration_id' => 123,
            'user_id' => 456,
            'edition_id' => 789,
            'enrolled_by' => 100,
            'enrollment_path' => 'individual',
        ]);

        $this->assertCount(1, $this->recordedCalls);
        $call = $this->recordedCalls[0];

        $this->assertEquals('registration', $call['entity_type']);
        $this->assertEquals(123, $call['entity_id']);
        $this->assertEquals('registration.created', $call['action']);
        $this->assertEquals(100, $call['actor_id']); // enrolled_by takes precedence
    }

    public function testOnAttendanceMarkedMapsStatusToAction(): void
    {
        $this->mockAuditService();
        $bridge = $this->createBridgeWithoutInit();

        $testCases = [
            'present' => 'attendance.marked_present',
            'absent' => 'attendance.marked_absent',
            'excused' => 'attendance.marked_excused',
            'unknown' => 'attendance.marked',
        ];

        foreach ($testCases as $status => $expectedAction) {
            $this->recordedCalls = [];

            $bridge->onAttendanceMarked([
                'attendance_id' => 1,
                'status' => $status,
            ]);

            $this->assertEquals(
                $expectedAction,
                $this->recordedCalls[0]['action'],
                "Status '$status' should map to action '$expectedAction'"
            );
        }
    }

    private function mockAuditService(): void
    {
        // Store reference for closure
        $recordedCalls = &$this->recordedCalls;

        // Override ntdst_get to return mock
        global $_test_ntdst_get_overrides;
        $_test_ntdst_get_overrides[\NTDST\Audit\AuditService::class] = new class($recordedCalls) {
            private array $calls;

            public function __construct(array &$calls)
            {
                $this->calls = &$calls;
            }

            public function record(
                string $entityType,
                int $entityId,
                string $action,
                ?int $actorId,
                array $context
            ): void {
                $this->calls[] = [
                    'entity_type' => $entityType,
                    'entity_id' => $entityId,
                    'action' => $action,
                    'actor_id' => $actorId,
                    'context' => $context,
                ];
            }
        };
    }

    private function createBridgeWithoutInit(): AuditBridge
    {
        // Use reflection to create instance without calling constructor
        $reflection = new \ReflectionClass(AuditBridge::class);
        return $reflection->newInstanceWithoutConstructor();
    }
}
```

**Step 2: Update test stubs to support ntdst_get overrides**

Add to `tests/Stubs/wordpress-stubs.php`:

```php
// Global override storage for testing
global $_test_ntdst_get_overrides;
$_test_ntdst_get_overrides = [];

if (!function_exists('ntdst_get')) {
    function ntdst_get(string $class) {
        global $_test_ntdst_get_overrides;
        if (isset($_test_ntdst_get_overrides[$class])) {
            return $_test_ntdst_get_overrides[$class];
        }
        // Return mock or throw
        throw new \RuntimeException("ntdst_get($class) not mocked");
    }
}
```

**Step 3: Run tests**

```bash
ddev exec vendor/bin/phpunit tests/Unit/AuditBridgeTest.php --testdox
```
Expected: All tests pass.

**Step 4: Commit**

```bash
git add tests/Unit/AuditBridgeTest.php tests/Stubs/
git commit -m "test(audit): add AuditBridge unit tests

Replace obsolete AuditServiceTest with tests for the new
AuditBridge that delegates to ntdst-audit plugin."
```

---

## Phase 2: Register Missing Handlers

### Task 3: Register Handler Classes in plugin-config.php

Handlers are instantiated by their constructors registering hooks, but they're never loaded.

**Files:**
- Modify: `web/app/mu-plugins/stride-core/plugin-config.php`

**Step 1: Check if handlers have hook registrations in constructors**

```bash
ddev exec grep -A5 "public function __construct" web/app/mu-plugins/stride-core/Handlers/*.php
```
Expected: Each handler's constructor calls `$this->init()` or registers hooks directly.

**Step 2: Add handlers to services array**

Edit `web/app/mu-plugins/stride-core/plugin-config.php`:

```php
'services' => [
    \Stride\Admin\AdminDashboardService::class,
    \Stride\Admin\AdminAPIController::class,
    \Stride\Modules\Edition\EditionService::class,
    \Stride\Modules\Edition\SessionService::class,
    \Stride\Modules\Edition\SessionSelectionService::class,
    \Stride\Modules\Edition\Admin\EditionAdminController::class,
    \Stride\Modules\Enrollment\EnrollmentService::class,
    \Stride\Modules\Invoicing\QuoteService::class,
    \Stride\Modules\Invoicing\VoucherService::class,
    \Stride\Modules\Invoicing\Admin\QuoteAdminController::class,
    \Stride\Modules\Invoicing\Admin\VoucherAdminController::class,
    \Stride\Modules\Trajectory\TrajectoryService::class,
    \Stride\Modules\Trajectory\TrajectorySelectionService::class,
    \Stride\Modules\Trajectory\Admin\TrajectoryAdminController::class,
    \Stride\Modules\Attendance\AttendanceService::class,
    \Stride\Modules\Completion\CompletionService::class,
    \Stride\Modules\Audit\AuditBridge::class,

    // Handlers (event listeners)
    \Stride\Handlers\EnrollmentQuoteHandler::class,
    \Stride\Handlers\EnrollmentFormHandler::class,
    \Stride\Handlers\QuoteUpdateHandler::class,
    \Stride\Handlers\ProfileHandler::class,
    \Stride\Handlers\ICalHandler::class,
],
```

**Step 3: Make handlers extend AbstractService or implement NTDST_Service_Meta**

Check if handlers need to implement the interface. If they don't, they won't be loaded by the Bootstrap.

Option A: Convert handlers to services (recommended for consistency)
Option B: Load handlers via a different mechanism

For now, verify handlers are instantiated by checking if their hooks fire.

**Step 4: Test that handlers are loaded**

```bash
ddev exec wp eval "
if (class_exists('\Stride\Handlers\EnrollmentQuoteHandler')) {
    echo 'EnrollmentQuoteHandler class exists' . PHP_EOL;
}
if (has_action('stride/registration/created')) {
    echo 'stride/registration/created hook registered' . PHP_EOL;
}
"
```
Expected: Both should be true.

**Step 5: Commit**

```bash
git add web/app/mu-plugins/stride-core/plugin-config.php
git commit -m "fix(handlers): register handler classes in plugin-config

Handlers were not being instantiated because they weren't in
the services array. Now properly registered for auto-loading."
```

---

## Phase 3: Fix Unbounded Query

### Task 4: Add Limit to Course Dropdown Query

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Modules/Edition/Admin/EditionDetailsMetabox.php:188`

**Step 1: Locate the unbounded query**

```bash
ddev exec grep -n "posts_per_page.*-1" web/app/mu-plugins/stride-core/Modules/Edition/Admin/EditionDetailsMetabox.php
```
Expected: Line 188

**Step 2: Add reasonable limit**

Change from:
```php
'posts_per_page' => -1,
```

To:
```php
'posts_per_page' => 200, // Reasonable limit for course dropdown
```

**Step 3: Verify the change**

```bash
ddev exec grep -n "posts_per_page" web/app/mu-plugins/stride-core/Modules/Edition/Admin/EditionDetailsMetabox.php
```
Expected: `'posts_per_page' => 200,`

**Step 4: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Edition/Admin/EditionDetailsMetabox.php
git commit -m "perf(edition): limit course dropdown query to 200

Prevents memory issues if site has thousands of courses.
200 is reasonable for a dropdown selector."
```

---

## Verification Stages (MANDATORY)

### Stage V1: Static Analysis

```bash
# Check modified files for syntax errors
ddev exec php -l web/app/mu-plugins/stride-core/plugin-config.php
ddev exec php -l web/app/mu-plugins/stride-core/Modules/Edition/Admin/EditionDetailsMetabox.php
```
Expected: No syntax errors.

### Stage V2: Unit Tests

```bash
ddev exec vendor/bin/phpunit --testdox
```
Expected: All tests pass (no more AuditRepository errors).

### Stage V3: Smoke Test

```bash
# Verify site loads
ddev exec curl -s -o /dev/null -w "%{http_code}" https://stride.ddev.site/
```
Expected: 200

```bash
# Verify admin loads
ddev exec wp eval "echo 'WordPress loaded: ' . get_bloginfo('name');"
```
Expected: Site name printed.

### Stage V4: Handler Registration Verification

```bash
ddev exec wp eval "
\$hooks = [
    'stride/registration/created',
    'wp_ajax_stride_update_profile',
    'wp_ajax_stride_enrollment_submit',
];
foreach (\$hooks as \$hook) {
    \$has = has_action(\$hook) ? 'YES' : 'NO';
    echo \"\$hook: \$has\" . PHP_EOL;
}
"
```
Expected: All hooks show YES.

### Stage V5: Manual Smoke Test

- [ ] Visit: https://stride.ddev.site/wp-admin/
      Expected: Admin dashboard loads without errors
- [ ] Navigate to: Editions > Add New
      Expected: Course dropdown loads (verify not 0 courses if data exists)
- [ ] Console: Open DevTools > Console
      Expected: No PHP fatal errors or JS errors

---

## Deferred Items (Future Work)

The following items from the audit are lower priority and should be addressed in separate plans:

1. **Large Controller Refactoring** — Split AdminAPIController (1636 lines) into focused classes
2. **Replace false/null with WP_Error** — 45+ instances across services
3. **Replace direct get_post_meta** — 63+ instances (use Data Manager pattern)
4. **Add comprehensive test coverage** — Most services have zero unit tests
5. **Add acceptance tests** — Only smoke tests exist currently

Each of these warrants its own implementation plan due to scope.
