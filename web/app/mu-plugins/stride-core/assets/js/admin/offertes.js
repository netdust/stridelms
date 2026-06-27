/* ==========================================================================
   Stride Admin Workspace — Offertes list surface (Cluster F)
   --------------------------------------------------------------------------
   One of the four functional list surfaces. Its own per-surface Alpine factory
   owns ALL of its data in init(): it fetches GET /admin/quotes server-side
   (paging/filter server-owned) and re-loads on every filter/page change. It
   owns its own loading / empty / error state.

   ENVELOPE CAVEAT (Phase-1 deferred, backend FROZEN): AdminQuoteService::
   getQuoteList returns the normal envelope { items, total, page, perPage,
   totalPages }, BUT on a zero-USER-search short-circuit it returns
   { data:[], total:0, … } (the `data` key, NOT `items`). We TOLERATE BOTH
   client-side via the pure quoteRows() normalizer below — we do NOT touch the
   backend to normalize. quoteRows() is the only branching logic on this surface
   and is exported (UMD tail) for the Tier-A unit test.

   Quote `status` is WORKFLOW status (Draft/Sent/Exported/Cancelled) — NOT
   payment (Stride does not track payment; Exact Online owns invoicing). The
   server sends `status` (value) + `statusLabel` (AS-RECEIVED, INV-7); we render
   the label as received and map the VALUE to a closed-enum badge-hue class only.

   INV-5: every x-html binds a CONSTANT icon name via icon('<literal>'); never a
   data field. Refs / names / amounts / labels render via x-text (auto-escaped).
   INV-7: statusLabel renders AS RECEIVED — never re-derived; badgeClass maps the
   VALUE to a CSS hue only (presentation), it does NOT re-derive the status.
   ========================================================================== */
