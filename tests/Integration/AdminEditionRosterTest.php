<?php

declare(strict_types=1);

namespace Stride\Tests\Integration;

use IntegrationTestCase;
use Stride\Admin\AdminEditionRosterService;
use Stride\Domain\AttendanceStatus;
use Stride\Domain\RegistrationStatus;
use Stride\Modules\Attendance\AttendanceRepository;
use Stride\Modules\Attendance\AttendanceTable;
use Stride\Modules\Enrollment\RegistrationRepository;

/**
 * Integration tests for the per-edition roster read-model (Phase 2a, Task 2a.1).
 *
 * Asserts the contract of AdminEditionRosterService::getRosterForEdition:
 *  1. Session selections are reflected via the convergence point
 *     (RegistrationRepository::getSelections / a batched equivalent) — NOT a raw
 *     ->selections decode in the service (INV-6b).
 *  2. Attendance is batch-read via AttendanceRepository::getByUsers($userIds, $editionId)
 *     over the loaded set (CM-3 loaded-set).
 *  3. The method signature accepts NO param that binds enrollment_data/selections
 *     into a SQL WHERE/GROUP BY (CM-3/M5) — proven by reflection on the signature.
 *  4. A registrant with _stride_anonymised_at set appears in the roster with PII
 *     redacted (name tombstoned, extras suppressed) — not in full, not omitted (CM-3b).
 *
 * Run: ddev exec vendor/bin/phpunit -c phpunit-integration.xml.dist --filter AdminEditionRoster
 */
class AdminEditionRosterTest extends IntegrationTestCase
{
    private static ?int $editionId = null;

    // Registrant with a known session selection set + present attendance.
    private static ?int $selectorUserId = null;
    private static ?int $selectorRegId = null;

    // A second plain registrant (no selection) to prove loaded-set assembly.
    private static ?int $plainUserId = null;
    private static ?int $plainRegId = null;

    // A GDPR-erased registrant — must appear redacted, never omitted, never full.
    private static ?int $anonUserId = null;
    private static ?int $anonRegId = null;

    // Known session IDs the selector picked.
    private static ?int $sessionAId = null;
    private static ?int $sessionBId = null;

    private static array $regIds = [];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::$editionId = self::createEdition('RosterTest Edition ' . time());

        // Two sessions linked to the edition.
        self::$sessionAId = self::createSession('RosterTest Sessie A ' . time(), self::$editionId);
        self::$sessionBId = self::createSession('RosterTest Sessie B ' . time(), self::$editionId);

        $repo = ntdst_get(RegistrationRepository::class);

        // --- Selector: registered, with a known session selection set ---
        self::$selectorUserId = self::createUser('roster_selector');
        update_user_meta(self::$selectorUserId, 'first_name', 'Selena');
        update_user_meta(self::$selectorUserId, 'last_name', 'Selector');
        $sel = $repo->create([
            'user_id'    => self::$selectorUserId,
            'edition_id' => self::$editionId,
            'status'     => RegistrationStatus::Confirmed->value,
        ]);
        self::assertValidRegId($sel, 'selector');
        self::$selectorRegId = (int) $sel;
        self::$regIds[] = (int) $sel;
        // Assign selections THROUGH the repository write path (decoded on read).
        $repo->setSelections(self::$selectorRegId, [self::$sessionAId, self::$sessionBId]);

        // Present attendance for session A so getByUsers has a loaded-set row.
        $attRepo = ntdst_get(AttendanceRepository::class);
        $attRepo->record(self::$sessionAId, self::$selectorUserId, AttendanceStatus::Present, self::$editionId);

        // --- Plain registrant: no selection ---
        self::$plainUserId = self::createUser('roster_plain');
        update_user_meta(self::$plainUserId, 'first_name', 'Paula');
        update_user_meta(self::$plainUserId, 'last_name', 'Plain');
        $plain = $repo->create([
            'user_id'    => self::$plainUserId,
            'edition_id' => self::$editionId,
            'status'     => RegistrationStatus::Confirmed->value,
        ]);
        self::assertValidRegId($plain, 'plain');
        self::$plainRegId = (int) $plain;
        self::$regIds[] = (int) $plain;

