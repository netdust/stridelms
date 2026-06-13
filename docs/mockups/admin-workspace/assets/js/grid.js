/* ==========================================================================
   Stride Admin Workspace — Inschrijvingen grid (Alpine component)
   --------------------------------------------------------------------------
   STATIC. Mirrors the real interaction model: server-side-paging FEEL (we
   page a hardcoded array, never render 4k), multi-select → state-aware bulk
   bar (derived from the §2.1 transition map), group-by aggregates from
   "structured" fields only, filter chips, sort, and a SIMULATED bulk
   partial-failure report. No backend, no fetch.
   ========================================================================== */
function grid() {
  return {
    /* ---- source ---- */
    all: WS.REGISTRATIONS,
    editions: WS.EDITIONS,
    companies: WS.COMPANIES,
    trajectories: WS.TRAJECTORIES,
    trajStatus: WS.TRAJ_STATUS,
    regStatus: WS.REG_STATUS,
    offerteStatus: WS.OFFERTE_STATUS,

    /* ---- view state ---- */
    selected: {},                 // id -> true (a plain object stands in for a Set, Alpine-reactive)
    page: 1,
    perPage: 10,
    sortKey: 'name',
    sortDir: 'asc',
    groupBy: '',                  // '' | 'edition' | 'status' | 'company' | 'trajectory'
    collapsed: {},                // group key -> true
    queue: '',                    // active worklist context (from ?queue=)
    armedAction: null,            // pre-armed bulk action id from the queue

    /* ---- filters ----
       `trajectory` is the second scope axis (spec §11.2). When set, the corpus
       narrows to that trajectory's CHILD edition-rows via the parent→child join
       (WS.childRegsByTrajectory), NOT a bare column match — see `get corpus`. */
    filters: { status: '', edition: '', company: '', trajectory: '', offerteOpen: false, noCert: false, q: '' },

    /* ---- result modal / overflow / toast ---- */
    showResult: false,
    result: null,
    busyAction: null,
    overflowOpen: false,
    toasts: [],
    toastSeq: 0,

    /* ===================================================================== */
    init() {
      const p = new URLSearchParams(location.search);
      const q = p.get('queue');
      if (q && WS.QUEUE_FILTER[q]) {
        const qf = WS.QUEUE_FILTER[q];
        this.queue = q;
        if (qf.status) this.filters.status = qf.status;
        if (qf.offerteNot) this.filters.offerteOpen = true;
        if (qf.noCert) this.filters.noCert = true;
        this.armedAction = qf.armAction || null;
        // a queue arriving with an armed action gives a hint toast
        this.$nextTick(() => this.toast('mixed',
          `Wachtrij geopend — <b>${WS.QUEUES.find(x=>x.key===q)?.label}</b>. Bulkactie "${this.armedLabel}" staat klaar.`));
      }
      // jump-to-grid from the Trajecten tab: ?trajectory=<id> pre-sets the
      // trajectory scope (same mechanism as ?queue=). The grid then shows that
      // trajectory's child edition-rows (spec §11.2, flow F7).
      const t = p.get('trajectory');
      if (t && this.trajectories[t]) {
        this.filters.trajectory = t;
        this.$nextTick(() => this.toast('mixed',
          `Traject geopend — <b>${this.trajectories[t].title}</b>. De grid toont de cursusdeelnames van dit traject.`));
      }
    },

    /* the working corpus. Normally the full registration set, but when a
       trajectory filter is active the corpus IS that trajectory's child
       edition-rows (the parent→child join, spec §11.2). This is what makes the
       grid show ONLY trajectory T's course rows and never another trajectory's
       (the leak-check, F7) — and it keeps the grid edition-grained (the parent
       row, edition_id=null, is never part of the corpus). */
    get corpus() {
      return this.filters.trajectory
        ? WS.childRegsByTrajectory(this.filters.trajectory)
        : this.all;
    },

    /* ===== derived: filtered + sorted full set ===== */
    get filtered() {
      let rows = this.corpus.filter(r => {
        const f = this.filters;
        if (f.status && r.status !== f.status) return false;
        if (f.edition && r.edition !== f.edition) return false;
        if (f.company && r.company !== f.company) return false;
        if (f.offerteOpen && !(r.status === 'confirmed' && r.offerte !== 'exported')) return false;
        if (f.noCert && !(r.status === 'completed' && r.cert === false)) return false;
        if (f.q) {
          const hay = (r.name + ' ' + r.email + ' ' + (this.companies[r.company]||'')).toLowerCase();
          if (!hay.includes(f.q.toLowerCase())) return false;
        }
        return true;
      });
      rows = rows.slice().sort((a, b) => {
        let x, y;
        switch (this.sortKey) {
          case 'name':    x = a.name; y = b.name; break;
          case 'edition': x = this.editions[a.edition].title; y = this.editions[b.edition].title; break;
          case 'status':  x = this.regStatus[a.status].label; y = this.regStatus[b.status].label; break;
          case 'att':     x = a.attendance ?? -1; y = b.attendance ?? -1; break;
          case 'company': x = this.companies[a.company]||'~'; y = this.companies[b.company]||'~'; break;
          default: x = a.name; y = b.name;
        }
        if (x < y) return this.sortDir === 'asc' ? -1 : 1;
        if (x > y) return this.sortDir === 'asc' ? 1 : -1;
        return 0;
      });
      return rows;
    },

    /* per-status counts for the pipeline chips — respect every OTHER active
       filter but ignore the status filter itself, so the funnel always shows
       how many rows each stage holds within the current context. */
    statusCount(status) {
      const f = this.filters;
      return this.corpus.filter(r => {
        if (r.status !== status) return false;
        if (f.edition && r.edition !== f.edition) return false;
        if (f.company && r.company !== f.company) return false;
        if (f.offerteOpen && !(r.status === 'confirmed' && r.offerte !== 'exported')) return false;
        if (f.noCert && !(r.status === 'completed' && r.cert === false)) return false;
        if (f.q) {
          const hay = (r.name + ' ' + r.email + ' ' + (this.companies[r.company] || '')).toLowerCase();
          if (!hay.includes(f.q.toLowerCase())) return false;
        }
        return true;
      }).length;
    },

    get total() { return this.filtered.length; },
    // the "van N" denominator — the corpus size. Normally the fixed 247-row
    // feel; when scoped to a trajectory it's that trajectory's child-row count.
    get totalAllCorpus() { return this.filters.trajectory ? this.corpus.length : 247; },
    get pageCount() { return Math.max(1, Math.ceil(this.total / this.perPage)); },
    get pageRows() {
      const start = (this.page - 1) * this.perPage;
      return this.filtered.slice(start, start + this.perPage);
    },
    get rangeFrom() { return this.total === 0 ? 0 : (this.page - 1) * this.perPage + 1; },
    get rangeTo() { return Math.min(this.page * this.perPage, this.total); },

    /* ===== grouping ===== */
    get groups() {
      if (!this.groupBy) return [];
      const keyOf = (r) =>
        this.groupBy === 'edition'    ? r.edition :
        this.groupBy === 'status'     ? r.status  :
        this.groupBy === 'company'    ? (r.company || 'none') :
        this.groupBy === 'trajectory' ? (r.trajectory || 'none') : '';
      const labelOf = (k) =>
        this.groupBy === 'edition'    ? this.editions[k].title :
        this.groupBy === 'status'     ? this.regStatus[k].label :
        this.groupBy === 'company'    ? (this.companies[k] || 'Geen organisatie') :
        this.groupBy === 'trajectory' ? (this.trajectories[k] ? this.trajectories[k].title : 'Geen traject') : k;

      const map = {};
      this.filtered.forEach(r => {
        const k = keyOf(r);
        (map[k] = map[k] || []).push(r);
      });
      return Object.keys(map).map(k => {
        const rows = map[k];
        const done = rows.filter(r => r.status === 'completed').length;
        const att = rows.filter(r => r.attendance != null);
        const attAvg = att.length ? Math.round(att.reduce((n, r) => n + r.attendance, 0) / att.length) : null;
        const dist = { none: 0, draft: 0, sent: 0, exported: 0 };
        rows.forEach(r => dist[r.offerte]++);
        return {
          key: k, label: labelOf(k), rows,
          count: rows.length,
          pctDone: Math.round((done / rows.length) * 100),
          attAvg, dist,
        };
      }).sort((a, b) => b.count - a.count);
    },

    /* human label for what we're grouping by (shown on each group header) */
    get groupKindLabel() {
      return { edition: 'Editie', status: 'Status', company: 'Organisatie', trajectory: 'Traject' }[this.groupBy] || '';
    },
    /* toggle one group section open/closed */
    toggleGroup(key) { this.collapsed[key] = !this.collapsed[key]; },
    /* when the group field changes: clear collapse state (all expanded), reset page,
       and clear selection so the bulk bar doesn't carry across a restructure */
    onGroupChange() {
      this.collapsed = {};
      this.page = 1;
    },
    /* compact textual offerte distribution for the group header */
    distSummary(dist) {
      const parts = [];
      if (dist.exported) parts.push(dist.exported + ' verwerkt');
      if (dist.sent)     parts.push(dist.sent + ' verzonden');
      if (dist.draft)    parts.push(dist.draft + ' in behandeling');
      if (dist.none)     parts.push(dist.none + ' geen');
      return parts.length ? parts.join(' · ') : 'geen offertes';
    },

    /* ===== selection ===== */
    get selectedIds() { return Object.keys(this.selected).filter(id => this.selected[id]).map(Number); },
    get selectedCount() { return this.selectedIds.length; },
    get selectedRows() { return this.all.filter(r => this.selected[r.id]); },
    isSelected(id) { return !!this.selected[id]; },
    toggle(id) { this.selected[id] = !this.selected[id]; },
    get pageAllSelected() {
      const ids = this.pageRows.map(r => r.id);
      return ids.length > 0 && ids.every(id => this.selected[id]);
    },
    get pageSomeSelected() {
      const ids = this.pageRows.map(r => r.id);
      return ids.some(id => this.selected[id]) && !this.pageAllSelected;
    },
    togglePage() {
      const ids = this.pageRows.map(r => r.id);
      const target = !this.pageAllSelected;
      ids.forEach(id => this.selected[id] = target);
    },
    selectAllFiltered() {                       // the "select-all across pages" model (carry the filter)
      this.filtered.forEach(r => this.selected[r.id] = true);
      this.toast('mixed', `<b>${this.total}</b> inschrijvingen geselecteerd over alle pagina's. De bulkactie draagt het filter, niet ${this.total} rijen.`);
    },
    clearSelection() { this.selected = {}; },

    /* ===== bulk actions — state-aware (intersection of selected states) ===== */
    get selectedStates() { return [...new Set(this.selectedRows.map(r => r.status))]; },
    get bulkActions() { return WS.actionsForStates(this.selectedStates); },
    get topActions() { return this.bulkActions.slice(0, 3); },
    get overflowActions() { return this.bulkActions.slice(3); },
    get mixedHint() {
      // when the selection mixes states with no shared safe action
      return this.selectedCount > 0 && this.bulkActions.length === 0;
    },
    get armedLabel() {
      const a = WS.SMART_ACTIONS.find(x => x.id === this.armedAction);
      return a ? a.label : '';
    },

    statesSummary() {
      return this.selectedStates.map(s => this.regStatus[s].label).join(', ');
    },

    /* ---- the simulated execution + partial-failure report ---- */
    runBulk(actionId) {
      const action = WS.SMART_ACTIONS.find(a => a.id === actionId);
      if (!action) return;
      this.overflowOpen = false;
      this.busyAction = actionId;

      // simulate a server-side loop with one engineered failure for the demo
      setTimeout(() => {
        const rows = this.selectedRows;
        const succeeded = [];
        const failed = [];
        rows.forEach((r, i) => {
          // engineer realistic per-row failures so the partial-success UI shows
          let fail = null;
          if (action.id === 'stride_bulk_promote_waitlist' && this.editions[r.edition].seatsOpen === 0) {
            fail = { code: 'capacity_full', message: 'Editie is vol — geen plaats vrij.' };
          } else if (action.id === 'stride_bulk_approve' && r.id === 109) {
            fail = { code: 'missing_consent', message: 'Toestemming GDPR ontbreekt nog.' };
          } else if (action.id === 'stride_bulk_quote_sent' && r.offerte === 'none') {
            fail = { code: 'no_quote', message: 'Geen offerte gekoppeld aan deze inschrijving.' };
          } else if (i === rows.length - 1 && rows.length >= 5 && action.danger !== true && Math.random() < 0.9) {
            // a generic mid-flow failure on the last row to demonstrate non-atomic semantics
            fail = { code: 'notify_failed', message: 'E-mail kon niet verzonden worden (mailserver time-out).' };
          }
          if (fail) failed.push({ id: r.id, name: r.name, ...fail });
          else succeeded.push({ id: r.id, name: r.name });
        });

        this.busyAction = null;
        this.result = {
          action: action.label,
          total: rows.length,
          succeeded, failed,
          ok: succeeded.length,
          err: failed.length,
        };

        if (failed.length === 0) {
          // clean success → toast only
          this.toast('ok', `<b>${action.label}</b>: ${succeeded.length} geslaagd.`);
          this.applyOptimistic(action, succeeded.map(s => s.id));
          this.clearSelection();
        } else {
          // partial → open the report modal
          this.applyOptimistic(action, succeeded.map(s => s.id));
          this.showResult = true;
        }
      }, 650);
    },

    // reflect the change visually on succeeded rows (mockup only)
    applyOptimistic(action, ids) {
      const set = new Set(ids);
      this.all.forEach(r => {
        if (!set.has(r.id)) return;
        switch (action.id) {
          case 'stride_bulk_approve':           r.status = (r.status === 'interest') ? 'pending' : 'confirmed'; break;
          case 'stride_bulk_promote_waitlist':  r.status = 'confirmed'; break;
          case 'stride_bulk_cancel':            r.status = 'cancelled'; r.offerte = 'none'; break;
          case 'stride_bulk_quote_sent':        if (r.offerte !== 'none') r.offerte = 'sent'; break;
          case 'stride_bulk_quote_exported':    if (r.offerte !== 'none') r.offerte = 'exported'; break;
        }
      });
    },

    closeResult() {
      this.showResult = false;
      // keep only the failed rows selected, so the user can retry just those
      const failIds = new Set(this.result.failed.map(f => f.id));
      this.all.forEach(r => { this.selected[r.id] = failIds.has(r.id); });
      this.result = null;
    },

    /* ===== filter chips ===== */
    setStatus(s) { this.filters.status = (this.filters.status === s) ? '' : s; this.page = 1; }
    ,setEdition(e) { this.filters.edition = (this.filters.edition === e) ? '' : e; this.page = 1; }
    ,clearAllFilters() { this.filters = { status:'', edition:'', company:'', trajectory:'', offerteOpen:false, noCert:false, q:'' }; this.queue=''; this.armedAction=null; this.page = 1; }
    ,get hasFilters() { const f = this.filters; return f.status||f.edition||f.company||f.trajectory||f.offerteOpen||f.noCert||f.q; }
    ,get activeChips() {
      const out = [];
      const f = this.filters;
      if (f.status)  out.push({ k:'status',  label: 'Status: ' + this.regStatus[f.status].label });
      if (f.edition) out.push({ k:'edition', label: 'Editie: ' + this.editions[f.edition].title });
      if (f.company) out.push({ k:'company', label: 'Org: ' + this.companies[f.company] });
      // the Traject scope pill (spec §11.2: "Traject: <naam>")
      if (f.trajectory && this.trajectories[f.trajectory]) out.push({ k:'trajectory', label: 'Traject: ' + this.trajectories[f.trajectory].title });
      if (f.offerteOpen) out.push({ k:'offerteOpen', label: 'Offerte nog niet verwerkt' });
      if (f.noCert)  out.push({ k:'noCert', label: 'Geen certificaat' });
      if (f.q)       out.push({ k:'q', label: '"' + f.q + '"' });
      return out;
    }
    ,removeChip(k) {
      if (k === 'offerteOpen' || k === 'noCert') this.filters[k] = false;
      else this.filters[k] = '';
      this.page = 1;
    }
    /* the Traject column cell: the trajectory title when a row belongs to one,
       else '' (most rows are not in a trajectory). */
    ,trajTitle(r) { return (r.trajectory && this.trajectories[r.trajectory]) ? this.trajectories[r.trajectory].title : ''; },

    /* ===== sort ===== */
    sort(key) {
      if (this.sortKey === key) this.sortDir = this.sortDir === 'asc' ? 'desc' : 'asc';
      else { this.sortKey = key; this.sortDir = 'asc'; }
    },

    /* ===== helpers for rendering ===== */
    attClass(v) { return v == null ? '' : v >= 80 ? 'ws-meter__fill--high' : v >= 60 ? 'ws-meter__fill--mid' : 'ws-meter__fill--low'; },
    distColor(k) { return { none:'#cbd5e1', draft:'#94a3b8', sent:'#2563eb', exported:'#16a34a' }[k]; },
    pageList() {
      // compact pagination model: 1 … (cur-1, cur, cur+1) … last
      const last = this.pageCount, cur = this.page, out = [];
      const push = (v) => out.push(v);
      if (last <= 7) { for (let i=1;i<=last;i++) push(i); return out; }
      push(1);
      if (cur > 3) push('…');
      for (let i=Math.max(2,cur-1); i<=Math.min(last-1,cur+1); i++) push(i);
      if (cur < last-2) push('…');
      push(last);
      return out;
    },
    goPage(p) { if (p>=1 && p<=this.pageCount) this.page = p; },

    // context-aware empty-state headline (covers the F1 "empty queue" case)
    emptyTitle() {
      if (this.filters.trajectory && this.trajectories[this.filters.trajectory]) return 'Nog geen inschrijvingen voor dit traject';
      if (this.queue === 'pending')  return 'Geen inschrijvingen wachten op goedkeuring';
      if (this.queue === 'waitlist') return 'Geen wachtlijst met vrije plaatsen';
      if (this.queue === 'offerte')  return 'Geen openstaande offerte-opvolging';
      if (this.queue === 'nocert')   return 'Iedereen heeft een certificaat';
      if (this.queue === 'oldinterest') return 'Geen oude interesse meer';
      if (this.filters.q)            return `Geen resultaten voor "${this.filters.q}"`;
      return 'Geen inschrijvingen gevonden';
    },

    /* toast plumbing */
    toast(kind, msg) {
      const id = ++this.toastSeq;
      this.toasts.push({ id, kind, msg });
      setTimeout(() => { this.toasts = this.toasts.filter(t => t.id !== id); }, 4200);
    },
  };
}
