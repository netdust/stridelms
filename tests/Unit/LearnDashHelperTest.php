<?php

declare(strict_types=1);

namespace Stride\Tests\Unit;

use Stride\Integrations\LearnDash\LearnDashHelper;
use Stride\Tests\TestCase;

/**
 * Unit tests for LearnDashHelper
 *
 * Tests the 9 new helper methods: access expiration (3), prerequisites (3),
 * drip-feed (2), and course points (1). Each method guards with isActive()
 * and function_exists() checks.
 */
class LearnDashHelperTest extends TestCase
{
    // ──────────────────────────────────────────────────────────
    // Access Expiration - inactive LD guard tests
    // ──────────────────────────────────────────────────────────

    /** @test */
    public function testHasExpirationReturnsFalseWhenLDInactive(): void
    {
        $this->assertFalse(LearnDashHelper::hasExpiration(100));
    }

    /** @test */
    public function testGetAccessExpirationReturnsNullWhenLDInactive(): void
    {
        $this->assertNull(LearnDashHelper::getAccessExpiration(100, 1));
    }

    /** @test */
    public function testGetAccessDaysRemainingReturnsNullWhenLDInactive(): void
    {
        $this->assertNull(LearnDashHelper::getAccessDaysRemaining(100, 1));
    }

    // ──────────────────────────────────────────────────────────
    // Prerequisites - inactive LD guard tests
    // ──────────────────────────────────────────────────────────

    /** @test */
    public function testHasPrerequisitesReturnsFalseWhenLDInactive(): void
    {
        $this->assertFalse(LearnDashHelper::hasPrerequisites(100));
    }

    /** @test */
    public function testGetPrerequisitesReturnsEmptyWhenLDInactive(): void
    {
        $this->assertSame([], LearnDashHelper::getPrerequisites(100, 1));
    }

    /** @test */
    public function testArePrerequisitesMetReturnsTrueWhenLDInactive(): void
    {
        // No prerequisites function = treat as no prerequisites = met
        $this->assertTrue(LearnDashHelper::arePrerequisitesMet(100, 1));
    }

    // ──────────────────────────────────────────────────────────
    // Drip-Feed - inactive LD guard tests
    // ──────────────────────────────────────────────────────────

    /** @test */
    public function testHasDripFeedReturnsFalseWhenLDInactive(): void
    {
        $this->assertFalse(LearnDashHelper::hasDripFeed(100));
    }

    /** @test */
    public function testGetLessonsWithAvailabilityReturnsEmptyWhenLDInactive(): void
    {
        $this->assertSame([], LearnDashHelper::getLessonsWithAvailability(100, 1));
    }

    // ──────────────────────────────────────────────────────────
    // Course Points - inactive LD guard tests
    // ──────────────────────────────────────────────────────────

    /** @test */
    public function testGetCoursePointsReturnsZeroWhenLDInactive(): void
    {
        $this->assertSame(0, LearnDashHelper::getCoursePoints(100));
    }

    // ──────────────────────────────────────────────────────────
    // Access Expiration - active LD tests
    // ──────────────────────────────────────────────────────────

