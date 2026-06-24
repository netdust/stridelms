/* ==========================================================================
   Stride Admin Workspace — Gebruikers search surface (Cluster F)
   --------------------------------------------------------------------------
   A search-DRIVEN surface (NOT a paged list): GET /admin/users/search?q=<query>
   returns a BARE ARRAY (not an {items} envelope — ground-truthed from
   AdminAPIController::searchUsers) of {id, name, email, organisation,
   registration_count} (max 10). The endpoint NEEDS a q; on an empty query we
   show a "search for a user" prompt, not an error and not a request.

   Row click → switchView('dossier', { user: u.id }) — reuses the cluster-D
   dossier deep-link (the shell owns switchView).

   This factory owns its own loading / empty / error / prompt state. The
   response is a bare array, so we tolerate a non-array defensively (-> []).

   INV-5: x-html binds CONSTANT icon names only. Names/emails/orgs via x-text.
   ========================================================================== */
(function (root, factory) {
  'use strict';
  const api = factory();
  if (typeof module !== 'undefined' && module.exports) {
    module.exports = api;
  }
  if (typeof root !== 'undefined') {
    root.gebruikers = api.gebruikers;
    root.WS = root.WS || {};
    root.WS.userRows = api.userRows;
    root.WS.userInitials = root.WS.userInitials || api.userInitials;
  }
})(typeof window !== 'undefined' ? window : this, function () {
  'use strict';

  /* The endpoint returns a BARE ARRAY. Tolerate a non-array (error shape, null)
     defensively so a malformed response never crashes the render. (PURE) */
  function userRows(payload) {
    return Array.isArray(payload) ? payload : [];
  }

  function userInitials(name) {
    const p = String(name || '').trim().split(/\s+/);
    return ((p[0]?.[0] || '') + (p[p.length - 1]?.[0] || '')).toUpperCase();
  }

  function gebruikers() {
    return {
      rows: [],
      query: '',
      searched: false, // a search has been run (drives prompt-vs-empty)

      loading: false,
      error: '',

      init() {
        // Search-driven: nothing to load until the admin types. Show the prompt.
      },

      async search() {
        const q = (this.query || '').trim();
        if (!q) {
          // Empty query → reset to the prompt, never an error, never a request.
          this.rows = [];
          this.searched = false;
          this.error = '';
          return;
        }
        this.loading = true;
        this.error = ''; // clear at the TOP (cluster-B lesson)
        try {
          const data = await this.api(`/admin/users/search?q=${encodeURIComponent(q)}`);
          this.rows = userRows(data); // bare-array tolerant
          this.searched = true;
        } catch (e) {
          this.error = (e && e.message) ? e.message : 'Kon gebruikers niet zoeken.';
          this.rows = [];
          this.searched = true;
        } finally {
          this.loading = false;
        }
      },

      reload() { this.search(); },
      clearSearch() { this.query = ''; this.rows = []; this.searched = false; this.error = ''; },

      get showPrompt() { return !this.loading && !this.error && !this.searched; },
      get showEmpty() { return !this.loading && !this.error && this.searched && this.rows.length === 0; },

      initials(name) { return userInitials(name); },

      /* row → the person's dossier (cluster D reads ?user=) */
      openRow(u) { if (u && u.id) this.switchView('dossier', { user: u.id }); },
    };
  }

  return {
    gebruikers,
    userRows,
    userInitials,
  };
});
