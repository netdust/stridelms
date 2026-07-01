<?php

declare(strict_types=1);

namespace Stride\Tests\Integration;

use IntegrationTestCase;
use Netdust\Mail\MailService;
use Stride\Modules\Enrollment\RegistrationRepository;
use Stride\Modules\Reminder\GateReminderService;
use WP_Error;

/**
 * Integration tests for GateReminderService::run() (Task 4.3 — the keystone
 * cron that wires findWithActiveDeadline + reminder-state ledger +
 * GateReminderDueCalculator + EnrollmentCompletion + ndmail_send together).
 *
 * Tier A (spec: explicitly Tier-A RED-first) — this is the idempotency /
 * send-decision logic, the feature's most dangerous path: double-send,
 * missed catch-up, or a send with no state mark (infinite resend) are all
 * real user-facing bugs.
 *
 * ndmail_send() interception: ndmail_send() delegates to
 * ntdst_get(MailService::class)->send(...) — this test rebinds
 * MailService::class in the real DI container to a spy double via
 * ntdst_set(), which is the actual seam the production code resolves
 * through (not a global/monkeypatch). Restored in tearDown().
 *
 * "Today" injection: rather than stub current_time(), the fixture writes
 * registered_at directly via $wpdb, relative to the REAL current_time('Y-m-d')
 * (read once via a fresh calculator call), so the service's own
 * current_time('Y-m-d') call lines up with the fixture's math without any
 * WordPress date filter override.
 *
 * Run: ddev exec "vendor/bin/phpunit -c phpunit-integration.xml.dist --filter GateReminderService"
 */
final class GateReminderServiceTest extends IntegrationTestCase
{
    private RegistrationRepository $repo;
    private GateReminderService $service;
    private object $mailSpy;

    /** @var array<int> */
    private array $createdRegistrationIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->repo = ntdst_get(RegistrationRepository::class);
        $this->service = ntdst_get(GateReminderService::class);

        // Spy double registered under the REAL binding ndmail_send() resolves
        // through — this is the un-mocked chain: run() -> ndmail_send() ->
        // ntdst_get(MailService::class)->send(...) -> spy.
        $this->mailSpy = new class {
            /** @var array<int, array{slug: string, context: array, options: array}> */
            public array $calls = [];
            public mixed $nextResult = true;

            public function send(string $slug, array $context, array $options = []): bool|WP_Error
            {
                $this->calls[] = ['slug' => $slug, 'context' => $context, 'options' => $options];

                return $this->nextResult;
            }
        };

