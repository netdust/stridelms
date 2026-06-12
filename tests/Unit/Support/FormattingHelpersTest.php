<?php

declare(strict_types=1);

namespace Stride\Tests\Unit\Support;

use ReflectionFunction;
use Stride\Tests\TestCase;

/**
 * Contract: stride_format_date is OWNED BY stride-core (audit H-6 / Task C2).
 *
 * stride-core (mu-plugin) renders mail + notifications with this formatter.
 * If the definition lives in the theme, activating any non-stridence theme
 * fatals mail/notification rendering. The unit suite loads NO theme, so
 * these assertions prove core-independence — and pin the Dutch output so
 * the format cannot silently fork (the old test stub returned English).
 */
class FormattingHelpersTest extends TestCase
{
    public function testDefinitionIsOwnedByStrideCoreNotThemeOrStub(): void
    {
        $this->assertTrue(
            function_exists('stride_format_date'),
            'stride_format_date must be defined from mu-plugin context alone (no theme loaded in unit suite)'
        );

        $source = (new ReflectionFunction('stride_format_date'))->getFileName();

        $this->assertStringContainsString(
            'mu-plugins/stride-core/Support/formatting.php',
            (string) $source,
            'stride_format_date must be defined by stride-core, not by the theme or a test stub'
        );
    }

    public function testFormatsDateWithDutchMonthName(): void
    {
        $this->assertSame('10 juni 2026', stride_format_date('2026-06-10'));
        $this->assertSame('1 maart 2025', stride_format_date('2025-03-01'));
    }

    public function testFormatsDateWithDutchDayName(): void
    {
        // 2026-06-10 is a Wednesday.
        $this->assertSame('woensdag 10 juni 2026', stride_format_date('2026-06-10', 'l j F Y'));
    }

    public function testRespectsCustomFormat(): void
    {
        $this->assertSame('10/06/2026', stride_format_date('2026-06-10', 'd/m/Y'));
    }

    public function testReturnsEmptyStringForUnparseableDate(): void
    {
        $this->assertSame('', stride_format_date('not-a-date'));
        $this->assertSame('', stride_format_date(''));
    }
}
