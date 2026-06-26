<?php

if (!defined('ABSPATH')) {
    exit(1);
}

$edition_id = get_posts(['post_type' => 'vad_edition', 'numberposts' => 1, 'fields' => 'ids'])[0] ?? 0;
if ($edition_id) {
    echo "Edition ID: $edition_id\n";
    $course_id = get_post_meta($edition_id, '_vad_course_id', true);
    echo "Course ID: $course_id\n";
    if ($course_id) {
        $tags = wp_get_object_terms($course_id, 'ld_course_tag');
        echo "Course tags: " . count($tags) . "\n";
        foreach ($tags as $tag) {
            echo "  - {$tag->name} (ID: {$tag->term_id})\n";
        }
    }
}

// List all available course tags
$all_tags = get_terms(['taxonomy' => 'ld_course_tag', 'hide_empty' => false]);
echo "\nAll course tags:\n";
if (is_array($all_tags)) {
    foreach ($all_tags as $tag) {
        echo "  - {$tag->name} (ID: {$tag->term_id})\n";
    }
} else {
    echo "  (none or taxonomy not registered)\n";
}
