<?php
/**
 * Manual shake-out for trajectory cascade-enrollment (Stap 14).
 *
 * Walks the full cascade lifecycle end-to-end against real DDEV with real
 * LearnDash, real DB, real services. Each section creates its own scoped
 * data (users + trajectory + editions + courses), asserts at each step,
 * prints PASS/FAIL, and cleans up in a final teardown so the script is
 * idempotent and re-runnable.
 *
 * Run:
 *   ddev exec wp eval-file tests/manual/shake-cascade.php
 *
 * Reports a final summary line; exit status reflects test outcome.
 */

require __DIR__ . '/shake-helpers.php';

use Stride\Contracts\LMSAdapterInterface;
use Stride\Domain\OfferingStatus;
use Stride\Domain\RegistrationStatus;
use Stride\Domain\TrajectoryMode;
use Stride\Modules\Enrollment\EnrollmentService;
use Stride\Modules\Enrollment\RegistrationRepository;
use Stride\Modules\Trajectory\TrajectoryCascadeService;
use Stride\Modules\Trajectory\TrajectoryCPT;
use Stride\Modules\Trajectory\TrajectorySelection;

global $shake_pass, $shake_fail, $shake_artifacts;
$shake_pass = 0;
$shake_fail = 0;
$shake_artifacts = [
    'users' => [],
    'posts' => [],
    'registrations' => [],
    'quotes' => [],
];

// === Local helpers (scoped to this script) ============================

function cascade_assert(bool $ok, string $label): void
{
    global $shake_pass, $shake_fail;
    if ($ok) {
        $shake_pass++;
        echo "  PASS: $label\n";
    } else {
        $shake_fail++;
        echo "  FAIL: $label\n";
    }
}

function cascade_user(string $prefix): int
{
    global $shake_artifacts;
    $username = $prefix . '_' . wp_generate_password(6, false);
    $userId = wp_create_user($username, 'pw', $username . '@cascade.shake.test');
    if (is_wp_error($userId)) {
        echo "  setup error: " . $userId->get_error_message() . "\n";
        exit(1);
    }
    $shake_artifacts['users'][] = $userId;
    return $userId;
}

function cascade_course(): int
{
    global $shake_artifacts;
    $id = wp_insert_post([
        'post_type' => 'sfwd-courses',
        'post_title' => 'Cascade shake course ' . wp_generate_password(4, false),
        'post_status' => 'publish',
    ]);
    if (is_wp_error($id)) {
        echo "  setup error course: " . $id->get_error_message() . "\n";
        exit(1);
    }
    $shake_artifacts['posts'][] = $id;
    return $id;
}

function cascade_edition(int $courseId = 0, int $capacity = 0, float $price = 0.0): int
{
    global $shake_artifacts;
    $id = wp_insert_post([
        'post_type' => 'vad_edition',
        'post_title' => 'Cascade shake edition ' . wp_generate_password(4, false),
        'post_status' => 'publish',
    ]);
    if (is_wp_error($id)) {
        echo "  setup error edition: " . $id->get_error_message() . "\n";
        exit(1);
    }
    $shake_artifacts['posts'][] = $id;

    update_post_meta($id, '_ntdst_status', OfferingStatus::Open->value);
    update_post_meta($id, '_ntdst_capacity', $capacity);
    update_post_meta($id, '_ntdst_price', $price);
    update_post_meta($id, '_ntdst_price_non_member', $price);
    update_post_meta($id, '_ntdst_start_date', date('Y-m-d', strtotime('+30 days')));
    update_post_meta($id, '_ntdst_end_date', date('Y-m-d', strtotime('+60 days')));
    if ($courseId > 0) {
        update_post_meta($id, '_ntdst_course_id', $courseId);
    }

    return $id;
}

