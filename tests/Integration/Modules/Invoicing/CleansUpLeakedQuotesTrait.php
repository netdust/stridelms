<?php

declare(strict_types=1);

namespace Stride\Tests\Integration\Modules\Invoicing;

/**
 * Shared tearDown helper for the AutoVoucher integration tests.
 *
 * Both AutoVoucherEditionTest and AutoVoucherTrajectoryTest drive the real
 * quote-creation seam, so each run leaves vad_quote posts keyed on the
 * registration/enrollment ids they created. The vad_registrations table's
 * AUTO_INCREMENT id is REUSED across runs (rows are hard-deleted in tearDown),
 * so a leaked quote whose `registration_id` meta = a low, reused id collides
 * with a LATER run's fresh registration: getQuoteByRegistration() then returns
 * the stale quote, the discount/used_count assertions read the wrong quote, and
 * the money tests flake (cross-run pollution, not a code bug).
 *
 * Deleting the quote posts keyed on our own registration ids — BEFORE the
 * registration rows go away — closes it. Same class of leak fixed for the
 * edition sibling in a1f80561; see gotcha_leaked_quotes_registration_id_reuse
 * and lesson_integration_test_registration_cleanup.
 */
trait CleansUpLeakedQuotesTrait
{
    /**
     * Hard-delete the vad_quote posts this suite's registrations produced.
     *
     * @param array<int> $registrationIds registration/enrollment ids whose
     *        quotes should be purged (the quote stores this in registration_id
     *        meta regardless of edition vs trajectory path).
     */
    protected function deleteQuotesForRegistrations(array $registrationIds): void
    {
        global $wpdb;

        foreach ($registrationIds as $regId) {
            $quotePosts = $wpdb->get_col($wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta}
                 WHERE meta_key = 'registration_id' AND meta_value = %s",
                (string) $regId,
            ));
            foreach ($quotePosts as $quotePostId) {
                wp_delete_post((int) $quotePostId, true);
            }
        }
    }
}
