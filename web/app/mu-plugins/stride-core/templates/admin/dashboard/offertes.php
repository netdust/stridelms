<?php
/**
 * Admin Workspace — Offertes surface (cluster F).
 *
 * In-shell list of quotes. Its own per-surface Alpine factory
 * (assets/js/admin/offertes.js) owns ALL its data: fetches GET /admin/quotes
 * server-side, owns its loading/empty/error state, re-loads on filter/page
 * change. The backend emits ONE envelope on every path (the zero-user-search
 * divergence was removed at the Offertes slice, F-A8); quoteRows() stays as
 * defensive tolerance only.
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
                <?php echo esc_html__('Status', 'stride'); ?>
                <select class="ws-select" x-model="filters.status" @change="onFilterChange()">
                    <option value=""><?php echo esc_html__('Alle statussen', 'stride'); ?></option>
                    <?php
                    // The filter speaks the SAME vocabulary the badge renders —
                    // the QuoteStatus enum (workflow status, not payment).
                    foreach (\Stride\Domain\QuoteStatus::cases() as $quoteStatus) : ?>
                        <option value="<?php echo esc_attr($quoteStatus->value); ?>"><?php echo esc_html($quoteStatus->label()); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="ws-select-wrap"
                 title="<?php echo esc_attr__('Filtert via de cursustag van de gekoppelde editie — offertes zonder editie vallen buiten elke tag.', 'stride'); ?>">
                <span x-html="icon('filter')" style="width:14px;height:14px"></span>
                <?php echo esc_html__('Editietag', 'stride'); ?>
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
                    <th><?php echo esc_html__('Datum', 'stride'); ?></th>
                    <th class="ws-col-status"><?php echo esc_html__('Status', 'stride'); ?></th>
                    <th style="text-align:right"><?php echo esc_html__('Bedrag', 'stride'); ?></th>
                    <th style="text-align:right;white-space:nowrap"></th>
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
                        <!-- Datum (F-O2): the date filter finally filters a
                             VISIBLE column. Server-owned Dutch label. -->
                        <td>
                            <span class="ws-org-cell">
                                <span x-html="icon('calendar')"></span>
                                <span x-text="r.dateLabel || '—'"></span>
                            </span>
                        </td>
                        <td>
                            <span class="ws-badge" :class="'ws-badge--'+badgeClass(r.status)" x-text="r.statusLabel"></span>
                            <!-- lock (F-O1): finalized on the edit screen —
                                 the admin sees a row is read-only BEFORE
                                 clicking through. inline-flex so the 13px box
                                 actually applies (a bare inline span ignores
                                 width/height and the SVG blows up to the
                                 cell). -->
                            <span x-show="r.locked" x-html="icon('lock')"
                                  style="display:inline-flex;width:13px;height:13px;vertical-align:middle;margin-left:4px;color:var(--ws-text-3)"
                                  title="<?php echo esc_attr__('Vergrendeld — niet meer bewerkbaar', 'stride'); ?>"></span>
                        </td>
                        <td style="text-align:right">
                            <b x-text="'€ ' + (r.totalFormatted || '0,00')"></b>
                        </td>
                        <!-- row actions (F-O1): dossier jump + an HONEST edit
                             affordance (the row click already navigates to the
                             WP edit screen — the quote workbench; now it says
                             so instead of being a surprise). -->
                        <td style="text-align:right;white-space:nowrap">
                            <button class="ws-btn ws-btn--ghost ws-btn--sm" @click="openPerson(r, $event)"
                                    :disabled="!(r.user && r.user.id)"
                                    title="<?php echo esc_attr__('Open het dossier van deze klant', 'stride'); ?>">
                                <span x-html="icon('users')"></span> <?php echo esc_html__('Dossier', 'stride'); ?>
                            </button>
                            <!-- .stop: without it the click bubbles to the
                                 row's @click="openRow" and fires twice.
                                 Locked rows say Bekijken — the workbench
                                 opens read-only there, and a button promising
                                 write actions next to a lock icon is the
                                 exact surprise F-O1 removes. Strings inside
                                 the Alpine expressions are JS-string
                                 context → esc_js. -->
                            <button class="ws-btn ws-btn--ghost ws-btn--sm" @click.stop="openRow(r)"
                                    :title="r.locked ? '<?php echo esc_js(__('Opent het bewerkscherm (alleen-lezen — vergrendeld)', 'stride')); ?>' : '<?php echo esc_js(__('Opent het bewerkscherm (verzenden, status, voucher, PDF)', 'stride')); ?>'">
                                <span x-html="icon('edit')"></span>
                                <span x-text="r.locked ? '<?php echo esc_js(__('Bekijken', 'stride')); ?>' : '<?php echo esc_js(__('Bewerken', 'stride')); ?>'"></span>
                            </button>
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
