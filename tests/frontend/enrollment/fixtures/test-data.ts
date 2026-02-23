/**
 * Test Data Fixtures for Trajectory Enrollment E2E Tests
 *
 * Contains test users, trajectories, vouchers and helper functions.
 * Uses seed data from scripts/seed.php
 */

import type { Page } from '@playwright/test';

/**
 * Test user credentials from seed data
 *
 * Email formats must match scripts/seed.php exactly:
 * - Regular students: student1@seed.test (login: seed_student1)
 * - E2E test users: seed_enrolled_user@seed.test, seed_completed_user@seed.test
 * - Admin: seed_admin@seed.test
 */
export const testUsers = {
  /**
   * Fresh student - not enrolled in any trajectories
   * From seed.php: login=seed_student1, email=student1@seed.test
   */
  student: {
    email: 'student1@seed.test',
    password: 'seedpass123',
    firstName: 'Pieter',
    lastName: 'Janssen',
  },

  /**
   * User already enrolled in test-trajectory
   * From seed.php: seed_enrolled_user@seed.test (enrolled in test-trajectory)
   */
  enrolledUser: {
    email: 'seed_enrolled_user@seed.test',
    password: 'seedpass123',
    firstName: 'Enrolled',
    lastName: 'User',
  },

  /**
   * User who has completed test-trajectory
   * From seed.php: seed_completed_user@seed.test (completed test-trajectory)
   */
  completedUser: {
    email: 'seed_completed_user@seed.test',
    password: 'seedpass123',
    firstName: 'Completed',
    lastName: 'User',
  },

  /**
   * Admin user for verification
   */
  admin: {
    email: 'seed_admin@seed.test',
    password: 'seedpass123',
    firstName: 'Admin',
    lastName: 'Seed',
  },
};

/**
 * Test trajectory data
 */
export const testTrajectories = {
  /**
   * Trajectory open for enrollment
   */
  open: {
    slug: 'test-trajectory',
    title: 'Test Trajectory',
    price: 450,
  },

  /**
   * Trajectory with closed enrollment
   */
  closed: {
    slug: 'closed-trajectory',
    title: 'Closed Trajectory',
  },
};

/**
 * Test voucher codes
 */
export const testVouchers = {
  /**
   * Valid 10% discount voucher
   */
  valid: 'SEEDVOUCHER10',

  /**
   * Invalid/expired voucher
   */
  invalid: 'INVALID123',

  /**
   * Valid 100% discount voucher
   */
  fullDiscount: 'SEEDFREE',
};

/**
 * URL patterns for the enrollment flow
 *
 * Note: WordPress post type uses /vad_trajectory/ as base URL.
 * The enrollment router intercepts /trajecten/{slug}/inschrijving/ for clean enrollment URLs.
 */
export const urls = {
  /**
   * Trajectory catalog page (uses post type archive URL)
   */
  trajectoryCatalog: '/vad_trajectory/',

  /**
   * Login page (custom Stride login, not WordPress default)
   */
  login: '/login',

  /**
   * Registration page
   */
  register: '/register/',

  /**
   * My trajectories dashboard page
   */
  myTrajectories: '/mijn-account/mijn-trajecten/',

  /**
   * Build trajectory detail URL (uses post type single URL)
   */
  trajectoryDetail: (slug: string) => `/vad_trajectory/${slug}/`,

  /**
   * Build trajectory enrollment URL (handled by router, uses clean /trajecten/ base)
   */
  trajectoryEnrollment: (slug: string) => `/trajecten/${slug}/inschrijving/`,
};

/**
 * Log in a user via the custom Stride login form
 *
 * The site uses a custom Alpine.js-based login form, not WordPress default.
 * Form fields: #email (email), password input, submit button
 *
 * @param page - Playwright page object
 * @param user - User credentials object with email and password
 */
export async function login(
  page: Page,
  user: { email: string; password: string }
): Promise<void> {
  await page.goto(urls.login);

  // Wait for page to load and Alpine.js to initialize
  await page.waitForLoadState('domcontentloaded');

  // Wait for password field to be visible (Alpine.js enables password mode)
  await page.waitForSelector('#password', { state: 'visible', timeout: 5000 });

  // Custom Stride login form - password mode
  await page.fill('#email', user.email);
  await page.fill('#password', user.password);
  await page.click('button[type="submit"]');

  // Wait for redirect after successful login (homepage or any dashboard page)
  await page.waitForURL((url) => !url.pathname.includes('/login'), { timeout: 10000 });
}

