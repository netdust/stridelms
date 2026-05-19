# Annual Report (Jaarrapport) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a `Stride → Jaarrapport` admin page that aggregates a year of platform activity (enrollments, completions, attendance hours, organisations, profile types, revenue) with a previous-year comparison column, on-screen Chart.js charts, per-table CSV export, and a tables-only DOMPDF download.

**Architecture:** Pure aggregation layer over existing data — no new tables, no schema changes. A single `AnnualReportService` builds an immutable `AnnualReport` DTO from raw SQL aggregates (registration table + edition/session postmeta + quote postmeta + usermeta). A new `AnnualReportPage` registers the admin submenu and renders the template. A new `AnnualReportPdfGenerator` reuses the DOMPDF stack from `QuotePDFGenerator`. CSV export uses a thin handler that calls into the same service. Year boundary = edition start date. Previous-year cells render `—` when no data exists.

**Tech Stack:** PHP 8.1+, NTDST DI container, MySQL/MariaDB (`$wpdb`), Chart.js 4 (CDN), DOMPDF (already vendored), Alpine.js (already in admin).

---

## File Structure

**New files (stride-core):**
- `Modules/Reporting/AnnualReportService.php` — aggregation service (raw SQL, returns DTO)
- `Modules/Reporting/AnnualReport.php` — immutable DTO with all report data
- `Modules/Reporting/AnnualReportSection.php` — value object: one table section (title, headers, rows, prev-year column)
- `Modules/Reporting/AnnualReportPdfGenerator.php` — DOMPDF wrapper for tables-only PDF
- `Modules/Reporting/Admin/AnnualReportPage.php` — registers submenu + renders template
- `Handlers/AnnualReportHandler.php` — AJAX handlers for PDF download + per-section CSV
- `templates/admin/annual-report.php` — on-screen template (KPIs + Chart.js + tables)
- `templates/pdf/annual-report.php` — print-friendly tables-only template
- `assets/css/admin/annual-report.css` — page styles
- `assets/js/admin/annual-report.js` — Alpine component (year switcher, chart rendering, downloads)
- `tests/Unit/AnnualReportServiceTest.php` — unit tests with mocked `$wpdb`
- `tests/Integration/AnnualReportServiceIntegrationTest.php` — integration tests against seeded DB

**Modified files:**
- `web/app/mu-plugins/stride-core/plugin-config.php` — register `AnnualReportPage` and `AnnualReportService`
- `web/app/mu-plugins/stride-core/Handlers/Handlers.php` (or wherever handlers register, see Task 7) — wire `AnnualReportHandler`

---

## Conventions referenced in this plan

These are existing patterns this plan mimics — do not reinvent:

- **Service metadata + DI:** all services implement `\NTDST_Service_Meta::metadata()` and use constructor DI (see `Stride\Modules\Edition\EditionService`).
- **Admin page pattern:** `add_submenu_page()` under parent slug `'stride-dashboard'` with capability `'stride_view'` (see `Stride\Admin\AdminDashboardService::registerAdminPage()`).
- **External assets:** CSS/JS lives in `stride-core/assets/{css,js}/admin/`, loaded via `wp_enqueue_*` on the page hook (see `AdminDashboardService::enqueueAssets()`).
- **Thin AJAX handlers:** no DI in constructor; use `ntdst_get()` inside methods; `wp_verify_nonce()` + `current_user_can()` checks first (see `Stride\Handlers\ProfileHandler`).
- **DOMPDF:** `new Dompdf(new Options(...))` → `loadHtml($html)` → `setPaper('A4')` → `render()` → `output()` (see `Stride\Modules\Invoicing\QuotePDFGenerator::renderPDF()`).
- **Registration table:** `RegistrationTable::getTableName()` (column names: `user_id`, `edition_id`, `status`, `company_id`, `created_at`).
- **Postmeta keys:** edition start = `_ntdst_start_date` (Y-m-d string), session date = `_ntdst_date`, session times = `_ntdst_start_time` / `_ntdst_end_time`, edition's course = `_ntdst_course_id` (verify with `grep` in EditionCPT before using).

---

## Task 1: Scaffold `AnnualReportSection` value object

**Files:**
- Create: `web/app/mu-plugins/stride-core/Modules/Reporting/AnnualReportSection.php`
- Test: `tests/Unit/AnnualReportSectionTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Unit/AnnualReportSectionTest.php
declare(strict_types=1);

namespace Stride\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Stride\Modules\Reporting\AnnualReportSection;

final class AnnualReportSectionTest extends TestCase
{
    public function test_creates_section_with_headers_and_rows(): void
    {
        $section = new AnnualReportSection(
            id: 'enrollments_by_course',
            title: 'Inschrijvingen per cursus',
            headers: ['Cursus', 'Huidig jaar', 'Vorig jaar'],
            rows: [
                ['Vorming A', 12, 8],
                ['Vorming B', 5, null],
            ],
        );

        $this->assertSame('enrollments_by_course', $section->id);
        $this->assertSame('Inschrijvingen per cursus', $section->title);
        $this->assertCount(2, $section->rows);
        $this->assertNull($section->rows[1][2]);
    }

    public function test_to_array_round_trips(): void
    {
        $section = new AnnualReportSection('a', 'A', ['h'], [['r']]);
        $arr = $section->toArray();
        $this->assertSame(['id' => 'a', 'title' => 'A', 'headers' => ['h'], 'rows' => [['r']]], $arr);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `ddev exec vendor/bin/phpunit --filter AnnualReportSectionTest`
Expected: FAIL — class `Stride\Modules\Reporting\AnnualReportSection` not found.

- [ ] **Step 3: Implement the value object**

```php
<?php
// web/app/mu-plugins/stride-core/Modules/Reporting/AnnualReportSection.php
declare(strict_types=1);

namespace Stride\Modules\Reporting;

final class AnnualReportSection
{
    /**
     * @param list<string> $headers
     * @param list<list<string|int|float|null>> $rows
     */
    public function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly array $headers,
        public readonly array $rows,
    ) {
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'headers' => $this->headers,
            'rows' => $this->rows,
        ];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `ddev exec vendor/bin/phpunit --filter AnnualReportSectionTest`
Expected: PASS — 2 tests, 4 assertions.

- [ ] **Step 5: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Reporting/AnnualReportSection.php \
        tests/Unit/AnnualReportSectionTest.php
git commit -m "feat(reporting): add AnnualReportSection value object"
```

---

## Task 2: Scaffold `AnnualReport` DTO

**Files:**
- Create: `web/app/mu-plugins/stride-core/Modules/Reporting/AnnualReport.php`
- Test: `tests/Unit/AnnualReportTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Unit/AnnualReportTest.php
declare(strict_types=1);

namespace Stride\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Stride\Modules\Reporting\AnnualReport;
use Stride\Modules\Reporting\AnnualReportSection;

final class AnnualReportTest extends TestCase
{
    public function test_holds_year_kpis_and_sections(): void
    {
        $report = new AnnualReport(
            year: 2026,
            previousYear: 2025,
            generatedAt: '2026-05-16 12:00:00',
            kpis: [
                'enrollments' => ['current' => 120, 'previous' => null],
                'completions' => ['current' => 80, 'previous' => null],
            ],
            sections: [
                new AnnualReportSection('s1', 'Sectie 1', ['col'], [['v']]),
            ],
        );

        $this->assertSame(2026, $report->year);
        $this->assertSame(2025, $report->previousYear);
        $this->assertSame(120, $report->kpis['enrollments']['current']);
        $this->assertNull($report->kpis['enrollments']['previous']);
        $this->assertCount(1, $report->sections);
    }

    public function test_kpi_change_percentage_returns_null_when_previous_missing(): void
    {
        $report = new AnnualReport(2026, 2025, '2026-05-16 12:00:00', [
            'enrollments' => ['current' => 120, 'previous' => null],
        ], []);

        $this->assertNull($report->kpiChangePercent('enrollments'));
    }

    public function test_kpi_change_percentage_calculates_when_both_present(): void
    {
        $report = new AnnualReport(2026, 2025, '2026-05-16 12:00:00', [
            'enrollments' => ['current' => 150, 'previous' => 100],
        ], []);

        $this->assertSame(50.0, $report->kpiChangePercent('enrollments'));
    }

