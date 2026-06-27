<?php

/**
 * Migration: collapse the member/non-member price differential.
 *
 * v1 has no membership feature, so the two price meta keys must hold the
 * same value. Non-member price is canonical — any edition where they
 * differ gets `_ntdst_price` rewritten to match `_ntdst_price_non_member`.
 *
 * Editions/trajectories with only one of the two set are normalised so
 * the other key holds the same value. Editions with neither set are
 * skipped.
 *
 * Idempotent: re-runs on already-aligned rows are no-ops.
 *
 * Usage:
 *   DRY_RUN=1 ddev exec wp eval-file scripts/migrate-member-prices.php
 *   ddev exec wp eval-file scripts/migrate-member-prices.php
 */

global $wpdb;
$dryRun = (bool) getenv('DRY_RUN');

$postTypes = ['vad_edition', 'vad_trajectory'];
$placeholder = implode(',', array_fill(0, count($postTypes), '%s'));

$rows = $wpdb->get_results(
    $wpdb->prepare(
        "SELECT p.ID,
                price.meta_value AS price,
                pnm.meta_value   AS price_non_member
         FROM {$wpdb->posts} p
         LEFT JOIN {$wpdb->postmeta} price
           ON price.post_id = p.ID AND price.meta_key = '_ntdst_price'
         LEFT JOIN {$wpdb->postmeta} pnm
           ON pnm.post_id   = p.ID AND pnm.meta_key   = '_ntdst_price_non_member'
         WHERE p.post_type IN ({$placeholder})
           AND p.post_status IN ('publish', 'draft', 'private')",
        ...$postTypes,
    ),
);

$changed = 0;
$skipped = 0;

foreach ($rows as $row) {
    $id = (int) $row->ID;
    $price = $row->price;
    $pnm   = $row->price_non_member;

    // Canonical value: non-member > price > 0
    $canonical = null;
    if ($pnm !== null && $pnm !== '') {
        $canonical = $pnm;
    } elseif ($price !== null && $price !== '') {
        $canonical = $price;
    } else {
        $skipped++; // both unset — nothing to align
        continue;
    }

    if ($price === $canonical && $pnm === $canonical) {
        $skipped++;
        continue;
    }

    if ($dryRun) {
        WP_CLI::log(sprintf(
            '[dry-run] %d: price %s → %s ; pnm %s → %s',
            $id,
            $price ?? '(null)',
            $canonical,
            $pnm ?? '(null)',
            $canonical,
        ));
    } else {
        update_post_meta($id, '_ntdst_price', $canonical);
        update_post_meta($id, '_ntdst_price_non_member', $canonical);
    }
    $changed++;
}

WP_CLI::success(sprintf(
    '%s%d aligned, %d already-canonical/skipped.',
    $dryRun ? '[dry-run] ' : '',
    $changed,
    $skipped,
));
