<?php

declare(strict_types=1);

namespace Stride\Modules\Edition\Admin;

use WP_Post;

/**
 * Shared sidebar partial for "offering" CPTs (Edition, Trajectory).
 *
 * Renders the Vóór / Tijdens / Na afloop lifecycle sections.
 * The host CPT must define these meta fields in its schema:
 *   enrollment_form, requires_approval, requires_questionnaire, requires_documents,
 *   post_requires_evaluation, post_requires_documents, post_requires_approval
 */
final class OfferingSidebarPartial
{
    /**
     * @param string $modelKey  The CPT slug as registered with ntdst_data() (e.g. 'vad_edition').
     * @param string $duringHint Optional hint shown under the "Tijdens inschrijving" section.
     */
    public static function render(WP_Post $post, string $modelKey, string $duringHint = ''): void
    {
        if ($post->post_status === 'auto-draft') {
            return;
        }

        $model = ntdst_data()->get($modelKey);
        if (!$model) {
            return;
        }

        $enrollmentForm   = $model->getMeta($post->ID, 'enrollment_form') ?: 'default';
        $requiresApproval = (bool) $model->getMeta($post->ID, 'requires_approval');
        $hasForm          = $enrollmentForm !== 'direct';

        $duringRequirements = [
            'requires_questionnaire' => __('Vragenlijst invullen', 'stride'),
            'requires_documents'     => __('Documenten uploaden', 'stride'),
        ];

        $postCourseRequirements = [
            'post_requires_evaluation' => __('Evaluatie invullen', 'stride'),
            'post_requires_documents'  => __('Documenten uploaden', 'stride'),
            'post_requires_approval'   => __('Aftekenen door beheerder', 'stride'),
        ];
        ?>
        <div class="stride-sidebar-section">
            <h4><?php esc_html_e('Vóór inschrijving', 'stride'); ?></h4>

            <label style="font-size: 11px; color: #646970; display: block; margin-bottom: 2px;">
                <?php esc_html_e('Inschrijfformulier', 'stride'); ?>
            </label>
            <select name="ntdst_fields[enrollment_form]" id="stride-enrollment-form" style="width: 100%; font-size: 12px;">
                <option value="default" <?php selected($enrollmentForm, 'default'); ?>>
                    <?php esc_html_e('Standaard formulier', 'stride'); ?>
                </option>
                <option value="minimal" <?php selected($enrollmentForm, 'minimal'); ?>>
                    <?php esc_html_e('Minimaal formulier', 'stride'); ?>
                </option>
                <option value="direct" <?php selected($enrollmentForm, 'direct'); ?>>
                    <?php esc_html_e('Geen (directe inschrijving)', 'stride'); ?>
                </option>
            </select>

            <div id="stride-approval-controls" style="margin-top: 10px; <?= $hasForm ? '' : 'display:none;' ?>">
                <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                    <input type="hidden" name="ntdst_fields[requires_approval]" value="0">
                    <input type="checkbox" name="ntdst_fields[requires_approval]" value="1"
                           <?php checked($requiresApproval); ?>>
                    <span style="font-weight: 600; font-size: 12px;">
                        <?php esc_html_e('Goedkeuring vereist', 'stride'); ?>
                    </span>
                </label>
                <p class="description" style="margin-top: 6px; font-size: 11px;">
                    <?php esc_html_e('Inschrijvingen wachten op goedkeuring door een beheerder.', 'stride'); ?>
                </p>
            </div>
        </div>

        <div class="stride-sidebar-section">
            <h4><?php esc_html_e('Tijdens inschrijving', 'stride'); ?></h4>
            <p class="description" style="margin-bottom: 8px; font-size: 11px;">
                <?php esc_html_e('Taken die deelnemers moeten voltooien na inschrijving.', 'stride'); ?>
            </p>
            <?php foreach ($duringRequirements as $key => $label): ?>
                <?php $checked = (bool) $model->getMeta($post->ID, $key); ?>
                <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; margin-bottom: 6px;">
                    <input type="hidden" name="ntdst_fields[<?= esc_attr($key) ?>]" value="0">
                    <input type="checkbox" name="ntdst_fields[<?= esc_attr($key) ?>]" value="1"
                           <?php checked($checked); ?>>
                    <span style="font-size: 12px;"><?= esc_html($label) ?></span>
                </label>
                <?php if ($key === 'requires_documents'):
                    $docVal = (string) $model->getMeta($post->ID, 'documents_instruction');
                    if ($docVal === '') {
                        $docVal = \Stride\Modules\Enrollment\EnrollmentCompletion::DEFAULT_DOCUMENTS_INSTRUCTION;
                    }
                    ?>
                    <div id="stride-documents-instruction" style="margin: 4px 0 8px 0; <?= $checked ? '' : 'display:none;' ?>">
                        <textarea name="ntdst_fields[documents_instruction]" rows="3"
                                  style="width: 100%; font-size: 12px;"
                                  placeholder="<?php esc_attr_e('Instructie voor de deelnemer…', 'stride'); ?>"><?= esc_textarea($docVal) ?></textarea>
                        <p class="description" style="font-size: 11px;">
                            <?php esc_html_e('Leeg laten = standaardtekst tonen.', 'stride'); ?>
                        </p>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
            <?php
            $questionnaireEnabled = (bool) $model->getMeta($post->ID, 'requires_questionnaire');
        $documentsEnabled     = (bool) $model->getMeta($post->ID, 'requires_documents');
        $gateDeadline         = (string) ($model->getMeta($post->ID, 'gate_deadline') ?: '');
        ?>
            <div id="stride-gate-deadline" style="margin: 4px 0 8px 0; <?= ($questionnaireEnabled || $documentsEnabled) ? '' : 'display:none;' ?>">
                <label style="font-size: 11px; color: #646970; display: block; margin-bottom: 2px;">
                    <?php esc_html_e('Deadline taken (vragenlijst & documenten)', 'stride'); ?>
                </label>
                <input type="date" name="ntdst_fields[gate_deadline]"
                       style="width: 100%; font-size: 12px;"
                       value="<?= esc_attr($gateDeadline) ?>">
            </div>
            <?php if ($duringHint !== ''): ?>
                <p class="description" style="margin-top: 8px; font-size: 11px; color: #646970;">
                    <?= esc_html($duringHint) ?>
                </p>
            <?php endif; ?>
        </div>

        <div class="stride-sidebar-section">
            <h4><?php esc_html_e('Na afloop', 'stride'); ?></h4>
            <p class="description" style="margin-bottom: 8px; font-size: 11px;">
                <?php esc_html_e('Taken die deelnemers moeten voltooien na de opleiding.', 'stride'); ?>
            </p>
            <?php foreach ($postCourseRequirements as $key => $label): ?>
                <?php $checked = (bool) $model->getMeta($post->ID, $key); ?>
                <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; margin-bottom: 6px;">
                    <input type="hidden" name="ntdst_fields[<?= esc_attr($key) ?>]" value="0">
                    <input type="checkbox" name="ntdst_fields[<?= esc_attr($key) ?>]" value="1"
                           <?php checked($checked); ?>>
                    <span style="font-size: 12px;"><?= esc_html($label) ?></span>
                </label>
                <?php if ($key === 'post_requires_documents'):
                    $postDocVal = (string) $model->getMeta($post->ID, 'post_documents_instruction');
                    if ($postDocVal === '') {
                        $postDocVal = \Stride\Modules\Enrollment\EnrollmentCompletion::DEFAULT_DOCUMENTS_INSTRUCTION;
                    }
                    ?>
                    <div id="stride-post-documents-instruction" style="margin: 4px 0 8px 0; <?= $checked ? '' : 'display:none;' ?>">
                        <textarea name="ntdst_fields[post_documents_instruction]" rows="3"
                                  style="width: 100%; font-size: 12px;"
                                  placeholder="<?php esc_attr_e('Instructie voor de deelnemer…', 'stride'); ?>"><?= esc_textarea($postDocVal) ?></textarea>
                        <p class="description" style="font-size: 11px;">
                            <?php esc_html_e('Leeg laten = standaardtekst tonen.', 'stride'); ?>
                        </p>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
            <?php
            $postEvaluationEnabled = (bool) $model->getMeta($post->ID, 'post_requires_evaluation');
        $postDocumentsEnabled  = (bool) $model->getMeta($post->ID, 'post_requires_documents');
        $postGateDeadline      = (string) ($model->getMeta($post->ID, 'post_gate_deadline') ?: '');
        ?>
            <div id="stride-post-gate-deadline" style="margin: 4px 0 8px 0; <?= ($postEvaluationEnabled || $postDocumentsEnabled) ? '' : 'display:none;' ?>">
                <label style="font-size: 11px; color: #646970; display: block; margin-bottom: 2px;">
                    <?php esc_html_e('Deadline afronding (evaluatie & documenten)', 'stride'); ?>
                </label>
                <input type="date" name="ntdst_fields[post_gate_deadline]"
                       style="width: 100%; font-size: 12px;"
                       value="<?= esc_attr($postGateDeadline) ?>">
            </div>
        </div>

        <script>
        jQuery(function($) {
            $('#stride-enrollment-form').on('change', function() {
                $('#stride-approval-controls').toggle($(this).val() !== 'direct');
            });
            $('input[name="ntdst_fields[requires_documents]"][type=checkbox]').on('change', function() {
                $('#stride-documents-instruction').toggle(this.checked);
            });
            $('input[name="ntdst_fields[post_requires_documents]"][type=checkbox]').on('change', function() {
                $('#stride-post-documents-instruction').toggle(this.checked);
            });
            function toggleGateDeadline() {
                var enabled = $('input[name="ntdst_fields[requires_questionnaire]"][type=checkbox]').is(':checked')
                    || $('input[name="ntdst_fields[requires_documents]"][type=checkbox]').is(':checked');
                $('#stride-gate-deadline').toggle(enabled);
            }
            $('input[name="ntdst_fields[requires_questionnaire]"][type=checkbox], input[name="ntdst_fields[requires_documents]"][type=checkbox]').on('change', toggleGateDeadline);
            function togglePostGateDeadline() {
                var enabled = $('input[name="ntdst_fields[post_requires_evaluation]"][type=checkbox]').is(':checked')
                    || $('input[name="ntdst_fields[post_requires_documents]"][type=checkbox]').is(':checked');
                $('#stride-post-gate-deadline').toggle(enabled);
            }
            $('input[name="ntdst_fields[post_requires_evaluation]"][type=checkbox], input[name="ntdst_fields[post_requires_documents]"][type=checkbox]').on('change', togglePostGateDeadline);
        });
        </script>
        <?php
    }
}