function cascade_trajectory(TrajectoryMode $mode, array $courses, float $price = 0.0): int
{
    global $shake_artifacts;
    $id = wp_insert_post([
        'post_type' => TrajectoryCPT::POST_TYPE,
        'post_title' => 'Cascade shake trajectory ' . wp_generate_password(4, false),
        'post_status' => 'publish',
    ]);
    if (is_wp_error($id)) {
        echo "  setup error trajectory: " . $id->get_error_message() . "\n";
        exit(1);
    }
    $shake_artifacts['posts'][] = $id;

    $model = ntdst_data()->get(TrajectoryCPT::POST_TYPE);
    $model->update($id, [
        'mode' => $mode->value,
        'status' => OfferingStatus::Open->value,
        'capacity' => 0,
        'price' => $price,
        'price_non_member' => $price,
        'courses' => $courses,
        'choice_available_date' => date('Y-m-d', strtotime('-1 day')),
    ]);

    return $id;
}

function cascade_has_ld_access(int $userId, int $courseId): bool
{
    return function_exists('sfwd_lms_has_access')
        && (bool) sfwd_lms_has_access($courseId, $userId);
}

function cascade_pure_ld_meta(int $userId): array
{
    $raw = get_user_meta($userId, TrajectoryCascadeService::TRAJECTORY_COURSES_META_KEY, true);
    return is_array($raw) ? array_values($raw) : [];
}

// === Setup ============================================================

$selection = ntdst_get(TrajectorySelection::class);
$enrollment = ntdst_get(EnrollmentService::class);
$cascade = ntdst_get(TrajectoryCascadeService::class);
$repo = ntdst_get(RegistrationRepository::class);
$lms = ntdst_get(LMSAdapterInterface::class);

echo "\n*** Trajectory cascade shake-out — " . date('c') . " ***\n";

// =====================================================================
shake_section('C1 — Cohort enrollment: mandatory edition + pure-LD course');
// =====================================================================
$user = cascade_user('shake_c1');
wp_set_current_user($user);
$courseA = cascade_course();
$onlineCourse = cascade_course();
$editionA = cascade_edition($courseA);
$trajectory = cascade_trajectory(TrajectoryMode::Cohort, [
    ['type' => 'edition', 'course_id' => $courseA, 'edition_id' => $editionA, 'required' => true, 'order' => 1],
    ['type' => 'online', 'course_id' => $onlineCourse, 'required' => true, 'order' => 2],
]);

$parentId = $selection->enroll($user, $trajectory);
cascade_assert(!is_wp_error($parentId) && $parentId > 0, "C1.1 enroll() returned parent id");
$shake_artifacts['registrations'][] = $parentId;

$children = $repo->findByParent($parentId);
cascade_assert(count($children) === 1, "C1.2 one mandatory child row created (got " . count($children) . ")");
cascade_assert(count($children) > 0 && (int) $children[0]->edition_id === $editionA, "C1.3 child linked to mandatory edition");
cascade_assert(cascade_has_ld_access($user, $courseA), "C1.4 LD access granted for child's edition course");

$meta = cascade_pure_ld_meta($user);
cascade_assert(count($meta) === 1 && (int) ($meta[0]['course_id'] ?? 0) === $onlineCourse, "C1.5 pure-LD course recorded in user meta");
cascade_assert(cascade_has_ld_access($user, $onlineCourse), "C1.6 LD access granted for pure-LD course");

// =====================================================================
shake_section('C2 — Cohort elective selection: add then remove');
// =====================================================================
$user2 = cascade_user('shake_c2');
wp_set_current_user($user2);
$electiveCourse = cascade_course();
$electiveEdition = cascade_edition($electiveCourse);
$trajectory2 = cascade_trajectory(TrajectoryMode::Cohort, []);
$parentId2 = $selection->enroll($user2, $trajectory2);
$shake_artifacts['registrations'][] = $parentId2;

// Add elective
$result = $selection->setSelections($parentId2, [$electiveEdition]);
cascade_assert($result === true, "C2.1 setSelections([electiveEdition]) succeeded");
$children = $repo->findByParent($parentId2);
cascade_assert(count($children) === 1, "C2.2 child row created for elective");
cascade_assert(cascade_has_ld_access($user2, $electiveCourse), "C2.3 LD access granted for elective course");

