/* ==========================================================================
   Stride Admin Workspace — Dossier surface (Cluster D)
   --------------------------------------------------------------------------
   The per-person case view. This factory OWNS loading ALL of its own data in
   init(): it reads ?user=<id> from the URL (the Vandaag/grid deep-link via the
   shell's switchView('dossier', {user})), then loads BOTH endpoints in
   parallel — GET /admin/users/{id}/detail and GET /admin/users/{id}/trajectories
   — each with its own loading / empty / error state (a failed trajectory load
   never blanks the registrations, and vice-versa; AF-3 mid-flow edge).

   Backend FROZEN — consumed exactly as Phase-1/D.1 emit it:
     GET /admin/users/{id}/detail
        { user, registrations:[{ id, edition_id, edition_title, status,
            enrollment_path, registered_at, completed_at, cancelled_at?,
            attendance:{present,absent,excused,total_sessions,hours}|null,
            stages:{<key>:{submitted_at,submitted_by,data:{label:value}}|null},
            selections:[<resolved label strings>], notes,
            offerte_status, offerte_status_label }],
          registrations_total, quotes (gated), attendance,
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
    root.WS.completionChecklist = api.completionChecklist;
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

  /* ---- state-appropriate actions for a single registration status -------- */
  const SMART_ACTIONS = [
    { id: 'stride_approve',           label: 'Goedkeuren',               icon: 'checkCircle', states: ['pending', 'interest'] },
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

  /* ======================================================================
     MAPPER 3 (Tier A) — completion checklist derivation
     ----------------------------------------------------------------------
     Derived from data already on the registration (no extra fetch):
       • Goedkeuring inschrijving : status !== 'pending'
       • Intake ingevuld          : !!stages.intake.submitted_at
       • Aanwezigheid ≥ 80%       : present / total_sessions >= 0.8
       • Eindevaluatie            : !!stages.evaluation.submitted_at
     A reg with no stages / no attendance → all but approval false (empty branch).
     ====================================================================== */
  function stageSubmitted(stages, key) {
    const s = stages && stages[key];
    return !!(s && s.submitted_at);
  }
  function completionChecklist(reg) {
    const r = reg || {};
    const stages = r.stages || {};
    const att = r.attendance || null;
    const present = att ? Number(att.present) || 0 : 0;
    const totalSessions = att ? Number(att.total_sessions) || 0 : 0;
    const attendanceMet = totalSessions > 0 && (present / totalSessions) >= 0.8;

    return [
      { label: 'Goedkeuring inschrijving', done: ['confirmed', 'completed'].includes(r.status) },
      { label: 'Intake ingevuld',          done: stageSubmitted(stages, 'intake') },
      { label: 'Aanwezigheid ≥ 80%',       done: attendanceMet },
      { label: 'Eindevaluatie',            done: stageSubmitted(stages, 'evaluation') },
    ];
  }

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

      // per-block load state — a failed block shows its OWN error, never blanks
      loading: { detail: true, trajectories: true },
      errors: { detail: '', trajectories: '' },

      get hasRegs() { return this.regs.length > 0; },

      /* init() reads ?user= then loads BOTH endpoints in parallel. I-1: gated so
         it loads the FIRST time dossier becomes active (it is reached via a
         ?user= deep-link, so view === 'dossier' on arrival → the guard fires on
         mount; the guard also prevents a spurious reload if the view toggles). */
      init() {
        window.WS.lazyLoad(this, 'dossier', () => this.load());
      },

      load() {
        // Reset error banners at the top so a successful retry recovers cleanly
        // (cluster-B lesson — a stale error must not survive a now-good load).
        this.errors.detail = '';
        this.errors.trajectories = '';

        const params = new URLSearchParams(window.location.search);
        const userId = Number(params.get('user')) || 0;
        if (!userId) {
          this.loading.detail = false;
          this.loading.trajectories = false;
          this.errors.detail = 'Geen gebruiker geselecteerd.';
          return;
        }

        Promise.allSettled([
          this.api(`/admin/users/${userId}/detail`),
          this.api(`/admin/users/${userId}/trajectories`),
        ]).then(([detail, trajectories]) => {
          // ---- detail: person + registrations + audit timeline ----
          if (detail.status === 'fulfilled') {
            const d = detail.value || {};
            this.person = d.user || null;
            this.regs = (d.registrations || []).map((r, i) => ({ ...r, open: i === 0 }));
            // audit_trail is GATED — absent/empty means the viewer can't see it
            // (PII N1) OR there's simply no history. Either way: locked/empty
            // timeline, never a crash.
            this.auditTrail = Array.isArray(d.audit_trail) ? d.audit_trail : [];
            this.canSeeTimeline = Array.isArray(d.audit_trail);
            this.timelineReg = this.regs.length ? this.regs[0].id : 0;
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

      /* completion checklist (mapper 3) */
      completionFor(r) { return completionChecklist(r); },

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
    completionChecklist,
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
