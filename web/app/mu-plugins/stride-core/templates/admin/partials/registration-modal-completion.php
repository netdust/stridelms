<?php
/**
 * @var array<string, array{status:string,label:string,completed_at:?string,completed_by:?string}> $taskRows
 * @var int $ldProgress         // 0–100
 * @var ?string $ldCompletionDate
 * @var bool $showAttendance
 * @var float $hoursAttended
 * @var float $hoursTotal
 * @var string $certificateUrl  // '' if none
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="stride-modal-body">
    <section class="stride-modal-section" data-section="tasks" data-open="1">
        <h3 class="stride-modal-section-title"><?php esc_html_e('Status van taken', 'stride'); ?></h3>
        <?php if (empty($taskRows)): ?>
            <p class="stride-modal-empty"><?php esc_html_e('Geen taken voor deze inschrijving.', 'stride'); ?></p>
        <?php else: ?>
            <table class="stride-modal-task-table">
                <thead><tr>
                    <th><?php esc_html_e('Taak', 'stride'); ?></th>
                    <th><?php esc_html_e('Status', 'stride'); ?></th>
                    <th><?php esc_html_e('Voltooid op', 'stride'); ?></th>
                </tr></thead>
                <tbody>
                    <?php foreach ($taskRows as $row): ?>
                        <tr>
                            <td><?php echo esc_html($row['label']); ?></td>
                            <td>
                                <span class="stride-status-badge <?php echo esc_attr($row['status']); ?>">
                                    <?php echo esc_html($row['status']); ?>
                                </span>
                            </td>
                            <td>
                                <?php echo $row['completed_at']
                                    ? esc_html(date_i18n('j M Y H:i', strtotime((string) $row['completed_at'])))
                                    : '—'; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>

    <section class="stride-modal-section" data-section="ld" data-open="1">
        <h3 class="stride-modal-section-title"><?php esc_html_e('LearnDash voortgang', 'stride'); ?></h3>
        <div class="stride-modal-progress">
            <div class="stride-modal-progress-bar">
                <div class="stride-modal-progress-fill" style="width: <?php echo esc_attr((string) (int) $ldProgress); ?>%;"></div>
            </div>
            <span class="stride-modal-progress-pct"><?php echo esc_html((int) $ldProgress . '%'); ?></span>
        </div>
        <?php if (!empty($ldCompletionDate)): ?>
            <p class="stride-modal-ld-date">
                <?php echo esc_html(sprintf(
                    /* translators: %s: completion date */
                    __('Voltooid op %s', 'stride'),
                    date_i18n('j M Y', strtotime((string) $ldCompletionDate)),
                )); ?>
            </p>
        <?php endif; ?>
    </section>

    <?php if (!empty($showAttendance)): ?>
        <section class="stride-modal-section" data-section="attendance" data-open="1">
            <h3 class="stride-modal-section-title"><?php esc_html_e('Aanwezigheid', 'stride'); ?></h3>
            <p>
                <?php echo esc_html(sprintf(
                    /* translators: 1: hours attended, 2: hours required */
                    __('%1$s / %2$s uur', 'stride'),
                    number_format_i18n($hoursAttended, 1),
                    number_format_i18n($hoursTotal, 1),
                )); ?>
            </p>
        </section>
    <?php endif; ?>

    <?php if (!empty($certificateUrl)): ?>
        <section class="stride-modal-section" data-section="cert" data-open="1">
            <h3 class="stride-modal-section-title"><?php esc_html_e('Certificaat', 'stride'); ?></h3>
            <a href="<?php echo esc_url($certificateUrl); ?>" target="_blank" rel="noopener" class="button button-secondary">
                <?php esc_html_e('Bekijk certificaat', 'stride'); ?>
            </a>
        </section>
    <?php endif; ?>
</div>
