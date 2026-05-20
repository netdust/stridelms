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

            <!-- Enrollment Requirements (includes form selector + approval) -->
            <?php $this->renderRequirementsSection($post); ?>

            <!-- Quote bulk lock/unlock -->
            <?php $this->renderQuotesLockSection($post); ?>
        </div>
        <?php
    }

    /**
     * Bulk lock/unlock all quotes linked to this edition.
     *
     * Admin-driven, no automatic cron: clicking "Vergrendel alle offertes"
     * loops the edition's quotes and sets locked=true. Individual quotes
     * can still be unlocked separately afterward.
     */
    private function renderQuotesLockSection(WP_Post $post): void
    {
        if ($post->post_status === 'auto-draft') {
            return;
        }

        $quoteRepo = ntdst_get(\Stride\Modules\Invoicing\QuoteRepository::class);
        $quotes = $quoteRepo->findByEdition($post->ID);
        $total = count($quotes);
        $lockedCount = 0;
        foreach ($quotes as $q) {
            $quoteId = (int) ($q['id'] ?? 0);
            if ($quoteId === 0) {
                continue;
            }
            if ($quoteRepo->getField($quoteId, 'locked', false)) {
                $lockedCount++;
            }
        }

        if ($total === 0) {
            return; // Nothing to lock yet
        }

        // The toggle reflects "are all quotes locked?". Any unlocked quote
        // means the action is "Lock all"; if every quote is already locked,
        // the action becomes "Unlock all".
        $allLocked = $lockedCount === $total;
        ?>
        <div class="stride-sidebar-section">
            <h4><?php esc_html_e('Offertes', 'stride'); ?></h4>
            <p class="description" id="stride-quotes-lock-status" style="font-size: 11px; margin-bottom: 8px;">
                <?php echo esc_html(sprintf(
                    /* translators: 1: locked count, 2: total */
                    __('%1$d van %2$d vergrendeld', 'stride'),
                    $lockedCount,
                    $total
                )); ?>
            </p>
            <button type="button"
                    class="button"
                    id="stride-toggle-quotes-lock"
                    data-edition-id="<?php echo esc_attr((string) $post->ID); ?>"
                    data-locked="<?php echo $allLocked ? '1' : '0'; ?>"
                    data-total="<?php echo esc_attr((string) $total); ?>">
                <?php echo $allLocked
                    ? esc_html__('Ontgrendel alle offertes', 'stride')
                    : esc_html__('Vergrendel alle offertes', 'stride'); ?>
            </button>
            <p class="description" style="font-size: 11px; margin-top: 8px;">
                <?php esc_html_e('Vergrendelde offertes kunnen niet meer worden bewerkt door deelnemers. Individuele offertes kunnen apart worden vrijgegeven.', 'stride'); ?>
            </p>
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
        OfferingSidebarPartial::render(
            $post,
            'vad_edition',
            __('Sessiekeuze wordt beheerd in de Sessies-metabox.', 'stride')
        );
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
