<?php

declare(strict_types=1);

use Tests\Support\AcceptanceTester;

/**
 * Trajectory E2E — drives the plan's acceptance matrix (F1–F6,
 * docs/superpowers/plans/2026-06-12-trajectory-wiring.md) through the real
 * browser and the un-mocked wire.
 *
 * Self-sufficient fixtures: own trajectory (cohort, open choice window) with
 * one mandatory edition-backed course and one elective group holding an
 * edition-backed course AND a pure-LD course (min_choices 1), own editions,
 * own users. Cleanup in _after.
 */
class TrajectoryE2ECest
{
    private string $stamp;

    private int $trajectoryId;
    private string $trajectorySlug;

    private int $mandatoryCourseId;
    private int $mandatoryEditionId;
    private int $electiveCourseId;
    private int $electiveEditionId;
    private int $pureLdCourseId;

    private int $userId;
    private string $userEmail;
    private int $strangerId;

    public function _before(AcceptanceTester $I): void
    {
        $this->stamp = time() . '_' . substr(md5((string) microtime(true)), 0, 4);

        // ── Courses ──
        $this->mandatoryCourseId = $I->havePostInDatabase([
            'post_type' => 'sfwd-courses', 'post_status' => 'publish',
            'post_title' => 'E2E Verplichte Cursus ' . $this->stamp,
        ]);
        $this->electiveCourseId = $I->havePostInDatabase([
            'post_type' => 'sfwd-courses', 'post_status' => 'publish',
            'post_title' => 'E2E Keuze Editie ' . $this->stamp,
        ]);
        $this->pureLdCourseId = $I->havePostInDatabase([
            'post_type' => 'sfwd-courses', 'post_status' => 'publish',
            'post_title' => 'E2E Keuze Online ' . $this->stamp,
        ]);

        // ── Editions ──
        $this->mandatoryEditionId = $this->makeEdition($I, 'E2E Verplichte Editie', $this->mandatoryCourseId);
        $this->electiveEditionId = $this->makeEdition($I, 'E2E Keuze Editie Aanbod', $this->electiveCourseId);

        // ── Trajectory ──
        $this->trajectorySlug = 'e2e-traject-' . $this->stamp;
        $this->trajectoryId = $I->havePostInDatabase([
            'post_type' => 'vad_trajectory', 'post_status' => 'publish',
            'post_title' => 'E2E Traject ' . $this->stamp,
            'post_name' => $this->trajectorySlug,
        ]);
        $I->havePostmetaInDatabase($this->trajectoryId, '_ntdst_mode', 'cohort');
        $I->havePostmetaInDatabase($this->trajectoryId, '_ntdst_status', 'open');
        $I->havePostmetaInDatabase($this->trajectoryId, '_ntdst_price', '100');
        $I->havePostmetaInDatabase($this->trajectoryId, '_ntdst_capacity', '20');
        $I->havePostmetaInDatabase($this->trajectoryId, '_ntdst_choice_available_date', date('Y-m-d', strtotime('-1 day')));
        $I->havePostmetaInDatabase($this->trajectoryId, '_ntdst_choice_deadline', date('Y-m-d', strtotime('+7 days')));
        $I->havePostmetaInDatabase($this->trajectoryId, '_ntdst_courses', json_encode([
            [
                'course_id' => $this->mandatoryCourseId,
                'required' => true,
                'type' => 'edition',
                'edition_id' => $this->mandatoryEditionId,
                'order' => 1,
            ],
            [
                'course_id' => $this->electiveCourseId,
                'required' => false,
                'group' => 'Verdieping',
                'min_choices' => 1,
                'type' => 'edition',
                'edition_id' => $this->electiveEditionId,
            ],
            [
                'course_id' => $this->pureLdCourseId,
                'required' => false,
                'group' => 'Verdieping',
                'min_choices' => 1,
            ],
        ]));

        // ── Users ──
        $this->userEmail = 'e2e_traj_' . $this->stamp . '@test.local';
        $this->userId = $I->haveUserInDatabase('e2e_traj_' . $this->stamp, 'subscriber', [
            'user_email' => $this->userEmail, 'display_name' => 'E2E Traject Tester',
        ]);
        $I->haveUserMetaInDatabase($this->userId, 'first_name', 'E2E');
        $I->haveUserMetaInDatabase($this->userId, 'last_name', 'Tester');

        $this->strangerId = $I->haveUserInDatabase('e2e_stranger_' . $this->stamp, 'subscriber', [
            'user_email' => 'e2e_stranger_' . $this->stamp . '@test.local',
        ]);
    }

