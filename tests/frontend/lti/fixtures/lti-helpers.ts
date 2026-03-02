/**
 * LTI E2E Test Helpers
 *
 * Shared utilities for LTI endpoint testing.
 * Uses seed data from scripts/seed.php
 */

import type { Page } from '@playwright/test';

// ---------------------------------------------------------------------------
// Constants
// ---------------------------------------------------------------------------

export const WP_ADMIN = '/wp/wp-admin';
export const AUTH_FILE = '/tmp/stride-lti-admin-auth.json';

export const adminUser = {
  email: 'seed_admin@seed.test',
  password: 'seedpass123',
};

// ---------------------------------------------------------------------------
// Login
// ---------------------------------------------------------------------------

/**
 * Log in to WordPress admin.
 *
 * Stride uses a custom login page at /login/ with Alpine.js.
 * The form has Email + Password fields and a "Sign In" button.
 * After login, we navigate to wp-admin.
 */
export async function wpAdminLogin(
  page: Page,
  user = adminUser,
): Promise<void> {
  // Try navigating to wp-admin -- if already logged in, we'll get through
  await page.goto(`${WP_ADMIN}/`, { waitUntil: 'domcontentloaded', timeout: 30000 });

  // If we ended up on wp-admin (no login redirect), we're done
  if (page.url().includes('wp-admin') && !page.url().includes('login')) return;

  // We're on the custom Stride login page
  await page.waitForLoadState('domcontentloaded');

  // Wait for the password field to appear (Alpine.js initializes it)
  await page.waitForSelector('input[type="password"], #password', { state: 'visible', timeout: 10000 });

  // Fill the custom login form
  const emailField = page.locator('input[type="email"], input[type="text"]').first();
  await emailField.fill(user.email);

  const passwordField = page.locator('input[type="password"]').first();
  await passwordField.fill(user.password);

  // Submit
  await page.click('button[type="submit"]');

  // Wait for login to complete (redirects away from login page)
  await page.waitForURL((url) => !url.pathname.includes('/login'), { timeout: 15000 });

  // Now navigate to wp-admin
  await page.goto(`${WP_ADMIN}/`, { waitUntil: 'domcontentloaded', timeout: 30000 });
}
