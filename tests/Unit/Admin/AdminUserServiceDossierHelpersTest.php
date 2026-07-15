<?php

declare(strict_types=1);

namespace Stride\Tests\Unit\Admin;

use ReflectionMethod;
use Stride\Admin\AdminUserService;
use Stride\Tests\TestCase;

/**
 * Unit: the dossier read-model helpers added by the admin-dashboard
 * production-readiness pass (F-D3/D4/D11/D12/D13).
 *
 * All helpers are private composition steps of getUserDetail — tested via
 * reflection (the endpoint itself is covered by the integration suite).
 */
final class AdminUserServiceDossierHelpersTest extends TestCase
{
    private AdminUserService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AdminUserService();
    }

    private function call(string $method, mixed ...$args): mixed
    {
        $ref = new ReflectionMethod(AdminUserService::class, $method);

        return $ref->invoke($this->service, ...$args);
    }

    // ---- EnrollmentCompletion::taskDisplayList (F-D4: the REAL completion
    // tasks, Dutch labels — the shared builder the dossier payload consumes) ----

    public function test_task_display_list_maps_types_to_dutch_labels_and_statuses(): void
    {
        $tasks = [
            'questionnaire' => ['status' => 'completed', 'completed_at' => '2026-05-01T10:00:00+00:00', 'phase' => 'enrollment'],
            'approval' => ['status' => 'pending', 'phase' => 'enrollment'],
            'post_approval' => ['status' => 'pending', 'phase' => 'post_course'],
        ];

        $list = \Stride\Modules\Enrollment\EnrollmentCompletion::taskDisplayList($tasks);

        $this->assertCount(3, $list);
        $this->assertSame('questionnaire', $list[0]['type']);
        $this->assertSame('Intakevragen', $list[0]['label']);
        $this->assertSame('completed', $list[0]['status']);
        $this->assertSame('01/05/2026', $list[0]['completed_at']);
        $this->assertSame('Goedkeuring', $list[1]['label']);
        $this->assertSame('pending', $list[1]['status']);
        $this->assertSame('', $list[1]['completed_at']);
        $this->assertSame('Aftekening', $list[2]['label']);
        $this->assertSame('post_course', $list[2]['phase']);
    }

    public function test_task_display_list_skips_malformed_entries_and_guards_bad_dates(): void
    {
        $this->assertSame([], \Stride\Modules\Enrollment\EnrollmentCompletion::taskDisplayList([]));

        $list = \Stride\Modules\Enrollment\EnrollmentCompletion::taskDisplayList([
            'approval' => 'not-an-array',
            'documents' => ['status' => 'completed', 'completed_at' => 'niet-een-datum'],
        ]);
        $this->assertCount(1, $list);
        $this->assertSame('documents', $list[0]['type']);
        // A malformed legacy stamp renders '' — never the 01/01/1970 epoch.
        $this->assertSame('', $list[0]['completed_at']);
    }

    // ---- mergeQuestionnaireStage (F-D3: completion-flow intake answers) ----

    public function test_questionnaire_answers_synthesize_the_intake_stage_when_absent(): void
    {
        $tasks = [
            'questionnaire' => [
                'status' => 'completed',
                'completed_at' => '2026-05-01T10:00:00+00:00',
                'data' => ['answers' => ['Waarom volg je deze opleiding?' => 'Bijscholing', 'akkoord' => true]],
            ],
        ];

        $stages = $this->call('mergeQuestionnaireStage', [], $tasks);

        $this->assertArrayHasKey('intake', $stages);
        $this->assertSame('Bijscholing', $stages['intake']['data']['Waarom volg je deze opleiding?']);
        $this->assertSame('Ja', $stages['intake']['data']['akkoord']);
        $this->assertNotSame('', $stages['intake']['submitted_at']);
    }

    public function test_existing_enrollment_data_intake_always_wins(): void
    {
        $existing = ['intake' => ['submitted_at' => 'x', 'submitted_by' => '', 'data' => ['q' => 'origineel']]];
        $tasks = ['questionnaire' => ['data' => ['answers' => ['q' => 'overschreven?']]]];

        $stages = $this->call('mergeQuestionnaireStage', $existing, $tasks);

        $this->assertSame('origineel', $stages['intake']['data']['q']);
    }

    public function test_no_answers_leaves_stages_untouched(): void
    {
        $this->assertSame([], $this->call('mergeQuestionnaireStage', [], []));
        $this->assertSame([], $this->call('mergeQuestionnaireStage', [], ['questionnaire' => ['data' => ['answers' => []]]]));
    }

    // ---- initialSelectionStage (F-D11: readable capture log) ----

    public function test_initial_selection_renders_phase_rows_with_resolved_titles(): void
    {
        global $_test_users;
        $_test_users[7] = (object) ['ID' => 7, 'display_name' => 'Jan Peeters'];

        // Title 501 comes from the batched map (no get_post round-trip);
        // 999 is unknown everywhere → deleted marker.
        $stage = $this->call('initialSelectionStage', [
            'type' => 'session',
            'phases' => [
                ['phase' => 'enrollment', 'session_ids' => [501, 999], 'captured_at' => '2026-05-01T10:00:00+00:00', 'captured_by' => 7],
            ],
        ], [501 => 'Sessie ochtend']);

        $this->assertCount(1, $stage['data']);
        $label = array_key_first($stage['data']);
        $this->assertStringStartsWith('Bij inschrijving', $label);
        $this->assertStringContainsString('Sessie ochtend', $stage['data'][$label]);
        $this->assertStringContainsString('#999 (verwijderd)', $stage['data'][$label]);
        $this->assertSame('Jan Peeters', $stage['submitted_by']);
        $this->assertNotSame('', $stage['submitted_at']);
    }

    public function test_initial_selection_empty_phases_yield_empty_stage(): void
    {
        $stage = $this->call('initialSelectionStage', ['type' => 'session', 'phases' => []]);

        $this->assertSame([], $stage['data']);
        $this->assertSame('', $stage['submitted_at']);
    }

    // ---- presentHours + buildSessionRows (F-D13: honest hours, per-session) ----

    /** @return array<int,array{edition_id:int,title:string,date:string,start_time:string,end_time:string}> */
    private function sessionDetails(): array
    {
        return [
            11 => ['edition_id' => 5, 'title' => 'Dag 1', 'date' => '2026-03-02', 'start_time' => '10:00', 'end_time' => '12:30'],
            12 => ['edition_id' => 5, 'title' => 'Dag 2', 'date' => '2026-03-01', 'start_time' => '09:00', 'end_time' => '17:00'],
            13 => ['edition_id' => 5, 'title' => 'Dag 3', 'date' => '2026-03-03', 'start_time' => '', 'end_time' => ''],
            21 => ['edition_id' => 6, 'title' => 'Andere editie', 'date' => '2026-03-02', 'start_time' => '10:00', 'end_time' => '18:00'],
        ];
    }

    public function test_present_hours_sums_real_durations_of_present_sessions_only(): void
    {
        $statuses = [11 => 'present', 12 => 'absent', 13 => 'present', 21 => 'present'];

        // 11 present (2.5h) + 13 present but timeless (0) + 12 absent (skip)
        // + 21 present but belongs to ANOTHER edition (skip).
        $this->assertSame(2.5, $this->call('presentHours', 5, $statuses, $this->sessionDetails()));
        $this->assertSame(0.0, $this->call('presentHours', 5, [], $this->sessionDetails()));
    }

    public function test_session_rows_are_date_ordered_with_marks_and_unmarked_default(): void
    {
        $rows = $this->call('buildSessionRows', 5, [11 => 'present'], $this->sessionDetails());

        $this->assertSame(['Dag 2', 'Dag 1', 'Dag 3'], array_column($rows, 'title'));
        $this->assertSame('present', $rows[1]['status']);
        $this->assertSame('', $rows[0]['status']); // never marked → muted state
        $this->assertSame('10:00–12:30', $rows[1]['time']);
        $this->assertSame('', $rows[2]['time']);   // timeless session
        $this->assertArrayNotHasKey('_sort', $rows[0]);
    }

    // ---- formatting + actor resolution (F-D12) ----

    public function test_format_iso_moment_and_local_date(): void
    {
        $this->assertSame('01/05/2026 10:00', $this->call('formatIsoMoment', '2026-05-01T10:00:00+00:00'));
        $this->assertSame('', $this->call('formatIsoMoment', ''));
        $this->assertSame('02/03/2026', $this->call('formatLocalDate', '2026-03-02 14:00:00'));
        $this->assertSame('', $this->call('formatLocalDate', ''));
        $this->assertSame('', $this->call('formatLocalDate', '0000-00-00 00:00:00'));
    }

    public function test_resolve_user_name_resolves_caches_and_passes_legacy_strings(): void
    {
        global $_test_users;
        $_test_users[7] = (object) ['ID' => 7, 'display_name' => 'Jan Peeters'];

        $this->assertSame('Jan Peeters', $this->call('resolveUserName', 7));
        $this->assertSame('Jan Peeters', $this->call('resolveUserName', '7'));
        $this->assertSame('', $this->call('resolveUserName', 999));
        $this->assertSame('', $this->call('resolveUserName', null));
        $this->assertSame('Els', $this->call('resolveUserName', 'Els')); // legacy name string
    }

    public function test_normalize_stages_formats_moment_and_resolves_actor(): void
    {
        global $_test_users;
        $_test_users[7] = (object) ['ID' => 7, 'display_name' => 'Jan Peeters'];

        $stages = $this->call('normalizeEnrollmentStages', json_encode([
            'enrollment_personal' => [
                'submitted_at' => '2026-05-01T10:00:00+00:00',
                'submitted_by' => 7,
                'data' => ['first_name' => 'Imane'],
            ],
        ]));

        $this->assertSame('01/05/2026 10:00', $stages['enrollment_personal']['submitted_at']);
        $this->assertSame('Jan Peeters', $stages['enrollment_personal']['submitted_by']);
        $this->assertSame('Imane', $stages['enrollment_personal']['data']['first_name']);
    }
}
