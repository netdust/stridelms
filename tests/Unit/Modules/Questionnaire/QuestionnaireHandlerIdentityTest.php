<?php

declare(strict_types=1);

namespace Stride\Tests\Unit\Modules\Questionnaire;

use Stride\Modules\Enrollment\RegistrationRepository;
use Stride\Modules\Questionnaire\QuestionnaireHandler;
use Stride\Modules\Questionnaire\QuestionnaireValidator;
use Stride\Tests\TestCase;

/**
 * Form-identity matrix gates for the public interest/waitlist forms
 * (plan 2026-07-14, SAFER VARIANT — Stefan's decision):
 *
 *  - a visitor's submission stays a LEAD (user_id NULL);
 *  - a logged-in submitter using their OWN e-mail is BOUND to their account
 *    (adopting an earlier lead row for that e-mail+edition first, so one
 *    person never holds two rows);
 *  - a logged-in submitter using ANOTHER e-mail stays a lead — NO
 *    get_user_by() binding of arbitrary e-mails at submission (a stranger
 *    must never write into a member's account);
 *  - an already-enrolled self-submission is a friendly error (own state —
 *    no info leak about other accounts);
 *  - the SUCCESS RESPONSE is identical for lead and bound submissions
 *    (threat 1 — no account-enumeration signal).
 */
final class QuestionnaireHandlerIdentityTest extends TestCase
{
    private QuestionnaireHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $validator = $this->createMock(QuestionnaireValidator::class);
        $validator->method('validate')->willReturn(true);
        ntdst_set(QuestionnaireValidator::class, $validator);

