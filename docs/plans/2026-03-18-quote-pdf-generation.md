# Quote PDF Generation — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Generate professional PDF quotes using DOMPDF, with company settings in admin and auto-attachment to emails.

**Architecture:** QuotePDFGenerator service (created inside QuoteService::init()) renders the existing HTML template with DOMPDF, stores PDFs in `wp-content/uploads/stride-quotes/`, and registers with the ndmail_pdf_generators filter for email attachment. Company details come from a new "Bedrijf" settings tab stored as `stride_company_details` WP option.

**Tech Stack:** DOMPDF 3.1 (already in composer.json), Alpine.js (existing settings app), PHP 8.3

---

## File Structure

| Action | File | Responsibility |
|--------|------|---------------|
| Create | `Modules/Invoicing/QuotePDFGenerator.php` | PDF generation, storage, hook registration |
| Create | `templates/pdf/quote.php` | HTML/CSS template for DOMPDF rendering |
| Create | `templates/admin/settings/tab-company.php` | Company settings form (Alpine.js) |
| Modify | `Modules/Invoicing/QuoteService.php` | Instantiate generator in `init()` |
| Modify | `Admin/StrideSettingsService.php` | Add company tab handler + localized data |
| Modify | `templates/admin/settings.php` | Add "Bedrijf" tab to nav |
| Modify | `assets/js/admin/settings.js` | Add company state + `saveCompany()` |
| Create | `tests/Unit/QuotePDFGeneratorTest.php` | Unit tests for generator logic |

All paths relative to `web/app/mu-plugins/stride-core/`.

---

### Task 1: Company Settings — Backend

Add company details save/load to StrideSettingsService.

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Admin/StrideSettingsService.php`

- [ ] **Step 1: Add company option constant and defaults**

In `StrideSettingsService`, add after the `DEFAULT_SLUGS` constant:

```php
/** Option name for company details */
private const OPTION_COMPANY = 'stride_company_details';

/** Default company details */
private const DEFAULT_COMPANY = [
    'name' => '',
    'address' => '',
    'postal_code' => '',
    'city' => '',
    'country' => 'België',
    'vat' => '',
    'email' => '',
    'phone' => '',
    'bank_account' => '',
];
```

- [ ] **Step 2: Add static accessor for company details**

Add after `getAllSlugs()`:

```php
/**
 * Get company details.
 *
 * @return array{name: string, address: string, postal_code: string, city: string, country: string, vat: string, email: string, phone: string, bank_account: string}
 */
public static function getCompanyDetails(): array
{
    $details = get_option(self::OPTION_COMPANY, self::DEFAULT_COMPANY);

    return array_merge(self::DEFAULT_COMPANY, is_array($details) ? $details : []);
}
```

- [ ] **Step 3: Add save handler for company tab**

Add after `saveGeneralSettings()`:

```php
/**
 * Save company details.
 *
 * @return array{message: string}
 */
private function saveCompanySettings(array $params): array
{
    $details = [
        'name'         => sanitize_text_field($params['name'] ?? ''),
        'address'      => sanitize_text_field($params['address'] ?? ''),
        'postal_code'  => sanitize_text_field($params['postal_code'] ?? ''),
        'city'         => sanitize_text_field($params['city'] ?? ''),
        'country'      => sanitize_text_field($params['country'] ?? self::DEFAULT_COMPANY['country']),
        'vat'          => sanitize_text_field($params['vat'] ?? ''),
        'email'        => sanitize_email($params['email'] ?? ''),
        'phone'        => sanitize_text_field($params['phone'] ?? ''),
        'bank_account' => sanitize_text_field($params['bank_account'] ?? ''),
    ];

    update_option(self::OPTION_COMPANY, $details);

    return ['message' => 'Bedrijfsgegevens opgeslagen.'];
}
```

- [ ] **Step 4: Register company tab in match statement**

In `handleSaveSettings()`, update the match:

```php
return match ($tab) {
    'general' => $this->saveGeneralSettings($params),
    'company' => $this->saveCompanySettings($params),
    'profile-types' => $this->saveProfileTypes($params),
    default => new WP_Error('invalid_tab', __('Onbekend tabblad.', 'stride')),
};
```

- [ ] **Step 5: Add company data to localized JS data**

In `getLocalizedData()`, add to the return array:

```php
'company' => self::getCompanyDetails(),
```

- [ ] **Step 6: Add 'company' to valid hash tabs in init()**

In `init()` of `settings.js` (handled in Task 2), the valid tabs list must include `'company'`. This is done in the JS file.

- [ ] **Step 7: Commit**

```bash
git add web/app/mu-plugins/stride-core/Admin/StrideSettingsService.php
git commit -m "feat(settings): add company details backend (save/load/localize)"
```

---

### Task 2: Company Settings — Frontend

Add the "Bedrijf" tab template and Alpine.js state.

**Files:**
- Create: `web/app/mu-plugins/stride-core/templates/admin/settings/tab-company.php`
- Modify: `web/app/mu-plugins/stride-core/templates/admin/settings.php`
- Modify: `web/app/mu-plugins/stride-core/assets/js/admin/settings.js`

- [ ] **Step 1: Add tab to settings nav**

In `templates/admin/settings.php`, update the `$tabs` array:

```php
$tabs = [
    'general'       => ['label' => 'Algemeen', 'icon' => 'dashicons-admin-generic'],
    'company'       => ['label' => 'Bedrijf', 'icon' => 'dashicons-building'],
    'profile-types' => ['label' => 'Profieltypes', 'icon' => 'dashicons-groups'],
];
```

- [ ] **Step 2: Add tab content section**

In `templates/admin/settings.php`, add after the Algemeen tab div and before the Profile Types tab div:

```php
<!-- Tab: Bedrijf -->
<div x-show="activeTab === 'company'" style="display: none;">
    <?php if (file_exists($templateDir . '/tab-company.php')): ?>
        <?php include $templateDir . '/tab-company.php'; ?>
    <?php endif; ?>
