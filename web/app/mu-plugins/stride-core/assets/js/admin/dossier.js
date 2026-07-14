/* ==========================================================================
   Stride Admin Workspace — Dossier surface (Cluster D)
   --------------------------------------------------------------------------
   The per-person case view. This factory OWNS loading ALL of its own data in
   init(): it reads ?user=<id> from the URL (the Vandaag/grid deep-link via the
   shell's switchView('dossier', {user})), then loads BOTH endpoints in
   parallel — GET /admin/users/{id}/detail and GET /admin/users/{id}/trajectories
   — each with its own loading / empty / error state (a failed trajectory load
   never blanks the registrations, and vice-versa; AF-3 mid-flow edge).

   Backend shape (see AdminUserService::getUserDetail):
     GET /admin/users/{id}/detail
        { user, registrations:[{ id, edition_id, trajectory_id, is_trajectory,
            edition_title, status,
            enrollment_path, registered_at, completed_at, cancelled_at?,
            attendance:{present,absent,excused,total_sessions,hours}|null,
            tasks:[{type,label,status,completed_at,phase}],
            stages:{<key>:{submitted_at,submitted_by,data:{label:value}}|null},
            selections:[<resolved label strings>], notes,
            offerte_status, offerte_status_label }],
          registrations_total, reg_page, reg_per_page, quotes (gated), attendance,
          audit_trail:[{id,type,text,target_url,actor_name,timestamp}] (GATED — only
            present for canSeeSensitive viewers; absent/empty → locked timeline),
          audit_trail_total }
     GET /admin/users/{id}/trajectories
        per-trajectory { trajectory:{id,title,status,mode}, completed_count,
          in_progress_count, total_required, required_courses:[{title,edition,state}],
          elective_groups:[{name,required,total,countChosen,isChosen,chosen:[{title,edition}]}] }

   INV-5: every x-html in dossier.php binds a CONSTANT icon name via
   icon('<literal>') / icon(<closed-enum field>); never a data field. The three
   mappers below only ever assign icon names from a fixed table, so the markup's
   ev.icon / ev.dot are closed-enum values, not free text. Person/edition/note/
   stage-data/selection/timeline-text all render via x-text (auto-escaped).
   INV-6b: `selections` are SERVER-resolved label strings — rendered, never
   parsed client-side. INV-7: status/offerte labels render AS RECEIVED.

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
    root.dossier = api.dossier;
    root.WS = root.WS || {};
    root.WS.auditToTimelineEvent = api.auditToTimelineEvent;
    root.WS.timelineForReg = api.timelineForReg;
    root.WS.avatarColor = root.WS.avatarColor || api.avatarColor;
    root.WS.initials = root.WS.initials || api.initials;
  }
})(typeof window !== 'undefined' ? window : this, function () {
  'use strict';

  /* ---- avatar helpers (pure — shared shape with vandaag.js / grid.js) ---- */
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

  /* ---- canonical stage order (the enrollment lifecycle, not object order) -- */
  const STAGE_ORDER = ['interest', 'waitlist', 'enrollment_personal', 'enrollment_billing', 'initial_selection', 'intake', 'evaluation'];

  /* human-readable stage names + a one-line "what this stage is" (closed-enum,
     keyed by the server stage key). icon values are INV-5 constants. */
  const STAGE_META = {
    interest:            { name: 'Interesse',                       icon: 'sparkle',  desc: 'Eerste interesse, vóór inschrijving.' },
    waitlist:            { name: 'Wachtlijst',                      icon: 'seat',     desc: 'Aangemeld op de wachtlijst.' },
    enrollment_personal: { name: 'Inschrijving — persoonsgegevens', icon: 'user',     desc: 'Persoonsgegevens ingevuld bij inschrijving.' },
    enrollment_billing:  { name: 'Inschrijving — facturatie',       icon: 'receipt',  desc: 'Facturatiegegevens ingevuld bij inschrijving.' },
    initial_selection:   { name: 'Initiële sessiekeuze',            icon: 'route',    desc: 'Keuze van sessies/keuzemodules bij inschrijving.' },
    intake:              { name: 'Intakevragenlijst',               icon: 'fileText', desc: 'Vragenlijst ingevuld na bevestiging (de "Intakevragen"-taak).' },
    evaluation:          { name: 'Evaluatie (na afloop)',           icon: 'award',    desc: 'Eindevaluatie ingevuld na de cursus.' },
  };

  /* ---- offerte LABEL → CSS modifier key (PURE) ----
     The offerte label is rendered AS RECEIVED (INV-7); this maps it to a
     closed-enum color class ONLY. Unknown/empty → 'none'. */
  const OFFERTE_CLASS = {
    'Geen offerte':   'none',
    'In behandeling': 'draft',
    'Verzonden':      'sent',
    'Verwerkt':       'exported',
  };
  function offerteClass(label) {
    return OFFERTE_CLASS[String(label || '')] || 'none';
  }

  /* ---- status → Dutch badge label + CSS class (closed-enum, by status value) */
  const STATUS_META = {
    interest:  { label: 'Interesse',     cls: 'interest' },
    waitlist:  { label: 'Wachtlijst',    cls: 'waitlist' },
    pending:   { label: 'In afwachting', cls: 'pending' },
    confirmed: { label: 'Bevestigd',     cls: 'confirmed' },
    completed: { label: 'Afgerond',      cls: 'completed' },
    cancelled: { label: 'Geannuleerd',   cls: 'cancelled' },
  };
  function statusMeta(status) {
    return STATUS_META[status] || { label: String(status || ''), cls: 'pending' };
  }

  /* ---- state-appropriate actions for a single registration status --------
     States MUST match the server transition map (RegistrationTransitions):
     Interest → Pending | Cancelled only — there is NO Interest → Confirmed, so
     "Goedkeuren" (approve→confirmed) is NOT a valid action on an interest row.
     An interest registration is for a course with no planned edition yet; there
     is nothing to approve until it becomes a real (pending) enrollment. Its only
     actions are messaging + cancelling. (grid.js + vandaag.js already scope
     approve to 'pending' — this aligns the dossier panel with them.) */
  const SMART_ACTIONS = [
    { id: 'stride_approve',           label: 'Goedkeuren',               icon: 'checkCircle', states: ['pending'] },
    { id: 'stride_promote_waitlist',  label: 'Promoveer van wachtlijst', icon: 'arrowUp',     states: ['waitlist'] },
    { id: 'stride_quote_sent',        label: 'Offerte verzonden',        icon: 'send',        states: ['confirmed'] },
    { id: 'stride_quote_exported',    label: 'Offerte verwerkt',         icon: 'checkCircle', states: ['confirmed'] },
    { id: 'stride_message',           label: 'Bericht sturen',           icon: 'mail',        states: ['confirmed', 'completed', 'interest', 'pending', 'waitlist'] },
    { id: 'stride_generate_doc',      label: 'Document genereren',       icon: 'fileText',    states: ['completed'] },
    { id: 'stride_cancel',            label: 'Annuleren',                icon: 'xCircle',     states: ['pending', 'interest', 'confirmed', 'waitlist'], danger: true },
  ];
  function actionsForState(state) {
    return SMART_ACTIONS.filter((a) => a.states.includes(state));
  }

  /* ======================================================================
     MAPPER 1 (Tier A) — audit entry → timeline event
     ----------------------------------------------------------------------
     Real D.1 entry  { id, type, text, target_url, actor_name, timestamp }
       → mockup event { id, dot, icon, title, actor, when }.
     icon/dot come from CONSTANT maps keyed by `type` (INV-5 — never the data
     value itself). An UNKNOWN type falls through to the default icon+dot; the
     unknown value is never echoed as an icon name. `when` is a Dutch-formatted
     timestamp (the entry carries epoch SECONDS). title←text, actor←actor_name.
     ====================================================================== */
  const TYPE_ICON = {
    enrollment: 'route',
    attendance: 'userCheck',
    completion: 'award',
    quote:      'receipt',
    user:       'user',
    edition:    'layers',
    auth:       'user',
    action:     'clock',
  };
  const TYPE_DOT = {
    enrollment: 'primary',
    attendance: 'success',
    completion: 'success',
    quote:      'warning',
    user:       'default',
    edition:    'default',
    auth:       'default',
    action:     'default',
  };
  const MONTHS_NL = ['jan', 'feb', 'mrt', 'apr', 'mei', 'jun', 'jul', 'aug', 'sep', 'okt', 'nov', 'dec'];

  /* Dutch-format an epoch-SECONDS timestamp → "27 mei 2026 · 11:40". */
  function formatWhen(epochSeconds) {
    const n = Number(epochSeconds);
    if (!n || isNaN(n)) return '';
    const d = new Date(n * 1000);
    if (isNaN(d.getTime())) return '';
    const day = d.getDate();
    const mon = MONTHS_NL[d.getMonth()] || '';
    const year = d.getFullYear();
    const hh = String(d.getHours()).padStart(2, '0');
    const mm = String(d.getMinutes()).padStart(2, '0');
    return `${day} ${mon} ${year} · ${hh}:${mm}`;
  }

  function auditToTimelineEvent(entry) {
    const e = entry || {};
    const type = e.type || '';
    return {
      id: e.id != null ? e.id : null,
      icon: TYPE_ICON[type] || 'clock',     // INV-5: constant fallback, never the data value
      dot: TYPE_DOT[type] || 'default',
      title: String(e.text || ''),
      actor: String(e.actor_name || ''),
      when: formatWhen(e.timestamp),
      url: String(e.target_url || ''),
    };
  }

  /* ======================================================================
     MAPPER 2 (Tier A) — per-registration timeline filter
     ----------------------------------------------------------------------
     The emitted audit entry carries NO top-level edition_id/registration_id;
     D.1 surfaces the edition ONLY via target_url (post.php?post={editionId}).
     So we attribute an event to a registration by the edition id parsed from
     its target_url, matched against reg.edition_id. Events with no resolvable
     edition (auth, user, edition-management) are reg-agnostic and show for
     EVERY reg. Returns mapped timeline-event shape (not raw entries).
     ====================================================================== */
  function editionIdFromUrl(url) {
    const m = /[?&]post=(\d+)/.exec(String(url || ''));
    return m ? Number(m[1]) : 0;
  }
  function timelineForReg(events, reg) {
    if (!Array.isArray(events) || !reg) return [];
    const editionId = Number(reg.edition_id) || 0;
    return events
      .filter((e) => {
        const evEdition = editionIdFromUrl(e && e.target_url);
        // reg-agnostic event (no edition) shows for every reg; otherwise it must
        // match THIS reg's edition (the scoping/denial branch).
        return evEdition === 0 || evEdition === editionId;
      })
      .map(auditToTimelineEvent);
  }

  /* (The former MAPPER 3 — a client-derived completion checklist with its own
     invented rules, e.g. "Aanwezigheid ≥ 80%" — is GONE. The server now emits
     the registration's REAL completion_tasks (`r.tasks`: type/label/status/
     completed_at from EnrollmentCompletion), and the template renders that
     list directly. Deriving a parallel checklist client-side was F-D4.) */

  /* ======================================================================
     Status-relevance gate (Tier A) — which detail sections are meaningful
     ----------------------------------------------------------------------
     Aanwezigheid / Gekozen sessies / Voltooiingstaken describe the FULFILLMENT
     of an active enrollment. waitlist/interest are pre-fulfillment and
     cancelled is terminal — none has attendance, session choices, or
     completion progress to show, so the template gates those three sections
     behind showsFulfillment() and renders a status-appropriate muted line
     instead. (Ingediende gegevens + Notities stay visible for ALL statuses.)
     ====================================================================== */
  const FULFILLMENT_STATES = ['pending', 'confirmed', 'completed'];
  function showsFulfillment(status) {
    return FULFILLMENT_STATES.includes(status);
  }
  /* the muted line shown for non-fulfillment statuses (closed-enum Dutch copy). */
  const FULFILLMENT_EMPTY_HINT = {
    waitlist:  'Op de wachtlijst — nog geen aanwezigheid of voltooiing.',
    interest:  'Interesse — nog niet ingeschreven.',
    cancelled: 'Geannuleerd — geen voortgang.',
  };
  function fulfillmentEmptyHint(status) {
    return FULFILLMENT_EMPTY_HINT[status] || 'Geen voortgang om te tonen voor deze status.';
  }

  /* ---- trajectory section helpers (§11.4) — pure presentational --------- */
  const TRAJ_STATUS = {
    draft:       { label: 'Concept',      cls: 'completed' },
    open:        { label: 'Open',         cls: 'confirmed' },
    full:        { label: 'Volzet',       cls: 'waitlist' },
    closed:      { label: 'Afgesloten',   cls: 'pending' },
    archived:    { label: 'Gearchiveerd', cls: 'cancelled' },
    in_progress: { label: 'Bezig',        cls: 'interest' },
    completed:   { label: 'Afgerond',     cls: 'completed' },
  };
  const TRAJ_MODE = { cohort: 'Cohorte', self_paced: 'Zelfstandig tempo' };
  const COURSE_STATE_LABEL = { afgerond: 'Afgerond', bezig: 'Bezig', 'nog te volgen': 'Nog te volgen' };
  const COURSE_STATE_CLASS = { afgerond: 'done', bezig: 'active', 'nog te volgen': 'upcoming' };

  /* ---- the Alpine factory ------------------------------------------------ */
  function dossier() {
    return {
      stageMeta: STAGE_META,
      stageOrder: STAGE_ORDER,

      // server-loaded state
      person: null,
      regs: [],
      trajectories: [],
      auditTrail: [],
      canSeeTimeline: true,    // false when audit_trail is gated off (PII N1)
      timelineReg: 0,
      openStages: {},

      // reload bookkeeping: which ?user=/?reg= target the current data belongs
      // to, and a monotonically increasing load token so an older in-flight
      // response can never overwrite a newer one (person A resolving after a
      // faster person B would otherwise show A's dossier under B's URL).
      loadedKey: null,
      loadSeq: 0,
      userId: 0,

      // registrations pagination (server pages at 20; regsTotal is the TRUE
      // count — the section chip and the "Toon meer" affordance read it).
      regsTotal: 0,
      regPerPage: 20,
      loadingMore: false,

      // per-block load state — a failed block shows its OWN error, never blanks
      loading: { detail: true, trajectories: true },
      errors: { detail: '', trajectories: '' },

      // per-row action feedback (keyed by registration id) — a brief inline
      // confirmation/error shown under that registration's action row. The
      // ENDPOINT is the security boundary; this is presentational feedback only.
      actionBusy: 0,            // registration id currently running, 0 = idle
      actionFeedback: {},       // { [regId]: { kind: 'ok'|'err', text } }

      get hasRegs() { return this.regs.length > 0; },

      /* Mirror of grid.js:443 — true to the proven god-component bulkApi(): POST
         to the ntdst/v1/action registry (envelope {success,data}), distinct from
         the shell api() (stride/v1). Carries the per-action nonce armed via
         StrideConfig.bulkNonces. The two surfaces are separate Alpine components,
         so the dossier owns its own copy rather than refactoring grid.js. */
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
          throw new Error((json && json.data && json.data.message) || 'Actie mislukt.');
        }
        return json.data;
      },

      /* init() — the dossier owns its FULL activation lifecycle instead of the
         shared one-shot wsLazyLoad latch. The latch is right for the list
         surfaces (load once, keep), but the dossier's TARGET changes with every
         "row → dossier" click (?user=/?reg=), so a load-once latch showed the
         PREVIOUS person's data under the new URL from the second use onward.
         Every activation reloads: a changed target gets a fresh spinner + reset,
         a same-target return gets a soft refresh (grid/cohort mutations may have
         changed the data underneath). load() self-guards via loadedKey/loadSeq,
         so activation events can never double-render or race. */
      init() {
        // Active on mount (deep-link ?view=dossier&user=…) → cold load.
        if (this.view === 'dossier') {
          this.load();
        }
        window.addEventListener('ws-view-changed', (e) => {
          if (e && e.detail && e.detail.view === 'dossier') {
            this.load();
          }
        });
      },

      load() {
        const params = new URLSearchParams(window.location.search);
        const userId = Number(params.get('user')) || 0;
        // Optional deep-link to a SPECIFIC registration (e.g. from a Vandaag
        // "Wacht op mij" row). When present we open/select that reg instead of
        // defaulting to the newest one; 0 = no preference, fall back to regs[0].
        const wantReg = Number(params.get('reg')) || 0;

        // Target change (a DIFFERENT person/registration than the loaded one)
        // → full reset + spinner, so the old person can never linger under the
        // new URL. Same target → soft refresh: keep the rendered dossier (and
        // the open/collapsed state) while fresh data loads underneath.
        const key = userId + ':' + wantReg;
        const targetChanged = key !== this.loadedKey;
        this.loadedKey = key;
        const seq = ++this.loadSeq;

        if (targetChanged) {
          this.loading.detail = true;
          this.loading.trajectories = true;
          this.person = null;
          this.regs = [];
          this.trajectories = [];
          this.auditTrail = [];
          this.openStages = {};
          this.actionFeedback = {};
        }
        // Reset error banners so a successful retry recovers cleanly
        // (cluster-B lesson — a stale error must not survive a now-good load).
        this.errors.detail = '';
        this.errors.trajectories = '';

        if (!userId) {
          this.loading.detail = false;
          this.loading.trajectories = false;
          this.errors.detail = 'Geen gebruiker geselecteerd.';
          return;
        }

        this.userId = userId;

        // Preserve which registrations are expanded across a SOFT refresh (an
        // action just ran; collapsing everything would lose the admin's place).
        const prevOpen = targetChanged
          ? {}
          : Object.fromEntries(this.regs.map((r) => [r.id, r.open]));

        // Soft refresh re-fetches everything already loaded (clamped at the
        // server's 100 cap) so "Toon meer" pages survive an action refresh.
        const perPage = targetChanged
          ? this.regPerPage
          : Math.min(100, Math.max(this.regPerPage, this.regs.length));

        Promise.allSettled([
          this.api(`/admin/users/${userId}/detail?reg_per_page=${perPage}`),
          this.api(`/admin/users/${userId}/trajectories`),
        ]).then(([detail, trajectories]) => {
          // A newer load() superseded this one (fast target switch) — drop it.
          if (seq !== this.loadSeq) {
            return;
          }

          // ---- detail: person + registrations + audit timeline ----
          if (detail.status === 'fulfilled') {
            const d = detail.value || {};
            this.person = d.user || null;
            const allRegs = d.registrations || [];
            // Honor the ?reg= deep-link if it matches a registration; otherwise
            // fall back to the first (newest, registered_at DESC from the server).
            const wantIdx = wantReg
              ? allRegs.findIndex((r) => Number(r.id) === wantReg)
              : -1;
            const openIdx = wantIdx >= 0 ? wantIdx : 0;
            this.regs = allRegs.map((r, i) => ({
              ...r,
              open: prevOpen[r.id] !== undefined ? prevOpen[r.id] : i === openIdx,
            }));
            this.regsTotal = Number(d.registrations_total) || allRegs.length;
            // audit_trail is GATED — absent/empty means the viewer can't see it
            // (PII N1) OR there's simply no history. Either way: locked/empty
            // timeline, never a crash.
            this.auditTrail = Array.isArray(d.audit_trail) ? d.audit_trail : [];
            this.canSeeTimeline = Array.isArray(d.audit_trail);
            const keepTimeline = !targetChanged
              && this.regs.some((r) => r.id === this.timelineReg);
            this.timelineReg = keepTimeline
              ? this.timelineReg
              : (this.regs.length ? this.regs[openIdx].id : 0);
          } else {
            this.errors.detail = 'Kon het dossier niet laden.';
          }
          this.loading.detail = false;

          // ---- trajectories: own block, own error (AF-3) ----
          // The endpoint wraps the list in { trajectories: [...] } (ground-truthed
          // against the live route) — NOT a bare array. A non-trajectory user
          // gets { trajectories: [] } → the section is absent (F8 empty edge).
          if (trajectories.status === 'fulfilled') {
            const t = trajectories.value || {};
            this.trajectories = Array.isArray(t.trajectories) ? t.trajectories
              : (Array.isArray(t) ? t : []);
          } else {
            this.errors.trajectories = 'Kon de trajecten niet laden.';
          }
          this.loading.trajectories = false;
        });
      },

      /* whether more registrations exist beyond the loaded pages */
      get hasMoreRegs() { return this.regs.length < this.regsTotal; },

      /* Append the next page of registrations (server pages at regPerPage,
         registered_at DESC). Deduped by id so an overlapping page can never
         duplicate a row. */
      async loadMoreRegs() {
        if (this.loadingMore || !this.userId || !this.hasMoreRegs) {
          return;
        }
        this.loadingMore = true;
        const nextPage = Math.floor(this.regs.length / this.regPerPage) + 1;
        try {
          const d = await this.api(
            `/admin/users/${this.userId}/detail?reg_page=${nextPage}&reg_per_page=${this.regPerPage}`,
          );
          const seen = new Set(this.regs.map((r) => r.id));
          const extra = (d.registrations || [])
            .filter((r) => !seen.has(r.id))
            .map((r) => ({ ...r, open: false }));
          this.regs = this.regs.concat(extra);
          this.regsTotal = Number(d.registrations_total) || this.regsTotal;
        } catch (e) {
          // Non-fatal: the loaded dossier stays; the button remains for retry.
        } finally {
          this.loadingMore = false;
        }
      },

      /* the registration currently selected in the timeline <select> */
      get activeReg() {
        return this.regs.find((r) => r.id === this.timelineReg) || this.regs[0] || null;
      },
      /* the per-registration timeline (mapper 2), mapped to event shape */
      get activeTimeline() {
        return timelineForReg(this.auditTrail, this.activeReg);
      },

      /* only stages WITH submitted data, in canonical order (empty hidden). */
      submittedStages(r) {
        const stages = (r && r.stages) || {};
        return STAGE_ORDER
          .filter((key) => {
            const s = stages[key];
            return s && s.data && Object.keys(s.data).length > 0;
          })
          .map((key) => ({ key, stage: stages[key], meta: STAGE_META[key] || { name: key, icon: 'fileText', desc: '' } }));
      },
      toggleStage(regId, key) {
        const k = regId + '-' + key;
        this.openStages[k] = !this.openStages[k];
      },
      isStageOpen(regId, key) { return !!this.openStages[regId + '-' + key]; },

      /* turn a stored field key into a readable label (snake_case → words). */
      humanizeKey(key) {
        const s = String(key || '');
        if (!/[_a-z]/.test(s) || /\s/.test(s)) return s;
        const t = s.replace(/_/g, ' ').replace(/\s+/g, ' ').trim();
        return t.charAt(0).toUpperCase() + t.slice(1);
      },

      /* status-relevance gate + the muted line for non-fulfillment statuses */
      showsFulfillment(status) { return showsFulfillment(status); },
      fulfillmentEmptyHint(status) { return fulfillmentEmptyHint(status); },

      /* attendance present/total ratio for a reg, as "N/M" microcopy */
      attSummary(r) {
        const a = r && r.attendance;
        if (!a || !a.total_sessions) return '';
        return `${a.present || 0}/${a.total_sessions} aanwezig`;
      },

      /* presentational helpers (all closed-enum / AS-RECEIVED) */
      statusMeta(status) { return statusMeta(status); },
      offerteClass(label) { return offerteClass(label); },
      actionsFor(state) { return actionsForState(state); },

      /* Map a dossier SMART_ACTION id → the gated bulk action id and dispatch it
         for this single registration via ids:[r.id]. Scope is the waitlist-promote
         feature: only `stride_promote_waitlist` → `stride_bulk_promote_waitlist`
         (the dossier id intentionally lacks `bulk_`; mirror grid.js:145). Other
         ids are no-ops here (still presentational) until they get wired. The
         bulk endpoint re-checks `stride_manage`, so a view-only user who somehow
         sees the button gets a 403 — the endpoint is the security boundary, not
         the button's visibility. */
      async runSmartAction(a, r) {
        const BULK_ID = { stride_promote_waitlist: 'stride_bulk_promote_waitlist' };
        const bulkId = a && BULK_ID[a.id];
        if (!bulkId || !r || !r.id || this.actionBusy) return;

        this.actionBusy = r.id;
        this.actionFeedback = { ...this.actionFeedback, [r.id]: null };
        try {
          const report = await this.bulkApi(bulkId, { ids: [r.id] });
          const failed = (report && report.failed) || [];
          const mine = failed.find((f) => Number(f.id) === Number(r.id));
          if (mine) {
            // The endpoint returns a per-row reason (e.g. lead_no_email,
            // capacity_full) — surface it, do not swallow it.
            this.actionFeedback = {
              ...this.actionFeedback,
              [r.id]: { kind: 'err', text: mine.message || 'Promoveren mislukt.' },
            };
          } else {
            this.actionFeedback = {
              ...this.actionFeedback,
              [r.id]: { kind: 'ok', text: 'Gepromoveerd van wachtlijst.' },
            };
            // Refresh the dossier so the row's new `confirmed` status shows.
            this.load();
          }
        } catch (e) {
          this.actionFeedback = {
            ...this.actionFeedback,
            [r.id]: { kind: 'err', text: (e && e.message) || 'Promoveren mislukt.' },
          };
        } finally {
          this.actionBusy = 0;
        }
      },
      trajStatus(s) { return TRAJ_STATUS[s] || { label: String(s || ''), cls: 'completed' }; },
      trajMode(m) { return TRAJ_MODE[m] || String(m || ''); },
      courseStateLabel(state) { return COURSE_STATE_LABEL[state] || String(state || ''); },
      courseStateClass(state) { return COURSE_STATE_CLASS[state] || 'upcoming'; },
      trajProgressPct(t) { return t && t.total_required ? Math.round((t.completed_count / t.total_required) * 100) : 0; },
      trajTodo(t) { return t ? Math.max(0, t.total_required - t.completed_count - t.in_progress_count) : 0; },

      /* crude actor-type heuristic for the timeline icon (admin vs the user) */
      actorIsAdmin(actor) { return /co[öo]rdinator|beheer|systeem|admin/i.test(actor || ''); },

      avatarColor(name) { return avatarColor(name); },
      initials(name) { return initials(name); },

      /* back to the grid */
      backToGrid() { this.switchView('inschrijvingen'); },
    };
  }

  return {
    dossier,
    auditToTimelineEvent,
    timelineForReg,
    showsFulfillment,
    fulfillmentEmptyHint,
    offerteClass,
    statusMeta,
    actionsForState,
    avatarColor,
    initials,
    formatWhen,
  };
});
