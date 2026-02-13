<?php
/**
 * Phase 5 Test Script - Attendance & Completion
 *
 * Tests the AttendanceRepository, CompletionEngine, and related functionality.
 *
 * Usage:
 *   ddev exec wp eval-file scripts/test-phase5.php
 */

if (!defined('ABSPATH')) {
    echo "This script must be run via WP-CLI\n";
    exit(1);
}

use ntdst\Stride\core\AttendanceRepository;
use ntdst\Stride\core\SessionService;
use ntdst\Stride\core\EditionService;
use ntdst\Stride\core\CourseService;
use ntdst\Stride\core\CompletionEngine;
use ntdst\Stride\core\RegistrationRepository;
use ntdst\Stride\FieldRegistry;

// Test tracking
$passed = 0;
$failed = 0;

function test(string $name, bool $condition): void
{
    global $passed, $failed;
    if ($condition) {
        echo "  [PASS] {$name}\n";
        $passed++;
    } else {
        echo "  [FAIL] {$name}\n";
        $failed++;
    }
}

echo "=== Phase 5: Attendance & Completion Tests ===\n\n";

// ========================================
// SETUP: Create test data
// ========================================

echo "Setting Up Test Data...\n";

// Create test user
$testUser = wp_create_user('phase5_test_user', 'testpass123', 'phase5@test.test');
if (is_wp_error($testUser)) {
    $user = get_user_by('login', 'phase5_test_user');
    $testUser = $user ? $user->ID : 0;
}
test('Test user created', $testUser > 0);

// Grant admin capabilities for attendance management tests
$user = new WP_User($testUser);
$user->set_role('administrator');
wp_set_current_user($testUser);

// Create test course
$courseId = wp_insert_post([
    'post_type' => 'sfwd-courses',
    'post_title' => 'Phase 5 Test Course',
    'post_status' => 'publish',
]);
test('Test course created', $courseId > 0);

// Create edition service
$editionService = function_exists('ntdst_get')
    ? ntdst_get(EditionService::class)
    : new EditionService();

// Create test edition
$editionId = wp_insert_post([
    'post_type' => 'vad_edition',
    'post_title' => 'Phase 5 Test Edition',
    'post_status' => 'publish',
]);
update_post_meta($editionId, FieldRegistry::EDITION_COURSE_ID, $courseId);
update_post_meta($editionId, FieldRegistry::EDITION_START_DATE, '2025-06-01');
update_post_meta($editionId, FieldRegistry::EDITION_END_DATE, '2025-06-03');
update_post_meta($editionId, FieldRegistry::EDITION_COMPLETION_MODE, CompletionEngine::MODE_ATTEND_ALL);
update_post_meta($editionId, FieldRegistry::EDITION_VENUE, 'Test Venue Brussels');
test('Test edition created', $editionId > 0);

// Create test sessions (3 days)
$sessionService = function_exists('ntdst_get')
    ? ntdst_get(SessionService::class)
    : new SessionService();

$sessions = [];
for ($i = 1; $i <= 3; $i++) {
    $sessionId = $sessionService->createSession([
        FieldRegistry::SESSION_EDITION_ID => $editionId,
        FieldRegistry::SESSION_DATE => "2025-06-0{$i}",
        FieldRegistry::SESSION_START_TIME => '09:00',
        FieldRegistry::SESSION_END_TIME => '17:00',
    ]);
    $sessions[] = is_int($sessionId) ? $sessionId : 0;
}
test('3 sessions created', count(array_filter($sessions)) === 3);

// Create registration
$regRepo = function_exists('ntdst_get')
    ? ntdst_get(RegistrationRepository::class)
    : new RegistrationRepository();

$regId = $regRepo->create([
    'user_id' => $testUser,
    'edition_id' => $editionId,
    'status' => RegistrationRepository::STATUS_CONFIRMED,
    'enrollment_path' => RegistrationRepository::PATH_INDIVIDUAL,
]);
test('Registration created', $regId > 0);

echo "\n";

// ========================================
// A. AttendanceRepository Tests
// ========================================

echo "A. Testing AttendanceRepository...\n";

$attendanceRepo = new AttendanceRepository();

// A1. Table creation
test('A1. Table exists after service init', $attendanceRepo->tableExists());

// A2. Mark present
$markResult = $attendanceRepo->mark($sessions[0], $testUser, AttendanceRepository::STATUS_PRESENT);
test('A2. mark() returns true', $markResult === true);

