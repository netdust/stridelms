<?php

declare(strict_types=1);

use Tests\Support\AcceptanceTester;

/**
 * User Dashboard E2E — "everything shows up where it should".
 *
 * Walks all 8 dashboard tabs (/mijn-account/?tab=…) as seed_student1 and
 * asserts the RIGHT content lands in the RIGHT place, not just that the tab
 * renders. Complements DashboardTabShakeoutCest (render/empty/fallback) and
 * DashboardCest (card auto-expand).
 *
 * Ground truth = the feature-matrix seeder (scripts/seed.php). seed_student1
 * is seeded with exactly:
 *  - confirmed registration on an OPEN edition  → "Bijscholing Bewegingsonderwijs"
 *    (future session 09:30 → home agenda + active inschrijvingen card)
 *  - waitlist registration on a FULL edition    → "Masterclass Mentale Veerkracht"
 *  - completed registration                      → "Motiverende Gespreksvoering"
 *  - confirmed registration on a past edition   → "Sportblessures Voorkomen"
 *  - one quote
 *  - no trajectory enrollment
 *
 * Titles are matched by stable prefix; IDs are never hardcoded. The Cest is
 * read-only — it mutates nothing, so no _after cleanup is needed.
 */
class DashboardE2ECest
{
    private const ACTIVE_COURSE    = 'Bijscholing Bewegingsonderwijs';
    private const WAITLIST_COURSE  = 'Masterclass Mentale Veerkracht';
    private const COMPLETED_COURSE = 'Motiverende Gespreksvoering';
    private const PAST_COURSE      = 'Sportblessures Voorkomen';

    private const NAV_LABELS = [
        'Home', 'Opleidingen', 'Trajecten', 'Offertes',
        'Meldingen', 'Downloads', 'Certificaten',
    ];

    private int $studentId;
    private string $firstName;

    public function _before(AcceptanceTester $I): void
    {
        $this->studentId = (int) $I->grabFromDatabase(
            $I->grabPrefixedTableNameFor('users'),
            'ID',
            ['user_login' => 'seed_student1'],
        );
        \PHPUnit\Framework\Assert::assertGreaterThan(0, $this->studentId, 'seed data must provide seed_student1');

        $this->firstName = (string) $I->grabFromDatabase(
            $I->grabPrefixedTableNameFor('usermeta'),
            'meta_value',
            ['user_id' => $this->studentId, 'meta_key' => 'first_name'],
        );
    }

    // =========================================================================
    // NAV + HOME
    // =========================================================================

    /**
     * @test
     */
    public function sidebarNavShowsAllTabsAndMarksActiveOne(AcceptanceTester $I): void
    {
        $I->wantTo('see every dashboard tab in the sidebar nav with the active one marked');

        $I->loginAsUserId($this->studentId, '/mijn-account/');
        $I->waitForElement('[aria-label="Dashboard navigatie"]', 10);

        foreach (self::NAV_LABELS as $label) {
            $I->see($label, '[aria-label="Dashboard navigatie"]');
        }

        // Home is the active item and its href carries no tab param.
        $activeHref = (string) $I->executeJS(
            "const a = document.querySelector('[aria-label=\"Dashboard navigatie\"] a[aria-current=\"page\"]'); return a ? a.href : '';"
        );
        \PHPUnit\Framework\Assert::assertStringNotContainsString('tab=', $activeHref, 'home must be the active nav item');

        // Switching tab moves the aria-current marker.
        $I->amOnPage('/mijn-account/?tab=offertes');
        $I->waitForElement('[aria-label="Dashboard navigatie"]', 10);
        $activeHref = (string) $I->executeJS(
            "const a = document.querySelector('[aria-label=\"Dashboard navigatie\"] a[aria-current=\"page\"]'); return a ? a.href : '';"
        );
        \PHPUnit\Framework\Assert::assertStringContainsString('tab=offertes', $activeHref, 'offertes must be the active nav item on its own tab');
    }

    /**
     * @test
     */
    public function homeShowsGreetingAgendaAndActiveEnrollment(AcceptanceTester $I): void
    {
        $I->wantTo('see my greeting, upcoming session and active course on the dashboard home');

        $I->loginAsUserId($this->studentId, '/mijn-account/');
        $I->waitForElement('main', 10);

        // Personal greeting (Goedemorgen/-middag/-avond varies with runtime).
        $I->see($this->firstName, 'h1');

        // Agenda: the confirmed registration's future session shows under
        // "Binnenkort". Read via textContent — Alpine keeps parts of the home
        // panels hidden until booted, so visible-text matching is flaky here.
        $I->see('Binnenkort');
        $agendaText = (string) $I->executeJS(
            "const h = Array.from(document.querySelectorAll('h2,h3')).find(el => el.textContent.includes('Binnenkort'));" .
            "return h ? h.closest('section').textContent : '';"
        );
        \PHPUnit\Framework\Assert::assertStringContainsString(self::ACTIVE_COURSE, $agendaText, 'upcoming agenda must list the active course session');
        \PHPUnit\Framework\Assert::assertStringNotContainsString(self::COMPLETED_COURSE, $agendaText, 'completed course must not appear in the upcoming agenda');

        $I->dontSee('Fatal error');
    }

    // =========================================================================
    // OPLEIDINGEN (inschrijvingen)
    // =========================================================================

