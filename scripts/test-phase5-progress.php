<?php
/**
 * Phase 5.3-5.4 Test Script - Trajectories & Session Selection
 *
 * Tests the new TrajectoryService, ProgressEngine, and SessionSelectionService.
 *
 * Usage:
 *   ddev exec wp eval-file scripts/test-phase5-progress.php
 */

if (!defined('ABSPATH')) {
    echo "This script must be run via WP-CLI\n";
    exit(1);
}

use ntdst\Stride\core\TrajectoryService;
use ntdst\Stride\core\TrajectoryEnrollmentRepository;
use ntdst\Stride\core\ProgressEngine;
use ntdst\Stride\core\SessionSelectionRepository;
use ntdst\Stride\core\SessionSelectionService;
use ntdst\Stride\core\SessionService;
use ntdst\Stride\core\EditionService;
use ntdst\Stride\core\CourseService;
use ntdst\Stride\core\RegistrationRepository;
use ntdst\Stride\enrollment\EnrollmentService;
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

echo "=== Phase 5.3-5.4: Trajectories & Session Selection Tests ===\n\n";

// ========================================
// 1. CLASS LOADING
// ========================================

echo "1. Testing Class Loading...\n";

$classes = [
    TrajectoryService::class,
    TrajectoryEnrollmentRepository::class,
    ProgressEngine::class,
    SessionSelectionRepository::class,
    SessionSelectionService::class,
];

foreach ($classes as $class) {
    test(basename(str_replace('\\', '/', $class)) . ' class exists', class_exists($class));
}

echo "\n";

// ========================================
// 2. SERVICE RESOLUTION
// ========================================

echo "2. Testing Service Resolution...\n";

$services = [
    TrajectoryService::class => null,
    ProgressEngine::class => null,
    SessionSelectionService::class => null,
];

foreach (array_keys($services) as $serviceClass) {
    try {
        $services[$serviceClass] = ntdst_get($serviceClass);
        test(basename(str_replace('\\', '/', $serviceClass)) . ' resolved via ntdst_get()', true);
    } catch (Exception $e) {
        test(basename(str_replace('\\', '/', $serviceClass)) . ' resolved via ntdst_get()', false);
    }
}

$trajectoryService = $services[TrajectoryService::class];
$progressEngine = $services[ProgressEngine::class];
$sessionSelectionService = $services[SessionSelectionService::class];

echo "\n";

// ========================================
// 3. TABLE EXISTENCE
// ========================================

echo "3. Testing Table Existence...\n";

global $wpdb;

$sessionSelectionRepo = new SessionSelectionRepository();
$trajectoryEnrollmentRepo = new TrajectoryEnrollmentRepository();

test('Session selection table exists', $sessionSelectionRepo->tableExists());
test('Trajectory enrollment table exists', $trajectoryEnrollmentRepo->tableExists());

echo "\n";

// ========================================
// 4. TRAJECTORY CPT
// ========================================

echo "4. Testing Trajectory CPT...\n";

test('vad_trajectory CPT registered', post_type_exists(TrajectoryService::POST_TYPE));

echo "\n";

// ========================================
// SETUP TEST DATA
// ========================================

echo "Setting Up Test Data...\n";

// Create test user
$testUser = wp_create_user('phase5_progress_test', 'testpass123', 'phase5_progress@test.test');
if (is_wp_error($testUser)) {
    $user = get_user_by('login', 'phase5_progress_test');
    $testUser = $user ? $user->ID : 0;
}
test('Test user created', $testUser > 0);

// Grant admin for testing
$user = new WP_User($testUser);
$user->set_role('administrator');
wp_set_current_user($testUser);

// Create test courses
$courseIds = [];
for ($i = 1; $i <= 4; $i++) {
    $courseIds[$i] = wp_insert_post([
        'post_type' => 'sfwd-courses',
        'post_title' => "Test Course {$i}",
        'post_status' => 'publish',
    ]);
}
test('4 test courses created', count(array_filter($courseIds)) === 4);

// Create test edition with sessions for session selection testing
$editionService = ntdst_get(EditionService::class);
$sessionService = ntdst_get(SessionService::class);
$regRepo = ntdst_get(RegistrationRepository::class);

// Use future dates (30 days from now)
$startDate = date('Y-m-d', strtotime('+30 days'));
$endDate = date('Y-m-d', strtotime('+31 days'));

