/* ==========================================================================
   Stride Admin Workspace — Edities surface (Cluster F)
   --------------------------------------------------------------------------
   One of the four functional list surfaces. Its own per-surface Alpine factory
   owns ALL of its data: it fetches GET /admin/editions server-side
   (paging/filter server-owned) and re-loads on every filter/page change. It
   owns its own loading / empty / error state.

   TWO VIEWS (viewMode, F-E1):
     - 'agenda' (default) = ONE ROW PER SESSION DATE. A multi-session edition
       appears once per session date, ordered by date. Each row carries a
       session identity (sessionId, date, dateLabel, startTime/endTime)
       layered on the edition base. Dateless editions have no session rows —
       they NEVER appear here.
     - 'list' = ONE ROW PER EDITION, NULL-date-permitting (§10.7) — the ONLY
       place the sessionless interest-anchor editions are visible. Rows carry
       startDate/endDate + a server dateLabel instead of a session identity.

   SCOPE (F-E2): 'upcoming' (default) = the server's 2-day-lookback cutoff,
   shown as a toggle pill so the cutoff is visible and escapable; 'all' lifts
   it. An explicit date filter always overrides the default cutoff.

   The endpoint returns editions with the EFFECTIVE status (INV-7) — the same
   read the typeahead uses. The item carries `status` (an OfferingStatus VALUE,
   e.g. 'open'/'full'/'in_progress') but NO label, so we map the VALUE to a
   Dutch label + a closed-enum badge-hue class here (presentation only; we do
   NOT re-derive the status — the value is rendered as the server decided it).
   The Status FILTER matches this same effective status server-side.

   Item shape (ground-truthed from getEditions/getEditionsAgendaView):
     common: { id (editionId), title, status:<OfferingStatus value>, capacity,
               registeredCount, course{id,title}, venue|null, isToday, isPast,
               dateLabel (server Dutch label), editUrl }
     agenda adds: { sessionId, sessionTitle, date (YYYY-MM-DD),
                    startTime|null, endTime|null }
     list adds:   { startDate|null, endDate|null, course.tags }

   FILTERS: Search (edition title) + Status (OfferingStatus dropdown) + Tag
   (one dropdown from GET /admin/course-tags `.tag`) + a single flatpickr
   field (mode:'range' → single date OR range, with a clear ✕ that resets to
   the default cutoff).

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

  function edities() {
    return {
      rows: [],
      total: 0,
      page: 1,
      perPage: 25,
      pageCount: 1,

      /* Agenda (one row per session date) vs Lijst (one row per edition,
         NULL-date-permitting — the only home of dateless editions, F-E1). */
      viewMode: 'agenda',

      /* 'upcoming' (default, the visible 2-day-lookback pill) | 'all' (F-E2). */
      scope: 'upcoming',

      /* The Tag dropdown options — fetched ONCE from /admin/course-tags `.tag`. */
      tagOptions: [],

      filters: { q: '', status: '', tag: '', dateFrom: '', dateTo: '' },

      loading: false,
      error: '',

      /* flatpickr instance (set in init, used by clearAllFilters). */
      _fp: null,

      init() {
        // The single date field is a flatpickr range picker. Instantiate it on
        // the x-ref input AFTER it exists (we're inside init()). nl locale +
        // the picker are enqueued globally before Alpine boots.
        if (window.flatpickr && this.$refs && this.$refs.dateInput) {
          this._fp = window.flatpickr(this.$refs.dateInput, {
            mode: 'range',
            dateFormat: 'Y-m-d',
            locale: 'nl',
            onChange: (dates) => this.onDateChange(dates),
          });
        }

        // I-1: load the FIRST time edities becomes active, not on mount. The
        // Tag vocabulary rides the same gate (F-E3 — it was fetched eagerly on
        // mount for a surface the admin may never open).
        window.WS.lazyLoad(this, 'edities', () => {
          this.loadFilterOptions();
          this.load(1);
        });
      },

      /* Fetch the Tag vocabulary ONCE. We use only the `.tag` array (free-form
         admin tags). On failure leave [] — the dropdown just shows "Alle tags". */
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
         - 0 dates → cleared:    both empty (back to the default cutoff)
         Dates are formatted to YYYY-MM-DD via the picker so they match the
         endpoint's date_from/date_to (sanitize_text_field, no parsing). */
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
        this.error = ''; // clear at the TOP (cluster-B lesson)

        const params = new URLSearchParams();
        params.set('view', this.viewMode);
        params.set('page', String(this.page));
        params.set('per_page', String(this.perPage));
        if (this.scope === 'all') params.set('scope', 'all');
        const f = this.filters;
        if (f.q) params.set('search', f.q);
        if (f.status) params.set('status', f.status);
        if (f.tag) params.set('tag', String(f.tag));
        if (f.dateFrom) params.set('date_from', f.dateFrom);
        if (f.dateTo) params.set('date_to', f.dateTo);

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

      /* Picking an admin-closed status under the upcoming scope is
         structurally near-empty (their sessions are in the past) —
         auto-widen, same rule as the Trajecten surface. */
      onFilterChange() {
        const s = this.filters.status;
        if (this.scope === 'upcoming' && (s === 'completed' || s === 'archived')) {
          this.scope = 'all';
        }
        this.load(1);
      },
      onSearchChange() { this.load(1); },
      goPage(p) { if (p >= 1 && p <= this.pageCount && p !== this.page) this.load(p); },
      onPerPageChange() { this.load(1); },

      setViewMode(mode) {
        if (mode !== this.viewMode && (mode === 'agenda' || mode === 'list')) {
          this.viewMode = mode;
          this.load(1);
        }
      },
      toggleScope() {
        this.scope = (this.scope === 'upcoming' ? 'all' : 'upcoming');
        this.load(1);
      },

      get hasFilters() {
        const f = this.filters;
        return !!(f.q || f.status || f.tag || f.dateFrom || f.dateTo);
      },
      clearAllFilters() {
        this.filters = { q: '', status: '', tag: '', dateFrom: '', dateTo: '' };
        // clear() fires onChange([]) → guarded to both-empty; avoid a double
        // load by clearing the picker first, then loading once below.
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

      statusLabel(value) { return editionStatusLabel(value); },
      badgeClass(value) { return editionBadgeClass(value); },

      /* Date cell text: the server's Dutch dateLabel (INV-7), falling back to
         the raw agenda `date` for an unparseable value. '' = dateless (list
         view only) — the template renders its own 'Geen datum' state. */
      dateText(r) {
        if (!r) return '';
        return r.dateLabel || r.date || '';
      },

      /* Session time-range (agenda rows only — list rows carry no times). */
      timeLabel(r) {
        if (!r) return '';
        if (r.startTime && r.endTime) return `${r.startTime}–${r.endTime}`;
        return r.startTime || r.endTime || '';
      },

      /* x-for :key — agenda rows are session rows, list rows are edition
         rows; the prefixes keep the two key spaces disjoint across a view
         switch (a bare number could collide and confuse Alpine's keyed
         reconciliation). */
      rowKey(r) {
        return r && r.sessionId ? 's' + r.sessionId : 'e' + ((r && r.id) || 0);
      },

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

      /* "Rooster" action → open the cohort-lens slideover (cluster G) for this
         edition. The cohort lens is a SIBLING x-data scope on the shell, so we
         relay through a window event it listens for (`ws-cohort-open`) rather
         than calling into it directly. stopPropagation so the row's openRow()
         navigation doesn't also fire. */
      openCohort(r, ev) {
        if (ev && typeof ev.stopPropagation === 'function') ev.stopPropagation();
        const id = r && Number(r.id);
        if (!id) return;
        window.dispatchEvent(new CustomEvent('ws-cohort-open', { detail: { editionId: id } }));
      },

      emptyTitle() {
        if (this.filters.q) return `Geen edities voor "${this.filters.q}"`;
        return this.viewMode === 'list' ? 'Geen edities gevonden' : 'Geen sessies gevonden';
      },
    };
  }

  return {
    edities,
    editionStatusLabel,
    editionBadgeClass,
  };
});
