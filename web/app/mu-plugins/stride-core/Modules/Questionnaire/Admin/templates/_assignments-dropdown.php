<?php
/**
 * Toon-aan dropdown — per-group popover in the card header.
 *
 * Scope: `group` from x-for in builder.php (passed through _group-card.php).
 *
 * Uses a nested Alpine x-data island for the open/close state so each card's
 * popover toggles independently. Click outside closes. Each option is a
 * checkbox bound through toggleAssignment(group, value) on the controller,
 * which keeps the string-normalization invariant the sanitizer expects.
 */
defined('ABSPATH') || exit;
?>
<div class="qb-assign" x-data="{ open: false }" @click.stop>
    <button type="button"
            class="qb-assign__trigger"
            @click="open = !open"
            :class="{ 'qb-assign__trigger--has-selection': (group.assignments || []).length > 0 }">
        <span class="qb-assign__icon" aria-hidden="true">👥</span>
        <span x-text="assignButtonLabel(group)"></span>
        <span class="qb-assign__caret" aria-hidden="true">▾</span>
    </button>

    <div class="qb-assign__popover" x-show="open" @click.outside="open = false" x-transition.opacity>
        <div class="qb-assign__hint">
            <?php esc_html_e('Selecteer aan welke edities of trajecten deze groep gekoppeld is. Niet gekoppeld = niet zichtbaar op formulier.', 'stride'); ?>
        </div>
        <template x-for="optgroup in assignments" :key="optgroup.label">
            <div class="qb-assign__optgroup">
                <div class="qb-assign__optgroup-label" x-text="optgroup.label"></div>
                <template x-for="opt in optgroup.options" :key="opt.value">
                    <label class="qb-assign__option">
                        <input type="checkbox"
                               :value="String(opt.value)"
                               :checked="isAssigned(group, opt.value)"
                               @change="toggleAssignment(group, opt.value)">
                        <span x-text="opt.label"></span>
                    </label>
                </template>
            </div>
        </template>
    </div>
</div>
