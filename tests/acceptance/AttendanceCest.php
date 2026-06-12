<?php

declare(strict_types=1);

use Tests\Support\AcceptanceTester;

/**
 * Attendance acceptance tests (hardening sprint Phase 3, F4).
 *
 * Attendance had ZERO acceptance coverage before this. Drives the admin
 * mark-present flow through the real edition metabox UI, asserts the
 * vad_attendance row, then the re-mark (update, not duplicate) and the
 * empty-state (no physical sessions) edges. The non-admin denial is
 * asserted at the wire (the AJAX handler requires nonce + edit_posts).
 *
 * Matrix: docs/architecture/acceptance-flows/p0-hardening-phase3.md (F4).
 */
class AttendanceCest
{
    private int $adminId = 0;
    private int $editionId = 0;
    private int $courseId = 0;
    private int $sessionId = 0;
    private int $studentId = 0;

    public function _before(AcceptanceTester $I): void
    {
        $this->adminId = $I->grabAdminUserId();
        if (!$this->adminId) {
            throw new \RuntimeException('No administrator account found');
        }

        $stamp = time() . '_' . substr(md5((string) microtime(true)), 0, 4);

        $this->courseId = $I->havePostInDatabase([
            'post_type' => 'sfwd-courses',
            'post_title' => 'Attendance Course ' . $stamp,
            'post_status' => 'publish',
        ]);

        $this->editionId = $I->havePostInDatabase([
            'post_type' => 'vad_edition',
            'post_title' => 'Attendance Edition ' . $stamp,
            'post_status' => 'publish',
        ]);
        $I->havePostmetaInDatabase($this->editionId, '_ntdst_course_id', $this->courseId);
        $I->havePostmetaInDatabase($this->editionId, '_ntdst_status', 'open');
        $I->havePostmetaInDatabase($this->editionId, '_ntdst_capacity', 20);
        $I->havePostmetaInDatabase($this->editionId, '_ntdst_start_date', date('Y-m-d', strtotime('+10 days')));

        // A physical session (attendance is only markable for in_person/webinar).
        $this->sessionId = $I->havePostInDatabase([
            'post_type' => 'vad_session',
            'post_title' => 'Session A ' . $stamp,
            'post_status' => 'publish',
        ]);
        $I->havePostmetaInDatabase($this->sessionId, '_ntdst_edition_id', $this->editionId);
        $I->havePostmetaInDatabase($this->sessionId, '_ntdst_date', date('Y-m-d', strtotime('+10 days')));
        $I->havePostmetaInDatabase($this->sessionId, '_ntdst_start_time', '09:00');
        $I->havePostmetaInDatabase($this->sessionId, '_ntdst_end_time', '17:00');
        $I->havePostmetaInDatabase($this->sessionId, '_ntdst_type', 'in_person');

        // A confirmed student so the attendance grid has a row.
        $this->studentId = $I->haveUserInDatabase('att_student_' . $stamp, 'subscriber', [
            'user_email' => 'att_student_' . $stamp . '@test.local',
            'display_name' => 'Att Student',
        ]);
        $I->haveUserMetaInDatabase($this->studentId, 'first_name', 'Att');
        $I->haveUserMetaInDatabase($this->studentId, 'last_name', 'Student');
        $I->haveInDatabase($I->grabPrefixedTableNameFor('vad_registrations'), [
            'user_id' => $this->studentId,
            'edition_id' => $this->editionId,
            'status' => 'confirmed',
            'enrollment_path' => 'individual',
            'registered_at' => date('Y-m-d H:i:s'),
        ]);

        // Establish the admin session (sets the auth cookie via the redirect)
        // BEFORE any test navigates to wp-admin.
        $I->loginAsUserId($this->adminId, '/wp/wp-admin/');
    }

    private function openAttendanceTab(AcceptanceTester $I): void
    {
        $I->amOnPage('/wp/wp-admin/post.php?post=' . $this->editionId . '&action=edit');
        // Un-collapse any closed metaboxes (WP persists collapse state per user).
        $I->executeJS('document.querySelectorAll(".closed").forEach(b => b.classList.remove("closed"));');
        $I->waitForElement('.stride-tab[data-tab="aanwezigheid"]', 10);
        $I->click('.stride-tab[data-tab="aanwezigheid"]');
        $I->wait(1);
    }

