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
}
