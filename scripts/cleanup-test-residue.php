<?php
/**
 * One-shot cleanup: remove test-artefact posts left by past Playwright/
 * Codeception runs. Safe to re-run — only matches specific test-naming
 * patterns and bypasses real seed data.
 *
 * Patterns scrubbed:
 *   - vad_edition: "E2E Test Editie - %", "Roundtrip Editie %", "Test Edition %",
 *     "No Course Edition"
 *   - vad_voucher: "TESTCREATE%", "FIXED1%", "PERCENT1%", "VAD-XXXX-XXXX" (4-4 random)
 *   - vad_session with empty post_title (canAddSession residue — now covered by
 *     AdminEditionCest::_after but historical rows remain)
 *   - vad_quote: every non-seed quote. Quotes are derived from registrations
 *     so once test editions are dropped, the orphan quotes have no purpose.
 *     Real seed quotes carry _stride_seed_data and are kept.
 *
 * Real seed data is identified by post_id presence in the {_stride_seed_data}
 * meta flag and is NEVER touched.
 *
 * Usage:
 *   DRY_RUN=1 ddev exec wp eval-file scripts/cleanup-test-residue.php
 *   ddev exec wp eval-file scripts/cleanup-test-residue.php
 */

global $wpdb;
$dryRun = (bool) getenv('DRY_RUN');

// 1) Test editions
$editionIds = $wpdb->get_col(
    "SELECT ID FROM {$wpdb->posts}
     WHERE post_type = 'vad_edition'
       AND (
            post_title LIKE 'E2E Test Editie - %'
         OR post_title LIKE 'Roundtrip Editie%'
         OR post_title LIKE 'Test Edition %'
         OR post_title = 'No Course Edition'
       )
       AND ID NOT IN (SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_stride_seed_data')"
);

// 2) Test vouchers — VAD-XXXX-XXXX pattern matches randomly-generated codes
$voucherIds = $wpdb->get_col(
    "SELECT ID FROM {$wpdb->posts}
     WHERE post_type = 'vad_voucher'
       AND (
            post_title LIKE 'TESTCREATE%'
         OR post_title LIKE 'FIXED1%'
         OR post_title LIKE 'PERCENT1%'
         OR post_title REGEXP '^VAD-[A-Z0-9]{4}-[A-Z0-9]{4}$'
       )
       AND ID NOT IN (SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_stride_seed_data')"
);

// 3) Empty-title vad_session rows (historical canAddSession residue)
$sessionIds = $wpdb->get_col(
    "SELECT ID FROM {$wpdb->posts}
     WHERE post_type = 'vad_session'
       AND post_title = ''"
);

// 4) Non-seed vad_quote rows (test-run residue, accumulates per registration).
$quoteIds = $wpdb->get_col(
    "SELECT ID FROM {$wpdb->posts}
     WHERE post_type = 'vad_quote'
       AND ID NOT IN (SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_stride_seed_data')"
);

WP_CLI::log(sprintf(
    '%sFound: %d editions, %d vouchers, %d empty-title sessions, %d quotes.',
    $dryRun ? '[dry-run] ' : '',
    count($editionIds),
    count($voucherIds),
    count($sessionIds),
    count($quoteIds)
));

if ($dryRun) {
    if (!empty($editionIds)) {
        WP_CLI::log('Edition IDs sample: ' . implode(',', array_slice($editionIds, 0, 5)) . (count($editionIds) > 5 ? '…' : ''));
    }
    if (!empty($voucherIds)) {
        WP_CLI::log('Voucher IDs sample: ' . implode(',', array_slice($voucherIds, 0, 5)) . (count($voucherIds) > 5 ? '…' : ''));
    }
    if (!empty($quoteIds)) {
        WP_CLI::log('Quote IDs sample: ' . implode(',', array_slice($quoteIds, 0, 5)) . (count($quoteIds) > 5 ? '…' : ''));
    }
    WP_CLI::success('[dry-run] No changes made.');
    return;
}

$deleted = 0;
foreach (array_merge($editionIds, $voucherIds, $sessionIds, $quoteIds) as $id) {
    if (wp_delete_post((int) $id, true)) {
        $deleted++;
    }
}

WP_CLI::success("Deleted {$deleted} test-residue posts.");
