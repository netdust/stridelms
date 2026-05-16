<?php

declare(strict_types=1);

use Tests\Support\AcceptanceTester;

/**
 * Acceptance tests for trajectory detail page rendering.
 */
class TrajectoryCest
{
    /**
     * SCENARIO: Trajectory detail course card expands on click.
     *
     *   GIVEN: a published trajectory with linked courses
     *   WHEN:  visiting /trajecten/{slug}/ and clicking the first course card header
     *   THEN:  the card body becomes visible.
     */
    public function courseCardOnTrajectoryDetailExpandsOnClick(AcceptanceTester $I): void
    {
        $I->wantTo('verify trajectory detail course cards expand on click');

        // Find any published trajectory slug
        $trajectoryId = (int) $I->grabFromDatabase('stride_posts', 'ID', [
            'post_type'   => 'vad_trajectory',
            'post_status' => 'publish',
        ]);
        if ($trajectoryId === 0) {
            $I->comment('No published trajectories — skipping trajectory acceptance check.');
            return;
        }
        $slug = (string) $I->grabFromDatabase('stride_posts', 'post_name', ['ID' => $trajectoryId]);

        $I->amOnPage('/trajecten/' . $slug . '/');

        // Page may not have any course cards if trajectory has no linked courses
        // Wait briefly for expandables to render; if none present, skip
        $I->wait(2);
        $cardCount = $I->executeJS("return document.querySelectorAll('[x-data^=\"expandable\"]').length;");
        if ($cardCount === 0) {
            $I->comment('Trajectory has no course cards — skipping expand check.');
            return;
        }

        // Collapsed state confirmed
        $firstOpen = $I->executeJS("
            const card = document.querySelectorAll('[x-data^=\"expandable\"]')[0];
            return Alpine.\$data(card).open;
        ");
        \PHPUnit\Framework\Assert::assertFalse($firstOpen, 'trajectory cards should start collapsed');

        // Click to expand
        $I->executeJS("
            const card = document.querySelectorAll('[x-data^=\"expandable\"]')[0];
            card.querySelector('button').click();
        ");
        $I->wait(1);

        $openAfterClick = $I->executeJS("
            const card = document.querySelectorAll('[x-data^=\"expandable\"]')[0];
            return Alpine.\$data(card).open;
        ");
        \PHPUnit\Framework\Assert::assertTrue($openAfterClick, 'trajectory card should be open after clicking');
    }
}
