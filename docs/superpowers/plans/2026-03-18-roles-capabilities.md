# Roles & Capabilities Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add WordPress capabilities (`stride_manage`, `stride_view`) and two new roles (`stride_coordinator`, `stride_supervisor`) to gate Stride admin access at three tiers: full admin, operational coordinator, and read-only supervisor.

**Architecture:** Define capabilities first, bundle them into roles, then swap hardcoded capability checks across AdminDashboardService, AdminAPIController, AdminGuidePage, and the dashboard template. Uses version-gated role registration so capability changes propagate to existing installs.

**Tech Stack:** WordPress roles/capabilities API, PHP 8.1+, Alpine.js (dashboard template guards)

**Spec:** `docs/superpowers/specs/2026-03-18-roles-capabilities-design.md`

---

## File Map

| File | Action | Responsibility |
|------|--------|---------------|
| `web/app/mu-plugins/stride-core/stride-core.php` | Modify | Register capabilities + roles with version-gated migration |
| `web/app/mu-plugins/stride-core/Admin/AdminAPIController.php` | Modify | Replace `canAccessAdmin()` with `canViewAdmin()` + `canManageAdmin()` |
| `web/app/mu-plugins/stride-core/Admin/AdminDashboardService.php` | Modify | Change CAPABILITY constant, add `canManage` to StrideConfig |
| `web/app/mu-plugins/stride-core/Admin/AdminGuidePage.php` | Modify | Change CAPABILITY constant |
| `web/app/mu-plugins/stride-core/templates/admin/dashboard.php` | Modify | PHP guards on static links, Alpine guards on action buttons |
| `web/app/mu-plugins/stride-core/assets/js/admin-dashboard.js` | Modify | Add `canManage` to Alpine component data |
| `scripts/seed.php` | Modify | Add coordinator + supervisor seed users |
| `tests/Unit/AdminAPIControllerTest.php` | Create | Unit tests for permission methods |
| `tests/Integration/AdminRolesIntegrationTest.php` | Create | Integration tests for role-based access |

---

### Task 1: Register Capabilities and Roles

**Files:**
- Modify: `web/app/mu-plugins/stride-core/stride-core.php:46-51`

- [ ] **Step 1: Add version-gated role registration after the existing partner role block**

In `stride-core.php`, after the partner role registration (line 51), add:

```php
// Register Stride capabilities and roles (version-gated for updates)
add_action('init', function (): void {
    $rolesVersion = 1;
    $currentVersion = (int) get_option('stride_roles_version', 0);

    if ($currentVersion < $rolesVersion) {
        // Remove existing roles so capability changes are applied
        remove_role('stride_coordinator');
        remove_role('stride_supervisor');

        // Training Coordinator — full Stride management, no WordPress settings
        add_role('stride_coordinator', 'Training Coordinator', [
            'read'              => true,
            'edit_posts'        => true,
            'edit_others_posts' => true,
            'publish_posts'     => true,
            'delete_posts'      => true,
            'upload_files'      => true,
            'stride_manage'     => true,
            'stride_view'       => true,
        ]);

        // Supervisor — read-only Stride access
        add_role('stride_supervisor', 'Supervisor', [
            'read'        => true,
            'stride_view' => true,
        ]);

        // Ensure administrator has Stride capabilities
        $admin = get_role('administrator');
        if ($admin) {
            $admin->add_cap('stride_manage');
            $admin->add_cap('stride_view');
        }

        update_option('stride_roles_version', $rolesVersion);
    }
}, 1);
```

- [ ] **Step 2: Verify roles are registered**

```bash
ddev exec wp role list --format=table
```

Expected: `stride_coordinator` and `stride_supervisor` appear in the list.

- [ ] **Step 3: Verify capabilities**

```bash
ddev exec wp cap list stride_coordinator
ddev exec wp cap list stride_supervisor
ddev exec wp eval "var_dump(get_role('administrator')->has_cap('stride_manage'));"
```

