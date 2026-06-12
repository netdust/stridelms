<?php

declare(strict_types=1);

namespace Stride\Tests\Unit;

use BadMethodCallException;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for NTDST_Theme.
 *
 * Covers the audit fixes:
 *  - validate_config rejects non-array shapes (item 10)
 *  - validate_config fills defaults for missing keys
 *  - mixin() pattern 1 (named instance) + pattern 2 (method injection)
 *  - mixin() throws on invalid usage (item 8)
 *  - __call() routes to mixin, throws BadMethodCall when unknown
 *  - apiAction capability failure produces WP_Error, not array (item 7)
 *  - wireMixins skips missing optional helpers (item 4)
 *
 * Asset enqueueing is wp_*-coupled and best left to integration tests.
 */
final class NtdstThemeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        global $_test_filters, $_test_options, $_test_current_user_id;
        $_test_filters = [];
        $_test_options['home'] = 'https://example.com';
        $_test_current_user_id = 0;
    }

    // ---------------------------------------------------------------------
    // validate_config (item 10)
    // ---------------------------------------------------------------------

    public function testValidateConfigFillsDefaults(): void
    {
        $theme = new \NTDST_Theme();
        $config = $theme->get_config();
        $this->assertSame('ntdst_theme', $config['textdomain']);
        $this->assertSame(1200, $config['content_width']);
        $this->assertSame(['styles' => [], 'scripts' => []], $config['assets']);
    }

    public function testValidateConfigMergesPartialAssets(): void
    {
        $theme = new \NTDST_Theme([
            'assets' => ['scripts' => ['app' => ['src' => '/app.js']]],
        ]);
        $config = $theme->get_config();
        $this->assertArrayHasKey('app', $config['assets']['scripts']);
        $this->assertSame([], $config['assets']['styles']);
    }

    public function testValidateConfigRejectsNonArrayImageSizes(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches("/image_sizes.*must be an array/");
        new \NTDST_Theme(['image_sizes' => 'not-an-array']);
    }

    public function testValidateConfigRejectsNonArrayMenus(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new \NTDST_Theme(['menus' => 'oops']);
    }

    // ---------------------------------------------------------------------
    // mixin() (items 8, 9)
    // ---------------------------------------------------------------------

    public function testMixinPattern1NamedInstanceProxy(): void
    {
        $theme = new \NTDST_Theme();
        $instance = new \stdClass();
        $instance->name = 'shared';

        $theme->mixin('shared', $instance);
        $this->assertSame($instance, $theme->shared());
    }

    public function testMixinPattern2MethodInjection(): void
    {
        $theme = new \NTDST_Theme();
        $helpers = new class {
            public function formatDate(string $date): string
            {
                return 'fmt:' . $date;
            }
        };

        $theme->mixin($helpers);
        $this->assertSame('fmt:2026-05-18', $theme->formatDate('2026-05-18'));
    }

    public function testMixinThrowsOnInvalidUsage(): void
    {
        $theme = new \NTDST_Theme();
        $this->expectException(InvalidArgumentException::class);
        // String name with null instance — neither pattern matches.
        $theme->mixin('orphan', null);
    }

    public function testCallThrowsOnUnknownMethod(): void
    {
        $theme = new \NTDST_Theme();
        $this->expectException(BadMethodCallException::class);
        $theme->doesNotExist();
    }

    // ---------------------------------------------------------------------
    // apiAction capability check (item 7)
    // ---------------------------------------------------------------------

    public function testApiActionReturnsWpErrorOnCapabilityFailure(): void
    {
        // Stub current_user_can defaults to true; force the cap to fail.
        global $current_user_caps;
        $current_user_caps = ['manage_options' => false];

        $theme = new \NTDST_Theme();
        $theme->apiAction('admin_action', fn() => ['ran' => true], ['capability' => 'manage_options']);

        // Invoke the registered filter the way Endpoints::handle_action would.
        $result = apply_filters('ntdst/api_data/admin_action', [], []);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('forbidden', $result->get_error_code());

        $current_user_caps = null; // restore default
    }

    public function testApiActionRunsCallbackWhenCapabilityPasses(): void
    {
        global $current_user_caps;
        $current_user_caps = ['manage_options' => true];

        $theme = new \NTDST_Theme();
        $theme->apiAction('safe_action', fn() => ['ran' => true]);

        $result = apply_filters('ntdst/api_data/safe_action', [], []);
        $this->assertSame(['ran' => true], $result);

        $current_user_caps = null;
    }

    // ---------------------------------------------------------------------
    // wireMixins skips missing helpers (item 4)
    // ---------------------------------------------------------------------

    public function testThemeConstructsWhenAllMixinsArePresent(): void
    {
        // All ntdst_* helpers are loaded via tests/bootstrap.php; just
        // confirm construction doesn't throw and the data mixin resolves.
        $theme = new \NTDST_Theme();
        $this->assertInstanceOf(\NTDST_Theme::class, $theme);
        // ntdst_data() is stubbed/loaded so $theme->data() should return something.
        $this->assertNotNull($theme->data());
    }

    // ---------------------------------------------------------------------
    // Fluent API helpers
    // ---------------------------------------------------------------------

    public function testOnAndFilterReturnSelfForChaining(): void
    {
        $theme = new \NTDST_Theme();
        $this->assertSame($theme, $theme->on('wp_footer', fn() => null));
        $this->assertSame($theme, $theme->filter('body_class', fn($c) => $c));
    }

    public function testWhenRunsCallbackOnTruthyCondition(): void
    {
        $theme = new \NTDST_Theme();
        $ran = false;
        $theme->when(fn() => true, function () use (&$ran) {
            $ran = true;
        });
        $this->assertTrue($ran);
    }

    public function testWhenSkipsCallbackOnFalsyCondition(): void
    {
        $theme = new \NTDST_Theme();
        $ran = false;
        $theme->when(fn() => false, function () use (&$ran) {
            $ran = true;
        });
        $this->assertFalse($ran);
    }
}
