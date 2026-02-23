<?php
/**
 * Fix edition slugs - convert numeric slugs to course-name-based slugs.
 *
 * Run with: ddev exec wp eval-file scripts/fix-edition-slugs.php
 */

$editions = get_posts([
    'post_type' => 'vad_edition',
    'post_status' => 'any',
    'posts_per_page' => -1,
]);

$updated = 0;
foreach ($editions as $edition) {
    // Skip if slug is already non-numeric
    if (!is_numeric($edition->post_name)) {
        WP_CLI::log("Skipping edition {$edition->ID}: slug already set to '{$edition->post_name}'");
        continue;
    }

    // Get linked course
    $courseId = (int) get_post_meta($edition->ID, '_ntdst_course_id', true);
    if ($courseId <= 0) {
        WP_CLI::warning("Edition {$edition->ID} has no linked course");
        continue;
    }

    $course = get_post($courseId);
    if (!$course) {
        WP_CLI::warning("Edition {$edition->ID}: course {$courseId} not found");
        continue;
    }

    // Generate unique slug
    global $wpdb;
    $baseSlug = $course->post_name;
    $slug = $baseSlug;
    $suffix = 2;

    while (true) {
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_name = %s AND post_type = %s AND ID != %d LIMIT 1",
            $slug,
            'vad_edition',
            $edition->ID
        ));

        if (!$exists) {
            break;
        }
        $slug = $baseSlug . '-' . $suffix;
        $suffix++;
    }

    // Update edition
    $result = wp_update_post([
        'ID' => $edition->ID,
        'post_name' => $slug,
        'post_title' => $course->post_title,
    ]);

    if (is_wp_error($result)) {
        WP_CLI::error("Failed to update edition {$edition->ID}: " . $result->get_error_message());
    } else {
        WP_CLI::success("Updated edition {$edition->ID}: {$edition->post_name} -> {$slug}");
        $updated++;
    }
}

WP_CLI::log("\nUpdated {$updated} editions.");
