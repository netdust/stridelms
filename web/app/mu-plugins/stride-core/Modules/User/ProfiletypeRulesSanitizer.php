<?php

declare(strict_types=1);

namespace Stride\Modules\User;

/**
 * Sanitizes + validates the per-profile-type enrollment rules map posted from the
 * Edition / Trajectory admin metabox (M5, plan §4/§5). The single place the rule
 * shape { "<slug>": { block: bool, minimal: bool, voucher: string|null } } is
 * enforced, so both admin controllers converge on one implementation instead of
 * duplicating the allowlist-drop + cast logic.
 *
 * Contract (from the threat model M5):
 *  - Any slug NOT in ProfileTypeService::getTypes() is DROPPED (allowlist), and
 *    reported via getDroppedSlugs() so the caller can surface an admin notice.
 *  - block / minimal cast to bool.
 *  - voucher is sanitize_text_field'd; an empty result becomes null.
 *  - A malformed (non-array) rule value for a known slug is skipped, never fatals.
 *
 * The caller is responsible for the nonce + capability guards (this runs INSIDE
 * that protection) and for persisting the returned map through the repository.
 */
final class ProfiletypeRulesSanitizer
{
    public function __construct(
        private readonly ProfileTypeService $profileTypes,
    ) {}

    /** @var array<int, string> slugs dropped as not-in-allowlist during the last sanitize() */
    private array $droppedSlugs = [];

    /**
     * @param mixed $raw the raw $_POST['ntdst_fields']['profiletype_rules'] value
     *                    (a map of slug => rule-row, or a JSON string to decode)
     * @return array<string, array{block: bool, minimal: bool, voucher: string|null}>
     */
    public function sanitize(mixed $raw): array
    {
        $this->droppedSlugs = [];

        // Accept either an already-decoded map (the metabox posts a nested array)
        // or a JSON string, without trusting either blindly.
        if (is_string($raw)) {
            $decoded = json_decode(wp_unslash($raw), true);
            $raw = is_array($decoded) ? $decoded : [];
        }

        if (!is_array($raw)) {
            return [];
        }

        $allowed = $this->allowedSlugs();
        $clean = [];

        foreach ($raw as $slug => $row) {
            $slug = sanitize_key((string) $slug);

            if (!in_array($slug, $allowed, true)) {
                // Unknown slug: DROP it and record for the admin notice. Guard the
                // empty-string slug (from a non-string key) out of the notice.
                if ($slug !== '') {
                    $this->droppedSlugs[] = $slug;
                }
                continue;
            }

            // A malformed (non-array) rule value must not fatal — skip it.
            if (!is_array($row)) {
                continue;
            }

            // Callers pass the raw slashed $_POST['ntdst_fields'] array. wp_unslash
            // BEFORE sanitizing (matching the JSON-string branch above) so a
            // voucher code containing an escaped char (apostrophe/backslash) is not
            // stored with a stray backslash and then fails to resolve at enroll.
            // Guard the non-string case: (string) on an ARRAY emits an "Array to
            // string conversion" warning and stores literal "Array" — a non-string
            // voucher is simply dropped to empty.
            $rawVoucher = $row['voucher'] ?? '';
            $voucher = is_string($rawVoucher)
                ? sanitize_text_field(wp_unslash($rawVoucher))
                : '';

            $clean[$slug] = [
                'block'   => (bool) ($row['block'] ?? false),
                'minimal' => (bool) ($row['minimal'] ?? false),
                'voucher' => $voucher !== '' ? $voucher : null,
            ];
        }

        return $clean;
    }

    /** @return array<int, string> unique slugs dropped in the last sanitize() call */
    public function getDroppedSlugs(): array
    {
        return array_values(array_unique($this->droppedSlugs));
    }

    /** @return array<int, string> the known profile-type slugs (allowlist source) */
    private function allowedSlugs(): array
    {
        return array_values(array_filter(array_map(
            static fn($type): string => is_array($type) ? (string) ($type['slug'] ?? '') : '',
            $this->profileTypes->getTypes(),
        )));
    }
}