(function (root, factory) {
  'use strict';
  const api = factory();
  if (typeof module !== 'undefined' && module.exports) {
    module.exports = api; // Node / Playwright unit test
  }
  if (typeof root !== 'undefined') {
    root.offertes = api.offertes;
    root.WS = root.WS || {};
    root.WS.quoteRows = api.quoteRows;
    root.WS.quoteBadgeClass = api.quoteBadgeClass;
  }
})(typeof window !== 'undefined' ? window : this, function () {
  'use strict';

  /* ---- envelope normalizer (PURE, Tier-A) --------------------------------
     The backend emits `items` normally but `data` on the zero-user-search
     short-circuit (Phase-1 deferred; backend frozen). Tolerate BOTH, in that
     precedence (items wins if both somehow present), and degrade an
     absent/malformed payload to [] — never a crash, never undefined rows. */
  function quoteRows(payload) {
    if (!payload || typeof payload !== 'object') return [];
    if (Array.isArray(payload.items)) return payload.items;
    if (Array.isArray(payload.data)) return payload.data;
    return [];
  }

  /* ---- quote status VALUE → badge hue class (PURE, styling only) ----------
     INV-7: the LABEL is rendered AS RECEIVED (statusLabel); this maps the
     closed quote-workflow VALUE to one of the shipped ws-badge--* hue classes
     only. Unknown/empty → 'cancelled' (the neutral slate hue), never an
     arbitrary class. */
  const QUOTE_BADGE = {
    draft:     'pending',    // In behandeling — amber/awaiting
    sent:      'confirmed',  // Verzonden — out the door
    exported:  'completed',  // Verwerkt — done in Exact
    cancelled: 'cancelled',  // Geannuleerd — dead-end
  };
  function quoteBadgeClass(status) {
    return QUOTE_BADGE[String(status || '')] || 'cancelled';
  }

  /* ---- the Alpine factory ------------------------------------------------ */
  function offertes() {
    return {
      /* server-driven page state */
      rows: [],
      total: 0,
      page: 1,
      perPage: 25,
      pageCount: 1,

      /* The Tag dropdown options — fetched ONCE from /admin/course-tags `.tag`
         (same vocabulary the Edities surface uses). */
      tagOptions: [],

      /* filter state — every change re-fetches. Search + Tag + Date only
         (Status removed — mirrors the Edities filter set). */
      filters: { q: '', tag: '', dateFrom: '', dateTo: '' },

      /* per-surface load state */
      loading: false,
      error: '',

      /* flatpickr instance (set in init, used by clearAllFilters). */
      _fp: null,

      init() {
        // The Tag vocabulary is independent of the quote load — fetch it once,
        // regardless of lazy-load, so the dropdown is populated on first paint.
        this.loadFilterOptions();

        // The single date field is a flatpickr range picker (single date OR
        // range). Instantiate on the x-ref input AFTER it exists. Same pattern
        // as the Edities surface.
        if (window.flatpickr && this.$refs && this.$refs.dateInput) {
          this._fp = window.flatpickr(this.$refs.dateInput, {
            mode: 'range',
            dateFormat: 'Y-m-d',
            locale: 'nl',
            onChange: (dates) => this.onDateChange(dates),
          });
        }

        // I-1: load the FIRST time offertes becomes active, not on mount.
        window.WS.lazyLoad(this, 'offertes', () => this.load(1));
      },

      /* Fetch the Tag vocabulary ONCE. Only the `.tag` array (free-form admin
         tags). On failure leave [] — the dropdown just shows "Alle tags". */
      async loadFilterOptions() {
        try {
          const data = await this.api('/admin/course-tags');
          this.tagOptions = (data && data.tag) || [];
        } catch (e) {
          this.tagOptions = [];
        }
      },

      /* flatpickr range onChange:
         - 1 date  → single day: date_from == date_to
         - 2 dates → a range:    date_from = dates[0], date_to = dates[1]
         - 0 dates → cleared:    both empty
         Formatted to YYYY-MM-DD via the picker to match date_from/date_to
         (sanitize_text_field server-side, validated as Y-m-d). */
      onDateChange(dates) {
        const fmt = (d) => (this._fp ? this._fp.formatDate(d, 'Y-m-d') : '');
        if (dates && dates.length === 1) {
          this.filters.dateFrom = fmt(dates[0]);
          this.filters.dateTo = fmt(dates[0]);
        } else if (dates && dates.length >= 2) {
          this.filters.dateFrom = fmt(dates[0]);
          this.filters.dateTo = fmt(dates[1]);
        } else {
          this.filters.dateFrom = '';
          this.filters.dateTo = '';
        }
        this.load(1);
      },

      async load(page) {
        if (page != null) this.page = page;
        this.loading = true;
        this.error = ''; // clear at the TOP so a successful reload recovers (cluster-B lesson)

        const params = new URLSearchParams();
        params.set('page', String(this.page));
        params.set('per_page', String(this.perPage));
        const f = this.filters;
        if (f.q) params.set('search', f.q);
        if (f.tag) params.set('tag', String(f.tag));
        if (f.dateFrom) params.set('date_from', f.dateFrom);
        if (f.dateTo) params.set('date_to', f.dateTo);

        try {
          const data = await this.api(`/admin/quotes?${params.toString()}`);
          this.rows = quoteRows(data); // tolerate items|data envelope
          this.total = (data && data.total) || 0;
          this.page = (data && data.page) || 1;
          this.perPage = (data && data.perPage) || this.perPage;
          this.pageCount = (data && data.totalPages) || 1;
        } catch (e) {
          this.error = (e && e.message) ? e.message : 'Kon de offertes niet laden.';
          this.rows = [];
          this.total = 0;
        } finally {
          this.loading = false;
        }
      },

      reload() { this.load(1); },
      onFilterChange() { this.load(1); },
      onSearchChange() { this.load(1); },
      goPage(p) { if (p >= 1 && p <= this.pageCount && p !== this.page) this.load(p); },
      onPerPageChange() { this.load(1); },

      get hasFilters() {
        const f = this.filters;
        return !!(f.q || f.tag || f.dateFrom || f.dateTo);
      },
      clearAllFilters() {
        this.filters = { q: '', tag: '', dateFrom: '', dateTo: '' };
        // clear() fires onChange([]) → guarded to both-empty; clear the picker
        // first, then load once below to avoid a double load.
        if (this._fp) this._fp.clear();
        this.load(1);
      },

      get rangeFrom() { return this.total === 0 ? 0 : (this.page - 1) * this.perPage + 1; },
      get rangeTo() { return Math.min(this.page * this.perPage, this.total); },
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

      badgeClass(status) { return quoteBadgeClass(status); },

      /* row → existing quote edit screen (WP post edit; the server already
         sends editUrl = post.php?post=<id>&action=edit). */
      openRow(r) { if (r && r.editUrl) window.location.href = r.editUrl; },

      emptyTitle() {
        if (this.filters.q) return `Geen offertes voor "${this.filters.q}"`;
        return 'Geen offertes gevonden';
      },
    };
  }

  return {
    offertes,
    quoteRows,
    quoteBadgeClass,
  };
});
