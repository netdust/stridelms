<?php
/**
 * Admin Dashboard Template — Soft Violet
 *
 * Full-screen Alpine.js application for Stride admin.
 * Views: Dashboard, Opkomende Sessies, Offertes, Trajecten, Gebruikers
 *
 * @var string $admin_url Base admin URL
 * @var string $user_name Current user display name
 * @package Stride\Admin
 */
defined('ABSPATH') || exit;
?>
<div class="wrap sd-app" x-data="strideApp()" x-cloak>

    <!-- ============================================================
         HEADER BAR
         ============================================================ -->
    <header class="sd-header">
        <div class="sd-header__left">
            <span class="sd-header__logo">Stride</span>
            <nav class="sd-header__nav">
                <button class="sd-header__tab"
                        :class="{ 'sd-header__tab--active': view === 'dashboard' }"
                        @click="switchView('dashboard')">Dashboard</button>
                <button class="sd-header__tab"
                        :class="{ 'sd-header__tab--active': view === 'edities' }"
                        @click="switchView('edities')">Opkomende Sessies</button>
                <button class="sd-header__tab"
                        :class="{ 'sd-header__tab--active': view === 'offertes' }"
                        @click="switchView('offertes')">Offertes</button>
                <button class="sd-header__tab"
                        :class="{ 'sd-header__tab--active': view === 'trajecten' }"
                        @click="switchView('trajecten')">Trajecten</button>
                <button class="sd-header__tab"
                        :class="{ 'sd-header__tab--active': view === 'gebruikers' }"
                        @click="switchView('gebruikers')">Gebruikers</button>
            </nav>
        </div>
        <div class="sd-header__right">
            <!-- Notification bell -->
            <div class="sd-bell" @click.stop="toggleNotifications()">
                <span class="sd-bell__icon">🔔</span>
                <span class="sd-bell__badge" x-show="unreadCount > 0" x-text="unreadCount"></span>
                <div class="sd-bell__dropdown" x-show="showNotifications" @click.outside="showNotifications = false" x-transition>
                    <template x-for="notif in notifications" :key="notif.id">
                        <div class="sd-bell__item" :class="{ 'sd-bell__item--unread': !notif.read }">
                            <span class="sd-bell__text" x-text="notif.text"></span>
                            <span class="sd-bell__time" x-text="formatRelativeTime(notif.timestamp)"></span>
                        </div>
                    </template>
                    <div class="sd-bell__footer">
                        <button class="sd-btn sd-btn--text" @click="markAllRead()">Alles gelezen</button>
                    </div>
                </div>
            </div>
            <!-- User avatar + name -->
            <div class="sd-avatar" x-text="(config.user?.name || '?')[0].toUpperCase()"></div>
            <span class="sd-header__user" x-text="config.user?.name"></span>
        </div>
    </header>

    <!-- ============================================================
         CONTENT AREA
         ============================================================ -->
    <div class="sd-content">

        <!-- ========================================================
             VIEW: DASHBOARD
             ======================================================== -->
        <div x-show="view === 'dashboard'">

            <!-- Greeting + date -->
            <div class="sd-greeting">
                <h2>Hi, <span x-text="config.user?.firstName || config.user?.name"></span></h2>
                <span class="sd-greeting__date" x-text="formatDate(new Date())"></span>
            </div>

            <!-- KPI row -->
            <div class="sd-kpi-row">
                <div class="sd-kpi-card" @click="switchView('edities')">
                    <span class="sd-kpi-card__label">Komende edities</span>
                    <template x-if="statsLoaded">
                        <span class="sd-kpi-card__value" x-text="stats.upcomingEditions ?? '—'"></span>
                    </template>
                    <template x-if="!statsLoaded">
                        <span class="sd-skeleton sd-skeleton--kpi"></span>
                    </template>
                </div>
                <div class="sd-kpi-card" @click="switchView('edities')">
                    <span class="sd-kpi-card__label">Actieve inschrijvingen</span>
                    <template x-if="statsLoaded">
                        <span class="sd-kpi-card__value" x-text="stats.totalRegistrations ?? '—'"></span>
                    </template>
                    <template x-if="!statsLoaded">
                        <span class="sd-skeleton sd-skeleton--kpi"></span>
                    </template>
                </div>
                <div class="sd-kpi-card" @click="switchView('offertes')">
                    <span class="sd-kpi-card__label">Openstaande offertes</span>
                    <template x-if="statsLoaded">
                        <span class="sd-kpi-card__value" x-text="stats.pendingQuotes ?? '—'"></span>
                    </template>
                    <template x-if="!statsLoaded">
                        <span class="sd-skeleton sd-skeleton--kpi"></span>
                    </template>
                </div>
                <div class="sd-kpi-card">
                    <span class="sd-kpi-card__label">Sessies vandaag</span>
                    <template x-if="statsLoaded">
                        <span class="sd-kpi-card__value" x-text="stats.todaySessions ?? '—'"></span>
                    </template>
                    <template x-if="!statsLoaded">
                        <span class="sd-skeleton sd-skeleton--kpi"></span>
                    </template>
                </div>
                <div class="sd-kpi-card" :class="{ 'sd-kpi-card--alert': (stats.actionsNeeded ?? 0) > 0 }">
                    <span class="sd-kpi-card__label">Acties nodig</span>
                    <template x-if="statsLoaded">
                        <span class="sd-kpi-card__value" x-text="stats.actionsNeeded ?? '—'"></span>
                    </template>
                    <template x-if="!statsLoaded">
                        <span class="sd-skeleton sd-skeleton--kpi"></span>
                    </template>
                </div>
            </div>

            <!-- Two-column layout -->
            <div class="sd-layout">
                <div class="sd-layout__primary">

                    <!-- Unified Acties nodig panel: approvals + post-course sign-offs + stale pendings + system notifications -->
                    <div class="sd-card" id="action-required-card">
                        <div class="sd-card__header">
                            <h3 class="sd-card__title">Acties nodig</h3>
                        </div>
                        <div class="sd-card__body">
                            <!-- Tabs (always visible so admin can see "0 wachten" at a glance) -->
                            <div class="sd-tabs" style="display:flex;gap:8px;margin-bottom:12px;border-bottom:1px solid var(--sd-border, #e5e7eb);">
                                <!-- "Wacht op mij" merges both approval AND post_approval: in both cases admin's
                                     job is to OK the registration to move it to the next phase. Row badge +
                                     button label switch per item.type. -->
                                <button
                                    type="button"
                                    class="sd-tab"
                                    :class="{ 'sd-tab--active': pendingApprovalsTab === 'approval', 'sd-tab--empty': (pendingApprovals.counts.approval + pendingApprovals.counts.post_approval) === 0 }"
                                    @click="pendingApprovalsTab = 'approval'">
                                    Wacht op mij
                                    <span class="sd-pill" :class="{ 'sd-pill--muted': (pendingApprovals.counts.approval + pendingApprovals.counts.post_approval) === 0 }" x-text="pendingApprovals.counts.approval + pendingApprovals.counts.post_approval"></span>
                                </button>
                                <button
                                    type="button"
                                    class="sd-tab"
                                    :class="{ 'sd-tab--active': pendingApprovalsTab === 'stale_user', 'sd-tab--empty': pendingApprovals.counts.stale_user === 0 }"
                                    @click="pendingApprovalsTab = 'stale_user'">
                                    Wacht op gebruiker
                                    <span class="sd-pill" :class="pendingApprovals.counts.stale_user === 0 ? 'sd-pill--muted' : 'sd-pill--warn'" x-text="pendingApprovals.counts.stale_user"></span>
                                </button>
                                <button
                                    type="button"
                                    class="sd-tab"
                                    :class="{ 'sd-tab--active': pendingApprovalsTab === 'notifications', 'sd-tab--empty': actionQueue.length === 0 }"
                                    @click="pendingApprovalsTab = 'notifications'">
                                    Meldingen
                                    <span class="sd-pill" :class="{ 'sd-pill--muted': actionQueue.length === 0 }" x-text="actionQueue.length"></span>
                                </button>
                            </div>

                            <!-- Per-tab empty state -->
                            <template x-if="!loading && pendingApprovalsTab === 'approval' && (pendingApprovals.counts.approval + pendingApprovals.counts.post_approval) === 0">
                                <div class="sd-empty">
                                    <span class="sd-empty__icon">✓</span>
                                    <p class="sd-empty__text">Geen inschrijvingen wachten op jouw goedkeuring</p>
                                </div>
                            </template>
                            <template x-if="!loading && pendingApprovalsTab === 'stale_user' && pendingApprovals.counts.stale_user === 0">
                                <div class="sd-empty">
                                    <span class="sd-empty__icon">✓</span>
                                    <p class="sd-empty__text">Geen hangende inschrijvingen</p>
                                    <p class="sd-empty__hint">Inschrijvingen <span x-text="pendingApprovals.stale_threshold_days"></span> dagen of langer zonder activiteit verschijnen hier.</p>
                                </div>
                            </template>
                            <template x-if="!loading && pendingApprovalsTab === 'notifications' && actionQueue.length === 0">
                                <div class="sd-empty">
                                    <span class="sd-empty__icon">✓</span>
                                    <p class="sd-empty__text">Geen meldingen</p>
                                </div>
                            </template>

                            <!-- Registration buckets (active tab only) -->
                            <table class="sd-table" x-show="pendingApprovalsTab !== 'notifications' && (
                                (pendingApprovalsTab === 'approval' && (pendingApprovals.counts.approval + pendingApprovals.counts.post_approval) > 0) ||
                                (pendingApprovalsTab === 'stale_user' && pendingApprovals.counts.stale_user > 0)
                            )">
                                <thead>
                                    <tr>
                                        <th>Gebruiker</th>
                                        <th>Editie</th>
                                        <th>Status</th>
                                        <th>Inschrijfdatum</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <template x-for="item in pendingApprovals.items.filter(i => pendingApprovalsTab === 'approval' ? (i.type === 'approval' || i.type === 'post_approval') : i.type === pendingApprovalsTab)" :key="item.id">
                                        <tr>
                                            <td>
                                                <a href="#" @click.prevent="viewUserInDetail(item.user_id)" x-text="item.user_name"></a>
                                                <div style="font-size:11px;color:#646970;" x-text="item.user_email"></div>
                                            </td>
                                            <td x-text="item.edition_title"></td>
                                            <td>
                                                <template x-if="item.type === 'approval'">
                                                    <span class="sd-status-badge sd-status-badge--pending">Klaar voor goedkeuring</span>
                                                </template>
                                                <template x-if="item.type === 'post_approval'">
                                                    <span class="sd-status-badge sd-status-badge--pending">Aftekening vereist</span>
                                                </template>
                                                <template x-if="item.type === 'stale_user'">
                                                    <span style="color:#b26200;">
                                                        Wacht op: <strong x-text="item.open_task_label || 'taak'"></strong>
                                                        <span style="color:#646970;font-size:11px;">— <span x-text="item.days_idle"></span>d openstaand</span>
                                                    </span>
                                                </template>
                                            </td>
                                            <td x-text="(item.registered_at || '').substring(0, 10)"></td>
                                            <td style="white-space:nowrap;">
                                                <template x-if="item.type === 'approval'">
                                                    <button class="sd-btn sd-btn--primary" @click="approveFromRow(item)">Keur goed</button>
                                                </template>
                                                <template x-if="item.type === 'post_approval'">
                                                    <button class="sd-btn sd-btn--primary" @click="approveFromRow(item)">Teken af</button>
                                                </template>
                                                <template x-if="item.type === 'stale_user' && item.edition_id">
                                                    <a :href="'<?php echo esc_url($admin_url); ?>post.php?post=' + item.edition_id + '&action=edit'"
                                                       class="sd-btn sd-btn--ghost"
                                                       target="_blank">Bekijk editie →</a>
                                                </template>
                                                <button class="sd-btn sd-btn--text" @click="viewUserInDetail(item.user_id)">Gebruiker →</button>
                                            </td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                            <p style="margin-top:12px;font-size:12px;color:#646970;" x-show="pendingApprovalsTab === 'stale_user'">
                                Inschrijvingen die <span x-text="pendingApprovals.stale_threshold_days"></span> dagen of langer geen activiteit hebben gehad.
                                Capaciteit blijft gereserveerd zolang ze openstaan — beslis per geval om te contacteren of te annuleren.
                            </p>

                            <!-- Notifications bucket: the rule-driven action queue -->
                            <div x-show="pendingApprovalsTab === 'notifications'">
                                <template x-for="item in actionQueue" :key="item.rule + (item.subject_id || '')">
                                    <div class="sd-action-item">
                                        <span class="sd-badge--priority" :class="'sd-badge--priority-' + item.priority"></span>
                                        <span class="sd-action-item__text" x-text="item.text"></span>
                                        <a :href="item.url || '#'" class="sd-action-item__link" x-show="item.url" target="_blank">Bekijk →</a>
                                        <button class="sd-action-item__dismiss" @click="dismissAction(item.rule, item.subject_id)" title="Negeren">×</button>
                                    </div>
                                </template>
                            </div>
                        </div>
                        <!-- Health checks footer -->
                        <div class="sd-health-checks">
                            <span class="sd-health-checks__label">Systeem</span>
                            <span class="sd-health-checks__item">
                                <span class="sd-health-dot" :class="'sd-health-dot--' + healthChecks.registration"></span>
                                Inschrijvingen
                            </span>
                            <span class="sd-health-checks__item">
                                <span class="sd-health-dot" :class="'sd-health-dot--' + healthChecks.mail"></span>
                                E-mail
                            </span>
                        </div>
                    </div>

                    <!-- Komende sessies -->
                    <div class="sd-card">
                        <div class="sd-card__header">
                            <h3 class="sd-card__title">Komende sessies</h3>
                            <a href="#" class="sd-card__link" @click.prevent="switchView('edities')">Alles bekijken →</a>
                        </div>
                        <table class="sd-table">
                            <thead>
                                <tr>
                                    <th>Editie</th>
                                    <th>Datum</th>
                                    <th>Tijd</th>
                                    <th>Locatie</th>
                                    <th>Capaciteit</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <template x-if="loading && upcomingSessions.length === 0">
                                    <template x-for="i in 4" :key="'sk-up-' + i">
                                        <tr class="sd-skeleton-row">
                                            <td><span class="sd-skeleton sd-skeleton--line"></span></td>
                                            <td><span class="sd-skeleton sd-skeleton--text"></span></td>
                                            <td><span class="sd-skeleton sd-skeleton--text"></span></td>
                                            <td><span class="sd-skeleton sd-skeleton--text"></span></td>
                                            <td><span class="sd-skeleton sd-skeleton--text"></span></td>
                                            <td><span class="sd-skeleton sd-skeleton--badge"></span></td>
                                        </tr>
                                    </template>
                                </template>
                                <template x-for="session in upcomingSessions" :key="session.sessionId || session.id">
                                    <tr :class="{'sd-table__row--today': session.isToday, 'sd-table__row--past': session.isPast}">
                                        <td><a :href="'<?php echo esc_url($admin_url); ?>post.php?post=' + session.edition_id + '&action=edit'" x-text="session.edition_title"></a></td>
                                        <td x-text="formatDate(session.date)"></td>
                                        <td x-text="session.time"></td>
                                        <td x-text="session.venue || '—'"></td>
                                        <td><span class="sd-capacity" x-text="session.registered + '/' + (session.capacity || '∞')"></span></td>
                                        <td><span class="sd-badge" :class="'sd-badge--' + session.status" x-text="session.status_label"></span></td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                        <template x-if="upcomingSessions.length === 0 && !loading">
                            <div class="sd-empty">
                                <span class="sd-empty__icon">📅</span>
                                <p class="sd-empty__text">Geen komende sessies</p>
                                <p class="sd-empty__hint">Er staan geen sessies gepland voor de komende dagen.</p>
                            </div>
                        </template>
                    </div>

                </div><!-- /.sd-layout__primary -->

                <div class="sd-layout__secondary">

                    <!-- Quick actions -->
                    <div class="sd-card">
                        <div class="sd-card__header">
                            <h3 class="sd-card__title">Snelle acties</h3>
                        </div>
                        <div class="sd-card__body">
                            <div class="sd-quick-actions">
                                <a href="<?php echo esc_url($admin_url); ?>post-new.php?post_type=vad_edition" class="sd-btn sd-btn--ghost sd-btn--block">+ Nieuwe editie</a>
                                <a href="<?php echo esc_url($admin_url); ?>post-new.php?post_type=vad_trajectory" class="sd-btn sd-btn--ghost sd-btn--block">+ Nieuw traject</a>
                                <a href="#" @click.prevent="switchView('offertes')" class="sd-btn sd-btn--ghost sd-btn--block">Offertes beheren</a>
                                <button class="sd-btn sd-btn--ghost sd-btn--block" @click="exportRegistrations()">Inschrijvingen exporteren</button>
                            </div>
                        </div>
                    </div>

                    <!-- Activity feed -->
                    <div class="sd-card">
                        <div class="sd-card__header">
                            <h3 class="sd-card__title">Recente activiteit</h3>
                        </div>
                        <div class="sd-card__body">
                            <template x-for="entry in activityFeed" :key="entry.id">
                                <div class="sd-activity-item">
                                    <div class="sd-activity-icon" :class="'sd-activity-icon--' + (entry.type || 'action')" :title="entry.actor_name || ''" x-html="activityIcon(entry.type)"></div>
                                    <div class="sd-activity-item__content">
                                        <template x-if="entry.target_url">
                                            <a class="sd-activity-item__text sd-activity-item__text--link" :href="entry.target_url" x-text="entry.text"></a>
                                        </template>
                                        <template x-if="!entry.target_url">
                                            <span class="sd-activity-item__text" x-text="entry.text"></span>
                                        </template>
                                        <span class="sd-activity-item__time" x-text="formatRelativeTime(entry.timestamp)"></span>
                                    </div>
                                </div>
                            </template>
                            <template x-if="activityFeed.length === 0 && !loading">
                                <div class="sd-empty">
                                    <span class="sd-empty__icon">·</span>
                                    <p class="sd-empty__text">Nog geen activiteit</p>
                                </div>
                            </template>
                            <!-- Activity feed shows last 10 items; no dedicated view yet -->
                        </div>
                    </div>

                    <!-- User search widget -->
                    <div class="sd-card">
                        <div class="sd-card__header">
                            <h3 class="sd-card__title">Gebruiker zoeken</h3>
                        </div>
                        <div class="sd-card__body">
                            <input type="text"
                                   class="sd-input"
                                   placeholder="Zoek op naam of e-mail…"
                                   @input.debounce.300ms="dashboardUserSearch($event.target.value)">
                            <div class="sd-search-results" x-show="dashboardUserResults.length > 0">
                                <template x-for="user in dashboardUserResults" :key="user.id">
                                    <div class="sd-search-results__item" @click="selectUser(user); switchView('gebruikers')">
                                        <div class="sd-avatar sd-avatar--sm" x-text="(user.name || '?')[0].toUpperCase()"></div>
                                        <div>
                                            <div class="sd-search-results__name" x-text="user.name"></div>
                                            <div class="sd-search-results__email" x-text="user.email"></div>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </div>

                </div><!-- /.sd-layout__secondary -->
            </div><!-- /.sd-layout -->

        </div><!-- /view: dashboard -->

        <!-- ========================================================
             VIEW: EDITIES
             ======================================================== -->
        <div x-show="view === 'edities'">

            <!-- Filters bar -->
            <div class="sd-filters">
                <input type="text"
                       class="sd-input"
                       placeholder="Zoek editie…"
                       x-model="editionFilters.search"
                       @input.debounce.300ms="loadEditions()">
                <select class="sd-select" x-model="editionFilters.status" @change="loadEditions()">
                    <option value="">Alle statussen</option>
                    <option value="open">Open</option>
                    <option value="full">Vol</option>
                    <option value="cancelled">Geannuleerd</option>
                    <option value="completed">Afgelopen</option>
                    <option value="closed">Gesloten</option>
                    <option value="announcement">Aankondiging</option>
                </select>
                <input type="text"
                       class="sd-input"
                       placeholder="Datumbereik"
                       x-ref="dateRange">
                <select class="sd-select" x-model="editionFilters.theme" @change="loadEditions()">
                    <option value="0">Alle onderwerpen</option>
                    <template x-for="term in editionTaxonomies.theme" :key="term.id">
                        <option :value="term.id" x-text="term.name"></option>
                    </template>
                </select>
                <select class="sd-select" x-model="editionFilters.format" @change="loadEditions()">
                    <option value="0">Alle vormen</option>
                    <template x-for="term in editionTaxonomies.format" :key="term.id">
                        <option :value="term.id" x-text="term.name"></option>
                    </template>
                </select>
                <select class="sd-select" x-model="editionFilters.tag" @change="loadEditions()" x-show="editionTaxonomies.tag.length > 0">
                    <option value="0">Alle tags</option>
                    <template x-for="term in editionTaxonomies.tag" :key="term.id">
                        <option :value="term.id" x-text="term.name"></option>
                    </template>
                </select>
                <button class="sd-btn sd-btn--ghost" @click="resetEditionFilters()">Reset</button>
            </div>

            <!-- Error state -->
            <template x-if="errors.edities">
                <div class="sd-error">
                    <span class="sd-error__icon">!</span>
                    <p class="sd-error__title" x-text="errors.edities"></p>
                    <button class="sd-btn sd-btn--ghost sd-btn--sm" @click="loadEditions()">Opnieuw proberen</button>
                </div>
            </template>

            <!-- Session table -->
            <div class="sd-card" x-show="!errors.edities">
                <table class="sd-table">
                    <thead>
                        <tr>
                            <th>Editie</th>
                            <th>Sessie</th>
                            <th>Datum</th>
                            <th>Tijd</th>
                            <th>Locatie</th>
                            <th>Capaciteit</th>
                            <th>Status</th>
                            <th>Acties</th>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- Skeleton rows while loading (no data yet) -->
                        <template x-if="loading && editionSessions.length === 0">
                            <template x-for="i in 5" :key="'sk-' + i">
                                <tr class="sd-skeleton-row">
                                    <td><span class="sd-skeleton sd-skeleton--line"></span></td>
                                    <td><span class="sd-skeleton sd-skeleton--text"></span></td>
                                    <td><span class="sd-skeleton sd-skeleton--text"></span></td>
                                    <td><span class="sd-skeleton sd-skeleton--text"></span></td>
                                    <td><span class="sd-skeleton sd-skeleton--text"></span></td>
                                    <td><span class="sd-skeleton sd-skeleton--text"></span></td>
                                    <td><span class="sd-skeleton sd-skeleton--badge"></span></td>
                                    <td><span class="sd-skeleton sd-skeleton--text"></span></td>
                                </tr>
                            </template>
                        </template>
                        <template x-for="session in editionSessions" :key="session.sessionId || session.id">
                            <tr :class="{'sd-table__row--today': session.isToday, 'sd-table__row--past': session.isPast}"
                                @click="openEdition(session.edition_id)">
                                <td x-text="session.edition_title"></td>
                                <td x-text="session.session_title || '—'"></td>
                                <td x-text="formatDate(session.date)"></td>
                                <td x-text="session.time"></td>
                                <td x-text="session.venue || '—'"></td>
                                <td><span class="sd-capacity" x-text="session.registered + '/' + (session.capacity || '∞')"></span></td>
                                <td><span class="sd-badge" :class="'sd-badge--' + session.status" x-text="session.status_label"></span></td>
                                <td>
                                    <a :href="'<?php echo esc_url($admin_url); ?>post.php?post=' + session.edition_id + '&action=edit'"
                                       class="sd-btn sd-btn--text"
                                       @click.stop
                                       title="Bewerken">✎</a>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
                <template x-if="editionSessions.length === 0 && !loading">
                    <div class="sd-empty">
                        <span class="sd-empty__icon">📅</span>
                        <p class="sd-empty__text">Geen edities gevonden</p>
                        <p class="sd-empty__hint">Probeer een ander filter of bereik.</p>
                    </div>
                </template>
            </div>

            <!-- Pagination -->
            <div class="sd-pagination" x-show="editionPagination.totalPages > 1">
                <button class="sd-btn sd-btn--ghost"
                        :disabled="editionPagination.page <= 1"
                        @click="editionPagination.page--; loadEditions()">← Vorige</button>
                <span class="sd-pagination__info" x-text="'Pagina ' + editionPagination.page + ' van ' + editionPagination.totalPages"></span>
                <button class="sd-btn sd-btn--ghost"
                        :disabled="editionPagination.page >= editionPagination.totalPages"
                        @click="editionPagination.page++; loadEditions()">Volgende →</button>
            </div>

            <!-- Edition slide-over -->
            <div class="sd-slideout" x-show="slideoverOpen && selectedEdition" x-transition:enter="sd-slideout--enter" x-transition:leave="sd-slideout--leave">
                <div class="sd-slideout__overlay" @click="closeSlideOver()"></div>
                <div class="sd-slideout__panel">
                    <div class="sd-slideout__header">
                        <h3 x-text="selectedEdition?.title"></h3>
                        <div class="sd-slideout__header-actions">
                            <div class="sd-kebab" @click.outside="kebabOpen = null" x-show="selectedEdition?.id">
                                <button class="sd-kebab__trigger"
                                        @click="kebabOpen = kebabOpen === 'edition' ? null : 'edition'"
                                        :aria-expanded="kebabOpen === 'edition' ? 'true' : 'false'"
                                        aria-label="Meer acties">⋮</button>
                                <div class="sd-kebab__menu" x-show="kebabOpen === 'edition'" x-cloak>
                                    <a :href="selectedEdition?.editUrl || '#'" class="sd-kebab__item" target="_blank" @click="kebabOpen = null">Bewerk in WP →</a>
                                    <div class="sd-kebab__divider"></div>
                                    <a :href="editionExportUrl('excel')" class="sd-kebab__item" target="_blank" @click="kebabOpen = null">Exporteer studenten (Excel)</a>
                                    <a :href="editionExportUrl('attendance')" class="sd-kebab__item" target="_blank" @click="kebabOpen = null">Exporteer aanwezigheid</a>
                                    <a :href="editionExportUrl('namecards')" class="sd-kebab__item" target="_blank" @click="kebabOpen = null">Naamkaartjes (PDF)</a>
                                    <div class="sd-kebab__divider"></div>
                                    <a :href="selectedEdition?.editUrl || '#'" class="sd-kebab__item" target="_blank" @click="kebabOpen = null">Dupliceer / Annuleer in WP →</a>
                                </div>
                            </div>

                            <button @click="closeSlideOver()" class="sd-slideout__close">×</button>
                        </div>
                    </div>
                    <!-- Tabs -->
                    <div class="sd-slideout__tabs">
                        <button class="sd-slideout__tab" :class="{'active': editionTab === 'students'}" @click="editionTab = 'students'">Studenten</button>
                        <button class="sd-slideout__tab" :class="{'active': editionTab === 'attendance'}" @click="editionTab = 'attendance'">Aanwezigheid</button>
                        <button class="sd-slideout__tab" :class="{'active': editionTab === 'info'}" @click="editionTab = 'info'">Info</button>
                    </div>
                    <!-- Tab content -->
                    <div class="sd-slideout__body">

                        <!-- Students tab -->
                        <div x-show="editionTab === 'students'">
                            <template x-for="reg in editionRegistrations" :key="reg.id">
                                <div class="sd-student-row">
                                    <div class="sd-avatar" x-text="(reg.name || '?')[0].toUpperCase()"></div>
                                    <div>
                                        <div class="sd-student-row__name" x-text="reg.name"></div>
                                        <div class="sd-student-row__email" x-text="reg.email"></div>
                                    </div>
                                    <span class="sd-badge" :class="'sd-badge--' + reg.status" x-text="reg.status_label"></span>
                                </div>
                            </template>
                            <template x-if="editionRegistrations.length === 0">
                                <div class="sd-empty">
                                    <span class="sd-empty__icon">👤</span>
                                    <p class="sd-empty__text">Nog geen inschrijvingen</p>
                                    <p class="sd-empty__hint">Zodra iemand zich inschrijft verschijnt die hier.</p>
                                </div>
                            </template>
                        </div>

                        <!-- Attendance tab -->
                        <div x-show="editionTab === 'attendance'">
                            <table class="sd-attendance-grid">
                                <thead>
                                    <tr>
                                        <th>Deelnemer</th>
                                        <template x-for="session in editionSessionList" :key="session.id">
                                            <th class="sd-attendance-grid__session" x-text="formatShortDate(session.date)"></th>
                                        </template>
                                    </tr>
                                </thead>
                                <tbody>
                                    <template x-for="reg in editionRegistrations" :key="reg.id">
                                        <tr>
                                            <td x-text="reg.name"></td>
                                            <template x-for="session in editionSessionList" :key="session.id">
                                                <td>
                                                    <button
                                                        class="sd-attendance-cell"
                                                        :class="'sd-attendance-cell--' + (reg.attendance?.[session.id] || 'none')"
                                                        @click="config.canManage && !session.isFuture && toggleAttendance(session.id, reg.user_id, reg.attendance?.[session.id])"
                                                        :disabled="!config.canManage || session.isFuture"
                                                        x-text="attendanceLabel(reg.attendance?.[session.id])">
                                                    </button>
                                                </td>
                                            </template>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>

                        <!-- Info tab -->
                        <div x-show="editionTab === 'info'">
                            <dl class="sd-detail-list">
                                <dt>Status</dt>
                                <dd><span class="sd-badge" :class="'sd-badge--' + selectedEdition?.status" x-text="selectedEdition?.status_label"></span></dd>
                                <dt>Periode</dt>
                                <dd x-text="selectedEdition?.period"></dd>
                                <dt>Locatie</dt>
                                <dd x-text="selectedEdition?.venue || '—'"></dd>
                                <dt>Prijs</dt>
                                <dd x-text="formatCurrency(selectedEdition?.price)"></dd>
                                <dt>Capaciteit</dt>
                                <dd x-text="(selectedEdition?.registered || 0) + '/' + (selectedEdition?.capacity || '∞')"></dd>
                            </dl>
                        </div>

                    </div><!-- /.sd-slideout__body -->
                </div><!-- /.sd-slideout__panel -->
            </div><!-- /.sd-slideout -->

        </div><!-- /view: edities -->

        <!-- ========================================================
             VIEW: OFFERTES
             ======================================================== -->
        <div x-show="view === 'offertes'">

            <!-- Filters bar -->
            <div class="sd-filters">
                <input type="text"
                       class="sd-input"
                       placeholder="Zoek offerte…"
                       x-model="quoteFilters.search"
                       @input.debounce.300ms="loadQuotes()">
                <select class="sd-select" x-model="quoteFilters.status" @change="loadQuotes()">
                    <option value="">Alle statussen</option>
                    <option value="draft">Concept</option>
                    <option value="sent">Verzonden</option>
                    <option value="exported">Geëxporteerd</option>
                    <option value="cancelled">Geannuleerd</option>
                </select>
                <select class="sd-select" x-model="quoteFilters.edition_id" @change="loadQuotes()">
                    <option value="">Alle edities</option>
                    <template x-for="ed in editionOptions" :key="ed.id">
                        <option :value="ed.id" x-text="ed.title"></option>
                    </template>
                </select>
                <button class="sd-btn sd-btn--ghost" @click="resetQuoteFilters()">Reset</button>
            </div>

            <!-- Error state -->
            <template x-if="errors.offertes">
                <div class="sd-error">
                    <span class="sd-error__icon">!</span>
                    <p class="sd-error__title" x-text="errors.offertes"></p>
                    <button class="sd-btn sd-btn--ghost sd-btn--sm" @click="loadQuotes()">Opnieuw proberen</button>
                </div>
            </template>

            <!-- Quotes table -->
            <div class="sd-card" x-show="!errors.offertes">
                <table class="sd-table">
                    <thead>
                        <tr>
                            <th>Offerte #</th>
                            <th>Klant</th>
                            <th>E-mail</th>
                            <th>Editie</th>
                            <th>Datum</th>
                            <th>Bedrag</th>
                            <th>Status</th>
                            <th>Acties</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="quote in quotes" :key="quote.id">
                            <tr @click="openQuote(quote.id)">
                                <td x-text="quote.number"></td>
                                <td x-text="quote.client_name"></td>
                                <td x-text="quote.client_email"></td>
                                <td x-text="quote.edition_title || '—'"></td>
                                <td x-text="formatDate(quote.date)"></td>
                                <td x-text="formatCurrency(quote.total)"></td>
                                <td><span class="sd-badge" :class="'sd-badge--' + quote.status" x-text="quote.status_label"></span></td>
                                <td @click.stop>
                                    <a :href="'<?php echo esc_url($admin_url); ?>post.php?post=' + quote.id + '&action=edit'"
                                       class="sd-btn sd-btn--text"
                                       title="Bewerken">✎</a>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
                <template x-if="quotes.length === 0 && !loading">
                    <div class="sd-empty">
                        <span class="sd-empty__icon">€</span>
                        <p class="sd-empty__text">Geen offertes gevonden</p>
                        <p class="sd-empty__hint">Probeer een ander filter of zoekterm.</p>
                    </div>
                </template>
            </div>

            <!-- Pagination -->
            <div class="sd-pagination" x-show="quotePagination.totalPages > 1">
                <button class="sd-btn sd-btn--ghost"
                        :disabled="quotePagination.page <= 1"
                        @click="quotePagination.page--; loadQuotes()">← Vorige</button>
                <span class="sd-pagination__info" x-text="'Pagina ' + quotePagination.page + ' van ' + quotePagination.totalPages"></span>
                <button class="sd-btn sd-btn--ghost"
                        :disabled="quotePagination.page >= quotePagination.totalPages"
                        @click="quotePagination.page++; loadQuotes()">Volgende →</button>
            </div>

            <!-- Quote slide-over -->
            <div class="sd-slideout" x-show="slideoverOpen && selectedQuote" x-transition:enter="sd-slideout--enter" x-transition:leave="sd-slideout--leave">
                <div class="sd-slideout__overlay" @click="closeSlideOver()"></div>
                <div class="sd-slideout__panel">
                    <div class="sd-slideout__header">
                        <h3>Offerte <span x-text="selectedQuote?.number"></span></h3>
                        <div class="sd-slideout__header-actions">
                            <div class="sd-kebab" @click.outside="kebabOpen = null" x-show="selectedQuote?.id">
                                <button class="sd-kebab__trigger"
                                        @click="kebabOpen = kebabOpen === 'quote' ? null : 'quote'"
                                        :aria-expanded="kebabOpen === 'quote' ? 'true' : 'false'"
                                        aria-label="Meer acties">⋮</button>
                                <div class="sd-kebab__menu" x-show="kebabOpen === 'quote'" x-cloak>
                                    <a :href="selectedQuote?.editUrl || '#'" class="sd-kebab__item" target="_blank" @click="kebabOpen = null">Bewerk in WP →</a>
                                    <div class="sd-kebab__divider"></div>
                                    <a :href="selectedQuote?.editUrl || '#'" class="sd-kebab__item" target="_blank" @click="kebabOpen = null">PDF tonen / verzenden →</a>
                                    <a :href="selectedQuote?.editUrl || '#'" class="sd-kebab__item" target="_blank" @click="kebabOpen = null">Markeer geëxporteerd →</a>
                                    <div class="sd-kebab__divider" x-show="config.canManage && selectedQuote?.status !== 'cancelled'"></div>
                                    <a :href="selectedQuote?.editUrl || '#'"
                                       class="sd-kebab__item sd-kebab__item--danger"
                                       target="_blank"
                                       @click="kebabOpen = null"
                                       x-show="config.canManage && selectedQuote?.status !== 'cancelled'">Annuleer offerte →</a>
                                </div>
                            </div>

                            <button @click="closeSlideOver()" class="sd-slideout__close">×</button>
                        </div>
                    </div>
                    <!-- Tabs -->
                    <div class="sd-slideout__tabs">
                        <button class="sd-slideout__tab" :class="{'active': quoteTab === 'details'}" @click="quoteTab = 'details'">Details</button>
                        <button class="sd-slideout__tab" :class="{'active': quoteTab === 'items'}" @click="quoteTab = 'items'">Regels</button>
                    </div>
                    <!-- Tab content -->
                    <div class="sd-slideout__body">

                        <!-- Details tab -->
                        <div x-show="quoteTab === 'details'">
                            <dl class="sd-detail-list">
                                <dt>Status</dt>
                                <dd><span class="sd-badge" :class="'sd-badge--' + selectedQuote?.status" x-text="selectedQuote?.status_label"></span></dd>
                                <dt>Klant</dt>
                                <dd x-text="selectedQuote?.client_name"></dd>
                                <dt>E-mail</dt>
                                <dd x-text="selectedQuote?.client_email"></dd>
                                <dt>Editie</dt>
                                <dd x-text="selectedQuote?.edition_title || '—'"></dd>
                                <dt>Datum</dt>
                                <dd x-text="formatDate(selectedQuote?.date)"></dd>
                                <dt>Subtotaal</dt>
                                <dd x-text="formatCurrency(selectedQuote?.subtotal)"></dd>
                                <dt>BTW</dt>
                                <dd x-text="formatCurrency(selectedQuote?.tax)"></dd>
                                <dt>Totaal</dt>
                                <dd x-text="formatCurrency(selectedQuote?.total)"></dd>
                            </dl>
                        </div>

                        <!-- Items tab -->
                        <div x-show="quoteTab === 'items'">
                            <table class="sd-table">
                                <thead>
                                    <tr>
                                        <th>Omschrijving</th>
                                        <th>Aantal</th>
                                        <th>Prijs</th>
                                        <th>Totaal</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <template x-for="item in selectedQuote?.items || []" :key="item.id">
                                        <tr>
                                            <td x-text="item.description"></td>
                                            <td x-text="item.quantity"></td>
                                            <td x-text="formatCurrency(item.price)"></td>
                                            <td x-text="formatCurrency(item.total)"></td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>

                    </div><!-- /.sd-slideout__body -->
                </div><!-- /.sd-slideout__panel -->
            </div><!-- /.sd-slideout -->

        </div><!-- /view: offertes -->

        <!-- ========================================================
             VIEW: TRAJECTEN
             ======================================================== -->
        <div x-show="view === 'trajecten'">

            <!-- Filters bar -->
            <div class="sd-filters">
                <input type="text"
                       class="sd-input"
                       placeholder="Zoek traject…"
                       x-model="trajectoryFilters.search"
                       @input.debounce.300ms="loadTrajectories()">
                <select class="sd-select" x-model="trajectoryFilters.status" @change="loadTrajectories()">
                    <option value="">Alle statussen</option>
                    <option value="open">Open</option>
                    <option value="closed">Gesloten</option>
                    <option value="full">Volzet</option>
                    <option value="draft">Concept</option>
                    <option value="archived">Gearchiveerd</option>
                </select>
                <button class="sd-btn sd-btn--ghost" @click="resetTrajectoryFilters()">Reset</button>
            </div>

            <!-- Error state -->
            <template x-if="errors.trajecten">
                <div class="sd-error">
                    <span class="sd-error__icon">!</span>
                    <p class="sd-error__title" x-text="errors.trajecten"></p>
                    <button class="sd-btn sd-btn--ghost sd-btn--sm" @click="loadTrajectories()">Opnieuw proberen</button>
                </div>
            </template>

            <!-- Trajectory table -->
            <div class="sd-card" x-show="!errors.trajecten">
                <table class="sd-table">
                    <thead>
                        <tr>
                            <th>Traject</th>
                            <th>Modus</th>
                            <th>Cursussen</th>
                            <th>Ingeschreven</th>
                            <th>Prijs</th>
                            <th>Deadline</th>
                            <th>Status</th>
                            <th>Acties</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="traj in trajectories" :key="traj.id">
                            <tr @click="openTrajectory(traj.id)">
                                <td x-text="traj.title"></td>
                                <td x-text="traj.modeLabel || traj.mode || '—'"></td>
                                <td x-text="traj.course_count"></td>
                                <td x-text="traj.registered"></td>
                                <td x-text="formatCurrency(traj.price)"></td>
                                <td x-text="traj.deadline ? formatDate(traj.deadline) : '—'"></td>
                                <td><span class="sd-badge" :class="'sd-badge--' + traj.status" x-text="traj.status_label"></span></td>
                                <td @click.stop>
                                    <a :href="'<?php echo esc_url($admin_url); ?>post.php?post=' + traj.id + '&action=edit'"
                                       class="sd-btn sd-btn--text"
                                       title="Bewerken">✎</a>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
                <template x-if="trajectories.length === 0 && !loading">
                    <div class="sd-empty">
                        <span class="sd-empty__icon">🗂</span>
                        <p class="sd-empty__text">Geen trajecten gevonden</p>
                        <p class="sd-empty__hint">Probeer een ander filter of maak een nieuw traject aan.</p>
                    </div>
                </template>
            </div>

            <!-- Pagination -->
            <div class="sd-pagination" x-show="trajectoryPagination.totalPages > 1">
                <button class="sd-btn sd-btn--ghost"
                        :disabled="trajectoryPagination.page <= 1"
                        @click="trajectoryPagination.page--; loadTrajectories()">← Vorige</button>
                <span class="sd-pagination__info" x-text="'Pagina ' + trajectoryPagination.page + ' van ' + trajectoryPagination.totalPages"></span>
                <button class="sd-btn sd-btn--ghost"
                        :disabled="trajectoryPagination.page >= trajectoryPagination.totalPages"
                        @click="trajectoryPagination.page++; loadTrajectories()">Volgende →</button>
            </div>

            <!-- Trajectory slide-over -->
            <div class="sd-slideout" x-show="slideoverOpen && selectedTrajectory" x-transition:enter="sd-slideout--enter" x-transition:leave="sd-slideout--leave">
                <div class="sd-slideout__overlay" @click="closeSlideOver()"></div>
                <div class="sd-slideout__panel">
                    <div class="sd-slideout__header">
                        <h3 x-text="selectedTrajectory?.title"></h3>
                        <div class="sd-slideout__header-actions">
                            <div class="sd-kebab" @click.outside="kebabOpen = null" x-show="selectedTrajectory?.id">
                                <button class="sd-kebab__trigger"
                                        @click="kebabOpen = kebabOpen === 'trajectory' ? null : 'trajectory'"
                                        :aria-expanded="kebabOpen === 'trajectory' ? 'true' : 'false'"
                                        aria-label="Meer acties">⋮</button>
                                <div class="sd-kebab__menu" x-show="kebabOpen === 'trajectory'" x-cloak>
                                    <a :href="selectedTrajectory?.editUrl || '#'" class="sd-kebab__item" target="_blank" @click="kebabOpen = null">Bewerk in WP →</a>
                                    <div class="sd-kebab__divider"></div>
                                    <a :href="selectedTrajectory?.editUrl || '#'" class="sd-kebab__item sd-kebab__item--danger" target="_blank" @click="kebabOpen = null">Annuleer in WP →</a>
                                </div>
                            </div>

                            <button @click="closeSlideOver()" class="sd-slideout__close">×</button>
                        </div>
                    </div>
                    <!-- Tabs -->
                    <div class="sd-slideout__tabs">
                        <button class="sd-slideout__tab" :class="{'active': trajectoryTab === 'details'}" @click="trajectoryTab = 'details'">Details</button>
                        <button class="sd-slideout__tab" :class="{'active': trajectoryTab === 'courses'}" @click="trajectoryTab = 'courses'">Cursussen</button>
                        <button class="sd-slideout__tab" :class="{'active': trajectoryTab === 'students'}" @click="trajectoryTab = 'students'">Studenten</button>
                    </div>
                    <!-- Tab content -->
                    <div class="sd-slideout__body">

                        <!-- Details tab -->
                        <div x-show="trajectoryTab === 'details'">
                            <dl class="sd-detail-list">
                                <dt>Status</dt>
                                <dd><span class="sd-badge" :class="'sd-badge--' + selectedTrajectory?.status" x-text="selectedTrajectory?.status_label"></span></dd>
                                <dt>Modus</dt>
                                <dd x-text="selectedTrajectory?.modeLabel || selectedTrajectory?.mode || '—'"></dd>
                                <dt>Prijs</dt>
                                <dd x-text="formatCurrency(selectedTrajectory?.price)"></dd>
                                <dt>Deadline</dt>
                                <dd x-text="selectedTrajectory?.deadline ? formatDate(selectedTrajectory.deadline) : '—'"></dd>
                                <dt>Ingeschreven</dt>
                                <dd x-text="selectedTrajectory?.registered"></dd>
                            </dl>
                        </div>

                        <!-- Courses tab -->
                        <div x-show="trajectoryTab === 'courses'">
                            <template x-for="course in trajectoryCourses" :key="course.id">
                                <div class="sd-student-row">
                                    <div>
                                        <div class="sd-student-row__name" x-text="course.title"></div>
                                        <div class="sd-student-row__email" x-text="course.edition_count + ' editie(s)'"></div>
                                    </div>
                                </div>
                            </template>
                            <template x-if="trajectoryCourses.length === 0">
                                <div class="sd-empty">
                                    <span class="sd-empty__icon">📚</span>
                                    <p class="sd-empty__text">Geen cursussen gekoppeld</p>
                                    <p class="sd-empty__hint">Voeg cursussen toe aan dit traject in de WordPress-editor.</p>
                                </div>
                            </template>
                        </div>

                        <!-- Students tab -->
                        <div x-show="trajectoryTab === 'students'">
                            <template x-for="reg in trajectoryRegistrations" :key="reg.id">
                                <div class="sd-student-row">
                                    <div class="sd-avatar" x-text="(reg.name || '?')[0].toUpperCase()"></div>
                                    <div>
                                        <div class="sd-student-row__name" x-text="reg.name"></div>
                                        <div class="sd-student-row__email" x-text="reg.email"></div>
                                    </div>
                                    <span class="sd-badge" :class="'sd-badge--' + reg.status" x-text="reg.status_label"></span>
                                </div>
                            </template>
                            <template x-if="trajectoryRegistrations.length === 0">
                                <div class="sd-empty">
                                    <span class="sd-empty__icon">👤</span>
                                    <p class="sd-empty__text">Nog geen inschrijvingen</p>
                                </div>
                            </template>
                        </div>

                    </div><!-- /.sd-slideout__body -->
                </div><!-- /.sd-slideout__panel -->
            </div><!-- /.sd-slideout -->

        </div><!-- /view: trajecten -->

        <!-- ========================================================
             VIEW: GEBRUIKERS
             ======================================================== -->
        <div x-show="view === 'gebruikers'">

            <!-- Search bar -->
            <div class="sd-filters">
                <input type="text"
                       class="sd-input sd-input--lg"
                       placeholder="Zoek gebruiker op naam, e-mail of organisatie…"
                       @input.debounce.300ms="searchUsers($event.target.value)">
            </div>

            <!-- Search results (mini cards) -->
            <div class="sd-user-results" x-show="userSearchResults.length > 0 && !selectedUser">
                <template x-for="user in userSearchResults" :key="user.id">
                    <div class="sd-user-results__card" @click="selectUser(user)">
                        <div class="sd-avatar" x-text="(user.name || '?')[0].toUpperCase()"></div>
                        <div>
                            <div class="sd-user-results__name" x-text="user.name"></div>
                            <div class="sd-user-results__email" x-text="user.email"></div>
                            <div class="sd-user-results__org" x-text="user.organisation || ''" x-show="user.organisation"></div>
                        </div>
                    </div>
                </template>
            </div>

            <!-- User detail (two-column) -->
            <div class="sd-layout" x-show="selectedUser">

                <div class="sd-layout__primary">

                    <!-- User header card (click-to-expand profile details) -->
                    <div class="sd-card sd-user-card" :class="{ 'sd-user-card--open': userProfileOpen }">
                        <div class="sd-card__body">
                            <div class="sd-user-header sd-user-header--toggle"
                                 @click="toggleUserProfile()"
                                 role="button"
                                 :aria-expanded="userProfileOpen ? 'true' : 'false'">
                                <div class="sd-avatar sd-avatar--lg" x-text="(selectedUser?.name || '?')[0].toUpperCase()"></div>
                                <div class="sd-user-header__info">
                                    <h3 x-text="selectedUser?.name"></h3>
                                    <div x-text="selectedUser?.email"></div>
                                    <div x-text="selectedUser?.organisation || ''" x-show="selectedUser?.organisation"></div>
                                </div>
                                <div class="sd-user-header__actions" @click.stop>
                                    <button class="sd-btn sd-btn--ghost" @click="impersonateUser(selectedUser?.id)" x-show="config.canManage && !selectedUser?.isAnonymised">Bekijk als gebruiker</button>
                                    <a :href="selectedUser?.anonymiseUrl || '#'"
                                       class="sd-btn sd-btn--ghost"
                                       x-show="config.canManage && selectedUser?.id && !selectedUser?.isAnonymised && selectedUser?.anonymiseUrl"
                                       @click="if (!confirmAnonymise()) { $event.preventDefault(); }">Anonimiseer</a>
                                    <span class="sd-tag sd-tag--muted" x-show="selectedUser?.isAnonymised" x-text="selectedUser?.anonymisedLabel"></span>
                                    <button class="sd-btn sd-btn--text" @click="closeUserDetail()" x-text="userDetailReturnTo === 'dashboard' ? '← Terug naar dashboard' : '← Terug'"></button>
                                </div>
                                <span class="sd-user-header__caret" aria-hidden="true">▾</span>
                            </div>

                            <!-- Collapsible profile sections -->
                            <div class="sd-user-profile" x-show="userProfileOpen" x-cloak>

                                <!-- Persoonlijke gegevens -->
                                <div class="sd-profile-section">
                                    <div class="sd-profile-section__header">
                                        <h4 class="sd-profile-section__title">Persoonsgegevens</h4>
                                        <div class="sd-profile-section__actions" x-show="config.canManage && !selectedUser?.isAnonymised">
                                            <template x-if="profileEdit.personal">
                                                <span>
                                                    <button class="sd-btn sd-btn--primary sd-btn--sm" @click="saveProfile('personal')" :disabled="profileSaving">
                                                        <span x-show="!profileSaving">Opslaan</span>
                                                        <span x-show="profileSaving">Bezig…</span>
                                                    </button>
                                                    <button class="sd-btn sd-btn--ghost sd-btn--sm" @click="cancelProfileEdit('personal')" :disabled="profileSaving">Annuleer</button>
                                                </span>
                                            </template>
                                            <template x-if="!profileEdit.personal">
                                                <button class="sd-btn sd-btn--ghost sd-btn--sm" @click="startProfileEdit('personal')">Bewerken</button>
                                            </template>
                                        </div>
                                    </div>

                                    <!-- Read mode -->
                                    <dl class="sd-profile-grid" x-show="!profileEdit.personal">
                                        <div><dt>Voornaam</dt><dd x-text="selectedUser?.first_name || '—'"></dd></div>
                                        <div><dt>Achternaam</dt><dd x-text="selectedUser?.last_name || '—'"></dd></div>
                                        <div><dt>E-mail</dt><dd x-text="selectedUser?.email || '—'"></dd></div>
                                        <div><dt>Telefoon</dt>
                                            <dd>
                                                <span x-text="selectedUser?.phone || '—'"></span>
                                            </dd>
                                        </div>
                                        <div><dt>Organisatie</dt><dd x-text="selectedUser?.organisation || '—'"></dd></div>
                                        <div><dt>Afdeling</dt><dd x-text="selectedUser?.department || '—'"></dd></div>
                                        <div>
                                            <dt>Rijksregisternummer</dt>
                                            <dd>
                                                <span x-show="!selectedUser?.national_id_present">—</span>
                                                <span x-show="selectedUser?.national_id_present" class="sd-sensitive">
                                                    <span x-text="revealed.national_id || selectedUser?.national_id"></span>
                                                    <button class="sd-btn sd-btn--text sd-btn--xs"
                                                            @click="revealField('national_id')"
                                                            x-show="!revealed.national_id">Toon</button>
                                                </span>
                                            </dd>
                                        </div>
                                        <div>
                                            <dt>Geboortedatum</dt>
                                            <dd>
                                                <span x-show="!selectedUser?.date_of_birth_present">—</span>
                                                <span x-show="selectedUser?.date_of_birth_present" class="sd-sensitive">
                                                    <span x-text="revealed.date_of_birth || selectedUser?.date_of_birth"></span>
                                                    <button class="sd-btn sd-btn--text sd-btn--xs"
                                                            @click="revealField('date_of_birth')"
                                                            x-show="!revealed.date_of_birth">Toon</button>
                                                </span>
                                            </dd>
                                        </div>
                                        <div>
                                            <dt>RIZIV-nummer</dt>
                                            <dd>
                                                <span x-show="!selectedUser?.professional_license_number_present">—</span>
                                                <span x-show="selectedUser?.professional_license_number_present" class="sd-sensitive">
                                                    <span x-text="revealed.professional_license_number || selectedUser?.professional_license_number"></span>
                                                    <button class="sd-btn sd-btn--text sd-btn--xs"
                                                            @click="revealField('professional_license_number')"
                                                            x-show="!revealed.professional_license_number">Toon</button>
                                                </span>
                                            </dd>
                                        </div>
                                    </dl>

                                    <!-- Edit mode -->
                                    <div class="sd-profile-grid sd-profile-grid--edit" x-show="profileEdit.personal">
                                        <div><label>Voornaam</label><input type="text" class="sd-input" x-model="profileDraft.first_name"></div>
                                        <div><label>Achternaam</label><input type="text" class="sd-input" x-model="profileDraft.last_name"></div>
                                        <div><label>E-mail</label><input type="email" class="sd-input" x-model="profileDraft.email"></div>
                                        <div><label>Telefoon</label><input type="text" class="sd-input" x-model="profileDraft.phone"></div>
                                        <div><label>Organisatie</label><input type="text" class="sd-input" x-model="profileDraft.organisation"></div>
                                        <div><label>Afdeling</label><input type="text" class="sd-input" x-model="profileDraft.department"></div>
                                        <div><label>Rijksregisternummer</label><input type="text" class="sd-input" x-model="profileDraft.national_id" :placeholder="selectedUser?.national_id_present ? '(ongewijzigd laten? leeg laten)' : ''"></div>
                                        <div><label>Geboortedatum</label><input type="date" class="sd-input" x-model="profileDraft.date_of_birth"></div>
                                        <div><label>RIZIV-nummer</label><input type="text" class="sd-input" x-model="profileDraft.professional_license_number"></div>
                                    </div>
                                </div>

                                <!-- Facturatie -->
                                <div class="sd-profile-section">
                                    <div class="sd-profile-section__header">
                                        <h4 class="sd-profile-section__title">Facturatiegegevens</h4>
                                        <div class="sd-profile-section__actions" x-show="config.canManage && !selectedUser?.isAnonymised">
                                            <template x-if="profileEdit.billing">
                                                <span>
                                                    <button class="sd-btn sd-btn--primary sd-btn--sm" @click="saveProfile('billing')" :disabled="profileSaving">
                                                        <span x-show="!profileSaving">Opslaan</span>
                                                        <span x-show="profileSaving">Bezig…</span>
                                                    </button>
                                                    <button class="sd-btn sd-btn--ghost sd-btn--sm" @click="cancelProfileEdit('billing')" :disabled="profileSaving">Annuleer</button>
                                                </span>
                                            </template>
                                            <template x-if="!profileEdit.billing">
                                                <button class="sd-btn sd-btn--ghost sd-btn--sm" @click="startProfileEdit('billing')">Bewerken</button>
                                            </template>
                                        </div>
                                    </div>

                                    <dl class="sd-profile-grid" x-show="!profileEdit.billing">
                                        <div><dt>Bedrijfsnaam</dt><dd x-text="selectedUser?.billing_company || '—'"></dd></div>
                                        <div><dt>BTW-nummer</dt><dd x-text="selectedUser?.billing_vat || '—'"></dd></div>
                                        <div><dt>Adres</dt><dd x-text="selectedUser?.billing_address_1 || '—'"></dd></div>
                                        <div><dt>Postcode</dt><dd x-text="selectedUser?.billing_postcode || '—'"></dd></div>
                                        <div><dt>Stad</dt><dd x-text="selectedUser?.billing_city || '—'"></dd></div>
                                        <div><dt>Facturatie-e-mail</dt><dd x-text="selectedUser?.invoice_email || '—'"></dd></div>
                                        <div><dt>GLN-nummer</dt><dd x-text="selectedUser?.gln_number || '—'"></dd></div>
                                    </dl>

                                    <div class="sd-profile-grid sd-profile-grid--edit" x-show="profileEdit.billing">
                                        <div><label>Bedrijfsnaam</label><input type="text" class="sd-input" x-model="profileDraft.company"></div>
                                        <div><label>BTW-nummer</label><input type="text" class="sd-input" x-model="profileDraft.vat_number"></div>
                                        <div><label>Adres</label><input type="text" class="sd-input" x-model="profileDraft.address"></div>
                                        <div><label>Postcode</label><input type="text" class="sd-input" x-model="profileDraft.postal_code"></div>
                                        <div><label>Stad</label><input type="text" class="sd-input" x-model="profileDraft.city"></div>
                                        <div><label>Facturatie-e-mail</label><input type="email" class="sd-input" x-model="profileDraft.invoice_email"></div>
                                        <div><label>GLN-nummer</label><input type="text" class="sd-input" x-model="profileDraft.gln_number"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- User registrations — pure enrollment info -->
                    <div class="sd-card">
                        <div class="sd-card__header">
                            <h3 class="sd-card__title">Inschrijvingen</h3>
                        </div>
                        <table class="sd-table">
                            <thead>
                                <tr>
                                    <th>Editie</th>
                                    <th>Inschrijfdatum</th>
                                    <th>Status</th>
                                    <th>Pad</th>
                                </tr>
                            </thead>
                            <tbody>
                                <template x-for="reg in userRegistrations" :key="reg.id">
                                    <tr>
                                        <td><a :href="'<?php echo esc_url($admin_url); ?>post.php?post=' + reg.edition_id + '&action=edit'" x-text="reg.edition_title"></a></td>
                                        <td x-text="formatDate(reg.date)"></td>
                                        <td><span class="sd-badge" :class="'sd-badge--' + reg.status" x-text="reg.status_label"></span></td>
                                        <td x-text="enrollmentPathLabel(reg.enrollment_path)"></td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                        <template x-if="userRegistrations.length === 0">
                            <div class="sd-empty">
                                <span class="sd-empty__icon">📝</span>
                                <p class="sd-empty__text">Nog geen inschrijvingen</p>
                                <p class="sd-empty__hint">Deze gebruiker heeft zich nog niet ingeschreven voor een editie.</p>
                            </div>
                        </template>
                    </div>

                    <!-- Attendance + progress (one row per sessioned edition) -->
                    <div class="sd-card">
                        <div class="sd-card__header">
                            <h3 class="sd-card__title">Aanwezigheid</h3>
                        </div>
                        <table class="sd-table">
                            <thead>
                                <tr>
                                    <th>Editie</th>
                                    <th>Aanwezigheid</th>
                                    <th>Uren</th>
                                    <th>Voortgang</th>
                                </tr>
                            </thead>
                            <tbody>
                                <template x-for="reg in sessionedRegistrations" :key="reg.id">
                                    <tr>
                                        <td x-text="reg.edition_title"></td>
                                        <td>
                                            <div class="sd-attendance-cell-stack">
                                                <div class="sd-attendance-cell-stack__count">
                                                    <span x-text="attendancePresent(reg) + '/' + attendanceTotal(reg) + ' sessies'"></span>
                                                    <span class="sd-attendance-pct" :class="attendancePctClass(reg)" x-text="attendancePct(reg.attendance) + '%'"></span>
                                                </div>
                                                <div class="sd-attendance-cell-stack__bar">
                                                    <div class="sd-attendance-cell-stack__bar-fill"
                                                         :class="attendanceBarClass(reg.attendance)"
                                                         :style="'width:' + attendancePct(reg.attendance) + '%'"></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td x-text="(reg.attendance?.hours ?? 0) + 'u'"></td>
                                        <td x-text="progressLabel(reg)"></td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                        <template x-if="sessionedRegistrations.length === 0">
                            <div class="sd-empty">
                                <span class="sd-empty__icon">⏱</span>
                                <p class="sd-empty__text">Geen edities met sessies</p>
                                <p class="sd-empty__hint">Deze gebruiker heeft geen klassikale of blended edities.</p>
                            </div>
                        </template>
                    </div>

                    <!-- User quotes / invoices -->
                    <div class="sd-card">
                        <div class="sd-card__header">
                            <h3 class="sd-card__title">Offertes</h3>
                        </div>
                        <table class="sd-table">
                            <thead>
                                <tr>
                                    <th>Offerte #</th>
                                    <th>Editie</th>
                                    <th>Bedrag</th>
                                    <th>Status</th>
                                    <th>Verzonden</th>
                                    <th>Betaald</th>
                                </tr>
                            </thead>
                            <tbody>
                                <template x-for="quote in userQuotes" :key="quote.id">
                                    <tr>
                                        <td x-text="quote.number"></td>
                                        <td x-text="quote.edition_title || '—'"></td>
                                        <td x-text="formatCurrency(quote.total)"></td>
                                        <td><span class="sd-badge" :class="'sd-badge--' + quote.status" x-text="quote.status_label"></span></td>
                                        <td x-text="quote.sent_at ? formatShortDate(quote.sent_at) : '—'"></td>
                                        <td x-text="quote.paid_at ? formatShortDate(quote.paid_at) : '—'"></td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                        <template x-if="userQuotes.length === 0">
                            <div class="sd-empty">
                                <span class="sd-empty__icon">€</span>
                                <p class="sd-empty__text">Geen offertes</p>
                                <p class="sd-empty__hint">Voor deze gebruiker is nog geen offerte aangemaakt.</p>
                            </div>
                        </template>
                    </div>

                </div><!-- /.sd-layout__primary -->

                <div class="sd-layout__secondary">

                    <!-- Audit timeline (capped at 30 — link below for full log) -->
                    <div class="sd-card">
                        <div class="sd-card__header">
                            <h3 class="sd-card__title">Audit log</h3>
                        </div>
                        <div class="sd-card__body">
                            <template x-for="entry in userAuditLog" :key="entry.id">
                                <div class="sd-activity-item">
                                    <div class="sd-activity-icon" :class="'sd-activity-icon--' + (entry.type || 'action')" :title="entry.actor_name || ''" x-html="activityIcon(entry.type)"></div>
                                    <div class="sd-activity-item__content">
                                        <template x-if="entry.target_url">
                                            <a class="sd-activity-item__text sd-activity-item__text--link" :href="entry.target_url" x-text="entry.text"></a>
                                        </template>
                                        <template x-if="!entry.target_url">
                                            <span class="sd-activity-item__text" x-text="entry.text"></span>
                                        </template>
                                        <span class="sd-activity-item__time" x-text="formatRelativeTime(entry.timestamp)"></span>
                                    </div>
                                </div>
                            </template>
                            <template x-if="userAuditLog.length === 0">
                                <div class="sd-empty">
                                    <span class="sd-empty__icon">·</span>
                                    <p class="sd-empty__text">Geen audit items</p>
                                </div>
                            </template>
                        </div>
                        <div class="sd-card__footer" x-show="selectedUser?.id">
                            <a :href="'<?php echo esc_url($admin_url); ?>admin.php?page=ntdst-audit-log&actor_id=' + selectedUser.id"
                               class="sd-btn sd-btn--text sd-btn--block">
                                <span x-show="userAuditLogTotal > 30">Toon alle <span x-text="userAuditLogTotal"></span> items in volledige audit log →</span>
                                <span x-show="userAuditLogTotal <= 30">Open in volledige audit log →</span>
                            </a>
                        </div>
                    </div>


                </div><!-- /.sd-layout__secondary -->

            </div><!-- /.sd-layout -->

        </div><!-- /view: gebruikers -->

    </div><!-- /.sd-content -->

    <!-- ============================================================
         TOAST CONTAINER
         ============================================================ -->
    <div class="sd-toast-container" x-show="toast" x-transition>
        <div class="sd-toast" :class="'sd-toast--' + (toast?.type || 'info')">
            <span x-text="toast?.message"></span>
            <button @click="toast = null">×</button>
        </div>
    </div>

</div><!-- /.sd-app -->
