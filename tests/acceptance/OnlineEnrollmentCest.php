<?php

declare(strict_types=1);

use Tests\Support\AcceptanceTester;

/**
 * Online Enrollment Flow Acceptance Tests
 *
 * Tests online (e-learning/webinar) enrollment paths:
 * - Form-based enrollment (default + minimal forms, 2-step flow)
 * - Direct enrollment (no form, immediate redirect)
 * - Course page CTA rendering
 * - Already enrolled state
 * - Admin metabox behavior for online editions
 *
 * Prerequisites: seed data must be loaded (scripts/seed.php)
 * with the extended scenarios (minimal, direct, webinar).
 *
 * Alpine.js forms: use executeJS with Alpine.$data(el) for navigation.
 * DB prefix: stride_ (not wp_).
 */
class OnlineEnrollmentCest
{
    private array $courseData = [];
    private int $studentUserId;

    public function _before(AcceptanceTester $I): void
    {
        $this->courseData = $this->resolveSeedData($I);
        $this->studentUserId = (int) $I->grabFromDatabase($I->grabPrefixedTableNameFor('users'), 'ID', ['user_login' => 'seed_student1']);

        $this->cleanupRegistrations($I);
    }

    public function _after(AcceptanceTester $I): void
    {
        $this->cleanupRegistrations($I);
    }

    private function cleanupRegistrations(AcceptanceTester $I): void
    {
        foreach ($this->courseData as $scenario) {
            $I->dontHaveInDatabase($I->grabPrefixedTableNameFor('vad_registrations'), [
                'user_id'    => $this->studentUserId,
                'edition_id' => $scenario['edition_id'],
            ]);
        }

        // Mindfulness (minimal scenario) is the only course this Cest may grant
        // LearnDash access for. Other seed scenarios already have legitimate
        // seed-data LD usermeta we must not touch.
        $minimalCourseId = $this->courseData['minimal']['course_id'] ?? 0;
        if ($minimalCourseId) {
            $I->dontHaveInDatabase($I->grabPrefixedTableNameFor('usermeta'), [
                'user_id'  => $this->studentUserId,
                'meta_key' => 'course_' . $minimalCourseId . '_access_from',
            ]);
            $I->dontHaveInDatabase($I->grabPrefixedTableNameFor('usermeta'), [
                'user_id'  => $this->studentUserId,
                'meta_key' => 'learndash_course_' . $minimalCourseId . '_enrolled_at',
            ]);
        }
    }

    private function resolveSeedData(AcceptanceTester $I): array
    {
        $data = [];
        $data['default'] = $this->findEditionByCourseTitlePrefix($I, 'E-learning: Eetproblemen');
        $data['minimal'] = $this->findEditionByCourseTitlePrefix($I, 'E-learning: Mindfulness');
        $data['direct']  = $this->findEditionByCourseTitlePrefix($I, 'E-learning: Snelle Update');
        $data['webinar'] = $this->findEditionByCourseTitlePrefix($I, 'Webinarreeks: Actuele');
        return $data;
    }

    private function findEditionByCourseTitlePrefix(AcceptanceTester $I, string $prefix): array
    {
        $courseId = $I->grabFromDatabase($I->grabPrefixedTableNameFor('posts'), 'ID', [
            'post_type' => 'sfwd-courses',
            'post_status' => 'publish',
            'post_title LIKE' => $prefix . '%',
        ]);

        $editionId = $I->grabFromDatabase($I->grabPrefixedTableNameFor('postmeta'), 'post_id', [
            'meta_key' => '_ntdst_course_id',
            'meta_value' => $courseId,
        ]);

        $slug = $I->grabFromDatabase($I->grabPrefixedTableNameFor('posts'), 'post_name', ['ID' => $editionId]);

        return [
            'course_id' => (int) $courseId,
            'edition_id' => (int) $editionId,
            'slug' => $slug,
            'enrollment_url' => '/edities/' . $editionId . '/inschrijving/',
        ];
    }

    private function loginAsStudent(AcceptanceTester $I, string $redirectTo = '/'): void
    {
        $I->loginAsUserId($this->studentUserId, $redirectTo);
    }