    public function test_kpi_change_percentage_handles_zero_previous(): void
    {
        $report = new AnnualReport(2026, 2025, '2026-05-16 12:00:00', [
            'enrollments' => ['current' => 10, 'previous' => 0],
        ], []);

        $this->assertNull($report->kpiChangePercent('enrollments'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `ddev exec vendor/bin/phpunit --filter AnnualReportTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement the DTO**

```php
<?php
// web/app/mu-plugins/stride-core/Modules/Reporting/AnnualReport.php
declare(strict_types=1);

namespace Stride\Modules\Reporting;

final class AnnualReport
{
    /**
     * @param array<string, array{current: int|float|null, previous: int|float|null}> $kpis
     * @param list<AnnualReportSection> $sections
     */
    public function __construct(
        public readonly int $year,
        public readonly int $previousYear,
        public readonly string $generatedAt,
        public readonly array $kpis,
        public readonly array $sections,
    ) {
    }

    public function kpiChangePercent(string $key): ?float
    {
        $kpi = $this->kpis[$key] ?? null;
        if ($kpi === null) {
            return null;
        }
        $current = $kpi['current'];
        $previous = $kpi['previous'];
        if ($current === null || $previous === null || $previous == 0) {
            return null;
        }
        return round((($current - $previous) / $previous) * 100, 1);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `ddev exec vendor/bin/phpunit --filter AnnualReportTest`
Expected: PASS — 4 tests.

- [ ] **Step 5: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Reporting/AnnualReport.php \
        tests/Unit/AnnualReportTest.php
git commit -m "feat(reporting): add AnnualReport DTO with kpi change calculation"
```

---

## Task 3: `AnnualReportService` — KPI aggregation

**Files:**
- Create: `web/app/mu-plugins/stride-core/Modules/Reporting/AnnualReportService.php`
- Test: `tests/Integration/AnnualReportServiceIntegrationTest.php`

Service builds the full report. KPIs in this task, sections in Task 4. Integration test because raw SQL against `$wpdb` is the whole point — unit tests with mocks would test nothing real.

**Year boundary:** an edition belongs to year N if its `_ntdst_start_date` postmeta falls in `[N-01-01, N-12-31]`. All KPIs are scoped to registrations whose `edition_id` resolves to an edition in year N.

**KPI list (8 tiles):**
1. `enrollments` — `COUNT(*)` registrations with `status IN ('confirmed', 'completed')` for year-N editions
2. `unique_participants` — `COUNT(DISTINCT user_id)` from same set
3. `unique_organisations` — `COUNT(DISTINCT um.meta_value)` where `um.meta_key = 'organisation'` and user appears in year-N registrations (empty string excluded)
4. `completions` — registrations with `status = 'completed'` for year-N editions
5. `completion_rate` — `completions / enrollments` (rounded to 1 decimal, `null` if enrollments = 0)
6. `training_hours` — sum across year-N sessions of `(end_time − start_time in hours) × present_count`, where present_count uses `Stride\Modules\Attendance\AttendanceService::countAttended($sessionId)` — verify this method exists; if it doesn't, query `wp_vad_attendance` directly (column: `status` with attended values from `AttendanceStatus::attendedValues()`)
7. `editions_ran` — `COUNT(*)` editions with start in year N
8. `sessions_ran` — `COUNT(*)` sessions whose `_ntdst_date` falls in year N AND whose parent edition is in year N

- [ ] **Step 1: Write the failing integration test**

```php
<?php
// tests/Integration/AnnualReportServiceIntegrationTest.php
declare(strict_types=1);

namespace Stride\Tests\Integration;

use WP_UnitTestCase;
use Stride\Modules\Reporting\AnnualReportService;

final class AnnualReportServiceIntegrationTest extends WP_UnitTestCase
{
    private AnnualReportService $service;

    public function setUp(): void
    {
        parent::setUp();
        $this->service = ntdst_get(AnnualReportService::class);
    }

    public function test_kpis_for_year_with_no_data_return_zero_or_null(): void
    {
        $report = $this->service->buildReport(1900);

        $this->assertSame(1900, $report->year);
        $this->assertSame(1899, $report->previousYear);
        $this->assertSame(0, $report->kpis['enrollments']['current']);
        $this->assertNull($report->kpis['enrollments']['previous']);
        $this->assertNull($report->kpis['completion_rate']['current']);
    }

    public function test_kpis_count_only_year_n_edition_registrations(): void
    {
        // Seed: one edition in 2024, one in 2026, registrations on both.
        $userId = self::factory()->user->create();

        $editionThisYear = self::factory()->post->create([
            'post_type' => 'vad_edition',
            'post_status' => 'publish',
        ]);
        update_post_meta($editionThisYear, '_ntdst_start_date', '2026-03-10');

        $editionLastYear = self::factory()->post->create([
            'post_type' => 'vad_edition',
            'post_status' => 'publish',
        ]);
        update_post_meta($editionLastYear, '_ntdst_start_date', '2025-09-01');

        global $wpdb;
        $table = $wpdb->prefix . 'vad_registrations';
        $wpdb->insert($table, [
            'user_id' => $userId,
            'edition_id' => $editionThisYear,
            'status' => 'confirmed',
            'created_at' => current_time('mysql'),
        ]);
        $wpdb->insert($table, [
            'user_id' => $userId,
            'edition_id' => $editionLastYear,
            'status' => 'confirmed',
            'created_at' => current_time('mysql'),
        ]);

        $report = $this->service->buildReport(2026);

        $this->assertSame(1, $report->kpis['enrollments']['current']);
        $this->assertSame(1, $report->kpis['enrollments']['previous']);
        $this->assertSame(1, $report->kpis['editions_ran']['current']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `ddev exec vendor/bin/phpunit --testsuite Integration --filter AnnualReportServiceIntegrationTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement the service (KPI methods only)**

```php
<?php
// web/app/mu-plugins/stride-core/Modules/Reporting/AnnualReportService.php
declare(strict_types=1);

namespace Stride\Modules\Reporting;

use Stride\Modules\Enrollment\RegistrationTable;
use Stride\Modules\Edition\EditionCPT;
use Stride\Modules\Edition\SessionCPT;

class AnnualReportService implements \NTDST_Service_Meta
{
    public static function metadata(): array
    {
        return [
            'name' => 'Annual Report Service',
            'description' => 'Aggregates yearly platform stats for government reporting',
            'priority' => 50,
        ];
    }

    public function buildReport(int $year): AnnualReport
    {
        $kpis = [
            'enrollments' => [
                'current' => $this->countEnrollments($year),
                'previous' => $this->yearHasData($year - 1) ? $this->countEnrollments($year - 1) : null,
            ],
            'unique_participants' => [
                'current' => $this->countUniqueParticipants($year),
                'previous' => $this->yearHasData($year - 1) ? $this->countUniqueParticipants($year - 1) : null,
            ],
            'unique_organisations' => [
                'current' => $this->countUniqueOrganisations($year),
                'previous' => $this->yearHasData($year - 1) ? $this->countUniqueOrganisations($year - 1) : null,
            ],
            'completions' => [
                'current' => $this->countCompletions($year),
                'previous' => $this->yearHasData($year - 1) ? $this->countCompletions($year - 1) : null,
            ],
            'completion_rate' => [
                'current' => $this->completionRate($year),
                'previous' => $this->yearHasData($year - 1) ? $this->completionRate($year - 1) : null,
            ],
            'training_hours' => [
                'current' => $this->trainingHours($year),
                'previous' => $this->yearHasData($year - 1) ? $this->trainingHours($year - 1) : null,
            ],
            'editions_ran' => [
                'current' => $this->countEditions($year),
                'previous' => $this->yearHasData($year - 1) ? $this->countEditions($year - 1) : null,
            ],
            'sessions_ran' => [
                'current' => $this->countSessions($year),
                'previous' => $this->yearHasData($year - 1) ? $this->countSessions($year - 1) : null,
            ],
        ];

        return new AnnualReport(
            year: $year,
            previousYear: $year - 1,
            generatedAt: current_time('mysql'),
            kpis: $kpis,
            sections: [], // populated in Task 4
        );
    }

    public function availableYears(): array
    {
        global $wpdb;
        $rows = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT YEAR(pm.meta_value) AS y
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = %s
               AND p.post_type = %s
               AND p.post_status = 'publish'
               AND pm.meta_value != ''
             ORDER BY y DESC",
            '_ntdst_start_date',
            EditionCPT::POST_TYPE
        ));
        return array_map('intval', $rows);
    }

    private function yearHasData(int $year): bool
    {
        return in_array($year, $this->availableYears(), true);
    }

    /** @return list<int> edition IDs whose start date is in $year */
    private function editionIdsForYear(int $year): array
    {
        global $wpdb;
        $start = sprintf('%d-01-01', $year);
        $end = sprintf('%d-12-31', $year);
        $rows = $wpdb->get_col($wpdb->prepare(
            "SELECT p.ID
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
             WHERE p.post_type = %s
               AND p.post_status = 'publish'
               AND pm.meta_key = %s
               AND pm.meta_value BETWEEN %s AND %s",
            EditionCPT::POST_TYPE,
            '_ntdst_start_date',
            $start,
            $end
        ));
        return array_map('intval', $rows);
    }

    private function countEditions(int $year): int
    {
        return count($this->editionIdsForYear($year));
    }

    private function countEnrollments(int $year): int
    {
        $ids = $this->editionIdsForYear($year);
        if (empty($ids)) {
            return 0;
        }
        global $wpdb;
        $table = RegistrationTable::getTableName();
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table}
             WHERE status IN ('confirmed', 'completed')
               AND edition_id IN ({$placeholders})",
            ...$ids
        ));
    }

    private function countCompletions(int $year): int
    {
        $ids = $this->editionIdsForYear($year);
        if (empty($ids)) {
            return 0;
        }
        global $wpdb;
        $table = RegistrationTable::getTableName();
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table}
             WHERE status = 'completed'
               AND edition_id IN ({$placeholders})",
            ...$ids
        ));
    }

    private function completionRate(int $year): ?float
    {
        $enrolled = $this->countEnrollments($year);
        if ($enrolled === 0) {
            return null;
        }
        return round(($this->countCompletions($year) / $enrolled) * 100, 1);
    }

    private function countUniqueParticipants(int $year): int
    {
        $ids = $this->editionIdsForYear($year);
        if (empty($ids)) {
            return 0;
        }
        global $wpdb;
        $table = RegistrationTable::getTableName();
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT user_id) FROM {$table}
             WHERE status IN ('confirmed', 'completed')
               AND edition_id IN ({$placeholders})",
            ...$ids
        ));
    }

    private function countUniqueOrganisations(int $year): int
    {
        $ids = $this->editionIdsForYear($year);
        if (empty($ids)) {
            return 0;
        }
        global $wpdb;
        $table = RegistrationTable::getTableName();
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT um.meta_value)
             FROM {$wpdb->usermeta} um
             INNER JOIN {$table} r ON r.user_id = um.user_id
             WHERE um.meta_key = %s
               AND um.meta_value != ''
               AND r.status IN ('confirmed', 'completed')
               AND r.edition_id IN ({$placeholders})",
            'organisation',
            ...$ids
        ));
    }

    private function countSessions(int $year): int
    {
        $editionIds = $this->editionIdsForYear($year);
        if (empty($editionIds)) {
            return 0;
        }
        global $wpdb;
        $start = sprintf('%d-01-01', $year);
        $end = sprintf('%d-12-31', $year);
        $placeholders = implode(',', array_fill(0, count($editionIds), '%d'));
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT p.ID)
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm_date ON p.ID = pm_date.post_id AND pm_date.meta_key = %s
             INNER JOIN {$wpdb->postmeta} pm_ed ON p.ID = pm_ed.post_id AND pm_ed.meta_key = %s
             WHERE p.post_type = %s
               AND p.post_status = 'publish'
               AND pm_date.meta_value BETWEEN %s AND %s
               AND pm_ed.meta_value IN ({$placeholders})",
            '_ntdst_date',
            '_ntdst_edition_id',
            SessionCPT::POST_TYPE,
            $start,
            $end,
            ...$editionIds
        ));
    }

    private function trainingHours(int $year): float
    {
        $editionIds = $this->editionIdsForYear($year);
        if (empty($editionIds)) {
            return 0.0;
        }
        global $wpdb;
        $start = sprintf('%d-01-01', $year);
        $end = sprintf('%d-12-31', $year);
        $placeholders = implode(',', array_fill(0, count($editionIds), '%d'));

        // Fetch sessions with start/end times for the year
        $sessions = $wpdb->get_results($wpdb->prepare(
            "SELECT p.ID,
                    pm_start.meta_value AS start_time,
                    pm_end.meta_value AS end_time
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm_date ON p.ID = pm_date.post_id AND pm_date.meta_key = %s
             INNER JOIN {$wpdb->postmeta} pm_ed ON p.ID = pm_ed.post_id AND pm_ed.meta_key = %s
             LEFT JOIN {$wpdb->postmeta} pm_start ON p.ID = pm_start.post_id AND pm_start.meta_key = %s
             LEFT JOIN {$wpdb->postmeta} pm_end ON p.ID = pm_end.post_id AND pm_end.meta_key = %s
             WHERE p.post_type = %s
               AND p.post_status = 'publish'
               AND pm_date.meta_value BETWEEN %s AND %s
               AND pm_ed.meta_value IN ({$placeholders})",
            '_ntdst_date',
            '_ntdst_edition_id',
            '_ntdst_start_time',
            '_ntdst_end_time',
            SessionCPT::POST_TYPE,
            $start,
            $end,
            ...$editionIds
        ));

        $attendanceService = ntdst_get(\Stride\Modules\Attendance\AttendanceService::class);
        $totalHours = 0.0;
        foreach ($sessions as $s) {
            $duration = $this->hoursBetween($s->start_time, $s->end_time);
            if ($duration <= 0) {
                continue;
            }
            $attended = $attendanceService->countAttended((int) $s->ID);
            $totalHours += $duration * $attended;
        }
        return round($totalHours, 1);
    }

    private function hoursBetween(?string $start, ?string $end): float
    {
        if (!$start || !$end) {
            return 0.0;
        }
        $s = strtotime($start);
        $e = strtotime($end);
        if (!$s || !$e || $e <= $s) {
            return 0.0;
        }
        return ($e - $s) / 3600;
    }
}
```

> **Verification note:** Before saving, run `ddev exec wp eval "echo method_exists('Stride\Modules\Attendance\AttendanceService', 'countAttended') ? 'OK' : 'MISSING';"`. If `MISSING`, replace `$attendanceService->countAttended($id)` with a direct query against `wp_vad_attendance` filtered by `AttendanceStatus::attendedValues()`.

- [ ] **Step 4: Register the service in plugin-config**

Open `web/app/mu-plugins/stride-core/plugin-config.php` and add inside the `'services'` array:

```php
\Stride\Modules\Reporting\AnnualReportService::class,
```

- [ ] **Step 5: Run test to verify it passes**

Run: `ddev exec vendor/bin/phpunit --testsuite Integration --filter AnnualReportServiceIntegrationTest`
Expected: PASS — 2 tests.

- [ ] **Step 6: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Reporting/AnnualReportService.php \
        web/app/mu-plugins/stride-core/plugin-config.php \
        tests/Integration/AnnualReportServiceIntegrationTest.php
git commit -m "feat(reporting): add AnnualReportService with KPI aggregation"
```

