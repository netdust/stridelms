<?php

declare(strict_types=1);

namespace Stride\Tests\Integration;

use IntegrationTestCase;
use Stride\Modules\Reporting\AnnualReport;
use Stride\Modules\Reporting\AnnualReportService;
use Stride\Modules\Enrollment\RegistrationTable;

/**
 * Integration tests for AnnualReportService KPI aggregation.
 *
 * Run: ddev exec vendor/bin/phpunit --testsuite Integration --filter AnnualReportServiceIntegrationTest
 */
class AnnualReportServiceIntegrationTest extends IntegrationTestCase
{
    private AnnualReportService $service;

    /** @var list<int> registration row ids created during a test, removed in tearDown */
    private array $createdRegistrationIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = ntdst_get(AnnualReportService::class);
    }

    protected function tearDown(): void
    {
        global $wpdb;
        $table = RegistrationTable::getTableName();
        foreach ($this->createdRegistrationIds as $id) {
            $wpdb->delete($table, ['id' => $id]);
        }
        $this->createdRegistrationIds = [];

        parent::tearDown();
    }

    public function test_kpis_for_year_with_no_data_return_zero_or_null(): void
    {
        $report = $this->service->buildReport(1900);

        $this->assertInstanceOf(AnnualReport::class, $report);
        $this->assertSame(1900, $report->year);
        $this->assertSame(1899, $report->previousYear);
        $this->assertSame(0, $report->kpis['enrollments']['current']);
        $this->assertNull($report->kpis['enrollments']['previous']);
        $this->assertNull($report->kpis['completion_rate']['current']);
    }

    public function test_kpis_count_only_year_n_edition_registrations(): void
    {
        // Pick years far in the future so we don't collide with seeded data
        // in the shared integration database.
        $currentYear = 9026;
        $previousYear = 9025;

        // Baseline before seeding (real DB is shared; cannot assume empty).
        $beforeCurrent = $this->service->buildReport($currentYear);
        $beforePrevious = $this->service->buildReport($previousYear);

        $beforeEnrollmentsCurrent = $beforeCurrent->kpis['enrollments']['current'];
        $beforeEnrollmentsPrevious = $beforePrevious->kpis['enrollments']['current'];
        $beforeEditionsCurrent = $beforeCurrent->kpis['editions_ran']['current'];

        $userId = self::$testUserId;

        $editionThisYear = $this->createTestEdition([
            'meta' => [
                '_ntdst_start_date' => $currentYear . '-03-10',
            ],
        ]);

        $editionLastYear = $this->createTestEdition([
            'meta' => [
                '_ntdst_start_date' => $previousYear . '-09-01',
            ],
        ]);

        global $wpdb;
        $table = RegistrationTable::getTableName();

        $wpdb->insert($table, [
            'user_id' => $userId,
            'edition_id' => $editionThisYear,
            'status' => 'confirmed',
            'registered_at' => current_time('mysql'),
        ]);
        $this->createdRegistrationIds[] = (int) $wpdb->insert_id;

        $wpdb->insert($table, [
            'user_id' => $userId,
            'edition_id' => $editionLastYear,
            'status' => 'confirmed',
            'registered_at' => current_time('mysql'),
        ]);
        $this->createdRegistrationIds[] = (int) $wpdb->insert_id;

        $report = $this->service->buildReport($currentYear);

        $this->assertSame(
            $beforeEnrollmentsCurrent + 1,
            $report->kpis['enrollments']['current'],
            'Current-year enrollments should increase by exactly 1'
        );
        $this->assertSame(
            $beforeEnrollmentsPrevious + 1,
            $report->kpis['enrollments']['previous'],
            'Previous-year enrollments should increase by exactly 1'
        );
        $this->assertSame(
            $beforeEditionsCurrent + 1,
            $report->kpis['editions_ran']['current'],
            'Current-year editions should increase by exactly 1'
        );
    }
}
