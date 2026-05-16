<?php

declare(strict_types=1);

namespace Stride\Modules\Reporting;

use Stride\Domain\AttendanceStatus;
use Stride\Modules\Attendance\AttendanceTable;
use Stride\Modules\Edition\EditionCPT;
use Stride\Modules\Edition\SessionCPT;
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

    public function buildReport(int $year): AnnualReport
    {
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
            sections: [], // populated in Task 4
        );
    }

    /**
     * Returns distinct years (DESC) that have at least one published edition with a start date.
     *
     * @return list<int>
     */
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

    /**
     * Edition IDs whose `_ntdst_start_date` falls within $year.
     *
     * @return list<int>
     */
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
