<?php

declare(strict_types=1);

use Tests\Support\AcceptanceTester;

/**
 * Acceptance tests for the trajectory cascade-enrollment user-facing surface.
 *
 * Stap 15 of plans/2026-05-20-trajectory-cascade-enrollment.md. Service-layer
 * + integration coverage lives in tests/Integration/TrajectoryCascade*Test.php
 * and tests/manual/shake-cascade.php. This Cest verifies that the dashboard
 * HTML actually reflects the cascade data model — children NOT shown as
 * standalone cards (Stap 11 read-path).
 *
 * Strategy: seed minimal cascade state via $I->haveInDatabase (no service
 * calls), then visit the dashboard and inspect rendered HTML.
 */
class TrajectoryCascadeCest
{
    private string $prefix = '';

    private int $studentId = 0;
    private int $parentRegistrationId = 0;
    private int $childRegistrationId = 0;
    private int $directRegistrationId = 0;
    private int $trajectoryPostId = 0;
    private int $editionPostId = 0;
    private int $directEditionPostId = 0;

    public function _before(AcceptanceTester $I): void
    {
        $this->prefix = $I->grabTablePrefix();
        $this->studentId = (int) $I->grabFromDatabase(
            $this->prefix . 'users',
            'ID',
            ['user_login' => 'seed_student1']
        );
        if ($this->studentId === 0) {
            throw new \RuntimeException('seed_student1 not found — run scripts/seed.php first.');
        }

        $this->cleanup($I);
    }

    public function _after(AcceptanceTester $I): void
    {
        $this->cleanup($I);
    }

    /**
     * SCENARIO: Cohort cascade child is hidden from the flat enrollments list.
     *
     *   GIVEN: seed_student1 has a cohort trajectory parent + one cascade
     *          child registration on an edition, plus a separate direct
     *          enrollment on another edition (the control).
     *   WHEN:  visiting /mijn-account/?tab=inschrijvingen
     *   THEN:  the rendered card count for the direct enrollment edition is 1
     *          AND no card is rendered for the cascade child's edition.
     */
    public function cohortChildDoesNotAppearAsStandaloneCard(AcceptanceTester $I): void
    {
        $I->wantTo('verify cascade children do not render as standalone cards on the dashboard');

        $this->seedCohortCascadeState($I);
        $this->seedDirectEnrollmentControl($I);

        $I->loginAsUserId($this->studentId, '/mijn-account/?tab=inschrijvingen');
        $I->waitForElement('section', 5);

        // Direct (non-cascade) edition should render exactly one card.
        $directCardCount = $this->countCardsForEdition($I, $this->directEditionPostId);
        \PHPUnit\Framework\Assert::assertSame(
            1,
            $directCardCount,
            'direct enrollment edition should render exactly one card on the dashboard'
        );

        // Cascade child should render zero standalone cards.
        $childCardCount = $this->countCardsForEdition($I, $this->editionPostId);
        \PHPUnit\Framework\Assert::assertSame(
            0,
            $childCardCount,
            'cascade child must not render as a standalone enrollment card'
        );
    }

    /**
     * SCENARIO: Self-paced cascade child survives a parent cancellation.
     *
     *   GIVEN: seed_student1 has a self-paced trajectory parent (cancelled)
     *          and a child registration on an edition (still Confirmed,
     *          because self-paced does not cascade cancellation).
     *   WHEN:  visiting /mijn-account/?tab=inschrijvingen
     *   THEN:  the child row's `parent_registration_id` is preserved AND
     *          the row's status in the DB is still 'confirmed'.
     *
     * This guards the cascadeOnCancellation self-paced no-op (Stap 7)
     * through HTTP: it confirms a real page-load doesn't trigger any
     * code path that mutates the child rows.
     */
    public function selfPacedCancelledParentLeavesChildConfirmed(AcceptanceTester $I): void
    {
        $I->wantTo('verify self-paced child survives parent cancellation on dashboard load');

        $this->seedSelfPacedCancelledState($I);

        $I->loginAsUserId($this->studentId, '/mijn-account/?tab=inschrijvingen');
        $I->waitForElement('section', 5);

        $childStatus = $I->grabFromDatabase(
            $this->prefix . 'vad_registrations',
            'status',
            ['id' => $this->childRegistrationId]
        );
        \PHPUnit\Framework\Assert::assertSame(
            'confirmed',
            $childStatus,
            'self-paced child must remain confirmed even after parent cancel'
        );

        $childParent = $I->grabFromDatabase(
            $this->prefix . 'vad_registrations',
            'parent_registration_id',
            ['id' => $this->childRegistrationId]
        );
        \PHPUnit\Framework\Assert::assertSame(
            (string) $this->parentRegistrationId,
            (string) $childParent,
            'parent_registration_id link survives cancellation'
        );
    }

    // === Setup / teardown ====================================================

