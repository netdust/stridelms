<?php
/**
 * Stride seed coverage verifier.
 *
 * Run: ddev exec wp eval-file scripts/seed-verify.php
 * Exits non-zero on any missing dimension.
 *
 * Reads the `stride_seed_covers` option (entity-key → dimension tags, written
 * by scripts/seed/runner.php) plus the actual DB rows, and asserts every
 * required feature dimension from docs/plans/2026-06-12-seed-feature-matrix.md
 * is both CLAIMED (tag present) and TRUE (DB rows back the claim).
 *
 * Meta-key ground truth (verified against seeded rows 2026-06-12):
 * - vad_edition status  → `_ntdst_status`  (EditionCPT meta_prefix '_ntdst_')
 * - vad_quote status    → bare `status`    (QuoteCPT meta_prefix '')
 * - vad_voucher scope   → `_ntdst_scope_mode` (VoucherCPT meta_prefix '_ntdst_')
 */

if (!defined('ABSPATH')) {
    echo "Run via WP-CLI: ddev exec wp eval-file scripts/seed-verify.php\n";
    exit(1);
}

$failures = [];
$check = function (string $label, bool $ok) use (&$failures) {
    echo ($ok ? "  OK   " : "  FAIL ") . $label . "\n";
    if (!$ok) { $failures[] = $label; }
};

global $wpdb;
$manifest = get_option('stride_seed_manifest') ?: [];
$covers   = get_option('stride_seed_covers') ?: [];
$allTags  = array_unique(array_merge([], ...array_values($covers)));
$regTable = $wpdb->prefix . 'vad_registrations';
$attTable = $wpdb->prefix . 'vad_attendance';

echo "\n=== Stride seed coverage verification ===\n\n";
$check('stride_seed_manifest option present', !empty($manifest));
$check('stride_seed_covers option present', !empty($covers));

// ---------------------------------------------------------------------------
// 1. Tag-list completeness: every REQUIRED dimension tag is claimed by some entity
// ---------------------------------------------------------------------------
$required = [
    'model:pure_ld_open','model:closed_single','model:multi_edition','model:trajectory_course',
    'edition_type:in_person','edition_type:online','edition_type:hybrid','edition_type:webinar',
    'content:edition_fields','content:speakers_repeater',
    'sessions:slots_choose_n','sessions:selection_deadline','sessions:type_assignment','sessions:lesson_linked',
    'req:pre_session_selection','req:pre_questionnaire','req:pre_documents','req:pre_approval',
    'req:post_evaluation','req:post_documents','req:post_approval',
    'form:default','form:minimal','form:direct','form:custom_all_types','form:reserved_fields',
    'capacity:full_real_users','capacity:waitlist_behind_full',
    'trajectory:cohort','trajectory:self_paced','trajectory:elective_choose_n',
    'flow:attendance_marked','flow:post_course_ready',
];
foreach ($required as $tag) { $check("tag claimed: {$tag}", in_array($tag, $allTags, true)); }

// ---------------------------------------------------------------------------
// 2. DB truth (claims are not enough — verify against actual rows)
// ---------------------------------------------------------------------------
$statuses = $wpdb->get_col("SELECT DISTINCT pm.meta_value FROM {$wpdb->postmeta} pm
    JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = 'vad_edition'
    WHERE pm.meta_key = '_ntdst_status'");
foreach (['draft','announcement','open','full','in_progress','postponed','cancelled','completed','archived'] as $s) {
    $check("edition status in DB: {$s}", in_array($s, $statuses, true));
}

// Exclude display-only fake fill rows (user_id >= 900000); keep anonymous interest (NULL user).
$regStatuses = $wpdb->get_col("SELECT DISTINCT status FROM {$regTable} WHERE user_id < 900000 OR user_id IS NULL");
foreach (['confirmed','pending','completed','cancelled','waitlist','interest'] as $s) {
    $check("registration status in DB: {$s}", in_array($s, $regStatuses, true));
}

$paths = $wpdb->get_col("SELECT DISTINCT enrollment_path FROM {$regTable}");
foreach (['individual','colleague','trajectory','partner'] as $p) {
    $check("enrollment path: {$p}", in_array($p, $paths, true));
}

// Quote status uses BARE `status` meta key (QuoteCPT meta_prefix '') — verified against seeded rows.
$quoteStatuses = $wpdb->get_col("SELECT DISTINCT pm.meta_value FROM {$wpdb->postmeta} pm
    JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = 'vad_quote' WHERE pm.meta_key = 'status'");
foreach (['draft','sent','exported','cancelled'] as $s) {
    $check("quote status: {$s}", in_array($s, $quoteStatuses, true));
}

// ---------------------------------------------------------------------------
// 3. Spot checks with real reads (catches the json-decode-to-[] gotcha)
// ---------------------------------------------------------------------------
$editionRepo = ntdst_get(\Stride\Modules\Edition\EditionRepository::class);

$findEditionByTag = function (string $tag) use ($covers): ?int {
    foreach ($covers as $key => $tags) {
        if (str_starts_with($key, 'edition:') && in_array($tag, $tags, true)) {
            return (int) substr($key, 8);
        }
    }
    return null;
};

// 3a. Featured edition: speakers repeater + all 7 content fields
$featured = $findEditionByTag('content:speakers_repeater');
$speakers = $featured ? $editionRepo->getField($featured, 'speakers') : null;
$check('featured edition speakers is non-empty {name,role} array',
    is_array($speakers) && !empty($speakers) && isset($speakers[0]['name'], $speakers[0]['role']));
foreach (['target_audience','required_experience','included','price_includes','cancellation_policy','cta_benefits','enrollment_info'] as $f) {
    $check("featured edition content field set: {$f}", $featured && (string) $editionRepo->getField($featured, $f) !== '');
}

// 3b. Full-real edition: count(real confirmed regs) == capacity AND a waitlist reg behind it
$fullEdition = $findEditionByTag('capacity:full_real_users');
$check('full-real edition tagged in covers', $fullEdition !== null);
if ($fullEdition) {
    $capacity = (int) $editionRepo->getField($fullEdition, 'capacity');
    $confirmed = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$regTable} WHERE edition_id = %d AND status = 'confirmed' AND user_id < 900000",
        $fullEdition
    ));
    $waitlist = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$regTable} WHERE edition_id = %d AND status = 'waitlist'",
        $fullEdition
    ));
    $check("full-real edition confirmed count ({$confirmed}) == capacity ({$capacity})", $capacity > 0 && $confirmed === $capacity);
    $check('full-real edition has >=1 waitlist registration behind it', $waitlist >= 1);
}

