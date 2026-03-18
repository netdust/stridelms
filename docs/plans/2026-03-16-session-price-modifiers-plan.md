# Session Price Modifiers — Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Allow session price modifiers to affect quote pricing when users select sessions during enrollment completion.

**Architecture:** Add `price_modifier` (int, cents) to `vad_session` CPT. On session selection completion, a new hook listener in `QuoteService` adds/replaces `session_modifier` line items on the existing quote and recalculates totals. Admin sees the modifier field per session row; users see price annotations during selection.

**Tech Stack:** PHP 8.3, NTDST Data Manager, WordPress hooks, Alpine.js, Tailwind CSS

**Design Spec:** `docs/plans/2026-03-16-session-price-modifiers-design.md`

---

## File Map

| File | Action | Purpose |
|------|--------|---------|
| `stride-core/Modules/Edition/SessionCPT.php` | Modify | Add `price_modifier` field definition |
| `stride-core/Modules/Edition/SessionService.php` | Modify | Include `price_modifier` in `formatSession()` and `formatSessionArray()` |
| `stride-core/Modules/Edition/Admin/EditionSessionsMetabox.php` | Modify | Add price modifier input to session form + data attr to row |
| `stride-core/Modules/Edition/Admin/EditionAdminController.php` | Modify | Add `price_modifier` to `sanitizeSessionData()` |
| `stride-core/assets/js/admin/edition-admin.js` | Modify | Send/populate `price_modifier` in session form JS |
| `stride-core/Modules/Invoicing/QuoteService.php` | Modify | Add `onSessionSelectionCompleted()` hook listener |
| `stride-core/Modules/Enrollment/EnrollmentCompletion.php` | Modify | Allow re-completion of `session_selection` task |
| `stridence/templates/forms/completion/task-session_selection.php` | Modify | Show price annotations and notice |
| `tests/Unit/QuoteCalculatorTest.php` | Create | Test session modifier calculation |
| `tests/Unit/QuoteServiceModifierTest.php` | Create | Test `onSessionSelectionCompleted` logic |

---

## Task 1: Add `price_modifier` field to SessionCPT

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Modules/Edition/SessionCPT.php:37-96`

- [ ] **Step 1: Add `price_modifier` to field definitions**

In `SessionCPT::getFields()`, add after the `optional` field:

```php
'price_modifier' => [
    'type' => 'int',
    'label' => 'Prijswijziging',
    'description' => 'Price modifier in cents (positive = surcharge, negative = discount)',
],
```

- [ ] **Step 2: Verify field registration**

Run:
```bash
ddev exec wp eval "
\$model = ntdst_data()->get('vad_session');
\$schema = \$model->getSchema();
echo isset(\$schema['fields']['price_modifier']) ? 'OK' : 'MISSING';
"
```
Expected: `OK`

- [ ] **Step 3: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Edition/SessionCPT.php
git commit -m "feat(session): add price_modifier field to SessionCPT"
```

---

## Task 2: Include `price_modifier` in SessionService formatters

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Modules/Edition/SessionService.php:214-279`

- [ ] **Step 1: Add to `formatSession()` (WP_Post-based)**

In `SessionService::formatSession()`, add after the `optional` line (line 230):

```php
'price_modifier' => (int) $this->repository->getField($post->ID, 'price_modifier', 0),
```

- [ ] **Step 2: Add to `formatSessionArray()` (array-based)**

In `SessionService::formatSessionArray()`, add after the `lesson_ids` line (line 277):

```php
'price_modifier' => (int) ($meta['_ntdst_price_modifier'] ?? $meta['price_modifier'] ?? 0),
```

- [ ] **Step 3: Verify output**

Run (requires at least one session to exist):
```bash
ddev exec wp eval "
\$svc = ntdst_get(\Stride\Modules\Edition\SessionService::class);
\$sessions = \$svc->getSessionsForEdition(/* use a valid edition ID */);
if (!empty(\$sessions)) {
    echo array_key_exists('price_modifier', \$sessions[0]) ? 'OK' : 'MISSING';
} else {
    echo 'No sessions found — field added, test later';
}
"
```
Expected: `OK` or info message

- [ ] **Step 4: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Edition/SessionService.php
git commit -m "feat(session): include price_modifier in session formatters"
```

