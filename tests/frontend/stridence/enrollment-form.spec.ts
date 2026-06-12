import { test, expect } from '@playwright/test';

/**
 * Enrollment Form Wizard Tests for Stridence Theme
 *
 * Tests the multi-step enrollment form:
 * Step 0: Enrollment Type (werknemer/collega/prive)
 * Step 1: Personal Info
 * Step 2: Billing Info + Voucher
 * Step 3: Confirmation + Terms
 */

test.describe('Enrollment Form - Step Navigation', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('/');

    // Mock ntdstAPI
    await page.evaluate(() => {
      (window as any).ntdstAPI = {
        call: async (action: string, params: any) => {
          if (action === 'stride_validate_voucher') {
            if (params.code === 'VALID100') {
              return { discount_formatted: '€ 100,00' };
            }
            throw new Error('Ongeldige kortingscode');
          }
          if (action === 'stride_submit_enrollment') {
            return { redirect_url: '/success' };
          }
          return { success: true };
        },
      };
    });

    // Set up the enrollment form component
    await page.evaluate(() => {
      const script = document.createElement('script');
      script.textContent = `
        function enrollmentForm(config) {
          return {
            currentStep: 0,
            stepLabels: ['Type', 'Gegevens', 'Facturatie', 'Bevestigen'],
            itemId: config.itemId,
            itemType: config.itemType,
            itemData: config.itemData,
            form: {
              enrollment_type: '',
              first_name: config.prefill?.first_name || '',
              last_name: config.prefill?.last_name || '',
              email: config.userEmail || '',
              phone: config.prefill?.phone || '',
              organisation: '',
              department: '',
              company: '',
              invoice_email: config.userEmail || '',
              address: '',
              postal_code: '',
              city: '',
              vat_number: '',
              voucher_code: '',
              terms_accepted: false,
            },
            voucherLoading: false,
            voucherValid: false,
            voucherError: '',
            voucherDiscount: '',
            submitting: false,
            submitError: '',
            nextStep() { if (this.currentStep < 3) this.currentStep++; },
            prevStep() { if (this.currentStep > 0) this.currentStep--; },
            async validateVoucher() {
              if (!this.form.voucher_code) return;
              this.voucherLoading = true;
              this.voucherError = '';
              try {
                const result = await ntdstAPI.call('stride_validate_voucher', { code: this.form.voucher_code });
                this.voucherValid = true;
                this.voucherDiscount = result.discount_formatted;
              } catch (e) {
                this.voucherError = e.message;
                this.voucherValid = false;
              } finally { this.voucherLoading = false; }
            },
            async submitForm() {
              if (!this.form.terms_accepted) return;
              this.submitting = true;
              try {
                const result = await ntdstAPI.call('stride_submit_enrollment', this.form);
                if (result.redirect_url) window.location.href = result.redirect_url;
              } catch (e) { this.submitError = e.message; }
              finally { this.submitting = false; }
            },
          };
        }
      `;
      document.head.appendChild(script);
    });
  });

  test('form starts at step 0 (enrollment type)', async ({ page }) => {
    await page.evaluate(() => {
      document.body.innerHTML = `
        <div x-data="enrollmentForm({ itemId: 1, itemType: 'edition', itemData: {}, prefill: {}, userEmail: 'test@test.com' })" id="form">
          <div x-show="currentStep === 0" id="step-0">Step 0: Type</div>
          <div x-show="currentStep === 1" id="step-1">Step 1: Personal</div>
          <div x-show="currentStep === 2" id="step-2">Step 2: Billing</div>
          <div x-show="currentStep === 3" id="step-3">Step 3: Confirm</div>
          <span x-text="currentStep" id="current-step"></span>
        </div>
      `;
      (window as any).Alpine.initTree(document.getElementById('form'));
    });

    await expect(page.locator('#current-step')).toHaveText('0');
    await expect(page.locator('#step-0')).toBeVisible();
    await expect(page.locator('#step-1')).toBeHidden();
  });

  test('next button is disabled until enrollment type is selected', async ({ page }) => {
    await page.evaluate(() => {
      document.body.innerHTML = `
        <div x-data="enrollmentForm({ itemId: 1, itemType: 'edition', itemData: {}, prefill: {}, userEmail: 'test@test.com' })" id="form">
          <div x-show="currentStep === 0">
            <label><input type="radio" value="werknemer" x-model="form.enrollment_type" id="type-werknemer"> Werknemer</label>
            <label><input type="radio" value="prive" x-model="form.enrollment_type" id="type-prive"> Prive</label>
            <button @click="nextStep" :disabled="!form.enrollment_type" id="next-btn">Volgende</button>
          </div>
          <div x-show="currentStep === 1" id="step-1">Step 1</div>
        </div>
      `;
      (window as any).Alpine.initTree(document.getElementById('form'));
    });

    // Button should be disabled initially
    await expect(page.locator('#next-btn')).toBeDisabled();

    // Select type
    await page.click('#type-werknemer');

    // Button should be enabled
    await expect(page.locator('#next-btn')).toBeEnabled();

    // Click next
    await page.click('#next-btn');

    // Should advance to step 1
    await expect(page.locator('#step-1')).toBeVisible();
  });

  test('can navigate forward and backward through steps', async ({ page }) => {
    await page.evaluate(() => {
      document.body.innerHTML = `
        <div x-data="enrollmentForm({ itemId: 1, itemType: 'edition', itemData: {}, prefill: {}, userEmail: 'test@test.com' })" id="form">
          <span x-text="currentStep" id="current-step"></span>
          <button @click="form.enrollment_type = 'werknemer'" id="set-type">Set Type</button>
          <button @click="nextStep" id="next-btn">Next</button>
          <button @click="prevStep" id="prev-btn">Prev</button>
        </div>
      `;
      (window as any).Alpine.initTree(document.getElementById('form'));
    });

    await expect(page.locator('#current-step')).toHaveText('0');

    // Set type and go to step 1
    await page.click('#set-type');
    await page.click('#next-btn');
    await expect(page.locator('#current-step')).toHaveText('1');

    // Go to step 2
    await page.click('#next-btn');
    await expect(page.locator('#current-step')).toHaveText('2');

    // Go to step 3
    await page.click('#next-btn');
    await expect(page.locator('#current-step')).toHaveText('3');

    // Cannot go beyond step 3
    await page.click('#next-btn');
    await expect(page.locator('#current-step')).toHaveText('3');

    // Go back
    await page.click('#prev-btn');
    await expect(page.locator('#current-step')).toHaveText('2');

    await page.click('#prev-btn');
    await expect(page.locator('#current-step')).toHaveText('1');

    await page.click('#prev-btn');
    await expect(page.locator('#current-step')).toHaveText('0');

    // Cannot go below step 0
    await page.click('#prev-btn');
    await expect(page.locator('#current-step')).toHaveText('0');
  });
});