Expected: coordinator has 8 caps including `stride_manage` + `stride_view`. Supervisor has `read` + `stride_view`. Admin has `stride_manage` = true.

- [ ] **Step 4: Commit**

```bash
git add web/app/mu-plugins/stride-core/stride-core.php
git commit -m "feat(roles): register stride_coordinator and stride_supervisor roles with capabilities"
```

---

### Task 2: Update AdminAPIController Permission Methods

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Admin/AdminAPIController.php:248-254` (permission method)
- Modify: `web/app/mu-plugins/stride-core/Admin/AdminAPIController.php:45-245` (route registrations)

- [ ] **Step 1: Replace `canAccessAdmin()` with two permission methods**

Replace lines 248-254:

```php
    /**
     * Permission callback for admin endpoints.
     */
    public function canAccessAdmin(): bool
    {
        return current_user_can('edit_others_posts');
    }
```

With:

```php
    /**
     * Permission callback for read-only admin endpoints.
     */
    public function canViewAdmin(): bool
    {
        return current_user_can('stride_view');
    }

    /**
     * Permission callback for mutation admin endpoints.
     */
    public function canManageAdmin(): bool
    {
        return current_user_can('stride_manage');
    }
```

- [ ] **Step 2: Update GET endpoint registrations to use `canViewAdmin`**

In `registerRoutes()`, change all GET endpoint `permission_callback` values from `canAccessAdmin` to `canViewAdmin`. These are on the following routes:

- `/admin/stats` (line 51)
- `/admin/editions` (line 58)
- `/admin/editions/(?P<id>\d+)` (line 103)
- `/admin/editions/(?P<id>\d+)/registrations` (line 116)
- `/admin/course-tags` (line 129)
- `/admin/quotes` (line 158)
- `/admin/trajectories` (line 190)
- `/admin/pending-approvals` (line 218)

Replace each:
```php
'permission_callback' => [$this, 'canAccessAdmin'],
```
With:
```php
'permission_callback' => [$this, 'canViewAdmin'],
```

- [ ] **Step 3: Update POST endpoint registrations to use `canManageAdmin`**

Change POST endpoint `permission_callback` values from `canAccessAdmin` to `canManageAdmin`:

- `/admin/attendance` (line 136)
- `/admin/approve-registration` (line 225)
- `/admin/approve-post-course` (line 238)

Replace each:
```php
'permission_callback' => [$this, 'canAccessAdmin'],
```
With:
```php
'permission_callback' => [$this, 'canManageAdmin'],
```

- [ ] **Step 4: Verify no references to `canAccessAdmin` remain**

```bash
ddev exec grep -rn 'canAccessAdmin' web/app/mu-plugins/stride-core/
```

Expected: no results.

- [ ] **Step 5: Commit**

```bash
git add web/app/mu-plugins/stride-core/Admin/AdminAPIController.php
git commit -m "feat(roles): split canAccessAdmin into canViewAdmin/canManageAdmin for tiered permissions"
```

---

### Task 3: Update AdminDashboardService and AdminGuidePage

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Admin/AdminDashboardService.php:21` (CAPABILITY constant)
- Modify: `web/app/mu-plugins/stride-core/Admin/AdminDashboardService.php:189-197` (StrideConfig)
- Modify: `web/app/mu-plugins/stride-core/Admin/AdminGuidePage.php:18` (CAPABILITY constant)

- [ ] **Step 1: Change AdminDashboardService CAPABILITY constant**

In `AdminDashboardService.php` line 21, change:

```php
    private const CAPABILITY = 'edit_others_posts';
```

To:

```php
    private const CAPABILITY = 'stride_view';
```

- [ ] **Step 2: Add `canManage` to StrideConfig**

In `AdminDashboardService.php`, in the `enqueueAssets()` method, update the `wp_localize_script` call (lines 189-197). Change:

```php
        wp_localize_script('alpinejs', 'StrideConfig', [
            'apiUrl' => rest_url('stride/v1'),
            'nonce' => wp_create_nonce('wp_rest'),
            'user' => [
                'id' => $user->ID,
                'name' => $user->display_name,
                'email' => $user->user_email,
            ],
        ]);
```

