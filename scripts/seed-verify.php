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
    if (!$ok) {
        $failures[] = $label;
    }
};

global $wpdb;
$manifest = get_option('stride_seed_manifest') ?: [];
$covers   = get_option('stride_seed_covers') ?: [];
$allTags  = array_unique(array_merge([], ...array_values($covers)));
$regTable = $wpdb->prefix . 'vad_registrations';
$attTable = $wpdb->prefix . 'vad_attendance';
$regIds   = array_map('intval', $manifest['registrations'] ?? []);
$regIdIn  = !empty($regIds) ? implode(',', $regIds) : '0';

// Seed-scope join fragment: constrains CPT queries to rows created by the seeder,
// so pre-existing / v3-ported rows can never produce a false PASS.
$seedJoin = "JOIN {$wpdb->postmeta} seed ON seed.post_id = p.ID
    AND seed.meta_key = '_stride_seed_data' AND seed.meta_value = '1'";

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
    'capacity:unlimited','capacity:full_fake_display',
    'voucher:full','voucher:fixed','voucher:percentage','voucher:scope_all',
    'sessions:type_in_person','sessions:type_online','sessions:type_webinar',
    'status:few_spots',
    'trajectory:cohort','trajectory:self_paced','trajectory:elective_choose_n',
    'flow:attendance_marked','flow:post_course_ready',
    // Dateless dimension (2026-06-14 dateless-editions-catalog): a klassikaal
    // interest anchor (Announcement, no dates) and an always-on online
    // enrollable (Open, no dates). Both must be seeded so the catalog feature
    // can be exercised end-to-end on /klassikaal and /online.
    'date:dateless_klassikaal','date:dateless_online',
];
foreach ($required as $tag) {
    $check("tag claimed: {$tag}", in_array($tag, $allTags, true));
}

// ---------------------------------------------------------------------------
// 2. DB truth (claims are not enough — verify against actual rows)
// ---------------------------------------------------------------------------
$statuses = $wpdb->get_col("SELECT DISTINCT pm.meta_value FROM {$wpdb->postmeta} pm
    JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = 'vad_edition'
    {$seedJoin}
    WHERE pm.meta_key = '_ntdst_status'");
// Status list derived from the enum so the verifier can never drift from the domain model.
$offeringStatuses = array_column(\Stride\Domain\OfferingStatus::cases(), 'value');
foreach ($offeringStatuses as $s) {
    $check("edition status in DB (seed-scoped): {$s}", in_array($s, $statuses, true));
}

// Seed-scoped via manifest ids. Exclude display-only fake fill rows (user_id >= 900000);
// keep anonymous interest (NULL user).
// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- int-cast IDs
$regStatuses = $wpdb->get_col("SELECT DISTINCT status FROM {$regTable}
    WHERE id IN ({$regIdIn}) AND (user_id < 900000 OR user_id IS NULL)");
foreach (['confirmed','pending','completed','cancelled','waitlist','interest'] as $s) {
    $check("registration status in DB (seed-scoped): {$s}", in_array($s, $regStatuses, true));
}

// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- int-cast IDs
$paths = $wpdb->get_col("SELECT DISTINCT enrollment_path FROM {$regTable} WHERE id IN ({$regIdIn})");
foreach (['individual','colleague','trajectory','partner'] as $p) {
    $check("enrollment path (seed-scoped): {$p}", in_array($p, $paths, true));
}

// Quote status uses BARE `status` meta key (QuoteCPT meta_prefix '') — verified against seeded rows.
$quoteStatuses = $wpdb->get_col("SELECT DISTINCT pm.meta_value FROM {$wpdb->postmeta} pm
    JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = 'vad_quote'
    {$seedJoin}
    WHERE pm.meta_key = 'status'");
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

// 3a. Featured edition: speakers repeater + all 7 content fields.
// Empirically verified 2026-06-12: an edition created WITHOUT content fields returns
// '' from getField('required_experience') — admin-metabox default copy does NOT leak
// into reads, so the `!== ''` checks below genuinely bite.
$featured = $findEditionByTag('content:speakers_repeater');
$speakers = $featured ? $editionRepo->getField($featured, 'speakers') : null;
$check(
    'featured edition speakers is non-empty {name,role} array',
    is_array($speakers) && !empty($speakers) && isset($speakers[0]['name'], $speakers[0]['role']),
);
foreach (['target_audience','required_experience','included','price_includes','cancellation_policy','cta_benefits','enrollment_info'] as $f) {
    $check("featured edition content field set: {$f}", $featured && (string) $editionRepo->getField($featured, $f) !== '');
}

// 3b. Full-real edition: count(real confirmed regs) == capacity AND a waitlist reg behind it
$fullEdition = $findEditionByTag('capacity:full_real_users');
$check('full-real edition tagged in covers', $fullEdition !== null);
if ($fullEdition) {
    $capacity = (int) $editionRepo->getField($fullEdition, 'capacity');
    // Mirrors EditionService::getRegisteredCount() capacity logic:
    // status IN ('confirmed','completed','pending') counts toward capacity.
    $confirmed = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$regTable} WHERE edition_id = %d
         AND status IN ('confirmed','completed','pending') AND user_id < 900000",
        $fullEdition,
    ));
    $waitlist = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$regTable} WHERE edition_id = %d AND status = 'waitlist'",
        $fullEdition,
    ));
    $check("full-real edition confirmed count ({$confirmed}) == capacity ({$capacity})", $capacity > 0 && $confirmed === $capacity);
    $check('full-real edition has >=1 waitlist registration behind it', $waitlist >= 1);
}

