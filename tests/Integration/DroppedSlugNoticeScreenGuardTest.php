<?php

declare(strict_types=1);

namespace Stride\Tests\Integration;

use IntegrationTestCase;
use Stride\Modules\Attendance\AttendanceRepository;
use Stride\Modules\Edition\Admin\EditionAdminController;
use Stride\Modules\Edition\EditionCPT;
use Stride\Modules\Edition\EditionRepository;
use Stride\Modules\Edition\EditionService;
use Stride\Modules\Edition\SessionRepository;
use Stride\Modules\Edition\SessionService;
use Stride\Modules\Enrollment\RegistrationRepository;
use Stride\Modules\Trajectory\Admin\TrajectoryAdminController;
use Stride\Modules\Trajectory\TrajectoryCPT;
use Stride\Modules\Trajectory\TrajectoryRepository;
use Stride\Modules\Trajectory\TrajectoryService;

/**
 * Shake-out Fix 1 (behavior change) — the dropped-slug admin_notices renderer on
 * BOTH EditionAdminController and TrajectoryAdminController must be SCREEN-GUARDED:
 * it may only echo its notice on its OWN CPT edit screen.
 *
 * Contract (from the shake-out review):
 *   1. GUARD (denial): with a pending dropped-slug transient set for the current
 *      user, renderDroppedSlugNotice() emits NO output when the current screen's
 *      post_type is something OTHER than the controller's CPT (e.g. the WP
 *      dashboard, or the sibling CPT's screen). The transient is NOT consumed.
 *   2. ALLOW (happy path): on the controller's OWN CPT edit screen the notice
 *      still renders (the flag is surfaced), and the transient is consumed.
 *   3. NO CROSS-CONSUMPTION: Edition's renderer, running on the Trajectory screen,
 *      must not consume the shared-key transient — so Trajectory's renderer on its
 *      own screen still finds and renders it. (This is the shared-key collision the
 *      guard makes moot.)
 *
 * These are the behavioral RED assertions the screen guard must satisfy. Before the
 * guard, renderDroppedSlugNotice() renders on WHATEVER admin page loads next and the
 * first callback to run consumes the shared key — so assertions 1 and 3 fail RED.
 *
 * Run: STRIDE_TEST_DB_DISPOSABLE=1 ddev exec bash -c \
 *   'STRIDE_TEST_DB_DISPOSABLE=1 vendor/bin/phpunit -c phpunit-integration.xml.dist --filter DroppedSlugNoticeScreenGuard'
 */
final class DroppedSlugNoticeScreenGuardTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once ABSPATH . 'wp-admin/includes/screen.php';
        wp_set_current_user((int) self::$testUserId);
        wp_get_current_user()->set_role('administrator');
    }

    protected function tearDown(): void
    {
        delete_transient('stride_dropped_profiletype_slugs_' . get_current_user_id());
        set_current_screen('front');
        wp_set_current_user(0);
        parent::tearDown();
    }

    // ======================================================================
    // Edition renderer
    // ======================================================================

    public function test_edition_notice_does_not_render_on_wrong_screen(): void
    {
        $this->flagSlugs();
        set_current_screen('dashboard'); // NOT vad_edition

        $output = $this->capture(fn() => $this->editionController()->renderDroppedSlugNotice());

        $this->assertSame('', $output, 'Edition dropped-slug notice must NOT render off its CPT screen');
        $this->assertNotEmpty(
            get_transient($this->key()),
            'the transient must survive a wrong-screen render (not consumed)',
        );
    }

    public function test_edition_notice_renders_on_edition_screen(): void
    {
        $this->flagSlugs();
        set_current_screen(EditionCPT::POST_TYPE);

        $output = $this->capture(fn() => $this->editionController()->renderDroppedSlugNotice());

        $this->assertStringContainsString('notice-warning', $output, 'notice must render on the edition CPT screen');
        $this->assertStringContainsString('ditbestaatniet', $output, 'the dropped slug name must be surfaced');
        $this->assertFalse(get_transient($this->key()), 'the transient must be consumed after rendering');
    }

    // ======================================================================
    // Trajectory renderer
    // ======================================================================

    public function test_trajectory_notice_does_not_render_on_wrong_screen(): void
    {
        $this->flagSlugs();
        set_current_screen('dashboard'); // NOT vad_trajectory

        $output = $this->capture(fn() => $this->trajectoryController()->renderDroppedSlugNotice());

        $this->assertSame('', $output, 'Trajectory dropped-slug notice must NOT render off its CPT screen');
        $this->assertNotEmpty(
            get_transient($this->key()),
            'the transient must survive a wrong-screen render (not consumed)',
        );
    }

    public function test_trajectory_notice_renders_on_trajectory_screen(): void
    {
        $this->flagSlugs();
        set_current_screen(TrajectoryCPT::POST_TYPE);

        $output = $this->capture(fn() => $this->trajectoryController()->renderDroppedSlugNotice());

        $this->assertStringContainsString('notice-warning', $output, 'notice must render on the trajectory CPT screen');
        $this->assertFalse(get_transient($this->key()), 'the transient must be consumed after rendering');
    }

    // ======================================================================
    // No cross-consumption — the shared-key collision the guard makes moot
    // ======================================================================

    public function test_edition_renderer_on_trajectory_screen_does_not_consume_trajectory_notice(): void
    {
        $this->flagSlugs();

        // Edition's callback fires while on the TRAJECTORY screen (as admin_notices
        // fires ALL registered callbacks on every admin page). It must render nothing
        // AND leave the transient intact for Trajectory's own renderer.
        set_current_screen(TrajectoryCPT::POST_TYPE);
        $editionOutput = $this->capture(fn() => $this->editionController()->renderDroppedSlugNotice());
        $this->assertSame('', $editionOutput, 'edition renderer must be silent on the trajectory screen');

        // Now Trajectory's renderer, on its own screen, still finds and renders it.
        $trajectoryOutput = $this->capture(fn() => $this->trajectoryController()->renderDroppedSlugNotice());
        $this->assertStringContainsString(
            'notice-warning',
            $trajectoryOutput,
            'trajectory notice must still render because the edition renderer did not consume the shared key',
        );
    }

    // ======================================================================
    // Harness
    // ======================================================================

    private function key(): string
    {
        return 'stride_dropped_profiletype_slugs_' . get_current_user_id();
    }

    private function flagSlugs(): void
    {
        set_transient($this->key(), ['ditbestaatniet'], 60);
    }

    /** @param callable():void $fn */
    private function capture(callable $fn): string
    {
        ob_start();
        $fn();
        return (string) ob_get_clean();
    }

    private function editionController(): EditionAdminController
    {
        return new EditionAdminController(
            ntdst_get(EditionService::class),
            ntdst_get(EditionRepository::class),
            ntdst_get(SessionService::class),
            ntdst_get(SessionRepository::class),
            ntdst_get(AttendanceRepository::class),
        );
    }

    private function trajectoryController(): TrajectoryAdminController
    {
        return new TrajectoryAdminController(
            ntdst_get(TrajectoryService::class),
            ntdst_get(TrajectoryRepository::class),
            ntdst_get(RegistrationRepository::class),
            ntdst_get(EditionRepository::class),
        );
    }
}
