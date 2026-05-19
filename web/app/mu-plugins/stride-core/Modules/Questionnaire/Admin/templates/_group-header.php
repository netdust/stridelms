<?php defined('ABSPATH') || exit; ?>
<div class="qb-group-header">
    <div style="flex:1">
        <input type="text"
               class="qb-inspector__input"
               style="border-color:transparent;background:transparent;padding-left:0;font-weight:600"
               x-model="selectedGroup.label"
               @input="isDirty = true"
               placeholder="<?php esc_attr_e('Groepsnaam (bv. Medische gegevens)', 'stride'); ?>">
    </div>
    <button type="button" class="qb-btn qb-btn--ghost"
            @click="if (confirm('<?php echo esc_js(__('Deze groep verwijderen?', 'stride')); ?>')) deleteGroup(selectedGroup.id)">
        <?php esc_html_e('Verwijder groep', 'stride'); ?>
    </button>
</div>

<div class="qb-group-meta">
    <label class="qb-group-meta__label">
        <?php esc_html_e('Toon aan', 'stride'); ?>
        <span style="font-weight:400;color:var(--sd-text-muted)">
            (<?php esc_html_e('leeg = alle deelnemers', 'stride'); ?>)
        </span>
    </label>
    <select class="qb-group-meta__select"
            multiple
            size="6"
            x-model="selectedGroup.assignments"
            @change="isDirty = true">
        <template x-for="optgroup in assignments" :key="optgroup.label">
            <optgroup :label="optgroup.label">
                <template x-for="opt in optgroup.options" :key="opt.value">
                    <option :value="opt.value" x-text="opt.label"></option>
                </template>
            </optgroup>
        </template>
    </select>
</div>
