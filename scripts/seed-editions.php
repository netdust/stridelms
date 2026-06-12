<?php
/**
 * Seed test editions for development.
 *
 * Creates test editions linked to existing or new courses.
 * This is a focused script for V1 Slice 1 development.
 *
 * Run: ddev exec wp eval-file scripts/seed-editions.php
 */

if (!defined('ABSPATH')) {
    echo "This script must be run via WP-CLI: ddev exec wp eval-file scripts/seed-editions.php\n";
    exit(1);
}

// Prevent accidental runs in production
if (defined('WP_ENV') && WP_ENV === 'production') {
    echo "ERROR: Cannot run seed script in production!\n";
    exit(1);
}

// Load autoloader
require_once ABSPATH . '../app/mu-plugins/stride-core/autoload.php';

use Stride\Domain\OfferingStatus;
use Stride\Domain\RegistrationStatus;
use Stride\Modules\Edition\EditionCPT;
use Stride\Modules\Enrollment\RegistrationTable;
use Stride\Modules\Enrollment\RegistrationRepository;

echo "\n=== Stride Edition Seeder ===\n\n";

// Ensure registration table exists
if (!RegistrationTable::exists()) {
    echo "Creating registration table...\n";
    RegistrationTable::create();
    echo "  + Registration table created\n";
}

// Get or create a test course
$courses = get_posts([
    'post_type' => 'sfwd-courses',
    'posts_per_page' => 1,
    'post_status' => 'publish',
]);

if (empty($courses)) {
    $courseId = wp_insert_post([
        'post_title' => 'Test Cursus - Basisvorming',
        'post_type' => 'sfwd-courses',
        'post_status' => 'publish',
        'post_content' => '<p>Dit is een testcursus voor ontwikkeling.</p>',
    ]);

    if (is_wp_error($courseId)) {
        echo "ERROR: Failed to create test course: {$courseId->get_error_message()}\n";
        exit(1);
    }

    // Mark as seed data
    update_post_meta($courseId, '_stride_seed_data', true);
    echo "Created test course: {$courseId}\n";
} else {
    $courseId = $courses[0]->ID;
    echo "Using existing course: {$courseId} ({$courses[0]->post_title})\n";
}

// Get the data model
$model = ntdst_data()->get(EditionCPT::POST_TYPE);

if (!$model) {
    echo "ERROR: Edition CPT not registered. Is stride-core plugin active?\n";
    exit(1);
}

// Define test editions
$editions = [
    [
        'title' => 'Basisvorming - Maart 2026',
        'course_id' => $courseId,
        'start_date' => '2026-03-15',
        'end_date' => '2026-03-16',
        'capacity' => 20,
        'price' => 250.00,
        'price_non_member' => 350.00,
        'venue' => 'VAD Brussel',
        'speakers' => 'Jan De Trainer',
        'status' => OfferingStatus::Open->value,
    ],
    [
        'title' => 'Basisvorming - April 2026',
        'course_id' => $courseId,
        'start_date' => '2026-04-10',
        'end_date' => '2026-04-11',
        'capacity' => 15,
        'price' => 250.00,
        'price_non_member' => 350.00,
        'venue' => 'VAD Gent',
        'speakers' => 'Marie Docent',
        'status' => OfferingStatus::Open->value,
    ],
    [
        'title' => 'Basisvorming - Mei 2026 (Volzet)',
        'course_id' => $courseId,
        'start_date' => '2026-05-20',
        'end_date' => '2026-05-21',
        'capacity' => 5,
        'price' => 250.00,
        'price_non_member' => 350.00,
        'venue' => 'VAD Antwerpen',
        'speakers' => 'Prof. Veilig',
        'status' => OfferingStatus::Full->value,
    ],
    [
        'title' => 'Basisvorming - Juni 2026 (Geannuleerd)',
        'course_id' => $courseId,
        'start_date' => '2026-06-10',
        'end_date' => '2026-06-11',
        'capacity' => 20,
        'price' => 250.00,
        'price_non_member' => 350.00,
        'venue' => 'VAD Rotterdam',
        'speakers' => '',
        'status' => OfferingStatus::Cancelled->value,
    ],
];

