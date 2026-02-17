<?php
/**
 * Stride V1 - Trajectory Enrollment Tests
 *
 * Tests trajectory CRUD, enrollment, and elective selection.
 *
 * Run with: ddev exec wp eval-file scripts/test-trajectory-enrollment.php
 */

if (!defined('ABSPATH')) {
    echo "Run via WP-CLI: ddev exec wp eval-file scripts/test-trajectory-enrollment.php\n";
    exit(1);
}

use Stride\Modules\Trajectory\TrajectoryService;
use Stride\Modules\Trajectory\TrajectoryRepository;
use Stride\Modules\Trajectory\TrajectorySelectionService;
use Stride\Modules\Trajectory\TrajectoryEnrollmentRepository;
use Stride\Domain\TrajectoryMode;
use Stride\Domain\TrajectoryStatus;

echo "=== Stride V1 - Trajectory Enrollment Tests ===" . PHP_EOL . PHP_EOL;

$trajectoryService = ntdst_get(TrajectoryService::class);
$trajectoryRepo = ntdst_get(TrajectoryRepository::class);
$selectionService = ntdst_get(TrajectorySelectionService::class);
$enrollmentRepo = ntdst_get(TrajectoryEnrollmentRepository::class);

$created = ['trajectories' => [], 'users' => [], 'enrollments' => []];
$GLOBALS['passed'] = 0;
$GLOBALS['failed'] = 0;

function assert_test(bool $condition, string $message): void {
    if ($condition) {
        echo "  [PASS] {$message}" . PHP_EOL;
        $GLOBALS['passed']++;
    } else {
        echo "  [FAIL] {$message}" . PHP_EOL;
        $GLOBALS['failed']++;
    }
}

wp_set_current_user(1);

