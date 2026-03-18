<?php
/**
 * Admin Dashboard Template
 *
 * Full-screen Alpine.js application for the Stride admin dashboard.
 * Contains views for: Dashboard, Trajectories, Editions, and Quotes.
 *
 * @var string $admin_url Base admin URL for generating links
 *
 * @package Stride\Admin
 */

defined('ABSPATH') || exit;
?>
<div class="wrap stride-app" x-data="strideApp()">
    <!-- Header -->
    <header class="stride-header">
        <div class="stride-header-left">
            <h1>Stride</h1>
            <nav class="stride-nav">
                <a href="#/" class="stride-nav-item" :class="{ 'active': view === 'dashboard' }" @click.prevent="view = 'dashboard'">
                    Dashboard
                </a>
                <a href="#/trajectories" class="stride-nav-item" :class="{ 'active': view === 'trajectories' }" @click.prevent="view = 'trajectories'">
                    Trajecten
                </a>
                <a href="#/editions" class="stride-nav-item" :class="{ 'active': view === 'editions' }" @click.prevent="view = 'editions'">
                    Editions
                </a>
                <a href="#/quotes" class="stride-nav-item" :class="{ 'active': view === 'quotes' }" @click.prevent="view = 'quotes'">
                    Quotes
                </a>
            </nav>
        </div>
        <div class="stride-header-right">
            <span class="stride-user-name" x-text="user.name"></span>
            <a href="<?php echo esc_url($admin_url); ?>" class="stride-btn stride-btn-ghost">
                WP Admin
            </a>
        </div>
    </header>

    <!-- Content -->
    <div class="stride-content">
        <!-- Dashboard View -->
        <template x-if="view === 'dashboard'">
            <div>
                <div class="stride-page-header">
                    <h2 class="stride-page-title">Dashboard</h2>
                </div>

                <!-- Stats Grid -->
                <div class="stride-stats">
                    <div class="stride-stat-card">
                        <div class="stride-stat-icon upcoming">
                            <span class="dashicons dashicons-calendar-alt"></span>
                        </div>
                        <div class="stride-stat-info">
                            <div class="stride-stat-value" x-text="stats.upcomingEditions">-</div>
                            <div class="stride-stat-label">Komende Editions</div>
                        </div>
                    </div>
                    <div class="stride-stat-card">
                        <div class="stride-stat-icon registrations">
                            <span class="dashicons dashicons-groups"></span>
                        </div>
                        <div class="stride-stat-info">
                            <div class="stride-stat-value" x-text="stats.totalRegistrations">-</div>
                            <div class="stride-stat-label">Actieve Inschrijvingen</div>
                        </div>
                    </div>
                    <div class="stride-stat-card">
                        <div class="stride-stat-icon pending">
                            <span class="dashicons dashicons-media-document"></span>
                        </div>
                        <div class="stride-stat-info">
                            <div class="stride-stat-value" x-text="stats.pendingQuotes">-</div>
                            <div class="stride-stat-label">Openstaande Offertes</div>
                        </div>
                    </div>
                    <div class="stride-stat-card">
                        <div class="stride-stat-icon today">
                            <span class="dashicons dashicons-clock"></span>
                        </div>
                        <div class="stride-stat-info">
                            <div class="stride-stat-value" x-text="stats.todaySessions">-</div>
                            <div class="stride-stat-label">Sessies Vandaag</div>
                        </div>
                    </div>
                    <div class="stride-stat-card">
                        <div class="stride-stat-icon trajectories">
                            <span class="dashicons dashicons-networking"></span>
                        </div>
                        <div class="stride-stat-info">
                            <div class="stride-stat-value" x-text="stats.openTrajectories">-</div>
                            <div class="stride-stat-label">Open Trajecten</div>
                        </div>
                    </div>
                    <div class="stride-stat-card">
                        <div class="stride-stat-icon week-trend" :class="{ 'positive': stats.registrationsThisWeek >= stats.registrationsLastWeek, 'negative': stats.registrationsThisWeek < stats.registrationsLastWeek }">
                            <span class="dashicons" :class="stats.registrationsThisWeek >= stats.registrationsLastWeek ? 'dashicons-arrow-up-alt' : 'dashicons-arrow-down-alt'"></span>
                        </div>
                        <div class="stride-stat-info">
                            <div class="stride-stat-value" x-text="stats.registrationsThisWeek || 0">-</div>
                            <div class="stride-stat-label">Deze week <span class="stride-stat-compare" x-text="'(vorige: ' + (stats.registrationsLastWeek || 0) + ')'"></span></div>
                        </div>
                    </div>
                </div>

                <!-- Alerts Section -->
                <template x-if="stats.alerts && stats.alerts.length > 0">
                    <div class="stride-alerts-section">
                        <template x-for="alert in stats.alerts" :key="alert.editionId + alert.type">
                            <div class="stride-alert" :class="'stride-alert-' + alert.type">
                                <span class="dashicons" :class="alert.type === 'almost_full' ? 'dashicons-warning' : 'dashicons-info'"></span>
                                <div class="stride-alert-content">
                                    <strong x-text="alert.editionTitle"></strong>
                                    <span class="stride-alert-date" x-text="formatDate(alert.startDate)"></span>
                                    <span class="stride-alert-message" x-text="alert.message"></span>
                                </div>
                                <div class="stride-alert-badge" x-text="alert.fillRate + '%'"></div>
                            </div>
                        </template>
                    </div>
                </template>

                <!-- Pending Approvals -->
                <template x-if="pendingApprovals.length > 0">
                    <div class="stride-card" style="margin-bottom: 24px;">
                        <div class="stride-card-header">
                            <h3 class="stride-card-title">
                                <span class="dashicons dashicons-shield"></span>
                                Wachtend op goedkeuring
                                <span class="stride-count-badge" x-text="pendingApprovals.length"></span>
                            </h3>
                        </div>
                        <div class="stride-card-body" style="padding: 0;">
                            <table class="stride-table" style="margin: 0;">
                                <thead>
                                    <tr>
                                        <th style="padding: 10px 16px;">Deelnemer</th>
                                        <th style="padding: 10px 16px;">Edition</th>
                                        <th style="padding: 10px 16px;">Ingeschreven</th>
                                        <th style="padding: 10px 16px;">Taken</th>
                                        <th style="padding: 10px 16px; text-align: right;">Actie</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <template x-for="approval in pendingApprovals" :key="approval.id">
                                        <tr>
                                            <td style="padding: 10px 16px;">
                                                <strong x-text="approval.user_name"></strong>
                                                <br>
                                                <small style="color: #646970;" x-text="approval.user_email"></small>
                                            </td>
                                            <td style="padding: 10px 16px;" x-text="approval.edition_title"></td>
                                            <td style="padding: 10px 16px;" x-text="formatDate(approval.registered_at)"></td>
                                            <td style="padding: 10px 16px;">
                                                <span style="color: #00a32a;">
                                                    <span class="dashicons dashicons-yes-alt" style="font-size: 16px; width: 16px; height: 16px; vertical-align: middle;"></span>
                                                    <span x-text="approval.type === 'post_approval' ? 'Na afloop — klaar voor aftekenen' : 'Alle taken voltooid'"></span>
                                                </span>
                                            </td>
                                            <td style="padding: 10px 16px; text-align: right;">
                                                <button class="stride-btn stride-btn-primary stride-btn-sm"
                                                        @click="approveRegistration(approval.id)"
                                                        :disabled="approval.approving">
                                                    <span x-show="!approval.approving" x-text="approval.type === 'post_approval' ? 'Aftekenen' : 'Goedkeuren'"></span>
                                                    <span x-show="approval.approving">Bezig...</span>
                                                </button>
                                            </td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </template>

                <!-- Two Column Layout -->
                <div class="stride-dashboard-grid">
                    <!-- Left Column -->
                    <div class="stride-dashboard-col">
                        <!-- Today's Sessions -->
                        <div class="stride-card">
                            <div class="stride-card-header">
                                <h3 class="stride-card-title">
                                    <span class="dashicons dashicons-clock"></span>
                                    Vandaag
                                </h3>
                            </div>
                            <div class="stride-card-body">
                                <template x-if="stats.todaySessionDetails && stats.todaySessionDetails.length > 0">
                                    <div class="stride-today-sessions">
                                        <template x-for="session in stats.todaySessionDetails" :key="session.id">
                                            <div class="stride-today-session">
                                                <div class="stride-session-time">
                                                    <span x-text="session.startTime || '—'"></span>
                                                    <span x-show="session.endTime"> - <span x-text="session.endTime"></span></span>
                                                </div>
                                                <div class="stride-session-info">
                                                    <div class="stride-session-title" x-text="session.editionTitle || session.title"></div>
                                                    <div class="stride-session-meta">
                                                        <span class="dashicons dashicons-groups"></span>
                                                        <span x-text="session.registeredCount + ' deelnemers'"></span>
                                                    </div>
                                                </div>
                                            </div>
                                        </template>
                                    </div>
                                </template>
                                <template x-if="!stats.todaySessionDetails || stats.todaySessionDetails.length === 0">
                                    <div class="stride-empty-state-sm">
                                        <span class="dashicons dashicons-calendar"></span>
                                        <p>Geen sessies vandaag</p>
                                    </div>
                                </template>
                            </div>
                        </div>

                        <!-- Upcoming Editions -->
                        <div class="stride-card">
                            <div class="stride-card-header">
                                <h3 class="stride-card-title">
                                    <span class="dashicons dashicons-calendar-alt"></span>
                                    Komende Editions
                                </h3>
                                <a href="#/editions" @click.prevent="view = 'editions'" class="stride-card-link">Bekijk alle</a>
                            </div>
                            <div class="stride-card-body">
                                <template x-if="stats.upcomingEditionDetails && stats.upcomingEditionDetails.length > 0">
                                    <div class="stride-upcoming-list">
                                        <template x-for="edition in stats.upcomingEditionDetails" :key="edition.id">
                                            <div class="stride-upcoming-item" @click="view = 'editions'; $nextTick(() => openEdition(edition.id))">
                                                <div class="stride-upcoming-date">
                                                    <div class="stride-upcoming-day" x-text="new Date(edition.startDate).getDate()"></div>
                                                    <div class="stride-upcoming-month" x-text="new Date(edition.startDate).toLocaleDateString('nl-BE', { month: 'short' })"></div>
                                                </div>
                                                <div class="stride-upcoming-info">
                                                    <div class="stride-upcoming-title" x-text="edition.title"></div>
                                                    <div class="stride-upcoming-meta">
                                                        <span x-text="edition.registeredCount + ' ingeschreven'"></span>
                                                        <span x-show="edition.capacity > 0" class="stride-capacity-pill" :class="{ 'almost-full': edition.spotsLeft <= 3 && edition.spotsLeft > 0, 'full': edition.spotsLeft === 0 }">
                                                            <span x-show="edition.spotsLeft > 0" x-text="edition.spotsLeft + ' vrij'"></span>
                                                            <span x-show="edition.spotsLeft === 0">Volzet</span>
                                                        </span>
                                                    </div>
                                                </div>
                                            </div>
                                        </template>
                                    </div>
                                </template>
                                <template x-if="!stats.upcomingEditionDetails || stats.upcomingEditionDetails.length === 0">
                                    <div class="stride-empty-state-sm">
                                        <span class="dashicons dashicons-calendar-alt"></span>
                                        <p>Geen komende editions</p>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </div>

                    <!-- Right Column -->
                    <div class="stride-dashboard-col">
                        <!-- Recent Registrations -->
                        <div class="stride-card">
                            <div class="stride-card-header">
                                <h3 class="stride-card-title">
                                    <span class="dashicons dashicons-groups"></span>
                                    Recente Inschrijvingen
                                </h3>
                            </div>
                            <div class="stride-card-body">
                                <template x-if="stats.recentRegistrations && stats.recentRegistrations.length > 0">
                                    <div class="stride-activity-feed">
                                        <template x-for="reg in stats.recentRegistrations" :key="reg.id">
                                            <div class="stride-activity-item">
                                                <div class="stride-activity-avatar">
                                                    <span x-text="reg.userName.charAt(0).toUpperCase()"></span>
                                                </div>
                                                <div class="stride-activity-content">
                                                    <div class="stride-activity-text">
                                                        <strong x-text="reg.userName"></strong>
                                                        <span>schreef in voor</span>
                                                        <strong x-text="reg.editionTitle"></strong>
                                                    </div>
                                                    <div class="stride-activity-time" x-text="formatRelativeTime(reg.createdAt)"></div>
                                                </div>
                                                <span class="stride-badge" :class="'stride-badge-' + reg.status" x-text="reg.status"></span>
                                            </div>
                                        </template>
                                    </div>
                                </template>
                                <template x-if="!stats.recentRegistrations || stats.recentRegistrations.length === 0">
                                    <div class="stride-empty-state-sm">
                                        <span class="dashicons dashicons-groups"></span>
                                        <p>Geen recente inschrijvingen</p>
                                    </div>
                                </template>
                            </div>
                        </div>

                        <!-- Quick Actions -->
                        <div class="stride-card">
                            <div class="stride-card-header">
                                <h3 class="stride-card-title">
                                    <span class="dashicons dashicons-admin-tools"></span>
                                    Snelle Acties
                                </h3>
                            </div>
                            <div class="stride-card-body stride-quick-actions">
                                <a href="<?php echo esc_url($admin_url . 'post-new.php?post_type=vad_edition'); ?>" class="stride-quick-action">
                                    <span class="dashicons dashicons-plus-alt"></span>
                                    <span>Nieuwe Edition</span>
                                </a>
                                <a href="<?php echo esc_url($admin_url . 'post-new.php?post_type=vad_trajectory'); ?>" class="stride-quick-action">
                                    <span class="dashicons dashicons-networking"></span>
                                    <span>Nieuw Traject</span>
                                </a>
                                <a href="#/quotes" @click.prevent="view = 'quotes'" class="stride-quick-action">
                                    <span class="dashicons dashicons-media-document"></span>
                                    <span>Bekijk Offertes</span>
                                </a>
                                <a href="<?php echo esc_url($admin_url . 'users.php'); ?>" class="stride-quick-action">
                                    <span class="dashicons dashicons-admin-users"></span>
                                    <span>Gebruikers</span>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </template>

        <!-- Editions View -->
        <template x-if="view === 'editions'">
            <div>
                <div class="stride-page-header">
                    <h2 class="stride-page-title">Editions</h2>
                    <a href="<?php echo esc_url($admin_url . 'post-new.php?post_type=vad_edition'); ?>" class="stride-btn stride-btn-primary">
                        <span class="dashicons dashicons-plus-alt2"></span>
                        New Edition
                    </a>
                </div>

                <!-- Filters -->
                <div class="stride-card">
                    <div class="stride-filters">
                        <div class="stride-filter-group">
                            <label class="stride-filter-label">Zoeken</label>
                            <input type="text" class="stride-input" placeholder="Zoek editions..." x-model="editionFilters.search" @input.debounce.300ms="loadEditions()">
                        </div>
                        <div class="stride-filter-group">
                            <label class="stride-filter-label">Status</label>
                            <select class="stride-select" x-model="editionFilters.status" @change="loadEditions()">
                                <option value="">Alle statussen</option>
                                <option value="open">Open</option>
                                <option value="full">Vol</option>
                                <option value="cancelled">Geannuleerd</option>
                                <option value="completed">Afgerond</option>
                            </select>
                        </div>
                        <div class="stride-filter-group">
                            <label class="stride-filter-label">Categorie</label>
                            <select class="stride-select" x-model="editionFilters.courseTag" @change="loadEditions()">
                                <option value="0">Alle categorieën</option>
                                <template x-for="tag in courseTags" :key="tag.id">
                                    <option :value="tag.id" x-text="tag.name"></option>
                                </template>
                            </select>
                        </div>
                        <div class="stride-filter-group">
                            <label class="stride-filter-label">Periode</label>
                            <input type="text" class="stride-input stride-date-range" x-ref="dateRange" placeholder="Selecteer periode...">
                        </div>
                        <div class="stride-filter-group stride-filter-actions">
                            <button type="button" class="stride-btn stride-btn-text" @click="editionFilters = { search: '', status: '', dateFrom: '', dateTo: '', courseTag: 0 }; if(dateRangePicker) dateRangePicker.clear(); loadEditions();">
                                <span class="dashicons dashicons-dismiss"></span> Reset
                            </button>
                        </div>
                        <div class="stride-filter-group stride-view-toggle">
                            <div class="stride-toggle-buttons">
                                <button type="button" class="stride-toggle-btn" :class="{ 'active': editionView === 'agenda' }" @click="editionView = 'agenda'; editions = []; loadEditions();" title="Agenda weergave">
                                    <span class="dashicons dashicons-calendar-alt"></span>
                                </button>
                                <button type="button" class="stride-toggle-btn" :class="{ 'active': editionView === 'list' }" @click="editionView = 'list'; editions = []; loadEditions();" title="Lijst weergave">
                                    <span class="dashicons dashicons-list-view"></span>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Table -->
                    <div class="stride-table-wrapper">
                        <template x-if="editionsLoading">
                            <div class="stride-loading">Loading editions...</div>
                        </template>
                        <template x-if="!editionsLoading && editions.length === 0">
                            <div class="stride-empty">
                                <span class="dashicons dashicons-calendar-alt stride-empty-icon"></span>
                                <p>No editions found</p>
                            </div>
                        </template>
                        <!-- Agenda View Table -->
                        <template x-if="!editionsLoading && editions.length > 0 && editionView === 'agenda'">
                            <table class="stride-table stride-agenda-table">
                                <thead>
                                    <tr>
                                        <th>Datum</th>
                                        <th>Editie</th>
                                        <th>Locatie</th>
                                        <th>Capaciteit</th>
                                        <th>Status</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <template x-for="item in editions" :key="item.sessionId || item.id">
                                        <tr @click="openEdition(item.id)" class="stride-clickable" :class="{ 'stride-row-today': item.isToday, 'stride-row-past': item.isPast }">
                                            <td class="stride-agenda-date">
                                                <div class="stride-date-primary" x-text="formatDateFull(item.date)"></div>
                                                <div class="stride-date-time" x-show="item.startTime">
                                                    <span x-text="item.startTime"></span>
                                                    <span x-show="item.endTime"> - <span x-text="item.endTime"></span></span>
                                                </div>
                                                <span x-show="item.isToday" class="stride-badge stride-badge-today">Vandaag</span>
                                            </td>
                                            <td>
                                                <div class="stride-edition-title" x-text="item.title"></div>
                                                <div class="stride-session-subtitle" x-show="item.sessionTitle" x-text="item.sessionTitle"></div>
                                            </td>
                                            <td x-text="item.venue || '-'"></td>
                                            <td>
                                                <span class="stride-capacity" :class="{ 'full': item.registeredCount >= item.capacity }">
                                                    <span x-text="item.registeredCount"></span>/<span x-text="item.capacity"></span>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="stride-badge" :class="'stride-badge-' + item.status" x-text="item.status"></span>
                                            </td>
                                            <td>
                                                <a :href="item.editUrl" class="stride-btn stride-btn-sm stride-btn-outline" @click.stop>
                                                    Edit
                                                </a>
                                            </td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </template>

                        <!-- List View Table -->
                        <template x-if="!editionsLoading && editions.length > 0 && editionView === 'list'">
                            <table class="stride-table">
                                <thead>
                                    <tr>
                                        <th>Editie</th>
                                        <th>Periode</th>
                                        <th>Locatie</th>
                                        <th>Capaciteit</th>
                                        <th>Status</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <template x-for="edition in editions" :key="edition.id">
                                        <tr @click="openEdition(edition.id)" class="stride-clickable" :class="{ 'stride-row-today': edition.isToday, 'stride-row-past': edition.isPast }">
                                            <td>
                                                <div class="stride-edition-title">
                                                    <span x-text="edition.title"></span>
                                                    <span x-show="edition.isToday" class="stride-badge stride-badge-today">Vandaag</span>
                                                </div>
                                            </td>
                                            <td>
                                                <span x-text="formatDate(edition.startDate)"></span>
                                                <template x-if="edition.endDate && edition.endDate !== edition.startDate">
                                                    <span x-text="' - ' + formatDate(edition.endDate)"></span>
                                                </template>
                                            </td>
                                            <td x-text="edition.venue || '-'"></td>
                                            <td>
                                                <span class="stride-capacity" :class="{ 'full': edition.registeredCount >= edition.capacity }">
                                                    <span x-text="edition.registeredCount"></span>/<span x-text="edition.capacity"></span>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="stride-badge" :class="'stride-badge-' + edition.status" x-text="edition.status"></span>
                                            </td>
                                            <td>
                                                <a :href="edition.editUrl" class="stride-btn stride-btn-sm stride-btn-outline" @click.stop>
                                                    Edit
                                                </a>
                                            </td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </template>

                        <!-- Pagination -->
                        <template x-if="editionPages > 1">
                            <div class="stride-pagination">
                                <button class="stride-page-btn" @click="editionPage--; loadEditions()" :disabled="editionPage === 1">&laquo;</button>
                                <span class="stride-page-info">Page <span x-text="editionPage"></span> of <span x-text="editionPages"></span></span>
                                <button class="stride-page-btn" @click="editionPage++; loadEditions()" :disabled="editionPage >= editionPages">&raquo;</button>
                            </div>
                        </template>
                    </div>
                </div>

                <!-- Edition Detail Slide-over -->
                <template x-if="selectedEdition">
                    <div class="stride-slideover-backdrop" @click.self="selectedEdition = null">
                        <div class="stride-slideover">
                            <div class="stride-slideover-header">
                                <h3 x-text="selectedEdition.title"></h3>
                                <button class="stride-slideover-close" @click="selectedEdition = null">&times;</button>
                            </div>
                            <div class="stride-slideover-tabs">
                                <button class="stride-slideover-tab" :class="{ 'active': editionTab === 'students' }" @click="editionTab = 'students'">
                                    Students
                                </button>
                                <button class="stride-slideover-tab" :class="{ 'active': editionTab === 'attendance' }" @click="editionTab = 'attendance'">
                                    Attendance
                                </button>
                                <button class="stride-slideover-tab" :class="{ 'active': editionTab === 'info' }" @click="editionTab = 'info'">
                                    Info
                                </button>
                            </div>
                            <div class="stride-slideover-body">
                                <!-- Students Tab -->
                                <template x-if="editionTab === 'students'">
                                    <div>
                                        <template x-if="registrationsLoading">
                                            <div class="stride-loading">Loading students...</div>
                                        </template>
                                        <template x-if="!registrationsLoading && registrations.length === 0">
                                            <div class="stride-empty-sm">No students registered</div>
                                        </template>
                                        <template x-if="!registrationsLoading && registrations.length > 0">
                                            <div class="stride-student-list">
                                                <template x-for="reg in registrations" :key="reg.id">
                                                    <div class="stride-student-item">
                                                        <div class="stride-student-avatar" x-text="reg.name ? reg.name.charAt(0).toUpperCase() : '?'"></div>
                                                        <div class="stride-student-info">
                                                            <div class="stride-student-name" x-text="reg.name || 'Unknown'"></div>
                                                            <div class="stride-student-email" x-text="reg.email || ''"></div>
                                                        </div>
                                                        <span class="stride-badge stride-badge-sm" :class="'stride-badge-' + reg.status" x-text="reg.status"></span>
                                                    </div>
                                                </template>
                                            </div>
                                        </template>
                                    </div>
                                </template>

                                <!-- Attendance Tab -->
                                <template x-if="editionTab === 'attendance'">
                                    <div>
                                        <template x-if="selectedEdition.sessions && selectedEdition.sessions.length > 0">
                                            <div class="stride-attendance-grid">
                                                <table class="stride-table stride-table-compact">
                                                    <thead>
                                                        <tr>
                                                            <th>Student</th>
                                                            <template x-for="session in selectedEdition.sessions" :key="session.id">
                                                                <th class="stride-attendance-header">
                                                                    <div x-text="formatShortDate(session.date)"></div>
                                                                </th>
                                                            </template>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <template x-for="reg in registrations" :key="reg.id">
                                                            <tr>
                                                                <td x-text="reg.name || 'Unknown'"></td>
                                                                <template x-for="session in selectedEdition.sessions" :key="session.id">
                                                                    <td class="stride-attendance-cell">
                                                                        <button
                                                                            class="stride-attendance-btn"
                                                                            :class="{
                                                                                'present': reg.attendance && reg.attendance[session.id] === 'present',
                                                                                'absent': reg.attendance && reg.attendance[session.id] === 'absent',
                                                                                'excused': reg.attendance && reg.attendance[session.id] === 'excused'
                                                                            }"
                                                                            @click="toggleAttendance(session.id, reg.userId, reg.attendance ? reg.attendance[session.id] : null)"
                                                                        >
                                                                            <template x-if="reg.attendance && reg.attendance[session.id] === 'present'">
                                                                                <span class="dashicons dashicons-yes"></span>
                                                                            </template>
                                                                            <template x-if="reg.attendance && reg.attendance[session.id] === 'absent'">
                                                                                <span class="dashicons dashicons-no"></span>
                                                                            </template>
                                                                            <template x-if="reg.attendance && reg.attendance[session.id] === 'excused'">
                                                                                <span class="dashicons dashicons-clock"></span>
                                                                            </template>
                                                                            <template x-if="!reg.attendance || !reg.attendance[session.id]">
                                                                                <span class="dashicons dashicons-minus"></span>
                                                                            </template>
                                                                        </button>
                                                                    </td>
                                                                </template>
                                                            </tr>
                                                        </template>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </template>
                                        <template x-if="!selectedEdition.sessions || selectedEdition.sessions.length === 0">
                                            <div class="stride-empty-sm">No sessions defined</div>
                                        </template>
                                    </div>
                                </template>

                                <!-- Info Tab -->
                                <template x-if="editionTab === 'info'">
                                    <div class="stride-info-list">
                                        <div class="stride-info-row">
                                            <span class="stride-info-label">Start Date</span>
                                            <span x-text="formatDate(selectedEdition.startDate)"></span>
                                        </div>
                                        <div class="stride-info-row">
                                            <span class="stride-info-label">End Date</span>
                                            <span x-text="formatDate(selectedEdition.endDate) || '-'"></span>
                                        </div>
                                        <div class="stride-info-row">
                                            <span class="stride-info-label">Venue</span>
                                            <span x-text="selectedEdition.venue || '-'"></span>
                                        </div>
                                        <div class="stride-info-row">
                                            <span class="stride-info-label">Capacity</span>
                                            <span x-text="selectedEdition.registeredCount + '/' + selectedEdition.capacity"></span>
                                        </div>
                                        <div class="stride-info-row">
                                            <span class="stride-info-label">Status</span>
                                            <span class="stride-badge" :class="'stride-badge-' + selectedEdition.status" x-text="selectedEdition.status"></span>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </div>
                </template>
            </div>
        </template>

        <!-- Quotes View -->
        <template x-if="view === 'quotes'">
            <div>
                <div class="stride-page-header">
                    <h2 class="stride-page-title">Quotes</h2>
                </div>

                <!-- Filters -->
                <div class="stride-card">
                    <div class="stride-filters">
                        <div class="stride-filter-group">
                            <label class="stride-filter-label">Search</label>
                            <input type="text" class="stride-input" placeholder="Search user name or email..." x-model="quoteFilters.search" @input.debounce.300ms="loadQuotes()">
                        </div>
                        <div class="stride-filter-group">
                            <label class="stride-filter-label">Edition</label>
                            <select class="stride-select" x-model="quoteFilters.editionId" @change="loadQuotes()">
                                <option value="">All Editions</option>
                                <template x-for="edition in quoteEditions" :key="edition.id">
                                    <option :value="edition.id" x-text="edition.title"></option>
                                </template>
                            </select>
                        </div>
                        <div class="stride-filter-group">
                            <label class="stride-filter-label">Status</label>
                            <select class="stride-select" x-model="quoteFilters.status" @change="loadQuotes()">
                                <option value="">All Statuses</option>
                                <option value="draft">Draft</option>
                                <option value="sent">Sent</option>
                                <option value="exported">Exported</option>
                            </select>
                        </div>
                    </div>

                    <!-- Table -->
                    <div class="stride-table-wrapper">
                        <template x-if="quotesLoading">
                            <div class="stride-loading">Loading quotes...</div>
                        </template>
                        <template x-if="!quotesLoading && quotes.length === 0">
                            <div class="stride-empty">
                                <span class="dashicons dashicons-media-document stride-empty-icon"></span>
                                <p>No quotes found</p>
                            </div>
                        </template>
                        <template x-if="!quotesLoading && quotes.length > 0">
                            <table class="stride-table">
                                <thead>
                                    <tr>
                                        <th>Quote #</th>
                                        <th>Customer</th>
                                        <th>Edition</th>
                                        <th>Date</th>
                                        <th>Total</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <template x-for="quote in quotes" :key="quote.id">
                                        <tr @click="openQuote(quote)" class="stride-clickable">
                                            <td>
                                                <span class="stride-quote-number" x-text="quote.number || '-'"></span>
                                            </td>
                                            <td>
                                                <div class="stride-customer-name" x-text="quote.user?.name || 'Unknown'"></div>
                                                <div class="stride-customer-email" x-text="quote.user?.email || ''"></div>
                                            </td>
                                            <td>
                                                <div class="stride-edition-title" x-text="quote.edition?.title || '-'"></div>
                                            </td>
                                            <td x-text="formatDate(quote.date)"></td>
                                            <td>
                                                <span class="stride-amount" x-text="formatCurrency(quote.total)"></span>
                                            </td>
                                            <td>
                                                <span class="stride-badge" :class="'stride-badge-' + quote.status" x-text="quote.status"></span>
                                            </td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </template>

                        <!-- Pagination -->
                        <template x-if="quotePages > 1">
                            <div class="stride-pagination">
                                <button class="stride-page-btn" @click="quotePage--; loadQuotes()" :disabled="quotePage === 1">&laquo;</button>
                                <span class="stride-page-info">Page <span x-text="quotePage"></span> of <span x-text="quotePages"></span></span>
                                <button class="stride-page-btn" @click="quotePage++; loadQuotes()" :disabled="quotePage >= quotePages">&raquo;</button>
                            </div>
                        </template>
                    </div>
                </div>

                <!-- Quote Detail Slide-over -->
                <template x-if="selectedQuote">
                    <div class="stride-slideover-backdrop" @click.self="selectedQuote = null">
                        <div class="stride-slideover">
                            <div class="stride-slideover-header">
                                <h3>Quote <span x-text="selectedQuote.number || '#' + selectedQuote.id"></span></h3>
                                <button class="stride-slideover-close" @click="selectedQuote = null">&times;</button>
                            </div>
                            <div class="stride-slideover-tabs">
                                <button class="stride-slideover-tab" :class="{ 'active': quoteTab === 'details' }" @click="quoteTab = 'details'">
                                    Details
                                </button>
                                <button class="stride-slideover-tab" :class="{ 'active': quoteTab === 'items' }" @click="quoteTab = 'items'">
                                    Items
                                </button>
                            </div>
                            <div class="stride-slideover-body">
                                <!-- Details Tab -->
                                <template x-if="quoteTab === 'details'">
                                    <div class="stride-info-list">
                                        <div class="stride-info-row">
                                            <span class="stride-info-label">Status</span>
                                            <span class="stride-badge" :class="'stride-badge-' + selectedQuote.status" x-text="selectedQuote.statusLabel || selectedQuote.status"></span>
                                        </div>
                                        <div class="stride-info-row">
                                            <span class="stride-info-label">Customer</span>
                                            <div>
                                                <div x-text="selectedQuote.user?.name || 'Unknown'"></div>
                                                <div class="stride-text-muted" x-text="selectedQuote.user?.email || ''"></div>
                                            </div>
                                        </div>
                                        <div class="stride-info-row">
                                            <span class="stride-info-label">Edition</span>
                                            <span x-text="selectedQuote.edition?.title || '-'"></span>
                                        </div>
                                        <div class="stride-info-row">
                                            <span class="stride-info-label">Created</span>
                                            <span x-text="formatDate(selectedQuote.date)"></span>
                                        </div>
                                        <div class="stride-info-row" x-show="selectedQuote.sentAt">
                                            <span class="stride-info-label">Sent</span>
                                            <span x-text="formatDate(selectedQuote.sentAt)"></span>
                                        </div>
                                        <div class="stride-info-row" x-show="selectedQuote.validUntil">
                                            <span class="stride-info-label">Valid Until</span>
                                            <span x-text="formatDate(selectedQuote.validUntil)"></span>
                                        </div>
                                        <div class="stride-info-row stride-info-divider">
                                            <span class="stride-info-label">Subtotal</span>
                                            <span x-text="formatCurrency(selectedQuote.subtotal)"></span>
                                        </div>
                                        <div class="stride-info-row">
                                            <span class="stride-info-label">BTW (21%)</span>
                                            <span x-text="formatCurrency(selectedQuote.tax)"></span>
                                        </div>
                                        <div class="stride-info-row stride-info-total">
                                            <span class="stride-info-label">Total</span>
                                            <span class="stride-amount-lg" x-text="formatCurrency(selectedQuote.total)"></span>
                                        </div>
                                        <div class="stride-info-actions">
                                            <a :href="selectedQuote.editUrl" class="stride-btn stride-btn-primary">
                                                Edit in WP Admin
                                            </a>
                                        </div>
                                    </div>
                                </template>

                                <!-- Items Tab -->
                                <template x-if="quoteTab === 'items'">
                                    <div>
                                        <template x-if="selectedQuote.lineItems && selectedQuote.lineItems.length > 0">
                                            <div class="stride-quote-items">
                                                <template x-for="(item, index) in selectedQuote.lineItems" :key="index">
                                                    <div class="stride-quote-item">
                                                        <div class="stride-quote-item-title" x-text="item.title || item.description || 'Item'"></div>
                                                        <div class="stride-quote-item-details">
                                                            <span class="stride-quote-item-type" x-text="item.type || ''"></span>
                                                            <span class="stride-quote-item-price" x-text="formatCurrency(item.price || item.amount || 0)"></span>
                                                        </div>
                                                    </div>
                                                </template>
                                            </div>
                                        </template>
                                        <template x-if="!selectedQuote.lineItems || selectedQuote.lineItems.length === 0">
                                            <div class="stride-empty-sm">No line items</div>
                                        </template>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </div>
                </template>
            </div>
        </template>

        <!-- Trajectories View -->
        <template x-if="view === 'trajectories'">
            <div>
                <div class="stride-page-header">
                    <h2 class="stride-page-title">Trajecten</h2>
                </div>

                <!-- Filters -->
                <div class="stride-card">
                    <div class="stride-filters">
                        <div class="stride-filter-group">
                            <label class="stride-filter-label">Zoeken</label>
                            <input type="text" class="stride-input" placeholder="Naam traject..." x-model="trajectoryFilters.search" @input.debounce.300ms="loadTrajectories()">
                        </div>
                        <div class="stride-filter-group">
                            <label class="stride-filter-label">Status</label>
                            <select class="stride-select" x-model="trajectoryFilters.status" @change="loadTrajectories()">
                                <option value="">Alle Statussen</option>
                                <option value="open">Open</option>
                                <option value="closed">Gesloten</option>
                                <option value="full">Volzet</option>
                                <option value="draft">Concept</option>
                            </select>
                        </div>
                    </div>

                    <!-- Table -->
                    <div class="stride-table-wrapper">
                        <template x-if="trajectoriesLoading">
                            <div class="stride-loading">Trajecten laden...</div>
                        </template>
                        <template x-if="!trajectoriesLoading && trajectories.length === 0">
                            <div class="stride-empty">
                                <span class="dashicons dashicons-networking stride-empty-icon"></span>
                                <p>Geen trajecten gevonden</p>
                            </div>
                        </template>
                        <template x-if="!trajectoriesLoading && trajectories.length > 0">
                            <table class="stride-table">
                                <thead>
                                    <tr>
                                        <th>Traject</th>
                                        <th>Modus</th>
                                        <th>Cursussen</th>
                                        <th>Ingeschreven</th>
                                        <th>Prijs</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <template x-for="trajectory in trajectories" :key="trajectory.id">
                                        <tr @click="openTrajectory(trajectory)" class="stride-clickable">
                                            <td>
                                                <div class="stride-trajectory-name" x-text="trajectory.title"></div>
                                                <div class="stride-trajectory-deadline" x-show="trajectory.enrollmentDeadline">
                                                    Deadline: <span x-text="formatDate(trajectory.enrollmentDeadline)"></span>
                                                </div>
                                            </td>
                                            <td>
                                                <span class="stride-badge stride-badge-info" x-text="trajectory.modeLabel"></span>
                                            </td>
                                            <td x-text="trajectory.courseCount + ' cursussen'"></td>
                                            <td>
                                                <span x-text="trajectory.enrolledCount"></span>
                                                <span x-show="trajectory.capacity > 0" class="stride-capacity-indicator">
                                                    / <span x-text="trajectory.capacity"></span>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="stride-amount" x-text="'€ ' + trajectory.priceFormatted"></span>
                                            </td>
                                            <td>
                                                <span class="stride-badge" :class="'stride-badge-' + trajectory.status" x-text="trajectory.statusLabel"></span>
                                            </td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </template>

                        <!-- Pagination -->
                        <template x-if="trajectoryPages > 1">
                            <div class="stride-pagination">
                                <button class="stride-page-btn" @click="trajectoryPage--; loadTrajectories()" :disabled="trajectoryPage === 1">&laquo;</button>
                                <span class="stride-page-info">Pagina <span x-text="trajectoryPage"></span> van <span x-text="trajectoryPages"></span></span>
                                <button class="stride-page-btn" @click="trajectoryPage++; loadTrajectories()" :disabled="trajectoryPage >= trajectoryPages">&raquo;</button>
                            </div>
                        </template>
                    </div>
                </div>

                <!-- Trajectory Detail Slide-over -->
                <template x-if="selectedTrajectory">
                    <div class="stride-slideover-backdrop" @click.self="selectedTrajectory = null">
                        <div class="stride-slideover stride-slideover-wide">
                            <div class="stride-slideover-header">
                                <h3 x-text="selectedTrajectory.title"></h3>
                                <button class="stride-slideover-close" @click="selectedTrajectory = null">&times;</button>
                            </div>
                            <div class="stride-slideover-tabs">
                                <button class="stride-slideover-tab" :class="{ 'active': trajectoryTab === 'details' }" @click="trajectoryTab = 'details'">
                                    Details
                                </button>
                                <button class="stride-slideover-tab" :class="{ 'active': trajectoryTab === 'courses' }" @click="trajectoryTab = 'courses'">
                                    Cursussen
                                </button>
                                <button class="stride-slideover-tab" :class="{ 'active': trajectoryTab === 'students' }" @click="trajectoryTab = 'students'">
                                    Studenten
                                </button>
                            </div>
                            <div class="stride-slideover-body">
                                <!-- Details Tab -->
                                <template x-if="trajectoryTab === 'details'">
                                    <div class="stride-info-list">
                                        <div class="stride-info-row">
                                            <span class="stride-info-label">Status</span>
                                            <span class="stride-badge" :class="'stride-badge-' + selectedTrajectory.status" x-text="selectedTrajectory.statusLabel"></span>
                                        </div>
                                        <div class="stride-info-row">
                                            <span class="stride-info-label">Modus</span>
                                            <span class="stride-badge stride-badge-info" x-text="selectedTrajectory.modeLabel"></span>
                                        </div>
                                        <div class="stride-info-row">
                                            <span class="stride-info-label">Capaciteit</span>
                                            <span x-text="selectedTrajectory.capacity > 0 ? selectedTrajectory.capacity + ' plaatsen' : 'Onbeperkt'"></span>
                                        </div>
                                        <div class="stride-info-row">
                                            <span class="stride-info-label">Ingeschreven</span>
                                            <span x-text="selectedTrajectory.enrolledCount + ' studenten'"></span>
                                        </div>
                                        <div class="stride-info-row stride-info-divider">
                                            <span class="stride-info-label">Prijs (lid)</span>
                                            <span class="stride-amount" x-text="'€ ' + selectedTrajectory.priceFormatted"></span>
                                        </div>
                                        <div class="stride-info-row" x-show="selectedTrajectory.priceNonMember > 0">
                                            <span class="stride-info-label">Prijs (niet-lid)</span>
                                            <span class="stride-amount" x-text="'€ ' + selectedTrajectory.priceNonMemberFormatted"></span>
                                        </div>
                                        <div class="stride-info-row stride-info-divider" x-show="selectedTrajectory.enrollmentDeadline">
                                            <span class="stride-info-label">Inschrijfdeadline</span>
                                            <span x-text="formatDate(selectedTrajectory.enrollmentDeadline)"></span>
                                        </div>
                                        <div class="stride-info-row" x-show="selectedTrajectory.choiceAvailableDate">
                                            <span class="stride-info-label">Keuzemoment start</span>
                                            <span x-text="formatDate(selectedTrajectory.choiceAvailableDate)"></span>
                                        </div>
                                        <div class="stride-info-row" x-show="selectedTrajectory.choiceDeadline">
                                            <span class="stride-info-label">Keuzemoment deadline</span>
                                            <span x-text="formatDate(selectedTrajectory.choiceDeadline)"></span>
                                        </div>
                                        <div class="stride-info-actions">
                                            <a :href="selectedTrajectory.editUrl" class="stride-btn stride-btn-primary">
                                                Bewerken in WP Admin
                                            </a>
                                        </div>
                                    </div>
                                </template>

                                <!-- Courses Tab -->
                                <template x-if="trajectoryTab === 'courses'">
                                    <div>
                                        <template x-if="selectedTrajectory.courses && selectedTrajectory.courses.length > 0">
                                            <div class="stride-course-list">
                                                <template x-for="(course, index) in selectedTrajectory.courses" :key="index">
                                                    <div class="stride-course-item">
                                                        <div class="stride-course-item-header">
                                                            <span class="stride-course-item-title" x-text="course.title || 'Edition #' + course.editionId"></span>
                                                            <span class="stride-badge" :class="course.type === 'required' ? 'stride-badge-primary' : 'stride-badge-info'" x-text="course.type === 'required' ? 'Verplicht' : 'Keuze'"></span>
                                                        </div>
                                                    </div>
                                                </template>
                                            </div>
                                        </template>
                                        <template x-if="!selectedTrajectory.courses || selectedTrajectory.courses.length === 0">
                                            <div class="stride-empty-sm">Geen cursussen gekoppeld</div>
                                        </template>
                                    </div>
                                </template>

                                <!-- Students Tab -->
                                <template x-if="trajectoryTab === 'students'">
                                    <div>
                                        <template x-if="selectedTrajectory.enrolledUsers && selectedTrajectory.enrolledUsers.length > 0">
                                            <table class="stride-table stride-table-compact">
                                                <thead>
                                                    <tr>
                                                        <th>Naam</th>
                                                        <th>Email</th>
                                                        <th>Status</th>
                                                        <th>Ingeschreven</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <template x-for="student in selectedTrajectory.enrolledUsers" :key="student.id">
                                                        <tr>
                                                            <td x-text="student.name"></td>
                                                            <td>
                                                                <a :href="'mailto:' + student.email" class="stride-link" x-text="student.email"></a>
                                                            </td>
                                                            <td>
                                                                <span class="stride-badge" :class="'stride-badge-' + student.status" x-text="student.status"></span>
                                                            </td>
                                                            <td x-text="formatDate(student.enrolledAt)"></td>
                                                        </tr>
                                                    </template>
                                                </tbody>
                                            </table>
                                        </template>
                                        <template x-if="!selectedTrajectory.enrolledUsers || selectedTrajectory.enrolledUsers.length === 0">
                                            <div class="stride-empty-sm">Nog geen studenten ingeschreven</div>
                                        </template>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </div>
                </template>
            </div>
        </template>
    </div>
</div>
