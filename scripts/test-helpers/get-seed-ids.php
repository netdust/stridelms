<?php
/**
 * Discover seed data IDs for E2E testing.
 *
 * Usage: ddev exec wp eval-file scripts/test-helpers/get-seed-ids.php
 *
 * Outputs JSON:
 * {
 *   "user_id": N,
 *   "user_first_name": "...",
 *   "user_display_name": "...",
 *   "edition_id": N,
 *   "edition_title": "...",
 *   "registration_id": N,
 *   "quote_id": N,
 *   "quote_number": "OFF-...",
 *   "templates_seeded": true|false,
 *   "site_name": "..."
 * }
 */

global $wpdb;

// Find a user that has registrations
$userId = 0;
$user = null;

// Try users in order: seed students, seed admin, WP admin
$candidates = ['seed_student1@seed.test', 'seed_admin@seed.test'];
foreach ($candidates as $email) {
    $candidate = get_user_by('email', $email);
    if ($candidate) {
        $hasReg = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}vad_registrations WHERE user_id = %d",
            $candidate->ID
        ));
        if ($hasReg > 0) {
            $user = $candidate;
            $userId = (int) $candidate->ID;
            break;
        }
    }
}
// Fall back to WP admin (user 1) who typically has registrations
if (!$userId) {
    $user = get_userdata(1);
    $userId = $user ? (int) $user->ID : 0;
}
$userFirstName = $user ? get_user_meta($userId, 'first_name', true) : '';
$userDisplayName = $user ? $user->display_name : '';

// Find a seed edition (has _stride_seed_data meta)
$editionId = (int) $wpdb->get_var(
    "SELECT p.ID FROM {$wpdb->posts} p
     JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_stride_seed_data'
     WHERE p.post_type = 'vad_edition' AND p.post_status = 'publish'
     LIMIT 1"
);

$editionTitle = $editionId ? get_the_title($editionId) : '';

// Find a registration for this user
$registrationId = 0;
if ($userId) {
    $registrationId = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}vad_registrations WHERE user_id = %d LIMIT 1",
            $userId
        )
    );
}

// Find a quote (any published quote)
$quoteId = 0;
$quoteNumber = '';
$quoteRow = $wpdb->get_row(
    "SELECT p.ID FROM {$wpdb->posts} p
     WHERE p.post_type = 'vad_quote' AND p.post_status = 'publish'
     LIMIT 1"
);
if ($quoteRow) {
    $quoteId = (int) $quoteRow->ID;
    $model = ntdst_data()->get('vad_quote');
    $quoteNumber = $model->getMeta($quoteId, 'quote_number') ?: '';
}

// Check if mail templates are seeded
$templatesSeeded = (bool) get_option('stride_mail_templates_seeded');

// Site name
$siteName = get_bloginfo('name');

echo json_encode([
    'user_id'           => $userId,
    'user_first_name'   => $userFirstName,
    'user_display_name' => $userDisplayName,
    'edition_id'        => $editionId,
    'edition_title'     => $editionTitle,
    'registration_id'   => $registrationId,
    'quote_id'          => $quoteId,
    'quote_number'      => $quoteNumber,
    'templates_seeded'  => $templatesSeeded,
    'site_name'         => $siteName,
], JSON_UNESCAPED_UNICODE);
