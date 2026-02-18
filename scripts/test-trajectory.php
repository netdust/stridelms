<?php
/**
 * Stride LMS - Trajectory Service Tests
 *
 * Tests trajectory enrollment, elective choices, and deadline handling.
 *
 * Run with: ddev exec wp eval-file scripts/test-trajectory.php
 */

if (!defined('ABSPATH')) {
    echo "This script must be run via WP-CLI: ddev exec wp eval-file scripts/test-trajectory.php\n";
    exit(1);
}

use Stride\Modules\Trajectory\TrajectoryService;
use Stride\Modules\Trajectory\TrajectorySelectionService;
use Stride\Modules\Trajectory\TrajectoryEnrollmentRepository;
use Stride\Domain\TrajectoryStatus;
use Stride\Domain\TrajectoryMode;

class StrideTrajectoryServiceTest
{
    private TrajectoryService $trajectoryService;
    private TrajectorySelectionService $selectionService;
    private TrajectoryEnrollmentRepository $enrollmentRepo;

    private array $created = [
        'user_ids' => [],
        'trajectory_ids' => [],
        'edition_ids' => [],
        'course_ids' => [],
        'enrollment_ids' => [],
    ];

    private int $passed = 0;
    private int $failed = 0;

    public function __construct()
    {
        $this->trajectoryService = ntdst_get(TrajectoryService::class);
        $this->selectionService = ntdst_get(TrajectorySelectionService::class);
        $this->enrollmentRepo = ntdst_get(TrajectoryEnrollmentRepository::class);
    }

    public function run(): void
    {
        echo "=== Stride LMS Trajectory Service Tests ===\n\n";

        wp_set_current_user(1);

        try {
            $this->setupTestData();
            $this->testTrajectoryEnrollment();
            $this->testElectiveChoices();
            $this->testChoicesBeforeDeadline();
            $this->testChoicesAfterDeadline();
            $this->testTrajectoryProgress();
        } catch (Exception $e) {
            echo "\n[FATAL] " . $e->getMessage() . "\n";
            echo $e->getTraceAsString() . "\n";
        } finally {
            $this->cleanup();
        }

        echo "\n=== Test Results ===\n";
        echo "Passed: {$this->passed}\n";
        echo "Failed: {$this->failed}\n";
        echo ($this->failed === 0 ? "ALL TESTS PASSED!" : "SOME TESTS FAILED") . "\n";
    }

    private function assert(bool $condition, string $message): void
    {
        if ($condition) {
            echo "  [PASS] {$message}\n";
            $this->passed++;
        } else {
            echo "  [FAIL] {$message}\n";
            $this->failed++;
        }
    }