---

## Task 3: Admin UI — session form and row

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Modules/Edition/Admin/EditionSessionsMetabox.php:126-158,160-278`
- Modify: `web/app/mu-plugins/stride-core/Modules/Edition/Admin/EditionAdminController.php:928-965`
- Modify: `web/app/mu-plugins/stride-core/assets/js/admin/edition-admin.js:209-220,323-335`

- [ ] **Step 1: Add `data-price-modifier` to session row**

In `EditionSessionsMetabox::renderSessionRow()`, add a new data attribute to the `<tr>` tag (after `data-lesson-ids` on line 138):

```php
data-price-modifier="<?php echo esc_attr((string) ($session['price_modifier'] ?? 0)); ?>"
```

Also show the modifier in the table row. Add a new column header in `render()` method and a corresponding `<td>` in `renderSessionRow()`:

In the table headers (around line 82), add before the actions column:
```php
<th class="column-price-mod"><?php esc_html_e('Prijs ±', 'stride'); ?></th>
```

In `renderSessionRow()`, add before the actions `<td>` (before line 148):
```php
<td class="column-price-mod">
    <?php
    $modifier = (int) ($session['price_modifier'] ?? 0);
    if ($modifier !== 0):
        $sign = $modifier > 0 ? '+' : '';
        echo esc_html($sign . number_format($modifier / 100, 2, ',', '.'));
    else:
        echo '-';
    endif;
    ?>
</td>
```

Update the colspan in `renderSessionFormTemplate` (line 164) from `6` to `7`.

- [ ] **Step 2: Add price modifier input to session form**

In `EditionSessionsMetabox::renderSessionFormTemplate()`, add a new field inside the common `stride-datetime-section` div (after the slot dropdown, around line 194):

```php
<div class="stride-field">
    <label>
        <?php esc_html_e('Prijswijziging (€)', 'stride'); ?>
    </label>
    <input type="number" name="session_price_modifier" step="0.01" placeholder="0,00"
           style="width: 100px;">
    <p class="description" id="stride-price-modifier-hint" style="display: none; font-size: 11px; color: #646970;">
        <?php esc_html_e('Alleen actief bij sessiekeuze', 'stride'); ?>
    </p>
</div>
```

- [ ] **Step 3: Add `price_modifier` to `sanitizeSessionData()`**

In `EditionAdminController::sanitizeSessionData()`, add after the `slot` field (line 934):

```php
// Convert euro input to cents for storage
$priceModifierInput = $input['price_modifier'] ?? '';
if ($priceModifierInput !== '' && $priceModifierInput !== null) {
    $data['price_modifier'] = (int) round(floatval(str_replace(',', '.', (string) $priceModifierInput)) * 100);
} else {
    $data['price_modifier'] = 0;
}
```

- [ ] **Step 4: Update JavaScript — populate form on edit**

In `edition-admin.js`, after the slot population block (around line 219), add:

```javascript
// Set price modifier (convert cents to euro)
var priceModifier = parseInt($editRow.data('price-modifier') || 0, 10);
if (priceModifier !== 0) {
    $formRow.find('input[name="session_price_modifier"]').val((priceModifier / 100).toFixed(2).replace('.', ','));
} else {
    $formRow.find('input[name="session_price_modifier"]').val('');
}
```

- [ ] **Step 5: Update JavaScript — send price_modifier on save**

In `edition-admin.js`, in the data object construction (around line 333), add:

```javascript
price_modifier: $form.find('input[name="session_price_modifier"]').val() || '',
```

- [ ] **Step 6: Update JavaScript — show/hide hint based on slot**

In `edition-admin.js`, add a change handler for the slot dropdown to toggle the hint:

```javascript
// Show price modifier hint when no slot is selected
$(document).on('change', 'select[name="session_slot"]', function() {
    var $hint = $(this).closest('.stride-session-form').find('#stride-price-modifier-hint');
    $hint.toggle($(this).val() === '');
});
```

- [ ] **Step 7: Test admin UI manually**

1. Go to an edition with sessions at `https://stride.ddev.site/wp/wp-admin`
2. Edit a session, verify the "Prijswijziging (€)" field appears
3. Enter `45,00`, save — verify it persists on re-edit
4. Enter `-20,50`, save — verify negative value persists
5. Verify the "Prijs ±" column shows the value in the session table

