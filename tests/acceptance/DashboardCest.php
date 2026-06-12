<?php

declare(strict_types=1);

use Tests\Support\AcceptanceTester;

/**
 * Acceptance tests for the course-card interaction on the dashboard.
 *
 * Helder Tij ruling (Stefan, 2026-06-12): cards render COLLAPSED by default
 * — the pre-redesign auto-expand-first behavior is gone on purpose. The
 * home tab uses flat panels (no expandable cards); expandable course-cards
 * live in the Afgerond section of the inschrijvingen tab.
 */
class DashboardCest
{
    /**
     * SCENARIO: expandable cards start collapsed and expand on click.
     *
     *   GIVEN: seed_student1 has completed enrollments (Afgerond cards)
     *   WHEN:  visiting /mijn-account/?tab=inschrijvingen
     *   THEN:  every expandable card is closed by default, and clicking a
     *          card's header opens it.
     */
    public function courseCardsStartCollapsedAndExpandOnClick(AcceptanceTester $I): void
    {
        $I->wantTo('verify course cards render collapsed and expand on click');

        $userId = (int) $I->grabFromDatabase($I->grabPrefixedTableNameFor('users'), 'ID', ['user_login' => 'seed_student1']);
        $I->loginAsUserId($userId, '/mijn-account/?tab=inschrijvingen');

        $I->waitForElement('section', 5);
        $I->wait(1); // Alpine boot

        $cardCount = (int) $I->executeJS("return document.querySelectorAll('[x-data^=\"expandable\"]').length;");
        \PHPUnit\Framework\Assert::assertGreaterThanOrEqual(1, $cardCount, 'expected at least 1 expandable course card');

        $anyOpen = (bool) $I->executeJS("
            return Array.from(document.querySelectorAll('[x-data^=\"expandable\"]'))
                .some((card) => Alpine.\$data(card).open);
        ");
        \PHPUnit\Framework\Assert::assertFalse($anyOpen, 'all cards must render collapsed (Helder Tij default)');

        $I->executeJS("
            const card = document.querySelectorAll('[x-data^=\"expandable\"]')[0];
            card.querySelector('button').click();
        ");
        $I->wait(1);

        $firstOpenAfterClick = (bool) $I->executeJS("
            const card = document.querySelectorAll('[x-data^=\"expandable\"]')[0];
            return Alpine.\$data(card).open;
        ");
        \PHPUnit\Framework\Assert::assertTrue($firstOpenAfterClick, 'card must open after clicking its header');
    }

    /**
     * SCENARIO: the dashboard home renders the active-enrollment panels
     * (flat, non-expandable in Helder Tij) without errors.
     */
    public function homeRendersEnrollmentPanels(AcceptanceTester $I): void
    {
        $I->wantTo('verify the dashboard home renders active enrollment panels');

        $userId = (int) $I->grabFromDatabase($I->grabPrefixedTableNameFor('users'), 'ID', ['user_login' => 'seed_student1']);
        $I->loginAsUserId($userId, '/mijn-account/');

        $I->waitForElement('main', 5);
        $I->see('Binnenkort');
        $I->dontSee('Fatal error');
    }
}