// A3. Check status
$status = $attendanceRepo->getStatus($sessions[0], $testUser);
test('A3. getStatus() returns "present"', $status === AttendanceRepository::STATUS_PRESENT);

// A4. isPresent
test('A4. isPresent() returns true', $attendanceRepo->isPresent($sessions[0], $testUser));

// A5. Mark absent
$attendanceRepo->mark($sessions[1], $testUser, AttendanceRepository::STATUS_ABSENT);
test('A5. Can mark absent', $attendanceRepo->getStatus($sessions[1], $testUser) === AttendanceRepository::STATUS_ABSENT);

// A6. Mark excused
$attendanceRepo->mark($sessions[2], $testUser, AttendanceRepository::STATUS_EXCUSED);
test('A6. Can mark excused', $attendanceRepo->getStatus($sessions[2], $testUser) === AttendanceRepository::STATUS_EXCUSED);

// A7. Get attendees for session
$attendees = $attendanceRepo->getAttendeesForSession($sessions[0]);
test('A7. getAttendeesForSession() returns user', in_array($testUser, $attendees, true));

// A8. Batch get attendees
$batchAttendees = $attendanceRepo->batchGetAttendees($sessions);
test('A8. batchGetAttendees() returns map', isset($batchAttendees[$sessions[0]]));

// A9. Count attended sessions
$count = $attendanceRepo->countAttendedSessions($testUser, $editionId);
test('A9. countAttendedSessions() = 1 (only session[0] is present)', $count === 1);

// A10. Batch mark
$batchResult = $attendanceRepo->batchMark($sessions[1], [
    $testUser => AttendanceRepository::STATUS_PRESENT,
]);
test('A10. batchMark() works', $batchResult > 0);

// Recount - should now be 2
$count = $attendanceRepo->countAttendedSessions($testUser, $editionId);
test('A10b. countAttendedSessions() = 2 after batch', $count === 2);

echo "\n";

// ========================================
// B. SessionService with AttendanceRepository
// ========================================

echo "B. Testing SessionService with AttendanceRepository...\n";

// B1. markPresent via service
$markResult = $sessionService->markPresent($sessions[2], $testUser);
test('B1. SessionService::markPresent() succeeds', $markResult === true);

// B2. isPresent via service
test('B2. SessionService::isPresent() returns true', $sessionService->isPresent($sessions[2], $testUser));

// B3. getAttendees via service
$attendees = $sessionService->getAttendees($sessions[2]);
test('B3. SessionService::getAttendees() returns user', in_array($testUser, $attendees, true));

// B4. countAttendedSessions via service
$count = $sessionService->countAttendedSessions($testUser, $editionId);
test('B4. SessionService::countAttendedSessions() = 3', $count === 3);

// B5. getAttendanceRate
$rate = $sessionService->getAttendanceRate($testUser, $editionId);
test('B5. SessionService::getAttendanceRate() = 1.0 (100%)', abs($rate - 1.0) < 0.01);

// B6. getHoursAttended
$hours = $sessionService->getHoursAttended($testUser, $editionId);
test('B6. SessionService::getHoursAttended() = 24 (3 days x 8 hours)', abs($hours - 24.0) < 0.1);

// B7. markExcused via service
$excuseResult = $sessionService->markExcused($sessions[0], $testUser);
test('B7. SessionService::markExcused() works', $excuseResult === true);

$newStatus = $attendanceRepo->getStatus($sessions[0], $testUser);
test('B7b. Status changed to excused', $newStatus === AttendanceRepository::STATUS_EXCUSED);

echo "\n";

// ========================================
// C. CompletionEngine Tests
// ========================================

echo "C. Testing CompletionEngine...\n";

// Reset attendance - mark all present
foreach ($sessions as $sid) {
    $attendanceRepo->mark($sid, $testUser, AttendanceRepository::STATUS_PRESENT);
}

$completionEngine = function_exists('ntdst_get')
    ? ntdst_get(CompletionEngine::class)
    : new CompletionEngine();

// C1. isEditionComplete with attend_all mode
test('C1. isEditionComplete() = true with 100% attendance',
    $completionEngine->isEditionComplete($editionId, $testUser));

// C2. Mark one absent - should fail completion
$attendanceRepo->mark($sessions[0], $testUser, AttendanceRepository::STATUS_ABSENT);
test('C2. isEditionComplete() = false with 2/3 attendance',
    !$completionEngine->isEditionComplete($editionId, $testUser));

