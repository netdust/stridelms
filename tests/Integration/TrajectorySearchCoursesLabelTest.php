<?php

declare(strict_types=1);

namespace Stride\Tests\Integration;

use IntegrationTestCase;
use Stride\Modules\Edition\EditionRepository;
use Stride\Modules\Enrollment\RegistrationRepository;
use Stride\Modules\Trajectory\Admin\TrajectoryAdminController;
use Stride\Modules\Trajectory\TrajectoryRepository;
use Stride\Modules\Trajectory\TrajectoryService;
use lucatume\WPBrowser\WordPress\WPDieException;

/**
 * Regression coverage for the "Onbekende cursus" bug in the trajectory
 * add-course dropdown.
 *
 * TrajectoryAdminController::ajaxSearchCoursesAndEditions() (source ~1343) builds
 * the Select2 options for the trajectory course/edition picker. The "Edities"
 * group derived each option's label from the edition's course title.
 *
 * THE BUG: it read the course id as $meta['course_id'] (BARE key) from the array
 * returned by EditionRepository::findUpcoming() -> withMeta(). withMeta() returns
 * meta keys with the storage prefix INTACT (_ntdst_course_id), so $meta['course_id']
 * was ALWAYS missing -> get_the_title(0) -> '' -> the __('Onbekende cursus') fallback
 * fired for EVERY upcoming edition. Live DB: 20/20 editions rendered "Onbekende cursus".
 *
 * This test drives the real AJAX path against an upcoming edition linked to a real
 * course, and asserts the returned option label carries the actual course TITLE,
 * not the "Onbekende cursus" fallback.
 *
 * Run: STRIDE_TEST_DB_DISPOSABLE=1 ddev exec --raw -- bash -c \
 *   'cd /var/www/html; STRIDE_TEST_DB_DISPOSABLE=1 vendor/bin/phpunit \
 *    -c phpunit-integration.xml.dist --filter TrajectorySearchCoursesLabel'
 */
final class TrajectorySearchCoursesLabelTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // verifyAjaxNonce() requires the stride_manage cap; the base fixture user
        // is a plain subscriber. Promote to administrator.
        wp_set_current_user((int) self::$testUserId);
        wp_get_current_user()->set_role('administrator');
    }

    protected function tearDown(): void
    {
        unset($_POST['nonce'], $_REQUEST['nonce'], $_POST['search'], $_REQUEST['search']);
        parent::tearDown();
    }

    private function controller(): TrajectoryAdminController
    {
        return new TrajectoryAdminController(
            ntdst_get(TrajectoryService::class),
            ntdst_get(TrajectoryRepository::class),
            ntdst_get(RegistrationRepository::class),
            ntdst_get(EditionRepository::class),
        );
    }

    /**
     * Drive ajaxSearchCoursesAndEditions through the real nonce-checked path and
     * return the decoded JSON payload (the terminal wp_send_json_success throws
     * WPDieException in the harness; swallow it and read the echoed JSON).
     *
     * @return array<string,mixed>|null
     */
    private function driveSearch(string $search = ''): ?array
    {
        wp_set_current_user((int) self::$testUserId);

        $_POST['nonce'] = $_REQUEST['nonce'] = wp_create_nonce('stride_trajectory_admin');
        $_POST['search'] = $_REQUEST['search'] = $search;

        $controller = $this->controller();

        $forceAjax = static fn (): bool => true;
        $thrower = static function (): callable {
            return static function (): void {
                throw new WPDieException('');
            };
        };

        add_filter('wp_doing_ajax', $forceAjax);
        add_filter('wp_die_ajax_handler', $thrower);

        ob_start();
        try {
            $controller->ajaxSearchCoursesAndEditions();
        } catch (WPDieException $e) {
            // terminal wp_send_json_success
        } finally {
            $json = ob_get_clean();
            remove_filter('wp_doing_ajax', $forceAjax);
            remove_filter('wp_die_ajax_handler', $thrower);
        }

        $payload = $json === '' ? null : json_decode($json, true);

        return is_array($payload) ? $payload : null;
    }

    public function testUpcomingEditionOptionUsesRealCourseTitleNotOnbekendeCursus(): void
    {
        $courseTitle = 'QA Cursus ' . wp_generate_password(6, false);
        $courseId = $this->createTestCourse(['post_title' => $courseTitle]);

        // Upcoming edition (start_date in the future) linked to the real course.
        $startDate = date('Y-m-d', strtotime('+30 days'));
        $this->createTestEdition([
            'post_title' => 'QA Editie ' . wp_generate_password(4, false),
            'meta' => [
                '_ntdst_course_id' => $courseId,
                '_ntdst_start_date' => $startDate,
                '_ntdst_status' => 'open',
            ],
        ]);

        $payload = $this->driveSearch();

        $this->assertNotNull($payload, 'AJAX search returned no JSON payload.');
        $this->assertTrue($payload['success'] ?? false, 'AJAX search did not report success.');

        // Collect every edition-group option label.
        $labels = [];
        foreach ($payload['data']['results'] ?? [] as $group) {
            if (($group['text'] ?? '') === 'Edities') {
                foreach ($group['children'] ?? [] as $child) {
                    $labels[] = $child['text'] ?? '';
                }
            }
        }

        $this->assertNotEmpty($labels, 'No "Edities" options were returned for the upcoming edition.');

        $matching = array_filter($labels, static fn (string $l): bool => str_contains($l, $courseTitle));
        $this->assertNotEmpty(
            $matching,
            'Edition option should carry the real course title "' . $courseTitle
                . '" but got: ' . implode(' | ', $labels)
        );

        foreach ($labels as $label) {
            $this->assertStringNotContainsString(
                'Onbekende cursus',
                $label,
                'Edition option fell back to "Onbekende cursus" for an edition with a valid course.'
            );
        }
    }
}
