<?php

declare(strict_types=1);

namespace Stride\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for NTDST_SectorRegistry.
 *
 * SectorRegistry is a singleton — its constructor runs registerDefaultSectors()
 * once. We reset its enable/tier caches between tests so option changes flow
 * through. Stride doesn't use sectors today, but this verifies the behavior
 * future projects rely on.
 */
final class NtdstSectorRegistryTest extends TestCase
{
    private \NTDST_SectorRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        global $_test_options;
        $_test_options = [];

        $this->registry = \NTDST_SectorRegistry::instance();
        $this->registry->clearCache();
    }

    // ---------------------------------------------------------------------
    // checkRequirements — null/all/array semantics
    // ---------------------------------------------------------------------

    public function testCheckRequirementsNullAlwaysLoads(): void
    {
        $this->assertTrue($this->registry->checkRequirements(null));
    }

    public function testCheckRequirementsAllAlwaysLoads(): void
    {
        $this->assertTrue($this->registry->checkRequirements('all'));
        $this->assertTrue($this->registry->checkRequirements('core'));
    }

    public function testCheckRequirementsEmptyArrayAlwaysLoads(): void
    {
        $this->assertTrue($this->registry->checkRequirements([]));
    }

    public function testCheckRequirementsRejectsWhenNoSectorEnabled(): void
    {
        global $_test_options;
        // Make sure none of the default sectors are enabled
        $_test_options['ntdst_enable_gallery'] = '0';
        $this->registry->clearCache();

        $this->assertFalse($this->registry->checkRequirements(['gallery' => 'essential']));
    }

    public function testCheckRequirementsAcceptsWhenSectorEnabledAtRequiredTier(): void
    {
        global $_test_options;
        $_test_options['ntdst_enable_gallery'] = '1';
        $_test_options['ntdst_gallery_tier'] = 'professional';
        $this->registry->clearCache();

        $this->assertTrue($this->registry->checkRequirements(['gallery' => 'essential']));
        $this->assertTrue($this->registry->checkRequirements(['gallery' => 'professional']));
        $this->assertFalse($this->registry->checkRequirements(['gallery' => 'premium']));
    }

    // ---------------------------------------------------------------------
    // meetsTier asymmetry (item S6)
    // ---------------------------------------------------------------------

    public function testMeetsTierTreatsUnknownCurrentAsLowest(): void
    {
        global $_test_options;
        $_test_options['ntdst_enable_gallery'] = '1';
        $_test_options['ntdst_gallery_tier'] = 'unknown_garbage_tier';
        $this->registry->clearCache();

        // Unknown current tier degrades to essential — essential requirement met
        $this->assertTrue($this->registry->meetsTier('gallery', 'essential'));
        // ... but won't satisfy higher requirements
        $this->assertFalse($this->registry->meetsTier('gallery', 'professional'));
    }

    public function testMeetsTierRejectsUnknownRequiredTier(): void
    {
        global $_test_options;
        $_test_options['ntdst_enable_gallery'] = '1';
        $_test_options['ntdst_gallery_tier'] = 'premium';
        $this->registry->clearCache();

        // Even with the highest current tier, unknown required tier fails safe.
        $this->assertFalse($this->registry->meetsTier('gallery', 'platinum_does_not_exist'));
    }

    public function testMeetsTierReturnsTrueForNoTierSector(): void
    {
        global $_test_options;
        $_test_options['ntdst_enable_print_shop'] = '1';
        $this->registry->clearCache();

        // printshop has tier_option=null — meetsTier always true once enabled
        $this->assertTrue($this->registry->meetsTier('printshop', 'professional'));
    }

    // ---------------------------------------------------------------------
    // has() / get() / register()
    // ---------------------------------------------------------------------

    public function testHasReturnsTrueForDefaultSector(): void
    {
        $this->assertTrue($this->registry->has('gallery'));
    }

    public function testHasReturnsFalseForUnknownSector(): void
    {
        $this->assertFalse($this->registry->has('nonsense'));
    }

    public function testRegisterAddsCustomSector(): void
    {
        $this->registry->register('custom', ['label' => 'Custom Platform']);
        $this->assertTrue($this->registry->has('custom'));
        $this->assertSame('Custom Platform', $this->registry->get('custom')['label']);
    }
}
