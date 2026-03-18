# LearnDash Feature Gaps Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Surface hidden LearnDash features (extended access/expiration, prerequisites, course materials, drip-feed dates, course points) in the Stridence theme so online courses work fully.

**Architecture:** Add new static methods to the existing `LearnDashHelper` class for each feature. Then integrate these into the existing course detail templates (sidebar, content, tabs). No new services or data models needed — this is purely presentation layer work using existing LD functions.

**Tech Stack:** PHP 8.3, LearnDash API functions, Tailwind CSS, Alpine.js (for expand/collapse), Stridence theme templates.

---

## Phase 1: LearnDashHelper Methods

### Task 1: Add access expiration methods to LearnDashHelper

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Integrations/LearnDash/LearnDashHelper.php`
- Test: `tests/Unit/LearnDashHelperTest.php`

**Step 1: Write the failing tests**

Create test file with tests for the new methods:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Stride\Integrations\LearnDash\LearnDashHelper;

class LearnDashHelperTest extends TestCase
{
    protected function setUp(): void
    {
        // Ensure LD functions are available via stubs
        if (!defined('LEARNDASH_VERSION')) {
            define('LEARNDASH_VERSION', '4.0.0');
        }
    }

    public function testGetAccessExpirationReturnsNullWhenNoExpiration(): void
    {
        // Mock learndash_get_setting to return empty expire_access
        $this->defineFunctionOnce('learndash_get_setting', fn($id, $key) => match($key) {
            'expire_access' => '',
            default => '',
        });

        $result = LearnDashHelper::getAccessExpiration(123, 1);
        $this->assertNull($result);
    }

    public function testGetAccessExpirationReturnsTimestampWhenEnabled(): void
    {
        $accessFrom = time() - (30 * DAY_IN_SECONDS); // 30 days ago
        $expireDays = 90;

        $this->defineFunctionOnce('learndash_get_setting', fn($id, $key) => match($key) {
            'expire_access' => 'on',
            'expire_access_days' => $expireDays,
            default => '',
        });
        $this->defineFunctionOnce('ld_course_access_from', fn($cid, $uid) => $accessFrom);
        $this->defineFunctionOnce('ld_course_access_expires_on', fn($cid, $uid) => $accessFrom + ($expireDays * DAY_IN_SECONDS));

        $result = LearnDashHelper::getAccessExpiration(123, 1);
        $this->assertIsInt($result);
        $this->assertGreaterThan(time(), $result);
    }

    public function testHasExpirationReturnsFalseWhenNotEnabled(): void
    {
        $this->defineFunctionOnce('learndash_get_setting', fn($id, $key) => '');

        $this->assertFalse(LearnDashHelper::hasExpiration(123));
    }

    public function testHasExpirationReturnsTrueWhenEnabled(): void
    {
        $this->defineFunctionOnce('learndash_get_setting', fn($id, $key) => match($key) {
            'expire_access' => 'on',
            default => '',
        });

        $this->assertTrue(LearnDashHelper::hasExpiration(123));
    }

    private function defineFunctionOnce(string $name, \Closure $fn): void
    {
        if (!function_exists($name)) {
            eval("function {$name}(...\$args) { return call_user_func({$name}::class . '::call', ...\$args); }");
        }
        // For unit testing, we rely on the stubs in tests/Stubs/
    }
}
```

> **Note to implementer:** The test stubs setup above is simplified. Match the existing pattern in `tests/Unit/LearnDashAdapterTest.php` for how LD functions are mocked. The test file at `tests/Stubs/` already has some LD function stubs — extend those.

**Step 2: Run tests to verify they fail**

Run: `ddev exec vendor/bin/phpunit --filter LearnDashHelperTest --testsuite Unit`
Expected: FAIL — methods don't exist yet.

**Step 3: Implement the methods**

Add these methods to `LearnDashHelper.php` (before the closing `}`):

