<?php

/**
 * Trajectory card helper.
 *
 * Builds the normalized args contract that `partials/card-trajectory.php`
 * consumes — used by BOTH the public catalog (archive-vad_trajectory.php)
 * and the dashboard "Mijn trajecten" tab, so the one card renders the same
 * everywhere. The partial stays a pure renderer (no service calls); ALL
 * lookups happen here.
 *
 * Per-user state (progress, started_at, dashboard_url) is OPTIONAL — passed
 * only from the dashboard. Its absence is what makes a card a catalog card
 * (no "X% voltooid" badge, "Bekijk traject" instead of "Open traject").
 *
 * @package stridence
 */

declare(strict_types=1);

use Stride\Modules\Trajectory\TrajectoryService;

if (!function_exists('stridence_build_trajectory_card_args')) {
    /**
     * @param array{progress?: int|null, started_at?: string, dashboard_url?: string} $opts
     * @return array<string, mixed>
     */
    function stridence_build_trajectory_card_args(int $trajectoryId, array $opts = []): array
    {
        $service    = ntdst_get(TrajectoryService::class);
        $trajectory = $service->getTrajectory($trajectoryId);

        if (!$trajectory) {
            return [];
        }

        $progress = $opts['progress'] ?? null;
        if ($progress !== null) {
            $progress = max(0, min(100, (int) $progress));
        }

        return [
            'id'             => $trajectoryId,
            'title'          => $trajectory['title'] ?? '',
            'status'         => $trajectory['status'] ?? 'open',
            'course_count'   => $service->getCourseCount($trajectoryId),
            'elective_count' => $service->getElectiveGroupCount($trajectoryId),
            'price'          => (int) ($trajectory['price'] ?? 0), // canonical cents
            'deadline'       => (string) ($trajectory['enrollment_deadline'] ?? ''),
            // Enrolled-only (dashboard) — null/'' on the catalog path.
            'progress'       => $progress,
            'started_at'     => (string) ($opts['started_at'] ?? ''),
            'dashboard_url'  => (string) ($opts['dashboard_url'] ?? ''),
        ];
    }
}
