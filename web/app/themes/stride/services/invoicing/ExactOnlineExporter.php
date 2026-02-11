<?php

namespace stride\services\invoicing;

defined('ABSPATH') || exit;

use WP_Error;

/**
 * Exact Online Exporter
 *
 * Exports quotes to CSV format for import into Exact Online.
 * The exact format is configurable and can be adjusted based on accounting requirements.
 *
 * @package stride\services\invoicing
 */
class ExactOnlineExporter
{
    private ?QuoteService $quoteService;

    /**
     * Constructor
     */
    public function __construct(?QuoteService $quoteService = null)
    {
        $this->quoteService = $quoteService ?? $this->resolveService(QuoteService::class);
    }

    /**
     * Resolve service from DI container or create new instance
     */
    private function resolveService(string $class): object
    {
        if (function_exists('ntdst_get')) {
            try {
                $service = ntdst_get($class);
                if ($service instanceof $class) {
                    return $service;
                }
            } catch (\Exception $e) {
                // Fall through to create new instance
            }
        }
        return new $class();
    }

    /**
     * Required capability for export operations
     */
    private const REQUIRED_CAPABILITY = 'manage_options';

    /**
     * Chunk size for processing large batches
     */
    private const CHUNK_SIZE = 100;

    /**
     * Export quotes to CSV for Exact Online import
     *
     * @param array $quoteIds Array of quote post IDs to export
     * @param bool $markExported Mark quotes as exported after generating CSV
     * @return string|WP_Error CSV content or error
     */
    public function exportBatch(array $quoteIds, bool $markExported = true): string|WP_Error
    {
        // Authorization check
        if (!current_user_can(self::REQUIRED_CAPABILITY)) {
            return new WP_Error('unauthorized', __('Onvoldoende rechten voor export.', 'stride'));
        }

        if (empty($quoteIds)) {
            return new WP_Error('no_quotes', __('Geen offertes geselecteerd voor export.', 'stride'));
        }

        // Use streaming CSV generation for memory efficiency
        $stream = fopen('php://temp', 'r+');
        $columns = $this->getColumnConfig();

        // Write header row
        fputcsv($stream, array_keys($columns), ';');

        // Process in chunks to prevent memory exhaustion
        $processedIds = [];
        foreach (array_chunk($quoteIds, self::CHUNK_SIZE) as $chunk) {
            foreach ($chunk as $quoteId) {
                $quote = $this->quoteService->getQuote((int) $quoteId);
                if ($quote) {
                    $row = $this->buildRow($quote, $columns);
                    fputcsv($stream, $row, ';');
                    $processedIds[] = $quote['id'];
                }
            }
        }

        if (empty($processedIds)) {
            fclose($stream);
            return new WP_Error('no_valid_quotes', __('Geen geldige offertes gevonden.', 'stride'));
        }

        // Mark as exported (also in chunks)
        if ($markExported) {
            foreach (array_chunk($processedIds, self::CHUNK_SIZE) as $chunk) {
                foreach ($chunk as $quoteId) {
                    $this->quoteService->exportQuote($quoteId);
                }
            }
        }

        rewind($stream);
        $content = stream_get_contents($stream);
        fclose($stream);

        return $content;
    }

    /**
     * Export all sent quotes (not yet exported)
     *
     * @param bool $markExported Mark quotes as exported after generating CSV
     * @return string|WP_Error CSV content or error
     */
    public function exportPending(bool $markExported = true): string|WP_Error
    {
        // Authorization check
        if (!current_user_can(self::REQUIRED_CAPABILITY)) {
            return new WP_Error('unauthorized', __('Onvoldoende rechten voor export.', 'stride'));
        }

        $quotes = $this->quoteService->getQuotesByStatus(QuoteService::STATUS_SENT);

        if (empty($quotes)) {
            return new WP_Error('no_pending', __('Geen offertes om te exporteren.', 'stride'));
        }

        $quoteIds = array_map(fn($q) => $q['id'], $quotes);
        return $this->exportBatch($quoteIds, $markExported);
    }

    /**
     * Get column configuration
     *
     * This defines the CSV structure. Can be overridden via filter.
     *
     * @return array Column name => callback or field path
     */
    private function getColumnConfig(): array
    {
        $columns = [
            'InvoiceNumber' => fn($q) => $q['number'],
            'InvoiceDate' => fn($q) => $this->formatDate($q['created_at']),
            'DueDate' => fn($q) => $this->formatDate($q['valid_until']),
            'CustomerCode' => fn($q) => $this->getCustomerCode($q),
            'CustomerName' => fn($q) => $this->getCustomerName($q),
            'CustomerEmail' => fn($q) => $q['billing']['email'] ?? '',
            'CustomerVAT' => fn($q) => $q['billing']['vat_number'] ?? '',
            'CustomerAddress' => fn($q) => $q['billing']['address'] ?? '',
            'CustomerCity' => fn($q) => $q['billing']['city'] ?? '',
            'CustomerPostalCode' => fn($q) => $q['billing']['postal_code'] ?? '',
            'GLN' => fn($q) => $q['billing']['gln_number'] ?? '',
            'OrderNumber' => fn($q) => $q['order_number'] ?? '',
            'Description' => fn($q) => $this->getDescription($q),
            'Amount' => fn($q) => $this->formatAmount($q['subtotal']),
            'TaxRate' => fn($q) => $this->getTaxRate(),
            'TaxAmount' => fn($q) => $this->formatAmount($q['tax']),
            'TotalAmount' => fn($q) => $this->formatAmount($q['total']),
            'Currency' => fn($q) => $this->getCurrency(),
            'VoucherCode' => fn($q) => $q['voucher_code'] ?? '',
        ];

        // Allow customization via filter
        return apply_filters('stride/export/exact_columns', $columns);
    }

