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
     The Vandaag deep-link sends a queue KEY and the endpoint accepts it
     directly (?queue= → WorklistQueueResolver id-set server-side) — the grid
     shows EXACTLY the rows the card counted, never a lossy status
     approximation. An unknown key returns {} — no fabricated param, no leak.

     ONE closed-enum table per queue key: the Dutch chip label AND the single
     registration status every row in that queue's id-set carries (each server
     predicate is status-homogeneous — WorklistQueueResolver::queueStatuses,
     pinned by the cross-language contract test). One table, one key-set: a
     queue added to the label half but not the status half broke the armed
     bulk bar silently when these were two parallel tables. `status` is NOT a
     filter approximation (the server pins the real id-set); it only lets an
     ARMED cross-page selection inside a queue offer the right bulk actions
     instead of guessing from the visible page (F-G7). */
  const QUEUE_META = {
    pending:            { label: 'Wacht op goedkeuring',          status: 'pending' },
    waitlist:           { label: 'Wachtlijst — plaatsen vrij',    status: 'waitlist' },
    offerte:            { label: 'Offerte-opvolging',             status: 'confirmed' },
    nocert:             { label: 'Afgerond zonder certificaat',   status: 'completed' },
    oldinterest:        { label: 'Oude interesse',                status: 'interest' },
    interest_to_invite: { label: 'Interesse — editie nu gepland', status: 'interest' },
  };
  function queueToParams(queueKey) {
    return QUEUE_META[queueKey] ? { queue: queueKey } : {};
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
     against — mirrors what load() sends MINUS page/per_page/sort/group_by
     (the expansion ignores paging). Takes the grid STATE (not just .filters):
     the queue pin and the edition scope are part of what the grid SHOWS, so
     they MUST be part of what a select-all expands over — omitting them made
     the server expand an armed queue selection over the whole table
     (blast-radius regression, review 2026-07-14). The server re-resolves
     queue → id-set and the default active scope via the SAME applyScopePins
     the grid read uses. Empty filters are omitted; numeric filters are
     coerced (a <select> yields strings). */
  function gridFilterPayload(state) {
    const s = state || {};
    const f = s.filters || {};
    const out = {};
    if (s.queue && QUEUE_META[s.queue]) out.queue = s.queue;
    if (s.editionScope === 'all') out.edition_scope = 'all';
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
    if (s.queue && QUEUE_META[s.queue]) out.queue = s.queue;
    if (s.editionScope === 'all') out.edition_scope = 'all';
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
      editionScope: p.get('edition_scope') === 'all' ? 'all' : 'active',
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
      group_label: g.group_label || '', // server-resolved header label (edition groups)
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
    // deferred: the handlers are honest server stubs (fail every row with
    // not_available) — render DISABLED with a "volgt binnenkort" tooltip,
    // never a live button whose only outcome is a full-red failure modal
    // (F-G6, decision 2026-07-14).
    { id: 'stride_bulk_message',             label: 'Bericht sturen',           icon: 'mail',        states: ['confirmed', 'completed', 'interest', 'pending', 'waitlist'], deferred: true },
    { id: 'stride_bulk_generate_doc',        label: 'Document genereren',       icon: 'fileText',    states: ['completed'], deferred: true },
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
      // Edition scope: 'active' (default — published editions the admin has
      // not closed) or 'all'. The DEFAULT is announced by a dismissable
      // "Actieve edities" pill (spec §10.4) — the old invisible scope made
      // historical registrations unreachable with zero explanation (F-G2).
      editionScope: 'active',

      /* filter sources. Editions come from the SERVER TYPEAHEAD
         (/admin/editions/options?q= — it was always searchable server-side;
         the grid consumed it once as a flat 100-cap dropdown, so editions
         beyond the first 100 could never be filtered on, F-G10). Trajectories
         are a small flat set (/admin/trajectories/options). Companies stay
         un-listed (a company is a bare int — no name entity exists). */
      editionOptions: [],
      editionQuery: '',
      editionPickerOpen: false,
      editionPickerLabel: '',      // the picked edition's title (chip + input display)
      editionOptsSeq: 0,           // stale-response token for the typeahead fetches
      editionOptsBusy: false,      // an options fetch is in flight (focus-fetch dedupe)
      trajectoryOptions: [],
      trajectoryLabelById: {},

      /* per-surface load state — a failed load shows its own banner, never blanks */
      loading: false,
      error: '',
      loadSeq: 0,                  // monotonic load token (stale-response guard, F-G8)

      /* selection + bulk */
      selected: {},                // id -> true
      selectedStatusById: {},      // id -> status value, stamped at SELECT time (F-G7)
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
          this.loadTrajectoryOptions();
          // No page arg: load(1) would stomp the ?p= restored by
          // hydrateStateFromUrl — a bookmarked page-3 view must land on
          // page 3, not silently reset (and rewrite the URL) to page 1.
          this.load();
        });

        // The lazyLoad latch fires its callback ONCE. But a queue deep-link from
        // Vandaag can arrive on EVERY re-activation of this surface (?queue=
        // rewritten, view switched back to inschrijvingen). Re-read the deep-link
        // and reload on each re-activation so the 2nd+ queue click actually
        // filters — without re-running the one-shot loadEditionOptions().
        window.addEventListener('ws-view-changed', (e) => {
          if (!e || !e.detail || e.detail.view !== 'inschrijvingen') return;
          if (this.applyQueueDeepLink()) {
            // The deep-link re-targeted the view (new/dropped queue or
            // trajectory): a selection — especially an ARMED cross-page
            // select-all — from the previous context must not survive onto
            // the new id-set, or the bulk bar arrives pre-armed over rows
            // the admin never selected. Same discipline as onFilterChange.
            this.clearSelection();
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
        let changed = false;

        const q = p.get('queue');
        if (q) {
          const qp = queueToParams(q);
          if (qp.queue && this.queue !== qp.queue) {
            this.queue = qp.queue;
            // The queue is its own server-side filter — a leftover status chip
            // would double-filter the pinned id-set (each queue is
            // single-status anyway; the funnel reflects it via statusCounts).
            this.filters.status = '';
            changed = true;
          }
        } else {
          // The URL is the deep-link contract BOTH ways: the shell DELETES
          // ?queue= on every switchView, so a re-activation without it must
          // DROP a lingering queue pin. Keeping it silently composed the old
          // queue with the new deep-link (e.g. Trajecten's "Toon
          // inschrijvingen" → queue=pending AND trajectory_id=X → an empty
          // intersection rendered as "Geen resultaten").
          if (this.queue) {
            this.queue = '';
            changed = true;
          }
          const directStatus = p.get('status');
          if (!this.filters.status && directStatus && STATUS_META[directStatus]) {
            this.filters.status = directStatus;
            changed = true;
          }
        }

        // Trajectory deep-link ("Toon inschrijvingen" on a trajectory):
        // ABSORB-only. Unlike ?queue= (deep-link-owned — mirrored from the
        // URL both ways), trajectory_id is ALSO a first-class grid filter
        // (the Traject select), so it behaves like status/edition/q: it
        // survives view round-trips until its chip is cleared, and the shell
        // no longer deletes it on switchView. A NEW deep-link target simply
        // overwrites it (the 2nd+ jump also filters, F-T1/F-G9); composing
        // visibly with a queue chip is the same admin-visible composition as
        // any other filter under a queue.
        const trajectoryId = parseInt(p.get('trajectory_id') || '', 10);
        if (Number.isFinite(trajectoryId) && trajectoryId > 0 && this.filters.trajectory_id !== trajectoryId) {
          this.filters.trajectory_id = trajectoryId;
          changed = true;
        }

        // Edition deep-link (Vandaag meldingen: capacity / editie-start
        // alerts open the grid scoped to that edition). Same absorb-only
        // contract as trajectory_id above: it is also a first-class filter,
        // survives round-trips until its chip is cleared, and a NEW deep-link
        // target simply overwrites it.
        const editionId = parseInt(p.get('edition_id') || '', 10);
        if (Number.isFinite(editionId) && editionId > 0 && this.filters.edition_id !== editionId) {
          this.filters.edition_id = editionId;
          changed = true;
        }

        return changed;
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
        this.editionScope = s.editionScope;
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
        // Race token: rapid filter/search changes can overlap requests; a slow
        // OLDER response resolving last must never overwrite the newer view
        // (the classic "filters don't work" symptom, F-G8).
        const seq = ++this.loadSeq;

        // ONE filter-param source (filterParams) shared by the grid read AND
        // the CSV export — "Exporteer huidige weergave" provably exports the
        // exact predicate this view renders (F-A9).
        const params = this.filterParams();
        params.set('page', String(this.page));
        params.set('per_page', String(this.perPage));
        if (this.groupBy) params.set('group_by', this.groupBy);

        // Sync the grid's view state into the browser URL so a filtered/sorted/
        // paged view is bookmarkable + reload-safe. Uses replaceState (same idiom
        // as shell.js) and PRESERVES shell's own params — never clobbers them.
        this.syncStateToUrl();

        return this.performLoad(params, seq);
      },

      /* The CURRENT VIEW's filter predicate as URLSearchParams — queue, scope,
         filters, sort. THE one builder consumed by load() (which adds paging/
         grouping) and by the export URL, so the two can never drift (F-A9). */
      filterParams() {
        const params = new URLSearchParams();
        const f = this.filters;
        if (this.queue) params.set('queue', this.queue);
        if (this.editionScope === 'all') params.set('edition_scope', 'all');
        if (f.status) params.set('status', f.status);
        if (f.edition_id) params.set('edition_id', String(f.edition_id));
        if (f.company_id) params.set('company_id', String(f.company_id));
        if (f.trajectory_id) params.set('trajectory_id', String(f.trajectory_id));
        if (f.q) params.set('q', f.q);
        if (this.sortKey) {
          params.set('sort', this.sortKey);
          params.set('order', this.sortDir);
        }
        return params;
      },

      /* "Exporteer huidige weergave" (F-A9 — the owner's explicit ask): a
         server-streamed CSV of the EXACT predicate on screen. Navigation (not
         fetch) so the browser handles the download; auth rides the _wpnonce
         query param (the same wp_rest nonce api() sends as a header). */
      exportCurrentView() {
        const cfg = window.StrideConfig || {};
        const params = this.filterParams();
        params.set('_wpnonce', cfg.nonce || '');
        window.location.href = `${cfg.apiUrl || ''}/admin/registrations/export?${params.toString()}`;
      },

      async performLoad(params, seq) {

        try {
          const data = await this.api(`/admin/registrations?${params.toString()}`);
          if (seq !== this.loadSeq) return;   // superseded by a newer load()
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
          // A bookmarked ?edition_id= restores the FILTER but not the picked
          // title (the typeahead options are a capped search result, so the
          // chip degraded to "Editie: #123"). The loaded rows carry the
          // edition title — resolve the label from the first matching row.
          if (this.filters.edition_id && !this.editionPickerLabel) {
            const hit = this.allVisibleRows.find(
              (r) => r.edition && Number(r.edition.id) === Number(this.filters.edition_id) && r.edition.title,
            );
            if (hit) {
              this.editionPickerLabel = hit.edition.title;
              this.editionQuery = hit.edition.title;
            }
          }
        } catch (e) {
          if (seq !== this.loadSeq) return;   // a stale failure must not blank the newer view
          this.error = (e && e.message) ? e.message : 'Kon de inschrijvingen niet laden.';
          this.rows = [];
          this.groups = [];
          this.total = 0;
        } finally {
          if (seq === this.loadSeq) this.loading = false;
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
        // `queue` is grid-owned once the deep-link has arrived: the shell WRITES
        // it on switchView, but clearing the queue chip in-grid must also drop
        // it from the URL or a reload would resurrect the dismissed queue.
        // Pagination is `p`, NEVER `page` — `page` is WP admin's routing param
        // (?page=stride-dashboard); deleting it here blanks the dashboard on reload.
        ['queue', 'edition_scope', 'status', 'edition_id', 'company_id', 'trajectory_id', 'q', 'sort', 'order', 'group_by', 'p', 'per_page']
          .forEach((k) => url.searchParams.delete(k));
        const gridParams = gridStateToParams(this);
        Object.keys(gridParams).forEach((k) => url.searchParams.set(k, gridParams[k]));
        window.history.replaceState(null, '', url.toString());
      },

      /* Edition typeahead: every (debounced) keystroke queries the server-side
         searchable options endpoint — no client-side 100-cap corpus. Same
         stale-response discipline as load(): a slow older response (e.g. the
         empty-query focus fetch) must never overwrite the options for what
         the user has since typed. */
      async loadEditionOptions() {
        const seq = ++this.editionOptsSeq;
        this.editionOptsBusy = true;
        try {
          const q = encodeURIComponent(this.editionQuery || '');
          const data = await this.api(`/admin/editions/options?scope=all&per_page=25&q=${q}`);
          if (seq !== this.editionOptsSeq) return;
          this.editionOptions = data.items || [];
        } catch (e) {
          if (seq !== this.editionOptsSeq) return;
          this.editionOptions = [];
        } finally {
          if (seq === this.editionOptsSeq) this.editionOptsBusy = false;
        }
      },
      openEditionPicker() {
        this.editionPickerOpen = true;
        // Fetch on open only when nothing is loaded AND nothing is in flight —
        // a focus immediately followed by typing otherwise double-fetches
        // (the debounced input handler owns the typed query's fetch).
        if (!this.editionOptions.length && !this.editionOptsBusy) this.loadEditionOptions();
      },
      pickEdition(e) {
        this.filters.edition_id = Number(e.id) || 0;
        this.editionPickerLabel = e.title || '';
        this.editionQuery = e.title || '';
        this.editionPickerOpen = false;
        this.onFilterChange();
      },
      clearEditionPick() {
        this.filters.edition_id = 0;
        this.editionPickerLabel = '';
        this.editionQuery = '';
        this.editionPickerOpen = false;
        this.onFilterChange();
      },

      /* Trajectory filter — small set, one lazy fetch (F-G9: the server join
         existed all along; the UI control and chip did not). */
      async loadTrajectoryOptions() {
        try {
          const data = await this.api('/admin/trajectories/options?scope=all&per_page=100');
          this.trajectoryOptions = data.items || [];
          this.trajectoryLabelById = {};
          this.trajectoryOptions.forEach((t) => { this.trajectoryLabelById[t.id] = t.title; });
        } catch (e) {
          this.trajectoryOptions = [];
        }
      },

      /* re-fetch helpers bound by the markup */
      /* Background/refresh reload keeps the CURRENT page — a ws-refresh
         after a lens mutation must never snap the admin back to page 1
         (their place in the list is work state). */
      reload() { this.load(); },
      onFilterChange() { this.clearSelection(); this.load(1); },
      // Search changes the filtered set — an ARMED cross-page select-all (or
      // a manual selection) must not silently re-target to the new set, same
      // discipline as onFilterChange (the armed payload is built at submit
      // time from live state).
      onSearchChange() { this.clearSelection(); this.load(1); },
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
        // A funnel click is an explicit re-filter — the queue context (and its
        // queue-specific empty state) must not linger under it (F-G14).
        this.queue = '';
        this.onFilterChange();
      },

      /* ===== filter chips ===== */
      get hasFilters() {
        const f = this.filters;
        return !!(this.queue || f.status || f.edition_id || f.company_id || f.trajectory_id || f.q);
      },
      get activeChips() {
        const out = [];
        const f = this.filters;
        if (this.queue && QUEUE_META[this.queue]) out.push({ k: 'queue', label: 'Wachtrij: ' + QUEUE_META[this.queue].label });
        if (f.status && STATUS_META[f.status]) out.push({ k: 'status', label: 'Status: ' + STATUS_META[f.status].label });
        if (f.edition_id) {
          const ed = this.editionOptions.find((e) => String(e.id) === String(f.edition_id));
          out.push({ k: 'edition_id', label: 'Editie: ' + (this.editionPickerLabel || (ed ? ed.title : ('#' + f.edition_id))) });
        }
        if (f.trajectory_id) {
          out.push({ k: 'trajectory_id', label: 'Traject: ' + (this.trajectoryLabelById[f.trajectory_id] || ('#' + f.trajectory_id)) });
        }
        if (f.company_id) out.push({ k: 'company_id', label: 'Organisatie #' + f.company_id });
        if (f.q) out.push({ k: 'q', label: '"' + f.q + '"' });
        return out;
      },
      removeChip(k) {
        if (k === 'queue') this.queue = '';
        else if (k === 'edition_id') { this.filters.edition_id = 0; this.editionPickerLabel = ''; this.editionQuery = ''; }
        else if (k === 'company_id' || k === 'trajectory_id') this.filters[k] = 0;
        else this.filters[k] = '';
        this.onFilterChange();
      },
      clearAllFilters() {
        this.filters = { status: '', edition_id: 0, company_id: 0, trajectory_id: 0, q: '' };
        this.queue = '';
        this.editionScope = 'active';   // back to the announced default
        this.editionPickerLabel = '';
        this.editionQuery = '';
        this.onFilterChange();
      },

      /* ===== edition scope pill (spec §10.4) =====
         The DEFAULT scope is announced, dismissable, and restorable — never
         invisible. Hidden while a queue pin or an explicit edition filter is
         active (both bypass the scope; showing the pill then would lie). */
      get scopePillVisible() { return !this.queue && !this.filters.edition_id; },
      widenScope() { this.editionScope = 'all'; this.onFilterChange(); },
      narrowScope() { this.editionScope = 'active'; this.onFilterChange(); },

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
          // Server-resolved label first (batched over THIS page's groups) —
          // the client options list is a capped typeahead result, so falling
          // back to it degraded unlisted editions to "Editie #123".
          if (g.group_label) return g.group_label;
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
      /* The rows selection/bulk reason over — the FLAT page, or every group's
         embedded child rows in grouped mode. Reading bare `this.rows` here was
         F-G4: grouped mode sets rows=[], so a homogeneous grouped selection
         permanently degraded to "Gemengde statussen — geen gedeelde actie". */
      get allVisibleRows() {
        return this.groupBy
          ? this.groups.flatMap((g) => g.rows || [])
          : this.rows;
      },
      get selectedIds() { return Object.keys(this.selected).filter((id) => this.selected[id]).map(Number); },
      get selectedCount() { return this.selectAllFilter ? this.total : this.selectedIds.length; },
      get selectedRows() { return this.allVisibleRows.filter((r) => this.selected[r.id]); },
      isSelected(id) { return !!this.selected[id]; },
      /* Selection stamps the row's STATUS at select time (selectedStatusById):
         deriving states from visible rows only meant a cross-page selection's
         bulk bar reflected just the current page — an action could be offered
         for (and sent to) off-screen rows in other states (F-G7). */
      stampStatus(id) {
        const row = this.allVisibleRows.find((r) => r.id === Number(id));
        if (row && row.status) this.selectedStatusById[id] = row.status.value;
      },
      toggle(id) {
        this.selected[id] = !this.selected[id];
        if (this.selected[id]) this.stampStatus(id);
        else { delete this.selectedStatusById[id]; this.selectAllFilter = false; }
      },
      get pageAllSelected() {
        const ids = this.allVisibleRows.map((r) => r.id);
        return ids.length > 0 && ids.every((id) => this.selected[id]);
      },
      get pageSomeSelected() {
        const ids = this.allVisibleRows.map((r) => r.id);
        return ids.some((id) => this.selected[id]) && !this.pageAllSelected;
      },
      togglePage() {
        const target = !this.pageAllSelected;
        this.allVisibleRows.forEach((r) => {
          this.selected[r.id] = target;
          if (target) this.stampStatus(r.id);
          else delete this.selectedStatusById[r.id];
        });
        if (!target) this.selectAllFilter = false;
      },
      /* arm the cross-page select-all: the bulk action carries the FILTER
         (including the queue pin + edition scope), the server expands the
         blast radius over the whole filtered set. Arming is only OFFERED in a
         status-homogeneous context (a status filter or a queue pin) — an
         armed selection spanning unknown off-page statuses would let the bulk
         bar offer actions the server then rejects row by row. */
      get canArmSelectAll() {
        return !this.groupBy && !!(this.filters.status || this.queue);
      },
      selectAllFiltered() {
        this.selectAllFilter = true;
        this.allVisibleRows.forEach((r) => { this.selected[r.id] = true; this.stampStatus(r.id); });
        this.toast('mixed', String(this.total), `inschrijvingen geselecteerd over alle pagina's. De bulkactie draagt het filter, niet ${this.total} rijen.`);
      },
      clearSelection() { this.selected = {}; this.selectedStatusById = {}; this.selectAllFilter = false; },

      /* ===== state-aware bulk bar (intersection of selected statuses) ===== */
      get canManage() { return !!(window.StrideConfig || {}).canManage; },
      get selectedStates() {
        // An ARMED selection derives its status from the FILTER it carries
        // (status filter or the queue's single row status) — never from the
        // visible page's stamps, which say nothing about off-page rows the
        // expansion will include (F-G7). Arming is gated to these two
        // contexts (canArmSelectAll); the empty-array fallback is defensive —
        // it offers NO action rather than one that lies about its blast radius.
        if (this.selectAllFilter) {
          if (this.filters.status) return [this.filters.status];
          if (this.queue && QUEUE_META[this.queue]) return [QUEUE_META[this.queue].status];
          return [];
        }
        // Manual selection: statuses stamped at SELECT time.
        const stamped = this.selectedIds
          .map((id) => this.selectedStatusById[id])
          .filter(Boolean);
        return [...new Set(stamped)];
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
            ? { select_all: true, filter: gridFilterPayload(this) }
            : { ids };
          const report = await this.bulkApi(actionId, payload);
          const succeeded = report.succeeded || [];
          const failed = report.failed || [];
          const nameById = {};
          this.allVisibleRows.forEach((r) => { nameById[r.id] = (r.user && r.user.name) || ('#' + r.id); });
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
        this.selectedStatusById = {};
        this.selectAllFilter = false;
        // Re-stamp the statuses of the retry selection from the (re-loaded)
        // visible rows — without stamps selectedStates is empty and the bulk
        // bar offers NO action for the very rows it invites the user to
        // retry. Off-page failed rows stay unstamped (their status is
        // unknown client-side); the bar then honestly narrows to the
        // stamped subset's shared actions.
        failIds.forEach((id) => this.stampStatus(id));
        this.result = null;
      },

      /* row click → the person's dossier (cluster D reads ?user=). A lead
         has NO account and thus no dossier yet — say so instead of a silent
         no-op on a row that looks clickable (F-G14). When the lead's e-mail
         matches an existing account, name it: that IS the admin's next step
         (the row binds to it at enrollment/promotion). */
      openRow(r) {
        const id = r && r.user && r.user.id;
        if (id) {
          this.switchView('dossier', { user: id });
        } else if (r && r.accountMatch) {
          this.toast('mixed', r.accountMatch.name, ': e-mailadres hoort bij dit bestaande account. De lead wordt eraan gekoppeld bij inschrijving of promotie.');
        } else {
          this.toast('mixed', '', 'Lead zonder account — er is nog geen dossier.');
        }
      },

      /* ===== presentational helpers ===== */
      offerteClass(label) { return offerteClass(label); },
      attClass(v) { return v == null ? '' : v >= 80 ? 'ws-meter__fill--high' : v >= 60 ? 'ws-meter__fill--mid' : 'ws-meter__fill--low'; },
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
    QUEUE_META,
  };
});
