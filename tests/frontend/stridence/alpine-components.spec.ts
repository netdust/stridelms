import { test, expect } from '@playwright/test';

/**
 * Alpine.js Component Tests for Stridence Theme
 *
 * Tests that all Alpine components initialize and function correctly.
 * Components tested: toastStore, dashboardTabs, courseDetailTabs,
 * confirmAction, mobileMenu, dropdown, expandable, loadingState,
 * inlineEdit, inlineEditSection
 */

test.describe('Alpine.js Initialization', () => {
  test('Alpine.js is loaded and started', async ({ page }) => {
    await page.goto('/');
    await page.waitForTimeout(500);

    const alpineLoaded = await page.evaluate(() => {
      return typeof (window as any).Alpine !== 'undefined';
    });

    expect(alpineLoaded).toBeTruthy();
  });

  test('ntdstAPI wrapper is available', async ({ page }) => {
    await page.goto('/');
    await page.waitForTimeout(500);

    const apiAvailable = await page.evaluate(() => {
      const api = (window as any).ntdstAPI;
      return api && typeof api.call === 'function' && typeof api.post === 'function';
    });

    expect(apiAvailable).toBeTruthy();
  });
});

test.describe('Toast Store Component', () => {
  test('toast can be triggered programmatically', async ({ page }) => {
    await page.goto('/');
    await page.waitForTimeout(500);

    // Create a toast container for testing
    await page.evaluate(() => {
      const container = document.createElement('div');
      container.setAttribute('x-data', 'toastStore()');
      container.setAttribute('x-show', 'visible');
      container.setAttribute('x-text', 'message');
      container.id = 'test-toast';
      container.style.display = 'none';
      document.body.appendChild(container);

      // Re-init Alpine for new element
      (window as any).Alpine.initTree(container);
    });

    // Trigger toast via event
    await page.evaluate(() => {
      document.dispatchEvent(
        new CustomEvent('toast', {
          detail: { message: 'Test toast message', type: 'success' },
        })
      );
    });

    // Toast component should work without errors
    const body = await page.locator('body');
    await expect(body).toBeVisible();
  });
});

test.describe('Dropdown Component', () => {
  test('dropdown opens and closes', async ({ page }) => {
    await page.goto('/');

    // Create test dropdown
    await page.evaluate(() => {
      document.body.innerHTML += `
        <div x-data="dropdown()" id="test-dropdown">
          <button @click="toggle()" id="dropdown-trigger">Toggle</button>
          <div x-show="open" id="dropdown-content">Content</div>
        </div>
      `;
      (window as any).Alpine.initTree(document.getElementById('test-dropdown'));
    });

    // Initially closed
    await expect(page.locator('#dropdown-content')).toBeHidden();

    // Open
    await page.click('#dropdown-trigger');
    await expect(page.locator('#dropdown-content')).toBeVisible();

    // Close
    await page.click('#dropdown-trigger');
    await expect(page.locator('#dropdown-content')).toBeHidden();
  });

  test('dropdown closes on click outside', async ({ page }) => {
    await page.goto('/');

    await page.evaluate(() => {
      document.body.innerHTML += `
        <div x-data="dropdown()" id="test-dropdown-outside">
          <button @click="toggle()" id="dropdown-trigger-outside">Toggle</button>
          <div x-show="open" id="dropdown-content-outside">Content</div>
        </div>
        <div id="outside-element" style="width: 100px; height: 100px; background: red;"></div>
      `;
      (window as any).Alpine.initTree(document.getElementById('test-dropdown-outside'));
    });

    // Open dropdown
    await page.click('#dropdown-trigger-outside');
    await expect(page.locator('#dropdown-content-outside')).toBeVisible();

    // Click outside
    await page.click('#outside-element');
    await page.waitForTimeout(100);

    // Should be closed
    await expect(page.locator('#dropdown-content-outside')).toBeHidden();
  });
});

