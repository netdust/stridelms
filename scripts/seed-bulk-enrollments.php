<?php

/**
 * Stride LMS — supplementary BULK enrollment seeder.
 *
 * Adds MANY registrations spanning OLD → RECENT registered_at dates, on top of
 * the curated feature-matrix seed (scripts/seed.php). Use when you need volume
 * (e.g. to exercise the admin Inschrijvingen grid / Offertes / dashboards) that
 * the small coverage matrix doesn't provide.
 *
 * - Enrolls each seed user across many editions (one row per user+edition; the
 *   existing curated rows are respected — findByUserAndEdition de-dupes).
 * - registered_at is spread deterministically from ~24 months ago to today, so
 *   the grid has both old and recent enrollments.
 * - status correlates with age: old editions/dates lean completed/confirmed/
 *   cancelled; recent lean confirmed/pending/interest/waitlist.
 * - completed_at / cancelled_at set where the status implies them.
 * - Every bulk row carries notes 'Seed: bulk volume' so it is identifiable and
 *   removable independently of the curated matrix rows.
 *
 * Run:    ddev exec wp eval-file scripts/seed-bulk-enrollments.php
 * Tune:   STRIDE_BULK_TARGET env (default 700) caps how many bulk rows to add.
 * Clean:  ddev exec wp eval-file scripts/seed-bulk-enrollments.php -- --purge
 *         (or set STRIDE_BULK_PURGE=1) removes only the 'Seed: bulk volume' rows.
 */

if (!defined('ABSPATH')) {
    echo "Run via WP-CLI: ddev exec wp eval-file scripts/seed-bulk-enrollments.php\n";
    exit(1);
}
if (!defined('WP_ENV') || !in_array(WP_ENV, ['development', 'local'], true)) {
    echo "ERROR: bulk enrollment seeder only allowed in development/local!\n";
    exit(1);
}

use Stride\Modules\Enrollment\RegistrationRepository;
use Stride\Modules\Edition\EditionRepository;
use Stride\Modules\Edition\EditionService;

global $wpdb;
$table = $wpdb->prefix . 'vad_registrations';
$BULK_NOTE = 'Seed: bulk volume';

/* ---- purge mode ---------------------------------------------------------- */
$purge = (getenv('STRIDE_BULK_PURGE') === '1') || in_array('--purge', (array) ($args ?? []), true);
if ($purge) {
    $n = $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE notes = %s", $BULK_NOTE));
    echo "Purged {$n} bulk-volume registrations.\n";
    return;
}

$repo = ntdst_get(RegistrationRepository::class);
$editionRepo = ntdst_get(EditionRepository::class);
$editionService = ntdst_get(EditionService::class);

$target = (int) (getenv('STRIDE_BULK_TARGET') ?: 700);

/* ---- gather the actors --------------------------------------------------- */
// Seed users only (don't touch real ported accounts).
$users = get_users(['search' => 'seed_*', 'search_columns' => ['user_login'], 'fields' => 'ID']);
$users = array_map('intval', $users);
// Drop the admin/instructor/coordinator/supervisor service accounts from the
// enrollee pool — students + fillers + the named enrolled/completed users are
// the realistic enrollees. Keep it simple: anyone whose login isn't a staff role.
$staffLogins = ['seed_admin', 'seed_instructor', 'seed_coordinator', 'seed_supervisor'];
$users = array_values(array_filter($users, function (int $uid) use ($staffLogins) {
    $u = get_userdata($uid);
    return $u && !in_array($u->user_login, $staffLogins, true);
}));

$editions = get_posts(['post_type' => 'vad_edition', 'posts_per_page' => -1, 'fields' => 'ids', 'post_status' => 'publish']);
$editions = array_map('intval', $editions);

if (empty($users) || empty($editions)) {
    echo "No seed users or editions found — run scripts/seed.php first.\n";
    return;
}

echo "Bulk enrolling: " . count($users) . " users × " . count($editions) . " editions, target {$target} rows.\n";

/* ---- deterministic spread helpers --------------------------------------- */
$now = time();
$oldest = strtotime('-24 months', $now);   // span: 2 years ago → now
$span = $now - $oldest;

