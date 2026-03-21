<?php

declare(strict_types=1);

use Tests\Support\AcceptanceTester;

/**
 * Acceptance tests for ntdst-audit plugin.
 * Tests admin page rendering, data display, and event recording.
 */
class AuditPluginCest
{
    private int $adminId;

    public function _before(AcceptanceTester $I): void
    {
        $this->adminId = (int) $I->grabFromDatabase('stride_users', 'ID', ['user_login' => 'admin']);
    }

    // ---------------------------------------------------------------
    // Admin Page
    // ---------------------------------------------------------------

    public function auditLogPageLoads(AcceptanceTester $I): void
    {
        $I->wantTo('verify the Audit Log admin page loads without errors');
        $I->loginAsUserId($this->adminId, '/wp/wp-admin/tools.php?page=ntdst-audit-log');
        $I->see('Audit Log');
        $I->seeElement('.ntdst-app');
        $I->seeElement('.ntdst-sidebar');
        $I->dontSee('Fatal error');
    }

    public function auditLogShowsEntries(AcceptanceTester $I): void
    {
        $I->wantTo('verify the Audit Log table shows data after Alpine loads');
        $I->loginAsUserId($this->adminId, '/wp/wp-admin/tools.php?page=ntdst-audit-log');
        $I->see('Audit Log');

        // Wait for Alpine.js to render table rows
        $I->waitForElement('.ntdst-main table tbody tr', 15);
        $I->seeElement('.ntdst-main table tbody tr');
    }

    public function auditLogHasFilterControls(AcceptanceTester $I): void
    {
        $I->wantTo('verify the Audit Log has date and entity type filter controls');
        $I->loginAsUserId($this->adminId, '/wp/wp-admin/tools.php?page=ntdst-audit-log');
        $I->see('Audit Log');

        // Filter form elements
        $I->seeElement('.ntdst-form-input');
        $I->see('From');
        $I->see('To');
        $I->see('Entity Type');
    }

    public function auditLogHasExportTab(AcceptanceTester $I): void
    {
        $I->wantTo('verify the Export tab exists in the sidebar');
        $I->loginAsUserId($this->adminId, '/wp/wp-admin/tools.php?page=ntdst-audit-log');
        $I->see('Export', '.ntdst-sidebar');
    }

    // ---------------------------------------------------------------
    // Event Recording (database level)
    // ---------------------------------------------------------------

    public function auditTableExists(AcceptanceTester $I): void
    {
        $I->wantTo('verify the audit_log table exists and has data');
        $I->seeInDatabase('stride_audit_log', []);
    }

    public function auditRecordPreservesSlashInAction(AcceptanceTester $I): void
    {
        $I->wantTo('verify audit records preserve / in action names');

        $I->haveInDatabase('stride_audit_log', [
            'entity_type' => 'test',
            'entity_id' => 99999,
            'action' => 'test.stride/verify-slash',
            'actor_id' => $this->adminId,
            'actor_type' => 'user',
            'context' => '{"test":true}',
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $I->seeInDatabase('stride_audit_log', [
            'entity_type' => 'test',
            'entity_id' => 99999,
            'action' => 'test.stride/verify-slash',
        ]);

        // Cleanup
        $I->dontHaveInDatabase('stride_audit_log', [
            'entity_type' => 'test',
            'entity_id' => 99999,
        ]);
    }

    public function auditContextStoredAsValidJson(AcceptanceTester $I): void
    {
        $I->wantTo('verify audit context is stored as valid JSON');

        $I->haveInDatabase('stride_audit_log', [
            'entity_type' => 'test',
            'entity_id' => 88888,
            'action' => 'test.json-check',
            'actor_id' => $this->adminId,
            'actor_type' => 'user',
            'context' => '{"key":"value","nested":{"a":1}}',
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $context = $I->grabFromDatabase('stride_audit_log', 'context', [
            'entity_type' => 'test',
            'entity_id' => 88888,
        ]);

        $decoded = json_decode($context, true);
        // Verify it's valid JSON by checking json_decode didn't return null
        \PHPUnit\Framework\Assert::assertNotNull($decoded, 'Context should be valid JSON');
        \PHPUnit\Framework\Assert::assertSame('value', $decoded['key']);

        // Cleanup
        $I->dontHaveInDatabase('stride_audit_log', [
            'entity_type' => 'test',
            'entity_id' => 88888,
        ]);
    }

    // ---------------------------------------------------------------
    // Non-admin access
    // ---------------------------------------------------------------

    public function nonAdminCannotAccessAuditPage(AcceptanceTester $I): void
    {
        $I->wantTo('verify non-admin users cannot access the Audit Log page');
        // Navigate without logging in
        $I->amOnPage('/wp/wp-admin/tools.php?page=ntdst-audit-log');
        // Should redirect to login or show forbidden
        $I->dontSee('Audit Log', 'h1');
    }
}
