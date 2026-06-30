<?php

declare(strict_types=1);

namespace Stride\Tests\Integration;

use IntegrationTestCase;
use Netdust\Mail\MailService;
use Stride\Modules\Enrollment\EnrollmentService;
use Stride\Modules\Enrollment\RegistrationRepository;
use Stride\Modules\Mail\StrideMailBridge;

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

    /**
     * @test
     * (e) CR-GATE1: the standalone relink write (attachUserToWaitlistRow) FAILS
     * (e.g. $wpdb->update returns false on a SQL error) on a free-seat edition.
     * The promote MUST surface a relink_failed WP_Error and bail BEFORE the
     * capacity transaction — never proceed with the stale (user_id=0) row into
     * confirmCore, which would orphan-grant against user 0 and confirm an
     * account-less row.
     *
     * Pre-fix regression this catches: the code ignored the attach bool and did
     * an unguarded re-find(); on a relink failure it would have continued with
     * user_id=0 (orphan grant) or null-deref'd a concurrently-deleted row.
     */
    public function anonRowSurfacesRelinkFailureAndDoesNotConfirm(): void
    {
        $editionId = $this->createTestEdition(['meta' => ['_ntdst_capacity' => 5]]);

        $email = 'relinkfail_' . wp_generate_password(6, false) . '@lead.test';
        $regId = $this->seedAnonWaitlistRegistration($editionId, [
            'name' => 'Relink Fail',
            'email' => $email,
        ]);

        // Force ONLY the relink UPDATE (attachUserToWaitlistRow → $wpdb->update of
        // user_id on this row) to fail with a SQL error, so it returns false.
        // resolveLeadAccount's user creation and the capacity SELECTs use other
        // statements and stay intact: the account IS created, only the re-link
        // write fails. Rewrite to a guaranteed-error query for that one UPDATE.
        $table = $GLOBALS['wpdb']->prefix . 'vad_registrations';
        $filter = static function (string $query) use ($table): string {
            if (stripos($query, "UPDATE `{$table}`") !== false
                && stripos($query, 'SET `user_id`') !== false) {
                return "UPDATE `{$table}` SET `user_id` = (SELECT 1 FROM nonexistent_relink_table_xyz)";
            }

            return $query;
        };

        $confirmedEvents = 0;
        $listener = function (array $data) use (&$confirmedEvents, $regId): void {
            if ((int) ($data['registration_id'] ?? 0) === $regId) {
                $confirmedEvents++;
            }
        };
        add_action('stride/registration/confirmed', $listener);

        $suppressed = $GLOBALS['wpdb']->suppress_errors(true);
        add_filter('query', $filter);
        try {
            $result = $this->enrollmentService->promoteFromWaitlist($regId);
        } finally {
            remove_filter('query', $filter);
            $GLOBALS['wpdb']->suppress_errors($suppressed);
            remove_action('stride/registration/confirmed', $listener);
        }

        // The created account is real; track it for teardown regardless of branch.
        if (($u = get_user_by('email', $email)) !== false) {
            $this->createdUserIds[] = (int) $u->ID;
        }

        $this->assertTrue(is_wp_error($result), 'relink failure must surface as a WP_Error');
        $this->assertSame('relink_failed', $result->get_error_code());

        // No confirm fired — the bail happened before the transaction.
        $this->assertSame(0, $confirmedEvents, 'confirmed must NOT fire when the relink failed');

        // Row stays on the waitlist (its status was never flipped).
        $row = $this->registrations->find($regId);
        $this->assertNotNull($row, 'row still exists');
        $this->assertSame('waitlist', $row->status, 'row stays on the waitlist after a relink failure');
    }

    // === Task 4: confirmation-mail suppression on collision (M-NEW-USER-MAIL-ONLY) ===

    /** @var list<array<string,mixed>> Captured wp_mail $atts per send (this test). */
    private array $sentMails = [];

    /** @var callable|null The pre_wp_mail capture filter, removed in finally. */
    private $mailCapture = null;

    /**
     * Capture every wp_mail send (recording $atts) and short-circuit the real
     * transport. This is the un-mocked seam: promoteFromWaitlist → dispatch →
     * the REAL netdust-mail confirmed-trigger closure → wp_mail. We assert on
     * the recipient list that actually reaches wp_mail.
     */
    private function startMailCapture(): void
    {
        $this->sentMails = [];
        $this->mailCapture = function ($short, $atts) {
            // If an earlier filter (the production suppression guard at p10)
            // already short-circuited, this never runs — so a suppressed mail is
            // correctly NOT recorded. We register at p99 so the guard wins first.
            if ($short !== null) {
                return $short;
            }
            $this->sentMails[] = $atts;

            return true; // short-circuit the real transport (no actual send)
        };
        add_filter('pre_wp_mail', $this->mailCapture, 99, 2);
    }

    private function stopMailCapture(): void
    {
        if ($this->mailCapture !== null) {
            remove_filter('pre_wp_mail', $this->mailCapture, 99);
            $this->mailCapture = null;
        }
    }

    private function mailsTo(string $email): int
    {
        $count = 0;
        foreach ($this->sentMails as $atts) {
            $to = $atts['to'] ?? [];
            $recipients = is_array($to) ? $to : [$to];
            foreach ($recipients as $r) {
                if (strcasecmp(trim((string) $r), $email) === 0) {
                    $count++;
                }
            }
        }

        return $count;
    }

    /**
     * Ensure the REAL netdust-mail confirmed-trigger closure is live on
     * stride/registration/confirmed (it is registered at init priority 20 from
     * the seeded active template; on a fresh CI DB the template is seeded here).
     * Idempotent: only activates triggers if no netdust-mail closure is present,
     * so we never double-register (which would double-send).
     */
    private function ensureConfirmedMailTriggerLive(): void
    {
        ntdst_get(StrideMailBridge::class)->seedTemplates();

        global $wp_filter;
        $hook = $wp_filter['stride/registration/confirmed'] ?? null;
        $hasNdmailClosure = false;
        if ($hook) {
            foreach (($hook->callbacks[10] ?? []) as $cb) {
                $fn = $cb['function'];
                if ($fn instanceof \Closure) {
                    $ref = new \ReflectionFunction($fn);
                    if (str_contains((string) $ref->getFileName(), 'netdust-mail')) {
                        $hasNdmailClosure = true;
                        break;
                    }
                }
            }
        }

        if (!$hasNdmailClosure) {
            ntdst_get(MailService::class)->activateTriggers();
        }
    }

    /**
     * @test
     * Task 4 (a): a NEW-account anon promote sends EXACTLY ONE confirmation mail
     * to the newly-created user's email. The seeded confirmed-trigger already
     * covers this — we assert it still works (no over-suppression / no double).
     */
    public function newAccountPromoteSendsExactlyOneConfirmMailToNewUser(): void
    {
        $this->ensureConfirmedMailTriggerLive();

        $editionId = $this->createTestEdition(['meta' => ['_ntdst_capacity' => 5]]);
        $email = 'newacct_' . wp_generate_password(6, false) . '@lead.test';
        $regId = $this->seedAnonWaitlistRegistration($editionId, [
            'name' => 'Nieuwe Klant',
            'email' => $email,
        ]);

        $this->startMailCapture();
        try {
            $result = $this->enrollmentService->promoteFromWaitlist($regId);
        } finally {
            $this->stopMailCapture();
        }

        $this->assertTrue($result, 'new-account promote should succeed');

        $row = $this->registrations->find($regId);
        $this->createdUserIds[] = (int) $row->user_id;

        $this->assertSame(
            1,
            $this->mailsTo($email),
            'the new user must receive exactly one confirmation mail',
        );
    }

    /**
     * @test
     * Task 4 (b) — THE bug-catching denial case: a collision promote (anon email
     * matches a PRE-EXISTING account) must send ZERO confirmation mail to that
     * existing account (M-NEW-USER-MAIL-ONLY / attack 6). The row is still
     * confirmed + linked + granted — only the unsolicited mail is suppressed.
     */
    public function collisionPromoteSendsZeroConfirmMailToExistingAccount(): void
    {
        $this->ensureConfirmedMailTriggerLive();

        $editionId = $this->createTestEdition(['meta' => ['_ntdst_capacity' => 5]]);

        $email = 'existing_mail_' . wp_generate_password(6, false) . '@real.test';
        $existingUserId = wp_create_user('existing_mail_' . wp_generate_password(6, false), 'pw123456', $email);
        $this->assertIsInt($existingUserId);
        $this->createdUserIds[] = $existingUserId;
        update_user_meta($existingUserId, 'first_name', 'Echte');
        update_user_meta($existingUserId, 'last_name', 'Gebruiker');

        $regId = $this->seedAnonWaitlistRegistration($editionId, [
            'name' => 'Imposter',
            'email' => $email,
        ]);

        $this->startMailCapture();
        try {
            $result = $this->enrollmentService->promoteFromWaitlist($regId);
        } finally {
            $this->stopMailCapture();
        }

        $this->assertTrue($result, 'collision promote should still succeed (links to existing)');

        // Row is still confirmed + linked to the existing account.
        $row = $this->registrations->find($regId);
        $this->assertSame($existingUserId, (int) $row->user_id, 'row linked to existing account');
        $this->assertSame('confirmed', $row->status, 'row still confirmed');

        // The denial: ZERO confirmation mail to the pre-existing account.
        $this->assertSame(
            0,
            $this->mailsTo($email),
            'an unsolicited confirmation mail must NOT reach a pre-existing account',
        );
    }

    /**
     * @test
     * Task 4 (c) — the over-suppression guard: a NORMAL logged-in-user confirm
     * (the existing free-seat case, user_id already set, was_new_account=false
     * but NOT a collision) must STILL send its confirmation mail. Suppression is
     * scoped to the anon-promote collision, never to all was_new_account===false
     * confirms.
     */
    public function normalAccountedPromoteStillSendsConfirmMail(): void
    {
        $this->ensureConfirmedMailTriggerLive();

        $editionId = $this->createTestEdition(['meta' => ['_ntdst_capacity' => 5]]);
        // A waitlist row that ALREADY carries a real user_id (the logged-in case).
        $regId = $this->seedWaitlistRegistration($editionId);

        $user = get_userdata(self::$testUserId);
        $email = (string) $user->user_email;

        $this->startMailCapture();
        try {
            $result = $this->enrollmentService->promoteFromWaitlist($regId);
        } finally {
            $this->stopMailCapture();
        }

        $this->assertTrue($result, 'accounted promote should succeed');

        $this->assertSame(
            1,
            $this->mailsTo($email),
            'a normal accounted confirm must still send its confirmation mail (no over-suppression)',
        );
    }

    /** Count captured mails to $email whose subject starts with $prefix. */
    private function mailsToWithSubjectPrefix(string $email, string $prefix): int
    {
        $count = 0;
        foreach ($this->sentMails as $atts) {
            $to = $atts['to'] ?? [];
            $recipients = is_array($to) ? $to : [$to];
            $subject = (string) ($atts['subject'] ?? '');
            foreach ($recipients as $r) {
                if (strcasecmp(trim((string) $r), $email) === 0
                    && str_starts_with($subject, $prefix)) {
                    $count++;
                }
            }
        }

        return $count;
    }

    /**
     * @test
     * Task 4 (d) — SUBJECT-SCOPING over-suppression guard (test-effectiveness
     * blind spot, 2026-06-30): the collision suppression at pre_wp_mail matches
     * BOTH recipient AND the confirmation template's subject prefix
     * (StrideMailBridge::armConfirmMailSuppression, $matchesRecipient &&
     * $matchesSubject). The existing collision test only ever emits the single
     * confirm mail to the existing recipient, so it passes whether or not the
     * subject half is enforced — a regression to recipient-only matching would
     * NOT be caught.
     *
     * This test bites that: during a COLLISION promote, while the guard is armed
     * (prio 5) and BEFORE the netdust-mail confirm send (prio 10) self-disarms
     * it, we emit an UNRELATED wp_mail to the SAME existing recipient with a
     * DIFFERENT subject (a hook at prio 6). That non-confirm mail must SURVIVE —
     * only the confirmation subject is suppressed. If subject-scoping breaks
     * (recipient-only), this unrelated mail is wrongly swallowed and the assert
     * goes RED.
     */
    public function collisionSuppressionDoesNotSwallowOtherSubjectToSameRecipient(): void
    {
        $this->ensureConfirmedMailTriggerLive();

        $editionId = $this->createTestEdition(['meta' => ['_ntdst_capacity' => 5]]);

        $email = 'subjscope_' . wp_generate_password(6, false) . '@real.test';
        $existingUserId = wp_create_user('subjscope_' . wp_generate_password(6, false), 'pw123456', $email);
        $this->assertIsInt($existingUserId);
        $this->createdUserIds[] = $existingUserId;

        $regId = $this->seedAnonWaitlistRegistration($editionId, [
            'name' => 'Imposter',
            'email' => $email,
        ]);

        // While the guard is ARMED (prio 5) but BEFORE the confirm send disarms it
        // (the confirm send is prio 10; this fires at prio 6), emit an unrelated
        // mail to the SAME recipient with a clearly different subject. It must NOT
        // be suppressed — only the confirmation subject is in scope.
        $otherSubject = 'Offerte ontvangen - andere mail';
        $otherMail = function () use ($email, $otherSubject): void {
            wp_mail($email, $otherSubject, 'Body of an unrelated mail.');
        };
        add_action('stride/registration/confirmed', $otherMail, 6);

        $this->startMailCapture();
        try {
            $result = $this->enrollmentService->promoteFromWaitlist($regId);
        } finally {
            $this->stopMailCapture();
            remove_action('stride/registration/confirmed', $otherMail, 6);
        }

        $this->assertTrue($result, 'collision promote should still succeed');

        // The DENIAL the existing collision test already covers: zero confirm mail.
        $this->assertSame(
            0,
            $this->mailsToWithSubjectPrefix($email, 'Inschrijving bevestigd'),
            'the confirmation mail must still be suppressed for the collision account',
        );

        // The NEW assertion (subject-scoping): the unrelated, different-subject mail
        // to the same recipient SURVIVES — suppression is scoped to the confirm
        // subject, not to the recipient wholesale.
        $this->assertSame(
            1,
            $this->mailsToWithSubjectPrefix($email, $otherSubject),
            'an unrelated mail (different subject) to the same recipient must NOT be suppressed',
        );
    }
}
