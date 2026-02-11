<?php

namespace stride\services\smartcode\providers;

defined('ABSPATH') || exit;

use stride\services\smartcode\contracts\SmartCodeProviderInterface;
use stride\services\smartcode\contracts\SmartCodeContextInterface;

/**
 * Invoice SmartCode Provider (Placeholder)
 *
 * Provides invoice data SmartCodes for FluentCRM and FluentForms.
 * This is a placeholder for future InvoiceService integration.
 *
 * Planned SmartCodes:
 * - stride_invoice.number
 * - stride_invoice.date
 * - stride_invoice.total
 * - stride_invoice.status
 * - stride_invoice.pdf_link
 *
 * @package stride\services\smartcode\providers
 */
class InvoiceSmartCodeProvider implements SmartCodeProviderInterface
{
    /**
     * Get the unique key for this provider
     *
     * @return string
     */
    public function getKey(): string
    {
        return 'stride_invoice';
    }

    /**
     * Get the display title for this provider
     *
     * @return string
     */
    public function getTitle(): string
    {
        return __('Stride Invoice', 'stride');
    }

    /**
     * Get available SmartCodes with labels
     *
     * @return array<string, string>
     */
    public function getShortCodes(): array
    {
        return [
            'number' => __('Invoice Number', 'stride'),
            'date' => __('Invoice Date', 'stride'),
            'total' => __('Invoice Total', 'stride'),
            'status' => __('Invoice Status', 'stride'),
            'pdf_link' => __('PDF Download Link', 'stride'),
        ];
    }

    /**
     * Get the value for a specific SmartCode
     *
     * @param string $valueKey
     * @param mixed $subscriber FluentCRM subscriber object or array
     * @param SmartCodeContextInterface $context
     * @return string|null
     */
    public function getValue(string $valueKey, mixed $subscriber, SmartCodeContextInterface $context): ?string
    {
        $invoiceId = $context->getInvoiceId();

        if (!$invoiceId) {
            return null;
        }

        // TODO: Implement when InvoiceService is created
        // For now, return null for all values
        return match ($valueKey) {
            'number' => $this->getNumber($invoiceId),
            'date' => $this->getDate($invoiceId),
            'total' => $this->getTotal($invoiceId),
            'status' => $this->getStatus($invoiceId),
            'pdf_link' => $this->getPdfLink($invoiceId),
            default => null,
        };
    }

    /**
     * Get invoice number
     *
     * @param int $invoiceId
     * @return string|null
     */
    private function getNumber(int $invoiceId): ?string
    {
        // TODO: Implement with InvoiceService
        return null;
    }

    /**
     * Get invoice date
     *
     * @param int $invoiceId
     * @return string|null
     */
    private function getDate(int $invoiceId): ?string
    {
        // TODO: Implement with InvoiceService
        return null;
    }

    /**
     * Get invoice total
     *
     * @param int $invoiceId
     * @return string|null
     */
    private function getTotal(int $invoiceId): ?string
    {
        // TODO: Implement with InvoiceService
        return null;
    }

    /**
     * Get invoice status
     *
     * @param int $invoiceId
     * @return string|null
     */
    private function getStatus(int $invoiceId): ?string
    {
        // TODO: Implement with InvoiceService
        return null;
    }

    /**
     * Get PDF download link
     *
     * @param int $invoiceId
     * @return string|null
     */
    private function getPdfLink(int $invoiceId): ?string
    {
        // TODO: Implement with InvoiceService
        return null;
    }
}
