/**
 * LTI Admin Settings — Alpine.js Application
 *
 * Single-page app for managing LTI Platforms and Logs.
 * Communicates with WP REST API via LtiConfig (wp_localize_script).
 */
document.addEventListener('alpine:init', () => {
    Alpine.data('ltiApp', () => ({
        // ── Tab State ──────────────────────────────────────
        tab: 'dashboard',

        // ── Dashboard State ────────────────────────────────
        stats: { platforms: 0 },
        copied: null,

        // ── Platforms State ────────────────────────────────
        platforms: [],
        platformsLoading: false,
        showPlatformModal: false,
        editingPlatformId: null,
        platformForm: {
            title: '',
            mode: '1.3',
            consumer_key: '',
            consumer_secret: '',
            platform_id: '',
            client_id: '',
            deployment_id: '',
            auth_endpoint: '',
            token_endpoint: '',
            jwks_endpoint: '',
            rsa_key: '',
            kid: '',
            enabled: true,
            role_instructor: 'administrator',
            role_learner: 'subscriber',
        },

        // ── Logs State ────────────────────────────────────
        logs: [],
        logsLoading: false,
        logChannel: 'lti',
        logDate: new Date().toISOString().split('T')[0],

        // ── Notification ──────────────────────────────────
        notification: null,
        notificationType: 'success',

        // ── Saving State ──────────────────────────────────
        saving: false,

        // ── Init ──────────────────────────────────────────
        init() {
            this.parseHash();
            window.addEventListener('hashchange', () => this.parseHash());
            this.loadStats();
        },

        parseHash() {
            const hash = window.location.hash.replace('#', '');
            const validTabs = ['dashboard', 'platforms', 'launch-test', 'logs', 'howto'];
            if (hash && validTabs.includes(hash)) {
                this.tab = hash;
                this.loadTabData(hash);
            }
        },

        setTab(t) {
            this.tab = t;
            window.location.hash = t;
            // loadTabData is triggered by hashchange → parseHash
        },

        loadTabData(t) {
            switch (t) {
                case 'platforms':
                    this.loadPlatforms();
                    break;
                case 'logs':
                    if (this.logs.length === 0) this.loadLogs();
                    break;
            }
        },

        // ── API Helper ────────────────────────────────────
        async apiFetch(url, options = {}) {
            const response = await fetch(url, {
                headers: {
                    'X-WP-Nonce': LtiConfig.nonce,
                    'Content-Type': 'application/json',
                },
                ...options,
            });
            if (!response.ok) {
                const error = await response.json().catch(() => ({}));
                throw new Error(error.message || `Request failed (${response.status})`);
            }
            return response;
        },

        async apiFetchJSON(url, options = {}) {
            const response = await this.apiFetch(url, options);
            return response.json();
        },

        // ── Dashboard ─────────────────────────────────────
        async loadStats() {
            try {
                const platformRes = await this.apiFetch(LtiConfig.restUrl + '/lti-platforms?per_page=1');
                this.stats.platforms = parseInt(platformRes.headers.get('X-WP-Total')) || 0;
            } catch (e) {
                console.error('Failed to load stats:', e);
            }
        },

        copyToClipboard(text, key) {
            if (!navigator.clipboard) return;
            navigator.clipboard.writeText(text).then(() => {
                this.copied = key;
                setTimeout(() => { this.copied = null; }, 2000);
            });
        },

        // ── Legacy Mode Helpers ─────────────────────────────
        generateRandomString(length) {
            const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
            let result = '';
            const values = crypto.getRandomValues(new Uint8Array(length));
            for (let i = 0; i < length; i++) result += chars[values[i] % chars.length];
            return result;
        },

        handleModeChange() {
            if (this.platformForm.mode === 'legacy' && !this.platformForm.consumer_key) {
                this.platformForm.consumer_key = 'NDLTI-' + this.generateRandomString(12);
                this.platformForm.consumer_secret = this.generateRandomString(32);
            }
        },

        regenerateSecret() {
            if (confirm('Regenerate secret? The old secret will stop working immediately.')) {
                this.platformForm.consumer_secret = this.generateRandomString(32);
            }
        },

        // ── Platforms CRUD ────────────────────────────────
        async loadPlatforms() {
            this.platformsLoading = true;
            try {
                this.platforms = await this.apiFetchJSON(
                    LtiConfig.restUrl + '/lti-platforms?per_page=100'
                );
            } catch (e) {
                this.notify('Failed to load platforms: ' + e.message, 'error');
            } finally {
                this.platformsLoading = false;
            }
        },

        newPlatform() {
            this.editingPlatformId = null;
            this.platformForm = {
                title: '',
                mode: '1.3',
                consumer_key: '',
                consumer_secret: '',
                platform_id: '',
                client_id: '',
                deployment_id: '',
                auth_endpoint: '',
                token_endpoint: '',
                jwks_endpoint: '',
                rsa_key: '',
                kid: '',
                enabled: true,
                role_instructor: 'administrator',
                role_learner: 'subscriber',
            };
            this.showPlatformModal = true;
        },

        editPlatform(platform) {
            this.editingPlatformId = platform.id;
            this.platformForm = {
                title: platform.title.rendered,
                mode: platform.meta.lti_mode || '1.3',
                consumer_key: platform.meta.lti_consumer_key || '',
                consumer_secret: platform.meta.lti_consumer_secret || '',
                platform_id: platform.meta.lti_platform_id || '',
                client_id: platform.meta.lti_client_id || '',
                deployment_id: platform.meta.lti_deployment_id || '',
                auth_endpoint: platform.meta.lti_auth_endpoint || '',
                token_endpoint: platform.meta.lti_token_endpoint || '',
                jwks_endpoint: platform.meta.lti_jwks_endpoint || '',
                rsa_key: platform.meta.lti_rsa_key || '',
                kid: platform.meta.lti_kid || '',
                enabled: !!platform.meta.lti_enabled,
                role_instructor: platform.meta.lti_role_instructor || 'administrator',
                role_learner: platform.meta.lti_role_learner || 'subscriber',
            };
            this.showPlatformModal = true;
        },

        async savePlatform() {
            this.saving = true;
            const body = {
                title: this.platformForm.title,
                status: 'publish',
                meta: {
                    lti_mode: this.platformForm.mode,
                    lti_consumer_key: this.platformForm.consumer_key,
                    lti_consumer_secret: this.platformForm.consumer_secret,
                    lti_platform_id: this.platformForm.platform_id,
                    lti_client_id: this.platformForm.client_id,
                    lti_deployment_id: this.platformForm.deployment_id,
                    lti_auth_endpoint: this.platformForm.auth_endpoint,
                    lti_token_endpoint: this.platformForm.token_endpoint,
                    lti_jwks_endpoint: this.platformForm.jwks_endpoint,
                    lti_rsa_key: this.platformForm.rsa_key,
                    lti_kid: this.platformForm.kid,
                    lti_enabled: this.platformForm.enabled,
                    lti_role_instructor: this.platformForm.role_instructor,
                    lti_role_learner: this.platformForm.role_learner,
                },
            };

            try {
                const url = this.editingPlatformId
                    ? LtiConfig.restUrl + '/lti-platforms/' + this.editingPlatformId
                    : LtiConfig.restUrl + '/lti-platforms';
                const method = this.editingPlatformId ? 'PUT' : 'POST';

                await this.apiFetchJSON(url, {
                    method,
                    body: JSON.stringify(body),
                });

                this.showPlatformModal = false;
                this.notify(this.editingPlatformId ? 'Platform updated' : 'Platform created');
                await this.loadPlatforms();
                await this.loadStats();
            } catch (e) {
                this.notify('Failed to save platform: ' + e.message, 'error');
            } finally {
                this.saving = false;
            }
        },

        async deletePlatform(id) {
            if (!confirm('Delete this platform? This cannot be undone.')) return;
            try {
                await this.apiFetchJSON(
                    LtiConfig.restUrl + '/lti-platforms/' + id + '?force=true',
                    { method: 'DELETE' }
                );
                this.notify('Platform deleted');
                await this.loadPlatforms();
                await this.loadStats();
            } catch (e) {
                this.notify('Failed to delete platform: ' + e.message, 'error');
            }
        },

        // ── Logs ──────────────────────────────────────────
        async loadLogs() {
            this.logsLoading = true;
            try {
                this.logs = await this.apiFetchJSON(
                    LtiConfig.restUrl.replace('/wp/v2', '/netdust-lti/v1')
                    + '/logs?channel=' + encodeURIComponent(this.logChannel)
                    + '&date=' + encodeURIComponent(this.logDate)
                    + '&limit=100'
                );
            } catch (e) {
                this.notify('Failed to load logs: ' + e.message, 'error');
            } finally {
                this.logsLoading = false;
            }
        },

        setLogChannel(channel) {
            this.logChannel = channel;
            this.loadLogs();
        },

        formatLogContext(context) {
            if (!context || Object.keys(context).length === 0) return '';
            try {
                return JSON.stringify(context, null, 2);
            } catch {
                return String(context);
            }
        },

        // ── Notification ──────────────────────────────────
        notify(message, type = 'success') {
            this.notification = message;
            this.notificationType = type;
            setTimeout(() => { this.notification = null; }, 4000);
        },
    }));
});
