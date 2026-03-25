/**
 * Stride Settings — Alpine.js Component
 *
 * Tabbed settings page with AJAX save per tab.
 * Uses ntdstAPI.call() for API communication.
 *
 * @package stride
 */

function strideSettingsApp() {
    return {

        // State
        activeTab: 'general',
        saving: false,
        message: '',
        messageType: 'success',

        // General settings
        general: {
            trajectory_slug: '',
            edition_slug: '',
            siteUrl: '',
        },

        // Profile types
        types: [],
        availableIcons: [],
        editingIndex: -1,
        editForm: {},
        isNew: false,
        confirmDelete: null,

        // Notification rules
        notifications: {
            capacity_threshold:  { enabled: true, value: 80 },
            session_approaching: { enabled: true, value: 1 },
            stale_quote:         { enabled: true, value: 7 },
            pending_approval:    { enabled: true },
            edition_starting:    { enabled: true, value: 3 },
            incomplete_tasks:    { enabled: true, value: 7 },
        },

        // Company details
        company: {
            name: '',
            address: '',
            postal_code: '',
            city: '',
            country: 'België',
            vat: '',
            email: '',
            phone: '',
            bank_account: '',
            logo: '',
        },

        /**
         * Initialize from localized data and URL hash.
         */
        init() {
            const data = window.strideSettings || {};

            // General tab
            if (data.general) {
                this.general = { ...data.general };
            }

            // Profile types tab
            if (data.profileTypes) {
                this.types = data.profileTypes.types || [];
                this.availableIcons = data.profileTypes.availableIcons || [];
            }

            // Notifications tab
            if (data.notifications) {
                this.notifications = { ...this.notifications, ...data.notifications };
            }

            // Company tab
            if (data.company) {
                this.company = { ...this.company, ...data.company };
            }

            // Read tab from URL hash
            const hash = window.location.hash.replace('#', '');
            if (['general', 'company', 'profile-types', 'notifications'].includes(hash)) {
                this.activeTab = hash;
            }
        },

        // =====================================================================
        // Navigation
        // =====================================================================

        /**
         * Switch active tab and update URL hash.
         */
        switchTab(tab) {
            this.activeTab = tab;
            window.location.hash = tab;
            this.message = '';
        },

        // =====================================================================
        // Messaging
        // =====================================================================

        /**
         * Show status message with auto-dismiss.
         */
        showMessage(text, type = 'success') {
            this.message = text;
            this.messageType = type;
            setTimeout(() => { this.message = ''; }, 4000);
        },

        // =====================================================================
        // General Tab
        // =====================================================================

        /**
         * Save URL slug settings.
         */
        async saveGeneral() {
            this.saving = true;
            try {
                const result = await this.apiCall('stride_save_settings', {
                    tab: 'general',
                    trajectory_slug: this.general.trajectory_slug,
                    edition_slug: this.general.edition_slug,
                });
                this.showMessage(result.message || 'Instellingen opgeslagen.');
            } catch (err) {
                this.showMessage(err.message || 'Opslaan mislukt.', 'error');
            } finally {
                this.saving = false;
            }
        },

        // =====================================================================
        // Company Tab
        // =====================================================================

        /**
         * Open WP media library to select a logo image.
         */
        selectLogo() {
            const frame = wp.media({
                title: 'Kies bedrijfslogo',
                button: { text: 'Gebruik als logo' },
                multiple: false,
                library: { type: 'image' },
            });
            frame.on('select', () => {
                const attachment = frame.state().get('selection').first().toJSON();
                this.company.logo = attachment.url;
            });
            frame.open();
        },

        /**
         * Remove the current logo.
         */
        removeLogo() {
            this.company.logo = '';
        },

        /**
         * Save company details.
         */
        async saveCompany() {
            this.saving = true;
            try {
                const result = await this.apiCall('stride_save_settings', {
                    tab: 'company',
                    ...this.company,
                });
                this.showMessage(result.message || 'Bedrijfsgegevens opgeslagen.');
            } catch (err) {
                this.showMessage(err.message || 'Opslaan mislukt.', 'error');
            } finally {
                this.saving = false;
            }
        },

        // =====================================================================
        // Notifications Tab
        // =====================================================================

        /**
         * Save notification settings.
         * Called from tab-notifications.php via saveTab('notifications').
         */
        async saveNotifications() {
            this.saving = true;
            try {
                const params = { tab: 'notifications' };

                // Flatten notification rules into key_enabled / key_value params
                for (const [key, rule] of Object.entries(this.notifications)) {
                    params[key + '_enabled'] = rule.enabled ? '1' : '0';
                    if (rule.value !== undefined) {
                        params[key + '_value'] = String(rule.value);
                    }
                }

                const result = await this.apiCall('stride_save_settings', params);
                this.showMessage(result.message || 'Meldingsinstellingen opgeslagen.');
            } catch (err) {
                this.showMessage(err.message || 'Opslaan mislukt.', 'error');
            } finally {
                this.saving = false;
            }
        },

        /**
         * Generic tab save dispatcher.
         * Used by tabs that call saveTab('tabName') from their template.
         */
        saveTab(tab) {
            switch (tab) {
                case 'general':       return this.saveGeneral();
                case 'company':       return this.saveCompany();
                case 'profile-types': return this.saveProfileTypes();
                case 'notifications': return this.saveNotifications();
            }
        },

        // =====================================================================
        // Profile Types Tab
        // =====================================================================

        /**
         * Convert text to URL-safe slug.
         */
        slugify(text) {
            return text
                .toString()
                .toLowerCase()
                .trim()
                .replace(/\s+/g, '-')
                .replace(/[^\w-]+/g, '')
                .replace(/--+/g, '-')
                .replace(/^-+|-+$/g, '');
        },

        /**
         * Start adding a new profile type.
         */
        startAdd() {
            this.editForm = {
                slug: '',
                label: '',
                description: '',
                color: '#3B82F6',
                icon: 'users',
                order: this.types.length,
            };
            this.isNew = true;
            this.editingIndex = this.types.length;
        },

        /**
         * Start editing an existing profile type.
         */
        startEdit(index) {
            this.editForm = { ...this.types[index] };
            this.isNew = false;
            this.editingIndex = index;
        },

        /**
         * Cancel editing.
         */
        cancelEdit() {
            this.editingIndex = -1;
            this.editForm = {};
            this.isNew = false;
        },

        /**
         * Save the current edit form (add or update), then persist.
         */
        async saveType() {
            if (!this.editForm.label || !this.editForm.label.trim()) {
                this.showMessage('Label is verplicht.', 'error');
                return;
            }

            // Auto-generate slug from label if empty
            if (!this.editForm.slug) {
                this.editForm.slug = this.slugify(this.editForm.label);
            }

            if (this.isNew) {
                this.types.push({ ...this.editForm, userCount: 0 });
            } else {
                this.types[this.editingIndex] = {
                    ...this.editForm,
                    userCount: this.types[this.editingIndex].userCount || 0,
                };
            }

            this.editingIndex = -1;
            this.editForm = {};
            this.isNew = false;

            await this.saveProfileTypes();
        },

        /**
         * Request delete confirmation.
         */
        requestDelete(index) {
            this.confirmDelete = index;
        },

        /**
         * Cancel delete.
         */
        cancelDelete() {
            this.confirmDelete = null;
        },

        /**
         * Confirm and execute delete, then persist.
         */
        async confirmDeleteType() {
            if (this.confirmDelete === null) return;

            this.types.splice(this.confirmDelete, 1);
            this.confirmDelete = null;

            // Reset editing if the deleted row was being edited
            this.editingIndex = -1;
            this.editForm = {};
            this.isNew = false;

            await this.saveProfileTypes();
        },

        /**
         * Persist all profile types to server.
         */
        async saveProfileTypes() {
            this.saving = true;
            try {
                // Reindex order
                const ordered = this.types.map((t, i) => ({ ...t, order: i }));

                const result = await this.apiCall('stride_save_settings', {
                    tab: 'profile-types',
                    types: JSON.stringify(ordered),
                });

                // Update local data with server response (includes fresh userCounts)
                if (result.types) {
                    this.types = result.types;
                }

                this.showMessage(result.message || 'Profieltypes opgeslagen.');
            } catch (err) {
                this.showMessage(err.message || 'Opslaan mislukt.', 'error');
            } finally {
                this.saving = false;
            }
        },

        // =====================================================================
        // API Communication
        // =====================================================================

        /**
         * Call API using ntdstAPI.call() with fallback to direct REST.
         */
        async apiCall(action, params) {
            if (typeof ntdstAPI !== 'undefined' && ntdstAPI.call) {
                return ntdstAPI.call(action, params);
            }

            // Fallback: direct REST call
            const nonceResponse = await fetch('/wp-json/ntdst/v1/get_nonce', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({ action }),
            });
            const nonceData = await nonceResponse.json();

            if (!nonceData.nonce && nonceData.data?.nonce) {
                nonceData.nonce = nonceData.data.nonce;
            }

            const response = await fetch('/wp-json/ntdst/v1/action', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({
                    action,
                    nonce: nonceData.nonce || nonceData.data?.nonce,
                    ...params,
                }),
            });

            const result = await response.json();

            if (result.success === false || result.error) {
                throw new Error(result.error || result.message || 'API error');
            }

            return result.data || result;
        },
    };
}
