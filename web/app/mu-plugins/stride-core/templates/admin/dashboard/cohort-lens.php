<?php
/**
 * Cohort-lens body (Phase 2a, Task 2a.9) — the per-session roster surface.
 *
 * Rendered inside the edition slideover (as the "Rooster" tab) AND inside the
 * trajectory roster slideover. It reads ONLY the `cohort` Alpine state, which is
 * populated by openCohort()/openTrajectoryRoster() from the already-tested 2a-A
 * /admin/editions/{id}/roster endpoint. The session/extras filtering is over the
 * LOADED set, client-side (CF3 leak-check — this UI is NEVER reused on the global
 * Inschrijvingen grid). Bulk actions reuse the same ntdst/v1/action POST + per-row
 * report as the grid, scoped server-side by the REQUIRED edition_id/trajectory_id.
 *
 * Presentational only — every dynamic value renders through Alpine x-text
 * (auto-escaped); selections render from rows[].selections (server-resolved via
 * getSelections / INV-6b), NEVER a raw-column decode.
 *
 * @var string $admin_url  Trailing-slashed admin URL (from the parent template).
 */

if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="sd-cohort">

    <!-- Loading -->
    <template x-if="cohort.loading">
        <div class="sd-cohort__loading">Rooster laden…</div>
    </template>

    <!-- Error (mid-flow load failure) -->
    <template x-if="!cohort.loading && cohort.error">
        <div class="sd-error">
            <span class="sd-error__icon">!</span>
            <p class="sd-error__title" x-text="cohort.error"></p>
            <button class="sd-btn sd-btn--ghost sd-btn--sm"
                    x-show="cohort.scope === 'edition'"
                    @click="loadCohortRoster('/admin/editions/' + cohort.scopeId + '/roster')">Opnieuw proberen</button>
        </div>
    </template>

    <template x-if="!cohort.loading && !cohort.error">
        <div>

            <!-- Session picker (edition scope only — a trajectory has no single
                 session axis). "geen sessies" note when the edition is sessionless. -->
            <div class="sd-cohort__sessions" x-show="cohort.scope === 'edition'">
                <div class="sd-cohort__sessions-label">Sessie</div>
                <template x-if="cohort.sessions.length === 0">
                    <p class="sd-cohort__note">Deze editie heeft geen sessies — toont alle inschrijvingen.</p>
                </template>
                <div class="sd-cohort__chips" x-show="cohort.sessions.length > 0">
                    <button type="button"
                            class="sd-chip"
                            :class="{ 'sd-chip--active': cohort.sessionId === 0 }"
                            @click="selectCohortSession(0)">Alle inschrijvingen</button>
                    <template x-for="session in cohort.sessions" :key="session.id">
                        <button type="button"
                                class="sd-chip"
                                :class="{ 'sd-chip--active': cohort.sessionId === session.id }"
                                @click="selectCohortSession(session.id)"
                                x-text="(session.title || formatShortDate(session.date) || ('Sessie ' + session.id))"></button>
                    </template>
                </div>
            </div>

            <!-- Extras filter chips (CF3 — loaded-set ONLY). Built client-side from
                 the loaded rows' extras; never a server param, never on the grid. -->
            <div class="sd-cohort__extras" x-show="cohortExtrasOptions.length > 0">
                <div class="sd-cohort__sessions-label">Extra's</div>
                <div class="sd-cohort__chips">
                    <template x-for="opt in cohortExtrasOptions" :key="opt.token">
                        <button type="button"
                                class="sd-chip sd-chip--extra"
                                :class="{ 'sd-chip--active': cohort.extrasFilter === opt.token }"
                                @click="setCohortExtrasFilter(opt.token)">
                            <span x-text="opt.key + ': ' + opt.value"></span>
                            <span class="sd-chip__count" x-text="opt.count"></span>
                        </button>
                    </template>
                    <button type="button"
                            class="sd-btn sd-btn--text sd-btn--sm"
                            x-show="cohort.extrasFilter"
                            @click="clearCohortExtrasFilter()">Wis filter</button>
                </div>
            </div>

            <!-- Count badge: visible (filtered) van session-scoped total -->
            <div class="sd-cohort__count">
                <b x-text="cohortVisibleCount"></b> van <b x-text="cohortSessionScopedCount"></b>
                <span x-show="cohort.extrasFilter || cohort.sessionId"> &mdash; gefilterd</span>
            </div>

            <!-- Exporter download links (CF6) — edition scope only -->
            <div class="sd-cohort__exports" x-show="cohort.scope === 'edition' && config.canManage">
                <div class="sd-cohort__sessions-label">Exporteren</div>
                <div class="sd-cohort__chips">
                    <template x-for="exp in cohortExporters" :key="exp.type">
                        <a class="sd-btn sd-btn--ghost sd-btn--sm"
                           :href="cohortExportUrl(exp.type)"
                           target="_blank"
                           x-text="exp.label"></a>
                    </template>
                </div>
            </div>

            <!-- Per-session / extras-filtered roster table -->
            <table class="sd-cohort__table">
                <thead>
                    <tr>
                        <th class="sd-cohort__th-check" x-show="config.canManage">
                            <input type="checkbox"
                                   :checked="cohortPageAllSelected"
                                   @change="toggleCohortAll()"
                                   aria-label="Selecteer alle zichtbare">
                        </th>
                        <th>Deelnemer</th>
                        <th>Organisatie</th>
                        <th>Status</th>
                        <th>Aanwezigheid</th>
                        <th x-show="cohortCanMarkAttendance">Markeer</th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="row in cohortVisibleRows" :key="row.registration_id">
                        <tr :class="{ 'is-selected': isCohortSelected(row.registration_id) }">
                            <td x-show="config.canManage">
                                <input type="checkbox"
                                       :checked="isCohortSelected(row.registration_id)"
                                       @change="toggleCohortRow(row.registration_id)"
                                       :aria-label="'Selecteer ' + row.name">
                            </td>
                            <td>
                                <span x-text="row.name"></span>
                                <em x-show="row.is_anonymised" class="sd-anon-tag">(verwijderd)</em>
                            </td>
                            <td x-text="row.organisation || '—'"></td>
                            <td><span class="sd-badge" :class="'sd-badge--' + row.status" x-text="regStatusMeta[row.status]?.label || row.status"></span></td>
                            <td x-text="cohortAttendanceLabel(row.attendance)"></td>
                            <td x-show="cohortCanMarkAttendance">
                                <div class="sd-cohort__mark">
                                    <button type="button" class="sd-attendance-cell sd-attendance-cell--present"
                                            @click="cohortMarkAttendance(row.user_id, 'present')" title="Aanwezig">✓</button>
                                    <button type="button" class="sd-attendance-cell sd-attendance-cell--absent"
                                            @click="cohortMarkAttendance(row.user_id, 'absent')" title="Afwezig">✗</button>
                                    <button type="button" class="sd-attendance-cell sd-attendance-cell--excused"
                                            @click="cohortMarkAttendance(row.user_id, 'excused')" title="Verontschuldigd">!</button>
                                    <button type="button" class="sd-attendance-cell sd-attendance-cell--none"
                                            @click="cohortMarkAttendance(row.user_id, '')" title="Wissen">·</button>
                                </div>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>

            <!-- Empty states (CF1) -->
            <template x-if="cohort.rows.length === 0">
                <div class="sd-empty">
                    <span class="sd-empty__icon">👤</span>
                    <p class="sd-empty__text" x-text="cohort.scope === 'trajectory' ? 'Geen inschrijvingen voor dit traject' : 'Nog geen inschrijvingen voor deze editie'"></p>
                </div>
            </template>
            <template x-if="cohort.rows.length > 0 && cohortVisibleCount === 0">
                <div class="sd-empty">
                    <span class="sd-empty__icon">🔍</span>
                    <p class="sd-empty__text" x-text="cohort.sessionId ? 'Niemand gekozen voor deze sessie' : 'Geen inschrijvingen voldoen aan het filter'"></p>
                </div>
            </template>

            <!-- Roster bulk bar (CF4 / CF5) — actions derived from the transition map -->
            <div class="ws-bulkbar sd-cohort__bulkbar" x-show="config.canManage && cohortSelectedCount > 0" x-transition>
                <div class="ws-bulkbar__count">
                    <span class="ws-bulkbar__num" x-text="cohortSelectedCount"></span>
                    <span class="ws-bulkbar__label">geselecteerd</span>
                </div>
                <div class="ws-bulkbar__div"></div>
                <span class="ws-bulkbar__hint" x-show="cohortMixedHint">
                    Geen gedeelde actie voor: <span x-text="cohortStatesSummary()"></span>
                </span>
                <div class="ws-bulkbar__actions" x-show="!cohortMixedHint">
                    <template x-for="a in cohortBulkActions" :key="a.id">
                        <button type="button"
                                class="ws-bbtn ws-bbtn--primary"
                                :disabled="cohort.bulkBusy !== null"
                                @click="runCohortBulk(a.id)">
                            <span x-show="cohort.bulkBusy === a.id">Bezig…</span>
                            <span x-show="cohort.bulkBusy !== a.id" x-text="a.label"></span>
                        </button>
                    </template>
                    <button type="button" class="ws-bbtn ws-bbtn--close" @click="clearCohortSelection()" title="Selectie wissen">×</button>
                </div>
            </div>

            <!-- Per-row partial-failure report (CF4/CF5) -->
            <template x-if="cohort.resultOpen && cohort.result">
                <div>
                    <div class="ws-overlay" @click="closeCohortResult()"></div>
                    <div class="ws-modal" role="dialog" aria-modal="true">
                        <div class="ws-modal__head">
                            <div class="ws-modal__icon" :class="cohort.result.err === 0 ? 'ws-modal__icon--ok' : 'ws-modal__icon--mixed'" x-text="cohort.result.err === 0 ? '✓' : '!'"></div>
                            <div>
                                <div class="ws-modal__title" x-text="cohort.result.action"></div>
                                <div class="ws-modal__sub">
                                    <span x-text="cohort.result.ok"></span> geslaagd<span x-show="cohort.result.err > 0">, <span x-text="cohort.result.err"></span> mislukt</span> van <span x-text="cohort.result.total"></span>
                                </div>
                            </div>
                        </div>
                        <div class="ws-modal__body">
                            <div class="ws-fail-list" x-show="cohort.result.failed.length > 0">
                                <div class="ws-fail-list__head">Mislukte inschrijvingen</div>
                                <template x-for="f in cohort.result.failed" :key="f.id">
                                    <div class="ws-fail-row">
                                        <span class="ws-fail-row__icon">×</span>
                                        <div>
                                            <div class="ws-fail-row__name" x-text="f.name"></div>
                                            <div class="ws-fail-row__msg" x-text="f.message"></div>
                                        </div>
                                        <span class="ws-fail-row__code" x-text="f.code"></span>
                                    </div>
                                </template>
                            </div>
                        </div>
                        <div class="ws-modal__foot">
                            <button type="button" class="sd-btn sd-btn--primary" @click="closeCohortResult()">Sluiten</button>
                        </div>
                    </div>
                </div>
            </template>

        </div>
    </template>
</div>