// 3c. Voucher scopes: scope_mode all/only/except each present on >=1 vad_voucher
$scopeModes = $wpdb->get_col("SELECT DISTINCT pm.meta_value FROM {$wpdb->postmeta} pm
    JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = 'vad_voucher'
    {$seedJoin}
    WHERE pm.meta_key = '_ntdst_scope_mode'");
foreach (['all','only','except'] as $mode) {
    $check("voucher scope_mode present (seed-scoped): {$mode}", in_array($mode, $scopeModes, true));
}

// Voucher discount types: full / fixed / percentage each present on >=1 seed voucher
// (matches \Stride\Domain\DiscountType cases).
$discountTypes = $wpdb->get_col("SELECT DISTINCT pm.meta_value FROM {$wpdb->postmeta} pm
    JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = 'vad_voucher'
    {$seedJoin}
    WHERE pm.meta_key = '_ntdst_discount_type'");
foreach (['full','fixed','percentage'] as $dt) {
    $check("voucher discount_type present (seed-scoped): {$dt}", in_array($dt, $discountTypes, true));
}

// 3c-bis. Dateless editions: the catalog eligibility query keys clause (3) off
// start_date/end_date being absent (NOT EXISTS), so a seeded dateless edition
// MUST carry NEITHER meta key, and its status must resolve correctly
// (Announcement for klassikaal → interest band; Open for online → enroll).
$assertDateless = function (string $tag, string $expectedStatus) use ($findEditionByTag, $editionRepo, $check): void {
    $id = $findEditionByTag($tag);
    $check("{$tag} edition seeded", $id !== null);
    if ($id === null) {
        return;
    }
    $start = get_post_meta($id, '_ntdst_start_date', true);
    $end   = get_post_meta($id, '_ntdst_end_date', true);
    $check("{$tag} has no start_date meta (catalog NOT EXISTS clause)", $start === '' || $start === false);
    $check("{$tag} has no end_date meta (catalog NOT EXISTS clause)", $end === '' || $end === false);
    $check("{$tag} stored status is {$expectedStatus}", (string) $editionRepo->getField($id, 'status') === $expectedStatus);
};
$assertDateless('date:dateless_klassikaal', 'announcement');
$assertDateless('date:dateless_online', 'open');

// 3d. Questionnaire: qg_enrollment_seed group exists, has 8 fields, types include all 7
$qRepo  = ntdst_get(\Stride\Modules\Questionnaire\QuestionnaireRepository::class);
$groups = array_column($qRepo->getAllGroups(), null, 'id');
$enrollGroup = $groups['qg_enrollment_seed'] ?? null;
$check('questionnaire group qg_enrollment_seed exists', $enrollGroup !== null);
$enrollFields = $enrollGroup['fields'] ?? [];
$check('qg_enrollment_seed has >=8 fields', count($enrollFields) >= 8);
$fieldTypes = array_unique(array_column($enrollFields, 'type'));
foreach (['description','text','textarea','select','radio','checkbox','scale'] as $type) {
    $check("qg_enrollment_seed covers field type: {$type}", in_array($type, $fieldTypes, true));
}

// 3e. qg_eval_seed resolves to >=1 assignment (cluster-2 review addition)
$evalGroup = $groups['qg_eval_seed'] ?? null;
$check('questionnaire group qg_eval_seed exists', $evalGroup !== null);
$check('qg_eval_seed has >=1 assignment', !empty($evalGroup['assignments']));

// 3e-bis. Shakeout F1: assignments must be EDITION ids, not course ids —
// QuestionnaireRepository::matchesAssignment strict-matches the rendered
// vad_edition post ID on /inschrijven/, so a course-id assignment never renders.
$enrollHasEditionAssignment = false;
foreach ((array) ($enrollGroup['assignments'] ?? []) as $aid) {
    if (is_int($aid) && get_post_type($aid) === 'vad_edition') {
        $enrollHasEditionAssignment = true;
        break;
    }
}
$check('qg_enrollment_seed has >=1 vad_edition assignment id', $enrollHasEditionAssignment);

// 3f. Partner data: >=1 registration with enrollment_path='partner' AND company_id=1
$partnerCount = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM {$regTable} WHERE enrollment_path = 'partner' AND company_id = 1",
);
$check("partner-path registration with company_id=1 exists", $partnerCount >= 1);

// 3g. Attendance: >=1 row in vad_attendance for a seed user ON a seed edition
// (both constraints, so v3-ported attendance can never satisfy the check).
$seedUserIds    = array_map('intval', $manifest['users'] ?? []);
$seedEditionIds = array_map('intval', $manifest['editions'] ?? []);
if (!empty($seedUserIds) && !empty($seedEditionIds)) {
    $uin = implode(',', $seedUserIds);
    $ein = implode(',', $seedEditionIds);
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- int-cast IDs
    $attCount = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$attTable} WHERE user_id IN ({$uin}) AND edition_id IN ({$ein})",
    );
} else {
    $attCount = 0;
}
$check('attendance row exists for a seed user on a seed edition', $attCount >= 1);

