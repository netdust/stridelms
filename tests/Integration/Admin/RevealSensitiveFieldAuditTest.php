<?php

declare(strict_types=1);

namespace Stride\Tests\Integration\Admin;

use IntegrationTestCase;
use NTDST\Audit\AuditTable;

/**
 * Audit-remediation Task B4 (audit finding M-1, threat-model M5).
 *
 * Every successful reveal of a sensitive identity field via
 * GET /stride/v1/admin/users/{id}/reveal MUST persist exactly one audit row
 * (entity=target user, actor=the revealing admin, context.field=the field) —
 * including when the meta value is empty (the access attempt is the event,
 * AF-2 empty edge) and for `phone` (the 4th allowed field). Denied requests
 * (invalid field → 400, non-existent user → 404) must write NO audit row.
 *
 * Assertions run against the persisted ntdst-audit table — no mocks.
 *
 * Run: ddev exec vendor/bin/phpunit -c phpunit-integration.xml.dist --filter RevealSensitiveFieldAuditTest
 */
final class RevealSensitiveFieldAuditTest extends IntegrationTestCase
{
    private const ACTION = 'admin.pii_reveal';

    private ?int $adminId = null;

    /** @var list<int> Entity ids whose audit rows we created and must clean. */
    private array $auditedEntityIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Ensure REST routes are registered.
        do_action('rest_api_init');