```php
/**
 * Check if course has access expiration enabled.
 */
public static function hasExpiration(int $courseId): bool
{
    if (!self::isActive()) {
        return false;
    }

    return learndash_get_setting($courseId, 'expire_access') === 'on';
}

/**
 * Get access expiration timestamp for a user's course.
 *
 * @return int|null Expiration timestamp, or null if no expiration
 */
public static function getAccessExpiration(int $courseId, ?int $userId = null): ?int
{
    if (!self::isActive() || !self::hasExpiration($courseId)) {
        return null;
    }

    $userId = $userId ?? get_current_user_id();
    if (!$userId) {
        return null;
    }

    if (!function_exists('ld_course_access_expires_on')) {
        return null;
    }

    $expires = ld_course_access_expires_on($courseId, $userId);
    return $expires > 0 ? $expires : null;
}

/**
 * Get remaining access days for a user's course.
 *
 * @return int|null Days remaining, or null if no expiration
 */
public static function getAccessDaysRemaining(int $courseId, ?int $userId = null): ?int
{
    $expires = self::getAccessExpiration($courseId, $userId);
    if ($expires === null) {
        return null;
    }

    $remaining = $expires - time();
    return max(0, (int) ceil($remaining / DAY_IN_SECONDS));
}
```

**Step 4: Run tests to verify they pass**

Run: `ddev exec vendor/bin/phpunit --filter LearnDashHelperTest --testsuite Unit`
Expected: PASS

**Step 5: Commit**

```bash
git add web/app/mu-plugins/stride-core/Integrations/LearnDash/LearnDashHelper.php tests/Unit/LearnDashHelperTest.php
git commit -m "feat(learndash): add access expiration helper methods"
```

---

### Task 2: Add prerequisite methods to LearnDashHelper

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Integrations/LearnDash/LearnDashHelper.php`
- Modify: `tests/Unit/LearnDashHelperTest.php`

**Step 1: Write the failing tests**

Add to `LearnDashHelperTest.php`:

```php
public function testGetPrerequisitesReturnsEmptyArrayWhenNone(): void
{
    $result = LearnDashHelper::getPrerequisites(123);
    $this->assertIsArray($result);
    $this->assertEmpty($result);
}

public function testHasPrerequisitesReturnsFalseWhenNone(): void
{
    $this->assertFalse(LearnDashHelper::hasPrerequisites(123));
}

public function testArePrerequisitesMetReturnsTrueWhenNoPrereqs(): void
{
    $this->assertTrue(LearnDashHelper::arePrerequisitesMet(123, 1));
}
```

**Step 2: Run tests to verify they fail**

Run: `ddev exec vendor/bin/phpunit --filter LearnDashHelperTest --testsuite Unit`
Expected: FAIL — methods don't exist.

**Step 3: Implement the methods**

Add to `LearnDashHelper.php`:

```php
/**
 * Check if course has prerequisites configured.
 */
public static function hasPrerequisites(int $courseId): bool
{
    if (!self::isActive() || !function_exists('learndash_get_course_prerequisite_enabled')) {
        return false;
    }

    return (bool) learndash_get_course_prerequisite_enabled($courseId);
}

/**
 * Get prerequisite courses with their completion status for a user.
 *
 * @return array<int, array{id: int, title: string, url: string, completed: bool}>
 */
public static function getPrerequisites(int $courseId, ?int $userId = null): array
{
    if (!self::isActive() || !self::hasPrerequisites($courseId)) {
        return [];
    }

    if (!function_exists('learndash_get_course_prerequisite')) {
        return [];
    }

    $userId = $userId ?? get_current_user_id();
    $prerequisiteIds = learndash_get_course_prerequisite($courseId);

    if (empty($prerequisiteIds)) {
        return [];
    }

    $result = [];
    foreach ($prerequisiteIds as $preReqId) {
        $preReqId = (int) $preReqId;
        if (!$preReqId) {
            continue;
        }

        $completed = false;
        if ($userId && function_exists('learndash_course_completed')) {
            $completed = learndash_course_completed($userId, $preReqId);
        }

        $result[] = [
            'id' => $preReqId,
            'title' => get_the_title($preReqId),
            'url' => get_permalink($preReqId),
            'completed' => $completed,
        ];
    }

    return $result;
}

/**
 * Check if all prerequisites are met for a user.
 */
