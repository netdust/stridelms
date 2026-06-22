<?php
/**
 * Dossier case view (Task 3.4) — person → registration, all stages.
 *
 * A slide-over opened from a grid row click (openGridRow → openDossier),
 * fed by GET /admin/users/{id}/detail (AdminUserService::getUserDetail).
 * Renders the person-headed registrations with their enrollment_data stages,
 * offerte status, attendance, selections, and the whole-person history timeline.
 *
 * Presentational only — all data (selections / INV-6b, offerte-status,
 * stage normalization) is composed server-side. The trajectory section is
 * OUT OF SCOPE (Phase 1E / cluster C2).
 *
 * @var string $admin_url  Trailing-slashed admin URL (from the parent template).
 */

if (!defined('ABSPATH')) {
    exit;
}
?>
<!-- ============================================================
     DOSSIER CASE VIEW (Task 3.4)
     ============================================================ -->
<div class="sd-slideout sd-slideout--wide" x-show="dossierOpen"
     x-transition:enter="sd-slideout--enter" x-transition:leave="sd-slideout--leave" x-cloak>
    <div class="sd-slideout__overlay" @click="closeDossier()"></div>
    <div class="sd-slideout__panel">

        <!-- Header: person -->
        <div class="sd-slideout__header">
            <div>
                <h3 x-text="dossierUser.display_name || dossierUser.name || 'Dossier'"></h3>
                <div class="sd-dossier__person-meta" x-show="!dossierLoading && !dossierError">
                    <span x-show="dossierUser.organisation" x-text="dossierUser.organisation"></span>
                    <span x-show="dossierUser.department" x-text="' · ' + dossierUser.department"></span>
                    <span x-show="dossierUser.email" x-text="dossierUser.email"></span>
                </div>
            </div>
            <button @click="closeDossier()" class="sd-slideout__close">×</button>
        </div>

        <div class="sd-slideout__body">

            <!-- Loading -->
            <div x-show="dossierLoading" class="sd-empty">
                <p class="sd-empty__text">Dossier laden…</p>
            </div>

            <!-- Error (mid-flow failure edge — grid stays intact behind) -->
            <div x-show="dossierError" class="sd-empty">
                <span class="sd-empty__icon">⚠</span>
                <p class="sd-empty__text" x-text="dossierError"></p>
            </div>

            <!-- Body -->
            <div x-show="!dossierLoading && !dossierError && dossier" class="sd-dossier">

                <div class="sd-dossier__main">

                    <!-- Registrations (person-headed; expand-one) -->
                    <div class="sd-section-title">
                        <span>Inschrijvingen</span>
                        <span class="sd-section-title__count" x-text="dossierRegistrations.length"></span>
                    </div>

                    <template x-if="dossierRegistrations.length === 0">
                        <div class="sd-empty">
                            <span class="sd-empty__icon">📝</span>
                            <p class="sd-empty__text">Nog geen inschrijvingen</p>
                        </div>
                    </template>

                    <template x-for="reg in dossierRegistrations" :key="reg.id">
                        <div class="sd-reg" :class="{ 'sd-reg--open': isDossierRegOpen(reg.id) }">

                            <!-- reg head -->
                            <div class="sd-reg__head" @click="toggleDossierReg(reg.id)" role="button"
                                 :aria-expanded="isDossierRegOpen(reg.id) ? 'true' : 'false'">
                                <span class="sd-reg__chev" x-text="isDossierRegOpen(reg.id) ? '▾' : '▸'"></span>
                                <div class="sd-reg__title">
                                    <b x-text="reg.edition_title"></b>
                                    <small x-text="'ingeschreven ' + formatShortDate(reg.registered_at)"></small>
                                </div>
                                <div class="sd-reg__badges">
                                    <span class="sd-badge" :class="dossierOfferteClass(reg.offerte_status)"
                                          x-show="reg.offerte_status"
                                          x-text="reg.offerte_status_label"></span>
                                    <span class="sd-badge" :class="'sd-badge--' + reg.status" x-text="reg.status_label"></span>
                                </div>
                            </div>

                            <!-- reg body -->
                            <div class="sd-reg__body" x-show="isDossierRegOpen(reg.id)" x-collapse>

                                <!-- Inschrijvingsstatus + pending two-substate hint (§2.4) -->
                                <div class="sd-detail-grid">
                                    <div class="sd-field sd-field--wide">
                                        <div class="sd-field__label">Inschrijvingsstatus</div>
                                        <div class="sd-field__val">
                                            <span class="sd-badge" :class="'sd-badge--' + reg.status" x-text="reg.status_label"></span>
                                            <span class="sd-status-hint" x-show="reg.status === 'pending'">
                                                Wacht op gebruiker (intake, sessiekeuze, documenten)
                                                <b>of</b> op goedkeuring zodra die taken klaar zijn.
                                            </span>
                                        </div>
                                    </div>
                                    <div class="sd-field">
                                        <div class="sd-field__label">Inschrijvingspad</div>
                                        <div class="sd-field__val" x-text="enrollmentPathLabel(reg.enrollment_path)"></div>
                                    </div>
                                    <div class="sd-field">
                                        <div class="sd-field__label">Ingeschreven op</div>
                                        <div class="sd-field__val" x-text="formatDate(reg.registered_at)"></div>
                                    </div>
                                </div>

                                <!-- enrollment_data STAGES: empty hidden, with-data closed-then-open.
                                     The intake stage IS the questionnaire — no separate Vragenlijst block. -->
                                <div class="sd-section-title"><span>Ingediende gegevens</span></div>
                                <template x-if="dossierStages(reg).length">
                                    <div class="sd-stages">
                                        <template x-for="s in dossierStages(reg)" :key="s.key">
                                            <div class="sd-stage" :class="{ 'sd-stage--open': isDossierStageOpen(reg.id, s.key) }">
                                                <div class="sd-stage__head" @click="toggleDossierStage(reg.id, s.key)"
                                                     role="button" :aria-expanded="isDossierStageOpen(reg.id, s.key) ? 'true' : 'false'">
                                                    <div class="sd-stage__heading">
                                                        <span class="sd-stage__name" x-text="s.meta.name"></span>
                                                        <span class="sd-stage__desc" x-text="s.meta.desc"></span>
                                                    </div>
                                                    <span class="sd-stage__submitted" x-show="s.stage.submitted_at">
                                                        <b x-text="s.stage.submitted_at"></b>
                                                        <span x-show="s.stage.submitted_by" x-text="'door ' + s.stage.submitted_by"></span>
                                                    </span>
                                                    <span class="sd-stage__chev" x-text="isDossierStageOpen(reg.id, s.key) ? '▾' : '▸'"></span>
                                                </div>
                                                <!-- clean label→value pairs, NEVER a raw JSON dump -->
                                                <div class="sd-stage__body" x-show="isDossierStageOpen(reg.id, s.key)" x-collapse>
                                                    <dl class="sd-kv">
                                                        <template x-for="(val, label) in s.stage.data" :key="label">
                                                            <div class="sd-kv__row">
                                                                <dt x-text="dossierFieldLabel(label)"></dt>
                                                                <dd x-text="val"></dd>
                                                            </div>
                                                        </template>
                                                    </dl>
                                                </div>
                                            </div>
                                        </template>
                                    </div>
                                </template>
                                <template x-if="!dossierStages(reg).length">
                                    <p class="sd-muted">Nog geen gegevens ingediend.</p>
                                </template>

                                <!-- Attendance summary -->
                                <template x-if="reg.attendance">
                                    <div>
                                        <div class="sd-section-title"><span>Aanwezigheid</span></div>
                                        <div class="sd-field__val"
                                             x-text="(reg.attendance.present || 0) + '/' + (reg.attendance.total_sessions || 0) + ' sessies · ' + (reg.attendance.hours || 0) + 'u'"></div>
                                    </div>
                                </template>

                                <!-- Selections (server-resolved labels, INV-6b) -->
                                <template x-if="(reg.selections || []).length">
                                    <div>
                                        <div class="sd-section-title"><span>Gekozen sessies</span></div>
                                        <div class="sd-pill-list">
                                            <template x-for="sel in reg.selections" :key="sel">
                                                <span class="sd-pill" x-text="sel"></span>
                                            </template>
                                        </div>
                                    </div>
                                </template>

                                <!-- Notes -->
                                <template x-if="reg.notes">
                                    <div>
                                        <div class="sd-section-title"><span>Notities</span></div>
                                        <p class="sd-note" x-text="reg.notes"></p>
                                    </div>
                                </template>

                                <!-- State-appropriate actions (§2.1 map; terminal → geen acties).
                                     Hidden entirely for view-only actors (no manage cap). -->
                                <div class="sd-reg-actions" x-show="config.canManage">
                                    <div class="sd-reg-actions__label">Acties voor deze inschrijving</div>
                                    <div class="sd-reg-actions__row">
                                        <template x-for="(a, i) in dossierActionsFor(reg.status)" :key="a.id">
                                            <button class="sd-btn"
                                                    :class="a.danger ? 'sd-btn--danger' : (i === 0 ? 'sd-btn--primary' : 'sd-btn--ghost')"
                                                    :disabled="gridBulkBusy === a.id"
                                                    @click="runDossierAction(reg.id, a.id)"
                                                    x-text="a.label"></button>
                                        </template>
                                        <template x-if="dossierActionsFor(reg.status).length === 0">
                                            <span class="sd-reg-actions__none">
                                                Geen acties — terminale status. Een nieuwe inschrijving start een nieuw dossier.
                                            </span>
                                        </template>
                                    </div>
                                </div>

                            </div>
                        </div>
                    </template>
                </div>

                <!-- History timeline (whole-person audit trail, incl. session-selection
                     + attendance events — AdminActivityMapper server-side) -->
                <div class="sd-dossier__aside">
                    <div class="sd-card">
                        <div class="sd-card__header"><h3 class="sd-card__title">Geschiedenis</h3></div>
                        <div class="sd-card__body">
                            <template x-for="(ev, i) in dossierTimeline" :key="i">
                                <div class="sd-activity-item">
                                    <div class="sd-activity-icon" :class="'sd-activity-icon--' + (ev.type || 'action')"
                                         x-html="activityIcon(ev.type)"></div>
                                    <div class="sd-activity-item__content">
                                        <span class="sd-activity-item__text" x-text="ev.text"></span>
                                        <span class="sd-activity-item__time">
                                            <span x-text="ev.actor_name"></span> ·
                                            <span x-text="formatRelativeTime(ev.timestamp)"></span>
                                        </span>
                                    </div>
                                </div>
                            </template>
                            <template x-if="dossierTimeline.length === 0">
                                <div class="sd-empty">
                                    <span class="sd-empty__icon">·</span>
                                    <p class="sd-empty__text">Geen geschiedenis</p>
                                </div>
                            </template>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>
</div>
