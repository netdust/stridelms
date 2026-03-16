/**
 * Session Price Modifiers — E2E UAT Tests
 *
 * Tests the price modifier feature on the session form within the
 * Edition admin screen:
 *
 *   1. Price modifier field exists in the session form
 *   2. Column header "Prijs ±" is present in the sessions table
 *   3. Positive, negative, and zero modifiers display correctly
 *   4. Price modifier persists when editing an existing session
 *   5. Hint text toggles based on slot selection
 *
 * Prerequisites:
 *   ddev exec wp eval-file scripts/seed.php
 */

import { test as baseTest, expect, type Page } from '@playwright/test';
import * as fs from 'fs';
import {
  wpAdminLogin,
  gotoEditionList,
  gotoEditEdition,
  getFirstEditionRow,
  waitForAjax,
  WP_ADMIN,
} from './fixtures/admin-helpers';

// ---------------------------------------------------------------------------
// Auth fixture — log in once and reuse cookies for all tests
// ---------------------------------------------------------------------------
const AUTH_FILE = '/tmp/stride-admin-auth.json';

const test = baseTest.extend({
  storageState: async ({ browser, baseURL }, use) => {
    let needsLogin = true;
    if (fs.existsSync(AUTH_FILE)) {
      const age = Date.now() - fs.statSync(AUTH_FILE).mtimeMs;
      needsLogin = age > 5 * 60 * 1000;
    }
    if (needsLogin) {
      const ctx = await browser.newContext({ ignoreHTTPSErrors: true, baseURL: baseURL! });
      const page = await ctx.newPage();
      await wpAdminLogin(page);
      await ctx.storageState({ path: AUTH_FILE });
      await page.close();
      await ctx.close();
    }
    await use(AUTH_FILE);
  },
});

test.use({ actionTimeout: 15000 });

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/** Navigate to the first seed edition's edit screen. */
async function openFirstEdition(page: Page): Promise<number> {
  await gotoEditionList(page);
  const { postId } = await getFirstEditionRow(page);
  expect(postId).toBeGreaterThan(0);
  await gotoEditEdition(page, postId);
  return postId;
}

/** Open the inline session form by clicking "Sessie toevoegen". */
async function openSessionForm(page: Page): Promise<void> {
  await page.click('#stride-add-session-btn');
  await expect(page.locator('.stride-session-form-row')).toBeVisible();
}

/**
 * Fill the session form with required fields and an optional price modifier,
 * then save via AJAX.
 *
 * @param priceModifier  Value to type in the price modifier input (e.g. "45,00").
 *                        Pass empty string to leave blank.
 */
async function fillAndSaveSession(
  page: Page,
  opts: {
    date?: string;
    startTime?: string;
    endTime?: string;
    priceModifier?: string;
  } = {},
): Promise<void> {
  const form = page.locator('.stride-session-form-row');

  // Required: date
  await form.locator('input[name="session_date"]').fill(opts.date ?? '2026-08-01');
  await form.locator('input[name="session_start_time"]').fill(opts.startTime ?? '09:00');
  await form.locator('input[name="session_end_time"]').fill(opts.endTime ?? '17:00');

  // Price modifier
  if (opts.priceModifier !== undefined && opts.priceModifier !== '') {
    await form.locator('input[name="session_price_modifier"]').fill(opts.priceModifier);
  }

  // Save via AJAX
  const ajaxPromise = waitForAjax(page);
  await page.click('.stride-session-save');
  const response = await ajaxPromise;
  const body = await response.json();
  expect(body.success).toBe(true);
}

// ============================================================================
// 1. PRICE MODIFIER FIELD IN SESSION FORM
// ============================================================================

test.describe('Price modifier field in session form', () => {
  test.beforeEach(async ({ page }) => {
    await openFirstEdition(page);
    await openSessionForm(page);
  });

  test('price modifier input exists in session form', async ({ page }) => {
    const form = page.locator('.stride-session-form-row');
    const input = form.locator('input[name="session_price_modifier"]');

    await expect(input).toBeVisible();
    // Should be a number input with step
    await expect(input).toHaveAttribute('type', 'number');
    await expect(input).toHaveAttribute('step', '0.01');
  });

  test('price modifier label reads "Prijswijziging (€)"', async ({ page }) => {
    const form = page.locator('.stride-session-form-row');
    // The label is in the same .stride-field as the input
    const field = form.locator('.stride-field:has(input[name="session_price_modifier"])');
    const label = field.locator('label');

    await expect(label).toContainText('Prijswijziging');
    await expect(label).toContainText('€');
  });
});

// ============================================================================
// 2. PRIJS ± COLUMN HEADER
// ============================================================================

test.describe('Prijs ± column header', () => {
  test('sessions table has "Prijs ±" column header', async ({ page }) => {
    await openFirstEdition(page);

    const sessionsTable = page.locator('.stride-sessions-table');
    await expect(sessionsTable).toBeVisible();

    const priceModHeader = sessionsTable.locator('thead th.column-price-mod');
    await expect(priceModHeader).toBeVisible();
    await expect(priceModHeader).toHaveText('Prijs ±');
  });
});

// ============================================================================
// 3. PRICE MODIFIER COLUMN SHOWS FORMATTED VALUES
// ============================================================================

