<?php

declare(strict_types=1);

namespace Stride\Handlers;

use WP_Error;

/**
 * Handles user profile API requests.
 *
 * Thin handler - validates input, updates user data.
 * Supports personal, billing, and notification form types.
 */
final class ProfileHandler
{
    public function __construct()
    {
        $this->init();
    }

    private function init(): void
    {
        add_filter('ntdst/api_data/stride_update_profile', [$this, 'handleUpdateProfile'], 10, 2);
        add_filter('ntdst/api_data/stride_gdpr_export', [$this, 'handleGdprExport'], 10, 2);
        add_filter('ntdst/api_data/stride_gdpr_erase', [$this, 'handleGdprErase'], 10, 2);
    }

    /**
     * Handle profile update request.
     *
     * Routes to specific handler based on form_type parameter.
     *
     * @param mixed $data Existing data (unused)
     * @param array<string, mixed> $params Request parameters
     * @return array<string, mixed>|WP_Error
     */
    public function handleUpdateProfile(mixed $data, array $params): array|WP_Error
    {
        $userId = get_current_user_id();
        if (!$userId) {
            return new WP_Error('not_logged_in', __('Je moet ingelogd zijn.', 'stride'));
        }

        $formType = sanitize_text_field($params['form_type'] ?? 'personal');

        return match ($formType) {
            'billing' => $this->updateBilling($userId, $params),
            'notifications' => $this->updateNotifications($userId, $params),
            'profile_type' => $this->updateProfileType($userId, $params),
            default => $this->updatePersonal($userId, $params),
        };
    }

    /**
     * Update personal profile data.
     *
     * Partial update: only fields present in $params are written. Missing
     * keys are left untouched. Empty-string values still wipe the field —
     * that's an explicit clear by the user.
     *
     * @param int $userId User ID
     * @param array<string, mixed> $params Request parameters
     * @return array<string, mixed>|WP_Error
     */
    private function updatePersonal(int $userId, array $params): array|WP_Error
    {
        // Build the wp_update_user payload only with keys actually posted.
        // display_name is recomputed only when first/last name was sent.
        $userUpdate = ['ID' => $userId];
        if (isset($params['first_name'])) {
            $userUpdate['first_name'] = sanitize_text_field($params['first_name']);
        }
        if (isset($params['last_name'])) {
            $userUpdate['last_name'] = sanitize_text_field($params['last_name']);
        }
        if (isset($userUpdate['first_name']) || isset($userUpdate['last_name'])) {
            $current = get_userdata($userId);
            $first = $userUpdate['first_name'] ?? ($current->first_name ?? '');
            $last = $userUpdate['last_name'] ?? ($current->last_name ?? '');
            $userUpdate['display_name'] = trim($first . ' ' . $last);
        }

        if (count($userUpdate) > 1) {
            $result = wp_update_user($userUpdate);
            if (is_wp_error($result)) {
                return $result;
            }
        }

        $metaFields = [
            'phone' => 'phone',
            'organisation' => 'organisation',
            'department' => 'department',
        ];

        foreach ($metaFields as $inputKey => $metaKey) {
            if (isset($params[$inputKey])) {
                update_user_meta($userId, $metaKey, sanitize_text_field($params[$inputKey]));
            }
        }

        ntdst_log('profile')->info('Personal profile updated', [
            'user_id' => $userId,
        ]);

        return [
            'success' => true,
            'message' => __('Persoonlijke gegevens bijgewerkt.', 'stride'),
        ];
    }

    /**
     * Update billing profile data.
     *
     * Partial update: only fields present in $params are written.
     *
     * @param int $userId User ID
     * @param array<string, mixed> $params Request parameters
     * @return array<string, mixed>|WP_Error
     */
    private function updateBilling(int $userId, array $params): array|WP_Error
    {
        // input key => [meta key, sanitiser]
        $billingMap = [
            'company'       => ['billing_company',    'sanitize_text_field'],
            'vat_number'    => ['billing_vat',        'sanitize_text_field'],
            'address'       => ['billing_address_1',  'sanitize_text_field'],
            'postal_code'   => ['billing_postcode',   'sanitize_text_field'],
            'city'          => ['billing_city',       'sanitize_text_field'],
            'invoice_email' => ['invoice_email',      'sanitize_email'],
            'gln_number'    => ['gln_number',         'sanitize_text_field'],
        ];

        foreach ($billingMap as $inputKey => [$metaKey, $sanitiser]) {
            if (isset($params[$inputKey])) {
                update_user_meta($userId, $metaKey, $sanitiser($params[$inputKey]));
            }
        }

        ntdst_log('profile')->info('Billing profile updated', [
            'user_id' => $userId,
        ]);

        return [
            'success' => true,
            'message' => __('Facturatiegegevens bijgewerkt.', 'stride'),
        ];
    }

    /**
     * Handle GDPR data export request.
     *
     * Uses WordPress built-in privacy request system (GDPR export_personal_data).
     * Sends a confirmation email to the user; once confirmed, WP generates the export.
     */
    public function handleGdprExport(mixed $data, array $params): array|WP_Error
    {
        $userId = get_current_user_id();
        if (!$userId) {
            return new WP_Error('not_logged_in', __('Je moet ingelogd zijn.', 'stride'));
        }

        $user = get_userdata($userId);
        $requestId = wp_create_user_request($user->user_email, 'export_personal_data');

        if (is_wp_error($requestId)) {
            return $requestId;
        }

        wp_send_user_request($requestId);

        ntdst_log('profile')->info('GDPR export requested', ['user_id' => $userId]);

        return [
            'success' => true,
            'message' => __('Je ontvangt een bevestigingsmail om de export te starten.', 'stride'),
        ];
    }