- [ ] **Step 8: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Edition/Admin/EditionSessionsMetabox.php \
        web/app/mu-plugins/stride-core/Modules/Edition/Admin/EditionAdminController.php \
        web/app/mu-plugins/stride-core/assets/js/admin/edition-admin.js
git commit -m "feat(session): add price modifier input to admin session form"
```

---

## Task 4: Allow re-completion of session_selection task

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Modules/Enrollment/EnrollmentCompletion.php:340-365`

The `completeTask()` method returns `true` early when a task is already completed (line 344), which means re-submissions won't fire the `stride/enrollment/task_completed` hook. For session selection specifically, we need the hook to fire on re-submission so the quote can be updated.

- [ ] **Step 1: Allow re-completion for session_selection**

In `EnrollmentCompletion::completeTask()`, replace the early return on line 344:

```php
if ($tasks[$taskType]['status'] === 'completed') {
    return true;
}
```

With:

```php
if ($tasks[$taskType]['status'] === 'completed') {
    // Session selection allows re-submission to update quote pricing
    if ($taskType === 'session_selection') {
        $tasks = $this->markTaskComplete($tasks, $taskType, $data);
        $repo->updateCompletionTasks($registrationId, $tasks);

        do_action('stride/enrollment/task_completed', [
            'registration_id' => $registrationId,
            'task_type' => $taskType,
            'tasks' => $tasks,
        ]);
    }
    return true;
}
```