To:

```php
        wp_localize_script('alpinejs', 'StrideConfig', [
            'apiUrl' => rest_url('stride/v1'),
            'nonce' => wp_create_nonce('wp_rest'),
            'user' => [
                'id' => $user->ID,
                'name' => $user->display_name,
                'email' => $user->user_email,
            ],
            'canManage' => current_user_can('stride_manage'),
        ]);
```

- [ ] **Step 3: Change AdminGuidePage CAPABILITY constant**

In `AdminGuidePage.php` line 18, change:

```php
    private const CAPABILITY = 'edit_others_posts';
```

To:

```php
    private const CAPABILITY = 'stride_view';
```

Also update the render check on line 39:

```php
        if (!current_user_can(self::CAPABILITY)) {
```

This already references the constant, so no code change needed — just verify it uses `self::CAPABILITY`.

- [ ] **Step 4: Commit**

```bash
git add web/app/mu-plugins/stride-core/Admin/AdminDashboardService.php web/app/mu-plugins/stride-core/Admin/AdminGuidePage.php
git commit -m "feat(roles): gate dashboard menu on stride_view, pass canManage to frontend"
```

---

### Task 4: Add Permission Guards to Dashboard Template

**Files:**
- Modify: `web/app/mu-plugins/stride-core/templates/admin/dashboard.php` (lines 37, 165, 312-340, 442, 490, 586, 801, 987)
- Modify: `web/app/mu-plugins/stride-core/assets/js/admin-dashboard.js` (Alpine component data init)

- [ ] **Step 1: Add `canManage` to Alpine component data**

In `admin-dashboard.js`, find the Alpine component's `data()` return object (the `strideApp` function). Add `canManage` to the data initialization:

```javascript
canManage: StrideConfig.canManage,
```

Add this alongside the existing data properties (near `apiUrl`, `nonce`, `user`, etc.).

- [ ] **Step 2: Add PHP guards to static quick action links**

In `dashboard.php`, wrap the quick action links in PHP capability checks. Around lines 312-324, change the links:

Wrap "Nieuwe Edition" (line ~312):
```php
<?php if (current_user_can('stride_manage')): ?>
<a href="<?php echo esc_url($admin_url . 'post-new.php?post_type=vad_edition'); ?>" class="stride-quick-action">
    <!-- existing icon SVG -->
    <span>Nieuwe Edition</span>
</a>
<?php endif; ?>
```

Wrap "Nieuw Traject" (line ~316):
```php
<?php if (current_user_can('stride_manage')): ?>
<a href="<?php echo esc_url($admin_url . 'post-new.php?post_type=vad_trajectory'); ?>" class="stride-quick-action">
    <!-- existing icon SVG -->
    <span>Nieuw Traject</span>
</a>
<?php endif; ?>
```

Wrap "Gebruikers" (line ~324):
```php
<?php if (current_user_can('stride_manage')): ?>
<a href="<?php echo esc_url($admin_url . 'users.php'); ?>" class="stride-quick-action">
    <!-- existing icon SVG -->
    <span>Gebruikers</span>
</a>
<?php endif; ?>
```

Wrap "New Edition" button in editions header (line ~340):
```php
<?php if (current_user_can('stride_manage')): ?>
<a href="<?php echo esc_url($admin_url . 'post-new.php?post_type=vad_edition'); ?>" class="stride-btn stride-btn-primary">New Edition</a>
<?php endif; ?>
```

- [ ] **Step 3: Add Alpine guards to dynamic action buttons**

In `dashboard.php`, add `x-show="canManage"` to Alpine-controlled mutation elements:

Approve/Aftekenen button in pending approvals (line ~165-170):
```html
<button x-show="canManage" class="stride-btn stride-btn-primary stride-btn-sm" @click="approveRegistration(approval.id)" :disabled="approval.approving">
```

