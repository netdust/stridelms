<?php

declare(strict_types=1);

use Tests\Support\AcceptanceTester;

/**
 * Enrollment edge-class acceptance tests (hardening sprint Phase 3).
 *
 * Matrix: docs/architecture/acceptance-flows/p0-hardening-phase3.md (F1-F3).
 * Drives the edges the happy-path EnrollmentCest leaves open: server-side
 * validation, double-submit, re-entry, capacity boundary, the colleague
 * PII guard (C3 regression), and voucher denial states.
 */
class EnrollmentEdgeCest
{
    private int $testEditionId;
    private int $testCourseId;
    private int $testUserId;
    private string $testUserEmail;

    public function _before(AcceptanceTester $I): void
    {
        $timestamp = time() . '_' . substr(md5((string) microtime(true)), 0, 4);

        $this->testCourseId = $I->havePostInDatabase([
            'post_type' => 'sfwd-courses',
            'post_title' => 'Edge Course ' . $timestamp,
            'post_status' => 'publish',
        ]);

        $this->testEditionId = $I->havePostInDatabase([
            'post_type' => 'vad_edition',
            'post_title' => 'Edge Edition ' . $timestamp,
            'post_name' => 'edge-edition-' . $timestamp,
            'post_status' => 'publish',
        ]);
        $I->havePostmetaInDatabase($this->testEditionId, '_ntdst_course_id', $this->testCourseId);
        $I->havePostmetaInDatabase($this->testEditionId, '_ntdst_price', 100);
        $I->havePostmetaInDatabase($this->testEditionId, '_ntdst_status', 'open');
        $I->havePostmetaInDatabase($this->testEditionId, '_ntdst_capacity', 20);
        $I->havePostmetaInDatabase($this->testEditionId, '_ntdst_start_date', date('Y-m-d', strtotime('+30 days')));

        $this->testUserEmail = 'edge_' . $timestamp . '@test.local';
        $this->testUserId = $I->haveUserInDatabase('edge_' . $timestamp, 'subscriber', [
            'user_email' => $this->testUserEmail,
            'display_name' => 'Edge Tester',
        ]);
        $I->haveUserMetaInDatabase($this->testUserId, 'first_name', 'Edge');
        $I->haveUserMetaInDatabase($this->testUserId, 'last_name', 'Tester');
    }

    private function enrollmentUrl(int $editionId = 0): string
    {
        return '/edities/' . ($editionId ?: $this->testEditionId) . '/inschrijving/';
    }

