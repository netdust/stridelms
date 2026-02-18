# Phase 4: Voucher System Completion

## Problem/Feature

Phase 4 (Voucher System) is marked "MOSTLY COMPLETE" in the project plan. The generic voucher infrastructure (creation, validation, redemption with atomic locking, admin UI) is fully built. What's missing are the **VAD-specific business rules**:

1. **5 voucher categories**: member, action, speaker, day, social
2. **Member voucher rules**: 2/year, 2-year expiry, blocked for multi-year trainings
3. **Day voucher prorating**: Auto-conversion for multi-day editions (1 day = 1/N discount)
4. **Social tariff**: 50% discount for ervaringsdeskundigen
5. **No split/extend/refund**: Enforcement and reversal tracking

## Acceptance Criteria

- [ ] Voucher category field added to CPT schema and admin UI
- [ ] Member vouchers enforce: 2-year expiry, blocked for `is_tweejarige_opleiding` editions
- [ ] Day vouchers calculate prorated discount based on edition session count
- [ ] Social vouchers apply 50% discount
- [ ] Voucher validation returns type-specific errors (not generic)
- [ ] Tests cover all 5 voucher types and their rules

## Implementation

### Step 1: Add Voucher Category Field

**Files:** `VoucherService.php`, `VoucherAdminController.php`, `FieldRegistry.php`

Add `VOUCHER_CATEGORY` field with 5 constants:

```php
// VoucherService.php
public const CATEGORY_MEMBER = 'member';    // 2/year, 2yr expiry, blocked for tweejarige
public const CATEGORY_ACTION = 'action';    // Promotional, no special rules
public const CATEGORY_SPEAKER = 'speaker';  // Issued to speakers
public const CATEGORY_DAY = 'day';          // Prorated for multi-day editions
public const CATEGORY_SOCIAL = 'social';    // 50% ervaringsdeskundigen

public const FIELD_CATEGORY = 'category';

// In schema:
self::FIELD_CATEGORY => ['type' => 'text'],
```

Update admin form with dropdown for category selection.

### Step 2: Edition Field for Multi-Year Training

**Files:** `EditionService.php`, `FieldRegistry.php`

Add field to detect "tweejarige opleiding":

```php
// FieldRegistry.php
public const EDITION_IS_MULTI_YEAR = '_vad_is_multi_year_training';

// EditionService schema
self::FIELD_IS_MULTI_YEAR => ['type' => 'boolean'],

// EditionService method
public function isMultiYearTraining(int $editionId): bool {
    $edition = $this->getEdition($editionId);
    return (bool) ($edition['is_multi_year_training'] ?? false);
}
```

### Step 3: Create VoucherTypeValidator Helper

**File:** `invoicing/Helpers/VoucherTypeValidator.php` (NEW)

Centralized validation logic for each voucher category:

```php
class VoucherTypeValidator {
    public function __construct(
        private EditionService $editionService,
        private SessionService $sessionService
    ) {}

    /**
     * Validate voucher against type-specific rules
     * @return true|WP_Error
     */
    public function validate(array $voucher, string $itemType, int $itemId): true|WP_Error {
        $category = $voucher['category'] ?? VoucherService::CATEGORY_ACTION;

        return match ($category) {
            VoucherService::CATEGORY_MEMBER => $this->validateMember($voucher, $itemType, $itemId),
            VoucherService::CATEGORY_DAY => $this->validateDay($voucher, $itemType, $itemId),
            VoucherService::CATEGORY_SOCIAL => true, // No restrictions
            VoucherService::CATEGORY_SPEAKER => true, // No restrictions
            VoucherService::CATEGORY_ACTION => true, // No restrictions
            default => true,
        };
    }

    private function validateMember(array $voucher, string $itemType, int $itemId): true|WP_Error {
        // Member vouchers can't be used for multi-year trainings
        if ($itemType === 'edition') {
            if ($this->editionService->isMultiYearTraining($itemId)) {
                return new WP_Error(
                    'member_voucher_blocked',
                    __('Lidmaatschapsvouchers zijn niet geldig voor tweejarige opleidingen.', 'stride')
                );
            }
        }
        return true;
    }

    private function validateDay(array $voucher, string $itemType, int $itemId): true|WP_Error {
        // Day vouchers require edition to have sessions
        if ($itemType === 'edition') {
            $sessions = $this->sessionService->getSessionsForEdition($itemId);
            if (count($sessions) === 0) {
                return new WP_Error(
                    'day_voucher_no_sessions',
                    __('Dagvouchers kunnen niet gebruikt worden voor edities zonder sessies.', 'stride')
                );
            }
        }
        return true;
    }
}
```

### Step 4: Update Discount Calculation for Day Vouchers