// 3h. Timestamp truth (cluster-2 review additions): seed registrations carry
// cancelled_at / completed_at where their status says so.
$regIds = array_map('intval', $manifest['registrations'] ?? []);
if (!empty($regIds)) {
    $in = implode(',', $regIds);
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- int-cast IDs
    $cancelledMissing = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$regTable} WHERE id IN ({$in}) AND status = 'cancelled' AND cancelled_at IS NULL",
    );
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- int-cast IDs
    $cancelledTotal = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$regTable} WHERE id IN ({$in}) AND status = 'cancelled'",
    );
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- int-cast IDs
    $completedMissing = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$regTable} WHERE id IN ({$in}) AND status = 'completed' AND completed_at IS NULL",
    );
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- int-cast IDs
    $completedTotal = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$regTable} WHERE id IN ({$in}) AND status = 'completed'",
    );
    $check('cancelled seed registration exists with cancelled_at set', $cancelledTotal >= 1 && $cancelledMissing === 0);
    $check('completed seed registration exists with completed_at set', $completedTotal >= 1 && $completedMissing === 0);
} else {
    $check('manifest contains registration ids (needed for timestamp checks)', false);
}

// 3i. Shakeout F3: >=1 pending seed registration is APPROVAL-READY — all user
// tasks completed, approval task present and still open (feeds the admin
// "Wacht op mij" bucket in /admin/pending-approvals).
$approvalReady = 0;
if (!empty($regIds)) {
    $in = implode(',', $regIds);
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- int-cast IDs
    $pendingRows = $wpdb->get_results(
        "SELECT id, completion_tasks FROM {$regTable}
         WHERE id IN ({$in}) AND status = 'pending' AND completion_tasks IS NOT NULL",
    );
    foreach ($pendingRows as $row) {
        $tasks = json_decode((string) $row->completion_tasks, true) ?: [];
        if (!isset($tasks['approval']) || ($tasks['approval']['status'] ?? 'pending') === 'completed') {
            continue;
        }
        $userDone = true;
        foreach ($tasks as $type => $task) {
            if ($type === 'approval' || $type === 'post_approval') {
                continue;
            }
            if (($task['status'] ?? 'pending') !== 'completed') {
                $userDone = false;
                break;
            }
        }
        if ($userDone) {
            $approvalReady++;
        }
    }
}
$check('>=1 pending seed registration approval-ready (user tasks complete, approval open)', $approvalReady >= 1);

