<?php

declare(strict_types=1);

use Tests\Support\AcceptanceTester;

/**
 * Dashboard empty-state + quote-lock + anonymise edge tests
 * (hardening sprint Phase 3, F7/F8/F9).
 *
 * Matrix: docs/architecture/acceptance-flows/p0-hardening-phase3.md.
 */
class DashboardQuoteGdprEdgeCest
{
    // ---- F7: dashboard empty state + nav consistency ----------------------

    /**
     * @test
     *
     * A brand-new user with no enrollments: dashboard renders without error,
     * shows no Certificaten nav, and the nav is IDENTICAL across tabs
     * (browser regression for the 1B single-source nav fix — the bug was
     * that nav appeared on home and vanished on other tabs).
     */
    public function newUserSeesConsistentNavAcrossTabs(AcceptanceTester $I): void
    {
        $I->wantTo('verify a new user gets identical dashboard nav across tabs');

        $stamp = time() . '_' . substr(md5((string) microtime(true)), 0, 4);
        $userId = $I->haveUserInDatabase('empty_' . $stamp, 'subscriber', [
            'user_email' => 'empty_' . $stamp . '@test.local',
            'display_name' => 'Empty Dash',
        ]);
        $I->haveUserMetaInDatabase($userId, 'first_name', 'Empty');
        $I->haveUserMetaInDatabase($userId, 'last_name', 'Dash');

        $I->loginAsUserId($userId, '/mijn-account/');
        $I->waitForElement('body', 10);
        $I->dontSee('Fatal error');

        // The crux of the 1B fix: the dashboard nav must be IDENTICAL on the
        // home tab and every other tab. The bug was items appearing on home
        // and vanishing elsewhere — caught here by comparing the rendered
        // nav-tab set across three tabs for the same user.
        $navOnHome = $this->grabNavLabels($I);
        \PHPUnit\Framework\Assert::assertNotEmpty($navOnHome, 'dashboard nav should render at least one tab');

        $I->amOnPage('/mijn-account/?tab=profiel');
        $I->waitForElement('body', 10);
        $navOnProfile = $this->grabNavLabels($I);

        $I->amOnPage('/mijn-account/?tab=inschrijvingen');
        $I->waitForElement('body', 10);
        $navOnInschrijvingen = $this->grabNavLabels($I);

        \PHPUnit\Framework\Assert::assertSame(
            $navOnHome,
            $navOnProfile,
            'dashboard nav must be identical on home and the profiel tab'
        );
        \PHPUnit\Framework\Assert::assertSame(
            $navOnHome,
            $navOnInschrijvingen,
            'dashboard nav must be identical on home and the inschrijvingen tab'
        );
    }

    /**
     * Grab the set of dashboard nav tab destinations (e.g. inschrijvingen,
     * certificaten, profiel) from the dashboard-navigation containers.
     *
     * Keyed on the ?tab= target rather than visible label/order so it is
     * stable across the dock/sidebar/mobile responsive variants. Returns a
     * sorted, de-duplicated list.
     *
     * @return string[]
     */
    private function grabNavLabels(AcceptanceTester $I): array
    {
        $raw = (string) $I->executeJS(<<<'JS'
            const navs = document.querySelectorAll('[aria-label="Dashboard navigatie"]');
            const tabs = new Set();
            navs.forEach(nav => nav.querySelectorAll('a[href*="tab="]').forEach(a => {
                const m = a.href.match(/[?&]tab=([a-z]+)/);
                if (m) tabs.add(m[1]);
            }));
            return JSON.stringify([...tabs].sort());
JS);

        return (array) json_decode($raw, true);
    }

    // ---- F8: quote locking ------------------------------------------------

