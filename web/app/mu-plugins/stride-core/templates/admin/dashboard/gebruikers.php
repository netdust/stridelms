<?php
/**
 * Admin Workspace — Gebruikers search surface (cluster F).
 *
 * A search-DRIVEN surface, PAGED since the Gebruikers slice (F-U1). Its own
 * per-surface Alpine factory (assets/js/admin/gebruikers.js) queries
 * GET /admin/users/search?q=&page=&per_page= which returns the standard
 * envelope of {id,name,email,organisation,registration_count,anonymised}
 * items. On an empty or too-short (<2 chars) query it shows the prompt —
 * not an error, not a request. Anonymised (GDPR-scrubbed) accounts carry a
 * "Geanonimiseerd" badge. Row click → switchView('dossier', {user:u.id}).
 *
 * Mounted x-data="gebruikers()" inside wsShell() — inherits api/switchView/icon.
 * INV-5: x-html binds CONSTANT icon names only. Names/emails/orgs via x-text.
 *
 * @package Stride\Admin
 */

defined('ABSPATH') || exit;
?>
<section class="ws-content ws-content--flush"
         x-show="view === 'gebruikers'"
         x-data="gebruikers()"
         @ws-refresh.window="if ($event.detail && $event.detail.view === 'gebruikers') reload()"
         x-cloak>

    <!-- ===== TOOLBAR ===== -->
    <div class="ws-toolbar">
        <div class="ws-toolbar__row">
            <span class="ws-toolbar__group-label"><?php echo esc_html__('Zoek gebruiker', 'stride'); ?></span>

            <div class="ws-search ws-search--inline">
                <span x-html="icon('search')"></span>
                <input type="text"
                       placeholder="<?php echo esc_attr__('Zoek op naam, e-mail of login…', 'stride'); ?>"
                       x-model="query" @input.debounce.350ms="onQueryChange()">
            </div>

            <button class="ws-btn ws-btn--subtle ws-btn--sm" x-show="query" @click="clearSearch()">
                <span x-html="icon('x')"></span> <?php echo esc_html__('Wissen', 'stride'); ?>
            </button>

            <div class="ws-toolbar__spacer"></div>
            <!-- honest count (F-U1: "10 resultaten" presented a capped page
                 as the complete set) — the true total, ranged. -->
            <div class="ws-count" x-show="searched && total > 0">
                <?php echo esc_html__('Toont', 'stride'); ?> <b x-text="rangeFrom"></b>–<b x-text="rangeTo"></b>
                <?php echo esc_html__('van', 'stride'); ?> <b x-text="total.toLocaleString('nl-BE')"></b>
                <?php echo esc_html__('gebruikers', 'stride'); ?>
            </div>
        </div>
    </div>

    <!-- ===== RESULTS ===== -->
    <div class="ws-tablewrap">

        <template x-if="error">
            <div class="ws-empty" style="padding-top:64px">
                <div class="ws-empty__icon" x-html="icon('alert')"></div>
                <h3><?php echo esc_html__('Zoeken mislukt', 'stride'); ?></h3>
                <p x-text="error"></p>
                <button class="ws-btn ws-btn--ghost" style="margin-top:16px" @click="reload()">
                    <span x-html="icon('refresh')"></span> <?php echo esc_html__('Opnieuw proberen', 'stride'); ?>
                </button>
            </div>
        </template>

        <template x-if="loading && !error">
            <div class="ws-empty" style="padding-top:64px"><p><?php echo esc_html__('Zoeken…', 'stride'); ?></p></div>
        </template>

        <!-- search prompt (no query yet, or shorter than the 2-char minimum —
             the client guards, so the server's 400 never flashes, F-U1) -->
        <div class="ws-empty" x-show="showPrompt" style="padding-top:80px">
            <div class="ws-empty__icon" x-html="icon('search')"></div>
            <h3><?php echo esc_html__('Zoek een gebruiker', 'stride'); ?></h3>
            <p><?php echo esc_html__('Typ minstens 2 tekens van een naam, e-mailadres of login.', 'stride'); ?></p>
        </div>

        <!-- no results -->
        <div class="ws-empty" x-show="showEmpty" style="padding-top:80px">
            <div class="ws-empty__icon" x-html="icon('users')"></div>
            <h3><?php echo esc_html__('Geen gebruikers gevonden', 'stride'); ?></h3>
            <p><?php echo esc_html__('Probeer een andere zoekterm.', 'stride'); ?></p>
        </div>

        <table class="ws-table" x-show="!loading && !error && searched && rows.length > 0">
            <thead>
                <tr>
                    <th><?php echo esc_html__('Naam', 'stride'); ?></th>
                    <th><?php echo esc_html__('Organisatie', 'stride'); ?></th>
                    <th><?php echo esc_html__('Inschrijvingen', 'stride'); ?></th>
                </tr>
            </thead>
            <tbody>
                <template x-for="u in rows" :key="u.id">
                    <tr @click="openRow(u)">
                        <td>
                            <div class="ws-namecell">
                                <div class="ws-namecell__avatar" style="background:#6366f1" x-text="initials(u.name)"></div>
                                <div>
                                    <div class="ws-namecell__name">
                                        <span x-text="u.name"></span>
                                        <!-- GDPR-scrubbed account kept for history (F-U1):
                                             flagged, so it never reads as an odd real person. -->
                                        <span class="ws-badge ws-badge--cancelled" x-show="u.anonymised"
                                              style="margin-left:6px"
                                              title="<?php echo esc_attr__('GDPR-geanonimiseerd account — persoonsgegevens zijn gewist', 'stride'); ?>"><?php echo esc_html__('Geanonimiseerd', 'stride'); ?></span>
                                    </div>
                                    <div class="ws-namecell__sub" x-text="u.email"></div>
                                </div>
                            </div>
                        </td>
                        <td>
                            <template x-if="u.organisation">
                                <span class="ws-org-cell"><span x-html="icon('building')"></span><span x-text="u.organisation"></span></span>
                            </template>
                            <template x-if="!u.organisation"><span class="ws-muted">—</span></template>
                        </td>
                        <td>
                            <span class="ws-org-cell"><span x-html="icon('ticket')"></span><span x-text="u.registration_count"></span></span>
                        </td>
                    </tr>
                </template>
            </tbody>
        </table>
    </div>

    <!-- ===== PAGINATION (F-U1: the capped 10 had no way to reach the rest) ===== -->
    <div class="ws-pager" x-show="!error && searched && pageCount > 1">
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
</section>