// status pools by age bucket (0=oldest .. 1=newest)
$oldStatuses    = ['completed', 'completed', 'confirmed', 'confirmed', 'cancelled'];
$midStatuses    = ['confirmed', 'confirmed', 'completed', 'cancelled', 'waitlist'];
$recentStatuses = ['confirmed', 'pending', 'pending', 'interest', 'waitlist'];

$paths = [
    RegistrationRepository::PATH_INDIVIDUAL,
    RegistrationRepository::PATH_INDIVIDUAL,
    RegistrationRepository::PATH_COLLEAGUE,
    RegistrationRepository::PATH_PARTNER,
];

$created = 0;
$skipped = 0;
$byStatus = [];
$i = 0;

// Walk user × edition pairs in a rotated order so the spread isn't all one user
// then the next. Cap at $target.
foreach ($editions as $eIdx => $editionId) {
    foreach ($users as $uIdx => $userId) {
        if ($created >= $target) {
            break 2;
        }
        $i++;

        // Skip ~1 in 3 pairs so not every user is in every edition (more realistic
        // sparsity) — deterministic on the index.
        if ($i % 3 === 0) {
            continue;
        }

        // Already enrolled (curated row or a prior bulk run)? skip.
        if ($repo->findByUserAndEdition($userId, $editionId)) {
            $skipped++;
            continue;
        }

        // Deterministic registered_at across the full span. Mix edition + user
        // index so dates don't cluster per edition.
        $frac = (($eIdx * 7 + $uIdx * 13 + $i * 3) % 1000) / 1000.0;
        $regTs = $oldest + (int) ($frac * $span);
        $regDate = date('Y-m-d H:i:s', $regTs);

        // Age bucket → status pool.
        $pool = $frac < 0.4 ? $oldStatuses : ($frac < 0.75 ? $midStatuses : $recentStatuses);
        $status = $pool[$i % count($pool)];
        $path = $paths[$i % count($paths)];

        $payload = [
            'user_id' => $userId,
            'edition_id' => $editionId,
            'status' => $status,
            'enrollment_path' => $path,
            'notes' => $BULK_NOTE,
        ];
        if ($path === RegistrationRepository::PATH_PARTNER) {
            $payload['company_id'] = 1;
        }

        $regId = $repo->create($payload);
        if (is_wp_error($regId)) {
            $skipped++;
            continue;
        }
        $regId = (int) $regId;

        // Override registered_at (create() stamps now) + derived timestamps.
        $update = ['registered_at' => $regDate];
        if ($status === 'completed') {
            // completed a little after they registered
            $update['completed_at'] = date('Y-m-d H:i:s', $regTs + 14 * DAY_IN_SECONDS);
        }
        if ($status === 'cancelled') {
            $update['cancelled_at'] = date('Y-m-d H:i:s', $regTs + 3 * DAY_IN_SECONDS);
        }
        $wpdb->update($table, $update, ['id' => $regId]);

        // Grant LD access for confirmed/completed so dashboards/progress are real.
        if (in_array($status, ['confirmed', 'completed'], true) && function_exists('ld_update_course_access')) {
            $courseId = (int) $editionService->getCourseId($editionId);
            if ($courseId) {
                ld_update_course_access($userId, $courseId, false);
            }
        }

        $created++;
        $byStatus[$status] = ($byStatus[$status] ?? 0) + 1;
    }
}

echo "\n=== Bulk enrollment complete ===\n";
echo "  created: {$created}\n";
echo "  skipped (already enrolled / errors): {$skipped}\n";
echo "  by status:\n";
foreach ($byStatus as $s => $c) {
    echo "    {$s}: {$c}\n";
}
$min = $wpdb->get_var($wpdb->prepare("SELECT MIN(registered_at) FROM {$table} WHERE notes = %s", $BULK_NOTE));
$max = $wpdb->get_var($wpdb->prepare("SELECT MAX(registered_at) FROM {$table} WHERE notes = %s", $BULK_NOTE));
echo "  bulk registered_at range: {$min} → {$max}\n";
$total = $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
echo "  TOTAL registrations in table now: {$total}\n";
