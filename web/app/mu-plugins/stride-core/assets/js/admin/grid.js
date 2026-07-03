/* ==========================================================================
   Stride Admin Workspace — Inschrijvingen grid (Cluster C)
   --------------------------------------------------------------------------
   The most-used surface. This factory OWNS loading ALL of its own data in
   init(); there is NO shared loader and NO client-side corpus. Every filter /
   page / sort / group-by change re-fetches ONE server page from
   GET /admin/registrations — the server does paging, filtering, sorting,
   grouping, and returns the funnel counts (statusCounts). We never slice a
   4k-row array client-side (plan §5 hard rule).

   This is a flat→nested REBIND of docs/mockups/admin-workspace/inschrijvingen.
   html: the mockup's data.js rows are flat scalars; the real endpoint returns
   nested objects. The mock data.js is DELETED — the markup binds to the REAL
   nested keys (r.user.name, r.status.value/label, r.offerteStatus, …). There
   is NO mock-shape adapter (a translation layer would drift).

   Backend FROZEN — consumed exactly as Phase-1 emits it:
     GET /admin/registrations   → { items, total, page, perPage, totalPages, statusCounts }
        flat item:  { id, user{id,name,email}, edition{id,title},
                      status{value,label}, offerteStatus:<label>, attendancePct,
                      company{id,name}, trajectory{id,title}|null }
        grouped item (group_by present): { group_value, count, pct_afgerond,
                      avg_attendance_pct, offerte_verdeling:{<label>:count} }
     GET /admin/editions/options?scope=all   → { items:[{id,title}] }  (edition filter source)
     POST ntdst/v1/action                     → bulk action registry (envelope {success,data})

   INV-5: every x-html binds a CONSTANT icon name via icon('<literal>'); never a
   data field. Status badges / offerte labels render via x-text (auto-escaped).
   INV-7: status.value/label + offerteStatus render AS RECEIVED — never
   re-derived client-side (no "is this past/terminal" recomputation). offerteClass
   maps the AS-RECEIVED label to a CSS color key only (presentation), it does NOT
   re-derive the status.
   company.id and company.name are INDEPENDENT (company_id FK vs billing_company
   name) — rendered side by side, never merged.

   The pure mappers are exported (UMD tail) so the Tier-A unit test can import
   them without a browser; the browser path attaches the factory to window.
   ========================================================================== */
