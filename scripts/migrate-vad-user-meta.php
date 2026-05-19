<?php
/**
 * Migrate VAD user data → Stride usermeta keys.
 *
 * Reads each Stride-required field from the VAD precedence chain:
 *   FluentCRM (main + custom contact fields)
 *     → BuddyBoss xprofile
 *       → WP usermeta (incl. _wpinv_* GetPaid fields)
 *
 * Mirrors the decorator order in VAD's services/Fluent/VAD_Fluent.php:
 *   FluentCRM → XProfile → GetPaid_Customers → GetPaid_Users → WP
 *
 * "Fluent first" rule (FluentCRM_UsersManager::get_field, line 82-85):
 *   non-empty value wins, otherwise fall through.
 *
 * Writes each resolved value to Stride's expected usermeta key (per
 * CLAUDE.md "User Meta Keys" section). Never falls back organisation
 * ← billing_company — they're separate concerns.
 *
 * Idempotent: only writes when target meta is empty AND a non-empty
 * source value was found.
 *
 * Run with:
 *   ddev exec wp eval-file scripts/migrate-vad-user-meta.php --path=web/wp
 */

if (!defined('ABSPATH')) {
    die('Run via wp eval-file');
}

global $wpdb;

// ─────────────────────────────────────────────────────────────────────────
// Map: stride_meta_key => [ fluentcrm_slug, xprofile_field_id, wp_meta_key ]
// Any of the three can be null; nulls are skipped during resolution.
// XProfile field IDs sourced from VAD's XProfile_UsersManager::get_field_ids().
// FluentCRM slugs sourced from ckqp_fc_meta id=6 (contact_custom_fields) +
// main fields (first_name/last_name/email/phone) + address (postal_code/city...).
// ─────────────────────────────────────────────────────────────────────────
$map = [
    'first_name'         => ['fcrm_main:first_name',  1,   'first_name'],
    'last_name'          => ['fcrm_main:last_name',   2,   'last_name'],
    'phone'              => ['fcrm_main:phone',       141, '_wpinv_phone'],

    'organisation'       => ['fcrm_custom:organisatie',     146, null],
    'department'         => ['fcrm_custom:afdeling',        null, null], // FluentCRM-only
    'gln_number'         => ['fcrm_custom:gln_nummer',      157, null],

    'billing_company'    => ['fcrm_custom:facturatie_naam_organisat', 147, '_wpinv_company'],
    'billing_address_1'  => ['fcrm_custom:facturatie_adres',          12,  '_wpinv_address'],
    'billing_postcode'   => ['fcrm_custom:facturatie_postcode',       15,  '_wpinv_zip'],
    'billing_city'       => ['fcrm_custom:facturatie_stad',           14,  '_wpinv_city'],
    'billing_vat'        => ['fcrm_custom:btw_ondernemingsnummer',    16,  '_wpinv_vat_number'],
    'invoice_email'      => ['fcrm_custom:facturatie_email',          151, '_wpinv_email_cc'],

    // External company link — VAD's winbooks_id is the canonical Winbooks ID
    // and is copied verbatim into Stride's _stride_company_id (an opaque
    // external identifier, not a WP post ID). Used for partner scoping in
    // CompanyAffiliation + Partner API queries.
    '_stride_company_id' => ['fcrm_custom:winbooks_id',               148, '_wpinv_company_id'],
];

// ─────────────────────────────────────────────────────────────────────────
// Pre-build FluentCRM subscriber lookup keyed by user_id.
// fc_subscribers has main fields directly. Custom fields live in
// fc_subscriber_meta with key='custom_values' (serialized PHP array).
// ─────────────────────────────────────────────────────────────────────────
echo "Loading FluentCRM subscribers…\n";
$fcrmRows = $wpdb->get_results(
    "SELECT id, user_id, first_name, last_name, email, phone, postal_code, city
       FROM ckqp_fc_subscribers
      WHERE user_id IS NOT NULL AND user_id > 0",
    OBJECT_K
);
$fcrmByUser = [];
foreach ($fcrmRows as $row) {
    $fcrmByUser[(int) $row->user_id] = $row;
}
$subscriberIds = array_map(fn($r) => (int) $r->id, $fcrmRows);
echo "  " . count($fcrmByUser) . " linked subscribers\n";

