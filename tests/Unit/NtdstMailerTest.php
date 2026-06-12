<?php

declare(strict_types=1);

namespace Stride\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Unit tests for NTDST_Mailer.
 *
 * Covers the audit fixes:
 *  - subject() strips CRLF (item 5)
 *  - from() sanitizes email + strips CRLF from name (item 1)
 *  - header() strips CRLF + ':' from name (item 2)
 *  - attach() refuses files outside allowed bases (item 4)
 *  - getDefaultTemplate() escapes {{key}} substitutions (item 6)
 *
 * Render paths that talk to Response.php and the filesystem are
 * covered by integration tests.
 */
final class NtdstMailerTest extends TestCase
{
    private \NTDST_Mailer $mailer;
    private string $tmpDir;
    private string $insideFile;
    private string $outsideFile;

    protected function setUp(): void
    {
        parent::setUp();

        global $_test_filters, $_test_options;
        $_test_filters = [];
        $_test_options['admin_email'] = 'admin@example.com';
        $_test_options['blogname'] = 'Test Site';
        $_test_options['home'] = 'https://example.com';

        $this->mailer = new \NTDST_Mailer();

        // Build a sandbox with an "inside" file (under our test upload dir)
        // and an "outside" file (sibling) to verify path constraint.
        $this->tmpDir = sys_get_temp_dir() . '/ntdst-mailer-test-' . uniqid();
        $uploads = $this->tmpDir . '/uploads';
        mkdir($uploads, 0o777, true);

        $this->insideFile = $uploads . '/document.pdf';
        $this->outsideFile = $this->tmpDir . '/secret.txt';
        file_put_contents($this->insideFile, 'inside');
        file_put_contents($this->outsideFile, 'outside');

        // Point wp_upload_dir at our sandbox via a filter on attachment bases.
        $bases = [$uploads];
        add_filter('ntdst_mail_attachment_bases', function () use ($bases) {
            return $bases;
        });
    }

    protected function tearDown(): void
    {
        @unlink($this->insideFile);
        @unlink($this->outsideFile);
        @rmdir(dirname($this->insideFile));
        @rmdir($this->tmpDir);
        parent::tearDown();
    }

    // ---------------------------------------------------------------------
    // subject() strips CRLF (item 5)
    // ---------------------------------------------------------------------

    public function testSubjectStripsCrlf(): void
    {
        $this->mailer->subject("Hello\r\nBcc: attacker@evil.com");
        $array = $this->mailer->toArray();
        $this->assertStringNotContainsString("\r", $array['subject']);
        $this->assertStringNotContainsString("\n", $array['subject']);
    }

    public function testSubjectPreservesNormalText(): void
    {
        $this->mailer->subject('Welcome to Stride!');
        $this->assertSame('Welcome to Stride!', $this->mailer->toArray()['subject']);
    }

    // ---------------------------------------------------------------------
    // from() sanitizes (item 1)
    // ---------------------------------------------------------------------

    public function testFromStripsCrlfFromName(): void
    {
        $this->mailer->from('sender@example.com', "Name\r\nMalicious-Header: x");
        $array = $this->mailer->toArray();
        $this->assertStringNotContainsString("\r", $array['from_name']);
        $this->assertStringNotContainsString("\n", $array['from_name']);
        $this->assertStringContainsString('Name', $array['from_name']);
    }

    public function testFromDefaultsNameToEmailWhenNameEmpty(): void
    {
        $this->mailer->from('sender@example.com', '');
        $this->assertSame('sender@example.com', $this->mailer->toArray()['from_name']);
    }

    // ---------------------------------------------------------------------
    // header() strips CRLF (item 2)
    // ---------------------------------------------------------------------

    public function testHeaderStripsCrlfFromValue(): void
    {
        // The attack is splitting one header into two via CRLF. After
        // stripping CRLF, the injection chars collapse into a single header
        // value — no new header line is created.
        $this->mailer->header('X-Custom', "value\r\nBcc: attacker@evil.com");

        $headers = $this->callPrivate('buildHeaders');
        $custom = array_values(array_filter($headers, fn($h) => str_starts_with($h, 'X-Custom:')));
        $this->assertCount(1, $custom, 'only one header line, not two');
        $this->assertStringNotContainsString("\r", $custom[0]);
        $this->assertStringNotContainsString("\n", $custom[0]);
    }

    public function testHeaderRejectsEmptyNameAfterStripping(): void
    {
        // A name made entirely of stripped chars ('::') becomes empty → no header added
        $before = count($this->callPrivate('buildHeaders'));
        $this->mailer->header(':::', 'value');
        $after = count($this->callPrivate('buildHeaders'));
        $this->assertSame($before, $after, 'empty header name must be silently dropped');
    }

    public function testHeaderAcceptsCleanInput(): void
    {
        $this->mailer->header('X-Custom', 'safe-value');
        $headers = $this->callPrivate('buildHeaders');
        $this->assertContains('X-Custom: safe-value', $headers);
    }

    // ---------------------------------------------------------------------
    // attach() refuses files outside allowed bases (item 4)
    // ---------------------------------------------------------------------

    public function testAttachAcceptsFileInsideAllowedBase(): void
    {
        $this->mailer->attach($this->insideFile);
        // Use toArray + reflection on attachments since toArray doesn't expose them
        $ref = new \ReflectionProperty($this->mailer, 'attachments');
        $ref->setAccessible(true);
        $this->assertSame([$this->insideFile], $ref->getValue($this->mailer));
    }

    public function testAttachRejectsFileOutsideAllowedBase(): void
    {
        $this->mailer->attach($this->outsideFile);
        $ref = new \ReflectionProperty($this->mailer, 'attachments');
        $ref->setAccessible(true);
        $this->assertSame([], $ref->getValue($this->mailer));
    }

    public function testAttachSilentlySkipsNonExistentFile(): void
    {
        $this->mailer->attach('/does/not/exist.pdf');
        $ref = new \ReflectionProperty($this->mailer, 'attachments');
        $ref->setAccessible(true);
        $this->assertSame([], $ref->getValue($this->mailer));
    }

    // ---------------------------------------------------------------------
    // getDefaultTemplate() escapes substitutions (item 6)
    // ---------------------------------------------------------------------

    public function testDefaultTemplateEscapesScalarValues(): void
    {
        $this->mailer->message('Hello {{name}}!');
        $output = $this->callPrivate('getDefaultTemplate', [['name' => '<script>alert(1)</script>']]);
        $this->assertStringNotContainsString('<script>alert', $output);
        $this->assertStringContainsString('&lt;script&gt;', $output);
    }

    public function testDefaultTemplateLeavesNonScalarValuesAlone(): void
    {
        $this->mailer->message('Hello {{name}}!');
        $output = $this->callPrivate('getDefaultTemplate', [['name' => ['array']]]);
        // Non-scalar values are NOT substituted; placeholder remains in output
        $this->assertStringContainsString('{{name}}', $output);
    }

    // ---------------------------------------------------------------------
    // helpers
    // ---------------------------------------------------------------------

    private function callPrivate(string $method, array $args = [])
    {
        $ref = new ReflectionMethod($this->mailer, $method);
        $ref->setAccessible(true);
        return $ref->invokeArgs($this->mailer, $args);
    }
}
