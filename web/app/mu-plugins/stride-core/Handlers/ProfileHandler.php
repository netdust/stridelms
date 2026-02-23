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
        $phone = sanitize_text_field($params['phone'] ?? '');

        $result = wp_update_user([
            'ID' => $userId,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'display_name' => trim($firstName . ' ' . $lastName),
        ]);

        if (is_wp_error($result)) {
            return $result;
        }

        update_user_meta($userId, 'phone', $phone);

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
            'invoice_organization_name' => sanitize_text_field($params['billing_company'] ?? ''),
            'vat_number' => sanitize_text_field($params['billing_vat'] ?? ''),
            'invoice_address' => sanitize_text_field($params['billing_address'] ?? ''),
            'invoice_postal_code' => sanitize_text_field($params['billing_postal_code'] ?? ''),
            'invoice_city' => sanitize_text_field($params['billing_city'] ?? ''),
            'invoice_email' => sanitize_email($params['billing_email'] ?? ''),
            'gln_number' => sanitize_text_field($params['billing_gln'] ?? ''),
        ];

        $legacyMappings = [
            'invoice_organization_name' => 'company',
            'invoice_address' => 'address_line_1',
            'invoice_postal_code' => 'postal_code',
            'invoice_city' => 'city',
        ];

        foreach ($billingFields as $key => $value) {
            update_user_meta($userId, $key, $value);

            if (isset($legacyMappings[$key])) {
                update_user_meta($userId, $legacyMappings[$key], $value);
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
