/* ==========================================================================
   Stride Admin Workspace — Edities list surface (Cluster F)
   --------------------------------------------------------------------------
   One of the four functional list surfaces. Its own per-surface Alpine factory
   owns ALL of its data in init(): it fetches GET /admin/editions?view=list
   server-side (paging/filter server-owned) and re-loads on every filter/page
   change. It owns its own loading / empty / error state.

   The endpoint returns editions with the EFFECTIVE status (INV-7) — the same
   read the typeahead uses. The item carries `status` (an OfferingStatus VALUE,
   e.g. 'open'/'full'/'in_progress') but NO label, so we map the VALUE to a
   Dutch label + a closed-enum badge-hue class here (presentation only; we do
   NOT re-derive the status — the value is rendered as the server decided it).

   Item shape (ground-truthed from EditionAdminMapper + getEditions LIST view):
     { id, title, course{id,title,tags}, capacity, registeredCount,
       status:<OfferingStatus value>, startDate|null, endDate|null, venue|null,
       isToday, isPast, editUrl }

   Edit action → editUrl (the server already sends post.php?post=<id>&action=edit).

   INV-5: every x-html binds a CONSTANT icon name via icon('<literal>'). Data via
   x-text (auto-escaped). INV-7: status value rendered as received; the label +
   hue maps are presentation-only closed-enum lookups over the OfferingStatus set.
   ========================================================================== */
(function (root, factory) {
  'use strict';
  const api = factory();
  if (typeof module !== 'undefined' && module.exports) {
    module.exports = api;
  }
  if (typeof root !== 'undefined') {
    root.edities = api.edities;
    root.WS = root.WS || {};
    root.WS.editionStatusLabel = api.editionStatusLabel;
    root.WS.editionBadgeClass = api.editionBadgeClass;
  }
})(typeof window !== 'undefined' ? window : this, function () {
  'use strict';

  /* ---- OfferingStatus VALUE → Dutch label + badge hue (PURE, presentation) -
     Mirrors OfferingStatus::label() exactly (the server sends only the VALUE on
     this endpoint, so the label is reconstructed here from the closed enum —
     NOT re-derived from dates; the value already IS the effective status).
     Unknown/empty → a neutral fallthrough, never a crash or arbitrary class. */
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
  function editionStatusLabel(value) {
    const m = STATUS_META[String(value || '')];
    return m ? m.label : (value || '—');
  }
  function editionBadgeClass(value) {
    const m = STATUS_META[String(value || '')];
    return m ? m.cls : 'cancelled';
  }

  /* The status filter options — the active (non-terminal) set an admin filters
     by most. Closed enum, value + Dutch label. */
  const STATUS_OPTIONS = [
    { value: 'announcement', label: 'Vooraankondiging' },
    { value: 'open',         label: 'Open voor inschrijving' },
    { value: 'full',         label: 'Volzet' },
    { value: 'in_progress',  label: 'Lopend' },
    { value: 'postponed',    label: 'Uitgesteld' },
    { value: 'completed',    label: 'Afgelopen' },
    { value: 'cancelled',    label: 'Geannuleerd' },
  ];

  function edities() {
    return {
      statusOptions: STATUS_OPTIONS,

      rows: [],
      total: 0,
      page: 1,
      perPage: 25,
      pageCount: 1,

      filters: { status: '', q: '' },

      loading: false,
      error: '',

      init() {
        this.load(1);
      },

      async load(page) {
        if (page != null) this.page = page;
        this.loading = true;
        this.error = ''; // clear at the TOP (cluster-B lesson)

        const params = new URLSearchParams();
        params.set('view', 'list');
        params.set('page', String(this.page));
        params.set('per_page', String(this.perPage));
        const f = this.filters;
        if (f.status) params.set('status', f.status);
        if (f.q) params.set('search', f.q);

        try {
          const data = await this.api(`/admin/editions?${params.toString()}`);
          this.rows = (data && data.items) || [];
          this.total = (data && data.total) || 0;
          this.page = (data && data.page) || 1;
          this.perPage = (data && data.perPage) || this.perPage;
          this.pageCount = (data && data.totalPages) || 1;
        } catch (e) {
          this.error = (e && e.message) ? e.message : 'Kon de edities niet laden.';
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

      get hasFilters() { return !!(this.filters.status || this.filters.q); },
      clearAllFilters() { this.filters = { status: '', q: '' }; this.load(1); },

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

      statusLabel(value) { return editionStatusLabel(value); },
      badgeClass(value) { return editionBadgeClass(value); },

      /* capacity meter — registered / capacity, clamped, '—' when uncapped */
      fillPct(r) {
        if (!r || !r.capacity) return 0;
        return Math.min(100, Math.round((r.registeredCount / r.capacity) * 100));
      },
      capLabel(r) {
        if (!r) return '—';
        if (!r.capacity) return String(r.registeredCount || 0);
        return `${r.registeredCount || 0} / ${r.capacity}`;
      },

      /* row → existing edition edit screen (server-supplied editUrl). */
      openRow(r) { if (r && r.editUrl) window.location.href = r.editUrl; },

      emptyTitle() {
        if (this.filters.q) return `Geen edities voor "${this.filters.q}"`;
        if (this.filters.status) return 'Geen edities met deze status';
        return 'Geen edities gevonden';
      },
    };
  }

  return {
    edities,
    editionStatusLabel,
    editionBadgeClass,
  };
});
