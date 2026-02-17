# Stride LMS V1 - Slice 3: Vouchers (Minimal Base)

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Minimal vertical slice for voucher creation, validation, and discount application to quotes.

**Architecture:** VoucherCPT uses DataManager for CPT registration. VoucherRepository handles data access. VoucherCodeGenerator is a pure helper for code generation. VoucherService contains minimal business logic (create, validate, redeem). Integration via `stride/quote/apply_voucher` event.

**Tech Stack:** PHP 8.3, ntdst-core (DataManager ORM, DI container)

**Reference:** `@ntdst-wp-dev` for all PHP code patterns

**Scope (Minimal V1):**
- Single-use vouchers with percentage/fixed/full discount types
- Basic validation (code exists, not used, within date range)
- Apply voucher to quote (store voucher_code, calculate discount)
- Redemption tracking

**Deferred (Later Slices):**
- Batch creation (admin bulk generate)
- Category-specific logic (DAY proration, SOCIAL 50%, MEMBER limits)
- API endpoints for AJAX validation
- Rate limiting
- Admin UI for voucher management

---

## Prerequisites

- [x] Slice 2 complete (Quote modules)
- [x] Money value object exists in Domain/
- [x] VoucherStatus enum needed in Domain/

---

## Task 1: Create VoucherStatus Enum

**Files:**
- Create: `web/app/mu-plugins/stride-core/Domain/VoucherStatus.php`

**Step 1: Create enum**

```php
<?php

declare(strict_types=1);

namespace Stride\Domain;

/**
 * Voucher status values.
 */
enum VoucherStatus: string
{
    case Active = 'active';
    case Exhausted = 'exhausted';
    case Expired = 'expired';
    case Disabled = 'disabled';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Actief',
            self::Exhausted => 'Uitgeput',
            self::Expired => 'Verlopen',
            self::Disabled => 'Uitgeschakeld',
        };
    }
}
```

**Step 2: Commit**

```bash
git add web/app/mu-plugins/stride-core/Domain/VoucherStatus.php
git commit -m "feat: add VoucherStatus enum"
```

---

## Task 2: Create DiscountType Enum

**Files:**
- Create: `web/app/mu-plugins/stride-core/Domain/DiscountType.php`

**Step 1: Create enum**

```php
<?php

declare(strict_types=1);

namespace Stride\Domain;

/**
 * Discount type values.
 */
enum DiscountType: string
{
    case Full = 'full';           // 100% discount
    case Fixed = 'fixed';         // Fixed amount (e.g., €50 off)
    case Percentage = 'percentage'; // Percentage (e.g., 20% off)

    public function label(): string
    {
        return match ($this) {
            self::Full => '100% korting',
            self::Fixed => 'Vast bedrag',
            self::Percentage => 'Percentage',
        };
    }
}
```

**Step 2: Commit**

```bash
git add web/app/mu-plugins/stride-core/Domain/DiscountType.php
git commit -m "feat: add DiscountType enum"
```

---

## Task 3: Register vad_voucher CPT

**Files:**
- Create: `web/app/mu-plugins/stride-core/Modules/Invoicing/VoucherCPT.php`

**Step 1: Create CPT registration class**

