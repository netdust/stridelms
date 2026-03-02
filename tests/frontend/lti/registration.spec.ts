import { test as baseTest, expect } from '@playwright/test';
import * as fs from 'fs';
import { wpAdminLogin, WP_ADMIN, AUTH_FILE } from './fixtures/lti-helpers';

/**
 * LTI Dynamic Registration Error Handling Tests
 *
 * Tests that /lti/register properly rejects invalid requests.
 * The registration endpoint requires admin authentication and a valid
 * openid_configuration URL parameter.
 */

// ---------------------------------------------------------------------------
// Unauthenticated tests (no login, use baseTest directly)
// ---------------------------------------------------------------------------
baseTest.describe('LTI Registration - Unauthenticated', () => {
  baseTest('rejects unauthenticated access', async ({ page }) => {
    await page.goto('/lti/register');

    // Should show 403 error (via wp_die content)
    // The handler calls wp_die with "administrator" in the message and "Unauthorized" as the title
    const content = await page.textContent('body');
    const is403 =
      content?.includes('administrator') ||
      content?.includes('Unauthorized');
    expect(is403).toBe(true);
  });
});

// ---------------------------------------------------------------------------
// Authenticated tests (admin login required)
// The /lti/register endpoint checks current_user_can('manage_options'),
// so we need valid WordPress auth cookies.
// ---------------------------------------------------------------------------
const test = baseTest.extend({
  storageState: async ({ browser, baseURL }, use) => {
    let needsLogin = true;
    if (fs.existsSync(AUTH_FILE)) {
      const age = Date.now() - fs.statSync(AUTH_FILE).mtimeMs;
      needsLogin = age > 5 * 60 * 1000;
    }

    if (needsLogin) {
      const ctx = await browser.newContext({ baseURL, ignoreHTTPSErrors: true });
      const page = await ctx.newPage();
      await wpAdminLogin(page);
      await ctx.storageState({ path: AUTH_FILE });
      await page.close();
      await ctx.close();
    }

    await use(AUTH_FILE);
  },
});

test.describe('LTI Registration - Admin', () => {
  // Ensure we have a valid admin session before testing the /lti/register endpoint
  test.beforeAll(async ({ browser }) => {
    // Force a fresh login to ensure the auth file is valid
    if (!fs.existsSync(AUTH_FILE)) {
      const ctx = await browser.newContext({ ignoreHTTPSErrors: true });
      const page = await ctx.newPage();
      await wpAdminLogin(page);
      await ctx.storageState({ path: AUTH_FILE });
      await page.close();
      await ctx.close();
    }
  });

  test('rejects missing openid_configuration', async ({ page }) => {
    // Verify we are logged in by visiting wp-admin first
    await page.goto(`${WP_ADMIN}/`, { waitUntil: 'domcontentloaded', timeout: 30000 });
    if (page.url().includes('login') || !page.url().includes('wp-admin')) {
      await wpAdminLogin(page);
    }

    // Now visit the registration endpoint without params
    await page.goto('/lti/register');
    const content = await page.textContent('body');

    // wp_die message mentions openid_configuration
    expect(content).toContain('openid_configuration');
  });

  test('rejects invalid openid_configuration URL', async ({ page }) => {
    // Verify we are logged in by visiting wp-admin first
    await page.goto(`${WP_ADMIN}/`, { waitUntil: 'domcontentloaded', timeout: 30000 });
    if (page.url().includes('login') || !page.url().includes('wp-admin')) {
      await wpAdminLogin(page);
    }

    // Now visit with an invalid URL
    await page.goto('/lti/register?openid_configuration=not-a-url');
    const content = await page.textContent('body');

    // wp_die message mentions openid_configuration (missing or invalid)
    expect(content).toContain('openid_configuration');
  });
});
