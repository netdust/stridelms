import { test, expect } from '@playwright/test';

/**
 * Inline Edit Component Tests for Stridence Theme
 *
 * Tests the inlineEdit and inlineEditSection Alpine components.
 * These components allow inline field editing with API integration.
 */

test.describe('inlineEdit Component', () => {
  test.beforeEach(async ({ page }) => {
    // Mock the ntdstAPI to avoid actual network calls
    await page.goto('/');
    await page.evaluate(() => {
      (window as any).ntdstAPI = {
        call: async (action: string, params: any) => {
          // Simulate success after small delay
          await new Promise((r) => setTimeout(r, 100));
          return { success: true };
        },
      };
    });
  });

  test('clicking value enters edit mode', async ({ page }) => {
    await page.evaluate(() => {
      document.body.innerHTML = `
        <div x-data="inlineEdit({ value: 'John Doe', action: 'update_profile', field: 'name' })" id="inline-edit">
          <span x-show="!editing" @click="startEdit()" id="display-value" x-text="value"></span>
          <input x-show="editing" x-model="value" x-ref="input" id="edit-input" type="text" />
        </div>
      `;
      (window as any).Alpine.initTree(document.getElementById('inline-edit'));
    });

    // Initially shows value, input is hidden
    await expect(page.locator('#display-value')).toBeVisible();
    await expect(page.locator('#display-value')).toHaveText('John Doe');
    await expect(page.locator('#edit-input')).toBeHidden();

    // Click to edit
    await page.click('#display-value');

    // Now input is visible
    await expect(page.locator('#display-value')).toBeHidden();
    await expect(page.locator('#edit-input')).toBeVisible();
    await expect(page.locator('#edit-input')).toBeFocused();
  });

  test('escape key cancels edit without saving', async ({ page }) => {
    await page.evaluate(() => {
      document.body.innerHTML = `
        <div x-data="inlineEdit({ value: 'Original', action: 'update', field: 'test' })" id="inline-edit">
          <span x-show="!editing" @click="startEdit()" id="display-value" x-text="value"></span>
          <input x-show="editing" x-model="value" x-ref="input" @keydown="handleKeydown($event)" id="edit-input" type="text" />
        </div>
      `;
      (window as any).Alpine.initTree(document.getElementById('inline-edit'));
    });

    // Enter edit mode
    await page.click('#display-value');
    await expect(page.locator('#edit-input')).toBeVisible();

    // Change value
    await page.fill('#edit-input', 'Changed Value');

    // Press escape
    await page.keyboard.press('Escape');

    // Should revert to original and exit edit mode
    await expect(page.locator('#display-value')).toBeVisible();
    await expect(page.locator('#display-value')).toHaveText('Original');
  });

  test('enter key saves the value', async ({ page }) => {
    let savedParams: any = null;

    await page.evaluate(() => {
      (window as any).ntdstAPI = {
        call: async (action: string, params: any) => {
          (window as any).__savedParams = params;
          await new Promise((r) => setTimeout(r, 50));
          return { success: true };
        },
      };

      document.body.innerHTML = `
        <div x-data="inlineEdit({ value: 'Original', action: 'test_save', field: 'name' })" id="inline-edit">
          <span x-show="!editing" @click="startEdit()" id="display-value" x-text="value"></span>
          <input x-show="editing" x-model="value" x-ref="input" @keydown="handleKeydown($event)" id="edit-input" type="text" />
          <span x-show="saving" id="saving-indicator">Saving...</span>
        </div>
      `;
      (window as any).Alpine.initTree(document.getElementById('inline-edit'));
    });

    // Enter edit mode
    await page.click('#display-value');

    // Change value
    await page.fill('#edit-input', 'New Value');

    // Press enter
    await page.keyboard.press('Enter');

    // Should show saving state briefly
    await page.waitForTimeout(200);

    // Should exit edit mode with new value
    await expect(page.locator('#display-value')).toBeVisible();
    await expect(page.locator('#display-value')).toHaveText('New Value');
  });

  test('no change exits edit mode without API call', async ({ page }) => {
    let apiCalled = false;

    await page.evaluate(() => {
      (window as any).ntdstAPI = {
        call: async () => {
          (window as any).__apiCalled = true;
          return { success: true };
        },
      };
      (window as any).__apiCalled = false;

      document.body.innerHTML = `
        <div x-data="inlineEdit({ value: 'Same', action: 'test', field: 'x' })" id="inline-edit">
          <span x-show="!editing" @click="startEdit()" id="display-value" x-text="value"></span>
          <input x-show="editing" x-model="value" x-ref="input" @keydown="handleKeydown($event)" id="edit-input" type="text" />
          <button x-show="editing" @click="saveEdit()" id="save-btn">Save</button>
        </div>
      `;
      (window as any).Alpine.initTree(document.getElementById('inline-edit'));
    });

    // Enter edit mode
    await page.click('#display-value');

    // Don't change value, just save
    await page.click('#save-btn');

    // Should exit edit mode
    await expect(page.locator('#display-value')).toBeVisible();

    // API should not have been called
    apiCalled = await page.evaluate(() => (window as any).__apiCalled);
    expect(apiCalled).toBeFalsy();
  });

  test('API error shows error message', async ({ page }) => {
    await page.evaluate(() => {
      (window as any).ntdstAPI = {
        call: async () => {
          throw new Error('Netwerk fout');
        },
      };

      document.body.innerHTML = `
        <div x-data="inlineEdit({ value: 'Test', action: 'fail_action', field: 'x' })" id="inline-edit">
          <span x-show="!editing" @click="startEdit()" id="display-value" x-text="value"></span>
          <input x-show="editing" x-model="value" x-ref="input" id="edit-input" type="text" />
          <button x-show="editing" @click="saveEdit()" id="save-btn">Save</button>
          <span x-show="error" x-text="error" id="error-msg" class="text-red-500"></span>
        </div>
      `;
      (window as any).Alpine.initTree(document.getElementById('inline-edit'));
    });

    // Enter edit mode and change value
    await page.click('#display-value');
    await page.fill('#edit-input', 'Changed');

    // Try to save (will fail)
    await page.click('#save-btn');
    await page.waitForTimeout(200);

    // Error should be shown
    await expect(page.locator('#error-msg')).toBeVisible();
    await expect(page.locator('#error-msg')).toContainText('Netwerk fout');

    // Should still be in edit mode
    await expect(page.locator('#edit-input')).toBeVisible();
  });
});