```php
<?php

declare(strict_types=1);

namespace Stride\Modules\Invoicing;

use Stride\Domain\DiscountType;
use Stride\Domain\VoucherStatus;

/**
 * Voucher CPT Registration.
 *
 * Discount codes for course enrollments.
 */
final class VoucherCPT
{
    public const POST_TYPE = 'vad_voucher';

    public static function register(): void
    {
        ntdst_data()->register(self::POST_TYPE, [
            'label' => 'Vouchers',
            'labels' => [
                'name' => 'Vouchers',
                'singular_name' => 'Voucher',
                'add_new' => 'Nieuwe voucher',
                'add_new_item' => 'Nieuwe voucher toevoegen',
                'edit_item' => 'Voucher bewerken',
            ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => true,
            'menu_icon' => 'dashicons-tickets-alt',
            'supports' => ['title'],
            'fields' => self::getFields(),
            'field_groups' => self::getFieldGroups(),
        ]);
    }

    private static function getFields(): array
    {
        return [
            'code' => [
                'type' => 'text',
                'label' => 'Vouchercode',
                'required' => true,
            ],
            'discount_type' => [
                'type' => 'select',
                'label' => 'Kortingstype',
                'options' => [
                    DiscountType::Full->value => DiscountType::Full->label(),
                    DiscountType::Fixed->value => DiscountType::Fixed->label(),
                    DiscountType::Percentage->value => DiscountType::Percentage->label(),
                ],
                'default' => DiscountType::Full->value,
            ],
            'discount_value' => [
                'type' => 'int',
                'label' => 'Kortingswaarde (centen of percentage)',
                'description' => 'Bedrag in centen voor vast, of 0-100 voor percentage',
            ],
            'usage_limit' => [
                'type' => 'int',
                'label' => 'Gebruikslimiet',
                'description' => '0 = onbeperkt',
                'default' => 1,
            ],
            'used_count' => [
                'type' => 'int',
                'label' => 'Aantal gebruikt',
                'default' => 0,
            ],
            'edition_id' => [
                'type' => 'int',
                'label' => 'Beperkt tot editie',
                'description' => '0 = alle edities',
            ],
            'valid_from' => [
                'type' => 'date',
                'label' => 'Geldig vanaf',
            ],
            'valid_until' => [
                'type' => 'date',
                'label' => 'Geldig tot',
            ],
            'status' => [
                'type' => 'select',
                'label' => 'Status',
                'options' => [
                    VoucherStatus::Active->value => VoucherStatus::Active->label(),
                    VoucherStatus::Exhausted->value => VoucherStatus::Exhausted->label(),
                    VoucherStatus::Expired->value => VoucherStatus::Expired->label(),
                    VoucherStatus::Disabled->value => VoucherStatus::Disabled->label(),
                ],
                'default' => VoucherStatus::Active->value,
            ],
            'created_by' => [
                'type' => 'int',
                'label' => 'Aangemaakt door',
            ],
            'redemptions' => [
                'type' => 'json',
                'label' => 'Verzilveringen',
                'description' => 'Array of {user_id, quote_id, redeemed_at}',
            ],
        ];
    }

    private static function getFieldGroups(): array
    {
        return [
            'general' => [
                'title' => 'Algemeen',
                'fields' => ['code', 'status'],
            ],
            'discount' => [
                'title' => 'Korting',
                'fields' => ['discount_type', 'discount_value'],
            ],
            'usage' => [
                'title' => 'Gebruik',
                'fields' => ['usage_limit', 'used_count'],
            ],
            'validity' => [
                'title' => 'Geldigheid',
                'fields' => ['edition_id', 'valid_from', 'valid_until'],
            ],
        ];
    }
}
```

**Step 2: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Invoicing/VoucherCPT.php
git commit -m "feat: add VoucherCPT registration"
```

---

## Task 4: Create VoucherRepository

**Files:**
- Create: `web/app/mu-plugins/stride-core/Modules/Invoicing/VoucherRepository.php`

**Step 1: Create repository class**

```php
<?php

declare(strict_types=1);

namespace Stride\Modules\Invoicing;

use Stride\Domain\VoucherStatus;
use Stride\Infrastructure\AbstractRepository;

/**
 * Repository for voucher data access.
 */
final class VoucherRepository extends AbstractRepository
{
    protected string $postType = VoucherCPT::POST_TYPE;

    /**
     * Find voucher by code.
     */
    public function findByCode(string $code): ?array
    {
        $code = strtoupper(trim($code));

        $results = $this->model()
            ->where('code', $code)
            ->where('post_status', 'publish')
            ->limit(1)
            ->withMeta()
            ->get();

        return $results[0] ?? null;
    }

    /**
     * Find active vouchers.
     *
     * @return array<array<string, mixed>>
     */
    public function findActive(int $limit = 100): array
    {
        return $this->model()
            ->where('status', VoucherStatus::Active->value)
            ->where('post_status', 'publish')
            ->orderBy('post_date', 'DESC')
            ->limit($limit)
            ->withMeta()
            ->get();
    }

