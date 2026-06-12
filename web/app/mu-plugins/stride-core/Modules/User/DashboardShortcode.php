<?php

declare(strict_types=1);

namespace Stride\Modules\User;

use Stride\Domain\RegistrationStatus;
use Stride\Modules\Edition\EditionRepository;
use Stride\Modules\Enrollment\RegistrationRepository;

/**
 * Dashboard shortcode for displaying user enrollments.
 */
final class DashboardShortcode
{
    public function __construct(
        private readonly RegistrationRepository $registrations,
        private readonly EditionRepository $editionRepository,
    ) {
        add_shortcode('stride_my_courses', [$this, 'renderMyCourses']);
    }

    /**
     * Render user's enrolled courses/editions.
     */
    public function renderMyCourses(array $atts = []): string
    {
        if (!is_user_logged_in()) {
            return '<p>Je moet ingelogd zijn om je cursussen te zien.</p>';
        }

        $userId = get_current_user_id();
        $enrollments = $this->registrations->findByUser($userId, RegistrationStatus::Confirmed->value);

        if (empty($enrollments)) {
            return '<div class="uk-alert uk-alert-primary">Je hebt nog geen inschrijvingen.</div>';
        }

        $output = '<div class="uk-grid uk-grid-small uk-child-width-1-1" uk-grid>';

        foreach ($enrollments as $registration) {
            $edition = $this->editionRepository->find((int) $registration->edition_id);

            if (is_wp_error($edition)) {
                continue;
            }

            $startDate = $this->editionRepository->getField($edition->ID, 'start_date', '');
            $venue = $this->editionRepository->getField($edition->ID, 'venue', '');
            $status = $this->editionRepository->getField($edition->ID, 'status', '');

            $output .= sprintf(
                '<div>
                    <div class="uk-card uk-card-default uk-card-body uk-card-small">
                        <h3 class="uk-card-title uk-margin-remove-bottom">%s</h3>
                        <p class="uk-text-meta uk-margin-remove-top">
                            <span uk-icon="calendar"></span> %s
                            %s
                        </p>
                        <span class="uk-label %s">%s</span>
                    </div>
                </div>',
                esc_html($edition->post_title),
                esc_html($startDate ? date_i18n('j F Y', strtotime($startDate)) : 'Datum onbekend'),
                $venue ? '<span uk-icon="location"></span> ' . esc_html($venue) : '',
                $status === 'completed' ? 'uk-label-success' : 'uk-label-primary',
                esc_html(ucfirst($status ?: 'ingeschreven')),
            );
        }

        $output .= '</div>';

        return $output;
    }
}
