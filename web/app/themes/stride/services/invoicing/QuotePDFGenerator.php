<?php

namespace stride\services\invoicing;

defined('ABSPATH') || exit;

use Dompdf\Dompdf;
use Dompdf\Options;
use stride\services\core\CourseService;
use stride\services\core\SubscriberService;
use WP_Error;

/**
 * Quote PDF Generator
 *
 * Generates PDF documents for quotes using DOMPDF.
 * PDFs are stored securely and served through authenticated endpoints.
 *
 * @package stride\services\invoicing
 */
class QuotePDFGenerator implements \NTDST_Service_Meta
{
    private string $uploadDir;
    private string $uploadUrl;
    private ?QuoteService $quoteService;
    private ?CourseService $courseService;
    private ?SubscriberService $subscriberService;

    /**
     * Service metadata for NTDST Bootstrap
     */
    public static function metadata(): array
    {
        return [
            'name' => 'Quote PDF Generator',
            'description' => 'Generates PDF documents for quotes using DOMPDF',
            'admin_only' => false,
            'enabled' => true,
            'priority' => 10,
        ];
    }

    /**
     * Constructor
     */
    public function __construct(
        ?QuoteService $quoteService = null,
        ?CourseService $courseService = null,
        ?SubscriberService $subscriberService = null
    ) {
        $this->quoteService = $quoteService ?? $this->resolveService(QuoteService::class);
        $this->courseService = $courseService ?? $this->resolveService(CourseService::class);
        $this->subscriberService = $subscriberService ?? $this->resolveService(SubscriberService::class);

        $upload = wp_upload_dir();
        $this->uploadDir = $upload['basedir'] . '/stride-quotes/';
        $this->uploadUrl = $upload['baseurl'] . '/stride-quotes/';

        $this->ensureSecureDirectory();

        // Register download endpoint
        add_action('init', [$this, 'registerRewriteRules']);
        add_action('template_redirect', [$this, 'handleDownloadRequest']);
        add_filter('query_vars', [$this, 'addQueryVars']);
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
     * Register rewrite rules for PDF download
     */
    public function registerRewriteRules(): void
    {
        add_rewrite_rule(
            '^quote-pdf/([0-9]+)/?$',
            'index.php?stride_quote_pdf=$matches[1]',
            'top'
        );
    }

    /**
     * Add query vars for PDF download
     *
     * @param array $vars Existing query vars
     * @return array Modified query vars
     */
    public function addQueryVars(array $vars): array
    {
        $vars[] = 'stride_quote_pdf';
        return $vars;
    }

    /**
     * Handle PDF download request
     */
    public function handleDownloadRequest(): void
    {
        $quoteId = get_query_var('stride_quote_pdf');
        if (!$quoteId) {
            return;
        }

        $this->servePdf((int) $quoteId);
    }

    /**
     * Generate PDF for a quote
     *
     * @param int $quoteId Quote post ID
     * @param bool $force Force regeneration even if PDF exists
     * @return string|WP_Error Path to generated PDF or error
     */
    public function generate(int $quoteId, bool $force = false): string|WP_Error
    {
        $quote = $this->quoteService->getQuote($quoteId);
        if (!$quote) {
            return new WP_Error('quote_not_found', __('Offerte niet gevonden.', 'stride'));
        }

        // Check if PDF already exists and is up to date
        $existingPath = $quote['pdf_path'] ?? '';
        if (!$force && $existingPath && file_exists($existingPath)) {
            return $existingPath;
        }

        // Enrich quote data for template
        $quoteData = $this->enrichQuoteData($quote);

        // Render HTML template
        $html = $this->renderTemplate($quoteData);

        // Generate PDF
        try {
            $options = new Options();
            $options->set('isHtml5ParserEnabled', true);
            $options->set('isRemoteEnabled', false); // Security: no remote resources
            $options->set('defaultFont', 'DejaVu Sans');

            $dompdf = new Dompdf($options);
            $dompdf->loadHtml($html);
            $dompdf->setPaper('A4', 'portrait');
            $dompdf->render();

            // Generate unique filename
            $filename = sprintf('quote-%s-%s.pdf', $quote['number'], substr(md5($quoteId . time()), 0, 8));
            $filepath = $this->uploadDir . $filename;

            // Save PDF
            file_put_contents($filepath, $dompdf->output());

            // Store path via QuoteService (uses DataManager)
            $this->quoteService->setPdfPath($quoteId, $filepath);

            return $filepath;

        } catch (\Exception $e) {
            // Log full error but return generic message (security: don't expose internals)
            error_log('Stride PDF generation error: ' . $e->getMessage());
            return new WP_Error('pdf_generation_failed', __('PDF generatie mislukt. Probeer het opnieuw.', 'stride'));
        }
    }

    /**
     * Serve PDF with authentication check
     *
     * @param int $quoteId Quote post ID
     */
    public function servePdf(int $quoteId): void
    {
        // Security: require login first (prevents enumeration via 404 vs 403)
        if (!is_user_logged_in()) {
            auth_redirect();
            exit;
        }

        $quote = $this->quoteService->getQuote($quoteId);
        if (!$quote) {
            wp_die(__('Offerte niet gevonden.', 'stride'), 404);
        }

        $userId = get_current_user_id();
        $ownerId = (int) $quote['user_id'];

        // Allow owner or admin
        if (!current_user_can('manage_options') && $userId !== $ownerId) {
            wp_die(__('U heeft geen toegang tot deze offerte.', 'stride'), 403);
        }

        // Get or generate PDF
        $filepath = $quote['pdf_path'] ?? '';
        if (!$filepath || !file_exists($filepath)) {
            $result = $this->generate($quoteId);
            if (is_wp_error($result)) {
                wp_die(__('PDF kon niet worden gegenereerd.', 'stride'), 500);
            }
            $filepath = $result;
        }

        // Security: validate filepath is within our upload directory (prevent path traversal)
        $realPath = realpath($filepath);
        $realUploadDir = realpath($this->uploadDir);

        if (!$realPath || !$realUploadDir || strpos($realPath, $realUploadDir) !== 0) {
            error_log('Stride PDF security: Invalid path attempted: ' . $filepath);
            wp_die(__('Ongeldig PDF-bestand.', 'stride'), 403);
        }

        // Sanitize filename for Content-Disposition header (prevent header injection)
        $safeFilename = preg_replace('/[^a-zA-Z0-9._-]/', '', basename($filepath));
        if (empty($safeFilename)) {
            $safeFilename = 'quote.pdf';
        }

        // Serve file
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $safeFilename . '"');
        header('Content-Length: ' . filesize($realPath));
        header('Cache-Control: private, max-age=0, must-revalidate');
        header('Pragma: public');

        readfile($realPath);
        exit;
    }

