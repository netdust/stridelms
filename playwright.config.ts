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
      // wp-admin and LTI admin features have no mobile-responsive UI and
      // legitimately don't need mobile coverage. Restrict mobile to
      // public-facing specs (theme, enrollment forms, dashboard).
      // Includes the top-level skips so mobile inherits the same ignores
      // (Playwright overrides rather than merges project testIgnore).
      testIgnore: [
        '**/admin/**',
        '**/lti/**',
        '**/trajectory-enrollment.spec.ts',
        '**/field-groups-enrollment.spec.ts',
        '**/uikit.spec.ts',
        // Alpine component tests inject markup + simulate interactions that
        // intermittently fail on Pixel-5 emulation (overlap / pointer-event
        // quirks). Components themselves are exercised by real pages on
        // mobile. Component-isolated tests run on chromium only.
        '**/alpine-components.spec.ts',
      ],
    },
  ],

  // Output directory for test artifacts
  outputDir: 'tests/frontend/test-results',

  // Specs that require seed data or features deferred post-launch:
  //   - trajectory-enrollment: trajectories deferred post-launch (LAUNCH-CHECKLIST §H)
  //   - field-groups-enrollment: relies on a field group "fg_1 Organisatie
  //     gegevens" assigned to edition 5913 that scripts/seed.php doesn't create.
  //     Manual shake-out covers this flow pre-launch; automation needs the seed
  //     script to set up the field group + assignment first.
  //   - lti/*: LTI is post-launch (LAUNCH-CHECKLIST §H, alongside trajectories)
  //   - uikit.spec.ts: theme migrated to Tailwind; UIkit is a plugin-internal
  //     detail of ntdst-auth only, not a theme-wide contract.
  testIgnore: [
    '**/trajectory-enrollment.spec.ts',
    '**/field-groups-enrollment.spec.ts',
    '**/lti/**',
    '**/uikit.spec.ts',
  ],
});
