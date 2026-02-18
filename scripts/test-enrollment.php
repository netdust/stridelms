<?php
/**
 * Stride LMS - Enrollment Service Tests
 *
 * Tests enrollment creation, cancellation, and access management.
 *
 * Run with: ddev exec wp eval-file scripts/test-enrollment.php
 */

if (!defined('ABSPATH')) {
    echo "This script must be run via WP-CLI: ddev exec wp eval-file scripts/test-enrollment.php\n";
    exit(1);
}

use Stride\Modules\Enrollment\EnrollmentService;
use Stride\Modules\Enrollment\RegistrationRepository;
use Stride\Modules\Edition\EditionService;
use Stride\Modules\Edition\EditionRepository;
use Stride\Domain\RegistrationStatus;
use Stride\Domain\EditionStatus;

class StrideEnrollmentServiceTest
{
    private EnrollmentService $enrollmentService;
    private RegistrationRepository $registrationRepo;
    private EditionService $editionService;
    private EditionRepository $editionRepository;

    private array $created = [
        'user_ids' => [],
        'edition_ids' => [],
        'course_ids' => [],
        'registration_ids' => [],
    ];

    private int $passed = 0;
    private int $failed = 0;

    public function __construct()
    {
        $this->enrollmentService = ntdst_get(EnrollmentService::class);
        $this->registrationRepo = ntdst_get(RegistrationRepository::class);
        $this->editionService = ntdst_get(EditionService::class);
        $this->editionRepository = ntdst_get(EditionRepository::class);
    }