    /**
     * @test
     */
    public function adminMarksPresentAndRowIsRecorded(AcceptanceTester $I): void
    {
        $I->wantTo('mark a student present and see the attendance row recorded');

        $this->openAttendanceTab($I);

        $I->waitForElement('.stride-attendance-toggle[data-user-id="' . $this->studentId . '"]', 10);

        $selector = '.stride-attendance-toggle[data-user-id="' . $this->studentId . '"][data-session-id="' . $this->sessionId . '"]';

        // First click cycles unmarked → present (see edition-admin.js cycle).
        $I->click($selector);

        // Condition-based wait (no fixed sleep): the JS removes the
        // 'processing' class in the AJAX success callback, AFTER the server
        // wrote the row — so present:not(.processing) means the DB write
        // is committed. A fixed wait() flaked when the round-trip was slow.
        $I->waitForElement($selector . '.present:not(.processing)', 10);

        $I->seeInDatabase($I->grabPrefixedTableNameFor('vad_attendance'), [
            'session_id' => $this->sessionId,
            'user_id' => $this->studentId,
            'status' => 'present',
        ]);
    }

    /**
     * @test
     *
     * Re-marking the same cell must UPDATE the row, never insert a second.
     * Drive present → absent by clicking twice (the cycle is
     * unmarked→present→absent→excused→unmarked).
     */
    public function reMarkingUpdatesRowDoesNotDuplicate(AcceptanceTester $I): void
    {
        $I->wantTo('verify re-marking attendance updates the row instead of duplicating');

        $this->openAttendanceTab($I);
        $I->waitForElement('.stride-attendance-toggle[data-user-id="' . $this->studentId . '"]', 10);

        $selector = '.stride-attendance-toggle[data-user-id="' . $this->studentId . '"][data-session-id="' . $this->sessionId . '"]';

        $I->click($selector); // → present
        // The 'processing' guard in edition-admin.js silently SWALLOWS any
        // click that lands while the previous AJAX is in flight — a fixed
        // wait(2) flaked whenever the round-trip ran longer, leaving the row
        // 'present' and the second click ignored. Wait for the guard to lift.
        $I->waitForElement($selector . '.present:not(.processing)', 10);
        $I->click($selector); // → absent
        $I->waitForElement($selector . '.absent:not(.processing)', 10);

        $count = (int) $I->grabNumRecords($I->grabPrefixedTableNameFor('vad_attendance'), [
            'session_id' => $this->sessionId,
            'user_id' => $this->studentId,
        ]);
        \PHPUnit\Framework\Assert::assertSame(1, $count, "re-marking must keep one row, got {$count}");

        $I->seeInDatabase($I->grabPrefixedTableNameFor('vad_attendance'), [
            'session_id' => $this->sessionId,
            'user_id' => $this->studentId,
            'status' => 'absent',
        ]);
    }

    /**
     * @test
     *
     * An edition whose only session is online (no attendance marking) shows
     * the empty-state notice rather than a grid.
     */
    public function editionWithoutPhysicalSessionsShowsEmptyState(AcceptanceTester $I): void
    {
        $I->wantTo('verify an edition with no physical sessions shows the attendance empty state');

        // Flip the seeded session to an online type → no attendance marking.
        $I->updateInDatabase(
            $I->grabPrefixedTableNameFor('postmeta'),
            ['meta_value' => 'online'],
            ['post_id' => $this->sessionId, 'meta_key' => '_ntdst_type']
        );

        $this->openAttendanceTab($I);

        $I->see('Voeg eerst fysieke sessies of webinars toe');
        $I->dontSeeElement('.stride-attendance-toggle');
    }

    /**
     * @test
     *
     * The mark-attendance endpoint is admin-only. An unauthenticated POST
     * (no nonce) must be refused, and no row may appear.
     */
    public function unauthenticatedMarkAttendanceIsRefused(AcceptanceTester $I): void
    {
        $I->wantTo('verify the attendance endpoint refuses unauthenticated marking');

        // Drop the admin session established in _before, then POST the AJAX
        // action with no valid nonce as an anonymous visitor.
        $I->amOnPage('/wp/wp-login.php?action=logout');
        $I->amOnPage('/');
        $I->executeJS("
            window.__attResult = null;
            fetch('/wp/wp-admin/admin-ajax.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=stride_mark_attendance&session_id={$this->sessionId}&user_id={$this->studentId}&status=present&nonce=bogus'
            }).then(r => r.text()).then(t => window.__attResult = t)
              .catch(() => window.__attResult = 'fetch-error');
        ");
        $I->waitForJS('return window.__attResult !== null;', 10);

        $I->dontSeeInDatabase($I->grabPrefixedTableNameFor('vad_attendance'), [
            'session_id' => $this->sessionId,
            'user_id' => $this->studentId,
        ]);
    }
}