$editionId = wp_insert_post([
    'post_type' => 'vad_edition',
    'post_title' => 'Session Selection Test Edition',
    'post_status' => 'publish',
]);
update_post_meta($editionId, FieldRegistry::EDITION_COURSE_ID, $courseIds[1]);
update_post_meta($editionId, FieldRegistry::EDITION_START_DATE, $startDate);
update_post_meta($editionId, FieldRegistry::EDITION_END_DATE, $endDate);
test('Test edition created', $editionId > 0);

// Create sessions with slots
$sessionIds = [];

// Morning slot - 1 session (auto-select)
$sessionIds[] = $sessionService->createSession([
    FieldRegistry::SESSION_EDITION_ID => $editionId,
    FieldRegistry::SESSION_DATE => $startDate,
    FieldRegistry::SESSION_START_TIME => '09:00',
    FieldRegistry::SESSION_END_TIME => '12:00',
    FieldRegistry::SESSION_SLOT => 'morning',
    FieldRegistry::SESSION_SLOT_LABEL => 'Voormiddag',
]);

// Afternoon slot - 2 sessions (pick 1)
$sessionIds[] = $sessionService->createSession([
    FieldRegistry::SESSION_EDITION_ID => $editionId,
    FieldRegistry::SESSION_DATE => $startDate,
    FieldRegistry::SESSION_START_TIME => '13:00',
    FieldRegistry::SESSION_END_TIME => '17:00',
    FieldRegistry::SESSION_SLOT => 'afternoon',
    FieldRegistry::SESSION_SLOT_LABEL => 'Namiddag',
]);
$sessionIds[] = $sessionService->createSession([
    FieldRegistry::SESSION_EDITION_ID => $editionId,
    FieldRegistry::SESSION_DATE => $startDate,
    FieldRegistry::SESSION_START_TIME => '13:00',
    FieldRegistry::SESSION_END_TIME => '17:00',
    FieldRegistry::SESSION_SLOT => 'afternoon',
    FieldRegistry::SESSION_SLOT_LABEL => 'Namiddag',
]);
$sessionIds = array_filter($sessionIds, fn($id) => is_int($id));
test('3 sessions created with slots', count($sessionIds) === 3);

// Set up session slot configuration on edition
$slotConfig = [
    ['slot' => 'morning', 'label' => 'Voormiddag', 'pick_count' => 1, 'required' => true],
    ['slot' => 'afternoon', 'label' => 'Namiddag', 'pick_count' => 1, 'required' => true],
];
$editionService->setSessionSlots($editionId, $slotConfig);
test('Session slots configured on edition', $editionService->requiresSessionSelection($editionId));

echo "\n";

// ========================================
// 5. SESSION SERVICE SLOT METHODS
// ========================================

echo "5. Testing SessionService Slot Methods...\n";

$sessionsBySlot = $sessionService->getSessionsBySlot($editionId);
test('getSessionsBySlot() returns slots', isset($sessionsBySlot['morning']) && isset($sessionsBySlot['afternoon']));
test('Morning slot has 1 session', count($sessionsBySlot['morning']['sessions'] ?? []) === 1);
test('Afternoon slot has 2 sessions', count($sessionsBySlot['afternoon']['sessions'] ?? []) === 2);

$slots = $sessionService->getSlots($editionId);
// getSlots returns array of slot objects with 'slot' key
$slotNames = array_column($slots, 'slot');
test('getSlots() returns slot objects', in_array('morning', $slotNames) && in_array('afternoon', $slotNames));

echo "\n";

// ========================================
// 6. EDITION SERVICE SLOT METHODS
// ========================================

echo "6. Testing EditionService Slot Methods...\n";

$slotInfo = $editionService->getSessionSlots($editionId);
test('getSessionSlots() returns configuration', count($slotInfo) === 2);
test('requiresSessionSelection() returns true', $editionService->requiresSessionSelection($editionId));
test('isSelectionOpen() returns true (edition not started)', $editionService->isSelectionOpen($editionId));

// Set a future deadline
$futureDate = date('Y-m-d', strtotime('+45 days'));
$editionService->setSelectionDeadline($editionId, $futureDate);
test('Selection deadline can be set', $editionService->getSelectionDeadline($editionId) === $futureDate);
test('isSelectionOpen() returns true (future deadline)', $editionService->isSelectionOpen($editionId));