// Remove elective
$result = $selection->setSelections($parentId2, []);
cascade_assert($result === true, "C2.4 setSelections([]) removes elective");
$children = $repo->findByParent($parentId2);
$cancelledStatuses = array_map(fn($c) => (string) $c->status, $children);
cascade_assert(in_array(RegistrationStatus::Cancelled->value, $cancelledStatuses, true), "C2.5 elective child cancelled (not deleted)");
cascade_assert(!cascade_has_ld_access($user2, $electiveCourse), "C2.6 LD access revoked on removal");

// =====================================================================
shake_section('C3 — Cohort capacity: full elective returns edition_full, others still land');
// =====================================================================
$user3 = cascade_user('shake_c3');
wp_set_current_user($user3);
$fullCourse = cascade_course();
$fullEdition = cascade_edition($fullCourse, capacity: 1);
$openCourse = cascade_course();
$openEdition = cascade_edition($openCourse);

// Fill the capacity-1 edition
$filler = cascade_user('shake_c3_filler');
$fillerReg = $repo->create([
    'user_id' => $filler,
    'edition_id' => $fullEdition,
    'status' => RegistrationStatus::Confirmed->value,
]);
$shake_artifacts['registrations'][] = $fillerReg;

$trajectory3 = cascade_trajectory(TrajectoryMode::Cohort, []);
$parentId3 = $selection->enroll($user3, $trajectory3);
$shake_artifacts['registrations'][] = $parentId3;

$result = $selection->setSelections($parentId3, [$fullEdition, $openEdition]);
cascade_assert(is_wp_error($result) && $result->get_error_code() === 'edition_full', "C3.1 setSelections returns edition_full for full edition");

$children = $repo->findByParent($parentId3);
$childEditionIds = array_map(fn($c) => (int) $c->edition_id, $children);
cascade_assert(in_array($openEdition, $childEditionIds, true), "C3.2 non-full edition still got a child (partial success)");
cascade_assert(!in_array($fullEdition, $childEditionIds, true), "C3.3 full edition produced no child");

// =====================================================================
shake_section('C4 — Cohort parent cancellation: full blast radius');
// =====================================================================
$user4 = cascade_user('shake_c4');
wp_set_current_user($user4);
$courseC4 = cascade_course();
$editionC4 = cascade_edition($courseC4);
$onlineC4 = cascade_course();
$trajectory4 = cascade_trajectory(TrajectoryMode::Cohort, [
    ['type' => 'edition', 'course_id' => $courseC4, 'edition_id' => $editionC4, 'required' => true, 'order' => 1],
    ['type' => 'online', 'course_id' => $onlineC4, 'required' => true, 'order' => 2],
]);
$parentId4 = $selection->enroll($user4, $trajectory4);
$shake_artifacts['registrations'][] = $parentId4;

cascade_assert(cascade_has_ld_access($user4, $courseC4), "C4.1 LD granted on child");
cascade_assert(cascade_has_ld_access($user4, $onlineC4), "C4.2 LD granted on pure-LD course");
cascade_assert(count(cascade_pure_ld_meta($user4)) === 1, "C4.3 pure-LD meta seeded");

// Cancel parent via EnrollmentService (the canonical path, fires the event)
$cancelResult = $enrollment->cancel($parentId4);
cascade_assert($cancelResult === true, "C4.4 EnrollmentService->cancel succeeded");

$childAfter = $repo->findByParent($parentId4);
$cancelled = array_filter($childAfter, fn($c) => (string) $c->status === RegistrationStatus::Cancelled->value);
cascade_assert(count($cancelled) === count($childAfter) && count($childAfter) > 0, "C4.5 all children cancelled by listener");
cascade_assert(!cascade_has_ld_access($user4, $courseC4), "C4.6 LD revoked on child course");
cascade_assert(!cascade_has_ld_access($user4, $onlineC4), "C4.7 LD revoked on pure-LD course");
cascade_assert(count(cascade_pure_ld_meta($user4)) === 0, "C4.8 pure-LD meta cleared");

