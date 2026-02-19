<?php
/**
 * Test enrollments loading for user
 */

$userId = 1;

$enrollmentService = ntdst_get(\Stride\Modules\Enrollment\EnrollmentService::class);
$enrollments = $enrollmentService->getUserEnrollments($userId);

echo "Enrollments found for user {$userId}: " . count($enrollments) . PHP_EOL;

foreach ($enrollments as $e) {
    echo "- Edition ID: " . ($e->edition_id ?? $e['edition_id'] ?? '?');
    echo ", Status: " . ($e->status ?? $e['status'] ?? '?');
    echo PHP_EOL;
}
