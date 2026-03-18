/**
 * NTDST Audit Log Admin App
 */
function strideAuditApp() {
    return {
        tab: 'log',
        entries: [],
        loading: false,
        page: 1,
        perPage: 50,
        totalPages: 1,
        totalEntries: 0,
        userResults: [],
        notification: null,
        notificationType: 'success',
        filters: {
            from: '',
            to: '',
            entity_type: '',
            actor_id: '',
            user_search: ''
        },

        init() {
            // Initialize date pickers
            this.$nextTick(() => {
                if (typeof flatpickr !== 'undefined') {
                    flatpickr(this.$refs.dateFrom, {
                        dateFormat: 'Y-m-d',
                        locale: 'nl',
                        defaultDate: new Date(Date.now() - 30 * 24 * 60 * 60 * 1000)
                    });
                    flatpickr(this.$refs.dateTo, {
                        dateFormat: 'Y-m-d',
                        locale: 'nl',
                        defaultDate: new Date()
                    });
                }
            });

            // Set default date range
            const now = new Date();
            const thirtyDaysAgo = new Date(now.getTime() - 30 * 24 * 60 * 60 * 1000);
            this.filters.from = thirtyDaysAgo.toISOString().split('T')[0];
            this.filters.to = now.toISOString().split('T')[0];

            // Load initial data
            this.loadEntries();
        },

        async loadEntries() {
            this.loading = true;

            try {
                const params = new URLSearchParams({
                    from: this.filters.from,
                    to: this.filters.to,
                    page: this.page,
                    per_page: this.perPage
                });

                if (this.filters.entity_type) {
                    params.append('entity_type', this.filters.entity_type);
                }
                if (this.filters.actor_id) {
                    params.append('actor_id', this.filters.actor_id);
                }

                const response = await fetch(
                    NtdstAuditConfig.restUrl + '?' + params.toString(),
                    {
                        headers: {
                            'X-WP-Nonce': NtdstAuditConfig.restNonce
                        }
                    }
                );

                if (!response.ok) {
                    throw new Error('Failed to load audit entries');
                }

                const data = await response.json();
                this.entries = data.entries.map(e => ({ ...e, expanded: false }));
                this.totalEntries = data.total;
                this.totalPages = Math.ceil(data.total / this.perPage);

            } catch (error) {
                console.error('Error loading audit entries:', error);
                this.notify('Error loading audit entries', 'error');
            } finally {
                this.loading = false;
            }
        },

        async searchUsers() {
            if (this.filters.user_search.length < 2) {
                this.userResults = [];
                return;
            }

            try {
                const response = await fetch(
                    NtdstAuditConfig.restUrl + '/users?search=' + encodeURIComponent(this.filters.user_search),
                    {
                        headers: {
                            'X-WP-Nonce': NtdstAuditConfig.restNonce
                        }
                    }
                );

                if (response.ok) {
                    this.userResults = await response.json();
                }
            } catch (error) {
                console.error('Error searching users:', error);
            }
        },

        exportCsv() {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = NtdstAuditConfig.ajaxUrl;

            const fields = {
                action: 'ntdst_audit_export_csv',
                nonce: NtdstAuditConfig.nonce,
                from: this.filters.from,
                to: this.filters.to,
                entity_type: this.filters.entity_type,
                actor_id: this.filters.actor_id
            };

            for (const [key, value] of Object.entries(fields)) {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = key;
                input.value = value;
                form.appendChild(input);
            }

            document.body.appendChild(form);
            form.submit();
            document.body.removeChild(form);
        },

        formatDate(dateStr) {
            const date = new Date(dateStr);
            return date.toLocaleString('nl-NL', {
                year: 'numeric',
                month: '2-digit',
                day: '2-digit',
                hour: '2-digit',
                minute: '2-digit'
            });
        },

        formatContext(contextStr) {
            if (!contextStr) return '{}';
            try {
                return JSON.stringify(JSON.parse(contextStr), null, 2);
            } catch {
                return contextStr;
            }
        },

        notify(message, type = 'success') {
            this.notification = message;
            this.notificationType = type;
            setTimeout(() => { this.notification = null; }, 4000);
        },
    };
}
