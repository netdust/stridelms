<?php

declare(strict_types=1);

namespace Stride\Tests\Unit\Admin;

use Stride\Domain\OfferingStatus;
use Stride\Domain\RegistrationStatus;
use Stride\Tests\TestCase;

/**
 * Cross-language vocabulary contract for the Trajecten surface (F-T2/F-T3).
 *
 * trajecten.js STATUS_BADGE is ONE flat status→hue table covering TWO PHP
 * enums: the trajectory badge binds OfferingStatus values, the roster badge
 * binds RegistrationStatus values. The original table was written against
 * fictional vocabularies ('closed', 'active') and silently slate-greyed the
 * most common REAL statuses — a class of drift no runtime error surfaces.
 *
 * This test pins the contract server-side:
 *  - every OfferingStatus value has a hue in trajecten.js STATUS_BADGE, and
 *    that hue EQUALS edities.js STATUS_META's cls for the same value (same
 *    status, same hue across admin surfaces);
 *  - every RegistrationStatus value has a hue, matching grid.js STATUS_META;
 *  - a value shared by both enums (completed, cancelled) must carry ONE hue,
 *    or the flat map is ambiguous.
 *
 * An enum case added/renamed in PHP without the client half fails HERE — not
 * weeks later as a neutral badge nobody reports. Same pattern as the queue
 * contract in WorklistQueueResolverTest.
 */
final class TrajectenStatusVocabularyTest extends TestCase
{
    public function test_every_offering_status_has_a_hue_matching_edities(): void
    {
        $badge = $this->extractJsBlock('trajecten.js', 'STATUS_BADGE');
        $edities = $this->extractJsBlock('edities.js', 'STATUS_META');

        foreach (OfferingStatus::cases() as $status) {
            $hue = $this->flatHue($badge, $status->value, 'trajecten.js STATUS_BADGE');
            $editiesHue = $this->clsHue($edities, $status->value, 'edities.js STATUS_META');

            $this->assertSame(
                $editiesHue,
                $hue,
                "trajectory status '{$status->value}' renders hue '{$hue}' in trajecten.js but '{$editiesHue}' in edities.js — same status, same hue across surfaces",
            );
        }
    }

    public function test_every_registration_status_has_a_hue_matching_grid(): void
    {
        $badge = $this->extractJsBlock('trajecten.js', 'STATUS_BADGE');
        $grid = $this->extractJsBlock('grid.js', 'STATUS_META');

        foreach (RegistrationStatus::cases() as $status) {
            $hue = $this->flatHue($badge, $status->value, 'trajecten.js STATUS_BADGE');
            $gridHue = $this->clsHue($grid, $status->value, 'grid.js STATUS_META');

            $this->assertSame(
                $gridHue,
                $hue,
                "registration status '{$status->value}' renders hue '{$hue}' in trajecten.js but '{$gridHue}' in grid.js — same status, same hue across surfaces",
            );
        }
    }

    public function test_the_flat_map_holds_no_fictional_statuses(): void
    {
        // Every key in STATUS_BADGE must belong to one of the two enums — a
        // leftover fictional key ('closed', 'active') means someone edited the
        // table against an imagined vocabulary again.
        $badge = $this->extractJsBlock('trajecten.js', 'STATUS_BADGE');
        preg_match_all("/^\s*([a-z_]+)\s*:\s*'/m", $badge, $m);
        $this->assertNotEmpty($m[1], 'STATUS_BADGE parsed to zero keys — extraction regex broke');

        $known = array_merge(
            array_map(static fn(OfferingStatus $s) => $s->value, OfferingStatus::cases()),
            array_map(static fn(RegistrationStatus $s) => $s->value, RegistrationStatus::cases()),
        );

        foreach ($m[1] as $key) {
            $this->assertContains(
                $key,
                $known,
                "trajecten.js STATUS_BADGE key '{$key}' exists in neither OfferingStatus nor RegistrationStatus — fictional vocabulary",
            );
        }
    }

    /** Read a `key: 'hue',` entry from the flat STATUS_BADGE literal. */
    private function flatHue(string $block, string $key, string $context): string
    {
        $matched = preg_match(
            '/\b' . preg_quote($key, '/') . "\s*:\s*'([a-z]+)'/",
            $block,
            $m,
        );
        $this->assertSame(1, $matched, "{$context} is missing status key '{$key}'");

        return $m[1];
    }

    /** Read a `key: { …, cls: 'hue' }` entry from a STATUS_META literal. */
    private function clsHue(string $block, string $key, string $context): string
    {
        $matched = preg_match(
            '/\b' . preg_quote($key, '/') . "\s*:\s*\{[^}]*cls:\s*'([a-z]+)'/",
            $block,
            $m,
        );
        $this->assertSame(1, $matched, "{$context} is missing status key '{$key}'");

        return $m[1];
    }

    public function test_admin_closed_mirror_matches_the_enum(): void
    {
        // Same contract as the edities half (EditiesStatusVocabularyTest):
        // the scope auto-widen must speak OfferingStatus::adminClosedValues().
        $this->assertSame(
            OfferingStatus::adminClosedValues(),
            $this->extractJsStringArray('trajecten.js', 'ADMIN_CLOSED'),
            'trajecten.js ADMIN_CLOSED must mirror OfferingStatus::adminClosedValues() exactly (same values, same order)',
        );
    }
}
