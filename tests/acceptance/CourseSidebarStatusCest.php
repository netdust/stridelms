<?php

declare(strict_types=1);

use Tests\Support\AcceptanceTester;

/**
 * Course / Edition Sidebar Status Acceptance Tests
 *
 * Verifies that the public sidebar reflects:
 * - Edition (effective) status → CTA branch on the online-course page
 *   (Inschrijven / Interesse melden / Op wachtlijst plaatsen / Niet beschikbaar)
 * - Edition status header + CTA on the edition detail page
 *   (Schrijf je in / Volzet / Editie geannuleerd / uitgesteld / is bezig)
 * - LearnDash "Course Access Settings" (price type + price) → Gratis vs
 *   formatted price + Cursus kopen on a pure-LD online course
 * - LearnDash "Course Completion Awards" / settings (points, required points,
 *   access duration, availability window) → Cursusdetails rows
 *
 * Subjects are resolved by seed title prefix (scripts/seed.php feature-matrix
 * seeder), never by hardcoded ID. All mutations are made directly in the DB
 * (postmeta) and restored in _after().
 *
 * Not covered: the certificate download button — it only renders in the
 * completed state, which requires real LearnDash course-progress usermeta.
 */
class CourseSidebarStatusCest
{
    /** Pure-LD online course (no edition): drives the LD-settings tests. */
    private const PURE_LD_COURSE_PREFIX = 'E-learning: Basiskennis';

    /** Online course WITH editions: drives the edition-status tests. */
    private const EDITION_COURSE_PREFIX = 'E-learning: Beweegbeleid';

    private int $pureLdCourseId;
    private string $pureLdCourseUrl;

    private int $editionCourseId;
    private string $editionCourseUrl;

    /** The edition under test (seeded status: open) + its detail URL. */
    private int $editionId;
    private string $editionUrl;
    private string $editionPriceFormatted;

    /** editionId => original `_ntdst_status`, for restore. */
    private array $originalStatuses = [];

    /** Original raw `_sfwd-courses` meta_value, or null when the row was absent. */
    private ?string $originalLdSettings = null;

    public function _before(AcceptanceTester $I): void
    {
        $this->pureLdCourseId = $this->grabCourseIdByTitlePrefix($I, self::PURE_LD_COURSE_PREFIX);
        $this->pureLdCourseUrl = $this->courseUrl($I, $this->pureLdCourseId);

        $this->editionCourseId = $this->grabCourseIdByTitlePrefix($I, self::EDITION_COURSE_PREFIX);
        $this->editionCourseUrl = $this->courseUrl($I, $this->editionCourseId);

        // Snapshot every edition status of the course, then park all but the
        // seeded-open one as draft so the page's "primary edition" is
        // deterministic regardless of seed ordering.
        $editionIds = $this->grabEditionIdsForCourse($I, $this->editionCourseId);
        \PHPUnit\Framework\Assert::assertNotEmpty($editionIds, 'Seed data must provide editions for ' . self::EDITION_COURSE_PREFIX);

        $this->editionId = 0;
        foreach ($editionIds as $editionId) {
            $status = (string) $I->grabFromDatabase($I->grabPrefixedTableNameFor('postmeta'), 'meta_value', [
                'post_id'  => $editionId,
                'meta_key' => '_ntdst_status',
            ]);
            $this->originalStatuses[$editionId] = $status;
            if ($this->editionId === 0 && $status === 'open') {
                $this->editionId = $editionId;
            }
        }
        \PHPUnit\Framework\Assert::assertGreaterThan(0, $this->editionId, 'Seed data must provide an open edition for ' . self::EDITION_COURSE_PREFIX);

        foreach ($editionIds as $editionId) {
            if ($editionId !== $this->editionId) {
                $this->setEditionStatus($I, $editionId, 'draft');
            }
        }

        $slug = (string) $I->grabFromDatabase($I->grabPrefixedTableNameFor('posts'), 'post_name', ['ID' => $this->editionId]);
        $this->editionUrl = '/edities/' . $slug . '/';

        $priceEuros = (float) $I->grabFromDatabase($I->grabPrefixedTableNameFor('postmeta'), 'meta_value', [
            'post_id'  => $this->editionId,
            'meta_key' => '_ntdst_price',
        ]);
        $this->editionPriceFormatted = '€ ' . number_format($priceEuros, 2, ',', '.');

        // Snapshot the pure-LD course's LearnDash settings row.
        $raw = $I->grabFromDatabase($I->grabPrefixedTableNameFor('postmeta'), 'meta_value', [
            'post_id'  => $this->pureLdCourseId,
            'meta_key' => '_sfwd-courses',
        ]);
        $this->originalLdSettings = is_string($raw) && $raw !== '' ? $raw : null;
    }

