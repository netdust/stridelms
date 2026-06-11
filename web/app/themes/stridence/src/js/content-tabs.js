/**
 * Content tabs factory (Helder Tij).
 *
 * Extracted from main.js so Vitest can import the real factory
 * (main.js boots Alpine on import and cannot be imported in tests) —
 * same pattern as toast-store.js.
 *
 * Server-render-first: all panels are in the DOM; x-show toggles them.
 * Unknown tab ids are ignored (setTab) or fall back to the first/initial
 * tab (constructor, init-from-hash), so a bogus #hash can never blank
 * the page.
 *
 * setTab mirrors the active tab into the URL hash (replaceState — no
 * history entry per click) so tabs are deep-linkable/shareable.
 *
 * Usage: x-data="contentTabs(['omschrijving','programma','praktisch','lesgever'])"
 */
export function contentTabs(tabs = [], initial = null) {
  return {
    tabs,
    activeTab: initial && tabs.includes(initial) ? initial : (tabs[0] ?? ''),
    isActive(id) { return this.activeTab === id; },
    setTab(id) {
      if (this.tabs.includes(id)) {
        this.activeTab = id;
        history.replaceState(null, '', '#' + id);
      }
    },
    init() {
      const h = window.location.hash.replace('#', '');
      if (this.tabs.includes(h)) this.activeTab = h;
    },
  };
}
