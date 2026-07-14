<?php
/**
 * Admin Workspace — Vandaag surface (cluster B).
 *
 * The launcher / workbench home. Ported from docs/mockups/admin-workspace/
 * vandaag.html, rebound from the mock WS.* fixtures to the live per-surface
 * Alpine factory in assets/js/admin/vandaag.js.
 *
 * The `vandaag()` factory OWNS loading ALL of its own data in init() — stat
 * strip + 5 queues (GET /admin/stats), and the Acties-nodig panel
 * (GET /admin/pending-approvals + GET /admin/action-queue). There is no shared
 * loader: "landed on Vandaag but a panel is empty" is structurally impossible.
 * Each panel carries its own loading / empty / error state.
 *
 * Scope: this section is mounted with `x-data="vandaag()"` INSIDE the shell's
 * `wsShell()` scope, so it inherits api(), switchView(), icon() from the shell
 * (Alpine v3 nested-component scope chain). It listens for the topbar's
 * `ws-refresh` window event and re-loads when Vandaag is the active surface.
 *
 * INV-5: every x-html binds a CONSTANT icon name — WS.icon('<literal>') for
 * fixed glyphs, or icon(<closed-enum field>) where the field (s.icon, q.icon,
 * q.actionIcon) is assigned ONLY from the fixed table in vandaag.js. No x-html
 * ever binds a free-text data field; person names/meta use x-text (escaped).
 * INV-7: stat/queue counts + labels render AS RECEIVED from the backend.
 *
 * @package Stride\Admin
 */