public static function arePrerequisitesMet(int $courseId, ?int $userId = null): bool
{
    if (!self::hasPrerequisites($courseId)) {
        return true;
    }

    $userId = $userId ?? get_current_user_id();
    if (!$userId) {
        return false;
    }

    if (function_exists('learndash_is_course_prerequities_completed')) {
        return learndash_is_course_prerequities_completed($courseId, $userId);
    }

    return true;
}
```

**Step 4: Run tests to verify they pass**

Run: `ddev exec vendor/bin/phpunit --filter LearnDashHelperTest --testsuite Unit`
Expected: PASS

**Step 5: Commit**

```bash
git add web/app/mu-plugins/stride-core/Integrations/LearnDash/LearnDashHelper.php tests/Unit/LearnDashHelperTest.php
git commit -m "feat(learndash): add prerequisite helper methods"
```

---

### Task 3: Add drip-feed / lesson availability methods to LearnDashHelper

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Integrations/LearnDash/LearnDashHelper.php`
- Modify: `tests/Unit/LearnDashHelperTest.php`

**Step 1: Write the failing tests**

Add to `LearnDashHelperTest.php`:

```php
public function testHasDripFeedReturnsFalseWhenNotConfigured(): void
{
    $this->assertFalse(LearnDashHelper::hasDripFeed(123));
}

public function testGetLessonsWithAvailabilityReturnsArray(): void
{
    $result = LearnDashHelper::getLessonsWithAvailability(123, 1);
    $this->assertIsArray($result);
}
```

**Step 2: Run tests to verify they fail**

Run: `ddev exec vendor/bin/phpunit --filter LearnDashHelperTest --testsuite Unit`
Expected: FAIL

**Step 3: Implement the methods**

Add to `LearnDashHelper.php`:

```php
/**
 * Check if any lesson in the course uses drip-feed scheduling.
 */
public static function hasDripFeed(int $courseId): bool
{
    if (!self::isActive()) {
        return false;
    }

    $lessons = learndash_get_course_lessons_list($courseId);
    foreach ($lessons as $lesson) {
        $lessonPost = $lesson['post'] ?? $lesson;
        $lessonId = $lessonPost->ID ?? 0;
        if (!$lessonId) {
            continue;
        }

        $visibleAfter = learndash_get_setting($lessonId, 'visible_after');
        $visibleAfterDate = learndash_get_setting($lessonId, 'visible_after_specific_date');

        if (!empty($visibleAfter) || !empty($visibleAfterDate)) {
            return true;
        }
    }

    return false;
}

/**
 * Get lessons with availability dates (for drip-feed display).
 *
 * Extends getLessons() with an 'available_from' timestamp for each lesson.
 *
 * @return array<int, array{id: int, title: string, url: string, completed: bool, available_from: int|null, is_available: bool}>
 */
public static function getLessonsWithAvailability(int $courseId, ?int $userId = null): array
{
    if (!self::isActive()) {
        return [];
    }

    $userId = $userId ?? get_current_user_id();
    $lessons = learndash_get_course_lessons_list($courseId);
    $result = [];

    foreach ($lessons as $lesson) {
        $lessonPost = $lesson['post'] ?? $lesson;
        $lessonId = is_object($lessonPost) ? $lessonPost->ID : (int) $lessonPost;
        if (!$lessonId) {
            continue;
        }

        $availableFrom = null;
        $isAvailable = true;

        if ($userId && function_exists('ld_lesson_access_from')) {
            $accessFrom = ld_lesson_access_from($lessonId, $userId, $courseId);
            if ($accessFrom && $accessFrom > time()) {
                $availableFrom = (int) $accessFrom;
                $isAvailable = false;
            }
        }

        $completed = false;
        if ($userId && function_exists('learndash_is_lesson_complete')) {
            $completed = learndash_is_lesson_complete($userId, $lessonId, $courseId);
        }

        $result[] = [
            'id' => $lessonId,
            'title' => is_object($lessonPost) ? $lessonPost->post_title : get_the_title($lessonId),
            'url' => get_permalink($lessonId),
            'completed' => $completed,
            'available_from' => $availableFrom,
            'is_available' => $isAvailable,
        ];
    }

    return $result;
}
```

**Step 4: Run tests to verify they pass**

Run: `ddev exec vendor/bin/phpunit --filter LearnDashHelperTest --testsuite Unit`
Expected: PASS

**Step 5: Commit**

```bash
git add web/app/mu-plugins/stride-core/Integrations/LearnDash/LearnDashHelper.php tests/Unit/LearnDashHelperTest.php
git commit -m "feat(learndash): add drip-feed lesson availability methods"
```

---