- [ ] **Step 2: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Enrollment/EnrollmentCompletion.php
git commit -m "feat(enrollment): allow session_selection re-completion for price updates"
```

---

## Task 5: Quote update on session selection — unit tests

**Files:**
- Create: `tests/Unit/QuoteServiceModifierTest.php`

- [ ] **Step 1: Write tests for onSessionSelectionCompleted logic**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Tests for session modifier quote update logic.
 *
 * Tests the pure logic of building session modifier line items
 * and the conditions under which quotes should/shouldn't be updated.
 */
class QuoteServiceModifierTest extends TestCase
{
    /**
     * Test: Build modifier items from sessions with modifiers in slots.
     */
    public function testBuildModifierItemsFromSlottedSessions(): void
    {
        $sessions = [
            ['id' => 10, 'slot' => 'keuze_a', 'price_modifier' => 4500, 'title' => 'Dag 1 + boek', 'edition_id' => 1],
            ['id' => 11, 'slot' => 'keuze_a', 'price_modifier' => 0, 'title' => 'Dag 1 standaard', 'edition_id' => 1],
            ['id' => 12, 'slot' => '', 'price_modifier' => 2000, 'title' => 'Bonus sessie', 'edition_id' => 1],
        ];
        $selectedIds = [10, 12];
        $editionId = 1;

        $items = $this->buildModifierItems($sessions, $selectedIds, $editionId);

        // Only session 10 qualifies: selected, has slot, non-zero modifier
        // Session 12 has no slot — modifier ignored
        $this->assertCount(1, $items);
        $this->assertEquals(10, $items[0]['id']);
        $this->assertEquals('session_modifier', $items[0]['type']);
        $this->assertEquals(4500, $items[0]['unit_price']);
        $this->assertEquals('Sessie: Dag 1 + boek', $items[0]['title']);
    }

    /**
     * Test: Negative modifiers produce negative line items.
     */
    public function testNegativeModifierProducesNegativeLineItem(): void
    {
        $sessions = [
            ['id' => 10, 'slot' => 'keuze_a', 'price_modifier' => -2000, 'title' => 'Goedkopere optie', 'edition_id' => 1],
        ];

        $items = $this->buildModifierItems($sessions, [10], 1);

        $this->assertCount(1, $items);
        $this->assertEquals(-2000, $items[0]['unit_price']);
        $this->assertEquals(-2000, $items[0]['total']);
    }

    /**
     * Test: Sessions not belonging to edition are skipped.
     */
    public function testCrossEditionSessionsSkipped(): void
    {
        $sessions = [
            ['id' => 10, 'slot' => 'keuze_a', 'price_modifier' => 4500, 'title' => 'Wrong edition', 'edition_id' => 99],
        ];

        $items = $this->buildModifierItems($sessions, [10], 1);

        $this->assertCount(0, $items);
    }

    /**
     * Test: Multiple sessions from same slot with pick_count > 1.
     */
    public function testMultipleSelectionsFromSameSlot(): void
    {
        $sessions = [
            ['id' => 10, 'slot' => 'keuze_a', 'price_modifier' => 2000, 'title' => 'Sessie X', 'edition_id' => 1],
            ['id' => 11, 'slot' => 'keuze_a', 'price_modifier' => 3000, 'title' => 'Sessie Y', 'edition_id' => 1],
        ];

        $items = $this->buildModifierItems($sessions, [10, 11], 1);

        $this->assertCount(2, $items);
        $this->assertEquals(2000, $items[0]['unit_price']);
        $this->assertEquals(3000, $items[1]['unit_price']);
    }

    /**
     * Test: Strip old modifier items and replace with new.
     */
    public function testReplaceModifierItems(): void
    {
        $existingItems = [
            ['type' => 'edition', 'title' => 'Editie A', 'quantity' => 1, 'unit_price' => 15000, 'total' => 15000],
            ['type' => 'session_modifier', 'id' => 10, 'title' => 'Old modifier', 'quantity' => 1, 'unit_price' => 2000, 'total' => 2000],
        ];

        $newModifiers = [
            ['id' => 11, 'type' => 'session_modifier', 'title' => 'Sessie: New', 'quantity' => 1, 'unit_price' => 4500, 'total' => 4500],
        ];

        $result = $this->replaceModifierItems($existingItems, $newModifiers);

        $this->assertCount(2, $result);
        $this->assertEquals('edition', $result[0]['type']);
        $this->assertEquals('session_modifier', $result[1]['type']);
        $this->assertEquals(4500, $result[1]['unit_price']);
    }

    /**
     * Test: No modifiers strips all existing modifier items.
     */
    public function testEmptyModifiersStripsAll(): void
    {
        $existingItems = [
            ['type' => 'edition', 'title' => 'Editie A', 'quantity' => 1, 'unit_price' => 15000, 'total' => 15000],
            ['type' => 'session_modifier', 'id' => 10, 'title' => 'Old', 'quantity' => 1, 'unit_price' => 2000, 'total' => 2000],
        ];

        $result = $this->replaceModifierItems($existingItems, []);

        $this->assertCount(1, $result);
        $this->assertEquals('edition', $result[0]['type']);
    }

    // === Pure logic extracted for testability ===

    /**
     * Build modifier line items from selected sessions.
     * Mirrors the logic that will live in QuoteService.
     */
    private function buildModifierItems(array $allSessions, array $selectedIds, int $editionId): array
    {
        $items = [];

        foreach ($allSessions as $session) {
            $sessionId = (int) $session['id'];

            // Must be selected
            if (!in_array($sessionId, $selectedIds, true)) {
                continue;
            }

            // Must belong to the edition
            if ((int) $session['edition_id'] !== $editionId) {
                continue;
            }

            // Must have a slot (guard rule)
            if (empty($session['slot'])) {
                continue;
            }

            // Must have non-zero modifier
            $modifier = (int) ($session['price_modifier'] ?? 0);
            if ($modifier === 0) {
                continue;
            }

            $items[] = [
                'id' => $sessionId,
                'type' => 'session_modifier',
                'title' => 'Sessie: ' . ($session['title'] ?: 'Sessie #' . $sessionId),
                'quantity' => 1,
                'unit_price' => $modifier,
                'total' => $modifier,
            ];
        }

        return $items;
    }

    /**
     * Replace session_modifier items in existing quote items.
     */
    private function replaceModifierItems(array $existingItems, array $newModifiers): array
    {
        // Strip old session_modifier items
        $filtered = array_filter($existingItems, fn($item) => ($item['type'] ?? '') !== 'session_modifier');

        // Append new ones
        return array_values(array_merge($filtered, $newModifiers));
    }
}
```

