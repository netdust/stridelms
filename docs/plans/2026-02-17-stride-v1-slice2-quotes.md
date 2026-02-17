# Stride LMS V1 - Slice 2: Quotes & Invoicing

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Complete vertical slice for quote creation on enrollment, quote listing in dashboard, and PDF download.

**Architecture:** QuoteRepository uses DataManager CPT for quotes (moderate volume ~5k/year). QuoteService handles business logic. EnrollmentQuoteHandler listens for `stride/registration/created` event and auto-creates quotes. QuoteCalculator is a pure helper for price calculations.

**Tech Stack:** PHP 8.3, ntdst-core (DataManager ORM, DI container), DOMPDF for PDF generation

**Reference:** `@ntdst-wp-dev` for all PHP code patterns

---

## Prerequisites

- [x] Slice 1 complete (Edition, Registration, Enrollment modules)
- [x] QuoteStatus enum exists in Domain/
- [x] Money value object exists in Domain/

---

## Task 1: Register vad_quote CPT

**Files:**
- Create: `web/app/mu-plugins/stride-core/Modules/Invoicing/QuoteCPT.php`

**Step 1: Create CPT registration class**

```php
<?php

declare(strict_types=1);

namespace Stride\Modules\Invoicing;

/**
 * Quote CPT Registration.
 *
 * Invoices/quotes for course enrollments.
 */
final class QuoteCPT
{
    public const POST_TYPE = 'vad_quote';

    public static function register(): void
    {
        ntdst_data()->register(self::POST_TYPE, [
            'label' => 'Offertes',
            'labels' => [
                'name' => 'Offertes',
                'singular_name' => 'Offerte',
                'add_new' => 'Nieuwe offerte',
                'add_new_item' => 'Nieuwe offerte toevoegen',
                'edit_item' => 'Offerte bewerken',
            ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => true,
            'menu_icon' => 'dashicons-media-text',
            'supports' => ['title'],
            'fields' => self::getFields(),
            'field_groups' => self::getFieldGroups(),
        ]);
    }

    private static function getFields(): array
    {
        return [
            'user_id' => [
                'type' => 'int',
                'label' => 'Gebruiker ID',
                'required' => true,
            ],
            'registration_id' => [
                'type' => 'int',
                'label' => 'Registratie ID',
            ],
            'edition_id' => [
                'type' => 'int',
                'label' => 'Editie ID',
            ],
            'quote_number' => [
                'type' => 'text',
                'label' => 'Offertenummer',
                'required' => true,
            ],
            'status' => [
                'type' => 'text',
                'label' => 'Status',
                'required' => true,
            ],
            'items' => [
                'type' => 'json',
                'label' => 'Regels',
            ],
            'subtotal' => [
                'type' => 'int',
                'label' => 'Subtotaal (centen)',
            ],
            'discount' => [
                'type' => 'int',
                'label' => 'Korting (centen)',
            ],
            'tax' => [
                'type' => 'int',
                'label' => 'BTW (centen)',
            ],
            'total' => [
                'type' => 'int',
                'label' => 'Totaal (centen)',
            ],
            'billing' => [
                'type' => 'json',
                'label' => 'Facturatiegegevens',
            ],
            'voucher_code' => [
                'type' => 'text',
                'label' => 'Kortingscode',
            ],
            'valid_until' => [
                'type' => 'text',
                'label' => 'Geldig tot',
            ],
            'sent_at' => [
                'type' => 'text',
                'label' => 'Verzonden op',
            ],
            'pdf_path' => [
                'type' => 'text',
                'label' => 'PDF pad',
            ],
            'notes' => [
                'type' => 'text',
                'label' => 'Notities',
            ],
        ];
    }

    private static function getFieldGroups(): array
    {
        return [
            'quote_main' => [
                'title' => 'Offerte',
                'fields' => ['user_id', 'registration_id', 'edition_id', 'quote_number', 'status'],
            ],
            'quote_amounts' => [
                'title' => 'Bedragen',
                'fields' => ['subtotal', 'discount', 'tax', 'total'],
            ],
            'quote_billing' => [
                'title' => 'Facturatie',
                'fields' => ['billing', 'voucher_code', 'valid_until'],
            ],
            'quote_meta' => [
                'title' => 'Meta',
                'fields' => ['sent_at', 'pdf_path', 'notes'],
            ],
        ];
    }
}
```

