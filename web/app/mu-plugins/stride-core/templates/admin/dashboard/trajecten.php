<?php
/**
 * Admin Workspace — Trajecten surface (Cluster E).
 *
 * Read-only overview of meerdelige leertrajecten + a detail slide-over (the
 * trajectory's courses + enrolled-deelnemers roster). Ported from
 * docs/mockups/admin-workspace/trajecten.html and REBOUND from the mock WS.*
 * fixtures to the live per-surface Alpine factory in assets/js/admin/trajecten.js,
 * which consumes the REAL frozen endpoints:
 *   GET /admin/trajectories       → { items:[…], total, … }   (list)
 *   GET /admin/trajectories/{id}  → <item> + { registrations:[…] } (detail)
 *
 * Backend FROZEN. The mockup's `trajectories`/`required`/`electiveGroups`/`users`
 * keys do NOT exist on these endpoints — rebound to the real flat shape:
 *   - list rows are flat items (t.title / t.status / t.statusLabel / t.modeLabel /
 *     t.capacity / t.enrolledCount / t.courseCount). No nested `trajectory:{…}`.
 *   - the detail courses are a FLAT array grouped client-side by type into
 *     detailCourses.{editions,online} (no required/elective structure on this
 *     endpoint — that lives on the dossier per-user endpoint). The "kies N uit M"
 *     elective microcopy is dropped (data not available here).
 *   - the roster is `detail.registrations` (regId,id,name,email,status,
 *     status_label); regId = registration row id, THE stable :key — id is the
 *     USER id and is 0 for a deleted account. A row links to the person's
 *     dossier via switchView('dossier',{user:r.id}) (disabled when id=0),
 *     NOT a hardcoded dossier.html#reg-103. No WS.COMPANIES lookup.
 *
 * Scope: mounted with x-data="trajecten()" INSIDE the shell's wsShell() scope,
 * so it inherits api(), switchView(), icon() from the shell (Alpine v3 nested).
 *
 * INV-5: every x-html binds a CONSTANT icon name via icon('<literal>'); never a
 * data field. INV-7: status/mode labels + status_label render AS RECEIVED via
 * x-text (auto-escaped). Load-bearing states (AF-4): loading / error / empty
 * list / empty roster — all own states, never a crash.
 *
 * @package Stride\Admin
 */

