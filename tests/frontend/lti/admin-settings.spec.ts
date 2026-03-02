import { test as baseTest, expect, type Page } from '@playwright/test';
import * as fs from 'fs';
import { wpAdminLogin, WP_ADMIN, AUTH_FILE } from './fixtures/lti-helpers';

// ---------------------------------------------------------------------------
// Auth fixture — log in once and reuse cookies for all tests
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

// ---------------------------------------------------------------------------
// Navigation helper — go to LTI settings, handle stale auth gracefully
// ---------------------------------------------------------------------------
const LTI_SETTINGS = `${WP_ADMIN}/options-general.php?page=netdust-lti`;

async function gotoLtiSettings(page: Page): Promise<void> {
  await page.goto(LTI_SETTINGS, { waitUntil: 'domcontentloaded', timeout: 30000 });

  // If redirected to login page, log in again
  if (page.url().includes('login') || !page.url().includes('wp-admin')) {
    await wpAdminLogin(page);
    await page.goto(LTI_SETTINGS, { waitUntil: 'domcontentloaded', timeout: 30000 });
  }

  // Wait for Alpine.js to initialize and render the app (x-cloak removed).
  // Do NOT use networkidle — WP admin heartbeat keeps connections open.
  await page.waitForFunction(
    () => {
      const app = document.querySelector('.lti-app');
      return app && !app.hasAttribute('x-cloak');
    },
    { timeout: 20000 },
  );
}

/**
 * LTI Admin Settings Page Tests
 *
 * Tests the Settings > Netdust LTI admin page.
 * The page uses Alpine.js with a LtiConfig JS object to render endpoint URLs.
 * Requires seed data: ddev exec wp eval-file scripts/seed.php
 */
test.describe('LTI Admin Settings', () => {
  // Login + Alpine.js init can take significant time on first load
  test.setTimeout(60000);

  test('settings page loads with endpoint URLs', async ({ page }) => {
    await gotoLtiSettings(page);

    // Page title
    await expect(page.locator('h1')).toContainText('Netdust LTI');

    // Wait for Alpine.js to render the endpoint tables
    await page.waitForSelector('.lti-endpoint-url', { state: 'visible', timeout: 15000 });

    // Check for Tool Provider endpoint URLs rendered by Alpine.js
    const endpointUrls = page.locator('.lti-endpoint-url');
    const allText = await endpointUrls.allTextContents();
    const joined = allText.join(' ');

    expect(joined).toContain('/lti/login');
    expect(joined).toContain('/lti/launch');
    expect(joined).toContain('/lti/jwks');
  });

  test('displays config and registration URLs', async ({ page }) => {
    await gotoLtiSettings(page);

    // Wait for Alpine.js to render
    await page.waitForSelector('.lti-endpoint-url', { state: 'visible', timeout: 15000 });

    const endpointUrls = page.locator('.lti-endpoint-url');
    const allText = await endpointUrls.allTextContents();
    const joined = allText.join(' ');

    expect(joined).toContain('/lti/configure-json');
    expect(joined).toContain('/lti/configure-xml');
    expect(joined).toContain('/lti/register');
  });

  test('copy buttons exist for each endpoint', async ({ page }) => {
    await gotoLtiSettings(page);

    // Wait for Alpine.js to render the endpoint tables
    await page.waitForSelector('.lti-copy-btn', { state: 'visible', timeout: 15000 });

    // Dashboard tab shows Tool Provider + Platform endpoint tables
    // Tool Provider: oidc_login, launch, jwks, deep_link, json_config, xml_config, dynamic_registration = 7
    // Platform: issuer, auth_endpoint, jwks_url, ags_endpoint, deep_link_return = 5
    // Total on Dashboard tab: 12
    const copyButtons = page.locator('.lti-copy-btn');
    const count = await copyButtons.count();

    // Should have at least 7 copy buttons (tool provider endpoints)
    expect(count).toBeGreaterThanOrEqual(7);
  });

  test('tab navigation is present', async ({ page }) => {
    await gotoLtiSettings(page);

    // The settings page uses tab-based navigation (Alpine.js)
    // Classes: .lti-nav-tabs container with .lti-nav-tab buttons
    await expect(page.locator('.lti-nav-tab', { hasText: 'Platforms' })).toBeVisible({ timeout: 10000 });
    await expect(page.locator('.lti-nav-tab', { hasText: 'Tools' })).toBeVisible({ timeout: 10000 });
    await expect(page.locator('.lti-nav-tab', { hasText: 'Dashboard' })).toBeVisible({ timeout: 10000 });
  });

  test('platform endpoints section is visible', async ({ page }) => {
    await gotoLtiSettings(page);

    // Dashboard tab shows both Tool Provider and Platform endpoint sections
    await expect(page.locator('text=Tool Provider Endpoints')).toBeVisible();
    await expect(page.locator('text=Platform Endpoints')).toBeVisible();
  });

  test('status cards are visible on dashboard', async ({ page }) => {
    await gotoLtiSettings(page);

    // Dashboard shows 4 status cards: RSA Keys, Platforms, Tools, Resources
    const statCards = page.locator('.lti-stat-card');
    const count = await statCards.count();
    expect(count).toBe(4);

    // RSA Keys card should show "Active" or "Missing"
    await expect(page.locator('.lti-stat-label:has-text("RSA Keys")')).toBeVisible();
  });
});
