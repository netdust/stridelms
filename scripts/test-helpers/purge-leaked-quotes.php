<?php

/**
 * One-off: purge leaked vad_quote posts + orphan postmeta from the DISPOSABLE
 * test DB. The AutoVoucher integration tests reuse low registration ids across
 * runs; a leaked quote whose registration_id meta matches a reused id makes
 * getQuoteByRegistration() return a stale quote. Run against the disposable DB
 * only. See gotcha_leaked_quotes_registration_id_reuse.
 */
global $wpdb;

$quoteIds = $wpdb->get_col("SELECT ID FROM {$wpdb->posts} WHERE post_type = 'vad_quote'");
foreach ($quoteIds as $id) {
    wp_delete_post((int) $id, true);
}

$orphans = $wpdb->query(
    "DELETE pm FROM {$wpdb->postmeta} pm
     LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id
     WHERE p.ID IS NULL",
);

printf("purged quotes=%d orphan_meta=%d\n", count($quoteIds), (int) $orphans);
