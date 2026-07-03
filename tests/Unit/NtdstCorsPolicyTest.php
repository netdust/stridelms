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
    protected function setUp(): void
    {
        parent::setUp();

        global $_test_filters;
        $_test_filters = [];
    }

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

    // -------------------------------------------------------------------
    // Header emission — applyCorsHeaders() overrides WP core's
    // rest_send_cors_headers() (reflect-any-origin + Allow-Credentials:
    // true default). Capturing seams (setHeaderSender/setHeaderRemover)
    // are required because native header()/header_remove() are
    // unobservable in the CLI SAPI — the proven todai-client pattern.
    // -------------------------------------------------------------------

    /** @var list<string> */
    private array $capturedSent = [];

    /** @var list<string> */
    private array $capturedRemoved = [];

    private function makePolicyWithCapture(array $configOverrides = []): \NTDST_Cors_Policy
    {
        $this->capturedSent = [];
        $this->capturedRemoved = [];

        $policy = new \NTDST_Cors_Policy(array_merge([
            'origins' => ['https://app.example.com'],
        ], $configOverrides));

        $policy->setHeaderSender(function (string $header): void {
            $this->capturedSent[] = $header;
        });
        $policy->setHeaderRemover(function (string $name): void {
            $this->capturedRemoved[] = $name;
        });

        return $policy;
    }

    public function testAllowedOriginSendsExactOriginVaryAndConfiguredMethodsHeaders(): void
    {
        $policy = $this->makePolicyWithCapture([
            'methods' => ['GET', 'POST'],
            'headers' => ['Content-Type', 'X-Custom'],
        ]);
        $policy->register('/stride/v1/widgets');

        $request = new \WP_REST_Request('GET', '/stride/v1/widgets');
        $request->set_header('origin', 'https://app.example.com');

        $served = $policy->applyCorsHeaders(true, null, $request, null);

        self::assertTrue($served);
        self::assertContains('Access-Control-Allow-Origin: https://app.example.com', $this->capturedSent);
        self::assertContains('Vary: Origin', $this->capturedSent);
        self::assertContains('Access-Control-Allow-Methods: GET, POST', $this->capturedSent);
        self::assertContains('Access-Control-Allow-Headers: Content-Type, X-Custom', $this->capturedSent);
        self::assertContains('Access-Control-Allow-Credentials', $this->capturedRemoved);

        foreach ($this->capturedSent as $header) {
            self::assertStringNotContainsString('Access-Control-Max-Age', $header);
        }
    }

    public function testAllowedOriginSendsMaxAgeOnlyWhenConfigured(): void
    {
        $policy = $this->makePolicyWithCapture(['max_age' => 600]);
        $policy->register('/stride/v1/widgets');

        $request = new \WP_REST_Request('GET', '/stride/v1/widgets');
        $request->set_header('origin', 'https://app.example.com');

        $policy->applyCorsHeaders(true, null, $request, null);

        self::assertContains('Access-Control-Max-Age: 600', $this->capturedSent);
    }

    public function testAllowCredentialsIsAlwaysRemovedRegardlessOfOriginOutcome(): void
    {
        // Mitigation 1 — this MUST fire unconditionally, even for the
        // denied-origin path, since WP core's own rest_send_cors_headers()
        // (prio 10) already set Allow-Credentials: true for ANY Origin.
        $policy = $this->makePolicyWithCapture();
        $policy->register('/stride/v1/widgets');

        $request = new \WP_REST_Request('GET', '/stride/v1/widgets');
        $request->set_header('origin', 'https://evil.example.com');

        $policy->applyCorsHeaders(true, null, $request, null);

        self::assertContains('Access-Control-Allow-Credentials', $this->capturedRemoved);
    }

    public function testDeniedOriginRemovesAllowOriginAndSendsNoCorsHeaders(): void
    {
        $policy = $this->makePolicyWithCapture();
        $policy->register('/stride/v1/widgets');

        $request = new \WP_REST_Request('GET', '/stride/v1/widgets');
        $request->set_header('origin', 'https://evil.example.com');

        $served = $policy->applyCorsHeaders(true, null, $request, null);

        self::assertTrue($served);
        self::assertContains('Access-Control-Allow-Credentials', $this->capturedRemoved);
        self::assertContains('Access-Control-Allow-Origin', $this->capturedRemoved);
        foreach ($this->capturedSent as $header) {
            self::assertStringNotContainsStringIgnoringCase('access-control-', $header);
        }
    }

    public function testMissingOriginHeaderIsTreatedLikeDeniedWithNoOriginEcho(): void
    {
        $policy = $this->makePolicyWithCapture();
        $policy->register('/stride/v1/widgets');

        $request = new \WP_REST_Request('GET', '/stride/v1/widgets');
        // No origin header set at all.

        $served = $policy->applyCorsHeaders(true, null, $request, null);

        self::assertTrue($served);
        self::assertContains('Access-Control-Allow-Credentials', $this->capturedRemoved);
        self::assertContains('Access-Control-Allow-Origin', $this->capturedRemoved);
        foreach ($this->capturedSent as $header) {
            self::assertStringNotContainsStringIgnoringCase('access-control-', $header);
        }
    }

    public function testForeignRouteNeverInvokesSenderOrRemoverAndPassesServedThrough(): void
    {
        $policy = $this->makePolicyWithCapture();
        $policy->register('/stride/v1/widgets');

        $request = new \WP_REST_Request('GET', '/wp/v2/posts');
        $request->set_header('origin', 'https://app.example.com');

        $served = $policy->applyCorsHeaders(false, null, $request, null);

        self::assertFalse($served, 'served value must pass through unchanged for a foreign route');
        self::assertSame([], $this->capturedSent);
        self::assertSame([], $this->capturedRemoved);
    }

    public function testReturnValueIsAlwaysTheIncomingServedValueNeverConvertedToTrue(): void
    {
        $policy = $this->makePolicyWithCapture();
        $policy->register('/stride/v1/widgets');

        $request = new \WP_REST_Request('GET', '/stride/v1/widgets');
        $request->set_header('origin', 'https://app.example.com');

        // Even on the matched-route, allowed-origin path, applyCorsHeaders()
        // must never upgrade a false $served to true.
        $served = $policy->applyCorsHeaders(false, null, $request, null);

        self::assertFalse($served);
    }

    public function testRegisterHooksRestPreServeRequestAtPriorityTwentyWithFourArgs(): void
    {
        global $_test_filters;
        $_test_filters = [];

        $policy = $this->makePolicyWithCapture();
        $policy->register('/stride/v1/widgets');

        self::assertTrue(has_filter('rest_pre_serve_request'));
        $registered = $_test_filters['rest_pre_serve_request'][0];
        self::assertSame(20, $registered['priority']);
        self::assertSame(4, $registered['accepted_args']);
    }

    // -------------------------------------------------------------------
    // register() prefix validation — review finding (P2): an empty string
    // trivially satisfies str_starts_with($route, ''), which would turn
    // this policy into a de facto GLOBAL CORS filter across every REST
    // route (including /wp/v2/*), directly contradicting the "never a
    // global filter" invariant documented on the class and enforced by
    // applyCorsHeaders()'s route-prefix check. A prefix missing its
    // leading slash is a silent no-op instead (get_route() always returns
    // a leading-slash form), which is its own trap — it never matches,
    // so the policy appears registered but emits nothing, ever.
    // -------------------------------------------------------------------

    public function testRegisterRejectsEmptyPrefixToPreventBecomingAGlobalCorsFilter(): void
    {
        $policy = $this->makePolicyWithCapture();

        $this->expectException(InvalidArgumentException::class);
        $policy->register('');
    }

    public function testRegisterRejectsPrefixMissingLeadingSlashAsASilentNoOpTrap(): void
    {
        $policy = $this->makePolicyWithCapture();

        $this->expectException(InvalidArgumentException::class);
        $policy->register('stride/v1');
    }

    public function testRegisterAcceptsAValidLeadingSlashPrefix(): void
    {
        $policy = $this->makePolicyWithCapture();

        $policy->register('/stride-test/v1/echo');

        self::assertTrue(has_filter('rest_pre_serve_request'));
    }

    // -------------------------------------------------------------------
    // Null-origin emission pin — review finding (P2 minor): the sandboxed
    // -iframe literal Origin: null must never make it through the
    // emission layer. allowsOrigin() already guards this, but pin it here
    // too so a future refactor of applyCorsHeaders()'s branch structure
    // can't unhook the guard without a test noticing.
    // -------------------------------------------------------------------

    public function testNullOriginEmitsNoAccessControlHeadersAndRemovesCredentialsAndAllowOrigin(): void
    {
        $policy = $this->makePolicyWithCapture();
        $policy->register('/stride/v1/widgets');

        $request = new \WP_REST_Request('GET', '/stride/v1/widgets');
        $request->set_header('origin', 'null');

        $served = $policy->applyCorsHeaders(true, null, $request, null);

        self::assertTrue($served);
        self::assertContains('Access-Control-Allow-Credentials', $this->capturedRemoved);
        self::assertContains('Access-Control-Allow-Origin', $this->capturedRemoved);
        foreach ($this->capturedSent as $header) {
            self::assertStringNotContainsStringIgnoringCase('access-control-', $header);
        }
    }
}