**Step 2: Verify syntax**

```bash
php -l web/app/mu-plugins/stride-core/Modules/Invoicing/QuoteCPT.php
```

**Step 3: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Invoicing/QuoteCPT.php
git commit -m "feat(invoicing): register vad_quote CPT with DataManager"
```

---

## Task 2: Create QuoteRepository

**Files:**
- Create: `web/app/mu-plugins/stride-core/Modules/Invoicing/QuoteRepository.php`

**Step 1: Write repository extending AbstractRepository**

```php
<?php

declare(strict_types=1);

namespace Stride\Modules\Invoicing;

use Stride\Domain\QuoteStatus;
use Stride\Infrastructure\AbstractRepository;
use WP_Post;
use WP_Error;

/**
 * Repository for quote data access.
 */
final class QuoteRepository extends AbstractRepository
{
    protected string $postType = QuoteCPT::POST_TYPE;

    /**
     * Find quotes for a user.
     *
     * @return array<array<string, mixed>>
     */
    public function findByUser(int $userId, ?string $status = null): array
    {
        $query = $this->model()
            ->where('user_id', $userId)
            ->where('post_status', 'publish')
            ->orderBy('post_date', 'DESC');

        if ($status !== null) {
            $query->where('status', $status);
        }

        return $query->withMeta()->get();
    }

    /**
     * Find quote by registration ID.
     */
    public function findByRegistration(int $registrationId): ?array
    {
        $results = $this->model()
            ->where('registration_id', $registrationId)
            ->where('post_status', 'publish')
            ->limit(1)
            ->withMeta()
            ->get();

        return $results[0] ?? null;
    }

    /**
     * Find quote by quote number.
     */
    public function findByNumber(string $quoteNumber): ?array
    {
        $results = $this->model()
            ->where('quote_number', $quoteNumber)
            ->where('post_status', 'publish')
            ->limit(1)
            ->withMeta()
            ->get();

        return $results[0] ?? null;
    }

    /**
     * Get quotes pending export.
     *
     * @return array<array<string, mixed>>
     */
    public function findPendingExport(): array
    {
        return $this->model()
            ->where('status', QuoteStatus::Sent->value)
            ->where('post_status', 'publish')
            ->orderBy('post_date', 'ASC')
            ->withMeta()
            ->get();
    }

