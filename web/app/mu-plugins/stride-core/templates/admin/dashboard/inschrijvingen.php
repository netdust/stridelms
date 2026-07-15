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

            <!-- search: server-side q — display name / login / e-mail of the
                 account, PLUS the lead_name/lead_email columns so anonymous
                 interest/waitlist leads are findable by their own submission.
                 The placeholder promises exactly what the WHERE searches
                 (it used to claim 'organisatie', which was never searched). -->
            <div class="ws-search ws-search--inline">
                <span x-html="icon('search')"></span>
                <input type="text"
                       placeholder="<?php echo esc_attr__('Zoek op naam of e-mail…', 'stride'); ?>"
                       x-model="filters.q" @input.debounce.350ms="onSearchChange()">
            </div>

            <!-- edition filter: a real server-side TYPEAHEAD. The old flat
                 <select> was fed by ONE capped fetch (first 100, oldest
                 first) — current editions were unpickable at scale (F-G10). -->
            <div class="ws-select-wrap" style="position:relative" @click.outside="editionPickerOpen = false">
                <span x-html="icon('grid')" style="width:14px;height:14px"></span>
                <input type="text" class="ws-select" style="min-width:180px"
                       placeholder="<?php echo esc_attr__('Editie — typ om te zoeken…', 'stride'); ?>"
                       x-model="editionQuery"
                       @focus="openEditionPicker()"
                       @input.debounce.300ms="loadEditionOptions(); editionPickerOpen = true">
                <div class="ws-menu__pop" x-show="editionPickerOpen" x-transition
                     style="position:absolute;top:100%;left:0;right:0;max-height:260px;overflow-y:auto;z-index:30">
                    <button type="button" class="ws-menu__item" x-show="filters.edition_id" @click="clearEditionPick()">
                        <span x-html="icon('x')"></span> <?php echo esc_html__('Editiefilter wissen', 'stride'); ?>
                    </button>
                    <template x-for="e in editionOptions" :key="e.id">
                        <button type="button" class="ws-menu__item" @click="pickEdition(e)" x-text="e.title"></button>
                    </template>
                    <div class="ws-menu__label" x-show="!editionOptions.length"><?php echo esc_html__('Geen edities gevonden', 'stride'); ?></div>
                </div>
            </div>

            <!-- trajectory filter (small flat set; the server-side parent→child
                 join existed all along — the control and chip did not, F-G9) -->
            <div class="ws-select-wrap">
                <span x-html="icon('route')" style="width:14px;height:14px"></span>
                <?php echo esc_html__('Traject', 'stride'); ?>
                <select class="ws-select" x-model.number="filters.trajectory_id" @change="onFilterChange()">
                    <option value="0"><?php echo esc_html__('Alle trajecten', 'stride'); ?></option>
                    <template x-for="t in trajectoryOptions" :key="t.id"><option :value="t.id" x-text="t.title"></option></template>
                </select>
            </div>

            <div class="ws-toolbar__spacer"></div>
            <?php // Grouped mode: `total` is the DISTINCT GROUP count (server
                  // contract) — the unit word must say so, or "Toont 1–25 van 3"
                  // reads as nonsense (F-G5). ?>
            <div class="ws-count">
                <?php echo esc_html__('Toont', 'stride'); ?> <b x-text="rangeFrom"></b>–<b x-text="rangeTo"></b>
                <?php echo esc_html__('van', 'stride'); ?> <b x-text="total.toLocaleString('nl-BE')"></b>
                <span x-show="groupBy"> <?php echo esc_html__('groepen', 'stride'); ?></span>
            </div>
        </div>

        <!-- scope pill + active filter chips. The edition scope is ALWAYS
             announced (spec §10.4): the default "Actieve edities" pill is
             dismissable (widens to everything, incl. afgesloten/gearchiveerde
             edities), and the widened state offers the way back — the scope is
             never an invisible reason rows are missing. -->
        <div class="ws-toolbar__row" x-show="activeChips.length || scopePillVisible" style="padding-top:2px">
            <span class="ws-filterbar__label" x-html="icon('filter')" style="width:14px;height:14px;color:var(--ws-text-3)"></span>
            <template x-if="scopePillVisible && editionScope === 'active'">
                <span class="ws-chip is-active" :title="'<?php echo esc_js(__('Standaard tonen we enkel actieve edities. Klik op ✕ om ook afgesloten en gearchiveerde edities te tonen.', 'stride')); ?>'">
                    <span><?php echo esc_html__('Actieve edities', 'stride'); ?></span>
                    <span class="ws-chip__x" @click="widenScope()" x-html="icon('x')"></span>
                </span>
            </template>
            <template x-if="scopePillVisible && editionScope === 'all'">
                <span class="ws-chip">
                    <span><?php echo esc_html__('Alle edities (ook afgesloten)', 'stride'); ?></span>
                    <span class="ws-chip__x" @click="narrowScope()" :title="'<?php echo esc_js(__('Terug naar enkel actieve edities', 'stride')); ?>'" x-html="icon('x')"></span>
                </span>
            </template>
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
                               :checked="pageAllSelected" x-effect="$el.indeterminate = pageSomeSelected" @click="togglePage()">
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
                <template x-for="r in rows" :key="r.id"><?php require __DIR__ . '/_reg-row.php'; ?></template>
            </tbody>
        </table>

        <!-- GROUPED — accordion: one collapsible <tbody> per group. The group
             header shows the aggregates; expanding reveals up to 8 composed child
             rows (reusing the shared _reg-row.php partial). "Toon alle N" drops
             the grouping and re-loads the full flat paginated set for the group. -->
        <table class="ws-table ws-table--grouped" x-show="!loading && !error && groupBy && total > 0">
            <thead>
                <tr>
                    <th class="ws-col-check"></th>
                    <th><?php echo esc_html__('Naam', 'stride'); ?></th>
                    <th><?php echo esc_html__('Editie', 'stride'); ?></th>
                    <th class="ws-col-status"><?php echo esc_html__('Status', 'stride'); ?></th>
                    <th class="ws-col-offerte"><?php echo esc_html__('Offerte', 'stride'); ?></th>
                    <th class="ws-col-att"><?php echo esc_html__('Aanwezigheid', 'stride'); ?></th>
                    <th><?php echo esc_html__('Organisatie', 'stride'); ?></th>
                    <th class="ws-col-traject"><?php echo esc_html__('Traject', 'stride'); ?></th>
                </tr>
            </thead>
            <template x-for="g in groupsView" :key="g.key">
                <tbody class="ws-group" :class="!collapsed[g.key] && 'is-expanded'">
                    <!-- group header row (click to toggle) -->
                    <tr class="ws-grouprow">
                        <td :colspan="8">
                            <div class="ws-grouphead" :class="collapsed[g.key] && 'is-collapsed'"
                                 @click="toggleGroup(g.key)" role="button" :aria-expanded="!collapsed[g.key]">
                                <span class="ws-grouphead__chev" x-html="icon('chevDown')"></span>
                                <span class="ws-grouphead__kind" x-text="groupKindLabel"></span>
                                <span class="ws-grouphead__title">
                                    <span x-text="groupLabel(g)"></span>
                                    <span class="ws-grouphead__count" x-text="g.count + (g.count===1 ? ' <?php echo esc_js(__('inschrijving', 'stride')); ?>' : ' <?php echo esc_js(__('inschrijvingen', 'stride')); ?>')"></span>
                                </span>
                                <div class="ws-grouphead__aggs">
                                    <div class="ws-agg"><span class="ws-agg__label"><?php echo esc_html__('% afgerond', 'stride'); ?></span><span class="ws-agg__val" x-text="g.pct_afgerond + '%'"></span></div>
                                    <div class="ws-agg"><span class="ws-agg__label"><?php echo esc_html__('gem. aanwezigheid', 'stride'); ?></span><span class="ws-agg__val" x-text="g.avg_attendance_pct != null ? g.avg_attendance_pct + '%' : '—'"></span></div>
                                    <div class="ws-agg ws-agg--dist">
                                        <span class="ws-agg__label"><?php echo esc_html__('offerte-verdeling', 'stride'); ?></span>
                                        <span class="ws-distlegend" x-text="distSummary(g.offerte_verdeling)"></span>
                                    </div>
                                </div>
                            </div>
                        </td>
                    </tr>
                    <!-- child rows: collapsed groups iterate an EMPTY array (no x-show
                         needed on the shared partial, keeping it flat/grouped-agnostic). -->
                    <template x-for="r in (collapsed[g.key] ? [] : g.rows)" :key="r.id"><?php require __DIR__ . '/_reg-row.php'; ?></template>
                    <!-- "Toon alle N" — only when the server capped the child rows -->
                    <tr class="ws-grouprow ws-grouprow--more" x-show="g.hasMore && !collapsed[g.key]">
                        <td :colspan="8">
                            <button class="ws-btn ws-btn--subtle ws-btn--sm" @click="showAllInGroup(g)">
                                <?php echo esc_html__('Toon alle', 'stride'); ?> <span x-text="g.rowTotal"></span>
                            </button>
                        </td>
                    </tr>
                </tbody>
            </template>
        </table>

        <!-- EMPTY STATE -->
        <div class="ws-empty" x-show="!loading && !error && total === 0" style="padding-top:80px">
            <div class="ws-empty__icon" x-html="icon('inbox')"></div>
            <h3 x-text="emptyTitle()"></h3>
            <p><?php echo esc_html__('Pas de filters aan of wis ze om meer inschrijvingen te zien.', 'stride'); ?></p>
            <?php // The scope is a REASON rows can be missing — say so and offer
                  // the one-click widen instead of blaming the user's filters. ?>
            <p class="ws-muted" x-show="scopePillVisible && editionScope === 'active'" style="font-size:var(--ws-fs-sm)">
                <?php echo esc_html__('Tip: je kijkt enkel naar actieve edities.', 'stride'); ?>
                <a href="#" @click.prevent="widenScope()"><?php echo esc_html__('Toon ook afgesloten edities', 'stride'); ?></a>
            </p>
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
                    <?php // Cross-page select-all only where the blast radius is
                          // describable (canArmSelectAll): flat mode (grouped
                          // `total` counts GROUPS) AND a status-homogeneous
                          // context (status filter or queue pin) — otherwise the
                          // bulk bar would offer actions for off-page rows in
                          // states it cannot know. ?>
                    <template x-if="canArmSelectAll && !selectAllFilter && total > selectedIds.length && selectedIds.length > 0">
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

            <?php // Deferred actions (a.deferred) render DISABLED with a
                  // "volgt binnenkort" tooltip — a live button that fails 100%
                  // of rows reads as broken, not as a roadmap (F-G6). ?>
            <div class="ws-bulkbar__actions">
                <template x-for="(a, i) in topActions" :key="a.id">
                    <button class="ws-bbtn" :class="i===0 && !a.danger && !a.deferred ? 'ws-bbtn--primary' : (a.danger ? 'ws-bbtn--danger' : '')"
                            :disabled="busyAction || a.deferred"
                            :style="a.deferred ? 'opacity:.5;cursor:not-allowed' : ''"
                            :title="a.deferred ? '<?php echo esc_js(__('Volgt binnenkort — nog niet beschikbaar.', 'stride')); ?>' : a.label"
                            @click="!a.deferred && runBulk(a.id)">
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
                            <button class="ws-menu__item" :class="a.danger && 'ws-menu__item--danger'"
                                    :disabled="a.deferred"
                                    :style="a.deferred ? 'opacity:.5;cursor:not-allowed' : ''"
                                    :title="a.deferred ? '<?php echo esc_js(__('Volgt binnenkort — nog niet beschikbaar.', 'stride')); ?>' : a.label"
                                    @click="!a.deferred && runBulk(a.id)">
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

    <!-- ===== PAGINATION =====
         One pager for BOTH modes. Groups ARE server-paginated (LIMIT/OFFSET on
         the distinct group values); the old grouped-mode footer HID the pager
         and claimed "paginering uit", making groups beyond the first page
         silently unreachable (F-G5). -->
    <div class="ws-pager" x-show="!error && total > 0">
        <div class="ws-count"><?php echo esc_html__('Pagina', 'stride'); ?> <b x-text="page"></b> <?php echo esc_html__('van', 'stride'); ?> <b x-text="pageCount"></b><span x-show="groupBy"> — <?php echo esc_html__('groepen per pagina', 'stride'); ?></span></div>
        <div class="ws-pager__pages">
            <button class="ws-page-btn" :disabled="page===1" @click="goPage(page-1)"><span x-html="icon('chevRight')" style="transform:rotate(180deg);width:15px;height:15px"></span></button>
            <!-- :key is the INDEX — pageList() emits the '…' sentinel twice
                     mid-range, and duplicate keys corrupt Alpine's keyed
                     reconciliation (shared ws-pager contract fix). -->
            <template x-for="(p, pi) in pageList()" :key="pi">
                <template x-if="p === '…'"><span class="ws-page-ellipsis">…</span></template>
                <template x-if="p !== '…'"><button class="ws-page-btn" :class="p===page && 'is-active'" @click="goPage(p)" x-text="p"></button></template>
            </template>
            <button class="ws-page-btn" :disabled="page===pageCount" @click="goPage(page+1)"><span x-html="icon('chevRight')" style="width:15px;height:15px"></span></button>
        </div>
        <div class="ws-select-wrap"><?php echo esc_html__('Per pagina', 'stride'); ?>
            <select class="ws-select" x-model.number="perPage" @change="onPerPageChange()"><option>10</option><option>25</option><option>50</option></select>
        </div>
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
