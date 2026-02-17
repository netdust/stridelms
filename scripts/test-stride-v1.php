<?php
/**
 * Stride V1 - Comprehensive Test Suite
 *
 * Tests all services and their integrations.
 *
 * Run with: ddev exec wp eval-file scripts/test-stride-v1.php
 */

if (!defined('ABSPATH')) {
    echo "Run via WP-CLI: ddev exec wp eval-file scripts/test-stride-v1.php\n";
    exit(1);
}

use Stride\Admin\AdminDashboardService;
use Stride\Admin\AdminAPIController;
use Stride\Modules\Edition\EditionService;
use Stride\Modules\Edition\SessionService;
use Stride\Modules\Invoicing\QuoteService;
use Stride\Modules\Invoicing\VoucherService;
use Stride\Modules\Enrollment\EnrollmentService;
use Stride\Modules\Attendance\AttendanceService;
use Stride\Modules\Completion\CompletionService;
use Stride\Modules\Trajectory\TrajectoryService;
use Stride\Domain\DiscountType;
use Stride\Domain\Money;

echo "=== Stride V1 - Comprehensive Test Suite ===\n\n";

$GLOBALS['passed'] = 0;
$GLOBALS['failed'] = 0;
$GLOBALS['skipped'] = 0;

function assert_test(bool $condition, string $message): void {
    if ($condition) {
        echo "  [PASS] {$message}\n";
        $GLOBALS['passed']++;
    } else {
        echo "  [FAIL] {$message}\n";
        $GLOBALS['failed']++;
    }
}

function skip_test(string $message): void {
    echo "  [SKIP] {$message}\n";
    $GLOBALS['skipped']++;
}

// Set admin user for permissions
wp_set_current_user(1);

// ==========================================
// A. SERVICE REGISTRATION
// ==========================================
echo "A. Service Registration...\n";

$services = [
    'AdminDashboardService' => AdminDashboardService::class,
    'AdminAPIController' => AdminAPIController::class,
    'EditionService' => EditionService::class,
    'SessionService' => SessionService::class,
    'QuoteService' => QuoteService::class,
    'VoucherService' => VoucherService::class,
    'EnrollmentService' => EnrollmentService::class,
    'AttendanceService' => AttendanceService::class,
    'CompletionService' => CompletionService::class,
    'TrajectoryService' => TrajectoryService::class,
];

foreach ($services as $name => $class) {
    try {
        $service = ntdst_get($class);
        assert_test($service !== null, "A. {$name} registered");
    } catch (Exception $e) {
        assert_test(false, "A. {$name} registered ({$e->getMessage()})");
    }
}

echo "\n";

// ==========================================
// B. ADMIN API ENDPOINTS
// ==========================================
echo "B. Admin API Endpoints...\n";

// B1. Stats endpoint
$request = new WP_REST_Request('GET', '/stride/v1/admin/stats');
$response = rest_do_request($request);
assert_test($response->get_status() === 200, 'B1. Stats endpoint returns 200');

$stats = $response->get_data();
assert_test(isset($stats['upcomingEditions']), 'B1a. Stats has upcomingEditions');
assert_test(isset($stats['totalRegistrations']), 'B1b. Stats has totalRegistrations');
assert_test(isset($stats['pendingQuotes']), 'B1c. Stats has pendingQuotes');

// B2. Editions endpoint
$request = new WP_REST_Request('GET', '/stride/v1/admin/editions');
$response = rest_do_request($request);
assert_test($response->get_status() === 200, 'B2. Editions endpoint returns 200');

$editions = $response->get_data();
assert_test(isset($editions['items']), 'B2a. Editions has items array');
assert_test(isset($editions['total']), 'B2b. Editions has total count');

// B3. Quotes endpoint
$request = new WP_REST_Request('GET', '/stride/v1/admin/quotes');
$response = rest_do_request($request);
assert_test($response->get_status() === 200, 'B3. Quotes endpoint returns 200');

$quotes = $response->get_data();
assert_test(isset($quotes['items']), 'B3a. Quotes has items array');

// B4. Permission check - anonymous
wp_set_current_user(0);
$request = new WP_REST_Request('GET', '/stride/v1/admin/stats');
$response = rest_do_request($request);
assert_test($response->get_status() === 401, 'B4. Anonymous user blocked');
wp_set_current_user(1);

echo "\n";

// ==========================================
// C. VOUCHER SERVICE
// ==========================================
echo "C. Voucher Service...\n";