// 3c. Voucher scopes: scope_mode all/only/except each present on >=1 vad_voucher
$scopeModes = $wpdb->get_col("SELECT DISTINCT pm.meta_value FROM {$wpdb->postmeta} pm
    JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = 'vad_voucher'
    WHERE pm.meta_key = '_ntdst_scope_mode'");
foreach (['all','only','except'] as $mode) {
    $check("voucher scope_mode present: {$mode}", in_array($mode, $scopeModes, true));
}

// 3d. Questionnaire: qg_enrollment_seed group exists, has 8 fields, types include all 7
$qRepo  = ntdst_get(\Stride\Modules\Questionnaire\QuestionnaireRepository::class);
$groups = array_column($qRepo->getAllGroups(), null, 'id');
$enrollGroup = $groups['qg_enrollment_seed'] ?? null;
$check('questionnaire group qg_enrollment_seed exists', $enrollGroup !== null);
$enrollFields = $enrollGroup['fields'] ?? [];
$check('qg_enrollment_seed has 8 fields', count($enrollFields) === 8);
$fieldTypes = array_unique(array_column($enrollFields, 'type'));
foreach (['description','text','textarea','select','radio','checkbox','scale'] as $type) {
    $check("qg_enrollment_seed covers field type: {$type}", in_array($type, $fieldTypes, true));
}

// 3e. qg_eval_seed resolves to >=1 course assignment (cluster-2 review addition)
$evalGroup = $groups['qg_eval_seed'] ?? null;
$check('questionnaire group qg_eval_seed exists', $evalGroup !== null);
$check('qg_eval_seed has >=1 course assignment', !empty($evalGroup['assignments']));

// 3f. Partner data: >=1 registration with enrollment_path='partner' AND company_id=1
$partnerCount = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM {$regTable} WHERE enrollment_path = 'partner' AND company_id = 1"
);
$check("partner-path registration with company_id=1 exists", $partnerCount >= 1);

// 3g. Attendance: >=1 row in vad_attendance for a seed user (manifest users)
$seedUserIds = array_map('intval', $manifest['users'] ?? []);
if (!empty($seedUserIds)) {
    $in = implode(',', $seedUserIds);
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- int-cast IDs
    $attCount = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$attTable} WHERE user_id IN ({$in})");
} else {
    $attCount = 0;
}
$check('attendance row exists for a seed user', $attCount >= 1);

// 3h. Timestamp truth (cluster-2 review additions): seed registrations carry
// cancelled_at / completed_at where their status says so.
$regIds = array_map('intval', $manifest['registrations'] ?? []);
if (!empty($regIds)) {
    $in = implode(',', $regIds);
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- int-cast IDs
    $cancelledMissing = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$regTable} WHERE id IN ({$in}) AND status = 'cancelled' AND cancelled_at IS NULL"
    );
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- int-cast IDs
    $cancelledTotal = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$regTable} WHERE id IN ({$in}) AND status = 'cancelled'"
    );
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- int-cast IDs
    $completedMissing = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$regTable} WHERE id IN ({$in}) AND status = 'completed' AND completed_at IS NULL"
    );
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- int-cast IDs
    $completedTotal = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$regTable} WHERE id IN ({$in}) AND status = 'completed'"
    );
    $check('cancelled seed registration exists with cancelled_at set', $cancelledTotal >= 1 && $cancelledMissing === 0);
    $check('completed seed registration exists with completed_at set', $completedTotal >= 1 && $completedMissing === 0);
} else {
    $check('manifest contains registration ids (needed for timestamp checks)', false);
}

// ---------------------------------------------------------------------------
echo "\n" . (empty($failures) ? "ALL DIMENSIONS COVERED\n" : count($failures) . " FAILURES\n");
exit(empty($failures) ? 0 : 1);
