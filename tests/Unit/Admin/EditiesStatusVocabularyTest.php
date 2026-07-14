<?php

declare(strict_types=1);

namespace Stride\Tests\Unit\Admin;

use Stride\Domain\OfferingStatus;
use Stride\Tests\TestCase;

/**
 * Cross-language vocabulary contract for the Edities surface (F-E2).
 *
 * GET /admin/editions sends only the effective-status VALUE; edities.js
 * STATUS_META reconstructs the Dutch label client-side. That label table
 * must mirror OfferingStatus::label() EXACTLY — a wording tweak landing on
 * one side only makes the Edities badge disagree with every server-labelled
 * surface (Trajecten, the CPT admin columns, the status filter dropdown,
 * which enumerates the enum's labels server-side on this very surface).
 *
 * Hue parity for the same table is asserted in TrajectenStatusVocabularyTest
 * (trajecten's STATUS_BADGE must match edities' cls per status); this test
 * owns the LABEL half and the no-fictional-keys guarantee for edities.js.
 */
final class EditiesStatusVocabularyTest extends TestCase
{
    public function test_every_offering_status_label_matches_the_enum(): void
    {
        $meta = $this->extractJsBlock('edities.js', 'STATUS_META');

        foreach (OfferingStatus::cases() as $status) {
            $matched = preg_match(
                '/\b' . preg_quote($status->value, '/') . "\s*:\s*\{\s*label:\s*'((?:[^'\\\\]|\\\\.)*)'/",
                $meta,
                $m,
            );
            $this->assertSame(1, $matched, "edities.js STATUS_META is missing status key '{$status->value}'");
            $this->assertSame(
                $status->label(),
                stripslashes($m[1]),
                "edities.js STATUS_META label for '{$status->value}' differs from OfferingStatus::label()",
            );
        }
    }

    public function test_status_meta_holds_no_fictional_statuses(): void
    {
        $meta = $this->extractJsBlock('edities.js', 'STATUS_META');
        preg_match_all('/^\s*([a-z_]+)\s*:\s*\{/m', $meta, $m);
        $this->assertNotEmpty($m[1], 'STATUS_META parsed to zero keys — extraction regex broke');

        $known = array_map(static fn(OfferingStatus $s) => $s->value, OfferingStatus::cases());
        foreach ($m[1] as $key) {
            $this->assertContains(
                $key,
                $known,
                "edities.js STATUS_META key '{$key}' is not an OfferingStatus value — fictional vocabulary",
            );
        }
    }

    private function extractJsBlock(string $file, string $constName): string
    {
        $jsDir = dirname(__DIR__, 3) . '/web/app/mu-plugins/stride-core/assets/js/admin/';
        $js = (string) file_get_contents($jsDir . $file);

        $matched = preg_match(
            '/const ' . preg_quote($constName, '/') . '\s*=\s*\{(.*?)\};/s',
            $js,
            $m,
        );
        $this->assertSame(1, $matched, "{$file} no longer defines const {$constName}");

        return $m[1];
    }
}
