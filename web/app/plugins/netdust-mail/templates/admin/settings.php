<?php defined('ABSPATH') || exit; ?>
<style>[x-cloak] { display: none !important; }</style>

<div class="wrap ntdst-app" x-data="mailApp()" x-cloak>

    <!-- ── Page Title ──────────────────────────────────── -->
    <div class="ntdst-page-title-bar">
        <h1>Netdust Mail <span class="ntdst-version">v<?php echo esc_html(NDMAIL_VERSION); ?></span></h1>
    </div>

    <!-- ── Notification Toast ──────────────────────────── -->
    <div x-show="notification" x-transition.opacity.duration.300ms
         class="ntdst-notification"
         :class="{
             'ntdst-notification-success': notificationType === 'success',
             'ntdst-notification-error': notificationType === 'error',
             'ntdst-notification-info': notificationType === 'info',
         }">
        <span x-text="notification"></span>
    </div>

    <!-- ── Sidebar Layout ──────────────────────────────── -->
    <div class="ntdst-layout">
        <aside class="ntdst-sidebar">
            <nav class="ntdst-sidebar-nav">
                <button class="ntdst-sidebar-item" :class="{ active: tab === 'dashboard' }" @click.prevent="setTab('dashboard')">
                    <span class="dashicons dashicons-dashboard"></span> Dashboard
                </button>
                <button class="ntdst-sidebar-item" :class="{ active: tab === 'templates' }" @click.prevent="setTab('templates')">
                    <span class="dashicons dashicons-email-alt"></span> Templates
                </button>
                <button class="ntdst-sidebar-item" :class="{ active: tab === 'settings' }" @click.prevent="setTab('settings')">
                    <span class="dashicons dashicons-admin-settings"></span> Settings
                </button>
                <button class="ntdst-sidebar-item" :class="{ active: tab === 'reference' }" @click.prevent="setTab('reference')">
                    <span class="dashicons dashicons-book"></span> Reference
                </button>
            </nav>
        </aside>

        <div class="ntdst-main">

        <!-- ════════════════════════════════════════════════
             Dashboard Tab
             ════════════════════════════════════════════════ -->
        <div x-show="tab === 'dashboard'">

            <div class="ntdst-stats">
                <div class="ntdst-stat-card">
                    <div class="ntdst-stat-icon">
                        <span class="dashicons dashicons-email-alt"></span>
                    </div>
                    <div>
                        <div class="ntdst-stat-value" x-text="stats.total"></div>
                        <div class="ntdst-stat-label">Templates</div>
                    </div>
                </div>
                <div class="ntdst-stat-card">
                    <div class="ntdst-stat-icon">
                        <span class="dashicons dashicons-yes-alt"></span>
                    </div>
                    <div>
                        <div class="ntdst-stat-value" x-text="stats.active"></div>
                        <div class="ntdst-stat-label">Active</div>
                    </div>
                </div>
                <div class="ntdst-stat-card">
                    <div class="ntdst-stat-icon">
                        <span class="dashicons dashicons-edit"></span>
                    </div>
                    <div>
                        <div class="ntdst-stat-value" x-text="stats.draft"></div>
                        <div class="ntdst-stat-label">Draft</div>
                    </div>
                </div>
            </div>

            <div class="ntdst-card">
                <div class="ntdst-card-header">
                    <h3 class="ntdst-card-title"><span class="dashicons dashicons-info"></span> Quick Start</h3>
                </div>
                <div class="ntdst-card-body">
                    <p>Manage email templates with SmartCode placeholders and action triggers.</p>
                    <ul style="margin:12px 0 0 16px;">
                        <li><strong>Templates</strong> — Create and edit email templates with SmartCode support</li>
                        <li><strong>Settings</strong> — Configure default sender name and email</li>
                        <li><strong>Reference</strong> — Browse available SmartCodes, triggers, and PDF generators</li>
                    </ul>
                </div>
            </div>

        </div>

        <!-- ════════════════════════════════════════════════
             Templates Tab
             ════════════════════════════════════════════════ -->
        <div x-show="tab === 'templates'">
            <div class="ntdst-page-header">
                <h2 class="ntdst-section-title">Templates</h2>
                <button @click="newTemplate()" class="ntdst-btn ntdst-btn-primary">
                    <span class="dashicons dashicons-plus-alt2" style="font-size:16px;width:16px;height:16px;"></span> New Template
                </button>
            </div>

            <div x-show="templatesLoading" class="ntdst-loading"></div>

            <div x-show="!templatesLoading && templates.length === 0" class="ntdst-empty-state">
                <span class="dashicons dashicons-email-alt"></span>
                <p>No email templates yet. Create one to get started.</p>
            </div>

            <div x-show="!templatesLoading && templates.length > 0" class="ntdst-card">
                <div class="ntdst-card-body">
                    <table class="ntdst-table">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Subject</th>
                                <th>Category</th>
                                <th>Trigger</th>
                                <th>Status</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="template in templates" :key="template.id">
                                <tr>
                                    <td x-text="template.title.rendered" style="font-weight:500;"></td>
                                    <td><code x-text="template.meta?._ndmail_subject || '—'" style="font-size:12px;"></code></td>
                                    <td x-text="getCategoryLabel(template.meta?._ndmail_category)"></td>
                                    <td>
                                        <span :class="template.meta?._ndmail_trigger ? '' : 'ntdst-text-muted'"
                                              x-text="getTriggerLabel(template.meta?._ndmail_trigger)"></span>
                                    </td>
                                    <td>
                                        <span class="ntdst-badge"
                                              :class="(template.meta?._ndmail_status || 'draft') === 'active' ? 'ntdst-badge-success' : 'ntdst-badge-muted'"
                                              x-text="(template.meta?._ndmail_status || 'draft') === 'active' ? 'Active' : 'Draft'"></span>
                                    </td>
                                    <td class="ntdst-table-actions">
                                        <button @click="editTemplate(template)" class="ntdst-btn ntdst-btn-sm ntdst-btn-ghost">Edit</button>
                                        <button @click="deleteTemplate(template.id)" class="ntdst-btn-icon danger" title="Delete">
                                            <span class="dashicons dashicons-trash"></span>
                                        </button>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Template Modal -->
            <div x-show="showTemplateModal" class="ntdst-modal-overlay" @click.self="showTemplateModal = false" @keydown.escape.window="showTemplateModal = false">
                <div class="ntdst-modal" @click.stop style="max-width:800px;">
                    <div class="ntdst-modal-header">
                        <h3 x-text="editingTemplateId ? 'Edit Template' : 'New Template'"></h3>
                        <button @click="showTemplateModal = false" class="ntdst-modal-close">&times;</button>
                    </div>
                    <div class="ntdst-modal-body">
                        <div class="ntdst-form-group">
                            <label class="ntdst-form-label">Name</label>
                            <input x-model="templateForm.title" class="ntdst-form-input" placeholder="e.g. Welcome Email">
                        </div>

                        <div class="ntdst-form-group">
                            <label class="ntdst-form-label">Subject Line</label>
                            <input x-model="templateForm.subject" class="ntdst-form-input" placeholder="e.g. Welcome {{user.first_name}}!">
                            <p class="ntdst-form-help">Supports SmartCodes</p>
                        </div>

                        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;">
                            <div class="ntdst-form-group">
                                <label class="ntdst-form-label">Category</label>
                                <select x-model="templateForm.category" class="ntdst-form-select">
                                    <option value="">— Select —</option>
                                    <template x-for="[key, label] in Object.entries(MailConfig.categoryOptions)" :key="key">
                                        <option :value="key" x-text="label"></option>
                                    </template>
                                </select>
                            </div>
                            <div class="ntdst-form-group">
                                <label class="ntdst-form-label">Trigger</label>
                                <select x-model="templateForm.trigger" class="ntdst-form-select">
                                    <option value="">— None (manual) —</option>
                                    <template x-for="[key, config] in Object.entries(MailConfig.triggers)" :key="key">
                                        <option :value="key" x-text="config.label || key"></option>
                                    </template>
                                </select>
                            </div>
                            <div class="ntdst-form-group">
                                <label class="ntdst-form-label">Status</label>
                                <select x-model="templateForm.status" class="ntdst-form-select">
                                    <option value="draft">Draft</option>
                                    <option value="active">Active</option>
                                </select>
                            </div>
                        </div>

                        <div class="ntdst-form-group">
                            <label class="ntdst-form-label">Email Body</label>
                            <div class="ndmail-smartcode-palette">
                                <template x-for="code in MailConfig.smartcodesFlat" :key="code.code">
                                    <button type="button" class="ndmail-smartcode-pill"
                                            @click="insertSmartCodeEditor(code.code)"
                                            x-text="code.code"
                                            :title="code.label"></button>
                                </template>
                            </div>
                            <div x-ref="editorWrap">
                                <textarea id="ndmail-editor" style="width:100%;"></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="ntdst-modal-footer">
                        <button @click="showTemplateModal = false" class="ntdst-btn ntdst-btn-ghost">Cancel</button>
                        <button @click="saveTemplate()" class="ntdst-btn ntdst-btn-primary" :disabled="saving">
                            <span x-show="saving">Saving...</span>
                            <span x-show="!saving" x-text="editingTemplateId ? 'Update' : 'Create'"></span>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- ════════════════════════════════════════════════
             Settings Tab
             ════════════════════════════════════════════════ -->
        <div x-show="tab === 'settings'">

            <div class="ntdst-card">
                <div class="ntdst-card-header">
                    <h3 class="ntdst-card-title"><span class="dashicons dashicons-email-alt"></span> Default Sender</h3>
                </div>
                <div class="ntdst-card-body">
                    <div class="ntdst-form-group">
                        <label class="ntdst-form-label">From Name</label>
                        <input x-model="settingsForm.fromName" class="ntdst-form-input" style="max-width:400px;" placeholder="WordPress default">
                        <p class="ntdst-form-help">Leave empty to use WordPress default.</p>
                    </div>
                    <div class="ntdst-form-group">
                        <label class="ntdst-form-label">From Email</label>
                        <input x-model="settingsForm.fromEmail" type="email" class="ntdst-form-input" style="max-width:400px;" placeholder="WordPress default">
                        <p class="ntdst-form-help">Leave empty to use WordPress default.</p>
                    </div>
                </div>
            </div>

            <button @click="saveSettings()" class="ntdst-btn ntdst-btn-primary" :disabled="settingsSaving">
                <span x-show="settingsSaving">Saving...</span>
                <span x-show="!settingsSaving">Save Settings</span>
            </button>

        </div>

        <!-- ════════════════════════════════════════════════
             Reference Tab
             ════════════════════════════════════════════════ -->
        <div x-show="tab === 'reference'">

            <!-- SmartCodes -->
            <div class="ntdst-card">
                <div class="ntdst-card-header">
                    <h3 class="ntdst-card-title"><span class="dashicons dashicons-editor-code"></span> SmartCodes</h3>
                </div>
                <div class="ntdst-card-body">
                    <p class="ntdst-form-help" style="margin-bottom:12px;">Use <code>{{category.field}}</code> syntax in subjects and email bodies. Add <code>|default</code> for fallback values.</p>
                    <table class="ntdst-table">
                        <thead>
                            <tr>
                                <th>Code</th>
                                <th>Label</th>
                                <th>Category</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="code in MailConfig.smartcodesFlat" :key="code.code">
                                <tr>
                                    <td><code x-text="code.code"></code></td>
                                    <td x-text="code.label"></td>
                                    <td x-text="code.category"></td>
                                </tr>
                            </template>
                            <tr x-show="MailConfig.smartcodesFlat.length === 0">
                                <td colspan="3" class="ntdst-text-muted" style="text-align:center;padding:20px;">No SmartCodes registered.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Triggers -->
            <div class="ntdst-card" style="margin-top:16px;">
                <div class="ntdst-card-header">
                    <h3 class="ntdst-card-title"><span class="dashicons dashicons-controls-play"></span> Triggers</h3>
                </div>
                <div class="ntdst-card-body">
                    <table class="ntdst-table">
                        <thead>
                            <tr>
                                <th>Action</th>
                                <th>Label</th>
                                <th>Context</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="[key, config] in Object.entries(MailConfig.triggers)" :key="key">
                                <tr>
                                    <td><code x-text="key"></code></td>
                                    <td x-text="config.label || key"></td>
                                    <td x-text="(config.context || []).join(', ')"></td>
                                </tr>
                            </template>
                            <tr x-show="Object.keys(MailConfig.triggers).length === 0">
                                <td colspan="3" class="ntdst-text-muted" style="text-align:center;padding:20px;">No triggers registered.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- PDF Generators -->
            <div class="ntdst-card" style="margin-top:16px;">
                <div class="ntdst-card-header">
                    <h3 class="ntdst-card-title"><span class="dashicons dashicons-media-document"></span> PDF Generators</h3>
                </div>
                <div class="ntdst-card-body">
                    <table class="ntdst-table">
                        <thead>
                            <tr>
                                <th>Key</th>
                                <th>Label</th>
                                <th>Context Key</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="[key, config] in Object.entries(MailConfig.pdfGenerators)" :key="key">
                                <tr>
                                    <td><code x-text="key"></code></td>
                                    <td x-text="config.label"></td>
                                    <td x-text="config.context_key || '—'"></td>
                                </tr>
                            </template>
                            <tr x-show="Object.keys(MailConfig.pdfGenerators).length === 0">
                                <td colspan="3" class="ntdst-text-muted" style="text-align:center;padding:20px;">No PDF generators registered.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>

        </div><!-- .ntdst-main -->
    </div><!-- .ntdst-layout -->
</div><!-- .ntdst-app -->
