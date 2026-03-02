/**
 * LTI Admin Settings — Alpine.js Application
 *
 * Single-page app for managing LTI Platforms, Tools, Resources, and Logs.
 * Communicates with WP REST API via LtiConfig (wp_localize_script).
 */
document.addEventListener('alpine:init', () => {
    Alpine.data('ltiApp', () => ({
        // ── Tab State ──────────────────────────────────────
        tab: 'dashboard',

        // ── Dashboard State ────────────────────────────────
        stats: { platforms: 0, tools: 0, resources: 0 },
        copied: null,

        // ── Platforms State ────────────────────────────────
        platforms: [],
        platformsLoading: false,
        showPlatformModal: false,
        editingPlatformId: null,
        platformForm: {
            title: '',
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

        // ── Tools State ───────────────────────────────────
        tools: [],
        toolsLoading: false,
        showToolModal: false,
        editingToolId: null,
        toolForm: {
            title: '',
            client_id: '',
            deployment_id: '',
            launch_url: '',
            oidc_url: '',
            jwks_url: '',
            public_key: '',
        },

        // ── Resources State ───────────────────────────────
        resources: [],
        resourcesLoading: false,
        showResourceModal: false,
        editingResourceId: null,
        resourceForm: {
            title: '',
            tool_id: '',
            launch_url: '',
            course_id: '',
            description: '',
            custom_params: '',
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
            const validTabs = ['dashboard', 'platforms', 'tools', 'resources', 'logs', 'howto'];
            if (hash && validTabs.includes(hash)) {
                this.tab = hash;
                this.loadTabData(hash);
            }
        },

        setTab(t) {
            this.tab = t;
            window.location.hash = t;
            this.loadTabData(t);
        },

        loadTabData(t) {
            switch (t) {
                case 'platforms':
                    if (this.platforms.length === 0) this.loadPlatforms();
                    break;
                case 'tools':
                    if (this.tools.length === 0) this.loadTools();
                    break;
                case 'resources':
                    if (this.resources.length === 0) this.loadResources();
                    if (this.tools.length === 0) this.loadTools();
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
                const [platformRes, toolRes, resourceRes] = await Promise.all([
                    this.apiFetch(LtiConfig.restUrl + '/lti-platforms?per_page=1'),
                    this.apiFetch(LtiConfig.restUrl + '/lti-tools?per_page=1'),
                    this.apiFetch(LtiConfig.restUrl + '/lti-resources?per_page=1'),
                ]);
                this.stats.platforms = parseInt(platformRes.headers.get('X-WP-Total')) || 0;
                this.stats.tools = parseInt(toolRes.headers.get('X-WP-Total')) || 0;
                this.stats.resources = parseInt(resourceRes.headers.get('X-WP-Total')) || 0;
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

        // ── Tools CRUD ────────────────────────────────────
        async loadTools() {
            this.toolsLoading = true;
            try {
                this.tools = await this.apiFetchJSON(
                    LtiConfig.restUrl + '/lti-tools?per_page=100'
                );
            } catch (e) {
                this.notify('Failed to load tools: ' + e.message, 'error');
            } finally {
                this.toolsLoading = false;
            }
        },

        newTool() {
            this.editingToolId = null;
            this.toolForm = {
                title: '',
                client_id: '',
                deployment_id: '',
                launch_url: '',
                oidc_url: '',
                jwks_url: '',
                public_key: '',
            };
            this.showToolModal = true;
        },

        editTool(tool) {
            this.editingToolId = tool.id;
            this.toolForm = {
                title: tool.title.rendered,
                client_id: tool.meta.lti_client_id || '',
                deployment_id: tool.meta.lti_deployment_id || '',
                launch_url: tool.meta.lti_launch_url || '',
                oidc_url: tool.meta.lti_oidc_url || '',
                jwks_url: tool.meta.lti_jwks_url || '',
                public_key: tool.meta.lti_public_key || '',
            };
            this.showToolModal = true;
        },

        async saveTool() {
            this.saving = true;
            const body = {
                title: this.toolForm.title,
                status: 'publish',
                meta: {
                    lti_client_id: this.toolForm.client_id,
                    lti_deployment_id: this.toolForm.deployment_id,
                    lti_launch_url: this.toolForm.launch_url,
                    lti_oidc_url: this.toolForm.oidc_url,
                    lti_jwks_url: this.toolForm.jwks_url,
                    lti_public_key: this.toolForm.public_key,
                },
            };

            try {
                const url = this.editingToolId
                    ? LtiConfig.restUrl + '/lti-tools/' + this.editingToolId
                    : LtiConfig.restUrl + '/lti-tools';
                const method = this.editingToolId ? 'PUT' : 'POST';

                await this.apiFetchJSON(url, {
                    method,
                    body: JSON.stringify(body),
                });

                this.showToolModal = false;
                this.notify(this.editingToolId ? 'Tool updated' : 'Tool created');
                await this.loadTools();
                await this.loadStats();
            } catch (e) {
                this.notify('Failed to save tool: ' + e.message, 'error');
            } finally {
                this.saving = false;
            }
        },

        async deleteTool(id) {
            if (!confirm('Delete this tool? This cannot be undone.')) return;
            try {
                await this.apiFetchJSON(
                    LtiConfig.restUrl + '/lti-tools/' + id + '?force=true',
                    { method: 'DELETE' }
                );
                this.notify('Tool deleted');
                await this.loadTools();
                await this.loadStats();
            } catch (e) {
                this.notify('Failed to delete tool: ' + e.message, 'error');
            }
        },

        testLaunchTool(tool) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = LtiConfig.homeUrl + '/lti/platform/launch';
            form.target = '_blank';

            const fields = {
                _wpnonce: LtiConfig.nonce,
                tool_id: tool.id,
                message_type: 'LtiResourceLinkRequest',
            };

            for (const [name, value] of Object.entries(fields)) {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = name;
                input.value = value;
                form.appendChild(input);
            }

            document.body.appendChild(form);
            form.submit();
            form.remove();
        },

        // ── Resources CRUD ────────────────────────────────
        async loadResources() {
            this.resourcesLoading = true;
            try {
                this.resources = await this.apiFetchJSON(
                    LtiConfig.restUrl + '/lti-resources?per_page=100'
                );
            } catch (e) {
                this.notify('Failed to load resources: ' + e.message, 'error');
            } finally {
                this.resourcesLoading = false;
            }
        },

        newResource() {
            this.editingResourceId = null;
            this.resourceForm = {
                title: '',
                tool_id: '',
                launch_url: '',
                course_id: '',
                description: '',
                custom_params: '',
            };
            this.showResourceModal = true;
        },

        editResource(resource) {
            this.editingResourceId = resource.id;
            this.resourceForm = {
                title: resource.title.rendered,
                tool_id: resource.meta.lti_tool_id || '',
                launch_url: resource.meta.lti_launch_url || '',
                course_id: resource.meta.lti_course_id || '',
                description: resource.meta.lti_description || '',
                custom_params: resource.meta.lti_custom_params || '',
            };
            this.showResourceModal = true;
        },

        async saveResource() {
            this.saving = true;
            const body = {
                title: this.resourceForm.title,
                status: 'publish',
                meta: {
                    lti_tool_id: this.resourceForm.tool_id ? parseInt(this.resourceForm.tool_id) : 0,
                    lti_launch_url: this.resourceForm.launch_url,
                    lti_course_id: this.resourceForm.course_id,
                    lti_description: this.resourceForm.description,
                    lti_custom_params: this.resourceForm.custom_params,
                },
            };

            try {
                const url = this.editingResourceId
                    ? LtiConfig.restUrl + '/lti-resources/' + this.editingResourceId
                    : LtiConfig.restUrl + '/lti-resources';
                const method = this.editingResourceId ? 'PUT' : 'POST';

                await this.apiFetchJSON(url, {
                    method,
                    body: JSON.stringify(body),
                });

                this.showResourceModal = false;
                this.notify(this.editingResourceId ? 'Resource updated' : 'Resource created');
                await this.loadResources();
                await this.loadStats();
            } catch (e) {
                this.notify('Failed to save resource: ' + e.message, 'error');
            } finally {
                this.saving = false;
            }
        },

        async deleteResource(id) {
            if (!confirm('Delete this resource? This cannot be undone.')) return;
            try {
                await this.apiFetchJSON(
                    LtiConfig.restUrl + '/lti-resources/' + id + '?force=true',
                    { method: 'DELETE' }
                );
                this.notify('Resource deleted');
                await this.loadResources();
                await this.loadStats();
            } catch (e) {
                this.notify('Failed to delete resource: ' + e.message, 'error');
            }
        },

        launchResource(resource) {
            window.open(LtiConfig.homeUrl + '/lti/launch/' + resource.id, '_blank');
        },

        getToolName(toolId) {
            const tool = this.tools.find(t => t.id === parseInt(toolId));
            return tool ? tool.title.rendered : '-';
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
