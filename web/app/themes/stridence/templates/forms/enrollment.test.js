import { describe, it, expect, vi, beforeEach } from 'vitest';
import { readFileSync } from 'fs';
import { fileURLToPath } from 'url';
import path from 'path';

const __dirname = path.dirname(fileURLToPath(import.meta.url));

// enrollment.js is a classic (non-module) <script> loaded globally in the
// browser via <script src>, referenced by Alpine's x-data="enrollmentForm(...)".
// It exports itself via a CommonJS guard for test loading only — see the
// bottom of the file — which is a no-op in the browser (module is undefined).
const source = readFileSync(path.join(__dirname, 'enrollment.js'), 'utf8');
const moduleObj = { exports: {} };
// eslint-disable-next-line no-new-func
new Function('module', 'exports', source)(moduleObj, moduleObj.exports);
const enrollmentForm = moduleObj.exports;

function baseConfig(overrides = {}) {
  return {
    itemId: 1,
    itemType: 'edition',
    itemData: {},
    userEmail: 'user@example.test',
    prefill: {},
    fieldGroups: [],
    enrollmentMode: 'enrollment',
    isOnline: false,
    formType: 'default',
    ...overrides,
  };
}

describe('enrollmentForm — step navigation validation', () => {
  it('blocks nextStep() from the personal step when required personal fields are empty', () => {
    const form = enrollmentForm(baseConfig());
    form.stepIndex = 1; // personal step
    // first_name/last_name/email/phone all empty by default (no prefill)
    form.form.email = ''; // config sets email from userEmail; clear it to simulate empty

    form.nextStep();

    expect(form.stepIndex).toBe(1);
  });

  it('blocks nextStep() from the billing step when required invoice/address fields are empty', () => {
    const form = enrollmentForm(baseConfig());
    form.stepIndex = 2; // billing step
    form.form.first_name = 'Jan';
    form.form.last_name = 'Janssens';
    form.form.email = 'jan@example.test';
    form.form.phone = '+32470000000';
    // billing fields left empty: company, invoice_email, address, postal_code, city

    form.nextStep();

    expect(form.stepIndex).toBe(2);
  });

  it('allows nextStep() from the billing step once all required billing fields are filled', () => {
    const form = enrollmentForm(baseConfig());
    form.stepIndex = 2;
    form.form.first_name = 'Jan';
    form.form.last_name = 'Janssens';
    form.form.email = 'jan@example.test';
    form.form.phone = '+32470000000';
    form.form.company = 'ACME';
    form.form.invoice_email = 'facturatie@acme.test';
    form.form.address = 'Hoofdstraat 1';
    form.form.postal_code = '1000';
    form.form.city = 'Brussel';

    form.nextStep();

    expect(form.stepIndex).toBe(3);
  });
});

describe('enrollmentForm — submitForm() final validation gate', () => {
  beforeEach(() => {
    global.ntdstAPI = { call: vi.fn().mockResolvedValue({ status: 'success', message: 'OK' }) };
  });

  it('does NOT call the server when required billing fields are missing, even if terms are accepted', async () => {
    const form = enrollmentForm(baseConfig());
    form.form.first_name = 'Jan';
    form.form.last_name = 'Janssens';
    form.form.email = 'jan@example.test';
    form.form.phone = '+32470000000';
    form.form.terms_accepted = true;
    // billing fields left empty — this is the reported bug: invoice address skipped

    await form.submitForm();

    expect(global.ntdstAPI.call).not.toHaveBeenCalled();
  });

  it('calls the server once all required fields (personal + billing) are filled and terms accepted', async () => {
    const form = enrollmentForm(baseConfig());
    form.form.first_name = 'Jan';
    form.form.last_name = 'Janssens';
    form.form.email = 'jan@example.test';
    form.form.phone = '+32470000000';
    form.form.company = 'ACME';
    form.form.invoice_email = 'facturatie@acme.test';
    form.form.address = 'Hoofdstraat 1';
    form.form.postal_code = '1000';
    form.form.city = 'Brussel';
    form.form.terms_accepted = true;

    await form.submitForm();

    expect(global.ntdstAPI.call).toHaveBeenCalledTimes(1);
  });

  it('does not require billing fields for a short-form (online edition) flow', async () => {
    const form = enrollmentForm(baseConfig({ isOnline: true }));
    form.form.first_name = 'Jan';
    form.form.last_name = 'Janssens';
    form.form.email = 'jan@example.test';
    form.form.phone = '+32470000000';
    form.form.terms_accepted = true;
    // billing fields intentionally left empty — short form never shows step 2

    await form.submitForm();

    expect(global.ntdstAPI.call).toHaveBeenCalledTimes(1);
  });
});
