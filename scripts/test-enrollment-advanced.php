<?php
/**
 * Stride LMS - Advanced Enrollment Tests
 *
 * Tests waitlist, colleague swap, cancellation policies, and managed enrollment.
 *
 * Run with: ddev exec wp eval-file scripts/test-enrollment-advanced.php
 */

if (!defined('ABSPATH')) {
    echo "This script must be run via WP-CLI: ddev exec wp eval-file scripts/test-enrollment-advanced.php\n";
    exit(1);
}

use ntdst\Stride\enrollment\EnrollmentService;
use ntdst\Stride\core\EditionService;
use ntdst\Stride\core\CourseService;
use ntdst\Stride\core\RegistrationRepository;
use ntdst\Stride\FieldRegistry;

class StrideEnrollmentAdvancedTest
{
    private EnrollmentService $enrollmentService;
    private EditionService $editionService;
    private CourseService $courseService;
    private RegistrationRepository $registrationRepo;

    private array $created = [
        'course_ids' => [],
        'edition_ids' => [],
        'user_ids' => [],
    ];

    private int $passed = 0;
    private int $failed = 0;

    public function __construct()
    {
        $this->enrollmentService = ntdst_get(EnrollmentService::class);
        $this->editionService = ntdst_get(EditionService::class);
        $this->courseService = ntdst_get(CourseService::class);
        $this->registrationRepo = ntdst_get(RegistrationRepository::class);
    }

