# Phase 3: Quote System

## Problem/Feature

Stride needs a quote system that:
1. Creates quotes when users enroll in courses
2. Produces PDF documents for download/email
3. Allows users to update billing info (order number, voucher, VAT, etc.)
4. Exports to Exact Online for accounting

**Key Decision from CLAUDE.md:** "Stride creates quotes; Exact Online handles actual invoicing"

---

## Acceptance Criteria

### Quote CPT
- [ ] Custom post type `vad_quote` registered
- [ ] Quote number format: `VADQ-YYYY-NNNNN`
- [ ] Status workflow: draft → sent → exported (simple 3-state)
- [ ] All required meta fields stored

### QuoteService
- [ ] `createQuote($userId, $courseId, $meta)` - Creates quote with auto-numbering
- [ ] `updateQuote($quoteId, $data)` - Update billing/meta info
- [ ] `sendQuote($quoteId)` - Mark sent, trigger email
- [ ] `exportQuote($quoteId)` - Mark exported for Exact Online
- [ ] Integration with `stride/enrollment/completed` hook

### VAT Number Validator
- [ ] Validate Belgian/EU VAT numbers via VIES API
- [ ] Auto-fetch company name and address from VAT lookup
- [ ] Cache validated results
- [ ] Fail open: basic format check when API unavailable

### User Quote Update Form
- [ ] Form for users to update billing info on existing quote
- [ ] Fields: order number, voucher code, VAT number, company details
- [ ] Only editable while quote is in draft status
- [ ] Email notification to admin on changes

### PDF Generation
- [ ] DOMPDF integration via Composer
- [ ] Quote PDF template
- [ ] Company/billing address handling
- [ ] Secure storage with authenticated download

### Exact Online Export
- [ ] CSV export format (configurable, format TBD)
- [ ] Batch export UI for admins
- [ ] Mark quotes as exported after download

---

## Implementation

### Directory Structure

```
services/
└── invoicing/
    ├── QuoteService.php           # Main service (CPT, CRUD, status, numbering)
    ├── VATValidator.php           # EU VIES API integration
    ├── QuotePDFGenerator.php      # DOMPDF wrapper
    ├── ExactOnlineExporter.php    # CSV export
    └── QuoteUpdateHandler.php     # User form updates

templates/
├── pdf/
│   └── quote.php                  # Quote PDF template
└── forms/
    └── quote-update.php           # User quote update form

forms/
└── quote-update.json              # FluentForms template for quote updates
```

**Note:** Consolidated from 8 files to 5. CPT registration and number generation are part of QuoteService.

### File 1: QuoteService.php

Main service with CPT registration and quote management.

```php
<?php
namespace stride\services\invoicing;

use stride\services\core\SubscriberService;

class QuoteService implements \NTDST_ServiceInterface
{
    public const POST_TYPE = 'vad_quote';
    public const STATUS_DRAFT = 'draft';
    public const STATUS_SENT = 'sent';
    public const STATUS_EXPORTED = 'exported';

    private ?SubscriberService $subscriberService;
    private ?VATValidator $vatValidator;

    public static function metadata(): array
    {
        return [
            'name' => 'Quote Service',
            'description' => 'Quote CPT, CRUD, and status management',
            'priority' => 10,
        ];
    }

    public function __construct(
        ?SubscriberService $subscriberService = null,
        ?VATValidator $vatValidator = null
    ) {
        $this->subscriberService = $subscriberService ?? ntdst_get(SubscriberService::class);
        $this->vatValidator = $vatValidator ?? new VATValidator();

        // Register CPT
        add_action('init', [$this, 'registerPostType']);

        // Hook into enrollment completion (matches existing signature)
        add_action('stride/enrollment/completed', [$this, 'handleEnrollmentCompleted'], 10, 3);
    }

    public function registerPostType(): void
    {
        register_post_type(self::POST_TYPE, [
            'labels' => [
                'name' => 'Offertes',
                'singular_name' => 'Offerte',
            ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => 'stride-admin',
            'supports' => ['title'],
            'capability_type' => 'post',
            'map_meta_cap' => true,
        ]);
    }

    public function createQuote(int $userId, int $courseId, array $meta = []): int
    {
        // 1. Generate quote number (atomic increment)
        // 2. Get billing info from subscriber
        // 3. Validate VAT number if provided
        // 4. Calculate totals (subtotal, tax 21%, total)
        // 5. Create post with meta
        // 6. Add CRM note via SubscriberService
        // 7. Fire 'stride/quote/created' action
    }

    public function updateQuote(int $quoteId, array $data): bool
    {
        // Only allow updates in draft status
        // Update billing/meta fields
        // Re-validate VAT if changed
        // Fire 'stride/quote/updated' action
    }

    public function sendQuote(int $quoteId): bool
    {
        // Update status to sent
        // Generate PDF
        // Trigger email notification
    }

    /**
     * Handle enrollment completion - matches hook signature.
     */
    public function handleEnrollmentCompleted(int $userId, int $courseId, array $data): void
    {
        if (!$this->shouldCreateQuote($userId, $courseId)) {
            return;
        }
        $this->createQuote($userId, $courseId, $data);
    }

    private function shouldCreateQuote(int $userId, int $courseId): bool
    {
        // Skip if user is admin
        // Skip if email ends with @vad.be or @druglijn.be
        // Skip if user has "geen-factuur" tag in FluentCRM
        // Skip if course has no price
        // Skip if user already has quote for this course
    }

    private function generateQuoteNumber(): string
    {
        global $wpdb;
        $year = date('Y');
        $optionName = "stride_quote_last_{$year}";

        // Atomic increment to prevent race conditions
        $wpdb->query('START TRANSACTION');
        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$wpdb->options} (option_name, option_value, autoload)
             VALUES (%s, 1, 'no')
             ON DUPLICATE KEY UPDATE option_value = option_value + 1",
            $optionName
        ));
        $number = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
            $optionName
        ));
        $wpdb->query('COMMIT');

        return sprintf('VADQ-%s-%05d', $year, $number);
    }
}
```

