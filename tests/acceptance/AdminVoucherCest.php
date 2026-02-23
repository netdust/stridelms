<?php

declare(strict_types=1);

use Tests\Support\AcceptanceTester;

/**
 * Acceptance tests for Voucher CPT admin screens
 *
 * Tests the WordPress admin UI for managing vouchers.
 * Run: ddev exec vendor/bin/codecept run acceptance AdminVoucherCest --steps
 */
class AdminVoucherCest
{
    private ?int $adminId = null;

    public function _before(AcceptanceTester $I): void
    {
        // Get admin user ID
        $this->adminId = (int) $I->grabFromDatabase('stride_users', 'ID', ['user_login' => 'admin']);

        if (!$this->adminId) {
            $I->fail('Admin user not found in database');
        }

        // Login as admin
        $I->loginAsUserId($this->adminId, '/wp/wp-admin/');
    }

    // =========================================================================
    // VOUCHER LIST
    // =========================================================================

    /**
     * @group admin
     * @group vouchers
     */
    public function canViewVouchersList(AcceptanceTester $I): void
    {
        $I->wantTo('view the vouchers list in admin');

        $I->amOnPage('/wp/wp-admin/edit.php?post_type=vad_voucher');

        $I->seeInCurrentUrl('post_type=vad_voucher');
        $I->see('Vouchers', 'h1');
        $I->seeElement('.wp-list-table');
        $I->dontSee('Fatal error');
    }

    /**
     * @group admin
     * @group vouchers
     */
    public function vouchersListShowsCustomColumns(AcceptanceTester $I): void
    {
        $I->wantTo('verify custom columns are shown in vouchers list');

        $I->amOnPage('/wp/wp-admin/edit.php?post_type=vad_voucher');

        $I->seeElement('table.wp-list-table');
        // Check for expected column
        $I->see('Code', 'thead');
        $I->dontSee('Fatal error');
    }

    // =========================================================================
    // CREATE VOUCHER
    // =========================================================================

    /**
     * @group admin
     * @group vouchers
     */
    public function canAccessNewVoucherPage(AcceptanceTester $I): void
    {
        $I->wantTo('access the new voucher page');

        $I->amOnPage('/wp/wp-admin/post-new.php?post_type=vad_voucher');

        $I->seeInCurrentUrl('post_type=vad_voucher');
        $I->seeElement('#title'); // Post title field (voucher code)
        $I->seeElement('#publish'); // Publish button
        $I->dontSee('Fatal error');
    }

    /**
     * @group admin
     * @group vouchers
     */
    public function newVoucherPageShowsMetaboxes(AcceptanceTester $I): void
    {
        $I->wantTo('verify metaboxes are displayed on new voucher page');

        $I->amOnPage('/wp/wp-admin/post-new.php?post_type=vad_voucher');

        // Should see metabox containers
        $I->seeElement('#normal-sortables, #side-sortables, #advanced-sortables');

        // Should not see any PHP errors
        $I->dontSee('Fatal error');
        $I->dontSee('Warning:');
    }

    /**
     * @group admin
     * @group vouchers
     */
    public function canCreateNewVoucher(AcceptanceTester $I): void
    {
        $I->wantTo('create a new voucher');

        $I->amOnPage('/wp/wp-admin/post-new.php?post_type=vad_voucher');

        // Fill in voucher code as title
        $voucherCode = 'TEST' . strtoupper(substr(md5((string) time()), 0, 6));
        $I->fillField('#title', $voucherCode);

        // Click publish
        $I->click('#publish');

        // Wait for save
        $I->waitForElement('.notice-success, #message.updated', 10);

        // Verify voucher was created (visible in admin notice)
        $I->see('Post published', '.notice');

        // Should be on the edit page now (not new page)
        $I->seeInCurrentUrl('action=edit');
    }

    // =========================================================================
    // EDIT VOUCHER
    // =========================================================================

    /**
     * @group admin
     * @group vouchers
     */
    public function canEditExistingVoucher(AcceptanceTester $I): void
    {
        $I->wantTo('edit an existing voucher');

        // Get an existing voucher from database
        $voucherId = $I->grabFromDatabase('stride_posts', 'ID', [
            'post_type' => 'vad_voucher',
            'post_status' => 'publish',
        ]);

        if (!$voucherId) {
            // Skip if no vouchers exist
            $I->amOnPage('/wp/wp-admin/edit.php?post_type=vad_voucher');
            $I->seeElement('.wp-list-table');
            return;
        }

        // Go to edit page
        $I->amOnPage('/wp/wp-admin/post.php?post=' . $voucherId . '&action=edit');

        // Should see metaboxes
        $I->seeElement('#normal-sortables, #side-sortables');

        // No errors
        $I->dontSee('Fatal error');
    }

    /**
     * @group admin
     * @group vouchers
     * @group metabox
     */
    public function voucherMetaboxesRender(AcceptanceTester $I): void
    {
        $I->wantTo('verify voucher metaboxes render correctly');

        // Get an existing voucher
        $voucherId = $I->grabFromDatabase('stride_posts', 'ID', [
            'post_type' => 'vad_voucher',
            'post_status' => 'publish',
        ]);

        if (!$voucherId) {
            // Skip if no vouchers exist
            $I->amOnPage('/wp/wp-admin/edit.php?post_type=vad_voucher');
            $I->see('Vouchers', 'h1');
            return;
        }

        $I->amOnPage('/wp/wp-admin/post.php?post=' . $voucherId . '&action=edit');

        // Should render without errors
        $I->dontSee('Fatal error');
        $I->dontSee('Warning:');

        // Should have form
        $I->seeElement('form#post');
    }

    // =========================================================================
    // NAVIGATION
    // =========================================================================

    /**
     * @group admin
     * @group vouchers
     */
    public function canNavigateToVouchersList(AcceptanceTester $I): void
    {
        $I->wantTo('navigate to vouchers list page');

        $I->amOnPage('/wp/wp-admin/edit.php?post_type=vad_voucher');

        // Should be on vouchers list
        $I->seeInCurrentUrl('post_type=vad_voucher');
        $I->see('Vouchers', 'h1');
        $I->dontSee('Fatal error');
    }
}