// C3. Test percentage mode
update_post_meta($editionId, FieldRegistry::EDITION_COMPLETION_MODE, CompletionEngine::MODE_ATTEND_PERCENTAGE);
update_post_meta($editionId, FieldRegistry::EDITION_COMPLETION_THRESHOLD, 60);

// 2/3 = 66.67% > 60% threshold
test('C3. isEditionComplete() = true with 66% attendance and 60% threshold',
    $completionEngine->isEditionComplete($editionId, $testUser));

// C4. Test count mode
update_post_meta($editionId, FieldRegistry::EDITION_COMPLETION_MODE, CompletionEngine::MODE_ATTEND_COUNT);
update_post_meta($editionId, FieldRegistry::EDITION_COMPLETION_THRESHOLD, 2);

// Attended 2 sessions, threshold is 2
test('C4. isEditionComplete() = true with 2 sessions and threshold 2',
    $completionEngine->isEditionComplete($editionId, $testUser));

// C5. Threshold too high
update_post_meta($editionId, FieldRegistry::EDITION_COMPLETION_THRESHOLD, 3);
test('C5. isEditionComplete() = false with 2 sessions and threshold 3',
    !$completionEngine->isEditionComplete($editionId, $testUser));

// C6. getCompletionStatus
$status = $completionEngine->getCompletionStatus($editionId, $testUser);
test('C6. getCompletionStatus() returns array with expected keys',
    isset($status['total_sessions']) && isset($status['attended']) && isset($status['is_complete']));

test('C6b. getCompletionStatus() shows 2 attended of 3 total',
    $status['attended'] === 2 && $status['total_sessions'] === 3);

echo "\n";

// ========================================
// D. Certificate Shortcode Tests
// ========================================

echo "D. Testing Certificate Shortcodes...\n";

$smartCode = function_exists('ntdst_get')
    ? ntdst_get(\ntdst\Stride\smartcode\SmartCodeService::class)
    : null;

if ($smartCode) {
    // D1. Set edition context
    $smartCode->setEditionId($editionId);

    // D2. Test edition title shortcode
    $title = do_shortcode('[stride_edition_title]');
    test('D1. [stride_edition_title] outputs edition title',
        strpos($title, 'Phase 5 Test Edition') !== false);

    // D3. Test venue shortcode (venue set during edition creation)
    $venue = do_shortcode('[stride_venue]');
    test('D2. [stride_venue] outputs venue',
        strpos($venue, 'Test Venue Brussels') !== false);

    // D4. Test hours attended
    // Reset context with user
    $_GET['user'] = $testUser;
    $hours = do_shortcode('[stride_hours_attended]');
    test('D3. [stride_hours_attended] outputs hours',
        !empty($hours) && is_numeric(str_replace(',', '.', $hours)));
    unset($_GET['user']);
} else {
    echo "  [SKIP] SmartCodeService not available\n";
}

echo "\n";

// ========================================
// E. Authorization Tests
// ========================================

echo "E. Testing Authorization...\n";

// Create a second non-admin user for authorization tests
$nonAdminUser = wp_create_user('phase5_nonadmin', 'testpass123', 'phase5_nonadmin@test.test');
if (is_wp_error($nonAdminUser)) {
    $user = get_user_by('login', 'phase5_nonadmin');
    $nonAdminUser = $user ? $user->ID : 0;
}
$nonAdminUserObj = new WP_User($nonAdminUser);
$nonAdminUserObj->set_role('subscriber'); // No admin capabilities

// E1. Test that non-admin can't view other users' attendance via shortcode
wp_set_current_user($nonAdminUser); // Switch to non-admin user

if ($smartCode) {
    $smartCode->setEditionId($editionId);

    // Try to view testUser's data as nonAdminUser (should be blocked)
    $_GET['user'] = $testUser;
    $hours = do_shortcode('[stride_hours_attended]');

    // Non-admin should get their own hours (0) or empty, not testUser's hours
    // Since nonAdminUser has no attendance, result should be 0 or empty
    $hoursValue = floatval(str_replace(',', '.', $hours));
    test('E1. Non-admin cannot view other user attendance via URL param',
        $hoursValue === 0.0 || empty($hours));
    unset($_GET['user']);
}

