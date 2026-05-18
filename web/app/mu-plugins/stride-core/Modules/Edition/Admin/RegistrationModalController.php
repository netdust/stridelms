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
}