    private function setupTestData(): void
    {
        echo "0. Setting up test data...\n";

        // Create courses for trajectory
        $courseIds = [];
        for ($i = 1; $i <= 5; $i++) {
            $courseId = wp_insert_post([
                'post_type' => 'sfwd-courses',
                'post_title' => "Trajectory Course {$i} " . time(),
                'post_status' => 'publish',
                'post_author' => 1,
            ]);
            $courseIds[] = $courseId;
            $this->created['course_ids'][] = $courseId;
        }

        // Create editions for the courses
        $editionIds = [];
        foreach ($courseIds as $index => $courseId) {
            $editionId = wp_insert_post([
                'post_type' => 'vad_edition',
                'post_title' => "Edition for Course " . ($index + 1) . " " . time(),
                'post_status' => 'publish',
                'post_author' => 1,
            ]);
            update_post_meta($editionId, 'course_id', $courseId);
            update_post_meta($editionId, 'start_date', date('Y-m-d', strtotime('+' . (30 + $index * 7) . ' days')));
            update_post_meta($editionId, 'price', 200.00); // €200.00
            update_post_meta($editionId, 'capacity', 20);
            update_post_meta($editionId, 'status', 'open');
            $editionIds[] = $editionId;
            $this->created['edition_ids'][] = $editionId;
        }

        // Create trajectory with courses:
        // - 3 required editions (0, 1, 2)
        // - 2 elective options (3, 4) - pick 1
        $trajectoryId = wp_insert_post([
            'post_type' => 'vad_trajectory',
            'post_title' => 'Test Trajectory ' . time(),
            'post_status' => 'publish',
            'post_author' => 1,
        ]);

        $courses = [
            ['course_id' => $courseIds[0], 'edition_id' => $editionIds[0], 'required' => true, 'group' => 'required'],
            ['course_id' => $courseIds[1], 'edition_id' => $editionIds[1], 'required' => true, 'group' => 'required'],
            ['course_id' => $courseIds[2], 'edition_id' => $editionIds[2], 'required' => true, 'group' => 'required'],
            ['course_id' => $courseIds[3], 'edition_id' => $editionIds[3], 'required' => false, 'group' => 'electives', 'pick_count' => 1],
            ['course_id' => $courseIds[4], 'edition_id' => $editionIds[4], 'required' => false, 'group' => 'electives', 'pick_count' => 1],
        ];

        update_post_meta($trajectoryId, 'courses', json_encode($courses));
        update_post_meta($trajectoryId, 'mode', TrajectoryMode::Cohort->value);
        update_post_meta($trajectoryId, 'status', TrajectoryStatus::Open->value);
        update_post_meta($trajectoryId, 'capacity', 10);
        update_post_meta($trajectoryId, 'price', 800.00); // €800.00 for full trajectory
        update_post_meta($trajectoryId, 'choice_deadline', date('Y-m-d', strtotime('+14 days')));
        update_post_meta($trajectoryId, 'enrollment_deadline', date('Y-m-d', strtotime('+7 days')));

        $this->created['trajectory_ids']['open'] = $trajectoryId;

        // Create trajectory with passed deadline
        $closedTrajectoryId = wp_insert_post([
            'post_type' => 'vad_trajectory',
            'post_title' => 'Closed Trajectory ' . time(),
            'post_status' => 'publish',
            'post_author' => 1,
        ]);

        update_post_meta($closedTrajectoryId, 'courses', json_encode($courses));
        update_post_meta($closedTrajectoryId, 'mode', TrajectoryMode::Cohort->value);
        update_post_meta($closedTrajectoryId, 'status', TrajectoryStatus::Open->value);
        update_post_meta($closedTrajectoryId, 'capacity', 10);
        update_post_meta($closedTrajectoryId, 'choice_deadline', date('Y-m-d', strtotime('-1 day'))); // Yesterday

        $this->created['trajectory_ids']['closed'] = $closedTrajectoryId;

        echo "  - Created 5 courses with editions\n";
        echo "  - Created trajectory with 3 required + 2 elective options\n";
        echo "  - Created closed trajectory (deadline passed)\n\n";
    }

    // ========================================
    // Test 6.1: Trajectory Enrollment
    // ========================================

    private function testTrajectoryEnrollment(): void
    {
        echo "6.1. Testing Trajectory Enrollment...\n";

        $userId = $this->createTestUser('trajectory_enroll_' . time());
        $this->created['user_ids'][] = $userId;
        $trajectoryId = $this->created['trajectory_ids']['open'];

        // Enroll in trajectory
        $enrollmentId = $this->selectionService->enroll($userId, $trajectoryId);

        $this->assert(
            !is_wp_error($enrollmentId),
            "enroll() returns enrollment ID"
        );

        if (!is_wp_error($enrollmentId)) {
            $this->created['enrollment_ids'][] = $enrollmentId;

            // Verify enrollment record
            $enrollment = $this->enrollmentRepo->find($enrollmentId);

            $this->assert(
                $enrollment !== null,
                "Enrollment record exists"
            );

            $this->assert(
                ($enrollment['status'] ?? '') === 'enrolled',
                "Enrollment status is 'enrolled'"
            );

            $this->assert(
                (int)($enrollment['trajectory_id'] ?? 0) === $trajectoryId,
                "Enrollment has correct trajectory_id"
            );
        }

        echo "\n";
    }

    // ========================================
    // Test 6.2: Elective Choices
    // ========================================

