<?php
/**
 * Questionnaire Builder v2 — one accordion card per formuliergroep.
 *
 * Scope: `group` and `gi` from x-for in builder.php.
 *
 * Header:
 *   - chevron (open/close)
 *   - title: read-only when named, input when empty or being edited.
 *     Pen icon toggles back into edit mode for a named group.
 *   - assignments dropdown
 *   - meta hint (stage, field count)
 *
 * Body (only when this card is the open one):
 *   - assignments multi-select
 *   - field list with type/required hint
 *   - inspector for the currently selected field
 *
 * Only one card open at a time — toggleGroup() sets selectedGroupId
 * to this group's id, or null when collapsing.
 */
defined('ABSPATH') || exit;
?>
<div class="qb-group-card"
     :class="{ 'qb-group-card--open': group.id === selectedGroupId }">
    <div class="qb-group-card__header" @click="toggleGroup(group.id)">
        <span class="qb-group-card__chevron" aria-hidden="true">▸</span>

        <div class="qb-group-card__title"
             x-data="{ editing: !group.label }"
             @qb-edit-title.window="if ($event.detail === group.id) { editing = true; $nextTick(() => $refs.nameInput?.focus()); }">

            <template x-if="!editing">
                <button type="button"
                        class="qb-group-card__name-display"
                        @click.stop="editing = true; $nextTick(() => { $refs.nameInput.focus(); $refs.nameInput.select(); })">
                    <span x-text="group.label"></span>
                    <span class="qb-group-card__name-pen" aria-hidden="true">✎</span>
                </button>
            </template>

            <template x-if="editing">
                <input type="text"
                       class="qb-group-card__name"
                       x-ref="nameInput"
                       :data-name-for="group.id"
                       x-model="group.label"
                       @click.stop
                       @input="isDirty = true"
                       @blur="if (group.label) editing = false"
                       @keydown.enter.prevent="if (group.label) editing = false"
                       @keydown.escape.prevent="if (group.label) editing = false"
                       placeholder="<?php esc_attr_e('Naam deze formuliergroep', 'stride'); ?>">
            </template>
        </div>

        <?php include __DIR__ . '/_stage-dropdown.php'; ?>
        <?php include __DIR__ . '/_assignments-dropdown.php'; ?>

        <span class="qb-group-card__meta"
              x-text="groupMeta(group)"></span>
    </div>

    <div class="qb-group-card__body" x-show="group.id === selectedGroupId" x-transition.duration.150ms>
        <div class="qb-group-card__split">
            <div class="qb-group-card__fields">
                <ul class="qb-field-list" x-ref="fieldList">
                    <template x-for="field in group.fields" :key="field.id">
                        <?php include __DIR__ . '/_field-row.php'; ?>
                    </template>
                </ul>

                <button type="button" class="qb-add-field" @click="addField()">
                    + <?php esc_html_e('Veld toevoegen', 'stride'); ?>
                </button>
            </div>

            <aside class="qb-group-card__inspector">
                <?php include __DIR__ . '/_inspector-body.php'; ?>
            </aside>
        </div>

        <div class="qb-group-card__footer">
            <button type="button" class="qb-btn qb-btn--danger qb-btn--sm"
                    @click="if (confirm('<?php echo esc_js(__('Deze formuliergroep verwijderen?', 'stride')); ?>')) deleteGroup(group.id)">
                <?php esc_html_e('Formuliergroep verwijderen', 'stride'); ?>
            </button>
        </div>
    </div>
</div>