    /**
     * Find vouchers by edition.
     *
     * @return array<array<string, mixed>>
     */
    public function findByEdition(int $editionId): array
    {
        return $this->model()
            ->where('edition_id', $editionId)
            ->where('post_status', 'publish')
            ->orderBy('post_date', 'DESC')
            ->withMeta()
            ->get();
    }

    /**
     * Update voucher meta fields.
     */
    public function updateMeta(int $voucherId, array $data): bool
    {
        return $this->model()->updateMeta($voucherId, $data) !== false;
    }

    /**
     * Increment used count and add redemption.
     */
    public function recordRedemption(int $voucherId, array $redemption, int $newUsedCount, VoucherStatus $newStatus): bool
    {
        $currentRedemptions = $this->model()->getMeta($voucherId, 'redemptions', []);
        if (!is_array($currentRedemptions)) {
            $currentRedemptions = [];
        }

        $currentRedemptions[] = $redemption;

        return $this->model()->updateMeta($voucherId, [
            'used_count' => $newUsedCount,
            'redemptions' => $currentRedemptions,
            'status' => $newStatus->value,
        ]) !== false;
    }
}
```

**Step 2: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Invoicing/VoucherRepository.php
git commit -m "feat: add VoucherRepository"
```

---

## Task 5: Create VoucherCodeGenerator

**Files:**
- Create: `web/app/mu-plugins/stride-core/Modules/Invoicing/VoucherCodeGenerator.php`

**Step 1: Create pure helper class**

```php
<?php

declare(strict_types=1);

namespace Stride\Modules\Invoicing;

/**
 * Pure helper for generating voucher codes.
 *
 * Stateless - all methods are static.
 */
final class VoucherCodeGenerator
{
    private const DEFAULT_PREFIX = 'VAD';

    /**
     * Generate a unique voucher code.
     *
     * @param string $prefix Code prefix (default: VAD)
     * @param callable|null $existsCheck Callback to check if code exists: fn(string $code): bool
     * @param int $maxAttempts Maximum generation attempts
     * @return string Generated code in format PREFIX-XXXX-XXXX
     */
    public static function generate(
        string $prefix = self::DEFAULT_PREFIX,
        ?callable $existsCheck = null,
        int $maxAttempts = 10
    ): string {
        $attempt = 0;

        do {
            $code = self::buildCode($prefix);
            $exists = $existsCheck ? $existsCheck($code) : false;
            $attempt++;
        } while ($exists && $attempt < $maxAttempts);

        return $code;
    }

    /**
     * Build a single code string.
     *
     * @param string $prefix Code prefix
     * @return string Code in format PREFIX-XXXX-XXXX
     */
    private static function buildCode(string $prefix): string
    {
        return sprintf(
            '%s-%s-%s',
            strtoupper($prefix),
            strtoupper(wp_generate_password(4, false)),
            strtoupper(wp_generate_password(4, false))
        );
    }
}
```

**Step 2: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Invoicing/VoucherCodeGenerator.php
git commit -m "feat: add VoucherCodeGenerator helper"
```

---

## Task 6: Create VoucherService

**Files:**
- Create: `web/app/mu-plugins/stride-core/Modules/Invoicing/VoucherService.php`

**Step 1: Create service class**

```php
<?php

declare(strict_types=1);

namespace Stride\Modules\Invoicing;

use Stride\Domain\DiscountType;
use Stride\Domain\Money;
use Stride\Domain\VoucherStatus;
use Stride\Infrastructure\AbstractService;
use WP_Error;
use WP_Post;

/**
 * Voucher business logic.
 */
final class VoucherService extends AbstractService
{
    public function __construct(
        private readonly VoucherRepository $repository,
    ) {
        parent::__construct();
    }

    public static function metadata(): array
    {
        return [
            'name' => 'Voucher Service',
            'description' => 'Manages discount vouchers',
            'priority' => 25,
        ];
    }

    protected function getConfigSlug(): string
    {
        return 'vouchers';
    }

    protected function init(): void
    {
        // No hooks needed - voucher operations triggered by service calls
    }

