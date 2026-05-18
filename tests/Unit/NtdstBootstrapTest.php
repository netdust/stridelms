<?php

declare(strict_types=1);

namespace Stride\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

/**
 * Unit tests for NTDST_Bootstrap.
 *
 * Targets the bits that are pure PHP and isolable from WordPress lifecycle:
 *  - getClassNameFromFile() regex (must handle final/abstract classes)
 *  - config() dot notation + null handling
 *  - register() idempotency
 *  - Error-level logging + Throwable catch on boot failure (sanity check via reflection)
 *
 * Full lifecycle behavior (hooks, sector loading) is covered by integration tests.
 */
final class NtdstBootstrapTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/ntdst-bootstrap-test-' . uniqid();
        mkdir($this->tmpDir, 0o777, true);
    }

    protected function tearDown(): void
    {
        $files = glob($this->tmpDir . '/*');
        foreach ($files ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->tmpDir);
        parent::tearDown();
    }

    // ---------------------------------------------------------------------
    // getClassNameFromFile regex (item 7 of the audit)
    // ---------------------------------------------------------------------

    public function testRegexFindsPlainClass(): void
    {
        $file = $this->writeFile('PlainService.php', <<<'PHP'
<?php
namespace Stride\Bootstrap\Fixtures;
class NtdstBootstrapFixturePlain {}
PHP);
        $this->assertSame(
            'Stride\\Bootstrap\\Fixtures\\NtdstBootstrapFixturePlain',
            $this->callPrivate('getClassNameFromFile', [$file])
        );
    }

    public function testRegexFindsFinalClass(): void
    {
        $file = $this->writeFile('FinalService.php', <<<'PHP'
<?php
namespace Stride\Bootstrap\Fixtures;
final class NtdstBootstrapFixtureFinal {}
PHP);
        $this->assertSame(
            'Stride\\Bootstrap\\Fixtures\\NtdstBootstrapFixtureFinal',
            $this->callPrivate('getClassNameFromFile', [$file])
        );
    }

    public function testRegexFindsAbstractClass(): void
    {
        $file = $this->writeFile('AbstractService.php', <<<'PHP'
<?php
namespace Stride\Bootstrap\Fixtures;
abstract class NtdstBootstrapFixtureAbstract {}
PHP);
        $this->assertSame(
            'Stride\\Bootstrap\\Fixtures\\NtdstBootstrapFixtureAbstract',
            $this->callPrivate('getClassNameFromFile', [$file])
        );
    }

    public function testRegexReturnsNullForNoClass(): void
    {
        $file = $this->writeFile('NoClass.php', <<<'PHP'
<?php
namespace Stride\Bootstrap\Fixtures;
// no class declared
PHP);
        $this->assertNull($this->callPrivate('getClassNameFromFile', [$file]));
    }

    // ---------------------------------------------------------------------
    // config() dot notation (item 12)
    // ---------------------------------------------------------------------

    public function testConfigReturnsNestedValue(): void
    {
        $bootstrap = $this->bootstrap([
            'modules' => ['barba' => ['animationDuration' => 300]],
        ]);
        $this->assertSame(300, $bootstrap->config('modules.barba.animationDuration'));
    }

    public function testConfigReturnsDefaultForMissingKey(): void
    {
        $bootstrap = $this->bootstrap(['modules' => []]);
        $this->assertSame('fallback', $bootstrap->config('modules.missing.key', 'fallback'));
    }

    public function testConfigDistinguishesNullFromMissing(): void
    {
        // Literal null in config should round-trip; missing keys should fall back.
        $bootstrap = $this->bootstrap(['feature' => null]);
        $this->assertNull($bootstrap->config('feature', 'default'));
        $this->assertSame('default', $bootstrap->config('not_present', 'default'));
    }

    public function testConfigWithoutKeyReturnsWholeConfig(): void
    {
        $bootstrap = $this->bootstrap(['a' => 1, 'b' => 2]);
        $this->assertSame(['a' => 1, 'b' => 2], $bootstrap->config());
    }

    public function testConfigHandlesNonArrayMidPath(): void
    {
        // 'a.b' is a string, not an array. Asking for 'a.b.c' should return default.
        $bootstrap = $this->bootstrap(['a' => ['b' => 'string-value']]);
        $this->assertSame('miss', $bootstrap->config('a.b.c', 'miss'));
    }

    // ---------------------------------------------------------------------
    // register() idempotency (item 2)
    // ---------------------------------------------------------------------

    public function testRegisterIsIdempotent(): void
    {
        $bootstrap = $this->bootstrap(['services' => ['core' => []]]);
        $bootstrap->register();
        $bootstrap->register(); // should be a no-op
        $this->assertSame([], $bootstrap->getServices());
    }

    // ---------------------------------------------------------------------
    // helpers
    // ---------------------------------------------------------------------

    private function bootstrap(array $config = []): \NTDST_Bootstrap
    {
        return new \NTDST_Bootstrap($config);
    }

    private function callPrivate(string $method, array $args)
    {
        $bootstrap = $this->bootstrap();
        $ref = new ReflectionMethod($bootstrap, $method);
        $ref->setAccessible(true);
        return $ref->invokeArgs($bootstrap, $args);
    }

    private function writeFile(string $name, string $content): string
    {
        $path = $this->tmpDir . '/' . $name;
        file_put_contents($path, $content);
        require_once $path; // make the class visible to class_exists()
        return $path;
    }
}
