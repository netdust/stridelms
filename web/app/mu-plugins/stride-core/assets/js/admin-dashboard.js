document.addEventListener('alpine:init', () => {
    Alpine.data('strideApp', () => ({
        // State
        user: StrideConfig.user,
        view: 'dashboard',
        loading: true,

        // Stats
        stats: {
            upcomingEditions: 0,
            totalRegistrations: 0,
            pendingQuotes: 0,
            todaySessions: 0
        },

        // Editions state
        editions: [],
        editionsLoading: false,
        editionFilters: { search: '', status: '', dateFrom: '', dateTo: '', courseTag: 0 },
        editionView: 'agenda', // 'agenda' (default) or 'list'
        editionPage: 1,
        editionPages: 1,
        selectedEdition: null,
        courseTags: [],
        dateRangePicker: null,
        editionTab: 'students',
        registrations: [],
        registrationsLoading: false,

        // Quotes state
        quotes: [],
        quotesLoading: false,
        quoteFilters: { search: '', status: '', editionId: '' },
        quotePage: 1,
        quotePages: 1,
        quoteEditions: [],
        selectedQuote: null,
        quoteTab: 'details',

        // Trajectories state
        trajectories: [],
        trajectoriesLoading: false,
        trajectoryFilters: { search: '', status: '' },
        trajectoryPage: 1,
        trajectoryPages: 1,
        selectedTrajectory: null,
        trajectoryTab: 'details',

        // Pending approvals
        pendingApprovals: [],

        // Initialize
        init() {
            this.parseHash();
            window.addEventListener('hashchange', () => this.parseHash());
            this.loadStats();

            // Watch view changes to load data
            this.$watch('view', (newView) => {
                this.loadViewData(newView);
                // Update hash to match view
                if (window.location.hash !== '#/' + newView) {
                    history.replaceState(null, '', '#/' + newView);
                }
            });
        },

        parseHash() {
            const hash = window.location.hash.replace('#/', '') || 'dashboard';
            this.view = hash;
            this.loadViewData(hash);
        },

        loadViewData(view) {
            // Load data when switching views
            if (view === 'editions') {
                if (this.editions.length === 0) {
                    this.loadEditions();
                }
                if (this.courseTags.length === 0) {
                    this.loadCourseTags();
                }
                // Initialize date picker after DOM update
                this.$nextTick(() => this.initDateRangePicker());
            } else if (view === 'quotes') {
                if (this.quotes.length === 0) {
                    this.loadQuotes();
                }
                if (this.quoteEditions.length === 0) {
                    this.loadQuoteEditions();
                }
            } else if (view === 'trajectories' && this.trajectories.length === 0) {
                this.loadTrajectories();
            }
        },

        async loadStats() {
            this.loading = true;
            try {
                const response = await fetch(`${StrideConfig.apiUrl}/admin/stats`, {
                    headers: {
                        'X-WP-Nonce': StrideConfig.nonce
                    }
                });
                if (response.ok) {
                    this.stats = await response.json();
                }
            } catch (e) {
                console.error('Failed to load stats:', e);
            }
            this.loading = false;
            // Also load pending approvals on dashboard
            this.loadPendingApprovals();
        },

        async loadPendingApprovals() {
            try {
                const response = await fetch(`${StrideConfig.apiUrl}/admin/pending-approvals`, {
                    headers: { 'X-WP-Nonce': StrideConfig.nonce }
                });
                if (response.ok) {
                    const data = await response.json();
                    this.pendingApprovals = (data.items || []).map(item => ({
                        ...item,
                        approving: false,
                    }));
                }
            } catch (e) {
                console.error('Failed to load pending approvals:', e);
            }
        },

        async approveRegistration(registrationId) {
            const approval = this.pendingApprovals.find(a => a.id === registrationId);
            if (!approval) return;

            // Use different endpoint for post-course vs enrollment approval
            const endpoint = approval.type === 'post_approval'
                ? '/admin/approve-post-course'
                : '/admin/approve-registration';

            approval.approving = true;
            try {
                const response = await fetch(`${StrideConfig.apiUrl}${endpoint}`, {
                    method: 'POST',
                    headers: {
                        'X-WP-Nonce': StrideConfig.nonce,
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({ registration_id: registrationId }),
                });
                if (response.ok) {
                    this.pendingApprovals = this.pendingApprovals.filter(a => a.id !== registrationId);
                } else {
                    const err = await response.json();
                    alert(err.message || 'Goedkeuring mislukt.');
                    approval.approving = false;
                }
            } catch (e) {
                console.error('Approve failed:', e);
                alert('Verbindingsfout. Probeer opnieuw.');
                approval.approving = false;
            }
        },

        async loadEditions() {
            this.editionsLoading = true;
            try {
                const params = new URLSearchParams({
                    page: this.editionPage,
                    per_page: 20,
                    view: this.editionView
                });
                if (this.editionFilters.search) {
                    params.append('search', this.editionFilters.search);
                }
                if (this.editionFilters.status) {
                    params.append('status', this.editionFilters.status);
                }
                if (this.editionFilters.dateFrom) {
                    params.append('date_from', this.editionFilters.dateFrom);
                }
                if (this.editionFilters.dateTo) {
                    params.append('date_to', this.editionFilters.dateTo);
                }
                if (this.editionFilters.courseTag) {
                    params.append('course_tag', this.editionFilters.courseTag);
                }

                const response = await fetch(`${StrideConfig.apiUrl}/admin/editions?${params}`, {
                    headers: {
                        'X-WP-Nonce': StrideConfig.nonce
                    }
                });
                if (response.ok) {
                    const data = await response.json();
                    this.editions = data.items || [];
                    this.editionPages = data.totalPages || 1;
                }
            } catch (e) {
                console.error('Failed to load editions:', e);
            }
            this.editionsLoading = false;
        },

        async loadCourseTags() {
            try {
                const response = await fetch(`${StrideConfig.apiUrl}/admin/course-tags`, {
                    headers: {
                        'X-WP-Nonce': StrideConfig.nonce
                    }
                });
                if (response.ok) {
                    this.courseTags = await response.json();
                }
            } catch (e) {
                console.error('Failed to load course tags:', e);
            }
        },

        initDateRangePicker() {
            if (this.dateRangePicker) return;
            const el = this.$refs.dateRange;
            if (!el || typeof flatpickr === 'undefined') return;

            this.dateRangePicker = flatpickr(el, {
                mode: 'range',
                dateFormat: 'Y-m-d',
                locale: typeof flatpickr.l10ns.nl !== 'undefined' ? 'nl' : 'default',
                allowInput: true,
                onChange: (selectedDates) => {
                    if (selectedDates.length === 2) {
                        this.editionFilters.dateFrom = selectedDates[0].toISOString().split('T')[0];
                        this.editionFilters.dateTo = selectedDates[1].toISOString().split('T')[0];
                        this.loadEditions();
                    } else if (selectedDates.length === 0) {
                        this.editionFilters.dateFrom = '';
                        this.editionFilters.dateTo = '';
                        this.loadEditions();
                    }
                }
            });
        },

        async openEdition(id) {
            try {
                const response = await fetch(`${StrideConfig.apiUrl}/admin/editions/${id}`, {
                    headers: {
                        'X-WP-Nonce': StrideConfig.nonce
                    }
                });
                if (response.ok) {
                    this.selectedEdition = await response.json();
                    this.editionTab = 'students';
                    this.loadRegistrations(id);
                }
            } catch (e) {
                console.error('Failed to load edition:', e);
            }
        },

        async loadRegistrations(editionId) {
            this.registrationsLoading = true;
            this.registrations = [];
            try {
                const response = await fetch(`${StrideConfig.apiUrl}/admin/editions/${editionId}/registrations`, {
                    headers: {
                        'X-WP-Nonce': StrideConfig.nonce
                    }
                });
                if (response.ok) {
                    const data = await response.json();
                    // Update sessions on the selected edition
                    if (this.selectedEdition && data.sessions) {
                        this.selectedEdition.sessions = data.sessions;
                    }
                    // Flatten user data for template compatibility
                    this.registrations = (data.items || []).map(reg => ({
                        ...reg,
                        userId: reg.user?.id,
                        name: reg.user?.name,
                        email: reg.user?.email,
                    }));
                }
            } catch (e) {
                console.error('Failed to load registrations:', e);
            }
            this.registrationsLoading = false;
        },

        async toggleAttendance(sessionId, userId, currentStatus) {
            // Cycle: null -> present -> absent -> excused -> null
            const statusCycle = [null, 'present', 'absent', 'excused'];
            const currentIndex = statusCycle.indexOf(currentStatus);
            const nextStatus = statusCycle[(currentIndex + 1) % statusCycle.length];

            try {
                const response = await fetch(`${StrideConfig.apiUrl}/admin/attendance`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': StrideConfig.nonce
                    },
                    body: JSON.stringify({
                        session_id: sessionId,
                        user_id: userId,
                        status: nextStatus
                    })
                });

                if (response.ok) {
                    // Update local state
                    const reg = this.registrations.find(r => r.userId === userId);
                    if (reg) {
                        if (!reg.attendance) {
                            reg.attendance = {};
                        }
                        if (nextStatus) {
                            reg.attendance[sessionId] = nextStatus;
                        } else {
                            delete reg.attendance[sessionId];
                        }
                    }
                }
            } catch (e) {
                console.error('Failed to update attendance:', e);
            }
        },

        formatDate(dateStr) {
            if (!dateStr) return '';
            const date = new Date(dateStr);
            const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
            return `${date.getDate()} ${months[date.getMonth()]} ${date.getFullYear()}`;
        },

        formatShortDate(dateStr) {
            if (!dateStr) return '';
            const date = new Date(dateStr);
            const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
            return `${date.getDate()} ${months[date.getMonth()]}`;
        },

        formatDateFull(dateStr) {
            if (!dateStr) return '';
            const date = new Date(dateStr);
            const days = ['Zo', 'Ma', 'Di', 'Wo', 'Do', 'Vr', 'Za'];
            const months = ['jan', 'feb', 'mrt', 'apr', 'mei', 'jun', 'jul', 'aug', 'sep', 'okt', 'nov', 'dec'];
            return `${days[date.getDay()]} ${date.getDate()} ${months[date.getMonth()]}`;
        },

        async loadQuotes() {
            this.quotesLoading = true;
            try {
                const params = new URLSearchParams({
                    page: this.quotePage,
                });
                if (this.quoteFilters.search) {
                    params.append('search', this.quoteFilters.search);
                }
                if (this.quoteFilters.status) {
                    params.append('status', this.quoteFilters.status);
                }
                if (this.quoteFilters.editionId) {
                    params.append('edition_id', this.quoteFilters.editionId);
                }
                const response = await fetch(`${StrideConfig.apiUrl}/admin/quotes?${params}`, {
                    headers: { 'X-WP-Nonce': StrideConfig.nonce }
                });
                if (response.ok) {
                    const data = await response.json();
                    this.quotes = data.items;
                    this.quotePages = data.totalPages;
                }
            } catch (e) {
                console.error('Failed to load quotes:', e);
            }
            this.quotesLoading = false;
        },

        async loadQuoteEditions() {
            try {
                // Load all published editions for the dropdown
                const response = await fetch(`${StrideConfig.apiUrl}/admin/editions?per_page=100&view=list`, {
                    headers: { 'X-WP-Nonce': StrideConfig.nonce }
                });
                if (response.ok) {
                    const data = await response.json();
                    this.quoteEditions = data.items || [];
                }
            } catch (e) {
                console.error('Failed to load editions for quote filter:', e);
            }
        },

        openQuote(quote) {
            this.selectedQuote = quote;
            this.quoteTab = 'details';
        },

        async loadTrajectories() {
            this.trajectoriesLoading = true;
            try {
                const params = new URLSearchParams({
                    page: this.trajectoryPage,
                    search: this.trajectoryFilters.search,
                    status: this.trajectoryFilters.status
                });
                const response = await fetch(`${StrideConfig.apiUrl}/admin/trajectories?${params}`, {
                    headers: { 'X-WP-Nonce': StrideConfig.nonce }
                });
                if (response.ok) {
                    const data = await response.json();
                    this.trajectories = data.items;
                    this.trajectoryPages = data.totalPages;
                }
            } catch (e) {
                console.error('Failed to load trajectories:', e);
            }
            this.trajectoriesLoading = false;
        },

        openTrajectory(trajectory) {
            this.selectedTrajectory = trajectory;
            this.trajectoryTab = 'details';
        },

        formatRelativeTime(dateStr) {
            if (!dateStr) return '';
            const date = new Date(dateStr);
            const now = new Date();
            const diffMs = now - date;
            const diffMins = Math.floor(diffMs / 60000);
            const diffHours = Math.floor(diffMs / 3600000);
            const diffDays = Math.floor(diffMs / 86400000);

            if (diffMins < 1) return 'Zojuist';
            if (diffMins < 60) return `${diffMins} min geleden`;
            if (diffHours < 24) return `${diffHours} uur geleden`;
            if (diffDays === 1) return 'Gisteren';
            if (diffDays < 7) return `${diffDays} dagen geleden`;
            return this.formatDate(dateStr);
        },

        formatCurrency(amount) {
            return new Intl.NumberFormat('nl-BE', { style: 'currency', currency: 'EUR' }).format(amount || 0);
        }
    }));
});