    public function _after(AcceptanceTester $I): void
    {
        foreach ($this->originalStatuses as $editionId => $status) {
            $this->setEditionStatus($I, $editionId, $status);
        }

        $table = $I->grabPrefixedTableNameFor('postmeta');
        $I->dontHaveInDatabase($table, [
            'post_id'  => $this->pureLdCourseId,
            'meta_key' => '_sfwd-courses',
        ]);
        if ($this->originalLdSettings !== null) {
            $I->haveInDatabase($table, [
                'post_id'    => $this->pureLdCourseId,
                'meta_key'   => '_sfwd-courses',
                'meta_value' => $this->originalLdSettings,
            ]);
        }
    }

    // =========================================================================
    // ONLINE COURSE PAGE — edition status drives the sidebar CTA
    // =========================================================================

    /**
     * @test
     */
    public function openEditionShowsEnrollCtaWithEditionPrice(AcceptanceTester $I): void
    {
        $I->wantTo('see Inschrijven CTA and edition price for an open edition on the course page');

        $this->setEditionStatus($I, $this->editionId, 'open');
        $I->amOnPage($this->editionCourseUrl);
        $I->waitForElement('aside', 10);

        $I->see($this->editionPriceFormatted, 'aside');
        $I->see('Inschrijven', 'aside a');
        $I->dontSee('Niet beschikbaar', 'aside');
    }

    /**
     * @test
     */
    public function announcementEditionShowsInterestCta(AcceptanceTester $I): void
    {
        $I->wantTo('see Interesse melden CTA when the edition status is announcement');

        $this->setEditionStatus($I, $this->editionId, 'announcement');
        $I->amOnPage($this->editionCourseUrl);
        $I->waitForElement('aside', 10);

        $I->see('Interesse melden', 'aside a');
        $I->see('nog in voorbereiding', 'aside');
        $I->dontSee('Op wachtlijst plaatsen', 'aside');
    }

    /**
     * @test
     */
    public function fullEditionShowsWaitlistCta(AcceptanceTester $I): void
    {
        $I->wantTo('see waitlist CTA when the edition status is full');

        $this->setEditionStatus($I, $this->editionId, 'full');
        $I->amOnPage($this->editionCourseUrl);
        $I->waitForElement('aside', 10);

        $I->see('Op wachtlijst plaatsen', 'aside a');
        $I->see('volzet', 'aside');
        $I->dontSee('Interesse melden', 'aside');
    }

    /**
     * @test
     */
    public function inProgressEditionShowsNotAvailable(AcceptanceTester $I): void
    {
        $I->wantTo('see a disabled Niet beschikbaar button when the edition is in progress');

        $this->setEditionStatus($I, $this->editionId, 'in_progress');
        $I->amOnPage($this->editionCourseUrl);
        $I->waitForElement('aside', 10);

        $I->see('Niet beschikbaar', 'aside button');
        $I->dontSee('Interesse melden', 'aside');
        $I->dontSee('Op wachtlijst plaatsen', 'aside');
    }

    // =========================================================================
    // EDITION DETAIL PAGE — status header, badge and CTA
    // =========================================================================

