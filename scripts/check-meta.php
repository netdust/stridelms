<?php

/**
 * Test script to check meta access patterns
 */

$editions = get_posts(['post_type' => 'vad_edition', 'numberposts' => 1]);
if ($editions) {
    $e = $editions[0];
    echo "Edition {$e->ID}: {$e->post_title}\n";
    echo "Direct price: [" . get_post_meta($e->ID, 'price', true) . "]\n";
    echo "Prefixed price: [" . get_post_meta($e->ID, '_ntdst_price', true) . "]\n";

    // Check via data manager
    $model = ntdst_data()->get('vad_edition');
    $edition = $model->find($e->ID);
    if (!is_wp_error($edition)) {
        echo "ORM fields['price']: [" . ($edition->fields['price'] ?? 'N/A') . "]\n";
    }
}
