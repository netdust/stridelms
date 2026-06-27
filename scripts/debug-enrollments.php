<?php

/**
 * Debug user enrollments and sessions
 */

$userId = 1; // Admin user
$enrollmentService = ntdst_get(\Stride\Modules\Enrollment\EnrollmentService::class);
$editionService = ntdst_get(\Stride\Modules\Edition\EditionService::class);
$sessionService = ntdst_get(\Stride\Modules\Edition\SessionService::class);

$enrollments = $enrollmentService->getUserEnrollments($userId);
echo "User has " . count($enrollments) . " edition enrollments\n";

$includedCourseIds = [];
$validEditions = 0;

foreach ($enrollments as $e) {
    $editionId = $e->edition_id;
    $edition = $editionService->getEdition($editionId);
    if (is_wp_error($edition)) {
        echo "  Edition #$editionId: INVALID (WP_Error)\n";
        continue;
    }
    $validEditions++;
    $courseId = $editionService->getCourseId($editionId);
    if ($courseId) {
        $includedCourseIds[] = $courseId;
    }
    echo "  Edition #$editionId: Course #$courseId - " . ($edition->post_title ?? "no title") . "\n";
}

echo "\nValid editions: $validEditions\n";
echo "Course IDs from editions: " . implode(", ", $includedCourseIds) . "\n";

// Check LearnDash direct enrollments
echo "\n--- LearnDash Direct Enrollments ---\n";
$ldCourses = learndash_user_get_enrolled_courses($userId);
echo "Total LD courses: " . count($ldCourses) . "\n";

$onlineOnly = 0;
foreach ($ldCourses as $courseId) {
    $inEdition = in_array($courseId, $includedCourseIds, true);
    $title = get_the_title($courseId);
    $status = $inEdition ? "(in edition)" : "(ONLINE ONLY)";
    echo "  Course #$courseId: $title $status\n";
    if (!$inEdition) {
        $onlineOnly++;
    }
}

echo "\nOnline-only courses: $onlineOnly\n";
echo "Expected total in dashboard: " . ($validEditions + $onlineOnly) . "\n";