---

## Task 4: Add section aggregations to the service

Sections are tables. Each section returns rows comparing current vs previous year. Cells with no previous-year data render `null` (template renders `—`).

**Sections to add:**
1. `enrollments_by_course` — `[course_title, current, previous]` — top 20 by current-year enrollments
2. `completion_funnel_by_course` — `[course_title, enrolled, started, completed, rate%]` — current year only (no prev column — too noisy)
3. `attendance_by_course` — `[course_title, total_hours, avg_hours_per_participant]` — current year only
4. `top_organisations` — `[organisation, current, previous]` — top 15 by current-year enrollment count
5. `profile_type_distribution` — `[profile_type, current, previous]` — counts grouped by `profile_type` usermeta
6. `quotes_summary` — `[metric, current, previous]` with rows: `Quotes issued`, `Total invoiced (€)`, `Paid (€)`, `Outstanding (€)`

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Modules/Reporting/AnnualReportService.php`
- Test: `tests/Integration/AnnualReportServiceIntegrationTest.php` (extend)

- [ ] **Step 1: Add failing tests for each section**

Append to `AnnualReportServiceIntegrationTest`:

```php
    public function test_enrollments_by_course_section_groups_correctly(): void
    {
        // Two courses, three editions in 2026, one in 2025.
        $courseA = self::factory()->post->create(['post_type' => 'sfwd-courses', 'post_title' => 'Course A']);
        $courseB = self::factory()->post->create(['post_type' => 'sfwd-courses', 'post_title' => 'Course B']);
        $userId = self::factory()->user->create();

        $editionA1 = $this->makeEdition('2026-02-10', $courseA);
        $editionA2 = $this->makeEdition('2026-05-10', $courseA);
        $editionB = $this->makeEdition('2026-03-01', $courseB);
        $this->makeRegistration($userId, $editionA1, 'confirmed');
        $this->makeRegistration($userId, $editionA2, 'confirmed');
        $this->makeRegistration($userId, $editionB, 'confirmed');

        $report = ntdst_get(AnnualReportService::class)->buildReport(2026);
        $section = $this->findSection($report, 'enrollments_by_course');

        $this->assertNotNull($section);
        $this->assertSame('Course A', $section->rows[0][0]);
        $this->assertSame(2, $section->rows[0][1]);
        $this->assertSame('Course B', $section->rows[1][0]);
        $this->assertSame(1, $section->rows[1][1]);
    }

    public function test_quotes_summary_section_aggregates_money_correctly(): void
    {
        // Two quotes — one paid, one outstanding.
        $q1 = self::factory()->post->create(['post_type' => 'vad_quote', 'post_status' => 'publish']);
        update_post_meta($q1, 'status', 'paid');
        update_post_meta($q1, 'total_cents', 15000);
        update_post_meta($q1, 'issued_at', '2026-04-01 09:00:00');

        $q2 = self::factory()->post->create(['post_type' => 'vad_quote', 'post_status' => 'publish']);
        update_post_meta($q2, 'status', 'sent');
        update_post_meta($q2, 'total_cents', 25000);
        update_post_meta($q2, 'issued_at', '2026-04-15 09:00:00');

        $report = ntdst_get(AnnualReportService::class)->buildReport(2026);
        $section = $this->findSection($report, 'quotes_summary');

        $this->assertSame(2, $section->rows[0][1]);              // Quotes issued
        $this->assertSame(400.0, $section->rows[1][1]);          // Total invoiced €
        $this->assertSame(150.0, $section->rows[2][1]);          // Paid €
        $this->assertSame(250.0, $section->rows[3][1]);          // Outstanding €
    }

    private function makeEdition(string $startDate, int $courseId): int
    {
        $id = self::factory()->post->create(['post_type' => 'vad_edition', 'post_status' => 'publish']);
        update_post_meta($id, '_ntdst_start_date', $startDate);
        update_post_meta($id, '_ntdst_course_id', $courseId);
        return $id;
    }

    private function makeRegistration(int $userId, int $editionId, string $status): void
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'vad_registrations', [
            'user_id' => $userId,
            'edition_id' => $editionId,
            'status' => $status,
            'created_at' => current_time('mysql'),
        ]);
    }

    private function findSection($report, string $id): ?\Stride\Modules\Reporting\AnnualReportSection
    {
        foreach ($report->sections as $s) {
            if ($s->id === $id) {
                return $s;
            }
        }
        return null;
    }