        $this->handler = new QuestionnaireHandler();
    }

    private function loginAs(int $id, string $email): void
    {
        global $_test_current_user_id, $_test_users;
        $_test_current_user_id = $id;
        $user = new \WP_User();
        $user->ID = $id;
        $user->user_email = $email;
        $_test_users[$id] = $user;
    }

    private function logout(): void
    {
        global $_test_current_user_id;
        $_test_current_user_id = 0;
    }

    private function interestParams(string $email): array
    {
        return [
            'edition_id' => 42,
            'name' => 'Anna Peeters',
            'email' => $email,
        ];
    }

    public function test_visitor_submission_stays_a_lead(): void
    {
        $this->logout();

        $repo = $this->createMock(RegistrationRepository::class);
        $repo->method('findAnonymousForEmailAndEdition')->willReturn(null);
        $repo->expects($this->never())->method('findByUserAndEdition');
        $repo->expects($this->never())->method('bindLeadToUser');
        $repo->expects($this->once())->method('create')
            ->with($this->callback(fn(array $p): bool => $p['user_id'] === null && $p['edition_id'] === 42))
            ->willReturn(101);
        ntdst_set(RegistrationRepository::class, $repo);

        $result = $this->handler->handleSubmitInterest(null, $this->interestParams('lead@example.test'));

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
    }

    public function test_logged_in_own_email_binds_to_the_account(): void
    {
        $this->loginAs(7, 'anna@example.test');

        $repo = $this->createMock(RegistrationRepository::class);
        $repo->method('findByUserAndEdition')->with(7, 42)->willReturn(null);
        $repo->method('findAnonymousForEmailAndEdition')->willReturn(null);
        $repo->expects($this->once())->method('create')
            ->with($this->callback(fn(array $p): bool => $p['user_id'] === 7))
            ->willReturn(102);
        ntdst_set(RegistrationRepository::class, $repo);

        // Case-insensitive: the e-mail is theirs even typed differently.
        $result = $this->handler->handleSubmitInterest(null, $this->interestParams('Anna@Example.TEST'));

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
    }

    public function test_self_bind_adopts_an_earlier_lead_row_instead_of_creating_a_second(): void
    {
        $this->loginAs(7, 'anna@example.test');

        $lead = (object) ['id' => 55, 'status' => 'interest', 'enrollment_data' => []];

        $repo = $this->createMock(RegistrationRepository::class);
        $repo->method('findByUserAndEdition')->willReturn(null);
        $repo->method('findAnonymousForEmailAndEdition')->willReturn($lead);
        $repo->expects($this->once())->method('bindLeadToUser')->with(55, 7)->willReturn(true);
        $repo->method('find')->with(55)->willReturn($lead);
        $repo->expects($this->once())->method('update')
            ->with(55, $this->callback(fn(array $u): bool => $u['status'] === 'interest' && isset($u['enrollment_data']['interest'])))
            ->willReturn(true);
        $repo->expects($this->never())->method('create');
        ntdst_set(RegistrationRepository::class, $repo);

        $result = $this->handler->handleSubmitInterest(null, $this->interestParams('anna@example.test'));

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
    }

    public function test_already_enrolled_self_submission_is_a_friendly_error(): void
    {
        $this->loginAs(7, 'anna@example.test');

        $confirmed = (object) ['id' => 60, 'status' => 'confirmed', 'enrollment_data' => []];

        $repo = $this->createMock(RegistrationRepository::class);
        $repo->method('findByUserAndEdition')->willReturn($confirmed);
        $repo->expects($this->never())->method('update');
        $repo->expects($this->never())->method('create');
        ntdst_set(RegistrationRepository::class, $repo);

        $result = $this->handler->handleSubmitInterest(null, $this->interestParams('anna@example.test'));

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('already_registered', $result->get_error_code());
    }

    public function test_logged_in_with_someone_elses_email_stays_a_lead(): void
    {
        // Rule 4 (on-behalf via the e-mail field) + safer variant: NO binding
        // to whatever account that e-mail may belong to.
        $this->loginAs(7, 'anna@example.test');

        $repo = $this->createMock(RegistrationRepository::class);
        $repo->expects($this->never())->method('findByUserAndEdition');
        $repo->expects($this->never())->method('bindLeadToUser');
        $repo->method('findAnonymousForEmailAndEdition')->willReturn(null);
        $repo->expects($this->once())->method('create')
            ->with($this->callback(fn(array $p): bool => $p['user_id'] === null))
            ->willReturn(103);
        ntdst_set(RegistrationRepository::class, $repo);

        $result = $this->handler->handleSubmitInterest(null, $this->interestParams('collega@example.test'));

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
    }

    public function test_interest_submission_never_downgrades_a_waitlist_row(): void
    {
        // A waitlist row is promotion-eligible — a stronger claim than
        // interest. The interest form appends its data but must keep the
        // waitlist status, or the user silently drops out of every
        // promotion query.
        $this->loginAs(7, 'anna@example.test');

        $waitlistRow = (object) ['id' => 70, 'status' => 'waitlist', 'enrollment_data' => [], 'company_id' => 5];

        $repo = $this->createMock(RegistrationRepository::class);
        $repo->method('findByUserAndEdition')->willReturn($waitlistRow);
        $repo->expects($this->once())->method('update')
            ->with(70, $this->callback(
                fn(array $u): bool => $u['status'] === 'waitlist'      // NOT downgraded
                    && isset($u['enrollment_data']['interest'])         // data still appended
                    && !array_key_exists('cancelled_at', $u),           // not a reactivation
            ))
            ->willReturn(true);
        ntdst_set(RegistrationRepository::class, $repo);

        $result = $this->handler->handleSubmitInterest(null, $this->interestParams('anna@example.test'));

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
    }

    public function test_reactivating_a_cancelled_row_clears_the_cancellation_stamp(): void
    {
        // Mirrors create()'s reactivate branch: a live interest row must not
        // carry a stale cancelled_at (it renders a cancellation date in the
        // dossier/exports, and updateStatus only stamps cancelled_at when
        // empty — a later cancellation would keep the OLD timestamp forever).
        $this->loginAs(7, 'anna@example.test');

        $cancelled = (object) ['id' => 71, 'status' => 'cancelled', 'enrollment_data' => [], 'company_id' => null];

        $repo = $this->createMock(RegistrationRepository::class);
        $repo->method('findByUserAndEdition')->willReturn($cancelled);
        $repo->expects($this->once())->method('update')
            ->with(71, $this->callback(
                fn(array $u): bool => $u['status'] === 'interest'
                    && array_key_exists('cancelled_at', $u) && $u['cancelled_at'] === null,
            ))
            ->willReturn(true);
        ntdst_set(RegistrationRepository::class, $repo);

        $result = $this->handler->handleSubmitInterest(null, $this->interestParams('anna@example.test'));

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
    }

    public function test_success_response_is_identical_for_lead_and_bound_submissions(): void
    {
        // Threat 1: nothing in the response may signal whether an e-mail has
        // an account (enumeration). Compare the two full response arrays.
        $repoLead = $this->createMock(RegistrationRepository::class);
        $repoLead->method('findAnonymousForEmailAndEdition')->willReturn(null);
        $repoLead->method('create')->willReturn(201);

        $this->logout();
        ntdst_set(RegistrationRepository::class, $repoLead);
        $leadResponse = $this->handler->handleSubmitInterest(null, $this->interestParams('x@example.test'));

        $repoBound = $this->createMock(RegistrationRepository::class);
        $repoBound->method('findByUserAndEdition')->willReturn(null);
        $repoBound->method('findAnonymousForEmailAndEdition')->willReturn(null);
        $repoBound->method('create')->willReturn(202);

        $this->loginAs(9, 'y@example.test');
        ntdst_set(RegistrationRepository::class, $repoBound);
        $boundResponse = $this->handler->handleSubmitInterest(null, $this->interestParams('y@example.test'));

        $this->assertSame($leadResponse, $boundResponse);
    }
}
