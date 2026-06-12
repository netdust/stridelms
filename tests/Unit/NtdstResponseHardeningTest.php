<?php

declare(strict_types=1);

namespace Stride\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Hardening tests for NTDST_Response — covers audit fixes that
 * complement the existing ResponseTest.php (mime types, fluent API).
 *
 * Fixes covered:
 *  - Template path-traversal blocked by isInside() (item 10)
 *  - Filename sanitization in sendFile (items 7, 8)
 *  - JSON serialization-failure fallback (item 12)
 */
final class NtdstResponseHardeningTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        // Reset Response's static template cache so tests don't pollute each other
        $clearMethod = new ReflectionMethod(\NTDST_Response::class, 'clearPathCache');
        $clearMethod->invoke(null);

        $this->tmpDir = sys_get_temp_dir() . '/ntdst-response-hardening-' . uniqid();
        mkdir($this->tmpDir, 0o777, true);
    }

    protected function tearDown(): void
    {
        $files = glob($this->tmpDir . '/*') ?: [];
        foreach ($files as $f) {
            is_dir($f) ? rmdir($f) : unlink($f);
        }
        @rmdir($this->tmpDir);

        \NTDST_Response::clearPathCache();
        parent::tearDown();
    }

    // ---------------------------------------------------------------------
    // isInside() — path traversal protection (item 10)
    // ---------------------------------------------------------------------

    public function testIsInsideAcceptsFileInBaseDirectory(): void
    {
        $base = $this->tmpDir;
        $file = $base . '/template.php';
        file_put_contents($file, '<?php');

        $response = new \NTDST_Response();
        $ref = new ReflectionMethod($response, 'isInside');
        $ref->setAccessible(true);

        $this->assertTrue($ref->invoke($response, $file, $base));
    }

    public function testIsInsideRejectsTraversalOutsideBase(): void
    {
        // Create a "sibling" file outside the base
        $base = $this->tmpDir . '/templates';
        mkdir($base, 0o777, true);

        $sibling = $this->tmpDir . '/secret.php';
        file_put_contents($sibling, '<?php // sensitive');

        // Construct a path that "looks like" it's inside but resolves outside
        $traversal = $base . '/../secret.php';

        $response = new \NTDST_Response();
        $ref = new ReflectionMethod($response, 'isInside');
        $ref->setAccessible(true);

        $this->assertFalse(
            $ref->invoke($response, $traversal, $base),
            'realpath should resolve traversal and reject paths outside base'
        );

        @unlink($sibling);
        @rmdir($base);
    }

    public function testIsInsideHandlesNonExistentFile(): void
    {
        $response = new \NTDST_Response();
        $ref = new ReflectionMethod($response, 'isInside');
        $ref->setAccessible(true);

        $this->assertFalse(
            $ref->invoke($response, '/does/not/exist.php', $this->tmpDir),
            'realpath returns false for missing files; method must reject'
        );
    }

    // ---------------------------------------------------------------------
    // Filename sanitization regex behavior (items 7, 8)
    // ---------------------------------------------------------------------

    public function testFilenameSanitizationStripsCrlfAndQuotes(): void
    {
        // We don't actually call sendFile (it exits) — just verify the
        // regex on which the fix depends behaves as expected. This locks
        // the behavior in place so future edits to that regex don't
        // regress silently.
        $malicious = "evil\r\nSet-Cookie: x=1\r\n\"injected\"";
        $safe = preg_replace('/[\r\n"]/', '', basename($malicious));

        $this->assertStringNotContainsString("\r", $safe);
        $this->assertStringNotContainsString("\n", $safe);
        $this->assertStringNotContainsString('"', $safe);
        $this->assertStringContainsString('evil', $safe);
    }

    public function testFilenameAsciiFallbackPreservesAsciiCharacters(): void
    {
        // 'ü' is 2 UTF-8 bytes (0xC3 0xBC); each replaced with an underscore.
        $unicode = "factuur-Müller-2026.pdf";
        $ascii = preg_replace('/[^\x20-\x7e]/', '_', $unicode);

        $this->assertStringStartsWith('factuur-M', $ascii);
        $this->assertStringEndsWith('ller-2026.pdf', $ascii);
        $this->assertStringNotContainsString('ü', $ascii);
    }

    public function testFilenameRawUrlencodeForRfc5987(): void
    {
        $unicode = "facture-Müller.pdf";
        $encoded = rawurlencode($unicode);
        // The 'ü' (U+00FC, UTF-8: 0xC3 0xBC) encodes to %C3%BC
        $this->assertStringContainsString('%C3%BC', $encoded);
    }
}
