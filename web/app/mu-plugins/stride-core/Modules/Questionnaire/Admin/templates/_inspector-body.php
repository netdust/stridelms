<?php
/**
 * Inspector content for the open formuliergroep's selected field.
 *
 * Embedded inside _group-card.php's body (the card supplies the
 * <aside class="qb-group-card__inspector"> wrapper). Bindings are
 * scoped via selectedField (which already follows selectedGroupId).
 */
defined('ABSPATH') || exit;
?>
<div class="qb-inspector__title">
    <span x-show="selectedField"><?php esc_html_e('Veld bewerken', 'stride'); ?></span>
    <span x-show="!selectedField"><?php esc_html_e('Klik op een veld om te bewerken', 'stride'); ?></span>
</div>

<template x-if="selectedField">
    <div>
        <div class="qb-inspector__field">
            <label class="qb-label"><?php esc_html_e('Type', 'stride'); ?></label>
            <select class="qb-input"
                    x-model="selectedField.type"
                    @change="isDirty = true">
                <template x-for="(typeDef, typeKey) in fieldTypes" :key="typeKey">
                    <option :value="typeKey" x-text="typeDef.label"></option>
                </template>
            </select>
        </div>

        <div class="qb-inspector__field" x-show="selectedField.type !== 'description'">
            <label class="qb-label"><?php esc_html_e('Vraag', 'stride'); ?></label>
            <input type="text"
                   class="qb-input"
                   x-model="selectedField.label"
                   @input="isDirty = true">
        </div>

        <div class="qb-inspector__field" x-show="selectedField.type !== 'description'">
            <label class="qb-label">
                <?php esc_html_e('Veldnaam (technisch)', 'stride'); ?>
                <span class="qb-label__hint">(<?php esc_html_e('optioneel — koppelt veld aan systeemveld', 'stride'); ?>)</span>
            </label>
            <input type="text"
                   class="qb-input"
                   x-model="selectedField.name"
                   @input="isDirty = true"
                   placeholder="bv. rijksregisternummer">
        </div>

        <div class="qb-inspector__field" x-show="selectedField.type === 'description'">
            <label class="qb-label"><?php esc_html_e('Tekst', 'stride'); ?></label>
            <textarea class="qb-input qb-input--textarea"
                      x-model="selectedField.label"
                      @input="isDirty = true"></textarea>
        </div>

        <div class="qb-inspector__field" x-show="selectedField.type !== 'description'">
            <label class="qb-label">
                <?php esc_html_e('Hulptekst', 'stride'); ?>
                <span class="qb-label__hint">(<?php esc_html_e('optioneel', 'stride'); ?>)</span>
            </label>
            <input type="text"
                   class="qb-input"
                   x-model="selectedField.help"
                   @input="isDirty = true">
        </div>

        <div class="qb-inspector__field" x-show="selectedField.type === 'select' || selectedField.type === 'radio'">
            <label class="qb-label">
                <?php esc_html_e('Opties', 'stride'); ?>
                <span class="qb-label__hint">(<?php esc_html_e('één per regel', 'stride'); ?>)</span>
            </label>
            <textarea class="qb-input qb-input--textarea"
                      x-model="selectedField.options"
                      @input="isDirty = true"></textarea>
        </div>

        <div class="qb-inspector__field" x-show="selectedField.type === 'scale'">
            <label class="qb-label"><?php esc_html_e('Schaal', 'stride'); ?></label>
            <div class="qb-inspector__row">
                <input type="number" class="qb-input" x-model.number="selectedField.min" min="1" @input="isDirty = true">
                <input type="number" class="qb-input" x-model.number="selectedField.max" min="2" @input="isDirty = true">
            </div>
        </div>

        <label class="qb-checkbox" x-show="selectedField.type !== 'description'">
            <input type="checkbox" x-model="selectedField.required" @change="isDirty = true">
            <?php esc_html_e('Verplicht in te vullen', 'stride'); ?>
        </label>

        <div class="qb-inspector__actions">
            <button type="button" class="qb-btn qb-btn--sm" @click="duplicateField(selectedField.id)">
                <?php esc_html_e('Dupliceren', 'stride'); ?>
            </button>
            <button type="button" class="qb-btn qb-btn--sm qb-btn--danger"
                    @click="if (confirm('<?php echo esc_js(__('Dit veld verwijderen?', 'stride')); ?>')) deleteField(selectedField.id)">
                <?php esc_html_e('Verwijderen', 'stride'); ?>
            </button>
        </div>
    </div>
</template>
