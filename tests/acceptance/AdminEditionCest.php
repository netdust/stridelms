<?php

declare(strict_types=1);

use Tests\Support\AcceptanceTester;

/**
 * Acceptance tests for Edition CPT admin screens
 *
 * Tests the WordPress admin UI for managing editions.
 * Run: ddev exec vendor/bin/codecept run acceptance AdminEditionCest --steps
 */
class AdminEditionCest
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
    // EDITION LIST
    // =========================================================================

    /**
     * @group admin
     * @group editions
     */
    public function canViewEditionsList(AcceptanceTester $I): void
    {
        $I->wantTo('view the editions list in admin');

        $I->amOnPage('/wp/wp-admin/edit.php?post_type=vad_edition');

        $I->seeInCurrentUrl('post_type=vad_edition');
        $I->see('Edities', 'h1');
        $I->seeElement('.wp-list-table');
        $I->dontSee('Fatal error');
    }

    /**
     * @group admin
     * @group editions
     */
    public function editionsListShowsCustomColumns(AcceptanceTester $I): void
    {
        $I->wantTo('verify custom columns are shown in editions list');

        $I->amOnPage('/wp/wp-admin/edit.php?post_type=vad_edition');

        $I->seeElement('table.wp-list-table');
        // Check for expected column (Editie is the edition column)
        $I->see('Editie', 'thead');
        $I->dontSee('Fatal error');
    }

    // =========================================================================
    // CREATE EDITION
    // =========================================================================

    /**
     * @group admin
     * @group editions
     */
    public function canAccessNewEditionPage(AcceptanceTester $I): void
    {
        $I->wantTo('access the new edition page');

        $I->amOnPage('/wp/wp-admin/post-new.php?post_type=vad_edition');

        $I->seeInCurrentUrl('post_type=vad_edition');
        $I->seeElement('#title'); // Post title field
        $I->seeElement('#publish'); // Publish button
        $I->dontSee('Fatal error');
    }

    /**
     * @group admin
     * @group editions
     */
    public function newEditionPageShowsMetaboxes(AcceptanceTester $I): void
    {
        $I->wantTo('verify metaboxes are displayed on new edition page');

        $I->amOnPage('/wp/wp-admin/post-new.php?post_type=vad_edition');

        // Should see metabox containers
        $I->seeElement('#normal-sortables, #side-sortables, #advanced-sortables');

        // Should not see any PHP errors
        $I->dontSee('Fatal error');
        $I->dontSee('Warning:');
    }

    /**
     * @group admin
     * @group editions
     */
    public function canCreateNewEdition(AcceptanceTester $I): void
    {
        $I->wantTo('create a new edition');

        $I->amOnPage('/wp/wp-admin/post-new.php?post_type=vad_edition');

        // Fill in title
        $editionTitle = 'Test Edition ' . time();
        $I->fillField('#title', $editionTitle);

        // Click publish
        $I->click('#publish');

        // Wait for save
        $I->waitForElement('.notice-success, #message.updated', 10);

        // Verify edition was created
        $I->see('Post published', '.notice');

        // Verify in database
        $I->seeInDatabase('stride_posts', [
            'post_title' => $editionTitle,
            'post_type' => 'vad_edition',
            'post_status' => 'publish',
        ]);
    }

    // =========================================================================
    // EDIT EDITION
    // =========================================================================

    /**
     * @group admin
     * @group editions
     */
    public function canEditExistingEdition(AcceptanceTester $I): void
    {
        $I->wantTo('edit an existing edition');

        // Get an existing edition from the database
        $editionId = $I->grabFromDatabase('stride_posts', 'ID', [
            'post_type' => 'vad_edition',
            'post_status' => 'publish',
        ]);

        if (!$editionId) {
            $I->fail('No published edition found in database');
        }

        // Go to edit page
        $I->amOnPage('/wp/wp-admin/post.php?post=' . $editionId . '&action=edit');

        // Should see the edit form
        $I->seeElement('#title');
        $I->seeElement('form#post');

        // Should see metaboxes
        $I->seeElement('#normal-sortables, #side-sortables');

        // No errors
        $I->dontSee('Fatal error');
    }

    /**
     * @group admin
     * @group editions
     * @group metabox
     */
    public function editionMetaboxesRender(AcceptanceTester $I): void
    {
        $I->wantTo('verify edition metaboxes render correctly');

        // Get an existing edition
        $editionId = $I->grabFromDatabase('stride_posts', 'ID', [
            'post_type' => 'vad_edition',
            'post_status' => 'publish',
        ]);

        if (!$editionId) {
            $I->fail('No published edition found in database');
        }

        $I->amOnPage('/wp/wp-admin/post.php?post=' . $editionId . '&action=edit');

        // Should see metabox content without errors
        $I->dontSee('Fatal error');
        $I->dontSee('Warning:');

        // Look for form
        $I->seeElement('form#post');
    }

    // =========================================================================
    // NAVIGATION
    // =========================================================================

    /**
     * @group admin
     * @group editions
     */
    public function canNavigateToEditionsList(AcceptanceTester $I): void
    {
        $I->wantTo('navigate to editions list page');

        $I->amOnPage('/wp/wp-admin/edit.php?post_type=vad_edition');

        // Should be on editions list
        $I->seeInCurrentUrl('post_type=vad_edition');
        $I->see('Edities', 'h1');
        $I->dontSee('Fatal error');
    }
}
