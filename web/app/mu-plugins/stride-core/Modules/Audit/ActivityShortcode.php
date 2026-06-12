<?php

declare(strict_types=1);

namespace Stride\Modules\Audit;

use NTDST\Audit\AuditService;
use Stride\Modules\Edition\EditionRepository;

final class ActivityShortcode
{
    public function __construct(
        private readonly AuditService $auditService,
        private readonly EditionRepository $editions,
    ) {
        add_shortcode('stride_my_activity', [$this, 'renderMilestones']);
    }

    /**
     * Render user's milestone activity.
     */
    public function renderMilestones(array $atts = []): string
    {
        if (!is_user_logged_in()) {
            return '<p>Je moet ingelogd zijn om je activiteit te zien.</p>';
        }

        $userId = get_current_user_id();
        $milestones = $this->auditService->getMilestonesForUser($userId);

        if (empty($milestones)) {
            return '<div class="uk-alert uk-alert-primary">Nog geen activiteit gevonden.</div>';
        }

        $output = '<div class="uk-timeline">';

        foreach ($milestones as $milestone) {
            $context = json_decode($milestone->context ?? '{}', true) ?: [];
            $date = date_i18n('j F Y', strtotime($milestone->created_at));
            $icon = $this->getIcon($milestone->action);
            $label = $this->getLabel($milestone->action, $context);

            $output .= sprintf(
                '<div class="uk-timeline-item">
                    <div class="uk-timeline-icon">
                        <span uk-icon="%s"></span>
                    </div>
                    <div class="uk-timeline-content uk-card uk-card-default uk-card-body uk-card-small">
                        <p class="uk-text-meta uk-margin-remove-bottom">%s</p>
                        <p class="uk-margin-remove-top">%s</p>
                    </div>
                </div>',
                esc_attr($icon),
                esc_html($date),
                wp_kses_post($label),
            );
        }

        $output .= '</div>';

        return $output;
    }

    private function getIcon(string $action): string
    {
        return match ($action) {
            'registration.created' => 'check',
            'completion.course_completed' => 'star',
            'completion.certificate_issued' => 'file-pdf',
            default => 'info',
        };
    }

    private function getLabel(string $action, array $context): string
    {
        return match ($action) {
            'registration.created' => $this->getRegistrationLabel($context),
            'completion.course_completed' => $this->getCompletionLabel($context),
            'completion.certificate_issued' => $this->getCertificateLabel($context),
            default => 'Activiteit geregistreerd',
        };
    }

    private function getRegistrationLabel(array $context): string
    {
        $editionId = $context['edition_id'] ?? 0;
        $edition = $editionId ? $this->editions->find($editionId) : null;

        if ($edition && !is_wp_error($edition)) {
            return sprintf('Je hebt je ingeschreven voor <strong>%s</strong>.', esc_html($edition->post_title));
        }

        return 'Je hebt je ingeschreven voor een cursus.';
    }

    private function getCompletionLabel(array $context): string
    {
        $courseTitle = $context['course_title'] ?? '';

        if ($courseTitle) {
            return sprintf('Je hebt <strong>%s</strong> afgerond.', esc_html($courseTitle));
        }

        return 'Je hebt een cursus afgerond.';
    }

    private function getCertificateLabel(array $context): string
    {
        $courseTitle = $context['course_title'] ?? '';

        if ($courseTitle) {
            return sprintf('Certificaat uitgereikt voor <strong>%s</strong>.', esc_html($courseTitle));
        }

        return 'Certificaat uitgereikt.';
    }
}
