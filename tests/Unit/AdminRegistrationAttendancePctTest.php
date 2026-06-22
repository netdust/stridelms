<?php

declare(strict_types=1);

namespace Stride\Tests\Unit;

use ReflectionMethod;
use Stride\Admin\AdminRegistrationQueryService;
use Stride\Tests\TestCase;

/**
 * Unit tests for AdminRegistrationQueryService::computeAttendancePct.
 *
 * FIX 3: attendance % must be clamped to [0,100]. A session trashed AFTER
 * attendance was marked makes the (publish-filtered) sessionCount denominator
 * smaller than the (non-publish-filtered) present-count numerator → >100%.
 * The clamp prevents the impossible value.
 *
 * Run: ddev exec vendor/bin/phpunit --testsuite Unit --filter AdminRegistrationAttendancePct
 */
final class AdminRegistrationAttendancePctTest extends TestCase
{
    private function invoke(int $userId, int $editionId, array $attendanceForEdition, int $sessionCount): ?int
    {
        // computeAttendancePct is pure arithmetic — it never touches the injected
        // repositories — so a constructor-less instance is sufficient and faithful.
        $service = (new \ReflectionClass(AdminRegistrationQueryService::class))
            ->newInstanceWithoutConstructor();

        $method = new ReflectionMethod(AdminRegistrationQueryService::class, 'computeAttendancePct');
        $method->setAccessible(true);

        return $method->invoke($service, $userId, $editionId, $attendanceForEdition, $sessionCount);
    }

    /**
     * @test
     * 4 'present' rows over 3 published sessions (one trashed after marking)
     * must clamp to 100, not report 133.
     */
    public function attendancePctClampedToHundred(): void
    {
        $userId    = 42;
        $editionId = 7;

        // 4 present marks (a 4th session was trashed → not counted in sessionCount).
        $attendanceForEdition = [
            $userId => [
                101 => 'present',
                102 => 'present',
                103 => 'present',
                104 => 'present',
            ],
        ];

        $result = $this->invoke($userId, $editionId, $attendanceForEdition, 3);

        $this->assertSame(100, $result, 'Attendance % must clamp to 100, never exceed it');
    }

    /**
     * @test
     * Regression: sessionCount=0 → null (division-by-zero guard preserved).
     */
    public function zeroSessionCountReturnsNull(): void
    {
        $result = $this->invoke(42, 7, [42 => [101 => 'present']], 0);

        $this->assertNull($result, 'sessionCount=0 must still return null');
    }

    /**
     * @test
     * Normal sub-100 case still computes correctly (no over-clamp).
     */
    public function normalAttendancePctUnaffected(): void
    {
        $userId = 42;
        $attendance = [
            $userId => [
                101 => 'present',
                102 => 'present',
                103 => 'absent',
            ],
        ];

        $result = $this->invoke($userId, 7, $attendance, 4);

        // 2 present / 4 sessions = 50.
        $this->assertSame(50, $result);
    }
}
