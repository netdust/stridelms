<?php
/**
 * Test Complete Enrollment Flow
 *
 * Run: ddev exec wp eval-file scripts/test-enrollment-flow.php
 */

use Stride\Modules\Enrollment\EnrollmentService;
use Stride\Modules\Enrollment\RegistrationRepository;

echo "=== Testing Complete Enrollment Flow ===\n\n";

// Step 1: Get or create test user
$user = get_user_by('email', 'test@stride.test');
if (!$user) {
    $userId = wp_create_user('stride_test', 'testpass123', 'test@stride.test');
    if (is_wp_error($userId)) {
        echo "Error creating user: " . $userId->get_error_message() . "\n";
        exit(1);
    }
    echo "Created test user: {$userId}\n";
} else {
    $userId = $user->ID;
    echo "Using existing user: {$userId}\n";
}

// Step 2: Get first open edition
$model = ntdst_data()->get('vad_edition');
$editions = $model->where('status', 'open')->where('post_status', 'publish')->limit(1)->withMeta()->get();

if (empty($editions)) {
    echo "No open editions found. Run scripts/seed-editions.php first.\n";
    exit(1);
}

$edition = $editions[0];
$editionId = $edition['id'];
echo "Found edition: {$editionId} - {$edition['title']}\n";

// Step 3: Get enrollment service
$enrollment = ntdst_get(EnrollmentService::class);

// Check if already enrolled
if ($enrollment->isEnrolled($userId, $editionId)) {
    echo "User already enrolled in this edition.\n";

    // Get registration details
    $regRepo = ntdst_get(RegistrationRepository::class);
    $existing = $regRepo->findByUserAndEdition($userId, $editionId);
    if ($existing) {
        echo "Existing registration ID: {$existing->id}\n";
        echo "Status: {$existing->status}\n";
    }
} else {
    // Step 4: Enroll user
    echo "\nEnrolling user...\n";
    $result = $enrollment->enroll($userId, $editionId);

    if (is_wp_error($result)) {
        echo 'Enrollment error: ' . $result->get_error_message() . "\n";
        exit(1);
    }

    echo "Enrollment successful! Registration ID: {$result}\n";
}

// Step 5: Verify enrollment
$isEnrolled = $enrollment->isEnrolled($userId, $editionId);
echo "\nVerification:\n";
echo "Is enrolled: " . ($isEnrolled ? 'YES' : 'NO') . "\n";

// Step 6: Check LearnDash access
$courseId = get_post_meta($editionId, 'course_id', true);
if ($courseId) {
    $hasAccess = sfwd_lms_has_access($courseId, $userId);
    echo "Has LearnDash access: " . ($hasAccess ? 'YES' : 'NO') . "\n";
}

echo "\n=== Flow Test Complete ===\n";
