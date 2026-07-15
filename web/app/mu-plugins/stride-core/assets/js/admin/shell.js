/* ==========================================================================
   Stride Admin Workspace — shell (the per-surface architecture's spine)
   --------------------------------------------------------------------------
   This is NOT a god-component. It owns ONLY the cross-surface concerns:
     - the active `view` (initialized from StrideConfig.defaultView, URL ?view=),
     - nav switching + URL state (?view=) for bookmarkability,
     - the shared `api()` helper every per-surface factory reuses,
     - the constant ICONS map + icon(name) lookup the rail/topbar render.

   Each surface (Vandaag, Inschrijvingen, Dossier, Trajecten, …) ships its OWN
   small Alpine factory in assets/js/admin/<surface>.js (clusters B–G). Those
   factories own their data-loaders; they do NOT add methods here.

   INV-5: icon() returns SVG built from a CONSTANT, whitelisted icon name keyed
   into the fixed ICONS map below — never from a data field. Markup binds
   x-html only to icon('<literal-name>'). Unknown names yield empty SVG, never
   raw passthrough.
   ========================================================================== */
(function () {
  'use strict';

  /* ---- Inline SVG icon set (Lucide-style, 24x24, stroke=currentColor) ----
     Ported verbatim from the wireframe data.js. CONSTANT map — INV-5. */
  const ICONS = {
    layers:     '<path d="m12.83 2.18a2 2 0 0 0-1.66 0L2.6 6.08a1 1 0 0 0 0 1.83l8.58 3.91a2 2 0 0 0 1.66 0l8.58-3.9a1 1 0 0 0 0-1.83Z"/><path d="m22 17.65-9.17 4.16a2 2 0 0 1-1.66 0L2 17.65"/><path d="m22 12.65-9.17 4.16a2 2 0 0 1-1.66 0L2 12.65"/>',
    grid:       '<rect width="7" height="7" x="3" y="3" rx="1"/><rect width="7" height="7" x="14" y="3" rx="1"/><rect width="7" height="7" x="14" y="14" rx="1"/><rect width="7" height="7" x="3" y="14" rx="1"/>',
    sun:        '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"/>',
    user:       '<path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
    users:      '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
    check:      '<path d="M20 6 9 17l-5-5"/>',
    checkCircle:'<path d="M21.8 10A10 10 0 1 1 17 3.34"/><path d="m9 11 3 3L22 4"/>',
    x:          '<path d="M18 6 6 18M6 6l12 12"/>',
    xCircle:    '<circle cx="12" cy="12" r="10"/><path d="m15 9-6 6M9 9l6 6"/>',
    clock:      '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
    arrowRight: '<path d="M5 12h14M12 5l7 7-7 7"/>',
    arrowUp:    '<path d="m5 12 7-7 7 7M12 19V5"/>',
    chevDown:   '<path d="m6 9 6 6 6-6"/>',
    chevRight:  '<path d="m9 18 6-6-6-6"/>',
    search:     '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
    send:       '<path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/>',
    mail:       '<rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/>',
    building:   '<rect width="16" height="20" x="4" y="2" rx="2"/><path d="M9 22v-4h6v4M9 6h.01M15 6h.01M9 10h.01M15 10h.01M9 14h.01M15 14h.01"/>',
    ticket:     '<path d="M2 9a3 3 0 0 1 0 6v2a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-2a3 3 0 0 1 0-6V7a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2Z"/><path d="M13 5v2M13 17v2M13 11v2"/>',
    award:      '<path d="m15.477 12.89 1.515 8.526a.5.5 0 0 1-.81.47l-3.58-2.687a1 1 0 0 0-1.197 0l-3.586 2.686a.5.5 0 0 1-.81-.469l1.514-8.526"/><circle cx="12" cy="8" r="6"/>',
    history:    '<path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5M12 7v5l4 2"/>',
    more:       '<circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/><circle cx="5" cy="12" r="1"/>',
    filter:     '<path d="M3 6h18M7 12h10M10 18h4"/>',
    alert:      '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4M12 17h.01"/>',
    bell:       '<path d="M10.27 21a1.94 1.94 0 0 0 3.46 0M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/>',
    hourglass:  '<path d="M5 22h14M5 2h14M17 22v-4.17a2 2 0 0 0-.59-1.42L12 12l-4.41 4.41A2 2 0 0 0 7 17.83V22M7 2v4.17a2 2 0 0 0 .59 1.42L12 12l4.41-4.41A2 2 0 0 0 17 6.17V2"/>',
    seat:       '<path d="M19 9V6a2 2 0 0 0-2-2H7a2 2 0 0 0-2 2v3M4 11v5a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-5a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2ZM6 18v2M18 18v2"/>',
    archive:    '<rect width="20" height="5" x="2" y="3" rx="1"/><path d="M4 8v11a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8M10 12h4"/>',
    fileText:   '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v5h5M16 13H8M16 17H8M10 9H8"/>',
    receipt:    '<path d="M4 2v20l2-1 2 1 2-1 2 1 2-1 2 1 2-1 2 1V2l-2 1-2-1-2 1-2-1-2 1-2-1-2 1Z"/><path d="M8 7h8M8 11h8M8 15h5"/>',
    download:   '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/>',
    edit:       '<path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.12 2.12 0 0 1 3 3L12 15l-4 1 1-4Z"/>',
    lock:       '<rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
    slash:      '<circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/>',
    tag:        '<path d="M12.586 2.586A2 2 0 0 0 11.172 2H4a2 2 0 0 0-2 2v7.172a2 2 0 0 0 .586 1.414l8.704 8.704a2.426 2.426 0 0 0 3.42 0l6.58-6.58a2.426 2.426 0 0 0 0-3.42z"/><circle cx="7.5" cy="7.5" r=".5"/>',
    swap:       '<path d="M16 3h5v5M21 3l-7 7M8 21H3v-5M3 21l7-7"/>',
    phone:      '<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.91.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92Z"/>',
    mapPin:     '<path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/>',
    calendar:   '<rect width="18" height="18" x="3" y="4" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
    route:      '<circle cx="6" cy="19" r="3"/><path d="M9 19h8.5a3.5 3.5 0 0 0 0-7h-11a3.5 3.5 0 0 1 0-7H15"/><circle cx="18" cy="5" r="3"/>',
    inbox:      '<path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/>',
    sparkle:    '<path d="M9.937 15.5A2 2 0 0 0 8.5 14.063l-6.135-1.582a.5.5 0 0 1 0-.962L8.5 9.936A2 2 0 0 0 9.937 8.5l1.582-6.135a.5.5 0 0 1 .962 0L14.063 8.5A2 2 0 0 0 15.5 9.937l6.135 1.581a.5.5 0 0 1 0 .964L15.5 14.063a2 2 0 0 0-1.437 1.437l-1.582 6.135a.5.5 0 0 1-.962 0z"/>',
    info:       '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/>',
    refresh:    '<path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8M21 3v5h-5M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16M3 21v-5h5"/>',
    briefcase:  '<path d="M16 20V4a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16M4 7h16a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V9a2 2 0 0 1 2-2Z"/>',
    book:       '<path d="M4 19.5v-15A2.5 2.5 0 0 1 6.5 2H19a1 1 0 0 1 1 1v18a1 1 0 0 1-1 1H6.5a1 1 0 0 1 0-5H20"/>',
    hash:       '<path d="M4 9h16M4 15h16M10 3 8 21M16 3l-2 18"/>',
    userPlus:   '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M19 8v6M22 11h-6"/>',
    userCheck:  '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="m16 11 2 2 4-4"/>',
    slash:      '<circle cx="12" cy="12" r="10"/><path d="m4.9 4.9 14.2 14.2"/>',
  };

  /* wsLazyLoad(self, myView, run) — the per-surface first-activation guard
     (I-1). Each per-surface factory is a NESTED Alpine x-data inside wsShell.
     Two Alpine-scope facts shape this:
       (a) `self.view` resolves up the scope chain to the shell's active view at
           READ time (prototype inheritance), so the on-mount check is correct;
       (b) a child's $watch('view') does NOT observe the PARENT's mutation of
           `view`, so we listen for the `ws-view-changed` window event the shell
           dispatches from its single view chokepoint instead.
     The load-once latch is a CLOSURE-LOCAL boolean — NOT a property on `self`.
     Writing `self._wsLoaded` would land on the shared parent scope object (every
     surface inherits the same shell proto), so the first surface to load would
     latch ALL of them. A closure var is per-call and immune to that sharing.
     The active-on-mount surface (deep-link / the default `vandaag`) still
     cold-loads because `self.view === myView` is already true at init. An
     optional `bus` (defaults to window) is injectable for the unit spec. */
  function wsLazyLoad(self, myView, run, bus) {
    const target = bus || (typeof window !== 'undefined' ? window : null);
    let loaded = false;
    const fire = () => {
      if (loaded) {
        return;
      }
      loaded = true;
      run();
    };
    if (self.view === myView) {
      fire();
    }
    if (target && target.addEventListener) {
      target.addEventListener('ws-view-changed', (e) => {
        if (e && e.detail && e.detail.view === myView) {
          fire();
        }
      });
    }
  }

  /* icon(name, cls) — INV-5 safe: `name` is a literal key from the markup,
     resolved to a CONSTANT SVG path. An unknown name renders an empty SVG. */
  function icon(name, cls) {
    const path = ICONS[name] || '';
    return '<svg class="' + (cls || '') +
      '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"' +
      ' stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + path + '</svg>';
  }

  /* The whitelisted set of surfaces the rail can switch to. Anything outside
     this set falls back to the default view (never an arbitrary string). */
  const VIEWS = [
    'vandaag', 'inschrijvingen', 'edities',
    'offertes', 'trajecten', 'gebruikers', 'dossier',
  ];

  /* ---- shared WS surface for the rail/topbar markup (icon lookup only) ----
     Guarded so the module is requirable under Node (the spec imports the pure
     wsLazyLoad guard); in the browser this populates window.WS as before. */
  if (typeof window !== 'undefined') {
    window.WS = window.WS || {};
    window.WS.icon = icon;
    window.WS.ICONS = ICONS;
    window.WS.lazyLoad = wsLazyLoad;
    window.wsShell = wsShell;
  }

  /* ---- the shell Alpine component ----
     Owns the cross-surface concerns only. Per-surface factories are separate. */
  function wsShell() {
    const config = window.StrideConfig || {};
    return {
      config,
      views: VIEWS,
      view: 'vandaag',

      init() {
        this.view = this.resolveView();
        // Keep the URL in sync when the active surface changes (bookmarkable
        // ?view= per the dashboard-tabs convention) AND broadcast the change so
        // each nested per-surface factory can lazily load on its first
        // activation (I-1). A child x-data's $watch('view') does NOT observe the
        // parent's reactive `view` (Alpine inherits parent props read-only via
        // the scope chain — mutations are not tracked across the boundary), so
        // the surfaces listen for this event instead of $watch-ing `view`.
        this.$watch('view', (v) => {
          this.writeViewToUrl(v);
          window.dispatchEvent(new CustomEvent('ws-view-changed', { detail: { view: v } }));
        });
        // Respond to browser back/forward.
        window.addEventListener('popstate', () => { this.view = this.resolveView(); });
      },

      /* Resolve the active view from ?view=, falling back to the
         server-printed default. Never trusts an arbitrary value. */
      resolveView() {
        const params = new URLSearchParams(window.location.search);
        const requested = params.get('view');
        const fallback = this.views.includes(config.defaultView) ? config.defaultView : 'vandaag';
        return requested && this.views.includes(requested) ? requested : fallback;
      },

      /* Switch the active surface, optionally seeding cross-surface deep-link
         params on the URL FIRST so the target surface reads them on its own
         init(). This is the cross-surface contract for cluster B's deep-links:
           switchView('inschrijvingen', { queue: 'pending' })  → ?queue=pending
           switchView('dossier',        { user: 1234, reg: 77 }) → ?user=1234&reg=77
         Cluster C's grid() consumes ?queue= and cluster D's dossier() consumes
         ?user= (+ optional ?reg= to pre-select a registration) from the URL on
         mount. Only a small whitelist of param keys is written (queue, user,
         reg); anything else is ignored. Stale deep-link params
         for surfaces OTHER than the target are cleared so a later plain
         switchView() doesn't carry a previous surface's filter. */
      switchView(view, params) {
        if (!this.views.includes(view)) {
          return;
        }
        const url = new URL(window.location.href);
        // Clear any previous DEEP-LINK-owned params before seeding the new
        // ones. trajectory_id is deliberately NOT in this delete list: it is a
        // first-class grid FILTER (the Traject select) as well as a deep-link,
        // and deleting it here wiped a user-picked filter on every view
        // round-trip while status/edition/q survived. Like those filters it
        // lives in the URL until the user clears its chip; the Trajecten
        // deep-link below OVERWRITES it when a new target is passed.
        url.searchParams.delete('queue');
        url.searchParams.delete('user');
        url.searchParams.delete('reg');
        if (params && typeof params === 'object') {
          if (params.queue != null && params.queue !== '') {
            url.searchParams.set('queue', String(params.queue));
          }
          if (params.user != null && params.user !== '') {
            url.searchParams.set('user', String(params.user));
          }
          // ?reg=<id> deep-links the dossier to the SPECIFIC waiting
          // registration (e.g. a Vandaag "Wacht op mij" row), so it opens the
          // right edition instead of defaulting to the person's newest one.
          if (params.reg != null && params.reg !== '') {
            url.searchParams.set('reg', String(params.reg));
          }
          // ?trajectory_id=<id> deep-links the grid scoped to a trajectory's
          // child edition-rows ("Toon inschrijvingen" on the Trajecten
          // slide-over). The old whitelist silently DROPPED it, so that button
          // switched views without filtering anything (F-T1).
          if (params.trajectory_id != null && params.trajectory_id !== '' && Number(params.trajectory_id) > 0) {
            url.searchParams.set('trajectory_id', String(params.trajectory_id));
          }
          // ?edition_id=<id> deep-links the grid scoped to one edition
          // (Vandaag meldingen: capacity / editie-start alerts). Same
          // contract as trajectory_id: it is ALSO a first-class grid filter,
          // so it is absorb-only — set when a deep-link passes it, never
          // deleted here (the grid owns clearing via its filter chip).
          if (params.edition_id != null && params.edition_id !== '' && Number(params.edition_id) > 0) {
            url.searchParams.set('edition_id', String(params.edition_id));
          }
        }
        // A REAL view switch pushes a history entry carrying the origin view
        // (F-S2): the browser Back button returns to where the admin came
        // from — URL params included (their filters, page, search term) —
        // and the dossier's Terug reads wsFrom to know an origin exists.
        // A same-view call (param seeding only) must NOT grow history.
        url.searchParams.set('view', view);
        if (view !== this.view) {
          window.history.pushState({ wsFrom: this.view }, '', url.toString());
        } else {
          window.history.replaceState(window.history.state, '', url.toString());
        }
        this.view = view;
      },

      isActive(view) {
        return this.view === view;
      },

      /* Write ?view= without reloading the page. Preserves any other query
         params already present (e.g. WordPress's `page=stride-dashboard`) AND
         the current history state — replaceState(null) would wipe the wsFrom
         origin the pushState navigation just recorded. */
      writeViewToUrl(view) {
        const url = new URL(window.location.href);
        url.searchParams.set('view', view);
        window.history.replaceState(window.history.state, '', url.toString());
      },

      icon(name, cls) {
        return icon(name, cls);
      },

      /* The shared API helper EVERY per-surface loader reuses. Sends the
         X-WP-Nonce header (StrideConfig.nonce = wp_rest). Reads `.message`
         from the WP_Error JSON shape {code,message,data:{status}} on failure. */
      async api(endpoint, options = {}) {
        const url = `${config.apiUrl}${endpoint}`;
        const response = await fetch(url, {
          ...options,
          headers: {
            'X-WP-Nonce': config.nonce,
            'Content-Type': 'application/json',
            ...(options.headers || {}),
          },
        });
        if (!response.ok) {
          const error = await response.json().catch(() => ({}));
          throw new Error(error.message || 'API error');
        }
        return response.json();
      },
    };
  }

  /* Node export for the unit specs — the PURE first-activation guard plus the
     shell factory (the navigation spec drives switchView/writeViewToUrl
     against a stubbed window/history). The window block above is guarded, so
     requiring this module under Node runs cleanly without a browser global;
     wsShell() itself reads window at CALL time, so the spec defines its stub
     first. */
  if (typeof module !== 'undefined' && module.exports) {
    module.exports = { wsLazyLoad: wsLazyLoad, wsShell: wsShell };
  }
})();