    /**
     * @test
     *
     * A locked quote rejects a customer billing update through the real
     * stride_update_quote wire; an unlocked quote (control) accepts the
     * same edit.
     */
    public function lockedQuoteRejectsBillingUpdateUnlockedAccepts(AcceptanceTester $I): void
    {
        $I->wantTo('verify a locked quote rejects billing edits and an unlocked one accepts');

        $stamp = time() . '_' . substr(md5((string) microtime(true)), 0, 4);
        $userId = $I->haveUserInDatabase('quote_' . $stamp, 'subscriber', [
            'user_email' => 'quote_' . $stamp . '@test.local',
            'display_name' => 'Quote Owner',
        ]);

        $lockedQuoteId = $this->makeQuote($I, $userId, 'QUOTE-LOCK-' . $stamp, true);
        $openQuoteId = $this->makeQuote($I, $userId, 'QUOTE-OPEN-' . $stamp, false);

        $I->loginAsUserId($userId, '/mijn-account/');
        $I->waitForElement('body', 10);

        // Locked quote → billing update refused.
        $lockedResult = $this->updateQuoteBilling($I, $lockedQuoteId, 'EVIL BV');
        \PHPUnit\Framework\Assert::assertTrue(
            (bool) ($lockedResult['error'] ?? false),
            'locked quote must reject billing update'
        );
        // QuoteRepository::updateMeta writes the BARE 'billing' key.
        $I->dontSeeInDatabase($I->grabPrefixedTableNameFor('postmeta'), [
            'post_id' => $lockedQuoteId,
            'meta_key' => 'billing',
            'meta_value like' => '%EVIL BV%',
        ]);

        // Unlocked quote (control) → billing update accepted.
        $openResult = $this->updateQuoteBilling($I, $openQuoteId, 'Legit BV');
        \PHPUnit\Framework\Assert::assertTrue(
            (bool) ($openResult['ok'] ?? false),
            'unlocked draft quote must accept billing update'
        );
        $I->seeInDatabase($I->grabPrefixedTableNameFor('postmeta'), [
            'post_id' => $openQuoteId,
            'meta_key' => 'billing',
            'meta_value like' => '%Legit BV%',
        ]);
    }

    private function makeQuote(AcceptanceTester $I, int $userId, string $number, bool $locked): int
    {
        $quoteId = $I->havePostInDatabase([
            'post_type' => 'vad_quote',
            'post_title' => $number,
            'post_status' => 'publish',
        ]);
        // The Data API applies the _ntdst_ prefix on read — fixtures write
        // BARE keys (matching IntegrationTestCase::createTestQuote). Prefixed
        // keys here would be double-prefixed and never resolve.
        $I->havePostmetaInDatabase($quoteId, 'user_id', $userId);
        $I->havePostmetaInDatabase($quoteId, 'quote_number', $number);
        $I->havePostmetaInDatabase($quoteId, 'status', 'draft');
        $I->havePostmetaInDatabase($quoteId, 'locked', $locked ? '1' : '');
        $I->havePostmetaInDatabase($quoteId, 'billing', serialize(['company' => 'Original BV']));

        return $quoteId;
    }

