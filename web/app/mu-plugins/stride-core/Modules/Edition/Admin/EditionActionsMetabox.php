<?php

declare(strict_types=1);

namespace Stride\Modules\Edition\Admin;

use Stride\Domain\OfferingStatus;
use Stride\Modules\Edition\EditionService;
use WP_Post;

/**
 * Edition Actions Metabox - Sidebar.
 *
 * Renders status, capacity, and edition controls.
 */
final class EditionActionsMetabox
{
    public function __construct(
        private readonly EditionService $editionService,
    ) {}

    public function render(WP_Post $post): void
    {
        // For new editions, show simple save prompt
        if ($post->post_status === 'auto-draft') {
            ?>
            <div style="text-align: center; padding: 20px 0; color: #646970;">
                <span class="dashicons dashicons-edit" style="font-size: 32px; width: 32px; height: 32px;"></span>
                <p><?php esc_html_e('Selecteer een cursus en vul de details in, sla dan op.', 'stride'); ?></p>
            </div>
            <?php
            return;
        }

        $status = $this->editionService->getStatus($post->ID);
        $capacity = $this->editionService->getCapacity($post->ID);
        $registeredCount = $this->editionService->getRegisteredCount($post->ID);
        $courseId = $this->editionService->getCourseId($post->ID);

        $statusConfig = $this->getStatusConfig($status);
        ?>
        <style>
            .stride-edition-sidebar .stride-sidebar-status {
                background: <?php echo esc_attr($statusConfig['bg']); ?>;
                border-bottom: 2px solid <?php echo esc_attr($statusConfig['color']); ?>;
                padding: 12px;
                margin: -12px -12px 12px -12px;
            }
            .stride-edition-sidebar .stride-sidebar-status .dashicons,
            .stride-edition-sidebar .stride-sidebar-status .status-label {
                color: <?php echo esc_attr($statusConfig['color']); ?>;
            }
        </style>

        <div class="stride-edition-sidebar">
            <!-- Status Header -->
            <div class="stride-sidebar-status">
                <span class="dashicons dashicons-<?php echo esc_attr($statusConfig['icon']); ?>" style="vertical-align: middle;"></span>
                <span class="status-label" style="font-weight: 600; margin-left: 4px;"><?php echo esc_html($statusConfig['label']); ?></span>
            </div>

            <!-- Capacity Section -->
            <?php $this->renderCapacitySection($capacity, $registeredCount); ?>

            <!-- Meta Info -->
            <?php $this->renderMetaInfo($post, $courseId); ?>

            <!-- Status Change -->
            <?php $this->renderStatusSection($status); ?>

            <!-- Enrollment Warnings -->
            <?php $this->renderWarnings($post); ?>

            <!-- Enrollment Requirements -->
            <?php $this->renderRequirementsSection($post); ?>

            <!-- Requires Approval -->
            <?php $this->renderApprovalSection($post); ?>
        </div>
        <?php
    }

    private function getStatusConfig(OfferingStatus $status): array
    {
        $badge = $status->badgeConfig();

        return [
            'label' => $status->label(),
            'color' => $badge['color'],
            'bg' => $badge['bg'],
            'icon' => $badge['icon'],
        ];
    }

