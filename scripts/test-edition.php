<?php
/**
 * Stride LMS - Edition Tests
 *
 * Tests edition CRUD, status management, capacity, and pricing.
 *
 * Run with: ddev exec wp eval-file scripts/test-edition.php
 */

if (!defined('ABSPATH')) {
    echo "This script must be run via WP-CLI: ddev exec wp eval-file scripts/test-edition.php\n";
    exit(1);
}

use ntdst\Stride\core\EditionService;
use ntdst\Stride\core\RegistrationRepository;
use ntdst\Stride\FieldRegistry;

class StrideEditionTest
{
    private EditionService $editionService;
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
        $this->editionService = ntdst_get(EditionService::class);
        $this->registrationRepo = ntdst_get(RegistrationRepository::class);
    }

    public function run(): void
    {
        echo "=== Stride LMS Edition Tests ===\n\n";

        wp_set_current_user(1);

        try {
            $this->testEditionCrud();
            $this->testEditionDates();
            $this->testEditionStatus();
            $this->testEditionCapacity();
            $this->testEditionPricing();
            $this->testEnrollmentValidation();
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
    // A. EDITION CRUD (8 tests)
    // ========================================

    private function testEditionCrud(): void
    {
        echo "A. Testing Edition CRUD Operations...\n";

        // Create test course first
        $courseId = wp_insert_post([
            'post_type' => 'sfwd-courses',
            'post_title' => 'Test Course for Edition ' . time(),
            'post_status' => 'publish',
            'post_author' => 1,
        ]);
        $this->created['course_ids'][] = $courseId;

        // A1. Create edition with minimal data
        $editionId = $this->editionService->createEdition([
            FieldRegistry::EDITION_COURSE_ID => $courseId,
            FieldRegistry::EDITION_START_DATE => date('Y-m-d', strtotime('+30 days')),
        ]);
        $this->assert(
            !is_wp_error($editionId) && $editionId > 0,
            "A1. Create edition with minimal data"
        );

        if (!is_wp_error($editionId)) {
            $this->created['edition_ids'][] = $editionId;
        }

        // A2. Create edition with full data
        $fullEditionId = $this->editionService->createEdition([
            FieldRegistry::EDITION_COURSE_ID => $courseId,
            FieldRegistry::EDITION_START_DATE => date('Y-m-d', strtotime('+60 days')),
            FieldRegistry::EDITION_END_DATE => date('Y-m-d', strtotime('+62 days')),
            FieldRegistry::EDITION_CAPACITY => 20,
            FieldRegistry::EDITION_PRICE => 250.00,
            FieldRegistry::EDITION_PRICE_NON_MEMBER => 350.00,
            FieldRegistry::EDITION_VENUE => 'Brussels Conference Center',
            FieldRegistry::EDITION_SPEAKERS => 'Jan Peeters, trainer; An Claes, gastspreker',
            FieldRegistry::EDITION_STATUS => FieldRegistry::EDITION_STATUS_OPEN,
            FieldRegistry::EDITION_INVOICE_ITEM => 'COURSE-001',
            FieldRegistry::EDITION_INVOICE_ENABLED => true,
            FieldRegistry::EDITION_CERTIFICATE_ENABLED => true,
        ]);
        $this->assert(
            !is_wp_error($fullEditionId) && $fullEditionId > 0,
            "A2. Create edition with full data"
        );

        if (!is_wp_error($fullEditionId)) {
            $this->created['edition_ids'][] = $fullEditionId;
        }

        // A3. Get edition
        $edition = $this->editionService->getEdition($fullEditionId);
        $this->assert(
            $edition !== null && isset($edition['id']),
            "A3. Get edition returns data"
        );

        // A4. Verify edition fields
        $this->assert(
            $edition['course_id'] === $courseId &&
            $edition['capacity'] === 20 &&
            abs($edition['price'] - 250.00) < 0.01 &&
            $edition['venue'] === 'Brussels Conference Center',
            "A4. Edition fields stored correctly"
        );

        // A5. Update edition
        $updateResult = $this->editionService->updateEdition($fullEditionId, [
            FieldRegistry::EDITION_CAPACITY => 25,
            FieldRegistry::EDITION_PRICE => 275.00,
        ]);
        $this->assert(!is_wp_error($updateResult), "A5. Update edition succeeds");

        // A6. Verify update
        $updatedEdition = $this->editionService->getEdition($fullEditionId);
        $this->assert(
            $updatedEdition['capacity'] === 25 && abs($updatedEdition['price'] - 275.00) < 0.01,
            "A6. Edition update persisted"
        );

        // A7. Get editions for course
        $editions = $this->editionService->getEditionsForCourse($courseId);
        $this->assert(
            is_array($editions) && count($editions) >= 2,
            "A7. Get editions for course returns list (found: " . count($editions) . ")"
        );

        // A8. Get non-existent edition returns null
        $nullEdition = $this->editionService->getEdition(999999);
        $this->assert($nullEdition === null, "A8. Get non-existent edition returns null");

        echo "\n";
    }

    // ========================================
    // B. EDITION DATES (6 tests)
    // ========================================

    private function testEditionDates(): void
    {
        echo "B. Testing Edition Dates...\n";

        $courseId = wp_insert_post([
            'post_type' => 'sfwd-courses',
            'post_title' => 'Date Test Course ' . time(),
            'post_status' => 'publish',
            'post_author' => 1,
        ]);
        $this->created['course_ids'][] = $courseId;

        // B1. Future edition not started
        $futureEditionId = $this->editionService->createEdition([
            FieldRegistry::EDITION_COURSE_ID => $courseId,
            FieldRegistry::EDITION_START_DATE => date('Y-m-d', strtotime('+30 days')),
            FieldRegistry::EDITION_END_DATE => date('Y-m-d', strtotime('+32 days')),
        ]);
        $this->created['edition_ids'][] = $futureEditionId;

        $this->assert(
            !$this->editionService->hasStarted($futureEditionId),
            "B1. Future edition has not started"
        );

        // B2. Future edition not ended
        $this->assert(
            !$this->editionService->hasEnded($futureEditionId),
            "B2. Future edition has not ended"
        );

        // B3. Future edition is upcoming
        $this->assert(
            $this->editionService->isUpcoming($futureEditionId),
            "B3. Future edition is upcoming"
        );

        // B4. Past edition has started
        $pastEditionId = $this->editionService->createEdition([
            FieldRegistry::EDITION_COURSE_ID => $courseId,
            FieldRegistry::EDITION_START_DATE => date('Y-m-d', strtotime('-5 days')),
            FieldRegistry::EDITION_END_DATE => date('Y-m-d', strtotime('-3 days')),
        ]);
        $this->created['edition_ids'][] = $pastEditionId;

        $this->assert(
            $this->editionService->hasStarted($pastEditionId),
            "B4. Past edition has started"
        );

        // B5. Past edition has ended
        $this->assert(
            $this->editionService->hasEnded($pastEditionId),
            "B5. Past edition has ended"
        );

        // B6. Get start/end dates
        $startDate = $this->editionService->getStartDate($futureEditionId);
        $endDate = $this->editionService->getEndDate($futureEditionId);
        $this->assert(
            $startDate !== null && $endDate !== null && strtotime($endDate) > strtotime($startDate),
            "B6. Start and end dates retrieved correctly"
        );

        echo "\n";
    }

    // ========================================
    // C. EDITION STATUS (8 tests)
    // ========================================

    private function testEditionStatus(): void
    {
        echo "C. Testing Edition Status...\n";

        $courseId = wp_insert_post([
            'post_type' => 'sfwd-courses',
            'post_title' => 'Status Test Course ' . time(),
            'post_status' => 'publish',
            'post_author' => 1,
        ]);
        $this->created['course_ids'][] = $courseId;

        // C1. New edition is open by default
        $editionId = $this->editionService->createEdition([
            FieldRegistry::EDITION_COURSE_ID => $courseId,
            FieldRegistry::EDITION_START_DATE => date('Y-m-d', strtotime('+30 days')),
        ]);
        $this->created['edition_ids'][] = $editionId;

        $status = $this->editionService->getStatus($editionId);
        $this->assert(
            $status === FieldRegistry::EDITION_STATUS_OPEN,
            "C1. New edition status is 'open'"
        );

        // C2. Enrollment is open for open edition
        $this->assert(
            $this->editionService->isEnrollmentOpen($editionId),
            "C2. Enrollment open for open edition"
        );

        // C3. Set to cancelled
        $this->editionService->updateEdition($editionId, [
            FieldRegistry::EDITION_STATUS => FieldRegistry::EDITION_STATUS_CANCELLED,
        ]);
        $this->assert(
            $this->editionService->isCancelled($editionId),
            "C3. Edition cancelled status correct"
        );

        // C4. Enrollment closed for cancelled edition
        $this->assert(
            !$this->editionService->isEnrollmentOpen($editionId),
            "C4. Enrollment closed for cancelled edition"
        );

        // C5. Set to postponed
        $this->editionService->updateEdition($editionId, [
            FieldRegistry::EDITION_STATUS => FieldRegistry::EDITION_STATUS_POSTPONED,
        ]);
        $this->assert(
            $this->editionService->isPostponed($editionId),
            "C5. Edition postponed status correct"
        );

        // C6. Set to announcement
        $this->editionService->updateEdition($editionId, [
            FieldRegistry::EDITION_STATUS => FieldRegistry::EDITION_STATUS_ANNOUNCEMENT,
        ]);
        $this->assert(
            $this->editionService->isAnnouncement($editionId),
            "C6. Edition announcement status correct"
        );

        // C7. Enrollment closed for announcement
        $this->assert(
            !$this->editionService->isEnrollmentOpen($editionId),
            "C7. Enrollment closed for announcement edition"
        );

        // C8. Set to full
        $this->editionService->updateEdition($editionId, [
            FieldRegistry::EDITION_STATUS => FieldRegistry::EDITION_STATUS_FULL,
        ]);
        $this->assert(
            $this->editionService->isFull($editionId),
            "C8. Edition full status correct"
        );

        echo "\n";
    }

    // ========================================
    // D. EDITION CAPACITY (8 tests)
    // ========================================

    private function testEditionCapacity(): void
    {
        echo "D. Testing Edition Capacity...\n";

        $courseId = wp_insert_post([
            'post_type' => 'sfwd-courses',
            'post_title' => 'Capacity Test Course ' . time(),
            'post_status' => 'publish',
            'post_author' => 1,
        ]);
        $this->created['course_ids'][] = $courseId;

        // D1. Edition with no capacity limit
        $unlimitedEditionId = $this->editionService->createEdition([
            FieldRegistry::EDITION_COURSE_ID => $courseId,
            FieldRegistry::EDITION_START_DATE => date('Y-m-d', strtotime('+30 days')),
            FieldRegistry::EDITION_CAPACITY => 0, // 0 = unlimited
        ]);
        $this->created['edition_ids'][] = $unlimitedEditionId;

        $capacity = $this->editionService->getCapacity($unlimitedEditionId);
        $this->assert(
            $capacity === null || $capacity === 0,
            "D1. Edition with 0 capacity returns null/0 (unlimited)"
        );

        // D2. Unlimited edition has available spots
        $availableSpots = $this->editionService->getAvailableSpots($unlimitedEditionId);
        $this->assert(
            $availableSpots === -1,
            "D2. Unlimited edition returns -1 available spots"
        );

        // D3. Edition with capacity limit
        $limitedEditionId = $this->editionService->createEdition([
            FieldRegistry::EDITION_COURSE_ID => $courseId,
            FieldRegistry::EDITION_START_DATE => date('Y-m-d', strtotime('+30 days')),
            FieldRegistry::EDITION_CAPACITY => 3,
        ]);
        $this->created['edition_ids'][] = $limitedEditionId;

        $capacity = $this->editionService->getCapacity($limitedEditionId);
        $this->assert($capacity === 3, "D3. Capacity retrieved correctly (3)");

        // D4. Initial registered count is 0
        $registeredCount = $this->editionService->getRegisteredCount($limitedEditionId);
        $this->assert($registeredCount === 0, "D4. Initial registered count is 0");

        // D5. Has available spots initially
        $this->assert(
            $this->editionService->hasAvailableSpots($limitedEditionId),
            "D5. Has available spots initially"
        );

        // D6. Available spots = capacity initially
        $availableSpots = $this->editionService->getAvailableSpots($limitedEditionId);
        $this->assert($availableSpots === 3, "D6. Available spots equals capacity (3)");

        // D7. Create registrations and verify count
        $userId = $this->createTestUser('capacity_test_' . time());
        $this->created['user_ids'][] = $userId;

        $this->registrationRepo->create([
            'user_id' => $userId,
            'edition_id' => $limitedEditionId,
            'status' => RegistrationRepository::STATUS_CONFIRMED,
        ]);

        $registeredCount = $this->editionService->getRegisteredCount($limitedEditionId);
        $this->assert($registeredCount === 1, "D7. Registered count updated after registration (1)");

        // D8. Available spots decreased
        $availableSpots = $this->editionService->getAvailableSpots($limitedEditionId);
        $this->assert($availableSpots === 2, "D8. Available spots decreased to 2");

        echo "\n";
    }

    // ========================================
    // E. EDITION PRICING (6 tests)
    // ========================================

    private function testEditionPricing(): void
    {
        echo "E. Testing Edition Pricing...\n";

        $courseId = wp_insert_post([
            'post_type' => 'sfwd-courses',
            'post_title' => 'Pricing Test Course ' . time(),
            'post_status' => 'publish',
            'post_author' => 1,
        ]);
        $this->created['course_ids'][] = $courseId;

        // E1. Free edition (no price)
        $freeEditionId = $this->editionService->createEdition([
            FieldRegistry::EDITION_COURSE_ID => $courseId,
            FieldRegistry::EDITION_START_DATE => date('Y-m-d', strtotime('+30 days')),
        ]);
        $this->created['edition_ids'][] = $freeEditionId;

        $price = $this->editionService->getPrice($freeEditionId);
        $this->assert(
            $price === null || $price === 0.0,
            "E1. Free edition price is null or 0"
        );

        // E2. Paid edition - member price
        $paidEditionId = $this->editionService->createEdition([
            FieldRegistry::EDITION_COURSE_ID => $courseId,
            FieldRegistry::EDITION_START_DATE => date('Y-m-d', strtotime('+30 days')),
            FieldRegistry::EDITION_PRICE => 250.50,
            FieldRegistry::EDITION_PRICE_NON_MEMBER => 350.75,
        ]);
        $this->created['edition_ids'][] = $paidEditionId;

        $price = $this->editionService->getPrice($paidEditionId);
        $this->assert(
            abs($price - 250.50) < 0.01,
            "E2. Member price stored correctly (250.50)"
        );

        // E3. Non-member price
        $priceNonMember = $this->editionService->getPriceNonMember($paidEditionId);
        $this->assert(
            abs($priceNonMember - 350.75) < 0.01,
            "E3. Non-member price stored correctly (350.75)"
        );

        // E4. Invoice item
        $invoiceEditionId = $this->editionService->createEdition([
            FieldRegistry::EDITION_COURSE_ID => $courseId,
            FieldRegistry::EDITION_START_DATE => date('Y-m-d', strtotime('+30 days')),
            FieldRegistry::EDITION_PRICE => 100.00,
            FieldRegistry::EDITION_INVOICE_ITEM => 'INV-ITEM-001',
            FieldRegistry::EDITION_INVOICE_ENABLED => true,
        ]);
        $this->created['edition_ids'][] = $invoiceEditionId;

        $invoiceItem = $this->editionService->getInvoiceItem($invoiceEditionId);
        $this->assert(
            $invoiceItem === 'INV-ITEM-001',
            "E4. Invoice item stored correctly"
        );

        // E5. Invoice enabled
        $this->assert(
            $this->editionService->isInvoiceEnabled($invoiceEditionId),
            "E5. Invoice enabled flag correct"
        );

        // E6. Certificate enabled
        $certEditionId = $this->editionService->createEdition([
            FieldRegistry::EDITION_COURSE_ID => $courseId,
            FieldRegistry::EDITION_START_DATE => date('Y-m-d', strtotime('+30 days')),
            FieldRegistry::EDITION_CERTIFICATE_ENABLED => true,
        ]);
        $this->created['edition_ids'][] = $certEditionId;

        $this->assert(
            $this->editionService->isCertificateEnabled($certEditionId),
            "E6. Certificate enabled flag correct"
        );

        echo "\n";
    }

    // ========================================
    // F. ENROLLMENT VALIDATION (8 tests)
    // ========================================

    private function testEnrollmentValidation(): void
    {
        echo "F. Testing Enrollment Validation...\n";

        $courseId = wp_insert_post([
            'post_type' => 'sfwd-courses',
            'post_title' => 'Validation Test Course ' . time(),
            'post_status' => 'publish',
            'post_author' => 1,
        ]);
        $this->created['course_ids'][] = $courseId;

        $userId = $this->createTestUser('validation_test_' . time());
        $this->created['user_ids'][] = $userId;

        // F1. Can enroll in open edition
        $openEditionId = $this->editionService->createEdition([
            FieldRegistry::EDITION_COURSE_ID => $courseId,
            FieldRegistry::EDITION_START_DATE => date('Y-m-d', strtotime('+30 days')),
            FieldRegistry::EDITION_STATUS => FieldRegistry::EDITION_STATUS_OPEN,
        ]);
        $this->created['edition_ids'][] = $openEditionId;

        $canEnroll = $this->editionService->canUserEnroll($userId, $openEditionId);
        $this->assert(
            $canEnroll === true,
            "F1. Can enroll in open edition"
        );

        // F2. Cannot enroll in cancelled edition
        $cancelledEditionId = $this->editionService->createEdition([
            FieldRegistry::EDITION_COURSE_ID => $courseId,
            FieldRegistry::EDITION_START_DATE => date('Y-m-d', strtotime('+30 days')),
            FieldRegistry::EDITION_STATUS => FieldRegistry::EDITION_STATUS_CANCELLED,
        ]);
        $this->created['edition_ids'][] = $cancelledEditionId;

        $canEnroll = $this->editionService->canUserEnroll($userId, $cancelledEditionId);
        $this->assert(
            is_wp_error($canEnroll) && $canEnroll->get_error_code() === 'edition_cancelled',
            "F2. Cannot enroll in cancelled edition"
        );

        // F3. Cannot enroll in ended edition
        $endedEditionId = $this->editionService->createEdition([
            FieldRegistry::EDITION_COURSE_ID => $courseId,
            FieldRegistry::EDITION_START_DATE => date('Y-m-d', strtotime('-5 days')),
            FieldRegistry::EDITION_END_DATE => date('Y-m-d', strtotime('-3 days')),
            FieldRegistry::EDITION_STATUS => FieldRegistry::EDITION_STATUS_OPEN,
        ]);
        $this->created['edition_ids'][] = $endedEditionId;

        $canEnroll = $this->editionService->canUserEnroll($userId, $endedEditionId);
        $this->assert(
            is_wp_error($canEnroll) && $canEnroll->get_error_code() === 'edition_ended',
            "F3. Cannot enroll in ended edition"
        );

        // F4. Cannot enroll in announcement edition
        $announcementEditionId = $this->editionService->createEdition([
            FieldRegistry::EDITION_COURSE_ID => $courseId,
            FieldRegistry::EDITION_START_DATE => date('Y-m-d', strtotime('+30 days')),
            FieldRegistry::EDITION_STATUS => FieldRegistry::EDITION_STATUS_ANNOUNCEMENT,
        ]);
        $this->created['edition_ids'][] = $announcementEditionId;

        $canEnroll = $this->editionService->canUserEnroll($userId, $announcementEditionId);
        $this->assert(
            is_wp_error($canEnroll) && $canEnroll->get_error_code() === 'edition_announcement',
            "F4. Cannot enroll in announcement edition"
        );

        // F5. Cannot enroll in full edition (via capacity)
        $fullEditionId = $this->editionService->createEdition([
            FieldRegistry::EDITION_COURSE_ID => $courseId,
            FieldRegistry::EDITION_START_DATE => date('Y-m-d', strtotime('+30 days')),
            FieldRegistry::EDITION_CAPACITY => 1,
            FieldRegistry::EDITION_STATUS => FieldRegistry::EDITION_STATUS_OPEN,
        ]);
        $this->created['edition_ids'][] = $fullEditionId;

        // Fill the edition
        $fillerUserId = $this->createTestUser('filler_' . time());
        $this->created['user_ids'][] = $fillerUserId;
        $this->registrationRepo->create([
            'user_id' => $fillerUserId,
            'edition_id' => $fullEditionId,
            'status' => RegistrationRepository::STATUS_CONFIRMED,
        ]);

        $canEnroll = $this->editionService->canUserEnroll($userId, $fullEditionId);
        $this->assert(
            is_wp_error($canEnroll) && $canEnroll->get_error_code() === 'edition_full',
            "F5. Cannot enroll in full edition"
        );

        // F6. Cannot enroll if already enrolled
        $doubleEnrollEditionId = $this->editionService->createEdition([
            FieldRegistry::EDITION_COURSE_ID => $courseId,
            FieldRegistry::EDITION_START_DATE => date('Y-m-d', strtotime('+30 days')),
            FieldRegistry::EDITION_STATUS => FieldRegistry::EDITION_STATUS_OPEN,
        ]);
        $this->created['edition_ids'][] = $doubleEnrollEditionId;

        $this->registrationRepo->create([
            'user_id' => $userId,
            'edition_id' => $doubleEnrollEditionId,
            'status' => RegistrationRepository::STATUS_CONFIRMED,
        ]);

        $canEnroll = $this->editionService->canUserEnroll($userId, $doubleEnrollEditionId);
        $this->assert(
            is_wp_error($canEnroll) && $canEnroll->get_error_code() === 'already_enrolled',
            "F6. Cannot enroll if already enrolled"
        );

        // F7. Cannot enroll in non-existent edition
        $canEnroll = $this->editionService->canUserEnroll($userId, 999999);
        $this->assert(
            is_wp_error($canEnroll) && $canEnroll->get_error_code() === 'invalid_edition',
            "F7. Cannot enroll in non-existent edition"
        );

        // F8. Validation rejects invalid data
        $invalidResult = $this->editionService->createEdition([
            FieldRegistry::EDITION_COURSE_ID => $courseId,
            FieldRegistry::EDITION_START_DATE => date('Y-m-d', strtotime('+30 days')),
            FieldRegistry::EDITION_END_DATE => date('Y-m-d', strtotime('+20 days')), // End before start
        ]);
        $this->assert(
            is_wp_error($invalidResult) && $invalidResult->get_error_code() === 'invalid_date_range',
            "F8. Validation rejects end date before start date"
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
            update_user_meta($userId, '_stride_test_edition', true);
        }

        return is_wp_error($userId) ? 0 : $userId;
    }

    private function cleanup(): void
    {
        echo "Cleaning Up Test Data...\n";

        wp_set_current_user(1);

        // Delete editions (this also cleans up registrations via cascade)
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
                wp_delete_user($userId);
            }
        }
        echo "  - Deleted " . count($this->created['user_ids']) . " users\n";

        echo "  Cleanup complete.\n";
    }
}

// Run the test
$test = new StrideEditionTest();
$test->run();
