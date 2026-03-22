# Flexible Membership Detection & Pricing

**Date:** 2026-03-21
**Status:** Draft
**Scope:** Minimal — filters + bug fix + call site audit

---

## Problem

1. **Broken meta key**: Seed writes `is_vad_member`, code reads `is_member` — membership check never works
2. **Hardcoded detection**: Membership is checked via `get_user_meta()` directly in 2 places (EnrollmentQuoteHandler + enrollment.php), not overridable per client
3. **Hardcoded pricing**: Member price is always the `price` field on edition — no way to apply percentage discounts or other strategies
4. **Type mismatch**: `EnrollmentFormHandler::getItemPrice()` declares `?float` return but `getPrice()` returns `Money`

## Solution

Add two WordPress filters and a membership method on EditionService. Fix the meta key. Update all 7 call sites to use the new signature.

### 1. Membership Check: `EditionService::isMember(int $userId): bool`

```php
public function isMember(int $userId): bool
{
    $isMember = (bool) get_user_meta($userId, 'is_vad_member', true);
    return (bool) apply_filters('stride/membership/is_member', $isMember, $userId);
}
```

- Fixes meta key to `is_vad_member` (matching seed data and v3 convention)
- Filter allows clients to override with role, FluentCRM tag, external API, etc.
- Non-existent users return `false` (empty string from `get_user_meta` → `false`)

### 2. Price Resolution: Updated `EditionService::getPrice()`

```php
public function getPrice(int $editionId, ?int $userId = null): Money
{
    $isMember = $userId !== null ? $this->isMember($userId) : false;
    $field = $isMember ? 'price' : 'price_non_member';
    $amount = (float) $this->repository->getField($editionId, $field, 0);
    $price = Money::eur($amount);

    return apply_filters('stride/membership/price', $price, $editionId, $userId, $isMember);
}
```

**Signature change:** `getPrice(int $editionId, bool $isMember = true)` → `getPrice(int $editionId, ?int $userId = null)`

**Intentional default change:** When no `$userId` is provided, the price defaults to **non-member** (was: member). This is correct — the "public" price shown to anonymous visitors should be the non-member price. All call sites with user context must now pass the userId explicitly.

### 3. Call Site Updates (all 7)

| # | File | Line | Current | After |
|---|------|------|---------|-------|
| 1 | `EditionService.php` | 207 | Definition: `getPrice($id, bool $isMember = true)` | `getPrice($id, ?int $userId = null)` |
| 2 | `EnrollmentQuoteHandler.php` | 198-207 | `getEditionPrice()` reads `is_member` meta, passes bool | Delegate: `$editionService->getPrice($editionId, $userId)` |
| 3 | `enrollment.php` | 61-62 | Reads `is_member` meta, passes bool | `$editionService->getPrice($item_id, $current_user->ID)` |
| 4 | `single-vad_edition.php` | 42 | `getPrice($edition_id)` — no user, gets member price | `getPrice($edition_id, get_current_user_id() ?: null)` — logged-in users see their price, anonymous see non-member |
| 5 | `EnrollmentFormHandler.php` | 480-491 | `getItemPrice()` returns `?float`, calls `getPrice($itemId)` | Fix return type to `Money`, pass `get_current_user_id()` for editions. Fix caller (line 459) to use `$price->inCents()` instead of `$price * 100` |
| 6 | `StrideMailBridge.php` | 611 | `getPrice($editionId)` — no user context | Keep as-is (no user context available in smartcode resolution — non-member price is acceptable for generic email templates) |
| 7a | `ReadAbilityRegistrar.php` | 372 | `getPrice($editionId)->format()` | Keep as-is (admin/assistant display — non-member price is the "list price") |
| 7b | `ReadAbilityRegistrar.php` | 1261 | `getPrice($id)->format()` | Keep as-is (CSV export — non-member price is the "list price") |

**Decision log for sites 6/7:** StrideMailBridge and ReadAbilityRegistrar have no user context and show "list prices." Non-member price as default is correct for these — it's the standard/published price.

### 4. Seed Script

No change needed — seed already writes `is_vad_member` correctly.

## Files Changed

| File | Change |
|------|--------|
| `stride-core/Modules/Edition/EditionService.php` | Add `isMember()`, update `getPrice()` signature |
| `stride-core/Handlers/EnrollmentQuoteHandler.php` | Simplify `getEditionPrice()` to delegate |
| `stride-core/Handlers/EnrollmentFormHandler.php` | Fix `getItemPrice()` return type, pass userId, fix `$price * 100` bug |
| `themes/stridence/templates/forms/enrollment.php` | Use `getPrice($id, $userId)`, remove `$isMember` variable |
| `themes/stridence/single-vad_edition.php` | Pass `get_current_user_id()` to `getPrice()` |

**Unchanged (intentional):**
| File | Reason |
|------|--------|
| `stride-core/Modules/Mail/StrideMailBridge.php` | No user context in smartcode resolution — non-member (list) price is correct |
| `stride-core/Modules/Assistant/ReadAbilityRegistrar.php` | Admin display / CSV export — list price is correct |

## Filters Reference

| Filter | Args | Return Type | Default Behavior | Purpose |
|--------|------|-------------|------------------|---------|
| `stride/membership/is_member` | `bool $isMember, int $userId` | `bool` | Checks `is_vad_member` user meta | Override membership detection |
| `stride/membership/price` | `Money $price, int $editionId, ?int $userId, bool $isMember` | `Money` | Returns edition's `price` or `price_non_member` field | Override final price calculation |

**Filter usage example — percentage discount for members:**
```php
add_filter('stride/membership/price', function (Money $price, int $editionId, ?int $userId, bool $isMember): Money {
    if ($isMember) {
        // 15% discount for members
        $discounted = (int) round($price->inCents() * 0.85);
        return Money::cents($discounted);
    }
    return $price;
}, 10, 4);
```

**Filter usage example — role-based membership:**
```php
add_filter('stride/membership/is_member', function (bool $isMember, int $userId): bool {
    return user_can($userId, 'lid');  // Dutch: "member" role
}, 10, 2);
```

## What This Enables (Future)

Without core code changes, a client can:
- Check membership via WordPress role, FluentCRM tag, or external API
- Apply percentage discounts instead of fixed member prices
- Use different pricing strategies per edition (filter receives `$editionId`)

## What This Does NOT Do

- No admin UI changes (two price fields remain)
- No new services or interfaces
- No changes to voucher system (vouchers remain separate)
- No database migrations
- No global helper function (use `ntdst_get(EditionService::class)->isMember($userId)`)