</div>
```

- [ ] **Step 3: Create tab-company.php template**

Create `web/app/mu-plugins/stride-core/templates/admin/settings/tab-company.php`:

```php
<?php
/**
 * Settings tab: Bedrijf (Company details)
 *
 * Alpine.js bindings — part of strideSettingsApp() component.
 * Used for PDF quote generation and email footers.
 *
 * @package stride
 */

defined('ABSPATH') || exit;
?>

<h2>Bedrijfsgegevens</h2>

<p class="description">
    Deze gegevens worden gebruikt op offertes (PDF) en in e-mails.
</p>

<table class="form-table" role="presentation">
    <tbody>
        <tr>
            <th scope="row">
                <label for="stride-company-name">Bedrijfsnaam <span class="required">*</span></label>
            </th>
            <td>
                <input type="text" id="stride-company-name" class="regular-text"
                       x-model="company.name" />
            </td>
        </tr>
        <tr>
            <th scope="row">
                <label for="stride-company-address">Adres</label>
            </th>
            <td>
                <input type="text" id="stride-company-address" class="regular-text"
                       x-model="company.address" />
            </td>
        </tr>
        <tr>
            <th scope="row">
                <label for="stride-company-postal">Postcode</label>
            </th>
            <td>
                <input type="text" id="stride-company-postal" class="small-text"
                       x-model="company.postal_code" />
            </td>
        </tr>
        <tr>
            <th scope="row">
                <label for="stride-company-city">Stad</label>
            </th>
            <td>
                <input type="text" id="stride-company-city" class="regular-text"
                       x-model="company.city" />
            </td>
        </tr>
        <tr>
            <th scope="row">
                <label for="stride-company-country">Land</label>
            </th>
            <td>
                <input type="text" id="stride-company-country" class="regular-text"
                       x-model="company.country" />
            </td>
        </tr>
        <tr>
            <th scope="row">
                <label for="stride-company-vat">BTW-nummer</label>
            </th>
            <td>
                <input type="text" id="stride-company-vat" class="regular-text"
                       x-model="company.vat"
                       placeholder="BE0123.456.789" />
            </td>
        </tr>
        <tr>
            <th scope="row">
                <label for="stride-company-email">E-mail</label>
            </th>
            <td>
                <input type="email" id="stride-company-email" class="regular-text"
                       x-model="company.email" />
            </td>
        </tr>
        <tr>
            <th scope="row">
                <label for="stride-company-phone">Telefoon</label>
            </th>
            <td>
                <input type="text" id="stride-company-phone" class="regular-text"
                       x-model="company.phone" />
            </td>
        </tr>
        <tr>
            <th scope="row">
                <label for="stride-company-bank">Bankrekening (IBAN)</label>
            </th>
            <td>
                <input type="text" id="stride-company-bank" class="regular-text"
                       x-model="company.bank_account"
                       placeholder="BE00 0000 0000 0000" />
            </td>
        </tr>
    </tbody>
</table>

