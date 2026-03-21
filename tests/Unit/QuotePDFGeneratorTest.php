<?php

declare(strict_types=1);

namespace Stride\Tests\Unit;

use Stride\Tests\TestCase;
use Stride\Modules\Invoicing\QuotePDFGenerator;

class QuotePDFGeneratorTest extends TestCase
{
    private QuotePDFGenerator $generator;

    protected function setUp(): void
    {
        parent::setUp();
        $mockQuoteService = $this->createMock(\Stride\Modules\Invoicing\QuoteService::class);
        $this->generator = new QuotePDFGenerator($mockQuoteService);
    }

    /** @test */
    public function testEnrichQuoteFormatsMoneyValues(): void
    {
        $quote = [
            'id' => 1,
            'quote_number' => 'OFF-2026-0001',
            'subtotal' => 10000,
            'discount' => 0,
            'tax' => 2100,
            'total' => 12100,
            'items' => [],
            'billing' => [],
            'user_id' => 1,
            'valid_until' => '2026-04-18',
            'post_date' => '2026-03-18',
        ];

        $enriched = $this->invokeMethod($this->generator, 'enrichQuoteForTemplate', [$quote]);

        $this->assertEquals('€ 100,00', $enriched['subtotal_formatted']);
        $this->assertEquals('€ 21,00', $enriched['tax_formatted']);
        $this->assertEquals('€ 121,00', $enriched['total_formatted']);
        $this->assertEquals(21, $enriched['tax_rate']);
    }

    /** @test */
    public function testEnrichQuoteIncludesCompanyDetails(): void
    {
        update_option('stride_company_details', [
            'name' => 'Test BV',
            'vat' => 'BE0123456789',
        ]);

        $quote = [
            'id' => 1,
            'quote_number' => 'OFF-2026-0001',
            'subtotal' => 0,
            'discount' => 0,
            'tax' => 0,
            'total' => 0,
            'items' => [],
            'billing' => [],
            'user_id' => 1,
            'valid_until' => '2026-04-18',
            'post_date' => '2026-03-18',
        ];

        $enriched = $this->invokeMethod($this->generator, 'enrichQuoteForTemplate', [$quote]);

        $this->assertEquals('Test BV', $enriched['company']['name']);
        $this->assertEquals('BE0123456789', $enriched['company']['vat']);
    }

    /** @test */
    public function testEnrichQuoteIncludesUserData(): void
    {
        $this->createUser([
            'ID' => 42,
            'first_name' => 'Jan',
            'last_name' => 'Janssen',
            'user_email' => 'jan@test.be',
            'display_name' => 'Jan Janssen',
        ]);

        $quote = [
            'id' => 1,
            'quote_number' => 'OFF-2026-0001',
            'subtotal' => 0,
            'discount' => 0,
            'tax' => 0,
            'total' => 0,
            'items' => [],
            'billing' => [],
            'user_id' => 42,
            'valid_until' => '2026-04-18',
            'post_date' => '2026-03-18',
        ];

        $enriched = $this->invokeMethod($this->generator, 'enrichQuoteForTemplate', [$quote]);

        $this->assertEquals('Jan Janssen', $enriched['user']['name']);
        $this->assertEquals('jan@test.be', $enriched['user']['email']);
    }

    /** @test */
    public function testEnrichQuoteDecodesBillingJson(): void
    {
        $billing = ['organisation' => 'ACME', 'vat_number' => 'BE999'];

        $quote = [
            'id' => 1,
            'quote_number' => 'OFF-2026-0001',
            'subtotal' => 0,
            'discount' => 0,
            'tax' => 0,
            'total' => 0,
            'items' => [],
            'billing' => json_encode($billing),
            'user_id' => 1,
            'valid_until' => '2026-04-18',
            'post_date' => '2026-03-18',
        ];

        $enriched = $this->invokeMethod($this->generator, 'enrichQuoteForTemplate', [$quote]);

        $this->assertEquals('ACME', $enriched['billing']['organisation']);
        $this->assertEquals('BE999', $enriched['billing']['vat_number']);
    }

    /** @test */
    public function testEnrichQuoteDecodesItemsJson(): void
    {
        $items = [
            ['title' => 'Course A', 'quantity' => 1, 'unit_price' => 5000, 'total' => 5000],
        ];

        $quote = [
            'id' => 1,
            'quote_number' => 'OFF-2026-0001',
            'subtotal' => 5000,
            'discount' => 0,
            'tax' => 1050,
            'total' => 6050,
            'items' => json_encode($items),
            'billing' => [],
            'user_id' => 1,
            'valid_until' => '2026-04-18',
            'post_date' => '2026-03-18',
        ];

        $enriched = $this->invokeMethod($this->generator, 'enrichQuoteForTemplate', [$quote]);

        $this->assertCount(1, $enriched['items']);
        $this->assertEquals('Course A', $enriched['items'][0]['title']);
    }

    /** @test */
    public function testGetStoragePathReturnsCorrectPath(): void
    {
        $path = $this->invokeMethod($this->generator, 'getStoragePath', ['OFF-2026-0001']);

        $this->assertStringContainsString('stride-quotes', $path);
        $this->assertStringEndsWith('OFF-2026-0001.pdf', $path);
    }

    /** @test */
    public function testGetRelativePathStripsContentDir(): void
    {
        $fullPath = WP_CONTENT_DIR . '/uploads/stride-quotes/OFF-2026-0001.pdf';
        $relative = $this->invokeMethod($this->generator, 'getRelativePath', [$fullPath]);

        $this->assertEquals('uploads/stride-quotes/OFF-2026-0001.pdf', $relative);
    }

    /** @test */
    public function testGenerateReturnsWpErrorWhenQuoteServiceNotAvailable(): void
    {
        $result = $this->generator->generate(999);

        $this->assertInstanceOf(\WP_Error::class, $result);
    }

    /** @test */
    public function testEnrichQuoteIncludesDiscountFormatted(): void
    {
        $quote = [
            'id' => 1,
            'quote_number' => 'OFF-2026-0001',
            'subtotal' => 10000,
            'discount' => 2500,
            'tax' => 1575,
            'total' => 9075,
            'items' => [],
            'billing' => [],
            'user_id' => 1,
            'valid_until' => '2026-04-18',
            'post_date' => '2026-03-18',
        ];

        $enriched = $this->invokeMethod($this->generator, 'enrichQuoteForTemplate', [$quote]);

        $this->assertEquals('€ 25,00', $enriched['discount_formatted']);
    }

    /**
     * Helper to invoke private/protected methods for testing.
     */
    private function invokeMethod(object $object, string $method, array $args = []): mixed
    {
        $reflection = new \ReflectionMethod($object, $method);
        $reflection->setAccessible(true);
        return $reflection->invoke($object, ...$args);
    }
}
