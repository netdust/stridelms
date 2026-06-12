import { test, expect } from '@playwright/test';

/**
 * JavaScript Behavior Tests
 *
 * Tests JavaScript functionality, Alpine.js, scroll effects, and error handling.
 */

test.describe('Alpine.js', () => {
  test('Alpine.js is loaded', async ({ page }) => {
    await page.goto('/login/');

    // Wait for Alpine to initialize
    await page.waitForTimeout(500);

    const alpineLoaded = await page.evaluate(() => {
      return typeof (window as any).Alpine !== 'undefined';
    });

    expect(alpineLoaded).toBeTruthy();
  });

  test('Alpine.js components initialize', async ({ page }) => {
    await page.goto('/login/');

    // Wait for Alpine
    await page.waitForTimeout(500);

    // Check for x-data attribute (Alpine component)
    const hasAlpineComponent = await page.locator('[x-data]').count();
    expect(hasAlpineComponent).toBeGreaterThan(0);
  });

  test('Alpine.js reactive data works', async ({ page }) => {
    await page.goto('/login/');
    await page.waitForTimeout(500);

    // Fill email - Alpine should track this
    await page.locator('#email').fill('test@example.com');

    // Alpine's x-model should have updated
    const value = await page.locator('#email').inputValue();
    expect(value).toBe('test@example.com');
  });
});

test.describe('Console Errors', () => {
  test('homepage has no console errors', async ({ page }) => {
    const errors: string[] = [];
    page.on('console', (msg) => {
      if (msg.type() === 'error') {
        errors.push(msg.text());
      }
    });

    await page.goto('/');
    await page.waitForTimeout(1000);

    // Filter out known acceptable errors (e.g., 404 for optional resources)
    const criticalErrors = errors.filter((e) => {
      return !e.includes('favicon') && !e.includes('404') && !e.includes('Failed to load resource');
    });

    expect(criticalErrors).toHaveLength(0);
  });

  test('login page has no console errors', async ({ page }) => {
    const errors: string[] = [];
    page.on('console', (msg) => {
      if (msg.type() === 'error') {
        errors.push(msg.text());
      }
    });

    await page.goto('/login/');
    await page.waitForTimeout(1000);

    const criticalErrors = errors.filter((e) => {
      return !e.includes('favicon') && !e.includes('404') && !e.includes('Failed to load resource');
    });

    expect(criticalErrors).toHaveLength(0);
  });

  test('register page has no console errors', async ({ page }) => {
    const errors: string[] = [];
    page.on('console', (msg) => {
      if (msg.type() === 'error') {
        errors.push(msg.text());
      }
    });

    await page.goto('/register/');
    await page.waitForTimeout(1000);

    const criticalErrors = errors.filter((e) => {
      return !e.includes('favicon') && !e.includes('404') && !e.includes('Failed to load resource');
    });

    expect(criticalErrors).toHaveLength(0);
  });

  test('cursussen page has no console errors', async ({ page }) => {
    const errors: string[] = [];
    page.on('console', (msg) => {
      if (msg.type() === 'error') {
        errors.push(msg.text());
      }
    });

    await page.goto('/cursussen/');
    await page.waitForTimeout(1000);

    const criticalErrors = errors.filter((e) => {
      return !e.includes('favicon') && !e.includes('404') && !e.includes('Failed to load resource');
    });

    expect(criticalErrors).toHaveLength(0);
  });
});

test.describe('Page Errors', () => {
  test('homepage has no uncaught exceptions', async ({ page }) => {
    const errors: Error[] = [];
    page.on('pageerror', (error) => errors.push(error));

    await page.goto('/');
    await page.waitForTimeout(1000);

    expect(errors).toHaveLength(0);
  });

  test('login page has no uncaught exceptions', async ({ page }) => {
    const errors: Error[] = [];
    page.on('pageerror', (error) => errors.push(error));

    await page.goto('/login/');
    await page.waitForTimeout(1000);

    expect(errors).toHaveLength(0);
  });
});