### File 2: VATValidator.php

EU VAT validation via VIES API. Fails open with basic format check when API unavailable.

```php
<?php
namespace stride\services\invoicing;

class VATValidator
{
    private const VIES_WSDL = 'https://ec.europa.eu/taxation_customs/vies/checkVatService.wsdl';
    private const CACHE_GROUP = 'stride_vat';
    private const CACHE_TTL = DAY_IN_SECONDS;

    /**
     * Validate VAT number and return company data.
     * Fails open: if VIES is unavailable, accepts valid format.
     *
     * @return array{valid: bool, name?: string, address?: string, source: string}
     */
    public function validate(string $vatNumber): array
    {
        $vatNumber = $this->normalize($vatNumber);

        // Basic format check first
        if (!$this->hasValidFormat($vatNumber)) {
            return ['valid' => false, 'source' => 'format'];
        }

        $countryCode = substr($vatNumber, 0, 2);
        $number = substr($vatNumber, 2);

        // Check cache
        $cached = wp_cache_get($vatNumber, self::CACHE_GROUP);
        if ($cached !== false) {
            return $cached;
        }

        try {
            $client = new \SoapClient(self::VIES_WSDL, [
                'exceptions' => true,
                'connection_timeout' => 5,
            ]);

            $response = $client->checkVat([
                'countryCode' => $countryCode,
                'vatNumber' => $number,
            ]);

            $result = [
                'valid' => $response->valid,
                'name' => $response->name ?? null,
                'address' => $response->address ?? null,
                'country_code' => $countryCode,
                'vat_number' => $vatNumber,
                'source' => 'vies',
            ];

            wp_cache_set($vatNumber, $result, self::CACHE_GROUP, self::CACHE_TTL);
            return $result;

        } catch (\Exception $e) {
            // FAIL OPEN: Accept if format is valid but VIES unavailable
            return [
                'valid' => true,
                'vat_number' => $vatNumber,
                'source' => 'format_only',
                'vies_error' => $e->getMessage(),
            ];
        }
    }

    public function normalize(string $vatNumber): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $vatNumber));
    }

    /**
     * Basic format validation for common EU countries.
     */
    private function hasValidFormat(string $vatNumber): bool
    {
        // Must start with 2-letter country code followed by digits
        if (strlen($vatNumber) < 4) {
            return false;
        }
        $countryCode = substr($vatNumber, 0, 2);
        if (!ctype_alpha($countryCode)) {
            return false;
        }
        // Belgian VAT: BE + 10 digits
        if ($countryCode === 'BE') {
            return preg_match('/^BE[0-9]{10}$/', $vatNumber);
        }
        // Other EU: accept if starts with valid country + has digits
        return preg_match('/^[A-Z]{2}[A-Z0-9]{2,}$/', $vatNumber);
    }
}
```

### File 3: QuoteUpdateHandler.php

Handle user updates to quote billing info (order number, voucher, VAT, etc.).

