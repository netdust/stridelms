<?php

declare(strict_types=1);

use Tests\Support\AcceptanceTester;

class SmokeTestCest
{
    public function frontPageLoads(AcceptanceTester $I): void
    {
        $I->wantTo('verify the front page loads without errors');
        $I->amOnPage('/');
        $I->seeElement('body');
        $I->dontSee('Fatal error');
    }

    public function adminDashboardLoads(AcceptanceTester $I): void
    {
        $I->wantTo('verify admin dashboard loads for admin user');

        $adminId = $I->grabAdminUserId();
        if (!$adminId) {
            throw new \RuntimeException('No administrator account found in database');
        }

        $I->loginAsUserId($adminId, '/wp/wp-admin/');
        $I->see('Dashboard');
    }
}
