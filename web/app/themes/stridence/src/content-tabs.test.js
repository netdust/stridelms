import { describe, it, expect, beforeEach } from 'vitest';
import { contentTabs } from './js/content-tabs.js';

const TABS = ['omschrijving', 'programma', 'praktisch', 'lesgever'];

describe('contentTabs', () => {
  beforeEach(() => {
    // jsdom: reset hash between tests so init() cases don't leak state.
    window.location.hash = '';
  });

  it('defaults to the first tab when no initial tab is given', () => {
    const tabs = contentTabs(TABS);

    expect(tabs.activeTab).toBe('omschrijving');
    expect(tabs.isActive('omschrijving')).toBe(true);
    expect(tabs.isActive('programma')).toBe(false);
  });

  it('honours a known initial tab and falls back to first on an unknown one', () => {
    expect(contentTabs(TABS, 'praktisch').activeTab).toBe('praktisch');
    expect(contentTabs(TABS, 'bogus').activeTab).toBe('omschrijving');
  });

  it('setTab activates a known tab', () => {
    const tabs = contentTabs(TABS);

    tabs.setTab('praktisch');

    expect(tabs.activeTab).toBe('praktisch');
    expect(tabs.isActive('praktisch')).toBe(true);
  });

  it('ignores setTab with an unknown id — activeTab unchanged', () => {
    const tabs = contentTabs(TABS);
    tabs.setTab('praktisch');

    tabs.setTab('bogus');

    expect(tabs.activeTab).toBe('praktisch');
  });

  it('init() preselects the tab named in location.hash', () => {
    window.location.hash = '#praktisch';
    const tabs = contentTabs(TABS);

    tabs.init();

    expect(tabs.activeTab).toBe('praktisch');
  });

  it('init() leaves the first/initial tab active on an unknown hash', () => {
    window.location.hash = '#bestaat-niet';

    const defaulted = contentTabs(TABS);
    defaulted.init();
    expect(defaulted.activeTab).toBe('omschrijving');

    const explicit = contentTabs(TABS, 'lesgever');
    explicit.init();
    expect(explicit.activeTab).toBe('lesgever');
  });

  it('handles an empty tabs array: activeTab is "" and setTab never throws', () => {
    const tabs = contentTabs([]);

    expect(tabs.activeTab).toBe('');
    expect(() => tabs.setTab('omschrijving')).not.toThrow();
    expect(tabs.activeTab).toBe('');
    expect(() => tabs.init()).not.toThrow();
  });
});
