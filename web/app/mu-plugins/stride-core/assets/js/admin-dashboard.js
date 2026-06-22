/* ==========================================================================
   Registration grid — client mirrors of server-side single sources.
   --------------------------------------------------------------------------
   PORTED from docs/mockups/admin-workspace/assets/js/data.js. These MUST stay
   in sync with the PHP sources (flagged for the review gate):
     • SMART_ACTIONS / actionsForStates — mirror of RegistrationTransitions
       (Modules/Enrollment/RegistrationTransitions.php, spec §2.1) PLUS the
       quote-status + post-course + deferred-stub bulk actions that are NOT
       lifecycle transitions. The bulk bar derives from this map, never a
       per-call hard-coded set (Sibling-site audit 2). NOTE divergence: the
       PHP transition map does NOT permit waitlist→cancelled, yet
       stride_bulk_cancel lists 'waitlist'; the server's handleBulkCancel
       calls EnrollmentService::cancel() directly (no isAllowed guard), so a
       waitlist cancel is attempted server-side. Flagged as a finding.
     • REG_STATUS — mirror of Domain/RegistrationStatus::label().
   When the PHP source changes, update this block (or, better, print the map
   into the admin bootstrap — see localized StrideConfig.transitions TODO). */
const STRIDE_REG_STATUS = {
    confirmed: { label: 'Bevestigd',     cls: 'confirmed', step: 4, pipe: 'Bevestigd' },
    completed: { label: 'Afgerond',      cls: 'completed', step: 5, pipe: 'Afgerond' },
    cancelled: { label: 'Geannuleerd',   cls: 'cancelled', exit: true, pipe: 'Geannuleerd' },
    waitlist:  { label: 'Wachtlijst',    cls: 'waitlist',  step: 2, pipe: 'Op wachtlijst' },
    interest:  { label: 'Interesse',     cls: 'interest',  step: 1, pipe: 'Interesse' },
    pending:   { label: 'In afwachting', cls: 'pending',   step: 3, pipe: 'Wacht op goedkeuring' },
};
const STRIDE_STATUS_PIPELINE = ['interest', 'waitlist', 'pending', 'confirmed', 'completed'];
const STRIDE_STATUS_EXIT = 'cancelled';

const STRIDE_SMART_ACTIONS = [
    { id: 'stride_bulk_approve',            label: 'Goedkeuren',               icon: 'approve', states: ['pending', 'interest'] },
    { id: 'stride_bulk_promote_waitlist',  label: 'Promoveer van wachtlijst', icon: 'up',      states: ['waitlist'] },
    { id: 'stride_bulk_quote_sent',         label: 'Offerte verzonden',        icon: 'send',    states: ['confirmed'] },
    { id: 'stride_bulk_quote_exported',     label: 'Offerte verwerkt',         icon: 'approve', states: ['confirmed'] },
    { id: 'stride_bulk_approve_post_course',label: 'Goedkeuren na cursus',     icon: 'award',   states: ['confirmed', 'completed'] },
    { id: 'stride_bulk_message',            label: 'Bericht sturen',           icon: 'mail',    states: ['confirmed', 'completed', 'interest', 'pending', 'waitlist'] },
    { id: 'stride_bulk_generate_doc',       label: 'Document genereren',       icon: 'doc',     states: ['completed'] },
    { id: 'stride_bulk_cancel',             label: 'Annuleren',                icon: 'cancel',  states: ['pending', 'interest', 'confirmed', 'waitlist'], danger: true },
];
/* the safe intersection across a set of states (used for the bulk bar) */
function strideActionsForStates(states) {
    const uniq = [...new Set(states)];
    if (uniq.length === 0) return [];
    return STRIDE_SMART_ACTIONS.filter(a => uniq.every(s => a.states.includes(s)));
}

