/* ==========================================================================
   Stride Admin Workspace — Gebruikers search surface (Cluster F)
   --------------------------------------------------------------------------
   A search-DRIVEN surface: GET /admin/users/search?q=<query>&page=&per_page=
   returns the standard envelope { items, total, page, perPage, totalPages }
   of {id, name, email, organisation, registration_count, anonymised} —
   PAGED since the Gebruikers slice (F-U1: the old bare-array cap of 10 was
   presented as the complete result set). The endpoint NEEDS a q of at least
   2 characters; the CLIENT guards shorter queries (the server's minLength
   400 used to flash as a raw English error on every 1-character keystroke)
   and shows the prompt instead. On an empty query we show the prompt — not
   an error and not a request.

   Row click → switchView('dossier', { user: u.id }) — reuses the cluster-D
   dossier deep-link (the shell owns switchView).

   This factory owns its own loading / empty / error / prompt state, with a
   _searchReq token so a slow earlier response never overwrites a faster
   later one (the shared list-race rule). userRows() normalizes the envelope
   and stays tolerant of the legacy bare array.

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

  /* Envelope normalizer (PURE): the endpoint emits { items } since the
     Gebruikers slice; the legacy bare array is tolerated defensively, and a
     malformed payload degrades to [] — never a crash. */
  function userRows(payload) {
    if (Array.isArray(payload)) return payload;
    if (payload && Array.isArray(payload.items)) return payload.items;
    return [];
  }

  function userInitials(name) {
    const p = String(name || '').trim().split(/\s+/);
    return ((p[0]?.[0] || '') + (p[p.length - 1]?.[0] || '')).toUpperCase();
  }

  /* The server rejects q shorter than this (minLength) — guard client-side
     so a short query shows the prompt, never a raw 400 flash (F-U1). */
  const MIN_QUERY = 2;

  function gebruikers() {
    return {
      rows: [],
      total: 0,
      page: 1,
      perPage: 25,
      pageCount: 1,
      query: '',
      searched: false, // a search has been run (drives prompt-vs-empty)

      loading: false,
      error: '',
      _searchReq: 0,

      init() {
        // Search-driven: nothing to load until the admin types. Show the prompt.
      },

      /* Debounced input handler — every new TERM restarts at page 1. */
      onQueryChange() { this.search(1); },

      async search(page) {
        if (page != null) this.page = page;
        const q = (this.query || '').trim();
        if (q.length < MIN_QUERY) {
          // Empty/too-short query → the prompt. Never an error, never a
          // request (the server's minLength 400 must stay unreachable from
          // normal typing). Bump the token so an in-flight longer-query
          // response can't land on the prompt.
          this._searchReq++;
          this.rows = [];
          this.total = 0;
          this.pageCount = 1;
          this.searched = false;
          this.error = '';
          this.loading = false;
          return;
        }
        const req = ++this._searchReq;
        this.loading = true;
        this.error = ''; // clear at the TOP (cluster-B lesson)
        try {
          const params = new URLSearchParams({
            q,
            page: String(this.page),
            per_page: String(this.perPage),
          });
          const data = await this.api(`/admin/users/search?${params.toString()}`);
          if (req !== this._searchReq) return; // superseded — drop stale response
          this.rows = userRows(data);
          this.total = Number(data && data.total) || this.rows.length;
          this.pageCount = Math.max(1, Number(data && data.totalPages) || 1);
          if (this.page > this.pageCount) {
            this.page = this.pageCount;
            this.search();
            return;
          }
          this.searched = true;
        } catch (e) {
          if (req !== this._searchReq) return;
          this.error = (e && e.message) ? e.message : 'Kon gebruikers niet zoeken.';
          this.rows = [];
          this.total = 0;
          this.searched = true;
        } finally {
          if (req === this._searchReq) this.loading = false;
        }
      },

      reload() { this.search(); },
      clearSearch() {
        this._searchReq++; // cancel any in-flight search
        this.query = '';
        this.rows = [];
        this.total = 0;
        this.page = 1;
        this.pageCount = 1;
        this.searched = false;
        this.error = '';
        this.loading = false;
      },

      /* Shared pager contract (goPage(p) absolute + pageList() ellipsis). */
      goPage(p) { if (p >= 1 && p <= this.pageCount && p !== this.page) this.search(p); },
      pageList() {
        const last = this.pageCount, cur = this.page, out = [];
        if (last <= 7) { for (let i = 1; i <= last; i++) out.push(i); return out; }
        out.push(1);
        if (cur > 3) out.push('…');
        for (let i = Math.max(2, cur - 1); i <= Math.min(last - 1, cur + 1); i++) out.push(i);
        if (cur < last - 2) out.push('…');
        out.push(last);
        return out;
      },
      get rangeFrom() { return this.total === 0 ? 0 : (this.page - 1) * this.perPage + 1; },
      get rangeTo() { return Math.min(this.page * this.perPage, this.total); },

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