Edit button in agenda view (line ~442):
```html
<a x-show="canManage" :href="item.editUrl" class="stride-btn stride-btn-sm stride-btn-outline" @click.stop>Edit</a>
```

Edit button in list view (line ~490):
```html
<a x-show="canManage" :href="edition.editUrl" class="stride-btn stride-btn-sm stride-btn-outline" @click.stop>Edit</a>
```

Attendance buttons (line ~586) — conditional render: interactive button for managers, read-only indicator for supervisors:
```html
<template x-if="canManage">
    <button class="stride-attendance-btn" @click="toggleAttendance(session.id, reg.userId, reg.attendance ? reg.attendance[session.id] : null)">
        <!-- existing toggle button content -->
    </button>
</template>
<template x-if="!canManage">
    <span class="stride-attendance-status"
          x-text="(reg.attendance && reg.attendance[session.id]) ? reg.attendance[session.id] : '—'">
    </span>
</template>
```

Note: For attendance, use `x-if` instead of `x-show` to prevent the button element from being in the DOM at all. Supervisors see the attendance status as text but cannot toggle it.

Quote detail "Edit in WP Admin" (line ~801):
```html
<a x-show="canManage" :href="selectedQuote.editUrl" class="stride-btn stride-btn-primary">Edit in WP Admin</a>
```

Trajectory detail "Bewerken in WP Admin" (line ~987):
```html
<a x-show="canManage" :href="selectedTrajectory.editUrl" class="stride-btn stride-btn-primary">Bewerken in WP Admin</a>
```

- [ ] **Step 4: Verify template renders without errors**

```bash
ddev launch /wp-admin/?page=stride-dashboard
```

Expected: Dashboard loads without PHP or JS errors. Check browser console.

- [ ] **Step 5: Commit**

```bash
git add web/app/mu-plugins/stride-core/templates/admin/dashboard.php web/app/mu-plugins/stride-core/assets/js/admin-dashboard.js
git commit -m "feat(roles): add permission guards to dashboard template for read-only supervisor role"
```

---

### Task 5: Update Seed Script

**Files:**
- Modify: `scripts/seed.php:159-166` (users array)

- [ ] **Step 1: Add coordinator and supervisor to seed users**

In `scripts/seed.php`, in the `createUsers()` method's `$users` array, insert before the closing `];` bracket (line 166):

```php
['login' => 'seed_coordinator', 'email' => 'seed_coordinator@seed.test', 'role' => 'stride_coordinator', 'first' => 'Coordinator', 'last' => 'Seed'],
['login' => 'seed_supervisor', 'email' => 'seed_supervisor@seed.test', 'role' => 'stride_supervisor', 'first' => 'Supervisor', 'last' => 'Seed'],
```

- [ ] **Step 2: Run seed script to verify**

```bash
ddev exec bash -c 'FORCE_UNSEED=1 wp eval-file scripts/unseed.php'
ddev exec wp eval-file scripts/seed.php
```

Expected: "seed_coordinator" and "seed_supervisor" users created without errors.

- [ ] **Step 3: Verify new users have correct roles**

```bash
ddev exec wp user get seed_coordinator --field=roles
ddev exec wp user get seed_supervisor --field=roles
```

Expected: `stride_coordinator` and `stride_supervisor` respectively.

- [ ] **Step 4: Commit**

```bash
git add scripts/seed.php
git commit -m "feat(roles): add coordinator and supervisor seed users"
```

---

### Task 6: Unit Tests for Permission Methods

**Files:**
- Create: `tests/Unit/AdminAPIControllerTest.php`

- [ ] **Step 1: Write unit tests**

