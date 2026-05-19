<?php defined('ABSPATH') || exit; ?>
<ul class="qb-field-list" x-ref="fieldList" data-group-id="" :data-group-id="selectedGroup?.id">
    <template x-for="field in selectedGroup.fields" :key="field.id">
        <?php include __DIR__ . '/_field-row.php'; ?>
    </template>
</ul>

<template x-if="selectedGroup.fields.length === 0">
    <div class="qb-empty">
        <?php esc_html_e('Geen velden in deze groep.', 'stride'); ?>
        <button type="button" class="qb-btn" style="margin-top:12px" @click="addField()">
            + <?php esc_html_e('Eerste veld toevoegen', 'stride'); ?>
        </button>
    </div>
</template>

<button type="button" class="qb-add-field" @click="addField()"
        x-show="selectedGroup.fields.length > 0">
    + <?php esc_html_e('Veld toevoegen', 'stride'); ?>
</button>