defined('ABSPATH') || exit;
?>
<section class="ws-content"
         x-show="view === 'vandaag'"
         x-data="vandaag()"
         @ws-refresh.window="if ($event.detail && $event.detail.view === 'vandaag') pulse()"
         x-cloak>
    <div class="ws-stagger">

        <!-- Page head -->
        <div class="ws-page-head">
            <div>
                <span class="ws-eyebrow"><?php echo esc_html__('Werklijst', 'stride'); ?> · <span x-text="today"></span></span>
                <h1><?php echo esc_html__('Goeiemorgen.', 'stride'); ?></h1>
                <p>
                    <?php echo esc_html__('Je hebt', 'stride'); ?>
                    <b x-text="totalActions"></b>
                    <?php echo esc_html__('openstaande acties verdeeld over', 'stride'); ?>
                    <b x-text="queues.length"></b>
                    <?php echo esc_html__('wachtrijen.', 'stride'); ?>
                </p>
            </div>
            <a class="ws-btn ws-btn--ghost" href="#" @click.prevent="switchView('inschrijvingen')">
                <span x-html="icon('grid')"></span> <?php echo esc_html__('Open alle inschrijvingen', 'stride'); ?>
            </a>
        </div>

        <!-- Stat strip -->
        <div class="ws-statstrip">
            <!-- error state for the stats call -->
            <template x-if="errors.stats">
                <div class="ws-empty" style="grid-column:1/-1;padding:24px">
                    <div class="ws-empty__icon" x-html="icon('alert')"></div>
                    <h3><?php echo esc_html__('Statistieken niet geladen', 'stride'); ?></h3>
                    <p x-text="errors.stats"></p>
                </div>
            </template>
            <!-- loading placeholders -->
            <template x-if="loading.stats && !errors.stats">
                <template x-for="i in 4" :key="'sk-stat-'+i">
                    <div class="ws-stat" style="opacity:.5">
                        <div class="ws-stat__label"><?php echo esc_html__('Laden…', 'stride'); ?></div>
                        <div class="ws-stat__num">—</div>
                    </div>
                </template>
            </template>
            <!-- populated cards -->
            <template x-if="!loading.stats && !errors.stats">
                <template x-for="s in stats" :key="s.label">
                    <div class="ws-stat">
                        <div class="ws-stat__label"><span x-html="icon(s.icon)"></span> <span x-text="s.label"></span></div>
                        <div class="ws-stat__num" x-text="s.num.toLocaleString('nl-BE')"></div>
                        <div class="ws-stat__delta" x-show="s.delta"
                             :class="s.kind==='up' ? 'ws-stat__delta--up' : 'ws-stat__delta--flat'">
                            <span x-show="s.kind==='up'" x-html="icon('arrowUp')" style="width:13px;height:13px"></span>
                            <span x-text="s.delta"></span>
                        </div>
                    </div>
                </template>
            </template>
        </div>

        <!-- Promoted "Acties nodig" panel (front door) -->
        <section class="ws-actions-strip">
            <div class="ws-actions-strip__tabs">
                <button class="ws-mini-tab" :class="actTab==='mij' && 'is-active'" @click="actTab='mij'">
                    <span x-html="icon('hourglass')" style="width:15px;height:15px"></span> <?php echo esc_html__('Wacht op mij', 'stride'); ?>
                    <span class="ws-mini-tab__pill" x-text="aq.mij.length"></span>
                </button>
                <button class="ws-mini-tab" :class="actTab==='gebruiker' && 'is-active'" @click="actTab='gebruiker'">
                    <span x-html="icon('clock')" style="width:15px;height:15px"></span> <?php echo esc_html__('Wacht op gebruiker', 'stride'); ?>
                    <span class="ws-mini-tab__pill" :class="aq.gebruiker.length && 'is-alert'" x-text="aq.gebruiker.length"></span>
                </button>
                <button class="ws-mini-tab" :class="actTab==='meldingen' && 'is-active'" @click="actTab='meldingen'">
                    <span x-html="icon('bell')" style="width:15px;height:15px"></span> <?php echo esc_html__('Meldingen', 'stride'); ?>
                    <span class="ws-mini-tab__pill" x-text="aq.meldingen.length"></span>
                </button>
            </div>
            <div>
                <!-- error state for the approvals/action-queue calls -->
                <template x-if="errors.actions">
                    <div class="ws-empty" style="padding:32px">
                        <div class="ws-empty__icon" x-html="icon('alert')"></div>
                        <h3><?php echo esc_html__('Acties niet geladen', 'stride'); ?></h3>
                        <p x-text="errors.actions"></p>
                    </div>
                </template>
                <!-- loading -->
                <template x-if="loading.actions && !errors.actions">
                    <div class="ws-empty" style="padding:32px">
                        <p><?php echo esc_html__('Laden…', 'stride'); ?></p>
                    </div>
                </template>
                <!-- populated rows -->
                <template x-if="!loading.actions && !errors.actions">
                    <div>
                        <template x-for="item in aq[actTab]" :key="item.regId">
                            <a class="ws-actitem" href="#" @click.prevent="openAction(item)">
                                <div class="ws-actitem__avatar"
                                     :style="`background:linear-gradient(135deg, ${avatarColor(item.name)}, ${avatarColor(item.name)}cc)`"
                                     x-text="initials(item.name)"></div>
                                <div class="ws-actitem__body">
                                    <div class="ws-actitem__title" x-text="item.name"></div>
                                    <div class="ws-actitem__meta" x-text="item.meta" x-show="item.meta"></div>
                                </div>
                                <div class="ws-actitem__age" x-text="item.age" x-show="item.age"></div>
                                <span class="ws-badge ws-badge--dotless"
                                      x-show="item.deadline"
                                      :class="item.deadline && item.deadline.kind === 'overdue' ? 'ws-badge--overdue' : 'ws-badge--due-soon'"
                                      x-text="item.deadline && item.deadline.label"></span>
                                <!-- No affordance on informational meldingen (no target and no url) -->
                                <span x-show="!item.isMelding || item.target || item.url"
                                      x-html="icon('chevRight')" style="width:16px;height:16px;color:var(--ws-text-3)"></span>
                            </a>
                        </template>
                        <div x-show="aq[actTab].length===0" class="ws-empty" style="padding:32px">
                            <div class="ws-empty__icon" x-html="icon('checkCircle')"></div>
                            <h3><?php echo esc_html__('Niets in deze wachtrij', 'stride'); ?></h3>
                            <p><?php echo esc_html__('Alles afgehandeld. Goed bezig.', 'stride'); ?></p>
                        </div>
                    </div>
                </template>
            </div>
        </section>

        <!-- The 5 queues -->
        <div class="ws-page-head" style="margin-bottom:var(--ws-4)">
            <div><h2 style="font-size:18px;letter-spacing:-0.01em"><?php echo esc_html__('Wachtrijen', 'stride'); ?></h2></div>
            <span class="ws-muted" style="font-size:var(--ws-fs-sm)"><?php echo esc_html__('Klik een wachtrij om de grid voorgefilterd te openen', 'stride'); ?></span>
        </div>

        <!-- queues error -->
        <template x-if="errors.stats">
            <div class="ws-empty" style="padding:24px">
                <div class="ws-empty__icon" x-html="icon('alert')"></div>
                <h3><?php echo esc_html__('Wachtrijen niet geladen', 'stride'); ?></h3>
            </div>
        </template>

        <div class="ws-queues" x-show="!errors.stats">
            <template x-for="q in queues" :key="q.key">
                <a class="ws-queue" :class="q.count===0 && 'is-empty'"
                   :style="`--accent:${q.accent}`"
                   href="#" @click.prevent="openQueue(q)">
                    <div class="ws-queue__top">
                        <div class="ws-queue__icon" x-html="icon(q.icon)"></div>
                        <div class="ws-queue__count" :class="q.count===0 && 'is-zero'" x-text="q.count"></div>
                    </div>
                    <div class="ws-queue__title" x-text="q.label"></div>
                    <!-- 7a: the approval card shows its ready/blocked split instead of the static definition -->
                    <div class="ws-queue__def" x-text="q.sub || q.def"></div>
                    <div class="ws-queue__foot">
                        <span class="ws-queue__action">
                            <span x-show="q.count>0" x-html="icon(q.actionIcon)"></span>
                            <span x-show="q.count===0" x-html="icon('checkCircle')"></span>
                            <span x-text="q.count>0 ? q.action : '<?php echo esc_js(__('Niets te doen', 'stride')); ?>'"></span>
                        </span>
                        <span class="ws-queue__go" x-show="q.count>0"><?php echo esc_html__('Openen', 'stride'); ?> <span x-html="icon('arrowRight')"></span></span>
                    </div>
                </a>
            </template>
        </div>

    </div>

    <!-- toast for the refresh action (scoped to this surface) -->
    <div class="ws-toast-zone" x-data="{ show:false }"
         @ws-toast.window="show=true; setTimeout(()=>show=false, 2600)">
        <template x-if="show">
            <div class="ws-toast">
                <div class="ws-toast__icon ws-toast__icon--ok" x-html="WS.icon('check')"></div>
                <div class="ws-toast__msg"><?php echo esc_html__('Tellingen vernieuwd', 'stride'); ?></div>
            </div>
        </template>
    </div>
</section>
