<?php defined('ABSPATH') || exit; ?>
<div class="qb-toolbar">
    <div style="display:flex;align-items:center;gap:14px">
        <div class="qb-toolbar__title"><?php esc_html_e('Vragenlijst — formuliergroepen', 'stride'); ?></div>
        <span class="qb-toolbar__count" x-text="groups.length + ' <?php echo esc_js(__('formuliergroepen', 'stride')); ?>'"></span>
    </div>
    <div class="qb-toolbar__actions">
        <a href="<?php echo esc_url(admin_url('admin.php?page=stride-questionnaire')); ?>" class="qb-btn">
            <?php esc_html_e('Annuleren', 'stride'); ?>
        </a>
        <button type="submit" class="qb-btn qb-btn--primary">
            <?php esc_html_e('Wijzigingen opslaan', 'stride'); ?>
        </button>
    </div>
</div>