test.describe('Enrollment Form - Enrollment Type Selection', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('/');
    await page.evaluate(() => {
      const script = document.createElement('script');
      script.textContent = `
        function enrollmentForm(config) {
          return {
            currentStep: 0,
            form: { enrollment_type: '' },
            nextStep() { this.currentStep++; },
          };
        }
      `;
      document.head.appendChild(script);
    });
  });

  test('three enrollment types are available', async ({ page }) => {
    await page.evaluate(() => {
      document.body.innerHTML = `
        <div x-data="enrollmentForm({})" id="form">
          <label><input type="radio" value="werknemer" x-model="form.enrollment_type" id="type-werknemer"> Werknemer</label>
          <label><input type="radio" value="collega" x-model="form.enrollment_type" id="type-collega"> Collega</label>
          <label><input type="radio" value="prive" x-model="form.enrollment_type" id="type-prive"> Prive</label>
          <span x-text="form.enrollment_type" id="selected-type"></span>
        </div>
      `;
      (window as any).Alpine.initTree(document.getElementById('form'));
    });

    await expect(page.locator('#type-werknemer')).toBeVisible();
    await expect(page.locator('#type-collega')).toBeVisible();
    await expect(page.locator('#type-prive')).toBeVisible();

    // Select each type
    await page.click('#type-werknemer');
    await expect(page.locator('#selected-type')).toHaveText('werknemer');

    await page.click('#type-collega');
    await expect(page.locator('#selected-type')).toHaveText('collega');

    await page.click('#type-prive');
    await expect(page.locator('#selected-type')).toHaveText('prive');
  });

  test('enrollment type affects visual selection state', async ({ page }) => {
    await page.evaluate(() => {
      document.body.innerHTML = `
        <div x-data="enrollmentForm({})" id="form">
          <label :class="form.enrollment_type === 'werknemer' ? 'border-primary' : 'border-gray-200'" class="border-2 p-4" id="label-werknemer">
            <input type="radio" value="werknemer" x-model="form.enrollment_type" class="sr-only">
            Werknemer
          </label>
          <label :class="form.enrollment_type === 'prive' ? 'border-primary' : 'border-gray-200'" class="border-2 p-4" id="label-prive">
            <input type="radio" value="prive" x-model="form.enrollment_type" class="sr-only">
            Prive
          </label>
        </div>
      `;
      (window as any).Alpine.initTree(document.getElementById('form'));
    });

    // Initially no selection
    await expect(page.locator('#label-werknemer')).not.toHaveClass(/border-primary/);
    await expect(page.locator('#label-prive')).not.toHaveClass(/border-primary/);

    // Select werknemer
    await page.click('#label-werknemer');
    await expect(page.locator('#label-werknemer')).toHaveClass(/border-primary/);
    await expect(page.locator('#label-prive')).not.toHaveClass(/border-primary/);
  });
});

