<?php
/**
 * Audit Log Admin Template
 * Alpine.js application for viewing audit entries.
 *
 * @package Stride\Modules\Audit
 */

defined('ABSPATH') || exit;
?>
<div class="wrap stride-audit-app" x-data="strideAuditApp()">
    <h1 class="wp-heading-inline">Audit Log</h1>

    <!-- Filters -->
    <div class="stride-audit-filters">
        <div class="stride-audit-filter-row">
            <label>
                <span>From</span>
                <input type="text" x-ref="dateFrom" x-model="filters.from" placeholder="Start date">
            </label>
            <label>
                <span>To</span>
                <input type="text" x-ref="dateTo" x-model="filters.to" placeholder="End date">
            </label>
            <label>
                <span>Entity Type</span>
                <select x-model="filters.entity_type">
                    <option value="">All</option>
                    <option value="registration">Registration</option>
                    <option value="completion">Completion</option>
                    <option value="attendance">Attendance</option>
                </select>
            </label>
            <label>
                <span>User</span>
                <input type="text" x-model="filters.user_search" placeholder="Search user..." @input.debounce.300ms="searchUsers">
                <select x-model="filters.actor_id" x-show="userResults.length > 0">
                    <option value="">Select user...</option>
                    <template x-for="user in userResults" :key="user.id">
                        <option :value="user.id" x-text="user.name + ' (' + user.email + ')'"></option>
                    </template>
                </select>
            </label>
            <button type="button" class="button button-primary" @click="loadEntries">Filter</button>
            <button type="button" class="button" @click="exportCsv">Export CSV</button>
        </div>
    </div>

    <!-- Loading State -->
    <div x-show="loading" class="stride-audit-loading">
        <span class="spinner is-active"></span> Loading...
    </div>

    <!-- Results Table -->
    <table class="wp-list-table widefat fixed striped" x-show="!loading">
        <thead>
            <tr>
                <th style="width: 140px;">Date</th>
                <th style="width: 100px;">Entity</th>
                <th style="width: 80px;">ID</th>
                <th style="width: 180px;">Action</th>
                <th style="width: 150px;">Actor</th>
                <th>Details</th>
            </tr>
        </thead>
        <tbody>
            <template x-for="entry in entries" :key="entry.id">
                <tr>
                    <td x-text="formatDate(entry.created_at)"></td>
                    <td>
                        <span class="stride-audit-badge" :class="'stride-audit-badge--' + entry.entity_type" x-text="entry.entity_type"></span>
                    </td>
                    <td x-text="entry.entity_id"></td>
                    <td x-text="entry.action"></td>
                    <td>
                        <template x-if="entry.actor_id">
                            <span x-text="entry.actor_name || 'User #' + entry.actor_id"></span>
                        </template>
                        <template x-if="!entry.actor_id">
                            <em class="stride-audit-system">system</em>
                        </template>
                    </td>
                    <td>
                        <button type="button" class="button button-small" @click="entry.expanded = !entry.expanded">
                            <span x-text="entry.expanded ? 'Hide' : 'Show'"></span>
                        </button>
                        <pre x-show="entry.expanded" x-text="formatContext(entry.context)" class="stride-audit-context"></pre>
                    </td>
                </tr>
            </template>
            <tr x-show="entries.length === 0 && !loading">
                <td colspan="6">No audit entries found.</td>
            </tr>
        </tbody>
    </table>

    <!-- Pagination -->
    <div class="stride-audit-pagination" x-show="totalPages > 1">
        <button type="button" class="button" :disabled="page === 1" @click="page--; loadEntries()">Previous</button>
        <span x-text="'Page ' + page + ' of ' + totalPages"></span>
        <button type="button" class="button" :disabled="page >= totalPages" @click="page++; loadEntries()">Next</button>
    </div>
</div>
