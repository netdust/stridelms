<?php
/**
 * V1 Slice 1 Complete Flow Test
 *
 * Tests the entire enrollment flow:
 * 1. Edition exists and is open
 * 2. User can enroll
 * 3. Registration is created
 * 4. LearnDash access is granted
 * 5. Shortcode displays enrollment
 *
 * Run: ddev exec wp eval-file scripts/test-v1-slice1-complete.php
 */

use Stride\Modules\Edition\EditionService;
use Stride\Modules\Enrollment\EnrollmentService;
use Stride\Modules\Enrollment\RegistrationRepository;
use Stride\Modules\User\DashboardShortcode;
use Stride\Domain\EditionStatus;
use Stride\Domain\RegistrationStatus;

echo "=== V1 Slice 1 Complete Flow Test ===\n\n";

$passed = 0;
$failed = 0;

function test($name, $condition, $message = '')
{
    global $passed, $failed;
    if ($condition) {
        echo "[PASS] {$name}\n";
        $passed++;
    } else {
        echo "[FAIL] {$name}" . ($message ? ": {$message}" : "") . "\n";
        $failed++;
    }
}

// ========================================
// 1. SERVICES ARE REGISTERED
// ========================================
echo "--- Service Registration ---\n";

$editionService = ntdst_get(EditionService::class);
test('EditionService is registered', $editionService !== null);

$enrollmentService = ntdst_get(EnrollmentService::class);
test('EnrollmentService is registered', $enrollmentService !== null);

$registrationRepo = ntdst_get(RegistrationRepository::class);
test('RegistrationRepository is registered', $registrationRepo !== null);

$dashboardShortcode = ntdst_get(DashboardShortcode::class);
test('DashboardShortcode is registered', $dashboardShortcode !== null);

// ========================================
// 2. EDITION EXISTS AND IS OPEN
// ========================================
echo "\n--- Edition Check ---\n";

$editions = ntdst_data()->get('vad_edition')
    ->where('status', 'open')
    ->where('post_status', 'publish')
    ->limit(1)
    ->withMeta()
    ->get();

test('Open edition exists', !empty($editions), 'Run seed-editions.php first');

if (empty($editions)) {
    echo "\n=== Cannot continue without editions ===\n";
    exit(1);
}

$edition = $editions[0];
$editionId = $edition['id'];
echo "  Using edition: {$editionId} - {$edition['title']}\n";

$courseId = $editionService->getCourseId($editionId);
test('Edition has course_id', $courseId !== null, "getCourseId returned: " . ($courseId ?? 'null'));
test('Edition status is open', $editionService->getStatus($editionId) === EditionStatus::Open);
test('Edition allows enrollment', $editionService->getStatus($editionId)->allowsEnrollment());

// ========================================
// 3. USER CAN ENROLL
// ========================================
echo "\n--- Enrollment Test ---\n";

// Create fresh test user
$testEmail = 'v1slice1test_' . time() . '@stride.test';
$userId = wp_create_user('v1slice1test_' . time(), 'testpass123', $testEmail);
test('Test user created', !is_wp_error($userId), is_wp_error($userId) ? $userId->get_error_message() : '');

if (is_wp_error($userId)) {
    echo "\n=== Cannot continue without test user ===\n";
    exit(1);
}

echo "  Test user ID: {$userId}\n";

// Check not already enrolled
$isEnrolledBefore = $enrollmentService->isEnrolled($userId, $editionId);
test('User is not enrolled before test', !$isEnrolledBefore);

// Enroll user
$registrationId = $enrollmentService->enroll($userId, $editionId);
test('Enrollment succeeds', !is_wp_error($registrationId), is_wp_error($registrationId) ? $registrationId->get_error_message() : '');

if (is_wp_error($registrationId)) {
    echo "\n=== Enrollment failed ===\n";
    // Cleanup
    wp_delete_user($userId);
    exit(1);
}

echo "  Registration ID: {$registrationId}\n";

// ========================================
// 4. REGISTRATION IS CORRECT
// ========================================
echo "\n--- Registration Verification ---\n";

$registration = $registrationRepo->find($registrationId);
test('Registration exists in database', !is_wp_error($registration) && $registration !== null);

if ($registration && !is_wp_error($registration)) {
    test('Registration user_id matches', (int)$registration->user_id === $userId);
    test('Registration edition_id matches', (int)$registration->edition_id === $editionId);
    test('Registration status is confirmed', $registration->status === RegistrationStatus::Confirmed->value);
}

// Check enrollment status
$isEnrolledAfter = $enrollmentService->isEnrolled($userId, $editionId);
test('User is enrolled after enrollment', $isEnrolledAfter);

// ========================================
// 5. LEARNDASH ACCESS
// ========================================
echo "\n--- LearnDash Access ---\n";

// $courseId already set above from getCourseId()
if ($courseId && function_exists('sfwd_lms_has_access')) {
    $hasLmsAccess = sfwd_lms_has_access($courseId, $userId);
    test('User has LearnDash course access', $hasLmsAccess);
} else {
    echo "  [SKIP] LearnDash not available or no course linked\n";
}

// ========================================
// 6. SHORTCODE RENDERING
// ========================================
echo "\n--- Shortcode Rendering ---\n";

wp_set_current_user($userId);
$output = $dashboardShortcode->renderMyCourses([]);
test('Shortcode produces output', strlen($output) > 0);
test('Shortcode contains edition title', strpos($output, 'Basisvorming') !== false || strpos($output, $edition['title']) !== false);

// ========================================
// 7. USER ENROLLMENTS LIST
// ========================================
echo "\n--- User Enrollments ---\n";

$enrollments = $enrollmentService->getUserEnrollments($userId);
test('getUserEnrollments returns array', is_array($enrollments));
test('User has 1 enrollment', count($enrollments) === 1);

// ========================================
// 8. CANCELLATION TEST
// ========================================
echo "\n--- Cancellation Test ---\n";

$cancelResult = $enrollmentService->cancel($registrationId);
test('Cancellation succeeds', $cancelResult === true || !is_wp_error($cancelResult));

$isEnrolledAfterCancel = $enrollmentService->isEnrolled($userId, $editionId);
test('User is not enrolled after cancellation', !$isEnrolledAfterCancel);

// Check LearnDash access revoked
if ($courseId && function_exists('sfwd_lms_has_access')) {
    $hasLmsAccessAfterCancel = sfwd_lms_has_access($courseId, $userId);
    test('LearnDash access revoked after cancellation', !$hasLmsAccessAfterCancel);
}

// ========================================
// CLEANUP
// ========================================
echo "\n--- Cleanup ---\n";

// Delete test registration
global $wpdb;
$wpdb->delete($wpdb->prefix . 'vad_registrations', ['id' => $registrationId]);
echo "  Deleted test registration {$registrationId}\n";

// Delete test user
wp_delete_user($userId);
echo "  Deleted test user {$userId}\n";

// ========================================
// SUMMARY
// ========================================
echo "\n=== TEST SUMMARY ===\n";
echo "Passed: {$passed}\n";
echo "Failed: {$failed}\n";
echo "Total: " . ($passed + $failed) . "\n";

if ($failed === 0) {
    echo "\nV1 Slice 1 is COMPLETE and WORKING!\n";
    exit(0);
} else {
    echo "\nSome tests failed. Review output above.\n";
    exit(1);
}
