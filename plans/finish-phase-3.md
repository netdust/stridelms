# Finish Phase 3: Quote/Invoice System

## Problem/Feature

Phase 3 is 95% complete. Three items remain to finish the quote system:

1. **Quote auto-lock 14 days before edition start** - Cron job to automatically lock quotes
2. **Billing edit restriction enforcement** - Prevent user edits 14 days before edition
3. **OGM payment reference** - Belgian structured communication for payment reconciliation

## Acceptance Criteria

- [ ] Quotes auto-lock 14 days before linked edition start date
- [ ] User quote update form rejects edits if edition starts in ≤14 days
- [ ] OGM payment reference generated and included in exports
- [ ] Tests added for new functionality

## Implementation

### 1. Quote Auto-Lock Cron Job

**File:** `mu-plugins/stride-core/invoicing/QuoteService.php`

Register Action Scheduler hook and lock method:

```php
// In constructor:
add_action('init', [$this, 'registerScheduledTasks']);
add_action('stride/quote/lock_approaching', [$this, 'lockApproachingEditions']);

// New methods:
public function registerScheduledTasks(): void
{
    if (!as_has_scheduled_action('stride/quote/lock_approaching')) {
        as_schedule_recurring_action(
            strtotime('tomorrow 6:00'),
            DAY_IN_SECONDS,
            'stride/quote/lock_approaching'
        );
    }
}

public function lockApproachingEditions(): void
{
    // Find editions starting in exactly 14 days
    $targetDate = date('Y-m-d', strtotime('+14 days'));

    // Query quotes with item_type='edition' where edition starts on target date
    $quotes = $this->findQuotes([
        'status' => self::STATUS_DRAFT,
        'item_type' => 'edition',
        'locked' => false,
    ]);

    foreach ($quotes as $quote) {
        $edition = ntdst_get(EditionService::class)->getEdition($quote['item_id']);
        if (!$edition) continue;

        $startDate = $edition['start_date'] ?? '';
        if ($startDate === $targetDate) {
            $this->lockQuote($quote['id'], 'auto_14_day_rule');
        }
    }
}

public function lockQuote(int $quoteId, string $reason = ''): true|WP_Error
{
    $result = $this->updateQuoteFields($quoteId, [
        self::FIELD_LOCKED => true,
    ]);

    if ($result) {
        $this->addNote($quoteId, sprintf(
            __('Offerte automatisch vergrendeld (%s).', 'stride'),
            $reason ?: 'manual'
        ));
        do_action('stride/quote/locked', $quoteId, $reason);
    }

    return $result ? true : new WP_Error('lock_failed', 'Could not lock quote');
}
```

### 2. User Billing Edit Restriction

**File:** `mu-plugins/stride-core/handlers/QuoteUpdateHandler.php`

Add date check before allowing updates:

```php
public function canUserEditQuote(int $quoteId): true|WP_Error
{
    $quote = $this->quoteService->getQuote($quoteId);
    if (!$quote) {
        return new WP_Error('not_found', __('Offerte niet gevonden.', 'stride'));
    }

    // Check locked flag
    if ($quote['locked']) {
        return new WP_Error('locked', __('Deze offerte is vergrendeld en kan niet meer worden gewijzigd.', 'stride'));
    }

    // Check 14-day rule for edition quotes
    if ($quote['item_type'] === 'edition') {
        $edition = ntdst_get(EditionService::class)->getEdition($quote['item_id']);
        if ($edition) {
            $startDate = $edition['start_date'] ?? '';
            if ($startDate) {
                $daysUntil = (strtotime($startDate) - time()) / DAY_IN_SECONDS;
                if ($daysUntil <= 14) {
                    return new WP_Error(
                        'deadline_passed',
                        __('Factuurgegevens kunnen niet meer worden gewijzigd minder dan 14 dagen voor aanvang.', 'stride')
                    );
                }
            }
        }
    }

    return true;
}
```

### 3. OGM Payment Reference Generator

**File:** `mu-plugins/stride-core/invoicing/Helpers/OGMGenerator.php` (new)

Belgian structured communication format: `+++NNN/NNNN/NNNCC+++`

