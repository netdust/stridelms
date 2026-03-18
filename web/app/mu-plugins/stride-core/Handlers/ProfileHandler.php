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
     * @param int $userId User ID
     * @param array<string, mixed> $params Request parameters
     * @return array<string, mixed>|WP_Error
     */
    private function updatePersonal(int $userId, array $params): array|WP_Error
    {
        $firstName = sanitize_text_field($params['first_name'] ?? '');
        $lastName = sanitize_text_field($params['last_name'] ?? '');

        $result = wp_update_user([
            'ID' => $userId,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'display_name' => trim($firstName . ' ' . $lastName),
        ]);

        if (is_wp_error($result)) {
            return $result;
        }

        $metaFields = [
            'phone' => sanitize_text_field($params['phone'] ?? ''),
            'organisation' => sanitize_text_field($params['organisation'] ?? ''),
            'department' => sanitize_text_field($params['department'] ?? ''),
        ];

        foreach ($metaFields as $key => $value) {
            update_user_meta($userId, $key, $value);
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
     * @param int $userId User ID
     * @param array<string, mixed> $params Request parameters
     * @return array<string, mixed>|WP_Error
     */
    private function updateBilling(int $userId, array $params): array|WP_Error
    {
        $billingFields = [
            'billing_company' => sanitize_text_field($params['company'] ?? ''),
            'billing_vat' => sanitize_text_field($params['vat_number'] ?? ''),
            'billing_address_1' => sanitize_text_field($params['address'] ?? ''),
            'billing_postcode' => sanitize_text_field($params['postal_code'] ?? ''),
            'billing_city' => sanitize_text_field($params['city'] ?? ''),
            'invoice_email' => sanitize_email($params['invoice_email'] ?? ''),
            'gln_number' => sanitize_text_field($params['gln_number'] ?? ''),
        ];

        foreach ($billingFields as $metaKey => $value) {
            update_user_meta($userId, $metaKey, $value);
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
     * Uses WordPress built-in privacy request system (GDPR remove_personal_data).
     * Sends a confirmation email to the user; once confirmed, WP processes the erasure.
     */
    public function handleGdprErase(mixed $data, array $params): array|WP_Error
    {
        $userId = get_current_user_id();
        if (!$userId) {
            return new WP_Error('not_logged_in', __('Je moet ingelogd zijn.', 'stride'));
        }

        $user = get_userdata($userId);
        $requestId = wp_create_user_request($user->user_email, 'remove_personal_data');

        if (is_wp_error($requestId)) {
            return $requestId;
        }

        wp_send_user_request($requestId);

        ntdst_log('profile')->info('GDPR erasure requested', ['user_id' => $userId]);

        return [
            'success' => true,
            'message' => __('Je ontvangt een bevestigingsmail om de verwijdering te bevestigen.', 'stride'),
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
