<?php
/**
 * Trajecten tab (Surface 4 — Task 3.6) — read-only trajectory overview.
 *
 * A server-paged list of trajectories with a search box, a status filter,
 * and an "Actieve trajecten" default-scope toggle, plus a detail slide-over
 * (details / courses / enrolled students) and a "Toon inschrijvingen" jump
 * that opens the Inschrijvingen grid scoped to this trajectory's CHILD
 * edition-rows via the EXISTING A2 trajectory filter (gridFilters.trajectory_id).
 *
 * All list/detail data comes from the already-tested /admin/trajectories(+/{id})
 * endpoints via loadTrajectories()/openTrajectory() (1D plumbing). This partial
 * adds ONLY markup + the scope toggle + the jump — no new JS state, no new
 * endpoint. The scope toggle is a client-side view filter over the loaded page
 * (mirrors the mockup's WS.trajIsActive); the status filter is the server-side
 * refetch control.
 *
 * Presentational only — every dynamic value renders through Alpine x-text
 * (auto-escaped); no raw echo of trajectory/user data.
 *
 * @var string $admin_url  Trailing-slashed admin URL (from the parent template).
 */

if (!defined('ABSPATH')) {
    exit;
}
?>
<div x-show="view === 'trajecten'">

    <!-- Filters bar: search + status (server refetch) + active-scope toggle (client view filter) -->
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

        <!-- Active-default scope toggle (active vs all). Mirrors the §10 editions
             posture: the list lands scoped to actieve trajecten; one click widens
             to all. Client-side view filter over the current page (the list
             endpoint has no scope param — status is the server control). -->
        <button type="button"
                class="sd-btn sd-btn--ghost sd-btn--sm"
                :class="{ 'sd-btn--active': trajectoryScope === 'active' }"
                @click="trajectoryScope = (trajectoryScope === 'active' ? 'all' : 'active')"
                :title="trajectoryScope === 'active' ? 'Toon ook afgesloten trajecten' : 'Beperk tot actieve trajecten'"
                x-text="trajectoryScope === 'active' ? 'Actieve trajecten' : 'Alle trajecten'"></button>

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
                <template x-for="traj in visibleTrajectories" :key="traj.id">
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
        <template x-if="visibleTrajectories.length === 0 && !loading">
            <div class="sd-empty">
                <span class="sd-empty__icon">🗂</span>
                <p class="sd-empty__text">Geen trajecten gevonden</p>
                <p class="sd-empty__hint">Probeer een ander filter of bereik.</p>
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

    <!-- Trajectory slide-over (detail) -->
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

            <!-- Jump to the Inschrijvingen grid scoped to this trajectory's child
                 edition-rows. REUSES the A2 trajectory filter (gridFilters.trajectory_id,
                 serialized at admin-dashboard.js:876) — the same field the ?trajectory=
                 deep-link sets at :855. No new filter path, no bare WHERE trajectory_id. -->
            <div class="sd-slideout__cta" x-show="selectedTrajectory?.id">
                <button type="button"
                        class="sd-btn sd-btn--primary sd-btn--block"
                        @click="showTrajectoryRegistrations(selectedTrajectory.id)">
                    Toon inschrijvingen
                </button>
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
