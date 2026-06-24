<?php
/**
 * Admin Workspace — Inschrijvingen surface (cluster C).
 *
 * The registration grid — the most-used surface. Ported from
 * docs/mockups/admin-workspace/inschrijvingen.html and REBOUND from the mock
 * WS.* flat fixtures to the live per-surface Alpine factory in
 * assets/js/admin/grid.js, which consumes the REAL nested endpoint shape.
 *
 * The grid() factory OWNS loading ALL of its own data in init(): it reads
 * ?queue=/?status= from the URL (the Vandaag deep-link), pre-filters, and
 * fetches GET /admin/registrations server-side (paging/filter/sort/group all
 * server-owned — never a client-side corpus slice). It owns its own
 * loading / empty / error state and re-loads on every filter/page/group change.
 *
 * Scope: mounted with x-data="grid()" INSIDE the shell's wsShell() scope, so it
 * inherits api(), switchView(), icon() from the shell (Alpine v3 nested-scope).
 *
 * INV-5: every x-html binds a CONSTANT icon name via icon('<literal>'); never a
 * data field. Status/offerte labels and names render via x-text (auto-escaped).
 * INV-7: status.value/label + offerteStatus render AS RECEIVED — never
 * re-derived. company.id and company.name are independent (FK vs billing name),
 * surfaced side by side, never merged.
 * AF-2: the bulk bar is gated by canManage (view-only role sees NO bulk bar).
 *
 * @package Stride\Admin
 */

