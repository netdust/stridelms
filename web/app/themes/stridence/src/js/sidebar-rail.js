/**
 * Dashboard sidebar rail factory (Helder Tij).
 *
 * Extracted from main.js so Vitest can import the real factory
 * (main.js boots Alpine on import and cannot be imported in tests) —
 * same pattern as toast-store.js / content-tabs.js.
 *
 * Collapse state persists across page loads via localStorage key
 * 'stride-rail' ('1' = collapsed, anything else = expanded). Storage
 * access is wrapped in try/catch so private-mode browsers (where
 * localStorage throws) simply fall back to the expanded default.
 *
 * Server-render-first: the sidebar renders expanded without JS;
 * Alpine only layers the collapse behavior on top.
 *
 * Usage: x-data="sidebarRail()"
 */
export function sidebarRail() {
  return {
    collapsed: false,
    init() {
      try {
        this.collapsed = localStorage.getItem('stride-rail') === '1';
      } catch (e) {
        this.collapsed = false;
      }
    },
    toggle() {
      this.collapsed = !this.collapsed;
      try {
        localStorage.setItem('stride-rail', this.collapsed ? '1' : '0');
      } catch (e) {
        /* private mode — state still toggles for this page view */
      }
    },
  };
}
