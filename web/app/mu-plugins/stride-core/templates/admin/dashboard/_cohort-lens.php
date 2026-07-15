<?php
/**
 * Admin Workspace — Cohort lens slideover (cluster G).
 *
 * A right-anchored ws-* slideover that overlays the current surface with the
 * per-edition roster: session-filter chips, loaded-set extras-filter chips
 * (CF3), and per-session attendance marking (CF2 — optimistic, with the
 * active mark lit and the cell showing the SELECTED session's state). Its own
 * per-surface Alpine factory (assets/js/admin/cohort.js) owns ALL its data.
 *
 * READ + ATTENDANCE ONLY (decision 5a, F-C1): the roster bulk bar was
 * removed — the cohort roster is confirmed/completed only (CR-1), so the one
 * lifecycle action could never appear and the rest were stubs. Lifecycle work
 * lives on the Inschrijvingen grid.
 *
 * Mounted INSIDE the shell's wsShell() scope, so it inherits api() / icon()
 * (Alpine v3 nested-scope). It is OPENED via the `ws-cohort-open` window event
 * the Edities surface dispatches (a sibling x-data scope cannot call into this
 * one directly), and renders nothing until then (x-show="open"). On close
 * after a mark it dispatches ws-refresh for the surfaces underneath (F-C3).
 *
 * INV-5: every x-html binds a CONSTANT icon name via icon('<literal>'); never a
 * data field. Names/labels render via x-text (auto-escaped). INV-6b: the session
 * filter matches rows[].selections (server-resolved session ids) — never a
 * client raw-column decode. INV-7: status + is_anonymised render AS RECEIVED.
 *
 * @package Stride\Admin
 */