    /**
     * Build a row of data for a quote
     *
     * @param array $quote Quote data
     * @param array $columns Column configuration
     * @return array Row data
     */
    private function buildRow(array $quote, array $columns): array
    {
        $row = [];

        foreach ($columns as $name => $getter) {
            if (is_callable($getter)) {
                $row[] = $getter($quote);
            } elseif (is_string($getter)) {
                // Support dot notation for nested fields
                $row[] = $this->getNestedValue($quote, $getter);
            } else {
                $row[] = '';
            }
        }

        return $row;
    }

    /**
     * Get nested value from array using dot notation
     *
     * @param array $data Data array
     * @param string $path Dot-notation path
     * @return mixed Value at path
     */
    private function getNestedValue(array $data, string $path): mixed
    {
        $keys = explode('.', $path);
        $value = $data;

        foreach ($keys as $key) {
            if (!is_array($value) || !isset($value[$key])) {
                return '';
            }
            $value = $value[$key];
        }

        return $value;
    }

    /**
     * Generate customer code
     *
     * @param array $quote Quote data
     * @return string Customer code
     */
    private function getCustomerCode(array $quote): string
    {
        // Use VAT number if available, otherwise user ID
        if (!empty($quote['billing']['vat_number'])) {
            return preg_replace('/[^A-Z0-9]/', '', $quote['billing']['vat_number']);
        }

        return 'USER' . str_pad($quote['user_id'], 6, '0', STR_PAD_LEFT);
    }

    /**
     * Get customer name
     *
     * @param array $quote Quote data
     * @return string Customer name
     */
    private function getCustomerName(array $quote): string
    {
        // Prefer organisation, fall back to user name
        if (!empty($quote['billing']['organisation'])) {
            return $quote['billing']['organisation'];
        }

        $user = get_userdata($quote['user_id']);
        if ($user) {
            return trim($user->first_name . ' ' . $user->last_name);
        }

        return '';
    }

    /**
     * Get invoice description
     *
     * @param array $quote Quote data
     * @return string Description
     */
    private function getDescription(array $quote): string
    {
        $items = $quote['items'] ?? [];
        $descriptions = array_map(fn($item) => $item['title'] ?? '', $items);

        return implode(', ', array_filter($descriptions));
    }

    /**
     * Format date for CSV
     *
     * @param string|null $date Date string
     * @return string Formatted date (YYYY-MM-DD)
     */
    private function formatDate(?string $date): string
    {
        if (!$date) {
            return '';
        }

        $timestamp = strtotime($date);
        return $timestamp ? date('Y-m-d', $timestamp) : '';
    }

    /**
     * Format amount for CSV
     *
     * Uses dot as decimal separator for international compatibility
     *
     * @param float $amount Amount
     * @return string Formatted amount
     */
    private function formatAmount(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }

    /**
     * Get tax rate from config
     *
     * @return string Tax rate
     */
    private function getTaxRate(): string
    {
        return (string) $this->getConfig('tax_rate', 21);
    }

    /**
     * Get currency from config
     *
     * @return string Currency code
     */
    private function getCurrency(): string
    {
        return $this->getConfig('currency', 'EUR');
    }

    /**
     * Get config value from theme-config.php
     *
     * @param string $key Config key
     * @param mixed $default Default value
     * @return mixed
     */
    private function getConfig(string $key, mixed $default = null): mixed
    {
        static $config = null;

        if ($config === null) {
            $configPath = get_stylesheet_directory() . '/theme-config.php';
            if (file_exists($configPath)) {
                $config = include $configPath;
            } else {
                $config = [];
            }
        }

        return $config['modules']['invoicing'][$key] ?? $default;
    }

    /**
     * Get filename for export
     *
     * @return string Filename
     */
    public function getExportFilename(): string
    {
        return sprintf('exact-export-%s.csv', date('Y-m-d-His'));
    }

    /**
     * Download CSV as file response
     *
     * @param string $content CSV content
     * @param string|null $filename Optional filename
     */
    public function downloadCsv(string $content, ?string $filename = null): void
    {
        // Authorization check
        if (!current_user_can(self::REQUIRED_CAPABILITY)) {
            wp_die(__('Onvoldoende rechten voor export.', 'stride'), 403);
        }

        $filename = $filename ?? $this->getExportFilename();

        // Sanitize filename to prevent header injection
        $safeFilename = preg_replace('/[^a-zA-Z0-9._-]/', '', basename($filename));
        if (empty($safeFilename)) {
            $safeFilename = 'export.csv';
        }

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $safeFilename . '"');
        header('Content-Length: ' . strlen($content));
        header('Cache-Control: private, max-age=0, must-revalidate');
        header('Pragma: public');

        // Add BOM for Excel UTF-8 compatibility
        echo "\xEF\xBB\xBF";
        echo $content;
        exit;
    }

    /**
     * Get summary of quotes available for export
     *
     * @return array|WP_Error Summary with counts by status or error
     */
    public function getExportSummary(): array|WP_Error
    {
        // Authorization check
        if (!current_user_can(self::REQUIRED_CAPABILITY)) {
            return new WP_Error('unauthorized', __('Onvoldoende rechten.', 'stride'));
        }

        $sent = $this->quoteService->getQuotesByStatus(QuoteService::STATUS_SENT);
        $exported = $this->quoteService->getQuotesByStatus(QuoteService::STATUS_EXPORTED);

        return [
            'pending_count' => count($sent),
            'pending_total' => array_sum(array_map(fn($q) => $q['total'], $sent)),
            'exported_count' => count($exported),
            'exported_total' => array_sum(array_map(fn($q) => $q['total'], $exported)),
        ];
    }
}
