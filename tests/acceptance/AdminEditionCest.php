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
    private ?int $editionWithSessionsId = null;

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

    /**
     * Strip artefacts the tests could have created.
     *  - canAddSession adds a vad_session via the admin UI without a title
     *    (defaults submit). Empty-title sessions = test residue.
     *  - canCreateNewEdition creates 'Test Edition <unix-ts>'. Same pattern.
     * Without this teardown the row counts grow monotonically across runs
     * and pollute voucher prorating math + admin Edities listing.
     */
    public function _after(AcceptanceTester $I): void
    {
        $I->dontHaveInDatabase($I->grabPrefixedTableNameFor('posts'), [
            'post_type'  => 'vad_session',
            'post_title' => '',
        ]);
        $I->dontHaveInDatabase($I->grabPrefixedTableNameFor('posts'), [
            'post_type'        => 'vad_edition',
            'post_title LIKE'  => 'Test Edition %',
        ]);
    }

    /**
     * Force a WP admin metabox open. WP persists per-user collapse state in
     * usermeta, so a metabox can land closed for the admin user on the picked
     * edition — making inner controls invisible to getVisibleText() / unable
     * to interact with via fillField().
     */
    private function openMetabox(AcceptanceTester $I, string $metaboxId): void
    {
        $I->executeJS(sprintf(
            'const box = document.getElementById(%s); if (box) { box.classList.remove("closed"); }',
            json_encode($metaboxId)
        ));
    }

    /**
     * Resolve a published edition that has at least two sessions. Hardcoding
     * IDs in tests is brittle — seed data changes and old IDs are dropped on
     * reseed. Helper method lives in Tests\Support\Helper\Acceptance.
     */
    private function editionWithSessions(AcceptanceTester $I): int
    {
        if ($this->editionWithSessionsId !== null) {
            return $this->editionWithSessionsId;
        }

        $editionId = $I->grabEditionWithMinSessions(2);

        if (!$editionId) {
            throw new \RuntimeException('No published edition with 2+ sessions found in seed data');
        }

        return $this->editionWithSessionsId = $editionId;
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

        // Verify edition was created (locale-independent check)
        $I->seeElement('.notice-success, #message.updated');

        // Verify in database
        $I->seeInDatabase($I->grabPrefixedTableNameFor('posts'), [
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
        $editionId = $I->grabFromDatabase($I->grabPrefixedTableNameFor('posts'), 'ID', [
            'post_type' => 'vad_edition',
            'post_status' => 'publish',
        ]);

        if (!$editionId) {
            throw new \RuntimeException('No published edition found in database');
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
        $editionId = $I->grabFromDatabase($I->grabPrefixedTableNameFor('posts'), 'ID', [
            'post_type' => 'vad_edition',
            'post_status' => 'publish',
        ]);

        if (!$editionId) {
            throw new \RuntimeException('No published edition found in database');
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

    // =========================================================================
    // SESSIONS
    // =========================================================================

    /**
     * SCENARIO: Sessions metabox shows existing sessions
     *   GIVEN: I am editing an edition with sessions
     *   WHEN: The page loads
     *   THEN: I see session rows with date, time, and type
     *
     * @group admin
     * @group editions
     * @group sessions
     */
    public function sessionsMetaboxShowsData(AcceptanceTester $I): void
    {
        $I->wantTo('verify sessions metabox shows existing session data');

        $editionId = $this->editionWithSessions($I);
        $I->amOnPage('/wp/wp-admin/post.php?post=' . $editionId . '&action=edit');
        $I->see('Sessies');
        $I->waitForElement('.session-row', 5);

        $sessionCount = $I->executeJS(
            'return document.querySelectorAll(".session-row").length'
        );
        \PHPUnit\Framework\Assert::assertGreaterThanOrEqual(
            2,
            (int) $sessionCount,
            'Edition should have at least 2 sessions'
        );
    }

    /**
     * SCENARIO: Add a session via the admin UI
     *   GIVEN: I am editing an existing edition
     *   WHEN: I click "Sessie toevoegen", then click "Opslaan"
     *   THEN: The session count increases
     *
     * @group admin
     * @group editions
     * @group sessions
     */
    public function canAddSession(AcceptanceTester $I): void
    {
        $I->wantTo('add a session to an edition via the admin UI');

        $editionId = $this->editionWithSessions($I);
        $I->amOnPage('/wp/wp-admin/post.php?post=' . $editionId . '&action=edit');
        $I->dontSee('Fatal error');

        $sessionsBefore = $I->executeJS(
            'return document.querySelectorAll(".session-row").length'
        );

        // Click "Sessie toevoegen" and save with defaults
        $I->click('Sessie toevoegen');
        $I->wait(1);

        // Click the session form's save button (inside the add row)
        $I->executeJS(
            'document.querySelector(".stride-session-add-row button.button-primary, ' .
            'tr.session-add-row button.button-primary, ' .
            'button.stride-save-session")?.click() || ' .
            '(() => { const btns = document.querySelectorAll("button"); ' .
            'for (const b of btns) { if (b.textContent.trim() === "Opslaan") { b.click(); break; } } })()'
        );
        $I->wait(4);

        $sessionsAfter = $I->executeJS(
            'return document.querySelectorAll(".session-row").length'
        );

        \PHPUnit\Framework\Assert::assertGreaterThan(
            (int) $sessionsBefore,
            (int) $sessionsAfter,
            'Session count should increase after adding'
        );
    }

    // =========================================================================
    // NOTES
    // =========================================================================

    /**
     * SCENARIO: Notes metabox renders with input form
     *   GIVEN: I am editing an edition
     *   WHEN: The page loads
     *   THEN: I see the Notities heading, text area, and add button
     *
     * @group admin
     * @group editions
     * @group notes
     */
    public function notesMetaboxRendersCorrectly(AcceptanceTester $I): void
    {
        $I->wantTo('verify the notes metabox renders with input form');

        $editionId = $this->editionWithSessions($I);
        $I->amOnPage('/wp/wp-admin/post.php?post=' . $editionId . '&action=edit');
        $I->see('Notities');

        // WP persists per-user metabox collapse state. Force the notes box open
        // so its inner controls (e.g. the "Notitie toevoegen" button) become
        // visible to getVisibleText().
        $this->openMetabox($I, 'stride_edition_notes');

        $I->see('Notitie toevoegen');

        // Hidden field exists in DOM (not visible to seeElement)
        $hasField = $I->executeJS('return !!document.getElementById("stride_notes_data")');
        \PHPUnit\Framework\Assert::assertTrue((bool) $hasField, 'Notes hidden field should exist');
    }

    /**
     * SCENARIO: Add a note via the admin UI
     *   GIVEN: I am editing an edition
     *   WHEN: I type a note and click "Notitie toevoegen"
     *   THEN: The note appears in the timeline and in the hidden field
     *
     * @group admin
     * @group editions
     * @group notes
     */
    public function canAddNote(AcceptanceTester $I): void
    {
        $I->wantTo('add a note to an edition via the admin UI');

        $editionId = $this->editionWithSessions($I);
        $I->amOnPage('/wp/wp-admin/post.php?post=' . $editionId . '&action=edit');
        $I->see('Notities');

        $this->openMetabox($I, 'stride_edition_notes');

        $I->fillField('textarea[placeholder*="notitie"]', 'Acceptance test note');
        $I->click('Notitie toevoegen');
        $I->wait(1);

        // Note should appear in the timeline
        $I->see('Acceptance test note');

        // Hidden field should contain the note data
        $notesJson = $I->executeJS(
            'return document.getElementById("stride_notes_data")?.value || "[]"'
        );
        $notes = json_decode($notesJson, true);
        \PHPUnit\Framework\Assert::assertNotEmpty($notes, 'Notes data should not be empty');

        $lastNote = end($notes);
        \PHPUnit\Framework\Assert::assertSame('Acceptance test note', $lastNote['content']);
    }
}