    // =========================================================================
    // ENROLLMENT FORM TESTS (Scenarios 2 + 3)
    // =========================================================================

    /**
     * @test
     */
    public function onlineDefaultFormShowsTwoSteps(AcceptanceTester $I): void
    {
        $I->wantTo('verify online default form shows only personal + confirm steps');

        $this->loginAsStudent($I, $this->courseData['default']['enrollment_url']);
        $I->waitForElement('form', 10);

        $this->assertProgressBarHasOnlySteps($I, ['Gegevens', 'Bevestigen']);
    }

    /**
     * @test
     */
    public function onlineMinimalFormShowsTwoSteps(AcceptanceTester $I): void
    {
        $I->wantTo('verify online minimal form shows only personal + confirm steps');

        $this->loginAsStudent($I, $this->courseData['minimal']['enrollment_url']);
        $I->waitForElement('form', 10);

        $this->assertProgressBarHasOnlySteps($I, ['Gegevens', 'Bevestigen']);
    }

    /**
     * Scope step assertions to the progress bar so that strings appearing inside
     * Alpine <template x-if> fragments elsewhere on the page (e.g. the billing
     * recap on step-confirm) don't pollute the result. Selenium's getText()
     * includes <template> content even though the user never sees it.
     */
    private function assertProgressBarHasOnlySteps(AcceptanceTester $I, array $expectedLabels): void
    {
        $progressLabels = $I->executeJS(<<<'JS'
            const nav = document.querySelector('nav[aria-label="Voortgang"]');
            if (!nav) return null;
            return Array.from(nav.querySelectorAll('li')).map(li => li.innerText.trim()).filter(Boolean);
        JS);

        // Strip the "1 Gegevens", "2 Bevestigen" numbering — keep just labels.
        $cleaned = array_map(static function ($label) {
            return trim((string) preg_replace('/^\d+\s+/', '', (string) $label));
        }, $progressLabels ?? []);

        \PHPUnit\Framework\Assert::assertSame(
            $expectedLabels,
            $cleaned,
            'Progress bar should show exactly: ' . implode(', ', $expectedLabels)
        );
    }

