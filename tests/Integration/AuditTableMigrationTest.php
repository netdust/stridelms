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
 *  - CR-F1: numeric values that overflow BIGINT UNSIGNED and JSON booleans
 *    also yield NULL. Under STRICT_TRANS_TABLES (MySQL 8 default — probed
 *    live) an unguarded CAST makes the ALTER abort with error 1292 and, post
 *    migration, makes any INSERT carrying such a value fail — losing the
 *    audit row (the M5 detection mechanism, the worst failure mode). Under
 *    non-strict mode (local DDEV) the same bug shows as misattribution
 *    instead: overflow saturates to 18446744073709551615, JSON true → user 1.
 *    Malformed-JSON is not constructible: the JSON column type rejects it at
 *    INSERT on both flavors.
 *  - The rewritten badge query uses indexes: EXPLAIN shows no full table scan
 *    (type != ALL, key non-null) on each UNION branch.
 *  - Running the migration twice is a no-op (idempotent, IF NOT EXISTS DDL).
 *
 * Run: ddev exec "vendor/bin/phpunit -c phpunit-integration.xml.dist --filter AuditTableMigration"
 */
final class AuditTableMigrationTest extends IntegrationTestCase
{
    private const SCHEMA_OPTION = 'ntdst_audit_schema_version';

    private const BACKOFF_TRANSIENT = 'ntdst_audit_migration_backoff';

