<?php

declare(strict_types=1);

use Tests\Support\AcceptanceTester;

/**
 * AF-4 shake-out (audit-remediation sprint, 2.2): catalog pages render the
 * same cards after batch hydration, and "Toon meer" pagination works through
 * the real Alpine + ntdstAPI + stride_catalog_page wire in a real browser.
 *
 * The exact query budget and the endpoint's page-boundary contract are
 * covered by the 2.2 integration tests (CatalogEndpointTest etc.).
 */
class CatalogShakeoutCest
{
    /**
     * SCENARIO: anonymous visitor sees server-rendered cards on /klassikaal.
     *
     *   THEN: at least one card, never more than the 24-card server cap.
     */
    public function klassikaalRendersCardsForAnonymous(AcceptanceTester $I): void
    {
        $I->wantTo('verify /klassikaal server-renders catalog cards for anonymous visitors');

        $I->amOnPage('/klassikaal');
        $I->waitForElement('[x-ref="grid"]', 10);
        $I->dontSee('Fatal error');

        $cards = (int) $I->executeJS("return document.querySelector('[x-ref=\"grid\"]').querySelectorAll('article').length;");
        \PHPUnit\Framework\Assert::assertGreaterThanOrEqual(1, $cards, 'klassikaal should render at least one card');
        \PHPUnit\Framework\Assert::assertLessThanOrEqual(24, $cards, 'server must render at most the 24-card cap');

        // Anonymous visitors never see the enrolled badge.
        $I->dontSee('Ingeschreven');
    }

    /**
     * SCENARIO: "Toon meer" loads the next page through the real wire
     * (AF-4 boundary: server cap + one past it, card neither dropped nor doubled).
     *
     *   GIVEN: /online has more items than the 24-card server cap
     *   WHEN:  clicking "Toon meer"
     *   THEN:  the remaining cards append; links stay unique (no dupes/drops).
     */
    public function onlineToonMeerAppendsNextPage(AcceptanceTester $I): void
    {
        $I->wantTo('verify Toon meer appends the next catalog page on /online');

        $I->amOnPage('/online');
        $I->waitForElement('[x-ref="grid"]', 10);

        $state = json_decode((string) $I->executeJS(
            "const el = document.querySelector('[x-ref=\"grid\"]').closest('[x-data]');" .
            "const d = Alpine.\$data(el); return JSON.stringify({shown: d.shown, total: d.total});"
        ), true);

        if (($state['total'] ?? 0) <= ($state['shown'] ?? 0)) {
            // Catalog shrank below the cap — pagination not reachable on this dataset.
            \PHPUnit\Framework\Assert::markTestSkipped('online catalog no longer exceeds the server cap; Toon meer not reachable');
        }

        \PHPUnit\Framework\Assert::assertSame(24, (int) $state['shown'], 'server should render exactly the 24-card cap when more items exist');

        $before = (int) $I->executeJS("return document.querySelector('[x-ref=\"grid\"]').querySelectorAll('article').length;");

        // Click the visible Toon meer button (real Alpine click → ntdstAPI →
        // endpoint). Wait for it to be interactive first — Alpine must have
        // booted before the @click listener exists.
        $I->waitForElementVisible('//button[contains(., "Toon meer")]', 10);
        // JS-click: the sticky header can overlap the button's viewport
        // position, making a native WebDriver click "not interactable".
        $I->executeJS(
            "const btn = Array.from(document.querySelectorAll('button')).find(b => b.textContent.includes('Toon meer') && b.offsetParent !== null); btn.click();"
        );

        $total = (int) $state['total'];
        $I->waitForJS("return document.querySelector('[x-ref=\"grid\"]').querySelectorAll('article').length >= {$total};", 10);

        $after = (int) $I->executeJS("return document.querySelector('[x-ref=\"grid\"]').querySelectorAll('article').length;");
        \PHPUnit\Framework\Assert::assertSame($total, $after, 'after Toon meer all items should be shown');
        \PHPUnit\Framework\Assert::assertGreaterThan($before, $after);

        // Boundary card neither dropped nor doubled: each card links to one
        // distinct destination (a card carries several anchors to the same
        // URL, so compare unique destinations against the card count).
        $unique = (int) $I->executeJS(
            "const links = Array.from(document.querySelector('[x-ref=\"grid\"]').querySelectorAll('article a[href]')).map(a => a.href);" .
            "return new Set(links).size;"
        );
        \PHPUnit\Framework\Assert::assertSame($after, $unique, 'no card may appear twice after pagination (boundary not doubled or dropped)');

        $errorState = $I->executeJS(
            "const el = document.querySelector('[x-ref=\"grid\"]').closest('[x-data]'); return Alpine.\$data(el).error;"
        );
        \PHPUnit\Framework\Assert::assertFalse((bool) $errorState, 'pagination should complete without the error state');
    }

    /**
     * SCENARIO: logged-in learner sees the enrolled badge (AF-4 isEnrolled branch).
     */
    public function loggedInStudentSeesEnrolledBadge(AcceptanceTester $I): void
    {
        $I->wantTo('verify a logged-in learner sees the Ingeschreven badge on /klassikaal');

        $userId = (int) $I->grabFromDatabase($I->grabPrefixedTableNameFor('users'), 'ID', ['user_login' => 'seed_student1']);
        $I->loginAsUserId($userId, '/klassikaal');
        $I->waitForElement('[x-ref="grid"]', 10);
        $I->see('Ingeschreven');
    }
}
