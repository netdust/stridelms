document.addEventListener('alpine:init', () => {
    Alpine.data('strideApp', () => ({
        // ── Routing ──────────────────────────────────────────────
        view: 'dashboard',
        loading: false,

        // ── Config ───────────────────────────────────────────────
        config: window.StrideConfig || {},

        // ── Dashboard home ───────────────────────────────────────
        stats: { upcomingEditions: 0, totalRegistrations: 0, pendingQuotes: 0, todaySessions: 0, actionsNeeded: 0, actionCount: 0 },
        actionQueue: [],
        upcomingSessions: [],
        activityFeed: [],
        healthChecks: { registration: 'green', mail: 'green' },

        // ── Editions ─────────────────────────────────────────────
        editions: [],
        editionFilters: { search: '', status: '', date_from: '', date_to: '', course_tag: 0 },
        editionPagination: { page: 1, totalPages: 1, total: 0 },
        courseTags: [],
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
        quickSendTarget: null,

        // ── Trajectories ─────────────────────────────────────────
        trajectories: [],
        trajectoryFilters: { search: '', status: '' },
        trajectoryPagination: { page: 1, totalPages: 1, total: 0 },
        selectedTrajectory: null,
        trajectoryTab: 'details',

        // ── Users ────────────────────────────────────────────────
        userSearchQuery: '',
        userSearchResults: [],
        dashboardUserResults: [],
        selectedUser: null,

        // ── Notifications ────────────────────────────────────────
        notifications: [],
        unreadCount: 0,
        showNotifications: false,

        // ── UI ───────────────────────────────────────────────────
        toast: null,

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

        /** Audit log for the selected user */
        get userAuditLog() {
            return this.selectedUser?.audit_trail || [];
        },

        /** Attendance summary for the selected user */
        get userAttendanceSummary() {
            return this.selectedUser?.attendance_summary || [];
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
            const validViews = ['dashboard', 'edities', 'offertes', 'trajecten', 'gebruikers'];
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
        },

        // ==============================================================
        //  VIEW DATA LOADING
        // ==============================================================

        loadViewData(view) {
            if (view === 'edities') {
                if (this.editions.length === 0) this.loadEditions();
                if (this.courseTags.length === 0) this.loadCourseTags();
                this.$nextTick(() => this.initDateRangePicker());
            } else if (view === 'offertes') {
                if (this.quotes.length === 0) this.loadQuotes();
                if (this.quoteEditions.length === 0) this.loadQuoteEditions();
            } else if (view === 'trajecten') {
                if (this.trajectories.length === 0) this.loadTrajectories();
            } else if (view === 'dashboard') {
                this.loadDashboard();
            }
        },

        // ==============================================================
        //  DASHBOARD METHODS
        // ==============================================================

        async loadDashboard() {
            this.loading = true;
            try {
                const [stats, queue, sessions, activity, health, notifs] = await Promise.allSettled([
                    this.api('/admin/stats'),
                    this.api('/admin/action-queue'),
                    this.api('/admin/editions?view=agenda&per_page=10'),
                    this.api('/admin/activity?limit=10'),
                    this.api('/admin/health-checks'),
                    this.api('/admin/notifications'),
                ]);
                if (stats.status === 'fulfilled') {
                    const s = stats.value;
                    s.actionsNeeded = s.actionCount ?? s.actionsNeeded ?? 0;
                    this.stats = s;
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
            this.loading = true;
            const params = new URLSearchParams({
                page: this.editionPagination.page,
                per_page: 20,
                view: 'agenda',
                search: this.editionFilters.search,
                status: this.editionFilters.status,
                date_from: this.editionFilters.date_from,
                date_to: this.editionFilters.date_to,
                course_tag: this.editionFilters.course_tag,
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
            } catch (e) {
                this.showToast('Edities laden mislukt', 'error');
            }
            this.loading = false;
        },

        async loadCourseTags() {
            try { this.courseTags = await this.api('/admin/course-tags'); } catch (e) {}
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
            this.editionFilters = { search: '', status: '', date_from: '', date_to: '', course_tag: 0 };
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
                    withdrawn: 'Teruggetrokken',
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
            } catch (e) { this.showToast('Offertes laden mislukt', 'error'); }
            this.loading = false;
        },

        async loadQuoteEditions() {
            try {
                const data = await this.api('/admin/editions?per_page=100&view=list');
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

        showQuickSend(quote) {
            this.quickSendTarget = quote;
        },

        async confirmQuickSend() {
            if (!this.quickSendTarget) return;
            const quoteId = this.quickSendTarget.id;
            try {
                await this.api(`/admin/quotes/${quoteId}/send`, { method: 'POST' });
                this.showToast('Offerte verzonden', 'success');
                this.quickSendTarget = null;
                this.loadQuotes(this.quotePagination.page);
            } catch (e) {
                this.showToast('Offerte verzenden mislukt \u2014 probeer opnieuw', 'error');
            }
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
            } catch (e) { this.showToast('Trajecten laden mislukt', 'error'); }
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
                    withdrawn: 'Teruggetrokken',
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
                    email: data.user?.email || '',
                    phone: data.user?.phone || '',
                    organisation: data.user?.organisation || '',
                    department: data.user?.department || '',
                    profile_type: data.user?.profile_type || null,
                    registrations,
                    quotes,
                    attendance_summary,
                };
            } catch (e) {
                this.showToast('Gebruiker laden mislukt', 'error');
            }
        },

        clearUserSearch() {
            this.userSearchQuery = '';
            this.userSearchResults = [];
            this.selectedUser = null;
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

        formatCurrency(cents) {
            if (cents == null) return '\u2014';
            return new Intl.NumberFormat('nl-BE', { style: 'currency', currency: 'EUR' }).format(cents / 100);
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
    }));
});
