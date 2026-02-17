<?php
/**
 * Stride V1 - Admin Dashboard Tests
 *
 * Tests admin API endpoints and service registration.
 *
 * Run with: ddev exec wp eval-file scripts/test-admin-dashboard.php
 */

if (!defined('ABSPATH')) {
    echo "Run via WP-CLI: ddev exec wp eval-file scripts/test-admin-dashboard.php\n";
    exit(1);
}

use Stride\Admin\AdminDashboardService;
use Stride\Admin\AdminAPIController;

echo "=== Stride V1 - Admin Dashboard Tests ===" . PHP_EOL . PHP_EOL;

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
    // === A. SERVICE REGISTRATION ===
    echo "A. Service Registration..." . PHP_EOL;

    // A1. AdminDashboardService exists
    $dashboardService = ntdst_get(AdminDashboardService::class);
    assert_test($dashboardService !== null, 'A1. AdminDashboardService registered');

    // A2. AdminAPIController exists
    $apiController = ntdst_get(AdminAPIController::class);
    assert_test($apiController !== null, 'A2. AdminAPIController registered');

    echo PHP_EOL;

    // === B. API ENDPOINTS ===
    echo "B. API Endpoints..." . PHP_EOL;

    // B1. Stats endpoint
    $request = new WP_REST_Request('GET', '/stride/v1/admin/stats');
    $response = rest_do_request($request);
    assert_test($response->get_status() === 200, 'B1. Stats endpoint returns 200');

    $stats = $response->get_data();
    assert_test(isset($stats['upcomingEditions']), 'B1a. Stats has upcomingEditions');
    assert_test(isset($stats['totalRegistrations']), 'B1b. Stats has totalRegistrations');
    assert_test(isset($stats['pendingQuotes']), 'B1c. Stats has pendingQuotes');
    assert_test(isset($stats['todaySessions']), 'B1d. Stats has todaySessions');

    // B2. Editions endpoint
    $request = new WP_REST_Request('GET', '/stride/v1/admin/editions');
    $response = rest_do_request($request);
    assert_test($response->get_status() === 200, 'B2. Editions endpoint returns 200');

    $editions = $response->get_data();
    assert_test(isset($editions['items']), 'B2a. Editions has items array');
    assert_test(isset($editions['total']), 'B2b. Editions has total count');
    assert_test(isset($editions['totalPages']), 'B2c. Editions has totalPages count');

    // B3. Quotes endpoint
    $request = new WP_REST_Request('GET', '/stride/v1/admin/quotes');
    $response = rest_do_request($request);
    assert_test($response->get_status() === 200, 'B3. Quotes endpoint returns 200');

    $quotes = $response->get_data();
    assert_test(isset($quotes['items']), 'B3a. Quotes has items array');

    echo PHP_EOL;

    // === C. PERMISSION CHECK ===
    echo "C. Permission Check..." . PHP_EOL;

    // C1. Anonymous user cannot access
    wp_set_current_user(0);
    $request = new WP_REST_Request('GET', '/stride/v1/admin/stats');
    $response = rest_do_request($request);
    assert_test($response->get_status() === 401, 'C1. Anonymous user blocked');

    // Restore admin user
    wp_set_current_user(1);

    echo PHP_EOL;

    // === D. ATTENDANCE API ===
    echo "D. Attendance API..." . PHP_EOL;

    // Create test data if we have seed data
    global $wpdb;
    // Find a session that has an edition_id set
    $testSession = $wpdb->get_var(
        "SELECT p.ID FROM {$wpdb->posts} p
         INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_vad_edition_id'
         WHERE p.post_type = 'vad_session' AND pm.meta_value IS NOT NULL
         LIMIT 1"
    );
    $testUser = $wpdb->get_var("SELECT ID FROM {$wpdb->users} WHERE ID > 1 LIMIT 1");

    if ($testSession && $testUser) {
        // D1. Mark present
        $request = new WP_REST_Request('POST', '/stride/v1/admin/attendance');
        $request->set_param('session_id', (int) $testSession);
        $request->set_param('user_id', (int) $testUser);
        $request->set_param('status', 'present');
        $response = rest_do_request($request);
        assert_test($response->get_status() === 200, 'D1. Mark attendance returns 200');

        $data = $response->get_data();
        assert_test(isset($data['success']) && $data['success'] === true, 'D1a. Mark attendance succeeds');

        // D2. Check attendance recorded
        $record = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}vad_attendance WHERE session_id = %d AND user_id = %d",
            $testSession,
            $testUser
        ));
        assert_test($record !== null, 'D2. Attendance record created');
        if ($record) {
            assert_test($record->status === 'present', 'D2a. Status is present');
        }

        // Cleanup
        $wpdb->delete($wpdb->prefix . 'vad_attendance', [
            'session_id' => $testSession,
            'user_id' => $testUser
        ]);
    } else {
        echo "  [SKIP] D. No seed data - run seed.php first" . PHP_EOL;
    }

    echo PHP_EOL;

} catch (Exception $e) {
    echo "[FATAL] " . $e->getMessage() . PHP_EOL;
    echo $e->getTraceAsString() . PHP_EOL;
}

$passed = $GLOBALS['passed'];
$failed = $GLOBALS['failed'];

echo "=== Results ===" . PHP_EOL;
echo "Passed: {$passed}" . PHP_EOL;
echo "Failed: {$failed}" . PHP_EOL;
echo ($failed === 0 ? "ALL TESTS PASSED!" : "SOME TESTS FAILED") . PHP_EOL;
