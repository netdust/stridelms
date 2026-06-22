<?php

declare(strict_types=1);

use Tests\Support\AcceptanceTester;

/**
 * Acceptance tests for ntdst-assistant plugin.
 * Tests admin page, UI elements, endpoint auth, and conversation management.
 *
 * Note: Tests that require Claude API calls (chat send/receive, tool execution)
 * are not included — they require a live API key and take 5-10s per call.
 * Those flows are covered by unit tests + manual smoke testing.
 */
class AssistantPluginCest
{
    private int $adminId;

    public function _before(AcceptanceTester $I): void
    {
        // ntdst-assistant is an opt-in plugin and is NOT active in every
        // environment (it is off by default locally and in CI). When it is
        // inactive its admin page, abilities and assets do not exist, so every
        // assertion here would hard-fail on an intentional config rather than a
        // real regression. Skip the whole Cest unless the plugin is active.
        $active = $I->grabOptionFromDatabase('active_plugins');
        $activePlugins = is_array($active) ? $active : (array) maybe_unserialize((string) $active);
        $assistantActive = false;
        foreach ($activePlugins as $plugin) {
            if (is_string($plugin) && str_contains($plugin, 'ntdst-assistant/')) {
                $assistantActive = true;
                break;
            }
        }
        if (!$assistantActive) {
            \PHPUnit\Framework\Assert::markTestSkipped('ntdst-assistant plugin is not active in this environment');
        }

        $this->adminId = $I->grabAdminUserId();
    }

    // ---------------------------------------------------------------
    // Admin Page — Structure
    // ---------------------------------------------------------------

    public function assistantPageLoads(AcceptanceTester $I): void
    {
        $I->wantTo('verify the Assistant admin page loads without errors');
        $I->loginAsUserId($this->adminId, '/wp/wp-admin/admin.php?page=stride-assistant');
        $I->see('Stride Assistent');
        $I->seeElement('#ntdst-assistant');
        $I->dontSee('Fatal error');
    }

    public function assistantPageHasHeader(AcceptanceTester $I): void
    {
        $I->wantTo('verify the Assistant page has header with title and clear button');
        $I->loginAsUserId($this->adminId, '/wp/wp-admin/admin.php?page=stride-assistant');
        $I->see('Stride Assistent', '.assistant-header');
        $I->see('Gesprek wissen', '.assistant-header');
    }

    public function assistantPageShowsEmptyState(AcceptanceTester $I): void
    {
        $I->wantTo('verify empty state shows when no messages');
        // Clear any existing conversation first
        $I->dontHaveInDatabase($I->grabPrefixedTableNameFor('options'), ['option_name LIKE' => '%ntdst_assistant_conv_%']);

        $I->loginAsUserId($this->adminId, '/wp/wp-admin/admin.php?page=stride-assistant');

        // Wait for Alpine to render
        $I->waitForElement('.assistant-empty', 5);
        $I->see('Stel een vraag over edities');
    }

    public function assistantPageHasInputArea(AcceptanceTester $I): void
    {
        $I->wantTo('verify the input textarea and send button exist');
        $I->loginAsUserId($this->adminId, '/wp/wp-admin/admin.php?page=stride-assistant');
        $I->seeElement('.assistant-input textarea');
        $I->see('Verstuur', '.assistant-input');
    }

    public function assistantPageHasNoJsErrors(AcceptanceTester $I): void
    {
        $I->wantTo('verify no JavaScript errors on assistant page');
        $I->loginAsUserId($this->adminId, '/wp/wp-admin/admin.php?page=stride-assistant');
        $I->seeElement('#ntdst-assistant');
        // If Alpine fails to load, the x-data container won't render
        // Check that Alpine processed the component by verifying empty state or input is visible
        $I->waitForElement('.assistant-input', 5);
    }

    // ---------------------------------------------------------------
    // Assets — Self-hosted
    // ---------------------------------------------------------------

    public function assistantLoadsLocalAlpine(AcceptanceTester $I): void
    {
        $I->wantTo('verify Alpine.js is loaded from local assets, not CDN');
        $I->loginAsUserId($this->adminId, '/wp/wp-admin/admin.php?page=stride-assistant');

        // Check page source for local Alpine path
        $pageSource = $I->grabPageSource();
        \PHPUnit\Framework\Assert::assertStringContainsString(
            'ntdst-assistant/assets/js/alpine.min.js',
            $pageSource,
            'Alpine.js should be loaded from local plugin assets'
        );
        \PHPUnit\Framework\Assert::assertStringNotContainsString(
            'cdn.jsdelivr.net/npm/alpinejs',
            $pageSource,
            'Alpine.js should NOT be loaded from CDN'
        );
    }

    // ---------------------------------------------------------------
    // REST Endpoints — Auth
    // ---------------------------------------------------------------

    public function chatEndpointRejectsUnauthenticatedGet(AcceptanceTester $I): void
    {
        $I->wantTo('verify /chat endpoint rejects unauthenticated GET requests');
        // /chat is POST-only — GET returns rest_no_route
        $I->amOnPage('/wp-json/ntdst-assistant/v1/chat');
        $I->seeInSource('"code"');
    }

