/* ==========================================================================
   Stride Admin Workspace — global search palette (⌘K, Phase 3c / F-S1)
   --------------------------------------------------------------------------
   The topbar search was a DISABLED input with a ⌘K hint that did nothing —
   F-S1's rule: implement it or remove it, never ship it dead. This implements
   it over the THREE existing typeahead endpoints (no new server surface):

     GET /admin/users/search?q&per_page=5          → Personen  → dossier
     GET /admin/editions/options?q&scope=all       → Edities   → grid ?edition_id=
     GET /admin/trajectories/options?q&scope=all   → Trajecten → grid ?trajectory_id=

   Opened via ⌘K / Ctrl+K anywhere in the workspace, or by clicking the topbar
   search box. Debounced parallel fetches (Promise.allSettled — one failing
   group renders its own error line, the others still show), a monotonic _req
   token (stale responses never land), min 2 code points (the users endpoint's
   server minimum — the gebruikers lesson). Arrow keys walk the flattened
   result list; Enter opens the active hit via the shell's switchView (the
   deep-link whitelist); Escape closes.

   INV-5: every x-html binds a CONSTANT icon name. Titles/names render via
   x-text (auto-escaped). INV-7: labels render AS RECEIVED.
   ========================================================================== */
(function (root, factory) {
  'use strict';
  const api = factory();
  if (typeof module !== 'undefined' && module.exports) {
    module.exports = api; // Node / Playwright unit test
  }
  if (typeof root !== 'undefined') {
    root.gsearch = api.gsearch;
  }
})(typeof window !== 'undefined' ? window : this, function () {
  'use strict';

  const MIN_QUERY = 2;
  function queryLength(q) {
    return Array.from(q).length;
  }

  /* Flatten the grouped results into one arrow-key walkable list (PURE).
     Each entry carries the group + the raw item so pick() can route it. */
  function flattenResults(results) {
    const flat = [];
    (results.users || []).forEach((u) => flat.push({ group: 'users', item: u }));
    (results.editions || []).forEach((e) => flat.push({ group: 'editions', item: e }));
    (results.trajectories || []).forEach((t) => flat.push({ group: 'trajectories', item: t }));
    return flat;
  }

  function gsearch() {
    return {
      open: false,
      q: '',
      loading: false,
      searched: false,
      results: { users: [], editions: [], trajectories: [] },
      failed: { users: false, editions: false, trajectories: false },
      active: 0, // index into flat()
      _req: 0,
      _searchedQ: '', // the query the CURRENT results answer (Enter gate)

      /* Alpine auto-invokes init() — the template must NOT also declare
         x-init="init()" (that registered this permanent listener twice).
         CAPTURE phase: when the palette is open its Escape must be swallowed
         BEFORE the bubble-phase @keydown.escape.window handlers of whatever
         modal/slide-over sits underneath — one keypress closes ONE layer. */
      init() {
        window.addEventListener('keydown', (e) => {
          if ((e.metaKey || e.ctrlKey) && String(e.key).toLowerCase() === 'k') {
            e.preventDefault();
            this.openPalette();
            return;
          }
          if (e.key === 'Escape' && this.open) {
            e.stopPropagation();
            this.close();
          }
        }, true);
      },

      openPalette() {
        this.open = true;
        this.$nextTick(() => {
          if (this.$refs && this.$refs.gsearchInput) this.$refs.gsearchInput.focus();
        });
      },

      close() {
        this._req++; // drop any in-flight response
        this.open = false;
        this.q = '';
        this._searchedQ = '';
        this.loading = false;
        this.searched = false;
        this.results = { users: [], editions: [], trajectories: [] };
        this.failed = { users: false, editions: false, trajectories: false };
        this.active = 0;
      },

      get flat() { return flattenResults(this.results); },
      get hasResults() { return this.flat.length > 0; },

      async search() {
        const q = (this.q || '').trim();
        if (queryLength(q) < MIN_QUERY) {
          this._req++;
          this.results = { users: [], editions: [], trajectories: [] };
          this.failed = { users: false, editions: false, trajectories: false };
          this.searched = false;
          this.loading = false;
          this.active = 0;
          return;
        }
        const req = ++this._req;
        this.loading = true;
        const enc = encodeURIComponent(q);

        const [users, editions, trajectories] = await Promise.allSettled([
          this.api(`/admin/users/search?q=${enc}&per_page=5`),
          this.api(`/admin/editions/options?q=${enc}&scope=all&per_page=5`),
          this.api(`/admin/trajectories/options?q=${enc}&scope=all&per_page=5`),
        ]);
        if (req !== this._req) return; // superseded / palette closed

        const items = (settled) => (settled.status === 'fulfilled' && settled.value && Array.isArray(settled.value.items))
          ? settled.value.items
          : [];
        this.results = {
          users: items(users),
          editions: items(editions),
          trajectories: items(trajectories),
        };
        this.failed = {
          users: users.status === 'rejected',
          editions: editions.status === 'rejected',
          trajectories: trajectories.status === 'rejected',
        };
        this.active = 0;
        this.searched = true;
        this._searchedQ = q;
        this.loading = false;
      },

      move(delta) {
        const last = this.flat.length - 1;
        if (last < 0) return;
        this.active = Math.min(last, Math.max(0, this.active + delta));
      },

      /* Enter gate: the rendered results answer _searchedQ, not necessarily
         the CURRENT q (the 300ms debounce / in-flight window). Picking while
         they diverge would navigate to the top hit of the PREVIOUS query —
         the wrong person's PII one keystroke after a fast Enter. */
      pickActive() {
        if (this.loading || (this.q || '').trim() !== this._searchedQ) return;
        const hit = this.flat[this.active];
        if (hit) this.pick(hit.group, hit.item);
      },

      /* Route a hit through the shell's switchView (the deep-link whitelist):
         a person opens their dossier; an edition/trajectory opens the grid
         scoped to it (the same first-class filters the chips clear). */
      pick(group, item) {
        if (!item || !item.id) return;
        if (group === 'users') {
          this.switchView('dossier', { user: item.id });
        } else if (group === 'editions') {
          this.switchView('inschrijvingen', { edition_id: item.id });
        } else if (group === 'trajectories') {
          this.switchView('inschrijvingen', { trajectory_id: item.id });
        }
        this.close();
      },

      /* Stable flat-index of a (group, position) pair for the is-active bind. */
      flatIndex(group, i) {
        if (group === 'users') return i;
        if (group === 'editions') return this.results.users.length + i;
        return this.results.users.length + this.results.editions.length + i;
      },
    };
  }

  return {
    gsearch,
    flattenResults,
  };
});
