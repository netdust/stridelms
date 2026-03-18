<?php
/**
 * Audit Log Admin Template
 * Alpine.js application for viewing audit entries.
 *
 * @package NTDST\Audit
 */

defined('ABSPATH') || exit;
?>
<style>[x-cloak] { display: none !important; }</style>

<div class="wrap ntdst-app" x-data="strideAuditApp()" x-cloak>

    <!-- Page Title -->
    <div class="ntdst-page-title-bar">
        <h1>Audit Log <span class="ntdst-version">v1.0</span></h1>
    </div>

    <!-- Notification Toast -->
    <div x-show="notification" x-transition.opacity.duration.300ms
         class="ntdst-notification"
         :class="{
             'ntdst-notification-success': notificationType === 'success',
             'ntdst-notification-error': notificationType === 'error',
         }">
        <span x-text="notification"></span>
    </div>

    <!-- Sidebar Layout -->
    <div class="ntdst-layout">
        <aside class="ntdst-sidebar">
            <nav class="ntdst-sidebar-nav">
                <button class="ntdst-sidebar-item" :class="{ active: tab === 'log' }" @click="tab = 'log'">
                    <span class="dashicons dashicons-list-view"></span> Log Entries
                </button>
                <button class="ntdst-sidebar-item" :class="{ active: tab === 'export' }" @click="tab = 'export'">
                    <span class="dashicons dashicons-download"></span> Export
                </button>
            </nav>
        </aside>

        <div class="ntdst-main">

            <!-- ── Log Tab ─────────────────────────────── -->
            <div x-show="tab === 'log'">
                <!-- Filters -->
                <div class="ntdst-card">
                    <div class="ntdst-card-body">
                        <div style="display:flex;flex-wrap:wrap;gap:16px;align-items:flex-end;">
                            <div class="ntdst-form-group" style="margin-bottom:0;">
                                <label class="ntdst-form-label">From</label>
                                <input type="text" x-ref="dateFrom" x-model="filters.from" class="ntdst-form-input" style="width:140px;" placeholder="Start date">
                            </div>
                            <div class="ntdst-form-group" style="margin-bottom:0;">
                                <label class="ntdst-form-label">To</label>
                                <input type="text" x-ref="dateTo" x-model="filters.to" class="ntdst-form-input" style="width:140px;" placeholder="End date">
                            </div>
                            <div class="ntdst-form-group" style="margin-bottom:0;">
                                <label class="ntdst-form-label">Entity Type</label>
                                <select x-model="filters.entity_type" class="ntdst-form-select" style="width:150px;">
                                    <option value="">All</option>
                                    <option value="registration">Registration</option>
                                    <option value="completion">Completion</option>
                                    <option value="attendance">Attendance</option>
                                </select>
                            </div>
                            <div class="ntdst-form-group" style="margin-bottom:0;">
                                <label class="ntdst-form-label">User</label>
                                <input type="text" x-model="filters.user_search" class="ntdst-form-input" style="width:180px;" placeholder="Search user..." @input.debounce.300ms="searchUsers">
                                <select x-model="filters.actor_id" x-show="userResults.length > 0" class="ntdst-form-select" style="width:180px;margin-top:4px;">
                                    <option value="">Select user...</option>
                                    <template x-for="user in userResults" :key="user.id">
                                        <option :value="user.id" x-text="user.name + ' (' + user.email + ')'"></option>
                                    </template>
                                </select>
                            </div>
                            <button type="button" class="ntdst-btn ntdst-btn-primary" @click="loadEntries">
                                <span class="dashicons dashicons-filter" style="font-size:16px;width:16px;height:16px;"></span> Filter
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Loading -->
                <div x-show="loading" class="ntdst-loading"></div>

                <!-- Empty State -->
                <div x-show="!loading && entries.length === 0" class="ntdst-empty-state">
                    <span class="dashicons dashicons-list-view"></span>
                    <p>No audit entries found for the selected filters.</p>
                </div>

                <!-- Results Table -->
                <div x-show="!loading && entries.length > 0" class="ntdst-card">
                    <div class="ntdst-card-body">
                        <table class="ntdst-table">
                            <thead>
                                <tr>
                                    <th style="width:140px;">Date</th>
                                    <th style="width:100px;">Entity</th>
                                    <th style="width:80px;">ID</th>
                                    <th style="width:180px;">Action</th>
                                    <th style="width:150px;">Actor</th>
                                    <th style="width:50px;"></th>
                                </tr>
                            </thead>
                            <template x-for="entry in entries" :key="entry.id">
                                <tbody>
                                    <tr style="cursor:pointer;" @click="entry.expanded = !entry.expanded">
                                        <td x-text="formatDate(entry.created_at)"></td>
                                        <td>
                                            <span class="ntdst-badge"
                                                  :class="{
                                                      'ntdst-badge-info': entry.entity_type === 'registration',
                                                      'ntdst-badge-success': entry.entity_type === 'completion',
                                                      'ntdst-badge-warning': entry.entity_type === 'attendance',
                                                      'ntdst-badge-muted': !['registration','completion','attendance'].includes(entry.entity_type),
                                                  }"
                                                  x-text="entry.entity_type"></span>
                                        </td>
                                        <td x-text="entry.entity_id"></td>
                                        <td x-text="entry.action"></td>
                                        <td>
                                            <template x-if="entry.actor_id">
                                                <span x-text="entry.actor_name || 'User #' + entry.actor_id"></span>
                                            </template>
                                            <template x-if="!entry.actor_id">
                                                <em class="ntdst-text-muted">system</em>
                                            </template>
                                        </td>
                                        <td>
                                            <span class="dashicons" :class="entry.expanded ? 'dashicons-arrow-up-alt2' : 'dashicons-arrow-down-alt2'" style="font-size:16px;width:16px;height:16px;vertical-align:middle;color:#999;"></span>
                                        </td>
                                    </tr>
                                    <tr x-show="entry.expanded">
                                        <td colspan="6" class="ntdst-detail-row">
                                            <pre class="ntdst-audit-context" x-text="formatContext(entry.context)"></pre>
                                        </td>
                                    </tr>
                                </tbody>
                            </template>
                        </table>
                    </div>
                </div>

                <!-- Pagination -->
                <div class="ntdst-pagination" x-show="totalPages > 1">
                    <button type="button" class="ntdst-btn" :disabled="page === 1" @click="page--; loadEntries()">Previous</button>
                    <span class="ntdst-text-muted" x-text="'Page ' + page + ' of ' + totalPages"></span>
                    <button type="button" class="ntdst-btn" :disabled="page >= totalPages" @click="page++; loadEntries()">Next</button>
                </div>
            </div>

            <!-- ── Export Tab ──────────────────────────── -->
            <div x-show="tab === 'export'">
                <div class="ntdst-card">
                    <div class="ntdst-card-header">
                        <h3 class="ntdst-card-title"><span class="dashicons dashicons-download"></span> Export Audit Log</h3>
                    </div>
                    <div class="ntdst-card-body">
                        <p>Export the filtered audit log as a CSV file. The export uses the same date range and filters as the log viewer.</p>
                        <div style="display:flex;gap:16px;align-items:flex-end;margin-top:12px;">
                            <div class="ntdst-form-group" style="margin-bottom:0;">
                                <label class="ntdst-form-label">From</label>
                                <input type="text" x-model="filters.from" class="ntdst-form-input" style="width:140px;">
                            </div>
                            <div class="ntdst-form-group" style="margin-bottom:0;">
                                <label class="ntdst-form-label">To</label>
                                <input type="text" x-model="filters.to" class="ntdst-form-input" style="width:140px;">
                            </div>
                            <button type="button" class="ntdst-btn ntdst-btn-primary" @click="exportCsv">
                                <span class="dashicons dashicons-download" style="font-size:16px;width:16px;height:16px;"></span> Export CSV
                            </button>
                        </div>
                    </div>
                </div>
            </div>

        </div><!-- .ntdst-main -->
    </div><!-- .ntdst-layout -->
</div>
