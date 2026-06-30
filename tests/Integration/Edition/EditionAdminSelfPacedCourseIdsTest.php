<?php

declare(strict_types=1);

namespace Stride\Tests\Integration\Edition;

use IntegrationTestCase;
use Stride\Modules\Attendance\AttendanceRepository;
use Stride\Modules\Edition\Admin\EditionAdminController;
use Stride\Modules\Edition\EditionRepository;
use Stride\Modules\Edition\EditionService;
use Stride\Modules\Edition\SessionRepository;
use Stride\Modules\Edition\SessionService;

/**
 * EditionAdminController::getSelfPacedCourseIds() — the narrow list that gates
 * the Sessions metabox.
 *
 * The bug: the Sessions metabox was hidden for ANY online-format course
 * (online/webinar/e-learning), so admins could not edit a webinar's scheduled
 * sessions. The fix decouples the Sessions gate onto a NARROWER list that is
 * ONLY self-paced e-learning — webinars and live-online keep their sessions UI.
 *
 * Contract asserted here:
 *   - a course tagged `e-learning` IS returned
 *   - a course tagged `webinar`     is NOT returned (the regression guard)
 *   - a course tagged `online`      is NOT returned
 *   - a course tagged `klassikaal`  is NOT returned
 *
 * getSelfPacedCourseIds() is private (mirrors getOnlineCourseIds()); it runs a
 * real `stride_format` tax_query, so it is exercised through a reflected call
 * on a container-wired controller — the same shape the production wiring uses.
 *
 * Run: ddev exec vendor/bin/phpunit -c phpunit-integration.xml.dist --filter EditionAdminSelfPacedCourseIdsTest
 */
final class EditionAdminSelfPacedCourseIdsTest extends IntegrationTestCase
{
    private function controller(): EditionAdminController
    {
        return new EditionAdminController(
            ntdst_get(EditionService::class),
            ntdst_get(EditionRepository::class),
            ntdst_get(SessionService::class),
            ntdst_get(SessionRepository::class),
            ntdst_get(AttendanceRepository::class),
        );
    }

    /**
     * @return list<int>
     */
    private function selfPacedIds(): array
    {
        $controller = $this->controller();
        $method = new \ReflectionMethod($controller, 'getSelfPacedCourseIds');
        $method->setAccessible(true);

        return array_map('intval', $method->invoke($controller));
    }

    private function assignFormat(int $courseId, string $slug): void
    {
        $term = term_exists($slug, 'stride_format') ?: wp_insert_term($slug, 'stride_format');
        $termId = is_array($term) ? (int) $term['term_id'] : (int) $term;
        wp_set_object_terms($courseId, [$termId], 'stride_format');
    }

    public function test_elearning_course_is_self_paced(): void
    {
        $course = $this->createTestCourse();
        $this->assignFormat($course, 'e-learning');

        $this->assertContains(
            $course,
            $this->selfPacedIds(),
            'An e-learning course must be in the self-paced list (its Sessions UI is suppressed)',
        );
    }

    public function test_webinar_course_is_not_self_paced(): void
    {
        // Regression guard for the actual bug: a webinar has live scheduled
        // sessions and must KEEP its Sessions metabox — it must NOT be classed
        // self-paced.
        $course = $this->createTestCourse();
        $this->assignFormat($course, 'webinar');

        $this->assertNotContains(
            $course,
            $this->selfPacedIds(),
            'A webinar course must NOT be self-paced — it has live sessions',
        );
    }

    public function test_online_course_is_not_self_paced(): void
    {
        $course = $this->createTestCourse();
        $this->assignFormat($course, 'online');

        $this->assertNotContains(
            $course,
            $this->selfPacedIds(),
            'A live-online course must NOT be self-paced — only e-learning suppresses sessions',
        );
    }

    public function test_klassikaal_course_is_not_self_paced(): void
    {
        $course = $this->createTestCourse();
        $this->assignFormat($course, 'klassikaal');

        $this->assertNotContains(
            $course,
            $this->selfPacedIds(),
            'A klassikaal course must NOT be self-paced',
        );
    }
}
