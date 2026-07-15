<?php
/**
 * Admin Workspace — global search palette (⌘K, Phase 3c / F-S1).
 *
 * A centered overlay palette searching the THREE existing typeahead endpoints
 * in parallel (persons / edities / trajecten — no new server surface). A hit
 * routes through the shell's switchView deep-link whitelist: person → dossier,
 * editie/traject → the grid scoped to it. Its Alpine factory lives in
 * assets/js/admin/gsearch.js; mounted INSIDE wsShell() so it inherits
 * api()/switchView()/icon(). Opened via ⌘K/Ctrl+K (window keydown in init)
 * or the ws-gsearch-open event the topbar search box dispatches.
 *
 * INV-5: every x-html binds a CONSTANT icon name via icon('<literal>').
 * Names/titles render via x-text (auto-escaped). INV-7: labels AS RECEIVED.
 *
 * @package Stride\Admin
 */

defined('ABSPATH') || exit;
?>
<div x-data="gsearch()"
     x-init="init()"
     @ws-gsearch-open.window="openPalette()"
     @keydown.escape.window="open && close()"
     x-cloak>
    <template x-if="open">
        <div>
            <div class="ws-overlay" @click="close()"></div>
            <div class="ws-palette" role="dialog" aria-modal="true" aria-label="<?php echo esc_attr__('Zoeken', 'stride'); ?>">
                <div class="ws-palette__input">
                    <span x-html="icon('search')"></span>
                    <input type="text" x-ref="gsearchInput"
                           placeholder="<?php echo esc_attr__('Zoek persoon, editie of traject… (min. 2 tekens)', 'stride'); ?>"
                           x-model="q"
                           @input.debounce.300ms="search()"
                           @keydown.down.prevent="move(1)"
                           @keydown.up.prevent="move(-1)"
                           @keydown.enter.prevent="pickActive()">
                    <kbd>Esc</kbd>
                </div>

                <div class="ws-palette__body">
                    <template x-if="loading">
                        <p class="ws-muted" style="padding:12px 16px;margin:0"><?php echo esc_html__('Zoeken…', 'stride'); ?></p>
                    </template>

                    <template x-if="!loading && searched && !hasResults">
                        <p class="ws-muted" style="padding:12px 16px;margin:0"><?php echo esc_html__('Geen resultaten.', 'stride'); ?></p>
                    </template>

                    <template x-if="!loading && hasResults">
                        <div>
                            <!-- Personen -->
                            <div x-show="results.users.length > 0">
                                <div class="ws-palette__group"><?php echo esc_html__('Personen', 'stride'); ?></div>
                                <template x-for="(u, i) in results.users" :key="'u'+u.id">
                                    <button type="button" class="ws-palette__hit"
                                            :class="{ 'is-active': active === flatIndex('users', i) }"
                                            @mouseenter="active = flatIndex('users', i)"
                                            @click="pick('users', u)">
                                        <span x-html="icon('user')"></span>
                                        <span class="ws-palette__hit-main">
                                            <span x-text="u.name"></span>
                                            <span class="ws-muted" x-text="u.email"></span>
                                        </span>
                                    </button>
                                </template>
                            </div>
                            <!-- Edities -->
                            <div x-show="results.editions.length > 0">
                                <div class="ws-palette__group"><?php echo esc_html__('Edities', 'stride'); ?></div>
                                <template x-for="(e, i) in results.editions" :key="'e'+e.id">
                                    <button type="button" class="ws-palette__hit"
                                            :class="{ 'is-active': active === flatIndex('editions', i) }"
                                            @mouseenter="active = flatIndex('editions', i)"
                                            @click="pick('editions', e)">
                                        <span x-html="icon('grid')"></span>
                                        <span class="ws-palette__hit-main"><span x-text="e.title"></span></span>
                                    </button>
                                </template>
                            </div>
                            <!-- Trajecten -->
                            <div x-show="results.trajectories.length > 0">
                                <div class="ws-palette__group"><?php echo esc_html__('Trajecten', 'stride'); ?></div>
                                <template x-for="(t, i) in results.trajectories" :key="'t'+t.id">
                                    <button type="button" class="ws-palette__hit"
                                            :class="{ 'is-active': active === flatIndex('trajectories', i) }"
                                            @mouseenter="active = flatIndex('trajectories', i)"
                                            @click="pick('trajectories', t)">
                                        <span x-html="icon('route')"></span>
                                        <span class="ws-palette__hit-main"><span x-text="t.title"></span></span>
                                    </button>
                                </template>
                            </div>
                        </div>
                    </template>

                    <!-- Per-group failures: the OTHER groups still render. -->
                    <template x-if="!loading && (failed.users || failed.editions || failed.trajectories)">
                        <p class="ws-muted" style="padding:8px 16px;margin:0;font-size:var(--ws-fs-sm)">
                            <?php echo esc_html__('Een deel van de zoekopdracht is mislukt — de getoonde groepen zijn volledig.', 'stride'); ?>
                        </p>
                    </template>
                </div>
            </div>
        </div>
    </template>
</div>