    private function testElectiveChoices(): void
    {
        echo "6.2. Testing Elective Choices...\n";

        $userId = $this->createTestUser('trajectory_choices_' . time());
        $this->created['user_ids'][] = $userId;
        $trajectoryId = $this->created['trajectory_ids']['open'];

        // Enroll first
        $enrollmentId = $this->selectionService->enroll($userId, $trajectoryId);

        if (is_wp_error($enrollmentId)) {
            echo "  [FAIL] Could not create enrollment for test\n";
            $this->failed++;
            return;
        }

        $this->created['enrollment_ids'][] = $enrollmentId;

        // Set elective choices - pick course 4 (index 3)
        $electiveCourseId = $this->created['course_ids'][3];
        $choices = [
            'electives' => [$electiveCourseId],
        ];

        $result = $this->selectionService->setElectiveChoices($enrollmentId, $choices);

        $this->assert(
            !is_wp_error($result),
            "setElectiveChoices() succeeds"
        );

        // Verify choices stored
        $storedChoices = $this->selectionService->getElectiveChoices($enrollmentId);

        $this->assert(
            isset($storedChoices['electives']) && in_array($electiveCourseId, $storedChoices['electives']),
            "Elective choices stored correctly"
        );

        echo "\n";
    }

    // ========================================
    // Test 6.3: Choices Before Deadline
    // ========================================

    private function testChoicesBeforeDeadline(): void
    {
        echo "6.3. Testing Choices Before Deadline...\n";

        $userId = $this->createTestUser('trajectory_before_' . time());
        $this->created['user_ids'][] = $userId;
        $trajectoryId = $this->created['trajectory_ids']['open'];

        // Enroll
        $enrollmentId = $this->selectionService->enroll($userId, $trajectoryId);

        if (is_wp_error($enrollmentId)) {
            echo "  [FAIL] Could not create enrollment for test\n";
            $this->failed++;
            return;
        }

        $this->created['enrollment_ids'][] = $enrollmentId;

        // Check if choices are locked
        $areLocked = $this->selectionService->areChoicesLocked($enrollmentId);

        $this->assert(
            $areLocked === false,
            "areChoicesLocked() returns false (deadline is future)"
        );

        // Should be able to update choices
        $electiveCourseId = $this->created['course_ids'][4]; // Pick different elective
        $choices = ['electives' => [$electiveCourseId]];

        $updateResult = $this->selectionService->setElectiveChoices($enrollmentId, $choices);

        $this->assert(
            !is_wp_error($updateResult),
            "Can update choices before deadline"
        );

        echo "\n";
    }

    // ========================================
    // Test 6.4: Choices After Deadline
    // ========================================

    private function testChoicesAfterDeadline(): void
    {
        echo "6.4. Testing Choices After Deadline...\n";

        $userId = $this->createTestUser('trajectory_after_' . time());
        $this->created['user_ids'][] = $userId;
        $trajectoryId = $this->created['trajectory_ids']['closed']; // Deadline passed

        // Manually create enrollment (bypassing enrollment deadline check)
        global $wpdb;
        $table = $wpdb->prefix . 'vad_trajectory_enrollments';

        $wpdb->insert($table, [
            'user_id' => $userId,
            'trajectory_id' => $trajectoryId,
            'status' => 'enrolled',
            'enrolled_at' => current_time('mysql'),
        ]);

        $enrollmentId = (int) $wpdb->insert_id;
        $this->created['enrollment_ids'][] = $enrollmentId;

        // Check if choices are locked
        $areLocked = $this->selectionService->areChoicesLocked($enrollmentId);

        $this->assert(
            $areLocked === true,
            "areChoicesLocked() returns true (deadline passed)"
        );

        // Attempt to update choices should fail
        $electiveCourseId = $this->created['course_ids'][3];
        $choices = ['electives' => [$electiveCourseId]];

        $updateResult = $this->selectionService->setElectiveChoices($enrollmentId, $choices);

        $this->assert(
            is_wp_error($updateResult),
            "Cannot update choices after deadline"
        );

        if (is_wp_error($updateResult)) {
            $this->assert(
                $updateResult->get_error_code() === 'choice_window_closed',
                "Error code is 'choice_window_closed' (got: " . $updateResult->get_error_code() . ")"
            );
        }

        echo "\n";
    }

