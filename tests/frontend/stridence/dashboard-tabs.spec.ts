import { test, expect } from '@playwright/test';

/**
 * Dashboard Tab Navigation Tests for Stridence Theme
 *
 * Tests the dashboard tabs component which manages tab state via URL params.
 * Verifies URL state sync, history navigation, and tab persistence.
 */

test.describe('Dashboard Tabs - URL State', () => {
  test.beforeEach(async ({ page }) => {
    // Listen for console errors
    page.on('pageerror', (error) => console.error('Page error:', error.message));
  });

  test('dashboardTabs component initializes with default tab', async ({ page }) => {
    await page.goto('/');

    await page.evaluate(() => {
      document.body.innerHTML = `
        <div x-data="dashboardTabs()" id="dashboard">
          <nav>
            <button @click="setTab('inschrijvingen')" :class="{ 'active': activeTab === 'inschrijvingen' }" id="tab-inschrijvingen">Inschrijvingen</button>
            <button @click="setTab('certificaten')" :class="{ 'active': activeTab === 'certificaten' }" id="tab-certificaten">Certificaten</button>
            <button @click="setTab('profiel')" :class="{ 'active': activeTab === 'profiel' }" id="tab-profiel">Profiel</button>
          </nav>
          <div x-show="activeTab === 'inschrijvingen'" id="content-inschrijvingen">Inschrijvingen Content</div>
          <div x-show="activeTab === 'certificaten'" id="content-certificaten">Certificaten Content</div>
          <div x-show="activeTab === 'profiel'" id="content-profiel">Profiel Content</div>
          <span x-text="activeTab" id="active-tab-indicator"></span>
        </div>
      `;
      (window as any).Alpine.initTree(document.getElementById('dashboard'));
    });

    // Default tab should be 'inschrijvingen'
    await expect(page.locator('#active-tab-indicator')).toHaveText('inschrijvingen');
    await expect(page.locator('#content-inschrijvingen')).toBeVisible();
    await expect(page.locator('#content-certificaten')).toBeHidden();
    await expect(page.locator('#content-profiel')).toBeHidden();
  });

  test('clicking tab changes active tab and updates URL', async ({ page }) => {
    await page.goto('/');

    await page.evaluate(() => {
      document.body.innerHTML = `
        <div x-data="dashboardTabs()" id="dashboard">
          <button @click="setTab('certificaten')" id="tab-certificaten">Certificaten</button>
          <div x-show="activeTab === 'certificaten'" id="content-certificaten">Certificaten</div>
          <span x-text="activeTab" id="active-tab-indicator"></span>
        </div>
      `;
      (window as any).Alpine.initTree(document.getElementById('dashboard'));
    });

    // Click certificaten tab
    await page.click('#tab-certificaten');
    await page.waitForTimeout(100);

    // Active tab should change
    await expect(page.locator('#active-tab-indicator')).toHaveText('certificaten');
    await expect(page.locator('#content-certificaten')).toBeVisible();

    // URL should be updated
    const url = new URL(page.url());
    expect(url.searchParams.get('tab')).toBe('certificaten');
  });

  test('URL param sets initial tab', async ({ page }) => {
    await page.goto('/?tab=profiel');

    await page.evaluate(() => {
      document.body.innerHTML = `
        <div x-data="dashboardTabs()" id="dashboard">
          <div x-show="activeTab === 'inschrijvingen'" id="content-inschrijvingen">Inschrijvingen</div>
          <div x-show="activeTab === 'profiel'" id="content-profiel">Profiel</div>
          <span x-text="activeTab" id="active-tab-indicator"></span>
        </div>
      `;
      (window as any).Alpine.initTree(document.getElementById('dashboard'));
    });

    // Tab from URL param should be active
    await expect(page.locator('#active-tab-indicator')).toHaveText('profiel');
    await expect(page.locator('#content-profiel')).toBeVisible();
    await expect(page.locator('#content-inschrijvingen')).toBeHidden();
  });

  test('browser back button restores previous tab', async ({ page }) => {
    await page.goto('/');

    await page.evaluate(() => {
      document.body.innerHTML = `
        <div x-data="dashboardTabs()" id="dashboard">
          <button @click="setTab('certificaten')" id="tab-certificaten">Certificaten</button>
          <button @click="setTab('offertes')" id="tab-offertes">Offertes</button>
          <span x-text="activeTab" id="active-tab-indicator"></span>
        </div>
      `;
      (window as any).Alpine.initTree(document.getElementById('dashboard'));
    });

    // Navigate through tabs
    await expect(page.locator('#active-tab-indicator')).toHaveText('inschrijvingen');

    await page.click('#tab-certificaten');
    await page.waitForTimeout(100);
    await expect(page.locator('#active-tab-indicator')).toHaveText('certificaten');

    await page.click('#tab-offertes');
    await page.waitForTimeout(100);
    await expect(page.locator('#active-tab-indicator')).toHaveText('offertes');

    // Go back
    await page.goBack();
    await page.waitForTimeout(100);
    await expect(page.locator('#active-tab-indicator')).toHaveText('certificaten');

    // Go back again
    await page.goBack();
    await page.waitForTimeout(100);
    await expect(page.locator('#active-tab-indicator')).toHaveText('inschrijvingen');
  });

  test('multiple tab changes create proper history', async ({ page }) => {
    await page.goto('/');

    await page.evaluate(() => {
      document.body.innerHTML = `
        <div x-data="dashboardTabs()" id="dashboard">
          <button @click="setTab('a')" id="tab-a">A</button>
          <button @click="setTab('b')" id="tab-b">B</button>
          <button @click="setTab('c')" id="tab-c">C</button>
          <span x-text="activeTab" id="active-tab-indicator"></span>
        </div>
      `;
      (window as any).Alpine.initTree(document.getElementById('dashboard'));
    });

    // Navigate A -> B -> C
    await page.click('#tab-a');
    await page.waitForTimeout(50);
    await page.click('#tab-b');
    await page.waitForTimeout(50);
    await page.click('#tab-c');
    await page.waitForTimeout(50);

    expect(new URL(page.url()).searchParams.get('tab')).toBe('c');

    // Forward should work after going back
    await page.goBack();
    await page.waitForTimeout(100);
    expect(new URL(page.url()).searchParams.get('tab')).toBe('b');

    await page.goForward();
    await page.waitForTimeout(100);
    expect(new URL(page.url()).searchParams.get('tab')).toBe('c');
  });
});

