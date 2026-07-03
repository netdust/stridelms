<?php

declare(strict_types=1);

namespace Stride\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for NTDST_Cors_Policy — the CORS decision surface.
 *
 * Threat-model attack 8 / mitigation 8: exact string identity via
 * in_array($origin, $list, true) against full scheme://host[:port]
 * strings; the literal 'null' and '' are rejected BEFORE any list/callable
 * consult; '*' throws at construction.
 *
 * Denial-path-heavy by design — the denial paths ARE the deliverable.
 */
final class NtdstCorsPolicyTest extends TestCase
{
    public function testExactOriginMatches(): void
    {
        $p = new \NTDST_Cors_Policy(['origins' => ['https://app.example.com']]);
        self::assertTrue($p->allowsOrigin('https://app.example.com', new \WP_REST_Request()));
    }

    public function testSubdomainAndPrefixVariantsAreDenied(): void
    {
        $p = new \NTDST_Cors_Policy(['origins' => ['https://app.example.com']]);
        foreach ([
            'https://app.example.com.evil.com',
            'https://evil-app.example.com',
            'http://app.example.com',          // scheme downgrade
            'https://app.example.com:8443',    // port variant
            'HTTPS://APP.EXAMPLE.COM',         // case games — exact identity only
        ] as $origin) {
            self::assertFalse($p->allowsOrigin($origin, new \WP_REST_Request()), $origin);
        }
    }

    public function testNullAndEmptyOriginNeverMatchEvenIfConfigured(): void
    {
        $p = new \NTDST_Cors_Policy(['origins' => ['null', '']]);
        self::assertFalse($p->allowsOrigin('null', new \WP_REST_Request()));
        self::assertFalse($p->allowsOrigin('', new \WP_REST_Request()));
    }

    public function testWildcardIsRejectedAtConstruction(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new \NTDST_Cors_Policy(['origins' => ['*']]);
    }

    public function testCallableResolverIsSupported(): void
    {
        $p = new \NTDST_Cors_Policy(['origins' => fn(string $o): bool => $o === 'https://dyn.example.com']);
        self::assertTrue($p->allowsOrigin('https://dyn.example.com', new \WP_REST_Request()));
        self::assertFalse($p->allowsOrigin('https://other.example.com', new \WP_REST_Request()));
    }

    public function testCallableResolverStillDeniesNullAndEmptyOrigin(): void
    {
        // Guard applies even to a resolver that would otherwise match anything.
        $p = new \NTDST_Cors_Policy(['origins' => fn(string $o): bool => true]);
        self::assertFalse($p->allowsOrigin('null', new \WP_REST_Request()));
        self::assertFalse($p->allowsOrigin('', new \WP_REST_Request()));
    }

    public function testNonListNonCallableOriginsThrowsAtConstruction(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new \NTDST_Cors_Policy(['origins' => 'https://app.example.com']);
    }

    public function testMissingOriginsKeyThrowsAtConstruction(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new \NTDST_Cors_Policy([]);
    }

    public function testWildcardAnywhereInListThrowsEvenAmongValidOrigins(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new \NTDST_Cors_Policy(['origins' => ['https://app.example.com', '*']]);
    }

    public function testTwoStringOriginListIsNeverMisreadAsAPhpCallable(): void
    {
        // ['strtolower', 'strtoupper'] (or any two-string list) is
        // PHP-callable-shaped; it must be treated as an exact-match
        // allow-list, not silently reinterpreted as a resolver.
        $p = new \NTDST_Cors_Policy(['origins' => ['strtolower', 'strtoupper']]);
        self::assertTrue($p->allowsOrigin('strtolower', new \WP_REST_Request()));
        self::assertFalse($p->allowsOrigin('https://app.example.com', new \WP_REST_Request()));
    }

    public function testDefaultsAreAppliedForMethodsHeadersAndMaxAge(): void
    {
        $p = new \NTDST_Cors_Policy(['origins' => ['https://app.example.com']]);
        self::assertSame(['GET', 'POST', 'OPTIONS'], $p->getMethods());
        self::assertSame(['Content-Type'], $p->getHeaders());
        self::assertNull($p->getMaxAge());
    }

    public function testConfiguredMethodsHeadersAndMaxAgeOverrideDefaults(): void
    {
        $p = new \NTDST_Cors_Policy([
            'origins' => ['https://app.example.com'],
            'methods' => ['GET'],
            'headers' => ['Authorization'],
            'max_age' => 600,
        ]);
        self::assertSame(['GET'], $p->getMethods());
        self::assertSame(['Authorization'], $p->getHeaders());
        self::assertSame(600, $p->getMaxAge());
    }
}