    /**
     * @test
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testHasExpirationReturnsTrueWhenEnabled(): void
    {
        $this->defineLDBase();
        $this->defineLDSetting(100, 'expire_access', 'on');

        $this->assertTrue(LearnDashHelper::hasExpiration(100));
    }

    /**
     * @test
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testHasExpirationReturnsFalseWhenDisabled(): void
    {
        $this->defineLDBase();
        $this->defineLDSetting(100, 'expire_access', '');

        $this->assertFalse(LearnDashHelper::hasExpiration(100));
    }

    /**
     * @test
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testGetAccessExpirationReturnsTimestamp(): void
    {
        $this->defineLDBase();
        $expiresAt = time() + 86400 * 30; // 30 days from now
        $this->defineLDSetting(100, 'expire_access', 'on');

        if (!function_exists('ld_course_access_expires_on')) {
            eval('function ld_course_access_expires_on(int $courseId, int $userId): int { return ' . $expiresAt . '; }');
        }

        $result = LearnDashHelper::getAccessExpiration(100, 42);
        $this->assertSame($expiresAt, $result);
    }

    /**
     * @test
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testGetAccessExpirationReturnsNullWhenNoExpiration(): void
    {
        $this->defineLDBase();
        $this->defineLDSetting(100, 'expire_access', '');

        $result = LearnDashHelper::getAccessExpiration(100, 42);
        $this->assertNull($result);
    }

    /**
     * @test
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testGetAccessExpirationReturnsNullWhenNoUser(): void
    {
        $this->defineLDBase();
        $this->defineLDSetting(100, 'expire_access', 'on');

        // userId=0 should return null
        $result = LearnDashHelper::getAccessExpiration(100, 0);
        $this->assertNull($result);
    }

    /**
     * @test
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testGetAccessExpirationReturnsNullWhenZeroTimestamp(): void
    {
        $this->defineLDBase();
        $this->defineLDSetting(100, 'expire_access', 'on');

        if (!function_exists('ld_course_access_expires_on')) {
            eval('function ld_course_access_expires_on(int $courseId, int $userId): int { return 0; }');
        }

        $result = LearnDashHelper::getAccessExpiration(100, 42);
        $this->assertNull($result);
    }

    /**
     * @test
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testGetAccessDaysRemainingReturnsCorrectDays(): void
    {
        $this->defineLDBase();
        $expiresAt = time() + 86400 * 10; // 10 days from now
        $this->defineLDSetting(100, 'expire_access', 'on');

        if (!function_exists('ld_course_access_expires_on')) {
            eval('function ld_course_access_expires_on(int $courseId, int $userId): int { return ' . $expiresAt . '; }');
        }

        $result = LearnDashHelper::getAccessDaysRemaining(100, 42);
        $this->assertSame(10, $result);
    }

    /**
     * @test
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testGetAccessDaysRemainingReturnsZeroWhenExpired(): void
    {
        $this->defineLDBase();
        $expiresAt = time() - 86400; // 1 day ago
        $this->defineLDSetting(100, 'expire_access', 'on');

        if (!function_exists('ld_course_access_expires_on')) {
            eval('function ld_course_access_expires_on(int $courseId, int $userId): int { return ' . $expiresAt . '; }');
        }

        $result = LearnDashHelper::getAccessDaysRemaining(100, 42);
        $this->assertSame(0, $result);
    }

    // ──────────────────────────────────────────────────────────
    // Prerequisites - active LD tests
    // ──────────────────────────────────────────────────────────

    /**
     * @test
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testHasPrerequisitesReturnsTrueWhenEnabled(): void
    {
        $this->defineLDBase();

        if (!function_exists('learndash_get_course_prerequisite_enabled')) {
            eval('function learndash_get_course_prerequisite_enabled(int $courseId) { return true; }');
        }

        $this->assertTrue(LearnDashHelper::hasPrerequisites(100));
    }

    /**
     * @test
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testHasPrerequisitesReturnsFalseWhenDisabled(): void
    {
        $this->defineLDBase();

        if (!function_exists('learndash_get_course_prerequisite_enabled')) {
            eval('function learndash_get_course_prerequisite_enabled(int $courseId) { return false; }');
        }

        $this->assertFalse(LearnDashHelper::hasPrerequisites(100));
    }

    /**
     * @test
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testGetPrerequisitesReturnsCoursesWithStatus(): void
    {
        $this->defineLDBase();
        $this->defineGetPermalink();
        $this->defineGetTheTitle();

        if (!function_exists('learndash_get_course_prerequisite_enabled')) {
            eval('function learndash_get_course_prerequisite_enabled(int $courseId) { return true; }');
        }
        if (!function_exists('learndash_get_course_prerequisite')) {
            eval('function learndash_get_course_prerequisite(int $courseId): array { return [50, 60]; }');
        }
        if (!function_exists('learndash_course_completed')) {
            eval('function learndash_course_completed(int $userId, int $courseId): bool { return $courseId === 50; }');
        }

        $result = LearnDashHelper::getPrerequisites(100, 42);

        $this->assertCount(2, $result);
        $this->assertSame(50, $result[0]['id']);
        $this->assertTrue($result[0]['completed']);
        $this->assertSame(60, $result[1]['id']);
        $this->assertFalse($result[1]['completed']);
    }

    /**
     * @test
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testGetPrerequisitesReturnsEmptyWhenNone(): void
    {
        $this->defineLDBase();

        if (!function_exists('learndash_get_course_prerequisite_enabled')) {
            eval('function learndash_get_course_prerequisite_enabled(int $courseId) { return true; }');
        }
        if (!function_exists('learndash_get_course_prerequisite')) {
            eval('function learndash_get_course_prerequisite(int $courseId): array { return []; }');
        }

        $result = LearnDashHelper::getPrerequisites(100, 42);
        $this->assertSame([], $result);
    }

    /**
     * @test
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testArePrerequisitesMetReturnsTrueWhenMet(): void
    {
        $this->defineLDBase();

        if (!function_exists('learndash_get_course_prerequisite_enabled')) {
            eval('function learndash_get_course_prerequisite_enabled(int $courseId) { return true; }');
        }
        if (!function_exists('learndash_is_course_prerequities_completed')) {
            eval('function learndash_is_course_prerequities_completed(int $courseId, int $userId): bool { return true; }');
        }

        $this->assertTrue(LearnDashHelper::arePrerequisitesMet(100, 42));
    }

    /**
     * @test
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testArePrerequisitesMetReturnsFalseWhenNotMet(): void
    {
        $this->defineLDBase();

        if (!function_exists('learndash_get_course_prerequisite_enabled')) {
            eval('function learndash_get_course_prerequisite_enabled(int $courseId) { return true; }');
        }
        if (!function_exists('learndash_is_course_prerequities_completed')) {
            eval('function learndash_is_course_prerequities_completed(int $courseId, int $userId): bool { return false; }');
        }

        $this->assertFalse(LearnDashHelper::arePrerequisitesMet(100, 42));
    }

    /**
     * @test
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testArePrerequisitesMetReturnsFalseWhenNoUser(): void
    {
        $this->defineLDBase();

        if (!function_exists('learndash_get_course_prerequisite_enabled')) {
            eval('function learndash_get_course_prerequisite_enabled(int $courseId) { return true; }');
        }

        $this->assertFalse(LearnDashHelper::arePrerequisitesMet(100, 0));
    }

    // ──────────────────────────────────────────────────────────
    // Drip-Feed - active LD tests
    // ──────────────────────────────────────────────────────────

    /**
     * @test
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testHasDripFeedReturnsTrueWhenLessonsHaveSchedule(): void
    {
        $this->defineLDBase();

        $lesson1 = new \WP_Post(['ID' => 201, 'post_title' => 'Lesson 1']);
        $lesson2 = new \WP_Post(['ID' => 202, 'post_title' => 'Lesson 2']);

        $this->defineLDLessons(100, [
            ['post' => $lesson1],
            ['post' => $lesson2],
        ]);

        // Lesson 202 has drip-feed
        $this->defineLDSettingMulti([
            '201:visible_after' => '',
            '201:visible_after_specific_date' => '',
            '202:visible_after' => '7',
            '202:visible_after_specific_date' => '',
        ]);

        $this->assertTrue(LearnDashHelper::hasDripFeed(100));
    }

    /**
     * @test
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testHasDripFeedReturnsFalseWhenNoSchedule(): void
    {
        $this->defineLDBase();

        $lesson1 = new \WP_Post(['ID' => 201, 'post_title' => 'Lesson 1']);

        $this->defineLDLessons(100, [
            ['post' => $lesson1],
        ]);

        $this->defineLDSettingMulti([
            '201:visible_after' => '',
            '201:visible_after_specific_date' => '',
        ]);

        $this->assertFalse(LearnDashHelper::hasDripFeed(100));
    }

    /**
     * @test
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testGetLessonsWithAvailabilityReturnsCorrectData(): void
    {
        $this->defineLDBase();
        $this->defineGetPermalink();

        $lesson1 = new \WP_Post(['ID' => 201, 'post_title' => 'Lesson 1']);
        $lesson2 = new \WP_Post(['ID' => 202, 'post_title' => 'Lesson 2']);

        $this->defineLDLessons(100, [
            ['post' => $lesson1],
            ['post' => $lesson2],
        ]);

        $futureTime = time() + 86400 * 5;

        // Lesson 201 is available, 202 is future
        if (!function_exists('ld_lesson_access_from')) {
            eval('function ld_lesson_access_from(int $lessonId, int $userId, int $courseId) {
                return $lessonId === 202 ? ' . $futureTime . ' : 0;
            }');
        }
        if (!function_exists('learndash_is_lesson_complete')) {
            eval('function learndash_is_lesson_complete(int $userId, int $lessonId, int $courseId): bool {
                return $lessonId === 201;
            }');
        }

        $result = LearnDashHelper::getLessonsWithAvailability(100, 42);

        $this->assertCount(2, $result);

        // Lesson 1: completed, available
        $this->assertSame(201, $result[0]['id']);
        $this->assertSame('Lesson 1', $result[0]['title']);
        $this->assertTrue($result[0]['completed']);
        $this->assertTrue($result[0]['is_available']);
        $this->assertNull($result[0]['available_from']);

        // Lesson 2: not completed, not available, has future date
        $this->assertSame(202, $result[1]['id']);
        $this->assertSame('Lesson 2', $result[1]['title']);
        $this->assertFalse($result[1]['completed']);
        $this->assertFalse($result[1]['is_available']);
        $this->assertSame($futureTime, $result[1]['available_from']);
    }

    // ──────────────────────────────────────────────────────────
    // Course Points - active LD tests
    // ──────────────────────────────────────────────────────────

    /**
     * @test
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testGetCoursePointsReturnsValue(): void
    {
        $this->defineLDBase();
        $this->defineLDSetting(100, 'course_points', '10');

        $this->assertSame(10, LearnDashHelper::getCoursePoints(100));
    }

    /**
     * @test
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testGetCoursePointsReturnsZeroWhenEmpty(): void
    {
        $this->defineLDBase();
        $this->defineLDSetting(100, 'course_points', '');

        $this->assertSame(0, LearnDashHelper::getCoursePoints(100));
    }

    // ──────────────────────────────────────────────────────────
    // Helper methods for setting up LD functions in separate processes
    // ──────────────────────────────────────────────────────────

    /**
     * Define LEARNDASH_VERSION and sfwd_lms_has_access (required for isActive).
     */
    private function defineLDBase(): void
    {
        if (!defined('LEARNDASH_VERSION')) {
            define('LEARNDASH_VERSION', '4.0.0');
        }

        if (!function_exists('sfwd_lms_has_access')) {
            eval('function sfwd_lms_has_access(int $courseId, int $userId): bool { return true; }');
        }
    }

