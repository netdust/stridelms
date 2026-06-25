<?php
/**
 * Admin Workspace — Dossier surface (cluster D).
 *
 * The per-person case view. Ported from docs/mockups/admin-workspace/dossier.html
 * and REBOUND from the mock WS.DOSSIER fixtures to the live per-surface Alpine
 * factory in assets/js/admin/dossier.js, which consumes the REAL endpoint shapes
 * (GET /admin/users/{id}/detail + /trajectories — both loaded in init()).
 *
 * The dossier() factory OWNS loading ALL its own data in init(): it reads ?user=
 * from the URL (the Vandaag/grid deep-link via the shell's switchView), then
 * loads BOTH endpoints in parallel, each with its own loading / empty / error
 * state (AF-3: a failed trajectory load never blanks the registrations).
 *
 * Scope: mounted with x-data="dossier()" INSIDE the shell's wsShell() scope, so
 * it inherits api(), switchView(), icon() from the shell (Alpine v3 nested-scope).
 *
 * INV-5 (fixes the mockup's violations — does NOT port them): every x-html binds
 * a CONSTANT icon name via icon('<literal>') / icon(<closed-enum field>); never a
 * data field. The mockup's `WS.icon('check')+sel` and `WS.icon(...)+person.x`
 * concatenations are REWRITTEN to icon-via-x-html + label-via-x-text. Person /
 * edition / note / stage-data / selection / timeline-text all render via x-text
 * (auto-escaped). ev.icon / ev.dot are closed-enum values from the mapper's
 * constant tables, not free text.
 * INV-6b: `selections` are SERVER-resolved label strings — rendered, never
 * parsed. INV-7: status / offerte labels render AS RECEIVED (statusMeta /
 * offerteClass only map to a CSS color key, never re-derive the status).
 *
 * @package Stride\Admin
 */