    /**
     * Create a voucher.
     *
     * @param array{
     *   code?: string,
     *   discount_type?: string,
     *   discount_value?: int,
     *   usage_limit?: int,
     *   edition_id?: int,
     *   valid_from?: string,
     *   valid_until?: string,
     * } $data
     */
    public function createVoucher(array $data = []): int|WP_Error
    {
        // Generate code if not provided
        $code = !empty($data['code'])
            ? strtoupper(trim($data['code']))
            : VoucherCodeGenerator::generate('VAD', fn($c) => $this->repository->findByCode($c) !== null);

        // Check for duplicate
        if ($this->repository->findByCode($code)) {
            return new WP_Error('code_exists', 'Deze vouchercode bestaat al');
        }

        $result = $this->repository->create([
            'title' => $code,
            'code' => $code,
            'discount_type' => $data['discount_type'] ?? DiscountType::Full->value,
            'discount_value' => (int) ($data['discount_value'] ?? 0),
            'usage_limit' => (int) ($data['usage_limit'] ?? 1),
            'used_count' => 0,
            'edition_id' => (int) ($data['edition_id'] ?? 0),
            'valid_from' => $data['valid_from'] ?? '',
            'valid_until' => $data['valid_until'] ?? '',
            'status' => VoucherStatus::Active->value,
            'created_by' => get_current_user_id(),
            'redemptions' => [],
        ]);

        if (is_wp_error($result)) {
            return $result;
        }

        $voucherId = $result->ID;

        $this->dispatch('voucher/created', [
            'voucher_id' => $voucherId,
            'code' => $code,
        ]);

        return $voucherId;
    }

    /**
     * Get voucher by ID.
     */
    public function getVoucher(int $voucherId): ?array
    {
        $result = $this->repository->find($voucherId);

        if (is_wp_error($result) || !$result) {
            return null;
        }

        return $this->hydrateVoucher($result);
    }

    /**
     * Get voucher by code.
     */
    public function getVoucherByCode(string $code): ?array
    {
        $result = $this->repository->findByCode($code);

        return $result ? $this->hydrateVoucher($result) : null;
    }

    /**
     * Validate a voucher code.
     *
     * @return array|WP_Error Voucher data or error
     */
    public function validateVoucher(string $code, ?int $editionId = null): array|WP_Error
    {
        $voucher = $this->getVoucherByCode($code);

        if (!$voucher) {
            return new WP_Error('not_found', 'Voucher niet gevonden');
        }

        if ($voucher['status_enum'] !== VoucherStatus::Active) {
            return new WP_Error('invalid_status', 'Voucher is niet meer geldig');
        }

        // Check usage limit
        if ($voucher['usage_limit'] > 0 && $voucher['used_count'] >= $voucher['usage_limit']) {
            return new WP_Error('exhausted', 'Voucher is uitgeput');
        }

        // Check date validity
        $now = current_time('Y-m-d');

        if (!empty($voucher['valid_from']) && $now < $voucher['valid_from']) {
            return new WP_Error('not_yet_valid', 'Voucher is nog niet geldig');
        }

        if (!empty($voucher['valid_until']) && $now > $voucher['valid_until']) {
            return new WP_Error('expired', 'Voucher is verlopen');
        }

        // Check edition restriction
        if ($editionId !== null && $voucher['edition_id'] > 0 && $voucher['edition_id'] !== $editionId) {
            return new WP_Error('wrong_edition', 'Voucher is niet geldig voor deze editie');
        }

        return $voucher;
    }

    /**
     * Calculate discount amount for a voucher.
     */
    public function calculateDiscount(array $voucher, Money $subtotal): Money
    {
        $discountType = DiscountType::tryFrom($voucher['discount_type'] ?? '') ?? DiscountType::Full;

        return match ($discountType) {
            DiscountType::Full => $subtotal,
            DiscountType::Fixed => Money::cents(min($voucher['discount_value'], $subtotal->inCents())),
            DiscountType::Percentage => Money::cents(
                (int) round($subtotal->inCents() * ($voucher['discount_value'] / 100))
            ),
        };
    }