    /**
     * Handle GDPR account erasure request.
     *
     * The previous flow used wp_create_user_request → user-confirms → WP's
     * privacy_personal_data_erasers fires. That callback (in ntdst-auth)
     * only wiped 3 consent meta keys and left every Stride business record
     * intact. Worse, it ran without admin awareness — VAD couldn't talk to
     * the user first about open invoices, certificates in progress, etc.
     *
     * New flow: the request becomes an admin ticket. We email the configured
     * stride_admin_email, log the request in the audit trail, and reply to
     * the user that admin will follow up. Admin then runs anonymise() (or
     * the WP hard-delete row action) manually after assessing the situation.
     *
     * No automated anonymise — the choice between anonymise (keep business
     * records pseudonymised) and hard-delete (nuke everything) is context-
     * dependent and needs a human decision.
     */
    public function handleGdprErase(mixed $data, array $params): array|WP_Error
    {
        $userId = get_current_user_id();
        if (!$userId) {
            return new WP_Error('not_logged_in', __('Je moet ingelogd zijn.', 'stride'));
        }

        $user = get_userdata($userId);
        if (!$user) {
            return new WP_Error('user_not_found', __('Gebruiker niet gevonden.', 'stride'));
        }

        $adminEmail = \Stride\Modules\Mail\StrideMailBridge::getAdminEmail();
        if (!$adminEmail) {
            ntdst_log('profile')->error('GDPR erasure request: no admin email configured', [
                'user_id' => $userId,
            ]);
            return new WP_Error('no_admin_email', __('Er kon geen aanvraag verstuurd worden. Neem rechtstreeks contact op met de beheerder.', 'stride'));
        }

        $reason = sanitize_textarea_field((string) ($params['reason'] ?? ''));

        $userLine = sprintf(
            '%s <%s> (ID %d)',
            $user->display_name ?: $user->user_login,
            $user->user_email,
            $userId
        );

        $body = sprintf(
            "Gebruiker %s heeft via /mijn-account/?tab=profiel om verwijdering van het account verzocht.\n\n",
            $userLine
        );
        if ($reason !== '') {
            $body .= "Toelichting van de gebruiker:\n" . $reason . "\n\n";
        }
        $body .= "Volgende stappen voor de beheerder:\n"
            . "1. Neem contact op met de gebruiker om de aanvraag te bevestigen.\n"
            . "2. Anonimiseer het account via Gebruikers → " . get_edit_user_link($userId) . "\n"
            . "   (of kies hard delete als geen historie bewaard moet blijven).\n\n"
            . "Deze aanvraag is automatisch gelogd in de audit trail.";

        $sent = wp_mail(
            $adminEmail,
            sprintf(__('[Stride] Account-verwijdering aangevraagd door %s', 'stride'), $user->display_name ?: $user->user_email),
            $body
        );

        ntdst_log('profile')->info('GDPR erasure request forwarded to admin', [
            'user_id' => $userId,
            'admin_email' => $adminEmail,
            'mail_sent' => $sent,
            'reason_provided' => $reason !== '',
        ]);

        do_action('stride/gdpr/erasure_requested', [
            'user_id' => $userId,
            'email' => $user->user_email,
            'reason' => $reason,
            'requested_at' => time(),
        ]);

        return [
            'success' => true,
            'message' => __('Je aanvraag is doorgestuurd. De beheerder neemt zo snel mogelijk contact met je op.', 'stride'),
        ];
    }

    /**
     * Update user's profile type.
     *
     * @param int $userId User ID
     * @param array<string, mixed> $params Request parameters
     * @return array<string, mixed>|WP_Error
     */
    private function updateProfileType(int $userId, array $params): array|WP_Error
    {
        $slug = sanitize_text_field($params['profile_type'] ?? '');

        if (empty($slug)) {
            return new WP_Error('missing_type', __('Kies een profieltype.', 'stride'));
        }

        $service = ntdst_get(\Stride\Modules\User\ProfileTypeService::class);

        if (!$service->setUserType($userId, $slug)) {
            return new WP_Error('invalid_type', __('Ongeldig profieltype.', 'stride'));
        }

        ntdst_log('profile')->info('Profile type updated', [
            'user_id' => $userId,
            'profile_type' => $slug,
        ]);

        return [
            'success' => true,
            'message' => __('Profieltype bijgewerkt.', 'stride'),
        ];
    }

    /**
     * Update notification preferences.
     *
     * @param int $userId User ID
     * @param array<string, mixed> $params Request parameters
     * @return array<string, mixed>|WP_Error
     */
    private function updateNotifications(int $userId, array $params): array|WP_Error
    {
        $notifyReminders = isset($params['notify_reminders']) ? 'yes' : 'no';
        $notifyNewCourses = isset($params['notify_new_courses']) ? 'yes' : 'no';
        $notifyNewsletter = isset($params['notify_newsletter']) ? 'yes' : 'no';

        update_user_meta($userId, 'stride_notify_reminders', $notifyReminders);
        update_user_meta($userId, 'stride_notify_new_courses', $notifyNewCourses);
        update_user_meta($userId, 'stride_notify_newsletter', $notifyNewsletter);

        $allowedLanguages = ['nl', 'fr', 'en'];
        $language = sanitize_text_field($params['communication_language'] ?? 'nl');
        if (!in_array($language, $allowedLanguages, true)) {
            $language = 'nl';
        }
        update_user_meta($userId, 'stride_communication_language', $language);

        ntdst_log('profile')->info('Notification preferences updated', [
            'user_id' => $userId,
        ]);

        return [
            'success' => true,
            'message' => __('Meldingsvoorkeuren bijgewerkt.', 'stride'),
        ];
    }
}