```php
<?php

declare(strict_types=1);

namespace Stride\Tests\Unit;

use Stride\Admin\AdminAPIController;
use Stride\Modules\Attendance\AttendanceRepository;
use Stride\Modules\Edition\EditionRepository;
use Stride\Modules\Edition\SessionRepository;
use Stride\Tests\TestCase;

/**
 * Unit tests for AdminAPIController permission methods.
 *
 * Run: ddev exec vendor/bin/phpunit --filter AdminAPIController --testsuite Unit
 */
class AdminAPIControllerTest extends TestCase
{
    private AdminAPIController $controller;

    protected function setUp(): void
    {
        parent::setUp();

        // Reset capability stubs to prevent leaking between tests
        global $current_user_caps;
        $current_user_caps = null;

        $this->controller = new AdminAPIController(
            $this->createMock(AttendanceRepository::class),
            $this->createMock(EditionRepository::class),
            $this->createMock(SessionRepository::class),
        );
    }

    protected function tearDown(): void
    {
        global $current_user_caps;
        $current_user_caps = null;

        parent::tearDown();
    }

    // =========================================================================
    // canViewAdmin
    // =========================================================================

    /**
     * @test
     */
    public function canViewAdminReturnsTrueWithStrideView(): void
    {
        global $current_user_caps;
        $current_user_caps = ['stride_view' => true];

        $this->assertTrue($this->controller->canViewAdmin());
    }

    /**
     * @test
     */
    public function canViewAdminReturnsFalseWithoutStrideView(): void
    {
        global $current_user_caps;
        $current_user_caps = ['read' => true];

        $this->assertFalse($this->controller->canViewAdmin());
    }

    // =========================================================================
    // canManageAdmin
    // =========================================================================

    /**
     * @test
     */
    public function canManageAdminReturnsTrueWithStrideManage(): void
    {
        global $current_user_caps;
        $current_user_caps = ['stride_manage' => true, 'stride_view' => true];

        $this->assertTrue($this->controller->canManageAdmin());
    }

    /**
     * @test
     */
    public function canManageAdminReturnsFalseWithOnlyStrideView(): void
    {
        global $current_user_caps;
        $current_user_caps = ['stride_view' => true];

        $this->assertFalse($this->controller->canManageAdmin());
    }

    /**
     * @test
     */
    public function canManageAdminReturnsFalseWithNoCaps(): void
    {
        global $current_user_caps;
        $current_user_caps = [];

        $this->assertFalse($this->controller->canManageAdmin());
    }
}
```

- [ ] **Step 2: Run unit tests**

```bash
ddev exec vendor/bin/phpunit --filter AdminAPIController --testsuite Unit
```

Expected: All 5 tests pass.

- [ ] **Step 3: Commit**

```bash
git add tests/Unit/AdminAPIControllerTest.php
git commit -m "test(roles): add unit tests for canViewAdmin and canManageAdmin permission methods"
```

---

### Task 7: Integration Tests for Role-Based Access

**Files:**
- Create: `tests/Integration/AdminRolesIntegrationTest.php`

- [ ] **Step 1: Write integration tests**

