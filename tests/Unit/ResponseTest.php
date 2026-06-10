<?php

declare(strict_types=1);

namespace Stride\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for NTDST_Response class
 *
 * Tests the download/inline methods and MIME type detection
 * without actually sending headers (pure logic tests).
 */
class ResponseTest extends TestCase
{
    /**
     * @test
     * @dataProvider mimeTypeProvider
     */
    public function testGetMimeTypeReturnsCorrectType(string $filename, string $expectedMime): void
    {
        $mime = \NTDST_Response::getMimeType($filename);
        $this->assertEquals($expectedMime, $mime);
    }

    public static function mimeTypeProvider(): array
    {
        return [
            // Documents
            ['invoice.pdf', 'application/pdf'],
            ['export.csv', 'text/csv; charset=utf-8'],
            ['data.json', 'application/json'],
            ['config.xml', 'application/xml'],
            ['readme.txt', 'text/plain; charset=utf-8'],

            // Calendar/Contact
            ['calendar.ics', 'text/calendar; charset=utf-8'],
            ['contact.vcf', 'text/vcard; charset=utf-8'],

            // Images
            ['photo.png', 'image/png'],
            ['photo.jpg', 'image/jpeg'],
            ['photo.jpeg', 'image/jpeg'],
            ['animation.gif', 'image/gif'],
            ['logo.svg', 'image/svg+xml'],
            ['modern.webp', 'image/webp'],

            // Archives
            ['archive.zip', 'application/zip'],
            ['compressed.gz', 'application/gzip'],

            // Office
            ['spreadsheet.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
            ['document.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],

            // Unknown/default
            ['unknown.xyz', 'application/octet-stream'],
            ['noextension', 'application/octet-stream'],
        ];
    }

    /**
     * @test
     */
    public function testGetMimeTypeIsCaseInsensitive(): void
    {
        $this->assertEquals('application/pdf', \NTDST_Response::getMimeType('FILE.PDF'));
        $this->assertEquals('image/jpeg', \NTDST_Response::getMimeType('Photo.JPG'));
        $this->assertEquals('text/calendar; charset=utf-8', \NTDST_Response::getMimeType('Calendar.ICS'));
    }

    /**
     * @test
     */
    public function testRegisterMimeTypeAddsCustomType(): void
    {
        // Register a custom MIME type
        \NTDST_Response::registerMimeType('custom', 'application/x-custom');

        $mime = \NTDST_Response::getMimeType('file.custom');
        $this->assertEquals('application/x-custom', $mime);
    }

    /**
     * @test
     */
    public function testRegisterMimeTypeOverridesExisting(): void
    {
        // Override existing type
        \NTDST_Response::registerMimeType('pdf', 'application/x-pdf-custom');

        $mime = \NTDST_Response::getMimeType('file.pdf');
        $this->assertEquals('application/x-pdf-custom', $mime);

        // Restore original for other tests
        \NTDST_Response::registerMimeType('pdf', 'application/pdf');
    }

    /**
     * File-download responses must carry the threat-model M4 headers:
     * Content-Type from the caller-supplied (validated) MIME,
     * Content-Disposition: attachment, and X-Content-Type-Options: nosniff
     * so an HTML/SVG body uploaded as "proof" can never be sniffed into
     * executing in the site origin.
     *
     * @test
     */
    public function testFileHeadersCarryDownloadSecurityHeaders(): void
    {
        $response = ntdst_response();
        $ref = new \ReflectionMethod($response, 'fileHeaders');
        $ref->setAccessible(true);

        $content = '%PDF-1.4 fake';
        $headers = $ref->invoke($response, $content, 'proof.pdf', 'application/pdf', 'attachment');

        $this->assertContains(
            'X-Content-Type-Options: nosniff',
            $headers,
            'Download responses must opt out of MIME sniffing (threat-model M4)',
        );
        $this->assertContains('Content-Type: application/pdf', $headers);
        $this->assertContains('Content-Length: ' . strlen($content), $headers);

        $disposition = array_values(array_filter(
            $headers,
            static fn(string $h): bool => str_starts_with($h, 'Content-Disposition:'),
        ));
        $this->assertCount(1, $disposition, 'Exactly one Content-Disposition header expected');
        $this->assertStringContainsString('attachment', $disposition[0]);
        $this->assertStringContainsString('filename="proof.pdf"', $disposition[0]);
    }

    /**
     * The stored validated MIME may be absent — headers must fall back to
     * filename-based detection, never to a sniffable empty Content-Type.
     *
     * @test
     */
    public function testFileHeadersFallBackToFilenameMimeDetection(): void
    {
        $response = ntdst_response();
        $ref = new \ReflectionMethod($response, 'fileHeaders');
        $ref->setAccessible(true);

        $headers = $ref->invoke($response, 'data', 'calendar.ics', null, 'attachment');

        $this->assertContains('Content-Type: text/calendar; charset=utf-8', $headers);
        $this->assertContains('X-Content-Type-Options: nosniff', $headers);
    }

    /**
     * @test
     */
    public function testResponseCanBeCreatedWithFactory(): void
    {
        $response = ntdst_response();

        $this->assertInstanceOf(\NTDST_Response::class, $response);
    }

    /**
     * @test
     */
    public function testResponseFluentInterface(): void
    {
        $response = ntdst_response()
            ->with('key1', 'value1')
            ->with('key2', 'value2')
            ->withData(['key3' => 'value3']);

        $this->assertInstanceOf(\NTDST_Response::class, $response);
    }

    /**
     * @test
     */
    public function testResponseReset(): void
    {
        $response = ntdst_response()
            ->with('key', 'value')
            ->error('Test error', 500)
            ->template('test/template');

        $response->reset();

        // After reset, template should be null
        $this->assertNull($response->getTemplate());
    }

    /**
     * @test
     */
    public function testResponseTemplate(): void
    {
        $response = ntdst_response()->template('dashboard/profile');

        $this->assertEquals('dashboard/profile', $response->getTemplate());
    }

    /**
     * @test
     */
    public function testResponseError(): void
    {
        $response = ntdst_response()->error('Something went wrong', 404);

        // Error state is internal, but we can verify template is null
        $this->assertNull($response->getTemplate());
    }
}
