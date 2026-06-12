<?php
/**
 * Stage dropdown — per-group fase picker in the card header.
 *
 * Scope: `group` from x-for in builder.php (passed through _group-card.php).
 *
 * Same popover pattern as _assignments-dropdown.php. Radio selection;
 * picking a stage closes the popover.
 */
defined('ABSPATH') || exit;
?>
<div class="qb-assign" x-data="{ open: false }" @click.stop>
    <button type="button"
            class="qb-assign__trigger qb-assign__trigger--has-selection"
            @click="open = !open">
        <span class="qb-assign__icon" aria-hidden="true">📄</span>
        <span x-text="stages[group.stage] || '<?php echo esc_js(__('Geen fase', 'stride')); ?>'"></span>
        <span class="qb-assign__caret" aria-hidden="true">▾</span>
    </button>

    <div class="qb-assign__popover" x-show="open" @click.outside="open = false" x-transition.opacity>
        <div class="qb-assign__hint">
            <?php esc_html_e('Op welk formulier hoort deze groep?', 'stride'); ?>
        </div>
        <template x-for="(label, key) in stages" :key="key">
            <label class="qb-assign__option">
                <input type="radio"
                       :name="'stage_' + group.id"
                       :value="key"
                       :checked="group.stage === key"
                       @change="group.stage = key; isDirty = true; open = false">
                <span x-text="label"></span>
            </label>
        </template>
    </div>
</div>