```

> **Note on quote meta keys:** the test assumes `status`, `total_cents`, `issued_at` postmeta. Run `grep -rn "update_post_meta.*\$quoteId\|post_meta.*quote" web/app/mu-plugins/stride-core/Modules/Invoicing/QuoteService.php | head -20` first; if keys differ, update both test and service to match real ones.

- [ ] **Step 2: Run tests to verify they fail**

Run: `ddev exec vendor/bin/phpunit --testsuite Integration --filter AnnualReportServiceIntegrationTest`
Expected: FAIL — sections array is empty.

- [ ] **Step 3: Implement the section methods on the service**

Replace the `sections: []` line in `buildReport()` with:

```php
sections: [
    $this->sectionEnrollmentsByCourse($year),
    $this->sectionCompletionFunnel($year),
    $this->sectionAttendanceByCourse($year),
    $this->sectionTopOrganisations($year),
    $this->sectionProfileTypeDistribution($year),
    $this->sectionQuotesSummary($year),
],
```

Add the methods:

```php
private function sectionEnrollmentsByCourse(int $year): AnnualReportSection
{
    $current = $this->enrollmentsByCourse($year);
    $previous = $this->yearHasData($year - 1) ? $this->enrollmentsByCourse($year - 1) : [];

    arsort($current);
    $rows = [];
    foreach (array_slice($current, 0, 20, true) as $courseId => $count) {
        $title = get_the_title($courseId) ?: '(Onbekende cursus)';
        $prev = $previous[$courseId] ?? null;
        $rows[] = [$title, (int) $count, $prev !== null ? (int) $prev : null];
    }

    return new AnnualReportSection(
        id: 'enrollments_by_course',
        title: __('Inschrijvingen per cursus', 'stride'),
        headers: [__('Cursus', 'stride'), (string) $year, (string) ($year - 1)],
        rows: $rows,
    );
}

/** @return array<int,int> course_id => enrollment_count */
private function enrollmentsByCourse(int $year): array
{
    $editionIds = $this->editionIdsForYear($year);
    if (empty($editionIds)) {
        return [];
    }
    global $wpdb;
    $table = RegistrationTable::getTableName();
    $placeholders = implode(',', array_fill(0, count($editionIds), '%d'));

    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT pm.meta_value AS course_id, COUNT(r.id) AS cnt
         FROM {$table} r
         INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = r.edition_id AND pm.meta_key = %s
         WHERE r.status IN ('confirmed', 'completed')
           AND r.edition_id IN ({$placeholders})
         GROUP BY pm.meta_value",
        '_ntdst_course_id',
        ...$editionIds
    ));

    $out = [];
    foreach ($rows as $r) {
        $out[(int) $r->course_id] = (int) $r->cnt;
    }
    return $out;
}

private function sectionCompletionFunnel(int $year): AnnualReportSection
{
    $editionIds = $this->editionIdsForYear($year);
    $rows = [];
    if (!empty($editionIds)) {
        global $wpdb;
        $table = RegistrationTable::getTableName();
        $placeholders = implode(',', array_fill(0, count($editionIds), '%d'));

        $raw = $wpdb->get_results($wpdb->prepare(
            "SELECT pm.meta_value AS course_id,
                    SUM(CASE WHEN r.status IN ('confirmed','completed') THEN 1 ELSE 0 END) AS enrolled,
                    SUM(CASE WHEN r.status = 'completed' THEN 1 ELSE 0 END) AS completed
             FROM {$table} r
             INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = r.edition_id AND pm.meta_key = %s
             WHERE r.edition_id IN ({$placeholders})
             GROUP BY pm.meta_value
             ORDER BY enrolled DESC",
            '_ntdst_course_id',
            ...$editionIds
        ));
        foreach ($raw as $r) {
            $enrolled = (int) $r->enrolled;
            $completed = (int) $r->completed;
            $rate = $enrolled > 0 ? round(($completed / $enrolled) * 100, 1) : null;
            $rows[] = [
                get_the_title((int) $r->course_id) ?: '(Onbekende cursus)',
                $enrolled,
                $completed,
                $rate,
            ];
        }
    }

    return new AnnualReportSection(
        id: 'completion_funnel_by_course',
        title: __('Voltooiing per cursus', 'stride'),
        headers: [__('Cursus', 'stride'), __('Ingeschreven', 'stride'), __('Voltooid', 'stride'), __('Voltooiingsgraad', 'stride')],
        rows: $rows,
    );
}

private function sectionAttendanceByCourse(int $year): AnnualReportSection
{
    $editionIds = $this->editionIdsForYear($year);
    $courseHours = []; // course_id => total_hours
    $courseParticipants = []; // course_id => unique_user_count

    if (!empty($editionIds)) {
        global $wpdb;
        $start = sprintf('%d-01-01', $year);
        $end = sprintf('%d-12-31', $year);
        $placeholders = implode(',', array_fill(0, count($editionIds), '%d'));

        $sessions = $wpdb->get_results($wpdb->prepare(
            "SELECT p.ID AS session_id,
                    pm_ed.meta_value AS edition_id,
                    pm_course.meta_value AS course_id,
                    pm_start.meta_value AS start_time,
                    pm_end.meta_value AS end_time
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm_date  ON p.ID = pm_date.post_id  AND pm_date.meta_key  = %s
             INNER JOIN {$wpdb->postmeta} pm_ed    ON p.ID = pm_ed.post_id    AND pm_ed.meta_key    = %s
             LEFT  JOIN {$wpdb->postmeta} pm_start ON p.ID = pm_start.post_id AND pm_start.meta_key = %s
             LEFT  JOIN {$wpdb->postmeta} pm_end   ON p.ID = pm_end.post_id   AND pm_end.meta_key   = %s
             LEFT  JOIN {$wpdb->postmeta} pm_course ON pm_ed.meta_value = pm_course.post_id AND pm_course.meta_key = %s
             WHERE p.post_type = %s
               AND p.post_status = 'publish'
               AND pm_date.meta_value BETWEEN %s AND %s
               AND pm_ed.meta_value IN ({$placeholders})",
            '_ntdst_date',
            '_ntdst_edition_id',
            '_ntdst_start_time',
            '_ntdst_end_time',
            '_ntdst_course_id',
            SessionCPT::POST_TYPE,
            $start,
            $end,
            ...$editionIds
        ));

        $attendanceService = ntdst_get(\Stride\Modules\Attendance\AttendanceService::class);
        $usersPerCourse = []; // course_id => [user_id => true]
        foreach ($sessions as $s) {
            $duration = $this->hoursBetween($s->start_time, $s->end_time);
            if ($duration <= 0) {
                continue;
            }
            $courseId = (int) $s->course_id;
            $attended = $attendanceService->countAttended((int) $s->session_id);
            $courseHours[$courseId] = ($courseHours[$courseId] ?? 0.0) + ($duration * $attended);
        }

        // Unique participants per course (from registrations)
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT pm.meta_value AS course_id, COUNT(DISTINCT r.user_id) AS users
             FROM " . RegistrationTable::getTableName() . " r
             INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = r.edition_id AND pm.meta_key = %s
             WHERE r.status IN ('confirmed','completed')
               AND r.edition_id IN ({$placeholders})
             GROUP BY pm.meta_value",
            '_ntdst_course_id',
            ...$editionIds
        ));
        foreach ($rows as $r) {
            $courseParticipants[(int) $r->course_id] = (int) $r->users;
        }
    }

    arsort($courseHours);
    $rowsOut = [];
    foreach ($courseHours as $courseId => $hours) {
        $participants = $courseParticipants[$courseId] ?? 0;
        $avg = $participants > 0 ? round($hours / $participants, 1) : null;
        $rowsOut[] = [
            get_the_title($courseId) ?: '(Onbekende cursus)',
            round($hours, 1),
            $avg,
        ];
    }

    return new AnnualReportSection(
        id: 'attendance_by_course',
        title: __('Vormingsuren per cursus', 'stride'),
        headers: [__('Cursus', 'stride'), __('Totale uren', 'stride'), __('Gem. uren per deelnemer', 'stride')],
        rows: $rowsOut,
    );
}

private function sectionTopOrganisations(int $year): AnnualReportSection
{
    $current = $this->organisationCounts($year);
    $previous = $this->yearHasData($year - 1) ? $this->organisationCounts($year - 1) : [];
    arsort($current);

    $rows = [];
    foreach (array_slice($current, 0, 15, true) as $org => $count) {
        $prev = $previous[$org] ?? null;
        $rows[] = [$org, $count, $prev];
    }

    return new AnnualReportSection(
        id: 'top_organisations',
        title: __('Top organisaties', 'stride'),
        headers: [__('Organisatie', 'stride'), (string) $year, (string) ($year - 1)],
        rows: $rows,
    );
}

/** @return array<string,int> org_name => count */
private function organisationCounts(int $year): array
{
    $editionIds = $this->editionIdsForYear($year);
    if (empty($editionIds)) {
        return [];
    }
    global $wpdb;
    $table = RegistrationTable::getTableName();
    $placeholders = implode(',', array_fill(0, count($editionIds), '%d'));

    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT um.meta_value AS org, COUNT(DISTINCT r.id) AS cnt
         FROM {$table} r
         INNER JOIN {$wpdb->usermeta} um ON um.user_id = r.user_id AND um.meta_key = %s
         WHERE um.meta_value != ''
           AND r.status IN ('confirmed','completed')
           AND r.edition_id IN ({$placeholders})
         GROUP BY um.meta_value",
        'organisation',
        ...$editionIds
    ));

    $out = [];
    foreach ($rows as $r) {
        $out[(string) $r->org] = (int) $r->cnt;
    }
    return $out;
}