defined('ABSPATH') || exit;
?>
<section class="ws-content ws-content--flush"
         x-show="view === 'dossier'"
         x-data="dossier()"
         @ws-refresh.window="if ($event.detail && $event.detail.view === 'dossier') init()"
         x-cloak>

    <!-- ===== LOADING ===== -->
    <template x-if="loading.detail">
        <div class="ws-content">
            <div class="ws-empty">
                <span class="ws-empty__icon" x-html="icon('user')"></span>
                <p><?php echo esc_html__('Dossier laden…', 'stride'); ?></p>
            </div>
        </div>
    </template>

    <!-- ===== DETAIL LOAD ERROR ===== -->
    <template x-if="!loading.detail && errors.detail">
        <div class="ws-content">
            <div class="ws-empty">
                <div class="ws-empty__icon" x-html="icon('alert')"></div>
                <h3><?php echo esc_html__('Dossier niet geladen', 'stride'); ?></h3>
                <p x-text="errors.detail"></p>
                <button class="ws-btn ws-btn--ghost ws-btn--sm" @click="backToGrid()" style="margin-top:var(--ws-4)">
                    <span x-html="icon('chevRight')" style="transform:rotate(180deg)"></span>
                    <?php echo esc_html__('Terug naar grid', 'stride'); ?>
                </button>
            </div>
        </div>
    </template>

    <!-- ===== DOSSIER BODY ===== -->
    <template x-if="!loading.detail && !errors.detail && person">
        <div class="ws-content">

            <!-- breadcrumb / back -->
            <div class="ws-topbar__crumbs" style="margin-bottom:var(--ws-6)">
                <a href="#" @click.prevent="backToGrid()" style="color:var(--ws-text-2)"><?php echo esc_html__('Inschrijvingen', 'stride'); ?></a>
                <span class="sep" x-html="icon('chevRight')" style="width:14px;height:14px"></span>
                <span><?php echo esc_html__('Gebruikers', 'stride'); ?></span>
                <span class="sep" x-html="icon('chevRight')" style="width:14px;height:14px"></span>
                <b x-text="person.display_name"></b>
            </div>

            <!-- ===== PERSON HEADER =====
                 Bound to the REAL /detail `user` object keys (ground-truthed):
                 display_name / organisation / department / email / phone /
                 billing_city / profile_type — NOT the mockup's name/org/role/city. -->
            <div class="ws-person-head ws-stagger">
                <div class="ws-person-head__avatar"
                     :style="`background:linear-gradient(135deg, ${avatarColor(person.display_name)}, #1d4ed8)`"
                     x-text="initials(person.display_name)"></div>
                <div class="ws-person-head__info">
                    <div class="ws-person-head__name" x-text="person.display_name"></div>
                    <div class="ws-person-head__meta">
                        <span x-show="person.profile_type">
                            <span x-html="icon('briefcase')"></span><span x-text="person.profile_type"></span>
                        </span>
                        <span x-show="person.organisation || person.department">
                            <span x-html="icon('building')"></span>
                            <span x-text="[person.organisation, person.department].filter(Boolean).join(' · ')"></span>
                        </span>
                        <span x-show="person.email">
                            <span x-html="icon('mail')"></span><span x-text="person.email"></span>
                        </span>
                        <span x-show="person.phone">
                            <span x-html="icon('phone')"></span><span x-text="person.phone"></span>
                        </span>
                        <span x-show="person.billing_city">
                            <span x-html="icon('mapPin')"></span><span x-text="person.billing_city"></span>
                        </span>
                    </div>
                </div>
            </div>

            <div class="ws-dossier">
                <!-- ===== LEFT: trajectories + registrations ===== -->
                <div>

                    <!-- ===== TRAJECTORY SECTION (§11.4 / F8) ===== -->
                    <template x-if="loading.trajectories">
                        <div class="ws-muted" style="margin-bottom:var(--ws-6);font-size:var(--ws-fs-sm)">
                            <?php echo esc_html__('Trajecten laden…', 'stride'); ?>
                        </div>
                    </template>
                    <template x-if="!loading.trajectories && errors.trajectories">
                        <div class="ws-empty" style="margin-bottom:var(--ws-6);padding:16px">
                            <div class="ws-empty__icon" x-html="icon('alert')"></div>
                            <p x-text="errors.trajectories"></p>
                        </div>
                    </template>

                    <template x-if="!loading.trajectories && !errors.trajectories && trajectories.length">
                        <div style="margin-bottom:var(--ws-8)">
                            <div class="ws-section-title ws-mt-0">
                                <span x-html="icon('route')"></span> <?php echo esc_html__('Trajecten', 'stride'); ?>
                                <span class="ws-grouphead__count" x-text="trajectories.length"></span>
                                <span class="ws-section-title__line"></span>
                            </div>

                            <template x-for="t in trajectories" :key="t.trajectory.id">
                                <div class="ws-traj-block">
                                    <div class="ws-traj-block__head">
                                        <div class="ws-traj-block__icon" x-html="icon('route')"></div>
                                        <div class="ws-traj-block__heading">
                                            <div class="ws-traj-block__title" x-text="t.trajectory.title"></div>
                                            <div class="ws-traj-block__meta">
                                                <span class="ws-badge" :class="'ws-badge--'+trajStatus(t.trajectory.status).cls" x-text="trajStatus(t.trajectory.status).label"></span>
                                                <span class="ws-traj-mode" x-text="trajMode(t.trajectory.mode)"></span>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- progress -->
                                    <div class="ws-traj-progress">
                                        <div class="ws-traj-progress__top">
                                            <span class="ws-traj-progress__headline"><b x-text="t.completed_count"></b> <?php echo esc_html__('van', 'stride'); ?> <b x-text="t.total_required"></b> <?php echo esc_html__('afgerond', 'stride'); ?></span>
                                            <span class="ws-traj-progress__pct" x-text="trajProgressPct(t) + '%'"></span>
                                        </div>
                                        <div class="ws-traj-progress__bar">
                                            <div class="ws-traj-progress__fill" :style="`width:${trajProgressPct(t)}%`"></div>
                                        </div>
                                        <div class="ws-traj-progress__legend">
                                            <span><span class="ws-dot ws-dot--done"></span> <b x-text="t.completed_count"></b> <?php echo esc_html__('afgerond', 'stride'); ?></span>
                                            <span><span class="ws-dot ws-dot--active"></span> <b x-text="t.in_progress_count"></b> <?php echo esc_html__('bezig', 'stride'); ?></span>
                                            <span><span class="ws-dot ws-dot--upcoming"></span> <b x-text="trajTodo(t)"></b> <?php echo esc_html__('nog te doen', 'stride'); ?></span>
                                        </div>
                                    </div>

                                    <!-- required courses with state -->
                                    <div class="ws-traj-subhead"><?php echo esc_html__('Verplichte cursussen', 'stride'); ?></div>
                                    <div class="ws-traj-courselist">
                                        <template x-for="(c, ci) in t.required_courses" :key="ci">
                                            <div class="ws-traj-courserow">
                                                <span class="ws-att-row__dot" :class="'ws-att-row__dot--'+courseStateClass(c.state)"></span>
                                                <div class="ws-traj-courserow__body">
                                                    <span class="ws-traj-courserow__title" x-text="c.title"></span>
                                                    <small x-text="c.cohort || c.edition || ''"></small>
                                                </div>
                                                <span class="ws-att-row__state" :class="'ws-att-row__state--'+courseStateClass(c.state)" x-text="courseStateLabel(c.state)"></span>
                                            </div>
                                        </template>
                                    </div>

                                    <!-- elective groups: chosen vs required -->
                                    <div class="ws-traj-subhead"><?php echo esc_html__('Keuzemodules', 'stride'); ?></div>
                                    <div class="ws-traj-electives">
                                        <template x-for="(g, gi) in t.elective_groups" :key="gi">
                                            <div class="ws-traj-elect" :class="g.isChosen ? 'is-complete' : 'is-open'">
                                                <div class="ws-traj-elect__top">
                                                    <span class="ws-traj-elect__name">
                                                        <span x-text="g.name"></span>
                                                        <span class="ws-traj-elect__rule" x-text="'<?php echo esc_js(__('kies', 'stride')); ?> ' + g.required + ' <?php echo esc_js(__('uit', 'stride')); ?> ' + g.total"></span>
                                                    </span>
                                                    <span class="ws-traj-elect__count" :class="g.isChosen ? 'is-complete' : 'is-open'">
                                                        <span x-html="icon(g.isChosen ? 'checkCircle' : 'clock')"></span>
                                                        <span x-text="g.countChosen + ' <?php echo esc_js(__('van', 'stride')); ?> ' + g.required + ' <?php echo esc_js(__('gekozen', 'stride')); ?>'"></span>
                                                    </span>
                                                </div>
                                                <template x-if="g.chosen && g.chosen.length">
                                                    <div class="ws-traj-elect__chosen">
                                                        <template x-for="(ch, chi) in g.chosen" :key="chi">
                                                            <span class="ws-pill ws-pill--done"><span x-html="icon('check')"></span><span x-text="ch.title"></span></span>
                                                        </template>
                                                        <template x-if="!g.isChosen">
                                                            <span class="ws-traj-elect__remaining" x-text="'<?php echo esc_js(__('Nog', 'stride')); ?> ' + (g.required - g.countChosen) + ' <?php echo esc_js(__('te kiezen', 'stride')); ?>'"></span>
                                                        </template>
                                                    </div>
                                                </template>
                                                <template x-if="!g.chosen || !g.chosen.length">
                                                    <div class="ws-traj-elect__none"><span x-html="icon('info')"></span> <?php echo esc_html__('Nog geen keuze gemaakt', 'stride'); ?></div>
                                                </template>
                                            </div>
                                        </template>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </template>

                    <!-- ===== REGISTRATIONS ===== -->
                    <div class="ws-section-title ws-mt-0">
                        <span x-html="icon('grid')"></span> <?php echo esc_html__('Inschrijvingen', 'stride'); ?>
                        <span class="ws-grouphead__count" x-text="regs.length"></span>
                        <span class="ws-section-title__line"></span>
                    </div>

                    <!-- empty: user with 0 registrations (AF-3) -->
                    <template x-if="!hasRegs">
                        <div class="ws-empty">
                            <span class="ws-empty__icon" x-html="icon('inbox')"></span>
                            <p><?php echo esc_html__('Deze persoon heeft nog geen inschrijvingen.', 'stride'); ?></p>
                        </div>
                    </template>

                    <template x-for="(r, idx) in regs" :key="r.id">
                        <div class="ws-reg" :class="r.open && 'is-open'" :id="'reg-'+r.id">
                            <!-- reg head -->
                            <div class="ws-reg__head" @click="r.open = !r.open">
                                <span class="ws-reg__chev" x-html="icon('chevRight')"></span>
                                <div class="ws-reg__title">
                                    <b x-text="r.edition_title"></b>
                                    <small><?php echo esc_html__('ingeschreven', 'stride'); ?> <span x-text="r.registered_at"></span></small>
                                </div>
                                <div class="ws-reg__badges">
                                    <span class="ws-offerte" :class="'ws-offerte--'+offerteClass(r.offerte_status_label || r.offerte_status)" style="margin-right:8px" x-show="r.offerte_status_label || r.offerte_status">
                                        <span class="ws-offerte__dot"></span><span x-text="r.offerte_status_label || r.offerte_status"></span>
                                    </span>
                                    <span class="ws-badge" :class="'ws-badge--'+statusMeta(r.status).cls" x-text="statusMeta(r.status).label"></span>
                                </div>
                            </div>

                            <!-- reg body -->
                            <div class="ws-reg__body" x-show="r.open" x-collapse>

                                <!-- detail grid (status lives in the reg header badge — not duplicated here) -->
                                <div class="ws-detail-grid">
                                    <div class="ws-field"><div class="ws-field__label"><?php echo esc_html__('Inschrijvingspad', 'stride'); ?></div><div class="ws-field__val" x-text="r.enrollment_path"></div></div>
                                    <div class="ws-field"><div class="ws-field__label"><?php echo esc_html__('Ingeschreven op', 'stride'); ?></div><div class="ws-field__val" x-text="r.registered_at"></div></div>
                                    <div class="ws-field"><div class="ws-field__label"><?php echo esc_html__('Afgerond op', 'stride'); ?></div><div class="ws-field__val" x-text="r.completed_at || '—'"></div></div>
                                </div>

                                <!-- pending hint (preserved from the removed status field; only for pending rows) -->
                                <template x-if="r.status === 'pending'">
                                    <p class="ws-status-hint">
                                        <span x-html="icon('info')"></span>
                                        <span><?php echo esc_html__('Wacht op gebruiker (intake, sessiekeuze, documenten) of op goedkeuring zodra die taken klaar zijn.', 'stride'); ?></span>
                                    </p>
                                </template>

                                <!-- enrollment_data STAGES (only stages WITH data render; closed by default) -->
                                <div class="ws-section-title"><span x-html="icon('fileText')"></span> <?php echo esc_html__('Ingediende gegevens', 'stride'); ?> <span class="ws-section-title__line"></span></div>
                                <template x-if="submittedStages(r).length">
                                    <div class="ws-stages">
                                        <template x-for="s in submittedStages(r)" :key="s.key">
                                            <div class="ws-stage" :class="isStageOpen(r.id, s.key) && 'is-open'">
                                                <div class="ws-stage__head" @click="toggleStage(r.id, s.key)" role="button" :aria-expanded="isStageOpen(r.id, s.key)">
                                                    <div class="ws-stage__icon" x-html="icon(s.meta.icon)"></div>
                                                    <div class="ws-stage__heading">
                                                        <span class="ws-stage__name" x-text="s.meta.name"></span>
                                                        <span class="ws-stage__desc" x-text="s.meta.desc"></span>
                                                    </div>
                                                    <span class="ws-stage__submitted">
                                                        <b x-text="s.stage.submitted_at"></b>
                                                        <span class="ws-stage__by" x-show="s.stage.submitted_by"><?php echo esc_html__('door', 'stride'); ?> <span x-text="s.stage.submitted_by"></span></span>
                                                    </span>
                                                    <span class="ws-stage__chev" x-html="icon('chevDown')"></span>
                                                </div>
                                                <div class="ws-stage__body" x-show="isStageOpen(r.id, s.key)" x-collapse>
                                                    <dl class="ws-kv">
                                                        <template x-for="(val, label) in s.stage.data" :key="label">
                                                            <div class="ws-kv__row">
                                                                <dt x-text="humanizeKey(label)"></dt>
                                                                <dd x-text="val"></dd>
                                                            </div>
                                                        </template>
                                                    </dl>
                                                </div>
                                            </div>
                                        </template>
                                    </div>
                                </template>
                                <template x-if="!submittedStages(r).length">
                                    <p class="ws-muted" style="font-size:var(--ws-fs-sm);font-style:italic;margin:0"><?php echo esc_html__('Nog geen gegevens ingediend.', 'stride'); ?></p>
                                </template>

                                <!-- FULFILLMENT region (attendance + sessions + completion) — only meaningful
                                     for pending/confirmed/completed. waitlist/interest/cancelled show a muted hint. -->
                                <template x-if="showsFulfillment(r.status)">
                                    <div>
                                        <!-- attendance per session -->
                                        <div class="ws-section-title"><span x-html="icon('calendar')"></span> <?php echo esc_html__('Aanwezigheid', 'stride'); ?> <span class="ws-section-title__line"></span></div>
                                        <template x-if="r.attendance">
                                            <div class="ws-att-summary">
                                                <span class="ws-att-summary__item"><span class="ws-att-row__dot ws-att-row__dot--present"></span> <b x-text="r.attendance.present"></b> <?php echo esc_html__('aanwezig', 'stride'); ?></span>
                                                <span class="ws-att-summary__item"><span class="ws-att-row__dot ws-att-row__dot--absent"></span> <b x-text="r.attendance.absent"></b> <?php echo esc_html__('afwezig', 'stride'); ?></span>
                                                <span class="ws-att-summary__item"><span class="ws-att-row__dot ws-att-row__dot--excused"></span> <b x-text="r.attendance.excused"></b> <?php echo esc_html__('verontschuldigd', 'stride'); ?></span>
                                                <span class="ws-att-summary__item"><?php echo esc_html__('van', 'stride'); ?> <b x-text="r.attendance.total_sessions"></b> <?php echo esc_html__('sessies', 'stride'); ?> · <b x-text="r.attendance.hours"></b><?php echo esc_html__('u', 'stride'); ?></span>
                                            </div>
                                        </template>
                                        <template x-if="!r.attendance">
                                            <p class="ws-muted" style="font-size:var(--ws-fs-sm);font-style:italic;margin:0"><?php echo esc_html__('Geen sessies / aanwezigheid voor deze inschrijving.', 'stride'); ?></p>
                                        </template>

                                        <!-- selections + completion tasks -->
                                        <div class="ws-dossier-two-col">
                                            <div>
                                                <div class="ws-section-title"><span x-html="icon('route')"></span> <?php echo esc_html__('Gekozen sessies', 'stride'); ?> <span class="ws-section-title__line"></span></div>
                                                <template x-if="r.selections && r.selections.length">
                                                    <div class="ws-pill-list">
                                                        <!-- INV-5 FIX: icon via x-html (constant), label via x-text (data) — never concatenated into x-html. INV-6b: server-resolved label, never parsed. -->
                                                        <template x-for="(sel, si) in r.selections" :key="si">
                                                            <span class="ws-pill"><span x-html="icon('check')"></span><span x-text="sel"></span></span>
                                                        </template>
                                                    </div>
                                                </template>
                                                <template x-if="!r.selections || !r.selections.length">
                                                    <p class="ws-muted" style="font-size:var(--ws-fs-sm);font-style:italic;margin:0"><?php echo esc_html__('Geen sessiekeuze.', 'stride'); ?></p>
                                                </template>
                                            </div>
                                            <div>
                                                <div class="ws-section-title"><span x-html="icon('checkCircle')"></span> <?php echo esc_html__('Voltooiingstaken', 'stride'); ?> <span class="ws-section-title__line"></span></div>
                                                <div class="ws-pill-list">
                                                    <template x-for="(task, ti) in completionFor(r)" :key="ti">
                                                        <span class="ws-pill" :class="task.done ? 'ws-pill--done' : 'ws-pill--todo'">
                                                            <span x-html="icon(task.done ? 'check' : 'clock')"></span><span x-text="task.label"></span>
                                                        </span>
                                                    </template>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </template>
                                <template x-if="!showsFulfillment(r.status)">
                                    <p class="ws-muted" style="font-size:var(--ws-fs-sm);font-style:italic;margin:0" x-text="fulfillmentEmptyHint(r.status)"></p>
                                </template>

                                <!-- notes -->
                                <div class="ws-section-title"><span x-html="icon('edit')"></span> <?php echo esc_html__('Notities', 'stride'); ?> <span class="ws-section-title__line"></span></div>
                                <template x-if="r.notes">
                                    <div class="ws-note"><span x-text="r.notes"></span></div>
                                </template>
                                <template x-if="!r.notes">
                                    <p class="ws-muted" style="font-size:var(--ws-fs-sm);font-style:italic;margin:0"><?php echo esc_html__('Geen notities.', 'stride'); ?></p>
                                </template>

                                <!-- state-appropriate actions (derived, presentational) -->
                                <div class="ws-reg-actions">
                                    <div class="ws-reg-actions__label"><span x-html="icon('sparkle')"></span> <?php echo esc_html__('Acties voor deze inschrijving', 'stride'); ?></div>
                                    <div class="ws-reg-actions__row">
                                        <template x-for="(a, i) in actionsFor(r.status)" :key="a.id">
                                            <button class="ws-action-btn"
                                                    :class="a.danger ? 'ws-action-btn--danger' : (i===0 ? 'ws-action-btn--primary' : 'ws-action-btn--secondary')"
                                                    :title="a.label">
                                                <span class="ws-action-btn__ico" x-html="icon(a.icon)"></span>
                                                <span x-text="a.label"></span>
                                            </button>
                                        </template>
                                        <template x-if="actionsFor(r.status).length === 0">
                                            <span class="ws-reg-actions__none">
                                                <span x-html="icon('info')"></span>
                                                <?php echo esc_html__('Geen acties — geannuleerd. Een nieuwe inschrijving start een nieuw dossier.', 'stride'); ?>
                                            </span>
                                        </template>
                                    </div>
                                </div>

                            </div>
                        </div>
                    </template>
                </div>

                <!-- ===== RIGHT: history timeline ===== -->
                <div>
                    <div class="ws-card ws-timeline-card">
                        <div class="ws-card__head">
                            <span x-html="icon('history')" style="width:17px;height:17px;color:var(--ws-primary)"></span>
                            <span class="ws-card__title"><?php echo esc_html__('Geschiedenis', 'stride'); ?></span>
                        </div>
                        <div class="ws-card__body">
                            <!-- timeline gated off (PII N1) → locked/empty, never a crash -->
                            <template x-if="!canSeeTimeline">
                                <div class="ws-empty">
                                    <span class="ws-empty__icon" x-html="icon('alert')"></span>
                                    <p><?php echo esc_html__('De geschiedenis is afgeschermd voor jouw rol.', 'stride'); ?></p>
                                </div>
                            </template>

                            <template x-if="canSeeTimeline">
                                <div>
                                    <div class="ws-timeline-card__note">
                                        <span x-html="icon('info')" style="width:14px;height:14px"></span>
                                        <?php echo esc_html__('Per-gebeurtenis events — leven hier, nooit in de grid.', 'stride'); ?>
                                    </div>

                                    <template x-if="activeTimeline.length">
                                        <div class="ws-timeline">
                                            <template x-for="(ev, i) in activeTimeline" :key="i">
                                                <div class="ws-tl-item">
                                                    <span class="ws-tl-item__node" :class="'ws-tl-item__node--'+ev.dot" x-html="icon(ev.icon)"></span>
                                                    <div class="ws-tl-item__title" x-text="ev.title"></div>
                                                    <div class="ws-tl-item__meta">
                                                        <span class="ws-tl-item__actor" x-html="icon(actorIsAdmin(ev.actor) ? 'briefcase' : 'user')"></span>
                                                        <span class="ws-tl-item__actor" x-text="ev.actor"></span> · <span x-text="ev.when"></span>
                                                    </div>
                                                </div>
                                            </template>
                                        </div>
                                    </template>
                                    <template x-if="!activeTimeline.length">
                                        <p class="ws-muted" style="font-size:var(--ws-fs-sm);font-style:italic;margin:var(--ws-4) 0"><?php echo esc_html__('Nog geen gebeurtenissen voor deze inschrijving.', 'stride'); ?></p>
                                    </template>

                                    <!-- per-registration timeline switcher -->
                                    <div class="ws-timeline-card__switch" x-show="regs.length > 1">
                                        <select class="ws-select" style="width:100%" x-model.number="timelineReg">
                                            <template x-for="r in regs" :key="r.id">
                                                <option :value="r.id" x-text="'<?php echo esc_js(__('Tijdlijn:', 'stride')); ?> ' + r.edition_title"></option>
                                            </template>
                                        </select>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </template>

</section>
