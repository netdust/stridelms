/* ==========================================================================
   Stride Admin Workspace — Trajecten surface (Cluster E)
   --------------------------------------------------------------------------
   Meerdelige leertrajecten — read-only overview (list) + a detail slide-over
   (the trajectory's courses + enrolled-deelnemers roster). Ported from
   docs/mockups/admin-workspace/trajecten.html and REBOUND from the mock WS.*
   fixtures to the live per-surface Alpine factory consuming the REAL frozen
   endpoints.

   Backend FROZEN — consumed EXACTLY as AdminTrajectoryService emits it
   (ground-truthed 2026-06-24; the brief's described shape was the *dossier*
   endpoint's, NOT these surface endpoints — see the rebind notes below):

     GET /admin/trajectories            (list — server owns filter/scope/paging)
        { items:[ <item> ], total, page, perPage, totalPages }
     GET /admin/trajectories/{id}       (detail)
        <item> PLUS { registrations:[{id,name,email,status,status_label}] }

     <item> = {
       id, title, description, status, statusLabel, mode, modeLabel,
       capacity, enrolledCount, courseCount,
       courses:[{ editionId, type, title }],   // FLAT — type ∈ {edition,online,…}
       enrolledUsers:[…], price, … }

   REBIND vs the mockup (load-bearing — the mockup keys do NOT exist on these
   endpoints):
     - list is `items`, NOT `trajectories`; each item is FLAT (no nested
       `trajectory:{…}`), so the markup binds t.title / t.status directly.
     - the detail endpoint has NO required[] / electiveGroups[] structure and
       NO "kies N uit M" group data — that resolution lives ONLY on the dossier
       per-user endpoint. Here `courses` is a flat array carrying a `type`
       (edition | online). groupCourses() splits it into { editions, online }
       so the slide-over renders two course blocks. The mockup's elective-group
       "kies N uit M" microcopy is DROPPED — the data to compute it is not on
       this endpoint.
     - the roster is `registrations` (id,name,email,status,status_label) — NOT
       the mockup's `users` with a WS.COMPANIES lookup (no such table, and the
       real row carries no company). Roster row shows name + email + the
       server's status_label, and links to the person's dossier via
       switchView('dossier', {user:r.id}) — NOT a hardcoded dossier.html#reg-103.

   INV-5: every x-html in trajecten.php binds a CONSTANT icon name via
   icon('<literal>'); never a data field. INV-7: status/mode LABELS and the
   roster status_label render AS RECEIVED — never re-derived. `badgeClass` is a
   closed status→hue lookup (styling only), not a re-labelling.

   The pure mappers (mapTrajectories, groupCourses) are exported via the UMD
   tail so the Tier-A unit test can import them without a browser; the browser
   path attaches the factory + mappers to window.WS.
   ========================================================================== */
