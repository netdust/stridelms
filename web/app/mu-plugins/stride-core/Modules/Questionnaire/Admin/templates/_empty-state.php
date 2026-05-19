<?php defined('ABSPATH') || exit; ?>
<div class="qb-empty">
    <?php esc_html_e('Geen groepen aangemaakt.', 'stride'); ?>
    <button type="button" class="qb-btn qb-btn--primary" style="margin-top:12px" @click="addGroup()">
        + <?php esc_html_e('Eerste groep toevoegen', 'stride'); ?>
    </button>
</div>