        ntdst_set(MailService::class, $this->mailSpy);
    }

    protected function tearDown(): void
    {
        // Un-register the spy so later tests/services re-autowire a real MailService.
        ntdst_container()->set(MailService::class, MailService::class);

        foreach ($this->createdRegistrationIds as $regId) {
            $this->deleteTestRegistration($regId);
        }
        $this->createdRegistrationIds = [];

        parent::tearDown();
    }

    // === Fixture helpers ===

    private function createReg(int $editionId): int
    {
        $regId = $this->repo->create([
            'user_id' => self::$testUserId,
            'edition_id' => $editionId,
            'status' => 'confirmed',
            'enrollment_path' => RegistrationRepository::PATH_INDIVIDUAL,
        ]);
        $this->assertIsInt($regId, 'Test fixture registration must be created');
        $this->createdRegistrationIds[] = $regId;

        return $regId;
    }

    private function setRegisteredAt(int $regId, string $mysqlDatetime): void
    {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'vad_registrations',
            ['registered_at' => $mysqlDatetime],
            ['id' => $regId],
        );
    }

    private function setCompletionTasks(int $regId, array $tasks): void
    {
        $this->repo->update($regId, ['completion_tasks' => $tasks]);
    }

    private function today(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(current_time('Y-m-d'));
    }

    // === Cases ===

    /** @test */
    public function sendsOnceWhenReminderIsDueToday(): void
    {
        $today = $this->today();
        $deadline = $today->modify('+20 days');
        $registeredAt = $today->modify('-7 days'); // reminderDays default fallback = 7

        $edition = $this->createTestEdition(['meta' => [
            '_ntdst_gate_deadline' => $deadline->format('Y-m-d'),
            '_ntdst_requires_questionnaire' => true,
        ]]);
        $regId = $this->createReg($edition);
        $this->setRegisteredAt($regId, $registeredAt->format('Y-m-d') . ' 00:00:00');
        $this->setCompletionTasks($regId, [
            'questionnaire' => ['status' => 'pending', 'phase' => 'enrollment'],
        ]);

        $this->service->run();

        $this->assertCount(1, $this->mailSpy->calls, 'Exactly one mail must be sent for the eligible registration');
        $this->assertSame('stride-gate-reminder', $this->mailSpy->calls[0]['slug']);

        $recipient = $this->mailSpy->calls[0]['options']['to'] ?? null;
        $this->assertNotEmpty($recipient, 'A recipient email must be passed via options.to');
        $this->assertSame(get_userdata(self::$testUserId)->user_email, $recipient);

        $state = $this->repo->getReminderState($regId);
        $this->assertNotNull($state['enroll']['reminder'] ?? null, 'reminder state must be marked after send');
    }

    /** @test */
    public function noResendWhenRunTwiceSameDay(): void
    {
        $today = $this->today();
        $deadline = $today->modify('+20 days');
        $registeredAt = $today->modify('-7 days');

        $edition = $this->createTestEdition(['meta' => [
            '_ntdst_gate_deadline' => $deadline->format('Y-m-d'),
            '_ntdst_requires_questionnaire' => true,
        ]]);
        $regId = $this->createReg($edition);
        $this->setRegisteredAt($regId, $registeredAt->format('Y-m-d') . ' 00:00:00');
        $this->setCompletionTasks($regId, [
            'questionnaire' => ['status' => 'pending', 'phase' => 'enrollment'],
        ]);

        $this->service->run();
        $this->service->run();

        $this->assertCount(1, $this->mailSpy->calls, 'Running the cron twice the same day must still send exactly once');
    }

    /** @test */
    public function catchUpAfterDowntimeSendsOnceNotOncePerMissedDay(): void
    {
        $today = $this->today();
        $deadline = $today->modify('+20 days');
        // Reminder date was 5 days ago (well past due) — simulates cron downtime.
        $registeredAt = $today->modify('-12 days'); // reminderDays=7 -> reminder due 5 days ago

        $edition = $this->createTestEdition(['meta' => [
            '_ntdst_gate_deadline' => $deadline->format('Y-m-d'),
            '_ntdst_requires_questionnaire' => true,
        ]]);
        $regId = $this->createReg($edition);
        $this->setRegisteredAt($regId, $registeredAt->format('Y-m-d') . ' 00:00:00');
        $this->setCompletionTasks($regId, [
            'questionnaire' => ['status' => 'pending', 'phase' => 'enrollment'],
        ]);

        $this->service->run();

        $this->assertCount(1, $this->mailSpy->calls, 'Catch-up after downtime must send exactly once, not once per missed day');
        $this->assertSame('stride-gate-reminder', $this->mailSpy->calls[0]['slug']);
    }

    /** @test */
    public function collisionOnDayBeforeDeadlineSendsOnlyDeadlineTomorrowSlug(): void
    {
        $today = $this->today();
        // Deadline is tomorrow -> today == deadline - 1 day (the collision point).
        $deadline = $today->modify('+1 day');
        // Registered such that reminderDate (registered + 7) collapses onto/after deadline.
        $registeredAt = $today->modify('-7 days');

        $edition = $this->createTestEdition(['meta' => [
            '_ntdst_gate_deadline' => $deadline->format('Y-m-d'),
            '_ntdst_requires_questionnaire' => true,
        ]]);
        $regId = $this->createReg($edition);
        $this->setRegisteredAt($regId, $registeredAt->format('Y-m-d') . ' 00:00:00');
        $this->setCompletionTasks($regId, [
            'questionnaire' => ['status' => 'pending', 'phase' => 'enrollment'],
        ]);

        $this->service->run();

        $this->assertCount(1, $this->mailSpy->calls, 'Day-before-deadline collision must send exactly one mail');
        $this->assertSame(
            'stride-gate-deadline-tomorrow',
            $this->mailSpy->calls[0]['slug'],
            'The day-before-deadline mail must win the collision, not the reminder',
        );
    }

    /** @test */
    public function skipsSendWhenPhaseTasksAlreadyCompleted(): void
    {
        $today = $this->today();
        $deadline = $today->modify('+20 days');
        $registeredAt = $today->modify('-7 days');

        $edition = $this->createTestEdition(['meta' => [
            '_ntdst_gate_deadline' => $deadline->format('Y-m-d'),
            '_ntdst_requires_questionnaire' => true,
        ]]);
        $regId = $this->createReg($edition);
        $this->setRegisteredAt($regId, $registeredAt->format('Y-m-d') . ' 00:00:00');
        $this->setCompletionTasks($regId, [
            'questionnaire' => ['status' => 'completed', 'phase' => 'enrollment'],
        ]);

        $this->service->run();

        $this->assertCount(0, $this->mailSpy->calls, 'No mail should be sent when the phase gate tasks are already completed');

        $state = $this->repo->getReminderState($regId);
        $this->assertArrayNotHasKey('enroll', $state, 'State must not be marked when nothing was sent');
    }

    /** @test */
    public function noSendWhenRecipientEmailIsInvalid(): void
    {
        $today = $this->today();
        $deadline = $today->modify('+20 days');
        $registeredAt = $today->modify('-7 days');

        $edition = $this->createTestEdition(['meta' => [
            '_ntdst_gate_deadline' => $deadline->format('Y-m-d'),
            '_ntdst_requires_questionnaire' => true,
        ]]);
        $regId = $this->createReg($edition);
        $this->setRegisteredAt($regId, $registeredAt->format('Y-m-d') . ' 00:00:00');
        $this->setCompletionTasks($regId, [
            'questionnaire' => ['status' => 'pending', 'phase' => 'enrollment'],
        ]);

        // Corrupt the recipient's email directly at the DB level (real invalid-email injection).
        global $wpdb;
        $originalEmail = get_userdata(self::$testUserId)->user_email;
        $wpdb->update($wpdb->users, ['user_email' => 'not-an-email'], ['ID' => self::$testUserId]);
        clean_user_cache(self::$testUserId);

        try {
            $this->service->run();

            $this->assertCount(0, $this->mailSpy->calls, 'No mail may be sent to an invalid email address');

            $state = $this->repo->getReminderState($regId);
            $this->assertArrayNotHasKey('enroll', $state, 'State must NOT be marked when the send was skipped for invalid email');
        } finally {
            $wpdb->update($wpdb->users, ['user_email' => $originalEmail], ['ID' => self::$testUserId]);
            clean_user_cache(self::$testUserId);
        }
    }

    /** @test */
    public function sendFailureIsLoggedAndStateNotMarkedSoItRetriesNextTick(): void
    {
        $today = $this->today();
        $deadline = $today->modify('+20 days');
        $registeredAt = $today->modify('-7 days');

        $edition = $this->createTestEdition(['meta' => [
            '_ntdst_gate_deadline' => $deadline->format('Y-m-d'),
            '_ntdst_requires_questionnaire' => true,
        ]]);
        $regId = $this->createReg($edition);
        $this->setRegisteredAt($regId, $registeredAt->format('Y-m-d') . ' 00:00:00');
        $this->setCompletionTasks($regId, [
            'questionnaire' => ['status' => 'pending', 'phase' => 'enrollment'],
        ]);

        $this->mailSpy->nextResult = new WP_Error('mail_failed', 'SMTP timeout');

        $this->service->run();

        $this->assertCount(1, $this->mailSpy->calls, 'ndmail_send must still be attempted once');

        $state = $this->repo->getReminderState($regId);
        $this->assertArrayNotHasKey('enroll', $state, 'State must NOT be marked when ndmail_send returns WP_Error (INV-4) — retries next tick');

        // Prove the retry: a second run (mail now "working") must send.
        $this->mailSpy->nextResult = true;
        $this->service->run();

        $this->assertCount(2, $this->mailSpy->calls, 'After a failed send, the next tick must retry (not skip forever)');

        $state = $this->repo->getReminderState($regId);
        $this->assertNotNull($state['enroll']['reminder'] ?? null, 'State must be marked once the retry succeeds');
    }
}