    /**
     * Define learndash_get_setting to return a specific value for one course/key pair.
     */
    private function defineLDSetting(int $postId, string $key, string $value): void
    {
        if (!function_exists('learndash_get_setting')) {
            eval('function learndash_get_setting($postId, $key, $value = null) {
                static $settings = [];
                if ($value !== null) {
                    $settings[$postId . ":" . $key] = $value;
                    return;
                }
                return $settings[$postId . ":" . $key] ?? "";
            }');
        }
        // Store the setting via the 3-arg form
        learndash_get_setting($postId, $key, $value);
    }

    /**
     * Define learndash_get_setting with a map of "postId:key" => value pairs.
     */
    private function defineLDSettingMulti(array $map): void
    {
        if (!function_exists('learndash_get_setting')) {
            $mapExport = var_export($map, true);
            eval('function learndash_get_setting(int $postId, string $key) {
                $map = ' . $mapExport . ';
                return $map[$postId . ":" . $key] ?? "";
            }');
        }
    }

    /**
     * Define learndash_get_course_lessons_list to return specific lessons for a course.
     */
    private function defineLDLessons(int $courseId, array $lessons): void
    {
        if (!function_exists('learndash_get_course_lessons_list')) {
            // We need to serialize/unserialize objects through eval
            $GLOBALS['_test_ld_lessons'] = [$courseId => $lessons];
            eval('function learndash_get_course_lessons_list(int $courseId): array {
                return $GLOBALS["_test_ld_lessons"][$courseId] ?? [];
            }');
        }
    }