```php
<?php
namespace stride\services\invoicing;

class QuoteUpdateHandler implements \NTDST_ServiceInterface
{
    private ?QuoteService $quoteService;
    private ?VATValidator $vatValidator;

    public static function metadata(): array
    {
        return [
            'name' => 'Quote Update Handler',
            'description' => 'Handles user updates to quote billing info',
            'priority' => 12,
        ];
    }

    public function __construct(
        ?QuoteService $quoteService = null,
        ?VATValidator $vatValidator = null
    ) {
        $this->quoteService = $quoteService ?? ntdst_get(QuoteService::class);
        $this->vatValidator = $vatValidator ?? new VATValidator();

        // FluentForms submission hook
        add_action('fluentform/submission_inserted', [$this, 'handleFormSubmission'], 10, 3);

        // AJAX endpoint for logged-in users
        add_action('wp_ajax_stride_update_quote', [$this, 'handleAjaxUpdate']);

        // Shortcode for update form
        add_shortcode('stride_quote_update', [$this, 'renderUpdateForm']);
    }

    /**
     * Render quote update form for user.
     */
    public function renderUpdateForm(array $atts = []): string
    {
        if (!is_user_logged_in()) {
            return '<p>Log in om uw gegevens bij te werken.</p>';
        }

        $quoteId = $atts['quote_id'] ?? $this->getUserDraftQuote();
        if (!$quoteId) {
            return '<p>Geen openstaande offerte gevonden.</p>';
        }

        $quote = $this->quoteService->getQuote($quoteId);
        if (!$quote) {
            return '<p>Offerte niet gevonden.</p>';
        }

        // Only allow updates while in draft
        if ($quote['status'] !== QuoteService::STATUS_DRAFT) {
            return '<p>Deze offerte kan niet meer worden gewijzigd.</p>';
        }

        ob_start();
        include get_stylesheet_directory() . '/templates/forms/quote-update.php';
        return ob_get_clean();
    }

    /**
     * Handle FluentForms submission to update quote.
     */
    public function handleFormSubmission($entryId, $formData, $form): void
    {
        if (empty($formData['quote_id'])) {
            return;
        }

        $quoteId = (int) $formData['quote_id'];
        $userId = get_current_user_id();

        // Verify ownership and draft status
        if (!$this->canUserUpdateQuote($quoteId, $userId)) {
            return;
        }

        // Validate VAT if provided, auto-fill company data
        $billingData = $this->prepareBillingData($formData);

        // Update quote
        $this->quoteService->updateQuote($quoteId, $billingData);

        // Notify admin
        do_action('stride/quote/updated_by_user', $quoteId, $userId, $billingData);
    }

    /**
     * AJAX handler for quote updates.
     */
    public function handleAjaxUpdate(): void
    {
        check_ajax_referer('stride_quote_update', 'nonce');

        $quoteId = (int) ($_POST['quote_id'] ?? 0);
        $userId = get_current_user_id();

        if (!$this->canUserUpdateQuote($quoteId, $userId)) {
            wp_send_json_error(['message' => 'Niet toegestaan']);
        }

        $billingData = $this->prepareBillingData($_POST);
        $this->quoteService->updateQuote($quoteId, $billingData);

        do_action('stride/quote/updated_by_user', $quoteId, $userId, $billingData);

        wp_send_json_success(['message' => 'Gegevens bijgewerkt']);
    }

    private function canUserUpdateQuote(int $quoteId, int $userId): bool
    {
        $quote = get_post($quoteId);
        if (!$quote || $quote->post_type !== QuoteService::POST_TYPE) {
            return false;
        }
        $ownerId = (int) get_post_meta($quoteId, '_stride_user_id', true);
        $status = get_post_meta($quoteId, '_stride_status', true);

        return $ownerId === $userId && $status === QuoteService::STATUS_DRAFT;
    }

    private function prepareBillingData(array $formData): array
    {
        $billing = [
            'company' => sanitize_text_field($formData['company_name'] ?? ''),
            'address' => sanitize_text_field($formData['address'] ?? ''),
            'city' => sanitize_text_field($formData['city'] ?? ''),
            'postal_code' => sanitize_text_field($formData['postal_code'] ?? ''),
            'vat_number' => sanitize_text_field($formData['vat_number'] ?? ''),
            'gln_number' => sanitize_text_field($formData['gln_number'] ?? ''),
            'order_number' => sanitize_text_field($formData['order_number'] ?? ''),
            'voucher_code' => sanitize_text_field($formData['voucher_code'] ?? ''),
        ];

        // Validate and enrich VAT data
        if (!empty($billing['vat_number'])) {
            $vatResult = $this->vatValidator->validate($billing['vat_number']);
            if ($vatResult['valid'] && !empty($vatResult['name'])) {
                $billing['company'] = $vatResult['name'];
            }
            if ($vatResult['valid'] && !empty($vatResult['address'])) {
                $billing['company_address_vies'] = $vatResult['address'];
            }
            $billing['vat_validated'] = $vatResult['valid'];
            $billing['vat_source'] = $vatResult['source'] ?? 'unknown';
        }

        return $billing;
    }

    private function getUserDraftQuote(): ?int
    {
        $userId = get_current_user_id();
        $quotes = get_posts([
            'post_type' => QuoteService::POST_TYPE,
            'meta_query' => [
                ['key' => '_stride_user_id', 'value' => $userId],
                ['key' => '_stride_status', 'value' => QuoteService::STATUS_DRAFT],
            ],
            'posts_per_page' => 1,
            'fields' => 'ids',
        ]);
        return $quotes[0] ?? null;
    }
}
```

