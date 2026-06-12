<?php
/**
 * One-shot: remove scheduled actions for hooks that no longer have a
 * listener in stride-core. After a hook is removed from the codebase,
 * Action Scheduler keeps trying to dispatch it and fails — those entries
 * clutter the admin "Scheduled Actions" view and skew failure counts.
 *
 * Idempotent: re-runs are no-ops once the orphan rows are gone.
 *
 * Add new dropped hooks to ORPHAN_HOOKS as they're retired. Run via:
 *   ddev exec wp eval-file scripts/cleanup-orphan-cron.php
 */

const ORPHAN_HOOKS = [
    // Dropped 2026-05-13 in commit 8a54c475 (OGM + quote-lock cron pulled
    // from v1 — Stride only creates quotes, locking belongs in Exact Online).
    'stride/quote/lock_approaching_editions',
];

global $wpdb;

$total = 0;
foreach (ORPHAN_HOOKS as $hook) {
    // Unschedule any pending/future runs WP knows about.
    wp_unschedule_hook($hook);

    // Drop any historical rows Action Scheduler kept (pending + failed +
    // complete — we don't need the history for hooks that no longer exist).
    $deleted = (int) $wpdb->query($wpdb->prepare(
        "DELETE FROM {$wpdb->prefix}actionscheduler_actions WHERE hook = %s",
        $hook
    ));

    // Drop dangling log rows for the deleted actions.
    $wpdb->query("DELETE FROM {$wpdb->prefix}actionscheduler_logs WHERE action_id NOT IN (SELECT action_id FROM {$wpdb->prefix}actionscheduler_actions)");

    if ($deleted > 0) {
        WP_CLI::log("Removed {$deleted} scheduled action rows for hook '{$hook}'");
    }
    $total += $deleted;
}

WP_CLI::success(sprintf('Cleanup complete. %d total rows removed across %d hook(s).', $total, count(ORPHAN_HOOKS)));
