<?php
/**
 * Stride LMS - Integration Tests (E2E)
 *
 * Tests complete end-to-end workflows combining multiple services.
 *
 * Run with: ddev exec wp eval-file scripts/test-integration.php
 */

if (!defined('ABSPATH')) {
    echo "This script must be run via WP-CLI: ddev exec wp eval-file scripts/test-integration.php\n";
    exit(1);
}

use Stride\Modules\Enrollment\EnrollmentService;
use Stride\Modules\Enrollment\RegistrationRepository;
use Stride\Modules\Invoicing\QuoteService;
use Stride\Modules\Invoicing\VoucherService;
use Stride\Modules\Attendance\AttendanceService;
use Stride\Modules\Completion\CompletionService;
use Stride\Modules\Trajectory\TrajectoryService;
use Stride\Modules\Trajectory\TrajectorySelectionService;
use Stride\Modules\Edition\SessionService;
use Stride\Domain\Money;
use Stride\Domain\CompletionMode;
use Stride\Domain\DiscountType;
use Stride\Domain\TrajectoryMode;
use Stride\Domain\TrajectoryStatus;

class StrideIntegrationTest
{
    private EnrollmentService $enrollmentService;
    private RegistrationRepository $registrationRepo;
    private QuoteService $quoteService;
    private VoucherService $voucherService;
    private AttendanceService $attendanceService;
    private CompletionService $completionService;
    private TrajectoryService $trajectoryService;
    private TrajectorySelectionService $trajectorySelectionService;
    private SessionService $sessionService;

    private array $created = [
        'user_ids' => [],
        'edition_ids' => [],
        'session_ids' => [],
        'course_ids' => [],
        'registration_ids' => [],
        'quote_ids' => [],
        'voucher_ids' => [],
        'trajectory_ids' => [],
        'trajectory_enrollment_ids' => [],
    ];

    private int $passed = 0;
    private int $failed = 0;

    public function __construct()
    {
        $this->enrollmentService = ntdst_get(EnrollmentService::class);
        $this->registrationRepo = ntdst_get(RegistrationRepository::class);
        $this->quoteService = ntdst_get(QuoteService::class);
        $this->voucherService = ntdst_get(VoucherService::class);
        $this->attendanceService = ntdst_get(AttendanceService::class);
        $this->completionService = ntdst_get(CompletionService::class);
        $this->trajectoryService = ntdst_get(TrajectoryService::class);
        $this->trajectorySelectionService = ntdst_get(TrajectorySelectionService::class);
        $this->sessionService = ntdst_get(SessionService::class);
    }

