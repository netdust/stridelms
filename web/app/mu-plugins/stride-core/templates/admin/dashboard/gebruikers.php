<?php
/**
 * Admin Workspace — Gebruikers search surface (cluster F).
 *
 * A search-DRIVEN surface (not a paged list). Its own per-surface Alpine
 * factory (assets/js/admin/gebruikers.js) queries GET /admin/users/search?q=
 * which returns a BARE ARRAY of {id,name,email,organisation,registration_count}.
 * On an empty query it shows a "search for a user" prompt — not an error, not a
 * request. Row click → switchView('dossier', {user:u.id}) (reuses cluster-D).
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
                       x-model="query" @input.debounce.350ms="search()">
            </div>

            <button class="ws-btn ws-btn--subtle ws-btn--sm" x-show="query" @click="clearSearch()">
                <span x-html="icon('x')"></span> <?php echo esc_html__('Wissen', 'stride'); ?>
            </button>

            <div class="ws-toolbar__spacer"></div>
            <div class="ws-count" x-show="searched && rows.length > 0">
                <b x-text="rows.length"></b> <?php echo esc_html__('resultaten', 'stride'); ?>
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

        <!-- search prompt (no query yet) -->
        <div class="ws-empty" x-show="showPrompt" style="padding-top:80px">
            <div class="ws-empty__icon" x-html="icon('search')"></div>
            <h3><?php echo esc_html__('Zoek een gebruiker', 'stride'); ?></h3>
            <p><?php echo esc_html__('Typ een naam, e-mailadres of login om gebruikers te vinden.', 'stride'); ?></p>
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
                                    <div class="ws-namecell__name" x-text="u.name"></div>
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
</section>
