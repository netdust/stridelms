/* ==========================================================================
   Stride Admin Workspace — Cohort lens slideover (Cluster G)
   --------------------------------------------------------------------------
   The per-edition roster overlay. Opened from an edition row (the Edities
   surface dispatches a `ws-cohort-open` window event with { editionId }); this
   component listens and slides a right-anchored panel over the current surface.

   This is a REBUILD (the old sd-* cohort-lens markup was deleted in cluster A's
   dead-code sweep) — the BEHAVIOR is lifted faithfully from the proven Phase-2a
   god-component (commit ec059145): session filter, loaded-set extras filter
   (CF3), per-session attendance marking (CF2), and the roster bulk bar driven by
   the SAME RosterBulkHandler registry + per-action StrideConfig.bulkNonces. The
   BACKEND is FROZEN — consumed exactly as it is emitted:

     GET /admin/editions/{id}          → { title, sessions:[{id,title,date,...}], ... }
     GET /admin/editions/{id}/roster   → { edition_id, rows:[{ registration_id,
            user_id, name, organisation, status, is_anonymised,
            selections:[<session ids, server-resolved via INV-6b>],
            attendance:{present,absent,excused}, extras:{<key>:<scalar>} }],
            extras_keys:[<key>] }
     POST ntdst/v1/action              → roster bulk registry (envelope {success,data})

   INV-5: every x-html in the markup binds a CONSTANT icon name via icon('<literal>');
          data renders via x-text (auto-escaped). INV-6b: rows[].selections are the
          session ids the SERVER resolved (getSelections convergence point) — the
          session filter matches against them, NEVER a client-side raw-column decode.
          INV-7: status + is_anonymised render AS RECEIVED (the service masks
          anonymised rows; we render the masked value, never re-derive).

   The two branching pure helpers (the loaded-set extras-option builder and the
   roster safe-state-intersection) are exported (UMD tail) so the Tier-A unit
   test imports them without a browser; the browser path attaches the factory.
   ========================================================================== */