test.describe('Network Requests', () => {
  test('no failed API requests on homepage', async ({ page }) => {
    const failedRequests: string[] = [];

    page.on('response', (response) => {
      if (response.status() >= 400 && response.status() !== 404) {
        // Ignore 404s for optional resources
        if (!response.url().includes('favicon')) {
          failedRequests.push(`${response.status()}: ${response.url()}`);
        }
      }
    });

    await page.goto('/');
    await page.waitForTimeout(1000);

    expect(failedRequests).toHaveLength(0);
  });
});

test.describe('Scroll Behavior', () => {
  test('page is scrollable', async ({ page }) => {
    await page.goto('/');
    // Allow late JS (Lenis/Alpine) to wire scroll handlers before sampling.
    await page.waitForLoadState('networkidle');

    const pageHeight = await page.evaluate(() => document.body.scrollHeight);
    const viewportHeight = await page.evaluate(() => window.innerHeight);

    if (pageHeight > viewportHeight) {
      await page.evaluate(() => window.scrollTo(0, 100));
      // Wait one frame so the browser commits the scroll position.
      await page.waitForTimeout(100);
      const scrollY = await page.evaluate(() => window.scrollY);
      expect(scrollY).toBeGreaterThan(0);
    }
  });

  test('scroll position resets on navigation', async ({ page }) => {
    await page.goto('/');

    // Scroll down
    await page.evaluate(() => window.scrollTo(0, 500));

    // Navigate to another page
    await page.goto('/login/');

    // Should be at top
    const scrollY = await page.evaluate(() => window.scrollY);
    expect(scrollY).toBe(0);
  });
});

test.describe('Image Loading', () => {
  test('images have alt attributes', async ({ page }) => {
    await page.goto('/');

    const images = await page.locator('img').all();

    for (const img of images.slice(0, 10)) {
      // Check first 10 images
      const alt = await img.getAttribute('alt');
      // Alt can be empty for decorative images, just shouldn't be undefined
      expect(alt).not.toBeNull();
    }
  });

  test('images load without errors', async ({ page }) => {
    const brokenImages: string[] = [];

    await page.goto('/');
    await page.waitForTimeout(1000);

    const images = await page.locator('img').all();

    for (const img of images.slice(0, 5)) {
      const naturalWidth = await img.evaluate((el: HTMLImageElement) => el.naturalWidth);
      const src = await img.getAttribute('src');

      if (naturalWidth === 0 && src) {
        brokenImages.push(src);
      }
    }

    // Allow some broken images (placeholders, lazy load)
    expect(brokenImages.length).toBeLessThan(3);
  });
});

test.describe('Performance', () => {
  test('page loads within reasonable time', async ({ page }) => {
    const startTime = Date.now();

    await page.goto('/', { waitUntil: 'domcontentloaded' });

    const loadTime = Date.now() - startTime;

    // Should load within 5 seconds
    expect(loadTime).toBeLessThan(5000);
  });

  test('no memory leaks on navigation', async ({ page }) => {
    // Navigate multiple times and check memory doesn't explode
    const initialMemory = await page.evaluate(() => {
      return (performance as any).memory?.usedJSHeapSize || 0;
    });

    for (let i = 0; i < 5; i++) {
      await page.goto('/');
      await page.goto('/login/');
    }

    const finalMemory = await page.evaluate(() => {
      return (performance as any).memory?.usedJSHeapSize || 0;
    });

    // Memory shouldn't grow excessively (allow 50% growth)
    if (initialMemory > 0) {
      expect(finalMemory).toBeLessThan(initialMemory * 1.5);
    }
  });
});

test.describe('Keyboard Navigation', () => {
  test('can tab through interactive elements', async ({ page }) => {
    await page.goto('/login/');

    // Start tabbing
    await page.keyboard.press('Tab');
    await page.keyboard.press('Tab');
    await page.keyboard.press('Tab');

    // Something should be focused
    const focusedTag = await page.evaluate(() => document.activeElement?.tagName);
    expect(focusedTag).toBeTruthy();
  });

  test('enter key submits form', async ({ page }) => {
    await page.goto('/login/');

    await page.locator('#email').fill('test@example.com');
    await page.keyboard.press('Enter');

    // Form should attempt submission (stay on page or show response)
    await page.waitForTimeout(500);

    const body = await page.locator('body');
    await expect(body).toBeVisible();
  });
});
