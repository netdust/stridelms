<?php
/**
 * Smoke Test: Meta Access Patterns
 *
 * Validates that the refactored meta access using Data Manager works correctly.
 * Tests: VoucherRepository, CompletionService, AttendanceRepository, EditionRepository
 *
 * Run with: ddev exec wp eval-file scripts/smoke-test-meta-access.php
 */

if (!defined('ABSPATH')) {
    echo "This script must be run via WP-CLI: ddev exec wp eval-file scripts/smoke-test-meta-access.php\n";
    exit(1);
}

use Stride\Domain\AttendanceStatus;
use Stride\Domain\CompletionMode;
use Stride\Domain\VoucherStatus;
use Stride\Modules\Attendance\AttendanceRepository;
use Stride\Modules\Completion\CompletionService;
use Stride\Modules\Edition\EditionRepository;
use Stride\Modules\Edition\SessionService;
use Stride\Modules\Invoicing\VoucherRepository;

class MetaAccessSmokeTest
{
    private int $passed = 0;
    private int $failed = 0;
    private array $created = [
        'editions' => [],
        'sessions' => [],
        'vouchers' => [],
        'attendance' => [],
    ];

    public function run(): void
    {
        echo "\n=== Meta Access Smoke Test ===\n";
        echo "Validates Data Manager access patterns after refactoring\n\n";

        wp_set_current_user(1);

        try {
            $this->testEditionRepositoryMeta();
            $this->testVoucherRepositoryMeta();
            $this->testCompletionServiceMeta();
            $this->testAttendanceRepositoryMeta();
            $this->testDataManagerDirectAccess();
        } catch (Exception $e) {
            echo "\n[FATAL] " . $e->getMessage() . "\n";
            echo $e->getTraceAsString() . "\n";
        } finally {
            $this->cleanup();
        }

        echo "\n=== Results ===\n";
        echo "Passed: {$this->passed}\n";
        echo "Failed: {$this->failed}\n";
        echo ($this->failed === 0 ? "ALL SMOKE TESTS PASSED!\n" : "SOME TESTS FAILED!\n");

        exit($this->failed === 0 ? 0 : 1);
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
    // Test 1: EditionRepository Meta Access
    // ========================================

    private function testEditionRepositoryMeta(): void
    {
        echo "1. Testing EditionRepository meta access...\n";

        $repo = ntdst_get(EditionRepository::class);

        // Create edition with Data Manager
        $editionId = wp_insert_post([
            'post_type' => 'vad_edition',
            'post_title' => 'Smoke Test Edition ' . time(),
            'post_status' => 'publish',
        ]);
        $this->created['editions'][] = $editionId;

        // Set meta via Data Manager
        $model = ntdst_data()->get('vad_edition');
        $model->updateMetaBatch($editionId, [
            'price' => 299.00,
            'venue' => 'Amsterdam Conference Center',
            'capacity' => 25,
            'status' => 'open',
        ]);

        // Read via repository->getField()
        $price = $repo->getField($editionId, 'price');
        $venue = $repo->getField($editionId, 'venue');
        $capacity = $repo->getField($editionId, 'capacity');

        $this->assert(
            (float)$price === 299.00,
            "EditionRepository->getField('price') returns 299.00 (got: " . var_export($price, true) . ")"
        );

        $this->assert(
            $venue === 'Amsterdam Conference Center',
            "EditionRepository->getField('venue') returns correct value"
        );

        $this->assert(
            (int)$capacity === 25,
            "EditionRepository->getField('capacity') returns 25 (got: " . var_export($capacity, true) . ")"
        );

        // Verify direct get_post_meta with unprefixed key returns EMPTY (proving prefix is needed)
        $directPrice = get_post_meta($editionId, 'price', true);
        $prefixedPrice = get_post_meta($editionId, '_ntdst_price', true);

        $this->assert(
            empty($directPrice),
            "Direct get_post_meta('price') returns empty (prefix required)"
        );

        $this->assert(
            (float)$prefixedPrice === 299.00,
            "get_post_meta('_ntdst_price') returns 299.00 (prefixed key works)"
        );

        echo "\n";
    }

    // ========================================
    // Test 2: VoucherRepository Meta Access
    // ========================================

    private function testVoucherRepositoryMeta(): void
    {
        echo "2. Testing VoucherRepository meta access...\n";

        $repo = ntdst_get(VoucherRepository::class);

        // Create voucher
        $voucherId = wp_insert_post([
            'post_type' => 'vad_voucher',
            'post_title' => 'SMOKETEST' . time(),
            'post_status' => 'publish',
        ]);
        $this->created['vouchers'][] = $voucherId;

        // Set meta via Data Manager
        $model = ntdst_data()->get('vad_voucher');
        $model->updateMetaBatch($voucherId, [
            'code' => 'SMOKETEST123',
            'discount_type' => 'percentage',
            'discount_value' => 15,
            'status' => VoucherStatus::Active->value,
            'max_uses' => 10,
            'used_count' => 2,
        ]);

        // Read via repository->getField()
        $code = $repo->getField($voucherId, 'code');
        $discountType = $repo->getField($voucherId, 'discount_type');
        $discountValue = $repo->getField($voucherId, 'discount_value');
        $status = $repo->getField($voucherId, 'status');
        $maxUses = $repo->getField($voucherId, 'max_uses');
        $usedCount = $repo->getField($voucherId, 'used_count');

        $this->assert(
            $code === 'SMOKETEST123',
            "VoucherRepository->getField('code') returns correct value"
        );

        $this->assert(
            $discountType === 'percentage',
            "VoucherRepository->getField('discount_type') returns 'percentage'"
        );

        $this->assert(
            (int)$discountValue === 15,
            "VoucherRepository->getField('discount_value') returns 15"
        );

        $this->assert(
            $status === VoucherStatus::Active->value,
            "VoucherRepository->getField('status') returns 'active'"
        );

        // Test findByCode
        $found = $repo->findByCode('SMOKETEST123');

        $this->assert(
            $found !== null && (int)$found['id'] === $voucherId,
            "VoucherRepository->findByCode() finds the voucher"
        );

        echo "\n";
    }

    // ========================================
    // Test 3: CompletionService Meta Access
    // ========================================

    private function testCompletionServiceMeta(): void
    {
        echo "3. Testing CompletionService meta access...\n";

        $service = ntdst_get(CompletionService::class);

        // Create edition
        $editionId = wp_insert_post([
            'post_type' => 'vad_edition',
            'post_title' => 'Completion Test Edition ' . time(),
            'post_status' => 'publish',
        ]);
        $this->created['editions'][] = $editionId;

        // Set completion mode via Data Manager
        $model = ntdst_data()->get('vad_edition');
        $model->updateMetaBatch($editionId, [
            'completion_mode' => CompletionMode::Percentage->value,
            'completion_threshold' => 80,
        ]);

        // Read via service methods
        $mode = $service->getCompletionMode($editionId);
        $threshold = $service->getCompletionThreshold($editionId);

        $this->assert(
            $mode === CompletionMode::Percentage,
            "CompletionService->getCompletionMode() returns Percentage enum"
        );

        $this->assert(
            $threshold === 80,
            "CompletionService->getCompletionThreshold() returns 80 (got: {$threshold})"
        );

        // Test setting via service
        $service->setCompletionMode($editionId, CompletionMode::Count);
        $service->setCompletionThreshold($editionId, 5);

        $newMode = $service->getCompletionMode($editionId);
        $newThreshold = $service->getCompletionThreshold($editionId);

        $this->assert(
            $newMode === CompletionMode::Count,
            "After setCompletionMode(), mode is Count"
        );

        $this->assert(
            $newThreshold === 5,
            "After setCompletionThreshold(), threshold is 5 (got: {$newThreshold})"
        );

        // Verify old _vad_ prefix does NOT work
        $oldPrefixMode = get_post_meta($editionId, '_vad_completion_mode', true);

        $this->assert(
            empty($oldPrefixMode),
            "Old _vad_completion_mode prefix returns empty (migrated away)"
        );

        echo "\n";
    }

    // ========================================
    // Test 4: AttendanceRepository Meta Access
    // ========================================

    private function testAttendanceRepositoryMeta(): void
    {
        echo "4. Testing AttendanceRepository meta access...\n";

        $attendanceRepo = ntdst_get(AttendanceRepository::class);
        $sessionService = ntdst_get(SessionService::class);

        // Create edition
        $editionId = wp_insert_post([
            'post_type' => 'vad_edition',
            'post_title' => 'Attendance Test Edition ' . time(),
            'post_status' => 'publish',
        ]);
        $this->created['editions'][] = $editionId;

        // Create session linked to edition via Data Manager
        $sessionId = wp_insert_post([
            'post_type' => 'vad_session',
            'post_title' => 'Attendance Test Session ' . time(),
            'post_status' => 'publish',
        ]);
        $this->created['sessions'][] = $sessionId;

        // Set session's edition_id via Data Manager
        $sessionModel = ntdst_data()->get('vad_session');
        $sessionModel->updateMetaBatch($sessionId, [
            'edition_id' => $editionId,
            'date' => date('Y-m-d', strtotime('+7 days')),
            'start_time' => '09:00',
            'end_time' => '12:00',
        ]);

        // Verify session->edition lookup works
        $lookupEditionId = (int)$sessionModel->getMeta($sessionId, 'edition_id');

        $this->assert(
            $lookupEditionId === $editionId,
            "Session->edition_id lookup via Data Manager works (got: {$lookupEditionId}, expected: {$editionId})"
        );

        // Record attendance (this should auto-lookup edition_id from session)
        $userId = get_current_user_id();
        $result = $attendanceRepo->record($sessionId, $userId, AttendanceStatus::Present);

        $this->assert(
            !is_wp_error($result) && is_int($result) && $result > 0,
            "AttendanceRepository->record() succeeds with auto edition lookup"
        );

        if (!is_wp_error($result)) {
            $this->created['attendance'][] = $result;

            // Verify the attendance record has correct edition_id
            $record = $attendanceRepo->find($result);

            $this->assert(
                $record !== null && (int)$record->edition_id === $editionId,
                "Attendance record has correct edition_id from session lookup"
            );
        }

        // Verify old _vad_edition_id prefix does NOT work
        $oldPrefixEdition = get_post_meta($sessionId, '_vad_edition_id', true);

        $this->assert(
            empty($oldPrefixEdition),
            "Old _vad_edition_id prefix returns empty (migrated away)"
        );

        echo "\n";
    }

    // ========================================
    // Test 5: Data Manager Direct Access
    // ========================================

    private function testDataManagerDirectAccess(): void
    {
        echo "5. Testing Data Manager direct access patterns...\n";

        // Test that all registered CPTs have models
        $cptList = ['vad_edition', 'vad_session', 'vad_voucher', 'vad_quote', 'vad_trajectory'];

        foreach ($cptList as $postType) {
            $model = ntdst_data()->get($postType);
            $this->assert(
                $model !== null,
                "Data Manager has model for '{$postType}'"
            );
        }

        // Test getMeta with default value
        $model = ntdst_data()->get('vad_edition');
        $nonExistent = $model->getMeta(999999, 'non_existent_field', 'default_value');

        $this->assert(
            $nonExistent === 'default_value',
            "getMeta() returns default value for non-existent field"
        );

        // Test updateMetaBatch returns true
        $editionId = $this->created['editions'][0] ?? null;
        if ($editionId) {
            $updateResult = $model->updateMetaBatch($editionId, [
                'notes' => ['test' => 'value'],
            ]);

            $this->assert(
                $updateResult === true,
                "updateMetaBatch() returns true on success"
            );

            // Verify JSON field works
            $notes = $model->getMeta($editionId, 'notes');

            $this->assert(
                is_array($notes) && ($notes['test'] ?? null) === 'value',
                "JSON field stores and retrieves correctly"
            );
        }

        echo "\n";
    }

    // ========================================
    // Cleanup
    // ========================================

    private function cleanup(): void
    {
        echo "Cleaning up...\n";

        global $wpdb;

        // Delete attendance records
        $attendanceTable = $wpdb->prefix . 'vad_attendance';
        foreach ($this->created['attendance'] as $id) {
            $wpdb->delete($attendanceTable, ['id' => $id], ['%d']);
        }

        // Delete sessions
        foreach ($this->created['sessions'] as $id) {
            wp_delete_post($id, true);
        }

        // Delete vouchers
        foreach ($this->created['vouchers'] as $id) {
            wp_delete_post($id, true);
        }

        // Delete editions
        foreach ($this->created['editions'] as $id) {
            wp_delete_post($id, true);
        }

        echo "  Done.\n";
    }
}

// Run
$test = new MetaAccessSmokeTest();
$test->run();
