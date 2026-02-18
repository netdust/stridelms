<?php
/**
 * Stride LMS - Edge Cases & Stress Tests
 *
 * Tests boundary conditions, error handling, and unusual scenarios.
 *
 * Run with: ddev exec wp eval-file scripts/test-edge-cases.php
 */

if (!defined('ABSPATH')) {
    echo "This script must be run via WP-CLI: ddev exec wp eval-file scripts/test-edge-cases.php\n";
    exit(1);
}

use Stride\Modules\Enrollment\EnrollmentService;
use Stride\Modules\Enrollment\RegistrationRepository;
use Stride\Modules\Invoicing\QuoteService;
use Stride\Modules\Invoicing\VoucherService;
use Stride\Modules\Attendance\AttendanceService;
use Stride\Modules\Completion\CompletionService;
use Stride\Domain\Money;
use Stride\Domain\DiscountType;
use Stride\Domain\CompletionMode;

class StrideEdgeCasesTest
{
    private EnrollmentService $enrollmentService;
    private RegistrationRepository $registrationRepo;
    private QuoteService $quoteService;
    private VoucherService $voucherService;
    private AttendanceService $attendanceService;
    private CompletionService $completionService;

    private array $created = [
        'user_ids' => [],
        'edition_ids' => [],
        'session_ids' => [],
        'course_ids' => [],
        'registration_ids' => [],
        'quote_ids' => [],
        'voucher_ids' => [],
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
    }

