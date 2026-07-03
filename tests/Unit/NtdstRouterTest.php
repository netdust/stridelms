<?php

declare(strict_types=1);

namespace Stride\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Unit tests for NTDST_Router.
 *
 * Covers the audit fixes:
 *  - compilePattern() escapes regex meta in literal path segments (item 13)
 *  - url() urlencodes parameter values (item 7)
 *  - $_SERVER guards in preventRedirectForRoutes (items 1, 3)
 *
 * Behavior that exits / redirects / hits template_include is left to
 * integration tests — the goal here is to exercise the pure-PHP logic.
 */
final class NtdstRouterTest extends TestCase
{
    private \NTDST_Router $router;

    protected function setUp(): void
    {
        parent::setUp();
        $this->router = new \NTDST_Router();
    }

    // ---------------------------------------------------------------------
    // compilePattern (item 13)
    // ---------------------------------------------------------------------

    public function testCompilePatternMatchesSimplePath(): void
    {
        $regex = $this->compile('projects');
        $this->assertSame(1, preg_match($regex, 'projects'));
        $this->assertSame(1, preg_match($regex, 'projects/'));
        $this->assertSame(0, preg_match($regex, 'projects/extra'));
    }

    public function testCompilePatternCapturesNamedParam(): void
    {
        $regex = $this->compile('projects/:slug');
        $this->assertSame(1, preg_match($regex, 'projects/foo-bar', $matches));
        $this->assertSame('foo-bar', $matches['slug']);
    }

    public function testCompilePatternCapturesMultipleParams(): void
    {
        $regex = $this->compile('users/:id/posts/:post_id');
        $this->assertSame(1, preg_match($regex, 'users/42/posts/abc', $matches));
        $this->assertSame('42', $matches['id']);
        $this->assertSame('abc', $matches['post_id']);
    }

    public function testCompilePatternEscapesDotsAsLiterals(): void
    {
        // Dot is regex-meta. Old code matched any character; new code matches literal dot.
        $regex = $this->compile('v1.0/users');
        $this->assertSame(1, preg_match($regex, 'v1.0/users'));
        $this->assertSame(0, preg_match($regex, 'v1X0/users'), 'dot must be literal');
    }

    public function testCompilePatternEscapesParensAsLiterals(): void
    {
        $regex = $this->compile('feed(rss)/items');
        $this->assertSame(1, preg_match($regex, 'feed(rss)/items'));
        $this->assertSame(0, preg_match($regex, 'feedrss/items'));
    }

    public function testCompilePatternRejectsExtraTrailingSegments(): void
    {
        $regex = $this->compile('items/:id');
        $this->assertSame(0, preg_match($regex, 'items/42/extra'));
    }

    public function testCompilePatternAllowsOptionalTrailingSlash(): void
    {
        $regex = $this->compile('items/:id');
        $this->assertSame(1, preg_match($regex, 'items/42'));
        $this->assertSame(1, preg_match($regex, 'items/42/'));
    }

    // ---------------------------------------------------------------------
    // url() urlencoding (item 7)
    // ---------------------------------------------------------------------

    public function testUrlEncodesSpaces(): void
    {
        // home_url stub returns 'http://example.com' + path
        $url = $this->router->url('/items/:slug', ['slug' => 'hello world']);
        $this->assertStringContainsString('hello+world', $url);
    }

    public function testUrlEncodesSlashes(): void
    {
        $url = $this->router->url('/items/:slug', ['slug' => 'a/b']);
        $this->assertStringNotContainsString('items/a/b', $url, 'slash inside param must be encoded');
        $this->assertStringContainsString('a%2Fb', $url);
    }

    public function testUrlEncodesQuestionMark(): void
    {
        $url = $this->router->url('/search/:q', ['q' => 'what?']);
        $this->assertStringContainsString('what%3F', $url);
    }

    public function testUrlPassesNumericParam(): void
    {
        $url = $this->router->url('/items/:id', ['id' => 42]);
        $this->assertStringContainsString('items/42', $url);
    }

    public function testUrlIgnoresUnknownParams(): void
    {
        // Documented behavior: extra params are silently dropped.
        $url = $this->router->url('/items/:id', ['id' => 1, 'unused' => 'x']);
        $this->assertStringContainsString('items/1', $url);
        $this->assertStringNotContainsString('unused', $url);
        $this->assertStringNotContainsString('=x', $url);
    }

    // ---------------------------------------------------------------------
    // $_SERVER guards (items 1, 3)
    // ---------------------------------------------------------------------

    public function testPreventRedirectDoesNotErrorWithoutServerKeys(): void
    {
        // Simulate CLI/test SAPI where $_SERVER lacks REQUEST_URI/METHOD.
        $previousUri = $_SERVER['REQUEST_URI'] ?? null;
        $previousMethod = $_SERVER['REQUEST_METHOD'] ?? null;
        unset($_SERVER['REQUEST_URI'], $_SERVER['REQUEST_METHOD']);

        try {
            // No routes registered, no exceptions expected.
            $result = $this->router->preventRedirectForRoutes('http://example.com/foo');
            $this->assertSame('http://example.com/foo', $result);
        } finally {
            // Restore globals so subsequent tests aren't affected.
            if ($previousUri !== null) {
                $_SERVER['REQUEST_URI'] = $previousUri;
            }
            if ($previousMethod !== null) {
                $_SERVER['REQUEST_METHOD'] = $previousMethod;
            }
        }
    }

    public function testPreventRedirectHandlesFalseRedirectUrl(): void
    {
        $_SERVER['REQUEST_URI'] = '/some/path';
        $_SERVER['REQUEST_METHOD'] = 'GET';

        // wp_redirect filters pass `false` when another filter already cancelled.
        $result = $this->router->preventRedirectForRoutes(false);
        $this->assertFalse($result);
    }

    public function testPreventRedirectReturnsFalseWhenRouteMatches(): void
    {
        $_SERVER['REQUEST_URI'] = '/projects/foo';
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $this->router->get('projects/:slug', fn() => null);

        $result = $this->router->preventRedirectForRoutes('http://example.com/canonical');
        $this->assertFalse($result, 'matched route must suppress canonical redirect');
    }

    // ---------------------------------------------------------------------
    // rest() facade (Task 3.4) — per-namespace cached NTDST_Rest_Registrar
    // ---------------------------------------------------------------------

    public function testRestReturnsARestRegistrar(): void
    {
        $registrar = $this->router->rest('stride/v1');
        $this->assertInstanceOf(\NTDST_Rest_Registrar::class, $registrar);
    }

    public function testRestReturnsSameInstanceForSameNamespace(): void
    {
        $first = $this->router->rest('stride/v1');
        $second = $this->router->rest('stride/v1');

        $this->assertSame(
            $first,
            $second,
            'rest() must cache per namespace — a second call for the same namespace returns the same registrar.',
        );
    }

    public function testRestReturnsDistinctInstancesForDifferentNamespaces(): void
    {
        $a = $this->router->rest('stride/v1');
        $b = $this->router->rest('other/v1');

        $this->assertNotSame(
            $a,
            $b,
            'rest() must return a distinct registrar per namespace — different namespaces never share an instance.',
        );
    }

    // ---------------------------------------------------------------------
    // helpers
    // ---------------------------------------------------------------------

    private function compile(string $pattern): string
    {
        $ref = new ReflectionMethod($this->router, 'compilePattern');
        $ref->setAccessible(true);
        return $ref->invoke($this->router, $pattern);
    }
}