    public function _after(AcceptanceTester $I): void
    {
        // Registrations (parent + cascade children) and quotes created by the
        // app are not have*-tracked — clean by hand.
        foreach ([$this->userId, $this->strangerId] as $uid) {
            $I->dontHaveInDatabase($I->grabPrefixedTableNameFor('vad_registrations'), ['user_id' => $uid]);
            // LD access usermeta granted by pure-LD picks.
            $I->dontHaveInDatabase($I->grabPrefixedTableNameFor('usermeta'), [
                'user_id' => $uid, 'meta_key' => 'course_' . $this->pureLdCourseId . '_access_from',
            ]);
            $I->dontHaveInDatabase($I->grabPrefixedTableNameFor('usermeta'), [
                'user_id' => $uid, 'meta_key' => 'learndash_course_' . $this->pureLdCourseId . '_enrolled_at',
            ]);
        }
    }

    private function makeEdition(AcceptanceTester $I, string $title, int $courseId): int
    {
        $editionId = $I->havePostInDatabase([
            'post_type' => 'vad_edition', 'post_status' => 'publish',
            'post_title' => $title . ' ' . $this->stamp,
            'post_name' => strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', $title . '-' . $this->stamp)),
        ]);
        $I->havePostmetaInDatabase($editionId, '_ntdst_course_id', $courseId);
        $I->havePostmetaInDatabase($editionId, '_ntdst_status', 'open');
        $I->havePostmetaInDatabase($editionId, '_ntdst_price', '50');
        $I->havePostmetaInDatabase($editionId, '_ntdst_capacity', '20');
        $I->havePostmetaInDatabase($editionId, '_ntdst_start_date', date('Y-m-d', strtotime('+30 days')));

        return $editionId;
    }

    private function dashboardUrl(string $tab = ''): string
    {
        return '/mijn-account/trajecten/' . $this->trajectorySlug . '/' . ($tab ? '?tab=' . $tab : '');
    }