private function sectionProfileTypeDistribution(int $year): AnnualReportSection
{
    $current = $this->profileTypeCounts($year);
    $previous = $this->yearHasData($year - 1) ? $this->profileTypeCounts($year - 1) : [];
    arsort($current);

    $rows = [];
    foreach ($current as $type => $count) {
        $prev = $previous[$type] ?? null;
        $rows[] = [$type ?: __('(geen)', 'stride'), $count, $prev];
    }

    return new AnnualReportSection(
        id: 'profile_type_distribution',
        title: __('Verdeling profieltypes', 'stride'),
        headers: [__('Profieltype', 'stride'), (string) $year, (string) ($year - 1)],
        rows: $rows,
    );
}

/** @return array<string,int> */
private function profileTypeCounts(int $year): array
{
    $editionIds = $this->editionIdsForYear($year);
    if (empty($editionIds)) {
        return [];
    }
    global $wpdb;
    $table = RegistrationTable::getTableName();
    $placeholders = implode(',', array_fill(0, count($editionIds), '%d'));

    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT COALESCE(um.meta_value, '') AS pt, COUNT(DISTINCT r.user_id) AS cnt
         FROM {$table} r
         LEFT JOIN {$wpdb->usermeta} um ON um.user_id = r.user_id AND um.meta_key = %s
         WHERE r.status IN ('confirmed','completed')
           AND r.edition_id IN ({$placeholders})
         GROUP BY pt",
        'profile_type',
        ...$editionIds
    ));

    $out = [];
    foreach ($rows as $r) {
        $out[(string) $r->pt] = (int) $r->cnt;
    }
    return $out;
}

private function sectionQuotesSummary(int $year): AnnualReportSection
{
    $cur = $this->quoteAggregates($year);
    $prev = $this->yearHasData($year - 1) ? $this->quoteAggregates($year - 1) : ['count' => null, 'invoiced' => null, 'paid' => null, 'outstanding' => null];

    return new AnnualReportSection(
        id: 'quotes_summary',
        title: __('Offertes en omzet', 'stride'),
        headers: [__('Metriek', 'stride'), (string) $year, (string) ($year - 1)],
        rows: [
            [__('Aantal offertes', 'stride'), $cur['count'], $prev['count']],
            [__('Totaal gefactureerd (€)', 'stride'), $cur['invoiced'], $prev['invoiced']],
            [__('Betaald (€)', 'stride'), $cur['paid'], $prev['paid']],
            [__('Openstaand (€)', 'stride'), $cur['outstanding'], $prev['outstanding']],
        ],
    );
}

/** @return array{count: int, invoiced: float, paid: float, outstanding: float} */
private function quoteAggregates(int $year): array
{
    global $wpdb;
    $start = sprintf('%d-01-01 00:00:00', $year);
    $end = sprintf('%d-12-31 23:59:59', $year);

    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT pm_status.meta_value AS status, pm_total.meta_value AS cents
         FROM {$wpdb->posts} p
         INNER JOIN {$wpdb->postmeta} pm_issued ON p.ID = pm_issued.post_id AND pm_issued.meta_key = %s
         LEFT  JOIN {$wpdb->postmeta} pm_status ON p.ID = pm_status.post_id AND pm_status.meta_key = %s
         LEFT  JOIN {$wpdb->postmeta} pm_total  ON p.ID = pm_total.post_id  AND pm_total.meta_key  = %s
         WHERE p.post_type = 'vad_quote'
           AND p.post_status = 'publish'
           AND pm_issued.meta_value BETWEEN %s AND %s",
        'issued_at',
        'status',
        'total_cents',
        $start,
        $end
    ));

    $count = count($rows);
    $invoiced = 0.0;
    $paid = 0.0;
    foreach ($rows as $r) {
        $eur = ((int) $r->cents) / 100;
        $invoiced += $eur;
        if ($r->status === 'paid') {
            $paid += $eur;
        }
    }
    return [
        'count' => $count,
        'invoiced' => round($invoiced, 2),
        'paid' => round($paid, 2),
        'outstanding' => round($invoiced - $paid, 2),
    ];
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `ddev exec vendor/bin/phpunit --testsuite Integration --filter AnnualReportServiceIntegrationTest`
Expected: PASS — 4 tests.

- [ ] **Step 5: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Reporting/AnnualReportService.php \
        tests/Integration/AnnualReportServiceIntegrationTest.php
git commit -m "feat(reporting): add 6 section aggregations to AnnualReportService"
```

---

## Task 5: `AnnualReportPage` admin submenu + on-screen template

**Files:**
- Create: `web/app/mu-plugins/stride-core/Modules/Reporting/Admin/AnnualReportPage.php`
- Create: `web/app/mu-plugins/stride-core/templates/admin/annual-report.php`
- Create: `web/app/mu-plugins/stride-core/assets/css/admin/annual-report.css`
- Create: `web/app/mu-plugins/stride-core/assets/js/admin/annual-report.js`
- Modify: `web/app/mu-plugins/stride-core/plugin-config.php`

- [ ] **Step 1: Implement the admin page service**

```php
<?php
// web/app/mu-plugins/stride-core/Modules/Reporting/Admin/AnnualReportPage.php
declare(strict_types=1);

namespace Stride\Modules\Reporting\Admin;

use Stride\Modules\Reporting\AnnualReportService;

class AnnualReportPage implements \NTDST_Service_Meta
{
    private const PARENT_SLUG = 'stride-dashboard';
    private const PAGE_SLUG = 'stride-annual-report';
    private const CAPABILITY = 'stride_view';
    private const HOOK_SUFFIX = 'stride_page_stride-annual-report';

    public static function metadata(): array
    {
        return [
            'name' => 'Annual Report Page',
            'description' => 'Admin submenu for the yearly government report',
            'priority' => 60,
        ];
    }

    public function __construct(private readonly AnnualReportService $service)
    {
        add_action('admin_menu', [$this, 'registerSubmenu'], 20);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
    }

    public function registerSubmenu(): void
    {
        add_submenu_page(
            self::PARENT_SLUG,
            __('Jaarrapport', 'stride'),
            __('Jaarrapport', 'stride'),
            self::CAPABILITY,
            self::PAGE_SLUG,
            [$this, 'render']
        );
    }

    public function enqueueAssets(string $hook): void
    {
        if ($hook !== self::HOOK_SUFFIX) {
            return;
        }

        wp_enqueue_style(
            'stride-annual-report',
            plugins_url('../../assets/css/admin/annual-report.css', __FILE__),
            [],
            '1.0.0'
        );

        wp_enqueue_script(
            'chart-js',
            'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js',
            [],
            '4.4.1',
            true
        );

        wp_enqueue_script(
            'alpinejs',
            'https://cdn.jsdelivr.net/npm/alpinejs@3.14.9/dist/cdn.min.js',
            ['chart-js'],
            '3.14.9',
            ['strategy' => 'defer']
        );

        wp_enqueue_script(
            'stride-annual-report',
            plugins_url('../../assets/js/admin/annual-report.js', __FILE__),
            ['alpinejs'],
            '1.0.0',
            true
        );

        $requestedYear = isset($_GET['year']) ? (int) $_GET['year'] : (int) current_time('Y');
        $availableYears = $this->service->availableYears();
        if (empty($availableYears)) {
            $availableYears = [(int) current_time('Y')];
        }
        if (!in_array($requestedYear, $availableYears, true)) {
            $requestedYear = $availableYears[0];
        }

        wp_localize_script('stride-annual-report', 'StrideAnnualReport', [
            'year' => $requestedYear,
            'availableYears' => $availableYears,
            'report' => $this->reportToJs($this->service->buildReport($requestedYear)),
            'pdfUrl' => admin_url('admin-ajax.php?action=stride_annual_report_pdf&year=' . $requestedYear . '&_wpnonce=' . wp_create_nonce('stride_annual_report')),
            'csvBaseUrl' => admin_url('admin-ajax.php?action=stride_annual_report_csv'),
            'csvNonce' => wp_create_nonce('stride_annual_report'),
        ]);
    }

