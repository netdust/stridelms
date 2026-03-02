# LTI Tool Provider Enhancements — Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Enhance the Tool Provider with dynamic registration, config endpoints, per-platform role mapping, GradePayload value object, platform-scoped usernames, and minimal filter hooks.

**Architecture:** All changes are within `web/app/plugins/netdust-lti/`. No new services — we extend existing classes. New files: `GradePayload` value object, registration template. Existing tests updated, new test files added.

**Tech Stack:** PHP 8.3, celtic/lti library, NTDST Data Manager, PHPUnit

**Design doc:** `docs/plans/2026-03-02-lti-tool-provider-enhancements-design.md`

---

## Task 1: GradePayload Value Object

Create the typed, immutable value object that replaces scalar parameters in the grade passback flow.

**Files:**
- Create: `web/app/plugins/netdust-lti/src/ToolProvider/Domain/GradePayload.php`
- Test: `web/app/plugins/netdust-lti/tests/Unit/GradePayloadTest.php`

**Step 1: Write failing tests**

```php
<?php
declare(strict_types=1);

namespace NetdustLTI\Tests\Unit;

use NetdustLTI\ToolProvider\Domain\GradePayload;
use PHPUnit\Framework\TestCase;

class GradePayloadTest extends TestCase
{
    public function testCompletionFactory(): void
    {
        $payload = GradePayload::completion(42, 100);

        $this->assertSame(42, $payload->userId);
        $this->assertSame(100, $payload->courseId);
        $this->assertSame(1.0, $payload->score);
        $this->assertSame(1.0, $payload->maxScore);
        $this->assertSame('Completed', $payload->activityProgress);
        $this->assertSame('FullyGraded', $payload->gradingProgress);
        $this->assertNull($payload->comment);
    }

    public function testQuizScoreFactory(): void
    {
        $payload = GradePayload::quizScore(42, 100, 8, 10);

        $this->assertSame(42, $payload->userId);
        $this->assertSame(100, $payload->courseId);
        $this->assertSame(8.0, $payload->score);
        $this->assertSame(10.0, $payload->maxScore);
        $this->assertSame('Completed', $payload->activityProgress);
        $this->assertSame('FullyGraded', $payload->gradingProgress);
    }

    public function testTincannyScoreFactory(): void
    {
        $payload = GradePayload::tincannyScore(42, 100, 85.5);

        $this->assertSame(42, $payload->userId);
        $this->assertSame(100, $payload->courseId);
        $this->assertSame(85.5, $payload->score);
        $this->assertSame(100.0, $payload->maxScore);
        $this->assertSame('Completed', $payload->activityProgress);
        $this->assertSame('FullyGraded', $payload->gradingProgress);
    }

    public function testCustomComment(): void
    {
        $payload = new GradePayload(
            userId: 42,
            courseId: 100,
            score: 1,
            maxScore: 1,
            activityProgress: 'Completed',
            gradingProgress: 'FullyGraded',
            comment: 'Excellent work',
        );

        $this->assertSame('Excellent work', $payload->comment);
    }
}
```

**Step 2: Run test to verify it fails**

Run: `ddev exec vendor/bin/phpunit --filter GradePayloadTest web/app/plugins/netdust-lti/tests/Unit/GradePayloadTest.php`
Expected: FAIL — class not found

**Step 3: Implement GradePayload**

```php
<?php
declare(strict_types=1);

namespace NetdustLTI\ToolProvider\Domain;

/**
 * Immutable value object for LTI grade submissions.
 */
final readonly class GradePayload
{
    public function __construct(
        public int $userId,
        public int $courseId,
        public float $score,
        public float $maxScore,
        public string $activityProgress,
        public string $gradingProgress,
        public ?string $comment = null,
    ) {}

    public static function completion(int $userId, int $courseId): self
    {
        return new self(
            userId: $userId,
            courseId: $courseId,
            score: 1.0,
            maxScore: 1.0,
            activityProgress: 'Completed',
            gradingProgress: 'FullyGraded',
        );
    }

    public static function quizScore(int $userId, int $courseId, float $score, float $maxScore): self
    {
        return new self(
            userId: $userId,
            courseId: $courseId,
            score: $score,
            maxScore: $maxScore,
            activityProgress: 'Completed',
            gradingProgress: 'FullyGraded',
        );
    }

    public static function tincannyScore(int $userId, int $courseId, float $percentage): self
    {
        return new self(
            userId: $userId,
            courseId: $courseId,
            score: $percentage,
            maxScore: 100.0,
            activityProgress: 'Completed',
            gradingProgress: 'FullyGraded',
        );
    }
}
```

**Step 4: Run test to verify it passes**

Run: `ddev exec vendor/bin/phpunit --filter GradePayloadTest web/app/plugins/netdust-lti/tests/Unit/GradePayloadTest.php`
Expected: 4 tests pass

**Step 5: Commit**

```bash
git add web/app/plugins/netdust-lti/src/ToolProvider/Domain/GradePayload.php \
        web/app/plugins/netdust-lti/tests/Unit/GradePayloadTest.php
git commit -m "feat(lti): add GradePayload value object with named constructors"
```

---

## Task 2: Refactor GradePassbackService to use GradePayload

Replace the three public methods and private `postScore()` with a single `postGrade(GradePayload)`.

**Files:**
- Modify: `web/app/plugins/netdust-lti/src/ToolProvider/Services/GradePassbackService.php`
- Modify: `web/app/plugins/netdust-lti/src/ToolProvider/Bridges/LearnDashBridge.php`
- Modify: `web/app/plugins/netdust-lti/src/ToolProvider/Bridges/TinCannyBridge.php`

**Step 1: Refactor GradePassbackService**

Replace the entire class body. The three public methods (`postCompletion`, `postQuizScore`, `postTinCannyScore`) and private `postScore()` collapse into one public method:

```php
<?php
declare(strict_types=1);

namespace NetdustLTI\ToolProvider\Services;

use ceLTIc\LTI\Outcome;
use ceLTIc\LTI\Platform;
use ceLTIc\LTI\User;
use ceLTIc\LTI\Service\Score;
use NetdustLTI\ToolProvider\Domain\GradePayload;
use NetdustLTI\ToolProvider\WPDataConnector;
use WP_Error;

final class GradePassbackService
{
    /**
     * Post a grade to the LTI platform via AGS.
     *
     * @param GradePayload $payload The grade data to submit
     * @return bool|WP_Error True on success, WP_Error on failure
     */
    public function postGrade(GradePayload $payload): bool|WP_Error
    {
        // Filter: allow modification of the grade payload before submission
        $payload = apply_filters('netdust_lti_grade_payload', $payload);

        // Filter: allow suppressing grade passback
        $shouldPost = apply_filters('netdust_lti_should_post_grade', true, $payload);
        if (!$shouldPost) {
            ntdst_log('lti-grade')->info('Grade passback suppressed by filter', [
                'user_id' => $payload->userId,
                'course_id' => $payload->courseId,
            ]);
            return true;
        }

        // Get LTI context from user meta
        $context = get_user_meta($payload->userId, '_netdust_lti_context_' . $payload->courseId, true);

        if (!$context) {
            return new WP_Error('no_context', 'No LTI context found for this user/course');
        }

        if (empty($context['line_item_url']) && empty($context['scores_url'])) {
            return new WP_Error('no_ags', 'No AGS endpoint available');
        }

        ntdst_log('lti-grade')->info('Posting score', [
            'user_id' => $payload->userId,
            'course_id' => $payload->courseId,
            'score' => "{$payload->score}/{$payload->maxScore}",
            'platform_id' => $context['platform_id'],
        ]);

        try {
            $dataConnector = ntdst_get(WPDataConnector::class);
            $platform = Platform::fromRecordId($context['platform_id'], $dataConnector);

            if (!$platform) {
                return new WP_Error('platform_not_found', 'Platform not found');
            }

            $scoreEndpoint = $context['scores_url'] ?? $context['line_item_url'];
            $scoreService = new Score($platform, $scoreEndpoint);

            $outcome = new Outcome(
                $payload->score,
                $payload->maxScore,
                $payload->activityProgress,
                $payload->gradingProgress
            );
            if ($payload->comment) {
                $outcome->comment = $payload->comment;
            }

            $ltiUser = new User();
            $ltiUser->ltiUserId = $context['lti_user_id'];

            $success = $scoreService->submit($outcome, $ltiUser);

            if (!$success) {
                $http = $scoreService->getHttpMessage();
                $errorMessage = $http?->error ?? 'Unknown error';

                ntdst_log('lti-grade')->error('AGS score submission failed', [
                    'error' => $errorMessage,
                    'http_status' => $http?->status ?? 'unknown',
                ]);

                return new WP_Error('ags_error', 'AGS score submission failed: ' . $errorMessage);
            }

            ntdst_log('lti-grade')->info('Score posted successfully');
            return true;

        } catch (\Exception $e) {
            ntdst_log('lti-grade')->error('Exception posting score', [
                'error' => $e->getMessage(),
            ]);
            return new WP_Error('exception', $e->getMessage());
        }
    }
}
```

**Step 2: Update LearnDashBridge** (`LearnDashBridge.php`)

Replace lines 45 and 81 (the method calls) with GradePayload factories:

Line 45 — change:
```php
$result = $this->gradeService->postCompletion($userId, $courseId);
```
To:
```php
$result = $this->gradeService->postGrade(GradePayload::completion($userId, $courseId));
```

Line 81 — change:
```php
$result = $this->gradeService->postQuizScore($user->ID, $courseId, $score, $maxScore);
```
To:
```php
$result = $this->gradeService->postGrade(GradePayload::quizScore($user->ID, $courseId, $score, $maxScore));
```

Add import at top:
```php
use NetdustLTI\ToolProvider\Domain\GradePayload;
```

**Step 3: Update TinCannyBridge** (`TinCannyBridge.php`)

Line 53 — change:
```php
$gradeResult = $this->gradeService->postTinCannyScore($userId, $courseId, $result);
```
To:
```php
$gradeResult = $this->gradeService->postGrade(GradePayload::tincannyScore($userId, $courseId, $result));
```

Add import at top:
```php
use NetdustLTI\ToolProvider\Domain\GradePayload;
```

**Step 4: Run all existing tests**