    /**
     * Redeem a voucher for a quote.
     *
     * Records the redemption and increments usage count.
     * Uses transaction locking to prevent race conditions.
     */
    public function redeemVoucher(string $code, int $userId, int $quoteId): array|WP_Error
    {
        global $wpdb;

        $voucher = $this->validateVoucher($code);

        if (is_wp_error($voucher)) {
            return $voucher;
        }

        $voucherId = $voucher['id'];

        try {
            $wpdb->query('START TRANSACTION');

            // Lock the voucher row
            $wpdb->get_row($wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE ID = %d FOR UPDATE",
                $voucherId
            ));

            // Re-fetch after lock to get current state
            $voucher = $this->getVoucher($voucherId);

            if (!$voucher || $voucher['status_enum'] !== VoucherStatus::Active) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('invalid_status', 'Voucher is niet meer geldig');
            }

            // Check if already used by this user
            $redemptions = $voucher['redemptions'] ?? [];
            foreach ($redemptions as $r) {
                if (($r['user_id'] ?? 0) === $userId) {
                    $wpdb->query('ROLLBACK');
                    return new WP_Error('already_redeemed', 'Je hebt deze voucher al gebruikt');
                }
            }

            // Record redemption
            $newUsedCount = $voucher['used_count'] + 1;
            $newStatus = $voucher['status_enum'];

            if ($voucher['usage_limit'] > 0 && $newUsedCount >= $voucher['usage_limit']) {
                $newStatus = VoucherStatus::Exhausted;
            }

            $redemption = [
                'user_id' => $userId,
                'quote_id' => $quoteId,
                'redeemed_at' => current_time('mysql'),
            ];

            $success = $this->repository->recordRedemption(
                $voucherId,
                $redemption,
                $newUsedCount,
                $newStatus
            );

            if (!$success) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('redemption_failed', 'Kon voucher niet verzilveren');
            }

            $wpdb->query('COMMIT');

            $this->dispatch('voucher/redeemed', [
                'voucher_id' => $voucherId,
                'user_id' => $userId,
                'quote_id' => $quoteId,
            ]);

            return [
                'voucher_id' => $voucherId,
                'code' => $voucher['code'],
            ];

        } catch (\Exception $e) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('transaction_failed', 'Transactie mislukt');
        }
    }

    /**
     * Hydrate voucher data.
     *
     * @param array<string, mixed>|WP_Post $voucher
     * @return array<string, mixed>
     */
    private function hydrateVoucher(array|WP_Post $voucher): array
    {
        $data = is_array($voucher) ? $voucher : (array) $voucher;

        // Flatten meta fields to top level if present
        if (isset($data['meta']) && is_array($data['meta'])) {
            $data = array_merge($data, $data['meta']);
        }

        // Parse enums
        $data['status_enum'] = VoucherStatus::tryFrom($data['status'] ?? '') ?? VoucherStatus::Active;
        $data['discount_type_enum'] = DiscountType::tryFrom($data['discount_type'] ?? '') ?? DiscountType::Full;

        // Ensure numeric fields
        $data['usage_limit'] = (int) ($data['usage_limit'] ?? 1);
        $data['used_count'] = (int) ($data['used_count'] ?? 0);
        $data['discount_value'] = (int) ($data['discount_value'] ?? 0);
        $data['edition_id'] = (int) ($data['edition_id'] ?? 0);

        // Ensure redemptions is array
        if (!isset($data['redemptions']) || !is_array($data['redemptions'])) {
            $data['redemptions'] = [];
        }

        return $data;
    }
}
```

**Step 2: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Invoicing/VoucherService.php
git commit -m "feat: add VoucherService with create, validate, redeem"
```

---

## Task 7: Wire up in stride-core.php

**Files:**
- Modify: `web/app/mu-plugins/stride-core/stride-core.php`

**Step 1: Add VoucherCPT registration**

In the `add_action('init', ...)` section (after QuoteCPT), add:

```php
add_action('init', [\Stride\Modules\Invoicing\VoucherCPT::class, 'register'], 5);
```