```php
<?php
namespace ntdst\Stride\invoicing\Helpers;

/**
 * Belgian OGM (Gestructureerde Mededeling) Generator
 *
 * Format: +++NNN/NNNN/NNNCC+++
 * - First 10 digits: base number (quote ID padded)
 * - Last 2 digits: modulo 97 check digits
 */
class OGMGenerator
{
    /**
     * Generate OGM from quote number
     *
     * @param string $quoteNumber Quote number like "VADQ-2026-00123"
     * @return string OGM like "+++000/0001/23097+++"
     */
    public function generate(string $quoteNumber): string
    {
        // Extract numeric part: VADQ-2026-00123 -> 202600123
        preg_match('/VADQ-(\d{4})-(\d+)/', $quoteNumber, $matches);
        if (count($matches) !== 3) {
            return '';
        }

        $year = $matches[1];
        $seq = ltrim($matches[2], '0') ?: '0';

        // Build 10-digit base (year 4 digits + sequence 6 digits)
        $base = substr($year, -2) . str_pad($seq, 8, '0', STR_PAD_LEFT);

        // Calculate modulo 97 check digits
        $checkDigits = $this->calculateCheckDigits($base);

        // Format as +++NNN/NNNN/NNNCC+++
        $full = $base . $checkDigits;
        return sprintf('+++%s/%s/%s+++',
            substr($full, 0, 3),
            substr($full, 3, 4),
            substr($full, 7, 5)
        );
    }

    /**
     * Calculate modulo 97 check digits
     */
    private function calculateCheckDigits(string $base): string
    {
        $mod = (int) $base % 97;
        $check = $mod === 0 ? 97 : $mod;
        return str_pad((string) $check, 2, '0', STR_PAD_LEFT);
    }

    /**
     * Validate OGM format and check digits
     */
    public function validate(string $ogm): bool
    {
        // Remove formatting
        $clean = preg_replace('/[^0-9]/', '', $ogm);
        if (strlen($clean) !== 12) {
            return false;
        }

        $base = substr($clean, 0, 10);
        $check = substr($clean, 10, 2);

        return $this->calculateCheckDigits($base) === $check;
    }
}
```

**Update:** `mu-plugins/stride-core/invoicing/Export/ExactOnlineExporter.php`

Add OGM column:

```php
// In getColumnConfig():
$columns = [
    // ... existing columns ...
    'PaymentReference' => fn($q) => $this->getOGM($q),
];

// New method:
private function getOGM(array $quote): string
{
    $generator = new \ntdst\Stride\invoicing\Helpers\OGMGenerator();
    return $generator->generate($quote['number'] ?? '');
}
```

**Update:** `mu-plugins/stride-core/invoicing/QuoteService.php`

Store OGM on quote creation:

```php
// In createQuoteForItem(), after generating quote number:
$ogmGenerator = new Helpers\OGMGenerator();
$ogm = $ogmGenerator->generate($quoteNumber);

// Store in quote fields:
$postId = $model->create([
    // ... existing fields ...
    self::FIELD_PAYMENT_REFERENCE => $ogm,
]);
```

Add field constant and schema:

```php
public const FIELD_PAYMENT_REFERENCE = 'payment_reference';

// In getFieldSchema():
self::FIELD_PAYMENT_REFERENCE => ['type' => 'string', 'default' => ''],
```

### 4. Test Script Updates

**File:** `scripts/test-quote-lifecycle.php`

Add tests for new functionality:

```php
// E. QUOTE LOCKING (4 tests)
private function testQuoteLocking(): void
{
    echo "E. Testing Quote Locking...\n";

    // E1. Lock quote manually
    // E2. Locked quote rejects updates
    // E3. Auto-lock for edition starting in 14 days
    // E4. OGM payment reference generated correctly
}
```

## Files to Modify

| File | Changes |
|------|---------|
| `invoicing/QuoteService.php` | Add cron registration, `lockQuote()`, `lockApproachingEditions()`, OGM field |
| `handlers/QuoteUpdateHandler.php` | Add `canUserEditQuote()` with 14-day check |
| `invoicing/Helpers/OGMGenerator.php` | **NEW** - Belgian payment reference generator |
| `invoicing/Export/ExactOnlineExporter.php` | Add PaymentReference column |
| `scripts/test-quote-lifecycle.php` | Add locking and OGM tests |

## References

- QuoteService lock field: `mu-plugins/stride-core/invoicing/QuoteService.php:76`
- Cancellation 14-day rule: `mu-plugins/stride-core/enrollment/EnrollmentService.php:349`
- Action Scheduler pattern: `mu-plugins/stride-core/invoicing/Helpers/VATValidator.php:131`
- Belgian OGM format: https://www.bnpparibasfortis.be/rsc/contrib/document/structured_communication.pdf