echo "\n";

// ========================================
// 7. SESSION SELECTION SERVICE
// ========================================

echo "7. Testing SessionSelectionService...\n";

// Create registration
$regId = $regRepo->create([
    'user_id' => $testUser,
    'edition_id' => $editionId,
    'status' => RegistrationRepository::STATUS_CONFIRMED,
    'enrollment_path' => RegistrationRepository::PATH_INDIVIDUAL,
]);
test('Registration created', $regId > 0);

// Get available slots
$slotsInfo = $sessionSelectionService->getSessionSlots($editionId);
test('getSessionSlots() returns slot data', !empty($slotsInfo));

// Select sessions (morning + one afternoon)
$morningSessionId = $sessionIds[0];
$afternoonSessionId = $sessionIds[1];

$selectResult = $sessionSelectionService->selectSessions($regId, [$morningSessionId, $afternoonSessionId]);
test('selectSessions() succeeds', $selectResult === true);

// Get user selections
$selections = $sessionSelectionService->getUserSelections($regId);
test('getUserSelections() returns 2 sessions', count($selections) === 2);
test('Selection includes morning session', in_array($morningSessionId, $selections));
test('Selection includes afternoon session', in_array($afternoonSessionId, $selections));

// Check selection complete
test('isSelectionComplete() returns true', $sessionSelectionService->isSelectionComplete($regId));

// Test over-selection (should fail)
$overSelectResult = $sessionSelectionService->selectSessions($regId, array_values($sessionIds));
test('Over-selection is rejected', is_wp_error($overSelectResult));

// Test auto-select single options
$sessionSelectionService->clearSelections($regId);
$autoResult = $sessionSelectionService->autoSelectSingleOptions($regId);
test('autoSelectSingleOptions() works', $autoResult === true);

$autoSelections = $sessionSelectionService->getUserSelections($regId);
test('Auto-select picks morning session', in_array($morningSessionId, $autoSelections));
test('Auto-select does not pick afternoon (has choices)', count($autoSelections) === 1);

echo "\n";

// ========================================
// 8. TRAJECTORY SERVICE
// ========================================

echo "8. Testing TrajectoryService...\n";

// Create trajectory with courses in the data
$requirements = [
    ['course_id' => $courseIds[1], 'group' => 'Basismodules'],
    ['course_id' => $courseIds[2], 'group' => 'Basismodules'],
    ['course_id' => $courseIds[3], 'group' => 'Keuzemodules', 'pick_count' => 1],
    ['course_id' => $courseIds[4], 'group' => 'Keuzemodules', 'pick_count' => 1],
];

$trajectoryId = $trajectoryService->createTrajectory([
    'title' => 'Test Trajectory',
    'description' => 'A test learning path',
    'status' => FieldRegistry::TRAJECTORY_STATUS_OPEN,
    'deadline_months' => 24,
    'courses' => $requirements,
]);
test('Trajectory created', !is_wp_error($trajectoryId) && $trajectoryId > 0);

// Get trajectory
$trajectory = $trajectoryService->getTrajectory($trajectoryId);
test('getTrajectory() returns data', !empty($trajectory) && $trajectory['title'] === 'Test Trajectory');

// Get course requirements
$reqs = $trajectoryService->getCourseRequirements($trajectoryId);
test('getCourseRequirements() returns 4 items', count($reqs) === 4);

// Get required courses (those without pick_count or pick_count <= 0)
$requiredCourses = $trajectoryService->getRequiredCourses($trajectoryId);
test('getRequiredCourses() returns 2 items', count($requiredCourses) === 2);

// Get elective courses (those with pick_count > 0)
$electiveCourses = $trajectoryService->getElectiveCourses($trajectoryId);
test('getElectiveCourses() returns 2 items', count($electiveCourses) === 2);

// Get courses by group
$coursesByGroup = $trajectoryService->getCoursesByGroup($trajectoryId);
$basisCourses = $coursesByGroup['Basismodules'] ?? [];
test('getCoursesByGroup(Basismodules) returns 2', count($basisCourses) === 2);

echo "\n";

// ========================================
// 9. PROGRESS ENGINE
// ========================================

echo "9. Testing ProgressEngine...\n";

// Enroll user in trajectory
$enrollResult = $progressEngine->enrollInTrajectory($testUser, $trajectoryId);
test('enrollInTrajectory() succeeds', !is_wp_error($enrollResult));

