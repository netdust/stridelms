<?php
/**
 * Test DashboardService with Edition Model
 */

echo "\n=== Testing DashboardService ===\n\n";

$dashboard = stride_service(\stride\services\frontend\DashboardService::class);
if (!$dashboard) {
    echo "FAIL: Could not get DashboardService\n";
    exit(1);
}
echo "OK: DashboardService instantiated\n";

// Test with a seed user if exists
$user = get_user_by("email", "student1@seed.test");
if ($user) {
    echo "\nTesting with user: {$user->user_email}\n";

    $courses = $dashboard->getUserCourses($user->ID);
    echo "OK: getUserCourses returned " . count($courses) . " courses\n";

    $trajectories = $dashboard->getUserTrajectories($user->ID);
    echo "OK: getUserTrajectories returned " . count($trajectories) . " trajectories\n";

    $dates = $dashboard->getUpcomingDates($user->ID, 5);
    echo "OK: getUpcomingDates returned " . count($dates) . " dates\n";

    $stats = $dashboard->getDashboardStats($user->ID);
    echo "OK: getDashboardStats returned stats\n";
    echo "    - total_courses: {$stats['total_courses']}\n";
    echo "    - completed_courses: {$stats['completed_courses']}\n";
    echo "    - in_progress_courses: {$stats['in_progress_courses']}\n";
    echo "    - total_trajectories: {$stats['total_trajectories']}\n";

    // Test single trajectory if available
    if (!empty($trajectories)) {
        $traj = $trajectories[0];
        $single = $dashboard->getTrajectoryDetail($traj['id'], $user->ID);
        echo "\nOK: getTrajectoryDetail returned for '{$single['title']}'\n";
        echo "    - mode: {$single['mode']}\n";
        echo "    - progress: {$single['progress']}%\n";
        echo "    - mandatory_modules: " . count($single['mandatory_modules']) . "\n";
        echo "    - elective_modules: " . count($single['elective_modules']) . "\n";
    }

} else {
    echo "INFO: No seed user found (student1@seed.test), testing with user ID 1\n";

    $courses = $dashboard->getUserCourses(1);
    echo "OK: getUserCourses returned " . count($courses) . " courses\n";

    $stats = $dashboard->getDashboardStats(1);
    echo "OK: getDashboardStats returned\n";
}

echo "\n=== DashboardService Tests Passed ===\n\n";