        // --- Anonymised registrant: PII must be redacted but row present ---
        self::$anonUserId = self::createUser('roster_anon');
        update_user_meta(self::$anonUserId, 'first_name', 'Anneke');
        update_user_meta(self::$anonUserId, 'last_name', 'Anoniem');
        update_user_meta(self::$anonUserId, '_stride_anonymised_at', time());
        $anon = $repo->create([
            'user_id'    => self::$anonUserId,
            'edition_id' => self::$editionId,
            'status'     => RegistrationStatus::Confirmed->value,
        ]);
        self::assertValidRegId($anon, 'anon');
        self::$anonRegId = (int) $anon;
        self::$regIds[] = (int) $anon;
    }

    public static function tearDownAfterClass(): void
    {
        global $wpdb;

        foreach (self::$regIds as $id) {
            $wpdb->delete($wpdb->prefix . 'vad_registrations', ['id' => $id]);
        }

        if (AttendanceTable::exists()) {
            $wpdb->delete(AttendanceTable::getTableName(), ['edition_id' => self::$editionId]);
        }

        foreach ([self::$editionId, self::$sessionAId, self::$sessionBId] as $postId) {
            if ($postId) {
                wp_delete_post($postId, true);
            }
        }

        foreach ([self::$selectorUserId, self::$plainUserId, self::$anonUserId] as $uid) {
            if ($uid) {
                require_once ABSPATH . 'wp-admin/includes/user.php';
                wp_delete_user($uid);
            }
        }

        parent::tearDownAfterClass();
    }

    private function service(): AdminEditionRosterService
    {
        return ntdst_get(AdminEditionRosterService::class);
    }

    /**
     * Index the roster rows by registration id for assertions.
     *
     * @param array<string,mixed> $roster
     * @return array<int,array<string,mixed>>
     */
    private function rowsByRegId(array $roster): array
    {
        $rows = $roster['rows'] ?? [];
        $byId = [];
        foreach ($rows as $row) {
            $byId[(int) $row['registration_id']] = $row;
        }
        return $byId;
    }

    public function test_roster_reflects_session_selections_via_convergence_method(): void
    {
        $roster = $this->service()->getRosterForEdition(self::$editionId);
        $byId = $this->rowsByRegId($roster);

        $this->assertArrayHasKey(self::$selectorRegId, $byId, 'selector row present');
        $selectorRow = $byId[self::$selectorRegId];

        // Selections are flat session IDs read through the convergence method
        // (getSelections decodes the JSON column server-side).
        $this->assertContains(self::$sessionAId, $selectorRow['selections']);
        $this->assertContains(self::$sessionBId, $selectorRow['selections']);

        // The plain registrant has no selection.
        $this->assertSame([], $byId[self::$plainRegId]['selections']);
    }

    public function test_selections_are_not_decoded_from_a_raw_column_in_the_service(): void
    {
        // INV-6b mechanical guard: the service must not perform its own
        // json_decode on a raw ->selections column. It reads through the
        // repository convergence method instead.
        $servicePath = (new \ReflectionClass(AdminEditionRosterService::class))->getFileName();
        $source = file_get_contents($servicePath);
        $this->assertIsString($source);
        $this->assertStringNotContainsString(
            '->selections',
            $source,
            'AdminEditionRosterService must not touch the raw ->selections column — read via getSelections (INV-6b)',
        );
    }

    public function test_attendance_is_batch_read_over_the_loaded_set(): void
    {
        $roster = $this->service()->getRosterForEdition(self::$editionId);
        $byId = $this->rowsByRegId($roster);

        // Selector has one present record for session A.
        $this->assertSame(1, $byId[self::$selectorRegId]['attendance']['present']);
        // Plain registrant has no attendance records.
        $this->assertSame(0, $byId[self::$plainRegId]['attendance']['present']);
    }

    public function test_signature_accepts_no_param_that_binds_json_into_sql(): void
    {
        // CM-3 / M5 enforced by construction: getRosterForEdition's signature is
        // (int $editionId, array $filters = []). There is NO scalar param named
        // for an extras/selections key, and $filters is applied over the loaded
        // set (never interpolated into SQL).
        $ref = new \ReflectionMethod(AdminEditionRosterService::class, 'getRosterForEdition');
        $params = $ref->getParameters();

        $this->assertSame('editionId', $params[0]->getName());
        $this->assertSame('int', (string) $params[0]->getType());

        $this->assertSame('filters', $params[1]->getName());
        $this->assertSame('array', (string) $params[1]->getType());
        $this->assertTrue($params[1]->isOptional(), 'filters must be optional (loaded-set only)');

        // No third param exists that could carry a raw SQL-bound key.
        $this->assertCount(2, $params, 'getRosterForEdition takes exactly (editionId, filters)');
    }

    public function test_anonymised_registrant_is_present_but_pii_redacted(): void
    {
        $roster = $this->service()->getRosterForEdition(self::$editionId);
        $byId = $this->rowsByRegId($roster);

        // Present (not omitted).
        $this->assertArrayHasKey(self::$anonRegId, $byId, 'anonymised registrant must still appear');
        $anonRow = $byId[self::$anonRegId];

        // PII redacted (name tombstoned, not the real name).
        $this->assertTrue($anonRow['is_anonymised']);
        $this->assertSame('(verwijderd)', $anonRow['name']);
        $this->assertStringNotContainsString('Anneke', $anonRow['name']);
        $this->assertStringNotContainsString('Anoniem', $anonRow['name']);

        // Extras suppressed for the erased user.
        $this->assertSame([], $anonRow['extras']);

        // A non-anonymised registrant still shows their real name (control).
        $this->assertFalse($byId[self::$selectorRegId]['is_anonymised']);
        $this->assertStringContainsString('Selena', $byId[self::$selectorRegId]['name']);
    }

    // === Fixtures ===

    private static function createEdition(string $title): int
    {
        $postId = wp_insert_post([
            'post_title'  => $title,
            'post_type'   => 'vad_edition',
            'post_status' => 'publish',
        ]);
        if (is_wp_error($postId) || !$postId) {
            throw new \RuntimeException("Failed to create edition: {$title}");
        }
        update_post_meta($postId, '_ntdst_status', 'open');
        update_post_meta($postId, '_ntdst_capacity', 20);
        update_post_meta($postId, '_ntdst_start_date', date('Y-m-d', strtotime('+30 days')));
        return (int) $postId;
    }

    private static function createSession(string $title, int $editionId): int
    {
        $postId = wp_insert_post([
            'post_title'  => $title,
            'post_type'   => 'vad_session',
            'post_status' => 'publish',
        ]);
        if (is_wp_error($postId) || !$postId) {
            throw new \RuntimeException("Failed to create session: {$title}");
        }
        update_post_meta($postId, '_ntdst_edition_id', $editionId);
        return (int) $postId;
    }

    private static function createUser(string $prefix): int
    {
        $u = wp_create_user($prefix . '_' . uniqid(), 'pass123', $prefix . '_' . uniqid() . '@test.local');
        if (is_wp_error($u)) {
            throw new \RuntimeException("Failed to create user {$prefix}: " . $u->get_error_message());
        }
        return (int) $u;
    }

    private static function assertValidRegId(mixed $result, string $label): void
    {
        if (is_wp_error($result)) {
            throw new \RuntimeException("Failed to create registration {$label}: " . $result->get_error_message());
        }
        if (!is_int($result) || $result <= 0) {
            throw new \RuntimeException("Invalid registration ID for {$label}: " . var_export($result, true));
        }
    }
}
