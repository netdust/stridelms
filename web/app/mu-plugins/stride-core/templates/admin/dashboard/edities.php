<?php
/**
 * Admin Workspace — Edities surface (cluster F).
 *
 * In-shell overview of scheduled offerings with TWO VIEWS (F-E1):
 *  - Agenda (default): ONE ROW PER SESSION DATE — dateless editions have no
 *    session rows and never appear here;
 *  - Lijst: ONE ROW PER EDITION, NULL-date-permitting (§10.7) — the only view
 *    where the sessionless interest-anchor editions are visible.
 * Its own per-surface Alpine factory (assets/js/admin/edities.js) owns ALL its
 * data: fetches GET /admin/editions server-side (paging/filter server-owned),
 * owns its loading/empty/error state, re-loads on every filter/page change.
 *
 * Mounted with x-data="edities()" INSIDE the shell's wsShell() scope, so it
 * inherits api(), switchView(), icon() (Alpine v3 nested-scope).
 *
 * SCOPE (F-E2): "Aankomend" (default — the server's 2-day-lookback cutoff,
 * now a visible toggle pill) | "Alles". FILTERS: Search (edition title) +
 * Status (OfferingStatus dropdown, matched against the EFFECTIVE status the
 * badge renders) + Tag (one dropdown from /admin/course-tags `.tag`) + a
 * single flatpickr field (single date OR range, with a clear ✕).
 *
 * INV-5: every x-html binds a CONSTANT icon name via icon('<literal>'); never a
 * data field. Titles/dates/labels render via x-text (auto-escaped).
 * INV-7: status value rendered AS RECEIVED (effective status); label + hue are
 * presentation-only closed-enum lookups over the OfferingStatus set.
 *
 * @package Stride\Admin
 */