// =====================================================================
shake_section('C5 — Cohort status change: Pending → Confirmed via confirmRegistration');
// =====================================================================
$user5 = cascade_user('shake_c5');
wp_set_current_user($user5);
$courseC5 = cascade_course();
$editionC5 = cascade_edition($courseC5);
$trajectory5 = cascade_trajectory(TrajectoryMode::Cohort, [
    ['type' => 'edition', 'course_id' => $courseC5, 'edition_id' => $editionC5, 'required' => true, 'order' => 1],
]);

// Enroll, then force parent + child to Pending (simulates admin-approval flow)
$parentId5 = $selection->enroll($user5, $trajectory5);
$shake_artifacts['registrations'][] = $parentId5;

$repo->updateStatus($parentId5, RegistrationStatus::Pending);
$childBefore = $repo->findByParent($parentId5)[0];
$repo->updateStatus((int) $childBefore->id, RegistrationStatus::Pending);
$lms->revokeAccess($user5, $courseC5);
cascade_assert(!cascade_has_ld_access($user5, $courseC5), "C5.1 LD revoked while child Pending");

// Admin confirms via EnrollmentService::confirmRegistration → fires confirmed event → cascade
$confirmResult = $enrollment->confirmRegistration($parentId5);
cascade_assert($confirmResult === true, "C5.2 confirmRegistration succeeded");

$childAfter = $repo->findByParent($parentId5)[0];
cascade_assert((string) $childAfter->status === RegistrationStatus::Confirmed->value, "C5.3 child status propagated to Confirmed");
cascade_assert(cascade_has_ld_access($user5, $courseC5), "C5.4 LD access granted via cascade on confirm");

// =====================================================================
shake_section('C6 — Self-paced isolation: parent cancel does NOT cascade');
// =====================================================================
$user6 = cascade_user('shake_c6');
wp_set_current_user($user6);
$courseC6 = cascade_course();
$editionC6 = cascade_edition($courseC6);
$trajectory6 = cascade_trajectory(TrajectoryMode::SelfPaced, [
    ['type' => 'edition', 'course_id' => $courseC6, 'edition_id' => $editionC6, 'required' => true, 'order' => 1],
]);
$parentId6 = $selection->enroll($user6, $trajectory6);
$shake_artifacts['registrations'][] = $parentId6;

cascade_assert(cascade_has_ld_access($user6, $courseC6), "C6.1 LD granted on self-paced child");

$enrollment->cancel($parentId6);
$childAfter6 = $repo->findByParent($parentId6)[0];
cascade_assert((string) $childAfter6->status === RegistrationStatus::Confirmed->value, "C6.2 self-paced child stays Confirmed");
cascade_assert(cascade_has_ld_access($user6, $courseC6), "C6.3 self-paced LD access preserved");

// =====================================================================
shake_section('C7 — Quote auto-generation: free trajectory + paid edition');
// =====================================================================
$user7 = cascade_user('shake_c7');
wp_set_current_user($user7);
$courseC7 = cascade_course();
$paidEdition = cascade_edition($courseC7, price: 75.00);
$trajectory7 = cascade_trajectory(TrajectoryMode::Cohort, [], price: 0.0);
$parentId7 = $selection->enroll($user7, $trajectory7);
$shake_artifacts['registrations'][] = $parentId7;

$selection->setSelections($parentId7, [$paidEdition]);
$child7 = $repo->findByParent($parentId7)[0];

cascade_assert((int) ($child7->quote_id ?? 0) > 0, "C7.1 child has a generated quote");

