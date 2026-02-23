import { defineConfig, devices } from '@playwright/test';

/**
 * Playwright configuration for Stride frontend tests.
 * Tests run against the local DDEV environment.
 */
export default defineConfig({
  testDir: './tests/frontend',
  fullyParallel: true,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 2 : 0,
  workers: process.env.CI ? 1 : 4,
  reporter: 'html',

  use: {
    baseURL: 'https://stride.ddev.site',
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
    video: 'on-first-retry',
    // Ignore HTTPS errors for local dev
    ignoreHTTPSErrors: true,
  },

  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
    },
    {
      name: 'mobile',
      use: {
        ...devices['Pixel 5'],
        // Use Chromium for mobile emulation instead of WebKit
      },
    },
  ],

  // Output directory for test artifacts
  outputDir: 'tests/frontend/test-results',
});