    /** Enroll the test user through the real wizard. */
    private function enroll(AcceptanceTester $I): void
    {
        $I->loginAsUserId($this->userId, '/trajecten/' . $this->trajectorySlug . '/inschrijving/');
        $I->waitForElement('form', 10);
        $I->wait(1);

        $I->executeJS("
            const el = document.querySelector('[x-data^=\"enrollmentForm\"]');
            const comp = Alpine.\$data(el);
            comp.form.enrollment_type = 'self';
            comp.form.first_name = 'E2E';
            comp.form.last_name = 'Tester';
            comp.form.email = '{$this->userEmail}';
            comp.form.phone = '+32477000000';
            comp.form.terms_accepted = true;
            comp.stepIndex = comp.stepMap.length - 1;
        ");
        $I->wait(1);
        $I->executeJS("
            const el = document.querySelector('[x-data^=\"enrollmentForm\"]');
            Alpine.\$data(el).submitForm();
        ");
        $I->wait(4);
    }

    private function grabParentRegistrationId(AcceptanceTester $I): int
    {
        return (int) $I->grabFromDatabase($I->grabPrefixedTableNameFor('vad_registrations'), 'id', [
            'user_id' => $this->userId,
            'trajectory_id' => $this->trajectoryId,
        ]);
    }

    /** Submit choices over the wire from the current logged-in page. */
    private function submitChoices(AcceptanceTester $I, int $registrationId, array $courseIds): void
    {
        $I->executeJS("
            window.__e2eResult = null;
            ntdstAPI.call('stride_save_trajectory_choices', {
                registration_id: {$registrationId},
                selections: " . json_encode(array_values($courseIds)) . ",
            }).then(r => window.__e2eResult = { ok: true })
              .catch(e => window.__e2eResult = { error: e.message || 'refused' });
        ");
        $I->waitForJS('return window.__e2eResult !== null;', 10);
    }

    private function wireResultOk(AcceptanceTester $I): bool
    {
        return (bool) $I->executeJS('return !!(window.__e2eResult && window.__e2eResult.ok);');
    }

    // =========================================================================
    // F1 — enroll → parent + mandatory child
    // =========================================================================

    /**
     * @test
     */
    public function enrollCreatesParentAndMandatoryChild(AcceptanceTester $I): void
    {
        $I->wantTo('enroll in the trajectory and find the parent plus the mandatory cascade child');

        $this->enroll($I);

        $parentId = $this->grabParentRegistrationId($I);
        \PHPUnit\Framework\Assert::assertGreaterThan(0, $parentId, 'parent trajectory registration must exist');

        $I->seeInDatabase($I->grabPrefixedTableNameFor('vad_registrations'), [
            'user_id' => $this->userId,
            'edition_id' => $this->mandatoryEditionId,
            'parent_registration_id' => $parentId,
        ]);

        // Dashboard shows the trajectory.
        $I->amOnPage('/mijn-account/?tab=trajecten');
        $I->waitForElement('main', 10);
        $I->see('E2E Traject ' . $this->stamp);

        // Mail fan-out: the trajectory-enrolled confirmation reached Mailpit.
        $found = false;
        for ($attempt = 0; $attempt < 10 && !$found; $attempt++) {
            $response = @file_get_contents('http://127.0.0.1:8025/api/v1/search?query=' . urlencode('to:"' . $this->userEmail . '"'));
            $payload = $response ? json_decode($response, true) : null;
            foreach ($payload['messages'] ?? [] as $message) {
                if (str_contains((string) ($message['Subject'] ?? ''), 'Inschrijving traject')) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                usleep(500_000);
            }
        }
        \PHPUnit\Framework\Assert::assertTrue($found, 'the stride-trajectory-enrolled mail must reach the enrollee');
    }

    // =========================================================================
    // F2/F3 — keuzes flow through browser + wire
    // =========================================================================

    /**
     * @test
     */
    public function chooseEditionElectiveThroughTheBrowserPersistsAndRenders(AcceptanceTester $I): void
    {
        $I->wantTo('pick an edition-backed elective in the keuzes tab and see it persisted + re-rendered');

        $this->enroll($I);
        $parentId = $this->grabParentRegistrationId($I);

        $I->amOnPage($this->dashboardUrl('keuzes'));
        $I->waitForElement('#elective-selection-form', 10);
        $I->wait(1); // Alpine boot

        // Pick the edition-backed elective and confirm.
        $I->checkOption('input[value="' . $this->electiveCourseId . '"]');
        $I->click('#elective-selection-form button[type="submit"]');
        $I->wait(3); // submit + reload

        // Persisted: child registration on the elective edition; selections column.
        $I->seeInDatabase($I->grabPrefixedTableNameFor('vad_registrations'), [
            'user_id' => $this->userId,
            'edition_id' => $this->electiveEditionId,
            'parent_registration_id' => $parentId,
        ]);

        // Re-rendered: the option is checked after reload.
        $I->amOnPage($this->dashboardUrl('keuzes'));
        $I->waitForElement('#elective-selection-form', 10);
        $checked = (bool) $I->executeJS(
            "const i = document.querySelector('input[value=\"{$this->electiveCourseId}\"]'); return i && i.checked;"
        );
        \PHPUnit\Framework\Assert::assertTrue($checked, 'the saved pick must render checked after reload');
    }

    /**
     * @test
     */
    public function switchingToPureLdGrantsAccessAndCancelsEditionChild(AcceptanceTester $I): void
    {
        $I->wantTo('switch the pick to the pure-LD course: LD access granted, edition child cancelled');

        $this->enroll($I);
        $parentId = $this->grabParentRegistrationId($I);

        $I->amOnPage($this->dashboardUrl('keuzes'));
        $I->waitForElement('#elective-selection-form', 10);
        $I->wait(1);

        // First pick the edition-backed elective…
        $this->submitChoices($I, $parentId, [$this->electiveCourseId]);
        \PHPUnit\Framework\Assert::assertTrue($this->wireResultOk($I), 'edition pick must be accepted');

        // …then switch to the pure-LD course.
        $this->submitChoices($I, $parentId, [$this->pureLdCourseId]);
        \PHPUnit\Framework\Assert::assertTrue($this->wireResultOk($I), 'pure-LD pick must be accepted');

        // LD access granted (adapter writes the LD course access usermeta).
        $I->seeInDatabase($I->grabPrefixedTableNameFor('usermeta'), [
            'user_id' => $this->userId,
            'meta_key' => 'course_' . $this->pureLdCourseId . '_access_from',
        ]);

        // The edition child is cancelled by the cascade reconcile.
        $childStatus = (string) $I->grabFromDatabase($I->grabPrefixedTableNameFor('vad_registrations'), 'status', [
            'user_id' => $this->userId,
            'edition_id' => $this->electiveEditionId,
            'parent_registration_id' => $parentId,
        ]);
        \PHPUnit\Framework\Assert::assertSame('cancelled', $childStatus, 'deselected edition child must be cancelled');

        // Switching back revokes the pure-LD access.
        $this->submitChoices($I, $parentId, [$this->electiveCourseId]);
        \PHPUnit\Framework\Assert::assertTrue($this->wireResultOk($I), 'switching back must be accepted');
        $I->dontSeeInDatabase($I->grabPrefixedTableNameFor('usermeta'), [
            'user_id' => $this->userId,
            'meta_key' => 'course_' . $this->pureLdCourseId . '_access_from',
        ]);
    }

    /**
     * @test
     */
    public function choicesDenialsAreEnforcedServerSide(AcceptanceTester $I): void
    {
        $I->wantTo('verify foreign, over-pick, unknown-course and closed-window submissions are refused');

        $this->enroll($I);
        $parentId = $this->grabParentRegistrationId($I);

        // Foreign actor: stranger calls with the owner's registration id.
        $I->loginAsUserId($this->strangerId, '/');
        $I->waitForElement('body', 10);
        $this->submitChoices($I, $parentId, [$this->electiveCourseId]);
        \PHPUnit\Framework\Assert::assertFalse($this->wireResultOk($I), 'foreign registration_id must be refused');

        // Owner context for the remaining denials.
        $I->loginAsUserId($this->userId, $this->dashboardUrl('keuzes'));
        $I->waitForElement('#elective-selection-form', 10);
        $I->wait(1);

        // Over-pick: both electives in a min_choices=1 group.
        $this->submitChoices($I, $parentId, [$this->electiveCourseId, $this->pureLdCourseId]);
        \PHPUnit\Framework\Assert::assertFalse($this->wireResultOk($I), 'over-picking must be refused');

        // Unknown course id.
        $this->submitChoices($I, $parentId, [999999]);
        \PHPUnit\Framework\Assert::assertFalse($this->wireResultOk($I), 'unknown course id must be refused');

        // No selections rows were created by any refused call.
        $I->dontSeeInDatabase($I->grabPrefixedTableNameFor('vad_registrations'), [
            'user_id' => $this->userId,
            'edition_id' => $this->electiveEditionId,
        ]);

        // Closed window: deadline in the past → refused server-side.
        $I->updateInDatabase($I->grabPrefixedTableNameFor('postmeta'),
            ['meta_value' => date('Y-m-d', strtotime('-1 day'))],
            ['post_id' => $this->trajectoryId, 'meta_key' => '_ntdst_choice_deadline']);
        $this->submitChoices($I, $parentId, [$this->electiveCourseId]);
        \PHPUnit\Framework\Assert::assertFalse($this->wireResultOk($I), 'submission after the deadline must be refused');
    }

    // =========================================================================
    // F4 — window states
    // =========================================================================

    /**
     * @test
     */
    public function keuzesWindowStatesRenderCorrectly(AcceptanceTester $I): void
    {
        $I->wantTo('see the before/open/after states of the keuzes tab');

        $this->enroll($I);
        $table = $I->grabPrefixedTableNameFor('postmeta');

        // BEFORE: available date in the future → preview, no form.
        $I->updateInDatabase($table, ['meta_value' => date('Y-m-d', strtotime('+2 days'))],
            ['post_id' => $this->trajectoryId, 'meta_key' => '_ntdst_choice_available_date']);
        $I->amOnPage($this->dashboardUrl('keuzes'));
        $I->waitForElement('main', 10);
        $I->see('Keuzemoment nog niet beschikbaar');
        \PHPUnit\Framework\Assert::assertSame(0, (int) $I->executeJS("return document.querySelectorAll('#elective-selection-form').length;"));

        // OPEN: window open → form renders.
        $I->updateInDatabase($table, ['meta_value' => date('Y-m-d', strtotime('-1 day'))],
            ['post_id' => $this->trajectoryId, 'meta_key' => '_ntdst_choice_available_date']);
        $I->amOnPage($this->dashboardUrl('keuzes'));
        $I->waitForElement('#elective-selection-form', 10);
        $I->see('Bevestig je keuze');

        // AFTER: deadline passed → read-only summary, no form.
        $I->updateInDatabase($table, ['meta_value' => date('Y-m-d', strtotime('-1 day'))],
            ['post_id' => $this->trajectoryId, 'meta_key' => '_ntdst_choice_deadline']);
        $I->amOnPage($this->dashboardUrl('keuzes'));
        $I->waitForElement('main', 10);
        $I->see('Keuzeperiode is gesloten');
        $I->see('Er zijn geen keuzes gemaakt tijdens de keuzeperiode.');
    }

    // =========================================================================
    // F5 — messages
    // =========================================================================

    /**
     * @test
     */
    public function berichtenTabShowsAdminMessagesAndHidesDeleted(AcceptanceTester $I): void
    {
        $I->wantTo('see admin-authored messages on the berichten tab, deleted ones hidden');

        $this->enroll($I);

        // Author messages exactly as the admin metabox save persists them
        // (registered json field, sanitized keys).
        $I->havePostmetaInDatabase($this->trajectoryId, '_ntdst_trajectory_messages', json_encode([
            ['type' => 'announcement', 'content' => 'Welkom bij het E2E traject!', 'author' => 'Beheerder', 'date' => date('Y-m-d')],
            ['type' => 'announcement', 'content' => 'Verwijderd bericht', 'author' => 'Beheerder', 'date' => date('Y-m-d'), '_deleted' => true],
        ]));

        $I->amOnPage($this->dashboardUrl('berichten'));
        $I->waitForElement('main', 10);

        $I->see('Welkom bij het E2E traject!');
        $I->dontSee('Verwijderd bericht');
    }

    // =========================================================================
    // F6 — materialen + access gate
    // =========================================================================

    /**
     * @test
     */
    public function materialenTabRendersAndNonEnrolledUserIsGated(AcceptanceTester $I): void
    {
        $I->wantTo('see the materialen tab render for an enrollee and the dashboard gate non-enrolled users');

        $this->enroll($I);
        $I->amOnPage($this->dashboardUrl('materialen'));
        $I->waitForElement('main', 10);
        $I->dontSee('Fatal error');

        // Non-enrolled user: no keuzes form reachable on this trajectory.
        $I->loginAsUserId($this->strangerId, $this->dashboardUrl('keuzes'));
        $I->waitForElement('body', 10);
        \PHPUnit\Framework\Assert::assertSame(
            0,
            (int) $I->executeJS("return document.querySelectorAll('#elective-selection-form').length;"),
            'a non-enrolled user must not reach the elective form'
        );
    }
}
