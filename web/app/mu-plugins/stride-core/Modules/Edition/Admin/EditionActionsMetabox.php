<?php

declare(strict_types=1);

namespace Stride\Modules\Edition\Admin;

use Stride\Domain\EditionStatus;
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
        </div>
        <?php
    }

    private function getStatusConfig(EditionStatus $status): array
    {
        return match ($status) {
            EditionStatus::Open => [
                'label' => __('Open voor inschrijving', 'stride'),
                'color' => '#00a32a',
                'bg' => '#ecf7ed',
                'icon' => 'yes-alt',
            ],
            EditionStatus::Full => [
                'label' => __('Volzet', 'stride'),
                'color' => '#d63638',
                'bg' => '#fcf0f1',
                'icon' => 'warning',
            ],
            EditionStatus::Cancelled => [
                'label' => __('Geannuleerd', 'stride'),
                'color' => '#a7aaad',
                'bg' => '#f0f0f1',
                'icon' => 'dismiss',
            ],
            EditionStatus::Postponed => [
                'label' => __('Uitgesteld', 'stride'),
                'color' => '#dba617',
                'bg' => '#fcf9e8',
                'icon' => 'clock',
            ],
            EditionStatus::Announcement => [
                'label' => __('Aankondiging', 'stride'),
                'color' => '#0073aa',
                'bg' => '#e5f5fa',
                'icon' => 'megaphone',
            ],
            EditionStatus::Completed => [
                'label' => __('Afgelopen', 'stride'),
                'color' => '#646970',
                'bg' => '#f6f7f7',
                'icon' => 'flag',
            ],
        };
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

    private function renderStatusSection(EditionStatus $currentStatus): void
    {
        ?>
        <div class="stride-sidebar-section">
            <h4><?php esc_html_e('Status', 'stride'); ?></h4>
            <select name="stride_change_status" id="stride_change_status" class="stride-status-select">
                <?php foreach (EditionStatus::cases() as $status): ?>
                    <option value="<?php echo esc_attr($status->value); ?>" <?php echo $currentStatus === $status ? 'selected' : ''; ?>>
                        <?php echo esc_html($status->label()); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php
    }
}