    public function run(): void
    {
        echo "=== Stride LMS Integration Tests (E2E) ===\n\n";

        wp_set_current_user(1);

        try {
            $this->testFullIndividualEnrollmentFlow();
            $this->testFullTrajectoryEnrollmentFlow();
            $this->testColleagueEnrollmentFlow();
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

    // ========================================
    // Test 8.1: Full Individual Enrollment Flow
    // ========================================

    private function testFullIndividualEnrollmentFlow(): void
    {
        echo "8.1. Testing Full Individual Enrollment Flow...\n";
        echo "  Flow: User registers -> Quote auto-created -> Apply voucher -> Mark sessions attended -> Complete\n\n";

        // === SETUP ===
        // Create course
        $courseId = wp_insert_post([
            'post_type' => 'sfwd-courses',
            'post_title' => 'Integration Test Course ' . time(),
            'post_status' => 'publish',
            'post_author' => 1,
        ]);
        $this->created['course_ids'][] = $courseId;

        // Create edition with price and sessions
        $editionId = wp_insert_post([
            'post_type' => 'vad_edition',
            'post_title' => 'Integration Test Edition ' . time(),
            'post_status' => 'publish',
            'post_author' => 1,
        ]);
        update_post_meta($editionId, 'course_id', $courseId);
        update_post_meta($editionId, 'start_date', date('Y-m-d', strtotime('+30 days')));
        update_post_meta($editionId, 'price', 500.00); // €500.00
        update_post_meta($editionId, 'capacity', 20);
        update_post_meta($editionId, 'status', 'open');
        update_post_meta($editionId, '_vad_completion_mode', CompletionMode::AttendAll->value);
        $this->created['edition_ids'][] = $editionId;

        // Create 3 sessions
        $sessionIds = [];
        for ($i = 1; $i <= 3; $i++) {
            $sessionId = wp_insert_post([
                'post_type' => 'vad_session',
                'post_title' => "Session {$i} " . time(),
                'post_status' => 'publish',
                'post_author' => 1,
            ]);
            update_post_meta($sessionId, 'edition_id', $editionId);
            update_post_meta($sessionId, 'date', date('Y-m-d', strtotime('+' . (30 + $i) . ' days')));
            update_post_meta($sessionId, 'start_time', '09:00');
            update_post_meta($sessionId, 'end_time', '12:00');
            update_post_meta($sessionId, 'type', 'in_person');
            $sessionIds[] = $sessionId;
            $this->created['session_ids'][] = $sessionId;
        }

        // Create user
        $userId = $this->createTestUser('integration_individual_' . time());
        $this->created['user_ids'][] = $userId;

        // Create 20% discount voucher
        $voucherId = $this->voucherService->createVoucher([
            'code' => 'INTTEST20_' . time(),
            'discount_type' => DiscountType::Percentage->value,
            'discount_value' => 20,
            'usage_limit' => 10,
            'valid_from' => date('Y-m-d'),
            'valid_until' => date('Y-m-d', strtotime('+30 days')),
        ]);
        $this->created['voucher_ids'][] = $voucherId;
        $voucherCode = get_post_meta($voucherId, 'code', true);

        // === STEP 1: USER ENROLLS ===
        $registrationId = $this->enrollmentService->enroll($userId, $editionId, [
            'enrollment_path' => RegistrationRepository::PATH_INDIVIDUAL,
        ]);

        $this->assert(
            !is_wp_error($registrationId),
            "Step 1: User enrollment succeeds"
        );

        if (is_wp_error($registrationId)) {
            echo "  Cannot continue test - enrollment failed\n\n";
            return;
        }

        $this->created['registration_ids'][] = $registrationId;

        // === STEP 2: VERIFY QUOTE AUTO-CREATED ===
        // Find quote for this registration
        global $wpdb;
        $quoteId = $wpdb->get_var($wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p
             JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
             WHERE p.post_type = 'vad_quote'
             AND pm.meta_key = 'registration_id'
             AND pm.meta_value = %d",
            $registrationId
        ));

        $this->assert(
            $quoteId !== null,
            "Step 2: Quote auto-created for registration"
        );

        if ($quoteId) {
            $this->created['quote_ids'][] = (int) $quoteId;
            $quote = $this->quoteService->getQuote((int) $quoteId);

            $this->assert(
                (int)($quote['subtotal'] ?? 0) === 50000,
                "Step 2b: Quote has correct subtotal (500.00)"
            );

            // === STEP 3: APPLY VOUCHER ===
            $applyResult = $this->quoteService->applyVoucher((int) $quoteId, $voucherCode);

            $this->assert(
                !is_wp_error($applyResult),
                "Step 3: Voucher applied successfully"
            );

            // Re-fetch quote to check discount
            $quoteAfterVoucher = $this->quoteService->getQuote((int) $quoteId);
            $expectedDiscount = 10000; // 20% of 50000

            $this->assert(
                (int)($quoteAfterVoucher['discount'] ?? 0) === $expectedDiscount,
                "Step 3b: Discount applied correctly (100.00 = 20% of 500.00)"
            );

            // === STEP 4: MARK QUOTE AS SENT ===
            $this->quoteService->markAsSent((int) $quoteId);
            $quoteSent = $this->quoteService->getQuote((int) $quoteId, true);

            $this->assert(
                ($quoteSent['status'] ?? '') === 'sent',
                "Step 4: Quote marked as sent"
            );
        }

        // === STEP 5: ATTEND ALL SESSIONS ===
        foreach ($sessionIds as $sessionId) {
            $this->attendanceService->markPresent($sessionId, $userId);
        }

        $attendedCount = $this->attendanceService->countAttended($userId, $editionId);

        $this->assert(
            $attendedCount === 3,
            "Step 5: All 3 sessions attended"
        );

        // === STEP 6: CHECK COMPLETION ===
        $isComplete = $this->completionService->isComplete($editionId, $userId);

        $this->assert(
            $isComplete === true,
            "Step 6: Edition marked as complete"
        );

        // Check progress
        $progress = $this->completionService->getProgress($editionId, $userId);

        $this->assert(
            isset($progress['is_complete']) && $progress['is_complete'] === true,
            "Step 6b: Progress shows is_complete = true"
        );

        echo "\n";
    }

    // ========================================
    // Test 8.2: Full Trajectory Enrollment Flow
    // ========================================

    private function testFullTrajectoryEnrollmentFlow(): void
    {
        echo "8.2. Testing Full Trajectory Enrollment Flow...\n";
        echo "  Flow: User enrolls in trajectory -> Makes elective choices -> Attends sessions -> Completes\n\n";

        // === SETUP ===
        // Create 4 courses (3 required + 2 elective options)
        $courseIds = [];
        $editionIds = [];

        for ($i = 1; $i <= 4; $i++) {
            $courseId = wp_insert_post([
                'post_type' => 'sfwd-courses',
                'post_title' => "Trajectory Course {$i} " . time(),
                'post_status' => 'publish',
                'post_author' => 1,
            ]);
            $courseIds[] = $courseId;
            $this->created['course_ids'][] = $courseId;

            // Create edition for each course
            $editionId = wp_insert_post([
                'post_type' => 'vad_edition',
                'post_title' => "Trajectory Edition {$i} " . time(),
                'post_status' => 'publish',
                'post_author' => 1,
            ]);
            update_post_meta($editionId, 'course_id', $courseId);
            update_post_meta($editionId, 'start_date', date('Y-m-d', strtotime('+' . (30 + $i * 7) . ' days')));
            update_post_meta($editionId, 'price', 200.00); // €200.00
            update_post_meta($editionId, 'capacity', 20);
            update_post_meta($editionId, 'status', 'open');
            update_post_meta($editionId, '_vad_completion_mode', CompletionMode::AttendAll->value);
            $editionIds[] = $editionId;
            $this->created['edition_ids'][] = $editionId;

            // Create 2 sessions per edition
            for ($j = 1; $j <= 2; $j++) {
                $sessionId = wp_insert_post([
                    'post_type' => 'vad_session',
                    'post_title' => "Traj Ed{$i} Session{$j} " . time(),
                    'post_status' => 'publish',
                    'post_author' => 1,
                ]);
                update_post_meta($sessionId, 'edition_id', $editionId);
                update_post_meta($sessionId, 'date', date('Y-m-d', strtotime('+' . (30 + $i * 7 + $j) . ' days')));
                update_post_meta($sessionId, 'start_time', '09:00');
                update_post_meta($sessionId, 'end_time', '12:00');
                update_post_meta($sessionId, 'type', 'in_person');
                $this->created['session_ids'][] = $sessionId;
            }
        }

        // Create trajectory with 2 required + 2 elective options (pick 1)
        $trajectoryId = wp_insert_post([
            'post_type' => 'vad_trajectory',
            'post_title' => 'Integration Test Trajectory ' . time(),
            'post_status' => 'publish',
            'post_author' => 1,
        ]);

        $courses = [
            ['course_id' => $courseIds[0], 'edition_id' => $editionIds[0], 'required' => true, 'group' => 'required'],
            ['course_id' => $courseIds[1], 'edition_id' => $editionIds[1], 'required' => true, 'group' => 'required'],
            ['course_id' => $courseIds[2], 'edition_id' => $editionIds[2], 'required' => false, 'group' => 'electives', 'pick_count' => 1],
            ['course_id' => $courseIds[3], 'edition_id' => $editionIds[3], 'required' => false, 'group' => 'electives', 'pick_count' => 1],
        ];

        update_post_meta($trajectoryId, 'courses', json_encode($courses));
        update_post_meta($trajectoryId, 'mode', TrajectoryMode::Cohort->value);
        update_post_meta($trajectoryId, 'status', TrajectoryStatus::Open->value);
        update_post_meta($trajectoryId, 'capacity', 10);
        update_post_meta($trajectoryId, 'price', 700.00); // €700.00
        update_post_meta($trajectoryId, 'choice_deadline', date('Y-m-d', strtotime('+14 days')));
        $this->created['trajectory_ids'][] = $trajectoryId;

        // Create user
        $userId = $this->createTestUser('integration_trajectory_' . time());
        $this->created['user_ids'][] = $userId;

        // === STEP 1: ENROLL IN TRAJECTORY ===
        $enrollmentId = $this->trajectorySelectionService->enroll($userId, $trajectoryId);

        $this->assert(
            !is_wp_error($enrollmentId),
            "Step 1: Trajectory enrollment succeeds"
        );

        if (is_wp_error($enrollmentId)) {
            echo "  Cannot continue test - enrollment failed\n\n";
            return;
        }

        $this->created['trajectory_enrollment_ids'][] = $enrollmentId;

        // === STEP 2: MAKE ELECTIVE CHOICES ===
        $chosenElective = $courseIds[2]; // Pick first elective option
        $choices = ['electives' => [$chosenElective]];

        $choiceResult = $this->trajectorySelectionService->setElectiveChoices($enrollmentId, $choices);

        $this->assert(
            !is_wp_error($choiceResult),
            "Step 2: Elective choices saved"
        );

        // Verify choices stored
        $storedChoices = $this->trajectorySelectionService->getElectiveChoices($enrollmentId);

        $this->assert(
            isset($storedChoices['electives']) && in_array($chosenElective, $storedChoices['electives']),
            "Step 2b: Choices retrieved correctly"
        );

        // === STEP 3: ATTEND REQUIRED EDITION SESSIONS ===
        // Attend sessions for first required edition
        $edition1Sessions = $this->sessionService->getSessionsForEdition($editionIds[0]);
        foreach ($edition1Sessions as $session) {
            $this->attendanceService->markPresent((int) $session['id'], $userId);
        }

        $isEdition1Complete = $this->completionService->isComplete($editionIds[0], $userId);

        $this->assert(
            $isEdition1Complete === true,
            "Step 3: First required edition completed"
        );

        // === STEP 4: CHECK TRAJECTORY PROGRESS ===
        $enrollment = $this->trajectorySelectionService->getEnrollment($enrollmentId);

        $this->assert(
            $enrollment !== null,
            "Step 4: Can retrieve trajectory enrollment details"
        );

        $this->assert(
            isset($enrollment['trajectory']) && is_array($enrollment['trajectory']),
            "Step 4b: Enrollment includes trajectory information"
        );

        echo "\n";
    }

    // ========================================
    // Test 8.3: Colleague Enrollment Flow
    // ========================================

    private function testColleagueEnrollmentFlow(): void
    {
        echo "8.3. Testing Colleague Enrollment Flow...\n";
        echo "  Flow: Admin enrolls colleague -> Quote goes to enrolling user -> Attendee gets access\n\n";

        // === SETUP ===
        // Create course and edition
        $courseId = wp_insert_post([
            'post_type' => 'sfwd-courses',
            'post_title' => 'Colleague Test Course ' . time(),
            'post_status' => 'publish',
            'post_author' => 1,
        ]);
        $this->created['course_ids'][] = $courseId;

        $editionId = wp_insert_post([
            'post_type' => 'vad_edition',
            'post_title' => 'Colleague Test Edition ' . time(),
            'post_status' => 'publish',
            'post_author' => 1,
        ]);
        update_post_meta($editionId, 'course_id', $courseId);
        update_post_meta($editionId, 'start_date', date('Y-m-d', strtotime('+30 days')));
        update_post_meta($editionId, 'price', 300.00); // €300.00
        update_post_meta($editionId, 'capacity', 20);
        update_post_meta($editionId, 'status', 'open');
        update_post_meta($editionId, '_vad_completion_mode', CompletionMode::AttendAll->value);
        $this->created['edition_ids'][] = $editionId;

        // Create 2 sessions
        for ($i = 1; $i <= 2; $i++) {
            $sessionId = wp_insert_post([
                'post_type' => 'vad_session',
                'post_title' => "Colleague Session {$i} " . time(),
                'post_status' => 'publish',
                'post_author' => 1,
            ]);
            update_post_meta($sessionId, 'edition_id', $editionId);
            update_post_meta($sessionId, 'date', date('Y-m-d', strtotime('+' . (30 + $i) . ' days')));
            update_post_meta($sessionId, 'start_time', '09:00');
            update_post_meta($sessionId, 'end_time', '12:00');
            update_post_meta($sessionId, 'type', 'in_person');
            $this->created['session_ids'][] = $sessionId;
        }

        // Create enrolling user (the one who pays)
        $enrollingUserId = $this->createTestUser('enroller_' . time());
        $this->created['user_ids'][] = $enrollingUserId;

        // Create attendee (the one who takes the course)
        $attendeeUserId = $this->createTestUser('attendee_' . time());
        $this->created['user_ids'][] = $attendeeUserId;

        // === STEP 1: ADMIN ENROLLS COLLEAGUE ===
        $registrationId = $this->enrollmentService->enroll($attendeeUserId, $editionId, [
            'enrollment_path' => RegistrationRepository::PATH_COLLEAGUE,
            'enrolled_by' => $enrollingUserId,
        ]);

        $this->assert(
            !is_wp_error($registrationId),
            "Step 1: Colleague enrollment succeeds"
        );

        if (is_wp_error($registrationId)) {
            echo "  Cannot continue test - enrollment failed\n\n";
            return;
        }

        $this->created['registration_ids'][] = $registrationId;

        // === STEP 2: VERIFY REGISTRATION DETAILS ===
        $registration = $this->registrationRepo->find($registrationId);

        $this->assert(
            $registration !== null,
            "Step 2: Registration record exists"
        );

        $this->assert(
            ($registration->enrollment_path ?? '') === RegistrationRepository::PATH_COLLEAGUE,
            "Step 2b: Enrollment path is 'colleague'"
        );

        $this->assert(
            (int) ($registration->user_id ?? 0) === $attendeeUserId,
            "Step 2c: Attendee is the user_id on registration"
        );

        $this->assert(
            (int) ($registration->enrolled_by ?? 0) === $enrollingUserId,
            "Step 2d: enrolled_by is the enrolling user"
        );

        // === STEP 3: VERIFY QUOTE CREATED FOR ENROLLING USER ===
        global $wpdb;
        $quoteId = $wpdb->get_var($wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p
             JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
             WHERE p.post_type = 'vad_quote'
             AND pm.meta_key = 'registration_id'
             AND pm.meta_value = %d",
            $registrationId
        ));

        if ($quoteId) {
            $this->created['quote_ids'][] = (int) $quoteId;
            $quote = $this->quoteService->getQuote((int) $quoteId);

            // Quote should be associated with the enrolling user for billing
            $this->assert(
                (int) ($quote['user_id'] ?? 0) === $enrollingUserId,
                "Step 3: Quote created for enrolling user (not attendee)"
            );
        } else {
            $this->assert(
                false,
                "Step 3: Quote was not created"
            );
        }

        // === STEP 4: ATTENDEE ATTENDS SESSIONS ===
        $sessions = $this->sessionService->getSessionsForEdition($editionId);
        foreach ($sessions as $session) {
            $this->attendanceService->markPresent((int) $session['id'], $attendeeUserId);
        }

        $attendedCount = $this->attendanceService->countAttended($attendeeUserId, $editionId);

        $this->assert(
            $attendedCount === 2,
            "Step 4: Attendee attended all sessions"
        );

        // === STEP 5: ATTENDEE COMPLETES ===
        $isComplete = $this->completionService->isComplete($editionId, $attendeeUserId);

        $this->assert(
            $isComplete === true,
            "Step 5: Attendee completed the edition"
        );

        // Enrolling user should NOT have completion (they didn't attend)
        $enrollerComplete = $this->completionService->isComplete($editionId, $enrollingUserId);

        $this->assert(
            $enrollerComplete === false,
            "Step 5b: Enrolling user did NOT complete (they didn't attend)"
        );

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
            update_user_meta($userId, '_stride_test_integration', true);
        }