// Custom values per subscriber (single SELECT, then index)
echo "Loading FluentCRM custom_values…\n";
$customRows = $wpdb->get_results(
    "SELECT subscriber_id, value
       FROM ckqp_fc_subscriber_meta
      WHERE `key` = 'custom_values' AND subscriber_id IN (" . implode(',', $subscriberIds ?: [0]) . ")"
);
$customByUser = [];
foreach ($customRows as $cr) {
    $sub = (int) $cr->subscriber_id;
    // Map subscriber_id → user_id
    $uid = null;
    foreach ($fcrmRows as $r) {
        if ((int) $r->id === $sub) {
            $uid = (int) $r->user_id;
            break;
        }
    }
    if (!$uid) continue;
    $unser = @unserialize($cr->value);
    if (is_array($unser)) {
        $customByUser[$uid] = $unser;
    }
}
echo "  " . count($customByUser) . " custom-value rows\n";

// ─────────────────────────────────────────────────────────────────────────
// Resolver: walk FluentCRM → XProfile → WP usermeta. First non-empty wins.
// ─────────────────────────────────────────────────────────────────────────
// XProfile data lookup: BuddyBoss plugin is not active on Stride, so
// xprofile_get_field_data() doesn't exist. Read from the table directly.
// Pre-index all xprofile values keyed by [user_id][field_id] for speed.
echo "Loading XProfile data…\n";
$xpRows = $wpdb->get_results(
    "SELECT user_id, field_id, value
       FROM ckqp_bp_xprofile_data
      WHERE value <> '' AND value IS NOT NULL"
);
$xpByUser = [];
foreach ($xpRows as $r) {
    $xpByUser[(int) $r->user_id][(int) $r->field_id] = (string) $r->value;
}
echo "  " . count($xpByUser) . " users with xprofile values\n";

$resolve = function (int $userId, string $fcrmSource, ?int $xprofileId, ?string $wpMetaKey) use ($fcrmByUser, $customByUser, $xpByUser): ?string {

    // FluentCRM
    if (str_starts_with($fcrmSource, 'fcrm_main:')) {
        $fld = substr($fcrmSource, strlen('fcrm_main:'));
        $sub = $fcrmByUser[$userId] ?? null;
        if ($sub && !empty(trim((string) ($sub->$fld ?? '')))) {
            return (string) $sub->$fld;
        }
    } elseif (str_starts_with($fcrmSource, 'fcrm_custom:')) {
        $slug = substr($fcrmSource, strlen('fcrm_custom:'));
        $vals = $customByUser[$userId] ?? null;
        if (is_array($vals) && !empty(trim((string) ($vals[$slug] ?? '')))) {
            return (string) $vals[$slug];
        }
    }

    // XProfile (read directly from table — BuddyBoss helper not available)
    if ($xprofileId !== null) {
        $v = $xpByUser[$userId][$xprofileId] ?? null;
        if ($v !== null && !empty(trim((string) $v))) {
            return (string) $v;
        }
    }

    // WP usermeta
    if ($wpMetaKey) {
        $v = get_user_meta($userId, $wpMetaKey, true);
        if (!empty(trim((string) $v))) {
            return (string) $v;
        }
    }

    return null;
};

// ─────────────────────────────────────────────────────────────────────────
// Iterate users, write resolved values into Stride's expected keys.
// Process in batches of 500 to keep memory reasonable.
// ─────────────────────────────────────────────────────────────────────────
$batch = 500;
$offset = 0;
$stats = ['users_seen' => 0, 'writes' => 0, 'skipped_already_set' => 0, 'no_value' => 0];

while (true) {
    $userIds = $wpdb->get_col($wpdb->prepare(
        "SELECT ID FROM ckqp_users ORDER BY ID ASC LIMIT %d OFFSET %d",
        $batch, $offset
    ));
    if (empty($userIds)) break;

    foreach ($userIds as $uid) {
        $uid = (int) $uid;
        $stats['users_seen']++;

        foreach ($map as $strideKey => [$fcrm, $xp, $wp]) {
            // Skip if Stride key already has a value (idempotent)
            $existing = get_user_meta($uid, $strideKey, true);
            if (!empty(trim((string) $existing))) {
                $stats['skipped_already_set']++;
                continue;
            }

            $value = $resolve($uid, $fcrm, $xp, $wp);
            if ($value === null) {
                $stats['no_value']++;
                continue;
            }

            update_user_meta($uid, $strideKey, $value);
            $stats['writes']++;
        }
    }

    $offset += $batch;
    if ($offset % 5000 === 0) {
        echo "  …processed $offset users (writes: {$stats['writes']})\n";
    }
}

echo "\nDone.\n";
echo "  users seen:           {$stats['users_seen']}\n";
echo "  meta writes:          {$stats['writes']}\n";
echo "  already-set, skipped: {$stats['skipped_already_set']}\n";
echo "  no source value:      {$stats['no_value']}\n";

// Spot-check the result for user 1
echo "\nUser 1 final state:\n";
foreach (array_keys($map) as $k) {
    $v = get_user_meta(1, $k, true);
    printf("  %-22s = %s\n", $k, $v ?: '(empty)');
}
