import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { sidebarRail } from './js/sidebar-rail.js';

const KEY = 'stride-rail';

describe('sidebarRail', () => {
  beforeEach(() => {
    localStorage.clear();
  });

  afterEach(() => {
    vi.restoreAllMocks();
  });

  it('defaults to expanded when no stored value exists', () => {
    const rail = sidebarRail();
    rail.init();

    expect(rail.collapsed).toBe(false);
  });

  it('toggle() flips the state and persists "1"/"0" to localStorage', () => {
    const rail = sidebarRail();
    rail.init();

    rail.toggle();
    expect(rail.collapsed).toBe(true);
    expect(localStorage.getItem(KEY)).toBe('1');

    rail.toggle();
    expect(rail.collapsed).toBe(false);
    expect(localStorage.getItem(KEY)).toBe('0');
  });

  it('restores collapsed state from a stored "1"', () => {
    localStorage.setItem(KEY, '1');

    const rail = sidebarRail();
    rail.init();

    expect(rail.collapsed).toBe(true);
  });

  it('treats a garbage stored value as expanded and does not throw', () => {
    localStorage.setItem(KEY, 'banana');

    const rail = sidebarRail();
    expect(() => rail.init()).not.toThrow();

    expect(rail.collapsed).toBe(false);
  });

  it('stays expanded and does not throw when localStorage.getItem throws (private mode)', () => {
    vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
      throw new Error('SecurityError: denied');
    });

    const rail = sidebarRail();
    expect(() => rail.init()).not.toThrow();

    expect(rail.collapsed).toBe(false);
  });

  it('toggle() still flips state when localStorage.setItem throws (private mode)', () => {
    vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
      throw new Error('QuotaExceededError');
    });

    const rail = sidebarRail();
    rail.init();

    expect(() => rail.toggle()).not.toThrow();
    expect(rail.collapsed).toBe(true);
  });
});
