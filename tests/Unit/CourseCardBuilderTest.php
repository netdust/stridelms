<?php

declare(strict_types=1);

namespace Stride\Tests\Unit;

use Stride\Tests\TestCase;

/**
 * Unit tests for course-card builder helpers.
 *
 * Targets two pure-mapping functions in themes/stridence/helpers/templates.php:
 * - \stridence_build_course_card_args_from_enrollment()
 * - stridence_build_course_card_args_from_trajectory_course()
 */
final class CourseCardBuilderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once dirname(__DIR__, 2) . '/web/app/themes/stridence/helpers/templates.php';
    }

    // --- from_enrollment: edition with pending tasks ---

    public function test_enrollment_edition_with_pending_tasks_sets_pending_count_and_task_summary(): void
    {
        $enrollment = [
            'type'         => 'edition',
            'edition_id'   => 100,
            'course_id'    => 50,
            'course_title' => 'Eerste hulp bij sportblessures',
            'start_date'   => '2026-06-10',
            'venue'        => 'Brussel',
            'sessions'     => [],
            'task_summary' => ['total' => 3, 'completed' => 1],
            'complete_url' => 'https://example.test/vormingen/edition-100/voltooien/',
            'cta'          => ['url' => 'https://example.test/vormingen/edition-100/voltooien/', 'label' => 'Inschrijving voltooien'],
            'progress'     => ['attended' => 0, 'required' => 0],
        ];

        $args = \stridence_build_course_card_args_from_enrollment($enrollment);

        $this->assertSame('edition', $args['type']);
        $this->assertTrue($args['enrolled']);
        $this->assertSame(2, $args['meta']['pending_tasks_count']);
        $this->assertSame(['total' => 3, 'completed' => 1], $args['body']['task_summary']);
        $this->assertSame('Inschrijving voltooien', $args['body']['primary_cta']['label']);
        $this->assertFalse($args['initial_open']);
    }

    // --- from_enrollment: online course with progress ---

    public function test_enrollment_online_at_60pct_sets_progress_label(): void
    {
        $enrollment = [
            'type'              => 'online',
            'course_id'         => 50,
            'course_title'      => 'Sportpsychologie 101',
            'course_url'        => 'https://example.test/opleidingen/sport-psy/',
            'progress'          => 60,
            'format_label'      => 'Online',
            'total_lessons'     => 5,
            'completed_lessons' => 3,
            'days_remaining'    => 28,
        ];

        $args = \stridence_build_course_card_args_from_enrollment($enrollment);

        $this->assertSame('online', $args['type']);
        $this->assertTrue($args['enrolled']);
        $this->assertSame(60, $args['body']['progress_pct']);
        $this->assertSame('3 van 5 lessen', $args['meta']['progress_label']);
        $this->assertSame('https://example.test/opleidingen/sport-psy/', $args['body']['primary_cta']['url']);
        $this->assertSame('Verder leren', $args['body']['primary_cta']['label']);
    }

    // --- from_enrollment: completed flag without certificate clears primary CTA ---

    public function test_enrollment_completed_without_cert_clears_primary_cta_and_sets_voltooid_pill(): void
    {
        $enrollment = [
            'type'              => 'online',
            'course_id'         => 50,
            'course_title'      => 'Sportpsychologie 101',
            'progress'          => 100,
            'total_lessons'     => 5,
            'completed_lessons' => 5,
            'completed_at'      => '2026-04-20',
        ];

        $args = \stridence_build_course_card_args_from_enrollment($enrollment, completed: true);

        $this->assertSame(['label' => 'Voltooid', 'tone' => 'muted'], $args['status_pill']);
        $this->assertNull($args['body']['primary_cta']);
        $this->assertSame(100, $args['body']['progress_pct']);
    }

    // --- from_enrollment: completed with certificate surfaces download CTA ---

    public function test_enrollment_completed_with_cert_surfaces_download_primary_cta(): void
    {
        $enrollment = [
            'type'              => 'online',
            'course_id'         => 50,
            'course_title'      => 'Sportpsychologie 101',
            'progress'          => 100,
            'total_lessons'     => 5,
            'completed_lessons' => 5,
            'completed_at'      => '2026-04-20',
            'certificate_url'   => 'https://example.test/cert',
        ];

        $args = \stridence_build_course_card_args_from_enrollment($enrollment, completed: true);

        $this->assertNotNull($args['body']['primary_cta']);
        $this->assertSame('Download certificaat', $args['body']['primary_cta']['label']);
        $this->assertSame('https://example.test/cert', $args['body']['primary_cta']['url']);
    }

    // --- from_enrollment: edition online progress = 0 means 'Start cursus' ---

    public function test_enrollment_online_zero_progress_uses_start_cursus_label(): void
    {
        $enrollment = [
            'type'              => 'online',
            'course_id'         => 50,
            'course_title'      => 'New course',
            'course_url'        => 'https://example.test/opleidingen/new/',
            'progress'          => 0,
            'total_lessons'     => 5,
            'completed_lessons' => 0,
        ];

        $args = \stridence_build_course_card_args_from_enrollment($enrollment);

        $this->assertSame('Start cursus', $args['body']['primary_cta']['label']);
    }

    // --- from_enrollment: edition with sessions list ---

    public function test_enrollment_edition_passes_sessions_through_to_body(): void
    {
        $enrollment = [
            'type'         => 'edition',
            'edition_id'   => 100,
            'course_id'    => 50,
            'course_title' => 'Cursus A',
            'start_date'   => '2026-06-10',
            'venue'        => '',
            'sessions'     => [
                ['date' => '2026-06-10', 'start_time' => '09:00', 'end_time' => '12:00'],
                ['date' => '2026-06-17', 'start_time' => '09:00', 'end_time' => '12:00'],
            ],
            'task_summary' => null,
            'cta'          => null,
            'progress'     => ['attended' => 0, 'required' => 2],
        ];

        $args = \stridence_build_course_card_args_from_enrollment($enrollment);

        $this->assertCount(2, $args['body']['sessions']);
        $this->assertSame('2026-06-10', $args['body']['sessions'][0]['date']);
        $this->assertNull($args['body']['primary_cta']);
    }

    // --- from_enrollment: imminence — Vandaag/Morgen badge driven by next_session ---

    public function test_enrollment_edition_today_session_sets_imminence_today(): void
    {
        $today = date('Y-m-d');
        $enrollment = [
            'type'         => 'edition',
            'edition_id'   => 100,
            'course_id'    => 50,
            'course_title' => 'Today course',
            'start_date'   => '2026-06-10',
            'next_session' => ['date' => $today, 'start_time' => '09:00', 'end_time' => '12:00'],
        ];

        $args = \stridence_build_course_card_args_from_enrollment($enrollment);

        $this->assertSame('today', $args['meta']['imminence']);
    }

    public function test_enrollment_edition_tomorrow_session_sets_imminence_tomorrow(): void
    {
        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        $enrollment = [
            'type'         => 'edition',
            'edition_id'   => 100,
            'course_id'    => 50,
            'course_title' => 'Tomorrow course',
            'start_date'   => '2026-06-10',
            'next_session' => ['date' => $tomorrow],
        ];

        $args = \stridence_build_course_card_args_from_enrollment($enrollment);

        $this->assertSame('tomorrow', $args['meta']['imminence']);
    }

    public function test_enrollment_edition_far_future_leaves_imminence_null(): void
    {
        $enrollment = [
            'type'         => 'edition',
            'edition_id'   => 100,
            'course_id'    => 50,
            'course_title' => 'Far future course',
            'start_date'   => '2099-01-01',
        ];

        $args = \stridence_build_course_card_args_from_enrollment($enrollment);

        $this->assertNull($args['meta']['imminence']);
    }

    // --- from_trajectory_course: required course with editions ---

    public function test_trajectory_required_course_with_editions_populates_body_editions(): void
    {
        $course = $this->createMock(\WP_Post::class);
        $course->ID = 50;
        $course->post_title = 'Verplichte basiscursus';
        $course->post_name = 'verplichte-basiscursus';

        // Stride/EditionService is invoked via ntdst_get(); see _registerEditionServiceStub() helper
        $this->_registerEditionServiceStub([
            ['id' => 100, 'start_date' => '2026-06-10', 'venue' => 'Brussel', 'can_enroll' => true],
            ['id' => 101, 'start_date' => '2026-07-15', 'venue' => 'Gent',    'can_enroll' => true],
        ]);

        $pill = ['label' => 'Verplicht', 'tone' => 'primary'];
        $args = \stridence_build_course_card_args_from_trajectory_course($course, $pill);

        $this->assertSame('public', $args['type']);
        $this->assertFalse($args['enrolled']);
        $this->assertSame($pill, $args['status_pill']);
        $this->assertCount(2, $args['body']['upcoming_editions']);
        $this->assertSame('2026-06-10', $args['meta']['start_date']);
        // This is a READ-ONLY overview card: no enrol buttons. The "full course"
        // link is rendered inline via body.course_url, not as a secondary_cta
        // button — both CTAs stay null (builder shape since the overview redesign).
        $this->assertNull($args['body']['primary_cta']);
        $this->assertNull($args['body']['secondary_cta']);
        $this->assertSame(home_url('/edities/' . $course->post_name . '/'), $args['body']['course_url']);
    }

    // --- from_trajectory_course: course with no editions ---

    public function test_trajectory_course_with_no_editions_leaves_meta_start_date_null(): void
    {
        $course = $this->createMock(\WP_Post::class);
        $course->ID = 51;
        $course->post_title = 'Cursus zonder editie';

        $this->_registerEditionServiceStub([]);

        $args = \stridence_build_course_card_args_from_trajectory_course($course, ['label' => 'Keuzevak', 'tone' => 'accent']);

        $this->assertSame([], $args['body']['upcoming_editions']);
        $this->assertNull($args['meta']['start_date']);
    }

    // --- from_trajectory_course: status pill passes through unchanged ---

    public function test_trajectory_course_passes_status_pill_through(): void
    {
        $course = $this->createMock(\WP_Post::class);
        $course->ID = 52;
        $course->post_title = 'Cursus C';

        $this->_registerEditionServiceStub([]);

        $pill = ['label' => 'Speciaal', 'tone' => 'accent'];
        $args = \stridence_build_course_card_args_from_trajectory_course($course, $pill);

        $this->assertSame($pill, $args['status_pill']);
    }

    /**
     * Register Edition service + repository stubs in the DI container so the
     * card builder can resolve them. Service exposes canEnroll() (business logic);
     * repository exposes findByCourse() / getField() (data access).
     */
    private function _registerEditionServiceStub(array $editions): void
    {
        $serviceStub = new class($editions) {
            public function __construct(private array $editions) {}
            public function canEnroll(int $editionId): bool
            {
                foreach ($this->editions as $ed) {
                    if ((int) ($ed['id'] ?? 0) === $editionId) {
                        return (bool) ($ed['can_enroll'] ?? false);
                    }
                }
                return false;
            }
            // The builder also reads capacity/registered to compute
            // places_remaining; uncapped (0) keeps these editions seat-agnostic.
            public function getCapacity(int $editionId): int { return 0; }
            public function getRegisteredCount(int $editionId): int { return 0; }
        };

        // The builder resolves SessionService to derive the time window from an
        // edition's first session. These fixtures carry no sessions, so an
        // empty array is the faithful stub (start_time/end_time stay null).
        $sessionStub = new class {
            public function getSessionsForEdition(int $editionId): array { return []; }
        };

        $repoStub = new class($editions) {
            public function __construct(private array $editions) {}
            public function findByCourse(int $courseId): array { return $this->editions; }
            public function getField(int $editionId, string $field, mixed $default = null): mixed
            {
                foreach ($this->editions as $ed) {
                    if ((int) ($ed['id'] ?? 0) === $editionId) {
                        return $ed[$field] ?? $default;
                    }
                }
                return $default;
            }
        };

        $this->registerService(\Stride\Modules\Edition\EditionService::class, $serviceStub);
        $this->registerService(\Stride\Modules\Edition\EditionRepository::class, $repoStub);
        $this->registerService(\Stride\Modules\Edition\SessionService::class, $sessionStub);
    }
}