test.describe('Price modifier column display', () => {
  test.describe.configure({ mode: 'serial' });

  let editionId: number;

  test('add session with positive modifier shows "+45,00"', async ({ page }) => {
    editionId = await openFirstEdition(page);
    await openSessionForm(page);

    await fillAndSaveSession(page, {
      date: '2026-09-10',
      startTime: '09:00',
      endTime: '12:00',
      priceModifier: '45',
    });

    // After AJAX save, the sessions table is refreshed with server HTML.
    // Find the session row with date 2026-09-10
    const row = page.locator('.session-row[data-date="2026-09-10"]').last();
    await expect(row).toBeVisible();

    const priceCell = row.locator('td.column-price-mod');
    await expect(priceCell).toContainText('+45,00');
  });

  test('add session with negative modifier shows "-20,50"', async ({ page }) => {
    await gotoEditEdition(page, editionId);
    await openSessionForm(page);

    await fillAndSaveSession(page, {
      date: '2026-09-11',
      startTime: '13:00',
      endTime: '16:00',
      priceModifier: '-20.50',
    });

    const row = page.locator('.session-row[data-date="2026-09-11"]').last();
    await expect(row).toBeVisible();

    const priceCell = row.locator('td.column-price-mod');
    await expect(priceCell).toContainText('-20,50');
  });

  test('add session with no modifier shows "-"', async ({ page }) => {
    await gotoEditEdition(page, editionId);
    await openSessionForm(page);

    await fillAndSaveSession(page, {
      date: '2026-09-12',
      startTime: '10:00',
      endTime: '11:00',
      priceModifier: '',
    });

    const row = page.locator('.session-row[data-date="2026-09-12"]').last();
    await expect(row).toBeVisible();

    const priceCell = row.locator('td.column-price-mod');
    // Zero or empty modifier should display "-"
    const text = await priceCell.textContent();
    expect(text?.trim()).toBe('-');
  });
});

// ============================================================================
// 4. PRICE MODIFIER PERSISTS ON EDIT
// ============================================================================

test.describe('Price modifier persistence on edit', () => {
  test.describe.configure({ mode: 'serial' });

  let editionId: number;

  test('create session with modifier, edit shows correct value', async ({ page }) => {
    editionId = await openFirstEdition(page);
    await openSessionForm(page);

    await fillAndSaveSession(page, {
      date: '2026-10-05',
      startTime: '09:00',
      endTime: '17:00',
      priceModifier: '75',
    });

    // Find the row we just created
    const row = page.locator('.session-row[data-date="2026-10-05"]').last();
    await expect(row).toBeVisible();

    // Verify data attribute stores cents
    const modifierAttr = await row.getAttribute('data-price-modifier');
    expect(Number(modifierAttr)).toBe(7500);

    // Click edit on this row
    await row.locator('.stride-edit-session').click();

    // Form should appear with the price modifier pre-filled
    const form = page.locator('.stride-session-form-row');
    await expect(form).toBeVisible();

    const input = form.locator('input[name="session_price_modifier"]');
    const value = await input.inputValue();
    // Value should be "75,00" (cents converted to euro with comma decimal)
    expect(value).toBe('75,00');
  });

  test('update modifier value and verify row updates', async ({ page }) => {
    await gotoEditEdition(page, editionId);

    // Find the session row with date 2026-10-05
    const row = page.locator('.session-row[data-date="2026-10-05"]').last();
    await expect(row).toBeVisible();

    // Click edit
    await row.locator('.stride-edit-session').click();

    const form = page.locator('.stride-session-form-row');
    await expect(form).toBeVisible();

    // Clear and set new value
    const input = form.locator('input[name="session_price_modifier"]');
    await input.clear();
    await input.fill('-20');

    // Save
    const ajaxPromise = waitForAjax(page);
    await page.click('.stride-session-save');
    const response = await ajaxPromise;
    const body = await response.json();
    expect(body.success).toBe(true);

    // Verify updated row shows -20,00
    const updatedRow = page.locator('.session-row[data-date="2026-10-05"]').last();
    await expect(updatedRow).toBeVisible();

    const priceCell = updatedRow.locator('td.column-price-mod');
    await expect(priceCell).toContainText('-20,00');
  });
});

// ============================================================================
// 5. HINT TEXT WHEN NO SLOT SELECTED
// ============================================================================

test.describe('Price modifier hint text', () => {
  test('hint toggles based on slot selection', async ({ page }) => {
    await openFirstEdition(page);
    await openSessionForm(page);

    const form = page.locator('.stride-session-form-row');
    const slotDropdown = form.locator('select[name="session_slot"]');

    // Skip if no slot dropdown (edition has no session_slots configured)
    if (!(await slotDropdown.isVisible().catch(() => false))) {
      test.skip(true, 'No slot dropdown — edition has no session_slots configured');
      return;
    }

    const hint = form.locator('#stride-price-modifier-hint');

    // Default: no slot selected → hint should be visible
    // (The JS shows the hint when slot value is empty)
    // The hint starts hidden via inline style; the JS toggles it on slot change.
    // Trigger the slot change event to initialize state.
    await slotDropdown.selectOption('');
    await slotDropdown.dispatchEvent('change');
    await expect(hint).toBeVisible();

    // Select a slot → hint should hide
    const options = await slotDropdown.locator('option:not([value=""])').all();
    if (options.length > 0) {
      const firstSlotValue = await options[0].getAttribute('value');
      await slotDropdown.selectOption(firstSlotValue!);
      await slotDropdown.dispatchEvent('change');
      await expect(hint).not.toBeVisible();

      // Deselect back to "Geen slot" → hint should reappear
      await slotDropdown.selectOption('');
      await slotDropdown.dispatchEvent('change');
      await expect(hint).toBeVisible();
    }
  });
});