// Get progress (should be 0%)
$progress = $progressEngine->getProgress($trajectoryId, $testUser);
test('getProgress() returns data', !empty($progress) && isset($progress['percentage']));
test('Initial progress is 0%', $progress['percentage'] === 0.0);
test('Progress shows not complete', $progress['is_complete'] === false);

// Test meetsRequirements (should be false)
test('meetsRequirements() returns false initially', !$progressEngine->meetsRequirements($trajectoryId, $testUser));

// Simulate completing basis course 1
$completedCourseIds = [$courseIds[1]];
$progress = $progressEngine->getProgress($trajectoryId, $testUser, $completedCourseIds);
test('Progress with 1 basis course shows partial', $progress['percentage'] > 0 && $progress['percentage'] < 100);

// Complete both basis courses
$completedCourseIds = [$courseIds[1], $courseIds[2]];
$progress = $progressEngine->getProgress($trajectoryId, $testUser, $completedCourseIds);
test('Progress with 2 basis courses (without elective) is not complete', !$progress['is_complete']);

// Complete basis + 1 elective
$completedCourseIds = [$courseIds[1], $courseIds[2], $courseIds[3]];
$progress = $progressEngine->getProgress($trajectoryId, $testUser, $completedCourseIds);
test('Progress with 2 basis + 1 elective is complete', $progress['is_complete']);
test('meetsRequirements() returns true with all requirements', $progressEngine->meetsRequirements($trajectoryId, $testUser, $completedCourseIds));

// Get available electives - when elective requirement is met (1/1), none are "available" (needed)
$available = $progressEngine->getAvailableElectives($trajectoryId, $testUser, $completedCourseIds);
test('getAvailableElectives() returns 0 when elective requirement met', count($available) === 0);

// With 0 electives completed, should return 2 available
$availableNoElectives = $progressEngine->getAvailableElectives($trajectoryId, $testUser, [$courseIds[1], $courseIds[2]]);
test('getAvailableElectives() returns 2 when no electives completed', count($availableNoElectives) === 2);

// Get user trajectories
$userTrajectories = $progressEngine->getUserTrajectories($testUser);
test('getUserTrajectories() returns enrollment', count($userTrajectories) > 0);

echo "\n";

// ========================================
// 10. ENROLLMENT SERVICE INTEGRATION
// ========================================

echo "10. Testing EnrollmentService Integration...\n";

$enrollmentService = ntdst_get(EnrollmentService::class);

// Test trajectory methods exist
test('enrollInTrajectory() method exists', method_exists($enrollmentService, 'enrollInTrajectory'));
test('cancelTrajectoryEnrollment() method exists', method_exists($enrollmentService, 'cancelTrajectoryEnrollment'));
test('getUserTrajectories() method exists', method_exists($enrollmentService, 'getUserTrajectories'));
test('getTrajectoryProgress() method exists', method_exists($enrollmentService, 'getTrajectoryProgress'));

// Test session selection in enrollInEdition (check the docblock mentions session_ids)
$reflection = new ReflectionMethod($enrollmentService, 'enrollInEdition');
$docComment = $reflection->getDocComment();
test('enrollInEdition() doc mentions session_ids parameter', strpos($docComment, 'session_ids') !== false);

echo "\n";

// ========================================
// CLEANUP
// ========================================

echo "Cleaning Up Test Data...\n";

// Delete session selections
$sessionSelectionRepo->deleteForRegistration($regId);
echo "  - Deleted session selections\n";

// Delete registration
$regRepo->delete($regId);
echo "  - Deleted registration\n";

// Delete sessions
foreach ($sessionIds as $sid) {
    wp_delete_post($sid, true);
}
echo "  - Deleted sessions\n";

// Delete edition
wp_delete_post($editionId, true);
echo "  - Deleted edition\n";

// Delete trajectory enrollment
$trajectoryEnrollmentRepo->cancel($trajectoryId, $testUser);
echo "  - Cancelled trajectory enrollment\n";

// Delete trajectory
wp_delete_post($trajectoryId, true);
echo "  - Deleted trajectory\n";

// Delete courses
foreach ($courseIds as $cid) {
    wp_delete_post($cid, true);
}
echo "  - Deleted courses\n";

// Delete user
wp_delete_user($testUser);
echo "  - Deleted test user\n";

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
