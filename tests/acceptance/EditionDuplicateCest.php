<?php

declare(strict_types=1);

use Tests\Support\AcceptanceTester;

/**
 * Acceptance: admin row-action duplicates an edition.
 *
 * Run: ddev exec vendor/bin/codecept run acceptance EditionDuplicateCest --steps
 */
class EditionDuplicateCest
{
    private ?int $adminId = null;

    public function _before(AcceptanceTester $I): void
    {
        $this->adminId = $I->grabAdminUserId();
        if (!$this->adminId) {
            throw new \RuntimeException('Admin user not found in database');
        }
        $I->loginAsUserId($this->adminId, '/wp/wp-admin/');
    }

    public function _after(AcceptanceTester $I): void
    {
        // Drop any "(kopie)" leftovers from this run.
        $I->dontHaveInDatabase($I->grabPrefixedTableNameFor('posts'), [
            'post_type'       => 'vad_edition',
            'post_title LIKE' => '% (kopie)',
        ]);
    }

    public function canDuplicateAnEditionFromTheList(AcceptanceTester $I): void
    {
        $I->amOnPage('/wp/wp-admin/edit.php?post_type=vad_edition');

        // Reveal row-actions globally — WP only shows them on :hover, and
        // Selenium's CSS :hover is unreliable. Force them visible via CSS so
        // we can interact with the link.
        $I->seeElement('table.wp-list-table tr.type-vad_edition');
        $I->executeJS(
            "var s=document.createElement('style');" .
            "s.textContent='.row-actions{left:auto !important;position:static !important;" .
            "opacity:1 !important;visibility:visible !important;height:auto !important;}';" .
            "document.head.appendChild(s);"
        );

        // Click the first Dupliceren link directly via its dedicated class.
        $I->click('table.wp-list-table tr.type-vad_edition .row-actions .stride_duplicate a');

        // Should land on the new draft's edit screen.
        $I->seeInCurrentUrl('post.php');
        $I->seeInCurrentUrl('action=edit');

        // Title field should carry the "(kopie)" suffix from the duplicator.
        $title = (string) $I->grabValueFrom('#title');
        \PHPUnit\Framework\Assert::assertStringEndsWith(
            '(kopie)',
            $title,
            'Duplicated edition title should end with "(kopie)"'
        );

        // Confirm we landed on a draft (hidden post_status field on the edit form).
        $I->seeInSource('id="hidden_post_status" value="draft"');
    }
}