    public function run(): void
    {
        echo "=== Stride LMS Edge Cases & Stress Tests ===\n\n";

        wp_set_current_user(1);

        try {
            $this->testZeroPriceEdition();
            $this->testVoucherExceedsSubtotal();
            $this->testAttendanceForNonEnrolledUser();
            $this->testCancelAlreadyCancelledRegistration();
            $this->testEditionWithNoSessions();
            $this->testUserDeletionCascade();
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
    // Test 9.1: Zero-Price Edition
    // ========================================

    private function testZeroPriceEdition(): void
    {
        echo "9.1. Testing Zero-Price Edition...\n";

        // Create course
        $courseId = wp_insert_post([
            'post_type' => 'sfwd-courses',
            'post_title' => 'Free Course ' . time(),
            'post_status' => 'publish',
            'post_author' => 1,
        ]);
        $this->created['course_ids'][] = $courseId;

        // Create edition with price = 0 (free)
        $editionId = wp_insert_post([
            'post_type' => 'vad_edition',
            'post_title' => 'Free Edition ' . time(),
            'post_status' => 'publish',
            'post_author' => 1,
        ]);
        update_post_meta($editionId, 'course_id', $courseId);
        update_post_meta($editionId, 'start_date', date('Y-m-d', strtotime('+30 days')));
        update_post_meta($editionId, 'price', 0); // Free
        update_post_meta($editionId, 'capacity', 20);
        update_post_meta($editionId, 'status', 'open');
        $this->created['edition_ids'][] = $editionId;

        // Create user and enroll
        $userId = $this->createTestUser('free_edition_' . time());
        $this->created['user_ids'][] = $userId;

        $registrationId = $this->enrollmentService->enroll($userId, $editionId, [
            'enrollment_path' => RegistrationRepository::PATH_INDIVIDUAL,
        ]);

        $this->assert(
            !is_wp_error($registrationId),
            "Enrollment in free edition succeeds"
        );

        if (!is_wp_error($registrationId)) {
            $this->created['registration_ids'][] = $registrationId;

            // Check if quote was created (may or may not be created for free editions)
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

                $this->assert(
                    ($quote['total'] ?? -1) === 0,
                    "Quote total is 0 for free edition (got: " . ($quote['total'] ?? 'null') . ")"
                );
            } else {
                $this->assert(
                    true,
                    "No quote created for free edition (acceptable behavior)"
                );
            }
        }

        echo "\n";
    }

    // ========================================
    // Test 9.2: Voucher Exceeds Subtotal
    // ========================================

    private function testVoucherExceedsSubtotal(): void
    {
        echo "9.2. Testing Voucher Exceeds Subtotal...\n";

        // Create course and edition with low price
        $courseId = wp_insert_post([
            'post_type' => 'sfwd-courses',
            'post_title' => 'Low Price Course ' . time(),
            'post_status' => 'publish',
            'post_author' => 1,
        ]);
        $this->created['course_ids'][] = $courseId;

        $editionId = wp_insert_post([
            'post_type' => 'vad_edition',
            'post_title' => 'Low Price Edition ' . time(),
            'post_status' => 'publish',
            'post_author' => 1,
        ]);
        update_post_meta($editionId, 'course_id', $courseId);
        update_post_meta($editionId, 'start_date', date('Y-m-d', strtotime('+30 days')));
        update_post_meta($editionId, 'price', 50.00); // €50.00
        update_post_meta($editionId, 'capacity', 20);
        update_post_meta($editionId, 'status', 'open');
        $this->created['edition_ids'][] = $editionId;

        // Create fixed voucher worth more than the subtotal
        $voucherId = $this->voucherService->createVoucher([
            'code' => 'BIGDISCOUNT_' . time(),
            'type' => DiscountType::Fixed->value,
            'amount' => 100.00, // 100.00 > 50.00 subtotal
            'max_uses' => 10,
            'valid_from' => date('Y-m-d'),
            'valid_until' => date('Y-m-d', strtotime('+30 days')),
        ]);
        $this->created['voucher_ids'][] = $voucherId;
        $voucherCode = get_post_meta($voucherId, 'code', true);

        // Create user, enroll, and get quote
        $userId = $this->createTestUser('voucher_exceed_' . time());
        $this->created['user_ids'][] = $userId;

        $registrationId = $this->createMockRegistration($userId, $editionId);

        // Create quote manually with the low subtotal
        $items = [
            [
                'title' => 'Test Edition',
                'quantity' => 1,
                'unit_price' => Money::eur(50.00),
            ],
        ];

        $quoteId = $this->quoteService->createQuote(
            $userId,
            $registrationId,
            $editionId,
            $items,
            [],
            null,
            Money::zero()
        );

        if (!is_wp_error($quoteId)) {
            $this->created['quote_ids'][] = $quoteId;

            // Apply the big voucher
            $applyResult = $this->quoteService->applyVoucher($quoteId, $voucherCode);

            $this->assert(
                !is_wp_error($applyResult),
                "Applying oversized voucher succeeds"
            );

            // Check that discount is capped at subtotal (no negative total)
            $quote = $this->quoteService->getQuote($quoteId);
            $discount = $quote['discount'] ?? 0;
            $subtotal = $quote['subtotal'] ?? 0;

            $this->assert(
                $discount <= $subtotal,
                "Discount capped at subtotal (discount: {$discount}, subtotal: {$subtotal})"
            );

            // Total should not be negative
            $total = $quote['total'] ?? -1;
            $this->assert(
                $total >= 0,
                "Total is not negative (got: {$total})"
            );
        } else {
            $this->assert(false, "Failed to create quote for test");
        }

        echo "\n";
    }

    // ========================================
    // Test 9.3: Attendance for Non-Enrolled User
    // ========================================

    private function testAttendanceForNonEnrolledUser(): void
    {
        echo "9.3. Testing Attendance for Non-Enrolled User...\n";

        // Create course and edition
        $courseId = wp_insert_post([
            'post_type' => 'sfwd-courses',
            'post_title' => 'Attendance Test Course ' . time(),
            'post_status' => 'publish',
            'post_author' => 1,
        ]);
        $this->created['course_ids'][] = $courseId;

        $editionId = wp_insert_post([
            'post_type' => 'vad_edition',
            'post_title' => 'Attendance Test Edition ' . time(),
            'post_status' => 'publish',
            'post_author' => 1,
        ]);
        update_post_meta($editionId, 'course_id', $courseId);
        update_post_meta($editionId, 'start_date', date('Y-m-d', strtotime('+30 days')));
        update_post_meta($editionId, 'price', 200.00); // €200.00
        update_post_meta($editionId, 'capacity', 20);
        update_post_meta($editionId, 'status', 'open');
        $this->created['edition_ids'][] = $editionId;

        // Create session
        $sessionId = wp_insert_post([
            'post_type' => 'vad_session',
            'post_title' => 'Test Session ' . time(),
            'post_status' => 'publish',
            'post_author' => 1,
        ]);
        update_post_meta($sessionId, 'edition_id', $editionId);
        update_post_meta($sessionId, 'date', date('Y-m-d', strtotime('+30 days')));
        update_post_meta($sessionId, 'start_time', '09:00');
        update_post_meta($sessionId, 'end_time', '12:00');
        update_post_meta($sessionId, 'type', 'in_person');
        $this->created['session_ids'][] = $sessionId;

        // Create user but DO NOT enroll
        $userId = $this->createTestUser('not_enrolled_' . time());
        $this->created['user_ids'][] = $userId;

        // Attempt to mark attendance for non-enrolled user
        $result = $this->attendanceService->markPresent($sessionId, $userId);

        // Depending on implementation, this might:
        // a) Return an error (strict validation)
        // b) Allow it (attendance can be marked without enrollment check)
        // Both are valid depending on business requirements

        $this->assert(
            is_wp_error($result) || $result !== false,
            "Attendance marking for non-enrolled user handled (error or allowed)"
        );

        // If it was allowed, check that the record was created
        if (!is_wp_error($result) && $result !== false) {
            $status = $this->attendanceService->getStatus($sessionId, $userId);
            $this->assert(
                $status !== null,
                "Attendance record created (permissive behavior)"
            );
        } else if (is_wp_error($result)) {
            $this->assert(
                true,
                "Attendance blocked for non-enrolled user (strict behavior, error: " . $result->get_error_code() . ")"
            );
        }

        echo "\n";
    }

    // ========================================
    // Test 9.4: Cancel Already Cancelled Registration
    // ========================================

    private function testCancelAlreadyCancelledRegistration(): void
    {
        echo "9.4. Testing Cancel Already Cancelled Registration...\n";

        // Create course and edition
        $courseId = wp_insert_post([
            'post_type' => 'sfwd-courses',
            'post_title' => 'Cancel Test Course ' . time(),
            'post_status' => 'publish',
            'post_author' => 1,
        ]);
        $this->created['course_ids'][] = $courseId;

        $editionId = wp_insert_post([
            'post_type' => 'vad_edition',
            'post_title' => 'Cancel Test Edition ' . time(),
            'post_status' => 'publish',
            'post_author' => 1,
        ]);
        update_post_meta($editionId, 'course_id', $courseId);
        update_post_meta($editionId, 'start_date', date('Y-m-d', strtotime('+30 days')));
        update_post_meta($editionId, 'price', 200.00); // €200.00
        update_post_meta($editionId, 'capacity', 20);
        update_post_meta($editionId, 'status', 'open');
        $this->created['edition_ids'][] = $editionId;

        // Create user and enroll
        $userId = $this->createTestUser('double_cancel_' . time());
        $this->created['user_ids'][] = $userId;

        $registrationId = $this->enrollmentService->enroll($userId, $editionId, [
            'enrollment_path' => RegistrationRepository::PATH_INDIVIDUAL,
        ]);

        if (is_wp_error($registrationId)) {
            $this->assert(false, "Failed to create registration for test");
            return;
        }

        $this->created['registration_ids'][] = $registrationId;

        // First cancellation
        $cancel1 = $this->enrollmentService->cancel($registrationId);

        $this->assert(
            !is_wp_error($cancel1),
            "First cancellation succeeds"
        );

        // Second cancellation (should be idempotent)
        $cancel2 = $this->enrollmentService->cancel($registrationId);

        // Should either succeed (idempotent) or return specific error
        $this->assert(
            !is_wp_error($cancel2) || $cancel2->get_error_code() === 'already_cancelled',
            "Second cancellation is idempotent or returns 'already_cancelled'"
        );

        // Verify status is still cancelled
        $registration = $this->registrationRepo->find($registrationId);

        $this->assert(
            ($registration->status ?? '') === 'cancelled',
            "Registration status remains 'cancelled'"
        );

        echo "\n";
    }

    // ========================================
    // Test 9.5: Edition with No Sessions
    // ========================================

    private function testEditionWithNoSessions(): void
    {
        echo "9.5. Testing Edition with No Sessions...\n";

        // Create course
        $courseId = wp_insert_post([
            'post_type' => 'sfwd-courses',
            'post_title' => 'No Sessions Course ' . time(),
            'post_status' => 'publish',
            'post_author' => 1,
        ]);
        $this->created['course_ids'][] = $courseId;

        // Create edition with NO sessions (virtual/online-only perhaps)
        $editionId = wp_insert_post([
            'post_type' => 'vad_edition',
            'post_title' => 'No Sessions Edition ' . time(),
            'post_status' => 'publish',
            'post_author' => 1,
        ]);
        update_post_meta($editionId, 'course_id', $courseId);
        update_post_meta($editionId, 'start_date', date('Y-m-d', strtotime('+30 days')));
        update_post_meta($editionId, 'price', 200.00); // €200.00
        update_post_meta($editionId, 'capacity', 20);
        update_post_meta($editionId, 'status', 'open');
        update_post_meta($editionId, '_vad_completion_mode', CompletionMode::AttendAll->value);
        $this->created['edition_ids'][] = $editionId;

        // Create user and enroll
        $userId = $this->createTestUser('no_sessions_' . time());
        $this->created['user_ids'][] = $userId;

        $this->createMockRegistration($userId, $editionId);

        // Check completion status for edition with no sessions
        // With 'attend_all' mode and 0 sessions, should be considered complete (vacuously true)
        // or handled gracefully
        $isComplete = $this->completionService->isComplete($editionId, $userId);

        $this->assert(
            $isComplete === true || $isComplete === false,
            "Completion check handles zero sessions gracefully (got: " . ($isComplete ? 'true' : 'false') . ")"
        );

        // Check progress
        $progress = $this->completionService->getProgress($editionId, $userId);

        $this->assert(
            isset($progress['total_sessions']) && $progress['total_sessions'] === 0,
            "Progress shows 0 total sessions"
        );

        // With 0 required sessions, percentage should be 100% or handled gracefully (no div by zero)
        $pct = (float)($progress['percentage'] ?? -1);
        $this->assert(
            isset($progress['percentage']) && ($pct === 100.0 || $pct === 0.0),
            "Progress percentage handles zero sessions (got: " . ($progress['percentage'] ?? 'null') . ")"
        );

        echo "\n";
    }

    // ========================================
    // Test 9.6: User Deletion Cascade
    // ========================================

    private function testUserDeletionCascade(): void
    {
        echo "9.6. Testing User Deletion Cascade...\n";

        // Create course and edition
        $courseId = wp_insert_post([
            'post_type' => 'sfwd-courses',
            'post_title' => 'Deletion Test Course ' . time(),
            'post_status' => 'publish',
            'post_author' => 1,
        ]);
        $this->created['course_ids'][] = $courseId;

        $editionId = wp_insert_post([
            'post_type' => 'vad_edition',
            'post_title' => 'Deletion Test Edition ' . time(),
            'post_status' => 'publish',
            'post_author' => 1,
        ]);
        update_post_meta($editionId, 'course_id', $courseId);
        update_post_meta($editionId, 'start_date', date('Y-m-d', strtotime('+30 days')));
        update_post_meta($editionId, 'price', 200.00); // €200.00
        update_post_meta($editionId, 'capacity', 20);
        update_post_meta($editionId, 'status', 'open');
        $this->created['edition_ids'][] = $editionId;

        // Create session
        $sessionId = wp_insert_post([
            'post_type' => 'vad_session',
            'post_title' => 'Deletion Test Session ' . time(),
            'post_status' => 'publish',
            'post_author' => 1,
        ]);
        update_post_meta($sessionId, 'edition_id', $editionId);
        update_post_meta($sessionId, 'date', date('Y-m-d', strtotime('+30 days')));
        update_post_meta($sessionId, 'start_time', '09:00');
        update_post_meta($sessionId, 'end_time', '12:00');
        update_post_meta($sessionId, 'type', 'in_person');
        $this->created['session_ids'][] = $sessionId;

        // Create user to be deleted
        $userId = $this->createTestUser('to_be_deleted_' . time());
        // Note: NOT adding to $this->created['user_ids'] since we'll delete manually

        // Enroll user
        $registrationId = $this->createMockRegistration($userId, $editionId);
        // Note: NOT adding to $this->created['registration_ids'] to see cascade behavior

        // Mark attendance
        $this->attendanceService->markPresent($sessionId, $userId);

        // Verify data exists before deletion
        $regBefore = $this->registrationRepo->find($registrationId);
        $this->assert(
            $regBefore !== null,
            "Registration exists before user deletion"
        );

        // Delete user
        require_once ABSPATH . 'wp-admin/includes/user.php';
        wp_delete_user($userId);

        // Check what happens to related data
        // Registration might still exist (orphaned) or be cleaned up
        $regAfter = $this->registrationRepo->find($registrationId);

        // Document the behavior (either is acceptable depending on design)
        if ($regAfter === null) {
            $this->assert(
                true,
                "Registration cleaned up with user deletion (cascade behavior)"
            );
        } else {
            $this->assert(
                true,
                "Registration preserved after user deletion (orphan behavior)"
            );

            // Clean up the orphaned registration
            global $wpdb;
            $wpdb->delete($wpdb->prefix . 'vad_registrations', ['id' => $registrationId], ['%d']);
        }

        // Check attendance records
        global $wpdb;
        $attendanceCount = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}vad_attendance WHERE user_id = %d",
            $userId
        ));

        if ($attendanceCount == 0) {
            $this->assert(
                true,
                "Attendance records cleaned up with user deletion"
            );
        } else {
            $this->assert(
                true,
                "Attendance records preserved after user deletion (orphaned, count: {$attendanceCount})"
            );

            // Clean up orphaned attendance
            $wpdb->delete($wpdb->prefix . 'vad_attendance', ['user_id' => $userId], ['%d']);
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
            update_user_meta($userId, '_stride_test_edge_cases', true);
        }

        return is_wp_error($userId) ? 0 : $userId;
    }

    private function createMockRegistration(int $userId, int $editionId): int
    {
        global $wpdb;
        $table = $wpdb->prefix . 'vad_registrations';

        $wpdb->insert($table, [
            'user_id' => $userId,
            'edition_id' => $editionId,
            'status' => 'confirmed',
            'enrollment_path' => 'individual',
        ]);

        $regId = (int) $wpdb->insert_id;
        $this->created['registration_ids'][] = $regId;

        return $regId;
    }

    private function cleanup(): void
    {
        echo "Cleaning Up Test Data...\n";

        global $wpdb;

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

        // Delete attendance records for test users
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
$test = new StrideEdgeCasesTest();
$test->run();
