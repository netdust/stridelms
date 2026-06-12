<?php

declare(strict_types=1);

namespace Stride\Tests\Unit;

use Stride\Integrations\LearnDash\LearnDashHelper;
use Stride\Tests\TestCase;

/**
 * Regression net for LearnDashHelper::isEnrolled() enrollment-state ordering
 * (bug "isenrolled free-mode gap", fixed 2026-05-20 in 1f35717a).
 *
 * course_X_access_from is LD's universal enrollment marker for EVERY access
 * mode — an enrolled user whose access window lapsed is still enrolled. The
 * pre-fix code only honoured the marker for MODE_OPEN, so free-mode courses
 * with expired windows rendered as "Beschikbaar" for enrolled users.
 *
 * Each test runs in its own process: LD functions are defined via eval()
 * behind function_exists guards, so definitions from other test classes in
 * the same process would otherwise leak in and freeze the scenario.
 */
class LearnDashHelperEnrollmentStateTest extends TestCase
{
    /**
     * @test
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function freeModeEnrolledUserWithLapsedAccessIsStillEnrolled(): void
    {
        // sfwd_lms_has_access() = false models the expired access window.
        $this->defineLearnDash(mode: 'free', hasAccess: false, progressPct: 0);
        update_user_meta(7, 'course_42_access_from', 1700000000);

        $this->assertTrue(LearnDashHelper::isEnrolled(42, 7));
    }

    /**
     * @test
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function openModeEnrollmentMarkerIsHonoured(): void
    {
        $this->defineLearnDash(mode: 'open', hasAccess: false, progressPct: 0);
        update_user_meta(7, 'course_42_access_from', 1700000000);

        $this->assertTrue(LearnDashHelper::isEnrolled(42, 7));
    }

    /**
     * @test
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function progressAloneMarksUserEnrolled(): void
    {
        $this->defineLearnDash(mode: 'free', hasAccess: false, progressPct: 40);

        $this->assertTrue(LearnDashHelper::isEnrolled(42, 7));
    }

    /**
     * @test
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function neverEnrolledFreeModeUserIsNotEnrolled(): void
    {
        $this->defineLearnDash(mode: 'free', hasAccess: false, progressPct: 0);

        $this->assertFalse(LearnDashHelper::isEnrolled(42, 7));
    }

    // === Helpers ===

    /**
     * Define the LD surface isEnrolled() touches, driven by globals so the
     * scenario stays adjustable after definition.
     */
    private function defineLearnDash(string $mode, bool $hasAccess, int $progressPct): void
    {
        if (!defined('LEARNDASH_VERSION')) {
            define('LEARNDASH_VERSION', '4.0-test');
        }

        $GLOBALS['_test_ld_mode'] = $mode;
        $GLOBALS['_test_ld_has_access'] = $hasAccess;
        $GLOBALS['_test_ld_progress'] = $progressPct;

        if (!function_exists('sfwd_lms_has_access')) {
            eval('function sfwd_lms_has_access($courseId, $userId) { return $GLOBALS["_test_ld_has_access"]; }');
        }
        if (!function_exists('learndash_get_setting')) {
            eval('function learndash_get_setting($postId, $key = null) {
                if ($key === "course_price_type") { return $GLOBALS["_test_ld_mode"]; }
                return ["course_price_type" => $GLOBALS["_test_ld_mode"]];
            }');
        }
        if (!function_exists('learndash_course_progress')) {
            eval('function learndash_course_progress($args) { return ["percentage" => $GLOBALS["_test_ld_progress"]]; }');
        }
    }
}
