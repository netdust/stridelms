<?php

declare(strict_types=1);

namespace Stride\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Unit tests for NTDST_Endpoints.
 *
 * Covers the audit fixes:
 *  - verifyOrigin() rejects example.com.evil.com (item 4)
 *  - getClientIp() respects trusted-proxy filter
 *  - hasCookieAuth() detects WP login cookies
 *  - checkRateLimit() supports per-action filters + per-user keying (items 1, 13)
 *  - Back-compat: old `Endpoints` class alias resolves to NTDST_Endpoints (item 15)
 *
 * Behavior tied to register_rest_route / WP_REST_Request is covered by the
 * integration suite; these tests focus on isolable pure-PHP logic.
 */
final class NtdstEndpointsTest extends TestCase
{
    private \NTDST_Endpoints $endpoints;

    protected function setUp(): void
    {
        parent::setUp();
        $this->endpoints = new \NTDST_Endpoints();

        // Reset cross-test globals
        $_SERVER['HTTP_ORIGIN'] = '';
        $_SERVER['HTTP_REFERER'] = '';
        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '';
        $_COOKIE = [];

        global $_test_filters, $_test_options, $_test_transients, $_test_current_user_id;
        $_test_filters = [];
        $_test_transients = [];
        $_test_options['home'] = 'https://example.com';
        $_test_options['siteurl'] = 'https://example.com';
        // Default to anonymous for rate-limit tests to use the IP bucket
        // unless a specific test sets $_test_current_user_id explicitly.
        $_test_current_user_id = 0;
    }

    // ---------------------------------------------------------------------
    // verifyOrigin — referer prefix bypass fix (item 4)
    // ---------------------------------------------------------------------

    public function testVerifyOriginAcceptsMatchingOrigin(): void
    {
        $_SERVER['HTTP_ORIGIN'] = 'https://example.com';
        $this->assertTrue($this->callPrivate('verifyOrigin'));
    }

    public function testVerifyOriginRejectsForeignOrigin(): void
    {
        $_SERVER['HTTP_ORIGIN'] = 'https://evil.com';
        $this->assertFalse($this->callPrivate('verifyOrigin'));
    }

    public function testVerifyOriginAcceptsMatchingReferer(): void
    {
        $_SERVER['HTTP_REFERER'] = 'https://example.com/path/page';
        $this->assertTrue($this->callPrivate('verifyOrigin'));
    }

    public function testVerifyOriginRejectsRefererWithExtraTld(): void
    {
        // Before the fix: home_url() returned 'https://example.com' and
        // str_starts_with('https://example.com.evil.com/x', 'https://example.com') was true.
        $_SERVER['HTTP_REFERER'] = 'https://example.com.evil.com/page';
        $this->assertFalse(
            $this->callPrivate('verifyOrigin'),
            'attacker-controlled subdomain must NOT pass referer check'
        );
    }

    public function testVerifyOriginAllowsCustomAllowlistOrigin(): void
    {
        $_SERVER['HTTP_ORIGIN'] = 'https://trusted-partner.com';

        add_filter('ntdst/api/allowed_origins', fn() => ['https://trusted-partner.com']);

        $this->assertTrue($this->callPrivate('verifyOrigin'));
    }

    public function testVerifyOriginAllowsMissingHeadersWithoutCookieAuth(): void
    {
        // No origin, no referer, no auth cookie → request is allowed (e.g. server-to-server)
        $this->assertTrue($this->callPrivate('verifyOrigin'));
    }

    public function testVerifyOriginRejectsMissingHeadersWhenCookieAuthPresent(): void
    {
        $_COOKIE['wordpress_logged_in_abc'] = '...';
        $this->assertFalse(
            $this->callPrivate('verifyOrigin'),
            'authenticated browser request must include Origin/Referer'
        );
    }

    // ---------------------------------------------------------------------
    // hasCookieAuth
    // ---------------------------------------------------------------------

    public function testHasCookieAuthFalseWithNoCookies(): void
    {
        $this->assertFalse($this->callPrivate('hasCookieAuth'));
    }