$quoteId = (int) $child7->quote_id;
if ($quoteId > 0) {
    $shake_artifacts['quotes'][] = $quoteId;
    $quote = ntdst_get(\Stride\Modules\Invoicing\QuoteService::class)->getQuote($quoteId);
    cascade_assert(!is_wp_error($quote) && (int) ($quote['subtotal'] ?? 0) === 7500, "C7.2 quote subtotal = €75 = 7500 cents (got " . (int) ($quote['subtotal'] ?? 0) . ")");
}

// Now remove the elective — quote should auto-cancel.
$selection->setSelections($parentId7, []);
$quoteAfter = ntdst_get(\Stride\Modules\Invoicing\QuoteService::class)->getQuote($quoteId, true);
cascade_assert(!is_wp_error($quoteAfter) && (string) ($quoteAfter['status'] ?? '') === 'cancelled', "C7.3 quote auto-cancelled when child removed");

// =====================================================================
shake_section('C8 — Backfill: pre-cascade parent gets children retro-actively');
// =====================================================================
$user8 = cascade_user('shake_c8');
wp_set_current_user($user8);
$courseC8 = cascade_course();
$editionC8 = cascade_edition($courseC8);
$electiveC8 = cascade_edition();
$trajectory8 = cascade_trajectory(TrajectoryMode::Cohort, [
    ['type' => 'edition', 'course_id' => $courseC8, 'edition_id' => $editionC8, 'required' => true, 'order' => 1],
]);

// Hand-craft a pre-cascade parent: trajectory enrollment row with selections JSON
// but no children — exactly the shape backfill is meant to fix.
$orphanParent = $repo->create([
    'user_id' => $user8,
    'trajectory_id' => $trajectory8,
    'enrollment_path' => RegistrationRepository::PATH_TRAJECTORY,
    'selections' => [$electiveC8],
]);
$shake_artifacts['registrations'][] = $orphanParent;
cascade_assert(count($repo->findByParent($orphanParent)) === 0, "C8.1 orphaned parent starts with no children");

$report = $cascade->backfillParent($orphanParent);
cascade_assert($report['error'] === null, "C8.2 backfill ran without error");
cascade_assert($report['children_after'] === 2, "C8.3 backfill created 2 children (1 mandatory + 1 elective, got " . $report['children_after'] . ")");

$backfilled = $repo->findByParent($orphanParent);
$backfilledEditions = array_map(fn($c) => (int) $c->edition_id, $backfilled);
cascade_assert(in_array($editionC8, $backfilledEditions, true), "C8.4 mandatory edition present after backfill");
cascade_assert(in_array($electiveC8, $backfilledEditions, true), "C8.5 elective from selections JSON present after backfill");

// =====================================================================
// Teardown
// =====================================================================
shake_section('Teardown');
global $wpdb;
$regsTable = $wpdb->prefix . 'vad_registrations';
foreach ($shake_artifacts['registrations'] as $regId) {
    $wpdb->delete($regsTable, ['id' => $regId]);
}
// Also clean any cascade children we created indirectly (they're not in $shake_artifacts).
foreach ($shake_artifacts['users'] as $userId) {
    $wpdb->delete($regsTable, ['user_id' => $userId]);
}
foreach ($shake_artifacts['quotes'] as $quoteId) {
    wp_delete_post($quoteId, true);
}
foreach ($shake_artifacts['posts'] as $postId) {
    wp_delete_post($postId, true);
}
require_once ABSPATH . 'wp-admin/includes/user.php';
foreach ($shake_artifacts['users'] as $userId) {
    wp_delete_user($userId);
}
echo "  cleaned " . count($shake_artifacts['users']) . " user(s), "
    . count($shake_artifacts['posts']) . " post(s), "
    . count($shake_artifacts['registrations']) . " registration(s), "
    . count($shake_artifacts['quotes']) . " quote(s)\n";

// =====================================================================
// Summary
// =====================================================================
echo "\n*** Summary: {$shake_pass} PASS / {$shake_fail} FAIL ***\n";
if ($shake_fail > 0) {
    exit(1);
}