    /**
     * Fill the Alpine wizard with a valid self-enrollment and jump to confirm.
     */
    private function fillValidForm(AcceptanceTester $I, string $type = 'zelf'): void
    {
        $I->executeJS("
            const el = document.querySelector('[x-data^=\"enrollmentForm\"]');
            const comp = Alpine.\$data(el);
            comp.form.enrollment_type = '{$type}';
            comp.form.first_name = 'Edge';
            comp.form.last_name = 'Tester';
            comp.form.email = '{$this->testUserEmail}';
            comp.form.phone = '+31612345678';
            comp.form.terms_accepted = true;
            comp.stepIndex = 3;
        ");
        $I->wait(1);
    }

    // =========================================================================
    // F1 — empty/zero: server refuses empty required fields
    // =========================================================================

    /**
     * @test
     */
    public function serverRefusesSubmissionWithEmptyRequiredFields(AcceptanceTester $I): void
    {
        $I->wantTo('verify the server refuses an enrollment with empty required fields');

        $I->loginAsUserId($this->testUserId, $this->enrollmentUrl());
        $I->waitForElement('form', 10);

        // Bypass HTML5 validation on purpose — drive submitForm() directly
        // with the required personal fields blanked. The SERVER must refuse.
        $I->executeJS("
            const el = document.querySelector('[x-data^=\"enrollmentForm\"]');
            const comp = Alpine.\$data(el);
            comp.form.enrollment_type = 'zelf';
            comp.form.first_name = '';
            comp.form.last_name = '';
            comp.form.email = '';
            comp.form.phone = '';
            comp.form.terms_accepted = true;
            comp.stepIndex = 3;
            comp.submitForm();
        ");

        $I->waitForJS(
            "const c = Alpine.\$data(document.querySelector('[x-data^=\"enrollmentForm\"]')); return c.submitError.length > 0;",
            10
        );

        $error = (string) $I->executeJS(
            "return Alpine.\$data(document.querySelector('[x-data^=\"enrollmentForm\"]')).submitError;"
        );
        $I->comment('Server error: ' . $error);

        $I->dontSeeInDatabase($I->grabPrefixedTableNameFor('vad_registrations'), [
            'user_id' => $this->testUserId,
            'edition_id' => $this->testEditionId,
        ]);
    }

    // =========================================================================
    // F1 — concurrent/double + wrong-order/re-entry
    // =========================================================================

    /**
     * @test
     *
     * Two near-simultaneous submitForm() calls (the component has no
     * in-flight guard) followed by a deliberate re-submit after success.
     * The server-side duplicate gate must hold: exactly ONE registration.
     */
    public function doubleAndRepeatSubmitYieldExactlyOneRegistration(AcceptanceTester $I): void
    {
        $I->wantTo('verify double-submit and re-submit create exactly one registration');

        $I->loginAsUserId($this->testUserId, $this->enrollmentUrl());
        $I->waitForElement('form', 10);
        $this->fillValidForm($I);

        // Near-simultaneous double submit (no await between calls).
        $I->executeJS("
            const comp = Alpine.\$data(document.querySelector('[x-data^=\"enrollmentForm\"]'));
            comp.submitForm();
            comp.submitForm();
        ");
        $I->wait(6);

        $count = (int) $I->grabNumRecords($I->grabPrefixedTableNameFor('vad_registrations'), [
            'user_id' => $this->testUserId,
            'edition_id' => $this->testEditionId,
        ]);
        \PHPUnit\Framework\Assert::assertSame(
            1,
            $count,
            "double-submit must yield exactly one registration, got {$count}"
        );

        // Re-entry: revisit the enrollment page (success may have redirected
        // away) and, when the wizard is still offered, submit once more.
        $I->amOnPage($this->enrollmentUrl());
        $I->waitForElement('body', 10);

        $wizardPresent = (bool) $I->executeJS(
            "return !!document.querySelector('[x-data^=\"enrollmentForm\"]');"
        );
        $I->comment('Enrollment page after success still renders wizard: ' . ($wizardPresent ? 'YES' : 'no'));

        if ($wizardPresent) {
            $this->fillValidForm($I);
            $I->executeJS("
                const comp = Alpine.\$data(document.querySelector('[x-data^=\"enrollmentForm\"]'));
                comp.submitForm();
            ");
            $I->wait(4);
        }

        $count = (int) $I->grabNumRecords($I->grabPrefixedTableNameFor('vad_registrations'), [
            'user_id' => $this->testUserId,
            'edition_id' => $this->testEditionId,
        ]);
        \PHPUnit\Framework\Assert::assertSame(
            1,
            $count,
            "re-entry must not create a second registration, got {$count}"
        );
    }

    // =========================================================================
    // F1 — boundary: capacity full
    // =========================================================================

    /**
     * @test
     */
    public function capacityFullEditionRefusesEnrollment(AcceptanceTester $I): void
    {
        $I->wantTo('verify a full edition refuses enrollment server-side');

        // Make the edition full: capacity 1, one confirmed registration.
        $I->updateInDatabase(
            $I->grabPrefixedTableNameFor('postmeta'),
            ['meta_value' => 1],
            ['post_id' => $this->testEditionId, 'meta_key' => '_ntdst_capacity']
        );
        $occupant = $I->haveUserInDatabase('edge_occupant_' . time(), 'subscriber', [
            'user_email' => 'edge_occupant_' . time() . '@test.local',
        ]);
        $I->haveInDatabase($I->grabPrefixedTableNameFor('vad_registrations'), [
            'user_id' => $occupant,
            'edition_id' => $this->testEditionId,
            'status' => 'confirmed',
            'enrollment_path' => 'individual',
            'registered_at' => date('Y-m-d H:i:s'),
        ]);

        $I->loginAsUserId($this->testUserId, $this->enrollmentUrl());
        $I->waitForElement('body', 10);

        // The page must not offer the normal enrollment wizard for a full
        // edition. Whatever it renders instead (waitlist / volzet notice),
        // the server is the real gate — drive it through the wire below.
        $pageOffersForm = (bool) $I->executeJS(
            "return document.body.innerText.includes('Voor wie is deze inschrijving');"
        );
        $I->comment('Full edition still shows wizard: ' . ($pageOffersForm ? 'YES' : 'no'));

        // Wire-level: a direct enrollment call must be refused.
        $I->executeJS("
            window.__edgeResult = null;
            ntdstAPI.call('stride_submit_enrollment', {
                item_type: 'edition',
                edition_id: {$this->testEditionId},
                enrollment_type: 'zelf',
                first_name: 'Edge',
                last_name: 'Tester',
                email: '{$this->testUserEmail}',
                phone: '+31612345678',
                terms_accepted: true,
            }).then(r => window.__edgeResult = { ok: true })
              .catch(e => window.__edgeResult = { error: e.message || 'refused' });
        ");
        $I->waitForJS('return window.__edgeResult !== null;', 10);

        $refused = (bool) $I->executeJS('return !!(window.__edgeResult && window.__edgeResult.error);');
        \PHPUnit\Framework\Assert::assertTrue($refused, 'full edition must refuse direct enrollment call');

        $I->dontSeeInDatabase($I->grabPrefixedTableNameFor('vad_registrations'), [
            'user_id' => $this->testUserId,
            'edition_id' => $this->testEditionId,
        ]);
    }

    // =========================================================================
    // F2 — colleague PII guard (C3 regression through the real wire)
    // =========================================================================

    /**
     * @test
     */
    public function colleagueEnrollmentLeavesExistingProfileUntouched(AcceptanceTester $I): void
    {
        $I->wantTo('verify enrolling an existing user as colleague cannot overwrite their profile');

        $stamp = time();
        $victimEmail = 'edge_victim_' . $stamp . '@test.local';
        $victimId = $I->haveUserInDatabase('edge_victim_' . $stamp, 'subscriber', [
            'user_email' => $victimEmail,
            'display_name' => 'Victim User',
        ]);
        $I->haveUserMetaInDatabase($victimId, 'first_name', 'Vera');
        $I->haveUserMetaInDatabase($victimId, 'last_name', 'Victim');
        $I->haveUserMetaInDatabase($victimId, 'organisation', 'Baseline Org');
        $I->haveUserMetaInDatabase($victimId, 'phone', '+32400000000');

        $I->loginAsUserId($this->testUserId, $this->enrollmentUrl());
        $I->waitForElement('form', 10);

        // Enroll the EXISTING victim as colleague with attacker-chosen values.
        $I->executeJS("
            const comp = Alpine.\$data(document.querySelector('[x-data^=\"enrollmentForm\"]'));
            comp.form.enrollment_type = 'collega';
            comp.form.first_name = 'Evil';
            comp.form.last_name = 'Overwrite';
            comp.form.email = '{$victimEmail}';
            comp.form.phone = '+31666666666';
            comp.form.organisation = 'EVIL Corp';
            comp.form.terms_accepted = true;
            comp.stepIndex = 3;
            comp.submitForm();
        ");
        $I->wait(6);

        // Registration exists for the victim (colleague path)…
        $I->seeInDatabase($I->grabPrefixedTableNameFor('vad_registrations'), [
            'user_id' => $victimId,
            'edition_id' => $this->testEditionId,
        ]);

        // …but the victim's profile is untouched.
        $I->seeInDatabase($I->grabPrefixedTableNameFor('usermeta'), [
            'user_id' => $victimId,
            'meta_key' => 'organisation',
            'meta_value' => 'Baseline Org',
        ]);
        $I->seeInDatabase($I->grabPrefixedTableNameFor('usermeta'), [
            'user_id' => $victimId,
            'meta_key' => 'phone',
            'meta_value' => '+32400000000',
        ]);
        $I->seeInDatabase($I->grabPrefixedTableNameFor('usermeta'), [
            'user_id' => $victimId,
            'meta_key' => 'first_name',
            'meta_value' => 'Vera',
        ]);
    }

    /**
     * @test
     */
    public function colleagueWithEmptyEmailIsRefused(AcceptanceTester $I): void
    {
        $I->wantTo('verify colleague enrollment without an email is refused');

        $I->loginAsUserId($this->testUserId, $this->enrollmentUrl());
        $I->waitForElement('form', 10);

        $I->executeJS("
            const comp = Alpine.\$data(document.querySelector('[x-data^=\"enrollmentForm\"]'));
            comp.form.enrollment_type = 'collega';
            comp.form.first_name = 'No';
            comp.form.last_name = 'Email';
            comp.form.email = '';
            comp.form.phone = '+31612345678';
            comp.form.terms_accepted = true;
            comp.stepIndex = 3;
            comp.submitForm();
        ");

        $I->waitForJS(
            "return Alpine.\$data(document.querySelector('[x-data^=\"enrollmentForm\"]')).submitError.length > 0;",
            10
        );

        $count = (int) $I->grabNumRecords($I->grabPrefixedTableNameFor('vad_registrations'), [
            'edition_id' => $this->testEditionId,
        ]);
        \PHPUnit\Framework\Assert::assertSame(0, $count, 'no registration may exist after refused colleague submit');
    }

    // =========================================================================
    // F3 — voucher denial states (driven through validateVoucher on the form)
    // =========================================================================

    private function makeVoucher(AcceptanceTester $I, string $code, array $meta = []): int
    {
        $voucherId = $I->havePostInDatabase([
            'post_type' => 'vad_voucher',
            'post_title' => $code,
            'post_status' => 'publish',
        ]);
        $defaults = [
            '_ntdst_code' => $code,
            '_ntdst_discount_type' => 'percentage',
            '_ntdst_discount_value' => 20,
            '_ntdst_status' => 'active',
            '_ntdst_usage_limit' => 10,
            '_ntdst_used_count' => 0,
        ];
        foreach (array_merge($defaults, $meta) as $key => $value) {
            $I->havePostmetaInDatabase($voucherId, $key, $value);
        }

        return $voucherId;
    }

    /**
     * Apply a voucher code on the loaded enrollment form and return the
     * component's error string ('' when the voucher validated).
     */
    private function applyVoucher(AcceptanceTester $I, string $code): string
    {
        $I->executeJS("
            const comp = Alpine.\$data(document.querySelector('[x-data^=\"enrollmentForm\"]'));
            comp.voucherError = '';
            comp.voucherValid = false;
            comp.form.voucher_code = '{$code}';
            comp.validateVoucher();
        ");
        $I->waitForJS(
            "const c = Alpine.\$data(document.querySelector('[x-data^=\"enrollmentForm\"]')); return c.voucherError.length > 0 || c.voucherValid;",
            10
        );

        return (string) $I->executeJS(
            "return Alpine.\$data(document.querySelector('[x-data^=\"enrollmentForm\"]')).voucherError;"
        );
    }

    /**
     * @test
     */
    public function voucherDenialStatesRenderTheRightErrors(AcceptanceTester $I): void
    {
        $I->wantTo('verify unknown, expired, exhausted and wrong-scope vouchers are refused with clear errors');

        $stamp = strtoupper(substr(md5((string) microtime(true)), 0, 6));

        $this->makeVoucher($I, 'EDGE_EXPIRED_' . $stamp, [
            '_ntdst_valid_until' => date('Y-m-d', strtotime('-2 days')),
        ]);
        $this->makeVoucher($I, 'EDGE_USEDUP_' . $stamp, [
            '_ntdst_usage_limit' => 1,
            '_ntdst_used_count' => 1,
        ]);
        $otherEdition = $I->havePostInDatabase([
            'post_type' => 'vad_edition',
            'post_title' => 'Other Edition ' . $stamp,
            'post_status' => 'publish',
        ]);
        $this->makeVoucher($I, 'EDGE_SCOPED_' . $stamp, [
            '_ntdst_scope_mode' => 'only',
            '_ntdst_edition_id' => $otherEdition,
        ]);
        $this->makeVoucher($I, 'EDGE_VALID_' . $stamp);

        $I->loginAsUserId($this->testUserId, $this->enrollmentUrl());
        $I->waitForElement('form', 10);

        // The handler deliberately collapses every denial reason into ONE
        // generic message (anti-enumeration: don't reveal whether a code
        // exists, expired, or is scoped to another edition). Pin that
        // contract — a regression to reason-specific messages should flag.
        $generic = 'ongeldig of verlopen';

        $error = $this->applyVoucher($I, 'EDGE_NOPE_' . $stamp);
        \PHPUnit\Framework\Assert::assertStringContainsString($generic, $error, 'unknown code');

        $error = $this->applyVoucher($I, 'EDGE_EXPIRED_' . $stamp);
        \PHPUnit\Framework\Assert::assertStringContainsString($generic, $error, 'expired voucher');

        $error = $this->applyVoucher($I, 'EDGE_USEDUP_' . $stamp);
        \PHPUnit\Framework\Assert::assertStringContainsString($generic, $error, 'exhausted voucher');

        $error = $this->applyVoucher($I, 'EDGE_SCOPED_' . $stamp);
        \PHPUnit\Framework\Assert::assertStringContainsString($generic, $error, 'wrong-scope voucher');

        // Control: a valid voucher passes…
        $error = $this->applyVoucher($I, 'EDGE_VALID_' . $stamp);
        \PHPUnit\Framework\Assert::assertSame('', $error, 'valid voucher must not error');

        // …and validating without enrolling must NOT consume a use.
        $I->seeInDatabase($I->grabPrefixedTableNameFor('postmeta'), [
            'meta_key' => '_ntdst_used_count',
            'meta_value' => 0,
            'post_id' => $I->grabFromDatabase($I->grabPrefixedTableNameFor('postmeta'), 'post_id', [
                'meta_key' => '_ntdst_code',
                'meta_value' => 'EDGE_VALID_' . $stamp,
            ]),
        ]);
    }
}
