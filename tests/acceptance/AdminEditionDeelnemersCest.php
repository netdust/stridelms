<?php

declare(strict_types=1);

use Tests\Support\AcceptanceTester;

/**
 * Acceptance tests for the deelnemers panel view-info modals.
 *
 * Run: ddev exec vendor/bin/codecept run acceptance AdminEditionDeelnemersCest --steps
 */
class AdminEditionDeelnemersCest
{
    private ?int $adminId = null;
    private ?int $editionId = null;

    public function _before(AcceptanceTester $I): void
    {
        $this->adminId = (int) $I->grabFromDatabase('stride_users', 'ID', ['user_login' => 'admin']);
        if (!$this->adminId) {
            $I->fail('Admin user not found in database');
        }
        $I->loginAsUserId($this->adminId, '/wp/wp-admin/');
    }

    private function editionWithRegistrations(AcceptanceTester $I): int
    {
        if ($this->editionId !== null) {
            return $this->editionId;
        }
        $id = $I->grabEditionWithRegistrations();
        if (!$id) {
            $I->fail('No published edition with registrations found in seed data');
        }
        return $this->editionId = $id;
    }

    private function openRegistrationMetabox(AcceptanceTester $I): void
    {
        // WP persists per-user metabox collapse state; ensure the deelnemers
        // metabox is open so its inner table is interactable.
        $I->executeJS('document.querySelectorAll(".closed").forEach(b => b.classList.remove("closed"));');
    }

    /**
     * @group admin
     * @group editions
     * @group deelnemers
     */
    public function adminCanOpenInschrijvingsgegevensModal(AcceptanceTester $I): void
    {
        $I->wantTo('open the enrollment-data modal from the deelnemers panel');

        $editionId = $this->editionWithRegistrations($I);
        $I->amOnPage('/wp/wp-admin/post.php?post=' . $editionId . '&action=edit');
        $this->openRegistrationMetabox($I);

        $I->waitForElementVisible('.stride-registration-table', 5);
        $I->seeElement('.stride-view-enrollment');

        $I->click('.stride-registration-table tbody .stride-view-enrollment');
        // Wait for the AJAX response to populate the modal content — the
        // modal element itself opens immediately with a skeleton, so wait
        // on the rendered section instead of just on the modal being visible.
        $I->waitForElement('.stride-modal-section[data-section="form"]', 10);
        $I->see('Inschrijvingsformulier', '#stride-registration-modal');

        $I->click('.stride-modal-close');
        $I->waitForElementNotVisible('.stride-modal-section[data-section="form"]', 3);
    }

    /**
     * @group admin
     * @group editions
     * @group deelnemers
     */
    public function adminCanOpenVoltooiingsModal(AcceptanceTester $I): void
    {
        $I->wantTo('open the completion-data modal from the deelnemers panel');

        $editionId = $this->editionWithRegistrations($I);
        $I->amOnPage('/wp/wp-admin/post.php?post=' . $editionId . '&action=edit');
        $this->openRegistrationMetabox($I);

        $I->waitForElementVisible('.stride-registration-table', 5);
        $I->click('.stride-registration-table tbody .stride-view-completion');
        $I->waitForElement('.stride-modal-section[data-section="ld"]', 10);
        $I->see('LearnDash voortgang', '#stride-registration-modal');
    }
}
