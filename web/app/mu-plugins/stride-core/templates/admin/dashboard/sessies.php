<?php
/**
 * Admin Workspace — Sessies (agenda) surface (cluster F).
 *
 * There is NO standalone sessions endpoint. This surface renders the agenda
 * view of GET /admin/editions?view=agenda — ONE ROW PER SESSION DATE — as a
 * date-grouped agenda. Its own per-surface Alpine factory
 * (assets/js/admin/sessies.js) owns ALL its data, server-paged by session date,
 * and owns its loading/empty/error state.
 *
 * Mounted x-data="sessies()" inside wsShell() — inherits api/switchView/icon.
 * INV-5: x-html binds CONSTANT icon names only. Dates/titles via x-text.
 * INV-7: edition status value rendered AS RECEIVED; label/hue presentation-only.
 *
 * @package Stride\Admin
 */

defined('ABSPATH') || exit;
?>
<section class="ws-content ws-content--flush"
         x-show="view === 'sessies'"
         x-data="sessies()"
         @ws-refresh.window="if ($event.detail && $event.detail.view === 'sessies') reload()"
         x-cloak>

    <!-- ===== TOOLBAR ===== -->
    <div class="ws-toolbar">
        <div class="ws-toolbar__row">
            <span class="ws-toolbar__group-label"><?php echo esc_html__('Agenda', 'stride'); ?></span>

            <div class="ws-search ws-search--inline">
                <span x-html="icon('search')"></span>
                <input type="text"
                       placeholder="<?php echo esc_attr__('Zoek op editie…', 'stride'); ?>"
                       x-model="filters.q" @input.debounce.350ms="onSearchChange()">
            </div>

            <button class="ws-btn ws-btn--subtle ws-btn--sm" x-show="hasFilters" @click="clearAllFilters()">
                <span x-html="icon('x')"></span> <?php echo esc_html__('Wissen', 'stride'); ?>
            </button>

            <div class="ws-toolbar__spacer"></div>
            <div class="ws-count">
                <?php echo esc_html__('Toont', 'stride'); ?> <b x-text="rangeFrom"></b>–<b x-text="rangeTo"></b>
                <?php echo esc_html__('van', 'stride'); ?> <b x-text="total.toLocaleString('nl-BE')"></b> <?php echo esc_html__('sessies', 'stride'); ?>
            </div>
        </div>
    </div>

    <!-- ===== AGENDA ===== -->
    <div class="ws-tablewrap">

        <template x-if="error">
            <div class="ws-empty" style="padding-top:64px">
                <div class="ws-empty__icon" x-html="icon('alert')"></div>
                <h3><?php echo esc_html__('Agenda niet geladen', 'stride'); ?></h3>
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
                    <th style="width:130px"><?php echo esc_html__('Tijd', 'stride'); ?></th>
                    <th><?php echo esc_html__('Editie', 'stride'); ?></th>
                    <th class="ws-col-status"><?php echo esc_html__('Status', 'stride'); ?></th>
                    <th><?php echo esc_html__('Locatie', 'stride'); ?></th>
                </tr>
            </thead>
            <tbody>
                <template x-for="day in days" :key="day.date">
                    <template x-for="(r, i) in day.rows" :key="r.sessionId">
                        <tr @click="openRow(r)">
                            <td>
                                <!-- day header on the first row of each day bucket -->
                                <template x-if="i === 0">
                                    <span class="ws-grouphead__title" style="display:flex;align-items:center;gap:6px">
                                        <span x-html="icon('calendar')" style="width:14px;height:14px"></span>
                                        <span x-text="dayLabel(day.date)"></span>
                                    </span>
                                </template>
                                <span class="ws-org-cell" :style="i === 0 ? 'margin-top:4px' : ''">
                                    <span x-html="icon('clock')"></span>
                                    <span x-text="timeRange(r) || '—'"></span>
                                </span>
                            </td>
                            <td>
                                <div class="ws-namecell__name" x-text="r.title || '—'"></div>
                                <div class="ws-namecell__sub" x-text="r.sessionTitle || ''"></div>
                            </td>
                            <td class="ws-col-status">
                                <span class="ws-badge" :class="'ws-badge--'+badgeClass(r.status)" x-text="statusLabel(r.status)"></span>
                            </td>
                            <td>
                                <template x-if="r.venue">
                                    <span class="ws-org-cell"><span x-html="icon('mapPin')"></span><span x-text="r.venue"></span></span>
                                </template>
                                <template x-if="!r.venue"><span class="ws-muted">—</span></template>
                            </td>
                        </tr>
                    </template>
                </template>
            </tbody>
        </table>

        <div class="ws-empty" x-show="!loading && !error && total === 0" style="padding-top:80px">
            <div class="ws-empty__icon" x-html="icon('calendar')"></div>
            <h3 x-text="emptyTitle()"></h3>
            <p><?php echo esc_html__('Geplande sessiedagen verschijnen hier zodra ze in de agenda staan.', 'stride'); ?></p>
            <button class="ws-btn ws-btn--ghost" style="margin-top:16px" x-show="hasFilters" @click="clearAllFilters()">
                <span x-html="icon('x')"></span> <?php echo esc_html__('Wissen', 'stride'); ?>
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
