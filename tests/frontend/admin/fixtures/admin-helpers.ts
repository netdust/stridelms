/**
 * Admin E2E Test Helpers
 *
 * Shared utilities for WordPress admin E2E tests.
 * Uses seed data from scripts/seed.php
 */

import type { Page } from '@playwright/test';

// ---------------------------------------------------------------------------
// Constants
// ---------------------------------------------------------------------------

export const WP_ADMIN = '/wp/wp-admin';

export const adminUsers = {
  admin: {
    email: 'seed_admin@seed.test',
    password: 'seedpass123',
  },
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
  user = adminUsers.admin,
): Promise<void> {
  // Try navigating to wp-admin — if already logged in, we'll get through
  await page.goto(`${WP_ADMIN}/`, { waitUntil: 'domcontentloaded', timeout: 30000 });

  // If we ended up on wp-admin (no login redirect), we're done
  if (page.url().includes('wp-admin') && !page.url().includes('login')) return;

  await page.waitForLoadState('domcontentloaded');

  // Detect which login form we're on: custom Stride or standard WordPress
  const isWpLogin = page.url().includes('wp-login.php');

  if (isWpLogin) {
    // Standard WordPress login form
    await page.fill('#user_login', user.email);
    await page.fill('#user_pass', user.password);
    await page.click('#wp-submit');
    // Wait for login redirect (may go to wp-admin or site root)
    await page.waitForURL((url) => !url.pathname.includes('wp-login'), { timeout: 15000 });
    // Ensure we end up in wp-admin
    if (!page.url().includes('wp-admin')) {
      await page.goto(`${WP_ADMIN}/`, { waitUntil: 'domcontentloaded', timeout: 30000 });
    }
  } else {
    // Custom Stride login page (Alpine.js)
    await page.waitForSelector('input[type="password"], #password', { state: 'visible', timeout: 10000 });

    const emailField = page.locator('input[type="email"], input[type="text"]').first();
    await emailField.fill(user.email);

    const passwordField = page.locator('input[type="password"]').first();
    await passwordField.fill(user.password);

    await page.click('button[type="submit"]');
    await page.waitForURL((url) => !url.pathname.includes('/login'), { timeout: 15000 });
    await page.goto(`${WP_ADMIN}/`, { waitUntil: 'domcontentloaded', timeout: 30000 });
  }
}

// ---------------------------------------------------------------------------
// Navigation
// ---------------------------------------------------------------------------

/**
 * Navigate to the Edition list table (edit.php?post_type=vad_edition).
 */
export async function gotoEditionList(page: Page): Promise<void> {
  await page.goto(`${WP_ADMIN}/edit.php?post_type=vad_edition`);
  await page.waitForLoadState('domcontentloaded');
}

/**
 * Navigate to the "Add New Edition" screen.
 */
export async function gotoNewEdition(page: Page): Promise<void> {
  await page.goto(`${WP_ADMIN}/post-new.php?post_type=vad_edition`);
  await page.waitForLoadState('domcontentloaded');
}

/**
 * Navigate to an existing edition edit screen by post ID.
 */
export async function gotoEditEdition(page: Page, postId: number): Promise<void> {
  await page.goto(`${WP_ADMIN}/post.php?post=${postId}&action=edit`);
  await page.waitForLoadState('domcontentloaded');
}

// ---------------------------------------------------------------------------
// Edition helpers
// ---------------------------------------------------------------------------

/**
 * Find the first edition on the list table and return its post ID + row locator.
 */
export async function getFirstEditionRow(page: Page) {
  const row = page.locator('#the-list tr').first();
  const editLink = row.locator('.row-title');
  const href = await editLink.getAttribute('href');
  const match = href?.match(/post=(\d+)/);
  const postId = match ? Number(match[1]) : 0;
  return { row, postId, editLink };
}

/**
 * Publish / Update the current edition being edited.
 */
export async function publishEdition(page: Page): Promise<void> {
  await page.click('#publish');
  // Wait for the "Post published" / "Post updated" notice
  await page.waitForSelector('#message, .notice-success, .updated', { timeout: 15000 });
}

/**
 * Wait for an AJAX response from admin-ajax.php.
 */
export async function waitForAjax(page: Page, timeout = 10000) {
  return page.waitForResponse(
    (res) => res.url().includes('admin-ajax.php') && res.status() === 200,
    { timeout },
  );
}

/**
 * Accept a native confirm() dialog (next one that fires).
 */
export function acceptNextDialog(page: Page): void {
  page.once('dialog', (dialog) => dialog.accept());
}

/**
 * Dismiss a native confirm() dialog.
 */
export function dismissNextDialog(page: Page): void {
  page.once('dialog', (dialog) => dialog.dismiss());
}
