<?php

declare(strict_types=1);

use Tests\Support\AcceptanceTester;

/**
 * Pre-enrollment flow acceptance tests — closes the gaps left open by
 * EnrollmentCest/EnrollmentEdgeCest (enrollment onward) and
 * CourseSidebarStatusCest (CTA display only):
 *
 *  1. The anonymous INTEREST form (/interesse/?editie=…) actually persists a
 *     registration row: status=interest, no user, stage-wrapped envelope in
 *     enrollment_data.
 *  2. The anonymous WAITLIST form (/wachtlijst/?editie=…) does the same with
 *     status=waitlist.
 *  3. One-row-per-email contract: interest then waitlist for the same email
 *     and edition UPDATES the existing anonymous row (status advances, both
 *     stage envelopes kept) instead of creating a second row.
 *  4. The server-side enrollment gate refuses stride_submit_enrollment for
 *     every non-open edition status (announcement / in_progress / cancelled /
 *     completed) — the UI hides the button, this proves a direct wire call
 *     can't bypass it. (EnrollmentEdgeCest already covers `full`.)
 *
 * Fixtures are self-created (no seed dependency). The fixture course gets no
 * stride_format term, so isClassroom() is false and the stored edition status
 * is also its effective status (no zero-sessions → Announcement override).
 */
class PreEnrollmentFlowCest
{
    private int $testCourseId;
    private int $testEditionId;
    private int $testUserId;
    private string $testUserEmail;
    private string $stamp;

    public function _before(AcceptanceTester $I): void
    {
        $this->stamp = time() . '_' . substr(md5((string) microtime(true)), 0, 4);

        $this->testCourseId = $I->havePostInDatabase([
            'post_type'   => 'sfwd-courses',
            'post_title'  => 'PreEnroll Course ' . $this->stamp,
            'post_status' => 'publish',
        ]);

        $this->testEditionId = $I->havePostInDatabase([
            'post_type'   => 'vad_edition',
            'post_title'  => 'PreEnroll Edition ' . $this->stamp,
            'post_name'   => 'preenroll-edition-' . $this->stamp,
            'post_status' => 'publish',
        ]);
        $I->havePostmetaInDatabase($this->testEditionId, '_ntdst_course_id', $this->testCourseId);
        $I->havePostmetaInDatabase($this->testEditionId, '_ntdst_price', 100);
        $I->havePostmetaInDatabase($this->testEditionId, '_ntdst_status', 'announcement');
        $I->havePostmetaInDatabase($this->testEditionId, '_ntdst_capacity', 20);
        $I->havePostmetaInDatabase($this->testEditionId, '_ntdst_start_date', date('Y-m-d', strtotime('+30 days')));

        $this->testUserEmail = 'preenroll_' . $this->stamp . '@test.local';
        $this->testUserId = $I->haveUserInDatabase('preenroll_' . $this->stamp, 'subscriber', [
            'user_email'   => $this->testUserEmail,
            'display_name' => 'PreEnroll Tester',
        ]);
        $I->haveUserMetaInDatabase($this->testUserId, 'first_name', 'PreEnroll');
        $I->haveUserMetaInDatabase($this->testUserId, 'last_name', 'Tester');
    }

    public function _after(AcceptanceTester $I): void
    {
        // Rows created by the application (not via have*) must be cleaned by hand.
        $I->dontHaveInDatabase($I->grabPrefixedTableNameFor('vad_registrations'), [
            'edition_id' => $this->testEditionId,
        ]);
    }

    private function setEditionStatus(AcceptanceTester $I, string $status): void
    {
        $I->updateInDatabase(
            $I->grabPrefixedTableNameFor('postmeta'),
            ['meta_value' => $status],
            ['post_id' => $this->testEditionId, 'meta_key' => '_ntdst_status'],
        );
    }

    /**
     * Fill + submit the anonymous interest/waitlist form and wait for the
     * Alpine success state.
     *
     * @param string $kind 'interest' or 'waitlist' (page slug + field-id prefix)
     */
    private function submitAnonymousForm(AcceptanceTester $I, string $kind, string $name, string $email): void
    {
        $page = $kind === 'interest' ? '/interesse/' : '/wachtlijst/';
        $I->amOnPage($page . '?editie=' . $this->testEditionId);
        $I->waitForElement('#' . $kind . '_name', 10);

        $I->fillField('#' . $kind . '_name', $name);
        $I->fillField('#' . $kind . '_email', $email);
        // The waitlist form carries a full billing section, so its submit
        // button sits below the fold — a plain click is intercepted (the point
        // is off-screen). Scroll it into view first so the click lands.
        $I->scrollTo('button[type="submit"]');
        $I->click('button[type="submit"]');

        $expected = $kind === 'interest'
            ? 'Je interesse is geregistreerd'
            : 'Je staat op de wachtlijst';
        $I->waitForText($expected, 10);
    }