**Step 2: Add VoucherRepository to DI container**

In the `ntdst/core_ready` hook (after QuoteRepository), add:

```php
ntdst_set(\Stride\Modules\Invoicing\VoucherRepository::class);
```

**Step 3: Commit**

```bash
git add web/app/mu-plugins/stride-core/stride-core.php
git commit -m "feat: wire VoucherCPT and VoucherRepository"
```

---

## Task 8: Add VoucherService to plugin-config.php

**Files:**
- Modify: `web/app/mu-plugins/stride-core/plugin-config.php`

**Step 1: Add VoucherService to services array**

After QuoteService in the 'services' array, add:

```php
\Stride\Modules\Invoicing\VoucherService::class,
```

**Step 2: Commit**

```bash
git add web/app/mu-plugins/stride-core/plugin-config.php
git commit -m "feat: register VoucherService"
```

---

## Task 9: Add Voucher Support to QuoteService

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Modules/Invoicing/QuoteService.php`

**Step 1: Update createQuote signature**

The createQuote method already accepts `?string $voucherCode` and `?Money $discount` parameters. We need to update the stored data to include `voucher_code` field.

Verify the current createQuote method stores `voucher_code` in the create call. If not present, add:

```php
'voucher_code' => $voucherCode,
```

to the repository create data array.

**Step 2: Add method to apply voucher to existing quote**

Add this method to QuoteService:

```php
/**
 * Apply a voucher code to a draft quote.
 */
public function applyVoucher(int $quoteId, string $voucherCode): bool|WP_Error
{
    $quote = $this->repository->find($quoteId);

    if (is_wp_error($quote)) {
        return $quote;
    }

    $status = QuoteStatus::tryFrom($quote->status ?? '');

    if ($status !== QuoteStatus::Draft) {
        return new WP_Error('invalid_status', 'Alleen concept-offertes kunnen worden aangepast');
    }

    // Validate and get voucher through VoucherService
    $voucherService = ntdst_get(VoucherService::class);
    $voucher = $voucherService->validateVoucher($voucherCode, (int) ($quote->edition_id ?? 0));

    if (is_wp_error($voucher)) {
        return $voucher;
    }

    // Calculate discount
    $subtotal = Money::cents((int) ($quote->subtotal ?? 0));
    $discount = $voucherService->calculateDiscount($voucher, $subtotal);

    // Recalculate totals
    $newSubtotal = $subtotal;
    $newDiscount = $discount;
    $newTax = Money::cents((int) round(($newSubtotal->inCents() - $newDiscount->inCents()) * 0.21));
    $newTotal = Money::cents($newSubtotal->inCents() - $newDiscount->inCents() + $newTax->inCents());

    // Update quote
    $result = $this->repository->updateMeta($quoteId, [
        'voucher_code' => $voucherCode,
        'discount' => $newDiscount->inCents(),
        'tax' => $newTax->inCents(),
        'total' => $newTotal->inCents(),
    ]);

    if (!$result) {
        return new WP_Error('update_failed', 'Kon offerte niet bijwerken');
    }

    // Redeem the voucher
    $redemption = $voucherService->redeemVoucher($voucherCode, (int) $quote->user_id, $quoteId);

    if (is_wp_error($redemption)) {
        // Rollback voucher code on quote (optional, or leave as-is)
        return $redemption;
    }

    $this->dispatch('quote/voucher_applied', [
        'quote_id' => $quoteId,
        'voucher_code' => $voucherCode,
        'discount' => $newDiscount->inCents(),
    ]);

    return true;
}
```

**Step 3: Add updateMeta method to QuoteRepository if not present**

In `QuoteRepository.php`, add:

```php
/**
 * Update quote meta fields.
 */
public function updateMeta(int $quoteId, array $data): bool
{
    return $this->model()->updateMeta($quoteId, $data) !== false;
}
```

**Step 4: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Invoicing/QuoteService.php
git add web/app/mu-plugins/stride-core/Modules/Invoicing/QuoteRepository.php
git commit -m "feat: add voucher support to QuoteService"
```

---

## Task 10: Verify Plugin Loads