    /**
     * @test
     */
    public function openEditionPageShowsEnrollCta(AcceptanceTester $I): void
    {
        $I->wantTo('see Schrijf je in CTA on an open edition detail page');

        $this->setEditionStatus($I, $this->editionId, 'open');
        $I->amOnPage($this->editionUrl);
        $I->waitForElement('body', 10);

        $I->see('Schrijf je in');
        $I->see($this->editionPriceFormatted);
    }

    /**
     * @test
     */
    public function fullEditionPageShowsVolzetAndWaitlist(AcceptanceTester $I): void
    {
        $I->wantTo('see Volzet badge and waitlist CTA on a full edition detail page');

        $this->setEditionStatus($I, $this->editionId, 'full');
        $I->amOnPage($this->editionUrl);
        $I->waitForElement('body', 10);

        $I->see('Volzet');
        $I->see('Op wachtlijst plaatsen');
        $I->dontSee('Schrijf je in');
    }

    /**
     * @test
     */
    public function cancelledEditionPageShowsStatusHeader(AcceptanceTester $I): void
    {
        $I->wantTo('see the cancelled status header on a cancelled edition detail page');

        $this->setEditionStatus($I, $this->editionId, 'cancelled');
        $I->amOnPage($this->editionUrl);
        $I->waitForElement('body', 10);

        $I->see('Editie geannuleerd');
        $I->dontSee('Schrijf je in');
    }

    /**
     * @test
     */
    public function postponedEditionPageShowsStatusHeader(AcceptanceTester $I): void
    {
        $I->wantTo('see the postponed status header on a postponed edition detail page');

        $this->setEditionStatus($I, $this->editionId, 'postponed');
        $I->amOnPage($this->editionUrl);
        $I->waitForElement('body', 10);

        $I->see('Editie uitgesteld');
        $I->dontSee('Schrijf je in');
    }

    /**
     * @test
     */
    public function inProgressEditionPageShowsStatusHeader(AcceptanceTester $I): void
    {
        $I->wantTo('see the in-progress status header on a running edition detail page');

        $this->setEditionStatus($I, $this->editionId, 'in_progress');
        $I->amOnPage($this->editionUrl);
        $I->waitForElement('body', 10);

        $I->see('Editie is bezig');
        $I->dontSee('Schrijf je in');
    }

    // =========================================================================
    // PURE-LD COURSE PAGE — LearnDash access settings drive the price block
    // =========================================================================

    /**
     * @test
     */
    public function freeAccessSettingShowsGratis(AcceptanceTester $I): void
    {
        $I->wantTo('see Gratis in the sidebar when course access is set to free');

        $this->setLdSettings($I, [
            'course_price_type' => 'free',
            'course_price'      => '',
        ]);
        $I->amOnPage($this->pureLdCourseUrl);
        $I->waitForElement('aside', 10);

        $I->see('Gratis', 'aside');
        $I->dontSee('Cursus kopen', 'aside');
    }

    /**
     * @test
     */
    public function paynowAccessSettingShowsPriceAndBuyCta(AcceptanceTester $I): void
    {
        $I->wantTo('see the price and Cursus kopen CTA when course access is set to buy now');

        $this->setLdSettings($I, [
            'course_price_type' => 'paynow',
            'course_price'      => '125',
        ]);
        $I->amOnPage($this->pureLdCourseUrl);
        $I->waitForElement('aside', 10);

        $I->see('€ 125,00', 'aside');
        $I->see('Cursus kopen', 'aside');
        $I->dontSee('Gratis', 'aside');
    }

    // =========================================================================
    // PURE-LD COURSE PAGE — completion awards + access duration details
    // =========================================================================

