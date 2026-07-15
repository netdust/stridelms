<?php

declare(strict_types=1);

namespace Stride\Tests\Unit\Admin;

use Stride\Domain\QuoteStatus;
use Stride\Tests\TestCase;

/**
 * Cross-language vocabulary contract for the Offertes surface.
 *
 * The quote workflow LABEL is server-owned (statusLabel, INV-7 — rendered as
 * received), so unlike edities there is no JS label table to pin. What the
 * client DOES own is the status VALUE → badge-hue table (QUOTE_BADGE): every
 * QuoteStatus value must have a hue, and the table may contain no key outside
 * the enum — the fictional-vocabulary class the Trajecten slice found
 * (F-T2/F-T3) fails at commit time here too.
 */
final class OffertesStatusVocabularyTest extends TestCase
{
    public function test_every_quote_status_has_a_badge_hue(): void
    {
        $badge = $this->extractJsBlock('offertes.js', 'QUOTE_BADGE');

        foreach (QuoteStatus::cases() as $status) {
            $this->assertMatchesRegularExpression(
                '/\b' . preg_quote($status->value, '/') . "\s*:\s*'[a-z]+'/",
                $badge,
                "offertes.js QUOTE_BADGE is missing quote status '{$status->value}'",
            );
        }
    }

    public function test_quote_badge_holds_no_fictional_statuses(): void
    {
        $badge = $this->extractJsBlock('offertes.js', 'QUOTE_BADGE');
        preg_match_all("/^\s*([a-z_]+)\s*:\s*'/m", $badge, $m);
        $this->assertNotEmpty($m[1], 'QUOTE_BADGE parsed to zero keys — extraction regex broke');

        $known = array_map(static fn(QuoteStatus $s) => $s->value, QuoteStatus::cases());
        foreach ($m[1] as $key) {
            $this->assertContains(
                $key,
                $known,
                "offertes.js QUOTE_BADGE key '{$key}' is not a QuoteStatus value — fictional vocabulary",
            );
        }
    }
}
