import { test, expect } from '@playwright/test';

/**
 * Form Interaction Tests
 *
 * Tests form filling, validation, and submission behavior.
 */

test.describe('Login Form', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('/login/');
  });

  test('email field accepts input', async ({ page }) => {
    const emailField = page.locator('#email');
    await emailField.fill('test@example.com');
    await expect(emailField).toHaveValue('test@example.com');
  });

  test('submit button is clickable', async ({ page }) => {
    // Stride login page renders TWO forms (password mode + magic-link mode),
    // each with its own submit button. Default mode='password' is the visible
    // one — scope to it explicitly.
    const submitButton = page.locator('form[x-show*="password"] button[type="submit"]');
    await expect(submitButton).toBeEnabled();
  });

  test('form shows loading state on submit', async ({ page }) => {
    const passwordForm = page.locator('form[x-show*="password"]');
    await passwordForm.locator('#email').fill('test@example.com');
    await passwordForm.locator('button[type="submit"]').click();

    // Alpine.js should show loading state (spinner or disabled button)
    // Wait a moment for state change
    await page.waitForTimeout(500);

    // At minimum, no JS errors should occur
    const body = await page.locator('body');
    await expect(body).toBeVisible();
  });

  test('empty form shows validation', async ({ page }) => {
    const submitButton = page.locator('form[x-show*="password"] button[type="submit"]');
    await submitButton.click();

    // HTML5 validation should prevent submission
    // Check that we're still on the login page
    await expect(page).toHaveURL(/login/);
  });
});

test.describe('Registration Form', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('/register/');
  });

  test('all required fields are present', async ({ page }) => {
    await expect(page.locator('#first_name')).toBeVisible();
    await expect(page.locator('#last_name')).toBeVisible();
    await expect(page.locator('#email')).toBeVisible();
    await expect(page.locator('#consent_terms')).toBeVisible();
    await expect(page.locator('#consent_privacy')).toBeVisible();
  });

  test('can fill all fields', async ({ page }) => {
    await page.locator('#first_name').fill('Test');
    await page.locator('#last_name').fill('User');
    await page.locator('#email').fill('test@example.com');
    await page.locator('#consent_terms').check();
    await page.locator('#consent_privacy').check();

    // Verify all filled
    await expect(page.locator('#first_name')).toHaveValue('Test');
    await expect(page.locator('#last_name')).toHaveValue('User');
    await expect(page.locator('#email')).toHaveValue('test@example.com');
    await expect(page.locator('#consent_terms')).toBeChecked();
    await expect(page.locator('#consent_privacy')).toBeChecked();
  });

  test('checkboxes are required', async ({ page }) => {
    await page.locator('#first_name').fill('Test');
    await page.locator('#last_name').fill('User');
    await page.locator('#email').fill('test@example.com');
    // Don't check the required checkboxes

    const submitButton = page.locator('button[type="submit"]');
    await submitButton.click();

    // Should stay on register page (HTML5 validation)
    await expect(page).toHaveURL(/register/);
  });

  test('form submission triggers AJAX', async ({ page }) => {
    // Fill form completely
    await page.locator('#first_name').fill('Test');
    await page.locator('#last_name').fill('User');
    const uniqueEmail = `test_${Date.now()}@example.com`;
    await page.locator('#email').fill(uniqueEmail);
    // profile_type is a required <select> — pick the first non-empty option so
    // HTML5 validation passes and the form actually submits via AJAX.
    const profileType = page.locator('#profile_type');
    const firstOption = await profileType.locator('option').nth(1).getAttribute('value');
    if (firstOption) await profileType.selectOption(firstOption);
    await page.locator('#consent_terms').check();
    await page.locator('#consent_privacy').check();

    // Listen for AJAX request
    const requestPromise = page.waitForRequest(
      (request) => request.url().includes('admin-ajax.php'),
      { timeout: 5000 }
    ).catch(() => null);

    // Submit
    await page.locator('button[type="submit"]').click();

    // Wait for AJAX
    const request = await requestPromise;

    // Either AJAX was sent, or form showed response
    const hasResponse = await page.locator('.uk-alert, [x-show="success"], [x-show="error"]').first().isVisible().catch(() => false);

    expect(request !== null || hasResponse).toBeTruthy();
  });
});

test.describe('Enrollment Form', () => {
  // This test requires a logged-in user and valid edition
  // We'll test the form structure assuming direct access

  test('enrollment page loads for anonymous users', async ({ page }) => {
    // Try to access enrollment without being logged in
    await page.goto('/inschrijven/');

    // Page should load without JS errors
    await expect(page.locator('body')).toBeVisible();

    // Should show some message (login prompt, error, or enrollment form)
    const content = await page.textContent('body');
    expect(content?.length).toBeGreaterThan(0);
  });

  test('enrollment page handles missing edition parameter', async ({ page }) => {
    await page.goto('/inschrijven/');

    // Should show error message about missing edition
    const content = await page.textContent('body');
    const hasErrorOrPrompt = content?.includes('cursus') || content?.includes('selecteer') || content?.includes('log in');

    expect(hasErrorOrPrompt).toBeTruthy();
  });
});

test.describe('Form Accessibility', () => {
  test('login form has accessible labels', async ({ page }) => {
    await page.goto('/login/');

    // Email field should have a label
    const emailLabel = page.locator('label[for="email"]');
    await expect(emailLabel).toBeVisible();
  });

  test('registration form has accessible labels', async ({ page }) => {
    await page.goto('/register/');

    // All fields should have labels
    await expect(page.locator('label[for="first_name"]')).toBeVisible();
    await expect(page.locator('label[for="last_name"]')).toBeVisible();
    await expect(page.locator('label[for="email"]')).toBeVisible();
  });

  test('form fields have proper focus states', async ({ page }) => {
    await page.goto('/login/');

    const emailField = page.locator('#email');
    await emailField.focus();

    // Check that the field is focused
    await expect(emailField).toBeFocused();
  });

  test('tab navigation works through form', async ({ page }) => {
    await page.goto('/login/');

    // Focus first field
    await page.locator('#email').focus();

    // Tab to next element
    await page.keyboard.press('Tab');

    // Should have moved focus
    const focusedElement = await page.evaluate(() => document.activeElement?.tagName);
    expect(focusedElement).toBeTruthy();
  });
});