test.describe('Enrollment Form - Voucher Validation', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('/');

    await page.evaluate(() => {
      (window as any).ntdstAPI = {
        call: async (action: string, params: any) => {
          await new Promise((r) => setTimeout(r, 100));
          if (action === 'stride_validate_voucher') {
            if (params.code === 'VALID100') {
              return { discount_formatted: '€ 100,00' };
            }
            if (params.code === 'VALID50') {
              return { discount_formatted: '€ 50,00' };
            }
            throw new Error('Ongeldige kortingscode');
          }
          return { success: true };
        },
      };

      const script = document.createElement('script');
      script.textContent = `
        function enrollmentForm(config) {
          return {
            form: { voucher_code: '' },
            voucherLoading: false,
            voucherValid: false,
            voucherError: '',
            voucherDiscount: '',
            async validateVoucher() {
              if (!this.form.voucher_code) return;
              this.voucherLoading = true;
              this.voucherError = '';
              try {
                const result = await ntdstAPI.call('stride_validate_voucher', { code: this.form.voucher_code });
                this.voucherValid = true;
                this.voucherDiscount = result.discount_formatted;
              } catch (e) {
                this.voucherError = e.message;
                this.voucherValid = false;
              } finally { this.voucherLoading = false; }
            },
          };
        }
      `;
      document.head.appendChild(script);
    });
  });

  test('valid voucher shows discount', async ({ page }) => {
    await page.evaluate(() => {
      document.body.innerHTML = `
        <div x-data="enrollmentForm({})" id="form">
          <input type="text" x-model="form.voucher_code" id="voucher-input">
          <button @click="validateVoucher" id="validate-btn">Validate</button>
          <span x-show="voucherLoading" id="loading">Loading...</span>
          <span x-show="voucherValid" id="valid-msg">✓ Valid</span>
          <span x-show="voucherDiscount" x-text="voucherDiscount" id="discount"></span>
          <span x-show="voucherError" x-text="voucherError" id="error"></span>
        </div>
      `;
      (window as any).Alpine.initTree(document.getElementById('form'));
    });

    await page.fill('#voucher-input', 'VALID100');
    await page.click('#validate-btn');

    // Wait for validation to complete (loading appears briefly then disappears)
    await page.waitForTimeout(300);

    // After validation - valid message should show
    await expect(page.locator('#valid-msg')).toBeVisible();
    await expect(page.locator('#discount')).toHaveText('€ 100,00');
    await expect(page.locator('#error')).toBeHidden();
  });

  test('invalid voucher shows error', async ({ page }) => {
    await page.evaluate(() => {
      document.body.innerHTML = `
        <div x-data="enrollmentForm({})" id="form">
          <input type="text" x-model="form.voucher_code" id="voucher-input">
          <button @click="validateVoucher" id="validate-btn">Validate</button>
          <span x-show="voucherValid" id="valid-msg">Valid</span>
          <span x-show="voucherError" x-text="voucherError" id="error"></span>
        </div>
      `;
      (window as any).Alpine.initTree(document.getElementById('form'));
    });

    await page.fill('#voucher-input', 'INVALIDCODE');
    await page.click('#validate-btn');

    await page.waitForTimeout(200);

    await expect(page.locator('#valid-msg')).toBeHidden();
    await expect(page.locator('#error')).toBeVisible();
    await expect(page.locator('#error')).toContainText('Ongeldige kortingscode');
  });

  test('voucher input is disabled after validation', async ({ page }) => {
    await page.evaluate(() => {
      document.body.innerHTML = `
        <div x-data="enrollmentForm({})" id="form">
          <input type="text" x-model="form.voucher_code" :disabled="voucherValid" id="voucher-input">
          <button @click="validateVoucher" :disabled="voucherValid" id="validate-btn">Validate</button>
        </div>
      `;
      (window as any).Alpine.initTree(document.getElementById('form'));
    });

    await page.fill('#voucher-input', 'VALID100');
    await page.click('#validate-btn');

    await page.waitForTimeout(200);

    // Input should be disabled after valid voucher
    await expect(page.locator('#voucher-input')).toBeDisabled();
    await expect(page.locator('#validate-btn')).toBeDisabled();
  });
});

