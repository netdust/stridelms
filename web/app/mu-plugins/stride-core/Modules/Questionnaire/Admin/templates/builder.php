<?php
/**
 * Questionnaire Builder v2 — top-level template.
 *
 * Renders a vertical accordion of formuliergroep cards. One card open at
 * a time; the inspector lives inside the open card's body (no separate
 * right-rail panel).
 *
 * State is hydrated by Alpine via window.strideQuestionnaireState
 * (inlined in QuestionnaireSettingsPage::inlineHeadAssets()).
 */
defined('ABSPATH') || exit;
?>
<div class="qb-app" x-data="questionnaireBuilder()" x-cloak>
    <form method="post">
        <?php wp_nonce_field(\Stride\Modules\Questionnaire\Admin\QuestionnaireSettingsPage::NONCE_ACTION, \Stride\Modules\Questionnaire\Admin\QuestionnaireSettingsPage::NONCE_FIELD); ?>
        <input type="hidden" name="stride_questionnaire_groups_json" :value="JSON.stringify(groups)">

        <?php include __DIR__ . '/_toolbar.php'; ?>

        <div class="qb-body">
            <p class="qb-help" x-show="groups.length <= 1">
                <?php esc_html_e('Een formuliergroep is een blok vragen dat samen wordt getoond op je formulier. Geef elke groep een herkenbare naam.', 'stride'); ?>
            </p>

            <div class="qb-accordion">
                <template x-for="(group, gi) in groups" :key="group.id">
                    <?php include __DIR__ . '/_group-card.php'; ?>
                </template>

                <button type="button" class="qb-add-group" @click="addGroup()">
                    + <?php esc_html_e('Nieuwe formuliergroep', 'stride'); ?>
                </button>
            </div>

            <template x-if="groups.length === 0">
                <div class="qb-empty">
                    <p><?php esc_html_e('Nog geen formuliergroepen.', 'stride'); ?></p>
                    <button type="button" class="qb-btn qb-btn--primary" @click="addGroup()">
                        + <?php esc_html_e('Eerste formuliergroep toevoegen', 'stride'); ?>
                    </button>
                </div>
            </template>
        </div>
    </form>
</div>