defined('ABSPATH') || exit;
?>
<div x-data="cohort()"
     x-init="init()"
     @ws-cohort-open.window="openForEdition($event.detail && $event.detail.editionId)"
     @keydown.escape.window="open && close()"
     x-cloak>

    <template x-if="open">
        <div>
            <div class="ws-overlay" @click="close()"></div>
            <aside class="ws-slideover" role="dialog" aria-modal="true" aria-label="<?php echo esc_attr__('Rooster', 'stride'); ?>">

                <!-- ===== HEAD ===== -->
                <div class="ws-slideover__head">
                    <div class="ws-slideover__head-main">
                        <div class="ws-eyebrow"><?php echo esc_html__('Rooster', 'stride'); ?></div>
                        <h2 class="ws-slideover__title" x-text="title || '<?php echo esc_js(__('Editie', 'stride')); ?>'"></h2>
                        <div class="ws-slideover__meta" x-show="!loading && !error">
                            <span class="ws-count"><b x-text="visibleCount"></b> <?php echo esc_html__('van', 'stride'); ?> <b x-text="sessionScopedCount"></b>
                                <span x-show="isFiltered"> — <?php echo esc_html__('gefilterd', 'stride'); ?></span>
                            </span>
                        </div>
                    </div>
                    <button class="ws-btn ws-btn--subtle ws-btn--sm" @click="close()" title="<?php echo esc_attr__('Sluiten', 'stride'); ?>">
                        <span x-html="icon('x')"></span>
                    </button>
                </div>

                <!-- ===== BODY ===== -->
                <div class="ws-slideover__body">

                    <!-- Loading -->
                    <template x-if="loading">
                        <div class="ws-empty" style="padding-top:48px"><p><?php echo esc_html__('Rooster laden…', 'stride'); ?></p></div>
                    </template>

                    <!-- Error (with retry) — also covers a failed edition-detail
                         fetch (F-C4: it used to be swallowed, silently producing a
                         sessionless lens where marking was impossible). -->
                    <template x-if="!loading && error">
                        <div class="ws-empty" style="padding-top:48px">
                            <div class="ws-empty__icon" x-html="icon('alert')"></div>
                            <h3><?php echo esc_html__('Rooster niet geladen', 'stride'); ?></h3>
                            <p x-text="error"></p>
                            <button class="ws-btn ws-btn--ghost" style="margin-top:16px" @click="retry()">
                                <span x-html="icon('refresh')"></span> <?php echo esc_html__('Opnieuw proberen', 'stride'); ?>
                            </button>
                        </div>
                    </template>

                    <template x-if="!loading && !error">
                        <div>

                            <!-- Session picker chips -->
                            <div style="margin-bottom:12px" x-show="sessions.length > 0">
                                <div class="ws-eyebrow" style="margin-bottom:6px"><?php echo esc_html__('Sessie', 'stride'); ?></div>
                                <div class="ws-row" style="flex-wrap:wrap;gap:6px">
                                    <button type="button" class="ws-chip" :class="{ 'is-active': sessionId === 0 }" @click="selectSession(0)">
                                        <?php echo esc_html__('Alle inschrijvingen', 'stride'); ?>
                                    </button>
                                    <template x-for="s in sessions" :key="s.id">
                                        <button type="button" class="ws-chip" :class="{ 'is-active': sessionId === s.id }"
                                                @click="selectSession(s.id)" x-text="sessionChipLabel(s)"></button>
                                    </template>
                                </div>
                            </div>
                            <p class="ws-muted" style="font-size:var(--ws-fs-sm);margin:0 0 12px"
                               x-show="sessions.length === 0 && rows.length > 0">
                                <?php echo esc_html__('Deze editie heeft geen sessies — toont alle inschrijvingen.', 'stride'); ?>
                            </p>

                            <!-- Extras filter chips (CF3 — loaded-set only) -->
                            <div style="margin-bottom:12px" x-show="extrasOptions.length > 0">
                                <div class="ws-eyebrow" style="margin-bottom:6px"><?php echo esc_html__('Extra\'s', 'stride'); ?></div>
                                <div class="ws-row" style="flex-wrap:wrap;gap:6px">
                                    <template x-for="opt in extrasOptions" :key="opt.token">
                                        <button type="button" class="ws-chip" :class="{ 'is-active': extrasFilter === opt.token }"
                                                @click="setExtrasFilter(opt.token)">
                                            <span x-text="opt.key + ': ' + opt.value"></span>
                                            <span class="ws-chip__count" x-text="opt.count"></span>
                                        </button>
                                    </template>
                                    <button type="button" class="ws-btn ws-btn--subtle ws-btn--sm" x-show="extrasFilter" @click="clearExtrasFilter()">
                                        <span x-html="icon('x')"></span> <?php echo esc_html__('Wis filter', 'stride'); ?>
                                    </button>
                                </div>
                            </div>

                            <!-- Roster table -->
                            <table class="ws-table" x-show="rows.length > 0 && visibleCount > 0" style="margin-top:8px">
                                <thead>
                                    <tr>
                                        <th><?php echo esc_html__('Deelnemer', 'stride'); ?></th>
                                        <th><?php echo esc_html__('Organisatie', 'stride'); ?></th>
                                        <th x-show="extrasKeys.length > 0"><?php echo esc_html__('Extra\'s', 'stride'); ?></th>
                                        <th class="ws-col-status"><?php echo esc_html__('Status', 'stride'); ?></th>
                                        <th><?php echo esc_html__('Aanwezigheid', 'stride'); ?></th>
                                        <th x-show="canMarkAttendance"><?php echo esc_html__('Markeer', 'stride'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <template x-for="row in visibleRows" :key="row.registration_id">
                                        <tr>
                                            <td>
                                                <span x-text="row.name"></span>
                                                <em x-show="row.is_anonymised" class="ws-muted" style="font-style:italic"> (<?php echo esc_html__('verwijderd', 'stride'); ?>)</em>
                                            </td>
                                            <td><span x-text="row.organisation || '—'"></span></td>
                                            <!-- per-row extras (F-C4: the keys were fetched but
                                                 only ever rendered as filter chips) -->
                                            <td x-show="extrasKeys.length > 0">
                                                <span class="ws-muted" style="font-size:var(--ws-fs-sm)" x-text="extrasSummary(row) || '—'"></span>
                                            </td>
                                            <td><span class="ws-badge" :class="'ws-badge--'+statusBadgeClass(row.status)" x-text="statusLabel(row.status)"></span></td>
                                            <!-- with a session selected this is THAT session's
                                                 state (the question being answered); otherwise
                                                 the cross-session aggregate (F-C2) -->
                                            <td><span x-text="attendanceCellLabel(row)"></span></td>
                                            <td x-show="canMarkAttendance">
                                                <div class="ws-row" style="gap:4px">
                                                    <!-- the ACTIVE mark is lit; clicking it again
                                                         clears (toggle) — buttons finally reflect
                                                         current state (F-C2) -->
                                                    <button type="button" class="ws-btn ws-btn--subtle ws-btn--sm" style="color:var(--ws-success-text)"
                                                            :class="{ 'is-active': markFor(row) === 'present' }"
                                                            @click="markAttendance(row.user_id, 'present')" title="<?php echo esc_attr__('Aanwezig', 'stride'); ?>">
                                                        <span x-html="icon('check')"></span>
                                                    </button>
                                                    <button type="button" class="ws-btn ws-btn--subtle ws-btn--sm" style="color:var(--ws-danger)"
                                                            :class="{ 'is-active': markFor(row) === 'absent' }"
                                                            @click="markAttendance(row.user_id, 'absent')" title="<?php echo esc_attr__('Afwezig', 'stride'); ?>">
                                                        <span x-html="icon('x')"></span>
                                                    </button>
                                                    <button type="button" class="ws-btn ws-btn--subtle ws-btn--sm" style="color:var(--ws-warning-text)"
                                                            :class="{ 'is-active': markFor(row) === 'excused' }"
                                                            @click="markAttendance(row.user_id, 'excused')" title="<?php echo esc_attr__('Verontschuldigd', 'stride'); ?>">
                                                        <span x-html="icon('info')"></span>
                                                    </button>
                                                    <button type="button" class="ws-btn ws-btn--subtle ws-btn--sm ws-muted"
                                                            x-show="markFor(row)"
                                                            @click="markAttendance(row.user_id, '')" title="<?php echo esc_attr__('Wissen', 'stride'); ?>">
                                                        <span x-html="icon('slash')"></span>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>

                            <!-- Empty: no registrations at all -->
                            <template x-if="rows.length === 0">
                                <div class="ws-empty" style="padding-top:48px">
                                    <div class="ws-empty__icon" x-html="icon('users')"></div>
                                    <h3><?php echo esc_html__('Geen inschrijvingen', 'stride'); ?></h3>
                                    <p><?php echo esc_html__('Nog geen inschrijvingen voor deze editie.', 'stride'); ?></p>
                                </div>
                            </template>

                            <!-- Empty: filtered to nothing -->
                            <template x-if="rows.length > 0 && visibleCount === 0">
                                <div class="ws-empty" style="padding-top:48px">
                                    <div class="ws-empty__icon" x-html="icon('search')"></div>
                                    <h3 x-text="sessionId ? '<?php echo esc_js(__('Niemand gekozen voor deze sessie', 'stride')); ?>' : '<?php echo esc_js(__('Geen inschrijvingen voldoen aan het filter', 'stride')); ?>'"></h3>
                                </div>
                            </template>

                        </div>
                    </template>
                </div>

            </aside>

            <!-- ===== TOASTS ===== -->
            <div class="ws-toast-zone">
                <template x-for="t in toasts" :key="t.id">
                    <div class="ws-toast">
                        <div class="ws-toast__icon" :class="t.kind==='ok' ? 'ws-toast__icon--ok' : 'ws-toast__icon--mixed'" x-html="icon(t.kind==='ok' ? 'check' : 'info')"></div>
                        <div class="ws-toast__msg"><strong x-text="t.lead" x-show="t.lead"></strong> <span x-text="t.body"></span></div>
                    </div>
                </template>
            </div>
        </div>
    </template>
</div>