```php
<?php

declare(strict_types=1);

namespace Stride\Tests\Integration;

use IntegrationTestCase;
use WP_REST_Request;

/**
 * Integration tests for Stride role-based admin access.
 *
 * Tests that coordinator, supervisor, and subscriber roles have correct
 * access levels to the admin REST API endpoints.
 *
 * Run: ddev exec vendor/bin/phpunit -c phpunit-integration.xml.dist --filter AdminRoles
 */
class AdminRolesIntegrationTest extends IntegrationTestCase
{
    private static ?int $coordinatorId = null;
    private static ?int $supervisorId = null;
    private static ?int $subscriberId = null;
    private static ?int $adminId = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        $ts = time();

        // Create admin
        self::$adminId = wp_create_user("role_admin_{$ts}", 'testpass123', "role_admin_{$ts}@test.local");
        $admin = get_user_by('ID', self::$adminId);
        $admin->set_role('administrator');

        // Create coordinator
        self::$coordinatorId = wp_create_user("role_coord_{$ts}", 'testpass123', "role_coord_{$ts}@test.local");
        $coord = get_user_by('ID', self::$coordinatorId);
        $coord->set_role('stride_coordinator');

        // Create supervisor
        self::$supervisorId = wp_create_user("role_super_{$ts}", 'testpass123', "role_super_{$ts}@test.local");
        $super = get_user_by('ID', self::$supervisorId);
        $super->set_role('stride_supervisor');

        // Create subscriber
        self::$subscriberId = wp_create_user("role_sub_{$ts}", 'testpass123', "role_sub_{$ts}@test.local");
        $sub = get_user_by('ID', self::$subscriberId);
        $sub->set_role('subscriber');
    }

    public static function tearDownAfterClass(): void
    {
        require_once ABSPATH . 'wp-admin/includes/user.php';
        foreach ([self::$adminId, self::$coordinatorId, self::$supervisorId, self::$subscriberId] as $id) {
            if ($id) {
                wp_delete_user($id);
            }
        }
        parent::tearDownAfterClass();
    }

    // =========================================================================
    // ADMINISTRATOR — Full access
    // =========================================================================

    /** @test */
    public function adminCanAccessGetEndpoints(): void
    {
        $this->actingAs(self::$adminId);

        $request = new WP_REST_Request('GET', '/stride/v1/admin/stats');
        $response = rest_do_request($request);

        $this->assertEquals(200, $response->get_status());
    }

    /** @test */
    public function adminCanAccessPostEndpoints(): void
    {
        $this->actingAs(self::$adminId);

        $request = new WP_REST_Request('POST', '/stride/v1/admin/attendance');
        $request->set_param('session_id', 1);
        $request->set_param('user_id', 1);

        $response = rest_do_request($request);

        // May return error for invalid session, but NOT 403
        $this->assertNotEquals(403, $response->get_status());
    }

    // =========================================================================
    // COORDINATOR — Full Stride access
    // =========================================================================

    /** @test */
    public function coordinatorCanAccessGetEndpoints(): void
    {
        $this->actingAs(self::$coordinatorId);

        $request = new WP_REST_Request('GET', '/stride/v1/admin/stats');
        $response = rest_do_request($request);

        $this->assertEquals(200, $response->get_status());
    }

    /** @test */
    public function coordinatorCanAccessEditions(): void
    {
        $this->actingAs(self::$coordinatorId);

        $request = new WP_REST_Request('GET', '/stride/v1/admin/editions');
        $response = rest_do_request($request);

        $this->assertEquals(200, $response->get_status());
    }

    /** @test */
    public function coordinatorCanAccessPostEndpoints(): void
    {
        $this->actingAs(self::$coordinatorId);

        $request = new WP_REST_Request('POST', '/stride/v1/admin/attendance');
        $request->set_param('session_id', 1);
        $request->set_param('user_id', 1);

        $response = rest_do_request($request);

        // May return error for invalid session, but NOT 403
        $this->assertNotEquals(403, $response->get_status());
    }

    /** @test */
    public function coordinatorCannotAccessSettings(): void
    {
        $this->actingAs(self::$coordinatorId);
        $this->assertFalse(current_user_can('manage_options'));
    }

    // =========================================================================
    // SUPERVISOR — Read-only access
    // =========================================================================

    /** @test */
    public function supervisorCanAccessGetEndpoints(): void
    {
        $this->actingAs(self::$supervisorId);

        $request = new WP_REST_Request('GET', '/stride/v1/admin/stats');
        $response = rest_do_request($request);

        $this->assertEquals(200, $response->get_status());
    }

    /** @test */
    public function supervisorCanAccessEditions(): void
    {
        $this->actingAs(self::$supervisorId);

        $request = new WP_REST_Request('GET', '/stride/v1/admin/editions');
        $response = rest_do_request($request);

        $this->assertEquals(200, $response->get_status());
    }

    /** @test */
    public function supervisorCanAccessQuotes(): void
    {
        $this->actingAs(self::$supervisorId);

        $request = new WP_REST_Request('GET', '/stride/v1/admin/quotes');
        $response = rest_do_request($request);

        $this->assertEquals(200, $response->get_status());
    }

    /** @test */
    public function supervisorCannotMarkAttendance(): void
    {
        $this->actingAs(self::$supervisorId);

        $request = new WP_REST_Request('POST', '/stride/v1/admin/attendance');
        $request->set_param('session_id', 1);
        $request->set_param('user_id', 1);

        $response = rest_do_request($request);

        $this->assertEquals(403, $response->get_status());
    }

    /** @test */
    public function supervisorCannotApproveRegistration(): void
    {
        $this->actingAs(self::$supervisorId);

        $request = new WP_REST_Request('POST', '/stride/v1/admin/approve-registration');
        $request->set_param('registration_id', 1);

        $response = rest_do_request($request);

        $this->assertEquals(403, $response->get_status());
    }

    /** @test */
    public function supervisorCannotApprovePostCourse(): void
    {
        $this->actingAs(self::$supervisorId);

        $request = new WP_REST_Request('POST', '/stride/v1/admin/approve-post-course');
        $request->set_param('registration_id', 1);

        $response = rest_do_request($request);

        $this->assertEquals(403, $response->get_status());
    }

    // =========================================================================
    // SUBSCRIBER — No admin access
    // =========================================================================

    /** @test */
    public function subscriberCannotAccessGetEndpoints(): void
    {
        $this->actingAs(self::$subscriberId);

        $request = new WP_REST_Request('GET', '/stride/v1/admin/stats');
        $response = rest_do_request($request);

        $this->assertEquals(403, $response->get_status());
    }

    /** @test */
    public function subscriberCannotAccessPostEndpoints(): void
    {
        $this->actingAs(self::$subscriberId);

        $request = new WP_REST_Request('POST', '/stride/v1/admin/attendance');
        $request->set_param('session_id', 1);
        $request->set_param('user_id', 1);

        $response = rest_do_request($request);

        $this->assertEquals(403, $response->get_status());
    }

    // =========================================================================
    // ROLE CAPABILITIES VERIFICATION
    // =========================================================================

    /** @test */
    public function administratorHasStrideCaps(): void
    {
        $role = get_role('administrator');
        $this->assertNotNull($role, 'Administrator role should exist');
        $this->assertTrue($role->has_cap('stride_manage'));
        $this->assertTrue($role->has_cap('stride_view'));
    }

    /** @test */
    public function coordinatorHasExpectedCaps(): void
    {
        $role = get_role('stride_coordinator');
        $this->assertNotNull($role, 'stride_coordinator role should exist');
        $this->assertTrue($role->has_cap('stride_manage'));
        $this->assertTrue($role->has_cap('stride_view'));
        $this->assertTrue($role->has_cap('edit_posts'));
        $this->assertTrue($role->has_cap('edit_others_posts'));
        $this->assertTrue($role->has_cap('publish_posts'));
        $this->assertTrue($role->has_cap('delete_posts'));
        $this->assertTrue($role->has_cap('upload_files'));
        $this->assertTrue($role->has_cap('read'));
    }

    /** @test */
    public function supervisorHasOnlyReadCaps(): void
    {
        $role = get_role('stride_supervisor');
        $this->assertNotNull($role, 'stride_supervisor role should exist');
        $this->assertTrue($role->has_cap('stride_view'));
        $this->assertTrue($role->has_cap('read'));
        $this->assertFalse($role->has_cap('stride_manage'));
        $this->assertFalse($role->has_cap('edit_posts'));
        $this->assertFalse($role->has_cap('edit_others_posts'));
    }
}
```