**Step 1: Run verification**

```bash
ddev exec wp eval "
echo 'VoucherCPT: ' . (class_exists('\Stride\Modules\Invoicing\VoucherCPT') ? 'OK' : 'FAIL') . PHP_EOL;
echo 'VoucherRepository: ' . (class_exists('\Stride\Modules\Invoicing\VoucherRepository') ? 'OK' : 'FAIL') . PHP_EOL;
echo 'VoucherService: ' . (class_exists('\Stride\Modules\Invoicing\VoucherService') ? 'OK' : 'FAIL') . PHP_EOL;
echo 'VoucherStatus: ' . (enum_exists('\Stride\Domain\VoucherStatus') ? 'OK' : 'FAIL') . PHP_EOL;
echo 'DiscountType: ' . (enum_exists('\Stride\Domain\DiscountType') ? 'OK' : 'FAIL') . PHP_EOL;
echo 'CPT Registered: ' . (post_type_exists('vad_voucher') ? 'OK' : 'FAIL') . PHP_EOL;
"
```

Expected output: All OK

**Step 2: Test voucher creation**

```bash
ddev exec wp eval "
\$service = ntdst_get(\Stride\Modules\Invoicing\VoucherService::class);
\$id = \$service->createVoucher([
    'discount_type' => 'percentage',
    'discount_value' => 20,
    'usage_limit' => 5,
]);
if (is_wp_error(\$id)) {
    echo 'ERROR: ' . \$id->get_error_message() . PHP_EOL;
} else {
    echo 'Created voucher ID: ' . \$id . PHP_EOL;
    \$v = \$service->getVoucher(\$id);
    echo 'Code: ' . \$v['code'] . PHP_EOL;
    echo 'Status: ' . \$v['status_enum']->label() . PHP_EOL;
}
"
```

---

## Task 11: Update Seed Script

**Files:**
- Modify: `scripts/seed.php`

**Step 1: Add voucher seeding**

Add after quote seeding section:

```php
// Seed vouchers
$log('Creating vouchers...');

$voucherService = ntdst_get(\Stride\Modules\Invoicing\VoucherService::class);

$vouchers = [
    [
        'code' => 'TEST-FULL-FREE',
        'discount_type' => \Stride\Domain\DiscountType::Full->value,
        'discount_value' => 0,
        'usage_limit' => 10,
    ],
    [
        'code' => 'TEST-50-EURO',
        'discount_type' => \Stride\Domain\DiscountType::Fixed->value,
        'discount_value' => 5000, // €50 in cents
        'usage_limit' => 5,
    ],
    [
        'code' => 'TEST-20-PERCENT',
        'discount_type' => \Stride\Domain\DiscountType::Percentage->value,
        'discount_value' => 20,
        'usage_limit' => 100,
    ],
];

foreach ($vouchers as $data) {
    $existing = $voucherService->getVoucherByCode($data['code']);
    if ($existing) {
        $log("  Voucher {$data['code']} already exists");
        continue;
    }

    $result = $voucherService->createVoucher($data);
    if (is_wp_error($result)) {
        $log("  ERROR creating voucher {$data['code']}: " . $result->get_error_message());
    } else {
        $log("  Created voucher {$data['code']} (ID: {$result})");
    }
}
```

**Step 2: Update unseed script**

In `scripts/unseed.php`, add voucher cleanup (after quote cleanup):

```php
// Delete seeded vouchers
$log('Deleting seeded vouchers...');
$vouchers = get_posts([
    'post_type' => 'vad_voucher',
    'posts_per_page' => -1,
    'post_status' => 'any',
    'meta_query' => [
        [
            'key' => 'code',
            'value' => 'TEST-',
            'compare' => 'LIKE',
        ],
    ],
]);

foreach ($vouchers as $voucher) {
    wp_delete_post($voucher->ID, true);
    $log("  Deleted voucher ID: {$voucher->ID}");
}
```

**Step 3: Commit**

```bash
git add scripts/seed.php scripts/unseed.php
git commit -m "feat: add voucher seeding"
```

---

## Task 12: Test Complete Flow

