/* ==========================================================================
   Stride Admin Workspace — Cohort lens slideover (Cluster G)
   --------------------------------------------------------------------------
   The per-edition roster overlay. Opened from an edition row (the Edities
   surface dispatches a `ws-cohort-open` window event with { editionId }); this
   component listens and slides a right-anchored panel over the current surface.

   READ + ATTENDANCE ONLY (decision 5a, F-C1): the roster bulk bar was REMOVED.
   The cohort roster is confirmed/completed only (CR-1), so the one lifecycle
   action (approve — pending/interest/waitlist) could never appear, and the
   remaining catalog entries were stubs failing every row. Lifecycle work lives
   on the Inschrijvingen grid; the lens is the per-session view + attendance
   marker. (The RosterBulkHandler backend registry is CURRENTLY UNCONSUMED —
   no surface POSTs stride_roster_bulk_* / stride_traj_roster_bulk_* anymore,
   and their nonces are no longer armed in StrideConfig.bulkNonces, which makes
   the handlers unreachable by design. Re-arm the specific action if a surface
   adopts the registry.)

   BACKEND — consumed exactly as emitted:

     GET /admin/editions/{id}          → { title, sessions:[{id,title,date,...}], ... }
     GET /admin/editions/{id}/roster   → { edition_id, rows:[{ registration_id,
            user_id, name, organisation, status, is_anonymised,
            selections:[<session ids, server-resolved via INV-6b>],
            attendance:{present,absent,excused},
            attendance_by_session:{<sessionId>: 'present'|'absent'|'excused'},
            extras:{<key>:<scalar>} }],
            extras_keys:[<key>] }
     POST /admin/attendance            → { session_id, user_id, status ''=clear }

   Attendance marking is OPTIMISTIC (F-C2): the row's per-session map is
   patched locally (aggregates recomputed from the same map — the server
   derives its counts from the identical deduped map, so the two stay one
   definition) and rolled back if the POST fails. No roster refetch per mark —
   twenty marks used to mean twenty full reloads with the scroll lost.

   MUTATION PROPAGATION (F-C3): any successful mark flags the lens dirty; on
   close it dispatches `ws-refresh` for the surfaces whose numbers attendance
   can change (edities / inschrijvingen / vandaag), so the view underneath
   never keeps pre-mutation data.

   INV-5: every x-html in the markup binds a CONSTANT icon name via
   icon('<literal>'); data renders via x-text (auto-escaped). INV-6b:
   rows[].selections are the session ids the SERVER resolved — the session
   filter matches against them, NEVER a client-side raw-column decode.
   INV-7: status + is_anonymised render AS RECEIVED.

   The branching pure helpers (the loaded-set extras-option builder and the
   per-row mark applier) are exported (UMD tail) so the Tier-A unit test
   imports them without a browser; the browser path attaches the factory.
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
    root.WS.cohortApplyMark = api.cohortApplyMark;
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

  /* Per-session mark labels (the attendance cell when a session is active). */
  const MARK_LABEL = {
    present: 'Aanwezig',
    absent:  'Afwezig',
    excused: 'Verontschuldigd',
  };

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

  /* ---- optimistic mark applier (F-C2, PURE, Tier-A) ------------------------
     Returns a NEW row with the per-session map patched (status '' deletes the
     mark) and the aggregate counts recomputed from that map — the SAME
     definition the server uses (counts derived from the deduped latest-wins
     map), so an optimistic patch and the next full fetch can never disagree. */
  function cohortApplyMark(row, sessionId, status) {
    const map = { ...((row && row.attendance_by_session) || {}) };
    const key = String(sessionId);
    if (status) {
      map[key] = status;
    } else {
      delete map[key];
    }
    const agg = { present: 0, absent: 0, excused: 0 };
    Object.values(map).forEach((s) => {
      if (agg[s] != null) agg[s]++;
    });
    return { ...row, attendance_by_session: map, attendance: agg };
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

      /* a mark was attempted — close() then refreshes the surfaces
         underneath (F-C3). Set BEFORE the POST awaits: close() reads it
         synchronously, and a mark still in flight at close time must not
         skip the refresh (a spurious refresh is cheap; a missed one leaves
         stale numbers). */
      _mutated: false,

      /* registration_id -> true while that row's mark POST is in flight —
         the per-row guard that makes rapid clicks safe (buttons disable). */
      _marking: {},

      toasts: [],
      toastSeq: 0,

      get canManage() { return !!(window.StrideConfig || {}).canManage; },

      /* Open the lens for an edition (relayed from the Edities surface via the
         `ws-cohort-open` window event). Resets all state, then fetches the
         edition detail (sessions + title) and the roster together. BOTH must
         succeed (F-C4): the old detail .catch(() => ({})) silently produced a
         sessionless lens where attendance marking was impossible with no
         signal — a failed detail now lands in the error + retry state. */
      async openForEdition(editionId) {
        const id = Number(editionId) || 0;
        if (!id) return;
        this.resetState();
        this.editionId = id;
        this.open = true;
        this.loading = true;
        try {
          const [detail, roster] = await Promise.all([
            this.api(`/admin/editions/${id}`),
            this.api(`/admin/editions/${id}/roster`),
          ]);
          this.title = (detail && detail.title) || '';
          this.sessions = (detail && detail.sessions) || [];
          this.rows = ((roster && roster.rows) || []).map((r) => ({
            ...r,
            selections: Array.isArray(r.selections) ? r.selections : [],
            attendance: r.attendance || { present: 0, absent: 0, excused: 0 },
            attendance_by_session: r.attendance_by_session || {},
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
        this._mutated = false;
        this._marking = {};
      },

      /* Close + propagate (F-C3): attendance marks change numbers the
         surfaces underneath display (occupancy/queues/agenda counts) — their
         templates all listen for ws-refresh with their view name. */
      close() {
        const mutated = this._mutated;
        this.open = false;
        this.resetState();
        this.editionId = 0;
        if (mutated) {
          ['edities', 'inschrijvingen', 'vandaag'].forEach((view) => {
            window.dispatchEvent(new CustomEvent('ws-refresh', { detail: { view } }));
          });
        }
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
      },
      sessionChipLabel(s) {
        return s.title || s.date || ('Sessie ' + s.id);
      },

      /* ===== extras (CF3 — loaded-set ONLY) ===== */
      get extrasOptions() { return cohortExtrasOptionsFrom(this.rows); },
      setExtrasFilter(token) {
        this.extrasFilter = this.extrasFilter === token ? '' : token;
      },
      clearExtrasFilter() { this.extrasFilter = ''; },
      /* Compact per-row extras summary (F-C4 — the keys were fetched but only
         ever rendered as filter chips, never on the rows they describe). */
      extrasSummary(row) {
        return Object.entries((row && row.extras) || {})
          .map(([k, v]) => `${k}: ${v}`)
          .join(' · ');
      },

      /* ===== attendance (F-C2 — per-session state, optimistic) ===== */
      aggregateLabel(att) {
        const a = att || {};
        const total = (a.present || 0) + (a.absent || 0) + (a.excused || 0);
        if (total === 0) return '—';
        return `${a.present || 0}/${total} aanwezig`;
      },
      /* The current mark for the ACTIVE session ('' = unmarked). */
      markFor(row) {
        if (!this.sessionId) return '';
        return ((row && row.attendance_by_session) || {})[String(this.sessionId)] || '';
      },
      /* The attendance cell: the active session's own state when a session is
         selected (that is the question the admin is answering); the
         cross-session aggregate otherwise. */
      attendanceCellLabel(row) {
        if (this.sessionId) {
          return MARK_LABEL[this.markFor(row)] || '—';
        }
        return this.aggregateLabel(row && row.attendance);
      },
      get canMarkAttendance() {
        return !!(this.canManage && this.sessionId);
      },
      isMarking(row) {
        return !!(row && this._marking[row.registration_id]);
      },
      /* Takes the ROW, never a bare user id: rows are keyed by
         registration_id (a user CAN hold two cohort rows — the registrations
         table deliberately has no UNIQUE(user_id, edition_id)), so a
         user-id lookup would route every click to the first row. */
      async markAttendance(row, status) {
        if (!this.canMarkAttendance || !row) return;
        const regId = row.registration_id;
        if (this._marking[regId]) return; // one in-flight mark per row
        const sessionId = Number(this.sessionId);
        const editionAtSend = this.editionId;
        const idx = this.rows.findIndex((r) => r.registration_id === regId);
        if (idx === -1) return;

        // Toggle semantics: clicking the row's CURRENT mark clears it (the
        // dedicated clear button passes '' explicitly).
        const current = this.markFor(this.rows[idx]);
        const next = status && status === current ? '' : status;

        // Optimistic patch + rollback snapshot (this row only — a concurrent
        // mark on another row must never be clobbered).
        const previous = this.rows[idx];
        this.rows.splice(idx, 1, cohortApplyMark(previous, sessionId, next));
        this._marking[regId] = true;
        this._mutated = true; // before the await — close() reads it sync (F-C3)

        try {
          await this.api('/admin/attendance', {
            method: 'POST',
            body: JSON.stringify({ session_id: sessionId, user_id: Number(row.user_id), status: next }),
          });
        } catch (e) {
          // Roll back JUST this row — and ONLY if the lens still shows the
          // same edition (a late failure after close/reopen must never splice
          // another edition's row object into this roster).
          if (this.editionId === editionAtSend) {
            const at = this.rows.findIndex((r) => r.registration_id === regId);
            if (at !== -1) this.rows.splice(at, 1, previous);
            this.toast('mixed', '', 'Aanwezigheid opslaan mislukt.');
          }
        } finally {
          delete this._marking[regId];
        }
      },

      /* ===== per-edition exports (F-A9/F-E3) =====
         The FIVE existing exporters — the endpoints and their server-side
         type allowlist (CM-4) predate this; the lens is their first
         workspace affordance. Via the shared WS.download (header-auth fetch
         + blob): an expired nonce fails SOFT as a toast; a ?_wpnonce
         navigation replaced the whole workspace with a raw JSON 403 after an
         overnight tab. stride_manage gated server-side (PII egress). */
      async exportFile(type) {
        try {
          await window.WS.download(`/admin/editions/${this.editionId}/export/${type}`);
        } catch (e) {
          this.toast('mixed', '', (e && e.message) || 'Export mislukt.');
        }
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
    cohortApplyMark,
  };
});
