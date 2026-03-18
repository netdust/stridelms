<?php

declare(strict_types=1);

namespace Tests\Unit\NetdustMail;

use Netdust\Mail\AttachmentHandler;
use PHPUnit\Framework\TestCase;

class AttachmentHandlerTest extends TestCase
{
    private AttachmentHandler $handler;

    /**
     * Temporary files created during tests.
     */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Reset global state
        global $_test_attached_files, $_test_filters;
        $_test_attached_files = [];
        $_test_filters = [];

        $this->handler = new AttachmentHandler();
    }

    protected function tearDown(): void
    {
        // Clean up temp files
        foreach ($this->tempFiles as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }
        $this->tempFiles = [];

        // Reset global state
        global $_test_attached_files, $_test_filters;
        $_test_attached_files = [];
        $_test_filters = [];

        parent::tearDown();
    }

    /**
     * Create a temporary file for testing.
     */
    private function createTempFile(string $content = 'test'): string
    {
        $path = sys_get_temp_dir() . '/test_attachment_' . uniqid() . '.txt';
        file_put_contents($path, $content);
        $this->tempFiles[] = $path;
        return $path;
    }

    // =========================================================================
    // resolve() - Empty input tests
    // =========================================================================

    public function test_resolve_returns_empty_array_for_empty_input(): void
    {
        $result = $this->handler->resolve([], []);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function test_resolve_returns_empty_array_for_null_equivalent(): void
    {
        $result = $this->handler->resolve([], ['user_id' => 123]);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    // =========================================================================
    // resolve() - Media attachment tests
    // =========================================================================

    public function test_resolve_returns_wp_error_for_media_missing_id(): void
    {
        $attachments = [
            ['type' => 'media'],
        ];

        $result = $this->handler->resolve($attachments, []);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertEquals('ndmail_invalid_attachment', $result->get_error_code());
        $this->assertStringContainsString('missing ID', $result->get_error_message());
    }

    public function test_resolve_returns_wp_error_for_missing_media_file(): void
    {
        global $_test_attached_files;
        $_test_attached_files[456] = '/nonexistent/path/to/file.pdf';

        $attachments = [
            ['type' => 'media', 'id' => 456],
        ];

        $result = $this->handler->resolve($attachments, []);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertEquals('ndmail_media_not_found', $result->get_error_code());
        $this->assertStringContainsString('456', $result->get_error_message());
    }

    public function test_resolve_returns_wp_error_when_get_attached_file_returns_false(): void
    {
        // Don't set the attachment - get_attached_file will return false
        $attachments = [
            ['type' => 'media', 'id' => 999],
        ];

        $result = $this->handler->resolve($attachments, []);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertEquals('ndmail_media_not_found', $result->get_error_code());
    }

    public function test_resolve_returns_file_paths_for_valid_media(): void
    {
        global $_test_attached_files;

        $tempFile = $this->createTempFile('PDF content here');
        $_test_attached_files[456] = $tempFile;

        $attachments = [
            ['type' => 'media', 'id' => 456],
        ];

        $result = $this->handler->resolve($attachments, []);

        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        $this->assertEquals($tempFile, $result[0]);
    }

    public function test_resolve_handles_multiple_media_attachments(): void
    {
        global $_test_attached_files;

        $tempFile1 = $this->createTempFile('File 1');
        $tempFile2 = $this->createTempFile('File 2');

        $_test_attached_files[100] = $tempFile1;
        $_test_attached_files[200] = $tempFile2;

        $attachments = [
            ['type' => 'media', 'id' => 100],
            ['type' => 'media', 'id' => 200],
        ];

        $result = $this->handler->resolve($attachments, []);

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        $this->assertEquals($tempFile1, $result[0]);
        $this->assertEquals($tempFile2, $result[1]);
    }

    // =========================================================================
    // resolve() - PDF attachment tests
    // =========================================================================

    public function test_resolve_returns_wp_error_for_pdf_missing_generator(): void
    {
        $attachments = [
            ['type' => 'pdf'],
        ];

        $result = $this->handler->resolve($attachments, []);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertEquals('ndmail_invalid_attachment', $result->get_error_code());
        $this->assertStringContainsString('missing generator', $result->get_error_message());
    }

    public function test_resolve_returns_wp_error_for_unknown_pdf_generator(): void
    {
        $attachments = [
            ['type' => 'pdf', 'generator' => 'unknown_generator'],
        ];

        $result = $this->handler->resolve($attachments, []);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertEquals('ndmail_unknown_generator', $result->get_error_code());
        $this->assertStringContainsString('unknown_generator', $result->get_error_message());
    }

    public function test_resolve_returns_wp_error_for_missing_pdf_context(): void
    {
        $tempFile = $this->createTempFile('PDF');

        add_filter('ndmail_pdf_generators', function ($generators) use ($tempFile) {
            $generators['stride_quote'] = [
                'label' => 'Quote PDF',
                'callback' => fn($id) => $tempFile,
                'context_key' => 'quote_id',
            ];
            return $generators;
        });

        // Need to create a new handler to pick up the filter
        $handler = new AttachmentHandler();

        $attachments = [
            ['type' => 'pdf', 'generator' => 'stride_quote'],
        ];

        // Missing quote_id in context
        $result = $handler->resolve($attachments, ['user_id' => 123]);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertEquals('ndmail_missing_pdf_context', $result->get_error_code());
        $this->assertStringContainsString('quote_id', $result->get_error_message());
    }

    public function test_resolve_returns_file_paths_for_valid_pdf_generator(): void
    {
        $tempFile = $this->createTempFile('PDF content');

        add_filter('ndmail_pdf_generators', function ($generators) use ($tempFile) {
            $generators['stride_quote'] = [
                'label' => 'Quote PDF',
                'callback' => fn($id) => $tempFile,
                'context_key' => 'quote_id',
            ];
            return $generators;
        });

        $handler = new AttachmentHandler();

        $attachments = [
            ['type' => 'pdf', 'generator' => 'stride_quote'],
        ];

        $result = $handler->resolve($attachments, ['quote_id' => 123]);

        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        $this->assertEquals($tempFile, $result[0]);
    }

    public function test_resolve_passes_context_id_to_pdf_generator(): void
    {
        $tempFile = $this->createTempFile('PDF');
        $receivedId = null;

        add_filter('ndmail_pdf_generators', function ($generators) use ($tempFile, &$receivedId) {
            $generators['stride_quote'] = [
                'label' => 'Quote PDF',
                'callback' => function ($id) use ($tempFile, &$receivedId) {
                    $receivedId = $id;
                    return $tempFile;
                },
                'context_key' => 'quote_id',
            ];
            return $generators;
        });

        $handler = new AttachmentHandler();

        $attachments = [
            ['type' => 'pdf', 'generator' => 'stride_quote'],
        ];

        $handler->resolve($attachments, ['quote_id' => 456]);

        $this->assertEquals(456, $receivedId);
    }

    public function test_resolve_returns_wp_error_when_pdf_generator_returns_invalid_path(): void
    {
        add_filter('ndmail_pdf_generators', function ($generators) {
            $generators['broken'] = [
                'label' => 'Broken Generator',
                'callback' => fn($id) => '/nonexistent/file.pdf',
                'context_key' => 'id',
            ];
            return $generators;
        });

        $handler = new AttachmentHandler();

        $attachments = [
            ['type' => 'pdf', 'generator' => 'broken'],
        ];

        $result = $handler->resolve($attachments, ['id' => 1]);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertEquals('ndmail_pdf_generation_failed', $result->get_error_code());
    }

    public function test_resolve_returns_wp_error_when_pdf_generator_throws_exception(): void
    {
        add_filter('ndmail_pdf_generators', function ($generators) {
            $generators['throws'] = [
                'label' => 'Throws Exception',
                'callback' => function ($id) {
                    throw new \RuntimeException('PDF generation failed');
                },
                'context_key' => 'id',
            ];
            return $generators;
        });

        $handler = new AttachmentHandler();

        $attachments = [
            ['type' => 'pdf', 'generator' => 'throws'],
        ];

        $result = $handler->resolve($attachments, ['id' => 1]);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertEquals('ndmail_pdf_generation_error', $result->get_error_code());
        $this->assertStringContainsString('PDF generation failed', $result->get_error_message());
    }

    public function test_resolve_returns_wp_error_for_invalid_callback(): void
    {
        add_filter('ndmail_pdf_generators', function ($generators) {
            $generators['invalid'] = [
                'label' => 'Invalid Callback',
                'callback' => 'not_a_callable',
                'context_key' => 'id',
            ];
            return $generators;
        });

        $handler = new AttachmentHandler();

        $attachments = [
            ['type' => 'pdf', 'generator' => 'invalid'],
        ];

        $result = $handler->resolve($attachments, ['id' => 1]);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertEquals('ndmail_invalid_generator', $result->get_error_code());
    }

    public function test_resolve_handles_pdf_generator_without_context_key(): void
    {
        $tempFile = $this->createTempFile('PDF');

        add_filter('ndmail_pdf_generators', function ($generators) use ($tempFile) {
            $generators['no_context'] = [
                'label' => 'No Context Required',
                'callback' => fn($id) => $tempFile,
                // No context_key specified
            ];
            return $generators;
        });

        $handler = new AttachmentHandler();

        $attachments = [
            ['type' => 'pdf', 'generator' => 'no_context'],
        ];

        $result = $handler->resolve($attachments, []);

        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        $this->assertEquals($tempFile, $result[0]);
    }

    // =========================================================================
    // resolve() - Mixed attachment types
    // =========================================================================

    public function test_resolve_handles_mixed_attachment_types(): void
    {
        global $_test_attached_files;

        $mediaFile = $this->createTempFile('Media content');
        $pdfFile = $this->createTempFile('PDF content');

        $_test_attached_files[100] = $mediaFile;

        add_filter('ndmail_pdf_generators', function ($generators) use ($pdfFile) {
            $generators['test_pdf'] = [
                'label' => 'Test PDF',
                'callback' => fn($id) => $pdfFile,
                'context_key' => 'doc_id',
            ];
            return $generators;
        });

        $handler = new AttachmentHandler();

        $attachments = [
            ['type' => 'media', 'id' => 100],
            ['type' => 'pdf', 'generator' => 'test_pdf'],
        ];

        $result = $handler->resolve($attachments, ['doc_id' => 1]);

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        $this->assertEquals($mediaFile, $result[0]);
        $this->assertEquals($pdfFile, $result[1]);
    }

    public function test_resolve_skips_unknown_attachment_types(): void
    {
        global $_test_attached_files;

        $tempFile = $this->createTempFile('Content');
        $_test_attached_files[100] = $tempFile;

        $attachments = [
            ['type' => 'media', 'id' => 100],
            ['type' => 'unknown', 'data' => 'ignored'],
            ['type' => 'another_unknown'],
        ];

        $result = $this->handler->resolve($attachments, []);

        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        $this->assertEquals($tempFile, $result[0]);
    }

    public function test_resolve_stops_on_first_error(): void
    {
        global $_test_attached_files;

        // First attachment is valid
        $tempFile = $this->createTempFile('Valid');
        $_test_attached_files[100] = $tempFile;

        // Second will fail (no attached file set for ID 999)

        $attachments = [
            ['type' => 'media', 'id' => 100],
            ['type' => 'media', 'id' => 999], // This will fail
            ['type' => 'media', 'id' => 888], // This won't be reached
        ];

        $result = $this->handler->resolve($attachments, []);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertEquals('ndmail_media_not_found', $result->get_error_code());
    }

    // =========================================================================
    // getAvailableGenerators() tests
    // =========================================================================

    public function test_get_available_generators_returns_empty_array_when_no_generators(): void
    {
        $result = $this->handler->getAvailableGenerators();

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function test_get_available_generators_returns_generator_info(): void
    {
        add_filter('ndmail_pdf_generators', function ($generators) {
            $generators['stride_quote'] = [
                'label' => 'Quote PDF',
                'callback' => fn($id) => '/path/to/pdf',
                'context_key' => 'quote_id',
            ];
            $generators['stride_certificate'] = [
                'label' => 'Certificate PDF',
                'callback' => fn($id) => '/path/to/cert',
                'context_key' => 'cert_id',
            ];
            return $generators;
        });

        $handler = new AttachmentHandler();
        $result = $handler->getAvailableGenerators();

        $this->assertIsArray($result);
        $this->assertCount(2, $result);

        $this->assertArrayHasKey('stride_quote', $result);
        $this->assertEquals('Quote PDF', $result['stride_quote']['label']);
        $this->assertEquals('quote_id', $result['stride_quote']['context_key']);

        $this->assertArrayHasKey('stride_certificate', $result);
        $this->assertEquals('Certificate PDF', $result['stride_certificate']['label']);
        $this->assertEquals('cert_id', $result['stride_certificate']['context_key']);
    }

    public function test_get_available_generators_uses_key_as_label_fallback(): void
    {
        add_filter('ndmail_pdf_generators', function ($generators) {
            $generators['my_generator'] = [
                // No label provided
                'callback' => fn($id) => '/path',
                'context_key' => 'id',
            ];
            return $generators;
        });

        $handler = new AttachmentHandler();
        $result = $handler->getAvailableGenerators();

        $this->assertEquals('my_generator', $result['my_generator']['label']);
    }

    public function test_get_available_generators_handles_missing_context_key(): void
    {
        add_filter('ndmail_pdf_generators', function ($generators) {
            $generators['simple'] = [
                'label' => 'Simple PDF',
                'callback' => fn($id) => '/path',
                // No context_key
            ];
            return $generators;
        });

        $handler = new AttachmentHandler();
        $result = $handler->getAvailableGenerators();

        $this->assertNull($result['simple']['context_key']);
    }

    // =========================================================================
    // Caching tests
    // =========================================================================

    public function test_pdf_generators_are_cached(): void
    {
        $callCount = 0;

        add_filter('ndmail_pdf_generators', function ($generators) use (&$callCount) {
            $callCount++;
            $generators['test'] = [
                'label' => 'Test',
                'callback' => fn($id) => '/path',
            ];
            return $generators;
        });

        $handler = new AttachmentHandler();

        // Call multiple times
        $handler->getAvailableGenerators();
        $handler->getAvailableGenerators();
        $handler->getAvailableGenerators();

        // Filter should only be called once due to caching
        $this->assertEquals(1, $callCount);
    }

    // =========================================================================
    // Class structure tests
    // =========================================================================

    public function test_class_has_correct_method_signatures(): void
    {
        $reflection = new \ReflectionClass(AttachmentHandler::class);

        // resolve method
        $method = $reflection->getMethod('resolve');
        $this->assertTrue($method->isPublic());
        $params = $method->getParameters();
        $this->assertCount(2, $params);
        $this->assertEquals('attachments', $params[0]->getName());
        $this->assertEquals('context', $params[1]->getName());

        // getAvailableGenerators method
        $method = $reflection->getMethod('getAvailableGenerators');
        $this->assertTrue($method->isPublic());
        $this->assertEquals('array', $method->getReturnType()->getName());
    }

    public function test_class_has_private_pdf_generators_property(): void
    {
        $reflection = new \ReflectionClass(AttachmentHandler::class);

        $this->assertTrue($reflection->hasProperty('pdfGenerators'));

        $property = $reflection->getProperty('pdfGenerators');
        $this->assertTrue($property->isPrivate());
    }

    public function test_class_has_private_helper_methods(): void
    {
        $reflection = new \ReflectionClass(AttachmentHandler::class);

        // resolveMedia
        $this->assertTrue($reflection->hasMethod('resolveMedia'));
        $method = $reflection->getMethod('resolveMedia');
        $this->assertTrue($method->isPrivate());

        // resolvePdf
        $this->assertTrue($reflection->hasMethod('resolvePdf'));
        $method = $reflection->getMethod('resolvePdf');
        $this->assertTrue($method->isPrivate());

        // getPdfGenerators
        $this->assertTrue($reflection->hasMethod('getPdfGenerators'));
        $method = $reflection->getMethod('getPdfGenerators');
        $this->assertTrue($method->isPrivate());
    }
}
