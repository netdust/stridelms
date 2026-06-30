<?php

declare(strict_types=1);

namespace Stride\Tests\Integration;

use IntegrationTestCase;
use Stride\Modules\Enrollment\EnrollmentService;
use Stride\Modules\Enrollment\RegistrationRepository;

/**
 * Integration tests for EnrollmentService::promoteFromWaitlist (Task 2.2, Decision 1).
 *
 * Promote shares the SAME grant + event semantics as a normal confirm: one
 * grant path, one stride/registration/confirmed event. The capacity re-check
 * uses the race-safe FOR UPDATE path, so this needs the real DB.
 *
 * Run: ddev exec vendor/bin/phpunit -c phpunit-integration.xml.dist --filter PromoteFromWaitlist
 */
class PromoteFromWaitlistTest extends IntegrationTestCase
{
    private EnrollmentService $enrollmentService;
    private RegistrationRepository $registrations;
    private array $testRegistrationIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->enrollmentService = ntdst_get(EnrollmentService::class);
        $this->registrations = ntdst_get(RegistrationRepository::class);
        $this->actingAs(self::$testUserId);
    }

    protected function tearDown(): void
    {
        foreach ($this->testRegistrationIds as $regId) {
            if ($regId) {
                $this->deleteTestRegistration($regId);
            }
        }
        $this->testRegistrationIds = [];

        if (!empty($this->createdUserIds)) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
            foreach (array_unique($this->createdUserIds) as $uid) {
                if ($uid && $uid !== self::$testUserId) {
                    wp_delete_user($uid);
                }
            }
        }
        $this->createdUserIds = [];

        parent::tearDown();
    }

    /** @var int[] Users created by the anon-promote branch, deleted in tearDown. */
    private array $createdUserIds = [];

    /**
     * @return array{0:int,1:int} [registrationId, confirmedEventCount-by-ref-not-used]
     */
    private function seedWaitlistRegistration(int $editionId): int
    {
        $regId = $this->registrations->create([
            'user_id' => self::$testUserId,
            'edition_id' => $editionId,
            'status' => 'waitlist',
            'enrollment_path' => RegistrationRepository::PATH_INDIVIDUAL,
        ]);
        $this->assertIsInt($regId);
        $this->testRegistrationIds[] = $regId;

        return $regId;
    }

    /**
     * Seed an ANONYMOUS (user_id=0) waitlist row carrying the upfront-captured
     * name/email + billing fields under enrollment_data.waitlist.data.* — the
     * exact path handleSubmitWaitlist/wrapStage writes (mirrors F0 storage).
     *
     * @param array<string,mixed> $data captured field set (input-key-named)
     */
    private function seedAnonWaitlistRegistration(int $editionId, array $data): int
    {
        $regId = $this->registrations->create([
            'user_id' => 0,
            'edition_id' => $editionId,
            'status' => 'waitlist',
            'enrollment_path' => RegistrationRepository::PATH_INDIVIDUAL,
            // submitted_by null = anonymous public-form submission
            'enrollment_data' => [
                'waitlist' => RegistrationRepository::wrapStage($data, null),
            ],
        ]);
        $this->assertIsInt($regId);
        $this->testRegistrationIds[] = $regId;

        return $regId;
    }

    private function countUsersByEmail(string $email): int
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_email = %s",
            $email,
        ));
    }

    /**
     * @test
     * Waitlist -> Confirmed on an edition with a free seat: grants access and
     * fires stride/registration/confirmed exactly once (same as a normal confirm).
     */
    public function promotesWaitlistRowOnEditionWithFreeSeat(): void
    {
        $editionId = $this->createTestEdition(['meta' => ['_ntdst_capacity' => 5]]);
        $regId = $this->seedWaitlistRegistration($editionId);

        $confirmedEvents = 0;
        $listener = function (array $data) use (&$confirmedEvents, $regId): void {
            if ((int) ($data['registration_id'] ?? 0) === $regId) {
                $confirmedEvents++;
            }
        };
        add_action('stride/registration/confirmed', $listener);

        $result = $this->enrollmentService->promoteFromWaitlist($regId);

        remove_action('stride/registration/confirmed', $listener);

        $this->assertTrue($result, 'promoteFromWaitlist should return true on success');
        $this->assertTrue(
            $this->enrollmentService->isEnrolled(self::$testUserId, $editionId),
            'Row should now be confirmed (enrolled)',
        );
        $this->assertSame(1, $confirmedEvents, 'stride/registration/confirmed must fire exactly once');
    }

    /**
     * @test
     * A full edition is skipped: per-row capacity re-check returns capacity_full
     * and the row stays on the waitlist (no grant, no event).
     */
    public function rejectsPromoteWhenEditionIsFull(): void
    {
        $editionId = $this->createTestEdition(['meta' => ['_ntdst_capacity' => 1]]);

        // Fill the single seat with a confirmed registration.
        $confirmedId = $this->registrations->create([
            'user_id' => self::$testUserId + 1,
            'edition_id' => $editionId,
            'status' => 'confirmed',
            'enrollment_path' => RegistrationRepository::PATH_INDIVIDUAL,
        ]);
        $this->testRegistrationIds[] = $confirmedId;

        $regId = $this->seedWaitlistRegistration($editionId);

        $result = $this->enrollmentService->promoteFromWaitlist($regId);

        $this->assertTrue(is_wp_error($result));
        $this->assertSame('capacity_full', $result->get_error_code());

        // Untouched: still waitlist.
        $row = $this->registrations->find($regId);
        $this->assertSame('waitlist', $row->status);
    }

    /**
     * @test
     * A non-waitlist row (e.g. confirmed) is rejected with invalid_status.
     */
    public function rejectsPromoteOnNonWaitlistRow(): void
    {
        $editionId = $this->createTestEdition(['meta' => ['_ntdst_capacity' => 5]]);
        $regId = $this->registrations->create([
            'user_id' => self::$testUserId,
            'edition_id' => $editionId,
            'status' => 'confirmed',
            'enrollment_path' => RegistrationRepository::PATH_INDIVIDUAL,
        ]);
        $this->testRegistrationIds[] = $regId;

        $result = $this->enrollmentService->promoteFromWaitlist($regId);

        $this->assertTrue(is_wp_error($result));
        $this->assertSame('invalid_status', $result->get_error_code());
    }

    /**
     * @test
     * A terminal edition (cancelled) rejects promote with edition_closed (INV-7).
     */
    public function rejectsPromoteOnTerminalEdition(): void
    {
        $editionId = $this->createTestEdition([
            'meta' => ['_ntdst_status' => 'cancelled', '_ntdst_capacity' => 5],
        ]);
        $regId = $this->seedWaitlistRegistration($editionId);

        $result = $this->enrollmentService->promoteFromWaitlist($regId);

        $this->assertTrue(is_wp_error($result));
        $this->assertSame('edition_closed', $result->get_error_code());
    }

    // === Anonymous-lead promote (account resolution + billing-meta map) ===

    /**
     * @test
     * (a) M-ROUNDTRIP: an anonymous (user_id=0) waitlist row carrying the
     * upfront-captured input-key billing fields, on a free-seat edition,
     * creates a NEW active account, maps the captured billing data onto that
     * user's usermeta (billing_company/billing_vat/invoice_email), re-links the
     * row's user_id, confirms it, and fires registration/confirmed once with a
     * NON-ZERO user_id.
     */
    public function promotesAnonRowCreatesUserMapsBillingMetaAndConfirms(): void
    {
        $editionId = $this->createTestEdition(['meta' => ['_ntdst_capacity' => 5]]);

        $email = 'anon_lead_' . wp_generate_password(6, false) . '@lead.test';
        $regId = $this->seedAnonWaitlistRegistration($editionId, [
            'name' => 'Jan Janssens',
            'email' => $email,
            'company' => 'Acme NV',
            'vat_number' => 'BE0123456789',
            'invoice_email' => 'facturen@acme.test',
            'organisation' => 'Acme Org',
        ]);

        $confirmedUserId = null;
        $confirmedEvents = 0;
        $listener = function (array $data) use (&$confirmedEvents, &$confirmedUserId, $regId): void {
            if ((int) ($data['registration_id'] ?? 0) === $regId) {
                $confirmedEvents++;
                $confirmedUserId = (int) ($data['user_id'] ?? 0);
            }
        };
        add_action('stride/registration/confirmed', $listener);

        $result = $this->enrollmentService->promoteFromWaitlist($regId);

        remove_action('stride/registration/confirmed', $listener);

        $this->assertTrue($result, 'anon promote should return true on a free seat');

        $row = $this->registrations->find($regId);
        $this->assertNotNull($row);
        $newUserId = (int) $row->user_id;
        $this->createdUserIds[] = $newUserId;

        $this->assertGreaterThan(0, $newUserId, 'row should now carry a real user_id');
        $this->assertSame('confirmed', $row->status, 'row should be confirmed');

        // M-ROUNDTRIP: captured input-keys mapped onto the new user's billing_* meta.
        $this->assertUserMeta($newUserId, 'billing_company', 'Acme NV');
        $this->assertUserMeta($newUserId, 'billing_vat', 'BE0123456789');
        $this->assertUserMeta($newUserId, 'invoice_email', 'facturen@acme.test');
        $this->assertUserMeta($newUserId, 'organisation', 'Acme Org');

        $this->assertSame(1, $confirmedEvents, 'confirmed must fire exactly once');
        $this->assertSame($newUserId, $confirmedUserId, 'confirmed must carry the resolved non-zero user_id');
    }

    /**
     * @test
     * (b) M-NO-OVERWRITE / attack 10: the lead's email matches an EXISTING user
     * who already has DIFFERENT billing_vat/invoice_email usermeta. Promote
     * links the row to that existing ID, creates NO second user, and leaves the
     * existing user's billing meta UNTOUCHED (no billing-meta poisoning).
     */
    public function collisionLinksToExistingUserWithoutOverwritingBillingMeta(): void
    {
        $editionId = $this->createTestEdition(['meta' => ['_ntdst_capacity' => 5]]);

        $email = 'existing_' . wp_generate_password(6, false) . '@real.test';
        $existingUserId = wp_create_user('existing_' . wp_generate_password(6, false), 'pw123456', $email);
        $this->assertIsInt($existingUserId);
        $this->createdUserIds[] = $existingUserId;

        // The real user's OWN billing details — must survive untouched.
        update_user_meta($existingUserId, 'billing_vat', 'BE9999999999');
        update_user_meta($existingUserId, 'invoice_email', 'real@real.test');

        // Anon lead reuses that email but submits DIFFERENT (attacker) billing data.
        $regId = $this->seedAnonWaitlistRegistration($editionId, [
            'name' => 'Imposter',
            'email' => $email,
            'vat_number' => 'BE0000000001',
            'invoice_email' => 'attacker@evil.test',
        ]);

        $usersBefore = $this->countUsersByEmail($email);

        $result = $this->enrollmentService->promoteFromWaitlist($regId);

        $this->assertTrue($result, 'collision promote should still succeed (links to existing)');

        $row = $this->registrations->find($regId);
        $this->assertSame($existingUserId, (int) $row->user_id, 'row linked to the existing account');

        // No second user for that email.
        $this->assertSame($usersBefore, $this->countUsersByEmail($email), 'no duplicate user created');

        // M-NO-OVERWRITE: existing user's billing meta is UNCHANGED.
        $this->assertUserMeta($existingUserId, 'billing_vat', 'BE9999999999', 'existing VAT must not be overwritten');
        $this->assertUserMeta($existingUserId, 'invoice_email', 'real@real.test', 'existing invoice_email must not be overwritten');
    }

    /**
     * @test
     * (c) M-EMAIL-VALIDATE: an anon row whose captured email is empty fails with
     * lead_no_email; the row stays on the waitlist and no user is created.
     */
    public function anonRowWithMissingEmailFailsAndStaysWaitlist(): void
    {
        $editionId = $this->createTestEdition(['meta' => ['_ntdst_capacity' => 5]]);
        $regId = $this->seedAnonWaitlistRegistration($editionId, [
            'name' => 'No Email',
            'email' => '',
            'company' => 'Nobody BV',
        ]);

        $result = $this->enrollmentService->promoteFromWaitlist($regId);

        $this->assertTrue(is_wp_error($result));
        $this->assertSame('lead_no_email', $result->get_error_code());

        $row = $this->registrations->find($regId);
        $this->assertSame('waitlist', $row->status, 'row stays on the waitlist');
        $this->assertSame(0, (int) $row->user_id, 'no user linked');
    }

    /**
     * @test
     * (d) M-SEQUENCE half-state: an anon row on a FULL edition is rejected with
     * capacity_full AFTER the account is resolved (account step precedes the
     * transaction). The row stays waitlist but now carries the created user_id
     * (benign-idempotent); a retry creates NO duplicate user.
     */
    public function anonRowOnFullEditionRejectsButRetryCreatesNoDuplicateUser(): void
    {
        $editionId = $this->createTestEdition(['meta' => ['_ntdst_capacity' => 1]]);

        // Fill the single seat.
        $confirmedId = $this->registrations->create([
            'user_id' => self::$testUserId + 1,
            'edition_id' => $editionId,
            'status' => 'confirmed',
            'enrollment_path' => RegistrationRepository::PATH_INDIVIDUAL,
        ]);
        $this->testRegistrationIds[] = $confirmedId;

        $email = 'fulledition_' . wp_generate_password(6, false) . '@lead.test';
        $regId = $this->seedAnonWaitlistRegistration($editionId, [
            'name' => 'Late Lead',
            'email' => $email,
        ]);

        $result = $this->enrollmentService->promoteFromWaitlist($regId);

        $this->assertTrue(is_wp_error($result));
        $this->assertSame('capacity_full', $result->get_error_code());

        $row = $this->registrations->find($regId);
        $this->assertSame('waitlist', $row->status, 'row stays on the waitlist');
        $linkedUserId = (int) $row->user_id;
        $this->assertGreaterThan(0, $linkedUserId, 'account was resolved before the transaction (benign half-state)');
        $this->createdUserIds[] = $linkedUserId;

        $usersAfterFirst = $this->countUsersByEmail($email);
        $this->assertSame(1, $usersAfterFirst, 'exactly one user created');

        // Retry: still full, must NOT create a second user (M-IDEMPOTENT — resolve
        // is gated on user_id===0, now non-zero).
        $retry = $this->enrollmentService->promoteFromWaitlist($regId);
        $this->assertTrue(is_wp_error($retry));
        $this->assertSame('capacity_full', $retry->get_error_code());
        $this->assertSame($usersAfterFirst, $this->countUsersByEmail($email), 'retry creates no duplicate user');
    }
}
