<?php

declare(strict_types=1);

use Tests\Support\AcceptanceTester;

/**
 * Acceptance tests for Quote CPT admin screens
 *
 * Tests the WordPress admin UI for managing quotes.
 * Run: ddev exec vendor/bin/codecept run acceptance AdminQuoteCest --steps
 */
class AdminQuoteCest
{
    private ?int $adminId = null;

    public function _before(AcceptanceTester $I): void
    {
        // Get admin user ID
        $this->adminId = $I->grabAdminUserId();

        if (!$this->adminId) {
            throw new \RuntimeException('Admin user not found in database');
        }

        // Login as admin
        $I->loginAsUserId($this->adminId, '/wp/wp-admin/');
    }

    // =========================================================================
    // QUOTE LIST
    // =========================================================================

    /**
     * @group admin
     * @group quotes
     */
    public function canViewQuotesList(AcceptanceTester $I): void
    {
        $I->wantTo('view the quotes list in admin');

        $I->amOnPage('/wp/wp-admin/edit.php?post_type=vad_quote');

        $I->seeInCurrentUrl('post_type=vad_quote');
        $I->see('Offertes', 'h1');
        $I->seeElement('.wp-list-table');
        $I->dontSee('Fatal error');
    }

    /**
     * @group admin
     * @group quotes
     */
    public function quotesListShowsTable(AcceptanceTester $I): void
    {
        $I->wantTo('verify table is shown in quotes list');

        $I->amOnPage('/wp/wp-admin/edit.php?post_type=vad_quote');

        $I->seeElement('table.wp-list-table');
        $I->dontSee('Fatal error');
    }

    // =========================================================================
    // VIEW/EDIT QUOTE
    // =========================================================================

    /**
     * @group admin
     * @group quotes
     */
    public function canViewExistingQuote(AcceptanceTester $I): void
    {
        $I->wantTo('view an existing quote');

        // Get an existing quote from database
        $quoteId = $I->grabFromDatabase($I->grabPrefixedTableNameFor('posts'), 'ID', [
            'post_type' => 'vad_quote',
            'post_status' => 'publish',
        ]);

        if (!$quoteId) {
            // Skip if no quotes exist
            $I->amOnPage('/wp/wp-admin/edit.php?post_type=vad_quote');
            $I->seeElement('.wp-list-table');
            return;
        }

        // Go to edit page
        $I->amOnPage('/wp/wp-admin/post.php?post=' . $quoteId . '&action=edit');

        // Should see metaboxes
        $I->seeElement('#normal-sortables, #side-sortables');

        // No errors
        $I->dontSee('Fatal error');
        $I->dontSee('Warning:');
    }

    /**
     * @group admin
     * @group quotes
     * @group metabox
     */
    public function quoteEditPageLoads(AcceptanceTester $I): void
    {
        $I->wantTo('verify quote edit page loads correctly');

        // Get an existing quote
        $quoteId = $I->grabFromDatabase($I->grabPrefixedTableNameFor('posts'), 'ID', [
            'post_type' => 'vad_quote',
            'post_status' => 'publish',
        ]);

        if (!$quoteId) {
            // Skip if no quotes exist
            $I->amOnPage('/wp/wp-admin/edit.php?post_type=vad_quote');
            $I->see('Offertes', 'h1');
            return;
        }

        $I->amOnPage('/wp/wp-admin/post.php?post=' . $quoteId . '&action=edit');

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
     * @group quotes
     */
    public function canNavigateToQuotesList(AcceptanceTester $I): void
    {
        $I->wantTo('navigate to quotes list page');

        $I->amOnPage('/wp/wp-admin/edit.php?post_type=vad_quote');

        // Should be on quotes list
        $I->seeInCurrentUrl('post_type=vad_quote');
        $I->see('Offertes', 'h1');
        $I->dontSee('Fatal error');
    }
}