    /**
     * Get download URL for a quote PDF
     *
     * @param int $quoteId Quote post ID
     * @return string Download URL
     */
    public function getDownloadUrl(int $quoteId): string
    {
        return home_url('/quote-pdf/' . $quoteId . '/');
    }

    /**
     * Ensure upload directory exists and is secured
     */
    private function ensureSecureDirectory(): void
    {
        if (!file_exists($this->uploadDir)) {
            wp_mkdir_p($this->uploadDir);
        }

        // Prevent direct access via .htaccess
        $htaccess = $this->uploadDir . '.htaccess';
        if (!file_exists($htaccess)) {
            file_put_contents($htaccess, "deny from all\n");
        }

        // Add index.php for extra security
        $index = $this->uploadDir . 'index.php';
        if (!file_exists($index)) {
            file_put_contents($index, "<?php // Silence is golden\n");
        }
    }

    /**
     * Enrich quote data for PDF template
     *
     * @param array $quote Raw quote data
     * @return array Enriched quote data
     */
    private function enrichQuoteData(array $quote): array
    {
        // Get user data via SubscriberService
        $userId = (int) ($quote['user_id'] ?? 0);
        $userName = $this->subscriberService->getFullName($userId);
        $userEmail = $this->subscriberService->getUserEmail($userId);

        // Get course data via CourseService
        $courseId = (int) ($quote['course_id'] ?? 0);
        $courseTitle = $this->courseService->getCourseTitle($courseId);

        // Format dates
        $quote['created_date'] = $quote['created_at']
            ? date_i18n(get_option('date_format'), strtotime($quote['created_at']))
            : '';

        $quote['valid_until_date'] = $quote['valid_until']
            ? date_i18n(get_option('date_format'), strtotime($quote['valid_until']))
            : '';

        // Format currency
        $quote['subtotal_formatted'] = $this->formatCurrency($quote['subtotal']);
        $quote['tax_formatted'] = $this->formatCurrency($quote['tax']);
        $quote['total_formatted'] = $this->formatCurrency($quote['total']);

        // Get tax rate from config
        $quote['tax_rate'] = $this->getConfig('tax_rate', 21);

        // User info
        $quote['user'] = [
            'name' => $userName ?? '',
            'email' => $userEmail ?? '',
        ];

        // Course info
        $quote['course'] = [
            'title' => $courseTitle ?? '',
        ];

        // Company info from theme config or defaults
        $quote['company'] = [
            'name' => get_bloginfo('name'),
            'address' => $this->getConfig('company_address', ''),
            'city' => $this->getConfig('company_city', ''),
            'postal_code' => $this->getConfig('company_postal_code', ''),
            'country' => $this->getConfig('company_country', 'België'),
            'vat' => $this->getConfig('company_vat', ''),
            'email' => get_option('admin_email'),
            'phone' => $this->getConfig('company_phone', ''),
            'bank_account' => $this->getConfig('company_bank_account', ''),
        ];

        return $quote;
    }