try {
    $voucherService = ntdst_get(VoucherService::class);

    // C1. Create voucher with percentage discount
    $voucherId = $voucherService->createVoucher([
        'discount_type' => DiscountType::Percentage->value,
        'discount_value' => 25,
        'usage_limit' => 5,
    ]);
    assert_test(!is_wp_error($voucherId), 'C1. Create voucher succeeds');

    if (!is_wp_error($voucherId)) {
        $voucher = $voucherService->getVoucher($voucherId);

        // C2. Voucher has generated code
        assert_test(!empty($voucher['code']), 'C2. Voucher has generated code: ' . ($voucher['code'] ?? 'none'));

        // C3. Voucher has correct discount type
        assert_test($voucher['discount_type'] === DiscountType::Percentage->value, 'C3. Discount type is percentage');

        // C4. Voucher validation works
        $validation = $voucherService->validateVoucher($voucher['code']);
        assert_test(!is_wp_error($validation), 'C4. Voucher validation passes');

        // C5. Calculate percentage discount
        $subtotal = Money::eur(100.00);
        $discount = $voucherService->calculateDiscount($voucher, $subtotal);
        assert_test($discount->inCents() === 2500, 'C5. 25% of 100 = 25.00 (got: ' . $discount->format() . ')');

        // C6. Calculate fixed discount
        $fixedVoucherId = $voucherService->createVoucher([
            'discount_type' => DiscountType::Fixed->value,
            'discount_value' => 5000, // 50.00 in cents
            'usage_limit' => 1,
        ]);
        $fixedVoucher = $voucherService->getVoucher($fixedVoucherId);
        $fixedDiscount = $voucherService->calculateDiscount($fixedVoucher, $subtotal);
        assert_test($fixedDiscount->inCents() === 5000, 'C6. Fixed 50.00 discount (got: ' . $fixedDiscount->format() . ')');

        // C7. Calculate full discount
        $fullVoucherId = $voucherService->createVoucher([
            'discount_type' => DiscountType::Full->value,
            'usage_limit' => 1,
        ]);
        $fullVoucher = $voucherService->getVoucher($fullVoucherId);
        $fullDiscount = $voucherService->calculateDiscount($fullVoucher, $subtotal);
        assert_test($fullDiscount->inCents() === 10000, 'C7. Full discount = subtotal (got: ' . $fullDiscount->format() . ')');

        // Cleanup
        wp_delete_post($voucherId, true);
        wp_delete_post($fixedVoucherId, true);
        wp_delete_post($fullVoucherId, true);
    }
} catch (Exception $e) {
    assert_test(false, 'C. Voucher tests failed: ' . $e->getMessage());
}

echo "\n";

// ==========================================
// D. EDITION SERVICE
// ==========================================
echo "D. Edition Service...\n";

try {
    $editionService = ntdst_get(EditionService::class);

    // Find an existing edition from seed data
    global $wpdb;
    $editionId = (int) $wpdb->get_var(
        "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'vad_edition' AND post_status = 'publish' LIMIT 1"
    );

    if ($editionId) {
        // D1. Get edition
        $edition = $editionService->getEdition($editionId);
        assert_test(!is_wp_error($edition), 'D1. Get edition succeeds');

        // D2. Edition exists check
        assert_test($editionService->exists($editionId), 'D2. Edition exists returns true');

        // D3. Non-existent edition
        assert_test(!$editionService->exists(999999), 'D3. Non-existent edition returns false');

        // D4. Get capacity
        $capacity = $editionService->getCapacity($editionId);
        assert_test($capacity >= 0, 'D4. Get capacity returns int: ' . $capacity);

        // D5. Get price
        $price = $editionService->getPrice($editionId);
        assert_test($price instanceof Money, 'D5. Get price returns Money object: ' . $price->format());

        // D6. Has available spots
        $hasSpots = $editionService->hasAvailableSpots($editionId);
        assert_test(is_bool($hasSpots), 'D6. hasAvailableSpots returns bool: ' . ($hasSpots ? 'yes' : 'no'));

        // D7. Can enroll check
        $canEnroll = $editionService->canEnroll($editionId);
        assert_test(is_bool($canEnroll), 'D7. canEnroll returns bool: ' . ($canEnroll ? 'yes' : 'no'));

    } else {
        skip_test('D. No edition data - run seed.php first');
    }
} catch (Exception $e) {
    assert_test(false, 'D. Edition tests failed: ' . $e->getMessage());
}

echo "\n";

// ==========================================
// E. ATTENDANCE SERVICE
// ==========================================
echo "E. Attendance Service...\n";

