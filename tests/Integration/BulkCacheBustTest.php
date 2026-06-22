<?php

declare(strict_types=1);

namespace Stride\Tests\Integration;

use IntegrationTestCase;
use Stride\Handlers\BulkRegistrationHandler;

/**
 * Integration test for M10 — cache bust on bulk completion (Task 2.4).
 *
 * Contract: a finished bulk batch must invalidate the `stride_action_queue`
 * transient so the admin "Acties nodig" queue recounts. Two new event names
 * carry that bust, added to AdminDashboardService's `$invalidateQueue` list:
 *   - stride/registration/bulk_completed     (fired at the tail of every bulk handler)
 *   - stride/registration/quote_status_changed (fired by the quote bulk handlers)
 * The per-row stride/registration/{confirmed,cancelled} busts are inherited
 * from the domain methods and are not re-tested here.
 *
 * Wiring note: the bust list lives in AdminDashboardService::init(), and that
 * service is admin_only — the framework skips it when is_admin() is false (the
 * CLI/integration context). So the listeners are NOT auto-wired by the
 * integration bootstrap. We boot the service ourselves under admin context in
 * setUp(), exercising the REAL production wiring code (not a test-local
 * re-registration of the same closures) so the seam under test is genuine.
 *
 * Run: ddev exec vendor/bin/phpunit -c phpunit-integration.xml.dist --filter BulkCacheBust
 */
final class BulkCacheBustTest extends IntegrationTestCase
{
    private const QUEUE_TRANSIENT = 'stride_action_queue';

    private ?int $adminId = null;
    private int $viewerId = 0;

    protected function setUp(): void
    {
        parent::setUp();

        // Flip is_admin() true so AdminDashboardService::init() runs its bust
        // wiring exactly as it would in wp-admin. set_current_screen() is the
        // canonical test lever for admin context.
        require_once ABSPATH . 'wp-admin/includes/screen.php';
        set_current_screen('dashboard');

        // Boot the dashboard service once per test — its constructor runs init(),
        // which registers the $invalidateQueue listeners on the new event names.
        // This is the production code under test; nothing about the bust is
        // re-declared here.
        new \Stride\Admin\AdminDashboardService();

        delete_transient(self::QUEUE_TRANSIENT);
    }

    protected function tearDown(): void
    {
        delete_transient(self::QUEUE_TRANSIENT);

        require_once ABSPATH . 'wp-admin/includes/user.php';
        if ($this->adminId) {
            wp_delete_user($this->adminId);
            $this->adminId = null;
        }
        if ($this->viewerId) {
            wp_delete_user($this->viewerId);
            $this->viewerId = 0;
        }

        // Restore the request context. set_current_screen('dashboard') in setUp
        // leaves is_admin() true for the rest of the process; under admin context
        // a wp_update_post() status change does NOT bust the NTDST_Query_Cache
        // count cache (a latent admin-context fragility), which would silently
        // corrupt sibling tests that assert on session counts (e.g.
        // CatalogBatchHydrationTest). Reset to a front-end context + anon user so
        // this test leaks nothing.
        set_current_screen('front');
        wp_set_current_user(0);

        parent::tearDown();
    }

    /** Listener side: the bulk_completed event must bust the queue transient. */
    public function test_bulk_completed_event_busts_action_queue(): void
    {
        set_transient(self::QUEUE_TRANSIENT, ['stale' => true], 300);

        do_action('stride/registration/bulk_completed', ['summary' => ['ok' => 3, 'error' => 0]]);

        $this->assertFalse(
            get_transient(self::QUEUE_TRANSIENT),
            'bulk_completed must bust the stride_action_queue transient',
        );
    }

    /** Listener side: the quote_status_changed event must bust the queue transient. */
    public function test_quote_status_changed_event_busts_action_queue(): void
    {
        set_transient(self::QUEUE_TRANSIENT, ['stale' => true], 300);

        do_action('stride/registration/quote_status_changed', ['count' => 1]);

        $this->assertFalse(
            get_transient(self::QUEUE_TRANSIENT),
            'quote_status_changed must bust the stride_action_queue transient',
        );
    }

    /**
     * Un-mocked seam: a REAL bulk handler invocation must, through its own
     * do_action fire and the real listener, leave the transient busted. This is
     * the wire between handler → do_action → bust, exercised end to end with no
     * stubbing of either half.
     *
     * An empty-id batch is sufficient: per the M10 contract a finished batch
     * fires bulk_completed regardless of row outcome (harmless recount).
     */
    public function test_real_bulk_handler_busts_queue_through_do_action(): void
    {
        $this->actingAsManager();
        $handler = new BulkRegistrationHandler();

        set_transient(self::QUEUE_TRANSIENT, ['stale' => true], 300);

        $report = $handler->handleBulkApprove([], ['ids' => []]);

        $this->assertIsArray($report, 'handler returned WP_Error — capability gate denied a manager');
        $this->assertFalse(
            get_transient(self::QUEUE_TRANSIENT),
            'a finished bulk batch must bust the queue through the real do_action → listener wire',
        );
    }

    /**
     * Negative / adversarial half of the seam: a DENIED bulk call (view-only
     * actor) must NOT fire bulk_completed — no batch ran, so the queue stays.
     * Proves the fire is at the batch tail, not the method entry.
     */
    public function test_denied_bulk_handler_does_not_bust_queue(): void
    {
        $this->actingAsViewer();
        $handler = new BulkRegistrationHandler();

        set_transient(self::QUEUE_TRANSIENT, ['stale' => true], 300);

        $result = $handler->handleBulkApprove([], ['ids' => [1, 2]]);

        $this->assertInstanceOf(\WP_Error::class, $result, 'view-only actor must be denied');
        $this->assertSame('forbidden', $result->get_error_code());
        $this->assertNotFalse(
            get_transient(self::QUEUE_TRANSIENT),
            'a denied bulk call must not fire bulk_completed — no batch ran',
        );
    }

    private function actingAsManager(): void
    {
        $this->ensureAdmin();
        get_role('administrator')?->add_cap('stride_manage');
        wp_set_current_user($this->adminId);
    }

    private function actingAsViewer(): void
    {
        // A subscriber has neither stride_manage nor administrator caps.
        $viewerId = $this->ensureViewer();
        wp_set_current_user($viewerId);
    }

    private function ensureAdmin(): void
    {
        if ($this->adminId !== null) {
            return;
        }
        $id = wp_create_user(
            'admin_bcb_' . wp_generate_password(6, false),
            'testpass',
            'adminbcb_' . wp_generate_password(6, false) . '@test.local',
        );
        $this->assertIsInt($id, 'Failed to create admin user');
        wp_update_user(['ID' => $id, 'role' => 'administrator']);
        $this->adminId = $id;
    }

    private function ensureViewer(): int
    {
        if ($this->viewerId) {
            return $this->viewerId;
        }
        $id = wp_create_user(
            'viewer_bcb_' . wp_generate_password(6, false),
            'testpass',
            'viewerbcb_' . wp_generate_password(6, false) . '@test.local',
        );
        $this->assertIsInt($id, 'Failed to create viewer user');
        wp_update_user(['ID' => $id, 'role' => 'subscriber']);
        $this->viewerId = $id;
        return $id;
    }
}
