<?php

declare(strict_types=1);

namespace Stride\Tests\Unit\Modules\Enrollment;

use Stride\Domain\RegistrationStatus;
use Stride\Modules\Enrollment\RegistrationTable;
use Stride\Tests\TestCase;

/**
 * Doc-drift guard: docs/DATA-MODEL-REGISTRATIONS.md is the required-reading
 * reference for wp_vad_registrations (linked from RegistrationTable,
 * RegistrationRepository and CLAUDE.md). A reference that silently lags the
 * schema is worse than none — it teaches future code the WRONG shape. These
 * assertions make the doc fail loudly the moment the table moves without it:
 *
 *  - every column in the CREATE TABLE DDL must appear in the doc;
 *  - every RegistrationStatus case must appear in the doc;
 *  - the doc must acknowledge the CURRENT schema version's migration step
 *    (adding v6 without documenting it fails here).
 */
final class RegistrationsDataModelDocTest extends TestCase
{
    private function doc(): string
    {
        $path = dirname(__DIR__, 4) . '/docs/DATA-MODEL-REGISTRATIONS.md';
        $this->assertFileExists($path, 'the registrations data-model reference must exist (it is linked as required reading)');

        return (string) file_get_contents($path);
    }

    /**
     * Parse the column names out of RegistrationTable::create()'s DDL — the
     * single DDL source (INV-3), so the doc is pinned to the real schema.
     *
     * @return list<string>
     */
    private function ddlColumns(): array
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 4) . '/web/app/mu-plugins/stride-core/Modules/Enrollment/RegistrationTable.php',
        );

        $matched = preg_match('/CREATE TABLE \{\$table\} \((.*?)\)\s*\{\$charset\}/s', $source, $m);
        $this->assertSame(1, $matched, 'RegistrationTable::create() no longer contains the expected CREATE TABLE block');

        $columns = [];
        foreach (explode("\n", $m[1]) as $line) {
            $line = trim($line);
            // Column lines start with the column name followed by its type;
            // INDEX/PRIMARY lines are not columns.
            if (preg_match('/^(\w+)\s+(BIGINT|VARCHAR|ENUM|JSON|DATETIME|TEXT)/i', $line, $col)) {
                $columns[] = $col[1];
            }
        }

        $this->assertNotEmpty($columns, 'no columns parsed from the DDL — the parser regex needs updating');

        return $columns;
    }

    public function test_every_ddl_column_is_documented(): void
    {
        $doc = $this->doc();

        foreach ($this->ddlColumns() as $column) {
            $this->assertStringContainsString(
                "`{$column}`",
                $doc,
                "wp_vad_registrations column '{$column}' is missing from docs/DATA-MODEL-REGISTRATIONS.md — "
                . 'document its semantics (and gotchas) in the §2 column reference before shipping it',
            );
        }
    }

    public function test_every_registration_status_is_documented(): void
    {
        $doc = $this->doc();

        foreach (RegistrationStatus::cases() as $case) {
            $this->assertStringContainsString(
                $case->name,
                $doc,
                "RegistrationStatus::{$case->name} is missing from the doc's lifecycle section (§3)",
            );
        }
    }

    public function test_the_current_schema_version_is_documented(): void
    {
        // The doc's §6 history must name the current version's step — a v6+
        // migration shipped without documentation fails HERE, at commit time.
        $this->assertStringContainsString(
            'v' . RegistrationTable::SCHEMA_VERSION,
            $this->doc(),
            'SCHEMA_VERSION was bumped without documenting the new step in docs/DATA-MODEL-REGISTRATIONS.md §6',
        );
    }
}