    private function renderCapacitySection(int $capacity, int $registeredCount): void
    {
        ?>
        <div class="stride-sidebar-section">
            <h4><?php esc_html_e('Capaciteit', 'stride'); ?></h4>

            <?php if ($capacity > 0): ?>
                <?php
                $percentage = min(100, round(($registeredCount / $capacity) * 100));
                $barClass = '';
                if ($percentage >= 100) {
                    $barClass = 'full';
                } elseif ($percentage >= 80) {
                    $barClass = 'warning';
                }
                ?>
                <div class="stride-capacity-bar <?php echo esc_attr($barClass); ?>">
                    <div class="stride-capacity-fill" style="width: <?php echo esc_attr($percentage); ?>%;"></div>
                </div>
                <div class="stride-capacity-text">
                    <?php echo esc_html(sprintf('%d / %d %s', $registeredCount, $capacity, __('plaatsen', 'stride'))); ?>
                </div>
            <?php else: ?>
                <div class="stride-capacity-text">
                    <?php echo esc_html(sprintf('%d %s', $registeredCount, __('inschrijvingen', 'stride'))); ?>
                    <br>
                    <small style="color: #646970;"><?php esc_html_e('(geen limiet)', 'stride'); ?></small>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    private function renderMetaInfo(WP_Post $post, ?int $courseId): void
    {
        $course = $courseId ? get_post($courseId) : null;
        ?>
        <div class="stride-sidebar-section">
            <ul class="stride-sidebar-meta">
                <li>
                    <span class="meta-label"><?php esc_html_e('Cursus', 'stride'); ?></span>
                    <span class="meta-value">
                        <?php if ($course): ?>
                            <a href="<?php echo esc_url(get_edit_post_link($course->ID)); ?>">
                                <?php echo esc_html(wp_trim_words($course->post_title, 5)); ?>
                            </a>
                        <?php else: ?>
                            <span style="color: #646970;">-</span>
                        <?php endif; ?>
                    </span>
                </li>
                <li>
                    <span class="meta-label"><?php esc_html_e('Aangemaakt', 'stride'); ?></span>
                    <span class="meta-value"><?php echo esc_html(date_i18n('d M Y', strtotime($post->post_date))); ?></span>
                </li>
                <li>
                    <span class="meta-label"><?php esc_html_e('Gewijzigd', 'stride'); ?></span>
                    <span class="meta-value"><?php echo esc_html(date_i18n('d M Y', strtotime($post->post_modified))); ?></span>
                </li>
            </ul>
        </div>
        <?php
    }

    private function renderWarnings(WP_Post $post): void
    {
        if ($post->post_status === 'auto-draft') {
            return;
        }

        $status = $this->editionService->getStatus($post->ID);
        if (!$status->allowsEnrollment()) {
            return;
        }

        $model = ntdst_data()->get('vad_edition');
        $enrollmentForm = $model->getMeta($post->ID, 'enrollment_form') ?: 'default';
    }

    private function renderRequirementsSection(WP_Post $post): void
    {
        if ($post->post_status === 'auto-draft') {
            return;
        }

        $model = ntdst_data()->get('vad_edition');

        $requirements = [
            'requires_questionnaire'     => __('Vragenlijst invullen', 'stride'),
            'requires_documents'         => __('Documenten uploaden', 'stride'),
            'requires_session_selection' => __('Sessiekeuze vereist', 'stride'),
        ];

        $selectionOpen     = (bool) $model->getMeta($post->ID, 'selection_open');
        $selectionDeadline = $model->getMeta($post->ID, 'selection_deadline') ?: '';
        $hasSessionReq     = (bool) $model->getMeta($post->ID, 'requires_session_selection');
        ?>
        <div class="stride-sidebar-section">
            <div class="stride-classroom-only">
                <h4><?php esc_html_e('Inschrijfvereisten', 'stride'); ?></h4>
                <p class="description" style="margin-bottom: 8px; font-size: 11px;">
                    <?php esc_html_e('Deelnemers moeten deze stappen voltooien na inschrijving.', 'stride'); ?>
                </p>
                <?php foreach ($requirements as $key => $label): ?>
                    <?php $checked = (bool) $model->getMeta($post->ID, $key); ?>
                    <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; margin-bottom: 6px;">
                        <input type="hidden" name="ntdst_fields[<?= esc_attr($key) ?>]" value="0">
                        <input type="checkbox" name="ntdst_fields[<?= esc_attr($key) ?>]" value="1"
                               <?php checked($checked); ?>>
                        <span style="font-size: 12px;"><?= esc_html($label) ?></span>
                    </label>
                <?php endforeach; ?>
            </div>

            <!-- Post-course requirements -->
            <div style="margin-top: 10px; padding-top: 8px; border-top: 1px solid #e0e0e0;">
                <h4><?php esc_html_e('Na afloop', 'stride'); ?></h4>
                <p class="description" style="margin-bottom: 8px; font-size: 11px;">
                    <?php esc_html_e('Taken die deelnemers moeten voltooien na de opleiding.', 'stride'); ?>
                </p>
                <?php
                $postCourseRequirements = [
                    'post_requires_evaluation' => __('Evaluatie invullen', 'stride'),
                    'post_requires_documents'  => __('Documenten uploaden', 'stride'),
                    'post_requires_approval'   => __('Goedkeuring beheerder', 'stride'),
                ];
                ?>
                <?php foreach ($postCourseRequirements as $key => $label): ?>
                    <?php $checked = (bool) $model->getMeta($post->ID, $key); ?>
                    <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; margin-bottom: 6px;">
                        <input type="hidden" name="ntdst_fields[<?= esc_attr($key) ?>]" value="0">
                        <input type="checkbox" name="ntdst_fields[<?= esc_attr($key) ?>]" value="1"
                               <?php checked($checked); ?>>
                        <span style="font-size: 12px;"><?= esc_html($label) ?></span>
                    </label>
                <?php endforeach; ?>
            </div>

            <!-- Enrollment form -->
            <?php $enrollmentForm = $model->getMeta($post->ID, 'enrollment_form') ?: 'default'; ?>
            <div style="margin-top: 10px; padding-top: 8px; border-top: 1px solid #e0e0e0;">
                <label style="font-size: 11px; color: #646970; display: block; margin-bottom: 2px;">
                    <?php esc_html_e('Inschrijfformulier', 'stride'); ?>
                </label>
                <select name="ntdst_fields[enrollment_form]" style="width: 100%; font-size: 12px;">
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
            </div>

            <!-- Active field groups (read-only) -->
            <?php
            $fieldGroupsService = ntdst_get(\Stride\Modules\Enrollment\EnrollmentFieldGroups::class);
            $activeGroups = $fieldGroupsService->getFieldGroupsForPost($post->ID, 'vad_edition');
            if (!empty($activeGroups)) : ?>
                <div style="margin-top: 8px;">
                    <span style="font-size: 11px; color: #646970;"><?php esc_html_e('Actieve veldgroepen:', 'stride'); ?></span>
                    <ul style="margin: 4px 0 0; padding: 0; list-style: none;">
                        <?php foreach ($activeGroups as $group) : ?>
                            <li style="font-size: 11px; padding: 2px 0; color: #50575e;">
                                <span class="dashicons dashicons-yes-alt" style="font-size: 14px; width: 14px; height: 14px; color: #2271b1; vertical-align: text-bottom;"></span>
                                <?php echo esc_html($group['label']); ?>
                                <span style="color: #a0a0a0;">(<?php echo esc_html($group['step'] === 'billing' ? 'facturatie' : 'persoonlijk'); ?>)</span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=stride-field-groups')); ?>" style="font-size: 11px;">
                        <?php esc_html_e('Veldgroepen beheren', 'stride'); ?> &rarr;
                    </a>
                </div>
            <?php endif; ?>

            <!-- Session selection controls (visible when session selection is required) -->
            <div style="margin-top: 10px; padding-top: 8px; border-top: 1px solid #e0e0e0; <?= $hasSessionReq ? '' : 'display:none;' ?>"
                 id="stride-selection-controls">
                <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; margin-bottom: 6px;">
                    <input type="hidden" name="ntdst_fields[selection_open]" value="0">
                    <input type="checkbox" name="ntdst_fields[selection_open]" value="1"
                           <?php checked($selectionOpen); ?>>
                    <span style="font-size: 12px; font-weight: 600;"><?php esc_html_e('Sessiekeuze geopend', 'stride'); ?></span>
                </label>
                <div style="margin-top: 4px;">
                    <label style="font-size: 11px; color: #646970; display: block; margin-bottom: 2px;">
                        <?php esc_html_e('Deadline', 'stride'); ?>
                    </label>
                    <input type="date" name="ntdst_fields[selection_deadline]"
                           value="<?= esc_attr($selectionDeadline) ?>"
                           style="width: 100%; font-size: 12px;">
                </div>
            </div>
        </div>

        <script>
        jQuery(function($) {
            $('input[name="ntdst_fields[requires_session_selection]"]').on('change', function() {
                $('#stride-selection-controls').toggle($(this).is(':checked'));
            });
        });
        </script>
        <?php
    }

    private function renderApprovalSection(WP_Post $post): void
    {
        $requiresApproval = (bool) $this->editionService->requiresApproval($post->ID);
        ?>
        <div class="stride-sidebar-section stride-classroom-only">
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
        <?php
    }

    private function renderStatusSection(OfferingStatus $currentStatus): void
    {
        ?>
        <div class="stride-sidebar-section">
            <h4><?php esc_html_e('Status', 'stride'); ?></h4>
            <select name="stride_change_status" id="stride_change_status" class="stride-status-select">
                <?php foreach (OfferingStatus::cases() as $status): ?>
                    <option value="<?php echo esc_attr($status->value); ?>" <?php echo $currentStatus === $status ? 'selected' : ''; ?>>
                        <?php echo esc_html($status->label()); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php
    }
}