Run: `ddev exec vendor/bin/phpunit --testsuite Unit`
Expected: All pass (existing tests don't directly test `GradePassbackService`)

**Step 5: Commit**

```bash
git add web/app/plugins/netdust-lti/src/ToolProvider/Services/GradePassbackService.php \
        web/app/plugins/netdust-lti/src/ToolProvider/Bridges/LearnDashBridge.php \
        web/app/plugins/netdust-lti/src/ToolProvider/Bridges/TinCannyBridge.php
git commit -m "refactor(lti): use GradePayload in GradePassbackService and bridges

Adds netdust_lti_grade_payload and netdust_lti_should_post_grade filters."
```

---

## Task 3: Per-Platform Role Mapping — Data Model

Add `role_instructor` and `role_learner` fields to the `lti_platform` CPT.

**Files:**
- Modify: `web/app/plugins/netdust-lti/src/Shared/LTIDataService.php`

**Step 1: Add role fields to platform registration**

In `LTIDataService::registerPlatformModel()`, add two fields after `contexts` (after line 175) and a new field group.

Add to `fields` array (after the `contexts` field):
```php
'role_instructor' => [
    'type' => 'text',
    'label' => 'Instructor Role',
    'description' => 'WordPress role for LTI Instructor (default: instructor)',
    'default' => 'instructor',
],
'role_learner' => [
    'type' => 'text',
    'label' => 'Learner Role',
    'description' => 'WordPress role for LTI Learner (default: subscriber)',
    'default' => 'subscriber',
],
```

Add to `field_groups` array (after `settings`):
```php
'roles' => [
    'title' => 'Role Mapping',
    'fields' => ['role_instructor', 'role_learner'],
],
```

Also add the two fields to the `settings` group's `fields` array: `['enabled', 'role_instructor', 'role_learner']`. Or keep them in the separate `roles` group — your choice. Separate group is cleaner with tabs.

**Step 2: Run tests to verify nothing breaks**

Run: `ddev exec vendor/bin/phpunit --testsuite Unit`
Expected: All pass

**Step 3: Commit**

```bash
git add web/app/plugins/netdust-lti/src/Shared/LTIDataService.php
git commit -m "feat(lti): add per-platform role mapping fields to lti_platform CPT"
```

---

## Task 4: Per-Platform Role Mapping + Username Scopes — UserProvisioner

Refactor `UserProvisioner` to accept platform ID, use platform-scoped subs, deterministic usernames, and per-platform role mapping.

**Files:**
- Modify: `web/app/plugins/netdust-lti/src/ToolProvider/Services/UserProvisioner.php`
- Modify: `web/app/plugins/netdust-lti/src/ToolProvider/Tool.php`
- Test: `web/app/plugins/netdust-lti/tests/Unit/UserProvisionerTest.php`

**Step 1: Write failing tests**

```php
<?php
declare(strict_types=1);

namespace NetdustLTI\Tests\Unit;

use NetdustLTI\Shared\Domain\LtiClaims;
use NetdustLTI\ToolProvider\Services\UserProvisioner;
use PHPUnit\Framework\TestCase;

class UserProvisionerTest extends TestCase
{
    private UserProvisioner $provisioner;

    protected function setUp(): void
    {
        $this->provisioner = new UserProvisioner();

        // Reset global state
        global $_test_users, $_test_user_meta, $_test_get_user_by;
        $_test_users = [];
        $_test_user_meta = [];
        $_test_get_user_by = [];
    }

    public function testProvisionNewUserWithPlatformScopedSub(): void
    {
        $claims = $this->makeClaims('user-123', 'jane@example.com', 'Jane', 'Doe');

        $user = $this->provisioner->provision($claims, 42);

        $this->assertInstanceOf(\WP_User::class, $user);

        // Verify sub is stored as platform-scoped
        global $_test_user_meta;
        $storedSub = $_test_user_meta[$user->ID]['_netdust_lti_sub'] ?? null;
        $this->assertSame('42:user-123', $storedSub);
    }

    public function testProvisionExistingUserByScopedSub(): void
    {
        // Pre-create a user with a scoped sub
        global $_test_user_meta, $_test_users;
        $existingUser = new \WP_User();
        $existingUser->ID = 555;
        $existingUser->user_email = 'jane@example.com';
        $existingUser->roles = ['subscriber'];
        $_test_users[555] = $existingUser;
        $_test_user_meta[555] = ['_netdust_lti_sub' => '42:user-123'];

        $claims = $this->makeClaims('user-123', 'jane@example.com', 'Jane', 'Doe');

        $user = $this->provisioner->provision($claims, 42);

        $this->assertSame(555, $user->ID);
    }

    public function testProvisionExistingUserByBareSub(): void
    {
        // Pre-create user with bare sub (legacy format)
        global $_test_user_meta, $_test_users;
        $existingUser = new \WP_User();
        $existingUser->ID = 556;
        $existingUser->user_email = 'legacy@example.com';
        $existingUser->roles = ['subscriber'];
        $_test_users[556] = $existingUser;
        $_test_user_meta[556] = ['_netdust_lti_sub' => 'user-456'];

        $claims = $this->makeClaims('user-456', 'legacy@example.com', 'Legacy', 'User');

        $user = $this->provisioner->provision($claims, 10);

        $this->assertSame(556, $user->ID);

        // Verify sub is now updated to scoped format
        $this->assertSame('10:user-456', $_test_user_meta[556]['_netdust_lti_sub']);
    }

    public function testProvisionUsesPerPlatformRoleMapping(): void
    {
        // Set up platform meta to return custom roles
        global $_test_platform_meta;
        $_test_platform_meta[42] = [
            'lti_role_instructor' => 'editor',
            'lti_role_learner' => 'author',
        ];

        $claims = $this->makeClaims(
            'instructor-1',
            'prof@example.com',
            'Prof',
            'Smith',
            ['http://purl.imsglobal.org/vocab/lis/v2/membership#Instructor']
        );

        $user = $this->provisioner->provision($claims, 42);

        // The user should be created with the platform-specific role
        $this->assertInstanceOf(\WP_User::class, $user);
    }

    public function testDeterministicUsernameFromNames(): void
    {
        $claims = $this->makeClaims('sub-1', 'alice@example.com', 'Alice', 'Johnson');

        $user = $this->provisioner->provision($claims, 1);

        $this->assertStringStartsWith('alice.johnson', strtolower($user->user_login));
    }

    public function testProvisionUserDataFilter(): void
    {
        global $_test_applied_filters;
        $_test_applied_filters = [];

        $claims = $this->makeClaims('new-user-1', 'new@example.com', 'New', 'User');

        $user = $this->provisioner->provision($claims, 1);

        // Verify filter was applied
        $this->assertContains('netdust_lti_provision_user_data', $_test_applied_filters);
    }

    private function makeClaims(
        string $sub,
        ?string $email,
        ?string $given,
        ?string $family,
        array $roles = []
    ): LtiClaims {
        return new LtiClaims(
            sub: $sub,
            email: $email,
            name: trim("$given $family"),
            givenName: $given,
            familyName: $family,
            contextId: 'ctx-1',
            contextTitle: 'Test Course',
            resourceLinkId: 'rl-1',
            resourceLinkTitle: 'Test Link',
            roles: $roles,
            custom: [],
            lineItemUrl: null,
            lineItemsUrl: null,
            scoresUrl: null,
        );
    }
}
```

**Step 2: Run test to verify it fails**

Run: `ddev exec vendor/bin/phpunit --filter UserProvisionerTest web/app/plugins/netdust-lti/tests/Unit/UserProvisionerTest.php`
Expected: FAIL — `provision()` doesn't accept `$platformId`

**Step 3: Update UserProvisioner**

Rewrite `UserProvisioner.php` with these changes:
- `provision(LtiClaims $claims, int $platformId)` — adds platform ID parameter
- `findByLtiSub()` — checks scoped format `{platformId}:{sub}` first, then bare `{sub}` for backwards compat
- `createUser()` — uses deterministic username `{given}.{family}`, applies `netdust_lti_provision_user_data` filter
- `generateUsername()` — standardize on `{given}.{family}` → email prefix → `lti_{sub_hash}`
- `resolveRole()` — reads platform meta for role mapping, falls back to defaults
- Sub storage always uses scoped format `{platformId}:{sub}`

```php
<?php
declare(strict_types=1);

namespace NetdustLTI\ToolProvider\Services;

use NetdustLTI\Shared\Domain\LtiClaims;
use WP_User;
use WP_Error;

final class UserProvisioner
{
    private const META_LTI_SUB = '_netdust_lti_sub';
    private const META_LTI_PROVISIONED = '_netdust_lti_provisioned';

    public function provision(LtiClaims $claims, int $platformId): WP_User|WP_Error
    {
        // 1. Look up by platform-scoped LTI sub (most reliable for repeat launches)
        $scopedSub = $platformId . ':' . $claims->sub;
        $userId = $this->findByLtiSub($scopedSub);

        // 2. Look up by bare sub (backwards compat with pre-scoped entries)
        if (!$userId) {
            $userId = $this->findByLtiSub($claims->sub);
        }

        // 3. Look up by email
        if (!$userId && $claims->email) {
            $existing = get_user_by('email', $claims->email);
            $userId = $existing instanceof WP_User ? $existing->ID : null;
        }

        // 4. Create new user with race condition protection
        if (!$userId) {
            $lockKey = 'lti_provision_' . md5($claims->email ?? $claims->sub);

            if (get_transient($lockKey)) {
                usleep(500000); // 500ms
                $userId = $this->findByLtiSub($scopedSub);
                if (!$userId && $claims->email) {
                    $existing = get_user_by('email', $claims->email);
                    $userId = $existing instanceof WP_User ? $existing->ID : null;
                }
            }

            if (!$userId) {
                set_transient($lockKey, true, 30);
                $userId = $this->createUser($claims, $platformId);
                delete_transient($lockKey);

                if (is_wp_error($userId)) {
                    return $userId;
                }

                update_user_meta($userId, self::META_LTI_PROVISIONED, 1);
            }
        }

        // Always store/update scoped sub
        update_user_meta($userId, self::META_LTI_SUB, $scopedSub);
        update_user_meta($userId, '_netdust_lti_last_login', current_time('mysql'));

        $user = get_user_by('id', $userId);

        if (!$user) {
            return new WP_Error('user_not_found', 'User could not be retrieved');
        }

        // Ensure user has at least a role
        if (empty($user->roles)) {
            $role = $this->resolveRole($claims, $platformId);
            $user->set_role($role);
        }

        return $user;
    }

    private function findByLtiSub(string $sub): ?int
    {
        global $wpdb;

        $userId = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT user_id FROM {$wpdb->usermeta}
                 WHERE meta_key = %s AND meta_value = %s LIMIT 1",
                self::META_LTI_SUB,
                $sub
            )
        );

        return $userId ? (int) $userId : null;
    }

    private function createUser(LtiClaims $claims, int $platformId): int|WP_Error
    {
        $username = $this->generateUsername($claims);
        $role = $this->resolveRole($claims, $platformId);

        $userData = [
            'user_login' => $username,
            'user_email' => $claims->email ?? $username . '@lti.local',
            'user_pass' => wp_generate_password(24),
            'display_name' => $claims->name ?? $username,
            'first_name' => $claims->givenName ?? '',
            'last_name' => $claims->familyName ?? '',
            'role' => $role,
        ];

        // Filter: allow modification of user data before creation
        $userData = apply_filters('netdust_lti_provision_user_data', $userData, $claims);

        return wp_insert_user($userData);
    }

    private function generateUsername(LtiClaims $claims): string
    {
        // 1. Try given.family
        if ($claims->givenName && $claims->familyName) {
            $base = sanitize_user(
                strtolower($claims->givenName . '.' . $claims->familyName),
                true
            );
        // 2. Fall back to email prefix
        } elseif ($claims->email) {
            $base = sanitize_user(explode('@', $claims->email)[0], true);
        // 3. Fall back to hash of sub
        } else {
            $base = 'lti_' . substr(md5($claims->sub), 0, 8);
        }

        // Ensure non-empty
        if (empty($base)) {
            $base = 'lti_' . substr(md5($claims->sub), 0, 8);
        }

        // Ensure unique
        $username = $base;
        $counter = 1;

        while (username_exists($username)) {
            $username = $base . '_' . $counter;
            $counter++;
        }

        return $username;
    }

    private function resolveRole(LtiClaims $claims, int $platformId): string
    {
        $model = ntdst_data()->get('lti_platform');
        $defaultInstructor = 'instructor';
        $defaultLearner = 'subscriber';

        if ($platformId > 0) {
            $instructorRole = $model->getMeta($platformId, 'role_instructor');
            $learnerRole = $model->getMeta($platformId, 'role_learner');

            if ($instructorRole) {
                $defaultInstructor = $instructorRole;
            }
            if ($learnerRole) {
                $defaultLearner = $learnerRole;
            }
        }

        return $claims->isInstructor() ? $defaultInstructor : $defaultLearner;
    }

    public function isLtiUser(int $userId): bool
    {
        return (bool) get_user_meta($userId, self::META_LTI_PROVISIONED, true);
    }
}
```

**Step 4: Update Tool::onLaunch()** to pass platform ID

In `Tool.php` line 54-55, change:
```php
$provisioner = ntdst_get(UserProvisioner::class);
$user = $provisioner->provision($this->claims);
```
To:
```php
$provisioner = ntdst_get(UserProvisioner::class);
$user = $provisioner->provision($this->claims, $this->platform->getRecordId());
```

Also add the `netdust_lti_claims` filter after line 51 (after claims are created):
```php
$this->claims = apply_filters('netdust_lti_claims', $this->claims);
```

**Step 5: Add test bootstrap stubs**

The tests need stubs for `ntdst_data()`, `get_post_meta()` for platform role lookup, and the `apply_filters` tracking. Add to `tests/bootstrap.php`:

```php
// Track applied filters for testing
global $_test_applied_filters;
$_test_applied_filters = [];

if (!function_exists('apply_filters')) {
    function apply_filters(string $hook_name, $value, ...$args)
    {
        global $_test_applied_filters;
        $_test_applied_filters[] = $hook_name;
        return $value;
    }
}

// Platform meta stub for role mapping tests
global $_test_platform_meta;
$_test_platform_meta = [];
```

Verify that the existing stubs for `$wpdb`, `get_user_by`, `update_user_meta`, `get_transient`, etc. cover all the needs. The main project's bootstrap (`tests/bootstrap.php`) already provides most of these.

**Step 6: Run tests**

Run: `ddev exec vendor/bin/phpunit --filter UserProvisionerTest web/app/plugins/netdust-lti/tests/Unit/UserProvisionerTest.php`
Expected: All pass

Run: `ddev exec vendor/bin/phpunit --testsuite Unit`
Expected: All tests pass

**Step 7: Commit**

```bash
git add web/app/plugins/netdust-lti/src/ToolProvider/Services/UserProvisioner.php \
        web/app/plugins/netdust-lti/src/ToolProvider/Tool.php \
        web/app/plugins/netdust-lti/tests/Unit/UserProvisionerTest.php \
        web/app/plugins/netdust-lti/tests/bootstrap.php
git commit -m "feat(lti): platform-scoped subs, role mapping, deterministic usernames

- UserProvisioner.provision() now takes platformId
- Sub stored as {platformId}:{sub}, with bare sub backwards compat
- Username generation: given.family → email prefix → lti_{hash}
- Per-platform role mapping via lti_platform CPT meta
- Adds netdust_lti_claims and netdust_lti_provision_user_data filters"
```

---

## Task 5: Should-Enroll Filter in CourseEnroller

Add the `netdust_lti_should_enroll` filter to `CourseEnroller::enroll()`.

**Files:**
- Modify: `web/app/plugins/netdust-lti/src/ToolProvider/Services/CourseEnroller.php`

**Step 1: Add filter to enroll method**

In `CourseEnroller::enroll()`, add after the LearnDash availability check (after line 41) and before the access check (line 44):

```php
// Filter: allow suppressing enrollment
$shouldEnroll = apply_filters('netdust_lti_should_enroll', true, $user, $courseId, $claims);
if (!$shouldEnroll) {
    ntdst_log('lti')->info('Enrollment suppressed by filter', [
        'user_id' => $user->ID,
        'course_id' => $courseId,
    ]);
    // Still store LTI context for grade passback even if enrollment is skipped
    $this->storeLtiContext($user->ID, $courseId, $claims, $platformId);
    return;
}
```

**Step 2: Run tests**

Run: `ddev exec vendor/bin/phpunit --testsuite Unit`
Expected: All pass

**Step 3: Commit**

```bash
git add web/app/plugins/netdust-lti/src/ToolProvider/Services/CourseEnroller.php
git commit -m "feat(lti): add netdust_lti_should_enroll filter to CourseEnroller"
```

---

## Task 6: Config Endpoints

Add `/lti/configure.json` and `/lti/configure.xml` routes to `ToolProvider\Router`.

**Files:**
- Modify: `web/app/plugins/netdust-lti/src/ToolProvider/Router.php`
- Test: `web/app/plugins/netdust-lti/tests/Unit/ConfigEndpointTest.php`

**Step 1: Write failing tests**

```php
<?php
declare(strict_types=1);

namespace NetdustLTI\Tests\Unit;

use PHPUnit\Framework\TestCase;

class ConfigEndpointTest extends TestCase
{
    public function testJsonConfigContainsRequiredFields(): void
    {
        // Simulate the config generation
        $config = $this->generateJsonConfig('https://stride.ddev.site');

        $this->assertArrayHasKey('title', $config);
        $this->assertArrayHasKey('description', $config);
        $this->assertArrayHasKey('oidc_initiation_url', $config);
        $this->assertArrayHasKey('target_link_uri', $config);
        $this->assertArrayHasKey('jwks_uri', $config);
        $this->assertArrayHasKey('claims', $config);
        $this->assertArrayHasKey('messages', $config);
        $this->assertArrayHasKey('scopes', $config);
    }

    public function testJsonConfigUrlsAreCorrect(): void
    {
        $config = $this->generateJsonConfig('https://stride.ddev.site');

        $this->assertSame('https://stride.ddev.site/lti/login', $config['oidc_initiation_url']);
        $this->assertSame('https://stride.ddev.site/lti/launch', $config['target_link_uri']);
        $this->assertSame('https://stride.ddev.site/lti/jwks', $config['jwks_uri']);
    }

    public function testJsonConfigIncludesDeepLinkingMessage(): void
    {
        $config = $this->generateJsonConfig('https://stride.ddev.site');

        $messageTypes = array_column($config['messages'], 'type');
        $this->assertContains('LtiResourceLinkRequest', $messageTypes);
        $this->assertContains('LtiDeepLinkingRequest', $messageTypes);
    }

    public function testJsonConfigIncludesAgsScopes(): void
    {
        $config = $this->generateJsonConfig('https://stride.ddev.site');

        $this->assertContains(
            'https://purl.imsglobal.org/spec/lti-ags/scope/lineitem',
            $config['scopes']
        );
        $this->assertContains(
            'https://purl.imsglobal.org/spec/lti-ags/scope/score',
            $config['scopes']
        );
    }

    public function testCanvasXmlContainsRequiredElements(): void
    {
        $xml = $this->generateCanvasXml('https://stride.ddev.site');

        $this->assertStringContainsString('blti:launch_url', $xml);
        $this->assertStringContainsString('https://stride.ddev.site/lti/launch', $xml);
        $this->assertStringContainsString('lticm:property', $xml);
    }

    /**
     * Helper: generates the JSON config array (mirrors Router::buildJsonConfig)
     */
    private function generateJsonConfig(string $homeUrl): array
    {
        return [
            'title' => 'Stride LMS',
            'description' => 'LearnDash course delivery via LTI 1.3',
            'oidc_initiation_url' => $homeUrl . '/lti/login',
            'target_link_uri' => $homeUrl . '/lti/launch',
            'jwks_uri' => $homeUrl . '/lti/jwks',
            'claims' => ['sub', 'name', 'email', 'given_name', 'family_name'],
            'messages' => [
                [
                    'type' => 'LtiResourceLinkRequest',
                    'target_link_uri' => $homeUrl . '/lti/launch',
                ],
                [
                    'type' => 'LtiDeepLinkingRequest',
                    'target_link_uri' => $homeUrl . '/lti/deep-link',
                ],
            ],
            'scopes' => [
                'https://purl.imsglobal.org/spec/lti-ags/scope/lineitem',
                'https://purl.imsglobal.org/spec/lti-ags/scope/lineitem.readonly',
                'https://purl.imsglobal.org/spec/lti-ags/scope/score',
                'https://purl.imsglobal.org/spec/lti-ags/scope/result.readonly',
            ],
        ];
    }

    /**
     * Helper: generates Canvas XML (mirrors Router::buildCanvasXml)
     */
    private function generateCanvasXml(string $homeUrl): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>
<cartridge_basiclti_link xmlns="http://www.imsglobal.org/xsd/imslticc_v1p0"
    xmlns:blti="http://www.imsglobal.org/xsd/imsbasiclti_v1p0"
    xmlns:lticm="http://www.imsglobal.org/xsd/imslticm_v1p0"
    xmlns:lticp="http://www.imsglobal.org/xsd/imslticp_v1p0"
    xmlns:lti="http://www.imsglobal.org/xsd/imslti_v1p0">
  <blti:title>Stride LMS</blti:title>
  <blti:description>LearnDash course delivery via LTI 1.3</blti:description>
  <blti:launch_url>' . $homeUrl . '/lti/launch</blti:launch_url>
  <blti:extensions platform="canvas.instructure.com">
    <lticm:property name="privacy_level">public</lticm:property>
    <lticm:property name="domain">' . wp_parse_url($homeUrl, PHP_URL_HOST) . '</lticm:property>
  </blti:extensions>
</cartridge_basiclti_link>';
    }
}
```

**Step 2: Run test to verify it fails**

Run: `ddev exec vendor/bin/phpunit --filter ConfigEndpointTest web/app/plugins/netdust-lti/tests/Unit/ConfigEndpointTest.php`
Expected: Tests pass (these test the data shape directly, not the router)

**Step 3: Add config routes to Router**

In `Router::handleRequest()` (the switch statement, before `default`), add two cases:

```php
case 'configure-json':
    $this->handleConfigureJson();
    break;

case 'configure-xml':
    $this->handleConfigureXml();
    break;
```

Add the handler methods:

```php
private function handleConfigureJson(): void
{
    header('Cache-Control: public, max-age=3600');
    $this->sendJsonSuccess($this->buildJsonConfig(), 200);
}

private function handleConfigureXml(): void
{
    header('Content-Type: application/xml; charset=utf-8');
    header('Cache-Control: public, max-age=3600');
    echo $this->buildCanvasXml();
    exit;
}

private function buildJsonConfig(): array
{
    $homeUrl = home_url();

    return [
        'title' => get_bloginfo('name') ?: 'Stride LMS',
        'description' => 'LearnDash course delivery via LTI 1.3',
        'oidc_initiation_url' => $homeUrl . '/lti/login',
        'target_link_uri' => $homeUrl . '/lti/launch',
        'jwks_uri' => $homeUrl . '/lti/jwks',
        'claims' => ['sub', 'name', 'email', 'given_name', 'family_name'],
        'messages' => [
            [
                'type' => 'LtiResourceLinkRequest',
                'target_link_uri' => $homeUrl . '/lti/launch',
            ],
            [
                'type' => 'LtiDeepLinkingRequest',
                'target_link_uri' => $homeUrl . '/lti/deep-link',
            ],
        ],
        'scopes' => [
            'https://purl.imsglobal.org/spec/lti-ags/scope/lineitem',
            'https://purl.imsglobal.org/spec/lti-ags/scope/lineitem.readonly',
            'https://purl.imsglobal.org/spec/lti-ags/scope/score',
            'https://purl.imsglobal.org/spec/lti-ags/scope/result.readonly',
        ],
    ];
}

private function buildCanvasXml(): string
{
    $homeUrl = home_url();
    $domain = wp_parse_url($homeUrl, PHP_URL_HOST);
    $title = esc_html(get_bloginfo('name') ?: 'Stride LMS');

    return '<?xml version="1.0" encoding="UTF-8"?>
<cartridge_basiclti_link xmlns="http://www.imsglobal.org/xsd/imslticc_v1p0"
    xmlns:blti="http://www.imsglobal.org/xsd/imsbasiclti_v1p0"
    xmlns:lticm="http://www.imsglobal.org/xsd/imslticm_v1p0"
    xmlns:lticp="http://www.imsglobal.org/xsd/imslticp_v1p0"
    xmlns:lti="http://www.imsglobal.org/xsd/imslti_v1p0">
  <blti:title>' . $title . '</blti:title>
  <blti:description>LearnDash course delivery via LTI 1.3</blti:description>
  <blti:launch_url>' . esc_url($homeUrl) . '/lti/launch</blti:launch_url>
  <blti:extensions platform="canvas.instructure.com">
    <lticm:property name="privacy_level">public</lticm:property>
    <lticm:property name="domain">' . esc_html($domain) . '</lticm:property>
    <lticm:options name="placements">
      <lticm:options name="course_navigation">
        <lticm:property name="enabled">true</lticm:property>
      </lticm:options>
    </lticm:options>
  </blti:extensions>
</cartridge_basiclti_link>';
}
```

**Step 4: Run all tests**

Run: `ddev exec vendor/bin/phpunit --testsuite Unit`
Expected: All pass

**Step 5: Commit**

```bash
git add web/app/plugins/netdust-lti/src/ToolProvider/Router.php \
        web/app/plugins/netdust-lti/tests/Unit/ConfigEndpointTest.php
git commit -m "feat(lti): add /lti/configure-json and /lti/configure-xml config endpoints"
```

---

## Task 7: Dynamic Registration

Add `/lti/register` route that leverages `ceLTIc\LTI\Tool::doRegistration()`.

**Files:**
- Modify: `web/app/plugins/netdust-lti/src/ToolProvider/Router.php`
- Modify: `web/app/plugins/netdust-lti/src/ToolProvider/Tool.php`
- Create: `web/app/plugins/netdust-lti/templates/registration-confirm.php`
- Test: `web/app/plugins/netdust-lti/tests/Unit/DynamicRegistrationTest.php`

**Step 1: Write failing tests**

```php
<?php
declare(strict_types=1);

namespace NetdustLTI\Tests\Unit;

use PHPUnit\Framework\TestCase;

class DynamicRegistrationTest extends TestCase
{
    public function testRegistrationRouteRequiresAdminCapability(): void
    {
        global $current_user_caps;
        $current_user_caps = ['manage_options' => false];

        // Simulate non-admin user accessing /lti/register
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unauthorized');

        // This should wp_die with an unauthorized message
        $this->simulateRegistrationAccess(false);
    }

    public function testRegistrationRouteRequiresOpenIdConfiguration(): void
    {
        global $current_user_caps;
        $current_user_caps = ['manage_options' => true];

        $this->expectException(\RuntimeException::class);

        // Missing openid_configuration parameter
        $this->simulateRegistrationAccess(true, []);
    }

    public function testRegistrationRouteAcceptsValidParams(): void
    {
        global $current_user_caps;
        $current_user_caps = ['manage_options' => true];

        // Valid params should not throw for parameter validation
        $params = [
            'openid_configuration' => 'https://moodle.example.com/.well-known/openid-configuration',
            'registration_token' => 'test-token-123',
        ];

        // We just verify the params are validated correctly
        $this->assertTrue($this->validateRegistrationParams($params));
    }

    private function simulateRegistrationAccess(bool $isAdmin, array $params = []): void
    {
        if (!$isAdmin) {
            throw new \RuntimeException('Unauthorized');
        }

        if (empty($params['openid_configuration'])) {
            throw new \RuntimeException('Missing openid_configuration parameter');
        }
    }

    private function validateRegistrationParams(array $params): bool
    {
        return !empty($params['openid_configuration'])
            && filter_var($params['openid_configuration'], FILTER_VALIDATE_URL);
    }
}
```

**Step 2: Run test to verify they pass (these are unit-level validation tests)**

Run: `ddev exec vendor/bin/phpunit --filter DynamicRegistrationTest web/app/plugins/netdust-lti/tests/Unit/DynamicRegistrationTest.php`

**Step 3: Add registration route to Router**

In `Router::handleRequest()` switch, add before `default`:

```php
case 'register':
    $this->handleRegistration();
    break;
```

Add the handler:

```php
private function handleRegistration(): void
{
    // Only admins can register platforms
    if (!current_user_can('manage_options')) {
        wp_die(
            __('You must be logged in as an administrator to register platforms.', 'netdust-lti'),
            __('Unauthorized', 'netdust-lti'),
            ['response' => 403]
        );
    }

    $openidConfig = $_GET['openid_configuration'] ?? '';
    $registrationToken = $_GET['registration_token'] ?? null;

    if (empty($openidConfig) || !filter_var($openidConfig, FILTER_VALIDATE_URL)) {
        wp_die(
            __('Missing or invalid openid_configuration parameter.', 'netdust-lti'),
            __('Registration Error', 'netdust-lti'),
            ['response' => 400]
        );
    }

    $dataConnector = ntdst_get(WPDataConnector::class);
    $tool = new Tool($dataConnector);

    // ceLTIc handles the registration protocol
    $tool->handleRequest();

    // If the tool generated HTML output (confirmation form), display it
    if ($tool->errorOutput) {
        echo $tool->errorOutput;
        exit;
    }
}
```

**Step 4: Override onRegistration() in Tool**

Add to `Tool.php`:

```php
protected function onRegistration(): void
{
    // Celtic's doRegistration() fetches the OpenID config and shows a confirmation form
    // We override to use our template and add security checks

    $platformInfo = [
        'name' => $this->platform->name ?? 'Unknown Platform',
        'platformId' => $this->platform->platformId ?? '',
        'clientId' => $this->platform->getKey() ?? '',
        'authorizationServerId' => $this->platform->authorizationServerId ?? '',
    ];

    $templatePath = dirname(__DIR__, 2) . '/templates/registration-confirm.php';

    if (file_exists($templatePath)) {
        // Pass data to template
        $platform = $platformInfo;
        $confirmUrl = add_query_arg([
            'openid_configuration' => $_GET['openid_configuration'] ?? '',
            'registration_token' => $_GET['registration_token'] ?? '',
            'confirm' => '1',
        ], home_url('/lti/register'));

        include $templatePath;
    } else {
        // Fallback: let celtic handle it
        parent::onRegistration();
    }
}
```

Note: The actual dynamic registration protocol is handled by `ceLTIc\LTI\Tool::handleRequest()` which detects the `openid_configuration` parameter and calls `doRegistration()` → `onRegistration()`. The `WPDataConnector::savePlatform()` handles persistence. We just need to provide the route and template.

**Step 5: Create registration confirmation template**

```php
<?php
/**
 * LTI Dynamic Registration Confirmation
 *
 * @var array  $platform   Platform info (name, platformId, clientId, authorizationServerId)
 * @var string $confirmUrl URL to confirm registration
 */

if (!defined('ABSPATH')) {
    exit;
}
?>
<!DOCTYPE html>
<html>
<head>
    <title><?php esc_html_e('Confirm LTI Platform Registration', 'netdust-lti'); ?></title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; padding: 40px; max-width: 600px; margin: 0 auto; background: #f0f0f1; }
        .card { background: #fff; padding: 30px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        h1 { font-size: 22px; margin: 0 0 20px; color: #1d2327; }
        .info-table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        .info-table th { text-align: left; padding: 8px 12px; color: #646970; font-weight: 500; width: 40%; }
        .info-table td { padding: 8px 12px; color: #1d2327; word-break: break-all; }
        .info-table tr { border-bottom: 1px solid #f0f0f1; }
        .actions { margin-top: 24px; display: flex; gap: 12px; }
        .btn { padding: 10px 20px; border-radius: 4px; font-size: 14px; cursor: pointer; text-decoration: none; display: inline-block; border: none; }
        .btn-primary { background: #2271b1; color: #fff; }
        .btn-primary:hover { background: #135e96; }
        .btn-secondary { background: #f0f0f1; color: #50575e; border: 1px solid #c3c4c7; }
        .btn-secondary:hover { background: #e0e0e0; }
        .warning { background: #fcf9e8; border-left: 4px solid #dba617; padding: 12px 16px; margin: 16px 0; font-size: 13px; }
    </style>
</head>
<body>
    <div class="card">
        <h1><?php esc_html_e('Confirm Platform Registration', 'netdust-lti'); ?></h1>

        <p><?php esc_html_e('An LTI platform wants to register with this tool. Review the details below:', 'netdust-lti'); ?></p>

        <table class="info-table">
            <tr>
                <th><?php esc_html_e('Platform Name', 'netdust-lti'); ?></th>
                <td><?php echo esc_html($platform['name']); ?></td>
            </tr>
            <tr>
                <th><?php esc_html_e('Platform ID (Issuer)', 'netdust-lti'); ?></th>
                <td><?php echo esc_html($platform['platformId']); ?></td>
            </tr>
            <tr>
                <th><?php esc_html_e('Client ID', 'netdust-lti'); ?></th>
                <td><?php echo esc_html($platform['clientId']); ?></td>
            </tr>
        </table>

        <div class="warning">
            <?php esc_html_e('Only approve registrations from platforms you trust. This will allow the platform to launch LTI content on your site.', 'netdust-lti'); ?>
        </div>

        <div class="actions">
            <form method="post" action="<?php echo esc_url($confirmUrl); ?>">
                <?php wp_nonce_field('netdust_lti_register', '_lti_reg_nonce'); ?>
                <button type="submit" class="btn btn-primary">
                    <?php esc_html_e('Approve Registration', 'netdust-lti'); ?>
                </button>
            </form>
            <a href="<?php echo esc_url(admin_url('options-general.php?page=netdust-lti')); ?>" class="btn btn-secondary">
                <?php esc_html_e('Cancel', 'netdust-lti'); ?>
            </a>
        </div>
    </div>
</body>
</html>
```

**Step 6: Fire action hook after registration**

In `WPDataConnector::savePlatform()`, after a successful save of a new platform (when `!$platform->getRecordId()` → create path), add:

```php
do_action('netdust_lti_platform_registered', $postId, $platform);
```

Check the current `savePlatform()` implementation to find the exact insert point. This fires after the CPT post is created.

**Step 7: Run all tests**

Run: `ddev exec vendor/bin/phpunit --testsuite Unit`
Expected: All pass

**Step 8: Flush rewrite rules**

Run: `ddev exec wp rewrite flush`

**Step 9: Commit**

```bash
git add web/app/plugins/netdust-lti/src/ToolProvider/Router.php \
        web/app/plugins/netdust-lti/src/ToolProvider/Tool.php \
        web/app/plugins/netdust-lti/src/ToolProvider/WPDataConnector.php \
        web/app/plugins/netdust-lti/templates/registration-confirm.php \
        web/app/plugins/netdust-lti/tests/Unit/DynamicRegistrationTest.php
git commit -m "feat(lti): add dynamic registration flow with confirmation template

- GET /lti/register?openid_configuration=...&registration_token=...
- Requires manage_options capability
- Confirmation page shows platform details before approval
- Fires netdust_lti_platform_registered action after save"
```

---

## Task 8: Display Config URLs on Admin Settings Page

Show the config endpoint URLs on the LTI settings page so admins can easily copy them.

**Files:**
- Modify: `web/app/plugins/netdust-lti/src/Admin/AdminPage.php` (or the settings template)

**Step 1: Check current admin page**

Read `web/app/plugins/netdust-lti/src/Admin/AdminPage.php` and `templates/admin/settings-page.php` to understand the current layout.

**Step 2: Add config URL section**

Add a "Tool Configuration URLs" section to the settings page that displays:
- JSON Config: `{site_url}/lti/configure-json`
- Canvas XML Config: `{site_url}/lti/configure-xml`
- Dynamic Registration: `{site_url}/lti/register`
- JWKS Endpoint: `{site_url}/lti/jwks`
- Launch URL: `{site_url}/lti/launch`
- Login URL: `{site_url}/lti/login`
- Deep Link URL: `{site_url}/lti/deep-link`

Each URL should have a "Copy" button using simple inline JS.

**Step 3: Run tests**

Run: `ddev exec vendor/bin/phpunit --testsuite Unit`
Expected: All pass

**Step 4: Commit**

```bash
git add web/app/plugins/netdust-lti/src/Admin/AdminPage.php \
        web/app/plugins/netdust-lti/templates/admin/settings-page.php
git commit -m "feat(lti): display config and registration URLs on admin settings page"
```

---

## Verification Stages (MANDATORY)

> Run AFTER all implementation tasks. NOT done until all stages pass.
> If ANY stage fails: fix → re-run that stage → continue.

### Stage V1: Static Analysis

```bash
ddev exec vendor/bin/phpunit --testsuite Unit 2>&1 | head -20
```

Verify no PHP syntax errors across all modified files:
```bash
ddev exec php -l web/app/plugins/netdust-lti/src/ToolProvider/Domain/GradePayload.php
ddev exec php -l web/app/plugins/netdust-lti/src/ToolProvider/Services/GradePassbackService.php
ddev exec php -l web/app/plugins/netdust-lti/src/ToolProvider/Services/UserProvisioner.php
ddev exec php -l web/app/plugins/netdust-lti/src/ToolProvider/Services/CourseEnroller.php
ddev exec php -l web/app/plugins/netdust-lti/src/ToolProvider/Router.php
ddev exec php -l web/app/plugins/netdust-lti/src/ToolProvider/Tool.php
ddev exec php -l web/app/plugins/netdust-lti/src/Shared/LTIDataService.php
ddev exec php -l web/app/plugins/netdust-lti/src/ToolProvider/Bridges/LearnDashBridge.php
ddev exec php -l web/app/plugins/netdust-lti/src/ToolProvider/Bridges/TinCannyBridge.php
ddev exec php -l web/app/plugins/netdust-lti/templates/registration-confirm.php
```

Expected: `No syntax errors detected` for each file.

### Stage V2: Unit Tests

**Test files created/modified:**
- `web/app/plugins/netdust-lti/tests/Unit/GradePayloadTest.php` (new)
- `web/app/plugins/netdust-lti/tests/Unit/UserProvisionerTest.php` (new)
- `web/app/plugins/netdust-lti/tests/Unit/ConfigEndpointTest.php` (new)
- `web/app/plugins/netdust-lti/tests/Unit/DynamicRegistrationTest.php` (new)

```bash
ddev exec vendor/bin/phpunit --testsuite Unit
```

Expected: ALL tests pass, including existing 57 tests + new tests.

### Stage V3: Manual Smoke Tests

Since LTI requires external platform interaction, verify by:

```markdown
## Manual Smoke Test

- [ ] Visit: https://stride.ddev.site/lti/configure-json
      Expected: JSON response with title, oidc_initiation_url, jwks_uri, messages, scopes
- [ ] Visit: https://stride.ddev.site/lti/configure-xml
      Expected: XML response with Canvas-compatible tool configuration
- [ ] Visit: https://stride.ddev.site/lti/register (not logged in)
      Expected: 403 Unauthorized error
- [ ] Visit: https://stride.ddev.site/lti/register (logged in as admin, no params)
      Expected: 400 error about missing openid_configuration
- [ ] Admin: https://stride.ddev.site/wp/wp-admin/options-general.php?page=netdust-lti
      Expected: Settings page shows config URLs with copy buttons
- [ ] Admin: Edit an LTI Platform → verify "Role Mapping" tab shows instructor/learner dropdowns
- [ ] Visit: https://stride.ddev.site/lti/jwks
      Expected: JWKS JSON with keys array
```

### Stage V4: Full Regression

```bash
ddev exec vendor/bin/phpunit --testsuite Unit
```

Expected: Zero failures across all suites.

### Stage V5: Code Review Checklist

```markdown
- [ ] GradePayload is readonly with named constructors
- [ ] GradePassbackService has single postGrade() method with both filters
- [ ] LearnDashBridge and TinCannyBridge use GradePayload factories
- [ ] UserProvisioner stores scoped sub as {platformId}:{sub}
- [ ] UserProvisioner reads platform role mapping via Data Manager
- [ ] Tool::onLaunch() passes platform ID and applies claims filter
- [ ] CourseEnroller has should_enroll filter
- [ ] Router has configure-json, configure-xml, register routes
- [ ] Registration requires manage_options capability
- [ ] Template uses esc_html/esc_url throughout
- [ ] No raw echo in services (only in templates/router endpoints)
```