    public function render(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(__('Geen toegang.', 'stride'));
        }
        $templatePath = dirname(__DIR__, 2) . '/../templates/admin/annual-report.php';
        // ↑ resolves to web/app/mu-plugins/stride-core/templates/admin/annual-report.php
        if (!file_exists($templatePath)) {
            echo '<div class="wrap"><h1>Jaarrapport</h1><p>Template ontbreekt.</p></div>';
            return;
        }
        include $templatePath;
    }

    private function reportToJs($report): array
    {
        return [
            'year' => $report->year,
            'previousYear' => $report->previousYear,
            'generatedAt' => $report->generatedAt,
            'kpis' => $report->kpis,
            'sections' => array_map(fn($s) => $s->toArray(), $report->sections),
        ];
    }
}
```

> **Path verification:** the `__FILE__` is at `…/Modules/Reporting/Admin/AnnualReportPage.php`. `dirname(__DIR__, 2)` from there is `…/Modules/Reporting`. The template lives at `…/templates/admin/annual-report.php`. Concatenating with `/../templates/admin/annual-report.php` resolves to `…/Modules/Reporting/../templates/admin/annual-report.php` = `…/Modules/templates/admin/annual-report.php` — wrong. Fix: use `dirname(__DIR__, 3) . '/templates/admin/annual-report.php'` instead.

Apply that fix:

```php
$templatePath = dirname(__DIR__, 3) . '/templates/admin/annual-report.php';
```

`dirname(__FILE__) = .../Modules/Reporting/Admin`. Going up 3 levels: `Admin` → `Reporting` → `Modules` → `stride-core`. Append `/templates/admin/annual-report.php`. Correct.

- [ ] **Step 2: Create the on-screen template**

```php
<?php
// web/app/mu-plugins/stride-core/templates/admin/annual-report.php
// Variables in scope: none. JS reads window.StrideAnnualReport.
?>
<div class="wrap stride-annual-report" x-data="strideAnnualReport()" x-init="init()">
    <div class="sar-header">
        <h1><?php esc_html_e('Jaarrapport', 'stride'); ?></h1>
        <div class="sar-controls">
            <label for="sar-year"><?php esc_html_e('Jaar', 'stride'); ?></label>
            <select id="sar-year" x-model.number="year" @change="changeYear($event.target.value)">
                <template x-for="y in availableYears" :key="y">
                    <option :value="y" x-text="y"></option>
                </template>
            </select>
            <a class="button button-secondary" :href="csvAllUrl()"><?php esc_html_e('CSV (alles)', 'stride'); ?></a>
            <a class="button button-primary" :href="pdfUrl"><?php esc_html_e('Download PDF', 'stride'); ?></a>
        </div>
    </div>

    <p class="sar-meta">
        <?php esc_html_e('Gegenereerd op', 'stride'); ?>
        <span x-text="report.generatedAt"></span>
        — <?php esc_html_e('vergelijking met', 'stride'); ?>
        <span x-text="report.previousYear"></span>
    </p>

    <section class="sar-kpis">
        <template x-for="(kpi, key) in report.kpis" :key="key">
            <div class="sar-kpi">
                <div class="sar-kpi-label" x-text="kpiLabel(key)"></div>
                <div class="sar-kpi-current" x-text="fmt(kpi.current, key)"></div>
                <div class="sar-kpi-previous">
                    <span x-text="report.previousYear + ':'"></span>
                    <span x-text="kpi.previous === null ? '—' : fmt(kpi.previous, key)"></span>
                    <span class="sar-kpi-delta" x-show="kpiDelta(key) !== null" x-text="kpiDelta(key)"></span>
                </div>
            </div>
        </template>
    </section>

    <section class="sar-chart">
        <h2><?php esc_html_e('Inschrijvingen per cursus', 'stride'); ?></h2>
        <canvas id="sar-chart-courses" height="80"></canvas>
    </section>

    <template x-for="section in report.sections" :key="section.id">
        <section class="sar-section">
            <header class="sar-section-head">
                <h2 x-text="section.title"></h2>
                <a class="button button-small" :href="csvUrl(section.id)"><?php esc_html_e('CSV', 'stride'); ?></a>
            </header>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <template x-for="h in section.headers" :key="h">
                            <th x-text="h"></th>
                        </template>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="(row, idx) in section.rows" :key="idx">
                        <tr>
                            <template x-for="(cell, ci) in row" :key="ci">
                                <td x-text="cell === null ? '—' : cell"></td>
                            </template>
                        </tr>
                    </template>
                    <tr x-show="section.rows.length === 0">
                        <td :colspan="section.headers.length"><?php esc_html_e('Geen gegevens.', 'stride'); ?></td>
                    </tr>
                </tbody>
            </table>
        </section>
    </template>
