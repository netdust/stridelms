<?php

/**
 * Migration: seed-stored edition/trajectory prices EUROS -> CENTS.
 *
 * CANONICAL UNIT DECISION (2026-06-30): the `_ntdst_price` / `_ntdst_price_non_member`
 * meta on vad_edition and vad_trajectory is CENTS. Admin-written rows already
 * store cents (admin save ×100). Seed-written rows (marked `_stride_seed_data`)
 * historically stored EUROS — those are the inconsistent rows this migration
 * converts ×100 to cents.
 *
 * DISCRIMINATOR: `_stride_seed_data` presence. Seed-marked == euros (convert);
 * non-seed == admin-written cents (LEAVE UNTOUCHED). This is the controller-
 * confirmed invariant. Only seed-marked rows are ever touched.
 *
 * DOUBLE-APPLY GUARD: this migration is NOT idempotent — re-running APPLY would
 * ×100 again and 100× the values. It must run EXACTLY ONCE. Because the seed
 * builders now store cents (builders.php), a fresh reseed needs NO migration;
 * this script only repairs rows seeded BEFORE the canonical fix.
 *
 * USAGE:
 *   Dry-run (default — changes nothing, prints the table):
 *     ddev exec wp eval-file scripts/migrations/2026-06-30-price-euros-to-cents.php
 *   Apply (only when an operator has reviewed the dry-run):
 *     ddev exec bash -c 'APPLY=1 wp eval-file scripts/migrations/2026-06-30-price-euros-to-cents.php'
 *
 * @package Stride\Scripts\Migrations
 *
 * Note: no `declare(strict_types=1)` — `wp eval-file` evaluates the file body,
 * where a strict_types declaration is not the first statement and fatals.
 */

if (!defined('ABSPATH')) {
    fwrite(STDERR, "This migration must run inside WordPress (wp eval-file).\n");
    exit(1);
}

$apply = getenv('APPLY') === '1';

$prefix      = '_ntdst_';
$seedKey     = '_stride_seed_data';
$priceKeys   = [$prefix . 'price', $prefix . 'price_non_member'];
$postTypes   = ['vad_edition', 'vad_trajectory'];

global $wpdb;

echo $apply
    ? "=== PRICE EUROS->CENTS MIGRATION — APPLY MODE (writing changes) ===\n"
    : "=== PRICE EUROS->CENTS MIGRATION — DRY RUN (no changes) ===\n";
echo "Discriminator: only posts with {$seedKey} meta (seed=euros) are touched.\n\n";

// Collect every seed-marked edition/trajectory and its price meta.
// Join on the seed marker so non-seed (admin cents) rows are never selected.
$typesIn = "'" . implode("','", array_map('esc_sql', $postTypes)) . "'";
$keysIn  = "'" . implode("','", array_map('esc_sql', $priceKeys)) . "'";

$rows = $wpdb->get_results(
    $wpdb->prepare(
        "SELECT p.ID AS post_id, p.post_type, pm.meta_key, pm.meta_value
           FROM {$wpdb->posts} p
           INNER JOIN {$wpdb->postmeta} seed
                   ON seed.post_id = p.ID AND seed.meta_key = %s
           INNER JOIN {$wpdb->postmeta} pm
                   ON pm.post_id = p.ID AND pm.meta_key IN ({$keysIn})
          WHERE p.post_type IN ({$typesIn})
          ORDER BY p.ID, pm.meta_key",
        $seedKey,
    ),
);

if (!$rows) {
    echo "No seed-marked edition/trajectory price rows found. Nothing to migrate.\n";
    return;
}

printf("%-7s | %-14s | %-22s | %-12s | %-14s | %-12s\n", '#id', 'post_type', 'field', 'old(euros)', 'new(cents)', 'human(€)');
echo str_repeat('-', 96) . "\n";

$toChange = 0;
$skipped  = 0;

foreach ($rows as $row) {
    $old = (int) $row->meta_value;

    // Skip zero/empty (free) — nothing to convert.
    if ($old <= 0) {
        $skipped++;
        continue;
    }

    $newCents = $old * 100;
    $human    = '€ ' . number_format($newCents / 100, 2, ',', '.');
    $field    = str_replace($prefix, '', (string) $row->meta_key);

    printf(
        "%-7d | %-14s | %-22s | %-12d | %-14d | %-12s\n",
        (int) $row->post_id,
        $row->post_type,
        $field,
        $old,
        $newCents,
        $human,
    );

    $toChange++;

    if ($apply) {
        update_post_meta((int) $row->post_id, (string) $row->meta_key, $newCents);
    }
}

echo str_repeat('-', 96) . "\n";
printf("Rows that WOULD change: %d   (zero-priced rows skipped: %d)\n", $toChange, $skipped);

if ($apply) {
    echo "\nAPPLIED. {$toChange} meta rows multiplied ×100. DO NOT run APPLY again (would double-convert).\n";
} else {
    echo "\nDRY RUN ONLY — nothing was written. Review the table above, then run with APPLY=1.\n";
}