- [ ] **Step 2: Run integration tests**

```bash
ddev exec vendor/bin/phpunit -c phpunit-integration.xml.dist --filter AdminRoles
```

Expected: All 17 tests pass.

- [ ] **Step 3: Commit**

```bash
git add tests/Integration/AdminRolesIntegrationTest.php
git commit -m "test(roles): add integration tests for role-based admin access"
```

---

### Task 8: Full Regression and Smoke Test

- [ ] **Step 1: Run all unit tests**

```bash
ddev exec vendor/bin/phpunit --testsuite Unit
```

Expected: All tests pass. The existing `AdminIntegrationTest::adminApiRejectsNonAdminUser` test should still pass because subscriber lacks `stride_view`.

- [ ] **Step 2: Run all integration tests**

```bash
ddev exec vendor/bin/phpunit -c phpunit-integration.xml.dist
```

Expected: All tests pass including existing admin tests and new role tests.

- [ ] **Step 3: Manual smoke test — administrator**

1. Log in as `seed_admin@seed.test` / `seedpass123`
2. Navigate to Stride dashboard
3. Verify: all quick action links visible, all action buttons visible, Settings/Formuliervelden submenus visible
4. Verify: can mark attendance, approve registration

- [ ] **Step 4: Manual smoke test — coordinator**

