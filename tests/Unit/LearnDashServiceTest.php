<?php

declare(strict_types=1);

namespace Stride\Tests\Unit;

use Stride\Integrations\LearnDash\LearnDashService;
use Stride\Tests\TestCase;

/**
 * Unit tests for LearnDashService
 *
 * Tests the LMS adapter methods: grantAccess, revokeAccess, isComplete.
 * Each method guards against missing LD functions.
 */
class LearnDashServiceTest extends TestCase
{
    private LearnDashService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new LearnDashService();
    }

    /**
     * @test
     */
    public function testGrantAccessReturnsFalseWhenLDUnavailable(): void
    {
        $result = $this->service->grantAccess(1, 100);

        $this->assertFalse($result);
    }

    /**
     * @test
     */
    public function testRevokeAccessReturnsFalseWhenLDUnavailable(): void
    {
        $result = $this->service->revokeAccess(1, 100);

        $this->assertFalse($result);
    }

    /**
     * @test
     */
    public function testIsCompleteReturnsFalseWhenLDUnavailable(): void
    {
        $result = $this->service->isComplete(1, 100);

        $this->assertFalse($result);
    }

    /**
     * @test
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testGrantAccessReturnsTrueWhenLDAvailable(): void
    {
        $this->service = new LearnDashService();

        if (!function_exists('ld_update_course_access')) {
            eval('
                function ld_update_course_access(int $userId, int $courseId, bool $remove = false): bool
                {
                    return true;
                }
            ');
        }
        // Course must exist (B3-005 guard).
        $post = new \stdClass();
        $post->post_type = 'sfwd-courses';
        $GLOBALS['_test_posts'] = [100 => $post];

        $result = $this->service->grantAccess(42, 100);

        $this->assertTrue($result);
    }

    /**
     * @test
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testRevokeAccessReturnsTrueWhenLDAvailable(): void
    {
        $this->service = new LearnDashService();

        if (!function_exists('ld_update_course_access')) {
            eval('
                function ld_update_course_access(int $userId, int $courseId, bool $remove = false): bool
                {
                    return true;
                }
            ');
        }
        // Course must exist (B3-005 guard).
        $post = new \stdClass();
        $post->post_type = 'sfwd-courses';
        $GLOBALS['_test_posts'] = [100 => $post];

        $result = $this->service->revokeAccess(42, 100);

        $this->assertTrue($result);
    }

    /**
     * @test
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testIsCompleteReturnsTrueWhenCourseComplete(): void
    {
        $this->service = new LearnDashService();

        if (!function_exists('learndash_course_completed')) {
            eval('
                function learndash_course_completed(int $userId, int $courseId): bool
                {
                    return true;
                }
            ');
        }

        $result = $this->service->isComplete(42, 100);

        $this->assertTrue($result);
    }

    /**
     * @test
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testIsCompleteReturnsFalseForIncompleteCourse(): void
    {
        $this->service = new LearnDashService();

        if (!function_exists('learndash_course_completed')) {
            eval('
                function learndash_course_completed(int $userId, int $courseId): bool
                {
                    return false;
                }
            ');
        }

        $result = $this->service->isComplete(42, 100);

        $this->assertFalse($result);
    }

    // === Regression for B3-005: course-exists guard ===

    /**
     * @test
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testGrantAccessRejectsNonExistentCourse(): void
    {
        $this->service = new LearnDashService();

        if (!function_exists('ld_update_course_access')) {
            // Track LD invocation to assert it WASN'T called.
            eval('
                function ld_update_course_access(int $userId, int $courseId, bool $remove = false): bool
                {
                    $GLOBALS["_ld_call_count"] = ($GLOBALS["_ld_call_count"] ?? 0) + 1;
                    return true;
                }
            ');
        }
        $GLOBALS['_ld_call_count'] = 0;
        // Stubs/wordpress-stubs.php::get_post_type uses $_test_posts global;
        // an unset post_id returns false.
        $GLOBALS['_test_posts'] = [];

        $result = $this->service->grantAccess(42, 99999);

        $this->assertFalse($result);
        $this->assertSame(0, $GLOBALS['_ld_call_count'], 'LD should not be touched for non-existent course');
    }

    /**
     * @test
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testRevokeAccessRejectsNonExistentCourse(): void
    {
        $this->service = new LearnDashService();

        if (!function_exists('ld_update_course_access')) {
            eval('
                function ld_update_course_access(int $userId, int $courseId, bool $remove = false): bool
                {
                    $GLOBALS["_ld_call_count"] = ($GLOBALS["_ld_call_count"] ?? 0) + 1;
                    return true;
                }
            ');
        }
        $GLOBALS['_ld_call_count'] = 0;
        $GLOBALS['_test_posts'] = [];

        $result = $this->service->revokeAccess(42, 99999);

        $this->assertFalse($result);
        $this->assertSame(0, $GLOBALS['_ld_call_count']);
    }

    /**
     * @test
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testGrantAccessAcceptsRealCourse(): void
    {
        $this->service = new LearnDashService();

        if (!function_exists('ld_update_course_access')) {
            eval('
                function ld_update_course_access(int $userId, int $courseId, bool $remove = false): bool
                {
                    return true;
                }
            ');
        }
        // Register a fake sfwd-courses post via the stub global.
        $post = new \stdClass();
        $post->post_type = 'sfwd-courses';
        $GLOBALS['_test_posts'] = [100 => $post];

        $result = $this->service->grantAccess(42, 100);

        $this->assertTrue($result);
    }
}