    /**
     * Render PDF template
     *
     * @param array $quote Enriched quote data
     * @return string HTML content
     */
    private function renderTemplate(array $quote): string
    {
        // Try custom template first
        $templatePath = get_stylesheet_directory() . '/templates/pdf/quote.php';

        if (file_exists($templatePath)) {
            ob_start();
            include $templatePath;
            return ob_get_clean();
        }

        // Fallback to default template
        return $this->renderDefaultTemplate($quote);
    }

    /**
     * Render default PDF template
     *
     * @param array $quote Enriched quote data
     * @return string HTML content
     */
    private function renderDefaultTemplate(array $quote): string
    {
        $billing = $quote['billing'] ?? [];

        ob_start();
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <style>
                * {
                    margin: 0;
                    padding: 0;
                    box-sizing: border-box;
                }
                body {
                    font-family: DejaVu Sans, sans-serif;
                    font-size: 10pt;
                    line-height: 1.4;
                    color: #333;
                }
                .container {
                    padding: 40px;
                }
                .header {
                    display: flex;
                    justify-content: space-between;
                    margin-bottom: 40px;
                    border-bottom: 2px solid #333;
                    padding-bottom: 20px;
                }
                .company-info {
                    width: 50%;
                }
                .company-name {
                    font-size: 16pt;
                    font-weight: bold;
                    margin-bottom: 10px;
                }
                .quote-info {
                    width: 40%;
                    text-align: right;
                }
                .quote-title {
                    font-size: 20pt;
                    font-weight: bold;
                    margin-bottom: 10px;
                }
                .quote-number {
                    font-size: 12pt;
                    color: #666;
                }
                .addresses {
                    display: flex;
                    justify-content: space-between;
                    margin-bottom: 40px;
                }
                .address-block {
                    width: 45%;
                }
                .address-label {
                    font-weight: bold;
                    margin-bottom: 5px;
                    color: #666;
                    text-transform: uppercase;
                    font-size: 8pt;
                }
                .items-table {
                    width: 100%;
                    border-collapse: collapse;
                    margin-bottom: 30px;
                }
                .items-table th,
                .items-table td {
                    border: 1px solid #ddd;
                    padding: 10px;
                    text-align: left;
                }
                .items-table th {
                    background-color: #f5f5f5;
                    font-weight: bold;
                }
                .items-table .amount {
                    text-align: right;
                }
                .totals {
                    width: 300px;
                    margin-left: auto;
                    margin-bottom: 40px;
                }
                .totals-row {
                    display: flex;
                    justify-content: space-between;
                    padding: 5px 0;
                    border-bottom: 1px solid #eee;
                }
                .totals-row.total {
                    font-weight: bold;
                    font-size: 12pt;
                    border-top: 2px solid #333;
                    border-bottom: none;
                    padding-top: 10px;
                }
                .payment-info {
                    background-color: #f5f5f5;
                    padding: 20px;
                    margin-bottom: 30px;
                }
                .payment-info h3 {
                    margin-bottom: 10px;
                }
                .footer {
                    margin-top: 40px;
                    padding-top: 20px;
                    border-top: 1px solid #ddd;
                    font-size: 8pt;
                    color: #666;
                    text-align: center;
                }
                .valid-until {
                    margin-top: 20px;
                    font-style: italic;
                    color: #666;
                }
            </style>
        </head>
        <body>
            <div class="container">
                <table style="width: 100%; margin-bottom: 40px; border-bottom: 2px solid #333; padding-bottom: 20px;">
                    <tr>
                        <td style="width: 50%; vertical-align: top;">
                            <div class="company-name"><?php echo esc_html($quote['company']['name']); ?></div>
                            <div><?php echo esc_html($quote['company']['address']); ?></div>
                            <div><?php echo esc_html($quote['company']['postal_code'] . ' ' . $quote['company']['city']); ?></div>
                            <?php if ($quote['company']['vat']): ?>
                                <div>BTW: <?php echo esc_html($quote['company']['vat']); ?></div>
                            <?php endif; ?>
                        </td>
                        <td style="width: 50%; text-align: right; vertical-align: top;">
                            <div class="quote-title">OFFERTE</div>
                            <div class="quote-number"><?php echo esc_html($quote['number']); ?></div>
                            <div style="margin-top: 10px;">Datum: <?php echo esc_html($quote['created_date']); ?></div>
                        </td>
                    </tr>
                </table>

                <table style="width: 100%; margin-bottom: 40px;">
                    <tr>
                        <td style="width: 50%; vertical-align: top;">
                            <div class="address-label">FACTUURADRES</div>
                            <?php if (!empty($billing['organisation'])): ?>
                                <div><strong><?php echo esc_html($billing['organisation']); ?></strong></div>
                            <?php endif; ?>
                            <div><?php echo esc_html($quote['user']['name']); ?></div>
                            <?php if (!empty($billing['address'])): ?>
                                <div><?php echo esc_html($billing['address']); ?></div>
                            <?php endif; ?>
                            <?php if (!empty($billing['postal_code']) || !empty($billing['city'])): ?>
                                <div><?php echo esc_html(trim($billing['postal_code'] . ' ' . ($billing['city'] ?? ''))); ?></div>
                            <?php endif; ?>
                            <?php if (!empty($billing['vat_number'])): ?>
                                <div>BTW: <?php echo esc_html($billing['vat_number']); ?></div>
                            <?php endif; ?>
                        </td>
                        <td style="width: 50%; vertical-align: top;">
                            <?php if (!empty($quote['order_number'])): ?>
                                <div><strong>Bestelnummer:</strong> <?php echo esc_html($quote['order_number']); ?></div>
                            <?php endif; ?>
                            <?php if (!empty($billing['gln_number'])): ?>
                                <div><strong>GLN:</strong> <?php echo esc_html($billing['gln_number']); ?></div>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>

                <table class="items-table">
                    <thead>
                        <tr>
                            <th>Omschrijving</th>
                            <th style="width: 80px;">Aantal</th>
                            <th style="width: 100px;" class="amount">Prijs</th>
                            <th style="width: 100px;" class="amount">Totaal</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($quote['items'] as $item): ?>
                        <tr>
                            <td><?php echo esc_html($item['title']); ?></td>
                            <td><?php echo esc_html($item['quantity']); ?></td>
                            <td class="amount"><?php echo esc_html($this->formatCurrency($item['unit_price'])); ?></td>
                            <td class="amount"><?php echo esc_html($this->formatCurrency($item['total'])); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <table style="width: 300px; margin-left: auto; margin-bottom: 40px;">
                    <tr>
                        <td>Subtotaal</td>
                        <td style="text-align: right;"><?php echo esc_html($quote['subtotal_formatted']); ?></td>
                    </tr>
                    <tr>
                        <td>BTW (<?php echo esc_html($quote['tax_rate']); ?>%)</td>
                        <td style="text-align: right;"><?php echo esc_html($quote['tax_formatted']); ?></td>
                    </tr>
                    <tr style="font-weight: bold; font-size: 12pt; border-top: 2px solid #333;">
                        <td style="padding-top: 10px;">Totaal</td>
                        <td style="text-align: right; padding-top: 10px;"><?php echo esc_html($quote['total_formatted']); ?></td>
                    </tr>
                </table>

                <?php if ($quote['company']['bank_account']): ?>
                <div class="payment-info">
                    <h3>Betalingsgegevens</h3>
                    <div>Rekeningnummer: <?php echo esc_html($quote['company']['bank_account']); ?></div>
                </div>
                <?php endif; ?>

                <div class="valid-until">
                    Deze offerte is geldig tot <?php echo esc_html($quote['valid_until_date']); ?>.
                </div>

                <div class="footer">
                    <?php echo esc_html($quote['company']['name']); ?>
                    <?php if ($quote['company']['email']): ?>
                        | <?php echo esc_html($quote['company']['email']); ?>
                    <?php endif; ?>
                    <?php if ($quote['company']['phone']): ?>
                        | <?php echo esc_html($quote['company']['phone']); ?>
                    <?php endif; ?>
                </div>
            </div>
        </body>
        </html>
        <?php
        return ob_get_clean();
    }

    /**
     * Format currency value
     *
     * @param float $amount Amount to format
     * @return string Formatted amount
     */
    private function formatCurrency(float $amount): string
    {
        $currency = $this->getConfig('currency', 'EUR');
        $symbol = $currency === 'EUR' ? '€' : $currency;

        return $symbol . ' ' . number_format($amount, 2, ',', '.');
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
     * Delete PDF file for a quote
     *
     * @param int $quoteId Quote post ID
     * @return bool True if deleted, false if not found
     */
    public function deletePdf(int $quoteId): bool
    {
        $quote = $this->quoteService->getQuote($quoteId);
        $filepath = $quote['pdf_path'] ?? '';

        if ($filepath && file_exists($filepath)) {
            unlink($filepath);
            // Clear path via QuoteService (uses DataManager)
            $this->quoteService->setPdfPath($quoteId, '');
            return true;
        }

        return false;
    }
}