(function (root, factory) {
  'use strict';
  const api = factory();
  if (typeof module !== 'undefined' && module.exports) {
    module.exports = api; // Node / Playwright unit test
  }
  if (typeof root !== 'undefined') {
    root.grid = api.grid;
    root.WS = root.WS || {};
    root.WS.queueToParams = api.queueToParams;
    root.WS.offerteClass = api.offerteClass;
    root.WS.gridFilterPayload = api.gridFilterPayload;
    root.WS.avatarColor = root.WS.avatarColor || api.avatarColor;
    root.WS.initials = root.WS.initials || api.initials;
  }
})(typeof window !== 'undefined' ? window : this, function () {
  'use strict';

  /* ---- avatar helpers (pure — shared shape with vandaag.js) ---- */
  const AVA = ['#3b82f6', '#0ea5e9', '#6366f1', '#8b5cf6', '#ec4899', '#f43f5e', '#f97316', '#eab308', '#22c55e', '#14b8a6', '#06b6d4'];
  function avatarColor(name) {
    const s = String(name || '');
    let h = 0;
    for (let i = 0; i < s.length; i++) h = (h * 31 + s.charCodeAt(i)) >>> 0;
    return AVA[h % AVA.length];
  }
  function initials(name) {
    const p = String(name || '').trim().split(/\s+/);
    return ((p[0]?.[0] || '') + (p[p.length - 1]?.[0] || '')).toUpperCase();
  }

  /* ---- the worklist pipeline (status order for the funnel chips) ----
     The funnel renders the lifecycle order; `cancelled` is the exit (dead-end),
     rendered separated. Labels/counts come from the server (statusCounts +
     status.label AS RECEIVED) — these are ONLY the order + the chip microcopy
     (a presentational, closed-enum table keyed by status VALUE). */
  const STATUS_PIPELINE = ['interest', 'waitlist', 'pending', 'confirmed', 'completed'];
  const STATUS_EXIT = 'cancelled';
  const STATUS_META = {
    interest:  { label: 'Interesse',     pipe: 'Interesse getoond',     cls: 'interest',  hint: 'Heeft interesse getoond maar is nog niet ingeschreven.' },
    waitlist:  { label: 'Wachtlijst',    pipe: 'Op wachtlijst',         cls: 'waitlist',  hint: 'Aangemeld maar nog geen plaats.' },
    pending:   { label: 'In afwachting', pipe: 'Wacht op goedkeuring',  cls: 'pending',   hint: 'Wacht op gebruiker of op goedkeuring.' },
    confirmed: { label: 'Bevestigd',     pipe: 'Bevestigd',             cls: 'confirmed', hint: 'Inschrijving goedgekeurd en bevestigd.' },
    completed: { label: 'Afgerond',      pipe: 'Afgerond',              cls: 'completed', hint: 'De cursus is afgerond.' },
    cancelled: { label: 'Geannuleerd',   pipe: 'Geannuleerd',           cls: 'cancelled', hint: 'Uitgestapt — een eindstatus buiten de funnel.' },
  };

  /* ---- queue → endpoint params (PURE, Tier-A) ----------------------------
     The Vandaag deep-link sends a queue KEY; the endpoint does NOT accept a
     `queue` param, so this translates each key to the closest REAL filter.
     For queues that aren't a clean single status (offerte/nocert/oldinterest)
     we map to the status that best approximates and do NOT invent a backend
     param. An unknown key returns {} — no fabricated param, no leak. */
  const QUEUE_STATUS = {
    pending:     'pending',
    waitlist:    'waitlist',
    offerte:     'confirmed',   // offerte-opvolging ≈ confirmed (no offerte param on the endpoint)
    nocert:      'completed',   // afgerond zonder certificaat ≈ completed
    oldinterest: 'interest',    // oude interesse ≈ interest
    interest_to_invite: 'interest', // interesse — editie nu gepland ≈ interest (view the list; mail send deferred)
  };
  function queueToParams(queueKey) {
    const status = QUEUE_STATUS[queueKey];
    return status ? { status } : {};
  }

  /* ---- offerte LABEL → CSS modifier key (PURE, Tier-A) -------------------
     INV-7: the label is rendered AS RECEIVED elsewhere; this maps it to a
     closed-enum color class ONLY. Unknown/empty → 'none' (never an arbitrary
     class). Mirrors QuoteStatus::label() exactly. */
  const OFFERTE_CLASS = {
    'Geen offerte':   'none',
    'In behandeling': 'draft',
    'Verzonden':      'sent',
    'Verwerkt':       'exported',
  };
  function offerteClass(label) {
    return OFFERTE_CLASS[String(label || '')] || 'none';
  }

  /* ---- select-all blast-radius payload (PURE, Tier-A) --------------------
     The structured filter subset the server expands a cross-page select-all
     against — mirrors what loadGrid sends MINUS page/per_page/sort/group_by
     (the expansion ignores paging). Empty filters are omitted so an empty
     payload means "the whole active set", not a malformed query. Numeric
     filters are coerced (a <select> yields strings). Lifted from the proven
     god-component gridFilterPayload(). */
  function gridFilterPayload(filters) {
    const f = filters || {};
    const out = {};
    if (f.status) out.status = f.status;
    if (f.edition_id) out.edition_id = Number(f.edition_id);
    if (f.company_id) out.company_id = Number(f.company_id);
    if (f.trajectory_id) out.trajectory_id = Number(f.trajectory_id);
    if (f.q) out.q = f.q;
    return out;
  }

  /* ---- URL round-trip (PURE, Tier-A) ------------------------------------
     The grid syncs its full view state into the browser URL (via replaceState
     in load()), so a filtered/sorted/paged view is bookmarkable, reload-safe
     and shareable. shell.js already owns ?view=/?queue=/?user=/?reg=; these two
     mappers are the grid's HALF of the same URL and must coexist with those.

     GROUP_BY_ALLOWLIST is the SERVER-allow-listed group dimensions (mirrors the
     endpoint's GROUP_BY_ALLOWLIST). A URL carrying a bogus ?group_by= must never
     become an active grouping — it would send an un-allow-listed group_by to the
     server and render a broken grouped view. Same discipline as queueToParams'
     denial branch: never fabricate a param the server would silently mishandle.

     DEFAULT_PER_PAGE mirrors the grid's `perPage: 25` initial state — it is the
     omit threshold (per_page is only URL-written when it differs) AND the parse
     fallback (a malformed ?per_page= coerces back to it, never NaN). */
  const GROUP_BY_ALLOWLIST = ['edition_id', 'status', 'company_id'];
  const DEFAULT_PER_PAGE = 25;

  /* state → the URL-param subset. Emits ONLY non-default state so a pristine
     grid writes nothing (a clean URL); ids are stringified for URLSearchParams.
     Symmetric with load()'s fetch params, minus the always-present page/per_page
     (those are emitted only when they differ from the default). */
  function gridStateToParams(state) {
    const s = state || {};
    const f = s.filters || {};
    const out = {};
    if (f.status) out.status = f.status;
    if (f.edition_id) out.edition_id = String(f.edition_id);
    if (f.company_id) out.company_id = String(f.company_id);
    if (f.trajectory_id) out.trajectory_id = String(f.trajectory_id);
    if (f.q) out.q = f.q;
    if (s.sortKey) {
      out.sort = s.sortKey;
      out.order = s.sortDir === 'desc' ? 'desc' : 'asc';
    }
    if (s.groupBy && GROUP_BY_ALLOWLIST.includes(s.groupBy)) out.group_by = s.groupBy;
    // The grid's pagination URL key is `p`, NOT `page` — `page` is WordPress
    // admin's routing param (?page=stride-dashboard). Writing/deleting `page`
    // here would destroy WP's routing and blank the whole dashboard on reload.
    if (s.page && Number(s.page) > 1) out.p = String(s.page);
    if (s.perPage && Number(s.perPage) !== DEFAULT_PER_PAGE) out.per_page = String(s.perPage);
    return out;
  }

  /* URLSearchParams → a grid-state patch. Coerces numerics and, on any malformed
     or un-allow-listed value, falls back to the default (never NaN, never a bogus
     group_by/order). Returns the SAME shape the grid seeds from, so init() can
     Object.assign the meaningful subset. */
  function gridStateFromParams(params) {
    const p = params || new URLSearchParams('');
    const toInt = (v, dflt) => {
      const n = parseInt(v, 10);
      return Number.isFinite(n) && n > 0 ? n : dflt;
    };
    const gb = p.get('group_by');
    const order = p.get('order');
    return {
      filters: {
        status: p.get('status') || '',
        edition_id: toInt(p.get('edition_id'), 0),
        company_id: toInt(p.get('company_id'), 0),
        trajectory_id: toInt(p.get('trajectory_id'), 0),
        q: p.get('q') || '',
      },
      sortKey: p.get('sort') || '',
      sortDir: order === 'desc' ? 'desc' : 'asc',
      groupBy: GROUP_BY_ALLOWLIST.includes(gb) ? gb : '',
      page: toInt(p.get('p'), 1),   // `p` — NOT `page` (WP admin routing param)
      perPage: toInt(p.get('per_page'), DEFAULT_PER_PAGE),
    };
  }

  /* ---- accordion per-group mapper (PURE, Tier-A) ------------------------
     Task 6: the grouped endpoint item now carries its own composed child rows
     ({ group_value, count, rows[≤8], row_total, pct_afgerond, avg_attendance_pct,
     offerte_verdeling }). This maps ONE server group into the shape the accordion
     template iterates (groupsView). It is PURE — it deliberately does NOT compute
     the display label (that depends on `this.editionOptions`); the template calls
     the instance-bound groupLabel(g) instead, which reads .group_value, so we pass
     group_value through untouched.

     Two load-bearing jobs:
       key     — a STABLE String for collapsed[key] / toggleGroup(key). A null
                 group_value (the "Geen editie / Geen organisatie" bucket) MUST
                 coerce to a stable string ('' via String(v ?? '')) — never
                 undefined, or collapsed[undefined] would alias every null-value
                 group into a single toggle.
       hasMore — true exactly when the true row_total exceeds the number of rows
                 the server actually shipped (capped at 8). Drives "Toon alle N". */
  function groupRowsFrom(group) {
    const g = group || {};
    const rows = g.rows || [];
    const rowTotal = g.row_total || 0;
    return {
      key: String(g.group_value ?? ''),
      group_value: g.group_value,      // passthrough so groupLabel(g) still resolves
      rows,
      count: g.count,
      rowTotal,
      hasMore: rowTotal > rows.length,
      pct_afgerond: g.pct_afgerond,
      avg_attendance_pct: g.avg_attendance_pct,
      offerte_verdeling: g.offerte_verdeling,
    };
  }

  /* ---- the §2.1 transition mirror for the state-aware bulk bar -----------
     Lifted verbatim from the god-component STRIDE_SMART_ACTIONS. The bulk bar
     offers the SAFE INTERSECTION of actions across the selected rows' statuses.
     Lifecycle actions are validated against StrideConfig.transitions at init
     (drift warning) — the JS constant never silently diverges from the server
     map. icon keys map into the shell ICONS set (INV-5 constants). */
  const SMART_ACTIONS = [
    { id: 'stride_bulk_approve',             label: 'Goedkeuren',               icon: 'checkCircle', states: ['pending'] },
    { id: 'stride_bulk_promote_waitlist',    label: 'Promoveer van wachtlijst', icon: 'arrowUp',     states: ['waitlist'] },
    { id: 'stride_bulk_quote_sent',          label: 'Offerte verzonden',        icon: 'send',        states: ['confirmed'] },
    { id: 'stride_bulk_quote_exported',      label: 'Offerte verwerkt',         icon: 'checkCircle', states: ['confirmed'] },
    { id: 'stride_bulk_approve_post_course', label: 'Goedkeuren na cursus',     icon: 'award',       states: ['confirmed', 'completed'] },
    { id: 'stride_bulk_message',             label: 'Bericht sturen',           icon: 'mail',        states: ['confirmed', 'completed', 'interest', 'pending', 'waitlist'] },
    { id: 'stride_bulk_generate_doc',        label: 'Document genereren',       icon: 'fileText',    states: ['completed'] },
    { id: 'stride_bulk_cancel',              label: 'Annuleren',                icon: 'xCircle',     states: ['pending', 'interest', 'confirmed', 'waitlist'], danger: true },
  ];
  const LIFECYCLE_TARGET = {
    stride_bulk_approve: 'confirmed',
    stride_bulk_promote_waitlist: 'confirmed',
    stride_bulk_cancel: 'cancelled',
  };
  function actionsForStates(states) {
    const uniq = [...new Set(states)];
    if (uniq.length === 0) return [];
    return SMART_ACTIONS.filter((a) => uniq.every((s) => a.states.includes(s)));
  }
  function validateTransitionDrift(transitions) {
    if (!transitions || typeof transitions !== 'object') return;
    const canReach = (from, target) => Array.isArray(transitions[from]) && transitions[from].includes(target);
    SMART_ACTIONS.forEach((a) => {
      const target = LIFECYCLE_TARGET[a.id];
      if (!target) return;
      const invalid = a.states.filter((s) => !canReach(s, target));
      if (invalid.length) {
        // eslint-disable-next-line no-console
        console.warn(`[grid] bulk action "${a.id}" offered for state(s) [${invalid}] the server map does not permit → "${target}" (CR-5).`);
      }
    });
  }

  /* ---- the Alpine factory ------------------------------------------------ */
  function grid() {
    return {
      statusMeta: STATUS_META,
      statusPipeline: STATUS_PIPELINE,
      statusExit: STATUS_EXIT,

      /* server-driven page state (NOT a client corpus) */
      rows: [],
      groups: [],
      statusCounts: {},
      total: 0,
      page: 1,
      perPage: 25,
      pageCount: 1,

      /* filter / view state — every change re-fetches */
      filters: { status: '', edition_id: 0, company_id: 0, trajectory_id: 0, q: '' },
      sortKey: '',
      sortDir: 'asc',
      groupBy: '',                 // '' | 'edition_id' | 'status' | 'company_id' (real GROUP_BY_ALLOWLIST)
      collapsed: {},
      queue: '',                   // active worklist context (from ?queue=)

      /* filter sources (edition options from the server; companies derived from
         the loaded page since there is no company-options endpoint) */
      editionOptions: [],

      /* per-surface load state — a failed load shows its own banner, never blanks */
      loading: false,
      error: '',

      /* selection + bulk */
      selected: {},                // id -> true
      selectAllFilter: false,      // armed cross-page select-all
      busyAction: null,
      overflowOpen: false,
      showResult: false,
      result: null,
      toasts: [],
      toastSeq: 0,

      /* ===================================================================== */
      init() {
        // Validate the bulk catalog against the server transition map (drift warn).
        validateTransitionDrift((window.StrideConfig || {}).transitions);

        // Restore the full grid view state from a bookmarked / reloaded URL
        // (filters/search/sort/page/per_page/group_by). Runs BEFORE the queue
        // deep-link so an explicit ?queue= from Vandaag still wins on status.
        this.hydrateStateFromUrl();

        // Cold-landing deep-link: ?queue=/?status= pre-filter on first load.
        this.applyQueueDeepLink();

        // I-1: load the grid the FIRST time inschrijvingen becomes active (lazy),
        // not on mount. Deep-links from Vandaag land with view already =
        // inschrijvingen, so the ?queue=/?status= filters set above are honored on
        // the first activation load. The expensive grid query never fires for a
        // user who never opens this surface.
        window.WS.lazyLoad(this, 'inschrijvingen', () => {
          this.loadEditionOptions();
          this.load(1);
        });

        // The lazyLoad latch fires its callback ONCE. But a queue deep-link from
        // Vandaag can arrive on EVERY re-activation of this surface (?queue=
        // rewritten, view switched back to inschrijvingen). Re-read the deep-link
        // and reload on each re-activation so the 2nd+ queue click actually
        // filters — without re-running the one-shot loadEditionOptions().
        window.addEventListener('ws-view-changed', (e) => {
          if (!e || !e.detail || e.detail.view !== 'inschrijvingen') return;
          if (this.applyQueueDeepLink()) {
            this.load(1);
          }
        });
      },

      /* Read ?queue=/?status= and apply them to the active filter. Returns true
         when the resulting queue/status actually CHANGED, so the caller knows
         whether a reload is warranted (a repeat activation with no new deep-link
         must not stomp the user's in-grid filtering). The shell's extended
         switchView wrote ?queue= (plan §5 / shell contract). */
      applyQueueDeepLink() {
        const p = new URLSearchParams(window.location.search);
        const q = p.get('queue');
        if (q) {
          const qp = queueToParams(q);
          if (qp.status && (this.queue !== q || this.filters.status !== qp.status)) {
            this.queue = q;
            this.filters.status = qp.status;
            return true;
          }
          return false;
        }
        const directStatus = p.get('status');
        if (!this.filters.status && directStatus && STATUS_META[directStatus]) {
          this.filters.status = directStatus;
          return true;
        }
        return false;
      },

      /* Restore the grid's view state from the URL on cold init — the read half
         of syncStateToUrl(). Parses the URL through gridStateFromParams (which
         coerces + denies malformed values), then copies the restored subset onto
         the live state so the first load(1) renders the bookmarked view. Leaves
         `queue` alone — applyQueueDeepLink (run right after) owns that, and an
         explicit ?queue= must still win over a restored ?status=. */
      hydrateStateFromUrl() {
        const s = gridStateFromParams(new URLSearchParams(window.location.search));
        this.filters = s.filters;
        this.sortKey = s.sortKey;
        this.sortDir = s.sortDir;
        this.groupBy = s.groupBy;
        this.page = s.page;
        this.perPage = s.perPage;
      },

      /* Fetch ONE server page (or grouped aggregates). The single place a
         filter/sort/page/group change funnels through. Owns loading/error. */
      async load(page) {
        if (page != null) this.page = page;
        this.loading = true;
        // Clear the error at the TOP of the load so a successful reload recovers
        // cleanly (learned from cluster B — a stale error must not survive a
        // now-successful load).
        this.error = '';

        const params = new URLSearchParams();
        params.set('page', String(this.page));
        params.set('per_page', String(this.perPage));
        const f = this.filters;
        if (f.status) params.set('status', f.status);
        if (f.edition_id) params.set('edition_id', String(f.edition_id));
        if (f.company_id) params.set('company_id', String(f.company_id));
        if (f.trajectory_id) params.set('trajectory_id', String(f.trajectory_id));
        if (f.q) params.set('q', f.q);
        if (this.sortKey) {
          params.set('sort', this.sortKey);
          params.set('order', this.sortDir);
        }
        if (this.groupBy) params.set('group_by', this.groupBy);

        // Sync the grid's view state into the browser URL so a filtered/sorted/
        // paged view is bookmarkable + reload-safe. Uses replaceState (same idiom
        // as shell.js) and PRESERVES shell's own params — never clobbers them.
        this.syncStateToUrl();

        try {
          const data = await this.api(`/admin/registrations?${params.toString()}`);
          if (this.groupBy) {
            this.groups = data.items || [];
            this.rows = [];
          } else {
            this.rows = data.items || [];
            this.groups = [];
          }
          this.statusCounts = data.statusCounts || {};
          this.total = data.total || 0;
          this.page = data.page || 1;
          this.perPage = data.perPage || this.perPage;
          this.pageCount = data.totalPages || 1;
        } catch (e) {
          this.error = (e && e.message) ? e.message : 'Kon de inschrijvingen niet laden.';
          this.rows = [];
          this.groups = [];
          this.total = 0;
        } finally {
          this.loading = false;
        }
      },

      /* Grid half of the admin URL. Writes the grid's non-default view state
         (filters/search/sort/page/per_page/group_by via gridStateToParams) into
         the query string with replaceState — matching shell.js's idiom — while
         PRESERVING every other param already present (WP's ?page=stride-dashboard,
         and shell's ?view=/?queue=/?user=/?reg=). The grid owns exactly its own
         keys: it deletes only those it manages, then re-sets the active subset,
         so a cleared filter drops its key instead of lingering. Guarded for the
         Node/test context where window.history is absent. */
      syncStateToUrl() {
        if (typeof window === 'undefined' || !window.history || !window.history.replaceState) return;
        const url = new URL(window.location.href);
        // Clear only the keys THIS grid owns (leave shell/WP params untouched).
        // Pagination is `p`, NEVER `page` — `page` is WP admin's routing param
        // (?page=stride-dashboard); deleting it here blanks the dashboard on reload.
        ['status', 'edition_id', 'company_id', 'trajectory_id', 'q', 'sort', 'order', 'group_by', 'p', 'per_page']
          .forEach((k) => url.searchParams.delete(k));
        const gridParams = gridStateToParams(this);
        Object.keys(gridParams).forEach((k) => url.searchParams.set(k, gridParams[k]));
        window.history.replaceState(null, '', url.toString());
      },

      async loadEditionOptions() {
        try {
          const data = await this.api('/admin/editions/options?scope=all&per_page=100');
          this.editionOptions = data.items || [];
        } catch (e) {
          this.editionOptions = [];
        }
      },

      /* re-fetch helpers bound by the markup */
      reload() { this.load(1); },
      onFilterChange() { this.clearSelection(); this.load(1); },
      onSearchChange() { this.load(1); },
      onGroupChange() { this.collapsed = {}; this.clearSelection(); this.load(1); },
      goPage(p) { if (p >= 1 && p <= this.pageCount && p !== this.page) this.load(p); },
      onPerPageChange() { this.load(1); },

      /* sort: toggle dir on the same key, else asc on the new key, then reload */
      sort(key) {
        if (this.sortKey === key) this.sortDir = this.sortDir === 'asc' ? 'desc' : 'asc';
        else { this.sortKey = key; this.sortDir = 'asc'; }
        this.load(1);
      },

      /* ===== funnel chips (counts from server statusCounts, AS RECEIVED) ===== */
      statusCount(status) { return Number(this.statusCounts[status]) || 0; },
      setStatus(s) {
        this.filters.status = (this.filters.status === s) ? '' : s;
        this.onFilterChange();
      },

      /* ===== filter chips ===== */
      get hasFilters() {
        const f = this.filters;
        return !!(f.status || f.edition_id || f.company_id || f.trajectory_id || f.q);
      },
      get activeChips() {
        const out = [];
        const f = this.filters;
        if (f.status && STATUS_META[f.status]) out.push({ k: 'status', label: 'Status: ' + STATUS_META[f.status].label });
        if (f.edition_id) {
          const ed = this.editionOptions.find((e) => String(e.id) === String(f.edition_id));
          out.push({ k: 'edition_id', label: 'Editie: ' + (ed ? ed.title : ('#' + f.edition_id)) });
        }
        if (f.company_id) out.push({ k: 'company_id', label: 'Organisatie #' + f.company_id });
        if (f.q) out.push({ k: 'q', label: '"' + f.q + '"' });
        return out;
      },
      removeChip(k) {
        if (k === 'edition_id' || k === 'company_id' || k === 'trajectory_id') this.filters[k] = 0;
        else this.filters[k] = '';
        this.onFilterChange();
      },
      clearAllFilters() {
        this.filters = { status: '', edition_id: 0, company_id: 0, trajectory_id: 0, q: '' };
        this.queue = '';
        this.onFilterChange();
      },

      /* ===== pagination model (compact 1 … cur-1 cur cur+1 … last) ===== */
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

      /* ===== grouping rendering ===== */
      get groupKindLabel() {
        return { edition_id: 'Editie', status: 'Status', company_id: 'Organisatie' }[this.groupBy] || '';
      },
      groupLabel(g) {
        const v = g.group_value;
        if (this.groupBy === 'status') return (STATUS_META[v] && STATUS_META[v].label) || v || '—';
        if (this.groupBy === 'edition_id') {
          const ed = this.editionOptions.find((e) => String(e.id) === String(v));
          return ed ? ed.title : (v ? ('Editie #' + v) : 'Geen editie');
        }
        if (this.groupBy === 'company_id') return v ? ('Organisatie #' + v) : 'Geen organisatie';
        return v || '—';
      },
      toggleGroup(key) { this.collapsed[key] = !this.collapsed[key]; },

      /* The accordion iterates this: each server group mapped through the pure
         groupRowsFrom (stable key + hasMore + child rows). groupRowsFrom passes
         group_value through, so the template still calls groupLabel(g) for the
         instance-dependent display label. */
      get groupsView() { return this.groups.map(groupRowsFrom); },

      /* "Toon alle N" — drop the grouping and re-fetch the FULL flat, paginated
         set for this one group, by pinning the group's dimension as a filter.
         Reuses the server-paged flat grid (NO client-side corpus append). The raw
         group_value (g.group_value / g.key) is the filter value. */
      showAllInGroup(g) {
        const raw = g.group_value;
        if (this.groupBy === 'status') this.filters.status = raw || '';
        else if (this.groupBy === 'edition_id') this.filters.edition_id = Number(raw) || 0;
        else if (this.groupBy === 'company_id') this.filters.company_id = Number(raw) || 0;
        this.groupBy = '';
        this.collapsed = {};      // match onGroupChange()'s collapse reset
        this.clearSelection();
        this.load(1);
      },

      distSummary(verdeling) {
        if (!verdeling) return 'geen offertes';
        const parts = Object.entries(verdeling).filter(([, n]) => n > 0).map(([label, n]) => `${n} ${String(label).toLowerCase()}`);
        return parts.length ? parts.join(' · ') : 'geen offertes';
      },

      /* ===== selection ===== */
      get selectedIds() { return Object.keys(this.selected).filter((id) => this.selected[id]).map(Number); },
      get selectedCount() { return this.selectAllFilter ? this.total : this.selectedIds.length; },
      get selectedRows() { return this.rows.filter((r) => this.selected[r.id]); },
      isSelected(id) { return !!this.selected[id]; },
      toggle(id) { this.selected[id] = !this.selected[id]; if (!this.selected[id]) this.selectAllFilter = false; },
      get pageAllSelected() {
        const ids = this.rows.map((r) => r.id);
        return ids.length > 0 && ids.every((id) => this.selected[id]);
      },
      get pageSomeSelected() {
        const ids = this.rows.map((r) => r.id);
        return ids.some((id) => this.selected[id]) && !this.pageAllSelected;
      },
      togglePage() {
        const target = !this.pageAllSelected;
        this.rows.forEach((r) => { this.selected[r.id] = target; });
        if (!target) this.selectAllFilter = false;
      },
      /* arm the cross-page select-all: the bulk action carries the FILTER, the
         server expands the blast radius over the whole filtered set. */
      selectAllFiltered() {
        this.selectAllFilter = true;
        this.rows.forEach((r) => { this.selected[r.id] = true; });
        this.toast('mixed', String(this.total), `inschrijvingen geselecteerd over alle pagina's. De bulkactie draagt het filter, niet ${this.total} rijen.`);
      },
      clearSelection() { this.selected = {}; this.selectAllFilter = false; },

      /* ===== state-aware bulk bar (intersection of selected statuses) ===== */
      get canManage() { return !!(window.StrideConfig || {}).canManage; },
      get selectedStates() {
        // Across pages the armed filter may have a single status filter; on a
        // page we read the loaded rows' statuses.
        if (this.selectAllFilter && this.filters.status) return [this.filters.status];
        return [...new Set(this.selectedRows.map((r) => r.status.value))];
      },
      get bulkActions() { return actionsForStates(this.selectedStates); },
      get topActions() { return this.bulkActions.slice(0, 3); },
      get overflowActions() { return this.bulkActions.slice(3); },
      get mixedHint() { return this.selectedCount > 0 && this.bulkActions.length === 0; },
      statesSummary() {
        return this.selectedStates.map((s) => (STATUS_META[s] ? STATUS_META[s].label : s)).join(', ');
      },

      /* POST to the ntdst/v1/action registry (envelope {success,data}). Distinct
         from the shell api() (stride/v1) — the registry lives at ntdst/v1/action.
         Carries the per-action nonce armed via StrideConfig.bulkNonces. Lifted
         from the proven god-component bulkApi(). */
      async bulkApi(action, payload) {
        const cfg = window.StrideConfig || {};
        const base = (cfg.apiUrl || '').replace(/\/stride\/v1$/, '/ntdst/v1');
        const nonce = (cfg.bulkNonces || {})[action] || '';
        const response = await fetch(`${base}/action`, {
          method: 'POST',
          headers: { 'X-WP-Nonce': cfg.nonce, 'Content-Type': 'application/json' },
          body: JSON.stringify({ ...payload, action, nonce }),
        });
        const json = await response.json().catch(() => ({}));
        if (!json || json.success !== true) {
          throw new Error((json && json.data && json.data.message) || 'Bulkactie mislukt.');
        }
        return json.data;
      },

      async runBulk(actionId) {
        if (!this.canManage) return;             // AF-2 denied edge — view-only sees no bulk bar
        const action = SMART_ACTIONS.find((a) => a.id === actionId);
        if (!action || this.busyAction) return;

        const armed = this.selectAllFilter;
        const ids = this.selectedIds;
        const count = armed ? this.total : ids.length;
        if (count === 0) return;

        // Honest blast-radius confirm for the armed cross-page case.
        if (armed) {
          if (!window.confirm(`Deze actie raakt ${count} inschrijving${count === 1 ? '' : 'en'}. Doorgaan?`)) return;
        }

        this.overflowOpen = false;
        this.busyAction = actionId;
        try {
          const payload = armed
            ? { select_all: true, filter: gridFilterPayload(this.filters) }
            : { ids };
          const report = await this.bulkApi(actionId, payload);
          const succeeded = report.succeeded || [];
          const failed = report.failed || [];
          const nameById = {};
          this.rows.forEach((r) => { nameById[r.id] = (r.user && r.user.name) || ('#' + r.id); });
          const failRows = failed.map((f) => ({ ...f, name: nameById[f.id] || ('#' + f.id) }));

          this.result = {
            action: action.label,
            total: report.total ?? ids.length,
            succeeded,
            failed: failRows,
            ok: (report.summary && report.summary.ok) ?? succeeded.length,
            err: (report.summary && report.summary.error) ?? failed.length,
          };

          if (failRows.length === 0) {
            this.toast('ok', action.label, `: ${this.result.ok} geslaagd.`);
            this.clearSelection();
          } else {
            this.showResult = true;  // partial-failure report modal
          }
          await this.load(this.page);
        } catch (e) {
          this.toast('mixed', '', (e && e.message) || 'Bulkactie mislukt.');
        } finally {
          this.busyAction = null;
        }
      },

      closeResult() {
        this.showResult = false;
        // keep only the failed rows selected so the user can retry just those
        const failIds = new Set((this.result && this.result.failed ? this.result.failed : []).map((f) => f.id));
        const next = {};
        failIds.forEach((id) => { next[id] = true; });
        this.selected = next;
        this.selectAllFilter = false;
        this.result = null;
      },

      /* row click → the person's dossier (cluster D reads ?user=) */
      openRow(r) {
        const id = r && r.user && r.user.id;
        if (id) this.switchView('dossier', { user: id });
      },

      /* ===== presentational helpers ===== */
      offerteClass(label) { return offerteClass(label); },
      attClass(v) { return v == null ? '' : v >= 80 ? 'ws-meter__fill--high' : v >= 60 ? 'ws-meter__fill--mid' : 'ws-meter__fill--low'; },
      distColor(label) {
        return { 'Geen offerte': '#cbd5e1', 'In behandeling': '#94a3b8', 'Verzonden': '#2563eb', 'Verwerkt': '#16a34a' }[label] || '#cbd5e1';
      },
      avatarColor(name) { return avatarColor(name); },
      initials(name) { return initials(name); },

      emptyTitle() {
        if (this.queue === 'pending') return 'Geen inschrijvingen wachten op goedkeuring';
        if (this.queue === 'waitlist') return 'Geen wachtlijst met vrije plaatsen';
        if (this.queue === 'offerte') return 'Geen openstaande offerte-opvolging';
        if (this.queue === 'nocert') return 'Niemand afgerond zonder certificaat';
        if (this.queue === 'oldinterest') return 'Geen oude interesse meer';
        if (this.queue === 'interest_to_invite') return 'Geen interesse om uit te nodigen';
        if (this.filters.q) return `Geen resultaten voor "${this.filters.q}"`;
        return 'Geen inschrijvingen gevonden';
      },

      /* INV-5: the toast renders `lead` (emphasized) + `body` via x-text — both
         plain strings, never x-html — so a server error string in `body` can
         never be an HTML sink. `lead` is optional (an empty string hides it). */
      toast(kind, lead, body) {
        const id = ++this.toastSeq;
        this.toasts.push({ id, kind, lead: lead || '', body: body || '' });
        setTimeout(() => { this.toasts = this.toasts.filter((t) => t.id !== id); }, 4200);
      },
    };
  }

  return {
    grid,
    queueToParams,
    offerteClass,
    gridFilterPayload,
    gridStateToParams,
    gridStateFromParams,
    groupRowsFrom,
    actionsForStates,
    avatarColor,
    initials,
  };
});