echo "\nCreating editions...\n";
$createdEditions = [];

foreach ($editions as $editionData) {
    // Check if edition already exists (by start_date and course_id)
    // Note: first() returns normalized object with 'id' and 'title' keys
    $existing = $model->where('post_status', 'publish')
        ->where('start_date', $editionData['start_date'])
        ->where('course_id', $editionData['course_id'])
        ->first();

    if ($existing) {
        // Model first() returns object with normalized keys: id, title
        echo "  - Edition already exists: {$existing->title} (ID: {$existing->id})\n";
        $createdEditions[] = (int) $existing->id;
        continue;
    }

    // Create edition using the data model
    // Note: NTDST Data model expects 'title' not 'post_title'
    // create() returns WP_Post object with ID and post_title
    $result = $model->create([
        'title' => $editionData['title'],
        'post_status' => 'publish',
        'course_id' => $editionData['course_id'],
        'start_date' => $editionData['start_date'],
        'end_date' => $editionData['end_date'],
        'capacity' => $editionData['capacity'],
        'price' => $editionData['price'],
        'price_non_member' => $editionData['price_non_member'],
        'venue' => $editionData['venue'],
        'speakers' => $editionData['speakers'],
        'status' => $editionData['status'],
    ]);

    if (is_wp_error($result)) {
        echo "  ! Error creating edition: " . $result->get_error_message() . "\n";
        continue;
    }

    // Mark as seed data
    update_post_meta($result->ID, '_stride_seed_data', true);

    $createdEditions[] = $result->ID;
    echo "  + Created edition: {$result->post_title} (ID: {$result->ID})\n";
}

// Create test registrations if we have users
echo "\nCreating test registrations...\n";

$testUsers = get_users([
    'meta_key' => '_stride_seed_data',
    'meta_value' => '1',
    'number' => 3,
]);

if (empty($testUsers)) {
    // Try to get any subscriber users
    $testUsers = get_users([
        'role' => 'subscriber',
        'number' => 3,
    ]);
}

if (!empty($testUsers) && !empty($createdEditions)) {
    $regRepo = new RegistrationRepository();

    // Filter to only open editions
    $openEditions = array_filter($createdEditions, function ($editionId) use ($model) {
        $status = $model->getMeta($editionId, 'status');
        return $status === OfferingStatus::Open->value;
    });

    if (!empty($openEditions)) {
        $openEditions = array_values($openEditions);

        foreach ($testUsers as $index => $user) {
            // Register each user to a different open edition
            $editionId = $openEditions[$index % count($openEditions)];

            // Check if already registered
            if ($regRepo->exists($user->ID, $editionId)) {
                echo "  - User {$user->user_login} already registered for edition {$editionId}\n";
                continue;
            }

            $result = $regRepo->create([
                'user_id' => $user->ID,
                'edition_id' => $editionId,
                'status' => RegistrationStatus::Confirmed->value,
                'enrollment_path' => RegistrationRepository::PATH_INDIVIDUAL,
                'notes' => 'Seeded registration',
            ]);

            if (is_wp_error($result)) {
                echo "  ! Failed to register {$user->user_login}: {$result->get_error_message()}\n";
                continue;
            }

            $edition = get_post($editionId);
            echo "  + Registered {$user->user_login} for: {$edition->post_title}\n";

            // Grant LearnDash course access if function exists
            if (function_exists('ld_update_course_access')) {
                ld_update_course_access($user->ID, $courseId, false);
                echo "    + Granted LearnDash access to course {$courseId}\n";
            }
        }
    } else {
        echo "  - No open editions available for registrations\n";
    }
} else {
    echo "  - No test users found, skipping registrations\n";
    echo "    Run 'ddev exec wp eval-file scripts/seed.php' first to create test users\n";
}

echo "\n=== Done! ===\n\n";
echo "Created/found " . count($createdEditions) . " editions.\n";
echo "\nEdition IDs: " . implode(', ', $createdEditions) . "\n";
echo "\nTo view editions in admin: /wp/wp-admin/edit.php?post_type=vad_edition\n";
echo "To remove seed data: ddev exec bash -c 'FORCE_UNSEED=1 wp eval-file scripts/unseed.php'\n\n";
