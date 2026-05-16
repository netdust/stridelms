<?php

declare(strict_types=1);

use Tests\Support\AcceptanceTester;

/**
 * Acceptance tests for the unified course-card on the dashboard.
 */
class DashboardCest
{
    /**
     * SCENARIO: Dashboard home Opleidingen — first card auto-expands.
     *
     *   GIVEN: seed_student1 has at least 2 active enrollments
     *   WHEN:  visiting /mijn-account/ (tab=home)
     *   THEN:  the first course card body is visible without clicking;
     *          the second card body is hidden until clicked.
     */
    public function courseCardOnHomeAutoExpandsFirst(AcceptanceTester $I): void
    {
        $I->wantTo('verify the first course card auto-expands on the dashboard home');

        // Login as seed_student1 (existing seed user, multiple enrollments)
        $userId = (int) $I->grabFromDatabase('stride_users', 'ID', ['user_login' => 'seed_student1']);
        $I->loginAsUserId($userId, '/mijn-account/');

        $I->waitForElement('section', 5);

        // Find all course cards inside the Opleidingen section.
        // Each card uses x-data="expandable(...)".
        $cardCount = $I->executeJS("return document.querySelectorAll('[x-data^=\"expandable\"]').length;");
        \PHPUnit\Framework\Assert::assertGreaterThanOrEqual(2, $cardCount, 'expected at least 2 course cards for this test');

        // First card body must be visible (open:true)
        $firstOpen = $I->executeJS("
            const card = document.querySelectorAll('[x-data^=\"expandable\"]')[0];
            return Alpine.\$data(card).open;
        ");
        \PHPUnit\Framework\Assert::assertTrue((bool) $firstOpen, 'first card should be open by default');

        // Second card body must be hidden (open:false)
        $secondOpen = $I->executeJS("
            const card = document.querySelectorAll('[x-data^=\"expandable\"]')[1];
            return Alpine.\$data(card).open;
        ");
        \PHPUnit\Framework\Assert::assertFalse((bool) $secondOpen, 'second card should be closed by default');

        // Click the second card's header — body becomes visible
        $I->executeJS("
            const card = document.querySelectorAll('[x-data^=\"expandable\"]')[1];
            card.querySelector('button').click();
        ");
        $I->wait(1);

        $secondOpenAfterClick = $I->executeJS("
            const card = document.querySelectorAll('[x-data^=\"expandable\"]')[1];
            return Alpine.\$data(card).open;
        ");
        \PHPUnit\Framework\Assert::assertTrue((bool) $secondOpenAfterClick, 'second card should be open after clicking its header');
    }

    /**
     * SCENARIO: Mijn opleidingen tab — first active card auto-expands.
     *
     *   GIVEN: seed_student1 has at least 1 active classroom edition
     *   WHEN:  visiting /mijn-account/?tab=inschrijvingen
     *   THEN:  the first card in Klassikale opleidingen is expanded.
     */
    public function courseCardOnInschrijvingenTabAutoExpandsFirst(AcceptanceTester $I): void
    {
        $I->wantTo('verify the first card on inschrijvingen tab auto-expands');

        $userId = (int) $I->grabFromDatabase('stride_users', 'ID', ['user_login' => 'seed_student1']);
        $I->loginAsUserId($userId, '/mijn-account/?tab=inschrijvingen');

        $I->waitForElement('section', 5);

        $firstOpen = $I->executeJS("
            const cards = document.querySelectorAll('[x-data^=\"expandable\"]');
            if (cards.length === 0) return null;
            return Alpine.\$data(cards[0]).open;
        ");
        \PHPUnit\Framework\Assert::assertTrue($firstOpen, 'first card on inschrijvingen tab should be open by default');
    }
}