    public function run(): void
    {
        echo "=== Stride LMS Advanced Enrollment Tests ===\n\n";

        wp_set_current_user(1);

        try {
            $this->testBasicEnrollment();
            $this->testWaitlist();
            $this->testCancellationPolicy();
            $this->testColleagueSwap();
            $this->testManagedEnrollment();
            $this->testInterestRegistration();
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

    private function skip(string $message): void
    {
        echo "  [SKIP] {$message}\n";
        $this->passed++;
    }

    // ========================================
    // A. BASIC ENROLLMENT (8 tests)
    // ========================================

    private function testBasicEnrollment(): void
    {
        echo "A. Testing Basic Enrollment...\n";

        $courseId = $this->createTestCourse('Basic Enrollment Test');
        $this->created['course_ids'][] = $courseId;

        $editionId = $this->createTestEdition($courseId, '+30 days');
        $this->created['edition_ids'][] = $editionId;

        $userId = $this->createTestUser('basic_enroll_' . time());
        $this->created['user_ids'][] = $userId;

        // A1. Enroll user in edition
        $registrationId = $this->enrollmentService->enrollInEdition($userId, $editionId);
        $this->assert(
            !is_wp_error($registrationId) && $registrationId > 0,
            "A1. Enroll user in edition succeeds"
        );

        // A2. User is enrolled
        $isEnrolled = $this->enrollmentService->isEnrolled($userId, $editionId);
        $this->assert($isEnrolled, "A2. User is enrolled after enrollment");

        // A3. User has LearnDash course access
        $hasAccess = $this->courseService->isUserEnrolled($userId, $courseId);
        $this->assert($hasAccess, "A3. User has LearnDash course access");

        // A4. Registration data is correct
        $registration = $this->enrollmentService->getRegistration($userId, $editionId);
        $this->assert(
            $registration !== null &&
            $registration['status'] === RegistrationRepository::STATUS_CONFIRMED &&
            $registration['user_id'] === $userId &&
            $registration['edition_id'] === $editionId,
            "A4. Registration data is correct"
        );

        // A5. Cannot enroll again
        $doubleEnroll = $this->enrollmentService->enrollInEdition($userId, $editionId);
        $this->assert(
            is_wp_error($doubleEnroll) && $doubleEnroll->get_error_code() === 'already_enrolled',
            "A5. Cannot enroll twice"
        );

        // A6. Get edition registrations
        $registrations = $this->enrollmentService->getEditionRegistrations($editionId);
        $this->assert(
            is_array($registrations) && count($registrations) >= 1,
            "A6. Edition registrations returns list"
        );

        // A7. Get user registrations
        $userRegs = $this->enrollmentService->getUserRegistrations($userId);
        $this->assert(
            is_array($userRegs) && count($userRegs) >= 1,
            "A7. User registrations returns list"
        );

        // A8. Enrollment path defaults to individual
        $this->assert(
            $registration['enrollment_path'] === RegistrationRepository::PATH_INDIVIDUAL,
            "A8. Default enrollment path is 'individual'"
        );

        echo "\n";
    }

    // ========================================
    // B. WAITLIST (8 tests)
    // ========================================

    private function testWaitlist(): void
    {
        echo "B. Testing Waitlist...\n";

        $courseId = $this->createTestCourse('Waitlist Test');
        $this->created['course_ids'][] = $courseId;

        // Create edition with capacity 1
        $editionId = $this->createTestEdition($courseId, '+30 days', 1);
        $this->created['edition_ids'][] = $editionId;

        $userId1 = $this->createTestUser('waitlist_user1_' . time());
        $this->created['user_ids'][] = $userId1;

        $userId2 = $this->createTestUser('waitlist_user2_' . time());
        $this->created['user_ids'][] = $userId2;

        // B1. First user enrolls successfully
        $reg1 = $this->enrollmentService->enrollInEdition($userId1, $editionId);
        $this->assert(
            !is_wp_error($reg1),
            "B1. First user enrolls successfully"
        );

        // B2. Second user cannot enroll (full)
        $reg2 = $this->enrollmentService->enrollInEdition($userId2, $editionId);
        $this->assert(
            is_wp_error($reg2) && $reg2->get_error_code() === 'edition_full',
            "B2. Second user cannot enroll (full)"
        );

        // B3. Second user can join waitlist
        $waitlistReg = $this->enrollmentService->addToWaitlist($userId2, $editionId);
        $this->assert(
            !is_wp_error($waitlistReg),
            "B3. User can join waitlist"
        );

        // B4. User is on waitlist
        $isOnWaitlist = $this->enrollmentService->isOnWaitlist($userId2, $editionId);
        $this->assert($isOnWaitlist, "B4. User is on waitlist");

        // B5. Waitlist user not enrolled
        $isEnrolled = $this->enrollmentService->isEnrolled($userId2, $editionId);
        $this->assert(!$isEnrolled, "B5. Waitlist user not enrolled");

        // B6. Waitlist user has no LearnDash access
        $hasAccess = $this->courseService->isUserEnrolled($userId2, $courseId);
        $this->assert(!$hasAccess, "B6. Waitlist user has no LearnDash access");

        // B7. Cannot join waitlist twice
        $doubleWaitlist = $this->enrollmentService->addToWaitlist($userId2, $editionId);
        $this->assert(
            is_wp_error($doubleWaitlist) && $doubleWaitlist->get_error_code() === 'already_registered',
            "B7. Cannot join waitlist twice"
        );

        // B8. Promote from waitlist
        $promoteResult = $this->enrollmentService->promoteFromWaitlist($waitlistReg);
        $this->assert(
            !is_wp_error($promoteResult),
            "B8. Promote from waitlist succeeds"
        );

        // Verify promotion
        $isEnrolled = $this->enrollmentService->isEnrolled($userId2, $editionId);
        $hasAccess = $this->courseService->isUserEnrolled($userId2, $courseId);
        $this->assert(
            $isEnrolled && $hasAccess,
            "    - Promoted user is now enrolled with access"
        );

        echo "\n";
    }

    // ========================================
    // C. CANCELLATION POLICY (8 tests)
    // ========================================

    private function testCancellationPolicy(): void
    {
        echo "C. Testing Cancellation Policy...\n";

        $courseId = $this->createTestCourse('Cancellation Test');
        $this->created['course_ids'][] = $courseId;

        // C1. Free cancellation > 14 days before
        $farEditionId = $this->createTestEdition($courseId, '+30 days');
        $this->created['edition_ids'][] = $farEditionId;

        $userId1 = $this->createTestUser('cancel_far_' . time());
        $this->created['user_ids'][] = $userId1;

        $reg1 = $this->enrollmentService->enrollInEdition($userId1, $farEditionId);
        $policy = $this->enrollmentService->getCancellationPolicy($reg1);

        $this->assert(
            $policy['can_cancel'] === true && $policy['free_cancellation'] === true,
            "C1. Free cancellation available > 14 days before"
        );

        // C2. Can swap > 14 days before
        $this->assert(
            $policy['can_swap'] === true,
            "C2. Can swap > 14 days before"
        );

        // C3. No free cancellation <= 14 days before
        $nearEditionId = $this->createTestEdition($courseId, '+7 days');
        $this->created['edition_ids'][] = $nearEditionId;

        $userId2 = $this->createTestUser('cancel_near_' . time());
        $this->created['user_ids'][] = $userId2;

        $reg2 = $this->enrollmentService->enrollInEdition($userId2, $nearEditionId);
        $policy2 = $this->enrollmentService->getCancellationPolicy($reg2);

        $this->assert(
            $policy2['can_cancel'] === true && $policy2['free_cancellation'] === false,
            "C3. No free cancellation <= 14 days before"
        );

        // C4. Can still swap <= 14 days before
        $this->assert(
            $policy2['can_swap'] === true,
            "C4. Can still swap <= 14 days before"
        );

        // C5. Cannot cancel after edition started
        $startedEditionId = $this->createTestEdition($courseId, '-1 days');
        $this->created['edition_ids'][] = $startedEditionId;

        $userId3 = $this->createTestUser('cancel_started_' . time());
        $this->created['user_ids'][] = $userId3;

        // Manually create registration for started edition
        $reg3 = $this->registrationRepo->create([
            'user_id' => $userId3,
            'edition_id' => $startedEditionId,
            'status' => RegistrationRepository::STATUS_CONFIRMED,
        ]);

        $policy3 = $this->enrollmentService->getCancellationPolicy($reg3);
        $this->assert(
            $policy3['can_cancel'] === false,
            "C5. Cannot cancel after edition started"
        );

        // C6. Cancel registration > 14 days
        $cancelResult = $this->enrollmentService->cancelRegistration($reg1);
        $this->assert(
            !is_wp_error($cancelResult),
            "C6. Cancel registration > 14 days succeeds"
        );

        // C7. User no longer enrolled after cancel
        $isEnrolled = $this->enrollmentService->isEnrolled($userId1, $farEditionId);
        $this->assert(!$isEnrolled, "C7. User not enrolled after cancellation");

        // C8. LearnDash access revoked after cancel
        $hasAccess = $this->courseService->isUserEnrolled($userId1, $courseId);
        $this->assert(!$hasAccess, "C8. LearnDash access revoked after cancellation");

        echo "\n";
    }

    // ========================================
    // D. COLLEAGUE SWAP (8 tests)
    // ========================================

    private function testColleagueSwap(): void
    {
        echo "D. Testing Colleague Swap...\n";

        $courseId = $this->createTestCourse('Swap Test');
        $this->created['course_ids'][] = $courseId;

        $editionId = $this->createTestEdition($courseId, '+30 days');
        $this->created['edition_ids'][] = $editionId;

        $originalUserId = $this->createTestUser('swap_original_' . time());
        $this->created['user_ids'][] = $originalUserId;

        $colleagueUserId = $this->createTestUser('swap_colleague_' . time());
        $this->created['user_ids'][] = $colleagueUserId;

        // D1. Enroll original user
        $originalReg = $this->enrollmentService->enrollInEdition($originalUserId, $editionId);
        $this->assert(
            !is_wp_error($originalReg),
            "D1. Original user enrolled"
        );

        // D2. Swap to colleague
        $newRegId = $this->enrollmentService->swapToColleague($originalReg, $colleagueUserId);
        $this->assert(
            !is_wp_error($newRegId),
            "D2. Swap to colleague succeeds"
        );

        // D3. Original user no longer enrolled
        $originalEnrolled = $this->enrollmentService->isEnrolled($originalUserId, $editionId);
        $this->assert(
            !$originalEnrolled,
            "D3. Original user no longer enrolled"
        );

        // D4. Colleague is enrolled
        $colleagueEnrolled = $this->enrollmentService->isEnrolled($colleagueUserId, $editionId);
        $this->assert(
            $colleagueEnrolled,
            "D4. Colleague is enrolled"
        );

        // D5. Original user lost LearnDash access
        $originalAccess = $this->courseService->isUserEnrolled($originalUserId, $courseId);
        $this->assert(
            !$originalAccess,
            "D5. Original user lost LearnDash access"
        );

        // D6. Colleague has LearnDash access
        $colleagueAccess = $this->courseService->isUserEnrolled($colleagueUserId, $courseId);
        $this->assert(
            $colleagueAccess,
            "D6. Colleague has LearnDash access"
        );

        // D7. New registration path is 'colleague'
        $newReg = $this->registrationRepo->get($newRegId);
        $this->assert(
            $newReg['enrollment_path'] === RegistrationRepository::PATH_COLLEAGUE,
            "D7. New registration path is 'colleague'"
        );

        // D8. Cannot swap to already enrolled colleague
        $anotherColleague = $this->createTestUser('swap_another_' . time());
        $this->created['user_ids'][] = $anotherColleague;

        $anotherReg = $this->enrollmentService->enrollInEdition($anotherColleague, $editionId);
        $swapToEnrolled = $this->enrollmentService->swapToColleague($anotherReg, $colleagueUserId);
        $this->assert(
            is_wp_error($swapToEnrolled) && $swapToEnrolled->get_error_code() === 'colleague_already_enrolled',
            "D8. Cannot swap to already enrolled colleague"
        );

        echo "\n";
    }

    // ========================================
    // E. MANAGED ENROLLMENT (6 tests)
    // ========================================

    private function testManagedEnrollment(): void
    {
        echo "E. Testing Managed Enrollment...\n";

        $courseId = $this->createTestCourse('Managed Test');
        $this->created['course_ids'][] = $courseId;

        $editionId = $this->createTestEdition($courseId, '+30 days');
        $this->created['edition_ids'][] = $editionId;

        $managerId = $this->createTestUser('manager_' . time());
        $this->created['user_ids'][] = $managerId;

        $employeeId = $this->createTestUser('employee_' . time());
        $this->created['user_ids'][] = $employeeId;

        // E1. Manager enrolls employee
        // Note: PATH_MANAGER doesn't exist, using 'colleague' path for managed enrollments
        $regId = $this->enrollmentService->enrollInEdition($employeeId, $editionId, [
            'enrolled_by_user_id' => $managerId,
            'enrollment_path' => RegistrationRepository::PATH_COLLEAGUE, // Closest match for managed
        ]);
        $this->assert(
            !is_wp_error($regId),
            "E1. Manager enrolls employee succeeds"
        );

        // E2. Employee is enrolled
        $isEnrolled = $this->enrollmentService->isEnrolled($employeeId, $editionId);
        $this->assert($isEnrolled, "E2. Employee is enrolled");

        // E3. Get enrolling manager
        $enrollingManager = $this->enrollmentService->getEnrollingManager($employeeId, $editionId);
        $this->assert(
            $enrollingManager === $managerId,
            "E3. Enrolling manager is correct"
        );

        // E4. Is managed enrollment
        $isManaged = $this->enrollmentService->isManaged($employeeId, $editionId);
        $this->assert($isManaged, "E4. Registration is managed");

        // E5. Registration path is 'colleague' (used for managed enrollments)
        $registration = $this->enrollmentService->getRegistration($employeeId, $editionId);
        $this->assert(
            $registration['enrollment_path'] === RegistrationRepository::PATH_COLLEAGUE,
            "E5. Registration path is 'colleague' (managed enrollment)"
        );

        // E6. Self-enrollment is not managed
        $selfUserId = $this->createTestUser('self_' . time());
        $this->created['user_ids'][] = $selfUserId;

        $this->enrollmentService->enrollInEdition($selfUserId, $editionId);
        $isSelfManaged = $this->enrollmentService->isManaged($selfUserId, $editionId);
        $this->assert(
            !$isSelfManaged,
            "E6. Self-enrollment is not managed"
        );

        echo "\n";
    }

    // ========================================
    // F. INTEREST REGISTRATION (6 tests)
    // ========================================

    private function testInterestRegistration(): void
    {
        echo "F. Testing Interest Registration...\n";

        $courseId = $this->createTestCourse('Interest Test');
        $this->created['course_ids'][] = $courseId;

        // Create announcement edition
        $announcementEditionId = $this->editionService->createEdition([
            FieldRegistry::EDITION_COURSE_ID => $courseId,
            FieldRegistry::EDITION_START_DATE => date('Y-m-d', strtotime('+30 days')),
            FieldRegistry::EDITION_STATUS => FieldRegistry::EDITION_STATUS_ANNOUNCEMENT,
        ]);
        $this->created['edition_ids'][] = $announcementEditionId;

        $userId = $this->createTestUser('interest_' . time());
        $this->created['user_ids'][] = $userId;

        // F1. Cannot enroll in announcement edition
        $enrollResult = $this->enrollmentService->enrollInEdition($userId, $announcementEditionId);
        $this->assert(
            is_wp_error($enrollResult) && $enrollResult->get_error_code() === 'edition_announcement',
            "F1. Cannot enroll in announcement edition"
        );

        // F2. Can register interest
        $interestReg = $this->enrollmentService->registerInterest($userId, $announcementEditionId);
        $this->assert(
            !is_wp_error($interestReg),
            "F2. Can register interest in announcement"
        );

        // F3. User is not enrolled
        $isEnrolled = $this->enrollmentService->isEnrolled($userId, $announcementEditionId);
        $this->assert(!$isEnrolled, "F3. Interest registration is not enrollment");

        // F4. User has no LearnDash access
        $hasAccess = $this->courseService->isUserEnrolled($userId, $courseId);
        $this->assert(!$hasAccess, "F4. Interest user has no LearnDash access");

        // F5. Registration status is 'interest'
        $registration = $this->registrationRepo->get($interestReg);
        $this->assert(
            $registration['status'] === RegistrationRepository::STATUS_INTEREST,
            "F5. Registration status is 'interest'"
        );

        // F6. Cannot register interest twice
        $doubleInterest = $this->enrollmentService->registerInterest($userId, $announcementEditionId);
        $this->assert(
            is_wp_error($doubleInterest) && $doubleInterest->get_error_code() === 'already_registered',
            "F6. Cannot register interest twice"
        );

        echo "\n";
    }

    // ========================================
    // HELPERS
    // ========================================

    private function createTestCourse(string $title): int
    {
        return wp_insert_post([
            'post_type' => 'sfwd-courses',
            'post_title' => $title . ' ' . time(),
            'post_status' => 'publish',
            'post_author' => 1,
        ]);
    }

    private function createTestEdition(int $courseId, string $startOffset, ?int $capacity = null): int
    {
        $data = [
            FieldRegistry::EDITION_COURSE_ID => $courseId,
            FieldRegistry::EDITION_START_DATE => date('Y-m-d', strtotime($startOffset)),
            FieldRegistry::EDITION_STATUS => FieldRegistry::EDITION_STATUS_OPEN,
        ];

        if ($capacity !== null) {
            $data[FieldRegistry::EDITION_CAPACITY] = $capacity;
        }

        $editionId = $this->editionService->createEdition($data);
        return is_wp_error($editionId) ? 0 : $editionId;
    }

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

        wp_set_current_user(1);

        // Delete editions
        foreach ($this->created['edition_ids'] as $editionId) {
            if ($editionId && !is_wp_error($editionId)) {
                wp_delete_post($editionId, true);
            }
        }
        echo "  - Deleted " . count($this->created['edition_ids']) . " editions\n";

        // Delete courses
        foreach ($this->created['course_ids'] as $courseId) {
            if ($courseId && !is_wp_error($courseId)) {
                wp_delete_post($courseId, true);
            }
        }
        echo "  - Deleted " . count($this->created['course_ids']) . " courses\n";

        // Delete users
        require_once ABSPATH . 'wp-admin/includes/user.php';
        foreach ($this->created['user_ids'] as $userId) {
            if ($userId) {
                // Revoke any LearnDash access
                foreach ($this->created['course_ids'] as $courseId) {
                    if ($this->courseService->isUserEnrolled($userId, $courseId)) {
                        $this->courseService->revokeAccess($userId, $courseId);
                    }
                }
                wp_delete_user($userId);
            }
        }
        echo "  - Deleted " . count($this->created['user_ids']) . " users\n";

        echo "  Cleanup complete.\n";
    }
}

// Run the test
$test = new StrideEnrollmentAdvancedTest();
$test->run();