/**
 * Log in and navigate to a specific URL
 *
 * @param page - Playwright page object
 * @param user - User credentials
 * @param targetUrl - URL to navigate to after login
 */
export async function loginAndNavigate(
  page: Page,
  user: { email: string; password: string },
  targetUrl: string
): Promise<void> {
  // Use custom Stride login with redirect
  const loginUrl = `${urls.login}?redirect_to=${encodeURIComponent(targetUrl)}`;
  await page.goto(loginUrl);

  // Wait for page to load and Alpine.js to initialize
  await page.waitForLoadState('domcontentloaded');

  // Wait for password field to be visible (Alpine.js enables password mode)
  await page.waitForSelector('#password', { state: 'visible', timeout: 5000 });

  await page.fill('#email', user.email);
  await page.fill('#password', user.password);
  await page.click('button[type="submit"]');

  // Wait for redirect (either to target or default redirect)
  await page.waitForURL((url) => !url.pathname.includes('/login'), { timeout: 10000 });

  // If not at target, navigate there (redirect might go to homepage)
  if (!page.url().includes(targetUrl)) {
    await page.goto(targetUrl);
    await page.waitForLoadState('domcontentloaded');
  }
}

/**
 * Log out the current user
 *
 * @param page - Playwright page object
 */
export async function logout(page: Page): Promise<void> {
  // Navigate to logout URL (custom Stride logout)
  await page.goto('/logout');

  // Wait for logout to complete - redirects to login page
  await page.waitForURL(/\/(login|inloggen)/, { timeout: 5000 }).catch(() => {
    // If custom logout doesn't exist, try WordPress logout
    return page.goto('/wp/wp-login.php?action=logout');
  });
}

/**
 * Check if user is currently logged in
 *
 * @param page - Playwright page object
 * @returns True if logged in
 */
export async function isLoggedIn(page: Page): Promise<boolean> {
  // Check for WordPress admin bar or logged-in body class
  const adminBar = page.locator('#wpadminbar');
  const loggedInBody = page.locator('body.logged-in');

  return (
    (await adminBar.isVisible().catch(() => false)) ||
    (await loggedInBody.count()) > 0
  );
}

/**
 * Fill enrollment form billing fields
 *
 * @param page - Playwright page object
 * @param data - Billing data to fill
 */
export async function fillBillingInfo(
  page: Page,
  data: {
    firstName?: string;
    lastName?: string;
    email?: string;
    company?: string;
    vatNumber?: string;
    address?: string;
    postalCode?: string;
    city?: string;
  }
): Promise<void> {
  if (data.firstName) {
    await page.fill('#first_name', data.firstName);
  }
  if (data.lastName) {
    await page.fill('#last_name', data.lastName);
  }
  if (data.email) {
    await page.fill('#email', data.email);
  }
  if (data.company) {
    await page.fill('#company', data.company);
  }
  if (data.vatNumber) {
    await page.fill('#vat_number', data.vatNumber);
  }
  if (data.address) {
    await page.fill('#address', data.address);
  }
  if (data.postalCode) {
    await page.fill('#postal_code', data.postalCode);
  }
  if (data.city) {
    await page.fill('#city', data.city);
  }
}

/**
 * Apply a voucher code
 *
 * @param page - Playwright page object
 * @param code - Voucher code to apply
 */
export async function applyVoucher(page: Page, code: string): Promise<void> {
  await page.fill('#voucher_code', code);
  await page.click('#apply-voucher');

  // Wait for voucher result to appear
  await page.waitForSelector('#voucher-result:not([style*="display: none"])');
}

/**
 * Accept terms and conditions checkboxes
 *
 * @param page - Playwright page object
 */
export async function acceptTerms(page: Page): Promise<void> {
  // Check terms checkbox (use first visible one - desktop or mobile)
  const termsCheckbox = page.locator('.terms-checkbox').first();
  await termsCheckbox.check();

  // Check cancellation checkbox
  const cancellationCheckbox = page.locator('.cancellation-checkbox').first();
  await cancellationCheckbox.check();
}

/**
 * Submit the enrollment form
 *
 * @param page - Playwright page object
 */
export async function submitEnrollment(page: Page): Promise<void> {
  const submitButton = page.locator('.submit-enrollment').first();
  await submitButton.click();
}
