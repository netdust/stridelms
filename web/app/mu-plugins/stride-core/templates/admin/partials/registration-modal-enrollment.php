<?php
/**
 * @var array $enrollmentData        Raw enrollment_data (for backward compat; prefer $stages / $initialSelection).
 * @var array $sessionSelections     [['slot_label' => ?string, 'session' => ?array]]
 * @var array $questionnaireAnswers  [question stem => answer]
 * @var array $documents             [['id', 'filename', 'uploaded_at']] — downloads go through the authenticated stride_download_proof handler (protected storage)
 * @var array $initialSelection      Built by RegistrationModalController::buildInitialSelection().
 * @var array $stages                Built by RegistrationModalController::buildStagesForDisplay().
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="stride-modal-body">

    <?php if (!empty($initialSelection)): ?>
    <section class="stride-modal-section" data-section="initial-selection" data-open="1">
        <h3 class="stride-modal-section-title"><?php esc_html_e('Originele keuze', 'stride'); ?></h3>
        <?php foreach ($initialSelection as $phase): ?>
            <div class="stride-modal-phase">
                <div class="stride-modal-phase-header">
                    <strong><?php echo esc_html($phase['phase_label']); ?></strong>
                    <?php if ($phase['captured_at_display'] !== ''): ?>
                        <span class="stride-modal-meta">
                            <?php printf(
                                /* translators: 1: date, 2: person name */
                                esc_html__('Vastgelegd op %1$s door %2$s', 'stride'),
                                esc_html($phase['captured_at_display']),
                                esc_html($phase['captured_by_display']),
                            ); ?>
                        </span>
                    <?php endif; ?>
                </div>
                <ul class="stride-modal-phase-items">
                    <?php foreach ($phase['items'] as $item): ?>
                        <li<?php echo $item['deleted'] ? ' class="stride-modal-deleted"' : ''; ?>>
                            <?php echo esc_html($item['label']); ?>
                            <?php if ($item['deleted']): ?>
                                <span class="stride-modal-deleted-marker"><?php esc_html_e('(verwijderd)', 'stride'); ?></span>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endforeach; ?>
    </section>
    <?php endif; ?>

    <?php if (!empty($stages)): ?>
    <section class="stride-modal-section" data-section="form" data-open="1">
        <h3 class="stride-modal-section-title"><?php esc_html_e('Formuliergegevens', 'stride'); ?></h3>
        <?php foreach ($stages as $stage): ?>
            <div class="stride-modal-stage">
                <div class="stride-modal-stage-header">
                    <strong><?php echo esc_html($stage['label']); ?></strong>
                    <?php if ($stage['submitted_at_display'] !== ''): ?>
                        <span class="stride-modal-meta">
                            <?php printf(
                                /* translators: 1: date, 2: person name */
                                esc_html__('Ingediend op %1$s door %2$s', 'stride'),
                                esc_html($stage['submitted_at_display']),
                                esc_html($stage['submitted_by_display']),
                            ); ?>
                        </span>
                    <?php endif; ?>
                </div>
                <dl class="stride-modal-dl">
                    <?php foreach ($stage['data'] as $key => $value): ?>
                        <?php if ($value === '' || $value === false || $value === null): continue; endif; ?>
                        <div class="stride-form-row" data-key="<?php echo esc_attr((string) $key); ?>">
                            <dt><?php echo esc_html(ucfirst(str_replace('_', ' ', (string) $key))); ?></dt>
                            <dd>
                                <?php
                                if (is_array($value)) {
                                    echo esc_html((string) wp_json_encode($value));
                                } else {
                                    echo esc_html($value === true ? __('Ja', 'stride') : (string) $value);
                                }
                        ?>
                            </dd>
                        </div>
                    <?php endforeach; ?>
                </dl>
            </div>
        <?php endforeach; ?>
    </section>
    <?php elseif (!empty($enrollmentData)): ?>
    <?php
    /*
     * Fallback: enrollment_data exists but is not yet namespace-wrapped.
     * Render flat keys, skipping known non-display fields and envelope-level keys.
     * This branch handles legacy records written before the Task-14 migration.
     */
    $skipKeys = ['organisation', 'department', 'initial_selection', 'interest', 'waitlist',
        'enrollment_personal', 'enrollment_billing', 'intake', 'evaluation'];
        ?>
    <section class="stride-modal-section" data-section="form" data-open="1">
        <h3 class="stride-modal-section-title"><?php esc_html_e('Inschrijvingsformulier', 'stride'); ?></h3>
        <dl class="stride-modal-dl">
            <?php foreach ($enrollmentData as $key => $value): ?>
                <?php if (in_array($key, $skipKeys, true) || $value === '' || $value === false || $value === null): continue; endif; ?>
                <div class="stride-form-row" data-key="<?php echo esc_attr((string) $key); ?>">
                    <dt><?php echo esc_html(ucfirst(str_replace('_', ' ', (string) $key))); ?></dt>
                    <dd>
                        <?php
                            if (is_array($value)) {
                                echo esc_html((string) wp_json_encode($value));
                            } else {
                                echo esc_html($value === true ? __('Ja', 'stride') : (string) $value);
                            }
                ?>
                    </dd>
                </div>
            <?php endforeach; ?>
        </dl>
    </section>
    <?php else: ?>
    <section class="stride-modal-section" data-section="form" data-open="1">
        <h3 class="stride-modal-section-title"><?php esc_html_e('Inschrijvingsformulier', 'stride'); ?></h3>
        <p class="stride-modal-empty"><?php esc_html_e('Geen inschrijvingsformulier voor deze editie.', 'stride'); ?></p>
    </section>
    <?php endif; ?>

    <section class="stride-modal-section" data-section="sessions" data-open="1">
        <h3 class="stride-modal-section-title"><?php esc_html_e('Sessiekeuzes', 'stride'); ?></h3>
        <?php if (empty($sessionSelections)): ?>
            <p class="stride-modal-empty"><?php esc_html_e('Geen sessiekeuze van toepassing.', 'stride'); ?></p>
        <?php else: ?>
            <ul class="stride-modal-sessions">
                <?php foreach ($sessionSelections as $row): ?>
                    <?php $session = $row['session']; ?>
                    <li class="stride-modal-session">
                        <?php if (!empty($row['slot_label'])): ?>
                            <span class="stride-modal-slot-label"><?php echo esc_html($row['slot_label']); ?></span>
                        <?php endif; ?>
                        <span class="stride-modal-session-date">
                            <?php echo esc_html(date_i18n('j M Y', strtotime((string) ($session['date'] ?? '')))); ?>
                        </span>
                        <?php if (!empty($session['start_time'])): ?>
                            <span class="stride-modal-session-time"><?php echo esc_html((string) $session['start_time']); ?></span>
                        <?php endif; ?>
                        <?php if (!empty($session['location'])): ?>
                            <span class="stride-modal-session-loc"><?php echo esc_html((string) $session['location']); ?></span>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <section class="stride-modal-section" data-section="questionnaire" data-open="1">
        <h3 class="stride-modal-section-title"><?php esc_html_e('Vragenlijst', 'stride'); ?></h3>
        <?php if (empty($questionnaireAnswers)): ?>
            <p class="stride-modal-empty"><?php esc_html_e('Geen vragenlijst voor deze editie.', 'stride'); ?></p>
        <?php else: ?>
            <ol class="stride-modal-qa">
                <?php foreach ($questionnaireAnswers as $question => $answer): ?>
                    <li class="stride-modal-qa-item">
                        <div class="stride-modal-qa-q"><?php echo esc_html((string) $question); ?></div>
                        <div class="stride-modal-qa-a">
                            <?php echo esc_html(is_string($answer) ? $answer : (string) wp_json_encode($answer)); ?>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ol>
        <?php endif; ?>
    </section>

    <section class="stride-modal-section" data-section="documents" data-open="1">
        <h3 class="stride-modal-section-title"><?php esc_html_e('Documenten', 'stride'); ?></h3>
        <?php if (empty($documents)): ?>
            <p class="stride-modal-empty"><?php esc_html_e('Geen documenten geüpload.', 'stride'); ?></p>
        <?php else: ?>
            <?php
            // Proofs are in protected storage — download via the authenticated
            // handler. The framework nonce is verified by NTDST_Endpoints;
            // _wpnonce (wp_rest) authenticates the admin's cookie for REST.
            $proofActionUrl = rest_url('ntdst/v1/action');
            $proofNonce = wp_create_nonce('stride_download_proof');
            $proofRestNonce = wp_create_nonce('wp_rest');
            ?>
            <ul class="stride-modal-docs">
                <?php foreach ($documents as $doc): ?>
                    <li class="stride-modal-doc">
                        <?php if (!empty($doc['id'])): ?>
                            <form method="post" action="<?php echo esc_url($proofActionUrl); ?>" class="stride-modal-doc-download">
                                <input type="hidden" name="action" value="stride_download_proof">
                                <input type="hidden" name="nonce" value="<?php echo esc_attr($proofNonce); ?>">
                                <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($proofRestNonce); ?>">
                                <input type="hidden" name="attachment_id" value="<?php echo esc_attr((string) $doc['id']); ?>">
                                <button type="submit" class="button-link">
                                    <span class="dashicons dashicons-media-default"></span>
                                    <?php echo esc_html($doc['filename']); ?>
                                </button>
                            </form>
                        <?php else: ?>
                            <span><?php echo esc_html($doc['filename']); ?></span>
                        <?php endif; ?>
                        <?php if (!empty($doc['uploaded_at'])): ?>
                            <span class="stride-modal-doc-date">
                                <?php echo esc_html(date_i18n('j M Y', strtotime((string) $doc['uploaded_at']))); ?>
                            </span>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
</div>
