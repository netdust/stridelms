<?php

/**
 * Stride LMS — One-time lead adoption pass (form-identity plan 2026-07-14).
 *
 * Run with: ddev exec wp eval-file scripts/adopt-leads.php
 * (production: wp eval-file scripts/adopt-leads.php — safe: bind-only,
 *  idempotent, never creates accounts, never deletes rows.)
 *
 * Implements rule 3 of the identity model for EXISTING data: an account
 * holder is never a lead. Every account-less registration whose lead_email
 * exactly matches a wp_users e-mail is bound to that account via
 * RegistrationRepository::bindLeadToUser() (user_id set, lead columns
 * cleared, enrollment_data untouched — same write the waitlist promotion
 * uses).
 *
 * Skips (and reports) rows whose target account ALREADY has a registration
 * for the same edition — binding those would create the user+edition
 * duplicate shape the enroll lock exists to prevent; an admin resolves the
 * pair manually (usually: cancel the stale lead row).
 *
 * Idempotent: bound rows leave the work-list (user_id set), so re-running
 * only processes the remainder.
 */

if (!defined('ABSPATH')) {
    echo "This script must be run via WP-CLI: wp eval-file scripts/adopt-leads.php\n";
    exit(1);
}

use Stride\Modules\Enrollment\RegistrationRepository;

$repo = ntdst_get(RegistrationRepository::class);

$rows = $repo->findLeadRowsWithEmail();

$bound = 0;
$noAccount = 0;
$skippedDuplicate = 0;
$failed = 0;

foreach ($rows as $row) {
    $user = get_user_by('email', (string) $row->lead_email);
    if (!$user) {
        $noAccount++;
        continue;
    }

    $editionId = (int) ($row->edition_id ?? 0);
    $existing = $editionId > 0 ? $repo->findByUserAndEdition((int) $user->ID, $editionId) : null;
    if ($existing) {
        // Binding would create the user+edition duplicate shape — report with
        // the existing row's status so triage is trivial: a CANCELLED existing
        // row usually means "cancel/remove the lead row"; an active one means
        // the lead row is redundant.
        $skippedDuplicate++;
        echo sprintf(
            "SKIP  reg #%d: account #%d (%s) already has a %s registration (#%d) for edition #%d — resolve manually\n",
            (int) $row->id,
            (int) $user->ID,
            (string) $row->lead_email,
            (string) $existing->status,
            (int) $existing->id,
            $editionId,
        );
        continue;
    }

    if ($repo->bindLeadToUser((int) $row->id, (int) $user->ID)) {
        $bound++;
        echo sprintf("BIND  reg #%d → account #%d (%s)\n", (int) $row->id, (int) $user->ID, (string) $row->lead_email);
    } else {
        $failed++;
        echo sprintf("FAIL  reg #%d: bind did not apply (concurrent change?)\n", (int) $row->id);
    }
}

echo "\n=== Lead adoption pass ===\n";
echo sprintf("Work-list: %d lead rows with an e-mail\n", count($rows));
echo sprintf("Bound to existing accounts: %d\n", $bound);
echo sprintf("No matching account (stay lead): %d\n", $noAccount);
echo sprintf("Skipped (duplicate user+edition — manual): %d\n", $skippedDuplicate);
echo sprintf("Failed binds: %d\n", $failed);
