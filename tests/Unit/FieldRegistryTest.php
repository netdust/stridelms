<?php

namespace Stride\Tests\Unit;

use PHPUnit\Framework\TestCase;
use stride\services\FieldRegistry;

/**
 * Unit Test: FieldRegistry
 *
 * Verifies that the FieldRegistry constants and helper methods work correctly.
 */
class FieldRegistryTest extends TestCase
{
    /**
     * Test: Basic constants are defined
     */
    public function testConstantsAreDefined(): void
    {
        $this->assertNotEmpty(FieldRegistry::FIELD_EMAIL);
        $this->assertNotEmpty(FieldRegistry::FIELD_FIRST_NAME);
        $this->assertNotEmpty(FieldRegistry::FIELD_LAST_NAME);
        $this->assertNotEmpty(FieldRegistry::SUBSCRIBER_VAT_NUMBER);
    }

    /**
     * Test: Constants have expected values
     */
    public function testConstantValues(): void
    {
        $this->assertEquals('email', FieldRegistry::FIELD_EMAIL);
        $this->assertEquals('first_name', FieldRegistry::FIELD_FIRST_NAME);
        $this->assertEquals('last_name', FieldRegistry::FIELD_LAST_NAME);
        $this->assertEquals('vat_number', FieldRegistry::SUBSCRIBER_VAT_NUMBER);
    }

    /**
     * Test: Invoice field constants
     */
    public function testInvoiceFieldConstants(): void
    {
        $this->assertEquals('invoice_organization_name', FieldRegistry::SUBSCRIBER_INVOICE_ORG_NAME);
        $this->assertEquals('invoice_address', FieldRegistry::SUBSCRIBER_INVOICE_ADDRESS);
        $this->assertEquals('invoice_city', FieldRegistry::SUBSCRIBER_INVOICE_CITY);
        $this->assertEquals('invoice_postal_code', FieldRegistry::SUBSCRIBER_INVOICE_POSTAL_CODE);
        $this->assertEquals('invoice_email', FieldRegistry::SUBSCRIBER_INVOICE_EMAIL);
    }
}
