<?php

declare(strict_types=1);

namespace Stride\Support;

/**
 * The ONE definition of "what is a non-PII logistics extra in enrollment_data".
 *
 * enrollment_data is stage-keyed, each stage an envelope of the shape
 * { submitted_at, submitted_by, data }. A registrant's free-form logistics
 * answers (dieet, lunch, boek, ...) live in those stages' `data` maps,
 * intermixed with PII / already-columned fields (name, billing_*, organisation).
 *
 * Two surfaces need to agree on which keys are extras vs PII:
 *   - {@see \Stride\Admin\AdminEditionRosterService::getRosterForEdition()} —
 *     surfaces extras as roster columns + filter chips.
 *   - {@see \Stride\Modules\Edition\Admin\EditionRegistrationExporter} —
 *     renders extras inline on the participant export sheet.
 *
 * They used to carry BYTE-FOR-BYTE COPIES of the stage list + skip list and the
 * envelope walk, which silently drift: add a PII key to one skip list and forget
 * the other, and that surface leaks a key the other suppresses (a GDPR-relevant
 * bug — CR-3/CR-5). This class is the single source of truth: one stage list,
 * one skip list, one walk. Each consumer keeps only its own VALUE shaping
 * (the roster filters to scalars; the exporter json_encodes non-scalars), never
 * its own copy of WHICH keys count.
 *
 * The skip list is a DENYLIST of suppressed keys (CR-3 deferral): a brand-new,
 * unlisted PII key submitted under a custom field would still surface as an
 * extra. Flipping to an allowlist of SHOWN keys is a larger product change,
 * deliberately out of scope here — the scope of CR-3 is "one list both surfaces
 * share so they cannot drift", not "change the allow/deny model".
 *
 * Pure function over a PARSED array — no DB, no WordPress.
 *
 * @package stride-core
 */
final class EnrollmentDataExtras
{
    /**
     * enrollment_data stages walked for logistics extras.
     *
     * Pre-account stages (interest/waitlist) and the append-only
     * initial_selection log are intentionally excluded.
     */
    public const STAGES = ['enrollment_personal', 'enrollment_billing', 'intake', 'evaluation'];

    /**
     * Keys never surfaced as a logistics extra: known PII or fields that already
     * have their own column elsewhere.
     */
    public const SKIP_KEYS = [
        'name', 'email', 'phone', 'first_name', 'last_name',
        'company', 'billing_company', 'billing_vat', 'billing_address_1',
        'billing_postcode', 'billing_city', 'invoice_email', 'gln_number',
        'organisation', 'department',
    ];

    /**
     * Walk the configured stages of a PARSED enrollment_data array and return the
     * discovered non-PII extras as a {key: value} map.
     *
     * Keys are DISCOVERED from the data (not a fixed allowlist of shown keys) and
     * filtered by {@see self::SKIP_KEYS}. Values are returned AS-IS (scalar or
     * structured) — the caller owns rendering/filtering: the roster keeps scalars
     * only, the exporter json_encodes non-scalars.
     *
     * @param  array<string,mixed> $parsedEnrollmentData  Already-decoded enrollment_data.
     * @return array<string,mixed>  Discovered extras, last-write-wins across stages.
     */
    public static function extract(array $parsedEnrollmentData): array
    {
        $extras = [];
        foreach (self::STAGES as $stage) {
            $envelope = $parsedEnrollmentData[$stage] ?? null;
            if (!is_array($envelope)) {
                continue;
            }
            $stageData = is_array($envelope['data'] ?? null) ? $envelope['data'] : [];
            foreach ($stageData as $key => $value) {
                if (in_array($key, self::SKIP_KEYS, true)) {
                    continue;
                }
                $extras[(string) $key] = $value;
            }
        }

        return $extras;
    }
}