    /**
     * @return array{0: array<string,mixed>, 1: array<string,mixed>} [row-ish fields, decoded enrollment_data]
     */
    private function grabAnonymousRegistration(AcceptanceTester $I): array
    {
        $table = $I->grabPrefixedTableNameFor('vad_registrations');

        $status = (string) $I->grabFromDatabase($table, 'status', ['edition_id' => $this->testEditionId]);
        $userId = $I->grabFromDatabase($table, 'user_id', ['edition_id' => $this->testEditionId]);
        $raw    = (string) $I->grabFromDatabase($table, 'enrollment_data', ['edition_id' => $this->testEditionId]);

        $data = json_decode($raw, true);
        \PHPUnit\Framework\Assert::assertIsArray($data, 'enrollment_data must be valid JSON, got: ' . substr($raw, 0, 200));

        return [['status' => $status, 'user_id' => $userId], $data];
    }

    /**
     * Assert Mailpit received a mail to $to whose subject contains $subjectFragment.
     *
     * Mail leaves through wp_mail → Mailpit (DDEV), so this proves the whole
     * chain: form → handler → stride/registration/* action → StrideMailBridge
     * → ndmail template render → SMTP. Polls briefly — delivery is async-ish.
     */
    private function assertMailpitReceived(string $to, string $subjectFragment): void
    {
        $found = null;
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $response = @file_get_contents('http://127.0.0.1:8025/api/v1/search?query=' . urlencode('to:"' . $to . '"'));
            $payload = $response ? json_decode($response, true) : null;
            foreach ($payload['messages'] ?? [] as $message) {
                if (str_contains((string) ($message['Subject'] ?? ''), $subjectFragment)) {
                    $found = $message;
                    break 2;
                }
            }
            usleep(500_000);
        }

