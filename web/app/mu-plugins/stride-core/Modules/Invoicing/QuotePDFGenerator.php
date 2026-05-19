<?php

declare(strict_types=1);

namespace Stride\Modules\Invoicing;

use Dompdf\Dompdf;
use Dompdf\Options;
use Stride\Admin\StrideSettingsService;
use Stride\Domain\Money;
use WP_Error;

/**
 * Generates quote PDFs using DOMPDF.
 *
 * Hooks:
 * - stride/quote/regenerate_pdf (action) → regenerates PDF for a quote
 * - ndmail_pdf_generators (filter) → registers for email attachment
 */
final class QuotePDFGenerator
{
    private const UPLOAD_DIR = 'stride-quotes';

    public function __construct(
        private readonly QuoteService $quoteService,
        private readonly QuoteRepository $repository,
    ) {
        $this->registerHooks();
    }

    private function registerHooks(): void
    {
        add_action('stride/quote/regenerate_pdf', [$this, 'generate']);
        add_filter('ndmail_pdf_generators', [$this, 'registerMailGenerator']);
    }

    /**
     * Register as email PDF generator.
     */
    public function registerMailGenerator(array $generators): array
    {
        $generators['stride_quote'] = [
            'label'       => 'Offerte PDF',
            'callback'    => [$this, 'resolveForEmail'],
            'context_key' => 'quote_id',
        ];

        return $generators;
    }

    /**
     * Resolve PDF path for email attachment.
     *
     * Returns existing PDF path, or generates on-the-fly.
     * Returns empty string on failure (AttachmentHandler checks file_exists).
     */
    public function resolveForEmail(int $quoteId): string
    {
        $pdfPath = $this->repository->getField($quoteId, 'pdf_path');

        // If PDF exists on disk, return it
        if ($pdfPath) {
            $fullPath = WP_CONTENT_DIR . '/' . $pdfPath;
            if (file_exists($fullPath)) {
                return $fullPath;
            }
        }

        // Generate and return path (or empty string on failure)
        $result = $this->generate($quoteId);

        return is_wp_error($result) ? '' : $result;
    }

    /**
     * Generate (or regenerate) PDF for a quote.
     *
     * @return string|WP_Error Full path to generated PDF, or WP_Error on failure
     */
    public function generate(int $quoteId): string|WP_Error
    {
        $quote = $this->quoteService->getQuote($quoteId, true);

        if (is_wp_error($quote)) {
            ntdst_log('invoicing')->error('PDF generation failed: quote not found', [
                'quote_id' => $quoteId,
                'error' => $quote->get_error_message(),
            ]);
            return $quote;
        }

        $quoteNumber = $quote['quote_number'] ?? '';
        if (empty($quoteNumber)) {
            return new WP_Error('missing_number', 'Quote has no number');
        }

        // Enrich data for template
        $enriched = $this->enrichQuoteForTemplate($quote);

        // Render HTML
        $html = $this->renderTemplate($enriched);
        if ($html === false) {
            return new WP_Error('template_error', 'Could not render PDF template');
        }

        // Generate PDF
        $storagePath = $this->getStoragePath($quoteNumber);
        $result = $this->renderPDF($html, $storagePath);

        if (is_wp_error($result)) {
            return $result;
        }

        // Save relative path to quote meta
        $relativePath = $this->getRelativePath($storagePath);
        $this->repository->updateMeta($quoteId, ['pdf_path' => $relativePath]);

        ntdst_log('invoicing')->info('Quote PDF generated', [
            'quote_id' => $quoteId,
            'quote_number' => $quoteNumber,
            'path' => $relativePath,
        ]);

        return $storagePath;
    }

    /**
     * Enrich quote data with formatted values for the template.
     */
    private function enrichQuoteForTemplate(array $quote): array
    {
        // Decode JSON fields if stored as strings
        $billing = $quote['billing'] ?? [];
        if (is_string($billing)) {
            $billing = json_decode($billing, true) ?: [];
        }

        $items = $quote['items'] ?? [];
        if (is_string($items)) {
            $items = json_decode($items, true) ?: [];
        }

        // User data
        $userId = (int) ($quote['user_id'] ?? 0);
        $user = $userId ? get_userdata($userId) : null;

        // Format dates
        $createdDate = '';
        if (!empty($quote['post_date'])) {
            $createdDate = date_i18n('j F Y', strtotime($quote['post_date']));
        }

        $validUntilDate = '';
        if (!empty($quote['valid_until'])) {
            $validUntilDate = date_i18n('j F Y', strtotime($quote['valid_until']));
        }

        return [
            'id'                  => (int) ($quote['id'] ?? $quote['ID'] ?? 0),
            'number'              => $quote['quote_number'] ?? '',
            'status'              => $quote['status'] ?? 'draft',
            'user_id'             => $userId,
            'items'               => $items,
            'subtotal'            => (int) ($quote['subtotal'] ?? 0),
            'discount'            => (int) ($quote['discount'] ?? 0),
            'tax'                 => (int) ($quote['tax'] ?? 0),
            'total'               => (int) ($quote['total'] ?? 0),
            'subtotal_formatted'  => Money::cents((int) ($quote['subtotal'] ?? 0))->format(),
            'discount_formatted'  => Money::cents((int) ($quote['discount'] ?? 0))->format(),
            'tax_formatted'       => Money::cents((int) ($quote['tax'] ?? 0))->format(),
            'total_formatted'     => Money::cents((int) ($quote['total'] ?? 0))->format(),
            'tax_rate'            => 21,
            'created_date'        => $createdDate,
            'valid_until_date'    => $validUntilDate,
            'billing'             => $billing,
            'order_number'        => $quote['order_number'] ?? '',
            'voucher_code'        => $quote['voucher_code'] ?? '',
            'user'                => [
                'name'  => $user ? $user->display_name : '',
                'email' => $user ? $user->user_email : '',
            ],
            'company'             => $this->enrichCompanyDetails(),
            'customer_notes'      => $this->extractCustomerNotes($quote),
        ];
    }

