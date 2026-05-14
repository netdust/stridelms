<?php
/**
 * Admin Dashboard Template — Soft Violet
 *
 * Full-screen Alpine.js application for Stride admin.
 * Views: Dashboard, Edities, Offertes, Trajecten, Gebruikers
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
                        @click="switchView('edities')">Edities</button>
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

                    <!-- Action Queue -->
                    <div class="sd-card">
                        <div class="sd-card__header">
                            <h3 class="sd-card__title">Acties nodig</h3>
                        </div>
                        <div class="sd-card__body">
                            <template x-if="actionQueue.length === 0 && !loading">
                                <div class="sd-empty">
                                    <span class="sd-empty__icon">✓</span>
                                    <p class="sd-empty__text">Alles is in orde</p>
                                    <p class="sd-empty__hint">Geen acties nodig op dit moment.</p>
                                </div>
                            </template>
                            <template x-for="item in actionQueue" :key="item.rule + (item.subject_id || '')">
                                <div class="sd-action-item">
                                    <span class="sd-badge--priority" :class="'sd-badge--priority-' + item.priority"></span>
                                    <span class="sd-action-item__text" x-text="item.text"></span>
                                    <a :href="item.url || '#'" class="sd-action-item__link" x-show="item.url">Bekijk →</a>
                                    <button class="sd-action-item__dismiss" @click="dismissAction(item.rule, item.subject_id)" title="Negeren">×</button>
                                </div>
                            </template>
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
                                    <div class="sd-avatar sd-avatar--sm" x-text="(entry.actor_name || '?')[0].toUpperCase()"></div>
                                    <div class="sd-activity-item__content">
                                        <span class="sd-activity-item__text" x-text="entry.text"></span>
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
                        <button @click="closeSlideOver()" class="sd-slideout__close">×</button>
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
                            <a :href="selectedEdition?.editUrl || '#'"
                               class="sd-btn sd-btn--ghost"
                               target="_blank"
                               x-show="selectedEdition?.id">Bewerk in WP →</a>
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
                                    <button class="sd-btn sd-btn--text"
                                            @click="quickSendTarget = quote"
                                            title="Verzenden"
                                            x-show="quote.status === 'draft'">✉</button>
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
                        <button @click="closeSlideOver()" class="sd-slideout__close">×</button>
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
                            <a :href="selectedQuote?.editUrl || '#'"
                               class="sd-btn sd-btn--ghost"
                               target="_blank"
                               x-show="selectedQuote?.id">Bewerk in WP →</a>
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
                        <button @click="closeSlideOver()" class="sd-slideout__close">×</button>
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
                            <a :href="selectedTrajectory?.editUrl || '#'"
                               class="sd-btn sd-btn--ghost"
                               target="_blank"
                               x-show="selectedTrajectory?.id">Bewerk in WP →</a>
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

                    <!-- User header card -->
                    <div class="sd-card">
                        <div class="sd-card__body">
                            <div class="sd-user-header">
                                <div class="sd-avatar sd-avatar--lg" x-text="(selectedUser?.name || '?')[0].toUpperCase()"></div>
                                <div class="sd-user-header__info">
                                    <h3 x-text="selectedUser?.name"></h3>
                                    <div x-text="selectedUser?.email"></div>
                                    <div x-text="selectedUser?.organisation || ''" x-show="selectedUser?.organisation"></div>
                                </div>
                                <div class="sd-user-header__actions">
                                    <button class="sd-btn sd-btn--ghost" @click="impersonateUser(selectedUser?.id)" x-show="config.canManage && !selectedUser?.isAnonymised">Bekijk als gebruiker</button>
                                    <a :href="selectedUser?.id ? '<?php echo esc_url($admin_url); ?>user-edit.php?user_id=' + selectedUser.id : '#'"
                                       class="sd-btn sd-btn--ghost"
                                       target="_blank"
                                       x-show="selectedUser?.id">Bewerk in WP →</a>
                                    <a :href="selectedUser?.anonymiseUrl || '#'"
                                       class="sd-btn sd-btn--ghost"
                                       x-show="config.canManage && selectedUser?.id && !selectedUser?.isAnonymised && selectedUser?.anonymiseUrl"
                                       @click="if (!confirmAnonymise()) { $event.preventDefault(); }">Anonimiseer</a>
                                    <span class="sd-tag sd-tag--muted" x-show="selectedUser?.isAnonymised" x-text="selectedUser?.anonymisedLabel"></span>
                                    <button class="sd-btn sd-btn--text" @click="selectedUser = null">← Terug</button>
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

                    <!-- Audit timeline -->
                    <div class="sd-card">
                        <div class="sd-card__header">
                            <h3 class="sd-card__title">Audit log</h3>
                        </div>
                        <div class="sd-card__body">
                            <template x-for="entry in userAuditLog" :key="entry.id">
                                <div class="sd-activity-item">
                                    <div class="sd-avatar sd-avatar--sm" x-text="(entry.actor || '?')[0].toUpperCase()"></div>
                                    <div class="sd-activity-item__content">
                                        <span class="sd-activity-item__text" x-text="entry.text"></span>
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

    <!-- ============================================================
         QUICK-SEND POPOVER
         ============================================================ -->
    <div class="sd-popover" x-show="quickSendTarget" @click.outside="quickSendTarget = null" x-transition>
        <p>Offerte <span x-text="quickSendTarget?.number"></span> verzenden naar <strong x-text="quickSendTarget?.client_email"></strong>?</p>
        <div class="sd-popover__actions">
            <button class="sd-btn" @click="confirmQuickSend()">Verzenden</button>
            <button class="sd-btn sd-btn--ghost" @click="quickSendTarget = null">Annuleren</button>
        </div>
    </div>

</div><!-- /.sd-app -->