    /**
     * @test
     */
    public function inschrijvingenSortsRegistrationsIntoTheRightSections(AcceptanceTester $I): void
    {
        $I->wantTo('see active, waitlist and completed registrations in their own sections');

        $I->loginAsUserId($this->studentId, '/mijn-account/?tab=inschrijvingen');
        $I->waitForElement('main', 10);
        $I->see('Opleidingen', 'h1');

        // Active + waitlist + past registrations are all present on the tab.
        $I->waitForText(self::ACTIVE_COURSE, 10);
        $I->see(self::WAITLIST_COURSE);
        $I->see(self::PAST_COURSE);

        // The completed-status registration lands under the (collapsed)
        // "Afgerond (n)" section — read textContent, the section body is
        // x-show-hidden until clicked. NOTE: only status=completed regs go
        // here. A confirmed reg on a past edition stays in the active list
        // BY DESIGN: it still carries pending post-course tasks (evaluation,
        // documents, approval) whose CTAs only render on active cards; the
        // completion flow flips it to status=completed, which moves it here.
        $I->see('Afgerond');
        $afgerondText = (string) $I->executeJS(
            "const h = Array.from(document.querySelectorAll('h2,h3')).find(el => el.textContent.includes('Afgerond'));" .
            "return h ? h.closest('section').textContent : '';"
        );
        \PHPUnit\Framework\Assert::assertStringContainsString(self::COMPLETED_COURSE, $afgerondText, 'completed registration must be in the Afgerond section');
        \PHPUnit\Framework\Assert::assertStringNotContainsString(self::ACTIVE_COURSE, $afgerondText, 'active registration must NOT be in the Afgerond section');

        $I->dontSee('Geen actieve opleidingen');
        $I->dontSee('Fatal error');
    }

    // =========================================================================
    // TRAJECTEN
    // =========================================================================

    /**
     * @test
     */
    public function trajectenShowsEmptyStateWhenNotEnrolled(AcceptanceTester $I): void
    {
        $I->wantTo('see the trajectory empty state for a student without trajectory enrollment');

        $I->loginAsUserId($this->studentId, '/mijn-account/?tab=trajecten');
        $I->waitForElement('main', 10);

        $I->see('Trajecten', 'h1');
        $I->see('Geen actieve trajecten');
        $I->dontSee('Fatal error');
    }

    // =========================================================================
    // CERTIFICATEN
    // =========================================================================

    /**
     * @test
     */
    public function certificatenListsCompletedEditions(AcceptanceTester $I): void
    {
        $I->wantTo('see completed editions on the certificates tab');

        $I->loginAsUserId($this->studentId, '/mijn-account/?tab=certificaten');
        $I->waitForElement('main', 10);

        $I->see('Certificaten', 'h1');
        $I->see(self::COMPLETED_COURSE);
        // Active enrollment has no certificate and must not appear here.
        $I->dontSee(self::ACTIVE_COURSE);
        $I->dontSee('Fatal error');
    }

    // =========================================================================
    // OFFERTES
    // =========================================================================

    /**
     * @test
     */
    public function offertesShowsTheSeededQuoteWithPriceBreakdown(AcceptanceTester $I): void
    {
        $I->wantTo('see my quote with its price breakdown on the offertes tab');

        $I->loginAsUserId($this->studentId, '/mijn-account/?tab=offertes');
        $I->waitForElement('main', 10);

        $I->see('Offertes', 'h1');
        $I->dontSee('Geen offertes');
        // Quote card anatomy: price breakdown labels.
        $I->see('Prijsoverzicht');
        $I->see('Subtotaal');
        $I->see('BTW (21%)');
        $I->see('Totaal');
        $I->dontSee('Fatal error');
    }

    // =========================================================================
    // MELDINGEN
    // =========================================================================

    /**
     * @test
     */
    public function meldingenRendersListOrEmptyState(AcceptanceTester $I): void
    {
        $I->wantTo('see the notifications tab render its list or empty state');

        $I->loginAsUserId($this->studentId, '/mijn-account/?tab=meldingen');
        $I->waitForElement('main', 10);

        $I->see('Meldingen', 'h1');
        // Either populated (mark-all-read control) or the explicit empty state —
        // never a half-rendered page.
        $state = (string) $I->executeJS(
            "return document.body.innerText.includes('Alles als gelezen markeren') ? 'list'" .
            " : (document.body.innerText.includes('Geen meldingen') ? 'empty' : 'broken');"
        );
        \PHPUnit\Framework\Assert::assertContains($state, ['list', 'empty'], 'meldingen must render its list or its empty state');
        $I->dontSee('Fatal error');
    }

    // =========================================================================
    // DOWNLOADS
    // =========================================================================

    /**
     * @test
     */
    public function downloadsGroupsCertificatesAndQuotes(AcceptanceTester $I): void
    {
        $I->wantTo('see the downloads tab grouped into certificate and quote sections');

        $I->loginAsUserId($this->studentId, '/mijn-account/?tab=downloads');
        $I->waitForElement('main', 10);

        $I->see('Downloads', 'h1');
        $I->see('Certificaten', 'h3');
        $I->see('Offertes', 'h3');
        $I->dontSee('Fatal error');
    }

    // =========================================================================
    // PROFIEL
    // =========================================================================

    /**
     * @test
     */
    public function profielShowsTheUsersOwnData(AcceptanceTester $I): void
    {
        $I->wantTo('see my own name and email on the profile tab');

        $displayName = (string) $I->grabFromDatabase(
            $I->grabPrefixedTableNameFor('users'),
            'display_name',
            ['ID' => $this->studentId],
        );
        $email = (string) $I->grabFromDatabase(
            $I->grabPrefixedTableNameFor('users'),
            'user_email',
            ['ID' => $this->studentId],
        );

        $I->loginAsUserId($this->studentId, '/mijn-account/?tab=profiel');
        $I->waitForElement('main', 10);

        $I->see('Profiel', 'h1');
        $I->see('Persoonlijke gegevens');
        $I->see($displayName);
        $I->see($email);
        $I->dontSee('Fatal error');
    }
}