    /**
     * Define get_permalink stub for separate process tests.
     */
    private function defineGetPermalink(): void
    {
        if (!function_exists('get_permalink')) {
            eval('function get_permalink($postId = null): string {
                return "https://stride.test/?p=" . (int) $postId;
            }');
        }
    }

    /**
     * Define get_the_title stub for separate process tests.
     */
    private function defineGetTheTitle(): void
    {
        if (!function_exists('get_the_title')) {
            eval('function get_the_title($post = null): string {
                return "Course " . (int) $post;
            }');
        }
    }

    // ──────────────────────────────────────────────────────────
    // Regression for B3-004: course-exists guard on hasAccess
    // ──────────────────────────────────────────────────────────

    /**
     * @test
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function hasAccessReturnsFalseForNonExistentCourse(): void
    {
        if (!function_exists('learndash_get_setting')) {
            eval('function learndash_get_setting($id, $key = null) { return ""; }');
        }
        if (!function_exists('sfwd_lms_has_access')) {
            // Mimics LD core: returns true for any int, including non-existent.
            eval('function sfwd_lms_has_access(int $courseId, int $userId): bool { return true; }');
        }
        // _test_posts empty → get_post_type returns false → not 'sfwd-courses'
        $GLOBALS['_test_posts'] = [];

        self::assertFalse(LearnDashHelper::hasAccess(99999, 1));
    }
}
