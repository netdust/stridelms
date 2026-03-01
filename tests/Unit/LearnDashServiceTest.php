<?php

declare(strict_types=1);

namespace Stride\Tests\Unit;

use Stride\Integrations\LearnDash\LearnDashService;
use Stride\Tests\TestCase;

/**
 * Unit tests for LearnDashService
 *
 * Tests the LMS adapter methods: getEnrolledCourses, getProgress,
 * getCompletionDate. Each method guards against missing LD functions.
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
    public function testGetEnrolledCoursesReturnsEmptyArrayWhenLDUnavailable(): void
    {
        $result = $this->service->getEnrolledCourses(1);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    /**
     * @test
     */
    public function testGetProgressReturnsZeroWhenLDUnavailable(): void
    {
        $result = $this->service->getProgress(1, 100);

        $this->assertSame(0, $result);
    }

    /**
     * @test
     */
    public function testGetCompletionDateReturnsNullWhenLDUnavailable(): void
    {
        $result = $this->service->getCompletionDate(1, 100);

        $this->assertNull($result);
    }

    /**
     * @test
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testGetEnrolledCoursesReturnsIntArrayWhenLDAvailable(): void
    {
        $this->service = new LearnDashService();

        if (!function_exists('learndash_user_get_enrolled_courses')) {
            eval('
                function learndash_user_get_enrolled_courses(int $userId): array
                {
                    return [101, 202, 303];
                }
            ');
        }

        $result = $this->service->getEnrolledCourses(42);

        $this->assertIsArray($result);
        $this->assertSame([101, 202, 303], $result);
    }

    /**
     * @test
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testGetProgressReturnsPercentageWhenLDAvailable(): void
    {
        $this->service = new LearnDashService();

        if (!function_exists('learndash_course_progress')) {
            eval('
                function learndash_course_progress(array $args): array
                {
                    return ["percentage" => 75];
                }
            ');
        }

        $result = $this->service->getProgress(42, 100);

        $this->assertSame(75, $result);
    }

    /**
     * @test
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testGetCompletionDateReturnsTimestampWhenComplete(): void
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

        if (!function_exists('learndash_user_get_course_completed_date')) {
            eval('
                function learndash_user_get_course_completed_date(int $userId, int $courseId): int
                {
                    return 1709136000;
                }
            ');
        }

        $result = $this->service->getCompletionDate(42, 100);

        $this->assertSame(1709136000, $result);
    }

    /**
     * @test
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testGetCompletionDateReturnsNullForIncompleteCourse(): void
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

        $result = $this->service->getCompletionDate(42, 100);

        $this->assertNull($result);
    }
}
