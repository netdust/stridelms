<?php defined('ABSPATH') || exit; ?>
<li class="qb-field-row"
    :class="{ 'qb-field-row--selected': field.id === selectedFieldId }"
    :data-field-id="field.id"
    @click="selectField(field.id)">
    <div class="qb-field-row__grab">⋮⋮</div>
    <div style="flex:1">
        <div class="qb-field-row__label" x-text="field.label || '<?php echo esc_js(__('Naamloos veld', 'stride')); ?>'"></div>
        <div class="qb-field-row__meta" x-text="fieldMeta(field)"></div>
    </div>
    <span style="font-size:11px;color:var(--sd-text-muted)" x-show="field.id !== selectedFieldId">✎</span>
</li>
