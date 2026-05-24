<?php

declare(strict_types=1);

namespace Stride\Tests\Unit\Modules\Enrollment;

use Stride\Modules\Enrollment\RegistrationRepository;
use Stride\Tests\TestCase;

final class RegistrationRepositoryNormalizeTest extends TestCase
{
    public function testWrapStageBuildsThreeKeyEnvelope(): void
    {
        $result = RegistrationRepository::wrapStage(['name' => 'Jan'], 42, '2026-05-24T12:00:00+00:00');

        $this->assertSame([
            'submitted_at' => '2026-05-24T12:00:00+00:00',
            'submitted_by' => 42,
            'data' => ['name' => 'Jan'],
        ], $result);
    }

    public function testWrapStageDefaultsSubmittedAtToNow(): void
    {
        $result = RegistrationRepository::wrapStage(['name' => 'Jan'], 42);

        $this->assertArrayHasKey('submitted_at', $result);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', $result['submitted_at']);
        $this->assertSame(42, $result['submitted_by']);
    }

    public function testWrapStageAcceptsNullSubmittedBy(): void
    {
        // Simulate anonymous context (no logged-in user) so auto-resolve yields null.
        global $_test_current_user_id;
        $prev = $_test_current_user_id;
        $_test_current_user_id = 0;

        $result = RegistrationRepository::wrapStage(['email' => 'a@b.c'], null, '2026-05-24T12:00:00+00:00');

        $_test_current_user_id = $prev;

        $this->assertNull($result['submitted_by']);
        $this->assertSame(['email' => 'a@b.c'], $result['data']);
    }

    public function testWrapStageEmptyDataIsAllowed(): void
    {
        $result = RegistrationRepository::wrapStage([], 42, '2026-05-24T12:00:00+00:00');

        $this->assertSame([], $result['data']);
    }

    public function testWrapStageOmittedSubmittedByResolvesToNullWhenAnonymous(): void
    {
        // No user logged in in the unit test context — current id = 0 — should map to null.
        global $_test_current_user_id;
        $prev = $_test_current_user_id;
        $_test_current_user_id = 0;

        $result = RegistrationRepository::wrapStage(['name' => 'Jan'], null, '2026-05-24T12:00:00+00:00');

        $_test_current_user_id = $prev;

        $this->assertNull($result['submitted_by']);
    }

    public function testNormalizeDropsUnknownRootKeys(): void
    {
        $input = [
            'interest' => RegistrationRepository::wrapStage(['name' => 'Jan'], null, '2026-05-24T12:00:00+00:00'),
            'random_key' => ['something' => 'else'],
            'profession' => 'doctor',
        ];

        $result = RegistrationRepository::normalizeEnrollmentData($input);

        $this->assertArrayHasKey('interest', $result);
        $this->assertArrayNotHasKey('random_key', $result);
        $this->assertArrayNotHasKey('profession', $result);
    }

    public function testNormalizePassesWellFormedStageThrough(): void
    {
        $stage = RegistrationRepository::wrapStage(['name' => 'Jan'], 42, '2026-05-24T12:00:00+00:00');
        $input = ['enrollment_personal' => $stage];

        $result = RegistrationRepository::normalizeEnrollmentData($input);

        $this->assertSame($stage, $result['enrollment_personal']);
    }

    public function testNormalizeFillsMissingMetaOnStage(): void
    {
        $input = ['interest' => ['data' => ['name' => 'Jan']]]; // missing submitted_at / submitted_by

        $result = RegistrationRepository::normalizeEnrollmentData($input);

        $this->assertArrayHasKey('submitted_at', $result['interest']);
        $this->assertArrayHasKey('submitted_by', $result['interest']);
        $this->assertNull($result['interest']['submitted_by']);
        $this->assertSame(['name' => 'Jan'], $result['interest']['data']);
    }

    public function testNormalizeDropsUnknownKeysInsideStage(): void
    {
        $input = [
            'interest' => [
                'submitted_at' => '2026-05-24T12:00:00+00:00',
                'submitted_by' => null,
                'data' => ['name' => 'Jan'],
                'rogue_key' => 'should be dropped',
            ],
        ];

        $result = RegistrationRepository::normalizeEnrollmentData($input);

        $this->assertArrayNotHasKey('rogue_key', $result['interest']);
        $this->assertSame(['name' => 'Jan'], $result['interest']['data']);
    }

    public function testNormalizePassesInitialSelectionThrough(): void
    {
        $initial = [
            'type' => 'edition',
            'phases' => [
                [
                    'phase' => 'enrollment',
                    'captured_at' => '2026-05-24T12:00:00+00:00',
                    'captured_by' => 42,
                    'session_ids' => [1, 2, 3],
                ],
            ],
        ];
        $input = ['initial_selection' => $initial];

        $result = RegistrationRepository::normalizeEnrollmentData($input);

        $this->assertSame($initial, $result['initial_selection']);
    }

    public function testNormalizeHandlesNonArrayStageValue(): void
    {
        // Defensive: scalar value at a stage key shouldn't crash; should be dropped.
        $input = ['interest' => 'oops'];

        $result = RegistrationRepository::normalizeEnrollmentData($input);

        $this->assertArrayNotHasKey('interest', $result);
    }
}