    // ========================================
    // Test 6.5: Trajectory Progress
    // ========================================

    private function testTrajectoryProgress(): void
    {
        echo "6.5. Testing Trajectory Progress...\n";

        $userId = $this->createTestUser('trajectory_progress_' . time());
        $this->created['user_ids'][] = $userId;
        $trajectoryId = $this->created['trajectory_ids']['open'];

        // Enroll
        $enrollmentId = $this->selectionService->enroll($userId, $trajectoryId);

        if (is_wp_error($enrollmentId)) {
            echo "  [FAIL] Could not create enrollment for test\n";
            $this->failed++;
            return;
        }

        $this->created['enrollment_ids'][] = $enrollmentId;

        // Get enrollment with details
        $enrollment = $this->selectionService->getEnrollment($enrollmentId);

        $this->assert(
            $enrollment !== null,
            "getEnrollment() returns enrollment data"
        );

        if ($enrollment) {
            $this->assert(
                isset($enrollment['trajectory']) && is_array($enrollment['trajectory']),
                "Enrollment includes trajectory details"
            );

            $this->assert(
                isset($enrollment['status']) && $enrollment['status'] === 'enrolled',
                "Enrollment status is 'enrolled'"
            );

            $this->assert(
                isset($enrollment['choices_locked']) && $enrollment['choices_locked'] === false,
                "choices_locked is false (deadline not passed)"
            );

            // Check trajectory has course info
            $trajectory = $enrollment['trajectory'] ?? [];
            $this->assert(
                isset($trajectory['courses']) && is_array($trajectory['courses']),
                "Trajectory includes course configuration"
            );
        }

        echo "\n";
    }

    // ========================================
    // HELPERS
    // ========================================

    private function createTestUser(string $username): int
    {
        $email = $username . '@test.local';
        $userId = wp_create_user($username, 'testpass123', $email);

        if (!is_wp_error($userId)) {
            update_user_meta($userId, 'first_name', 'Test');
            update_user_meta($userId, 'last_name', 'User');
            update_user_meta($userId, '_stride_test_trajectory', true);
        }

        return is_wp_error($userId) ? 0 : $userId;
    }

    private function cleanup(): void
    {
        echo "Cleaning Up Test Data...\n";

        global $wpdb;

        // Delete trajectory enrollments
        $enrollmentTable = $wpdb->prefix . 'vad_trajectory_enrollments';
        foreach ($this->created['enrollment_ids'] as $enrollmentId) {
            $wpdb->delete($enrollmentTable, ['id' => $enrollmentId], ['%d']);
        }
        echo "  - Deleted " . count($this->created['enrollment_ids']) . " trajectory enrollments\n";

        // Delete trajectories
        foreach ($this->created['trajectory_ids'] as $trajectoryId) {
            wp_delete_post($trajectoryId, true);
        }
        echo "  - Deleted " . count($this->created['trajectory_ids']) . " trajectories\n";

        // Delete editions
        foreach ($this->created['edition_ids'] as $editionId) {
            wp_delete_post($editionId, true);
        }
        echo "  - Deleted " . count($this->created['edition_ids']) . " editions\n";

        // Delete courses
        foreach ($this->created['course_ids'] as $courseId) {
            wp_delete_post($courseId, true);
        }
        echo "  - Deleted " . count($this->created['course_ids']) . " courses\n";

        // Delete users
        require_once ABSPATH . 'wp-admin/includes/user.php';
        foreach ($this->created['user_ids'] as $userId) {
            if ($userId) {
                wp_delete_user($userId);
            }
        }
        echo "  - Deleted " . count($this->created['user_ids']) . " users\n";

        echo "  Cleanup complete.\n";
    }
}

// Run the test
$test = new StrideTrajectoryServiceTest();
$test->run();
