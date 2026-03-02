<?php defined('ABSPATH') || exit; ?>
<style>[x-cloak] { display: none !important; }</style>

<div class="wrap lti-app" x-data="ltiApp()" x-cloak>

    <!-- ── Header ──────────────────────────────────────── -->
    <header class="lti-header">
        <div class="lti-header-left">
            <h1>Netdust LTI</h1>
            <nav class="lti-nav">
                <button class="lti-nav-item" :class="{ active: tab === 'dashboard' }" @click.prevent="setTab('dashboard')">Dashboard</button>
                <button class="lti-nav-item" :class="{ active: tab === 'platforms' }" @click.prevent="setTab('platforms')">Platforms</button>
                <button class="lti-nav-item" :class="{ active: tab === 'tools' }" @click.prevent="setTab('tools')">Tools</button>
                <button class="lti-nav-item" :class="{ active: tab === 'resources' }" @click.prevent="setTab('resources')">Resources</button>
                <button class="lti-nav-item" :class="{ active: tab === 'logs' }" @click.prevent="setTab('logs')">Logs</button>
                <button class="lti-nav-item" :class="{ active: tab === 'howto' }" @click.prevent="setTab('howto')">How-To</button>
            </nav>
        </div>
        <div class="lti-header-right">
            <a href="<?php echo esc_url(admin_url()); ?>" class="lti-btn lti-btn-ghost" style="color:#fff;border-color:rgba(255,255,255,.3);">WP Admin</a>
        </div>
    </header>

    <!-- ── Notification Toast ──────────────────────────── -->
    <div x-show="notification" x-transition.opacity.duration.300ms
         class="lti-notification"
         :class="{
             'lti-notification-success': notificationType === 'success',
             'lti-notification-error': notificationType === 'error',
             'lti-notification-info': notificationType === 'info',
         }">
        <span x-text="notification"></span>
    </div>

    <div class="lti-content">

        <!-- ════════════════════════════════════════════════
             Dashboard Tab
             ════════════════════════════════════════════════ -->
        <div x-show="tab === 'dashboard'">

            <!-- Status Cards -->
            <div class="lti-stats">
                <div class="lti-stat-card">
                    <div class="lti-stat-icon keys">
                        <span class="dashicons dashicons-admin-network"></span>
                    </div>
                    <div>
                        <div class="lti-stat-value" x-text="LtiConfig.keyStatus.hasKeys ? 'Active' : 'Missing'"></div>
                        <div class="lti-stat-label">RSA Keys</div>
                    </div>
                </div>
                <div class="lti-stat-card">
                    <div class="lti-stat-icon platforms">
                        <span class="dashicons dashicons-cloud"></span>
                    </div>
                    <div>
                        <div class="lti-stat-value" x-text="stats.platforms"></div>
                        <div class="lti-stat-label">Platforms</div>
                    </div>
                </div>
                <div class="lti-stat-card">
                    <div class="lti-stat-icon tools">
                        <span class="dashicons dashicons-admin-tools"></span>
                    </div>
                    <div>
                        <div class="lti-stat-value" x-text="stats.tools"></div>
                        <div class="lti-stat-label">Tools</div>
                    </div>
                </div>
                <div class="lti-stat-card">
                    <div class="lti-stat-icon resources">
                        <span class="dashicons dashicons-media-text"></span>
                    </div>
                    <div>
                        <div class="lti-stat-value" x-text="stats.resources"></div>
                        <div class="lti-stat-label">Resources</div>
                    </div>
                </div>
            </div>

            <!-- Tool Provider Endpoints -->
            <div class="lti-card">
                <div class="lti-card-header">
                    <div>
                        <h3 class="lti-card-title"><span class="dashicons dashicons-upload"></span> Tool Provider Endpoints</h3>
                        <p class="lti-form-help" style="margin-top:4px;">Provide these URLs when registering with an external LMS</p>
                    </div>
                </div>
                <div class="lti-card-body">
                    <table class="lti-endpoint-table">
                        <thead>
                            <tr>
                                <th>Endpoint</th>
                                <th>URL</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="[key, url] in Object.entries(LtiConfig.toolEndpoints)" :key="key">
                                <tr>
                                    <td x-text="key.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase())" style="font-weight:500;white-space:nowrap;"></td>
                                    <td><code class="lti-endpoint-url" x-text="url"></code></td>
                                    <td style="text-align:right;">
                                        <button class="lti-copy-btn" :class="{ 'lti-copied': copied === 'tool-' + key }"
                                                @click="copyToClipboard(url, 'tool-' + key)"
                                                x-text="copied === 'tool-' + key ? 'Copied!' : 'Copy'"></button>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Platform Endpoints -->
            <div class="lti-card">
                <div class="lti-card-header">
                    <div>
                        <h3 class="lti-card-title"><span class="dashicons dashicons-download"></span> Platform Endpoints</h3>
                        <p class="lti-form-help" style="margin-top:4px;">Provide these URLs when configuring an external tool to use this site as a platform</p>
                    </div>
                </div>
                <div class="lti-card-body">
                    <table class="lti-endpoint-table">
                        <thead>
                            <tr>
                                <th>Endpoint</th>
                                <th>URL</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="[key, url] in Object.entries(LtiConfig.platformEndpoints)" :key="key">
                                <tr>
                                    <td x-text="key.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase())" style="font-weight:500;white-space:nowrap;"></td>
                                    <td><code class="lti-endpoint-url" x-text="url"></code></td>
                                    <td style="text-align:right;">
                                        <button class="lti-copy-btn" :class="{ 'lti-copied': copied === 'plat-' + key }"
                                                @click="copyToClipboard(url, 'plat-' + key)"
                                                x-text="copied === 'plat-' + key ? 'Copied!' : 'Copy'"></button>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ════════════════════════════════════════════════
             Platforms Tab
             ════════════════════════════════════════════════ -->
        <div x-show="tab === 'platforms'">
            <div class="lti-page-header">
                <h2 class="lti-page-title">Platforms</h2>
                <button @click="newPlatform()" class="lti-btn lti-btn-primary">
                    <span class="dashicons dashicons-plus-alt2" style="font-size:16px;width:16px;height:16px;"></span> Add Platform
                </button>
            </div>

            <div x-show="platformsLoading" class="lti-loading"></div>

            <div x-show="!platformsLoading && platforms.length === 0" class="lti-empty-state">
                <span class="dashicons dashicons-cloud"></span>
                <p>No platforms configured yet. Add one to start receiving LTI launches.</p>
            </div>

            <div x-show="!platformsLoading && platforms.length > 0" class="lti-card">
                <div class="lti-card-body">
                    <table class="lti-table">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Platform ID</th>
                                <th>Client ID</th>
                                <th>Status</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="platform in platforms" :key="platform.id">
                                <tr>
                                    <td x-text="platform.title.rendered" style="font-weight:500;"></td>
                                    <td><code x-text="platform.meta.lti_platform_id || '-'" style="font-size:12px;"></code></td>
                                    <td><code x-text="platform.meta.lti_client_id || '-'" style="font-size:12px;"></code></td>
                                    <td>
                                        <span class="lti-badge"
                                              :class="platform.meta.lti_enabled ? 'lti-badge-success' : 'lti-badge-muted'"
                                              x-text="platform.meta.lti_enabled ? 'Enabled' : 'Disabled'"></span>
                                    </td>
                                    <td class="lti-table-actions">
                                        <button @click="editPlatform(platform)" class="lti-btn lti-btn-sm lti-btn-ghost">Edit</button>
                                        <button @click="deletePlatform(platform.id)" class="lti-btn-icon danger" title="Delete">
                                            <span class="dashicons dashicons-trash"></span>
                                        </button>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Platform Modal -->
            <div x-show="showPlatformModal" class="lti-modal-overlay" @click.self="showPlatformModal = false" @keydown.escape.window="showPlatformModal = false">
                <div class="lti-modal" @click.stop>
                    <div class="lti-modal-header">
                        <h3 x-text="editingPlatformId ? 'Edit Platform' : 'Add Platform'"></h3>
                        <button @click="showPlatformModal = false" class="lti-modal-close">&times;</button>
                    </div>
                    <div class="lti-modal-body">
                        <div class="lti-form-group">
                            <label class="lti-form-label">Name</label>
                            <input x-model="platformForm.title" class="lti-form-input" placeholder="e.g. Canvas LMS">
                        </div>

                        <h4 class="lti-field-group-title">Credentials</h4>
                        <div class="lti-form-group">
                            <label class="lti-form-label">Platform ID (Issuer)</label>
                            <input x-model="platformForm.platform_id" class="lti-form-input" placeholder="https://canvas.instructure.com">
                            <p class="lti-form-help">The platform issuer URL</p>
                        </div>
                        <div class="lti-form-group">
                            <label class="lti-form-label">Client ID</label>
                            <input x-model="platformForm.client_id" class="lti-form-input">
                        </div>
                        <div class="lti-form-group">
                            <label class="lti-form-label">Deployment ID</label>
                            <input x-model="platformForm.deployment_id" class="lti-form-input">
                            <p class="lti-form-help">Optional deployment ID for multi-tenancy</p>
                        </div>

                        <h4 class="lti-field-group-title">Endpoints</h4>
                        <div class="lti-form-group">
                            <label class="lti-form-label">Auth Endpoint</label>
                            <input x-model="platformForm.auth_endpoint" class="lti-form-input" placeholder="https://...">
                        </div>
                        <div class="lti-form-group">
                            <label class="lti-form-label">Token Endpoint</label>
                            <input x-model="platformForm.token_endpoint" class="lti-form-input" placeholder="https://...">
                        </div>
                        <div class="lti-form-group">
                            <label class="lti-form-label">JWKS Endpoint</label>
                            <input x-model="platformForm.jwks_endpoint" class="lti-form-input" placeholder="https://...">
                        </div>

                        <h4 class="lti-field-group-title">Keys</h4>
                        <div class="lti-form-group">
                            <label class="lti-form-label">RSA Public Key (PEM)</label>
                            <textarea x-model="platformForm.rsa_key" class="lti-form-textarea" rows="5" placeholder="-----BEGIN PUBLIC KEY-----"></textarea>
                            <p class="lti-form-help">Optional. The platform's public key in PEM format. Not needed if JWKS endpoint is set.</p>
                        </div>
                        <div class="lti-form-group">
                            <label class="lti-form-label">Key ID (kid)</label>
                            <input x-model="platformForm.kid" class="lti-form-input" placeholder="Optional key ID">
                        </div>

                        <h4 class="lti-field-group-title">Settings</h4>
                        <div class="lti-form-group" style="display:flex;align-items:center;gap:12px;">
                            <label class="lti-form-toggle">
                                <input type="checkbox" x-model="platformForm.enabled">
                                <span class="lti-toggle-track"></span>
                                <span class="lti-toggle-thumb"></span>
                            </label>
                            <span class="lti-form-label" style="margin-bottom:0;">Enabled</span>
                        </div>

                        <h4 class="lti-field-group-title">Role Mapping</h4>
                        <div class="lti-form-group">
                            <label class="lti-form-label">Instructor Role</label>
                            <input x-model="platformForm.role_instructor" class="lti-form-input" placeholder="administrator">
                            <p class="lti-form-help">WordPress role assigned to LTI instructors</p>
                        </div>
                        <div class="lti-form-group">
                            <label class="lti-form-label">Learner Role</label>
                            <input x-model="platformForm.role_learner" class="lti-form-input" placeholder="subscriber">
                            <p class="lti-form-help">WordPress role assigned to LTI learners</p>
                        </div>
                    </div>
                    <div class="lti-modal-footer">
                        <button @click="showPlatformModal = false" class="lti-btn lti-btn-ghost">Cancel</button>
                        <button @click="savePlatform()" class="lti-btn lti-btn-primary" :disabled="saving">
                            <span x-show="saving">Saving...</span>
                            <span x-show="!saving" x-text="editingPlatformId ? 'Update' : 'Create'"></span>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- ════════════════════════════════════════════════
             Tools Tab
             ════════════════════════════════════════════════ -->
        <div x-show="tab === 'tools'">
            <div class="lti-page-header">
                <h2 class="lti-page-title">Tools</h2>
                <button @click="newTool()" class="lti-btn lti-btn-primary">
                    <span class="dashicons dashicons-plus-alt2" style="font-size:16px;width:16px;height:16px;"></span> Add Tool
                </button>
            </div>

            <div x-show="toolsLoading" class="lti-loading"></div>

            <div x-show="!toolsLoading && tools.length === 0" class="lti-empty-state">
                <span class="dashicons dashicons-admin-tools"></span>
                <p>No tools configured yet. Add a tool to enable outbound LTI launches.</p>
            </div>

            <div x-show="!toolsLoading && tools.length > 0" class="lti-card">
                <div class="lti-card-body">
                    <table class="lti-table">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Launch URL</th>
                                <th>Client ID</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="tool in tools" :key="tool.id">
                                <tr>
                                    <td x-text="tool.title.rendered" style="font-weight:500;"></td>
                                    <td><code x-text="tool.meta.lti_launch_url || '-'" style="font-size:12px;"></code></td>
                                    <td><code x-text="tool.meta.lti_client_id || '-'" style="font-size:12px;"></code></td>
                                    <td class="lti-table-actions">
                                        <button @click="testLaunchTool(tool)" class="lti-btn lti-btn-sm lti-btn-primary">Test Launch</button>
                                        <button @click="editTool(tool)" class="lti-btn lti-btn-sm lti-btn-ghost">Edit</button>
                                        <button @click="deleteTool(tool.id)" class="lti-btn-icon danger" title="Delete">
                                            <span class="dashicons dashicons-trash"></span>
                                        </button>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Tool Modal -->
            <div x-show="showToolModal" class="lti-modal-overlay" @click.self="showToolModal = false" @keydown.escape.window="showToolModal = false">
                <div class="lti-modal" @click.stop>
                    <div class="lti-modal-header">
                        <h3 x-text="editingToolId ? 'Edit Tool' : 'Add Tool'"></h3>
                        <button @click="showToolModal = false" class="lti-modal-close">&times;</button>
                    </div>
                    <div class="lti-modal-body">
                        <div class="lti-form-group">
                            <label class="lti-form-label">Name</label>
                            <input x-model="toolForm.title" class="lti-form-input" placeholder="e.g. SCORM Cloud">
                        </div>

                        <h4 class="lti-field-group-title">Credentials</h4>
                        <div class="lti-form-group">
                            <label class="lti-form-label">Client ID</label>
                            <input x-model="toolForm.client_id" class="lti-form-input">
                        </div>
                        <div class="lti-form-group">
                            <label class="lti-form-label">Deployment ID</label>
                            <input x-model="toolForm.deployment_id" class="lti-form-input">
                        </div>

                        <h4 class="lti-field-group-title">Endpoints</h4>
                        <div class="lti-form-group">
                            <label class="lti-form-label">Launch URL</label>
                            <input x-model="toolForm.launch_url" class="lti-form-input" placeholder="https://...">
                        </div>
                        <div class="lti-form-group">
                            <label class="lti-form-label">OIDC Login URL</label>
                            <input x-model="toolForm.oidc_url" class="lti-form-input" placeholder="https://...">
                        </div>
                        <div class="lti-form-group">
                            <label class="lti-form-label">JWKS URL</label>
                            <input x-model="toolForm.jwks_url" class="lti-form-input" placeholder="https://...">
                        </div>

                        <h4 class="lti-field-group-title">Keys</h4>
                        <div class="lti-form-group">
                            <label class="lti-form-label">Public Key (PEM)</label>
                            <textarea x-model="toolForm.public_key" class="lti-form-textarea" rows="5" placeholder="-----BEGIN PUBLIC KEY-----"></textarea>
                            <p class="lti-form-help">Optional. The tool's public key for verifying signatures. Not needed if JWKS URL is set.</p>
                        </div>
                    </div>
                    <div class="lti-modal-footer">
                        <button @click="showToolModal = false" class="lti-btn lti-btn-ghost">Cancel</button>
                        <button @click="saveTool()" class="lti-btn lti-btn-primary" :disabled="saving">
                            <span x-show="saving">Saving...</span>
                            <span x-show="!saving" x-text="editingToolId ? 'Update' : 'Create'"></span>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- ════════════════════════════════════════════════
             Resources Tab
             ════════════════════════════════════════════════ -->
        <div x-show="tab === 'resources'">
            <div class="lti-page-header">
                <h2 class="lti-page-title">Resources</h2>
                <button @click="newResource()" class="lti-btn lti-btn-primary">
                    <span class="dashicons dashicons-plus-alt2" style="font-size:16px;width:16px;height:16px;"></span> Add Resource
                </button>
            </div>

            <div x-show="resourcesLoading" class="lti-loading"></div>

            <div x-show="!resourcesLoading && resources.length === 0" class="lti-empty-state">
                <span class="dashicons dashicons-media-text"></span>
                <p>No resources configured yet. Add a resource to link tools with courses.</p>
            </div>

            <div x-show="!resourcesLoading && resources.length > 0" class="lti-card">
                <div class="lti-card-body">
                    <table class="lti-table">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Tool</th>
                                <th>Launch URL</th>
                                <th>Course ID</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="resource in resources" :key="resource.id">
                                <tr>
                                    <td x-text="resource.title.rendered" style="font-weight:500;"></td>
                                    <td x-text="getToolName(resource.meta.lti_tool_id)"></td>
                                    <td><code x-text="resource.meta.lti_launch_url || '-'" style="font-size:12px;"></code></td>
                                    <td><code x-text="resource.meta.lti_course_id || '-'" style="font-size:12px;"></code></td>
                                    <td class="lti-table-actions">
                                        <button @click="launchResource(resource)" class="lti-btn lti-btn-sm lti-btn-primary">Launch</button>
                                        <button @click="editResource(resource)" class="lti-btn lti-btn-sm lti-btn-ghost">Edit</button>
                                        <button @click="deleteResource(resource.id)" class="lti-btn-icon danger" title="Delete">
                                            <span class="dashicons dashicons-trash"></span>
                                        </button>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Resource Modal -->
            <div x-show="showResourceModal" class="lti-modal-overlay" @click.self="showResourceModal = false" @keydown.escape.window="showResourceModal = false">
                <div class="lti-modal" @click.stop>
                    <div class="lti-modal-header">
                        <h3 x-text="editingResourceId ? 'Edit Resource' : 'Add Resource'"></h3>
                        <button @click="showResourceModal = false" class="lti-modal-close">&times;</button>
                    </div>
                    <div class="lti-modal-body">
                        <div class="lti-form-group">
                            <label class="lti-form-label">Name</label>
                            <input x-model="resourceForm.title" class="lti-form-input" placeholder="e.g. Module 1 — Introduction">
                        </div>

                        <h4 class="lti-field-group-title">Configuration</h4>
                        <div class="lti-form-group">
                            <label class="lti-form-label">Tool</label>
                            <select x-model="resourceForm.tool_id" class="lti-form-input">
                                <option value="">-- Select a tool --</option>
                                <template x-for="tool in tools" :key="tool.id">
                                    <option :value="tool.id" x-text="tool.title.rendered"></option>
                                </template>
                            </select>
                            <p class="lti-form-help">The tool provider that hosts this resource</p>
                        </div>
                        <div class="lti-form-group">
                            <label class="lti-form-label">Launch URL</label>
                            <input x-model="resourceForm.launch_url" class="lti-form-input" placeholder="https://...">
                            <p class="lti-form-help">Override the tool's default launch URL for this resource</p>
                        </div>
                        <div class="lti-form-group">
                            <label class="lti-form-label">Course ID</label>
                            <input x-model="resourceForm.course_id" class="lti-form-input" placeholder="e.g. 42">
                            <p class="lti-form-help">WordPress course ID to link with this resource</p>
                        </div>
                        <div class="lti-form-group">
                            <label class="lti-form-label">Description</label>
                            <textarea x-model="resourceForm.description" class="lti-form-textarea" rows="3" placeholder="Optional description"></textarea>
                        </div>
                        <div class="lti-form-group">
                            <label class="lti-form-label">Custom Parameters (JSON)</label>
                            <textarea x-model="resourceForm.custom_params" class="lti-form-textarea" rows="4" placeholder='{"key": "value"}'></textarea>
                            <p class="lti-form-help">Optional JSON object of custom LTI parameters</p>
                        </div>
                    </div>
                    <div class="lti-modal-footer">
                        <button @click="showResourceModal = false" class="lti-btn lti-btn-ghost">Cancel</button>
                        <button @click="saveResource()" class="lti-btn lti-btn-primary" :disabled="saving">
                            <span x-show="saving">Saving...</span>
                            <span x-show="!saving" x-text="editingResourceId ? 'Update' : 'Create'"></span>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- ════════════════════════════════════════════════
             Logs Tab
             ════════════════════════════════════════════════ -->
        <div x-show="tab === 'logs'">
            <div class="lti-page-header">
                <h2 class="lti-page-title">Logs</h2>
            </div>

            <div class="lti-log-controls">
                <nav class="lti-nav">
                    <button class="lti-nav-item" :class="{ active: logChannel === 'lti' }" @click="setLogChannel('lti')">Launches</button>
                    <button class="lti-nav-item" :class="{ active: logChannel === 'grades' }" @click="setLogChannel('grades')">Grade Passbacks</button>
                </nav>
                <input type="date" x-model="logDate" class="lti-form-input" style="width:auto;">
                <button @click="loadLogs()" class="lti-btn lti-btn-ghost">Load</button>
            </div>

            <div x-show="logsLoading" class="lti-loading"></div>

            <div x-show="!logsLoading && logs.length === 0" class="lti-empty-state">
                <span class="dashicons dashicons-media-text"></span>
                <p>No log entries found for the selected date and channel.</p>
            </div>

            <div x-show="!logsLoading && logs.length > 0" class="lti-card">
                <div class="lti-card-body">
                    <table class="lti-log-table">
                        <thead>
                            <tr>
                                <th>Time</th>
                                <th>Level</th>
                                <th>Message</th>
                                <th>Context</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="(log, idx) in logs" :key="idx">
                                <tr>
                                    <td x-text="log.time || ''" style="white-space:nowrap;"></td>
                                    <td>
                                        <span class="lti-log-level"
                                              :class="{
                                                  'lti-log-level-info': (log.level || '').toLowerCase() === 'info',
                                                  'lti-log-level-warning': (log.level || '').toLowerCase() === 'warning',
                                                  'lti-log-level-error': (log.level || '').toLowerCase() === 'error',
                                                  'lti-log-level-debug': (log.level || '').toLowerCase() === 'debug',
                                              }"
                                              x-text="(log.level || '').toUpperCase()"></span>
                                    </td>
                                    <td x-text="log.message || ''"></td>
                                    <td>
                                        <div x-show="log.context && Object.keys(log.context).length > 0"
                                             class="lti-log-context"
                                             x-text="formatLogContext(log.context)"></div>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ════════════════════════════════════════════════
             How-To Tab
             ════════════════════════════════════════════════ -->
        <div x-show="tab === 'howto'">
            <div class="lti-docs">
                <?php
                $howtoPath = __DIR__ . '/howto.php';
                if (file_exists($howtoPath)) {
                    include $howtoPath;
                } else {
                    echo '<p>Documentation not found. Create <code>templates/admin/howto.php</code> to add content here.</p>';
                }
                ?>
            </div>
        </div>

    </div><!-- .lti-content -->
</div><!-- .lti-app -->