        $adminId = wp_create_user(
            'admin_reveal_test_' . wp_generate_password(6, false),
            'testpass',
            'adminreveal_' . wp_generate_password(6, false) . '@test.local'
        );
        $this->assertIsInt($adminId, 'Failed to create admin user');
        wp_update_user(['ID' => $adminId, 'role' => 'administrator']);
        $this->adminId = $adminId;
    }

    protected function tearDown(): void
    {
        global $wpdb;

        foreach (array_unique($this->auditedEntityIds) as $entityId) {
            $wpdb->delete(AuditTable::getTableName(), [
                'action' => self::ACTION,
                'entity_type' => 'user',
                'entity_id' => $entityId,
            ]);
        }
        $this->auditedEntityIds = [];

        $this->cleanupUserMeta(self::$testUserId, [
            'national_id',
            'phone',
            'date_of_birth',
        ]);

        if ($this->adminId) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
            wp_delete_user($this->adminId);
            $this->adminId = null;
        }

        parent::tearDown();
    }

    public function testSuccessfulRevealWritesExactlyOneAuditRow(): void
    {
        update_user_meta(self::$testUserId, 'national_id', '85.07.30-033.61');

        $before = count($this->auditRowsFor(self::$testUserId));

        $response = $this->dispatchReveal(self::$testUserId, 'national_id');

        $this->assertSame(200, $response->get_status());
        $data = (array) $response->get_data();
        $this->assertSame('national_id', $data['field'] ?? null);
        $this->assertSame('85.07.30-033.61', $data['value'] ?? null);

        $rows = $this->auditRowsFor(self::$testUserId);
        $this->assertCount(
            $before + 1,
            $rows,
            'Exactly one admin.pii_reveal audit row must be persisted for a successful reveal'
        );

        $row = $rows[0];
        $this->assertSame('user', $row->entity_type);
        $this->assertSame((string) self::$testUserId, (string) $row->entity_id, 'Audit entity must be the target user');
        $this->assertSame((string) $this->adminId, (string) $row->actor_id, 'Audit actor must be the revealing admin');

        $context = json_decode((string) $row->context, true);
        $this->assertSame('national_id', $context['field'] ?? null, 'context.field must record which field was revealed');
    }

    public function testPhoneFieldIsAuditedIdentically(): void
    {
        // `phone` is the 4th allowed field (audit M-1 correction — not 3).
        update_user_meta(self::$testUserId, 'phone', '+32 470 12 34 56');

        $before = count($this->auditRowsFor(self::$testUserId));

        $response = $this->dispatchReveal(self::$testUserId, 'phone');

        $this->assertSame(200, $response->get_status());

        $rows = $this->auditRowsFor(self::$testUserId);
        $this->assertCount($before + 1, $rows);

        $context = json_decode((string) $rows[0]->context, true);
        $this->assertSame('phone', $context['field'] ?? null);
    }

    public function testEmptyMetaValueStillWritesAuditRow(): void
    {
        // AF-2 empty edge: the access attempt is the event, even when the
        // meta value is absent.
        delete_user_meta(self::$testUserId, 'date_of_birth');

        $before = count($this->auditRowsFor(self::$testUserId));

        $response = $this->dispatchReveal(self::$testUserId, 'date_of_birth');

        $this->assertSame(200, $response->get_status());
        $data = (array) $response->get_data();
        $this->assertSame('', $data['value'] ?? null, 'Empty meta must reveal as empty string');

        $rows = $this->auditRowsFor(self::$testUserId);
        $this->assertCount(
            $before + 1,
            $rows,
            'An empty value is still a PII access attempt — the audit row must be written'
        );
    }

    public function testTwoRapidRevealsWriteTwoAuditRows(): void
    {
        // AF-2 concurrent/double edge: each access is an event, no dedupe.
        update_user_meta(self::$testUserId, 'national_id', '85.07.30-033.61');

        $before = count($this->auditRowsFor(self::$testUserId));

        $this->dispatchReveal(self::$testUserId, 'national_id');
        $this->dispatchReveal(self::$testUserId, 'national_id');

        $this->assertCount(
            $before + 2,
            $this->auditRowsFor(self::$testUserId),
            'Two reveals must produce two audit rows — access events are never deduped'
        );
    }

    public function testInvalidFieldReturns400AndWritesNoAuditRow(): void
    {
        // Adversarial: a real, sensitive-looking meta key that is NOT on the
        // allow-list must be rejected before any value read or audit write.
        $before = count($this->auditRowsFor(self::$testUserId));

        $response = $this->dispatchReveal(self::$testUserId, 'session_tokens');

        $this->assertSame(400, $response->get_status());
        // C2: the denial path now returns the WP_Error body shape
        // ({code, message, data.status}), not the old {error: ...} envelope.
        $data = (array) $response->get_data();
        $this->assertArrayNotHasKey('error', $data, 'C2: old {error: ...} envelope must be gone');
        $this->assertSame('invalid_field', $data['code'] ?? null);
        $this->assertSame(400, (int) ($data['data']['status'] ?? 0));
        $this->assertCount(
            $before,
            $this->auditRowsFor(self::$testUserId),
            'A rejected field must not produce an audit row'
        );
    }

    public function testFailedAuditWriteLogsWarningAndDoesNotBlockReveal(): void
    {
        // Shake-out F4 (AF-2 ruling: availability over strictness). A failed
        // AuditService::record() must log via ntdst_log('audit') and NOT
        // block the reveal response. The failure is genuine: the
        // ntdst/audit/table_name filter points the insert at a table that
        // does not exist, so $wpdb->insert() fails for real — no mocks.
        global $wpdb;

        update_user_meta(self::$testUserId, 'national_id', '85.07.30-033.61');

        $before = count($this->auditRowsFor(self::$testUserId));

        $captured = [];
        $logSpy = static function ($level, $message, $context) use (&$captured): void {
            $captured[] = ['level' => $level, 'message' => (string) $message, 'context' => (array) $context];
        };
        $breakTable = static fn(): string => 'audit_log_missing_f4_test';

        add_action('ntdst_log_audit', $logSpy, 10, 3);
        add_filter('ntdst/audit/table_name', $breakTable);
        $suppressing = $wpdb->suppress_errors(true);

        try {
            $response = $this->dispatchReveal(self::$testUserId, 'national_id');
        } finally {
            $wpdb->suppress_errors($suppressing);
            remove_filter('ntdst/audit/table_name', $breakTable);
            remove_action('ntdst_log_audit', $logSpy, 10);
        }

        // 1. The reveal is NOT blocked: 200 + the value still returned.
        $this->assertSame(
            200,
            $response->get_status(),
            'A failed audit write must not block the reveal (availability over strictness — AF-2)'
        );
        $data = (array) $response->get_data();
        $this->assertSame('85.07.30-033.61', $data['value'] ?? null, 'Reveal value must survive the audit failure');

        // 2. No audit row landed in the REAL table (the write genuinely failed).
        $this->assertCount(
            $before,
            $this->auditRowsFor(self::$testUserId),
            'The failed insert must leave zero audit rows in the real table'
        );

        // 3. The failure was logged on the audit channel.
        $warnings = array_filter(
            $captured,
            static fn(array $entry): bool => str_contains($entry['message'], 'PII reveal audit write failed')
        );
        $this->assertNotEmpty(
            $warnings,
            'A failed audit write must log "PII reveal audit write failed" via ntdst_log(audit). Captured: '
            . wp_json_encode(array_column($captured, 'message'))
        );
        $warning = array_values($warnings)[0];
        $this->assertSame('national_id', $warning['context']['field'] ?? null, 'The warning context must name the field');
        $this->assertSame(self::$testUserId, $warning['context']['user_id'] ?? null, 'The warning context must name the user');
    }

    public function testNonExistentUserReturns404AndWritesNoAuditRow(): void
    {
        $bogusId = 99999999;
        $before = count($this->auditRowsFor($bogusId));

        $response = $this->dispatchReveal($bogusId, 'national_id');

        $this->assertSame(404, $response->get_status());
        // C2: the denial path now returns the WP_Error body shape.
        $data = (array) $response->get_data();
        $this->assertArrayNotHasKey('error', $data, 'C2: old {error: ...} envelope must be gone');
        $this->assertSame('not_found', $data['code'] ?? null);
        $this->assertSame(404, (int) ($data['data']['status'] ?? 0));
        $this->assertCount(
            $before,
            $this->auditRowsFor($bogusId),
            'A reveal against a non-existent user must not produce an audit row'
        );
    }

    private function dispatchReveal(int $userId, string $field): \WP_REST_Response
    {
        wp_set_current_user($this->adminId);

        $this->auditedEntityIds[] = $userId;

        $request = new \WP_REST_Request('GET', "/stride/v1/admin/users/{$userId}/reveal");
        $request->set_param('field', $field);

        $response = rest_get_server()->dispatch($request);

        $this->assertNotInstanceOf(\WP_Error::class, $response, 'REST dispatch returned WP_Error');

        return $response;
    }

    /**
     * Persisted admin.pii_reveal rows for an entity, newest first — straight
     * from the ntdst-audit table, no service-layer indirection.
     *
     * @return array<object>
     */
    private function auditRowsFor(int $entityId): array
    {
        global $wpdb;

        $table = AuditTable::getTableName();

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE action = %s AND entity_type = 'user' AND entity_id = %d
             ORDER BY id DESC",
            self::ACTION,
            $entityId
        ));
    }
}