(function (root, factory) {
  'use strict';
  const api = factory();
  if (typeof module !== 'undefined' && module.exports) {
    module.exports = api; // node / Playwright unit test
  }
  if (typeof root !== 'undefined') {
    root.cohort = api.cohort;
    root.WS = root.WS || {};
    root.WS.cohortExtrasOptionsFrom = api.cohortExtrasOptionsFrom;
    root.WS.rosterActionsForStates = api.rosterActionsForStates;
  }
})(typeof window !== 'undefined' ? window : this, function () {
  'use strict';

  /* ---- registration status VALUE → Dutch label (presentation, closed enum) -
     Mirrors the grid's STATUS_META labels (same authoritative set). Rendered
     AS RECEIVED — never re-derived (INV-7). Unknown/empty → the raw value. */
  const STATUS_LABEL = {
    interest:  'Interesse',
    waitlist:  'Wachtlijst',
    pending:   'In afwachting',
    confirmed: 'Bevestigd',
    completed: 'Afgerond',
    cancelled: 'Geannuleerd',
  };
  function statusLabel(value) {
    return STATUS_LABEL[String(value || '')] || (value || '—');
  }
  function statusBadgeClass(value) {
    return STATUS_LABEL[String(value || '')] ? String(value) : 'cancelled';
  }

  /* ---- Roster bulk-action catalog (lifted from the god-component) ---------
     Scope-agnostic ids; cohortActionName() prefixes each to the registry action
     (edition → stride_roster_bulk_*). `approve` is the ONE lifecycle action; its
     offered states are validated against the SAME StrideConfig.transitions map at
     init (CR-5) so a roster button can't drift from the server map. message /
     generate_doc are orthogonal-to-status deferred stubs (no map entry). icon
     keys are CONSTANT ICONS-map names (INV-5). */
  const ROSTER_ACTIONS = [
    { id: 'approve',      label: 'Goedkeuren',         icon: 'checkCircle', states: ['pending', 'interest', 'waitlist'] },
    { id: 'message',      label: 'Bericht sturen',     icon: 'mail',        states: ['confirmed', 'completed', 'interest', 'pending', 'waitlist'] },
    { id: 'generate_doc', label: 'Document genereren', icon: 'fileText',    states: ['confirmed', 'completed'] },
  ];
  const ROSTER_LIFECYCLE_TARGET = { approve: 'confirmed' };

  /* The safe intersection of roster actions across a set of selected states —
     an action is offered ONLY if EVERY selected state is in its `states`. Empty
     selection → [] (no action on nothing). PURE, Tier-A. */
  function rosterActionsForStates(states) {
    const uniq = [...new Set(states)];
    if (uniq.length === 0) return [];
    return ROSTER_ACTIONS.filter((a) => uniq.every((s) => a.states.includes(s)));
  }

  /* Warn (don't break) if a lifecycle action is offered for a state the server
     transition map does not permit to reach its target (CR-5 drift guard). */
  function validateRosterTransitionDrift(transitions) {
    if (!transitions || typeof transitions !== 'object') return;
    const canReach = (from, target) => Array.isArray(transitions[from]) && transitions[from].includes(target);
    ROSTER_ACTIONS.forEach((a) => {
      const target = ROSTER_LIFECYCLE_TARGET[a.id];
      if (!target) return;
      const invalid = a.states.filter((s) => !canReach(s, target));
      if (invalid.length) {
        // eslint-disable-next-line no-console
        console.warn(`[cohort] roster action "${a.id}" offered for state(s) [${invalid}] the server map does not permit → "${target}" (CR-5).`);
      }
    });
  }

  /* ---- loaded-set extras-filter option builder (CF3, PURE, Tier-A) --------
     The distinct {key=value} pairs present across the LOADED rows, each with a
     count. Built CLIENT-SIDE over the returned set ONLY — never a server param,
     never offered on the global grid (the leak-check). Values are coerced to a
     string for a stable token. Empty / no-extras rows contribute nothing. */
  function cohortExtrasOptionsFrom(rows) {
    const seen = new Map();
    (rows || []).forEach((r) => {
      Object.entries((r && r.extras) || {}).forEach(([k, v]) => {
        const value = String(v);
        const token = `${k}=${value}`;
        if (!seen.has(token)) {
          seen.set(token, { token, key: k, value, count: 0 });
        }
        seen.get(token).count++;
      });
    });
    return [...seen.values()];
  }

  /* ---- the Alpine factory ------------------------------------------------ */
  function cohort() {
    return {
      open: false,
      loading: false,
      error: '',

      editionId: 0,
      title: '',
      rows: [],          // roster rows from the endpoint (loaded set)
      extrasKeys: [],
      sessions: [],      // session list (from the edition detail endpoint)
      sessionId: 0,      // active per-session filter (0 = all rows)
      extrasFilter: '',  // active "key=value" extras filter ('' = none)

      selected: {},      // registration_id -> true
      bulkBusy: null,    // action id currently running
      result: null,      // { action, total, succeeded[], failed[], ok, err }
      resultOpen: false,

      toasts: [],
      toastSeq: 0,

      get canManage() { return !!(window.StrideConfig || {}).canManage; },

      init() {
        validateRosterTransitionDrift((window.StrideConfig || {}).transitions);
      },

      /* Open the lens for an edition (relayed from the Edities surface via the
         `ws-cohort-open` window event). Resets all state, then fetches the
         edition detail (sessions + title) and the roster in parallel-ish. */
      async openForEdition(editionId) {
        const id = Number(editionId) || 0;
        if (!id) return;
        this.resetState();
        this.editionId = id;
        this.open = true;
        this.loading = true;
        try {
          const [detail, roster] = await Promise.all([
            this.api(`/admin/editions/${id}`).catch(() => ({})),
            this.api(`/admin/editions/${id}/roster`),
          ]);
          this.title = (detail && detail.title) || '';
          this.sessions = (detail && detail.sessions) || [];
          this.rows = ((roster && roster.rows) || []).map((r) => ({
            ...r,
            selections: Array.isArray(r.selections) ? r.selections : [],
            attendance: r.attendance || { present: 0, absent: 0, excused: 0 },
            extras: r.extras || {},
          }));
          this.extrasKeys = (roster && roster.extras_keys) || [];
        } catch (e) {
          this.error = 'Kon het rooster niet laden.';
        } finally {
          this.loading = false;
        }
      },

      /* Re-fetch JUST the roster (after an attendance mark or a bulk action) —
         keeps the session list/title already loaded. */
      async reloadRoster() {
        if (!this.editionId) return;
        this.loading = true;
        this.error = '';
        try {
          const roster = await this.api(`/admin/editions/${this.editionId}/roster`);
          this.rows = ((roster && roster.rows) || []).map((r) => ({
            ...r,
            selections: Array.isArray(r.selections) ? r.selections : [],
            attendance: r.attendance || { present: 0, absent: 0, excused: 0 },
            extras: r.extras || {},
          }));
          this.extrasKeys = (roster && roster.extras_keys) || [];
        } catch (e) {
          this.error = 'Kon het rooster niet laden.';
        } finally {
          this.loading = false;
        }
      },

      retry() { this.openForEdition(this.editionId); },

      resetState() {
        this.error = '';
        this.title = '';
        this.rows = [];
        this.extrasKeys = [];
        this.sessions = [];
        this.sessionId = 0;
        this.extrasFilter = '';
        this.selected = {};
        this.result = null;
        this.resultOpen = false;
        this.bulkBusy = null;
      },

      close() {
        this.open = false;
        this.resetState();
        this.editionId = 0;
      },

      /* ===== per-session roster (CF1) =====
         "Who is in which session" = rows whose selections (server-resolved,
         INV-6b) include the active session id. sessionId 0 = the whole roster.
         The extras filter (loaded-set, CF3) narrows further. */
      get visibleRows() {
        let rows = this.rows;
        if (this.sessionId) {
          const sid = Number(this.sessionId);
          rows = rows.filter((r) => (r.selections || []).map(Number).includes(sid));
        }
        if (this.extrasFilter) {
          const [key, ...rest] = this.extrasFilter.split('=');
          const value = rest.join('=');
          rows = rows.filter((r) => String((r.extras && r.extras[key]) != null ? r.extras[key] : '') === value);
        }
        return rows;
      },
      get visibleCount() { return this.visibleRows.length; },
      get sessionScopedCount() {
        if (!this.sessionId) return this.rows.length;
        const sid = Number(this.sessionId);
        return this.rows.filter((r) => (r.selections || []).map(Number).includes(sid)).length;
      },
      get isFiltered() { return !!(this.sessionId || this.extrasFilter); },
      selectSession(sessionId) {
        this.sessionId = Number(sessionId) || 0;
        this.selected = {}; // selection is per-visible-set; reset on axis change
      },
      sessionChipLabel(s) {
        return s.title || s.date || ('Sessie ' + s.id);
      },

      /* ===== extras filter chips (CF3 — loaded-set ONLY) ===== */
      get extrasOptions() { return cohortExtrasOptionsFrom(this.rows); },
      setExtrasFilter(token) {
        this.extrasFilter = this.extrasFilter === token ? '' : token;
        this.selected = {};
      },
      clearExtrasFilter() { this.extrasFilter = ''; },

      /* ===== attendance ===== */
      attendanceLabel(att) {
        const a = att || {};
        const total = (a.present || 0) + (a.absent || 0) + (a.excused || 0);
        if (total === 0) return '—';
        return `${a.present || 0}/${total} aanwezig`;
      },
      get canMarkAttendance() {
        return !!(this.canManage && this.sessionId);
      },
      async markAttendance(userId, status) {
        if (!this.canMarkAttendance) return;
        const sessionId = Number(this.sessionId);
        try {
          await this.api('/admin/attendance', {
            method: 'POST',
            body: JSON.stringify({ session_id: sessionId, user_id: Number(userId), status }),
          });
          await this.reloadRoster();
          this.sessionId = sessionId; // preserve the session focus
        } catch (e) {
          this.toast('mixed', '', 'Aanwezigheid opslaan mislukt.');
        }
      },

      /* ===== selection + roster bulk bar (CF4) ===== */
      isSelected(id) { return !!this.selected[id]; },
      toggleRow(id) { this.selected[id] = !this.selected[id]; },
      get selectedIds() {
        return Object.keys(this.selected).filter((id) => this.selected[id]).map(Number);
      },
      get selectedCount() { return this.selectedIds.length; },
      get selectedRows() {
        const sel = this.selected;
        return this.rows.filter((r) => sel[r.registration_id]);
      },
      get pageAllSelected() {
        const ids = this.visibleRows.map((r) => r.registration_id);
        return ids.length > 0 && ids.every((id) => this.selected[id]);
      },
      toggleAll() {
        const ids = this.visibleRows.map((r) => r.registration_id);
        const target = !this.pageAllSelected;
        ids.forEach((id) => { this.selected[id] = target; });
      },
      clearSelection() { this.selected = {}; },

      get selectedStates() {
        return [...new Set(this.selectedRows.map((r) => r.status))].filter(Boolean);
      },
      get bulkActions() { return rosterActionsForStates(this.selectedStates); },
      get mixedHint() { return this.selectedCount > 0 && this.bulkActions.length === 0; },
      statesSummary() {
        return this.selectedStates.map((s) => statusLabel(s)).join(', ');
      },

      /* The registry action name for the edition scope. Edition →
         stride_roster_bulk_*; the per-action nonce (StrideConfig.bulkNonces) is
         keyed on this full name and bulkApi reads it. */
      actionName(actionId) { return 'stride_roster_bulk_' + actionId; },

      /* POST to the ntdst/v1/action registry (envelope {success,data}). Distinct
         from the shell api() (stride/v1). Carries the per-action nonce. */
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
        if (!this.canManage) return;
        const action = ROSTER_ACTIONS.find((a) => a.id === actionId);
        if (!action || this.bulkBusy) return;
        const ids = this.selectedIds;
        if (ids.length === 0) return;

        const fullAction = this.actionName(actionId);
        this.bulkBusy = actionId;
        try {
          // CM-1: edition_id is a REQUIRED scope param — the server re-checks each
          // row belongs to it (foreign rows → out_of_scope, never mutated).
          const report = await this.bulkApi(fullAction, { ids, edition_id: this.editionId });
          const succeeded = report.succeeded || [];
          const failed = report.failed || [];
          const nameById = {};
          this.rows.forEach((r) => { nameById[r.registration_id] = r.name || ('#' + r.registration_id); });
          const failRows = failed.map((f) => ({ ...f, name: nameById[f.id] || ('#' + f.id) }));

          this.result = {
            action: action.label,
            total: report.total != null ? report.total : ids.length,
            succeeded,
            failed: failRows,
            ok: (report.summary && report.summary.ok) != null ? report.summary.ok : succeeded.length,
            err: (report.summary && report.summary.error) != null ? report.summary.error : failed.length,
          };

          if (failRows.length === 0) {
            this.toast('ok', action.label, `: ${this.result.ok} geslaagd.`);
            this.clearSelection();
          } else if (this.result.ok === 0 && failRows.every((f) => f.code === 'not_available')) {
            this.toast('mixed', '', 'Deze actie is nog niet beschikbaar.');
            this.resultOpen = true;
          } else {
            this.resultOpen = true;
          }
          await this.reloadRoster();
        } catch (e) {
          this.toast('mixed', '', (e && e.message) || 'Bulkactie mislukt.');
        } finally {
          this.bulkBusy = null;
        }
      },

      closeResult() {
        this.resultOpen = false;
        // keep only the failed rows selected so the user can retry just those
        const failIds = new Set((this.result && this.result.failed ? this.result.failed : []).map((f) => f.id));
        const next = {};
        failIds.forEach((id) => { next[id] = true; });
        this.selected = next;
        this.result = null;
      },

      /* ===== presentational helpers ===== */
      statusLabel(value) { return statusLabel(value); },
      statusBadgeClass(value) { return statusBadgeClass(value); },

      /* INV-5: the toast renders `lead`/`body` via x-text (plain strings, never
         x-html) so a server error string can never be an HTML sink. */
      toast(kind, lead, body) {
        const id = ++this.toastSeq;
        this.toasts.push({ id, kind, lead: lead || '', body: body || '' });
        setTimeout(() => { this.toasts = this.toasts.filter((t) => t.id !== id); }, 4200);
      },
    };
  }

  return {
    cohort,
    cohortExtrasOptionsFrom,
    rosterActionsForStates,
    ROSTER_ACTIONS,
  };
});
