<?php

declare(strict_types=1);

namespace Stride\Tests\Integration;

use IntegrationTestCase;
use Netdust\Mail\MailService;
use Stride\Modules\Enrollment\EnrollmentService;
use WP_Error;

/**
 * Task 5.3 — mail #1 ("je moet nog...") event binding.
 *
 * Tier A: this is a conditional-send guard (fires ONLY when the phase's
 * edition carries a gate deadline), which is exactly the erosion-guard
 * shape testing-workflow calls out — short conditional dispatch is still
 * Tier A, not "just wiring".
 *
 * ndmail_send() interception mirrors GateReminderServiceTest: rebind
 * MailService::class in the real DI container to a spy double via
 * ntdst_set() — the actual seam StrideMailBridge's handlers resolve
 * through via ndmail_send().
 *
 * Run: ddev exec "vendor/bin/phpunit -c phpunit-integration.xml.dist --filter GateTodoEventBindingTest"
 */
final class GateTodoEventBindingTest extends IntegrationTestCase
{
    private object $mailSpy;

    /** @var array<int> */
    private array $createdRegistrationIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->mailSpy = new class {
            /** @var array<int, array{slug: string, context: array, options: array}> */
            public array $calls = [];

            public function send(string $slug, array $context, array $options = []): bool|WP_Error
            {
                $this->calls[] = ['slug' => $slug, 'context' => $context, 'options' => $options];

                return true;
            }
        };

        ntdst_set(MailService::class, $this->mailSpy);
    }

    protected function tearDown(): void
    {
        ntdst_container()->set(MailService::class, MailService::class);

        foreach ($this->createdRegistrationIds as $regId) {
            $this->deleteTestRegistration($regId);
        }
        $this->createdRegistrationIds = [];

        parent::tearDown();
    }

    private function gateTodoCalls(): array
    {
        return array_values(array_filter(
            $this->mailSpy->calls,
            static fn(array $call) => $call['slug'] === 'stride-gate-todo',
        ));
    }

    /** @test */
    public function enrollingInAnEditionWithGateDeadlineSendsGateTodoMail(): void
    {
        $editionId = $this->createTestEdition(['meta' => [
            '_ntdst_gate_deadline' => '2099-01-01',
        ]]);

        $enrollmentService = ntdst_get(EnrollmentService::class);
        $registrationId = $enrollmentService->enroll(self::$testUserId, $editionId);

        $this->assertIsInt($registrationId, 'Fixture enrollment must succeed');
        $this->createdRegistrationIds[] = $registrationId;

        $gateTodoCalls = $this->gateTodoCalls();
        $this->assertCount(1, $gateTodoCalls, 'stride-gate-todo must fire once when the edition has a gate_deadline');
        $this->assertSame(self::$testUserId, $gateTodoCalls[0]['context']['user_id'] ?? null);
        $this->assertSame($editionId, $gateTodoCalls[0]['context']['edition_id'] ?? null);
    }

    /** @test */
    public function enrollingInAnEditionWithNoGateDeadlineSendsNoGateTodoMail(): void
    {
        $editionId = $this->createTestEdition(['meta' => [
            '_ntdst_gate_deadline' => '',
        ]]);

        $enrollmentService = ntdst_get(EnrollmentService::class);
        $registrationId = $enrollmentService->enroll(self::$testUserId, $editionId);

        $this->assertIsInt($registrationId, 'Fixture enrollment must succeed');
        $this->createdRegistrationIds[] = $registrationId;

        $this->assertCount(0, $this->gateTodoCalls(), 'No gate-todo mail may be sent for an edition with no gate_deadline');
    }

    /** @test */
    public function courseCompletionWithPostGateDeadlineSendsGateTodoMail(): void
    {
        $editionId = $this->createTestEdition(['meta' => [
            '_ntdst_post_gate_deadline' => '2099-01-01',
            '_ntdst_course_id' => 0,
        ]]);

        do_action('stride/completion/completed', [
            'edition_id' => $editionId,
            'user_id' => self::$testUserId,
            'course_id' => 0,
        ]);

        $gateTodoCalls = $this->gateTodoCalls();
        $this->assertCount(1, $gateTodoCalls, 'stride-gate-todo must fire on completion when the edition has a post_gate_deadline');
        $this->assertSame(self::$testUserId, $gateTodoCalls[0]['context']['user_id'] ?? null);
        $this->assertSame($editionId, $gateTodoCalls[0]['context']['edition_id'] ?? null);
    }

    /** @test */
    public function courseCompletionWithNoPostGateDeadlineSendsNoGateTodoMail(): void
    {
        $editionId = $this->createTestEdition(['meta' => [
            '_ntdst_post_gate_deadline' => '',
        ]]);

        do_action('stride/completion/completed', [
            'edition_id' => $editionId,
            'user_id' => self::$testUserId,
            'course_id' => 0,
        ]);

        $this->assertCount(0, $this->gateTodoCalls(), 'No gate-todo mail may be sent for an edition with no post_gate_deadline');
    }
}