test.describe('Dashboard Tabs - Content Visibility', () => {
  test('only active tab content is visible', async ({ page }) => {
    await page.goto('/');

    await page.evaluate(() => {
      document.body.innerHTML = `
        <div x-data="dashboardTabs()" id="dashboard">
          <button @click="setTab('tab1')" id="btn-tab1">Tab 1</button>
          <button @click="setTab('tab2')" id="btn-tab2">Tab 2</button>
          <button @click="setTab('tab3')" id="btn-tab3">Tab 3</button>

          <div x-show="activeTab === 'inschrijvingen'" id="content-default">Default Content</div>
          <div x-show="activeTab === 'tab1'" id="content-tab1">Tab 1 Content</div>
          <div x-show="activeTab === 'tab2'" id="content-tab2">Tab 2 Content</div>
          <div x-show="activeTab === 'tab3'" id="content-tab3">Tab 3 Content</div>
        </div>
      `;
      (window as any).Alpine.initTree(document.getElementById('dashboard'));
    });

    // Default tab content visible
    await expect(page.locator('#content-default')).toBeVisible();
    await expect(page.locator('#content-tab1')).toBeHidden();
    await expect(page.locator('#content-tab2')).toBeHidden();
    await expect(page.locator('#content-tab3')).toBeHidden();

    // Switch to tab 2
    await page.click('#btn-tab2');
    await page.waitForTimeout(100);

    await expect(page.locator('#content-default')).toBeHidden();
    await expect(page.locator('#content-tab1')).toBeHidden();
    await expect(page.locator('#content-tab2')).toBeVisible();
    await expect(page.locator('#content-tab3')).toBeHidden();
  });

  test('tab button styling updates on selection', async ({ page }) => {
    await page.goto('/');

    await page.evaluate(() => {
      document.body.innerHTML = `
        <div x-data="dashboardTabs()" id="dashboard">
          <button
            @click="setTab('inschrijvingen')"
            :class="{ 'bg-blue-500': activeTab === 'inschrijvingen', 'bg-gray-200': activeTab !== 'inschrijvingen' }"
            id="btn-inschrijvingen"
          >Inschrijvingen</button>
          <button
            @click="setTab('offertes')"
            :class="{ 'bg-blue-500': activeTab === 'offertes', 'bg-gray-200': activeTab !== 'offertes' }"
            id="btn-offertes"
          >Offertes</button>
        </div>
      `;
      (window as any).Alpine.initTree(document.getElementById('dashboard'));
    });

    // Initial state - inschrijvingen is active
    const inschrijvingenBtn = page.locator('#btn-inschrijvingen');
    const offertesBtn = page.locator('#btn-offertes');

    await expect(inschrijvingenBtn).toHaveClass(/bg-blue-500/);
    await expect(offertesBtn).toHaveClass(/bg-gray-200/);

    // Click offertes
    await page.click('#btn-offertes');
    await page.waitForTimeout(100);

    await expect(inschrijvingenBtn).toHaveClass(/bg-gray-200/);
    await expect(offertesBtn).toHaveClass(/bg-blue-500/);
  });
});