    /** @var array<int> */
    private array $seededIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        // A lingering failure-backoff (I1b) would make migrate() silently
        // no-op and turn every assertion below into a false negative.
        delete_transient(self::BACKOFF_TRANSIENT);
    }

    protected function tearDown(): void
    {
        global $wpdb;

        if ($this->seededIds !== []) {
            $ids = implode(',', array_map('intval', $this->seededIds));
            $wpdb->query("DELETE FROM {$this->table()} WHERE id IN ({$ids})");
            $this->seededIds = [];
        }

        // Re-run the migration so the dev table always ends at the latest
        // schema, even if a forced-rerun test failed mid-way. The backoff
        // transient must go first or migrate() bails.
        delete_transient(self::BACKOFF_TRANSIENT);
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

        // CR-F1 edges: values the bare RLIKE '^[0-9]+$' guard wrongly admits.
        $overflowStringId = $this->seedRow('{"user_id": "99999999999999999999999"}');
        $overflowIntId = $this->seedRow('{"user_id": 99999999999999999999999}');
        $twentyDigitId = $this->seedRow('{"user_id": "99999999999999999999"}');
        $jsonTrueId = $this->seedRow('{"user_id": true}');
        $nineteenDigitId = $this->seedRow('{"user_id": "9999999999999999999"}');

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

        // CR-F1: overflow + boolean must yield NULL — never abort the ALTER
        // (strict mode) or misattribute (non-strict saturation / true → 1).
        $this->assertNull($this->subjectOf($overflowStringId), 'CR-F1: >BIGINT UNSIGNED numeric string must yield NULL, not saturate/abort');
        $this->assertNull($this->subjectOf($overflowIntId), 'CR-F1: >BIGINT UNSIGNED JSON integer must yield NULL, not saturate/abort');
        $this->assertNull($this->subjectOf($twentyDigitId), 'CR-F1: 20-digit value must yield NULL (digit bound is 19)');
        $this->assertNull($this->subjectOf($jsonTrueId), 'CR-F1: JSON true must yield NULL, not misattribute to user 1');

        // 19-digit boundary stays populated (exceeds PHP_INT_MAX — compare as string).
        $this->assertSame(
            '9999999999999999999',
            $this->subjectRawOf($nineteenDigitId),
            'CR-F1: 19-digit value within BIGINT UNSIGNED must populate',
        );

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
            UNION ALL
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
    public function activityFeedOrderIsIndexOrderedAfterMigration(): void
    {
        // 4B.1: the activity feed runs `ORDER BY created_at DESC, id DESC LIMIT N`.
        // Without a (created_at, id) index this is a full-table scan + filesort.
        // idx_created_id STRUCTURALLY satisfies the keyset order: forcing it yields
        // an index scan with NO filesort. We assert the structural guarantee via
        // FORCE INDEX because the UNFORCED optimiser choice on a small, volatile
        // table is statistics-dependent (non-deterministic) — the fix is that an
        // index-ordered path now EXISTS, which FORCE INDEX proves deterministically.
        global $wpdb;

        for ($i = 0; $i < 40; $i++) {
            $this->seedRow('{"user_id": ' . (778000000 + $i) . '}');
        }

        delete_option(self::SCHEMA_OPTION);
        AuditTable::migrate();

        $this->assertTrue($this->indexExists('idx_created_id'), '4B.1: migrate() must add idx_created_id');

        $plan = $wpdb->get_results(
            "EXPLAIN SELECT * FROM {$this->table()} FORCE INDEX (idx_created_id)
             ORDER BY created_at DESC, id DESC LIMIT 50",
        );

        $this->assertNotEmpty($plan, 'EXPLAIN should return a query plan');

        $extra = (string) ($plan[0]->Extra ?? '');
        $usedKey = (string) ($plan[0]->key ?? '');

        $this->assertSame('idx_created_id', $usedKey, '4B.1: the keyset index must be usable for this order');
        $this->assertStringNotContainsString(
            'Using filesort',
            $extra,
            '4B.1: idx_created_id must provide index-ordered retrieval (no top-N filesort)',
        );
    }

    /** @test */
    public function postMigrationInsertsWithUnsafeSubjectValuesSucceed(): void
    {
        // Rebuild the column from the current expression — IF-NOT-EXISTS /
        // existence-checked DDL would otherwise keep a stale column from an
        // earlier code version (exactly the local-dev drift this PR fixes).
        $this->dropMigrationArtifacts();

        delete_option(self::SCHEMA_OPTION);
        AuditTable::migrate();

        // CR-F1 worst failure mode: with an unguarded CAST, a post-migration
        // INSERT carrying an overflow value fails under strict mode — the
        // audit row (the M5 detection mechanism) is silently lost. seedRow()
        // asserts the insert itself succeeded.
        $overflowId = $this->seedRow('{"user_id": "99999999999999999999999"}');
        $trueId = $this->seedRow('{"user_id": true}');
        $validId = $this->seedRow('{"user_id": 777000113}');

        $this->assertNull($this->subjectOf($overflowId), 'CR-F1: overflow insert must persist with NULL subject');
        $this->assertNull($this->subjectOf($trueId), 'CR-F1: JSON-true insert must persist with NULL subject');
        $this->assertSame(777000113, $this->subjectOf($validId), 'valid subject must still populate on insert');
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

    /** @test */
    public function freshInstallCreatesLatestSchemaWithoutAlter(): void
    {
        global $wpdb;

        // Route the whole AuditTable API at a scratch table (I1a): create()
        // must build the LATEST schema in one statement — column + both v2
        // indexes — and stamp the version, with ZERO ALTER needed after.
        $scratch = static fn(): string => 'phpunit_audit_fresh';
        add_filter('ntdst/audit/table_name', $scratch);

        $savedVersion = get_option(self::SCHEMA_OPTION);

        try {
            $wpdb->query("DROP TABLE IF EXISTS {$this->table()}");
            delete_option(self::SCHEMA_OPTION);

            AuditTable::create();

            $this->assertTrue(AuditTable::exists(), 'create() must build the table');
            $this->assertTrue($this->columnExists('subject_user_id'), 'I1a: fresh CREATE must include subject_user_id');
            $this->assertTrue($this->indexExists('idx_subject_user'), 'I1a: fresh CREATE must include idx_subject_user');
            $this->assertTrue($this->indexExists('idx_action'), 'I1a: fresh CREATE must include idx_action');
            $this->assertTrue($this->indexExists('idx_created_id'), '4B.1: fresh CREATE must include idx_created_id (created_at, id) keyset index');
            $this->assertTrue($this->indexExists('idx_entity'), 'base index must survive');

            $this->assertSame(
                AuditTable::SCHEMA_VERSION,
                (int) get_option(self::SCHEMA_OPTION),
                'I1a: create() must stamp SCHEMA_VERSION (fresh installs never re-run historical steps)',
            );

            // The generated column must compute on the fresh path too
            // (doubles as the dbDelta-mangling check).
            $validId = $this->seedRow('{"user_id": 777000114}');
            $overflowId = $this->seedRow('{"user_id": "99999999999999999999999"}');
            $this->assertSame(777000114, $this->subjectOf($validId));
            $this->assertNull($this->subjectOf($overflowId));
            $this->seededIds = []; // scratch table is dropped whole below

            $alters = $this->captureAlters(static fn() => AuditTable::migrate());
            $this->assertSame([], $alters, 'I1a: migrate() after a fresh create() must issue ZERO ALTERs');
        } finally {
            $wpdb->query("DROP TABLE IF EXISTS {$this->table()}");
            remove_filter('ntdst/audit/table_name', $scratch);

            if ($savedVersion !== false) {
                update_option(self::SCHEMA_OPTION, $savedVersion);
            }
        }
    }

    /** @test */
    public function failedMigrationSetsBackoffAndSkipsRetryWhileItLives(): void
    {
        global $wpdb;

        // Scratch table WITHOUT a context column: the v2 ALTER (generated
        // column referencing `context`) fails deterministically.
        $scratch = static fn(): string => 'phpunit_audit_backoff';
        add_filter('ntdst/audit/table_name', $scratch);

        $savedVersion = get_option(self::SCHEMA_OPTION);

        // The failing ALTERs below are the point of the test — keep wpdb's
        // error echo out of the output (failOnRisky).
        $suppressed = $wpdb->suppress_errors();

        try {
            $wpdb->query("DROP TABLE IF EXISTS {$this->table()}");
            $wpdb->query("CREATE TABLE {$this->table()} (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                action VARCHAR(50) NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )");
            update_option(self::SCHEMA_OPTION, 1);

            $alters = $this->captureAlters(static fn() => AuditTable::migrate());
            $this->assertNotSame([], $alters, 'Precondition: the failing run must attempt the ALTER');

            $this->assertNotFalse(
                get_transient(self::BACKOFF_TRANSIENT),
                'I1b: a failed migration must set the 5-min backoff transient',
            );
            $this->assertSame(1, (int) get_option(self::SCHEMA_OPTION), 'Failed run must not stamp the version');

            // While the backoff lives, migrate() must bail BEFORE any DDL.
            $alters = $this->captureAlters(static fn() => AuditTable::migrate());
            $this->assertSame([], $alters, 'I1b: migrate() must not retry while the backoff transient lives');

            // Once it lapses, the retry semantics are unchanged.
            delete_transient(self::BACKOFF_TRANSIENT);
            $alters = $this->captureAlters(static fn() => AuditTable::migrate());
            $this->assertNotSame([], $alters, 'I1b: lapsed backoff must restore the retry');
        } finally {
            $wpdb->suppress_errors($suppressed);
            delete_transient(self::BACKOFF_TRANSIENT);
            $wpdb->query("DROP TABLE IF EXISTS {$this->table()}");
            remove_filter('ntdst/audit/table_name', $scratch);

            if ($savedVersion !== false) {
                update_option(self::SCHEMA_OPTION, $savedVersion);
            }
        }
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
     * Raw string form — for values exceeding PHP_INT_MAX where an (int) cast
     * would itself corrupt the comparison.
     */
    private function subjectRawOf(int $id): ?string
    {
        global $wpdb;

        return $wpdb->get_var($wpdb->prepare(
            "SELECT subject_user_id FROM {$this->table()} WHERE id = %d",
            $id,
        ));
    }

    /**
     * Drop the v2 artifacts so migrate() exercises the real ALTER every run
     * (indexes first — dropping the column would silently shrink them).
     * Existence-checked instead of "DROP ... IF EXISTS" (MariaDB-only; C1).
     */
    private function dropMigrationArtifacts(): void
    {
        global $wpdb;

        if ($this->indexExists('idx_subject_user')) {
            $wpdb->query("ALTER TABLE {$this->table()} DROP INDEX idx_subject_user");
        }
        if ($this->indexExists('idx_action')) {
            $wpdb->query("ALTER TABLE {$this->table()} DROP INDEX idx_action");
        }
        if ($this->indexExists('idx_created_id')) {
            $wpdb->query("ALTER TABLE {$this->table()} DROP INDEX idx_created_id");
        }
        if ($this->columnExists('subject_user_id')) {
            $wpdb->query("ALTER TABLE {$this->table()} DROP COLUMN subject_user_id");
        }
    }

    private function columnExists(string $column): bool
    {
        global $wpdb;

        return (bool) $wpdb->get_var($wpdb->prepare(
            'SELECT 1 FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
            $this->table(),
            $column,
        ));
    }

    private function indexExists(string $index): bool
    {
        global $wpdb;

        return (bool) $wpdb->get_var($wpdb->prepare(
            'SELECT 1 FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s',
            $this->table(),
            $index,
        ));
    }

    /**
     * Run $fn while capturing every ALTER TABLE statement wpdb issues.
     *
     * @return string[]
     */
    private function captureAlters(callable $fn): array
    {
        $alters = [];
        $collector = static function (string $query) use (&$alters): string {
            if (stripos(ltrim($query), 'ALTER TABLE') === 0) {
                $alters[] = $query;
            }

            return $query;
        };

        add_filter('query', $collector);

        try {
            $fn();
        } finally {
            remove_filter('query', $collector);
        }

        return $alters;
    }
}