<p class="submit">
    <button type="button"
            class="button button-primary"
            :disabled="saving || !company.name?.trim()"
            @click="saveCompany()">
        <span x-show="!saving">Opslaan</span>
        <span x-show="saving">Bezig met opslaan&hellip;</span>
    </button>
</p>
```

- [ ] **Step 4: Add company state and saveCompany() to settings.js**

In `assets/js/admin/settings.js`, add to the state (after `confirmDelete: null,`):

```javascript
// Company details
company: {
    name: '',
    address: '',
    postal_code: '',
    city: '',
    country: 'België',
    vat: '',
    email: '',
    phone: '',
    bank_account: '',
},
```

In `init()`, add after the profile types block:

```javascript
// Company tab
if (data.company) {
    this.company = { ...this.company, ...data.company };
}
```

Update the valid hash tabs check in `init()`:

```javascript
if (['general', 'company', 'profile-types'].includes(hash)) {
    this.activeTab = hash;
}
```

Add `saveCompany()` method after `saveGeneral()`:

```javascript
// =====================================================================
// Company Tab
// =====================================================================

/**
 * Save company details.
 */
async saveCompany() {
    this.saving = true;
    try {
        const result = await this.apiCall('stride_save_settings', {
            tab: 'company',
            ...this.company,
        });
        this.showMessage(result.message || 'Bedrijfsgegevens opgeslagen.');
    } catch (err) {
        this.showMessage(err.message || 'Opslaan mislukt.', 'error');
    } finally {
        this.saving = false;
    }
},
```

- [ ] **Step 5: Commit**

```bash
git add web/app/mu-plugins/stride-core/templates/admin/settings.php
git add web/app/mu-plugins/stride-core/templates/admin/settings/tab-company.php
git add web/app/mu-plugins/stride-core/assets/js/admin/settings.js
git commit -m "feat(settings): add Bedrijf tab with company details form"
```

---

### Task 3: PDF Template

Move and adapt the existing template from `bck/`.

**Files:**
- Create: `web/app/mu-plugins/stride-core/templates/pdf/quote.php`

- [ ] **Step 1: Create the templates/pdf directory and template**

Copy the template from `bck/stride/templates/pdf/quote.php` to `web/app/mu-plugins/stride-core/templates/pdf/quote.php` with these changes:

1. Replace `$this->formatCurrency()` calls (lines 409-410) with the `$formatCurrency` closure that will be passed by the generator:

Line 409 — change:
```php
<td class="amount"><?php echo esc_html($this->formatCurrency($item['unit_price'] ?? 0)); ?></td>
```
To:
```php
<td class="amount"><?php echo esc_html($formatCurrency($item['unit_price'] ?? 0)); ?></td>
```

Line 410 — change:
```php
<td class="amount"><?php echo esc_html($this->formatCurrency($item['total'] ?? 0)); ?></td>
```
To:
```php
<td class="amount"><?php echo esc_html($formatCurrency($item['total'] ?? 0)); ?></td>
```

2. Update the file header comment to reference `$formatCurrency` closure:
```php
 * - $formatCurrency: Closure (int $cents) => string, e.g. "€ 45,00"
```

3. Add a conditional discount row in the totals section (after the Subtotaal row, before the BTW row):
```php
<?php if (!empty($quote['discount']) && $quote['discount'] > 0): ?>
<tr>
    <td class="label"><?php esc_html_e('Korting', 'stride'); ?></td>
    <td class="value">-<?php echo esc_html($quote['discount_formatted'] ?? '€ 0,00'); ?></td>