        return is_wp_error($userId) ? 0 : $userId;
    }

    private function cleanup(): void
    {
        echo "Cleaning Up Test Data...\n";

        global $wpdb;

        // Delete trajectory enrollments
        $trajEnrollTable = $wpdb->prefix . 'vad_trajectory_enrollments';
        foreach ($this->created['trajectory_enrollment_ids'] as $enrollmentId) {
            $wpdb->delete($trajEnrollTable, ['id' => $enrollmentId], ['%d']);
        }
        echo "  - Deleted " . count($this->created['trajectory_enrollment_ids']) . " trajectory enrollments\n";

        // Delete quotes
        foreach ($this->created['quote_ids'] as $quoteId) {
            wp_delete_post($quoteId, true);
        }
        echo "  - Deleted " . count($this->created['quote_ids']) . " quotes\n";

        // Delete vouchers
        foreach ($this->created['voucher_ids'] as $voucherId) {
            wp_delete_post($voucherId, true);
        }
        echo "  - Deleted " . count($this->created['voucher_ids']) . " vouchers\n";

        // Delete attendance records
        $attendanceTable = $wpdb->prefix . 'vad_attendance';
        foreach ($this->created['user_ids'] as $userId) {
            $wpdb->delete($attendanceTable, ['user_id' => $userId], ['%d']);
        }
        echo "  - Deleted attendance records\n";

        // Delete sessions
        foreach ($this->created['session_ids'] as $sessionId) {
            wp_delete_post($sessionId, true);
        }
        echo "  - Deleted " . count($this->created['session_ids']) . " sessions\n";

        // Delete registrations
        $regTable = $wpdb->prefix . 'vad_registrations';
        foreach ($this->created['registration_ids'] as $regId) {
            $wpdb->delete($regTable, ['id' => $regId], ['%d']);
        }
        echo "  - Deleted " . count($this->created['registration_ids']) . " registrations\n";

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
$test = new StrideIntegrationTest();
$test->run();
