<?php

declare(strict_types=1);

namespace Stride\Modules\Reporting;

use Stride\Domain\AttendanceStatus;
use Stride\Modules\Attendance\AttendanceTable;
use Stride\Modules\Edition\EditionCPT;
use Stride\Modules\Edition\EditionRepository;
use Stride\Modules\Edition\SessionCPT;
use Stride\Modules\Edition\SessionRepository;
use Stride\Modules\Enrollment\RegistrationTable;

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

    public function __construct(
        private readonly EditionRepository $editions,
        private readonly SessionRepository $sessions,
    ) {
    }

    public function buildReport(int $year): AnnualReport
    {
        // Reset per-build caches so consecutive buildReport() calls always see fresh data.
        $this->availableYearsCache = null;
        $this->editionIdsCache     = [];

        $hasPrev = $this->yearHasData($year - 1);

        $kpis = [
            'enrollments' => [
                'current' => $this->countEnrollments($year),
                'previous' => $hasPrev ? $this->countEnrollments($year - 1) : null,
            ],
            'unique_participants' => [
                'current' => $this->countUniqueParticipants($year),
                'previous' => $hasPrev ? $this->countUniqueParticipants($year - 1) : null,
            ],
            'unique_organisations' => [
                'current' => $this->countUniqueOrganisations($year),
                'previous' => $hasPrev ? $this->countUniqueOrganisations($year - 1) : null,
            ],
            'completions' => [
                'current' => $this->countCompletions($year),
                'previous' => $hasPrev ? $this->countCompletions($year - 1) : null,
            ],
            'completion_rate' => [
                'current' => $this->completionRate($year),
                'previous' => $hasPrev ? $this->completionRate($year - 1) : null,
            ],
            'training_hours' => [
                'current' => $this->trainingHours($year),
                'previous' => $hasPrev ? $this->trainingHours($year - 1) : null,
            ],
            'editions_ran' => [
                'current' => $this->countEditions($year),
                'previous' => $hasPrev ? $this->countEditions($year - 1) : null,
            ],
            'sessions_ran' => [
                'current' => $this->countSessions($year),
                'previous' => $hasPrev ? $this->countSessions($year - 1) : null,
            ],
        ];

        return new AnnualReport(
            year: $year,
            previousYear: $year - 1,
            generatedAt: current_time('mysql'),
            kpis: $kpis,
            sections: [
                $this->sectionEnrollmentsByCourse($year),
                $this->sectionCompletionFunnel($year),
                $this->sectionAttendanceByCourse($year),
                $this->sectionTopOrganisations($year),
                $this->sectionProfileTypeDistribution($year),
                $this->sectionQuotesSummary($year),
            ],
        );
    }

    private function sectionEnrollmentsByCourse(int $year): AnnualReportSection
    {
        $current  = $this->enrollmentsByCourse($year);
        $previous = $this->yearHasData($year - 1) ? $this->enrollmentsByCourse($year - 1) : [];

        arsort($current);
        $rows = [];
        foreach (array_slice($current, 0, 20, true) as $courseId => $count) {
            $title = get_the_title($courseId) ?: '(Onbekende cursus)';
            $prev  = $previous[$courseId] ?? null;
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
            $this->editions->getMetaPrefix() . 'course_id',
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
                $this->editions->getMetaPrefix() . 'course_id',
                ...$editionIds
            ));
            foreach ($raw as $r) {
                $enrolled  = (int) $r->enrolled;
                $completed = (int) $r->completed;
                $rate      = $enrolled > 0 ? round(($completed / $enrolled) * 100, 1) : null;
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
            headers: [
                __('Cursus', 'stride'),
                __('Ingeschreven', 'stride'),
                __('Voltooid', 'stride'),
                __('Voltooiingsgraad', 'stride'),
            ],
            rows: $rows,
        );
    }

    private function sectionAttendanceByCourse(int $year): AnnualReportSection
    {
        $editionIds = $this->editionIdsForYear($year);
        $courseHours = [];        // course_id => total_hours
        $courseParticipants = []; // course_id => unique_user_count

        if (!empty($editionIds)) {
            global $wpdb;
            $start = sprintf('%d-01-01', $year);
            $end   = sprintf('%d-12-31', $year);
            $placeholders = implode(',', array_fill(0, count($editionIds), '%d'));

            $sessionPrefix = $this->sessions->getMetaPrefix();
            $editionPrefix = $this->editions->getMetaPrefix();
            $sessions = $wpdb->get_results($wpdb->prepare(
                "SELECT p.ID AS session_id,
                        pm_ed.meta_value AS edition_id,
                        pm_course.meta_value AS course_id,
                        pm_start.meta_value AS start_time,
                        pm_end.meta_value AS end_time
                 FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} pm_date   ON p.ID = pm_date.post_id  AND pm_date.meta_key  = %s
                 INNER JOIN {$wpdb->postmeta} pm_ed     ON p.ID = pm_ed.post_id    AND pm_ed.meta_key    = %s
                 LEFT  JOIN {$wpdb->postmeta} pm_start  ON p.ID = pm_start.post_id AND pm_start.meta_key = %s
                 LEFT  JOIN {$wpdb->postmeta} pm_end    ON p.ID = pm_end.post_id   AND pm_end.meta_key   = %s
                 LEFT  JOIN {$wpdb->postmeta} pm_course ON pm_ed.meta_value = pm_course.post_id AND pm_course.meta_key = %s
                 WHERE p.post_type = %s
                   AND p.post_status = 'publish'
                   AND pm_date.meta_value BETWEEN %s AND %s
                   AND pm_ed.meta_value IN ({$placeholders})",
                $sessionPrefix . 'date',
                $sessionPrefix . 'edition_id',
                $sessionPrefix . 'start_time',
                $sessionPrefix . 'end_time',
                $editionPrefix . 'course_id',
                SessionCPT::POST_TYPE,
                $start,
                $end,
                ...$editionIds
            ));

            $attendanceTable = AttendanceTable::getTableName();
            $attendedValues  = AttendanceStatus::attendedValues();

            foreach ($sessions as $s) {
                $duration = $this->hoursBetween($s->start_time, $s->end_time);
                if ($duration <= 0) {
                    continue;
                }
                $courseId = (int) $s->course_id;
                // countAttended on AttendanceService is keyed by user+edition, not session.
                // Query the attendance table directly for the session, mirroring trainingHours().
                $attended = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$attendanceTable}
                     WHERE session_id = %d
                       AND status IN ({$attendedValues})",
                    (int) $s->session_id
                ));
                $courseHours[$courseId] = ($courseHours[$courseId] ?? 0.0) + ($duration * $attended);
            }

            // Unique participants per course (from registrations).
            $registrationsTable = RegistrationTable::getTableName();
            $rowsParticipants = $wpdb->get_results($wpdb->prepare(
                "SELECT pm.meta_value AS course_id, COUNT(DISTINCT r.user_id) AS users
                 FROM {$registrationsTable} r
                 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = r.edition_id AND pm.meta_key = %s
                 WHERE r.status IN ('confirmed','completed')
                   AND r.edition_id IN ({$placeholders})
                 GROUP BY pm.meta_value",
                $this->editions->getMetaPrefix() . 'course_id',
                ...$editionIds
            ));
            foreach ($rowsParticipants as $r) {
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
            headers: [
                __('Cursus', 'stride'),
                __('Totale uren', 'stride'),
                __('Gem. uren per deelnemer', 'stride'),
            ],
            rows: $rowsOut,
        );
    }

    private function sectionTopOrganisations(int $year): AnnualReportSection
    {
        $current  = $this->organisationCounts($year);
        $previous = $this->yearHasData($year - 1) ? $this->organisationCounts($year - 1) : [];
        arsort($current);

        $rows = [];
        foreach (array_slice($current, 0, 15, true) as $org => $count) {
            $prev = $previous[$org] ?? null;
            $rows[] = [$org, (int) $count, $prev !== null ? (int) $prev : null];
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
        $current  = $this->profileTypeCounts($year);
        $previous = $this->yearHasData($year - 1) ? $this->profileTypeCounts($year - 1) : [];
        arsort($current);

        $rows = [];
        foreach ($current as $type => $count) {
            $prev   = $previous[$type] ?? null;
            $label  = $type !== '' ? $type : __('(geen)', 'stride');
            $rows[] = [$label, (int) $count, $prev !== null ? (int) $prev : null];
        }

        return new AnnualReportSection(
            id: 'profile_type_distribution',
            title: __('Verdeling profieltypes', 'stride'),
            headers: [__('Profieltype', 'stride'), (string) $year, (string) ($year - 1)],
            rows: $rows,
        );
    }

    /** @return array<string,int> profile_type => unique_user_count */
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
        $cur  = $this->quoteAggregates($year);
        $prev = $this->yearHasData($year - 1)
            ? $this->quoteAggregates($year - 1)
            : ['count' => null, 'invoiced' => null, 'paid' => null, 'outstanding' => null];

        return new AnnualReportSection(
            id: 'quotes_summary',
            title: __('Offertes en omzet', 'stride'),
            headers: [__('Metriek', 'stride'), (string) $year, (string) ($year - 1)],
            rows: [
                [__('Aantal offertes', 'stride'),           $cur['count'],       $prev['count']],
                [__('Totaal gefactureerd (€)', 'stride'),   $cur['invoiced'],    $prev['invoiced']],
                [__('Betaald (€)', 'stride'),               $cur['paid'],        $prev['paid']],
                [__('Openstaand (€)', 'stride'),            $cur['outstanding'], $prev['outstanding']],
            ],
        );
    }

    /**
     * Aggregate quote totals for the given year.
     *
     * Project specifics:
     *  - QuoteCPT has `meta_prefix => ''`, so postmeta keys are `status` and `total` (cents).
     *  - There is no `issued_at` field; the quote's issue date is the post's `post_date`.
     *  - Project has no `paid` status — invoicing is handled in Exact Online. The closest
     *    finalised state in QuoteStatus is `exported` (= processed/sent to Exact).
     *
     * @return array{count: int, invoiced: float, paid: float, outstanding: float}
     */
    private function quoteAggregates(int $year): array
    {
        global $wpdb;
        $start = sprintf('%d-01-01 00:00:00', $year);
        $end   = sprintf('%d-12-31 23:59:59', $year);

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT pm_status.meta_value AS status, pm_total.meta_value AS cents
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm_status ON p.ID = pm_status.post_id AND pm_status.meta_key = %s
             LEFT JOIN {$wpdb->postmeta} pm_total  ON p.ID = pm_total.post_id  AND pm_total.meta_key  = %s
             WHERE p.post_type = 'vad_quote'
               AND p.post_status = 'publish'
               AND p.post_date BETWEEN %s AND %s",
            'status',
            'total',
            $start,
            $end
        ));

        $count    = count($rows);
        $invoiced = 0.0;
        $paid     = 0.0;
        foreach ($rows as $r) {
            $eur = ((int) $r->cents) / 100;
            $invoiced += $eur;
            if ($r->status === \Stride\Domain\QuoteStatus::Exported->value) {
                $paid += $eur;
            }
        }
        return [
            'count'       => $count,
            'invoiced'    => round($invoiced, 2),
            'paid'        => round($paid, 2),
            'outstanding' => round($invoiced - $paid, 2),
        ];
    }

    private ?array $availableYearsCache = null;

    /** @var array<int, list<int>> */
    private array $editionIdsCache = [];

    /**
     * Returns distinct years (DESC) that have at least one published edition with a start date.
     *
     * @return list<int>
     */
    public function availableYears(): array
    {
        return $this->availableYearsCache ??= $this->fetchAvailableYears();
    }

    private function fetchAvailableYears(): array
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
            $this->editions->getMetaPrefix() . 'start_date',
            EditionCPT::POST_TYPE
        ));
        return array_map('intval', $rows);
    }

    private function yearHasData(int $year): bool
    {
        return in_array($year, $this->availableYears(), true);
    }

    /**
     * Edition IDs whose `_ntdst_start_date` falls within $year.
     *
     * @return list<int>
     */
    private function editionIdsForYear(int $year): array
    {
        return $this->editionIdsCache[$year] ??= $this->fetchEditionIdsForYear($year);
    }

    private function fetchEditionIdsForYear(int $year): array
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
            $this->editions->getMetaPrefix() . 'start_date',
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
        $sessionPrefix = $this->sessions->getMetaPrefix();
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT p.ID)
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm_date ON p.ID = pm_date.post_id AND pm_date.meta_key = %s
             INNER JOIN {$wpdb->postmeta} pm_ed ON p.ID = pm_ed.post_id AND pm_ed.meta_key = %s
             WHERE p.post_type = %s
               AND p.post_status = 'publish'
               AND pm_date.meta_value BETWEEN %s AND %s
               AND pm_ed.meta_value IN ({$placeholders})",
            $sessionPrefix . 'date',
            $sessionPrefix . 'edition_id',
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

        $sessionPrefix = $this->sessions->getMetaPrefix();
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
            $sessionPrefix . 'date',
            $sessionPrefix . 'edition_id',
            $sessionPrefix . 'start_time',
            $sessionPrefix . 'end_time',
            SessionCPT::POST_TYPE,
            $start,
            $end,
            ...$editionIds
        ));

        $attendanceTable = AttendanceTable::getTableName();
        // attendedValues() returns a pre-quoted, SQL-safe comma-separated list of enum constants.
        $attendedValues = AttendanceStatus::attendedValues();
        $totalHours = 0.0;
        foreach ($sessions as $s) {
            $duration = $this->hoursBetween($s->start_time, $s->end_time);
            if ($duration <= 0) {
                continue;
            }
            $attended = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$attendanceTable}
                 WHERE session_id = %d
                   AND status IN ({$attendedValues})",
                (int) $s->ID
            ));
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
