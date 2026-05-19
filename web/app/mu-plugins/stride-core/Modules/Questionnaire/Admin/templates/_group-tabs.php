<?php defined('ABSPATH') || exit; ?>
<div class="qb-tabs">
    <div class="qb-tabs__list">
        <template x-for="group in groups" :key="group.id">
            <button type="button"
                    class="qb-tab"
                    :class="{ 'qb-tab--active': group.id === selectedGroupId }"
                    @click="selectGroup(group.id)"
                    x-text="group.label || '<?php echo esc_js(__('Nieuwe groep', 'stride')); ?>'"></button>
        </template>
        <button type="button" class="qb-tab qb-tab--add" @click="addGroup()">
            + <?php esc_html_e('Nieuwe groep', 'stride'); ?>
        </button>
    </div>
    <div style="font-size:var(--sd-font-size-sm);color:var(--sd-text-secondary)" x-show="selectedGroup">
        <?php esc_html_e('Fase:', 'stride'); ?>
        <strong x-text="stages[selectedGroup?.stage] || ''" style="color:var(--sd-text-primary)"></strong>
    </div>
</div>
