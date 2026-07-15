<?php

declare(strict_types=1);

namespace Stride\Tests\Unit\Admin;

use Stride\Domain\AttendanceStatus;
use Stride\Domain\RegistrationStatus;
use Stride\Tests\TestCase;

/**
 * Cross-language vocabulary contracts for the Cohort lens.
 *
 * The lens owns TWO client-side label tables that must mirror PHP enums:
 *
 *  - STATUS_LABEL (registration statuses): must carry every RegistrationStatus
 *    value with the enum's exact Dutch label. Drift is worse than a raw value
 *    here — statusBadgeClass() maps any key MISSING from the table to the
 *    'cancelled' hue, so a new legitimate status would render as cancelled.
 *
 *  - MARK_LABEL (attendance statuses): must carry every AttendanceStatus value
 *    with the enum's exact label. A new attendance case without the client
 *    half renders '—' for marked rows AND silently drops out of both
 *    aggregate computations (attendanceMaps server-side, cohortApplyMark
 *    client-side hardcode the present/absent/excused keys).
 *
 * Same pattern as Edities/Trajecten/OffertesStatusVocabularyTest; the shared
 * extraction + no-fictional-keys helpers live in TestCase.
 */
final class CohortStatusVocabularyTest extends TestCase
{
    public function test_every_registration_status_has_the_enum_label(): void
    {
        $table = $this->extractJsBlock('cohort.js', 'STATUS_LABEL');

        foreach (RegistrationStatus::cases() as $status) {
            $matched = preg_match(
                '/\b' . preg_quote($status->value, '/') . "\s*:\s*'((?:[^'\\\\]|\\\\.)*)'/",
                $table,
                $m,
            );
            $this->assertSame(1, $matched, "cohort.js STATUS_LABEL is missing status '{$status->value}' — it would render with the cancelled hue");
            $this->assertSame(
                $status->label(),
                stripslashes($m[1]),
                "cohort.js STATUS_LABEL for '{$status->value}' differs from RegistrationStatus::label()",
            );
        }

        $this->assertJsMapKeysWithinEnum('cohort.js', 'STATUS_LABEL', array_map(
            static fn(RegistrationStatus $s) => $s->value,
            RegistrationStatus::cases(),
        ));
    }

    public function test_every_attendance_status_has_the_enum_mark_label(): void
    {
        $table = $this->extractJsBlock('cohort.js', 'MARK_LABEL');

        foreach (AttendanceStatus::cases() as $status) {
            $matched = preg_match(
                '/\b' . preg_quote($status->value, '/') . "\s*:\s*'((?:[^'\\\\]|\\\\.)*)'/",
                $table,
                $m,
            );
            $this->assertSame(1, $matched, "cohort.js MARK_LABEL is missing attendance status '{$status->value}' — marked rows would render '—' and drop from the aggregates");
            $this->assertSame(
                $status->label(),
                stripslashes($m[1]),
                "cohort.js MARK_LABEL for '{$status->value}' differs from AttendanceStatus::label()",
            );
        }

        $this->assertJsMapKeysWithinEnum('cohort.js', 'MARK_LABEL', array_map(
            static fn(AttendanceStatus $s) => $s->value,
            AttendanceStatus::cases(),
        ));
    }
}
