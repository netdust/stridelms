/**
 * Toast notification store factory.
 *
 * Extracted from main.js so Vitest can import the real factory
 * (main.js boots Alpine on import and cannot be imported in tests).
 *
 * Dispatch contract (back-compatible, SSA-3):
 *   $dispatch('toast', { message: 'Opgeslagen', type: 'success' })
 *
 * - `message` maps to the card title; `type` defaults to 'success'
 *   (any non-'error' type renders the success variant).
 * - Optional `sub` renders a second muted line.
 * - A second show() before the 4000ms auto-hide replaces the content
 *   and resets the timer; close() hides immediately and clears it.
 */
export function createToastStore() {
  return {
    visible: false,
    title: '',
    sub: '',
    type: 'success',
    timeout: null,

    show({ message, type = 'success', sub = '' }) {
      clearTimeout(this.timeout);
      this.title = message;
      this.sub = sub;
      this.type = type;
      this.visible = true;
      this.timeout = setTimeout(() => (this.visible = false), 4000);
    },

    close() {
      clearTimeout(this.timeout);
      this.timeout = null;
      this.visible = false;
    },
  };
}