// ---------------------------------------------------------------------------
// 4. Demo persona (seed_completed_user) — every dashboard surface populated.
//    Drives the SAME reads the dashboard tabs use, so a PASS here means the
//    rendered surface will populate (premise ground-truth: trajectory needs a
//    PARENT row, certificate needs a genuine LD completion + assigned cert).
// ---------------------------------------------------------------------------
$demo = get_user_by('email', 'seed_completed_user@seed.test');
$check('demo persona user exists', $demo instanceof WP_User);
if ($demo instanceof WP_User) {
    $uid = (int) $demo->ID;

    // F3 Meldingen: >=1 notification, >=1 unread (real product read)
    $notif = ntdst_get(\Stride\Modules\Notification\NotificationService::class);
    $all   = $notif->getNotifications($uid);
    $check('demo persona has >=1 notification', count($all) >= 1);
    $check('demo persona has >=1 UNREAD notification', $notif->getUnreadCount($uid) >= 1);

    // F2 Certificaten: >=1 completed edition reg whose course yields a cert link
    $regRepo = ntdst_get(\Stride\Modules\Enrollment\RegistrationRepository::class);
    $edSvc   = ntdst_get(\Stride\Modules\Edition\EditionService::class);
    $hasCertLink = false;
    foreach ($regRepo->findByUser($uid) as $r) {
        if (($r->status ?? '') !== 'completed' || empty($r->edition_id)) {
            continue;
        }
        $cid = (int) $edSvc->getCourseId((int) $r->edition_id);
        if ($cid && \Stride\Integrations\LearnDash\LearnDashHelper::getCertificateLink($cid, $uid) !== '') {
            $hasCertLink = true;
            break;
        }
    }
    $check('demo persona has a downloadable certificate link', $hasCertLink);

    // F4 Offertes: >=1 quote tied to the demo persona (vad_quote user_id meta is BARE)
    $quoteCount = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->postmeta} pm
         JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = 'vad_quote'
         WHERE pm.meta_key = 'user_id' AND pm.meta_value = %d",
        $uid,
    ));
    $check('demo persona has >=1 quote', $quoteCount >= 1);

    // F5 Trajecten: >=1 PARENT trajectory enrollment (the corrected premise)
    $trajEnrollments = $regRepo->findTrajectoryEnrollmentsByUser($uid);
    $check('demo persona has >=1 parent trajectory enrollment (tab-trajecten read)', count($trajEnrollments) >= 1);

    // F1 Dashboard pending task: >=1 pending reg with open completion_tasks
    $pendingWithTasks = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}vad_registrations
         WHERE user_id = %d AND status = 'pending' AND completion_tasks IS NOT NULL",
        $uid,
    ));
    $check('demo persona has >=1 pending registration with a completion task', $pendingWithTasks >= 1);
}

// ---------------------------------------------------------------------------
echo "\n" . (empty($failures) ? "ALL DIMENSIONS COVERED\n" : count($failures) . " FAILURES\n");
// NOTE: `ddev exec bash -c '...; echo $?'` always shows 0 — ddev expands `$?`
// in its own wrapper shell. Check the HOST-side `$?` after `ddev exec` instead.
exit(empty($failures) ? 0 : 1);