defined('ABSPATH') || exit;
?>
<section class="ws-content ws-content--flush ws-grid-shell"
         x-show="view === 'inschrijvingen'"
         x-data="grid()"
         @ws-refresh.window="if ($event.detail && $event.detail.view === 'inschrijvingen') reload()"
         x-cloak>

    <!-- ===== TOOLBAR ===== -->
    <div class="ws-toolbar">

        <!-- funnel: lifecycle stages left → right, counts from server statusCounts -->
        <div class="ws-toolbar__row ws-toolbar__row--pipe">
            <div class="ws-pipeline" role="group" aria-label="<?php echo esc_attr__('Filter op fase in het inschrijvingsproces', 'stride'); ?>">
                <span class="ws-pipeline__label"><?php echo esc_html__('Fase in inschrijvingsproces', 'stride'); ?></span>
                <div class="ws-pipeline__track">
                    <template x-for="(key, i) in statusPipeline" :key="key">
                        <div class="ws-pipeline__step">
                            <button class="ws-stage-chip"
                                    :class="'ws-stage-chip--'+statusMeta[key].cls + (filters.status===key ? ' is-active' : '')"
                                    :title="statusMeta[key].hint"
                                    :aria-pressed="filters.status===key"
                                    @click="setStatus(key)">
                                <span class="ws-stage-chip__no" x-text="i+1"></span>
                                <span class="ws-stage-chip__txt" x-text="statusMeta[key].pipe"></span>
                                <span class="ws-stage-chip__count" x-text="statusCount(key)"></span>
                            </button>
                            <span class="ws-pipeline__arrow" x-show="i < statusPipeline.length - 1" x-html="icon('arrowRight')"></span>
                        </div>
                    </template>

                    <span class="ws-pipeline__sep" title="<?php echo esc_attr__('Buiten de funnel — eindstatus', 'stride'); ?>"></span>
                    <button class="ws-stage-chip ws-stage-chip--exit"
                            :class="filters.status===statusExit && 'is-active'"
                            :title="statusMeta[statusExit].hint"
                            :aria-pressed="filters.status===statusExit"
                            @click="setStatus(statusExit)">
                        <span class="ws-stage-chip__icon" x-html="icon('xCircle')"></span>
                        <span class="ws-stage-chip__txt" x-text="statusMeta[statusExit].pipe"></span>
                        <span class="ws-stage-chip__count" x-text="statusCount(statusExit)"></span>
                    </button>
                </div>
            </div>
            <div class="ws-toolbar__spacer"></div>
            <button class="ws-btn ws-btn--subtle ws-btn--sm" x-show="hasFilters" @click="clearAllFilters()">
                <span x-html="icon('x')"></span> <?php echo esc_html__('Filters wissen', 'stride'); ?>
            </button>
        </div>

        <div class="ws-toolbar__row">
            <!-- group-by VIEW control (real GROUP_BY_ALLOWLIST: edition_id/status/company_id) -->
            <div class="ws-select-wrap ws-select-wrap--group" :class="groupBy && 'is-grouping'">
                <span x-html="icon('layers')" style="width:14px;height:14px"></span>
                <?php echo esc_html__('Indelen per', 'stride'); ?>
                <select class="ws-select" x-model="groupBy" @change="onGroupChange()">
                    <option value=""><?php echo esc_html__('Niet indelen', 'stride'); ?></option>
                    <option value="edition_id"><?php echo esc_html__('Editie', 'stride'); ?></option>
                    <option value="status"><?php echo esc_html__('Status', 'stride'); ?></option>
                    <option value="company_id"><?php echo esc_html__('Organisatie', 'stride'); ?></option>
                </select>
                <button class="ws-group-clear" x-show="groupBy" @click="groupBy=''; onGroupChange()" title="<?php echo esc_attr__('Indeling opheffen', 'stride'); ?>">
                    <span x-html="icon('x')"></span>
                </button>
            </div>

            <span class="ws-toolbar__sep"></span>
            <span class="ws-toolbar__group-label"><?php echo esc_html__('Filters', 'stride'); ?></span>

            <!-- search: server-side q (name/email/organisation LIKE) -->
            <div class="ws-search ws-search--inline">
                <span x-html="icon('search')"></span>
                <input type="text"
                       placeholder="<?php echo esc_attr__('Zoek op naam, e-mail, organisatie…', 'stride'); ?>"
                       x-model="filters.q" @input.debounce.350ms="onSearchChange()">
            </div>

            <!-- edition filter (server typeahead source: GET /admin/editions/options) -->
            <div class="ws-select-wrap">
                <span x-html="icon('grid')" style="width:14px;height:14px"></span>
                <?php echo esc_html__('Editie', 'stride'); ?>
                <select class="ws-select" x-model.number="filters.edition_id" @change="onFilterChange()">
                    <option value="0"><?php echo esc_html__('Alle edities', 'stride'); ?></option>
                    <template x-for="e in editionOptions" :key="e.id"><option :value="e.id" x-text="e.title"></option></template>
                </select>
            </div>

            <div class="ws-toolbar__spacer"></div>
            <div class="ws-count">
                <?php echo esc_html__('Toont', 'stride'); ?> <b x-text="rangeFrom"></b>–<b x-text="rangeTo"></b>
                <?php echo esc_html__('van', 'stride'); ?> <b x-text="total.toLocaleString('nl-BE')"></b>
            </div>
        </div>

        <!-- active filter chips -->
        <div class="ws-toolbar__row" x-show="activeChips.length" style="padding-top:2px">
            <span class="ws-filterbar__label" x-html="icon('filter')" style="width:14px;height:14px;color:var(--ws-text-3)"></span>
            <template x-for="chip in activeChips" :key="chip.k">
                <span class="ws-chip is-active">
                    <span x-text="chip.label"></span>
                    <span class="ws-chip__x" @click="removeChip(chip.k)" x-html="icon('x')"></span>
                </span>
            </template>
        </div>
    </div>

    <!-- ===== TABLE ===== -->
    <div class="ws-tablewrap">

        <!-- error banner (load failed) -->
        <template x-if="error">
            <div class="ws-empty" style="padding-top:64px">
                <div class="ws-empty__icon" x-html="icon('alert')"></div>
                <h3><?php echo esc_html__('Inschrijvingen niet geladen', 'stride'); ?></h3>
                <p x-text="error"></p>
                <button class="ws-btn ws-btn--ghost" style="margin-top:16px" @click="reload()">
                    <span x-html="icon('refresh')"></span> <?php echo esc_html__('Opnieuw proberen', 'stride'); ?>
                </button>
            </div>
        </template>

        <!-- loading skeleton -->
        <template x-if="loading && !error">
            <div class="ws-empty" style="padding-top:64px">
                <p><?php echo esc_html__('Laden…', 'stride'); ?></p>
            </div>
        </template>

        <!-- FLAT (ungrouped) -->
        <table class="ws-table" x-show="!loading && !error && !groupBy && total > 0">
            <thead>
                <tr>
                    <th class="ws-col-check">
                        <input type="checkbox" class="ws-check"
                               :checked="pageAllSelected" :indeterminate="pageSomeSelected" @click="togglePage()">
                    </th>
                    <th class="is-sortable" :class="sortKey==='name' && 'is-sorted'" @click="sort('name')">
                        <?php echo esc_html__('Naam', 'stride'); ?>
                        <span class="ws-sort-ind" x-html="icon('chevDown')" :style="sortKey==='name'&&sortDir==='asc'?'transform:rotate(180deg)':''"></span>
                    </th>
                    <th class="is-sortable" :class="sortKey==='edition' && 'is-sorted'" @click="sort('edition')">
                        <?php echo esc_html__('Editie', 'stride'); ?>
                        <span class="ws-sort-ind" x-html="icon('chevDown')" :style="sortKey==='edition'&&sortDir==='asc'?'transform:rotate(180deg)':''"></span>
                    </th>
                    <th class="ws-col-status is-sortable" :class="sortKey==='status' && 'is-sorted'" @click="sort('status')">
                        <?php echo esc_html__('Status', 'stride'); ?>
                        <span class="ws-sort-ind" x-html="icon('chevDown')" :style="sortKey==='status'&&sortDir==='asc'?'transform:rotate(180deg)':''"></span>
                    </th>
                    <th class="ws-col-offerte"><?php echo esc_html__('Offerte', 'stride'); ?></th>
                    <th class="ws-col-att"><?php echo esc_html__('Aanwezigheid', 'stride'); ?></th>
                    <th><?php echo esc_html__('Organisatie', 'stride'); ?></th>
                    <th class="ws-col-traject"><?php echo esc_html__('Traject', 'stride'); ?></th>
                </tr>
            </thead>
            <tbody>
                <template x-for="r in rows" :key="r.id">
                    <tr :class="isSelected(r.id) && 'is-selected'" @click="openRow(r)">
                        <td class="ws-col-check" @click.stop>
                            <input type="checkbox" class="ws-check" :checked="isSelected(r.id)" @change="toggle(r.id)">
                        </td>
                        <td>
                            <div class="ws-namecell">
                                <div class="ws-namecell__avatar" :style="`background:${avatarColor(r.user.name)}`" x-text="initials(r.user.name)"></div>
                                <div>
                                    <div class="ws-namecell__name" x-text="r.user.name"></div>
                                    <div class="ws-namecell__sub" x-text="r.user.email"></div>
                                </div>
                            </div>
                        </td>
                        <td class="ws-edition-cell">
                            <span x-text="r.edition.title || '—'"></span>
                        </td>
                        <td>
                            <span class="ws-badge" :class="'ws-badge--'+r.status.value" x-text="r.status.label"></span>
                        </td>
                        <td>
                            <span class="ws-offerte" :class="'ws-offerte--'+offerteClass(r.offerteStatus)">
                                <span class="ws-offerte__dot"></span>
                                <span x-text="r.offerteStatus"></span>
                            </span>
                        </td>
                        <td>
                            <template x-if="r.attendancePct != null">
                                <div class="ws-meter">
                                    <div class="ws-meter__track"><div class="ws-meter__fill" :class="attClass(r.attendancePct)" :style="`width:${r.attendancePct}%`"></div></div>
                                    <span class="ws-meter__val" x-text="r.attendancePct + '%'"></span>
                                </div>
                            </template>
                            <template x-if="r.attendancePct == null">
                                <span class="ws-meter__val ws-meter__val--na">—</span>
                            </template>
                        </td>
                        <td>
                            <template x-if="r.company.name">
                                <span class="ws-org-cell"><span x-html="icon('building')"></span><span x-text="r.company.name"></span></span>
                            </template>
                            <template x-if="!r.company.name && r.company.id">
                                <span class="ws-org-cell"><span x-html="icon('building')"></span><span x-text="'#'+r.company.id"></span></span>
                            </template>
                            <template x-if="!r.company.name && !r.company.id">
                                <span class="ws-muted" style="font-size:var(--ws-fs-sm)"><?php echo esc_html__('Particulier', 'stride'); ?></span>
                            </template>
                        </td>
                        <td>
                            <template x-if="r.trajectory && r.trajectory.title">
                                <span class="ws-traject-cell"><span x-html="icon('route')"></span><span x-text="r.trajectory.title"></span></span>
                            </template>
                            <template x-if="!r.trajectory || !r.trajectory.title"><span class="ws-muted">—</span></template>
                        </td>
                    </tr>
                </template>
            </tbody>
        </table>

        <!-- GROUPED — aggregate rows (different shape: group_value/count/pct_afgerond/…) -->
        <table class="ws-table ws-table--grouped" x-show="!loading && !error && groupBy && total > 0">
            <thead>
                <tr>
                    <th><?php echo esc_html__('Groep', 'stride'); ?></th>
                    <th><?php echo esc_html__('Aantal', 'stride'); ?></th>
                    <th><?php echo esc_html__('% afgerond', 'stride'); ?></th>
                    <th><?php echo esc_html__('Gem. aanwezigheid', 'stride'); ?></th>
                    <th><?php echo esc_html__('Offerte-verdeling', 'stride'); ?></th>
                </tr>
            </thead>
            <tbody>
                <template x-for="g in groups" :key="g.group_value">
                    <tr class="ws-grouprow">
                        <td>
                            <span class="ws-grouphead__kind" x-text="groupKindLabel"></span>
                            <span class="ws-grouphead__title" x-text="groupLabel(g)"></span>
                        </td>
                        <td><b x-text="g.count"></b> <span x-text="g.count===1 ? '<?php echo esc_js(__('inschrijving', 'stride')); ?>' : '<?php echo esc_js(__('inschrijvingen', 'stride')); ?>'"></span></td>
                        <td><span class="ws-agg__val" x-text="g.pct_afgerond + '%'"></span></td>
                        <td><span class="ws-agg__val" x-text="g.avg_attendance_pct != null ? g.avg_attendance_pct + '%' : '—'"></span></td>
                        <td><span class="ws-distlegend" x-text="distSummary(g.offerte_verdeling)"></span></td>
                    </tr>
                </template>
            </tbody>
        </table>

        <!-- EMPTY STATE -->
        <div class="ws-empty" x-show="!loading && !error && total === 0" style="padding-top:80px">
            <div class="ws-empty__icon" x-html="icon('inbox')"></div>
            <h3 x-text="emptyTitle()"></h3>
            <p><?php echo esc_html__('Pas de filters aan of wis ze om meer inschrijvingen te zien.', 'stride'); ?></p>
            <button class="ws-btn ws-btn--ghost" style="margin-top:16px" x-show="hasFilters" @click="clearAllFilters()">
                <span x-html="icon('x')"></span> <?php echo esc_html__('Filters wissen', 'stride'); ?>
            </button>
        </div>
    </div>

    <!-- ===== BULK BAR (gated by canManage — AF-2 denied edge) ===== -->
    <template x-if="canManage && selectedCount > 0">
        <div class="ws-bulkbar">
            <div class="ws-bulkbar__count">
                <span class="ws-bulkbar__num" x-text="selectedCount"></span>
                <span class="ws-bulkbar__label">
                    <?php echo esc_html__('geselecteerd', 'stride'); ?>
                    <template x-if="!selectAllFilter && total > selectedIds.length && selectedIds.length > 0">
                        <a @click="selectAllFiltered()"><?php echo esc_html__('— selecteer alle', 'stride'); ?> <span x-text="total"></span></a>
                    </template>
                </span>
            </div>

            <div class="ws-bulkbar__div"></div>

            <template x-if="!mixedHint">
                <span class="ws-bulkbar__hint" style="color:#cbd5e1">
                    <span x-html="icon('info')"></span>
                    <span x-text="'<?php echo esc_js(__('Status:', 'stride')); ?> ' + statesSummary()"></span>
                </span>
            </template>
            <template x-if="mixedHint">
                <span class="ws-bulkbar__hint">
                    <span x-html="icon('alert')"></span>
                    <?php echo esc_html__('Gemengde statussen — geen gedeelde actie. Verfijn de selectie.', 'stride'); ?>
                </span>
            </template>

            <div class="ws-bulkbar__actions">
                <template x-for="(a, i) in topActions" :key="a.id">
                    <button class="ws-bbtn" :class="i===0 && !a.danger ? 'ws-bbtn--primary' : (a.danger ? 'ws-bbtn--danger' : '')"
                            :disabled="busyAction" @click="runBulk(a.id)">
                        <template x-if="busyAction===a.id"><span x-html="icon('refresh')" style="animation:spin 0.8s linear infinite"></span></template>
                        <template x-if="busyAction!==a.id"><span x-html="icon(a.icon)"></span></template>
                        <span x-text="a.label"></span>
                    </button>
                </template>

                <div class="ws-menu" x-show="overflowActions.length > 0" @click.outside="overflowOpen=false">
                    <button class="ws-bbtn" @click="overflowOpen=!overflowOpen"><span x-html="icon('more')"></span> <?php echo esc_html__('Meer', 'stride'); ?></button>
                    <div class="ws-menu__pop" x-show="overflowOpen" x-transition>
                        <div class="ws-menu__label"><?php echo esc_html__('Acties', 'stride'); ?></div>
                        <template x-for="a in overflowActions" :key="a.id">
                            <button class="ws-menu__item" :class="a.danger && 'ws-menu__item--danger'" @click="runBulk(a.id)">
                                <span x-html="icon(a.icon)"></span> <span x-text="a.label"></span>
                            </button>
                        </template>
                    </div>
                </div>

                <div class="ws-bulkbar__div"></div>
                <button class="ws-bbtn ws-bbtn--close" title="<?php echo esc_attr__('Selectie wissen', 'stride'); ?>" @click="clearSelection()"><span x-html="icon('x')"></span></button>
            </div>
        </div>
    </template>

    <!-- ===== PAGINATION ===== -->
    <div class="ws-pager" x-show="!error && total > 0 && !groupBy">
        <div class="ws-count"><?php echo esc_html__('Pagina', 'stride'); ?> <b x-text="page"></b> <?php echo esc_html__('van', 'stride'); ?> <b x-text="pageCount"></b></div>
        <div class="ws-pager__pages">
            <button class="ws-page-btn" :disabled="page===1" @click="goPage(page-1)"><span x-html="icon('chevRight')" style="transform:rotate(180deg);width:15px;height:15px"></span></button>
            <template x-for="p in pageList()" :key="p">
                <template x-if="p === '…'"><span class="ws-page-ellipsis">…</span></template>
                <template x-if="p !== '…'"><button class="ws-page-btn" :class="p===page && 'is-active'" @click="goPage(p)" x-text="p"></button></template>
            </template>
            <button class="ws-page-btn" :disabled="page===pageCount" @click="goPage(page+1)"><span x-html="icon('chevRight')" style="width:15px;height:15px"></span></button>
        </div>
        <div class="ws-select-wrap"><?php echo esc_html__('Per pagina', 'stride'); ?>
            <select class="ws-select" x-model.number="perPage" @change="onPerPageChange()"><option>10</option><option>25</option><option>50</option></select>
        </div>
    </div>
    <div class="ws-pager" x-show="!error && groupBy && total > 0" style="justify-content:center">
        <span class="ws-muted" style="font-size:var(--ws-fs-sm)"><?php echo esc_html__('Gegroepeerd — paginering uit.', 'stride'); ?> <b x-text="total"></b> <?php echo esc_html__('inschrijvingen in', 'stride'); ?> <b x-text="groups.length"></b> <?php echo esc_html__('groepen.', 'stride'); ?></span>
    </div>

    <!-- ===== RESULT MODAL (partial-failure report) ===== -->
    <template x-if="showResult && result">
        <div>
            <div class="ws-overlay" @click="closeResult()"></div>
            <div class="ws-modal" role="dialog" aria-modal="true">
                <div class="ws-modal__head">
                    <div class="ws-modal__icon" :class="result.err === 0 ? 'ws-modal__icon--ok' : 'ws-modal__icon--mixed'"
                         x-html="icon(result.err === 0 ? 'checkCircle' : 'alert')"></div>
                    <div>
                        <div class="ws-modal__title" x-text="result.action + ' <?php echo esc_js(__('afgerond', 'stride')); ?>'"></div>
                        <div class="ws-modal__sub" x-text="result.ok + ' <?php echo esc_js(__('van', 'stride')); ?> ' + result.total + ' <?php echo esc_js(__('geslaagd', 'stride')); ?>' + (result.err ? ' · ' + result.err + ' <?php echo esc_js(__('mislukt', 'stride')); ?>' : '')"></div>
                    </div>
                </div>
                <div class="ws-modal__body">
                    <template x-if="result.err > 0">
                        <div class="ws-fail-list">
                            <div class="ws-fail-list__head"><?php echo esc_html__('Mislukte rijen — niet gewijzigd', 'stride'); ?></div>
                            <template x-for="f in result.failed" :key="f.id">
                                <div class="ws-fail-row">
                                    <div class="ws-fail-row__icon" x-html="icon('x')"></div>
                                    <div style="flex:1;min-width:0">
                                        <div class="ws-fail-row__name" x-text="f.name"></div>
                                        <div class="ws-fail-row__msg" x-text="f.message"></div>
                                    </div>
                                    <span class="ws-fail-row__code" x-text="f.code"></span>
                                </div>
                            </template>
                        </div>
                    </template>
                </div>
                <div class="ws-modal__foot">
                    <button class="ws-btn ws-btn--ghost" @click="closeResult()"><?php echo esc_html__('Sluiten', 'stride'); ?></button>
                </div>
            </div>
        </div>
    </template>

    <!-- ===== TOASTS ===== -->
    <div class="ws-toast-zone">
        <template x-for="t in toasts" :key="t.id">
            <div class="ws-toast">
                <div class="ws-toast__icon" :class="t.kind==='ok' ? 'ws-toast__icon--ok' : 'ws-toast__icon--mixed'" x-html="icon(t.kind==='ok' ? 'check' : 'info')"></div>
                <div class="ws-toast__msg"><strong x-text="t.lead" x-show="t.lead"></strong> <span x-text="t.body"></span></div>
            </div>
        </template>
    </div>
</section>