### Task 4: Add course points method to LearnDashHelper

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Integrations/LearnDash/LearnDashHelper.php`
- Modify: `tests/Unit/LearnDashHelperTest.php`

**Step 1: Write the failing test**

```php
public function testGetCoursePointsReturnsZeroWhenNotConfigured(): void
{
    $this->assertEquals(0, LearnDashHelper::getCoursePoints(123));
}
```

**Step 2: Run test to verify it fails**

Run: `ddev exec vendor/bin/phpunit --filter testGetCoursePointsReturnsZeroWhenNotConfigured --testsuite Unit`
Expected: FAIL

**Step 3: Implement**

Add to `LearnDashHelper.php`:

```php
/**
 * Get course points value.
 *
 * @return int Points awarded for completing this course (0 = none)
 */
public static function getCoursePoints(int $courseId): int
{
    if (!self::isActive()) {
        return 0;
    }

    $points = learndash_get_setting($courseId, 'course_points');
    return (int) ($points ?: 0);
}
```

**Step 4: Run test to verify it passes**

Run: `ddev exec vendor/bin/phpunit --filter LearnDashHelperTest --testsuite Unit`
Expected: PASS

**Step 5: Commit**

```bash
git add web/app/mu-plugins/stride-core/Integrations/LearnDash/LearnDashHelper.php tests/Unit/LearnDashHelperTest.php
git commit -m "feat(learndash): add course points helper method"
```

---

## Phase 1 Integration Gate

Run: `ddev exec vendor/bin/phpunit --testsuite Unit`
Expected: All green, no regressions. All new methods exist on `LearnDashHelper`.

---

## Phase 2: Template Integration

### Task 5: Show access expiration in sidebar-online

Display expiration date and "days remaining" warning in the enrolled sidebar state.

**Files:**
- Modify: `web/app/themes/stridence/templates/course/sidebar-online.php`

**Step 1: Read the current file**

Read `web/app/themes/stridence/templates/course/sidebar-online.php` (already read above).

**Step 2: Add expiration display to enrolled states**

In `sidebar-online.php`, after the progress bar section (line ~79), inside the `elseif ($has_access)` block, add the expiration notice. Also add it to the completed block.

Find the `<!-- Enrolled, in progress -->` section (line 62-90) and add after the progress bar `</div>` (after line 79):

```php
            <?php
            // Access expiration warning
            $days_remaining = LearnDashHelper::getAccessDaysRemaining($course_id, $user_id);
            if ($days_remaining !== null) :
                $expiration_ts = LearnDashHelper::getAccessExpiration($course_id, $user_id);
                $is_urgent = $days_remaining <= 14;
            ?>
                <div class="flex items-start gap-2 p-3 rounded-lg text-sm <?php echo $is_urgent ? 'bg-warning/10 text-warning-dark' : 'bg-surface-alt text-text-muted'; ?>">
                    <?php echo stridence_icon($is_urgent ? 'alert-circle' : 'clock', 'w-4 h-4 mt-0.5 shrink-0'); ?>
                    <div>
                        <span class="font-medium">
                            <?php echo esc_html(sprintf(
                                _n('Nog %d dag toegang', 'Nog %d dagen toegang', $days_remaining, 'stridence'),
                                $days_remaining
                            )); ?>
                        </span>
                        <?php if ($expiration_ts) : ?>
                            <span class="block text-xs mt-0.5">
                                <?php echo esc_html(sprintf(
                                    __('Vervalt op %s', 'stridence'),
                                    stride_format_date(date('Y-m-d', $expiration_ts))
                                )); ?>
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
```

In the completed state block (`if ($is_complete)` around line 37-60), add after `<p class="text-sm text-text-muted">` paragraph (after line 47):

```php
            <?php
            $days_remaining = LearnDashHelper::getAccessDaysRemaining($course_id, $user_id);
            if ($days_remaining !== null) :
                $expiration_ts = LearnDashHelper::getAccessExpiration($course_id, $user_id);
            ?>
                <p class="text-xs text-text-muted">
                    <?php echo esc_html(sprintf(
                        __('Toegang tot %s', 'stridence'),
                        stride_format_date(date('Y-m-d', $expiration_ts))
                    )); ?>
                </p>
            <?php endif; ?>