    public function testHasCookieAuthFalseWithUnrelatedCookies(): void
    {
        $_COOKIE['session'] = 'xyz';
        $_COOKIE['wp_settings'] = '1';
        $this->assertFalse($this->callPrivate('hasCookieAuth'));
    }

    public function testHasCookieAuthTrueWithWordpressLoggedInCookie(): void
    {
        $_COOKIE['wordpress_logged_in_5d41402a'] = 'secret';
        $this->assertTrue($this->callPrivate('hasCookieAuth'));
    }

    // ---------------------------------------------------------------------
    // getClientIp — trusted-proxy logic
    // ---------------------------------------------------------------------

    public function testGetClientIpIgnoresXForwardedForByDefault(): void
    {
        // REMOTE_ADDR is NOT in the default trusted proxy list (127.0.0.1, ::1)
        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4';

        $this->assertSame('203.0.113.7', $this->callPrivate('getClientIp'));
    }

    public function testGetClientIpHonorsXForwardedForFromTrustedProxy(): void
    {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4, 5.6.7.8';

        $this->assertSame('1.2.3.4', $this->callPrivate('getClientIp'));
    }

    public function testGetClientIpRejectsMalformedForwardedHeader(): void
    {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = 'not-an-ip';

        $this->assertSame('127.0.0.1', $this->callPrivate('getClientIp'));
    }

    // ---------------------------------------------------------------------
    // checkRateLimit — per-action filter + per-user keying (items 1, 13)
    // ---------------------------------------------------------------------

    public function testRateLimitAllowsRequestsUnderTheCap(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->assertTrue($this->callPrivate('checkRateLimit', ['my_action']));
        }
    }

    public function testRateLimitBlocksOnceCapHit(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->callPrivate('checkRateLimit', ['my_action']);
        }
        $this->assertFalse($this->callPrivate('checkRateLimit', ['my_action']));
    }

    public function testRateLimitIsPerAction(): void
    {
        // Exhaust action_a; action_b must still be allowed.
        for ($i = 0; $i < 30; $i++) {
            $this->callPrivate('checkRateLimit', ['action_a']);
        }
        $this->assertFalse($this->callPrivate('checkRateLimit', ['action_a']));
        $this->assertTrue($this->callPrivate('checkRateLimit', ['action_b']));
    }

    public function testRateLimitFilterCanTightenForSensitiveAction(): void
    {
        // Tighten send_magic_link to 2/window.
        add_filter('ntdst/api/rate_limit/send_magic_link', fn() => 2);

        $this->assertTrue($this->callPrivate('checkRateLimit', ['send_magic_link']));
        $this->assertTrue($this->callPrivate('checkRateLimit', ['send_magic_link']));
        $this->assertFalse($this->callPrivate('checkRateLimit', ['send_magic_link']));
    }

    public function testRateLimitFilterCanDisableLimit(): void
    {
        add_filter('ntdst/api/rate_limit/free_action', fn() => 0);

        for ($i = 0; $i < 100; $i++) {
            $this->assertTrue($this->callPrivate('checkRateLimit', ['free_action']));
        }
    }

    public function testRateLimitKeysSeparateUsers(): void
    {
        global $_test_users, $_test_current_user_id;

        // First user exhausts their bucket
        $_test_current_user_id = 1;
        for ($i = 0; $i < 30; $i++) {
            $this->callPrivate('checkRateLimit', ['my_action']);
        }
        $this->assertFalse($this->callPrivate('checkRateLimit', ['my_action']));

        // Second user should still be allowed
        $_test_current_user_id = 2;
        $this->assertTrue($this->callPrivate('checkRateLimit', ['my_action']));
    }

    // ---------------------------------------------------------------------
    // Back-compat class alias (item 15)
    // ---------------------------------------------------------------------

    public function testOldEndpointsClassAliasResolves(): void
    {
        $this->assertTrue(class_exists('Endpoints'));
        $this->assertTrue(is_a($this->endpoints, 'Endpoints'));
    }

    // ---------------------------------------------------------------------
    // helpers
    // ---------------------------------------------------------------------

    private function callPrivate(string $method, array $args = [])
    {
        $ref = new ReflectionMethod($this->endpoints, $method);
        $ref->setAccessible(true);
        return $ref->invokeArgs($this->endpoints, $args);
    }
}
