<?php
/**
 * Shared helpers for the registration-lifecycle shake-out scripts.
 * Loaded by scripts in tests/manual/shake-*.php
 */

use Stride\Domain\OfferingStatus;
use Stride\Domain\RegistrationStatus;

function shake_reset_editions(): void
{
    $repo = ntdst_get(\Stride\Modules\Edition\EditionRepository::class);
    $repo->updateStatus(13222, OfferingStatus::Full);
    $repo->updateStatus(13224, OfferingStatus::Announcement);
    $repo->updateStatus(13230, OfferingStatus::Open);
    $repo->updateStatus(13234, OfferingStatus::Open);
    $repo->updateStatus(13265, OfferingStatus::Open);
    $repo->updateStatus(13311, OfferingStatus::Open);

    // Push test editions into the near future so EditionService::isPast() doesn't
    // reject our enrollment-path scenarios. Seed dates are stale relative to
    // wall-clock time; we set them to today + 30/60 days so the suite works
    // regardless of when it's run.
    //
    // Also clear any post-course requirement meta leaked from prior runs.
    // Split into two updateMetaBatch calls: a single batch that mixes string
    // date fields with boolean fields returns false on the whole batch when
    // any single field rejects, leaving the others untouched.
    $model = ntdst_data()->get('vad_edition');
    $future_start = date('Y-m-d', strtotime('+30 days'));
    $future_end   = date('Y-m-d', strtotime('+60 days'));
    foreach ([13222, 13224, 13230, 13234, 13240, 13257, 13265, 13311] as $id) {
        $model->updateMetaBatch($id, [
            'start_date' => $future_start,
            'end_date'   => $future_end,
        ]);
        $model->updateMetaBatch($id, [
            'post_requires_evaluation' => false,
            'post_requires_documents'  => false,
            'post_requires_approval'   => false,
        ]);
    }
}

function shake_clean_test_users(): void
{
    global $wpdb;
    $wpdb->query("DELETE FROM stride_vad_registrations WHERE user_id BETWEEN 7781 AND 7790");
    $wpdb->query("DELETE FROM stride_vad_registrations WHERE enrollment_data LIKE '%smoke.test%' OR enrollment_data LIKE '%shake.test%'");
}

function shake_dump_reg(int $regId): string
{
    $repo = ntdst_get(\Stride\Modules\Enrollment\RegistrationRepository::class);
    $r = $repo->find($regId);
    if (!$r) return "(reg $regId not found)";
    return sprintf(
        "id=%d user=%s status=%s path=%s quote=%s comp_at=%s cancel_at=%s",
        $r->id,
        $r->user_id ?? "NULL",
        $r->status,
        $r->enrollment_path ?? "-",
        $r->quote_id ?? "-",
        $r->completed_at ?? "-",
        $r->cancelled_at ?? "-",
    );
}

function shake_section(string $title): void
{
    echo "\n=== $title ===\n";
}

function shake_assert_error($result, string $expected, string $label): void
{
    if (is_wp_error($result)) {
        $code = $result->get_error_code();
        $msg = $result->get_error_message();
        if ($expected === '*' || $code === $expected) {
            echo "  PASS: [$label] error=$code msg=$msg\n";
        } else {
            echo "  FAIL: [$label] expected $expected got $code msg=$msg\n";
        }
    } else {
        echo "  FAIL: [$label] expected error $expected, got success: " . (is_int($result) ? "reg=$result" : json_encode($result)) . "\n";
    }
}

function shake_assert_success($result, string $label): mixed
{
    if (is_wp_error($result)) {
        echo "  FAIL: [$label] got error: " . $result->get_error_message() . "\n";
        return null;
    }
    echo "  OK: [$label] " . (is_int($result) ? "reg=$result" : (is_array($result) ? json_encode($result) : "yes")) . "\n";
    return $result;
}