**Step 1: Run seed script**

```bash
ddev exec wp eval-file scripts/seed.php
```

**Step 2: Test voucher validation and discount calculation**

```bash
ddev exec wp eval "
\$service = ntdst_get(\Stride\Modules\Invoicing\VoucherService::class);

// Test validation
\$voucher = \$service->validateVoucher('TEST-20-PERCENT');
if (is_wp_error(\$voucher)) {
    echo 'Validation ERROR: ' . \$voucher->get_error_message() . PHP_EOL;
    exit(1);
}

echo 'Voucher validated: ' . \$voucher['code'] . PHP_EOL;
echo 'Discount type: ' . \$voucher['discount_type_enum']->label() . PHP_EOL;
echo 'Discount value: ' . \$voucher['discount_value'] . '%' . PHP_EOL;

// Test discount calculation
\$subtotal = \Stride\Domain\Money::euros(350);
\$discount = \$service->calculateDiscount(\$voucher, \$subtotal);

echo 'Subtotal: ' . \$subtotal->format() . PHP_EOL;
echo 'Discount (20%): ' . \$discount->format() . PHP_EOL;
echo 'After discount: ' . \Stride\Domain\Money::cents(\$subtotal->inCents() - \$discount->inCents())->format() . PHP_EOL;
"
```

Expected output:
```
Voucher validated: TEST-20-PERCENT
Discount type: Percentage
Discount value: 20%
Subtotal: € 350,00
Discount (20%): € 70,00
After discount: € 280,00
```

**Step 3: Test full voucher redemption**

Create a test script to verify the complete flow:

```bash
ddev exec wp eval "
// Get services
\$quoteService = ntdst_get(\Stride\Modules\Invoicing\QuoteService::class);
\$voucherService = ntdst_get(\Stride\Modules\Invoicing\VoucherService::class);

// Find a draft quote
\$quotes = get_posts([
    'post_type' => 'vad_quote',
    'posts_per_page' => 1,
    'meta_query' => [
        ['key' => 'status', 'value' => 'draft'],
    ],
]);

if (empty(\$quotes)) {
    echo 'No draft quotes found - run seed script first' . PHP_EOL;
    exit(1);
}

\$quoteId = \$quotes[0]->ID;
\$quote = \$quoteService->getQuote(\$quoteId);

echo 'Testing voucher on quote #' . \$quoteId . PHP_EOL;
echo 'Before: ' . \$quote['total_money']->format() . PHP_EOL;

// Apply voucher
\$result = \$quoteService->applyVoucher(\$quoteId, 'TEST-20-PERCENT');

if (is_wp_error(\$result)) {
    echo 'ERROR: ' . \$result->get_error_message() . PHP_EOL;
    exit(1);
}

// Re-fetch quote
\$quote = \$quoteService->getQuote(\$quoteId);
echo 'After: ' . \$quote['total_money']->format() . PHP_EOL;
echo 'Discount: ' . \$quote['discount_money']->format() . PHP_EOL;
echo 'Voucher code: ' . (\$quote['voucher_code'] ?? 'N/A') . PHP_EOL;

echo PHP_EOL . 'SUCCESS: Voucher flow complete!' . PHP_EOL;
"
```

---

## Summary

**Created files:**
- `Domain/VoucherStatus.php` - Status enum
- `Domain/DiscountType.php` - Discount type enum
- `Modules/Invoicing/VoucherCPT.php` - CPT registration
- `Modules/Invoicing/VoucherRepository.php` - Data access
- `Modules/Invoicing/VoucherCodeGenerator.php` - Code generation helper
- `Modules/Invoicing/VoucherService.php` - Business logic

**Modified files:**
- `stride-core.php` - Wire CPT and repository
- `plugin-config.php` - Register service
- `Modules/Invoicing/QuoteService.php` - Add applyVoucher method
- `Modules/Invoicing/QuoteRepository.php` - Add updateMeta method
- `scripts/seed.php` - Seed test vouchers
- `scripts/unseed.php` - Cleanup test vouchers

**Next Slice (4):** Email Notifications (quote sent, enrollment confirmed)