        \PHPUnit\Framework\Assert::assertNotNull(
            $found,
            "Mailpit must have a mail to {$to} with subject containing '{$subjectFragment}'"
        );
    }

    private function assertAuditRecorded(AcceptanceTester $I, string $action): void
    {
        $registrationId = (int) $I->grabFromDatabase($I->grabPrefixedTableNameFor('vad_registrations'), 'id', [
            'edition_id' => $this->testEditionId,
        ]);
        $I->seeInDatabase($I->grabPrefixedTableNameFor('audit_log'), [
            'entity_type' => 'registration',
            'entity_id'   => $registrationId,
            'action'      => $action,
        ]);
    }

    private function assertStageEnvelope(array $data, string $stage, string $name, string $email): void
    {
        \PHPUnit\Framework\Assert::assertArrayHasKey($stage, $data, "enrollment_data must contain the '{$stage}' stage");
        $envelope = $data[$stage];
        \PHPUnit\Framework\Assert::assertArrayHasKey('submitted_at', $envelope, "{$stage}.submitted_at missing");
        \PHPUnit\Framework\Assert::assertArrayHasKey('data', $envelope, "{$stage}.data missing");
        \PHPUnit\Framework\Assert::assertSame($name, $envelope['data']['name'] ?? null, "{$stage}.data.name mismatch");
        \PHPUnit\Framework\Assert::assertSame($email, $envelope['data']['email'] ?? null, "{$stage}.data.email mismatch");
        // Anonymous submission → submitted_by is null.
        \PHPUnit\Framework\Assert::assertNull($envelope['submitted_by'] ?? null, "{$stage}.submitted_by must be null for anonymous submissions");
    }

    // =========================================================================
    // 1. Interest form → DB row
    // =========================================================================

    /**
     * @test
     */
    public function interestFormCreatesAnonymousRegistration(AcceptanceTester $I): void
    {
        $I->wantTo('submit the anonymous interest form and find a status=interest row with the stage envelope');

        $email = 'interest_' . $this->stamp . '@test.local';
        $this->submitAnonymousForm($I, 'interest', 'Interest Tester', $email);

        [$row, $data] = $this->grabAnonymousRegistration($I);
        \PHPUnit\Framework\Assert::assertSame('interest', $row['status'], 'registration status must be interest');
        \PHPUnit\Framework\Assert::assertEmpty($row['user_id'], 'anonymous interest must not be linked to a user');
        $this->assertStageEnvelope($data, 'interest', 'Interest Tester', $email);

        // The semantic event must have fanned out to its consumers:
        // StrideMailBridge (confirmation mail) and AuditBridge (audit row).
        $this->assertMailpitReceived($email, 'Bevestiging interesse');
        $this->assertAuditRecorded($I, 'registration.interest_registered');
    }

    // =========================================================================
    // 2. Waitlist form → DB row
    // =========================================================================

    /**
     * @test
     */
    public function waitlistFormCreatesAnonymousRegistration(AcceptanceTester $I): void
    {
        $I->wantTo('submit the anonymous waitlist form and find a status=waitlist row with the stage envelope');

        $this->setEditionStatus($I, 'full');

        $email = 'waitlist_' . $this->stamp . '@test.local';
        $this->submitAnonymousForm($I, 'waitlist', 'Waitlist Tester', $email);

        [$row, $data] = $this->grabAnonymousRegistration($I);
        \PHPUnit\Framework\Assert::assertSame('waitlist', $row['status'], 'registration status must be waitlist');
        \PHPUnit\Framework\Assert::assertEmpty($row['user_id'], 'anonymous waitlist must not be linked to a user');
        $this->assertStageEnvelope($data, 'waitlist', 'Waitlist Tester', $email);

        // Event fan-out: confirmation mail + audit row (see interest test).
        $this->assertMailpitReceived($email, 'Bevestiging wachtlijst');
        $this->assertAuditRecorded($I, 'registration.waitlisted');
    }

    // =========================================================================
    // 3. One row per email: interest → waitlist updates, never duplicates
    // =========================================================================

    /**
     * @test
     */
    public function interestThenWaitlistReusesTheSameAnonymousRow(AcceptanceTester $I): void
    {
        $I->wantTo('verify interest followed by waitlist for the same email updates one row with both stages');

        $email = 'stages_' . $this->stamp . '@test.local';

        $this->submitAnonymousForm($I, 'interest', 'Stages Tester', $email);

        // Edition fills up → same person joins the waitlist.
        $this->setEditionStatus($I, 'full');
        $this->submitAnonymousForm($I, 'waitlist', 'Stages Tester', $email);

        $count = (int) $I->grabNumRecords($I->grabPrefixedTableNameFor('vad_registrations'), [
            'edition_id' => $this->testEditionId,
        ]);
        \PHPUnit\Framework\Assert::assertSame(1, $count, 'interest + waitlist for one email must share one registration row');

        [$row, $data] = $this->grabAnonymousRegistration($I);
        \PHPUnit\Framework\Assert::assertSame('waitlist', $row['status'], 'status must advance to waitlist');
        $this->assertStageEnvelope($data, 'interest', 'Stages Tester', $email);
        $this->assertStageEnvelope($data, 'waitlist', 'Stages Tester', $email);
    }

    // =========================================================================
    // 4. Server-side enrollment gate for non-open statuses
    // =========================================================================

    /**
     * @test
     */
    public function serverRefusesEnrollmentForEveryNonOpenStatus(AcceptanceTester $I): void
    {
        $I->wantTo('verify a direct enrollment call is refused for every non-open edition status');

        // Load any theme page once so ntdstAPI + auth cookie are available;
        // the wire call itself doesn't depend on the visited page.
        $I->loginAsUserId($this->testUserId, '/');
        $I->waitForElement('body', 10);

        foreach (['announcement', 'in_progress', 'cancelled', 'completed'] as $status) {
            $this->setEditionStatus($I, $status);

            $I->executeJS("
                window.__gateResult = null;
                ntdstAPI.call('stride_submit_enrollment', {
                    item_type: 'edition',
                    edition_id: {$this->testEditionId},
                    enrollment_type: 'zelf',
                    first_name: 'PreEnroll',
                    last_name: 'Tester',
                    email: '{$this->testUserEmail}',
                    phone: '+31612345678',
                    terms_accepted: true,
                }).then(r => window.__gateResult = { ok: true })
                  .catch(e => window.__gateResult = { error: e.message || 'refused' });
            ");
            $I->waitForJS('return window.__gateResult !== null;', 10);

            $refused = (bool) $I->executeJS('return !!(window.__gateResult && window.__gateResult.error);');
            \PHPUnit\Framework\Assert::assertTrue($refused, "status '{$status}' must refuse direct enrollment");

            $I->dontSeeInDatabase($I->grabPrefixedTableNameFor('vad_registrations'), [
                'user_id'    => $this->testUserId,
                'edition_id' => $this->testEditionId,
            ]);
        }
    }
}