- [ ] **Step 2: Run tests to verify they pass**

```bash
ddev exec vendor/bin/phpunit --filter QuoteServiceModifierTest --testsuite Unit
```
Expected: All 6 tests pass.

- [ ] **Step 3: Commit**

```bash
git add tests/Unit/QuoteServiceModifierTest.php
git commit -m "test(invoicing): add unit tests for session modifier quote logic"
```

---

## Task 6: Implement `onSessionSelectionCompleted` in QuoteService

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Modules/Invoicing/QuoteService.php:41-64,111`

- [ ] **Step 1: Register hook listener in `init()`**

In `QuoteService::init()`, after the existing `add_action` on line 63, add:

```php
// Update quote when session selection completes (may add price modifiers)
add_action('stride/enrollment/task_completed', [$this, 'onSessionSelectionCompleted']);
```

- [ ] **Step 2: Implement `onSessionSelectionCompleted()`**

Add this method to `QuoteService` after `onRegistrationCancelled()`:

```php
/**
 * Handle session selection completion — update quote with price modifiers.
 *
 * @param array{registration_id: int, task_type: string, tasks: array} $data
 */
public function onSessionSelectionCompleted(array $data): void
{
    // Only handle session_selection tasks
    if (($data['task_type'] ?? '') !== 'session_selection') {
        return;
    }

    $registrationId = (int) ($data['registration_id'] ?? 0);
    if (!$registrationId) {
        return;
    }

    // Get session IDs from task data
    $tasks = $data['tasks'] ?? [];
    $sessionIds = $tasks['session_selection']['data']['session_ids'] ?? [];
    if (empty($sessionIds)) {
        return;
    }
    $sessionIds = array_map('intval', $sessionIds);

    // Find quote for this registration
    $quote = $this->getQuoteByRegistration($registrationId);
    if (!$quote) {
        ntdst_log('invoicing')->info('No quote for registration, skipping session modifiers', [
            'registration_id' => $registrationId,
        ]);
        return;
    }

    $quoteId = (int) $quote['id'];
    $status = $quote['status_enum'] ?? QuoteStatus::tryFrom($quote['status'] ?? '');

    // Cancelled quotes: silent no-op
    if ($status === QuoteStatus::Cancelled) {
        return;
    }

    // Get registration to find edition_id
    $repo = ntdst_get(\Stride\Modules\Enrollment\RegistrationRepository::class);
    $registration = $repo->find($registrationId);
    if (!$registration) {
        return;
    }
    $editionId = (int) $registration->edition_id;
    $userId = (int) $registration->user_id;

    // Fetch sessions and build modifier items
    $sessionService = ntdst_get(\Stride\Modules\Edition\SessionService::class);
    $allSessions = $sessionService->getSessionsForEdition($editionId);

    $modifierItems = [];
    foreach ($allSessions as $session) {
        $sessionId = (int) $session['id'];

        if (!in_array($sessionId, $sessionIds, true)) {
            continue;
        }
        if (empty($session['slot'])) {
            continue;
        }
        $modifier = (int) ($session['price_modifier'] ?? 0);
        if ($modifier === 0) {
            continue;
        }

        $modifierItems[] = [
            'id' => $sessionId,
            'type' => 'session_modifier',
            'title' => 'Sessie: ' . ($session['title'] ?: 'Sessie #' . $sessionId),
            'quantity' => 1,
            'unit_price' => $modifier,
            'total' => $modifier,
        ];
    }

    // If no modifiers and no existing modifier items, nothing to do
    $existingItems = $quote['items'] ?? [];
    if (is_string($existingItems)) {
        $existingItems = json_decode($existingItems, true) ?: [];
    }
    $hasExistingModifiers = !empty(array_filter($existingItems, fn($i) => ($i['type'] ?? '') === 'session_modifier'));

    if (empty($modifierItems) && !$hasExistingModifiers) {
        return;
    }

    // Non-draft quotes: fire blocked hook
    if ($status !== QuoteStatus::Draft) {
        $isLocked = (bool) ($quote['locked'] ?? false);
        if ($status === QuoteStatus::Sent || $status === QuoteStatus::Exported || $isLocked) {
            do_action('stride/quote/session_modifier_blocked', [
                'quote_id' => $quoteId,
                'registration_id' => $registrationId,
                'user_id' => $userId,
                'modifiers' => array_map(fn($m) => [
                    'session_id' => $m['id'],
                    'title' => $m['title'],
                    'amount_cents' => $m['unit_price'],
                ], $modifierItems),
            ]);

            ntdst_log('invoicing')->warning('Session modifier blocked: quote not editable', [
                'quote_id' => $quoteId,
                'registration_id' => $registrationId,
                'status' => $status?->value ?? 'unknown',
            ]);
            return;
        }
    }

    // Replace modifier items
    $filteredItems = array_values(array_filter($existingItems, fn($i) => ($i['type'] ?? '') !== 'session_modifier'));
    $updatedItems = array_merge($filteredItems, $modifierItems);

    // Recalculate totals preserving existing discount
    $existingDiscount = (int) ($quote['discount'] ?? 0);
    $moneyItems = array_map(fn($item) => [
        'title' => $item['title'],
        'quantity' => $item['quantity'],
        'unit_price' => \Stride\Domain\Money::cents($item['unit_price']),
        'type' => $item['type'] ?? 'edition',
    ], $updatedItems);
    $discount = $existingDiscount > 0 ? \Stride\Domain\Money::cents($existingDiscount) : null;
    $totals = QuoteCalculator::calculateTotals($moneyItems, $discount);

    // Update quote
    $this->repository->updateMeta($quoteId, [
        'items' => $updatedItems,
        'subtotal' => $totals['subtotal']->inCents(),
        'tax' => $totals['tax']->inCents(),
        'total' => $totals['total']->inCents(),
    ]);

    ntdst_log('invoicing')->info('Session modifiers applied to quote', [
        'quote_id' => $quoteId,
        'registration_id' => $registrationId,
        'modifier_count' => count($modifierItems),
        'new_total' => $totals['total']->inCents(),
    ]);

    do_action('stride/quote/modifiers_applied', [
        'quote_id' => $quoteId,
        'registration_id' => $registrationId,
        'user_id' => $userId,
        'modifiers' => $modifierItems,
        'new_total' => $totals['total']->inCents(),
    ]);
}
```

- [ ] **Step 3: Run existing tests to check no regressions**

```bash
ddev exec vendor/bin/phpunit --testsuite Unit
```
Expected: All tests pass.

- [ ] **Step 4: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Invoicing/QuoteService.php
git commit -m "feat(invoicing): update quote on session selection with price modifiers"
```