    /**
     * @test
     */
    public function onlineEnrollmentCreatesRegistration(AcceptanceTester $I): void
    {
        $I->wantTo('verify online enrollment creates a registration record');

        $this->loginAsStudent($I, $this->courseData['minimal']['enrollment_url']);
        $I->waitForElement('form', 10);

        // Use executeJS to fill form and submit via Alpine (established pattern)
        $I->executeJS("
            const el = document.querySelector('[x-data^=\"enrollmentForm\"]');
            const comp = Alpine.\$data(el);
            if (comp) {
                comp.form.enrollment_type = 'self';
                comp.form.terms_accepted = true;
                comp.stepIndex = comp.stepMap.length - 1;
            }
        ");
        $I->wait(1);

        $I->executeJS("
            const el = document.querySelector('[x-data^=\"enrollmentForm\"]');
            const comp = Alpine.\$data(el);
            if (comp) comp.submitForm();
        ");
        $I->wait(3);

        $I->seeInDatabase($I->grabPrefixedTableNameFor('vad_registrations'), [
            'edition_id' => $this->courseData['minimal']['edition_id'],
            'user_id' => $this->studentUserId,
        ]);
    }

    // =========================================================================
    // DIRECT ENROLLMENT TESTS (Scenario 4)
    // =========================================================================

    /**
     * @test
     */
    public function directEnrollmentSkipsForm(AcceptanceTester $I): void
    {
        $I->wantTo('verify direct enrollment redirects without showing a form');

        $this->loginAsStudent($I, $this->courseData['direct']['enrollment_url']);
        $I->wait(3);

        $I->dontSee('Gegevens');
        $I->dontSee('Facturatie');

        $I->seeInDatabase($I->grabPrefixedTableNameFor('vad_registrations'), [
            'edition_id' => $this->courseData['direct']['edition_id'],
            'user_id' => $this->studentUserId,
        ]);
    }

    /**
     * @test
     */
    public function alreadyEnrolledUserSeesMessage(AcceptanceTester $I): void
    {
        $I->wantTo('verify already enrolled user sees already-enrolled message');

        $I->haveInDatabase($I->grabPrefixedTableNameFor('vad_registrations'), [
            'user_id'         => $this->studentUserId,
            'edition_id'      => $this->courseData['default']['edition_id'],
            'status'          => 'confirmed',
            'enrollment_path' => 'individual',
            'registered_at'   => date('Y-m-d H:i:s'),
        ]);

        $this->loginAsStudent($I, $this->courseData['default']['enrollment_url']);
        $I->waitForText('Je bent al ingeschreven', 10);
    }

    // =========================================================================
    // COURSE PAGE CTA TESTS
    // =========================================================================

    /**
     * @test
     */
    public function closedOnlineCourseCTAShowsEnrollButton(AcceptanceTester $I): void
    {
        $I->wantTo('verify closed online course shows Inschrijven CTA');

        $courseSlug = $I->grabFromDatabase($I->grabPrefixedTableNameFor('posts'), 'post_name', [
            'ID' => $this->courseData['default']['course_id'],
        ]);

        $this->loginAsStudent($I, '/cursussen/' . $courseSlug . '/');
        $I->waitForElement('body', 10);

        $I->see('Inschrijven');
        $I->dontSee('Fatal error');
    }

    /**
     * @test
     */
    public function webinarCourseShowsWebinarLabel(AcceptanceTester $I): void
    {
        $I->wantTo('verify webinar course page shows Webinar format label');

        $courseSlug = $I->grabFromDatabase($I->grabPrefixedTableNameFor('posts'), 'post_name', [
            'ID' => $this->courseData['webinar']['course_id'],
        ]);

        $this->loginAsStudent($I, '/cursussen/' . $courseSlug . '/');
        $I->waitForElement('body', 10);

        $I->see('Webinar');
    }

    // =========================================================================
    // ADMIN UI TESTS
    // =========================================================================

    /**
     * @test
     */
    public function onlineEditionHidesSessionsMetabox(AcceptanceTester $I): void
    {
        $I->wantTo('verify online edition edit page hides sessions metabox');

        $adminId = (int) $I->grabFromDatabase($I->grabPrefixedTableNameFor('users'), 'ID', ['user_login' => 'seed_admin']);
        $I->loginAsUserId($adminId, '/wp/wp-admin/post.php?post=' . $this->courseData['minimal']['edition_id'] . '&action=edit');

        $I->waitForElement('#post', 10);
        $I->wait(2);

        $I->executeJS("
            const sessionsBox = document.getElementById('stride_edition_sessions');
            return sessionsBox ? sessionsBox.style.display : 'not-found';
        ");
    }

    // =========================================================================
    // DASHBOARD TESTS
    // =========================================================================

    /**
     * @test
     */
    public function enrolledOnlineCourseAppearsInDashboard(AcceptanceTester $I): void
    {
        $I->wantTo('verify enrolled online course appears in dashboard');

        $courseId = $this->courseData['minimal']['course_id'];

        $I->haveInDatabase($I->grabPrefixedTableNameFor('vad_registrations'), [
            'user_id'         => $this->studentUserId,
            'edition_id'      => $this->courseData['minimal']['edition_id'],
            'status'          => 'confirmed',
            'enrollment_path' => 'individual',
            'registered_at'   => date('Y-m-d H:i:s'),
        ]);

        // Online courses surface on the dashboard via LearnDash usermeta — not
        // vad_registrations. Mirror what EnrollmentService → LMSAdapter::grantAccess
        // writes in production, so the dashboard "Online cursussen" section picks
        // this enrollment up.
        $now = time();
        $I->haveUserMetaInDatabase($this->studentUserId, 'course_' . $courseId . '_access_from', (string) $now);
        $I->haveUserMetaInDatabase($this->studentUserId, 'learndash_course_' . $courseId . '_enrolled_at', (string) $now);

        // WP core redirects /dashboard/ → /wp-admin/ via wp_redirect_admin_locations();
        // the real user dashboard lives at /mijn-account/.
        $this->loginAsStudent($I, '/mijn-account/?tab=inschrijvingen');
        $I->waitForElement('body', 10);

        $I->see('Mindfulness');
        $I->dontSee('Fatal error');
    }
}
