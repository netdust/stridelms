# Phase 4 — Voucher Scope & Per-Session Apply Mode

**Supersedes:** `plans/phase-4-voucher-completion.md` (the 5-category / multi-year-training / `VoucherTypeValidator` plan from Feb 2026).

**Why superseded:** the original plan baked 5 hard-coded voucher categories (member / action / speaker / day / social) into code. After re-reviewing the existing voucher infra (audit 2026-05-14), the user decision is to keep the form generic and instead expose two intuitive controls that admins can compose freely:

1. **Bidirectional edition scope** — today's "Beperkt tot editie" becomes a 3-way radio: *Alle / Alleen / Behalve*. Replaces the rigid "member voucher blocked for multi-year" rule with admin discretion (add the 2-year editions to "Behalve" on the member voucher).
2. **Apply mode for multi-session editions** — voucher declares whether it discounts the full edition or one session. Replaces the rigid "day voucher prorating" category with a per-voucher opt-in.

Categories, `VoucherTypeValidator`, `is_multi_year_training` edition field, and the social-50% special case are **all dropped**. Existing generic discount types (Full / Fixed / Percentage) cover those cases already.

---

## Acceptance Criteria

- [ ] Voucher CPT gains two fields: `scope_mode` (`all` | `only` | `except`) and `apply_mode` (`full` | `single_session`)
- [ ] Voucher CPT gains `excluded_edition_ids` field (JSON array of int) for the "behalve" case
- [ ] Existing single `edition_id` field stays — used when `scope_mode = 'only'`
- [ ] Admin form replaces the "Beperkt tot editie" single dropdown with a 3-way radio + relevant picker(s)
- [ ] Admin form gains an "Toepassen op" dropdown
- [ ] `VoucherService::validateVoucher()` honours `scope_mode = 'except'` (rejects if target edition is in `excluded_edition_ids`)
- [ ] `VoucherService::calculateDiscount()` accepts an optional `?int $editionId` and, when `apply_mode = 'single_session'`, divides the subtotal by `max(session_count, 1)` before applying the discount type
- [ ] 0-session editions silently fall through to "full" behaviour (no error, no special case)
- [ ] Migration: existing vouchers with `edition_id > 0` are interpreted as `scope_mode = 'only'`; everything else is `scope_mode = 'all'`. No DB migration needed — `scope_mode` defaults to `'all'` when absent, and "only" is inferred from `edition_id > 0` if `scope_mode` is empty
- [ ] 6 new integration tests + green existing test suite (20 voucher integration + 9 voucher unit tests stay passing)
- [ ] LAUNCH-CHECKLIST §C marked done with commit SHA

---

## NTDST Classification

| Code unit | Type | Why |
|---|---|---|
| `VoucherCPT::getFields()` | **Schema modification** | Field definitions live in CPT class per existing pattern. No `FieldRegistry.php`. |
| `VoucherService` | **Existing service** | Already implements feature; we modify two methods. No new hooks. |
| `VoucherAdminController` | **Existing sub-component** | Form rendering + save; modify both. |
| `Helpers/VoucherScopeValidator` | **NEW plain class** | Pure logic, no hooks, no `NTDST_Service_Meta`. Resolved via DI autowiring. ≤ 100 LOC. |
| `Helpers/VoucherProrater` | **NEW plain class** | Pure math (subtotal / session_count). Pure, testable, no WP. ≤ 50 LOC. |

Following the audit's note: don't create a `VoucherTypeValidator` with `match` on categories — there are no categories. Two narrow helpers (scope + prorater) replace it.

---

## Files Changed

