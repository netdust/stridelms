<?php defined('ABSPATH') || exit; ?>
<div class="qb-group-header">
    <div style="flex:1">
        <input type="text"
               class="qb-inspector__input"
               style="border-color:transparent;background:transparent;padding-left:0;font-weight:600"
               x-model="selectedGroup.label"
               @input="isDirty = true"
               placeholder="<?php esc_attr_e('Groepsnaam (bv. Medische gegevens)', 'stride'); ?>">
        <div style="font-size:var(--sd-font-size-sm);color:var(--sd-text-muted);margin-top:2px">
            <?php esc_html_e('Toon aan:', 'stride'); ?>
            <span x-text="selectedGroup.assigned.length === 0 ? '<?php echo esc_js(__('alle deelnemers', 'stride')); ?>' : selectedGroup.assigned.length + ' <?php echo esc_js(__('toewijzingen', 'stride')); ?>'"></span>
        </div>
    </div>
    <button type="button" class="qb-btn qb-btn--ghost"
            @click="if (confirm('<?php echo esc_js(__('Deze groep verwijderen?', 'stride')); ?>')) deleteGroup(selectedGroup.id)">
        <?php esc_html_e('Verwijder groep', 'stride'); ?>
    </button>
</div>