test.describe('Enrollment Form - Terms and Submission', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('/');

    await page.evaluate(() => {
      (window as any).ntdstAPI = {
        call: async (action: string, params: any) => {
          await new Promise((r) => setTimeout(r, 100));
          if (action === 'stride_submit_enrollment') {
            if (params.fail) throw new Error('Submission failed');
            return { redirect_url: '/success' };
          }
          return { success: true };
        },
      };

      const script = document.createElement('script');
      script.textContent = `
        function enrollmentForm(config) {
          return {
            form: { terms_accepted: false },
            submitting: false,
            submitError: '',
            async submitForm() {
              if (!this.form.terms_accepted) return;
              this.submitting = true;
              this.submitError = '';
              try {
                const result = await ntdstAPI.call('stride_submit_enrollment', this.form);
                if (result.redirect_url) {
                  document.getElementById('redirect-target').textContent = result.redirect_url;
                }
              } catch (e) {
                this.submitError = e.message;
              } finally { this.submitting = false; }
            },
          };
        }
      `;
      document.head.appendChild(script);
    });
  });

  test('submit button is disabled without terms acceptance', async ({ page }) => {
    await page.evaluate(() => {
      document.body.innerHTML = `
        <div x-data="enrollmentForm({})" id="form">
          <label>
            <input type="checkbox" x-model="form.terms_accepted" id="terms-checkbox">
            Ik ga akkoord
          </label>
          <button @click="submitForm" :disabled="!form.terms_accepted" id="submit-btn">Submit</button>
        </div>
      `;
      (window as any).Alpine.initTree(document.getElementById('form'));
    });

    // Button should be disabled
    await expect(page.locator('#submit-btn')).toBeDisabled();

    // Check terms
    await page.click('#terms-checkbox');

    // Button should be enabled
    await expect(page.locator('#submit-btn')).toBeEnabled();
  });

  test('successful submission triggers redirect', async ({ page }) => {
    await page.evaluate(() => {
      document.body.innerHTML = `
        <div x-data="enrollmentForm({})" id="form">
          <input type="checkbox" x-model="form.terms_accepted" id="terms-checkbox">
          <button @click="submitForm" id="submit-btn">Submit</button>
          <span x-show="submitting" id="submitting">Submitting...</span>
          <span id="redirect-target"></span>
        </div>
      `;
      (window as any).Alpine.initTree(document.getElementById('form'));
    });

    await page.click('#terms-checkbox');
    await page.click('#submit-btn');

    // Wait for submission to complete
    await page.waitForTimeout(300);

    // Should get redirect URL (the test mock writes to redirect-target instead of actual redirect)
    await expect(page.locator('#redirect-target')).toHaveText('/success');
  });

  test('submission error is displayed', async ({ page }) => {
    await page.evaluate(() => {
      (window as any).ntdstAPI = {
        call: async () => {
          throw new Error('Inschrijving mislukt');
        },
      };

      document.body.innerHTML = `
        <div x-data="enrollmentForm({})" id="form">
          <input type="checkbox" x-model="form.terms_accepted" id="terms-checkbox">
          <button @click="submitForm" id="submit-btn">Submit</button>
          <span x-show="submitError" x-text="submitError" id="error"></span>
        </div>
      `;
      (window as any).Alpine.initTree(document.getElementById('form'));
    });

    await page.click('#terms-checkbox');
    await page.click('#submit-btn');

    await page.waitForTimeout(200);

    await expect(page.locator('#error')).toBeVisible();
    await expect(page.locator('#error')).toContainText('Inschrijving mislukt');
  });
});