### File 4: QuotePDFGenerator.php

DOMPDF wrapper with secure storage.

```php
<?php
namespace stride\services\invoicing;

use Dompdf\Dompdf;
use Dompdf\Options;

class QuotePDFGenerator
{
    private string $uploadDir;

    public function __construct()
    {
        $upload = wp_upload_dir();
        $this->uploadDir = $upload['basedir'] . '/stride-quotes/';

        $this->ensureSecureDirectory();
    }

    public function generate(int $quoteId): string
    {
        $quote = $this->getQuoteData($quoteId);
        $html = $this->renderTemplate($quote);

        $options = new Options();
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', false); // Security: no remote resources

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $filename = sprintf('quote-%s.pdf', $quote['number']);
        $filepath = $this->uploadDir . $filename;
        file_put_contents($filepath, $dompdf->output());

        // Store path in post meta for later retrieval
        update_post_meta($quoteId, '_stride_pdf_path', $filepath);

        return $filepath;
    }

    /**
     * Serve PDF with authentication check.
     */
    public function servePdf(int $quoteId): void
    {
        $userId = get_current_user_id();
        $ownerId = (int) get_post_meta($quoteId, '_stride_user_id', true);

        // Allow owner or admin
        if (!current_user_can('manage_options') && $userId !== $ownerId) {
            wp_die('Niet toegestaan', 403);
        }

        $filepath = get_post_meta($quoteId, '_stride_pdf_path', true);
        if (!$filepath || !file_exists($filepath)) {
            $filepath = $this->generate($quoteId);
        }

        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . basename($filepath) . '"');
        readfile($filepath);
        exit;
    }

    private function ensureSecureDirectory(): void
    {
        if (!file_exists($this->uploadDir)) {
            wp_mkdir_p($this->uploadDir);
        }
        // Prevent direct access
        $htaccess = $this->uploadDir . '.htaccess';
        if (!file_exists($htaccess)) {
            file_put_contents($htaccess, "deny from all\n");
        }
        $index = $this->uploadDir . 'index.php';
        if (!file_exists($index)) {
            file_put_contents($index, "<?php // Silence is golden\n");
        }
    }

    private function getQuoteData(int $quoteId): array
    {
        // Fetch quote post and meta, format for template
    }

    private function renderTemplate(array $quote): string
    {
        ob_start();
        include get_stylesheet_directory() . '/templates/pdf/quote.php';
        return ob_get_clean();
    }
}
```

### File 5: ExactOnlineExporter.php

CSV export (format configurable).

```php
<?php
namespace stride\services\invoicing;

class ExactOnlineExporter
{
    /**
     * Export quotes to CSV for Exact Online import.
     * Format is configurable since exact requirements TBD.
     */
    public function exportBatch(array $quoteIds): string
    {
        $quotes = array_map([$this, 'getQuoteData'], $quoteIds);

        $csv = fopen('php://temp', 'r+');

        // Header row (configurable)
        fputcsv($csv, [
            'InvoiceNumber',
            'InvoiceDate',
            'DueDate',
            'CustomerName',
            'CustomerEmail',
            'CustomerVAT',
            'Amount',
            'TaxAmount',
            'Total',
            'PaymentReference', // OGM
            'Description',
        ]);

        foreach ($quotes as $quote) {
            fputcsv($csv, [
                $quote['number'],
                $quote['date'],
                $quote['due_date'],
                $quote['billing']['company'] ?? $quote['billing']['name'],
                $quote['billing']['email'],
                $quote['billing']['vat_number'],
                $quote['subtotal'],
                $quote['tax'],
                $quote['total'],
                $quote['payment_reference'],
                $this->formatDescription($quote),
            ]);

            // Mark as exported
            update_post_meta($quote['id'], '_stride_exported', current_time('mysql'));
        }

        rewind($csv);
        $content = stream_get_contents($csv);
        fclose($csv);

        return $content;
    }
}
```