test.describe('inlineEditSection Component', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('/');
    await page.evaluate(() => {
      (window as any).ntdstAPI = {
        call: async (action: string, params: any) => {
          await new Promise((r) => setTimeout(r, 50));
          return { success: true };
        },
      };
    });
  });

  test('section edit button enters edit mode for all fields', async ({ page }) => {
    await page.evaluate(() => {
      document.body.innerHTML = `
        <div x-data="inlineEditSection({ action: 'update_billing', params: { id: 1 }, fields: { company: 'Acme', vat: 'BE123' } })" id="section">
          <template x-if="!editing">
            <div>
              <dl>
                <dt>Company</dt>
                <dd x-text="fields.company" id="display-company"></dd>
                <dt>VAT</dt>
                <dd x-text="fields.vat" id="display-vat"></dd>
              </dl>
              <button @click="startEdit()" id="edit-btn">Bewerken</button>
            </div>
          </template>
          <template x-if="editing">
            <div>
              <input x-model="fields.company" id="input-company" />
              <input x-model="fields.vat" id="input-vat" />
              <button @click="saveEdit()" id="save-btn">Opslaan</button>
              <button @click="cancelEdit()" id="cancel-btn">Annuleren</button>
            </div>
          </template>
        </div>
      `;
      (window as any).Alpine.initTree(document.getElementById('section'));
    });

    // Initially shows display values
    await expect(page.locator('#display-company')).toBeVisible();
    await expect(page.locator('#display-vat')).toBeVisible();
    await expect(page.locator('#edit-btn')).toBeVisible();

    // Click edit
    await page.click('#edit-btn');

    // Now shows input fields
    await expect(page.locator('#input-company')).toBeVisible();
    await expect(page.locator('#input-vat')).toBeVisible();
    await expect(page.locator('#save-btn')).toBeVisible();
    await expect(page.locator('#cancel-btn')).toBeVisible();
  });

  test('cancel restores all original values', async ({ page }) => {
    await page.evaluate(() => {
      document.body.innerHTML = `
        <div x-data="inlineEditSection({ action: 'update', params: {}, fields: { name: 'John', email: 'john@test.com' } })" id="section">
          <template x-if="!editing">
            <div id="display-mode">
              <span x-text="fields.name" id="display-name"></span>
              <span x-text="fields.email" id="display-email"></span>
              <button @click="startEdit()" id="edit-btn">Edit</button>
            </div>
          </template>
          <template x-if="editing">
            <div id="edit-mode">
              <input x-model="fields.name" id="input-name" />
              <input x-model="fields.email" id="input-email" />
              <button @click="cancelEdit()" id="cancel-btn">Cancel</button>
            </div>
          </template>
        </div>
      `;
      (window as any).Alpine.initTree(document.getElementById('section'));
    });

    // Enter edit mode
    await page.click('#edit-btn');

    // Change both values
    await page.fill('#input-name', 'Jane');
    await page.fill('#input-email', 'jane@test.com');

    // Cancel
    await page.click('#cancel-btn');

    // Values should be restored
    await expect(page.locator('#display-name')).toHaveText('John');
    await expect(page.locator('#display-email')).toHaveText('john@test.com');
  });

  test('save sends all fields to API', async ({ page }) => {
    await page.evaluate(() => {
      (window as any).__savedPayload = null;
      (window as any).ntdstAPI = {
        call: async (action: string, params: any) => {
          (window as any).__savedPayload = { action, params };
          return { success: true };
        },
      };

      document.body.innerHTML = `
        <div x-data="inlineEditSection({
          action: 'stride_update_billing',
          params: { quote_id: 42 },
          fields: { org: 'Company', city: 'Brussels' }
        })" id="section">
          <template x-if="!editing">
            <button @click="startEdit()" id="edit-btn">Edit</button>
          </template>
          <template x-if="editing">
            <div>
              <input x-model="fields.org" id="input-org" />
              <input x-model="fields.city" id="input-city" />
              <button @click="saveEdit()" id="save-btn">Save</button>
            </div>
          </template>
        </div>
      `;
      (window as any).Alpine.initTree(document.getElementById('section'));
    });

    // Edit and save
    await page.click('#edit-btn');
    await page.fill('#input-org', 'New Company');
    await page.fill('#input-city', 'Antwerp');
    await page.click('#save-btn');

    await page.waitForTimeout(200);

    // Verify API was called with correct payload
    const savedPayload = await page.evaluate(() => (window as any).__savedPayload);
    expect(savedPayload.action).toBe('stride_update_billing');
    expect(savedPayload.params.quote_id).toBe(42);
    expect(savedPayload.params.org).toBe('New Company');
    expect(savedPayload.params.city).toBe('Antwerp');
  });

  test('saving state is shown during API call', async ({ page }) => {
    await page.evaluate(() => {
      (window as any).ntdstAPI = {
        call: async () => {
          await new Promise((r) => setTimeout(r, 500)); // Slow API
          return { success: true };
        },
      };

      document.body.innerHTML = `
        <div x-data="inlineEditSection({ action: 'test', params: {}, fields: { x: 'a' } })" id="section">
          <template x-if="!editing">
            <button @click="startEdit()" id="edit-btn">Edit</button>
          </template>
          <template x-if="editing">
            <div>
              <input x-model="fields.x" id="input-x" />
              <button @click="saveEdit()" :disabled="saving" id="save-btn">
                <span x-show="!saving">Save</span>
                <span x-show="saving" id="saving-text">Saving...</span>
              </button>
            </div>
          </template>
        </div>
      `;
      (window as any).Alpine.initTree(document.getElementById('section'));
    });

    // Edit and change value
    await page.click('#edit-btn');
    await page.fill('#input-x', 'b');

    // Click save - should show saving state
    await page.click('#save-btn');

    // Saving indicator should be visible
    await expect(page.locator('#saving-text')).toBeVisible();

    // Wait for API to complete
    await page.waitForTimeout(600);

    // Should exit edit mode
    await expect(page.locator('#edit-btn')).toBeVisible();
  });

  test('API error shows error and stays in edit mode', async ({ page }) => {
    await page.evaluate(() => {
      (window as any).ntdstAPI = {
        call: async () => {
          throw new Error('Validatie fout: ongeldig BTW-nummer');
        },
      };

      document.body.innerHTML = `
        <div x-data="inlineEditSection({ action: 'test', params: {}, fields: { vat: 'invalid' } })" id="section">
          <template x-if="!editing">
            <button @click="startEdit()" id="edit-btn">Edit</button>
          </template>
          <template x-if="editing">
            <div>
              <input x-model="fields.vat" id="input-vat" />
              <button @click="saveEdit()" id="save-btn">Save</button>
              <div x-show="error" x-text="error" id="error-msg" class="text-red-500"></div>
            </div>
          </template>
        </div>
      `;
      (window as any).Alpine.initTree(document.getElementById('section'));
    });

    await page.click('#edit-btn');
    await page.click('#save-btn');

    await page.waitForTimeout(200);

    // Error should be visible
    await expect(page.locator('#error-msg')).toBeVisible();
    await expect(page.locator('#error-msg')).toContainText('BTW-nummer');

    // Should still be in edit mode
    await expect(page.locator('#input-vat')).toBeVisible();
  });
});