**File:** `VoucherService.php` - modify `calculateDiscount()`

```php
public function calculateDiscount(array $voucher, string $itemType, int $itemId, float $itemPrice): float {
    $category = $voucher['category'] ?? self::CATEGORY_ACTION;

    // Day vouchers prorate based on session count
    if ($category === self::CATEGORY_DAY && $itemType === 'edition') {
        return $this->calculateDayVoucherDiscount($voucher, $itemId, $itemPrice);
    }

    // Social vouchers always 50%
    if ($category === self::CATEGORY_SOCIAL) {
        return round($itemPrice * 0.5, 2);
    }

    // Standard discount logic for other types
    return match ($voucher['discount_type'] ?? self::DISCOUNT_TYPE_FULL) {
        self::DISCOUNT_TYPE_FULL => $itemPrice,
        self::DISCOUNT_TYPE_FIXED => min((float) $voucher['discount_value'], $itemPrice),
        self::DISCOUNT_TYPE_PERCENTAGE => round($itemPrice * ((float) $voucher['discount_value'] / 100), 2),
        default => 0.0,
    };
}

private function calculateDayVoucherDiscount(array $voucher, int $editionId, float $itemPrice): float {
    $sessionService = $this->resolveService(SessionService::class);
    $sessions = $sessionService->getSessionsForEdition($editionId);
    $dayCount = max(count($sessions), 1);

    // 1 day voucher = price / N days
    return round($itemPrice / $dayCount, 2);
}
```

### Step 5: Integrate Type Validator into VoucherService

**File:** `VoucherService.php` - modify `validateVoucher()`

```php
public function validateVoucher(string $code, ?int $itemId = null): array|WP_Error {
    // ... existing validation (status, dates, limits) ...

    // Type-specific validation
    if ($itemId !== null) {
        $itemType = $voucher['item_type'] ?: 'edition';
        $typeValidator = new VoucherTypeValidator(
            $this->resolveService(EditionService::class),
            $this->resolveService(SessionService::class)
        );
        $typeResult = $typeValidator->validate($voucher, $itemType, $itemId);
        if (is_wp_error($typeResult)) {
            return $typeResult;
        }
    }

    return $voucher;
}
```

### Step 6: Update Admin Form

**File:** `VoucherAdminController.php` - add category dropdown

```php
// In renderMetaboxContent() - add category section
$categories = [
    VoucherService::CATEGORY_ACTION => __('Actie', 'stride'),
    VoucherService::CATEGORY_MEMBER => __('Lidmaatschap (2 per jaar)', 'stride'),
    VoucherService::CATEGORY_SPEAKER => __('Spreker', 'stride'),
    VoucherService::CATEGORY_DAY => __('Dag (pro rata)', 'stride'),
    VoucherService::CATEGORY_SOCIAL => __('Sociaal tarief (50%)', 'stride'),
];
```

### Step 7: Add Tests

**File:** `scripts/test-voucher.php` - add type-specific tests

```php
// E. VAD-specific voucher types (new section)
// E1. Member voucher validates for regular edition
// E2. Member voucher blocked for multi-year edition
// E3. Day voucher calculates prorated discount (3 sessions = 1/3 price)
// E4. Day voucher requires edition with sessions
// E5. Social voucher applies 50% discount
// E6. Action voucher has no restrictions
```

## File Changes Summary

| File | Action | Purpose |
|------|--------|---------|
| `VoucherService.php` | Modify | Add category constants, field, discount logic |
| `VoucherAdminController.php` | Modify | Add category dropdown |
| `EditionService.php` | Modify | Add `is_multi_year_training` field |
| `FieldRegistry.php` | Modify | Add new field constants |
| `VoucherTypeValidator.php` | Create | Type-specific validation rules |
| `test-voucher.php` | Modify | Add type-specific tests |

## References

- Similar patterns: `VATValidator.php` for validation helper pattern
- Existing discount calculation: `VoucherService.php:380-410`
- Edition field patterns: `EditionService.php:92-150`

## Out of Scope (Deferred)

These items are mentioned in the project plan but deferred to later phases:

- **Member voucher auto-generation** (annual renewal cron) → Phase 8 (Automations)
- **Voucher reversal on cancellation** → Phase 8 (needs cancellation event system)
- **Annual member voucher renewal trigger** → Phase 8 (needs FluentCRM automation)

## Estimated Effort

| Item | Lines |
|------|-------|
| Category field + constants | 50 |
| VoucherTypeValidator helper | 150 |
| Discount calculation updates | 50 |
| Admin form category dropdown | 30 |
| Edition multi-year field | 30 |
| Tests for all types | 150 |
| **Total** | **~460 lines** |