(function (root, factory) {
  'use strict';
  const api = factory();
  if (typeof module !== 'undefined' && module.exports) {
    module.exports = api; // Node / Playwright unit test
  }
  if (typeof root !== 'undefined') {
    root.trajecten = api.trajecten;
    root.WS = root.WS || {};
    root.WS.mapTrajectories = api.mapTrajectories;
    root.WS.groupCourses = api.groupCourses;
  }
})(typeof window !== 'undefined' ? window : this, function () {
  'use strict';

  /* ---- status VALUE → badge hue class (styling only — INV-5/INV-7) --------
     Ported from the mockup TRAJ_STATUS table. The LABEL is never taken from
     here (the API's statusLabel wins, INV-7); this maps only the closed status
     value to a CSS hue class. An unknown status falls back to the neutral
     slate ('completed') class — never a crash, never raw passthrough. */
  const STATUS_BADGE = {
    // trajectory statuses (the list + detail header badge)
    draft: 'completed',
    open: 'confirmed',
    full: 'waitlist',
    closed: 'pending',
    archived: 'cancelled',
    // registration statuses (the roster row badge — I-1: these were missing, so
    // every roster badge fell through to the neutral slate hue regardless of
    // the actual enrolment state). Distinct keys, so the two domains coexist.
    active: 'confirmed',
    completed: 'completed',
    cancelled: 'cancelled',
    pending: 'pending',
  };
  function badgeClass(status) {
    return STATUS_BADGE[status] || 'completed';
  }

  /* ---- mapTrajectories(payload): the list read-model ----------------------
     Reads payload.ITEMS (the real list key). Each row carries the server's
     statusLabel/modeLabel AS RECEIVED plus a derived badgeClass for the hue.
     Missing / empty payload → []. */
  function mapTrajectories(payload) {
    const items = (payload && Array.isArray(payload.items)) ? payload.items : [];
    return items.map((t) => ({
      id: t.id,
      title: t.title || '',
      description: t.description || '',
      status: t.status || 'draft',
      statusLabel: t.statusLabel || '',
      mode: t.mode || '',
      modeLabel: t.modeLabel || '',
      capacity: Number(t.capacity) || 0,
      enrolledCount: Number(t.enrolledCount) || 0,
      courseCount: Number(t.courseCount) || 0,
      badgeClass: badgeClass(t.status),
    }));
  }

  /* ---- groupCourses(courses): the flat-courses divergence handler ---------
     The real detail endpoint emits a FLAT courses array with a `type`. Split it
     into { editions, online } so the slide-over renders an "Edities" block and a
     "Zelfstudie / online" block. `label` is the course's title, with a fallback
     for the empty-title `online` rows. Type 'edition' → editions; anything else
     (online / unknown / missing) → the online catch-all (never crashes). */
  function groupCourses(courses) {
    const arr = Array.isArray(courses) ? courses : [];
    const editions = [];
    const online = [];
    arr.forEach((c) => {
      const row = {
        editionId: Number(c.editionId) || 0,
        type: c.type || '',
        label: (c.title && String(c.title).trim()) ? c.title : 'Online module',
      };
      if (c.type === 'edition') {
        editions.push(row);
      } else {
        online.push(row);
      }
    });
    return { editions, online };
  }

  /* ---- the Alpine factory ------------------------------------------------ */
  function trajecten() {
    return {
      // list state
      rows: [],
      total: 0,
      loading: true,
      error: '',

      // filters (server-owned — sent to GET /admin/trajectories, never a
      // client-side corpus slice; the cluster-B/C lesson)
      scope: 'active',   // 'active' | 'all' — the default-scope pill
      statusFilter: '',
      q: '',

      // detail slide-over state (its own load lifecycle)
      detail: null,
      detailCourses: { editions: [], online: [] },
      detailLoading: false,
      detailError: '',

      init() {
        // I-1: load the list the FIRST time trajecten becomes active, not on
        // mount. The ?open=<id> detail deep-link is gated together so it only
        // fires when this surface is actually shown (detail load stays
        // click-driven via openDetail() thereafter).
        window.WS.lazyLoad(this, 'trajecten', () => {
          this.loadList();
          const id = new URLSearchParams(window.location.search).get('open');
          if (id) {
            this.openDetail(Number(id));
          }
        });
      },

      /* Load the list. Server owns scope/status/search filtering. Error reset
         at the top so a successful retry recovers cleanly (cluster-B lesson). */
      loadList() {
        this.loading = true;
        this.error = '';
        const params = new URLSearchParams({ per_page: '50' });
        if (this.scope === 'active') params.set('scope', 'active');
        if (this.statusFilter) params.set('status', this.statusFilter);
        if (this.q.trim()) params.set('search', this.q.trim());
        this.api('/admin/trajectories?' + params.toString())
          .then((data) => {
            this.rows = window.WS.mapTrajectories(data);
            this.total = Number(data && data.total) || this.rows.length;
          })
          .catch(() => {
            this.error = 'Kon de trajecten niet laden.';
            this.rows = [];
          })
          .finally(() => { this.loading = false; });
      },

      onFilterChange() { this.loadList(); },

      toggleScope() {
        this.scope = (this.scope === 'active' ? 'all' : 'active');
        this.loadList();
      },

      /* Open the detail slide-over: a single O(1) fetch (the F1-fixed path that
         resolves a trajectory beyond the first page). Own loading/error/empty
         (empty roster is NOT an error — the t3 edge). */
      openDetail(id) {
        if (!id) return;
        this.detail = null;
        this.detailCourses = { editions: [], online: [] };
        this.detailLoading = true;
        this.detailError = '';
        this.api('/admin/trajectories/' + encodeURIComponent(id))
          .then((data) => {
            this.detail = data;
            this.detailCourses = window.WS.groupCourses(data && data.courses);
          })
          .catch(() => {
            this.detailError = 'Kon dit traject niet laden.';
          })
          .finally(() => { this.detailLoading = false; });
      },

      closeDetail() {
        this.detail = null;
        this.detailError = '';
        this.detailCourses = { editions: [], online: [] };
      },

      /* Jump to a roster person's dossier via the shell's extended switchView
         (NOT a hardcoded dossier.html#reg-103). */
      openPerson(r) {
        if (r && r.id) this.switchView('dossier', { user: r.id });
      },

      badgeClass(status) { return badgeClass(status); },
    };
  }

  return {
    trajecten,
    mapTrajectories,
    groupCourses,
    badgeClass,
  };
});