    public function run(): void
    {
        echo "=== Stride LMS Enrollment Service Tests ===\n\n";

        wp_set_current_user(1);

        try {
            $this->setupTestData();
            $this->testBasicEnrollment();
            $this->testEnrollmentWithQuote();
            $this->testEnrollmentWithoutQuote();
            $this->testDuplicateEnrollmentPrevention();
            $this->testCancellation();
            $this->testCapacityHandling();
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

        // Create a LearnDash course
        $courseId = wp_insert_post([
            'post_type' => 'sfwd-courses',
            'post_title' => 'Enrollment Test Course ' . time(),
            'post_status' => 'publish',
            'post_author' => 1,
        ]);
        $this->created['course_ids'][] = $courseId;

        // Create standard open edition
        $openEditionId = wp_insert_post([
            'post_type' => 'vad_edition',
            'post_title' => 'Open Edition ' . time(),
            'post_status' => 'publish',
            'post_author' => 1,
        ]);
        update_post_meta($openEditionId, 'course_id', $courseId);
        update_post_meta($openEditionId, 'start_date', date('Y-m-d', strtotime('+30 days')));
        update_post_meta($openEditionId, 'end_date', date('Y-m-d', strtotime('+31 days')));
        update_post_meta($openEditionId, 'price', 300.00); // €300.00
        update_post_meta($openEditionId, 'capacity', 20);
        update_post_meta($openEditionId, 'status', 'open');
        $this->created['edition_ids']['open'] = $openEditionId;

        // Create full edition (capacity 1)
        $fullEditionId = wp_insert_post([
            'post_type' => 'vad_edition',
            'post_title' => 'Full Edition ' . time(),
            'post_status' => 'publish',
            'post_author' => 1,
        ]);
        update_post_meta($fullEditionId, 'course_id', $courseId);
        update_post_meta($fullEditionId, 'start_date', date('Y-m-d', strtotime('+30 days')));
        update_post_meta($fullEditionId, 'end_date', date('Y-m-d', strtotime('+31 days')));
        update_post_meta($fullEditionId, 'price', 300.00); // €300.00
        update_post_meta($fullEditionId, 'capacity', 1);
        update_post_meta($fullEditionId, 'status', 'open');
        $this->created['edition_ids']['full'] = $fullEditionId;

        echo "  - Created course {$courseId} and editions\n\n";
    }

    // ========================================
    // Test 1.1: Basic Enrollment
    // ========================================

    private function testBasicEnrollment(): void
    {
        echo "1.1. Testing Basic Enrollment...\n";

        $userId = $this->createTestUser('basic_enroll_' . time());
        $this->created['user_ids'][] = $userId;
        $editionId = $this->created['edition_ids']['open'];

        // Enroll user
        $result = $this->enrollmentService->enroll($userId, $editionId);

        $this->assert(
            !is_wp_error($result),
            "Enrollment returns registration ID (got: " . (is_wp_error($result) ? $result->get_error_message() : $result) . ")"
        );

        if (!is_wp_error($result)) {
            $this->created['registration_ids'][] = $result;

            // Get registration and verify status
            $registration = $this->enrollmentService->getRegistration($result);
            $this->assert(
                !is_wp_error($registration) && $registration->status === RegistrationStatus::Confirmed->value,
                "Registration has status 'confirmed' (got: " . ($registration->status ?? 'error') . ")"
            );

            // Check isEnrolled returns true
            $isEnrolled = $this->enrollmentService->isEnrolled($userId, $editionId);
            $this->assert(
                $isEnrolled === true,
                "isEnrolled() returns true"
            );

            // Check LearnDash access granted
            $courseId = $this->created['course_ids'][0];
            if (function_exists('sfwd_lms_has_access')) {
                $hasAccess = sfwd_lms_has_access($courseId, $userId);
                $this->assert(
                    $hasAccess === true,
                    "LearnDash access granted"
                );
            } else {
                echo "  [SKIP] LearnDash not available for access check\n";
            }
        }

        echo "\n";
    }

    // ========================================
    // Test 1.2: Enrollment with Quote (Individual Path)
    // ========================================

    private function testEnrollmentWithQuote(): void
    {
        echo "1.2. Testing Enrollment with Quote (Individual Path)...\n";

        $userId = $this->createTestUser('quote_enroll_' . time());
        $this->created['user_ids'][] = $userId;
        $editionId = $this->created['edition_ids']['open'];

        // Enroll with individual path (should trigger quote creation)
        $result = $this->enrollmentService->enroll($userId, $editionId, [
            'enrollment_path' => RegistrationRepository::PATH_INDIVIDUAL,
        ]);

        $this->assert(
            !is_wp_error($result),
            "Enrollment with individual path succeeds"
        );

        if (!is_wp_error($result)) {
            $this->created['registration_ids'][] = $result;

            // Get registration and check quote
            $registration = $this->registrationRepo->find($result);

            if (!is_wp_error($registration)) {
                // Note: Quote creation happens via event handler (EnrollmentQuoteHandler)
                // The quote_id will be set if the handler is active
                echo "  [INFO] Registration quote_id: " . ($registration->quote_id ?? 'null') . "\n";

                // Verify enrollment path is stored
                $this->assert(
                    $registration->enrollment_path === RegistrationRepository::PATH_INDIVIDUAL,
                    "Enrollment path stored as 'individual'"
                );
            }
        }

        echo "\n";
    }

    // ========================================
    // Test 1.3: Enrollment Without Quote (Trajectory Path)
    // ========================================

    private function testEnrollmentWithoutQuote(): void
    {
        echo "1.3. Testing Enrollment Without Quote (Trajectory Path)...\n";

        $userId = $this->createTestUser('trajectory_enroll_' . time());
        $this->created['user_ids'][] = $userId;
        $editionId = $this->created['edition_ids']['open'];

        // Enroll with trajectory path (should not create individual quote)
        $result = $this->enrollmentService->enroll($userId, $editionId, [
            'enrollment_path' => RegistrationRepository::PATH_TRAJECTORY,
        ]);

        $this->assert(
            !is_wp_error($result),
            "Enrollment with trajectory path succeeds"
        );

        if (!is_wp_error($result)) {
            $this->created['registration_ids'][] = $result;

            $registration = $this->registrationRepo->find($result);

            if (!is_wp_error($registration)) {
                $this->assert(
                    $registration->enrollment_path === RegistrationRepository::PATH_TRAJECTORY,
                    "Enrollment path stored as 'trajectory'"
                );

                // Note: Trajectory enrollments should not auto-create quotes
                // Quote logic is handled by trajectory pricing
                echo "  [INFO] Registration quote_id: " . ($registration->quote_id ?? 'null') . "\n";
            }
        }

        echo "\n";
    }

    // ========================================
    // Test 1.4: Duplicate Enrollment Prevention
    // ========================================

    private function testDuplicateEnrollmentPrevention(): void
    {
        echo "1.4. Testing Duplicate Enrollment Prevention...\n";

        $userId = $this->createTestUser('duplicate_enroll_' . time());
        $this->created['user_ids'][] = $userId;
        $editionId = $this->created['edition_ids']['open'];

        // First enrollment
        $result1 = $this->enrollmentService->enroll($userId, $editionId);

        $this->assert(
            !is_wp_error($result1),
            "First enrollment succeeds"
        );

        if (!is_wp_error($result1)) {
            $this->created['registration_ids'][] = $result1;
        }

        // Second enrollment attempt
        $result2 = $this->enrollmentService->enroll($userId, $editionId);

        $this->assert(
            is_wp_error($result2),
            "Second enrollment returns error"
        );

        if (is_wp_error($result2)) {
            $this->assert(
                $result2->get_error_code() === 'already_enrolled',
                "Error code is 'already_enrolled' (got: " . $result2->get_error_code() . ")"
            );
        }

        echo "\n";
    }

    // ========================================
    // Test 1.5: Cancellation
    // ========================================

    private function testCancellation(): void
    {
        echo "1.5. Testing Cancellation...\n";

        $userId = $this->createTestUser('cancel_enroll_' . time());
        $this->created['user_ids'][] = $userId;
        $editionId = $this->created['edition_ids']['open'];

        // First, enroll the user
        $registrationId = $this->enrollmentService->enroll($userId, $editionId);

        if (is_wp_error($registrationId)) {
            echo "  [FAIL] Could not create enrollment for test\n";
            $this->failed++;
            return;
        }

        $this->created['registration_ids'][] = $registrationId;

        // Verify enrolled
        $this->assert(
            $this->enrollmentService->isEnrolled($userId, $editionId),
            "User is enrolled before cancellation"
        );

        // Cancel the enrollment
        $cancelResult = $this->enrollmentService->cancel($registrationId);

        $this->assert(
            !is_wp_error($cancelResult) && $cancelResult === true,
            "Cancellation succeeds"
        );

        // Verify status changed
        $registration = $this->registrationRepo->find($registrationId);

        if (!is_wp_error($registration)) {
            $this->assert(
                $registration->status === RegistrationStatus::Cancelled->value,
                "Status changed to 'cancelled'"
            );

            $this->assert(
                !empty($registration->cancelled_at),
                "cancelled_at timestamp is set"
            );
        }

        // Verify isEnrolled returns false
        $this->assert(
            $this->enrollmentService->isEnrolled($userId, $editionId) === false,
            "isEnrolled() returns false after cancellation"
        );

        // Check LearnDash access revoked
        $courseId = $this->created['course_ids'][0];
        if (function_exists('sfwd_lms_has_access')) {
            $hasAccess = sfwd_lms_has_access($courseId, $userId);
            $this->assert(
                $hasAccess === false,
                "LearnDash access revoked"
            );
        }

        echo "\n";
    }

    // ========================================
    // Test 1.6: Capacity Handling
    // ========================================

    private function testCapacityHandling(): void
    {
        echo "1.6. Testing Capacity Handling...\n";

        $fullEditionId = $this->created['edition_ids']['full'];

        // First user should succeed
        $user1 = $this->createTestUser('capacity_user1_' . time());
        $this->created['user_ids'][] = $user1;

        $result1 = $this->enrollmentService->enroll($user1, $fullEditionId);

        $this->assert(
            !is_wp_error($result1),
            "First user can enroll (capacity=1)"
        );

        if (!is_wp_error($result1)) {
            $this->created['registration_ids'][] = $result1;
        }

        // Second user should fail (edition full)
        $user2 = $this->createTestUser('capacity_user2_' . time());
        $this->created['user_ids'][] = $user2;

        $result2 = $this->enrollmentService->enroll($user2, $fullEditionId);

        $this->assert(
            is_wp_error($result2),
            "Second user cannot enroll (edition full)"
        );

        if (is_wp_error($result2)) {
            $this->assert(
                $result2->get_error_code() === 'edition_full',
                "Error code is 'edition_full' (got: " . $result2->get_error_code() . ")"
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
            update_user_meta($userId, '_stride_test_enrollment', true);
        }

        return is_wp_error($userId) ? 0 : $userId;
    }

    private function cleanup(): void
    {
        echo "Cleaning Up Test Data...\n";

        // Delete registrations
        global $wpdb;
        $table = $wpdb->prefix . 'vad_registrations';
        foreach ($this->created['registration_ids'] as $regId) {
            $wpdb->delete($table, ['id' => $regId], ['%d']);
        }
        echo "  - Deleted " . count($this->created['registration_ids']) . " registrations\n";

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
$test = new StrideEnrollmentServiceTest();
$test->run();
