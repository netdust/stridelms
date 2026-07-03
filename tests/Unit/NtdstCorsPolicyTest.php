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
        // ['DateTime', 'createFromFormat'] IS is_callable() in PHP (verified:
        // a static-method [class, method] pair) — the sharpest fixture for
        // the array-before-is_callable guard ordering. The previous fixture
        // (['strtolower', 'strtoupper']) was NOT is_callable(), so that test
        // passed regardless of guard order — vacuous.
        //
        // Branch-distinguishing assertions: in LIST mode, 'DateTime' is
        // literally on the allow-list → true, and an unlisted origin → false.
        // In (buggy) CALLABLE mode, allowsOrigin() would invoke
        // DateTime::createFromFormat($origin, $request) — a TypeError, since
        // the second argument is a WP_REST_Request, not a string. So a clean
        // true/false here proves list-mode semantics, not resolver semantics.
        $p = new \NTDST_Cors_Policy(['origins' => ['DateTime', 'createFromFormat']]);
        self::assertTrue($p->allowsOrigin('DateTime', new \WP_REST_Request()));
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
        global $_test_filters; // read below; reset already done in setUp()

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

    // -------------------------------------------------------------------
    // P2 gate findings — root-prefix guard, multi-prefix register,
    // segment-boundary matching, callable-path emission shape gate,
    // empty-registration bail.
    // -------------------------------------------------------------------

    public function testRegisterRejectsRootSlashPrefixAsAGlobalFilterInDisguise(): void
    {
        // '/' passes the empty and leading-slash checks, but
        // str_starts_with($route, '/') is true for EVERY REST route —
        // functionally identical to the empty-prefix global-filter trap.
        $policy = $this->makePolicyWithCapture();

        $this->expectException(InvalidArgumentException::class);
        $policy->register('/');
    }

    public function testRepeatRegisterProtectsEveryPrefixNotJustTheLast(): void
    {
        // A second register() call must APPEND, not overwrite — otherwise
        // the first prefix is silently left fail-open on WP core's
        // reflect-origin + Allow-Credentials default (prio 10).
        $policy = $this->makePolicyWithCapture();
        $policy->register('/a/v1');
        $policy->register('/b/v1');

        foreach (['/a/v1/things', '/b/v1/things'] as $route) {
            $this->capturedSent = [];
            $this->capturedRemoved = [];

            $request = new \WP_REST_Request('GET', $route);
            $request->set_header('origin', 'https://app.example.com');

            $policy->applyCorsHeaders(true, null, $request, null);

            self::assertContains(
                'Access-Control-Allow-Origin: https://app.example.com',
                $this->capturedSent,
                "route {$route} must receive policy treatment",
            );
            self::assertContains('Access-Control-Allow-Credentials', $this->capturedRemoved, $route);
        }

        // A third, never-registered prefix stays untouched.
        $this->capturedSent = [];
        $this->capturedRemoved = [];

        $request = new \WP_REST_Request('GET', '/c/v1/things');
        $request->set_header('origin', 'https://app.example.com');

        $policy->applyCorsHeaders(true, null, $request, null);

        self::assertSame([], $this->capturedSent);
        self::assertSame([], $this->capturedRemoved);
    }

    public function testPrefixMatchingRespectsPathSegmentBoundaries(): void
    {
        // '/stride/v1' must govern '/stride/v1' (exact) and '/stride/v1/sub'
        // (child segment) but NEVER '/stride/v10/...' or '/stride/v1-admin/...'
        // — a plain str_starts_with() prefix check leaks across segment
        // boundaries into sibling namespaces.
        $policy = $this->makePolicyWithCapture();
        $policy->register('/stride/v1');

        foreach (['/stride/v1', '/stride/v1/sub'] as $route) {
            $this->capturedSent = [];
            $this->capturedRemoved = [];

            $request = new \WP_REST_Request('GET', $route);
            $request->set_header('origin', 'https://app.example.com');

            $policy->applyCorsHeaders(true, null, $request, null);

            self::assertContains(
                'Access-Control-Allow-Origin: https://app.example.com',
                $this->capturedSent,
                "route {$route} must match prefix /stride/v1",
            );
        }

        foreach (['/stride/v10/x', '/stride/v1-admin/x'] as $route) {
            $this->capturedSent = [];
            $this->capturedRemoved = [];

            $request = new \WP_REST_Request('GET', $route);
            $request->set_header('origin', 'https://app.example.com');

            $policy->applyCorsHeaders(true, null, $request, null);

            self::assertSame([], $this->capturedSent, "route {$route} must NOT match prefix /stride/v1");
            self::assertSame([], $this->capturedRemoved, "route {$route} must NOT match prefix /stride/v1");
        }
    }

    public function testMalformedOriginApprovedByOverPermissiveCallableIsNeverEmitted(): void
    {
        // The emission shape gate must fire BEFORE header() — PHP's header()
        // would throw on CR/LF, but the gate means no exception path exists
        // at all: a malformed origin is simply treated as denied.
        $malformed = "https://evil.test\r\nX-Injected: 1";

        $policy = $this->makePolicyWithCapture([
            'origins' => static fn(string $o): bool => true, // over-permissive resolver
        ]);
        $policy->register('/stride/v1/widgets');

        $request = new \WP_REST_Request('GET', '/stride/v1/widgets');
        $request->set_header('origin', $malformed);

        $served = $policy->applyCorsHeaders(true, null, $request, null);

        self::assertTrue($served);
        self::assertContains('Access-Control-Allow-Origin', $this->capturedRemoved);
        foreach ($this->capturedSent as $header) {
            self::assertStringNotContainsStringIgnoringCase('access-control-', $header);
            self::assertStringNotContainsString('X-Injected', $header);
        }
    }

    public function testApplyCorsHeadersWithoutRegisterIsAFullNoOp(): void
    {
        // Raw add_filter misuse defense: a policy whose register() was never
        // called has no scope — it must bail before ANY header side effect.
        $policy = $this->makePolicyWithCapture();

        $request = new \WP_REST_Request('GET', '/stride/v1/widgets');
        $request->set_header('origin', 'https://app.example.com');

        $served = $policy->applyCorsHeaders(false, null, $request, null);

        self::assertFalse($served);
        self::assertSame([], $this->capturedSent);
        self::assertSame([], $this->capturedRemoved);
    }
}
