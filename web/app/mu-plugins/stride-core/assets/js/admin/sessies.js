/* ==========================================================================
   Stride Admin Workspace — Sessies (agenda) list surface (Cluster F)
   --------------------------------------------------------------------------
   There is NO standalone sessions list endpoint (backend FROZEN — we do NOT
   invent one). The agenda view of GET /admin/editions?view=agenda returns ONE
   ROW PER SESSION DATE (a session->edition INNER JOIN), which is exactly an
   agenda. This surface renders that as a date-sorted agenda, grouped by day.

   Item shape (ground-truthed from getEditionsAgendaView + EditionAdminMapper):
     { id (editionId), sessionId, title (edition title), sessionTitle,
       date:'YYYY-MM-DD', startTime|null, endTime|null, venue|null,
       course{id,title}, capacity, registeredCount, status:<OfferingStatus>,
       isToday, isPast, editUrl }

   The agenda is server-paged by session date (date ASC). We group the loaded
   page's rows by `date` for the day headers (a presentational client-side
   group of the already-paged rows — NOT a corpus slice). Edit → editUrl.

   INV-5: every x-html binds a CONSTANT icon name. Data via x-text. INV-7: the
   edition status value is rendered as received; label/hue are the same
   closed-enum OfferingStatus presentation maps used by the edities surface.
   ========================================================================== */
(function (root, factory) {
  'use strict';
  const api = factory();
  if (typeof module !== 'undefined' && module.exports) {
    module.exports = api;
  }
  if (typeof root !== 'undefined') {
    root.sessies = api.sessies;
    root.WS = root.WS || {};
    root.WS.groupByDate = api.groupByDate;
  }
})(typeof window !== 'undefined' ? window : this, function () {
  'use strict';

  /* OfferingStatus VALUE → Dutch label + badge hue (presentation-only, mirrors
     OfferingStatus::label()). Kept local so this surface owns its own state per
     the per-surface structural rule. */
  const STATUS_META = {
    draft:       { label: 'Concept',                cls: 'cancelled' },
    announcement:{ label: 'Vooraankondiging',       cls: 'waitlist' },
    open:        { label: 'Open voor inschrijving', cls: 'confirmed' },
    full:        { label: 'Volzet',                 cls: 'pending' },
    in_progress: { label: 'Lopend',                 cls: 'interest' },
    postponed:   { label: 'Uitgesteld',             cls: 'waitlist' },
    cancelled:   { label: 'Geannuleerd',            cls: 'cancelled' },
    completed:   { label: 'Afgelopen',              cls: 'completed' },
    archived:    { label: 'Gearchiveerd',           cls: 'cancelled' },
  };

  /* ---- group a paged set of agenda rows by their date (PURE) --------------
     Returns an ordered list of { date, rows } day-buckets, preserving the
     server's date-ASC order. Rows missing a date fall into a trailing '' bucket
     (defensive — agenda rows always carry a date, but never crash). */
  function groupByDate(rows) {
    const list = Array.isArray(rows) ? rows : [];
    const order = [];
    const map = {};
    list.forEach((r) => {
      const d = (r && r.date) || '';
      if (!(d in map)) { map[d] = []; order.push(d); }
      map[d].push(r);
    });
    return order.map((d) => ({ date: d, rows: map[d] }));
  }

  function sessies() {
    return {
      rows: [],
      total: 0,
      page: 1,
      perPage: 25,
      pageCount: 1,

      filters: { q: '' },

      loading: false,
      error: '',

      init() {
        // I-1: load the FIRST time sessies becomes active, not on mount.
        window.WS.lazyLoad(this, 'sessies', () => this.load(1));
      },

      async load(page) {
        if (page != null) this.page = page;
        this.loading = true;
        this.error = ''; // clear at the TOP (cluster-B lesson)

        const params = new URLSearchParams();
        params.set('view', 'agenda');
        params.set('page', String(this.page));
        params.set('per_page', String(this.perPage));
        if (this.filters.q) params.set('search', this.filters.q);

        try {
          const data = await this.api(`/admin/editions?${params.toString()}`);
          this.rows = (data && data.items) || [];
          this.total = (data && data.total) || 0;
          this.page = (data && data.page) || 1;
          this.perPage = (data && data.perPage) || this.perPage;
          this.pageCount = (data && data.totalPages) || 1;
        } catch (e) {
          this.error = (e && e.message) ? e.message : 'Kon de agenda niet laden.';
          this.rows = [];
          this.total = 0;
        } finally {
          this.loading = false;
        }
      },

      reload() { this.load(1); },
      onSearchChange() { this.load(1); },
      goPage(p) { if (p >= 1 && p <= this.pageCount && p !== this.page) this.load(p); },
      onPerPageChange() { this.load(1); },

      get hasFilters() { return !!this.filters.q; },
      clearAllFilters() { this.filters = { q: '' }; this.load(1); },

      /* day-grouped buckets of the current page (presentational) */
      get days() { return groupByDate(this.rows); },

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

      statusLabel(value) { const m = STATUS_META[String(value || '')]; return m ? m.label : (value || '—'); },
      badgeClass(value) { const m = STATUS_META[String(value || '')]; return m ? m.cls : 'cancelled'; },

      /* Dutch long date for the day header, from the YYYY-MM-DD agenda date.
         Built from the date parts (no Date() TZ surprise on a bare date). */
      dayLabel(date) {
        if (!date) return '—';
        const parts = String(date).split('-');
        if (parts.length !== 3) return date;
        const months = ['januari', 'februari', 'maart', 'april', 'mei', 'juni', 'juli', 'augustus', 'september', 'oktober', 'november', 'december'];
        const m = Number(parts[1]) - 1;
        const monthName = (m >= 0 && m < 12) ? months[m] : parts[1];
        return `${Number(parts[2])} ${monthName} ${parts[0]}`;
      },
      timeRange(r) {
        if (!r) return '';
        if (r.startTime && r.endTime) return `${r.startTime}–${r.endTime}`;
        return r.startTime || r.endTime || '';
      },

      openRow(r) { if (r && r.editUrl) window.location.href = r.editUrl; },

      emptyTitle() {
        if (this.filters.q) return `Geen sessies voor "${this.filters.q}"`;
        return 'Geen geplande sessies';
      },
    };
  }

  return {
    sessies,
    groupByDate,
  };
});