```

**Step 3: Commit**

```bash
git add web/app/themes/stridence/templates/course/sidebar-online.php
git commit -m "feat(theme): show access expiration in online course sidebar"
```

---

### Task 6: Show prerequisites on course detail page

Display a notice when a course has unmet prerequisites, with links to the required courses.

**Files:**
- Modify: `web/app/themes/stridence/templates/course/content.php`

**Step 1: Read the current file**

Read `web/app/themes/stridence/templates/course/content.php` (already read above).

**Step 2: Add prerequisites notice before the Overzicht section**

At the top of `content.php`, after the variables but before `<!-- Overzicht Section -->` (line 19), add:

```php
<?php
// Prerequisites check (online courses only)
$user_id = get_current_user_id();
if ($is_online && LearnDashHelper::hasPrerequisites($course_id)) :
    $prerequisites = LearnDashHelper::getPrerequisites($course_id, $user_id ?: null);
    $all_met = !$user_id ? false : LearnDashHelper::arePrerequisitesMet($course_id, $user_id);

    if (!empty($prerequisites) && !$all_met) :
?>
<div class="mb-8 p-4 rounded-lg border border-amber-200 bg-amber-50">
    <div class="flex items-start gap-3">
        <?php echo stridence_icon('alert-circle', 'w-5 h-5 text-amber-600 mt-0.5 shrink-0'); ?>
        <div>
            <h3 class="font-semibold text-amber-800 mb-1">
                <?php esc_html_e('Vereiste voorkennis', 'stridence'); ?>
            </h3>
            <p class="text-sm text-amber-700 mb-3">
                <?php esc_html_e('Rond eerst de volgende cursus(sen) af om toegang te krijgen:', 'stridence'); ?>
            </p>
            <ul class="space-y-2">
                <?php foreach ($prerequisites as $prereq) : ?>
                    <li class="flex items-center gap-2 text-sm">
                        <?php if ($prereq['completed']) : ?>
                            <?php echo stridence_icon('check-circle', 'w-4 h-4 text-green-600'); ?>
                            <span class="text-green-700 line-through"><?php echo esc_html($prereq['title']); ?></span>
                        <?php else : ?>
                            <?php echo stridence_icon('circle', 'w-4 h-4 text-amber-400'); ?>
                            <a href="<?php echo esc_url($prereq['url']); ?>" class="text-amber-800 hover:underline font-medium">
                                <?php echo esc_html($prereq['title']); ?>
                            </a>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
</div>
<?php
    endif;
endif;
?>
```

Add the `use` statement at the top of the file, after `defined('ABSPATH') || exit;`:

```php
use Stride\Integrations\LearnDash\LearnDashHelper;
```

**Step 3: Commit**

```bash
git add web/app/themes/stridence/templates/course/content.php
git commit -m "feat(theme): show prerequisite courses notice on course detail"
```

---

### Task 7: Show course materials on course detail page

Display the LearnDash "Course Materials" field content in the Praktisch section.

**Files:**
- Modify: `web/app/themes/stridence/templates/course/content.php`

**Step 1: Read the current file**

The file was already read. The Praktisch section is at line 55-99.

**Step 2: Add materials section**

In the `<!-- Praktisch Section -->` (line 55), after the grid of info cards (`</div>` at line 98), add a materials block:

```php
    <?php
    $materials = LearnDashHelper::getCourseMaterials($course_id);
    if (!empty($materials)) :
    ?>
        <div class="mt-6">
            <h3 class="font-semibold text-text mb-3 flex items-center gap-2">
                <?php echo stridence_icon('file-text', 'w-5 h-5 text-primary'); ?>
                <?php esc_html_e('Cursusmateriaal', 'stridence'); ?>
            </h3>
            <div class="prose-stride text-sm max-w-none">
                <?php echo wp_kses_post($materials); ?>
            </div>
        </div>
    <?php endif; ?>
```

**Step 3: Commit**

```bash
git add web/app/themes/stridence/templates/course/content.php
git commit -m "feat(theme): show course materials in praktisch section"
```

---

### Task 8: Show drip-feed dates in the Programma section

When a course uses drip-feed content, show "available from" dates next to locked lessons.

**Files:**
- Modify: `web/app/themes/stridence/templates/course/content.php`

**Step 1: Read the current file**

Already read. The Programma section is at line 27-37. Currently it uses `[course_content]` shortcode.

**Step 2: Add drip-feed date display**

The `[course_content]` shortcode already renders locked lessons via LD's own rendering. However, it does NOT show the "available from" date. We need to add a supplementary section when drip-feed is active.

Replace the Programma section content to conditionally show availability dates:

Find (lines 27-37):
```php
<!-- Programma Section -->
<section id="programma" class="scroll-mt-32">
    <h2 class="font-heading text-2xl font-bold text-text mb-6">
        <?php esc_html_e('Programma', 'stridence'); ?>
    </h2>
    <div class="learndash-course-content">
        <?php
        // Use LearnDash course_content shortcode for lesson listing
        echo do_shortcode('[course_content course_id="' . esc_attr($course_id) . '"]');
        ?>
    </div>