defined('ABSPATH') || exit;
?>
<section class="ws-content ws-traj-shell"
         x-show="view === 'trajecten'"
         x-data="trajecten()"
         @ws-refresh.window="if ($event.detail && $event.detail.view === 'trajecten') loadList()"
         @keydown.escape.window="view === 'trajecten' && (detail || detailLoading || detailError) && closeDetail()"
         x-cloak>
    <div class="ws-stagger">

        <!-- ===== PAGE HEAD ===== -->
        <div class="ws-page-head">
            <div>
                <span class="ws-eyebrow"><?php echo esc_html__('Beheer · leertrajecten', 'stride'); ?></span>
                <h1><?php echo esc_html__('Trajecten', 'stride'); ?></h1>
                <p><?php echo esc_html__('Meerdelige leertrajecten — verplichte cursussen plus keuzemodules. Alleen-lezen overzicht; open een traject voor de cursussen en deelnemers.', 'stride'); ?></p>
            </div>
        </div>

        <!-- ===== TOOLBAR: scope pill + status filter + search ===== -->
        <div class="ws-traj-toolbar">
            <!-- the "Actieve trajecten" default-scope pill (server-owned scope) -->
            <!-- Strings inside the Alpine :title EXPRESSION are JS-string
                 context → esc_js, not esc_attr (an esc_attr'd apostrophe
                 HTML-decodes back to a raw quote and breaks the expression).
                 x-html icons stay CONSTANT literals per INV-5 — two x-show'd
                 spans, never a dynamic icon(cond ? … : …) expression. -->
            <span class="ws-chip" :class="scope==='active' && 'is-active'" @click="toggleScope()"
                  :title="scope==='active' ? '<?php echo esc_js(__('Toon ook afgesloten trajecten', 'stride')); ?>' : '<?php echo esc_js(__('Beperk tot actieve trajecten', 'stride')); ?>'">
                <span x-show="scope==='active'" x-html="icon('check')" style="width:13px;height:13px"></span>
                <span x-show="scope!=='active'" x-html="icon('archive')" style="width:13px;height:13px"></span>
                <span x-text="scope==='active' ? '<?php echo esc_js(__('Actieve trajecten', 'stride')); ?>' : '<?php echo esc_js(__('Alle trajecten', 'stride')); ?>'"></span>
            </span>

            <div class="ws-select-wrap" style="margin-left:var(--ws-3)">
                <span x-html="icon('filter')" style="width:14px;height:14px"></span>
                <?php echo esc_html__('Status', 'stride'); ?>
                <select class="ws-select" x-model="statusFilter" @change="onFilterChange()">
                    <option value=""><?php echo esc_html__('Alle statussen', 'stride'); ?></option>
                    <?php
                    // The REAL trajectory vocabulary (F-T2): trajectories carry
                    // OfferingStatus values; the old hardcoded list offered a
                    // nonexistent 'closed' (matched nothing) and omitted
                    // in_progress/cancelled/completed entirely.
                    foreach (\Stride\Domain\OfferingStatus::cases() as $offeringStatus) : ?>
                        <option value="<?php echo esc_attr($offeringStatus->value); ?>"><?php echo esc_html($offeringStatus->label()); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="ws-search ws-search--inline" style="margin-left:var(--ws-3)">
                <span x-html="icon('search')"></span>
                <input type="text"
                       placeholder="<?php echo esc_attr__('Zoek traject op titel…', 'stride'); ?>"
                       x-model="q" @input.debounce.350ms="onFilterChange()">
            </div>

            <div class="ws-toolbar__spacer" style="flex:1"></div>
            <div class="ws-count"><?php echo esc_html__('Toont', 'stride'); ?> <b x-text="rows.length"></b> <?php echo esc_html__('van', 'stride'); ?> <b x-text="total"></b> <?php echo esc_html__('trajecten', 'stride'); ?></div>
        </div>

        <!-- ===== LIST ===== -->
        <div class="ws-card" style="overflow:hidden;margin-top:var(--ws-4)">

            <!-- error banner -->
            <template x-if="error">
                <div class="ws-empty" style="padding:var(--ws-10) var(--ws-6)">
                    <div class="ws-empty__icon" x-html="icon('alert')"></div>
                    <h3><?php echo esc_html__('Trajecten niet geladen', 'stride'); ?></h3>
                    <p x-text="error"></p>
                    <button class="ws-btn ws-btn--ghost" style="margin-top:16px" @click="loadList()">
                        <span x-html="icon('refresh')"></span> <?php echo esc_html__('Opnieuw proberen', 'stride'); ?>
                    </button>
                </div>
            </template>

            <!-- loading -->
            <template x-if="loading && !error">
                <div class="ws-empty" style="padding:var(--ws-10) var(--ws-6)">
                    <p><?php echo esc_html__('Laden…', 'stride'); ?></p>
                </div>
            </template>

            <!-- table -->
            <table class="ws-table ws-traj-table" x-show="!loading && !error && rows.length > 0">
                <thead>
                    <tr>
                        <th><?php echo esc_html__('Traject', 'stride'); ?></th>
                        <th class="ws-col-status"><?php echo esc_html__('Status', 'stride'); ?></th>
                        <th><?php echo esc_html__('Vorm', 'stride'); ?></th>
                        <th class="ws-num"><?php echo esc_html__('Capaciteit', 'stride'); ?></th>
                        <th class="ws-num"><?php echo esc_html__('Ingeschreven', 'stride'); ?></th>
                        <th class="ws-num"><?php echo esc_html__('Cursussen', 'stride'); ?></th>
                        <th class="ws-col-traject-action"></th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="t in rows" :key="t.id">
                        <tr @click="openDetail(t.id)">
                            <td>
                                <div class="ws-traj-namecell">
                                    <span class="ws-traj-namecell__icon" x-html="icon('route')"></span>
                                    <div>
                                        <div class="ws-traj-namecell__title" x-text="t.title"></div>
                                        <div class="ws-traj-namecell__sub" x-text="t.description"></div>
                                    </div>
                                </div>
                            </td>
                            <td><span class="ws-badge" :class="'ws-badge--'+t.badgeClass" x-text="t.statusLabel"></span></td>
                            <td><span class="ws-traj-mode" x-text="t.modeLabel"></span></td>
                            <td class="ws-num" x-text="t.capacity ? t.capacity : '<?php echo esc_js(__('onbeperkt', 'stride')); ?>'"></td>
                            <td class="ws-num"><span :class="t.enrolledCount===0 && 'ws-muted'" x-text="t.enrolledCount"></span></td>
                            <td class="ws-num" x-text="t.courseCount"></td>
                            <td class="ws-col-traject-action">
                                <span class="ws-traj-open"><span><?php echo esc_html__('Open', 'stride'); ?></span> <span x-html="icon('chevRight')"></span></span>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>

            <!-- empty list (0 active trajectories / no match) -->
            <div class="ws-empty" x-show="!loading && !error && rows.length === 0" style="padding:var(--ws-10) var(--ws-6)">
                <div class="ws-empty__icon" x-html="icon('route')"></div>
                <h3><?php echo esc_html__('Geen actieve trajecten', 'stride'); ?></h3>
                <p><?php echo esc_html__('Pas de zoekterm, status of het bereik aan.', 'stride'); ?></p>
            </div>

        </div>

        <!-- pager (F-T4: the list was hard-capped at the first 50 with no way to
             reach the rest). SAME shared ws-pager markup + goPage(p)/pageList()
             contract as edities/offertes — no per-surface pager dialect. -->
        <div class="ws-pager" x-show="!loading && !error && pageCount > 1">
            <div class="ws-count"><?php echo esc_html__('Pagina', 'stride'); ?> <b x-text="page"></b> <?php echo esc_html__('van', 'stride'); ?> <b x-text="pageCount"></b></div>
            <div class="ws-pager__pages">
                <button class="ws-page-btn" :disabled="page===1" @click="goPage(page-1)"><span x-html="icon('chevRight')" style="transform:rotate(180deg);width:15px;height:15px"></span></button>
                <template x-for="p in pageList()" :key="p">
                    <template x-if="p === '…'"><span class="ws-page-ellipsis">…</span></template>
                    <template x-if="p !== '…'"><button class="ws-page-btn" :class="p===page && 'is-active'" @click="goPage(p)" x-text="p"></button></template>
                </template>
                <button class="ws-page-btn" :disabled="page===pageCount" @click="goPage(page+1)"><span x-html="icon('chevRight')" style="width:15px;height:15px"></span></button>
            </div>
        </div>
    </div>

    <!-- ===== DETAIL SLIDE-OVER ===== -->
    <template x-if="detail || detailLoading || detailError">
        <div>
            <div class="ws-overlay" @click="closeDetail()"></div>
            <aside class="ws-slideover" role="dialog" aria-modal="true">

                <!-- detail loading -->
                <template x-if="detailLoading">
                    <div class="ws-slideover__body">
                        <div class="ws-empty" style="padding:var(--ws-10) var(--ws-4)">
                            <p><?php echo esc_html__('Laden…', 'stride'); ?></p>
                        </div>
                    </div>
                </template>

                <!-- detail error (incl. 404 — a trajectory beyond the first page) -->
                <template x-if="detailError && !detailLoading">
                    <div class="ws-slideover__body">
                        <div class="ws-empty" style="padding:var(--ws-10) var(--ws-4)">
                            <div class="ws-empty__icon" x-html="icon('alert')"></div>
                            <h3><?php echo esc_html__('Traject niet geladen', 'stride'); ?></h3>
                            <p x-text="detailError"></p>
                            <button class="ws-btn ws-btn--ghost" style="margin-top:16px" @click="closeDetail()"><?php echo esc_html__('Sluiten', 'stride'); ?></button>
                        </div>
                    </div>
                </template>

                <!-- detail body -->
                <template x-if="detail && !detailLoading && !detailError">
                    <div>
                        <div class="ws-slideover__head">
                            <div class="ws-slideover__head-main">
                                <span class="ws-eyebrow" style="margin-bottom:4px"><?php echo esc_html__('Traject', 'stride'); ?></span>
                                <h2 class="ws-slideover__title" x-text="detail.title"></h2>
                                <div class="ws-slideover__meta">
                                    <span class="ws-badge" :class="'ws-badge--'+badgeClass(detail.status)" x-text="detail.statusLabel"></span>
                                    <span class="ws-traj-mode"><span x-html="icon('route')"></span> <span x-text="detail.modeLabel"></span></span>
                                    <span class="ws-traj-mode"><span x-html="icon('users')"></span> <span x-text="detail.enrolledCount + ' <?php echo esc_js(__('ingeschreven', 'stride')); ?>'"></span></span>
                                    <span class="ws-traj-mode"><span x-html="icon('book')"></span> <span x-text="detail.courseCount + ' <?php echo esc_js(__('cursussen', 'stride')); ?>'"></span></span>
                                </div>
                            </div>
                            <button class="ws-btn ws-btn--icon ws-btn--ghost" @click="closeDetail()" title="<?php echo esc_attr__('Sluiten', 'stride'); ?>"><span x-html="icon('x')"></span></button>
                        </div>

                        <div class="ws-slideover__body">
                            <!-- jump to the grid SCOPED to this trajectory: the
                                 trajectory_id deep-link rides switchView (shell
                                 whitelist) and the grid absorbs it on activation
                                 (child edition-rows via the parent→child join).
                                 It used to pass NOTHING — a plain view switch
                                 that landed on the unfiltered grid (F-T1). -->
                            <button class="ws-btn" style="width:100%;margin-bottom:var(--ws-5)"
                                    @click="switchView('inschrijvingen', { trajectory_id: detail && detail.id })">
                                <span x-html="icon('grid')"></span> <?php echo esc_html__('Toon inschrijvingen', 'stride'); ?>
                            </button>

                            <!-- COURSE LIST -->
                            <div class="ws-section-title ws-mt-0"><span x-html="icon('book')"></span> <?php echo esc_html__('Cursussen', 'stride'); ?> <span class="ws-section-title__line"></span></div>

                            <!-- edition-backed courses -->
                            <div class="ws-traj-courseblock" x-show="detailCourses.editions.length > 0">
                                <div class="ws-traj-courseblock__label"><?php echo esc_html__('Cursussen met editie', 'stride'); ?></div>
                                <template x-for="(c, ci) in detailCourses.editions" :key="ci">
                                    <div class="ws-traj-course">
                                        <span class="ws-traj-course__dot ws-traj-course__dot--req"></span>
                                        <div class="ws-traj-course__body">
                                            <div class="ws-traj-course__title" x-text="c.label"></div>
                                        </div>
                                        <span class="ws-pill" style="background:var(--ws-primary-subtle);border-color:var(--ws-primary-light);color:var(--ws-primary-active)"><?php echo esc_html__('Editie', 'stride'); ?></span>
                                    </div>
                                </template>
                            </div>

                            <!-- online / self-study modules -->
                            <div class="ws-traj-courseblock" x-show="detailCourses.online.length > 0">
                                <div class="ws-traj-courseblock__label">
                                    <span x-html="icon('route')" style="width:13px;height:13px;color:#7c3aed"></span>
                                    <?php echo esc_html__('Online modules', 'stride'); ?>
                                </div>
                                <template x-for="(c, ci) in detailCourses.online" :key="ci">
                                    <div class="ws-traj-course">
                                        <span class="ws-traj-course__dot ws-traj-course__dot--elect"></span>
                                        <div class="ws-traj-course__body">
                                            <div class="ws-traj-course__title" x-text="c.label"></div>
                                        </div>
                                        <span class="ws-pill"><?php echo esc_html__('Online', 'stride'); ?></span>
                                    </div>
                                </template>
                            </div>

                            <!-- no courses at all -->
                            <div class="ws-empty" x-show="detailCourses.editions.length === 0 && detailCourses.online.length === 0" style="padding:var(--ws-6) var(--ws-4);text-align:left">
                                <p style="margin:0"><?php echo esc_html__('Nog geen cursussen aan dit traject gekoppeld.', 'stride'); ?></p>
                            </div>

                            <!-- ENROLLED DEELNEMERS (roster) -->
                            <!-- Count = the REAL participant total (enrolledCount), not the
                                 clamped roster length; when the roster is clipped at 50 the
                                 note says so instead of silently under-reporting (F-T4). -->
                            <div class="ws-section-title"><span x-html="icon('users')"></span> <?php echo esc_html__('Ingeschreven deelnemers', 'stride'); ?> <span class="ws-grouphead__count" x-text="detail.enrolledCount"></span><span class="ws-section-title__line"></span></div>
                            <p class="ws-muted" style="margin:calc(-1 * var(--ws-2)) 0 var(--ws-3);font-size:var(--ws-fs-sm)"
                               x-show="detail.enrolledCount > (detail.registrations || []).length"
                               x-text="'<?php echo esc_js(__('Alleen de %d recentste worden getoond.', 'stride')); ?>'.replace('%d', (detail.registrations || []).length)"></p>

                            <template x-if="detail.registrations && detail.registrations.length">
                                <div class="ws-traj-roster">
                                    <!-- keyed on regId (registration row id) — r.id is the USER id
                                         and is 0 for every deleted account; keying on it collapses
                                         those rows. A deleted-account row has no dossier to open:
                                         the button is disabled with an explanatory title. -->
                                    <template x-for="r in detail.registrations" :key="r.regId">
                                        <button class="ws-traj-rosteritem" @click="openPerson(r)"
                                                :disabled="!r.id"
                                                :title="r.id ? '' : '<?php echo esc_js(__('Account verwijderd — geen dossier beschikbaar', 'stride')); ?>'"
                                                :style="r.id ? '' : 'cursor:default;opacity:.65'">
                                            <div class="ws-traj-rosteritem__body">
                                                <div class="ws-traj-rosteritem__name" x-text="r.name"></div>
                                                <div class="ws-traj-rosteritem__sub" x-text="r.email"></div>
                                            </div>
                                            <span class="ws-badge" :class="'ws-badge--'+badgeClass(r.status)" x-text="r.status_label"></span>
                                        </button>
                                    </template>
                                </div>
                            </template>

                            <!-- empty roster (the t3 edge — NOT a crash) -->
                            <template x-if="!detail.registrations || !detail.registrations.length">
                                <div class="ws-empty" style="padding:var(--ws-8) var(--ws-4);text-align:left;display:flex;gap:var(--ws-3);align-items:center">
                                    <div class="ws-empty__icon" style="width:32px;height:32px;margin:0" x-html="icon('inbox')"></div>
                                    <div>
                                        <h3 style="margin:0"><?php echo esc_html__('Nog geen inschrijvingen', 'stride'); ?></h3>
                                        <p style="margin:2px 0 0;max-width:none"><?php echo esc_html__('Dit traject heeft nog geen deelnemers.', 'stride'); ?></p>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>
                </template>
            </aside>
        </div>
    </template>
</section>
