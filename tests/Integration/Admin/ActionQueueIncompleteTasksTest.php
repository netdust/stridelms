<?php

declare(strict_types=1);

namespace Stride\Tests\Integration\Admin;

use IntegrationTestCase;
use Stride\Modules\Enrollment\RegistrationRepository;

/**
 * Regression for audit H-1 (dead column, action queue).
 *
 * The incomplete_tasks bucket in AdminAPIController::getActionQueue() queried
 * `r.tasks`, but the column on wp_vad_registrations is `completion_tasks`
 * (see RegistrationTable). The resulting DB error was swallowed into an empty
 * bucket, so overdue completion tasks NEVER surfaced on the admin dashboard.
 *
 * Contract (updated for the Vandaag slice, F-V1): a PENDING registration
 * older than the rule cutoff with an open USER task (real task shape —
 * {type: {status: 'pending'}}; the old '"completed":false' boolean was a key
 * no writer ever produces, which is why the melding never fired) MUST yield
 * an `incomplete_tasks` item in GET /stride/v1/admin/action-queue. Rows
 * whose only open task is the ADMIN's approval must NOT count — the melding
 * deep-links to the "Wacht op gebruiker" tab.
 *
 * Run: ddev exec vendor/bin/phpunit -c phpunit-integration.xml.dist --filter ActionQueueIncompleteTasksTest
 */
final class ActionQueueIncompleteTasksTest extends IntegrationTestCase
{
    private const RULES_OPTION = 'stride_notification_rules';
    private const QUEUE_TRANSIENT = 'stride_action_queue';

    private RegistrationRepository $registrations;

    /** @var list<int> */
    private array $testRegistrationIds = [];

    private mixed $savedRules = false;
    private ?int $adminId = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registrations = ntdst_get(RegistrationRepository::class);

        // Ensure REST routes are registered.
        do_action('rest_api_init');

        // Force the incomplete_tasks rule to a known state (enabled, 7-day
        // window) regardless of what the dev DB has saved. Other rules keep
        // their defaults via the array_merge in getNotificationRules().
        $this->savedRules = get_option(self::RULES_OPTION, false);
        update_option(self::RULES_OPTION, [
            'incomplete_tasks' => ['enabled' => true, 'value' => 7],
        ]);

        delete_transient(self::QUEUE_TRANSIENT);
    }

    protected function tearDown(): void
    {
        global $wpdb;
        foreach ($this->testRegistrationIds as $regId) {
            $wpdb->delete($wpdb->prefix . 'vad_registrations', ['id' => $regId]);
        }
        $this->testRegistrationIds = [];

        if ($this->savedRules === false) {
            delete_option(self::RULES_OPTION);
        } else {
            update_option(self::RULES_OPTION, $this->savedRules);
        }
        delete_transient(self::QUEUE_TRANSIENT);

        if ($this->adminId) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
            wp_delete_user($this->adminId);
            $this->adminId = null;
        }

        parent::tearDown();
    }

    public function testOverdueIncompleteTaskSurfacesInActionQueue(): void
    {
        $this->seedOverdueRegistrationWithIncompleteTask();

        $response = $this->dispatchActionQueue();

        $this->assertSame(
            200,
            $response->get_status(),
            'Expected 200 from action-queue, got ' . $response->get_status()
            . ' (body: ' . wp_json_encode($response->get_data()) . ')'
        );

        $items = (array) $response->get_data();
        $incomplete = array_values(array_filter(
            $items,
            static fn($item) => is_array($item) && ($item['rule'] ?? null) === 'incomplete_tasks'
        ));

        $this->assertNotEmpty(
            $incomplete,
            'Expected an incomplete_tasks item for the seeded overdue registration. '
            . $this->dbErrorContext()
        );
    }

    public function testDisabledRuleProducesNoIncompleteTasksItem(): void
    {
        // Negative path: same qualifying data, rule switched OFF — the bucket
        // must not appear. Deterministic regardless of other rows in the DB,
        // because the rule gate short-circuits before any data is evaluated.
        update_option(self::RULES_OPTION, [
            'incomplete_tasks' => ['enabled' => false, 'value' => 7],
        ]);
        delete_transient(self::QUEUE_TRANSIENT);

        $this->seedOverdueRegistrationWithIncompleteTask();

        $response = $this->dispatchActionQueue();
        $this->assertSame(200, $response->get_status());

        $items = (array) $response->get_data();
        $incomplete = array_filter(
            $items,
            static fn($item) => is_array($item) && ($item['rule'] ?? null) === 'incomplete_tasks'
        );

        $this->assertSame(
            [],
            array_values($incomplete),
            'incomplete_tasks rule is disabled — no item may be emitted'
        );
    }

    /**
     * Confirmed registration, registered 30 days ago (well past the 7-day
     * cutoff), with a completion task that is not completed.
     */
    private function seedOverdueRegistrationWithIncompleteTask(): int
    {
        global $wpdb;

        $editionId = $this->createTestEdition();

        $regId = $this->registrations->create([
            'user_id' => self::$testUserId,
            'edition_id' => $editionId,
            'status' => 'pending',
        ]);

        $this->assertIsInt($regId, 'Failed to create pending registration: ' . wp_json_encode($regId));
        $this->testRegistrationIds[] = $regId;

        // Backdate past the cutoff and attach an incomplete completion task.
        // The repository stamps registered_at itself, so fixture surgery via
        // direct update is the only way to age the row.
        $updated = $wpdb->update(
            $wpdb->prefix . 'vad_registrations',
            [
                'registered_at' => wp_date('Y-m-d H:i:s', strtotime('-30 days')),
                'completion_tasks' => wp_json_encode([
                    // Real writer shape (EnrollmentCompletion): an open USER
                    // task — waiting on the participant, not the admin.
                    'questionnaire' => ['status' => 'pending', 'phase' => 'enrollment'],
                ]),
            ],
            ['id' => $regId],
        );
        $this->assertNotFalse($updated, 'Failed to backdate registration fixture: ' . $wpdb->last_error);

        return $regId;
    }

    private function dispatchActionQueue(): \WP_REST_Response
    {
        global $wpdb;

        if ($this->adminId === null) {
            $adminId = wp_create_user(
                'admin_aq_test_' . wp_generate_password(6, false),
                'testpass',
                'adminaq_' . wp_generate_password(6, false) . '@test.local'
            );
            $this->assertIsInt($adminId, 'Failed to create admin user');
            wp_update_user(['ID' => $adminId, 'role' => 'administrator']);
            $this->adminId = $adminId;
        }
        wp_set_current_user($this->adminId);

        // Capture DB errors without echoing them (the suite is strict about
        // output); $EZSQL_ERROR is filled by wpdb regardless of show_errors.
        $GLOBALS['EZSQL_ERROR'] = [];
        $wasShowing = $wpdb->hide_errors();

        $request = new \WP_REST_Request('GET', '/stride/v1/admin/action-queue');
        $response = rest_get_server()->dispatch($request);

        if ($wasShowing) {
            $wpdb->show_errors();
        }

        $this->assertNotInstanceOf(\WP_Error::class, $response, 'REST dispatch returned WP_Error');

        return $response;
    }

    /**
     * Attribute an empty bucket to its real cause: if the SQL hit a dead
     * column, the swallowed wpdb error is reproduced in the failure message.
     */
    private function dbErrorContext(): string
    {
        global $wpdb;

        $captured = array_slice((array) ($GLOBALS['EZSQL_ERROR'] ?? []), -3);

        return 'wpdb->last_error: ' . var_export($wpdb->last_error, true)
            . '; captured DB errors during dispatch: ' . wp_json_encode($captured);
    }
}
