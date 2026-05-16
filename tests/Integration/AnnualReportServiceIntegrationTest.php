<?php

declare(strict_types=1);

namespace Stride\Tests\Integration;

use IntegrationTestCase;
use Stride\Modules\Reporting\AnnualReport;
use Stride\Modules\Reporting\AnnualReportSection;
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

    /** @var list<int> quote post ids created during a test, removed in tearDown */
    private array $createdQuoteIds = [];

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

        foreach ($this->createdQuoteIds as $id) {
            wp_delete_post($id, true);
        }
        $this->createdQuoteIds = [];

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

    public function test_enrollments_by_course_section_groups_correctly(): void
    {
        // Far-future year — avoid collisions with seeded data in the shared DB.
        $year = 9026;

        $courseA = $this->createTestCourse(['post_title' => 'AR Course A ' . wp_generate_password(4, false)]);
        $courseB = $this->createTestCourse(['post_title' => 'AR Course B ' . wp_generate_password(4, false)]);
        $userId = self::$testUserId;

        $editionA1 = $this->makeEdition($year . '-02-10', $courseA);
        $editionA2 = $this->makeEdition($year . '-05-10', $courseA);
        $editionB  = $this->makeEdition($year . '-03-01', $courseB);

        $this->makeRegistration($userId, $editionA1, 'confirmed');
        $this->makeRegistration($userId, $editionA2, 'confirmed');
        $this->makeRegistration($userId, $editionB, 'confirmed');

        $report = $this->service->buildReport($year);
        $section = $this->findSection($report, 'enrollments_by_course');

        $this->assertNotNull($section);
        $this->assertInstanceOf(AnnualReportSection::class, $section);

        $titleA = get_the_title($courseA);
        $titleB = get_the_title($courseB);

        $countA = $this->rowCountForTitle($section, $titleA);
        $countB = $this->rowCountForTitle($section, $titleB);

        $this->assertSame(2, $countA, 'Course A should have 2 enrollments');
        $this->assertSame(1, $countB, 'Course B should have 1 enrollment');
    }

    public function test_quotes_summary_section_aggregates_money_correctly(): void
    {
        // Use a far-past year — avoids both seed-data collisions AND WordPress'
        // auto-`future` post-status flip for future-dated posts (which would
        // exclude our quotes from the `post_status = 'publish'` filter).
        $year = 1925;

        // Baseline (real DB is shared).
        $before = $this->findSection($this->service->buildReport($year), 'quotes_summary');
        $this->assertNotNull($before, 'quotes_summary section must exist');
        $baseCount      = (int) $before->rows[0][1];
        $baseInvoiced   = (float) $before->rows[1][1];
        $basePaid       = (float) $before->rows[2][1];
        $baseOutstanding = (float) $before->rows[3][1];

        // Two quotes — one "paid" (exported), one outstanding (sent).
        // Note: project has no `paid` status; `exported` means processed via Exact Online.
        $q1 = wp_insert_post([
            'post_type'     => 'vad_quote',
            'post_status'   => 'publish',
            'post_title'    => 'AR Quote ' . wp_generate_password(6, false),
            'post_date'     => $year . '-04-01 09:00:00',
            'post_date_gmt' => $year . '-04-01 09:00:00',
        ]);
        $this->assertNotInstanceOf(\WP_Error::class, $q1);
        update_post_meta($q1, 'status', 'exported');
        update_post_meta($q1, 'total', 15000);
        $this->createdQuoteIds[] = (int) $q1;

        $q2 = wp_insert_post([
            'post_type'     => 'vad_quote',
            'post_status'   => 'publish',
            'post_title'    => 'AR Quote ' . wp_generate_password(6, false),
            'post_date'     => $year . '-04-15 09:00:00',
            'post_date_gmt' => $year . '-04-15 09:00:00',
        ]);
        $this->assertNotInstanceOf(\WP_Error::class, $q2);
        update_post_meta($q2, 'status', 'sent');
        update_post_meta($q2, 'total', 25000);
        $this->createdQuoteIds[] = (int) $q2;

        $section = $this->findSection($this->service->buildReport($year), 'quotes_summary');
        $this->assertNotNull($section);

        $this->assertSame($baseCount + 2, (int) $section->rows[0][1], 'Quote count should increase by 2');
        $this->assertEqualsWithDelta($baseInvoiced + 400.0, (float) $section->rows[1][1], 0.001, 'Total invoiced should grow by €400');
        $this->assertEqualsWithDelta($basePaid + 150.0, (float) $section->rows[2][1], 0.001, '"Paid" (exported) should grow by €150');
        $this->assertEqualsWithDelta($baseOutstanding + 250.0, (float) $section->rows[3][1], 0.001, 'Outstanding should grow by €250');
    }

    private function makeEdition(string $startDate, int $courseId): int
    {
        return $this->createTestEdition([
            'meta' => [
                '_ntdst_start_date' => $startDate,
                '_ntdst_course_id'  => $courseId,
            ],
        ]);
    }

    private function makeRegistration(int $userId, int $editionId, string $status): void
    {
        global $wpdb;
        $wpdb->insert(RegistrationTable::getTableName(), [
            'user_id'       => $userId,
            'edition_id'    => $editionId,
            'status'        => $status,
            'registered_at' => current_time('mysql'),
        ]);
        $this->createdRegistrationIds[] = (int) $wpdb->insert_id;
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

    private function rowCountForTitle(AnnualReportSection $section, string $title): int
    {
        foreach ($section->rows as $row) {
            if ($row[0] === $title) {
                return (int) $row[1];
            }
        }
        return 0;
    }
}
