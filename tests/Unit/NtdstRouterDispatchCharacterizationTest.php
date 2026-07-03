<?php

declare(strict_types=1);

namespace Stride\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Characterization tests for NTDST_Router::handleTemplateInclude()'s
 * current dispatch return contract.
 *
 * Pins the CURRENT behavior (per the return-contract docblock on
 * NTDST_Router::register()) BEFORE Task 1.3 refactors the result-handling
 * into a resolveRouteResult() seam and adds the missing NTDST_Response
 * branch. These three pins prove Task 1.3's change alters ONLY the
 * Response case:
 *
 *  (a) matched route callback returns an existing file path → that path
 *      is returned by handleTemplateInclude().
 *  (b) matched route callback returns false → falls through, original
 *      $template is returned.
 *  (c) no route matches → original $template is returned.
 *
 * Deliberately NOT covered here: the null/true return paths, because
 * they call exit() and cannot be exercised in-process. That risk is
 * deferred to the integration gate (Task 4.1).
 *
 * Each test uses a fresh NTDST_Router() instance (matching
 * NtdstRouterTest's convention) rather than the ntdst_router() singleton,
 * so route registrations in one test cannot leak into another via the
 * shared static instance. Note, however, that each `new NTDST_Router()`
 * still registers global `template_include`/`redirect_canonical` filter
 * closures in the stub's process-global `$_test_filters` — inert today
 * because these tests call handleTemplateInclude() directly, but a future
 * test that dispatches via apply_filters() would see stale closures from
 * prior instances.
 */
final class NtdstRouterDispatchCharacterizationTest extends TestCase
{
    private \NTDST_Router $router;
    private ?string $fixtureFile = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->router = new \NTDST_Router();
    }

    protected function tearDown(): void
    {
        if ($this->fixtureFile !== null && file_exists($this->fixtureFile)) {
            unlink($this->fixtureFile);
        }
        parent::tearDown();
    }

    /**
     * Sets $_SERVER['REQUEST_URI']/['REQUEST_METHOD'] for the duration of
     * $fn(), restoring the previous values (including the unset case) in
     * a finally block regardless of how $fn() completes.
     */
    private function withServerRequest(string $uri, string $method, callable $fn): mixed
    {
        $previousUri = $_SERVER['REQUEST_URI'] ?? null;
        $previousMethod = $_SERVER['REQUEST_METHOD'] ?? null;

        try {
            $_SERVER['REQUEST_URI'] = $uri;
            $_SERVER['REQUEST_METHOD'] = $method;

            return $fn();
        } finally {
            if ($previousUri !== null) {
                $_SERVER['REQUEST_URI'] = $previousUri;
            } else {
                unset($_SERVER['REQUEST_URI']);
            }
            if ($previousMethod !== null) {
                $_SERVER['REQUEST_METHOD'] = $previousMethod;
            } else {
                unset($_SERVER['REQUEST_METHOD']);
            }
        }
    }

    public function testMatchedRouteReturningExistingFilePathIsReturnedAsTemplate(): void
    {
        $this->fixtureFile = tempnam(sys_get_temp_dir(), 'ntdst_router_fixture_');
        $this->assertNotFalse($this->fixtureFile, 'tempnam() must produce a real file for the contract');

        $this->router->get('projects/:slug', fn() => $this->fixtureFile);

        $result = $this->withServerRequest(
            '/projects/foo',
            'GET',
            fn() => $this->router->handleTemplateInclude('/original/template.php'),
        );

        $this->assertSame(
            $this->fixtureFile,
            $result,
            'an existing file path returned by the matched callback must be returned as the template',
        );
    }

    public function testMatchedRouteReturningFalseFallsThroughToOriginalTemplate(): void
    {
        $this->router->get('projects/:slug', fn() => false);

        $result = $this->withServerRequest(
            '/projects/foo',
            'GET',
            fn() => $this->router->handleTemplateInclude('/original/template.php'),
        );

        $this->assertSame(
            '/original/template.php',
            $result,
            'a callback returning false must fall through and leave the original template untouched',
        );
    }

    public function testNoRouteMatchReturnsOriginalTemplate(): void
    {
        $this->router->get('projects/:slug', fn() => '/should-not-be-reached.php');

        $result = $this->withServerRequest(
            '/nowhere/matches',
            'GET',
            fn() => $this->router->handleTemplateInclude('/original/template.php'),
        );

        $this->assertSame(
            '/original/template.php',
            $result,
            'when no route matches the URL, the original template must be returned unchanged',
        );
    }

    /**
     * Characterization pin for the resolveRouteResult() parity decision:
     * BEFORE Task 1.3, an unrecognized callback return (int, array, …)
     * fell off the end of handleTemplateInclude()'s if-chain and the
     * foreach CONTINUED scanning later routes. That observable behavior
     * must survive the refactor (the brief's "return $template" fallback
     * would short-circuit it — pinned test is the authority).
     */
    public function testUnrecognizedReturnTypeContinuesScanningLaterRoutes(): void
    {
        $this->fixtureFile = tempnam(sys_get_temp_dir(), 'ntdst_router_fixture_');
        $this->assertNotFalse($this->fixtureFile, 'tempnam() must produce a real file for the contract');

        $this->router->get('projects/:slug', fn() => 12345);
        $this->router->get('projects/:slug', fn() => $this->fixtureFile);

        $result = $this->withServerRequest(
            '/projects/foo',
            'GET',
            fn() => $this->router->handleTemplateInclude('/original/template.php'),
        );

        $this->assertSame(
            $this->fixtureFile,
            $result,
            'an unrecognized return type must not short-circuit scanning — a later matching route must still win',
        );
    }

    /**
     * Builds the test-seam router subclass for the resolveRouteResult()
     * tests: renderResponse() records instead of rendering+exiting.
     */
    private function makeRecordingRouter(): \NTDST_Router
    {
        return new class extends \NTDST_Router {
            /** @var list<?string> */
            public array $rendered = [];

            protected function renderResponse(\NTDST_Response $response): void
            {
                // test seam — production renders + exits; test records
                $this->rendered[] = $response->getTemplate();
            }

            public function expose(mixed $result, string $template): string|false|null
            {
                return $this->resolveRouteResult($result, $template);
            }
        };
    }

    /**
     * Task 1.3 RED: the latent bug. A pattern-route callback returning an
     * NTDST_Response (without itself calling an exiting output method)
     * must be recognized as handled — parity with when()/template().
     */
    public function testResponseWithTemplateIsRecognizedAsHandled(): void
    {
        $router = $this->makeRecordingRouter();

        $response = \ntdst_response()->template('project/single');
        $outcome = $router->expose($response, '/theme/index.php');

        self::assertNull($outcome, 'A Response return must be treated as handled, not fall through');
        self::assertSame(['project/single'], $router->rendered);
    }

    /**
     * Parity edge: when()/template() exit on a Response even when no
     * template was set (documented, deliberate). resolveRouteResult()
     * must mirror that — still delegated to renderResponse() and still
     * handled (null → caller exits).
     */
    public function testResponseWithoutTemplateIsStillTreatedAsHandled(): void
    {
        $router = $this->makeRecordingRouter();

        $outcome = $router->expose(\ntdst_response(), '/theme/index.php');

        self::assertNull($outcome, 'A Response with no template is still handled — parity with when()/template()');
        self::assertSame([null], $router->rendered, 'the Response is delegated to renderResponse() even without a template');
    }

    /**
     * The PRODUCTION renderResponse() (not the test seam): a Response with
     * no template set must render nothing and return — render() is never
     * called (it would exit/include), in parity with when()/template()
     * which skip render() when getTemplate() is empty.
     */
    public function testProductionRenderResponseWithoutTemplateRendersNothing(): void
    {
        $router = new class extends \NTDST_Router {
            public function exposeRender(\NTDST_Response $response): void
            {
                $this->renderResponse($response);
            }
        };

        $router->exposeRender(\ntdst_response());

        // Reaching this line proves render() was never invoked: render()
        // includes a template and exits the process. beStrictAboutOutput
        // additionally fails the test if anything was echoed.
        $this->addToAssertionCount(1);
    }
}
