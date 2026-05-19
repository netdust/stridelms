<?php
/**
 * Questionnaire Builder v2 — top-level template.
 *
 * Loaded from QuestionnaireSettingsPage::renderPage().
 * State is hydrated by Alpine via window.strideQuestionnaireState
 * (set in enqueueAssets()).
 *
 * Form posts to the same admin URL — handleSave() is wired on
 * admin_init and reads $_POST directly. NONCE_ACTION + NONCE_FIELD
 * are the existing constants on QuestionnaireSettingsPage.
 */
defined('ABSPATH') || exit;
?>
<div class="qb-app" x-data="questionnaireBuilder()" x-cloak>
    <form method="post">
        <?php wp_nonce_field(\Stride\Modules\Questionnaire\Admin\QuestionnaireSettingsPage::NONCE_ACTION, \Stride\Modules\Questionnaire\Admin\QuestionnaireSettingsPage::NONCE_FIELD); ?>
        <input type="hidden" name="stride_questionnaire_groups_json" :value="JSON.stringify(groups)">

        <?php include __DIR__ . '/_toolbar.php'; ?>
        <?php include __DIR__ . '/_group-tabs.php'; ?>

        <div class="qb-body">
            <div class="qb-canvas">
                <template x-if="selectedGroup">
                    <div>
                        <?php include __DIR__ . '/_group-header.php'; ?>
                        <?php include __DIR__ . '/_field-list.php'; ?>
                    </div>
                </template>
                <template x-if="!selectedGroup">
                    <?php include __DIR__ . '/_empty-state.php'; ?>
                </template>
            </div>
            <?php include __DIR__ . '/_inspector.php'; ?>
        </div>
    </form>
</div>
