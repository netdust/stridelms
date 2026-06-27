<?php

/**
 * Repair trajectory course entries authored without type/edition_id
 * (pre-2026-06-12 seeder shape — shake-out BUG-2).
 *
 * Entries lacking these keys are treated as pure-LD by the selection and
 * cascade paths, so picks bypass the edition registration, capacity and
 * quote machinery. This binds each course entry to its OPEN edition
 * (fallback: any edition); courses without editions become explicit
 * 'online' entries.
 *
 * Run: ddev exec wp eval-file scripts/fix-trajectory-course-editions.php
 * Idempotent — entries that already carry a type are left untouched.
 * (No strict_types: wp eval-file wraps the file in eval().)
 */

$repo = ntdst_get(\Stride\Modules\Trajectory\TrajectoryRepository::class);

$trajectories = get_posts([
    'post_type' => 'vad_trajectory',
    'post_status' => 'any',
    'numberposts' => -1,
]);

$resolveEdition = static function (int $courseId): int {
    $candidates = get_posts([
        'post_type' => 'vad_edition',
        'post_status' => 'publish',
        'numberposts' => -1,
        'meta_key' => '_ntdst_course_id',
        'meta_value' => (string) $courseId,
        'fields' => 'ids',
    ]);
    $fallback = 0;
    foreach ($candidates as $editionId) {
        $status = (string) get_post_meta((int) $editionId, '_ntdst_status', true);
        if ($status === 'open') {
            return (int) $editionId;
        }
        if (!$fallback) {
            $fallback = (int) $editionId;
        }
    }

    return $fallback;
};

foreach ($trajectories as $trajectory) {
    $courses = $repo->getCourses($trajectory->ID);
    if (empty($courses)) {
        continue;
    }

    $changed = 0;
    foreach ($courses as &$entry) {
        if (!empty($entry['type'])) {
            continue; // already authored with the admin-UI shape
        }
        $courseId = (int) ($entry['course_id'] ?? 0);
        $editionId = $courseId ? $resolveEdition($courseId) : 0;
        if ($editionId) {
            $entry['type'] = 'edition';
            $entry['edition_id'] = $editionId;
        } else {
            $entry['type'] = 'online';
        }
        $changed++;
    }
    unset($entry);

    if ($changed > 0) {
        $repo->update($trajectory->ID, ['courses' => $courses]);
        printf("Repaired %d course entries on trajectory %d (%s)\n", $changed, $trajectory->ID, $trajectory->post_title);
    }
}

echo "Done.\n";