### Integration: Hook into Enrollment

The existing `EnrollmentService.php` already fires `stride/enrollment/completed` (line 138).

QuoteService listens to this hook and creates quotes when appropriate.

**Business Rules (from v3 analysis):**
- Skip if user is admin
- Skip if email ends with @vad.be or @druglijn.be
- Skip if user has "Geen factuur" tag in FluentCRM
- Skip if course has no price/invoice item
- Skip if user already has quote for this course

### Composer Dependency

Add DOMPDF to composer.json:

```bash
composer require dompdf/dompdf
```

---

## Configuration

Add to `theme-config.php`:

```php
'invoicing' => [
    'quote_prefix' => 'VADQ',        // Quote number prefix
    'tax_rate' => 21.0,              // BTW percentage
    'currency' => 'EUR',
    'valid_days' => 30,              // Quote validity period
    'skip_domains' => ['vad.be', 'druglijn.be'],
    'skip_tag' => 'geen-factuur',
    'vat_validation' => [
        'enabled' => true,
        'auto_fill' => true,         // Auto-fill company from VIES
        'fail_open' => true,         // Accept valid format when VIES unavailable
    ],
    'allow_user_updates' => true,    // Allow users to update billing info
],
```

---

## References

### Similar Code in Stride
- `services/enrollment/EnrollmentService.php:138` - Enrollment completed hook
- `services/enrollment/FormSubmissionHandler.php:455-463` - Invoice field extraction
- `services/smartcode/providers/InvoiceSmartCodeProvider.php` - Placeholder for SmartCodes
- `theme-config.php` - Invoicing config section

### VAD v3 Patterns
- `/vad-vormingen/.../InvoiceOrchestrationService.php` - Quote creation flow
- `/vad-vormingen/.../PDFHelper.php` - DOMPDF implementation
- `/vad-vormingen/.../ExportInvoice_Hooks.php` - Exact Online export with OGM

---

## Task Checklist

### Core Infrastructure
- [ ] Create `services/invoicing/` directory
- [ ] Add DOMPDF to composer.json
- [ ] Create `templates/pdf/` directory
- [ ] Create `templates/forms/` directory

### QuoteService (CPT + CRUD)
- [ ] Implement `QuoteService.php` with CPT registration
- [ ] Atomic quote number generation
- [ ] Hook into `stride/enrollment/completed`
- [ ] Add business rules for quote creation (skip domains, tags)
- [ ] CRUD methods: createQuote, updateQuote, sendQuote
- [ ] Add CRM notes on quote events

### VAT Validation
- [ ] Implement `VATValidator.php` with VIES SOAP client
- [ ] Add caching for validated VAT numbers
- [ ] Auto-populate company name/address from VIES response
- [ ] Fail open: basic format check when API unavailable

### User Quote Updates
- [ ] Implement `QuoteUpdateHandler.php`
- [ ] Create `templates/forms/quote-update.php` template
- [ ] Create `forms/quote-update.json` FluentForms template
- [ ] AJAX handler for updates
- [ ] Admin notification on user changes
- [ ] Support order number, voucher code, VAT, company fields

### PDF Generation
- [ ] Implement `QuotePDFGenerator.php`
- [ ] Secure storage with .htaccess protection
- [ ] Authenticated download endpoint
- [ ] Create `templates/pdf/quote.php` template
- [ ] Test PDF output with sample data

### Export
- [ ] Implement `ExactOnlineExporter.php`
- [ ] Create admin UI for batch export (Phase 6)

### Integration
- [ ] Update `InvoiceSmartCodeProvider.php` with real implementation
- [ ] Fire hooks: `stride/quote/created`, `stride/quote/sent`, etc.

---

## Notes

- **Exact Online format TBD**: CSV export is placeholder; actual format needs confirmation from accounting
- **No GetPaid dependency**: Building custom CPT unlike v3 which used GetPaid plugin
- **Admin UI deferred**: Phase 6 covers admin dashboard; this phase focuses on backend
- **VAT validation**: Uses EU VIES SOAP API with fail-open on downtime (basic format check)
- **InvoiceService deferred**: Online payment/invoice conversion to be built when needed
- **User updates**: Only allowed while quote is in draft status
