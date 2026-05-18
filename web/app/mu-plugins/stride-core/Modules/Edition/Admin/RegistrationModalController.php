<?php

declare(strict_types=1);

namespace Stride\Modules\Edition\Admin;

use Stride\Modules\Edition\EditionService;
use Stride\Modules\Edition\SessionSelection;
use Stride\Modules\Edition\SessionService;
use Stride\Modules\Enrollment\RegistrationRepository;

/**
 * Server-renders enrollment-data and completion-data modals
 * for the deelnemers panel on a vad_edition post.
 */
final class RegistrationModalController
{
    public const NONCE_AJAX = 'stride_edition_admin';
    public const AJAX_ACTION = 'stride_get_registration_modal';

    public function __construct(
        private readonly EditionService $editionService,
        private readonly SessionService $sessionService,
        private readonly SessionSelection $sessionSelection,
        private readonly RegistrationRepository $registrations,
    ) {
        $this->init();
    }

    private function init(): void
    {
        if (!is_admin()) {
            return;
        }

        add_action('wp_ajax_' . self::AJAX_ACTION, [$this, 'ajaxGetModal']);
    }

    public function ajaxGetModal(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Onvoldoende rechten.', 'stride')], 403);
            return;
        }

        $nonce = isset($_REQUEST['nonce']) ? sanitize_text_field((string) $_REQUEST['nonce']) : '';
        if (!wp_verify_nonce($nonce, self::NONCE_AJAX)) {
            wp_send_json_error(['message' => __('Ongeldige sessie. Herlaad de pagina.', 'stride')], 403);
            return;
        }

        $registrationId = isset($_REQUEST['registration_id']) ? (int) $_REQUEST['registration_id'] : 0;
        $type = isset($_REQUEST['type']) ? sanitize_key((string) $_REQUEST['type']) : '';

        if ($registrationId <= 0 || !in_array($type, ['enrollment', 'completion'], true)) {
            wp_send_json_error(['message' => __('Ongeldige aanvraag.', 'stride')], 400);
            return;
        }

        wp_send_json_success(['html' => '', 'title' => '']);
    }

    /**
     * Build the payload (title + html) for a modal, or a WP_Error.
     *
     * @return array{title: string, html: string}|\WP_Error
     */
    public function buildPayload(int $registrationId, string $type): array|\WP_Error
    {
        $registration = $this->registrations->find($registrationId);
        if (!$registration) {
            return new \WP_Error(
                'registration_not_found',
                __('Inschrijving niet gevonden.', 'stride'),
            );
        }

        $userId = (int) $registration->user_id;
        $anonymisedAt = (int) get_user_meta($userId, '_stride_anonymised_at', true);
        if ($anonymisedAt > 0) {
            return new \WP_Error(
                'user_unavailable',
                __('Gegevens van deze gebruiker zijn niet meer beschikbaar.', 'stride'),
            );
        }

        $user = get_userdata($userId);
        if (!$user) {
            return new \WP_Error(
                'user_unavailable',
                __('Gegevens van deze gebruiker zijn niet meer beschikbaar.', 'stride'),
            );
        }

        $editionId = (int) $registration->edition_id;
        $edition = $this->editionService->getEdition($editionId);
        $editionTitle = $edition instanceof \WP_Post ? $edition->post_title : '';

        return [
            'title' => $this->buildTitle($type, $user->display_name, $editionTitle),
            'html' => '',
        ];
    }

    private function buildTitle(string $type, string $userName, string $editionTitle): string
    {
        if ($type === 'completion') {
            return sprintf(
                /* translators: %s: user display name */
                __('Voltooiing — %s', 'stride'),
                $userName,
            );
        }

        return sprintf(
            /* translators: 1: user display name, 2: edition title */
            __('Inschrijving — %1$s — %2$s', 'stride'),
            $userName,
            $editionTitle,
        );
    }
}