test.describe('Inline Edit - Accessibility', () => {
  test('input receives focus when entering edit mode', async ({ page }) => {
    await page.goto('/');

    await page.evaluate(() => {
      document.body.innerHTML = `
        <div x-data="inlineEdit({ value: 'Test', action: 'x', field: 'y' })" id="inline-edit">
          <span x-show="!editing" @click="startEdit()" id="display-value" x-text="value" tabindex="0"></span>
          <input x-show="editing" x-model="value" x-ref="input" id="edit-input" type="text" />
        </div>
      `;
      (window as any).Alpine.initTree(document.getElementById('inline-edit'));
    });

    await page.click('#display-value');
    await expect(page.locator('#edit-input')).toBeFocused();
  });

  test('keyboard navigation works', async ({ page }) => {
    await page.goto('/');

    await page.evaluate(() => {
      (window as any).ntdstAPI = { call: async () => ({ success: true }) };

      document.body.innerHTML = `
        <div x-data="inlineEdit({ value: 'Focus Test', action: 'x', field: 'y' })" id="inline-edit">
          <span x-show="!editing" @click="startEdit()" id="display-value" x-text="value" tabindex="0"></span>
          <input x-show="editing" x-model="value" x-ref="input" @keydown="handleKeydown($event)" id="edit-input" type="text" />
        </div>
      `;
      (window as any).Alpine.initTree(document.getElementById('inline-edit'));
    });

    // Focus display value with keyboard
    await page.focus('#display-value');
    await page.keyboard.press('Enter'); // Should not trigger edit (no handler)

    // Click to enter edit mode
    await page.click('#display-value');
    await expect(page.locator('#edit-input')).toBeFocused();

    // Type and escape
    await page.keyboard.type(' Modified');
    await page.keyboard.press('Escape');

    await expect(page.locator('#display-value')).toHaveText('Focus Test');
  });
});