1. Log in as `seed_coordinator@seed.test` / `seedpass123`
2. Navigate to Stride dashboard
3. Verify: all quick action links visible, all action buttons visible
4. Verify: Settings/Formuliervelden submenus NOT visible
5. Verify: can mark attendance, approve registration

- [ ] **Step 5: Manual smoke test — supervisor**

1. Log in as `seed_supervisor@seed.test` / `seedpass123`
2. Navigate to Stride dashboard
3. Verify: NO quick action links (Nieuwe Edition, Nieuw Traject, Gebruikers)
4. Verify: NO action buttons (approve, attendance toggle, edit links)
5. Verify: CAN see edition list, stats, quotes, trajectories (read-only)
6. Verify: Settings/Formuliervelden submenus NOT visible

- [ ] **Step 6: Final commit with all passing tests**

If any fixes were needed during smoke testing, commit them:

```bash
git add -A
git commit -m "fix(roles): address smoke test findings"
```

---

## Verification Stages (MANDATORY)

> Run AFTER all implementation tasks. NOT done until all stages pass.
> If ANY stage fails: fix → re-run that stage → continue.

### Stage V1: Static Analysis

```bash
ddev exec vendor/bin/phpcs --standard=PSR12 web/app/mu-plugins/stride-core/stride-core.php web/app/mu-plugins/stride-core/Admin/AdminAPIController.php web/app/mu-plugins/stride-core/Admin/AdminDashboardService.php web/app/mu-plugins/stride-core/Admin/AdminGuidePage.php
```

Expected: No errors. Fix all issues before proceeding.

### Stage V2: Unit Tests

**Test files:**
- `tests/Unit/AdminAPIControllerTest.php`

```bash
ddev exec vendor/bin/phpunit --testsuite Unit
```

Expected: ALL tests pass.

### Stage V3: Integration Tests

**Test files:**
- `tests/Integration/AdminRolesIntegrationTest.php`

```bash
ddev exec vendor/bin/phpunit -c phpunit-integration.xml.dist --filter AdminRoles
```

Expected: ALL tests pass.

### Stage V4: Full Regression

```bash
ddev exec vendor/bin/phpunit --testsuite Unit && ddev exec vendor/bin/phpunit -c phpunit-integration.xml.dist
```

Expected: Zero failures across all suites.

### Stage V5: Smoke Test Checklist

```markdown
## Manual Smoke Test

- [ ] Login as `seed_admin@seed.test` (seedpass123)
      Expected: Full dashboard, all buttons, Settings visible
- [ ] Login as `seed_coordinator@seed.test` (seedpass123)
      Expected: Full dashboard, all buttons, NO Settings submenu
- [ ] Login as `seed_supervisor@seed.test` (seedpass123)
      Expected: Read-only dashboard, NO action buttons, NO quick links, NO Settings
- [ ] Login as `student1@seed.test` (seedpass123)
      Expected: No Stride menu in WP admin at all
- [ ] Database: `ddev exec wp role list --format=table`
      Expected: stride_coordinator and stride_supervisor in list
- [ ] Database: `ddev exec wp cap list stride_coordinator`
      Expected: stride_manage, stride_view, edit_posts, edit_others_posts, publish_posts, delete_posts, upload_files, read
- [ ] Database: `ddev exec wp cap list stride_supervisor`
      Expected: stride_view, read
```
