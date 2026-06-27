<?php
/**
 * Admin Workspace — Offertes surface (cluster F).
 *
 * In-shell list of quotes. Its own per-surface Alpine factory
 * (assets/js/admin/offertes.js) owns ALL its data: fetches GET /admin/quotes
 * server-side, owns its loading/empty/error state, re-loads on filter/page
 * change. It tolerates the Phase-1-deferred items|data envelope client-side
 * (quoteRows) — the backend is FROZEN, never normalized here.
 *
 * Quote `status` is WORKFLOW status (Draft/Sent/Exported/Cancelled), NOT
 * payment. The server sends `statusLabel` AS RECEIVED (INV-7) — rendered
 * verbatim; badgeClass maps the VALUE to a hue class only.
 *
 * Mounted x-data="offertes()" inside wsShell() — inherits api/switchView/icon.
 * INV-5: x-html binds CONSTANT icon names only. Data via x-text.
 *
 * @package Stride\Admin
 */

defined('ABSPATH') || exit;
?>
<section class="ws-content ws-content--flush"
         x-show="view === 'offertes'"
         x-data="offertes()"
         @ws-refresh.window="if ($event.detail && $event.detail.view === 'offertes') reload()"
         x-cloak>

    <!-- ===== TOOLBAR ===== -->
    <div class="ws-toolbar">
        <div class="ws-toolbar__row">
            <span class="ws-toolbar__group-label"><?php echo esc_html__('Filters', 'stride'); ?></span>

            <div class="ws-search ws-search--inline">
                <span x-html="icon('search')"></span>
                <input type="text"
                       placeholder="<?php echo esc_attr__('Zoek op nummer, klant…', 'stride'); ?>"
                       x-model="filters.q" @input.debounce.350ms="onSearchChange()">
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
                <h3><?php echo esc_html__('Offertes niet geladen', 'stride'); ?></h3>
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
                    <th><?php echo esc_html__('Offerte', 'stride'); ?></th>
                    <th><?php echo esc_html__('Klant', 'stride'); ?></th>
                    <th><?php echo esc_html__('Editie', 'stride'); ?></th>
                    <th class="ws-col-status"><?php echo esc_html__('Status', 'stride'); ?></th>
                    <th style="text-align:right"><?php echo esc_html__('Bedrag', 'stride'); ?></th>
                </tr>
            </thead>
            <tbody>
                <template x-for="r in rows" :key="r.id">
                    <tr @click="openRow(r)">
                        <td>
                            <span class="ws-org-cell">
                                <span x-html="icon('receipt')"></span>
                                <span x-text="r.number || ('#'+r.id)"></span>
                            </span>
                        </td>
                        <td>
                            <div class="ws-namecell">
                                <div>
                                    <div class="ws-namecell__name" x-text="(r.user && r.user.name) || '—'"></div>
                                    <div class="ws-namecell__sub" x-text="(r.user && r.user.email) || ''"></div>
                                </div>
                            </div>
                        </td>
                        <td>
                            <template x-if="r.edition && r.edition.title">
                                <span x-text="r.edition.title"></span>
                            </template>
                            <template x-if="!(r.edition && r.edition.title)"><span class="ws-muted">—</span></template>
                        </td>
                        <td>
                            <span class="ws-badge" :class="'ws-badge--'+badgeClass(r.status)" x-text="r.statusLabel"></span>
                        </td>
                        <td style="text-align:right">
                            <b x-text="'€ ' + (r.totalFormatted || '0,00')"></b>
                        </td>
                    </tr>
                </template>
            </tbody>
        </table>

        <div class="ws-empty" x-show="!loading && !error && total === 0" style="padding-top:80px">
            <div class="ws-empty__icon" x-html="icon('receipt')"></div>
            <h3 x-text="emptyTitle()"></h3>
            <p><?php echo esc_html__('Pas de filters aan of wis ze om meer offertes te zien.', 'stride'); ?></p>
            <button class="ws-btn ws-btn--ghost" style="margin-top:16px" x-show="hasFilters" @click="clearAllFilters()">
                <span x-html="icon('x')"></span> <?php echo esc_html__('Filters wissen', 'stride'); ?>
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
