<?php
/**
 * Phase H — Removed enum value: 'withdrawn'
 *
 * Verify the value is rejected at the DB level after enum cleanup.
 */
require __DIR__ . '/shake-helpers.php';

use Stride\Domain\RegistrationStatus;

global $wpdb;

// --- H1: DB-level rejection of 'withdrawn' status ---
shake_section('H1: ALTERED ENUM rejects withdrawn');
$wpdb->query("DELETE FROM stride_vad_registrations WHERE user_id = 7790 AND edition_id = 13230");
$wpdb->suppress_errors(true);
$result = $wpdb->insert("stride_vad_registrations", [
    'user_id' => 7790,
    'edition_id' => 13230,
    'status' => 'withdrawn',
    'enrollment_path' => 'individual',
    'registered_at' => current_time('mysql'),
]);
$wpdb->suppress_errors(false);
if ($result === false || $wpdb->insert_id === 0) {
    echo "  H1 PASS: DB rejected 'withdrawn' insert\n";
} else {
    // MySQL non-strict mode might convert to '' or first enum value. Check actual stored value
    $stored = $wpdb->get_var("SELECT status FROM stride_vad_registrations WHERE id = " . (int)$wpdb->insert_id);
    if ($stored === 'withdrawn') {
        echo "  H1 FAIL: 'withdrawn' was accepted and stored\n";
    } else {
        echo "  H1 PASS: 'withdrawn' coerced/rejected (stored as '$stored')\n";
    }
    $wpdb->query("DELETE FROM stride_vad_registrations WHERE id = " . (int)$wpdb->insert_id);
}

// --- H2: Enum no longer exposes Withdrawn case ---
shake_section('H2: RegistrationStatus enum has no Withdrawn case');
$cases = array_map(fn($c) => $c->value, RegistrationStatus::cases());
echo "  enum cases: " . json_encode($cases) . "\n";
echo "  withdrawn absent: " . (!in_array('withdrawn', $cases, true) ? "OK" : "FAIL") . "\n";

// --- H3: tryFrom('withdrawn') returns null ---
shake_section('H3: tryFrom("withdrawn") returns null');
$r = RegistrationStatus::tryFrom('withdrawn');
echo "  result: " . ($r === null ? "null" : $r->value) . "\n";
echo "  expected null: " . ($r === null ? "OK" : "FAIL") . "\n";

echo "\n=== Phase H complete ===\n";