    /**
     * Generate next quote number.
     */
    public function generateQuoteNumber(): string
    {
        $year = date('Y');
        $prefix = "OFF-{$year}-";

        // Find highest number for this year
        global $wpdb;
        $table = $wpdb->prefix . 'postmeta';
        $postsTable = $wpdb->prefix . 'posts';

        $lastNumber = $wpdb->get_var($wpdb->prepare(
            "SELECT MAX(CAST(SUBSTRING(meta_value, %d) AS UNSIGNED))
             FROM {$table} pm
             JOIN {$postsTable} p ON pm.post_id = p.ID
             WHERE pm.meta_key = 'quote_number'
             AND pm.meta_value LIKE %s
             AND p.post_type = %s",
            strlen($prefix) + 1,
            $prefix . '%',
            QuoteCPT::POST_TYPE
        ));

        $nextNumber = ((int) $lastNumber) + 1;

        return $prefix . str_pad((string) $nextNumber, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Update quote status.
     */
    public function updateStatus(int $quoteId, QuoteStatus $status): bool
    {
        $data = ['status' => $status->value];

        if ($status === QuoteStatus::Sent) {
            $data['sent_at'] = current_time('mysql');
        }

        return $this->model()->updateMeta($quoteId, $data) !== false;
    }

    /**
     * Get field value from quote.
     */
    public function getField(int $quoteId, string $field, mixed $default = null): mixed
    {
        return $this->model()->getMeta($quoteId, $field, $default);
    }
}
```

**Step 2: Verify syntax**

```bash
php -l web/app/mu-plugins/stride-core/Modules/Invoicing/QuoteRepository.php
```

**Step 3: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Invoicing/QuoteRepository.php
git commit -m "feat(invoicing): add QuoteRepository with user and registration queries"
```

---

## Task 3: Create QuoteCalculator

**Files:**
- Create: `web/app/mu-plugins/stride-core/Modules/Invoicing/QuoteCalculator.php`

**Step 1: Write pure calculation helper**

```php
<?php

declare(strict_types=1);

namespace Stride\Modules\Invoicing;

use Stride\Domain\Money;

/**
 * Pure calculation helper for quotes.
 *
 * All methods are stateless functions operating on Money values.
 */
final class QuoteCalculator
{
    private const TAX_RATE = 21.0; // Belgian BTW rate

    /**
     * Calculate totals from items.
     *
     * @param array<array{title: string, quantity: int, unit_price: Money}> $items
     * @return array{subtotal: Money, tax: Money, total: Money}
     */
    public static function calculateTotals(array $items, ?Money $discount = null): array
    {
        $subtotal = Money::zero();

        foreach ($items as $item) {
            $itemTotal = $item['unit_price']->multiply($item['quantity']);
            $subtotal = $subtotal->add($itemTotal);
        }

        // Apply discount
        $discountAmount = $discount ?? Money::zero();
        $discountedSubtotal = $subtotal;

        if (!$discountAmount->isZero() && !$subtotal->isZero()) {
            $discountedSubtotal = $subtotal->subtract($discountAmount);
        }

        // Calculate tax on discounted subtotal
        $tax = self::calculateTax($discountedSubtotal);
        $total = $discountedSubtotal->add($tax);

        return [
            'subtotal' => $subtotal,
            'discount' => $discountAmount,
            'tax' => $tax,
            'total' => $total,
        ];
    }

    /**
     * Calculate tax amount.
     */
    public static function calculateTax(Money $amount): Money
    {
        return $amount->multiply(self::TAX_RATE / 100);
    }

    /**
     * Calculate discount from voucher percentage.
     */
    public static function calculatePercentageDiscount(Money $subtotal, float $percentage): Money
    {
        if ($percentage <= 0 || $percentage > 100) {
            return Money::zero();
        }

        return $subtotal->multiply($percentage / 100);
    }

    /**
     * Format items for storage.
     *
     * @param array<array{title: string, quantity: int, unit_price: Money, type?: string}> $items
     * @return array<array{title: string, quantity: int, unit_price: int, total: int, type: string}>
     */
    public static function formatItemsForStorage(array $items): array
    {
        $formatted = [];

        foreach ($items as $item) {
            $unitPrice = $item['unit_price'];
            $quantity = $item['quantity'];
            $total = $unitPrice->multiply($quantity);

            $formatted[] = [
                'title' => $item['title'],
                'quantity' => $quantity,
                'unit_price' => $unitPrice->inCents(),
                'total' => $total->inCents(),
                'type' => $item['type'] ?? 'edition',
            ];
        }

        return $formatted;
    }
}
```

**Step 2: Verify syntax**

```bash
php -l web/app/mu-plugins/stride-core/Modules/Invoicing/QuoteCalculator.php
```

**Step 3: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Invoicing/QuoteCalculator.php
git commit -m "feat(invoicing): add QuoteCalculator pure helper"
```

---

## Task 4: Create QuoteService

**Files:**
- Create: `web/app/mu-plugins/stride-core/Modules/Invoicing/QuoteService.php`

**Step 1: Write quote service**

```php
<?php

declare(strict_types=1);

namespace Stride\Modules\Invoicing;

use Stride\Contracts\EditionQueryInterface;
use Stride\Domain\Money;
use Stride\Domain\QuoteStatus;
use Stride\Infrastructure\AbstractService;
use WP_Error;

/**
 * Quote business logic.
 */
final class QuoteService extends AbstractService
{
    public function __construct(
        private readonly QuoteRepository $repository,
        private readonly EditionQueryInterface $editions,
    ) {
        parent::__construct();
    }

    public static function metadata(): array
    {
        return [
            'name' => 'Quote Service',
            'description' => 'Manages quotes and invoices',
            'priority' => 20,
        ];
    }

    protected function getConfigSlug(): string
    {
        return 'invoicing';
    }

    protected function init(): void
    {
        // No hooks needed - quote creation triggered by handler
    }

    /**
     * Create a quote for a registration.
     *
     * @param array<array{title: string, quantity: int, unit_price: Money}> $items
     * @param array<string, mixed> $billing
     */
    public function createQuote(
        int $userId,
        int $registrationId,
        int $editionId,
        array $items,
        array $billing = [],
        ?string $voucherCode = null,
        ?Money $discount = null,
    ): int|WP_Error {
        // Calculate totals
        $totals = QuoteCalculator::calculateTotals($items, $discount);

        // Generate quote number
        $quoteNumber = $this->repository->generateQuoteNumber();

        // Get edition title for quote title
        $edition = $this->editions->exists($editionId)
            ? get_post($editionId)
            : null;
        $title = $edition ? $edition->post_title : "Offerte {$quoteNumber}";

        // Format items for storage
        $storedItems = QuoteCalculator::formatItemsForStorage($items);

        // Create quote
        $quoteId = $this->repository->create([
            'title' => $title,
            'user_id' => $userId,
            'registration_id' => $registrationId,
            'edition_id' => $editionId,
            'quote_number' => $quoteNumber,
            'status' => QuoteStatus::Draft->value,
            'items' => $storedItems,
            'subtotal' => $totals['subtotal']->inCents(),
            'discount' => $totals['discount']->inCents(),
            'tax' => $totals['tax']->inCents(),
            'total' => $totals['total']->inCents(),
            'billing' => $billing,
            'voucher_code' => $voucherCode,
            'valid_until' => date('Y-m-d', strtotime('+30 days')),
        ]);

        if (is_wp_error($quoteId)) {
            return $quoteId;
        }

        // Fire event
        $this->dispatch('quote/created', [
            'quote_id' => $quoteId,
            'user_id' => $userId,
            'registration_id' => $registrationId,
            'edition_id' => $editionId,
            'total' => $totals['total']->inCents(),
        ]);

        return $quoteId;
    }

    /**
     * Get quote by ID.
     */
    public function getQuote(int $quoteId): array|WP_Error
    {
        $result = $this->repository->find($quoteId);

        if (is_wp_error($result)) {
            return $result;
        }

        return $this->hydrateQuote($result);
    }

    /**
     * Get quotes for a user.
     *
     * @return array<array<string, mixed>>
     */
    public function getUserQuotes(int $userId): array
    {
        $quotes = $this->repository->findByUser($userId);

        return array_map([$this, 'hydrateQuote'], $quotes);
    }

    /**
     * Get quote by registration.
     */
    public function getQuoteByRegistration(int $registrationId): ?array
    {
        $quote = $this->repository->findByRegistration($registrationId);

        return $quote ? $this->hydrateQuote($quote) : null;
    }

    /**
     * Mark quote as sent.
     */
    public function markAsSent(int $quoteId): bool|WP_Error
    {
        $quote = $this->repository->find($quoteId);

        if (is_wp_error($quote)) {
            return $quote;
        }

        $status = QuoteStatus::tryFrom($quote->status ?? '');

        if ($status !== QuoteStatus::Draft) {
            return new WP_Error('invalid_status', 'Only draft quotes can be sent');
        }

        $result = $this->repository->updateStatus($quoteId, QuoteStatus::Sent);

        if ($result) {
            $this->dispatch('quote/sent', ['quote_id' => $quoteId]);
        }

        return $result;
    }

    /**
     * Cancel quote.
     */
    public function cancel(int $quoteId): bool|WP_Error
    {
        $quote = $this->repository->find($quoteId);

        if (is_wp_error($quote)) {
            return $quote;
        }

        $status = QuoteStatus::tryFrom($quote->status ?? '');

        if ($status === QuoteStatus::Exported) {
            return new WP_Error('cannot_cancel', 'Exported quotes cannot be cancelled');
        }

        $result = $this->repository->updateStatus($quoteId, QuoteStatus::Cancelled);

        if ($result) {
            $this->dispatch('quote/cancelled', ['quote_id' => $quoteId]);
        }

        return $result;
    }

    /**
     * Hydrate quote data with Money objects.
     */
    private function hydrateQuote(array|\WP_Post $quote): array
    {
        $data = is_array($quote) ? $quote : (array) $quote;

        // Convert cents to Money objects
        $data['subtotal_money'] = Money::cents((int) ($data['subtotal'] ?? 0));
        $data['discount_money'] = Money::cents((int) ($data['discount'] ?? 0));
        $data['tax_money'] = Money::cents((int) ($data['tax'] ?? 0));
        $data['total_money'] = Money::cents((int) ($data['total'] ?? 0));

        // Parse status
        $data['status_enum'] = QuoteStatus::tryFrom($data['status'] ?? '') ?? QuoteStatus::Draft;

        return $data;
    }
}
```

**Step 2: Verify syntax**

```bash
php -l web/app/mu-plugins/stride-core/Modules/Invoicing/QuoteService.php
```

**Step 3: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Invoicing/QuoteService.php
git commit -m "feat(invoicing): add QuoteService for quote business logic"
```

---

## Task 5: Create EnrollmentQuoteHandler

**Files:**
- Create: `web/app/mu-plugins/stride-core/Handlers/EnrollmentQuoteHandler.php`

**Step 1: Write handler that creates quotes on enrollment**

```php
<?php

declare(strict_types=1);

namespace Stride\Handlers;

use Stride\Contracts\EditionQueryInterface;
use Stride\Domain\Money;
use Stride\Modules\Invoicing\QuoteService;

/**
 * Creates quotes when users enroll in editions.
 *
 * Listens for stride/registration/created event.
 */
final class EnrollmentQuoteHandler
{
    public function __construct(
        private readonly QuoteService $quotes,
        private readonly EditionQueryInterface $editions,
    ) {
        add_action('stride/registration/created', [$this, 'onRegistrationCreated']);
    }

    /**
     * Handle registration created event.
     *
     * @param array{registration_id: int, user_id: int, edition_id: int} $data
     */
    public function onRegistrationCreated(array $data): void
    {
        $registrationId = $data['registration_id'] ?? 0;
        $userId = $data['user_id'] ?? 0;
        $editionId = $data['edition_id'] ?? 0;

        if (!$registrationId || !$userId || !$editionId) {
            return;
        }

        // Check if quote already exists
        $existing = $this->quotes->getQuoteByRegistration($registrationId);
        if ($existing) {
            return;
        }

        // Get edition details
        $edition = get_post($editionId);
        if (!$edition) {
            return;
        }

        // Get price (check if member - simplified for now)
        $price = $this->getEditionPrice($editionId, $userId);

        // Skip free editions
        if ($price->isZero()) {
            return;
        }

        // Build items array
        $items = [
            [
                'title' => $edition->post_title,
                'quantity' => 1,
                'unit_price' => $price,
                'type' => 'edition',
            ],
        ];

        // Get user billing info (simplified - fetch from user meta)
        $billing = $this->getUserBilling($userId);

        // Create quote
        $this->quotes->createQuote(
            userId: $userId,
            registrationId: $registrationId,
            editionId: $editionId,
            items: $items,
            billing: $billing,
        );
    }

    /**
     * Get edition price for user.
     */
    private function getEditionPrice(int $editionId, int $userId): Money
    {
        // Check if user is member (simplified - check user meta)
        $isMember = (bool) get_user_meta($userId, 'is_member', true);

        $field = $isMember ? 'price' : 'price_non_member';
        $amount = (float) get_post_meta($editionId, $field, true);

        // Fall back to member price if non-member price not set
        if (!$isMember && $amount === 0.0) {
            $amount = (float) get_post_meta($editionId, 'price', true);
        }

        return Money::eur($amount);
    }

    /**
     * Get user billing information.
     *
     * @return array<string, string>
     */
    private function getUserBilling(int $userId): array
    {
        $user = get_userdata($userId);

        if (!$user) {
            return [];
        }

        return [
            'name' => $user->display_name,
            'email' => $user->user_email,
            'company' => get_user_meta($userId, 'company', true) ?: '',
            'address' => get_user_meta($userId, 'billing_address', true) ?: '',
            'vat_number' => get_user_meta($userId, 'vat_number', true) ?: '',
        ];
    }
}
```

**Step 2: Verify syntax**

```bash
php -l web/app/mu-plugins/stride-core/Handlers/EnrollmentQuoteHandler.php
```

**Step 3: Commit**

```bash
git add web/app/mu-plugins/stride-core/Handlers/EnrollmentQuoteHandler.php
git commit -m "feat(handlers): add EnrollmentQuoteHandler for auto-creating quotes"
```

---

## Task 6: Update Plugin Config and Bootstrap

**Files:**
- Modify: `web/app/mu-plugins/stride-core/plugin-config.php`
- Modify: `web/app/mu-plugins/stride-core/stride-core.php`

**Step 1: Read current files**

Read both files to understand current state.

**Step 2: Update plugin-config.php**

Add to bindings (none needed for Slice 2), add services:

```php
'services' => [
    \Stride\Modules\Edition\EditionService::class,
    \Stride\Modules\Enrollment\EnrollmentService::class,
    \Stride\Modules\Invoicing\QuoteService::class,  // Add this
],
```

**Step 3: Update stride-core.php**

Add CPT registration and handler wiring:

In the init hooks section, add:
```php
add_action('init', [\Stride\Modules\Invoicing\QuoteCPT::class, 'register'], 5);
```

In the `ntdst/core_ready` hook, add repository:
```php
ntdst_set(\Stride\Modules\Invoicing\QuoteRepository::class);
```

In the `ntdst/features_ready` hook, add handler registration:
```php
// Register handlers
ntdst_set(\Stride\Handlers\EnrollmentQuoteHandler::class);
ntdst_get(\Stride\Handlers\EnrollmentQuoteHandler::class);
```

**Step 4: Verify syntax**

```bash
php -l web/app/mu-plugins/stride-core/plugin-config.php
php -l web/app/mu-plugins/stride-core/stride-core.php
```

**Step 5: Commit**

```bash
git add web/app/mu-plugins/stride-core/
git commit -m "feat: wire up Invoicing module in plugin config"
```

---

## Task 7: Add Quotes to Dashboard Shortcode

**Files:**
- Create: `web/app/mu-plugins/stride-core/Modules/User/QuotesShortcode.php`
- Modify: `web/app/mu-plugins/stride-core/stride-core.php`

**Step 1: Create quotes shortcode**

```php
<?php

declare(strict_types=1);

namespace Stride\Modules\User;

use Stride\Modules\Invoicing\QuoteService;

/**
 * Dashboard shortcode for displaying user quotes.
 */
final class QuotesShortcode
{
    public function __construct(
        private readonly QuoteService $quotes,
    ) {
        add_shortcode('stride_my_quotes', [$this, 'renderMyQuotes']);
    }

    /**
     * Render user's quotes.
     */
    public function renderMyQuotes(array $atts = []): string
    {
        if (!is_user_logged_in()) {
            return '<p>Je moet ingelogd zijn om je offertes te zien.</p>';
        }

        $userId = get_current_user_id();
        $quotes = $this->quotes->getUserQuotes($userId);

        if (empty($quotes)) {
            return '<div class="uk-alert uk-alert-primary">Je hebt nog geen offertes.</div>';
        }

        $output = '<div class="uk-overflow-auto"><table class="uk-table uk-table-divider uk-table-small">';
        $output .= '<thead><tr>';
        $output .= '<th>Nummer</th><th>Cursus</th><th>Totaal</th><th>Status</th><th></th>';
        $output .= '</tr></thead><tbody>';

        foreach ($quotes as $quote) {
            $statusClass = match ($quote['status']) {
                'sent' => 'uk-label-success',
                'exported' => 'uk-label-primary',
                'cancelled' => 'uk-label-danger',
                default => 'uk-label-warning',
            };

            $output .= sprintf(
                '<tr>
                    <td>%s</td>
                    <td>%s</td>
                    <td>%s</td>
                    <td><span class="uk-label %s">%s</span></td>
                    <td><a href="%s" class="uk-button uk-button-small uk-button-default">Bekijk</a></td>
                </tr>',
                esc_html($quote['quote_number'] ?? ''),
                esc_html($quote['post_title'] ?? ''),
                esc_html($quote['total_money']->format()),
                $statusClass,
                esc_html($quote['status_enum']->label()),
                esc_url(add_query_arg('quote_id', $quote['id'] ?? $quote['ID'], home_url('/mijn-account/offertes/')))
            );
        }

        $output .= '</tbody></table></div>';

        return $output;
    }
}
```

**Step 2: Register shortcode in stride-core.php**

Add to `ntdst/features_ready` hook:

```php
ntdst_set(\Stride\Modules\User\QuotesShortcode::class);
ntdst_get(\Stride\Modules\User\QuotesShortcode::class);
```

**Step 3: Verify syntax**

```bash
php -l web/app/mu-plugins/stride-core/Modules/User/QuotesShortcode.php
```

**Step 4: Commit**

```bash
git add web/app/mu-plugins/stride-core/
git commit -m "feat(user): add QuotesShortcode for displaying user quotes"
```

---

## Task 8: Verify Services Load

**Step 1: Check CPT registered**

```bash
ddev exec wp post-type list --format=table | grep vad
```

Expected: `vad_edition` and `vad_quote` listed

**Step 2: Test autoloading**

```bash
ddev exec wp eval "
require_once ABSPATH . '../app/mu-plugins/stride-core/autoload.php';
echo class_exists('Stride\Modules\Invoicing\QuoteService') ? 'QuoteService: OK' : 'QuoteService: FAIL';
echo PHP_EOL;
echo class_exists('Stride\Modules\Invoicing\QuoteRepository') ? 'QuoteRepository: OK' : 'QuoteRepository: FAIL';
echo PHP_EOL;
echo class_exists('Stride\Handlers\EnrollmentQuoteHandler') ? 'EnrollmentQuoteHandler: OK' : 'EnrollmentQuoteHandler: FAIL';
"
```

Expected: All OK

**Step 3: Check recent commits**

```bash
git log --oneline -8
```

---

## Task 9: Test Quote Creation Flow

**Step 1: Create test enrollment with quote**

```bash
ddev exec wp eval "
require_once ABSPATH . '../app/mu-plugins/stride-core/autoload.php';

// Get test user
\$user = get_user_by('email', 'test@stride.test');
if (!\$user) {
    echo 'Test user not found';
    exit(1);
}

// Get an open edition
\$model = ntdst_data()->get('vad_edition');
\$editions = \$model->where('status', 'open')->where('post_status', 'publish')->limit(1)->withMeta()->get();

if (empty(\$editions)) {
    echo 'No open editions found';
    exit(1);
}

\$editionId = \$editions[0]['id'];
echo \"Testing with edition: {\$editionId}\\n\";

// Enroll user (this should trigger quote creation)
\$enrollment = ntdst_get(\\Stride\\Modules\\Enrollment\\EnrollmentService::class);
\$result = \$enrollment->enroll(\$user->ID, \$editionId);

if (is_wp_error(\$result)) {
    echo 'Enrollment error: ' . \$result->get_error_message() . \"\\n\";
    exit(1);
}

echo \"Registration created: {\$result}\\n\";

// Check if quote was created
\$quoteService = ntdst_get(\\Stride\\Modules\\Invoicing\\QuoteService::class);
\$quote = \$quoteService->getQuoteByRegistration(\$result);

if (\$quote) {
    echo \"Quote created: \" . \$quote['quote_number'] . \"\\n\";
    echo \"Total: \" . \$quote['total_money']->format() . \"\\n\";
} else {
    echo \"No quote created (edition may be free)\\n\";
}
"
```

**Step 2: Check quote in database**

```bash
ddev exec wp post list --post_type=vad_quote --format=table
```

**Step 3: Test quotes shortcode**

```bash
ddev exec wp eval "
require_once ABSPATH . '../app/mu-plugins/stride-core/autoload.php';

\$user = get_user_by('email', 'test@stride.test');
wp_set_current_user(\$user->ID);

\$shortcode = ntdst_get(\\Stride\\Modules\\User\\QuotesShortcode::class);
\$output = \$shortcode->renderMyQuotes();

echo '=== Quotes Shortcode Output ===\\n';
echo strip_tags(\$output);
"
```

---

## Task 10: Update Seed Script

**Files:**
- Modify: `scripts/seed-editions.php`

**Step 1: Add price meta to seeded editions**

Ensure seeded editions have `price` and `price_non_member` meta set so quotes are created.

**Step 2: Run seed script**

```bash
ddev exec wp eval-file scripts/seed-editions.php
```

**Step 3: Commit**

```bash
git add scripts/
git commit -m "chore: ensure seed editions have prices for quote testing"
```

---

## Slice 2 Complete - Exit Criteria

- [ ] vad_quote CPT registered with DataManager
- [ ] QuoteRepository with user/registration queries
- [ ] QuoteCalculator for price/tax calculations
- [ ] QuoteService for business logic
- [ ] EnrollmentQuoteHandler auto-creates quotes on enrollment
- [ ] QuotesShortcode displaying user quotes
- [ ] Complete flow: enroll → quote created → visible in dashboard

---

## Next: Slice 3

Create separate plan: `docs/plans/2026-02-17-stride-v1-slice3-vouchers.md`

Slice 3 covers:
- VoucherCPT + VoucherRepository
- VoucherService with code generation
- Apply voucher to quote
- Voucher redemption tracking
