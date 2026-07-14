/* ==========================================================================
   Stride Admin Workspace — Vandaag surface (cluster B)
   --------------------------------------------------------------------------
   The launcher / workbench home. This factory OWNS loading ALL of its own
   data in init() — there is NO shared loader. "Landed on Vandaag but a panel
   is empty" is structurally impossible: every panel's data is fetched here,
   and each panel carries its own loading/empty/error state.

   Data sources (backend FROZEN — consumed exactly as Phase-1 emits them):
     - GET /admin/stats              → stat strip (4 cards) + the 5 queues
     - GET /admin/pending-approvals  → Acties-nodig "mij" + "gebruiker" buckets
     - GET /admin/action-queue       → Acties-nodig "meldingen" bucket

   The mij/gebruiker/meldingen wiring is LIFTED verbatim from the old
   admin-dashboard.js loadDashboard() (pending-approvals `type` filter + the
   tab-default priority). See plan §8 (CORRECTED): this is a REUSE, not a new
   mapping.

   INV-5: every x-html in vandaag.html binds a CONSTANT icon name via
   WS.icon('<literal>') / WS.icon(<closed-enum field>) — never a data field.
   The mappers below only ever assign icon names from a fixed table, so the
   markup's `s.icon` / `q.icon` are closed-enum values, not free text.
   INV-7: stat/queue counts + labels are rendered AS RECEIVED; nothing is
   re-derived client-side.

   The pure mappers are exported (UMD tail) so the Tier-A unit test can import
   them without a browser. The browser path attaches them to window.WS.
   ========================================================================== */