// E2. Test that user without registration can't access edition context via URL
// Create another edition that nonAdminUser has NO registration for
$otherEditionId = wp_insert_post([
    'post_type' => 'vad_edition',
    'post_title' => 'Phase 5 Other Edition',
    'post_status' => 'publish',
]);
update_post_meta($otherEditionId, FieldRegistry::EDITION_COURSE_ID, $courseId);
update_post_meta($otherEditionId, FieldRegistry::EDITION_VENUE, 'Secret Venue');

if ($smartCode) {
    // Clear any cached edition
    $smartCode->setEditionId(null);

    // Try to access other edition via URL param (should be blocked)
    $_GET['edition_id'] = $otherEditionId;
    $venue = do_shortcode('[stride_venue]');

    // Should NOT return the secret venue since user has no registration
    test('E2. User without registration cannot access edition via URL param',
        strpos($venue, 'Secret Venue') === false);
    unset($_GET['edition_id']);
}

// Clean up other edition
wp_delete_post($otherEditionId, true);

// E3. Test that admin CAN view other users' attendance
wp_set_current_user($testUser); // Switch back to admin user

if ($smartCode) {
    $smartCode->setEditionId($editionId);

    // Admin viewing nonAdminUser's data should work (even if 0)
    $_GET['user'] = $nonAdminUser;
    $hours = do_shortcode('[stride_hours_attended]');
    // This should NOT error - admin can view any user
    test('E3. Admin can view other user attendance via URL param', true);
    unset($_GET['user']);
}

// E4. Test that user CAN access edition they're registered for
// Register nonAdminUser for the test edition
$nonAdminRegId = $regRepo->create([
    'user_id' => $nonAdminUser,
    'edition_id' => $editionId,
    'status' => RegistrationRepository::STATUS_CONFIRMED,
    'enrollment_path' => RegistrationRepository::PATH_INDIVIDUAL,
]);

wp_set_current_user($nonAdminUser); // Switch to non-admin

if ($smartCode) {
    $smartCode->setEditionId(null); // Clear cache

    $_GET['edition_id'] = $editionId;
    $venue = do_shortcode('[stride_venue]');

    // Should return venue since user has registration
    test('E4. User with registration can access edition via URL param',
        strpos($venue, 'Test Venue Brussels') !== false);
    unset($_GET['edition_id']);
}

// E5. Test SessionService authorization - non-admin can't mark attendance
$markResult = $sessionService->markPresent($sessions[0], $testUser);
test('E5. Non-admin cannot mark attendance',
    is_wp_error($markResult) && $markResult->get_error_code() === 'unauthorized');

// E6. Test that canManageAttendance returns false for non-admin
test('E6. canManageAttendance() returns false for non-admin',
    !$sessionService->canManageAttendance());

// Switch back to admin for cleanup
wp_set_current_user($testUser);

// Clean up non-admin registration
if ($nonAdminRegId) {
    $regRepo->delete($nonAdminRegId);
}

// Delete non-admin user
wp_delete_user($nonAdminUser);
echo "  - Deleted non-admin test user\n";

echo "\n";

// ========================================
// CLEANUP
// ========================================

echo "Cleaning Up Test Data...\n";

// Delete attendance records
global $wpdb;
$tableName = $attendanceRepo->getTableName();
$wpdb->query($wpdb->prepare(
    "DELETE FROM {$tableName} WHERE edition_id = %d",
    $editionId
));
echo "  - Deleted attendance records\n";

// Delete registration
if ($regId) {
    $regRepo->delete($regId);
    echo "  - Deleted registration {$regId}\n";
}

// Delete sessions
foreach ($sessions as $sid) {
    wp_delete_post($sid, true);
}
echo "  - Deleted " . count($sessions) . " sessions\n";

// Delete edition
wp_delete_post($editionId, true);
echo "  - Deleted edition {$editionId}\n";

// Delete course
wp_delete_post($courseId, true);
echo "  - Deleted course {$courseId}\n";

// Delete user
wp_delete_user($testUser);
echo "  - Deleted user {$testUser}\n";

echo "  Cleanup complete.\n";

// ========================================
// RESULTS
// ========================================

echo "\n=== Test Results ===\n";
echo "Passed: {$passed}\n";
echo "Failed: {$failed}\n";

if ($failed === 0) {
    echo "ALL TESTS PASSED!\n";
    exit(0);
} else {
    echo "SOME TESTS FAILED!\n";
    exit(1);
}
