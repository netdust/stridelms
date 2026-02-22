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

        // Get admin user ID (user ID 1 is typically admin)
        $adminId = $I->grabFromDatabase('stride_users', 'ID', ['user_login' => 'admin']);

        if ($adminId) {
            $I->loginAsUserId((int) $adminId, '/wp/wp-admin/');
            $I->see('Dashboard');
        } else {
            // If no admin user exists, skip this test
            $I->comment('No admin user found - skipping admin dashboard test');
            $I->amOnPage('/');
            $I->seeElement('body');
        }
    }
}