    /**
     * @test
     */
    public function completionAwardAndAccessSettingsShowInCursusdetails(AcceptanceTester $I): void
    {
        $I->wantTo('see points, required points and access duration in the Cursusdetails block');

        $this->setLdSettings($I, [
            'course_price_type'     => 'free',
            'course_points'         => '5',
            'course_points_enabled' => 'on',
            'course_points_access'  => '10',
            'expire_access'         => 'on',
            'expire_access_days'    => '30',
        ]);
        $I->amOnPage($this->pureLdCourseUrl);
        $I->waitForElement('aside', 10);

        $I->see('Cursusdetails', 'aside');
        $I->see('Punten na afronding', 'aside');
        $I->see('5 punten', 'aside');
        $I->see('Vereiste punten', 'aside');
        $I->see('10 punten', 'aside');
        $I->see('Toegangsduur', 'aside');
        $I->see('30 dagen', 'aside');
    }

    /**
     * @test
     */
    public function defaultSettingsHideCursusdetailsRows(AcceptanceTester $I): void
    {
        $I->wantTo('not see points or access-duration rows when no awards/expiration are configured');

        $this->setLdSettings($I, [
            'course_price_type' => 'free',
        ]);
        $I->amOnPage($this->pureLdCourseUrl);
        $I->waitForElement('aside', 10);

        $I->dontSee('Punten na afronding', 'aside');
        $I->dontSee('Vereiste punten', 'aside');
        $I->dontSee('Toegangsduur', 'aside');
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function grabCourseIdByTitlePrefix(AcceptanceTester $I, string $prefix): int
    {
        $courseId = (int) $I->grabFromDatabase($I->grabPrefixedTableNameFor('posts'), 'ID', [
            'post_type'        => 'sfwd-courses',
            'post_status'      => 'publish',
            'post_title LIKE'  => $prefix . '%',
        ]);
        \PHPUnit\Framework\Assert::assertGreaterThan(0, $courseId, 'Seed course not found: ' . $prefix);

        return $courseId;
    }

    private function courseUrl(AcceptanceTester $I, int $courseId): string
    {
        $slug = (string) $I->grabFromDatabase($I->grabPrefixedTableNameFor('posts'), 'post_name', ['ID' => $courseId]);

        return '/opleidingen/' . $slug . '/';
    }

    /**
     * @return array<int>
     */
    private function grabEditionIdsForCourse(AcceptanceTester $I, int $courseId): array
    {
        $ids = $I->grabColumnFromDatabase($I->grabPrefixedTableNameFor('postmeta'), 'post_id', [
            'meta_key'   => '_ntdst_course_id',
            'meta_value' => (string) $courseId,
        ]);

        $editionIds = [];
        foreach (array_map('intval', $ids) as $id) {
            $isEdition = $I->grabFromDatabase($I->grabPrefixedTableNameFor('posts'), 'ID', [
                'ID'          => $id,
                'post_type'   => 'vad_edition',
                'post_status' => 'publish',
            ]);
            if ((int) $isEdition > 0) {
                $editionIds[] = $id;
            }
        }

        return $editionIds;
    }

    private function setEditionStatus(AcceptanceTester $I, int $editionId, string $status): void
    {
        $I->updateInDatabase(
            $I->grabPrefixedTableNameFor('postmeta'),
            ['meta_value' => $status],
            ['post_id' => $editionId, 'meta_key' => '_ntdst_status'],
        );
    }

    /**
     * Replace the pure-LD course's `_sfwd-courses` settings with exactly the
     * given keys (un-prefixed, e.g. 'course_price_type'). Starting from a
     * clean array — not merging — keeps each test's setting set explicit.
     */
    private function setLdSettings(AcceptanceTester $I, array $settings): void
    {
        $prefixed = [];
        foreach ($settings as $key => $value) {
            $prefixed['sfwd-courses_' . $key] = $value;
        }

        $table = $I->grabPrefixedTableNameFor('postmeta');
        $I->dontHaveInDatabase($table, [
            'post_id'  => $this->pureLdCourseId,
            'meta_key' => '_sfwd-courses',
        ]);
        $I->haveInDatabase($table, [
            'post_id'    => $this->pureLdCourseId,
            'meta_key'   => '_sfwd-courses',
            'meta_value' => serialize($prefixed),
        ]);
    }
}
