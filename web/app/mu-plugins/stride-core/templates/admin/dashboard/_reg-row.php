<?php defined('ABSPATH') || exit; ?>
                    <tr :class="{ 'is-selected': isSelected(r.id), 'is-anon': r.anonymous || r.user.id === 0 }" @click="openRow(r)">
                        <td class="ws-col-check" @click.stop>
                            <input type="checkbox" class="ws-check" :checked="isSelected(r.id)" @change="toggle(r.id)">
                        </td>
                        <td>
                            <div class="ws-namecell">
                                <div class="ws-namecell__avatar" :style="`background:${avatarColor(r.user.name)}`" x-text="initials(r.user.name)"></div>
                                <div>
                                    <div class="ws-namecell__name">
                                        <span x-text="r.user.name"></span>
                                        <?php // Identity state (never "anonymous" — a lead is a participant
                                              // without an account yet). "Account gevonden" = this lead's
                                              // e-mail matches an existing account; it binds at promotion.
                                              // ONE span for both states — two near-copies of the lead
                                              // predicate is the one-row-two-identities drift class. ?>
                                        <span class="ws-badge ws-badge--dotless ws-badge--lead"
                                              x-show="r.anonymous || r.user.id === 0"
                                              x-text="r.accountMatch ? '<?php echo esc_js(__('Account gevonden', 'stride')); ?>' : '<?php echo esc_js(__('Geen account', 'stride')); ?>'"
                                              :title="r.accountMatch
                                                  ? '<?php echo esc_js(__('E-mailadres hoort bij een bestaand account:', 'stride')); ?> ' + r.accountMatch.name + '. <?php echo esc_js(__('Wordt gekoppeld bij inschrijving of promotie.', 'stride')); ?>'
                                                  : '<?php echo esc_js(__('Lead — nog geen account.', 'stride')); ?>'"></span>
                                    </div>
                                    <div class="ws-namecell__sub" x-text="r.user.email || '—'"></div>
                                </div>
                            </div>
                        </td>
                        <td class="ws-edition-cell">
                            <span x-text="r.edition.title || '—'"></span>
                        </td>
                        <td>
                            <span class="ws-badge" :class="'ws-badge--'+r.status.value" x-text="r.status.label"></span>
                        </td>
                        <td>
                            <span class="ws-offerte" :class="'ws-offerte--'+offerteClass(r.offerteStatus)">
                                <span class="ws-offerte__dot"></span>
                                <span x-text="r.offerteStatus"></span>
                            </span>
                        </td>
                        <td>
                            <template x-if="r.attendancePct != null">
                                <div class="ws-meter">
                                    <div class="ws-meter__track"><div class="ws-meter__fill" :class="attClass(r.attendancePct)" :style="`width:${r.attendancePct}%`"></div></div>
                                    <span class="ws-meter__val" x-text="r.attendancePct + '%'"></span>
                                </div>
                            </template>
                            <template x-if="r.attendancePct == null">
                                <span class="ws-meter__val ws-meter__val--na">—</span>
                            </template>
                        </td>
                        <td>
                            <template x-if="r.company.name">
                                <span class="ws-org-cell"><span x-html="icon('building')"></span><span x-text="r.company.name"></span></span>
                            </template>
                            <template x-if="!r.company.name && r.company.id">
                                <span class="ws-org-cell"><span x-html="icon('building')"></span><span x-text="'#'+r.company.id"></span></span>
                            </template>
                            <template x-if="!r.company.name && !r.company.id">
                                <span class="ws-muted" style="font-size:var(--ws-fs-sm)"><?php echo esc_html__('Particulier', 'stride'); ?></span>
                            </template>
                        </td>
                        <td>
                            <template x-if="r.trajectory && r.trajectory.title">
                                <span class="ws-traject-cell"><span x-html="icon('route')"></span><span x-text="r.trajectory.title"></span></span>
                            </template>
                            <template x-if="!r.trajectory || !r.trajectory.title"><span class="ws-muted">—</span></template>
                        </td>
                    </tr>