(function (root, factory) {
  'use strict';
  const api = factory();
  if (typeof module !== 'undefined' && module.exports) {
    module.exports = api; // Node / Playwright unit test
  }
  if (typeof root !== 'undefined') {
    // Browser: expose the factory + pure mappers on the shared WS surface.
    root.vandaag = api.vandaag;
    root.WS = root.WS || {};
    root.WS.mapStats = api.mapStats;
    root.WS.mapQueues = api.mapQueues;
    root.WS.mapActionBuckets = api.mapActionBuckets;
    root.WS.mapMeldingen = api.mapMeldingen;
    root.WS.avatarColor = root.WS.avatarColor || api.avatarColor;
    root.WS.initials = root.WS.initials || api.initials;
  }
})(typeof window !== 'undefined' ? window : this, function () {
  'use strict';

  /* ---- avatar helpers (ported from the mockup data.js — pure) ---- */
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

  /* ---- stat strip: 4 cards mapped from GET /admin/stats ----------------
     `delta`/`kind` have a backend source ONLY for active registrations
     (registrationsThisWeek vs …LastWeek). The other three render WITHOUT a
     fabricated delta (delta:'' → the markup shows no number). INV-7: counts
     are rendered as received. */
  function mapStats(stats) {
    const s = stats || {};
    const thisWeek = Number(s.registrationsThisWeek) || 0;
    const lastWeek = Number(s.registrationsLastWeek) || 0;
    const regDelta = thisWeek - lastWeek;

    return [
      { label: 'Komende edities', num: Number(s.upcomingEditions) || 0, delta: '', kind: 'flat', icon: 'layers' },
      {
        label: 'Actieve inschrijvingen',
        num: Number(s.totalRegistrations) || 0,
        delta: regDelta > 0 ? `+${regDelta} deze week` : (regDelta < 0 ? `${regDelta} deze week` : ''),
        kind: regDelta > 0 ? 'up' : 'flat',
        icon: 'users',
      },
      { label: 'Openstaande offertes', num: Number(s.pendingQuotes) || 0, delta: '', kind: 'flat', icon: 'receipt' },
      { label: 'Sessies vandaag', num: Number(s.todaySessions) || 0, delta: '', kind: 'flat', icon: 'calendar' },
    ];
  }

  /* ---- the 5 worklist queues, mapped from stats.worklistQueues ----------
     Mockup keys → real worklistQueues keys (plan §4):
       pending→pending · waitlist→waitlist_open · offerte→offerte_opvolging
       nocert→nocert · oldinterest→oldinterest. */
  const QUEUE_DEFS = [
    { key: 'pending', countKey: 'pending', label: 'Wacht op goedkeuring', def: 'status = in afwachting', accent: '#d97706', icon: 'hourglass', action: 'Goedkeuren', actionIcon: 'checkCircle' },
    { key: 'waitlist', countKey: 'waitlist_open', label: 'Wachtlijst — plaatsen vrij', def: 'wachtlijst + editie heeft vrije plaatsen', accent: '#8b5cf6', icon: 'seat', action: 'Promoveer van wachtlijst', actionIcon: 'arrowUp' },
    { key: 'offerte', countKey: 'offerte_opvolging', label: 'Offerte-opvolging', def: 'bevestigd + offerte nog niet verwerkt', accent: '#2563eb', icon: 'receipt', action: 'Markeer verzonden / verwerkt', actionIcon: 'send' },
    { key: 'nocert', countKey: 'nocert', label: 'Afgerond zonder certificaat', def: 'afgerond + voltooid + geen LD-certificaat', accent: '#16a34a', icon: 'award', action: 'Bericht sturen', actionIcon: 'mail' },
    { key: 'oldinterest', countKey: 'oldinterest', label: 'Oude interesse', def: 'interesse + ouder dan 90 dagen', accent: '#64748b', icon: 'clock', action: 'Bericht sturen / archiveren', actionIcon: 'archive' },
    // interest_to_invite: interest on an edition that NOW has a planned date.
    // The action deep-links to the interest list (switchView via the card's
    // generic queue wiring); the actual bulk-mail SEND is DEFERRED to the
    // netdust-mail broadcast (not built yet).
    { key: 'interest_to_invite', countKey: 'interest_to_invite', label: 'Interesse — editie nu gepland', def: 'interesse op een editie die nu een datum heeft', accent: '#0ea5e9', icon: 'sparkle', action: 'Bekijk & uitnodigen', actionIcon: 'users' },
  ];
  function mapQueues(worklistQueues) {
    const w = worklistQueues || {};
    return QUEUE_DEFS.map((d) => {
      const count = Number(w[d.countKey]) || 0;
      const q = {
        key: d.key,
        label: d.label,
        def: d.def,
        accent: d.accent,
        icon: d.icon,
        action: d.action,
        actionIcon: d.actionIcon,
        count,
        sub: '',
      };
      // Decision 7a — the approval card splits its total into the two
      // sub-states (server-derived, same definition as the count: ready ∪
      // blocked ≡ pending). Rendered instead of the static def line; only
      // when the payload actually carries the split (older cached payloads
      // within the stats TTL may not) and there is something to split.
      if (d.key === 'pending' && count > 0 && w.pending_ready != null) {
        const ready = Number(w.pending_ready) || 0;
        const blocked = Math.max(0, count - ready);
        q.sub = `${ready} klaar voor goedkeuring · ${blocked} wacht op deelnemer`;
      }
      return q;
    });
  }

  /* ---- "sinds Nd" age microcopy from an ISO-ish timestamp -------------- */
  function ageSince(ts) {
    if (!ts) return '';
    const then = new Date(String(ts).replace(' ', 'T'));
    if (isNaN(then.getTime())) return '';
    const days = Math.max(0, Math.floor((Date.now() - then.getTime()) / 86400000));
    if (days === 0) return 'vandaag';
    if (days === 1) return 'sinds 1d';
    return `sinds ${days}d`;
  }

  /* ---- gate-deadline badge for a stale_user row (Task 6.2) --------------
     Reads the days_left / days_overdue keys Task 6.1 derives server-side
     (buildDeadlineCountdown() in AdminAPIController — the SAME convergence
     point). Display-only (D3): no action is taken here, just a badge.
       - days_overdue a non-negative number → red "overdue" badge
       - else days_left a non-negative number → neutral "due soon" badge
       - else (no activeDeadline on the item)  → no badge (return null)
     Read defensively: `!= null` guards (loose — catches both null and
     undefined while allowing 0) + Number(...) + explicit >= 0 checks, so a
     missing/absent/explicit-null key renders no badge, never "NaN dagen",
     "undefined dagen", or a false-positive "vandaag verlopen" from
     Number(null) === 0. */
  function deadlineBadge(item) {
    const overdue = Number(item && item.days_overdue);
    if (item && item.days_overdue != null && !isNaN(overdue) && overdue >= 0) {
      return {
        kind: 'overdue',
        label: overdue === 0 ? 'vandaag verlopen' : `${overdue} ${overdue === 1 ? 'dag' : 'dagen'} te laat`,
      };
    }
    const left = Number(item && item.days_left);
    if (item && item.days_left != null && !isNaN(left) && left >= 0) {
      return {
        kind: 'due-soon',
        label: `nog ${left} ${left === 1 ? 'dag' : 'dagen'}`,
      };
    }
    return null;
  }

  /* ---- Acties-nodig: bucket pending-approvals items into mij/gebruiker --
     LIFTED from admin-dashboard.js: items with type ∈ {approval,post_approval}
     are admin-action ("Wacht op mij"); type === 'stale_user' is waiting-on-the
     -user ("Wacht op gebruiker"). Each item is already per-person.
     Returns { mij, gebruiker, defaultTab } — defaultTab follows the old
     priority: (approval+post_approval) > stale_user > meldingen. Empty payload
     → empty buckets, default 'meldingen'. */
  function mapActionBuckets(payload, meldingenCount) {
    const items = (payload && Array.isArray(payload.items)) ? payload.items : [];
    const counts = (payload && payload.counts) || {};

    const toRow = (item, metaSuffix) => ({
      regId: item.id,
      userId: item.user_id,
      name: item.user_name || '',
      meta: [item.edition_title, metaSuffix].filter(Boolean).join(' · '),
      age: item.type === 'stale_user'
        ? (Number(item.days_idle) >= 0 ? `sinds ${item.days_idle}d` : ageSince(item.registered_at))
        : ageSince(item.registered_at),
      // Gate-deadline badge (Task 6.2) — stale_user rows only; other types
      // (approval/post_approval) have no gate-deadline concept.
      deadline: item.type === 'stale_user' ? deadlineBadge(item) : null,
    });

    const mij = items
      .filter((i) => i.type === 'approval' || i.type === 'post_approval')
      .map((i) => toRow(i, i.type === 'post_approval' ? 'wacht op goedkeuring na cursus' : 'wacht op goedkeuring'));

    const gebruiker = items
      .filter((i) => i.type === 'stale_user')
      .map((i) => toRow(i, i.open_task_label || 'wacht op gebruiker'));

    const adminCount = (Number(counts.approval) || 0) + (Number(counts.post_approval) || 0);
    const staleCount = Number(counts.stale_user) || 0;
    let defaultTab;
    if (adminCount > 0) defaultTab = 'mij';
    else if (staleCount > 0) defaultTab = 'gebruiker';
    else defaultTab = 'meldingen';

    return { mij, gebruiker, defaultTab, hasMeldingen: (Number(meldingenCount) || 0) > 0 };
  }

  /* ---- meldingen ← /admin/action-queue (flat aggregate alert rows) ------
     The action-queue is a flat array
     [{rule, priority, text, subject_id, url, target}]. Mapped to the same row
     shape the Acties markup renders. `regId` = a stable key (rule+subject);
     these rows are aggregate, so name = the alert text and there is no
     avatar/person link. Navigation: `target` ({view, params}) routes through
     the shell's switchView (stays in the workspace); `url` is the wp-admin
     fallback (quotes); neither → informational, no navigation. */
  function mapMeldingen(actionQueue) {
    const arr = Array.isArray(actionQueue) ? actionQueue : [];
    return arr.map((a) => ({
      regId: `${a.rule}-${a.subject_id || 0}`,
      rule: a.rule || '',
      subjectId: a.subject_id || 0,
      name: a.text || '',
      meta: '',
      age: '',
      url: a.url || '',
      target: (a.target && a.target.view) ? a.target : null,
      priority: a.priority || 'blue',
      isMelding: true,
    }));
  }

  /* ---- the Alpine factory ------------------------------------------------ */
  function vandaag() {
    return {
      // panel state
      stats: [],
      queues: [],
      aq: { mij: [], gebruiker: [], meldingen: [] },
      actTab: 'mij',

      // per-panel loading / error flags (a panel never blanks the surface)
      loading: { stats: true, actions: true },
      errors: { stats: '', actions: '' },

      today: new Date().toLocaleDateString('nl-BE', { weekday: 'long', day: 'numeric', month: 'long' }),

      get totalActions() {
        return this.queues.reduce((n, q) => n + (q.count || 0), 0);
      },

      /* init() loads on mount because Vandaag is StrideConfig.defaultView — the
         cold-landing surface. The guard fires immediately (view === 'vandaag' on
         mount) so the dashboard lands populated; on a deep-link to another view
         it instead loads the first time the user navigates BACK to Vandaag. */
      init() {
        window.WS.lazyLoad(this, 'vandaag', () => this.load());
      },

      /* load() does the actual work. Called once via the first-activation guard,
         and again (bypassing the latch) by pulse() for an explicit refresh.
         Both fetches run in parallel; a panel that fails shows its own error,
         the rest still renders (AF-1 mid-flow). */
      load() {
        // Clear any prior error banners so a successful retry (pulse) recovers
        // cleanly — otherwise a stale error survives a now-successful load.
        this.errors.stats = '';
        this.errors.actions = '';
        Promise.allSettled([
          this.api('/admin/stats'),
          this.api('/admin/pending-approvals?stale_days=7&per_page=100'),
          this.api('/admin/action-queue'),
        ]).then(([stats, approvals, queue]) => {
          // ---- stat strip + 5 queues (from /admin/stats) ----
          if (stats.status === 'fulfilled') {
            this.stats = window.WS.mapStats(stats.value);
            this.queues = window.WS.mapQueues(stats.value.worklistQueues);
          } else {
            this.errors.stats = 'Kon de statistieken niet laden.';
          }
          this.loading.stats = false;

          // ---- Acties-nodig (meldingen first so defaultTab can see it) ----
          const meldingen = queue.status === 'fulfilled'
            ? window.WS.mapMeldingen(queue.value)
            : [];
          const queueFailed = queue.status === 'rejected';

          if (approvals.status === 'fulfilled') {
            const buckets = window.WS.mapActionBuckets(approvals.value, meldingen.length);
            this.aq = { mij: buckets.mij, gebruiker: buckets.gebruiker, meldingen };
            this.actTab = buckets.defaultTab;
          } else {
            this.aq = { mij: [], gebruiker: [], meldingen };
            this.actTab = 'meldingen';
            this.errors.actions = 'Kon de goedkeuringslijst niet laden.';
          }
          // If only the meldingen call failed, surface it but keep mij/gebruiker.
          if (queueFailed && !this.errors.actions) {
            this.errors.actions = 'Kon de meldingen niet laden.';
          }
          this.loading.actions = false;
        });
      },

      /* Open a queue → grid, pre-filtered by ?queue=<key> (cluster C reads it
         from the URL on its own init). Uses the shell's extended switchView.
         A count-0 card still opens (spec F1): the grid shows the queue's
         truthful empty state — a silent no-op read as a broken card. */
      openQueue(q) {
        if (!q) return;
        this.switchView('inschrijvingen', { queue: q.key });
      },

      /* Click an Acties row → that person's dossier (cluster D reads ?user=),
         deep-linked to the SPECIFIC waiting registration via ?reg= so the
         dossier opens the right edition rather than the person's newest one.
         Meldingen rows route their workspace `target` through switchView
         (a `vandaag` target is a LOCAL tab switch — e.g. the stale-tasks
         aggregate opens "Wacht op gebruiker" one tab over); `url` opens the
         wp-admin fallback (quotes); neither → informational, no-op. */
      openAction(item) {
        if (item.isMelding) {
          if (item.target) {
            if (item.target.view === 'vandaag') {
              this.actTab = (item.target.params && item.target.params.tab) || 'gebruiker';
            } else {
              this.switchView(item.target.view, item.target.params || {});
            }
            return;
          }
          if (item.url) window.open(item.url, '_blank', 'noopener');
          return;
        }
        const id = item.userId || item.regId;
        if (id) this.switchView('dossier', { user: id, reg: item.regId });
      },

      pulse() {
        // Re-run the full load (bypassing the first-activation latch), then
        // toast the result.
        this.loading.stats = true;
        this.loading.actions = true;
        this.load();
        window.dispatchEvent(new CustomEvent('ws-toast'));
      },

      avatarColor(name) { return window.WS.avatarColor(name); },
      initials(name) { return window.WS.initials(name); },
    };
  }

  return {
    vandaag,
    mapStats,
    mapQueues,
    mapActionBuckets,
    mapMeldingen,
    avatarColor,
    initials,
    ageSince,
    deadlineBadge,
  };
});