---

## Task 7: User-facing session selection template

**Files:**
- Modify: `web/app/themes/stridence/templates/forms/completion/task-session_selection.php:87-126,204-214`

- [ ] **Step 1: Add price annotation to `$renderOption` closure**

Replace the `$renderOption` closure (lines 87-126) with a version that shows price modifiers. After the title `<span>` inside the closure (around line 107), add:

```php
<?php
$priceModifier = (int) ($session['price_modifier'] ?? 0);
$sessionSlot = $session['slot'] ?? '';
if ($priceModifier !== 0 && $sessionSlot !== ''):
    $sign = $priceModifier > 0 ? '+' : '';
    $formatted = $sign . '€ ' . number_format(abs($priceModifier) / 100, 2, ',', '.');
    if ($priceModifier < 0) {
        $formatted = '-€ ' . number_format(abs($priceModifier) / 100, 2, ',', '.');
    }
?>
    <span class="text-xs font-medium <?= $priceModifier > 0 ? 'text-amber-600' : 'text-green-600' ?> ml-1">
        (<?= esc_html($formatted) ?>)
    </span>
<?php endif; ?>
```

- [ ] **Step 2: Add notice below the selection form**

Before the submit button div (line 204), add a notice that only shows when sessions have modifiers:

```php
<?php
// Check if any slotted session has a price modifier
$hasModifiers = false;
foreach ($sessions as $s) {
    if (!empty($s['slot']) && (int) ($s['price_modifier'] ?? 0) !== 0) {
        $hasModifiers = true;
        break;
    }
}
if ($hasModifiers):
?>
    <p class="text-xs text-text-muted mt-3 flex items-center gap-1">
        <?= stridence_icon('info', 'w-3.5 h-3.5') ?>
        <?= esc_html__('Je offerte wordt automatisch aangepast op basis van je sessiekeuze.', 'stridence') ?>
    </p>
<?php endif; ?>
```