    private function seedCohortCascadeState(AcceptanceTester $I): void
    {
        $this->trajectoryPostId = $this->insertPost($I, 'vad_trajectory', 'Cascade Cest Cohort');
        $I->haveInDatabase($this->prefix . 'postmeta', [
            'post_id' => $this->trajectoryPostId,
            'meta_key' => '_ntdst_mode',
            'meta_value' => 'cohort',
        ]);

        $this->editionPostId = $this->insertPost($I, 'vad_edition', 'Cascade Cest Edition');
        $I->haveInDatabase($this->prefix . 'postmeta', [
            'post_id' => $this->editionPostId,
            'meta_key' => '_ntdst_status',
            'meta_value' => 'open',
        ]);

        // Parent trajectory registration.
        $this->parentRegistrationId = $I->haveInDatabase($this->prefix . 'vad_registrations', [
            'user_id' => $this->studentId,
            'trajectory_id' => $this->trajectoryPostId,
            'edition_id' => null,
            'parent_registration_id' => null,
            'status' => 'confirmed',
            'enrollment_path' => 'trajectory',
            'registered_at' => date('Y-m-d H:i:s'),
        ]);

        // Cascade child on an edition, linked via parent_registration_id.
        $this->childRegistrationId = $I->haveInDatabase($this->prefix . 'vad_registrations', [
            'user_id' => $this->studentId,
            'trajectory_id' => null,
            'edition_id' => $this->editionPostId,
            'parent_registration_id' => $this->parentRegistrationId,
            'status' => 'confirmed',
            'enrollment_path' => 'trajectory',
            'registered_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function seedDirectEnrollmentControl(AcceptanceTester $I): void
    {
        $this->directEditionPostId = $this->insertPost($I, 'vad_edition', 'Cascade Cest Direct Edition');
        $I->haveInDatabase($this->prefix . 'postmeta', [
            'post_id' => $this->directEditionPostId,
            'meta_key' => '_ntdst_status',
            'meta_value' => 'open',
        ]);

        $this->directRegistrationId = $I->haveInDatabase($this->prefix . 'vad_registrations', [
            'user_id' => $this->studentId,
            'edition_id' => $this->directEditionPostId,
            'status' => 'confirmed',
            'enrollment_path' => 'individual',
            'registered_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function seedSelfPacedCancelledState(AcceptanceTester $I): void
    {
        $this->trajectoryPostId = $this->insertPost($I, 'vad_trajectory', 'Cascade Cest Self-Paced');
        $I->haveInDatabase($this->prefix . 'postmeta', [
            'post_id' => $this->trajectoryPostId,
            'meta_key' => '_ntdst_mode',
            'meta_value' => 'self_paced',
        ]);

        $this->editionPostId = $this->insertPost($I, 'vad_edition', 'Self-Paced Cest Edition');
        $I->haveInDatabase($this->prefix . 'postmeta', [
            'post_id' => $this->editionPostId,
            'meta_key' => '_ntdst_status',
            'meta_value' => 'open',
        ]);

        // Parent: cancelled
        $this->parentRegistrationId = $I->haveInDatabase($this->prefix . 'vad_registrations', [
            'user_id' => $this->studentId,
            'trajectory_id' => $this->trajectoryPostId,
            'status' => 'cancelled',
            'enrollment_path' => 'trajectory',
            'cancelled_at' => date('Y-m-d H:i:s'),
            'registered_at' => date('Y-m-d H:i:s'),
        ]);

        // Child: still confirmed (self-paced no-op semantics)
        $this->childRegistrationId = $I->haveInDatabase($this->prefix . 'vad_registrations', [
            'user_id' => $this->studentId,
            'edition_id' => $this->editionPostId,
            'parent_registration_id' => $this->parentRegistrationId,
            'status' => 'confirmed',
            'enrollment_path' => 'trajectory',
            'registered_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function cleanup(AcceptanceTester $I): void
    {
        if ($this->parentRegistrationId) {
            $I->dontHaveInDatabase($this->prefix . 'vad_registrations', ['id' => $this->parentRegistrationId]);
        }
        if ($this->childRegistrationId) {
            $I->dontHaveInDatabase($this->prefix . 'vad_registrations', ['id' => $this->childRegistrationId]);
        }
        if ($this->directRegistrationId) {
            $I->dontHaveInDatabase($this->prefix . 'vad_registrations', ['id' => $this->directRegistrationId]);
        }
        foreach ([$this->trajectoryPostId, $this->editionPostId, $this->directEditionPostId] as $postId) {
            if ($postId > 0) {
                $I->dontHaveInDatabase($this->prefix . 'postmeta', ['post_id' => $postId]);
                $I->dontHaveInDatabase($this->prefix . 'posts', ['ID' => $postId]);
            }
        }

        $this->parentRegistrationId = 0;
        $this->childRegistrationId = 0;
        $this->directRegistrationId = 0;
        $this->trajectoryPostId = 0;
        $this->editionPostId = 0;
        $this->directEditionPostId = 0;
    }

    private function insertPost(AcceptanceTester $I, string $postType, string $title): int
    {
        return (int) $I->haveInDatabase($this->prefix . 'posts', [
            'post_author' => 1,
            'post_date' => date('Y-m-d H:i:s'),
            'post_date_gmt' => gmdate('Y-m-d H:i:s'),
            'post_content' => '',
            'post_title' => $title,
            'post_status' => 'publish',
            'comment_status' => 'closed',
            'ping_status' => 'closed',
            'post_name' => strtolower(str_replace([' ', ':'], '-', $title)) . '-' . substr(md5((string) microtime(true)), 0, 6),
            'post_modified' => date('Y-m-d H:i:s'),
            'post_modified_gmt' => gmdate('Y-m-d H:i:s'),
            'post_type' => $postType,
        ]);
    }

    /**
     * Count rendered enrollment cards whose href / data attributes link to a
     * given edition. Inspects the page HTML rather than relying on a
     * data-edition-id selector that the theme may not expose. We look for a
     * link to the edition's permalink as a robust signal that the dashboard
     * is rendering it.
     */
    private function countCardsForEdition(AcceptanceTester $I, int $editionId): int
    {
        return (int) $I->executeJS(
            "return document.querySelectorAll('a[href*=\"edition_id={$editionId}\"], a[href*=\"/edities/\"][data-edition-id=\"{$editionId}\"], [data-edition-id=\"{$editionId}\"]').length;"
        );
    }
}