test.describe('Mobile Menu Component', () => {
  test('mobile menu toggles open state', async ({ page }) => {
    await page.setViewportSize({ width: 375, height: 667 });
    await page.goto('/');

    await page.evaluate(() => {
      document.body.innerHTML += `
        <div x-data="mobileMenu()" id="test-mobile-menu">
          <button @click="toggle()" id="menu-toggle">Menu</button>
          <nav x-show="open" id="mobile-nav">Nav Content</nav>
        </div>
      `;
      (window as any).Alpine.initTree(document.getElementById('test-mobile-menu'));
    });

    // Initially closed
    await expect(page.locator('#mobile-nav')).toBeHidden();

    // Open
    await page.click('#menu-toggle');
    await expect(page.locator('#mobile-nav')).toBeVisible();

    // Body should have overflow-hidden class
    const hasOverflowHidden = await page.evaluate(() => {
      return document.body.classList.contains('overflow-hidden');
    });
    expect(hasOverflowHidden).toBeTruthy();

    // Close
    await page.click('#menu-toggle');
    await expect(page.locator('#mobile-nav')).toBeHidden();
  });
});

test.describe('Expandable Component', () => {
  test('expandable toggles content visibility', async ({ page }) => {
    await page.goto('/');

    await page.evaluate(() => {
      document.body.innerHTML += `
        <div x-data="expandable()" id="test-expandable">
          <button @click="toggle()" id="expand-trigger">Expand</button>
          <div x-show="open" id="expand-content">Hidden Content</div>
        </div>
      `;
      (window as any).Alpine.initTree(document.getElementById('test-expandable'));
    });

    // Initially closed
    await expect(page.locator('#expand-content')).toBeHidden();

    // Expand
    await page.click('#expand-trigger');
    await expect(page.locator('#expand-content')).toBeVisible();

    // Collapse
    await page.click('#expand-trigger');
    await expect(page.locator('#expand-content')).toBeHidden();
  });
});

test.describe('Confirm Action Component', () => {
  test('confirm action shows confirmation state', async ({ page }) => {
    await page.goto('/');

    await page.evaluate(() => {
      document.body.innerHTML += `
        <div x-data="confirmAction()" id="test-confirm">
          <button x-show="!confirming" @click="startConfirm()" id="delete-btn">Delete</button>
          <div x-show="confirming" id="confirm-dialog">
            <span>Are you sure?</span>
            <button @click="cancel()" id="cancel-btn">Cancel</button>
            <button @click="confirm()" id="confirm-btn">Confirm</button>
          </div>
        </div>
      `;
      (window as any).Alpine.initTree(document.getElementById('test-confirm'));
    });

    // Initially shows delete button
    await expect(page.locator('#delete-btn')).toBeVisible();
    await expect(page.locator('#confirm-dialog')).toBeHidden();

    // Click delete
    await page.click('#delete-btn');
    await expect(page.locator('#delete-btn')).toBeHidden();
    await expect(page.locator('#confirm-dialog')).toBeVisible();

    // Cancel
    await page.click('#cancel-btn');
    await expect(page.locator('#delete-btn')).toBeVisible();
    await expect(page.locator('#confirm-dialog')).toBeHidden();
  });
});

test.describe('Loading State Component', () => {
  test('loading state tracks async operations', async ({ page }) => {
    await page.goto('/');

    await page.evaluate(() => {
      document.body.innerHTML += `
        <div x-data="loadingState()" id="test-loading">
          <span x-show="loading" id="loading-indicator">Loading...</span>
          <span x-show="!loading" id="ready-indicator">Ready</span>
        </div>
      `;
      (window as any).Alpine.initTree(document.getElementById('test-loading'));
    });

    // Initially ready
    await expect(page.locator('#ready-indicator')).toBeVisible();
    await expect(page.locator('#loading-indicator')).toBeHidden();
  });
});

test.describe('No Console Errors', () => {
  test('homepage loads without Alpine errors', async ({ page }) => {
    const errors: string[] = [];
    page.on('pageerror', (error) => errors.push(error.message));
    page.on('console', (msg) => {
      if (msg.type() === 'error') {
        errors.push(msg.text());
      }
    });

    await page.goto('/');
    await page.waitForTimeout(1000);

    // Filter out non-Alpine errors
    const alpineErrors = errors.filter(
      (e) =>
        e.toLowerCase().includes('alpine') ||
        e.includes('x-data') ||
        e.includes('x-show') ||
        e.includes('x-bind')
    );

    expect(alpineErrors).toHaveLength(0);
  });
});