</section>
```

Replace with:
```php
<!-- Programma Section -->
<section id="programma" class="scroll-mt-32">
    <h2 class="font-heading text-2xl font-bold text-text mb-6">
        <?php esc_html_e('Programma', 'stridence'); ?>
    </h2>

    <?php
    // Show drip-feed schedule notice if applicable
    $current_user_id = get_current_user_id();
    $has_drip = $is_online && $current_user_id && LearnDashHelper::hasAccess($course_id, $current_user_id) && LearnDashHelper::hasDripFeed($course_id);

    if ($has_drip) :
        $lessons_with_dates = LearnDashHelper::getLessonsWithAvailability($course_id, $current_user_id);
        $locked_lessons = array_filter($lessons_with_dates, fn($l) => !$l['is_available']);

        if (!empty($locked_lessons)) :
    ?>
        <div class="mb-4 p-3 rounded-lg bg-blue-50 border border-blue-200 text-sm text-blue-800 flex items-start gap-2">
            <?php echo stridence_icon('info', 'w-4 h-4 mt-0.5 shrink-0 text-blue-600'); ?>
            <span>
                <?php esc_html_e('Sommige lessen worden op een later moment beschikbaar. Bekijk de planning hieronder.', 'stridence'); ?>
            </span>
        </div>

        <div class="mb-6 space-y-2">
            <?php foreach ($lessons_with_dates as $lesson) : ?>
                <div class="flex items-center gap-3 p-3 rounded-lg <?php echo $lesson['is_available'] ? 'bg-surface' : 'bg-surface-alt'; ?>">
                    <?php if ($lesson['completed']) : ?>
                        <?php echo stridence_icon('check-circle', 'w-5 h-5 text-green-600 shrink-0'); ?>
                    <?php elseif (!$lesson['is_available']) : ?>
                        <?php echo stridence_icon('clock', 'w-5 h-5 text-text-muted shrink-0'); ?>
                    <?php else : ?>
                        <?php echo stridence_icon('circle', 'w-5 h-5 text-primary shrink-0'); ?>
                    <?php endif; ?>

                    <div class="flex-1 min-w-0">
                        <?php if ($lesson['is_available']) : ?>
                            <a href="<?php echo esc_url($lesson['url']); ?>" class="text-sm font-medium text-text hover:text-primary truncate block">
                                <?php echo esc_html($lesson['title']); ?>
                            </a>
                        <?php else : ?>
                            <span class="text-sm font-medium text-text-muted truncate block">
                                <?php echo esc_html($lesson['title']); ?>
                            </span>
                        <?php endif; ?>
                    </div>

                    <?php if ($lesson['available_from']) : ?>
                        <span class="text-xs text-text-muted whitespace-nowrap">
                            <?php echo esc_html(sprintf(
                                __('Beschikbaar %s', 'stridence'),
                                stride_format_date(date('Y-m-d', $lesson['available_from']))
                            )); ?>
                        </span>
                    <?php elseif ($lesson['completed']) : ?>
                        <span class="text-xs text-green-600 whitespace-nowrap">
                            <?php esc_html_e('Afgerond', 'stridence'); ?>
                        </span>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php
        endif;
    endif;
    ?>

    <div class="learndash-course-content">
        <?php
        // Use LearnDash course_content shortcode for lesson listing
        echo do_shortcode('[course_content course_id="' . esc_attr($course_id) . '"]');
        ?>
    </div>
