<?php

declare(strict_types=1);

namespace Stride\Tests\Integration;

use IntegrationTestCase;
use Stride\Modules\Enrollment\EnrollmentService;

/**
 * Task 5.3 — mail #1 ("je moet nog...") event binding.
 *
 * Tier A: this is a conditional-send guard (fires ONLY when the phase's
 * edition carries a gate deadline), which is exactly the erosion-guard
 * shape testing-workflow calls out — short conditional dispatch is still
 * Tier A, not "just wiring".
 *
 * CRITICAL (review finding, 2026-07-01): the original version of this test
 * swapped MailService::class in the DI container for a spy via ntdst_set().
 * That is BLIND to netdust-mail's activateTriggers() auto-trigger path:
 * activateTriggers() runs on `init` priority 20 and its add_action() closure
 * captures the REAL MailService instance (`$this`) at bind time — long
 * before any test's ntdst_set() call. So when the `stride-gate-todo`
 * template itself carried a non-empty `trigger` (the bug), the auto-trigger
 * called the REAL send() directly, bypassing the spy entirely, while the
 * explicit onRegistrationCreatedGateTodoMail handler's ndmail_send() (which
 * resolves MailService::class fresh from the container on each call) WAS
 * seen by the spy. Net effect: the spy only ever saw 1 call (the explicit
 * handler), so "no-deadline -> 0" and "with-deadline -> 1" both passed green
 * while the auto-trigger silently double-sent / unconditionally sent in the
 * background.
 *
 * Fix: intercept the REAL, unbypassable send path instead — `pre_wp_mail`,
 * which every send (auto-trigger OR explicit handler) funnels through via
 * MailService::send() -> ntdst_mail()->send() -> wp_mail(). This is the same
 * pattern MailServiceIntegrationTest already uses. We identify gate-todo
 * sends by the template's unique rendered subject prefix ("Nog te doen: ").
 *
 * Run: ddev exec "vendor/bin/phpunit -c phpunit-integration.xml.dist --filter GateTodoEventBindingTest"
 */
final class GateTodoEventBindingTest extends IntegrationTestCase
{
    /** @var array<int, array{to: mixed, subject: string}> */
    private array $sentMails = [];

    private $preWpMailFilter;

    /** @var array<int> */
    private array $createdRegistrationIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->sentMails = [];
        $this->preWpMailFilter = function ($null, $atts) {
            $this->sentMails[] = ['to' => $atts['to'] ?? null, 'subject' => $atts['subject'] ?? ''];

            return true; // Short-circuit: prevent an actual send attempt.
        };
        add_filter('pre_wp_mail', $this->preWpMailFilter, 10, 2);
    }

    protected function tearDown(): void
    {
        remove_filter('pre_wp_mail', $this->preWpMailFilter, 10);

        foreach ($this->createdRegistrationIds as $regId) {
            $this->deleteTestRegistration($regId);
        }
        $this->createdRegistrationIds = [];

        parent::tearDown();
    }

    /**
     * @return array<int, array{to: mixed, subject: string}>
     */
    private function gateTodoCalls(): array
    {
        return array_values(array_filter(
            $this->sentMails,
            static fn(array $call) => str_starts_with($call['subject'], 'Nog te doen: '),
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
        $this->assertCount(1, $gateTodoCalls, 'stride-gate-todo must fire exactly once when the edition has a gate_deadline (not zero, not twice)');
        $recipient = is_array($gateTodoCalls[0]['to']) ? $gateTodoCalls[0]['to'][0] : $gateTodoCalls[0]['to'];
        $this->assertSame(get_userdata(self::$testUserId)->user_email, $recipient);
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
        $this->assertCount(1, $gateTodoCalls, 'stride-gate-todo must fire exactly once on completion when the edition has a post_gate_deadline');
        $recipient = is_array($gateTodoCalls[0]['to']) ? $gateTodoCalls[0]['to'][0] : $gateTodoCalls[0]['to'];
        $this->assertSame(get_userdata(self::$testUserId)->user_email, $recipient);
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