    /**
     * Extract customer-facing notes (excludes internal/admin notes).
     *
     * @return array<array{content: string, date: string}>
     */
    private function extractCustomerNotes(array $quote): array
    {
        $notes = $quote['notes'] ?? [];
        if (is_string($notes)) {
            $notes = json_decode($notes, true) ?: [];
        }

        return array_values(array_filter($notes, function (array $note): bool {
            return ($note['type'] ?? '') === 'customer'
                && !empty($note['content'])
                && empty($note['_deleted']);
        }));
    }

    /**
     * Get company details with logo resolved to absolute file path for DOMPDF.
     */
    private function enrichCompanyDetails(): array
    {
        $company = StrideSettingsService::getCompanyDetails();

        // Resolve logo URL to absolute file path (DOMPDF needs file path, not URL)
        if (!empty($company['logo'])) {
            $logoPath = $this->urlToPath($company['logo']);
            $company['logo_path'] = ($logoPath && file_exists($logoPath)) ? $logoPath : '';
        } else {
            $company['logo_path'] = '';
        }

        return $company;
    }

    /**
     * Convert a WordPress upload URL to an absolute file path.
     */
    private function urlToPath(string $url): string
    {
        $uploadDir = wp_upload_dir();
        $baseUrl = $uploadDir['baseurl'] ?? '';
        $baseDir = $uploadDir['basedir'] ?? '';

        if ($baseUrl && $baseDir && str_contains($url, $baseUrl)) {
            return str_replace($baseUrl, $baseDir, $url);
        }

        // Fallback: try content URL
        $contentUrl = content_url();
        if (str_contains($url, $contentUrl)) {
            return str_replace($contentUrl, WP_CONTENT_DIR, $url);
        }

        return '';
    }

    /**
     * Render the PHP template to HTML string.
     */
    private function renderTemplate(array $quote): string|false
    {
        $templatesDir = dirname(__DIR__, 2) . '/templates';

        if (!file_exists($templatesDir . '/pdf/quote.php')) {
            ntdst_log('invoicing')->error('PDF template not found', [
                'path' => $templatesDir . '/pdf/quote.php',
            ]);
            return false;
        }

        return ntdst_response()
            ->addPath($templatesDir)
            ->withData([
                'quote' => $quote,
                'formatCurrency' => fn(int $cents): string => Money::cents(abs($cents))->format(),
            ])
            ->html('pdf/quote');
    }

    /**
     * Render HTML to PDF file using DOMPDF.
     */
    private function renderPDF(string $html, string $outputPath): true|WP_Error
    {
        try {
            $options = new Options();
            $options->set('isRemoteEnabled', false);
            $options->set('isHtml5ParserEnabled', true);
            $options->set('defaultFont', 'DejaVu Sans');

            $dompdf = new Dompdf($options);
            $dompdf->loadHtml($html);
            $dompdf->setPaper('A4', 'portrait');
            $dompdf->render();

            $output = $dompdf->output();

            if (empty($output)) {
                return new WP_Error('pdf_empty', 'DOMPDF produced empty output');
            }

            // Ensure directory exists
            $dir = dirname($outputPath);
            if (!is_dir($dir)) {
                wp_mkdir_p($dir);
            }

            $written = file_put_contents($outputPath, $output);

            if ($written === false) {
                return new WP_Error('write_failed', 'Could not write PDF to disk');
            }

            return true;
        } catch (\Throwable $e) {
            ntdst_log('invoicing')->error('DOMPDF rendering failed', [
                'error' => $e->getMessage(),
            ]);
            return new WP_Error('dompdf_error', $e->getMessage());
        }
    }

    /**
     * Get full storage path for a quote PDF.
     */
    private function getStoragePath(string $quoteNumber): string
    {
        $uploadDir = wp_upload_dir();
        $baseDir = $uploadDir['basedir'] ?? (WP_CONTENT_DIR . '/uploads');

        return $baseDir . '/' . self::UPLOAD_DIR . '/' . $quoteNumber . '.pdf';
    }

    /**
     * Convert full path to relative path (relative to WP_CONTENT_DIR).
     */
    private function getRelativePath(string $fullPath): string
    {
        return ltrim(str_replace(WP_CONTENT_DIR, '', $fullPath), '/');
    }
}