defined('ABSPATH') || exit;
?>
<section class="ws-content ws-content--flush"
         x-show="view === 'edities'"
         x-data="edities()"
         @ws-refresh.window="if ($event.detail && $event.detail.view === 'edities') reload()"
         x-cloak>

    <!-- ===== TOOLBAR ===== -->
    <div class="ws-toolbar">
        <div class="ws-toolbar__row">
            <!-- view toggle (F-E1): Agenda = one row per session date (dateless
                 editions have no session rows); Lijst = one row per edition,
                 the ONLY view where dateless interest-anchor editions appear.
                 Static tooltips → plain title= (esc_attr, HTML context). -->
            <span class="ws-chip" :class="viewMode==='agenda' && 'is-active'" @click="setViewMode('agenda')"
                  title="<?php echo esc_attr__('Eén rij per sessiedatum', 'stride'); ?>">
                <span x-html="icon('calendar')" style="width:13px;height:13px"></span>
                <?php echo esc_html__('Agenda', 'stride'); ?>
            </span>
            <span class="ws-chip" :class="viewMode==='list' && 'is-active'" @click="setViewMode('list')"
                  title="<?php echo esc_attr__('Eén rij per editie, ook edities zonder datum', 'stride'); ?>">
                <span x-html="icon('grid')" style="width:13px;height:13px"></span>
                <?php echo esc_html__('Lijst', 'stride'); ?>
            </span>

            <!-- scope pill (F-E2): the 2-day-lookback default cutoff, visible
                 and escapable instead of silent. Hidden while a date filter is
                 active — the server ignores scope then (the explicit range IS
                 the scope), and a toggleable no-op pill reads as broken.
                 Strings inside the Alpine :title EXPRESSION are JS-string
                 context → esc_js (an esc_attr'd apostrophe HTML-decodes back
                 to a raw quote and breaks the expression). x-html icons stay
                 CONSTANT literals per INV-5 — two x-show'd spans, never a
                 dynamic icon(cond ? … : …) expression. -->
            <span class="ws-chip" style="margin-left:var(--ws-3)" x-show="!filters.dateFrom"
                  :class="scope==='upcoming' && 'is-active'" @click="toggleScope()"
                  :title="scope==='upcoming' ? '<?php echo esc_js(__('Toon ook eerdere edities', 'stride')); ?>' : '<?php echo esc_js(__('Beperk tot aankomende edities', 'stride')); ?>'">
                <span x-show="scope==='upcoming'" x-html="icon('check')" style="width:13px;height:13px"></span>
                <span x-show="scope!=='upcoming'" x-html="icon('archive')" style="width:13px;height:13px"></span>
                <span x-text="scope==='upcoming' ? '<?php echo esc_js(__('Aankomend', 'stride')); ?>' : '<?php echo esc_js(__('Alles', 'stride')); ?>'"></span>
            </span>

            <div class="ws-search ws-search--inline" style="margin-left:var(--ws-3)">
                <span x-html="icon('search')"></span>
                <input type="text"
                       placeholder="<?php echo esc_attr__('Zoek op titel…', 'stride'); ?>"
                       x-model="filters.q" @input.debounce.350ms="onSearchChange()">
            </div>

            <div class="ws-select-wrap">
                <span x-html="icon('filter')" style="width:14px;height:14px"></span>
                <?php echo esc_html__('Status', 'stride'); ?>
                <select class="ws-select" x-model="filters.status" @change="onStatusChange()">
                    <option value=""><?php echo esc_html__('Alle statussen', 'stride'); ?></option>
                    <?php
                    // F-E2: the filter speaks the SAME vocabulary the badge
                    // renders — the OfferingStatus enum, matched server-side
                    // against the EFFECTIVE status.
                    foreach (\Stride\Domain\OfferingStatus::cases() as $offeringStatus) : ?>
                        <option value="<?php echo esc_attr($offeringStatus->value); ?>"><?php echo esc_html($offeringStatus->label()); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="ws-select-wrap">
                <span x-html="icon('filter')" style="width:14px;height:14px"></span>
                <?php echo esc_html__('Tag', 'stride'); ?>
                <select class="ws-select" x-model="filters.tag" @change="onFilterChange()">
                    <option value=""><?php echo esc_html__('Alle tags', 'stride'); ?></option>
                    <template x-for="o in tagOptions" :key="o.id"><option :value="o.id" x-text="o.name"></option></template>
                </select>
            </div>

            <div class="ws-select-wrap">
                <span x-html="icon('calendar')" style="width:14px;height:14px"></span>
                <input type="text"
                       class="ws-select"
                       x-ref="dateInput"
                       readonly
                       placeholder="<?php echo esc_attr__('Datum of periode…', 'stride'); ?>">
            </div>

            <button class="ws-btn ws-btn--subtle ws-btn--sm" x-show="hasFilters" @click="clearAllFilters()">
                <span x-html="icon('x')"></span> <?php echo esc_html__('Filters wissen', 'stride'); ?>
            </button>

            <div class="ws-toolbar__spacer"></div>
            <div class="ws-count">
                <?php echo esc_html__('Toont', 'stride'); ?> <b x-text="rangeFrom"></b>–<b x-text="rangeTo"></b>
                <?php echo esc_html__('van', 'stride'); ?> <b x-text="total.toLocaleString('nl-BE')"></b>
            </div>
        </div>
    </div>

    <!-- ===== TABLE ===== -->
    <div class="ws-tablewrap">

        <template x-if="error">
            <div class="ws-empty" style="padding-top:64px">
                <div class="ws-empty__icon" x-html="icon('alert')"></div>
                <h3><?php echo esc_html__('Edities niet geladen', 'stride'); ?></h3>
                <p x-text="error"></p>
                <button class="ws-btn ws-btn--ghost" style="margin-top:16px" @click="reload()">
                    <span x-html="icon('refresh')"></span> <?php echo esc_html__('Opnieuw proberen', 'stride'); ?>
                </button>
            </div>
        </template>

        <template x-if="loading && !error">
            <div class="ws-empty" style="padding-top:64px"><p><?php echo esc_html__('Laden…', 'stride'); ?></p></div>
        </template>

        <table class="ws-table" x-show="!loading && !error && total > 0">
            <thead>
                <tr>
                    <th><?php echo esc_html__('Editie', 'stride'); ?></th>
                    <th><?php echo esc_html__('Datum', 'stride'); ?></th>
                    <th class="ws-col-status"><?php echo esc_html__('Status', 'stride'); ?></th>
                    <th><?php echo esc_html__('Inschrijvingen', 'stride'); ?></th>
                    <th><?php echo esc_html__('Locatie', 'stride'); ?></th>
                    <th style="text-align:right;white-space:nowrap"></th>
                </tr>
            </thead>
            <tbody>
                <!-- rowKey: agenda rows are sessions ('s'+sessionId), list rows
                     are editions ('e'+id) — disjoint key spaces across the
                     view toggle. -->
                <template x-for="r in rows" :key="rowKey(r)">
                    <tr @click="openRow(r)">
                        <td>
                            <div class="ws-namecell">
                                <div class="ws-namecell__avatar" style="background:#3b82f6" x-html="icon('grid')"></div>
                                <div>
                                    <div class="ws-namecell__name" x-text="r.title || '—'"></div>
                                    <div class="ws-namecell__sub" x-text="(r.course && r.course.title) || ''"></div>
                                </div>
                            </div>
                        </td>
                        <td>
                            <span class="ws-org-cell">
                                <span x-html="icon('calendar')"></span>
                                <span>
                                    <span x-text="dateText(r) || '<?php echo esc_js(__('Geen datum', 'stride')); ?>'"></span>
                                    <span class="ws-muted" x-show="timeLabel(r)" x-text="' · ' + timeLabel(r)"></span>
                                </span>
                            </span>
                        </td>
                        <td>
                            <span class="ws-badge" :class="'ws-badge--'+badgeClass(r.status)" x-text="statusLabel(r.status)"></span>
                        </td>
                        <td>
                            <div class="ws-meter" x-show="r.capacity">
                                <div class="ws-meter__track"><div class="ws-meter__fill" :style="`width:${fillPct(r)}%`"></div></div>
                                <span class="ws-meter__val" x-text="capLabel(r)"></span>
                            </div>
                            <span class="ws-meter__val" x-show="!r.capacity" x-text="capLabel(r)"></span>
                        </td>
                        <td>
                            <template x-if="r.venue">
                                <span class="ws-org-cell"><span x-html="icon('mapPin')"></span><span x-text="r.venue"></span></span>
                            </template>
                            <template x-if="!r.venue"><span class="ws-muted">—</span></template>
                        </td>
                        <td style="text-align:right;white-space:nowrap">
                            <button class="ws-btn ws-btn--ghost ws-btn--sm" @click="openCohort(r, $event)" title="<?php echo esc_attr__('Rooster bekijken', 'stride'); ?>">
                                <span x-html="icon('users')"></span> <?php echo esc_html__('Rooster', 'stride'); ?>
                            </button>
                        </td>
                    </tr>
                </template>
            </tbody>
        </table>

        <div class="ws-empty" x-show="!loading && !error && total === 0" style="padding-top:80px">
            <div class="ws-empty__icon" x-html="icon('grid')"></div>
            <h3 x-text="emptyTitle()"></h3>
            <!-- three-way copy: every branch offers an ACTIONABLE control or
                 states the honest end (never "clear the filters" with no
                 filters set and no button rendered). -->
            <p x-show="hasFilters"><?php echo esc_html__('Pas de filters aan of wis ze om meer edities te zien.', 'stride'); ?></p>
            <!-- scope-aware empty state (F-E2): the silent 2-day cutoff may be
                 exactly why the list is empty — say so and offer the way out. -->
            <p x-show="!hasFilters && scope === 'upcoming'"><?php echo esc_html__('Alleen aankomende edities worden standaard getoond.', 'stride'); ?></p>
            <p x-show="!hasFilters && scope !== 'upcoming'"><?php echo esc_html__('Er zijn nog geen edities.', 'stride'); ?></p>
            <button class="ws-btn ws-btn--ghost" style="margin-top:16px" x-show="hasFilters" @click="clearAllFilters()">
                <span x-html="icon('x')"></span> <?php echo esc_html__('Filters wissen', 'stride'); ?>
            </button>
            <button class="ws-btn ws-btn--ghost" style="margin-top:16px" x-show="!hasFilters && scope === 'upcoming'" @click="toggleScope()">
                <span x-html="icon('archive')"></span> <?php echo esc_html__('Toon ook eerdere edities', 'stride'); ?>
            </button>
        </div>
    </div>

    <!-- ===== PAGINATION ===== -->
    <div class="ws-pager" x-show="!error && total > 0">
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
</section>
