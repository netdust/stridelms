<?php defined('ABSPATH') || exit; ?>
<style>[x-cloak] { display: none !important; }</style>

<div class="wrap ntdst-app" x-data="ltiApp()" x-cloak>

    <!-- ── Page Title ──────────────────────────────────── -->
    <div class="ntdst-page-title-bar">
        <h1>Netdust LTI <span class="ntdst-version">v1.0</span></h1>
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
                <button class="ntdst-sidebar-item" :class="{ active: tab === 'platforms' }" @click.prevent="setTab('platforms')">
                    <span class="dashicons dashicons-cloud"></span> Platforms
                </button>
                <button class="ntdst-sidebar-item" :class="{ active: tab === 'logs' }" @click.prevent="setTab('logs')">
                    <span class="dashicons dashicons-media-text"></span> Logs
                </button>
                <button class="ntdst-sidebar-item" :class="{ active: tab === 'howto' }" @click.prevent="setTab('howto')">
                    <span class="dashicons dashicons-book"></span> How-To
                </button>
            </nav>
        </aside>

        <div class="ntdst-main">

        <!-- ════════════════════════════════════════════════
             Dashboard Tab
             ════════════════════════════════════════════════ -->
        <div x-show="tab === 'dashboard'">

            <!-- Status Cards -->
            <div class="ntdst-stats">
                <div class="ntdst-stat-card">
                    <div class="ntdst-stat-icon keys">
                        <span class="dashicons dashicons-admin-network"></span>
                    </div>
                    <div>
                        <div class="ntdst-stat-value" x-text="LtiConfig.keyStatus.hasKeys ? 'Active' : 'Missing'"></div>
                        <div class="ntdst-stat-label">RSA Keys</div>
                    </div>
                </div>
                <div class="ntdst-stat-card">
                    <div class="ntdst-stat-icon platforms">
                        <span class="dashicons dashicons-cloud"></span>
                    </div>
                    <div>
                        <div class="ntdst-stat-value" x-text="stats.platforms"></div>
                        <div class="ntdst-stat-label">Platforms</div>
                    </div>
                </div>
            </div>

            <!-- Tool Provider Endpoints -->
            <div class="ntdst-card">
                <div class="ntdst-card-header">
                    <div>
                        <h3 class="ntdst-card-title"><span class="dashicons dashicons-upload"></span> Tool Provider Endpoints</h3>
                        <p class="ntdst-form-help" style="margin-top:4px;">Provide these URLs when registering with an external LMS</p>
                    </div>
                </div>
                <div class="ntdst-card-body">
                    <table class="ntdst-endpoint-table">
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
                                    <td><code class="ntdst-endpoint-url" x-text="url"></code></td>
                                    <td style="text-align:right;">
                                        <button class="ntdst-copy-btn" :class="{ 'ntdst-copied': copied === 'tool-' + key }"
                                                @click="copyToClipboard(url, 'tool-' + key)"
                                                x-text="copied === 'tool-' + key ? 'Copied!' : 'Copy'"></button>
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
            <div class="ntdst-page-header">
                <h2 class="ntdst-section-title">Platforms</h2>
                <button @click="newPlatform()" class="ntdst-btn ntdst-btn-primary">
                    <span class="dashicons dashicons-plus-alt2" style="font-size:16px;width:16px;height:16px;"></span> Add Platform
                </button>
            </div>

            <div x-show="platformsLoading" class="ntdst-loading"></div>

            <div x-show="!platformsLoading && platforms.length === 0" class="ntdst-empty-state">
                <span class="dashicons dashicons-cloud"></span>
                <p>No platforms configured yet. Add one to start receiving LTI launches.</p>
            </div>

            <div x-show="!platformsLoading && platforms.length > 0" class="ntdst-card">
                <div class="ntdst-card-body">
                    <table class="ntdst-table">
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
                                        <span class="ntdst-badge"
                                              :class="platform.meta.lti_enabled ? 'ntdst-badge-success' : 'ntdst-badge-muted'"
                                              x-text="platform.meta.lti_enabled ? 'Enabled' : 'Disabled'"></span>
                                    </td>
                                    <td class="ntdst-table-actions">
                                        <button @click="editPlatform(platform)" class="ntdst-btn ntdst-btn-sm ntdst-btn-ghost">Edit</button>
                                        <button @click="deletePlatform(platform.id)" class="ntdst-btn-icon danger" title="Delete">
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
            <div x-show="showPlatformModal" class="ntdst-modal-overlay" @click.self="showPlatformModal = false" @keydown.escape.window="showPlatformModal = false">
                <div class="ntdst-modal" @click.stop>
                    <div class="ntdst-modal-header">
                        <h3 x-text="editingPlatformId ? 'Edit Platform' : 'Add Platform'"></h3>
                        <button @click="showPlatformModal = false" class="ntdst-modal-close">&times;</button>
                    </div>
                    <div class="ntdst-modal-body">
                        <div class="ntdst-form-group">
                            <label class="ntdst-form-label">Name</label>
                            <input x-model="platformForm.title" class="ntdst-form-input" placeholder="e.g. Canvas LMS">
                        </div>

                        <h4 class="ntdst-field-group-title">Credentials</h4>
                        <div class="ntdst-form-group">
                            <label class="ntdst-form-label">Platform ID (Issuer)</label>
                            <input x-model="platformForm.platform_id" class="ntdst-form-input" placeholder="https://canvas.instructure.com">
                            <p class="ntdst-form-help">The platform issuer URL</p>
                        </div>
                        <div class="ntdst-form-group">
                            <label class="ntdst-form-label">Client ID</label>
                            <input x-model="platformForm.client_id" class="ntdst-form-input">
                        </div>
                        <div class="ntdst-form-group">
                            <label class="ntdst-form-label">Deployment ID</label>
                            <input x-model="platformForm.deployment_id" class="ntdst-form-input">
                            <p class="ntdst-form-help">Optional deployment ID for multi-tenancy</p>
                        </div>

                        <h4 class="ntdst-field-group-title">Endpoints</h4>
                        <div class="ntdst-form-group">
                            <label class="ntdst-form-label">Auth Endpoint</label>
                            <input x-model="platformForm.auth_endpoint" class="ntdst-form-input" placeholder="https://...">
                        </div>
                        <div class="ntdst-form-group">
                            <label class="ntdst-form-label">Token Endpoint</label>
                            <input x-model="platformForm.token_endpoint" class="ntdst-form-input" placeholder="https://...">
                        </div>
                        <div class="ntdst-form-group">
                            <label class="ntdst-form-label">JWKS Endpoint</label>
                            <input x-model="platformForm.jwks_endpoint" class="ntdst-form-input" placeholder="https://...">
                        </div>

                        <h4 class="ntdst-field-group-title">Keys</h4>
                        <div class="ntdst-form-group">
                            <label class="ntdst-form-label">RSA Public Key (PEM)</label>
                            <textarea x-model="platformForm.rsa_key" class="ntdst-form-textarea" rows="5" placeholder="-----BEGIN PUBLIC KEY-----"></textarea>
                            <p class="ntdst-form-help">Optional. The platform's public key in PEM format. Not needed if JWKS endpoint is set.</p>
                        </div>
                        <div class="ntdst-form-group">
                            <label class="ntdst-form-label">Key ID (kid)</label>
                            <input x-model="platformForm.kid" class="ntdst-form-input" placeholder="Optional key ID">
                        </div>

                        <h4 class="ntdst-field-group-title">Settings</h4>
                        <div class="ntdst-form-group" style="display:flex;align-items:center;gap:12px;">
                            <label class="ntdst-form-toggle">
                                <input type="checkbox" x-model="platformForm.enabled">
                                <span class="ntdst-toggle-track"></span>
                                <span class="ntdst-toggle-thumb"></span>
                            </label>
                            <span class="ntdst-form-label" style="margin-bottom:0;">Enabled</span>
                        </div>

                        <h4 class="ntdst-field-group-title">Role Mapping</h4>
                        <div class="ntdst-form-group">
                            <label class="ntdst-form-label">Instructor Role</label>
                            <input x-model="platformForm.role_instructor" class="ntdst-form-input" placeholder="administrator">
                            <p class="ntdst-form-help">WordPress role assigned to LTI instructors</p>
                        </div>
                        <div class="ntdst-form-group">
                            <label class="ntdst-form-label">Learner Role</label>
                            <input x-model="platformForm.role_learner" class="ntdst-form-input" placeholder="subscriber">
                            <p class="ntdst-form-help">WordPress role assigned to LTI learners</p>
                        </div>
                    </div>
                    <div class="ntdst-modal-footer">
                        <button @click="showPlatformModal = false" class="ntdst-btn ntdst-btn-ghost">Cancel</button>
                        <button @click="savePlatform()" class="ntdst-btn ntdst-btn-primary" :disabled="saving">
                            <span x-show="saving">Saving...</span>
                            <span x-show="!saving" x-text="editingPlatformId ? 'Update' : 'Create'"></span>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- ════════════════════════════════════════════════
             Logs Tab
             ════════════════════════════════════════════════ -->
        <div x-show="tab === 'logs'">
            <div class="ntdst-page-header">
                <h2 class="ntdst-section-title">Logs</h2>
            </div>

            <div class="ntdst-log-controls">
                <nav class="ntdst-log-channel-tabs">
                    <button class="ntdst-log-channel-tab" :class="{ active: logChannel === 'lti' }" @click="setLogChannel('lti')">Launches</button>
                    <button class="ntdst-log-channel-tab" :class="{ active: logChannel === 'grades' }" @click="setLogChannel('grades')">Grade Passbacks</button>
                </nav>
                <input type="date" x-model="logDate" class="ntdst-form-input" style="width:auto;">
                <button @click="loadLogs()" class="ntdst-btn ntdst-btn-ghost">Load</button>
            </div>

            <div x-show="logsLoading" class="ntdst-loading"></div>

            <div x-show="!logsLoading && logs.length === 0" class="ntdst-empty-state">
                <span class="dashicons dashicons-media-text"></span>
                <p>No log entries found for the selected date and channel.</p>
            </div>

            <div x-show="!logsLoading && logs.length > 0" class="ntdst-card">
                <div class="ntdst-card-body">
                    <table class="ntdst-log-table">
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
                                        <span class="ntdst-log-level"
                                              :class="{
                                                  'ntdst-log-level-info': (log.level || '').toLowerCase() === 'info',
                                                  'ntdst-log-level-warning': (log.level || '').toLowerCase() === 'warning',
                                                  'ntdst-log-level-error': (log.level || '').toLowerCase() === 'error',
                                                  'ntdst-log-level-debug': (log.level || '').toLowerCase() === 'debug',
                                              }"
                                              x-text="(log.level || '').toUpperCase()"></span>
                                    </td>
                                    <td x-text="log.message || ''"></td>
                                    <td>
                                        <div x-show="log.context && Object.keys(log.context).length > 0"
                                             class="ntdst-log-context"
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
            <div class="ntdst-docs">
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

        </div><!-- .ntdst-main -->
    </div><!-- .ntdst-layout -->
</div><!-- .ntdst-app -->
