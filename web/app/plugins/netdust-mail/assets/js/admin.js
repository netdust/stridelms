/**
 * Netdust Mail Admin — Alpine.js Application
 *
 * Single-page app for managing email templates, settings, and reference docs.
 * Communicates with WP REST API via MailConfig (wp_localize_script).
 */
document.addEventListener('alpine:init', () => {
    Alpine.data('mailApp', () => ({
        // ── Tab State ──────────────────────────────────────
        tab: 'dashboard',

        // ── Dashboard State ────────────────────────────────
        stats: { total: 0, active: 0, draft: 0 },

        // ── Templates State ────────────────────────────────
        templates: [],
        templatesLoading: false,
        showTemplateModal: false,
        editingTemplateId: null,
        templateForm: {
            title: '',
            subject: '',
            body: '',
            category: '',
            trigger: '',
            status: 'draft',
        },

        // ── Settings State ─────────────────────────────────
        settingsForm: {
            fromName: MailConfig.settings.fromName,
            fromEmail: MailConfig.settings.fromEmail,
        },
        settingsSaving: false,

        // ── Notification ───────────────────────────────────
        notification: null,
        notificationType: 'success',

        // ── Saving State ───────────────────────────────────
        saving: false,

        // ── Editor State ───────────────────────────────────
        editorReady: false,

        // ── Init ───────────────────────────────────────────
        init() {
            this.parseHash();
            window.addEventListener('hashchange', () => this.parseHash());
            this.loadStats();

            // Watch modal open/close for TinyMCE lifecycle
            this.$watch('showTemplateModal', (open) => {
                if (open) {
                    this.$nextTick(() => this.initEditor());
                } else {
                    this.destroyEditor();
                }
            });
        },

        parseHash() {
            const hash = window.location.hash.replace('#', '');
            const validTabs = ['dashboard', 'templates', 'settings', 'reference'];
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
                case 'templates':
                    if (this.templates.length === 0) this.loadTemplates();
                    break;
            }
        },

        // ── API Helper ─────────────────────────────────────
        async apiFetch(url, options = {}) {
            const response = await fetch(url, {
                headers: {
                    'X-WP-Nonce': MailConfig.nonce,
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

        // ── Dashboard ──────────────────────────────────────
        async loadStats() {
            try {
                const res = await this.apiFetch(MailConfig.restUrl + '/mail-templates?per_page=100&status=any');
                const templates = await res.json();
                this.stats.total = templates.length;
                this.stats.active = templates.filter(t => (t.meta?._ndmail_status || 'draft') === 'active').length;
                this.stats.draft = templates.filter(t => (t.meta?._ndmail_status || 'draft') === 'draft').length;
            } catch (e) {
                console.error('Failed to load stats:', e);
            }
        },

        // ── Templates CRUD ─────────────────────────────────
        async loadTemplates() {
            this.templatesLoading = true;
            try {
                this.templates = await this.apiFetchJSON(
                    MailConfig.restUrl + '/mail-templates?per_page=100&status=any&context=edit'
                );
            } catch (e) {
                this.notify('Failed to load templates: ' + e.message, 'error');
            } finally {
                this.templatesLoading = false;
            }
        },

        newTemplate() {
            this.editingTemplateId = null;
            this.templateForm = {
                title: '',
                subject: '',
                body: '',
                category: '',
                trigger: '',
                status: 'draft',
            };
            this.showTemplateModal = true;
        },

        editTemplate(template) {
            this.editingTemplateId = template.id;
            this.templateForm = {
                title: template.title.rendered,
                subject: template.meta?._ndmail_subject || '',
                body: template.content?.raw || '',
                category: template.meta?._ndmail_category || '',
                trigger: template.meta?._ndmail_trigger || '',
                status: template.meta?._ndmail_status || 'draft',
            };
            this.showTemplateModal = true;
        },

        async saveTemplate() {
            // Sync editor content before saving
            this.syncEditorContent();
            this.saving = true;

            const body = {
                title: this.templateForm.title,
                content: this.templateForm.body,
                status: 'publish',
                meta: {
                    _ndmail_subject: this.templateForm.subject,
                    _ndmail_category: this.templateForm.category,
                    _ndmail_trigger: this.templateForm.trigger,
                    _ndmail_status: this.templateForm.status,
                },
            };

            try {
                const url = this.editingTemplateId
                    ? MailConfig.restUrl + '/mail-templates/' + this.editingTemplateId
                    : MailConfig.restUrl + '/mail-templates';
                const method = this.editingTemplateId ? 'PUT' : 'POST';

                await this.apiFetchJSON(url, {
                    method,
                    body: JSON.stringify(body),
                });

                this.showTemplateModal = false;
                this.notify(this.editingTemplateId ? 'Template updated' : 'Template created');
                await this.loadTemplates();
                await this.loadStats();
            } catch (e) {
                this.notify('Failed to save template: ' + e.message, 'error');
            } finally {
                this.saving = false;
            }
        },

        async deleteTemplate(id) {
            if (!confirm('Delete this template? This cannot be undone.')) return;
            try {
                await this.apiFetchJSON(
                    MailConfig.restUrl + '/mail-templates/' + id + '?force=true',
                    { method: 'DELETE' }
                );
                this.notify('Template deleted');
                await this.loadTemplates();
                await this.loadStats();
            } catch (e) {
                this.notify('Failed to delete template: ' + e.message, 'error');
            }
        },

        // ── Template helpers ───────────────────────────────
        getCategoryLabel(key) {
            return MailConfig.categoryOptions[key] || key || '—';
        },

        getTriggerLabel(key) {
            if (!key) return 'Manual';
            const t = MailConfig.triggers[key];
            return t?.label || key;
        },

        // ── TinyMCE Editor ─────────────────────────────────
        initEditor() {
            const editorId = 'ndmail-editor';

            // Set initial content on the textarea
            const textarea = document.getElementById(editorId);
            if (textarea) {
                textarea.value = this.templateForm.body;
            }

            // Initialize WordPress TinyMCE
            if (typeof wp !== 'undefined' && wp.editor) {
                wp.editor.initialize(editorId, {
                    tinymce: {
                        wpautop: true,
                        plugins: 'charmap colorpicker hr lists paste tabfocus textcolor wordpress wpautoresize wpeditimage wpemoji wpgallery wplink wptextpattern',
                        toolbar1: 'formatselect | bold italic underline strikethrough | bullist numlist | blockquote hr | alignleft aligncenter alignright | link unlink | wp_adv',
                        toolbar2: 'forecolor backcolor | pastetext removeformat charmap | outdent indent | undo redo | wp_help',
                        height: 350,
                    },
                    quicktags: true,
                    mediaButtons: false,
                });
                this.editorReady = true;
            }
        },

        destroyEditor() {
            const editorId = 'ndmail-editor';
            if (this.editorReady && typeof wp !== 'undefined' && wp.editor) {
                wp.editor.remove(editorId);
                this.editorReady = false;
            }
        },

        syncEditorContent() {
            const editorId = 'ndmail-editor';
            const editor = typeof tinymce !== 'undefined' ? tinymce.get(editorId) : null;

            if (editor && !editor.hidden) {
                // Visual mode — get from TinyMCE
                this.templateForm.body = editor.getContent();
            } else {
                // Text/HTML mode — get from textarea
                const textarea = document.getElementById(editorId);
                if (textarea) {
                    this.templateForm.body = textarea.value;
                }
            }
        },

        insertSmartCodeEditor(code) {
            const editorId = 'ndmail-editor';
            const editor = typeof tinymce !== 'undefined' ? tinymce.get(editorId) : null;

            if (editor && !editor.hidden) {
                // Visual mode — insert via TinyMCE
                editor.insertContent(code);
            } else {
                // Text/HTML mode — insert into textarea at cursor
                const textarea = document.getElementById(editorId);
                if (!textarea) return;
                const start = textarea.selectionStart;
                const end = textarea.selectionEnd;
                const text = textarea.value;
                textarea.value = text.substring(0, start) + code + text.substring(end);
                textarea.selectionStart = textarea.selectionEnd = start + code.length;
                textarea.focus();
            }
        },

        // ── Settings ───────────────────────────────────────
        async saveSettings() {
            this.settingsSaving = true;
            try {
                const url = MailConfig.restUrl.replace('/wp/v2', '/netdust-mail/v1') + '/settings';
                await this.apiFetchJSON(url, {
                    method: 'POST',
                    body: JSON.stringify(this.settingsForm),
                });
                this.notify('Settings saved');
            } catch (e) {
                this.notify('Failed to save settings: ' + e.message, 'error');
            } finally {
                this.settingsSaving = false;
            }
        },

        // ── Notification ───────────────────────────────────
        notify(message, type = 'success') {
            this.notification = message;
            this.notificationType = type;
            setTimeout(() => { this.notification = null; }, 4000);
        },
    }));
});