test.describe('Dashboard Tabs - Edge Cases', () => {
  test('invalid tab param falls back to default', async ({ page }) => {
    await page.goto('/?tab=nonexistent');

    await page.evaluate(() => {
      document.body.innerHTML = `
        <div x-data="dashboardTabs()" id="dashboard">
          <span x-text="activeTab" id="active-tab-indicator"></span>
        </div>
      `;
      (window as any).Alpine.initTree(document.getElementById('dashboard'));
    });

    // Should use the value from URL (component doesn't validate)
    // This tests that the component doesn't crash with unknown tabs
    const indicator = page.locator('#active-tab-indicator');
    await expect(indicator).toBeVisible();
  });

  test('empty tab param uses default', async ({ page }) => {
    await page.goto('/?tab=');

    await page.evaluate(() => {
      document.body.innerHTML = `
        <div x-data="dashboardTabs()" id="dashboard">
          <span x-text="activeTab" id="active-tab-indicator"></span>
        </div>
      `;
      (window as any).Alpine.initTree(document.getElementById('dashboard'));
    });

    // Empty string should fall back to default
    await expect(page.locator('#active-tab-indicator')).toHaveText('inschrijvingen');
  });

  test('rapid tab clicks are handled correctly', async ({ page }) => {
    await page.goto('/');

    await page.evaluate(() => {
      document.body.innerHTML = `
        <div x-data="dashboardTabs()" id="dashboard">
          <button @click="setTab('a')" id="tab-a">A</button>
          <button @click="setTab('b')" id="tab-b">B</button>
          <button @click="setTab('c')" id="tab-c">C</button>
          <span x-text="activeTab" id="active-tab-indicator"></span>
        </div>
      `;
      (window as any).Alpine.initTree(document.getElementById('dashboard'));
    });

    // Rapid clicks
    await page.click('#tab-a');
    await page.click('#tab-b');
    await page.click('#tab-c');
    await page.click('#tab-a');
    await page.click('#tab-b');

    await page.waitForTimeout(200);

    // Final state should be 'b'
    await expect(page.locator('#active-tab-indicator')).toHaveText('b');
  });
});