try {
    // === A. TRAJECTORY CRUD ===
    echo "A. Trajectory CRUD..." . PHP_EOL;

    // A1. Create trajectory
    $trajectoryId = $trajectoryService->createTrajectory([
        'title' => 'Test Trajectory',
        'mode' => TrajectoryMode::Cohort->value,
        'status' => TrajectoryStatus::Open->value,
        'capacity' => 20,
        'enrollment_deadline' => date('Y-m-d', strtotime('+30 days')),
        'choice_available_date' => date('Y-m-d', strtotime('+1 day')),
        'choice_deadline' => date('Y-m-d', strtotime('+14 days')),
        'courses' => [
            ['course_id' => 101, 'group' => 'Basis', 'required' => true],
            ['course_id' => 102, 'group' => 'Basis', 'required' => true],
            ['course_id' => 201, 'group' => 'Keuze', 'required' => false, 'pick_count' => 1],
            ['course_id' => 202, 'group' => 'Keuze', 'required' => false, 'pick_count' => 1],
            ['course_id' => 203, 'group' => 'Keuze', 'required' => false, 'pick_count' => 1],
        ],
    ]);
    assert_test(!is_wp_error($trajectoryId), 'A1. Create trajectory');
    $created['trajectories'][] = $trajectoryId;

    // A2. Get trajectory
    $trajectory = $trajectoryService->getTrajectory($trajectoryId);
    assert_test($trajectory !== null && $trajectory['title'] === 'Test Trajectory', 'A2. Get trajectory');

    // A3. Check mode
    assert_test($trajectory['mode_enum'] === TrajectoryMode::Cohort, 'A3. Mode is cohort');

    // A4. Check status
    assert_test($trajectory['status_enum'] === TrajectoryStatus::Open, 'A4. Status is open');

    // A5. Get courses
    $courses = $trajectoryService->getCourses($trajectoryId);
    assert_test(count($courses) === 5, 'A5. Has 5 courses');

    // A6. Get required courses
    $required = $trajectoryService->getRequiredCourses($trajectoryId);
    assert_test(count($required) === 2, 'A6. Has 2 required courses');

    // A7. Get elective groups
    $groups = $trajectoryRepo->getElectiveGroups($trajectoryId);
    assert_test(isset($groups['Keuze']) && count($groups['Keuze']) === 3, 'A7. Has Keuze group with 3 electives');

    echo PHP_EOL;

    // === B. ENROLLMENT ===
    echo "B. Enrollment..." . PHP_EOL;

    // Create test user
    $userId = wp_create_user('traj_test_' . time(), 'pass123', 'traj@test.local');
    $created['users'][] = $userId;

    // B1. Enrollment is open
    $isOpen = $trajectoryService->isEnrollmentOpen($trajectoryId);
    assert_test($isOpen, 'B1. Enrollment is open');

    // B2. Has capacity
    $hasCapacity = $selectionService->hasCapacity($trajectoryId);
    assert_test($hasCapacity, 'B2. Has capacity');

    // B3. Enroll user
    $enrollmentId = $selectionService->enroll($userId, $trajectoryId);
    assert_test(!is_wp_error($enrollmentId), 'B3. Enroll user succeeds');
    $created['enrollments'][] = $enrollmentId;

    // B4. Is enrolled
    $isEnrolled = $enrollmentRepo->isEnrolled($userId, $trajectoryId);
    assert_test($isEnrolled, 'B4. User is enrolled');

    // B5. Double enrollment fails
    $result = $selectionService->enroll($userId, $trajectoryId);
    assert_test(is_wp_error($result) && $result->get_error_code() === 'already_enrolled', 'B5. Double enrollment rejected');

    // B6. Get enrollment
    $enrollment = $selectionService->getEnrollment($enrollmentId);
    assert_test($enrollment !== null && $enrollment['user_id'] === $userId, 'B6. Get enrollment');

    echo PHP_EOL;

    // === C. ELECTIVE SELECTION ===
    echo "C. Elective Selection..." . PHP_EOL;

    // Make choice window open by setting dates
    $trajectoryService->updateTrajectory($trajectoryId, [
        'choice_available_date' => date('Y-m-d', strtotime('-1 day')),
        'choice_deadline' => date('Y-m-d', strtotime('+14 days')),
    ]);

    // C1. Choice window is open
    $isWindowOpen = $trajectoryService->isChoiceWindowOpen($trajectoryId);
    assert_test($isWindowOpen, 'C1. Choice window is open');

    // C2. Set valid choices
    $result = $selectionService->setElectiveChoices($enrollmentId, [
        'Keuze' => [201],
    ]);
    assert_test($result === true, 'C2. Set valid choices succeeds');

    // C3. Get choices
    $choices = $selectionService->getElectiveChoices($enrollmentId);
    assert_test(isset($choices['Keuze']) && in_array(201, $choices['Keuze']), 'C3. Get choices');

    // C4. Update choices
    $result = $selectionService->setElectiveChoices($enrollmentId, [
        'Keuze' => [202],
    ]);
    assert_test($result === true, 'C4. Update choices succeeds');

    // C5. Verify updated
    $choices = $selectionService->getElectiveChoices($enrollmentId);
    assert_test(in_array(202, $choices['Keuze']), 'C5. Choices updated');

    // C6. Too few choices fails
    $result = $selectionService->setElectiveChoices($enrollmentId, [
        'Keuze' => [],
    ]);
    assert_test(is_wp_error($result) && $result->get_error_code() === 'incomplete_choices', 'C6. Too few choices rejected');

    // C7. Too many choices fails
    $result = $selectionService->setElectiveChoices($enrollmentId, [
        'Keuze' => [201, 202],
    ]);
    assert_test(is_wp_error($result) && $result->get_error_code() === 'too_many_choices', 'C7. Too many choices rejected');

    echo PHP_EOL;

    // === D. DEADLINE ENFORCEMENT ===
    echo "D. Deadline Enforcement..." . PHP_EOL;

    // D1. Days until deadline
    $days = $selectionService->getDaysUntilChoiceDeadline($trajectoryId);
    assert_test($days >= 13 && $days <= 14, 'D1. Days until deadline correct');

    // D2. Choices not locked
    $isLocked = $selectionService->areChoicesLocked($enrollmentId);
    assert_test(!$isLocked, 'D2. Choices not locked');

    // D3. Set past deadline
    $trajectoryService->updateTrajectory($trajectoryId, [
        'choice_deadline' => date('Y-m-d', strtotime('-1 day')),
    ]);
    $isLocked = $trajectoryService->areChoicesLocked($trajectoryId);
    assert_test($isLocked, 'D3. Choices locked after deadline');

    // D4. Selection blocked after deadline
    $result = $selectionService->setElectiveChoices($enrollmentId, [
        'Keuze' => [203],
    ]);
    assert_test(is_wp_error($result) && $result->get_error_code() === 'choice_window_closed', 'D4. Selection blocked after deadline');

    echo PHP_EOL;

    // === E. CAPACITY ===
    echo "E. Capacity..." . PHP_EOL;

    // Create capacity-limited trajectory
    $limitedId = $trajectoryService->createTrajectory([
        'title' => 'Limited Trajectory',
        'mode' => TrajectoryMode::Cohort->value,
        'status' => TrajectoryStatus::Open->value,
        'capacity' => 1,
        'courses' => [
            ['course_id' => 101, 'group' => 'Basis', 'required' => true],
        ],
    ]);
    $created['trajectories'][] = $limitedId;

    // E1. Has capacity initially
    $hasCapacity = $selectionService->hasCapacity($limitedId);
    assert_test($hasCapacity, 'E1. Has capacity initially');

    // E2. Enroll fills capacity
    $enrollmentId2 = $selectionService->enroll($userId, $limitedId);
    $created['enrollments'][] = $enrollmentId2;
    $hasCapacity = $selectionService->hasCapacity($limitedId);
    assert_test(!$hasCapacity, 'E2. No capacity after enrollment');

    // E3. Second user blocked
    $userId2 = wp_create_user('traj_test2_' . time(), 'pass123', 'traj2@test.local');
    $created['users'][] = $userId2;

    $result = $selectionService->enroll($userId2, $limitedId);
    assert_test(is_wp_error($result) && $result->get_error_code() === 'no_capacity', 'E3. Full trajectory rejects enrollment');

    echo PHP_EOL;

} catch (Exception $e) {
    echo "[FATAL] " . $e->getMessage() . PHP_EOL;
    echo $e->getTraceAsString() . PHP_EOL;
}

// Cleanup
echo "Cleaning up..." . PHP_EOL;

foreach ($created['enrollments'] as $id) {
    global $wpdb;
    $wpdb->delete($wpdb->prefix . 'vad_trajectory_enrollments', ['id' => $id]);
}
foreach ($created['trajectories'] as $id) {
    wp_delete_post($id, true);
}
require_once ABSPATH . 'wp-admin/includes/user.php';
foreach ($created['users'] as $id) {
    wp_delete_user($id);
}

$passed = $GLOBALS['passed'];
$failed = $GLOBALS['failed'];

echo PHP_EOL . "=== Results ===" . PHP_EOL;
echo "Passed: {$passed}" . PHP_EOL;
echo "Failed: {$failed}" . PHP_EOL;
echo ($failed === 0 ? "ALL TESTS PASSED!" : "SOME TESTS FAILED") . PHP_EOL;