</section>
```

> **Design decision:** We show BOTH the drip-feed schedule AND the native `[course_content]` shortcode. The schedule shows dates; the shortcode handles actual navigation/locking. For non-enrolled users and non-drip courses, only the shortcode renders.

**Step 3: Commit**

```bash
git add web/app/themes/stridence/templates/course/content.php
git commit -m "feat(theme): show drip-feed lesson dates in programma section"
```

---

### Task 9: Show course points in sidebar-online

Display course points value if configured, in the "not enrolled" sidebar state.

**Files:**
- Modify: `web/app/themes/stridence/templates/course/sidebar-online.php`

**Step 1: Read the current file**

Already read.

**Step 2: Add points to the benefits list**

In `sidebar-online.php`, in the "not enrolled" section, add a points benefit to the `<ul>` list (after the "Certificaat na afronding" `<li>`, around line 123):

```php
                <?php
                $course_points = LearnDashHelper::getCoursePoints($course_id);
                if ($course_points > 0) :
                ?>
                <li class="flex items-center gap-2">
                    <?php echo stridence_icon('check', 'w-4 h-4 text-green-600'); ?>
                    <?php echo esc_html(sprintf(
                        _n('%d punt na afronding', '%d punten na afronding', $course_points, 'stridence'),
                        $course_points
                    )); ?>
                </li>
                <?php endif; ?>
```

**Step 3: Commit**

```bash
git add web/app/themes/stridence/templates/course/sidebar-online.php
git commit -m "feat(theme): show course points in online sidebar benefits"
```

---

### Task 10: Show expiration info in "not enrolled" sidebar state

For pay-now and subscription courses with expiration enabled, show the access duration before enrollment so users know what they're buying.

**Files:**
- Modify: `web/app/themes/stridence/templates/course/sidebar-online.php`

**Step 1: Read the current file**

Already read.

**Step 2: Add access duration info**

In `sidebar-online.php`, in the "not enrolled" section, after the price display (around line 108) and before the benefits list, add:

```php
            <?php
            if (LearnDashHelper::hasExpiration($course_id)) :
                $expire_days = (int) learndash_get_setting($course_id, 'expire_access_days');
                if ($expire_days > 0) :
            ?>
                <p class="text-sm text-text-muted">
                    <?php echo esc_html(sprintf(
                        __('%d dagen toegang na inschrijving', 'stridence'),
                        $expire_days
                    )); ?>
                </p>
            <?php
                endif;
            endif;
            ?>
```

**Step 3: Commit**

```bash
git add web/app/themes/stridence/templates/course/sidebar-online.php
git commit -m "feat(theme): show access duration for courses with expiration"
```

---

## Phase 2 Integration Gate

**Verify all templates render without errors:**

Run: `ddev exec vendor/bin/phpunit --testsuite Unit`
Expected: All green.

**Smoke Test:**

- [ ] Visit: `https://stride.ddev.site/opleidingen/` (any online course)
      Expected: Course detail page renders without PHP errors
- [ ] Check: Sidebar shows price, benefits, course points if configured
- [ ] Check: If course has expiration, "X dagen toegang" shows below price
- [ ] If enrolled: sidebar shows progress + expiration date warning
- [ ] If completed: sidebar shows "Toegang tot [date]" if expiring
- [ ] Check: Prerequisites banner shows above Overzicht if configured
- [ ] Check: Course materials show in Praktisch section if populated
- [ ] Check: Drip-feed schedule shows above Programma if lessons are scheduled
- [ ] Admin: LearnDash > Courses > Edit any course
      Set prerequisites, materials, expiration, points — verify they render
- [ ] Console: DevTools > Console
      Expected: No red errors

---

## Summary of Changes

| Gap | Where | What |
|-----|-------|------|
| **Extended Access / Expiration** | `sidebar-online.php` | "Nog X dagen toegang" warning when enrolled; "X dagen toegang" info before enrollment |
| **Prerequisites** | `content.php` | Yellow banner above Overzicht with required course links and completion status |
| **Course Materials** | `content.php` | Materials section inside Praktisch, rendered with `wp_kses_post()` |
| **Drip-Feed Dates** | `content.php` | Lesson schedule with "Beschikbaar [date]" shown above the native `[course_content]` shortcode |
| **Course Points** | `sidebar-online.php` | "X punten na afronding" in benefits list |

**Files created:**
- `tests/Unit/LearnDashHelperTest.php` (new)

**Files modified:**
- `web/app/mu-plugins/stride-core/Integrations/LearnDash/LearnDashHelper.php` (7 new methods)
- `web/app/themes/stridence/templates/course/sidebar-online.php` (expiration + points)
- `web/app/themes/stridence/templates/course/content.php` (prerequisites + materials + drip-feed)