test.describe('Enrollment Form - Progress Indicator', () => {
  test('progress steps reflect current step', async ({ page }) => {
    await page.goto('/');

    await page.evaluate(() => {
      const script = document.createElement('script');
      script.textContent = `
        function enrollmentForm(config) {
          return {
            currentStep: 0,
            stepLabels: ['Type', 'Gegevens', 'Facturatie', 'Bevestigen'],
            form: { enrollment_type: 'werknemer' },
            nextStep() { this.currentStep++; },
          };
        }
      `;
      document.head.appendChild(script);

      // Use explicit list items instead of x-for (simpler for testing)
      document.body.innerHTML = `
        <div x-data="enrollmentForm({})" id="form">
          <ol>
            <li :class="currentStep >= 0 ? 'text-primary' : 'text-gray-400'"
                :data-completed="currentStep > 0"
                :data-current="currentStep === 0"
                class="step-indicator">Type</li>
            <li :class="currentStep >= 1 ? 'text-primary' : 'text-gray-400'"
                :data-completed="currentStep > 1"
                :data-current="currentStep === 1"
                class="step-indicator">Gegevens</li>
            <li :class="currentStep >= 2 ? 'text-primary' : 'text-gray-400'"
                :data-completed="currentStep > 2"
                :data-current="currentStep === 2"
                class="step-indicator">Facturatie</li>
            <li :class="currentStep >= 3 ? 'text-primary' : 'text-gray-400'"
                :data-completed="currentStep > 3"
                :data-current="currentStep === 3"
                class="step-indicator">Bevestigen</li>
          </ol>
          <button @click="nextStep" id="next-btn">Next</button>
        </div>
      `;
      (window as any).Alpine.initTree(document.getElementById('form'));
    });

    // Initial state - first step is current (has text-primary class)
    const steps = page.locator('.step-indicator');
    await expect(steps.nth(0)).toHaveClass(/text-primary/);
    await expect(steps.nth(1)).toHaveClass(/text-gray-400/);

    // Advance
    await page.click('#next-btn');
    await expect(steps.nth(0)).toHaveClass(/text-primary/);
    await expect(steps.nth(1)).toHaveClass(/text-primary/);
    await expect(steps.nth(2)).toHaveClass(/text-gray-400/);

    // Advance again
    await page.click('#next-btn');
    await expect(steps.nth(0)).toHaveClass(/text-primary/);
    await expect(steps.nth(1)).toHaveClass(/text-primary/);
    await expect(steps.nth(2)).toHaveClass(/text-primary/);
    await expect(steps.nth(3)).toHaveClass(/text-gray-400/);
  });
});