| File | Action | Why |
|---|---|---|
| `Modules/Invoicing/VoucherCPT.php` | Modify | Add 3 fields to schema |
| `Modules/Invoicing/VoucherService.php` | Modify | Wire validator + prorater into `validateVoucher` + `calculateDiscount`; hydrate new fields |
| `Modules/Invoicing/Admin/VoucherAdminController.php` | Modify | Replace edition section UI + add apply-mode dropdown + save handlers |
| `Modules/Invoicing/Helpers/VoucherScopeValidator.php` | Create | `validate(array $voucher, ?int $editionId): true\|WP_Error` |
| `Modules/Invoicing/Helpers/VoucherProrater.php` | Create | `prorate(Money $subtotal, int $sessionCount): Money` |
| `tests/Integration/VoucherServiceIntegrationTest.php` | Modify | 6 new scenarios |
| `docs/LAUNCH-CHECKLIST.md` | Modify | Mark §C done at end |

**No changes:** `EditionService`, `EditionCPT`, `QuoteService`, `EnrollmentFormHandler`, `EnrollmentQuoteHandler`, `RegistrationRepository`, `SessionService`. Existing callers either already pass `editionId` (3 of 4) or pass `null` (1 of 4 — trajectory flow stays today's behaviour because `editionId = null` short-circuits the prorating branch).

---

## Implementation Steps

### Step 1 — VoucherCPT schema

Add to `getFields()`:

```php
'scope_mode' => [
    'type' => 'select',
    'label' => 'Geldig voor',
    'options' => [
        'all' => 'Alle edities',
        'only' => 'Alleen voor één editie',
        'except' => 'Alle edities behalve…',
    ],
    'default' => 'all',
],
'excluded_edition_ids' => [
    'type' => 'json',
    'label' => 'Uitgesloten edities',
    'description' => 'Array of edition IDs to exclude when scope_mode = except',
],
'apply_mode' => [
    'type' => 'select',
    'label' => 'Toepassen op',
    'options' => [
        'full' => 'Volledige editie',
        'single_session' => 'Eén sessie (pro rata)',
    ],
    'default' => 'full',
],
```

Update `getFieldGroups()` to put `scope_mode`, `edition_id`, `excluded_edition_ids` under `validity` group and `apply_mode` under `discount` group.

### Step 2 — VoucherScopeValidator (NEW)

`Modules/Invoicing/Helpers/VoucherScopeValidator.php` — pure class, no hooks:

```php
final class VoucherScopeValidator
{
    public function validate(array $voucher, ?int $editionId): true|WP_Error
    {
        if ($editionId === null) {
            return true; // No edition context (trajectory) — scope not enforced
        }

        $mode = $voucher['scope_mode'] ?? 'all';
        // Back-compat: empty scope_mode + edition_id > 0 = legacy "only" mode
        if ($mode === '' && (int) ($voucher['edition_id'] ?? 0) > 0) {
            $mode = 'only';
        }

        return match ($mode) {
            'only' => $this->validateOnly($voucher, $editionId),
            'except' => $this->validateExcept($voucher, $editionId),
            default => true, // 'all' or unknown
        };
    }

    private function validateOnly(array $voucher, int $editionId): true|WP_Error { … }
    private function validateExcept(array $voucher, int $editionId): true|WP_Error { … }
}
```

Returns `WP_Error('wrong_edition', …)` matching today's error code so existing callers don't break.

### Step 3 — VoucherProrater (NEW)

`Modules/Invoicing/Helpers/VoucherProrater.php` — pure math:

```php
final class VoucherProrater
{
    public function prorate(Money $subtotal, int $sessionCount): Money
    {
        $divisor = max($sessionCount, 1); // 0-session edition collapses to 1
        return Money::cents((int) round($subtotal->inCents() / $divisor));
    }
}
```

### Step 4 — VoucherService integration

- Inject `VoucherScopeValidator` + `VoucherProrater` + `SessionService` via constructor (4 params total — within the 5-param NTDST limit).
- `validateVoucher()`: replace the inline `edition_id` check (line 138) with a call to `$this->scopeValidator->validate($voucher, $editionId)`. Return the WP_Error if any.
- `calculateDiscount()`: change signature to `calculateDiscount(array $voucher, Money $subtotal, ?int $editionId = null): Money`. If `apply_mode === 'single_session'` AND `$editionId !== null`, count sessions via `SessionService::getSessionsForEdition()` and replace `$subtotal` with `$this->prorater->prorate($subtotal, $sessionCount)` *before* the existing match statement. Match logic stays as-is.
- `hydrateVoucher()`: ensure new fields default sensibly (`scope_mode = 'all'`, `excluded_edition_ids = []`, `apply_mode = 'full'`).

### Step 5 — Admin form

In `renderVoucherMetabox()`:
- Replace the `"Beperkt tot editie"` section (lines 198–223) with a 3-way radio + edition picker (`scope_mode = 'only'`) + multi-select edition list (`scope_mode = 'except'`). Use Alpine.js `x-show` on the wrapper divs based on the radio value (existing admin dashboard already uses Alpine — verify on page).
- Add `"Toepassen op"` dropdown under the discount section.

In `handleSave()`:
- Sanitize `scope_mode` against the 3-value whitelist.
- Sanitize `excluded_edition_ids` as `array_map('absint', …)` from the multi-select.
- Sanitize `apply_mode` against the 2-value whitelist.
- When `scope_mode !== 'only'`, force `edition_id = 0` so stale data doesn't leak.
- When `scope_mode !== 'except'`, force `excluded_edition_ids = []`.

In `defineListColumns()` / `renderListColumn()`:
- The existing "Editie" column (lines 555–568) should render `scope_mode` summary: "Alle edities" / "Alleen: X" / "Behalve: X, Y, Z".

### Step 6 — Tests

New integration tests in `tests/Integration/VoucherServiceIntegrationTest.php`:

1. `validationRejectsExcludedEdition()` — `scope_mode='except'` with target in `excluded_edition_ids` → `WP_Error('wrong_edition')`
2. `validationAcceptsNonExcludedEdition()` — `scope_mode='except'` with target NOT in list → success
3. `validationLegacyEditionIdActsAsOnlyMode()` — voucher with no `scope_mode` but `edition_id > 0` still rejects wrong edition (back-compat)
4. `calculatesProratedDiscountForSingleSessionMode()` — `apply_mode='single_session'` + 4-session edition + 100% discount → 25% of price
5. `calculatesProratedPercentageDiscount()` — `apply_mode='single_session'` + 4-session edition + 50% discount → 12.5% of price
6. `prorateFallsBackForZeroSessionEdition()` — `apply_mode='single_session'` + e-learning edition (0 sessions) → full subtotal discount (math collapses)

---

## Risks & Mitigations

| Risk | Mitigation |
|---|---|
| Existing call sites break when signature of `calculateDiscount` changes | Optional `$editionId = null` parameter — default behaviour unchanged for 1 of 4 callers; other 3 already have `editionId` in scope and gain prorating capability "for free" |
| Legacy vouchers (no `scope_mode`) suddenly become invalid | `VoucherScopeValidator` infers `scope_mode = 'only'` when `scope_mode` is empty but `edition_id > 0`. Tested explicitly. |
| Admin saves "behalve" with empty list | Equivalent to "Alle" — no extra rejection. Form should default-empty array; validator returns `true` for empty exclusion list. |
| Trajectory flow (single `null` caller) regresses | Validator short-circuits `true` when `editionId === null`. Prorater never called. Verified by existing trajectory test if any. |

---

## Out of Scope (Stays Deferred)

- Member voucher auto-generation
- Annual member voucher renewal cron
- Voucher reversal on cancellation
- Per-user redemption caps beyond the existing 1-per-user-per-voucher
- Trajectory-level voucher scope (currently any voucher works for any trajectory)

---

## Estimated Effort

| Item | LOC |
|---|---|
| CPT field additions | 30 |
| VoucherScopeValidator + VoucherProrater | 80 |
| VoucherService wiring (+ hydrate) | 40 |
| Admin form (3-way radio + multi-select + apply-mode + save) | 100 |
| Admin list column update | 15 |
| Tests (6 new scenarios) | 180 |
| **Total** | **~445 LOC** |

Compared to the old plan's 460 LOC for 5 hard-coded categories with the same effort, this delivers admin-tunable scope + prorating without baking VAD-specific business rules into code.