</div>
```

- [ ] **Step 3: Create the page CSS**

```css
/* web/app/mu-plugins/stride-core/assets/css/admin/annual-report.css */
.stride-annual-report .sar-header { display:flex; justify-content:space-between; align-items:center; gap:16px; margin-bottom:8px; }
.stride-annual-report .sar-controls { display:flex; gap:8px; align-items:center; }
.stride-annual-report .sar-meta { color:#646970; margin:0 0 16px; }
.stride-annual-report .sar-kpis { display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:12px; margin-bottom:24px; }
.stride-annual-report .sar-kpi { background:#fff; border:1px solid #dcdcde; border-radius:6px; padding:12px 14px; }
.stride-annual-report .sar-kpi-label { font-size:12px; color:#646970; text-transform:uppercase; letter-spacing:.04em; }
.stride-annual-report .sar-kpi-current { font-size:24px; font-weight:600; margin:4px 0; }
.stride-annual-report .sar-kpi-previous { font-size:12px; color:#50575e; }
.stride-annual-report .sar-kpi-delta { margin-left:6px; padding:1px 6px; border-radius:10px; background:#f0f0f1; }
.stride-annual-report .sar-chart { background:#fff; border:1px solid #dcdcde; border-radius:6px; padding:16px; margin-bottom:24px; }
.stride-annual-report .sar-section { margin-bottom:24px; }
.stride-annual-report .sar-section-head { display:flex; justify-content:space-between; align-items:center; margin-bottom:6px; }
.stride-annual-report .sar-section-head h2 { margin:0; font-size:16px; }
```

- [ ] **Step 4: Create the page JS (Alpine + Chart.js)**

```js
// web/app/mu-plugins/stride-core/assets/js/admin/annual-report.js
function strideAnnualReport() {
    const cfg = window.StrideAnnualReport || {};
    return {
        year: cfg.year,
        availableYears: cfg.availableYears || [],
        report: cfg.report || { kpis: {}, sections: [], previousYear: cfg.year - 1, generatedAt: '' },
        pdfUrl: cfg.pdfUrl,
        csvBaseUrl: cfg.csvBaseUrl,
        csvNonce: cfg.csvNonce,
        chart: null,

        init() {
            this.$nextTick(() => this.renderChart());
        },

        changeYear(y) {
            const url = new URL(window.location.href);
            url.searchParams.set('year', y);
            window.location.href = url.toString();
        },

        kpiLabel(key) {
            const map = {
                enrollments: 'Inschrijvingen',
                unique_participants: 'Unieke deelnemers',
                unique_organisations: 'Organisaties bereikt',
                completions: 'Voltooid',
                completion_rate: 'Voltooiingsgraad',
                training_hours: 'Vormingsuren',
                editions_ran: 'Edities',
                sessions_ran: 'Sessies',
            };
            return map[key] || key;
        },

        fmt(v, key) {
            if (v === null || v === undefined) return '—';
            if (key === 'completion_rate') return v + '%';
            if (key === 'training_hours') return v + ' u';
            return new Intl.NumberFormat('nl-BE').format(v);
        },

        kpiDelta(key) {
            const kpi = this.report.kpis[key];
            if (!kpi || kpi.current === null || kpi.previous === null || kpi.previous === 0) return null;
            const pct = Math.round(((kpi.current - kpi.previous) / kpi.previous) * 1000) / 10;
            return (pct >= 0 ? '+' : '') + pct + '%';
        },

        csvUrl(sectionId) {
            return this.csvBaseUrl + '&section=' + encodeURIComponent(sectionId) + '&year=' + this.year + '&_wpnonce=' + this.csvNonce;
        },

        csvAllUrl() {
            return this.csvBaseUrl + '&section=all&year=' + this.year + '&_wpnonce=' + this.csvNonce;
        },

        renderChart() {
            const section = this.report.sections.find(s => s.id === 'enrollments_by_course');
            if (!section || !section.rows.length) return;
            const ctx = document.getElementById('sar-chart-courses');
            if (!ctx || typeof Chart === 'undefined') return;

            const labels = section.rows.map(r => r[0]);
            const current = section.rows.map(r => r[1]);
            const previous = section.rows.map(r => r[2] === null ? 0 : r[2]);

            this.chart = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels,
                    datasets: [
                        { label: String(this.year), data: current, backgroundColor: '#2271b1' },
                        { label: String(this.report.previousYear), data: previous, backgroundColor: '#c3c4c7' },
                    ],
                },
                options: {
                    indexAxis: 'y',
                    responsive: true,
                    plugins: { legend: { position: 'bottom' } },
                    scales: { x: { beginAtZero: true, ticks: { precision: 0 } } },
                },
            });
        },
    };
}
window.strideAnnualReport = strideAnnualReport;
```

- [ ] **Step 5: Register the page service**

In `web/app/mu-plugins/stride-core/plugin-config.php`, add inside the `'services'` array:

```php
\Stride\Modules\Reporting\Admin\AnnualReportPage::class,
```

- [ ] **Step 6: Smoke-check the page loads**

Run:
```bash
ddev exec wp eval "echo class_exists('Stride\Modules\Reporting\Admin\AnnualReportPage') ? 'OK' : 'FAIL';"
```
Expected: `OK`.

Then visit `https://stride.ddev.site/wp/wp-admin/admin.php?page=stride-annual-report` in a browser logged in as admin. Confirm page renders, year selector works, KPI tiles show "—" if no data, chart canvas appears.

- [ ] **Step 7: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Reporting/Admin/AnnualReportPage.php \
        web/app/mu-plugins/stride-core/templates/admin/annual-report.php \
        web/app/mu-plugins/stride-core/assets/css/admin/annual-report.css \
        web/app/mu-plugins/stride-core/assets/js/admin/annual-report.js \
        web/app/mu-plugins/stride-core/plugin-config.php
git commit -m "feat(reporting): add Jaarrapport admin page with KPIs and chart"
```

---

## Task 6: `AnnualReportPdfGenerator` (tables-only PDF)

**Files:**
- Create: `web/app/mu-plugins/stride-core/Modules/Reporting/AnnualReportPdfGenerator.php`
- Create: `web/app/mu-plugins/stride-core/templates/pdf/annual-report.php`
- Test: `tests/Integration/AnnualReportPdfGeneratorIntegrationTest.php`

- [ ] **Step 1: Write the failing integration test**

```php
<?php
// tests/Integration/AnnualReportPdfGeneratorIntegrationTest.php
declare(strict_types=1);

namespace Stride\Tests\Integration;

use WP_UnitTestCase;
use Stride\Modules\Reporting\AnnualReportPdfGenerator;
use Stride\Modules\Reporting\AnnualReportService;

final class AnnualReportPdfGeneratorIntegrationTest extends WP_UnitTestCase
{
    public function test_generate_returns_non_empty_pdf_binary(): void
    {
        $service = ntdst_get(AnnualReportService::class);
        $gen = ntdst_get(AnnualReportPdfGenerator::class);

        $report = $service->buildReport((int) current_time('Y'));
        $bytes = $gen->generate($report);

        $this->assertNotSame('', $bytes);
        $this->assertStringStartsWith('%PDF-', $bytes);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `ddev exec vendor/bin/phpunit --testsuite Integration --filter AnnualReportPdfGeneratorIntegrationTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement the generator**

```php
<?php
// web/app/mu-plugins/stride-core/Modules/Reporting/AnnualReportPdfGenerator.php
declare(strict_types=1);

namespace Stride\Modules\Reporting;

use Dompdf\Dompdf;
use Dompdf\Options;

class AnnualReportPdfGenerator implements \NTDST_Service_Meta
{
    public static function metadata(): array
    {
        return [
            'name' => 'Annual Report PDF Generator',
            'description' => 'Renders the Jaarrapport as a tables-only PDF',
            'priority' => 60,
        ];
    }

    public function generate(AnnualReport $report): string
    {
        $html = $this->renderHtml($report);

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }

    private function renderHtml(AnnualReport $report): string
    {
        $templatePath = dirname(__DIR__, 2) . '/templates/pdf/annual-report.php';
        // dirname(__FILE__) = .../Modules/Reporting; up 2 = .../mu-plugins/stride-core
        ob_start();
        include $templatePath;
        return (string) ob_get_clean();
    }
}
```

> **Path verification:** `__FILE__` = `.../stride-core/Modules/Reporting/AnnualReportPdfGenerator.php`. `dirname(__DIR__, 2)`: `__DIR__` = `.../Modules/Reporting`. Up 2 = `.../stride-core`. Append `/templates/pdf/annual-report.php`. Correct.

- [ ] **Step 4: Create the PDF template**

```php
<?php
// web/app/mu-plugins/stride-core/templates/pdf/annual-report.php
/** @var \Stride\Modules\Reporting\AnnualReport $report */
$kpiLabels = [
    'enrollments' => 'Inschrijvingen',
    'unique_participants' => 'Unieke deelnemers',
    'unique_organisations' => 'Organisaties bereikt',
    'completions' => 'Voltooid',
    'completion_rate' => 'Voltooiingsgraad',
    'training_hours' => 'Vormingsuren',
    'editions_ran' => 'Edities',
    'sessions_ran' => 'Sessies',
];
$fmt = function ($v, $key) {
    if ($v === null) {
        return '—';
    }
    if ($key === 'completion_rate') {
        return $v . '%';
    }
    if ($key === 'training_hours') {
        return $v . ' u';
    }
    return is_numeric($v) ? number_format((float) $v, ($v == (int) $v ? 0 : 2), ',', '.') : (string) $v;
};
?><!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="UTF-8">
<title>Jaarrapport <?php echo (int) $report->year; ?></title>
<style>
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 10pt; color: #1d2327; }
    h1 { font-size: 18pt; margin: 0 0 4pt; }
    h2 { font-size: 12pt; margin: 16pt 0 4pt; border-bottom: 1px solid #ccc; padding-bottom: 2pt; }
    .meta { color: #50575e; margin-bottom: 16pt; }
    table { width: 100%; border-collapse: collapse; margin-bottom: 8pt; }
    th, td { border: 1px solid #dcdcde; padding: 4pt 6pt; text-align: left; vertical-align: top; }
    th { background: #f6f7f7; }
    .kpi-grid td { width: 25%; }
    .num { text-align: right; }
</style>
</head>
<body>
    <h1>Jaarrapport <?php echo (int) $report->year; ?></h1>
    <p class="meta">
        Vergelijking met <?php echo (int) $report->previousYear; ?> —
        Gegenereerd op <?php echo esc_html($report->generatedAt); ?>
    </p>

    <h2>Kerncijfers</h2>
    <table class="kpi-grid">
        <?php
        $kpiKeys = array_keys($report->kpis);
        for ($i = 0; $i < count($kpiKeys); $i += 2):
            ?>
            <tr>
                <?php for ($j = 0; $j < 2; $j++):
                    $k = $kpiKeys[$i + $j] ?? null;
                    if ($k === null) {
                        echo '<td></td><td></td>';
                        continue;
                    }
                    $kpi = $report->kpis[$k];
                    $label = $kpiLabels[$k] ?? $k;
                    ?>
                    <td><strong><?php echo esc_html($label); ?></strong></td>
                    <td class="num">
                        <?php echo esc_html($fmt($kpi['current'], $k)); ?>
                        <span style="color:#646970;">(<?php echo (int) $report->previousYear; ?>: <?php echo esc_html($fmt($kpi['previous'], $k)); ?>)</span>
                    </td>
                <?php endfor; ?>
            </tr>
        <?php endfor; ?>
    </table>

    <?php foreach ($report->sections as $section): ?>
        <h2><?php echo esc_html($section->title); ?></h2>
        <?php if (empty($section->rows)): ?>
            <p style="color:#646970;">Geen gegevens.</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <?php foreach ($section->headers as $h): ?>
                            <th><?php echo esc_html((string) $h); ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($section->rows as $row): ?>
                        <tr>
                            <?php foreach ($row as $i => $cell): ?>
                                <td class="<?php echo $i === 0 ? '' : 'num'; ?>">
                                    <?php echo $cell === null ? '—' : esc_html((string) $cell); ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    <?php endforeach; ?>
</body>
</html>
```

- [ ] **Step 5: Register the generator**

In `plugin-config.php`, add to `'services'`:

```php
\Stride\Modules\Reporting\AnnualReportPdfGenerator::class,
```

- [ ] **Step 6: Run test to verify it passes**

Run: `ddev exec vendor/bin/phpunit --testsuite Integration --filter AnnualReportPdfGeneratorIntegrationTest`
Expected: PASS — 1 test.

- [ ] **Step 7: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Reporting/AnnualReportPdfGenerator.php \
        web/app/mu-plugins/stride-core/templates/pdf/annual-report.php \
        web/app/mu-plugins/stride-core/plugin-config.php \
        tests/Integration/AnnualReportPdfGeneratorIntegrationTest.php
git commit -m "feat(reporting): add DOMPDF generator for Jaarrapport"
```

---

## Task 7: `AnnualReportHandler` — PDF + CSV downloads

Thin handler following `Stride\Handlers\ProfileHandler` pattern.

**Files:**
- Create: `web/app/mu-plugins/stride-core/Handlers/AnnualReportHandler.php`
- Modify: wherever existing handlers are registered. Check `plugin-config.php` first — if handlers are listed under `'services'`, add it there. If there's a separate Handlers bootstrap, use that.

- [ ] **Step 1: Discover the handler-registration pattern**

Run:
```bash
grep -rn "ProfileHandler\|AnnualReportHandler\|new.*Handler\b" /home/ntdst/Sites/stride/web/app/mu-plugins/stride-core/ | grep -v "test\|Test" | head -20
```

Identify how `ProfileHandler` (or any other handler) is wired. Apply the same pattern for `AnnualReportHandler`.

- [ ] **Step 2: Implement the handler**

```php
<?php
// web/app/mu-plugins/stride-core/Handlers/AnnualReportHandler.php
declare(strict_types=1);

namespace Stride\Handlers;

use Stride\Modules\Reporting\AnnualReportService;
use Stride\Modules\Reporting\AnnualReportPdfGenerator;
use Stride\Modules\Reporting\AnnualReport;
use Stride\Modules\Reporting\AnnualReportSection;

final class AnnualReportHandler
{
    private const NONCE_ACTION = 'stride_annual_report';
    private const CAPABILITY = 'stride_view';

    public function __construct()
    {
        add_action('wp_ajax_stride_annual_report_pdf', [$this, 'downloadPdf']);
        add_action('wp_ajax_stride_annual_report_csv', [$this, 'downloadCsv']);
    }

    public function downloadPdf(): void
    {
        $this->guard();
        $year = $this->resolveYear();

        $service = ntdst_get(AnnualReportService::class);
        $gen = ntdst_get(AnnualReportPdfGenerator::class);
        $report = $service->buildReport($year);
        $bytes = $gen->generate($report);

        $filename = sprintf('jaarrapport-%d.pdf', $year);
        nocache_headers();
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($bytes));
        echo $bytes;
        exit;
    }

    public function downloadCsv(): void
    {
        $this->guard();
        $year = $this->resolveYear();
        $sectionId = isset($_GET['section']) ? sanitize_key((string) $_GET['section']) : 'all';

        $service = ntdst_get(AnnualReportService::class);
        $report = $service->buildReport($year);

        nocache_headers();
        header('Content-Type: text/csv; charset=UTF-8');
        $filename = $sectionId === 'all'
            ? sprintf('jaarrapport-%d.csv', $year)
            : sprintf('jaarrapport-%d-%s.csv', $year, $sectionId);
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $out = fopen('php://output', 'w');
        // UTF-8 BOM so Excel opens it correctly
        fwrite($out, "\xEF\xBB\xBF");

        if ($sectionId === 'all') {
            $this->writeKpisCsv($out, $report);
            foreach ($report->sections as $section) {
                fputcsv($out, []);
                $this->writeSectionCsv($out, $section);
            }
        } else {
            $section = $this->findSection($report, $sectionId);
            if ($section === null) {
                fputcsv($out, ['Sectie niet gevonden: ' . $sectionId]);
            } else {
                $this->writeSectionCsv($out, $section);
            }
        }

        fclose($out);
        exit;
    }

    private function guard(): void
    {
        $nonce = isset($_REQUEST['_wpnonce']) ? (string) $_REQUEST['_wpnonce'] : '';
        if (!wp_verify_nonce($nonce, self::NONCE_ACTION)) {
            wp_die(__('Ongeldige aanvraag.', 'stride'), 403);
        }
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(__('Geen toegang.', 'stride'), 403);
        }
    }

    private function resolveYear(): int
    {
        $year = isset($_GET['year']) ? (int) $_GET['year'] : (int) current_time('Y');
        if ($year < 2000 || $year > 2100) {
            $year = (int) current_time('Y');
        }
        return $year;
    }

    private function writeKpisCsv($out, AnnualReport $report): void
    {
        fputcsv($out, ['Kerncijfers']);
        fputcsv($out, ['Metriek', (string) $report->year, (string) $report->previousYear]);
        foreach ($report->kpis as $key => $kpi) {
            fputcsv($out, [
                $key,
                $kpi['current'] ?? '',
                $kpi['previous'] ?? '',
            ]);
        }
    }

    private function writeSectionCsv($out, AnnualReportSection $section): void
    {
        fputcsv($out, [$section->title]);
        fputcsv($out, $section->headers);
        foreach ($section->rows as $row) {
            fputcsv($out, array_map(fn($c) => $c === null ? '' : $c, $row));
        }
    }

    private function findSection(AnnualReport $report, string $id): ?AnnualReportSection
    {
        foreach ($report->sections as $s) {
            if ($s->id === $id) {
                return $s;
            }
        }
        return null;
    }
}
```

- [ ] **Step 3: Register the handler**

Wire `AnnualReportHandler` using the same mechanism `ProfileHandler` uses (discovered in Step 1). Most likely: add `\Stride\Handlers\AnnualReportHandler::class` to the `'services'` array in `plugin-config.php`.

- [ ] **Step 4: Smoke-test download endpoints**

In a logged-in admin browser session:
1. Visit `https://stride.ddev.site/wp/wp-admin/admin.php?page=stride-annual-report`
2. Click "Download PDF" — confirm a `jaarrapport-YYYY.pdf` downloads and opens correctly.
3. Click any section "CSV" button — confirm a CSV downloads and opens in Excel/LibreOffice with correct headers.

If the PDF link returns a 403, the nonce isn't being passed — re-check `wp_localize_script` output in the page source.

- [ ] **Step 5: Commit**

```bash
git add web/app/mu-plugins/stride-core/Handlers/AnnualReportHandler.php \
        web/app/mu-plugins/stride-core/plugin-config.php
git commit -m "feat(reporting): add PDF and CSV download handlers"
```

---

## Verification Stages (MANDATORY)

> Run AFTER all implementation tasks. NOT done until all stages pass.
> If ANY stage fails: fix → re-run that stage → continue.

### Stage V1: Static Analysis

```bash
ddev exec vendor/bin/phpcs --standard=PSR12 \
  web/app/mu-plugins/stride-core/Modules/Reporting/AnnualReport.php \
  web/app/mu-plugins/stride-core/Modules/Reporting/AnnualReportSection.php \
  web/app/mu-plugins/stride-core/Modules/Reporting/AnnualReportService.php \
  web/app/mu-plugins/stride-core/Modules/Reporting/AnnualReportPdfGenerator.php \
  web/app/mu-plugins/stride-core/Modules/Reporting/Admin/AnnualReportPage.php \
  web/app/mu-plugins/stride-core/Handlers/AnnualReportHandler.php
```

If a `phpstan.neon` is configured in the project, also run:
```bash
ddev exec vendor/bin/phpstan analyse \
  web/app/mu-plugins/stride-core/Modules/Reporting \
  web/app/mu-plugins/stride-core/Handlers/AnnualReportHandler.php \
  --level=5
```

Expected: No errors. Fix all issues before proceeding.

### Stage V2: Unit Tests

**Test files created in tasks:**
- `tests/Unit/AnnualReportSectionTest.php`
- `tests/Unit/AnnualReportTest.php`

```bash
ddev exec vendor/bin/phpunit --testsuite Unit --filter "AnnualReport"
```

Expected: ALL tests pass (6 tests across the two files).

### Stage V3: Acceptance Tests (Browser)

> Stride uses PHPUnit + integration suite, not Codeception/acceptance browser tests. Substitute integration tests + a manual browser checklist (V5) for V3.

**Integration tests created in tasks:**
- `tests/Integration/AnnualReportServiceIntegrationTest.php`
- `tests/Integration/AnnualReportPdfGeneratorIntegrationTest.php`

**Scenarios covered:**
```
SERVICE — happy path:
  GIVEN: 1 edition in 2026 with 1 confirmed registration, 1 edition in 2025 with 1
  WHEN: service.buildReport(2026)
  THEN: kpis.enrollments.current == 1, .previous == 1, editions_ran.current == 1

SERVICE — empty year:
  GIVEN: no editions in 1900
  WHEN: service.buildReport(1900)
  THEN: kpis.enrollments.current == 0, kpis.enrollments.previous == null

SERVICE — section grouping:
  GIVEN: 2 courses, 3 editions in 2026
  WHEN: service.buildReport(2026)
  THEN: enrollments_by_course section has correct counts per course

SERVICE — quotes aggregation:
  GIVEN: 2 quotes in 2026 (1 paid, 1 sent)
  WHEN: service.buildReport(2026)
  THEN: quotes_summary shows count=2, invoiced=400, paid=150, outstanding=250

PDF — generation:
  GIVEN: any report
  WHEN: pdfGenerator.generate(report)
  THEN: returns binary starting with %PDF-
```

```bash
ddev exec vendor/bin/phpunit --testsuite Integration --filter "AnnualReport"
```

Expected: ALL integration tests pass.

### Stage V4: Full Regression

```bash
ddev exec vendor/bin/phpunit
```

Expected: Zero new failures. Pre-existing failures (if any) noted separately.

### Stage V5: Smoke Test Checklist

```markdown
## Manual Smoke Test

- [ ] Visit: `https://stride.ddev.site/wp/wp-admin/admin.php?page=stride-annual-report`
      Expected: page renders, no PHP errors, no console errors, "Jaarrapport" heading visible
- [ ] Year selector dropdown lists available years (from `_ntdst_start_date` postmeta years)
      Expected: at least the current year present
- [ ] KPI tiles render
      Expected: 8 tiles. Previous-year line shows "—" if no previous-year data, value otherwise
- [ ] Chart renders
      Expected: horizontal bar chart with one bar per top-10 course (or empty if no data)
- [ ] Section tables render
      Expected: 6 sections (enrollments by course, completion funnel, attendance, organisations, profile types, quotes)
- [ ] Click section "CSV" button
      Expected: downloads `jaarrapport-YYYY-<section_id>.csv`, opens cleanly in Excel/LibreOffice
- [ ] Click "CSV (alles)" button
      Expected: downloads full report CSV with all sections
- [ ] Click "Download PDF"
      Expected: downloads `jaarrapport-YYYY.pdf`, opens in any PDF reader, contains KPI grid + all 6 sections
- [ ] Change year via dropdown
      Expected: page reloads with new year in `?year=` query param, all sections re-aggregated
- [ ] Database (no schema change should have occurred):
      `ddev exec wp db query "SHOW TABLES LIKE '%report%'"`
      Expected: empty result — we don't create any new tables
- [ ] User without `stride_view` capability cannot access page
      Expected: `wp_die` "Geen toegang"
- [ ] PDF download URL without nonce returns 403
      Expected: `wp_die` "Ongeldige aanvraag"
```

---

## Self-Review Notes

- **Spec coverage:** all 4 user decisions covered — `—` placeholders (Task 4 sections + Task 5 template + Task 6 PDF), edition-start-date year basis (Task 3 `editionIdsForYear`), submenu under `stride-dashboard` (Task 5 `registerSubmenu`), CSV per table (Task 7 `downloadCsv`).
- **Placeholders:** none — every code step shows the actual code.
- **Type consistency:** `AnnualReportSection::$rows` is `list<list<scalar|null>>` consistently; `kpis` shape `[current, previous]` matches across service, DTO, template, and PDF; method names match across files.
- **Known soft spots flagged in plan:** (1) `AttendanceService::countAttended()` existence — Task 3 includes verification command; (2) quote meta keys (`status`, `total_cents`, `issued_at`) — Task 4 includes verification command; (3) handler registration mechanism — Task 7 starts with a discovery step instead of guessing.
- **Effort estimate:** ~6–8 hours. Tasks 3 and 4 are the bulk (SQL aggregation + tests).