</tr>
<?php endif; ?>
```

All other template code stays identical — it's already well-built with defensive defaults, proper escaping, and professional styling.

- [ ] **Step 2: Commit**

```bash
git add web/app/mu-plugins/stride-core/templates/pdf/quote.php
git commit -m "feat(pdf): add quote PDF template (adapted from bck/)"
```

---

### Task 4: QuotePDFGenerator Service

The core generator that renders HTML to PDF via DOMPDF.

**Files:**
- Create: `web/app/mu-plugins/stride-core/Modules/Invoicing/QuotePDFGenerator.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/QuotePDFGeneratorTest.php`:

```php
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
        $this->generator = new QuotePDFGenerator();
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
        // Set company option
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
        $user = $this->createUser([
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
        // The relative path should be relative to WP_CONTENT_DIR
        $fullPath = WP_CONTENT_DIR . '/uploads/stride-quotes/OFF-2026-0001.pdf';
        $relative = $this->invokeMethod($this->generator, 'getRelativePath', [$fullPath]);

        $this->assertEquals('uploads/stride-quotes/OFF-2026-0001.pdf', $relative);
    }

    /** @test */
    public function testGenerateReturnsWpErrorWhenQuoteServiceNotAvailable(): void
    {
        // QuoteService not registered in test container → ntdst_get returns null
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
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
ddev exec vendor/bin/phpunit --filter QuotePDFGeneratorTest --testsuite Unit
```

Expected: FAIL — class QuotePDFGenerator does not exist.

- [ ] **Step 3: Create QuotePDFGenerator service**

Create `web/app/mu-plugins/stride-core/Modules/Invoicing/QuotePDFGenerator.php`:

```php
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

    public function __construct()
    {
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
        $model = ntdst_data()->get(QuoteCPT::POST_TYPE);
        $pdfPath = $model->getMeta($quoteId, 'pdf_path');

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
        $quoteService = ntdst_get(QuoteService::class);
        $quote = $quoteService->getQuote($quoteId, true);

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
        $model = ntdst_data()->get(QuoteCPT::POST_TYPE);
        $model->updateMeta($quoteId, 'pdf_path', $relativePath);

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
            'company'             => StrideSettingsService::getCompanyDetails(),
        ];
    }

    /**
     * Render the PHP template to HTML string.
     */
    private function renderTemplate(array $quote): string|false
    {
        $templatePath = dirname(__DIR__, 2) . '/templates/pdf/quote.php';

        if (!file_exists($templatePath)) {
            ntdst_log('invoicing')->error('PDF template not found', [
                'path' => $templatePath,
            ]);
            return false;
        }

        // Provide formatCurrency closure for the template
        $formatCurrency = fn(int $cents): string => Money::cents(abs($cents))->format();

        ob_start();
        include $templatePath;
        return ob_get_clean();
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
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
ddev exec vendor/bin/phpunit --filter QuotePDFGeneratorTest --testsuite Unit
```

Expected: All tests PASS.

- [ ] **Step 5: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Invoicing/QuotePDFGenerator.php
git add tests/Unit/QuotePDFGeneratorTest.php
git commit -m "feat(pdf): add QuotePDFGenerator service with unit tests"
```

---

### Task 5: Wire Generator into QuoteService

Instantiate the generator and trigger auto-generation on quote creation.

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Modules/Invoicing/QuoteService.php`

- [ ] **Step 1: Instantiate generator in init()**

In `QuoteService::init()`, add after the VoucherService creation block (line ~48):

```php
// PDF generator (registers own hooks)
new QuotePDFGenerator();
```

- [ ] **Step 2: Add auto-generation on quote creation**

In `QuoteService::createQuote()`, add after the `dispatch('quote/created', ...)` call (around line 393), before `return $quoteId;`:

```php
// Auto-generate PDF
do_action('stride/quote/regenerate_pdf', $quoteId);
```

- [ ] **Step 3: Run full test suite to verify no regressions**

```bash
ddev exec vendor/bin/phpunit --testsuite Unit
```

Expected: All unit tests PASS.

- [ ] **Step 4: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Invoicing/QuoteService.php
git commit -m "feat(pdf): wire QuotePDFGenerator into QuoteService lifecycle"
```

---

### Task 6: Stubs for Unit Test Support

Add missing WP function stubs needed by QuotePDFGenerator. These are confirmed missing from the current `wordpress-stubs.php`: `wp_upload_dir()`, `wp_mkdir_p()`, `date_i18n()`. Also need `updateMeta()` on the data manager stub in `stride-infrastructure-stubs.php`.

**Note:** This task should be done BEFORE Task 4 step 4 (running tests), so the stubs are in place. Ordering here is logical grouping; implementor should add stubs first.

**Files:**
- Modify: `tests/Stubs/wordpress-stubs.php`
- Modify: `tests/Stubs/stride-infrastructure-stubs.php`

- [ ] **Step 1: Add missing WP function stubs to wordpress-stubs.php**

Add to `tests/Stubs/wordpress-stubs.php`:

```php
if (!function_exists('wp_upload_dir')) {
    function wp_upload_dir() {
        return [
            'basedir' => WP_CONTENT_DIR . '/uploads',
            'baseurl' => '/wp-content/uploads',
        ];
    }
}

if (!function_exists('wp_mkdir_p')) {
    function wp_mkdir_p(string $target): bool {
        if (is_dir($target)) return true;
        return mkdir($target, 0755, true);
    }
}

if (!function_exists('date_i18n')) {
    function date_i18n(string $format, $timestamp = false): string {
        if ($timestamp === false) $timestamp = time();
        return date($format, $timestamp);
    }
}
```

- [ ] **Step 2: Add updateMeta to data manager stub**

In `tests/Stubs/stride-infrastructure-stubs.php`, find the anonymous class returned by `ntdst_data()->get()` and add this method alongside existing `getMeta` and `updateMetaBatch`:

```php
public function updateMeta(int $postId, string $key, mixed $value): bool
{
    global $_test_data_manager_meta;
    if (!isset($_test_data_manager_meta[$this->postType])) {
        $_test_data_manager_meta[$this->postType] = [];
    }
    if (!isset($_test_data_manager_meta[$this->postType][$postId])) {
        $_test_data_manager_meta[$this->postType][$postId] = [];
    }
    $_test_data_manager_meta[$this->postType][$postId][$key] = $value;
    return true;
}
```

- [ ] **Step 3: Run tests to verify stubs work**

```bash
ddev exec vendor/bin/phpunit --filter QuotePDFGeneratorTest --testsuite Unit
```

Expected: All tests PASS.

- [ ] **Step 4: Commit**

```bash
git add tests/Stubs/
git commit -m "test: add WP function stubs for QuotePDFGenerator tests"
```

---

### Task 7: Seed Company Details

Add VAD company details to the seed script for development.

**Files:**
- Modify: `scripts/seed.php`

- [ ] **Step 1: Add company details seeding**

In `scripts/seed.php`, add inside the main seed function, before the summary/credentials output section (look for the line `echo "=== Test Credentials ==="`). Add this block:

```php
// =========================================================================
// Company Details
// =========================================================================
echo "\n--- Company Details ---\n";

$companyDetails = [
    'name'         => 'VAD vzw',
    'address'      => 'Vanderlindenstraat 15',
    'postal_code'  => '1030',
    'city'         => 'Brussel',
    'country'      => 'België',
    'vat'          => 'BE0420.798.935',
    'email'        => 'info@vad.be',
    'phone'        => '+32 2 423 03 33',
    'bank_account' => 'BE68 0682 0553 5765',
];

update_option('stride_company_details', $companyDetails);
echo "  - Company details seeded: {$companyDetails['name']}\n";
```

- [ ] **Step 2: Commit**

```bash
git add scripts/seed.php
git commit -m "seed: add VAD company details for PDF generation"
```

---

## Verification Stages (MANDATORY)

> Run AFTER all implementation tasks. NOT done until all stages pass.
> If ANY stage fails: fix → re-run that stage → continue.

### Stage V1: Unit Tests

```bash
ddev exec vendor/bin/phpunit --testsuite Unit
```

Expected: ALL tests pass, including QuotePDFGeneratorTest.

### Stage V2: Integration Test — PDF Generation

```bash
# Seed the database first
ddev exec wp eval-file scripts/seed.php

# Test company settings are saved
ddev exec wp eval "
\$details = get_option('stride_company_details');
echo 'Company: ' . (\$details['name'] ?? 'NOT SET') . PHP_EOL;
echo 'Bank: ' . (\$details['bank_account'] ?? 'NOT SET') . PHP_EOL;
"
```

Expected:
```
Company: VAD vzw
Bank: BE68 0682 0553 5765
```

### Stage V3: Manual Smoke Test

```markdown
## Manual Smoke Test

- [ ] Visit: https://stride.ddev.site/wp/wp-admin/admin.php?page=stride-settings#company
      Expected: "Bedrijf" tab visible with VAD company details pre-filled (after seeding)
- [ ] Action: Edit company name, click "Opslaan"
      Expected: Success message "Bedrijfsgegevens opgeslagen.", value persists on page reload
- [ ] Admin: Open any existing quote in wp-admin → sidebar should show "Regenerate PDF" button
- [ ] Action: Click "Regenerate PDF"
      Expected: PDF link appears in sidebar, clicking opens professional quote PDF in new tab
- [ ] Database: `ddev exec wp eval "echo get_post_meta(<quote_id>, 'pdf_path', true);"`
      Expected: `uploads/stride-quotes/OFF-2026-XXXX.pdf`
- [ ] File: `ddev exec ls web/app/content/uploads/stride-quotes/`
      Expected: PDF file exists with correct quote number filename
```

### Stage V4: Full Regression

```bash
ddev exec vendor/bin/phpunit --testsuite Unit
```

Expected: Zero failures across all suites.
