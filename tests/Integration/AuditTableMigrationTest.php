<?php

declare(strict_types=1);

namespace Stride\Tests\Integration;

use IntegrationTestCase;
use NTDST\Audit\AuditTable;

/**
 * Integration tests for the ntdst-audit subject_user_id migration (audit H-4 / task F1).
 *
 * Contract (threat-model M8 is the spec):
 *  - Row count identical before/after the migration (no row rewrites — DDL only).
 *  - subject_user_id is a STORED generated column populated from context.user_id:
 *    int and numeric-string values populate; absent key, NULL context, JSON null
 *    and non-numeric garbage all yield NULL (the strict-mode CAST trap).
 *  - The rewritten badge query uses indexes: EXPLAIN shows no full table scan
 *    (type != ALL, key non-null) on each UNION branch.
 *  - Running the migration twice is a no-op (idempotent, IF NOT EXISTS DDL).
 *
 * Run: ddev exec "vendor/bin/phpunit -c phpunit-integration.xml.dist --filter AuditTableMigration"
 */
final class AuditTableMigrationTest extends IntegrationTestCase
{
    private const SCHEMA_OPTION = 'ntdst_audit_schema_version';

    /** @var array<int> */
    private array $seededIds = [];

    protected function tearDown(): void
    {
        global $wpdb;

        if ($this->seededIds !== []) {
            $ids = implode(',', array_map('intval', $this->seededIds));
            $wpdb->query("DELETE FROM {$this->table()} WHERE id IN ({$ids})");
            $this->seededIds = [];
        }

        // Re-run the migration so the dev table always ends at the latest
        // schema, even if a forced-rerun test failed mid-way.
        AuditTable::migrate();
        update_option(self::SCHEMA_OPTION, AuditTable::SCHEMA_VERSION);

        parent::tearDown();
    }

    /** @test */
    public function migrationPreservesRowCountAndPopulatesSubjectUserId(): void
    {
        global $wpdb;

        $this->dropMigrationArtifacts();

        // Seed every context shape BEFORE the column exists, so the ALTER
        // itself computes them (the strict-mode CAST trap fires here if the
        // expression is wrong, aborting the whole ALTER).
        $intId = $this->seedRow('{"user_id": 777000111}');
        $stringId = $this->seedRow('{"user_id": "777000112"}');
        $absentId = $this->seedRow('{"edition_id": 5}');
        $nullCtxId = $this->seedRow(null);
        $jsonNullId = $this->seedRow('{"user_id": null}');
        $garbageId = $this->seedRow('{"user_id": "abc"}');

        $before = $this->rowCount();

        delete_option(self::SCHEMA_OPTION);
        AuditTable::migrate();

        $this->assertSame($before, $this->rowCount(), 'M8: row count must be identical before/after the migration');

        $this->assertSame(777000111, $this->subjectOf($intId), 'int context.user_id must populate');
        $this->assertSame(777000112, $this->subjectOf($stringId), 'numeric-string context.user_id must populate');
        $this->assertNull($this->subjectOf($absentId), 'absent user_id key must yield NULL');
        $this->assertNull($this->subjectOf($nullCtxId), 'NULL context must yield NULL');
        $this->assertNull($this->subjectOf($jsonNullId), 'JSON null user_id must yield NULL (not CAST error)');
        $this->assertNull($this->subjectOf($garbageId), 'non-numeric user_id must yield NULL (not CAST error)');

        $this->assertSame(
            AuditTable::SCHEMA_VERSION,
            (int) get_option(self::SCHEMA_OPTION),
            'Schema version option must be stamped after migration',
        );
    }

    /** @test */
    public function badgeQueryUsesIndexesAfterMigration(): void
    {
        global $wpdb;

        delete_option(self::SCHEMA_OPTION);
        AuditTable::migrate();

        $since = (new \DateTime('-30 days'))->format('Y-m-d H:i:s');

        $plan = $wpdb->get_results($wpdb->prepare(
            "EXPLAIN
            (SELECT * FROM {$this->table()}
              WHERE subject_user_id = %d
                AND (actor_id IS NULL OR actor_id != %d)
                AND created_at >= %s)
            UNION
            (SELECT * FROM {$this->table()}
              WHERE actor_id = %d
                AND action LIKE 'completion.%%'
                AND created_at >= %s)
            ORDER BY created_at DESC LIMIT 50",
            777000111,
            777000111,
            $since,
            777000111,
            $since,
        ));

        $this->assertNotEmpty($plan, 'EXPLAIN should return a query plan');

        $branches = array_filter($plan, fn(object $row): bool => $row->select_type !== 'UNION RESULT');
        $this->assertNotEmpty($branches);

        foreach ($branches as $row) {
            $this->assertNotSame('ALL', $row->type, 'H-4: badge query branch must not full-scan the audit table');
            $this->assertNotNull($row->key, 'H-4: badge query branch must use an index');
        }
    }

    /** @test */
    public function migrationIsIdempotent(): void
    {
        global $wpdb;

        delete_option(self::SCHEMA_OPTION);
        AuditTable::migrate();

        $count = $this->rowCount();

        // Guarded second run: version stamped, must be a no-op.
        AuditTable::migrate();
        $this->assertSame('', $wpdb->last_error, 'Guarded second run must not error');

        // Forced second run: IF NOT EXISTS DDL must be a clean no-op.
        delete_option(self::SCHEMA_OPTION);
        AuditTable::migrate();

        $this->assertSame('', $wpdb->last_error, 'Forced second run must not error');
        $this->assertSame($count, $this->rowCount(), 'Forced second run must not change row count');
        $this->assertSame(AuditTable::SCHEMA_VERSION, (int) get_option(self::SCHEMA_OPTION));
    }

    // === Helpers ===

    private function table(): string
    {
        return AuditTable::getTableName();
    }

    private function seedRow(?string $contextJson): int
    {
        global $wpdb;

        $result = $wpdb->insert($this->table(), [
            'entity_type' => 'phpunit_migration',
            'entity_id' => 1,
            'action' => 'phpunit.migration_seed',
            'actor_id' => null,
            'actor_type' => 'system',
            'context' => $contextJson,
        ]);

        $this->assertNotFalse($result, 'Raw seed insert failed: ' . $wpdb->last_error);

        $id = (int) $wpdb->insert_id;
        $this->seededIds[] = $id;

        return $id;
    }

    private function rowCount(): int
    {
        global $wpdb;

        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->table()}");
    }

    private function subjectOf(int $id): ?int
    {
        global $wpdb;

        $value = $wpdb->get_var($wpdb->prepare(
            "SELECT subject_user_id FROM {$this->table()} WHERE id = %d",
            $id,
        ));

        return $value === null ? null : (int) $value;
    }

    /**
     * Drop the v2 artifacts so migrate() exercises the real ALTER every run
     * (indexes first — dropping the column would silently shrink them).
     */
    private function dropMigrationArtifacts(): void
    {
        global $wpdb;

        $wpdb->query("ALTER TABLE {$this->table()} DROP INDEX IF EXISTS idx_subject_user");
        $wpdb->query("ALTER TABLE {$this->table()} DROP INDEX IF EXISTS idx_action");
        $wpdb->query("ALTER TABLE {$this->table()} DROP COLUMN IF EXISTS subject_user_id");
    }
}
