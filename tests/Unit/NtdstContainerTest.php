<?php

declare(strict_types=1);

namespace Stride\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Unit tests for NTDST_Container.
 *
 * Covers the bug-fixes and polish landed in the 2026-05-18 hardening pass:
 *  - PSR-11-compliant has()
 *  - Circular dependency detection
 *  - Loud failure on typo'd class bindings
 *  - Factory parameter typing rules
 *  - make() rejects unknown $params keys
 *  - set() distinguishes "no second arg" from explicit null
 *  - Improved error messages
 *  - flush()/forget() clear reflection caches
 */
final class NtdstContainerTest extends TestCase
{
    private \NTDST_Container $container;

    protected function setUp(): void
    {
        parent::setUp();
        $this->container = new \NTDST_Container();
    }

    public function testGetReturnsRegisteredPrimitive(): void
    {
        $this->container->set('api_key', 'abc123');
        $this->assertSame('abc123', $this->container->get('api_key'));
    }

    public function testGetAutowiresUnregisteredClass(): void
    {
        $instance = $this->container->get(NtdstContainerFixtureA::class);
        $this->assertInstanceOf(NtdstContainerFixtureA::class, $instance);
    }

    public function testGetCachesSingleton(): void
    {
        $first = $this->container->get(NtdstContainerFixtureA::class);
        $second = $this->container->get(NtdstContainerFixtureA::class);
        $this->assertSame($first, $second);
    }

    public function testMakeReturnsFreshInstance(): void
    {
        $first = $this->container->make(NtdstContainerFixtureA::class);
        $second = $this->container->make(NtdstContainerFixtureA::class);
        $this->assertNotSame($first, $second);
    }

    public function testHasReturnsTrueForRegisteredId(): void
    {
        $this->container->set('api_key', 'abc');
        $this->assertTrue($this->container->has('api_key'));
    }

    public function testHasReturnsTrueForAutowirableClass(): void
    {
        $this->assertTrue($this->container->has(NtdstContainerFixtureA::class));
    }

    public function testHasReturnsFalseForUnknownId(): void
    {
        $this->assertFalse($this->container->has('does_not_exist_xyz'));
    }

    public function testCircularDependencyThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Circular dependency/');
        $this->container->get(NtdstContainerCycleA::class);
    }

    public function testTypoInBindingThrows(): void
    {
        $this->container->set('Some\\Interface', 'Stride\\Modules\\Edition\\EditionServce');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/non-existent class/');
        $this->container->get('Some\\Interface');
    }

    public function testBindingToRealClassResolvesCorrectly(): void
    {
        $this->container->set(NtdstContainerInterfaceX::class, NtdstContainerImplX::class);
        $instance = $this->container->get(NtdstContainerInterfaceX::class);
        $this->assertInstanceOf(NtdstContainerImplX::class, $instance);
    }

    public function testPrimitiveStringWithLowercaseDoesNotTriggerTypoCheck(): void
    {
        // Primitive string values shouldn't be mistaken for typo'd class bindings.
        $this->container->set('greeting', 'hello world');
        $this->assertSame('hello world', $this->container->get('greeting'));
    }

    public function testSetWithNoSecondArgRegistersIdAsClass(): void
    {
        $this->container->set(NtdstContainerFixtureA::class);
        $this->assertInstanceOf(
            NtdstContainerFixtureA::class,
            $this->container->get(NtdstContainerFixtureA::class)
        );
    }

    public function testSetWithExplicitNullStoresNull(): void
    {
        $this->container->set('nullable', null);
        $this->assertNull($this->container->get('nullable'));
    }

    public function testFactoryWithUntypedParamReceivesContainer(): void
    {
        $this->container->set('via_factory', function ($c) {
            return $c instanceof \NTDST_Container ? 'got-container' : 'no-container';
        });
        $this->assertSame('got-container', $this->container->get('via_factory'));
    }

    public function testFactoryWithContainerTypedParamReceivesContainer(): void
    {
        $this->container->set('typed_factory', function (\NTDST_Container $c) {
            return $c;
        });
        $this->assertSame($this->container, $this->container->get('typed_factory'));
    }

    public function testFactoryWithUnrelatedTypedParamGetsNoArgs(): void
    {
        // First param is `int $count = 5`; container must not be passed in.
        $this->container->set('counting_factory', function (int $count = 5) {
            return $count;
        });
        $this->assertSame(5, $this->container->get('counting_factory'));
    }

    public function testFactoryWithNoParamsIsCalled(): void
    {
        $this->container->set('zero_arg', fn() => 'plain');
        $this->assertSame('plain', $this->container->get('zero_arg'));
    }

    public function testMakeRejectsUnknownParamKey(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Unknown parameter\(s\) for/');
        $this->container->make(NtdstContainerFixtureWithCtorParam::class, ['typo_name' => 'oops']);
    }

    public function testMakeAcceptsKnownParamKey(): void
    {
        $obj = $this->container->make(NtdstContainerFixtureWithCtorParam::class, ['label' => 'hello']);
        $this->assertSame('hello', $obj->label);
    }

    public function testUnresolvableParameterErrorIncludesType(): void
    {
        try {
            $this->container->get(NtdstContainerFixtureUnresolvable::class);
            $this->fail('Expected exception');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('amount', $e->getMessage());
            $this->assertStringContainsString('int', $e->getMessage());
            $this->assertStringContainsString(NtdstContainerFixtureUnresolvable::class, $e->getMessage());
        }
    }

    public function testFlushClearsAllCaches(): void
    {
        $this->container->set('foo', 'bar');
        $this->container->get(NtdstContainerFixtureA::class);

        $this->container->flush();

        $this->assertFalse($this->container->has('foo'));
        // After flush, autowire still works for known classes
        $this->assertInstanceOf(
            NtdstContainerFixtureA::class,
            $this->container->get(NtdstContainerFixtureA::class)
        );
    }

    public function testForgetRemovesService(): void
    {
        $this->container->set('foo', 'bar');
        $this->container->forget('foo');
        $this->assertFalse(isset($this->container->keys()['foo']));
    }

    public function testContainerSelfReference(): void
    {
        $this->assertSame(
            $this->container,
            $this->container->get(\NTDST_Container::class)
        );
    }

    public function testCallInjectsTypedDependencies(): void
    {
        $result = $this->container->call(
            fn(NtdstContainerFixtureA $a) => get_class($a)
        );
        $this->assertSame(NtdstContainerFixtureA::class, $result);
    }

    public function testCallAcceptsPrimitiveOverride(): void
    {
        $result = $this->container->call(
            fn(string $name) => "hi, {$name}",
            ['name' => 'world']
        );
        $this->assertSame('hi, world', $result);
    }
}

// ---------------------------------------------------------------------------
// Test fixtures
// ---------------------------------------------------------------------------

final class NtdstContainerFixtureA
{
    public function __construct() {}
}

final class NtdstContainerFixtureWithCtorParam
{
    public function __construct(public string $label = 'default') {}
}

final class NtdstContainerFixtureUnresolvable
{
    public function __construct(int $amount) {}
}

interface NtdstContainerInterfaceX {}
final class NtdstContainerImplX implements NtdstContainerInterfaceX {}

final class NtdstContainerCycleA
{
    public function __construct(NtdstContainerCycleB $b) {}
}
final class NtdstContainerCycleB
{
    public function __construct(NtdstContainerCycleA $a) {}
}