- [ ] **Step 3: Test manually**

1. Set a session with slot and price_modifier via admin
2. Navigate to user dashboard → completion tasks → session selection
3. Verify price annotation shows next to the session
4. Verify the notice text appears at the bottom

- [ ] **Step 4: Commit**

```bash
git add web/app/themes/stridence/templates/forms/completion/task-session_selection.php
git commit -m "feat(theme): show session price modifiers in selection UI"
```

---

## Verification Stages (MANDATORY)

> Run AFTER all implementation tasks. NOT done until all stages pass.
> If ANY stage fails: fix → re-run that stage → continue.

### Stage V1: Static Analysis

```bash
ddev exec vendor/bin/phpunit --testsuite Unit
```

Expected: All tests pass including new `QuoteServiceModifierTest`.

### Stage V2: Unit Tests

**Test files:**
- `tests/Unit/QuoteServiceModifierTest.php` (created in Task 5)

```bash
ddev exec vendor/bin/phpunit --filter QuoteServiceModifierTest --testsuite Unit
```

Expected: All 6 tests pass.

### Stage V3: Full Regression

```bash
ddev exec vendor/bin/phpunit
```

Expected: Zero failures across all suites.

### Stage V4: Smoke Test Checklist

```markdown
## Manual Smoke Test

- [ ] Admin: Open an edition → Sessions tab
      Expected: "Prijs ±" column visible, price modifier input in session form
- [ ] Admin: Add session with slot "keuze_a" and modifier "45,00" → Save
      Expected: Session saved, "+45,00" shown in Prijs ± column
- [ ] Admin: Edit session, verify modifier field shows "45,00"
      Expected: Value persists correctly
- [ ] Admin: Add session with slot "keuze_a" and modifier "-20,50" → Save
      Expected: "-20,50" shown in Prijs ± column
- [ ] Admin: Add session WITHOUT slot and modifier "30,00" → Save
      Expected: "-" shown in Prijs ± column (modifier ignored, no slot)
- [ ] User: Go to session selection completion task
      Expected: Sessions with modifiers show price annotation (+€ 45,00) or (-€ 20,50)
- [ ] User: Notice text visible: "Je offerte wordt automatisch aangepast..."
      Expected: Only shows when at least one session has a modifier
- [ ] User: Select a session with modifier → Submit
      Expected: Quote updated with session_modifier line item, total recalculated
- [ ] Admin: Check quote → Items should show "Sessie: [title]" line item
      Expected: Modifier line item visible with correct amount
- [ ] User: Re-select different sessions → Submit
      Expected: Old modifier items replaced, new ones added, total recalculated
- [ ] Admin: Lock quote, then user re-selects
      Expected: Quote NOT modified, warning logged
```