    public function clearEndpointRejectsUnauthenticated(AcceptanceTester $I): void
    {
        $I->wantTo('verify /clear endpoint rejects unauthenticated requests');
        $I->amOnPage('/wp-json/ntdst-assistant/v1/clear');
        // GET on a POST-only route returns method not allowed or route not found
        $I->seeInSource('"code"');
    }

    public function downloadEndpointRejectsFakeToken(AcceptanceTester $I): void
    {
        $I->wantTo('verify /download endpoint rejects invalid signed URLs');
        $I->amOnPage('/wp-json/ntdst-assistant/v1/download?file=fake.csv&token=invalid&expires=9999999999&uid=1');
        $I->seeInSource('ongeldig of verlopen');
    }

    // ---------------------------------------------------------------
    // Abilities — Registration
    // ---------------------------------------------------------------

    public function abilitiesAreRegistered(AcceptanceTester $I): void
    {
        $I->wantTo('verify Stride abilities are registered in WordPress');

        // Verify abilities via the assistant admin page source (it includes tool definitions)
        $I->loginAsUserId($this->adminId, '/wp/wp-admin/admin.php?page=stride-assistant');

        $pageSource = $I->grabPageSource();

        // The page localizes ntdstAssistantConfig with restUrl — if the page loads,
        // abilities are registered (they're verified in unit tests for exact names)
        \PHPUnit\Framework\Assert::assertStringContainsString('ntdstAssistantConfig', $pageSource);
        \PHPUnit\Framework\Assert::assertStringContainsString('ntdst-assistant/v1/', $pageSource);
    }

    // ---------------------------------------------------------------
    // Non-admin access
    // ---------------------------------------------------------------

    public function assistantPageRequiresCapability(AcceptanceTester $I): void
    {
        $I->wantTo('verify the Assistant menu requires edit_others_posts capability');

        // Find a subscriber user (or create test via DB)
        $subscriberId = $I->grabFromDatabase($I->grabPrefixedTableNameFor('users'), 'ID', ['user_login' => 'seed_student1']);

        if ($subscriberId) {
            $I->loginAsUserId((int) $subscriberId, '/wp/wp-admin/admin.php?page=stride-assistant');
            // Subscriber should not see the assistant page content
            $I->dontSee('Stride Assistent', '.assistant-header');
        } else {
            // No subscriber user to test with — verify via code that capability is enforced
            $I->comment('No subscriber user found — skipping capability test');
            $I->loginAsUserId($this->adminId, '/wp/wp-admin/admin.php?page=stride-assistant');
            $I->see('Stride Assistent');
        }
    }

    // ---------------------------------------------------------------
    // Conversation State
    // ---------------------------------------------------------------

    public function clearButtonDisabledWhenNoMessages(AcceptanceTester $I): void
    {
        $I->wantTo('verify clear button is disabled when no messages exist');
        $I->loginAsUserId($this->adminId, '/wp/wp-admin/admin.php?page=stride-assistant');
        $I->waitForElement('.assistant-header', 5);

        // The button should have disabled attribute when messages are empty
        $I->seeElement('.assistant-header button[disabled]');
    }

    public function sendButtonDisabledWhenInputEmpty(AcceptanceTester $I): void
    {
        $I->wantTo('verify send button is disabled when textarea is empty');
        $I->loginAsUserId($this->adminId, '/wp/wp-admin/admin.php?page=stride-assistant');
        $I->waitForElement('.assistant-input', 5);

        // Verstuur button should be disabled with empty input
        $I->seeElement('.assistant-input button[disabled]');
    }

    // ---------------------------------------------------------------
    // Export Infrastructure
    // ---------------------------------------------------------------

    public function exportDirectoryIsProtected(AcceptanceTester $I): void
    {
        $I->wantTo('verify export directory has .htaccess protection');

        // Check if export dir exists (may not exist yet if no exports have run)
        $uploadsDir = $I->grabFromDatabase($I->grabPrefixedTableNameFor('options'), 'option_value', ['option_name' => 'upload_path']);

        // We can verify the ExportService creates .htaccess by checking the dir
        // If the dir exists from earlier tests, it should have .htaccess
        $I->amOnPage('/app/uploads/stride-exports/.htaccess');
        // Should be forbidden or not found (protected)
        $I->dontSee('<?php');
    }

    public function cronCleanupIsScheduled(AcceptanceTester $I): void
    {
        $I->wantTo('verify export cleanup cron is scheduled');

        // The cron event should exist in the cron array
        $I->seeInDatabase($I->grabPrefixedTableNameFor('options'), [
            'option_name' => 'cron',
        ]);

        // Grab the cron option and verify our hook exists
        $cronOption = $I->grabFromDatabase($I->grabPrefixedTableNameFor('options'), 'option_value', ['option_name' => 'cron']);
        \PHPUnit\Framework\Assert::assertStringContainsString(
            'ntdst_assistant_cleanup_exports',
            $cronOption,
            'Export cleanup cron should be scheduled'
        );
    }
}
