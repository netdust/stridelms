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

    // --- from_enrollment: completed flag ---

    public function test_enrollment_completed_clears_primary_cta_and_sets_voltooid_pill(): void
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

        $this->assertSame(['label' => 'Voltooid', 'tone' => 'muted'], $args['status_pill']);
        $this->assertNull($args['body']['primary_cta']);
        $this->assertSame(100, $args['body']['progress_pct']);
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
}
