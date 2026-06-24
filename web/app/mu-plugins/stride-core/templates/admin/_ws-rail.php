<?php
/**
 * Admin Workspace — command rail (shared chrome partial).
 *
 * The dark left nav. Ported from the duplicated `<aside class="ws-rail">` block
 * in every docs/mockups/admin-workspace/*.html (there was no shared partial in
 * the mockup — this is the single source).
 *
 * Lives inside the `wsShell()` Alpine scope (see _ws-shell host in dashboard.php):
 *   - nav items switch the active surface via switchView('<view>') and mark
 *     themselves active via isActive('<view>') — no per-page hrefs.
 *   - icons are rendered via icon('<literal-name>') → constant ICONS map (INV-5;
 *     the bound argument is always a string literal, never a data field).
 *   - the user chip reads StrideConfig.user (Alpine x-text auto-escapes).
 *
 * @package Stride\Admin
 */

defined('ABSPATH') || exit;
?>
<aside class="ws-rail">
    <div class="ws-brand">
        <div class="ws-brand__mark" x-html="icon('layers')"></div>
        <div>
            <div class="ws-brand__name">Stride</div>
            <div class="ws-brand__sub">Workspace</div>
        </div>
    </div>

    <nav class="ws-nav">
        <div class="ws-nav__group">
            <div class="ws-nav__label">Werkbank</div>
            <a class="ws-nav__item" href="#" :class="isActive('vandaag') && 'is-active'" @click.prevent="switchView('vandaag')">
                <span x-html="icon('sun')"></span> Vandaag
            </a>
            <a class="ws-nav__item" href="#" :class="isActive('inschrijvingen') && 'is-active'" @click.prevent="switchView('inschrijvingen')">
                <span x-html="icon('grid')"></span> Inschrijvingen
            </a>
        </div>
        <div class="ws-nav__group">
            <div class="ws-nav__label">Beheer</div>
            <a class="ws-nav__item" href="#" :class="isActive('edities') && 'is-active'" @click.prevent="switchView('edities')">
                <span x-html="icon('layers')"></span> Edities
            </a>
            <a class="ws-nav__item" href="#" :class="isActive('sessies') && 'is-active'" @click.prevent="switchView('sessies')">
                <span x-html="icon('calendar')"></span> Sessies
            </a>
            <a class="ws-nav__item" href="#" :class="isActive('offertes') && 'is-active'" @click.prevent="switchView('offertes')">
                <span x-html="icon('receipt')"></span> Offertes
            </a>
            <a class="ws-nav__item" href="#" :class="isActive('trajecten') && 'is-active'" @click.prevent="switchView('trajecten')">
                <span x-html="icon('route')"></span> Trajecten
            </a>
            <a class="ws-nav__item" href="#" :class="isActive('gebruikers') && 'is-active'" @click.prevent="switchView('gebruikers')">
                <span x-html="icon('users')"></span> Gebruikers
            </a>
        </div>
    </nav>

    <div class="ws-rail__foot">
        <div class="ws-user">
            <div class="ws-user__avatar" x-text="(config.user.name || '?').trim().split(/\s+/).map(p => p[0] || '').slice(0, 2).join('').toUpperCase()"></div>
            <div>
                <div class="ws-user__name" x-text="config.user.name"></div>
                <div class="ws-user__role" x-text="config.canManage ? 'stride_manage' : 'stride_view'"></div>
            </div>
        </div>
    </div>
</aside>
