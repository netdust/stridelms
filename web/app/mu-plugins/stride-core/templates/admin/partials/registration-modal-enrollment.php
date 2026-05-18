<?php
/**
 * @var array $enrollmentData
 * @var array $sessionSelections   // [['slot_label' => ?string, 'session' => ?array]]
 * @var array $questionnaireAnswers // [question stem => answer]
 * @var array $documents           // [['filename', 'size', 'uploaded_at', 'url']]
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

$skipKeys = ['organisation', 'department'];
?>
<div class="stride-modal-body">
    <section class="stride-modal-section" data-section="form" data-open="1">
        <h3 class="stride-modal-section-title"><?php esc_html_e('Inschrijvingsformulier', 'stride'); ?></h3>
        <?php if (empty($enrollmentData)): ?>
            <p class="stride-modal-empty"><?php esc_html_e('Geen inschrijvingsformulier voor deze editie.', 'stride'); ?></p>
        <?php else: ?>
            <dl class="stride-modal-dl">
                <?php foreach ($enrollmentData as $key => $value): ?>
                    <?php if (in_array($key, $skipKeys, true) || $value === '' || $value === false || $value === null): continue; endif; ?>
                    <div class="stride-form-row" data-key="<?php echo esc_attr((string) $key); ?>">
                        <dt><?php echo esc_html(ucfirst(str_replace('_', ' ', (string) $key))); ?></dt>
                        <dd>
                            <?php
                            if (is_array($value)) {
                                echo esc_html(wp_json_encode($value));
                            } else {
                                echo esc_html($value === true ? __('Ja', 'stride') : (string) $value);
                            }
                            ?>
                        </dd>
                    </div>
                <?php endforeach; ?>
            </dl>
        <?php endif; ?>
    </section>

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
        <p class="stride-modal-empty"><?php esc_html_e('Geen vragenlijst voor deze editie.', 'stride'); ?></p>
    </section>

    <section class="stride-modal-section" data-section="documents" data-open="1">
        <h3 class="stride-modal-section-title"><?php esc_html_e('Documenten', 'stride'); ?></h3>
        <p class="stride-modal-empty"><?php esc_html_e('Geen documenten geüpload.', 'stride'); ?></p>
    </section>
</div>