try {
    $attendanceService = ntdst_get(AttendanceService::class);

    // Find a session with edition_id
    global $wpdb;
    $sessionId = (int) $wpdb->get_var(
        "SELECT p.ID FROM {$wpdb->posts} p
         INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = 'edition_id'
         WHERE p.post_type = 'vad_session' AND pm.meta_value IS NOT NULL AND pm.meta_value != ''
         LIMIT 1"
    );

    $testUserId = (int) $wpdb->get_var("SELECT ID FROM {$wpdb->users} WHERE ID > 1 LIMIT 1");

    if ($sessionId && $testUserId) {
        // E1. Mark present
        $result = $attendanceService->markPresent($sessionId, $testUserId);
        assert_test(!is_wp_error($result), 'E1. markPresent succeeds');

        // E2. Check is present
        $isPresent = $attendanceService->isPresent($sessionId, $testUserId);
        assert_test($isPresent === true, 'E2. User is marked present');

        // E3. Get attendance status (returns AttendanceStatus enum)
        $status = $attendanceService->getStatus($sessionId, $testUserId);
        assert_test($status?->value === 'present', 'E3. Status is "present" (got: ' . ($status?->value ?? 'null') . ')');

        // E4. Mark absent
        $result = $attendanceService->markAbsent($sessionId, $testUserId);
        $status = $attendanceService->getStatus($sessionId, $testUserId);
        assert_test($status?->value === 'absent', 'E4. Status updated to "absent"');

        // E5. Mark excused
        $result = $attendanceService->markExcused($sessionId, $testUserId);
        $status = $attendanceService->getStatus($sessionId, $testUserId);
        assert_test($status?->value === 'excused', 'E5. Status updated to "excused"');

        // Cleanup
        $wpdb->delete($wpdb->prefix . 'vad_attendance', [
            'session_id' => $sessionId,
            'user_id' => $testUserId
        ]);
    } else {
        skip_test('E. No session/user data - run seed.php first');
    }
} catch (Exception $e) {
    assert_test(false, 'E. Attendance tests failed: ' . $e->getMessage());
}

echo "\n";

// ==========================================
// F. COMPLETION SERVICE
// ==========================================
echo "F. Completion Service...\n";

try {
    $completionService = ntdst_get(CompletionService::class);

    // Find an edition
    global $wpdb;
    $editionId = (int) $wpdb->get_var(
        "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'vad_edition' AND post_status = 'publish' LIMIT 1"
    );
    $testUserId = (int) $wpdb->get_var("SELECT ID FROM {$wpdb->users} WHERE ID > 1 LIMIT 1");

    if ($editionId && $testUserId) {
        // F1. Get completion mode (returns CompletionMode enum)
        $mode = $completionService->getCompletionMode($editionId);
        assert_test($mode !== null, 'F1. Completion mode is valid: ' . $mode->value);

        // F2. Check completion status (edition first, then user)
        $isComplete = $completionService->isComplete($editionId, $testUserId);
        assert_test(is_bool($isComplete), 'F2. isComplete returns bool');

        // F3. Get progress (edition first, then user)
        $progress = $completionService->getProgress($editionId, $testUserId);
        assert_test(is_array($progress), 'F3. getProgress returns array');
        assert_test(isset($progress['attended']), 'F3a. Progress has attended count');
        assert_test(isset($progress['total_sessions']), 'F3b. Progress has total_sessions count');
    } else {
        skip_test('F. No edition/user data - run seed.php first');
    }
} catch (Exception $e) {
    assert_test(false, 'F. Completion tests failed: ' . $e->getMessage());
}

echo "\n";

// ==========================================
// G. TRAJECTORY SERVICE
// ==========================================
echo "G. Trajectory Service...\n";

try {
    $trajectoryService = ntdst_get(TrajectoryService::class);

    // Find a trajectory
    global $wpdb;
    $trajectoryId = (int) $wpdb->get_var(
        "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'vad_trajectory' AND post_status = 'publish' LIMIT 1"
    );

    if ($trajectoryId) {
        // G1. Get trajectory
        $trajectory = $trajectoryService->getTrajectory($trajectoryId);
        assert_test($trajectory !== null && isset($trajectory['id']), 'G1. Get trajectory succeeds');

        if ($trajectory) {
            // G2. Mode is in trajectory data
            $mode = $trajectory['mode'] ?? null;
            assert_test(in_array($mode, ['cohort', 'open']), 'G2. Mode is valid: ' . $mode);

            // G3. Status is in trajectory data
            $status = $trajectory['status'] ?? null;
            assert_test(in_array($status, ['open', 'closed', 'full', 'archived', 'draft']), 'G3. Status is valid: ' . $status);

            // G4. Check enrollment open
            $isOpen = $trajectoryService->isEnrollmentOpen($trajectoryId);
            assert_test(is_bool($isOpen), 'G4. isEnrollmentOpen returns bool');
        }
    } else {
        skip_test('G. No trajectory data - run seed.php first');
    }
} catch (Exception $e) {
    assert_test(false, 'G. Trajectory tests failed: ' . $e->getMessage());
}

echo "\n";

// ==========================================
// RESULTS
// ==========================================
$passed = $GLOBALS['passed'];
$failed = $GLOBALS['failed'];
$skipped = $GLOBALS['skipped'];
$total = $passed + $failed;

echo "=== Results ===\n";
echo "Passed: {$passed}\n";
echo "Failed: {$failed}\n";
echo "Skipped: {$skipped}\n";
echo "Total: {$total}\n";

if ($failed === 0) {
    echo "\nALL TESTS PASSED!\n";
} else {
    echo "\nSOME TESTS FAILED\n";
}