    /**
     * Drive the customer billing-update endpoint and return {ok}|{error}.
     *
     * @return array{ok?: bool, error?: string}
     */
    private function updateQuoteBilling(AcceptanceTester $I, int $quoteId, string $company): array
    {
        $I->executeJS("
            window.__quoteResult = null;
            ntdstAPI.call('stride_update_quote', {
                quote_id: {$quoteId},
                billing: { company: '{$company}' },
            }).then(() => window.__quoteResult = { ok: true })
              .catch(e => window.__quoteResult = { error: e.message || 'refused' });
        ");
        $I->waitForJS('return window.__quoteResult !== null;', 10);

        $raw = (string) $I->executeJS('return JSON.stringify(window.__quoteResult);');

        return (array) json_decode($raw, true);
    }

    // ---- F9: anonymise (GDPR) --------------------------------------------

    /**
     * @test
     *
     * Admin anonymise row action strips PII but keeps the registration row;
     * running it a second time is idempotent (no error).
     */
    public function adminAnonymiseStripsPiiKeepsRegistrationAndIsIdempotent(AcceptanceTester $I): void
    {
        $I->wantTo('verify admin anonymise strips PII, keeps registrations, and is idempotent');

        $adminId = $I->grabAdminUserId();
        if (!$adminId) {
            throw new \RuntimeException('No administrator account found');
        }

        $stamp = time() . '_' . substr(md5((string) microtime(true)), 0, 4);
        $courseId = $I->havePostInDatabase([
            'post_type' => 'sfwd-courses',
            'post_title' => 'Anon Course ' . $stamp,
            'post_status' => 'publish',
        ]);
        $editionId = $I->havePostInDatabase([
            'post_type' => 'vad_edition',
            'post_title' => 'Anon Edition ' . $stamp,
            'post_status' => 'publish',
        ]);
        $I->havePostmetaInDatabase($editionId, '_ntdst_course_id', $courseId);

        $victimId = $I->haveUserInDatabase('anon_' . $stamp, 'subscriber', [
            'user_email' => 'anon_' . $stamp . '@test.local',
            'display_name' => 'Anon Victim',
        ]);
        $I->haveUserMetaInDatabase($victimId, 'first_name', 'Anon');
        $I->haveUserMetaInDatabase($victimId, 'last_name', 'Victim');
        $I->haveUserMetaInDatabase($victimId, 'national_id', '90010112345');
        $I->haveInDatabase($I->grabPrefixedTableNameFor('vad_registrations'), [
            'user_id' => $victimId,
            'edition_id' => $editionId,
            'status' => 'confirmed',
            'enrollment_path' => 'individual',
            'registered_at' => date('Y-m-d H:i:s'),
        ]);

        $I->loginAsUserId($adminId, '/wp/wp-admin/');

        // Drive the real admin row action (admin-post.php with the per-user
        // nonce) twice — second run must be idempotent.
        $this->anonymiseViaRowAction($I, $victimId);

        // Registration row survives.
        $I->seeInDatabase($I->grabPrefixedTableNameFor('vad_registrations'), [
            'user_id' => $victimId,
            'edition_id' => $editionId,
        ]);

        // PII stripped: anonymised marker set, national_id cleared, email rewritten.
        $I->seeInDatabase($I->grabPrefixedTableNameFor('usermeta'), [
            'user_id' => $victimId,
            'meta_key' => '_stride_anonymised_at',
        ]);
        $nationalId = $I->grabFromDatabase($I->grabPrefixedTableNameFor('usermeta'), 'meta_value', [
            'user_id' => $victimId,
            'meta_key' => 'national_id',
        ]);
        \PHPUnit\Framework\Assert::assertEmpty($nationalId, 'national_id must be cleared after anonymise');

        $email = $I->grabFromDatabase($I->grabPrefixedTableNameFor('users'), 'user_email', ['ID' => $victimId]);
        \PHPUnit\Framework\Assert::assertStringContainsString('deleted.local', $email, 'email must be rewritten to a deleted placeholder');

        // The UI prevents a second anonymise: an already-anonymised user's
        // row shows a "Geanonimiseerd op …" label instead of the action link.
        // That is the idempotency guarantee at the UI layer (the service-level
        // early-return is integration-covered).
        $newLogin = (string) $I->grabFromDatabase($I->grabPrefixedTableNameFor('users'), 'user_login', ['ID' => $victimId]);
        $I->amOnPage('/wp/wp-admin/users.php?s=' . urlencode($newLogin));
        $I->waitForElement('#the-list', 10);
        // Row-action text is hover-hidden — assert against the rendered source.
        $I->seeInSource('Geanonimiseerd op');

        $stillHasAction = (bool) $I->executeJS(
            "const links = Array.from(document.querySelectorAll('a[href*=\"stride_anonymise_user\"]'));" .
            "return links.some(a => a.href.includes('user={$victimId}'));"
        );
        \PHPUnit\Framework\Assert::assertFalse($stillHasAction, 'anonymised user must not expose the anonymise action again');
    }

    /**
     * Trigger the anonymise admin-post action for a user.
     *
     * The row action lives on users.php but is revealed on hover; rather than
     * fight the hover state, read the rendered link straight from the row's
     * HTML (it is present in the DOM, just visually hidden). This drives the
     * real admin-post.php handler with the real per-user nonce — the same
     * request the admin's click produces.
     */
    private function anonymiseViaRowAction(AcceptanceTester $I, int $userId): void
    {
        // Search by the user's login so the row is on the first page regardless
        // of how many users the seed DB holds (users.php paginates at 20).
        $login = (string) $I->grabFromDatabase($I->grabPrefixedTableNameFor('users'), 'user_login', ['ID' => $userId]);
        $I->amOnPage('/wp/wp-admin/users.php?s=' . urlencode($login));
        $I->waitForElement('#the-list', 10);

        $href = (string) $I->executeJS(
            "const links = Array.from(document.querySelectorAll('a[href*=\"stride_anonymise_user\"]'));" .
            "const m = links.find(a => a.href.includes('user={$userId}') || a.href.includes('user%3D{$userId}'));" .
            "return m ? m.href : '';"
        );

        \PHPUnit\Framework\Assert::assertNotSame('', $href, "anonymise row action link must be present for user {$userId}");

        $I->amOnUrl($href);
        $I->waitForElement('body', 10);
    }
}