document.addEventListener('alpine:init', () => {
    Alpine.data('strideApp', () => ({
        // ── Routing ──────────────────────────────────────────────
        view: 'dashboard',
        loading: false,
        statsLoaded: false,

        // ── Error state per view (set when a fetch throws) ────────
        errors: { dashboard: '', edities: '', inschrijvingen: '', offertes: '', trajecten: '', users: '' },

        // ── Config ───────────────────────────────────────────────
        config: window.StrideConfig || {},

        // ── Dashboard home ───────────────────────────────────────
        stats: { upcomingEditions: null, totalRegistrations: null, pendingQuotes: null, todaySessions: null, actionsNeeded: null, actionCount: null },
        actionQueue: [],
        pendingApprovals: { items: [], counts: { approval: 0, post_approval: 0, stale_user: 0 }, stale_threshold_days: 7 },
        pendingApprovalsTab: 'approval', // 'approval' | 'post_approval' | 'stale_user'
        userDetailReturnTo: null, // 'dashboard' when admin arrived from actie-vereist card
        upcomingSessions: [],
        activityFeed: [],
        healthChecks: { registration: 'green', mail: 'green', audit: 'green' },

        // ── Editions ─────────────────────────────────────────────
        editions: [],
        editionFilters: { search: '', status: '', date_from: '', date_to: '', theme: 0, format: 0, tag: 0 },
        editionPagination: { page: 1, totalPages: 1, total: 0 },
        editionTaxonomies: { theme: [], format: [], tag: [] },
        selectedEdition: null,
        editionTab: 'students',
        editionRegistrations: [],
        editionSessions: [],
        slideoverOpen: false,

        // ── Quotes ───────────────────────────────────────────────
        quotes: [],
        quoteFilters: { search: '', status: '', edition_id: 0 },
        quotePagination: { page: 1, totalPages: 1, total: 0 },
        quoteEditions: [],
        selectedQuote: null,
        quoteTab: 'details',

        // ── Trajectories ─────────────────────────────────────────
        trajectories: [],
        trajectoryFilters: { search: '', status: '' },
        trajectoryPagination: { page: 1, totalPages: 1, total: 0 },
        selectedTrajectory: null,
        trajectoryTab: 'details',

        // ── Inschrijvingen grid (Tasks 3.1 + 3.2) ─────────────────
        // SERVER-PAGED: `gridRows` holds ONE server page, never the full
        // corpus (§5). page/perPage/sortKey/sortDir/groupBy/filters are
        // server query params on /admin/registrations; any change re-fetches.
        gridRows: [],            // one page of flat registration items (or [] when grouped)
        gridGroups: [],          // grouped aggregates (when groupBy is set)
        // Per-status funnel counts from the server (Task 3.3 Part B). Reflects the
        // active filter set MINUS the status filter, so each pipeline chip shows a
        // live count regardless of which chip is selected.
        gridStatusCounts: {},
        gridLoaded: false,
        gridPagination: { page: 1, perPage: 25, total: 0, totalPages: 1 },
        gridSort: { key: '', dir: 'asc' },   // '' = server default
        gridGroupBy: '',         // '' | 'edition_id' | 'status' | 'company_id' | 'trajectory_id'
        gridCollapsed: {},       // group_value -> true (collapsed)
        // Structured-only filters (Sibling-site audit 3 — every key is a column the
        // endpoint whitelists; NO enrollment_data/JSON filter). offerteOpen/noCert
        // are queue hints carried client-side, applied as armed-action context only.
        gridFilters: { status: '', edition_id: 0, company_id: 0, trajectory_id: 0, q: '' },
        gridQueue: '',           // active worklist context (from ?queue=)
        gridArmedAction: null,   // pre-armed bulk action id from the queue

        // ── Multi-select + bulk bar (Task 3.2) ────────────────────
        gridSelected: {},        // id -> true (plain object stands in for a Set)
        gridSelectAllFilter: false, // true = "select all matching the filter" (carry filter, not 4k rows)
        gridBulkBusy: null,      // action id currently running
        gridOverflowOpen: false,
        gridResult: null,        // { action, total, succeeded[], failed[], ok, err }
        gridResultOpen: false,

        // ── Users ────────────────────────────────────────────────
        userSearchQuery: '',
        userSearchResults: [],
        dashboardUserResults: [],
        selectedUser: null,
        userProfileOpen: false,
        profileEdit: { personal: false, billing: false },
        profileDraft: {},
        profileSaving: false,
        // Per-session reveals of sensitive fields (national_id, date_of_birth, license).
        // Never persisted — cleared when selectedUser changes.
        revealed: {},

        // ── Notifications ────────────────────────────────────────
        notifications: [],
        unreadCount: 0,
        showNotifications: false,

        // ── UI ───────────────────────────────────────────────────
        toast: null,
        // Which slide-over kebab is open: 'edition' | 'quote' | 'trajectory' | null
        kebabOpen: null,

        // ==============================================================
        //  COMPUTED GETTERS
        //  Template references these derived arrays from selectedEdition
        //  and selectedTrajectory / selectedUser detail data.
        // ==============================================================

        /** Sessions list for the attendance grid inside edition slide-over */
        get editionSessionList() {
            return this.selectedEdition?.sessions || this.editionSessions || [];
        },

        /** quoteEditions exposed as "editionOptions" for the quote filter dropdown */
        get editionOptions() {
            return this.quoteEditions;
        },

        /** Courses inside the selected trajectory */
        get trajectoryCourses() {
            return this.selectedTrajectory?.courses || [];
        },

        /** Registrations inside the selected trajectory */
        get trajectoryRegistrations() {
            return this.selectedTrajectory?.registrations || [];
        },

        /** Registrations for the selected user */
        get userRegistrations() {
            return this.selectedUser?.registrations || [];
        },

        /** Quotes for the selected user */
        get userQuotes() {
            return this.selectedUser?.quotes || [];
        },

        /** Audit log for the selected user (capped at 30 — full log linked separately) */
        get userAuditLog() {
            return (this.selectedUser?.audit_trail || []).slice(0, 30);
        },

        /** Total audit-entry count (so we can show "X meer" when truncated) */
        get userAuditLogTotal() {
            return (this.selectedUser?.audit_trail || []).length;
        },

        /** Attendance summary for the selected user (legacy, kept for callers) */
        get userAttendanceSummary() {
            return this.selectedUser?.attendance_summary || [];
        },

        /**
         * Registrations whose edition has sessions — used by the Aanwezigheid
         * table so e-learning enrollments don't pollute the progress view.
         */
        get sessionedRegistrations() {
            return (this.selectedUser?.registrations || []).filter(r => r.has_sessions);
        },

        // ==============================================================
        //  CORE METHODS
        // ==============================================================

        init() {
            this.parseHash();
            window.addEventListener('hashchange', () => this.parseHash());
            this.loadDashboard();
            this.$watch('view', (newView) => {
                this.loadViewData(newView);
                history.replaceState(null, '', newView === 'dashboard' ? '#/' : '#/' + newView);
            });
        },

        parseHash() {
            const hash = window.location.hash.replace('#/', '') || 'dashboard';
            const validViews = ['dashboard', 'edities', 'inschrijvingen', 'offertes', 'trajecten', 'gebruikers'];
            this.view = validViews.includes(hash) ? hash : 'dashboard';
            this.loadViewData(this.view);
        },

        switchView(view) {
            this.view = view;
        },

        async api(endpoint, options = {}) {
            const url = `${this.config.apiUrl}${endpoint}`;
            const response = await fetch(url, {
                ...options,
                headers: {
                    'X-WP-Nonce': this.config.nonce,
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

        showToast(message, type = 'info') {
            this.toast = { message, type };
            setTimeout(() => { this.toast = null; }, 4000);
        },

        openSlideOver() { this.slideoverOpen = true; },
        closeSlideOver() {
            this.slideoverOpen = false;
            this.selectedEdition = null;
            this.selectedQuote = null;
            this.selectedTrajectory = null;
            this.kebabOpen = null;
        },

        // Build a stride_export_registrations URL for the selected edition.
        // Nonce is keyed `stride_edition_admin`; types: excel | attendance | namecards.
        editionExportUrl(type) {
            const id = this.selectedEdition?.id;
            if (!id) return '#';
            const nonce = this.config.exportNonce || '';
            return `${this.config.adminUrl || '/wp-admin/'}admin-ajax.php?action=stride_export_registrations&type=${encodeURIComponent(type)}&edition_id=${id}&nonce=${encodeURIComponent(nonce)}`;
        },

        // ==============================================================
        //  VIEW DATA LOADING
        // ==============================================================

        _loadingViews: {},
        loadViewData(view) {
            if (view === 'edities') {
                if (this.editions.length === 0 && !this._loadingViews.edities) this.loadEditions();
                if (this.editionTaxonomies.theme.length === 0) this.loadEditionTaxonomies();
                this.$nextTick(() => this.initDateRangePicker());
            } else if (view === 'offertes') {
                if (this.quotes.length === 0 && !this._loadingViews.offertes) this.loadQuotes();
                if (this.quoteEditions.length === 0) this.loadQuoteEditions();
            } else if (view === 'trajecten') {
                if (this.trajectories.length === 0 && !this._loadingViews.trajecten) this.loadTrajectories();
            } else if (view === 'inschrijvingen') {
                if (this.editionOptions.length === 0 && this.quoteEditions.length === 0) this.loadQuoteEditions();
                if (!this.gridLoaded && !this._loadingViews.inschrijvingen) this.initGrid();
            } else if (view === 'dashboard') {
                this.loadDashboard();
            }
        },

        // ==============================================================
        //  DASHBOARD METHODS
        // ==============================================================

        async loadDashboard() {
            this.loading = true;
            this.errors.dashboard = '';
            try {
                const [stats, queue, sessions, activity, health, notifs, approvals] = await Promise.allSettled([
                    this.api('/admin/stats'),
                    this.api('/admin/action-queue'),
                    this.api('/admin/editions?view=agenda&per_page=10'),
                    this.api('/admin/activity?limit=10'),
                    this.api('/admin/health-checks'),
                    this.api('/admin/notifications'),
                    this.api('/admin/pending-approvals?stale_days=7&per_page=100'),
                ]);
                if (stats.status === 'rejected') {
                    this.errors.dashboard = 'Kon dashboard-data niet laden.';
                }
                if (stats.status === 'fulfilled') {
                    const s = stats.value;
                    s.actionsNeeded = s.actionCount ?? s.actionsNeeded ?? 0;
                    this.stats = s;
                    this.statsLoaded = true;
                }
                if (queue.status === 'fulfilled') this.actionQueue = queue.value;
                if (sessions.status === 'fulfilled') {
                    const statusLabels = { open: 'Open', full: 'Vol', cancelled: 'Geannuleerd', completed: 'Afgelopen', closed: 'Gesloten' };
                    this.upcomingSessions = (sessions.value.items || []).map(s => ({
                        ...s,
                        edition_id: s.id,
                        edition_title: s.title,
                        time: [s.startTime, s.endTime].filter(Boolean).join('–') || '—',
                        registered: s.registeredCount ?? 0,
                        status_label: statusLabels[s.status] || s.status || '—',
                        isToday: s.isToday || s.date === new Date().toISOString().split('T')[0],
                        isPast: s.isPast || new Date(s.date) < new Date(new Date().setHours(0, 0, 0, 0)),
                    }));
                }
                if (activity.status === 'fulfilled') this.activityFeed = activity.value;
                if (health.status === 'fulfilled') this.healthChecks = health.value;
                if (notifs.status === 'fulfilled') {
                    this.notifications = notifs.value.notifications || [];
                    this.unreadCount = notifs.value.unread_count || 0;
                }
                if (approvals.status === 'fulfilled') {
                    this.pendingApprovals = approvals.value;
                    // CR-E2: surface truncation instead of silently rendering fewer rows than the tab pills claim.
                    if (approvals.value.clipped || (approvals.value.total ?? 0) > (approvals.value.items || []).length) {
                        console.warn(`Stride: goedkeuringslijst afgekapt — ${(approvals.value.items || []).length} van ${approvals.value.total} items geladen.`);
                    }
                    // Default-active tab priority: approval/post_approval (admin-action) > stale_user > notifications
                    // approval + post_approval are merged under the "Wacht op mij" tab (key: 'approval')
                    const c = approvals.value.counts || {};
                    if ((c.approval + c.post_approval) > 0) this.pendingApprovalsTab = 'approval';
                    else if (c.stale_user > 0) this.pendingApprovalsTab = 'stale_user';
                    else if (this.actionQueue.length > 0) this.pendingApprovalsTab = 'notifications';

                    // Deep-link from action-queue: hash `#action-required-<bucket>`
                    // tells us which tab to activate + scrolls the card into view.
                    // post_approval is folded into the 'approval' tab (same UX bucket).
                    const hash = (window.location.hash || '').replace(/^#/, '');
                    const m = hash.match(/^action-required-(approval|post_approval|stale_user)$/);
                    if (m) {
                        const tab = m[1] === 'post_approval' ? 'approval' : m[1];
                        const hasItems = tab === 'approval'
                            ? (c.approval + c.post_approval) > 0
                            : c[tab] > 0;
                        if (hasItems) {
                            this.pendingApprovalsTab = tab;
                            setTimeout(() => {
                                document.getElementById('action-required-card')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
                            }, 100);
                        }
                    }
                }
            } catch (e) {
                console.error('Dashboard load error:', e);
            }
            this.loading = false;
        },

        async dismissAction(rule, subjectId) {
            try {
                await this.api('/admin/action-queue/dismiss', {
                    method: 'POST',
                    body: JSON.stringify({ rule, subject_id: subjectId || 0 }),
                });
                this.actionQueue = this.actionQueue.filter(i =>
                    !(i.rule === rule && (i.subject_id || 0) === (subjectId || 0))
                );
                this.stats.actionsNeeded = Math.max(0, (this.stats.actionsNeeded || 0) - 1);
            } catch (e) {
                this.showToast('Actie negeren mislukt', 'error');
            }
        },

        async dashboardUserSearch(query) {
            if (query.length < 2) { this.dashboardUserResults = []; return; }
            try {
                this.dashboardUserResults = await this.api(`/admin/users/search?q=${encodeURIComponent(query)}`);
            } catch (e) { this.dashboardUserResults = []; }
        },

        navigateToUser(userId) {
            this.dashboardUserResults = [];
            this.view = 'gebruikers';
            this.$nextTick(() => this.selectUser(userId));
        },

        exportRegistrations() {
            window.location.href = this.config.apiUrl + '/admin/export/registrations?_wpnonce=' + this.config.nonce;
        },

        // ==============================================================
        //  EDITIONS METHODS
        // ==============================================================

        async loadEditions(page) {
            if (page != null) this.editionPagination.page = page;
            this._loadingViews.edities = true;
            this.loading = true;
            const params = new URLSearchParams({
                page: this.editionPagination.page,
                per_page: 20,
                view: 'agenda',
                search: this.editionFilters.search,
                status: this.editionFilters.status,
                date_from: this.editionFilters.date_from,
                date_to: this.editionFilters.date_to,
                theme: this.editionFilters.theme,
                format: this.editionFilters.format,
                tag: this.editionFilters.tag,
            });
            try {
                const data = await this.api(`/admin/editions?${params}`);
                const statusLabels = { open: 'Open', full: 'Vol', cancelled: 'Geannuleerd', completed: 'Afgelopen', closed: 'Gesloten', announcement: 'Aankondiging' };
                this.editions = (data.items || []).map(s => ({
                    ...s,
                    edition_id: s.id,
                    edition_title: s.title,
                    session_title: s.sessionTitle || s.session_title || '',
                    time: [s.startTime, s.endTime].filter(Boolean).join('–') || '—',
                    registered: s.registeredCount ?? s.registered ?? 0,
                    status_label: statusLabels[s.status] || s.status || '—',
                    isToday: s.isToday || s.date === new Date().toISOString().split('T')[0],
                    isPast: s.isPast || new Date(s.date) < new Date(new Date().setHours(0, 0, 0, 0)),
                }));
                this.editionSessions = this.editions;
                this.editionPagination = { page: data.page || 1, totalPages: data.total_pages || data.totalPages || 1, total: data.total || 0 };
                this.errors.edities = '';
            } catch (e) {
                this.errors.edities = 'Edities laden mislukt. Controleer de verbinding en probeer opnieuw.';
                this.showToast('Edities laden mislukt', 'error');
            }
            this.loading = false;
        },

        async loadEditionTaxonomies() {
            try {
                const data = await this.api('/admin/course-tags');
                // API returns { theme: [...], format: [...], tag: [...] }
                this.editionTaxonomies = {
                    theme: data.theme || [],
                    format: data.format || [],
                    tag: data.tag || [],
                };
            } catch (e) {}
        },

        initDateRangePicker() {
            const el = this.$refs?.dateRange;
            if (!el || el._flatpickr) return;
            if (typeof flatpickr === 'undefined') return;
            flatpickr(el, {
                mode: 'range',
                dateFormat: 'Y-m-d',
                locale: typeof flatpickr.l10ns?.nl !== 'undefined' ? 'nl' : 'default',
                onChange: (dates) => {
                    if (dates.length === 2) {
                        this.editionFilters.date_from = dates[0].toISOString().split('T')[0];
                        this.editionFilters.date_to = dates[1].toISOString().split('T')[0];
                        this.loadEditions();
                    }
                },
            });
        },

        resetEditionFilters() {
            this.editionFilters = { search: '', status: '', date_from: '', date_to: '', theme: 0, format: 0, tag: 0 };
            const el = this.$refs?.dateRange;
            if (el?._flatpickr) el._flatpickr.clear();
            this.loadEditions(1);
        },

        async openEdition(id) {
            try {
                const [edition, regs] = await Promise.all([
                    this.api(`/admin/editions/${id}`),
                    this.api(`/admin/editions/${id}/registrations`),
                ]);
                this.selectedEdition = edition;
                const regStatusLabels = {
                    confirmed: 'Bevestigd', completed: 'Afgerond', cancelled: 'Geannuleerd',
                    pending: 'In afwachting', interest: 'Interesse', waitlist: 'Wachtlijst',
                };
                this.editionRegistrations = (regs.registrations || regs.items || []).map(reg => ({
                    ...reg,
                    user_id: reg.user_id || reg.user?.id,
                    name: reg.name || reg.user?.name,
                    email: reg.email || reg.user?.email,
                    status_label: reg.status_label || regStatusLabels[reg.status] || reg.status || '—',
                }));
                if (regs.sessions) {
                    this.selectedEdition.sessions = regs.sessions;
                }
                this.editionTab = 'students';
                this.openSlideOver();
            } catch (e) {
                this.showToast('Editie laden mislukt', 'error');
            }
        },

        async toggleAttendance(sessionId, userId, currentStatus) {
            const cycle = { undefined: 'present', null: 'present', '': 'present', none: 'present', present: 'absent', absent: 'excused', excused: '' };
            const newStatus = cycle[currentStatus] ?? 'present';
            // Optimistic update
            const reg = this.editionRegistrations.find(r => r.user_id === userId);
            if (reg) {
                if (!reg.attendance) reg.attendance = {};
                reg.attendance[sessionId] = newStatus || undefined;
            }
            try {
                await this.api('/admin/attendance', {
                    method: 'POST',
                    body: JSON.stringify({ session_id: sessionId, user_id: userId, status: newStatus }),
                });
            } catch (e) {
                // Revert on failure
                if (reg) reg.attendance[sessionId] = currentStatus;
                this.showToast('Aanwezigheid opslaan mislukt', 'error');
            }
        },

        attendanceLabel(status) {
            return { present: '\u2713', absent: '\u2717', excused: '!', '': '\u00b7', undefined: '\u00b7', null: '\u00b7' }[status] ?? '\u00b7';
        },

        // ==============================================================
        //  QUOTES METHODS
        // ==============================================================

        async loadQuotes(page) {
            if (page != null) this.quotePagination.page = page;
            this._loadingViews.offertes = true;
            this.loading = true;
            const params = new URLSearchParams({
                page: this.quotePagination.page, per_page: 20,
                search: this.quoteFilters.search,
                status: this.quoteFilters.status,
                edition_id: this.quoteFilters.edition_id,
            });
            try {
                const data = await this.api(`/admin/quotes?${params}`);
                const quoteStatusLabels = { draft: 'Concept', sent: 'Verzonden', exported: 'Geëxporteerd', cancelled: 'Geannuleerd' };
                this.quotes = (data.items || []).map(q => ({
                    ...q,
                    client_name: q.user?.name || q.client_name || '—',
                    client_email: q.user?.email || q.client_email || '—',
                    edition_title: q.edition?.title || q.edition_title || '—',
                    status_label: q.statusLabel || quoteStatusLabels[q.status] || q.status || '—',
                }));
                this.quotePagination = { page: data.page || 1, totalPages: data.total_pages || data.totalPages || 1, total: data.total || 0 };
                this.errors.offertes = '';
            } catch (e) {
                this.errors.offertes = 'Offertes laden mislukt. Probeer opnieuw.';
                this.showToast('Offertes laden mislukt', 'error');
            }
            this.loading = false;
        },

        async loadQuoteEditions() {
            try {
                // Lightweight typeahead (Task 1.4a). scope=all so the quotes
                // filter can see archived/past editions too. Envelope returns
                // {items:[{id,title,...}]} — template-compatible.
                const data = await this.api('/admin/editions/options?scope=all');
                this.quoteEditions = data.items || [];
            } catch (e) {}
        },

        openQuote(quoteOrId) {
            // Template calls openQuote(quote.id) from table row click
            let quote;
            if (typeof quoteOrId === 'object') {
                quote = quoteOrId;
            } else {
                quote = this.quotes.find(q => q.id === quoteOrId) || null;
            }
            if (quote) {
                // Map lineItems to items with correct field names for template
                quote.items = (quote.lineItems || quote.items || []).map(item => ({
                    ...item,
                    description: item.title || item.description || '',
                    price: item.unit_price ?? item.price ?? 0,
                    total: item.line_total ?? item.total ?? ((item.unit_price ?? item.price ?? 0) * (item.quantity || 1)),
                }));
            }
            this.selectedQuote = quote;
            this.quoteTab = 'details';
            if (this.selectedQuote) this.openSlideOver();
        },

        resetQuoteFilters() {
            this.quoteFilters = { search: '', status: '', edition_id: 0 };
            this.loadQuotes(1);
        },

        // ==============================================================
        //  TRAJECTORY METHODS
        // ==============================================================

        async loadTrajectories(page) {
            if (page != null) this.trajectoryPagination.page = page;
            this._loadingViews.trajecten = true;
            this.loading = true;
            const params = new URLSearchParams({
                page: this.trajectoryPagination.page, per_page: 20,
                search: this.trajectoryFilters.search,
                status: this.trajectoryFilters.status,
            });
            try {
                const data = await this.api(`/admin/trajectories?${params}`);
                this.trajectories = (data.items || []).map(t => ({
                    ...t,
                    course_count: t.courseCount ?? t.course_count ?? 0,
                    registered: t.enrolledCount ?? t.registered ?? 0,
                    status_label: t.statusLabel ?? t.status_label ?? t.status ?? '—',
                    deadline: t.enrollmentDeadline ?? t.deadline ?? null,
                }));
                this.trajectoryPagination = { page: data.page || 1, totalPages: data.total_pages || data.totalPages || 1, total: data.total || 0 };
                this.errors.trajecten = '';
            } catch (e) {
                this.errors.trajecten = 'Trajecten laden mislukt. Probeer opnieuw.';
                this.showToast('Trajecten laden mislukt', 'error');
            }
            this.loading = false;
        },

        async openTrajectory(id) {
            try {
                const data = await this.api(`/admin/trajectories/${id}`);
                this.selectedTrajectory = data;
                this.trajectoryTab = 'details';
                this.openSlideOver();
            } catch (e) { this.showToast('Traject laden mislukt', 'error'); }
        },

        resetTrajectoryFilters() {
            this.trajectoryFilters = { search: '', status: '' };
            this.loadTrajectories(1);
        },

        // ==============================================================
        //  INSCHRIJVINGEN GRID METHODS (Tasks 3.1 + 3.2)
        // ==============================================================

        // Status metadata + pipeline order (client mirrors of the PHP enum).
        regStatusMeta: STRIDE_REG_STATUS,
        statusPipeline: STRIDE_STATUS_PIPELINE,
        statusExit: STRIDE_STATUS_EXIT,

        // First entry into the grid view: honour ?queue=/?status= deep-links,
        // then load page 1. The queue mapping mirrors the mockup QUEUE_FILTER.
        initGrid() {
            const p = new URLSearchParams(window.location.search);
            const queue = p.get('queue');
            const queueMap = {
                pending:     { status: 'pending',   armAction: 'stride_bulk_approve' },
                waitlist:    { status: 'waitlist',  armAction: 'stride_bulk_promote_waitlist' },
                offerte:     { status: 'confirmed', armAction: 'stride_bulk_quote_sent' },
                nocert:      { status: 'completed', armAction: 'stride_bulk_message' },
                oldinterest: { status: 'interest',  armAction: 'stride_bulk_message' },
            };
            if (queue && queueMap[queue]) {
                this.gridQueue = queue;
                this.gridFilters.status = queueMap[queue].status;
                this.gridArmedAction = queueMap[queue].armAction;
            }
            const trajId = parseInt(p.get('trajectory') || '0', 10);
            if (trajId > 0) this.gridFilters.trajectory_id = trajId;

            this.loadGrid(1);
        },

        // Fetch ONE server page (or grouped aggregates). Every filter/sort/group
        // change funnels here — the server returns the page + total. Never a
        // client-side slice of a full corpus (§5 hard rule).
        async loadGrid(page) {
            if (page != null) this.gridPagination.page = page;
            this._loadingViews.inschrijvingen = true;
            this.loading = true;
            this.errors.inschrijvingen = '';

            const f = this.gridFilters;
            const params = new URLSearchParams();
            params.set('page', String(this.gridPagination.page));
            params.set('per_page', String(this.gridPagination.perPage));
            if (f.status) params.set('status', f.status);
            if (f.edition_id) params.set('edition_id', String(f.edition_id));
            if (f.company_id) params.set('company_id', String(f.company_id));
            if (f.trajectory_id) params.set('trajectory_id', String(f.trajectory_id));
            if (f.q) params.set('q', f.q);
            if (this.gridSort.key) {
                params.set('sort', this.gridSort.key);
                params.set('order', this.gridSort.dir);
            }
            if (this.gridGroupBy) params.set('group_by', this.gridGroupBy);

            try {
                const data = await this.api(`/admin/registrations?${params.toString()}`);
                if (this.gridGroupBy) {
                    this.gridGroups = data.items || [];
                    this.gridRows = [];
                } else {
                    this.gridRows = data.items || [];
                    this.gridGroups = [];
                }
                this.gridStatusCounts = data.statusCounts || {};
                this.gridPagination = {
                    page: data.page || 1,
                    perPage: data.perPage || this.gridPagination.perPage,
                    total: data.total || 0,
                    totalPages: data.totalPages || 1,
                };
                this.gridLoaded = true;
            } catch (e) {
                this.errors.inschrijvingen = 'Inschrijvingen laden mislukt. Probeer opnieuw.';
            }
            this._loadingViews.inschrijvingen = false;
            this.loading = false;
        },

        // A filter/sort/group control changed → reset to page 1, drop the
        // cross-page select-all (the selection no longer matches the new filter),
        // and re-fetch.
        applyGridChange() {
            this.gridSelectAllFilter = false;
            this.loadGrid(1);
        },

        setGridStatus(status) {
            this.gridFilters.status = (this.gridFilters.status === status) ? '' : status;
            this.applyGridChange();
        },

        onGridGroupChange() {
            this.gridCollapsed = {};
            this.clearGridSelection();
            this.loadGrid(1);
        },

        sortGrid(key) {
            if (this.gridSort.key === key) {
                this.gridSort.dir = this.gridSort.dir === 'asc' ? 'desc' : 'asc';
            } else {
                this.gridSort.key = key;
                this.gridSort.dir = 'asc';
            }
            this.loadGrid(1);
        },

        resetGridFilters() {
            this.gridFilters = { status: '', edition_id: 0, company_id: 0, trajectory_id: 0, q: '' };
            this.gridQueue = '';
            this.gridArmedAction = null;
            this.clearGridSelection();
            this.loadGrid(1);
        },

        get gridHasFilters() {
            const f = this.gridFilters;
            return !!(f.status || f.edition_id || f.company_id || f.trajectory_id || f.q);
        },

        gridGroupKindLabel() {
            return {
                edition_id: 'Editie', status: 'Status',
                company_id: 'Organisatie', trajectory_id: 'Traject',
            }[this.gridGroupBy] || '';
        },

        toggleGridGroup(key) {
            this.gridCollapsed[key] = !this.gridCollapsed[key];
        },

        // group_value resolves to a human label depending on the group axis.
        gridGroupLabel(group) {
            const v = group.group_value;
            if (this.gridGroupBy === 'status') {
                return this.regStatusMeta[v]?.label || v || '—';
            }
            if (this.gridGroupBy === 'edition_id') {
                const ed = this.editionOptions.find(e => String(e.id) === String(v));
                return ed?.title || (v ? `Editie #${v}` : 'Geen editie');
            }
            if (this.gridGroupBy === 'trajectory_id') {
                return v ? `Traject #${v}` : 'Geen traject';
            }
            if (this.gridGroupBy === 'company_id') {
                return v ? `Organisatie #${v}` : 'Geen organisatie';
            }
            return v || '—';
        },

        // Compact textual offerte distribution for a grouped row header.
        gridDistSummary(verdeling) {
            if (!verdeling) return 'geen offertes';
            const parts = Object.entries(verdeling)
                .filter(([, n]) => n > 0)
                .map(([label, n]) => `${n} ${label.toLowerCase()}`);
            return parts.length ? parts.join(' · ') : 'geen offertes';
        },

        // ── Pagination ─────────────────────────────────────────────
        get gridRangeFrom() {
            return this.gridPagination.total === 0 ? 0
                : (this.gridPagination.page - 1) * this.gridPagination.perPage + 1;
        },
        get gridRangeTo() {
            return Math.min(this.gridPagination.page * this.gridPagination.perPage, this.gridPagination.total);
        },
        goGridPage(p) {
            if (p >= 1 && p <= this.gridPagination.totalPages && p !== this.gridPagination.page) {
                this.loadGrid(p);
            }
        },

        // Live per-status count for a funnel chip (Task 3.3 Part B). Server
        // returns a zero-filled map, so this is always a number.
        gridStatusCount(key) {
            return this.gridStatusCounts[key] ?? 0;
        },

        // ── Empty-state headline (F1 edges) ────────────────────────
        gridEmptyTitle() {
            const q = this.gridQueue;
            if (q === 'pending')     return 'Geen inschrijvingen wachten op goedkeuring';
            if (q === 'waitlist')    return 'Geen wachtlijst met vrije plaatsen';
            if (q === 'offerte')     return 'Geen openstaande offerte-opvolging';
            if (q === 'nocert')      return 'Iedereen heeft een certificaat';
            if (q === 'oldinterest') return 'Geen oude interesse meer';
            if (this.gridFilters.q)  return `Geen resultaten voor "${this.gridFilters.q}"`;
            return 'Geen inschrijvingen gevonden';
        },

        // ── Selection model (Task 3.2) ─────────────────────────────
        // Selection is over the LOADED page; select-all-filter carries the
        // FILTER (a flag), never 4k client rows (§5). The capped server-side
        // expansion is Phase 1F / Task 4.1.
        isGridSelected(id) { return !!this.gridSelected[id]; },
        toggleGridRow(id) {
            this.gridSelected[id] = !this.gridSelected[id];
            // Hand-deselecting a row breaks the "all on this filter" contract.
            if (!this.gridSelected[id]) this.gridSelectAllFilter = false;
        },
        get gridSelectedIds() {
            return Object.keys(this.gridSelected).filter(id => this.gridSelected[id]).map(Number);
        },
        get gridSelectedCount() {
            // When select-all-filter is armed the effective count is the whole
            // filtered set (the server total), not the loaded page.
            return this.gridSelectAllFilter ? this.gridPagination.total : this.gridSelectedIds.length;
        },
        get gridSelectedRows() {
            return this.gridRows.filter(r => this.gridSelected[r.id]);
        },
        get gridPageAllSelected() {
            const ids = this.gridRows.map(r => r.id);
            return ids.length > 0 && ids.every(id => this.gridSelected[id]);
        },
        get gridPageSomeSelected() {
            const ids = this.gridRows.map(r => r.id);
            return ids.some(id => this.gridSelected[id]) && !this.gridPageAllSelected;
        },
        toggleGridPage() {
            const ids = this.gridRows.map(r => r.id);
            const target = !this.gridPageAllSelected;
            ids.forEach(id => this.gridSelected[id] = target);
            if (!target) this.gridSelectAllFilter = false;
        },
        // "Select all N matching the filter" — sets a flag the bulk POST reads to
        // expand server-side (1F). Here it visibly marks the loaded page + arms
        // the flag; the confirm copy states it affects N rows.
        selectAllFiltered() {
            this.gridRows.forEach(r => this.gridSelected[r.id] = true);
            this.gridSelectAllFilter = true;
            this.showToast(`${this.gridPagination.total} inschrijvingen geselecteerd — de bulkactie draagt het filter.`, 'info');
        },
        clearGridSelection() {
            this.gridSelected = {};
            this.gridSelectAllFilter = false;
        },

        // ── Bulk bar — state-aware, derived from the transition mirror ─
        get gridSelectedStates() {
            return [...new Set(this.gridSelectedRows.map(r => r.status?.value))].filter(Boolean);
        },
        get gridBulkActions() {
            // Derived from the §2.1 transition mirror (Sibling-site audit 2),
            // never a hard-coded per-call set.
            return strideActionsForStates(this.gridSelectedStates);
        },
        get gridTopActions() { return this.gridBulkActions.slice(0, 3); },
        get gridOverflowActions() { return this.gridBulkActions.slice(3); },
        get gridMixedHint() {
            return this.gridSelectedCount > 0 && this.gridBulkActions.length === 0;
        },
        gridStatesSummary() {
            return this.gridSelectedStates.map(s => this.regStatusMeta[s]?.label || s).join(', ');
        },

        // POST to the ntdst/v1/action registry. Carries the per-action nonce
        // (§2.3) armed via StrideConfig.bulkNonces (printed by the PHP bootstrap).
        // Distinct from this.api() — that helper targets the stride/v1 namespace;
        // the action registry lives at ntdst/v1/action with an envelope response.
        async bulkApi(action, payload) {
            const base = (this.config.apiUrl || '').replace(/\/stride\/v1$/, '/ntdst/v1');
            const nonce = (this.config.bulkNonces || {})[action] || '';
            const response = await fetch(`${base}/action`, {
                method: 'POST',
                headers: {
                    'X-WP-Nonce': this.config.nonce,
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ ...payload, action, nonce }),
            });
            const json = await response.json().catch(() => ({}));
            // The registry returns { success, data } at HTTP 200 even on
            // application errors (denied/invalid nonce). Read the envelope.
            if (!json || json.success !== true) {
                const msg = json?.data?.message || 'Bulkactie mislukt.';
                throw new Error(msg);
            }
            return json.data;
        },

        async runGridBulk(actionId) {
            const action = STRIDE_SMART_ACTIONS.find(a => a.id === actionId);
            if (!action || this.gridBulkBusy) return;

            // Cross-page select-all warns about the true blast radius before firing.
            if (this.gridSelectAllFilter && this.gridPagination.total > this.gridRows.length) {
                if (!window.confirm(`Dit raakt ${this.gridPagination.total} inschrijvingen over alle pagina's. Doorgaan?`)) {
                    return;
                }
            }
            const ids = this.gridSelectedIds;
            if (ids.length === 0) return;

            this.gridOverflowOpen = false;
            this.gridBulkBusy = actionId;
            try {
                const report = await this.bulkApi(actionId, { ids });
                const succeeded = report.succeeded || [];
                const failed = report.failed || [];
                // Decorate failed rows with the person's name from the loaded page.
                const nameById = {};
                this.gridRows.forEach(r => { nameById[r.id] = r.user?.name || ('#' + r.id); });
                const failRows = failed.map(f => ({ ...f, name: nameById[f.id] || ('#' + f.id) }));

                this.gridResult = {
                    action: action.label,
                    total: report.total ?? ids.length,
                    succeeded,
                    failed: failRows,
                    ok: (report.summary?.ok) ?? succeeded.length,
                    err: (report.summary?.error) ?? failed.length,
                };

                if (failRows.length === 0) {
                    this.showToast(`${action.label}: ${this.gridResult.ok} geslaagd.`, 'success');
                    this.clearGridSelection();
                } else if (this.gridResult.ok === 0 && failRows.every(f => f.code === 'not_available')) {
                    // Deferred-stub path (drift #6): message/generate_doc return
                    // not_available per row. Render gracefully, no fake success.
                    this.showToast('Deze actie is nog niet beschikbaar.', 'info');
                    this.gridResultOpen = true;
                } else {
                    // Partial-failure report (F2 happy path).
                    this.gridResultOpen = true;
                }
                await this.loadGrid(this.gridPagination.page);
            } catch (e) {
                // Hard failure (denied / invalid nonce / batch cap).
                this.showToast(e.message || 'Bulkactie mislukt.', 'error');
            } finally {
                this.gridBulkBusy = null;
            }
        },

        closeGridResult() {
            this.gridResultOpen = false;
            // Keep only the failed rows selected so the user can retry just those.
            const failIds = new Set((this.gridResult?.failed || []).map(f => f.id));
            const next = {};
            failIds.forEach(id => { next[id] = true; });
            this.gridSelected = next;
            this.gridSelectAllFilter = false;
            this.gridResult = null;
        },

        // Row-click seam for Task 3.4 (Dossier). A no-op stub today — 3.4 wires
        // openDossier() here. Kept so 3.4 needs no template change to the rows.
        openGridRow(row) {
            // eslint-disable-next-line no-unused-expressions
            row; // placeholder — Dossier case-view is Task 3.4 (next sequential task)
        },

        // Helper: attendance bar colour class for a grid row's attendancePct.
        gridAttClass(pct) {
            if (pct == null) return '';
            if (pct >= 80) return 'ws-meter__fill--high';
            if (pct >= 60) return 'ws-meter__fill--mid';
            return 'ws-meter__fill--low';
        },

        // ==============================================================
        //  USER METHODS
        // ==============================================================

        async searchUsers(query) {
            this.userSearchQuery = query;
            if (query.length < 2) { this.userSearchResults = []; return; }
            try {
                this.userSearchResults = await this.api(`/admin/users/search?q=${encodeURIComponent(query)}`);
            } catch (e) { this.userSearchResults = []; }
        },

        async selectUser(userOrId) {
            // Can be called with a user object (from search results) or a numeric id
            const userId = typeof userOrId === 'object' ? userOrId.id : userOrId;
            try {
                const data = await this.api(`/admin/users/${userId}/detail`);

                // Status label maps
                const regStatusLabels = {
                    confirmed: 'Bevestigd', completed: 'Afgerond', cancelled: 'Geannuleerd',
                    pending: 'In afwachting', interest: 'Interesse', waitlist: 'Wachtlijst',
                };
                const quoteStatusLabels = { draft: 'Concept', sent: 'Verzonden', exported: 'Geëxporteerd', cancelled: 'Geannuleerd' };

                // Map registrations: add date and status_label
                const registrations = (data.registrations || []).map(reg => ({
                    ...reg,
                    date: reg.registered_at || reg.date || null,
                    status_label: regStatusLabels[reg.status] || reg.status || '—',
                }));

                // Map quotes: add number, edition_title, status_label
                const quotes = (data.quotes || []).map(q => ({
                    ...q,
                    number: q.number || q.quote_number || q.title || '—',
                    edition_title: q.edition_title || '—',
                    status_label: quoteStatusLabels[q.status] || q.status || '—',
                }));

                // Map attendance for userAttendanceSummary
                const attendance_summary = (data.attendance || []).map(att => ({
                    ...att,
                    attended: att.present || 0,
                    total: (att.present || 0) + (att.absent || 0) + (att.excused || 0),
                    hours: ((att.present || 0) * 4), // estimate
                }));

                // Flatten: merge user fields to top level for template access
                this.selectedUser = {
                    ...data,
                    id: data.user?.id,
                    name: data.user?.display_name || data.user?.name || '',
                    first_name: data.user?.first_name || '',
                    last_name: data.user?.last_name || '',
                    email: data.user?.email || '',
                    phone: data.user?.phone || '',
                    organisation: data.user?.organisation || '',
                    department: data.user?.department || '',
                    national_id: data.user?.national_id || '',
                    national_id_present: !!data.user?.national_id_present,
                    date_of_birth: data.user?.date_of_birth || '',
                    date_of_birth_present: !!data.user?.date_of_birth_present,
                    professional_license_number: data.user?.professional_license_number || '',
                    professional_license_number_present: !!data.user?.professional_license_number_present,
                    billing_company: data.user?.billing_company || '',
                    billing_vat: data.user?.billing_vat || '',
                    billing_address_1: data.user?.billing_address_1 || '',
                    billing_postcode: data.user?.billing_postcode || '',
                    billing_city: data.user?.billing_city || '',
                    invoice_email: data.user?.invoice_email || '',
                    gln_number: data.user?.gln_number || '',
                    profile_type: data.user?.profile_type || null,
                    isAnonymised: !!data.user?.is_anonymised,
                    anonymisedLabel: data.user?.anonymised_label || '',
                    anonymiseUrl: data.user?.anonymise_url || null,
                    registrations,
                    quotes,
                    attendance_summary,
                };

                // Reset profile-edit state when switching users.
                this.profileEdit = { personal: false, billing: false };
                this.profileDraft = {};
                this.revealed = {};
            } catch (e) {
                this.showToast('Gebruiker laden mislukt', 'error');
            }
        },

        clearUserSearch() {
            this.userSearchQuery = '';
            this.userSearchResults = [];
            this.selectedUser = null;
        },

        // Switch to the gebruikers view AND load user detail in one call.
        // Used by the actie-vereist card so admin actually sees the user-detail
        // panel (selectUser alone doesn't change view).
        // Records 'dashboard' as the return target so "← Terug" can take admin
        // back to the queue instead of leaving them stranded on an empty
        // gebruikers search.
        async viewUserInDetail(userId) {
            this.userDetailReturnTo = 'dashboard';
            this.switchView('gebruikers');
            await this.selectUser(userId);
        },

        // Close the user-detail panel and route admin back to wherever they
        // came from. If they navigated from the dashboard's actie-vereist card,
        // bring them back to the dashboard view; otherwise just clear the
        // selection and stay on the gebruikers search view.
        closeUserDetail() {
            const target = this.userDetailReturnTo;
            this.selectedUser = null;
            this.userDetailReturnTo = null;
            this.userProfileOpen = false;
            this.profileEdit = { personal: false, billing: false };
            this.profileDraft = {};
            this.revealed = {};
            if (target === 'dashboard') {
                this.switchView('dashboard');
            }
        },

        toggleUserProfile() {
            this.userProfileOpen = !this.userProfileOpen;
        },

        startProfileEdit(section) {
            const u = this.selectedUser || {};
            if (section === 'personal') {
                this.profileDraft = {
                    ...this.profileDraft,
                    first_name: u.first_name || '',
                    last_name: u.last_name || '',
                    email: u.email || '',
                    phone: u.phone || '',
                    organisation: u.organisation || '',
                    department: u.department || '',
                    national_id: '',
                    date_of_birth: '',
                    professional_license_number: '',
                };
            } else if (section === 'billing') {
                this.profileDraft = {
                    ...this.profileDraft,
                    company: u.billing_company || '',
                    vat_number: u.billing_vat || '',
                    address: u.billing_address_1 || '',
                    postal_code: u.billing_postcode || '',
                    city: u.billing_city || '',
                    invoice_email: u.invoice_email || '',
                    gln_number: u.gln_number || '',
                };
            }
            this.profileEdit[section] = true;
            this.userProfileOpen = true;
        },

        cancelProfileEdit(section) {
            this.profileEdit[section] = false;
        },

        async saveProfile(section) {
            if (!this.selectedUser?.id) return;

            // Build payload for the section being saved. Empty sensitive-field
            // strings are dropped so admins don't accidentally wipe national_id
            // by saving the form without re-typing it.
            const draft = this.profileDraft || {};
            const personalKeys = ['first_name', 'last_name', 'email', 'phone', 'organisation', 'department'];
            const sensitiveKeys = ['national_id', 'date_of_birth', 'professional_license_number'];
            const billingKeys = ['company', 'vat_number', 'address', 'postal_code', 'city', 'invoice_email', 'gln_number'];

            const payload = {};
            const keys = section === 'personal' ? [...personalKeys, ...sensitiveKeys] : billingKeys;
            for (const k of keys) {
                if (k in draft) {
                    const v = draft[k];
                    // Skip empty sensitive fields — protects existing values from accidental wipe.
                    if (sensitiveKeys.includes(k) && (v === '' || v == null)) continue;
                    payload[k] = v;
                }
            }

            this.profileSaving = true;
            try {
                await this.api(`/admin/users/${this.selectedUser.id}/profile`, {
                    method: 'POST',
                    body: JSON.stringify(payload),
                });
                this.profileEdit[section] = false;
                this.showToast('Gebruiker bijgewerkt', 'success');
                await this.selectUser(this.selectedUser.id);
                this.userProfileOpen = true;
            } catch (e) {
                this.showToast(e.message || 'Bijwerken mislukt', 'error');
            } finally {
                this.profileSaving = false;
            }
        },

        async revealField(field) {
            if (!this.selectedUser?.id) return;
            try {
                const data = await this.api(`/admin/users/${this.selectedUser.id}/reveal?field=${encodeURIComponent(field)}`);
                this.revealed = { ...this.revealed, [field]: data.value || '—' };
            } catch (e) {
                this.showToast(e.message || 'Tonen mislukt', 'error');
            }
        },

        // Approve a registration straight from a dashboard row.
        async approveFromRow(item) {
            if (!confirm('Inschrijving goedkeuren?')) return;
            try {
                const endpoint = item.type === 'post_approval'
                    ? '/admin/approve-post-course'
                    : '/admin/approve-registration';
                await this.api(endpoint, {
                    method: 'POST',
                    body: JSON.stringify({ registration_id: item.id }),
                });
                // Remove the row optimistically
                this.pendingApprovals.items = this.pendingApprovals.items.filter(i => i.id !== item.id);
                this.pendingApprovals.counts[item.type] = Math.max(0, this.pendingApprovals.counts[item.type] - 1);
                this.showToast('Inschrijving goedgekeurd', 'success');
            } catch (e) {
                this.showToast(e.message || 'Goedkeuring mislukt', 'error');
            }
        },

        async impersonateUser(userId) {
            if (!this.config.canManage) return;
            try {
                const result = await this.api(`/admin/users/${userId}/impersonate`, { method: 'POST' });
                if (result.redirect) {
                    window.location.href = result.redirect;
                }
            } catch (e) {
                this.showToast(e.message || 'Impersonatie mislukt', 'error');
            }
        },

        confirmAnonymise() {
            return confirm(
                'Anonimiseer deze gebruiker?\n\n' +
                'Persoonlijke gegevens worden verwijderd. Inschrijvingen blijven bewaard.\n' +
                'Deze actie kan niet ongedaan worden gemaakt.'
            );
        },

        // ==============================================================
        //  NOTIFICATION METHODS
        // ==============================================================

        toggleNotifications() {
            this.showNotifications = !this.showNotifications;
        },

        async markAllRead() {
            try {
                await this.api('/admin/notifications/read', { method: 'POST' });
                this.unreadCount = 0;
                this.notifications = this.notifications.map(n => ({ ...n, read: true }));
                this.showNotifications = false;
            } catch (e) { this.showToast('Markeren mislukt', 'error'); }
        },

        // ==============================================================
        //  FORMATTING METHODS
        // ==============================================================

        formatDate(dateStr) {
            if (!dateStr) return '\u2014';
            const d = new Date(dateStr);
            return d.toLocaleDateString('nl-BE', { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' });
        },

        formatShortDate(dateStr) {
            if (!dateStr) return '\u2014';
            const d = new Date(dateStr);
            return d.toLocaleDateString('nl-BE', { day: 'numeric', month: 'short' });
        },

        formatCurrency(value) {
            if (value == null) return '\u2014';
            return new Intl.NumberFormat('nl-BE', { style: 'currency', currency: 'EUR' }).format(value);
        },

        formatRelativeTime(timestamp) {
            if (!timestamp) return '';
            const now = Math.floor(Date.now() / 1000);
            const ts = typeof timestamp === 'number' ? timestamp : Math.floor(new Date(timestamp).getTime() / 1000);
            const diff = now - ts;
            if (diff < 60) return 'zojuist';
            if (diff < 3600) return Math.floor(diff / 60) + ' min geleden';
            if (diff < 86400) return Math.floor(diff / 3600) + ' uur geleden';
            if (diff < 604800) return Math.floor(diff / 86400) + ' dag(en) geleden';
            return new Date(ts * 1000).toLocaleDateString('nl-BE');
        },

        // Map audit-entry type → small inline SVG. Stroke uses currentColor
        // so the per-type CSS modifier controls the colour.
        activityIcon(type) {
            const svg = (path) =>
                '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + path + '</svg>';
            const icons = {
                // pencil — enrollment / registration
                enrollment: '<path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 1 1 3 3L7 19l-4 1 1-4Z"/>',
                // check-circle — attendance
                attendance: '<circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/>',
                // award — completion / certificate
                completion: '<circle cx="12" cy="8" r="6"/><path d="m15.5 13-1 7L12 18l-2.5 2-1-7"/>',
                // receipt — quote
                quote: '<path d="M4 4v17l3-2 3 2 3-2 3 2 3-2 1 2V4Z"/><path d="M8 9h8M8 13h8M8 17h5"/>',
                // user
                user: '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
                // calendar — edition
                edition: '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 11h18"/>',
                // log-in — auth
                auth: '<path d="M15 3h6v18h-6"/><path d="m10 17 5-5-5-5"/><path d="M15 12H3"/>',
                action: '<circle cx="12" cy="12" r="3"/>',
            };
            return svg(icons[type] || icons.action);
        },

        enrollmentPathLabel(path) {
            return {
                individual: 'Individueel',
                colleague:  'Collega',
                voucher:    'Voucher',
            }[path] || path || '—';
        },

        // ── Attendance helpers (for Aanwezigheid table) ────────────────────
        attendancePct(att) {
            if (!att) return 0;
            const total = att.total_sessions || (att.present + att.absent + att.excused);
            return total > 0 ? Math.round((att.present / total) * 100) : 0;
        },

        attendancePresent(reg) {
            return reg.attendance?.present ?? 0;
        },

        attendanceTotal(reg) {
            const att = reg.attendance;
            if (!att) return 0;
            return att.total_sessions || (att.present + att.absent + att.excused);
        },

        attendanceBarClass(att) {
            const pct = this.attendancePct(att);
            if (pct >= 80) return 'sd-attendance-cell-stack__bar-fill--good';
            if (pct >= 50) return 'sd-attendance-cell-stack__bar-fill--mid';
            return 'sd-attendance-cell-stack__bar-fill--low';
        },

        attendancePctClass(reg) {
            const pct = this.attendancePct(reg.attendance);
            if (pct >= 80) return 'sd-attendance-pct--good';
            if (pct >= 50) return 'sd-attendance-pct--mid';
            return 'sd-attendance-pct--low';
        },

        /**
         * Per-edition progress label for the Voortgang column.
         * Mirrors enrollment lifecycle, not LearnDash course completion.
         */
        progressLabel(reg) {
            if (reg.cancelled_at) return 'Geannuleerd';
            if (reg.completed_at) return 'Afgerond op ' + this.formatShortDate(reg.completed_at);
            return 'Bezig';
        },
    }));
});
